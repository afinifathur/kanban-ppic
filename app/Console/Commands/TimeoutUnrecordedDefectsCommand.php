<?php

namespace App\Console\Commands;

use App\Models\SandCastingStageExecution;
use App\Services\SandCasting\SandCastingStageExecutionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class TimeoutUnrecordedDefectsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sand-casting:timeout-unrecorded-defects
                            {--dry-run : Preview matching executions without mutating database}
                            {--since= : Optional activation cut-off date (YYYY-MM-DD) for physical_done_at}
                            {--days= : Override timeout duration in calendar days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically apply 0 PCS defect (Auto-Nihil) for Sand Casting stage executions exceeding 5 calendar days timeout';

    public function __construct(
        protected SandCastingStageExecutionService $executionService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $timeoutDays = (int) ($this->option('days') ?: config('sand_casting.auto_nihil.timeout_days', 5));
        if ($timeoutDays <= 0) {
            $timeoutDays = 5;
        }

        $activationDateInput = $this->option('since') ?: config('sand_casting.auto_nihil.activation_date');
        $activationDate = null;
        if (! empty($activationDateInput)) {
            try {
                $activationDate = Carbon::parse($activationDateInput)->startOfDay();
            } catch (Throwable $e) {
                $this->error("Format tanggal aktivasi (--since) tidak valid: '{$activationDateInput}'. Gunakan format YYYY-MM-DD.");

                return self::FAILURE;
            }
        }

        $now = now();
        $cutoffThreshold = $now->copy()->subDays($timeoutDays);

        $this->info('========================================================================');
        $this->info(' SAND CASTING: DEFECT ZERO TIMEOUT SCHEDULER (AUTO-NIHIL)               ');
        $this->info('========================================================================');
        $this->info("Waktu Saat Ini       : {$now->format('Y-m-d H:i:s')} (Asia/Jakarta)");
        $this->info("Batas Waktu Timeout  : {$timeoutDays} Hari Kalender (<= {$cutoffThreshold->format('Y-m-d H:i:s')})");
        $this->info('Tanggal Aktivasi     : '.($activationDate ? $activationDate->format('Y-m-d') : 'Semua (Tanpa batas bawah)'));
        $this->info('Mode Eksekusi        : '.($isDryRun ? 'DRY-RUN (Simulasi Saja)' : 'LIVE EXECUTION'));
        $this->info('------------------------------------------------------------------------');

        // Step 1: Query candidate executions
        $query = SandCastingStageExecution::query()
            ->with(['castingResultLine.castingResult', 'castingResultLine.productionPlan', 'operator'])
            ->where('status', SandCastingStageExecution::STATUS_WAITING_DEFECT)
            ->whereNotNull('physical_done_at')
            ->where('physical_done_at', '<=', $cutoffThreshold)
            ->where('is_auto_nihil', false)
            ->orderBy('physical_done_at', 'asc')
            ->orderBy('id', 'asc');

        if ($activationDate !== null) {
            $query->where('physical_done_at', '>=', $activationDate);
        }

        $candidates = $query->get();
        $candidateCount = $candidates->count();

        // Also check backlog count before activation date for audit transparency
        $backlogCount = 0;
        if ($activationDate !== null) {
            $backlogCount = SandCastingStageExecution::where('status', SandCastingStageExecution::STATUS_WAITING_DEFECT)
                ->whereNotNull('physical_done_at')
                ->where('physical_done_at', '<', $activationDate)
                ->count();
        }

        if ($candidateCount === 0) {
            $this->info('Tidak ditemukan eksekusi WAITING_DEFECT yang memenuhi syarat timeout.');
            if ($backlogCount > 0) {
                $this->comment("Info: Terdapat {$backlogCount} KTR historis sebelum tanggal aktivasi {$activationDate->format('Y-m-d')} yang dilindungi dan memerlukan review manual.");
            }

            return self::SUCCESS;
        }

        $this->info("Ditemukan {$candidateCount} eksekusi yang memenuhi syarat timeout.");

        $tableRows = [];
        foreach ($candidates as $cand) {
            $diffDays = $cand->physical_done_at ? max(0, (int) $cand->physical_done_at->diffInDays($now)) : 0;
            $traveler = $cand->castingResultLine?->traveler_number ?? 'KTR-'.$cand->sand_casting_casting_result_line_id;

            $tableRows[] = [
                'ID' => $cand->id,
                'KTR' => $traveler,
                'Stage' => strtoupper($cand->stage),
                'Checkpoint' => $cand->checkpoint_code,
                'Input (pcs)' => $cand->input_qty,
                'Selesai Fisik' => $cand->physical_done_at?->format('Y-m-d H:i:s'),
                'Aging (hari)' => "{$diffDays} hari",
            ];
        }

        $this->table(['ID', 'KTR', 'Stage', 'Checkpoint', 'Input (pcs)', 'Selesai Fisik', 'Aging (hari)'], $tableRows);

        if ($isDryRun) {
            $this->warn('Mode DRY-RUN aktif: Tidak ada perubahan data yang disimpan ke database.');

            return self::SUCCESS;
        }

        // Step 2: Execute Auto-Nihil in Transaction with Concurrency Lock
        $processedCount = 0;
        $failedCount = 0;

        foreach ($candidates as $candidate) {
            try {
                $this->executionService->timeoutToAutoNihil(
                    executionOrId: $candidate->id,
                    systemActorId: null,
                    notes: "Nihil otomatis sistem ({$timeoutDays} hari kalender timeout)"
                );

                $processedCount++;
                $this->line("  [OK] Eksekusi ID #{$candidate->id} ({$candidate->checkpoint_code}) berhasil ditetapkan NIHIL OTOMATIS (0 PCS).");
            } catch (Throwable $e) {
                $failedCount++;
                $this->error("  [FAIL] Eksekusi ID #{$candidate->id}: {$e->getMessage()}");
                Log::error("Sand Casting Auto-Nihil failed on execution #{$candidate->id}: {$e->getMessage()}");
            }
        }

        $this->info('========================================================================');
        $this->info("HASIL: Berhasil = {$processedCount} | Gagal = {$failedCount} | Total Kandidat = {$candidateCount}");
        if ($backlogCount > 0) {
            $this->comment("Info: {$backlogCount} KTR historis sebelum {$activationDate->format('Y-m-d')} tetap dipertahankan untuk review manual.");
        }
        $this->info('========================================================================');

        return $failedCount === 0 ? self::SUCCESS : self::FAILURE;
    }
}
