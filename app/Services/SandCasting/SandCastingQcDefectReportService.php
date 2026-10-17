<?php

namespace App\Services\SandCasting;

use App\Models\DefectType;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\SandCastingStageExecutionDefect;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class SandCastingQcDefectReportService
{
    /**
     * Canonical 6 stages where stage executions and QC defect verifications take place.
     */
    public const STAGES = [
        'netto' => 'Netto',
        'bubut_od' => 'Bubut OD',
        'bubut_cnc' => 'Bubut CNC',
        'bor' => 'Bor',
        'qc' => 'QC',
        'gudang_jadi' => 'Gudang Jadi',
    ];

    public const STAGE_LABELS = [
        'netto' => 'NETTO',
        'bubut_od' => 'BUBUT OD',
        'bubut_cnc' => 'BUBUT CNC',
        'bor' => 'BOR',
        'qc' => 'QC',
        'gudang_jadi' => 'GUDANG JADI',
    ];

    /**
     * Get defect types master list applicable for a specific stage or all stages.
     * Includes compatibility mapping for 'qc' stage to also match defect_types with department = 'finish'.
     *
     * @return Collection<int, DefectType>
     */
    public function getDefectTypesForStage(?string $stage = null): Collection
    {
        $query = DefectType::query()->active()->orderBy('name', 'asc');

        if ($stage && $stage !== 'all') {
            $canonical = SandCastingStageAuthorizationService::normalizeStage($stage) ?? $stage;
            $departments = [$canonical];

            // Compatibility mapping: 'qc' operational stage corresponds to 'finish' in defect_types table
            if ($canonical === 'qc') {
                $departments[] = 'finish';
            }

            $query->where(function (Builder $q) use ($departments) {
                $q->whereIn('department', $departments)
                    ->orWhereNull('department')
                    ->orWhere('department', 'all');
            });
        }

        return $query->get();
    }

    /**
     * Resolve effective unit weight in KG for a KTR line.
     * Uses audited snapshot-first rule:
     * 1. line.unit_weight_kg (if populated and > 0)
     * 2. plan.weight (if populated and > 0)
     * 3. null (unavailable)
     */
    public function resolveEffectiveUnitWeightKg(?SandCastingCastingResultLine $line): ?float
    {
        if (! $line) {
            return null;
        }

        if ($line->unit_weight_kg !== null && (float) $line->unit_weight_kg > 0) {
            return (float) $line->unit_weight_kg;
        }

        if ($line->productionPlan && $line->productionPlan->weight !== null && (float) $line->productionPlan->weight > 0) {
            return (float) $line->productionPlan->weight;
        }

        return null;
    }

    /**
     * Build the normalized QC Defect Detail Report dataset.
     *
     * @param  array{date_from?: string, date_to?: string, stage?: string, defect_type_id?: int|string|null, search?: string, scope?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function getReportDataset(array $filters = [], ?User $user = null, bool $paginate = true, int $perPage = 25): array
    {
        $currentUser = $user ?? auth()->user();
        if (array_key_exists('scope', $filters)) {
            $scope = $filters['scope'];
        } elseif ($currentUser && $currentUser->hasRole('ppic')) {
            $scope = $currentUser->product_scope;
        } else {
            $scope = null;
        }

        $dateFrom = ! empty($filters['date_from']) ? $filters['date_from'] : Carbon::now()->subDays(29)->toDateString();
        $dateTo = ! empty($filters['date_to']) ? $filters['date_to'] : Carbon::now()->toDateString();
        $selectedStage = ! empty($filters['stage']) ? $filters['stage'] : 'all';
        $defectTypeId = ! empty($filters['defect_type_id']) && $filters['defect_type_id'] !== 'all' ? (int) $filters['defect_type_id'] : null;
        $search = trim((string) ($filters['search'] ?? ''));

        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        // 1. Build Base Query for Confirmed Defect Breakdown Rows
        $query = SandCastingStageExecutionDefect::query()
            ->with([
                'defectType',
                'stageExecution' => function ($eq) {
                    $eq->with([
                        'qcVerifiedBy',
                        'operator',
                        'castingResultLine.castingResult',
                        'castingResultLine.productionPlan',
                    ]);
                },
            ])
            ->whereHas('stageExecution', function (Builder $eq) use ($dateFrom, $dateTo, $selectedStage, $scope, $search) {
                // Strictly CONFIRMED executions
                $eq->where('status', SandCastingStageExecution::STATUS_CONFIRMED);

                // Date logic aligned with Zone 3 effective date hierarchy
                $eq->whereEffectiveDefectDateBetween($dateFrom, $dateTo);

                // Stage filter
                if ($selectedStage !== 'all') {
                    $canonicalStage = SandCastingStageAuthorizationService::normalizeStage($selectedStage) ?? $selectedStage;
                    $eq->where('stage', $canonicalStage);
                }

                // Product Scope filter
                if ($scope) {
                    $eq->whereHas('castingResultLine.productionPlan', function (Builder $pq) use ($scope) {
                        $pq->where('product_scope', $scope);
                    });
                }

                // Free-text search filter across KTR, Heat, Code, Product Name, PO, Customer
                if ($search !== '') {
                    $eq->where(function (Builder $sq) use ($search) {
                        $sq->whereHas('castingResultLine', function (Builder $lq) use ($search) {
                            $lq->where('traveler_number', 'like', "%{$search}%")
                                ->orWhereHas('castingResult', function (Builder $rq) use ($search) {
                                    $rq->where('heat_number', 'like', "%{$search}%");
                                })
                                ->orWhereHas('productionPlan', function (Builder $pq) use ($search) {
                                    $pq->where('code', 'like', "%{$search}%")
                                        ->orWhere('item_name', 'like', "%{$search}%")
                                        ->orWhere('po_number', 'like', "%{$search}%")
                                        ->orWhere('customer', 'like', "%{$search}%");
                                });
                        });
                    });
                }
            });

        // Filter by specific defect type ID if selected
        if ($defectTypeId) {
            $query->where('defect_type_id', $defectTypeId);
        }

        // Order deterministically by verification date desc, execution id desc, defect row id desc
        $query->orderByDesc('id');

        // 2. Fetch full matching collection for aggregation and Pareto summary
        $allBreakdowns = $query->get();

        // 3. Map into structured view records
        $allRecords = $allBreakdowns->map(function (SandCastingStageExecutionDefect $def, int $idx) {
            $exec = $def->stageExecution;
            $line = $exec?->castingResultLine;
            $plan = $line?->productionPlan;
            $casting = $line?->castingResult;
            $unitWeight = $this->resolveEffectiveUnitWeightKg($line);
            $hasWeight = ($unitWeight !== null && $unitWeight > 0);
            $qty = (int) $def->qty;
            $estKg = $hasWeight ? round($qty * $unitWeight, 2) : null;

            // Effective defect date following hierarchy: inspection_date -> physical_done_at -> cast_date -> defect_entered_at -> qc_verified_at
            $effectiveDateStr = $exec?->resolveEffectiveDefectDate();
            $effectiveDate = $effectiveDateStr ? Carbon::parse($effectiveDateStr) : null;

            $stageKey = (string) ($exec?->stage ?? 'unknown');
            $stageLabel = self::STAGE_LABELS[$stageKey] ?? strtoupper(str_replace('_', ' ', $stageKey));

            return [
                'id' => $def->id,
                'execution_id' => $exec?->id,
                'date' => $effectiveDate ? $effectiveDate->format('d/m/Y') : '—',
                'date_raw' => $effectiveDate ? $effectiveDate->toDateString() : null,
                'verified_at_formatted' => $exec?->qc_verified_at ? Carbon::parse($exec->qc_verified_at)->format('d/m/Y H:i') : ($effectiveDate ? $effectiveDate->format('d/m/Y') : '—'),
                'traveler_number' => $line?->traveler_number ?? '—',
                'heat_number' => $casting?->heat_number ?? '—',
                'production_code' => $plan?->code ?? '—',
                'po_number' => $plan?->po_number ?? '—',
                'item_name' => $plan?->item_name ?? '—',
                'customer' => $plan?->customer ?? '—',
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
                'operator' => $exec?->operator?->name ?? '—',
                'notes' => $def->notes ?? $exec?->notes ?? '—',
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

        // Count auxiliary zero-defect executions within the same filter window
        $zeroDefectQuery = SandCastingStageExecution::query()
            ->where('status', SandCastingStageExecution::STATUS_CONFIRMED)
            ->where('defect_qty', 0)
            ->whereEffectiveDefectDateBetween($dateFrom, $dateTo);

        if ($selectedStage !== 'all') {
            $canonicalStage = SandCastingStageAuthorizationService::normalizeStage($selectedStage) ?? $selectedStage;
            $zeroDefectQuery->where('stage', $canonicalStage);
        }
        if ($scope) {
            $zeroDefectQuery->whereHas('castingResultLine.productionPlan', function (Builder $pq) use ($scope) {
                $pq->where('product_scope', $scope);
            });
        }
        $zeroDefectKtrCount = $zeroDefectQuery->count();

        // 5. Pareto / Summary Table (Grouped by defect_type_id and stage)
        $paretoGroups = $allRecords->groupBy(function ($r) {
            return $r['defect_type_id'].'_'.$r['stage'];
        });

        $paretoRows = [];
        foreach ($paretoGroups as $key => $items) {
            $first = $items->first();
            $pcs = (int) $items->sum('qty');
            $validItems = $items->where('has_weight', true);
            $estKg = (float) $validItems->sum('estimated_kg');
            $validPcs = (int) $validItems->sum('qty');
            $unavailPcs = $pcs - $validPcs;
            $ktrCount = $items->pluck('traveler_number')->filter(fn ($t) => $t !== '—')->unique()->count();

            // Contribution % relative to total defect PCS across current report filter
            $contribPct = ($totalDefectPcs > 0) ? round(($pcs / $totalDefectPcs) * 100, 1) : 0.0;

            $paretoRows[] = [
                'defect_type_id' => $first['defect_type_id'],
                'defect_name' => $first['defect_name'],
                'stage' => $first['stage'],
                'stage_label' => $first['stage_label'],
                'qty_pcs' => $pcs,
                'contribution_pct' => $contribPct,
                'estimated_kg' => round($estKg, 2),
                'valid_weight_pcs' => $validPcs,
                'unavailable_weight_pcs' => $unavailPcs,
                'has_unavailable_weight' => ($unavailPcs > 0),
                'ktr_count' => $ktrCount,
            ];
        }

        // Sort Pareto table by defect PCS descending, then estimated KG descending
        usort($paretoRows, function ($a, $b) {
            if ($b['qty_pcs'] !== $a['qty_pcs']) {
                return $b['qty_pcs'] <=> $a['qty_pcs'];
            }

            return $b['estimated_kg'] <=> $a['estimated_kg'];
        });

        // Top defect type across all records
        $topDefectType = ! empty($paretoRows) ? $paretoRows[0]['defect_name'] : '—';

        // 6. Pagination of detail records for web presentation
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

        // Available defect choices for the dropdown selector (filtered by selected stage)
        $availableDefectTypes = $this->getDefectTypesForStage($selectedStage);

        return [
            'records' => $detailPaginator,
            'all_records' => $allRecords,
            'pareto_summary' => $paretoRows,
            'summary' => [
                'total_defect_pcs' => $totalDefectPcs,
                'total_estimated_kg' => round($totalEstimatedKg, 2),
                'affected_ktr_count' => $affectedKtrCount,
                'top_defect_type' => $topDefectType,
                'valid_weight_pcs' => $validWeightPcs,
                'unavailable_weight_pcs' => $unavailableWeightPcs,
                'weight_coverage_pct' => $weightCoveragePct,
                'is_weight_fully_available' => ($unavailableWeightPcs === 0),
                'zero_defect_ktr_count' => $zeroDefectKtrCount,
                'total_records_count' => $allRecords->count(),
            ],
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'stage' => $selectedStage,
                'defect_type_id' => $defectTypeId,
                'search' => $search,
            ],
            'stages' => self::STAGES,
            'stage_labels' => self::STAGE_LABELS,
            'available_defect_types' => $availableDefectTypes,
        ];
    }
}
