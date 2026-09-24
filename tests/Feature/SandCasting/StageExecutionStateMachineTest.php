<?php

namespace Tests\Feature\SandCasting;

use App\Models\DefectType;
use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use App\Services\SandCasting\SandCastingStageExecutionService;
use App\Services\SandCasting\TravelerNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StageExecutionStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected User $operator;

    protected User $adminPpic;

    protected User $qcInspector;

    protected SandCastingStageExecutionService $service;

    protected DefectType $defectTypeKeropos;

    protected DefectType $defectTypeRetak;

    protected function setUp(): void
    {
        parent::setUp();

        $permissionPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $permissionExecution = Permission::firstOrCreate(['name' => 'access_execution']);

        $rolePpic = Role::firstOrCreate(['name' => 'ppic']);
        $rolePpic->givePermissionTo([$permissionPlanning, $permissionExecution]);

        $roleAdmin = Role::firstOrCreate(['name' => 'admin']);
        $roleAdmin->givePermissionTo([$permissionPlanning, $permissionExecution]);

        $roleQc = Role::firstOrCreate(['name' => 'admin_qc_fitting']);
        $roleQc->givePermissionTo([$permissionPlanning, $permissionExecution]);

        $this->operator = User::factory()->create([
            'name' => 'Budi Operator',
            'email' => 'operator@peroniks.com',
            'assigned_stage' => 'netto',
        ]);

        $this->adminPpic = User::factory()->create([
            'name' => 'Siti Admin PPIC',
            'email' => 'adminppic_test@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->adminPpic->assignRole('ppic');

        $this->qcInspector = User::factory()->create([
            'name' => 'Hendra QC',
            'email' => 'qc_inspector@peroniks.com',
        ]);
        $this->qcInspector->assignRole('admin_qc_fitting');

        $this->defectTypeKeropos = DefectType::create([
            'department' => 'netto',
            'name' => 'Keropos',
            'is_active' => true,
        ]);

        $this->defectTypeRetak = DefectType::create([
            'department' => 'netto',
            'name' => 'Retak',
            'is_active' => true,
        ]);

        $this->service = new SandCastingStageExecutionService;
    }

    protected function createKtrLine(array $attributes = []): SandCastingCastingResultLine
    {
        $plan = ProductionPlan::create([
            'code' => '268ET001',
            'title' => 'Rencana SC 268ET001',
            'item_code' => '4.101',
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-001',
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'customer' => 'PT SINAR METAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260920-0001',
            'scheduled_date' => '2026-09-20',
            'status' => 'ISSUED',
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
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'A214092001',
            'cast_date' => '2026-09-20',
            'furnace' => 'F-01',
            'shift' => '1',
            'operator_name' => 'Sutrisno',
            'recorded_by' => $this->adminPpic->id,
        ]);

        $travelerNumber = TravelerNumberGenerator::generateNext('2026-09-20');

        return $result->lines()->create(array_merge([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $travelerNumber,
            'qty_good' => 100,
            'qty_reject' => 2,
            'unit_weight_kg' => 3.25,
            'total_weight_kg' => 325.00,
            'print_count' => 1,
            'printed_at' => now(),
            'last_printed_by' => $this->adminPpic->id,
            'current_stage' => 'netto',
        ], $attributes));
    }

    public function test_1_new_ktr_starts_at_netto_with_netto_cut_ready(): void
    {
        $line = $this->createKtrLine();

        $this->assertEquals('netto', $line->current_stage);

        $active = $this->service->resolveActiveCheckpoint($line);
        $this->assertNotNull($active);
        $this->assertEquals('NETTO_CUT', $active['code']);
        $this->assertEquals('netto', $active['stage']);
        $this->assertEquals(SandCastingStageExecution::STATUS_READY, $active['status']);
    }

    public function test_1b_production_hasil_cor_service_flow_automatically_initializes_netto_and_netto_cut(): void
    {
        $plan = ProductionPlan::create([
            'code' => '268ET999',
            'title' => 'Rencana SC 268ET999',
            'item_code' => '4.999',
            'item_name' => 'FLANGE BESI JIS 10K 3"',
            'po_number' => 'PO-999',
            'line_number' => 1,
            'qty_planned' => 50,
            'qty_remaining' => 50,
            'customer' => 'PT SINAR METAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260922-0001',
            'scheduled_date' => '2026-09-22',
            'status' => 'ISSUED',
            'created_by' => $this->adminPpic->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 50,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '3"',
            'aisi' => 'FC250',
        ]);

        $resultService = new \App\Services\SandCasting\SandCastingCastingResultService;
        $result = $resultService->recordResult(
            headerData: [
                'heat_number' => 'A214092201',
                'cast_date' => '2026-09-22',
                'furnace' => 'F-01',
                'shift' => '1',
                'operator_name' => 'Sutrisno',
            ],
            linesData: [
                [
                    'sand_casting_casting_order_line_id' => $orderLine->id,
                    'qty_good' => 50,
                    'qty_reject' => 0,
                    'unit_weight_kg' => 4.5,
                ],
            ],
            recordedBy: $this->adminPpic->id
        );

        $createdLine = $result->lines()->first();
        $this->assertNotNull($createdLine);
        $this->assertEquals('netto', $createdLine->current_stage);

        $active = $this->service->resolveActiveCheckpoint($createdLine);
        $this->assertNotNull($active);
        $this->assertEquals('NETTO_CUT', $active['code']);
        $this->assertEquals('netto', $active['stage']);
        $this->assertEquals(SandCastingStageExecution::STATUS_READY, $active['status']);
    }

    public function test_2_historical_ktr_with_null_stage_is_rejected_and_untouched(): void
    {
        $line = $this->createKtrLine(['current_stage' => null]);

        $this->assertNull($this->service->resolveActiveCheckpoint($line));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('belum memiliki operational stage');

        $this->service->markPhysicalDone($line->traveler_number, 'netto', $this->operator->id);
    }

    public function test_3_netto_mark_physical_done_creates_waiting_defect_execution(): void
    {
        $line = $this->createKtrLine();

        $exec = $this->service->markPhysicalDone($line->traveler_number, 'netto', $this->operator->id, 'Selesai potong');

        $this->assertInstanceOf(SandCastingStageExecution::class, $exec);
        $this->assertEquals('netto', $exec->stage);
        $this->assertEquals('NETTO_CUT', $exec->checkpoint_code);
        $this->assertEquals(100, $exec->input_qty);
        $this->assertEquals(0, $exec->defect_qty);
        $this->assertEquals(100, $exec->good_qty);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_DEFECT, $exec->status);
        $this->assertEquals($this->operator->id, $exec->operator_id);
        $this->assertNotNull($exec->physical_done_at);
        $this->assertEquals('Selesai potong', $exec->notes);

        // Check active checkpoint status is now WAITING_DEFECT
        $active = $this->service->resolveActiveCheckpoint($line);
        $this->assertEquals('NETTO_CUT', $active['code']);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_DEFECT, $active['status']);
    }

    public function test_4_netto_input_comes_strictly_from_cor_qty_good(): void
    {
        $line = $this->createKtrLine(['qty_good' => 88]);

        $exec = $this->service->markPhysicalDone($line->traveler_number, 'netto', $this->operator->id);

        $this->assertEquals(88, $exec->input_qty);
    }

    public function test_5_reject_double_mark_physical_done_on_same_checkpoint(): void
    {
        $line = $this->createKtrLine();

        $this->service->markPhysicalDone($line->traveler_number, 'netto', $this->operator->id);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sudah pernah diproses fisik pada checkpoint NETTO_CUT');

        $this->service->markPhysicalDone($line->traveler_number, 'netto', $this->operator->id);
    }

    public function test_6_record_defect_validates_bounds_and_calculates_good_qty(): void
    {
        $line = $this->createKtrLine();
        $exec = $this->service->markPhysicalDone($line->traveler_number, 'netto', $this->operator->id);

        // Defect > input rejected
        try {
            $this->service->recordDefectQty($exec, 105, $this->adminPpic->id);
            $this->fail('Expected exception when defect > input');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak boleh melebihi jumlah input', $e->getMessage());
        }

        // Negative defect rejected
        try {
            $this->service->recordDefectQty($exec, -1, $this->adminPpic->id);
            $this->fail('Expected exception when defect < 0');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak boleh negatif', $e->getMessage());
        }

        // Valid defect record: input 100, defect 5 -> good 95, status WAITING_QC
        $updatedExec = $this->service->recordDefectQty($exec, 5, $this->adminPpic->id, 'Defect form #12');

        $this->assertEquals(5, $updatedExec->defect_qty);
        $this->assertEquals(95, $updatedExec->good_qty);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $updatedExec->status);
        $this->assertEquals($this->adminPpic->id, $updatedExec->defect_entered_by);
        $this->assertNotNull($updatedExec->defect_entered_at);
        $this->assertEquals('Defect form #12', $updatedExec->notes);
    }

    public function test_6b_record_defect_rejected_when_already_waiting_qc_and_quantities_remain_unchanged(): void
    {
        $line = $this->createKtrLine();
        $exec = $this->service->markPhysicalDone($line->traveler_number, 'netto', $this->operator->id);

        // Step 1: Transitions WAITING_DEFECT -> WAITING_QC
        $exec = $this->service->recordDefectQty($exec, 5, $this->adminPpic->id);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $exec->status);
        $this->assertEquals(5, $exec->defect_qty);
        $this->assertEquals(95, $exec->good_qty);

        // Step 2: Attempting to call recordDefectQty again while in WAITING_QC MUST BE REJECTED
        try {
            $this->service->recordDefectQty($exec, 10, $this->adminPpic->id);
            $this->fail('Expected exception when calling recordDefectQty on WAITING_QC status');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('sudah dalam status WAITING_QC dan tidak dapat diubah lagi', $e->getMessage());
        }

        // Ensure quantities in database remain untouched (defect=5, good=95)
        $freshExec = $exec->fresh();
        $this->assertEquals(5, $freshExec->defect_qty);
        $this->assertEquals(95, $freshExec->good_qty);
        $this->assertEquals(100, $freshExec->input_qty);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $freshExec->status);
    }

    public function test_7_qc_breakdown_mismatch_is_rejected(): void
    {
        $line = $this->createKtrLine();
        $exec = $this->service->markPhysicalDone($line->traveler_number, 'netto', $this->operator->id);
        $exec = $this->service->recordDefectQty($exec, 10, $this->adminPpic->id);

        // QC breakdown sums to 8 instead of 10
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Total klasifikasi cacat (8 PCS) tidak sesuai dengan total defect (10 PCS)');

        $this->service->verifyQcBreakdown($exec, [
            ['defect_type_id' => $this->defectTypeKeropos->id, 'qty' => 5],
            ['defect_type_id' => $this->defectTypeRetak->id, 'qty' => 3],
        ], $this->qcInspector->id);
    }

    public function test_8_qc_breakdown_success_confirms_checkpoint_and_advances_stage(): void
    {
        $line = $this->createKtrLine();
        $exec = $this->service->markPhysicalDone($line->traveler_number, 'netto', $this->operator->id);
        $exec = $this->service->recordDefectQty($exec, 10, $this->adminPpic->id);

        $confirmed = $this->service->verifyQcBreakdown($exec, [
            ['defect_type_id' => $this->defectTypeKeropos->id, 'qty' => 6],
            ['defect_type_id' => $this->defectTypeRetak->id, 'qty' => 4],
        ], $this->qcInspector->id, 'QC Lolos 90 pcs');

        $this->assertEquals(SandCastingStageExecution::STATUS_CONFIRMED, $confirmed->status);
        $this->assertEquals(10, $confirmed->defect_qty);
        $this->assertEquals(90, $confirmed->good_qty);
        $this->assertEquals($this->qcInspector->id, $confirmed->qc_verified_by);
        $this->assertNotNull($confirmed->qc_verified_at);

        // Verify child defect breakdown records
        $this->assertCount(2, $confirmed->defects);
        $this->assertDatabaseHas('sand_casting_stage_execution_defects', [
            'sand_casting_stage_execution_id' => $confirmed->id,
            'defect_type_id' => $this->defectTypeKeropos->id,
            'qty' => 6,
        ]);
        $this->assertDatabaseHas('sand_casting_stage_execution_defects', [
            'sand_casting_stage_execution_id' => $confirmed->id,
            'defect_type_id' => $this->defectTypeRetak->id,
            'qty' => 4,
        ]);

        // Netto completed -> line.current_stage must advance to bubut_od
        $this->assertEquals('bubut_od', $line->fresh()->current_stage);

        // Active checkpoint is now OD_TURNING in READY status
        $active = $this->service->resolveActiveCheckpoint($line->fresh());
        $this->assertNotNull($active);
        $this->assertEquals('OD_TURNING', $active['code']);
        $this->assertEquals('bubut_od', $active['stage']);
        $this->assertEquals(SandCastingStageExecution::STATUS_READY, $active['status']);
    }

    public function test_9_zero_defect_qc_confirmation_succeeds_without_breakdown_rows(): void
    {
        $line = $this->createKtrLine();
        $exec = $this->service->markPhysicalDone($line->traveler_number, 'netto', $this->operator->id);
        $exec = $this->service->recordDefectQty($exec, 0, $this->adminPpic->id);

        $confirmed = $this->service->verifyQcBreakdown($exec, [], $this->qcInspector->id);

        $this->assertEquals(SandCastingStageExecution::STATUS_CONFIRMED, $confirmed->status);
        $this->assertEquals(0, $confirmed->defect_qty);
        $this->assertEquals(100, $confirmed->good_qty);
        $this->assertCount(0, $confirmed->defects);

        $this->assertEquals('bubut_od', $line->fresh()->current_stage);
    }

    public function test_10_good_qty_zero_halts_and_does_not_advance_current_stage(): void
    {
        $line = $this->createKtrLine();
        $exec = $this->service->markPhysicalDone($line->traveler_number, 'netto', $this->operator->id);
        $exec = $this->service->recordDefectQty($exec, 100, $this->adminPpic->id);

        $confirmed = $this->service->verifyQcBreakdown($exec, [
            ['defect_type_id' => $this->defectTypeKeropos->id, 'qty' => 100],
        ], $this->qcInspector->id);

        $this->assertEquals(SandCastingStageExecution::STATUS_CONFIRMED, $confirmed->status);
        $this->assertEquals(0, $confirmed->good_qty);

        // Current stage MUST NOT advance to bubut_od; remains at netto (halted)
        $this->assertEquals('netto', $line->fresh()->current_stage);
        $this->assertNull($this->service->resolveActiveCheckpoint($line->fresh()));
    }

    public function test_11_confirmed_execution_is_immutable(): void
    {
        $line = $this->createKtrLine();
        $exec = $this->service->markPhysicalDone($line->traveler_number, 'netto', $this->operator->id);
        $exec = $this->service->recordDefectQty($exec, 5, $this->adminPpic->id);
        $confirmed = $this->service->verifyQcBreakdown($exec, [
            ['defect_type_id' => $this->defectTypeKeropos->id, 'qty' => 5],
        ], $this->qcInspector->id);

        // Attempting to record defect on confirmed execution must fail
        try {
            $this->service->recordDefectQty($confirmed, 10, $this->adminPpic->id);
            $this->fail('Expected exception modifying confirmed execution');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('sudah berstatus CONFIRMED', $e->getMessage());
        }

        // Attempting to re-verify confirmed execution must fail
        try {
            $this->service->verifyQcBreakdown($confirmed, [], $this->qcInspector->id);
            $this->fail('Expected exception re-verifying confirmed execution');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('sudah berstatus CONFIRMED', $e->getMessage());
        }
    }

    public function test_12_cnc_three_checkpoints_sequence_and_quantity_continuity(): void
    {
        $line = $this->createKtrLine(['qty_good' => 60]);

        // 1. NETTO: 60 -> defect 0 -> good 60
        $nettoExec = $this->service->markPhysicalDone($line->traveler_number, 'netto', $this->operator->id);
        $nettoExec = $this->service->recordDefectQty($nettoExec, 0, $this->adminPpic->id);
        $this->service->verifyQcBreakdown($nettoExec, [], $this->qcInspector->id);
        $this->assertEquals('bubut_od', $line->fresh()->current_stage);

        // 2. BUBUT OD (Physical OD + Marking): 60 -> defect 0 -> good 60 -> advances directly to bubut_cnc
        $odExec = $this->service->markPhysicalDone($line->traveler_number, 'bubut_od', $this->operator->id);
        $odExec = $this->service->recordDefectQty($odExec, 0, $this->adminPpic->id);
        $this->service->verifyQcBreakdown($odExec, [], $this->qcInspector->id);
        $this->assertEquals('bubut_cnc', $line->fresh()->current_stage);

        // 3. BUBUT CNC Checkpoint 1 (CNC_MACHINING): Input 60 -> defect 5 -> good 55
        $activeChk1 = $this->service->resolveActiveCheckpoint($line->fresh());
        $this->assertEquals('CNC_MACHINING', $activeChk1['code']);

        $cncExec1 = $this->service->markPhysicalDone($line->traveler_number, 'bubut_cnc', $this->operator->id);
        $this->assertEquals('CNC_MACHINING', $cncExec1->checkpoint_code);
        $this->assertEquals(60, $cncExec1->input_qty);

        $cncExec1 = $this->service->recordDefectQty($cncExec1, 5, $this->adminPpic->id);
        $this->service->verifyQcBreakdown($cncExec1, [
            ['defect_type_id' => $this->defectTypeKeropos->id, 'qty' => 5],
        ], $this->qcInspector->id);

        // Current stage MUST STILL be bubut_cnc!
        $this->assertEquals('bubut_cnc', $line->fresh()->current_stage);

        // 4. BUBUT CNC Checkpoint 2 (QC_POST_CNC): Input 55 -> defect 2 -> good 53
        $activeChk2 = $this->service->resolveActiveCheckpoint($line->fresh());
        $this->assertEquals('QC_POST_CNC', $activeChk2['code']);

        $cncExec2 = $this->service->markPhysicalDone($line->traveler_number, 'bubut_cnc', $this->operator->id);
        $this->assertEquals('QC_POST_CNC', $cncExec2->checkpoint_code);
        $this->assertEquals(55, $cncExec2->input_qty); // Input strictly derived from CNC_MACHINING good_qty!

        $cncExec2 = $this->service->recordDefectQty($cncExec2, 2, $this->adminPpic->id);
        $this->service->verifyQcBreakdown($cncExec2, [
            ['defect_type_id' => $this->defectTypeRetak->id, 'qty' => 2],
        ], $this->qcInspector->id);

        // Current stage MUST STILL be bubut_cnc!
        $this->assertEquals('bubut_cnc', $line->fresh()->current_stage);

        // 5. BUBUT CNC Checkpoint 3 (QC_PRE_BOR): Input 53 -> defect 3 -> good 50
        $activeChk3 = $this->service->resolveActiveCheckpoint($line->fresh());
        $this->assertEquals('QC_PRE_BOR', $activeChk3['code']);

        $cncExec3 = $this->service->markPhysicalDone($line->traveler_number, 'bubut_cnc', $this->operator->id);
        $this->assertEquals('QC_PRE_BOR', $cncExec3->checkpoint_code);
        $this->assertEquals(53, $cncExec3->input_qty); // Input strictly derived from QC_POST_CNC good_qty!

        $cncExec3 = $this->service->recordDefectQty($cncExec3, 3, $this->adminPpic->id);
        $this->service->verifyQcBreakdown($cncExec3, [
            ['defect_type_id' => $this->defectTypeKeropos->id, 'qty' => 3],
        ], $this->qcInspector->id);

        // Now that QC_PRE_BOR is CONFIRMED, stage MUST advance to bor!
        $this->assertEquals('bor', $line->fresh()->current_stage);

        // 6. BOR (BOR_DRILLING): Input must be exactly 50!
        $activeBor = $this->service->resolveActiveCheckpoint($line->fresh());
        $this->assertEquals('BOR_DRILLING', $activeBor['code']);

        $borExec = $this->service->markPhysicalDone($line->traveler_number, 'bor', $this->operator->id);
        $this->assertEquals('BOR_DRILLING', $borExec->checkpoint_code);
        $this->assertEquals(50, $borExec->input_qty); // Hard guarantee: 50 PCS only!
    }

    public function test_13_reject_stage_skipping(): void
    {
        $line = $this->createKtrLine(); // current_stage is netto

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('saat ini berada di stage NETTO. Tahap yang valid adalah NETTO, bukan BUBUT CNC');

        $this->service->markPhysicalDone($line->traveler_number, 'bubut_cnc', $this->operator->id);
    }

    public function test_14_full_pipeline_to_completion(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100]);

        $pipeline = [
            ['stage' => 'netto', 'chk' => 'NETTO_CUT', 'defect' => 5], // good 95
            ['stage' => 'bubut_od', 'chk' => 'OD_TURNING', 'defect' => 3], // good 92
            ['stage' => 'bubut_cnc', 'chk' => 'CNC_MACHINING', 'defect' => 5], // good 87
            ['stage' => 'bubut_cnc', 'chk' => 'QC_POST_CNC', 'defect' => 2], // good 85
            ['stage' => 'bubut_cnc', 'chk' => 'QC_PRE_BOR', 'defect' => 3], // good 82
            ['stage' => 'bor', 'chk' => 'BOR_DRILLING', 'defect' => 2], // good 80
            ['stage' => 'qc', 'chk' => 'QC_FINAL_INSPECTION', 'defect' => 0], // good 80
            ['stage' => 'gudang_jadi', 'chk' => 'GUDANG_RECEIVE', 'defect' => 0], // good 80
        ];

        foreach ($pipeline as $step) {
            $exec = $this->service->markPhysicalDone($line->traveler_number, $step['stage'], $this->operator->id);
            $this->assertEquals($step['chk'], $exec->checkpoint_code);

            $exec = $this->service->recordDefectQty($exec, $step['defect'], $this->adminPpic->id);

            $breakdown = [];
            if ($step['defect'] > 0) {
                $breakdown = [['defect_type_id' => $this->defectTypeKeropos->id, 'qty' => $step['defect']]];
            }

            $this->service->verifyQcBreakdown($exec, $breakdown, $this->qcInspector->id);
        }

        $this->assertEquals('completed', $line->fresh()->current_stage);
        $this->assertCount(8, $line->stageExecutions);
    }
}
