<?php

namespace App\Services\Fitting;

use App\Models\FittingItem;
use App\Models\FittingKitirDecision;
use App\Models\LostWaxCastingResult;
use App\Models\LostWaxKitir;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FittingKitirDecisionService
{
    public function __construct(
        private readonly FittingStageAuthorizationService $authService
    ) {}

    /**
     * Mark an issued, untransferred LostWaxKitir as NOT_CONTINUED (terminal unless explicitly reopened).
     * Never performs hard delete; reason is optional (nullable).
     *
     * Canonical lock hierarchy:
     *   1. lost_wax_casting_results -> 2. lost_wax_kitirs -> 3. fitting_items -> 4. fitting_kitir_decisions
     *
     * @throws InvalidArgumentException
     */
    public function markNotContinued(LostWaxKitir $kitir, User $user, ?string $reason = null): FittingKitirDecision
    {
        $this->authService->authorizeTransferKitir($user);

        $cleanReason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        try {
            return DB::transaction(function () use ($kitir, $user, $cleanReason) {
                // 1. Lock parent LostWaxCastingResult FIRST
                $castingResult = LostWaxCastingResult::where('id', $kitir->lost_wax_casting_result_id)
                    ->lockForUpdate()
                    ->first();

                // 2. Lock child LostWaxKitir SECOND
                $lockedKitir = LostWaxKitir::where('id', $kitir->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $castingResult && $lockedKitir->lost_wax_casting_result_id) {
                    $castingResult = LostWaxCastingResult::where('id', $lockedKitir->lost_wax_casting_result_id)
                        ->lockForUpdate()
                        ->first();
                }

                if ($lockedKitir->issued_at === null) {
                    throw new InvalidArgumentException("Kitir [{$lockedKitir->traveler_number}] belum diterbitkan sehingga tidak dapat ditandai NOT_CONTINUED.");
                }

                if (! $castingResult || $castingResult->status !== 'COMPLETED') {
                    throw new InvalidArgumentException("Kitir [{$lockedKitir->traveler_number}] berasal dari Heat yang sudah dibatalkan (CANCELLED).");
                }

                // 3. Lock & verify fitting_items (cannot mark NOT_CONTINUED if already actively transferred)
                $hasActiveTransfer = FittingItem::where(function ($q) use ($lockedKitir) {
                    $q->where('active_kitir_id', $lockedKitir->id)
                        ->orWhere(function ($q2) use ($lockedKitir) {
                            $q2->where('lost_wax_kitir_id', $lockedKitir->id)
                                ->where('status', FittingItem::STATUS_ACTIVE);
                        });
                })->lockForUpdate()->exists();

                if ($hasActiveTransfer) {
                    throw new InvalidArgumentException("Kitir [{$lockedKitir->traveler_number}] sudah ditransfer ke Fitting Production dan tidak dapat ditandai NOT_CONTINUED.");
                }

                // 4. Lock & verify fitting_kitir_decisions (prevent duplicate active NOT_CONTINUED decision)
                $hasActiveDecision = FittingKitirDecision::where(function ($q) use ($lockedKitir) {
                    $q->where('active_kitir_id', $lockedKitir->id)
                        ->orWhere(function ($q2) use ($lockedKitir) {
                            $q2->where('lost_wax_kitir_id', $lockedKitir->id)
                                ->where('status', FittingKitirDecision::STATUS_ACTIVE);
                        });
                })->lockForUpdate()->exists();

                if ($hasActiveDecision) {
                    throw new InvalidArgumentException("Kitir [{$lockedKitir->traveler_number}] sudah memiliki keputusan aktif NOT_CONTINUED.");
                }

                return FittingKitirDecision::create([
                    'lost_wax_kitir_id' => $lockedKitir->id,
                    'active_kitir_id' => $lockedKitir->id, // DB UNIQUE active decision guard
                    'lost_wax_casting_result_id' => $castingResult->id,
                    'decision' => FittingKitirDecision::DECISION_NOT_CONTINUED,
                    'reason' => $cleanReason,
                    'decided_by' => $user->id,
                    'decided_at' => Carbon::now(),
                    'status' => FittingKitirDecision::STATUS_ACTIVE,
                ]);
            });
        } catch (QueryException $e) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'uk_fit_dec_active_kitir') || str_contains($message, 'fitting_kitir_decisions.active_kitir_id')) {
                throw new InvalidArgumentException("Kitir [{$kitir->traveler_number}] sudah ditandai NOT_CONTINUED oleh transaksi bersamaan.", 0, $e);
            }

            throw $e;
        }
    }

    /**
     * Undo / Reopen an active NOT_CONTINUED decision so the Kitir returns to the active transfer pool.
     * Preserves complete decision history (never deletes the record).
     *
     * Canonical lock hierarchy:
     *   1. lost_wax_casting_results -> 2. lost_wax_kitirs -> 3. fitting_items -> 4. fitting_kitir_decisions
     *
     * @throws InvalidArgumentException
     */
    public function reopenDecision(FittingKitirDecision $decision, User $user, ?string $reopenReason = null): FittingKitirDecision
    {
        $this->authService->authorizeTransferKitir($user);

        $cleanReopenReason = $reopenReason !== null && trim($reopenReason) !== '' ? trim($reopenReason) : null;

        return DB::transaction(function () use ($decision, $user, $cleanReopenReason) {
            // 1. Lock parent LostWaxCastingResult FIRST
            $castingResult = LostWaxCastingResult::where('id', $decision->lost_wax_casting_result_id)
                ->lockForUpdate()
                ->first();

            // 2. Lock child LostWaxKitir SECOND
            $lockedKitir = LostWaxKitir::where('id', $decision->lost_wax_kitir_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $castingResult && $lockedKitir->lost_wax_casting_result_id) {
                $castingResult = LostWaxCastingResult::where('id', $lockedKitir->lost_wax_casting_result_id)
                    ->lockForUpdate()
                    ->first();
            }

            // 3. Lock & verify fitting_items THIRD (ensure Kitir was not transferred concurrently)
            $hasActiveTransfer = FittingItem::where(function ($q) use ($lockedKitir) {
                $q->where('active_kitir_id', $lockedKitir->id)
                    ->orWhere(function ($q2) use ($lockedKitir) {
                        $q2->where('lost_wax_kitir_id', $lockedKitir->id)
                            ->where('status', FittingItem::STATUS_ACTIVE);
                    });
            })->lockForUpdate()->exists();

            // 4. Lock FittingKitirDecision FOURTH
            $lockedDecision = FittingKitirDecision::where('id', $decision->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedDecision->status !== FittingKitirDecision::STATUS_ACTIVE) {
                throw new InvalidArgumentException("Keputusan untuk Kitir [{$lockedKitir->traveler_number}] sudah dikembalikan ke daftar transfer sebelumnya.");
            }

            if ($hasActiveTransfer) {
                throw new InvalidArgumentException("Kitir [{$lockedKitir->traveler_number}] sudah berstatus ditransfer sehingga keputusan tidak dapat di-undo.");
            }

            if (! $castingResult || $castingResult->status !== 'COMPLETED') {
                throw new InvalidArgumentException("Kitir [{$lockedKitir->traveler_number}] tidak dapat dikembalikan ke daftar transfer karena Heat sumber sudah dibatalkan (CANCELLED).");
            }

            $lockedDecision->update([
                'status' => FittingKitirDecision::STATUS_REOPENED,
                'active_kitir_id' => null,
                'reopened_by' => $user->id,
                'reopened_at' => Carbon::now(),
                'reopen_reason' => $cleanReopenReason,
            ]);

            return $lockedDecision->fresh(['lostWaxKitir', 'decidedBy', 'reopenedBy']);
        });
    }
}
