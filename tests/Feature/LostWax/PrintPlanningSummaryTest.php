<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\ProductionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the "Selected Items Summary" bar on the Print Planning page
 * (/lost-wax/print-orders/plans).
 *
 * The summary itself is computed client-side from the persistent selection
 * state (sessionStorage). These tests verify the server-rendered contract that
 * the summary depends on:
 *   1. The summary bar markup renders with three metrics at zero.
 *   2. Every selectable plan checkbox exposes data-qty (remaining-to-schedule)
 *      and data-weight (unit weight) so the client can aggregate them.
 *   3. Metadata stays correct across pagination and for over-scheduled plans.
 *   4. Authorization boundaries are preserved (admin read-only).
 */
class PrintPlanningSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $ppicUser;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $accessPlanning = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'access_planning']);
        $accessExecution = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'access_execution']);

        $adminRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
        $ppicRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ppic']);

        $adminRole->syncPermissions([$accessPlanning, $accessExecution]);
        $ppicRole->syncPermissions([$accessPlanning, $accessExecution]);

        $this->ppicUser = User::factory()->create(['product_scope' => 'FLANGE_STAINLESS']);
        $this->ppicUser->assignRole('ppic');

        $this->adminUser = User::factory()->create(['product_scope' => null]);
        $this->adminUser->assignRole('admin');
    }

    protected function createProductionPlan(array $attributes = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'code' => 'AB01',
            'customer' => 'A06',
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

    public function test_summary_bar_renders_three_metrics_at_zero(): void
    {
        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans'));
        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('id="summary-count"', $html);
        $this->assertStringContainsString('id="summary-qty"', $html);
        $this->assertStringContainsString('id="summary-weight"', $html);

        $this->assertStringContainsString('Item Terpilih', $html);
        $this->assertStringContainsString('Total Qty (PCS)', $html);
        $this->assertStringContainsString('Total Berat (KG)', $html);

        $this->assertStringContainsString('>0 item<', $html);
        $this->assertStringContainsString('>0 pcs<', $html);
        $this->assertStringContainsString('>0 kg<', $html);
    }

    public function test_checkbox_exposes_qty_and_weight_metadata(): void
    {
        $this->createProductionPlan(['qty_planned' => 200, 'weight' => 0.75]);

        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans'));
        $response->assertOk();

        $html = $response->getContent();

        // qty = qty_remaining_scheduled = qty_planned - qty_scheduled = 200 - 0
        $this->assertStringContainsString('data-qty="200"', $html);
        $this->assertStringContainsString('data-weight="0.75"', $html);
    }

    public function test_qty_metadata_uses_remaining_scheduled_not_planned(): void
    {
        $plan = $this->createProductionPlan(['qty_planned' => 200]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260825-SUM-001',
            'scheduled_date' => '2026-08-25',
            'status' => 'DRAFT',
            'created_by' => $this->ppicUser->id,
        ]);
        $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 120,
            'item_name' => $plan->item_name,
        ]);

        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans'));
        $response->assertOk();

        $html = $response->getContent();

        // 200 - 120 = 80 remaining to schedule
        $this->assertStringContainsString('data-qty="80"', $html);
        $this->assertStringNotContainsString('data-qty="200"', $html);
    }

    public function test_qty_metadata_clamped_to_zero_when_over_scheduled(): void
    {
        $plan = $this->createProductionPlan(['qty_planned' => 200]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260825-SUM-002',
            'scheduled_date' => '2026-08-25',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);
        $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 220,
            'item_name' => $plan->item_name,
        ]);

        // Over-scheduled plans are auto-hidden from the active pool; use "all".
        $response = $this->actingAs($this->ppicUser)
            ->get(route('lost-wax.print-orders.plans', ['status' => 'all']));
        $response->assertOk();

        $html = $response->getContent();

        // 200 - 220 = -20 => clamped to 0
        $this->assertStringContainsString('data-qty="0"', $html);
        $this->assertStringContainsString('data-weight="0.75"', $html);
    }

    public function test_cross_pagination_metadata_rendered_on_every_page(): void
    {
        for ($i = 1; $i <= 120; $i++) {
            $this->createProductionPlan([
                'code' => 'PG-'.$i,
                'customer' => 'CUST-'.$i,
                'po_number' => 'PO-'.$i,
                'item_name' => 'Plan '.$i,
            ]);
        }

        $page1 = $this->actingAs($this->ppicUser)
            ->get(route('lost-wax.print-orders.plans', ['status' => 'active', 'plans_page' => 1]));
        $page1->assertOk();
        $this->assertStringContainsString('data-qty="200"', $page1->getContent());
        $this->assertStringContainsString('data-weight="0.75"', $page1->getContent());

        $page2 = $this->actingAs($this->ppicUser)
            ->get(route('lost-wax.print-orders.plans', ['status' => 'active', 'plans_page' => 2]));
        $page2->assertOk();
        $this->assertStringContainsString('data-qty="200"', $page2->getContent());
        $this->assertStringContainsString('data-weight="0.75"', $page2->getContent());
    }

    public function test_summary_bar_not_rendered_on_orders_tab(): void
    {
        $response = $this->actingAs($this->ppicUser)
            ->get(route('lost-wax.print-orders.plans', ['tab' => 'orders']));
        $response->assertOk();

        $this->assertStringNotContainsString('id="summary-count"', $response->getContent());
    }

    public function test_admin_read_only_still_sees_summary_but_no_checkboxes(): void
    {
        $this->createProductionPlan();

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.print-orders.plans'));
        $response->assertOk();

        $html = $response->getContent();

        // Summary bar is informational and still renders for admin.
        $this->assertStringContainsString('id="summary-count"', $html);

        // Admin must not see any selectable checkboxes.
        $this->assertStringNotContainsString('class="plan-checkbox', $html);
    }
}
