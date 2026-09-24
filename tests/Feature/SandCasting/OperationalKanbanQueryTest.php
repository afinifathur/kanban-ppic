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
     * Requirement A: Empty stage returns clean structure with 0 counts.
     */
    public function test_a_empty_stage_returns_empty_structure(): void
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

    /**
     * Requirement B: READY KTR appears in correct stage ready bucket.
     */
    public function test_b_ready_ktr_appears_in_correct_stage_ready_bucket(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 80, 'current_stage' => 'netto']);

        $nettoData = $this->queryService->getStageKanbanData('netto');
        $odData = $this->queryService->getStageKanbanData('bubut_od');

        $this->assertEquals(1, $nettoData['summary']['ready_count']);
        $this->assertEquals(80, $nettoData['summary']['ready_qty']);
        $this->assertEquals($ktr->traveler_number, $nettoData['ready'][0]['traveler_number']);
        $this->assertEquals('ready', $nettoData['ready'][0]['display_bucket']);
        $this->assertEquals('READY', $nettoData['ready'][0]['display_status']);

        // Must not appear in OD
        $this->assertEquals(0, $odData['summary']['total_count']);
    }

    /**
     * Requirement C & D: WAITING_DEFECT leaves origin active queue and appears in next stage INCOMING.
     */
    public function test_c_and_d_waiting_defect_leaves_origin_and_appears_in_next_stage_incoming(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        // SPV Netto marks physical done
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);

        $nettoData = $this->queryService->getStageKanbanData('netto');
        $odData = $this->queryService->getStageKanbanData('bubut_od');

        // Netto ready queue is now 0 (left origin active queue)
        $this->assertEquals(0, $nettoData['summary']['ready_count']);
        $this->assertEquals(0, $nettoData['summary']['total_count']);

        // OD incoming queue has 1 card in WAITING_DEFECT
        $this->assertEquals(0, $odData['summary']['ready_count']);
        $this->assertEquals(1, $odData['summary']['incoming_count']);
        $this->assertEquals(100, $odData['summary']['incoming_qty']);
        $this->assertEquals($ktr->traveler_number, $odData['incoming'][0]['traveler_number']);
        $this->assertEquals('incoming', $odData['incoming'][0]['display_bucket']);
        $this->assertEquals('WAITING_DEFECT', $odData['incoming'][0]['display_status']);
    }

    /**
     * Requirement E & F: WAITING_QC appears in next stage INCOMING and cannot appear READY.
     */
    public function test_e_and_f_waiting_qc_appears_in_next_stage_incoming_and_not_ready(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);
        $exec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $this->executionService->recordDefectQty($exec, 5, $this->admin->id);

        $odData = $this->queryService->getStageKanbanData('bubut_od');

        $this->assertEquals(0, $odData['summary']['ready_count']);
        $this->assertEquals(1, $odData['summary']['incoming_count']);
        $this->assertEquals(95, $odData['summary']['incoming_qty']); // Projected good qty 95
        $this->assertEquals($ktr->traveler_number, $odData['incoming'][0]['traveler_number']);
        $this->assertEquals('incoming', $odData['incoming'][0]['display_bucket']);
        $this->assertEquals('WAITING_QC', $odData['incoming'][0]['display_status']);
        $this->assertEquals(95, $odData['incoming'][0]['good_qty']);
        $this->assertEquals(5, $odData['incoming'][0]['defect_qty']);
    }

    /**
     * Requirement G: QC CONFIRMED + good > 0 appears READY in next stage.
     */
    public function test_g_qc_confirmed_with_good_qty_appears_ready_in_next_stage(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);
        $exec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $exec = $this->executionService->recordDefectQty($exec, 5, $this->admin->id);
        $this->executionService->verifyQcBreakdown($exec, [
            ['defect_type_id' => $this->defectPinhole->id, 'qty' => 5],
        ], $this->qcInspector->id);

        $ktr->refresh();
        $this->assertEquals('bubut_od', $ktr->current_stage);

        $odData = $this->queryService->getStageKanbanData('bubut_od');

        $this->assertEquals(1, $odData['summary']['ready_count']);
        $this->assertEquals(0, $odData['summary']['incoming_count']);
        $this->assertEquals(95, $odData['summary']['ready_qty']);
        $this->assertEquals($ktr->traveler_number, $odData['ready'][0]['traveler_number']);
        $this->assertEquals('ready', $odData['ready'][0]['display_bucket']);
        $this->assertEquals('READY', $odData['ready'][0]['display_status']);
        $this->assertEquals('OD_TURNING', $odData['ready'][0]['active_checkpoint']);
    }

    /**
     * Requirement H & I: good = 0 becomes HALTED and does not appear in next stage.
     */
    public function test_h_and_i_zero_good_qty_becomes_halted_and_not_in_next_stage(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'netto']);
        $exec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $exec = $this->executionService->recordDefectQty($exec, 50, $this->admin->id);
        $this->executionService->verifyQcBreakdown($exec, [
            ['defect_type_id' => $this->defectPinhole->id, 'qty' => 50],
        ], $this->qcInspector->id);

        $ktr->refresh();
        $this->assertEquals('netto', $ktr->current_stage); // Stage does not advance

        $nettoData = $this->queryService->getStageKanbanData('netto');
        $odData = $this->queryService->getStageKanbanData('bubut_od');

        $this->assertEquals(0, $nettoData['summary']['ready_count']);
        $this->assertEquals(1, $nettoData['summary']['halted_count']);
        $this->assertEquals('halted', $nettoData['halted'][0]['display_bucket']);
        $this->assertEquals('HALTED', $nettoData['halted'][0]['display_status']);
        $this->assertEquals(0, $nettoData['halted'][0]['qty']);

        // Next stage (OD) is completely empty
        $this->assertEquals(0, $odData['summary']['total_count']);
    }

    /**
     * Requirement J, K, L, M: Deterministic FIFO Ordering (Urgent > cast_date > created_at > ID).
     */
    public function test_j_to_m_deterministic_fifo_ordering(): void
    {
        $resEarly = SandCastingCastingResult::create([
            'heat_number' => 'H100',
            'cast_date' => '2026-09-10',
            'shift' => 1,
            'furnace' => 'F1',
            'recorded_by' => $this->admin->id,
        ]);

        $resLate = SandCastingCastingResult::create([
            'heat_number' => 'H200',
            'cast_date' => '2026-09-15',
            'shift' => 1,
            'furnace' => 'F1',
            'recorded_by' => $this->admin->id,
        ]);

        // Card 1: Normal early
        $c1 = $this->createKtrLine([
            'sand_casting_casting_result_id' => $resEarly->id,
            'heat_number' => 'H100',
            'is_urgent' => false,
            'current_stage' => 'netto',
        ]);

        // Card 2: Urgent late (should jump to first position)
        $c2 = $this->createKtrLine([
            'sand_casting_casting_result_id' => $resLate->id,
            'heat_number' => 'H200',
            'is_urgent' => true,
            'current_stage' => 'netto',
        ]);

        // Card 3: Normal late
        $c3 = $this->createKtrLine([
            'sand_casting_casting_result_id' => $resLate->id,
            'heat_number' => 'H200',
            'is_urgent' => false,
            'current_stage' => 'netto',
        ]);

        $data = $this->queryService->getStageKanbanData('netto');
        $ready = $data['ready'];

        $this->assertCount(3, $ready);
        $this->assertEquals($c2->traveler_number, $ready[0]['traveler_number'], 'Urgent must be first');
        $this->assertEquals($c1->traveler_number, $ready[1]['traveler_number'], 'Oldest cast_date must be second');
        $this->assertEquals($c3->traveler_number, $ready[2]['traveler_number'], 'Later cast_date must be third');
    }

    /**
     * Requirement N & O: Line grouping and size fallback mapping.
     */
    public function test_n_and_o_line_grouping_and_size_fallback(): void
    {
        $this->assertEquals(1, SandCastingProductionFloorQueryService::resolveLineNumber(1, '2"'));
        $this->assertEquals(2, SandCastingProductionFloorQueryService::resolveLineNumber(2, '4"'));
        $this->assertEquals(3, SandCastingProductionFloorQueryService::resolveLineNumber(3, '8"'));
        $this->assertEquals(4, SandCastingProductionFloorQueryService::resolveLineNumber(4, '14"'));

        // Fallback when plan line_number is null
        $this->assertEquals(1, SandCastingProductionFloorQueryService::resolveLineNumber(null, '1/2"'));
        $this->assertEquals(1, SandCastingProductionFloorQueryService::resolveLineNumber(null, '2"'));
        $this->assertEquals(2, SandCastingProductionFloorQueryService::resolveLineNumber(null, '2-1/2"'));
        $this->assertEquals(2, SandCastingProductionFloorQueryService::resolveLineNumber(null, '4"'));
        $this->assertEquals(3, SandCastingProductionFloorQueryService::resolveLineNumber(null, '6"'));
        $this->assertEquals(3, SandCastingProductionFloorQueryService::resolveLineNumber(null, '10"'));
        $this->assertEquals(4, SandCastingProductionFloorQueryService::resolveLineNumber(null, '14"'));
        $this->assertEquals(4, SandCastingProductionFloorQueryService::resolveLineNumber(null, 'DN 350'));
    }

    /**
     * Requirement P: Dynamic aging calculation.
     */
    public function test_p_dynamic_aging_calculation(): void
    {
        $result = SandCastingCastingResult::create([
            'heat_number' => 'H-AGING',
            'cast_date' => now()->subDays(5)->format('Y-m-d'),
            'shift' => 1,
            'furnace' => 'F1',
            'recorded_by' => $this->admin->id,
        ]);

        $ktr = $this->createKtrLine([
            'sand_casting_casting_result_id' => $result->id,
            'heat_number' => 'H-AGING',
            'current_stage' => 'netto',
        ]);

        $card = $this->queryService->resolveKanbanCard($ktr);

        $this->assertNotNull($card);
        $this->assertGreaterThanOrEqual(5, $card['aging']['total_aging_days']);
        $this->assertStringContainsString('h', $card['aging']['total_aging_label']);
    }

    /**
     * Requirement S, T, U: Bubut CNC multi-checkpoint progression in Kanban.
     */
    public function test_s_t_u_bubut_cnc_multi_checkpoint_kanban_progression(): void
    {
        // 1. Progress a KTR from Netto -> Bubut OD -> Bubut CNC
        $ktr = $this->createKtrLine(['qty_good' => 40, 'current_stage' => 'netto']);

        // Netto
        $e1 = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->admin->id);
        $e1 = $this->executionService->recordDefectQty($e1, 0, $this->admin->id);
        $this->executionService->verifyQcBreakdown($e1, [], $this->qcInspector->id);

        // Bubut OD
        $e2 = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->admin->id);
        $e2 = $this->executionService->recordDefectQty($e2, 0, $this->admin->id);
        $this->executionService->verifyQcBreakdown($e2, [], $this->qcInspector->id);

        $ktr->refresh();
        $this->assertEquals('bubut_cnc', $ktr->current_stage);

        // 2. Initial CNC: CNC_MACHINING is READY in BUBUT CNC
        $cncData = $this->queryService->getStageKanbanData('bubut_cnc');
        $this->assertEquals(1, $cncData['summary']['ready_count']);
        $this->assertEquals('CNC_MACHINING', $cncData['ready'][0]['active_checkpoint']);

        // 3. SPV CNC marks physical done -> BUBUT CNC incoming WAITING_DEFECT
        $eCnc = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_cnc', $this->admin->id);
        $cncData2 = $this->queryService->getStageKanbanData('bubut_cnc');
        $this->assertEquals(0, $cncData2['summary']['ready_count']);
        $this->assertEquals(1, $cncData2['summary']['incoming_count']);
        $this->assertEquals('WAITING_DEFECT', $cncData2['incoming'][0]['display_status']);

        // 4. Admin records defect -> WAITING_QC
        $eCnc = $this->executionService->recordDefectQty($eCnc, 2, $this->admin->id);
        $cncData3 = $this->queryService->getStageKanbanData('bubut_cnc');
        $this->assertEquals('WAITING_QC', $cncData3['incoming'][0]['display_status']);

        // 5. QC confirms CNC_MACHINING -> Next active checkpoint is QC_POST_CNC
        $this->executionService->verifyQcBreakdown($eCnc, [
            ['defect_type_id' => $this->defectPinhole->id, 'qty' => 2],
        ], $this->qcInspector->id);
        $cncData4 = $this->queryService->getStageKanbanData('bubut_cnc');
        $this->assertEquals(1, $cncData4['summary']['incoming_count']);
        $this->assertEquals('QC_POST_CNC', $cncData4['incoming'][0]['active_checkpoint']);

        // 6. QC executes & confirms QC_POST_CNC
        $ePostCnc = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_cnc', $this->qcInspector->id);
        $ePostCnc = $this->executionService->recordDefectQty($ePostCnc, 0, $this->qcInspector->id);
        $this->executionService->verifyQcBreakdown($ePostCnc, [], $this->qcInspector->id);

        // Next active checkpoint is QC_PRE_BOR (shown in BOR incoming)
        $borData = $this->queryService->getStageKanbanData('bor');
        $this->assertEquals(1, $borData['summary']['incoming_count']);
        $this->assertEquals('QC_PRE_BOR', $borData['incoming'][0]['active_checkpoint']);

        // 7. QC executes & confirms QC_PRE_BOR -> Advances to BOR READY
        $ePreBor = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_cnc', $this->qcInspector->id);
        $ePreBor = $this->executionService->recordDefectQty($ePreBor, 0, $this->qcInspector->id);
        $this->executionService->verifyQcBreakdown($ePreBor, [], $this->qcInspector->id);

        $borData2 = $this->queryService->getStageKanbanData('bor');
        $this->assertEquals(1, $borData2['summary']['ready_count']);
        $this->assertEquals('BOR_DRILLING', $borData2['ready'][0]['active_checkpoint']);
        $this->assertEquals(38, $borData2['summary']['ready_qty']);
    }

    /**
     * Requirement Q & R: Active Checkpoints for NETTO and OD.
     */
    public function test_q_r_active_checkpoint_netto_and_od(): void
    {
        $ktrNetto = $this->createKtrLine(['current_stage' => 'netto']);
        $cardNetto = $this->queryService->resolveKanbanCard($ktrNetto);
        $this->assertEquals('NETTO_CUT', $cardNetto['active_checkpoint']);

        $ktrOd = $this->createKtrLine(['current_stage' => 'bubut_od']);
        $cardOd = $this->queryService->resolveKanbanCard($ktrOd);
        $this->assertEquals('OD_TURNING', $cardOd['active_checkpoint']);
    }

    /**
     * Requirement V & W: Quantity Chain and Defect Quantity accurate on Card DTO.
     */
    public function test_v_w_quantity_chain_and_defect_on_card(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        // Stage 1: Initial Ready
        $card1 = $this->queryService->resolveKanbanCard($ktr);
        $this->assertEquals(100, $card1['qty']);
        $this->assertEquals(100, $card1['input_qty']);
        $this->assertEquals(0, $card1['defect_qty']);

        // Stage 2: Netto Physical Done & Defect
        $e1 = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->admin->id);
        $e1 = $this->executionService->recordDefectQty($e1, 4, $this->admin->id);
        $card2 = $this->queryService->resolveKanbanCard($ktr->fresh());
        $this->assertEquals(96, $card2['qty']);
        $this->assertEquals(100, $card2['input_qty']);
        $this->assertEquals(4, $card2['defect_qty']);
        $this->assertEquals(96, $card2['good_qty']);

        // Stage 3: Confirmed & advanced to OD
        $this->executionService->verifyQcBreakdown($e1, [
            ['defect_type_id' => $this->defectPinhole->id, 'qty' => 4],
        ], $this->qcInspector->id);
        $card3 = $this->queryService->resolveKanbanCard($ktr->fresh());
        $this->assertEquals(96, $card3['qty']);
        $this->assertEquals(96, $card3['input_qty']);
        $this->assertEquals(0, $card3['defect_qty']);
    }

    /**
     * Requirement Y: Full KTR Identity Fields Contract.
     */
    public function test_y_ktr_identity_fields_contract(): void
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

    /**
     * Requirement Z & AA: Multiple KTRs from same Heat & different items remain independent.
     */
    public function test_z_aa_multiple_ktrs_same_heat_different_items_independence(): void
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

        // Process only KTR 1 to physical done
        $this->executionService->markPhysicalDone($ktr1->traveler_number, 'netto', $this->admin->id);

        $nettoData = $this->queryService->getStageKanbanData('netto');
        $odData = $this->queryService->getStageKanbanData('bubut_od');

        // Netto has KTR 2 in Ready
        $this->assertEquals(1, $nettoData['summary']['ready_count']);
        $this->assertEquals($ktr2->traveler_number, $nettoData['ready'][0]['traveler_number']);

        // OD has KTR 1 in Incoming
        $this->assertEquals(1, $odData['summary']['incoming_count']);
        $this->assertEquals($ktr1->traveler_number, $odData['incoming'][0]['traveler_number']);
    }

    /**
     * Requirement AB: WAITING QC cards from multiple previous stages correctly projected.
     */
    public function test_ab_waiting_qc_from_multiple_previous_stages_projected(): void
    {
        // Netto KTR waiting QC -> projected to OD incoming
        $ktr1 = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'netto']);
        $e1 = $this->executionService->markPhysicalDone($ktr1->traveler_number, 'netto', $this->admin->id);
        $this->executionService->recordDefectQty($e1, 2, $this->admin->id);

        // Bubut OD KTR waiting QC -> projected to Bubut CNC incoming
        $ktr2 = $this->createKtrLine(['qty_good' => 40, 'current_stage' => 'bubut_od']);
        // Create confirmed Netto exec for KTR 2
        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $ktr2->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 0,
            'good_qty' => 40,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->admin->id,
            'executed_at' => now(),
        ]);
        $e2 = $this->executionService->markPhysicalDone($ktr2->traveler_number, 'bubut_od', $this->admin->id);
        $this->executionService->recordDefectQty($e2, 1, $this->admin->id);

        $odData = $this->queryService->getStageKanbanData('bubut_od');
        $cncData = $this->queryService->getStageKanbanData('bubut_cnc');

        $this->assertEquals(1, $odData['summary']['incoming_count']);
        $this->assertEquals($ktr1->traveler_number, $odData['incoming'][0]['traveler_number']);

        $this->assertEquals(1, $cncData['summary']['incoming_count']);
        $this->assertEquals($ktr2->traveler_number, $cncData['incoming'][0]['traveler_number']);
    }

    /**
     * Requirement AC: Urgent ordering does not alter stage ownership.
     */
    public function test_ac_urgent_ordering_does_not_alter_stage_ownership(): void
    {
        // Urgent KTR at Netto
        $ktrNettoUrgent = $this->createKtrLine(['is_urgent' => true, 'current_stage' => 'netto']);

        // Normal KTR at Bubut OD
        $ktrOdNormal = $this->createKtrLine(['is_urgent' => false, 'current_stage' => 'bubut_od']);

        $nettoData = $this->queryService->getStageKanbanData('netto');
        $odData = $this->queryService->getStageKanbanData('bubut_od');

        $this->assertEquals(1, $nettoData['summary']['ready_count']);
        $this->assertEquals($ktrNettoUrgent->traveler_number, $nettoData['ready'][0]['traveler_number']);

        $this->assertEquals(1, $odData['summary']['ready_count']);
        $this->assertEquals($ktrOdNormal->traveler_number, $odData['ready'][0]['traveler_number']);
    }

    /**
     * Requirement AD: Historical KTR with current_stage = NULL is excluded.
     */
    public function test_ad_historical_null_stage_ktr_is_excluded(): void
    {
        $this->createKtrLine(['current_stage' => null]);

        $nettoData = $this->queryService->getStageKanbanData('netto');
        $this->assertEquals(0, $nettoData['summary']['total_count']);
    }

    /**
     * Critical Safety Test: Operational Kanban query makes ZERO database mutations.
     */
    public function test_safety_kanban_query_is_strictly_read_only(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        $execCountBefore = SandCastingStageExecution::count();
        $lineCountBefore = SandCastingCastingResultLine::count();
        $dbQueriesCount = 0;

        DB::listen(function ($query) use (&$dbQueriesCount) {
            $dbQueriesCount++;
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

    /**
     * TEST A: NETTO WAITING_DEFECT appears in BUBUT OD as INCOMING (not READY).
     */
    public function test_a_netto_waiting_defect_appears_in_od_as_incoming(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 12, 'current_stage' => 'netto']);

        // SPV Netto marks physical work done -> execution transitions to WAITING_DEFECT
        $this->executionService->markPhysicalDone(
            travelerNumber: $ktr->traveler_number,
            targetStage: 'netto',
            operatorId: $this->spvNetto->id
        );

        $odData = $this->queryService->getStageKanbanData('bubut_od');

        $this->assertEquals(0, $odData['summary']['ready_count']);
        $this->assertEquals(1, $odData['summary']['incoming_count']);
        $this->assertEquals(12, $odData['summary']['incoming_qty']);

        $incomingCard = $odData['incoming'][0];
        $this->assertEquals($ktr->traveler_number, $incomingCard['traveler_number']);
        $this->assertEquals('bubut_od', $incomingCard['display_stage']);
        $this->assertEquals('incoming', $incomingCard['display_bucket']);
        $this->assertEquals('WAITING_DEFECT', $incomingCard['display_status']);
        $this->assertEquals(12, $incomingCard['qty']);
    }

    /**
     * TEST B: WAITING_DEFECT cannot be processed / scanned by SPV OD.
     */
    public function test_b_waiting_defect_cannot_be_processed_by_od(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 12, 'current_stage' => 'netto']);

        $this->executionService->markPhysicalDone(
            travelerNumber: $ktr->traveler_number,
            targetStage: 'netto',
            operatorId: $this->spvNetto->id
        );

        // 1. Direct service attempt throws InvalidArgumentException
        $this->expectException(\InvalidArgumentException::class);
        $this->executionService->markPhysicalDone(
            travelerNumber: $ktr->traveler_number,
            targetStage: 'bubut_od',
            operatorId: $this->spvBubutOd->id
        );
    }

    /**
     * TEST B2: WAITING_DEFECT execution endpoint rejected for OD SPV.
     */
    public function test_b2_waiting_defect_execute_endpoint_rejected_for_od(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 12, 'current_stage' => 'netto']);

        $this->executionService->markPhysicalDone(
            travelerNumber: $ktr->traveler_number,
            targetStage: 'netto',
            operatorId: $this->spvNetto->id
        );

        $response = $this->actingAs($this->spvBubutOd)->postJson(route('sand-casting.scan.execute', 'bubut-od'), [
            'traveler_number' => $ktr->traveler_number,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);

        // Verify only the 1 Netto execution exists, no OD execution created
        $this->assertEquals(1, SandCastingStageExecution::count());
        $this->assertEquals('netto', SandCastingStageExecution::first()->stage);
    }

    /**
     * TEST C: KTR does NOT appear as READY in NETTO when WAITING_DEFECT.
     */
    public function test_c_ktr_does_not_appear_as_ready_in_netto_when_waiting_defect(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 12, 'current_stage' => 'netto']);

        $this->executionService->markPhysicalDone(
            travelerNumber: $ktr->traveler_number,
            targetStage: 'netto',
            operatorId: $this->spvNetto->id
        );

        $nettoData = $this->queryService->getStageKanbanData('netto');

        $this->assertEquals(0, $nettoData['summary']['ready_count']);
        $this->assertEquals(0, $nettoData['summary']['incoming_count']);
        $this->assertEquals(0, $nettoData['summary']['halted_count']);
        $this->assertEquals(0, $nettoData['summary']['total_count']);
    }

    /**
     * TEST D: After Defect + QC confirmed, KTR becomes READY in OD with verified good qty.
     */
    public function test_d_after_defect_and_qc_confirmed_ktr_becomes_ready_in_od(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 12, 'current_stage' => 'netto']);

        $exec = $this->executionService->markPhysicalDone(
            travelerNumber: $ktr->traveler_number,
            targetStage: 'netto',
            operatorId: $this->spvNetto->id
        );

        // Admin records 2 defect -> good_qty becomes 10, status becomes WAITING_QC
        $execWaitingQc = $this->executionService->recordDefectQty(
            executionOrId: $exec->id,
            defectQty: 2,
            adminId: $this->admin->id
        );

        // QC confirms with 2 pinhole defects
        $this->executionService->verifyQcBreakdown(
            executionOrId: $execWaitingQc->id,
            defects: [
                ['defect_type_id' => $this->defectPinhole->id, 'qty' => 2],
            ],
            qcUserId: $this->qcInspector->id
        );

        $odData = $this->queryService->getStageKanbanData('bubut_od');

        $this->assertEquals(1, $odData['summary']['ready_count']);
        $this->assertEquals(0, $odData['summary']['incoming_count']);
        $this->assertEquals(10, $odData['summary']['ready_qty']);

        $readyCard = $odData['ready'][0];
        $this->assertEquals($ktr->traveler_number, $readyCard['traveler_number']);
        $this->assertEquals('bubut_od', $readyCard['display_stage']);
        $this->assertEquals('ready', $readyCard['display_bucket']);
        $this->assertEquals('READY', $readyCard['display_status']);
        $this->assertEquals(10, $readyCard['qty']);
    }

    /**
     * TEST E: No duplicate cards across the pipeline.
     */
    public function test_e_no_duplicate_card_for_waiting_defect(): void
    {
        $ktr = $this->createKtrLine(['qty_good' => 12, 'current_stage' => 'netto']);

        $this->executionService->markPhysicalDone(
            travelerNumber: $ktr->traveler_number,
            targetStage: 'netto',
            operatorId: $this->spvNetto->id
        );

        $stages = ['netto', 'bubut_od', 'bubut_cnc', 'bor', 'qc', 'gudang_jadi'];
        $foundCount = 0;

        foreach ($stages as $stg) {
            $data = $this->queryService->getStageKanbanData($stg);
            $allCards = array_merge($data['ready'], $data['incoming'], $data['halted']);
            foreach ($allCards as $c) {
                if ($c['traveler_number'] === $ktr->traveler_number) {
                    $foundCount++;
                }
            }
        }

        $this->assertEquals(1, $foundCount, 'KTR in WAITING_DEFECT must appear exactly once across all stage boards.');
    }

    /**
     * TEST F: Existing READY cards in OD are unaffected and remain fully functional.
     */
    public function test_f_existing_ready_in_od_is_unaffected(): void
    {
        // 1 KTR advanced through Netto and currently READY in OD
        $ktrOd = $this->createKtrLine(['qty_good' => 20, 'current_stage' => 'netto']);
        $execOdNetto = $this->executionService->markPhysicalDone(
            travelerNumber: $ktrOd->traveler_number,
            targetStage: 'netto',
            operatorId: $this->spvNetto->id
        );
        $execOdWaitingQc = $this->executionService->recordDefectQty(
            executionOrId: $execOdNetto->id,
            defectQty: 0,
            adminId: $this->admin->id
        );
        $this->executionService->verifyQcBreakdown(
            executionOrId: $execOdWaitingQc->id,
            defects: [],
            qcUserId: $this->qcInspector->id
        );

        // 1 KTR currently WAITING_DEFECT in Netto (incoming for OD)
        $ktrNetto = $this->createKtrLine(['qty_good' => 12, 'current_stage' => 'netto']);
        $this->executionService->markPhysicalDone(
            travelerNumber: $ktrNetto->traveler_number,
            targetStage: 'netto',
            operatorId: $this->spvNetto->id
        );

        $odData = $this->queryService->getStageKanbanData('bubut_od');

        $this->assertEquals(1, $odData['summary']['ready_count']);
        $this->assertEquals(1, $odData['summary']['incoming_count']);
        $this->assertEquals(20, $odData['summary']['ready_qty']);
        $this->assertEquals(12, $odData['summary']['incoming_qty']);

        $this->assertEquals($ktrOd->traveler_number, $odData['ready'][0]['traveler_number']);
        $this->assertEquals($ktrNetto->traveler_number, $odData['incoming'][0]['traveler_number']);
    }
}
