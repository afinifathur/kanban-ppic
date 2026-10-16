<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use App\Services\SandCasting\SandCastingProductionStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductionStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected SandCastingProductionStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution']);

        $roleAdmin = Role::firstOrCreate(['name' => 'admin']);
        $roleAdmin->givePermissionTo([$permPlanning, $permExecution]);

        $this->adminUser = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@peroniks.com',
        ]);
        $this->adminUser->assignRole('admin');

        $this->service = new SandCastingProductionStatusService;
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        static $seq = 1;
        $num = $seq++;

        return ProductionPlan::create(array_merge([
            'code' => 'S'.sprintf('%03d', $num),
            'title' => 'Rencana Sand Casting '.$num,
            'item_code' => 'ITEM-'.$num,
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-SC-'.$num,
            'line_number' => 1,
            'po_quantity' => 1000,
            'qty_planned' => 1100,
            'qty_remaining' => 1100,
            'customer' => 'PT SINAR METAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ], $attributes));
    }

    protected function createKtr(
        ProductionPlan $plan,
        int $qtyGood = 100,
        int $qtyReject = 0,
        string $stage = 'netto',
        ?string $heatNumber = null,
        ?SandCastingCastingOrder $order = null
    ): SandCastingCastingResultLine {
        static $ktrSeq = 1;
        $num = $ktrSeq++;

        if (! $order) {
            $order = SandCastingCastingOrder::create([
                'casting_order_number' => 'PCOR-'.now()->format('ymd').'-'.sprintf('%05d', $num),
                'scheduled_date' => now()->toDateString(),
                'status' => 'ISSUED',
                'created_by' => $this->adminUser->id,
            ]);
        }

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => $qtyGood + $qtyReject,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '2"',
            'aisi' => 'FC250',
        ]);

        $heat = $heatNumber ?? 'HN-'.now()->format('ymd').'-'.sprintf('%04d', $num);
        $result = SandCastingCastingResult::firstOrCreate(
            ['heat_number' => $heat],
            [
                'cast_date' => now()->toDateString(),
                'furnace' => 'F1',
                'shift' => '1',
                'operator_name' => 'Budi Cor',
                'recorded_by' => $this->adminUser->id,
            ]
        );

        return $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-'.now()->format('Ymd').'-'.sprintf('%06d', $num),
            'qty_good' => $qtyGood,
            'qty_reject' => $qtyReject,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => ($qtyGood + $qtyReject) * 2.50,
            'current_stage' => $stage,
        ]);
    }

    protected function createExecution(
        SandCastingCastingResultLine $ktr,
        string $stage,
        string $checkpointCode,
        int $inputQty,
        int $defectQty,
        int $goodQty,
        string $status = SandCastingStageExecution::STATUS_CONFIRMED
    ): SandCastingStageExecution {
        return $ktr->stageExecutions()->create([
            'stage' => $stage,
            'checkpoint_code' => $checkpointCode,
            'input_qty' => $inputQty,
            'defect_qty' => $defectQty,
            'good_qty' => $goodQty,
            'status' => $status,
            'operator_id' => $this->adminUser->id,
            'executed_at' => now(),
            'physical_done_at' => now(),
        ]);
    }

    /**
     * SCENARIO 1: PO 1000 / Plan 1100 / Cast 0 -> COR = "COR"
     */
    public function test_01_po_1000_plan_1100_cast_0_shows_cor(): void
    {
        $plan = $this->createPlan(['po_quantity' => 1000, 'qty_planned' => 1100]);

        $row = $this->service->calculatePlanStatusRow($plan);

        $this->assertSame(1000, $row['po_target']);
        $this->assertSame(1100, $row['planned_qty']);
        $this->assertSame(0, $row['cast_good_total']);
        $this->assertSame(0, $row['net_available_good']);
        $this->assertSame('COR', $row['cor_indicator']);
        $this->assertSame('ACTIVE', $row['status']);
    }

    /**
     * SCENARIO 2: Cast 500 / PO 1000 -> COR = "COR"
     */
    public function test_02_cast_500_below_po_shows_cor(): void
    {
        $plan = $this->createPlan(['po_quantity' => 1000, 'qty_planned' => 1100]);
        $this->createKtr($plan, 500, 0, 'netto');

        $plan->load('sandCastingResultLines.stageExecutions');
        $row = $this->service->calculatePlanStatusRow($plan);

        $this->assertSame(500, $row['cast_good_total']);
        $this->assertSame(500, $row['net_available_good']);
        $this->assertSame('COR', $row['cor_indicator']);
        $this->assertSame(500, $row['netto']);
    }

    /**
     * SCENARIO 3: Cast 1101 / reject 0 -> COR = "" (kosong)
     */
    public function test_03_cast_1101_exceeding_po_clears_cor(): void
    {
        $plan = $this->createPlan(['po_quantity' => 1000, 'qty_planned' => 1100]);
        $this->createKtr($plan, 1101, 0, 'netto');

        $plan->load('sandCastingResultLines.stageExecutions');
        $row = $this->service->calculatePlanStatusRow($plan);

        $this->assertSame(1101, $row['cast_good_total']);
        $this->assertSame(1101, $row['net_available_good']);
        $this->assertSame('', $row['cor_indicator']);
    }

    /**
     * SCENARIO 4: Cast 1101 / downstream reject 102 -> Net Available 999 (< PO 1000) -> COR = "COR"
     */
    public function test_04_cast_1101_with_downstream_reject_102_reappears_cor(): void
    {
        $plan = $this->createPlan(['po_quantity' => 1000, 'qty_planned' => 1100]);
        $ktr = $this->createKtr($plan, 1101, 0, 'bubut_od');

        // Netto execution: input 1101, defect 102, good 999
        $this->createExecution($ktr, 'netto', 'NETTO_CUT', 1101, 102, 999);

        $plan->load('sandCastingResultLines.stageExecutions');
        $row = $this->service->calculatePlanStatusRow($plan);

        $this->assertSame(1101, $row['cast_good_total']);
        $this->assertSame(102, $row['r_netto']);
        $this->assertSame(999, $row['net_available_good']);
        $this->assertSame('COR', $row['cor_indicator']);
        $this->assertSame(999, $row['bubut_od']);
    }

    /**
     * SCENARIO 5: Cast 1101 / downstream reject 101 -> Net Available 1000 (== PO 1000) -> COR = "" (kosong)
     */
    public function test_05_cast_1101_with_downstream_reject_101_clears_cor(): void
    {
        $plan = $this->createPlan(['po_quantity' => 1000, 'qty_planned' => 1100]);
        $ktr = $this->createKtr($plan, 1101, 0, 'bubut_od');

        $this->createExecution($ktr, 'netto', 'NETTO_CUT', 1101, 101, 1000);

        $plan->load('sandCastingResultLines.stageExecutions');
        $row = $this->service->calculatePlanStatusRow($plan);

        $this->assertSame(1101, $row['cast_good_total']);
        $this->assertSame(101, $row['r_netto']);
        $this->assertSame(1000, $row['net_available_good']);
        $this->assertSame('', $row['cor_indicator']);
    }

    /**
     * SCENARIO 6: Multi Heat -> aggregate into one Production Code
     */
    public function test_06_multi_heat_aggregated_into_single_production_code(): void
    {
        $plan = $this->createPlan(['code' => 'S797', 'po_quantity' => 1000]);

        $this->createKtr($plan, 500, 0, 'netto', 'HEAT-A');
        $this->createKtr($plan, 400, 0, 'netto', 'HEAT-B');
        $this->createKtr($plan, 201, 0, 'netto', 'HEAT-C');

        $rows = $this->service->getAggregatedRows(['search' => 'S797']);

        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertSame('S797', $row['code']);
        $this->assertSame(1101, $row['cast_good_total']);
        $this->assertSame(3, $row['heat_count']);
        $this->assertSame(3, $row['ktr_count']);
        $this->assertSame('', $row['cor_indicator']);
    }

    /**
     * SCENARIO 7: Multi KTR -> no double counting
     */
    public function test_07_multi_ktr_positions_summed_correctly(): void
    {
        $plan = $this->createPlan(['po_quantity' => 1000]);

        // KTR 1 in Netto (200 pcs)
        $this->createKtr($plan, 200, 0, 'netto');

        // KTR 2 in Bubut OD (300 pcs)
        $ktr2 = $this->createKtr($plan, 300, 0, 'bubut_od');
        $this->createExecution($ktr2, 'netto', 'NETTO_CUT', 300, 0, 300);

        $plan->load('sandCastingResultLines.stageExecutions');
        $row = $this->service->calculatePlanStatusRow($plan);

        $this->assertSame(500, $row['cast_good_total']);
        $this->assertSame(200, $row['netto']);
        $this->assertSame(300, $row['bubut_od']);
        $this->assertSame(0, $row['bubut_cnc']);
    }

    /**
     * SCENARIO 8: Multi PCOR -> does not duplicate rows
     */
    public function test_08_multi_pcor_aggregates_into_one_row(): void
    {
        $plan = $this->createPlan(['code' => 'S999']);

        $order1 = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-01',
            'scheduled_date' => now()->toDateString(),
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);
        $order2 = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-02',
            'scheduled_date' => now()->toDateString(),
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $this->createKtr($plan, 500, 0, 'netto', 'HEAT-1', $order1);
        $this->createKtr($plan, 600, 0, 'netto', 'HEAT-2', $order2);

        $rows = $this->service->getAggregatedRows(['search' => 'S999']);

        $this->assertCount(1, $rows);
        $this->assertSame(1100, $rows[0]['cast_good_total']);
    }

    /**
     * SCENARIO 9: CNC 3 checkpoints -> quantity does not triple count
     */
    public function test_09_cnc_subcheckpoints_do_not_triple_count_quantity(): void
    {
        $plan = $this->createPlan(['po_quantity' => 100]);
        $ktr = $this->createKtr($plan, 100, 0, 'bubut_cnc');

        // Netto: input 100, good 100
        $this->createExecution($ktr, 'netto', 'NETTO_CUT', 100, 0, 100);

        // OD: input 100, good 100
        $this->createExecution($ktr, 'bubut_od', 'OD_TURNING', 100, 0, 100);

        // CNC Sub-checkpoint 1: CNC_MACHINING (input 100, defect 2, good 98)
        $this->createExecution($ktr, 'bubut_cnc', 'CNC_MACHINING', 100, 2, 98);

        // CNC Sub-checkpoint 2: QC_POST_CNC (input 98, defect 1, good 97)
        $this->createExecution($ktr, 'bubut_cnc', 'QC_POST_CNC', 98, 1, 97);

        // CNC Sub-checkpoint 3: QC_PRE_BOR (input 97, defect 0, good 97)
        $this->createExecution($ktr, 'bubut_cnc', 'QC_PRE_BOR', 97, 0, 97);

        $plan->load('sandCastingResultLines.stageExecutions');
        $row = $this->service->calculatePlanStatusRow($plan);

        // Defect CNC is sum of 3 checkpoints: 2 + 1 + 0 = 3
        $this->assertSame(3, $row['r_cnc']);
        // CNC active WIP is the effective input quantity at CNC = 100
        $this->assertSame(100, $row['bubut_cnc']);
    }

    /**
     * SCENARIO 10: R per stage is non-cumulative
     */
    public function test_10_r_per_stage_is_non_cumulative(): void
    {
        $plan = $this->createPlan(['po_quantity' => 100]);
        $ktr = $this->createKtr($plan, 100, 5, 'bubut_od'); // 5 reject at casting

        // Netto: defect 30
        $this->createExecution($ktr, 'netto', 'NETTO_CUT', 100, 30, 70);

        // OD: defect 10
        $this->createExecution($ktr, 'bubut_od', 'OD_TURNING', 70, 10, 60);

        $plan->load('sandCastingResultLines.stageExecutions');
        $row = $this->service->calculatePlanStatusRow($plan);

        $this->assertSame(5, $row['r_cor']);
        $this->assertSame(30, $row['r_netto']);
        $this->assertSame(10, $row['r_od']);
        // Bubut OD active WIP is effective input quantity at OD = 70 (100 - 30 netto defect)
        $this->assertSame(70, $row['bubut_od']);
        $this->assertSame(45, $row['total_reject']); // 5 + 30 + 10
    }

    /**
     * SCENARIO 11: GD 999 / PO 1000 -> ACTIVE, remaining_po = 1
     */
    public function test_11_gd_below_po_is_active(): void
    {
        $plan = $this->createPlan(['po_quantity' => 1000]);
        $ktr = $this->createKtr($plan, 999, 0, 'completed');

        $this->createExecution($ktr, 'gudang_jadi', 'GUDANG_RECEIVE', 999, 0, 999);

        $plan->load('sandCastingResultLines.stageExecutions');
        $row = $this->service->calculatePlanStatusRow($plan);

        $this->assertSame(999, $row['gd']);
        $this->assertSame(1, $row['remaining_po']);
        $this->assertSame('ACTIVE', $row['status']);
    }

    /**
     * SCENARIO 12: GD 1000 / PO 1000 -> COMPLETED, remaining_po = 0
     */
    public function test_12_gd_equal_po_is_completed(): void
    {
        $plan = $this->createPlan(['po_quantity' => 1000]);
        $ktr = $this->createKtr($plan, 1000, 0, 'completed');

        $this->createExecution($ktr, 'gudang_jadi', 'GUDANG_RECEIVE', 1000, 0, 1000);

        $plan->load('sandCastingResultLines.stageExecutions');
        $row = $this->service->calculatePlanStatusRow($plan);

        $this->assertSame(1000, $row['gd']);
        $this->assertSame(0, $row['remaining_po']);
        $this->assertSame('COMPLETED', $row['status']);
    }

    /**
     * SCENARIO 13: Overproduction (GD 1050 / PO 1000) -> COMPLETED
     */
    public function test_13_overproduction_gd_1050_is_completed(): void
    {
        $plan = $this->createPlan(['po_quantity' => 1000, 'qty_planned' => 1100]);
        $ktr = $this->createKtr($plan, 1050, 0, 'completed');

        $this->createExecution($ktr, 'gudang_jadi', 'GUDANG_RECEIVE', 1050, 0, 1050);

        $plan->load('sandCastingResultLines.stageExecutions');
        $row = $this->service->calculatePlanStatusRow($plan);

        $this->assertSame(1050, $row['gd']);
        $this->assertSame(0, $row['remaining_po']);
        $this->assertSame('COMPLETED', $row['status']);
        $this->assertSame('', $row['cor_indicator']);
    }

    /**
     * SCENARIO 14: Late defect entry maintains physical position and updates balance
     */
    public function test_14_late_defect_updates_balance_without_rewinding_stage(): void
    {
        $plan = $this->createPlan(['po_quantity' => 1000]);
        $ktr = $this->createKtr($plan, 1000, 0, 'bor'); // KTR physically already at Bor

        // Netto was physically done, but defect was recorded later (50 pcs)
        $this->createExecution($ktr, 'netto', 'NETTO_CUT', 1000, 50, 950);

        $plan->load('sandCastingResultLines.stageExecutions');
        $row = $this->service->calculatePlanStatusRow($plan);

        $this->assertSame('bor', $ktr->current_stage);
        $this->assertSame(950, $row['bor']); // usable qty reflects the 50 pcs defect
        $this->assertSame(50, $row['r_netto']);
        $this->assertSame(950, $row['net_available_good']);
        $this->assertSame('COR', $row['cor_indicator']); // 950 < 1000, so COR appears
    }

    /**
     * SCENARIO 15: Zero-good / 100% reject stops WIP addition
     */
    public function test_15_zero_good_halted_ktr_does_not_add_wip(): void
    {
        $plan = $this->createPlan(['po_quantity' => 1000]);
        $ktr = $this->createKtr($plan, 1000, 0, 'netto');

        // Netto 100% defect
        $this->createExecution($ktr, 'netto', 'NETTO_CUT', 1000, 1000, 0);

        $plan->load('sandCastingResultLines.stageExecutions');
        $row = $this->service->calculatePlanStatusRow($plan);

        $this->assertSame(0, $row['netto']);
        $this->assertSame(1000, $row['r_netto']);
        $this->assertSame(0, $row['net_available_good']);
        $this->assertSame('COR', $row['cor_indicator']);
    }

    /**
     * SCENARIO 16: Detail - 1 KTR returns 1 detail item
     */
    public function test_16_detail_single_ktr_returns_single_detail_item(): void
    {
        $plan = $this->createPlan(['code' => 'S160', 'customer' => 'PT ABC', 'item_name' => 'FLANGE 2 INCH']);
        $this->createKtr($plan, 100, 2, 'netto', 'HN-160');

        $details = $this->service->getProductionCodeDetails($plan->id);

        $this->assertNotNull($details);
        $this->assertSame('S160', $details['production_code']);
        $this->assertSame('PT ABC', $details['customer']);
        $this->assertSame('FLANGE 2 INCH', $details['item_name']);
        $this->assertSame(1, $details['ktr_count']);
        $this->assertSame(100, $details['total_physical_qty']);
        $this->assertCount(1, $details['items']);
        $this->assertSame('HN-160', $details['items'][0]['heat_number']);
        $this->assertSame('NETTO', $details['items'][0]['current_stage_label']);
        $this->assertSame(100, $details['items'][0]['quantity']);
    }

    /**
     * SCENARIO 17: Detail - Multi Heat returns all related Heats under the same Production Code
     */
    public function test_17_detail_multi_heat_returns_all_heats(): void
    {
        $plan = $this->createPlan(['code' => 'S170']);
        $this->createKtr($plan, 50, 0, 'netto', 'HEAT-170-A');
        $this->createKtr($plan, 60, 0, 'bubut_od', 'HEAT-170-B');
        $this->createKtr($plan, 70, 0, 'bubut_cnc', 'HEAT-170-C');

        $details = $this->service->getProductionCodeDetails('S170');

        $this->assertNotNull($details);
        $this->assertSame(3, $details['ktr_count']);
        $this->assertSame(180, $details['total_physical_qty']);

        $heats = collect($details['items'])->pluck('heat_number')->all();
        $this->assertContains('HEAT-170-A', $heats);
        $this->assertContains('HEAT-170-B', $heats);
        $this->assertContains('HEAT-170-C', $heats);
    }

    /**
     * SCENARIO 18: Detail - One Heat with Multi Production Code isolates KTRs per Production Code
     */
    public function test_18_detail_one_heat_multi_production_code_strict_boundary(): void
    {
        $planA = $this->createPlan(['code' => 'S180-A', 'item_name' => 'FLANGE 2"']);
        $planB = $this->createPlan(['code' => 'S180-B', 'item_name' => 'FLANGE 3"']);

        $sharedHeat = 'HEAT-SHARED-180';
        $ktrA = $this->createKtr($planA, 80, 0, 'netto', $sharedHeat);
        $ktrB = $this->createKtr($planB, 120, 0, 'bubut_od', $sharedHeat);

        $detailsA = $this->service->getProductionCodeDetails('S180-A');
        $detailsB = $this->service->getProductionCodeDetails('S180-B');

        $this->assertNotNull($detailsA);
        $this->assertNotNull($detailsB);

        $this->assertSame(1, $detailsA['ktr_count']);
        $this->assertSame(80, $detailsA['total_physical_qty']);
        $this->assertSame($ktrA->traveler_number, $detailsA['items'][0]['traveler_number']);

        $this->assertSame(1, $detailsB['ktr_count']);
        $this->assertSame(120, $detailsB['total_physical_qty']);
        $this->assertSame($ktrB->traveler_number, $detailsB['items'][0]['traveler_number']);
    }

    /**
     * SCENARIO 19: Detail - Position labels and quantities for each stage (NETTO, OD, CNC, BOR, QC, GD)
     */
    public function test_19_detail_stage_positions_and_quantities(): void
    {
        $plan = $this->createPlan(['code' => 'S190']);

        // NETTO
        $ktrNetto = $this->createKtr($plan, 100, 0, 'netto');

        // OD
        $ktrOd = $this->createKtr($plan, 90, 0, 'bubut_od');
        $this->createExecution($ktrOd, 'netto', 'NETTO_CUT', 90, 0, 90);

        // CNC
        $ktrCnc = $this->createKtr($plan, 80, 0, 'bubut_cnc');
        $this->createExecution($ktrCnc, 'netto', 'NETTO_CUT', 80, 0, 80);
        $this->createExecution($ktrCnc, 'bubut_od', 'OD_TURNING', 80, 0, 80);

        // BOR
        $ktrBor = $this->createKtr($plan, 70, 0, 'bor');
        $this->createExecution($ktrBor, 'netto', 'NETTO_CUT', 70, 0, 70);
        $this->createExecution($ktrBor, 'bubut_od', 'OD_TURNING', 70, 0, 70);
        $this->createExecution($ktrBor, 'bubut_cnc', 'CNC_MACHINING', 70, 0, 70);

        // QC
        $ktrQc = $this->createKtr($plan, 60, 0, 'qc');
        $this->createExecution($ktrQc, 'netto', 'NETTO_CUT', 60, 0, 60);
        $this->createExecution($ktrQc, 'bubut_od', 'OD_TURNING', 60, 0, 60);
        $this->createExecution($ktrQc, 'bubut_cnc', 'CNC_MACHINING', 60, 0, 60);
        $this->createExecution($ktrQc, 'bor', 'BOR_DRILLING', 60, 0, 60);

        // GD (completed)
        $ktrGd = $this->createKtr($plan, 50, 0, 'completed');
        $this->createExecution($ktrGd, 'gudang_jadi', 'GUDANG_RECEIVE', 50, 0, 50);

        $details = $this->service->getProductionCodeDetails('S190');
        $this->assertNotNull($details);
        $this->assertSame(6, $details['ktr_count']);
        $this->assertSame(450, $details['total_physical_qty']); // 100+90+80+70+60+50

        $itemByStage = collect($details['items'])->keyBy('current_stage_label');

        $this->assertSame(100, $itemByStage['NETTO']['quantity']);
        $this->assertSame(90, $itemByStage['OD']['quantity']);
        $this->assertSame(80, $itemByStage['CNC']['quantity']);
        $this->assertSame(70, $itemByStage['BOR']['quantity']);
        $this->assertSame(60, $itemByStage['QC']['quantity']);
        $this->assertSame(50, $itemByStage['GD']['quantity']);
    }

    /**
     * SCENARIO 20: Detail - Completed Production Code remains viewable with GD items
     */
    public function test_20_detail_completed_production_code_shows_gd_items(): void
    {
        $plan = $this->createPlan(['code' => 'S200', 'po_quantity' => 500]);
        $ktr = $this->createKtr($plan, 500, 0, 'completed');
        $this->createExecution($ktr, 'gudang_jadi', 'GUDANG_RECEIVE', 500, 0, 500);

        $details = $this->service->getProductionCodeDetails('S200');

        $this->assertNotNull($details);
        $this->assertSame('COMPLETED', $details['status']);
        $this->assertSame(500, $details['total_physical_qty']);
        $this->assertSame('GD', $details['items'][0]['current_stage_label']);
    }

    /**
     * SCENARIO 21: Detail - Late defect updates quantity without rewinding physical stage
     */
    public function test_21_detail_late_defect_updates_quantity_at_physical_stage(): void
    {
        $plan = $this->createPlan(['code' => 'S210', 'po_quantity' => 1000]);
        $ktr = $this->createKtr($plan, 1000, 0, 'bor');

        // Recorded 50 defects at netto
        $this->createExecution($ktr, 'netto', 'NETTO_CUT', 1000, 50, 950);

        $details = $this->service->getProductionCodeDetails('S210');

        $this->assertNotNull($details);
        $this->assertSame('BOR', $details['items'][0]['current_stage_label']);
        $this->assertSame(950, $details['items'][0]['quantity']);
        $this->assertSame(50, $details['items'][0]['defect_qty']);
    }

    /**
     * SCENARIO 22: Search - Exact Heat Number returns matching Production Plan
     */
    public function test_22_search_exact_heat_number_returns_plan(): void
    {
        $plan = $this->createPlan(['code' => 'S220']);
        $this->createKtr($plan, 100, 0, 'netto', 'HEAT-EXACT-220');

        $otherPlan = $this->createPlan(['code' => 'S221']);
        $this->createKtr($otherPlan, 100, 0, 'netto', 'HEAT-OTHER-999');

        $rows = $this->service->getAggregatedRows(['search' => 'HEAT-EXACT-220']);

        $this->assertCount(1, $rows);
        $this->assertSame('S220', $rows[0]['code']);
        $this->assertContains('HEAT-EXACT-220', $rows[0]['heat_numbers']);
    }

    /**
     * SCENARIO 23: Search - Partial Heat Number returns matching Production Plan
     */
    public function test_23_search_partial_heat_number_returns_plan(): void
    {
        $plan = $this->createPlan(['code' => 'S230']);
        $this->createKtr($plan, 100, 0, 'netto', 'A201102601');

        $rows = $this->service->getAggregatedRows(['search' => '1102601']);

        $this->assertCount(1, $rows);
        $this->assertSame('S230', $rows[0]['code']);
        $this->assertContains('A201102601', $rows[0]['heat_numbers']);
    }

    /**
     * SCENARIO 24: Search - Exact KTR traveler number returns matching Production Plan
     */
    public function test_24_search_exact_ktr_traveler_number_returns_plan(): void
    {
        $plan = $this->createPlan(['code' => 'S240']);
        $ktr = $this->createKtr($plan, 100, 0, 'netto', 'HN-240');

        $otherPlan = $this->createPlan(['code' => 'S241']);
        $this->createKtr($otherPlan, 100, 0, 'netto', 'HN-241');

        $rows = $this->service->getAggregatedRows(['search' => $ktr->traveler_number]);

        $this->assertCount(1, $rows);
        $this->assertSame('S240', $rows[0]['code']);
    }

    /**
     * SCENARIO 25: Search - Partial KTR traveler number returns matching Production Plan
     */
    public function test_25_search_partial_ktr_traveler_number_returns_plan(): void
    {
        $plan = $this->createPlan(['code' => 'S250']);
        $ktr = $this->createKtr($plan, 100, 0, 'netto', 'HN-250');

        // Extract a partial segment of the traveler number
        $partial = substr($ktr->traveler_number, -6);

        $rows = $this->service->getAggregatedRows(['search' => $partial]);

        $this->assertNotEmpty($rows);
        $this->assertTrue(collect($rows)->pluck('code')->contains('S250'));
    }

    /**
     * SCENARIO 26: Search - Unknown search term returns no unrelated records
     */
    public function test_26_search_unknown_term_returns_empty(): void
    {
        $plan = $this->createPlan(['code' => 'S260']);
        $this->createKtr($plan, 100, 0, 'netto', 'HN-260');

        $rows = $this->service->getAggregatedRows(['search' => 'COMPLETELY_NONEXISTENT_KEYWORD_XYZ']);

        $this->assertCount(0, $rows);
    }

    /**
     * SCENARIO 27: Search - Existing fields (code, po_number, customer, item_name) continue working
     */
    public function test_27_existing_search_fields_continue_working(): void
    {
        $plan = $this->createPlan([
            'code' => 'S270-ALPHA',
            'po_number' => 'PO-BETA-777',
            'customer' => 'PT GAMMA CORP',
            'item_name' => 'SPECIAL DELTA VALVE',
        ]);
        $this->createKtr($plan, 100, 0, 'netto');

        $this->assertCount(1, $this->service->getAggregatedRows(['search' => 'S270-ALPHA']));
        $this->assertCount(1, $this->service->getAggregatedRows(['search' => 'PO-BETA-777']));
        $this->assertCount(1, $this->service->getAggregatedRows(['search' => 'GAMMA CORP']));
        $this->assertCount(1, $this->service->getAggregatedRows(['search' => 'DELTA VALVE']));
    }

    /**
     * SCENARIO 28: KTR received into GD with baseline 100 and upstream defect 5 contributes 95 effective GD qty,
     * even if the stored GUDANG_RECEIVE snapshot remains 100.
     */
    public function test_28_ktr_with_gudang_receive_and_late_upstream_defect_calculates_effective_gd_qty(): void
    {
        $plan = $this->createPlan(['code' => 'S280', 'po_quantity' => 100]);
        $ktr = $this->createKtr($plan, 100, 0, 'completed');

        // Physical execution at GUDANG_RECEIVE scanned with good_qty = 100 before defect was recorded
        $gdExec = $this->createExecution($ktr, 'gudang_jadi', 'GUDANG_RECEIVE', 100, 0, 100);

        // Later defect recorded upstream at Netto (5 pcs defect)
        $this->createExecution($ktr, 'netto', 'NETTO_CUT', 100, 5, 95);

        $plan->load('sandCastingResultLines.stageExecutions');
        $row = $this->service->calculatePlanStatusRow($plan);

        // Effective GD must be 95 (100 baseline - 5 defect), not the stale snapshot 100
        $this->assertSame(95, $row['gd']);
        $this->assertSame(5, $row['remaining_po']);
        $this->assertSame('ACTIVE', $row['status']);

        // Verify stored snapshot was not mutated in database
        $this->assertSame(100, $gdExec->fresh()->good_qty);
    }

    /**
     * SCENARIO 29: KTR without a physical GUDANG_RECEIVE execution contributes zero GD quantity.
     */
    public function test_29_ktr_without_gudang_receive_execution_contributes_zero_gd_qty(): void
    {
        $plan = $this->createPlan(['code' => 'S290', 'po_quantity' => 100]);
        $ktr = $this->createKtr($plan, 100, 0, 'qc');

        // Executions up to QC, but NO GUDANG_RECEIVE
        $this->createExecution($ktr, 'netto', 'NETTO_CUT', 100, 0, 100);
        $this->createExecution($ktr, 'qc', 'QC_FINAL_INSPECTION', 100, 0, 100);

        $plan->load('sandCastingResultLines.stageExecutions');
        $row = $this->service->calculatePlanStatusRow($plan);

        $this->assertSame(0, $row['gd']);
        $this->assertSame(100, $row['qc']);
        $this->assertSame(100, $row['remaining_po']);
        $this->assertSame('ACTIVE', $row['status']);
    }

    /**
     * SCENARIO 30: Multiple KTRs within one production plan aggregate effective GD quantity correctly.
     */
    public function test_30_multiple_ktrs_within_one_plan_aggregate_effective_gd_qty_correctly(): void
    {
        $plan = $this->createPlan(['code' => 'S300', 'po_quantity' => 200]);

        // KTR 1: Baseline 100, 5 Netto defect -> effective GD = 95
        $ktr1 = $this->createKtr($plan, 100, 0, 'completed', 'HEAT-300-1');
        $this->createExecution($ktr1, 'netto', 'NETTO_CUT', 100, 5, 95);
        $this->createExecution($ktr1, 'gudang_jadi', 'GUDANG_RECEIVE', 100, 0, 100); // stale snapshot 100

        // KTR 2: Baseline 100, 10 CNC defect -> effective GD = 90
        $ktr2 = $this->createKtr($plan, 100, 0, 'completed', 'HEAT-300-2');
        $this->createExecution($ktr2, 'bubut_cnc', 'CNC_MACHINING', 100, 10, 90);
        $this->createExecution($ktr2, 'gudang_jadi', 'GUDANG_RECEIVE', 100, 0, 100); // stale snapshot 100

        $plan->load('sandCastingResultLines.stageExecutions');
        $row = $this->service->calculatePlanStatusRow($plan);

        // 95 + 90 = 185
        $this->assertSame(185, $row['gd']);
        $this->assertSame(15, $row['remaining_po']);
        $this->assertSame('ACTIVE', $row['status']);
    }

    /**
     * SCENARIO 31: Partial receipts and remaining PO quantity remain correct.
     */
    public function test_31_partial_receipt_and_remaining_po_quantity_calculation(): void
    {
        $plan = $this->createPlan(['code' => 'S310', 'po_quantity' => 200]);

        // KTR 1: Baseline 100, 5 Netto defect -> 95 received in GD
        $ktr1 = $this->createKtr($plan, 100, 0, 'completed', 'HEAT-310-1');
        $this->createExecution($ktr1, 'netto', 'NETTO_CUT', 100, 5, 95);
        $this->createExecution($ktr1, 'gudang_jadi', 'GUDANG_RECEIVE', 95, 0, 95);

        // KTR 2: Baseline 100, 0 defects, currently at Bor
        $ktr2 = $this->createKtr($plan, 100, 0, 'bor', 'HEAT-310-2');
        $this->createExecution($ktr2, 'netto', 'NETTO_CUT', 100, 0, 100);
        $this->createExecution($ktr2, 'bubut_od', 'OD_TURNING', 100, 0, 100);
        $this->createExecution($ktr2, 'bubut_cnc', 'CNC_MACHINING', 100, 0, 100);

        $plan->load('sandCastingResultLines.stageExecutions');
        $row = $this->service->calculatePlanStatusRow($plan);

        $this->assertSame(95, $row['gd']);
        $this->assertSame(100, $row['bor']);
        $this->assertSame(105, $row['remaining_po']); // 200 - 95 = 105
        $this->assertSame('ACTIVE', $row['status']);
    }

    /**
     * SCENARIO 32: PO status becomes COMPLETED only when effective GD meets existing PO target.
     */
    public function test_32_po_completion_requires_effective_gd_to_meet_po_target(): void
    {
        $plan = $this->createPlan(['code' => 'S320', 'po_quantity' => 100]);

        // KTR 1: Baseline 100, 5 Netto defect -> effective GD = 95. Stale snapshot = 100.
        $ktr1 = $this->createKtr($plan, 100, 0, 'completed', 'HEAT-320-1');
        $this->createExecution($ktr1, 'netto', 'NETTO_CUT', 100, 5, 95);
        $this->createExecution($ktr1, 'gudang_jadi', 'GUDANG_RECEIVE', 100, 0, 100);

        $plan->load('sandCastingResultLines.stageExecutions');
        $row = $this->service->calculatePlanStatusRow($plan);

        // Effective GD is 95, target is 100 -> Must be ACTIVE, NOT prematurely COMPLETED
        $this->assertSame(95, $row['gd']);
        $this->assertSame(5, $row['remaining_po']);
        $this->assertSame('ACTIVE', $row['status']);

        // Add KTR 2 with 10 pcs completed into GD
        $ktr2 = $this->createKtr($plan, 10, 0, 'completed', 'HEAT-320-2');
        $this->createExecution($ktr2, 'gudang_jadi', 'GUDANG_RECEIVE', 10, 0, 10);

        $plan->load('sandCastingResultLines.stageExecutions');
        $updatedRow = $this->service->calculatePlanStatusRow($plan);

        // Total effective GD is 95 + 10 = 105 >= 100 -> Now COMPLETED
        $this->assertSame(105, $updatedRow['gd']);
        $this->assertSame(0, $updatedRow['remaining_po']);
        $this->assertSame('COMPLETED', $updatedRow['status']);
    }

    /**
     * SCENARIO 33: The fix does not mutate stored scan snapshots or physical history in the database.
     */
    public function test_33_effective_gd_fix_does_not_mutate_stored_snapshots_or_history(): void
    {
        $plan = $this->createPlan(['code' => 'S330', 'po_quantity' => 100]);
        $ktr = $this->createKtr($plan, 100, 0, 'completed');

        $gdExec = $this->createExecution($ktr, 'gudang_jadi', 'GUDANG_RECEIVE', 100, 0, 100);
        $nettoExec = $this->createExecution($ktr, 'netto', 'NETTO_CUT', 100, 5, 95);

        $originalGdExec = $gdExec->fresh()->toArray();
        $originalNettoExec = $nettoExec->fresh()->toArray();
        $originalKtr = $ktr->fresh()->toArray();

        // Calculate plan status and query aggregated rows
        $this->service->calculatePlanStatusRow($plan->load('sandCastingResultLines.stageExecutions'));
        $this->service->getAggregatedRows(['search' => 'S330']);
        $this->service->getProductionCodeDetails('S330');

        // Confirm database records are identical
        $this->assertEquals($originalGdExec, $gdExec->fresh()->toArray());
        $this->assertEquals($originalNettoExec, $nettoExec->fresh()->toArray());
        $this->assertEquals($originalKtr, $ktr->fresh()->toArray());
    }
}
