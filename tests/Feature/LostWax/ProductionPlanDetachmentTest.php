<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\ProductionItem;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWaxQualityService;
use App\Services\PrintExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionPlanDetachmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $ppicUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ppicUser = User::factory()->create([
            'name' => 'PPIC Stainless',
            'email' => 'ppic_ss@peroniks.com',
            'product_scope' => 'FLANGE_STAINLESS',
        ]);
        $this->ppicUser->assignRole('ppic');
        $this->ppicUser->givePermissionTo('access_planning');
    }

    /**
     * TEST 1: Lost Wax Plan Creation via /plan/create endpoint
     */
    public function test_1_lost_wax_plan_creation(): void
    {
        $payload = [
            'date' => '2026-08-28',
            'title' => 'Rencana Lost Wax Flange 304',
            'plans' => [
                [
                    'code' => 'LW-001',
                    'item_code' => 'ITEM-X',
                    'item_name' => 'SS304 FLANGE DN 50',
                    'aisi' => '304',
                    'size' => 'DN 50',
                    'weight' => 2.5,
                    'po_number' => 'PO-001',
                    'po_quantity' => 100,
                    'qty_planned' => 120,
                    'line_number' => 1,
                    'customer' => 'PT SAMPLE INDO',
                    'product_scope' => 'FLANGE_STAINLESS',
                ],
            ],
        ];

        $response = $this->actingAs($this->ppicUser)->postJson(route('plan.store'), $payload);
        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('production_plans', [
            'code' => 'LW-001',
            'item_code' => 'ITEM-X',
            'po_number' => 'PO-001',
            'po_quantity' => 100,
            'qty_planned' => 120,
            'qty_remaining' => 120,
        ]);
    }

    /**
     * TEST 2: Same Item Code Must Not Import Existing Cor Data
     */
    public function test_2_same_item_code_must_not_import_cor_data(): void
    {
        // Create an unassigned Cor ProductionItem with item_code = 'ITEM-X'
        $corItem = ProductionItem::create([
            'plan_id' => null,
            'code' => 'COR-001',
            'heat_number' => 'H99901',
            'item_code' => 'ITEM-X',
            'item_name' => 'SS304 FLANGE DN 50',
            'qty_pcs' => 50,
            'current_dept' => 'cor',
            'dept_entry_at' => now(),
        ]);

        $payload = [
            'title' => 'Rencana Baru Lost Wax',
            'plans' => [
                [
                    'code' => 'LW-001',
                    'item_code' => 'ITEM-X',
                    'item_name' => 'SS304 FLANGE DN 50',
                    'po_number' => 'PO-001',
                    'po_quantity' => 100,
                    'qty_planned' => 120,
                    'line_number' => 1,
                    'customer' => 'PT SAMPLE INDO',
                    'product_scope' => 'FLANGE_STAINLESS',
                ],
            ],
        ];

        $response = $this->actingAs($this->ppicUser)->postJson(route('plan.store'), $payload);
        $response->assertOk();

        $plan = ProductionPlan::where('code', 'LW-001')->firstOrFail();

        // Ensure NO ProductionItem is attached to this plan
        $this->assertSame(0, $plan->items()->count());
        $this->assertNull($corItem->fresh()->plan_id);
        $this->assertSame(120, $plan->qty_remaining);
    }

    /**
     * TEST 3: Different Production Code Isolation
     */
    public function test_3_different_production_code_isolation(): void
    {
        $planA = ProductionPlan::create([
            'code' => 'LW-A',
            'item_code' => 'ITEM-X',
            'item_name' => 'SS304 FLANGE DN 50',
            'po_number' => 'PO-A',
            'po_quantity' => 50,
            'qty_planned' => 60,
            'qty_remaining' => 60,
            'line_number' => 1,
            'product_scope' => 'FLANGE_STAINLESS',
        ]);

        $planB = ProductionPlan::create([
            'code' => 'LW-B',
            'item_code' => 'ITEM-X',
            'item_name' => 'SS304 FLANGE DN 50',
            'po_number' => 'PO-B',
            'po_quantity' => 70,
            'qty_planned' => 80,
            'qty_remaining' => 80,
            'line_number' => 1,
            'product_scope' => 'FLANGE_STAINLESS',
        ]);

        $this->assertNotSame($planA->id, $planB->id);
        $this->assertSame('LW-A', $planA->code);
        $this->assertSame('LW-B', $planB->code);
        $this->assertSame(50, $planA->po_quantity);
        $this->assertSame(70, $planB->po_quantity);
    }

    /**
     * TEST 4: PO Number Persistence
     */
    public function test_4_po_number_persistence(): void
    {
        $payload = [
            'title' => 'Rencana PO Persistence',
            'plans' => [
                [
                    'code' => 'LW-PO-TEST',
                    'item_code' => 'ITEM-PO',
                    'item_name' => 'SS304 FLANGE DN 50',
                    'po_number' => 'PO-2026-001',
                    'po_quantity' => 500,
                    'qty_planned' => 550,
                    'line_number' => 1,
                    'customer' => 'PT SAMPLE INDO',
                    'product_scope' => 'FLANGE_STAINLESS',
                ],
            ],
        ];

        $this->actingAs($this->ppicUser)->postJson(route('plan.store'), $payload);

        $plan = ProductionPlan::where('code', 'LW-PO-TEST')->firstOrFail();
        $this->assertSame('PO-2026-001', $plan->po_number);
    }

    /**
     * TEST 5: Production Status and Recovery Pool PO Flow
     */
    public function test_5_production_status_po(): void
    {
        $plan = ProductionPlan::create([
            'code' => 'LW-STATUS-PO',
            'item_code' => 'ITEM-STATUS',
            'item_name' => 'SS304 FLANGE DN 50',
            'po_number' => 'PO-2026-999',
            'po_quantity' => 1000,
            'qty_planned' => 1200,
            'qty_remaining' => 1200,
            'line_number' => 1,
            'product_scope' => 'FLANGE_STAINLESS',
        ]);

        // Create Print Order so it appears in Production Status (has printOrderLines)
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-9901',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);
        $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 1200,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        // 1. Check PO filtering on Production Status
        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.production-status', ['po_numbers' => ['PO-2026-999']]));
        $response->assertOk();
        $response->assertSee('LW-STATUS-PO');
        $response->assertSee('PO: PO-2026-999');

        // 2. Check PO is rendered on Recovery Pool
        $recoveryResponse = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $recoveryResponse->assertOk();
        $recoveryResponse->assertSee('LW-STATUS-PO');
        $recoveryResponse->assertSee('PO-2026-999');
    }

    /**
     * TEST 6: PO Quantity vs Planned Separation
     */
    public function test_6_po_quantity_vs_planned(): void
    {
        $payload = [
            'title' => 'Rencana PO Qty Separation',
            'plans' => [
                [
                    'code' => 'LW-SEP-01',
                    'item_code' => 'ITEM-SEP',
                    'item_name' => 'SS304 FLANGE DN 50',
                    'po_number' => 'PO-2026-SEP',
                    'po_quantity' => 1000,
                    'qty_planned' => 1200,
                    'line_number' => 1,
                    'customer' => 'PT SAMPLE INDO',
                    'product_scope' => 'FLANGE_STAINLESS',
                ],
            ],
        ];

        $this->actingAs($this->ppicUser)->postJson(route('plan.store'), $payload);

        $plan = ProductionPlan::where('code', 'LW-SEP-01')->firstOrFail();
        $this->assertSame(1000, $plan->po_quantity);
        $this->assertSame(1200, $plan->qty_planned);
        $this->assertNotSame($plan->po_quantity, $plan->qty_planned);
    }

    /**
     * TEST 7: PO NULL Compatibility
     */
    public function test_7_po_null_compatibility(): void
    {
        $plan = ProductionPlan::create([
            'code' => 'LW-NULL-PO',
            'item_code' => 'ITEM-NULL',
            'item_name' => 'SS304 FLANGE DN 50',
            'po_number' => 'LEGACY-PO-EMPTY',
            'po_quantity' => null,
            'qty_planned' => 1200,
            'qty_remaining' => 1200,
            'line_number' => 1,
            'product_scope' => 'FLANGE_STAINLESS',
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-9902',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);
        $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 1200,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        // Check Production Status page does not crash
        $statusResponse = $this->actingAs($this->ppicUser)->get(route('lost-wax.production-status'));
        $statusResponse->assertOk();
        $statusResponse->assertSee('LW-NULL-PO');

        // Check Recovery Pool page does not crash
        $recoveryResponse = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $recoveryResponse->assertOk();
        $recoveryResponse->assertSee('LW-NULL-PO');
        $recoveryResponse->assertSee('PO BELUM DIISI');
    }

    /**
     * TEST 8: Lost Wax Existing Workflow Works Without ProductionItem
     */
    public function test_8_lost_wax_existing_workflow(): void
    {
        $plan = ProductionPlan::create([
            'code' => 'LW-E2E-01',
            'item_code' => 'ITEM-E2E',
            'item_name' => 'SS304 FLANGE DN 50',
            'po_number' => 'PO-E2E',
            'po_quantity' => 1000,
            'qty_planned' => 1200,
            'qty_remaining' => 1200,
            'line_number' => 1,
            'product_scope' => 'FLANGE_STAINLESS',
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-9903',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);
        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 1200,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
        ]);

        $printService = app(PrintExecutionService::class);
        $printService->record($line, [
            'qty_good' => 1200,
            'qty_defect' => 0,
            'execution_date' => '2026-08-28',
            'status' => 'FINALIZED',
            'recorded_by' => $this->ppicUser->id,
        ]);

        $qualityService = app(LostWaxQualityService::class);
        $bd = $qualityService->getProductionPlanQuantityBreakdown($plan);

        $this->assertSame(1200, $bd['q_print_good']);
        $this->assertSame(1200, $bd['q_usable']);
        $this->assertSame('NORMAL', $bd['status']);
        $this->assertSame(0, $plan->items()->count()); // Zero ProductionItems required
    }
}
