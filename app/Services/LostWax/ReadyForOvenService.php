<?php

namespace App\Services\LostWax;

use App\Models\LostWaxItemReference;
use App\Models\LostWaxTree;
use App\Models\ProductionPlan;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ReadyForOvenService
{
    /**
     * Retrieve the queue of trees that have completed coating and are ready for the Oven.
     * Items are grouped when production code, item name, customer, finished stage,
     * and work order key are identical.
     *
     * @param  string|null  $search  Search keyword for production code, item name, customer, barcode, work order, or PO
     * @param  string|null  $scope  Optional RBAC product scope
     * @param  mixed  $dailyTargetKg  Optional daily oven target in kg for coverage simulation
     * @param  string|null  $poStatus  Optional PO achievement status filter (all, completed, kurang, sesuai, belum_ditentukan)
     * @param  array  $codes  Optional list of production codes to filter
     * @param  array  $customers  Optional list of customer codes to filter
     * @return array{items: Collection, raw_items: Collection, summary: array, all_codes: array, all_customers: array}
     */
    public function getQueueData(
        ?string $search = null,
        ?string $scope = null,
        mixed $dailyTargetKg = null,
        ?string $poStatus = 'all',
        array $codes = [],
        array $customers = []
    ): array {
        // 1. Fetch eligible tree candidates with eager loading
        $query = LostWaxTree::query()
            ->with([
                'defects',
                'coatingRack',
                'printOrderLine.productionPlan',
                'printOrderLine.printOrder',
                'rangkaiExecution.workOrder',
                'workOrder.itemReference',
                'allocations.printOrderLine.productionPlan',
                'scanEvents' => function ($q) {
                    $q->whereIn('stage', ['layer_6', 'layer_7', 'oven'])
                        ->where('result', 'success')
                        ->whereDoesntHave('void')
                        ->orderByDesc('scanned_at')
                        ->orderByDesc('id');
                },
            ])
            ->where('status', '!=', 'cancelled')
            ->where('current_stage', '!=', 'oven')
            ->where(function ($q) {
                // 6-layer candidates: neither tree nor work_order requires layer 7, current_stage = layer_6
                $q->where(function ($sub) {
                    $sub->where('lost_wax_trees.require_layer_7', false)
                        ->where(function ($woSub) {
                            $woSub->whereNull('lost_wax_trees.work_order_id')
                                ->orWhereDoesntHave('workOrder', function ($w) {
                                    $w->where('require_layer_7', true);
                                });
                        })
                        ->where('lost_wax_trees.current_stage', 'layer_6');
                })
                // 7-layer candidates: any tree that has reached layer_7 (whether planned or dynamic shopfloor dipping)
                    ->orWhere('lost_wax_trees.current_stage', 'layer_7');
            })
            // Ensure no active (non-voided) successful oven scan exists
            ->whereDoesntHave('scanEvents', function ($q) {
                $q->where('stage', 'oven')
                    ->where('result', 'success')
                    ->whereDoesntHave('void');
            });

        // 2. Apply RBAC product scope at query level when available
        if ($scope) {
            $query->where(function ($q) use ($scope) {
                $q->whereHas('printOrderLine.productionPlan', function ($p) use ($scope) {
                    $p->where('product_scope', $scope);
                })
                    ->orWhereHas('allocations.printOrderLine.productionPlan', function ($p) use ($scope) {
                        $p->where('product_scope', $scope);
                    })
                    ->orWhereHas('workOrder', function ($w) use ($scope) {
                        if ($scope === 'FLANGE_STAINLESS') {
                            $w->whereIn('family_code', ['3', '4']);
                        } elseif ($scope === 'FLANGE_BESI') {
                            $w->whereIn('family_code', ['6']);
                        } elseif ($scope === 'FITTING_STAINLESS') {
                            $w->whereIn('family_code', ['1', '2']);
                        } else {
                            $w->whereRaw('1=0');
                        }
                    });
            });
        }

        $trees = $query->get();

        // Precompute coating completion stats for each Work Order identity represented in $trees
        $coatingStatsMap = $this->precomputeCoatingCompletion($trees);

        $rawRows = collect();

        foreach ($trees as $tree) {
            // A. Physical condition current_stage is the source of truth:
            // - A tree at current_stage = 'layer_7' has completed Layer 7 (regardless of initial require_layer_7).
            // - A tree at current_stage = 'layer_6' has completed Layer 6 (provided it is not designated for layer 7).
            $requiresLayer7 = (bool) ($tree->require_layer_7 || ($tree->workOrder && $tree->workOrder->require_layer_7));

            if ($tree->current_stage === 'layer_7') {
                $finalStage = 'layer_7';
            } elseif ($tree->current_stage === 'layer_6' && ! $requiresLayer7) {
                $finalStage = 'layer_6';
            } else {
                continue;
            }

            // B. Must have usable quantity > 0 (filter out 100% defect / afkir)
            $treeUsableQty = $tree->usable_quantity;
            if ($treeUsableQty <= 0) {
                continue;
            }

            // C. Find the latest valid (non-voided, result = 'success') scan event for the final stage
            $finalStageEvent = $tree->scanEvents
                ->where('stage', $finalStage)
                ->where('result', 'success')
                ->sortByDesc('scanned_at')
                ->first();

            if (! $finalStageEvent) {
                // If no valid success event exists for the final stage, skip
                continue;
            }

            // D. Safety check: ensure no active oven scan exists in memory
            $hasActiveOven = $tree->scanEvents
                ->where('stage', 'oven')
                ->where('result', 'success')
                ->isNotEmpty();

            if ($hasActiveOven) {
                continue;
            }

            $rackLabel = $tree->coatingRack
                ? 'RAK-'.str_pad($tree->coatingRack->rack_number, 2, '0', STR_PAD_LEFT)
                : '-';

            $scannedAt = $finalStageEvent->scanned_at;
            $treeGross = (int) $tree->quantity;
            $treeDefect = (int) $tree->total_defect_quantity;

            // Resolve Work Order Identity for this tree
            $woKeyInfo = $this->resolveTreeWorkOrderKey($tree);
            $woStats = $coatingStatsMap[$woKeyInfo['key']] ?? [
                'total' => 1,
                'coated' => 1,
                'is_full' => true,
                'status' => 'FULL BATCH',
                'progress_text' => '1 / 1 TREE COATED',
                'percentage' => 100,
            ];

            // E. Handle multi-allocation vs single order trees
            if ($tree->allocations->isNotEmpty()) {
                $allocations = $tree->allocations;
                $isMultiAllocated = $allocations->count() > 1;

                // Group allocations by (plan_id / code, item_name, customer) to collapse identical lines on the same tree
                $groupedAllocations = $allocations->groupBy(function ($alloc) {
                    $line = $alloc->printOrderLine;
                    $plan = $line?->productionPlan;
                    $code = $plan?->code ?? $line?->code ?? '-';
                    $item = $plan?->item_name ?? $line?->item_name ?? '-';
                    $cust = $plan?->customer ?? $line?->customer ?? '-';

                    return "{$code}|{$item}|{$cust}";
                });

                // Calculate prorated usable quantity across allocation groups
                $totalAllocatedQty = $allocations->sum('allocated_qty');
                $accumulatedUsable = 0;
                $groupCount = $groupedAllocations->count();
                $idx = 0;

                foreach ($groupedAllocations as $groupKey => $groupAllocs) {
                    $idx++;
                    $firstAlloc = $groupAllocs->first();
                    $line = $firstAlloc->printOrderLine;
                    $plan = $line?->productionPlan;

                    // Scope filter for line if applicable
                    if ($scope && $plan && $plan->product_scope !== $scope) {
                        continue;
                    }

                    $code = $plan?->code ?? $line?->code ?? $tree->getSourceCode() ?? '-';
                    $customer = $plan?->customer ?? $line?->customer ?? $tree->getSourceCustomer() ?? '-';
                    $itemName = $plan?->item_name ?? $line?->item_name ?? $tree->getSourceProduct() ?? '-';
                    $poNumber = $plan?->po_number ?? '-';
                    $poQuantity = ($plan && $plan->po_quantity !== null && (int) $plan->po_quantity > 0)
                        ? (int) $plan->po_quantity
                        : null;
                    $groupGrossQty = (int) $groupAllocs->sum('allocated_qty');

                    // Pro-rate usable pcs based on gross proportion:
                    if ($treeDefect === 0 || $totalAllocatedQty === 0) {
                        $groupUsable = $groupGrossQty;
                    } elseif ($idx === $groupCount) {
                        // Last group gets remainder to eliminate rounding variance:
                        $groupUsable = max(0, $treeUsableQty - $accumulatedUsable);
                    } else {
                        $ratio = $groupGrossQty / $totalAllocatedQty;
                        $groupUsable = (int) round($treeUsableQty * $ratio);
                        $accumulatedUsable += $groupUsable;
                    }

                    // Resolve unit weight and weight KG for this specific allocation
                    $unitWeight = $this->resolveUnitWeight($plan, $tree);
                    $hasWeight = ($unitWeight !== null && $unitWeight > 0);
                    $weightKg = $hasWeight ? ($groupUsable * $unitWeight) : null;

                    $rawRows->push([
                        'tree_id' => $tree->id,
                        'barcode' => $tree->barcode,
                        'human_barcode' => $tree->human_barcode,
                        'rack_label' => $rackLabel,
                        'production_code' => $code,
                        'item_name' => $itemName,
                        'customer' => $customer,
                        'po_number' => $poNumber,
                        'po_quantity' => $poQuantity,
                        'work_order_key' => $woKeyInfo['key'],
                        'work_order_number' => $woKeyInfo['number'],
                        'work_order_type' => $woKeyInfo['type'],
                        'coating_total_trees' => $woStats['total'],
                        'coating_completed_trees' => $woStats['coated'],
                        'is_full_batch' => $woStats['is_full'],
                        'coating_status' => $woStats['status'],
                        'coating_progress_text' => $woStats['progress_text'],
                        'coating_percentage' => $woStats['percentage'],
                        'tree_count' => 1,
                        'is_multi_allocated' => $isMultiAllocated,
                        'quantity_pcs' => $groupUsable,
                        'gross_pcs' => $groupGrossQty,
                        'defect_pcs' => max(0, $groupGrossQty - $groupUsable),
                        'unit_weight' => $unitWeight,
                        'unit_weight_formatted' => $hasWeight ? number_format($unitWeight, 2, ',', '.') : '-',
                        'weight_kg' => $weightKg,
                        'weight_kg_formatted' => $hasWeight ? number_format($weightKg, 2, ',', '.') : '-',
                        'has_weight' => $hasWeight,
                        'finished_stage' => $finalStage,
                        'finished_stage_label' => config("lost_wax.stages.{$finalStage}", ucfirst($finalStage)),
                        'finished_at' => $scannedAt,
                        'finished_at_formatted' => $scannedAt->format('d/m/Y H:i'),
                    ]);
                }
            } else {
                // Single order tree
                $line = $tree->printOrderLine;
                $plan = $line?->productionPlan;
                $wo = $tree->workOrder;

                if ($scope && $plan && $plan->product_scope !== $scope) {
                    continue;
                }

                $code = $plan?->code ?? $tree->getSourceCode() ?? '-';
                $customer = $plan?->customer ?? $tree->getSourceCustomer() ?? '-';
                $itemName = $plan?->item_name ?? $tree->getSourceProduct() ?? '-';
                $poNumber = $plan?->po_number ?? $wo?->po_number ?? '-';

                $poQuantity = null;
                if ($plan && $plan->po_quantity !== null && (int) $plan->po_quantity > 0) {
                    $poQuantity = (int) $plan->po_quantity;
                } elseif ($wo && $wo->po_quantity !== null && (int) $wo->po_quantity > 0) {
                    $poQuantity = (int) $wo->po_quantity;
                }

                // Resolve unit weight and weight KG
                $unitWeight = $this->resolveUnitWeight($plan, $tree);
                $hasWeight = ($unitWeight !== null && $unitWeight > 0);
                $weightKg = $hasWeight ? ($treeUsableQty * $unitWeight) : null;

                $rawRows->push([
                    'tree_id' => $tree->id,
                    'barcode' => $tree->barcode,
                    'human_barcode' => $tree->human_barcode,
                    'rack_label' => $rackLabel,
                    'production_code' => $code,
                    'item_name' => $itemName,
                    'customer' => $customer,
                    'po_number' => $poNumber,
                    'po_quantity' => $poQuantity,
                    'work_order_key' => $woKeyInfo['key'],
                    'work_order_number' => $woKeyInfo['number'],
                    'work_order_type' => $woKeyInfo['type'],
                    'coating_total_trees' => $woStats['total'],
                    'coating_completed_trees' => $woStats['coated'],
                    'is_full_batch' => $woStats['is_full'],
                    'coating_status' => $woStats['status'],
                    'coating_progress_text' => $woStats['progress_text'],
                    'coating_percentage' => $woStats['percentage'],
                    'tree_count' => 1,
                    'is_multi_allocated' => false,
                    'quantity_pcs' => $treeUsableQty,
                    'gross_pcs' => $treeGross,
                    'defect_pcs' => $treeDefect,
                    'unit_weight' => $unitWeight,
                    'unit_weight_formatted' => $hasWeight ? number_format($unitWeight, 2, ',', '.') : '-',
                    'weight_kg' => $weightKg,
                    'weight_kg_formatted' => $hasWeight ? number_format($weightKg, 2, ',', '.') : '-',
                    'has_weight' => $hasWeight,
                    'finished_stage' => $finalStage,
                    'finished_stage_label' => config("lost_wax.stages.{$finalStage}", ucfirst($finalStage)),
                    'finished_at' => $scannedAt,
                    'finished_at_formatted' => $scannedAt->format('d/m/Y H:i'),
                ]);
            }
        }

        // 3. TWO-TIER GROUPING:
        // Tier 1 (Parent): Group primarily by production_code (plus item_name, customer, finished_stage for total safety)
        $grouped = $rawRows->groupBy(function ($row) {
            return $row['production_code'].'|'.$row['item_name'].'|'.$row['customer'].'|'.$row['finished_stage'];
        })->map(function (Collection $codeMembers, $groupKey) {
            $first = $codeMembers->first();
            $code = $first['production_code'];
            $poQuantity = $first['po_quantity'];
            $poNumber = $first['po_number'];

            // Sort all members of this code by finished_at ASC, barcode ASC
            $sortedMembers = $codeMembers->sort(function ($a, $b) {
                $timeA = $a['finished_at'] instanceof Carbon ? $a['finished_at']->timestamp : 0;
                $timeB = $b['finished_at'] instanceof Carbon ? $b['finished_at']->timestamp : 0;
                if ($timeA !== $timeB) {
                    return $timeA <=> $timeB;
                }

                return strcmp($a['barcode'], $b['barcode']);
            })->values();

            $earliest = $sortedMembers->first();
            $latest = $sortedMembers->last();

            // Total trees and quantities for this production code:
            $treeCount = (int) $sortedMembers->sum('tree_count');
            $usablePcs = (int) $sortedMembers->sum('quantity_pcs');
            $grossPcs = (int) $sortedMembers->sum('gross_pcs');
            $defectPcs = (int) $sortedMembers->sum('defect_pcs');

            // Tier 2 (Children): Sub-grouping by work_order_key
            $workOrders = $sortedMembers->groupBy('work_order_key')->map(function (Collection $woMembers, $woKey) {
                $woFirst = $woMembers->first();
                $woEarliest = $woMembers->first();
                $woLatest = $woMembers->last();

                $woTreeCount = (int) $woMembers->sum('tree_count');
                $woUsablePcs = (int) $woMembers->sum('quantity_pcs');
                $woGrossPcs = (int) $woMembers->sum('gross_pcs');
                $woDefectPcs = (int) $woMembers->sum('defect_pcs');

                $woBarcodes = $woMembers->pluck('barcode')->unique()->values()->all();
                $woHumanBarcodes = $woMembers->pluck('human_barcode')->unique()->values()->all();
                $woRacks = $woMembers->pluck('rack_label')->unique()->filter(fn ($r) => $r !== '-')->values()->all();
                $woRacksCompact = empty($woRacks) ? '-' : implode(', ', $woRacks);

                $woBarcodesWithRack = $woMembers->map(function ($m) {
                    $rack = $m['rack_label'] !== '-' ? "[{$m['rack_label']}]" : '';

                    return trim("{$m['barcode']} {$rack}");
                })->values()->all();

                return [
                    'work_order_key' => $woKey,
                    'work_order_number' => $woFirst['work_order_number'],
                    'work_order_type' => $woFirst['work_order_type'],
                    'tree_count' => $woTreeCount,
                    'quantity_pcs' => $woUsablePcs,
                    'gross_pcs' => $woGrossPcs,
                    'defect_pcs' => $woDefectPcs,
                    'coating_total_trees' => $woFirst['coating_total_trees'],
                    'coating_completed_trees' => $woFirst['coating_completed_trees'],
                    'coating_status' => $woFirst['coating_status'],
                    'is_full_batch' => $woFirst['is_full_batch'],
                    'coating_progress_text' => $woFirst['coating_progress_text'],
                    'coating_percentage' => $woFirst['coating_percentage'],
                    'finished_at' => $woEarliest['finished_at'],
                    'finished_at_formatted' => $woEarliest['finished_at']->format('d/m/Y H:i'),
                    'latest_finished_at' => $woLatest['finished_at'],
                    'latest_finished_at_formatted' => $woLatest['finished_at']->format('d/m/Y H:i'),
                    'barcodes' => $woBarcodes,
                    'human_barcodes' => $woHumanBarcodes,
                    'barcodes_with_rack' => $woBarcodesWithRack,
                    'racks_compact' => $woRacksCompact,
                    'racks' => $woRacks,
                    'trees' => $woMembers->values()->all(),
                ];
            })->sort(function ($a, $b) {
                // SPKs sorted by earliest finished_at ASC, then work_order_number ASC
                $timeA = $a['finished_at'] instanceof Carbon ? $a['finished_at']->timestamp : 0;
                $timeB = $b['finished_at'] instanceof Carbon ? $b['finished_at']->timestamp : 0;
                if ($timeA !== $timeB) {
                    return $timeA <=> $timeB;
                }

                return strcmp($a['work_order_number'], $b['work_order_number']);
            })->values();

            // PO & Variance calculations on master code level
            $poVariancePcs = null;
            $poVarianceStatus = null;
            $poVarianceText = null;

            if ($poQuantity !== null) {
                $diff = $usablePcs - $poQuantity;
                $poVariancePcs = abs($diff);
                if ($diff > 0) {
                    $poVarianceStatus = 'LEBIH';
                    $poVarianceText = 'Lebih '.number_format($poVariancePcs, 0, ',', '.').' PCS';
                } elseif ($diff < 0) {
                    $poVarianceStatus = 'KURANG';
                    $poVarianceText = 'Kurang '.number_format($poVariancePcs, 0, ',', '.').' PCS';
                } else {
                    $poVarianceStatus = 'SESUAI';
                    $poVarianceText = 'Sesuai PO';
                }
            } else {
                $poVarianceStatus = 'BELUM_DITENTUKAN';
                $poVarianceText = 'PO belum ditentukan';
            }

            // Weight calculation for this code
            $hasWeight = $sortedMembers->every(fn ($m) => $m['has_weight'] === true);
            $groupWeightKg = $hasWeight ? (float) $sortedMembers->sum('weight_kg') : null;
            $unitWeight = $first['unit_weight'];

            $barcodes = $sortedMembers->pluck('barcode')->unique()->values()->all();
            $humanBarcodes = $sortedMembers->pluck('human_barcode')->unique()->values()->all();
            $racks = $sortedMembers->pluck('rack_label')->unique()->filter(fn ($r) => $r !== '-')->values()->all();
            $racksCompact = empty($racks) ? '-' : implode(', ', $racks);

            $barcodesWithRack = $sortedMembers->map(function ($m) {
                $rack = $m['rack_label'] !== '-' ? "[{$m['rack_label']}]" : '';

                return trim("{$m['barcode']} {$rack}");
            })->values()->all();

            $earliestFinishedAt = $earliest['finished_at'];
            $latestFinishedAt = $latest['finished_at'];

            // Coating completion metrics across all Work Orders of this code (preserved for backend/tests)
            $totalTreesAcrossWos = (int) $workOrders->sum('coating_total_trees');
            $coatedTreesAcrossWos = (int) $workOrders->sum('coating_completed_trees');
            $isFullBatch = ($totalTreesAcrossWos > 0 && $coatedTreesAcrossWos >= $totalTreesAcrossWos);
            $coatingStatus = $isFullBatch ? 'FULL BATCH' : 'PARTIAL BATCH';
            $coatingProgressText = "{$coatedTreesAcrossWos} / {$totalTreesAcrossWos} TREE COATED";

            return [
                'group_key' => $groupKey,
                'production_code' => $code,
                'item_name' => $first['item_name'],
                'customer' => $first['customer'],
                'po_number' => $poNumber,
                'po_quantity' => $poQuantity,
                'po_quantity_formatted' => $poQuantity !== null ? number_format($poQuantity, 0, ',', '.') : '-',
                'tree_count' => $treeCount,
                'quantity_pcs' => $usablePcs,
                'gross_pcs' => $grossPcs,
                'defect_pcs' => $defectPcs,
                'siap_oven_code_pcs' => $usablePcs, // Equals quantity_pcs since one row per code!
                'siap_oven_code_pcs_formatted' => number_format($usablePcs, 0, ',', '.'),
                'is_split_code' => $workOrders->count() > 1,
                'has_multiple_spk' => $workOrders->count() > 1,
                'work_order_count' => $workOrders->count(),
                'po_variance_pcs' => $poVariancePcs,
                'po_variance_status' => $poVarianceStatus,
                'po_variance_text' => $poVarianceText,
                'work_orders' => $workOrders,
                // Work order summary fields from first/earliest for backwards compatibility
                'work_order_key' => $first['work_order_key'],
                'work_order_number' => $workOrders->pluck('work_order_number')->filter(fn ($n) => $n !== '-')->implode(', ') ?: '-',
                'work_order_type' => $first['work_order_type'],
                'coating_total_trees' => $totalTreesAcrossWos,
                'coating_completed_trees' => $coatedTreesAcrossWos,
                'coating_status' => $coatingStatus,
                'is_full_batch' => $isFullBatch,
                'coating_progress_text' => $coatingProgressText,
                'coating_percentage' => $totalTreesAcrossWos > 0 ? (int) round(($coatedTreesAcrossWos / $totalTreesAcrossWos) * 100) : 100,
                'unit_weight' => $unitWeight,
                'unit_weight_formatted' => $hasWeight ? number_format($unitWeight, 2, ',', '.') : '-',
                'weight_kg' => $groupWeightKg,
                'weight_kg_formatted' => $hasWeight ? number_format($groupWeightKg, 2, ',', '.') : '-',
                'has_weight' => $hasWeight,
                'finished_stage' => $first['finished_stage'],
                'finished_stage_label' => $first['finished_stage_label'],
                'finished_at' => $earliestFinishedAt,
                'finished_at_formatted' => $earliestFinishedAt->format('d/m/Y H:i'),
                'latest_finished_at' => $latestFinishedAt,
                'latest_finished_at_formatted' => $latestFinishedAt->format('d/m/Y H:i'),
                'has_mixed_times' => ($earliestFinishedAt->timestamp !== $latestFinishedAt->timestamp),
                'barcodes' => $barcodes,
                'human_barcodes' => $humanBarcodes,
                'barcodes_with_rack' => $barcodesWithRack,
                'racks_compact' => $racksCompact,
                'is_multi_allocated' => $sortedMembers->contains(fn ($m) => $m['is_multi_allocated']),
                'members' => $sortedMembers->all(),
            ];
        });

        // 4. Optional Search Filter
        $searchLower = trim(strtolower((string) $search));
        if ($searchLower !== '') {
            $grouped = $grouped->filter(function ($item) use ($searchLower) {
                return str_contains(strtolower($item['production_code']), $searchLower)
                    || str_contains(strtolower($item['item_name']), $searchLower)
                    || str_contains(strtolower($item['customer']), $searchLower)
                    || str_contains(strtolower((string) $item['po_number']), $searchLower)
                    || str_contains(strtolower((string) $item['work_order_number']), $searchLower)
                    || str_contains(strtolower($item['racks_compact']), $searchLower)
                    || collect($item['barcodes'])->contains(fn ($b) => str_contains(strtolower($b), $searchLower))
                    || collect($item['human_barcodes'])->contains(fn ($b) => str_contains(strtolower($b), $searchLower))
                    || collect($item['work_orders'])->contains(fn ($wo) => str_contains(strtolower($wo['work_order_number']), $searchLower));
            });
        }

        // 5. Strict FIFO Group Sorting:
        // Priority 1: Earliest finished_at timestamp ASC (oldest finished layer first)
        // Priority 2: Production Code ASC
        // Priority 3: Item Name ASC
        // Priority 4: Customer ASC
        // Priority 5: First Barcode ASC
        $sortedGroups = $grouped->sort(function ($a, $b) {
            $timeA = $a['finished_at'] instanceof Carbon ? $a['finished_at']->timestamp : 0;
            $timeB = $b['finished_at'] instanceof Carbon ? $b['finished_at']->timestamp : 0;

            if ($timeA !== $timeB) {
                return $timeA <=> $timeB;
            }

            $cmpCode = strcmp($a['production_code'], $b['production_code']);
            if ($cmpCode !== 0) {
                return $cmpCode;
            }

            $cmpItem = strcmp($a['item_name'], $b['item_name']);
            if ($cmpItem !== 0) {
                return $cmpItem;
            }

            $cmpCust = strcmp($a['customer'], $b['customer']);
            if ($cmpCust !== 0) {
                return $cmpCust;
            }

            $barcodeA = $a['barcodes'][0] ?? '';
            $barcodeB = $b['barcodes'][0] ?? '';

            return strcmp($barcodeA, $barcodeB);
        })->values();

        // Extract all available production codes and customers before filtering for dropdown options
        $allCodes = $sortedGroups->pluck('production_code')
            ->filter(fn ($c) => $c !== '-' && ! empty($c))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $allCustomers = $sortedGroups->pluck('customer')
            ->filter(fn ($c) => $c !== '-' && ! empty($c))
            ->unique()
            ->sort()
            ->values()
            ->all();

        // 6. Multi-Factor Dropdown Filtering: Kode Produksi, Status PO, Customer (Phase 1.3 Revision)
        $validPoStatuses = [
            'all' => 'ALL',
            'completed' => 'COMPLETED',
            'lebih' => 'COMPLETED', // Alias for backwards compatibility
            'kurang' => 'KURANG',
            'sesuai' => 'SESUAI',
            'belum_ditentukan' => 'PO BELUM DITENTUKAN',
        ];

        $normalizedPoStatus = strtolower(trim((string) $poStatus));
        if (! array_key_exists($normalizedPoStatus, $validPoStatuses)) {
            $normalizedPoStatus = 'all';
        }
        $poStatusLabel = $validPoStatuses[$normalizedPoStatus];

        // Status counts across all codes (after optional search keyword filter)
        $poStatusCounts = [
            'all' => $sortedGroups->count(),
            'completed' => $sortedGroups->where('po_variance_status', 'LEBIH')->count(),
            'lebih' => $sortedGroups->where('po_variance_status', 'LEBIH')->count(),
            'kurang' => $sortedGroups->where('po_variance_status', 'KURANG')->count(),
            'sesuai' => $sortedGroups->where('po_variance_status', 'SESUAI')->count(),
            'belum_ditentukan' => $sortedGroups->filter(fn ($g) => $g['po_quantity'] === null || $g['po_variance_status'] === 'BELUM_DITENTUKAN')->count(),
        ];

        // Sanitize multi-select array inputs
        $cleanCodes = array_values(array_filter($codes, fn ($c) => $c !== null && $c !== ''));
        $cleanCustomers = array_values(array_filter($customers, fn ($c) => $c !== null && $c !== ''));

        // Apply filters while strictly preserving FIFO sorting
        $filteredGroups = $sortedGroups;

        // A. Filter by Kode Produksi (multiple allowed)
        if (! empty($cleanCodes)) {
            $filteredGroups = $filteredGroups->whereIn('production_code', $cleanCodes);
        }

        // B. Filter by Customer (multiple allowed)
        if (! empty($cleanCustomers)) {
            $filteredGroups = $filteredGroups->whereIn('customer', $cleanCustomers);
        }

        // C. Filter by Status PO
        if ($normalizedPoStatus === 'completed' || $normalizedPoStatus === 'lebih') {
            $filteredGroups = $filteredGroups->where('po_variance_status', 'LEBIH');
        } elseif ($normalizedPoStatus === 'kurang') {
            $filteredGroups = $filteredGroups->where('po_variance_status', 'KURANG');
        } elseif ($normalizedPoStatus === 'sesuai') {
            $filteredGroups = $filteredGroups->where('po_variance_status', 'SESUAI');
        } elseif ($normalizedPoStatus === 'belum_ditentukan') {
            $filteredGroups = $filteredGroups->filter(fn ($g) => $g['po_quantity'] === null || $g['po_variance_status'] === 'BELUM_DITENTUKAN');
        }

        $filteredGroups = $filteredGroups->values();

        // Build active filter human descriptions
        $activeFilterDescriptions = [];
        if (! empty($cleanCodes)) {
            $activeFilterDescriptions[] = 'Kode: '.implode(', ', $cleanCodes);
        }
        if ($normalizedPoStatus !== 'all') {
            $activeFilterDescriptions[] = 'Status PO: '.$poStatusLabel;
        }
        if (! empty($cleanCustomers)) {
            $activeFilterDescriptions[] = 'Customer: '.implode(', ', $cleanCustomers);
        }
        $activeFilterText = implode(' | ', $activeFilterDescriptions);
        $isFiltered = ! empty($activeFilterDescriptions);

        // 7. Build accountable summary metrics
        $filteredUniqueTreeIds = $filteredGroups->flatMap(function ($g) {
            return collect($g['members'])->pluck('tree_id');
        })->unique()->count();

        $totalRangkai = (int) $filteredGroups->sum('tree_count');
        $totalUsablePcs = (int) $filteredGroups->sum('quantity_pcs');
        $totalGrossPcs = (int) $filteredGroups->sum('gross_pcs');
        $totalDefectPcs = (int) $filteredGroups->sum('defect_pcs');
        $totalGroups = $filteredGroups->count();
        $totalSpkCount = (int) $filteredGroups->sum('work_order_count');
        $distinctPlans = $filteredGroups->pluck('production_code')->filter(fn ($c) => $c !== '-' && ! empty($c))->unique()->count();

        // Deduplicated PO Summary by Distinct Production Code (prevents double-counting across multiple groups)
        $distinctCodePoMap = [];
        foreach ($filteredGroups as $g) {
            $c = $g['production_code'];
            if (! array_key_exists($c, $distinctCodePoMap)) {
                $distinctCodePoMap[$c] = $g['po_quantity'];
            }
        }

        $totalPoPcs = 0;
        $codesWithPoCount = 0;
        $codesWithoutPoCount = 0;

        foreach ($distinctCodePoMap as $c => $poQty) {
            if ($poQty !== null) {
                $totalPoPcs += $poQty;
                $codesWithPoCount++;
            } else {
                $codesWithoutPoCount++;
            }
        }

        $hasIncompletePo = $codesWithoutPoCount > 0;
        $overallPoDiff = $totalUsablePcs - $totalPoPcs;
        $overallPoVariancePcs = abs($overallPoDiff);
        $overallPoVarianceStatus = null;
        $overallPoVarianceText = null;

        if ($codesWithPoCount > 0) {
            if ($overallPoDiff > 0) {
                $overallPoVarianceStatus = 'LEBIH';
                $overallPoVarianceText = 'Lebih '.number_format($overallPoVariancePcs, 0, ',', '.').' PCS';
            } elseif ($overallPoDiff < 0) {
                $overallPoVarianceStatus = 'KURANG';
                $overallPoVarianceText = 'Kurang '.number_format($overallPoVariancePcs, 0, ',', '.').' PCS';
            } else {
                $overallPoVarianceStatus = 'SESUAI';
                $overallPoVarianceText = 'Sesuai PO';
            }
        }

        // Weight & Coverage Days Calculation
        $validWeightGroups = $filteredGroups->filter(fn ($g) => $g['has_weight'] === true);
        $totalWeightKg = (float) $validWeightGroups->sum('weight_kg');
        $groupsWithWeight = $validWeightGroups->count();
        $groupsWithoutWeight = $filteredGroups->filter(fn ($g) => $g['has_weight'] === false)->count();
        $hasIncompleteWeights = $groupsWithoutWeight > 0;

        // Daily Target KG resolution
        $defaultTarget = (float) config('lost_wax.daily_oven_target_kg', 2000);
        if ($defaultTarget <= 0) {
            $defaultTarget = 2000.0;
        }

        $dailyTarget = null;
        if ($dailyTargetKg !== null && $dailyTargetKg !== '') {
            $normalized = str_replace(',', '.', (string) $dailyTargetKg);
            if (is_numeric($normalized) && (float) $normalized > 0) {
                $dailyTarget = (float) $normalized;
            }
        }
        if ($dailyTarget === null || $dailyTarget <= 0) {
            $dailyTarget = $defaultTarget;
        }

        $coverageDays = $dailyTarget > 0 ? round($totalWeightKg / $dailyTarget, 1) : 0.0;

        $summary = [
            'total_unique_trees' => $filteredUniqueTreeIds,
            'total_rangkai' => $totalRangkai,
            'total_usable_pcs' => $totalUsablePcs,
            'total_gross_pcs' => $totalGrossPcs,
            'total_defect_pcs' => $totalDefectPcs,
            'total_groups' => $totalGroups,
            'total_spk_count' => $totalSpkCount,
            'distinct_plans_count' => $distinctPlans,
            'total_trees' => $filteredUniqueTreeIds, // alias for backwards compatibility
            'total_rows' => $totalGroups,           // alias for backwards compatibility
            'total_po_pcs' => $totalPoPcs,
            'total_po_pcs_formatted' => number_format($totalPoPcs, 0, ',', '.'),
            'codes_with_po_count' => $codesWithPoCount,
            'codes_without_po_count' => $codesWithoutPoCount,
            'has_incomplete_po' => $hasIncompletePo,
            'overall_po_variance_pcs' => $overallPoVariancePcs,
            'overall_po_variance_status' => $overallPoVarianceStatus,
            'overall_po_variance_text' => $overallPoVarianceText,
            'total_weight_kg' => $totalWeightKg,
            'total_weight_kg_formatted' => number_format($totalWeightKg, 2, ',', '.'),
            'groups_with_weight_count' => $groupsWithWeight,
            'groups_without_weight_count' => $groupsWithoutWeight,
            'has_incomplete_weights' => $hasIncompleteWeights,
            'daily_target_kg' => $dailyTarget,
            'daily_target_kg_formatted' => number_format($dailyTarget, 0, ',', '.'),
            'coverage_days' => $coverageDays,
            'coverage_days_formatted' => number_format($coverageDays, 1, ',', '.'),
            'queue_total_groups' => $sortedGroups->count(),
            'po_status' => $normalizedPoStatus,
            'po_status_label' => $poStatusLabel,
            'is_po_status_filtered' => ($normalizedPoStatus !== 'all'),
            'po_status_counts' => $poStatusCounts,
            'selected_codes' => $cleanCodes,
            'selected_customers' => $cleanCustomers,
            'is_filtered' => $isFiltered,
            'active_filter_text' => $activeFilterText,
            'active_filter_descriptions' => $activeFilterDescriptions,
            'generated_at' => Carbon::now(config('app.timezone')),
            'generated_at_formatted' => Carbon::now(config('app.timezone'))->format('d/m/Y H:i:s'),
        ];

        return [
            'items' => $filteredGroups,
            'raw_items' => $rawRows,
            'summary' => $summary,
            'all_codes' => $allCodes,
            'all_customers' => $allCustomers,
        ];
    }

    /**
     * Resolve the Work Order key and identifier for a given tree.
     *
     * @return array{key: string, number: string, type: string}
     */
    protected function resolveTreeWorkOrderKey(LostWaxTree $tree): array
    {
        if ($tree->rangkaiExecution && $tree->rangkaiExecution->workOrder) {
            $rwo = $tree->rangkaiExecution->workOrder;

            return [
                'key' => 'RWO:'.$rwo->id,
                'number' => $rwo->rangkai_order_number,
                'type' => 'rangkai',
            ];
        }

        if ($tree->work_order_id && $tree->workOrder) {
            $wo = $tree->workOrder;

            return [
                'key' => 'LWO:'.$wo->id,
                'number' => $wo->et_code ?? ('WO-'.$wo->id),
                'type' => 'legacy',
            ];
        }

        if ($tree->lost_wax_print_order_line_id) {
            return [
                'key' => 'POL:'.$tree->lost_wax_print_order_line_id,
                'number' => $tree->printOrderLine?->code ?? ('LINE-'.$tree->lost_wax_print_order_line_id),
                'type' => 'print_line',
            ];
        }

        return [
            'key' => 'TREE:'.$tree->id,
            'number' => $tree->barcode,
            'type' => 'tree',
        ];
    }

    /**
     * Precompute coating completion metrics for all Work Orders associated with the given trees.
     *
     * @param  Collection<int, LostWaxTree>  $trees
     * @return array<string, array{total: int, coated: int, is_full: bool, status: string, progress_text: string, percentage: int}>
     */
    protected function precomputeCoatingCompletion(Collection $trees): array
    {
        $rwoIds = [];
        $lwoIds = [];
        $polIds = [];

        foreach ($trees as $tree) {
            if ($tree->rangkaiExecution && $tree->rangkaiExecution->rangkai_work_order_id) {
                $rwoIds[] = $tree->rangkaiExecution->rangkai_work_order_id;
            } elseif ($tree->work_order_id) {
                $lwoIds[] = $tree->work_order_id;
            } elseif ($tree->lost_wax_print_order_line_id) {
                $polIds[] = $tree->lost_wax_print_order_line_id;
            }
        }

        $rwoIds = array_values(array_unique($rwoIds));
        $lwoIds = array_values(array_unique($lwoIds));
        $polIds = array_values(array_unique($polIds));

        // 1. Fetch sibling trees for Rangkai Work Orders
        $rwoTrees = ! empty($rwoIds) ? LostWaxTree::query()
            ->whereHas('rangkaiExecution', function ($q) use ($rwoIds) {
                $q->whereIn('rangkai_work_order_id', $rwoIds)
                    ->where('status', '!=', 'CANCELLED');
            })
            ->where('status', '!=', 'cancelled')
            ->with('rangkaiExecution')
            ->get() : collect();

        // 2. Fetch sibling trees for Legacy Work Orders
        $lwoTrees = ! empty($lwoIds) ? LostWaxTree::query()
            ->whereIn('work_order_id', $lwoIds)
            ->where('status', '!=', 'cancelled')
            ->get() : collect();

        // 3. Fetch sibling trees for Print Order Lines
        $polTrees = ! empty($polIds) ? LostWaxTree::query()
            ->whereIn('lost_wax_print_order_line_id', $polIds)
            ->whereNull('rangkai_execution_id')
            ->whereNull('work_order_id')
            ->where('status', '!=', 'cancelled')
            ->get() : collect();

        $statsMap = [];

        // Aggregate RWO stats
        $groupedRwo = $rwoTrees->groupBy(fn ($t) => 'RWO:'.$t->rangkaiExecution->rangkai_work_order_id);
        foreach ($groupedRwo as $key => $siblingGroup) {
            $total = $siblingGroup->count();
            $coated = $siblingGroup->filter(fn ($t) => $this->isTreeCoated($t))->count();
            $isFull = ($total > 0 && $coated >= $total);

            $statsMap[$key] = [
                'total' => $total,
                'coated' => $coated,
                'is_full' => $isFull,
                'status' => $isFull ? 'FULL BATCH' : 'PARTIAL BATCH',
                'progress_text' => "{$coated} / {$total} TREE COATED",
                'percentage' => $total > 0 ? (int) round(($coated / $total) * 100) : 100,
            ];
        }

        // Aggregate LWO stats
        $groupedLwo = $lwoTrees->groupBy(fn ($t) => 'LWO:'.$t->work_order_id);
        foreach ($groupedLwo as $key => $siblingGroup) {
            $total = $siblingGroup->count();
            $coated = $siblingGroup->filter(fn ($t) => $this->isTreeCoated($t))->count();
            $isFull = ($total > 0 && $coated >= $total);

            $statsMap[$key] = [
                'total' => $total,
                'coated' => $coated,
                'is_full' => $isFull,
                'status' => $isFull ? 'FULL BATCH' : 'PARTIAL BATCH',
                'progress_text' => "{$coated} / {$total} TREE COATED",
                'percentage' => $total > 0 ? (int) round(($coated / $total) * 100) : 100,
            ];
        }

        // Aggregate POL stats
        $groupedPol = $polTrees->groupBy(fn ($t) => 'POL:'.$t->lost_wax_print_order_line_id);
        foreach ($groupedPol as $key => $siblingGroup) {
            $total = $siblingGroup->count();
            $coated = $siblingGroup->filter(fn ($t) => $this->isTreeCoated($t))->count();
            $isFull = ($total > 0 && $coated >= $total);

            $statsMap[$key] = [
                'total' => $total,
                'coated' => $coated,
                'is_full' => $isFull,
                'status' => $isFull ? 'FULL BATCH' : 'PARTIAL BATCH',
                'progress_text' => "{$coated} / {$total} TREE COATED",
                'percentage' => $total > 0 ? (int) round(($coated / $total) * 100) : 100,
            ];
        }

        return $statsMap;
    }

    /**
     * Determine whether a physical tree has completed its dipping/coating process.
     * Trees in oven are considered to have completed coating.
     */
    protected function isTreeCoated(LostWaxTree $tree): bool
    {
        if ($tree->current_stage === 'oven') {
            return true;
        }

        if ($tree->require_layer_7) {
            return $tree->current_stage === 'layer_7';
        }

        return in_array($tree->current_stage, ['layer_6', 'layer_7']);
    }

    /**
     * Resolve unit weight in KG according to hierarchy:
     * 1. ProductionPlan::weight
     * 2. Fallback to LostWaxItemReference::unit_weight_snapshot
     */
    protected function resolveUnitWeight(?ProductionPlan $plan, ?LostWaxTree $tree): ?float
    {
        // 1. Primary: ProductionPlan::weight
        if ($plan && $plan->weight !== null) {
            $val = (float) $plan->weight;
            if ($val > 0) {
                return $val;
            }
        }

        // 2. Fallback: LostWaxItemReference::unit_weight_snapshot via legacy workOrder or line
        if ($tree && $tree->workOrder && $tree->workOrder->itemReference) {
            $val = (float) $tree->workOrder->itemReference->unit_weight_snapshot;
            if ($val > 0) {
                return $val;
            }
        }

        return null;
    }
}
