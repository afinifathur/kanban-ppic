<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxItemReference;
use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxTree;
use App\Models\LostWaxWorkOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefactorIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function createProductionPlan($attributes = [])
    {
        return \App\Models\ProductionPlan::create(array_merge([
            'code' => '26AB001',
            'customer' => 'A06',
            'item_code' => '4.101105K.A0020',
            'item_name' => 'SS304 JIS 5K 3/4"',
            'aisi' => '304',
            'size' => '3/4"',
            'weight' => 0.75,
            'po_number' => 'PO-AB-01',
            'qty_planned' => 200,
            'qty_remaining' => 200,
            'line_number' => 1,
            'status' => 'planning',
        ], $attributes));
    }

    public function test_new_flow_traceability_and_production_status_aggregation(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan();

        // 1. Create Print Order #1 (Ordered: 120)
        $order1 = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260818-0001',
            'scheduled_date' => '2026-08-18',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $line1 = $order1->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 120,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        // Record Outcome for Print Order #1 (Good: 118, Defect: 2)
        $this->actingAs($user)
            ->put(route('lost-wax.outcomes.update', $order1), [
                'items' => [
                    [
                        'id' => $line1->id,
                        'qty_actual_good' => 118,
                        'qty_actual_defect' => 2,
                        'standard_tree_capacity' => 20,
                    ],
                ],
            ]);

        $line1->refresh();
        $this->assertEquals(118, $line1->qty_actual_good);

        // Assemble 6 trees: 20 * 5 + 18 = 118
        $this->actingAs($user)
            ->post(route('lost-wax.assemblies.store', $line1), [
                'quantities' => [20, 20, 20, 20, 20, 18],
                'family_code' => '1',
            ]);

        // Assert 6 trees created under line 1
        $this->assertEquals(6, LostWaxTree::where('lost_wax_print_order_line_id', $line1->id)->count());

        // Check Production Status page loads and contains the row
        $response = $this->actingAs($user)->get(route('lost-wax.production-status'));
        $response->assertStatus(200);

        // Check row variables inside the view data
        $rows = $response->viewData('rows');
        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertEquals('print_order_line', $row['source_type']);
        $this->assertEquals($line1->id, $row['source_id']);
        $this->assertEquals('26AB001', $row['code']);
        $this->assertEquals('A06', $row['customer']);
        $this->assertEquals(200, $row['planned_qty']);
        $this->assertEquals(120, $row['scheduled_qty']);
        $this->assertEquals(118, $row['actual_good']);
        $this->assertEquals(2, $row['actual_defect']);
        $this->assertEquals(118, $row['assembly_qty']);
        $this->assertEquals(6, $row['tree_count']);

        // 2. Create Print Order #2 (Ordered: 82) to trigger plan aggregation
        $order2 = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260818-0002',
            'scheduled_date' => '2026-08-18',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $line2 = $order2->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 82,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        // Record Outcome for Print Order #2 (Good: 80, Defect: 2)
        $this->actingAs($user)
            ->put(route('lost-wax.outcomes.update', $order2), [
                'items' => [
                    [
                        'id' => $line2->id,
                        'qty_actual_good' => 80,
                        'qty_actual_defect' => 2,
                        'standard_tree_capacity' => 20,
                    ],
                ],
            ]);

        $line2->refresh();

        // Assemble 5 trees under line 2 (e.g. 20 * 4) -> 80
        $this->actingAs($user)
            ->post(route('lost-wax.assemblies.store', $line2), [
                'quantities' => [20, 20, 20, 20],
                'family_code' => '1',
            ]);

        // Verify aggregation on Production Status: Plan = 200, Scheduled = 202, Good = 198, Defect = 4
        $response2 = $this->actingAs($user)->get(route('lost-wax.production-status'));
        $rows2 = $response2->viewData('rows');

        // Still exactly 1 row (aggregated by ProductionPlan)
        $this->assertCount(1, $rows2);
        $aggregatedRow = $rows2[0];

        $this->assertEquals(200, $aggregatedRow['planned_qty']);
        $this->assertEquals(202, $aggregatedRow['scheduled_qty']); // Over-scheduled
        $this->assertEquals(198, $aggregatedRow['actual_good']);
        $this->assertEquals(4, $aggregatedRow['actual_defect']);
        $this->assertEquals(198, $aggregatedRow['assembly_qty']);
        $this->assertEquals(10, $aggregatedRow['tree_count']); // 6 from line 1 + 4 from line 2

        // Verify tree detail modal loading works explicitly with first line_id
        $treesResponse = $this->actingAs($user)->get(
            route('lost-wax.production-status.trees', ['print_order_line_id' => $aggregatedRow['source_id']])
        );
        $treesResponse->assertStatus(200);
        $treesData = $treesResponse->json();

        $this->assertEquals(10, $treesData['tree_count']);
        $this->assertCount(10, $treesData['trees']);
    }

    public function test_legacy_work_orders_display_and_compatibility(): void
    {
        $user = User::factory()->create();

        // Create legacy reference and work order
        $ref = LostWaxItemReference::create([
            'item_code_snapshot' => 'LEGACY-01',
            'item_name_snapshot' => 'Legacy Item',
            'aisi_snapshot' => '304',
            'master_item_key' => 'LEGACY-KEY',
        ]);

        $wo = LostWaxWorkOrder::create([
            'et_code' => 'LWDEMO001',
            'customer_name' => 'Legacy Customer',
            'item_reference_id' => $ref->id,
            'po_number' => 'PO-LEGACY-01',
            'po_quantity' => 100,
            'stock_quantity' => 0,
            'net_requirement_quantity' => 100,
            'planned_quantity' => 100,
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->get(route('lost-wax.production-status'));
        $rows = $response->viewData('rows');

        // Check that legacy row displays correctly
        $legacyRow = collect($rows)->firstWhere('source_type', 'legacy_work_order');
        $this->assertNotNull($legacyRow);
        $this->assertEquals($wo->id, $legacyRow['source_id']);
        $this->assertEquals('LWDEMO001', $legacyRow['code']);
        $this->assertEquals('Legacy Customer', $legacyRow['customer']);
        $this->assertEquals(100, $legacyRow['planned_qty']);

        // Verify tree detail modal queries legacy work order
        $treesResponse = $this->actingAs($user)->get(
            route('lost-wax.production-status.trees', ['work_order_id' => $wo->id])
        );
        $treesResponse->assertStatus(200);
        $treesData = $treesResponse->json();
        $this->assertEquals('LWDEMO001', $treesData['et_code']);
    }
}
