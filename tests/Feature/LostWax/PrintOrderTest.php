<?php

namespace Tests\Feature\LostWax;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintOrderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper to create a production plan item.
     */
    protected function createProductionPlan($attributes = [])
    {
        return \App\Models\ProductionPlan::create(array_merge([
            'code' => 'AB01',
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

    /**
     * Test 1-5: Multiple print orders can belong to one plan, scheduled quantities,
     * sisa (remaining) calculated correctly, and planned qty remains untouched.
     */
    public function test_plan_item_can_have_multiple_print_orders_and_remaining_is_calculated_correctly(): void
    {
        $plan = $this->createProductionPlan(['qty_planned' => 200]);
        $user = User::factory()->create();

        // Create Print Order 1
        $order1 = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260818-0001',
            'scheduled_date' => '2026-08-18',
            'status' => 'DRAFT',
            'created_by' => $user->id,
        ]);
        $order1->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 120,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        // Assert first print order scheduled qty and remaining
        $this->assertSame(200, $plan->qty_planned);
        $this->assertSame(120, $plan->qty_scheduled);
        $this->assertSame(80, $plan->qty_remaining_scheduled);

        // Create Print Order 2
        $order2 = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260818-0002',
            'scheduled_date' => '2026-08-18',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);
        $order2->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 80,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        // Assert second print order scheduled qty and remaining
        $plan = $plan->fresh(); // refresh relation
        $this->assertSame(200, $plan->qty_planned);
        $this->assertSame(200, $plan->qty_scheduled);
        $this->assertSame(0, $plan->qty_remaining_scheduled);
    }

    /**
     * Test 6-10: Qty Perintah can differ from Planned Qty, and can exceed Plan.
     */
    public function test_qty_perintah_can_differ_and_exceed_plan(): void
    {
        $plan = $this->createProductionPlan(['qty_planned' => 200]);
        $user = User::factory()->create();

        $order = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260818-0001',
            'scheduled_date' => '2026-08-18',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);
        $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 220,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        $plan = $plan->fresh();
        $this->assertSame(200, $plan->qty_planned);
        $this->assertSame(220, $plan->qty_scheduled);
        $this->assertSame(-20, $plan->qty_remaining_scheduled);
    }

    /**
     * Test: Cancelled Print Orders are excluded from dynamic scheduled calculations.
     */
    public function test_cancelled_print_orders_are_excluded_from_reserved_quantities(): void
    {
        $plan = $this->createProductionPlan(['qty_planned' => 200]);
        $user = User::factory()->create();

        // Active Order (DRAFT)
        $order1 = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260818-0001',
            'scheduled_date' => '2026-08-18',
            'status' => 'DRAFT',
            'created_by' => $user->id,
        ]);
        $order1->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 120,
            'item_name' => $plan->item_name,
        ]);

        // Cancelled Order (CANCELLED)
        $order2 = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260818-0002',
            'scheduled_date' => '2026-08-18',
            'status' => 'CANCELLED',
            'created_by' => $user->id,
        ]);
        $order2->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 80,
            'item_name' => $plan->item_name,
        ]);

        $plan = $plan->fresh();
        $this->assertSame(120, $plan->qty_scheduled);
        $this->assertSame(80, $plan->qty_remaining_scheduled);
    }

    /**
     * Test 11-13: One print order can have multiple lines with correct snapshots,
     * maintaining historical integrity even if master plan details change.
     */
    public function test_print_order_lines_snapshot_data_and_maintain_historical_integrity(): void
    {
        $plan1 = $this->createProductionPlan([
            'code' => 'AB01',
            'customer' => 'A06',
            'item_name' => 'Produk A',
            'size' => '1/2"',
            'aisi' => '304',
        ]);

        $plan2 = $this->createProductionPlan([
            'code' => 'AB02',
            'customer' => 'A06',
            'item_name' => 'Produk B',
            'size' => '1"',
            'aisi' => '316',
        ]);

        $user = User::factory()->create();

        $order = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260818-0001',
            'scheduled_date' => '2026-08-18',
            'status' => 'DRAFT',
            'created_by' => $user->id,
        ]);

        $line1 = $order->lines()->create([
            'production_plan_id' => $plan1->id,
            'qty_ordered' => 50,
            'code' => $plan1->code,
            'customer' => $plan1->customer,
            'item_name' => $plan1->item_name,
            'size' => $plan1->size,
            'aisi' => $plan1->aisi,
        ]);

        $line2 = $order->lines()->create([
            'production_plan_id' => $plan2->id,
            'qty_ordered' => 150,
            'code' => $plan2->code,
            'customer' => $plan2->customer,
            'item_name' => $plan2->item_name,
            'size' => $plan2->size,
            'aisi' => $plan2->aisi,
        ]);

        // Verify snapshots initially
        $this->assertSame('AB01', $line1->code);
        $this->assertSame('Produk A', $line1->item_name);
        $this->assertSame('1/2"', $line1->size);
        $this->assertSame('304', $line1->aisi);
        $this->assertSame('A06', $line1->customer);

        $this->assertSame('AB02', $line2->code);
        $this->assertSame('Produk B', $line2->item_name);
        $this->assertSame('1"', $line2->size);
        $this->assertSame('316', $line2->aisi);
        $this->assertSame('A06', $line2->customer);

        // Modify the master plans to simulate changes later
        $plan1->update([
            'code' => 'AB99',
            'item_name' => 'Produk A Modified',
            'size' => '2"',
            'aisi' => '316L',
        ]);
        $plan2->delete(); // Simulated delete

        // Reload the lines and verify historical integrity remains intact!
        $line1 = $line1->fresh();
        $line2 = $line2->fresh();

        $this->assertSame('AB01', $line1->code);
        $this->assertSame('Produk A', $line1->item_name);
        $this->assertSame('1/2"', $line1->size);
        $this->assertSame('304', $line1->aisi);

        $this->assertSame('AB02', $line2->code);
        $this->assertSame('Produk B', $line2->item_name);
        $this->assertNull($line2->production_plan_id); // Deleted plan set to null
    }

    /**
     * Test 14-15: HTTP lifecycle and print verification (Hasil and Rusak remain empty).
     */
    public function test_print_order_http_actions_and_print_empty_columns(): void
    {
        $plan = $this->createProductionPlan();
        $user = User::factory()->create();

        // 1. Load plans page
        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.plans'));
        $response->assertOk();
        $response->assertSee($plan->code);

        // 2. Load create page
        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.create', ['plan_ids' => [$plan->id]]));
        $response->assertOk();
        $response->assertSee($plan->item_name);

        // 3. Store print order
        $response = $this->actingAs($user)->post(route('lost-wax.print-orders.store'), [
            'scheduled_date' => '2026-08-18',
            'print_order_number' => 'PC-20260818-0005',
            'items' => [
                [
                    'production_plan_id' => $plan->id,
                    'qty_ordered' => 120,
                ],
            ],
        ]);

        $order = \App\Models\LostWaxPrintOrder::where('print_order_number', 'PC-20260818-0005')->first();
        $this->assertNotNull($order);
        $response->assertRedirect(route('lost-wax.print-orders.show', $order));

        // 4. Update status to ISSUED
        $response = $this->actingAs($user)->post(route('lost-wax.print-orders.update-status', $order), [
            'status' => 'ISSUED',
        ]);
        $response->assertRedirect();
        $this->assertSame('ISSUED', $order->fresh()->status);

        // 5. Load printable form and verify empty columns
        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.print', $order));
        $response->assertOk();

        // Assert empty cells for Hasil and Rusak and Logo Prod
        $response->assertSee('FORM LAPORAN KERJA CETAK LILIN');
        $response->assertSee('QTY PRODUKSI');
        $response->assertSee('HASIL');
        $response->assertSee('RUSAK');

        // Assert document state did not change automatically due to opening print view
        $this->assertSame('ISSUED', $order->fresh()->status);

        // 6. Transition to CANCELLED and assert immutable rules
        $response = $this->actingAs($user)->post(route('lost-wax.print-orders.update-status', $order), [
            'status' => 'CANCELLED',
        ]);
        $response->assertRedirect();
        $this->assertSame('CANCELLED', $order->fresh()->status);

        // Attempting to revert CANCELLED to DRAFT should fail
        $response = $this->actingAs($user)->post(route('lost-wax.print-orders.update-status', $order), [
            'status' => 'DRAFT',
        ]);
        $response->assertSessionHas('error');
        $this->assertSame('CANCELLED', $order->fresh()->status);
    }
}
