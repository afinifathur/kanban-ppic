<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\User;
use App\Services\SandCasting\SandCastingCastingResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CastingOrderModelATest extends TestCase
{
    use RefreshDatabase;

    protected User $ppicUser;

    protected SandCastingCastingResultService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $permission = Permission::firstOrCreate(['name' => 'access_planning']);
        $role = Role::firstOrCreate(['name' => 'ppic']);
        $role->givePermissionTo($permission);

        $this->ppicUser = User::factory()->create([
            'email' => 'ppic_flange@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->ppicUser->assignRole('ppic');

        $this->service = app(SandCastingCastingResultService::class);
    }

    protected function createPlan(int $qty = 200): ProductionPlan
    {
        return ProductionPlan::create([
            'code' => 'SC-MODEL-A',
            'title' => 'Rencana Model A',
            'item_code' => '4.101',
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-MODEL-A',
            'line_number' => 1,
            'qty_planned' => $qty,
            'qty_remaining' => $qty,
            'weight' => 2.50,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);
    }

    /**
     * Skenario 1: ProductionPlan 200 PCS, PCOR-001 ISSUED 200 PCS -> kuota tersedia 0.
     */
    public function test_scenario_1_production_plan_200_pcor_issued_200_available_quota_is_zero(): void
    {
        $plan = $this->createPlan(200);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261106-0001',
            'scheduled_date' => '2026-11-06',
            'status' => 'DRAFT',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 200,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        // Issue order
        $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.update-status', $order), ['status' => 'ISSUED'])
            ->assertRedirect();

        $plan->refresh();
        $this->assertEquals('ISSUED', $order->fresh()->status);
        $this->assertEquals(200, $plan->qty_casting_scheduled);
        $this->assertEquals(0, $plan->qty_remaining_casting_scheduled);
    }

    /**
     * Skenario 2: PCOR-001 Good 100 PCS -> status PARTIALLY_COMPLETED, kuota tersedia tetap 0.
     */
    public function test_scenario_2_pcor_partially_completed_with_good_100_available_quota_remains_zero(): void
    {
        $plan = $this->createPlan(200);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261106-0002',
            'scheduled_date' => '2026-11-06',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 200,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        // Record Heat 1: Good 100, Reject 0
        $this->service->recordResult([
            'heat_number' => 'HEAT-SCENARIO-02',
            'cast_date' => '2026-11-06',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 100,
                'qty_reject' => 0,
            ],
        ], $this->ppicUser->id);

        $order->refresh();
        $plan->refresh();

        $this->assertEquals('PARTIALLY_COMPLETED', $order->status);
        $this->assertEquals(100, $line->fresh()->qty_cast_good);
        $this->assertEquals(100, $line->fresh()->qty_remaining_to_cast);

        // Under Model A: scheduled committed remains 200 (qty_ordered), so remaining available is 0
        $this->assertEquals(200, $plan->qty_casting_scheduled);
        $this->assertEquals(0, $plan->qty_remaining_casting_scheduled);

        // Subquery in plans list also shows 0 remaining quota
        $plansResp = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-orders.plans', ['tab' => 'plans']));
        $plansResp->assertOk();
        $activePlans = $plansResp->viewData('plans');
        $this->assertNotContains($plan->id, $activePlans->pluck('id')->toArray());
    }

    /**
     * Skenario 3: PCOR-001 masih dapat menerima Good tambahan 100 PCS -> status COMPLETED.
     */
    public function test_scenario_3_pcor_partially_completed_can_receive_additional_100_good_becoming_completed(): void
    {
        $plan = $this->createPlan(200);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261106-0003',
            'scheduled_date' => '2026-11-06',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 200,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        // Heat 1: 100 Good
        $this->service->recordResult([
            'heat_number' => 'HEAT-SCENARIO-03A',
            'cast_date' => '2026-11-06',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 100,
                'qty_reject' => 0,
            ],
        ], $this->ppicUser->id);

        $this->assertEquals('PARTIALLY_COMPLETED', $order->fresh()->status);

        // Heat 2: Additional 100 Good
        $this->service->recordResult([
            'heat_number' => 'HEAT-SCENARIO-03B',
            'cast_date' => '2026-11-07',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 100,
                'qty_reject' => 0,
            ],
        ], $this->ppicUser->id);

        $order->refresh();
        $plan->refresh();

        $this->assertEquals('COMPLETED', $order->status);
        $this->assertEquals(200, $line->fresh()->qty_cast_good);
        $this->assertEquals(0, $line->fresh()->qty_remaining_to_cast);
        $this->assertEquals(200, $plan->qty_casting_scheduled);
        $this->assertEquals(0, $plan->qty_remaining_casting_scheduled);
    }

    /**
     * Skenario 4: Setelah Good 200 PCS (order COMPLETED), tambahan Heat baru harus ditolak.
     */
    public function test_scenario_4_after_full_good_additional_1_good_is_strictly_rejected(): void
    {
        $plan = $this->createPlan(200);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261106-0004',
            'scheduled_date' => '2026-11-06',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 200,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        // Record full 200 Good
        $this->service->recordResult([
            'heat_number' => 'HEAT-SCENARIO-04A',
            'cast_date' => '2026-11-06',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 200,
                'qty_reject' => 0,
            ],
        ], $this->ppicUser->id);

        $this->assertEquals('COMPLETED', $order->fresh()->status);
        $this->assertEquals(0, $line->fresh()->qty_remaining_to_cast);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sudah selesai (COMPLETED) dan tidak dapat menerima penambahan Heat baru');

        // Attempting to record 1 more Good on completed order must be rejected
        $this->service->recordResult([
            'heat_number' => 'HEAT-SCENARIO-04B',
            'cast_date' => '2026-11-07',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 1,
                'qty_reject' => 0,
            ],
        ], $this->ppicUser->id);
    }

    /**
     * Skenario 5: Input yang melebihi sisa kuota (over-plan) diizinkan dan mencatat seluruh Good aktual.
     */
    public function test_scenario_5_over_quota_input_is_allowed_and_commits_actual_good(): void
    {
        $plan = $this->createPlan(200);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261106-0005',
            'scheduled_date' => '2026-11-06',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 200,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        // First user inputs 150 Good
        $this->service->recordResult([
            'heat_number' => 'HEAT-SCENARIO-05A',
            'cast_date' => '2026-11-06',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 150,
                'qty_reject' => 0,
            ],
        ], $this->ppicUser->id);

        $this->assertEquals(150, $line->fresh()->qty_cast_good);
        $this->assertEquals(50, $line->fresh()->qty_remaining_to_cast);

        // Second user inputs 60 Good (exceeding remaining 50 -> total 210 pcs)
        $resB = $this->service->recordResult([
            'heat_number' => 'HEAT-SCENARIO-05B',
            'cast_date' => '2026-11-06',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 60,
                'qty_reject' => 0,
            ],
        ], $this->ppicUser->id);

        $this->assertNotNull($resB);
        $this->assertEquals(210, $line->fresh()->qty_cast_good);
        $this->assertEquals(0, $line->fresh()->qty_remaining_to_cast);
        $this->assertEquals('COMPLETED', $order->fresh()->status);

        // Model A commits actual 210, remaining quota is 0 (no duplicate PCOR can be issued)
        $this->assertEquals(210, $plan->fresh()->qty_casting_scheduled);
        $this->assertEquals(0, $plan->fresh()->qty_remaining_casting_scheduled);
    }

    /**
     * Skenario 6: Reject tidak boleh mengurangi outstanding Good.
     */
    public function test_scenario_6_reject_does_not_reduce_outstanding_good(): void
    {
        $plan = $this->createPlan(200);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261106-0006',
            'scheduled_date' => '2026-11-06',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 200,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        // Record 50 Good and 30 Reject
        $this->service->recordResult([
            'heat_number' => 'HEAT-SCENARIO-06',
            'cast_date' => '2026-11-06',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 50,
                'qty_reject' => 30,
            ],
        ], $this->ppicUser->id);

        $line->refresh();
        $this->assertEquals(50, $line->qty_cast_good);
        $this->assertEquals(30, $line->qty_cast_reject);
        // Outstanding is max(0, ordered - good) = 200 - 50 = 150 (NOT 200 - 80)
        $this->assertEquals(150, $line->qty_remaining_to_cast);
    }

    /**
     * Skenario 7: PCOR DRAFT tidak dapat menerima hasil cor.
     */
    public function test_scenario_7_draft_pcor_cannot_receive_casting_results(): void
    {
        $plan = $this->createPlan(200);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261106-0007',
            'scheduled_date' => '2026-11-06',
            'status' => 'DRAFT',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 200,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('harus berstatus ISSUED');

        $this->service->recordResult([
            'heat_number' => 'HEAT-SCENARIO-07',
            'cast_date' => '2026-11-06',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 10,
                'qty_reject' => 0,
            ],
        ], $this->ppicUser->id);
    }

    /**
     * Skenario 8: PCOR COMPLETED dan CANCELLED tidak dapat menerima hasil baru.
     */
    public function test_scenario_8_completed_and_cancelled_pcor_cannot_receive_new_results(): void
    {
        $plan = $this->createPlan(200);

        // 1. CANCELLED test
        $orderCancelled = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261106-0008A',
            'scheduled_date' => '2026-11-06',
            'status' => 'CANCELLED',
            'created_by' => $this->ppicUser->id,
        ]);
        $lineCancelled = $orderCancelled->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        try {
            $this->service->recordResult([
                'heat_number' => 'HEAT-SCENARIO-08A',
                'cast_date' => '2026-11-06',
            ], [
                [
                    'sand_casting_casting_order_line_id' => $lineCancelled->id,
                    'qty_good' => 10,
                    'qty_reject' => 0,
                ],
            ], $this->ppicUser->id);
            $this->fail('Expected exception for CANCELLED order.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('dibatalkan', $e->getMessage());
        }

        // 2. COMPLETED test: can receive additional physical outcomes
        $orderCompleted = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261106-0008B',
            'scheduled_date' => '2026-11-06',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);
        $lineCompleted = $orderCompleted->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        // Complete the order first (100 Good)
        $this->service->recordResult([
            'heat_number' => 'HEAT-SCENARIO-08B1',
            'cast_date' => '2026-11-06',
        ], [
            [
                'sand_casting_casting_order_line_id' => $lineCompleted->id,
                'qty_good' => 100,
                'qty_reject' => 0,
            ],
        ], $this->ppicUser->id);

        $this->assertEquals('COMPLETED', $orderCompleted->fresh()->status);

        // Additional physical heat on completed order is rejected
        try {
            $this->service->recordResult([
                'heat_number' => 'HEAT-SCENARIO-08B2',
                'cast_date' => '2026-11-06',
            ], [
                [
                    'sand_casting_casting_order_line_id' => $lineCompleted->id,
                    'qty_good' => 10,
                    'qty_reject' => 0,
                ],
            ], $this->ppicUser->id);
            $this->fail('Expected exception for COMPLETED order.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('COMPLETED', $e->getMessage());
        }
    }

    /**
     * Skenario 9: PCOR dengan hasil aktual tidak dapat dibatalkan.
     */
    public function test_scenario_9_pcor_with_actual_results_cannot_be_cancelled(): void
    {
        $plan = $this->createPlan(200);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261106-0009',
            'scheduled_date' => '2026-11-06',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 200,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        // Record outcome
        $this->service->recordResult([
            'heat_number' => 'HEAT-SCENARIO-09',
            'cast_date' => '2026-11-06',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 50,
                'qty_reject' => 0,
            ],
        ], $this->ppicUser->id);

        $this->assertTrue($order->fresh()->hasRecordedOutcomes());

        // Attempting to cancel via controller
        $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.update-status', $order), ['status' => 'CANCELLED'])
            ->assertSessionHas('error');

        $this->assertNotEquals('CANCELLED', $order->fresh()->status);
    }

    /**
     * Skenario 10: CANCELLED yang valid melepaskan kuota.
     */
    public function test_scenario_10_valid_cancelled_pcor_releases_quota(): void
    {
        $plan = $this->createPlan(200);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261106-0010',
            'scheduled_date' => '2026-11-06',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 200,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $plan->refresh();
        $this->assertEquals(0, $plan->qty_remaining_casting_scheduled);

        // Cancel order without outcomes
        $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.update-status', $order), ['status' => 'CANCELLED'])
            ->assertRedirect();

        $this->assertEquals('CANCELLED', $order->fresh()->status);
        $plan->refresh();
        $this->assertEquals(0, $plan->qty_casting_scheduled);
        $this->assertEquals(200, $plan->qty_remaining_casting_scheduled);
    }

    /**
     * Skenario 11: Accessor model dan subquery plans menghasilkan kuota tersedia yang sama.
     */
    public function test_scenario_11_model_accessor_and_plans_sql_subquery_produce_exact_same_available_quota(): void
    {
        $plan = $this->createPlan(200);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261106-0011',
            'scheduled_date' => '2026-11-06',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 120,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        // Partial result
        $this->service->recordResult([
            'heat_number' => 'HEAT-SCENARIO-11',
            'cast_date' => '2026-11-06',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 50,
                'qty_reject' => 0,
            ],
        ], $this->ppicUser->id);

        $this->assertEquals('PARTIALLY_COMPLETED', $order->fresh()->status);

        // 1. Model Accessor
        $planFresh = $plan->fresh();
        $accessorCommitted = $planFresh->qty_casting_scheduled;
        $accessorRemaining = $planFresh->qty_remaining_casting_scheduled;

        // 2. SQL Subquery from controller plans view
        $plansResp = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-orders.plans', ['tab' => 'plans']));
        $plansResp->assertOk();
        $plansInView = $plansResp->viewData('plans');
        $planFromSubquery = $plansInView->firstWhere('id', $plan->id);

        $this->assertNotNull($planFromSubquery);
        $subqueryCommitted = (int) $planFromSubquery->qty_casting_scheduled;
        $subqueryRemaining = $planFromSubquery->qty_remaining_casting_scheduled;

        // Both must be exactly 120 committed and 80 remaining
        $this->assertEquals(120, $accessorCommitted);
        $this->assertEquals(80, $accessorRemaining);
        $this->assertEquals($accessorCommitted, $subqueryCommitted);
        $this->assertEquals($accessorRemaining, $subqueryRemaining);
    }

    /**
     * Skenario 12: Dua PCOR tidak dapat mengunci kuantitas ProductionPlan yang sama melebihi sisa kuotanya.
     */
    public function test_scenario_12_two_pcors_cannot_lock_production_plan_exceeding_remaining_quota(): void
    {
        $plan = $this->createPlan(200);

        // PCOR-01 orders 120 PCS
        $order1 = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261106-0012A',
            'scheduled_date' => '2026-11-06',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);
        $order1->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 120,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        // Attempting to create PCOR-02 with 100 PCS (more than 80 remaining) fails with 422
        $failResp = $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.store'), [
                'casting_order_number' => 'PCOR-20261106-0012B',
                'scheduled_date' => '2026-11-06',
                'items' => [
                    ['production_plan_id' => $plan->id, 'qty_ordered' => 100, 'line_number' => 1],
                ],
            ]);
        $failResp->assertStatus(422);

        // Creating PCOR-02 with exactly 80 PCS succeeds
        $successResp = $this->actingAs($this->ppicUser)
            ->post(route('sand-casting.casting-orders.store'), [
                'casting_order_number' => 'PCOR-20261106-0012C',
                'scheduled_date' => '2026-11-06',
                'items' => [
                    ['production_plan_id' => $plan->id, 'qty_ordered' => 80, 'line_number' => 1],
                ],
            ]);
        $successResp->assertRedirect();

        $plan->refresh();
        $this->assertEquals(200, $plan->qty_casting_scheduled);
        $this->assertEquals(0, $plan->qty_remaining_casting_scheduled);
    }
}
