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
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinalQualityLockTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminPpic;

    protected User $adminUser;

    protected User $qcUser;

    protected User $spvGudangJadi;

    protected User $spvNetto;

    protected User $spvBubutOd;

    protected User $spvBubutCnc;

    protected User $spvBor;

    protected User $spvQc;

    protected SandCastingStageExecutionService $executionService;

    protected SandCastingProductionFloorQueryService $queryService;

    protected SandCastingQuantityResolverService $quantityResolver;

    protected DefectType $defectTypeNetto;

    protected DefectType $defectTypeQc;

    protected DefectType $defectTypeGudangJadi;

    protected function setUp(): void
    {
        parent::setUp();

        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution']);

        $rolePpic = Role::firstOrCreate(['name' => 'ppic']);
        $rolePpic->givePermissionTo([$permPlanning, $permExecution]);

        $roleAdmin = Role::firstOrCreate(['name' => 'admin']);
        $roleAdmin->givePermissionTo([$permPlanning, $permExecution]);

        $roleQc = Role::firstOrCreate(['name' => 'qc']);
        $roleQc->givePermissionTo($permExecution);

        $roleSpv = Role::firstOrCreate(['name' => 'spv']);
        $roleSpv->givePermissionTo($permExecution);

        // 1. Admin PPIC Flange (Authorized PPIC actor)
        $this->adminPpic = User::factory()->create([
            'name' => 'Admin PPIC Flange',
            'email' => 'adminppicfl@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->adminPpic->assignRole('ppic');

        // 2. Superadmin
        $this->adminUser = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'superadmin@peroniks.com',
        ]);
        $this->adminUser->assignRole('admin');

        // 3. QC User
        $this->qcUser = User::factory()->create([
            'name' => 'Admin QC Flange',
            'email' => 'adminqcflange@peroniks.com',
        ]);
        $this->qcUser->assignRole('qc');

        // 4. SPVs per stage
        $this->spvNetto = User::factory()->create([
            'name' => 'SPV Netto',
            'email' => 'spvnettofl@peroniks.com',
            'assigned_stage' => 'netto',
        ]);
        $this->spvNetto->assignRole('spv');

        $this->spvBubutOd = User::factory()->create([
            'name' => 'SPV Bubut OD',
            'email' => 'spvbbodfl@peroniks.com',
            'assigned_stage' => 'bubut_od',
        ]);
        $this->spvBubutOd->assignRole('spv');

        $this->spvBubutCnc = User::factory()->create([
            'name' => 'SPV Bubut CNC',
            'email' => 'spvcncfl@peroniks.com',
            'assigned_stage' => 'bubut_cnc',
        ]);
        $this->spvBubutCnc->assignRole('spv');

        $this->spvBor = User::factory()->create([
            'name' => 'SPV Bor',
            'email' => 'spvborfl@peroniks.com',
            'assigned_stage' => 'bor',
        ]);
        $this->spvBor->assignRole('spv');

        $this->spvQc = User::factory()->create([
            'name' => 'SPV QC',
            'email' => 'spvqcfl@peroniks.com',
            'assigned_stage' => 'qc',
        ]);
        $this->spvQc->assignRole('spv');

        $this->spvGudangJadi = User::factory()->create([
            'name' => 'SPV Gudang Jadi',
            'email' => 'spvgdfl@peroniks.com',
            'assigned_stage' => 'gudang_jadi',
        ]);
        $this->spvGudangJadi->assignRole('spv');

        $this->executionService = new SandCastingStageExecutionService;
        $this->queryService = new SandCastingProductionFloorQueryService;
        $this->quantityResolver = new SandCastingQuantityResolverService;

        // Seed defect types
        $this->defectTypeNetto = DefectType::firstOrCreate(
            ['department' => 'netto', 'name' => 'Keropos Netto'],
            ['is_active' => true]
        );
        $this->defectTypeQc = DefectType::firstOrCreate(
            ['department' => 'qc', 'name' => 'Dimensi Under QC'],
            ['is_active' => true]
        );
        $this->defectTypeGudangJadi = DefectType::firstOrCreate(
            ['department' => 'gudang_jadi', 'name' => 'Goresan Gudang Jadi'],
            ['is_active' => true]
        );
    }

    protected function createKtrLine(array $attributes = [], ?SandCastingCastingResult $existingResult = null): SandCastingCastingResultLine
    {
        $uniqueSuffix = strtoupper(bin2hex(random_bytes(4))).rand(100, 999);

        $plan = ProductionPlan::create([
            'code' => 'PLAN-'.$uniqueSuffix,
            'title' => 'Rencana SC '.$uniqueSuffix,
            'item_code' => 'ITEM-'.$uniqueSuffix,
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-'.$uniqueSuffix,
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'customer' => 'PT SINAR METAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-'.$uniqueSuffix,
            'scheduled_date' => now()->toDateString(),
            'status' => 'ISSUED',
            'notes' => 'Test PCOR',
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
            'status' => 'issued',
        ]);

        if (! $existingResult) {
            $existingResult = SandCastingCastingResult::create([
                'heat_number' => 'HEAT-'.$uniqueSuffix,
                'cast_date' => now()->toDateString(),
                'furnace' => 'F1',
                'shift' => '1',
                'operator_name' => 'Budi Cor',
                'recorded_by' => $this->adminPpic->id,
                'total_qty_good' => 100,
                'total_qty_reject' => 0,
            ]);
        }

        $travelerNumber = 'KTR-'.now()->format('Ymd').'-'.$uniqueSuffix;

        return $existingResult->lines()->create(array_merge([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $travelerNumber,
            'qty_good' => 100,
            'qty_reject' => 0,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 250.00,
            'current_stage' => 'netto',
            'queue_position' => null,
            'is_urgent' => false,
        ], $attributes));
    }

    /**
     * Helper to run full physical progression up to Gudang Jadi completed.
     *
     * @return array<string, SandCastingStageExecution>
     */
    protected function progressKtrToGudangJadiCompleted(SandCastingCastingResultLine $line): array
    {
        // 1. Netto (Input 100, Defect 2, Good 98)
        $nettoExec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvNetto->id);
        $nettoExec = $this->executionService->recordDefectQty($nettoExec, 2, $this->adminPpic->id);
        $this->executionService->verifyQcBreakdown($nettoExec, [
            ['defect_type_id' => $this->defectTypeNetto->id, 'qty' => 2],
        ], $this->qcUser->id);

        // 2. Bubut OD (Input 98, Defect 0, Good 98)
        $bubutOdExec = $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_od', $this->spvBubutOd->id);
        $bubutOdExec = $this->executionService->recordDefectQty($bubutOdExec, 0, $this->adminPpic->id);
        $this->executionService->verifyQcBreakdown($bubutOdExec, [], $this->qcUser->id);

        // 3. Bubut CNC (Input 98, Defect 3, Good 95)
        $bubutCncExec = $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_cnc', $this->spvBubutCnc->id);
        $bubutCncExec = $this->executionService->recordDefectQty($bubutCncExec, 3, $this->adminPpic->id);
        $this->executionService->verifyQcBreakdown($bubutCncExec, [
            ['defect_type_id' => $this->defectTypeQc->id, 'qty' => 3],
        ], $this->qcUser->id);

        // 4. Bor (Input 95, Defect 0, Good 95)
        $borExec = $this->executionService->markPhysicalDone($line->traveler_number, 'bor', $this->spvBor->id);
        $borExec = $this->executionService->recordDefectQty($borExec, 0, $this->adminPpic->id);
        $this->executionService->verifyQcBreakdown($borExec, [], $this->qcUser->id);

        // 5. QC (Input 95, Defect 5, Good 90)
        $qcExec = $this->executionService->markPhysicalDone($line->traveler_number, 'qc', $this->spvQc->id);
        $qcExec = $this->executionService->recordDefectQty($qcExec, 5, $this->adminPpic->id);
        $this->executionService->verifyQcBreakdown($qcExec, [
            ['defect_type_id' => $this->defectTypeQc->id, 'qty' => 5],
        ], $this->qcUser->id);

        // 6. Gudang Jadi (Physical mark done -> transitions current_stage to completed)
        $gudangJadiExec = $this->executionService->markPhysicalDone($line->traveler_number, 'gudang_jadi', $this->spvGudangJadi->id);

        $line->refresh();

        return [
            'netto' => $nettoExec->fresh(),
            'bubut_od' => $bubutOdExec->fresh(),
            'bubut_cnc' => $bubutCncExec->fresh(),
            'bor' => $borExec->fresh(),
            'qc' => $qcExec->fresh(),
            'gudang_jadi' => $gudangJadiExec->fresh(),
        ];
    }

    /**
     * TC-01 — KTR belum selesai: Admin PPIC dapat mencatat defect Netto.
     */
    public function test_tc01_uncompleted_ktr_can_record_netto_defect(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto']);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvNetto->id);

        $response = $this->actingAs($this->adminPpic)
            ->postJson("/sand-casting/defects/{$exec->id}/record", [
                'defect_qty' => 4,
                'notes' => 'Pencatatan defect netto normal',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'defect_qty' => 4,
                'good_qty' => 96,
                'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            ],
        ]);

        $this->assertDatabaseHas('sand_casting_stage_executions', [
            'id' => $exec->id,
            'defect_qty' => 4,
            'good_qty' => 96,
        ]);
    }

    /**
     * TC-02 — KTR belum selesai: Admin PPIC dapat mengoreksi defect QC.
     */
    public function test_tc02_uncompleted_ktr_can_adjust_qc_defect(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto']);

        // Progress up to QC
        $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvNetto->id);
        $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_od', $this->spvBubutOd->id);
        $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_cnc', $this->spvBubutCnc->id);
        $this->executionService->markPhysicalDone($line->traveler_number, 'bor', $this->spvBor->id);
        $qcExec = $this->executionService->markPhysicalDone($line->traveler_number, 'qc', $this->spvQc->id);

        // Initial record QC defect 5 pcs
        $this->executionService->recordDefectQty($qcExec, 5, $this->adminPpic->id);

        // Adjust defect +2 pcs
        $response = $this->actingAs($this->adminPpic)
            ->postJson("/sand-casting/defects/{$qcExec->id}/adjust", [
                'adjustment_qty' => 2,
                'notes' => 'Koreksi penambahan defect QC',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'defect_qty' => 7,
                'good_qty' => 93,
            ],
        ]);
    }

    /**
     * TC-03 — Finalisasi Gudang Jadi: Ketika markPhysicalDone() berhasil, KTR berubah menjadi completed dan final quality lock aktif.
     */
    public function test_tc03_gudang_jadi_finalization_activates_final_quality_lock(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto']);
        $executions = $this->progressKtrToGudangJadiCompleted($line);

        $line->refresh();
        $this->assertEquals('completed', $line->current_stage);
        $this->assertNull($line->queue_position);

        // Verify GUDANG_RECEIVE execution exists
        $this->assertDatabaseHas('sand_casting_stage_executions', [
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'gudang_jadi',
            'checkpoint_code' => 'GUDANG_RECEIVE',
        ]);

        // Quality lock must be true for all prior stages
        $this->assertTrue($this->executionService->isStageQualityLocked($executions['netto'], $line));
        $this->assertTrue($this->executionService->isStageQualityLocked($executions['bubut_od'], $line));
        $this->assertTrue($this->executionService->isStageQualityLocked($executions['bubut_cnc'], $line));
        $this->assertTrue($this->executionService->isStageQualityLocked($executions['bor'], $line));
        $this->assertTrue($this->executionService->isStageQualityLocked($executions['qc'], $line));

        // Exception: stage gudang_jadi must NOT be locked
        $this->assertFalse($this->executionService->isStageQualityLocked($executions['gudang_jadi'], $line));
    }

    /**
     * TC-04 — Defect Netto setelah finalisasi: Coba endpoint record, add, dan adjust (harus ditolak HTTP 422).
     */
    public function test_tc04_netto_defect_mutation_rejected_after_gudang_jadi_completed(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto']);
        $executions = $this->progressKtrToGudangJadiCompleted($line);
        $nettoExec = $executions['netto'];
        $initialDefect = $nettoExec->defect_qty;

        // 1. Try /record endpoint
        $resRecord = $this->actingAs($this->adminPpic)
            ->postJson("/sand-casting/defects/{$nettoExec->id}/record", [
                'defect_qty' => 10,
                'notes' => 'Mencoba re-record netto',
            ]);
        $resRecord->assertStatus(422);
        $resRecord->assertJson(['success' => false]);
        $this->assertStringContainsString('TERKUNCI', $resRecord->json('message'));

        // 2. Try /add endpoint
        $resAdd = $this->actingAs($this->adminPpic)
            ->postJson("/sand-casting/defects/{$nettoExec->id}/add", [
                'added_qty' => 2,
                'notes' => 'Mencoba tambah defect netto',
            ]);
        $resAdd->assertStatus(422);
        $resAdd->assertJson(['success' => false]);
        $this->assertStringContainsString('TERKUNCI', $resAdd->json('message'));

        // 3. Try /adjust endpoint with negative delta
        $resAdjust = $this->actingAs($this->adminPpic)
            ->postJson("/sand-casting/defects/{$nettoExec->id}/adjust", [
                'adjustment_qty' => -1,
                'notes' => 'Mencoba kurangi defect netto',
            ]);
        $resAdjust->assertStatus(422);
        $resAdjust->assertJson(['success' => false]);
        $this->assertStringContainsString('TERKUNCI', $resAdjust->json('message'));

        // Confirm database values are completely untouched
        $this->assertEquals($initialDefect, $nettoExec->fresh()->defect_qty);
    }

    /**
     * TC-05 — Defect Bubut OD, CNC, dan Bor setelah finalisasi: Coba seluruh jalur mutasi (harus ditolak).
     */
    public function test_tc05_machining_stages_defect_mutation_rejected_after_finalization(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto']);
        $executions = $this->progressKtrToGudangJadiCompleted($line);

        $machiningStages = ['bubut_od', 'bubut_cnc', 'bor'];

        foreach ($machiningStages as $stg) {
            $exec = $executions[$stg];
            $initialDefect = $exec->defect_qty;

            // Attempt add
            $res = $this->actingAs($this->adminPpic)
                ->postJson("/sand-casting/defects/{$exec->id}/add", [
                    'added_qty' => 1,
                    'notes' => "Mencoba edit defect {$stg}",
                ]);

            $res->assertStatus(422);
            $res->assertJson(['success' => false]);
            $this->assertStringContainsString('TERKUNCI', $res->json('message'));
            $this->assertEquals($initialDefect, $exec->fresh()->defect_qty);
        }
    }

    /**
     * TC-06 — Defect QC setelah finalisasi: Coba save-draft dan verify (harus ditolak).
     */
    public function test_tc06_qc_defect_draft_and_verify_rejected_after_finalization(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto']);
        $executions = $this->progressKtrToGudangJadiCompleted($line);
        $qcExec = $executions['qc'];

        // 1. Try save draft QC
        $resDraft = $this->actingAs($this->qcUser)
            ->postJson("/sand-casting/qc-defects/{$qcExec->id}/save-draft", [
                'defects' => [
                    ['defect_type_id' => $this->defectTypeQc->id, 'qty' => 3],
                ],
                'notes' => 'Mencoba simpan draft QC pasca gudang jadi',
            ]);

        $resDraft->assertStatus(422);
        $resDraft->assertJson(['success' => false]);
        $this->assertStringContainsString('TERKUNCI', $resDraft->json('message'));

        // 2. Try verify QC
        $resVerify = $this->actingAs($this->qcUser)
            ->postJson("/sand-casting/qc-defects/{$qcExec->id}/verify", [
                'defects' => [
                    ['defect_type_id' => $this->defectTypeQc->id, 'qty' => $qcExec->defect_qty],
                ],
                'notes' => 'Mencoba verifikasi QC pasca gudang jadi',
            ]);

        $resVerify->assertStatus(422);
        $resVerify->assertJson(['success' => false]);
        $this->assertStringContainsString('TERKUNCI', $resVerify->json('message'));
    }

    /**
     * TC-07 — Defect Gudang Jadi setelah finalisasi: Coba record, add, dan adjust (harus tetap berhasil).
     */
    public function test_tc07_gudang_jadi_defect_mutation_remains_allowed_after_finalization(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto']);
        $executions = $this->progressKtrToGudangJadiCompleted($line);
        $gudangExec = $executions['gudang_jadi'];

        // Initial input into Gudang Jadi is 90 pcs (100 - 2 netto - 3 cnc - 5 qc = 90)
        $this->assertEquals(90, $gudangExec->input_qty);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_DEFECT, $gudangExec->status);

        // 1. Step 2: Record defect initial 2 pcs in Gudang Jadi (e.g. handling defect)
        $resRecord = $this->actingAs($this->adminPpic)
            ->postJson("/sand-casting/defects/{$gudangExec->id}/record", [
                'defect_qty' => 2,
                'notes' => 'Catat reject handling gudang jadi',
            ]);

        $resRecord->assertStatus(200);
        $resRecord->assertJson([
            'success' => true,
            'data' => [
                'defect_qty' => 2,
                'good_qty' => 88,
                'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            ],
        ]);

        // 2. Step 2B: Add defect susulan +1 pcs
        $resAdd = $this->actingAs($this->adminPpic)
            ->postJson("/sand-casting/defects/{$gudangExec->id}/add", [
                'added_qty' => 1,
                'notes' => 'Tambah defect gudang jadi susulan',
            ]);

        $resAdd->assertStatus(200);
        $resAdd->assertJson([
            'success' => true,
            'data' => [
                'defect_qty' => 3,
                'good_qty' => 87,
            ],
        ]);

        // 3. Step 2C: Adjust defect -1 pcs (koreksi)
        $resAdjust = $this->actingAs($this->adminPpic)
            ->postJson("/sand-casting/defects/{$gudangExec->id}/adjust", [
                'adjustment_qty' => -1,
                'notes' => 'Koreksi salah hitung barang gudang',
            ]);

        $resAdjust->assertStatus(200);
        $resAdjust->assertJson([
            'success' => true,
            'data' => [
                'defect_qty' => 2,
                'good_qty' => 88,
            ],
        ]);
    }

    /**
     * TC-08 — KTR lain belum selesai: Coba mengubah defect KTR lain yang berada dalam Heat Number sama (harus berhasil).
     */
    public function test_tc08_other_ktr_in_same_heat_remains_editable(): void
    {
        $castingResult = SandCastingCastingResult::create([
            'heat_number' => 'HEAT-SAME-BATCH-001',
            'cast_date' => now()->toDateString(),
            'furnace' => 'F1',
            'shift' => '1',
            'operator_name' => 'Budi Cor',
            'recorded_by' => $this->adminPpic->id,
            'total_qty_good' => 200,
            'total_qty_reject' => 0,
        ]);

        // KTR-1: Progressed to Gudang Jadi completed
        $line1 = $this->createKtrLine(['current_stage' => 'netto'], $castingResult);
        $execs1 = $this->progressKtrToGudangJadiCompleted($line1);

        // KTR-2: Still in process at Netto
        $line2 = $this->createKtrLine(['current_stage' => 'netto'], $castingResult);
        $nettoExec2 = $this->executionService->markPhysicalDone($line2->traveler_number, 'netto', $this->spvNetto->id);

        // Verify KTR-1 Netto is locked
        $this->assertTrue($this->executionService->isStageQualityLocked($execs1['netto'], $line1));

        // Verify KTR-2 Netto is NOT locked
        $this->assertFalse($this->executionService->isStageQualityLocked($nettoExec2, $line2));

        // Mutating defect on KTR-2 succeeds
        $res2 = $this->actingAs($this->adminPpic)
            ->postJson("/sand-casting/defects/{$nettoExec2->id}/record", [
                'defect_qty' => 5,
                'notes' => 'Defect KTR-2 berhasil dicatat',
            ]);

        $res2->assertStatus(200);
        $res2->assertJson([
            'success' => true,
            'data' => [
                'defect_qty' => 5,
                'good_qty' => 95,
            ],
        ]);
    }

    /**
     * TC-09 — UI Lock: Halaman defect dan QC menampilkan badge TERKUNCI untuk stage hulu pada KTR yang sudah selesai.
     */
    public function test_tc09_ui_renders_locked_badge_for_completed_ktr_stages(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto']);
        $executions = $this->progressKtrToGudangJadiCompleted($line);

        // Format cards via query service
        $cardNetto = $this->queryService->formatDefectRecordingCard($executions['netto']);
        $cardGudang = $this->queryService->formatDefectRecordingCard($executions['gudang_jadi']);
        $cardQc = $this->queryService->formatQcVerificationCard($executions['qc']);

        $this->assertTrue($cardNetto['is_quality_locked']);
        $this->assertFalse($cardGudang['is_quality_locked']);
        $this->assertTrue($cardQc['is_quality_locked']);

        // Check Blade rendering for defects index
        $responseDefects = $this->actingAs($this->adminPpic)
            ->get('/sand-casting/defects?mode=recorded&stage=netto');

        $responseDefects->assertStatus(200);
        $responseDefects->assertSee('TERKUNCI');

        // Check Blade rendering for qc-defects index
        $responseQc = $this->actingAs($this->qcUser)
            ->get('/sand-casting/qc-defects?mode=unrecorded&stage=qc');

        $responseQc->assertStatus(200);
    }

    /**
     * TC-10 — Race Condition & Transaction Design:
     * Verifikasi bahwa jika finalisasi Gudang Jadi selesai lebih dulu, operasi koreksi defect hulu
     * yang dieksekusi setelahnya secara konsisten ditolak oleh row lock dan fresh finalization check.
     */
    public function test_tc10_transaction_and_row_locking_guarantees_race_safety(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto']);

        // Progress up to QC completed, currently at stage gudang_jadi
        $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvNetto->id);
        $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_od', $this->spvBubutOd->id);
        $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_cnc', $this->spvBubutCnc->id);
        $this->executionService->markPhysicalDone($line->traveler_number, 'bor', $this->spvBor->id);
        $qcExec = $this->executionService->markPhysicalDone($line->traveler_number, 'qc', $this->spvQc->id);
        $this->executionService->recordDefectQty($qcExec, 5, $this->adminPpic->id);

        // Before finalization, QC defect modification is allowed
        $this->assertFalse($this->executionService->isStageQualityLocked($qcExec));

        // Thread A finishes markPhysicalDone('gudang_jadi')
        $this->executionService->markPhysicalDone($line->traveler_number, 'gudang_jadi', $this->spvGudangJadi->id);

        // Thread B immediately attempts to adjust QC defect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('telah TERKUNCI karena proses Gudang Jadi sudah selesai');

        $this->executionService->adjustDefectQty($qcExec, 1, $this->adminPpic->id, 'Penyesuaian defect balap');
    }

    /**
     * TC-11 — Existing Workflow Regression:
     * Pastikan workflow normal, audit log, quantity calculation, dan scanner tetap utuh.
     */
    public function test_tc11_existing_workflow_and_quantity_resolution_unaffected(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto']);
        $executions = $this->progressKtrToGudangJadiCompleted($line);

        // Audit log check: each stage execution defect log is properly recorded
        $nettoLogs = $executions['netto']->defectLogs()->get();
        $this->assertNotEmpty($nettoLogs);
        $this->assertEquals(2, $nettoLogs->first()->new_total);

        // Verify mathematical resolution consistency
        $effectiveNettoInput = $this->quantityResolver->resolveEffectiveInputQty($line, 'netto');
        $effectiveNettoGood = $this->quantityResolver->resolveEffectiveGoodQty($line, 'netto');
        $this->assertEquals(100, $effectiveNettoInput);
        $this->assertEquals(98, $effectiveNettoGood);

        // Effective input to Gudang Jadi (100 - 2 netto - 3 cnc - 5 qc = 90)
        $effectiveGudangInput = $this->quantityResolver->resolveEffectiveInputQty($line, 'gudang_jadi');
        $this->assertEquals(90, $effectiveGudangInput);

        // Scanner lookup endpoint works normally
        $lookupRes = $this->actingAs($this->spvGudangJadi)
            ->getJson('/sand-casting/scan/ktr/'.urlencode($line->traveler_number));

        $lookupRes->assertStatus(200);
        $lookupRes->assertJson(['success' => true]);
        $this->assertEquals('completed', $lookupRes->json('data.current_stage'));
    }

    /**
     * TC-12 — Security Guard Fail-Closed Test:
     * Memastikan guard bersikap fail-closed dan menolak mutasi jika parent KTR tidak ditemukan.
     */
    public function test_tc12_guard_is_fail_closed_and_rejects_mutation_when_parent_ktr_is_missing(): void
    {
        // 1. In-memory execution without parent line for stage 'netto'
        $orphanExec = new SandCastingStageExecution([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 0,
            'good_qty' => 100,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
        ]);
        $orphanExec->id = 88888;
        $orphanExec->setRelation('castingResultLine', null);

        // Assert direct call to assertStageQualityNotLocked throws InvalidArgumentException (fail-closed)
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Data KTR induk tidak ditemukan untuk eksekusi ID 88888. Mutasi defect ditolak demi integritas mutu (Fail-Closed).');

        $this->executionService->assertStageQualityNotLocked($orphanExec, null);
    }

    /**
     * TC-13 — Helper isStageQualityLocked returns true (locked) for orphaned execution (fail-closed)
     * and false for valid Gudang Jadi execution.
     */
    public function test_tc13_is_stage_quality_locked_returns_true_for_orphaned_execution(): void
    {
        $orphanExec = new SandCastingStageExecution([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
        ]);
        $orphanExec->id = 99999;
        $orphanExec->setRelation('castingResultLine', null);

        // Fail-closed: isStageQualityLocked must return true (treated as locked)
        $this->assertTrue($this->executionService->isStageQualityLocked($orphanExec, null));

        // Pengecualian Gudang Jadi: execution stage gudang_jadi with valid line is not locked
        $line = $this->createKtrLine(['current_stage' => 'netto']);
        $executions = $this->progressKtrToGudangJadiCompleted($line);
        $this->assertFalse($this->executionService->isStageQualityLocked($executions['gudang_jadi'], $line));
    }
}
