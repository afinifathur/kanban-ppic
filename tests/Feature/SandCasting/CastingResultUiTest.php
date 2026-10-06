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

    public function test_search_by_heat_number_exact_and_partial(): void
    {
        $plan = $this->createPlan(['code' => '269ET777']);
        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260916-0001',
            'scheduled_date' => '2026-09-16',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);
        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $result1 = SandCastingCastingResult::create([
            'heat_number' => 'A217092602',
            'cast_date' => '2026-09-16',
            'furnace' => 'F-01',
            'shift' => '1',
            'recorded_by' => $this->ppicUser->id,
        ]);
        $result1->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-16'),
            'qty_good' => 50,
            'qty_reject' => 0,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 125.00,
        ]);

        $result2 = SandCastingCastingResult::create([
            'heat_number' => 'B999092601',
            'cast_date' => '2026-09-16',
            'furnace' => 'F-02',
            'shift' => '1',
            'recorded_by' => $this->ppicUser->id,
        ]);

        // 1. Search Heat Number exact
        $responseExact = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.index', ['heat_number' => 'A217092602']));
        $responseExact->assertOk();
        $responseExact->assertSee('A217092602');
        $responseExact->assertDontSee('B999092601');

        // 2. Search Heat Number partial
        $responsePartial = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.index', ['heat_number' => 'A21709']));
        $responsePartial->assertOk();
        $responsePartial->assertSee('A217092602');
        $responsePartial->assertDontSee('B999092601');
    }

    public function test_search_by_production_code_exact_and_partial(): void
    {
        $planA = $this->createPlan(['code' => '269ET777', 'title' => 'Item Plan A']);
        $planB = $this->createPlan(['code' => '270AB888', 'title' => 'Item Plan B']);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260916-0002',
            'scheduled_date' => '2026-09-16',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);
        $orderLineA = $order->lines()->create([
            'production_plan_id' => $planA->id,
            'qty_ordered' => 100,
            'code' => $planA->code,
            'item_name' => $planA->item_name,
        ]);
        $orderLineB = $order->lines()->create([
            'production_plan_id' => $planB->id,
            'qty_ordered' => 100,
            'code' => $planB->code,
            'item_name' => $planB->item_name,
        ]);

        $heatForA = SandCastingCastingResult::create([
            'heat_number' => 'HEAT-FOR-269ET',
            'cast_date' => '2026-09-16',
            'furnace' => 'F-01',
            'shift' => '1',
            'recorded_by' => $this->ppicUser->id,
        ]);
        $heatForA->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLineA->id,
            'production_plan_id' => $planA->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-16'),
            'qty_good' => 60,
            'qty_reject' => 0,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 150.00,
        ]);

        $heatForB = SandCastingCastingResult::create([
            'heat_number' => 'HEAT-FOR-270AB',
            'cast_date' => '2026-09-16',
            'furnace' => 'F-02',
            'shift' => '2',
            'recorded_by' => $this->ppicUser->id,
        ]);
        $heatForB->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLineB->id,
            'production_plan_id' => $planB->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-16'),
            'qty_good' => 40,
            'qty_reject' => 0,
            'unit_weight_kg' => 3.00,
            'total_weight_kg' => 120.00,
        ]);

        // 1. Exact Production Code search
        $responseExact = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.index', ['heat_number' => '269ET777']));
        $responseExact->assertOk();
        $responseExact->assertSee('HEAT-FOR-269ET');
        $responseExact->assertDontSee('HEAT-FOR-270AB');

        // 2. Partial Production Code search
        $responsePartial = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.index', ['heat_number' => '269ET']));
        $responsePartial->assertOk();
        $responsePartial->assertSee('HEAT-FOR-269ET');
        $responsePartial->assertDontSee('HEAT-FOR-270AB');

        // 3. Search for other Production Code
        $responseB = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.index', ['heat_number' => '270AB888']));
        $responseB->assertOk();
        $responseB->assertSee('HEAT-FOR-270AB');
        $responseB->assertDontSee('HEAT-FOR-269ET');

        // 4. Non-matching search
        $responseNone = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.index', ['heat_number' => 'NONEXISTENT999']));
        $responseNone->assertOk();
        $responseNone->assertDontSee('HEAT-FOR-269ET');
        $responseNone->assertDontSee('HEAT-FOR-270AB');
    }

    public function test_heat_with_multiple_production_codes_is_found_by_any_of_them(): void
    {
        $planA = $this->createPlan(['code' => '269ET777', 'title' => 'Item Plan A']);
        $planB = $this->createPlan(['code' => '268AB002', 'title' => 'Item Plan B']);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260916-0003',
            'scheduled_date' => '2026-09-16',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);
        $orderLineA = $order->lines()->create([
            'production_plan_id' => $planA->id,
            'qty_ordered' => 100,
            'code' => $planA->code,
            'item_name' => $planA->item_name,
        ]);
        $orderLineB = $order->lines()->create([
            'production_plan_id' => $planB->id,
            'qty_ordered' => 100,
            'code' => $planB->code,
            'item_name' => $planB->item_name,
        ]);

        $sharedHeat = SandCastingCastingResult::create([
            'heat_number' => 'HEAT-MULTI-PC-01',
            'cast_date' => '2026-09-16',
            'furnace' => 'F-01',
            'shift' => '1',
            'recorded_by' => $this->ppicUser->id,
        ]);
        $sharedHeat->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLineA->id,
            'production_plan_id' => $planA->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-16'),
            'qty_good' => 30,
            'qty_reject' => 0,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 75.00,
        ]);
        $sharedHeat->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLineB->id,
            'production_plan_id' => $planB->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-16'),
            'qty_good' => 45,
            'qty_reject' => 0,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 112.50,
        ]);

        // Search by Production Code A
        $resA = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.index', ['heat_number' => '269ET777']));
        $resA->assertOk();
        $resA->assertSee('HEAT-MULTI-PC-01');

        // Search by Production Code B
        $resB = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.index', ['heat_number' => '268AB002']));
        $resB->assertOk();
        $resB->assertSee('HEAT-MULTI-PC-01');

        // Search by Heat Number directly
        $resHeat = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.index', ['heat_number' => 'HEAT-MULTI-PC-01']));
        $resHeat->assertOk();
        $resHeat->assertSee('HEAT-MULTI-PC-01');
    }
}
