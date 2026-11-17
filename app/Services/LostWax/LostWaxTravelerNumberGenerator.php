<?php

namespace App\Services\LostWax;

use App\Models\LostWaxKitir;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class LostWaxTravelerNumberGenerator
{
    /**
     * Generate sequential, concurrency-safe Traveler Number for Fitting Kitir.
     * Format: PFKTR-YYYYMMDD-XXXX
     *
     * @param  string  $date  Date in 'Y-m-d' or 'Ymd' format
     */
    public static function generateNext(string $date): string
    {
        return DB::transaction(function () use ($date) {
            $dateStr = str_replace('-', '', substr($date, 0, 10));

            // Lock existing kitir records for that date pattern to prevent race conditions
            $lastKitir = LostWaxKitir::where('traveler_number', 'like', 'PFKTR-'.$dateStr.'-%')
                ->lockForUpdate()
                ->orderByRaw('LENGTH(traveler_number) DESC')
                ->orderBy('traveler_number', 'desc')
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

    /**
     * Determine if an exception represents a unique constraint collision on traveler_number.
     * Detects SQLSTATE 23000 / MySQL error 1062 on uk_lw_kitirs_traveler / traveler_number,
     * as well as SQLite UNIQUE constraint violations for traveler_number.
     */
    public static function isTravelerCollision(Throwable $e): bool
    {
        if (! ($e instanceof QueryException)) {
            return false;
        }

        $message = $e->getMessage();
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        // MySQL duplicate entry: SQLSTATE 23000, error 1062, matching key uk_lw_kitirs_traveler or traveler_number
        $isMysqlDuplicate = ($sqlState === '23000' || $driverCode === 1062)
            && (str_contains($message, 'uk_lw_kitirs_traveler') || str_contains($message, 'traveler_number'));

        // SQLite duplicate entry (used in automated test environment): code 19 or 23000
        $isSqliteDuplicate = (str_contains($message, 'UNIQUE constraint failed') || str_contains($message, 'Integrity constraint violation'))
            && str_contains($message, 'lost_wax_kitirs.traveler_number');

        return $isMysqlDuplicate || $isSqliteDuplicate;
    }

    /**
     * Execute an operation that issues a Kitir with traveler_number with up to $maxAttempts retries
     * specifically catching duplicate key collisions on uk_lw_kitirs_traveler.
     *
     * @template T
     *
     * @param  callable(string $travelerNumber): T  $operation
     * @return T
     *
     * @throws RuntimeException
     * @throws Throwable
     */
    public static function executeWithRetry(callable $operation, string $date, int $maxAttempts = 3)
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $travelerNumber = self::generateNext($date);

                return $operation($travelerNumber);
            } catch (Throwable $e) {
                if (self::isTravelerCollision($e) && $attempt < $maxAttempts) {
                    $lastException = $e;

                    continue;
                }

                if (self::isTravelerCollision($e)) {
                    throw new RuntimeException(
                        "Gagal menerbitkan nomor Kitir Produksi (PFKTR) setelah {$maxAttempts} kali percobaan karena benturan sequence nomor traveler (uk_lw_kitirs_traveler). Silakan coba lagi.",
                        0,
                        $e
                    );
                }

                // Other errors (foreign key, network, syntax, validation) must not be retried
                throw $e;
            }
        }

        throw new RuntimeException(
            "Gagal menerbitkan nomor Kitir Produksi (PFKTR) setelah {$maxAttempts} kali percobaan karena benturan sequence nomor traveler (uk_lw_kitirs_traveler).",
            0,
            $lastException
        );
    }
}
