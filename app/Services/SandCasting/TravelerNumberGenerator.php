<?php

namespace App\Services\SandCasting;

use App\Models\SandCastingCastingResultLine;
use Illuminate\Support\Facades\DB;

class TravelerNumberGenerator
{
    /**
     * Generate sequential, concurrency-safe Traveler Number (Kitir).
     * Format: KTR-YYYYMMDD-XXXX
     */
    public static function generateNext(string $date): string
    {
        return DB::transaction(function () use ($date) {
            $dateStr = str_replace('-', '', $date);

            // Lock existing lines for that date pattern to avoid race conditions
            $lastLine = SandCastingCastingResultLine::where('traveler_number', 'like', 'KTR-'.$dateStr.'-%')
                ->lockForUpdate()
                ->orderBy('id', 'desc')
                ->first();

            $sequence = 1;
            if ($lastLine && $lastLine->traveler_number) {
                $parts = explode('-', $lastLine->traveler_number);
                if (count($parts) === 3) {
                    $sequence = ((int) $parts[2]) + 1;
                }
            }

            $candidate = 'KTR-'.$dateStr.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            while (SandCastingCastingResultLine::where('traveler_number', $candidate)->exists()) {
                $sequence++;
                $candidate = 'KTR-'.$dateStr.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            }

            return $candidate;
        });
    }
}
