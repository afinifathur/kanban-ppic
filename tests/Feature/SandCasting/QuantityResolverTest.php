<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use App\Services\SandCasting\SandCastingQuantityResolverService;
use App\Services\SandCasting\SandCastingStageExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuantityResolverTest extends TestCase
{
    use RefreshDatabase;

    protected SandCastingQuantityResolverService $resolverService;

    protected SandCastingStageExecutionService $executionService;

    protected User $operatorUser;

    protected User $adminPpic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolverService = new SandCastingQuantityResolverService;
        $this->executionService = new SandCastingStageExecutionService;

        $this->operatorUser = User::factory()->create([
            'name' => 'Operator Bubut',
            'email' => 'operator@peroniks.com',
        ]);

        $this->adminPpic = User::factory()->create([
            'name' => 'Admin PPIC Flange',
            'email' => 'adminppicfl@peroniks.com',
        ]);
    }

    /**
     * Helper to create a standard casting result line with default 40 good.
     */
    protected function createKtrLine(int $qtyGood = 40, int $qtyReject = 0, ?string $currentStage = 'netto'): SandCastingCastingResultLine
    {
        $plan = ProductionPlan::create([
            'code' => 'TEST-PLAN-'.uniqid(),
            'po_number' => 'PO-'.uniqid(),
            'item_code' => 'ITM-001',
            'item_name' => 'Flange 2 Inch',
            'customer' => 'PT Test Customer',
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
        ]);

        $order = \App\Models\SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-'.uniqid().'-'.rand(1000, 9999),
            'scheduled_date' => now()->toDateString(),
            'status' => 'ISSUED',
            'notes' => 'Test order',
            'created_by' => $this->adminPpic->id,
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
            'recorded_by' => $this->adminPpic->id,
        ]);

        return SandCastingCastingResultLine::create([
            'sand_casting_casting_result_id' => $result->id,
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-'.uniqid(),
            'qty_good' => $qtyGood,
            'qty_reject' => $qtyReject,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => $qtyGood * 2.50,
            'current_stage' => $currentStage,
        ]);
    }

    /**
     * 1. No defect -> Effective Input is 40 across all stages.
     */
    public function test_no_defect_returns_full_root_quantity_across_all_stages(): void
    {
        $line = $this->createKtrLine(qtyGood: 40);

        foreach (SandCastingQuantityResolverService::PIPELINE_STAGES as $stage) {
            $this->assertEquals(40, $this->resolverService->resolveEffectiveInputQty($line, $stage), "Input for {$stage} should be 40");
            $this->assertEquals(40, $this->resolverService->resolveEffectiveGoodQty($line, $stage), "Good for {$stage} should be 40");
        }
    }

    /**
     * 2. NETTO defect 5 -> NETTO Good = 35, Downstream Input (OD, CNC, BOR, QC, GD) = 35.
     */
    public function test_netto_defect_5_propagates_to_downstream_input_35(): void
    {
        $line = $this->createKtrLine(qtyGood: 40);

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 5,
            'good_qty' => 35,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => now(),
            'executed_at' => now(),
        ]);

        // NETTO Input must still be 40 (its own defect does not reduce its input)
        $this->assertEquals(40, $this->resolverService->resolveEffectiveInputQty($line, 'netto'));
        // NETTO Good must be 35
        $this->assertEquals(35, $this->resolverService->resolveEffectiveGoodQty($line, 'netto'));

        // All downstream stages must have Effective Input = 35
        $this->assertEquals(35, $this->resolverService->resolveEffectiveInputQty($line, 'bubut_od'));
        $this->assertEquals(35, $this->resolverService->resolveEffectiveInputQty($line, 'bubut_cnc'));
        $this->assertEquals(35, $this->resolverService->resolveEffectiveInputQty($line, 'bor'));
        $this->assertEquals(35, $this->resolverService->resolveEffectiveInputQty($line, 'qc'));
        $this->assertEquals(35, $this->resolverService->resolveEffectiveInputQty($line, 'gudang_jadi'));
    }

    /**
     * 3. NETTO 5 + CNC 2 -> BOR available = 33 (Flow 40 -> 35 -> 33).
     */
    public function test_netto_5_plus_cnc_2_results_in_bor_available_33(): void
    {
        $line = $this->createKtrLine(qtyGood: 40);

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 5,
            'good_qty' => 35,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => now()->subHours(4),
            'executed_at' => now()->subHours(4),
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
            'physical_done_at' => now()->subHours(3),
            'executed_at' => now()->subHours(3),
        ]);

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 35,
            'defect_qty' => 2,
            'good_qty' => 33,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => now()->subHours(2),
            'executed_at' => now()->subHours(2),
        ]);

        // Netto: Input 40, Good 35
        $this->assertEquals(40, $this->resolverService->resolveEffectiveInputQty($line, 'netto'));
        $this->assertEquals(35, $this->resolverService->resolveEffectiveGoodQty($line, 'netto'));

        // OD: Input 35, Good 35
        $this->assertEquals(35, $this->resolverService->resolveEffectiveInputQty($line, 'bubut_od'));
        $this->assertEquals(35, $this->resolverService->resolveEffectiveGoodQty($line, 'bubut_od'));

        // CNC: Input 35, Good 33
        $this->assertEquals(35, $this->resolverService->resolveEffectiveInputQty($line, 'bubut_cnc'));
        $this->assertEquals(33, $this->resolverService->resolveEffectiveGoodQty($line, 'bubut_cnc'));

        // BOR: Input 33
        $this->assertEquals(33, $this->resolverService->resolveEffectiveInputQty($line, 'bor'));
    }

    /**
     * 4. Multi-stage cumulative: NETTO 5 + OD 2 + CNC 3 -> BOR Input = 30.
     */
    public function test_multi_stage_defects_without_double_counting(): void
    {
        $line = $this->createKtrLine(qtyGood: 40);

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
            'stage' => 'bubut_od',
            'checkpoint_code' => 'OD_TURNING',
            'input_qty' => 35,
            'defect_qty' => 2,
            'good_qty' => 33,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => now(),
            'executed_at' => now(),
        ]);

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 33,
            'defect_qty' => 3,
            'good_qty' => 30,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => now(),
            'executed_at' => now(),
        ]);

        // BOR Input = 40 - (5 + 2 + 3) = 30
        $this->assertEquals(30, $this->resolverService->resolveEffectiveInputQty($line, 'bor'));
        $this->assertEquals(30, $this->resolverService->resolveEffectiveGoodQty($line, 'bor'));
    }

    /**
     * 5 & 6. Target stage defect does not reduce its own input, but reduces its good output.
     */
    public function test_target_stage_defect_does_not_reduce_its_own_input(): void
    {
        $line = $this->createKtrLine(qtyGood: 40);

        // BOR has a defect of 3
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

        // BOR Input remains 40
        $this->assertEquals(40, $this->resolverService->resolveEffectiveInputQty($line, 'bor'));

        // BOR Good is 40 - 3 = 37
        $this->assertEquals(37, $this->resolverService->resolveEffectiveGoodQty($line, 'bor'));

        // QC Input is 37
        $this->assertEquals(37, $this->resolverService->resolveEffectiveInputQty($line, 'qc'));
    }

    /**
     * 7, 8, 9, 10. Late defect after downstream physical execution does not mutate physical history or current stage.
     */
    public function test_late_defect_reconciles_operational_quantity_without_mutating_physical_history(): void
    {
        $line = $this->createKtrLine(qtyGood: 40, currentStage: 'bor');

        $timeDay1 = now()->subDays(2);

        // Day 1: Physical executions happen quickly with provisional 40 pcs
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
            'defect_entered_by' => $this->adminPpic->id,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
        ]);

        // Reload line with stage executions
        $line->load('stageExecutions');

        // Verify resolver outputs:
        $this->assertEquals(35, $this->resolverService->resolveEffectiveInputQty($line, 'bor'));
        $this->assertEquals(35, $this->resolverService->resolveEffectiveGoodQty($line, 'bor'));

        // Verify Database immutability (Physical history is preserved)
        $freshOd = $odExec->fresh();
        $this->assertEquals(40, $freshOd->input_qty, 'Historical OD input_qty must remain 40');
        $this->assertEquals(40, $freshOd->good_qty, 'Historical OD good_qty must remain 40');
        $this->assertEquals($timeDay1->toDateTimeString(), $freshOd->physical_done_at->toDateTimeString());

        $freshCnc = $cncExec->fresh();
        $this->assertEquals(40, $freshCnc->input_qty, 'Historical CNC input_qty must remain 40');
        $this->assertEquals(40, $freshCnc->good_qty, 'Historical CNC good_qty must remain 40');
        $this->assertEquals($timeDay1->toDateTimeString(), $freshCnc->physical_done_at->toDateTimeString());

        // Verify current_stage remains 'bor'
        $this->assertEquals('bor', $line->fresh()->current_stage);
    }

    /**
     * 11. Defect log audit entries are not double counted.
     */
    public function test_cumulative_defect_qty_is_authoritative_and_defect_logs_are_not_double_counted(): void
    {
        $line = $this->createKtrLine(qtyGood: 40);

        $exec = SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 5, // Cumulative total is 5
            'good_qty' => 35,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => now(),
            'executed_at' => now(),
        ]);

        // Create 2 defect log entries (initial 3 + additional 2 = 5)
        $exec->defectLogs()->create([
            'added_qty' => 3,
            'previous_total' => 0,
            'new_total' => 3,
            'user_id' => $this->adminPpic->id,
        ]);

        $exec->defectLogs()->create([
            'added_qty' => 2,
            'previous_total' => 3,
            'new_total' => 5,
            'user_id' => $this->adminPpic->id,
        ]);

        $line->load('stageExecutions');

        // Resolver must deduct exactly 5 (the cumulative defect_qty), NOT (5 + 3 + 2 = 10)
        $this->assertEquals(35, $this->resolverService->resolveEffectiveInputQty($line, 'bubut_od'));
    }

    /**
     * 12. Multiple KTR lines are strictly isolated and do not pool defects.
     */
    public function test_multiple_ktr_lines_are_strictly_isolated(): void
    {
        $line1 = $this->createKtrLine(qtyGood: 40);
        $line2 = $this->createKtrLine(qtyGood: 50);

        // Line 1 has 10 defects at Netto
        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line1->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 10,
            'good_qty' => 30,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => now(),
            'executed_at' => now(),
        ]);

        // Line 2 has 0 defects at Netto
        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line2->id,
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

        $this->assertEquals(30, $this->resolverService->resolveEffectiveInputQty($line1, 'bubut_od'));
        $this->assertEquals(50, $this->resolverService->resolveEffectiveInputQty($line2, 'bubut_od'));
    }

    /**
     * 13. Effective quantity is clamped at 0 when total defect exceeds root good.
     */
    public function test_effective_quantity_never_becomes_negative(): void
    {
        $line = $this->createKtrLine(qtyGood: 40);

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 45, // Total defect > root good (anomaly)
            'good_qty' => 0,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => now(),
            'executed_at' => now(),
        ]);

        $this->assertEquals(0, $this->resolverService->resolveEffectiveInputQty($line, 'bubut_od'));
        $this->assertEquals(0, $this->resolverService->resolveEffectiveGoodQty($line, 'bubut_od'));
    }

    /**
     * 14. Missing upstream executions does not incorrectly zero quantity.
     */
    public function test_missing_upstream_execution_falls_back_to_root_good_minus_recorded_defects(): void
    {
        $line = $this->createKtrLine(qtyGood: 40);

        // Only CNC has an execution record with 2 defects (Netto and OD executions not yet recorded in DB)
        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 40,
            'defect_qty' => 2,
            'good_qty' => 38,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->operatorUser->id,
            'physical_done_at' => now(),
            'executed_at' => now(),
        ]);

        // Netto & OD input is 40
        $this->assertEquals(40, $this->resolverService->resolveEffectiveInputQty($line, 'netto'));
        $this->assertEquals(40, $this->resolverService->resolveEffectiveInputQty($line, 'bubut_od'));
        $this->assertEquals(40, $this->resolverService->resolveEffectiveInputQty($line, 'bubut_cnc'));

        // BOR Input is 40 - 2 = 38
        $this->assertEquals(38, $this->resolverService->resolveEffectiveInputQty($line, 'bor'));
    }

    /**
     * 15. Stage normalization handles slug formats (e.g. 'bubut-od', 'bubut-cnc', 'gudang-jadi').
     */
    public function test_stage_name_normalization_handles_slugs_and_variants(): void
    {
        $line = $this->createKtrLine(qtyGood: 40);

        $this->assertEquals(40, $this->resolverService->resolveEffectiveInputQty($line, 'bubut-od'));
        $this->assertEquals(40, $this->resolverService->resolveEffectiveInputQty($line, 'bubut-cnc'));
        $this->assertEquals(40, $this->resolverService->resolveEffectiveInputQty($line, 'gudang-jadi'));
        $this->assertEquals(40, $this->resolverService->resolveEffectiveInputQty($line, 'hasil_cor'));
    }

    /**
     * 16. Invalid stage throws InvalidArgumentException.
     */
    public function test_invalid_stage_throws_invalid_argument_exception(): void
    {
        $line = $this->createKtrLine(qtyGood: 40);

        $this->expectException(\InvalidArgumentException::class);
        $this->resolverService->resolveEffectiveInputQty($line, 'invalid_stage_xyz');
    }
}
