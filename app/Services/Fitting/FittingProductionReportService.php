<?php

namespace App\Services\Fitting;

use App\Models\FittingItem;
use App\Models\FittingStageExecution;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FittingProductionReportService
{
    /**
     * Canonical 6 stages of Fitting Production in strict pipeline order.
     */
    public const STAGES = [
        FittingItem::STAGE_NETTO_PF => 'Netto PF',
        FittingItem::STAGE_KIMIA => 'Kimia',
        FittingItem::STAGE_MARKING => 'Marking',
        FittingItem::STAGE_BUBUT_CNC_PF => 'Bubut CNC PF',
        FittingItem::STAGE_QC_PF => 'QC PF',
        FittingItem::STAGE_GUDANG_JADI_PF => 'Gudang Jadi PF',
    ];

    /**
     * Mapping from stage code to execution checkpoint code.
     */
    public const STAGE_CHECKPOINTS = [
        FittingItem::STAGE_NETTO_PF => FittingStageExecution::CHECKPOINT_NETTO,
        FittingItem::STAGE_KIMIA => FittingStageExecution::CHECKPOINT_KIMIA,
        FittingItem::STAGE_MARKING => FittingStageExecution::CHECKPOINT_MARKING,
        FittingItem::STAGE_BUBUT_CNC_PF => FittingStageExecution::CHECKPOINT_CNC,
        FittingItem::STAGE_QC_PF => FittingStageExecution::CHECKPOINT_QC,
        FittingItem::STAGE_GUDANG_JADI_PF => FittingStageExecution::CHECKPOINT_GUDANG_JADI,
    ];

    /**
     * Sequential stage ordering (1-based index).
     */
    public const STAGE_ORDER = [
        FittingItem::STAGE_NETTO_PF => 1,
        FittingItem::STAGE_KIMIA => 2,
        FittingItem::STAGE_MARKING => 3,
        FittingItem::STAGE_BUBUT_CNC_PF => 4,
        FittingItem::STAGE_QC_PF => 5,
        FittingItem::STAGE_GUDANG_JADI_PF => 6,
    ];

    public function __construct(
        protected FittingQuantityResolverService $quantityResolver
    ) {}

    /**
     * Get the order number for a specific canonical stage.
     */
    public static function getStageOrder(string $stage): int
    {
        $canonical = FittingStageAuthorizationService::normalizeStage($stage) ?? $stage;

        return self::STAGE_ORDER[$canonical] ?? 99;
    }

    /**
     * Get the canonical normalized production report dataset for Fitting Production.
     *
     * @param  array{
     *     date_from?: string,
     *     date_to?: string,
     *     stage?: string,
     *     search?: string,
     *     line?: int|string|null
     * }  $filters
     * @return array{
     *     stage_summaries: Collection<int, array<string, mixed>>,
     *     items: Collection<int, array<string, mixed>>,
     *     summary: array{
     *         total_pfktr_activities: int,
     *         total_input_pcs: int,
     *         total_qty_output: int,
     *         total_good_pcs: int,
     *         total_defect: int,
     *         total_defect_pcs: int,
     *         total_weight: float,
     *         total_weight_kg: float,
     *         total_weight_ton: float,
     *         overall_defect_rate: float,
     *         total_records: int
     *     },
     *     filters: array{
     *         date_from: string,
     *         date_to: string,
     *         stage: string,
     *         search: string,
     *         line: int|null
     *     },
     *     stages: array<string, string>,
     *     lines: array<int, string>
     * }
     */
    public function getProductionDataset(array $filters = [], ?User $user = null, bool $includeDetails = true): array
    {
        $dateFrom = ! empty($filters['date_from']) ? Carbon::parse($filters['date_from'])->format('Y-m-d') : date('Y-m-d');
        $dateTo = ! empty($filters['date_to']) ? Carbon::parse($filters['date_to'])->format('Y-m-d') : date('Y-m-d');
        $selectedStage = ! empty($filters['stage']) ? strtolower(trim((string) $filters['stage'])) : 'all';
        $search = trim((string) ($filters['search'] ?? ''));
        $lineFilter = FittingItem::normalizeLineNumber($filters['line'] ?? null);

        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $allStageKeys = array_keys(self::STAGES);
        $execStages = ($selectedStage === 'all' || ! in_array($selectedStage, $allStageKeys, true))
            ? $allStageKeys
            : [$selectedStage];

        // 1. Build Base Executions Query
        $eagerLoads = [
            'fittingItem' => function ($iq) {
                $iq->with([
                    'transfer',
                    'productionPlan',
                    'allocations' => fn ($aq) => $aq->orderBy('id', 'asc'),
                    'stageExecutions' => fn ($seq) => $seq->orderBy('executed_at', 'asc')->orderBy('id', 'asc'),
                ]);
            },
            'operator',
            'defects.defectType',
        ];

        $execQuery = FittingStageExecution::query()
            ->with($eagerLoads)
            ->whereIn('stage', $execStages)
            ->whereNotNull('physical_done_at')
            ->whereDate('physical_done_at', '>=', $dateFrom)
            ->whereDate('physical_done_at', '<=', $dateTo);

        // Apply Line Filter on parent traveler
        if ($lineFilter !== null) {
            $execQuery->whereHas('fittingItem', function (Builder $iq) use ($lineFilter) {
                $iq->where('line_number', $lineFilter)
                    ->orWhereHas('allocations', fn ($aq) => $aq->where('line_number', $lineFilter));
            });
        }

        // Apply Search Filter (PFKTR, Heat, Production Code, Item Name, Customer, PO Number)
        if ($search !== '') {
            $execQuery->where(function (Builder $q) use ($search) {
                $q->whereHas('fittingItem', function (Builder $iq) use ($search) {
                    $iq->where('traveler_number', 'like', "%{$search}%")
                        ->orWhere('heat_number', 'like', "%{$search}%")
                        ->orWhere('production_code', 'like', "%{$search}%")
                        ->orWhere('item_name', 'like', "%{$search}%")
                        ->orWhere('item_code', 'like', "%{$search}%")
                        ->orWhere('customer', 'like', "%{$search}%")
                        ->orWhereHas('productionPlan', function (Builder $pq) use ($search) {
                            $pq->where('code', 'like', "%{$search}%")
                                ->orWhere('po_number', 'like', "%{$search}%")
                                ->orWhere('customer', 'like', "%{$search}%");
                        })
                        ->orWhereHas('allocations', function (Builder $aq) use ($search) {
                            $aq->where('production_code', 'like', "%{$search}%")
                                ->orWhere('item_name', 'like', "%{$search}%")
                                ->orWhere('customer', 'like', "%{$search}%");
                        });
                });
            });
        }

        $executions = $execQuery->orderBy('physical_done_at', 'asc')->get();

        // 2. Build Stage Summaries (Strict 6 Stages, Anti Double-Count via grouping by fitting_item_id)
        $stageSummaries = collect();
        $totalPfktrActivities = 0;
        $totalInputPcs = 0;
        $totalDefectPcs = 0;
        $totalGoodPcs = 0;
        $totalWeightKg = 0.0;

        foreach ($allStageKeys as $stgKey) {
            if ($selectedStage !== 'all' && $selectedStage !== $stgKey) {
                continue;
            }

            $stgExecs = $executions->where('stage', $stgKey);
            // Anti double-count: Group executions by parent traveler ID within this stage
            $groupedByItem = $stgExecs->groupBy('fitting_item_id');

            $stgKtrCount = $groupedByItem->count();
            $stgInputPcs = 0;
            $stgDefectPcs = 0;
            $stgGoodPcs = 0;
            $stgWeightKg = 0.0;

            $lineBreakdown = [
                1 => ['label' => 'Line 1', 'pcs' => 0, 'kg' => 0.0],
                2 => ['label' => 'Line 2', 'pcs' => 0, 'kg' => 0.0],
                3 => ['label' => 'Line 3', 'pcs' => 0, 'kg' => 0.0],
                4 => ['label' => 'Line 4', 'pcs' => 0, 'kg' => 0.0],
                'unassigned' => ['label' => 'Unassigned', 'pcs' => 0, 'kg' => 0.0],
            ];

            foreach ($groupedByItem as $itemId => $itemExecs) {
                /** @var FittingStageExecution|null $exec */
                $exec = $itemExecs->last();
                /** @var FittingItem|null $item */
                $item = $exec?->fittingItem;
                if (! $item) {
                    continue;
                }

                // SSOT Dynamic Operational Quantities
                $effectiveInput = $this->quantityResolver->resolveEffectiveInputQty($item, $stgKey);
                $defect = (int) $itemExecs->sum('defect_qty');
                $effectiveGood = $this->quantityResolver->resolveEffectiveGoodQty($item, $stgKey);

                $unitWeight = (float) ($item->unit_weight_kg ?? 0.0);
                $itemWeightKg = round($effectiveGood * $unitWeight, 2);

                $stgInputPcs += $effectiveInput;
                $stgDefectPcs += $defect;
                $stgGoodPcs += $effectiveGood;
                $stgWeightKg += $itemWeightKg;

                // Line classification
                $normLine = FittingItem::normalizeLineNumber($item->line_number);
                $lineKey = $normLine !== null ? $normLine : 'unassigned';
                $lineBreakdown[$lineKey]['pcs'] += $effectiveGood;
                $lineBreakdown[$lineKey]['kg'] += $itemWeightKg;
            }

            $defectRate = $stgInputPcs > 0 ? round(($stgDefectPcs / $stgInputPcs) * 100, 2) : 0.0;

            $stageSummaries->push([
                'stage' => $stgKey,
                'stage_label' => self::STAGES[$stgKey] ?? strtoupper($stgKey),
                'stage_order' => self::getStageOrder($stgKey),
                'ktr_count' => $stgKtrCount,
                'input_pcs' => $stgInputPcs,
                'defect_pcs' => $stgDefectPcs,
                'good_pcs' => $stgGoodPcs,
                'defect_rate' => $defectRate,
                'weight_kg' => round($stgWeightKg, 2),
                'line_breakdown' => $lineBreakdown,
            ]);

            $totalPfktrActivities += $stgKtrCount;
            $totalInputPcs += $stgInputPcs;
            $totalDefectPcs += $stgDefectPcs;
            $totalGoodPcs += $stgGoodPcs;
            $totalWeightKg += $stgWeightKg;
        }

        // 3. Build Detailed Rows (per PFKTR & Execution)
        $detailItems = collect();
        if ($includeDetails) {
            foreach ($executions as $exec) {
                /** @var FittingItem|null $item */
                $item = $exec->fittingItem;
                if (! $item) {
                    continue;
                }

                $stg = (string) $exec->stage;
                $effectiveInput = $this->quantityResolver->resolveEffectiveInputQty($item, $stg);
                $defectQty = (int) $exec->defect_qty;
                $effectiveGood = $this->quantityResolver->resolveEffectiveGoodQty($item, $stg);

                $unitWeight = (float) ($item->unit_weight_kg ?? 0.0);
                $totalWeight = round($effectiveGood * $unitWeight, 2);
                $doneDate = $exec->physical_done_at ? $exec->physical_done_at->format('Y-m-d H:i') : '-';
                $operatorName = $exec->operator?->name ?: '-';

                $normLine = FittingItem::normalizeLineNumber($item->line_number);

                // Multi-SPK Allocation Breakdown
                $allocationsData = [];
                $isMulti = (bool) $item->is_multi_allocation || ($item->allocations && $item->allocations->count() > 1);

                if ($isMulti && $item->allocations && $item->allocations->isNotEmpty()) {
                    $allocEntries = [];
                    foreach ($item->allocations as $alloc) {
                        $allocEntries[] = [
                            'id' => (int) $alloc->id,
                            'weight' => (float) $alloc->allocated_qty_transferred,
                            'capacity' => (int) $alloc->allocated_qty_transferred,
                        ];
                    }

                    // Distribute input and defect proportionally using Largest Remainder Method
                    $inputDist = FittingProductionStatusService::distributeCapConstrainedLargestRemainder($effectiveInput, $allocEntries);
                    $defectDist = FittingProductionStatusService::distributeCapConstrainedLargestRemainder($defectQty, $allocEntries);

                    foreach ($item->allocations as $alloc) {
                        $aId = (int) $alloc->id;
                        $aInput = $inputDist[$aId] ?? 0;
                        $aDefect = $defectDist[$aId] ?? 0;
                        $aGood = max(0, $aInput - $aDefect);
                        $aWeight = (float) ($alloc->unit_weight_kg ?? $unitWeight);

                        $allocationsData[] = [
                            'allocation_id' => $aId,
                            'production_plan_id' => $alloc->production_plan_id,
                            'production_code' => $alloc->production_code ?: ($alloc->productionPlan?->code ?? '-'),
                            'item_name' => $alloc->item_name ?: ($alloc->productionPlan?->item_name ?? '-'),
                            'customer' => $alloc->customer ?: ($alloc->productionPlan?->customer ?? '-'),
                            'line_number' => FittingItem::normalizeLineNumber($alloc->line_number) ?? $normLine,
                            'allocated_input_qty' => $aInput,
                            'allocated_defect_qty' => $aDefect,
                            'allocated_good_qty' => $aGood,
                            'allocated_weight_kg' => round($aGood * $aWeight, 2),
                        ];
                    }
                }

                $detailItems->push([
                    'id' => $exec->id,
                    'execution_id' => $exec->id,
                    'fitting_item_id' => $item->id,
                    'traveler_number' => (string) $item->traveler_number,
                    'heat_number' => (string) ($item->heat_number ?? '-'),
                    'cast_date' => $item->cast_date ? $item->cast_date->format('Y-m-d') : '-',
                    'production_code' => (string) ($item->production_code ?: ($item->productionPlan?->code ?? '-')),
                    'item_code' => (string) ($item->item_code ?? '-'),
                    'item_name' => (string) ($item->item_name ?: ($item->productionPlan?->item_name ?? '-')),
                    'customer' => (string) ($item->customer ?: ($item->productionPlan?->customer ?? '-')),
                    'line_number' => $normLine,
                    'line_label' => FittingItem::lineLabelFor($normLine),
                    'stage' => $stg,
                    'stage_label' => self::STAGES[$stg] ?? strtoupper($stg),
                    'stage_order' => self::getStageOrder($stg),
                    'checkpoint_code' => (string) $exec->checkpoint_code,
                    'raw_input_qty' => (int) $exec->input_qty,
                    'input_qty' => $effectiveInput,
                    'defect_qty' => $defectQty,
                    'good_qty' => $effectiveGood,
                    'weight' => $unitWeight,
                    'total_weight' => $totalWeight,
                    'physical_done_at' => $doneDate,
                    'operator' => $operatorName,
                    'status' => $exec->status,
                    'is_multi_allocation' => $isMulti,
                    'allocations' => $allocationsData,
                    'notes' => $exec->notes,
                ]);
            }
        }

        $overallDefectRate = $totalInputPcs > 0 ? round(($totalDefectPcs / $totalInputPcs) * 100, 2) : 0.0;
        $totalWeightKg = round($totalWeightKg, 2);

        return [
            'stage_summaries' => $stageSummaries,
            'stageSummaries' => $stageSummaries,
            'items' => $detailItems,
            'summary' => [
                'total_pfktr_activities' => $totalPfktrActivities,
                'total_input_pcs' => $totalInputPcs,
                'total_qty_output' => $totalGoodPcs,
                'total_good_pcs' => $totalGoodPcs,
                'total_defect' => $totalDefectPcs,
                'total_defect_pcs' => $totalDefectPcs,
                'total_weight' => $totalWeightKg,
                'total_weight_kg' => $totalWeightKg,
                'total_weight_ton' => round($totalWeightKg / 1000, 2),
                'overall_defect_rate' => $overallDefectRate,
                'total_records' => $detailItems->count(),
            ],
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'stage' => $selectedStage,
                'search' => $search,
                'line' => $lineFilter,
            ],
            'stages' => self::STAGES,
            'lines' => FittingItem::LINE_LABELS,
        ];
    }
}
