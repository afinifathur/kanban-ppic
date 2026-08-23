<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxRangkaiWorkOrder;
use App\Models\LostWaxTree;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssemblySimplifiedWOTest extends TestCase
{
    use RefreshDatabase;

    protected function createProductionPlan($attributes = [])
    {
        return \App\Models\ProductionPlan::create(array_merge([
            'code' => 'TEST_SIMP',
            'customer' => 'TEST CUSTOMER',
            'item_code' => '4.101105K.A0020',
            'item_name' => 'SS316 BLIND 2"',
            'aisi' => '316',
            'size' => '2"',
            'weight' => 0.75,
            'po_number' => 'PO-TEST-SIMP',
            'qty_planned' => 200,
            'qty_remaining' => 200,
            'line_number' => 1,
            'status' => 'planning',
        ], $attributes));
    }

    protected function setUpPrintExecution($plan, $user, $good = 200, $defect = 0)
    {
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260823-0001',
            'scheduled_date' => '2026-08-23',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 200,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        $printService = app(\App\Services\PrintExecutionService::class);
        $printService->record($line, [
            'qty_good' => $good,
            'qty_defect' => $defect,
            'execution_date' => '2026-08-23',
            'status' => 'FINALIZED',
            'recorded_by' => $user->id,
        ]);

        return $line;
    }

    /**
     * A. New WO stores standard_capacity_guide.
     */
    public function test_new_wo_stores_standard_capacity_guide(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();
        $line = $this->setUpPrintExecution($plan, $user, 120, 0);

        $response = $this->actingAs($user)->post(route('lost-wax.assemblies.work-orders.store', $line), [
            'qty_ordered' => 120,
            'standard_capacity_guide' => 20,
            'notes' => 'Some simplified note',
        ]);

        $response->assertRedirect();

        $wo = LostWaxRangkaiWorkOrder::first();
        $this->assertNotNull($wo);
        $this->assertEquals(120, $wo->qty_trees_planned); // Repurposed for qty ordered
        $this->assertEquals(1, $wo->tree_capacity); // Marker
        $this->assertEquals(20, $wo->standard_capacity_guide);
        $this->assertEquals(120, $wo->qty_planned_pcs);
        $this->assertEquals(120, $wo->qty_outstanding);
    }

    /**
     * B. Two WOs dari PrintOrderLine yang sama dapat memiliki guide berbeda dan menyimpan nilai historisnya.
     */
    public function test_two_wos_from_same_print_order_line_can_have_different_guides(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();
        $line = $this->setUpPrintExecution($plan, $user, 200, 0);

        // Create WO #1 (ordered: 80, guide: 20)
        $this->actingAs($user)->post(route('lost-wax.assemblies.work-orders.store', $line), [
            'qty_ordered' => 80,
            'standard_capacity_guide' => 20,
        ]);

        // Create WO #2 (ordered: 100, guide: 18)
        $this->actingAs($user)->post(route('lost-wax.assemblies.work-orders.store', $line), [
            'qty_ordered' => 100,
            'standard_capacity_guide' => 18,
        ]);

        $wos = LostWaxRangkaiWorkOrder::orderBy('id', 'asc')->get();
        $this->assertCount(2, $wos);

        $this->assertEquals(80, $wos[0]->qty_planned_pcs);
        $this->assertEquals(20, $wos[0]->standard_capacity_guide);

        $this->assertEquals(100, $wos[1]->qty_planned_pcs);
        $this->assertEquals(18, $wos[1]->standard_capacity_guide);
    }

    /**
     * C. Creating WO does not create LostWaxTree.
     */
    public function test_creating_wo_does_not_create_lost_wax_tree(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();
        $line = $this->setUpPrintExecution($plan, $user, 120, 0);

        $this->assertEquals(0, LostWaxTree::count());

        $this->actingAs($user)->post(route('lost-wax.assemblies.work-orders.store', $line), [
            'qty_ordered' => 120,
            'standard_capacity_guide' => 20,
        ]);

        // Verify Work Order was created, but no trees
        $this->assertEquals(1, LostWaxRangkaiWorkOrder::count());
        $this->assertEquals(0, LostWaxTree::count());
    }

    /**
     * D. Execution tetap dapat membuat tree dengan quantity aktual.
     */
    public function test_execution_can_still_create_tree_with_actual_quantity(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();
        $line = $this->setUpPrintExecution($plan, $user, 120, 0);

        // Create Work Order
        $this->actingAs($user)->post(route('lost-wax.assemblies.work-orders.store', $line), [
            'qty_ordered' => 120,
            'standard_capacity_guide' => 20,
        ]);
        $wo = LostWaxRangkaiWorkOrder::first();

        // Record Execution: taken 100 pcs, distributed across 5 trees
        $response = $this->actingAs($user)->post(route('lost-wax.assemblies.work-orders.execution.store', $wo), [
            'execution_date' => '2026-08-23',
            'trees_created' => 5,
            'family_code' => 'A',
            'quantities' => [20, 20, 20, 20, 18], // 98 pcs total
        ]);

        $response->assertRedirect();

        // Verify physical trees created: 5 trees, total quantity = 98 pcs
        $this->assertEquals(5, LostWaxTree::count());
        $this->assertEquals(98, LostWaxTree::sum('quantity'));

        $wo->refresh();
        $this->assertEquals(98, $wo->qty_executed_pcs);
        $this->assertEquals(22, $wo->qty_outstanding); // 120 - 98 = 22 outstanding
        $this->assertEquals('IN_PROGRESS', $wo->status);
    }

    /**
     * E. Legacy WO tetap menghitung qty_planned_pcs dengan benar.
     */
    public function test_legacy_wo_still_calculates_qty_planned_pcs_correctly(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();
        $line = $this->setUpPrintExecution($plan, $user, 120, 0);

        // Manually instantiate a legacy WO
        $wo = LostWaxRangkaiWorkOrder::create([
            'rangkai_order_number' => 'RWO-LEGACY-001',
            'lost_wax_print_order_line_id' => $line->id,
            'qty_trees_planned' => 5,
            'tree_capacity' => 20, // tree_capacity > 1
            'require_layer_7' => true,
            'status' => 'OPEN',
            'created_by' => $user->id,
        ]);

        $this->assertEquals(100, $wo->qty_planned_pcs); // 5 * 20
        $this->assertEquals(100, $wo->qty_outstanding);
    }

    /**
     * F. Guide bukan hard limit terhadap quantity actual tree.
     */
    public function test_guide_is_not_hard_limit_on_actual_tree_quantity(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();
        $line = $this->setUpPrintExecution($plan, $user, 120, 0);

        // Create WO with standard capacity guide = 20
        $this->actingAs($user)->post(route('lost-wax.assemblies.work-orders.store', $line), [
            'qty_ordered' => 120,
            'standard_capacity_guide' => 20,
        ]);
        $wo = LostWaxRangkaiWorkOrder::first();

        // Record execution where a single tree exceeds the standard capacity guide (e.g. 25 pcs)
        // System must accept it because it is only a guide, not a hard validator.
        $response = $this->actingAs($user)->post(route('lost-wax.assemblies.work-orders.execution.store', $wo), [
            'execution_date' => '2026-08-23',
            'trees_created' => 1,
            'family_code' => 'A',
            'quantities' => [25], // 25 > 20
        ]);

        $response->assertRedirect();

        $this->assertEquals(1, LostWaxTree::count());
        $this->assertEquals(25, LostWaxTree::sum('quantity'));

        $wo->refresh();
        $this->assertEquals(25, $wo->qty_executed_pcs);
        $this->assertEquals(95, $wo->qty_outstanding);
    }

    /**
     * Scenario A: WO 120, execution 100, capacity 20 -> 5 tree
     */
    public function test_scenario_a_wo_120_execution_100_capacity_20(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();
        $line = $this->setUpPrintExecution($plan, $user, 120, 0);

        $this->actingAs($user)->post(route('lost-wax.assemblies.work-orders.store', $line), [
            'qty_ordered' => 120,
            'standard_capacity_guide' => 20,
        ]);
        $wo = LostWaxRangkaiWorkOrder::first();

        $response = $this->actingAs($user)->post(route('lost-wax.assemblies.work-orders.execution.store', $wo), [
            'execution_date' => '2026-08-23',
            'trees_created' => 5,
            'family_code' => 'A',
            'quantities' => [20, 20, 20, 20, 20],
        ]);

        $response->assertRedirect();
        $this->assertEquals(5, LostWaxTree::count());
        $this->assertEquals(100, LostWaxTree::sum('quantity'));

        $wo->refresh();
        $this->assertEquals(5, $wo->executions()->first()->trees_created);
    }

    /**
     * Scenario B: WO 120, execution 98, capacity 20 -> 5 tree -> 20, 20, 20, 20, 18
     */
    public function test_scenario_b_wo_120_execution_98_capacity_20(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();
        $line = $this->setUpPrintExecution($plan, $user, 120, 0);

        $this->actingAs($user)->post(route('lost-wax.assemblies.work-orders.store', $line), [
            'qty_ordered' => 120,
            'standard_capacity_guide' => 20,
        ]);
        $wo = LostWaxRangkaiWorkOrder::first();

        $response = $this->actingAs($user)->post(route('lost-wax.assemblies.work-orders.execution.store', $wo), [
            'execution_date' => '2026-08-23',
            'trees_created' => 5,
            'family_code' => 'A',
            'quantities' => [20, 20, 20, 20, 18],
        ]);

        $response->assertRedirect();
        $this->assertEquals(5, LostWaxTree::count());
        $this->assertEquals(98, LostWaxTree::sum('quantity'));

        $quantities = LostWaxTree::pluck('quantity')->toArray();
        $this->assertEquals([20, 20, 20, 20, 18], $quantities);
    }

    /**
     * Scenario C: WO 120, execution 10, capacity 20 -> 1 tree -> 10
     */
    public function test_scenario_c_wo_120_execution_10_capacity_20(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();
        $line = $this->setUpPrintExecution($plan, $user, 120, 0);

        $this->actingAs($user)->post(route('lost-wax.assemblies.work-orders.store', $line), [
            'qty_ordered' => 120,
            'standard_capacity_guide' => 20,
        ]);
        $wo = LostWaxRangkaiWorkOrder::first();

        $response = $this->actingAs($user)->post(route('lost-wax.assemblies.work-orders.execution.store', $wo), [
            'execution_date' => '2026-08-23',
            'trees_created' => 1,
            'family_code' => 'A',
            'quantities' => [10],
        ]);

        $response->assertRedirect();
        $this->assertEquals(1, LostWaxTree::count());
        $this->assertEquals(10, LostWaxTree::sum('quantity'));
        $this->assertEquals([10], LostWaxTree::pluck('quantity')->toArray());
    }

    /**
     * Scenario D: WO 120, execution 100, capacity 10 -> 10 tree
     */
    public function test_scenario_d_wo_120_execution_100_capacity_10(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();
        $line = $this->setUpPrintExecution($plan, $user, 120, 0);

        $this->actingAs($user)->post(route('lost-wax.assemblies.work-orders.store', $line), [
            'qty_ordered' => 120,
            'standard_capacity_guide' => 10,
        ]);
        $wo = LostWaxRangkaiWorkOrder::first();

        $response = $this->actingAs($user)->post(route('lost-wax.assemblies.work-orders.execution.store', $wo), [
            'execution_date' => '2026-08-23',
            'trees_created' => 10,
            'family_code' => 'A',
            'quantities' => array_fill(0, 10, 10),
        ]);

        $response->assertRedirect();
        $this->assertEquals(10, LostWaxTree::count());
        $this->assertEquals(100, LostWaxTree::sum('quantity'));
    }

    /**
     * Scenario E: Execution tidak boleh melebihi outstanding
     */
    public function test_scenario_e_execution_cannot_exceed_outstanding(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();
        $line = $this->setUpPrintExecution($plan, $user, 120, 0);

        $this->actingAs($user)->post(route('lost-wax.assemblies.work-orders.store', $line), [
            'qty_ordered' => 100,
            'standard_capacity_guide' => 20,
        ]);
        $wo = LostWaxRangkaiWorkOrder::first();

        $response = $this->actingAs($user)->post(route('lost-wax.assemblies.work-orders.execution.store', $wo), [
            'execution_date' => '2026-08-23',
            'trees_created' => 6,
            'family_code' => 'A',
            'quantities' => array_fill(0, 6, 20),
        ]);

        $response->assertSessionHas('error');
        $this->assertEquals(0, LostWaxTree::count());
    }

    /**
     * Scenario I: Print A5 loads correctly
     */
    public function test_scenario_i_print_a5_loads_correctly(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();
        $line = $this->setUpPrintExecution($plan, $user, 120, 0);

        $this->actingAs($user)->post(route('lost-wax.assemblies.work-orders.store', $line), [
            'qty_ordered' => 120,
            'standard_capacity_guide' => 20,
        ]);
        $wo = LostWaxRangkaiWorkOrder::first();

        $response = $this->actingAs($user)->get(route('lost-wax.assemblies.work-orders.print', $wo));
        $response->assertOk();
        $response->assertSee('A5 landscape');
        $response->assertSee('Perintah Rangkai');
        $response->assertSee($wo->rangkai_order_number);
    }

    /**
     * Scenario J: Detail page loads successfully and renders capacity
     */
    public function test_work_order_detail_page_loads_successfully(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();
        $line = $this->setUpPrintExecution($plan, $user, 120, 0);

        $this->actingAs($user)->post(route('lost-wax.assemblies.work-orders.store', $line), [
            'qty_ordered' => 120,
            'standard_capacity_guide' => 20,
        ]);
        $wo = LostWaxRangkaiWorkOrder::first();

        $response = $this->actingAs($user)->get(route('lost-wax.assemblies.work-orders.show', $wo));
        $response->assertOk();
        $response->assertSee($wo->rangkai_order_number);
        $response->assertSee('20 pcs / tree');
        $response->assertSee('Pedoman');
    }
}
