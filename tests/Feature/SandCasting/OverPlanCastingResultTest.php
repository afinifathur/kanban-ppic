<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResultCorrection;
use App\Models\SandCastingCastingResultLine;
use App\Models\User;
use App\Services\SandCasting\SandCastingCastingResultService;
use App\Services\SandCasting\SandCastingProductionStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OverPlanCastingResultTest extends TestCase
{
    use RefreshDatabase;

    protected User $ppicUser;

    protected SandCastingCastingResultService $resultService;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Queue::fake();

        $permission = Permission::firstOrCreate(['name' => 'access_planning']);
        $role = Role::firstOrCreate(['name' => 'ppic']);
        $role->givePermissionTo($permission);

        $this->ppicUser = User::factory()->create([
            'email' => 'ppic_overplan@peroniks.com',
            'product_scope' => null,
        ]);
        $this->ppicUser->assignRole($role);

        $this->resultService = app(SandCastingCastingResultService::class);
    }

    protected function createPlan(int $planned = 110, int $po = 100, string $code = '268ET010'): ProductionPlan
    {
        return ProductionPlan::create([
            'code' => $code,
            'item_code' => 'IC-'.$code,
            'item_name' => 'FLANGE 2" JIS 10K',
            'po_number' => 'PO-TEST-001',
            'line_number' => 1,
            'po_quantity' => $po,
            'qty_planned' => $planned,
            'qty_remaining' => $planned,
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'PENDING',
            'weight' => 2.50,
            'product_scope' => 'flange',
        ]);
    }

    protected function createIssuedOrder(ProductionPlan $plan, int $qtyOrdered = 110): array
    {
        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-OVERPLAN-'.uniqid(),
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
     * Skenario 1: Plan 110, actual 120 dalam satu Heat terakhir: berhasil disimpan, order status COMPLETED.
     */
    public function test_1_plan_110_actual_120_in_final_heat_saves_successfully(): void
    {
        $plan = $this->createPlan(110, 100);
        [$order, $line] = $this->createIssuedOrder($plan, 110);

        $result = $this->resultService->recordResult([
            'heat_number' => 'HEAT-OP-01',
            'cast_date' => '2026-11-09',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 120,
                'qty_reject' => 0,
            ],
        ], $this->ppicUser->id);

        $this->assertNotNull($result);
        $this->assertEquals(120, $line->fresh()->qty_cast_good);
        $this->assertEquals(0, $line->fresh()->qty_remaining_to_cast);
        $this->assertEquals('COMPLETED', $order->fresh()->status);
    }

    /**
     * Skenario 2: Akumulasi Heat 40 + 40 + 40 untuk plan 110: seluruh Heat berhasil disimpan (total 120 pcs).
     */
    public function test_2_accumulation_of_three_heats_40_40_40_for_plan_110_succeeds(): void
    {
        $plan = $this->createPlan(110, 100);
        [$order, $line] = $this->createIssuedOrder($plan, 110);

        // Heat 1: 40 pcs
        $this->resultService->recordResult([
            'heat_number' => 'HEAT-H1',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $line->id, 'qty_good' => 40, 'qty_reject' => 0],
        ], $this->ppicUser->id);
        $this->assertEquals(40, $line->fresh()->qty_cast_good);
        $this->assertEquals('PARTIALLY_COMPLETED', $order->fresh()->status);

        // Heat 2: 40 pcs
        $this->resultService->recordResult([
            'heat_number' => 'HEAT-H2',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $line->id, 'qty_good' => 40, 'qty_reject' => 0],
        ], $this->ppicUser->id);
        $this->assertEquals(80, $line->fresh()->qty_cast_good);
        $this->assertEquals('PARTIALLY_COMPLETED', $order->fresh()->status);

        // Heat 3: 40 pcs (surpasses plan 110 -> 120)
        $this->resultService->recordResult([
            'heat_number' => 'HEAT-H3',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $line->id, 'qty_good' => 40, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $this->assertEquals(120, $line->fresh()->qty_cast_good);
        $this->assertEquals(0, $line->fresh()->qty_remaining_to_cast);
        $this->assertEquals('COMPLETED', $order->fresh()->status);

        // Assert 3 distinct KTRs created
        $this->assertEquals(3, SandCastingCastingResultLine::where('sand_casting_casting_order_line_id', $line->id)->count());
    }

    /**
     * Skenario 3: Over-plan warning tidak memblokir submit form create via HTTP POST.
     */
    public function test_3_over_plan_warning_does_not_block_submission_on_web_form(): void
    {
        $plan = $this->createPlan(110, 100);
        [$order, $line] = $this->createIssuedOrder($plan, 110);

        $resp = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.create'));
        $resp->assertOk();
        $resp->assertSee($plan->code);

        // Submit over-plan payload (120 pcs Good vs 110 ordered)
        $postResp = $this->actingAs($this->ppicUser)->post(route('sand-casting.casting-results.store'), [
            'heat_number' => 'HEAT-POST-OVERPLAN',
            'cast_date' => '2026-11-09',
            'furnace' => 'F-01',
            'shift' => '1',
            'items' => [
                [
                    'sand_casting_casting_order_line_id' => $line->id,
                    'qty_good' => 120,
                    'qty_reject' => 0,
                ],
            ],
        ]);

        $postResp->assertRedirect();
        $postResp->assertSessionHas('success');
        $this->assertEquals(120, $line->fresh()->qty_cast_good);
        $this->assertEquals('COMPLETED', $order->fresh()->status);
    }

    /**
     * Skenario 4: Setelah PCOR menjadi COMPLETED, pencatatan Heat baru ditolak melalui service.
     */
    public function test_4_after_pcor_becomes_completed_new_heat_is_rejected_by_service(): void
    {
        $plan = $this->createPlan(110, 100);
        [$order, $line] = $this->createIssuedOrder($plan, 110);

        // Pour 110 exact -> reaches COMPLETED
        $this->resultService->recordResult([
            'heat_number' => 'HEAT-EXACT-110',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $line->id, 'qty_good' => 110, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $this->assertEquals('COMPLETED', $order->fresh()->status);

        // Attempting to record additional heat on completed PCOR must throw exception
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sudah selesai (COMPLETED) dan tidak dapat menerima penambahan Heat baru');

        $this->resultService->recordResult([
            'heat_number' => 'HEAT-ILLEGAL-EXTRA',
            'cast_date' => '2026-11-10',
        ], [
            ['sand_casting_casting_order_line_id' => $line->id, 'qty_good' => 10, 'qty_reject' => 0],
        ], $this->ppicUser->id);
    }

    /**
     * Skenario 5: PCOR COMPLETED tidak muncul pada candidate lines di form create.
     */
    public function test_5_completed_pcor_does_not_appear_in_candidate_lines(): void
    {
        $plan = $this->createPlan(110, 100, 'CODE-COMPLETED-HIDE');
        [$order, $line] = $this->createIssuedOrder($plan, 110);

        // Pour 110 to complete the order
        $this->resultService->recordResult([
            'heat_number' => 'HEAT-COMPLETE-HIDE',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $line->id, 'qty_good' => 110, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $this->assertEquals('COMPLETED', $order->fresh()->status);

        // Access create view: candidate lines must not include this completed order line
        $response = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.create'));
        $response->assertOk();

        $availableLines = $response->viewData('availableLines');
        $this->assertFalse($availableLines->contains('id', $line->id));
        $response->assertDontSee('CODE-COMPLETED-HIDE');
    }

    /**
     * Skenario 6: PCOR COMPLETED tidak bisa dilewati melalui parameter URL preselectedOrderId.
     */
    public function test_6_completed_pcor_cannot_be_bypassed_via_preselected_order_id(): void
    {
        $plan = $this->createPlan(110, 100, 'CODE-BYPASS-ATTEMPT');
        [$order, $line] = $this->createIssuedOrder($plan, 110);

        // Complete the order
        $this->resultService->recordResult([
            'heat_number' => 'HEAT-COMPLETE-BYPASS',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $line->id, 'qty_good' => 110, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $this->assertEquals('COMPLETED', $order->fresh()->status);

        // Attempt to access create with ?casting_order_id={completedOrder->id}
        $response = $this->actingAs($this->ppicUser)->get(
            route('sand-casting.casting-results.create', ['casting_order_id' => $order->id])
        );

        // Must redirect back with error message and NOT stage the completed order
        $response->assertRedirect(route('sand-casting.casting-results.create'));
        $response->assertSessionHas('error');
    }

    /**
     * Skenario 7: Direct POST pada PCOR COMPLETED ditolak oleh backend controller & service.
     */
    public function test_7_direct_post_on_completed_pcor_is_rejected(): void
    {
        $plan = $this->createPlan(110, 100, 'CODE-DIRECT-POST');
        [$order, $line] = $this->createIssuedOrder($plan, 110);

        // Complete the order first
        $this->resultService->recordResult([
            'heat_number' => 'HEAT-COMPLETE-DIRECT',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $line->id, 'qty_good' => 110, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $this->assertEquals('COMPLETED', $order->fresh()->status);

        // Direct POST to store endpoint targeting completed line
        $response = $this->actingAs($this->ppicUser)->post(route('sand-casting.casting-results.store'), [
            'heat_number' => 'HEAT-DIRECT-FORBIDDEN',
            'cast_date' => '2026-11-10',
            'items' => [
                [
                    'sand_casting_casting_order_line_id' => $line->id,
                    'qty_good' => 15,
                    'qty_reject' => 0,
                ],
            ],
        ]);

        $response->assertSessionHas('error');
        $this->assertEquals(110, $line->fresh()->qty_cast_good);

        // Direct JSON POST returns 422
        $jsonResp = $this->actingAs($this->ppicUser)->postJson(route('sand-casting.casting-results.store'), [
            'heat_number' => 'HEAT-DIRECT-JSON',
            'cast_date' => '2026-11-10',
            'items' => [
                [
                    'sand_casting_casting_order_line_id' => $line->id,
                    'qty_good' => 15,
                    'qty_reject' => 0,
                ],
            ],
        ]);

        $jsonResp->assertStatus(422);
        $jsonResp->assertJsonFragment(['success' => false]);
    }

    /**
     * Skenario 8: PCOR ISSUED dan PARTIALLY_COMPLETED tetap menerima over-plan.
     */
    public function test_8_issued_and_partially_completed_pcor_accept_over_plan(): void
    {
        // 1. ISSUED PCOR receiving over-plan in 1st heat
        $planA = $this->createPlan(50, 50, 'CODE-OVER-ISSUED');
        [$orderA, $lineA] = $this->createIssuedOrder($planA, 50);

        $resultA = $this->resultService->recordResult([
            'heat_number' => 'HEAT-OVER-ISSUED',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $lineA->id, 'qty_good' => 60, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $this->assertNotNull($resultA);
        $this->assertEquals(60, $lineA->fresh()->qty_cast_good);
        $this->assertEquals('COMPLETED', $orderA->fresh()->status);

        // 2. PARTIALLY_COMPLETED PCOR receiving over-plan in 2nd heat
        $planB = $this->createPlan(100, 100, 'CODE-OVER-PARTIAL');
        [$orderB, $lineB] = $this->createIssuedOrder($planB, 100);

        // Heat 1: 50 pcs -> PARTIALLY_COMPLETED
        $this->resultService->recordResult([
            'heat_number' => 'HEAT-PARTIAL-1',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $lineB->id, 'qty_good' => 50, 'qty_reject' => 0],
        ], $this->ppicUser->id);
        $this->assertEquals('PARTIALLY_COMPLETED', $orderB->fresh()->status);

        // Heat 2: 60 pcs (total 110 > 100) -> succeeds and becomes COMPLETED
        $resultB2 = $this->resultService->recordResult([
            'heat_number' => 'HEAT-PARTIAL-2',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $lineB->id, 'qty_good' => 60, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $this->assertNotNull($resultB2);
        $this->assertEquals(110, $lineB->fresh()->qty_cast_good);
        $this->assertEquals('COMPLETED', $orderB->fresh()->status);
    }

    /**
     * Skenario 9: Koreksi hasil existing mengikuti aturan lifecycle dan tidak merusak audit log.
     */
    public function test_9_correction_of_existing_result_on_completed_pcor_follows_lifecycle_and_records_audit_trail(): void
    {
        $plan = $this->createPlan(100, 100, 'CODE-CORRECTION');
        [$order, $line] = $this->createIssuedOrder($plan, 100);

        // Record heat 100 pcs -> COMPLETED
        $result = $this->resultService->recordResult([
            'heat_number' => 'HEAT-CORR-01',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $line->id, 'qty_good' => 100, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $this->assertEquals('COMPLETED', $order->fresh()->status);
        $resultLine = $result->lines->first();

        // 1. Correct existing line notes/weight on COMPLETED order: allowed
        $updated = $this->resultService->correctResult(
            $result,
            [
                'heat_number' => 'HEAT-CORR-01',
                'cast_date' => '2026-11-09',
                'reason' => 'Perbaikan catatan penuangan',
            ],
            [
                [
                    'id' => $resultLine->id,
                    'sand_casting_casting_order_line_id' => $line->id,
                    'qty_good' => 100,
                    'qty_reject' => 0,
                    'notes' => 'Catatan dikoreksi setelah audit',
                ],
            ],
            $this->ppicUser->id
        );

        $this->assertNotNull($updated);
        $this->assertEquals('Catatan dikoreksi setelah audit', $resultLine->fresh()->notes);

        // Verify audit log exists
        $auditLog = SandCastingCastingResultCorrection::where('sand_casting_casting_result_id', $result->id)
            ->where('field_name', 'notes')
            ->first();
        $this->assertNotNull($auditLog);
        $this->assertEquals('Catatan dikoreksi setelah audit', $auditLog->new_value);

        // 2. Correcting qty_good down from 100 to 90 reverts order back to PARTIALLY_COMPLETED
        $this->resultService->correctResult(
            $result,
            [
                'heat_number' => 'HEAT-CORR-01',
                'cast_date' => '2026-11-09',
                'reason' => 'Koreksi salah hitung timbang',
            ],
            [
                [
                    'id' => $resultLine->id,
                    'sand_casting_casting_order_line_id' => $line->id,
                    'qty_good' => 90,
                    'qty_reject' => 0,
                ],
            ],
            $this->ppicUser->id
        );

        $this->assertEquals(90, $line->fresh()->qty_cast_good);
        $this->assertEquals('PARTIALLY_COMPLETED', $order->fresh()->status);

        // 3. Attempting to reassign an existing line to a CANCELLED order is rejected
        $cancelledPlan = $this->createPlan(50, 50, 'CODE-OTHER-CANCEL');
        $cancelledOrder = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-CANCELLED-'.uniqid(),
            'scheduled_date' => '2026-11-09',
            'status' => 'CANCELLED',
            'created_by' => $this->ppicUser->id,
        ]);
        $cancelledLine = $cancelledOrder->lines()->create([
            'production_plan_id' => $cancelledPlan->id,
            'qty_ordered' => 50,
            'code' => $cancelledPlan->code,
            'item_name' => $cancelledPlan->item_name,
        ]);

        try {
            $this->resultService->correctResult(
                $result,
                [
                    'heat_number' => 'HEAT-CORR-01',
                    'cast_date' => '2026-11-09',
                    'reason' => 'Mencoba pindah ke order yang sudah CANCELLED',
                ],
                [
                    [
                        'id' => $resultLine->id,
                        'sand_casting_casting_order_line_id' => $cancelledLine->id,
                        'qty_good' => 90,
                        'qty_reject' => 0,
                    ],
                ],
                $this->ppicUser->id
            );
            $this->fail('Expected exception when reassigning line to a CANCELLED order.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak dalam status yang valid', $e->getMessage());
        }
    }

    /**
     * Skenario 10: Kode produksi baru untuk cor ulang (misal 2610AB21X) dapat membuat PCOR baru secara normal.
     */
    public function test_10_new_production_code_for_recast_creates_new_pcor_normally(): void
    {
        // Initial production code: 2610AB21 (Plan 100, poured 100 -> COMPLETED)
        $planInitial = $this->createPlan(100, 100, '2610AB21');
        [$orderInitial, $lineInitial] = $this->createIssuedOrder($planInitial, 100);

        $this->resultService->recordResult([
            'heat_number' => 'HEAT-INITIAL-01',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $lineInitial->id, 'qty_good' => 100, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $this->assertEquals('COMPLETED', $orderInitial->fresh()->status);

        // Replacement / Recast production code created: 2610AB21X
        $planRecast = $this->createPlan(30, 30, '2610AB21X');
        [$orderRecast, $lineRecast] = $this->createIssuedOrder($planRecast, 30);

        $this->assertEquals('ISSUED', $orderRecast->status);
        $this->assertEquals(30, $planRecast->fresh()->qty_casting_scheduled);

        // Recast PCOR can record heats normally
        $resultRecast = $this->resultService->recordResult([
            'heat_number' => 'HEAT-RECAST-01',
            'cast_date' => '2026-11-10',
        ], [
            ['sand_casting_casting_order_line_id' => $lineRecast->id, 'qty_good' => 30, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $this->assertNotNull($resultRecast);
        $this->assertEquals(30, $lineRecast->fresh()->qty_cast_good);
        $this->assertEquals('COMPLETED', $orderRecast->fresh()->status);

        // Initial order remains untouched
        $this->assertEquals(100, $lineInitial->fresh()->qty_cast_good);
        $this->assertEquals('COMPLETED', $orderInitial->fresh()->status);
    }

    /**
     * Skenario 11: Model A mengunci max(ordered, good) dan mencegah penerbitan PCOR ganda.
     */
    public function test_11_model_a_commits_higher_quantity_and_prevents_duplicate_pcor(): void
    {
        $plan = $this->createPlan(110, 100);
        [$order, $line] = $this->createIssuedOrder($plan, 110);

        // Record 120 Good (over-plan by 10)
        $this->resultService->recordResult([
            'heat_number' => 'HEAT-NO-LEAK',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $line->id, 'qty_good' => 120, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $planFresh = $plan->fresh();
        // Model commits max(110, 120) = 120
        $this->assertEquals(120, $planFresh->qty_casting_scheduled);
        $this->assertEquals(0, $planFresh->qty_remaining_casting_scheduled);

        // Attempting to issue a new PCOR for this plan fails because available quota is 0
        $newOrder = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-DUPLICATE-ATTEMPT',
            'scheduled_date' => '2026-11-10',
            'status' => 'DRAFT',
            'created_by' => $this->ppicUser->id,
        ]);
        $newLine = $newOrder->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 10,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $resp = $this->actingAs($this->ppicUser)->post(
            route('sand-casting.casting-orders.update-status', $newOrder),
            ['status' => 'ISSUED']
        );

        $resp->assertSessionHas('error');
        $this->assertEquals('DRAFT', $newOrder->fresh()->status);
    }

    /**
     * Skenario 12: Outstanding PO dan sisa kuota rencana cor tidak pernah bernilai negatif.
     */
    public function test_12_outstanding_po_never_becomes_negative(): void
    {
        $plan = $this->createPlan(110, 100);
        [$order, $line] = $this->createIssuedOrder($plan, 110);

        $this->resultService->recordResult([
            'heat_number' => 'HEAT-PO-NONNEG',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $line->id, 'qty_good' => 135, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $planFresh = $plan->fresh();
        $this->assertEquals(135, $planFresh->qty_casting_scheduled);
        $this->assertEquals(0, $planFresh->qty_remaining_casting_scheduled);
        $this->assertGreaterThanOrEqual(0, $planFresh->qty_remaining_casting_scheduled);

        $statusService = app(SandCastingProductionStatusService::class);
        $planRow = $statusService->calculatePlanStatusRow($planFresh);

        $this->assertNotNull($planRow);
        $this->assertGreaterThanOrEqual(0, $planRow['remaining_po']);
    }

    /**
     * Skenario 13: Final Quality Lock dan workflow downstream (netto) tidak mengalami regresi.
     */
    public function test_13_final_quality_lock_and_downstream_stages_have_no_regression(): void
    {
        $plan = $this->createPlan(110, 100);
        [$order, $line] = $this->createIssuedOrder($plan, 110);

        $result = $this->resultService->recordResult([
            'heat_number' => 'HEAT-DOWNSTREAM-KTR',
            'cast_date' => '2026-11-09',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 120,
                'qty_reject' => 5,
            ],
        ], $this->ppicUser->id);

        $resultLine = $result->lines->first();
        $this->assertNotNull($resultLine->traveler_number);
        $this->assertEquals(120, $resultLine->qty_good);
        $this->assertEquals('netto', $resultLine->current_stage);

        // Verify result line is persisted with netto stage and accurate traveler number
        $freshLine = SandCastingCastingResultLine::where('traveler_number', $resultLine->traveler_number)->first();
        $this->assertNotNull($freshLine);
        $this->assertEquals(120, $freshLine->qty_good);
        $this->assertEquals(5, $freshLine->qty_reject);
        $this->assertEquals('netto', $freshLine->current_stage);
    }
}
