<?php

namespace Tests\Feature\SandCasting;

use App\Console\Commands\ReconcileCncDebtCommand;
use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileCncDebtCommandTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $operatorUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'name' => 'Admin PPIC Test',
            'email' => 'admin@peroniks.com',
        ]);

        $this->operatorUser = User::factory()->create([
            'name' => 'Operator CNC Test',
            'email' => 'operatorcnc@peroniks.com',
        ]);
    }

    protected function createKtrLine(string $travelerNumber, string $currentStage = 'bubut_cnc', int $qty = 50): SandCastingCastingResultLine
    {
        $plan = ProductionPlan::create([
            'code' => '268ET'.rand(100, 999),
            'title' => 'Rencana SC '.rand(100, 999),
            'item_code' => '4.'.rand(100, 999),
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-'.rand(100, 999),
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'customer' => 'PT SINAR METAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-'.rand(1000, 9999),
            'scheduled_date' => now()->toDateString(),
            'status' => 'ISSUED',
            'notes' => 'Test order',
            'created_by' => $this->adminUser->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '2"',
            'aisi' => 'FC250',
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 250.00,
            'status' => 'issued',
        ]);

        $castingResult = SandCastingCastingResult::create([
            'heat_number' => 'A214092'.rand(100, 999),
            'cast_date' => now()->toDateString(),
            'furnace' => 'F1',
            'shift' => '1',
            'operator_name' => 'Budi Cor',
            'recorded_by' => $this->adminUser->id,
            'total_qty_good' => 100,
            'total_qty_reject' => 0,
        ]);

        return $castingResult->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $travelerNumber,
            'qty_good' => $qty,
            'qty_reject' => 0,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => $qty * 2.50,
            'current_stage' => $currentStage,
            'queue_position' => null,
            'is_urgent' => false,
        ]);
    }

    protected function addCncExecution(SandCastingCastingResultLine $line, string $checkpointCode = 'CNC_MACHINING', bool $physicalDone = true): SandCastingStageExecution
    {
        return $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => $checkpointCode,
            'input_qty' => $line->qty_good,
            'defect_qty' => 0,
            'good_qty' => $line->qty_good,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'operator_id' => $this->operatorUser->id,
            'executed_at' => now()->subDays(2),
            'physical_done_at' => $physicalDone ? now()->subDays(2) : null,
        ]);
    }

    /**
     * TEST 1: Dry-run does not modify database.
     */
    public function test_1_dry_run_does_not_modify_database(): void
    {
        $line = $this->createKtrLine('KTR-20260917-0046', 'bubut_cnc', 60);
        $this->addCncExecution($line, 'CNC_MACHINING', true);

        $this->artisan('sand-casting:reconcile-cnc-debt')
            ->expectsOutputToContain('MODE: DRY RUN')
            ->expectsOutputToContain('WOULD UPDATE → bor')
            ->expectsOutputToContain('[DRY RUN SELESAI] Tidak ada perubahan database yang dilakukan.')
            ->assertExitCode(0);

        $this->assertSame('bubut_cnc', $line->fresh()->current_stage);
    }

    /**
     * TEST 2: Execute moves all 16 whitelist KTRs from bubut_cnc -> bor.
     */
    public function test_2_execute_reconciles_whitelist_ktrs_from_bubut_cnc_to_bor(): void
    {
        $lines = [];
        foreach (ReconcileCncDebtCommand::WHITELIST_KTRS as $ktrNumber) {
            $l = $this->createKtrLine($ktrNumber, 'bubut_cnc', 40);
            $this->addCncExecution($l, 'CNC_MACHINING', true);
            $lines[] = $l;
        }

        $this->artisan('sand-casting:reconcile-cnc-debt', ['--execute' => true])
            ->expectsOutputToContain('MODE: EXECUTE')
            ->expectsOutputToContain('Candidate:  16')
            ->expectsOutputToContain('Safe:       16')
            ->expectsOutputToContain('Updated:    16')
            ->assertExitCode(0);

        foreach ($lines as $l) {
            $this->assertSame('bor', $l->fresh()->current_stage);
        }
    }

    /**
     * TEST 3: Fresh KTR that has not done CNC is not modified.
     */
    public function test_3_fresh_ktr_without_cnc_execution_is_not_modified(): void
    {
        $freshLine = $this->createKtrLine('KTR-20260924-9999', 'bubut_cnc', 50);
        // No CNC execution attached!

        $this->artisan('sand-casting:reconcile-cnc-debt', ['--execute' => true])
            ->assertExitCode(0);

        $this->assertSame('bubut_cnc', $freshLine->fresh()->current_stage);
    }

    /**
     * TEST 4: KTR with current_stage already bor is not modified.
     */
    public function test_4_ktr_already_at_bor_is_not_modified(): void
    {
        $borLine = $this->createKtrLine('KTR-20260917-0046', 'bor', 60);
        $this->addCncExecution($borLine, 'CNC_MACHINING', true);

        $this->artisan('sand-casting:reconcile-cnc-debt', ['--execute' => true])
            ->assertExitCode(0);

        $this->assertSame('bor', $borLine->fresh()->current_stage);
    }

    /**
     * TEST 5: Candidate without CNC_MACHINING physical_done is not modified.
     */
    public function test_5_candidate_without_physical_done_is_not_modified(): void
    {
        $line = $this->createKtrLine('KTR-20260917-0046', 'bubut_cnc', 60);
        $this->addCncExecution($line, 'CNC_MACHINING', false); // physical_done_at = NULL

        $this->artisan('sand-casting:reconcile-cnc-debt', ['--execute' => true])
            ->assertExitCode(0);

        $this->assertSame('bubut_cnc', $line->fresh()->current_stage);
    }

    /**
     * TEST 6: Command does not create new executions.
     */
    public function test_6_command_does_not_create_new_stage_executions(): void
    {
        $line = $this->createKtrLine('KTR-20260917-0046', 'bubut_cnc', 60);
        $exec = $this->addCncExecution($line, 'CNC_MACHINING', true);

        $initialExecCount = SandCastingStageExecution::count();

        $this->artisan('sand-casting:reconcile-cnc-debt', ['--execute' => true])
            ->assertExitCode(0);

        $this->assertSame($initialExecCount, SandCastingStageExecution::count());
        $this->assertCount(1, $line->fresh()->stageExecutions);
        $this->assertSame($exec->id, $line->fresh()->stageExecutions->first()->id);
    }

    /**
     * TEST 7: Quantity, good_qty, defect_qty, input_qty remain intact.
     */
    public function test_7_quantities_and_execution_data_remain_immutable(): void
    {
        $line = $this->createKtrLine('KTR-20260917-0046', 'bubut_cnc', 60);
        $exec = $this->addCncExecution($line, 'CNC_MACHINING', true);

        $this->artisan('sand-casting:reconcile-cnc-debt', ['--execute' => true])
            ->assertExitCode(0);

        $freshLine = $line->fresh();
        $freshExec = $exec->fresh();

        $this->assertSame(60, $freshLine->qty_good);
        $this->assertSame(0, $freshLine->qty_reject);
        $this->assertSame(60, $freshExec->input_qty);
        $this->assertSame(0, $freshExec->defect_qty);
        $this->assertSame(60, $freshExec->good_qty);
        $this->assertSame('CNC_MACHINING', $freshExec->checkpoint_code);
    }

    /**
     * TEST 8: Unexpected candidate (outside whitelist) is not processed.
     */
    public function test_8_unexpected_candidate_outside_whitelist_is_flagged_and_not_processed(): void
    {
        $unexpectedLine = $this->createKtrLine('KTR-20260999-0099', 'bubut_cnc', 30);
        $this->addCncExecution($unexpectedLine, 'CNC_MACHINING', true);

        $this->artisan('sand-casting:reconcile-cnc-debt', ['--execute' => true])
            ->expectsOutputToContain('Unexpected: 1')
            ->expectsOutputToContain('SKIPPED (UNEXPECTED)')
            ->assertExitCode(0);

        $this->assertSame('bubut_cnc', $unexpectedLine->fresh()->current_stage);
    }

    /**
     * TEST 9: Transaction atomicity / rollback if candidate update fails midway.
     */
    public function test_9_transaction_rolls_back_if_validation_fails(): void
    {
        $line1 = $this->createKtrLine('KTR-20260915-0009', 'bubut_cnc', 48);
        $this->addCncExecution($line1, 'CNC_MACHINING', true);

        $line2 = $this->createKtrLine('KTR-20260916-0016', 'bubut_cnc', 44);
        $this->addCncExecution($line2, 'CNC_MACHINING', true);

        // Simulate a database failure on the second candidate update
        SandCastingCastingResultLine::saving(function ($model) use ($line2) {
            if ($model->id === $line2->id) {
                throw new \RuntimeException('Simulated database error on line 2 update');
            }
        });

        $this->artisan('sand-casting:reconcile-cnc-debt', ['--execute' => true])
            ->expectsOutputToContain('TRANSACTION ROLLBACK')
            ->assertExitCode(1);

        // Line 1 must NOT be partially updated due to rollback
        $this->assertSame('bubut_cnc', $line1->fresh()->current_stage);
        $this->assertSame('bubut_cnc', $line2->fresh()->current_stage);
    }

    /**
     * TEST 10: Specific audit example KTR-20260917-0046 reconciles cleanly to bor and retains its execution ID.
     */
    public function test_10_specific_ktr_20260917_0046_reconciles_to_bor_cleanly(): void
    {
        $line = $this->createKtrLine('KTR-20260917-0046', 'bubut_cnc', 60);
        $exec = $this->addCncExecution($line, 'CNC_MACHINING', true);

        $this->artisan('sand-casting:reconcile-cnc-debt', ['--execute' => true])
            ->assertExitCode(0);

        $this->assertSame('bor', $line->fresh()->current_stage);
        $this->assertCount(1, $line->fresh()->stageExecutions);
        $this->assertSame($exec->id, $line->fresh()->stageExecutions->first()->id);
    }
}
