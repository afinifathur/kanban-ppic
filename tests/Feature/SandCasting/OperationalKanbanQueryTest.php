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
use App\Services\SandCasting\SandCastingStageExecutionService;
use App\Services\SandCasting\TravelerNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OperationalKanbanQueryTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $spvNetto;

    protected User $spvBubutOd;

    protected User $spvBubutCnc;

    protected User $spvBor;

    protected User $qcInspector;

    protected SandCastingStageExecutionService $executionService;

    protected SandCastingProductionFloorQueryService $queryService;

    protected DefectType $defectPinhole;

    protected function setUp(): void
    {
        parent::setUp();

        $spvRole = Role::firstOrCreate(['name' => 'spv']);

        $this->admin = User::create([
            'name' => 'Admin PPIC',
            'email' => 'admin_sc_kanban@peroniks.com',
            'password' => bcrypt('password'),
        ]);

        $this->spvNetto = User::create([
            'name' => 'SPV Netto',
            'email' => 'spv_netto_kanban@peroniks.com',
            'password' => bcrypt('password'),
            'assigned_stage' => 'netto',
        ]);
        $this->spvNetto->assignRole('spv');

        $this->spvBubutOd = User::create([
            'name' => 'SPV Bubut OD',
            'email' => 'spv_od_kanban@peroniks.com',
            'password' => bcrypt('password'),
            'assigned_stage' => 'bubut_od',
        ]);
        $this->spvBubutOd->assignRole('spv');

        $this->spvBubutCnc = User::create([
            'name' => 'SPV Bubut CNC',
            'email' => 'spv_cnc_kanban@peroniks.com',
            'password' => bcrypt('password'),
            'assigned_stage' => 'bubut_cnc',
        ]);
        $this->spvBubutCnc->assignRole('spv');

        $this->spvBor = User::create([
            'name' => 'SPV Bor',
            'email' => 'spv_bor_kanban@peroniks.com',
            'password' => bcrypt('password'),
            'assigned_stage' => 'bor',
        ]);
        $this->spvBor->assignRole('spv');

        $this->qcInspector = User::create([
            'name' => 'QC Inspector',
            'email' => 'qc_inspector_kanban@peroniks.com',
            'password' => bcrypt('password'),
        ]);

        $this->defectPinhole = DefectType::create([
            'department' => 'netto',
            'name' => 'Pinhole Porosity',
            'is_active' => true,
        ]);

        $this->executionService = new SandCastingStageExecutionService;
        $this->queryService = new SandCastingProductionFloorQueryService($this->executionService);
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
            'created_by' => $this->admin->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '2"',
            'aisi' => 'FC250',
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'H'.rand(1000, 9999),
            'cast_date' => '2026-09-20',
            'shift' => 1,
            'furnace' => 'F1',
            'recorded_by' => $this->admin->id,
        ]);

        $lineAttributes = array_merge([
            'sand_casting_casting_result_id' => $result->id,
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'product_scope' => 'FLANGE_BESI',
            'item_code' => '4.101',
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'code' => $plan->code,
            'heat_number' => $result->heat_number,
            'qty_good' => 100,
            'qty_reject' => 0,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 250.00,
            'current_stage' => 'netto',
            'is_urgent' => false,
        ], $attributes);

        if (! isset($lineAttributes['traveler_number'])) {
            $lineAttributes['traveler_number'] = TravelerNumberGenerator::generateNext('2026-09-20');
        }

        return SandCastingCastingResultLine::create($lineAttributes);
    }

    /**
     * TEST MANDATORI A:
     * NETTO physical DONE, Admin defect pending -> OD Kanban = READY
     */
    public function test_mandatory_a_netto_physical_done_admin_defect_pending_od_kanban_ready(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'netto']);

        // SPV Netto marks physical work done
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);

        $odData = $this->queryService->getStageKanbanData('bubut_od');

        $this->assertEquals(1, $odData['summary']['ready_count']);
        $this->assertEquals(0, $odData['summary']['incoming_count']);
        $this->assertEquals(50, $odData['summary']['ready_qty']);
        $this->assertEquals($ktr->traveler_number, $odData['ready'][0]['traveler_number']);
        $this->assertEquals('ready', $odData['ready'][0]['display_bucket']);
        $this->assertEquals('READY', $odData['ready'][0]['display_status']);
        $this->assertEquals('OD_TURNING', $odData['ready'][0]['active_checkpoint']);
    }

    /**
     * TEST MANDATORI B:
     * OD physical DONE, Admin defect pending -> CNC Kanban = READY
     */
    public function test_mandatory_b_od_physical_done_admin_defect_pending_cnc_kanban_ready(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 45, 'current_stage' => 'netto']);

        // Netto physical done
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);

        // Bubut OD physical done
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->spvBubutOd->id);

        $cncData = $this->queryService->getStageKanbanData('bubut_cnc');

        $this->assertEquals(1, $cncData['summary']['ready_count']);
        $this->assertEquals(0, $cncData['summary']['incoming_count']);
        $this->assertEquals(45, $cncData['summary']['ready_qty']);
        $this->assertEquals($ktr->traveler_number, $cncData['ready'][0]['traveler_number']);
        $this->assertEquals('ready', $cncData['ready'][0]['display_bucket']);
        $this->assertEquals('READY', $cncData['ready'][0]['display_status']);
        $this->assertEquals('CNC_MACHINING', $cncData['ready'][0]['active_checkpoint']);
    }

    /**
     * TEST MANDATORI C:
     * CNC_MACHINING physical DONE, Admin defect pending -> QC_POST_CNC READY
     */
    public function test_mandatory_c_cnc_machining_physical_done_admin_defect_pending_qc_post_cnc_ready(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 30, 'current_stage' => 'netto']);

        $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->spvBubutOd->id);

        // CNC Machining physical done
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_cnc', $this->spvBubutCnc->id);

        $cncData = $this->queryService->getStageKanbanData('bubut_cnc');

        $this->assertEquals(1, $cncData['summary']['ready_count']);
        $this->assertEquals('QC_POST_CNC', $cncData['ready'][0]['active_checkpoint']);
        $this->assertEquals('ready', $cncData['ready'][0]['display_bucket']);
        $this->assertEquals('READY', $cncData['ready'][0]['display_status']);
    }

    /**
     * TEST MANDATORI D:
     * QC_POST_CNC physical DONE, Admin defect pending -> QC_PRE_BOR READY
     */
    public function test_mandatory_d_qc_post_cnc_physical_done_admin_defect_pending_qc_pre_bor_ready(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 30, 'current_stage' => 'netto']);

        $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->spvBubutOd->id);
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_cnc', $this->spvBubutCnc->id);

        // QC Post CNC physical done
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_cnc', $this->qcInspector->id);

        $cncData = $this->queryService->getStageKanbanData('bubut_cnc');

        $this->assertEquals(1, $cncData['summary']['ready_count']);
        $this->assertEquals('QC_PRE_BOR', $cncData['ready'][0]['active_checkpoint']);
        $this->assertEquals('ready', $cncData['ready'][0]['display_bucket']);
        $this->assertEquals('READY', $cncData['ready'][0]['display_status']);
    }

    /**
     * TEST MANDATORI E:
     * QC_PRE_BOR physical DONE, Admin defect pending -> BOR READY
     */
    public function test_mandatory_e_qc_pre_bor_physical_done_admin_defect_pending_bor_ready(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 30, 'current_stage' => 'netto']);

        $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->spvBubutOd->id);
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_cnc', $this->spvBubutCnc->id);
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_cnc', $this->qcInspector->id);

        // QC Pre Bor physical done -> stage advances to bor
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_cnc', $this->qcInspector->id);

        $borData = $this->queryService->getStageKanbanData('bor');

        $this->assertEquals(1, $borData['summary']['ready_count']);
        $this->assertEquals(0, $borData['summary']['incoming_count']);
        $this->assertEquals('BOR_DRILLING', $borData['ready'][0]['active_checkpoint']);
        $this->assertEquals('ready', $borData['ready'][0]['display_bucket']);
        $this->assertEquals('READY', $borData['ready'][0]['display_status']);
    }

    /**
     * TEST MANDATORI F:
     * Previous stage belum physical DONE -> downstream tetap INCOMING
     */
    public function test_mandatory_f_previous_stage_not_physical_done_downstream_remains_incoming(): void
    {
        // KTR is in Netto and NOT yet physical done
        $ktr = $this->createKtrLine(['qty_good' => 70, 'current_stage' => 'netto']);

        $nettoData = $this->queryService->getStageKanbanData('netto');
        $odData = $this->queryService->getStageKanbanData('bubut_od');

        // Netto sees it as READY
        $this->assertEquals(1, $nettoData['summary']['ready_count']);
        $this->assertEquals($ktr->traveler_number, $nettoData['ready'][0]['traveler_number']);

        // OD sees it as INCOMING
        $this->assertEquals(0, $odData['summary']['ready_count']);
        $this->assertEquals(1, $odData['summary']['incoming_count']);
        $this->assertEquals(70, $odData['summary']['incoming_qty']);
        $this->assertEquals($ktr->traveler_number, $odData['incoming'][0]['traveler_number']);
        $this->assertEquals('incoming', $odData['incoming'][0]['display_bucket']);
        $this->assertEquals('INCOMING', $odData['incoming'][0]['display_status']);
    }

    /**
     * TEST MANDATORI G:
     * WAITING_DEFECT tidak membuat card menjadi INCOMING di stage aktifnya
     */
    public function test_mandatory_g_waiting_defect_does_not_make_card_incoming(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 80, 'current_stage' => 'netto']);

        // Netto physical done -> current_stage = bubut_od, Netto exec status = WAITING_DEFECT
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);

        $odData = $this->queryService->getStageKanbanData('bubut_od');

        $this->assertEquals(1, $odData['summary']['ready_count']);
        $this->assertEquals(0, $odData['summary']['incoming_count']);
        $this->assertEquals('ready', $odData['ready'][0]['display_bucket']);
        $this->assertEquals('READY', $odData['ready'][0]['display_status']);
    }

    /**
     * TEST MANDATORI H:
     * WAITING_QC tidak membuat card menjadi INCOMING di stage aktifnya
     */
    public function test_mandatory_h_waiting_qc_does_not_make_card_incoming(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 80, 'current_stage' => 'netto']);

        // Netto physical done -> current_stage = bubut_od
        $exec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);

        // PPIC records defect -> Netto exec status = WAITING_QC
        $this->executionService->recordDefectQty($exec, 2, $this->admin->id);

        $odData = $this->queryService->getStageKanbanData('bubut_od');

        $this->assertEquals(1, $odData['summary']['ready_count']);
        $this->assertEquals(0, $odData['summary']['incoming_count']);
        $this->assertEquals('ready', $odData['ready'][0]['display_bucket']);
        $this->assertEquals('READY', $odData['ready'][0]['display_status']);
        $this->assertEquals(78, $odData['ready'][0]['qty']); // Provisional good qty is 78
    }

    /**
     * TEST MANDATORI I:
     * Administrative defect queue tetap FIFO
     */
    public function test_mandatory_i_administrative_defect_queue_remains_fifo(): void
    {
        $ktr1 = $this->createKtrLine(['qty_good' => 10, 'current_stage' => 'netto']);
        $ktr2 = $this->createKtrLine(['qty_good' => 20, 'current_stage' => 'netto']);

        $e1 = $this->executionService->markPhysicalDone($ktr1->traveler_number, 'netto', $this->spvNetto->id);
        $e2 = $this->executionService->markPhysicalDone($ktr2->traveler_number, 'netto', $this->spvNetto->id);

        // Manually adjust physical_done_at to test chronological FIFO
        $e1->update(['physical_done_at' => now()->subMinutes(10)]);
        $e2->update(['physical_done_at' => now()->subMinutes(5)]);

        $queue = $this->queryService->getDefectRecordingQueue('netto');

        $this->assertCount(2, $queue);
        $this->assertEquals($ktr1->traveler_number, $queue[0]['traveler_number']);
        $this->assertEquals($ktr2->traveler_number, $queue[1]['traveler_number']);
    }

    /**
     * TEST MANDATORI J:
     * Administrative QC queue tetap FIFO
     */
    public function test_mandatory_j_administrative_qc_queue_remains_fifo(): void
    {
        $ktr1 = $this->createKtrLine(['qty_good' => 10, 'current_stage' => 'netto']);
        $ktr2 = $this->createKtrLine(['qty_good' => 20, 'current_stage' => 'netto']);

        $e1 = $this->executionService->markPhysicalDone($ktr1->traveler_number, 'netto', $this->spvNetto->id);
        $e2 = $this->executionService->markPhysicalDone($ktr2->traveler_number, 'netto', $this->spvNetto->id);

        $e1 = $this->executionService->recordDefectQty($e1, 1, $this->admin->id);
        $e2 = $this->executionService->recordDefectQty($e2, 2, $this->admin->id);

        // Manually adjust defect_entered_at to test chronological FIFO
        $e1->update(['defect_entered_at' => now()->subMinutes(15)]);
        $e2->update(['defect_entered_at' => now()->subMinutes(5)]);

        $queue = $this->queryService->getQcVerificationQueue('netto');

        $this->assertCount(2, $queue);
        $this->assertEquals($ktr1->traveler_number, $queue[0]['traveler_number']);
        $this->assertEquals($ktr2->traveler_number, $queue[1]['traveler_number']);
    }

    /**
     * TEST MANDATORI K:
     * Urgent tetap mengalahkan FIFO normal sesuai existing semantics
     */
    public function test_mandatory_k_urgent_beats_normal_fifo(): void
    {
        $resEarly = SandCastingCastingResult::create([
            'heat_number' => 'H101',
            'cast_date' => '2026-09-10',
            'shift' => 1,
            'furnace' => 'F1',
            'recorded_by' => $this->admin->id,
        ]);

        $resLate = SandCastingCastingResult::create([
            'heat_number' => 'H102',
            'cast_date' => '2026-09-18',
            'shift' => 1,
            'furnace' => 'F1',
            'recorded_by' => $this->admin->id,
        ]);

        $cNormalOld = $this->createKtrLine([
            'sand_casting_casting_result_id' => $resEarly->id,
            'heat_number' => 'H101',
            'is_urgent' => false,
            'current_stage' => 'netto',
        ]);

        $cUrgentNew = $this->createKtrLine([
            'sand_casting_casting_result_id' => $resLate->id,
            'heat_number' => 'H102',
            'is_urgent' => true,
            'current_stage' => 'netto',
        ]);

        $data = $this->queryService->getStageKanbanData('netto');
        $ready = $data['ready'];

        $this->assertCount(2, $ready);
        $this->assertEquals($cUrgentNew->traveler_number, $ready[0]['traveler_number'], 'Urgent must be ahead of older non-urgent');
        $this->assertEquals($cNormalOld->traveler_number, $ready[1]['traveler_number']);
    }

    /**
     * TEST MANDATORI L:
     * Manual queue_position tetap mengalahkan Urgent sesuai existing semantics
     */
    public function test_mandatory_l_manual_queue_position_beats_urgent(): void
    {
        $cUrgentNoPos = $this->createKtrLine([
            'is_urgent' => true,
            'queue_position' => null,
            'current_stage' => 'netto',
        ]);

        $cNormalWithPos = $this->createKtrLine([
            'is_urgent' => false,
            'queue_position' => 1,
            'current_stage' => 'netto',
        ]);

        $data = $this->queryService->getStageKanbanData('netto');
        $ready = $data['ready'];

        $this->assertCount(2, $ready);
        $this->assertEquals($cNormalWithPos->traveler_number, $ready[0]['traveler_number'], 'Manual queue_position must beat urgent');
        $this->assertEquals($cUrgentNoPos->traveler_number, $ready[1]['traveler_number']);
    }

    /**
     * TEST MANDATORI M:
     * Historical KTR tetap dapat diproyeksikan (query history & findByTraveler)
     */
    public function test_mandatory_m_historical_ktr_projection(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 60, 'current_stage' => 'netto']);

        // Execute Netto -> OD -> CNC
        $e1 = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $this->executionService->recordDefectQty($e1, 2, $this->admin->id);
        $this->executionService->verifyQcBreakdown($e1, [
            ['defect_type_id' => $this->defectPinhole->id, 'qty' => 2],
        ], $this->qcInspector->id);

        $e2 = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->spvBubutOd->id);
        $this->executionService->recordDefectQty($e2, 0, $this->admin->id);
        $this->executionService->verifyQcBreakdown($e2, [], $this->qcInspector->id);

        // Verify traveler details and history projection
        $dto = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertNotNull($dto);
        $this->assertEquals('bubut_cnc', $dto['current_stage']);
        $this->assertCount(2, $dto['stage_history']);

        $history = $this->queryService->getStageHistory($ktr->traveler_number);
        $this->assertCount(2, $history);
        $this->assertEquals('netto', $history[0]['stage']);
        $this->assertEquals(2, $history[0]['defect_qty']);
        $this->assertEquals(58, $history[0]['good_qty']);
        $this->assertEquals('bubut_od', $history[1]['stage']);
        $this->assertEquals(0, $history[1]['defect_qty']);
        $this->assertEquals(58, $history[1]['good_qty']);
    }

    /**
     * TEST MANDATORI N:
     * HALTED behavior untuk good_qty = 0 yang memang sudah confirmed tetap dipertahankan
     */
    public function test_mandatory_n_halted_behavior_for_confirmed_zero_good_qty(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 25, 'current_stage' => 'netto']);

        $exec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $exec = $this->executionService->recordDefectQty($exec, 25, $this->admin->id);
        $this->executionService->verifyQcBreakdown($exec, [
            ['defect_type_id' => $this->defectPinhole->id, 'qty' => 25],
        ], $this->qcInspector->id);

        $odData = $this->queryService->getStageKanbanData('bubut_od');

        // Total scrap in Netto means it is HALTED on Bubut OD
        $this->assertEquals(0, $odData['summary']['ready_count']);
        $this->assertEquals(0, $odData['summary']['incoming_count']);
        $this->assertEquals(1, $odData['summary']['halted_count']);
        $this->assertEquals('halted', $odData['halted'][0]['display_bucket']);
        $this->assertEquals('HALTED', $odData['halted'][0]['display_status']);
        $this->assertEquals(0, $odData['halted'][0]['qty']);
    }

    /**
     * Additional Structural Tests for Operational Kanban Data Integrity.
     */
    public function test_empty_stage_returns_empty_structure(): void
    {
        $data = $this->queryService->getStageKanbanData('netto');

        $this->assertEquals('netto', $data['stage']);
        $this->assertEquals(0, $data['summary']['ready_count']);
        $this->assertEquals(0, $data['summary']['incoming_count']);
        $this->assertEquals(0, $data['summary']['halted_count']);
        $this->assertEquals(0, $data['summary']['total_count']);
        $this->assertEquals(0, $data['summary']['ready_qty']);
        $this->assertIsArray($data['ready']);
        $this->assertEmpty($data['ready']);
        $this->assertIsArray($data['incoming']);
        $this->assertEmpty($data['incoming']);
        $this->assertIsArray($data['halted']);
        $this->assertEmpty($data['halted']);
    }

    public function test_line_grouping_and_size_fallback(): void
    {
        $this->assertEquals(1, SandCastingProductionFloorQueryService::resolveLineNumber(1, '2"'));
        $this->assertEquals(2, SandCastingProductionFloorQueryService::resolveLineNumber(2, '4"'));
        $this->assertEquals(3, SandCastingProductionFloorQueryService::resolveLineNumber(3, '8"'));
        $this->assertEquals(4, SandCastingProductionFloorQueryService::resolveLineNumber(4, '14"'));

        $this->assertEquals(1, SandCastingProductionFloorQueryService::resolveLineNumber(null, '1/2"'));
        $this->assertEquals(1, SandCastingProductionFloorQueryService::resolveLineNumber(null, '2"'));
        $this->assertEquals(2, SandCastingProductionFloorQueryService::resolveLineNumber(null, '2-1/2"'));
        $this->assertEquals(2, SandCastingProductionFloorQueryService::resolveLineNumber(null, '4"'));
        $this->assertEquals(3, SandCastingProductionFloorQueryService::resolveLineNumber(null, '6"'));
        $this->assertEquals(3, SandCastingProductionFloorQueryService::resolveLineNumber(null, '10"'));
        $this->assertEquals(4, SandCastingProductionFloorQueryService::resolveLineNumber(null, '14"'));
        $this->assertEquals(4, SandCastingProductionFloorQueryService::resolveLineNumber(null, 'DN 350'));
    }

    public function test_dynamic_aging_calculation(): void
    {
        $result = SandCastingCastingResult::create([
            'heat_number' => 'H-AGING-TEST',
            'cast_date' => now()->subDays(5)->format('Y-m-d'),
            'shift' => 1,
            'furnace' => 'F1',
            'recorded_by' => $this->admin->id,
        ]);

        $ktr = $this->createKtrLine([
            'sand_casting_casting_result_id' => $result->id,
            'heat_number' => 'H-AGING-TEST',
            'current_stage' => 'netto',
        ]);

        $card = $this->queryService->resolveKanbanCard($ktr);

        $this->assertNotNull($card);
        $this->assertGreaterThanOrEqual(5, $card['aging']['total_aging_days']);
        $this->assertStringContainsString('h', $card['aging']['total_aging_label']);
    }

    public function test_ktr_identity_fields_contract(): void
    {
        $ktr = $this->createKtrLine([
            'qty_good' => 75,
            'unit_weight_kg' => 4.00,
            'total_weight_kg' => 300.00,
            'current_stage' => 'netto',
        ]);

        $data = $this->queryService->getStageKanbanData('netto');
        $card = $data['ready'][0];

        $this->assertArrayHasKey('traveler_number', $card);
        $this->assertArrayHasKey('heat_number', $card);
        $this->assertArrayHasKey('production_code', $card);
        $this->assertArrayHasKey('item_code', $card);
        $this->assertArrayHasKey('item_name', $card);
        $this->assertArrayHasKey('customer', $card);
        $this->assertArrayHasKey('size', $card);
        $this->assertArrayHasKey('line_number', $card);
        $this->assertArrayHasKey('qty', $card);
        $this->assertArrayHasKey('input_qty', $card);
        $this->assertArrayHasKey('good_qty', $card);
        $this->assertArrayHasKey('defect_qty', $card);
        $this->assertArrayHasKey('unit_weight_kg', $card);
        $this->assertArrayHasKey('total_weight_kg', $card);
        $this->assertArrayHasKey('current_stage', $card);
        $this->assertArrayHasKey('display_stage', $card);
        $this->assertArrayHasKey('display_bucket', $card);
        $this->assertArrayHasKey('display_status', $card);
        $this->assertArrayHasKey('active_checkpoint', $card);
        $this->assertArrayHasKey('execution_status', $card);
        $this->assertArrayHasKey('is_urgent', $card);
        $this->assertArrayHasKey('cast_date', $card);
        $this->assertArrayHasKey('aging', $card);
    }

    public function test_multiple_ktrs_same_heat_different_items_independence(): void
    {
        $result = SandCastingCastingResult::create([
            'heat_number' => 'HEAT-SHARED-99',
            'cast_date' => '2026-09-20',
            'shift' => 1,
            'furnace' => 'F1',
            'recorded_by' => $this->admin->id,
        ]);

        $ktr1 = $this->createKtrLine([
            'sand_casting_casting_result_id' => $result->id,
            'heat_number' => 'HEAT-SHARED-99',
            'item_code' => '4.101',
            'item_name' => 'ITEM A 2"',
            'qty_good' => 50,
            'current_stage' => 'netto',
        ]);

        $ktr2 = $this->createKtrLine([
            'sand_casting_casting_result_id' => $result->id,
            'heat_number' => 'HEAT-SHARED-99',
            'item_code' => '4.102',
            'item_name' => 'ITEM B 4"',
            'qty_good' => 30,
            'current_stage' => 'netto',
        ]);

        // Process only KTR 1 to physical done -> KTR 1 enters bubut_od
        $this->executionService->markPhysicalDone($ktr1->traveler_number, 'netto', $this->spvNetto->id);

        $nettoData = $this->queryService->getStageKanbanData('netto');
        $odData = $this->queryService->getStageKanbanData('bubut_od');

        // Netto has KTR 2 in Ready
        $this->assertEquals(1, $nettoData['summary']['ready_count']);
        $this->assertEquals($ktr2->traveler_number, $nettoData['ready'][0]['traveler_number']);

        // OD has KTR 1 in Ready (since Netto is physically done)
        $this->assertEquals(1, $odData['summary']['ready_count']);
        $this->assertEquals($ktr1->traveler_number, $odData['ready'][0]['traveler_number']);

        // OD has KTR 2 in Incoming (since Netto is still processing KTR 2)
        $this->assertEquals(1, $odData['summary']['incoming_count']);
        $this->assertEquals($ktr2->traveler_number, $odData['incoming'][0]['traveler_number']);
    }

    public function test_historical_null_stage_ktr_is_excluded(): void
    {
        $this->createKtrLine(['current_stage' => null]);

        $nettoData = $this->queryService->getStageKanbanData('netto');
        $this->assertEquals(0, $nettoData['summary']['total_count']);
    }

    public function test_safety_kanban_query_is_strictly_read_only(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        $execCountBefore = SandCastingStageExecution::count();
        $lineCountBefore = SandCastingCastingResultLine::count();

        DB::listen(function ($query) {
            $sql = strtoupper($query->sql);
            $this->assertStringNotContainsString('INSERT', $sql, 'Kanban query must not execute INSERT');
            $this->assertStringNotContainsString('UPDATE', $sql, 'Kanban query must not execute UPDATE');
            $this->assertStringNotContainsString('DELETE', $sql, 'Kanban query must not execute DELETE');
            $this->assertStringNotContainsString('PRODUCTION_ITEMS', $sql, 'Kanban query must not touch production_items');
        });

        $data = $this->queryService->getStageKanbanData('netto');

        $this->assertNotEmpty($data);
        $this->assertEquals($execCountBefore, SandCastingStageExecution::count(), 'Executions count must not change');
        $this->assertEquals($lineCountBefore, SandCastingCastingResultLine::count(), 'Line count must not change');
        $this->assertEquals('netto', $ktr->fresh()->current_stage, 'current_stage must not change');
    }
}
