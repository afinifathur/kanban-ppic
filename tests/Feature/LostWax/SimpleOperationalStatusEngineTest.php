<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWaxQualityService;
use App\Services\PrintExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimpleOperationalStatusEngineTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected PrintExecutionService $printService;

    protected LostWaxQualityService $qualityService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'name' => 'Admin PPIC',
            'email' => 'admin@peroniks.com',
        ]);

        $this->printService = app(PrintExecutionService::class);
        $this->qualityService = app(LostWaxQualityService::class);
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'code' => 'PLAN-STATUS-TEST',
            'customer' => 'PT PERONI INTI',
            'item_code' => '4.101105K.A0020',
            'item_name' => 'SS304 VALVE BODY',
            'aisi' => '304',
            'size' => 'DN 50',
            'weight' => 1.25,
            'po_number' => 'PO-TEST',
            'po_quantity' => 1000,
            'qty_planned' => 1200,
            'qty_remaining' => 1200,
            'line_number' => 1,
            'status' => 'planning',
            'is_closed' => false,
        ], $attributes));
    }

    protected function createPrintLine(ProductionPlan $plan, int $ordered): LostWaxPrintOrderLine
    {
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-STAT-'.uniqid(),
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
     * Case 1: PO 1000 / Plan 1200 / Total 900 => KURANG
     */
    public function test_case_1_po_1000_plan_1200_total_900_evaluates_to_kurang(): void
    {
        $plan = $this->createPlan(['code' => 'CASE-1', 'po_quantity' => 1000, 'qty_planned' => 1200]);
        $this->assertEquals('KURANG', $plan->evaluateProductionStatus(900));

        $line = $this->createPrintLine($plan, 1200);
        $this->printService->record($line, ['qty_gross_output' => 900, 'qty_defect' => 0, 'status' => 'FINALIZED', 'recorded_by' => $this->adminUser->id]);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $targetRow = collect($response->viewData('rows'))->firstWhere('code', 'CASE-1');
        $this->assertEquals('KURANG', $targetRow['quality_status']);
    }

    /**
     * Case 2: PO 1000 / Plan 1200 / Total 1000 => WATCH
     */
    public function test_case_2_po_1000_plan_1200_total_1000_evaluates_to_watch(): void
    {
        $plan = $this->createPlan(['code' => 'CASE-2', 'po_quantity' => 1000, 'qty_planned' => 1200]);
        $this->assertEquals('WATCH', $plan->evaluateProductionStatus(1000));

        $line = $this->createPrintLine($plan, 1200);
        $this->printService->record($line, ['qty_gross_output' => 1000, 'qty_defect' => 0, 'status' => 'FINALIZED', 'recorded_by' => $this->adminUser->id]);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $targetRow = collect($response->viewData('rows'))->firstWhere('code', 'CASE-2');
        $this->assertEquals('WATCH', $targetRow['quality_status']);
    }

    /**
     * Case 3: PO 1000 / Plan 1200 / Total 1200 => WATCH
     */
    public function test_case_3_po_1000_plan_1200_total_1200_evaluates_to_watch(): void
    {
        $plan = $this->createPlan(['code' => 'CASE-3', 'po_quantity' => 1000, 'qty_planned' => 1200]);
        $this->assertEquals('WATCH', $plan->evaluateProductionStatus(1200));

        $line = $this->createPrintLine($plan, 1200);
        $this->printService->record($line, ['qty_gross_output' => 1200, 'qty_defect' => 0, 'status' => 'FINALIZED', 'recorded_by' => $this->adminUser->id]);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $targetRow = collect($response->viewData('rows'))->firstWhere('code', 'CASE-3');
        $this->assertEquals('WATCH', $targetRow['quality_status']);
    }

    /**
     * Case 4: PO 1000 / Plan 1200 / Total 1201 => NORMAL
     */
    public function test_case_4_po_1000_plan_1200_total_1201_evaluates_to_normal(): void
    {
        $plan = $this->createPlan(['code' => 'CASE-4', 'po_quantity' => 1000, 'qty_planned' => 1200]);
        $this->assertEquals('NORMAL', $plan->evaluateProductionStatus(1201));

        $line = $this->createPrintLine($plan, 1200);
        $this->printService->record($line, ['qty_gross_output' => 1201, 'qty_defect' => 0, 'status' => 'FINALIZED', 'recorded_by' => $this->adminUser->id]);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $targetRow = collect($response->viewData('rows'))->firstWhere('code', 'CASE-4');
        $this->assertEquals('NORMAL', $targetRow['quality_status']);
    }

    /**
     * Case 5: PO 1000 / Plan 900 / Total 950 => KURANG
     */
    public function test_case_5_po_1000_plan_900_total_950_evaluates_to_kurang(): void
    {
        $plan = $this->createPlan(['code' => 'CASE-5', 'po_quantity' => 1000, 'qty_planned' => 900]);
        $this->assertEquals('KURANG', $plan->evaluateProductionStatus(950));

        $line = $this->createPrintLine($plan, 900);
        $this->printService->record($line, ['qty_gross_output' => 950, 'qty_defect' => 0, 'status' => 'FINALIZED', 'recorded_by' => $this->adminUser->id]);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $targetRow = collect($response->viewData('rows'))->firstWhere('code', 'CASE-5');
        $this->assertEquals('KURANG', $targetRow['quality_status']);
    }

    /**
     * Case 6: PO 1000 / Plan 900 / Total 1000 => NORMAL
     */
    public function test_case_6_po_1000_plan_900_total_1000_evaluates_to_normal(): void
    {
        $plan = $this->createPlan(['code' => 'CASE-6', 'po_quantity' => 1000, 'qty_planned' => 900]);
        $this->assertEquals('NORMAL', $plan->evaluateProductionStatus(1000));

        $line = $this->createPrintLine($plan, 900);
        $this->printService->record($line, ['qty_gross_output' => 1000, 'qty_defect' => 0, 'status' => 'FINALIZED', 'recorded_by' => $this->adminUser->id]);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $targetRow = collect($response->viewData('rows'))->firstWhere('code', 'CASE-6');
        $this->assertEquals('NORMAL', $targetRow['quality_status']);
    }

    /**
     * Case 7: PO 1000 / Plan 900 / Total 1001 => NORMAL
     */
    public function test_case_7_po_1000_plan_900_total_1001_evaluates_to_normal(): void
    {
        $plan = $this->createPlan(['code' => 'CASE-7', 'po_quantity' => 1000, 'qty_planned' => 900]);
        $this->assertEquals('NORMAL', $plan->evaluateProductionStatus(1001));

        $line = $this->createPrintLine($plan, 900);
        $this->printService->record($line, ['qty_gross_output' => 1001, 'qty_defect' => 0, 'status' => 'FINALIZED', 'recorded_by' => $this->adminUser->id]);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $targetRow = collect($response->viewData('rows'))->firstWhere('code', 'CASE-7');
        $this->assertEquals('NORMAL', $targetRow['quality_status']);
    }

    /**
     * Case 8: PO NULL / Plan 1200 / Total 1000 => WATCH
     */
    public function test_case_8_po_null_plan_1200_total_1000_evaluates_to_watch(): void
    {
        $plan = $this->createPlan(['code' => 'CASE-8', 'po_quantity' => null, 'qty_planned' => 1200]);
        $this->assertEquals('WATCH', $plan->evaluateProductionStatus(1000));

        $line = $this->createPrintLine($plan, 1200);
        $this->printService->record($line, ['qty_gross_output' => 1000, 'qty_defect' => 0, 'status' => 'FINALIZED', 'recorded_by' => $this->adminUser->id]);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $targetRow = collect($response->viewData('rows'))->firstWhere('code', 'CASE-8');
        $this->assertEquals('WATCH', $targetRow['quality_status']);
    }

    /**
     * Case 9: PO NULL / Plan 1200 / Total 1201 => NORMAL
     */
    public function test_case_9_po_null_plan_1200_total_1201_evaluates_to_normal(): void
    {
        $plan = $this->createPlan(['code' => 'CASE-9', 'po_quantity' => null, 'qty_planned' => 1200]);
        $this->assertEquals('NORMAL', $plan->evaluateProductionStatus(1201));

        $line = $this->createPrintLine($plan, 1200);
        $this->printService->record($line, ['qty_gross_output' => 1201, 'qty_defect' => 0, 'status' => 'FINALIZED', 'recorded_by' => $this->adminUser->id]);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $targetRow = collect($response->viewData('rows'))->firstWhere('code', 'CASE-9');
        $this->assertEquals('NORMAL', $targetRow['quality_status']);
    }

    /**
     * Case 10: Gross 1300 / Defect 400 / Net 900 / PO 1000 => KURANG
     */
    public function test_case_10_gross_1300_defect_400_net_900_po_1000_evaluates_to_kurang(): void
    {
        $plan = $this->createPlan(['code' => 'CASE-10', 'po_quantity' => 1000, 'qty_planned' => 1200]);

        $line = $this->createPrintLine($plan, 1200);
        $this->printService->record($line, ['qty_gross_output' => 1300, 'qty_defect' => 400, 'status' => 'FINALIZED', 'recorded_by' => $this->adminUser->id]);

        $breakdown = $this->qualityService->getProductionPlanQuantityBreakdown($plan);
        $this->assertEquals(900, $breakdown['q_usable']);
        $this->assertEquals('KURANG', $breakdown['status']);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $targetRow = collect($response->viewData('rows'))->firstWhere('code', 'CASE-10');
        $this->assertEquals('KURANG', $targetRow['quality_status']);
    }

    /**
     * Case 11: Defect > PO but Net Good remains > Plan => NORMAL
     */
    public function test_case_11_defect_greater_than_po_but_net_good_exceeds_plan_evaluates_to_normal(): void
    {
        // PO = 1000, Plan = 1200, Gross = 5000, Defect = 1500 -> Net Good = 3500 (> Plan 1200)
        $plan = $this->createPlan(['code' => 'CASE-11', 'po_quantity' => 1000, 'qty_planned' => 1200]);

        $line = $this->createPrintLine($plan, 1200);
        $this->printService->record($line, ['qty_gross_output' => 5000, 'qty_defect' => 1500, 'status' => 'FINALIZED', 'recorded_by' => $this->adminUser->id]);

        $breakdown = $this->qualityService->getProductionPlanQuantityBreakdown($plan);
        $this->assertEquals(3500, $breakdown['q_usable']);
        $this->assertEquals('NORMAL', $breakdown['status']);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $targetRow = collect($response->viewData('rows'))->firstWhere('code', 'CASE-11');
        $this->assertEquals('NORMAL', $targetRow['quality_status']);
    }

    /**
     * Case 12: Exact PO match (TOTAL == PO): customer considered met, status next evaluated by Plan.
     */
    public function test_case_12_exact_po_match_is_not_kurang(): void
    {
        $plan = $this->createPlan(['code' => 'CASE-12', 'po_quantity' => 1000, 'qty_planned' => 1500]);
        $this->assertEquals('WATCH', $plan->evaluateProductionStatus(1000));

        $line = $this->createPrintLine($plan, 1500);
        $this->printService->record($line, ['qty_gross_output' => 1000, 'qty_defect' => 0, 'status' => 'FINALIZED', 'recorded_by' => $this->adminUser->id]);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $targetRow = collect($response->viewData('rows'))->firstWhere('code', 'CASE-12');
        $this->assertEquals('WATCH', $targetRow['quality_status']);
    }

    /**
     * Case 13: UI Badges (Web) and Print/PDF text rendering verification
     */
    public function test_case_13_ui_badges_and_print_text_rendering(): void
    {
        $planNormal = $this->createPlan(['code' => 'P-NORM', 'po_quantity' => 1000, 'qty_planned' => 1000]);
        $l1 = $this->createPrintLine($planNormal, 1000);
        $this->printService->record($l1, ['qty_gross_output' => 1001, 'qty_defect' => 0, 'status' => 'FINALIZED', 'recorded_by' => $this->adminUser->id]);

        $planWatch = $this->createPlan(['code' => 'P-WATCH', 'po_quantity' => 1000, 'qty_planned' => 1500]);
        $l2 = $this->createPrintLine($planWatch, 1500);
        $this->printService->record($l2, ['qty_gross_output' => 1200, 'qty_defect' => 0, 'status' => 'FINALIZED', 'recorded_by' => $this->adminUser->id]);

        $planKurang = $this->createPlan(['code' => 'P-KURANG', 'po_quantity' => 1000, 'qty_planned' => 1500]);
        $l3 = $this->createPrintLine($planKurang, 1500);
        $this->printService->record($l3, ['qty_gross_output' => 800, 'qty_defect' => 0, 'status' => 'FINALIZED', 'recorded_by' => $this->adminUser->id]);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $response->assertOk();

        $rows = collect($response->viewData('rows'));
        $this->assertEquals('NORMAL', $rows->firstWhere('code', 'P-NORM')['quality_status']);
        $this->assertEquals('WATCH', $rows->firstWhere('code', 'P-WATCH')['quality_status']);
        $this->assertEquals('KURANG', $rows->firstWhere('code', 'P-KURANG')['quality_status']);

        $content = $response->getContent();
        $this->assertStringContainsString('P-NORM', $content);
        $pos = strpos($content, 'P-NORM');
        $snippet = substr($content, $pos, 6000);

        $this->assertStringContainsString('NORMAL', $snippet);
        $this->assertStringContainsString('bg-emerald-100', $snippet);
        $this->assertStringContainsString('text-emerald-800', $snippet);
        $this->assertStringContainsString('border-emerald-300', $snippet);

        $this->assertStringContainsString('WATCH', $content);
        $this->assertStringContainsString('KURANG', $content);
        $this->assertStringContainsString('bg-amber-100', $content);
        $this->assertStringContainsString('text-amber-800', $content);
        $this->assertStringContainsString('bg-orange-100', $content);
        $this->assertStringContainsString('text-orange-800', $content);

        // 3. Verify obsolete status keywords are NOT present in badge definitions
        $this->assertStringNotContainsString('CRITICAL', $content);
        $this->assertStringNotContainsString('WARNING', $content);
    }
}
