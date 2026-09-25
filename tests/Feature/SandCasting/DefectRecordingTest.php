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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DefectRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminPpic;

    protected User $adminUser;

    protected User $spvUser;

    protected User $unauthorizedUser;

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

        // 1. Primary target user: adminppicfl@peroniks.com
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

        // 3. SPV User
        $this->spvUser = User::factory()->create([
            'name' => 'SPV Netto',
            'email' => 'spvnettofl@peroniks.com',
            'assigned_stage' => 'netto',
        ]);
        $this->spvUser->assignRole('spv');

        // 4. Unauthorized User without roles
        $this->unauthorizedUser = User::factory()->create([
            'name' => 'Regular Operator',
            'email' => 'regular@peroniks.com',
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
     * TEST 1: DefectRecordingAuthorizationTest
     * - Unauthorized users (unauthenticated, regular users, SPVs) denied with 403
     * - Authorized PPIC and Admin users allowed
     */
    public function test_1_defect_recording_authorization(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto']);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);

        // 1. Unauthenticated -> Redirects or 403/401
        $responseUnauth = $this->get('/sand-casting/defects');
        $responseUnauth->assertRedirect(route('login'));

        // 2. Regular user without role -> 403 Forbidden
        $responseRegular = $this->actingAs($this->unauthorizedUser)->get('/sand-casting/defects');
        $responseRegular->assertStatus(403);

        $responsePostRegular = $this->actingAs($this->unauthorizedUser)->post("/sand-casting/defects/{$exec->id}/record", [
            'defect_qty' => 5,
        ]);
        $responsePostRegular->assertStatus(403);

        // 3. SPV User cannot access PPIC defect recording -> 403 Forbidden
        $responseSpv = $this->actingAs($this->spvUser)->get('/sand-casting/defects');
        $responseSpv->assertStatus(403);

        $responsePostSpv = $this->actingAs($this->spvUser)->post("/sand-casting/defects/{$exec->id}/record", [
            'defect_qty' => 5,
        ]);
        $responsePostSpv->assertStatus(403);

        // 4. Authorized PPIC -> 200 OK
        $responsePpic = $this->actingAs($this->adminPpic)->get('/sand-casting/defects');
        $responsePpic->assertStatus(200);

        // 5. Authorized Admin -> 200 OK
        $responseAdmin = $this->actingAs($this->adminUser)->get('/sand-casting/defects');
        $responseAdmin->assertStatus(200);
    }

    /**
     * TEST 2: DefectRecordingQueueFifoTest
     * - Oldest physical_done_at appears first in queue
     * - Tie-breaker: physical_done_at ASC, id ASC
     */
    public function test_2_defect_recording_queue_fifo(): void
    {
        $line1 = $this->createKtrLine(['current_stage' => 'netto']);
        $line2 = $this->createKtrLine(['current_stage' => 'netto']);
        $line3 = $this->createKtrLine(['current_stage' => 'netto']);

        // Create executions with different physical completion timestamps
        $exec1 = $line1->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 0,
            'good_qty' => 100,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'operator_id' => $this->spvUser->id,
            'physical_done_at' => now()->subHours(5),
            'executed_at' => now()->subHours(5),
        ]);

        $exec2 = $line2->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 0,
            'good_qty' => 100,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'operator_id' => $this->spvUser->id,
            'physical_done_at' => now()->subHours(10), // Oldest!
            'executed_at' => now()->subHours(10),
        ]);

        $exec3 = $line3->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 0,
            'good_qty' => 100,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'operator_id' => $this->spvUser->id,
            'physical_done_at' => now()->subHours(2), // Newest!
            'executed_at' => now()->subHours(2),
        ]);

        $queue = $this->queryService->getDefectRecordingQueue('netto');

        $this->assertCount(3, $queue);
        // Expect FIFO: exec2 (10h ago), exec1 (5h ago), exec3 (2h ago)
        $this->assertEquals($exec2->id, $queue[0]['id']);
        $this->assertEquals($exec1->id, $queue[1]['id']);
        $this->assertEquals($exec3->id, $queue[2]['id']);
    }

    /**
     * TEST 3: DefectRecordingZeroDefectTest
     * - defect_qty = 0 is fully valid
     * - status transitions to WAITING_QC
     * - good_qty = input_qty
     */
    public function test_3_defect_recording_zero_defect(): void
    {
        $line = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'netto']);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);

        $response = $this->actingAs($this->adminPpic)->post("/sand-casting/defects/{$exec->id}/record", [
            'defect_qty' => 0,
            'notes' => '100% mulus tanpa cacat',
        ]);

        $response->assertRedirect(route('sand-casting.defects.index', ['tab' => 'netto']));
        $response->assertSessionHas('success');

        $freshExec = $exec->fresh();
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $freshExec->status);
        $this->assertEquals(0, $freshExec->defect_qty);
        $this->assertEquals(50, $freshExec->good_qty);
        $this->assertEquals(50, $freshExec->input_qty);
        $this->assertEquals($this->adminPpic->id, $freshExec->defect_entered_by);
        $this->assertNotNull($freshExec->defect_entered_at);
        $this->assertEquals('100% mulus tanpa cacat', $freshExec->notes);
    }

    /**
     * TEST 4: DefectRecordingValidationTest
     * - Negative defect_qty is rejected with 422
     * - defect_qty > input_qty is rejected with 422
     * - Non-integer defect_qty is rejected with 422
     */
    public function test_4_defect_recording_validation(): void
    {
        $line = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'netto']);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);

        // 1. Negative defect
        $responseNegative = $this->actingAs($this->adminPpic)
            ->postJson("/sand-casting/defects/{$exec->id}/record", ['defect_qty' => -5]);
        $responseNegative->assertStatus(422);

        // 2. Defect > Input (input = 50, defect = 51)
        $responseExcess = $this->actingAs($this->adminPpic)
            ->postJson("/sand-casting/defects/{$exec->id}/record", ['defect_qty' => 51]);
        $responseExcess->assertStatus(422);

        // 3. Non-integer / non-numeric
        $responseString = $this->actingAs($this->adminPpic)
            ->postJson("/sand-casting/defects/{$exec->id}/record", ['defect_qty' => 'abc']);
        $responseString->assertStatus(422);

        // Execution status must remain WAITING_DEFECT
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_DEFECT, $exec->fresh()->status);
    }

    /**
     * TEST 5: DefectRecordingAuditTrailTest
     * - defect_entered_by is set to authenticated Admin PPIC
     * - defect_entered_at is set to current timestamp
     * - physical_done_at is preserved
     */
    public function test_5_defect_recording_audit_trail(): void
    {
        $line = $this->createKtrLine(['qty_good' => 80, 'current_stage' => 'netto']);
        $originalPhysicalDone = now()->subHours(3);

        $exec = $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 80,
            'defect_qty' => 0,
            'good_qty' => 80,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'operator_id' => $this->spvUser->id,
            'physical_done_at' => $originalPhysicalDone,
            'executed_at' => $originalPhysicalDone,
        ]);

        $response = $this->actingAs($this->adminPpic)->postJson("/sand-casting/defects/{$exec->id}/record", [
            'defect_qty' => 10,
            'notes' => 'Catatan cacat potong',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $freshExec = $exec->fresh();
        $this->assertEquals($this->adminPpic->id, $freshExec->defect_entered_by);
        $this->assertNotNull($freshExec->defect_entered_at);
        $this->assertEquals($originalPhysicalDone->toDateTimeString(), $freshExec->physical_done_at->toDateTimeString());
        $this->assertEquals(70, $freshExec->good_qty);
        $this->assertEquals(10, $freshExec->defect_qty);
    }

    /**
     * TEST 6: DefectRecordingStateTransitionTest
     * - WAITING_DEFECT -> WAITING_QC
     * - Good qty calculated strictly server-side
     * - Cannot re-record defect when already in WAITING_QC or CONFIRMED
     */
    public function test_6_defect_recording_state_transitions(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);

        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_DEFECT, $exec->status);

        // Transition: WAITING_DEFECT -> WAITING_QC
        $this->actingAs($this->adminPpic)->postJson("/sand-casting/defects/{$exec->id}/record", [
            'defect_qty' => 15,
        ])->assertStatus(200);

        $freshExec = $exec->fresh();
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $freshExec->status);
        $this->assertEquals(85, $freshExec->good_qty);

        // Attempting to record defect again while WAITING_QC must be rejected
        $repeatResponse = $this->actingAs($this->adminPpic)->postJson("/sand-casting/defects/{$exec->id}/record", [
            'defect_qty' => 5,
        ]);
        $repeatResponse->assertStatus(422);

        // Verify quantities were not modified
        $this->assertEquals(15, $exec->fresh()->defect_qty);
        $this->assertEquals(85, $exec->fresh()->good_qty);
    }

    /**
     * TEST 7: DefectRecordingTabCounterTest
     * - Global WAITING_DEFECT count and total PCS are calculated across all 6 stages
     * - Only WAITING_DEFECT executions are counted in backlog
     * - Incoming today is informational
     */
    public function test_7_defect_recording_tab_counter(): void
    {
        // Stage 1: Netto (2 waiting defect, 1 confirmed)
        $line1 = $this->createKtrLine(['qty_good' => 40, 'current_stage' => 'netto']);
        $this->executionService->markPhysicalDone($line1->traveler_number, 'netto', $this->spvUser->id);

        $line2 = $this->createKtrLine(['qty_good' => 60, 'current_stage' => 'netto']);
        $this->executionService->markPhysicalDone($line2->traveler_number, 'netto', $this->spvUser->id);

        $line3 = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);
        $exec3 = $this->executionService->markPhysicalDone($line3->traveler_number, 'netto', $this->spvUser->id);
        $this->executionService->recordDefectQty($exec3, 0, $this->adminPpic->id); // Moved to WAITING_QC

        // Stage 2: Bubut OD (1 waiting defect)
        $line4 = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'bubut_od']);
        $line4->stageExecutions()->create([
            'stage' => 'bubut_od',
            'checkpoint_code' => 'OD_TURNING',
            'input_qty' => 50,
            'defect_qty' => 0,
            'good_qty' => 50,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'operator_id' => $this->spvUser->id,
            'physical_done_at' => now(),
            'executed_at' => now(),
        ]);

        $summary = $this->queryService->getDefectRecordingSummary();

        // Total WAITING_DEFECT: line1 (40), line2 (60), line4 (50) -> count = 3, pcs = 150
        $this->assertEquals(3, $summary['waiting_defect_count']);
        $this->assertEquals(150, $summary['waiting_defect_pcs']);
        $this->assertEquals(2, $summary['stage_counts']['netto']);
        $this->assertEquals(1, $summary['stage_counts']['bubut_od']);
        $this->assertEquals(0, $summary['stage_counts']['bubut_cnc']);
    }

    /**
     * TEST 8: DefectRecordingCncCheckpointTest
     * - CNC tab handles multiple CNC checkpoints (CNC_MACHINING, QC_POST_CNC, QC_PRE_BOR)
     * - Checkpoint code is displayed and separate executions are not collapsed
     */
    public function test_8_defect_recording_cnc_checkpoints(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'bubut_cnc']);

        $execCnc = $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 100,
            'defect_qty' => 0,
            'good_qty' => 100,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'operator_id' => $this->spvUser->id,
            'physical_done_at' => now()->subHour(),
            'executed_at' => now()->subHour(),
        ]);

        $cncQueue = $this->queryService->getDefectRecordingQueue('bubut_cnc');

        $this->assertCount(1, $cncQueue);
        $this->assertEquals('CNC_MACHINING', $cncQueue[0]['checkpoint_code']);
        $this->assertEquals('bubut_cnc', $cncQueue[0]['stage']);

        // View loads bubut_cnc tab with checkpoint code
        $response = $this->actingAs($this->adminPpic)->get('/sand-casting/defects?tab=bubut_cnc');
        $response->assertStatus(200);
        $response->assertSee('CNC_MACHINING');
        $response->assertSee($line->traveler_number);
    }

    /**
     * TEST 9: DefectRecordingZeroVsUnrecordedUiTest
     * - Unrecorded defect displays "BELUM DICATAT"
     * - Defect zero displays "RUSAK 0 PCS"
     * - Defect N displays "RUSAK N PCS"
     */
    public function test_9_defect_recording_zero_vs_unrecorded_ui(): void
    {
        $line1 = $this->createKtrLine(['qty_good' => 75, 'current_stage' => 'netto']);
        $exec1 = $this->executionService->markPhysicalDone($line1->traveler_number, 'netto', $this->spvUser->id);

        $card1 = $this->queryService->formatDefectRecordingCard($exec1);
        $this->assertEquals('unrecorded', $card1['defect_state']);
        $this->assertEquals('BELUM DICATAT', $card1['defect_status_label']);

        // Now record 0 defect
        $execZero = $this->executionService->recordDefectQty($exec1, 0, $this->adminPpic->id);
        $cardZero = $this->queryService->formatDefectRecordingCard($execZero);
        $this->assertEquals('zero', $cardZero['defect_state']);
        $this->assertEquals('RUSAK 0 PCS', $cardZero['defect_status_label']);

        // Another line with 3 defects
        $line2 = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'netto']);
        $exec2 = $this->executionService->markPhysicalDone($line2->traveler_number, 'netto', $this->spvUser->id);
        $execWithDefect = $this->executionService->recordDefectQty($exec2, 3, $this->adminPpic->id);
        $cardDefect = $this->queryService->formatDefectRecordingCard($execWithDefect);
        $this->assertEquals('defect', $cardDefect['defect_state']);
        $this->assertEquals('RUSAK 3 PCS', $cardDefect['defect_status_label']);

        // Line 3 in WAITING_DEFECT state to assert Blade view renders BELUM DICATAT and CATAT RUSAK
        $line3 = $this->createKtrLine(['qty_good' => 60, 'current_stage' => 'netto']);
        $this->executionService->markPhysicalDone($line3->traveler_number, 'netto', $this->spvUser->id);

        // Assert that the Blade view renders BELUM DICATAT for active waiting defect
        $response = $this->actingAs($this->adminPpic)->get('/sand-casting/defects');
        $response->assertStatus(200);
        $response->assertSee('BELUM DICATAT');
        $response->assertSee('CATAT RUSAK');
    }
}
