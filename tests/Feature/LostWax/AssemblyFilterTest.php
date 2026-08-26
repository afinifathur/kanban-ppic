<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssemblyFilterTest extends TestCase
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

    protected function createPrintOrderLine($order, $plan, $attributes = [])
    {
        return $order->lines()->create(array_merge([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'qty_actual_good' => 50, // ensures qty_available_for_rangkai > 0
        ], $attributes));
    }

    public function test_filter_by_kode_produksi(): void
    {
        $user = User::factory()->create();
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0001',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $plan1 = $this->createProductionPlan(['code' => '26AB001']);
        $plan2 = $this->createProductionPlan(['code' => '26AB002']);

        $line1 = $this->createPrintOrderLine($order, $plan1);
        $line2 = $this->createPrintOrderLine($order, $plan2);

        $response = $this->actingAs($user)->get(route('lost-wax.assemblies.index', [
            'tab' => 'available',
            'code' => '26AB001',
        ]));

        $response->assertStatus(200);
        $response->assertSee('26AB001');
        $response->assertDontSee('26AB002');
    }

    public function test_filter_by_customer(): void
    {
        $user = User::factory()->create();
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0001',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $plan1 = $this->createProductionPlan(['code' => '26AB001', 'customer' => 'A06']);
        $plan2 = $this->createProductionPlan(['code' => '26AB002', 'customer' => 'B09']);

        $line1 = $this->createPrintOrderLine($order, $plan1);
        $line2 = $this->createPrintOrderLine($order, $plan2);

        $response = $this->actingAs($user)->get(route('lost-wax.assemblies.index', [
            'tab' => 'available',
            'customer' => 'A06',
        ]));

        $response->assertStatus(200);
        $response->assertSee('26AB001');
        $response->assertDontSee('26AB002');
    }

    public function test_filter_by_size(): void
    {
        $user = User::factory()->create();
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0001',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $plan1 = $this->createProductionPlan(['code' => '26AB001', 'size' => '1/2"']);
        $plan2 = $this->createProductionPlan(['code' => '26AB002', 'size' => '2"']);

        $line1 = $this->createPrintOrderLine($order, $plan1);
        $line2 = $this->createPrintOrderLine($order, $plan2);

        $response = $this->actingAs($user)->get(route('lost-wax.assemblies.index', [
            'tab' => 'available',
            'size' => '1/2"',
        ]));

        $response->assertStatus(200);
        $response->assertSee('26AB001');
        $response->assertDontSee('26AB002');
    }

    public function test_filter_by_combined(): void
    {
        $user = User::factory()->create();
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0001',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        // Line 1: Customer A06, Size 1/2"
        $plan1 = $this->createProductionPlan(['code' => '26AB001', 'customer' => 'A06', 'size' => '1/2"']);
        // Line 2: Customer A06, Size 2"
        $plan2 = $this->createProductionPlan(['code' => '26AB002', 'customer' => 'A06', 'size' => '2"']);
        // Line 3: Customer B09, Size 1/2"
        $plan3 = $this->createProductionPlan(['code' => '26AB003', 'customer' => 'B09', 'size' => '1/2"']);

        $line1 = $this->createPrintOrderLine($order, $plan1);
        $line2 = $this->createPrintOrderLine($order, $plan2);
        $line3 = $this->createPrintOrderLine($order, $plan3);

        $response = $this->actingAs($user)->get(route('lost-wax.assemblies.index', [
            'tab' => 'available',
            'customer' => 'A06',
            'size' => '1/2"',
        ]));

        $response->assertStatus(200);
        $response->assertSee('26AB001');
        $response->assertDontSee('26AB002');
        $response->assertDontSee('26AB003');
    }

    public function test_tab2_filter_by_kode_produksi(): void
    {
        $user = User::factory()->create();
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0001',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $plan1 = $this->createProductionPlan(['code' => '26AB001']);
        $plan2 = $this->createProductionPlan(['code' => '26AB002']);

        $line1 = $this->createPrintOrderLine($order, $plan1);
        $line2 = $this->createPrintOrderLine($order, $plan2);

        $service = app(\App\Services\RangkaiExecutionService::class);
        $wo1 = $service->createWorkOrder($line1, [
            'qty_trees_planned' => 2,
            'tree_capacity' => 20,
            'created_by' => $user->id,
        ]);
        $wo2 = $service->createWorkOrder($line2, [
            'qty_trees_planned' => 2,
            'tree_capacity' => 20,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('lost-wax.assemblies.work-orders.index', [
            'code' => '26AB001',
        ]));

        $response->assertStatus(200);
        $response->assertSee($wo1->rangkai_order_number);
        $response->assertDontSee($wo2->rangkai_order_number);
    }

    public function test_tab2_filter_by_customer(): void
    {
        $user = User::factory()->create();
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0001',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $plan1 = $this->createProductionPlan(['code' => '26AB001', 'customer' => 'A06']);
        $plan2 = $this->createProductionPlan(['code' => '26AB002', 'customer' => 'B09']);

        $line1 = $this->createPrintOrderLine($order, $plan1);
        $line2 = $this->createPrintOrderLine($order, $plan2);

        $service = app(\App\Services\RangkaiExecutionService::class);
        $wo1 = $service->createWorkOrder($line1, [
            'qty_trees_planned' => 2,
            'tree_capacity' => 20,
            'created_by' => $user->id,
        ]);
        $wo2 = $service->createWorkOrder($line2, [
            'qty_trees_planned' => 2,
            'tree_capacity' => 20,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('lost-wax.assemblies.work-orders.index', [
            'customer' => 'A06',
        ]));

        $response->assertStatus(200);
        $response->assertSee($wo1->rangkai_order_number);
        $response->assertDontSee($wo2->rangkai_order_number);
    }

    public function test_tab2_filter_by_size(): void
    {
        $user = User::factory()->create();
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0001',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $plan1 = $this->createProductionPlan(['code' => '26AB001', 'size' => '1/2"']);
        $plan2 = $this->createProductionPlan(['code' => '26AB002', 'size' => '2"']);

        $line1 = $this->createPrintOrderLine($order, $plan1);
        $line2 = $this->createPrintOrderLine($order, $plan2);

        $service = app(\App\Services\RangkaiExecutionService::class);
        $wo1 = $service->createWorkOrder($line1, [
            'qty_trees_planned' => 2,
            'tree_capacity' => 20,
            'created_by' => $user->id,
        ]);
        $wo2 = $service->createWorkOrder($line2, [
            'qty_trees_planned' => 2,
            'tree_capacity' => 20,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('lost-wax.assemblies.work-orders.index', [
            'size' => '1/2"',
        ]));

        $response->assertStatus(200);
        $response->assertSee($wo1->rangkai_order_number);
        $response->assertDontSee($wo2->rangkai_order_number);
    }

    public function test_tab2_filter_by_combined(): void
    {
        $user = User::factory()->create();
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0001',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        // Plan 1: Customer A06, Size 1/2"
        $plan1 = $this->createProductionPlan(['code' => '26AB001', 'customer' => 'A06', 'size' => '1/2"']);
        // Plan 2: Customer A06, Size 2"
        $plan2 = $this->createProductionPlan(['code' => '26AB002', 'customer' => 'A06', 'size' => '2"']);
        // Plan 3: Customer B09, Size 1/2"
        $plan3 = $this->createProductionPlan(['code' => '26AB003', 'customer' => 'B09', 'size' => '1/2"']);

        $line1 = $this->createPrintOrderLine($order, $plan1);
        $line2 = $this->createPrintOrderLine($order, $plan2);
        $line3 = $this->createPrintOrderLine($order, $plan3);

        $service = app(\App\Services\RangkaiExecutionService::class);
        $wo1 = $service->createWorkOrder($line1, [
            'qty_trees_planned' => 2,
            'tree_capacity' => 20,
            'created_by' => $user->id,
        ]);
        $wo2 = $service->createWorkOrder($line2, [
            'qty_trees_planned' => 2,
            'tree_capacity' => 20,
            'created_by' => $user->id,
        ]);
        $wo3 = $service->createWorkOrder($line3, [
            'qty_trees_planned' => 2,
            'tree_capacity' => 20,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('lost-wax.assemblies.work-orders.index', [
            'customer' => 'A06',
            'size' => '1/2"',
        ]));

        $response->assertStatus(200);
        $response->assertSee($wo1->rangkai_order_number);
        $response->assertDontSee($wo2->rangkai_order_number);
        $response->assertDontSee($wo3->rangkai_order_number);
    }

    public function test_pagination_retains_filters(): void
    {
        $user = User::factory()->create();
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0001',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $plan = $this->createProductionPlan(['code' => '26AB001', 'customer' => 'A06', 'size' => '1/2"']);
        $line = $this->createPrintOrderLine($order, $plan);

        $service = app(\App\Services\RangkaiExecutionService::class);
        // Create 20 Work Orders to trigger pagination (since perPage = 15)
        for ($i = 0; $i < 20; $i++) {
            $service->createWorkOrder($line, [
                'qty_trees_planned' => 2,
                'tree_capacity' => 20,
                'created_by' => $user->id,
            ]);
        }

        $response = $this->actingAs($user)->get(route('lost-wax.assemblies.work-orders.index', [
            'customer' => 'A06',
            'size' => '1/2"',
        ]));

        $response->assertStatus(200);

        // Assert that the page links contain customer and size parameters
        $response->assertSee('customer=A06');
        $response->assertSee('size=1%2F2'); // URL encoded 1/2"
    }

    public function test_backward_compatibility_tab_redirect(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('lost-wax.assemblies.index', [
            'tab' => 'work-orders',
            'customer' => 'A06',
        ]));

        $response->assertRedirect(route('lost-wax.assemblies.work-orders.index', [
            'customer' => 'A06',
        ]));
    }
}
