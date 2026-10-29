<?php

namespace App\Console\Commands;

use App\Models\SandCastingWipSnapshot;
use App\Services\SandCasting\SandCastingWipSnapshotService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CaptureSandCastingWipSnapshotCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sand-casting:capture-wip-snapshot
                            {--date= : Tanggal snapshot operasional (format YYYY-MM-DD, default hari ini)}
                            {--scope= : Filter scope produk tertentu (default: all)}
                            {--force : Paksa timpa snapshot aktual yang sudah tersimpan}
                            {--reconstruct : Jalankan mode rekonstruksi posisi antrean historis}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ambil snapshot posisi antrean WIP harian Sand Casting pada cutoff 07.00 WIB secara permanen.';

    /**
     * Execute the console command.
     */
    public function handle(SandCastingWipSnapshotService $snapshotService): int
    {
        $timezone = SandCastingWipSnapshotService::TIMEZONE;
        $now = Carbon::now($timezone);

        $dateOpt = $this->option('date');
        $targetDate = $dateOpt ? Carbon::parse($dateOpt, $timezone)->toDateString() : $now->toDateString();
        $scopeOpt = $this->option('scope') ?: 'all';
        $force = (bool) $this->option('force');
        $isReconstruct = (bool) $this->option('reconstruct');

        $this->info('=== Pengambilan WIP Snapshot Sand Casting ===');
        $this->line("Waktu Sistem           : {$now->format('Y-m-d H:i:s')} {$timezone}");
        $this->line("Tanggal Snapshot       : {$targetDate}");
        $this->line("Scheduled Cutoff       : 07:00:00 {$timezone}");
        $this->line("Product Scope          : {$scopeOpt}");
        $this->line('Mode                   : '.($isReconstruct ? 'REKONSTRUKSI HISTORIS' : 'SNAPSHOT HARIAN AKTUAL'));
        $this->line('Force Overwrite        : '.($force ? 'YA' : 'TIDAK'));

        if ($isReconstruct) {
            $this->info('Menjalankan rekonstruksi historis berbasis log pengerjaan fisik...');
            $snapshots = $snapshotService->reconstructHistoricalSnapshot($targetDate, $scopeOpt, $force);
        } else {
            $sourceType = SandCastingWipSnapshot::SOURCE_ACTUAL;
            $snapshots = $snapshotService->captureSnapshot($targetDate, $scopeOpt, $force, $sourceType);
        }

        $rows = [];
        foreach ($snapshots as $snap) {
            $rows[] = [
                $snap->stage,
                $snap->snapshot_date->toDateString(),
                $snap->product_scope,
                number_format($snap->qty_pcs),
                number_format($snap->weight_kg, 2).' kg',
                number_format($snap->weight_ton, 2).' ton',
                number_format($snap->ktr_count).' KTR',
                $snap->source_type,
                $snap->status,
            ];
        }

        $this->table(
            ['Tahap', 'Tanggal', 'Scope', 'PCS', 'Berat (KG)', 'Tonase', 'KTR', 'Sumber', 'Status'],
            $rows
        );

        $this->info('Penyimpanan snapshot selesai: '.count($snapshots).' tahap tercatat.');

        return Command::SUCCESS;
    }
}
