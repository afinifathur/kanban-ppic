<?php

namespace Tests\Feature\SandCasting;

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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SpvOperationalScannerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $spvNetto;

    protected User $spvBubutOd;

    protected User $spvBubutCnc;

    protected SandCastingStageExecutionService $executionService;

    protected SandCastingProductionFloorQueryService $queryService;

    protected function setUp(): void
    {
        parent::setUp();

        $accessPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution']);

        $roleAdmin = Role::firstOrCreate(['name' => 'admin']);
        $roleAdmin->givePermissionTo([$accessPlanning, $accessExecution]);

        $roleSpv = Role::firstOrCreate(['name' => 'spv']);
        $roleSpv->givePermissionTo([$accessExecution]);

        $this->admin = User::factory()->create([
            'name' => 'Admin PPIC',
            'email' => 'admin@peroniks.com',
        ]);
        $this->admin->assignRole('admin');

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

        $this->spvBubutCnc = User::factory()->create([
            'name' => 'SPV Bubut CNC',
            'email' => 'spv_cnc@peroniks.com',
            'assigned_stage' => 'bubut_cnc',
        ]);
        $this->spvBubutCnc->assignRole('spv');

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
            'current_stage' => 'netto',
            'is_urgent' => false,
        ], $attributes);

        if (! isset($lineAttributes['traveler_number'])) {
            $lineAttributes['traveler_number'] = TravelerNumberGenerator::generateNext('2026-09-20');
        }

        return SandCastingCastingResultLine::create($lineAttributes);
    }

    /**
     * Requirement A: SPV netto can open netto scanner page.
     */
    public function test_a_spv_netto_can_open_netto_scanner(): void
    {
        $response = $this->actingAs($this->spvNetto)->get('/sand-casting/scan/netto');
        $response->assertStatus(200);
        $response->assertSee('SCANNER OPERASIONAL');
        $response->assertSee('NETTO');

        // Main scan entry redirects SPV to their assigned stage
        $responseIndex = $this->actingAs($this->spvNetto)->get('/sand-casting/scan');
        $responseIndex->assertRedirect(route('sand-casting.scan.stage', 'netto'));
    }

    /**
     * Requirement B: SPV netto cannot open bubut_od scanner.
     */
    public function test_b_spv_netto_cannot_open_bubut_od_scanner(): void
    {
        $response = $this->actingAs($this->spvNetto)->get('/sand-casting/scan/stage/bubut-od');
        $response->assertStatus(403);
    }

    /**
     * Requirement C & D: SPV netto can scan valid KTR and receive full identity & active checkpoint.
     */
    public function test_c_and_d_scanner_returns_ktr_identity_and_active_checkpoint(): void
    {
        $line = $this->createKtrLine([
            'qty_good' => 85,
            'current_stage' => 'netto',
        ]);

        $response = $this->actingAs($this->spvNetto)->getJson("/sand-casting/scan/ktr/{$line->traveler_number}");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.traveler_number', $line->traveler_number);
        $response->assertJsonPath('data.current_stage', 'netto');
        $response->assertJsonPath('data.active_checkpoint', 'NETTO_CUT');
        $response->assertJsonPath('data.operational_status', 'READY');
        $response->assertJsonPath('data.current_input_qty', 85);
        $response->assertJsonPath('data.heat_number', $line->castingResult->heat_number);
        $response->assertJsonPath('data.production_code', $line->productionPlan->code);
    }

    /**
     * Requirement E: Scanner returns server-calculated input quantity.
     */
    public function test_e_scanner_returns_server_calculated_input_quantity(): void
    {
        $line = $this->createKtrLine(['qty_good' => 120, 'current_stage' => 'netto']);

        $response = $this->actingAs($this->spvNetto)->getJson("/sand-casting/scan/ktr/{$line->traveler_number}");

        $response->assertStatus(200);
        $this->assertSame(120, $response->json('data.current_input_qty'));
    }

    /**
     * Requirement F, G, H, I: SPV executes physical completion -> markPhysicalDone() -> WAITING_DEFECT -> no stage skipping.
     */
    public function test_f_to_i_spv_executes_physical_done_transitions_to_waiting_defect(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        $response = $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line->traveler_number,
            'notes' => 'Potong netto selesai shift 1',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.stage', 'netto');
        $response->assertJsonPath('data.checkpoint_code', 'NETTO_CUT');
        $response->assertJsonPath('data.status', 'WAITING_DEFECT');
        $response->assertJsonPath('data.input_qty', 100);
        $response->assertJsonPath('data.defect_qty', 0);
        $response->assertJsonPath('data.good_qty', 100);
        $response->assertJsonPath('data.operator_id', $this->spvNetto->id);

        // Verify Database Execution Record
        $this->assertDatabaseHas('sand_casting_stage_executions', [
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'input_qty' => 100,
            'good_qty' => 100,
            'defect_qty' => 0,
            'operator_id' => $this->spvNetto->id,
            'notes' => 'Potong netto selesai shift 1',
        ]);

        // In decoupled model: current_stage advances immediately to 'bubut_od'
        $line->refresh();
        $this->assertSame('bubut_od', $line->current_stage);

        // Verify lookup query service now returns READY status at bubut_od
        $freshLookup = $this->queryService->findByTraveler($line->traveler_number);
        $this->assertSame('READY', $freshLookup['operational_status']);
    }

    /**
     * Requirement J: SPV cannot execute another operational stage (HTTP 403).
     */
    public function test_j_spv_cannot_execute_unauthorized_stage(): void
    {
        $line = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'bubut_od']);

        $response = $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/bubut-od/execute', [
            'traveler_number' => $line->traveler_number,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('sand_casting_stage_executions', [
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'bubut_od',
        ]);
    }

    /**
     * Requirement K: Historical KTR with current_stage = NULL is rejected.
     */
    public function test_k_historical_null_stage_ktr_is_rejected(): void
    {
        $historicalLine = $this->createKtrLine(['current_stage' => null, 'qty_good' => 50]);

        $response = $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $historicalLine->traveler_number,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('belum memiliki operational stage', $response->json('message'));
    }

    /**
     * Requirement L: KTR in wrong current_stage is rejected by state machine.
     */
    public function test_l_ktr_in_wrong_current_stage_is_rejected_by_state_machine(): void
    {
        // Line is at 'netto', but SPV CNC calls execute on 'bubut_cnc'
        $line = $this->createKtrLine(['current_stage' => 'netto', 'qty_good' => 50]);

        $response = $this->actingAs($this->spvBubutCnc)->postJson('/sand-casting/scan/bubut-cnc/execute', [
            'traveler_number' => $line->traveler_number,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('saat ini berada di stage NETTO', $response->json('message'));
    }

    /**
     * Requirement M & Q: Repeated execution of already processed checkpoint / double tap is rejected.
     */
    public function test_m_and_q_repeated_execution_is_rejected_and_no_duplicates_created(): void
    {
        $line = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'netto']);

        // First execution succeeds
        $firstResponse = $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line->traveler_number,
        ]);
        $firstResponse->assertStatus(200);

        $executionCount = SandCastingStageExecution::where('sand_casting_casting_result_line_id', $line->id)->count();
        $this->assertSame(1, $executionCount);

        // Second duplicate execution attempt on same checkpoint
        $secondResponse = $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line->traveler_number,
        ]);

        $secondResponse->assertStatus(422);
        $secondResponse->assertJsonPath('success', false);
        $this->assertNotEmpty($secondResponse->json('message'));

        // No duplicate execution row created
        $this->assertSame(1, SandCastingStageExecution::where('sand_casting_casting_result_line_id', $line->id)->count());
    }

    /**
     * Requirement N & O & P: Unauthorized request produces 403, state machine produces 422, leaves state intact.
     */
    public function test_n_o_p_unauthorized_and_failed_requests_leave_state_intact(): void
    {
        $line = $this->createKtrLine(['qty_good' => 75, 'qty_reject' => 5, 'current_stage' => 'netto']);

        // 1. Unauthorized attempt (403)
        $authFailResponse = $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/bubut-cnc/execute', [
            'traveler_number' => $line->traveler_number,
        ]);
        $authFailResponse->assertStatus(403);

        // 2. State Machine failure attempt (422)
        $smFailResponse = $this->actingAs($this->spvBubutCnc)->postJson('/sand-casting/scan/bubut-cnc/execute', [
            'traveler_number' => $line->traveler_number,
        ]);
        $smFailResponse->assertStatus(422);

        // Data remains completely untouched
        $freshLine = $line->fresh();
        $this->assertSame('netto', $freshLine->current_stage);
        $this->assertSame(75, (int) $freshLine->qty_good);
        $this->assertSame(5, (int) $freshLine->qty_reject);
        $this->assertSame(0, SandCastingStageExecution::where('sand_casting_casting_result_line_id', $line->id)->count());
    }

    /**
     * TEST A: Fresh NETTO KTR has current_stage = netto, active_checkpoint = NETTO_CUT, and operational_status = READY.
     */
    public function test_a_fresh_netto_ktr_is_ready(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        $lookup = $this->queryService->findByTraveler($line->traveler_number);
        $this->assertSame('netto', $lookup['current_stage']);
        $this->assertSame('NETTO_CUT', $lookup['active_checkpoint']);
        $this->assertSame('READY', $lookup['operational_status']);
        $this->assertSame(100, $lookup['current_input_qty']);
    }

    /**
     * TEST B: NETTO physical done immediately advances current_stage to bubut_od, allowing OD execution.
     */
    public function test_b_netto_physical_done_allows_od_execution(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        $this->executionService->markPhysicalDone($line->traveler_number, 'netto', (int) $this->spvNetto->id);
        $line->refresh();

        $this->assertSame('bubut_od', $line->current_stage);

        $lookup = $this->queryService->findByTraveler($line->traveler_number);
        $this->assertSame('bubut_od', $lookup['current_stage']);
        $this->assertSame('OD_TURNING', $lookup['active_checkpoint']);
        $this->assertSame('READY', $lookup['operational_status']);
        $this->assertSame(100, $lookup['current_input_qty']);
    }

    /**
     * TEST C: If a KTR whose stage is already completed or has no active checkpoint is inspected, operational status is not READY.
     */
    public function test_c_scan_completed_checkpoint_has_no_active_checkpoint_and_not_ready(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        // Create an execution on netto manually without promoting stage to test un-advanced/duplicate state
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 0,
            'good_qty' => 100,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'operator_id' => $this->spvNetto->id,
            'physical_done_at' => now(),
            'executed_at' => now(),
        ]);

        $lookup = $this->queryService->findByTraveler($line->traveler_number);
        $this->assertNull($lookup['active_checkpoint']);
        $this->assertSame('WAITING_DEFECT', $lookup['operational_status']);
        $this->assertNotSame('READY', $lookup['operational_status']);
    }

    /**
     * TEST D: Duplicate NETTO execution is strictly rejected by backend.
     */
    public function test_d_duplicate_netto_execution_rejected(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line->traveler_number,
        ])->assertStatus(200);

        // Attempting to execute netto again must fail with 422
        $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line->traveler_number,
        ])->assertStatus(422);
    }

    /**
     * TEST E: Duplicate OD execution is strictly rejected by backend.
     */
    public function test_e_duplicate_od_execution_rejected(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        $this->executionService->markPhysicalDone($line->traveler_number, 'netto', (int) $this->spvNetto->id);

        $this->actingAs($this->spvBubutOd)->postJson('/sand-casting/scan/bubut-od/execute', [
            'traveler_number' => $line->traveler_number,
        ])->assertStatus(200);

        // Second OD execution attempt must fail
        $this->actingAs($this->spvBubutOd)->postJson('/sand-casting/scan/bubut-od/execute', [
            'traveler_number' => $line->traveler_number,
        ])->assertStatus(422);
    }

    /**
     * TEST F: OD valid execution after NETTO physical done works without Admin PPIC defect input.
     */
    public function test_f_od_execution_works_without_admin_ppic_defect(): void
    {
        $line = $this->createKtrLine(['qty_good' => 150, 'current_stage' => 'netto']);

        // 1. SPV Netto marks physical done
        $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line->traveler_number,
        ])->assertStatus(200);

        // Verify Netto execution is WAITING_DEFECT (Admin PPIC has NOT entered defect)
        $nettoExec = $line->stageExecutions()->where('checkpoint_code', 'NETTO_CUT')->first();
        $this->assertSame(SandCastingStageExecution::STATUS_WAITING_DEFECT, $nettoExec->status);
        $this->assertNull($nettoExec->defect_entered_at);

        // 2. SPV OD scans and executes physically WITHOUT waiting for Admin PPIC
        $response = $this->actingAs($this->spvBubutOd)->postJson('/sand-casting/scan/bubut-od/execute', [
            'traveler_number' => $line->traveler_number,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.checkpoint_code', 'OD_TURNING');
        $response->assertJsonPath('data.input_qty', 150);

        // Netto execution status remains WAITING_DEFECT and unmutated
        $nettoExec->refresh();
        $this->assertSame(SandCastingStageExecution::STATUS_WAITING_DEFECT, $nettoExec->status);
    }

    /**
     * TEST G: OD physical done while NETTO defect still pending advances to bubut_cnc.
     */
    public function test_g_od_physical_done_advances_to_bubut_cnc(): void
    {
        $line = $this->createKtrLine(['qty_good' => 90, 'current_stage' => 'netto']);

        $this->executionService->markPhysicalDone($line->traveler_number, 'netto', (int) $this->spvNetto->id);
        $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_od', (int) $this->spvBubutOd->id);

        $line->refresh();
        $this->assertSame('bubut_cnc', $line->current_stage);

        $lookup = $this->queryService->findByTraveler($line->traveler_number);
        $this->assertSame('bubut_cnc', $lookup['current_stage']);
        $this->assertSame('CNC_MACHINING', $lookup['active_checkpoint']);
        $this->assertSame('READY', $lookup['operational_status']);
    }

    /**
     * TEST H: CNC checkpoints remain valid in sequence.
     */
    public function test_h_cnc_checkpoints_remain_valid_in_sequence(): void
    {
        $line = $this->createKtrLine(['qty_good' => 80, 'current_stage' => 'netto']);

        $this->executionService->markPhysicalDone($line->traveler_number, 'netto', (int) $this->spvNetto->id);
        $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_od', (int) $this->spvBubutOd->id);

        // 1. CNC_MACHINING
        $lookup1 = $this->queryService->findByTraveler($line->traveler_number);
        $this->assertSame('CNC_MACHINING', $lookup1['active_checkpoint']);
        $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_cnc', (int) $this->spvBubutCnc->id);

        // 2. QC_POST_CNC
        $line->refresh();
        $this->assertSame('bubut_cnc', $line->current_stage);
        $lookup2 = $this->queryService->findByTraveler($line->traveler_number);
        $this->assertSame('QC_POST_CNC', $lookup2['active_checkpoint']);
        $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_cnc', (int) $this->spvBubutCnc->id);

        // 3. QC_PRE_BOR
        $line->refresh();
        $this->assertSame('bubut_cnc', $line->current_stage);
        $lookup3 = $this->queryService->findByTraveler($line->traveler_number);
        $this->assertSame('QC_PRE_BOR', $lookup3['active_checkpoint']);
        $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_cnc', (int) $this->spvBubutCnc->id);

        // After QC_PRE_BOR -> advances to 'bor'
        $line->refresh();
        $this->assertSame('bor', $line->current_stage);
        $lookup4 = $this->queryService->findByTraveler($line->traveler_number);
        $this->assertSame('BOR_DRILLING', $lookup4['active_checkpoint']);
    }
}
