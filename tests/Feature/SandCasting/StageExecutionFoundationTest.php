<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use App\Services\SandCasting\TravelerNumberGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StageExecutionFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::firstOrCreate(['name' => 'access_planning']);
        $role = Role::firstOrCreate(['name' => 'ppic']);
        $role->givePermissionTo($permission);

        $this->user = User::factory()->create([
            'email' => 'ppic_exec@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->user->assignRole('ppic');
    }

    protected function createKtrLine(array $attributes = []): SandCastingCastingResultLine
    {
        $plan = ProductionPlan::create([
            'code' => '268ET001',
            'title' => 'Rencana SC 268ET001',
            'item_code' => '4.101',
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-001',
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'customer' => 'PT SINAR METAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260920-0001',
            'scheduled_date' => '2026-09-20',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
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
            'heat_number' => 'A214092001',
            'cast_date' => '2026-09-20',
            'furnace' => 'F-01',
            'shift' => '1',
            'operator_name' => 'Sutrisno',
            'recorded_by' => $this->user->id,
        ]);

        $travelerNumber = TravelerNumberGenerator::generateNext('2026-09-20');

        return $result->lines()->create(array_merge([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $travelerNumber,
            'qty_good' => 100,
            'qty_reject' => 2,
            'unit_weight_kg' => 3.25,
            'total_weight_kg' => 325.00,
            'print_count' => 1,
            'printed_at' => now(),
            'last_printed_by' => $this->user->id,
        ], $attributes));
    }

    public function test_stage_execution_belongs_to_result_line_and_user_as_operator(): void
    {
        $line = $this->createKtrLine();

        $execution = SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 5,
            'good_qty' => 95,
            'status' => 'CONFIRMED',
            'operator_id' => $this->user->id,
            'executed_at' => now(),
            'notes' => 'Netto potong selesai 95 pcs bagus.',
        ]);

        $this->assertInstanceOf(SandCastingCastingResultLine::class, $execution->castingResultLine);
        $this->assertEquals($line->id, $execution->castingResultLine->id);

        $this->assertInstanceOf(User::class, $execution->operator);
        $this->assertEquals($this->user->id, $execution->operator->id);

        $this->assertDatabaseHas('sand_casting_stage_executions', [
            'id' => $execution->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 5,
            'good_qty' => 95,
            'operator_id' => $this->user->id,
        ]);
    }

    public function test_result_line_has_many_stage_executions(): void
    {
        $line = $this->createKtrLine();

        $exec1 = SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 5,
            'good_qty' => 95,
            'status' => 'CONFIRMED',
            'operator_id' => $this->user->id,
            'executed_at' => now(),
        ]);

        $exec2 = SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'bubut_od',
            'checkpoint_code' => 'OD_TURNING',
            'input_qty' => 95,
            'defect_qty' => 3,
            'good_qty' => 92,
            'status' => 'CONFIRMED',
            'operator_id' => $this->user->id,
            'executed_at' => now()->addMinutes(30),
        ]);

        $this->assertCount(2, $line->stageExecutions);
        $this->assertTrue($line->stageExecutions->contains('id', $exec1->id));
        $this->assertTrue($line->stageExecutions->contains('id', $exec2->id));
    }

    public function test_current_stage_is_nullable_for_historical_rows(): void
    {
        $line = $this->createKtrLine();

        $this->assertNull($line->current_stage);

        $line->update(['current_stage' => 'netto']);
        $this->assertEquals('netto', $line->fresh()->current_stage);
    }

    public function test_is_urgent_defaults_to_false_and_urgent_metadata_casts(): void
    {
        $line = $this->createKtrLine();

        $this->assertFalse($line->is_urgent);
        $this->assertNull($line->urgent_set_at);
        $this->assertNull($line->urgent_set_by);

        $setAt = now();
        $line->update([
            'is_urgent' => true,
            'urgent_set_at' => $setAt,
            'urgent_set_by' => $this->user->id,
        ]);

        $line->refresh();
        $this->assertTrue($line->is_urgent);
        $this->assertEquals($this->user->id, $line->urgentSetBy->id);
        $this->assertNotNull($line->urgent_set_at);
    }

    public function test_duplicate_ktr_and_checkpoint_execution_is_rejected_by_unique_constraint(): void
    {
        $line = $this->createKtrLine();

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 5,
            'good_qty' => 95,
            'status' => 'CONFIRMED',
            'operator_id' => $this->user->id,
            'executed_at' => now(),
        ]);

        // Attempting to record second execution on the SAME checkpoint for the SAME KTR must trigger Unique Constraint violation
        $this->expectException(QueryException::class);

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 95,
            'defect_qty' => 0,
            'good_qty' => 95,
            'status' => 'CONFIRMED',
            'operator_id' => $this->user->id,
            'executed_at' => now()->addMinutes(10),
        ]);
    }

    public function test_foreign_key_deletion_is_restricted_when_stage_executions_exist(): void
    {
        $line = $this->createKtrLine();

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 5,
            'good_qty' => 95,
            'status' => 'CONFIRMED',
            'operator_id' => $this->user->id,
            'executed_at' => now(),
        ]);

        // Deleting the KTR line while stage execution exists must fail with restrictOnDelete
        $this->expectException(QueryException::class);
        $line->delete();
    }

    public function test_existing_ktr_data_and_initial_cor_output_remains_intact(): void
    {
        $line = $this->createKtrLine([
            'traveler_number' => 'KTR-20260920-9999',
            'qty_good' => 100,
            'qty_reject' => 2,
            'print_count' => 3,
        ]);

        // Record downstream execution
        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 10,
            'good_qty' => 90,
            'status' => 'CONFIRMED',
            'operator_id' => $this->user->id,
            'executed_at' => now(),
        ]);

        $line->refresh();

        // Historical COR initial output must NEVER be altered by downstream execution
        $this->assertEquals(100, $line->qty_good);
        $this->assertEquals(2, $line->qty_reject);
        $this->assertEquals('KTR-20260920-9999', $line->traveler_number);
        $this->assertEquals(3, $line->print_count);
    }
}
