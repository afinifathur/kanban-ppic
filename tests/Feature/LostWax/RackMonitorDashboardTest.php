<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxCoatingRack;
use App\Models\LostWaxTree;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RackMonitorDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create 35 coating racks
        for ($i = 1; $i <= 35; $i++) {
            LostWaxCoatingRack::create([
                'rack_number' => $i,
                'label' => 'RAK-'.str_pad($i, 2, '0', STR_PAD_LEFT),
                'status' => 'active',
            ]);
        }
    }

    private function createTree(array $attributes = []): LostWaxTree
    {
        static $sequence = 1;
        $seq = $sequence++;

        return LostWaxTree::create(array_merge([
            'barcode' => '99'.str_pad($seq, 7, '0', STR_PAD_LEFT),
            'tree_number' => 1,
            'quantity' => 20,
            'status' => 'generated',
            'require_layer_7' => false,
            'production_date' => '2026-08-22',
            'family_code' => '4',
            'daily_sequence' => $seq,
        ], $attributes));
    }

    // ─── Test 1: Otorisasi & Dashboard Loads ───
    public function test_dashboard_loads_for_authorized_user(): void
    {
        $user = User::factory()->create();

        // Guest is redirected
        $response = $this->get(route('lost-wax.rack-monitor.index'));
        $response->assertRedirect('/login');

        // Authenticated user can load the dashboard
        $response = $this->actingAs($user)->get(route('lost-wax.rack-monitor.index'));
        $response->assertOk();
        $response->assertSee('MONITORING RAK LAPISAN');
        $response->assertSee('Antrean Prioritas Rak');
        $response->assertSee('Aging Monitor');
    }

    // ─── Test 2: Priority Ordering (LATE -> READY -> NEAR_READY -> NORMAL) ───
    public function test_priority_ordering_is_correct(): void
    {
        $user = User::factory()->create();

        // Let's set a fixed time
        Carbon::setTestNow(Carbon::create(2026, 8, 24, 12, 0, 0));

        // Global fallback is 4 hours min / 6 hours max/buffer

        // Rack 1: Scanned 10 hours ago -> LATE (age 10 hours > 6)
        $rack1 = LostWaxCoatingRack::where('rack_number', 1)->first();
        $this->createTree([
            'rack_id' => $rack1->id,
            'current_stage' => 'layer_1',
            'last_scan_at' => Carbon::now()->subHours(10),
        ]);

        // Rack 2: Scanned 5 hours ago -> READY (age 5 hours, min 4 <= 5 <= 6)
        $rack2 = LostWaxCoatingRack::where('rack_number', 2)->first();
        $this->createTree([
            'rack_id' => $rack2->id,
            'current_stage' => 'layer_1',
            'last_scan_at' => Carbon::now()->subHours(5),
        ]);

        // Rack 3: Scanned 3.5 hours ago -> NEAR_READY (age 3.5 hours, min 4, remaining 30m <= 60m)
        $rack3 = LostWaxCoatingRack::where('rack_number', 3)->first();
        $this->createTree([
            'rack_id' => $rack3->id,
            'current_stage' => 'layer_1',
            'last_scan_at' => Carbon::now()->subMinutes(210), // 3.5 hours
        ]);

        // Rack 4: Scanned 2 hours ago -> NORMAL (age 2 hours, min 4, remaining 2 hours > 60m)
        $rack4 = LostWaxCoatingRack::where('rack_number', 4)->first();
        $this->createTree([
            'rack_id' => $rack4->id,
            'current_stage' => 'layer_1',
            'last_scan_at' => Carbon::now()->subHours(2),
        ]);

        $response = $this->actingAs($user)->get(route('lost-wax.rack-monitor.index'));
        $response->assertOk();

        // Get view data and check order
        $viewRacks = $response->viewData('racks');

        $this->assertCount(4, $viewRacks);
        $this->assertEquals($rack1->id, $viewRacks[0]['rack_id']);
        $this->assertEquals('LATE', $viewRacks[0]['presentation_state']);

        $this->assertEquals($rack2->id, $viewRacks[1]['rack_id']);
        $this->assertEquals('READY', $viewRacks[1]['presentation_state']);

        $this->assertEquals($rack3->id, $viewRacks[2]['rack_id']);
        $this->assertEquals('NEAR_READY', $viewRacks[2]['presentation_state']);

        $this->assertEquals($rack4->id, $viewRacks[3]['rack_id']);
        $this->assertEquals('NORMAL', $viewRacks[3]['presentation_state']);

        Carbon::setTestNow(null);
    }

    // ─── Test 3: Layer 7 Specific Thresholds Override Global Fallback ───
    public function test_layer7_specific_thresholds_applied(): void
    {
        $user = User::factory()->create();

        // Config has layer_7 min: 24h, buffer: 26h
        Carbon::setTestNow(Carbon::create(2026, 8, 24, 12, 0, 0));

        $rack = LostWaxCoatingRack::where('rack_number', 7)->first();
        $this->createTree([
            'rack_id' => $rack->id,
            'current_stage' => 'layer_7',
            'last_scan_at' => Carbon::now()->subHours(25), // 25 hours ago
        ]);

        $response = $this->actingAs($user)->get(route('lost-wax.rack-monitor.index'));
        $response->assertOk();

        $viewRacks = $response->viewData('racks');
        $this->assertCount(1, $viewRacks);
        // Under global fallback (min 4h, max 6h), 25 hours would be LATE.
        // Under layer_7 (min 24h, max 26h), 25 hours is READY.
        $this->assertEquals('READY', $viewRacks[0]['presentation_state']);

        Carbon::setTestNow(null);
    }

    // ─── Test 4: Mixed Stage Detection ───
    public function test_mixed_stage_badge_and_state(): void
    {
        $user = User::factory()->create();
        Carbon::setTestNow(Carbon::create(2026, 8, 24, 12, 0, 0));

        $rack = LostWaxCoatingRack::where('rack_number', 10)->first();
        $this->createTree([
            'rack_id' => $rack->id,
            'current_stage' => 'layer_1',
            'last_scan_at' => Carbon::now()->subHours(2),
        ]);
        $this->createTree([
            'rack_id' => $rack->id,
            'current_stage' => 'layer_2',
            'last_scan_at' => Carbon::now()->subHours(1),
        ]);

        $response = $this->actingAs($user)->get(route('lost-wax.rack-monitor.index'));
        $response->assertOk();
        $response->assertSee('MIXED');

        $viewRacks = $response->viewData('racks');
        $this->assertTrue($viewRacks[0]['is_mixed']);

        Carbon::setTestNow(null);
    }

    // ─── Test 5: Layer 7 Split Detection ───
    public function test_layer7_split_badge_and_state(): void
    {
        $user = User::factory()->create();
        Carbon::setTestNow(Carbon::create(2026, 8, 24, 12, 0, 0));

        $rack = LostWaxCoatingRack::where('rack_number', 11)->first();
        $this->createTree([
            'rack_id' => $rack->id,
            'current_stage' => 'layer_6',
            'last_scan_at' => Carbon::now()->subHours(2),
        ]);
        $this->createTree([
            'rack_id' => $rack->id,
            'current_stage' => 'layer_7',
            'last_scan_at' => Carbon::now()->subHours(1),
        ]);

        $response = $this->actingAs($user)->get(route('lost-wax.rack-monitor.index'));
        $response->assertOk();
        $response->assertSee('L7 SPLIT');

        $viewRacks = $response->viewData('racks');
        $this->assertTrue($viewRacks[0]['is_layer7_split']);

        Carbon::setTestNow(null);
    }

    // ─── Test 6: Unassigned Tree Counter & Banner ───
    public function test_unassigned_tree_counter_and_warning_banner(): void
    {
        $user = User::factory()->create();

        // 1 tree in a rack, 2 trees unassigned
        $rack = LostWaxCoatingRack::first();
        $this->createTree([
            'rack_id' => $rack->id,
            'current_stage' => 'layer_1',
            'last_scan_at' => now(),
        ]);
        $this->createTree(['rack_id' => null, 'current_stage' => 'layer_1']);
        $this->createTree(['rack_id' => null, 'current_stage' => null]);

        $response = $this->actingAs($user)->get(route('lost-wax.rack-monitor.index'));
        $response->assertOk();
        $response->assertSee('Terdapat Tree Belum Ditempatkan');
        $response->assertSee('2 tree');

        $summary = $response->viewData('summary');
        $this->assertEquals(2, $summary['unassigned']);
    }

    // ─── Test 7: N+1 Prevention check ───
    public function test_n_plus_one_prevention(): void
    {
        $user = User::factory()->create();

        // Populate multiple active racks to verify query count is constant
        for ($r = 1; $r <= 10; $r++) {
            $rack = LostWaxCoatingRack::where('rack_number', $r)->first();
            for ($t = 0; $t < 3; $t++) {
                $this->createTree([
                    'rack_id' => $rack->id,
                    'current_stage' => 'layer_1',
                    'last_scan_at' => now(),
                ]);
            }
        }

        DB::enableQueryLog();
        $response = $this->actingAs($user)->get(route('lost-wax.rack-monitor.index'));
        $response->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // We expect minimal number of queries:
        // - Auth user session query
        // - 1 query to fetch trees with coatingRack relation
        // - 1 query for counting unassigned trees
        // This is bounded and far below N (e.g. 10 racks)
        $this->assertLessThan(10, $queryCount, 'Database query count should be small and bounded.');
    }

    // ─── Test 8: Dashboard excludes Oven trees and renders Rack 10 correctly ───
    public function test_dashboard_excludes_oven_trees_and_displays_compact_barcode(): void
    {
        $user = User::factory()->create();
        Carbon::setTestNow(Carbon::create(2026, 8, 30, 12, 0, 0));

        $rack10 = LostWaxCoatingRack::where('rack_number', 10)->first();

        // 7 trees in layer_3
        for ($i = 1; $i <= 7; $i++) {
            $this->createTree([
                'barcode' => '1290826'.str_pad((string) (8 + $i), 3, '0', STR_PAD_LEFT),
                'rack_id' => $rack10->id,
                'quantity' => ($i === 1) ? 32 : 16,
                'current_stage' => 'layer_3',
                'last_scan_at' => Carbon::now()->subHours(2),
            ]);
        }

        // 5 trees in oven (should be excluded)
        for ($i = 1; $i <= 5; $i++) {
            $this->createTree([
                'barcode' => '3290826'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'rack_id' => $rack10->id,
                'quantity' => 32,
                'current_stage' => 'oven',
                'last_scan_at' => Carbon::now()->subHours(1),
            ]);
        }

        $response = $this->actingAs($user)->get(route('lost-wax.rack-monitor.index'));
        $response->assertOk();

        $viewRacks = $response->viewData('racks');
        $this->assertCount(1, $viewRacks);
        $this->assertEquals(7, $viewRacks[0]['tree_count']);
        $this->assertEquals(128, $viewRacks[0]['total_quantity']);
        $this->assertEquals('layer_3', $viewRacks[0]['dominant_stage']);

        // Check barcodes in view payload
        $barcodes = array_column($viewRacks[0]['trees'], 'barcode');
        $this->assertContains('1290826009', $barcodes);
        $this->assertNotContains('3290826001', $barcodes);
        $this->assertNotContains('3290826002', $barcodes);

        $summary = $response->viewData('summary');
        $this->assertEquals(1, $summary['total_active']);

        Carbon::setTestNow(null);
    }
}
