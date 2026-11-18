<?php

namespace App\Services\Fitting;

use App\Models\FittingItem;
use App\Models\FittingStageExecution;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FittingStageExecutionService
{
    private readonly FittingQuantityResolverService $quantityResolver;

    public function __construct(
        private readonly FittingStageAuthorizationService $authService,
        ?FittingQuantityResolverService $quantityResolver = null
    ) {
        $this->quantityResolver = $quantityResolver ?? new FittingQuantityResolverService;
    }

    /**
     * Execute a single physical scan for a Fitting Production traveler (PFKTR) on a locked stage.
     *
     * Strictly enforces:
     * - Authorization via FittingStageAuthorizationService::authorizeStageExecution()
     * - Single traveler identity by PFKTR only (never creates travelers, never mutates Lost Wax)
     * - Strict linear 6-stage sequence (netto_pf -> kimia -> marking -> bubut_cnc_pf -> qc_pf -> gudang_jadi_pf -> completed)
     * - No stage skipping, no rework, no duplicate scan, no scanning CANCELLED or COMPLETED items
     * - Compatible Phase 2.1 lock hierarchy: locks `fitting_items` -> `fitting_stage_executions`
     * - Phase 4 compatibility: stores defect_qty = 0, good_qty = input_qty, status = WAITING_DEFECT
     * - Resets `queue_position = null` atomically upon stage transition so queue positions never collide across stages
     * - Preserves `status = ACTIVE` and `active_kitir_id` upon reaching `completed` (blocking re-transfer & Heat cancel)
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws InvalidArgumentException
     */
    public function scanStage(
        string $travelerNumber,
        string $targetStage,
        User $user,
        ?string $notes = null
    ): FittingStageExecution {
        $canonicalStage = FittingStageAuthorizationService::normalizeStage($targetStage);
        if ($canonicalStage === null) {
            throw new InvalidArgumentException("Tahap operasional '{$targetStage}' tidak valid dalam alur Fitting Production.");
        }

        // 1. Strict RBAC authorization before touching database
        $this->authService->authorizeStageExecution($user, $canonicalStage);

        $pfktr = strtoupper(trim($travelerNumber));
        if ($pfktr === '') {
            throw new InvalidArgumentException('Nomor Kitir (PFKTR) wajib diisi.');
        }

        if (! str_starts_with($pfktr, 'PFKTR-')) {
            throw new InvalidArgumentException("Identitas scan harus berupa nomor Kitir PFKTR (contoh: PFKTR-YYYYMMDD-XXXX), bukan '{$pfktr}'. Gunakan fitur Cari Heat untuk memilih PFKTR spesifik.");
        }

        $checkpointCode = FittingStageAuthorizationService::STAGE_CHECKPOINTS[$canonicalStage] ?? null;
        if ($checkpointCode === null) {
            throw new InvalidArgumentException("Checkpoint untuk tahap '{$canonicalStage}' tidak terdefinisi.");
        }

        $cleanNotes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;
        $stageLabel = FittingStageAuthorizationService::STAGE_LABELS[$canonicalStage] ?? strtoupper($canonicalStage);

        try {
            return DB::transaction(function () use ($pfktr, $canonicalStage, $checkpointCode, $stageLabel, $user, $cleanNotes) {
                // 2. Acquire lock on ACTIVE FittingItem first (compatible with cancelTransferItem lock hierarchy)
                $item = FittingItem::where('traveler_number', $pfktr)
                    ->where('status', FittingItem::STATUS_ACTIVE)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (! $item) {
                    // Distinguish CANCELLED transfer vs unknown PFKTR cleanly
                    $anyItem = FittingItem::where('traveler_number', $pfktr)
                        ->orderByDesc('id')
                        ->lockForUpdate()
                        ->first();

                    if ($anyItem && $anyItem->status === FittingItem::STATUS_CANCELLED) {
                        throw new InvalidArgumentException("Traveler [{$pfktr}] memiliki transfer berstatus DIBATALKAN (CANCELLED) dan tidak dapat dipindai.");
                    }

                    throw new InvalidArgumentException("Nomor Kitir [{$pfktr}] tidak ditemukan pada antrean aktif Fitting Production.");
                }

                // 3. Re-verify ACTIVE status strictly after lock acquisition
                if ($item->status !== FittingItem::STATUS_ACTIVE) {
                    throw new InvalidArgumentException("Traveler [{$pfktr}] tidak berstatus ACTIVE (status saat ini: {$item->status}) dan tidak dapat dipindai.");
                }

                // 4. Reject COMPLETED travelers
                if ($item->current_stage === FittingItem::STAGE_COMPLETED) {
                    throw new InvalidArgumentException("Traveler [{$pfktr}] sudah berstatus SELESAI (COMPLETED) di Gudang Jadi PF dan tidak dapat diproses ulang.");
                }

                // 5. Strict stage sequence validation (no skipping, no rework / already-passed stage)
                $currentStage = $item->current_stage;
                $currentIdx = array_search($currentStage, FittingItem::STAGES, true);
                $targetIdx = array_search($canonicalStage, FittingItem::STAGES, true);

                if ($currentIdx === false || $targetIdx === false) {
                    throw new InvalidArgumentException("Status tahap traveler [{$pfktr}] ('{$currentStage}') tidak valid.");
                }

                if ($targetIdx < $currentIdx) {
                    $currentLabel = FittingStageAuthorizationService::STAGE_LABELS[$currentStage] ?? strtoupper((string) $currentStage);
                    throw new InvalidArgumentException(
                        "Traveler [{$pfktr}] sudah melewati tahap {$stageLabel} (posisi saat ini: {$currentLabel}). Rework / scan ulang tahap sebelumnya tidak diperbolehkan."
                    );
                }

                if ($targetIdx > $currentIdx) {
                    $currentLabel = FittingStageAuthorizationService::STAGE_LABELS[$currentStage] ?? strtoupper((string) $currentStage);
                    throw new InvalidArgumentException(
                        "Tidak dapat melompati tahap. Traveler [{$pfktr}] saat ini berada di tahap {$currentLabel}, belum mencapai tahap {$stageLabel}."
                    );
                }

                // 6. Lock all fitting_stage_executions for this traveler in deterministic ORDER BY id ASC
                //    (identical to FittingDefectService canonical lock order — prevents AB-BA InnoDB deadlocks)
                $existingExecutions = FittingStageExecution::where('fitting_item_id', $item->id)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                $item->setRelation('stageExecutions', $existingExecutions);

                if ($existingExecutions->contains('checkpoint_code', $checkpointCode)) {
                    throw new InvalidArgumentException("Traveler [{$pfktr}] sudah pernah dipindai pada tahap {$stageLabel}.");
                }

                // 7. Determine next stage and effective input quantity (Phase 4: deducts upstream defects cleanly)
                $nextStage = FittingItem::nextStageFor($canonicalStage);
                if ($nextStage === null) {
                    throw new InvalidArgumentException("Tahap berikutnya setelah '{$canonicalStage}' tidak dapat ditentukan.");
                }

                $now = Carbon::now();
                $inputQty = $this->quantityResolver->resolveEffectiveInputQty($item, $canonicalStage);

                if ($inputQty <= 0) {
                    throw new InvalidArgumentException(
                        "Traveler [{$pfktr}] tidak memiliki kuantitas bagus tersisa (Effective Input = 0 PCS) untuk diproses pada tahap {$stageLabel}."
                    );
                }

                $execution = FittingStageExecution::create([
                    'fitting_item_id' => $item->id,
                    'stage' => $canonicalStage,
                    'checkpoint_code' => $checkpointCode,
                    'input_qty' => $inputQty,
                    'defect_qty' => 0,
                    'good_qty' => $inputQty,
                    'status' => FittingStageExecution::STATUS_WAITING_DEFECT,
                    'operator_id' => $user->id,
                    'executed_at' => $now,
                    'physical_done_at' => $now,
                    'notes' => $cleanNotes,
                ]);

                // 8. Atomically advance current_stage and reset queue_position (retaining status=ACTIVE & active_kitir_id!)
                $item->update([
                    'current_stage' => $nextStage,
                    'queue_position' => null,
                ]);

                return $execution->load(['fittingItem', 'operator']);
            });
        } catch (QueryException $e) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'uk_fit_exec_item_chk') || str_contains($message, 'fitting_stage_executions.fitting_item_id')) {
                throw new InvalidArgumentException("Traveler [{$pfktr}] sudah dipindai pada tahap {$stageLabel} oleh transaksi bersamaan.", 0, $e);
            }

            throw $e;
        }
    }
}
