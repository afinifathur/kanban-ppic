<?php

namespace App\Services;

use App\Models\LostWaxCastingResultTree;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxScanEventVoid;
use App\Models\LostWaxTree;
use App\Models\LostWaxTreeDefect;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ScanVoidService
{
    /**
     * Void the given scan event.
     *
     * @throws \InvalidArgumentException
     */
    public function void(LostWaxScanEvent $event, string $reason, int $voidedByUserId): LostWaxScanEventVoid
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Alasan pembatalan (void) tidak boleh kosong.');
        }

        return DB::transaction(function () use ($event, $reason, $voidedByUserId) {
            // Lock the tree and event row
            $tree = LostWaxTree::lockForUpdate()->find($event->tree_id);
            if (! $tree) {
                throw new \InvalidArgumentException('Pohon (tree) tidak ditemukan.');
            }

            // Acquire lock on the event to prevent concurrent voids
            $lockedEvent = LostWaxScanEvent::lockForUpdate()->find($event->id);
            if (! $lockedEvent) {
                throw new \InvalidArgumentException('Event scan tidak ditemukan.');
            }

            // Verify if already voided
            if ($lockedEvent->void()->exists()) {
                throw new \InvalidArgumentException('Event scan ini sudah dibatalkan (void) sebelumnya.');
            }

            // Guard: Block void if Tree is already cast / linked to a non-CANCELLED Casting Result
            $hasNonCancelledCasting = $tree->current_stage === 'cor'
                || LostWaxCastingResultTree::where('active_tree_id', $tree->id)->exists()
                || LostWaxCastingResultTree::where('lost_wax_tree_id', $tree->id)
                    ->whereHas('castingResult', fn ($q) => $q->where('status', '!=', 'CANCELLED'))
                    ->exists();

            if ($hasNonCancelledCasting) {
                throw new \InvalidArgumentException('Event scan tidak dapat dibatalkan karena Tree sudah masuk transaksi Hasil Cor (Casting Result).');
            }

            // Guard: Block voiding a siap_cor event while active post-Siap Cor defects are linked to it
            if ($lockedEvent->stage === 'siap_cor') {
                $hasLinkedPostDefects = LostWaxTreeDefect::where('lost_wax_tree_id', $tree->id)
                    ->whereNull('voided_at')
                    ->where(function ($q) use ($lockedEvent) {
                        $q->where('scan_event_id', $lockedEvent->id)
                            ->orWhere('defect_context', LostWaxTreeDefect::CONTEXT_POST_SIAP_COR);
                    })
                    ->exists();

                if ($hasLinkedPostDefects) {
                    throw new \InvalidArgumentException('Event Scan Siap Cor tidak dapat dibatalkan karena masih memiliki defect Pasca-Siap Cor aktif. Lakukan void defect terkait terlebih dahulu.');
                }
            }

            // Determine if the event is the latest successful non-voided event of the tree
            $latestEvent = LostWaxScanEvent::where('tree_id', $tree->id)
                ->where('result', 'success')
                ->whereDoesntHave('void')
                ->orderBy('scanned_at', 'desc')
                ->orderBy('id', 'desc')
                ->first();

            if (! $latestEvent || $latestEvent->id !== $lockedEvent->id) {
                throw new \InvalidArgumentException('Hanya scan event sukses terakhir yang boleh dibatalkan (void).');
            }

            // Create the void record
            $void = LostWaxScanEventVoid::create([
                'scan_event_id' => $lockedEvent->id,
                'voided_by' => $voidedByUserId,
                'voided_at' => Carbon::now(config('app.timezone')),
                'void_reason' => $reason,
            ]);

            // Reconstruct active state
            $newLatestEvent = LostWaxScanEvent::where('tree_id', $tree->id)
                ->where('result', 'success')
                ->whereDoesntHave('void')
                ->orderBy('scanned_at', 'desc')
                ->orderBy('id', 'desc')
                ->first();

            if ($newLatestEvent) {
                $tree->update([
                    'current_stage' => $newLatestEvent->stage,
                    'last_scan_at' => $newLatestEvent->scanned_at,
                ]);
            } else {
                $tree->update([
                    'current_stage' => null,
                    'last_scan_at' => null,
                ]);
            }

            return $void;
        });
    }
}
