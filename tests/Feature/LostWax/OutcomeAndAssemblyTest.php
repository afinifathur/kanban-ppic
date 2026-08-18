<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxTree;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutcomeAndAssemblyTest extends TestCase
{
    use RefreshDatabase;

    protected function createProductionPlan($attributes = [])
    {
        return \App\Models\ProductionPlan::create(array_merge([
            'code' => 'AB61',
            'customer' => 'LOKAL',
            'item_code' => '4.101105K.A0020',
            'item_name' => 'SS316 BLIND 2"',
            'aisi' => '316',
            'size' => '2"',
            'weight' => 0.75,
            'po_number' => 'PO-AB-01',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'status' => 'planning',
        ], $attributes));
    }

    public function test_input_actual_printed_outcomes_saves_successfully(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260818-0001',
            'scheduled_date' => '2026-08-18',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 120,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        $response = $this->actingAs($user)
            ->put(route('lost-wax.outcomes.update', $order), [
                'items' => [
                    [
                        'id' => $line->id,
                        'qty_actual_good' => 118,
                        'qty_actual_defect' => 0,
                        'standard_tree_capacity' => 20,
                    ],
                ],
            ]);

        $response->assertRedirect(route('lost-wax.outcomes.index'));
        $response->assertSessionHas('success');

        $line->refresh();
        $this->assertEquals(118, $line->qty_actual_good);
        $this->assertEquals(0, $line->qty_actual_defect);
        $this->assertEquals(20, $line->standard_tree_capacity);
    }

    public function test_outcome_validation_invariant_not_exceed_ordered(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260818-0001',
            'scheduled_date' => '2026-08-18',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        // Attempt total 120 (115 good + 5 defect) on qty_ordered 100 (invalid)
        $response = $this->actingAs($user)
            ->put(route('lost-wax.outcomes.update', $order), [
                'items' => [
                    [
                        'id' => $line->id,
                        'qty_actual_good' => 115,
                        'qty_actual_defect' => 5,
                        'standard_tree_capacity' => 20,
                    ],
                ],
            ]);

        $response->assertSessionHas('error');
        $line->refresh();
        $this->assertNull($line->qty_actual_good);
    }

    public function test_cannot_cancel_print_order_if_outcomes_are_recorded(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260818-0001',
            'scheduled_date' => '2026-08-18',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 120,
            'qty_actual_good' => 118,
            'qty_actual_defect' => 0,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        $response = $this->actingAs($user)
            ->post(route('lost-wax.print-orders.update-status', $order), [
                'status' => 'CANCELLED',
            ]);

        $response->assertSessionHas('error');
        $order->refresh();
        $this->assertEquals('ISSUED', $order->status);
    }

    public function test_assembly_mathematical_distribution(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260818-0001',
            'scheduled_date' => '2026-08-18',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 150,
            'qty_actual_good' => 118,
            'qty_actual_defect' => 0,
            'standard_tree_capacity' => 20,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        // Case 1: 118 with capacity 20 => 20, 20, 20, 20, 20, 18
        $response = $this->actingAs($user)
            ->get(route('lost-wax.assemblies.create', ['line' => $line->id, 'standard_tree_capacity' => 20]));
        $response->assertOk();
        $response->assertSee('Tree #006');

        // Case 2: 43 with capacity 20 => 20, 20, 3
        $line->update(['qty_actual_good' => 43]);
        $response = $this->actingAs($user)
            ->get(route('lost-wax.assemblies.create', ['line' => $line->id, 'standard_tree_capacity' => 20]));
        $response->assertOk();
        $response->assertSee('Tree #003');

        // Case 3: 7 with capacity 20 => 7
        $line->update(['qty_actual_good' => 7]);
        $response = $this->actingAs($user)
            ->get(route('lost-wax.assemblies.create', ['line' => $line->id, 'standard_tree_capacity' => 20]));
        $response->assertOk();
        $response->assertSee('Tree #001');
    }

    public function test_cannot_exceed_available_good_quantity(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260818-0001',
            'scheduled_date' => '2026-08-18',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 150,
            'qty_actual_good' => 115,
            'qty_actual_defect' => 5, // Rusak 5, Good 115
            'standard_tree_capacity' => 20,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        // Attempting to generate 118 pcs when available is only 115 (Rusak strictly excluded)
        $response = $this->actingAs($user)
            ->post(route('lost-wax.assemblies.store', $line), [
                'quantities' => [20, 20, 20, 20, 20, 18],
                'family_code' => '1',
            ]);

        $response->assertSessionHas('error');
        $this->assertEquals(0, LostWaxTree::count());
    }

    public function test_concurrency_lock_and_successful_generation(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260818-0001',
            'scheduled_date' => '2026-08-18',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 150,
            'qty_actual_good' => 118,
            'qty_actual_defect' => 0,
            'standard_tree_capacity' => 20,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        $response = $this->actingAs($user)
            ->post(route('lost-wax.assemblies.store', $line), [
                'quantities' => [20, 20, 20, 20, 20, 18],
                'family_code' => '1',
            ]);

        $response->assertRedirect(route('lost-wax.trees.index'));
        $response->assertSessionHas('success');

        $this->assertEquals(6, LostWaxTree::count());
        $tree = LostWaxTree::orderBy('id', 'desc')->first();
        $this->assertEquals(18, $tree->quantity);
        $this->assertEquals('generated', $tree->status);
        $this->assertFalse($tree->is_correctable); // Commited trees are immutable
    }

    public function test_traceability_and_scanning_compatibility(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260818-0001',
            'scheduled_date' => '2026-08-18',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 150,
            'qty_actual_good' => 118,
            'qty_actual_defect' => 0,
            'standard_tree_capacity' => 20,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        $this->actingAs($user)
            ->post(route('lost-wax.assemblies.store', $line), [
                'quantities' => [20],
                'family_code' => '1',
            ]);

        $tree = LostWaxTree::first();

        // 1. Traceability
        $this->assertEquals('AB61', $tree->getSourceCode());
        $this->assertEquals('PC-20260818-0001', $tree->getSourcePrintOrderNumber());
        $this->assertEquals('LOKAL', $tree->getSourceCustomer());
        $this->assertEquals('SS316 BLIND 2"', $tree->getSourceProduct());
        $this->assertEquals('316', $tree->getSourceAisi());
        $this->assertEquals('2"', $tree->getSourceSize());

        // 2. Scan Engine Compatibility (loads correct attributes)
        $response = $this->actingAs($user)
            ->post(route('lost-wax.scan.process'), [
                'barcode' => $tree->barcode,
                'expected_stage' => 'layer_1',
            ]);

        $response->assertJson([
            'success' => true,
        ]);

        $tree->refresh();
        $this->assertEquals('layer_1', $tree->current_stage);
    }
}
