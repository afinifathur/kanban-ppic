<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\LostWaxWorkOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\ArrayItemMasterRepository;
use Tests\TestCase;

class DemoDataAndManualScanTest extends TestCase
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
                ['code' => 'LY029', 'name' => 'Blind Flange', 'aisi' => '1.4408', 'standard' => 'EN', 'unit_weight' => '2.500', 'status' => 'active'],
                ['code' => 'LY030', 'name' => 'WNRF Flange', 'aisi' => 'CF8', 'standard' => 'ANSI', 'unit_weight' => '0.800', 'status' => 'active'],
                ['code' => 'LY031', 'name' => 'Loose Flange', 'aisi' => '1.4308', 'standard' => 'UNI', 'unit_weight' => '3.500', 'status' => 'active'],
                ['code' => 'LY032', 'name' => 'Raised Flange', 'aisi' => '1.4308', 'standard' => 'EN', 'unit_weight' => '3.000', 'status' => 'active'],
                ['code' => 'LY033', 'name' => 'Reduced Flange', 'aisi' => 'SCS 13 A', 'standard' => 'JIS', 'unit_weight' => '1.500', 'status' => 'active'],
                ['code' => 'LY034', 'name' => 'Plane Flange E', 'aisi' => 'SCS 13 A', 'standard' => 'TABLE E', 'unit_weight' => '0.500', 'status' => 'active'],
                ['code' => 'LY035', 'name' => 'Special Flange', 'aisi' => 'SCS 14 A', 'standard' => 'DIN', 'unit_weight' => '2.000', 'status' => 'active'],
            ])
        );
    }

    // ─── Test demo command creates expected records ───

    public function test_demo_command_creates_expected_records(): void
    {
        $user = User::factory()->create(['email' => 'adminppicpf@peroniks.com']);

        $this->artisan('lost-wax:seed-demo', ['--yes' => true])
            ->expectsOutputToContain('Work Order dibuat')
            ->expectsOutputToContain('Tree dibuat')
            ->expectsOutputToContain('Scan events')
            ->expectsOutputToContain('DEMO DATA SUMMARY')
            ->assertSuccessful();

        $this->assertGreaterThanOrEqual(10, LostWaxWorkOrder::count());
        $this->assertGreaterThanOrEqual(30, LostWaxTree::count());
        $this->assertGreaterThanOrEqual(100, LostWaxScanEvent::count());
        $this->assertGreaterThanOrEqual(1, LostWaxScanEvent::where('result', 'rejected')->count());

        // All work orders use LWDEMO prefix
        $wos = LostWaxWorkOrder::all();
        foreach ($wos as $wo) {
            $this->assertStringStartsWith('LWDEMO', $wo->et_code);
        }

        // Varied stages
        $stages = LostWaxTree::selectRaw('current_stage')->distinct()->pluck('current_stage')->toArray();
        $this->assertContains(null, $stages, 'Should have some unscanned trees');
        $this->assertContains('layer_1', $stages);
        $this->assertContains('oven', $stages);

        // Aging distribution verified
        $agingTypes = LostWaxScanEvent::whereNotNull('aging_status')->pluck('aging_status')->unique()->toArray();
        $this->assertContains('normal', $agingTypes);
        $this->assertContains('too_fast', $agingTypes);
        $this->assertContains('too_long', $agingTypes);

        // Layer 7 exists
        $this->assertContains('layer_7', $stages);
        $layer7Wos = LostWaxWorkOrder::where('require_layer_7', true)->count();
        $this->assertGreaterThanOrEqual(1, $layer7Wos);
    }

    // ─── Test demo command is idempotent ───

    public function test_demo_command_is_idempotent(): void
    {
        User::factory()->create(['email' => 'adminppicpf@peroniks.com']);

        $this->artisan('lost-wax:seed-demo', ['--yes' => true])->assertSuccessful();
        $firstCount = LostWaxWorkOrder::count();

        $this->artisan('lost-wax:seed-demo', ['--yes' => true])->assertSuccessful();
        $secondCount = LostWaxWorkOrder::count();

        $this->assertSame($firstCount, $secondCount, 'Second run should not create duplicates');
    }

    // ─── Test --fresh option recreates cleanly ───

    public function test_fresh_option_recreates(): void
    {
        User::factory()->create(['email' => 'adminppicpf@peroniks.com']);

        $this->artisan('lost-wax:seed-demo', ['--yes' => true])->assertSuccessful();
        $firstMaxId = LostWaxWorkOrder::max('id');

        $this->artisan('lost-wax:seed-demo', ['--fresh' => true, '--yes' => true])->assertSuccessful();
        $secondMinId = LostWaxWorkOrder::min('id');

        // With fresh, new IDs should be allocated (they reset when records are deleted)
        $this->assertNotSame($firstMaxId, LostWaxWorkOrder::max('id'));
    }

    // ─── Test manual scan via API endpoint ───

    public function test_manual_scan_keyboard_input_advances_tree(): void
    {
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 8, 0, 0));

        User::factory()->create(['email' => 'adminppicpf@peroniks.com']);

        $this->artisan('lost-wax:seed-demo', ['--yes' => true]);

        $freshTree = LostWaxTree::whereNull('current_stage')->first();
        $this->assertNotNull($freshTree, 'Should have fresh tree for testing');

        $user = User::first();

        // Simulate keyboard typing the barcode and pressing Enter
        $response = $this->actingAs($user)
            ->postJson(route('lost-wax.scan.process'), [
                'barcode' => $freshTree->barcode,
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $response->assertJsonPath('stage_label', 'Lapisan 1');

        $this->assertSame('layer_1', $freshTree->fresh()->current_stage);

        // Advance to layer_2
        $response2 = $this->actingAs($user)
            ->postJson(route('lost-wax.scan.process'), [
                'barcode' => $freshTree->barcode,
            ]);

        $response2->assertOk();
        $response2->assertJson(['success' => true]);
        $this->assertSame('Lapisan 2', $response2->json('stage_label'));

        \Carbon\Carbon::setTestNow(null);
    }

    // ─── Test invalid barcode ───

    public function test_invalid_barcode_returns_error(): void
    {
        User::factory()->create();

        $user = User::first();

        $response = $this->actingAs($user)
            ->postJson(route('lost-wax.scan.process'), [
                'barcode' => '9999999999',
            ]);

        $response->assertOk();
        $response->assertJson(['success' => false]);
        $response->assertJsonPath('reason', 'Barcode tidak ditemukan.');
    }

    // ─── Test completed tree returns error ───

    public function test_completed_tree_returns_error(): void
    {
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 8, 0, 0));

        User::factory()->create(['email' => 'adminppicpf@peroniks.com']);
        $this->artisan('lost-wax:seed-demo', ['--yes' => true]);

        $ovenTree = LostWaxTree::where('current_stage', 'oven')->first();
        $this->assertNotNull($ovenTree, 'Should have tree at oven stage');

        $user = User::first();

        $response = $this->actingAs($user)
            ->postJson(route('lost-wax.scan.process'), [
                'barcode' => $ovenTree->barcode,
            ]);

        $response->assertOk();
        $response->assertJson(['success' => false]);
        $response->assertJsonPath('reason', 'Tree sudah menyelesaikan semua tahapan.');

        \Carbon\Carbon::setTestNow(null);
    }

    // ─── Test scan operator page loads ───

    public function test_scan_page_shows_operator_screen(): void
    {
        User::factory()->create(['email' => 'adminppicpf@peroniks.com']);
        $this->artisan('lost-wax:seed-demo', ['--yes' => true]);

        $user = User::first();

        $this->actingAs($user)
            ->get(route('lost-wax.scan.index'))
            ->assertOk()
            ->assertSee('SIAP SCAN')
            ->assertSee('SCAN BARCODE');
    }

    // ─── Test tree history page shows timeline ───

    public function test_tree_history_shows_anomaly(): void
    {
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 8, 0, 0));

        User::factory()->create(['email' => 'adminppicpf@peroniks.com']);
        $this->artisan('lost-wax:seed-demo', ['--yes' => true]);

        $affectedTree = LostWaxTree::whereHas('scanEvents', function ($q) {
            $q->where('result', 'rejected');
        })->first();

        $this->assertNotNull($affectedTree, 'Should have a tree with rejected scan');

        $user = User::first();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.trees.history', $affectedTree));

        $response->assertOk();
        $response->assertSee('Timeline Scan');
        $response->assertSee('DITOLAK');
        $response->assertSee('Lapisan 1');

        \Carbon\Carbon::setTestNow(null);
    }

    // ─── Test tree index shows all trees ───

    public function test_tree_index_shows_all_stages(): void
    {
        User::factory()->create(['email' => 'adminppicpf@peroniks.com']);
        $this->artisan('lost-wax:seed-demo', ['--yes' => true]);

        $user = User::first();

        $this->actingAs($user)
            ->get(route('lost-wax.trees.index'))
            ->assertOk();
    }

    // ─── Test work order index shows all demo WOs ───

    public function test_work_order_index_shows_demo_wos(): void
    {
        User::factory()->create(['email' => 'adminppicpf@peroniks.com']);
        $this->artisan('lost-wax:seed-demo', ['--yes' => true]);

        $user = User::first();

        $this->actingAs($user)
            ->get(route('lost-wax.work-orders.index'))
            ->assertOk()
            ->assertSee('LWDEMO001')
            ->assertSee('LWDEMO005')
            ->assertSee('LWDEMO010');
    }

    // ─── Test that trees keep proper scan history ───

    public function test_tree_at_layer_4_has_correct_scan_history(): void
    {
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11, 8, 0, 0));

        User::factory()->create(['email' => 'adminppicpf@peroniks.com']);
        $this->artisan('lost-wax:seed-demo', ['--yes' => true]);

        $layer4Tree = LostWaxTree::where('current_stage', 'layer_4')->first();
        $this->assertNotNull($layer4Tree);

        $events = LostWaxScanEvent::where('tree_id', $layer4Tree->id)
            ->where('result', 'success')
            ->orderBy('scanned_at')
            ->pluck('stage')
            ->toArray();

        $this->assertSame(['layer_1', 'layer_2', 'layer_3', 'layer_4'], $events);
        $this->assertSame('layer_4', $layer4Tree->current_stage);

        \Carbon\Carbon::setTestNow(null);
    }
}
