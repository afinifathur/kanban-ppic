<?php

namespace Tests\Feature\SandCasting;

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

class StageExecutionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected SandCastingStageExecutionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::firstOrCreate(['name' => 'access_planning']);
        $role = Role::firstOrCreate(['name' => 'ppic']);
        $role->givePermissionTo($permission);

        $this->user = User::factory()->create([
            'email' => 'operator_sc@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->user->assignRole('ppic');

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
            'created_by' => $this->user->id,
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
            'recorded_by' => $this->user->id,
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
            'last_printed_by' => $this->user->id,
            'current_stage' => 'netto',
        ], $attributes));
    }

    /**
     * TEST 1: NETTO execution
     * COR 100, defect 5 -> input 100, good 95, current_stage = bubut_od
     */
    public function test_netto_execution_resolves_cor_qty_and_advances_stage(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        $execution = $this->service->execute(
            travelerNumber: $line->traveler_number,
            targetStage: 'netto',
            defectQty: 5,
            operatorId: $this->user->id,
            notes: 'Netto potong selesai'
        );

        $this->assertInstanceOf(SandCastingStageExecution::class, $execution);
        $this->assertEquals('netto', $execution->stage);
        $this->assertEquals(100, $execution->input_qty);
        $this->assertEquals(5, $execution->defect_qty);
        $this->assertEquals(95, $execution->good_qty);
        $this->assertEquals($this->user->id, $execution->operator_id);
        $this->assertEquals('Netto potong selesai', $execution->notes);

        $line->refresh();
        $this->assertEquals('bubut_od', $line->current_stage);
    }

    /**
     * TEST 2: BUBUT OD execution
     * Previous NETTO good = 95, defect 3 -> input 95, good 92, current_stage = marking
     */
    public function test_bubut_od_execution_resolves_previous_stage_good_qty(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        // Execute NETTO
        $this->service->execute(
            travelerNumber: $line->traveler_number,
            targetStage: 'netto',
            defectQty: 5,
            operatorId: $this->user->id
        );

        // Execute BUBUT_OD
        $execution = $this->service->execute(
            travelerNumber: $line->traveler_number,
            targetStage: 'bubut_od',
            defectQty: 3,
            operatorId: $this->user->id,
            notes: 'Bubut OD selesai'
        );

        $this->assertEquals('bubut_od', $execution->stage);
        $this->assertEquals(95, $execution->input_qty);
        $this->assertEquals(3, $execution->defect_qty);
        $this->assertEquals(92, $execution->good_qty);

        $line->refresh();
        $this->assertEquals('marking', $line->current_stage);
    }

    /**
     * TEST 3: Full 7-stage chain execution
     * 100 -> 95 -> 92 -> 91 -> 91 -> 89 -> 88 -> gudang_jadi -> completed
     */
    public function test_full_chain_quantity_continuity_and_completion(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        // 1. NETTO (100 in, 5 defect -> 95 good)
        $this->service->execute($line->traveler_number, 'netto', 5, $this->user->id);
        $line->refresh();
        $this->assertEquals('bubut_od', $line->current_stage);

        // 2. BUBUT OD (95 in, 3 defect -> 92 good)
        $this->service->execute($line->traveler_number, 'bubut_od', 3, $this->user->id);
        $line->refresh();
        $this->assertEquals('marking', $line->current_stage);

        // 3. MARKING (92 in, 1 defect -> 91 good)
        $this->service->execute($line->traveler_number, 'marking', 1, $this->user->id);
        $line->refresh();
        $this->assertEquals('bubut_cnc', $line->current_stage);

        // 4. BUBUT CNC (91 in, 0 defect -> 91 good across CNC_MACHINING, QC_POST_CNC, QC_PRE_BOR)
        $this->service->execute($line->traveler_number, 'bubut_cnc', 0, $this->user->id);
        $this->service->execute($line->traveler_number, 'bubut_cnc', 0, $this->user->id);
        $this->service->execute($line->traveler_number, 'bubut_cnc', 0, $this->user->id);
        $line->refresh();
        $this->assertEquals('bor', $line->current_stage);

        // 5. BOR (91 in, 2 defect -> 89 good)
        $this->service->execute($line->traveler_number, 'bor', 2, $this->user->id);
        $line->refresh();
        $this->assertEquals('qc', $line->current_stage);

        // 6. QC (89 in, 1 defect -> 88 good)
        $this->service->execute($line->traveler_number, 'qc', 1, $this->user->id);
        $line->refresh();
        $this->assertEquals('gudang_jadi', $line->current_stage);

        // 7. GUDANG JADI (88 in, 0 defect -> 88 good)
        $gudangExec = $this->service->execute($line->traveler_number, 'gudang_jadi', 0, $this->user->id);
        $this->assertEquals('gudang_jadi', $gudangExec->stage);
        $this->assertEquals(88, $gudangExec->input_qty);
        $this->assertEquals(0, $gudangExec->defect_qty);
        $this->assertEquals(88, $gudangExec->good_qty);

        $line->refresh();
        $this->assertEquals('completed', $line->current_stage);
        $this->assertCount(9, $line->stageExecutions);
    }

    /**
     * TEST 4: Defect > input rejection
     */
    public function test_reject_defect_greater_than_input_qty(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Jumlah defect (105) tidak boleh melebihi jumlah input (100).');

        $this->service->execute($line->traveler_number, 'netto', 105, $this->user->id);
    }

    /**
     * TEST 5: Negative defect rejection
     */
    public function test_reject_negative_defect_qty(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Jumlah defect tidak boleh negatif.');

        $this->service->execute($line->traveler_number, 'netto', -1, $this->user->id);
    }

    /**
     * TEST 6: Target stage skip rejection (NETTO -> BUBUT_CNC)
     */
    public function test_reject_target_stage_skip(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("KTR {$line->traveler_number} saat ini berada di stage NETTO. Tahap yang valid adalah NETTO, bukan BUBUT CNC.");

        $this->service->execute($line->traveler_number, 'bubut_cnc', 0, $this->user->id);
    }

    /**
     * TEST 7: Current stage mismatch (current = bubut_od, target = netto)
     */
    public function test_reject_current_stage_mismatch(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        // Execute NETTO successfully -> advances to bubut_od
        $this->service->execute($line->traveler_number, 'netto', 5, $this->user->id);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("KTR {$line->traveler_number} saat ini berada di stage BUBUT OD. Tahap yang valid adalah BUBUT OD, bukan NETTO.");

        // Attempting to execute NETTO again on a BUBUT_OD stage
        $this->service->execute($line->traveler_number, 'netto', 0, $this->user->id);
    }

    /**
     * TEST 8: Completed KTR rejection
     */
    public function test_reject_execution_on_completed_ktr(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'completed']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("KTR {$line->traveler_number} sudah selesai diproses (completed) dan tidak dapat dieksekusi lagi.");

        $this->service->execute($line->traveler_number, 'gudang_jadi', 0, $this->user->id);
    }

    /**
     * TEST 9: Historical current_stage NULL rejection
     */
    public function test_reject_historical_ktr_with_null_current_stage(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => null]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("KTR {$line->traveler_number} belum memiliki operational stage dan tidak dapat diproses.");

        $this->service->execute($line->traveler_number, 'netto', 0, $this->user->id);
    }

    /**
     * TEST 10: goodQty = 0
     * Execution recorded, NOT completed, current_stage halted at targetStage (no auto advance)
     */
    public function test_zero_good_qty_records_execution_and_halts_stage_without_auto_advancing(): void
    {
        $line = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'netto']);

        // All 50 units are scrap in NETTO
        $execution = $this->service->execute($line->traveler_number, 'netto', 50, $this->user->id, 'Semua scrap saat potong');

        $this->assertEquals(50, $execution->input_qty);
        $this->assertEquals(50, $execution->defect_qty);
        $this->assertEquals(0, $execution->good_qty);

        $line->refresh();
        // current_stage remains at netto (halted, does not move to bubut_od, and is NOT marked completed)
        $this->assertEquals('netto', $line->current_stage);
        $this->assertCount(1, $line->stageExecutions);
    }

    /**
     * TEST 11: COR qty_good remains unchanged
     */
    public function test_cor_qty_good_and_qty_reject_remain_immutable(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'qty_reject' => 2, 'current_stage' => 'netto']);

        $this->service->execute($line->traveler_number, 'netto', 10, $this->user->id);

        $line->refresh();
        $this->assertEquals(100, $line->qty_good);
        $this->assertEquals(2, $line->qty_reject);
    }

    /**
     * TEST 12: traveler_number remains unchanged
     */
    public function test_traveler_number_remains_unchanged(): void
    {
        $line = $this->createKtrLine(['traveler_number' => 'KTR-20260920-8888', 'current_stage' => 'netto']);

        $this->service->execute('KTR-20260920-8888', 'netto', 5, $this->user->id);

        $line->refresh();
        $this->assertEquals('KTR-20260920-8888', $line->traveler_number);
    }

    /**
     * TEST 13: Existing print tracking remains unchanged
     */
    public function test_print_tracking_metadata_remains_unchanged(): void
    {
        $printedAt = now()->subHours(2);
        $line = $this->createKtrLine([
            'current_stage' => 'netto',
            'print_count' => 4,
            'printed_at' => $printedAt,
            'last_printed_by' => $this->user->id,
        ]);

        $this->service->execute($line->traveler_number, 'netto', 5, $this->user->id);

        $line->refresh();
        $this->assertEquals(4, $line->print_count);
        $this->assertEquals($this->user->id, $line->last_printed_by);
        $this->assertEquals($printedAt->toDateTimeString(), $line->printed_at->toDateTimeString());
    }

    /**
     * TEST 14: Duplicate execution rejected
     */
    public function test_duplicate_execution_on_same_stage_is_rejected(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        // First execution succeeds
        $this->service->execute($line->traveler_number, 'netto', 5, $this->user->id);

        // Refresh and manually reset current_stage to netto to simulate an attempt to re-execute a completed stage
        $line->refresh();
        $line->current_stage = 'netto';
        $line->save();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("KTR {$line->traveler_number} tidak memiliki checkpoint aktif yang siap diproses.");

        $this->service->execute($line->traveler_number, 'netto', 2, $this->user->id);
    }

    /**
     * TEST 15: Invalid traveler number rejected
     */
    public function test_reject_empty_or_nonexistent_traveler_number(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Nomor Traveler (KTR) wajib diisi.');
        $this->service->execute('', 'netto', 0, $this->user->id);
    }

    /**
     * TEST 16: Non-existent traveler number rejected
     */
    public function test_reject_nonexistent_traveler_number(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("KTR dengan nomor 'KTR-99999999-9999' tidak ditemukan.");
        $this->service->execute('KTR-99999999-9999', 'netto', 0, $this->user->id);
    }

    /**
     * TEST 17: Invalid target stage rejected
     */
    public function test_reject_invalid_target_stage(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Tahap target 'cor' tidak valid dalam alur eksekusi Sand Casting.");
        $this->service->execute($line->traveler_number, 'cor', 0, $this->user->id);
    }

    /**
     * TEST 18: Invalid operator ID rejected
     */
    public function test_reject_nonexistent_operator_id(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Operator dengan ID 99999 tidak ditemukan.');
        $this->service->execute($line->traveler_number, 'netto', 0, 99999);
    }

    /**
     * TEST 19: Database unique constraint catch translation
     */
    public function test_database_unique_constraint_is_caught_and_translated_to_domain_error(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        // Insert stage execution directly into DB to simulate race condition where check passed before lock
        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 0,
            'good_qty' => 100,
            'status' => SandCastingStageExecution::STATUS_READY,
            'operator_id' => $this->user->id,
            'executed_at' => now(),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("KTR {$line->traveler_number} sudah pernah dieksekusi pada checkpoint NETTO_CUT");

        $this->service->execute($line->traveler_number, 'netto', 5, $this->user->id);
    }
}
