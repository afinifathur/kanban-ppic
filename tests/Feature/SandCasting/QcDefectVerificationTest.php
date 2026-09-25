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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class QcDefectVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminQc;

    protected User $adminQcFitting;

    protected User $adminUser;

    protected User $adminPpic;

    protected User $spvUser;

    protected User $unauthorizedUser;

    protected DefectType $defectPorosity;

    protected DefectType $defectRetak;

    protected DefectType $defectDimensi;

    protected SandCastingStageExecutionService $executionService;

    protected SandCastingProductionFloorQueryService $queryService;

    protected function setUp(): void
    {
        parent::setUp();

        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution']);

        $rolePpic = Role::firstOrCreate(['name' => 'ppic']);
        $rolePpic->givePermissionTo([$permPlanning, $permExecution]);

        $roleAdmin = Role::firstOrCreate(['name' => 'admin']);
        $roleAdmin->givePermissionTo([$permPlanning, $permExecution]);

        $roleSpv = Role::firstOrCreate(['name' => 'spv']);
        $roleSpv->givePermissionTo($permExecution);

        $roleQc = Role::firstOrCreate(['name' => 'qc']);
        $roleQc->givePermissionTo($permExecution);

        $roleAdminQcFitting = Role::firstOrCreate(['name' => 'admin_qc_fitting']);
        $roleAdminQcFitting->givePermissionTo($permExecution);

        // 1. Primary QC target user
        $this->adminQc = User::factory()->create([
            'name' => 'Admin QC Flange',
            'email' => 'adminqcflange@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->adminQc->assignRole('qc');

        // 2. Secondary QC user
        $this->adminQcFitting = User::factory()->create([
            'name' => 'Admin QC Fitting',
            'email' => 'adminqcfitting@peroniks.com',
            'product_scope' => 'FITTING_BESI',
        ]);
        $this->adminQcFitting->assignRole('admin_qc_fitting');

        // 3. Superadmin
        $this->adminUser = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'superadmin@peroniks.com',
        ]);
        $this->adminUser->assignRole('admin');

        // 4. PPIC User
        $this->adminPpic = User::factory()->create([
            'name' => 'Admin PPIC Flange',
            'email' => 'adminppicfl@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->adminPpic->assignRole('ppic');

        // 5. SPV User
        $this->spvUser = User::factory()->create([
            'name' => 'SPV Netto',
            'email' => 'spvnettofl@peroniks.com',
            'assigned_stage' => 'netto',
        ]);
        $this->spvUser->assignRole('spv');

        // 6. Regular unauthorized User
        $this->unauthorizedUser = User::factory()->create([
            'name' => 'Regular Operator',
            'email' => 'regular@peroniks.com',
        ]);

        // Defect Types for testing
        $this->defectPorosity = DefectType::create([
            'name' => 'Porosity',
            'department' => 'netto',
            'is_active' => true,
        ]);

        $this->defectRetak = DefectType::create([
            'name' => 'Retak',
            'department' => 'netto',
            'is_active' => true,
        ]);

        $this->defectDimensi = DefectType::create([
            'name' => 'Dimensi Tidak Sesuai',
            'department' => 'bubut_cnc',
            'is_active' => true,
        ]);

        $this->executionService = new SandCastingStageExecutionService;
        $this->queryService = new SandCastingProductionFloorQueryService;
    }

    protected function createKtrLine(array $attributes = [], ?SandCastingCastingResult $existingResult = null): SandCastingCastingResultLine
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
            'casting_order_number' => 'PCOR-'.rand(1000, 9999),
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
            'status' => 'issued',
        ]);

        if (! $existingResult) {
            $existingResult = SandCastingCastingResult::create([
                'heat_number' => 'A214092'.rand(100, 999),
                'cast_date' => now()->toDateString(),
                'furnace' => 'F1',
                'shift' => '1',
                'operator_name' => 'Budi Cor',
                'recorded_by' => $this->adminPpic->id,
                'total_qty_good' => 100,
                'total_qty_reject' => 0,
            ]);
        }

        $travelerNumber = 'KTR-'.now()->format('Ymd').'-'.sprintf('%04d', rand(1, 9999));

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
     * Helper to prepare an execution at WAITING_QC state.
     */
    protected function prepareWaitingQcExecution(string $stage = 'netto', int $defectQty = 5, ?SandCastingCastingResultLine $line = null): SandCastingStageExecution
    {
        if (! $line) {
            $line = $this->createKtrLine(['current_stage' => $stage]);
        }

        $exec = $this->executionService->markPhysicalDone($line->traveler_number, $stage, $this->spvUser->id);
        $exec = $this->executionService->recordDefectQty($exec->id, $defectQty, $this->adminPpic->id);

        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $exec->status);

        return $exec;
    }

    /**
     * TEST 1: QcVerificationAuthorizationTest
     * - QC allowed (adminqcflange, adminqcfitting, role qc, role admin)
     * - PPIC denied (403)
     * - SPV denied (403)
     * - Unauthorized denied (403 or redirect)
     */
    public function test_1_qc_verification_authorization(): void
    {
        $exec = $this->prepareWaitingQcExecution('netto', 5);

        // 1. Unauthenticated -> Redirects to login
        $responseUnauth = $this->get('/sand-casting/qc-defects');
        $responseUnauth->assertRedirect(route('login'));

        // 2. Regular user without role -> 403 Forbidden
        $responseRegular = $this->actingAs($this->unauthorizedUser)->get('/sand-casting/qc-defects');
        $responseRegular->assertStatus(403);

        $responsePostRegular = $this->actingAs($this->unauthorizedUser)->post("/sand-casting/qc-defects/{$exec->id}/verify", [
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 5],
            ],
        ]);
        $responsePostRegular->assertStatus(403);

        // 3. PPIC User cannot access QC verification -> 403 Forbidden
        $responsePpic = $this->actingAs($this->adminPpic)->get('/sand-casting/qc-defects');
        $responsePpic->assertStatus(403);

        $responsePostPpic = $this->actingAs($this->adminPpic)->post("/sand-casting/qc-defects/{$exec->id}/verify", [
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 5],
            ],
        ]);
        $responsePostPpic->assertStatus(403);

        // 4. SPV User cannot access QC verification -> 403 Forbidden
        $responseSpv = $this->actingAs($this->spvUser)->get('/sand-casting/qc-defects');
        $responseSpv->assertStatus(403);

        $responsePostSpv = $this->actingAs($this->spvUser)->post("/sand-casting/qc-defects/{$exec->id}/verify", [
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 5],
            ],
        ]);
        $responsePostSpv->assertStatus(403);

        // 5. Authorized QC Users -> 200 OK
        $responseQc = $this->actingAs($this->adminQc)->get('/sand-casting/qc-defects');
        $responseQc->assertStatus(200);

        $responseQcFitting = $this->actingAs($this->adminQcFitting)->get('/sand-casting/qc-defects');
        $responseQcFitting->assertStatus(200);

        // 6. Authorized Admin -> 200 OK
        $responseAdmin = $this->actingAs($this->adminUser)->get('/sand-casting/qc-defects');
        $responseAdmin->assertStatus(200);
    }

    /**
     * TEST 2: QcVerificationQueueFifoTest
     * - Sorted by defect_entered_at ASC, id ASC
     */
    public function test_2_qc_verification_queue_fifo(): void
    {
        $line1 = $this->createKtrLine(['current_stage' => 'netto']);
        $line2 = $this->createKtrLine(['current_stage' => 'netto']);
        $line3 = $this->createKtrLine(['current_stage' => 'netto']);

        $exec1 = $line1->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 5,
            'good_qty' => 95,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'operator_id' => $this->spvUser->id,
            'physical_done_at' => now()->subHours(6),
            'defect_entered_by' => $this->adminPpic->id,
            'defect_entered_at' => now()->subHours(4),
            'executed_at' => now()->subHours(6),
        ]);

        $exec2 = $line2->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 2,
            'good_qty' => 98,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'operator_id' => $this->spvUser->id,
            'physical_done_at' => now()->subHours(8),
            'defect_entered_by' => $this->adminPpic->id,
            'defect_entered_at' => now()->subHours(7), // Oldest defect_entered_at!
            'executed_at' => now()->subHours(8),
        ]);

        $exec3 = $line3->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 0,
            'good_qty' => 100,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'operator_id' => $this->spvUser->id,
            'physical_done_at' => now()->subHours(2),
            'defect_entered_by' => $this->adminPpic->id,
            'defect_entered_at' => now()->subHours(1), // Newest defect_entered_at
            'executed_at' => now()->subHours(2),
        ]);

        $queue = $this->queryService->getQcVerificationQueue('netto');

        $this->assertCount(3, $queue);
        // Expect FIFO: exec2 (7h ago), exec1 (4h ago), exec3 (1h ago)
        $this->assertEquals($exec2->id, $queue[0]['id']);
        $this->assertEquals($exec1->id, $queue[1]['id']);
        $this->assertEquals($exec3->id, $queue[2]['id']);
    }

    /**
     * TEST 3: QcVerificationZeroDefectTest
     * - defect_qty = 0
     * - empty breakdown [] accepted
     * - status becomes CONFIRMED
     * - no row created in sand_casting_stage_execution_defects
     */
    public function test_3_qc_verification_zero_defect(): void
    {
        $exec = $this->prepareWaitingQcExecution('netto', 0);
        $this->assertEquals(0, $exec->defect_qty);

        $response = $this->actingAs($this->adminQc)->postJson("/sand-casting/qc-defects/{$exec->id}/verify", [
            'defects' => [],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'execution_id' => $exec->id,
                    'status' => SandCastingStageExecution::STATUS_CONFIRMED,
                    'defect_qty' => 0,
                ],
            ]);

        $exec->refresh();
        $this->assertEquals(SandCastingStageExecution::STATUS_CONFIRMED, $exec->status);
        $this->assertEquals($this->adminQc->id, $exec->qc_verified_by);
        $this->assertNotNull($exec->qc_verified_at);
        $this->assertCount(0, $exec->defects);
    }

    /**
     * TEST 4: QcVerificationBreakdownMatchTest
     * - Exact sum match accepted
     * - Breakdown rows created
     */
    public function test_4_qc_verification_breakdown_match(): void
    {
        $exec = $this->prepareWaitingQcExecution('netto', 5);

        $breakdown = [
            [
                'defect_type_id' => $this->defectPorosity->id,
                'qty' => 3,
                'notes' => 'Porosity pinhole permukaan',
            ],
            [
                'defect_type_id' => $this->defectRetak->id,
                'qty' => 2,
                'notes' => 'Retak leher flange',
            ],
        ];

        $response = $this->actingAs($this->adminQc)->postJson("/sand-casting/qc-defects/{$exec->id}/verify", [
            'defects' => $breakdown,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'execution_id' => $exec->id,
                    'status' => SandCastingStageExecution::STATUS_CONFIRMED,
                ],
            ]);

        $exec->refresh();
        $this->assertEquals(SandCastingStageExecution::STATUS_CONFIRMED, $exec->status);
        $this->assertCount(2, $exec->defects);

        $this->assertDatabaseHas('sand_casting_stage_execution_defects', [
            'sand_casting_stage_execution_id' => $exec->id,
            'defect_type_id' => $this->defectPorosity->id,
            'qty' => 3,
            'notes' => 'Porosity pinhole permukaan',
        ]);

        $this->assertDatabaseHas('sand_casting_stage_execution_defects', [
            'sand_casting_stage_execution_id' => $exec->id,
            'defect_type_id' => $this->defectRetak->id,
            'qty' => 2,
            'notes' => 'Retak leher flange',
        ]);
    }

    /**
     * TEST 5: QcVerificationBreakdownMismatchTest
     * - Less than defect_qty rejected (422)
     * - Greater than defect_qty rejected (422)
     * - State remains WAITING_QC
     */
    public function test_5_qc_verification_breakdown_mismatch(): void
    {
        $exec = $this->prepareWaitingQcExecution('netto', 5);

        // Case A: Sum is 4 (less than 5)
        $responseLess = $this->actingAs($this->adminQc)->postJson("/sand-casting/qc-defects/{$exec->id}/verify", [
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 4],
            ],
        ]);
        $responseLess->assertStatus(422);

        $exec->refresh();
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $exec->status);

        // Case B: Sum is 6 (more than 5)
        $responseMore = $this->actingAs($this->adminQc)->postJson("/sand-casting/qc-defects/{$exec->id}/verify", [
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 3],
                ['defect_type_id' => $this->defectRetak->id, 'qty' => 3],
            ],
        ]);
        $responseMore->assertStatus(422);

        $exec->refresh();
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $exec->status);
    }

    /**
     * TEST 6: QcVerificationAuditTrailTest
     * - qc_verified_by and qc_verified_at are populated
     * - PPIC fields (defect_entered_by, defect_entered_at, input_qty, defect_qty, good_qty) remain unchanged
     */
    public function test_6_qc_verification_audit_trail(): void
    {
        $exec = $this->prepareWaitingQcExecution('netto', 3);
        $originalPpicId = $exec->defect_entered_by;
        $originalPpicAt = $exec->defect_entered_at;
        $originalInputQty = $exec->input_qty;
        $originalDefectQty = $exec->defect_qty;
        $originalGoodQty = $exec->good_qty;

        $response = $this->actingAs($this->adminQc)->postJson("/sand-casting/qc-defects/{$exec->id}/verify", [
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 3],
            ],
        ]);
        $response->assertStatus(200);

        $exec->refresh();
        $this->assertEquals($this->adminQc->id, $exec->qc_verified_by);
        $this->assertNotNull($exec->qc_verified_at);

        // Ensure PPIC fields are intact and unchanged
        $this->assertEquals($originalPpicId, $exec->defect_entered_by);
        $this->assertEquals($originalPpicAt->toDateTimeString(), $exec->defect_entered_at->toDateTimeString());
        $this->assertEquals($originalInputQty, $exec->input_qty);
        $this->assertEquals($originalDefectQty, $exec->defect_qty);
        $this->assertEquals($originalGoodQty, $exec->good_qty);
    }

    /**
     * TEST 7: QcVerificationStageAdvanceTest
     * - good_qty > 0: KTR advances to next stage
     * - good_qty == 0: KTR is halted (current_stage does not advance, resolveActiveCheckpoint returns null)
     */
    public function test_7_qc_verification_stage_advance(): void
    {
        // Case 1: good_qty > 0 (100 in, 5 defect, 95 good)
        $line1 = $this->createKtrLine(['current_stage' => 'netto']);
        $exec1 = $this->prepareWaitingQcExecution('netto', 5, $line1);

        $this->actingAs($this->adminQc)->postJson("/sand-casting/qc-defects/{$exec1->id}/verify", [
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 5],
            ],
        ])->assertStatus(200);

        $line1->refresh();
        $this->assertEquals('bubut_od', $line1->current_stage);

        // Case 2: good_qty == 0 (100 in, 100 defect, 0 good)
        $line2 = $this->createKtrLine(['current_stage' => 'netto']);
        $exec2 = $this->prepareWaitingQcExecution('netto', 100, $line2);

        $this->actingAs($this->adminQc)->postJson("/sand-casting/qc-defects/{$exec2->id}/verify", [
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 100],
            ],
        ])->assertStatus(200);

        $line2->refresh();
        $this->assertEquals('netto', $line2->current_stage);
        $this->assertNull($this->executionService->resolveActiveCheckpoint($line2));
    }

    /**
     * TEST 8: QcVerificationCncCheckpointsTest
     * - Multi-checkpoint CNC (CNC_MACHINING, QC_POST_CNC, QC_PRE_BOR) are independent
     */
    public function test_8_qc_verification_cnc_checkpoints(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'bubut_cnc']);

        // Checkpoint 1: CNC_MACHINING
        $execCnc = $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 100,
            'defect_qty' => 2,
            'good_qty' => 98,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'operator_id' => $this->spvUser->id,
            'physical_done_at' => now()->subHours(3),
            'defect_entered_by' => $this->adminPpic->id,
            'defect_entered_at' => now()->subHours(2),
            'executed_at' => now()->subHours(3),
        ]);

        // Checkpoint 2: QC_POST_CNC
        $execPost = $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'QC_POST_CNC',
            'input_qty' => 98,
            'defect_qty' => 1,
            'good_qty' => 97,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'operator_id' => $this->spvUser->id,
            'physical_done_at' => now()->subHours(2),
            'defect_entered_by' => $this->adminPpic->id,
            'defect_entered_at' => now()->subHours(1),
            'executed_at' => now()->subHours(2),
        ]);

        $queueCnc = $this->queryService->getQcVerificationQueue('bubut_cnc');
        $this->assertCount(2, $queueCnc);
        $this->assertEquals('CNC_MACHINING', $queueCnc[0]['checkpoint_code']);
        $this->assertEquals('QC_POST_CNC', $queueCnc[1]['checkpoint_code']);

        // Verify Checkpoint 1 independently
        $this->actingAs($this->adminQc)->postJson("/sand-casting/qc-defects/{$execCnc->id}/verify", [
            'defects' => [
                ['defect_type_id' => $this->defectDimensi->id, 'qty' => 2],
            ],
        ])->assertStatus(200);

        $execCnc->refresh();
        $this->assertEquals(SandCastingStageExecution::STATUS_CONFIRMED, $execCnc->status);

        // Checkpoint 2 should still be WAITING_QC
        $execPost->refresh();
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $execPost->status);
    }

    /**
     * TEST 9: QcVerificationSummaryCounterTest
     * - WAITING_QC count across all 6 stages
     */
    public function test_9_qc_verification_summary_counter(): void
    {
        $line1 = $this->createKtrLine(['current_stage' => 'netto']);
        $line2 = $this->createKtrLine(['current_stage' => 'bubut_od']);
        $line3 = $this->createKtrLine(['current_stage' => 'bubut_cnc']);

        $line1->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 4,
            'good_qty' => 96,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'operator_id' => $this->spvUser->id,
            'defect_entered_by' => $this->adminPpic->id,
            'defect_entered_at' => now()->subMinutes(30),
            'executed_at' => now()->subHours(1),
        ]);

        $line2->stageExecutions()->create([
            'stage' => 'bubut_od',
            'checkpoint_code' => 'BUBUT_OD_FACING',
            'input_qty' => 96,
            'defect_qty' => 2,
            'good_qty' => 94,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'operator_id' => $this->spvUser->id,
            'defect_entered_by' => $this->adminPpic->id,
            'defect_entered_at' => now()->subMinutes(20),
            'executed_at' => now()->subHours(1),
        ]);

        $line3->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 94,
            'defect_qty' => 3,
            'good_qty' => 91,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'operator_id' => $this->spvUser->id,
            'defect_entered_by' => $this->adminPpic->id,
            'defect_entered_at' => now()->subMinutes(10),
            'executed_at' => now()->subHours(1),
        ]);

        $summary = $this->queryService->getQcVerificationSummary();

        $this->assertEquals(9, $summary['waiting_qc_defect_pcs']);
        $this->assertEquals(3, $summary['waiting_qc_count']);
        $this->assertEquals(1, $summary['stage_counts']['netto']);
        $this->assertEquals(1, $summary['stage_counts']['bubut_od']);
        $this->assertEquals(1, $summary['stage_counts']['bubut_cnc']);
        $this->assertEquals(0, $summary['stage_counts']['bor']);
        $this->assertEquals(0, $summary['stage_counts']['qc']);
        $this->assertEquals(0, $summary['stage_counts']['gudang_jadi']);
    }
}
