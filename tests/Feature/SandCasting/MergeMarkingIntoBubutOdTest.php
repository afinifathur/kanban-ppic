<?php

namespace Tests\Feature\SandCasting;

use App\Models\DefectType;
use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use App\Services\SandCasting\SandCastingProductionFloorQueryService;
use App\Services\SandCasting\SandCastingStageAuthorizationService;
use App\Services\SandCasting\SandCastingStageExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MergeMarkingIntoBubutOdTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $ppicUser;

    protected User $qcInspector;

    protected User $spvNetto;

    protected User $spvBubutOd;

    protected User $spvBubutCnc;

    protected SandCastingStageExecutionService $executionService;

    protected SandCastingStageAuthorizationService $authService;

    protected SandCastingProductionFloorQueryService $queryService;

    protected DefectType $defectPinhole;

    protected function setUp(): void
    {
        parent::setUp();

        $accessPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution']);

        $roleAdmin = Role::firstOrCreate(['name' => 'admin']);
        $roleAdmin->givePermissionTo([$accessPlanning, $accessExecution]);

        $rolePpic = Role::firstOrCreate(['name' => 'ppic']);
        $rolePpic->givePermissionTo([$accessPlanning, $accessExecution]);

        $roleSpv = Role::firstOrCreate(['name' => 'spv']);
        $roleSpv->givePermissionTo([$accessExecution]);

        $roleQcFitting = Role::firstOrCreate(['name' => 'admin_qc_fitting']);
        $roleQcFitting->givePermissionTo([$accessPlanning, $accessExecution]);

        $this->adminUser = User::factory()->create([
            'name' => 'Admin Sand Casting',
            'email' => 'admin_merge_test@peroniks.com',
        ]);
        $this->adminUser->assignRole('admin');

        $this->ppicUser = User::factory()->create([
            'name' => 'PPIC User',
            'email' => 'ppic_merge_test@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->ppicUser->assignRole('ppic');

        $this->qcInspector = User::factory()->create([
            'name' => 'QC Inspector',
            'email' => 'qc_merge_test@peroniks.com',
        ]);
        $this->qcInspector->assignRole('admin_qc_fitting');

        $this->spvNetto = User::factory()->create([
            'name' => 'SPV Netto',
            'email' => 'spv_netto_merge@peroniks.com',
            'assigned_stage' => 'netto',
        ]);
        $this->spvNetto->assignRole('spv');

        $this->spvBubutOd = User::factory()->create([
            'name' => 'SPV Bubut OD',
            'email' => 'spv_od_merge@peroniks.com',
            'assigned_stage' => 'bubut_od',
        ]);
        $this->spvBubutOd->assignRole('spv');

        $this->spvBubutCnc = User::factory()->create([
            'name' => 'SPV Bubut CNC',
            'email' => 'spv_cnc_merge@peroniks.com',
            'assigned_stage' => 'bubut_cnc',
        ]);
        $this->spvBubutCnc->assignRole('spv');

        $this->executionService = new SandCastingStageExecutionService;
        $this->authService = new SandCastingStageAuthorizationService;
        $this->queryService = new SandCastingProductionFloorQueryService;

        $this->defectPinhole = DefectType::firstOrCreate([
            'code' => 'PINHOLE_TEST',
        ], [
            'name' => 'Pinhole Defect Test',
            'department' => 'BUBUT',
            'is_active' => true,
        ]);
    }

    protected function createKtrLine(array $attributes = []): SandCastingCastingResultLine
    {
        $plan = ProductionPlan::create([
            'code' => '268ET'.rand(100, 999),
            'title' => 'Rencana SC '.rand(100, 999),
            'item_code' => '4.'.rand(100, 999),
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-'.rand(100, 999),
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'customer' => 'PT SINAR METAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260920-'.rand(1000, 9999),
            'scheduled_date' => '2026-09-20',
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
            'target_shape' => 'FLANGE',
            'line_number' => 1,
            'product_scope' => 'FLANGE_BESI',
        ]);

        $castResult = SandCastingCastingResult::create([
            'heat_number' => 'A2210926'.rand(10, 99),
            'cast_date' => '2026-09-20',
            'furnace' => 'F-01',
            'shift' => '1',
            'operator_name' => 'Sutrisno',
            'recorded_by' => $this->adminUser->id,
        ]);

        $ktrLine = $castResult->lines()->create(array_merge([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-20260920-'.rand(1000, 9999),
            'heat_number' => $castResult->heat_number,
            'line_code' => 'FLANGE_1',
            'item_code' => $plan->item_code,
            'item_name' => $plan->item_name,
            'qty_pcor' => 100,
            'qty_cor' => 100,
            'qty_good' => 100,
            'qty_reject' => 0,
            'current_stage' => 'netto',
            'queue_position' => null,
        ], $attributes));

        if ($ktrLine->current_stage === 'bubut_od') {
            $ktrLine->stageExecutions()->create([
                'stage' => 'netto',
                'checkpoint_code' => 'NETTO_CUT',
                'input_qty' => $ktrLine->qty_good,
                'defect_qty' => 0,
                'good_qty' => $ktrLine->qty_good,
                'status' => SandCastingStageExecution::STATUS_CONFIRMED,
                'operator_id' => $this->spvNetto->id,
                'executed_at' => now()->subHours(2),
            ]);
        }

        return $ktrLine;
    }

    /**
     * TEST 1: NETTO confirmed -> current_stage = bubut_od
     */
    public function test_1_netto_confirmed_sets_current_stage_to_bubut_od(): void
    {
        $line = $this->createKtrLine(['qty_good' => 80, 'current_stage' => 'netto']);

        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvNetto->id);
        $exec = $this->executionService->recordDefectQty($exec, 5, $this->ppicUser->id);
        $confirmed = $this->executionService->verifyQcBreakdown($exec, [
            ['defect_type_id' => $this->defectPinhole->id, 'qty' => 5],
        ], $this->qcInspector->id);

        $line->refresh();
        $this->assertEquals('bubut_od', $line->current_stage);
        $this->assertEquals(75, $confirmed->good_qty);

        $odKanban = $this->queryService->getStageKanbanData('bubut_od');
        $this->assertTrue(collect($odKanban['ready'])->contains('traveler_number', $line->traveler_number));
    }

    /**
     * TEST 2: OD scan -> execution checkpoint = OD_TURNING -> status = WAITING_DEFECT
     */
    public function test_2_od_scan_creates_od_turning_checkpoint_with_status_waiting_defect(): void
    {
        $line = $this->createKtrLine(['qty_good' => 75, 'current_stage' => 'bubut_od']);

        $response = $this->actingAs($this->spvBubutOd)->postJson('/sand-casting/scan/bubut-od/execute', [
            'traveler_number' => $line->traveler_number,
            'notes' => 'Bubut OD dan marking fisik selesai',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.checkpoint_code', 'OD_TURNING');
        $response->assertJsonPath('data.status', 'WAITING_DEFECT');
        $response->assertJsonPath('data.input_qty', 75);

        $this->assertDatabaseHas('sand_casting_stage_executions', [
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'bubut_od',
            'checkpoint_code' => 'OD_TURNING',
            'status' => 'WAITING_DEFECT',
        ]);
    }

    /**
     * TEST 3: OD tidak memiliki checkpoint MARKING_STAMP.
     */
    public function test_3_od_does_not_have_marking_stamp_checkpoint(): void
    {
        $checkpoints = SandCastingStageExecutionService::STAGE_CHECKPOINTS['bubut_od'] ?? [];
        $this->assertEquals(['OD_TURNING'], $checkpoints);
        $this->assertFalse(in_array('MARKING_STAMP', $checkpoints));
        $this->assertArrayNotHasKey('marking', SandCastingStageExecutionService::STAGE_CHECKPOINTS);
        $this->assertArrayNotHasKey('MARKING_STAMP', SandCastingStageExecutionService::CHECKPOINT_STAGE);
    }

    /**
     * TEST 4: OD defect workflow: WAITING_DEFECT -> PPIC total defect -> WAITING_QC -> QC breakdown -> CONFIRMED
     */
    public function test_4_od_defect_workflow(): void
    {
        $line = $this->createKtrLine(['qty_good' => 75, 'current_stage' => 'bubut_od']);

        // Step 1: Scan / Mark Physical Done
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_od', $this->spvBubutOd->id);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_DEFECT, $exec->status);

        // Step 2: PPIC enters total defect
        $exec = $this->executionService->recordDefectQty($exec, 3, $this->ppicUser->id);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $exec->status);
        $this->assertEquals(3, $exec->defect_qty);

        // Step 3: QC enters defect breakdown and confirms
        $exec = $this->executionService->verifyQcBreakdown($exec, [
            ['defect_type_id' => $this->defectPinhole->id, 'qty' => 3],
        ], $this->qcInspector->id);

        $this->assertEquals(SandCastingStageExecution::STATUS_CONFIRMED, $exec->status);
        $this->assertEquals(72, $exec->good_qty);
    }

    /**
     * TEST 5: OD confirmed dengan good_qty > 0 -> current_stage = bubut_cnc
     */
    public function test_5_od_confirmed_with_good_qty_gt_zero_advances_to_bubut_cnc(): void
    {
        $line = $this->createKtrLine(['qty_good' => 75, 'current_stage' => 'bubut_od']);

        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_od', $this->spvBubutOd->id);
        $exec = $this->executionService->recordDefectQty($exec, 3, $this->ppicUser->id);
        $confirmed = $this->executionService->verifyQcBreakdown($exec, [
            ['defect_type_id' => $this->defectPinhole->id, 'qty' => 3],
        ], $this->qcInspector->id);

        $line->refresh();
        $this->assertEquals('bubut_cnc', $line->current_stage);
        $this->assertEquals(72, $confirmed->good_qty);

        $cncKanban = $this->queryService->getStageKanbanData('bubut_cnc');
        $this->assertTrue(collect($cncKanban['ready'])->contains('traveler_number', $line->traveler_number));
    }

    /**
     * TEST 6: OD confirmed dengan good_qty == 0 -> tetap HALTED di Bubut OD.
     */
    public function test_6_od_confirmed_with_good_qty_zero_remains_halted_in_bubut_od(): void
    {
        $line = $this->createKtrLine(['qty_good' => 10, 'current_stage' => 'bubut_od']);

        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_od', $this->spvBubutOd->id);
        $exec = $this->executionService->recordDefectQty($exec, 10, $this->ppicUser->id);
        $confirmed = $this->executionService->verifyQcBreakdown($exec, [
            ['defect_type_id' => $this->defectPinhole->id, 'qty' => 10],
        ], $this->qcInspector->id);

        $line->refresh();
        $this->assertEquals('bubut_cnc', $line->current_stage);
        $this->assertEquals(0, $confirmed->good_qty);
        $this->assertEquals(10, $confirmed->defect_qty);

        $cncKanban = $this->queryService->getStageKanbanData('bubut_cnc');
        $this->assertTrue(collect($cncKanban['halted'])->contains('traveler_number', $line->traveler_number));
        $this->assertFalse(collect($cncKanban['ready'])->contains('traveler_number', $line->traveler_number));
    }

    /**
     * TEST 7: CNC mengambil input dari OD good_qty.
     */
    public function test_7_cnc_takes_input_qty_from_od_good_qty(): void
    {
        $line = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'bubut_od']);

        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_od', $this->spvBubutOd->id);
        $exec = $this->executionService->recordDefectQty($exec, 4, $this->ppicUser->id);
        $this->executionService->verifyQcBreakdown($exec, [
            ['defect_type_id' => $this->defectPinhole->id, 'qty' => 4],
        ], $this->qcInspector->id);

        $line->refresh();
        $this->assertEquals('bubut_cnc', $line->current_stage);

        // Next execution at CNC
        $execCnc = $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_cnc', $this->spvBubutCnc->id);
        $this->assertEquals(46, $execCnc->input_qty);
        $this->assertEquals('CNC_MACHINING', $execCnc->checkpoint_code);
    }

    /**
     * TEST 8: CNC tetap memiliki 3 checkpoints: CNC_MACHINING, QC_POST_CNC, QC_PRE_BOR
     */
    public function test_8_cnc_retains_all_three_checkpoints(): void
    {
        $cncCheckpoints = SandCastingStageExecutionService::STAGE_CHECKPOINTS['bubut_cnc'];
        $this->assertEquals(['CNC_MACHINING', 'QC_POST_CNC', 'QC_PRE_BOR'], $cncCheckpoints);

        $this->assertEquals('CNC_MACHINING', SandCastingStageExecutionService::NEXT_CHECKPOINT['OD_TURNING']);
        $this->assertEquals('QC_POST_CNC', SandCastingStageExecutionService::NEXT_CHECKPOINT['CNC_MACHINING']);
        $this->assertEquals('QC_PRE_BOR', SandCastingStageExecutionService::NEXT_CHECKPOINT['QC_POST_CNC']);
        $this->assertEquals('BOR_DRILLING', SandCastingStageExecutionService::NEXT_CHECKPOINT['QC_PRE_BOR']);
    }

    /**
     * TEST 9: Tidak ada operational stage 'marking'.
     */
    public function test_9_no_operational_stage_marking(): void
    {
        $canonicalStages = SandCastingStageExecutionService::STAGES;
        $this->assertEquals(['netto', 'bubut_od', 'bubut_cnc', 'bor', 'qc', 'gudang_jadi'], $canonicalStages);
        $this->assertNotContains('marking', $canonicalStages);

        $authStages = SandCastingStageAuthorizationService::VALID_STAGES;
        $this->assertEquals(['netto', 'bubut_od', 'bubut_cnc', 'bor', 'qc', 'gudang_jadi'], $authStages);
        $this->assertNotContains('marking', $authStages);
    }

    /**
     * TEST 10: Tidak ada scanner route operational untuk marking.
     */
    public function test_10_no_scanner_route_for_marking(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/sand-casting/scan/stage/marking');
        $response->assertNotFound();

        $responsePost = $this->actingAs($this->adminUser)->postJson('/sand-casting/scan/marking/execute', [
            'traveler_number' => 'KTR-TEST',
        ]);
        $responsePost->assertStatus(422);
        $responsePost->assertJsonPath('success', false);
        $this->assertStringContainsString('tidak valid', $responsePost->json('message'));
    }

    /**
     * TEST 11: SPV OD dapat mengakses bubut_od.
     */
    public function test_11_spv_od_can_access_bubut_od(): void
    {
        $this->assertTrue($this->authService->canAccessStage($this->spvBubutOd, 'bubut_od'));

        $response = $this->actingAs($this->spvBubutOd)->get('/sand-casting/scan/stage/bubut-od');
        $response->assertOk();

        $responseKanban = $this->actingAs($this->spvBubutOd)->get('/sand-casting/kanban/bubut-od');
        $responseKanban->assertOk();
    }

    /**
     * TEST 12: SPV OD tidak membutuhkan stage marking.
     */
    public function test_12_spv_od_does_not_need_marking_stage(): void
    {
        $this->assertEquals('bubut_od', $this->spvBubutOd->assigned_stage);
        $this->assertFalse($this->authService->canAccessStage($this->spvBubutOd, 'marking'));
    }

    /**
     * TEST 13: Kanban tidak menampilkan board MARKING.
     */
    public function test_13_kanban_does_not_display_marking_board(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/sand-casting/kanban/bubut-od');
        $response->assertOk();

        // Switcher tabs should not contain marking route
        $response->assertDontSee('/sand-casting/kanban/marking');

        // Direct request to marking kanban returns 404
        $responseMarking = $this->actingAs($this->adminUser)->get('/sand-casting/kanban/marking');
        $responseMarking->assertNotFound();
    }

    /**
     * TEST 14: Queue position OD di-reset ketika KTR berpindah ke CNC.
     */
    public function test_14_queue_position_reset_when_ktr_advances_to_cnc(): void
    {
        $line = $this->createKtrLine([
            'qty_good' => 60,
            'current_stage' => 'bubut_od',
            'queue_position' => 1,
        ]);

        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_od', $this->spvBubutOd->id);
        $exec = $this->executionService->recordDefectQty($exec, 0, $this->ppicUser->id);
        $this->executionService->verifyQcBreakdown($exec, [], $this->qcInspector->id);

        $line->refresh();
        $this->assertEquals('bubut_cnc', $line->current_stage);
        $this->assertNull($line->queue_position, 'Queue position must be reset to NULL upon advancing to CNC.');
    }

    /**
     * TEST 15: KITIR PRODUKSI tidak berubah dan tetap memiliki checklist MARKING fisik.
     */
    public function test_15_kitir_produksi_remains_unmodified_and_contains_marking_checklist(): void
    {
        $line = $this->createKtrLine(['qty_good' => 60, 'current_stage' => 'bubut_od']);

        $response = $this->actingAs($this->adminUser)->get('/sand-casting/casting-results/'.$line->sand_casting_casting_result_id.'/lines/'.$line->id.'/kitir');
        $response->assertOk();

        $content = $response->getContent();

        // Verify MARKING is still rendered on the physical traveler / Kitir checklist
        $this->assertStringContainsString('MARKING', $content);
        $this->assertStringContainsString('BUBUT OD', $content);
        $this->assertStringContainsString('BUBUT CNC', $content);
    }
}
