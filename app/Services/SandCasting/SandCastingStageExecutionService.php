<?php

namespace App\Services\SandCasting;

use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SandCastingStageExecutionService
{
    /**
     * Official Sand Casting execution stages.
     */
    public const STAGES = [
        'netto',
        'bubut_od',
        'marking',
        'bubut_cnc',
        'bor',
        'qc',
        'gudang_jadi',
    ];

    /**
     * Sequential stage transition mapping.
     */
    public const STAGE_FLOW = [
        'netto' => 'bubut_od',
        'bubut_od' => 'marking',
        'marking' => 'bubut_cnc',
        'bubut_cnc' => 'bor',
        'bor' => 'qc',
        'qc' => 'gudang_jadi',
        'gudang_jadi' => 'completed',
    ];

    /**
     * Preceding stage mapping for automatic input quantity resolution.
     */
    public const PREVIOUS_STAGE = [
        'bubut_od' => 'netto',
        'marking' => 'bubut_od',
        'bubut_cnc' => 'marking',
        'bor' => 'bubut_cnc',
        'qc' => 'bor',
        'gudang_jadi' => 'qc',
    ];

    /**
     * Execute a production stage for a physical KTR Traveler.
     *
     * @param  string  $travelerNumber  KTR Barcode Identity (e.g. KTR-20260920-0001)
     * @param  string  $targetStage  Target stage to execute (e.g. netto, bubut_od, ...)
     * @param  int  $defectQty  Defect / scrap quantity reported by operator
     * @param  int|null  $operatorId  User ID who performed the execution (defaults to auth()->id())
     * @param  string|null  $notes  Optional operator execution notes
     *
     * @throws InvalidArgumentException
     */
    public function execute(
        string $travelerNumber,
        string $targetStage,
        int $defectQty,
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

        return DB::transaction(function () use ($travelerNumber, $targetStage, $defectQty, $resolvedOperatorId, $notes) {
            // 1. Lock KTR row exclusively (pessimistic locking)
            $line = SandCastingCastingResultLine::where('traveler_number', $travelerNumber)
                ->lockForUpdate()
                ->first();

            if (! $line) {
                throw new InvalidArgumentException("KTR dengan nomor '{$travelerNumber}' tidak ditemukan.");
            }

            // 2. Validate operational stage existence (reject historical NULL KTRs)
            if ($line->current_stage === null) {
                throw new InvalidArgumentException("KTR {$travelerNumber} belum memiliki operational stage dan tidak dapat diproses.");
            }

            // 3. Validate completed state
            if ($line->current_stage === 'completed') {
                throw new InvalidArgumentException("KTR {$travelerNumber} sudah selesai diproses (completed) dan tidak dapat dieksekusi lagi.");
            }

            // 4. Validate current stage matches target stage
            if ($line->current_stage !== $targetStage) {
                $currentLabel = strtoupper(str_replace('_', ' ', $line->current_stage));
                $targetLabel = strtoupper(str_replace('_', ' ', $targetStage));
                throw new InvalidArgumentException("KTR {$travelerNumber} saat ini berada di stage {$currentLabel}. Tahap yang valid adalah {$currentLabel}, bukan {$targetLabel}.");
            }

            // 5. Defense-in-depth: check existing execution for this stage
            $existingExecution = $line->stageExecutions()->where('stage', $targetStage)->first();
            if ($existingExecution) {
                throw new InvalidArgumentException("KTR {$travelerNumber} sudah pernah dieksekusi pada tahap {$targetStage}.");
            }

            // 6. Resolve input quantity safely on server-side
            if ($targetStage === 'netto') {
                $inputQty = (int) $line->qty_good;
            } else {
                $prevStage = self::PREVIOUS_STAGE[$targetStage];
                $prevExec = $line->stageExecutions()->where('stage', $prevStage)->first();
                if (! $prevExec) {
                    throw new InvalidArgumentException("Riwayat eksekusi tahap sebelumnya ({$prevStage}) tidak ditemukan untuk KTR {$travelerNumber}.");
                }
                $inputQty = (int) $prevExec->good_qty;
            }

            // 7. Validate defect quantity
            if ($defectQty < 0) {
                throw new InvalidArgumentException('Jumlah defect tidak boleh negatif.');
            }

            if ($defectQty > $inputQty) {
                throw new InvalidArgumentException("Jumlah defect ({$defectQty}) tidak boleh melebihi jumlah input ({$inputQty}).");
            }

            $goodQty = $inputQty - $defectQty;

            // 8. Create stage execution record
            try {
                $execution = $line->stageExecutions()->create([
                    'stage' => $targetStage,
                    'input_qty' => $inputQty,
                    'defect_qty' => $defectQty,
                    'good_qty' => $goodQty,
                    'operator_id' => $resolvedOperatorId,
                    'executed_at' => now(),
                    'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
                ]);
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'uniq_sc_stage_exec_line_stage') || str_contains($e->getMessage(), 'UNIQUE')) {
                    throw new InvalidArgumentException("KTR {$travelerNumber} sudah pernah dieksekusi pada tahap {$targetStage}.");
                }
                throw $e;
            }

            // 9. Update current_stage in the same transaction
            if ($goodQty > 0) {
                $nextStage = self::STAGE_FLOW[$targetStage];
                $line->current_stage = $nextStage;
                $line->save();
            }
            // If goodQty === 0, current_stage remains at targetStage (halted, does not advance, not completed)

            return $execution->load(['castingResultLine', 'operator']);
        });
    }
}
