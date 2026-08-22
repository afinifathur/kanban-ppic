<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintExecution;
use App\Models\LostWaxPrintOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function createProductionPlan($attributes = [])
    {
        return \App\Models\ProductionPlan::create(array_merge([
            'code' => 'TEST61',
            'customer' => 'TEST CUSTOMER',
            'item_code' => '4.101105K.A0020',
            'item_name' => 'SS316 BLIND 2"',
            'aisi' => '316',
            'size' => '2"',
            'weight' => 0.75,
            'po_number' => 'PO-TEST-01',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'status' => 'planning',
        ], $attributes));
    }

    public function test_create_print_execution_via_service_successfully(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260818-0001',
            'scheduled_date' => '2026-08-18',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        $service = app(\App\Services\PrintExecutionService::class);

        // Record a draft execution
        $execution = $service->record($line, [
            'qty_good' => 70,
            'qty_defect' => 5,
            'execution_date' => '2026-08-18',
            'status' => 'DRAFT',
            'notes' => 'Draft Day 1',
            'recorded_by' => $user->id,
        ]);

        $this->assertInstanceOf(LostWaxPrintExecution::class, $execution);
        $this->assertEquals(70, $execution->qty_good);
        $this->assertEquals(5, $execution->qty_defect);
        $this->assertEquals('DRAFT', $execution->status);

        // Check line aggregates: draft should not affect finalized actual and outstanding
        $line->refresh();
        $this->assertEquals(0, (int) $line->qty_executed_good);
        $this->assertEquals(0, (int) $line->qty_executed_defect);
        $this->assertEquals(100, $line->qty_outstanding);
        $this->assertEquals('PENDING', $line->execution_status);

        // Parent Order should still be ISSUED
        $order->refresh();
        $this->assertEquals('ISSUED', $order->status);

        // Now finalize the execution
        $service->finalize($execution);

        // Line aggregates should update now
        $line->refresh();
        $this->assertEquals(70, $line->qty_executed_good);
        $this->assertEquals(5, $line->qty_executed_defect);
        $this->assertEquals(25, $line->qty_outstanding);
        $this->assertEquals('IN_PROGRESS', $line->execution_status);

        // Parent Order should become PARTIALLY_COMPLETED
        $order->refresh();
        $this->assertEquals('PARTIALLY_COMPLETED', $order->status);

    }

    public function test_partial_and_second_execution_transitions_status_to_completed(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260818-0001',
            'scheduled_date' => '2026-08-18',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        $service = app(\App\Services\PrintExecutionService::class);

        // Day 1: 70 good, 5 defect
        $service->record($line, [
            'qty_good' => 70,
            'qty_defect' => 5,
            'execution_date' => '2026-08-18',
            'status' => 'FINALIZED',
            'recorded_by' => $user->id,
        ]);

        $line->refresh();
        $this->assertEquals(25, $line->qty_outstanding);

        // Day 2: 25 good, 0 defect
        $service->record($line, [
            'qty_good' => 25,
            'qty_defect' => 0,
            'execution_date' => '2026-08-19',
            'status' => 'FINALIZED',
            'recorded_by' => $user->id,
        ]);

        $line->refresh();
        $this->assertEquals(0, $line->qty_outstanding);
        $this->assertEquals(95, $line->qty_executed_good);
        $this->assertEquals(5, $line->qty_executed_defect);
        $this->assertEquals('COMPLETED', $line->execution_status);

        // Parent Order should become COMPLETED automatically
        $order->refresh();
        $this->assertEquals('COMPLETED', $order->status);
    }

    public function test_draft_execution_can_be_edited_but_finalized_is_locked(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260818-0001',
            'scheduled_date' => '2026-08-18',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        $service = app(\App\Services\PrintExecutionService::class);

        // Create draft
        $execution = $service->record($line, [
            'qty_good' => 40,
            'qty_defect' => 2,
            'execution_date' => '2026-08-18',
            'status' => 'DRAFT',
            'recorded_by' => $user->id,
        ]);

        // Edit draft
        $service->update($execution, [
            'qty_good' => 45,
            'qty_defect' => 5,
        ]);

        $execution->refresh();
        $this->assertEquals(45, $execution->qty_good);
        $this->assertEquals(5, $execution->qty_defect);

        // Finalize
        $service->finalize($execution);
        $this->assertEquals('FINALIZED', $execution->status);

        // Try to edit finalized - should throw exception
        $this->expectException(\InvalidArgumentException::class);
        $service->update($execution, [
            'qty_good' => 50,
        ]);
    }

    public function test_multi_line_order_completion_stages(): void
    {
        $user = User::factory()->create();
        $plan1 = $this->createProductionPlan(['code' => 'A1']);
        $plan2 = $this->createProductionPlan(['code' => 'A2']);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260818-0001',
            'scheduled_date' => '2026-08-18',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $line1 = $order->lines()->create([
            'production_plan_id' => $plan1->id,
            'qty_ordered' => 50,
            'code' => $plan1->code,
            'customer' => $plan1->customer,
            'item_name' => $plan1->item_name,
        ]);

        $line2 = $order->lines()->create([
            'production_plan_id' => $plan2->id,
            'qty_ordered' => 80,
            'code' => $plan2->code,
            'customer' => $plan2->customer,
            'item_name' => $plan2->item_name,
        ]);

        $service = app(\App\Services\PrintExecutionService::class);

        // Record for line 1 only (completed)
        $service->record($line1, [
            'qty_good' => 50,
            'qty_defect' => 0,
            'status' => 'FINALIZED',
        ]);

        $order->refresh();
        // Still has line 2 outstanding, status should be PARTIALLY_COMPLETED
        $this->assertEquals('PARTIALLY_COMPLETED', $order->status);

        // Record for line 2 (completed)
        $service->record($line2, [
            'qty_good' => 80,
            'qty_defect' => 0,
            'status' => 'FINALIZED',
        ]);

        $order->refresh();
        // All lines done, status should become COMPLETED
        $this->assertEquals('COMPLETED', $order->status);
    }

    public function test_outcomes_ui_visibility_and_execution_history(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260818-9999',
            'scheduled_date' => '2026-08-18',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => 'SS316 TEE BAR',
        ]);

        $service = app(\App\Services\PrintExecutionService::class);

        // Record a draft execution
        $service->record($line, [
            'qty_good' => 30,
            'qty_defect' => 2,
            'execution_date' => '2026-08-18',
            'status' => 'DRAFT',
            'notes' => 'Draft execution notes',
            'recorded_by' => $user->id,
        ]);

        // Record a finalized execution
        $service->record($line, [
            'qty_good' => 40,
            'qty_defect' => 3,
            'execution_date' => '2026-08-19',
            'status' => 'FINALIZED',
            'notes' => 'Finalized execution notes',
            'recorded_by' => $user->id,
        ]);

        // Access index page as authorized user
        $responseIndex = $this->actingAs($user)->get(route('lost-wax.outcomes.index'));
        $responseIndex->assertStatus(200);
        // Verify progress details are visible on index
        $responseIndex->assertSee('PC-20260818-9999');
        $responseIndex->assertSee('Good:');
        $responseIndex->assertSee('Defect:');
        $responseIndex->assertSee('Ostd:');
        $responseIndex->assertSee('43%'); // Progress based on finalized only (40 good + 3 defect = 43)

        // Access edit page
        $responseEdit = $this->actingAs($user)->get(route('lost-wax.outcomes.edit', $order));
        $responseEdit->assertStatus(200);
        // Verify history table contains correct chronological rows
        $responseEdit->assertSee('#1');
        $responseEdit->assertSee('#2');
        $responseEdit->assertSee('DRAFT');
        $responseEdit->assertSee('FINALIZED');
        $responseEdit->assertSee('SS316 TEE BAR');
        $responseEdit->assertSee('Total Good Terkumpul');
        $responseEdit->assertSee('40 pcs');
        $responseEdit->assertSee('3 pcs');
        $responseEdit->assertSee('57 pcs'); // Outstanding = 100 - 40 - 3 = 57
    }
}
