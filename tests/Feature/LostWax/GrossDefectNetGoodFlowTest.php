<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintExecution;
use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxTree;
use App\Models\LostWaxTreeAllocation;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWaxQualityService;
use App\Services\PrintExecutionService;
use App\Services\RangkaiExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrossDefectNetGoodFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected PrintExecutionService $printService;

    protected RangkaiExecutionService $rangkaiService;

    protected LostWaxQualityService $qualityService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'name' => 'PPIC Operator',
            'email' => 'operator@peroniks.com',
        ]);

        $this->printService = app(PrintExecutionService::class);
        $this->rangkaiService = app(RangkaiExecutionService::class);
        $this->qualityService = app(LostWaxQualityService::class);
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'code' => '268L651',
            'customer' => 'PT PERONI INTI',
            'item_code' => '4.101105K.A0020',
            'item_name' => 'SS304 SQUARE DN 40',
            'aisi' => '304',
            'size' => 'DN 40',
            'weight' => 1.25,
            'po_number' => 'PO-2026-940',
            'po_quantity' => 940,
            'qty_planned' => 1019,
            'qty_remaining' => 1019,
            'line_number' => 1,
            'status' => 'planning',
            'is_closed' => false,
        ], $attributes));
    }

    protected function createPrintLine(ProductionPlan $plan, string $docNumber, int $ordered): LostWaxPrintOrderLine
    {
        $order = LostWaxPrintOrder::create([
            'print_order_number' => $docNumber,
            'scheduled_date' => now()->format('Y-m-d'),
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        return $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => $ordered,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'standard_tree_capacity' => 20,
        ]);
    }

    /**
     * 1. CETAK: Gross 210, Defect 10 -> Net Good = 200
     */
    public function test_cetak_gross_defect_calculates_net_good_and_persists(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintLine($plan, 'PC-20260826-0007', 204);

        $execution = $this->printService->record($line, [
            'qty_gross_output' => 210,
            'qty_defect' => 10,
            'execution_date' => '2026-08-26',
            'status' => 'FINALIZED',
            'recorded_by' => $this->user->id,
        ]);

        $this->assertSame(210, $execution->qty_gross_output);
        $this->assertSame(10, $execution->qty_defect);
        $this->assertSame(200, $execution->qty_good);
        $this->assertSame(210, $execution->gross_output);

        $line->refresh();
        $this->assertSame(200, $line->qty_executed_good);
        $this->assertSame(10, $line->qty_executed_defect);
        $this->assertSame(200, $line->qty_available_for_rangkai);
    }

    /**
     * 2. VALIDATION: defect > gross -> reject, negative values -> reject
     */
    public function test_validation_rejects_defect_exceeding_gross_and_negative_quantities(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintLine($plan, 'PC-TEST-001', 100);

        // Defect > Gross
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak boleh melebihi');

        $this->printService->record($line, [
            'qty_gross_output' => 100,
            'qty_defect' => 105,
            'execution_date' => now()->format('Y-m-d'),
        ]);
    }

    public function test_validation_rejects_negative_quantities(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintLine($plan, 'PC-TEST-002', 100);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak boleh negatif');

        $this->printService->record($line, [
            'qty_gross_output' => -5,
            'qty_defect' => 0,
            'execution_date' => now()->format('Y-m-d'),
        ]);
    }

    /**
     * 3. MATERIAL POOL: Gross 210, Defect 10, Net 200, Allocated 144 -> Available 56
     */
    public function test_material_pool_deducts_allocation_from_net_good(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintLine($plan, 'PC-20260826-0007', 204);

        $this->printService->record($line, [
            'qty_gross_output' => 210,
            'qty_defect' => 10,
            'execution_date' => '2026-08-26',
            'status' => 'FINALIZED',
            'recorded_by' => $this->user->id,
        ]);

        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => 'F3260826001',
            'tree_number' => 1,
            'quantity' => 144,
            'status' => 'generated',
            'production_date' => '2026-08-26',
            'family_code' => 'F3',
            'daily_sequence' => 1,
        ]);

        LostWaxTreeAllocation::create([
            'lost_wax_tree_id' => $tree->id,
            'lost_wax_print_order_line_id' => $line->id,
            'allocated_qty' => 144,
        ]);

        $this->assertSame(56, $line->fresh(['treeAllocations.tree'])->qty_available_for_rangkai);
    }

    /**
     * 4. ZERO DEFECT REGRESSION: Gross 210, Defect 0 -> Net 210
     */
    public function test_zero_defect_yields_full_gross_as_net_good(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintLine($plan, 'PC-20260827-0003', 204);

        $execution = $this->printService->record($line, [
            'qty_gross_output' => 210,
            'qty_defect' => 0,
            'execution_date' => '2026-08-27',
            'status' => 'FINALIZED',
            'recorded_by' => $this->user->id,
        ]);

        $this->assertSame(210, $execution->qty_good);
        $this->assertSame(210, $line->fresh()->qty_available_for_rangkai);
    }

    /**
     * 5. HISTORICAL NET RECORD NORMALIZATION: good = 316, defect = 4 -> gross = 320, net = 316 (no double deduction)
     */
    public function test_normalization_command_handles_net_record_correctly(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintLine($plan, 'PC-NET-TEST', 320);

        // Simulate legacy un-normalized record where operator entered net good = 316, defect = 4
        $exec = LostWaxPrintExecution::create([
            'lost_wax_print_order_line_id' => $line->id,
            'execution_date' => now()->format('Y-m-d'),
            'qty_gross_output' => null,
            'qty_good' => 316,
            'qty_defect' => 4,
            'status' => 'FINALIZED',
            'recorded_at' => now(),
        ]);

        $this->artisan('lost-wax:normalize-gross')->assertSuccessful();

        $exec->refresh();
        $this->assertSame(320, $exec->qty_gross_output);
        $this->assertSame(316, $exec->qty_good);
        $this->assertSame(4, $exec->qty_defect);
        $this->assertSame(316, $line->fresh()->qty_available_for_rangkai);
    }

    /**
     * 6. HISTORICAL GROSS RECORD NORMALIZATION: old good = 210, defect = 10 -> normalized gross = 210, net = 200
     */
    public function test_normalization_command_normalizes_gross_record_correctly(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintLine($plan, 'PC-20260826-0007', 204);

        // Simulate legacy un-normalized record where operator entered gross = 210 into good field
        $exec = LostWaxPrintExecution::create([
            'lost_wax_print_order_line_id' => $line->id,
            'execution_date' => '2026-08-26',
            'qty_gross_output' => null,
            'qty_good' => 210,
            'qty_defect' => 10,
            'status' => 'FINALIZED',
            'recorded_at' => now(),
        ]);

        $this->artisan('lost-wax:normalize-gross')->assertSuccessful();

        $exec->refresh();
        $this->assertSame(210, $exec->qty_gross_output);
        $this->assertSame(200, $exec->qty_good);
        $this->assertSame(10, $exec->qty_defect);
        $this->assertSame(200, $line->fresh()->qty_available_for_rangkai);
    }

    /**
     * 7, 8 & 9. CRITICAL UAT 268L651 FULL MASS BALANCE & PRODUCTION STATUS
     * Gross = 840, Defect = 10, Net = 830, Allocated = 144, Standby = 686.
     */
    public function test_critical_uat_case_268l651_mass_balance_and_production_status(): void
    {
        $plan = $this->createPlan();

        $line1 = $this->createPrintLine($plan, 'PC-20260826-0007', 204);
        $line2 = $this->createPrintLine($plan, 'PC-20260827-0003', 204);
        $line3 = $this->createPrintLine($plan, 'PC-20260827-0004', 204);
        $line4 = $this->createPrintLine($plan, 'PC-20260829-0002', 204);

        $this->printService->record($line1, ['qty_gross_output' => 210, 'qty_defect' => 10, 'execution_date' => '2026-08-26', 'status' => 'FINALIZED', 'recorded_by' => $this->user->id]);
        $this->printService->record($line2, ['qty_gross_output' => 210, 'qty_defect' => 0, 'execution_date' => '2026-08-27', 'status' => 'FINALIZED', 'recorded_by' => $this->user->id]);
        $this->printService->record($line3, ['qty_gross_output' => 210, 'qty_defect' => 0, 'execution_date' => '2026-08-27', 'status' => 'FINALIZED', 'recorded_by' => $this->user->id]);
        $this->printService->record($line4, ['qty_gross_output' => 210, 'qty_defect' => 0, 'execution_date' => '2026-08-29', 'status' => 'FINALIZED', 'recorded_by' => $this->user->id]);

        // 1. Initial pool check
        $this->assertSame(830, $this->rangkaiService->getTotalAvailablePool('268L651'));

        // 2. Consume 144 pcs into Rangkai
        $workOrder = $this->rangkaiService->createWorkOrder($line1, [
            'qty_trees_planned' => 8,
            'tree_capacity' => 20,
        ]);

        $this->rangkaiService->recordExecution($workOrder, [
            'execution_date' => '2026-08-30',
            'trees_created' => 8,
            'quantities' => [20, 20, 20, 20, 20, 20, 20, 4], // sum = 144
            'family_code' => 'F3',
        ]);

        // 3. FIFO balances
        $this->assertSame(56, $line1->fresh(['treeAllocations.tree'])->qty_available_for_rangkai);
        $this->assertSame(210, $line2->fresh(['treeAllocations.tree'])->qty_available_for_rangkai);
        $this->assertSame(210, $line3->fresh(['treeAllocations.tree'])->qty_available_for_rangkai);
        $this->assertSame(210, $line4->fresh(['treeAllocations.tree'])->qty_available_for_rangkai);
        $this->assertSame(686, $this->rangkaiService->getTotalAvailablePool('268L651'));

        // 4. Production Status Breakdown
        $breakdown = $this->qualityService->getProductionPlanQuantityBreakdown($plan);

        $this->assertSame(830, $breakdown['q_print_good'], 'Net Good Cetak must be 830');
        $this->assertSame(10, $breakdown['q_print_defect'], 'Defect Cetak must be 10');
        $this->assertSame(144, $breakdown['q_active_trees_gross'], 'Active Trees Gross must be 144');
        $this->assertSame(686, $breakdown['q_standby'], 'Standby Pool must be 686 (830 - 144)');
        $this->assertSame(830, $breakdown['q_usable'], 'Canonical Usable must be 830 (830 - 0 tree defect)');
    }

    /**
     * 10. HTTP ENDPOINT OUTCOME RECORDING WITH GROSS AND DEFECT
     */
    public function test_outcome_http_endpoint_stores_gross_and_calculates_net_good(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintLine($plan, 'PC-HTTP-001', 204);

        $response = $this->actingAs($this->user)->postJson(
            route('lost-wax.outcomes.lines.execution.store', $line),
            [
                'qty_gross_output' => 210,
                'qty_defect' => 10,
                'execution_date' => now()->format('Y-m-d'),
                'status' => 'FINALIZED',
                'notes' => 'Tested via HTTP JSON',
            ]
        );

        $response->assertStatus(200)->assertJson(['success' => true]);

        $line->refresh();
        $this->assertSame(200, $line->qty_executed_good);
        $this->assertSame(10, $line->qty_executed_defect);
        $this->assertSame(200, $line->qty_available_for_rangkai);
    }
}
