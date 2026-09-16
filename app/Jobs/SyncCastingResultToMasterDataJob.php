<?php

namespace App\Jobs;

use App\Services\Integration\MasterDataHeatNumberPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncCastingResultToMasterDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $castingResultId;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     * Attempt 1 -> 60s (1 min), Attempt 2 -> 300s (5 mins), Attempt 3 -> 900s (15 mins)
     */
    public array $backoff = [60, 300, 900];

    /**
     * Create a new job instance.
     */
    public function __construct(int $castingResultId)
    {
        $this->castingResultId = $castingResultId;
    }

    /**
     * Execute the job.
     */
    public function handle(MasterDataHeatNumberPublisher $publisher): void
    {
        Log::info('[SyncHeatNumber:JOB_START] Starting sync for Casting Result ID: '.$this->castingResultId, [
            'casting_result_id' => $this->castingResultId,
            'attempt' => $this->attempts(),
        ]);

        try {
            $publisher->publishCastingResultById($this->castingResultId);
        } catch (Throwable $e) {
            Log::warning('[SyncHeatNumber:JOB_RETRY] Sync job encountered error, will retry if attempts remain', [
                'casting_result_id' => $this->castingResultId,
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure after all retries are exhausted.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('[SyncHeatNumber:JOB_FAILED_FINAL] Sync job permanently failed after max retries. Manual reconciliation required via php artisan kanban:sync-heat-numbers', [
            'casting_result_id' => $this->castingResultId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
