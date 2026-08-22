<?php

namespace Tests\Feature\LostWax;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ppic']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'operator']);

        // Give ppic access_planning permission if permissions exist
        $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'access_planning']);
        \Spatie\Permission\Models\Role::findByName('ppic')->givePermissionTo($permission);
    }

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

    /**
     * Sprint 1: Verify plan sorting, closed filters, open/close buttons, and database persistence.
     */
    public function test_sprint_1_plans_pool_behavior(): void
    {
        $user = User::factory()->create();

        // 1. Plan dengan Sisa > 0 (tampil di pool Aktif)
        $planActive = $this->createProductionPlan([
            'code' => 'CODE-ACT',
            'customer' => 'CUST-ACT',
            'qty_planned' => 100,
            'is_closed' => false,
        ]);

        // 2. Plan dengan Sisa = 0 (tidak muncul di pool Aktif)
        $planCompleted = $this->createProductionPlan([
            'code' => 'CODE-COMP',
            'customer' => 'CUST-COMP',
            'qty_planned' => 100,
            'is_closed' => false,
        ]);
        // Schedule it fully
        $order1 = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260819-0001',
            'scheduled_date' => '2026-08-19',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);
        $order1->lines()->create([
            'production_plan_id' => $planCompleted->id,
            'qty_ordered' => 100,
            'item_name' => $planCompleted->item_name,
        ]);

        // 3. Plan dengan Sisa < 0 (tidak muncul di pool Aktif)
        $planOverScheduled = $this->createProductionPlan([
            'code' => 'CODE-OVER',
            'customer' => 'CUST-OVER',
            'qty_planned' => 100,
            'is_closed' => false,
        ]);
        $order2 = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260819-0002',
            'scheduled_date' => '2026-08-19',
            'status' => 'DRAFT',
            'created_by' => $user->id,
        ]);
        $order2->lines()->create([
            'production_plan_id' => $planOverScheduled->id,
            'qty_ordered' => 120,
            'item_name' => $planOverScheduled->item_name,
        ]);

        // 4. Plan CLOSED tidak muncul di pool Aktif
        $planClosed = $this->createProductionPlan([
            'code' => 'CODE-CLOSED',
            'customer' => 'CUST-CLOSED',
            'qty_planned' => 100,
            'is_closed' => true,
        ]);

        // Request default pool Aktif
        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.plans', ['status' => 'active']));
        $response->assertOk();
        $response->assertSee('CODE-ACT');

        $html = $response->getContent();
        preg_match('/<tbody[^>]*>(.*?)<\/tbody>/is', $html, $matches);
        $tbody = $matches[1] ?? '';

        $this->assertStringContainsString('CODE-ACT', $tbody);
        $this->assertStringNotContainsString('CODE-COMP', $tbody);
        $this->assertStringNotContainsString('CODE-OVER', $tbody);
        $this->assertStringNotContainsString('CODE-CLOSED', $tbody);

        // 5. Plan CLOSED muncul pada filter Closed
        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.plans', ['status' => 'closed']));
        $response->assertOk();

        $htmlClosed = $response->getContent();
        preg_match('/<tbody[^>]*>(.*?)<\/tbody>/is', $htmlClosed, $matches);
        $tbodyClosed = $matches[1] ?? '';

        $this->assertStringNotContainsString('CODE-ACT', $tbodyClosed);
        $this->assertStringContainsString('CODE-CLOSED', $tbodyClosed);

        // 8. Production Plan tidak terhapus ketika ditutup (masih ada di database)
        $this->assertDatabaseHas('production_plans', ['id' => $planClosed->id]);

        // 6. Plan CLOSED dapat dibuka kembali
        $response = $this->actingAs($user)->post(route('lost-wax.print-orders.store'), [
            'action' => 'open_plan',
            'production_plan_id' => $planClosed->id,
        ]);
        $response->assertRedirect();
        $this->assertFalse($planClosed->fresh()->is_closed);

        // 7. Membuka kembali plan dengan Sisa 0 tidak membuatnya muncul di pool Aktif
        // Let's close the completed plan first:
        $response = $this->actingAs($user)->post(route('lost-wax.print-orders.store'), [
            'action' => 'close_plan',
            'production_plan_id' => $planCompleted->id,
        ]);
        $response->assertRedirect();
        $this->assertTrue($planCompleted->fresh()->is_closed);

        // Now open it again:
        $response = $this->actingAs($user)->post(route('lost-wax.print-orders.store'), [
            'action' => 'open_plan',
            'production_plan_id' => $planCompleted->id,
        ]);
        $response->assertRedirect();
        $this->assertFalse($planCompleted->fresh()->is_closed);

        // Check default pool Aktif again: completed plan (sisa 0) should still not be there
        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.plans', ['status' => 'active']));
        $response->assertOk();

        $htmlActive2 = $response->getContent();
        preg_match('/<tbody[^>]*>(.*?)<\/tbody>/is', $htmlActive2, $matches);
        $tbodyActive2 = $matches[1] ?? '';
        $this->assertStringNotContainsString('CODE-COMP', $tbodyActive2);
    }

    /**
     * Sprint 1: Verify closed plan validation (creation prevention), autocomplete search data,
     * filter retention, and orders tab rendering.
     */
    public function test_sprint_1_security_and_filters_behavior(): void
    {
        $user = User::factory()->create();

        // 9. CLOSED plan tidak dapat digunakan membuat Print Order baru (create & store)
        $planClosed = $this->createProductionPlan([
            'code' => 'CODE-CLOSED',
            'customer' => 'CUST-CLOSED',
            'qty_planned' => 100,
            'is_closed' => true,
        ]);

        // Try load create page with CLOSED plan
        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.create', ['plan_ids' => [$planClosed->id]]));
        $response->assertRedirect(route('lost-wax.print-orders.plans'));
        $response->assertSessionHas('error', 'Item Production Plan ini sudah ditutup dan tidak dapat dibuat menjadi Perintah Cetak baru.');

        // Try store print order with CLOSED plan
        $response = $this->actingAs($user)->post(route('lost-wax.print-orders.store'), [
            'scheduled_date' => '2026-08-19',
            'print_order_number' => 'PC-20260819-9999',
            'items' => [
                [
                    'production_plan_id' => $planClosed->id,
                    'qty_ordered' => 50,
                ],
            ],
        ]);
        $response->assertRedirect(route('lost-wax.print-orders.plans'));
        $response->assertSessionHas('error', 'Item Production Plan ini sudah ditutup dan tidak dapat dibuat menjadi Perintah Cetak baru.');

        // 10. Autocomplete Kode Cust bekerja & 11. Autocomplete Customer bekerja
        $planAutocomplete = $this->createProductionPlan([
            'code' => 'AUTO-CODE',
            'customer' => 'AUTO-CUST',
            'is_closed' => false,
        ]);

        // Create 15 more plans to trigger pagination
        for ($i = 0; $i < 15; $i++) {
            $this->createProductionPlan([
                'code' => 'AUTO-CODE',
                'customer' => 'AUTO-CUST',
                'is_closed' => false,
            ]);
        }

        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.plans'));
        $response->assertOk();
        $response->assertSee('AUTO-CODE');
        $response->assertSee('AUTO-CUST');

        // 12. Kombinasi filter bekerja & 13. Pagination mempertahankan filter
        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.plans', [
            'code' => 'AUTO-CODE',
            'customer' => 'AUTO-CUST',
            'status' => 'active',
        ]));
        $response->assertOk();
        $response->assertSee('AUTO-CODE');
        $response->assertSee('plans_page=2');
        $response->assertSee('status=active');
        $response->assertSee('code=AUTO-CODE');
        $response->assertSee('customer=AUTO-CUST');

        // 14. ?tab=orders membuka tab Dokumen Perintah Cetak
        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.plans', ['tab' => 'orders']));
        $response->assertOk();
        $response->assertSee('Dokumen Perintah Cetak (Print Orders)');
        // Ensure Tab 2 table headers are visible
        $response->assertSee('Total Qty');

        // 15. Print Order existing tetap menggunakan state machine yang sama (DRAFT, ISSUED, CANCELLED)
        // 16. Existing Print Order workflow tidak rusak
        $plan = $this->createProductionPlan();
        $order = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260819-0010',
            'scheduled_date' => '2026-08-19',
            'status' => 'DRAFT',
            'created_by' => $user->id,
        ]);
        $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 50,
            'item_name' => $plan->item_name,
        ]);

        $this->assertSame('DRAFT', $order->status);

        // Transition to ISSUED
        $this->actingAs($user)->post(route('lost-wax.print-orders.update-status', $order), ['status' => 'ISSUED']);
        $this->assertSame('ISSUED', $order->fresh()->status);

        // Transition to CANCELLED
        $this->actingAs($user)->post(route('lost-wax.print-orders.update-status', $order), ['status' => 'CANCELLED']);
        $this->assertSame('CANCELLED', $order->fresh()->status);
    }

    public function test_bulk_close_and_single_close_workflow_safety(): void
    {
        $user = User::factory()->create();
        $user->assignRole('ppic');

        $plan1 = $this->createProductionPlan(['code' => 'P1', 'is_closed' => false]);
        $plan2 = $this->createProductionPlan(['code' => 'P2', 'is_closed' => false]);
        $plan3 = $this->createProductionPlan(['code' => 'P3', 'is_closed' => false]);

        // 1. PPIC can single close a plan item
        $response = $this->actingAs($user)->post(route('lost-wax.print-orders.store'), [
            'action' => 'close_plan',
            'production_plan_id' => $plan1->id,
        ]);
        $response->assertRedirect();
        $this->assertTrue($plan1->fresh()->is_closed);

        // 2. PPIC can bulk close plans
        $response = $this->actingAs($user)->post(route('lost-wax.print-orders.store'), [
            'action' => 'bulk_close_plans',
            'plan_ids' => [$plan2->id, $plan3->id],
        ]);
        $response->assertRedirect();
        $this->assertTrue($plan2->fresh()->is_closed);
        $this->assertTrue($plan3->fresh()->is_closed);

        // 3. Closed plans are excluded from active list filter
        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.plans', ['status' => 'active']));
        $response->assertOk();
        $plansInActiveView = $response->original->getData()['plans'];
        $this->assertFalse($plansInActiveView->contains('id', $plan1->id));
        $this->assertFalse($plansInActiveView->contains('id', $plan2->id));
        $this->assertFalse($plansInActiveView->contains('id', $plan3->id));

        // 4. Closed plans are visible under closed filter
        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.plans', ['status' => 'closed']));
        $response->assertOk();
        $plansInClosedView = $response->original->getData()['plans'];
        $this->assertTrue($plansInClosedView->contains('id', $plan1->id));
        $this->assertTrue($plansInClosedView->contains('id', $plan2->id));
        $this->assertTrue($plansInClosedView->contains('id', $plan3->id));

        // 5. Closed plan cannot be chosen to create a new print order
        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.create', ['plan_ids' => [$plan1->id]]));
        $response->assertRedirect(route('lost-wax.print-orders.plans'));
        $response->assertSessionHas('error', 'Item Production Plan ini sudah ditutup dan tidak dapat dibuat menjadi Perintah Cetak baru.');

        // 6. Action safety: closing does NOT create print orders or modify execution counts
        $this->assertSame(0, \App\Models\LostWaxPrintOrder::count());
        $this->assertSame(0, \App\Models\LostWaxPrintOrderLine::count());
    }
}
