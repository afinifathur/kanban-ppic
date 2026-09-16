<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\User;
use App\Services\SandCasting\TravelerNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CastingResultUiTest extends TestCase
{
    use RefreshDatabase;

    protected User $ppicUser;

    protected User $otherScopeUser;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::firstOrCreate(['name' => 'access_planning']);
        $ppicRole = Role::firstOrCreate(['name' => 'ppic']);
        $ppicRole->givePermissionTo($permission);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->givePermissionTo($permission);

        $this->ppicUser = User::factory()->create([
            'email' => 'ppic_flange@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->ppicUser->assignRole('ppic');

        $this->otherScopeUser = User::factory()->create([
            'email' => 'ppic_fitting@peroniks.com',
            'product_scope' => 'FITTING_STAINLESS',
        ]);
        $this->otherScopeUser->assignRole('ppic');

        $this->adminUser = User::factory()->create([
            'email' => 'admin@peroniks.com',
            'product_scope' => null,
        ]);
        $this->adminUser->assignRole('admin');
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'code' => 'LH083',
            'title' => 'Rencana SC LH083',
            'item_code' => '4.101',
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-001',
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'weight' => 2.50,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ], $attributes));
    }

    public function test_create_page_accessible_for_issued_order_and_renders_pool(): void
    {
        $plan = $this->createPlan();

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0001',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.create', ['casting_order_id' => $order->id]));

        $response->assertOk();
        $response->assertSee('Input Hasil Cor');
        $response->assertSee('PCOR-20260914-0001');
        $response->assertSee('LH083');
        $response->assertSee('FLANGE BESI JIS 10K', false);
    }

    public function test_create_page_filters_out_other_scope_items_for_scoped_user(): void
    {
        $planFlange = $this->createPlan(['code' => 'FL001', 'product_scope' => 'FLANGE_BESI']);
        $planFitting = $this->createPlan(['code' => 'FT001', 'product_scope' => 'FITTING_STAINLESS']);

        $order1 = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-FL', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $order1->lines()->create(['production_plan_id' => $planFlange->id, 'qty_ordered' => 100, 'code' => 'FL001', 'item_name' => 'Flange Item']);

        $order2 = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-FT', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->otherScopeUser->id]);
        $order2->lines()->create(['production_plan_id' => $planFitting->id, 'qty_ordered' => 50, 'code' => 'FT001', 'item_name' => 'Fitting Item']);

        // Fitting user sees only Fitting items, not Flange items
        $response = $this->actingAs($this->otherScopeUser)
            ->get(route('sand-casting.casting-results.create'));

        $response->assertOk();
        $response->assertSee('FT001');
        $response->assertDontSee('FL001');
    }

    public function test_backward_compatibility_redirect_from_order_detail(): void
    {
        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0003',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get('/sand-casting/casting-orders/'.$order->id.'/results/create');

        $response->assertRedirect(route('sand-casting.casting-results.create', ['casting_order_id' => $order->id]));
    }

    public function test_successful_store_redirects_to_result_detail(): void
    {
        $plan = $this->createPlan();

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0005',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-results.store'), [
                'heat_number' => 'A213092601',
                'cast_date' => '2026-09-14',
                'furnace' => 'F-01',
                'shift' => '1',
                'items' => [
                    [
                        'sand_casting_casting_order_line_id' => $line->id,
                        'qty_good' => 95,
                        'qty_reject' => 2,
                    ],
                ],
            ]);

        $result = SandCastingCastingResult::where('heat_number', 'A213092601')->firstOrFail();

        $response->assertRedirect(route('sand-casting.casting-results.show', $result));
        $response->assertSessionHas('success');
    }

    public function test_result_detail_displays_heat_production_code_and_traveler(): void
    {
        $plan = $this->createPlan();

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0006',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => 'LH083',
            'item_name' => 'FLANGE BESI JIS 10K 2"',
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'A213092601',
            'cast_date' => '2026-09-14',
            'furnace' => 'FURNACE-1',
            'shift' => '1',
            'operator_name' => 'Hartono',
            'recorded_by' => $this->ppicUser->id,
        ]);

        $traveler = TravelerNumberGenerator::generateNext('2026-09-14');

        $result->lines()->create([
            'sand_casting_casting_order_line_id' => $line->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $traveler,
            'qty_good' => 95,
            'qty_reject' => 2,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 237.50,
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.show', $result));

        $response->assertOk();
        $response->assertSee('A213092601');
        $response->assertSee('LH083');
        $response->assertSee($traveler);
        $response->assertSee('95');
        $response->assertSee('237.50 kg');
    }

    public function test_multiple_production_codes_and_pcors_under_one_heat_render_correctly(): void
    {
        $plan1 = $this->createPlan(['code' => 'LH083', 'item_code' => '4.101', 'item_name' => 'Item A']);
        $plan2 = $this->createPlan(['code' => 'LH084', 'item_code' => '4.102', 'item_name' => 'Item B']);

        $order1 = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0007',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);
        $line1 = $order1->lines()->create([
            'production_plan_id' => $plan1->id,
            'qty_ordered' => 50,
            'code' => 'LH083',
            'item_name' => 'Item A',
        ]);

        $order2 = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0008',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);
        $line2 = $order2->lines()->create([
            'production_plan_id' => $plan2->id,
            'qty_ordered' => 30,
            'code' => 'LH084',
            'item_name' => 'Item B',
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'A213092601',
            'cast_date' => '2026-09-14',
            'recorded_by' => $this->ppicUser->id,
        ]);

        $t1 = TravelerNumberGenerator::generateNext('2026-09-14');
        $result->lines()->create([
            'sand_casting_casting_order_line_id' => $line1->id,
            'production_plan_id' => $plan1->id,
            'traveler_number' => $t1,
            'qty_good' => 50,
        ]);

        $t2 = TravelerNumberGenerator::generateNext('2026-09-14');
        $result->lines()->create([
            'sand_casting_casting_order_line_id' => $line2->id,
            'production_plan_id' => $plan2->id,
            'traveler_number' => $t2,
            'qty_good' => 30,
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.show', $result));

        $response->assertOk();
        $response->assertSee('LH083');
        $response->assertSee('LH084');
        $response->assertSee('PCOR-20260914-0007');
        $response->assertSee('PCOR-20260914-0008');
        $response->assertSee($t1);
        $response->assertSee($t2);
    }

    public function test_perintah_cor_show_renders_results_history(): void
    {
        $plan = $this->createPlan();

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0009',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => 'LH083',
            'item_name' => 'FLANGE BESI JIS 10K 2"',
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'A213092601',
            'cast_date' => '2026-09-14',
            'recorded_by' => $this->ppicUser->id,
        ]);

        $result->lines()->create([
            'sand_casting_casting_order_line_id' => $line->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-14'),
            'qty_good' => 80,
            'qty_reject' => 5,
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.show', $order));

        $response->assertOk();
        $response->assertSee('Input Hasil Cor');
        $response->assertSee('Riwayat Hasil Cor &amp; Heat Number', false);
        $response->assertSee('A213092601');
    }

    public function test_results_index_page_renders_heats_list_and_totals(): void
    {
        $plan = $this->createPlan();

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0010',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 200,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $result1 = SandCastingCastingResult::create([
            'heat_number' => 'A214092601',
            'cast_date' => '2026-09-14',
            'furnace' => 'F-01',
            'shift' => '1',
            'recorded_by' => $this->ppicUser->id,
        ]);

        $result1->lines()->create([
            'sand_casting_casting_order_line_id' => $line->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-14'),
            'qty_good' => 120,
            'qty_reject' => 4,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 300.00,
        ]);

        $result2 = SandCastingCastingResult::create([
            'heat_number' => 'A214092602',
            'cast_date' => '2026-09-14',
            'furnace' => 'F-02',
            'shift' => '2',
            'recorded_by' => $this->ppicUser->id,
        ]);

        $result2->lines()->create([
            'sand_casting_casting_order_line_id' => $line->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-14'),
            'qty_good' => 80,
            'qty_reject' => 2,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 200.00,
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.index'));

        $response->assertOk();
        $response->assertSee('Hasil Cor (Sand Casting)');
        $response->assertSee('A214092601');
        $response->assertSee('A214092602');
        $response->assertSee('120');
        $response->assertSee('80');
        $response->assertSee('F-01');
        $response->assertSee('F-02');
    }

    public function test_reject_does_not_fulfill_good_demand_and_allows_subsequent_good_fill(): void
    {
        $plan = $this->createPlan(['qty_planned' => 100, 'qty_remaining' => 100]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0012',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        // Heat 1: Good = 95, Reject = 5 -> remaining good quota is 5
        $this->actingAs($this->ppicUser)->post(route('sand-casting.casting-results.store'), [
            'heat_number' => 'A214092601',
            'cast_date' => '2026-09-14',
            'items' => [
                [
                    'sand_casting_casting_order_line_id' => $line->id,
                    'qty_good' => 95,
                    'qty_reject' => 5,
                ],
            ],
        ])->assertRedirect();

        $line->refresh();
        $this->assertEquals(95, $line->qty_cast_good);
        $this->assertEquals(5, $line->qty_cast_reject);
        $this->assertEquals(5, $line->qty_remaining_to_cast);

        // Heat 2: Good = 5, Reject = 2 -> fully fulfills remaining 5 good
        $this->actingAs($this->ppicUser)->post(route('sand-casting.casting-results.store'), [
            'heat_number' => 'A214092602',
            'cast_date' => '2026-09-14',
            'items' => [
                [
                    'sand_casting_casting_order_line_id' => $line->id,
                    'qty_good' => 5,
                    'qty_reject' => 2,
                ],
            ],
        ])->assertRedirect();

        $line->refresh();
        $this->assertEquals(100, $line->qty_cast_good);
        $this->assertEquals(7, $line->qty_cast_reject);
        $this->assertEquals(0, $line->qty_remaining_to_cast);
    }

    public function test_lost_wax_source_line_is_rejected_with_403_on_http_post(): void
    {
        $lwPlan = ProductionPlan::create([
            'code' => 'LW-MAL',
            'title' => 'Rencana LW',
            'item_code' => '4.103',
            'item_name' => 'FLANGE BESI 2" LW',
            'po_number' => 'PO-LW-01',
            'qty_planned' => 50,
            'qty_remaining' => 50,
            'line_number' => 1,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0013',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $lwPlan->id,
            'qty_ordered' => 50,
            'code' => $lwPlan->code,
            'item_name' => $lwPlan->item_name,
        ]);

        $response = $this->actingAs($this->ppicUser)->post(route('sand-casting.casting-results.store'), [
            'heat_number' => 'A214092601',
            'cast_date' => '2026-09-14',
            'items' => [
                [
                    'sand_casting_casting_order_line_id' => $line->id,
                    'qty_good' => 20,
                    'qty_reject' => 0,
                ],
            ],
        ]);

        $response->assertStatus(403);
        $this->assertEquals(0, SandCastingCastingResult::count());
    }

    public function test_optional_pcor_filter_does_not_constrain_heat_composition(): void
    {
        $plan1 = $this->createPlan(['code' => '268ET001', 'qty_planned' => 100, 'qty_remaining' => 100]);
        $plan2 = $this->createPlan(['code' => '268AB002', 'qty_planned' => 50, 'qty_remaining' => 50]);

        $order1 = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-001', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $line1 = $order1->lines()->create(['production_plan_id' => $plan1->id, 'qty_ordered' => 100, 'code' => '268ET001', 'item_name' => 'Item 1']);

        $order2 = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-002', 'scheduled_date' => '2026-09-15', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $line2 = $order2->lines()->create(['production_plan_id' => $plan2->id, 'qty_ordered' => 50, 'code' => '268AB002', 'item_name' => 'Item 2']);

        // Submit a single heat combining line1 (from PCOR-001) and line2 (from PCOR-002)
        $response = $this->actingAs($this->ppicUser)->post(route('sand-casting.casting-results.store'), [
            'heat_number' => 'A214092699',
            'cast_date' => '2026-09-14',
            'items' => [
                ['sand_casting_casting_order_line_id' => $line1->id, 'qty_good' => 40, 'qty_reject' => 0],
                ['sand_casting_casting_order_line_id' => $line2->id, 'qty_good' => 30, 'qty_reject' => 0],
            ],
        ]);

        $response->assertRedirect();
        $result = SandCastingCastingResult::where('heat_number', 'A214092699')->firstOrFail();
        $this->assertEquals(2, $result->lines->count());
        $this->assertEquals(70, $result->total_qty_good);
    }

    public function test_create_page_contains_auto_keterangan_and_no_manual_notes_input(): void
    {
        $plan = $this->createPlan(['title' => 'Rencana Khusus Flange']);
        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260915-0001',
            'scheduled_date' => '2026-09-15',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);
        $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $response = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.create'));

        $response->assertOk();
        // Check that manual stage_notes input was removed
        $response->assertDontSee('id="stage_notes"', false);
        // Check that auto-keterangan element is present
        $response->assertSee('id="selectedItemKeterangan"', false);
        $response->assertSee('Rencana Khusus Flange');
        $response->assertSee('qty_remaining_to_cast');
    }
}
