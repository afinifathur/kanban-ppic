<?php

namespace App\Services\Fitting;

use App\Models\FittingItem;
use InvalidArgumentException;

/**
 * Single Source of Truth for dynamic Fitting Production effective quantity propagation.
 *
 * Strictly READ-ONLY:
 * - Root initial quantity is `fitting_items.qty_transferred`.
 * - Upstream defects reduce the effective input of downstream stages without mutating
 *   historical `fitting_stage_executions.input_qty` scan snapshots.
 * - Defects are never subtracted twice; each stage deducts only defects strictly before
 *   (for input) or up to and including (for output/good) the target stage.
 */
class FittingQuantityResolverService
{
    public const STAGE_TRANSFER = 'transfer';

    /**
     * Canonical Fitting pipeline stage ordering (1-based).
     */
    public const STAGE_ORDER = [
        self::STAGE_TRANSFER => 1,
        FittingItem::STAGE_NETTO_PF => 2,
        FittingItem::STAGE_KIMIA => 3,
        FittingItem::STAGE_MARKING => 4,
        FittingItem::STAGE_BUBUT_CNC_PF => 5,
        FittingItem::STAGE_QC_PF => 6,
        FittingItem::STAGE_GUDANG_JADI_PF => 7,
    ];

    /**
     * Resolve effective input quantity entering $targetStage for a single FittingItem (PFKTR).
     * Formula: max(0, qty_transferred - SUM(defect_qty WHERE stage_order < target_order))
     */
    public function resolveEffectiveInputQty(FittingItem $item, string $targetStage): int
    {
        return $this->calculateEffectiveQty($item, $targetStage, includeCurrentStageDefect: false);
    }

    /**
     * Resolve effective good/output quantity after completing $targetStage for a single FittingItem (PFKTR).
     * Formula: max(0, qty_transferred - SUM(defect_qty WHERE stage_order <= target_order))
     */
    public function resolveEffectiveGoodQty(FittingItem $item, string $targetStage): int
    {
        return $this->calculateEffectiveQty($item, $targetStage, includeCurrentStageDefect: true);
    }

    /**
     * Resolve total defect quantity recorded across all stages EXCEPT $excludedStage.
     * Used during defect boundary validation so total defects across all stages never exceed qty_transferred.
     */
    public function resolveOtherStagesDefectTotal(FittingItem $item, string $excludedStage): int
    {
        $normalizedExcluded = self::normalizeStageName($excludedStage);
        if ($normalizedExcluded === null) {
            throw new InvalidArgumentException("Tahap '{$excludedStage}' tidak valid dalam alur Fitting Production.");
        }

        $executions = $item->relationLoaded('stageExecutions')
            ? $item->stageExecutions
            : $item->stageExecutions()->get();

        $total = 0;
        foreach ($executions as $exec) {
            $execStage = self::normalizeStageName($exec->stage);
            if ($execStage === null || $execStage === self::STAGE_TRANSFER || $execStage === $normalizedExcluded) {
                continue;
            }
            $total += max(0, (int) ($exec->defect_qty ?? 0));
        }

        return $total;
    }

    /**
     * Resolve maximum permissible total defect quantity for $targetStage without violating either:
     * 1. Effective input entering $targetStage, OR
     * 2. Overall mass balance across all 6 stages (SUM(all stage defects) <= qty_transferred).
     */
    public function resolveMaxAllowedDefectQty(FittingItem $item, string $targetStage): int
    {
        $effectiveInput = $this->resolveEffectiveInputQty($item, $targetStage);
        $otherStagesDefects = $this->resolveOtherStagesDefectTotal($item, $targetStage);
        $globalRemaining = max(0, (int) $item->qty_transferred - $otherStagesDefects);

        return min($effectiveInput, $globalRemaining);
    }

    /**
     * Build a complete per-stage quantity traceability breakdown for a single PFKTR.
     *
     * @return array<string, array{stage: string, effective_input_qty: int, defect_qty: int, effective_good_qty: int, executed: bool, status: string|null}>
     */
    public function buildStageQuantityTrace(FittingItem $item): array
    {
        $executions = $item->relationLoaded('stageExecutions')
            ? $item->stageExecutions
            : $item->stageExecutions()->get();

        $execByStage = $executions->keyBy(fn ($e) => self::normalizeStageName($e->stage));
        $trace = [];

        foreach (FittingItem::STAGES as $stage) {
            $exec = $execByStage->get($stage);
            $effectiveInput = $this->resolveEffectiveInputQty($item, $stage);
            $defectQty = $exec ? (int) $exec->defect_qty : 0;
            $effectiveGood = $this->resolveEffectiveGoodQty($item, $stage);

            $trace[$stage] = [
                'stage' => $stage,
                'effective_input_qty' => $effectiveInput,
                'defect_qty' => $defectQty,
                'effective_good_qty' => $effectiveGood,
                'executed' => $exec !== null,
                'status' => $exec?->status,
            ];
        }

        return $trace;
    }

    protected function calculateEffectiveQty(
        FittingItem $item,
        string $targetStage,
        bool $includeCurrentStageDefect
    ): int {
        $normalizedTarget = self::normalizeStageName($targetStage);
        if ($normalizedTarget === null) {
            throw new InvalidArgumentException("Tahap target '{$targetStage}' tidak valid dalam alur Fitting Production.");
        }

        $rootQty = max(0, (int) ($item->qty_transferred ?? 0));
        if ($rootQty === 0) {
            return 0;
        }

        if ($normalizedTarget === self::STAGE_TRANSFER) {
            return $rootQty;
        }

        $targetOrder = self::STAGE_ORDER[$normalizedTarget];

        $executions = $item->relationLoaded('stageExecutions')
            ? $item->stageExecutions
            : $item->stageExecutions()->get();

        $deductedDefects = 0;

        foreach ($executions as $exec) {
            $execStage = self::normalizeStageName($exec->stage);
            if ($execStage === null || $execStage === self::STAGE_TRANSFER) {
                continue;
            }

            $execOrder = self::STAGE_ORDER[$execStage] ?? 99;
            $shouldDeduct = $includeCurrentStageDefect
                ? ($execOrder <= $targetOrder)
                : ($execOrder < $targetOrder);

            if ($shouldDeduct) {
                $deductedDefects += max(0, (int) ($exec->defect_qty ?? 0));
            }
        }

        return max(0, $rootQty - $deductedDefects);
    }

    public static function normalizeStageName(?string $stage): ?string
    {
        if ($stage === null) {
            return null;
        }

        $cleaned = strtolower(trim($stage));
        if ($cleaned === self::STAGE_TRANSFER) {
            return self::STAGE_TRANSFER;
        }

        return FittingStageAuthorizationService::normalizeStage($cleaned);
    }
}
