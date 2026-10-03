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
        // Quantity CNC is the latest output = 97 (NOT 98 + 97 + 97 = 292!)
        $this->assertSame(97, $row['bubut_cnc']);
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
        $this->assertSame(60, $row['bubut_od']);
        $this->assertSame(45, $row['total_reject']); // 5 + 30 + 10
    }

    /**
     * SCENARIO 11: GD 999 / PO 1000 -> ACTIVE, remaining_po = 1
     */
    public function test_11_gd_below_po_is_active(): void
    {
        $plan = $this->createPlan(['po_quantity' => 1000]);
        $ktr = $this->createKtr($plan, 1000, 0, 'completed');

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
        $ktr = $this->createKtr($plan, 1150, 0, 'completed');

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
}
