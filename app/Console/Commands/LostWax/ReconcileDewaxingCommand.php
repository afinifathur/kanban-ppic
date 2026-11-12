<?php

namespace App\Console\Commands\LostWax;

use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileDewaxingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lost-wax:reconcile-dewaxing
                            {--dry-run : Simulasi rekonsiliasi tanpa mengubah data database}
                            {--cutoff=2026-10-12 23:59:59 : Batas akhir tanggal/waktu scan masuk oven (WIB)}
                            {--user-id=3 : ID User Admin pencatat rekonsiliasi}
                            {--chunk=500 : Ukuran batch transaksi pemrosesan data}
                            {--force : Konfirmasi eksekusi langsung ke database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rekonsiliasi historis pohon lost wax yang telah selesai dewaxing berdasarkan cutoff waktu';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $cutoff = (string) $this->option('cutoff');
        $userId = (int) $this->option('user-id');
        $chunkSize = max(10, (int) $this->option('chunk'));
        $isDryRun = (bool) $this->option('dry-run');
        $isForce = (bool) $this->option('force');

        // Jika force tidak diberikan, otomatis jalankan dalam dry-run mode demi keamanan
        $liveMode = $isForce && ! $isDryRun;

        $this->info('========================================================================');
        $this->info(' LOST WAX: REKONSILIASI HISTORIS PROSES DEWAXING (CUTOFF RECONCILIATION)');
        $this->info('========================================================================');
        $this->info('Status Mode : '.($liveMode ? '🔴 LIVE EXECUTION (MODIFIKASI DATABASE)' : '🟡 DRY-RUN (SIMULASI, TIDAK ADA PERUBAHAN)'));
        $this->info("Cutoff      : {$cutoff} WIB");
        $this->info("Batch Chunk : {$chunkSize} pohon per transaksi");

        // Validasi User Actor
        $user = User::find($userId);
        if (! $user) {
            $this->error("User ID {$userId} tidak ditemukan di database!");

            return self::FAILURE;
        }
        $this->info("Pencatat    : ID {$user->id} — {$user->name} ({$user->email})");
        $this->newLine();

        // 1. Populasi Total di Stage Oven saat ini
        $totalOvenCurrent = LostWaxTree::where('current_stage', 'oven')->count();
        $this->line("Total Pohon di Stage Oven saat ini: <comment>{$totalOvenCurrent}</comment> pohon");

        // 2. Query Kandidat Rekonsiliasi (scanned_at <= cutoff)
        $eligibleQuery = LostWaxTree::where('current_stage', 'oven')
            ->where('status', '!=', 'cancelled')
            ->whereHas('scanEvents', function ($q) use ($cutoff) {
                $q->where('stage', 'oven')
                    ->where('result', 'success')
                    ->whereDoesntHave('void')
                    ->where('scanned_at', '<=', $cutoff);
            })
            ->whereDoesntHave('scanEvents', function ($q) {
                $q->where('stage', 'siap_cor')
                    ->where('result', 'success')
                    ->whereDoesntHave('void');
            });

        $eligibleCount = (clone $eligibleQuery)->count();

        // 3. Query Tetap Dimonitor (scanned_at > cutoff)
        $remainingQuery = LostWaxTree::where('current_stage', 'oven')
            ->where('status', '!=', 'cancelled')
            ->whereHas('scanEvents', function ($q) use ($cutoff) {
                $q->where('stage', 'oven')
                    ->where('result', 'success')
                    ->whereDoesntHave('void')
                    ->where('scanned_at', '>', $cutoff);
            })
            ->whereDoesntHave('scanEvents', function ($q) {
                $q->where('stage', 'siap_cor')
                    ->where('result', 'success')
                    ->whereDoesntHave('void');
            });

        $remainingCount = (clone $remainingQuery)->count();

        // 4. Hitung Quantity PCS untuk Cohort Kandidat
        $grossPcs = (int) (clone $eligibleQuery)->sum('quantity');
        $defectPcs = (int) DB::table('lost_wax_tree_defects')
            ->whereIn('lost_wax_tree_id', (clone $eligibleQuery)->select('id'))
            ->sum('defect_qty');
        $netPcs = $grossPcs - $defectPcs;

        // Rentang Tanggal Scan Masuk Oven
        $earliestScan = LostWaxScanEvent::whereIn('tree_id', (clone $eligibleQuery)->select('id'))
            ->where('stage', 'oven')
            ->where('result', 'success')
            ->whereDoesntHave('void')
            ->min('scanned_at');

        $latestScan = LostWaxScanEvent::whereIn('tree_id', (clone $eligibleQuery)->select('id'))
            ->where('stage', 'oven')
            ->where('result', 'success')
            ->whereDoesntHave('void')
            ->where('scanned_at', '<=', $cutoff)
            ->max('scanned_at');

        $this->table(
            ['Metrik Analisis', 'Nilai'],
            [
                ['Kandidat Rekonsiliasi (<= Cutoff)', number_format($eligibleCount).' pohon'],
                ['Total Gross PCS', number_format($grossPcs).' PCS'],
                ['Total Defect PCS', number_format($defectPcs).' PCS'],
                ['Total Net Layak PCS', number_format($netPcs).' PCS'],
                ['Scan Oven Terlama', $earliestScan ?? '-'],
                ['Scan Oven Terbaru (Cohort)', $latestScan ?? '-'],
                ['Pohon Tetap di Monitoring (> Cutoff)', number_format($remainingCount).' pohon'],
            ]
        );

        if ($eligibleCount === 0) {
            $this->info('Tidak ada pohon kandidat yang memenuhi kriteria rekonsiliasi. Selesai (Idempotent).');

            return self::SUCCESS;
        }

        // Tampilkan peringatan jika dalam Dry-run
        if (! $liveMode) {
            $this->warn('HASIL SIMULASI (DRY-RUN):');
            $this->line("- {$eligibleCount} pohon siap direkonsiliasi ke stage 'siap_cor'.");
            $this->line("- {$netPcs} PCS net layak akan berpindah ke Persediaan Pasca Dewaxing.");
            $this->line('- Tidak ada data yang diubah di database.');
            $this->comment('Untuk mengeksekusi perubahan nyata, jalankan:');
            $this->line("  php artisan lost-wax:reconcile-dewaxing --cutoff=\"{$cutoff}\" --user-id={$userId} --force");

            return self::SUCCESS;
        }

        // --- LIVE EXECUTION ---
        $this->warn("MEMULAI EKSEKUSI REKONSILIASI NYATA UNTUK {$eligibleCount} POHON...");
        $startTime = microtime(true);
        $executionTime = Carbon::now('Asia/Jakarta');
        $notes = 'Rekonsiliasi historis dewaxing berdasarkan cutoff 12 Oktober 2026.';

        $allCandidateIds = (clone $eligibleQuery)->pluck('id')->toArray();
        $totalCandidates = count($allCandidateIds);
        $chunks = array_chunk($allCandidateIds, $chunkSize);

        $reconciledCount = 0;
        $skippedCount = 0;
        $progressBar = $this->output->createProgressBar($totalCandidates);
        $progressBar->start();

        foreach ($chunks as $chunkIndex => $chunkIds) {
            DB::transaction(function () use (
                $chunkIds,
                $userId,
                $executionTime,
                $notes,
                &$reconciledCount,
                &$skippedCount,
                $progressBar
            ) {
                // Lock rows inside transaction
                $trees = LostWaxTree::whereIn('id', $chunkIds)
                    ->lockForUpdate()
                    ->get();

                foreach ($trees as $tree) {
                    // Double check guard: tree must still be in 'oven' and have no active siap_cor
                    $hasActiveSiapCor = LostWaxScanEvent::where('tree_id', $tree->id)
                        ->where('stage', 'siap_cor')
                        ->where('result', 'success')
                        ->whereDoesntHave('void')
                        ->exists();

                    if ($tree->current_stage !== 'oven' || $hasActiveSiapCor || $tree->status === 'cancelled') {
                        $skippedCount++;
                        $progressBar->advance();

                        continue;
                    }

                    // 1. Create Scan Event Record
                    LostWaxScanEvent::create([
                        'tree_id' => $tree->id,
                        'barcode' => $tree->barcode,
                        'stage' => 'siap_cor',
                        'scanned_at' => $executionTime,
                        'operator_id' => $userId,
                        'result' => 'success',
                        'source' => LostWaxScanEvent::SOURCE_HISTORICAL_RECONCILIATION,
                        'notes' => $notes,
                        'aging_minutes' => null,
                        'aging_status' => null,
                        'created_at' => $executionTime,
                        'updated_at' => $executionTime,
                    ]);

                    // 2. Update Tree Stage
                    $tree->update([
                        'current_stage' => 'siap_cor',
                        'last_scan_at' => $executionTime,
                        'updated_at' => $executionTime,
                    ]);

                    $reconciledCount++;
                    $progressBar->advance();
                }
            });
        }

        $progressBar->finish();
        $this->newLine(2);

        $elapsedSeconds = round(microtime(true) - $startTime, 2);

        $this->info('========================================================================');
        $this->info(' REKONSILIASI HISTORIS SELESAI DENGAN SUKSES');
        $this->info('========================================================================');
        $this->line("Waktu Eksekusi         : {$elapsedSeconds} detik");
        $this->line("Pohon Berhasil Diubah  : <info>{$reconciledCount}</info> pohon");
        $this->line("Pohon Dilewati (Skip)  : <comment>{$skippedCount}</comment> pohon");
        $this->line('Net PCS Berpindah      : <info>'.number_format($netPcs).'</info> PCS');
        $this->line("Timestamp Tercatat     : {$executionTime->format('Y-m-d H:i:s')} WIB");
        $this->line("Actor User ID          : {$userId} ({$user->name})");

        return self::SUCCESS;
    }
}
