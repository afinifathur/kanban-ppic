<?php

namespace App\Console\Commands;

use App\Models\SandCastingCastingResult;
use App\Services\Integration\MasterDataHeatNumberPublisher;
use Illuminate\Console\Command;
use Throwable;

class SyncHeatNumbersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanban:sync-heat-numbers
                            {--date= : Filter by cast_date (YYYY-MM-DD)}
                            {--heat= : Filter by specific heat_number}
                            {--limit=100 : Maximum records to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize Sand Casting Heat Numbers from Kanban PPIC to Master Data KPI (md_heat_numbers)';

    /**
     * Execute the console command.
     */
    public function handle(MasterDataHeatNumberPublisher $publisher): int
    {
        $date = $this->option('date');
        $heat = $this->option('heat');
        $limit = (int) $this->option('limit');

        $this->info('Starting Sand Casting Heat Number synchronization to Master Data KPI...');

        $query = SandCastingCastingResult::with([
            'lines.productionPlan',
            'lines.castingOrderLine.productionPlan',
        ])->orderBy('id', 'desc');

        if ($date) {
            $query->whereDate('cast_date', $date);
            $this->line("Filtering by cast_date: <comment>{$date}</comment>");
        }

        if ($heat) {
            $query->where('heat_number', trim($heat));
            $this->line("Filtering by heat_number: <comment>{$heat}</comment>");
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $results = $query->get();

        if ($results->isEmpty()) {
            $this->warn('No matching Sand Casting Casting Result records found.');

            return 0;
        }

        $this->info("Found {$results->count()} casting result(s) to synchronize.");

        $tableRows = [];
        $totalSynced = 0;
        $totalFailed = 0;

        $progressBar = $this->output->createProgressBar($results->count());
        $progressBar->start();

        foreach ($results as $result) {
            try {
                $syncResult = $publisher->publishCastingResult($result);

                if ($syncResult['success']) {
                    $totalSynced += $syncResult['synced_count'];
                    foreach ($syncResult['payloads'] as $p) {
                        $tableRows[] = [
                            $result->heat_number,
                            $p['item_code'],
                            $p['cor_qty'],
                            $p['kode_produksi'] ?? '-',
                            '<info>SYNCED</info>',
                            '-',
                        ];
                    }
                } else {
                    $totalFailed++;
                    $tableRows[] = [
                        $result->heat_number,
                        '-',
                        '-',
                        '-',
                        '<error>FAILED</error>',
                        implode(', ', $syncResult['errors']),
                    ];
                }
            } catch (Throwable $e) {
                $totalFailed++;
                $tableRows[] = [
                    $result->heat_number,
                    '-',
                    '-',
                    '-',
                    '<error>ERROR</error>',
                    $e->getMessage(),
                ];
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->table(
            ['Heat Number', 'Item Code', 'Cor Qty', 'Kode Produksi', 'Status', 'Message'],
            $tableRows
        );

        $this->info("Synchronization Complete! Synced items: {$totalSynced} | Failed heats: {$totalFailed}");

        return $totalFailed > 0 ? 1 : 0;
    }
}
