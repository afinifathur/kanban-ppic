<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use App\Services\SandCasting\SandCastingDashboardService;
use App\Services\SandCasting\SandCastingProductionReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Zone4DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $ppicUser;

    protected User $spvUser;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution']);

        $roleAdmin = Role::firstOrCreate(['name' => 'admin']);
        $roleAdmin->givePermissionTo([$permPlanning, $permExecution]);

        $rolePpic = Role::firstOrCreate(['name' => 'ppic']);
        $rolePpic->givePermissionTo([$permPlanning, $permExecution]);

        $roleSpv = Role::firstOrCreate(['name' => 'spv']);
        $roleSpv->givePermissionTo([$permExecution]);

        $this->adminUser = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@peroniks.com',
        ]);
        $this->adminUser->assignRole('admin');

        $this->ppicUser = User::factory()->create([
            'name' => 'Admin PPIC Flange',
            'email' => 'adminppicfl@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->ppicUser->assignRole('ppic');

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
            'code' => 'FL-304-10K-'.$idx,
            'title' => 'Rencana SC '.$idx,
            'item_code' => 'ITEM-'.$idx,
            'customer' => 'PT Test Customer',
            'item_name' => 'Flange 2 Inch',
            'po_number' => 'PO-'.$idx,
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'weight' => 2.50,
            'status' => 'ACTIVE',
        ], $planOverrides));

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-Z4-'.$idx,
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
            'size' => '2"',
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 250.00,
        ]);

        $result = SandCastingCastingResult::create([
            'sand_casting_casting_order_id' => $order->id,
            'heat_number' => 'HT-Z4-'.$idx,
            'cast_date' => now()->toDateString(),
            'recorded_by' => $this->spvUser->id,
            'operator_name' => 'Budi',
        ]);

        $line = SandCastingCastingResultLine::create(array_merge([
            'sand_casting_casting_result_id' => $result->id,
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-Z4-'.$idx,
            'qty_good' => 50,
            'qty_reject' => 2,
            'unit_weight_kg' => 2.50,
            'current_stage' => 'qc',
            'status' => 'IN_PROGRESS',
        ], $lineOverrides));

        return [$plan, $line, $result];
    }

    /**
     * 1. Default stage is QC and payload contains all 6 stages.
     */
    public function test_zone_4_default_stage_is_qc_and_contains_all_six_stages(): void
    {
        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=4');
        $res->assertStatus(200);

        $this->assertSame('qc', $res->json('zone_4.default_stage'));

        $expectedStages = ['netto', 'bubut_od', 'bubut_cnc', 'bor', 'qc', 'gudang_jadi'];
        $stages = $res->json('zone_4.stages');

        $this->assertSame($expectedStages, array_keys($stages));
        $this->assertSame('QC', $stages['qc']['stage_label']);
        $this->assertSame('NETTO', $stages['netto']['stage_label']);
        $this->assertSame('Bubut OD', $stages['bubut_od']['stage_label']);
        $this->assertSame('Bubut CNC', $stages['bubut_cnc']['stage_label']);
        $this->assertSame('BOR', $stages['bor']['stage_label']);
        $this->assertSame('Gudang Jadi', $stages['gudang_jadi']['stage_label']);
    }

    /**
     * 2. Exactly 30 consecutive calendar days including today.
     */
    public function test_zone_4_contains_exact_30_consecutive_calendar_days_including_today(): void
    {
        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=4');
        $res->assertStatus(200);

        $daily = $res->json('zone_4.stages.qc.daily');
        $this->assertCount(30, $daily);

        $todayStr = Carbon::now(SandCastingDashboardService::TIMEZONE)->toDateString();
        $startStr = Carbon::now(SandCastingDashboardService::TIMEZONE)->subDays(29)->toDateString();

        $this->assertSame($startStr, $res->json('zone_4.period_start'));
        $this->assertSame($todayStr, $res->json('zone_4.period_end'));

        // Check sequential calendar dates
        for ($i = 0; $i < 30; $i++) {
            $expectedDate = Carbon::parse($startStr)->addDays($i)->toDateString();
            $this->assertSame($expectedDate, $daily[$i]['date']);
        }
    }

    /**
     * 3. Days without production are zero, not omitted.
     */
    public function test_zone_4_days_without_production_are_zero_not_omitted(): void
    {
        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=4');
        $res->assertStatus(200);

        $daily = $res->json('zone_4.stages.qc.daily');

        foreach ($daily as $day) {
            $this->assertSame(0.0, (float) $day['good_kg']);
            $this->assertSame(0, $day['good_pcs']);
            $this->assertSame(0, $day['ktr_count']);
        }
    }

    /**
     * 4. Anti double-counting and aggregation match production report.
     */
    public function test_zone_4_anti_double_count_and_aggregation_match_production_report(): void
    {
        [$plan, $line, $result] = $this->createPlanWithKtrLine([
            'qty_good' => 80,
            'unit_weight_kg' => 3.25,
            'current_stage' => 'bubut_cnc',
        ]);

        $threeDaysAgo = Carbon::now(SandCastingDashboardService::TIMEZONE)->subDays(3);

        // CNC OP10 checkpoint
        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_OP10',
            'input_qty' => 80,
            'defect_qty' => 2,
            'good_qty' => 78,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => $threeDaysAgo,
            'executed_at' => $threeDaysAgo,
            'operator_id' => $this->spvUser->id,
        ]);

        // CNC OP20 checkpoint on the same date for the SAME KTR line
        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_OP20',
            'input_qty' => 78,
            'defect_qty' => 3,
            'good_qty' => 75,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => $threeDaysAgo,
            'executed_at' => $threeDaysAgo,
            'operator_id' => $this->spvUser->id,
        ]);

        // Effective good for CNC is 75 pcs (80 input - 2 - 3 defects)
        // Unit weight is 3.25 kg => 75 * 3.25 = 243.7500 kg
        $expectedWeightKg = 75 * 3.25;

        // Fetch Zone 4 data
        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=4&fresh=1');
        $res->assertStatus(200);

        $cncStage = $res->json('zone_4.stages.bubut_cnc');
        $this->assertEquals($expectedWeightKg, (float) $cncStage['total_weight_kg']);
        $this->assertSame(75, $cncStage['total_good_pcs']);
        $this->assertSame(1, $cncStage['total_ktr_count']); // 1 distinct KTR, not 2!

        // Find the specific day in daily series
        $targetDateStr = $threeDaysAgo->toDateString();
        $targetDay = collect($cncStage['daily'])->firstWhere('date', $targetDateStr);

        $this->assertNotNull($targetDay);
        $this->assertEquals($expectedWeightKg, (float) $targetDay['good_kg']);
        $this->assertSame(75, $targetDay['good_pcs']);
        $this->assertSame(1, $targetDay['ktr_count']);

        // Reconcile with canonical SandCastingProductionReportService
        $reportService = app(SandCastingProductionReportService::class);
        $reportData = $reportService->getProductionDataset([
            'date_from' => $targetDateStr,
            'date_to' => $targetDateStr,
            'stage' => 'bubut_cnc',
        ], $this->adminUser, includeDetails: false);

        $reportSummary = collect($reportData['stage_summaries'])->firstWhere('stage', 'bubut_cnc');
        $this->assertNotNull($reportSummary);
        $this->assertEquals($expectedWeightKg, (float) $reportSummary['weight_kg']);
        $this->assertSame(75, $reportSummary['good_pcs']);
        $this->assertSame(1, $reportSummary['ktr_count']);
    }

    /**
     * 5. Weight precision is preserved in kg without lossy ton rounding.
     */
    public function test_zone_4_preserves_kilogram_precision_without_lossy_ton_rounding(): void
    {
        [$plan, $line, $result] = $this->createPlanWithKtrLine([
            'qty_good' => 10,
            'unit_weight_kg' => 2.35,
            'current_stage' => 'qc',
        ]);

        $today = Carbon::now(SandCastingDashboardService::TIMEZONE);

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'qc',
            'checkpoint_code' => 'QC_VISUAL',
            'input_qty' => 10,
            'defect_qty' => 0,
            'good_qty' => 10,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => $today,
            'executed_at' => $today,
            'operator_id' => $this->spvUser->id,
        ]);

        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=4&fresh=1');
        $res->assertStatus(200);

        // 10 * 2.35 = 23.5 kg.
        // If it were converted to ton and rounded to 2 decimals: 23.5 / 1000 = 0.02 ton -> 20.0 kg!
        // We ensure 23.5000 kg is preserved.
        $qcStage = $res->json('zone_4.stages.qc');
        $this->assertEquals(23.5, (float) $qcStage['total_weight_kg']);
        $this->assertNotEquals(20.0, (float) $qcStage['total_weight_kg']);
    }

    /**
     * 6. Blade template contains stage selector dropdown defaulting to QC, responsive canvas, and switch function.
     */
    public function test_zone_4_dropdown_selection_and_ui_rendering_in_blade(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/sand-casting/dashboard?zone=4');
        $response->assertStatus(200);

        $content = $response->getContent();

        // Dropdown exists with 6 stages and QC is selected by default
        $this->assertStringContainsString('id="zone4-stage-select"', $content);
        $this->assertStringContainsString('value="qc" selected', $content);
        $this->assertStringContainsString('value="netto"', $content);
        $this->assertStringContainsString('value="bubut_od"', $content);
        $this->assertStringContainsString('value="bubut_cnc"', $content);
        $this->assertStringContainsString('value="bor"', $content);
        $this->assertStringContainsString('value="gudang_jadi"', $content);

        // Canvas exists
        $this->assertStringContainsString('id="chartZone4Throughput"', $content);

        // Switch function exists
        $this->assertStringContainsString('function switchZone4Stage(stageKey)', $content);
    }

    /**
     * 7. Zone 1 displays weight in kilogram with four decimal places.
     */
    public function test_zone_1_displays_weight_with_four_decimal_places(): void
    {
        [$plan, $line, $result] = $this->createPlanWithKtrLine([
            'qty_good' => 100,
            'qty_reject' => 0,
            'unit_weight_kg' => 492.46,
            'current_stage' => 'netto',
        ]);

        $today = Carbon::now(SandCastingDashboardService::TIMEZONE);

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 0,
            'good_qty' => 100,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => $today,
            'executed_at' => $today,
            'operator_id' => $this->spvUser->id,
        ]);

        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=1&fresh=1');
        $res->assertStatus(200);

        $netto = collect($res->json('zone_1.daily_throughput_stages'))->firstWhere('stage', 'netto');
        $this->assertNotNull($netto);
        $this->assertArrayHasKey('good_kg', $netto);
        $this->assertEquals(49246.0, (float) $netto['good_kg']);

        // Check Blade UI formatting
        $viewRes = $this->actingAs($this->adminUser)->get('/sand-casting/dashboard?zone=1');
        $viewRes->assertStatus(200);
        $viewContent = $viewRes->getContent();

        // Check formatNumber(..., 4) is used for Throughput labels and Y-axis callback
        $this->assertStringContainsString('formatNumber(kgVal, 4)', $viewContent);
        $this->assertStringContainsString("callback: v => formatNumber(v, 4) + ' kg'", $viewContent);
        $this->assertStringContainsString('THROUGHPUT 6 TAHAP PROSES (KG & PCS)', $viewContent);
    }

    /**
     * 8. Four line series are available for all six stages with identical 30-day date ranges.
     */
    public function test_zone_4_four_line_series_available_for_all_six_stages(): void
    {
        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=4&fresh=1');
        $res->assertStatus(200);

        $stages = $res->json('zone_4.stages');
        $expectedStages = ['netto', 'bubut_od', 'bubut_cnc', 'bor', 'qc', 'gudang_jadi'];

        foreach ($expectedStages as $stgKey) {
            $this->assertArrayHasKey($stgKey, $stages);
            $stage = $stages[$stgKey];

            // series_by_line contains 1, 2, 3, 4
            $this->assertArrayHasKey('series_by_line', $stage);
            $seriesByLine = $stage['series_by_line'];
            $this->assertSame(['1', '2', '3', '4'], array_map('strval', array_keys($seriesByLine)));

            foreach ([1, 2, 3, 4] as $ln) {
                $this->assertCount(30, $seriesByLine[$ln]['data']);
                $this->assertSame("Line {$ln}", $seriesByLine[$ln]['label']);
            }

            // Each daily record also has lines 1..4
            $daily = $stage['daily'];
            $this->assertCount(30, $daily);
            foreach ($daily as $day) {
                $this->assertArrayHasKey('lines', $day);
                $this->assertSame(['1', '2', '3', '4'], array_map('strval', array_keys($day['lines'])));
                $this->assertArrayHasKey('unallocated_kg', $day);
                $this->assertArrayHasKey('unallocated_pcs', $day);
                $this->assertArrayHasKey('is_fully_reconciled', $day);
            }
        }
    }

    /**
     * 9. Total of four lines exactly reconciles with daily bar chart when all data has valid line identity.
     */
    public function test_zone_4_total_four_lines_reconciles_with_daily_bar_chart_when_line_identity_valid(): void
    {
        $targetDate = Carbon::now(SandCastingDashboardService::TIMEZONE)->subDays(2);

        // Create 4 KTR lines on Line 1, Line 2, Line 3, Line 4 with different weights and quantities
        $lineConfigs = [
            1 => ['qty' => 50, 'weight' => 2.0, 'stage' => 'qc'],
            2 => ['qty' => 40, 'weight' => 3.5, 'stage' => 'qc'],
            3 => ['qty' => 30, 'weight' => 5.0, 'stage' => 'qc'],
            4 => ['qty' => 20, 'weight' => 8.25, 'stage' => 'qc'],
        ];

        foreach ($lineConfigs as $lineNum => $cfg) {
            [$plan, $ktrLine, $result] = $this->createPlanWithKtrLine(
                [
                    'qty_good' => $cfg['qty'],
                    'unit_weight_kg' => $cfg['weight'],
                    'current_stage' => 'qc',
                ],
                [
                    'line_number' => $lineNum,
                    'weight' => $cfg['weight'],
                ]
            );

            SandCastingStageExecution::create([
                'sand_casting_casting_result_line_id' => $ktrLine->id,
                'stage' => 'qc',
                'checkpoint_code' => 'QC_FINAL',
                'input_qty' => $cfg['qty'],
                'defect_qty' => 0,
                'good_qty' => $cfg['qty'],
                'status' => SandCastingStageExecution::STATUS_CONFIRMED,
                'physical_done_at' => $targetDate,
                'executed_at' => $targetDate,
                'operator_id' => $this->spvUser->id,
            ]);
        }

        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=4&fresh=1');
        $res->assertStatus(200);

        $qcStage = $res->json('zone_4.stages.qc');
        $daily = collect($qcStage['daily']);
        $dayData = $daily->firstWhere('date', $targetDate->toDateString());

        $this->assertNotNull($dayData);

        // Expected totals:
        // Line 1: 50 * 2.0 = 100.0 kg
        // Line 2: 40 * 3.5 = 140.0 kg
        // Line 3: 30 * 5.0 = 150.0 kg
        // Line 4: 20 * 8.25 = 165.0 kg
        // Total = 555.0 kg, Total PCS = 140 pcs
        $this->assertEquals(100.0, (float) $dayData['lines'][1]['good_kg']);
        $this->assertEquals(140.0, (float) $dayData['lines'][2]['good_kg']);
        $this->assertEquals(150.0, (float) $dayData['lines'][3]['good_kg']);
        $this->assertEquals(165.0, (float) $dayData['lines'][4]['good_kg']);

        $sum4LinesKg = (float) $dayData['lines'][1]['good_kg']
            + (float) $dayData['lines'][2]['good_kg']
            + (float) $dayData['lines'][3]['good_kg']
            + (float) $dayData['lines'][4]['good_kg'];

        $sum4LinesPcs = (int) $dayData['lines'][1]['good_pcs']
            + (int) $dayData['lines'][2]['good_pcs']
            + (int) $dayData['lines'][3]['good_pcs']
            + (int) $dayData['lines'][4]['good_pcs'];

        // Sum matches bar chart total exactly
        $this->assertEquals((float) $dayData['good_kg'], $sum4LinesKg);
        $this->assertSame((int) $dayData['good_pcs'], $sum4LinesPcs);
        $this->assertEquals(0.0, (float) $dayData['unallocated_kg']);
        $this->assertSame(0, $dayData['unallocated_pcs']);
        $this->assertTrue($dayData['is_fully_reconciled']);

        // Stage totals also reconcile
        $this->assertTrue($qcStage['is_fully_reconciled']);
        $this->assertEquals(0.0, (float) $qcStage['total_unallocated_kg']);
    }

    /**
     * 10. Data without line identity is not estimated into lines 1-4 and reports reconciliation gap.
     */
    public function test_zone_4_unassigned_line_identity_reported_as_reconciliation_gap_without_fake_distribution(): void
    {
        $targetDate = Carbon::now(SandCastingDashboardService::TIMEZONE)->subDays(1);

        // Create KTR without production plan
        [$plan, $ktrLine, $result] = $this->createPlanWithKtrLine([
            'qty_good' => 35,
            'unit_weight_kg' => 4.0,
            'current_stage' => 'netto',
            'production_plan_id' => null,
        ]);

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $ktrLine->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 35,
            'defect_qty' => 0,
            'good_qty' => 35,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => $targetDate,
            'executed_at' => $targetDate,
            'operator_id' => $this->spvUser->id,
        ]);

        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=4&fresh=1');
        $res->assertStatus(200);

        $nettoStage = $res->json('zone_4.stages.netto');
        $dayData = collect($nettoStage['daily'])->firstWhere('date', $targetDate->toDateString());

        $this->assertNotNull($dayData);

        // Lines 1..4 should all be 0 kg
        foreach ([1, 2, 3, 4] as $ln) {
            $this->assertEquals(0.0, (float) $dayData['lines'][$ln]['good_kg']);
            $this->assertSame(0, $dayData['lines'][$ln]['good_pcs']);
        }

        // Unallocated must capture the 35 pcs * 4.0 kg = 140.0 kg
        $this->assertEquals(140.0, (float) $dayData['unallocated_kg']);
        $this->assertSame(35, $dayData['unallocated_pcs']);
        $this->assertFalse($dayData['is_fully_reconciled']);

        // Day bar total still reflects true 140.0 kg output
        $this->assertEquals(140.0, (float) $dayData['good_kg']);
    }

    /**
     * 11. Line chart canvas, legend badges, and dual-chart switcher exist in Blade view.
     */
    public function test_zone_4_line_chart_canvas_and_dual_switcher_rendered_in_blade(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/sand-casting/dashboard?zone=4');
        $response->assertStatus(200);

        $content = $response->getContent();

        // Second chart canvas exists
        $this->assertStringContainsString('id="chartZone4LineDistribution"', $content);

        // Line legend badges exist
        $this->assertStringContainsString('Line 1', $content);
        $this->assertStringContainsString('Line 2', $content);
        $this->assertStringContainsString('Line 3', $content);
        $this->assertStringContainsString('Line 4', $content);

        // Header for line distribution chart exists
        $this->assertStringContainsString('DISTRIBUSI OUTPUT PER LINE (LINE 1–4)', $content);
        $this->assertStringContainsString('id="z4-lines-stage-label"', $content);

        // JavaScript checks: both charts updated on stage switch and in-place polling
        $this->assertStringContainsString('dashboardCharts.zone4Lines', $content);
        $this->assertStringContainsString('dashboardCharts.zone4Throughput', $content);
    }

    /**
     * 12. Compact summary metadata above bar chart and integer Y-axis label formatting rendered in Blade.
     */
    public function test_zone_4_compact_summary_metadata_and_integer_axis_formatting_rendered_in_blade(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/sand-casting/dashboard?zone=4');
        $response->assertStatus(200);

        $content = $response->getContent();

        // Compact summary metadata exists above bar chart with Zone 3 styling
        $this->assertStringContainsString('TOTAL 30 HARI (', $content);
        $this->assertStringContainsString('id="z4-summary-stage-name"', $content);
        $this->assertStringContainsString('id="z4-total-kg"', $content);
        $this->assertStringContainsString('id="z4-total-pcs"', $content);
        $this->assertStringContainsString('id="z4-total-ktr"', $content);

        // Y-axis ticks on both charts use integer formatting (e.g. 0 kg, 500 kg, 1.000 kg)
        $this->assertStringContainsString("callback: function(value) { return formatNumber(value, 0) + ' kg'; }", $content);

        // Detail tooltips retain 4 decimal places precision for kg
        $this->assertStringContainsString('` Output Baik: ${formatNumber(kgVal, 4)} kg`', $content);
        $this->assertStringContainsString('` ${context.dataset.label}: ${formatNumber(val, 4)} kg`', $content);
    }

    /**
     * 13. Direct data labels on Zone 4 bar chart use shared directDataLabelsPlugin and omit zero-production days.
     */
    public function test_zone_4_bar_chart_direct_data_labels_use_direct_data_labels_plugin_and_omit_zero_days(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/sand-casting/dashboard?zone=4');
        $response->assertStatus(200);

        $content = $response->getContent();

        // directDataLabelsPlugin is shared between Zona 1 and Zona 4
        $this->assertStringContainsString('plugins: [directDataLabelsPlugin]', $content);

        // Bar label formats: 4-decimal kg and PCS
        $this->assertStringContainsString('const kgText = `${formatNumber(kgVal, 4)} kg`;', $content);
        $this->assertStringContainsString('const pcsText = `${formatNumber(pcsVal, 0)} PCS`;', $content);

        // Days with zero production (0 kg & 0 pcs) are omitted in dense 30-day mode to prevent clutter
        $this->assertStringContainsString('if (isDense && kgVal === 0 && pcsVal === 0)', $content);

        // Ample headroom provided to prevent label clipping
        $this->assertStringContainsString('maxKg * 1.35', $content);
        $this->assertStringContainsString('padding: { top: 32', $content);
    }
}
