<?php

namespace App\Console\Commands;

use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileCncDebtCommand extends Command
{
    /**
     * Exact audited whitelist of 16 historical CNC debt KTRs.
     */
    public const WHITELIST_KTRS = [
        'KTR-20260915-0009',
        'KTR-20260916-0016',
        'KTR-20260916-0017',
        'KTR-20260916-0018',
        'KTR-20260917-0005',
        'KTR-20260917-0029',
        'KTR-20260917-0030',
        'KTR-20260917-0046',
        'KTR-20260918-0011',
        'KTR-20260921-0019',
        'KTR-20260921-0021',
        'KTR-20260923-0007',
        'KTR-20260921-0023',
        'KTR-20260924-0013',
        'KTR-20260924-0014',
        'KTR-20260926-0013',
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sand-casting:reconcile-cnc-debt
                            {--dry-run : Perform dry-run preview without mutating database}
                            {--execute : Execute reconciliation and update current_stage from bubut_cnc to bor}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile historical Sand Casting CNC debt (completed CNC_MACHINING) from bubut_cnc to bor';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isExecute = (bool) $this->option('execute');
        $modeLabel = $isExecute ? 'EXECUTE' : 'DRY RUN';

        $this->info('===============================================');
        $this->info('SAND CASTING - CNC HISTORICAL DEBT RECONCILIATION');
        $this->info("MODE: {$modeLabel}");
        $this->info('===============================================');

        // 1. Fetch all lines currently at bubut_cnc stage
        $cncLines = SandCastingCastingResultLine::where('current_stage', 'bubut_cnc')
            ->with([
                'castingResult',
                'productionPlan',
                'castingOrderLine',
                'stageExecutions' => function ($q) {
                    $q->orderBy('id', 'asc');
                },
            ])
            ->orderBy('id', 'asc')
            ->get();

        $safeCandidates = collect();
        $unexpectedCandidates = collect();
        $freshUnprocessed = collect();
        $invalidCandidates = collect();

        foreach ($cncLines as $line) {
            $cncExecutions = $line->stageExecutions->where('stage', 'bubut_cnc');
            $cncMachiningExec = $cncExecutions->firstWhere('checkpoint_code', 'CNC_MACHINING');

            // Line has not performed CNC execution yet (fresh queue item)
            if ($cncExecutions->isEmpty() || ! $cncMachiningExec) {
                $freshUnprocessed->push($line);

                continue;
            }

            // Must have physical_done_at populated
            if (! $cncMachiningExec->physical_done_at) {
                $invalidCandidates->push([
                    'line' => $line,
                    'reason' => 'CNC_MACHINING physical_done_at is NULL',
                ]);

                continue;
            }

            $isWhitelisted = in_array($line->traveler_number, self::WHITELIST_KTRS, true);

            $lastCncExec = $cncExecutions->last();
            $candidateData = [
                'line' => $line,
                'traveler_number' => $line->traveler_number,
                'production_code' => $line->productionPlan?->code ?? $line->castingOrderLine?->code ?? '-',
                'heat_number' => $line->castingResult?->heat_number ?? '-',
                'qty' => $line->qty_good,
                'current_stage' => $line->current_stage,
                'cnc_exec_id' => $cncMachiningExec->id,
                'cnc_phys_done_at' => $cncMachiningExec->physical_done_at?->format('Y-m-d H:i:s'),
                'last_checkpoint' => $lastCncExec?->checkpoint_code,
                'last_status' => $lastCncExec?->status,
                'is_whitelisted' => $isWhitelisted,
            ];

            if ($isWhitelisted) {
                $safeCandidates->push($candidateData);
            } else {
                $unexpectedCandidates->push($candidateData);
            }
        }

        $totalCandidates = $safeCandidates->count() + $unexpectedCandidates->count();
        $expectedCount = count(self::WHITELIST_KTRS);

        $this->line('');
        $this->line("Candidate:  {$totalCandidates}");
        $this->line("Expected:   {$expectedCount}");
        $this->line("Safe:       {$safeCandidates->count()}");
        $this->line("Unexpected: {$unexpectedCandidates->count()}");
        $this->line('');

        // Prepare table rows for display
        $tableRows = [];
        foreach ($safeCandidates as $c) {
            $tableRows[] = [
                $c['traveler_number'],
                $c['production_code'],
                $c['heat_number'],
                $c['qty'],
                $c['current_stage'],
                $c['cnc_exec_id'],
                $c['cnc_phys_done_at'],
                $c['last_checkpoint'],
                $isExecute ? 'UPDATING → bor' : 'WOULD UPDATE → bor',
            ];
        }

        foreach ($unexpectedCandidates as $c) {
            $tableRows[] = [
                $c['traveler_number'],
                $c['production_code'],
                $c['heat_number'],
                $c['qty'],
                $c['current_stage'],
                $c['cnc_exec_id'],
                $c['cnc_phys_done_at'],
                $c['last_checkpoint'],
                'SKIPPED (UNEXPECTED)',
            ];
        }

        if (! empty($tableRows)) {
            $this->table(
                [
                    'KTR',
                    'Production Code',
                    'Heat',
                    'Qty',
                    'Current Stage',
                    'CNC Execution ID',
                    'CNC Physical Done At',
                    'Last Historical Checkpoint',
                    'Action',
                ],
                $tableRows
            );
        }

        if ($unexpectedCandidates->isNotEmpty()) {
            $this->warn("PERINGATAN: Ditemukan {$unexpectedCandidates->count()} kandidat di luar whitelist 16 KTR. Kandidat tersebut TIDAK akan diproses.");
        }

        // 2. DRY RUN Mode: Stop here safely
        if (! $isExecute) {
            $this->info("\n[DRY RUN SELESAI] Tidak ada perubahan database yang dilakukan.");
            $this->info("Gunakan flag '--execute' untuk menerapkan pembaruan.");

            return Command::SUCCESS;
        }

        // 3. EXECUTE Mode: Atomic transactional reconciliation
        if ($safeCandidates->isEmpty()) {
            $this->warn('Tidak ada kandidat aman untuk diproses.');

            return Command::SUCCESS;
        }

        $this->info("\nMemulai proses rekonsiliasi atomik untuk {$safeCandidates->count()} KTR aman...");

        $initialExecutionsCount = SandCastingStageExecution::count();
        $updatedCount = 0;
        $failedCount = 0;
        $auditLogs = [];

        try {
            DB::transaction(function () use ($safeCandidates, &$updatedCount, &$auditLogs) {
                foreach ($safeCandidates as $c) {
                    $line = SandCastingCastingResultLine::where('id', $c['line']->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $line) {
                        throw new \RuntimeException("Line ID {$c['line']->id} ({$c['traveler_number']}) tidak ditemukan saat locking.");
                    }

                    if ($line->current_stage !== 'bubut_cnc') {
                        throw new \RuntimeException("Line {$line->traveler_number} gagal validasi: current_stage saat ini '{$line->current_stage}', bukan 'bubut_cnc'.");
                    }

                    $cncMachiningExec = $line->stageExecutions()
                        ->where('stage', 'bubut_cnc')
                        ->where('checkpoint_code', 'CNC_MACHINING')
                        ->whereNotNull('physical_done_at')
                        ->first();

                    if (! $cncMachiningExec) {
                        throw new \RuntimeException("Line {$line->traveler_number} gagal validasi: riwayat CNC_MACHINING physical done tidak valid.");
                    }

                    // Mutate ONLY current_stage to bor
                    $oldStage = $line->current_stage;
                    $line->current_stage = 'bor';
                    $line->queue_position = null;
                    $line->save();

                    $updatedCount++;

                    $logEntry = sprintf(
                        '[RECONCILE] %s | KTR: %s | Line ID: %d | old: %s -> new: bor | Reason: historical CNC debt reconciliation | CNC Exec ID: %d | CNC PhysDone: %s',
                        now()->toDateTimeString(),
                        $line->traveler_number,
                        $line->id,
                        $oldStage,
                        $cncMachiningExec->id,
                        $cncMachiningExec->physical_done_at->toDateTimeString()
                    );

                    $auditLogs[] = $logEntry;
                    Log::info($logEntry);
                }
            });
        } catch (Throwable $e) {
            $this->error("\nTRANSACTION ROLLBACK: Terjadi kesalahan saat eksekusi: ".$e->getMessage());

            return Command::FAILURE;
        }

        // Post-execution safety verification
        $postExecutionsCount = SandCastingStageExecution::count();
        if ($postExecutionsCount !== $initialExecutionsCount) {
            $this->error("CRITICAL ERROR: Jumlah baris stage_executions berubah (Awal: {$initialExecutionsCount}, Akhir: {$postExecutionsCount})!");

            return Command::FAILURE;
        }

        $this->line('');
        $this->info('===============================================');
        $this->info('HASIL REKONSILIASI SELESAI');
        $this->info('===============================================');
        $this->line("Updated:    {$updatedCount}");
        $this->line('Skipped:    0');
        $this->line("Unexpected: {$unexpectedCandidates->count()}");
        $this->line("Failed:     {$failedCount}");
        $this->line('');

        $this->info('AUDIT LOG ENTRIES:');
        foreach ($auditLogs as $log) {
            $this->line("  {$log}");
        }

        return Command::SUCCESS;
    }
}
