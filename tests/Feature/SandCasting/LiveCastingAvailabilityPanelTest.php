<?php

namespace Tests\Feature\SandCasting;

use App\Models\Customer;
use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingOrderLine;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LiveCastingAvailabilityPanelTest extends TestCase
{
    use RefreshDatabase;

    protected User $ppicUser;

    protected User $adminUser;

    protected static int $planCounter = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::firstOrCreate(['name' => 'access_planning']);
        $ppicRole = Role::firstOrCreate(['name' => 'ppic']);
        $ppicRole->givePermissionTo($permission);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->givePermissionTo($permission);

        $this->ppicUser = User::factory()->create([
            'email' => 'ppic_panel@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->ppicUser->assignRole('ppic');

        $this->adminUser = User::factory()->create([
            'email' => 'admin_panel@peroniks.com',
            'product_scope' => null,
        ]);
        $this->adminUser->assignRole('admin');

        // Master customers for categorization
        Customer::create(['name' => 'PT LOKAL ABADI', 'category' => Customer::CATEGORY_LOCAL, 'is_active' => true]);
        Customer::create(['name' => 'GLOBAL EXPORT CORP', 'category' => Customer::CATEGORY_EXPORT, 'is_active' => true]);
    }

    protected function createPlan(array $attributes): ProductionPlan
    {
        $id = self::$planCounter++;
        $defaults = [
            'code' => 'SC-CODE-'.$id,
            'title' => 'Title '.$id,
            'item_code' => '4.'.(100 + $id),
            'item_name' => 'FLANGE BESI '.$id,
            'po_number' => 'PO-'.(1000 + $id),
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'weight' => 2.50,
            'line_number' => 1,
            'customer' => 'PT LOKAL ABADI',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ];

        return ProductionPlan::create(array_merge($defaults, $attributes));
    }

    /**
     * Test 1: Total LOCAL + EXPORT equals overall total (accounting for unclassified).
     */
    public function test_total_local_and_export_equals_overall_total_accounting_for_unclassified(): void
    {
        // 1. LOCAL customer plan
        $this->createPlan([
            'code' => 'SC-LOC-01',
            'title' => 'Rencana Local 1',
            'qty_planned' => 100,
            'weight' => 2.50,
            'line_number' => 1,
            'customer' => 'PT LOKAL ABADI',
        ]);

        // 2. EXPORT customer plan
        $this->createPlan([
            'code' => 'SC-EXP-01',
            'title' => 'Rencana Export 1',
            'qty_planned' => 60,
            'weight' => 4.00,
            'line_number' => 2,
            'customer' => 'GLOBAL EXPORT CORP',
        ]);

        // 3. UNCLASSIFIED customer plan (unknown customer not in master and not STOK)
        $this->createPlan([
            'code' => 'SC-UNC-01',
            'title' => 'Rencana Unclassified 1',
            'qty_planned' => 40,
            'weight' => 5.00,
            'line_number' => 3,
            'customer' => 'UNKNOWN CUSTOMER XYZ',
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.plans', ['tab' => 'plans']));

        $response->assertOk();
        $baseline = $response->viewData('baselineAvailability');

        $this->assertNotNull($baseline);
        $this->assertEquals(200, $baseline['total']['pcs']);
        $this->assertEquals(100, $baseline['categories']['LOCAL']['pcs']);
        $this->assertEquals(60, $baseline['categories']['EXPORT']['pcs']);
        $this->assertEquals(40, $baseline['categories']['UNCLASSIFIED']['pcs']);

        // Check summation
        $sumCategoriesPcs = $baseline['categories']['LOCAL']['pcs']
            + $baseline['categories']['EXPORT']['pcs']
            + $baseline['categories']['UNCLASSIFIED']['pcs'];
        $this->assertEquals($baseline['total']['pcs'], $sumCategoriesPcs);

        $sumCategoriesKg = round($baseline['categories']['LOCAL']['kg']
            + $baseline['categories']['EXPORT']['kg']
            + $baseline['categories']['UNCLASSIFIED']['kg'], 2);
        $this->assertEquals($baseline['total']['kg'], $sumCategoriesKg);
    }

    /**
     * Test 2: Total Line 1–4 equals overall total (accounting for unassigned lines).
     */
    public function test_total_line_1_to_4_equals_overall_total_accounting_for_unassigned_lines(): void
    {
        // Line 1
        $this->createPlan([
            'code' => 'SC-L1',
            'qty_planned' => 50,
            'weight' => 2.00,
            'line_number' => 1,
            'customer' => 'PT LOKAL ABADI',
        ]);

        // Line 2
        $this->createPlan([
            'code' => 'SC-L2',
            'qty_planned' => 40,
            'weight' => 3.00,
            'line_number' => 2,
            'customer' => 'PT LOKAL ABADI',
        ]);

        // Line 3
        $this->createPlan([
            'code' => 'SC-L3',
            'qty_planned' => 30,
            'weight' => 4.00,
            'line_number' => 3,
            'customer' => 'GLOBAL EXPORT CORP',
        ]);

        // Line 4
        $this->createPlan([
            'code' => 'SC-L4',
            'qty_planned' => 20,
            'weight' => 5.00,
            'line_number' => 4,
            'customer' => 'GLOBAL EXPORT CORP',
        ]);

        // Line Unassigned (0)
        $this->createPlan([
            'code' => 'SC-L0',
            'qty_planned' => 15,
            'weight' => 6.00,
            'line_number' => 0,
            'customer' => 'PT LOKAL ABADI',
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.plans', ['tab' => 'plans']));

        $response->assertOk();
        $baseline = $response->viewData('baselineAvailability');

        $this->assertEquals(50, $baseline['lines'][1]['pcs']);
        $this->assertEquals(40, $baseline['lines'][2]['pcs']);
        $this->assertEquals(30, $baseline['lines'][3]['pcs']);
        $this->assertEquals(20, $baseline['lines'][4]['pcs']);
        $this->assertEquals(15, $baseline['lines'][0]['pcs']);

        $sumLinesPcs = $baseline['lines'][1]['pcs']
            + $baseline['lines'][2]['pcs']
            + $baseline['lines'][3]['pcs']
            + $baseline['lines'][4]['pcs']
            + $baseline['lines'][0]['pcs'];
        $this->assertEquals($baseline['total']['pcs'], $sumLinesPcs);
        $this->assertEquals(155, $baseline['total']['pcs']);

        $sumLinesKg = round(
            $baseline['lines'][1]['kg']
            + $baseline['lines'][2]['kg']
            + $baseline['lines'][3]['kg']
            + $baseline['lines'][4]['kg']
            + $baseline['lines'][0]['kg'],
            2
        );
        $this->assertEquals($baseline['total']['kg'], $sumLinesKg);
    }

    /**
     * Test 3: Kg and pcs consistency with product weight resolver.
     */
    public function test_kg_and_pcs_consistency_with_product_weight_resolver(): void
    {
        // Plan 1: 50 pcs * 3.50 kg = 175.00 kg
        $this->createPlan([
            'code' => 'SC-W1',
            'qty_planned' => 50,
            'weight' => 3.50,
            'line_number' => 1,
            'customer' => 'PT LOKAL ABADI',
        ]);

        // Plan 2: 25 pcs * 10.25 kg = 256.25 kg
        $this->createPlan([
            'code' => 'SC-W2',
            'qty_planned' => 25,
            'weight' => 10.25,
            'line_number' => 2,
            'customer' => 'GLOBAL EXPORT CORP',
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.plans', ['tab' => 'plans']));

        $response->assertOk();
        $baseline = $response->viewData('baselineAvailability');

        $this->assertEquals(75, $baseline['total']['pcs']);
        $this->assertEquals(431.25, $baseline['total']['kg']); // 175.00 + 256.25
        $this->assertEquals(175.00, $baseline['lines'][1]['kg']);
        $this->assertEquals(256.25, $baseline['lines'][2]['kg']);
    }

    /**
     * Test 4: Line with 0 PO displays 0 pcs and 'Tidak ada PO'.
     */
    public function test_line_with_zero_po_displays_zero_pcs_and_tidak_ada_po(): void
    {
        // Only Line 1 has demand
        $this->createPlan([
            'code' => 'SC-L1-ONLY',
            'qty_planned' => 80,
            'weight' => 2.00,
            'line_number' => 1,
            'customer' => 'PT LOKAL ABADI',
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.plans', ['tab' => 'plans']));

        $response->assertOk();
        $baseline = $response->viewData('baselineAvailability');

        $this->assertEquals(80, $baseline['lines'][1]['pcs']);
        $this->assertEquals(0, $baseline['lines'][2]['pcs']);
        $this->assertEquals(0, $baseline['lines'][3]['pcs']);
        $this->assertEquals(0, $baseline['lines'][4]['pcs']);

        // Check HTML renders "Tidak ada PO" for lines 2, 3, 4
        $response->assertSee('id="panel-line-2-no-po-badge"', false);
        $response->assertSee('id="panel-line-3-no-po-badge"', false);
        $response->assertSee('id="panel-line-4-no-po-badge"', false);
        $response->assertSee('Tidak ada PO');
    }

    /**
     * Test 5: Filter on customer or code updates baseline correctly with filter_active = true.
     */
    public function test_filter_on_customer_or_code_updates_baseline_correctly_with_filter_active_true(): void
    {
        $this->createPlan([
            'code' => 'SC-ALPHA-10',
            'qty_planned' => 100,
            'weight' => 2.00,
            'line_number' => 1,
            'customer' => 'PT LOKAL ABADI',
        ]);

        $this->createPlan([
            'code' => 'SC-BETA-20',
            'qty_planned' => 50,
            'weight' => 3.00,
            'line_number' => 2,
            'customer' => 'GLOBAL EXPORT CORP',
        ]);

        // Unfiltered request: both plans included
        $unfilteredResp = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.plans', ['tab' => 'plans']));
        $this->assertFalse($unfilteredResp->viewData('baselineAvailability')['filter_active']);
        $this->assertEquals(150, $unfilteredResp->viewData('baselineAvailability')['total']['pcs']);
        $unfilteredResp->assertDontSee('id="panel-filter-active-badge"', false);

        // Filter by code: 'ALPHA'
        $filteredResp = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.plans', ['tab' => 'plans', 'code' => 'ALPHA']));
        $filteredResp->assertOk();
        $filteredBaseline = $filteredResp->viewData('baselineAvailability');

        $this->assertTrue($filteredBaseline['filter_active']);
        $this->assertEquals(100, $filteredBaseline['total']['pcs']);
        $this->assertEquals(200.00, $filteredBaseline['total']['kg']);
        $this->assertEquals(100, $filteredBaseline['lines'][1]['pcs']);
        $this->assertEquals(0, $filteredBaseline['lines'][2]['pcs']);
        $filteredResp->assertSee('id="panel-filter-active-badge"', false);
        $filteredResp->assertSee('Filter Aktif');
    }

    /**
     * Test 6: Plans page view renders all panel elements successfully.
     */
    public function test_plans_page_view_renders_all_panel_elements_successfully(): void
    {
        $plan = $this->createPlan([
            'code' => 'SC-PANEL-VIEW',
            'qty_planned' => 120,
            'weight' => 4.50,
            'line_number' => 2,
            'customer' => 'PT LOKAL ABADI',
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.plans', ['tab' => 'plans']));

        $response->assertOk()
            ->assertSee('id="live-casting-availability-panel"', false)
            ->assertSee('SISA KUOTA')
            ->assertSee('id="panel-total-rem-pcs"', false)
            ->assertSee('id="panel-total-rem-kg"', false)
            ->assertSee('id="panel-local-rem-pcs"', false)
            ->assertSee('id="panel-local-rem-kg"', false)
            ->assertSee('id="panel-export-rem-pcs"', false)
            ->assertSee('id="panel-export-rem-kg"', false)
            ->assertSee('id="panel-line-1-card"', false)
            ->assertSee('id="panel-line-2-card"', false)
            ->assertSee('id="panel-line-3-card"', false)
            ->assertSee('id="panel-line-4-card"', false)
            ->assertSee('id="panel-line-2-pct"', false)
            ->assertSee('id="panel-line-2-loc-kg"', false)
            ->assertSee('id="panel-line-2-exp-kg"', false)
            ->assertSee('data-origin-line="2"', false)
            ->assertSee('const BASELINE_AVAILABILITY =', false);
    }

    /**
     * Test 7: Items already completed in PCOR are excluded from available quota.
     */
    public function test_items_already_completed_in_pcor_are_excluded_from_available_quota(): void
    {
        $completedPlan = $this->createPlan([
            'code' => 'SC-COMPLETED-01',
            'qty_planned' => 100,
            'weight' => 5.00,
            'line_number' => 1,
            'customer' => 'PT LOKAL ABADI',
        ]);

        // Create PCOR that orders and fulfills all 100 pcs
        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261109-0099',
            'scheduled_date' => now()->toDateString(),
            'status' => 'COMPLETED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = SandCastingCastingOrderLine::create([
            'sand_casting_casting_order_id' => $order->id,
            'production_plan_id' => $completedPlan->id,
            'code' => $completedPlan->code,
            'item_name' => $completedPlan->item_name,
            'qty_ordered' => 100,
            'line_number' => 1,
        ]);

        $castResult = SandCastingCastingResult::create([
            'heat_number' => 'HEAT-01',
            'cast_date' => now()->toDateString(),
            'recorded_by' => $this->ppicUser->id,
        ]);

        SandCastingCastingResultLine::create([
            'sand_casting_casting_result_id' => $castResult->id,
            'sand_casting_casting_order_line_id' => $line->id,
            'traveler_number' => 'KTR-20261109-0001',
            'heat_number' => 'HEAT-01',
            'qty_poured' => 100,
            'qty_good' => 100,
            'qty_defect' => 0,
        ]);

        // Active plan with available quota
        $activePlan = $this->createPlan([
            'code' => 'SC-ACTIVE-01',
            'qty_planned' => 75,
            'weight' => 2.00,
            'line_number' => 2,
            'customer' => 'PT LOKAL ABADI',
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.plans', ['tab' => 'plans']));

        $response->assertOk();
        $baseline = $response->viewData('baselineAvailability');

        // Total available must be 75 pcs (from $activePlan only), NOT 175 pcs
        $this->assertEquals(75, $baseline['total']['pcs']);
        $this->assertEquals(150.00, $baseline['total']['kg']);
        $this->assertArrayNotHasKey($completedPlan->id, $baseline['items']);
        $this->assertArrayHasKey($activePlan->id, $baseline['items']);
    }

    /**
     * Test 8: Line distribution percentage is based on weight kg including unassigned.
     */
    public function test_line_distribution_percentage_is_based_on_weight_kg_including_unassigned(): void
    {
        // Equal pcs (50 pcs each), but Line 1 is heavy (3.0 kg/pc = 150 kg), Line 2 is light (1.0 kg/pc = 50 kg)
        // Total weight = 200 kg.
        // Weight-based %: Line 1 = 150/200 = 75%, Line 2 = 50/200 = 25%.
        $this->createPlan([
            'code' => 'SC-HEAVY-L1',
            'qty_planned' => 50,
            'weight' => 3.00,
            'line_number' => 1,
            'customer' => 'PT LOKAL ABADI',
        ]);

        $this->createPlan([
            'code' => 'SC-LIGHT-L2',
            'qty_planned' => 50,
            'weight' => 1.00,
            'line_number' => 2,
            'customer' => 'GLOBAL EXPORT CORP',
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.plans', ['tab' => 'plans']));

        $response->assertOk();

        // Line 1 should render 75%, Line 2 should render 25%, Line 3 should render 0%
        $response->assertSee('id="panel-line-1-pct" class="text-[10px] font-bold font-mono text-amber-700">75%</span>', false);
        $response->assertSee('id="panel-line-2-pct" class="text-[10px] font-bold font-mono text-amber-700">25%</span>', false);
        $response->assertSee('id="panel-line-3-pct" class="text-[10px] font-bold font-mono text-slate-400">0%</span>', false);
        $response->assertSee('id="panel-line-4-pct" class="text-[10px] font-bold font-mono text-slate-400">0%</span>', false);
    }

    /**
     * Test 9: Line card displays stacked vertical Local and Export details.
     */
    public function test_line_card_displays_stacked_vertical_local_and_export_details(): void
    {
        $this->createPlan([
            'code' => 'SC-STACKED-01',
            'qty_planned' => 60,
            'weight' => 2.50,
            'line_number' => 1,
            'customer' => 'PT LOKAL ABADI',
        ]);

        $this->createPlan([
            'code' => 'SC-STACKED-02',
            'qty_planned' => 40,
            'weight' => 2.50,
            'line_number' => 1,
            'customer' => 'GLOBAL EXPORT CORP',
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.plans', ['tab' => 'plans']));

        $response->assertOk();
        $response->assertSee('L: Local');
        $response->assertSee('E: Export');
        $response->assertSee('id="panel-line-1-loc-kg"', false);
        $response->assertSee('id="panel-line-1-exp-kg"', false);
    }

    /**
     * Test 10: Perintah Cor page has no Line dropdown and displays read-only Line badge from production plan.
     */
    public function test_perintah_cor_page_has_no_line_dropdown_and_renders_read_only_line(): void
    {
        $this->createPlan([
            'code' => 'SC-RO-03',
            'qty_planned' => 100,
            'weight' => 3.50,
            'line_number' => 3,
            'customer' => 'PT LOKAL ABADI',
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.plans', ['tab' => 'plans']));

        $response->assertOk();
        // Absolutely NO dropdown select for line in the table
        $response->assertDontSee('plan-line-select', false);
        $response->assertDontSee('data-plan-line', false);
        // Displays read-only badge Line 3
        $response->assertSee('Line 3');
    }
}
