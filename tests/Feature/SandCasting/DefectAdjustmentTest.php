<?php

namespace Tests\Feature\SandCasting;

use App\Models\DefectType;
use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\SandCastingStageExecutionDefectLog;
use App\Models\User;
use App\Services\SandCasting\SandCastingProductionFloorQueryService;
use App\Services\SandCasting\SandCastingProductionStatusService;
use App\Services\SandCasting\SandCastingQuantityResolverService;
use App\Services\SandCasting\SandCastingStageExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DefectAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminPpic;

    protected User $adminPpicStainless;

    protected User $adminQc;

    protected User $spvUser;

    protected SandCastingStageExecutionService $executionService;

    protected SandCastingProductionFloorQueryService $queryService;

    protected SandCastingProductionStatusService $statusService;

    protected SandCastingQuantityResolverService $quantityResolver;

    protected DefectType $defectPorosity;

    protected DefectType $defectRetak;

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

        $this->adminPpic = User::factory()->create([
            'name' => 'Admin PPIC Besi',
            'email' => 'adminppicfl@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->adminPpic->assignRole($rolePpic);

        $this->adminPpicStainless = User::factory()->create([
            'name' => 'Admin PPIC Stainless',
            'email' => 'adminppicss@peroniks.com',
            'product_scope' => 'FLANGE_STAINLESS',
        ]);
        $this->adminPpicStainless->assignRole($rolePpic);

        $this->adminQc = User::factory()->create([
            'name' => 'Admin QC',
            'email' => 'adminqcflange@peroniks.com',
        ]);
        $this->adminQc->assignRole($roleQc);

        $this->spvUser = User::factory()->create([
            'name' => 'SPV Netto',
            'email' => 'spvnetto@peroniks.com',
        ]);
        $this->spvUser->assignRole($roleSpv);

        $this->executionService = app(SandCastingStageExecutionService::class);
        $this->queryService = app(SandCastingProductionFloorQueryService::class);
        $this->quantityResolver = app(SandCastingQuantityResolverService::class);
        $this->statusService = app(SandCastingProductionStatusService::class);

        $this->defectPorosity = DefectType::firstOrCreate(
            ['name' => 'POROSITY (NETTO)'],
            ['department' => 'netto', 'code' => 'POR-01']
        );
        $this->defectRetak = DefectType::firstOrCreate(
            ['name' => 'RETAK (NETTO)'],
            ['department' => 'netto', 'code' => 'RET-01']
        );
    }

    protected function createKtr(int $qtyGood = 100, string $scope = 'FLANGE_BESI', string $code = 'PLAN-01'): array
    {
        $plan = ProductionPlan::create([
            'code' => $code,
            'po_number' => 'PO-'.uniqid(),
            'item_code' => 'ITM-01',
            'item_name' => 'Flange Besi DN50',
            'po_quantity' => $qtyGood,
            'qty_planned' => $qtyGood,
            'qty_remaining' => $qtyGood,
            'line_number' => 1,
            'customer' => 'PT SINAR METAL',
            'product_scope' => $scope,
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'APPROVED',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-'.uniqid(),
            'scheduled_date' => now()->toDateString(),
            'status' => 'ISSUED',
            'created_by' => $this->adminPpic->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => $qtyGood,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '2"',
            'aisi' => 'FC250',
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'HEAT-'.uniqid(),
            'cast_date' => now()->toDateString(),
            'furnace' => 'F1',
            'shift' => '1',
            'operator_name' => 'Budi',
            'recorded_by' => $this->adminPpic->id,
        ]);

        $line = SandCastingCastingResultLine::create([
            'sand_casting_casting_result_id' => $result->id,
            'production_plan_id' => $plan->id,
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'traveler_number' => 'KTR-'.uniqid(),
            'qty_good' => $qtyGood,
            'qty_reject' => 0,
            'current_stage' => 'netto',
        ]);

        return [$plan, $line];
    }

    /**
     * TEST 1: Positive adjustment increases total defect correctly.
     */
    public function test_01_positive_adjustment_increases_total_correctly(): void
    {
        [, $line] = $this->createKtr(100);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);
        $this->executionService->recordDefectQty($exec, 5, $this->adminPpic->id);

        $adjusted = $this->executionService->adjustDefectQty($exec, 3, $this->adminPpic->id, 'Tambah temuan defect');

        $this->assertSame(8, $adjusted->defect_qty);
        $this->assertSame(92, $adjusted->good_qty);
        $this->assertSame(SandCastingStageExecution::STATUS_WAITING_QC, $adjusted->status);

        $latestLog = $adjusted->defectLogs->last();
        $this->assertSame(3, $latestLog->added_qty);
        $this->assertSame(5, $latestLog->previous_total);
        $this->assertSame(8, $latestLog->new_total);
    }

    /**
     * TEST 2: Negative adjustment decreases total defect correctly.
     */
    public function test_02_negative_adjustment_decreases_total_correctly(): void
    {
        [, $line] = $this->createKtr(100);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);
        $this->executionService->recordDefectQty($exec, 5, $this->adminPpic->id);

        $adjusted = $this->executionService->adjustDefectQty($exec, -2, $this->adminPpic->id, 'Koreksi salah hitung operator');

        $this->assertSame(3, $adjusted->defect_qty);
        $this->assertSame(97, $adjusted->good_qty);
        $this->assertSame(SandCastingStageExecution::STATUS_WAITING_QC, $adjusted->status);

        $latestLog = $adjusted->defectLogs->last();
        $this->assertSame(-2, $latestLog->added_qty);
        $this->assertSame(5, $latestLog->previous_total);
        $this->assertSame(3, $latestLog->new_total);
        $this->assertSame('Koreksi salah hitung operator', $latestLog->notes);
    }

    /**
     * TEST 3: Negative adjustment without a reason is rejected.
     */
    public function test_03_negative_adjustment_without_reason_is_rejected(): void
    {
        [, $line] = $this->createKtr(100);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);
        $this->executionService->recordDefectQty($exec, 5, $this->adminPpic->id);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Alasan koreksi (notes/reason) wajib diisi untuk penyesuaian defect negatif.');

        $this->executionService->adjustDefectQty($exec, -2, $this->adminPpic->id, '   ');
    }

    /**
     * TEST 4: An adjustment cannot produce a negative total defect.
     */
    public function test_04_adjustment_cannot_produce_negative_total(): void
    {
        [, $line] = $this->createKtr(100);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);
        $this->executionService->recordDefectQty($exec, 5, $this->adminPpic->id);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Total defect baru tidak boleh negatif');

        $this->executionService->adjustDefectQty($exec, -6, $this->adminPpic->id, 'Koreksi melebihi total');
    }

    /**
     * TEST 5: An adjustment cannot exceed the applicable dynamic quantity boundary.
     */
    public function test_05_adjustment_cannot_exceed_applicable_dynamic_quantity_boundary(): void
    {
        [, $line] = $this->createKtr(100);

        // 1. Netto physical scan input 100
        $execNetto = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);

        // 2. Bubut OD scanned before Netto defect is entered -> stored input snapshot is 100
        $execOd = $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_od', $this->spvUser->id);

        // 3. Netto defect later entered as 30 pcs -> effective input reaching OD is 70 pcs
        $this->executionService->recordDefectQty($execNetto, 30, $this->adminPpic->id);

        // 4. Record initial OD defect of 10 pcs
        $this->executionService->recordDefectQty($execOd, 10, $this->adminPpic->id);

        // 5. Try to adjust OD defect by +65 pcs (new total would be 75 pcs).
        // Stored snapshot on OD execution is 100, but dynamic boundary is 70 (100 - 30 netto defect).
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('melebihi batas kuantitas input efektif (70 PCS)');

        $this->executionService->adjustDefectQty($execOd, 65, $this->adminPpic->id, 'Penambahan melebihi batas efektif');
    }

    /**
     * TEST 6: Correcting total to zero preserves distinction between recorded-zero and unrecorded.
     */
    public function test_06_correcting_total_to_zero_preserves_recorded_zero_distinction(): void
    {
        [, $line] = $this->createKtr(100);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);
        $this->executionService->recordDefectQty($exec, 5, $this->adminPpic->id);

        // Adjust to 0
        $adjusted = $this->executionService->adjustDefectQty($exec, -5, $this->adminPpic->id, 'Koreksi defect nihil');

        $this->assertSame(0, $adjusted->defect_qty);
        $this->assertSame(100, $adjusted->good_qty);
        // Status remains WAITING_QC (explicitly recorded), NOT WAITING_DEFECT (unrecorded)
        $this->assertSame(SandCastingStageExecution::STATUS_WAITING_QC, $adjusted->status);

        // Query service formats state as 'zero', NOT 'unrecorded'
        $formatted = $this->queryService->formatDefectRecordingCard($adjusted->fresh());
        $this->assertSame('zero', $formatted['defect_state']);
        $this->assertSame('RUSAK 0 PCS', $formatted['defect_status_label']);
    }

    /**
     * TEST 7: Confirmed execution requiring reverification no longer appears as valid final QC confirmation.
     */
    public function test_07_confirmed_execution_requiring_reverification_reverts_status_and_requires_qc(): void
    {
        [, $line] = $this->createKtr(100);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);
        $this->executionService->recordDefectQty($exec, 5, $this->adminPpic->id);

        // QC confirms
        $this->executionService->verifyQcBreakdown($exec, [
            ['defect_type_id' => $this->defectPorosity->id, 'qty' => 5],
        ], $this->adminQc->id);

        $confirmed = $exec->fresh();
        $this->assertSame(SandCastingStageExecution::STATUS_CONFIRMED, $confirmed->status);
        $this->assertNotNull($confirmed->qc_verified_at);

        // PPIC adjusts defect from 5 to 3 (-2 pcs)
        $reverted = $this->executionService->adjustDefectQty($confirmed, -2, $this->adminPpic->id, 'Koreksi ke 3 pcs');

        $fresh = $reverted->fresh();
        $this->assertSame(SandCastingStageExecution::STATUS_WAITING_QC, $fresh->status);
        $this->assertNull($fresh->qc_verified_at);
        $this->assertNull($fresh->qc_verified_by);

        // Card indicates reverification is required
        $card = $this->queryService->formatQcVerificationCard($fresh);
        $this->assertTrue($card['is_reverification']);

        // Old breakdown was for 5 pcs. QC must re-verify to match new total of 3 pcs.
        $this->executionService->verifyQcBreakdown($fresh, [
            ['defect_type_id' => $this->defectPorosity->id, 'qty' => 3],
        ], $this->adminQc->id);

        $reconfirmed = $fresh->fresh();
        $this->assertSame(SandCastingStageExecution::STATUS_CONFIRMED, $reconfirmed->status);
        $this->assertSame(3, $reconfirmed->defect_qty);
        $this->assertSame(97, $reconfirmed->good_qty);
        $this->assertNotNull($reconfirmed->qc_verified_at);
    }

    /**
     * TEST 8: Duplicate retry/request does not apply the same adjustment twice.
     */
    public function test_08_duplicate_retry_does_not_apply_same_adjustment_twice(): void
    {
        [, $line] = $this->createKtr(100);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);
        $this->executionService->recordDefectQty($exec, 5, $this->adminPpic->id);

        $idempotencyKey = 'adj-key-retry-12345';

        // First attempt
        $res1 = $this->executionService->adjustDefectQty($exec, 2, $this->adminPpic->id, 'Tambah 2 pcs', $idempotencyKey);
        $this->assertSame(7, $res1->defect_qty);
        $this->assertCount(2, $res1->defectLogs);

        // Duplicate retry with identical key
        $res2 = $this->executionService->adjustDefectQty($exec, 2, $this->adminPpic->id, 'Tambah 2 pcs', $idempotencyKey);
        $this->assertSame(7, $res2->defect_qty); // Still 7, NOT 9!
        $this->assertCount(2, $exec->fresh()->defectLogs); // No duplicate log added
    }

    /**
     * TEST 9: Concurrent adjustments cannot silently overwrite one another or violate quantity constraints.
     */
    public function test_09_database_unique_index_prevents_duplicate_idempotency_insertion(): void
    {
        [, $line] = $this->createKtr(100);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);
        $this->executionService->recordDefectQty($exec, 5, $this->adminPpic->id);

        $key = 'concurrent-unique-key-999';
        $this->executionService->adjustDefectQty($exec, 1, $this->adminPpic->id, 'First write', $key);

        // Direct DB attempt to insert duplicate key must trigger unique constraint violation
        $this->expectException(\Illuminate\Database\QueryException::class);
        SandCastingStageExecutionDefectLog::create([
            'sand_casting_stage_execution_id' => $exec->id,
            'added_qty' => 1,
            'previous_total' => 6,
            'new_total' => 7,
            'user_id' => $this->adminPpic->id,
            'idempotency_key' => $key,
        ]);
    }

    /**
     * TEST 10: Adjustment log preserves user, reason, previous total, new total, and signed delta.
     */
    public function test_10_adjustment_log_preserves_full_audit_trail(): void
    {
        [, $line] = $this->createKtr(100);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);
        $this->executionService->recordDefectQty($exec, 10, $this->adminPpic->id);

        $this->executionService->adjustDefectQty($exec, -4, $this->adminPpic->id, 'Alasan audit spesifik koreksi');

        $log = SandCastingStageExecutionDefectLog::where('sand_casting_stage_execution_id', $exec->id)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($this->adminPpic->id, $log->user_id);
        $this->assertSame(-4, $log->added_qty);
        $this->assertSame(10, $log->previous_total);
        $this->assertSame(6, $log->new_total);
        $this->assertSame('Alasan audit spesifik koreksi', $log->notes);
        $this->assertFalse((bool) $log->is_system_action);
    }

    /**
     * TEST 11: Defect adjustment does not mutate historical physical scan snapshots.
     */
    public function test_11_defect_adjustment_does_not_mutate_physical_scan_snapshots(): void
    {
        [, $line] = $this->createKtr(100);
        $execNetto = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);
        $execOd = $this->executionService->markPhysicalDone($line->traveler_number, 'bubut_od', $this->spvUser->id);

        $originalOdInput = $execOd->input_qty;
        $originalOdTime = $execOd->physical_done_at;
        $originalOdOperator = $execOd->operator_id;

        // Record and adjust defect on Netto
        $this->executionService->recordDefectQty($execNetto, 5, $this->adminPpic->id);
        $this->executionService->adjustDefectQty($execNetto, 10, $this->adminPpic->id, 'Tambah defect netto');

        $freshOd = $execOd->fresh();
        // Downstream scan snapshot attributes remain completely unmutated
        $this->assertSame($originalOdInput, $freshOd->input_qty);
        $this->assertEquals($originalOdTime, $freshOd->physical_done_at);
        $this->assertSame($originalOdOperator, $freshOd->operator_id);
    }

    /**
     * TEST 12: Different Production Codes remain isolated; no cross-code quantity borrowing occurs.
     */
    public function test_12_production_codes_remain_strictly_isolated_no_quantity_borrowing(): void
    {
        [$planA, $lineA] = $this->createKtr(100, 'FLANGE_BESI', 'PLAN-ALPHA');
        [$planB, $lineB] = $this->createKtr(50, 'FLANGE_BESI', 'PLAN-BETA');

        $execA = $this->executionService->markPhysicalDone($lineA->traveler_number, 'netto', $this->spvUser->id);
        $execB = $this->executionService->markPhysicalDone($lineB->traveler_number, 'netto', $this->spvUser->id);

        $this->executionService->recordDefectQty($execA, 20, $this->adminPpic->id);
        $this->executionService->recordDefectQty($execB, 5, $this->adminPpic->id);

        // Adjust defect on Plan A
        $this->executionService->adjustDefectQty($execA, 10, $this->adminPpic->id, 'Penyesuaian Plan A');

        // Verify Plan B is 100% untouched
        $freshB = $execB->fresh();
        $this->assertSame(5, $freshB->defect_qty);
        $this->assertSame(45, $freshB->good_qty);
        $this->assertSame(50, (int) $lineB->fresh()->qty_good);

        // Check resolver boundaries are independent
        $effectiveA = $this->quantityResolver->resolveEffectiveGoodQty($lineA->fresh(), 'netto');
        $effectiveB = $this->quantityResolver->resolveEffectiveGoodQty($lineB->fresh(), 'netto');

        $this->assertSame(70, $effectiveA); // 100 - 30 = 70
        $this->assertSame(45, $effectiveB); // 50 - 5 = 45
    }

    /**
     * TEST 13: Existing positive defect entry behavior and role/product-scope rules remain intact.
     */
    public function test_13_existing_positive_defect_and_product_scope_enforced_via_controller(): void
    {
        [, $line] = $this->createKtr(100, 'FLANGE_BESI');
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);
        $this->executionService->recordDefectQty($exec, 5, $this->adminPpic->id);

        // User with SPV role cannot adjust defects (must be PPIC or Admin)
        $response = $this->actingAs($this->spvUser)->postJson("/sand-casting/defects/{$exec->id}/adjust", [
            'adjustment_qty' => -2,
            'notes' => 'Unauthorized role correction',
        ]);
        $response->assertStatus(403);

        // Authorized user can adjust via HTTP endpoint
        $responseSuccess = $this->actingAs($this->adminPpic)->postJson("/sand-casting/defects/{$exec->id}/adjust", [
            'adjustment_qty' => -2,
            'notes' => 'Authorized correction',
        ]);
        $responseSuccess->assertStatus(200);
        $responseSuccess->assertJsonPath('success', true);
        $this->assertSame(3, $exec->fresh()->defect_qty);
    }

    /**
     * TEST 14: Phase 6D-1 effective GD calculation remains correct with defect adjustments.
     */
    public function test_14_effective_gd_calculation_reflects_defect_adjustments_dynamically(): void
    {
        [$plan, $line] = $this->createKtr(100, 'FLANGE_BESI', 'PLAN-GD-ADJ');
        $line->update(['current_stage' => 'completed']);

        // GUDANG_RECEIVE physical execution scanned with snapshot good_qty = 100
        $gdExec = $line->stageExecutions()->create([
            'stage' => 'gudang_jadi',
            'checkpoint_code' => 'GUDANG_RECEIVE',
            'input_qty' => 100,
            'defect_qty' => 0,
            'good_qty' => 100,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->spvUser->id,
            'executed_at' => now(),
            'physical_done_at' => now(),
        ]);

        // Netto execution has 5 defect recorded
        $nettoExec = $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 5,
            'good_qty' => 95,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'operator_id' => $this->spvUser->id,
            'executed_at' => now(),
            'physical_done_at' => now(),
        ]);

        // Initial check: effective GD is 95
        $row = $this->statusService->calculatePlanStatusRow($plan->load('sandCastingResultLines.stageExecutions'));
        $this->assertSame(95, $row['gd']);
        $this->assertSame(5, $row['remaining_po']);
        $this->assertSame('ACTIVE', $row['status']);

        // PPIC adjusts Netto defect downward: 5 -> 2 (-3 pcs)
        $this->executionService->adjustDefectQty($nettoExec, -3, $this->adminPpic->id, 'Koreksi penurunan defect netto');

        // Production Status dynamically resolves effective GD: 100 - 2 = 98 pcs
        $updatedRow = $this->statusService->calculatePlanStatusRow($plan->fresh(['sandCastingResultLines.stageExecutions']));
        $this->assertSame(98, $updatedRow['gd']);
        $this->assertSame(2, $updatedRow['remaining_po']);
        $this->assertSame('ACTIVE', $updatedRow['status']);

        // Stored snapshot on GUDANG_RECEIVE was never mutated
        $this->assertSame(100, $gdExec->fresh()->good_qty);
    }

    /**
     * TEST 15: Reusing an idempotency key with a DIFFERENT payload quantity is rejected.
     */
    public function test_15_idempotency_key_reused_with_different_payload_is_rejected(): void
    {
        [, $line] = $this->createKtr(100);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);
        $this->executionService->recordDefectQty($exec, 5, $this->adminPpic->id);

        $key = 'idempotent-key-payload-check';

        // 1. Initial adjustment with delta +2
        $this->executionService->adjustDefectQty($exec, 2, $this->adminPpic->id, 'Tambah 2 pcs', $key);
        $this->assertSame(7, $exec->fresh()->defect_qty);

        // 2. Retry with SAME key but DIFFERENT delta (+4) must be strictly rejected
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Idempotency key '{$key}' telah digunakan sebelumnya dengan kuantitas yang berbeda (2 PCS)");

        $this->executionService->adjustDefectQty($exec, 4, $this->adminPpic->id, 'Tambah 4 pcs dengan key lama', $key);
    }

    /**
     * TEST 16: Reusing an idempotency key across DIFFERENT executions is rejected cleanly.
     */
    public function test_16_idempotency_key_reused_across_different_executions_is_rejected(): void
    {
        [, $lineA] = $this->createKtr(100, 'FLANGE_BESI', 'PLAN-A');
        [, $lineB] = $this->createKtr(100, 'FLANGE_BESI', 'PLAN-B');

        $execA = $this->executionService->markPhysicalDone($lineA->traveler_number, 'netto', $this->spvUser->id);
        $execB = $this->executionService->markPhysicalDone($lineB->traveler_number, 'netto', $this->spvUser->id);

        $this->executionService->recordDefectQty($execA, 5, $this->adminPpic->id);
        $this->executionService->recordDefectQty($execB, 5, $this->adminPpic->id);

        $sharedKey = 'idempotent-key-cross-ktr';

        // 1. Use key on Execution A
        $this->executionService->adjustDefectQty($execA, 2, $this->adminPpic->id, 'Adjust Exec A', $sharedKey);
        $this->assertSame(7, $execA->fresh()->defect_qty);

        // 2. Attempt to use same key on Execution B must be rejected cleanly without throwing 500
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Idempotency key '{$sharedKey}' telah digunakan pada eksekusi KTR lain.");

        $this->executionService->adjustDefectQty($execB, 2, $this->adminPpic->id, 'Adjust Exec B with same key', $sharedKey);
    }

    /**
     * TEST 17: Rejected adjustment preserves defect total and does not append orphan logs.
     */
    public function test_17_rejected_adjustment_preserves_defect_total_and_log_history(): void
    {
        [, $line] = $this->createKtr(100);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);
        $this->executionService->recordDefectQty($exec, 10, $this->adminPpic->id);

        $initialLogsCount = $exec->defectLogs()->count();
        $this->assertSame(1, $initialLogsCount);

        // Attempt invalid negative adjustment (would result in -5 pcs)
        try {
            $this->executionService->adjustDefectQty($exec, -15, $this->adminPpic->id, 'Invalid delta');
            $this->fail('Expected InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Total defect baru tidak boleh negatif', $e->getMessage());
        }

        // Verify defect_qty, good_qty, and logs count remain 100% untouched
        $fresh = $exec->fresh();
        $this->assertSame(10, $fresh->defect_qty);
        $this->assertSame(90, $fresh->good_qty);
        $this->assertSame($initialLogsCount, $fresh->defectLogs()->count());
    }

    /**
     * TEST 18: HTTP endpoint handles conflicting and duplicate idempotency keys with proper HTTP status codes.
     */
    public function test_18_http_endpoint_rejects_conflicting_idempotency_keys_with_422(): void
    {
        [, $line] = $this->createKtr(100);
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);
        $this->executionService->recordDefectQty($exec, 5, $this->adminPpic->id);

        $key = 'http-idempotency-test-key';

        // 1. First HTTP request with X-Idempotency-Key
        $res1 = $this->actingAs($this->adminPpic)->postJson("/sand-casting/defects/{$exec->id}/adjust", [
            'adjustment_qty' => 3,
            'notes' => 'HTTP adjustment 1',
        ], ['X-Idempotency-Key' => $key]);

        $res1->assertStatus(200);
        $res1->assertJsonPath('success', true);
        $this->assertSame(8, $exec->fresh()->defect_qty);

        // 2. Identical retry via HTTP must return 200 without doubling delta
        $resRetry = $this->actingAs($this->adminPpic)->postJson("/sand-casting/defects/{$exec->id}/adjust", [
            'adjustment_qty' => 3,
            'notes' => 'HTTP adjustment 1 retry',
        ], ['X-Idempotency-Key' => $key]);

        $resRetry->assertStatus(200);
        $this->assertSame(8, $exec->fresh()->defect_qty); // Still 8

        // 3. Request with SAME key but DIFFERENT delta must return 422 Unprocessable Entity
        $resMismatch = $this->actingAs($this->adminPpic)->postJson("/sand-casting/defects/{$exec->id}/adjust", [
            'adjustment_qty' => 5,
            'notes' => 'Conflicting payload',
        ], ['X-Idempotency-Key' => $key]);

        $resMismatch->assertStatus(422);
        $resMismatch->assertJsonPath('success', false);
        $this->assertStringContainsString('telah digunakan sebelumnya dengan kuantitas yang berbeda', $resMismatch->json('message'));
        $this->assertSame(8, $exec->fresh()->defect_qty); // Still 8
    }
}
