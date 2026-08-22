<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxRangkaiWorkOrder;
use App\Models\LostWaxTree;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RangkaiExecutionTest extends TestCase
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

    protected function setUpPrintExecution($plan, $user, $good = 100, $defect = 0)
    {
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

        $printService = app(\App\Services\PrintExecutionService::class);
        $printService->record($line, [
            'qty_good' => $good,
            'qty_defect' => $defect,
            'execution_date' => '2026-08-18',
            'status' => 'FINALIZED',
            'recorded_by' => $user->id,
        ]);

        return $line;
    }

    public function test_create_rangkai_work_order_successfully(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();
        $line = $this->setUpPrintExecution($plan, $user, 100, 0);

        $service = app(\App\Services\RangkaiExecutionService::class);

        $wo = $service->createWorkOrder($line, [
            'qty_trees_planned' => 5,
            'tree_capacity' => 20,
            'require_layer_7' => true,
            'notes' => 'Rencana Rangkai Awal',
            'created_by' => $user->id,
        ]);

        $this->assertInstanceOf(LostWaxRangkaiWorkOrder::class, $wo);
        $this->assertEquals('OPEN', $wo->status);
        $this->assertEquals(100, $wo->qty_planned_pcs);
        $this->assertEquals(100, $wo->qty_outstanding);
        $this->assertTrue($wo->require_layer_7);
    }

    public function test_cannot_create_rangkai_work_order_beyond_available(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();
        // 70 good, 5 defect (total good available = 70)
        $line = $this->setUpPrintExecution($plan, $user, 70, 5);

        $service = app(\App\Services\RangkaiExecutionService::class);

        // Attempting to plan 4 trees * 20 capacity = 80 pcs (exceeds 70 available)
        $this->expectException(\InvalidArgumentException::class);
        $service->createWorkOrder($line, [
            'qty_trees_planned' => 4,
            'tree_capacity' => 20,
            'created_by' => $user->id,
        ]);
    }

    public function test_rangkai_executions_and_physical_trees_creation(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();
        $line = $this->setUpPrintExecution($plan, $user, 100, 0);

        $service = app(\App\Services\RangkaiExecutionService::class);

        $wo = $service->createWorkOrder($line, [
            'qty_trees_planned' => 5,
            'tree_capacity' => 20,
            'created_by' => $user->id,
        ]);

        // Execution #1: 4 trees, 80 pcs
        $exec1 = $service->recordExecution($wo, [
            'execution_date' => '2026-08-22',
            'trees_created' => 4,
            'quantities' => [20, 20, 20, 20],
            'family_code' => '4',
            'recorded_by' => $user->id,
        ]);

        $wo->refresh();
        $this->assertEquals('IN_PROGRESS', $wo->status);
        $this->assertEquals(20, $wo->qty_outstanding);
        $this->assertEquals(80, $wo->qty_executed_pcs);
        $this->assertEquals(4, LostWaxTree::where('rangkai_execution_id', $exec1->id)->count());

        // Execution #2: 1 tree, 20 pcs
        $exec2 = $service->recordExecution($wo, [
            'execution_date' => '2026-08-22',
            'trees_created' => 1,
            'quantities' => [20],
            'family_code' => '4',
            'recorded_by' => $user->id,
        ]);

        $wo->refresh();
        $this->assertEquals('COMPLETED', $wo->status);
        $this->assertEquals(0, $wo->qty_outstanding);
        $this->assertEquals(100, $wo->qty_executed_pcs);
        $this->assertEquals(1, LostWaxTree::where('rangkai_execution_id', $exec2->id)->count());

        // Total trees created = 5
        $this->assertEquals(5, LostWaxTree::whereHas('rangkaiExecution', function ($q) use ($wo) {
            $q->where('rangkai_work_order_id', $wo->id);
        })->count());
    }

    public function test_concurrency_double_allocation_prevention(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();
        $line = $this->setUpPrintExecution($plan, $user, 100, 0);

        $service = app(\App\Services\RangkaiExecutionService::class);

        $wo = $service->createWorkOrder($line, [
            'qty_trees_planned' => 1,
            'tree_capacity' => 20,
            'created_by' => $user->id,
        ]);

        // Record execution of 20 pcs
        $service->recordExecution($wo, [
            'execution_date' => '2026-08-22',
            'trees_created' => 1,
            'quantities' => [20],
            'family_code' => '4',
            'recorded_by' => $user->id,
        ]);

        // Attempting to record another execution of 20 pcs when outstanding is 0 - must throw exception
        $this->expectException(\InvalidArgumentException::class);
        $service->recordExecution($wo, [
            'execution_date' => '2026-08-22',
            'trees_created' => 1,
            'quantities' => [20],
            'family_code' => '4',
            'recorded_by' => $user->id,
        ]);
    }
}
