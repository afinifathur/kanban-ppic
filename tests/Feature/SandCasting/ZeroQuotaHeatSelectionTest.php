<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\User;
use App\Services\SandCasting\SandCastingCastingResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ZeroQuotaHeatSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $ppicUser;

    protected SandCastingCastingResultService $resultService;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $permission = Permission::firstOrCreate(['name' => 'access_planning']);
        $role = Role::firstOrCreate(['name' => 'ppic']);
        $role->givePermissionTo($permission);

        $this->ppicUser = User::factory()->create([
            'email' => 'ppic_zeroquota@peroniks.com',
            'product_scope' => null,
        ]);
        $this->ppicUser->assignRole($role);

        $this->resultService = app(SandCastingCastingResultService::class);
    }

    protected function createPlan(int $planned = 100, string $code = '26TEST'): ProductionPlan
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
            'weight' => 2.50,
            'product_scope' => 'flange',
        ]);
    }

    protected function createMultiLineOrder(array $lineDefs): array
    {
        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-MULTI-'.uniqid(),
            'scheduled_date' => '2026-11-09',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $lines = [];
        foreach ($lineDefs as $def) {
            $plan = $this->createPlan($def['qty'], $def['code']);
            $lines[$def['code']] = $order->lines()->create([
                'production_plan_id' => $plan->id,
                'qty_ordered' => $def['qty'],
                'code' => $plan->code,
                'item_name' => $plan->item_name,
            ]);
        }

        return [$order, $lines];
    }

    /**
     * Test 1: Candidate isolation in multi-line PCOR.
     * Line A (remaining = 0) is excluded from $availableLines, Line B (remaining = 20) is included.
     */
    public function test_1_multiline_pcor_candidate_isolation_excludes_zero_quota_line(): void
    {
        [$order, $lines] = $this->createMultiLineOrder([
            ['code' => '2610L001', 'qty' => 20],
            ['code' => '269UN152', 'qty' => 20],
        ]);

        $lineA = $lines['2610L001'];
        $lineB = $lines['269UN152'];

        // Cast Item A fully (20 pcs) in Heat 1
        $this->resultService->recordResult([
            'heat_number' => 'HEAT-ISO-01',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $lineA->id, 'qty_good' => 20, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $order->refresh();
        $this->assertEquals('PARTIALLY_COMPLETED', $order->status);
        $this->assertEquals(0, $lineA->fresh()->qty_remaining_to_cast);
        $this->assertEquals(20, $lineB->fresh()->qty_remaining_to_cast);

        // Fetch create page
        $response = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.create'));
        $response->assertOk();

        $availableLines = $response->viewData('availableLines');

        // Line A must be excluded
        $this->assertFalse($availableLines->contains('id', $lineA->id), 'Line A with 0 remaining quota must NOT be in availableLines');

        // Line B must be included
        $this->assertTrue($availableLines->contains('id', $lineB->id), 'Line B with positive remaining quota must be in availableLines');
    }

    /**
     * Test 2: Direct HTTP POST on zero-quota line in PARTIALLY_COMPLETED PCOR is rejected with 422.
     */
    public function test_2_direct_post_on_zero_quota_line_rejected_with_422(): void
    {
        [$order, $lines] = $this->createMultiLineOrder([
            ['code' => '2610L001', 'qty' => 10],
            ['code' => '269UN152', 'qty' => 20],
        ]);

        $lineA = $lines['2610L001'];
        $lineB = $lines['269UN152'];

        // Cast Item A to completion (10 pcs)
        $this->resultService->recordResult([
            'heat_number' => 'HEAT-POST-01',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $lineA->id, 'qty_good' => 10, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $this->assertEquals('PARTIALLY_COMPLETED', $order->fresh()->status);
        $this->assertEquals(0, $lineA->fresh()->qty_remaining_to_cast);

        $initialLineACount = SandCastingCastingResultLine::where('sand_casting_casting_order_line_id', $lineA->id)->count();

        // Direct HTTP POST targeting Line A with 5 pcs
        $response = $this->actingAs($this->ppicUser)->postJson(route('sand-casting.casting-results.store'), [
            'heat_number' => 'HEAT-ILLEGAL-POST',
            'cast_date' => '2026-11-09',
            'items' => [
                ['sand_casting_casting_order_line_id' => $lineA->id, 'qty_good' => 5, 'qty_reject' => 0],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'success' => false,
        ]);
        $this->assertStringContainsString('sudah terpenuhi', $response->json('message'));
        $this->assertStringContainsString('0 pcs', $response->json('message'));

        // Verify no new result line created
        $this->assertEquals($initialLineACount, SandCastingCastingResultLine::where('sand_casting_casting_order_line_id', $lineA->id)->count());
    }

    /**
     * Test 3: Legitimate final Heat over-plan is allowed when remaining > 0, then blocks subsequent Heat.
     */
    public function test_3_legitimate_final_heat_overplan_allowed_then_blocks_subsequent_heat(): void
    {
        [$order, $lines] = $this->createMultiLineOrder([
            ['code' => '2610L001', 'qty' => 10],
            ['code' => '269UN152', 'qty' => 10],
        ]);

        $lineA = $lines['2610L001'];
        $lineB = $lines['269UN152'];

        // Finish Line A exactly
        $this->resultService->recordResult([
            'heat_number' => 'HEAT-OP-A',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $lineA->id, 'qty_good' => 10, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        // Line B has remaining = 10 pcs (> 0). Now pour 15 pcs (+5 over-plan)
        $result = $this->resultService->recordResult([
            'heat_number' => 'HEAT-OP-B-FINAL',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $lineB->id, 'qty_good' => 15, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $this->assertNotNull($result);
        $this->assertEquals(15, $result->lines->first()->qty_good);
        $this->assertEquals(0, $lineB->fresh()->qty_remaining_to_cast);
        $this->assertEquals('COMPLETED', $order->fresh()->status);

        // Attempting another heat on Line B must be strictly blocked
        $this->expectException(InvalidArgumentException::class);
        $this->resultService->recordResult([
            'heat_number' => 'HEAT-OP-B-EXTRA',
            'cast_date' => '2026-11-10',
        ], [
            ['sand_casting_casting_order_line_id' => $lineB->id, 'qty_good' => 5, 'qty_reject' => 0],
        ], $this->ppicUser->id);
    }

    /**
     * Test 4: PCOR reopening restores line to candidates.
     */
    public function test_4_pcor_reopening_restores_line_to_available_candidates(): void
    {
        [$order, $lines] = $this->createMultiLineOrder([
            ['code' => '2610L001', 'qty' => 20],
            ['code' => '269UN152', 'qty' => 20],
        ]);

        $lineA = $lines['2610L001'];
        $lineB = $lines['269UN152'];

        // Complete both lines
        $resA = $this->resultService->recordResult([
            'heat_number' => 'HEAT-REOPEN-A',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $lineA->id, 'qty_good' => 20, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $resB = $this->resultService->recordResult([
            'heat_number' => 'HEAT-REOPEN-B',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $lineB->id, 'qty_good' => 25, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $this->assertEquals('COMPLETED', $order->fresh()->status);

        // Correct Line A's KTR from 20 to 12 pcs
        $ktrLineA = $resA->lines->first();
        $this->resultService->correctResult(
            $resA,
            [
                'heat_number' => 'HEAT-REOPEN-A',
                'cast_date' => '2026-11-09',
                'reason' => 'Koreksi kuantitas aktual lebih rendah',
            ],
            [
                [
                    'id' => $ktrLineA->id,
                    'sand_casting_casting_order_line_id' => $lineA->id,
                    'qty_good' => 12,
                    'qty_reject' => 0,
                ],
            ],
            $this->ppicUser->id
        );

        $order->refresh();
        $lineA->refresh();
        $lineB->refresh();

        $this->assertEquals('PARTIALLY_COMPLETED', $order->status);
        $this->assertEquals(8, $lineA->qty_remaining_to_cast);
        $this->assertEquals(0, $lineB->qty_remaining_to_cast);

        // Access create page: Line A must be back in availableLines, Line B must remain excluded
        $response = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.create'));
        $response->assertOk();

        $availableLines = $response->viewData('availableLines');
        $this->assertTrue($availableLines->contains('id', $lineA->id), 'Reopened Line A must reappear in availableLines');
        $this->assertFalse($availableLines->contains('id', $lineB->id), 'Satisfied Line B must remain excluded');
    }

    /**
     * Test 5: Zero quota line rejected by service even when parent order is active.
     */
    public function test_5_zero_quota_line_rejected_by_service_even_when_parent_is_active(): void
    {
        [$order, $lines] = $this->createMultiLineOrder([
            ['code' => '2610L001', 'qty' => 10],
            ['code' => '269UN152', 'qty' => 20],
        ]);

        $lineA = $lines['2610L001'];
        $lineB = $lines['269UN152'];

        // Finish Line A
        $this->resultService->recordResult([
            'heat_number' => 'HEAT-SRV-01',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $lineA->id, 'qty_good' => 10, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $this->assertEquals('PARTIALLY_COMPLETED', $order->fresh()->status);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sudah terpenuhi (Sisa kuota: 0 pcs)');

        $this->resultService->recordResult([
            'heat_number' => 'HEAT-SRV-EXTRA',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $lineA->id, 'qty_good' => 5, 'qty_reject' => 0],
        ], $this->ppicUser->id);
    }

    /**
     * Test 6: Preselected order URL enforces remaining quota filtering.
     */
    public function test_6_preselected_order_url_enforces_remaining_quota_filtering(): void
    {
        [$order, $lines] = $this->createMultiLineOrder([
            ['code' => '2610L001', 'qty' => 10],
            ['code' => '269UN152', 'qty' => 20],
        ]);

        $lineA = $lines['2610L001'];
        $lineB = $lines['269UN152'];

        // Complete Line A
        $this->resultService->recordResult([
            'heat_number' => 'HEAT-PRE-01',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $lineA->id, 'qty_good' => 10, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        // Access create page with ?casting_order_id={$order->id}
        $response = $this->actingAs($this->ppicUser)->get(
            route('sand-casting.casting-results.create', ['casting_order_id' => $order->id])
        );

        $response->assertOk();
        $availableLines = $response->viewData('availableLines');

        $this->assertFalse($availableLines->contains('id', $lineA->id), 'Line A with remaining 0 must be filtered out even with preselectedOrderId');
        $this->assertTrue($availableLines->contains('id', $lineB->id), 'Line B with remaining 20 must remain present');
    }

    /**
     * Test 7: Atomic rollback when request includes a zero-quota item.
     */
    public function test_7_atomic_rollback_on_zero_quota_rejection(): void
    {
        [$order, $lines] = $this->createMultiLineOrder([
            ['code' => '2610L001', 'qty' => 10],
            ['code' => '269UN152', 'qty' => 20],
        ]);

        $lineA = $lines['2610L001'];
        $lineB = $lines['269UN152'];

        // Finish Line A
        $this->resultService->recordResult([
            'heat_number' => 'HEAT-ROLL-01',
            'cast_date' => '2026-11-09',
        ], [
            ['sand_casting_casting_order_line_id' => $lineA->id, 'qty_good' => 10, 'qty_reject' => 0],
        ], $this->ppicUser->id);

        $initialResultCount = SandCastingCastingResult::count();
        $initialLineCount = SandCastingCastingResultLine::count();

        // Attempt combined heat with valid Line B (10 pcs) and invalid Line A (5 pcs)
        $response = $this->actingAs($this->ppicUser)->postJson(route('sand-casting.casting-results.store'), [
            'heat_number' => 'HEAT-COMBINED-FAIL',
            'cast_date' => '2026-11-09',
            'items' => [
                ['sand_casting_casting_order_line_id' => $lineB->id, 'qty_good' => 10, 'qty_reject' => 0],
                ['sand_casting_casting_order_line_id' => $lineA->id, 'qty_good' => 5, 'qty_reject' => 0],
            ],
        ]);

        $response->assertStatus(422);

        // Entire transaction must be rolled back: no new heat header or lines
        $this->assertEquals($initialResultCount, SandCastingCastingResult::count());
        $this->assertEquals($initialLineCount, SandCastingCastingResultLine::count());
        $this->assertDatabaseMissing('sand_casting_casting_results', ['heat_number' => 'HEAT-COMBINED-FAIL']);
    }
}
