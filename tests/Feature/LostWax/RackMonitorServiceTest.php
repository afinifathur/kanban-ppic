<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxCoatingRack;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\User;
use App\Services\LostWax\RackMonitorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RackMonitorServiceTest extends TestCase
{
    use RefreshDatabase;

    private RackMonitorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RackMonitorService;

        // Create 35 coating racks
        for ($i = 1; $i <= 35; $i++) {
            LostWaxCoatingRack::create([
                'rack_number' => $i,
                'label' => 'Coating Rack '.str_pad($i, 2, '0', STR_PAD_LEFT),
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

    // ─── Test 1: 35 rack tersedia ───
    public function test_35_racks_available(): void
    {
        $this->assertDatabaseCount('lost_wax_coating_racks', 35);
        $this->assertEquals(35, LostWaxCoatingRack::where('status', 'active')->count());
    }

    // ─── Test 2: Rack dengan 30 tree pada Layer 1 menghasilkan dominant_stage = layer_1 ───
    public function test_rack_with_30_trees_on_layer_1(): void
    {
        $rack = LostWaxCoatingRack::first();
        for ($i = 0; $i < 30; $i++) {
            $this->createTree([
                'rack_id' => $rack->id,
                'current_stage' => 'layer_1',
                'last_scan_at' => now(),
            ]);
        }

        $activeRacks = $this->service->getActiveRacks();

        $this->assertCount(1, $activeRacks);
        $this->assertEquals($rack->id, $activeRacks[0]['rack_id']);
        $this->assertEquals('layer_1', $activeRacks[0]['dominant_stage']);
        $this->assertEquals(30, $activeRacks[0]['tree_count']);
        $this->assertEquals(30 * 20, $activeRacks[0]['total_quantity']);
        $this->assertFalse($activeRacks[0]['is_mixed']);
    }

    // ─── Test 3: Mixed rack: Layer 3 = 27, Layer 4 = 3 ───
    public function test_mixed_rack_stage_distribution(): void
    {
        $rack = LostWaxCoatingRack::first();

        // 27 trees in layer_3
        for ($i = 0; $i < 27; $i++) {
            $this->createTree([
                'rack_id' => $rack->id,
                'current_stage' => 'layer_3',
                'last_scan_at' => now(),
            ]);
        }

        // 3 trees in layer_4
        for ($i = 0; $i < 3; $i++) {
            $this->createTree([
                'rack_id' => $rack->id,
                'current_stage' => 'layer_4',
                'last_scan_at' => now(),
            ]);
        }

        $activeRacks = $this->service->getActiveRacks();

        $this->assertCount(1, $activeRacks);
        $this->assertEquals('layer_3', $activeRacks[0]['dominant_stage']);
        $this->assertTrue($activeRacks[0]['is_mixed']);
        $this->assertEquals(27, $activeRacks[0]['stage_distribution']['layer_3']);
        $this->assertEquals(3, $activeRacks[0]['stage_distribution']['layer_4']);
    }

    // ─── Test 4: LAST scan: 08:00, 08:20, 08:35 -> rack_stage_started_at = 08:35 ───
    public function test_rack_stage_started_at_uses_last_valid_scan(): void
    {
        $rack = LostWaxCoatingRack::first();

        $time1 = Carbon::create(2026, 8, 24, 8, 0, 0);
        $time2 = Carbon::create(2026, 8, 24, 8, 20, 0);
        $time3 = Carbon::create(2026, 8, 24, 8, 35, 0);

        $tree1 = $this->createTree(['rack_id' => $rack->id, 'current_stage' => 'layer_3', 'last_scan_at' => $time1]);
        $tree2 = $this->createTree(['rack_id' => $rack->id, 'current_stage' => 'layer_3', 'last_scan_at' => $time2]);
        $tree3 = $this->createTree(['rack_id' => $rack->id, 'current_stage' => 'layer_3', 'last_scan_at' => $time3]);

        $activeRacks = $this->service->getActiveRacks();

        $this->assertCount(1, $activeRacks);
        $this->assertEquals($time3->toIso8601String(), $activeRacks[0]['rack_stage_started_at']->toIso8601String());
    }

    // ─── Test 5: Void event: scan terakhir di-void → aggregation harus menggunakan event aktif terakhir ───
    public function test_void_event_excludes_voided_timestamps(): void
    {
        $rack = LostWaxCoatingRack::first();
        $user = User::factory()->create();

        // We create a tree and scan it. We'll use the ScanVoidService to mock/perform a void.
        $tree = $this->createTree(['rack_id' => $rack->id]);

        $time1 = Carbon::create(2026, 8, 24, 8, 0, 0);
        Carbon::setTestNow($time1);
        $event1 = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'layer_1',
            'scanned_at' => $time1,
            'operator_id' => $user->id,
            'result' => 'success',
        ]);
        $tree->update(['current_stage' => 'layer_1', 'last_scan_at' => $time1]);

        $time2 = Carbon::create(2026, 8, 24, 9, 0, 0);
        Carbon::setTestNow($time2);
        $event2 = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'layer_2',
            'scanned_at' => $time2,
            'operator_id' => $user->id,
            'result' => 'success',
        ]);
        $tree->update(['current_stage' => 'layer_2', 'last_scan_at' => $time2]);

        // Prior to void, dominant stage is layer_2, started at 09:00
        $activeRacks = $this->service->getActiveRacks();
        $this->assertEquals('layer_2', $activeRacks[0]['dominant_stage']);
        $this->assertEquals($time2->toIso8601String(), $activeRacks[0]['rack_stage_started_at']->toIso8601String());

        // Now void the last event using ScanVoidService
        $voidService = app(\App\Services\ScanVoidService::class);
        $voidService->void($event2, 'Revert to L1', $user->id);

        // After void, tree is reverted to layer_1 with last_scan_at = 08:00
        $activeRacksAfterVoid = $this->service->getActiveRacks();
        $this->assertEquals('layer_1', $activeRacksAfterVoid[0]['dominant_stage']);
        $this->assertEquals($time1->toIso8601String(), $activeRacksAfterVoid[0]['rack_stage_started_at']->toIso8601String());

        Carbon::setTestNow(null);
    }

    // ─── Test 6: Layer 7: aging tidak boleh menggunakan threshold 6 jam. Harus menggunakan 24h / 26h baseline. ───
    public function test_layer_7_uses_correct_aging_thresholds(): void
    {
        $rack = LostWaxCoatingRack::first();

        // 1. NORMAL: 23 hours in Layer 7
        $timeNormal = now()->subHours(23);
        $treeNormal = $this->createTree(['rack_id' => $rack->id, 'current_stage' => 'layer_7', 'last_scan_at' => $timeNormal]);

        $activeRacks = $this->service->getActiveRacks();
        $this->assertEquals('normal', $activeRacks[0]['aging_status']);

        // 2. READY: 25 hours in Layer 7 (range 24h - 26h)
        $timeReady = now()->subHours(25);
        $treeNormal->update(['last_scan_at' => $timeReady]);

        $activeRacks = $this->service->getActiveRacks();
        $this->assertEquals('ready', $activeRacks[0]['aging_status']);

        // 3. LATE: 27 hours in Layer 7 (> 26h)
        $timeLate = now()->subHours(27);
        $treeNormal->update(['last_scan_at' => $timeLate]);

        $activeRacks = $this->service->getActiveRacks();
        $this->assertEquals('late', $activeRacks[0]['aging_status']);
    }

    // ─── Test 7: Rack movement: pindah rack tidak mengubah aging timestamp ───
    public function test_rack_movement_does_not_change_aging_timestamp(): void
    {
        $rackA = LostWaxCoatingRack::where('rack_number', 1)->first();
        $rackB = LostWaxCoatingRack::where('rack_number', 2)->first();

        $scanTime = now()->subHours(2);
        $tree = $this->createTree([
            'rack_id' => $rackA->id,
            'current_stage' => 'layer_1',
            'last_scan_at' => $scanTime,
        ]);

        // Aggregate Rack A
        $racks = $this->service->getActiveRacks();
        $this->assertCount(1, $racks);
        $this->assertEquals($rackA->id, $racks[0]['rack_id']);
        $this->assertEquals($scanTime->toIso8601String(), $racks[0]['rack_stage_started_at']->toIso8601String());

        // Move to Rack B
        $tree->update(['rack_id' => $rackB->id]);

        // Aggregate again
        $racks = $this->service->getActiveRacks();
        $this->assertCount(1, $racks);
        $this->assertEquals($rackB->id, $racks[0]['rack_id']);
        $this->assertEquals($scanTime->toIso8601String(), $racks[0]['rack_stage_started_at']->toIso8601String());
    }

    // ─── Test 8: Tree tanpa rack tidak masuk aggregation tetapi dihitung dalam: unassigned_tree_count ───
    public function test_trees_without_rack_are_unassigned(): void
    {
        // 2 trees assigned to rack 1
        $rack = LostWaxCoatingRack::first();
        $this->createTree(['rack_id' => $rack->id, 'current_stage' => 'layer_1', 'last_scan_at' => now()]);
        $this->createTree(['rack_id' => $rack->id, 'current_stage' => 'layer_1', 'last_scan_at' => now()]);

        // 3 trees without rack (current_stage != 'oven')
        $this->createTree(['rack_id' => null, 'current_stage' => 'layer_1']);
        $this->createTree(['rack_id' => null, 'current_stage' => null]);
        $this->createTree(['rack_id' => null, 'current_stage' => 'layer_3']);

        // 1 completed tree without rack (should be excluded from unassigned count)
        $this->createTree(['rack_id' => null, 'current_stage' => 'oven']);

        $activeRacks = $this->service->getActiveRacks();
        $this->assertCount(1, $activeRacks);
        $this->assertEquals(2, $activeRacks[0]['tree_count']);

        $unassignedCount = $this->service->getUnassignedTreeCount();
        $this->assertEquals(3, $unassignedCount);
    }

    // ─── Test 9: Empty rack tidak muncul sebagai active rack ───
    public function test_empty_racks_excluded_from_active_racks(): void
    {
        // There are 35 racks, none have trees.
        $activeRacks = $this->service->getActiveRacks();
        $this->assertEmpty($activeRacks);

        // Put tree in rack 15
        $rack15 = LostWaxCoatingRack::where('rack_number', 15)->first();
        $this->createTree(['rack_id' => $rack15->id, 'current_stage' => 'layer_1', 'last_scan_at' => now()]);

        $activeRacks = $this->service->getActiveRacks();
        $this->assertCount(1, $activeRacks);
        $this->assertEquals(15, $activeRacks[0]['rack_number']);
    }

    // ─── Test 10: No N+1 regression / query count ───
    public function test_no_n_plus_one_regression(): void
    {
        // We will put trees in 10 different racks.
        for ($i = 1; $i <= 10; $i++) {
            $rack = LostWaxCoatingRack::where('rack_number', $i)->first();
            $this->createTree(['rack_id' => $rack->id, 'current_stage' => 'layer_1', 'last_scan_at' => now()]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $activeRacks = $this->service->getActiveRacks();

        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();

        // Expected queries:
        // 1. Query to select trees with their coating racks.
        // 2. Query to load the relationship if not fully joined or done via eager loading.
        // It should be exactly 2 queries, or at least a very small bounded number, definitely not proportional to the number of racks (10).
        $this->assertLessThanOrEqual(5, count($queryLog), 'Query count should be bounded and not scale with number of racks.');
    }

    // ─── Test 11: Oven trees are excluded from rack aggregation (Rack 10 scenario) ───
    public function test_oven_trees_are_excluded_from_rack_dataset_and_aggregation(): void
    {
        $rack10 = LostWaxCoatingRack::where('rack_number', 10)->first();

        // 7 Trees on Layer 3
        for ($i = 1; $i <= 7; $i++) {
            $this->createTree([
                'barcode' => '1290826'.str_pad((string) (8 + $i), 3, '0', STR_PAD_LEFT),
                'rack_id' => $rack10->id,
                'quantity' => ($i === 1) ? 32 : 16,
                'current_stage' => 'layer_3',
                'last_scan_at' => Carbon::create(2026, 8, 30, 6, 25, 0),
            ]);
        }

        // 5 Trees on Oven (physically out of rack)
        for ($i = 1; $i <= 5; $i++) {
            $this->createTree([
                'barcode' => '3290826'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'rack_id' => $rack10->id,
                'quantity' => 32,
                'current_stage' => 'oven',
                'last_scan_at' => Carbon::create(2026, 9, 5, 21, 48, 0),
            ]);
        }

        $activeRacks = $this->service->getActiveRacks();

        $this->assertCount(1, $activeRacks);
        $rackData = $activeRacks[0];

        $this->assertEquals(10, $rackData['rack_number']);
        $this->assertEquals(7, $rackData['tree_count'], 'Tree count should only include 7 Layer 3 trees');
        $this->assertEquals(128, $rackData['total_quantity'], 'Total quantity should be 32 + (6*16) = 128 pcs');
        $this->assertEquals('layer_3', $rackData['dominant_stage']);
        $this->assertFalse($rackData['is_mixed']);
        $this->assertEquals(7, $rackData['stage_distribution']['layer_3']);
        $this->assertArrayNotHasKey('oven', $rackData['stage_distribution']);

        // Check tree list
        $this->assertCount(7, $rackData['trees']);
        foreach ($rackData['trees'] as $tree) {
            $this->assertEquals('layer_3', $tree['current_stage']);
            $this->assertStringNotContainsString(' ', $tree['human_barcode']);
            $this->assertStringNotContainsString('-', $tree['human_barcode']);
            $this->assertMatchesRegularExpression('/^[0-9]{10}$/', $tree['human_barcode']);
        }

        // Check getRackDetail
        $detail = $this->service->getRackDetail($rack10->id);
        $this->assertNotNull($detail);
        $this->assertEquals(7, $detail['tree_count']);
        $this->assertEquals(128, $detail['total_quantity']);
    }

    // ─── Test 12: Rack with ONLY oven trees is considered empty ───
    public function test_rack_with_only_oven_trees_is_considered_empty(): void
    {
        $rack = LostWaxCoatingRack::where('rack_number', 5)->first();

        // 3 trees in oven associated with this rack
        for ($i = 1; $i <= 3; $i++) {
            $this->createTree([
                'rack_id' => $rack->id,
                'quantity' => 20,
                'current_stage' => 'oven',
                'last_scan_at' => now(),
            ]);
        }

        $activeRacks = $this->service->getActiveRacks();
        $this->assertEmpty($activeRacks, 'Rack with only oven trees should not be in active racks');

        $detail = $this->service->getRackDetail($rack->id);
        $this->assertNull($detail, 'getRackDetail should return null for rack with only oven trees');
    }
}
