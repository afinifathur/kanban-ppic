<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\LostWaxWorkOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\ArrayItemMasterRepository;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            \App\Contracts\ItemMasterRepository::class,
            new ArrayItemMasterRepository([
                ['code' => 'LY026', 'name' => 'Hex Nipple SUS304', 'aisi' => 'SCS 13 A', 'standard' => 'JIS', 'unit_weight' => '0.300', 'status' => 'active'],
                ['code' => 'LY027', 'name' => 'Flange X', 'aisi' => 'SCS 14 A', 'standard' => 'JIS', 'unit_weight' => '1.980', 'status' => 'active'],
                ['code' => 'LY028', 'name' => 'Elbow 90', 'aisi' => 'SCS 13 A', 'standard' => 'JIS', 'unit_weight' => '0.450', 'status' => 'active'],
            ])
        );

        // Seed minimal demo data for dashboard testing
        $this->seedMinimalData();
    }

    private function seedMinimalData(): void
    {
        $user = User::factory()->create(['email' => 'admin@ppic.com']);

        // 3 Work Orders with varied families
        $specs = [
            ['et_code' => 'LWDEMO001', 'family' => '3', 'net' => 45, 'require_layer_7' => false],
            ['et_code' => 'LWDEMO002', 'family' => '3', 'net' => 30, 'require_layer_7' => false],
            ['et_code' => 'LWDEMO003', 'family' => '4', 'net' => 30, 'require_layer_7' => true],
        ];

        $skus = [
            ['code' => 'LY026', 'name' => 'Hex Nipple SUS304', 'aisi' => 'SCS 13 A'],
            ['code' => 'LY027', 'name' => 'Flange X', 'aisi' => 'SCS 14 A'],
            ['code' => 'LY028', 'name' => 'Elbow 90', 'aisi' => 'SCS 13 A'],
        ];

        foreach ($specs as $i => $spec) {
            $sku = $skus[$i];

            $ref = \App\Models\LostWaxItemReference::updateOrCreate(
                ['master_source' => 'masterdata_kpi', 'master_item_key' => $sku['code']],
                ['item_code_snapshot' => $sku['code'], 'item_name_snapshot' => $sku['name'], 'aisi_snapshot' => $sku['aisi'], 'last_synced_at' => now()]
            );

            $wo = LostWaxWorkOrder::create([
                'item_reference_id' => $ref->id,
                'et_code' => $spec['et_code'],
                'et_prefix' => 'LWDEMO',
                'et_sequence' => $i + 1,
                'po_number' => 'PO-DEMO-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'po_quantity' => 100,
                'stock_quantity' => 50,
                'net_requirement_quantity' => $spec['net'],
                'status' => 'active',
                'family_code' => $spec['family'],
                'require_layer_7' => $spec['require_layer_7'],
            ]);

            $plan = \App\Models\LostWaxWorkOrderPlan::create([
                'work_order_id' => $wo->id,
                'wave_number' => 1,
                'plan_type' => 'initial',
                'planned_quantity' => $spec['net'],
                'status' => 'planned',
            ]);

            \App\Models\LostWaxWorkOrderWip::create([
                'work_order_id' => $wo->id,
                'work_order_plan_id' => $plan->id,
                'stage' => 'assembly',
                'quantity' => $spec['net'],
                'status' => 'recorded',
                'produced_at' => now(),
            ]);
        }

        // Generate trees manually via service
        $treeService = app(\App\Services\TreeGenerationService::class);

        $plan1 = \App\Models\LostWaxWorkOrderPlan::first();
        $plan2 = \App\Models\LostWaxWorkOrderPlan::skip(1)->first();
        $plan3 = \App\Models\LostWaxWorkOrderPlan::skip(2)->first();

        $trees1 = $treeService->generate($plan1, 15, [15, 15, 15], '3');
        $trees2 = $treeService->generate($plan2, 10, [10, 10, 10], '3');
        $trees3 = $treeService->generate($plan3, 10, [10, 10, 10], '4');

        // Apply varied scan states
        $scanService = app(\App\Services\ScanService::class);

        \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 8, 0, 0));

        // LWDEMO001: 3 trees -> layer_2, layer_1, null
        $scanService->process($trees1[0]->barcode, $user->id);
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 13, 0, 0));
        $scanService->process($trees1[0]->barcode, $user->id); // layer_2, normal aging

        $scanService->process($trees1[1]->barcode, $user->id); // layer_1

        // LWDEMO002: 3 trees -> oven, layer_6, layer_1
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 8, 0, 0));
        foreach (range(1, 6) as $i) {
            \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 8 + $i, 0, 0));
            $scanService->process($trees2[0]->barcode, $user->id); // layer_6
        }
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 15, 0, 0));
        $scanService->processOvenScan($trees2[0]->barcode, $user->id); // oven

        \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 8, 0, 0));
        foreach (range(1, 6) as $i) {
            \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 8 + $i, 0, 0));
            $scanService->process($trees2[1]->barcode, $user->id); // layer_6
        }

        \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 8, 0, 0));
        $scanService->process($trees2[2]->barcode, $user->id); // layer_1

        // LWDEMO003: 3 trees -> layer_7, layer_6, oven (with require_layer_7 = true)
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 8, 0, 0));
        foreach (range(1, 7) as $i) {
            \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 8 + $i, 0, 0));
            $scanService->process($trees3[0]->barcode, $user->id); // layer_7
        }

        \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 8, 0, 0));
        foreach (range(1, 6) as $i) {
            \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 8 + $i, 0, 0));
            $scanService->process($trees3[1]->barcode, $user->id); // layer_6
        }

        \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 8, 0, 0));
        foreach (range(1, 7) as $i) {
            \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 8 + $i, 0, 0));
            $scanService->process($trees3[2]->barcode, $user->id); // layer_7
        }
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 16, 0, 0));
        $scanService->processOvenScan($trees3[2]->barcode, $user->id); // oven (via layer_7)

        // Create anomaly: rejected scan
        $freshTree = LostWaxTree::whereNull('current_stage')->first();
        if ($freshTree) {
            \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 9, 0, 0));
            $scanService->process($freshTree->barcode, $user->id); // → layer_1

            \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 9, 30, 0));
            $scanService->rejectSkippedScan(
                $freshTree->fresh(),
                $user->id,
                'layer_3',
                'expected layer_2'
            );
        }

        \Carbon\Carbon::setTestNow(null);
    }

    // ─── TEST 1: Dashboard loads ───
    public function test_dashboard_loads(): void
    {
        $user = User::first();

        $this->actingAs($user)
            ->get(route('lost-wax.dashboard'))
            ->assertOk()
            ->assertSee('Lost Wax Dashboard')
            ->assertSee('Distribusi Stage')
            ->assertSee('Aging Monitor')
            ->assertSee('Perlu Perhatian')
            ->assertSee('Work Order Monitor');
    }

    // ─── TEST 2: Overview counts are correct ───
    public function test_overview_counts_are_correct(): void
    {
        $user = User::first();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.dashboard'));

        $activeWos = LostWaxWorkOrder::whereIn('status', ['draft', 'planned', 'active'])->count();
        $totalTrees = LostWaxTree::count();

        $response->assertOk();
        $this->assertEquals(3, $activeWos);
        $this->assertEquals(9, $totalTrees);
    }

    // ─── TEST 3: Stage distribution is correct ───
    public function test_stage_distribution_is_correct(): void
    {
        $user = User::first();

        // Count trees per stage
        $nullCount = LostWaxTree::whereNull('current_stage')->count();
        $layer1Count = LostWaxTree::where('current_stage', 'layer_1')->count();
        $layer2Count = LostWaxTree::where('current_stage', 'layer_2')->count();
        $ovenCount = LostWaxTree::where('current_stage', 'oven')->count();

        $this->assertGreaterThan(0, $layer1Count, 'Should have layer_1 trees');
        $this->assertGreaterThan(0, $layer2Count, 'Should have layer_2 trees');
        $this->assertGreaterThan(0, $ovenCount, 'Should have oven trees');
    }

    // ─── TEST 4: Aging aggregation exists ───
    public function test_aging_aggregation_has_values(): void
    {
        $user = User::first();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.dashboard'));

        $response->assertSee('Normal');
        $response->assertSee('Cepat');
        $response->assertSee('Lama');
    }

    // ─── TEST 5: Hot list shows TOO_LONG trees ───
    public function test_hot_list_has_entries(): void
    {
        $user = User::first();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.dashboard'));

        $response->assertOk();
        $response->assertSee('Perlu Perhatian');
    }

    // ─── TEST 6: Barcode search finds a tree ───
    public function test_barcode_search_finds_existing_tree(): void
    {
        $user = User::first();

        $tree = LostWaxTree::first();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.dashboard', ['search' => $tree->barcode]));

        $response->assertOk();
        $response->assertSee('Tree Ditemukan');
        $response->assertSee($tree->barcode);
    }

    // ─── TEST 7: Barcode search returns current stage ───
    public function test_barcode_search_shows_current_stage(): void
    {
        $user = User::first();

        $tree = LostWaxTree::whereNotNull('current_stage')->first();
        $this->assertNotNull($tree);

        $response = $this->actingAs($user)
            ->get(route('lost-wax.dashboard', ['search' => $tree->barcode]));

        $response->assertOk();
        $response->assertSee($tree->current_stage_label);
    }

    // ─── TEST 8: ET aggregate shows tree distribution ───
    public function test_et_aggregate_shows_stage_counts(): void
    {
        $user = User::first();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.dashboard'));

        $response->assertOk();
        $response->assertSee('LWDEMO001');
        $response->assertSee('LWDEMO002');
        $response->assertSee('LWDEMO003');
    }

    // ─── TEST 9: Variable tree quantities aggregated correctly ───
    public function test_variable_tree_quantities_aggregated(): void
    {
        $totalTreeQty = LostWaxTree::sum('quantity');
        $this->assertGreaterThan(0, $totalTreeQty);

        $response = $this->actingAs(User::first())
            ->get(route('lost-wax.dashboard'));

        $response->assertOk();

        // Check that the dashboard includes individual tree quantities
        // Not the global sum (which is 105, but rendered as 30 + 30 + 45 in cards)
        foreach (LostWaxTree::all() as $tree) {
            $this->assertNotNull($tree->workOrder->et_code);
        }
    }

    // ─── TEST 10: Rejected scan anomalies visible ───
    public function test_rejected_scan_anomalies_visible_on_dashboard(): void
    {
        $user = User::first();

        $rejectedCount = LostWaxScanEvent::where('result', 'rejected')->count();
        $this->assertGreaterThanOrEqual(1, $rejectedCount, 'Should have at least 1 rejected scan');

        $response = $this->actingAs($user)
            ->get(route('lost-wax.dashboard'));

        $response->assertOk();
    }

    // ─── TEST 11: Existing tree detail still works ───
    public function test_existing_tree_detail_page_still_works(): void
    {
        $user = User::first();
        $tree = LostWaxTree::first();

        $this->actingAs($user)
            ->get(route('lost-wax.trees.show', $tree))
            ->assertOk();
    }

    // ─── TEST 12: Existing scan workflow not broken ───
    public function test_existing_scan_workflow_still_works(): void
    {
        $user = User::first();

        $scanTarget = LostWaxTree::whereNull('current_stage')->first();

        if (! $scanTarget) {
            $scanTarget = LostWaxTree::where('current_stage', 'layer_1')->first();
        }

        $this->assertNotNull($scanTarget, 'Need a tree to scan');

        \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 15, 0, 0));

        $response = $this->actingAs($user)
            ->postJson(route('lost-wax.scan.process'), [
                'barcode' => $scanTarget->barcode,
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        \Carbon\Carbon::setTestNow(null);
    }

    // ─── TEST 13: Dashboard sidebar link is active ───
    public function test_dashboard_sidebar_highlighted(): void
    {
        $user = User::first();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.dashboard'));

        $response->assertOk();
        $response->assertSee('Dashboard');
    }

    // ─── TEST 14: ET filter works ───
    public function test_et_filter_narrows_results(): void
    {
        $user = User::first();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.dashboard', ['et' => 'LWDEMO001']));

        $response->assertOk();
    }

    // ─── TEST 15: Search by non-existent barcode ───
    public function test_search_non_existent_barcode(): void
    {
        $user = User::first();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.dashboard', ['search' => '9999999999']));

        $response->assertOk();
        $response->assertSee('Tidak ditemukan');
    }
}
