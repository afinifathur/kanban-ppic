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

class DefectRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminPpic;

    protected User $adminUser;

    protected User $qcUser;

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

        $roleQc = Role::firstOrCreate(['name' => 'qc']);
        $roleQc->givePermissionTo($permExecution);

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

        // 3. QC User
        $this->qcUser = User::factory()->create([
            'name' => 'Admin QC Flange',
            'email' => 'adminqcflange@peroniks.com',
        ]);
        $this->qcUser->assignRole('qc');

        // 4. SPV User
        $this->spvUser = User::factory()->create([
            'name' => 'SPV Netto',
            'email' => 'spvnettofl@peroniks.com',
            'assigned_stage' => 'netto',
        ]);
        $this->spvUser->assignRole('spv');

        // 5. Unauthorized User without roles
        $this->unauthorizedUser = User::factory()->create([
            'name' => 'Regular Operator',
            'email' => 'regular@peroniks.com',
        ]);

        $this->executionService = new SandCastingStageExecutionService;
        $this->queryService = new SandCastingProductionFloorQueryService;
    }

    protected function createKtrLine(array $attributes = [], ?SandCastingCastingResult $existingResult = null): SandCastingCastingResultLine
    {
        $uniqueSuffix = bin2hex(random_bytes(4)).rand(100, 999);

        $plan = ProductionPlan::create([
            'code' => '268ET'.$uniqueSuffix,
            'title' => 'Rencana SC '.$uniqueSuffix,
            'item_code' => '4.'.$uniqueSuffix,
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
                'heat_number' => 'A214092'.$uniqueSuffix,
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
     * TEST 1: DefectRecordingAuthorizationTest
     */
    public function test_1_defect_recording_authorization(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto']);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);

        // 1. Unauthenticated
        $this->get('/sand-casting/defects')->assertRedirect(route('login'));

        // 2. Regular user without role -> 403
        $this->actingAs($this->unauthorizedUser)->get('/sand-casting/defects')->assertStatus(403);
        $this->actingAs($this->unauthorizedUser)->post("/sand-casting/defects/{$exec->id}/record", ['defect_qty' => 5])->assertStatus(403);
        $this->actingAs($this->unauthorizedUser)->post("/sand-casting/defects/{$exec->id}/add", ['added_qty' => 2])->assertStatus(403);

        // 3. SPV User cannot record PPIC defect -> 403
        $this->actingAs($this->spvUser)->get('/sand-casting/defects')->assertStatus(403);
        $this->actingAs($this->spvUser)->post("/sand-casting/defects/{$exec->id}/record", ['defect_qty' => 5])->assertStatus(403);

        // 4. Authorized PPIC -> 200
        $this->actingAs($this->adminPpic)->get('/sand-casting/defects')->assertStatus(200);

        // 5. Authorized Admin -> 200
        $this->actingAs($this->adminUser)->get('/sand-casting/defects')->assertStatus(200);
    }

    /**
     * TEST 2: Tab Belum Dicatat and Sudah Dicatat workflow
     * KTR WAITING_DEFECT appears in Belum Dicatat; upon initial recording, moves to Sudah Dicatat.
     */
    public function test_2_unrecorded_vs_recorded_tabs_flow(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);

        // Step 1: In Belum Dicatat tab
        $responseUnrecorded = $this->actingAs($this->adminPpic)->get('/sand-casting/defects?mode=unrecorded');
        $responseUnrecorded->assertStatus(200);
        $responseUnrecorded->assertSee($line->traveler_number);
        $responseUnrecorded->assertSee('CATAT RUSAK');

        // Step 2: Record initial defect 5
        $this->actingAs($this->adminPpic)->postJson("/sand-casting/defects/{$exec->id}/record", [
            'defect_qty' => 5,
        ])->assertStatus(200);

        // Step 3: Now appears in Sudah Dicatat tab, not in Belum Dicatat
        $responseUnrecordedAfter = $this->actingAs($this->adminPpic)->get('/sand-casting/defects?mode=unrecorded');
        $responseUnrecordedAfter->assertDontSee($line->traveler_number);

        $responseRecorded = $this->actingAs($this->adminPpic)->get('/sand-casting/defects?mode=recorded');
        $responseRecorded->assertStatus(200);
        $responseRecorded->assertSee($line->traveler_number);
        $responseRecorded->assertSee('TAMBAH DEFECT');
    }

    /**
     * TEST 2B: Standard web redirect returns to mode=unrecorded with stage filter and flash success
     */
    public function test_2b_initial_recording_web_redirects_to_unrecorded_tab_preserving_stage(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);

        $response = $this->actingAs($this->adminPpic)->post("/sand-casting/defects/{$exec->id}/record", [
            'defect_qty' => 3,
            'inspection_date' => now()->toDateString(),
            'notes' => 'Recorded from web form',
            'search' => 'KTR',
        ]);

        $response->assertRedirect(route('sand-casting.defects.index', [
            'mode' => 'unrecorded',
            'page' => 1,
            'stage' => 'netto',
            'search' => 'KTR',
        ]));
        $response->assertSessionHas('success');

        $fresh = $exec->fresh();
        $this->assertEquals(3, $fresh->defect_qty);
        $this->assertEquals(now()->toDateString(), $fresh->inspection_date->toDateString());
    }

    /**
     * TEST 2C: Validation error on inspection_date in future
     */
    public function test_2c_future_inspection_date_is_rejected(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);

        $futureDate = now()->addDays(2)->toDateString();

        $response = $this->actingAs($this->adminPpic)->post("/sand-casting/defects/{$exec->id}/record", [
            'defect_qty' => 3,
            'inspection_date' => $futureDate,
        ]);

        $response->assertSessionHasErrors('inspection_date');
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_DEFECT, $exec->fresh()->status);
    }

    /**
     * TEST 3, 4, 5, 6: Cumulative Defect Addition (5 -> +2 = 7 -> +4 = 11) and Audit Trail
     */
    public function test_3_cumulative_defect_addition_and_audit_trail(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);

        // 1. Initial defect: 5
        $this->actingAs($this->adminPpic)->postJson("/sand-casting/defects/{$exec->id}/record", [
            'defect_qty' => 5,
            'notes' => 'Initial defect entry',
        ])->assertStatus(200);

        $exec1 = $exec->fresh();
        $this->assertEquals(5, $exec1->defect_qty);
        $this->assertEquals(95, $exec1->good_qty);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $exec1->status);
        $this->assertCount(1, $exec1->defectLogs);
        $this->assertEquals(5, $exec1->defectLogs[0]->added_qty);
        $this->assertEquals(0, $exec1->defectLogs[0]->previous_total);
        $this->assertEquals(5, $exec1->defectLogs[0]->new_total);

        // 2. Tambah +2: 5 -> 7
        $this->actingAs($this->adminPpic)->postJson("/sand-casting/defects/{$exec->id}/add", [
            'added_qty' => 2,
            'notes' => 'Ditemukan defect tambahan 2 pcs',
        ])->assertStatus(200);

        $exec2 = $exec->fresh();
        $this->assertEquals(7, $exec2->defect_qty);
        $this->assertEquals(93, $exec2->good_qty);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $exec2->status);
        $this->assertCount(2, $exec2->defectLogs);
        $this->assertEquals(2, $exec2->defectLogs[1]->added_qty);
        $this->assertEquals(5, $exec2->defectLogs[1]->previous_total);
        $this->assertEquals(7, $exec2->defectLogs[1]->new_total);

        // 3. Tambah +4: 7 -> 11
        $this->actingAs($this->adminPpic)->postJson("/sand-casting/defects/{$exec->id}/add", [
            'added_qty' => 4,
            'notes' => 'Ditemukan defect tambahan 4 pcs lagi',
        ])->assertStatus(200);

        $exec3 = $exec->fresh();
        $this->assertEquals(11, $exec3->defect_qty);
        $this->assertEquals(89, $exec3->good_qty);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $exec3->status);
        $this->assertCount(3, $exec3->defectLogs);
        $this->assertEquals(4, $exec3->defectLogs[2]->added_qty);
        $this->assertEquals(7, $exec3->defectLogs[2]->previous_total);
        $this->assertEquals(11, $exec3->defectLogs[2]->new_total);
    }

    /**
     * TEST 7, 8, 9: Validation of Tambah Defect (> input, 0, negative)
     */
    public function test_7_tambah_defect_validations(): void
    {
        $line = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'netto']);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);
        $this->executionService->recordDefectQty($exec, 40, $this->adminPpic->id);

        // 1. Tambah 0 -> 422
        $this->actingAs($this->adminPpic)->postJson("/sand-casting/defects/{$exec->id}/add", [
            'added_qty' => 0,
        ])->assertStatus(422);

        // 2. Tambah negatif -> 422
        $this->actingAs($this->adminPpic)->postJson("/sand-casting/defects/{$exec->id}/add", [
            'added_qty' => -3,
        ])->assertStatus(422);

        // 3. Tambah sehingga total > input (40 + 15 = 55 > 50) -> 422
        $this->actingAs($this->adminPpic)->postJson("/sand-casting/defects/{$exec->id}/add", [
            'added_qty' => 15,
        ])->assertStatus(422);

        // Verify defect_qty is still 40
        $this->assertEquals(40, $exec->fresh()->defect_qty);
        $this->assertEquals(10, $exec->fresh()->good_qty);
    }

    /**
     * TEST 10: Unauthorized user cannot add defect
     */
    public function test_10_unauthorized_user_cannot_add_defect(): void
    {
        $line = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'netto']);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);
        $this->executionService->recordDefectQty($exec, 5, $this->adminPpic->id);

        $this->actingAs($this->spvUser)->postJson("/sand-casting/defects/{$exec->id}/add", [
            'added_qty' => 2,
        ])->assertStatus(403);
    }

    /**
     * TEST 12, 13: CONFIRMED + PPIC tambah defect -> CONFIRMED reverts to WAITING_QC and QC re-verifies
     */
    public function test_12_confirmed_execution_reverts_to_waiting_qc_upon_addition(): void
    {
        $defectType = DefectType::firstOrCreate(
            ['department' => 'netto', 'name' => 'Keropos Netto'],
            ['is_active' => true]
        );

        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);
        $this->executionService->recordDefectQty($exec, 5, $this->adminPpic->id);

        // QC confirms with 5 defects
        $this->executionService->verifyQcBreakdown($exec, [
            ['defect_type_id' => $defectType->id, 'qty' => 5],
        ], $this->qcUser->id);

        $this->assertEquals(SandCastingStageExecution::STATUS_CONFIRMED, $exec->fresh()->status);

        // PPIC adds +2 defect: 5 -> 7
        $this->actingAs($this->adminPpic)->postJson("/sand-casting/defects/{$exec->id}/add", [
            'added_qty' => 2,
        ])->assertStatus(200);

        $execReverted = $exec->fresh();
        $this->assertEquals(7, $execReverted->defect_qty);
        $this->assertEquals(93, $execReverted->good_qty);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $execReverted->status);

        // QC re-verification must breakdown all 7 defects
        $this->executionService->verifyQcBreakdown($execReverted, [
            ['defect_type_id' => $defectType->id, 'qty' => 7],
        ], $this->qcUser->id);

        $this->assertEquals(SandCastingStageExecution::STATUS_CONFIRMED, $exec->fresh()->status);
        $this->assertEquals(7, $exec->fresh()->defect_qty);
    }

    /**
     * TEST 14, 15: Physical current_stage and physical_done_at remain immutable when defect is added
     */
    public function test_14_physical_stage_and_timestamps_unaffected_by_defect_addition(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);
        $originalPhysicalDone = now()->subHours(4);

        $exec = $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 0,
            'good_qty' => 100,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'operator_id' => $this->spvUser->id,
            'physical_done_at' => $originalPhysicalDone,
            'executed_at' => $originalPhysicalDone,
        ]);

        // Advance line physical stage to bubut_od
        $line->current_stage = 'bubut_od';
        $line->save();

        // PPIC records initial defect: 5
        $this->executionService->recordDefectQty($exec, 5, $this->adminPpic->id);

        // PPIC adds defect: +3
        $this->executionService->addDefectQty($exec, 3, $this->adminPpic->id);

        $freshLine = $line->fresh();
        $freshExec = $exec->fresh();

        // Physical current_stage must stay at bubut_od
        $this->assertEquals('bubut_od', $freshLine->current_stage);
        // physical_done_at must not change
        $this->assertEquals($originalPhysicalDone->toDateTimeString(), $freshExec->physical_done_at->toDateTimeString());
    }

    /**
     * TEST 18, 19, 20: Server-side pagination and filters
     */
    public function test_18_pagination_and_filters(): void
    {
        // Create 30 lines
        for ($i = 1; $i <= 30; $i++) {
            $line = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'netto']);
            $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);
        }

        $paginator = $this->queryService->getDefectRecordingPaginated('unrecorded', 'netto', [], 25);
        $this->assertEquals(30, $paginator->total());
        $this->assertCount(25, $paginator->items());
        $this->assertEquals(2, $paginator->lastPage());
    }

    /**
     * TEST 21, 22: Tab sorting (FIFO physical_done_at ASC on unrecorded, defect_entered_at DESC on recorded)
     */
    public function test_21_tab_sorting_orders(): void
    {
        $line1 = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'netto']);
        $line2 = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'netto']);

        $exec1 = $line1->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 50,
            'defect_qty' => 0,
            'good_qty' => 50,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'operator_id' => $this->spvUser->id,
            'physical_done_at' => now()->subHours(5),
            'executed_at' => now()->subHours(5),
        ]);

        $exec2 = $line2->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 50,
            'defect_qty' => 0,
            'good_qty' => 50,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'operator_id' => $this->spvUser->id,
            'physical_done_at' => now()->subHours(10), // Older
            'executed_at' => now()->subHours(10),
        ]);

        // Unrecorded: FIFO order (exec2 first)
        $unrecorded = $this->queryService->getDefectRecordingPaginated('unrecorded', 'netto', [], 25);
        $this->assertEquals($exec2->id, $unrecorded->items()[0]['id']);

        // Now record defect on both
        $this->executionService->recordDefectQty($exec2, 2, $this->adminPpic->id);
        sleep(1);
        $this->executionService->recordDefectQty($exec1, 4, $this->adminPpic->id);

        // Recorded: defect_entered_at DESC (exec1 first)
        $recorded = $this->queryService->getDefectRecordingPaginated('recorded', 'netto', [], 25);
        $this->assertEquals($exec1->id, $recorded->items()[0]['id']);
    }

    /**
     * TEST 23: Auto-Nihil Command 5 Calendar Days Timeout & Backlog Protection
     */
    public function test_23_auto_nihil_command_processes_older_than_5_days_and_respects_activation_date(): void
    {
        config(['sand_casting.auto_nihil.activation_date' => '2026-10-01']);
        config(['sand_casting.auto_nihil.timeout_days' => 5]);

        // KTR 1: 6 days old, after activation date (Candidate for auto-nihil)
        $line1 = $this->createKtrLine(['qty_good' => 60, 'current_stage' => 'netto']);
        $exec1 = $line1->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 60,
            'defect_qty' => 0,
            'good_qty' => 60,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'operator_id' => $this->spvUser->id,
            'physical_done_at' => now()->subDays(6),
            'executed_at' => now()->subDays(6),
        ]);

        // KTR 2: 2 days old (Too young, should be skipped)
        $line2 = $this->createKtrLine(['qty_good' => 40, 'current_stage' => 'netto']);
        $exec2 = $line2->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 0,
            'good_qty' => 40,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'operator_id' => $this->spvUser->id,
            'physical_done_at' => now()->subDays(2),
            'executed_at' => now()->subDays(2),
        ]);

        // KTR 3: 15 days old but BEFORE activation date (Backlog, should be skipped)
        $line3 = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'netto']);
        $exec3 = $line3->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 50,
            'defect_qty' => 0,
            'good_qty' => 50,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'operator_id' => $this->spvUser->id,
            'physical_done_at' => \Carbon\Carbon::parse('2026-09-15 10:00:00'),
            'executed_at' => \Carbon\Carbon::parse('2026-09-15 10:00:00'),
        ]);

        // Run artisan command
        $this->artisan('sand-casting:timeout-unrecorded-defects', [
            '--since' => '2026-10-01',
            '--days' => 5,
        ])->assertSuccessful();

        // Verify exec1 became auto-nihil
        $fresh1 = $exec1->fresh();
        $this->assertTrue((bool) $fresh1->is_auto_nihil);
        $this->assertNotNull($fresh1->auto_nihil_at);
        $this->assertEquals(0, $fresh1->defect_qty);
        $this->assertEquals(60, $fresh1->good_qty);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $fresh1->status);
        $this->assertCount(1, $fresh1->defectLogs);
        $this->assertTrue((bool) $fresh1->defectLogs[0]->is_system_action);

        // Verify exec2 is untouched
        $fresh2 = $exec2->fresh();
        $this->assertFalse((bool) $fresh2->is_auto_nihil);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_DEFECT, $fresh2->status);

        // Verify exec3 is untouched (protected backlog)
        $fresh3 = $exec3->fresh();
        $this->assertFalse((bool) $fresh3->is_auto_nihil);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_DEFECT, $fresh3->status);
    }

    /**
     * TEST 24: Dry Run Mode does not mutate database
     */
    public function test_24_auto_nihil_dry_run_mode(): void
    {
        $line = $this->createKtrLine(['qty_good' => 60, 'current_stage' => 'netto']);
        $exec = $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 60,
            'defect_qty' => 0,
            'good_qty' => 60,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'operator_id' => $this->spvUser->id,
            'physical_done_at' => now()->subDays(7),
            'executed_at' => now()->subDays(7),
        ]);

        $this->artisan('sand-casting:timeout-unrecorded-defects', [
            '--dry-run' => true,
            '--days' => 5,
        ])->assertSuccessful();

        $fresh = $exec->fresh();
        $this->assertFalse((bool) $fresh->is_auto_nihil);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_DEFECT, $fresh->status);
        $this->assertCount(0, $fresh->defectLogs);
    }

    /**
     * TEST 25: Revising defect (+N) after auto-nihil
     */
    public function test_25_tambah_defect_after_auto_nihil_creates_cumulative_log(): void
    {
        $line = $this->createKtrLine(['qty_good' => 80, 'current_stage' => 'netto']);
        $exec = $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 80,
            'defect_qty' => 0,
            'good_qty' => 80,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'operator_id' => $this->spvUser->id,
            'physical_done_at' => now()->subDays(6),
            'executed_at' => now()->subDays(6),
        ]);

        // 1. Auto-Nihil occurs
        $this->executionService->timeoutToAutoNihil($exec);

        $freshAuto = $exec->fresh();
        $this->assertTrue((bool) $freshAuto->is_auto_nihil);
        $this->assertEquals(0, $freshAuto->defect_qty);
        $this->assertEquals(80, $freshAuto->good_qty);

        // 2. Later, Admin discovers 3 defects and adds them
        $this->actingAs($this->adminPpic)->postJson("/sand-casting/defects/{$exec->id}/add", [
            'added_qty' => 3,
            'notes' => 'Defect susulan ditemukan di gudang',
        ])->assertStatus(200);

        $freshRevised = $exec->fresh();
        $this->assertEquals(3, $freshRevised->defect_qty);
        $this->assertEquals(77, $freshRevised->good_qty);
        $this->assertEquals(SandCastingStageExecution::STATUS_WAITING_QC, $freshRevised->status);

        // Defect logs must have 2 entries: 1 auto-nihil, 1 addition
        $this->assertCount(2, $freshRevised->defectLogs);
        $this->assertTrue((bool) $freshRevised->defectLogs[0]->is_system_action);
        $this->assertEquals(0, $freshRevised->defectLogs[0]->added_qty);

        $this->assertFalse((bool) $freshRevised->defectLogs[1]->is_system_action);
        $this->assertEquals(3, $freshRevised->defectLogs[1]->added_qty);
        $this->assertEquals(0, $freshRevised->defectLogs[1]->previous_total);
    }
}
