<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResultCorrection;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use App\Services\SandCasting\SandCastingCastingResultService;
use App\Services\SandCasting\SandCastingStageExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PcorReopeningCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $ppicUser;

    protected SandCastingCastingResultService $resultService;

    protected SandCastingStageExecutionService $stageService;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $permission = Permission::firstOrCreate(['name' => 'access_planning']);
        $role = Role::firstOrCreate(['name' => 'ppic']);
        $role->givePermissionTo($permission);

        $this->ppicUser = User::factory()->create([
            'email' => 'ppic_reopen@peroniks.com',
            'product_scope' => null,
        ]);
        $this->ppicUser->assignRole($role);

        $this->resultService = app(SandCastingCastingResultService::class);
        $this->stageService = app(SandCastingStageExecutionService::class);
    }

    protected function createPlan(int $planned = 100, string $code = '26AB'): ProductionPlan
    {
        return ProductionPlan::create([
            'code' => $code,
            'item_code' => 'IC-'.$code,
            'item_name' => 'ITEM '.$code,
            'po_number' => 'PO-'.$code,
            'line_number' => 1,
            'po_quantity' => $planned,
            'qty_planned' => $planned,
            'qty_remaining' => $planned,
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'PENDING',
            'weight' => 3.00,
            'product_scope' => 'flange',
        ]);
    }

    protected function createIssuedOrder(ProductionPlan $plan, int $qtyOrdered = 100, ?string $orderNumber = null): array
    {
        $order = SandCastingCastingOrder::create([
            'casting_order_number' => $orderNumber ?? ('PCOR-'.$plan->code.'-'.uniqid()),
            'scheduled_date' => '2026-11-09',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => $qtyOrdered,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        return [$order, $line];
    }

    /**
     * Test 1: Koreksi kuantitas yang membuat COMPLETED tetap COMPLETED.
     */
    public function test_1_quantity_correction_keeping_completed_as_completed(): void
    {
        $plan = $this->createPlan(100, '26TEST-01');
        [$order, $line] = $this->createIssuedOrder($plan, 100);

        // Heat 1: 60 pcs
        $this->resultService->recordResult([
            'heat_number' => 'HEAT-T1-1',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $line->id, 'qty_good' => 60, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        // Heat 2: 60 pcs -> Total 120 (COMPLETED, over-plan by 20)
        $heat2 = $this->resultService->recordResult([
            'heat_number' => 'HEAT-T1-2',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $line->id, 'qty_good' => 60, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $this->assertEquals('COMPLETED', $order->fresh()->status);
        $this->assertEquals(120, $line->fresh()->qty_cast_good);

        // Admin corrects Heat 2 down from 60 to 50 pcs (Total becomes 110 >= 100)
        $heat2Line = $heat2->lines->first();
        $this->resultService->correctResult(
            $heat2,
            [
                'heat_number' => 'HEAT-T1-2',
                'cast_date' => '2026-11-09',
                'reason' => 'Koreksi kelebihan timbang 10 pcs',
            ],
            [
                [
                    'id' => $heat2Line->id,
                    'sand_casting_casting_order_line_id' => $line->id,
                    'qty_good' => 50,
                    'qty_reject' => 0,
                ],
            ],
            $this->ppicUser->id
        );

        // Total 110 >= 100: status remains COMPLETED
        $this->assertEquals(110, $line->fresh()->qty_cast_good);
        $this->assertEquals('COMPLETED', $order->fresh()->status);
    }

    /**
     * Test 2: Koreksi kuantitas yang membuat COMPLETED menjadi PARTIALLY_COMPLETED.
     */
    public function test_2_quantity_correction_making_completed_partially_completed(): void
    {
        $plan = $this->createPlan(100, '26TEST-02');
        [$order, $line] = $this->createIssuedOrder($plan, 100);

        // Heat 1: 60 pcs
        $this->resultService->recordResult([
            'heat_number' => 'HEAT-T2-1',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $line->id, 'qty_good' => 60, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        // Heat 2: 45 pcs -> Total 105 (COMPLETED)
        $heat2 = $this->resultService->recordResult([
            'heat_number' => 'HEAT-T2-2',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $line->id, 'qty_good' => 45, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $this->assertEquals('COMPLETED', $order->fresh()->status);
        $this->assertEquals(105, $line->fresh()->qty_cast_good);

        // Admin corrects Heat 2 from 45 down to 30 pcs (Total becomes 90 < 100)
        $heat2Line = $heat2->lines->first();
        $this->resultService->correctResult(
            $heat2,
            [
                'heat_number' => 'HEAT-T2-2',
                'cast_date' => '2026-11-09',
                'reason' => 'Koreksi salah hitung good penuangan',
            ],
            [
                [
                    'id' => $heat2Line->id,
                    'sand_casting_casting_order_line_id' => $line->id,
                    'qty_good' => 30,
                    'qty_reject' => 0,
                ],
            ],
            $this->ppicUser->id
        );

        // Total 90 < 100: order status reopens to PARTIALLY_COMPLETED!
        $this->assertEquals(90, $line->fresh()->qty_cast_good);
        $this->assertEquals(10, $line->fresh()->qty_remaining_to_cast);
        $this->assertEquals('PARTIALLY_COMPLETED', $order->fresh()->status);
    }

    /**
     * Test 3: Heat baru dapat dicatat setelah PCOR dibuka kembali menjadi PARTIALLY_COMPLETED.
     */
    public function test_3_new_heat_can_be_recorded_after_pcor_reopened(): void
    {
        $plan = $this->createPlan(100, '26TEST-03');
        [$order, $line] = $this->createIssuedOrder($plan, 100);

        // Heat 1: 100 pcs (COMPLETED)
        $heat1 = $this->resultService->recordResult([
            'heat_number' => 'HEAT-T3-1',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $line->id, 'qty_good' => 100, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $this->assertEquals('COMPLETED', $order->fresh()->status);

        // Before correction, new heat is rejected
        try {
            $this->resultService->recordResult([
                'heat_number' => 'HEAT-T3-REJECTED',
                'cast_date' => '2026-11-10',
            ], [
                ['sand_casting_casting_order_line_id' => $line->id, 'qty_good' => 10, 'qty_reject' => 0],
            ], $this->ppicUser->id);
            $this->fail('Expected exception for completed order.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('COMPLETED', $e->getMessage());
        }

        // Correct Heat 1 down from 100 to 80 pcs -> Reopens to PARTIALLY_COMPLETED
        $heat1Line = $heat1->lines->first();
        $this->resultService->correctResult(
            $heat1,
            [
                'heat_number' => 'HEAT-T3-1',
                'cast_date' => '2026-11-09',
                'reason' => 'Koreksi 20 pcs reject di cor',
            ],
            [
                [
                    'id' => $heat1Line->id,
                    'sand_casting_casting_order_line_id' => $line->id,
                    'qty_good' => 80,
                    'qty_reject' => 20,
                ],
            ],
            $this->ppicUser->id
        );

        $this->assertEquals('PARTIALLY_COMPLETED', $order->fresh()->status);

        // Now record Heat 2 for the reopened PCOR: succeeds! (Pour 30 Good -> Total 110 pcs)
        $heat2 = $this->resultService->recordResult([
            'heat_number' => 'HEAT-T3-2',
            'cast_date' => '2026-11-10',
        ], [
            ['sand_casting_casting_order_line_id' => $line->id, 'qty_good' => 30, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $this->assertNotNull($heat2);
        $this->assertEquals(110, $line->fresh()->qty_cast_good);
        $this->assertEquals('COMPLETED', $order->fresh()->status);
    }

    /**
     * Test 4: Koreksi production code / PCOR dari 26AB ke 26AC.
     */
    public function test_4_correction_of_production_code_and_pcor_from_26ab_to_26ac(): void
    {
        $planA = $this->createPlan(100, '26AB');
        $planB = $this->createPlan(100, '26AC');

        [$orderA, $lineA] = $this->createIssuedOrder($planA, 100, 'PCOR-26AB-001');
        [$orderB, $lineB] = $this->createIssuedOrder($planB, 100, 'PCOR-26AC-001');

        // Record Heat for Order A (20 pcs Good)
        $result = $this->resultService->recordResult([
            'heat_number' => 'HEAT-REASSIGN-01',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $lineA->id, 'qty_good' => 20, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $resLine = $result->lines->first();
        $this->assertEquals($lineA->id, $resLine->sand_casting_casting_order_line_id);
        $this->assertEquals($planA->id, $resLine->production_plan_id);

        // Reassign this KTR line from Order A (26AB) to Order B (26AC)
        $updated = $this->resultService->correctResult(
            $result,
            [
                'heat_number' => 'HEAT-REASSIGN-01',
                'cast_date' => '2026-11-09',
                'reason' => 'Salah input kode produksi: sebenarnya barang 26AC',
            ],
            [
                [
                    'id' => $resLine->id,
                    'sand_casting_casting_order_line_id' => $lineB->id,
                    'qty_good' => 20,
                    'qty_reject' => 0,
                ],
            ],
            $this->ppicUser->id
        );

        $resLineFresh = $resLine->fresh();
        $this->assertEquals($lineB->id, $resLineFresh->sand_casting_casting_order_line_id);
        $this->assertEquals($planB->id, $resLineFresh->production_plan_id);
    }

    /**
     * Test 5: Recalculation total dan status kedua PCOR.
     */
    public function test_5_recalculation_of_totals_and_statuses_for_both_pcors(): void
    {
        $planA = $this->createPlan(100, '26AB');
        $planB = $this->createPlan(100, '26AC');

        [$orderA, $lineA] = $this->createIssuedOrder($planA, 100, 'PCOR-26AB-002');
        [$orderB, $lineB] = $this->createIssuedOrder($planB, 100, 'PCOR-26AC-002');

        // Order A has Heat 1 (80 pcs) and Heat 2 (20 pcs) -> Total 100 (COMPLETED)
        $this->resultService->recordResult([
            'heat_number' => 'HEAT-A-1',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $lineA->id, 'qty_good' => 80, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $heatA2 = $this->resultService->recordResult([
            'heat_number' => 'HEAT-A-2',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $lineA->id, 'qty_good' => 20, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $this->assertEquals('COMPLETED', $orderA->fresh()->status);
        $this->assertEquals(100, $lineA->fresh()->qty_cast_good);

        // Order B has Heat B1 (80 pcs) -> PARTIALLY_COMPLETED
        $this->resultService->recordResult([
            'heat_number' => 'HEAT-B-1',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $lineB->id, 'qty_good' => 80, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $this->assertEquals('PARTIALLY_COMPLETED', $orderB->fresh()->status);
        $this->assertEquals(80, $lineB->fresh()->qty_cast_good);

        // Move Heat A2 (20 pcs) from Order A to Order B
        $heatA2Line = $heatA2->lines->first();
        $this->resultService->correctResult(
            $heatA2,
            [
                'heat_number' => 'HEAT-A-2',
                'cast_date' => '2026-11-09',
                'reason' => 'Pindah alokasi KTR ke PCOR 26AC yang sebenarnya',
            ],
            [
                [
                    'id' => $heatA2Line->id,
                    'sand_casting_casting_order_line_id' => $lineB->id,
                    'qty_good' => 20,
                    'qty_reject' => 0,
                ],
            ],
            $this->ppicUser->id
        );

        // Order A (Source): 100 - 20 = 80 Good -> Reopens to PARTIALLY_COMPLETED!
        $this->assertEquals(80, $lineA->fresh()->qty_cast_good);
        $this->assertEquals(20, $lineA->fresh()->qty_remaining_to_cast);
        $this->assertEquals('PARTIALLY_COMPLETED', $orderA->fresh()->status);

        // Order B (Target): 80 + 20 = 100 Good -> Becomes COMPLETED!
        $this->assertEquals(100, $lineB->fresh()->qty_cast_good);
        $this->assertEquals(0, $lineB->fresh()->qty_remaining_to_cast);
        $this->assertEquals('COMPLETED', $orderB->fresh()->status);

        // Model A Quota validation:
        // Order A is PARTIALLY_COMPLETED, commits max(100, 80) = 100
        $this->assertEquals(100, $planA->fresh()->qty_casting_scheduled);
        $this->assertEquals(0, $planA->fresh()->qty_remaining_casting_scheduled);

        // Order B is COMPLETED, commits max(100, 100) = 100
        $this->assertEquals(100, $planB->fresh()->qty_casting_scheduled);
        $this->assertEquals(0, $planB->fresh()->qty_remaining_casting_scheduled);
    }

    /**
     * Test 6: Rollback seluruh perubahan apabila salah satu proses koreksi gagal.
     */
    public function test_6_rollback_of_entire_correction_transaction_if_any_step_fails(): void
    {
        $plan = $this->createPlan(100, '26ROLLBACK');
        [$order, $line] = $this->createIssuedOrder($plan, 100);

        $result = $this->resultService->recordResult([
            'heat_number' => 'HEAT-RB-01',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $line->id, 'qty_good' => 100, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $resLine = $result->lines->first();
        $this->assertEquals('COMPLETED', $order->fresh()->status);
        $this->assertEquals(100, $resLine->qty_good);

        // Attempt correction with invalid line id (should throw exception and rollback)
        try {
            $this->resultService->correctResult(
                $result,
                [
                    'heat_number' => 'HEAT-RB-01-FAIL',
                    'cast_date' => '2026-11-09',
                    'reason' => 'Simulasi kegagalan',
                ],
                [
                    [
                        'id' => 999999, // Non-existent line ID
                        'sand_casting_casting_order_line_id' => $line->id,
                        'qty_good' => 50,
                        'qty_reject' => 0,
                    ],
                ],
                $this->ppicUser->id
            );
            $this->fail('Expected exception for non-existent line ID.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak valid', $e->getMessage());
        }

        // Verify database state is untouched (rolled back)
        $this->assertEquals('HEAT-RB-01', $result->fresh()->heat_number);
        $this->assertEquals(100, $resLine->fresh()->qty_good);
        $this->assertEquals('COMPLETED', $order->fresh()->status);
        $this->assertEquals(0, SandCastingCastingResultCorrection::where('sand_casting_casting_result_id', $result->id)->count());
    }

    /**
     * Test 7: Audit trail koreksi lengkap menyimpan nilai lama, baru, kode produksi, dan nomor PCOR.
     */
    public function test_7_audit_trail_captures_complete_history_with_codes_and_pcor_numbers(): void
    {
        $planA = $this->createPlan(100, '26AB');
        $planB = $this->createPlan(100, '26AC');

        [$orderA, $lineA] = $this->createIssuedOrder($planA, 100, 'PCOR-AUDIT-A');
        [$orderB, $lineB] = $this->createIssuedOrder($planB, 100, 'PCOR-AUDIT-B');

        $result = $this->resultService->recordResult([
            'heat_number' => 'HEAT-AUDIT-01',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $lineA->id, 'qty_good' => 50, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $resLine = $result->lines->first();

        // Move to 26AC and adjust qty from 50 to 45
        $this->resultService->correctResult(
            $result,
            [
                'heat_number' => 'HEAT-AUDIT-01',
                'cast_date' => '2026-11-09',
                'reason' => 'Perbaikan alokasi KTR dan penyesuaian timbang',
            ],
            [
                [
                    'id' => $resLine->id,
                    'sand_casting_casting_order_line_id' => $lineB->id,
                    'qty_good' => 45,
                    'qty_reject' => 0,
                ],
            ],
            $this->ppicUser->id
        );

        // Verify audit records
        $corrections = SandCastingCastingResultCorrection::where('sand_casting_casting_result_id', $result->id)->get();

        // 1. Audit production_code
        $codeAudit = $corrections->firstWhere('field_name', 'production_code');
        $this->assertNotNull($codeAudit);
        $this->assertEquals('26AB', $codeAudit->old_value);
        $this->assertEquals('26AC', $codeAudit->new_value);
        $this->assertEquals('Perbaikan alokasi KTR dan penyesuaian timbang', $codeAudit->reason);
        $this->assertEquals($this->ppicUser->id, $codeAudit->corrected_by);

        // 2. Audit casting_order_number
        $pcorAudit = $corrections->firstWhere('field_name', 'casting_order_number');
        $this->assertNotNull($pcorAudit);
        $this->assertEquals('PCOR-AUDIT-A', $pcorAudit->old_value);
        $this->assertEquals('PCOR-AUDIT-B', $pcorAudit->new_value);

        // 3. Audit qty_good
        $qtyAudit = $corrections->firstWhere('field_name', 'qty_good');
        $this->assertNotNull($qtyAudit);
        $this->assertEquals('50', (string) $qtyAudit->old_value);
        $this->assertEquals('45', (string) $qtyAudit->new_value);

        // 4. Audit foreign keys
        $lineAudit = $corrections->firstWhere('field_name', 'sand_casting_casting_order_line_id');
        $this->assertNotNull($lineAudit);
        $this->assertEquals($lineA->id, (int) $lineAudit->old_value);
        $this->assertEquals($lineB->id, (int) $lineAudit->new_value);
    }

    /**
     * Test 8: Penolakan koreksi yang melanggar Final Quality Lock dan execution gate.
     */
    public function test_8_rejection_of_correction_violating_final_quality_lock_and_execution_gates(): void
    {
        $plan = $this->createPlan(100, '26GATE-LOCK');
        [$order, $line] = $this->createIssuedOrder($plan, 100);

        $result = $this->resultService->recordResult([
            'heat_number' => 'HEAT-GATE-01',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $line->id, 'qty_good' => 100, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $resLine = $result->lines->first();

        // Create physical execution on this KTR (e.g. Netto defect recorded)
        $nettoExec = $resLine->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_STAGE',
            'input_qty' => 100,
            'defect_qty' => 2,
            'good_qty' => 98,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->ppicUser->id,
            'executed_at' => now(),
            'physical_done_at' => now(),
        ]);

        $this->assertTrue($resLine->hasPhysicalExecution());

        // 1. Attempting to change Qty Good on KTR with physical execution is rejected
        try {
            $this->resultService->correctResult(
                $result,
                [
                    'heat_number' => 'HEAT-GATE-01',
                    'cast_date' => '2026-11-09',
                    'reason' => 'Coba ubah kuantitas KTR yang sudah ada eksekusi fisik',
                ],
                [
                    [
                        'id' => $resLine->id,
                        'sand_casting_casting_order_line_id' => $line->id,
                        'qty_good' => 95,
                        'qty_reject' => 0,
                    ],
                ],
                $this->ppicUser->id
            );
            $this->fail('Expected exception when modifying qty_good of post-gate KTR.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('terkunci dan tidak dapat diubah karena KTR sudah memiliki eksekusi fisik', $e->getMessage());
        }

        // 2. Attempting to reassign PCOR / item on KTR with physical execution is rejected
        $otherPlan = $this->createPlan(100, '26OTHER');
        [$otherOrder, $otherLine] = $this->createIssuedOrder($otherPlan, 100);

        try {
            $this->resultService->correctResult(
                $result,
                [
                    'heat_number' => 'HEAT-GATE-01',
                    'cast_date' => '2026-11-09',
                    'reason' => 'Coba pindah PCOR KTR yang sudah ada eksekusi fisik',
                ],
                [
                    [
                        'id' => $resLine->id,
                        'sand_casting_casting_order_line_id' => $otherLine->id,
                        'qty_good' => 100,
                        'qty_reject' => 0,
                    ],
                ],
                $this->ppicUser->id
            );
            $this->fail('Expected exception when reassigning order line of post-gate KTR.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('terkunci dan tidak dapat diubah karena KTR sudah memiliki eksekusi fisik', $e->getMessage());
        }
    }
}
