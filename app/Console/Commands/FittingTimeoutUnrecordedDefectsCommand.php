<?php

namespace App\Console\Commands;

use App\Models\FittingItem;
use App\Models\FittingStageExecution;
use App\Services\Fitting\FittingDefectService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class FittingTimeoutUnrecordedDefectsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fitting:timeout-unrecorded-defects
                            {--dry-run : Preview matching executions without mutating database}
                            {--since= : Optional activation cut-off date (YYYY-MM-DD) for physical_done_at}
                            {--days= : Override timeout duration in calendar days}
                            {--id= : Process a specific stage execution ID only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically apply 0 PCS defect (Auto-Nihil) for Fitting Production stage executions exceeding 5 calendar days timeout';

    public function __construct(
        protected FittingDefectService $defectService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $timeoutDays = (int) ($this->option('days') ?: config('fitting.auto_nihil.timeout_days', 5));
        if ($timeoutDays <= 0) {
            $timeoutDays = 5;
        }

        $activationDateInput = $this->option('since') ?: config('fitting.auto_nihil.activation_date');
        $activationDate = null;
        if (! empty($activationDateInput)) {
            try {
                $activationDate = Carbon::parse($activationDateInput)->startOfDay();
            } catch (Throwable) {
                $this->error("Format tanggal aktivasi (--since) tidak valid: '{$activationDateInput}'. Gunakan format YYYY-MM-DD.");

                return self::FAILURE;
            }
        }

        $now = now();
        $cutoffThreshold = $now->copy()->subDays($timeoutDays);
        $targetId = $this->option('id') ? (int) $this->option('id') : null;

        $this->info('========================================================================');
        $this->info(' FITTING PRODUCTION: DEFECT ZERO TIMEOUT SCHEDULER (AUTO-NIHIL)         ');
        $this->info('========================================================================');
        $this->info("Waktu Saat Ini       : {$now->format('Y-m-d H:i:s')} (Asia/Jakarta)");
        $this->info("Batas Waktu Timeout  : {$timeoutDays} Hari Kalender (<= {$cutoffThreshold->format('Y-m-d H:i:s')})");
        $this->info('Tanggal Aktivasi     : '.($activationDate ? $activationDate->format('Y-m-d') : 'Semua (Tanpa batas bawah)'));
        if ($targetId) {
            $this->info("Target Spesifik ID   : #{$targetId}");
        }
        $this->info('Mode Eksekusi        : '.($isDryRun ? 'DRY-RUN (Simulasi Saja)' : 'LIVE EXECUTION'));
        $this->info('------------------------------------------------------------------------');

        $query = FittingStageExecution::query()
            ->with(['fittingItem.stageExecutions', 'operator'])
            ->where('status', FittingStageExecution::STATUS_WAITING_DEFECT)
            ->whereNull('auto_nihil_at')
            ->whereNotNull('physical_done_at')
            ->where('physical_done_at', '<=', $cutoffThreshold)
            ->whereHas('fittingItem', fn ($q) => $q->where('status', FittingItem::STATUS_ACTIVE))
            ->orderBy('physical_done_at', 'asc')
            ->orderBy('id', 'asc');

        if ($targetId) {
            $query->where('id', $targetId);
        }

        if ($activationDate !== null) {
            $query->where('physical_done_at', '>=', $activationDate);
        }

        $candidates = $query->get();

        // Separate candidates skipped by Final Quality Lock (stages 1..5 after Gudang Jadi PF)
        $eligible = collect();
        $skippedLocked = collect();

        foreach ($candidates as $cand) {
            if ($this->defectService->isStageQualityLocked($cand, $cand->fittingItem)) {
                $skippedLocked->push($cand);
            } else {
                $eligible->push($cand);
            }
        }

        $backlogCount = 0;
        if ($activationDate !== null) {
            $backlogCount = FittingStageExecution::where('status', FittingStageExecution::STATUS_WAITING_DEFECT)
                ->whereNotNull('physical_done_at')
                ->where('physical_done_at', '<', $activationDate)
                ->whereHas('fittingItem', fn ($q) => $q->where('status', FittingItem::STATUS_ACTIVE))
                ->count();
        }

        if ($eligible->isEmpty()) {
            $this->info('Tidak ditemukan eksekusi Fitting WAITING_DEFECT yang memenuhi syarat Auto-Nihil.');
            if ($skippedLocked->isNotEmpty()) {
                $this->comment("Info: {$skippedLocked->count()} eksekusi dilewati karena sudah TERKUNCI oleh Final Quality Lock (Gudang Jadi PF selesai).");
            }
            if ($backlogCount > 0) {
                $this->comment("Info: Terdapat {$backlogCount} PFKTR historis sebelum tanggal aktivasi {$activationDate->format('Y-m-d')} yang dilindungi.");
            }

            return self::SUCCESS;
        }

        $this->info("Ditemukan {$eligible->count()} eksekusi yang memenuhi syarat Auto-Nihil.");

        $tableRows = [];
        foreach ($eligible as $cand) {
            $diffDays = $cand->physical_done_at ? max(0, (int) $cand->physical_done_at->diffInDays($now)) : 0;
            $traveler = $cand->fittingItem?->traveler_number ?? 'PFKTR-'.$cand->fitting_item_id;

            $tableRows[] = [
                'ID' => $cand->id,
                'PFKTR' => $traveler,
                'Stage' => strtoupper($cand->stage),
                'Checkpoint' => $cand->checkpoint_code,
                'Input (pcs)' => $cand->input_qty,
                'Selesai Fisik' => $cand->physical_done_at?->format('Y-m-d H:i:s'),
                'Aging (hari)' => "{$diffDays} hari",
            ];
        }

        $this->table(['ID', 'PFKTR', 'Stage', 'Checkpoint', 'Input (pcs)', 'Selesai Fisik', 'Aging (hari)'], $tableRows);

        if ($isDryRun) {
            $this->warn('Mode DRY-RUN aktif: Tidak ada perubahan data yang disimpan ke database.');

            return self::SUCCESS;
        }

        $processedCount = 0;
        $failedCount = 0;

        foreach ($eligible as $candidate) {
            try {
                $this->defectService->timeoutToAutoNihil(
                    executionOrId: $candidate->id,
                    systemActorId: null,
                    notes: "Nihil otomatis sistem ({$timeoutDays} hari kalender timeout)",
                    timeoutDays: $timeoutDays
                );

                $processedCount++;
                $this->line("  [OK] Eksekusi ID #{$candidate->id} ({$candidate->checkpoint_code}) berhasil ditetapkan NO_DEFECT / AUTO-NIHIL (0 PCS).");
            } catch (Throwable $e) {
                $failedCount++;
                $this->error("  [FAIL] Eksekusi ID #{$candidate->id}: {$e->getMessage()}");
                Log::error("Fitting Auto-Nihil failed on execution #{$candidate->id}: {$e->getMessage()}");
            }
        }

        $this->info('========================================================================');
        $this->info("HASIL: Berhasil = {$processedCount} | Dilewati (Quality Lock) = {$skippedLocked->count()} | Gagal = {$failedCount} | Total Eligible = {$eligible->count()}");
        if ($backlogCount > 0) {
            $this->comment("Info: {$backlogCount} PFKTR historis sebelum {$activationDate->format('Y-m-d')} tetap dipertahankan untuk review manual.");
        }
        $this->info('========================================================================');

        return $failedCount === 0 ? self::SUCCESS : self::FAILURE;
    }
}
