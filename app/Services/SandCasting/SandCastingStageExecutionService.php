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
        'bubut_cnc' => ['CNC_MACHINING', 'QC_POST_CNC', 'QC_PRE_BOR'],
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
        'QC_POST_CNC' => 'bubut_cnc',
        'QC_PRE_BOR' => 'bubut_cnc',
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
        'QC_POST_CNC' => 'CNC_MACHINING',
        'QC_PRE_BOR' => 'QC_POST_CNC',
        'BOR_DRILLING' => 'QC_PRE_BOR',
        'QC_FINAL_INSPECTION' => 'BOR_DRILLING',
        'GUDANG_RECEIVE' => 'QC_FINAL_INSPECTION',
    ];

    /**
     * Next checkpoint mapping in the pipeline.
     */
    public const NEXT_CHECKPOINT = [
        'NETTO_CUT' => 'OD_TURNING',
        'OD_TURNING' => 'CNC_MACHINING',
        'CNC_MACHINING' => 'QC_POST_CNC',
        'QC_POST_CNC' => 'QC_PRE_BOR',
        'QC_PRE_BOR' => 'BOR_DRILLING',
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

            if ($exec->status !== SandCastingStageExecution::STATUS_CONFIRMED) {
                return [
                    'code' => $chkCode,
                    'stage' => $line->current_stage,
                    'status' => $exec->status,
                    'execution' => $exec,
                ];
            }

            // If confirmed with good_qty === 0, it is halted (no subsequent checkpoint can run)
            if ($exec->good_qty === 0) {
                return null;
            }
        }

        return null;
    }

    /**
     * Step 1: Operator / SPV marks physical work done for active checkpoint.
     * Transitions checkpoint to WAITING_DEFECT.
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

            // 3. Resolve input quantity strictly server-side
            if ($checkpointCode === 'NETTO_CUT') {
                $inputQty = (int) $line->qty_good;
            } else {
                $prevCode = self::PREVIOUS_CHECKPOINT[$checkpointCode];
                $prevExec = $line->stageExecutions()
                    ->where('checkpoint_code', $prevCode)
                    ->where('status', SandCastingStageExecution::STATUS_CONFIRMED)
                    ->first();

                if (! $prevExec) {
                    throw new InvalidArgumentException("Riwayat eksekusi checkpoint sebelumnya ({$prevCode}) belum dikonfirmasi untuk KTR {$travelerNumber}.");
                }

                $inputQty = (int) $prevExec->good_qty;
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

            return $execution->load(['castingResultLine', 'operator']);
        });
    }

    /**
     * Step 2: Admin PPIC records total defect quantity.
     * Transitions checkpoint from WAITING_DEFECT to WAITING_QC.
     */
    public function recordDefectQty(
        int|SandCastingStageExecution $executionOrId,
        int $defectQty,
        ?int $adminId = null,
        ?string $notes = null
    ): SandCastingStageExecution {
        $resolvedAdminId = $adminId ?? auth()->id();
        if (! $resolvedAdminId) {
            throw new InvalidArgumentException('Admin ID wajib diisi atau user harus terautentikasi.');
        }

        if (! User::where('id', $resolvedAdminId)->exists()) {
            throw new InvalidArgumentException("User Admin dengan ID {$resolvedAdminId} tidak ditemukan.");
        }

        return DB::transaction(function () use ($executionOrId, $defectQty, $resolvedAdminId, $notes) {
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
                throw new InvalidArgumentException("Eksekusi checkpoint {$execution->checkpoint_code} sudah berstatus CONFIRMED dan tidak dapat diubah.");
            }

            if ($execution->status === SandCastingStageExecution::STATUS_WAITING_QC) {
                throw new InvalidArgumentException("Eksekusi checkpoint {$execution->checkpoint_code} sudah dalam status WAITING_QC dan tidak dapat diubah lagi.");
            }

            if ($execution->status !== SandCastingStageExecution::STATUS_WAITING_DEFECT) {
                throw new InvalidArgumentException("Eksekusi checkpoint {$execution->checkpoint_code} belum dalam status WAITING_DEFECT (Status: {$execution->status}).");
            }

            if ($defectQty < 0) {
                throw new InvalidArgumentException('Jumlah defect tidak boleh negatif.');
            }

            if ($defectQty > $execution->input_qty) {
                throw new InvalidArgumentException("Jumlah defect ({$defectQty}) tidak boleh melebihi jumlah input ({$execution->input_qty}).");
            }

            $goodQty = $execution->input_qty - $defectQty;

            $execution->defect_qty = $defectQty;
            $execution->good_qty = $goodQty;
            $execution->defect_entered_at = now();
            $execution->defect_entered_by = $resolvedAdminId;
            $execution->status = SandCastingStageExecution::STATUS_WAITING_QC;
            if ($notes !== null && trim($notes) !== '') {
                $execution->notes = trim($notes);
            }
            $execution->save();

            return $execution->load(['castingResultLine', 'operator', 'defectEnteredBy']);
        });
    }

    /**
     * Step 3: QC Inspector verifies defect classification breakdown.
     * Transitions checkpoint from WAITING_QC to CONFIRMED and opens downstream stage/checkpoint.
     */
    public function verifyQcBreakdown(
        int|SandCastingStageExecution $executionOrId,
        array $defects = [],
        ?int $qcUserId = null,
        ?string $notes = null
    ): SandCastingStageExecution {
        $resolvedQcUserId = $qcUserId ?? auth()->id();
        if (! $resolvedQcUserId) {
            throw new InvalidArgumentException('QC User ID wajib diisi atau user harus terautentikasi.');
        }

        if (! User::where('id', $resolvedQcUserId)->exists()) {
            throw new InvalidArgumentException("User QC dengan ID {$resolvedQcUserId} tidak ditemukan.");
        }

        return DB::transaction(function () use ($executionOrId, $defects, $resolvedQcUserId, $notes) {
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
            if ($notes !== null && trim($notes) !== '') {
                $execution->notes = trim($notes);
            }
            $execution->save();

            // Advance process stage only if good_qty > 0 and this is the last checkpoint of current process
            if ($execution->good_qty > 0) {
                $nextCheckpoint = self::NEXT_CHECKPOINT[$execution->checkpoint_code] ?? null;

                if ($nextCheckpoint !== null) {
                    $nextStage = self::CHECKPOINT_STAGE[$nextCheckpoint];
                    if ($nextStage !== $line->current_stage) {
                        $line->current_stage = $nextStage;
                        $line->queue_position = null;
                        $line->save();
                    }
                } else {
                    // Final checkpoint in entire flow (GUDANG_RECEIVE) confirmed
                    $line->current_stage = 'completed';
                    $line->queue_position = null;
                    $line->save();
                }
            }
            // If good_qty === 0, current_stage remains unchanged (halted, does not advance, not completed)

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
