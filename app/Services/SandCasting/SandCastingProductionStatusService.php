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

        return match ($line->current_stage) {
            'netto' => $this->resolveNettoUsable($line, $execs),
            'bubut_od' => $this->resolveOdUsable($line, $execs),
            'bubut_cnc' => $this->resolveCncUsable($line, $execs),
            'bor' => $this->resolveBorUsable($line, $execs),
            'qc' => $this->resolveQcUsable($line, $execs),
            default => 0,
        };
    }

    /**
     * Resolve usable pieces at Netto stage.
     */
    protected function resolveNettoUsable(SandCastingCastingResultLine $line, Collection $execs): int
    {
        $nettoExec = $execs->firstWhere('checkpoint_code', 'NETTO_CUT');
        if ($nettoExec) {
            return (int) $nettoExec->good_qty;
        }

        return (int) $line->qty_good;
    }

    /**
     * Resolve usable pieces at Bubut OD stage.
     */
    protected function resolveOdUsable(SandCastingCastingResultLine $line, Collection $execs): int
    {
        $odExec = $execs->firstWhere('checkpoint_code', 'OD_TURNING');
        if ($odExec) {
            return (int) $odExec->good_qty;
        }

        $nettoExec = $execs->firstWhere('checkpoint_code', 'NETTO_CUT');
        if ($nettoExec) {
            return (int) $nettoExec->good_qty;
        }

        return (int) $line->qty_good;
    }

    /**
     * Resolve usable pieces at Bubut CNC stage.
     * Prevents double counting by taking the latest executed checkpoint in CNC,
     * or fallback to previous OD output.
     */
    protected function resolveCncUsable(SandCastingCastingResultLine $line, Collection $execs): int
    {
        // Check CNC sub-checkpoints in reverse chronological order
        foreach (['QC_PRE_BOR', 'QC_POST_CNC', 'CNC_MACHINING'] as $chkCode) {
            $chkExec = $execs->firstWhere('checkpoint_code', $chkCode);
            if ($chkExec) {
                return (int) $chkExec->good_qty;
            }
        }

        $odExec = $execs->firstWhere('checkpoint_code', 'OD_TURNING');
        if ($odExec) {
            return (int) $odExec->good_qty;
        }

        $nettoExec = $execs->firstWhere('checkpoint_code', 'NETTO_CUT');
        if ($nettoExec) {
            return (int) $nettoExec->good_qty;
        }

        return (int) $line->qty_good;
    }

    /**
     * Resolve usable pieces at Bor stage.
     */
    protected function resolveBorUsable(SandCastingCastingResultLine $line, Collection $execs): int
    {
        $borExec = $execs->firstWhere('checkpoint_code', 'BOR_DRILLING');
        if ($borExec) {
            return (int) $borExec->good_qty;
        }

        $preBorExec = $execs->firstWhere('checkpoint_code', 'QC_PRE_BOR');
        if ($preBorExec) {
            return (int) $preBorExec->good_qty;
        }

        return $this->resolveCncUsable($line, $execs);
    }

    /**
     * Resolve usable pieces at QC stage.
     */
    protected function resolveQcUsable(SandCastingCastingResultLine $line, Collection $execs): int
    {
        $qcExec = $execs->firstWhere('checkpoint_code', 'QC_FINAL_INSPECTION');
        if ($qcExec) {
            return (int) $qcExec->good_qty;
        }

        $borExec = $execs->firstWhere('checkpoint_code', 'BOR_DRILLING');
        if ($borExec) {
            return (int) $borExec->good_qty;
        }

        return $this->resolveBorUsable($line, $execs);
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
