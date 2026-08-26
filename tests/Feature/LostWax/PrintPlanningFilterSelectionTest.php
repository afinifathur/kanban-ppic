<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\ProductionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintPlanningFilterSelectionTest extends TestCase
{
    use RefreshDatabase;

    private User $ppicUser;

    protected function setUp(): void
    {
        parent::setUp();

        $accessPlanning = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'access_planning']);
        $accessExecution = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'access_execution']);

        $ppicRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ppic']);
        $ppicRole->syncPermissions([$accessPlanning, $accessExecution]);

        $this->ppicUser = User::factory()->create(['product_scope' => 'FLANGE_STAINLESS']);
        $this->ppicUser->assignRole('ppic');
    }

    protected function createProductionPlan(array $attributes = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'code' => 'AB01',
            'customer' => 'CUST_A',
            'item_code' => '4.101105K.A0020',
            'item_name' => 'SS304 JIS 5K 3/4"',
            'aisi' => '304',
            'size' => '3/4"',
            'weight' => 0.75,
            'po_number' => 'PO-AB-01',
            'qty_planned' => 200,
            'qty_remaining' => 200,
            'line_number' => 1,
            'status' => 'planning',
            'product_scope' => 'FLANGE_STAINLESS',
        ], $attributes));
    }

    /**
     * Verify that the plans page renders the explicit clear button markup and summary indicators.
     */
    public function test_plans_page_renders_clear_selection_button_and_summary_contract(): void
    {
        $plan = $this->createProductionPlan();

        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans'));
        $response->assertOk();

        $html = $response->getContent();

        // Check clear selection button existence
        $this->assertStringContainsString('id="clear-selection-btn"', $html);
        $this->assertStringContainsString('Bersihkan Pilihan', $html);

        // Check reset button does not have data-clear-selection-reset attribute
        $this->assertStringNotContainsString('data-clear-selection-reset="true"', $html);

        // Check summary bar elements
        $this->assertStringContainsString('id="selection-summary"', $html);
        $this->assertStringContainsString('id="summary-count"', $html);
        $this->assertStringContainsString('id="summary-qty"', $html);
        $this->assertStringContainsString('id="summary-weight"', $html);
    }

    /**
     * Verify filter by customer shows matching rows with correct data-qty and data-weight.
     */
    public function test_filter_by_customer_and_code_exposes_correct_metadata(): void
    {
        $planA1 = $this->createProductionPlan(['code' => 'CODE-A1', 'customer' => 'CUST_A', 'qty_planned' => 100, 'weight' => 1.25]);
        $planA2 = $this->createProductionPlan(['code' => 'CODE-A2', 'customer' => 'CUST_A', 'qty_planned' => 150, 'weight' => 2.00]);
        $planB1 = $this->createProductionPlan(['code' => 'CODE-B1', 'customer' => 'CUST_B', 'qty_planned' => 200, 'weight' => 0.50]);

        // Filter for CUST_A
        $responseA = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['customer' => 'CUST_A']));
        $responseA->assertOk();
        $htmlA = $responseA->getContent();
        preg_match('/<tbody[^>]*>(.*?)<\/tbody>/is', $htmlA, $matchesA);
        $tbodyA = $matchesA[1] ?? '';

        $this->assertStringContainsString('CODE-A1', $tbodyA);
        $this->assertStringContainsString('CODE-A2', $tbodyA);
        $this->assertStringNotContainsString('CODE-B1', $tbodyA);
        $this->assertStringContainsString('data-qty="100"', $tbodyA);
        $this->assertStringContainsString('data-weight="1.25"', $tbodyA);
        $this->assertStringContainsString('data-qty="150"', $tbodyA);
        $this->assertStringContainsString('data-weight="2.00"', $tbodyA);

        // Filter for CUST_B
        $responseB = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['customer' => 'CUST_B']));
        $responseB->assertOk();
        $htmlB = $responseB->getContent();
        preg_match('/<tbody[^>]*>(.*?)<\/tbody>/is', $htmlB, $matchesB);
        $tbodyB = $matchesB[1] ?? '';

        $this->assertStringNotContainsString('CODE-A1', $tbodyB);
        $this->assertStringNotContainsString('CODE-A2', $tbodyB);
        $this->assertStringContainsString('CODE-B1', $tbodyB);
        $this->assertStringContainsString('data-qty="200"', $tbodyB);
        $this->assertStringContainsString('data-weight="0.50"', $tbodyB);
    }

    /**
     * Verify that Print Order create and store workflows accept plans selected across multiple different filters.
     */
    public function test_print_order_creation_accepts_accumulated_cross_filter_plan_ids(): void
    {
        // 3 plans under 3 different customer filters
        $planA = $this->createProductionPlan(['code' => 'PLAN-A', 'customer' => 'CUSTOMER_ALPHA', 'qty_planned' => 100]);
        $planB = $this->createProductionPlan(['code' => 'PLAN-B', 'customer' => 'CUSTOMER_BETA', 'qty_planned' => 200]);
        $planC = $this->createProductionPlan(['code' => 'PLAN-C', 'customer' => 'CUSTOMER_GAMMA', 'qty_planned' => 300]);

        // Simulated accumulated selection from user filtering Alpha, Beta, Gamma
        $accumulatedIds = [$planA->id, $planB->id, $planC->id];

        // Create page GET request with accumulated IDs
        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.create', [
            'plan_ids' => $accumulatedIds,
        ]));
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('PLAN-A', $html);
        $this->assertStringContainsString('PLAN-B', $html);
        $this->assertStringContainsString('PLAN-C', $html);
        $this->assertStringContainsString('CUSTOMER_ALPHA', $html);
        $this->assertStringContainsString('CUSTOMER_BETA', $html);
        $this->assertStringContainsString('CUSTOMER_GAMMA', $html);

        // Store the print order with all 3 items
        $storeResponse = $this->actingAs($this->ppicUser)->post(route('lost-wax.print-orders.store'), [
            'scheduled_date' => '2026-08-26',
            'print_order_number' => 'PC-20260826-FILTER-001',
            'items' => [
                ['production_plan_id' => $planA->id, 'qty_ordered' => 100],
                ['production_plan_id' => $planB->id, 'qty_ordered' => 200],
                ['production_plan_id' => $planC->id, 'qty_ordered' => 300],
            ],
        ]);

        $order = LostWaxPrintOrder::where('print_order_number', 'PC-20260826-FILTER-001')->first();
        $this->assertNotNull($order);
        $this->assertSame(3, $order->lines()->count());
        $this->assertEqualsCanonicalizing(
            ['PLAN-A', 'PLAN-B', 'PLAN-C'],
            $order->lines()->pluck('code')->all()
        );
    }

    /**
     * Verify filter reset link returns full list without breaking planning page.
     */
    public function test_reset_filters_route_loads_all_active_plans(): void
    {
        $plan1 = $this->createProductionPlan(['code' => 'P1', 'customer' => 'CUST_1']);
        $plan2 = $this->createProductionPlan(['code' => 'P2', 'customer' => 'CUST_2']);

        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'plans']));
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('P1', $html);
        $this->assertStringContainsString('P2', $html);
    }
}
