<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\User;
use App\Services\SandCasting\SandCastingProductionFloorQueryService;
use App\Services\SandCasting\SandCastingStageAuthorizationService;
use App\Services\SandCasting\SandCastingStageExecutionService;
use App\Services\SandCasting\TravelerNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductionFloorKanbanReorderTest extends TestCase
{
    use RefreshDatabase;

    protected User $ppicAuthorizedUser;

    protected User $ppicOtherUser;

    protected User $spvUser;

    protected User $adminUser;

    protected User $qcUser;

    protected SandCastingProductionFloorQueryService $queryService;

    protected SandCastingStageExecutionService $executionService;

    protected function setUp(): void
    {
        parent::setUp();

        $planPerm = Permission::firstOrCreate(['name' => 'access_planning']);
        $ppicRole = Role::firstOrCreate(['name' => 'ppic']);
        $spvRole = Role::firstOrCreate(['name' => 'spv']);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $qcRole = Role::firstOrCreate(['name' => 'qc']);

        $ppicRole->givePermissionTo($planPerm);
        $adminRole->givePermissionTo($planPerm);

        // Authorized PPIC
        $this->ppicAuthorizedUser = User::factory()->create([
            'name' => 'PPIC Flange User',
            'email' => 'ppicflange@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->ppicAuthorizedUser->assignRole('ppic');

        // Other PPIC user
        $this->ppicOtherUser = User::factory()->create([
            'name' => 'Other PPIC User',
            'email' => 'otherppic@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->ppicOtherUser->assignRole('ppic');

        // SPV user
        $this->spvUser = User::factory()->create([
            'name' => 'SPV Netto',
            'email' => 'spv_netto@peroniks.com',
            'assigned_stage' => 'netto',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvUser->assignRole('spv');

        // Admin user
        $this->adminUser = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->adminUser->assignRole('admin');

        // QC user
        $this->qcUser = User::factory()->create([
            'name' => 'QC Inspector',
            'email' => 'qc@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->qcUser->assignRole('qc');

        $this->executionService = new SandCastingStageExecutionService;
        $this->queryService = new SandCastingProductionFloorQueryService($this->executionService);
    }

    protected function createKtrLine(array $attributes = []): SandCastingCastingResultLine
    {
        $plan = ProductionPlan::create([
            'code' => $attributes['production_code'] ?? '268ET'.rand(100, 999),
            'title' => 'Rencana SC '.rand(100, 999),
            'item_code' => '4.'.rand(100, 999),
            'item_name' => $attributes['item_name'] ?? 'SS304 EN1092-1 PN16 DN50',
            'po_number' => 'PO-'.rand(100, 999),
            'line_number' => $attributes['line_number'] ?? 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'customer' => $attributes['customer'] ?? 'PT A06 METAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260920-'.rand(1000, 9999),
            'scheduled_date' => '2026-09-20',
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
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
            'casting_order_id' => $order->id,
            'heat_number' => $attributes['heat_number'] ?? ('HEAT-'.rand(1000, 9999)),
            'cast_date' => $attributes['cast_date'] ?? '2026-09-20',
            'furnace' => 'F1',
            'shift' => 1,
            'operator_name' => 'Operator Cor',
            'recorded_by' => $this->adminUser->id,
        ]);

        $castDateStr = $attributes['cast_date'] ?? '2026-09-20';

        return SandCastingCastingResultLine::create([
            'sand_casting_casting_result_id' => $result->id,
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => TravelerNumberGenerator::generateNext($castDateStr),
            'qty_good' => $attributes['qty_good'] ?? 25,
            'qty_reject' => $attributes['qty_reject'] ?? 0,
            'unit_weight_kg' => 1.50,
            'total_weight_kg' => 37.50,
            'current_stage' => $attributes['current_stage'] ?? 'netto',
            'is_urgent' => $attributes['is_urgent'] ?? false,
            'queue_position' => $attributes['queue_position'] ?? null,
        ]);
    }

    public function test_authorization_service_allows_only_ppicflange(): void
    {
        $authService = new SandCastingStageAuthorizationService;

        $this->assertTrue($authService->canReorderQueue($this->ppicAuthorizedUser));
        $this->assertFalse($authService->canReorderQueue($this->ppicOtherUser));
        $this->assertFalse($authService->canReorderQueue($this->spvUser));
        $this->assertFalse($authService->canReorderQueue($this->adminUser));
        $this->assertFalse($authService->canReorderQueue($this->qcUser));
        $this->assertFalse($authService->canReorderQueue(null));
    }

    public function test_authorized_ppic_can_reorder_and_spv_gets_403_on_http_post(): void
    {
        $line1 = $this->createKtrLine(['line_number' => 1, 'item_name' => 'Item A', 'cast_date' => '2026-09-18']);
        $line2 = $this->createKtrLine(['line_number' => 1, 'item_name' => 'Item B', 'cast_date' => '2026-09-19']);
        $line3 = $this->createKtrLine(['line_number' => 1, 'item_name' => 'Item C', 'cast_date' => '2026-09-20']);

        // 1. SPV forbidden
        $responseSpv = $this->actingAs($this->spvUser)->postJson(route('sand-casting.kanban.reorder', 'netto'), [
            'line_number' => 1,
            'from_position' => 3,
            'to_position' => 1,
        ]);
        $responseSpv->assertStatus(403);

        // 2. Other PPIC forbidden
        $responseOther = $this->actingAs($this->ppicOtherUser)->postJson(route('sand-casting.kanban.reorder', 'netto'), [
            'line_number' => 1,
            'from_position' => 3,
            'to_position' => 1,
        ]);
        $responseOther->assertStatus(403);

        // 3. Authorized PPIC allowed
        $responseAuth = $this->actingAs($this->ppicAuthorizedUser)->postJson(route('sand-casting.kanban.reorder', 'netto'), [
            'line_number' => 1,
            'from_position' => 3,
            'to_position' => 1,
        ]);
        $responseAuth->assertOk();
        $responseAuth->assertJsonPath('success', true);
        $responseAuth->assertJsonPath('data.total_reordered', 3);

        // Verify line3 is now at position 1, line1 at 2, line2 at 3
        $line3->refresh();
        $line1->refresh();
        $line2->refresh();

        $this->assertSame(1, $line3->queue_position);
        $this->assertSame(2, $line1->queue_position);
        $this->assertSame(3, $line2->queue_position);
    }

    public function test_reorder_from_end_to_middle_and_sequential_assignment(): void
    {
        $items = [];
        for ($i = 1; $i <= 10; $i++) {
            $items[$i] = $this->createKtrLine([
                'line_number' => 2,
                'item_name' => "Item #{$i}",
                'cast_date' => '2026-09-'.sprintf('%02d', 10 + $i),
                'line_idx' => $i,
            ]);
        }

        // Move Item #10 (from pos 10) to position 3
        $result = $this->queryService->reorderStageLineQueue('netto', 2, 10, 3);

        $this->assertSame(10, $result['total_reordered']);
        $this->assertSame(10, $result['from_pos']);
        $this->assertSame(3, $result['to_pos']);

        // Item 10 should have position 3
        $items[10]->refresh();
        $this->assertSame(3, $items[10]->queue_position);

        // Item 1, 2 should be at 1, 2
        $items[1]->refresh();
        $items[2]->refresh();
        $this->assertSame(1, $items[1]->queue_position);
        $this->assertSame(2, $items[2]->queue_position);

        // Item 3 should now be pushed to 4
        $items[3]->refresh();
        $this->assertSame(4, $items[3]->queue_position);

        // Check full sequential positions 1..10 without duplicates or gaps
        $allPositions = SandCastingCastingResultLine::whereIn('id', array_map(fn ($it) => $it->id, $items))
            ->pluck('queue_position')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(range(1, 10), $allPositions);
    }

    public function test_reorder_from_top_to_bottom_forward(): void
    {
        $items = [];
        for ($i = 1; $i <= 5; $i++) {
            $items[$i] = $this->createKtrLine([
                'line_number' => 1,
                'item_name' => "Item {$i}",
                'cast_date' => '2026-09-'.sprintf('%02d', 10 + $i),
                'line_idx' => $i,
            ]);
        }

        // Move item 1 from pos 1 to pos 4
        $this->queryService->reorderStageLineQueue('netto', 1, 1, 4);

        $items[1]->refresh();
        $items[2]->refresh();
        $items[3]->refresh();
        $items[4]->refresh();
        $items[5]->refresh();

        $this->assertSame(1, $items[2]->queue_position);
        $this->assertSame(2, $items[3]->queue_position);
        $this->assertSame(3, $items[4]->queue_position);
        $this->assertSame(4, $items[1]->queue_position);
        $this->assertSame(5, $items[5]->queue_position);
    }

    public function test_reorder_same_to_same_preserves_order(): void
    {
        $items = [];
        for ($i = 1; $i <= 3; $i++) {
            $items[$i] = $this->createKtrLine([
                'line_number' => 3,
                'item_name' => "Item {$i}",
                'cast_date' => '2026-09-'.sprintf('%02d', 10 + $i),
                'line_idx' => $i,
            ]);
        }

        $this->queryService->reorderStageLineQueue('netto', 3, 2, 2);

        $items[1]->refresh();
        $items[2]->refresh();
        $items[3]->refresh();

        $this->assertSame(1, $items[1]->queue_position);
        $this->assertSame(2, $items[2]->queue_position);
        $this->assertSame(3, $items[3]->queue_position);
    }

    public function test_invalid_positions_throw_exception(): void
    {
        $this->createKtrLine(['line_number' => 1]);
        $this->createKtrLine(['line_number' => 1]);

        $this->expectException(\InvalidArgumentException::class);
        $this->queryService->reorderStageLineQueue('netto', 1, 0, 1);
    }

    public function test_out_of_bounds_position_throws_exception(): void
    {
        $this->createKtrLine(['line_number' => 1]);
        $this->createKtrLine(['line_number' => 1]);

        $this->expectException(\InvalidArgumentException::class);
        $this->queryService->reorderStageLineQueue('netto', 1, 1, 99);
    }

    public function test_different_stage_and_line_isolation(): void
    {
        $nettoL1 = $this->createKtrLine(['current_stage' => 'netto', 'line_number' => 1]);
        $nettoL2 = $this->createKtrLine(['current_stage' => 'netto', 'line_number' => 2]);
        $bubutL1 = $this->createKtrLine(['current_stage' => 'bubut_od', 'line_number' => 1]);

        $this->queryService->reorderStageLineQueue('netto', 1, 1, 1);

        $nettoL1->refresh();
        $nettoL2->refresh();
        $bubutL1->refresh();

        $this->assertSame(1, $nettoL1->queue_position);
        $this->assertNull($nettoL2->queue_position);
        $this->assertNull($bubutL1->queue_position);
    }

    public function test_new_item_entering_queue_with_null_position_follows_manual_queue_in_fifo(): void
    {
        $lineA = $this->createKtrLine(['line_number' => 1, 'cast_date' => '2026-09-10', 'line_idx' => 1]);
        $lineB = $this->createKtrLine(['line_number' => 1, 'cast_date' => '2026-09-11', 'line_idx' => 2]);

        // Reorder: lineB becomes #1, lineA becomes #2
        $this->queryService->reorderStageLineQueue('netto', 1, 2, 1);

        // Later, new KTR lineC is created with queue_position = NULL and older cast_date
        $lineC = $this->createKtrLine(['line_number' => 1, 'cast_date' => '2026-09-01', 'line_idx' => 3]);
        $this->assertNull($lineC->queue_position);

        $kanbanData = $this->queryService->getStageKanbanData('netto', ['line_number' => 1]);

        $readyCards = $kanbanData['ready'];
        $this->assertCount(3, $readyCards);

        // #1 is lineB (explicit pos 1), #2 is lineA (explicit pos 2), #3 is lineC (null pos, appended)
        $this->assertSame($lineB->id, $readyCards[0]['id']);
        $this->assertSame('#01', $readyCards[0]['queue_number']);

        $this->assertSame($lineA->id, $readyCards[1]['id']);
        $this->assertSame('#02', $readyCards[1]['queue_number']);

        $this->assertSame($lineC->id, $readyCards[2]['id']);
        $this->assertSame('#03', $readyCards[2]['queue_number']);
    }

    public function test_queue_position_resets_to_null_when_stage_advances(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto', 'queue_position' => 5]);
        $this->assertSame(5, $line->queue_position);

        // SPV records physical done
        $exec = $this->executionService->markPhysicalDone($line->traveler_number, 'netto', $this->spvUser->id);

        // Admin records defect
        $this->executionService->recordDefectQty($exec->id, 0, $this->adminUser->id);

        // QC confirms breakdown -> moves to bubut_od
        $this->executionService->verifyQcBreakdown($exec->id, [], $this->qcUser->id);

        $line->refresh();
        $this->assertSame('bubut_od', $line->current_stage);
        $this->assertNull($line->queue_position);
    }

    public function test_reorder_does_not_mutate_identities_aging_quantities_or_create_stage_executions(): void
    {
        $line1 = $this->createKtrLine(['line_number' => 1, 'qty_good' => 38, 'heat_number' => 'HEAT-A01', 'cast_date' => '2026-09-15']);
        $line2 = $this->createKtrLine(['line_number' => 1, 'qty_good' => 45, 'heat_number' => 'HEAT-B02', 'cast_date' => '2026-09-16']);

        $executionsCountBefore = \App\Models\SandCastingStageExecution::count();

        $this->queryService->reorderStageLineQueue('netto', 1, 2, 1);

        $executionsCountAfter = \App\Models\SandCastingStageExecution::count();
        $this->assertSame($executionsCountBefore, $executionsCountAfter);

        $line1->refresh();
        $line2->refresh();

        $this->assertSame(38, (int) $line1->qty_good);
        $this->assertSame(45, (int) $line2->qty_good);
        $this->assertSame('HEAT-A01', $line1->castingResult->heat_number);
        $this->assertSame('HEAT-B02', $line2->castingResult->heat_number);
        $this->assertSame('netto', $line1->current_stage);
        $this->assertSame('netto', $line2->current_stage);
    }

    public function test_ui_renders_edit_antrian_button_only_for_authorized_ppic(): void
    {
        $this->createKtrLine(['line_number' => 1, 'customer' => 'PT A06 METAL']);

        // PPIC authorized sees Edit Antrian button
        $resAuth = $this->actingAs($this->ppicAuthorizedUser)->get(route('sand-casting.kanban.stage', 'netto'));
        $resAuth->assertOk();
        $resAuth->assertSee('EDIT ANTRIAN');
        $resAuth->assertSee('#01');
        $resAuth->assertSee('[A06 METAL]');

        // SPV does NOT see Edit Antrian button
        $resSpv = $this->actingAs($this->spvUser)->get(route('sand-casting.kanban.stage', 'netto'));
        $resSpv->assertOk();
        $resSpv->assertDontSee('EDIT ANTRIAN');
        $resSpv->assertSee('#01');
        $resSpv->assertSee('[A06 METAL]');
    }

    public function test_customer_badge_deterministic_palette(): void
    {
        $badge1 = SandCastingProductionFloorQueryService::resolveCustomerBadge('PT A06 METAL');
        $badge2 = SandCastingProductionFloorQueryService::resolveCustomerBadge('PT A06 METAL');
        $badge3 = SandCastingProductionFloorQueryService::resolveCustomerBadge('E02');
        $badgeEmpty = SandCastingProductionFloorQueryService::resolveCustomerBadge(null);

        $this->assertSame($badge1, $badge2);
        $this->assertSame('A06 METAL', $badge1['code']);
        $this->assertSame('E02', $badge3['code']);
        $this->assertSame('STD', $badgeEmpty['code']);
        $this->assertNotEmpty($badge1['bg']);
        $this->assertNotEmpty($badge1['text']);
    }
}
