<?php

namespace Tests\Feature\LostWax;

use App\Models\ProductionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintOrderCreateCalculatorTest extends TestCase
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

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'code' => '268ETB733',
            'customer' => 'CUST01',
            'item_code' => '268ETB733',
            'item_name' => 'SS304 SQUARE DN 25',
            'aisi' => '304',
            'size' => '1"',
            'weight' => 0.75,
            'po_number' => 'PO-001',
            'qty_planned' => 200,
            'qty_remaining' => 200,
            'line_number' => 1,
            'status' => 'planning',
            'product_scope' => 'FLANGE_STAINLESS',
            'is_closed' => false,
        ], $attributes));
    }

    public function test_create_page_renders_summary_bar_elements_and_metadata(): void
    {
        $plan = $this->createPlan(['code' => 'P1', 'weight' => 1.25, 'qty_planned' => 100]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('lost-wax.print-orders.create', ['plan_ids' => [$plan->id]]));

        $response->assertOk();
        $response->assertSee('id="selection-summary-bar"', false);
        $response->assertSee('id="summary-item-count"', false);
        $response->assertSee('id="summary-total-pcs"', false);
        $response->assertSee('id="summary-total-kg"', false);
        $response->assertSee('Item Terpilih');
        $response->assertSee('Total Qty (PCS)');
        $response->assertSee('Total Berat (KG)');
        $response->assertSee('data-weight-per-piece="1.25"', false);
    }

    public function test_create_page_with_multiple_plans_exposes_each_item_weight(): void
    {
        $plan1 = $this->createPlan(['code' => 'ITEM-01', 'weight' => 2.50, 'qty_planned' => 100]);
        $plan2 = $this->createPlan(['code' => 'ITEM-02', 'weight' => 1.80, 'qty_planned' => 50]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('lost-wax.print-orders.create', ['plan_ids' => [$plan1->id, $plan2->id]]));

        $response->assertOk();
        $response->assertSee('data-weight-per-piece="2.50"', false);
        $response->assertSee('data-weight-per-piece="1.80"', false);
        $response->assertSee('2 item');
    }

    public function test_store_action_persists_print_order_and_retains_server_authoritative_integrity(): void
    {
        $plan1 = $this->createPlan(['code' => 'ITEM-01', 'weight' => 2.50, 'qty_planned' => 100]);
        $plan2 = $this->createPlan(['code' => 'ITEM-02', 'weight' => 1.80, 'qty_planned' => 50]);

        $response = $this->actingAs($this->ppicUser)
            ->post(route('lost-wax.print-orders.store'), [
                'print_order_number' => 'PC-TEST-001',
                'scheduled_date' => now()->toDateString(),
                'items' => [
                    ['production_plan_id' => $plan1->id, 'qty_ordered' => 80],
                    ['production_plan_id' => $plan2->id, 'qty_ordered' => 40],
                ],
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('lost_wax_print_orders', [
            'print_order_number' => 'PC-TEST-001',
            'status' => 'DRAFT',
        ]);
        $this->assertDatabaseHas('lost_wax_print_order_lines', [
            'production_plan_id' => $plan1->id,
            'qty_ordered' => 80,
        ]);
        $this->assertDatabaseHas('lost_wax_print_order_lines', [
            'production_plan_id' => $plan2->id,
            'qty_ordered' => 40,
        ]);
    }
}
