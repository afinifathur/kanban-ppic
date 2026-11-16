<?php

namespace App\Services\LostWax;

use App\Models\LostWaxKitir;
use Illuminate\Support\Facades\DB;

class LostWaxTravelerNumberGenerator
{
    /**
     * Generate sequential, concurrency-safe Traveler Number for Fitting Kitir.
     * Format: PFKTR-YYYYMMDD-XXXX
     *
     * @param string $date Date in 'Y-m-d' or 'Ymd' format
     */
    public static function generateNext(string $date): string
    {
        return DB::transaction(function () use ($date) {
            $dateStr = str_replace('-', '', substr($date, 0, 10));

            // Lock existing kitir records for that date pattern to prevent race conditions
            $lastKitir = LostWaxKitir::where('traveler_number', 'like', 'PFKTR-'.$dateStr.'-%')
                ->lockForUpdate()
                ->orderBy('id', 'desc')
                ->first();

            $sequence = 1;
            if ($lastKitir && $lastKitir->traveler_number) {
                $parts = explode('-', $lastKitir->traveler_number);
                if (count($parts) === 3) {
                    $sequence = ((int) $parts[2]) + 1;
                }
            }

            $candidate = 'PFKTR-'.$dateStr.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            while (LostWaxKitir::where('traveler_number', $candidate)->exists()) {
                $sequence++;
                $candidate = 'PFKTR-'.$dateStr.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            }

            return $candidate;
        });
    }
}

