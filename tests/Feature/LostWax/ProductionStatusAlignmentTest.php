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
        $this->assertSame('WARNING', $breakdown['status']);
        $this->assertNull($breakdown['po_quantity']);
        $this->assertNull($breakdown['deficit_vs_po']);
        $this->assertSame(50, $breakdown['deficit_vs_plan']);
    }

    /**
     * CASE 7: PO populated evaluates to WARNING if Planned > Usable >= PO.
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
        $this->assertSame('WARNING', $breakdown['status']);
        $this->assertSame(1000, $breakdown['po_quantity']);
        $this->assertSame(0, $breakdown['deficit_vs_po']);
        $this->assertSame(50, $breakdown['deficit_vs_plan']);
    }

    /**
     * CASE 8: PO threatened evaluates to CRITICAL if Usable < PO.
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
        $this->assertSame('CRITICAL', $breakdown['status']);
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
}
