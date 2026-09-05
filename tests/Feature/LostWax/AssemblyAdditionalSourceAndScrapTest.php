<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxRangkaiWorkOrder;
use App\Models\LostWaxTree;
use App\Models\LostWaxTreeAllocation;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\PrintExecutionService;
use App\Services\RangkaiExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AssemblyAdditionalSourceAndScrapTest extends TestCase
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

    private function createPlan(string $code = '268ETB827', int $qty = 119): ProductionPlan
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

    private function createPrintOrderWithExecutedLine(string $code = '268ETB827', int $plannedQty = 119, int $goodQty = 119): array
    {
        $plan = $this->createPlan($code, $plannedQty);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-TEST-'.$code,
            'scheduled_date' => now()->toDateString(),
            'order_date' => now()->toDateString(),
            'status' => 'ISSUED',
            'notes' => 'Test',
            'created_by' => $this->user->id,
        ]);

        $line = LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $order->id,
            'production_plan_id' => $plan->id,
            'code' => $code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'aisi' => $plan->aisi,
            'size' => $plan->size,
            'weight' => $plan->weight,
            'qty_ordered' => $plannedQty,
            'qty_executed_good' => $goodQty,
            'qty_executed_scrap' => 0,
            'qty_excess_closed' => 0,
        ]);

        return [$order, $line, $plan];
    }

    private function createRangkaiWorkOrder(LostWaxPrintOrderLine $line, int $plannedQty = 119, int $capacity = 20): LostWaxRangkaiWorkOrder
    {
        return LostWaxRangkaiWorkOrder::create([
            'lost_wax_print_order_line_id' => $line->id,
            'rangkai_order_number' => 'RWO-TEST-'.$line->code,
            'qty_trees_planned' => $plannedQty,
            'tree_capacity' => 1,
            'standard_capacity_guide' => $capacity,
            'require_layer_7' => false,
            'status' => 'OPEN',
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * Test A: Normal Assembly
     * 119 available -> 119 assembly -> PASS
     */
    public function test_a_normal_assembly_passes()
    {
        [$order, $line] = $this->createPrintOrderWithExecutedLine('268ETB827', 119, 119);
        $wo = $this->createRangkaiWorkOrder($line, 119, 20);

        $this->assertEquals(119, $line->qty_available_for_rangkai);
        $this->assertEquals(119, $wo->qty_outstanding);

        $quantities = [20, 20, 20, 20, 20, 19]; // total 119
        $execution = $this->rangkaiService->recordExecution($wo, [
            'execution_date' => now()->toDateString(),
            'trees_created' => 6,
            'quantities' => $quantities,
            'operator_name' => 'operator1',
            'recorded_by' => $this->user->id,
        ]);

        $this->assertNotNull($execution);
        $this->assertEquals(119, $execution->total_pcs);
        $this->assertEquals(0, $execution->additional_source_qty);
        $this->assertNull($execution->additional_source_line_id);
        $this->assertEquals(0, $wo->fresh()->qty_outstanding);
        $this->assertEquals(0, $line->fresh()->qty_available_for_rangkai);
    }

    /**
     * Test B: Over available without additional source
     * 119 available -> 120 assembly -> REJECT
     */
    public function test_b_over_available_without_source_is_rejected()
    {
        [$order, $line] = $this->createPrintOrderWithExecutedLine('268ETB827', 119, 119);
        $wo = $this->createRangkaiWorkOrder($line, 119, 20);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Total kuantitas rangkai (120 pcs) melebihi ketersediaan (119 pcs) sebesar 1 pcs. Harap cantumkan Sumber Lilin Tambahan.');

        $quantities = [20, 20, 20, 20, 20, 20]; // total 120
        $this->rangkaiService->recordExecution($wo, [
            'execution_date' => now()->toDateString(),
            'trees_created' => 6,
            'quantities' => $quantities,
            'operator_name' => 'operator1',
            'recorded_by' => $this->user->id,
        ]);
    }

    /**
     * Test C: Over available with valid additional source
     * 119 available -> 120 assembly, Additional source = 1 -> PASS
     * Allocation created for both main line (119 pcs) and additional source line (1 pcs)
     * Planned WO quantity remains 119 pcs.
     */
    public function test_c_over_available_with_valid_source_passes_and_traces_ledger()
    {
        [$order1, $line1] = $this->createPrintOrderWithExecutedLine('268ETB827', 119, 119);
        [$order2, $line2] = $this->createPrintOrderWithExecutedLine('268ETB828', 100, 100);
        $wo = $this->createRangkaiWorkOrder($line1, 119, 20);

        $quantities = [20, 20, 20, 20, 20, 20]; // total 120
        $execution = $this->rangkaiService->recordExecution($wo, [
            'execution_date' => now()->toDateString(),
            'trees_created' => 6,
            'quantities' => $quantities,
            'operator_name' => 'operator1',
            'recorded_by' => $this->user->id,
            'additional_source_line_id' => $line2->id,
            'additional_source_qty' => 1,
            'additional_source_reason' => 'Lilin sisa produksi PC-TEST-268ETB828',
        ]);

        $this->assertNotNull($execution);
        $this->assertEquals(120, $execution->total_pcs);
        $this->assertEquals(1, $execution->additional_source_qty);
        $this->assertEquals($line2->id, $execution->additional_source_line_id);
        $this->assertEquals('268ETB828', $execution->additional_source_code);
        $this->assertEquals('Lilin sisa produksi PC-TEST-268ETB828', $execution->additional_source_reason);

        // Check Planned WO quantity did not change!
        $this->assertEquals(119, $wo->fresh()->qty_planned_pcs);
        $this->assertEquals(0, $wo->fresh()->qty_outstanding);

        // Check main line available is 0 (119 consumed)
        $this->assertEquals(0, $line1->fresh()->qty_available_for_rangkai);

        // Check additional line available is 99 (1 consumed)
        $this->assertEquals(99, $line2->fresh()->qty_available_for_rangkai);

        // Check tree allocations
        $treeIds = $execution->trees->pluck('id');
        $allocations = LostWaxTreeAllocation::whereIn('lost_wax_tree_id', $treeIds)->get();

        $mainAllocated = $allocations->where('lost_wax_print_order_line_id', $line1->id)->sum('allocated_qty');
        $additionalAllocated = $allocations->where('lost_wax_print_order_line_id', $line2->id)->sum('allocated_qty');

        $this->assertEquals(119, $mainAllocated);
        $this->assertEquals(1, $additionalAllocated);
        $this->assertEquals(120, $mainAllocated + $additionalAllocated);
    }

    /**
     * Test D: Additional source mismatch
     * 119 available -> 120 assembly (diff = 1), Additional source qty = 2 -> REJECT
     */
    public function test_d_additional_source_quantity_mismatch_is_rejected()
    {
        [$order1, $line1] = $this->createPrintOrderWithExecutedLine('268ETB827', 119, 119);
        [$order2, $line2] = $this->createPrintOrderWithExecutedLine('268ETB828', 100, 100);
        $wo = $this->createRangkaiWorkOrder($line1, 119, 20);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Kuantitas sumber tambahan (2 pcs) harus sama persis dengan selisih kuantitas (1 pcs).');

        $quantities = [20, 20, 20, 20, 20, 20]; // total 120
        $this->rangkaiService->recordExecution($wo, [
            'execution_date' => now()->toDateString(),
            'trees_created' => 6,
            'quantities' => $quantities,
            'operator_name' => 'operator1',
            'recorded_by' => $this->user->id,
            'additional_source_line_id' => $line2->id,
            'additional_source_qty' => 2, // mismatch: requested 2 when diff is 1
            'additional_source_reason' => 'Alasan mismatch',
        ]);
    }

    /**
     * Test E: Invalid / unavailable additional source
     * 119 available -> 120 assembly, Additional source = 1 but source balance = 0 -> REJECT
     */
    public function test_e_invalid_or_unavailable_additional_source_is_rejected()
    {
        [$order1, $line1] = $this->createPrintOrderWithExecutedLine('268ETB827', 119, 119);
        [$order2, $line2] = $this->createPrintOrderWithExecutedLine('268ETB828', 100, 0); // available = 0
        $wo = $this->createRangkaiWorkOrder($line1, 119, 20);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Kuantitas lilin tersedia pada sumber tambahan Kode 268ETB828 (0 pcs) tidak mencukupi untuk tambahan 1 pcs.');

        $quantities = [20, 20, 20, 20, 20, 20]; // total 120
        $this->rangkaiService->recordExecution($wo, [
            'execution_date' => now()->toDateString(),
            'trees_created' => 6,
            'quantities' => $quantities,
            'operator_name' => 'operator1',
            'recorded_by' => $this->user->id,
            'additional_source_line_id' => $line2->id,
            'additional_source_qty' => 1,
            'additional_source_reason' => 'Alasan',
        ]);
    }

    /**
     * Test F: Scrap / Close Excess
     * 4 pcs available -> scrap 4 -> balance berkurang, 4 pcs tidak dapat digunakan lagi, audit trail tersimpan
     */
    public function test_f_scrap_closes_balance_and_records_audit_trail()
    {
        [$order, $line] = $this->createPrintOrderWithExecutedLine('268ETB827', 100, 100);
        $wo = $this->createRangkaiWorkOrder($line, 96, 20);

        // Execute 96 pcs
        $this->rangkaiService->recordExecution($wo, [
            'execution_date' => now()->toDateString(),
            'trees_created' => 5,
            'quantities' => [20, 20, 20, 20, 16],
            'operator_name' => 'operator1',
            'recorded_by' => $this->user->id,
        ]);

        $this->assertEquals(4, $line->fresh()->qty_available_for_rangkai);

        // Operator scraps remaining 4 pcs
        $closedLine = $this->rangkaiService->closeExcessBalance(
            $line->fresh(),
            4,
            'Lilin retak/rusak saat handling',
            $this->user
        );

        $this->assertEquals(4, $closedLine->qty_excess_closed);
        $this->assertEquals('Lilin retak/rusak saat handling', $closedLine->excess_closure_reason);
        $this->assertEquals($this->user->id, $closedLine->excess_closed_by);
        $this->assertNotNull($closedLine->excess_closed_at);
        $this->assertEquals(0, $closedLine->qty_available_for_rangkai);

        // Verify cannot be used as additional source
        [$order2, $line2] = $this->createPrintOrderWithExecutedLine('268ETB828', 10, 10);
        $wo2 = $this->createRangkaiWorkOrder($line2, 10, 10);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Kuantitas lilin tersedia pada sumber tambahan Kode 268ETB827 (0 pcs) tidak mencukupi untuk tambahan 1 pcs.');

        $this->rangkaiService->recordExecution($wo2, [
            'execution_date' => now()->toDateString(),
            'trees_created' => 1,
            'quantities' => [11],
            'operator_name' => 'operator1',
            'recorded_by' => $this->user->id,
            'additional_source_line_id' => $line->id,
            'additional_source_qty' => 1,
            'additional_source_reason' => 'Mencoba pakai lilin yang sudah diafkir',
        ]);
    }

    /**
     * Test G: Cancel execution with additional source
     * 119 normal + 1 additional source -> Cancel execution -> Both balances restored cleanly without double credit
     */
    public function test_g_cancel_execution_with_additional_source_restores_both_ledgers()
    {
        [$order1, $line1] = $this->createPrintOrderWithExecutedLine('268ETB827', 119, 119);
        [$order2, $line2] = $this->createPrintOrderWithExecutedLine('268ETB828', 100, 100);
        $wo = $this->createRangkaiWorkOrder($line1, 119, 20);

        $quantities = [20, 20, 20, 20, 20, 20]; // total 120
        $execution = $this->rangkaiService->recordExecution($wo, [
            'execution_date' => now()->toDateString(),
            'trees_created' => 6,
            'quantities' => $quantities,
            'operator_name' => 'operator1',
            'recorded_by' => $this->user->id,
            'additional_source_line_id' => $line2->id,
            'additional_source_qty' => 1,
            'additional_source_reason' => 'Lilin sisa produksi PC-TEST-268ETB828',
        ]);

        $this->assertEquals(0, $line1->fresh()->qty_available_for_rangkai);
        $this->assertEquals(99, $line2->fresh()->qty_available_for_rangkai);
        $this->assertEquals(0, $wo->fresh()->qty_outstanding);

        // Cancel the execution
        $cancelledExecution = $this->rangkaiService->cancelExecution(
            $execution,
            'Salah jumlah input operator',
            $this->user
        );

        $this->assertTrue($cancelledExecution->is_cancelled);
        $this->assertEquals('Salah jumlah input operator', $cancelledExecution->cancellation_reason);

        // Verify both balances are restored exactly
        $this->assertEquals(119, $line1->fresh()->qty_available_for_rangkai);
        $this->assertEquals(100, $line2->fresh()->qty_available_for_rangkai);
        $this->assertEquals(119, $wo->fresh()->qty_outstanding);

        // Verify trees are marked cancelled
        $trees = LostWaxTree::where('lost_wax_rangkai_execution_id', $execution->id)->get();
        foreach ($trees as $tree) {
            $this->assertEquals('cancelled', $tree->status);
        }

        // Verify allocations are deleted/released
        $allocationsCount = LostWaxTreeAllocation::whereIn('lost_wax_tree_id', $trees->pluck('id'))->count();
        $this->assertEquals(0, $allocationsCount);
    }

    /**
     * Test HTTP Controller Flow for Execution with Additional Source and Scrap
     */
    public function test_http_controller_store_execution_with_additional_source_and_scrap()
    {
        $this->actingAs($this->user);

        [$order1, $line1] = $this->createPrintOrderWithExecutedLine('268ETB827', 119, 119);
        [$order2, $line2] = $this->createPrintOrderWithExecutedLine('268ETB828', 50, 50);
        $wo = $this->createRangkaiWorkOrder($line1, 119, 20);

        // Submit execution over available with additional source via HTTP POST
        $response = $this->post(route('lost-wax.assemblies.work-orders.execution.store', $wo), [
            'execution_date' => now()->toDateString(),
            'trees_created' => 6,
            'quantities' => [20, 20, 20, 20, 20, 20],
            'family_code' => '268ETB827',
            'operator_name' => 'Budi',
            'additional_source_line_id' => $line2->id,
            'additional_source_qty' => 1,
            'additional_source_reason' => 'Menggunakan 1 pcs sisa lilin dari line 268ETB828',
        ]);

        $response->assertRedirect(route('lost-wax.assemblies.work-orders.show', $wo));
        $response->assertSessionHas('success');

        $this->assertEquals(0, $line1->fresh()->qty_available_for_rangkai);
        $this->assertEquals(49, $line2->fresh()->qty_available_for_rangkai);

        // Now scrap the remaining 49 pcs on line 2 via HTTP POST
        $scrapResponse = $this->post(route('lost-wax.assemblies.lines.close-excess', $line2), [
            'qty_to_close' => 49,
            'excess_closure_reason' => 'Afkir sisa produksi lilin tidak terpakai',
        ]);

        $scrapResponse->assertRedirect();
        $scrapResponse->assertSessionHas('success');
        $this->assertEquals(0, $line2->fresh()->qty_available_for_rangkai);
        $this->assertEquals(49, $line2->fresh()->qty_excess_closed);
        $this->assertEquals('Afkir sisa produksi lilin tidak terpakai', $line2->fresh()->excess_closure_reason);
    }
}
