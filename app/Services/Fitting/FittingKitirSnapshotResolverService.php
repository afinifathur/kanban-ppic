<?php

namespace App\Services\Fitting;

use App\Models\FittingItem;
use App\Models\LostWaxKitir;
use App\Models\LostWaxTree;
use App\Models\ProductionPlan;

/**
 * Pure read-only resolver that computes Fitting transfer snapshots (allocations, line_number,
 * unit_weight_kg, total_weight_kg, item_code, po_number) from immutable Lost Wax & ProductionPlan data.
 *
 * Strictly enforces Architecture Lock rules:
 * - Line 1..4 MUST come exclusively from `production_plans.line_number` via `FittingItem::normalizeLineNumber()`.
 * - Values outside 1..4 or null -> Unassigned (`null`). NEVER infer line from item size/diameter.
 * - Multi-allocation Kitirs spanning different lines -> physical traveler `line_number = null` (Unassigned),
 *   while preserving each individual allocation's normalized `line_number`.
 * - Weight comes from `production_plans.weight` (fallback legacy `itemReference.unit_weight_snapshot`),
 *   calculated per allocation (`allocated_qty * unit_weight`) with weighted average on the physical traveler.
 */
class FittingKitirSnapshotResolverService
{
    public const WEIGHT_STATUS_COMPLETE = 'COMPLETE';

    public const WEIGHT_STATUS_PARTIAL = 'PARTIAL';

    public const WEIGHT_STATUS_MISSING = 'MISSING';

    public const SUGGESTED_LINE_CONSISTENT = 'CONSISTENT';

    public const SUGGESTED_LINE_INCONSISTENT = 'INCONSISTENT';

    public const SUGGESTED_LINE_UNASSIGNED = 'UNASSIGNED';

    /**
     * Resolve full snapshot data for a single LostWaxKitir.
     *
     * @return array{
     *     production_plan_id: int|null,
     *     production_code: string,
     *     item_code: string|null,
     *     item_name: string|null,
     *     customer: string|null,
     *     po_number: string|null,
     *     line_number: int|null,
     *     is_line_assigned: bool,
     *     line_status_reason: string|null,
     *     tree_count: int,
     *     qty_transferred: int,
     *     unit_weight_kg: float,
     *     total_weight_kg: float,
     *     has_weight: bool,
     *     has_complete_weight: bool,
     *     is_weight_incomplete: bool,
     *     weight_status: string,
     *     missing_weight_codes: array<int, string>,
     *     is_multi_allocation: bool,
     *     allocations: array<int, array{
     *         production_plan_id: int|null,
     *         production_code: string,
     *         item_code: string|null,
     *         item_name: string|null,
     *         customer: string|null,
     *         po_number: string|null,
     *         line_number: int|null,
     *         allocated_qty_transferred: int,
     *         unit_weight_kg: float,
     *         allocated_weight_kg: float,
     *         has_weight: bool
     *     }>
     * }
     */
    public function resolve(LostWaxKitir $kitir): array
    {
        if (! $kitir->relationLoaded('castingResult')) {
            $kitir->load('castingResult');
        }

        $castingResult = $kitir->castingResult;
        if ($castingResult && ! $castingResult->relationLoaded('trees')) {
            $castingResult->load([
                'trees.tree.allocations.printOrderLine.productionPlan',
                'trees.tree.printOrderLine.productionPlan',
                'trees.tree.workOrder.itemReference',
            ]);
        }

        $targetCode = trim((string) $kitir->production_code);
        $matchingResultTrees = $castingResult
            ? $castingResult->trees->filter(fn ($t) => trim((string) $t->production_code) === $targetCode)->values()
            : collect();

        $aggregated = [];

        foreach ($matchingResultTrees as $resultTree) {
            $detailQty = (int) $resultTree->quantity_pcs;
            if ($detailQty <= 0) {
                continue;
            }

            /** @var LostWaxTree|null $physTree */
            $physTree = $resultTree->tree;

            if ($physTree && $physTree->allocations->isNotEmpty()) {
                $allocs = $physTree->allocations;
                $grouped = $allocs->groupBy(function ($alloc) use ($resultTree) {
                    $line = $alloc->printOrderLine;
                    $plan = $line?->productionPlan;
                    if ($plan) {
                        return 'plan:'.$plan->id;
                    }
                    $code = trim((string) ($line?->code ?? $resultTree->production_code));

                    return 'code:'.$code;
                });

                $totalGrossAllocated = (int) $allocs->sum('allocated_qty');
                $groupCount = $grouped->count();
                $accumulated = 0;
                $idx = 0;

                foreach ($grouped as $key => $groupAllocs) {
                    $idx++;
                    $firstAlloc = $groupAllocs->first();
                    $line = $firstAlloc->printOrderLine;
                    $plan = $line?->productionPlan;
                    $groupGross = (int) $groupAllocs->sum('allocated_qty');

                    $remaining = max(0, $detailQty - $accumulated);

                    if ($idx === $groupCount) {
                        $proratedQty = $remaining;
                    } elseif ($totalGrossAllocated <= 0) {
                        $calculatedQty = (int) floor($detailQty / max(1, $groupCount));
                        $proratedQty = min($remaining, max(0, $calculatedQty));
                    } else {
                        $calculatedQty = (int) round($detailQty * ($groupGross / $totalGrossAllocated));
                        $proratedQty = min($remaining, max(0, $calculatedQty));
                    }

                    $accumulated += $proratedQty;

                    $this->mergeAllocationBucket(
                        $aggregated,
                        $key,
                        $plan,
                        $physTree,
                        trim((string) ($plan?->code ?? $line?->code ?? $targetCode)),
                        $plan?->item_code,
                        $plan?->item_name ?? $line?->item_name ?? $resultTree->item_name,
                        $plan?->customer ?? $line?->customer ?? $resultTree->customer,
                        $plan?->po_number,
                        FittingItem::normalizeLineNumber($plan?->line_number),
                        $proratedQty
                    );
                }
            } elseif ($physTree && $physTree->printOrderLine) {
                $line = $physTree->printOrderLine;
                $plan = $line->productionPlan;
                $key = $plan ? 'plan:'.$plan->id : 'code:'.trim((string) ($line->code ?? $targetCode));

                $this->mergeAllocationBucket(
                    $aggregated,
                    $key,
                    $plan,
                    $physTree,
                    trim((string) ($plan?->code ?? $line->code ?? $targetCode)),
                    $plan?->item_code,
                    $plan?->item_name ?? $line->item_name ?? $resultTree->item_name,
                    $plan?->customer ?? $line->customer ?? $resultTree->customer,
                    $plan?->po_number,
                    FittingItem::normalizeLineNumber($plan?->line_number),
                    $detailQty
                );
            } elseif ($physTree && $physTree->workOrder) {
                $itemRef = $physTree->workOrder->itemReference;
                $code = trim((string) ($physTree->workOrder->et_code ?? $targetCode));
                $key = 'wo:'.$physTree->workOrder->id.':'.$code;

                $this->mergeAllocationBucket(
                    $aggregated,
                    $key,
                    null,
                    $physTree,
                    $code,
                    $itemRef?->item_code_snapshot,
                    $itemRef?->item_name_snapshot ?? $resultTree->item_name,
                    $physTree->workOrder->customer_name ?? $resultTree->customer,
                    null,
                    null,
                    $detailQty
                );
            } else {
                // Simplified tree without printOrderLine/workOrder: preserve Kitir metadata without fabricating Line/Weight
                $key = 'fallback:'.$targetCode;

                $this->mergeAllocationBucket(
                    $aggregated,
                    $key,
                    null,
                    $physTree,
                    $targetCode,
                    null,
                    $resultTree->item_name ?? $kitir->item_name,
                    $resultTree->customer ?? $kitir->customer,
                    null,
                    null,
                    $detailQty
                );
            }
        }

        // If no matching result trees existed (defensive fallback), construct 1 row directly from Kitir snapshot
        if (empty($aggregated)) {
            $aggregated['kitir:'.$targetCode] = [
                'production_plan_id' => null,
                'production_code' => $targetCode,
                'item_code' => null,
                'item_name' => $kitir->item_name !== '-' ? $kitir->item_name : null,
                'customer' => $kitir->customer !== '-' ? $kitir->customer : null,
                'po_number' => null,
                'line_number' => null,
                'allocated_qty_transferred' => (int) $kitir->quantity_pcs,
                'unit_weight_kg' => 0.0,
                'allocated_weight_kg' => 0.0,
                'has_weight' => false,
            ];
        }

        $allocations = array_values($aggregated);

        // Finalize per-allocation weight calculation and completeness tracking
        $totalWeightKg = 0.0;
        $totalAllocatedQty = 0;
        $anyWeightAvailable = false;
        $allWeightsAvailable = count($allocations) > 0;
        $missingWeightCodes = [];

        foreach ($allocations as &$alloc) {
            $allocQty = max(0, (int) $alloc['allocated_qty_transferred']);
            $unitW = round((float) $alloc['unit_weight_kg'], 4);
            $allocWeight = $alloc['has_weight'] ? round($allocQty * $unitW, 2) : 0.0;

            $alloc['allocated_qty_transferred'] = $allocQty;
            $alloc['unit_weight_kg'] = $unitW;
            $alloc['allocated_weight_kg'] = $allocWeight;

            $totalAllocatedQty += $allocQty;
            $totalWeightKg += $allocWeight;

            if ($alloc['has_weight']) {
                $anyWeightAvailable = true;
            } else {
                $allWeightsAvailable = false;
                $missingWeightCodes[] = $alloc['production_code'];
            }
        }
        unset($alloc);

        $totalWeightKg = round($totalWeightKg, 2);
        $qtyTransferred = (int) $kitir->quantity_pcs;
        $isMultiAllocation = count($allocations) > 1;
        $missingWeightCodes = array_values(array_unique($missingWeightCodes));

        if ($allWeightsAvailable) {
            $weightStatus = self::WEIGHT_STATUS_COMPLETE;
        } elseif ($anyWeightAvailable) {
            $weightStatus = self::WEIGHT_STATUS_PARTIAL;
        } else {
            $weightStatus = self::WEIGHT_STATUS_MISSING;
        }

        // Only compute a weighted average unit weight when ALL allocations have valid weight;
        // if partial weight is missing, keep unit_weight_kg = 0.0 so we never present a misleading average.
        $effectiveUnitWeight = ($allWeightsAvailable && $qtyTransferred > 0)
            ? round($totalWeightKg / $qtyTransferred, 4)
            : 0.0;

        // Resolve Suggested Line (Line Rencana) strictly from ProductionPlan allocations (read-only recommendation)
        // Only CONSISTENT when ALL allocations share the exact same valid Line (1..4).
        // Empty, out-of-range, or differing lines across allocations -> default empty (PPIC must select explicitly).
        $lineNumber = null;
        $suggestedLineStatus = self::SUGGESTED_LINE_UNASSIGNED;
        $suggestedLineLabel = 'Belum Ditentukan';
        $lineStatusReason = null;

        if (! $isMultiAllocation) {
            $lineNumber = $allocations[0]['line_number'];
            if ($lineNumber !== null) {
                $suggestedLineStatus = self::SUGGESTED_LINE_CONSISTENT;
                $suggestedLineLabel = "Line {$lineNumber}";
            } else {
                $suggestedLineStatus = self::SUGGESTED_LINE_UNASSIGNED;
                $suggestedLineLabel = 'Belum Ditentukan';
                $lineStatusReason = 'Line belum ditentukan / di luar 1–4 pada Daftar Rencana';
            }
        } else {
            $allocLines = array_map(fn ($a) => $a['line_number'], $allocations);
            $uniqueLines = array_values(array_unique($allocLines, SORT_REGULAR));
            $nonNullLines = array_values(array_unique(array_filter($allocLines, fn ($l) => $l !== null)));

            if (count($uniqueLines) === 1 && $uniqueLines[0] !== null) {
                $lineNumber = $uniqueLines[0];
                $suggestedLineStatus = self::SUGGESTED_LINE_CONSISTENT;
                $suggestedLineLabel = "Line {$lineNumber}";
            } elseif (count($nonNullLines) > 0) {
                $lineNumber = null;
                $suggestedLineStatus = self::SUGGESTED_LINE_INCONSISTENT;
                $suggestedLineLabel = 'Tidak Konsisten';
                $lineStatusReason = count($nonNullLines) > 1
                    ? 'Multi-allocation beda Line ('.implode(', ', array_map(fn ($l) => "Line {$l}", $nonNullLines)).')'
                    : 'Multi-allocation sebagian memiliki Line kosong / di luar 1–4 (tercatat: Line '.$nonNullLines[0].')';
            } else {
                $lineNumber = null;
                $suggestedLineStatus = self::SUGGESTED_LINE_UNASSIGNED;
                $suggestedLineLabel = 'Belum Ditentukan';
                $lineStatusReason = 'Multi-allocation belum memiliki Line pada Daftar Rencana';
            }
        }

        // Resolve consolidated item_code & po_number for display/snapshot
        $itemCodes = collect($allocations)->pluck('item_code')->filter()->map('trim')->unique()->values();
        $poNumbers = collect($allocations)->pluck('po_number')->filter()->map('trim')->unique()->values();

        return [
            'production_plan_id' => ! $isMultiAllocation ? $allocations[0]['production_plan_id'] : null,
            'production_code' => $targetCode,
            'item_code' => $itemCodes->isNotEmpty() ? substr($itemCodes->implode(', '), 0, 100) : null,
            'item_name' => ($kitir->item_name && $kitir->item_name !== '-') ? $kitir->item_name : ($allocations[0]['item_name'] ?? null),
            'customer' => ($kitir->customer && $kitir->customer !== '-') ? $kitir->customer : ($allocations[0]['customer'] ?? null),
            'po_number' => $poNumbers->isNotEmpty() ? $poNumbers->implode(', ') : null,
            'line_number' => $lineNumber,
            'suggested_line_number' => $lineNumber,
            'suggested_line_status' => $suggestedLineStatus,
            'suggested_line_label' => $suggestedLineLabel,
            'is_line_assigned' => $lineNumber !== null,
            'line_status_reason' => $lineStatusReason,
            'tree_count' => (int) $kitir->tree_count,
            'qty_transferred' => $qtyTransferred,
            'unit_weight_kg' => $effectiveUnitWeight,
            'total_weight_kg' => $totalWeightKg,
            'has_weight' => $anyWeightAvailable,
            'has_complete_weight' => $allWeightsAvailable,
            'is_weight_incomplete' => ! $allWeightsAvailable,
            'weight_status' => $weightStatus,
            'missing_weight_codes' => $missingWeightCodes,
            'is_multi_allocation' => $isMultiAllocation,
            'allocations' => $allocations,
        ];
    }

    /**
     * Merge an allocation contribution into the aggregation map keyed by plan/code.
     */
    protected function mergeAllocationBucket(
        array &$aggregated,
        string $key,
        ?ProductionPlan $plan,
        ?LostWaxTree $tree,
        string $productionCode,
        ?string $itemCode,
        ?string $itemName,
        ?string $customer,
        ?string $poNumber,
        ?int $normalizedLine,
        int $qty
    ): void {
        $unitWeight = $this->resolveUnitWeight($plan, $tree);
        $hasWeight = $unitWeight !== null && $unitWeight > 0;

        if (! isset($aggregated[$key])) {
            $aggregated[$key] = [
                'production_plan_id' => $plan?->id,
                'production_code' => $productionCode !== '' ? $productionCode : '-',
                'item_code' => $itemCode ? trim($itemCode) : null,
                'item_name' => ($itemName && $itemName !== '-') ? trim($itemName) : null,
                'customer' => ($customer && $customer !== '-') ? trim($customer) : null,
                'po_number' => ($poNumber && $poNumber !== '-') ? trim($poNumber) : null,
                'line_number' => $normalizedLine,
                'allocated_qty_transferred' => $qty,
                'unit_weight_kg' => $hasWeight ? $unitWeight : 0.0,
                'allocated_weight_kg' => 0.0,
                'has_weight' => $hasWeight,
            ];
        } else {
            $aggregated[$key]['allocated_qty_transferred'] += $qty;
            if (! $aggregated[$key]['has_weight'] && $hasWeight) {
                $aggregated[$key]['unit_weight_kg'] = $unitWeight;
                $aggregated[$key]['has_weight'] = true;
            }
        }
    }

    /**
     * Resolve unit weight strictly from ProductionPlan::weight, with legacy LostWaxItemReference fallback.
     */
    protected function resolveUnitWeight(?ProductionPlan $plan, ?LostWaxTree $tree): ?float
    {
        if ($plan && $plan->weight !== null) {
            $val = (float) $plan->weight;
            if ($val > 0) {
                return $val;
            }
        }

        if ($tree && $tree->workOrder && $tree->workOrder->itemReference) {
            $val = (float) $tree->workOrder->itemReference->unit_weight_snapshot;
            if ($val > 0) {
                return $val;
            }
        }

        return null;
    }
}
