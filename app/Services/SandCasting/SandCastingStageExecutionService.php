<?php

namespace App\Services\SandCasting;

use App\Models\DefectType;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SandCastingStageExecutionService
{
    public function __construct(
        protected ?SandCastingQuantityResolverService $quantityResolver = null
    ) {
        $this->quantityResolver = $quantityResolver ?? new SandCastingQuantityResolverService;
    }

    /**
     * Official Sand Casting main process stages.
     */
    public const STAGES = [
        'netto',
        'bubut_od',
        'bubut_cnc',
        'bor',
        'qc',
        'gudang_jadi',
    ];

    /**
     * Sequential stage transition mapping (when a stage's checkpoints are all confirmed).
     */
    public const STAGE_FLOW = [
        'netto' => 'bubut_od',
        'bubut_od' => 'bubut_cnc',
        'bubut_cnc' => 'bor',
        'bor' => 'qc',
        'qc' => 'gudang_jadi',
        'gudang_jadi' => 'completed',
    ];

    /**
     * All official Checkpoints in Sand Casting pipeline.
     */
    public const CHECKPOINTS = [
        'NETTO_CUT',
        'OD_TURNING',
        'CNC_MACHINING',
        'QC_POST_CNC',
        'QC_PRE_BOR',
        'BOR_DRILLING',
        'QC_FINAL_INSPECTION',
        'GUDANG_RECEIVE',
    ];

    /**
     * Mapping from Stage to its sequential Checkpoint Codes.
     */
    public const STAGE_CHECKPOINTS = [
        'netto' => ['NETTO_CUT'],
        'bubut_od' => ['OD_TURNING'],
        'bubut_cnc' => ['CNC_MACHINING'],
        'bor' => ['BOR_DRILLING'],
        'qc' => ['QC_FINAL_INSPECTION'],
        'gudang_jadi' => ['GUDANG_RECEIVE'],
    ];

    /**
     * Mapping from Checkpoint Code to its parent Stage.
     */
    public const CHECKPOINT_STAGE = [
        'NETTO_CUT' => 'netto',
        'OD_TURNING' => 'bubut_od',
        'CNC_MACHINING' => 'bubut_cnc',
        'QC_POST_CNC' => 'bubut_cnc', // preserved for historical compatibility
        'QC_PRE_BOR' => 'bubut_cnc',  // preserved for historical compatibility
        'BOR_DRILLING' => 'bor',
        'QC_FINAL_INSPECTION' => 'qc',
        'GUDANG_RECEIVE' => 'gudang_jadi',
    ];

    /**
     * Preceding checkpoint mapping for authoritative input quantity resolution.
     */
    public const PREVIOUS_CHECKPOINT = [
        'OD_TURNING' => 'NETTO_CUT',
        'CNC_MACHINING' => 'OD_TURNING',
        'QC_POST_CNC' => 'CNC_MACHINING', // historical compatibility
        'QC_PRE_BOR' => 'QC_POST_CNC',     // historical compatibility
        'BOR_DRILLING' => 'CNC_MACHINING',
        'QC_FINAL_INSPECTION' => 'BOR_DRILLING',
        'GUDANG_RECEIVE' => 'QC_FINAL_INSPECTION',
    ];

    /**
     * Next checkpoint mapping in the pipeline.
     */
    public const NEXT_CHECKPOINT = [
        'NETTO_CUT' => 'OD_TURNING',
        'OD_TURNING' => 'CNC_MACHINING',
        'CNC_MACHINING' => 'BOR_DRILLING',
        'QC_POST_CNC' => 'QC_PRE_BOR',     // historical compatibility
        'QC_PRE_BOR' => 'BOR_DRILLING',    // historical compatibility
        'BOR_DRILLING' => 'QC_FINAL_INSPECTION',
        'QC_FINAL_INSPECTION' => 'GUDANG_RECEIVE',
        'GUDANG_RECEIVE' => null,
    ];

    /**
     * Resolve the active checkpoint that is currently ready or in-progress for a KTR.
     *
     * @return array{code: string, stage: string, status: string, execution?: SandCastingStageExecution}|null
     */
    public function resolveActiveCheckpoint(SandCastingCastingResultLine $line): ?array
    {
        if ($line->current_stage === null || $line->current_stage === 'completed') {
            return null;
        }

        // If any confirmed execution has good_qty === 0, KTR is halted (no subsequent physical checkpoint can run)
        $hasConfirmedHalted = $line->relationLoaded('stageExecutions')
            ? $line->stageExecutions->contains(fn ($e) => $e->status === SandCastingStageExecution::STATUS_CONFIRMED && $e->good_qty === 0)
            : $line->stageExecutions()->where('status', SandCastingStageExecution::STATUS_CONFIRMED)->where('good_qty', 0)->exists();

        if ($hasConfirmedHalted) {
            return null;
        }

        if ($this->quantityResolver->resolveEffectiveInputQty($line, $line->current_stage) <= 0) {
            return null;
        }

        $stageCheckpoints = self::STAGE_CHECKPOINTS[$line->current_stage] ?? [];
        if (empty($stageCheckpoints)) {
            return null;
        }

        foreach ($stageCheckpoints as $chkCode) {
            $exec = $line->relationLoaded('stageExecutions')
                ? $line->stageExecutions->firstWhere('checkpoint_code', $chkCode)
                : $line->stageExecutions()->where('checkpoint_code', $chkCode)->first();

            if (! $exec) {
                return [
                    'code' => $chkCode,
                    'stage' => $line->current_stage,
                    'status' => SandCastingStageExecution::STATUS_READY,
                ];
            }
        }

        return null;
    }

    /**
     * Step 1: Operator / SPV marks physical work done for active checkpoint.
     * Transitions checkpoint to WAITING_DEFECT and immediately advances physical current_stage.
     */
    public function markPhysicalDone(
        string $travelerNumber,
        string $targetStage,
        ?int $operatorId = null,
        ?string $notes = null
    ): SandCastingStageExecution {
        $travelerNumber = trim($travelerNumber);
        if ($travelerNumber === '') {
            throw new InvalidArgumentException('Nomor Traveler (KTR) wajib diisi.');
        }

        $targetStage = strtolower(trim($targetStage));
        if (! in_array($targetStage, self::STAGES, true)) {
            throw new InvalidArgumentException("Tahap target '{$targetStage}' tidak valid dalam alur eksekusi Sand Casting.");
        }

        $resolvedOperatorId = $operatorId ?? auth()->id();
        if (! $resolvedOperatorId) {
            throw new InvalidArgumentException('Operator ID wajib diisi atau user harus terautentikasi.');
        }

        if (! User::where('id', $resolvedOperatorId)->exists()) {
            throw new InvalidArgumentException("Operator dengan ID {$resolvedOperatorId} tidak ditemukan.");
        }

        return DB::transaction(function () use ($travelerNumber, $targetStage, $resolvedOperatorId, $notes) {
            // 1. Lock KTR row exclusively
            $line = SandCastingCastingResultLine::where('traveler_number', $travelerNumber)
                ->lockForUpdate()
                ->first();

            if (! $line) {
                throw new InvalidArgumentException("KTR dengan nomor '{$travelerNumber}' tidak ditemukan.");
            }

            if ($line->current_stage === null) {
                throw new InvalidArgumentException("KTR {$travelerNumber} belum memiliki operational stage dan tidak dapat diproses.");
            }

            if ($line->current_stage === 'completed') {
                throw new InvalidArgumentException("KTR {$travelerNumber} sudah selesai diproses (completed) dan tidak dapat dieksekusi lagi.");
            }

            if ($line->current_stage !== $targetStage) {
                $currentLabel = strtoupper(str_replace('_', ' ', $line->current_stage));
                $targetLabel = strtoupper(str_replace('_', ' ', $targetStage));
                throw new InvalidArgumentException("KTR {$travelerNumber} saat ini berada di stage {$currentLabel}. Tahap yang valid adalah {$currentLabel}, bukan {$targetLabel}.");
            }

            // 2. Resolve active checkpoint
            $active = $this->resolveActiveCheckpoint($line);
            if ($active === null) {
                throw new InvalidArgumentException("KTR {$travelerNumber} tidak memiliki checkpoint aktif yang siap diproses.");
            }

            if (isset($active['status']) && $active['status'] !== SandCastingStageExecution::STATUS_READY) {
                throw new InvalidArgumentException("KTR {$travelerNumber} sudah pernah diproses fisik pada checkpoint {$active['code']} (Status: {$active['status']}).");
            }

            $checkpointCode = $active['code'];

            // 3. Resolve input quantity strictly server-side using centralized resolver
            if ($checkpointCode === 'NETTO_CUT') {
                $inputQty = $this->quantityResolver->resolveEffectiveInputQty($line, 'netto');
            } else {
                $prevCode = self::PREVIOUS_CHECKPOINT[$checkpointCode];
                $prevExec = $line->stageExecutions()
                    ->where('checkpoint_code', $prevCode)
                    ->first();

                // Safe fallback for historical records if BOR_DRILLING looks for previous execution
                if (! $prevExec && $checkpointCode === 'BOR_DRILLING') {
                    $prevExec = $line->stageExecutions()
                        ->whereIn('checkpoint_code', ['QC_PRE_BOR', 'QC_POST_CNC', 'CNC_MACHINING'])
                        ->latest('id')
                        ->first();
                }

                if (! $prevExec) {
                    throw new InvalidArgumentException("Riwayat eksekusi checkpoint sebelumnya ({$prevCode}) belum ditemukan untuk KTR {$travelerNumber}.");
                }

                $inputQty = $this->quantityResolver->resolveEffectiveInputQty($line, $targetStage);
            }

            if ($inputQty <= 0) {
                throw new InvalidArgumentException("KTR {$travelerNumber} tidak memiliki kuantitas bagus yang tersedia untuk diproses.");
            }

            // 4. Create execution record in WAITING_DEFECT state
            try {
                $execution = $line->stageExecutions()->create([
                    'stage' => $targetStage,
                    'checkpoint_code' => $checkpointCode,
                    'input_qty' => $inputQty,
                    'defect_qty' => 0,
                    'good_qty' => $inputQty,
                    'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
                    'operator_id' => $resolvedOperatorId,
                    'physical_done_at' => now(),
                    'executed_at' => now(),
                    'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
                ]);
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'uniq_sc_stage_exec_line_chk') || str_contains($e->getMessage(), 'UNIQUE')) {
                    throw new InvalidArgumentException("KTR {$travelerNumber} sudah pernah dieksekusi pada checkpoint {$checkpointCode}.");
                }
                throw $e;
            }

            // 5. Advance physical current_stage immediately upon physical completion
            $nextCheckpoint = self::NEXT_CHECKPOINT[$checkpointCode] ?? null;
            if ($nextCheckpoint !== null) {
                $nextStage = self::CHECKPOINT_STAGE[$nextCheckpoint];
                if ($nextStage !== $line->current_stage) {
                    $line->current_stage = $nextStage;
                    $line->queue_position = null;
                    $line->save();
                }
            } else {
                // Final checkpoint in entire flow (GUDANG_RECEIVE) completed physically
                $line->current_stage = 'completed';
                $line->queue_position = null;
                $line->save();
            }

            return $execution->load(['castingResultLine', 'operator']);
        });
    }

    /**
     * Step 2: Admin PPIC records total defect quantity (Initial Recording).
     * Transitions checkpoint from WAITING_DEFECT to WAITING_QC and writes the initial audit log.
     */
    public function recordDefectQty(
        int|SandCastingStageExecution $executionOrId,
        int $defectQty,
        ?int $adminId = null,
        ?string $notes = null,
        ?string $inspectionDate = null
    ): SandCastingStageExecution {
        $resolvedAdminId = $adminId ?? auth()->id();
        if (! $resolvedAdminId) {
            throw new InvalidArgumentException('Admin ID wajib diisi atau user harus terautentikasi.');
        }

        if (! User::where('id', $resolvedAdminId)->exists()) {
            throw new InvalidArgumentException("User Admin dengan ID {$resolvedAdminId} tidak ditemukan.");
        }

        return DB::transaction(function () use ($executionOrId, $defectQty, $resolvedAdminId, $notes, $inspectionDate) {
            $executionId = $executionOrId instanceof SandCastingStageExecution ? $executionOrId->id : (int) $executionOrId;

            $execution = SandCastingStageExecution::where('id', $executionId)
                ->lockForUpdate()
                ->first();

            if (! $execution) {
                throw new InvalidArgumentException("Eksekusi dengan ID {$executionId} tidak ditemukan.");
            }

            // Lock parent line
            $line = $execution->castingResultLine()->lockForUpdate()->first();
            if (! $line) {
                throw new InvalidArgumentException('KTR terkait eksekusi ini tidak ditemukan.');
            }

            if ($execution->status === SandCastingStageExecution::STATUS_CONFIRMED) {
                throw new InvalidArgumentException("Eksekusi checkpoint {$execution->checkpoint_code} sudah berstatus CONFIRMED dan tidak dapat diubah. Gunakan fitur tambah defect untuk mencatat penambahan.");
            }

            if ($execution->status === SandCastingStageExecution::STATUS_WAITING_QC) {
                throw new InvalidArgumentException("Eksekusi checkpoint {$execution->checkpoint_code} sudah dalam status WAITING_QC dan tidak dapat diubah lagi. Gunakan fitur tambah defect untuk mencatat penambahan.");
            }

            if ($execution->status !== SandCastingStageExecution::STATUS_WAITING_DEFECT) {
                throw new InvalidArgumentException("Eksekusi checkpoint {$execution->checkpoint_code} belum dalam status WAITING_DEFECT (Status: {$execution->status}).");
            }

            if ($defectQty < 0) {
                throw new InvalidArgumentException('Jumlah defect tidak boleh negatif.');
            }

            $effectiveInput = $this->quantityResolver->resolveEffectiveInputQty($line, $execution->stage);
            if ($defectQty > $effectiveInput) {
                throw new InvalidArgumentException("Jumlah defect ({$defectQty}) tidak boleh melebihi jumlah input ({$effectiveInput}).");
            }

            $goodQty = $execution->input_qty - $defectQty;

            $execution->defect_qty = $defectQty;
            $execution->good_qty = $goodQty;
            $execution->defect_entered_at = now();
            $execution->defect_entered_by = $resolvedAdminId;
            $execution->status = SandCastingStageExecution::STATUS_WAITING_QC;
            $execution->is_auto_nihil = false;
            $execution->auto_nihil_at = null;

            if ($inspectionDate !== null && trim($inspectionDate) !== '') {
                $execution->inspection_date = trim($inspectionDate);
            }

            if ($notes !== null && trim($notes) !== '') {
                $execution->notes = trim($notes);
            }
            $execution->save();

            // Create initial defect audit log (0 -> defectQty)
            $execution->defectLogs()->create([
                'added_qty' => $defectQty,
                'previous_total' => 0,
                'new_total' => $defectQty,
                'user_id' => $resolvedAdminId,
                'is_system_action' => false,
                'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            ]);

            return $execution->load(['castingResultLine', 'operator', 'defectEnteredBy', 'defectLogs.user']);
        });
    }

    /**
     * Step 2B: Admin PPIC adjusts defect quantity with signed delta (+/-).
     * Transitions status:
     * - From CONFIRMED -> WAITING_QC (requiring QC re-verification).
     * - Maintains WAITING_QC.
     * - Rejects adjustments if still in WAITING_DEFECT (must use initial recording first).
     * Strictly atomic with row locks, dynamic boundary validation, and idempotency protection.
     * Physical flow and past scan snapshots remain untouched.
     */
    public function adjustDefectQty(
        int|SandCastingStageExecution $executionOrId,
        int $adjustmentQty,
        ?int $adminId = null,
        ?string $notes = null,
        ?string $idempotencyKey = null
    ): SandCastingStageExecution {
        $resolvedAdminId = $adminId ?? auth()->id();
        if (! $resolvedAdminId) {
            throw new InvalidArgumentException('Admin ID wajib diisi atau user harus terautentikasi.');
        }

        if (! User::where('id', $resolvedAdminId)->exists()) {
            throw new InvalidArgumentException("User Admin dengan ID {$resolvedAdminId} tidak ditemukan.");
        }

        if ($adjustmentQty === 0) {
            throw new InvalidArgumentException('Jumlah penyesuaian defect tidak boleh 0.');
        }

        $trimmedNotes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;
        if ($adjustmentQty < 0 && $trimmedNotes === null) {
            throw new InvalidArgumentException('Alasan koreksi (notes/reason) wajib diisi untuk penyesuaian defect negatif.');
        }

        $trimmedKey = $idempotencyKey !== null && trim($idempotencyKey) !== '' ? trim($idempotencyKey) : null;

        return DB::transaction(function () use ($executionOrId, $adjustmentQty, $resolvedAdminId, $trimmedNotes, $trimmedKey) {
            $executionId = $executionOrId instanceof SandCastingStageExecution ? $executionOrId->id : (int) $executionOrId;

            $execution = SandCastingStageExecution::where('id', $executionId)
                ->lockForUpdate()
                ->first();

            if (! $execution) {
                throw new InvalidArgumentException("Eksekusi dengan ID {$executionId} tidak ditemukan.");
            }

            // Lock parent line
            $line = $execution->castingResultLine()->lockForUpdate()->first();
            if (! $line) {
                throw new InvalidArgumentException('KTR terkait eksekusi ini tidak ditemukan.');
            }

            // Idempotency check: verify against both this execution and global log table
            if ($trimmedKey !== null) {
                $existingLog = \App\Models\SandCastingStageExecutionDefectLog::where('idempotency_key', $trimmedKey)
                    ->first();

                if ($existingLog) {
                    // Scenario 2: Key was used on a different execution
                    if ((int) $existingLog->sand_casting_stage_execution_id !== (int) $execution->id) {
                        throw new InvalidArgumentException("Idempotency key '{$trimmedKey}' telah digunakan pada eksekusi KTR lain.");
                    }

                    // Scenario 1: Key was used on this execution with a different payload
                    if ((int) $existingLog->added_qty !== $adjustmentQty) {
                        throw new InvalidArgumentException("Idempotency key '{$trimmedKey}' telah digunakan sebelumnya dengan kuantitas yang berbeda ({$existingLog->added_qty} PCS).");
                    }

                    // Identical retry: return existing execution without re-applying delta
                    return $execution->load(['castingResultLine', 'operator', 'defectEnteredBy', 'defectLogs.user', 'defects.defectType']);
                }
            }

            if ($execution->status === SandCastingStageExecution::STATUS_WAITING_DEFECT) {
                throw new InvalidArgumentException("Eksekusi checkpoint {$execution->checkpoint_code} belum pernah dicatat defect. Gunakan form pencatatan awal.");
            }

            if (! in_array($execution->status, [SandCastingStageExecution::STATUS_WAITING_QC, SandCastingStageExecution::STATUS_CONFIRMED], true)) {
                throw new InvalidArgumentException("Eksekusi checkpoint {$execution->checkpoint_code} berada dalam status tidak valid ({$execution->status}) untuk penyesuaian defect.");
            }

            $currentDefectQty = (int) $execution->defect_qty;
            $newTotalDefect = $currentDefectQty + $adjustmentQty;

            if ($newTotalDefect < 0) {
                throw new InvalidArgumentException("Total defect baru tidak boleh negatif ({$newTotalDefect} PCS). Koreksi {$adjustmentQty} PCS ditolak.");
            }

            // Dynamic boundary validation: defect on this stage cannot exceed effective input entering this stage
            $effectiveInput = $this->quantityResolver->resolveEffectiveInputQty($line, $execution->stage);
            if ($newTotalDefect > $effectiveInput) {
                throw new InvalidArgumentException("Total defect baru ({$newTotalDefect} PCS) melebihi batas kuantitas input efektif ({$effectiveInput} PCS). Penyesuaian {$adjustmentQty} PCS ditolak.");
            }

            $inputQty = (int) $execution->input_qty;
            $newGoodQty = max(0, $inputQty - $newTotalDefect);

            $execution->defect_qty = $newTotalDefect;
            $execution->good_qty = $newGoodQty;
            $execution->defect_entered_at = now();
            $execution->defect_entered_by = $resolvedAdminId;

            // If previously CONFIRMED, revert status to WAITING_QC so QC re-verifies the new total
            if ($execution->status === SandCastingStageExecution::STATUS_CONFIRMED) {
                $execution->status = SandCastingStageExecution::STATUS_WAITING_QC;
                $execution->qc_verified_at = null;
                $execution->qc_verified_by = null;
            }

            if ($trimmedNotes !== null) {
                $execution->notes = $trimmedNotes;
            }
            $execution->save();

            // Create cumulative audit log record with concurrency duplicate-key protection
            try {
                $execution->defectLogs()->create([
                    'added_qty' => $adjustmentQty,
                    'previous_total' => $currentDefectQty,
                    'new_total' => $newTotalDefect,
                    'user_id' => $resolvedAdminId,
                    'is_system_action' => false,
                    'notes' => $trimmedNotes,
                    'idempotency_key' => $trimmedKey,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if ($trimmedKey !== null) {
                    $racedLog = \App\Models\SandCastingStageExecutionDefectLog::where('idempotency_key', $trimmedKey)->first();
                    if ($racedLog && (int) $racedLog->sand_casting_stage_execution_id === (int) $execution->id && (int) $racedLog->added_qty === $adjustmentQty) {
                        return $execution->fresh(['castingResultLine', 'operator', 'defectEnteredBy', 'defectLogs.user', 'defects.defectType']);
                    }
                    if ($racedLog && (int) $racedLog->sand_casting_stage_execution_id !== (int) $execution->id) {
                        throw new InvalidArgumentException("Idempotency key '{$trimmedKey}' telah digunakan pada eksekusi KTR lain.");
                    }
                }
                throw new InvalidArgumentException('Konflik idempotency key: request penyesuaian telah diproses secara bersamaan.');
            }

            return $execution->load(['castingResultLine', 'operator', 'defectEnteredBy', 'defectLogs.user', 'defects.defectType']);
        });
    }

    /**
     * Step 2B: Admin PPIC adds cumulative defect quantity (Tambah Defect Susulan).
     * Backward-compatible alias for adjustDefectQty.
     */
    public function addDefectQty(
        int|SandCastingStageExecution $executionOrId,
        int $addedQty,
        ?int $adminId = null,
        ?string $notes = null,
        ?string $idempotencyKey = null
    ): SandCastingStageExecution {
        return $this->adjustDefectQty($executionOrId, $addedQty, $adminId, $notes, $idempotencyKey);
    }

    /**
     * Step 2-Timeout: Auto-Nihil after 5 calendar days timeout.
     * Transitions WAITING_DEFECT -> WAITING_QC with defect_qty = 0, good_qty = input_qty, is_auto_nihil = true.
     * Strictly atomic with lockForUpdate().
     */
    public function timeoutToAutoNihil(
        int|SandCastingStageExecution $executionOrId,
        ?int $systemActorId = null,
        ?string $notes = null
    ): SandCastingStageExecution {
        return DB::transaction(function () use ($executionOrId, $systemActorId, $notes) {
            $executionId = $executionOrId instanceof SandCastingStageExecution ? $executionOrId->id : (int) $executionOrId;

            $execution = SandCastingStageExecution::where('id', $executionId)
                ->lockForUpdate()
                ->first();

            if (! $execution) {
                throw new InvalidArgumentException("Eksekusi dengan ID {$executionId} tidak ditemukan.");
            }

            if ($execution->is_auto_nihil && $execution->status === SandCastingStageExecution::STATUS_WAITING_QC) {
                return $execution->load(['castingResultLine', 'operator', 'defectLogs.user']);
            }

            if ($execution->status !== SandCastingStageExecution::STATUS_WAITING_DEFECT) {
                throw new InvalidArgumentException("Eksekusi checkpoint {$execution->checkpoint_code} sudah tidak dalam status WAITING_DEFECT (Status: {$execution->status}).");
            }

            if ($execution->physical_done_at === null) {
                throw new InvalidArgumentException("Eksekusi checkpoint {$execution->checkpoint_code} tidak memiliki physical_done_at yang valid.");
            }

            $now = now();
            $inputQty = (int) $execution->input_qty;

            $execution->defect_qty = 0;
            $execution->good_qty = $inputQty;
            $execution->defect_entered_at = $now;
            $execution->defect_entered_by = $systemActorId;
            $execution->status = SandCastingStageExecution::STATUS_WAITING_QC;
            $execution->is_auto_nihil = true;
            $execution->auto_nihil_at = $now;
            if ($notes !== null && trim($notes) !== '') {
                $execution->notes = trim($notes);
            }
            $execution->save();

            $execution->defectLogs()->create([
                'added_qty' => 0,
                'previous_total' => 0,
                'new_total' => 0,
                'user_id' => $systemActorId,
                'is_system_action' => true,
                'notes' => $notes ?? 'Penetapan Nihil Otomatis (Timeout 5 Hari)',
            ]);

            return $execution->load(['castingResultLine', 'operator', 'defectLogs.user']);
        });
    }

    /**
     * Step 3A: QC Inspector saves draft defect classification breakdown.
     * Maintains status WAITING_QC and does NOT confirm the execution.
     * Total draft breakdown must be > 0 and <= execution.defect_qty.
     * Strictly atomic with lockForUpdate().
     */
    public function saveQcBreakdownDraft(
        int|SandCastingStageExecution $executionOrId,
        array $defects = [],
        ?int $qcUserId = null,
        ?string $notes = null,
        ?string $inspectionDate = null
    ): SandCastingStageExecution {
        $resolvedQcUserId = $qcUserId ?? auth()->id();
        if (! $resolvedQcUserId) {
            throw new InvalidArgumentException('QC User ID wajib diisi atau user harus terautentikasi.');
        }

        if (! User::where('id', $resolvedQcUserId)->exists()) {
            throw new InvalidArgumentException("User QC dengan ID {$resolvedQcUserId} tidak ditemukan.");
        }

        return DB::transaction(function () use ($executionOrId, $defects, $notes, $inspectionDate) {
            $executionId = $executionOrId instanceof SandCastingStageExecution ? $executionOrId->id : (int) $executionOrId;

            $execution = SandCastingStageExecution::where('id', $executionId)
                ->lockForUpdate()
                ->first();

            if (! $execution) {
                throw new InvalidArgumentException("Eksekusi dengan ID {$executionId} tidak ditemukan.");
            }

            $line = $execution->castingResultLine()->lockForUpdate()->first();
            if (! $line) {
                throw new InvalidArgumentException('KTR terkait eksekusi ini tidak ditemukan.');
            }

            if ($execution->status === SandCastingStageExecution::STATUS_CONFIRMED) {
                throw new InvalidArgumentException("Eksekusi checkpoint {$execution->checkpoint_code} sudah berstatus CONFIRMED. Draft tidak dapat disimpan.");
            }

            if ($execution->status !== SandCastingStageExecution::STATUS_WAITING_QC) {
                throw new InvalidArgumentException("Eksekusi checkpoint {$execution->checkpoint_code} belum dalam status verifikasi QC (Status: {$execution->status}).");
            }

            if ((int) $execution->defect_qty <= 0) {
                throw new InvalidArgumentException('Tidak dapat menyimpan draft rincian cacat karena total defect PPIC adalah 0 PCS.');
            }

            if (empty($defects)) {
                throw new InvalidArgumentException('Rincian cacat (defects) tidak boleh kosong untuk menyimpan draft.');
            }

            $totalBreakdown = 0;
            $validatedDefects = [];

            foreach ($defects as $d) {
                $qty = (int) ($d['qty'] ?? 0);
                if ($qty <= 0) {
                    throw new InvalidArgumentException('Kuantitas setiap jenis cacat harus lebih dari 0.');
                }

                $typeId = (int) ($d['defect_type_id'] ?? 0);
                if (! DefectType::where('id', $typeId)->exists()) {
                    throw new InvalidArgumentException("Jenis cacat dengan ID {$typeId} tidak ditemukan.");
                }

                $validatedDefects[] = [
                    'defect_type_id' => $typeId,
                    'qty' => $qty,
                    'notes' => isset($d['notes']) && trim($d['notes']) !== '' ? trim($d['notes']) : null,
                ];

                $totalBreakdown += $qty;
            }

            if ($totalBreakdown <= 0) {
                throw new InvalidArgumentException('Total kuantitas draft cacat harus lebih dari 0 PCS.');
            }

            if ($totalBreakdown > (int) $execution->defect_qty) {
                throw new InvalidArgumentException("Total rincian draft cacat ({$totalBreakdown} PCS) melebihi total defect PPIC ({$execution->defect_qty} PCS).");
            }

            // Replace defects with validated draft rows
            $execution->defects()->delete();
            foreach ($validatedDefects as $vd) {
                $execution->defects()->create($vd);
            }

            // Crucial: status remains STATUS_WAITING_QC, qc_verified_at and qc_verified_by remain untouched
            if ($inspectionDate !== null && trim($inspectionDate) !== '') {
                $parsedDate = \Carbon\Carbon::parse($inspectionDate)->startOfDay();
                if ($parsedDate->isFuture()) {
                    throw new InvalidArgumentException('Tanggal pemeriksaan fisik / defect tidak boleh di masa depan.');
                }
                $execution->inspection_date = $parsedDate->toDateString();
            }
            if ($notes !== null && trim($notes) !== '') {
                $execution->notes = trim($notes);
            }
            $execution->save();

            return $execution->load(['castingResultLine', 'operator', 'defectEnteredBy', 'defects.defectType']);
        });
    }

    /**
     * Step 3: QC Inspector verifies defect classification breakdown.
     * Transitions checkpoint from WAITING_QC to CONFIRMED.
     */
    public function verifyQcBreakdown(
        int|SandCastingStageExecution $executionOrId,
        array $defects = [],
        ?int $qcUserId = null,
        ?string $notes = null,
        ?string $inspectionDate = null
    ): SandCastingStageExecution {
        $resolvedQcUserId = $qcUserId ?? auth()->id();
        if (! $resolvedQcUserId) {
            throw new InvalidArgumentException('QC User ID wajib diisi atau user harus terautentikasi.');
        }

        if (! User::where('id', $resolvedQcUserId)->exists()) {
            throw new InvalidArgumentException("User QC dengan ID {$resolvedQcUserId} tidak ditemukan.");
        }

        return DB::transaction(function () use ($executionOrId, $defects, $resolvedQcUserId, $notes, $inspectionDate) {
            $executionId = $executionOrId instanceof SandCastingStageExecution ? $executionOrId->id : (int) $executionOrId;

            $execution = SandCastingStageExecution::where('id', $executionId)
                ->lockForUpdate()
                ->first();

            if (! $execution) {
                throw new InvalidArgumentException("Eksekusi dengan ID {$executionId} tidak ditemukan.");
            }

            $line = $execution->castingResultLine()->lockForUpdate()->first();
            if (! $line) {
                throw new InvalidArgumentException('KTR terkait eksekusi ini tidak ditemukan.');
            }

            if ($execution->status === SandCastingStageExecution::STATUS_CONFIRMED) {
                throw new InvalidArgumentException("Eksekusi checkpoint {$execution->checkpoint_code} sudah berstatus CONFIRMED.");
            }

            if ($execution->status !== SandCastingStageExecution::STATUS_WAITING_QC) {
                throw new InvalidArgumentException("Eksekusi checkpoint {$execution->checkpoint_code} belum dalam status verifikasi QC (Status: {$execution->status}).");
            }

            // Defect breakdown validation
            if ($execution->defect_qty === 0) {
                if (! empty($defects)) {
                    $totalBreakdown = 0;
                    foreach ($defects as $d) {
                        $totalBreakdown += (int) ($d['qty'] ?? 0);
                    }
                    if ($totalBreakdown > 0) {
                        throw new InvalidArgumentException('Klasifikasi cacat tidak valid karena total defect adalah 0.');
                    }
                }
                $execution->defects()->delete();
            } else {
                $totalBreakdown = 0;
                $validatedDefects = [];

                foreach ($defects as $d) {
                    $qty = (int) ($d['qty'] ?? 0);
                    if ($qty <= 0) {
                        throw new InvalidArgumentException('Kuantitas setiap jenis cacat harus lebih dari 0.');
                    }

                    $typeId = (int) ($d['defect_type_id'] ?? 0);
                    if (! DefectType::where('id', $typeId)->exists()) {
                        throw new InvalidArgumentException("Jenis cacat dengan ID {$typeId} tidak ditemukan.");
                    }

                    $validatedDefects[] = [
                        'defect_type_id' => $typeId,
                        'qty' => $qty,
                        'notes' => isset($d['notes']) && trim($d['notes']) !== '' ? trim($d['notes']) : null,
                    ];

                    $totalBreakdown += $qty;
                }

                if ($totalBreakdown !== (int) $execution->defect_qty) {
                    throw new InvalidArgumentException("Total klasifikasi cacat ({$totalBreakdown} PCS) tidak sesuai dengan total defect ({$execution->defect_qty} PCS).");
                }

                // Delete and recreate defect breakdown
                $execution->defects()->delete();
                foreach ($validatedDefects as $vd) {
                    $execution->defects()->create($vd);
                }
            }

            // Update execution state to CONFIRMED
            $execution->status = SandCastingStageExecution::STATUS_CONFIRMED;
            $execution->qc_verified_at = now();
            $execution->qc_verified_by = $resolvedQcUserId;
            if ($inspectionDate !== null && trim($inspectionDate) !== '') {
                $parsedDate = \Carbon\Carbon::parse($inspectionDate)->startOfDay();
                if ($parsedDate->isFuture()) {
                    throw new InvalidArgumentException('Tanggal pemeriksaan fisik / defect tidak boleh di masa depan.');
                }
                $execution->inspection_date = $parsedDate->toDateString();
            }
            if ($notes !== null && trim($notes) !== '') {
                $execution->notes = trim($notes);
            }
            $execution->save();

            return $execution->load(['castingResultLine', 'operator', 'defectEnteredBy', 'qcVerifiedBy', 'defects.defectType']);
        });
    }

    /**
     * Backward-compatible execute method (used by legacy scanner tests/pilot until UI refactor).
     * Automates: markPhysicalDone -> recordDefectQty -> verifyQcBreakdown (zero or mock breakdown).
     */
    public function execute(
        string $travelerNumber,
        string $targetStage,
        int $defectQty,
        ?int $operatorId = null,
        ?string $notes = null
    ): SandCastingStageExecution {
        $execution = $this->markPhysicalDone($travelerNumber, $targetStage, $operatorId, $notes);
        $execution = $this->recordDefectQty($execution, $defectQty, $operatorId, $notes);

        $defects = [];
        if ($defectQty > 0) {
            // Find or create default defect type for backward compatibility
            $defectType = DefectType::firstOrCreate(
                ['department' => $targetStage, 'name' => 'Defect '.ucfirst($targetStage)],
                ['is_active' => true]
            );
            $defects[] = [
                'defect_type_id' => $defectType->id,
                'qty' => $defectQty,
                'notes' => $notes,
            ];
        }

        return $this->verifyQcBreakdown($execution, $defects, $operatorId, $notes);
    }
}
