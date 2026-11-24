<?php

namespace App\Services\Fitting;

use App\Models\DefectType;
use App\Models\FittingItem;
use App\Models\FittingStageExecution;
use App\Models\FittingStageExecutionDefect;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class FittingQcDefectReportService
{
    /**
     * Canonical 6 stages where Fitting stage executions and QC defect verifications take place.
     */
    public const STAGES = [
        'netto_pf' => 'Netto PF',
        'kimia' => 'Kimia',
        'marking' => 'Marking',
        'bubut_cnc_pf' => 'Bubut CNC PF',
        'qc_pf' => 'QC PF',
        'gudang_jadi_pf' => 'Gudang Jadi PF',
    ];

    public const STAGE_LABELS = [
        'netto_pf' => 'NETTO PF',
        'kimia' => 'KIMIA',
        'marking' => 'MARKING',
        'bubut_cnc_pf' => 'BUBUT CNC PF',
        'qc_pf' => 'QC PF',
        'gudang_jadi_pf' => 'GUDANG JADI PF',
    ];

    /**
     * Get defect types master list applicable for a specific stage or all stages.
     *
     * @return Collection<int, DefectType>
     */
    public function getDefectTypesForStage(?string $stage = null): Collection
    {
        $query = DefectType::query()->active()->orderBy('name', 'asc');

        if ($stage && $stage !== 'all') {
            $canonical = FittingStageAuthorizationService::normalizeStage($stage) ?? $stage;
            $departments = array_unique(array_filter([$canonical, 'fitting']));

            $query->where(function (Builder $q) use ($departments) {
                $q->whereIn('department', $departments)
                    ->orWhereNull('department')
                    ->orWhere('department', 'all');
            });
        } else {
            $query->where(function (Builder $q) {
                $q->where('department', 'fitting')
                    ->orWhereIn('department', \App\Models\FittingItem::STAGES)
                    ->orWhereNull('department')
                    ->orWhere('department', 'all');
            });
        }

        return $query->get();
    }

    /**
     * Resolve effective unit weight in KG for a Fitting Item.
     * Hierarchy:
     * 1. item.unit_weight_kg (if populated and > 0)
     * 2. plan.weight (if populated and > 0)
     * 3. null
     */
    public function resolveEffectiveUnitWeightKg(?FittingItem $item): ?float
    {
        if (! $item) {
            return null;
        }

        if ($item->unit_weight_kg !== null && (float) $item->unit_weight_kg > 0) {
            return (float) $item->unit_weight_kg;
        }

        if ($item->productionPlan && $item->productionPlan->weight !== null && (float) $item->productionPlan->weight > 0) {
            return (float) $item->productionPlan->weight;
        }

        return null;
    }

    /**
     * Build the normalized Fitting QC Defect Report dataset.
     *
     * @param  array{date_from?: string, date_to?: string, stage?: string, line?: int|null, defect_type_id?: int|string|null, search?: string}  $filters
     * @return array<string, mixed>
     */
    public function getReportDataset(array $filters = [], ?User $user = null, bool $paginate = true, int $perPage = 25): array
    {
        $defaultDate = Carbon::now()->toDateString();
        $dateFrom = ! empty($filters['date_from']) ? $filters['date_from'] : $defaultDate;
        $dateTo = ! empty($filters['date_to']) ? $filters['date_to'] : $defaultDate;
        $selectedStage = ! empty($filters['stage']) ? strtolower(trim((string) $filters['stage'])) : 'all';
        $lineFilter = FittingItem::normalizeLineNumber($filters['line'] ?? null);
        $defectTypeId = ! empty($filters['defect_type_id']) && $filters['defect_type_id'] !== 'all' ? (int) $filters['defect_type_id'] : null;
        $search = trim((string) ($filters['search'] ?? ''));

        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        // 1. Build Base Query for Confirmed Defect Breakdown Rows
        $query = FittingStageExecutionDefect::query()
            ->with([
                'defectType',
                'stageExecution' => function ($eq) {
                    $eq->with([
                        'qcVerifiedBy',
                        'operator',
                        'defectEnteredBy',
                        'fittingItem.productionPlan',
                        'fittingItem.allocations',
                    ]);
                },
            ])
            ->whereHas('stageExecution', function (Builder $eq) use ($dateFrom, $dateTo, $selectedStage, $lineFilter, $search) {
                // Strictly CONFIRMED executions with defect_qty > 0
                $eq->where('status', FittingStageExecution::STATUS_CONFIRMED)
                    ->where('defect_qty', '>', 0)
                    ->whereHas('fittingItem', fn (Builder $iq) => $iq->where('status', FittingItem::STATUS_ACTIVE));

                // Date hierarchy: inspection_date -> physical_done_at fallback
                $eq->where(function (Builder $dq) use ($dateFrom, $dateTo) {
                    $dq->where(function (Builder $sub1) use ($dateFrom, $dateTo) {
                        $sub1->whereNotNull('fitting_stage_executions.inspection_date')
                            ->where(function (Builder $idq) use ($dateFrom, $dateTo) {
                                $idq->whereBetween('fitting_stage_executions.inspection_date', [$dateFrom, $dateTo])
                                    ->orWhereBetween('fitting_stage_executions.inspection_date', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59']);
                            });
                    })->orWhere(function (Builder $sub2) use ($dateFrom, $dateTo) {
                        $sub2->whereNull('fitting_stage_executions.inspection_date')
                            ->whereNotNull('fitting_stage_executions.physical_done_at')
                            ->whereBetween('fitting_stage_executions.physical_done_at', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59']);
                    });
                });

                // Stage filter
                if ($selectedStage !== 'all') {
                    $canonicalStage = FittingStageAuthorizationService::normalizeStage($selectedStage) ?? $selectedStage;
                    $eq->where('stage', $canonicalStage);
                }

                // Line filter (Line 1-4)
                if ($lineFilter !== null) {
                    $eq->whereHas('fittingItem', function (Builder $iq) use ($lineFilter) {
                        $iq->where('line_number', $lineFilter)
                            ->orWhereHas('allocations', fn (Builder $aq) => $aq->where('line_number', $lineFilter));
                    });
                }

                // Search across PFKTR, Heat, Production Code, Item Name, Customer, PO
                if ($search !== '') {
                    $eq->whereHas('fittingItem', function (Builder $iq) use ($search) {
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
                }
            });

        // Filter by specific defect type ID if selected
        if ($defectTypeId) {
            $query->where('defect_type_id', $defectTypeId);
        }

        // Deterministic ordering: ID desc
        $query->orderByDesc('id');

        // 2. Fetch full matching collection for aggregation and Pareto summary
        $allBreakdowns = $query->get();

        // 3. Map into structured view records
        $allRecords = $allBreakdowns->map(function (FittingStageExecutionDefect $def) {
            $exec = $def->stageExecution;
            $item = $exec?->fittingItem;
            $plan = $item?->productionPlan;
            $unitWeight = $this->resolveEffectiveUnitWeightKg($item);
            $hasWeight = ($unitWeight !== null && $unitWeight > 0);
            $qty = (int) $def->qty;
            $estKg = $hasWeight ? round($qty * $unitWeight, 2) : null;

            // Effective defect date hierarchy: inspection_date -> physical_done_at
            $effectiveDateStr = $exec?->resolveEffectiveDefectDate();
            $effectiveDate = $effectiveDateStr ? Carbon::parse($effectiveDateStr) : null;

            $stageKey = (string) ($exec?->stage ?? 'unknown');
            $stageLabel = self::STAGE_LABELS[$stageKey] ?? strtoupper(str_replace('_', ' ', $stageKey));
            $normLine = $item?->line_number ? FittingItem::normalizeLineNumber($item->line_number) : 1;

            return [
                'id' => $def->id,
                'execution_id' => $exec?->id,
                'date' => $effectiveDate ? $effectiveDate->format('d/m/Y') : '—',
                'date_raw' => $effectiveDate ? $effectiveDate->toDateString() : null,
                'inspection_date' => $exec?->inspection_date ? Carbon::parse($exec->inspection_date)->format('d/m/Y') : null,
                'physical_done_at' => $exec?->physical_done_at ? Carbon::parse($exec->physical_done_at)->format('d/m/Y H:i') : null,
                'verified_at_formatted' => $exec?->qc_verified_at ? Carbon::parse($exec->qc_verified_at)->format('d/m/Y H:i') : ($effectiveDate ? $effectiveDate->format('d/m/Y') : '—'),
                'traveler_number' => $item?->traveler_number ?? '—',
                'heat_number' => $item?->heat_number ?? '—',
                'cast_date' => $item?->cast_date ? Carbon::parse($item->cast_date)->format('d/m/Y') : '—',
                'production_code' => (string) ($item?->production_code ?: ($plan?->code ?? '—')),
                'po_number' => (string) ($plan?->po_number ?? '—'),
                'item_name' => (string) ($item?->item_name ?: ($plan?->item_name ?? '—')),
                'customer' => (string) ($item?->customer ?: ($plan?->customer ?? '—')),
                'line_number' => $normLine,
                'line_label' => FittingItem::lineLabelFor($normLine),
                'stage' => $stageKey,
                'stage_label' => $stageLabel,
                'checkpoint_code' => $exec?->checkpoint_code ?? '—',
                'defect_type_id' => $def->defect_type_id,
                'defect_name' => $def->defectType?->name ?? 'Lain-lain',
                'defect_department' => $def->defectType?->department ?? '—',
                'qty' => $qty,
                'unit_weight' => $unitWeight,
                'has_weight' => $hasWeight,
                'estimated_kg' => $estKg,
                'qc_verifier' => $exec?->qcVerifiedBy?->name ?? 'Admin QC',
                'operator' => $exec?->operator?->name ?? ($exec->defectEnteredBy?->name ?? '—'),
                'defect_entered_by' => $exec?->defectEnteredBy?->name ?? '—',
                'notes' => $def->notes ?: ($exec?->notes ?? '—'),
                'is_multi_allocation' => (bool) $item?->is_multi_allocation,
                'allocations_count' => $item?->allocations?->count() ?? 0,
            ];
        });

        // 4. Compute Summary KPIs over the full dataset
        $totalDefectPcs = (int) $allRecords->sum('qty');
        $validWeightRecords = $allRecords->where('has_weight', true);
        $totalEstimatedKg = (float) $validWeightRecords->sum('estimated_kg');
        $validWeightPcs = (int) $validWeightRecords->sum('qty');
        $unavailableWeightPcs = $totalDefectPcs - $validWeightPcs;
        $weightCoveragePct = ($totalDefectPcs > 0)
            ? round(($validWeightPcs / $totalDefectPcs) * 100, 1)
            : 100.0;

        $uniqueKtrs = $allRecords->pluck('traveler_number')->filter(fn ($t) => $t !== '—')->unique()->values();
        $affectedKtrCount = $uniqueKtrs->count();

        // 5. Pareto Table (Grouped by defect_type_id)
        $paretoGroups = $allRecords->groupBy('defect_type_id');
        $paretoRows = [];

        foreach ($paretoGroups as $dtId => $items) {
            $first = $items->first();
            $pcs = (int) $items->sum('qty');
            $validItems = $items->where('has_weight', true);
            $estKg = (float) $validItems->sum('estimated_kg');
            $validPcs = (int) $validItems->sum('qty');
            $unavailPcs = $pcs - $validPcs;
            $ktrCount = $items->pluck('traveler_number')->filter(fn ($t) => $t !== '—')->unique()->count();
            $contribPct = ($totalDefectPcs > 0) ? round(($pcs / $totalDefectPcs) * 100, 1) : 0.0;

            // Per stage breakdown
            $stageBreakdown = [];
            foreach (array_keys(self::STAGES) as $stgKey) {
                $stageBreakdown[$stgKey] = (int) $items->where('stage', $stgKey)->sum('qty');
            }

            $paretoRows[] = [
                'defect_type_id' => $dtId,
                'defect_name' => $first['defect_name'],
                'qty_pcs' => $pcs,
                'contribution_pct' => $contribPct,
                'estimated_kg' => round($estKg, 2),
                'valid_weight_pcs' => $validPcs,
                'unavailable_weight_pcs' => $unavailPcs,
                'has_unavailable_weight' => ($unavailPcs > 0),
                'ktr_count' => $ktrCount,
                'stage_breakdown' => $stageBreakdown,
            ];
        }

        // Sort Pareto table by defect PCS desc, then estimated KG desc
        usort($paretoRows, function ($a, $b) {
            if ($b['qty_pcs'] !== $a['qty_pcs']) {
                return $b['qty_pcs'] <=> $a['qty_pcs'];
            }

            return $b['estimated_kg'] <=> $a['estimated_kg'];
        });

        $topDefectType = ! empty($paretoRows) ? $paretoRows[0]['defect_name'] : '—';

        // 6. Stage Distribution Summary (Strict 6 Stages)
        $stageDistribution = [];
        foreach (self::STAGES as $stgKey => $stgLabel) {
            $stgItems = $allRecords->where('stage', $stgKey);
            $stgPcs = (int) $stgItems->sum('qty');
            $stgKtrs = $stgItems->pluck('traveler_number')->filter(fn ($t) => $t !== '—')->unique()->count();
            $stgEstKg = (float) $stgItems->where('has_weight', true)->sum('estimated_kg');
            $stgContrib = ($totalDefectPcs > 0) ? round(($stgPcs / $totalDefectPcs) * 100, 1) : 0.0;

            // Top defect in this stage
            $stgTopDefect = $stgItems->groupBy('defect_name')->sortByDesc(fn ($g) => $g->sum('qty'))->keys()->first() ?? '—';

            $stageDistribution[$stgKey] = [
                'stage' => $stgKey,
                'stage_label' => $stgLabel,
                'defect_pcs' => $stgPcs,
                'contribution_pct' => $stgContrib,
                'affected_ktr_count' => $stgKtrs,
                'estimated_kg' => round($stgEstKg, 2),
                'top_defect_name' => $stgTopDefect,
            ];
        }

        // 7. Pagination of detail records for web presentation
        if ($paginate) {
            $page = LengthAwarePaginator::resolveCurrentPage();
            $totalCount = $allRecords->count();
            $pagedItems = $allRecords->slice(($page - 1) * $perPage, $perPage)->values();

            $detailPaginator = new LengthAwarePaginator(
                $pagedItems,
                $totalCount,
                $perPage,
                $page,
                ['path' => LengthAwarePaginator::resolveCurrentPath()]
            );
        } else {
            $detailPaginator = $allRecords;
        }

        // Available defect choices for the dropdown selector
        $availableDefectTypes = $this->getDefectTypesForStage($selectedStage);

        return [
            'records' => $detailPaginator,
            'all_records' => $allRecords,
            'pareto_summary' => $paretoRows,
            'paretoSummary' => $paretoRows,
            'stage_distribution' => $stageDistribution,
            'stageDistribution' => $stageDistribution,
            'summary' => [
                'total_defect_pcs' => $totalDefectPcs,
                'total_estimated_kg' => round($totalEstimatedKg, 2),
                'affected_ktr_count' => $affectedKtrCount,
                'top_defect_type' => $topDefectType,
                'valid_weight_pcs' => $validWeightPcs,
                'unavailable_weight_pcs' => $unavailableWeightPcs,
                'weight_coverage_pct' => $weightCoveragePct,
                'is_weight_fully_available' => ($unavailableWeightPcs === 0),
                'total_records_count' => $allRecords->count(),
            ],
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'stage' => $selectedStage,
                'line' => $lineFilter,
                'defect_type_id' => $defectTypeId,
                'search' => $search,
            ],
            'stages' => self::STAGES,
            'stage_labels' => self::STAGE_LABELS,
            'stageLabels' => self::STAGE_LABELS,
            'lines' => FittingItem::LINE_LABELS,
            'available_defect_types' => $availableDefectTypes,
            'availableDefectTypes' => $availableDefectTypes,
        ];
    }
}
