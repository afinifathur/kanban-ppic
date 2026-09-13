<?php

namespace Tests\Feature;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\ProductionItem;
use App\Models\ProductionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductionPlanDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_production_plan_does_not_silently_default_to_lost_wax(): void
    {
        $plan = new ProductionPlan;
        $this->assertNull($plan->production_domain, 'Newly instantiated ProductionPlan must not have an automatic default domain.');

        $planExplicit = ProductionPlan::create([
            'code' => '26AB001',
            'title' => 'Test Explicit',
            'item_code' => '4.101105K.A0015',
            'item_name' => 'SS304 CASTED PLANE FLANGE JIS 5K 1/2"',
            'po_number' => 'PO-001',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'status' => 'planning',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
        ]);

        $this->assertEquals(ProductionPlan::DOMAIN_LOST_WAX, $planExplicit->production_domain);
        $this->assertTrue($planExplicit->isLostWax());
        $this->assertFalse($planExplicit->isSandCasting());
    }

    public function test_creating_lost_wax_planning_group_assigns_lost_wax_domain_to_all_rows(): void
    {
        $user = User::factory()->create();

        $payload = [
            'title' => 'Rencana A06',
            'date' => '2026-08-23',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'plans' => [
                [
                    'code' => '26AB001',
                    'item_code' => '4.101105K.A0015',
                    'item_name' => 'SS304 CASTED PLANE FLANGE JIS 5K 1/2"',
                    'po_number' => 'PO-101',
                    'qty_planned' => 50,
                    'line_number' => 1,
                    'customer' => 'PT ABC',
                ],
                [
                    'code' => '26AB002',
                    'item_code' => '4.1091150LB.A0015',
                    'item_name' => 'SS304 CASTED SORF FLANGE ANSI 150LBS 1/2"',
                    'po_number' => 'PO-102',
                    'qty_planned' => 60,
                    'line_number' => 2,
                    'customer' => 'PT XYZ',
                ],
            ],
        ];

        $response = $this->actingAs($user)->postJson(route('plan.store'), $payload);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('production_plans', [
            'code' => '26AB001',
            'title' => 'Rencana A06',
            'production_domain' => 'LOST_WAX',
        ]);

        $this->assertDatabaseHas('production_plans', [
            'code' => '26AB002',
            'title' => 'Rencana A06',
            'production_domain' => 'LOST_WAX',
        ]);

        $this->assertEquals(2, ProductionPlan::where('production_domain', 'LOST_WAX')->count());
        $this->assertEquals(0, ProductionPlan::where('production_domain', 'SAND_CASTING')->count());
    }

    public function test_creating_sand_casting_planning_group_assigns_sand_casting_domain_to_all_rows(): void
    {
        $user = User::factory()->create();

        $payload = [
            'title' => 'Rencana B12 Sand Casting',
            'date' => '2026-08-24',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'plans' => [
                [
                    'code' => 'LH083',
                    'item_code' => 'SC-4.101105K.A0015',
                    'item_name' => 'FC250 CASTED FLANGE 1/2"',
                    'po_number' => 'PO-SC-01',
                    'qty_planned' => 200,
                    'line_number' => 1,
                    'customer' => 'PT Mitra Sand',
                ],
                [
                    'code' => 'LH084',
                    'item_code' => 'SC-4.1091150LB.A0015',
                    'item_name' => 'FCD450 CASTED FLANGE 1/2"',
                    'po_number' => 'PO-SC-02',
                    'qty_planned' => 300,
                    'line_number' => 2,
                    'customer' => 'PT Mitra Sand',
                ],
            ],
        ];

        $response = $this->actingAs($user)->postJson(route('plan.store'), $payload);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('production_plans', [
            'code' => 'LH083',
            'title' => 'Rencana B12 Sand Casting',
            'production_domain' => 'SAND_CASTING',
        ]);

        $this->assertDatabaseHas('production_plans', [
            'code' => 'LH084',
            'title' => 'Rencana B12 Sand Casting',
            'production_domain' => 'SAND_CASTING',
        ]);

        $this->assertEquals(2, ProductionPlan::where('production_domain', 'SAND_CASTING')->count());
        $this->assertEquals(0, ProductionPlan::where('production_domain', 'LOST_WAX')->count());
    }

    public function test_missing_production_domain_is_rejected(): void
    {
        $user = User::factory()->create();

        $payload = [
            'title' => 'Rencana Tanpa Domain',
            'date' => '2026-08-25',
            'plans' => [
                [
                    'code' => '26AB001',
                    'item_code' => '4.101105K.A0015',
                    'item_name' => 'SS304 FLANGE',
                    'po_number' => 'PO-101',
                    'qty_planned' => 50,
                    'line_number' => 1,
                ],
            ],
        ];

        $response = $this->actingAs($user)->postJson(route('plan.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['production_domain']);
    }

    public function test_empty_string_production_domain_is_rejected(): void
    {
        $user = User::factory()->create();

        $payload = [
            'title' => 'Rencana Empty Domain',
            'date' => '2026-08-25',
            'production_domain' => '',
            'plans' => [
                [
                    'code' => '26AB001',
                    'item_code' => '4.101105K.A0015',
                    'item_name' => 'SS304 FLANGE',
                    'po_number' => 'PO-101',
                    'qty_planned' => 50,
                    'line_number' => 1,
                ],
            ],
        ];

        $response = $this->actingAs($user)->postJson(route('plan.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['production_domain']);
    }

    public function test_null_production_domain_is_rejected(): void
    {
        $user = User::factory()->create();

        $payload = [
            'title' => 'Rencana Null Domain',
            'date' => '2026-08-25',
            'production_domain' => null,
            'plans' => [
                [
                    'code' => '26AB001',
                    'item_code' => '4.101105K.A0015',
                    'item_name' => 'SS304 FLANGE',
                    'po_number' => 'PO-101',
                    'qty_planned' => 50,
                    'line_number' => 1,
                ],
            ],
        ];

        $response = $this->actingAs($user)->postJson(route('plan.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['production_domain']);
    }

    public function test_invalid_production_domain_is_rejected(): void
    {
        $user = User::factory()->create();

        $payload = [
            'title' => 'Rencana Invalid Domain',
            'date' => '2026-08-25',
            'production_domain' => 'FORGING',
            'plans' => [
                [
                    'code' => '26AB001',
                    'item_code' => '4.101105K.A0015',
                    'item_name' => 'SS304 FLANGE',
                    'po_number' => 'PO-101',
                    'qty_planned' => 50,
                    'line_number' => 1,
                ],
            ],
        ];

        $response = $this->actingAs($user)->postJson(route('plan.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['production_domain']);
    }

    public function test_index_filters_by_production_domain(): void
    {
        $user = User::factory()->create();

        ProductionPlan::create([
            'code' => 'LW-01',
            'title' => 'Lost Wax Group',
            'item_code' => 'ITM-LW',
            'item_name' => 'Item LW',
            'po_number' => 'PO-LW',
            'qty_planned' => 10,
            'qty_remaining' => 10,
            'line_number' => 1,
            'status' => 'planning',
            'production_domain' => 'LOST_WAX',
            'created_at' => '2026-08-20 08:00:00',
        ]);

        ProductionPlan::create([
            'code' => 'SC-01',
            'title' => 'Sand Casting Group',
            'item_code' => 'ITM-SC',
            'item_name' => 'Item SC',
            'po_number' => 'PO-SC',
            'qty_planned' => 20,
            'qty_remaining' => 20,
            'line_number' => 1,
            'status' => 'planning',
            'production_domain' => 'SAND_CASTING',
            'created_at' => '2026-08-21 08:00:00',
        ]);

        // Filter ALL
        $responseAll = $this->actingAs($user)->get(route('plan.index', ['production_domain' => 'ALL']));
        $responseAll->assertStatus(200);
        $responseAll->assertSee('Lost Wax Group');
        $responseAll->assertSee('Sand Casting Group');

        // Filter LOST_WAX
        $responseLw = $this->actingAs($user)->get(route('plan.index', ['production_domain' => 'LOST_WAX']));
        $responseLw->assertStatus(200);
        $responseLw->assertSee('Lost Wax Group');
        $responseLw->assertDontSee('Sand Casting Group');

        // Filter SAND_CASTING
        $responseSc = $this->actingAs($user)->get(route('plan.index', ['production_domain' => 'SAND_CASTING']));
        $responseSc->assertStatus(200);
        $responseSc->assertSee('Sand Casting Group');
        $responseSc->assertDontSee('Lost Wax Group');
    }

    public function test_detail_view_displays_domain_correctly(): void
    {
        $user = User::factory()->create();

        ProductionPlan::create([
            'code' => 'SC-100',
            'title' => 'Sand Casting Group 1',
            'item_code' => 'ITM-SC',
            'item_name' => 'Casting Flange SC',
            'po_number' => 'PO-SC-100',
            'qty_planned' => 45,
            'qty_remaining' => 45,
            'line_number' => 1,
            'status' => 'planning',
            'production_domain' => 'SAND_CASTING',
            'created_at' => '2026-08-22 08:00:00',
        ]);

        $response = $this->actingAs($user)->get(route('plan.index', ['date' => '2026-08-22']));
        $response->assertStatus(200);
        $response->assertSee('SAND CASTING');
        $response->assertSee('SC-100');
        $response->assertSee('Casting Flange SC');
    }

    public function test_domain_edit_allowed_when_plan_is_unstarted_and_has_no_transactions(): void
    {
        $user = User::factory()->create();

        $plan = ProductionPlan::create([
            'code' => 'FRESH-01',
            'title' => 'Fresh Plan',
            'item_code' => 'ITM-01',
            'item_name' => 'Fresh Flange',
            'po_number' => 'PO-FRESH',
            'qty_planned' => 50,
            'qty_remaining' => 50,
            'line_number' => 1,
            'status' => 'planning',
            'production_domain' => 'LOST_WAX',
        ]);

        $response = $this->actingAs($user)->put(route('plan.update', $plan->id), [
            'line_number' => 1,
            'po_number' => 'PO-FRESH',
            'item_code' => 'ITM-01',
            'item_name' => 'Fresh Flange',
            'qty_planned' => 50,
            'status' => 'planning',
            'production_domain' => 'SAND_CASTING',
        ]);

        $response->assertRedirect();
        $this->assertEquals('SAND_CASTING', $plan->fresh()->production_domain);
    }

    public function test_domain_edit_blocked_when_plan_has_print_order_lines(): void
    {
        $user = User::factory()->create();

        $plan = ProductionPlan::create([
            'code' => 'LOCKED-01',
            'title' => 'Locked Plan',
            'item_code' => 'ITM-01',
            'item_name' => 'Locked Flange',
            'po_number' => 'PO-LOCKED',
            'qty_planned' => 50,
            'qty_remaining' => 50,
            'line_number' => 1,
            'status' => 'planning',
            'production_domain' => 'LOST_WAX',
        ]);

        // Create PrintOrderLine referencing this plan
        $printOrder = LostWaxPrintOrder::create([
            'print_order_number' => 'PO-PRINT-01',
            'scheduled_date' => now(),
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $printOrder->id,
            'production_plan_id' => $plan->id,
            'qty_ordered' => 10,
            'code' => 'LOCKED-01',
            'customer' => 'Customer A',
            'item_name' => 'Locked Flange',
        ]);

        $response = $this->actingAs($user)->put(route('plan.update', $plan->id), [
            'line_number' => 1,
            'po_number' => 'PO-LOCKED',
            'item_code' => 'ITM-01',
            'item_name' => 'Locked Flange',
            'qty_planned' => 50,
            'status' => 'planning',
            'production_domain' => 'SAND_CASTING',
        ]);

        $response->assertSessionHas('error');
        $this->assertEquals('LOST_WAX', $plan->fresh()->production_domain);
    }

    public function test_domain_edit_blocked_when_plan_is_active_or_completed(): void
    {
        $user = User::factory()->create();

        $plan = ProductionPlan::create([
            'code' => 'ACTIVE-01',
            'title' => 'Active Plan',
            'item_code' => 'ITM-01',
            'item_name' => 'Active Flange',
            'po_number' => 'PO-ACT',
            'qty_planned' => 50,
            'qty_remaining' => 30,
            'line_number' => 1,
            'status' => 'active',
            'production_domain' => 'LOST_WAX',
        ]);

        $response = $this->actingAs($user)->put(route('plan.update', $plan->id), [
            'line_number' => 1,
            'po_number' => 'PO-ACT',
            'item_code' => 'ITM-01',
            'item_name' => 'Active Flange',
            'qty_planned' => 50,
            'status' => 'active',
            'production_domain' => 'SAND_CASTING',
        ]);

        $response->assertSessionHas('error');
        $this->assertEquals('LOST_WAX', $plan->fresh()->production_domain);
    }

    public function test_domain_edit_blocked_when_plan_has_production_items(): void
    {
        $user = User::factory()->create();

        $plan = ProductionPlan::create([
            'code' => 'ITEM-LOCK-01',
            'title' => 'Item Locked Plan',
            'item_code' => 'ITM-01',
            'item_name' => 'Locked Flange',
            'po_number' => 'PO-ITM-LOCK',
            'qty_planned' => 50,
            'qty_remaining' => 50,
            'line_number' => 1,
            'status' => 'planning',
            'production_domain' => 'SAND_CASTING',
        ]);

        ProductionItem::create([
            'plan_id' => $plan->id,
            'code' => 'COR-01',
            'heat_number' => 'H9999',
            'item_code' => 'ITM-01',
            'item_name' => 'Locked Flange',
            'qty_pcs' => 20,
            'current_dept' => 'cor',
            'dept_entry_at' => now(),
        ]);

        $response = $this->actingAs($user)->put(route('plan.update', $plan->id), [
            'line_number' => 1,
            'po_number' => 'PO-ITM-LOCK',
            'item_code' => 'ITM-01',
            'item_name' => 'Locked Flange',
            'qty_planned' => 50,
            'status' => 'planning',
            'production_domain' => 'LOST_WAX',
        ]);

        $response->assertSessionHas('error');
        $this->assertEquals('SAND_CASTING', $plan->fresh()->production_domain);
    }

    public function test_existing_product_scope_filtering_preserved(): void
    {
        Role::findOrCreate('ppic');
        $user = User::factory()->create([
            'product_scope' => 'FLANGE_BESI',
        ]);
        $user->assignRole('ppic');

        ProductionPlan::create([
            'code' => 'BESI-01',
            'title' => 'Rencana Besi',
            'item_code' => 'ITM-BESI',
            'item_name' => 'Flange Besi',
            'po_number' => 'PO-BESI',
            'qty_planned' => 10,
            'qty_remaining' => 10,
            'line_number' => 1,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => 'SAND_CASTING',
            'created_at' => '2026-08-25 08:00:00',
        ]);

        ProductionPlan::create([
            'code' => 'SS-01',
            'title' => 'Rencana Stainless',
            'item_code' => 'ITM-SS',
            'item_name' => 'Flange Stainless',
            'po_number' => 'PO-SS',
            'qty_planned' => 10,
            'qty_remaining' => 10,
            'line_number' => 1,
            'product_scope' => 'FLANGE_STAINLESS',
            'production_domain' => 'LOST_WAX',
            'created_at' => '2026-08-26 08:00:00',
        ]);

        $response = $this->actingAs($user)->get(route('plan.index'));
        $response->assertStatus(200);
        $response->assertSee('Rencana Besi');
        $response->assertDontSee('Rencana Stainless');
    }

    public function test_delete_plan_allowed_for_fresh_unstarted_plan(): void
    {
        $user = User::factory()->create();

        $plan = ProductionPlan::create([
            'code' => 'DELETE-ME',
            'title' => 'Delete Test',
            'item_code' => 'ITM-DEL',
            'item_name' => 'To be deleted',
            'po_number' => 'PO-DEL',
            'qty_planned' => 10,
            'qty_remaining' => 10,
            'line_number' => 1,
            'status' => 'planning',
            'production_domain' => 'SAND_CASTING',
        ]);

        $response = $this->actingAs($user)->delete(route('plan.destroy', $plan->id));
        $response->assertRedirect();
        $this->assertDatabaseMissing('production_plans', ['id' => $plan->id]);
    }
}
