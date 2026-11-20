<?php

namespace App\Services\Fitting;

use App\Models\FittingItem;
use App\Models\FittingItemAllocation;
use App\Models\FittingStageExecution;
use App\Models\ProductionPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class FittingProductionStatusService
{
    public function __construct(
        protected FittingQuantityResolverService $quantityResolver,
        protected FittingStageAuthorizationService $authService
    ) {}

    /**
     * Get aggregated production status data with global KPI, tab counters, and pagination.
     *
     * @param  array{
     *     tab?: string,
     *     status?: string,
     *     line?: int|string|null,
     *     search?: string|null,
     *     customer?: string|null,
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     date_basis?: string|null,
     *     sort_by?: string|null,
     *     sort_dir?: string|null,
     *     per_page?: int|null,
     *     page?: int|null
     * }  $filters
     * @return array{
     *     rows: LengthAwarePaginator|array,
     *     summary: array{
     *         total_spk_aktif: int,
     *         total_target_po: int,
     *         total_selesai_gd: int,
     *         total_sisa_target: int,
     *         total_defect_tercatat: int,
     *         total_defect_qc: int
     *     },
     *     tab_counts: array{
     *         active: int,
     *         completed: int,
     *         all: int,
     *         non_spk: int
     *     },
     *     non_spk_rows: Collection,
     *     filters: array
     * }
     */
    public function getProductionStatusData(array $filters = [], ?User $user = null): array
    {
        $tab = strtolower(trim((string) ($filters['tab'] ?? $filters['status'] ?? 'active')));
        if (! in_array($tab, ['active', 'completed', 'all', 'non_spk'], true)) {
            $tab = 'active';
        }

        $line = FittingItem::normalizeLineNumber($filters['line'] ?? null);
        $search = trim((string) ($filters['search'] ?? ''));
        $customer = trim((string) ($filters['customer'] ?? ''));
        $dateBasis = strtolower(trim((string) ($filters['date_basis'] ?? 'transfer')));
        if (! in_array($dateBasis, ['transfer', 'spk'], true)) {
            $dateBasis = 'transfer';
        }

        $dateFrom = ! empty($filters['date_from']) ? Carbon::parse($filters['date_from'])->format('Y-m-d') : null;
        $dateTo = ! empty($filters['date_to']) ? Carbon::parse($filters['date_to'])->format('Y-m-d') : null;

        $sortBy = (string) ($filters['sort_by'] ?? 'code');
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        // 1. Build base query for ProductionPlans associated with Fitting
        $planQuery = ProductionPlan::query()
            ->where(function (Builder $q) {
                $q->whereHas('fittingItems', fn ($iq) => $iq->where('status', FittingItem::STATUS_ACTIVE))
                    ->orWhereHas('fittingItemAllocations.fittingItem', fn ($iq) => $iq->where('status', FittingItem::STATUS_ACTIVE));
            });

        // Line filter (ProductionPlan line or Item/Allocation line)
        if ($line !== null) {
            $planQuery->where(function (Builder $q) use ($line) {
                $q->where('line_number', $line)
                    ->orWhereHas('fittingItems', fn ($iq) => $iq->where('status', FittingItem::STATUS_ACTIVE)->where('line_number', $line))
                    ->orWhereHas('fittingItemAllocations.fittingItem', fn ($iq) => $iq->where('status', FittingItem::STATUS_ACTIVE)->where('line_number', $line));
            });
        }

        // Search filter (Code, Part Name, Item Code, Customer, PO Number)
        if ($search !== '') {
            $planQuery->where(function (Builder $q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('item_name', 'like', "%{$search}%")
                    ->orWhere('item_code', 'like', "%{$search}%")
                    ->orWhere('customer', 'like', "%{$search}%")
                    ->orWhere('po_number', 'like', "%{$search}%");
            });
        }

        // Customer filter
        if ($customer !== '') {
            $planQuery->where('customer', $customer);
        }

        // Date filter: selects SPKs active in that date range, then full history of the SPK is calculated
        if ($dateFrom !== null || $dateTo !== null) {
            $start = $dateFrom ?? '1970-01-01';
            $end = $dateTo ?? '2099-12-31';

            if ($dateBasis === 'spk') {
                $planQuery->whereBetween('created_at', ["{$start} 00:00:00", "{$end} 23:59:59"]);
            } else {
                // Default: Transfer Date
                $planQuery->where(function (Builder $q) use ($start, $end) {
                    $q->whereHas('fittingItems.transfer', fn ($tq) => $tq->whereBetween('transfer_date', [$start, $end]))
                        ->orWhereHas('fittingItemAllocations.fittingItem.transfer', fn ($tq) => $tq->whereBetween('transfer_date', [$start, $end]));
                });
            }
        }

        // Eager load active fitting items and allocations
        $plans = $planQuery->with([
            'fittingItems' => fn ($q) => $q->where('status', FittingItem::STATUS_ACTIVE)
                ->with([
                    'transfer',
                    'stageExecutions' => fn ($eq) => $eq->orderBy('executed_at', 'asc')->orderBy('id', 'asc')->with('defects'),
                ]),
            'fittingItemAllocations' => fn ($q) => $q->whereHas('fittingItem', fn ($iq) => $iq->where('status', FittingItem::STATUS_ACTIVE))
                ->with([
                    'fittingItem' => fn ($iq) => $iq->with([
                        'transfer',
                        'allocations' => fn ($aq) => $aq->orderBy('id', 'asc'),
                        'stageExecutions' => fn ($eq) => $eq->orderBy('executed_at', 'asc')->orderBy('id', 'asc')->with('defects'),
                    ]),
                ]),
        ])->get();

        // 2. Aggregate all SPK rows
        $allCalculatedRows = collect();
        $rowNum = 1;

        foreach ($plans as $plan) {
            $row = $this->calculatePlanStatusRow($plan, $rowNum);
            $allCalculatedRows->push($row);
            $rowNum++;
        }

        // Sort all calculated rows
        $sortedRows = $this->sortRows($allCalculatedRows, $sortBy, $sortDir);

        // Calculate tab counts
        $activeRows = $sortedRows->where('status', 'ACTIVE')->values();
        $completedRows = $sortedRows->where('status', 'COMPLETED')->values();

        // 3. Query Non-SPK PFKTRs (isolated from SPK aggregation)
        $nonSpkItems = $this->getNonSpkItems($line, $search, $customer, $dateFrom, $dateTo);

        $tabCounts = [
            'active' => $activeRows->count(),
            'completed' => $completedRows->count(),
            'all' => $sortedRows->count(),
            'non_spk' => $nonSpkItems->count(),
        ];

        // Global KPI calculation (follows active filters, not restricted to pagination page)
        $kpiTargetPo = $activeRows->sum('po_target');
        $kpiSelesaiGd = $sortedRows->sum('gd');
        $kpiSisaTarget = $activeRows->sum('remaining_po');
        $kpiTotalDefect = $sortedRows->sum('total_defect_tercatat');
        $kpiTotalDefectQc = $sortedRows->sum('total_defect_qc');

        $summary = [
            'total_spk_aktif' => $activeRows->count(),
            'total_target_po' => $kpiTargetPo,
            'total_selesai_gd' => $kpiSelesaiGd,
            'total_sisa_target' => $kpiSisaTarget,
            'total_defect_tercatat' => $kpiTotalDefect,
            'total_defect_qc' => $kpiTotalDefectQc,
        ];

        // Select rows for current tab
        $selectedRows = match ($tab) {
            'active' => $activeRows,
            'completed' => $completedRows,
            'all' => $sortedRows,
            'non_spk' => collect(), // Handled via non_spk_rows
        };

        // Paginate selected SPK rows
        $paginatedRows = new LengthAwarePaginator(
            $selectedRows->forPage($page, $perPage)->values(),
            $selectedRows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return [
            'rows' => $paginatedRows,
            'summary' => $summary,
            'tab_counts' => $tabCounts,
            'non_spk_rows' => $nonSpkItems,
            'filters' => [
                'tab' => $tab,
                'line' => $line,
                'search' => $search,
                'customer' => $customer,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'date_basis' => $dateBasis,
                'sort_by' => $sortBy,
                'sort_dir' => $sortDir,
                'per_page' => $perPage,
            ],
        ];
    }

    /**
     * Calculate one status row for a single ProductionPlan.
     * Implements sequential mass balance and Cap-Constrained Largest Remainder Method.
     *
     * @return array<string, mixed>
     */
    public function calculatePlanStatusRow(ProductionPlan $plan, int $rowNumber = 1): array
    {
        // Target PO: official definition
        $poTarget = ($plan->po_quantity !== null && (int) $plan->po_quantity > 0)
            ? (int) $plan->po_quantity
            : (int) $plan->qty_planned;

        $planQty = (int) $plan->qty_planned;

        // Collect all distinct physical travelers relevant to this SPK
        // A traveler can be linked directly or via allocations
        $singleItems = $plan->fittingItems ? $plan->fittingItems->where('is_multi_allocation', false)->all() : [];
        $allocations = $plan->fittingItemAllocations ? $plan->fittingItemAllocations->all() : [];

        $totalTransferred = 0;
        $totalWip = [
            FittingItem::STAGE_NETTO_PF => 0,
            FittingItem::STAGE_KIMIA => 0,
            FittingItem::STAGE_MARKING => 0,
            FittingItem::STAGE_BUBUT_CNC_PF => 0,
            FittingItem::STAGE_QC_PF => 0,
        ];
        $totalDefects = [
            FittingItem::STAGE_NETTO_PF => 0,
            FittingItem::STAGE_KIMIA => 0,
            FittingItem::STAGE_MARKING => 0,
            FittingItem::STAGE_BUBUT_CNC_PF => 0,
            FittingItem::STAGE_QC_PF => 0,
        ];
        $totalDefectsQc = [
            FittingItem::STAGE_NETTO_PF => 0,
            FittingItem::STAGE_KIMIA => 0,
            FittingItem::STAGE_MARKING => 0,
            FittingItem::STAGE_BUBUT_CNC_PF => 0,
            FittingItem::STAGE_QC_PF => 0,
        ];
        $totalGd = 0;
        $pfktrNumbers = [];

        // 1. Process Single-Allocation PFKTRs
        foreach ($singleItems as $item) {
            $pfktrNumbers[] = $item->traveler_number;
            $itemQtyTransferred = (int) $item->qty_transferred;
            $totalTransferred += $itemQtyTransferred;

            $execs = $item->relationLoaded('stageExecutions') ? $item->stageExecutions : $item->stageExecutions()->get();
            $execByStage = $execs->keyBy(fn ($e) => FittingQuantityResolverService::normalizeStageName($e->stage));

            // Sequential stage progression
            $currentEffective = $itemQtyTransferred;
            $isCompleted = ($item->current_stage === FittingItem::STAGE_COMPLETED);

            foreach (FittingItem::STAGES as $stage) {
                if ($stage === FittingItem::STAGE_GUDANG_JADI_PF) {
                    if ($isCompleted) {
                        $totalGd += $currentEffective;
                    }

                    continue;
                }

                $exec = $execByStage->get($stage);
                $defectRecorded = $exec ? (int) $exec->defect_qty : 0;
                $defectQc = ($exec && $exec->status === FittingStageExecution::STATUS_CONFIRMED) ? (int) $exec->defect_qty : 0;

                $totalDefects[$stage] += $defectRecorded;
                $totalDefectsQc[$stage] += $defectQc;

                // WIP belongs to stage if traveler's current_stage is this stage
                if ($item->current_stage === $stage) {
                    $totalWip[$stage] += $currentEffective;
                }

                // Advance effective input
                $currentEffective = max(0, $currentEffective - $defectRecorded);
            }
        }

        // 2. Process Multi-Allocation PFKTRs
        // Group allocations by fitting_item_id to process each multi-allocation PFKTR as a whole
        $allocsByItem = collect($allocations)->groupBy('fitting_item_id');

        foreach ($allocsByItem as $itemId => $itemAllocs) {
            /** @var FittingItemAllocation $currentPlanAlloc */
            $currentPlanAlloc = $itemAllocs->first();
            $parentItem = $currentPlanAlloc->fittingItem;
            if (! $parentItem || $parentItem->status !== FittingItem::STATUS_ACTIVE) {
                continue;
            }

            $pfktrNumbers[] = $parentItem->traveler_number;

            // Load all sibling allocations of this parent PFKTR in deterministic ORDER BY id ASC
            $allParentAllocs = $parentItem->relationLoaded('allocations')
                ? $parentItem->allocations->sortBy('id')->values()
                : $parentItem->allocations()->orderBy('id', 'asc')->get();

            $parentExecs = $parentItem->relationLoaded('stageExecutions')
                ? $parentItem->stageExecutions
                : $parentItem->stageExecutions()->get();
            $execByStage = $parentExecs->keyBy(fn ($e) => FittingQuantityResolverService::normalizeStageName($e->stage));

            $isCompleted = ($parentItem->current_stage === FittingItem::STAGE_COMPLETED);

            // Compute sequential mass balance per allocation
            $planPortion = $this->resolveMultiAllocationPortionForPlan(
                $parentItem,
                $allParentAllocs,
                $execByStage,
                (int) $plan->id,
                $isCompleted
            );

            $totalTransferred += $planPortion['transfer_qty'];
            $totalGd += $planPortion['gd_qty'];

            foreach (FittingItem::STAGES as $st) {
                if ($st === FittingItem::STAGE_GUDANG_JADI_PF) {
                    continue;
                }
                $totalWip[$st] += $planPortion['wip'][$st] ?? 0;
                $totalDefects[$st] += $planPortion['defects'][$st] ?? 0;
                $totalDefectsQc[$st] += $planPortion['defects_qc'][$st] ?? 0;
            }
        }

        // 3. Totals & Shortage Evaluation
        $totalDefectTercatat = array_sum($totalDefects);
        $totalDefectQc = array_sum($totalDefectsQc);

        $remainingPo = max(0, $poTarget - $totalGd);

        // Available Good uses ONLY QC-verified defects
        $availableGood = max(0, $totalTransferred - $totalDefectQc);
        $shortage = max(0, $poTarget - $availableGood);
        $kitirBadge = $shortage > 0 ? 'KITIR' : '';

        // Status is COMPLETED if Gudang Jadi >= Target PO
        $status = ($totalGd >= $poTarget) ? 'COMPLETED' : 'ACTIVE';

        // Line resolution (from SPK, fallback to first item/allocation line)
        $lineNum = FittingItem::normalizeLineNumber($plan->line_number);
        if ($lineNum === null && ! empty($singleItems)) {
            $lineNum = FittingItem::normalizeLineNumber($singleItems[0]->line_number);
        }
        if ($lineNum === null && ! empty($allocations)) {
            $lineNum = FittingItem::normalizeLineNumber($allocations[0]->line_number);
        }

        return [
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'title' => $plan->title,
            'customer' => $plan->customer ?? '-',
            'item_code' => $plan->item_code ?? '-',
            'item_name' => $plan->item_name ?? '-',
            'size' => $plan->size ?? '-',
            'line_number' => $lineNum,
            'line_label' => FittingItem::lineLabelFor($lineNum),
            'po_target' => $poTarget,
            'po_quantity' => $plan->po_quantity,
            'planned_qty' => $planQty,
            'kitir_transfer' => $totalTransferred,
            'available_good' => $availableGood,
            'shortage' => $shortage,
            'kitir_badge' => $kitirBadge,
            'wip_netto' => $totalWip[FittingItem::STAGE_NETTO_PF],
            'r_netto' => $totalDefects[FittingItem::STAGE_NETTO_PF],
            'wip_kimia' => $totalWip[FittingItem::STAGE_KIMIA],
            'r_kimia' => $totalDefects[FittingItem::STAGE_KIMIA],
            'wip_marking' => $totalWip[FittingItem::STAGE_MARKING],
            'r_marking' => $totalDefects[FittingItem::STAGE_MARKING],
            'wip_cnc' => $totalWip[FittingItem::STAGE_BUBUT_CNC_PF],
            'r_cnc' => $totalDefects[FittingItem::STAGE_BUBUT_CNC_PF],
            'wip_qc' => $totalWip[FittingItem::STAGE_QC_PF],
            'r_qc' => $totalDefects[FittingItem::STAGE_QC_PF],
            'gd' => $totalGd,
            'remaining_po' => $remainingPo,
            'status' => $status,
            'total_defect_tercatat' => $totalDefectTercatat,
            'total_defect_qc' => $totalDefectQc,
            'pfktr_count' => count(array_unique($pfktrNumbers)),
            'pfktr_numbers' => array_values(array_unique($pfktrNumbers)),
        ];
    }

    /**
     * Resolve sequential mass-balance portions for a specific ProductionPlan within a multi-allocation PFKTR.
     * Enforces Cap-Constrained Largest Remainder Method on defect distribution.
     *
     * @param  Collection<int, FittingItemAllocation>  $allAllocs
     * @param  Collection<string, FittingStageExecution>  $execByStage
     * @return array{
     *     transfer_qty: int,
     *     gd_qty: int,
     *     wip: array<string, int>,
     *     defects: array<string, int>,
     *     defects_qc: array<string, int>
     * }
     */
    protected function resolveMultiAllocationPortionForPlan(
        FittingItem $parentItem,
        Collection $allAllocs,
        Collection $execByStage,
        int $targetPlanId,
        bool $isCompleted
    ): array {
        $totalRootTransfer = (int) $parentItem->qty_transferred;
        if ($totalRootTransfer <= 0 || $allAllocs->isEmpty()) {
            return [
                'transfer_qty' => 0,
                'gd_qty' => 0,
                'wip' => [],
                'defects' => [],
                'defects_qc' => [],
            ];
        }

        // Initial state per allocation
        $allocStates = [];
        foreach ($allAllocs as $alloc) {
            $allocInitial = (int) $alloc->allocated_qty_transferred;
            $allocStates[$alloc->id] = [
                'id' => (int) $alloc->id,
                'plan_id' => (int) $alloc->production_plan_id,
                'initial_transfer' => $allocInitial,
                'current_good' => $allocInitial,
                'wip' => [
                    FittingItem::STAGE_NETTO_PF => 0,
                    FittingItem::STAGE_KIMIA => 0,
                    FittingItem::STAGE_MARKING => 0,
                    FittingItem::STAGE_BUBUT_CNC_PF => 0,
                    FittingItem::STAGE_QC_PF => 0,
                ],
                'defects' => [
                    FittingItem::STAGE_NETTO_PF => 0,
                    FittingItem::STAGE_KIMIA => 0,
                    FittingItem::STAGE_MARKING => 0,
                    FittingItem::STAGE_BUBUT_CNC_PF => 0,
                    FittingItem::STAGE_QC_PF => 0,
                ],
                'defects_qc' => [
                    FittingItem::STAGE_NETTO_PF => 0,
                    FittingItem::STAGE_KIMIA => 0,
                    FittingItem::STAGE_MARKING => 0,
                    FittingItem::STAGE_BUBUT_CNC_PF => 0,
                    FittingItem::STAGE_QC_PF => 0,
                ],
                'gd' => 0,
            ];
        }

        // Sequential stage progression
        foreach (FittingItem::STAGES as $stage) {
            if ($stage === FittingItem::STAGE_GUDANG_JADI_PF) {
                if ($isCompleted) {
                    foreach ($allocStates as $aId => &$st) {
                        $st['gd'] = $st['current_good'];
                    }
                    unset($st);
                }

                continue;
            }

            $exec = $execByStage->get($stage);
            $defectRecorded = $exec ? (int) $exec->defect_qty : 0;
            $defectQc = ($exec && $exec->status === FittingStageExecution::STATUS_CONFIRMED) ? (int) $exec->defect_qty : 0;

            // Prepare entries for Cap-Constrained Largest Remainder Method
            $entries = [];
            foreach ($allocStates as $aId => $st) {
                $entries[] = [
                    'id' => $aId,
                    'weight' => (float) $st['initial_transfer'],
                    'capacity' => (int) $st['current_good'],
                ];
            }

            // Distribute recorded defect
            $distRecorded = self::distributeCapConstrainedLargestRemainder($defectRecorded, $entries);
            // Distribute QC verified defect
            $distQc = self::distributeCapConstrainedLargestRemainder($defectQc, $entries);

            // Update states
            foreach ($allocStates as $aId => &$st) {
                $allocDefect = $distRecorded[$aId] ?? 0;
                $allocDefectQc = $distQc[$aId] ?? 0;

                $st['defects'][$stage] = $allocDefect;
                $st['defects_qc'][$stage] = $allocDefectQc;

                // WIP if parentItem is currently at this stage
                if ($parentItem->current_stage === $stage) {
                    $st['wip'][$stage] = $st['current_good'];
                }

                // Advance good input
                $st['current_good'] = max(0, $st['current_good'] - $allocDefect);
            }
            unset($st);
        }

        // Sum up parts for the target plan
        $planTransfer = 0;
        $planGd = 0;
        $planWip = [
            FittingItem::STAGE_NETTO_PF => 0,
            FittingItem::STAGE_KIMIA => 0,
            FittingItem::STAGE_MARKING => 0,
            FittingItem::STAGE_BUBUT_CNC_PF => 0,
            FittingItem::STAGE_QC_PF => 0,
        ];
        $planDefects = [
            FittingItem::STAGE_NETTO_PF => 0,
            FittingItem::STAGE_KIMIA => 0,
            FittingItem::STAGE_MARKING => 0,
            FittingItem::STAGE_BUBUT_CNC_PF => 0,
            FittingItem::STAGE_QC_PF => 0,
        ];
        $planDefectsQc = [
            FittingItem::STAGE_NETTO_PF => 0,
            FittingItem::STAGE_KIMIA => 0,
            FittingItem::STAGE_MARKING => 0,
            FittingItem::STAGE_BUBUT_CNC_PF => 0,
            FittingItem::STAGE_QC_PF => 0,
        ];

        foreach ($allocStates as $st) {
            if ($st['plan_id'] === $targetPlanId) {
                $planTransfer += $st['initial_transfer'];
                $planGd += $st['gd'];
                foreach (FittingItem::STAGES as $s) {
                    if ($s === FittingItem::STAGE_GUDANG_JADI_PF) {
                        continue;
                    }
                    $planWip[$s] += $st['wip'][$s] ?? 0;
                    $planDefects[$s] += $st['defects'][$s] ?? 0;
                    $planDefectsQc[$s] += $st['defects_qc'][$s] ?? 0;
                }
            }
        }

        return [
            'transfer_qty' => $planTransfer,
            'gd_qty' => $planGd,
            'wip' => $planWip,
            'defects' => $planDefects,
            'defects_qc' => $planDefectsQc,
        ];
    }

    /**
     * Cap-Constrained Largest Remainder Method (Hare-Niemeyer with capacity clamp and tie-breaker).
     *
     * Rules:
     * - Initial proportions based on initial transfer weights.
     * - Distributed quantity per allocation cannot exceed its capacity (Good Input).
     * - Any overflow defect is iteratively redistributed among allocations that still have capacity.
     * - Tie-breaker: allocation.id ASC.
     * - Sum of distributed integers equals min($totalQty, sum(capacities)).
     * - Never produces negative values.
     *
     * @param  int  $totalQty  Quantity to distribute
     * @param  array<int, array{id: int, weight: float, capacity: int}>  $allocationEntries
     * @return array<int, int> Map of allocation_id => distributed_qty
     */
    public static function distributeCapConstrainedLargestRemainder(int $totalQty, array $allocationEntries): array
    {
        $result = [];
        foreach ($allocationEntries as $entry) {
            $result[$entry['id']] = 0;
        }

        if ($totalQty <= 0 || empty($allocationEntries)) {
            return $result;
        }

        $totalCapacity = array_sum(array_column($allocationEntries, 'capacity'));
        $targetToDistribute = min($totalQty, $totalCapacity);
        if ($targetToDistribute <= 0) {
            return $result;
        }

        $remainingToDistribute = $targetToDistribute;
        $activeEntries = $allocationEntries;

        // Iterative redistribution until all target quantity is distributed within capacity
        while ($remainingToDistribute > 0 && ! empty($activeEntries)) {
            // Filter to only entries with remaining capacity > 0
            $eligible = [];
            foreach ($activeEntries as $entry) {
                $remCap = $entry['capacity'] - ($result[$entry['id']] ?? 0);
                if ($remCap > 0) {
                    $eligible[] = [
                        'id' => $entry['id'],
                        'weight' => max(0.0001, (float) $entry['weight']),
                        'rem_cap' => $remCap,
                    ];
                }
            }

            if (empty($eligible)) {
                break;
            }

            $totalWeight = array_sum(array_column($eligible, 'weight'));
            if ($totalWeight <= 0) {
                $totalWeight = count($eligible);
                foreach ($eligible as &$el) {
                    $el['weight'] = 1.0;
                }
                unset($el);
            }

            // Step 1: Compute quotas, base integers, and remainders
            $baseTotal = 0;
            $itemsWithQuota = [];

            foreach ($eligible as $el) {
                $quota = ($el['weight'] / $totalWeight) * $remainingToDistribute;
                $base = (int) floor($quota);
                // Clamp base to remaining capacity
                $clampedBase = min($base, $el['rem_cap']);
                $remainder = $quota - $base;

                $baseTotal += $clampedBase;
                $itemsWithQuota[] = [
                    'id' => $el['id'],
                    'rem_cap' => $el['rem_cap'],
                    'base' => $clampedBase,
                    'remainder' => $remainder,
                ];
            }

            // Assign base integers to result
            foreach ($itemsWithQuota as $iq) {
                $result[$iq['id']] += $iq['base'];
            }

            $surplus = $remainingToDistribute - $baseTotal;
            if ($surplus <= 0) {
                break;
            }

            // Step 2: Sort candidates for surplus distribution (+1)
            // Order: Has remaining headroom DESC, Remainder DESC, ID ASC (Tie-breaker)
            usort($itemsWithQuota, function ($a, $b) use ($result, $allocationEntries) {
                $entryA = collect($allocationEntries)->firstWhere('id', $a['id']);
                $entryB = collect($allocationEntries)->firstWhere('id', $b['id']);
                $headroomA = ($entryA['capacity'] ?? 0) - ($result[$a['id']] ?? 0);
                $headroomB = ($entryB['capacity'] ?? 0) - ($result[$b['id']] ?? 0);

                $canTakeA = $headroomA > 0 ? 1 : 0;
                $canTakeB = $headroomB > 0 ? 1 : 0;
                if ($canTakeA !== $canTakeB) {
                    return $canTakeB <=> $canTakeA;
                }

                // Remainder DESC (float compare with small epsilon)
                if (abs($b['remainder'] - $a['remainder']) > 1e-9) {
                    return $b['remainder'] <=> $a['remainder'];
                }

                // Tie-breaker: ID ASC
                return $a['id'] <=> $b['id'];
            });

            // Distribute surplus +1 to top candidates with headroom
            $distributedInSurplus = 0;
            foreach ($itemsWithQuota as $iq) {
                if ($distributedInSurplus >= $surplus) {
                    break;
                }
                $entry = collect($allocationEntries)->firstWhere('id', $iq['id']);
                $headroom = ($entry['capacity'] ?? 0) - ($result[$iq['id']] ?? 0);
                if ($headroom > 0) {
                    $result[$iq['id']] += 1;
                    $distributedInSurplus++;
                }
            }

            $remainingToDistribute = $targetToDistribute - array_sum($result);

            // Re-evaluate activeEntries for next iteration if surplus could not be completely placed
            $newActive = [];
            foreach ($allocationEntries as $entry) {
                if (($result[$entry['id']] ?? 0) < $entry['capacity']) {
                    $newActive[] = $entry;
                }
            }
            $activeEntries = $newActive;
        }

        return $result;
    }

    /**
     * Get isolated Non-SPK PFKTR travelers (active PFKTRs with NULL production_plan_id and no allocations).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getNonSpkItems(
        ?int $line = null,
        string $search = '',
        string $customer = '',
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): Collection {
        $query = FittingItem::query()
            ->where('status', FittingItem::STATUS_ACTIVE)
            ->whereNull('production_plan_id')
            ->doesntHave('allocations')
            ->with(['transfer', 'stageExecutions.defects']);

        if ($line !== null) {
            $query->where('line_number', $line);
        }

        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('traveler_number', 'like', "%{$search}%")
                    ->orWhere('heat_number', 'like', "%{$search}%")
                    ->orWhere('production_code', 'like', "%{$search}%")
                    ->orWhere('item_name', 'like', "%{$search}%")
                    ->orWhere('item_code', 'like', "%{$search}%");
            });
        }

        if ($customer !== '') {
            $query->where('customer', $customer);
        }

        if ($dateFrom !== null || $dateTo !== null) {
            $start = $dateFrom ?? '1970-01-01';
            $end = $dateTo ?? '2099-12-31';
            $query->whereHas('transfer', fn ($tq) => $tq->whereBetween('transfer_date', [$start, $end]));
        }

        $items = $query->orderBy('traveler_number', 'asc')->get();

        return $items->map(function (FittingItem $item) {
            $effectiveInput = $this->quantityResolver->resolveEffectiveInputQty($item, (string) $item->current_stage);
            $execs = $item->relationLoaded('stageExecutions') ? $item->stageExecutions : $item->stageExecutions()->get();
            $totalDefect = (int) $execs->sum('defect_qty');

            return [
                'id' => $item->id,
                'traveler_number' => $item->traveler_number,
                'heat_number' => $item->heat_number,
                'cast_date' => $item->cast_date?->format('d/m/Y') ?? '-',
                'transfer_number' => $item->transfer?->transfer_number ?? '-',
                'transfer_date' => $item->transfer?->transfer_date?->format('d/m/Y') ?? '-',
                'production_code' => $item->production_code ?: '(Tanpa Kode)',
                'item_name' => $item->item_name ?: '-',
                'item_code' => $item->item_code ?: '-',
                'customer' => $item->customer ?: '-',
                'line_number' => $item->line_number,
                'line_label' => FittingItem::lineLabelFor($item->line_number),
                'qty_transferred' => (int) $item->qty_transferred,
                'effective_input' => $effectiveInput,
                'total_defect' => $totalDefect,
                'current_stage' => $item->current_stage,
                'current_stage_label' => FittingStageAuthorizationService::STAGE_LABELS[$item->current_stage] ?? strtoupper((string) $item->current_stage),
            ];
        });
    }

    /**
     * Get detailed drilldown PFKTR barcode items for a specific Production Plan / Code.
     *
     * @return array<string, mixed>|null
     */
    public function getPlanDetails(int|string $planIdOrCode, ?User $user = null): ?array
    {
        $plan = ProductionPlan::query()
            ->where(function (Builder $q) use ($planIdOrCode) {
                if (is_numeric($planIdOrCode)) {
                    $q->where('id', (int) $planIdOrCode)->orWhere('code', (string) $planIdOrCode);
                } else {
                    $q->where('code', (string) $planIdOrCode);
                }
            })
            ->with([
                'fittingItems' => fn ($q) => $q->where('status', FittingItem::STATUS_ACTIVE)
                    ->with(['transfer', 'stageExecutions.defects.defectType']),
                'fittingItemAllocations' => fn ($q) => $q->whereHas('fittingItem', fn ($iq) => $iq->where('status', FittingItem::STATUS_ACTIVE))
                    ->with([
                        'fittingItem' => fn ($iq) => $iq->with(['transfer', 'allocations', 'stageExecutions.defects.defectType']),
                    ]),
            ])
            ->first();

        if (! $plan) {
            return null;
        }

        $rowSummary = $this->calculatePlanStatusRow($plan, 1);

        $travelers = [];

        // Single travelers
        foreach ($plan->fittingItems->where('is_multi_allocation', false) as $item) {
            $travelers[] = $this->formatTravelerDetail($item, null);
        }

        // Multi-allocation travelers
        foreach ($plan->fittingItemAllocations as $alloc) {
            if ($alloc->fittingItem && $alloc->fittingItem->status === FittingItem::STATUS_ACTIVE) {
                $travelers[] = $this->formatTravelerDetail($alloc->fittingItem, $alloc);
            }
        }

        return [
            'plan' => [
                'id' => $plan->id,
                'code' => $plan->code,
                'customer' => $plan->customer ?? '-',
                'item_name' => $plan->item_name ?? '-',
                'item_code' => $plan->item_code ?? '-',
                'po_target' => $rowSummary['po_target'],
                'kitir_transfer' => $rowSummary['kitir_transfer'],
                'gd' => $rowSummary['gd'],
                'remaining_po' => $rowSummary['remaining_po'],
                'status' => $rowSummary['status'],
                'kitir_badge' => $rowSummary['kitir_badge'],
                'shortage' => $rowSummary['shortage'],
            ],
            'items' => $travelers,
        ];
    }

    /**
     * Format a single traveler item for the drilldown modal.
     *
     * @return array<string, mixed>
     */
    protected function formatTravelerDetail(FittingItem $item, ?FittingItemAllocation $alloc = null): array
    {
        $trace = $this->quantityResolver->buildStageQuantityTrace($item);
        $execs = $item->relationLoaded('stageExecutions') ? $item->stageExecutions : $item->stageExecutions()->get();

        $stageBreakdowns = [];
        foreach (FittingItem::STAGES as $stage) {
            $exec = $execs->firstWhere('stage', $stage);
            $defectsList = [];
            if ($exec && $exec->relationLoaded('defects')) {
                foreach ($exec->defects as $d) {
                    $defectsList[] = [
                        'type' => $d->defectType?->name ?? 'Cacat',
                        'qty' => (int) $d->qty,
                        'notes' => $d->notes,
                    ];
                }
            }

            $stageBreakdowns[$stage] = [
                'stage' => $stage,
                'label' => FittingStageAuthorizationService::STAGE_LABELS[$stage] ?? strtoupper($stage),
                'input_qty' => $trace[$stage]['effective_input_qty'] ?? 0,
                'defect_qty' => $trace[$stage]['defect_qty'] ?? 0,
                'good_qty' => $trace[$stage]['effective_good_qty'] ?? 0,
                'status' => $exec?->status ?? 'BELUM',
                'executed_at' => $exec?->executed_at?->format('d/m/Y H:i'),
                'defects' => $defectsList,
            ];
        }

        return [
            'traveler_number' => $item->traveler_number,
            'heat_number' => $item->heat_number,
            'cast_date' => $item->cast_date?->format('d/m/Y') ?? '-',
            'transfer_number' => $item->transfer?->transfer_number ?? '-',
            'transfer_date' => $item->transfer?->transfer_date?->format('d/m/Y') ?? '-',
            'line_number' => $item->line_number,
            'line_label' => FittingItem::lineLabelFor($item->line_number),
            'is_multi_allocation' => (bool) $item->is_multi_allocation,
            'allocated_qty' => $alloc ? (int) $alloc->allocated_qty_transferred : (int) $item->qty_transferred,
            'qty_transferred' => (int) $item->qty_transferred,
            'current_stage' => $item->current_stage,
            'current_stage_label' => FittingStageAuthorizationService::STAGE_LABELS[$item->current_stage] ?? strtoupper((string) $item->current_stage),
            'stages' => $stageBreakdowns,
        ];
    }

    /**
     * Sort rows by given field and direction.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    protected function sortRows(Collection $rows, string $sortBy, string $sortDir): Collection
    {
        $descending = ($sortDir === 'desc');

        return $rows->sortBy(function ($row) use ($sortBy) {
            return match ($sortBy) {
                'customer' => strtolower((string) ($row['customer'] ?? '')),
                'item_name' => strtolower((string) ($row['item_name'] ?? '')),
                'target_po', 'po_target' => (int) ($row['po_target'] ?? 0),
                'transfer', 'kitir_transfer' => (int) ($row['kitir_transfer'] ?? 0),
                'gd' => (int) ($row['gd'] ?? 0),
                'remaining_po' => (int) ($row['remaining_po'] ?? 0),
                'status' => (string) ($row['status'] ?? ''),
                default => strtolower((string) ($row['code'] ?? '')),
            };
        }, SORT_REGULAR, $descending)->values();
    }
}
