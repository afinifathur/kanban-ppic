<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use App\Services\SandCasting\SandCastingStageAuthorizationService;
use App\Services\SandCasting\SandCastingStageExecutionService;
use App\Services\SandCasting\TravelerNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StageAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $ppicUser;

    protected User $spvNetto;

    protected User $spvBubutOd;

    protected User $spvMarking;

    protected User $spvBubutCnc;

    protected User $spvBor;

    protected User $spvQc;

    protected User $spvGudangJadi;

    protected User $spvUnassigned;

    protected User $qcFittingUser;

    protected SandCastingStageAuthorizationService $authService;

    protected SandCastingStageExecutionService $executionService;

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
            'email' => 'admin_sc@peroniks.com',
        ]);
        $this->adminUser->assignRole('admin');

        $this->ppicUser = User::factory()->create([
            'name' => 'PPIC Sand Casting',
            'email' => 'ppic_sc@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->ppicUser->assignRole('ppic');

        $this->spvNetto = User::factory()->create([
            'name' => 'SPV Netto',
            'email' => 'spv_netto@peroniks.com',
            'assigned_stage' => 'netto',
        ]);
        $this->spvNetto->assignRole('spv');

        $this->spvBubutOd = User::factory()->create([
            'name' => 'SPV Bubut OD',
            'email' => 'spv_od@peroniks.com',
            'assigned_stage' => 'bubut_od',
        ]);
        $this->spvBubutOd->assignRole('spv');

        $this->spvMarking = User::factory()->create([
            'name' => 'SPV Marking',
            'email' => 'spv_marking@peroniks.com',
            'assigned_stage' => 'marking',
        ]);
        $this->spvMarking->assignRole('spv');

        $this->spvBubutCnc = User::factory()->create([
            'name' => 'SPV Bubut CNC',
            'email' => 'spv_cnc@peroniks.com',
            'assigned_stage' => 'bubut_cnc',
        ]);
        $this->spvBubutCnc->assignRole('spv');

        $this->spvBor = User::factory()->create([
            'name' => 'SPV Bor',
            'email' => 'spv_bor@peroniks.com',
            'assigned_stage' => 'bor',
        ]);
        $this->spvBor->assignRole('spv');

        $this->spvQc = User::factory()->create([
            'name' => 'SPV QC Sand Casting',
            'email' => 'spv_qc@peroniks.com',
            'assigned_stage' => 'qc',
        ]);
        $this->spvQc->assignRole('spv');

        $this->spvGudangJadi = User::factory()->create([
            'name' => 'SPV Gudang Jadi',
            'email' => 'spv_gudang@peroniks.com',
            'assigned_stage' => 'gudang_jadi',
        ]);
        $this->spvGudangJadi->assignRole('spv');

        $this->spvUnassigned = User::factory()->create([
            'name' => 'SPV Tanpa Stage',
            'email' => 'spv_null@peroniks.com',
            'assigned_stage' => null,
        ]);
        $this->spvUnassigned->assignRole('spv');

        $this->qcFittingUser = User::factory()->create([
            'name' => 'Inspector QC Fitting',
            'email' => 'qc_fitting@peroniks.com',
        ]);
        $this->qcFittingUser->assignRole('admin_qc_fitting');

        $this->authService = new SandCastingStageAuthorizationService;
        $this->executionService = new SandCastingStageExecutionService;
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
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'H'.rand(1000, 9999),
            'cast_date' => '2026-09-20',
            'shift' => 1,
            'furnace' => 'F1',
            'recorded_by' => $this->adminUser->id,
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
            'qty_poured' => 100,
            'qty_good' => 95,
            'qty_reject' => 5,
            'current_stage' => 'netto',
            'is_urgent' => false,
        ], $attributes);

        if (! isset($lineAttributes['traveler_number'])) {
            $lineAttributes['traveler_number'] = TravelerNumberGenerator::generateNext('2026-09-20');
        }

        return SandCastingCastingResultLine::create($lineAttributes);
    }

    /**
     * Requirement A: SPV with assigned_stage = netto can execute netto when KTR is ready.
     */
    public function test_requirement_a_spv_netto_can_execute_netto(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto', 'qty_good' => 50]);

        $response = $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line->traveler_number,
            'notes' => 'Netto cutting by SPV Netto',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.stage', 'netto');
        $response->assertJsonPath('data.status', 'WAITING_DEFECT');
        $response->assertJsonPath('data.input_qty', 50);

        $this->assertDatabaseHas('sand_casting_stage_executions', [
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'status' => 'WAITING_DEFECT',
            'operator_id' => $this->spvNetto->id,
        ]);
    }

    /**
     * Requirement B: SPV with assigned_stage = netto CANNOT execute bubut_od.
     */
    public function test_requirement_b_spv_netto_cannot_execute_bubut_od(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'bubut_od', 'qty_good' => 50]);

        $response = $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/bubut-od/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 0,
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('SPV', $response->json('message'));

        $this->assertDatabaseMissing('sand_casting_stage_executions', [
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'bubut_od',
        ]);
    }

    /**
     * Requirement C: SPV with assigned_stage = netto CANNOT execute marking.
     */
    public function test_requirement_c_spv_netto_cannot_execute_marking(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'marking', 'qty_good' => 50]);

        $response = $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/marking/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 0,
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);

        $this->assertDatabaseMissing('sand_casting_stage_executions', [
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'marking',
        ]);
    }

    /**
     * Requirement D: SPV with assigned_stage = netto CANNOT execute bubut_cnc.
     */
    public function test_requirement_d_spv_netto_cannot_execute_bubut_cnc(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'bubut_cnc', 'qty_good' => 50]);

        $response = $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/bubut-cnc/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 0,
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);

        $this->assertDatabaseMissing('sand_casting_stage_executions', [
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'bubut_cnc',
        ]);
    }

    /**
     * Requirement E: SPV with assigned_stage = netto CANNOT execute bor.
     */
    public function test_requirement_e_spv_netto_cannot_execute_bor(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'bor', 'qty_good' => 50]);

        $response = $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/bor/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 0,
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);

        $this->assertDatabaseMissing('sand_casting_stage_executions', [
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'bor',
        ]);
    }

    /**
     * Requirement F: SPV with assigned_stage = netto CANNOT execute qc.
     */
    public function test_requirement_f_spv_netto_cannot_execute_qc(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'qc', 'qty_good' => 50]);

        $response = $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/qc/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 0,
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);

        $this->assertDatabaseMissing('sand_casting_stage_executions', [
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'qc',
        ]);
    }

    /**
     * Requirement G: SPV with assigned_stage = netto CANNOT execute gudang_jadi.
     */
    public function test_requirement_g_spv_netto_cannot_execute_gudang_jadi(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'gudang_jadi', 'qty_good' => 50]);

        $response = $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/gudang-jadi/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 0,
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);

        $this->assertDatabaseMissing('sand_casting_stage_executions', [
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'gudang_jadi',
        ]);
    }

    /**
     * Requirement H: SPV with assigned_stage = NULL cannot execute any operational stage.
     */
    public function test_requirement_h_spv_unassigned_cannot_execute_any_stage(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto', 'qty_good' => 50]);

        $response = $this->actingAs($this->spvUnassigned)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 0,
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('assigned_stage', $response->json('message'));

        $this->assertDatabaseMissing('sand_casting_stage_executions', [
            'sand_casting_casting_result_line_id' => $line->id,
        ]);
    }

    /**
     * Requirement I & J: SPV assigned_stage = bubut_cnc allows CNC operational stage at process level.
     */
    public function test_requirement_i_and_j_spv_bubut_cnc_authorized_at_process_level(): void
    {
        // Advance line to bubut_cnc stage
        $line = $this->createKtrLine(['current_stage' => 'bubut_cnc', 'qty_good' => 50]);

        // Prior confirmed executions for NETTO, OD, and MARKING
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 50,
            'defect_qty' => 0,
            'good_qty' => 50,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->spvNetto->id,
            'executed_at' => now()->subHours(3),
        ]);

        $line->stageExecutions()->create([
            'stage' => 'bubut_od',
            'checkpoint_code' => 'OD_TURNING',
            'input_qty' => 50,
            'defect_qty' => 0,
            'good_qty' => 50,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->spvBubutOd->id,
            'executed_at' => now()->subHours(2),
        ]);

        $line->stageExecutions()->create([
            'stage' => 'marking',
            'checkpoint_code' => 'MARKING_STAMP',
            'input_qty' => 50,
            'defect_qty' => 0,
            'good_qty' => 50,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->spvMarking->id,
            'executed_at' => now()->subHour(),
        ]);

        // SPV Bubut CNC executes first CNC checkpoint (CNC_MACHINING)
        $response = $this->actingAs($this->spvBubutCnc)->postJson('/sand-casting/scan/bubut-cnc/execute', [
            'traveler_number' => $line->traveler_number,
            'notes' => 'CNC operation complete',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', 'WAITING_DEFECT');
        $response->assertJsonPath('data.input_qty', 50);

        $this->assertDatabaseHas('sand_casting_stage_executions', [
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'status' => 'WAITING_DEFECT',
            'operator_id' => $this->spvBubutCnc->id,
        ]);
    }

    /**
     * Requirement K & L: Unauthorized request creates NO execution row and modifies no data.
     */
    public function test_requirement_k_and_l_unauthorized_request_creates_no_execution_and_leaves_data_intact(): void
    {
        $line = $this->createKtrLine([
            'current_stage' => 'netto',
            'qty_good' => 90,
            'qty_reject' => 10,
        ]);

        $initialExecutionCount = SandCastingStageExecution::count();

        // SPV Netto tries to execute bubut_cnc on KTR
        $response = $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/bubut-cnc/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 5,
            'notes' => 'Unauthorized attempt',
        ]);

        $response->assertStatus(403);

        // K: No execution created
        $this->assertSame($initialExecutionCount, SandCastingStageExecution::count());

        // L: No attributes modified
        $freshLine = $line->fresh();
        $this->assertSame('netto', $freshLine->current_stage);
        $this->assertSame(90, (int) $freshLine->qty_good);
        $this->assertSame(10, (int) $freshLine->qty_reject);
    }

    /**
     * Requirement M: Admin and PPIC access remains compatible and authorized.
     */
    public function test_requirement_m_admin_and_ppic_access_preserved(): void
    {
        $line1 = $this->createKtrLine(['current_stage' => 'netto', 'qty_good' => 30]);
        $line2 = $this->createKtrLine(['current_stage' => 'netto', 'qty_good' => 40]);

        // Admin execution
        $responseAdmin = $this->actingAs($this->adminUser)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line1->traveler_number,
            'defect_qty' => 0,
        ]);
        $responseAdmin->assertStatus(200);

        // PPIC execution
        $responsePpic = $this->actingAs($this->ppicUser)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line2->traveler_number,
            'defect_qty' => 0,
        ]);
        $responsePpic->assertStatus(200);
    }

    /**
     * Requirement N: Historical KTR with current_stage = NULL remains rejected.
     */
    public function test_requirement_n_historical_null_stage_ktr_rejected_by_state_machine(): void
    {
        $historicalLine = $this->createKtrLine([
            'current_stage' => null,
            'qty_good' => 50,
        ]);

        $response = $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $historicalLine->traveler_number,
            'defect_qty' => 0,
        ]);

        // Authorization passed for netto, but state machine rejects null stage with 422
        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('belum memiliki operational stage', $response->json('message'));
    }

    /**
     * Requirement O: Authorization failure (403) and State-Machine failure (422) are strictly distinguishable.
     */
    public function test_requirement_o_authorization_failure_403_and_state_machine_failure_422_are_distinguishable(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto', 'qty_good' => 50]);

        // Case 1: SPV Bubut CNC executes bubut-cnc on a KTR currently at netto
        // Authorization check passes (SPV CNC is allowed to request bubut-cnc)
        // State machine check fails (KTR is not at bubut_cnc) -> Expect 422
        $responseStateMachineFail = $this->actingAs($this->spvBubutCnc)->postJson('/sand-casting/scan/bubut-cnc/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 0,
        ]);
        $responseStateMachineFail->assertStatus(422);
        $responseStateMachineFail->assertJsonPath('success', false);
        $this->assertStringContainsString('saat ini berada di stage NETTO', $responseStateMachineFail->json('message'));

        // Case 2: SPV Netto executes bubut-cnc on KTR currently at netto
        // Authorization check fails (SPV Netto cannot request bubut-cnc) -> Expect 403
        $responseAuthFail = $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/bubut-cnc/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 0,
        ]);
        $responseAuthFail->assertStatus(403);
        $responseAuthFail->assertJsonPath('success', false);
        $this->assertStringContainsString('tidak memiliki hak akses', $responseAuthFail->json('message'));

        // Case 3: SPV Netto executes netto on KTR currently at netto
        // Authorization passes, State machine passes -> Expect 200
        $responseSuccess = $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 0,
        ]);
        $responseSuccess->assertStatus(200);
        $responseSuccess->assertJsonPath('success', true);
    }

    /**
     * Requirement P: Scanner page view authorization.
     */
    public function test_scanner_page_view_authorization(): void
    {
        // SPV Netto can open netto scanner page
        $responseNetto = $this->actingAs($this->spvNetto)->get('/sand-casting/scan/netto');
        $responseNetto->assertStatus(200);

        // SPV Netto cannot open bubut-od scanner page
        $responseOd = $this->actingAs($this->spvNetto)->get('/sand-casting/scan/stage/bubut-od');
        $responseOd->assertStatus(403);
    }

    /**
     * Requirement Q: admin_qc_fitting is NOT granted generic Sand Casting SPV stage access.
     */
    public function test_admin_qc_fitting_cannot_execute_sand_casting_spv_stages(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto', 'qty_good' => 50]);

        $response = $this->actingAs($this->qcFittingUser)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 0,
        ]);

        $response->assertStatus(403);
        $this->assertFalse($this->authService->canAccessStage($this->qcFittingUser, 'netto'));
    }

    /**
     * Service direct unit test: Canonical normalization and authorization checks.
     */
    public function test_service_normalization_and_exceptions(): void
    {
        $this->assertSame('bubut_cnc', SandCastingStageAuthorizationService::normalizeStage('bubut-cnc'));
        $this->assertSame('bubut_od', SandCastingStageAuthorizationService::normalizeStage('bubut-od'));
        $this->assertSame('gudang_jadi', SandCastingStageAuthorizationService::normalizeStage('gudang-jadi'));
        $this->assertSame('netto', SandCastingStageAuthorizationService::normalizeStage('netto'));
        $this->assertNull(SandCastingStageAuthorizationService::normalizeStage('invalid_stage_xyz'));

        // Invalid stage throws InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $this->authService->authorize($this->spvNetto, 'invalid_stage');
    }
}
