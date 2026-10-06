<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use App\Services\SandCasting\SandCastingProductionFloorQueryService;
use App\Services\SandCasting\SandCastingProductionStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionStatusQuantityReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected SandCastingProductionStatusService $statusService;

    protected SandCastingProductionFloorQueryService $kanbanService;

    protected User $adminUser;

    protected User $operatorUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statusService = new SandCastingProductionStatusService;
        $this->kanbanService = new SandCastingProductionFloorQueryService;

        $this->adminUser = User::factory()->create([
            'name' => 'Admin PPIC',
            'email' => 'adminppic@peroniks.com',
        ]);

        $this->operatorUser = User::factory()->create([
            'name' => 'Operator Bubut',
            'email' => 'operator@peroniks.com',
        ]);
    }

    protected function createPlanWithKtrLine(int $qtyGood = 40, ?string $currentStage = 'netto'): array
    {
        $plan = ProductionPlan::create([
            'code' => 'PLAN-'.uniqid(),
            'po_number' => 'PO-'.uniqid(),
            'item_code' => 'ITM-001',
            'item_name' => 'Flange 2 Inch',
            'customer' => 'PT Test Customer',
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-'.uniqid().'-'.rand(1000, 9999),
            'scheduled_date' => now()->toDateString(),
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '2"',
            'aisi' => 'FC250',
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 250.00,
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'HEAT-'.uniqid(),
            'cast_date' => now()->toDateString(),
            'furnace' => 'F1',
            'shift' => '1',
            'recorded_by' => $this->adminUser->id,
        ]);

        $line = SandCastingCastingResultLine::create([
            'sand_casting_casting_result_id' => $result->id,
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-'.uniqid(),
            'qty_good' => $qtyGood,
            'qty_reject' => 0,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => $qtyGood * 2.50,
            'current_stage' => $currentStage,
        ]);

        return [$plan, $line];
    }

    /**
     * TEST 1: COR = 40, No defect -> Stage WIP displays 40 at whichever stage KTR resides.
     */
    public function test_production_status_no_defect_displays_full_wip_40(): void
    {
        [$planNetto, $lineNetto] = $this->createPlanWithKtrLine(40, 'netto');
        $rowNetto = $this->statusService->calculatePlanStatusRow($planNetto->fresh(['sandCastingResultLines.stageExecutions']));
        $this->assertEquals(40, $rowNetto['netto']);
        $this->assertEquals(0, $rowNetto['bubut_od']);

        [$planOd, $lineOd] = $this->createPlanWithKtrLine(40, 'bubut_od');
        $rowOd = $this->statusService->calculatePlanStatusRow($planOd->fresh(['sandCastingResultLines.stageExecutions']));
        $this->assertEquals(40, $rowOd['bubut_od']);
        $this->assertEquals(0, $rowOd['netto']);

        [$planBor, $lineBor] = $this->createPlanWithKtrLine(40, 'bor');
        $rowBor = $this->statusService->calculatePlanStatusRow($planBor->fresh(['sandCastingResultLines.stageExecutions']));
        $this->assertEquals(40, $rowBor['bor']);
    }

    /**
     * TEST 2: COR = 40, NETTO defect = 5, current_stage = OD -> OD WIP = 35.
     */
    public function test_production_status_netto_defect_5_results_in_od_wip_35(): void
    {
        [$plan, $line] = $this->createPlanWithKtrLine(40, 'bubut_od');

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 5,
            'good_qty' => 35,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => now(),
            'executed_at' => now(),
        ]);

        $row = $this->statusService->calculatePlanStatusRow($plan->fresh(['sandCastingResultLines.stageExecutions']));

        $this->assertEquals(40, $row['cast_good_total']);
        $this->assertEquals(5, $row['r_netto']);
        $this->assertEquals(35, $row['bubut_od']);
        $this->assertEquals(35, $row['net_available_good']);
    }

    /**
     * TEST 3: COR = 40, NETTO defect 5, CNC defect 2, current_stage = BOR -> BOR WIP = 33.
     */
    public function test_production_status_netto_5_plus_cnc_2_results_in_bor_wip_33(): void
    {
        [$plan, $line] = $this->createPlanWithKtrLine(40, 'bor');

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 5,
            'good_qty' => 35,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => now()->subHours(3),
            'executed_at' => now()->subHours(3),
        ]);

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'bubut_od',
            'checkpoint_code' => 'OD_TURNING',
            'input_qty' => 35,
            'defect_qty' => 0,
            'good_qty' => 35,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => now()->subHours(2),
            'executed_at' => now()->subHours(2),
        ]);

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 35,
            'defect_qty' => 2,
            'good_qty' => 33,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => now()->subHours(1),
            'executed_at' => now()->subHours(1),
        ]);

        $row = $this->statusService->calculatePlanStatusRow($plan->fresh(['sandCastingResultLines.stageExecutions']));

        $this->assertEquals(40, $row['cast_good_total']);
        $this->assertEquals(5, $row['r_netto']);
        $this->assertEquals(2, $row['r_cnc']);
        $this->assertEquals(33, $row['bor']);
        $this->assertEquals(33, $row['net_available_good']);
    }

    /**
     * TEST 4: Defect on active stage does not reduce active stage WIP/Input.
     */
    public function test_defect_on_active_stage_does_not_reduce_its_own_wip(): void
    {
        [$plan, $line] = $this->createPlanWithKtrLine(40, 'bor');

        // BOR execution has 3 defects
        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'bor',
            'checkpoint_code' => 'BOR_DRILLING',
            'input_qty' => 40,
            'defect_qty' => 3,
            'good_qty' => 37,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => now(),
            'executed_at' => now(),
        ]);

        $row = $this->statusService->calculatePlanStatusRow($plan->fresh(['sandCastingResultLines.stageExecutions']));

        // BOR WIP must show input 40 (work that entered BOR)
        $this->assertEquals(40, $row['bor']);
        $this->assertEquals(3, $row['r_bor']);
        $this->assertEquals(37, $row['net_available_good']);
    }

    /**
     * TEST 5 & 6: Late defect after downstream physical execution reconciles WIP without mutating physical database records.
     */
    public function test_late_defect_reconciles_wip_without_mutating_physical_database_records(): void
    {
        [$plan, $line] = $this->createPlanWithKtrLine(40, 'bor');

        $timeDay1 = now()->subDays(2);

        $nettoExec = SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 0,
            'good_qty' => 40,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => $timeDay1,
            'executed_at' => $timeDay1,
        ]);

        $odExec = SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'bubut_od',
            'checkpoint_code' => 'OD_TURNING',
            'input_qty' => 40,
            'defect_qty' => 0,
            'good_qty' => 40,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => $timeDay1,
            'executed_at' => $timeDay1,
        ]);

        $cncExec = SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 40,
            'defect_qty' => 0,
            'good_qty' => 40,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => $timeDay1,
            'executed_at' => $timeDay1,
        ]);

        // Day 3: Admin records late NETTO defect = 5
        $nettoExec->update([
            'defect_qty' => 5,
            'good_qty' => 35,
            'defect_entered_at' => now(),
        ]);

        $rowDay3 = $this->statusService->calculatePlanStatusRow($plan->fresh(['sandCastingResultLines.stageExecutions']));
        $this->assertEquals(35, $rowDay3['bor'], 'BOR WIP must immediately reconcile to 35');
        $this->assertEquals(35, $rowDay3['net_available_good']);

        // Admin later records CNC defect = 2
        $cncExec->update([
            'defect_qty' => 2,
            'good_qty' => 38,
            'defect_entered_at' => now(),
        ]);

        $rowAfterCnc = $this->statusService->calculatePlanStatusRow($plan->fresh(['sandCastingResultLines.stageExecutions']));
        $this->assertEquals(33, $rowAfterCnc['bor'], 'BOR WIP must immediately reconcile to 33');
        $this->assertEquals(33, $rowAfterCnc['net_available_good']);

        // Physical database records MUST remain intact
        $this->assertEquals(40, $odExec->fresh()->input_qty);
        $this->assertEquals(40, $cncExec->fresh()->input_qty);
        $this->assertEquals($timeDay1->toDateTimeString(), $odExec->fresh()->physical_done_at->toDateTimeString());
    }

    /**
     * TEST 7: Multiple KTR isolation in Production Status.
     */
    public function test_multiple_ktr_isolation_in_production_status(): void
    {
        $plan = ProductionPlan::create([
            'code' => 'PLAN-MULTI-'.uniqid(),
            'po_number' => 'PO-'.uniqid(),
            'item_code' => 'ITM-001',
            'item_name' => 'Flange 2 Inch',
            'customer' => 'PT Test Customer',
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-'.uniqid().'-'.rand(1000, 9999),
            'scheduled_date' => now()->toDateString(),
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '2"',
            'aisi' => 'FC250',
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 250.00,
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'HEAT-'.uniqid(),
            'cast_date' => now()->toDateString(),
            'furnace' => 'F1',
            'shift' => '1',
            'recorded_by' => $this->adminUser->id,
        ]);

        // KTR 1: 40 pcs at BOR with Netto defect 5 -> 35
        $line1 = SandCastingCastingResultLine::create([
            'sand_casting_casting_result_id' => $result->id,
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-1-'.uniqid(),
            'qty_good' => 40,
            'qty_reject' => 0,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 100.00,
            'current_stage' => 'bor',
        ]);

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line1->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 5,
            'good_qty' => 35,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => now(),
            'executed_at' => now(),
        ]);

        // KTR 2: 50 pcs at BOR with no defect -> 50
        $line2 = SandCastingCastingResultLine::create([
            'sand_casting_casting_result_id' => $result->id,
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-2-'.uniqid(),
            'qty_good' => 50,
            'qty_reject' => 0,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 125.00,
            'current_stage' => 'bor',
        ]);

        $row = $this->statusService->calculatePlanStatusRow($plan->fresh(['sandCastingResultLines.stageExecutions']));

        $this->assertEquals(90, $row['cast_good_total']);
        $this->assertEquals(5, $row['r_netto']);
        $this->assertEquals(85, $row['bor'], 'Total BOR WIP must be 35 + 50 = 85');
        $this->assertEquals(85, $row['net_available_good']);
    }

    /**
     * TEST 8: Balance integrity: Total WIP + Total Output + Rejects = Total COR Good.
     */
    public function test_production_status_balance_integrity(): void
    {
        [$plan, $line] = $this->createPlanWithKtrLine(40, 'bor');

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 5,
            'good_qty' => 35,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => now(),
            'executed_at' => now(),
        ]);

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 35,
            'defect_qty' => 2,
            'good_qty' => 33,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => now(),
            'executed_at' => now(),
        ]);

        $row = $this->statusService->calculatePlanStatusRow($plan->fresh(['sandCastingResultLines.stageExecutions']));

        // BOR WIP (33) + Netto Reject (5) + CNC Reject (2) = 40 (Total COR Good)
        $this->assertEquals(40, $row['bor'] + $row['r_netto'] + $row['r_cnc']);
    }

    /**
     * TEST 9: Cross-module consistency between Kanban Ready and Production Status WIP.
     */
    public function test_cross_module_consistency_between_kanban_and_production_status(): void
    {
        [$plan, $line] = $this->createPlanWithKtrLine(40, 'bor');

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 5,
            'good_qty' => 35,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => now(),
            'executed_at' => now(),
        ]);

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 35,
            'defect_qty' => 2,
            'good_qty' => 33,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => now(),
            'executed_at' => now(),
        ]);

        // Kanban Ready Card
        $kanbanCard = $this->kanbanService->resolveKanbanCard($line->fresh(['stageExecutions']));

        // Production Status Row
        $statusRow = $this->statusService->calculatePlanStatusRow($plan->fresh(['sandCastingResultLines.stageExecutions']));

        // Detail list item
        $details = $this->statusService->getProductionCodeDetails($plan->id);

        $this->assertEquals(33, $kanbanCard['qty']);
        $this->assertEquals(33, $statusRow['bor']);
        $this->assertEquals(33, $details['items'][0]['quantity']);
        $this->assertEquals($kanbanCard['qty'], $statusRow['bor'], 'Kanban Ready and Production Status WIP must be 100% identical');
    }
}
