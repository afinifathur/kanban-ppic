<?php

namespace App\Services\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SandCastingProductionStatusService
{
    /**
     * Canonical Sand Casting operational stages.
     */
    public const STAGES = [
        'cor' => 'Hasil Cor',
        'netto' => 'Netto',
        'bubut_od' => 'Bubut OD',
        'bubut_cnc' => 'Bubut CNC',
        'bor' => 'Bor',
        'qc' => 'QC',
        'gudang_jadi' => 'Gudang Jadi',
    ];

    public function __construct(
        protected ?SandCastingQuantityResolverService $quantityResolver = null
    ) {
        $this->quantityResolver = $quantityResolver ?? new SandCastingQuantityResolverService;
    }

    /**
     * Get the aggregated production status rows for Sand Casting grouped by ProductionPlan (Production Code).
     *
     * @param  array{search?: string, filter?: string, status?: string, codes?: array, customers?: array, po_numbers?: array}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function getAggregatedRows(array $filters = [], ?User $user = null): array
    {
        $currentUser = $user ?? auth()->user();
        $scope = $currentUser?->product_scope;

        $search = trim((string) ($filters['search'] ?? ''));
        $statusFilter = strtolower(trim((string) ($filters['status'] ?? $filters['filter'] ?? 'all')));

        $codes = $this->parseFilterArray($filters['codes'] ?? $filters['code'] ?? null);
        $customers = $this->parseFilterArray($filters['customers'] ?? $filters['customer'] ?? null);
        $poNumbers = $this->parseFilterArray($filters['po_numbers'] ?? $filters['po_number'] ?? null);

        $planQuery = ProductionPlan::query()
            ->where(function (Builder $q) {
                $q->where('production_domain', ProductionPlan::DOMAIN_SAND_CASTING)
                    ->orWhereHas('sandCastingResultLines')
                    ->orWhereHas('castingOrderLines');
            })
            ->with([
                'sandCastingResultLines' => function ($q) {
                    $q->with([
                        'castingResult',
                        'stageExecutions' => function ($sq) {
                            $sq->orderBy('executed_at', 'asc')->orderBy('id', 'asc');
                        },
                    ]);
                },
                'castingOrderLines.castingOrder',
            ]);

        // Apply RBAC Product Scope
        if ($currentUser && $currentUser->hasRole('ppic') && $scope) {
            $planQuery->where('product_scope', $scope);
        }

        // Apply Search Filter
        if ($search !== '') {
            $planQuery->where(function (Builder $q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('po_number', 'like', "%{$search}%")
                    ->orWhere('customer', 'like', "%{$search}%")
                    ->orWhere('item_name', 'like', "%{$search}%");
            });
        }

        // Apply Category Array Filters
        if (! empty($codes)) {
            $planQuery->whereIn('code', $codes);
        }
        if (! empty($customers)) {
            $planQuery->whereIn('customer', $customers);
        }
        if (! empty($poNumbers)) {
            $planQuery->whereIn('po_number', $poNumbers);
        }

        $plans = $planQuery->orderBy('code', 'asc')->get();

        $rows = [];
        $rowNumber = 1;

        foreach ($plans as $plan) {
            $row = $this->calculatePlanStatusRow($plan, $rowNumber);

            // Filter by completion status if requested
            if ($statusFilter === 'active' && $row['status'] !== 'ACTIVE') {
                continue;
            }
            if ($statusFilter === 'completed' && $row['status'] !== 'COMPLETED') {
                continue;
            }

            $rows[] = $row;
            $rowNumber++;
        }

        return $rows;
    }

    /**
     * Get summary counts (active, completed, total) for the given filter context.
     *
     * @param  array{search?: string, codes?: array, customers?: array, po_numbers?: array}  $filters
     * @return array{active_count: int, completed_count: int, total_count: int}
     */
    public function getSummaryCounts(array $filters = [], ?User $user = null): array
    {
        $allRows = $this->getAggregatedRows(array_merge($filters, ['status' => 'all']), $user);

        $activeCount = 0;
        $completedCount = 0;

        foreach ($allRows as $row) {
            if ($row['status'] === 'ACTIVE') {
                $activeCount++;
            } elseif ($row['status'] === 'COMPLETED') {
                $completedCount++;
            }
        }

        return [
            'active_count' => $activeCount,
            'completed_count' => $completedCount,
            'total_count' => count($allRows),
        ];
    }

    /**
     * Calculate single production status row for a given ProductionPlan.
     *
     * @return array<string, mixed>
     */
    public function calculatePlanStatusRow(ProductionPlan $plan, int $rowNumber = 1): array
    {
        /** @var Collection<int, SandCastingCastingResultLine> $lines */
        $lines = $plan->sandCastingResultLines ?? collect();

        // 1. PO and Planned Quantities
        $poTarget = $plan->po_quantity !== null && (int) $plan->po_quantity > 0
            ? (int) $plan->po_quantity
            : (int) $plan->qty_planned;

        $planQty = (int) $plan->qty_planned;

        // 2. Aggregate Hasil Cor (Casting Results)
        $castGoodTotal = (int) $lines->sum('qty_good');
        $rCor = (int) $lines->sum('qty_reject');

        // 3. Aggregate Stage Executions & Defects across all KTR lines
        $allExecutions = $lines->flatMap(function (SandCastingCastingResultLine $line) {
            return $line->stageExecutions ?? collect();
        });

        // Non-cumulative defects per department
        $rNetto = (int) $allExecutions->where('checkpoint_code', 'NETTO_CUT')->sum('defect_qty');
        $rOd = (int) $allExecutions->where('checkpoint_code', 'OD_TURNING')->sum('defect_qty');
        $rCnc = (int) $allExecutions->whereIn('checkpoint_code', ['CNC_MACHINING', 'QC_POST_CNC', 'QC_PRE_BOR'])->sum('defect_qty');
        $rBor = (int) $allExecutions->where('checkpoint_code', 'BOR_DRILLING')->sum('defect_qty');
        $rQc = (int) $allExecutions->where('checkpoint_code', 'QC_FINAL_INSPECTION')->sum('defect_qty');

        $downstreamRejects = $rNetto + $rOd + $rCnc + $rBor + $rQc;
        $totalReject = $rCor + $downstreamRejects;

        // 4. Net Available Good Quantity & COR Indicator
        $netAvailableGood = max(0, $castGoodTotal - $downstreamRejects);
        $corIndicator = ($netAvailableGood < $poTarget) ? 'COR' : '';

        // 5. Stage WIP Quantities (Current Physical Position)
        $nettoWip = 0;
        $odWip = 0;
        $cncWip = 0;
        $borWip = 0;
        $qcWip = 0;

        foreach ($lines as $line) {
            $stage = $line->current_stage;
            if ($stage === null || $stage === 'completed') {
                continue;
            }

            $usableQty = $this->resolveKtrUsableQty($line);
            if ($usableQty <= 0) {
                continue;
            }

            match ($stage) {
                'netto' => $nettoWip += $usableQty,
                'bubut_od' => $odWip += $usableQty,
                'bubut_cnc' => $cncWip += $usableQty,
                'bor' => $borWip += $usableQty,
                'qc' => $qcWip += $usableQty,
                default => null,
            };
        }

        // 6. Gudang Jadi (GD) Quantity from physical GUDANG_RECEIVE checkpoint
        $gdExecutions = $allExecutions->where('checkpoint_code', 'GUDANG_RECEIVE');
        $gdQty = (int) $gdExecutions->sum('good_qty');

        // 7. Completion & Remaining PO
        $remainingPo = max(0, $poTarget - $gdQty);
        $status = ($gdQty >= $poTarget) ? 'COMPLETED' : 'ACTIVE';

        // 8. Traceability metadata (Heats & KTR counts)
        $uniqueHeats = $lines->pluck('castingResult.heat_number')->filter()->unique()->values()->all();

        return [
            'no' => $rowNumber,
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'customer' => $plan->customer ?? '-',
            'item_code' => $plan->item_code ?? '-',
            'item_name' => $plan->item_name ?? '-',
            'size' => $plan->size ?? '-',
            'aisi' => $plan->aisi ?? '-',
            'po_target' => $poTarget,
            'po_quantity' => $plan->po_quantity,
            'planned_qty' => $planQty,
            'cor_indicator' => $corIndicator,
            'cast_good_total' => $castGoodTotal,
            'net_available_good' => $netAvailableGood,
            'r_cor' => $rCor,
            'netto' => $nettoWip,
            'r_netto' => $rNetto,
            'bubut_od' => $odWip,
            'r_od' => $rOd,
            'bubut_cnc' => $cncWip,
            'r_cnc' => $rCnc,
            'bor' => $borWip,
            'r_bor' => $rBor,
            'qc' => $qcWip,
            'r_qc' => $rQc,
            'gd' => $gdQty,
            'remaining_po' => $remainingPo,
            'status' => $status,
            'total_reject' => $totalReject,
            'ktr_count' => $lines->count(),
            'heat_numbers' => $uniqueHeats,
            'heat_count' => count($uniqueHeats),
        ];
    }

    /**
     * Resolve the usable quantity for a single KTR traveler at its current physical stage.
     * Uses centralized SandCastingQuantityResolverService as single source of truth.
     */
    public function resolveKtrUsableQty(SandCastingCastingResultLine $line): int
    {
        if ($line->current_stage === null || $line->current_stage === 'completed') {
            return 0;
        }

        $execs = $line->relationLoaded('stageExecutions')
            ? $line->stageExecutions
            : $line->stageExecutions()->orderBy('executed_at', 'asc')->orderBy('id', 'asc')->get();

        // If any confirmed checkpoint has good_qty === 0, KTR is completely halted
        $hasHalted = $execs->contains(function (SandCastingStageExecution $e) {
            return $e->status === SandCastingStageExecution::STATUS_CONFIRMED && $e->good_qty === 0;
        });

        if ($hasHalted) {
            return 0;
        }

        return $this->quantityResolver->resolveEffectiveInputQty($line, $line->current_stage);
    }

    /**
     * Resolve usable pieces at Netto stage (kept for backward compatibility).
     */
    protected function resolveNettoUsable(SandCastingCastingResultLine $line, Collection $execs): int
    {
        return $this->quantityResolver->resolveEffectiveInputQty($line, 'netto');
    }

    /**
     * Resolve usable pieces at Bubut OD stage (kept for backward compatibility).
     */
    protected function resolveOdUsable(SandCastingCastingResultLine $line, Collection $execs): int
    {
        return $this->quantityResolver->resolveEffectiveInputQty($line, 'bubut_od');
    }

    /**
     * Resolve usable pieces at Bubut CNC stage (kept for backward compatibility).
     */
    protected function resolveCncUsable(SandCastingCastingResultLine $line, Collection $execs): int
    {
        return $this->quantityResolver->resolveEffectiveInputQty($line, 'bubut_cnc');
    }

    /**
     * Resolve usable pieces at Bor stage (kept for backward compatibility).
     */
    protected function resolveBorUsable(SandCastingCastingResultLine $line, Collection $execs): int
    {
        return $this->quantityResolver->resolveEffectiveInputQty($line, 'bor');
    }

    /**
     * Resolve usable pieces at QC stage (kept for backward compatibility).
     */
    protected function resolveQcUsable(SandCastingCastingResultLine $line, Collection $execs): int
    {
        return $this->quantityResolver->resolveEffectiveInputQty($line, 'qc');
    }

    /**
     * Get detailed physical KTR barcode items and status for a specific Production Plan.
     *
     * @return array<string, mixed>|null
     */
    public function getProductionCodeDetails(int|string $planIdOrCode, ?User $user = null): ?array
    {
        $currentUser = $user ?? auth()->user();
        $scope = $currentUser?->product_scope;

        $planQuery = ProductionPlan::query()
            ->where(function (Builder $q) {
                $q->where('production_domain', ProductionPlan::DOMAIN_SAND_CASTING)
                    ->orWhereHas('sandCastingResultLines')
                    ->orWhereHas('castingOrderLines');
            })
            ->where(function (Builder $q) use ($planIdOrCode) {
                if (is_numeric($planIdOrCode)) {
                    $q->where('id', (int) $planIdOrCode)->orWhere('code', (string) $planIdOrCode);
                } else {
                    $q->where('code', (string) $planIdOrCode);
                }
            })
            ->with([
                'sandCastingResultLines' => function ($q) {
                    $q->with([
                        'castingResult',
                        'stageExecutions' => function ($sq) {
                            $sq->orderBy('executed_at', 'asc')->orderBy('id', 'asc');
                        },
                    ]);
                },
            ]);

        // Apply RBAC Product Scope
        if ($currentUser && $currentUser->hasRole('ppic') && $scope) {
            $planQuery->where('product_scope', $scope);
        }

        /** @var ProductionPlan|null $plan */
        $plan = $planQuery->first();

        if (! $plan) {
            return null;
        }

        $lines = $plan->sandCastingResultLines ?? collect();

        $stageLabels = [
            'netto' => 'NETTO',
            'bubut_od' => 'OD',
            'bubut_cnc' => 'CNC',
            'bor' => 'BOR',
            'qc' => 'QC',
            'completed' => 'GD',
            'gudang_jadi' => 'GD',
        ];

        $items = [];
        $totalPhysicalQty = 0;

        foreach ($lines as $line) {
            $stage = $line->current_stage;
            $stageLabel = $stage ? ($stageLabels[$stage] ?? strtoupper(str_replace('_', ' ', $stage))) : 'COR';

            // Resolve physical quantity currently at this position
            if ($stage === 'completed' || $stage === 'gudang_jadi') {
                $gdExec = $line->stageExecutions->firstWhere('checkpoint_code', 'GUDANG_RECEIVE');
                $qty = $gdExec ? (int) $gdExec->good_qty : $this->resolveQcUsable($line, $line->stageExecutions);
            } elseif ($stage === null) {
                $qty = (int) $line->qty_good;
            } else {
                $qty = $this->resolveKtrUsableQty($line);
            }

            $totalPhysicalQty += $qty;

            // Total defect recorded on this line
            $defectQty = (int) $line->qty_reject + (int) $line->stageExecutions->sum('defect_qty');

            // Latest activity timestamp
            $lastExec = $line->stageExecutions->last();
            $lastActivity = $lastExec?->executed_at ?? $lastExec?->physical_done_at ?? $line->updated_at ?? $line->created_at;

            $items[] = [
                'id' => $line->id,
                'traveler_number' => $line->traveler_number,
                'heat_number' => $line->castingResult?->heat_number ?? '-',
                'cast_date' => $line->castingResult?->cast_date?->format('d/m/Y') ?? '-',
                'furnace' => $line->castingResult?->furnace ?? '-',
                'shift' => $line->castingResult?->shift ?? '-',
                'operator_name' => $line->castingResult?->operator_name ?? '-',
                'current_stage' => $stage ?? 'cor',
                'current_stage_label' => $stageLabel,
                'quantity' => $qty,
                'qty_cor_good' => (int) $line->qty_good,
                'defect_qty' => $defectQty,
                'last_activity_at' => $lastActivity ? $lastActivity->format('d/m/Y H:i') : '-',
            ];
        }

        // Sort items by Stage Priority, then Heat Number, then Traveler Number
        $stageOrder = [
            'NETTO' => 1,
            'OD' => 2,
            'CNC' => 3,
            'BOR' => 4,
            'QC' => 5,
            'GD' => 6,
            'COR' => 0,
        ];

        usort($items, function ($a, $b) use ($stageOrder) {
            $rankA = $stageOrder[$a['current_stage_label']] ?? 99;
            $rankB = $stageOrder[$b['current_stage_label']] ?? 99;

            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            $heatComp = strcmp((string) $a['heat_number'], (string) $b['heat_number']);
            if ($heatComp !== 0) {
                return $heatComp;
            }

            return strcmp((string) $a['traveler_number'], (string) $b['traveler_number']);
        });

        $rowSummary = $this->calculatePlanStatusRow($plan);

        return [
            'production_plan_id' => $plan->id,
            'production_code' => $plan->code,
            'customer' => $plan->customer ?? '-',
            'item_code' => $plan->item_code ?? '-',
            'item_name' => $plan->item_name ?? '-',
            'po_target' => $rowSummary['po_target'],
            'planned_qty' => $rowSummary['planned_qty'],
            'status' => $rowSummary['status'],
            'cor_indicator' => $rowSummary['cor_indicator'],
            'total_physical_qty' => $totalPhysicalQty,
            'ktr_count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * Parse raw filter input into a clean string array.
     *
     * @return array<int, string>
     */
    protected function parseFilterArray(mixed $input): array
    {
        if (empty($input)) {
            return [];
        }

        if (is_string($input)) {
            $decoded = json_decode($input, true);
            if (is_array($decoded)) {
                return array_values(array_filter(array_map('trim', $decoded), fn ($v) => $v !== ''));
            }

            if (str_contains($input, ',')) {
                return array_values(array_filter(array_map('trim', explode(',', $input)), fn ($v) => $v !== ''));
            }

            $trimmed = trim($input);

            return $trimmed !== '' ? [$trimmed] : [];
        }

        if (is_array($input)) {
            return array_values(array_filter(array_map('trim', $input), fn ($v) => $v !== ''));
        }

        return [];
    }
}
