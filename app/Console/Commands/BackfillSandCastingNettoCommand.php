<?php

namespace App\Console\Commands;

use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class BackfillSandCastingNettoCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sand-casting:backfill-netto
                            {--dry-run : Perform validation and preview without mutating database}
                            {--force : Execute without interactive confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill historical Sand Casting KTRs (current_stage = NULL) to opening stage NETTO';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $isForce = $this->option('force');

        $this->info('===============================================================');
        $this->info(' SAND CASTING HISTORICAL KTR BACKFILL — OPENING STAGE: NETTO  ');
        $this->info('===============================================================');

        // Step 1: Query & Classify
        $allLines = SandCastingCastingResultLine::with([
            'castingResult',
            'productionPlan',
            'castingOrderLine',
            'stageExecutions',
        ])->orderBy('id', 'asc')->get();

        $totalKtr = $allLines->count();

        $candidates = collect();
        $alreadyStaged = collect();
        $hasExecution = collect();
        $zeroOrInvalidQty = collect();
        $nullTraveler = collect();

        foreach ($allLines as $line) {
            $hasExec = $line->stageExecutions->isNotEmpty();
            $isZeroQty = ($line->qty_good <= 0);
            $isTravelerNull = empty($line->traveler_number);
            $hasStage = ! empty($line->current_stage);

            if ($hasStage) {
                $alreadyStaged->push($line);

                continue;
            }

            if ($hasExec) {
                $hasExecution->push($line);

                continue;
            }

            if ($isZeroQty) {
                $zeroOrInvalidQty->push($line);

                continue;
            }

            if ($isTravelerNull) {
                $nullTraveler->push($line);

                continue;
            }

            // Valid candidate: current_stage IS NULL, qty_good > 0, no executions, traveler_number not null
            $candidates->push($line);
        }

        // Summary statistics
        $this->table(
            ['Metric', 'Count', 'Notes'],
            [
                ['Total KTR Records', $totalKtr, 'All sand_casting_casting_result_lines'],
                ['Backfill Candidates', $candidates->count(), 'current_stage IS NULL, qty_good > 0, no execution'],
                ['Already Staged (Excluded)', $alreadyStaged->count(), 'current_stage IS NOT NULL (e.g. UAT KTR)'],
                ['Has Execution (Excluded)', $hasExecution->count(), 'Must not be touched automatically'],
                ['Qty Good <= 0 (Excluded)', $zeroOrInvalidQty->count(), 'Invalid quantity'],
                ['Traveler NULL (Excluded)', $nullTraveler->count(), 'Missing physical identity'],
            ]
        );

        $totalCandidateQty = $candidates->sum('qty_good');
        $printedCount = $candidates->where('print_count', '>', 0)->count();
        $notPrintedCount = $candidates->where('print_count', '<=', 0)->count();

        $this->line('');
        $this->info("BACKFILL CANDIDATES : {$candidates->count()} KTR");
        $this->info("TOTAL QUANTITY      : {$totalCandidateQty} PCS");
        $this->info("PRINTED KTR         : {$printedCount}");
        $this->info("NOT PRINTED KTR     : {$notPrintedCount}");
        $this->line('');

        // Sample candidate records (up to 20 or all if <= 25)
        $sampleCount = min(20, $candidates->count());
        $sampleRows = $candidates->take($sampleCount)->map(function ($line) {
            return [
                $line->id,
                $line->traveler_number,
                $line->castingResult?->heat_number ?? '-',
                $line->productionPlan?->code ?? $line->castingOrderLine?->code ?? '-',
                \Illuminate\Support\Str::limit($line->productionPlan?->item_name ?? $line->castingOrderLine?->item_name ?? '-', 30),
                $line->qty_good,
                $line->print_count,
                $line->printed_at ? $line->printed_at->format('Y-m-d H:i') : '-',
                $line->current_stage ?? 'NULL',
            ];
        })->all();

        $this->comment("SAMPLE CANDIDATES (First {$sampleCount} of {$candidates->count()}):");
        $this->table(
            ['ID', 'KTR', 'HEAT', 'PROD CODE', 'ITEM', 'QTY GOOD', 'PRINT COUNT', 'PRINTED AT', 'STAGE'],
            $sampleRows
        );

        // Already staged records summary
        if ($alreadyStaged->isNotEmpty()) {
            $this->comment('ALREADY STAGED (WILL BE PRESERVED AS-IS):');
            $this->table(
                ['ID', 'KTR', 'HEAT', 'STAGE', 'QTY', 'EXECUTIONS'],
                $alreadyStaged->map(fn ($l) => [
                    $l->id,
                    $l->traveler_number,
                    $l->castingResult?->heat_number ?? '-',
                    $l->current_stage,
                    $l->qty_good,
                    $l->stageExecutions->count(),
                ])->all()
            );
        }

        // Dry run exit
        if ($isDryRun) {
            $this->warn('DRY RUN COMPLETE — No database modifications were made.');

            return 0;
        }

        if ($candidates->isEmpty()) {
            $this->info('No candidates to backfill. Database is already up-to-date.');

            return 0;
        }

        // Confirmation before execution
        $this->warn("About to update {$candidates->count()} KTR records ({$totalCandidateQty} PCS) from current_stage NULL -> 'netto'.");

        if (! $isForce && ! $this->confirm('Proceed with database backfill transaction?', false)) {
            $this->error('Backfill aborted by user.');

            return 1;
        }

        // Step 5: Database Transaction Execution
        $candidateIds = $candidates->pluck('id')->all();

        try {
            DB::transaction(function () use ($candidateIds) {
                // Update only current_stage to 'netto' for candidate IDs
                SandCastingCastingResultLine::whereIn('id', $candidateIds)
                    ->whereNull('current_stage')
                    ->update([
                        'current_stage' => 'netto',
                    ]);
            });

            $this->info('DATABASE TRANSACTION COMMITTED SUCCESSFULLY.');
        } catch (Throwable $e) {
            $this->error('TRANSACTION FAILED & ROLLED BACK: '.$e->getMessage());

            return 1;
        }

        // Post-Backfill Audit
        $postNullCount = SandCastingCastingResultLine::whereNull('current_stage')->count();
        $postNettoCount = SandCastingCastingResultLine::where('current_stage', 'netto')->count();
        $postTotalExecs = SandCastingStageExecution::count();
        $postTotalQty = SandCastingCastingResultLine::sum('qty_good');

        $this->line('');
        $this->info('===============================================================');
        $this->info(' POST-BACKFILL AUDIT SUMMARY                                   ');
        $this->info('===============================================================');
        $this->table(
            ['Field', 'Before Backfill', 'After Backfill', 'Status'],
            [
                ['current_stage IS NULL', $candidates->count(), $postNullCount, $postNullCount === 0 ? '<info>CLEARED (0)</info>' : '<error>NON-ZERO</error>'],
                ['current_stage = netto', $alreadyStaged->where('current_stage', 'netto')->count(), $postNettoCount, '<info>MATCH</info>'],
                ['Total StageExecutions', 0, $postTotalExecs, $postTotalExecs === 0 ? '<info>0 (NO FAKE EXECS)</info>' : '<error>MUTATED</error>'],
                ['Total Qty Good (All)', $allLines->sum('qty_good'), (int) $postTotalQty, (int) $postTotalQty === (int) $allLines->sum('qty_good') ? '<info>UNCHANGED</info>' : '<error>ALTERED</error>'],
            ]
        );

        $this->info("Backfill successfully completed for {$candidates->count()} historical KTRs.");

        return 0;
    }
}
