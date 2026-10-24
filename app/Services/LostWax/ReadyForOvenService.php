<?php

namespace App\Services\LostWax;

use App\Models\LostWaxTree;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ReadyForOvenService
{
    /**
     * Retrieve the queue of trees that have completed coating and are ready for the Oven.
     * Items are grouped when production code, item name, customer, and finished stage are identical.
     *
     * @param  string|null  $search  Search keyword for production code, item name, customer, or barcode
     * @param  string|null  $scope  Optional RBAC product scope
     * @return array{items: Collection, raw_items: Collection, summary: array}
     */
    public function getQueueData(?string $search = null, ?string $scope = null): array
    {
        // 1. Fetch eligible tree candidates with eager loading
        $query = LostWaxTree::query()
            ->with([
                'defects',
                'coatingRack',
                'printOrderLine.productionPlan',
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
                // 7-layer candidates: either tree or work_order requires layer 7, current_stage = layer_7
                    ->orWhere(function ($sub) {
                        $sub->where(function ($req7Sub) {
                            $req7Sub->where('lost_wax_trees.require_layer_7', true)
                                ->orWhereHas('workOrder', function ($w) {
                                    $w->where('require_layer_7', true);
                                });
                        })
                            ->where('lost_wax_trees.current_stage', 'layer_7');
                    });
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

        $rawRows = collect();

        foreach ($trees as $tree) {
            // A. Strict model-level evaluation of require_layer_7
            $requiresLayer7 = $tree->require_layer_7;
            $requiredFinalStage = $requiresLayer7 ? 'layer_7' : 'layer_6';

            // Tree must currently be at the exact required final stage
            if ($tree->current_stage !== $requiredFinalStage) {
                continue;
            }

            // B. Must have usable quantity > 0 (filter out 100% defect / afkir)
            $treeUsableQty = $tree->usable_quantity;
            if ($treeUsableQty <= 0) {
                continue;
            }

            // C. Find the latest valid (non-voided, result = 'success') scan event for the required stage
            $finalStageEvent = $tree->scanEvents
                ->where('stage', $requiredFinalStage)
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

                    $rawRows->push([
                        'tree_id' => $tree->id,
                        'barcode' => $tree->barcode,
                        'human_barcode' => $tree->human_barcode,
                        'rack_label' => $rackLabel,
                        'production_code' => $code,
                        'item_name' => $itemName,
                        'customer' => $customer,
                        'tree_count' => 1,
                        'is_multi_allocated' => $isMultiAllocated,
                        'quantity_pcs' => $groupUsable,
                        'gross_pcs' => $groupGrossQty,
                        'defect_pcs' => max(0, $groupGrossQty - $groupUsable),
                        'finished_stage' => $requiredFinalStage,
                        'finished_stage_label' => config("lost_wax.stages.{$requiredFinalStage}", ucfirst($requiredFinalStage)),
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

                $rawRows->push([
                    'tree_id' => $tree->id,
                    'barcode' => $tree->barcode,
                    'human_barcode' => $tree->human_barcode,
                    'rack_label' => $rackLabel,
                    'production_code' => $code,
                    'item_name' => $itemName,
                    'customer' => $customer,
                    'tree_count' => 1,
                    'is_multi_allocated' => false,
                    'quantity_pcs' => $treeUsableQty,
                    'gross_pcs' => $treeGross,
                    'defect_pcs' => $treeDefect,
                    'finished_stage' => $requiredFinalStage,
                    'finished_stage_label' => config("lost_wax.stages.{$requiredFinalStage}", ucfirst($requiredFinalStage)),
                    'finished_at' => $scannedAt,
                    'finished_at_formatted' => $scannedAt->format('d/m/Y H:i'),
                ]);
            }
        }

        // 3. GROUPING IDENTICAL ITEMS
        // Group by (production_code, item_name, customer, finished_stage)
        $grouped = $rawRows->groupBy(function ($row) {
            return $row['production_code'].'|'.$row['item_name'].'|'.$row['customer'].'|'.$row['finished_stage'];
        })->map(function (Collection $members, $groupKey) {
            $first = $members->first();

            // Sort members internally by finished_at ASC, barcode ASC
            $sortedMembers = $members->sort(function ($a, $b) {
                $timeA = $a['finished_at'] instanceof Carbon ? $a['finished_at']->timestamp : 0;
                $timeB = $b['finished_at'] instanceof Carbon ? $b['finished_at']->timestamp : 0;
                if ($timeA !== $timeB) {
                    return $timeA <=> $timeB;
                }

                return strcmp($a['barcode'], $b['barcode']);
            })->values();

            $earliest = $sortedMembers->first();
            $latest = $sortedMembers->last();

            $treeCount = (int) $members->sum('tree_count');
            $usablePcs = (int) $members->sum('quantity_pcs');
            $grossPcs = (int) $members->sum('gross_pcs');
            $defectPcs = (int) $members->sum('defect_pcs');

            $barcodes = $members->pluck('barcode')->unique()->values()->all();
            $humanBarcodes = $members->pluck('human_barcode')->unique()->values()->all();
            $racks = $members->pluck('rack_label')->unique()->filter(fn ($r) => $r !== '-')->values()->all();
            $racksCompact = empty($racks) ? '-' : implode(', ', $racks);

            $barcodesWithRack = $members->map(function ($m) {
                $rack = $m['rack_label'] !== '-' ? "[{$m['rack_label']}]" : '';

                return trim("{$m['barcode']} {$rack}");
            })->values()->all();

            $earliestFinishedAt = $earliest['finished_at'];
            $latestFinishedAt = $latest['finished_at'];

            return [
                'group_key' => $groupKey,
                'production_code' => $first['production_code'],
                'item_name' => $first['item_name'],
                'customer' => $first['customer'],
                'tree_count' => $treeCount,
                'quantity_pcs' => $usablePcs,
                'gross_pcs' => $grossPcs,
                'defect_pcs' => $defectPcs,
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
                'is_multi_allocated' => $members->contains(fn ($m) => $m['is_multi_allocated']),
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
                    || str_contains(strtolower($item['racks_compact']), $searchLower)
                    || collect($item['barcodes'])->contains(fn ($b) => str_contains(strtolower($b), $searchLower))
                    || collect($item['human_barcodes'])->contains(fn ($b) => str_contains(strtolower($b), $searchLower));
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

        // 6. Build accountable summary metrics
        $filteredUniqueTreeIds = $sortedGroups->flatMap(function ($g) {
            return collect($g['members'])->pluck('tree_id');
        })->unique()->count();

        $totalRangkai = (int) $sortedGroups->sum('tree_count');
        $totalUsablePcs = (int) $sortedGroups->sum('quantity_pcs');
        $totalGrossPcs = (int) $sortedGroups->sum('gross_pcs');
        $totalDefectPcs = (int) $sortedGroups->sum('defect_pcs');
        $totalGroups = $sortedGroups->count();
        $distinctPlans = $sortedGroups->pluck('production_code')->filter(fn ($c) => $c !== '-' && ! empty($c))->unique()->count();

        $summary = [
            'total_unique_trees' => $filteredUniqueTreeIds,
            'total_rangkai' => $totalRangkai,
            'total_usable_pcs' => $totalUsablePcs,
            'total_gross_pcs' => $totalGrossPcs,
            'total_defect_pcs' => $totalDefectPcs,
            'total_groups' => $totalGroups,
            'distinct_plans_count' => $distinctPlans,
            'total_trees' => $filteredUniqueTreeIds, // alias for backwards compatibility
            'total_rows' => $totalGroups,           // alias for backwards compatibility
            'generated_at' => Carbon::now(config('app.timezone')),
            'generated_at_formatted' => Carbon::now(config('app.timezone'))->format('d/m/Y H:i:s'),
        ];

        return [
            'items' => $sortedGroups,
            'raw_items' => $rawRows,
            'summary' => $summary,
        ];
    }
}
