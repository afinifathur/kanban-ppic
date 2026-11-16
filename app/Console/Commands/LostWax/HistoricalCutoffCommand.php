<?php

namespace App\Console\Commands\LostWax;

use App\Models\LostWaxTree;
use App\Models\User;
use App\Services\LostWax\HistoricalCutoffService;
use Illuminate\Console\Command;

class HistoricalCutoffCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lost-wax:historical-cutoff
                            {--preview : Tampilkan ringkasan dan daftar kandidat barcode yang memenuhi kriteria cut-off}
                            {--export= : Ekspor seluruh daftar kandidat ke file CSV (wajib bersama --preview, contoh: --export=storage/kandidat.csv)}
                            {--barcodes= : Daftar barcode spesifik dipisahkan koma (contoh: 12808280001,12808280002)}
                            {--file= : Path file teks berisi daftar barcode (satu barcode per baris atau dipisahkan koma)}
                            {--dry-run : Simulasi validasi dan pemrosesan tanpa mengubah database}
                            {--reason= : Alasan administratif dilakukannya Historical Cut-off (wajib untuk live execution)}
                            {--notes= : Catatan tambahan opsional}
                            {--user-id= : ID User pelaksana eksekusi (opsional, default: admin)}
                            {--flow=all : Filter kandidat pada preview/ekspor (all, legacy, new_flow)}
                            {--limit=50 : Batas jumlah baris yang ditampilkan pada tabel preview (default 50)}
                            {--force : Lewati konfirmasi interaktif saat live eksekusi}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manajemen administratif Historical Cut-off Lost Wax (preview, dry-run, dan eksekusi barcode terpilih)';

    public function __construct(
        protected HistoricalCutoffService $cutoffService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('========================================================================');
        $this->info(' LOST WAX: HISTORICAL CUT-OFF MANAGEMENT (ARTISAN COMMAND ONLY)');
        $this->info('========================================================================');

        $isPreview = (bool) $this->option('preview');
        $barcodesOption = $this->option('barcodes');
        $fileOption = $this->option('file');
        $isDryRun = (bool) $this->option('dry-run');
        $exportOption = $this->option('export');

        // Safeguard: --export must only be used with --preview (strictly read-only)
        if ($exportOption !== null && ! $isPreview) {
            $this->error('Opsi --export hanya dapat digunakan bersama opsi --preview (ekspor daftar kandidat bersifat read-only).');
            $this->line('Contoh: php artisan lost-wax:historical-cutoff --preview --export="storage/kandidat.csv"');

            return self::FAILURE;
        }

        if ($exportOption !== null && (! empty($barcodesOption) || ! empty($fileOption))) {
            $this->error('Opsi --export tidak dapat digabungkan dengan --barcodes atau --file (pencegahan benturan eksekusi live).');

            return self::FAILURE;
        }

        // Mode 1: Preview Candidates
        if ($isPreview) {
            return $this->handlePreview();
        }

        // Safeguard: Execution or dry-run requires explicit barcodes or file
        if (empty($barcodesOption) && empty($fileOption)) {
            $this->line('Tentukan opsi operasi:');
            $this->line('  1. Gunakan --preview untuk melihat kandidat barcode yang memenuhi kriteria cut-off.');
            $this->line('  2. Gunakan --barcodes=... atau --file=... bersama --dry-run untuk mensimulasikan pemrosesan barcode terpilih.');
            $this->line('  3. Gunakan --barcodes=... atau --file=... bersama --reason=... untuk mengeksekusi cut-off secara administratif.');
            $this->newLine();
            $this->line('[ATURAN KEAMANAN]: Eksekusi otomatis terhadap seluruh kandidat DILARANG.');
            $this->line('Hanya barcode yang diaudit dan dipilih secara eksplisit yang dapat diproses.');

            return self::FAILURE;
        }

        // Parse Barcodes
        $barcodes = $this->resolveBarcodes($barcodesOption, $fileOption);
        if (empty($barcodes)) {
            $this->error('Daftar barcode yang diberikan kosong atau tidak valid.');

            return self::FAILURE;
        }

        $this->info(sprintf('Daftar barcode input: %d barcode unik.', count($barcodes)));

        // Validate Barcodes
        $validation = $this->validateBarcodes($barcodes);
        $eligibleTrees = $validation['eligible'];
        $ineligible = $validation['ineligible'];

        $this->newLine();
        $this->info(sprintf('Hasil Validasi: %d valid / memenuhi syarat, %d tidak valid / ditolak.', count($eligibleTrees), count($ineligible)));

        // Show Ineligible Barcodes
        if (! empty($ineligible)) {
            $this->newLine();
            $this->warn('Daftar Barcode Tidak Valid / Ditolak:');
            $ineligibleRows = array_map(function ($item) {
                return [
                    'barcode' => $item['barcode'],
                    'reason' => $item['reason'],
                ];
            }, $ineligible);

            $this->table(['Barcode', 'Alasan Penolakan'], $ineligibleRows);
        }

        // Show Eligible Summary
        $totalGross = $eligibleTrees->sum('quantity');
        $totalDefect = $eligibleTrees->sum('total_defect_quantity');
        $totalUsable = $eligibleTrees->sum('usable_quantity');

        $this->newLine();
        $this->info('Ringkasan Kuantitas Barcode Valid:');
        $this->line(sprintf('  - Total Pohon (Trees)       : %d', $eligibleTrees->count()));
        $this->line(sprintf('  - Total Gross Quantity     : %d PCS', $totalGross));
        $this->line(sprintf('  - Total Defect Quantity    : %d PCS', $totalDefect));
        $this->line(sprintf('  - Total Net Good (Usable)  : %d PCS', $totalUsable));

        // Mode 2: Dry Run
        if ($isDryRun) {
            return $this->handleDryRun($eligibleTrees);
        }

        // Mode 3: Live Execution
        return $this->handleLiveExecution($eligibleTrees, $ineligible);
    }

    /**
     * Preview candidates based on agreed criteria.
     */
    protected function handlePreview(): int
    {
        $flow = (string) $this->option('flow');
        $limit = max(1, (int) $this->option('limit'));

        $this->info('Memindai kandidat Historical Cut-off...');
        $this->line('Kriteria: Memiliki valid Scan Oven dengan umur > 30 hari (tanpa Cor aktif & tanpa anomali)');

        $filters = [];
        if (in_array($flow, ['legacy', 'new_flow'], true)) {
            $filters['source_type'] = $flow;
        }

        $candidates = $this->cutoffService->getCandidates($filters);

        $totalCandidates = $candidates->count();
        $legacyCount = $candidates->where('flow_type', 'legacy')->count();
        $newFlowCount = $candidates->where('flow_type', 'new_flow')->count();
        $totalNetGood = $candidates->sum('usable_quantity');

        $this->newLine();
        $this->info('RINGKASAN KANDIDAT:');
        $this->line("  - Total Barcode Kandidat    : {$totalCandidates}");
        $this->line("  - Alur Legacy Work Order    : {$legacyCount}");
        $this->line("  - Alur New Flow (Plan)      : {$newFlowCount}");
        $this->line("  - Total Net Good (Usable)   : {$totalNetGood} PCS");

        // Breakdown by Stage
        $stageBreakdown = $candidates->groupBy('current_stage')->map->count();
        $this->newLine();
        $this->info('Breakdown Berdasarkan Tahapan Terakhir:');
        foreach ($stageBreakdown as $stage => $count) {
            $this->line(sprintf('  - %-15s : %d barcode', $stage, $count));
        }

        // Breakdown by Criteria
        $oven31to60 = $candidates->filter(fn ($c) => $c['oven_age_days'] > 30 && $c['oven_age_days'] <= 60)->count();
        $oven61to90 = $candidates->filter(fn ($c) => $c['oven_age_days'] > 60 && $c['oven_age_days'] <= 90)->count();
        $ovenOver90 = $candidates->filter(fn ($c) => $c['oven_age_days'] > 90)->count();

        $this->newLine();
        $this->info('Breakdown Berdasarkan Kriteria (Scan Oven > 30 hari):');
        $this->line("  - Umur Scan Oven 31 - 60 hari : {$oven31to60} barcode");
        $this->line("  - Umur Scan Oven 61 - 90 hari : {$oven61to90} barcode");
        $this->line("  - Umur Scan Oven > 90 hari    : {$ovenOver90} barcode");

        // Informative Barcode Age breakdown
        $barcodeAgeOver50 = $candidates->where('barcode_age_days', '>', 50)->count();
        $barcodeAgeUnderEq50 = $candidates->where('barcode_age_days', '<=', 50)->count();
        $this->line("  - Info: Umur Barcode > 50 hr  : {$barcodeAgeOver50} barcode | <= 50 hr: {$barcodeAgeUnderEq50} barcode");

        // Table Sample
        if ($totalCandidates > 0) {
            $this->newLine();
            $this->info(sprintf('Tabel Kandidat (Menampilkan hingga %d baris pertama):', $limit));

            $tableRows = $candidates->take($limit)->map(function ($c) {
                return [
                    'barcode' => $c['barcode'],
                    'tree_no' => $c['tree_number'],
                    'flow' => $c['flow_type'],
                    'code' => $c['production_code'],
                    'item' => mb_strimwidth($c['item_name'], 0, 22, '...'),
                    'stage' => $c['current_stage'],
                    'net_qty' => $c['usable_quantity'],
                    'barcode_age' => $c['barcode_age_days'].' hr',
                    'oven_age' => $c['oven_age_days'] !== null ? $c['oven_age_days'].' hr' : '-',
                    'reason' => $c['candidate_reason'],
                ];
            })->toArray();

            $this->table(
                ['Barcode', 'Tree #', 'Flow', 'Kode', 'Item', 'Stage', 'Net Qty', 'Umur BC', 'Umur Oven', 'Kriteria'],
                $tableRows
            );

            if ($totalCandidates > $limit) {
                $this->line(sprintf('... dan %d barcode kandidat lainnya. Gunakan --limit=%d untuk melihat lebih banyak.', $totalCandidates - $limit, $totalCandidates));
            }
        }

        // Export to CSV if requested
        $exportPath = $this->option('export');
        if (! empty($exportPath)) {
            $exportSuccess = $this->exportCandidatesToCsv($candidates, $exportPath);
            if (! $exportSuccess) {
                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->comment('CATATAN: Tidak ada eksekusi otomatis.');
        $this->comment('Pilih daftar barcode yang sudah diverifikasi dan jalankan via:');
        $this->line('  php artisan lost-wax:historical-cutoff --barcodes=BC1,BC2 --dry-run');
        $this->line('  php artisan lost-wax:historical-cutoff --barcodes=BC1,BC2 --reason="Pembersihan data Oven/Siap Cor lama"');

        return self::SUCCESS;
    }

    /**
     * Export all candidates to a CSV file (read-only).
     *
     * @param  \Illuminate\Support\Collection  $candidates
     */
    protected function exportCandidatesToCsv($candidates, string $filePath): bool
    {
        $this->newLine();
        $this->info("Mengekspor daftar kandidat ke file CSV: {$filePath}...");

        $dir = dirname($filePath);
        if ($dir !== '' && $dir !== '.' && ! is_dir($dir)) {
            if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                $this->error("Gagal membuat direktori ekspor: {$dir}");

                return false;
            }
        }

        $handle = @fopen($filePath, 'w');
        if ($handle === false) {
            $this->error("Gagal membuka file untuk penulisan: {$filePath}");

            return false;
        }

        // UTF-8 BOM for Microsoft Excel compatibility
        fwrite($handle, "\xEF\xBB\xBF");

        // Exactly 17 headers
        $headers = [
            'Barcode',
            'Tree ID',
            'Tree Number',
            'Flow',
            'Production Code',
            'Item Code',
            'Item Name',
            'Production Plan ID / Work Order ID',
            'Current Stage',
            'Current Status',
            'Gross Quantity',
            'Defect Quantity',
            'Net Good Quantity',
            'Barcode Age (Hari)',
            'Last Valid Oven Scan Date',
            'Oven Scan Age (Hari)',
            'Candidate Reason',
        ];
        fputcsv($handle, $headers);

        foreach ($candidates as $c) {
            $row = [
                $c['barcode'],
                $c['tree_id'],
                $c['tree_number'],
                $c['flow_type'] === 'legacy' ? 'Legacy' : 'New Flow',
                $c['production_code'],
                $c['item_code'] ?? '-',
                $c['item_name'] ?? '-',
                $c['plan_or_wo_id'] ?? '-',
                $c['current_stage'],
                $c['current_status'] ?? 'generated',
                (int) $c['quantity'],
                (int) $c['defect_quantity'],
                (int) $c['usable_quantity'],
                (int) $c['barcode_age_days'],
                $c['last_oven_scan_date'] ?? '-',
                $c['oven_age_days'] !== null ? $c['oven_age_days'] : '-',
                $c['candidate_reason'],
            ];
            fputcsv($handle, $row);
        }

        fclose($handle);

        $totalCount = $candidates->count();
        $totalUsable = $candidates->sum('usable_quantity');

        $this->newLine();
        $this->info('========================================================================');
        $this->info(' EKSPOR CSV BERHASIL');
        $this->info('========================================================================');
        $this->line("  - Lokasi File        : {$filePath}");
        $this->line("  - Total Baris Data   : {$totalCount} kandidat barcode");
        $this->line("  - Total Net Good     : {$totalUsable} PCS");
        $this->line('  - Total Kolom        : 17 kolom (UTF-8 BOM Excel Compatible)');
        $this->info('========================================================================');

        return true;
    }

    /**
     * Handle Dry Run simulation.
     */
    protected function handleDryRun($eligibleTrees): int
    {
        $this->newLine();
        $this->warn('========================================================================');
        $this->warn(' STATUS MODE: DRY-RUN (SIMULASI VALIDASI, TIDAK ADA MODIFIKASI DB)');
        $this->warn('========================================================================');

        if ($eligibleTrees->isEmpty()) {
            $this->error('Tidak ada barcode valid yang dapat disimulasikan.');

            return self::FAILURE;
        }

        $rows = [];
        foreach ($eligibleTrees as $tree) {
            $milestones = $this->cutoffService->calculateMilestoneDates($tree);
            $rows[] = [
                'barcode' => $tree->barcode,
                'tree_no' => $tree->tree_number,
                'source' => $tree->work_order_id ? 'legacy' : 'new_flow',
                'current_stage' => $tree->current_stage ?: 'sebelum_scan',
                'gross_qty' => (int) $tree->quantity,
                'defect_qty' => (int) $tree->total_defect_quantity,
                'usable_qty' => (int) $tree->usable_quantity,
                'siap_cor_milestone' => $milestones['siap_cor_date'] ?? '-',
                'cor_milestone' => $milestones['cor_date'] ?? '-',
            ];
        }

        $this->table(
            ['Barcode', 'Tree #', 'Flow', 'Current Stage', 'Gross', 'Defect', 'Net Good', 'Siap Cor Milestone', 'Cor Milestone'],
            $rows
        );

        $this->newLine();
        $this->info('SIMULASI SELESAI.');
        $this->info(sprintf('%d barcode siap dieksekusi dengan total %d PCS Net Good.', $eligibleTrees->count(), $eligibleTrees->sum('usable_quantity')));
        $this->line('Untuk mengeksekusi secara permanen, hilangkan opsi --dry-run dan sertakan --reason="..."');

        return self::SUCCESS;
    }

    /**
     * Handle Live Execution.
     */
    protected function handleLiveExecution($eligibleTrees, array $ineligible): int
    {
        if ($eligibleTrees->isEmpty()) {
            $this->error('Tidak ada barcode valid yang memenuhi syarat untuk dieksekusi.');

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $reason = (string) $this->ask('Masukkan alasan administratif Historical Cut-off');
            $reason = trim($reason);

            if ($reason === '') {
                $this->error('Alasan Historical Cut-off wajib diisi untuk eksekusi live!');

                return self::FAILURE;
            }
        }

        $notes = $this->option('notes');

        // Resolve Executing User
        $userId = $this->resolveUserId();
        if (! $userId) {
            $this->error('User pelaksana tidak ditemukan.');

            return self::FAILURE;
        }

        $user = User::find($userId);

        $eligibleCount = $eligibleTrees->count();
        $totalUsable = $eligibleTrees->sum('usable_quantity');

        // Confirmation Prompt
        $isForce = (bool) $this->option('force');
        if (! $isForce) {
            $this->newLine();
            $this->warn("PERINGATAN: Anda akan mengeksekusi Historical Cut-off untuk {$eligibleCount} barcode ({$totalUsable} PCS Net Good).");
            $this->warn('Status barcode akan diubah menjadi historical_cutoff dan dihapus dari WIP aktif.');
            $confirmed = $this->confirm('Apakah Anda yakin ingin melanjutkan eksekusi ke database?', false);

            if (! $confirmed) {
                $this->comment('Eksekusi dibatalkan oleh pengguna.');

                return self::SUCCESS;
            }
        }

        $this->info('Mengeksekusi Historical Cut-off di dalam database transaction atomik...');

        try {
            $treeIds = $eligibleTrees->pluck('id')->toArray();
            $result = $this->cutoffService->executeCutoff($treeIds, $userId, $reason, $notes);

            $this->newLine();
            $this->info('========================================================================');
            $this->info(' HISTORICAL CUT-OFF BERHASIL DIEKSEKUSI');
            $this->info('========================================================================');
            $this->line("  - No. Dokumen Cut-off : {$result->cutoff_number}");
            $this->line("  - Tanggal Cut-off     : {$result->cutoff_date}");
            $this->line("  - Total Pohon (Trees) : {$result->total_trees}");
            $this->line("  - Total Gross Qty     : {$result->total_quantity} PCS");
            $this->line("  - Total Defect Qty    : {$result->total_defect_quantity} PCS");
            $this->line("  - Total Net Good Qty  : {$result->total_usable_quantity} PCS");
            $this->line("  - Alasan              : {$result->reason}");
            if ($result->notes) {
                $this->line("  - Catatan             : {$result->notes}");
            }
            $this->line("  - Pelaksana           : {$user->name} ({$user->email})");
            $this->line("  - Waktu Eksekusi      : {$result->executed_at}");
            $this->info('========================================================================');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('Gagal mengeksekusi Historical Cut-off: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Resolve list of barcodes from comma-separated string and/or file.
     *
     * @return array<string>
     */
    protected function resolveBarcodes(?string $barcodesOption, ?string $fileOption): array
    {
        $list = [];

        if (! empty($barcodesOption)) {
            $parts = explode(',', $barcodesOption);
            foreach ($parts as $p) {
                $clean = trim($p);
                if ($clean !== '') {
                    $list[] = $clean;
                }
            }
        }

        if (! empty($fileOption)) {
            $filePath = $fileOption;
            if (! file_exists($filePath)) {
                $this->error("File tidak ditemukan: {$filePath}");

                return [];
            }

            $content = file_get_contents($filePath);
            if ($content !== false) {
                $lines = preg_split('/[\r\n,]+/', $content);
                foreach ($lines as $line) {
                    $clean = trim($line);
                    if ($clean !== '') {
                        $list[] = $clean;
                    }
                }
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * Validate barcodes against database records and business rules.
     *
     * @param  array<string>  $barcodes
     * @return array{eligible: \Illuminate\Support\Collection, ineligible: array}
     */
    protected function validateBarcodes(array $barcodes): array
    {
        $trees = LostWaxTree::with([
            'workOrder.itemReference',
            'printOrderLine.printOrder',
            'printOrderLine.productionPlan',
            'defects',
            'activeCastingResultTree.castingResult',
            'activeHistoricalCutoffTree',
            'scanEvents' => function ($q) {
                $q->whereDoesntHave('void')->orderBy('scanned_at', 'desc');
            },
        ])
            ->whereIn('barcode', $barcodes)
            ->get()
            ->keyBy('barcode');

        $eligible = collect();
        $ineligible = [];

        foreach ($barcodes as $bc) {
            $tree = $trees->get($bc);

            if (! $tree) {
                $ineligible[] = [
                    'barcode' => $bc,
                    'reason' => 'Barcode tidak ditemukan di database.',
                ];

                continue;
            }

            $rawStatus = trim((string) ($tree->status ?? 'generated'));
            $statusLower = strtolower($rawStatus);

            if ($statusLower === 'cancelled') {
                $ineligible[] = [
                    'barcode' => $bc,
                    'reason' => 'Pohon berstatus DIBATALKAN dan tidak dapat diproses.',
                ];

                continue;
            }

            if ($statusLower === 'historical_cutoff' || $tree->activeHistoricalCutoffTree !== null) {
                $ineligible[] = [
                    'barcode' => $bc,
                    'reason' => 'Pohon sudah memiliki Historical Cut-off aktif.',
                ];

                continue;
            }

            $hasActiveCor = $tree->activeCastingResultTree !== null
                && $tree->activeCastingResultTree->castingResult?->status === 'COMPLETED';

            if ($hasActiveCor) {
                $ineligible[] = [
                    'barcode' => $bc,
                    'reason' => 'Pohon sudah memiliki transaksi Cor Verified aktif.',
                ];

                continue;
            }

            $stage = $tree->current_stage ?: 'sebelum_scan';
            $validActiveStatuses = HistoricalCutoffService::VALID_ACTIVE_STATUSES;

            if (! in_array($statusLower, $validActiveStatuses, true) || ! in_array($stage, HistoricalCutoffService::STANDARD_STAGES, true)) {
                $ineligible[] = [
                    'barcode' => $bc,
                    'reason' => "Anomali status/stage ('{$rawStatus}' / '{$stage}').",
                ];

                continue;
            }

            $eligible->push($tree);
        }

        return [
            'eligible' => $eligible,
            'ineligible' => $ineligible,
        ];
    }

    /**
     * Resolve user ID for execution audit.
     */
    protected function resolveUserId(): ?int
    {
        $id = $this->option('user-id');
        if ($id) {
            $user = User::find($id);

            return $user ? $user->id : null;
        }

        // Try admin user or first user
        $admin = User::where('email', 'adminppicpf@peroniks.com')->first();
        if ($admin) {
            return $admin->id;
        }

        $firstUser = User::first();

        return $firstUser?->id;
    }
}
