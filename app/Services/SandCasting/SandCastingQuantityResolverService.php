<?php

namespace App\Services\SandCasting;

use App\Models\SandCastingCastingResultLine;
use InvalidArgumentException;

class SandCastingQuantityResolverService
{
    /**
     * Canonical Sand Casting pipeline stages from root casting to warehouse.
     */
    public const PIPELINE_STAGES = [
        'cor',
        'netto',
        'bubut_od',
        'bubut_cnc',
        'bor',
        'qc',
        'gudang_jadi',
    ];

    /**
     * Canonical stage order mapping (1-based index).
     */
    public const STAGE_ORDER = [
        'cor' => 1,
        'netto' => 2,
        'bubut_od' => 3,
        'bubut_cnc' => 4,
        'bor' => 5,
        'qc' => 6,
        'gudang_jadi' => 7,
    ];

    /**
     * Resolve the operational effective input quantity for a KTR line entering target stage.
     * Formula: max(0, COR.qty_good - SUM(upstream stage defects before targetStage))
     *
     * Note: Defect on target stage does NOT reduce the input to target stage.
     */
    public function resolveEffectiveInputQty(SandCastingCastingResultLine $line, string $targetStage): int
    {
        return $this->calculateEffectiveQty($line, $targetStage, includeCurrentStageDefect: false);
    }

    /**
     * Resolve the operational effective good/output quantity for a KTR line after completing target stage.
     * Formula: max(0, EffectiveInputQty - TargetStageDefect) = max(0, COR.qty_good - SUM(all defects <= targetStage))
     */
    public function resolveEffectiveGoodQty(SandCastingCastingResultLine $line, string $targetStage): int
    {
        return $this->calculateEffectiveQty($line, $targetStage, includeCurrentStageDefect: true);
    }

    /**
     * Core calculation engine that computes effective quantity from root good minus defects.
     * Strictly READ-ONLY. Guaranteed free from double-counting and pooling across KTRs.
     */
    protected function calculateEffectiveQty(
        SandCastingCastingResultLine $line,
        string $targetStage,
        bool $includeCurrentStageDefect
    ): int {
        $normalizedTarget = self::normalizeStageName($targetStage);
        if ($normalizedTarget === null) {
            throw new InvalidArgumentException("Tahapan target '{$targetStage}' tidak valid dalam alur Sand Casting.");
        }

        $rootGood = (int) ($line->qty_good ?? 0);
        if ($rootGood <= 0) {
            return 0;
        }

        $targetOrder = self::STAGE_ORDER[$normalizedTarget];

        // Root COR stage has no upstream defects
        if ($normalizedTarget === 'cor') {
            return $rootGood;
        }

        // Get executions belonging exclusively to this specific line (eager loaded if available)
        $executions = $line->relationLoaded('stageExecutions')
            ? $line->stageExecutions
            : $line->stageExecutions()->get();

        $totalDeductedDefect = 0;

        foreach ($executions as $exec) {
            $execStage = self::normalizeStageName($exec->stage);
            if ($execStage === null || $execStage === 'cor') {
                continue;
            }

            $execOrder = self::STAGE_ORDER[$execStage] ?? 99;

            $shouldDeduct = $includeCurrentStageDefect
                ? ($execOrder <= $targetOrder)
                : ($execOrder < $targetOrder);

            if ($shouldDeduct) {
                $totalDeductedDefect += (int) ($exec->defect_qty ?? 0);
            }
        }

        return max(0, $rootGood - $totalDeductedDefect);
    }

    /**
     * Normalize stage name to canonical stage string.
     */
    public static function normalizeStageName(?string $stage): ?string
    {
        if ($stage === null) {
            return null;
        }

        $cleaned = strtolower(trim($stage));
        $cleaned = str_replace('-', '_', $cleaned);

        if ($cleaned === 'cor' || $cleaned === 'hasil_cor' || $cleaned === 'hasil-cor') {
            return 'cor';
        }

        return SandCastingStageAuthorizationService::normalizeStage($cleaned);
    }
}
