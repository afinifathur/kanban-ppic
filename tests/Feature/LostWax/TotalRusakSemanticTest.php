<?php

namespace Tests\Feature\LostWax;

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

class TotalRusakSemanticTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected PrintExecutionService $printService;

    protected RangkaiExecutionService $rangkaiService;

    protected LostWaxQualityService $qualityService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'name' => 'Admin PPIC',
            'email' => 'admin@peroniks.com',
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
            'created_by' => $this->adminUser->id,
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
     * Test 1 — Cetak defect only: Gross = 210, Defect = 10, Net = 200, Tot Rsk = 10
     */
    public function test_1_cetak_defect_only_yields_correct_tot_rsk(): void
    {
        $plan = $this->createPlan(['code' => 'TEST-01']);
        $line = $this->createPrintLine($plan, 'PC-T1', 204);

        $this->printService->record($line, [
            'qty_gross_output' => 210,
            'qty_defect' => 10,
            'execution_date' => now()->format('Y-m-d'),
            'status' => 'FINALIZED',
            'recorded_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $response->assertOk();

        $targetRow = collect($response->viewData('rows'))->firstWhere('code', 'TEST-01');
        $this->assertNotNull($targetRow);

        $this->assertEquals(200, $targetRow['ctk_display'], 'CTK Standby is Net Good 200');
        $this->assertEquals(10, $targetRow['r_ctk_display'], 'CTK R indicator is 10');
        $this->assertEquals(10, $targetRow['overall_defect'], 'Tot Rsk is 10 (from Cetak)');
        $this->assertEquals(200, $targetRow['total_lap'], 'Total remains Net Good 200 without double-deduction');
    }

    /**
     * Test 2 — Multiple downstream defects: CTK = 10, RGKI = 5, L3 = 3, Oven = 2 -> Tot Rsk = 20
     */
    public function test_2_multiple_downstream_defects_aggregate_accurately(): void
    {
        $plan = $this->createPlan(['code' => 'TEST-02']);
        $line = $this->createPrintLine($plan, 'PC-T2', 500);

        // Cetak: Gross = 500, Defect = 10, Net Good = 490
        $this->printService->record($line, [
            'qty_gross_output' => 500,
            'qty_defect' => 10,
            'execution_date' => now()->format('Y-m-d'),
            'status' => 'FINALIZED',
            'recorded_by' => $this->adminUser->id,
        ]);

        // Tree 1: Assembly (sebelum_scan), gross 50, assembly defect 5 -> net 45
        $t1 = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => 'F3010101001',
            'tree_number' => 1,
            'quantity' => 50,
            'status' => 'generated',
            'current_stage' => null,
            'production_date' => now()->format('Y-m-d'),
            'family_code' => 'F3',
            'daily_sequence' => 1,
        ]);
        $this->qualityService->recordDefect($t1, 'assembly', 5, 'pola_rusak');

        // Tree 2: Layer 3, gross 50, layer 3 defect 3 -> net 47
        $t2 = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => 'F3010101002',
            'tree_number' => 2,
            'quantity' => 50,
            'status' => 'in_process',
            'current_stage' => 'layer_3',
            'production_date' => now()->format('Y-m-d'),
            'family_code' => 'F3',
            'daily_sequence' => 2,
        ]);
        $this->qualityService->recordDefect($t2, 'layer_3', 3, 'retak_layer');

        // Tree 3: Oven, gross 50, oven defect 2 -> net 48
        $t3 = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => 'F3010101003',
            'tree_number' => 3,
            'quantity' => 50,
            'status' => 'completed',
            'current_stage' => 'oven',
            'production_date' => now()->format('Y-m-d'),
            'family_code' => 'F3',
            'daily_sequence' => 3,
        ]);
        $this->qualityService->recordDefect($t3, 'oven', 2, 'pecah_bakar');

        // Standby CTK = 490 Net Good - 150 Active Trees Gross = 340
        // RGKI Net = 45
        // L3 Net = 47
        // Oven Net = 48
        // Total Distributed = 340 + 45 + 47 + 48 = 480
        // Tot Rsk = 10 (Cetak) + 5 (RGKI) + 3 (L3) + 2 (Oven) = 20

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $response->assertOk();

        $targetRow = collect($response->viewData('rows'))->firstWhere('code', 'TEST-02');
        $this->assertNotNull($targetRow);

        $this->assertEquals(340, $targetRow['ctk_display'], 'CTK Standby is 340');
        $this->assertEquals(10, $targetRow['r_ctk_display'], 'CTK R is 10');
        $this->assertEquals(45, $targetRow['rgki_display'], 'RGKI is 45');
        $this->assertEquals(5, $targetRow['r_rgki_display'], 'RGKI R is 5');
        $this->assertEquals(47, $targetRow['layer_3'], 'L3 is 47');
        $this->assertEquals(3, $targetRow['r_layer_3'], 'L3 R is 3');
        $this->assertEquals(48, $targetRow['oven_qty'], 'Oven is 48');
        $this->assertEquals(2, $targetRow['r_oven'], 'Oven R is 2');

        $this->assertEquals(20, $targetRow['overall_defect'], 'Tot Rsk = 10 + 5 + 3 + 2 = 20');
        $this->assertEquals(480, $targetRow['total_lap'], 'Total equals 480');
    }

    /**
     * Test 3 — Zero defects: Tot Rsk = 0
     */
    public function test_3_zero_defects_yields_zero_tot_rsk(): void
    {
        $plan = $this->createPlan(['code' => 'TEST-03']);
        $line = $this->createPrintLine($plan, 'PC-T3', 200);

        $this->printService->record($line, [
            'qty_gross_output' => 200,
            'qty_defect' => 0,
            'execution_date' => now()->format('Y-m-d'),
            'status' => 'FINALIZED',
            'recorded_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $response->assertOk();

        $targetRow = collect($response->viewData('rows'))->firstWhere('code', 'TEST-03');
        $this->assertNotNull($targetRow);

        $this->assertEquals(0, $targetRow['overall_defect'], 'Tot Rsk is 0 when no defect exists');
        $this->assertEquals(200, $targetRow['total_lap'], 'Total is 200');
    }

    /**
     * Test 4 — No double counting: Explicitly verify that the same defect record is counted exactly once.
     */
    public function test_4_no_double_counting_across_breakdown_and_controller(): void
    {
        $plan = $this->createPlan(['code' => 'TEST-04']);
        $line = $this->createPrintLine($plan, 'PC-T4', 300);

        $this->printService->record($line, [
            'qty_gross_output' => 300,
            'qty_defect' => 7,
            'execution_date' => now()->format('Y-m-d'),
            'status' => 'FINALIZED',
            'recorded_by' => $this->adminUser->id,
        ]);

        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => 'F3040404001',
            'tree_number' => 1,
            'quantity' => 100,
            'status' => 'in_process',
            'current_stage' => 'layer_1',
            'production_date' => now()->format('Y-m-d'),
            'family_code' => 'F3',
            'daily_sequence' => 1,
        ]);
        $this->qualityService->recordDefect($tree, 'layer_1', 4, 'cacat_layer_1');

        $breakdown = $this->qualityService->getProductionPlanQuantityBreakdown($plan);

        $this->assertEquals(7, $breakdown['q_print_defect'], 'Print defect is 7');
        $this->assertEquals(4, $breakdown['q_tree_defect'], 'Tree defect is 4');
        $this->assertEquals(11, $breakdown['q_total_defect'], 'Total defect is 11 (7 + 4)');

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $targetRow = collect($response->viewData('rows'))->firstWhere('code', 'TEST-04');

        $this->assertEquals(11, $targetRow['overall_defect'], 'Tot Rsk in row equals exactly 11 without double counting');
    }

    /**
     * Test 5 — 268L651 Acceptance Test
     * Total = 830, Tot Rsk = 10, CTK R = 10, Material Pool = 686, Standby = 686
     */
    public function test_5_critical_uat_268l651_total_and_tot_rsk_exact(): void
    {
        $plan = $this->createPlan(['code' => '268L651', 'po_quantity' => 940, 'qty_planned' => 1019]);

        $l1 = $this->createPrintLine($plan, 'PC-20260826-0007', 204);
        $l2 = $this->createPrintLine($plan, 'PC-20260827-0003', 204);
        $l3 = $this->createPrintLine($plan, 'PC-20260827-0004', 204);
        $l4 = $this->createPrintLine($plan, 'PC-20260829-0002', 204);

        $this->printService->record($l1, ['qty_gross_output' => 210, 'qty_defect' => 10, 'execution_date' => '2026-08-26', 'status' => 'FINALIZED', 'recorded_by' => $this->adminUser->id]);
        $this->printService->record($l2, ['qty_gross_output' => 210, 'qty_defect' => 0, 'execution_date' => '2026-08-27', 'status' => 'FINALIZED', 'recorded_by' => $this->adminUser->id]);
        $this->printService->record($l3, ['qty_gross_output' => 210, 'qty_defect' => 0, 'execution_date' => '2026-08-27', 'status' => 'FINALIZED', 'recorded_by' => $this->adminUser->id]);
        $this->printService->record($l4, ['qty_gross_output' => 210, 'qty_defect' => 0, 'execution_date' => '2026-08-29', 'status' => 'FINALIZED', 'recorded_by' => $this->adminUser->id]);

        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $l1->id,
            'barcode' => 'F3260826001',
            'tree_number' => 1,
            'quantity' => 144,
            'status' => 'generated',
            'current_stage' => null,
            'production_date' => '2026-08-26',
            'family_code' => 'F3',
            'daily_sequence' => 1,
        ]);

        LostWaxTreeAllocation::create([
            'lost_wax_tree_id' => $tree->id,
            'lost_wax_print_order_line_id' => $l1->id,
            'allocated_qty' => 144,
        ]);

        $this->assertEquals(686, $this->rangkaiService->getTotalAvailablePool('268L651'));

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $response->assertOk();

        $targetRow = collect($response->viewData('rows'))->firstWhere('code', '268L651');
        $this->assertNotNull($targetRow);

        $this->assertEquals(830, $targetRow['total_lap'], 'Total is 830');
        $this->assertEquals(10, $targetRow['overall_defect'], 'Tot Rsk is 10');
        $this->assertEquals(686, $targetRow['ctk_display'], 'CTK Standby is 686');
        $this->assertEquals(10, $targetRow['r_ctk_display'], 'CTK R is 10');
        $this->assertEquals(144, $targetRow['rgki_display'], 'RGKI is 144');
        $this->assertEquals(0, $targetRow['r_rgki_display'], 'RGKI R is 0');
    }

    /**
     * Test 6 — Total remains independent: Adding a defect indicator must not subtract again from Total.
     */
    public function test_6_total_remains_independent_and_does_not_double_deduct_tot_rsk(): void
    {
        $plan = $this->createPlan(['code' => 'TEST-06']);
        $line = $this->createPrintLine($plan, 'PC-T6', 100);

        // Cetak: Gross = 100, Defect = 10 -> Net Good = 90
        $this->printService->record($line, [
            'qty_gross_output' => 100,
            'qty_defect' => 10,
            'execution_date' => now()->format('Y-m-d'),
            'status' => 'FINALIZED',
            'recorded_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $targetRow = collect($response->viewData('rows'))->firstWhere('code', 'TEST-06');

        // Total must be Net Good (90), not (90 - 10 = 80)
        $this->assertEquals(90, $targetRow['total_lap'], 'Total is Net Good 90');
        $this->assertEquals(10, $targetRow['overall_defect'], 'Tot Rsk is 10');
        $this->assertEquals(90, $targetRow['ctk_display'], 'CTK Standby is 90');
        $this->assertEquals(10, $targetRow['r_ctk_display'], 'CTK R is 10');
    }
}
