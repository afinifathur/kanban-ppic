<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\User;
use App\Services\SandCasting\TravelerNumberGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CastingResultModelAndDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::firstOrCreate(['name' => 'access_planning']);
        $role = Role::firstOrCreate(['name' => 'ppic']);
        $role->givePermissionTo($permission);

        $this->user = User::factory()->create([
            'email' => 'ppic_sc@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->user->assignRole('ppic');
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'code' => 'LH083',
            'title' => 'Rencana SC LH083',
            'item_code' => '4.101',
            'item_name' => 'SS304 JIS 10K 2"',
            'po_number' => 'PO-001',
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ], $attributes));
    }

    public function test_casting_result_has_no_direct_casting_order_foreign_key_and_is_standalone(): void
    {
        $plan = $this->createPlan();

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0001',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        $travelerNumber = TravelerNumberGenerator::generateNext('2026-09-14');

        // Standalone CastingResult (Heat)
        $result = SandCastingCastingResult::create([
            'heat_number' => 'A213092601',
            'cast_date' => '2026-09-14',
            'furnace' => 'FURNACE-1',
            'shift' => 'Shift 1',
            'operator_name' => 'Budi',
            'recorded_by' => $this->user->id,
        ]);

        $resultLine = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $travelerNumber,
            'qty_good' => 90,
            'qty_reject' => 5,
            'unit_weight_kg' => 1.5,
            'total_weight_kg' => 135.0,
        ]);

        $this->assertDatabaseHas('sand_casting_casting_results', [
            'id' => $result->id,
            'heat_number' => 'A213092601',
        ]);

        $this->assertDatabaseHas('sand_casting_casting_result_lines', [
            'id' => $resultLine->id,
            'traveler_number' => $travelerNumber,
            'qty_good' => 90,
            'qty_reject' => 5,
        ]);

        // CastingResult does not have castingOrder relation, but has orderLines hasManyThrough
        $this->assertFalse(method_exists($result, 'castingOrder'));
        $this->assertTrue($result->orderLines->contains('id', $orderLine->id));

        // CastingOrder discovers results through result lines
        $this->assertTrue($order->results()->get()->contains('id', $result->id));
        $this->assertTrue($order->hasRecordedOutcomes());

        $this->assertEquals(90, $orderLine->qty_cast_good);
        $this->assertEquals(5, $orderLine->qty_cast_reject);
        $this->assertEquals(95, $orderLine->qty_cast_total);
        $this->assertEquals(10, $orderLine->qty_remaining_to_cast);
    }

    public function test_single_heat_can_contain_multiple_pcor_lines_and_orders(): void
    {
        $plan1 = $this->createPlan(['code' => '268ET001', 'item_code' => '4.101', 'item_name' => 'SS304 JIS 10K 2"', 'qty_planned' => 100, 'qty_remaining' => 100]);
        $plan2 = $this->createPlan(['code' => '268AB002', 'item_code' => '4.102', 'item_name' => 'SS304 SORF 4"', 'qty_planned' => 5, 'qty_remaining' => 5]);
        $plan3 = $this->createPlan(['code' => '268L001', 'item_code' => '4.103', 'item_name' => 'SS304 DIN PN16 5"', 'qty_planned' => 55, 'qty_remaining' => 55]);

        // 3 Separate PCOR Documents
        $order1 = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0001',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);
        $line1 = $order1->lines()->create([
            'production_plan_id' => $plan1->id,
            'qty_ordered' => 100,
            'code' => '268ET001',
            'item_name' => 'SS304 JIS 10K 2"',
        ]);

        $order2 = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260915-0002',
            'scheduled_date' => '2026-09-15',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);
        $line2 = $order2->lines()->create([
            'production_plan_id' => $plan2->id,
            'qty_ordered' => 5,
            'code' => '268AB002',
            'item_name' => 'SS304 SORF 4"',
        ]);

        $order3 = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260916-0004',
            'scheduled_date' => '2026-09-16',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);
        $line3 = $order3->lines()->create([
            'production_plan_id' => $plan3->id,
            'qty_ordered' => 55,
            'code' => '268L001',
            'item_name' => 'SS304 DIN PN16 5"',
        ]);

        // 1 ACTUAL POURING EVENT (HEAT A214092601) COMBINING 3 PCORS
        $result = SandCastingCastingResult::create([
            'heat_number' => 'A214092601',
            'cast_date' => '2026-09-14',
            'recorded_by' => $this->user->id,
        ]);

        $result->lines()->create([
            'sand_casting_casting_order_line_id' => $line1->id,
            'production_plan_id' => $plan1->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-14'),
            'qty_good' => 100,
            'qty_reject' => 0,
            'unit_weight_kg' => 2.0,
            'total_weight_kg' => 200.0,
        ]);

        $result->lines()->create([
            'sand_casting_casting_order_line_id' => $line2->id,
            'production_plan_id' => $plan2->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-14'),
            'qty_good' => 5,
            'qty_reject' => 0,
            'unit_weight_kg' => 4.0,
            'total_weight_kg' => 20.0,
        ]);

        $result->lines()->create([
            'sand_casting_casting_order_line_id' => $line3->id,
            'production_plan_id' => $plan3->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-14'),
            'qty_good' => 55,
            'qty_reject' => 0,
            'unit_weight_kg' => 3.0,
            'total_weight_kg' => 165.0,
        ]);

        // 1 Heat has 3 lines across 3 distinct PCORs and production codes
        $this->assertEquals(3, $result->lines()->count());
        $this->assertEquals(160, $result->total_qty_good);
        $this->assertEquals(385.0, $result->total_weight_kg);

        // All 3 separate PCORs discover this Heat
        $this->assertTrue($order1->results()->get()->contains('id', $result->id));
        $this->assertTrue($order2->results()->get()->contains('id', $result->id));
        $this->assertTrue($order3->results()->get()->contains('id', $result->id));

        $this->assertTrue($order1->hasRecordedOutcomes());
        $this->assertTrue($order2->hasRecordedOutcomes());
        $this->assertTrue($order3->hasRecordedOutcomes());
    }

    public function test_single_casting_order_line_can_have_multiple_heats(): void
    {
        $plan = $this->createPlan(['qty_planned' => 550, 'qty_remaining' => 550]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0003',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 550,
            'code' => 'LH083',
            'item_name' => 'SS304 JIS 10K 2"',
        ]);

        // Heat 1: 300 pcs
        $result1 = SandCastingCastingResult::create([
            'heat_number' => 'A213092601',
            'cast_date' => '2026-09-14',
            'recorded_by' => $this->user->id,
        ]);
        $result1->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-14'),
            'qty_good' => 300,
            'qty_reject' => 0,
            'unit_weight_kg' => 1.0,
            'total_weight_kg' => 300.0,
        ]);

        // Heat 2: 250 pcs
        $result2 = SandCastingCastingResult::create([
            'heat_number' => 'A213092602',
            'cast_date' => '2026-09-14',
            'recorded_by' => $this->user->id,
        ]);
        $result2->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-14'),
            'qty_good' => 250,
            'qty_reject' => 0,
            'unit_weight_kg' => 1.0,
            'total_weight_kg' => 250.0,
        ]);

        $this->assertEquals(2, $orderLine->resultLines()->count());
        $this->assertEquals(550, $orderLine->qty_cast_good);
        $this->assertEquals(0, $orderLine->qty_remaining_to_cast);

        $heats = $orderLine->resultLines->map(fn ($rl) => $rl->castingResult->heat_number)->values()->all();
        $this->assertEquals(['A213092601', 'A213092602'], $heats);

        // Order results query returns both heats
        $orderHeats = $order->results()->pluck('heat_number')->all();
        $this->assertContains('A213092601', $orderHeats);
        $this->assertContains('A213092602', $orderHeats);
    }

    public function test_traveler_number_is_unique_and_different_from_heat_and_code(): void
    {
        $plan = $this->createPlan();

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0004',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => 'LH083',
            'item_name' => 'SS304 JIS 10K 2"',
        ]);

        $t1 = TravelerNumberGenerator::generateNext('2026-09-14');
        $this->assertEquals('KTR-20260914-0001', $t1);
        $this->assertNotEquals($t1, 'A213092601');
        $this->assertNotEquals($t1, 'LH083');
        $this->assertNotEquals($t1, 'PCOR-20260914-0004');

        $result = SandCastingCastingResult::create([
            'heat_number' => 'A213092601',
            'cast_date' => '2026-09-14',
            'recorded_by' => $this->user->id,
        ]);

        $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $t1,
            'qty_good' => 50,
        ]);

        $t2 = TravelerNumberGenerator::generateNext('2026-09-14');
        $this->assertEquals('KTR-20260914-0002', $t2);

        // Attempt to insert duplicate traveler_number must trigger unique constraint violation
        $this->expectException(QueryException::class);
        $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $t1,
            'qty_good' => 50,
        ]);
    }

    public function test_deleting_casting_order_line_with_results_is_restricted(): void
    {
        $plan = $this->createPlan();

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0005',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => 'LH083',
            'item_name' => 'SS304 JIS 10K 2"',
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'A213092601',
            'cast_date' => '2026-09-14',
            'recorded_by' => $this->user->id,
        ]);

        $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-14'),
            'qty_good' => 100,
        ]);

        $this->expectException(QueryException::class);
        $orderLine->delete();
    }

    public function test_production_plan_sand_casting_result_lines_relationship(): void
    {
        $scPlan = $this->createPlan();

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0006',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $scPlan->id,
            'qty_ordered' => 100,
            'code' => 'LH083',
            'item_name' => 'SS304 JIS 10K 2"',
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'A213092601',
            'cast_date' => '2026-09-14',
            'recorded_by' => $this->user->id,
        ]);

        $resLine = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $scPlan->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-14'),
            'qty_good' => 80,
            'qty_reject' => 5,
        ]);

        $this->assertTrue($scPlan->sandCastingResultLines->contains('id', $resLine->id));
        $this->assertEquals(80, $scPlan->sandCastingResultLines->sum('qty_good'));
    }

    public function test_order_with_outcomes_cannot_be_cancelled_via_status_transition(): void
    {
        $scPlan = $this->createPlan();

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0007',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $scPlan->id,
            'qty_ordered' => 100,
            'code' => 'LH083',
            'item_name' => 'SS304 JIS 10K 2"',
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'A213092601',
            'cast_date' => '2026-09-14',
            'recorded_by' => $this->user->id,
        ]);

        $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $scPlan->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-14'),
            'qty_good' => 50,
            'qty_reject' => 0,
        ]);

        $this->assertTrue($order->fresh()->hasRecordedOutcomes());

        // Attempting to cancel via CastingOrderController@updateStatus must fail with error
        $response = $this->actingAs($this->user)
            ->post(route('sand-casting.casting-orders.update-status', $order), [
                'status' => 'CANCELLED',
            ]);

        $response->assertSessionHas('error', 'Dokumen tidak dapat dibatalkan karena hasil cor sudah dicatat.');
        $this->assertEquals('ISSUED', $order->fresh()->status);
    }

    public function test_traveler_generator_handles_concurrency_collision_retry(): void
    {
        $plan = $this->createPlan();
        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0008',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);
        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => 'LH083',
            'item_name' => 'SS304 JIS 10K 2"',
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'A213092601',
            'cast_date' => '2026-09-14',
            'recorded_by' => $this->user->id,
        ]);

        // Manually create a line with KTR-20260914-0001
        $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-20260914-0001',
            'qty_good' => 10,
        ]);

        // Also manually simulate a collision by pre-inserting KTR-20260914-0002
        $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-20260914-0002',
            'qty_good' => 10,
        ]);

        // Next generation must detect the collision and jump to KTR-20260914-0003
        $nextTraveler = TravelerNumberGenerator::generateNext('2026-09-14');
        $this->assertEquals('KTR-20260914-0003', $nextTraveler);
    }
}
