<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use App\Services\SandCasting\SandCastingProductionFloorQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalKanbanQuantityReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected SandCastingProductionFloorQueryService $queryService;

    protected User $adminUser;

    protected User $operatorUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queryService = new SandCastingProductionFloorQueryService;

        $this->adminUser = User::factory()->create([
            'name' => 'Admin PPIC',
            'email' => 'adminppic@peroniks.com',
        ]);

        $this->operatorUser = User::factory()->create([
            'name' => 'Operator Bubut',
            'email' => 'operator@peroniks.com',
        ]);
    }

    protected function createKtrLine(int $qtyGood = 40, ?string $currentStage = 'netto', ?int $queuePosition = null): SandCastingCastingResultLine
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

        return SandCastingCastingResultLine::create([
            'sand_casting_casting_result_id' => $result->id,
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-'.uniqid(),
            'qty_good' => $qtyGood,
            'qty_reject' => 0,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => $qtyGood * 2.50,
            'current_stage' => $currentStage,
            'queue_position' => $queuePosition,
        ]);
    }

    /**
     * KANBAN TEST 1: COR = 40, No defect -> Ready Card displays 40 on each stage.
     */
    public function test_kanban_no_defect_displays_full_quantity_40(): void
    {
        $lineNetto = $this->createKtrLine(qtyGood: 40, currentStage: 'netto');
        $cardNetto = $this->queryService->resolveKanbanCard($lineNetto);
        $this->assertEquals(40, $cardNetto['qty']);
        $this->assertEquals(40, $cardNetto['input_qty']);

        $lineOd = $this->createKtrLine(qtyGood: 40, currentStage: 'bubut_od');
        $cardOd = $this->queryService->resolveKanbanCard($lineOd);
        $this->assertEquals(40, $cardOd['qty']);

        $lineCnc = $this->createKtrLine(qtyGood: 40, currentStage: 'bubut_cnc');
        $cardCnc = $this->queryService->resolveKanbanCard($lineCnc);
        $this->assertEquals(40, $cardCnc['qty']);

        $lineBor = $this->createKtrLine(qtyGood: 40, currentStage: 'bor');
        $cardBor = $this->queryService->resolveKanbanCard($lineBor);
        $this->assertEquals(40, $cardBor['qty']);
    }

    /**
     * KANBAN TEST 2: COR = 40, NETTO defect = 5 -> Downstream Ready displays 35.
     */
    public function test_kanban_netto_defect_5_propagates_to_downstream_stages_as_35(): void
    {
        $line = $this->createKtrLine(qtyGood: 40, currentStage: 'bubut_od');

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

        $line->load('stageExecutions');

        // OD Ready card must display 35
        $cardOd = $this->queryService->resolveKanbanCard($line);
        $this->assertEquals(35, $cardOd['qty']);
        $this->assertEquals(35, $cardOd['input_qty']);

        // Check incoming card from OD to CNC
        $cardIncomingCnc = $this->queryService->resolveIncomingKanbanCard($line, 'bubut_cnc');
        $this->assertEquals(35, $cardIncomingCnc['qty']);

        // Simulate moving line to BOR
        $line->current_stage = 'bor';
        $line->save();

        $cardBor = $this->queryService->resolveKanbanCard($line);
        $this->assertEquals(35, $cardBor['qty']);
        $this->assertEquals(35, $cardBor['input_qty']);
    }

    /**
     * KANBAN TEST 3: COR = 40, NETTO defect 5, CNC defect 2 -> BOR Ready = 33.
     */
    public function test_kanban_netto_5_plus_cnc_2_displays_33_on_bor_ready(): void
    {
        $line = $this->createKtrLine(qtyGood: 40, currentStage: 'bor');

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

        $line->load('stageExecutions');

        // Kanban Board query for stage 'bor'
        $kanbanData = $this->queryService->getStageKanbanData('bor');
        $borCards = $kanbanData['ready'];

        $this->assertCount(1, $borCards);
        $this->assertEquals(33, $borCards[0]['qty']);
        $this->assertEquals(33, $borCards[0]['input_qty']);
        $this->assertEquals(33, $kanbanData['summary']['ready_qty']);
    }

    /**
     * KANBAN TEST 4: Physical BOR execution already input 40 -> then late NETTO defect 5 -> Kanban BOR = 35.
     * Database historical execution input_qty must remain 40.
     */
    public function test_kanban_late_defect_reconciles_display_without_mutating_database_snapshots(): void
    {
        $line = $this->createKtrLine(qtyGood: 40, currentStage: 'bor');

        $timeDay1 = now()->subDays(2);

        // Day 1: Executions recorded with provisional 40
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

        // Day 3: Admin records NETTO defect = 5
        $nettoExec->update([
            'defect_qty' => 5,
            'good_qty' => 35,
            'defect_entered_at' => now(),
            'defect_entered_by' => $this->adminUser->id,
        ]);

        $line->load('stageExecutions');

        // Kanban Card for BOR must show 35 pcs
        $cardBor = $this->queryService->resolveKanbanCard($line);
        $this->assertEquals(35, $cardBor['qty']);
        $this->assertEquals(35, $cardBor['input_qty']);

        // Historical snapshots in DB MUST REMAIN 40
        $this->assertEquals(40, $odExec->fresh()->input_qty);
        $this->assertEquals(40, $cncExec->fresh()->input_qty);
    }

    /**
     * KANBAN TEST 5: Late defect does NOT alter queue_position, current_stage, or physical_done_at.
     */
    public function test_late_defect_does_not_mutate_queue_position_or_stage(): void
    {
        $line = $this->createKtrLine(qtyGood: 40, currentStage: 'bor', queuePosition: 2);

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

        // Admin records NETTO defect = 5
        $nettoExec->update([
            'defect_qty' => 5,
            'good_qty' => 35,
            'defect_entered_at' => now(),
        ]);

        $freshLine = $line->fresh();
        $this->assertEquals('bor', $freshLine->current_stage);
        $this->assertEquals(2, $freshLine->queue_position);

        $card = $this->queryService->resolveKanbanCard($freshLine);
        $this->assertEquals(2, $card['queue_position']);
        $this->assertEquals('bor', $card['current_stage']);
        $this->assertEquals(35, $card['qty']);
    }

    /**
     * KANBAN TEST 6: Multiple KTR isolation on Kanban board.
     */
    public function test_multiple_ktr_isolation_on_kanban_board(): void
    {
        $lineA = $this->createKtrLine(qtyGood: 40, currentStage: 'bor');
        $lineB = $this->createKtrLine(qtyGood: 50, currentStage: 'bor');

        // Line A has Netto defect 5
        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $lineA->id,
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

        // Line B has no defects
        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $lineB->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 50,
            'defect_qty' => 0,
            'good_qty' => 50,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => now(),
            'executed_at' => now(),
        ]);

        $kanbanData = $this->queryService->getStageKanbanData('bor');
        $cards = collect($kanbanData['ready']);

        $cardA = $cards->firstWhere('id', $lineA->id);
        $cardB = $cards->firstWhere('id', $lineB->id);

        $this->assertNotNull($cardA);
        $this->assertNotNull($cardB);
        $this->assertEquals(35, $cardA['qty']);
        $this->assertEquals(50, $cardB['qty']);
        $this->assertEquals(85, $kanbanData['summary']['ready_qty']);
    }

    /**
     * KANBAN TEST 7: Target stage defect does not reduce its own input quantity, but reduces downstream input.
     */
    public function test_target_stage_defect_does_not_reduce_its_own_input_quantity(): void
    {
        $line = $this->createKtrLine(qtyGood: 40, currentStage: 'qc');

        // Stage execution on BOR with 3 defects
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

        $line->load('stageExecutions');

        // Effective Input for BOR must still be 40 (BOR's own defect does not reduce its input)
        $this->assertEquals(40, (new \App\Services\SandCasting\SandCastingQuantityResolverService)->resolveEffectiveInputQty($line, 'bor'));

        // Effective Good for BOR must be 37
        $this->assertEquals(37, (new \App\Services\SandCasting\SandCastingQuantityResolverService)->resolveEffectiveGoodQty($line, 'bor'));

        // Ready card for QC (the stage receiving BOR output) must show input 37
        $cardQc = $this->queryService->resolveKanbanCard($line);
        $this->assertNotNull($cardQc);
        $this->assertEquals(37, $cardQc['qty']);
        $this->assertEquals(37, $cardQc['input_qty']);
        $this->assertEquals(37, $cardQc['good_qty']);
    }
}
