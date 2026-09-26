<?php

namespace App\Services\SandCasting;

use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use Illuminate\Support\Collection;

class SandCastingProductionFloorQueryService
{
    public const STATUS_READY = 'READY';

    public const STATUS_WAITING_DEFECT = 'WAITING_DEFECT';

    public const STATUS_WAITING_QC = 'WAITING_QC';

    public const STATUS_CONFIRMED = 'CONFIRMED';

    public const STATUS_HALTED = 'HALTED';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_NO_STAGE = 'NO_STAGE';

    public function __construct(
        protected ?SandCastingStageExecutionService $executionService = null
    ) {
        $this->executionService = $executionService ?? new SandCastingStageExecutionService;
    }

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
            'stageExecutions' => fn ($query) => $query->orderBy('executed_at', 'asc')->orderBy('id', 'asc')->with(['operator', 'defectEnteredBy', 'qcVerifiedBy', 'defects.defectType']),
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
            'stageExecutions' => fn ($query) => $query->orderBy('executed_at', 'asc')->orderBy('id', 'asc')->with(['operator', 'defectEnteredBy', 'qcVerifiedBy', 'defects.defectType']),
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
            ->with(['operator', 'defectEnteredBy', 'qcVerifiedBy', 'defects.defectType'])
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
        $activeCheckpoint = $this->executionService->resolveActiveCheckpoint($line);
        $operationalStatus = $this->resolveOperationalStatus($line, $currentInputQty, $activeCheckpoint);
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
            'queue_position' => $line->queue_position,
            'customer_badge' => self::resolveCustomerBadge($line->productionPlan?->customer ?? $line->castingOrderLine?->customer),
            'active_checkpoint' => $activeCheckpoint['code'] ?? null,
            'active_checkpoint_status' => $activeCheckpoint['status'] ?? null,
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
     * Resolve the current input quantity available for the traveler's active checkpoint.
     */
    protected function resolveCurrentInputQty(SandCastingCastingResultLine $line): ?int
    {
        if ($line->current_stage === null) {
            return null;
        }

        if ($line->current_stage === 'completed') {
            return 0;
        }

        $stageCheckpoints = SandCastingStageExecutionService::STAGE_CHECKPOINTS[$line->current_stage] ?? [];
        if (empty($stageCheckpoints)) {
            return 0;
        }

        $firstCheckpoint = $stageCheckpoints[0];

        // If the first checkpoint is NETTO_CUT
        if ($firstCheckpoint === 'NETTO_CUT') {
            $exec = $line->stageExecutions->firstWhere('checkpoint_code', 'NETTO_CUT');
            if ($exec) {
                return (int) $exec->good_qty;
            }

            return (int) $line->qty_good;
        }

        // Loop through checkpoints of current stage to find active unexecuted or latest input
        foreach ($stageCheckpoints as $chkCode) {
            $exec = $line->stageExecutions->firstWhere('checkpoint_code', $chkCode);

            if (! $exec) {
                // Input for this unexecuted checkpoint comes from its immediately preceding checkpoint
                $prevChkCode = SandCastingStageExecutionService::PREVIOUS_CHECKPOINT[$chkCode] ?? null;
                if ($prevChkCode) {
                    $prevExec = $line->stageExecutions
                        ->firstWhere('checkpoint_code', $prevChkCode);

                    return $prevExec ? (int) $prevExec->good_qty : 0;
                }

                return 0;
            }

            // If this checkpoint is CONFIRMED and good_qty === 0, halted with 0
            if ($exec->status === SandCastingStageExecution::STATUS_CONFIRMED && $exec->good_qty === 0) {
                return 0;
            }
        }

        // If all checkpoints in this stage have an execution, return the good_qty of the last checkpoint
        $lastChkCode = end($stageCheckpoints);
        $lastExec = $line->stageExecutions->firstWhere('checkpoint_code', $lastChkCode);

        return $lastExec ? (int) $lastExec->good_qty : 0;
    }

    /**
     * Resolve the operational status for the traveler.
     */
    protected function resolveOperationalStatus(
        SandCastingCastingResultLine $line,
        ?int $currentInputQty,
        ?array $activeCheckpoint = null
    ): string {
        if ($line->current_stage === null) {
            return self::STATUS_NO_STAGE;
        }

        if ($line->current_stage === 'completed') {
            return self::STATUS_COMPLETED;
        }

        // Check if halted anywhere in execution chain
        $haltedExec = $line->stageExecutions
            ->firstWhere(fn ($e) => $e->status === SandCastingStageExecution::STATUS_CONFIRMED && $e->good_qty === 0);
        if ($haltedExec) {
            return self::STATUS_HALTED;
        }

        if ($activeCheckpoint !== null && isset($activeCheckpoint['status'])) {
            return $activeCheckpoint['status'];
        }

        // If no active checkpoint is ready for physical execution in this stage, inspect the last execution
        $stageCheckpoints = SandCastingStageExecutionService::STAGE_CHECKPOINTS[$line->current_stage] ?? [];
        if (! empty($stageCheckpoints)) {
            $lastChkCode = end($stageCheckpoints);
            $lastExec = $line->stageExecutions->firstWhere('checkpoint_code', $lastChkCode);
            if ($lastExec) {
                return $lastExec->status;
            }
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
            'checkpoint_code' => $exec->checkpoint_code,
            'status' => $exec->status,
            'input_qty' => (int) $exec->input_qty,
            'defect_qty' => (int) $exec->defect_qty,
            'good_qty' => (int) $exec->good_qty,
            'operator_id' => $exec->operator_id,
            'operator_name' => $exec->operator?->name,
            'executed_at' => $exec->executed_at,
            'physical_done_at' => $exec->physical_done_at,
            'defect_entered_at' => $exec->defect_entered_at,
            'defect_entered_by' => $exec->defect_entered_by,
            'defect_entered_by_name' => $exec->defectEnteredBy?->name,
            'qc_verified_at' => $exec->qc_verified_at,
            'qc_verified_by' => $exec->qc_verified_by,
            'qc_verified_by_name' => $exec->qcVerifiedBy?->name,
            'notes' => $exec->notes,
            'defects' => $exec->defects ? $exec->defects->map(fn ($d) => [
                'id' => $d->id,
                'defect_type_id' => $d->defect_type_id,
                'defect_name' => $d->defectType?->name,
                'qty' => (int) $d->qty,
                'notes' => $d->notes,
            ])->values()->all() : [],
        ];
    }

    // =========================================================================
    // OPERATIONAL KANBAN READ MODEL (PHASE 3B-4A)
    // =========================================================================

    public const BUCKET_READY = 'ready';

    public const BUCKET_INCOMING = 'incoming';

    public const BUCKET_HALTED = 'halted';

    /**
     * Get structured Operational Kanban board data for a specific stage.
     *
     * @param  string  $stage  Canonical or slug stage name
     * @param  array{line_number?: int, search?: string, customer?: string, is_urgent?: bool}  $filters
     * @return array{
     *     stage: string,
     *     summary: array{
     *         ready_count: int,
     *         incoming_count: int,
     *         halted_count: int,
     *         total_count: int,
     *         ready_qty: int,
     *         incoming_qty: int,
     *         halted_qty: int
     *     },
     *     ready: list<array>,
     *     incoming: list<array>,
     *     halted: list<array>
     * }
     *
     * @throws \InvalidArgumentException
     */
    public function getStageKanbanData(string $stage, array $filters = []): array
    {
        $canonicalStage = SandCastingStageAuthorizationService::normalizeStage($stage);
        if ($canonicalStage === null) {
            throw new \InvalidArgumentException("Tahap operasional '{$stage}' tidak valid dalam alur Sand Casting.");
        }

        // Previous stage in pipeline that feeds into this stage
        $prevStage = array_search($canonicalStage, SandCastingStageExecutionService::STAGE_FLOW, true) ?: null;

        // Query all active KTR lines (excluding historical NULL stage and completed)
        $lines = SandCastingCastingResultLine::whereNotNull('current_stage')
            ->where('current_stage', '!=', 'completed')
            ->with([
                'castingResult',
                'productionPlan',
                'castingOrderLine.castingOrder',
                'urgentSetBy',
                'stageExecutions' => fn ($query) => $query->orderBy('executed_at', 'asc')->orderBy('id', 'asc')->with(['operator', 'defectEnteredBy', 'qcVerifiedBy', 'defects.defectType']),
            ])
            ->get();

        $ready = [];
        $incoming = [];
        $halted = [];

        $passesFilters = function (array $card) use ($filters): bool {
            if (isset($filters['line_number']) && $filters['line_number'] !== null && (int) $filters['line_number'] !== (int) $card['line_number']) {
                return false;
            }

            if (isset($filters['is_urgent']) && (bool) $filters['is_urgent'] !== (bool) $card['is_urgent']) {
                return false;
            }

            if (! empty($filters['customer'])) {
                $custFilter = strtolower(trim((string) $filters['customer']));
                if (! str_contains(strtolower((string) $card['customer']), $custFilter)) {
                    return false;
                }
            }

            if (! empty($filters['search'])) {
                $search = strtolower(trim((string) $filters['search']));
                $haystack = strtolower(
                    ($card['traveler_number'] ?? '').' '.
                    ($card['heat_number'] ?? '').' '.
                    ($card['production_code'] ?? '').' '.
                    ($card['item_name'] ?? '').' '.
                    ($card['item_code'] ?? '').' '.
                    ($card['customer'] ?? '')
                );
                if (! str_contains($haystack, $search)) {
                    return false;
                }
            }

            return true;
        };

        foreach ($lines as $line) {
            if ($line->current_stage === $canonicalStage) {
                $card = $this->resolveKanbanCard($line);
                if ($card === null) {
                    continue;
                }

                if (! $passesFilters($card)) {
                    continue;
                }

                if ($card['display_bucket'] === self::BUCKET_HALTED) {
                    $halted[] = $card;
                } else {
                    $ready[] = $card;
                }
            } elseif ($prevStage !== null && $line->current_stage === $prevStage) {
                $card = $this->resolveIncomingKanbanCard($line, $canonicalStage);
                if ($card === null) {
                    continue;
                }

                if (! $passesFilters($card)) {
                    continue;
                }

                $incoming[] = $card;
            }
        }

        // Sort buckets according to deterministic rules:
        // Ready bucket:
        // 1. Manual Queue Position Override (if set)
        // 2. is_urgent DESC
        // 3. cast_date ASC (oldest first)
        // 4. created_at ASC
        // 5. id ASC
        $fifoComparator = function (array $a, array $b): int {
            // 1. Manual Queue Position Override (if set)
            $hasPosA = isset($a['queue_position']) && $a['queue_position'] !== null;
            $hasPosB = isset($b['queue_position']) && $b['queue_position'] !== null;

            if ($hasPosA && $hasPosB) {
                if ($a['queue_position'] !== $b['queue_position']) {
                    return (int) $a['queue_position'] <=> (int) $b['queue_position'];
                }
            } elseif ($hasPosA && ! $hasPosB) {
                return -1; // Explicit manual queue position comes first
            } elseif (! $hasPosA && $hasPosB) {
                return 1;
            }

            // 2. Urgent priority
            if ($a['is_urgent'] !== $b['is_urgent']) {
                return $a['is_urgent'] ? -1 : 1;
            }

            // 3. Cast date (oldest first, nulls last)
            $castA = $a['cast_date'] ?? '9999-12-31';
            $castB = $b['cast_date'] ?? '9999-12-31';
            if ($castA !== $castB) {
                return strcmp($castA, $castB);
            }

            // 4. Created at
            $createdA = $a['created_at_raw'] ?? '9999-12-31 23:59:59';
            $createdB = $b['created_at_raw'] ?? '9999-12-31 23:59:59';
            if ($createdA !== $createdB) {
                return strcmp($createdA, $createdB);
            }

            // 5. ID tie breaker
            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        };

        usort($ready, $fifoComparator);
        usort($incoming, $fifoComparator);
        usort($halted, $fifoComparator);

        // Group ready cards by line to assign consecutive operational queue numbers (#01, #02, ...)
        $readyByLineCounters = [];
        foreach ($ready as &$rCard) {
            $lNum = $rCard['line_number'] ?? 1;
            $readyByLineCounters[$lNum] = ($readyByLineCounters[$lNum] ?? 0) + 1;
            $seq = $readyByLineCounters[$lNum];
            $rCard['queue_seq'] = $seq;
            $rCard['queue_number'] = sprintf('#%02d', $seq);
        }
        unset($rCard);

        // Strip internal raw sorting fields for clean output contract
        $cleaner = function (array $card): array {
            unset($card['created_at_raw']);

            return $card;
        };

        $ready = array_map($cleaner, $ready);
        $incoming = array_map($cleaner, $incoming);
        $halted = array_map($cleaner, $halted);

        $summary = [
            'ready_count' => count($ready),
            'incoming_count' => count($incoming),
            'halted_count' => count($halted),
            'total_count' => count($ready) + count($incoming) + count($halted),
            'ready_qty' => array_sum(array_column($ready, 'qty')),
            'incoming_qty' => array_sum(array_column($incoming, 'qty')),
            'halted_qty' => array_sum(array_column($halted, 'qty')),
        ];

        return [
            'stage' => $canonicalStage,
            'summary' => $summary,
            'ready' => array_values($ready),
            'incoming' => array_values($incoming),
            'halted' => array_values($halted),
        ];
    }

    /**
     * Resolve single KTR traveler into an Operational Kanban card DTO.
     */
    public function resolveKanbanCard(SandCastingCastingResultLine $line): ?array
    {
        if ($line->current_stage === null || $line->current_stage === 'completed') {
            return null;
        }

        // Check if KTR is halted anywhere in execution chain (confirmed with good_qty === 0)
        $haltedExec = $line->stageExecutions
            ->firstWhere(fn ($e) => $e->status === SandCastingStageExecution::STATUS_CONFIRMED && $e->good_qty === 0);

        if ($haltedExec) {
            $currentInputQty = (int) $haltedExec->input_qty;
            $displayStage = $line->current_stage;
            $displayBucket = self::BUCKET_HALTED;
            $displayStatus = self::STATUS_HALTED;
            $activeChkCode = $haltedExec->checkpoint_code;
            $activeChkStatus = self::STATUS_HALTED;
            $activeExec = $haltedExec;
            $inputQty = $currentInputQty;
            $goodQty = 0;
            $defectQty = (int) $haltedExec->defect_qty;
            $effectiveQty = 0;
        } else {
            $activeCheckpoint = $this->executionService->resolveActiveCheckpoint($line);
            if ($activeCheckpoint === null) {
                return null;
            }

            $chkCode = $activeCheckpoint['code'];
            $currentInputQty = $this->resolveCurrentInputQty($line);

            // Latest execution on this line (if any)
            $activeExec = $line->stageExecutions->last();

            $displayStage = $line->current_stage;
            $displayBucket = self::BUCKET_READY;
            $displayStatus = self::STATUS_READY;
            $activeChkCode = $chkCode;
            $activeChkStatus = self::STATUS_READY;
            $inputQty = $currentInputQty ?? (int) $line->qty_good;
            $defectQty = 0;
            $goodQty = $inputQty;
            $effectiveQty = $inputQty;
        }

        $size = $line->castingOrderLine?->size ?? $line->productionPlan?->size;
        $lineNumber = self::resolveLineNumber($line->productionPlan?->line_number, $size);
        $unitWeight = (float) ($line->unit_weight_kg ?? 0);
        $totalWeight = round($effectiveQty * $unitWeight, 2);
        $aging = $this->calculateAging($line, $activeExec);

        return [
            'id' => $line->id,
            'traveler_number' => $line->traveler_number,
            'heat_number' => $line->castingResult?->heat_number,
            'production_code' => $line->productionPlan?->code ?? $line->castingOrderLine?->code,
            'item_code' => $line->productionPlan?->item_code,
            'item_name' => $line->productionPlan?->item_name ?? $line->castingOrderLine?->item_name,
            'customer' => $line->productionPlan?->customer ?? $line->castingOrderLine?->customer,
            'size' => $size,
            'line_number' => $lineNumber,
            'qty' => $effectiveQty,
            'input_qty' => $inputQty,
            'good_qty' => $goodQty,
            'defect_qty' => $defectQty,
            'unit_weight_kg' => $unitWeight,
            'total_weight_kg' => $totalWeight,
            'current_stage' => $line->current_stage,
            'queue_position' => $line->queue_position,
            'customer_badge' => self::resolveCustomerBadge($line->productionPlan?->customer ?? $line->castingOrderLine?->customer),
            'display_stage' => $displayStage,
            'display_bucket' => $displayBucket,
            'display_status' => $displayStatus,
            'active_checkpoint' => $activeChkCode,
            'execution_status' => $activeChkStatus,
            'is_urgent' => (bool) $line->is_urgent,
            'cast_date' => $line->castingResult?->cast_date?->format('Y-m-d'),
            'aging' => $aging,
            'physical_done_at' => $activeExec?->physical_done_at?->format('Y-m-d H:i:s'),
            'qc_verified_at' => $activeExec?->qc_verified_at?->format('Y-m-d H:i:s'),
            'operator_name' => $activeExec?->operator?->name,
            'created_at_raw' => $line->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Resolve single KTR traveler into an Incoming Operational Kanban card DTO for target stage.
     */
    public function resolveIncomingKanbanCard(SandCastingCastingResultLine $line, string $targetStage): ?array
    {
        if ($line->current_stage === null || $line->current_stage === 'completed') {
            return null;
        }

        // Check if KTR is halted in previous stage
        $haltedExec = $line->stageExecutions
            ->firstWhere(fn ($e) => $e->status === SandCastingStageExecution::STATUS_CONFIRMED && $e->good_qty === 0);

        if ($haltedExec) {
            // Halted items in previous stage will NOT arrive at targetStage
            return null;
        }

        $activeCheckpoint = $this->executionService->resolveActiveCheckpoint($line);
        $currentInputQty = $this->resolveCurrentInputQty($line);
        $activeExec = $line->stageExecutions->last();

        $size = $line->castingOrderLine?->size ?? $line->productionPlan?->size;
        $lineNumber = self::resolveLineNumber($line->productionPlan?->line_number, $size);
        $unitWeight = (float) ($line->unit_weight_kg ?? 0);
        $effectiveQty = $currentInputQty ?? (int) $line->qty_good;
        $totalWeight = round($effectiveQty * $unitWeight, 2);
        $aging = $this->calculateAging($line, $activeExec);

        return [
            'id' => $line->id,
            'traveler_number' => $line->traveler_number,
            'heat_number' => $line->castingResult?->heat_number,
            'production_code' => $line->productionPlan?->code ?? $line->castingOrderLine?->code,
            'item_code' => $line->productionPlan?->item_code,
            'item_name' => $line->productionPlan?->item_name ?? $line->castingOrderLine?->item_name,
            'customer' => $line->productionPlan?->customer ?? $line->castingOrderLine?->customer,
            'size' => $size,
            'line_number' => $lineNumber,
            'qty' => $effectiveQty,
            'input_qty' => $effectiveQty,
            'good_qty' => $effectiveQty,
            'defect_qty' => 0,
            'unit_weight_kg' => $unitWeight,
            'total_weight_kg' => $totalWeight,
            'current_stage' => $line->current_stage,
            'queue_position' => $line->queue_position,
            'customer_badge' => self::resolveCustomerBadge($line->productionPlan?->customer ?? $line->castingOrderLine?->customer),
            'display_stage' => $targetStage,
            'display_bucket' => self::BUCKET_INCOMING,
            'display_status' => 'INCOMING',
            'active_checkpoint' => $activeCheckpoint['code'] ?? null,
            'execution_status' => $activeCheckpoint['status'] ?? 'IN_PROGRESS',
            'is_urgent' => (bool) $line->is_urgent,
            'cast_date' => $line->castingResult?->cast_date?->format('Y-m-d'),
            'aging' => $aging,
            'physical_done_at' => $activeExec?->physical_done_at?->format('Y-m-d H:i:s'),
            'qc_verified_at' => $activeExec?->qc_verified_at?->format('Y-m-d H:i:s'),
            'operator_name' => $activeExec?->operator?->name,
            'created_at_raw' => $line->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Resolve Line Number (1 to 4) using plan line number or size dimension fallback.
     */
    public static function resolveLineNumber(?int $planLineNumber, ?string $size): int
    {
        if ($planLineNumber !== null && $planLineNumber >= 1 && $planLineNumber <= 4) {
            return $planLineNumber;
        }

        if (empty($size)) {
            return 1;
        }

        $clean = trim(str_replace(['"', '”', 'inch', 'INCH'], '', $size));

        // Handle DN numbers if present:
        // DN 15-50 -> Line 1 (<= 2")
        // DN 65-125 -> Line 2 (2.5" - 5")
        // DN 150-300 -> Line 3 (6" - 12")
        // DN 350+ -> Line 4 (14"+)
        if (preg_match('/DN\s*(\d+)/i', $clean, $matches)) {
            $dn = (int) $matches[1];
            if ($dn <= 50) {
                return 1;
            }
            if ($dn <= 125) {
                return 2;
            }
            if ($dn <= 300) {
                return 3;
            }

            return 4;
        }

        $val = self::parseNumericSize($clean);
        if ($val === null) {
            return 1;
        }

        // 1/2" – 2" -> Line 1
        if ($val <= 2.001) {
            return 1;
        }

        // 2-1/2" – 5" -> Line 2
        if ($val <= 5.001) {
            return 2;
        }

        // 6" – 12" -> Line 3
        if ($val <= 12.001) {
            return 3;
        }

        // 14"+ -> Line 4
        return 4;
    }

    /**
     * Parse numeric size from fractional, mixed fraction, or decimal string.
     */
    protected static function parseNumericSize(string $sizeStr): ?float
    {
        $sizeStr = trim($sizeStr);
        if ($sizeStr === '') {
            return null;
        }

        if (is_numeric($sizeStr)) {
            return (float) $sizeStr;
        }

        // Mixed fraction like "2-1/2" or "2 1/2"
        if (preg_match('/^(\d+)[-\s]+(\d+)\/(\d+)$/', $sizeStr, $matches)) {
            $whole = (float) $matches[1];
            $num = (float) $matches[2];
            $den = (float) $matches[3];

            return $den > 0 ? $whole + ($num / $den) : $whole;
        }

        // Simple fraction like "1/2", "3/4"
        if (preg_match('/^(\d+)\/(\d+)$/', $sizeStr, $matches)) {
            $num = (float) $matches[1];
            $den = (float) $matches[2];

            return $den > 0 ? ($num / $den) : null;
        }

        return null;
    }

    /**
     * Calculate dynamic aging for Kanban card.
     */
    public function calculateAging(SandCastingCastingResultLine $line, ?SandCastingStageExecution $activeExec = null): array
    {
        $now = now();
        $castDate = $line->castingResult?->cast_date;
        $baseDate = $castDate ? $castDate->copy()->startOfDay() : ($line->created_at ? $line->created_at->copy()->startOfDay() : $now->copy()->startOfDay());

        $totalDays = max(0, (int) $baseDate->diffInDays($now->startOfDay()));

        $stageTimestamp = $activeExec?->physical_done_at
            ?? $activeExec?->executed_at
            ?? $line->stageExecutions->last()?->qc_verified_at
            ?? $line->created_at
            ?? $now;

        $stageHours = max(0, (int) $stageTimestamp->diffInHours($now));
        $stageDays = max(0, (int) $stageTimestamp->diffInDays($now));

        return [
            'total_aging_days' => $totalDays,
            'total_aging_label' => "{$totalDays}h",
            'stage_aging_days' => $stageDays,
            'stage_aging_hours' => $stageHours,
            'stage_aging_label' => $stageDays > 0 ? "{$stageDays}h" : "{$stageHours}j",
        ];
    }

    /**
     * Reorder active ready queue items for a stage and line number.
     *
     * @param  string  $stage  Canonical or slug stage name
     * @param  int  $lineNumber  1 to 4
     * @param  int  $fromPos  1-based original position
     * @param  int  $toPos  1-based target position
     * @return array{stage: string, line_number: int, total_reordered: int, from_pos: int, to_pos: int, items: list<array>}
     *
     * @throws \InvalidArgumentException
     */
    public function reorderStageLineQueue(string $stage, int $lineNumber, int $fromPos, int $toPos): array
    {
        $canonicalStage = SandCastingStageAuthorizationService::normalizeStage($stage);
        if ($canonicalStage === null) {
            throw new \InvalidArgumentException("Tahap operasional '{$stage}' tidak valid dalam alur Sand Casting.");
        }

        if ($lineNumber < 1 || $lineNumber > 4) {
            throw new \InvalidArgumentException("Line {$lineNumber} tidak valid (1-4).");
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($canonicalStage, $lineNumber, $fromPos, $toPos) {
            // Lock active KTR lines for this stage
            $lines = SandCastingCastingResultLine::where('current_stage', $canonicalStage)
                ->where('current_stage', '!=', 'completed')
                ->lockForUpdate()
                ->with([
                    'castingResult',
                    'productionPlan',
                    'castingOrderLine.castingOrder',
                    'urgentSetBy',
                    'stageExecutions' => fn ($query) => $query->orderBy('executed_at', 'asc')->orderBy('id', 'asc')->with(['operator', 'defectEnteredBy', 'qcVerifiedBy', 'defects.defectType']),
                ])
                ->get();

            $readyItems = [];
            foreach ($lines as $line) {
                $card = $this->resolveKanbanCard($line);
                if (
                    $card !== null &&
                    $card['display_stage'] === $canonicalStage &&
                    $card['display_bucket'] === self::BUCKET_READY &&
                    (int) $card['line_number'] === (int) $lineNumber
                ) {
                    $readyItems[] = [
                        'line' => $line,
                        'card' => $card,
                    ];
                }
            }

            $count = count($readyItems);
            if ($count === 0) {
                throw new \InvalidArgumentException("Tidak ada antrean aktif pada stage {$canonicalStage} line {$lineNumber}.");
            }

            if ($fromPos < 1 || $fromPos > $count) {
                throw new \InvalidArgumentException("Posisi asal ({$fromPos}) tidak valid. Antrean aktif saat ini berjumlah {$count}.");
            }

            if ($toPos < 1 || $toPos > $count) {
                throw new \InvalidArgumentException("Posisi tujuan ({$toPos}) tidak valid. Antrean aktif saat ini berjumlah {$count}.");
            }

            // Sort existing ready items using standard FIFO / queue_position comparator
            usort($readyItems, function (array $a, array $b): int {
                $cardA = $a['card'];
                $cardB = $b['card'];

                $hasPosA = isset($cardA['queue_position']) && $cardA['queue_position'] !== null;
                $hasPosB = isset($cardB['queue_position']) && $cardB['queue_position'] !== null;

                if ($hasPosA && $hasPosB) {
                    if ($cardA['queue_position'] !== $cardB['queue_position']) {
                        return (int) $cardA['queue_position'] <=> (int) $cardB['queue_position'];
                    }
                } elseif ($hasPosA && ! $hasPosB) {
                    return -1;
                } elseif (! $hasPosA && $hasPosB) {
                    return 1;
                }

                if ($cardA['is_urgent'] !== $cardB['is_urgent']) {
                    return $cardA['is_urgent'] ? -1 : 1;
                }

                $castA = $cardA['cast_date'] ?? '9999-12-31';
                $castB = $cardB['cast_date'] ?? '9999-12-31';
                if ($castA !== $castB) {
                    return strcmp($castA, $castB);
                }

                $createdA = $cardA['created_at_raw'] ?? '9999-12-31 23:59:59';
                $createdB = $cardB['created_at_raw'] ?? '9999-12-31 23:59:59';
                if ($createdA !== $createdB) {
                    return strcmp($createdA, $createdB);
                }

                return ($cardA['id'] ?? 0) <=> ($cardB['id'] ?? 0);
            });

            // Reorder array: move element from ($fromPos - 1) to ($toPos - 1)
            $moved = array_splice($readyItems, $fromPos - 1, 1);
            array_splice($readyItems, $toPos - 1, 0, $moved);

            // Re-sequence queue_position sequentially 1..N
            $reordered = [];
            $pos = 1;
            foreach ($readyItems as $entry) {
                /** @var SandCastingCastingResultLine $model */
                $model = $entry['line'];
                $model->queue_position = $pos;
                $model->save();

                $reordered[] = [
                    'id' => $model->id,
                    'traveler_number' => $model->traveler_number,
                    'queue_position' => $pos,
                    'queue_number' => sprintf('#%02d', $pos),
                ];
                $pos++;
            }

            return [
                'stage' => $canonicalStage,
                'line_number' => $lineNumber,
                'total_reordered' => count($reordered),
                'from_pos' => $fromPos,
                'to_pos' => $toPos,
                'items' => $reordered,
            ];
        });
    }

    /**
     * Resolve deterministic customer badge (code, background, text, border classes).
     *
     * @return array{code: string, label: string, bg: string, text: string, border: string}
     */
    public static function resolveCustomerBadge(?string $customer): array
    {
        $raw = trim((string) $customer);
        if ($raw === '') {
            return [
                'code' => 'STD',
                'label' => 'STD',
                'bg' => 'bg-slate-100 dark:bg-slate-800',
                'text' => 'text-slate-700 dark:text-slate-300',
                'border' => 'border-slate-300 dark:border-slate-600',
            ];
        }

        $upper = strtoupper($raw);
        $clean = preg_replace('/^(PT|CV|UD)\.?\s+/i', '', $upper);
        $clean = trim(trim($clean), '[]');
        if ($clean === '') {
            $clean = $upper;
        }

        $palettes = [
            ['bg' => 'bg-sky-50 dark:bg-sky-950/60', 'text' => 'text-sky-700 dark:text-sky-300', 'border' => 'border-sky-200 dark:border-sky-800'],
            ['bg' => 'bg-indigo-50 dark:bg-indigo-950/60', 'text' => 'text-indigo-700 dark:text-indigo-300', 'border' => 'border-indigo-200 dark:border-indigo-800'],
            ['bg' => 'bg-purple-50 dark:bg-purple-950/60', 'text' => 'text-purple-700 dark:text-purple-300', 'border' => 'border-purple-200 dark:border-purple-800'],
            ['bg' => 'bg-pink-50 dark:bg-pink-950/60', 'text' => 'text-pink-700 dark:text-pink-300', 'border' => 'border-pink-200 dark:border-pink-800'],
            ['bg' => 'bg-amber-50 dark:bg-amber-950/60', 'text' => 'text-amber-700 dark:text-amber-300', 'border' => 'border-amber-200 dark:border-amber-800'],
            ['bg' => 'bg-teal-50 dark:bg-teal-950/60', 'text' => 'text-teal-700 dark:text-teal-300', 'border' => 'border-teal-200 dark:border-teal-800'],
            ['bg' => 'bg-emerald-50 dark:bg-emerald-950/60', 'text' => 'text-emerald-700 dark:text-emerald-300', 'border' => 'border-emerald-200 dark:border-emerald-800'],
            ['bg' => 'bg-cyan-50 dark:bg-cyan-950/60', 'text' => 'text-cyan-700 dark:text-cyan-300', 'border' => 'border-cyan-200 dark:border-cyan-800'],
        ];

        $idx = abs(crc32($clean)) % count($palettes);
        $pal = $palettes[$idx];

        $displayCode = mb_substr($clean, 0, 10);

        return [
            'code' => $displayCode,
            'label' => $displayCode,
            'bg' => $pal['bg'],
            'text' => $pal['text'],
            'border' => $pal['border'],
        ];
    }

    // =========================================================================
    // DEFECT RECORDING READ MODEL (PPIC 6 TABS)
    // =========================================================================

    /**
     * Get global summary counters for PPIC Defect Recording dashboard.
     *
     * @return array{
     *     waiting_defect_count: int,
     *     waiting_defect_pcs: int,
     *     today_incoming_count: int,
     *     stage_counts: array<string, int>
     * }
     */
    public function getDefectRecordingSummary(): array
    {
        $waitingExecs = SandCastingStageExecution::where('status', SandCastingStageExecution::STATUS_WAITING_DEFECT)->get();

        $todayIncomingCount = SandCastingStageExecution::whereDate('physical_done_at', today())->count();

        $stageCounts = [];
        foreach (SandCastingStageExecutionService::STAGES as $stg) {
            $stageCounts[$stg] = $waitingExecs->where('stage', $stg)->count();
        }

        return [
            'waiting_defect_count' => $waitingExecs->count(),
            'waiting_defect_pcs' => (int) $waitingExecs->sum('input_qty'),
            'today_incoming_count' => $todayIncomingCount,
            'stage_counts' => $stageCounts,
        ];
    }

    /**
     * Get FIFO queue of stage executions for a specific stage awaiting defect recording.
     *
     * @param  string  $stage  Canonical or slug stage name
     * @param  array{search?: string}  $filters
     * @return list<array>
     */
    public function getDefectRecordingQueue(string $stage, array $filters = []): array
    {
        $canonicalStage = SandCastingStageAuthorizationService::normalizeStage($stage);
        if ($canonicalStage === null) {
            throw new \InvalidArgumentException("Tahap operasional '{$stage}' tidak valid dalam alur Sand Casting.");
        }

        $query = SandCastingStageExecution::where('stage', $canonicalStage)
            ->where('status', SandCastingStageExecution::STATUS_WAITING_DEFECT)
            ->with([
                'castingResultLine.castingResult',
                'castingResultLine.productionPlan',
                'castingResultLine.castingOrderLine.castingOrder',
                'operator',
                'defectEnteredBy',
            ])
            ->orderBy('physical_done_at', 'asc')
            ->orderBy('id', 'asc');

        $executions = $query->get();

        $items = [];
        foreach ($executions as $exec) {
            $card = $this->formatDefectRecordingCard($exec);
            if ($card === null) {
                continue;
            }

            if (! empty($filters['search'])) {
                $search = strtolower(trim((string) $filters['search']));
                $haystack = strtolower(
                    ($card['traveler_number'] ?? '').' '.
                    ($card['heat_number'] ?? '').' '.
                    ($card['production_code'] ?? '').' '.
                    ($card['item_name'] ?? '').' '.
                    ($card['item_code'] ?? '').' '.
                    ($card['customer'] ?? '').' '.
                    ($card['checkpoint_code'] ?? '')
                );
                if (! str_contains($haystack, $search)) {
                    continue;
                }
            }

            $items[] = $card;
        }

        return $items;
    }

    /**
     * Get all 6 stages queues for PPIC Defect Recording.
     *
     * @param  array{search?: string}  $filters
     * @return array<string, list<array>>
     */
    public function getAllDefectRecordingQueues(array $filters = []): array
    {
        $queues = [];
        foreach (SandCastingStageExecutionService::STAGES as $stage) {
            $queues[$stage] = $this->getDefectRecordingQueue($stage, $filters);
        }

        return $queues;
    }

    /**
     * Format a SandCastingStageExecution into a standardized Defect Recording Card DTO.
     */
    public function formatDefectRecordingCard(SandCastingStageExecution $exec): ?array
    {
        $line = $exec->castingResultLine;
        if (! $line) {
            return null;
        }

        $size = $line->castingOrderLine?->size ?? $line->productionPlan?->size;
        $lineNumber = self::resolveLineNumber($line->productionPlan?->line_number, $size);
        $aging = $this->calculateAging($line, $exec);
        $customer = $line->productionPlan?->customer ?? $line->castingOrderLine?->customer;

        // Defect status determination
        if ($exec->status === SandCastingStageExecution::STATUS_WAITING_DEFECT) {
            $defectState = 'unrecorded';
            $defectStatusLabel = 'BELUM DICATAT';
        } elseif ($exec->defect_qty === 0) {
            $defectState = 'zero';
            $defectStatusLabel = 'RUSAK 0 PCS';
        } else {
            $defectState = 'defect';
            $defectStatusLabel = "RUSAK {$exec->defect_qty} PCS";
        }

        return [
            'id' => $exec->id,
            'sand_casting_casting_result_line_id' => $exec->sand_casting_casting_result_line_id,
            'traveler_number' => $line->traveler_number,
            'heat_number' => $line->castingResult?->heat_number,
            'production_code' => $line->productionPlan?->code ?? $line->castingOrderLine?->code,
            'item_code' => $line->productionPlan?->item_code,
            'item_name' => $line->productionPlan?->item_name ?? $line->castingOrderLine?->item_name,
            'customer' => $customer,
            'customer_badge' => self::resolveCustomerBadge($customer),
            'size' => $size,
            'line_number' => $lineNumber,
            'stage' => $exec->stage,
            'checkpoint_code' => $exec->checkpoint_code,
            'checkpoint_label' => str_replace('_', ' ', $exec->checkpoint_code),
            'input_qty' => (int) $exec->input_qty,
            'defect_qty' => (int) $exec->defect_qty,
            'good_qty' => (int) $exec->good_qty,
            'status' => $exec->status,
            'defect_state' => $defectState,
            'defect_status_label' => $defectStatusLabel,
            'physical_done_at' => $exec->physical_done_at?->format('Y-m-d H:i:s'),
            'physical_done_date' => $exec->physical_done_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
            'physical_done_human' => $exec->physical_done_at?->diffForHumans(),
            'operator_name' => $exec->operator?->name ?? '-',
            'notes' => $exec->notes,
            'aging' => $aging,
            'is_urgent' => (bool) $line->is_urgent,
        ];
    }

    // =========================================================================
    // QC DEFECT VERIFICATION READ MODEL (ADMIN QC 6 TABS)
    // =========================================================================

    /**
     * Get global summary counters for QC Defect Verification dashboard.
     *
     * @return array{
     *     waiting_qc_count: int,
     *     waiting_qc_defect_pcs: int,
     *     today_verified_count: int,
     *     stage_counts: array<string, int>
     * }
     */
    public function getQcVerificationSummary(): array
    {
        $waitingExecs = SandCastingStageExecution::where('status', SandCastingStageExecution::STATUS_WAITING_QC)->get();

        $todayVerifiedCount = SandCastingStageExecution::where('status', SandCastingStageExecution::STATUS_CONFIRMED)
            ->whereDate('qc_verified_at', today())
            ->count();

        $stageCounts = [];
        foreach (SandCastingStageExecutionService::STAGES as $stg) {
            $stageCounts[$stg] = $waitingExecs->where('stage', $stg)->count();
        }

        return [
            'waiting_qc_count' => $waitingExecs->count(),
            'waiting_qc_defect_pcs' => (int) $waitingExecs->sum('defect_qty'),
            'today_verified_count' => $todayVerifiedCount,
            'stage_counts' => $stageCounts,
        ];
    }

    /**
     * Get FIFO queue of stage executions for a specific stage awaiting QC defect verification.
     * FIFO sorted by defect_entered_at ASC, id ASC.
     *
     * @param  string  $stage  Canonical or slug stage name
     * @param  array{search?: string}  $filters
     * @return list<array>
     */
    public function getQcVerificationQueue(string $stage, array $filters = []): array
    {
        $canonicalStage = SandCastingStageAuthorizationService::normalizeStage($stage);
        if ($canonicalStage === null) {
            throw new \InvalidArgumentException("Tahap operasional '{$stage}' tidak valid dalam alur Sand Casting.");
        }

        $query = SandCastingStageExecution::where('stage', $canonicalStage)
            ->where('status', SandCastingStageExecution::STATUS_WAITING_QC)
            ->with([
                'castingResultLine.castingResult',
                'castingResultLine.productionPlan',
                'castingResultLine.castingOrderLine.castingOrder',
                'operator',
                'defectEnteredBy',
                'qcVerifiedBy',
                'defects.defectType',
            ])
            ->orderBy('defect_entered_at', 'asc')
            ->orderBy('id', 'asc');

        $executions = $query->get();

        $items = [];
        foreach ($executions as $exec) {
            $card = $this->formatQcVerificationCard($exec);
            if ($card === null) {
                continue;
            }

            if (! empty($filters['search'])) {
                $search = strtolower(trim((string) $filters['search']));
                $haystack = strtolower(
                    ($card['traveler_number'] ?? '').' '.
                    ($card['heat_number'] ?? '').' '.
                    ($card['production_code'] ?? '').' '.
                    ($card['item_name'] ?? '').' '.
                    ($card['item_code'] ?? '').' '.
                    ($card['customer'] ?? '').' '.
                    ($card['checkpoint_code'] ?? '')
                );
                if (! str_contains($haystack, $search)) {
                    continue;
                }
            }

            $items[] = $card;
        }

        return $items;
    }

    /**
     * Get all 6 stages queues for QC Defect Verification.
     *
     * @param  array{search?: string}  $filters
     * @return array<string, list<array>>
     */
    public function getAllQcVerificationQueues(array $filters = []): array
    {
        $queues = [];
        foreach (SandCastingStageExecutionService::STAGES as $stage) {
            $queues[$stage] = $this->getQcVerificationQueue($stage, $filters);
        }

        return $queues;
    }

    /**
     * Format a SandCastingStageExecution into a standardized QC Verification Card DTO.
     */
    public function formatQcVerificationCard(SandCastingStageExecution $exec): ?array
    {
        $line = $exec->castingResultLine;
        if (! $line) {
            return null;
        }

        $size = $line->castingOrderLine?->size ?? $line->productionPlan?->size;
        $lineNumber = self::resolveLineNumber($line->productionPlan?->line_number, $size);
        $aging = $this->calculateAging($line, $exec);
        $customer = $line->productionPlan?->customer ?? $line->castingOrderLine?->customer;

        $defects = $exec->defects ? $exec->defects->map(fn ($d) => [
            'id' => $d->id,
            'defect_type_id' => $d->defect_type_id,
            'defect_name' => $d->defectType?->name,
            'qty' => (int) $d->qty,
            'notes' => $d->notes,
        ])->values()->all() : [];

        return [
            'id' => $exec->id,
            'sand_casting_casting_result_line_id' => $exec->sand_casting_casting_result_line_id,
            'traveler_number' => $line->traveler_number,
            'heat_number' => $line->castingResult?->heat_number,
            'production_code' => $line->productionPlan?->code ?? $line->castingOrderLine?->code,
            'item_code' => $line->productionPlan?->item_code,
            'item_name' => $line->productionPlan?->item_name ?? $line->castingOrderLine?->item_name,
            'customer' => $customer,
            'customer_badge' => self::resolveCustomerBadge($customer),
            'size' => $size,
            'line_number' => $lineNumber,
            'stage' => $exec->stage,
            'checkpoint_code' => $exec->checkpoint_code,
            'checkpoint_label' => str_replace('_', ' ', $exec->checkpoint_code),
            'input_qty' => (int) $exec->input_qty,
            'defect_qty' => (int) $exec->defect_qty,
            'good_qty' => (int) $exec->good_qty,
            'status' => $exec->status,
            'physical_done_at' => $exec->physical_done_at?->format('Y-m-d H:i:s'),
            'defect_entered_at' => $exec->defect_entered_at?->format('Y-m-d H:i:s'),
            'defect_entered_human' => $exec->defect_entered_at?->diffForHumans(),
            'defect_entered_by_name' => $exec->defectEnteredBy?->name ?? '-',
            'qc_verified_at' => $exec->qc_verified_at?->format('Y-m-d H:i:s'),
            'qc_verified_by_name' => $exec->qcVerifiedBy?->name ?? '-',
            'operator_name' => $exec->operator?->name ?? '-',
            'notes' => $exec->notes,
            'aging' => $aging,
            'is_urgent' => (bool) $line->is_urgent,
            'defects' => $defects,
        ];
    }

    /**
     * Get active defect types for a specific stage (or department).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\DefectType>
     */
    public function getStageDefectTypes(string $stage): \Illuminate\Database\Eloquent\Collection
    {
        $canonicalStage = SandCastingStageAuthorizationService::normalizeStage($stage) ?? $stage;

        return \App\Models\DefectType::where(function ($q) use ($canonicalStage) {
            $q->where('department', $canonicalStage)
                ->orWhereNull('department')
                ->orWhere('department', 'all');
        })
            ->active()
            ->orderBy('name', 'asc')
            ->get();
    }
}
