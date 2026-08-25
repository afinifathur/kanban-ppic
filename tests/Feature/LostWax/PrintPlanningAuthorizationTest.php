<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\ProductionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authorization tests for the Print Planning page.
 *
 * Covers T-1 through T-8 from the audit:
 * docs/architecture/print-planning-scope-and-auto-hide-audit.md
 *
 * Security boundary: backend rejects mutations from admin (product_scope=null)
 * with HTTP 403, regardless of UI visibility.
 */
class PrintPlanningAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    private User $ppicFlange;

    private ProductionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $accessPlanning = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'access_planning']);
        $accessExecution = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'access_execution']);

        $adminRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
        $ppicRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ppic']);

        $adminRole->syncPermissions([$accessPlanning, $accessExecution]);
        $ppicRole->syncPermissions([$accessPlanning, $accessExecution]);

        // Admin PPIC aggregator — no product_scope
        $this->adminUser = User::factory()->create([
            'email' => 'adminppicpf@peroniks.com',
            'product_scope' => null,
        ]);
        $this->adminUser->assignRole('admin');

        // PPIC Flange — scoped owner
        $this->ppicFlange = User::factory()->create([
            'email' => 'ppicflange@peroniks.com',
            'product_scope' => 'FLANGE_STAINLESS',
        ]);
        $this->ppicFlange->assignRole('ppic');

        // A plan in FLANGE_STAINLESS scope
        $this->plan = ProductionPlan::create([
            'code' => 'SS-AUDIT-001',
            'customer' => 'Cust-A',
            'item_code' => 'LY001',
            'item_name' => 'SS304 Flange',
            'product_scope' => 'FLANGE_STAINLESS',
            'aisi' => '304',
            'qty_planned' => 500,
            'qty_remaining' => 500,
            'status' => 'planning',
            'line_number' => 1,
            'po_number' => 'PO-AUDIT-001',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // T-1: Admin → close_plan → 403
    // ─────────────────────────────────────────────────────────────────────────

    public function test_t1_admin_cannot_close_plan(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->post(route('lost-wax.print-orders.store'), [
                'action' => 'close_plan',
                'production_plan_id' => $this->plan->id,
            ]);

        $response->assertStatus(403);
        $this->assertFalse($this->plan->fresh()->is_closed);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // T-2: Admin → open_plan → 403
    // ─────────────────────────────────────────────────────────────────────────

    public function test_t2_admin_cannot_open_plan(): void
    {
        $this->plan->update(['is_closed' => true]);

        $response = $this->actingAs($this->adminUser)
            ->post(route('lost-wax.print-orders.store'), [
                'action' => 'open_plan',
                'production_plan_id' => $this->plan->id,
            ]);

        $response->assertStatus(403);
        $this->assertTrue($this->plan->fresh()->is_closed);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // T-3: Admin → bulk_close_plans → 403
    // ─────────────────────────────────────────────────────────────────────────

    public function test_t3_admin_cannot_bulk_close_plans(): void
    {
        $plan2 = ProductionPlan::create([
            'code' => 'SS-AUDIT-002',
            'customer' => 'Cust-B',
            'item_code' => 'LY002',
            'item_name' => 'SS304 Flange B',
            'product_scope' => 'FLANGE_STAINLESS',
            'aisi' => '304',
            'qty_planned' => 300,
            'qty_remaining' => 300,
            'status' => 'planning',
            'line_number' => 2,
            'po_number' => 'PO-AUDIT-002',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->post(route('lost-wax.print-orders.store'), [
                'action' => 'bulk_close_plans',
                'plan_ids' => [$this->plan->id, $plan2->id],
            ]);

        $response->assertStatus(403);
        $this->assertFalse($this->plan->fresh()->is_closed);
        $this->assertFalse($plan2->fresh()->is_closed);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // T-4: Admin → GET create Print Order page → 403
    // ─────────────────────────────────────────────────────────────────────────

    public function test_t4_admin_cannot_access_create_print_order_page(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->get(route('lost-wax.print-orders.create', ['plan_ids' => [$this->plan->id]]));

        $response->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // T-5: Admin → POST store Print Order → 403
    // ─────────────────────────────────────────────────────────────────────────

    public function test_t5_admin_cannot_store_print_order(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->post(route('lost-wax.print-orders.store'), [
                'scheduled_date' => '2026-08-25',
                'print_order_number' => 'PC-20260825-9999',
                'items' => [
                    [
                        'production_plan_id' => $this->plan->id,
                        'qty_ordered' => 100,
                    ],
                ],
            ]);

        $response->assertStatus(403);
        $this->assertSame(0, LostWaxPrintOrder::count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // T-6: Admin can still read all plans across scopes (aggregator view)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_t6_admin_can_view_all_plans_read_only(): void
    {
        $planBesi = ProductionPlan::create([
            'code' => 'FE-AUDIT-001',
            'customer' => 'Cust-C',
            'item_code' => 'LY003',
            'item_name' => 'Flange Besi',
            'product_scope' => 'FLANGE_BESI',
            'aisi' => 'FE360',
            'qty_planned' => 200,
            'qty_remaining' => 200,
            'status' => 'planning',
            'line_number' => 3,
            'po_number' => 'PO-AUDIT-003',
        ]);

        $planFitting = ProductionPlan::create([
            'code' => 'FIT-AUDIT-001',
            'customer' => 'Cust-D',
            'item_code' => 'LY004',
            'item_name' => 'SS304 Elbow',
            'product_scope' => 'FITTING_STAINLESS',
            'aisi' => '304',
            'qty_planned' => 150,
            'qty_remaining' => 150,
            'status' => 'planning',
            'line_number' => 4,
            'po_number' => 'PO-AUDIT-004',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get(route('lost-wax.print-orders.plans', ['status' => 'all']));

        $response->assertOk();
        // Admin sees all three scopes
        $response->assertSee('SS-AUDIT-001');  // FLANGE_STAINLESS
        $response->assertSee('FE-AUDIT-001');  // FLANGE_BESI
        $response->assertSee('FIT-AUDIT-001'); // FITTING_STAINLESS

        // Admin must NOT see mutation buttons
        $response->assertDontSee('id="select-all"');
        $response->assertDontSee('id="submit-btn"');
        $response->assertDontSee('id="bulk-close-btn"');
        $response->assertDontSee('Buat Perintah Cetak');
        $response->assertDontSee('Tutup Terpilih');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // T-7: Fully-scheduled plan auto-hides from active pool without manual close
    // ─────────────────────────────────────────────────────────────────────────

    public function test_t7_fully_scheduled_plan_auto_hides_from_active_pool(): void
    {
        // Plan: 500 pcs planned
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260825-T7-0001',
            'scheduled_date' => '2026-08-25',
            'status' => 'ISSUED',
            'created_by' => $this->ppicFlange->id,
        ]);
        $order->lines()->create([
            'production_plan_id' => $this->plan->id,
            'qty_ordered' => 500, // fully scheduled
            'item_name' => $this->plan->item_name,
        ]);

        // Plan is NOT manually closed
        $this->assertFalse($this->plan->fresh()->is_closed);

        // Active pool should NOT show it (remaining = 0)
        $response = $this->actingAs($this->ppicFlange)
            ->get(route('lost-wax.print-orders.plans', ['status' => 'active']));
        $response->assertOk();

        $html = $response->getContent();
        preg_match('/<tbody[^>]*>(.*?)<\/tbody>/is', $html, $matches);
        $tbody = $matches[1] ?? '';
        $this->assertStringNotContainsString('SS-AUDIT-001', $tbody);

        // But visible under ?status=all
        $response = $this->actingAs($this->ppicFlange)
            ->get(route('lost-wax.print-orders.plans', ['status' => 'all']));
        $response->assertOk();
        $response->assertSee('SS-AUDIT-001');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // T-8: Cancelled PO restores remaining to schedule → plan reappears in active
    // ─────────────────────────────────────────────────────────────────────────

    public function test_t8_cancelled_print_order_restores_remaining_schedule(): void
    {
        // PO-1: 300 pcs — ISSUED
        $order1 = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260825-T8-0001',
            'scheduled_date' => '2026-08-25',
            'status' => 'ISSUED',
            'created_by' => $this->ppicFlange->id,
        ]);
        $order1->lines()->create([
            'production_plan_id' => $this->plan->id,
            'qty_ordered' => 300,
            'item_name' => $this->plan->item_name,
        ]);

        // PO-2: 200 pcs — ISSUED (now total scheduled = 500 = qty_planned)
        $order2 = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260825-T8-0002',
            'scheduled_date' => '2026-08-25',
            'status' => 'ISSUED',
            'created_by' => $this->ppicFlange->id,
        ]);
        $order2->lines()->create([
            'production_plan_id' => $this->plan->id,
            'qty_ordered' => 200,
            'item_name' => $this->plan->item_name,
        ]);

        // At this point plan is fully scheduled → not in active pool
        $freshPlan = $this->plan->fresh();
        $this->assertSame(500, $freshPlan->qty_scheduled);
        $this->assertSame(0, $freshPlan->qty_remaining_scheduled);

        $response = $this->actingAs($this->ppicFlange)
            ->get(route('lost-wax.print-orders.plans', ['status' => 'active']));
        $html = $response->getContent();
        preg_match('/<tbody[^>]*>(.*?)<\/tbody>/is', $html, $matches);
        $this->assertStringNotContainsString('SS-AUDIT-001', $matches[1] ?? '');

        // Cancel PO-2 (200 pcs)
        $order2->update(['status' => 'CANCELLED']);

        // Now effective scheduled = 300, remaining = 200 → plan should reappear
        $freshPlan = $this->plan->fresh();
        $this->assertSame(300, $freshPlan->qty_scheduled);
        $this->assertSame(200, $freshPlan->qty_remaining_scheduled);

        $response = $this->actingAs($this->ppicFlange)
            ->get(route('lost-wax.print-orders.plans', ['status' => 'active']));
        $html = $response->getContent();
        preg_match('/<tbody[^>]*>(.*?)<\/tbody>/is', $html, $matches);
        $this->assertStringContainsString('SS-AUDIT-001', $matches[1] ?? '');

        // And qty_planned must not have changed
        $this->assertSame(500, $this->plan->fresh()->qty_planned);

        // Re-schedule the cancelled quantity; the plan should hide again once fully scheduled.
        $order3 = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260825-T8-0003',
            'scheduled_date' => '2026-08-25',
            'status' => 'ISSUED',
            'created_by' => $this->ppicFlange->id,
        ]);
        $order3->lines()->create([
            'production_plan_id' => $this->plan->id,
            'qty_ordered' => 200,
            'item_name' => $this->plan->item_name,
        ]);

        $freshPlan = $this->plan->fresh();
        $this->assertSame(500, $freshPlan->qty_scheduled);
        $this->assertSame(0, $freshPlan->qty_remaining_scheduled);

        $response = $this->actingAs($this->ppicFlange)
            ->get(route('lost-wax.print-orders.plans', ['status' => 'active']));
        $html = $response->getContent();
        preg_match('/<tbody[^>]*>(.*?)<\/tbody>/is', $html, $matches);
        $this->assertStringNotContainsString('SS-AUDIT-001', $matches[1] ?? '');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Regression: PPIC can still close own-scope plan
    // ─────────────────────────────────────────────────────────────────────────

    public function test_ppic_can_close_own_scope_plan(): void
    {
        $response = $this->actingAs($this->ppicFlange)
            ->post(route('lost-wax.print-orders.store'), [
                'action' => 'close_plan',
                'production_plan_id' => $this->plan->id,
            ]);

        $response->assertRedirect();
        $this->assertTrue($this->plan->fresh()->is_closed);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Regression: PPIC cannot close plan belonging to another scope
    // ─────────────────────────────────────────────────────────────────────────

    public function test_ppic_cannot_close_other_scope_plan(): void
    {
        $planBesi = ProductionPlan::create([
            'code' => 'FE-REGRESS-001',
            'customer' => 'Cust-X',
            'item_code' => 'LY099',
            'item_name' => 'Flange Besi',
            'product_scope' => 'FLANGE_BESI',
            'aisi' => 'FE360',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'status' => 'planning',
            'line_number' => 9,
            'po_number' => 'PO-REGRESS-001',
        ]);

        $response = $this->actingAs($this->ppicFlange)
            ->post(route('lost-wax.print-orders.store'), [
                'action' => 'close_plan',
                'production_plan_id' => $planBesi->id,
            ]);

        $response->assertStatus(403);
        $this->assertFalse($planBesi->fresh()->is_closed);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Regression: PPIC can create Print Order for own-scope plan
    // ─────────────────────────────────────────────────────────────────────────

    public function test_ppic_can_create_print_order_for_own_scope(): void
    {
        $response = $this->actingAs($this->ppicFlange)
            ->post(route('lost-wax.print-orders.store'), [
                'scheduled_date' => '2026-08-25',
                'print_order_number' => 'PC-20260825-PPIC-001',
                'items' => [
                    [
                        'production_plan_id' => $this->plan->id,
                        'qty_ordered' => 200,
                    ],
                ],
            ]);

        $response->assertRedirect();
        $this->assertSame(1, LostWaxPrintOrder::count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Regression: PPIC cannot create Print Order for other scope plan
    // ─────────────────────────────────────────────────────────────────────────

    public function test_ppic_cannot_create_print_order_for_other_scope(): void
    {
        $planBesi = ProductionPlan::create([
            'code' => 'FE-REGRESS-002',
            'customer' => 'Cust-Y',
            'item_code' => 'LY098',
            'item_name' => 'Flange Besi',
            'product_scope' => 'FLANGE_BESI',
            'aisi' => 'FE360',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'status' => 'planning',
            'line_number' => 10,
            'po_number' => 'PO-REGRESS-002',
        ]);

        $response = $this->actingAs($this->ppicFlange)
            ->post(route('lost-wax.print-orders.store'), [
                'scheduled_date' => '2026-08-25',
                'print_order_number' => 'PC-20260825-PPIC-002',
                'items' => [
                    [
                        'production_plan_id' => $planBesi->id,
                        'qty_ordered' => 50,
                    ],
                ],
            ]);

        $response->assertStatus(403);
        $this->assertSame(0, LostWaxPrintOrder::count());
    }
}
