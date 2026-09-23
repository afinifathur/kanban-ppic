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

        // Verify current_stage does NOT advance prematurely (remains 'netto' until QC confirms)
        $line->refresh();
        $this->assertSame('netto', $line->current_stage);

        // Verify lookup query service now returns WAITING_DEFECT status
        $freshLookup = $this->queryService->findByTraveler($line->traveler_number);
        $this->assertSame('WAITING_DEFECT', $freshLookup['operational_status']);
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
        $this->assertStringContainsString('sudah pernah diproses fisik', $secondResponse->json('message'));

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
}
