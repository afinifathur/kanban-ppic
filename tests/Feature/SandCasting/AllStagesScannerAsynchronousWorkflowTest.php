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
use App\Services\SandCasting\SandCastingQuantityResolverService;
use App\Services\SandCasting\SandCastingStageExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AllStagesScannerAsynchronousWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $spvNetto;

    protected User $spvBubutOd;

    protected User $spvBubutCnc;

    protected User $spvBor;

    protected User $spvQc;

    protected User $spvGudangJadi;

    protected User $qcInspector;

    protected DefectType $defectType;

    protected SandCastingStageExecutionService $executionService;

    protected SandCastingProductionFloorQueryService $queryService;

    protected SandCastingQuantityResolverService $quantityResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $planPerm = Permission::firstOrCreate(['name' => 'access_planning']);
        $execPerm = Permission::firstOrCreate(['name' => 'access_execution']);
        $visPerm = Permission::firstOrCreate(['name' => 'access_production_visibility']);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->givePermissionTo([$planPerm, $execPerm, $visPerm]);

        $spvRole = Role::firstOrCreate(['name' => 'spv']);
        $spvRole->givePermissionTo([$execPerm]);

        $qcRole = Role::firstOrCreate(['name' => 'qc']);
        $qcRole->givePermissionTo([$execPerm]);

        $this->adminUser = User::factory()->create([
            'name' => 'Super Admin PPIC',
            'email' => 'admin_sc_test@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->adminUser->assignRole('admin');

        $this->spvNetto = User::factory()->create([
            'name' => 'SPV Netto',
            'email' => 'spv_netto_sc@peroniks.com',
            'assigned_stage' => 'netto',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvNetto->assignRole('spv');

        $this->spvBubutOd = User::factory()->create([
            'name' => 'SPV Bubut OD',
            'email' => 'spv_od_sc@peroniks.com',
            'assigned_stage' => 'bubut_od',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvBubutOd->assignRole('spv');

        $this->spvBubutCnc = User::factory()->create([
            'name' => 'SPV Bubut CNC',
            'email' => 'spv_cnc_sc@peroniks.com',
            'assigned_stage' => 'bubut_cnc',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvBubutCnc->assignRole('spv');

        $this->spvBor = User::factory()->create([
            'name' => 'SPV Bor',
            'email' => 'spv_bor_sc@peroniks.com',
            'assigned_stage' => 'bor',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvBor->assignRole('spv');

        $this->spvQc = User::factory()->create([
            'name' => 'SPV QC Stage',
            'email' => 'spv_qc_sc@peroniks.com',
            'assigned_stage' => 'qc',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvQc->assignRole('spv');

        $this->spvGudangJadi = User::factory()->create([
            'name' => 'SPV Gudang Jadi',
            'email' => 'spv_gudang_sc@peroniks.com',
            'assigned_stage' => 'gudang_jadi',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvGudangJadi->assignRole('spv');

        $this->qcInspector = User::factory()->create([
            'name' => 'QC Inspector',
            'email' => 'qc_inspector_sc@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->qcInspector->assignRole('qc');

        $this->defectType = DefectType::create([
            'department' => 'netto',
            'name' => 'Porositas / Retak',
            'is_active' => true,
        ]);

        $this->quantityResolver = new SandCastingQuantityResolverService;
        $this->executionService = new SandCastingStageExecutionService($this->quantityResolver);
        $this->queryService = new SandCastingProductionFloorQueryService($this->executionService, $this->quantityResolver);
    }

    protected function createKtrLine(int $qty = 30, string $stage = 'netto'): SandCastingCastingResultLine
    {
        static $seq = 1;
        $idx = $seq++;

        $plan = ProductionPlan::create([
            'code' => 'SPK-ALL-'.str_pad((string) $idx, 4, '0', STR_PAD_LEFT),
            'title' => 'Rencana All Scanners '.$idx,
            'item_code' => '4.'.str_pad((string) $idx, 4, '0', STR_PAD_LEFT),
            'item_name' => 'SS304 SORF ANSI 300LBS 2"',
            'po_number' => 'PO-SC-'.$idx,
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'customer' => 'PT PERONI INTI METAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-ALL-'.str_pad((string) $idx, 4, '0', STR_PAD_LEFT),
            'scheduled_date' => '2026-10-31',
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
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => round($qty * 2.50, 2),
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'HEAT-ALL-'.str_pad((string) $idx, 4, '0', STR_PAD_LEFT),
            'cast_date' => '2026-10-31',
            'furnace' => 'F1',
            'shift' => '1',
            'recorded_by' => $this->adminUser->id,
        ]);

        return SandCastingCastingResultLine::create([
            'sand_casting_casting_result_id' => $result->id,
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-ALL-'.str_pad((string) $idx, 4, '0', STR_PAD_LEFT),
            'qty_good' => $qty,
            'qty_reject' => 0,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => round($qty * 2.50, 2),
            'current_stage' => $stage,
        ]);
    }

    /**
     * Matriks A: Defect NETTO berstatus WAITING_QC tidak memblokir scanner downstream.
     * Uji berturut-turut pada BUBUT OD, BUBUT CNC, BOR, QC, dan GUDANG JADI.
     */
    public function test_matrix_a_netto_waiting_qc_allows_all_downstream_scanners(): void
    {
        $ktr = $this->createKtrLine(30, 'netto');

        // Netto physical done, 2 defect recorded (status WAITING_QC)
        $eNetto = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $this->executionService->recordDefectQty($eNetto, 2, $this->adminUser->id);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $eNetto->fresh()->status);

        $ktr->refresh();
        $this->assertEquals('bubut_od', $ktr->current_stage);

        // 1. Scanner BUBUT OD tetap READY
        $lookupOd = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertEquals('READY', $lookupOd['operational_status']);
        $this->assertEquals('OD_TURNING', $lookupOd['active_checkpoint']);
        $this->assertEquals(28, $lookupOd['current_input_qty']);

        $resOd = $this->actingAs($this->spvBubutOd)->getJson("/sand-casting/scan/ktr/{$ktr->traveler_number}");
        $resOd->assertOk();
        $resOd->assertJsonPath('data.operational_status', 'READY');
        $resOd->assertJsonPath('data.current_input_qty', 28);

        // SPV Bubut OD menyelesaikan proses fisik
        $this->actingAs($this->spvBubutOd)->postJson('/sand-casting/scan/bubut-od/execute', [
            'traveler_number' => $ktr->traveler_number,
        ])->assertOk();

        // 2. Scanner BUBUT CNC tetap READY
        $ktr->refresh();
        $this->assertEquals('bubut_cnc', $ktr->current_stage);

        $lookupCnc = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertEquals('READY', $lookupCnc['operational_status']);
        $this->assertEquals('CNC_MACHINING', $lookupCnc['active_checkpoint']);
        $this->assertEquals(28, $lookupCnc['current_input_qty']);

        $resCnc = $this->actingAs($this->spvBubutCnc)->getJson("/sand-casting/scan/ktr/{$ktr->traveler_number}");
        $resCnc->assertOk();
        $resCnc->assertJsonPath('data.operational_status', 'READY');

        // SPV Bubut CNC menyelesaikan proses fisik
        $this->actingAs($this->spvBubutCnc)->postJson('/sand-casting/scan/bubut-cnc/execute', [
            'traveler_number' => $ktr->traveler_number,
        ])->assertOk();

        // 3. Scanner BOR tetap READY
        $ktr->refresh();
        $this->assertEquals('bor', $ktr->current_stage);

        $lookupBor = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertEquals('READY', $lookupBor['operational_status']);
        $this->assertEquals('BOR_DRILLING', $lookupBor['active_checkpoint']);
        $this->assertEquals(28, $lookupBor['current_input_qty']);

        $resBor = $this->actingAs($this->spvBor)->getJson("/sand-casting/scan/ktr/{$ktr->traveler_number}");
        $resBor->assertOk();
        $resBor->assertJsonPath('data.operational_status', 'READY');

        // SPV Bor menyelesaikan proses fisik
        $this->actingAs($this->spvBor)->postJson('/sand-casting/scan/bor/execute', [
            'traveler_number' => $ktr->traveler_number,
        ])->assertOk();

        // 4. Scanner QC tetap READY
        $ktr->refresh();
        $this->assertEquals('qc', $ktr->current_stage);

        $lookupQc = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertEquals('READY', $lookupQc['operational_status']);
        $this->assertEquals('QC_FINAL_INSPECTION', $lookupQc['active_checkpoint']);
        $this->assertEquals(28, $lookupQc['current_input_qty']);

        $resQc = $this->actingAs($this->spvQc)->getJson("/sand-casting/scan/ktr/{$ktr->traveler_number}");
        $resQc->assertOk();
        $resQc->assertJsonPath('data.operational_status', 'READY');

        // SPV QC menyelesaikan proses fisik
        $this->actingAs($this->spvQc)->postJson('/sand-casting/scan/qc/execute', [
            'traveler_number' => $ktr->traveler_number,
        ])->assertOk();

        // 5. Scanner GUDANG JADI tetap READY
        $ktr->refresh();
        $this->assertEquals('gudang_jadi', $ktr->current_stage);

        $lookupGudang = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertEquals('READY', $lookupGudang['operational_status']);
        $this->assertEquals('GUDANG_RECEIVE', $lookupGudang['active_checkpoint']);
        $this->assertEquals(28, $lookupGudang['current_input_qty']);

        $resGudang = $this->actingAs($this->spvGudangJadi)->getJson("/sand-casting/scan/ktr/{$ktr->traveler_number}");
        $resGudang->assertOk();
        $resGudang->assertJsonPath('data.operational_status', 'READY');
    }

    /**
     * Matriks B & C & D & E: Defect pada stage downstream (BUBUT OD, BUBUT CNC, BOR, QC)
     * tidak memblokir stage setelahnya.
     */
    public function test_matrix_b_c_d_e_subsequent_defects_do_not_block_downstream_scanners(): void
    {
        $ktr = $this->createKtrLine(50, 'netto');

        // 1. Netto normal (50)
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $ktr->refresh();

        // 2. Bubut OD done, defect 3 recorded (WAITING_QC)
        $eOd = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->spvBubutOd->id);
        $this->executionService->recordDefectQty($eOd, 3, $this->adminUser->id);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $eOd->fresh()->status);
        $ktr->refresh();

        // Scanner Bubut CNC harus READY dengan 47 PCS
        $lookupCnc = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertEquals('READY', $lookupCnc['operational_status']);
        $this->assertEquals(47, $lookupCnc['current_input_qty']);

        // 3. Bubut CNC done, defect 2 recorded (WAITING_QC)
        $eCnc = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_cnc', $this->spvBubutCnc->id);
        $this->executionService->recordDefectQty($eCnc, 2, $this->adminUser->id);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $eCnc->fresh()->status);
        $ktr->refresh();

        // Scanner Bor harus READY dengan 45 PCS
        $lookupBor = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertEquals('READY', $lookupBor['operational_status']);
        $this->assertEquals(45, $lookupBor['current_input_qty']);

        // 4. Bor done, defect 1 recorded (WAITING_QC)
        $eBor = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bor', $this->spvBor->id);
        $this->executionService->recordDefectQty($eBor, 1, $this->adminUser->id);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $eBor->fresh()->status);
        $ktr->refresh();

        // Scanner QC harus READY dengan 44 PCS
        $lookupQc = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertEquals('READY', $lookupQc['operational_status']);
        $this->assertEquals(44, $lookupQc['current_input_qty']);

        // 5. QC done, defect 4 recorded (WAITING_QC)
        $eQc = $this->executionService->markPhysicalDone($ktr->traveler_number, 'qc', $this->spvQc->id);
        $this->executionService->recordDefectQty($eQc, 4, $this->adminUser->id);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $eQc->fresh()->status);
        $ktr->refresh();

        // Scanner Gudang Jadi harus READY dengan 40 PCS
        $lookupGudang = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertEquals('READY', $lookupGudang['operational_status']);
        $this->assertEquals(40, $lookupGudang['current_input_qty']);
        $this->assertEquals('GUDANG_RECEIVE', $lookupGudang['active_checkpoint']);
    }

    /**
     * Skenario 3: Pengujian Operasional Lengkap End-to-End dengan Asynchronous QC Verification.
     */
    public function test_complete_end_to_end_operational_flow_with_asynchronous_qc_verification(): void
    {
        // 1. Buat KTR 30 PCS
        $ktr = $this->createKtrLine(30, 'netto');

        // 2. Operator menyelesaikan NETTO
        $eNetto = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $ktr->refresh();
        $this->assertEquals('bubut_od', $ktr->current_stage);

        // 3. Admin PPIC menginput defect 2 PCS di NETTO
        $eNetto = $this->executionService->recordDefectQty($eNetto, 2, $this->adminUser->id);

        // 4. Status defect NETTO menjadi WAITING_QC
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $eNetto->fresh()->status);

        // 5. Operator tetap menyelesaikan BUBUT OD dengan 28 PCS
        $lookupOd = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertEquals('READY', $lookupOd['operational_status']);
        $this->assertEquals(28, $lookupOd['current_input_qty']);
        $eOd = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->spvBubutOd->id);
        $this->assertEquals(28, (int) $eOd->input_qty);
        $ktr->refresh();
        $this->assertEquals('bubut_cnc', $ktr->current_stage);

        // 6. Admin PPIC menginput defect pada BUBUT OD (1 PCS)
        $eOd = $this->executionService->recordDefectQty($eOd, 1, $this->adminUser->id);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $eOd->fresh()->status);

        // 7. Operator tetap menyelesaikan BUBUT CNC (27 PCS)
        $lookupCnc = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertEquals('READY', $lookupCnc['operational_status']);
        $this->assertEquals(27, $lookupCnc['current_input_qty']);
        $eCnc = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_cnc', $this->spvBubutCnc->id);
        $this->assertEquals(27, (int) $eCnc->input_qty);
        $ktr->refresh();
        $this->assertEquals('bor', $ktr->current_stage);

        // 8. Lanjutkan BOR (27 PCS), QC (27 PCS), hingga GUDANG JADI (27 PCS)
        $lookupBor = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertEquals('READY', $lookupBor['operational_status']);
        $this->assertEquals(27, $lookupBor['current_input_qty']);
        $eBor = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bor', $this->spvBor->id);
        $this->assertEquals(27, (int) $eBor->input_qty);
        $ktr->refresh();
        $this->assertEquals('qc', $ktr->current_stage);

        $lookupQc = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertEquals('READY', $lookupQc['operational_status']);
        $this->assertEquals(27, $lookupQc['current_input_qty']);
        $eQc = $this->executionService->markPhysicalDone($ktr->traveler_number, 'qc', $this->spvQc->id);
        $this->assertEquals(27, (int) $eQc->input_qty);
        $ktr->refresh();
        $this->assertEquals('gudang_jadi', $ktr->current_stage);

        $lookupGudang = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertEquals('READY', $lookupGudang['operational_status']);
        $this->assertEquals(27, $lookupGudang['current_input_qty']);
        $eGudang = $this->executionService->markPhysicalDone($ktr->traveler_number, 'gudang_jadi', $this->spvGudangJadi->id);
        $this->assertEquals(27, (int) $eGudang->input_qty);
        $ktr->refresh();
        $this->assertEquals('completed', $ktr->current_stage);

        // 9. Admin QC melakukan verifikasi defect secara terpisah & asynchronous
        $this->executionService->verifyQcBreakdown($eNetto, [
            ['defect_type_id' => $this->defectType->id, 'qty' => 2],
        ], $this->qcInspector->id);
        $this->assertEquals(SandCastingStageExecution::STATUS_CONFIRMED, $eNetto->fresh()->status);

        $this->executionService->verifyQcBreakdown($eOd, [
            ['defect_type_id' => $this->defectType->id, 'qty' => 1],
        ], $this->qcInspector->id);
        $this->assertEquals(SandCastingStageExecution::STATUS_CONFIRMED, $eOd->fresh()->status);

        // Verifikasi integritas riwayat
        $history = $this->queryService->getStageHistory($ktr->traveler_number);
        $this->assertCount(6, $history);
        $this->assertEquals('completed', $ktr->fresh()->current_stage);
    }

    /**
     * Proteksi 1: Pencegahan double processing pada stage yang sudah selesai.
     */
    public function test_completed_stage_rescan_prevents_double_processing(): void
    {
        $ktr = $this->createKtrLine(25, 'netto');

        // Netto done, defect recorded (WAITING_QC)
        $eNetto = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $this->executionService->recordDefectQty($eNetto, 3, $this->adminUser->id);
        $ktr->refresh();

        // SPV Netto mencoba scan ulang KTR yang sama di stasiun Netto
        $resNetto = $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $ktr->traveler_number,
        ]);
        // Ditolak karena stage KTR saat ini sudah bubut_od
        $resNetto->assertStatus(422);

        // Jika lookup KTR dilakukan saat stage belum dipromosikan (misal duplicate race):
        // Active checkpoint null -> operational status adalah status eksekusi terakhir (WAITING_QC)
        $mockLine = $this->createKtrLine(25, 'netto');
        $exec = $mockLine->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 25,
            'defect_qty' => 2,
            'good_qty' => 23,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'operator_id' => $this->spvNetto->id,
            'physical_done_at' => now(),
            'executed_at' => now(),
        ]);
        $lookupCompletedStage = $this->queryService->findByTraveler($mockLine->traveler_number);
        $this->assertNull($lookupCompletedStage['active_checkpoint']);
        $this->assertEquals('WAITING_QC', $lookupCompletedStage['operational_status']);
    }

    /**
     * Proteksi 2: Eksekusi pada stage yang salah ditolak (Stage mismatch).
     */
    public function test_wrong_stage_execution_is_rejected(): void
    {
        $ktr = $this->createKtrLine(20, 'bubut_cnc');

        // Mencoba eksekusi di endpoint BOR
        $res = $this->actingAs($this->spvBor)->postJson('/sand-casting/scan/bor/execute', [
            'traveler_number' => $ktr->traveler_number,
        ]);
        $res->assertStatus(422);
        $res->assertJsonPath('message', 'KTR '.$ktr->traveler_number.' saat ini berada di stage BUBUT CNC. Tahap yang valid adalah BUBUT CNC, bukan BOR.');
    }

    /**
     * Proteksi 3: KTR dengan remaining processable quantity = 0 tetap HALTED dan tidak dapat diproses.
     */
    public function test_zero_remaining_quantity_ktr_is_halted_and_blocked(): void
    {
        $ktr = $this->createKtrLine(20, 'netto');

        // Netto: seluruh 20 PCS defect dicatat
        $eNetto = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $this->executionService->recordDefectQty($eNetto, 20, $this->adminUser->id);
        $ktr->refresh();

        $lookup = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertEquals(0, $lookup['current_input_qty']);
        $this->assertEquals('HALTED', $lookup['operational_status']);

        // Mencoba eksekusi di endpoint Bubut OD
        $res = $this->actingAs($this->spvBubutOd)->postJson('/sand-casting/scan/bubut-od/execute', [
            'traveler_number' => $ktr->traveler_number,
        ]);
        $res->assertStatus(422);
    }

    /**
     * Proteksi 4: Stage Authorization & Product Scope tetap berjalan ketat.
     */
    public function test_stage_and_product_scope_authorization_remain_enforced(): void
    {
        $ktr = $this->createKtrLine(20, 'bubut_od');

        // SPV Bubut CNC mencoba akses scanner Bubut OD -> 403
        $this->actingAs($this->spvBubutCnc)
            ->get('/sand-casting/scan/stage/bubut-od')
            ->assertStatus(403);

        // SPV Bubut CNC mencoba eksekusi Bubut OD -> 403
        $this->actingAs($this->spvBubutCnc)
            ->postJson('/sand-casting/scan/bubut-od/execute', [
                'traveler_number' => $ktr->traveler_number,
            ])
            ->assertStatus(403);
    }
}

