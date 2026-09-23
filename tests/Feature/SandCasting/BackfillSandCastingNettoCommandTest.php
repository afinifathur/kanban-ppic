<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use App\Services\SandCasting\TravelerNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillSandCastingNettoCommandTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'name' => 'Admin Test',
            'email' => 'admin_test@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
    }

    protected function createLine(array $attributes = []): SandCastingCastingResultLine
    {
        $plan = ProductionPlan::create([
            'code' => '268ET'.rand(100, 999),
            'title' => 'Rencana SC '.rand(100, 999),
            'item_code' => '4.'.rand(100, 999),
            'item_name' => 'SS304 TEST FLANGE',
            'po_number' => 'PO-'.rand(100, 999),
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'customer' => 'PT TEST',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260920-'.rand(1000, 9999),
            'scheduled_date' => '2026-09-20',
            'status' => 'ISSUED',
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
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'LA'.date('ymd').rand(10, 99),
            'cast_date' => '2026-09-20',
            'shift' => 1,
            'furnace' => 'F-01',
            'recorded_by' => $this->adminUser->id,
        ]);

        return SandCastingCastingResultLine::create(array_merge([
            'sand_casting_casting_result_id' => $result->id,
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-20'),
            'qty_good' => 20,
            'qty_reject' => 0,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 50.0,
            'current_stage' => null,
            'print_count' => 1,
            'printed_at' => now(),
            'notes' => 'Original note',
        ], $attributes));
    }

    public function test_dry_run_does_not_mutate_database(): void
    {
        $line = $this->createLine(['current_stage' => null]);

        $this->artisan('sand-casting:backfill-netto', ['--dry-run' => true])
            ->expectsOutputToContain('BACKFILL CANDIDATES : 1 KTR')
            ->expectsOutputToContain('DRY RUN COMPLETE — No database modifications were made.')
            ->assertExitCode(0);

        $this->assertNull($line->fresh()->current_stage);
    }

    public function test_backfill_updates_null_stage_to_netto_and_preserves_all_other_fields(): void
    {
        $line = $this->createLine([
            'current_stage' => null,
            'qty_good' => 35,
            'qty_reject' => 2,
            'unit_weight_kg' => 4.20,
            'total_weight_kg' => 147.0,
            'print_count' => 3,
            'notes' => 'Important note',
        ]);

        $this->artisan('sand-casting:backfill-netto', ['--force' => true])
            ->expectsOutputToContain('DATABASE TRANSACTION COMMITTED SUCCESSFULLY.')
            ->assertExitCode(0);

        $fresh = $line->fresh();
        $this->assertEquals('netto', $fresh->current_stage);
        $this->assertEquals(35, $fresh->qty_good);
        $this->assertEquals(2, $fresh->qty_reject);
        $this->assertEquals(4.20, $fresh->unit_weight_kg);
        $this->assertEquals(147.0, $fresh->total_weight_kg);
        $this->assertEquals(3, $fresh->print_count);
        $this->assertEquals('Important note', $fresh->notes);

        // Verify NO stage executions were created
        $this->assertEquals(0, SandCastingStageExecution::count());
    }

    public function test_backfill_is_idempotent(): void
    {
        $this->createLine(['current_stage' => null]);

        // First run
        $this->artisan('sand-casting:backfill-netto', ['--force' => true])
            ->assertExitCode(0);

        // Second run: should report 0 candidates
        $this->artisan('sand-casting:backfill-netto', ['--force' => true])
            ->expectsOutputToContain('BACKFILL CANDIDATES : 0 KTR')
            ->expectsOutputToContain('No candidates to backfill. Database is already up-to-date.')
            ->assertExitCode(0);
    }

    public function test_backfill_does_not_touch_already_staged_or_executed_lines(): void
    {
        $stagedLine = $this->createLine(['current_stage' => 'bubut_od']);
        $executedLine = $this->createLine(['current_stage' => null]);

        // Create execution for executedLine
        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $executedLine->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 20,
            'defect_qty' => 0,
            'good_qty' => 20,
            'status' => 'CONFIRMED',
            'operator_id' => $this->adminUser->id,
            'executed_at' => now(),
        ]);

        $this->artisan('sand-casting:backfill-netto', ['--force' => true])
            ->expectsOutputToContain('BACKFILL CANDIDATES : 0 KTR')
            ->assertExitCode(0);

        $this->assertEquals('bubut_od', $stagedLine->fresh()->current_stage);
        $this->assertNull($executedLine->fresh()->current_stage);
    }
}
