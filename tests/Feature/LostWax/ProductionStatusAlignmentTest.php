<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxTree;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWaxQualityService;
use App\Services\PrintExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionStatusAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'name' => 'PPIC Admin',
            'email' => 'ppicadmin@peroniks.com',
        ]);
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'code' => '268ETB827',
            'customer' => 'PT PERONI INTI',
            'item_code' => '4.101105K.A0020',
            'item_name' => 'SS304 SQUARE DN 40',
            'aisi' => '304',
            'size' => 'DN 40',
            'weight' => 1.25,
            'po_number' => 'PO-2026-001',
            'po_quantity' => 1000,
            'qty_planned' => 1200,
            'qty_remaining' => 1200,
            'line_number' => 1,
            'status' => 'planning',
            'is_closed' => false,
        ], $attributes));
    }

    protected function createPrintOrderWithExecution(ProductionPlan $plan, int $good = 1000, int $defect = 0): LostWaxPrintOrder
    {
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0001',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => $good + $defect,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        $printService = app(PrintExecutionService::class);
        $printService->record($line, [
            'qty_good' => $good,
            'qty_defect' => $defect,
            'execution_date' => '2026-08-28',
            'status' => 'FINALIZED',
            'recorded_by' => $this->adminUser->id,
        ]);

        return $order;
    }

    protected function createTreeForLine($line, int $quantity = 32, ?string $currentStage = null): LostWaxTree
    {
        static $seq = 0;
        $seq++;

        return LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => '1280828'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'tree_number' => $seq,
            'quantity' => $quantity,
            'status' => 'in_coating',
            'current_stage' => $currentStage,
            'production_date' => '2026-08-28',
            'family_code' => '3',
            'daily_sequence' => $seq,
        ]);
    }

    /**
     * CASE 1: Standby material is NOT counted as defect.
     * Print Good = 1000, Tree Mounted = 320, Tree Defect = 0 -> Standby = 680, Defect = 0, Usable = 1000.
     */
    public function test_case_1_standby_is_not_defect(): void
    {
        $plan = $this->createPlan(['qty_planned' => 1200, 'po_quantity' => 1000]);
        $order = $this->createPrintOrderWithExecution($plan, 1000, 0);
        $line = $order->lines->first();

        // 10 trees x 32 pcs = 320 pcs mounted
        for ($i = 0; $i < 10; $i++) {
            $this->createTreeForLine($line, 32, 'layer_1');
        }

        $qualityService = app(LostWaxQualityService::class);
        $breakdown = $qualityService->getProductionPlanQuantityBreakdown($plan);

        $this->assertSame(1000, $breakdown['q_print_good']);
        $this->assertSame(320, $breakdown['q_active_trees_gross']);
        $this->assertSame(680, $breakdown['q_standby']);
        $this->assertSame(0, $breakdown['q_tree_defect']);
        $this->assertSame(1000, $breakdown['q_usable']);

        // Check via Production Status UI
        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status'));
        $response->assertOk();
        $response->assertSee('268ETB827');
    }

    /**
     * CASE 2: Print defect is NOT double deducted.
     * Print Output = 1300, Print Defect = 20, Print Good = 1280, Tree Defect = 40 -> Usable = 1240.
     */
    public function test_case_2_print_defect_not_double_deducted(): void
    {
        $plan = $this->createPlan(['qty_planned' => 1200, 'po_quantity' => 1000]);
        $order = $this->createPrintOrderWithExecution($plan, 1280, 20);
        $line = $order->lines->first();

        $tree = $this->createTreeForLine($line, 100, 'layer_1');

        $qualityService = app(LostWaxQualityService::class);
        $qualityService->recordDefect($tree, 'layer_1', 40, 'retak_lapisan');

        $breakdown = $qualityService->getProductionPlanQuantityBreakdown($plan);

        $this->assertSame(1280, $breakdown['q_print_good']);
        $this->assertSame(20, $breakdown['q_print_defect']);
        $this->assertSame(40, $breakdown['q_tree_defect']);
        $this->assertSame(1240, $breakdown['q_usable']);
        $this->assertSame('NORMAL', $breakdown['status']);
    }

    /**
     * CASE 3: Multi-stage defects accumulate cleanly.
     */
    public function test_case_3_multi_stage_defects(): void
    {
        $plan = $this->createPlan(['qty_planned' => 1200, 'po_quantity' => 1000]);
        $order = $this->createPrintOrderWithExecution($plan, 1280, 0);
        $line = $order->lines->first();

        $tree = $this->createTreeForLine($line, 100, 'oven');

        $qualityService = app(LostWaxQualityService::class);
        $qualityService->recordDefect($tree, 'assembly', 10, 'pola_patah');
        $qualityService->recordDefect($tree, 'layer_1', 10, 'retak_lapisan');
        $qualityService->recordDefect($tree, 'layer_2', 5, 'lapisan_rontok');
        $qualityService->recordDefect($tree, 'layer_7', 5, 'lapisan_tipis');
        $qualityService->recordDefect($tree, 'oven', 10, 'oven_pecah');

        $breakdown = $qualityService->getProductionPlanQuantityBreakdown($plan);

        $this->assertSame(40, $breakdown['q_tree_defect']);
        $this->assertSame(10, $breakdown['q_assembly_defect']);
        $this->assertSame(20, $breakdown['q_layer_defect']);
        $this->assertSame(10, $breakdown['q_oven_defect']);
        $this->assertSame(1240, $breakdown['q_usable']);
    }

    /**
     * CASE 4: Legacy tree without defects evaluates to 100% usable.
     */
    public function test_case_4_legacy_tree_without_defect(): void
    {
        $plan = $this->createPlan(['qty_planned' => 1200, 'po_quantity' => 1000]);
        $order = $this->createPrintOrderWithExecution($plan, 32, 0);
        $line = $order->lines->first();

        $tree = $this->createTreeForLine($line, 32, 'layer_2');

        $this->assertSame(0, $tree->total_defect_quantity);
        $this->assertSame(32, $tree->usable_quantity);
    }

    /**
     * CASE 5: Cancelled tree is excluded from active quantity and defect.
     */
    public function test_case_5_cancelled_tree_is_excluded(): void
    {
        $plan = $this->createPlan(['qty_planned' => 1200, 'po_quantity' => 1000]);
        $order = $this->createPrintOrderWithExecution($plan, 320, 0);
        $line = $order->lines->first();

        $tree = $this->createTreeForLine($line, 32, 'layer_1');
        $tree->update(['status' => 'cancelled']);

        $qualityService = app(LostWaxQualityService::class);
        $breakdown = $qualityService->getProductionPlanQuantityBreakdown($plan);

        $this->assertSame(0, $breakdown['q_active_trees_gross']);
        $this->assertSame(320, $breakdown['q_standby']);
        $this->assertSame(0, $breakdown['q_tree_defect']);
        $this->assertSame(320, $breakdown['q_usable']);
    }

    /**
     * CASE 6: PO NULL evaluates to WARNING if Usable < Planned, never CRITICAL.
     */
    public function test_case_6_po_null_evaluates_to_warning(): void
    {
        $plan = $this->createPlan(['qty_planned' => 1200, 'po_quantity' => null]);
        $order = $this->createPrintOrderWithExecution($plan, 1200, 0);
        $line = $order->lines->first();

        $tree = $this->createTreeForLine($line, 100, 'layer_1');
        $qualityService = app(LostWaxQualityService::class);
        $qualityService->recordDefect($tree, 'layer_1', 50, 'retak_lapisan');

        $breakdown = $qualityService->getProductionPlanQuantityBreakdown($plan);

        $this->assertSame(1150, $breakdown['q_usable']);
        $this->assertSame('WATCH', $breakdown['status']);
        $this->assertNull($breakdown['po_quantity']);
        $this->assertNull($breakdown['deficit_vs_po']);
        $this->assertSame(50, $breakdown['deficit_vs_plan']);
    }

    /**
     * CASE 7: PO populated evaluates to WATCH if Planned > Usable >= PO.
     */
    public function test_case_7_po_populated_evaluates_to_warning(): void
    {
        $plan = $this->createPlan(['qty_planned' => 1200, 'po_quantity' => 1000]);
        $order = $this->createPrintOrderWithExecution($plan, 1200, 0);
        $line = $order->lines->first();

        $tree = $this->createTreeForLine($line, 100, 'layer_1');
        $qualityService = app(LostWaxQualityService::class);
        $qualityService->recordDefect($tree, 'layer_1', 50, 'retak_lapisan');

        $breakdown = $qualityService->getProductionPlanQuantityBreakdown($plan);

        $this->assertSame(1150, $breakdown['q_usable']);
        $this->assertSame('WATCH', $breakdown['status']);
        $this->assertSame(1000, $breakdown['po_quantity']);
        $this->assertSame(0, $breakdown['deficit_vs_po']);
        $this->assertSame(50, $breakdown['deficit_vs_plan']);
    }

    /**
     * CASE 8: PO threatened evaluates to KURANG if Usable < PO.
     */
    public function test_case_8_po_threatened_evaluates_to_critical(): void
    {
        $plan = $this->createPlan(['qty_planned' => 1200, 'po_quantity' => 1000]);
        $order = $this->createPrintOrderWithExecution($plan, 1200, 0);
        $line = $order->lines->first();

        $tree = $this->createTreeForLine($line, 300, 'layer_1');
        $qualityService = app(LostWaxQualityService::class);
        $qualityService->recordDefect($tree, 'layer_1', 250, 'retak_lapisan');

        $breakdown = $qualityService->getProductionPlanQuantityBreakdown($plan);

        $this->assertSame(950, $breakdown['q_usable']);
        $this->assertSame('KURANG', $breakdown['status']);
        $this->assertSame(50, $breakdown['deficit_vs_po']);
        $this->assertSame(250, $breakdown['deficit_vs_plan']);
    }

    /**
     * CASE 9: Production Status Web View renders status badges and accurate quantities.
     */
    public function test_production_status_view_renders_badges_and_quantities(): void
    {
        $plan = $this->createPlan(['qty_planned' => 1200, 'po_quantity' => 1000]);
        $order = $this->createPrintOrderWithExecution($plan, 1280, 0);
        $line = $order->lines->first();

        $tree = $this->createTreeForLine($line, 100, 'layer_1');
        $qualityService = app(LostWaxQualityService::class);
        $qualityService->recordDefect($tree, 'layer_1', 40, 'retak_lapisan');

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status'));

        $response->assertOk();
        $response->assertSee('268ETB827');
        $response->assertSee('SS304 SQUARE DN 40');
        $response->assertSee('NORMAL');
    }

    /**
     * CASE 10: Production Status Excel Export streams successfully.
     */
    public function test_production_status_export_xlsx(): void
    {
        $plan = $this->createPlan(['qty_planned' => 1200, 'po_quantity' => 1000]);
        $this->createPrintOrderWithExecution($plan, 1200, 0);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status.export'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /**
     * CASE 11: Mass balance equation across all distributed stages matches Total and Net Good.
     */
    public function test_mass_balance_across_all_distributed_stages(): void
    {
        // PO = 1000, Plan = 1100, Print Good = 1100
        $plan = $this->createPlan(['code' => '268KS103', 'qty_planned' => 1100, 'po_quantity' => 1000]);
        $order = $this->createPrintOrderWithExecution($plan, 1100, 0);
        $line = $order->lines->first();

        // 1 tree in sebelum_scan (30 pcs gross, 2 defect = 28 usable)
        $t0 = $this->createTreeForLine($line, 30, null);
        $qualityService = app(LostWaxQualityService::class);
        $qualityService->recordDefect($t0, 'assembly', 2, 'pola_patah');

        // 1 tree in L1 (100 pcs gross, 5 defect = 95 usable)
        $t1 = $this->createTreeForLine($line, 100, 'layer_1');
        $qualityService->recordDefect($t1, 'layer_1', 5, 'retak_lapisan');

        // 1 tree in L3 (100 pcs gross, 3 defect = 97 usable)
        $t3 = $this->createTreeForLine($line, 100, 'layer_3');
        $qualityService->recordDefect($t3, 'layer_3', 3, 'slurry_rontok');

        // 1 tree in Oven (200 pcs gross, 10 defect = 190 usable)
        $tOven = $this->createTreeForLine($line, 200, 'oven');
        $qualityService->recordDefect($tOven, 'oven', 10, 'wax_bleed');

        // Total trees gross = 30 + 100 + 100 + 200 = 430
        // Standby CTK = 1100 - 430 = 670
        // Total Defect = 2 + 5 + 3 + 10 = 20
        // Net Good / Usable = 1100 - 20 = 1080
        // Sum of distributed = CTK(670) + RGKI(28) + L1(95) + L3(97) + Oven(190) = 1080

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $response->assertOk();

        $rows = $response->viewData('rows');
        $targetRow = collect($rows)->firstWhere('code', '268KS103');
        $this->assertNotNull($targetRow);

        $this->assertEquals(670, $targetRow['ctk_display'], 'CTK Standby matches');
        $this->assertEquals(28, $targetRow['rgki_display'], 'RGKI sebelum_scan matches');
        $this->assertEquals(95, $targetRow['layer_1'], 'L1 matches');
        $this->assertEquals(97, $targetRow['layer_3'], 'L3 matches');
        $this->assertEquals(190, $targetRow['oven_qty'], 'Oven matches');
        $this->assertEquals(20, $targetRow['overall_defect'], 'Tot Rsk matches');

        // Mass balance sum
        $distributedSum = $targetRow['ctk_display'] + $targetRow['rgki_display'] +
            $targetRow['layer_1'] + $targetRow['layer_2'] + $targetRow['layer_3'] +
            $targetRow['layer_4'] + $targetRow['layer_5'] + $targetRow['layer_6'] +
            $targetRow['layer_7'] + $targetRow['oven_qty'];

        $this->assertEquals(1080, $distributedSum, 'Distributed sum matches Net Good exactly');
        $this->assertEquals(1080, $targetRow['total_lap'], 'Total column equals distributed sum');
        $this->assertEquals('WATCH', $targetRow['quality_status'], 'Net Good (1080) < Plan (1100) but >= PO (1000) evaluates to WATCH');
    }

    /**
     * CASE 12: Re-Plan boundary: when Net Good drops below PO, status becomes KURANG.
     */
    public function test_replan_triggered_when_net_good_below_po(): void
    {
        // PO = 1000, Plan = 1100, Print Good = 1100
        $plan = $this->createPlan(['code' => '268KS104', 'qty_planned' => 1100, 'po_quantity' => 1000]);
        $order = $this->createPrintOrderWithExecution($plan, 1100, 0);
        $line = $order->lines->first();

        // 1 tree with 300 pcs gross, and 101 defect in Layer 2
        // Total Defect = 101 -> Net Good = 1100 - 101 = 999 (< PO 1000)
        $t = $this->createTreeForLine($line, 300, 'layer_2');
        $qualityService = app(LostWaxQualityService::class);
        $qualityService->recordDefect($t, 'layer_2', 101, 'retak_parah');

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $response->assertOk();

        $rows = $response->viewData('rows');
        $targetRow = collect($rows)->firstWhere('code', '268KS104');
        $this->assertNotNull($targetRow);

        $this->assertEquals(999, $targetRow['total_lap'], 'Total equals 999');
        $this->assertEquals(101, $targetRow['overall_defect'], 'Tot Rsk equals 101');
        $this->assertEquals('KURANG', $targetRow['quality_status'], 'Status is KURANG because Net Good (999) < PO (1000)');

        $response->assertSee('KURANG');
        $response->assertSee('>Total<', false);
    }

    /**
     * CASE 13: Real UAT Case 268L651 - PO 940, Plan 1019, Gross 840, Defect 10 -> Net Good 830 -> KURANG.
     */
    public function test_uat_case_268l651_critical_replan(): void
    {
        // PO = 940, Plan = 1019, Executed Good = 840
        $plan = $this->createPlan(['code' => '268L651', 'qty_planned' => 1019, 'po_quantity' => 940]);
        $order = $this->createPrintOrderWithExecution($plan, 840, 0);
        $line = $order->lines->first();

        // 1 tree with 144 pcs gross, and 10 defect in assembly
        $t = $this->createTreeForLine($line, 144, null);
        $qualityService = app(LostWaxQualityService::class);
        $qualityService->recordDefect($t, 'assembly', 10, 'pola_rusak');

        // CTK Standby = 840 - 144 = 696
        // RGKI Net = 144 - 10 = 134
        // Gross = 696 + 144 = 840
        // Tot Rsk = 10
        // Net Good = 840 - 10 = 830 (CTK 696 + RGKI Net 134 = 830)

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $response->assertOk();

        $rows = $response->viewData('rows');
        $targetRow = collect($rows)->firstWhere('code', '268L651');
        $this->assertNotNull($targetRow);

        $this->assertEquals(696, $targetRow['ctk_display'], 'CTK Standby is 696');
        $this->assertEquals(134, $targetRow['rgki_display'], 'RGKI Net is 134');
        $this->assertEquals(10, $targetRow['overall_defect'], 'Tot Rsk is 10');
        $this->assertEquals(830, $targetRow['total_lap'], 'Total / Net Good is 830');
        $this->assertEquals('KURANG', $targetRow['quality_status'], 'Net Good (830) < PO (940) triggers KURANG');
    }

    /**
     * CASE 14: Real UAT Case 268ETB827 - Discrepancy explanation verification.
     * PO 950, Plan 1100, Total 950 (CTK 22 + Net Trees 928 = 950), Tot Rsk 6 (Gross 956).
     */
    public function test_uat_case_268etb827_exact_mass_balance(): void
    {
        $plan = $this->createPlan(['code' => '268ETB827', 'qty_planned' => 1100, 'po_quantity' => 950]);
        $order = $this->createPrintOrderWithExecution($plan, 956, 0);
        $line = $order->lines->first();

        // Trees:
        // L1: 96 gross, 0 defect -> 96 net
        $t1 = $this->createTreeForLine($line, 96, 'layer_1');

        // L2: 224 gross, 0 defect -> 224 net
        $t2 = $this->createTreeForLine($line, 224, 'layer_2');

        // L3: 194 gross, 2 defect -> 192 net
        $t3 = $this->createTreeForLine($line, 194, 'layer_3');
        $qualityService = app(LostWaxQualityService::class);
        $qualityService->recordDefect($t3, 'layer_3', 2, 'retak');

        // L4: 128 gross, 0 defect -> 128 net
        $t4 = $this->createTreeForLine($line, 128, 'layer_4');

        // L5: 196 gross, 4 defect -> 192 net
        $t5 = $this->createTreeForLine($line, 196, 'layer_5');
        $qualityService->recordDefect($t5, 'layer_5', 4, 'pasir_rontok');

        // Oven: 96 gross, 0 defect -> 96 net
        $tOven = $this->createTreeForLine($line, 96, 'oven');

        // Total trees gross = 96 + 224 + 194 + 128 + 196 + 96 = 934
        // Standby CTK = 956 - 934 = 22
        // Total Defect (Tot Rsk) = 2 + 4 = 6
        // Total Distributed Gross = 22 + 934 = 956
        // Net Good = 956 - 6 = 950 (Stage net sum: 22 + 96 + 224 + 192 + 128 + 192 + 96 = 950)

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $response->assertOk();

        $rows = $response->viewData('rows');
        $targetRow = collect($rows)->firstWhere('code', '268ETB827');
        $this->assertNotNull($targetRow);

        $this->assertEquals(22, $targetRow['ctk_display'], 'CTK Standby is 22');
        $this->assertEquals(6, $targetRow['overall_defect'], 'Tot Rsk is 6');
        $this->assertEquals(96, $targetRow['layer_1'], 'L1 is 96');
        $this->assertEquals(224, $targetRow['layer_2'], 'L2 is 224');
        $this->assertEquals(192, $targetRow['layer_3'], 'L3 is 192');
        $this->assertEquals(128, $targetRow['layer_4'], 'L4 is 128');
        $this->assertEquals(192, $targetRow['layer_5'], 'L5 is 192');
        $this->assertEquals(96, $targetRow['oven_qty'], 'Oven is 96');
        $this->assertEquals(950, $targetRow['total_lap'], 'Total / Net Good is exactly 950');
        $this->assertEquals('WATCH', $targetRow['quality_status'], 'Net Good (950) == PO (950) but < Plan (1100) evaluates to WATCH (PO is safe)');
    }
}
