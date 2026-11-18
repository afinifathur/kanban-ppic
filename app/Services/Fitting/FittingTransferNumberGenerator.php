<?php

namespace App\Services\Fitting;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FittingTransferNumberGenerator
{
    public const PREFIX = 'PFTRF';

    public const MAX_RETRIES = 3;

    /**
     * Generate the next sequential Fitting Transfer Number for a given date.
     * Format: PFTRF-YYYYMMDD-XXXX (e.g., PFTRF-20261118-0001).
     *
     * IMPORTANT: MUST be called inside an active DB::transaction() so that
     * `lockForUpdate()` holds its InnoDB lock until the INSERT commits.
     */
    public static function generateNext(?string $date = null): string
    {
        $parsedDate = $date ? Carbon::parse($date) : Carbon::today();
        $dateSegment = $parsedDate->format('Ymd');
        $prefix = self::PREFIX.'-'.$dateSegment.'-';

        $latest = DB::table('fitting_transfers')
            ->where('transfer_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('transfer_number')
            ->value('transfer_number');

        $nextSeq = 1;
        if ($latest) {
            $parts = explode('-', $latest);
            $lastNum = (int) end($parts);
            $nextSeq = $lastNum + 1;
        }

        return sprintf('%s%04d', $prefix, $nextSeq);
    }

    /**
     * Execute a transactional callback with automatic retry when a concurrent transaction
     * collides on the transfer_number unique constraint (`uk_fit_transfers_number`).
     *
     * Guarantees DB::transaction() is started BEFORE any lockForUpdate() / generateNext()
     * inside $callback, keeping locks active until INSERT commits.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function runInTransactionWithRetry(callable $callback, int $maxRetries = self::MAX_RETRIES)
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return DB::transaction(fn () => $callback());
            } catch (QueryException $e) {
                if (! self::isTransferNumberCollision($e) || $attempt >= $maxRetries) {
                    if (self::isTransferNumberCollision($e)) {
                        throw new RuntimeException(
                            "Gagal membuat nomor Transfer Fitting unik setelah {$maxRetries} percobaan karena transaksi bersamaan. Silakan coba lagi.",
                            0,
                            $e
                        );
                    }

                    throw $e;
                }
            }
        }
    }

    /**
     * Execute a creation callback inside DB::transaction() with automatic retry on
     * `uk_fit_transfers_number` collision. Generates the sequential number INSIDE
     * the open transaction so `lockForUpdate()` remains held until INSERT commits.
     *
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    public static function executeWithRetry(callable $callback, ?string $date = null, int $maxRetries = self::MAX_RETRIES)
    {
        return self::runInTransactionWithRetry(function () use ($callback, $date) {
            $transferNumber = self::generateNext($date);

            return $callback($transferNumber);
        }, $maxRetries);
    }

    /**
     * Identify whether a QueryException is specifically a unique constraint collision
     * on `fitting_transfers.transfer_number`.
     */
    public static function isTransferNumberCollision(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $message = strtolower($e->getMessage());

        $isDuplicateError = $sqlState === '23000'
            || $sqlState === '23505'
            || $driverCode === 1062
            || $driverCode === 19
            || str_contains($message, 'duplicate entry')
            || str_contains($message, 'unique constraint failed');

        if (! $isDuplicateError) {
            return false;
        }

        return str_contains($message, 'uk_fit_transfers_number')
            || str_contains($message, 'transfer_number');
    }
}
