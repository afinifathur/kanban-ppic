<?php

namespace App\Services\Barcode;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PrintJobService
{
    /**
     * Create a new TSC print job.
     */
    public function createTscJob(string $payload, string $printerName, string $templateType, int $copies = 1): \App\Models\PrintJob
    {
        return \App\Models\PrintJob::create([
            'printer_name' => $printerName,
            'payload_tspl' => $payload,
            'payload_hash' => hash('sha256', $payload),
            'copies' => $copies,
            'status' => 'pending',
            'template_type' => $templateType,
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * Atomic claim for next pending print job.
     * Restricts to jobs from the last 10 minutes to prevent printing stale tasks.
     */
    public function claimNextJob(string $machineId, string $printerName): ?\App\Models\PrintJob
    {
        $tenMinutesAgo = now()->subMinutes(10);

        return DB::transaction(function () use ($machineId, $printerName, $tenMinutesAgo) {
            $job = \App\Models\PrintJob::where('status', 'pending')
                ->where('printer_name', $printerName)
                ->where('created_at', '>=', $tenMinutesAgo)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->first();

            if ($job) {
                $job->update([
                    'status' => 'processing',
                    'claimed_by_machine' => $machineId,
                    'claimed_at' => now(),
                ]);
            }

            return $job;
        });
    }

    /**
     * Resets status of jobs stuck in 'processing' for more than 5 minutes back to 'pending'.
     */
    public function recoverStaleJobs(): int
    {
        return \App\Models\PrintJob::where('status', 'processing')
            ->where('claimed_at', '<', now()->subMinutes(5))
            ->update([
                'status' => 'pending',
                'claimed_by_machine' => null,
                'claimed_at' => null,
            ]);
    }

    public function markPrinted(int $id): bool
    {
        return \App\Models\PrintJob::where('id', $id)->update([
            'status' => 'printed',
            'printed_at' => now(),
        ]);
    }

    public function markFailed(int $id, string $error): bool
    {
        return \App\Models\PrintJob::where('id', $id)->update([
            'status' => 'failed',
            'failed_at' => now(),
            'error_message' => $error,
        ]);
    }
}
