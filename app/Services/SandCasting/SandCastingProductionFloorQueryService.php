<?php

namespace App\Services\SandCasting;

use App\Models\SandCastingCastingResultLine;
use Illuminate\Support\Collection;

class SandCastingProductionFloorQueryService
{
    public const STATUS_READY = 'READY';

    public const STATUS_HALTED = 'HALTED';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_NO_STAGE = 'NO_STAGE';

    /**
     * Find and resolve operational state for a single KTR traveler.
     */
    public function findByTraveler(string $travelerNumber): ?array
    {
        $travelerNumber = strtoupper(trim($travelerNumber));
        if ($travelerNumber === '') {
            return null;
        }

        $line = SandCastingCastingResultLine::with([
            'castingResult',
            'productionPlan',
            'castingOrderLine.castingOrder',
            'urgentSetBy',
            'stageExecutions' => fn ($query) => $query->orderBy('executed_at', 'asc')->orderBy('id', 'asc')->with('operator'),
        ])->where('traveler_number', $travelerNumber)->first();

        if (! $line) {
            return null;
        }

        return $this->formatTravelerData($line);
    }

    /**
     * Find all KTR travelers associated with a specific Heat Number (manual fallback lookup).
     *
     * @return Collection<int, array>
     */
    public function findByHeat(string $heatNumber): Collection
    {
        $heatNumber = strtoupper(trim($heatNumber));
        if ($heatNumber === '') {
            return collect();
        }

        $lines = SandCastingCastingResultLine::whereHas('castingResult', function ($query) use ($heatNumber) {
            $query->where('heat_number', $heatNumber);
        })->with([
            'castingResult',
            'productionPlan',
            'castingOrderLine.castingOrder',
            'urgentSetBy',
            'stageExecutions' => fn ($query) => $query->orderBy('executed_at', 'asc')->orderBy('id', 'asc')->with('operator'),
        ])
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return $lines->map(fn ($line) => $this->formatTravelerData($line));
    }

    /**
     * Get chronological stage execution history for a traveler.
     *
     * @return Collection<int, array>
     */
    public function getStageHistory(string $travelerNumber): Collection
    {
        $travelerNumber = strtoupper(trim($travelerNumber));
        if ($travelerNumber === '') {
            return collect();
        }

        $line = SandCastingCastingResultLine::where('traveler_number', $travelerNumber)->first();
        if (! $line) {
            return collect();
        }

        return $line->stageExecutions()
            ->with('operator')
            ->orderBy('executed_at', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn ($exec) => $this->formatStageExecution($exec));
    }

    /**
     * Format a SandCastingCastingResultLine model into a standardized read array.
     */
    protected function formatTravelerData(SandCastingCastingResultLine $line): array
    {
        $currentInputQty = $this->resolveCurrentInputQty($line);
        $operationalStatus = $this->resolveOperationalStatus($line, $currentInputQty);
        $nextStage = $this->resolveNextStage($line->current_stage);

        $history = $line->stageExecutions->map(fn ($exec) => $this->formatStageExecution($exec))->values()->all();

        return [
            'id' => $line->id,
            'traveler_number' => $line->traveler_number,
            'heat_number' => $line->castingResult?->heat_number,
            'cast_date' => $line->castingResult?->cast_date?->format('Y-m-d'),
            'furnace' => $line->castingResult?->furnace,
            'shift' => $line->castingResult?->shift,
            'operator_name' => $line->castingResult?->operator_name,
            'production_code' => $line->productionPlan?->code ?? $line->castingOrderLine?->code,
            'item_code' => $line->productionPlan?->item_code,
            'item_name' => $line->productionPlan?->item_name ?? $line->castingOrderLine?->item_name,
            'customer' => $line->productionPlan?->customer ?? $line->castingOrderLine?->customer,
            'line_number' => $line->productionPlan?->line_number,
            'qty_cor' => (int) $line->qty_good,
            'qty_reject_cor' => (int) $line->qty_reject,
            'unit_weight_kg' => $line->unit_weight_kg,
            'total_weight_kg' => $line->total_weight_kg,
            'current_stage' => $line->current_stage,
            'current_input_qty' => $currentInputQty,
            'operational_status' => $operationalStatus,
            'next_stage' => $nextStage,
            'is_urgent' => (bool) $line->is_urgent,
            'urgent_set_at' => $line->urgent_set_at,
            'urgent_set_by' => $line->urgent_set_by,
            'urgent_set_by_name' => $line->urgentSetBy?->name,
            'stage_history' => $history,
        ];
    }

    /**
     * Resolve the current input quantity available for the traveler's current stage.
     */
    protected function resolveCurrentInputQty(SandCastingCastingResultLine $line): ?int
    {
        if ($line->current_stage === null) {
            return null;
        }

        if ($line->current_stage === 'completed') {
            return 0;
        }

        // If current stage has already been executed (e.g. halted with good_qty = 0)
        $currentStageExec = $line->stageExecutions->firstWhere('stage', $line->current_stage);
        if ($currentStageExec) {
            return (int) $currentStageExec->good_qty;
        }

        if ($line->current_stage === 'netto') {
            return (int) $line->qty_good;
        }

        if (isset(SandCastingStageExecutionService::PREVIOUS_STAGE[$line->current_stage])) {
            $prevStage = SandCastingStageExecutionService::PREVIOUS_STAGE[$line->current_stage];
            $prevExec = $line->stageExecutions->firstWhere('stage', $prevStage);

            return $prevExec ? (int) $prevExec->good_qty : 0;
        }

        return 0;
    }

    /**
     * Resolve the operational status for the traveler.
     */
    protected function resolveOperationalStatus(SandCastingCastingResultLine $line, ?int $currentInputQty): string
    {
        if ($line->current_stage === null) {
            return self::STATUS_NO_STAGE;
        }

        if ($line->current_stage === 'completed') {
            return self::STATUS_COMPLETED;
        }

        if ($currentInputQty !== null && $currentInputQty > 0) {
            return self::STATUS_READY;
        }

        return self::STATUS_HALTED;
    }

    /**
     * Resolve the next stage based on the state machine flow.
     */
    protected function resolveNextStage(?string $currentStage): ?string
    {
        if ($currentStage === null || $currentStage === 'completed') {
            return null;
        }

        return SandCastingStageExecutionService::STAGE_FLOW[$currentStage] ?? null;
    }

    /**
     * Format a single stage execution record.
     */
    protected function formatStageExecution($exec): array
    {
        return [
            'id' => $exec->id,
            'stage' => $exec->stage,
            'input_qty' => (int) $exec->input_qty,
            'defect_qty' => (int) $exec->defect_qty,
            'good_qty' => (int) $exec->good_qty,
            'operator_id' => $exec->operator_id,
            'operator_name' => $exec->operator?->name,
            'executed_at' => $exec->executed_at,
            'notes' => $exec->notes,
        ];
    }
}
