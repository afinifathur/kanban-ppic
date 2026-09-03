<?php

namespace App\Console\Commands;

use App\Models\LostWaxPrintExecution;
use App\Models\LostWaxPrintOrderLine;
use App\Services\PrintExecutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizePrintExecutionGrossCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lost-wax:normalize-gross {--dry-run : Preview changes without committing to database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely normalize historical Lost Wax print execution records to Gross -> Defect -> Net Good';

    /**
     * Execute the console command.
     */
    public function handle(PrintExecutionService $service): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $this->info('================================================================');
        $this->info(' LOST WAX: PRINT EXECUTION GROSS -> DEFECT -> NET NORMALIZATION ');
        $this->info('================================================================');
        $this->info($isDryRun ? 'Mode: DRY-RUN (no database changes will be made)' : 'Mode: LIVE EXECUTION');

        // Fetch all un-normalized executions
        $unnormalized = LostWaxPrintExecution::with('printOrderLine.printOrder')
            ->whereNull('qty_gross_output')
            ->orderBy('id')
            ->get();

        $totalCount = $unnormalized->count();
        $this->info("Found {$totalCount} un-normalized execution record(s).");

        if ($totalCount === 0) {
            $this->info('All records are already normalized. Nothing to do.');

            return self::SUCCESS;
        }

        $grossModified = [];
        $netPreserved = [];
        $zeroDefectCount = 0;
        $affectedLineIds = [];

        foreach ($unnormalized as $exec) {
            $line = $exec->printOrderLine;
            $ordered = $line ? (int) $line->qty_ordered : 0;
            $oldGood = (int) $exec->qty_good;
            $defect = (int) $exec->qty_defect;
            $status = $exec->status;

            if ($defect === 0) {
                // Zero defect: Gross = Good, Net = Good (unchanged)
                $zeroDefectCount++;
                $newGross = $oldGood;
                $newGood = $oldGood;
                $classification = 'ZERO_DEFECT';
            } elseif ($ordered > 0 && $oldGood >= $ordered) {
                // Confirmed/probable GROSS input pattern:
                // Operator entered total printed (Gross) in the Good field
                $newGross = $oldGood;
                $newGood = max(0, $oldGood - $defect);
                $classification = 'GROSS_PATTERN';

                $grossModified[] = [
                    'id' => $exec->id,
                    'order' => $line?->printOrder?->print_order_number ?? '-',
                    'code' => $line?->code ?? '-',
                    'ordered' => $ordered,
                    'old_good' => $oldGood,
                    'defect' => $defect,
                    'new_gross' => $newGross,
                    'new_good' => $newGood,
                    'status' => $status,
                ];
            } else {
                // NET input pattern:
                // Operator entered Net Good < ordered with separate defect
                $newGross = $oldGood + $defect;
                $newGood = $oldGood;
                $classification = 'NET_PATTERN';

                $netPreserved[] = [
                    'id' => $exec->id,
                    'order' => $line?->printOrder?->print_order_number ?? '-',
                    'code' => $line?->code ?? '-',
                    'ordered' => $ordered,
                    'old_good' => $oldGood,
                    'defect' => $defect,
                    'new_gross' => $newGross,
                    'new_good' => $newGood,
                    'status' => $status,
                ];
            }

            if ($line) {
                $affectedLineIds[$line->id] = $line->id;
            }
        }

        $this->newLine();
        $this->info('Classification Summary:');
        $this->info("- Zero Defect (Gross=Good, Net=Good): {$zeroDefectCount} record(s)");
        $this->info('- GROSS Pattern (Gross=OldGood, Net=OldGood-Defect): '.count($grossModified).' record(s)');
        $this->info('- NET Pattern (Gross=OldGood+Defect, Net=OldGood): '.count($netPreserved).' record(s)');

        if (count($grossModified) > 0) {
            $this->newLine();
            $this->info('--- Records modified from GROSS to NET GOOD: ---');
            $this->table(
                ['Exec ID', 'Print Order', 'Code', 'Ordered', 'Old Good', 'Defect', 'New Gross', 'New Net Good', 'Status'],
                $grossModified
            );
        }

        if (count($netPreserved) > 0) {
            $this->newLine();
            $this->info('--- Records preserved as NET GOOD (Gross backfilled): ---');
            $this->table(
                ['Exec ID', 'Print Order', 'Code', 'Ordered', 'Old Good', 'Defect', 'New Gross', 'Net Good', 'Status'],
                $netPreserved
            );
        }

        if ($isDryRun) {
            $this->warn('DRY-RUN completed. No changes were committed.');

            return self::SUCCESS;
        }

        // Commit in a safe database transaction
        DB::transaction(function () use ($unnormalized, $service, $affectedLineIds) {
            foreach ($unnormalized as $exec) {
                $line = $exec->printOrderLine;
                $ordered = $line ? (int) $line->qty_ordered : 0;
                $oldGood = (int) $exec->qty_good;
                $defect = (int) $exec->qty_defect;

                if ($defect === 0) {
                    $newGross = $oldGood;
                    $newGood = $oldGood;
                } elseif ($ordered > 0 && $oldGood >= $ordered) {
                    $newGross = $oldGood;
                    $newGood = max(0, $oldGood - $defect);
                } else {
                    $newGross = $oldGood + $defect;
                    $newGood = $oldGood;
                }

                $exec->qty_gross_output = $newGross;
                $exec->qty_good = $newGood;
                $exec->save();
            }

            // Rebuild aggregates for all affected print order lines
            foreach ($affectedLineIds as $lineId) {
                $line = LostWaxPrintOrderLine::find($lineId);
                if ($line) {
                    $service->updateLineAggregates($line);
                }
            }
        });

        $this->newLine();
        $this->info('SUCCESS: Normalization completed and line aggregates successfully refreshed.');

        return self::SUCCESS;
    }
}
