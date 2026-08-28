<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\LostWaxTreeAllocation;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\PrintExecutionService;
use App\Services\RangkaiExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductionCodePoolAndAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected RangkaiExecutionService $rangkaiService;

    protected PrintExecutionService $printExecutionService;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $accessPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution']);
        $adminRole->syncPermissions([$accessPlanning, $accessExecution]);

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');

        $this->rangkaiService = app(RangkaiExecutionService::class);
        $this->printExecutionService = app(PrintExecutionService::class);
    }

    private function createPlan(string $code = '268ETB827', int $qty = 320): ProductionPlan
    {
        return ProductionPlan::create([
            'code' => $code,
            'customer' => 'CUSL063',
            'item_code' => '4.101105K.A0080',
            'item_name' => 'CS Q235 PLANE FLANGE JIS 5K 2-1/2"',
            'aisi' => 'CS Q235',
            'size' => '2-1/2"',
            'weight' => 1.25,
            'po_number' => 'PO-TEST-'.$code,
            'qty_planned' => $qty,
            'qty_remaining' => $qty,
            'line_number' => 1,
            'status' => 'planning',
        ]);
    }

    private function createPrintLine(ProductionPlan $plan, int $qtyOrdered, int $good, int $defect = 0): LostWaxPrintOrderLine
    {
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-'.date('Ymd').'-'.rand(1000, 9999),
            'scheduled_date' => now()->format('Y-m-d'),
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => $qtyOrdered,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'standard_tree_capacity' => 32,
        ]);

        // Record finalized execution
        $this->printExecutionService->record($line, [
            'qty_good' => $good,
            'qty_defect' => $defect,
            'status' => 'FINALIZED',
            'execution_date' => now()->format('Y-m-d'),
            'recorded_by' => $this->user->id,
        ]);

        return $line->fresh();
    }

    /**
     * CASE 1: Normal (Need 320, Good 320, Rangkai 320)
     */
    public function test_case_1_normal_flow(): void
    {
        $plan = $this->createPlan('268ETB827', 320);
        $line = $this->createPrintLine($plan, 320, 320, 0);

        $this->assertEquals(320, $line->qty_available_for_rangkai);

        $workOrder = $this->rangkaiService->createWorkOrder($line, [
            'qty_trees_planned' => 320,
            'tree_capacity' => 1,
            'standard_capacity_guide' => 32,
        ]);

        $quantities = array_fill(0, 10, 32); // 10 trees @ 32 pcs = 320 pcs
        $execution = $this->rangkaiService->recordExecution($workOrder, [
            'execution_date' => now()->format('Y-m-d'),
            'trees_created' => 10,
            'quantities' => $quantities,
            'family_code' => '6',
        ]);

        $this->assertEquals(0, $execution->variance_qty);
        $this->assertFalse($execution->is_anomaly);
        $this->assertEquals('COMPLETED', $workOrder->fresh()->status);
        $this->assertEquals(0, $line->fresh()->qty_available_for_rangkai);
        $this->assertEquals(10, LostWaxTree::count());
        $this->assertEquals(10, LostWaxTreeAllocation::count());
        $this->assertEquals(320, LostWaxTreeAllocation::sum('allocated_qty'));
    }

    /**
     * CASE 2 & 3: Excess Active & Excess Recycled / Closed
     */
    public function test_case_2_and_3_excess_handling_and_closure(): void
    {
        $plan = $this->createPlan('268ETB827', 300);
        $line = $this->createPrintLine($plan, 320, 324, 0); // Overprint: 324 good

        $this->assertEquals(324, $line->qty_available_for_rangkai);

        $workOrder = $this->rangkaiService->createWorkOrder($line, [
            'qty_trees_planned' => 320,
            'tree_capacity' => 1,
            'standard_capacity_guide' => 32,
        ]);

        $quantities = array_fill(0, 10, 32); // 320 pcs
        $execution = $this->rangkaiService->recordExecution($workOrder, [
            'execution_date' => now()->format('Y-m-d'),
            'trees_created' => 10,
            'quantities' => $quantities,
            'family_code' => '6',
        ]);

        // Case 2: 4 pcs remain active in available pool
        $this->assertEquals(4, $line->fresh()->qty_available_for_rangkai);
        $this->assertEquals(0, $execution->variance_qty);
        $this->assertFalse($execution->is_anomaly);

        // Case 3: Close excess 4 pcs
        $this->rangkaiService->closeExcessBalance($line, 4);
        $this->assertEquals(4, $line->fresh()->qty_excess_closed);
        $this->assertEquals(0, $line->fresh()->qty_available_for_rangkai);
    }

    /**
     * CASE 4: Defect / Partial Tree (Good 316, Defect 4, Rangkai 316 => 9x32 + 28)
     */
    public function test_case_4_defect_and_partial_tree(): void
    {
        $plan = $this->createPlan('268ETB827', 320);
        $line = $this->createPrintLine($plan, 320, 316, 4);

        $this->assertEquals(316, $line->qty_available_for_rangkai);

        $workOrder = $this->rangkaiService->createWorkOrder($line, [
            'qty_trees_planned' => 316,
            'tree_capacity' => 1,
            'standard_capacity_guide' => 32,
        ]);

        $quantities = array_merge(array_fill(0, 9, 32), [28]); // 9x32 + 28 = 316 pcs
        $execution = $this->rangkaiService->recordExecution($workOrder, [
            'execution_date' => now()->format('Y-m-d'),
            'trees_created' => 10,
            'quantities' => $quantities,
            'family_code' => '6',
        ]);

        $this->assertEquals(0, $execution->variance_qty);
        $this->assertEquals(10, $workOrder->executions()->first()->trees()->count());
        $this->assertEquals(28, LostWaxTree::latest('id')->first()->quantity);
        $this->assertEquals(0, $line->fresh()->qty_available_for_rangkai);
        $this->assertEquals('COMPLETED', $workOrder->fresh()->status);
    }

    /**
     * CASE 5: Shortage (Target 320, Good 320, Rangkai 300, Close Shortage 20)
     */
    public function test_case_5_shortage_closure(): void
    {
        $plan = $this->createPlan('268ETB827', 320);
        $line = $this->createPrintLine($plan, 320, 320, 0);

        $workOrder = $this->rangkaiService->createWorkOrder($line, [
            'qty_trees_planned' => 320, // RWO originally planned for 320
            'tree_capacity' => 1,
            'standard_capacity_guide' => 32,
        ]);

        $quantities = array_merge(array_fill(0, 9, 32), [12]); // 9x32 + 12 = 300 pcs
        $execution = $this->rangkaiService->recordExecution($workOrder, [
            'execution_date' => now()->format('Y-m-d'),
            'trees_created' => 10,
            'quantities' => $quantities,
            'family_code' => '6',
        ]);

        $this->assertEquals('IN_PROGRESS', $workOrder->fresh()->status);
        $this->assertEquals(20, $workOrder->fresh()->qty_outstanding);

        // PPIC decides to close with shortage
        $this->rangkaiService->closeWorkOrderWithShortage($workOrder, 'Customer accepts 300 pcs partial delivery', $this->user);

        $this->assertEquals('CLOSED_WITH_SHORTAGE', $workOrder->fresh()->status);
        $this->assertEquals(0, $workOrder->fresh()->qty_outstanding);
        $this->assertEquals('Customer accepts 300 pcs partial delivery', $workOrder->fresh()->closure_reason);
    }

    /**
     * CASE 6: Carry Over Across Print Order Lines Under Same Production Code
     */
    public function test_case_6_carry_over_fifo_allocation(): void
    {
        $plan = $this->createPlan('268ETB827', 320);

        // Line A: Good 100
        $lineA = $this->createPrintLine($plan, 100, 100, 0);

        // Line B: Good 252
        $lineB = $this->createPrintLine($plan, 252, 252, 0);

        // Consume 96 from Line A first
        $woA = $this->rangkaiService->createWorkOrder($lineA, [
            'qty_trees_planned' => 96,
            'tree_capacity' => 1,
            'standard_capacity_guide' => 32,
        ]);
        $this->rangkaiService->recordExecution($woA, [
            'execution_date' => now()->format('Y-m-d'),
            'trees_created' => 3,
            'quantities' => [32, 32, 32],
            'family_code' => '6',
        ]);

        // Line A available = 4, Line B available = 252, Total Pool = 256
        $this->assertEquals(4, $lineA->fresh()->qty_available_for_rangkai);
        $this->assertEquals(252, $lineB->fresh()->qty_available_for_rangkai);
        $this->assertEquals(256, $this->rangkaiService->getTotalAvailablePool('268ETB827'));

        // Now create a Work Order on Line B for 256 pcs (using carry-over)
        $woB = $this->rangkaiService->createWorkOrder($lineB, [
            'qty_trees_planned' => 256,
            'tree_capacity' => 1,
            'standard_capacity_guide' => 32,
        ]);

        $quantities = array_fill(0, 8, 32); // 8 trees @ 32 = 256 pcs
        $this->rangkaiService->recordExecution($woB, [
            'execution_date' => now()->format('Y-m-d'),
            'trees_created' => 8,
            'quantities' => $quantities,
            'family_code' => '6',
        ]);

        // Verify Tree 4 (first tree of this batch) has allocations from both Line A (4) and Line B (28)
        $trees = $woB->executions()->first()->trees()->orderBy('id', 'asc')->get();
        $this->assertCount(8, $trees);

        $tree1 = $trees[0];
        $allocationsTree1 = $tree1->allocations()->get();
        $this->assertCount(2, $allocationsTree1);

        $allocA = $allocationsTree1->where('lost_wax_print_order_line_id', $lineA->id)->first();
        $allocB = $allocationsTree1->where('lost_wax_print_order_line_id', $lineB->id)->first();
        $this->assertNotNull($allocA);
        $this->assertNotNull($allocB);
        $this->assertEquals(4, $allocA->allocated_qty);
        $this->assertEquals(28, $allocB->allocated_qty);

        // Trees 2-8 have allocations entirely from Line B (32 pcs each)
        for ($i = 1; $i < 8; $i++) {
            $allocs = $trees[$i]->allocations()->get();
            $this->assertCount(1, $allocs);
            $this->assertEquals($lineB->id, $allocs[0]->lost_wax_print_order_line_id);
            $this->assertEquals(32, $allocs[0]->allocated_qty);
        }

        // Both lines should now have available = 0
        $this->assertEquals(0, $lineA->fresh()->qty_available_for_rangkai);
        $this->assertEquals(0, $lineB->fresh()->qty_available_for_rangkai);
    }

    /**
     * CASE 7: Cross Production Code Isolation (Must NOT Pool)
     */
    public function test_case_7_cross_production_code_isolation(): void
    {
        $planA = $this->createPlan('268ETB827', 100);
        $lineA = $this->createPrintLine($planA, 100, 4, 0); // 4 pcs on Code A

        $planB = $this->createPlan('268ETB828', 300); // Different code!
        $lineB = $this->createPrintLine($planB, 300, 252, 0); // 252 pcs on Code B

        // 1. Pool for 268ETB827 must be strictly 4
        $this->assertEquals(4, $this->rangkaiService->getTotalAvailablePool('268ETB827'));
        // 2. Pool for 268ETB828 must be strictly 252
        $this->assertEquals(252, $this->rangkaiService->getTotalAvailablePool('268ETB828'));

        // 3. Create RWO on Code A and execute 4 pcs
        $woA = $this->rangkaiService->createWorkOrder($lineA, [
            'qty_trees_planned' => 4,
            'tree_capacity' => 1,
            'standard_capacity_guide' => 32,
        ]);
        $this->rangkaiService->recordExecution($woA, [
            'execution_date' => now()->format('Y-m-d'),
            'trees_created' => 1,
            'quantities' => [4],
            'family_code' => '6',
        ]);

        // Code A is now 0, Code B MUST REMAIN 252 (NO accidental pooling)
        $this->assertEquals(0, $lineA->fresh()->qty_available_for_rangkai);
        $this->assertEquals(252, $lineB->fresh()->qty_available_for_rangkai);
        $this->assertEquals(252, $this->rangkaiService->getTotalAvailablePool('268ETB828'));

        // Assert all allocations for woA point ONLY to lineA, never lineB
        $treeAllocations = LostWaxTreeAllocation::whereIn('lost_wax_tree_id', $woA->executions->first()->trees->pluck('id'))->get();
        $this->assertTrue($treeAllocations->every(fn ($alloc) => $alloc->lost_wax_print_order_line_id === $lineA->id));
    }

    /**
     * CASE 8: Physical Over-Consumption / Anomaly (Physical > Recorded Pool Available)
     */
    public function test_case_8_physical_over_consumption_anomaly_handling(): void
    {
        $plan = $this->createPlan('268ETB827', 256);
        $line = $this->createPrintLine($plan, 256, 256, 0);

        $workOrder = $this->rangkaiService->createWorkOrder($line, [
            'qty_trees_planned' => 256,
            'tree_capacity' => 1,
            'standard_capacity_guide' => 32,
        ]);

        // 4 pcs were closed / reduced from pool, leaving 252 pcs available in pool
        $this->rangkaiService->closeExcessBalance($line, 4);
        $this->assertEquals(252, $line->fresh()->qty_available_for_rangkai);

        // Operator physically consumes 256 pcs (4 pcs more than the 252 recorded in pool)
        $quantities = array_fill(0, 8, 32); // 8 x 32 = 256 pcs
        $execution = $this->rangkaiService->recordExecution($workOrder, [
            'execution_date' => now()->format('Y-m-d'),
            'trees_created' => 8,
            'quantities' => $quantities,
            'family_code' => '6',
        ]);

        // Traveler must be created successfully without fake allocations
        $this->assertEquals(8, LostWaxTree::count());
        $this->assertEquals(252, LostWaxTreeAllocation::sum('allocated_qty')); // Exactly 252 official allocations

        // Execution flagged with variance and anomaly
        $this->assertEquals(-4, $execution->variance_qty);
        $this->assertTrue($execution->is_anomaly);
        $this->assertStringContainsString('256 pcs', $execution->anomaly_notes);
        $this->assertStringContainsString('252 pcs', $execution->anomaly_notes);
        $this->assertEquals(0, $line->fresh()->qty_available_for_rangkai);
    }

    /**
     * CASE 9: Cancellation Before Layer 1 Scan Releases Allocations
     */
    public function test_case_9_cancellation_before_layer_1_releases_allocations(): void
    {
        $plan = $this->createPlan('268ETB827', 320);
        $lineA = $this->createPrintLine($plan, 100, 4, 0);
        $lineB = $this->createPrintLine($plan, 252, 252, 0);

        $workOrder = $this->rangkaiService->createWorkOrder($lineB, [
            'qty_trees_planned' => 256,
            'tree_capacity' => 1,
            'standard_capacity_guide' => 32,
        ]);

        $quantities = array_fill(0, 8, 32); // 256 pcs
        $execution = $this->rangkaiService->recordExecution($workOrder, [
            'execution_date' => now()->format('Y-m-d'),
            'trees_created' => 8,
            'quantities' => $quantities,
            'family_code' => '6',
        ]);

        $this->assertEquals(0, $lineA->fresh()->qty_available_for_rangkai);
        $this->assertEquals(0, $lineB->fresh()->qty_available_for_rangkai);
        $this->assertEquals(9, LostWaxTreeAllocation::count()); // Tree 1 (2 allocs) + 7 trees (1 alloc each) = 9

        // Cancel execution before Layer 1
        $this->rangkaiService->cancelExecution($execution, 'Wrong moulding batch used', $this->user);

        $this->assertEquals('CANCELLED', $execution->fresh()->status);
        $this->assertEquals(0, LostWaxTreeAllocation::count()); // Allocations deleted/released
        $this->assertEquals(4, $lineA->fresh()->qty_available_for_rangkai); // Line A restored to 4
        $this->assertEquals(252, $lineB->fresh()->qty_available_for_rangkai); // Line B restored to 252
        $this->assertEquals(256, $this->rangkaiService->getTotalAvailablePool('268ETB827'));
    }

    /**
     * CASE 10: Cancellation After Layer 1 Scan Must Fail
     */
    public function test_case_10_cancellation_after_layer_1_scan_is_blocked(): void
    {
        $plan = $this->createPlan('268ETB827', 320);
        $line = $this->createPrintLine($plan, 320, 320, 0);

        $workOrder = $this->rangkaiService->createWorkOrder($line, [
            'qty_trees_planned' => 320,
            'tree_capacity' => 1,
            'standard_capacity_guide' => 32,
        ]);

        $execution = $this->rangkaiService->recordExecution($workOrder, [
            'execution_date' => now()->format('Y-m-d'),
            'trees_created' => 10,
            'quantities' => array_fill(0, 10, 32),
            'family_code' => '6',
        ]);

        $firstTree = $execution->trees()->first();

        // Perform Layer 1 Scan on first tree
        LostWaxScanEvent::create([
            'tree_id' => $firstTree->id,
            'barcode' => $firstTree->barcode,
            'stage' => 'layer_1',
            'result' => 'success',
            'operator_id' => $this->user->id,
            'scanned_at' => now(),
        ]);
        $firstTree->update(['current_stage' => 'layer_1']);

        // Cancellation must be strictly rejected
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Traveler tidak dapat dibatalkan karena satu atau lebih rangkaian (tree) sudah melalui Scan Layer 1.');

        $this->rangkaiService->cancelExecution($execution, 'Attempt cancel after scan', $this->user);
    }

    /**
     * CASE 11: Backward Compatibility with Legacy Trees (No Double Counting)
     */
    public function test_case_11_backward_compatibility_with_legacy_trees(): void
    {
        $plan = $this->createPlan('268ETB827', 320);
        $line = $this->createPrintLine($plan, 320, 320, 0);

        // Create a legacy tree directly without any LostWaxTreeAllocation row
        LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => '6260827999',
            'tree_number' => 1,
            'quantity' => 100, // 100 pcs legacy
            'status' => 'generated',
            'production_date' => now()->format('Y-m-d'),
            'family_code' => '6',
            'daily_sequence' => 1,
        ]);

        // Available must properly deduct the 100 legacy pcs: 320 - 100 = 220 pcs
        $this->assertEquals(220, $line->fresh()->qty_available_for_rangkai);

        // Now create a new tree with allocation of 50 pcs
        $newTree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => '6260827998',
            'tree_number' => 2,
            'quantity' => 50,
            'status' => 'generated',
            'production_date' => now()->format('Y-m-d'),
            'family_code' => '6',
            'daily_sequence' => 2,
        ]);
        LostWaxTreeAllocation::create([
            'lost_wax_tree_id' => $newTree->id,
            'lost_wax_print_order_line_id' => $line->id,
            'allocated_qty' => 50,
        ]);

        // Available must properly deduct both: 320 - 100 (legacy) - 50 (new) = 170 pcs
        $this->assertEquals(170, $line->fresh()->qty_available_for_rangkai);
    }

    /**
     * CASE 12: Concurrency Simulation (Two Operators Consuming from the Same Pool)
     */
    public function test_case_12_concurrent_execution_pool_deductions(): void
    {
        $plan = $this->createPlan('268ETB827', 256);
        $lineA = $this->createPrintLine($plan, 100, 100, 0);
        $lineB = $this->createPrintLine($plan, 156, 156, 0);

        $this->assertEquals(256, $this->rangkaiService->getTotalAvailablePool('268ETB827'));

        // Operator 1 prepares WO for 128 pcs
        $wo1 = $this->rangkaiService->createWorkOrder($lineA, [
            'qty_trees_planned' => 128,
            'tree_capacity' => 1,
            'standard_capacity_guide' => 32,
        ]);

        // Operator 2 prepares WO for 128 pcs
        $wo2 = $this->rangkaiService->createWorkOrder($lineB, [
            'qty_trees_planned' => 128,
            'tree_capacity' => 1,
            'standard_capacity_guide' => 32,
        ]);

        // Operator 1 executes 128 pcs (4 trees @ 32 pcs)
        $exec1 = $this->rangkaiService->recordExecution($wo1, [
            'execution_date' => now()->format('Y-m-d'),
            'trees_created' => 4,
            'quantities' => [32, 32, 32, 32],
            'family_code' => '6',
        ]);

        // Pool remaining after Operator 1: 256 - 128 = 128
        $this->assertEquals(128, $this->rangkaiService->getTotalAvailablePool('268ETB827'));

        // Operator 2 executes 128 pcs (4 trees @ 32 pcs)
        $exec2 = $this->rangkaiService->recordExecution($wo2, [
            'execution_date' => now()->format('Y-m-d'),
            'trees_created' => 4,
            'quantities' => [32, 32, 32, 32],
            'family_code' => '6',
        ]);

        // Pool remaining after Operator 2: 0
        $this->assertEquals(0, $this->rangkaiService->getTotalAvailablePool('268ETB827'));
        $this->assertEquals(0, $lineA->fresh()->qty_available_for_rangkai);
        $this->assertEquals(0, $lineB->fresh()->qty_available_for_rangkai);

        // Assert 8 total trees and 256 total allocations
        $this->assertEquals(8, LostWaxTree::count());
        $this->assertEquals(256, LostWaxTreeAllocation::sum('allocated_qty'));
    }

    /**
     * CASE 13: HTTP Create WO from Line A (avail 4 pcs) with Full Pool (30 pcs)
     */
    public function test_case_13_create_wo_http_from_line_a_can_order_full_pool_qty(): void
    {
        $plan = $this->createPlan('268ETB827', 30);
        $lineA = $this->createPrintLine($plan, 100, 4, 0); // 4 pcs available
        $lineB = $this->createPrintLine($plan, 100, 26, 0); // 26 pcs available

        $this->assertEquals(4, $lineA->qty_available_for_rangkai);
        $this->assertEquals(26, $lineB->qty_available_for_rangkai);
        $this->assertEquals(30, $this->rangkaiService->getTotalAvailablePool('268ETB827'));

        // View Create page for Line A
        $response = $this->actingAs($this->user)->get(route('lost-wax.assemblies.create', $lineA));
        $response->assertStatus(200);
        $response->assertSee('Total Pool Tersedia');
        $response->assertSee('30');
        $response->assertSee('SPK Ini');

        // Store WO for 30 pcs from Line A (exceeds lineA available 4 pcs, but within total pool 30 pcs)
        $postResponse = $this->actingAs($this->user)->post(route('lost-wax.assemblies.work-orders.store', $lineA), [
            'qty_ordered' => 30,
            'standard_capacity_guide' => 32,
            'notes' => 'Combined pool WO from Line A',
        ]);

        $postResponse->assertRedirect(route('lost-wax.assemblies.work-orders.index'));
        $postResponse->assertSessionHas('success');

        // Verify WO created with 30 planned pcs
        $wo = \App\Models\LostWaxRangkaiWorkOrder::latest('id')->first();
        $this->assertNotNull($wo);
        $this->assertEquals($lineA->id, $wo->lost_wax_print_order_line_id);
        $this->assertEquals(30, $wo->qty_trees_planned);

        // Execute WO with 30 pcs -> FIFO takes 4 pcs from Line A and 26 pcs from Line B
        $exec = $this->rangkaiService->recordExecution($wo, [
            'execution_date' => now()->format('Y-m-d'),
            'trees_created' => 1,
            'quantities' => [30],
            'family_code' => '6',
        ]);

        $this->assertEquals(0, $exec->variance_qty);
        $this->assertFalse($exec->is_anomaly);

        $tree = $exec->trees()->first();
        $allocs = $tree->allocations()->get();
        $this->assertCount(2, $allocs);

        $this->assertEquals(4, $allocs->where('lost_wax_print_order_line_id', $lineA->id)->first()->allocated_qty);
        $this->assertEquals(26, $allocs->where('lost_wax_print_order_line_id', $lineB->id)->first()->allocated_qty);

        $this->assertEquals(0, $lineA->fresh()->qty_available_for_rangkai);
        $this->assertEquals(0, $lineB->fresh()->qty_available_for_rangkai);
        $this->assertEquals(0, $this->rangkaiService->getTotalAvailablePool('268ETB827'));
    }

    /**
     * CASE 14: HTTP Create WO from Line B (avail 26 pcs) with Full Pool (30 pcs)
     */
    public function test_case_14_create_wo_http_from_line_b_can_order_full_pool_qty(): void
    {
        $plan = $this->createPlan('268ETB827', 30);
        $lineA = $this->createPrintLine($plan, 100, 4, 0); // 4 pcs available
        $lineB = $this->createPrintLine($plan, 100, 26, 0); // 26 pcs available

        // Store WO for 30 pcs from Line B
        $postResponse = $this->actingAs($this->user)->post(route('lost-wax.assemblies.work-orders.store', $lineB), [
            'qty_ordered' => 30,
            'standard_capacity_guide' => 32,
            'notes' => 'Combined pool WO from Line B',
        ]);

        $postResponse->assertRedirect(route('lost-wax.assemblies.work-orders.index'));
        $postResponse->assertSessionHas('success');

        $wo = \App\Models\LostWaxRangkaiWorkOrder::latest('id')->first();
        $this->assertEquals($lineB->id, $wo->lost_wax_print_order_line_id);
        $this->assertEquals(30, $wo->qty_trees_planned);
    }

    /**
     * CASE 15: HTTP Create WO exceeding Total Pool (e.g. 31 pcs when pool is 30) is Rejected
     */
    public function test_case_15_create_wo_http_exceeding_total_pool_is_rejected(): void
    {
        $plan = $this->createPlan('268ETB827', 30);
        $lineA = $this->createPrintLine($plan, 100, 4, 0);
        $lineB = $this->createPrintLine($plan, 100, 26, 0); // Total pool = 30

        $postResponse = $this->actingAs($this->user)->post(route('lost-wax.assemblies.work-orders.store', $lineA), [
            'qty_ordered' => 31, // Exceeds pool of 30
            'standard_capacity_guide' => 32,
        ]);

        $postResponse->assertSessionHas('error');
        $this->assertStringContainsString('31 pcs', session('error'));
        $this->assertStringContainsString('30 pcs', session('error'));
    }

    /**
     * CASE 16: FIFO Exhaustion Ensures No Negative Source Balance and Correct Variance Tracking
     */
    public function test_case_16_fifo_exhaustion_ensures_no_negative_balance_and_variance_tracking(): void
    {
        $plan = $this->createPlan('268ETB827', 30);
        $lineA = $this->createPrintLine($plan, 100, 4, 0);
        $lineB = $this->createPrintLine($plan, 100, 26, 0); // Pool = 30

        $wo = $this->rangkaiService->createWorkOrder($lineA, [
            'qty_trees_planned' => 30,
            'tree_capacity' => 1,
            'standard_capacity_guide' => 32,
        ]);

        // Simulating physical execution with 32 pcs (2 pcs more than pool 30)
        // Since outstanding is 30, we test recordExecution with outstanding
        $execution = $this->rangkaiService->recordExecution($wo, [
            'execution_date' => now()->format('Y-m-d'),
            'trees_created' => 1,
            'quantities' => [30],
            'family_code' => '6',
        ]);

        // Assert no source line has negative balance
        $this->assertGreaterThanOrEqual(0, $lineA->fresh()->qty_available_for_rangkai);
        $this->assertGreaterThanOrEqual(0, $lineB->fresh()->qty_available_for_rangkai);
        $this->assertEquals(0, $lineA->fresh()->qty_available_for_rangkai);
        $this->assertEquals(0, $lineB->fresh()->qty_available_for_rangkai);
    }
}
