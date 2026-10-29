<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\SandCastingWipSnapshot;
use App\Models\User;
use App\Services\SandCasting\SandCastingDashboardService;
use App\Services\SandCasting\SandCastingWipSnapshotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Zone4WipSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $spvUser;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution']);

        $roleAdmin = Role::firstOrCreate(['name' => 'admin']);
        $roleAdmin->givePermissionTo([$permPlanning, $permExecution]);

        $roleSpv = Role::firstOrCreate(['name' => 'spv']);
        $roleSpv->givePermissionTo([$permExecution]);

        $this->adminUser = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@peroniks.com',
        ]);
        $this->adminUser->assignRole('admin');

        $this->spvUser = User::factory()->create([
            'name' => 'SPV Sand Casting',
            'email' => 'spv@peroniks.com',
        ]);
        $this->spvUser->assignRole('spv');
    }

    protected function createPlanWithKtrLine(array $lineOverrides = [], array $planOverrides = []): array
    {
        static $seq = 1;
        $idx = $seq++;

        $plan = ProductionPlan::create(array_merge([
            'code' => 'FL-304-WIP-'.$idx,
            'title' => 'Rencana WIP '.$idx,
            'item_code' => 'ITEM-WIP-'.$idx,
            'customer' => 'PT Test Customer',
            'item_name' => 'Flange 3 Inch',
            'po_number' => 'PO-WIP-'.$idx,
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'weight' => 2.50,
            'status' => 'ACTIVE',
        ], $planOverrides));

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-WIP-'.$idx,
            'scheduled_date' => now()->toDateString(),
            'shift' => 1,
            'target_pcs' => 100,
            'status' => 'COMPLETED',
            'created_by' => $this->spvUser->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '3"',
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 250.00,
        ]);

        $result = SandCastingCastingResult::create([
            'sand_casting_casting_order_id' => $order->id,
            'heat_number' => 'HEAT-WIP-'.$idx,
            'cast_date' => now()->toDateString(),
            'furnace' => 'FURNACE-1',
            'shift' => '1',
            'operator_name' => 'Operator Test',
            'status' => 'LOCKED',
            'recorded_by' => $this->spvUser->id,
        ]);

        $line = $result->lines()->create(array_merge([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-WIP-'.$idx,
            'qty_good' => 50,
            'qty_reject' => 0,
            'unit_weight_kg' => 2.50,
            'current_stage' => 'netto',
            'status' => 'IN_PROGRESS',
        ], $lineOverrides));

        return [$plan, $line, $result];
    }

    /**
     * Requirement 1: Snapshot dibuat sesuai cutoff 07.00 WIB Asia/Jakarta.
     */
    public function test_1_snapshot_created_with_cutoff_0700_wib_asia_jakarta(): void
    {
        $todayStr = Carbon::now(SandCastingWipSnapshotService::TIMEZONE)->toDateString();

        $service = app(SandCastingWipSnapshotService::class);
        $snapshots = $service->captureSnapshot($todayStr);

        $this->assertNotEmpty($snapshots);

        foreach ($snapshots as $snap) {
            $this->assertSame($todayStr, $snap->snapshot_date->toDateString());
            $this->assertSame("{$todayStr} 07:00:00", $snap->scheduled_cutoff_at->format('Y-m-d H:i:s'));
            $this->assertSame('actual', $snap->source_type);
            $this->assertSame('SUCCESS', $snap->status);
        }
    }

    /**
     * Requirement 2: Kelima tahap WIP didukung (NETTO, BUBUT OD, BUBUT CNC, BOR, QC).
     */
    public function test_2_five_wip_stages_supported_in_service_and_dashboard_payload(): void
    {
        $todayStr = Carbon::now(SandCastingWipSnapshotService::TIMEZONE)->toDateString();
        $expectedStages = ['netto', 'bubut_od', 'bubut_cnc', 'bor', 'qc'];

        $service = app(SandCastingWipSnapshotService::class);
        $snapshots = $service->captureSnapshot($todayStr);

        $stagesCaptured = $snapshots->pluck('stage')->sort()->values()->all();
        sort($expectedStages);
        $this->assertSame($expectedStages, $stagesCaptured);

        // Verify in Zone 4 Dashboard endpoint (Dedicated wip_snapshot payload)
        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=4&fresh=1');
        $res->assertStatus(200);

        $zone4WipStages = $res->json('zone_4.wip_snapshot.stages');
        foreach ($expectedStages as $stg) {
            $this->assertArrayHasKey($stg, $zone4WipStages);
            $this->assertTrue($zone4WipStages[$stg]['is_wip_stage']);
        }

        // Terminal stage gudang_jadi is not in the 5 WIP stages
        $this->assertArrayNotHasKey('gudang_jadi', $zone4WipStages);

        // Throughput stages in zone_4.stages still contains all 6 stages including gudang_jadi
        $throughputStages = $res->json('zone_4.stages');
        $this->assertArrayHasKey('gudang_jadi', $throughputStages);
        $this->assertCount(6, $throughputStages);
    }

    /**
     * Requirement 3: PCS, berat, dan jumlah KTR sesuai resolver resmi.
     */
    public function test_3_pcs_weight_and_ktr_reconcile_with_official_resolvers(): void
    {
        // KTR 1 at Bubut CNC: 60 pcs root good, 2.75 kg unit weight
        // Defect in Netto (upstream): 5 pcs
        // Defect in Bubut OD (upstream): 3 pcs
        // Expected effective input to CNC = 60 - 5 - 3 = 52 pcs
        // Expected weight = 52 * 2.75 = 143.0000 kg
        [$plan, $line, $result] = $this->createPlanWithKtrLine([
            'qty_good' => 60,
            'unit_weight_kg' => 2.75,
            'current_stage' => 'bubut_cnc',
        ]);

        $yesterday = Carbon::now(SandCastingWipSnapshotService::TIMEZONE)->subDay();

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 60,
            'defect_qty' => 5,
            'good_qty' => 55,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => $yesterday,
            'executed_at' => $yesterday,
            'operator_id' => $this->spvUser->id,
        ]);

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'bubut_od',
            'checkpoint_code' => 'OD_TURNING',
            'input_qty' => 55,
            'defect_qty' => 3,
            'good_qty' => 52,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => $yesterday,
            'executed_at' => $yesterday,
            'operator_id' => $this->spvUser->id,
        ]);

        $service = app(SandCastingWipSnapshotService::class);
        $wip = $service->computeLiveActiveWip('bubut_cnc');

        $this->assertSame(52, $wip['qty_pcs']);
        $this->assertEquals(143.0, $wip['weight_kg']);
        $this->assertSame(1, $wip['ktr_count']);
    }

    /**
     * Requirement 4: KTR tidak dihitung ganda.
     */
    public function test_4_ktr_is_not_double_counted(): void
    {
        // 1 KTR with multiple executions in history must remain 1 distinct KTR
        [$plan, $line, $result] = $this->createPlanWithKtrLine([
            'qty_good' => 40,
            'unit_weight_kg' => 3.0,
            'current_stage' => 'bor',
        ]);

        // Multiple previous executions
        foreach (['NETTO_CUT', 'OD_TURNING', 'CNC_OP10', 'CNC_OP20'] as $chk) {
            SandCastingStageExecution::create([
                'sand_casting_casting_result_line_id' => $line->id,
                'stage' => 'bor',
                'checkpoint_code' => $chk,
                'input_qty' => 40,
                'defect_qty' => 0,
                'good_qty' => 40,
                'status' => SandCastingStageExecution::STATUS_CONFIRMED,
                'physical_done_at' => now(),
                'executed_at' => now(),
                'operator_id' => $this->spvUser->id,
            ]);
        }

        $service = app(SandCastingWipSnapshotService::class);
        $wip = $service->computeLiveActiveWip('bor');

        $this->assertSame(1, $wip['ktr_count']);
        $this->assertSame(40, $wip['qty_pcs']);
    }

    /**
     * Requirement 5: Scheduler / Artisan command idempotent.
     */
    public function test_5_scheduler_command_is_idempotent_no_duplicate_records(): void
    {
        $todayStr = Carbon::now(SandCastingWipSnapshotService::TIMEZONE)->toDateString();

        [$plan, $line, $result] = $this->createPlanWithKtrLine([
            'qty_good' => 20,
            'unit_weight_kg' => 2.0,
            'current_stage' => 'qc',
        ]);

        // Run command 1st time (captures both 'all' and active scope 'FLANGE_BESI')
        $exitCode1 = Artisan::call('sand-casting:capture-wip-snapshot', [
            '--date' => $todayStr,
        ]);
        $this->assertSame(0, $exitCode1);

        $countAfterFirst = SandCastingWipSnapshot::where('snapshot_date', $todayStr)->count();
        $this->assertSame(10, $countAfterFirst); // 5 stages * 2 scopes ('all' and 'FLANGE_BESI')

        $scopesCaptured = SandCastingWipSnapshot::where('snapshot_date', $todayStr)->pluck('product_scope')->unique()->sort()->values()->all();
        $this->assertSame(['FLANGE_BESI', 'all'], $scopesCaptured);

        // Run command 2nd time without --force
        $exitCode2 = Artisan::call('sand-casting:capture-wip-snapshot', [
            '--date' => $todayStr,
        ]);
        $this->assertSame(0, $exitCode2);

        $countAfterSecond = SandCastingWipSnapshot::where('snapshot_date', $todayStr)->count();
        $this->assertSame(10, $countAfterSecond); // Still exact 10 records, no duplicate!

        // Run specific scope command (idempotent for single scope)
        $exitCode3 = Artisan::call('sand-casting:capture-wip-snapshot', [
            '--date' => $todayStr,
            '--scope' => 'all',
        ]);
        $this->assertSame(0, $exitCode3);
        $this->assertSame(5, SandCastingWipSnapshot::where('snapshot_date', $todayStr)->where('product_scope', 'all')->count());
    }

    /**
     * Requirement 6: Snapshot hari sebelumnya tidak berubah ketika status KTR berubah setelah cutoff.
     */
    public function test_6_previous_day_snapshot_remains_immutable_when_ktr_moves(): void
    {
        $yesterdayStr = Carbon::now(SandCastingWipSnapshotService::TIMEZONE)->subDay()->toDateString();
        $todayStr = Carbon::now(SandCastingWipSnapshotService::TIMEZONE)->toDateString();

        // Yesterday: KTR is at NETTO with 100 pcs * 2.0 kg = 200.0 kg
        [$plan, $line, $result] = $this->createPlanWithKtrLine([
            'qty_good' => 100,
            'unit_weight_kg' => 2.0,
            'current_stage' => 'netto',
        ]);

        $service = app(SandCastingWipSnapshotService::class);
        $service->captureSnapshot($yesterdayStr);

        $yesterdayNettoSnap = SandCastingWipSnapshot::where('snapshot_date', $yesterdayStr)
            ->where('stage', 'netto')
            ->first();
        $this->assertNotNull($yesterdayNettoSnap);
        $this->assertSame(100, $yesterdayNettoSnap->qty_pcs);
        $this->assertEquals(200.0, (float) $yesterdayNettoSnap->weight_kg);

        // Today after 07:00: KTR is processed and moves from Netto to Bubut OD
        $line->update(['current_stage' => 'bubut_od']);

        // Capture today's snapshot
        $service->captureSnapshot($todayStr);

        // Verify yesterday's snapshot has NOT mutated
        $yesterdayNettoAfter = SandCastingWipSnapshot::where('snapshot_date', $yesterdayStr)
            ->where('stage', 'netto')
            ->first();
        $this->assertSame(100, $yesterdayNettoAfter->qty_pcs);
        $this->assertEquals(200.0, (float) $yesterdayNettoAfter->weight_kg);

        // Today's snapshot reflects Bubut OD
        $todayOdSnap = SandCastingWipSnapshot::where('snapshot_date', $todayStr)
            ->where('stage', 'bubut_od')
            ->first();
        $this->assertNotNull($todayOdSnap);
        $this->assertSame(100, $todayOdSnap->qty_pcs);
    }

    /**
     * Requirement 7: Grafik menggunakan nilai snapshot historis, bukan data live.
     */
    public function test_7_chart_uses_stored_historical_snapshot_not_live_data(): void
    {
        $yesterdayStr = Carbon::now(SandCastingWipSnapshotService::TIMEZONE)->subDay()->toDateString();

        // Stored snapshot with historical value: 88 pcs, 264.0 kg
        SandCastingWipSnapshot::create([
            'snapshot_date' => $yesterdayStr,
            'scheduled_cutoff_at' => Carbon::parse("{$yesterdayStr} 07:00:00"),
            'captured_at' => Carbon::parse("{$yesterdayStr} 07:00:05"),
            'stage' => 'qc',
            'product_scope' => 'all',
            'qty_pcs' => 88,
            'weight_kg' => 264.0,
            'ktr_count' => 3,
            'source_type' => SandCastingWipSnapshot::SOURCE_ACTUAL,
            'status' => SandCastingWipSnapshot::STATUS_SUCCESS,
        ]);

        // Live database currently has completely different data (0 KTR in QC)
        SandCastingCastingResultLine::query()->delete();

        // Fetch dashboard payload
        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=4&fresh=1');
        $res->assertStatus(200);

        $qcDaily = collect($res->json('zone_4.wip_snapshot.stages.qc.daily'));
        $yesterdayItem = $qcDaily->firstWhere('date', $yesterdayStr);

        $this->assertNotNull($yesterdayItem);
        $this->assertTrue($yesterdayItem['is_available']);
        $this->assertEquals(264.0, (float) $yesterdayItem['good_kg']);
        $this->assertSame(88, $yesterdayItem['good_pcs']);
        $this->assertSame(3, $yesterdayItem['ktr_count']);
    }

    /**
     * Requirement 8: Tanggal tanpa snapshot tidak ditampilkan sebagai nilai nol (is_available false, good_kg null).
     */
    public function test_8_dates_without_snapshot_are_not_rendered_as_zero(): void
    {
        // Clear all snapshots
        SandCastingWipSnapshot::query()->delete();

        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=4&fresh=1');
        $res->assertStatus(200);

        $qcDaily = $res->json('zone_4.wip_snapshot.stages.qc.daily');
        $this->assertCount(30, $qcDaily);

        foreach ($qcDaily as $day) {
            $this->assertFalse($day['is_available']);
            $this->assertNull($day['good_kg']);
            $this->assertNull($day['weight_kg']);
            $this->assertNull($day['good_pcs']);
            $this->assertNull($day['ktr_count']);
        }
    }

    /**
     * Requirement 9: Snapshot rekonstruksi dapat dibedakan dari snapshot aktual.
     */
    public function test_9_reconstructed_snapshot_is_distinguishable_from_actual(): void
    {
        $targetDateStr = Carbon::now(SandCastingWipSnapshotService::TIMEZONE)->subDays(4)->toDateString();

        SandCastingWipSnapshot::create([
            'snapshot_date' => $targetDateStr,
            'scheduled_cutoff_at' => Carbon::parse("{$targetDateStr} 07:00:00"),
            'captured_at' => now(),
            'stage' => 'bor',
            'product_scope' => 'all',
            'qty_pcs' => 45,
            'weight_kg' => 112.5,
            'ktr_count' => 2,
            'source_type' => SandCastingWipSnapshot::SOURCE_RECONSTRUCTED,
            'status' => SandCastingWipSnapshot::STATUS_SUCCESS,
        ]);

        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=4&fresh=1');
        $res->assertStatus(200);

        $borDaily = collect($res->json('zone_4.wip_snapshot.stages.bor.daily'));
        $dayItem = $borDaily->firstWhere('date', $targetDateStr);

        $this->assertNotNull($dayItem);
        $this->assertTrue($dayItem['is_available']);
        $this->assertTrue($dayItem['is_reconstructed']);
        $this->assertSame('reconstructed', $dayItem['source_type']);
        $this->assertEquals(112.5, (float) $dayItem['good_kg']);
    }

    /**
     * Requirement 10: Ringkasan menggunakan snapshot terbaru yang valid, bukan penjumlahan seluruh nilai stok 30 hari.
     */
    public function test_10_summary_uses_latest_valid_snapshot_not_sum_of_30_days(): void
    {
        $todayStr = Carbon::now(SandCastingWipSnapshotService::TIMEZONE)->toDateString();
        $yesterdayStr = Carbon::now(SandCastingWipSnapshotService::TIMEZONE)->subDay()->toDateString();
        $twoDaysAgoStr = Carbon::now(SandCastingWipSnapshotService::TIMEZONE)->subDays(2)->toDateString();

        // 2 days ago: 100 pcs, 250.0 kg
        SandCastingWipSnapshot::create([
            'snapshot_date' => $twoDaysAgoStr,
            'scheduled_cutoff_at' => Carbon::parse("{$twoDaysAgoStr} 07:00:00"),
            'captured_at' => now(),
            'stage' => 'qc',
            'product_scope' => 'all',
            'qty_pcs' => 100,
            'weight_kg' => 250.0,
            'ktr_count' => 4,
            'source_type' => SandCastingWipSnapshot::SOURCE_ACTUAL,
            'status' => SandCastingWipSnapshot::STATUS_SUCCESS,
        ]);

        // Yesterday: 90 pcs, 225.0 kg
        SandCastingWipSnapshot::create([
            'snapshot_date' => $yesterdayStr,
            'scheduled_cutoff_at' => Carbon::parse("{$yesterdayStr} 07:00:00"),
            'captured_at' => now(),
            'stage' => 'qc',
            'product_scope' => 'all',
            'qty_pcs' => 90,
            'weight_kg' => 225.0,
            'ktr_count' => 3,
            'source_type' => SandCastingWipSnapshot::SOURCE_ACTUAL,
            'status' => SandCastingWipSnapshot::STATUS_SUCCESS,
        ]);

        // Today (latest valid snapshot): 80 pcs, 200.0 kg
        SandCastingWipSnapshot::create([
            'snapshot_date' => $todayStr,
            'scheduled_cutoff_at' => Carbon::parse("{$todayStr} 07:00:00"),
            'captured_at' => now(),
            'stage' => 'qc',
            'product_scope' => 'all',
            'qty_pcs' => 80,
            'weight_kg' => 200.0,
            'ktr_count' => 2,
            'source_type' => SandCastingWipSnapshot::SOURCE_ACTUAL,
            'status' => SandCastingWipSnapshot::STATUS_SUCCESS,
        ]);

        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=4&fresh=1');
        $res->assertStatus(200);

        $qcStage = $res->json('zone_4.wip_snapshot.stages.qc');

        // Total should be latest snapshot (80 pcs & 200.0 kg), NOT sum (270 pcs & 675.0 kg)!
        $this->assertSame(80, $qcStage['total_good_pcs']);
        $this->assertEquals(200.0, (float) $qcStage['total_weight_kg']);
        $this->assertSame(2, $qcStage['total_ktr_count']);

        $this->assertNotSame(270, $qcStage['total_good_pcs']);
        $this->assertNotEquals(675.0, (float) $qcStage['total_weight_kg']);
    }

    /**
     * Requirement 11: Fitur dan pengujian throughput Zona 4 yang sudah ada tetap berfungsi.
     */
    public function test_11_existing_throughput_features_and_tests_continue_to_function(): void
    {
        $today = Carbon::now(SandCastingDashboardService::TIMEZONE);

        [$plan, $line, $result] = $this->createPlanWithKtrLine([
            'qty_good' => 50,
            'unit_weight_kg' => 3.0,
            'current_stage' => 'netto',
        ]);

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 50,
            'defect_qty' => 0,
            'good_qty' => 50,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => $today,
            'executed_at' => $today,
            'operator_id' => $this->spvUser->id,
        ]);

        $service = app(SandCastingDashboardService::class);
        $throughput = $service->buildZone4DailyThroughputTonnage($today->toDateString(), $this->adminUser, null);

        $this->assertArrayHasKey('stages', $throughput);
        $this->assertSame(6, count($throughput['stages'])); // All 6 stages for throughput
        $this->assertSame(150.0, (float) $throughput['stages']['netto']['total_weight_kg']);
        $this->assertSame(50, $throughput['stages']['netto']['total_good_pcs']);
    }

    /**
     * Requirement 12: Zona 4 menampilkan tiga grafik dalam urutan persis:
     * 1. TREN OUTPUT BAIK HARIAN — [TAHAP]
     * 2. DISTRIBUSI OUTPUT PER LINE (LINE 1–4) — [TAHAP]
     * 3. TREN WIP SNAPSHOT HARIAN — [TAHAP]
     * Serta kedua dropdown tahap (Throughput & WIP Snapshot) bekerja secara independen.
     */
    public function test_12_ui_renders_three_charts_in_exact_order_and_independent_dropdowns(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/sand-casting/dashboard');
        $response->assertStatus(200);

        $content = $response->getContent();

        // 1. Throughput bar chart canvas exists
        $this->assertStringContainsString('id="chartZone4Throughput"', $content);
        // 2. Line distribution line chart canvas exists
        $this->assertStringContainsString('id="chartZone4LineDistribution"', $content);
        // 3. WIP snapshot bar chart canvas exists
        $this->assertStringContainsString('id="chartZone4WipSnapshot"', $content);

        // Verify exact sequential order in DOM
        $pos1 = strpos($content, 'id="chartZone4Throughput"');
        $pos2 = strpos($content, 'id="chartZone4LineDistribution"');
        $pos3 = strpos($content, 'id="chartZone4WipSnapshot"');

        $this->assertNotFalse($pos1);
        $this->assertNotFalse($pos2);
        $this->assertNotFalse($pos3);
        $this->assertTrue($pos1 < $pos2, 'Chart 1 (Throughput) must precede Chart 2 (Line Distribution)');
        $this->assertTrue($pos2 < $pos3, 'Chart 2 (Line Distribution) must precede Chart 3 (WIP Snapshot)');

        // Verify both independent dropdowns exist
        $this->assertStringContainsString('id="zone4-stage-select"', $content);
        $this->assertStringContainsString('onchange="switchZone4Stage(this.value)"', $content);

        $this->assertStringContainsString('id="zone4-wip-stage-select"', $content);
        $this->assertStringContainsString('onchange="switchZone4WipStage(this.value)"', $content);

        // Verify Throughput dropdown has 6 stages including gudang_jadi
        $this->assertStringContainsString('value="gudang_jadi"', $content);

        // Verify independent summary metadata elements exist for both
        $this->assertStringContainsString('id="z4-total-kg"', $content);
        $this->assertStringContainsString('id="z4-wip-total-kg"', $content);
    }

    /**
     * Requirement 13: Hasil capture identik 100% dengan hasil agregasi Zona 2 pada data dan scope yang sama.
     */
    public function test_13_snapshot_data_is_100_percent_identical_to_zone_2_pipeline_aggregation(): void
    {
        $todayStr = Carbon::now(SandCastingWipSnapshotService::TIMEZONE)->toDateString();

        // Stage 1 (netto): 50 pcs * 2.5 kg = 125 kg (0.13 ton)
        $this->createPlanWithKtrLine(['qty_good' => 50, 'unit_weight_kg' => 2.5, 'current_stage' => 'netto']);
        // Stage 2 (bubut_od): 40 pcs * 3.0 kg = 120 kg (0.12 ton)
        $this->createPlanWithKtrLine(['qty_good' => 40, 'unit_weight_kg' => 3.0, 'current_stage' => 'bubut_od']);
        // Stage 3 (bubut_cnc): 30 pcs * 4.0 kg = 120 kg (0.12 ton)
        $this->createPlanWithKtrLine(['qty_good' => 30, 'unit_weight_kg' => 4.0, 'current_stage' => 'bubut_cnc']);
        // Stage 4 (bor): 20 pcs * 1.5 kg = 30 kg (0.03 ton)
        $this->createPlanWithKtrLine(['qty_good' => 20, 'unit_weight_kg' => 1.5, 'current_stage' => 'bor']);
        // Stage 5 (qc): 10 pcs * 5.0 kg = 50 kg (0.05 ton)
        $this->createPlanWithKtrLine(['qty_good' => 10, 'unit_weight_kg' => 5.0, 'current_stage' => 'qc']);

        // 1. Fetch Zone 2 official pipeline directly from dashboard
        $resZone2 = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=2&fresh=1');
        $resZone2->assertStatus(200);
        $z2Distribution = collect($resZone2->json('zone_2.wip_distribution'))->keyBy('stage');

        // 2. Perform snapshot capture for 'all'
        $service = app(SandCastingWipSnapshotService::class);
        $service->captureSnapshot($todayStr, 'all', true);

        // 3. Fetch Zone 4 snapshot payload
        $resZone4 = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=4&fresh=1');
        $resZone4->assertStatus(200);
        $z4Stages = $resZone4->json('zone_4.wip_snapshot.stages');

        // 4. Verify exact parity between Zone 2 and Zone 4 WIP snapshot for all 5 stages
        foreach (['netto', 'bubut_od', 'bubut_cnc', 'bor', 'qc'] as $stg) {
            $z2 = $z2Distribution[$stg];
            $z4 = $z4Stages[$stg];

            $this->assertSame((int) $z2['wip_pcs'], (int) $z4['total_good_pcs'], "PCS mismatch on stage {$stg}");
            $this->assertSame((int) $z2['ktr_count'], (int) $z4['total_ktr_count'], "KTR mismatch on stage {$stg}");
            $this->assertEquals((float) $z2['wip_ton'], (float) $z4['latest_snapshot']['wip_ton'], "Tonnage mismatch on stage {$stg}");
            $this->assertEquals((float) $z2['weight_kg'], (float) $z4['total_weight_kg'], "Weight mismatch on stage {$stg}");
        }
    }

    /**
     * Requirement 14: Isolasi scope PPIC dan Admin mengikuti aturan Zona 2 tanpa kebocoran lintas-scope.
     */
    public function test_14_scope_isolation_ppic_user_sees_exact_scope_and_cannot_leak_across_scopes(): void
    {
        $todayStr = Carbon::now(SandCastingWipSnapshotService::TIMEZONE)->toDateString();

        // Plan A: Scope FLANGE_STAINLESS (25 pcs * 2.0 kg = 50 kg)
        $this->createPlanWithKtrLine(
            ['qty_good' => 25, 'unit_weight_kg' => 2.0, 'current_stage' => 'qc'],
            ['product_scope' => 'FLANGE_STAINLESS', 'code' => 'SS-FL-01']
        );

        // Plan B: Scope FLANGE_BESI (35 pcs * 3.0 kg = 105 kg)
        $this->createPlanWithKtrLine(
            ['qty_good' => 35, 'unit_weight_kg' => 3.0, 'current_stage' => 'qc'],
            ['product_scope' => 'FLANGE_BESI', 'code' => 'BESI-FL-01']
        );

        // Create PPIC user for FLANGE_STAINLESS
        $rolePpic = Role::firstOrCreate(['name' => 'ppic']);
        $ppicStainlessUser = User::factory()->create([
            'name' => 'PPIC Flange Stainless',
            'email' => 'ppicflange@peroniks.com',
            'product_scope' => 'FLANGE_STAINLESS',
        ]);
        $ppicStainlessUser->assignRole('ppic');

        // Create PPIC user for unrelated scope with no data (FITTING_STAINLESS)
        $ppicFittingUser = User::factory()->create([
            'name' => 'PPIC Fitting',
            'email' => 'ppicfitting@peroniks.com',
            'product_scope' => 'FITTING_STAINLESS',
        ]);
        $ppicFittingUser->assignRole('ppic');

        // Capture snapshot without scope parameter (captures 'all', 'FLANGE_STAINLESS', 'FLANGE_BESI', 'FITTING_STAINLESS')
        Artisan::call('sand-casting:capture-wip-snapshot', ['--date' => $todayStr, '--force' => true]);

        // 1. PPIC Stainless User: sees ONLY FLANGE_STAINLESS (25 pcs, 50.0 kg)
        $resStainless = $this->actingAs($ppicStainlessUser)->getJson('/sand-casting/dashboard/data?zone=4&fresh=1');
        $resStainless->assertStatus(200);
        $qcStainless = $resStainless->json('zone_4.wip_snapshot.stages.qc');
        $this->assertSame(25, $qcStainless['total_good_pcs']);
        $this->assertEquals(50.0, (float) $qcStainless['total_weight_kg']);
        $this->assertTrue($qcStainless['latest_snapshot']['is_available']);

        // 2. Admin User: sees 'all' (sum of both = 60 pcs, 155.0 kg)
        $resAdmin = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=4&fresh=1');
        $resAdmin->assertStatus(200);
        $qcAdmin = $resAdmin->json('zone_4.wip_snapshot.stages.qc');
        $this->assertSame(60, $qcAdmin['total_good_pcs']);
        $this->assertEquals(155.0, (float) $qcAdmin['total_weight_kg']);

        // 3. PPIC Fitting User (no data): sees 0 pcs / 0 kg with is_available false (NO FALLBACK TO 'all')
        $resFitting = $this->actingAs($ppicFittingUser)->getJson('/sand-casting/dashboard/data?zone=4&fresh=1');
        $resFitting->assertStatus(200);
        $qcFitting = $resFitting->json('zone_4.wip_snapshot.stages.qc');
        $this->assertSame(0, $qcFitting['total_good_pcs']);
        $this->assertEquals(0.0, (float) $qcFitting['total_weight_kg']);
        $this->assertFalse($qcFitting['latest_snapshot']['is_available']);
    }

    /**
     * Requirement 15: Penanganan keterlambatan snapshot dicatat secara eksplisit pada metadata audit.
     */
    public function test_15_late_capture_adds_explicit_delay_metadata_and_warning(): void
    {
        $yesterdayStr = Carbon::now(SandCastingWipSnapshotService::TIMEZONE)->subDays(2)->toDateString();

        $this->createPlanWithKtrLine([
            'qty_good' => 15,
            'unit_weight_kg' => 2.0,
            'current_stage' => 'qc',
        ]);

        $service = app(SandCastingWipSnapshotService::class);
        // Capturing snapshot for 2 days ago today is a delayed capture
        $snaps = $service->captureSnapshot($yesterdayStr, 'all', true);

        $qcSnap = $snaps->firstWhere('stage', 'qc');
        $this->assertNotNull($qcSnap);
        $this->assertTrue($qcSnap->details['delayed_capture'] ?? false);
        $this->assertGreaterThan(0, $qcSnap->details['delay_minutes'] ?? 0);
        $this->assertStringContainsString('terlambat', $qcSnap->details['observation_note'] ?? '');
    }

    /**
     * Requirement 16: Scheduler command terdaftar tepat pada pukul 07.00 WIB Asia/Jakarta.
     */
    public function test_16_scheduler_is_registered_at_0700_wib_asia_jakarta(): void
    {
        /** @var \Illuminate\Console\Scheduling\Schedule $schedule */
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);

        $events = collect($schedule->events());
        $wipEvent = $events->first(function ($event) {
            return str_contains($event->command ?? '', 'sand-casting:capture-wip-snapshot')
                || str_contains($event->description ?? '', 'sand-casting-daily-wip-snapshot');
        });

        $this->assertNotNull($wipEvent, 'Command sand-casting:capture-wip-snapshot harus terjadwal.');
        $this->assertSame('0 7 * * *', $wipEvent->expression, 'Snapshot harus dijadwalkan tepat pukul 07.00 setiap hari.');
        $this->assertSame('Asia/Jakarta', $wipEvent->timezone, 'Timezone harus Asia/Jakarta.');
    }
}
