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
            'product_scope' => 'FLANGE_STAINLESS',
        ], $attributes));
    }

    /**
     * Helper to create a PPIC user scoped to FLANGE_STAINLESS.
     */
    protected function makePpicUser(): User
    {
        $user = User::factory()->create(['product_scope' => 'FLANGE_STAINLESS']);
        $user->assignRole('ppic');

        return $user;
    }

    /**
     * Helper to create a print order with a given number of unique-item lines.
     */
    protected function createMultiLineOrder(User $user, int $lineCount, string $status = 'ISSUED'): \App\Models\LostWaxPrintOrder
    {
        $order = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260825-MULTI-'.$lineCount,
            'scheduled_date' => '2026-08-25',
            'status' => $status,
            'created_by' => $user->id,
        ]);

        for ($i = 1; $i <= $lineCount; $i++) {
            $order->lines()->create([
                'qty_ordered' => 10,
                'code' => sprintf('ITEM-%03d', $i),
                'customer' => 'CUST-001',
                'item_name' => 'Produk Fitting '.$i,
                'size' => '1"',
                'aisi' => '304',
            ]);
        }

        return $order;
    }

    /**
     * Helper to create a single-line print order with a specific command quantity.
     */
    protected function createSingleLineOrder(User $user, string $code, int $qtyOrdered, string $status = 'ISSUED'): \App\Models\LostWaxPrintOrder
    {
        $order = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260825-CONT-'.$code,
            'scheduled_date' => '2026-08-25',
            'status' => $status,
            'created_by' => $user->id,
        ]);

        $order->lines()->create([
            'qty_ordered' => $qtyOrdered,
            'code' => $code,
            'customer' => 'CUST-001',
            'item_name' => 'Produk '.$code,
            'size' => '1"',
            'aisi' => '304',
        ]);

        return $order;
    }

    /**
     * Helper to record a FINALIZED execution against a print order line.
     */
    protected function recordExecution(\App\Models\LostWaxPrintOrderLine $line, int $good, int $defect): void
    {
        app(\App\Services\PrintExecutionService::class)->record($line, [
            'qty_good' => $good,
            'qty_defect' => $defect,
            'execution_date' => '2026-08-25',
            'status' => 'FINALIZED',
            'recorded_by' => $line->printOrder->created_by,
        ]);
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
        $user = User::factory()->create(['product_scope' => 'FLANGE_STAINLESS']);
        $user->assignRole('ppic');

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

        $html = $response->getContent();
        $this->assertStringContainsString('transform: scale(0.95)', $html);
        $this->assertStringContainsString('print-wrapper', $html);
        $this->assertStringNotContainsString('<div class="draft-watermark">', $html);
        $this->assertStringNotContainsString('BELUM DITERBITKAN - JANGAN DIGUNAKAN SEBAGAI PERINTAH PRODUKSI', $html);

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

    public function test_draft_print_renders_dual_watermarks_without_mutation(): void
    {
        $plan1 = $this->createProductionPlan(['code' => 'DRAFT-001', 'item_name' => 'Draft Produk 1']);
        $plan2 = $this->createProductionPlan(['code' => 'DRAFT-002', 'item_name' => 'Draft Produk 2']);
        $user = User::factory()->create(['product_scope' => 'FLANGE_STAINLESS']);
        $user->assignRole('ppic');

        $order = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260825-0002',
            'scheduled_date' => '2026-08-25',
            'status' => 'DRAFT',
            'created_by' => $user->id,
        ]);

        $order->lines()->create([
            'production_plan_id' => $plan1->id,
            'qty_ordered' => 120,
            'code' => $plan1->code,
            'customer' => $plan1->customer,
            'item_name' => $plan1->item_name,
            'size' => $plan1->size,
            'aisi' => $plan1->aisi,
        ]);

        $order->lines()->create([
            'production_plan_id' => $plan2->id,
            'qty_ordered' => 80,
            'code' => $plan2->code,
            'customer' => $plan2->customer,
            'item_name' => $plan2->item_name,
            'size' => $plan2->size,
            'aisi' => $plan2->aisi,
        ]);

        $this->assertSame('DRAFT', $order->fresh()->status);
        $this->assertSame(0, \App\Models\LostWaxPrintExecution::count());

        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.print', $order));
        $response->assertOk();

        $html = $response->getContent();
        $this->assertSame(2, substr_count($html, '<div class="draft-watermark">'));
        $this->assertSame(2, substr_count($html, '<span class="draft-watermark__title">DRAFT</span>'));
        $this->assertSame(2, substr_count($html, 'BELUM DITERBITKAN - JANGAN DIGUNAKAN SEBAGAI PERINTAH PRODUKSI'));
        $response->assertSee('DRAFT');
        $response->assertSee('FORM LAPORAN KERJA CETAK LILIN');
        $response->assertSee('FORM SETTING MESIN CETAK');

        $this->assertSame('DRAFT', $order->fresh()->status);
        $this->assertSame(0, \App\Models\LostWaxPrintExecution::count());
    }

    public function test_print_compact_mode_preserves_existing_layout_for_up_to_ten_items(): void
    {
        $user = $this->makePpicUser();

        foreach ([9, 10] as $lineCount) {
            $order = $this->createMultiLineOrder($user, $lineCount);

            $response = $this->actingAs($user)->get(route('lost-wax.print-orders.print', $order));
            $response->assertOk();

            $html = $response->getContent();

            $this->assertStringContainsString('data-print-layout="compact"', $html);
            $this->assertStringNotContainsString('data-print-layout="expanded"', $html);
            $this->assertStringNotContainsString('<div class="print-page">', $html);

            $response->assertSee('FORM LAPORAN KERJA CETAK LILIN');
            $response->assertSee('FORM SETTING MESIN CETAK');

            // 10 rows per form (2 forms) = 20 rows
            $this->assertSame(20, substr_count($html, '<tr class="h-6">'));

            // Existing print-safe scaling is retained
            $this->assertStringContainsString('transform: scale(0.95)', $html);
        }
    }

    public function test_print_expanded_mode_for_eleven_items_renders_two_pages_with_thirty_rows(): void
    {
        $user = $this->makePpicUser();
        $order = $this->createMultiLineOrder($user, 11);

        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.print', $order));
        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('data-print-layout="expanded"', $html);
        $this->assertStringNotContainsString('data-print-layout="compact"', $html);
        $this->assertSame(2, substr_count($html, '<div class="print-page">'));

        // 30 rows per form (2 forms) = 60 rows
        $this->assertSame(60, substr_count($html, '<tr class="h-6">'));

        // Every item appears exactly once per form
        $this->assertSame(2, substr_count($html, 'ITEM-001'));
        $this->assertSame(2, substr_count($html, 'ITEM-011'));

        $response->assertSee('FORM LAPORAN KERJA CETAK LILIN');
        $response->assertSee('FORM SETTING MESIN CETAK');
    }

    public function test_print_expanded_mode_applies_print_safe_scale(): void
    {
        $user = $this->makePpicUser();
        $order = $this->createMultiLineOrder($user, 11);

        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.print', $order));
        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('transform: scale(0.95)', $html);
        $this->assertStringContainsString('transform-origin: top left', $html);

        // Still two pages with both forms
        $this->assertSame(2, substr_count($html, '<div class="print-page">'));
        $response->assertSee('FORM LAPORAN KERJA CETAK LILIN');
        $response->assertSee('FORM SETTING MESIN CETAK');
    }

    public function test_print_expanded_mode_for_twenty_one_items_keeps_all_items_in_order(): void
    {
        $user = $this->makePpicUser();
        $order = $this->createMultiLineOrder($user, 21);

        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.print', $order));
        $response->assertOk();

        $html = $response->getContent();

        $this->assertSame(2, substr_count($html, '<div class="print-page">'));
        $this->assertSame(60, substr_count($html, '<tr class="h-6">'));

        // First and last items both present in each form, ordered identically
        $this->assertSame(2, substr_count($html, 'ITEM-001'));
        $this->assertSame(2, substr_count($html, 'ITEM-021'));
        $this->assertTrue(strpos($html, 'ITEM-001') < strpos($html, 'ITEM-021'));
    }

    public function test_print_expanded_mode_for_twenty_five_items_no_missing_or_overflow(): void
    {
        $user = $this->makePpicUser();
        $order = $this->createMultiLineOrder($user, 25);

        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.print', $order));
        $response->assertOk();

        $html = $response->getContent();

        $this->assertSame(2, substr_count($html, '<div class="print-page">'));
        $this->assertSame(60, substr_count($html, '<tr class="h-6">'));

        // Last item must not be dropped
        $this->assertSame(2, substr_count($html, 'ITEM-025'));
        $this->assertSame(2, substr_count($html, 'ITEM-001'));
    }

    public function test_draft_expanded_print_renders_watermark_on_both_pages(): void
    {
        $user = $this->makePpicUser();
        $order = $this->createMultiLineOrder($user, 12, 'DRAFT');

        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.print', $order));
        $response->assertOk();

        $html = $response->getContent();

        $this->assertSame(2, substr_count($html, '<div class="print-page">'));
        $this->assertSame(2, substr_count($html, '<div class="draft-watermark">'));
        $this->assertSame(2, substr_count($html, '<span class="draft-watermark__title">DRAFT</span>'));
        $this->assertSame(2, substr_count($html, 'BELUM DITERBITKAN - JANGAN DIGUNAKAN SEBAGAI PERINTAH PRODUKSI'));

        // Read-only: status unchanged, no executions created
        $this->assertSame('DRAFT', $order->fresh()->status);
        $this->assertSame(0, \App\Models\LostWaxPrintExecution::count());
    }

    public function test_continuation_print_fresh_order_shows_full_qty(): void
    {
        $user = $this->makePpicUser();
        $order = $this->createSingleLineOrder($user, '268KS103', 250);

        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.print', $order));
        $response->assertOk();

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '>250</td>'));
    }

    public function test_continuation_print_partial_execution_shows_outstanding(): void
    {
        $user = $this->makePpicUser();
        $order = $this->createSingleLineOrder($user, '268KS103', 250);

        $this->recordExecution($order->lines->first(), 120, 0);

        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.print', $order));
        $response->assertOk();

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '>130</td>'));
        $this->assertSame(0, substr_count($html, '>250</td>'));
    }

    public function test_continuation_print_partial_with_defect_shows_net_outstanding(): void
    {
        $user = $this->makePpicUser();
        $order = $this->createSingleLineOrder($user, '268KS103', 250);

        $this->recordExecution($order->lines->first(), 120, 10);

        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.print', $order));
        $response->assertOk();

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '>120</td>'));
        $this->assertSame(0, substr_count($html, '>250</td>'));
    }

    public function test_continuation_print_fully_completed_item_redirects_instead_of_empty_pdf(): void
    {
        $user = $this->makePpicUser();
        $order = $this->createSingleLineOrder($user, '268KS103', 250);

        $this->recordExecution($order->lines->first(), 250, 0);

        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.print', $order));

        $response->assertRedirect(route('lost-wax.print-orders.show', $order));
        $response->assertSessionHas('error', 'Seluruh item sudah selesai dicetak, tidak ada sisa yang perlu dicetak ulang.');
    }

    public function test_continuation_print_mixed_items_only_shows_outstanding_item(): void
    {
        $user = $this->makePpicUser();

        $order = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260825-MIXED-1',
            'scheduled_date' => '2026-08-25',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $itemA = $order->lines()->create([
            'qty_ordered' => 250,
            'code' => '268KS103',
            'customer' => 'CUST-001',
            'item_name' => 'Produk A',
            'size' => '1"',
            'aisi' => '304',
        ]);

        $itemB = $order->lines()->create([
            'qty_ordered' => 250,
            'code' => '268ST007',
            'customer' => 'CUST-001',
            'item_name' => 'Produk B',
            'size' => '1"',
            'aisi' => '304',
        ]);

        // A fully completed, B partially completed (180 good)
        $this->recordExecution($itemA, 250, 0);
        $this->recordExecution($itemB, 180, 0);

        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.print', $order));
        $response->assertOk();

        $html = $response->getContent();

        // Only B remains with 70 outstanding; A is omitted entirely.
        $this->assertSame(1, substr_count($html, '>70</td>'));
        $this->assertStringContainsString('268ST007', $html);
        $this->assertStringNotContainsString('268KS103', $html);
    }

    public function test_continuation_print_multi_day_and_historical_integrity(): void
    {
        $user = $this->makePpicUser();
        $order = $this->createSingleLineOrder($user, '268KS103', 250);
        $line = $order->lines->first();

        // Day 1: 120 good -> outstanding 130
        $this->recordExecution($line, 120, 0);
        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.print', $order));
        $this->assertSame(1, substr_count($response->getContent(), '>130</td>'));

        // Day 2: +100 good -> outstanding 30
        $this->recordExecution($line, 100, 0);
        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.print', $order));
        $this->assertSame(1, substr_count($response->getContent(), '>30</td>'));

        // Day 3: +30 good -> outstanding 0 -> redirect
        $this->recordExecution($line, 30, 0);
        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.print', $order));
        $response->assertRedirect(route('lost-wax.print-orders.show', $order));

        // Historical integrity: original command quantity remains 250
        $this->assertSame(250, $line->fresh()->qty_ordered);
        $this->assertSame(250, (int) $line->fresh()->qty_executed_good);
    }

    /**
     * Sprint 1: Verify plan sorting, closed filters, open/close buttons, and database persistence.
     */
    public function test_sprint_1_plans_pool_behavior(): void
    {
        $user = User::factory()->create(['product_scope' => 'FLANGE_STAINLESS']);
        $user->assignRole('ppic');

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
        $user = User::factory()->create(['product_scope' => 'FLANGE_STAINLESS']);
        $user->assignRole('ppic');

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
        $response->assertSessionHas('error', 'Item Production Plan ini sudah tidak aktif dan tidak dapat dibuat menjadi Perintah Cetak baru.');

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
        $response->assertSessionHas('error', 'Item Production Plan ini sudah tidak aktif dan tidak dapat dibuat menjadi Perintah Cetak baru.');

        // 10. Autocomplete Kode Cust bekerja & 11. Autocomplete Customer bekerja
        $planAutocomplete = $this->createProductionPlan([
            'code' => 'AUTO-CODE',
            'customer' => 'AUTO-CUST',
            'is_closed' => false,
        ]);

        // Create enough plans to trigger pagination with the new 50-item page size
        for ($i = 0; $i < 55; $i++) {
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
        $user = User::factory()->create(['product_scope' => 'FLANGE_STAINLESS']);
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
        $response->assertSessionHas('error', 'Item Production Plan ini sudah tidak aktif dan tidak dapat dibuat menjadi Perintah Cetak baru.');

        // 6. Action safety: closing does NOT create print orders or modify execution counts
        $this->assertSame(0, \App\Models\LostWaxPrintOrder::count());
        $this->assertSame(0, \App\Models\LostWaxPrintOrderLine::count());
    }

    public function test_actual_can_exceed_command_qty(): void
    {
        $plan = $this->createProductionPlan(['qty_planned' => 330]);
        $user = User::factory()->create();
        $order = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260824-0001',
            'scheduled_date' => '2026-08-24',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);
        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 200,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $service = app(\App\Services\PrintExecutionService::class);

        // Command = 200, Actual = 201
        $service->record($line, [
            'qty_good' => 201,
            'qty_defect' => 0,
            'status' => 'FINALIZED',
            'execution_date' => '2026-08-24',
            'recorded_by' => $user->id,
        ]);

        $line = $line->fresh();
        $this->assertSame(201, $line->qty_executed_good);
        $this->assertSame(0, $line->qty_executed_defect);
        $this->assertSame('COMPLETED', $line->execution_status);
        $this->assertSame(0, $line->qty_outstanding); // Clamped via accessor to 0
    }

    public function test_large_overprint_is_allowed(): void
    {
        $plan = $this->createProductionPlan(['qty_planned' => 330]);
        $user = User::factory()->create();
        $order = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260824-0002',
            'scheduled_date' => '2026-08-24',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);
        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 200,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $service = app(\App\Services\PrintExecutionService::class);

        // Command = 200, Actual = 250
        $service->record($line, [
            'qty_good' => 250,
            'qty_defect' => 0,
            'status' => 'FINALIZED',
            'execution_date' => '2026-08-24',
            'recorded_by' => $user->id,
        ]);

        $line = $line->fresh();
        $this->assertSame(200, $line->qty_ordered);
        $this->assertSame(250, $line->qty_executed_good);
        $this->assertSame('COMPLETED', $line->execution_status);
    }

    public function test_overprint_does_not_change_planned_qty(): void
    {
        $plan = $this->createProductionPlan(['qty_planned' => 330]);
        $user = User::factory()->create();
        $order = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260824-0003',
            'scheduled_date' => '2026-08-24',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);
        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 200,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $service = app(\App\Services\PrintExecutionService::class);

        // Command = 200, Actual = 250
        $service->record($line, [
            'qty_good' => 250,
            'qty_defect' => 0,
            'status' => 'FINALIZED',
            'execution_date' => '2026-08-24',
            'recorded_by' => $user->id,
        ]);

        $plan = $plan->fresh();
        $this->assertSame(330, $plan->qty_planned);
        $this->assertSame(0, $plan->qty_scheduled); // transitioned to COMPLETED
        $this->assertSame(330, $plan->qty_remaining_scheduled);
        $this->assertSame(250, $plan->qty_produced);
        $this->assertSame(80, $plan->qty_remaining_to_produce); // 330 - 250
    }

    public function test_qty_outstanding_clamped_to_zero_externally(): void
    {
        $plan = $this->createProductionPlan(['qty_planned' => 330]);
        $user = User::factory()->create();
        $order = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260824-0004',
            'scheduled_date' => '2026-08-24',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);
        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 200,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $service = app(\App\Services\PrintExecutionService::class);
        $service->record($line, [
            'qty_good' => 210,
            'qty_defect' => 0,
            'status' => 'FINALIZED',
            'execution_date' => '2026-08-24',
            'recorded_by' => $user->id,
        ]);

        $line = $line->fresh();
        $this->assertSame(0, $line->qty_outstanding); // accessor returns max(0, outstanding)
    }

    public function test_plans_pagination_uses_fifty_items_per_page_and_create_accepts_multi_page_selected_ids(): void
    {
        $user = User::factory()->create(['product_scope' => 'FLANGE_STAINLESS']);
        $user->assignRole('ppic');

        $plans = collect();
        for ($i = 1; $i <= 120; $i++) {
            $plans->push($this->createProductionPlan([
                'code' => 'PG-'.$i,
                'customer' => 'CUST-'.$i,
                'po_number' => 'PO-'.$i,
                'item_name' => 'Plan '.$i,
            ]));
        }

        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.plans', ['status' => 'active']));
        $response->assertOk();
        $response->assertSee('Menampilkan 1 - 50 dari 120 Rencana');
        $response->assertSee('plans_page=2');

        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.plans', [
            'status' => 'active',
            'plans_page' => 2,
        ]));
        $response->assertOk();
        $response->assertSee('Menampilkan 51 - 100 dari 120 Rencana');

        $page1Plan = $plans[119];
        $page2Plan = $plans[69];
        $page3Plan = $plans[19];

        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.create', [
            'plan_ids' => [$page1Plan->id, $page2Plan->id, $page3Plan->id],
        ]));

        $response->assertOk();
        $response->assertSee($page1Plan->code);
        $response->assertSee($page2Plan->code);
        $response->assertSee($page3Plan->code);
    }

    public function test_duplicate_selected_ids_are_deduplicated_before_store(): void
    {
        $user = User::factory()->create(['product_scope' => 'FLANGE_STAINLESS']);
        $user->assignRole('ppic');

        $planA = $this->createProductionPlan(['code' => 'DUP-A']);
        $planB = $this->createProductionPlan(['code' => 'DUP-B']);

        $response = $this->actingAs($user)->post(route('lost-wax.print-orders.store'), [
            'scheduled_date' => '2026-08-25',
            'print_order_number' => 'PC-20260825-DUP-001',
            'items' => [
                [
                    'production_plan_id' => $planA->id,
                    'qty_ordered' => 100,
                ],
                [
                    'production_plan_id' => $planA->id,
                    'qty_ordered' => 100,
                ],
                [
                    'production_plan_id' => $planB->id,
                    'qty_ordered' => 75,
                ],
            ],
        ]);

        $response->assertRedirect();

        $order = \App\Models\LostWaxPrintOrder::where('print_order_number', 'PC-20260825-DUP-001')->first();
        $this->assertNotNull($order);
        $this->assertSame(2, $order->lines()->count());
        $this->assertSame(2, $order->lines()->distinct('production_plan_id')->count('production_plan_id'));
    }

    public function test_successful_create_page_exposes_selection_clear_script(): void
    {
        $user = User::factory()->create(['product_scope' => 'FLANGE_STAINLESS']);
        $user->assignRole('ppic');

        $plan = $this->createProductionPlan(['code' => 'CLEAR-1']);

        $response = $this->actingAs($user)->followingRedirects()->post(route('lost-wax.print-orders.store'), [
            'scheduled_date' => '2026-08-25',
            'print_order_number' => 'PC-20260825-CLEAR-001',
            'items' => [
                [
                    'production_plan_id' => $plan->id,
                    'qty_ordered' => 100,
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertSee('sessionStorage.removeItem');
    }

    public function test_negative_quantity_rejected(): void
    {
        $plan = $this->createProductionPlan(['qty_planned' => 330]);
        $user = User::factory()->create();
        $order = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260824-0005',
            'scheduled_date' => '2026-08-24',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);
        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 200,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $service = app(\App\Services\PrintExecutionService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->record($line, [
            'qty_good' => -10,
            'qty_defect' => 0,
            'status' => 'FINALIZED',
            'execution_date' => '2026-08-24',
            'recorded_by' => $user->id,
        ]);
    }

    public function test_print_view_renders_untruncated_long_specifications_and_aisi(): void
    {
        $plan = $this->createProductionPlan([
            'qty_planned' => 330,
        ]);
        $user = User::factory()->create();
        $order = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260824-0006',
            'scheduled_date' => '2026-08-24',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 200,
            'code' => '4.1091150LB.A0025',
            'item_name' => 'SS304 SORF ANSI 150LBS 1"',
            'size' => '1"',
            'aisi' => '304',
            'customer' => 'A06',
        ]);

        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.print', $order));
        $response->assertOk();

        // Verify that long tokens and specs are rendered fully and not truncated
        $response->assertSee('4.1091150LB.A0025');
        $response->assertSee('SS304 SORF ANSI 150LBS 1"');
        $response->assertSee('304');
        $response->assertSee('1"');
        $response->assertSee('A06');

        // Verify that the HTML output does not use CSS truncate
        $html = $response->getContent();
        $this->assertStringNotContainsString('truncate', $html);
    }

    /**
     * Helper to create a DRAFT print order linked to a production plan.
     */
    protected function createDraftOrderWithLine(User $user, \App\Models\ProductionPlan $plan, int $qty, string $number): array
    {
        $order = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => $number,
            'scheduled_date' => '2026-08-25',
            'status' => 'DRAFT',
            'created_by' => $user->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => $qty,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        return [$order, $line];
    }

    public function test_delete_item_from_draft_releases_allocation(): void
    {
        $user = $this->makePpicUser();
        $planA = $this->createProductionPlan(['code' => 'DL-A', 'qty_planned' => 120]);
        $planB = $this->createProductionPlan(['code' => 'DL-B', 'qty_planned' => 220]);

        $order = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260825-DEL-001',
            'scheduled_date' => '2026-08-25',
            'status' => 'DRAFT',
            'created_by' => $user->id,
        ]);
        $lineA = $order->lines()->create([
            'production_plan_id' => $planA->id,
            'qty_ordered' => 100,
            'item_name' => $planA->item_name,
        ]);
        $lineB = $order->lines()->create([
            'production_plan_id' => $planB->id,
            'qty_ordered' => 220,
            'item_name' => $planB->item_name,
        ]);

        $response = $this->actingAs($user)->delete(route('lost-wax.print-orders.lines.destroy', [$order, $lineB]));

        $response->assertRedirect(route('lost-wax.print-orders.edit', $order));
        $response->assertSessionHas('success');

        // Line 2 deleted, line 1 remains, order stays DRAFT.
        $this->assertDatabaseMissing('lost_wax_print_order_lines', ['id' => $lineB->id]);
        $this->assertDatabaseHas('lost_wax_print_order_lines', ['id' => $lineA->id]);
        $this->assertSame('DRAFT', $order->fresh()->status);

        // Plan B allocation released back to planning.
        $this->assertSame(0, $planB->fresh()->qty_scheduled);
        $this->assertSame(220, $planB->fresh()->qty_remaining_scheduled);

        // Plan A allocation unchanged.
        $this->assertSame(100, $planA->fresh()->qty_scheduled);
        $this->assertSame(20, $planA->fresh()->qty_remaining_scheduled);
    }

    public function test_deleted_allocation_makes_plan_available_in_planning(): void
    {
        $user = $this->makePpicUser();
        $plan = $this->createProductionPlan(['code' => 'DL-PLAN', 'qty_planned' => 220]);

        [$order, $line] = $this->createDraftOrderWithLine($user, $plan, 220, 'PC-20260825-DEL-002');

        // Fully scheduled plan is auto-hidden from the active pool.
        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.plans', ['status' => 'active']));
        preg_match('/<tbody[^>]*>(.*?)<\/tbody>/is', $response->getContent(), $matches);
        $this->assertStringNotContainsString('DL-PLAN', $matches[1] ?? '');

        $this->actingAs($user)->delete(route('lost-wax.print-orders.lines.destroy', [$order, $line]));

        $plan = $plan->fresh();
        $this->assertSame(0, $plan->qty_scheduled);
        $this->assertSame(220, $plan->qty_remaining_scheduled);

        // Plan is selectable again in planning.
        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.plans', ['status' => 'active']));
        preg_match('/<tbody[^>]*>(.*?)<\/tbody>/is', $response->getContent(), $matches);
        $this->assertStringContainsString('DL-PLAN', $matches[1] ?? '');
    }

    public function test_cannot_delete_item_from_issued_print_order(): void
    {
        $user = $this->makePpicUser();
        $plan = $this->createProductionPlan(['code' => 'DL-ISS', 'qty_planned' => 220]);

        $order = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260825-DEL-003',
            'scheduled_date' => '2026-08-25',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);
        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 220,
            'item_name' => $plan->item_name,
        ]);

        $response = $this->actingAs($user)->delete(route('lost-wax.print-orders.lines.destroy', [$order, $line]));

        $response->assertStatus(403);
        $this->assertDatabaseHas('lost_wax_print_order_lines', ['id' => $line->id]);
        $this->assertSame(220, $plan->fresh()->qty_scheduled);
        $this->assertSame(0, $plan->fresh()->qty_remaining_scheduled);
    }

    public function test_delete_last_item_removes_empty_draft(): void
    {
        $user = $this->makePpicUser();
        $plan = $this->createProductionPlan(['code' => 'DL-LAST', 'qty_planned' => 220]);

        [$order, $line] = $this->createDraftOrderWithLine($user, $plan, 220, 'PC-20260825-DEL-004');

        $response = $this->actingAs($user)->delete(route('lost-wax.print-orders.lines.destroy', [$order, $line]));

        $response->assertRedirect(route('lost-wax.print-orders.plans'));
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('lost_wax_print_orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('lost_wax_print_order_lines', ['id' => $line->id]);

        $this->assertSame(0, $plan->fresh()->qty_scheduled);
        $this->assertSame(220, $plan->fresh()->qty_remaining_scheduled);
    }

    public function test_edit_page_renders_delete_buttons_and_edit_quantity_still_works(): void
    {
        $user = $this->makePpicUser();
        $plan = $this->createProductionPlan(['code' => 'DL-EDIT', 'qty_planned' => 200]);

        [$order, $line] = $this->createDraftOrderWithLine($user, $plan, 100, 'PC-20260825-DEL-005');

        $response = $this->actingAs($user)->get(route('lost-wax.print-orders.edit', $order));
        $response->assertOk();
        $response->assertSee('data-delete-url');
        $response->assertSee('delete-line-btn');

        // Existing quantity editing must still work.
        $response = $this->actingAs($user)->put(route('lost-wax.print-orders.update', $order), [
            'scheduled_date' => '2026-08-25',
            'print_order_number' => 'PC-20260825-DEL-005',
            'items' => [
                ['id' => $line->id, 'qty_ordered' => 150],
            ],
        ]);
        $response->assertRedirect(route('lost-wax.print-orders.show', $order));
        $this->assertSame(150, $line->fresh()->qty_ordered);
    }

    public function test_delete_middle_item_preserves_other_lines(): void
    {
        $user = $this->makePpicUser();
        $planA = $this->createProductionPlan(['code' => 'DL-MA', 'qty_planned' => 100]);
        $planB = $this->createProductionPlan(['code' => 'DL-MB', 'qty_planned' => 200]);
        $planC = $this->createProductionPlan(['code' => 'DL-MC', 'qty_planned' => 300]);

        $order = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260825-DEL-006',
            'scheduled_date' => '2026-08-25',
            'status' => 'DRAFT',
            'created_by' => $user->id,
        ]);
        $lineA = $order->lines()->create(['production_plan_id' => $planA->id, 'qty_ordered' => 100, 'item_name' => 'A']);
        $lineB = $order->lines()->create(['production_plan_id' => $planB->id, 'qty_ordered' => 200, 'item_name' => 'B']);
        $lineC = $order->lines()->create(['production_plan_id' => $planC->id, 'qty_ordered' => 300, 'item_name' => 'C']);

        $this->actingAs($user)->delete(route('lost-wax.print-orders.lines.destroy', [$order, $lineB]));

        $this->assertDatabaseMissing('lost_wax_print_order_lines', ['id' => $lineB->id]);
        $this->assertDatabaseHas('lost_wax_print_order_lines', ['id' => $lineA->id, 'qty_ordered' => 100]);
        $this->assertDatabaseHas('lost_wax_print_order_lines', ['id' => $lineC->id, 'qty_ordered' => 300]);

        $this->assertSame(0, $planA->fresh()->qty_remaining_scheduled);
        $this->assertSame(200, $planB->fresh()->qty_remaining_scheduled);
        $this->assertSame(0, $planC->fresh()->qty_remaining_scheduled);
    }

    public function test_line_with_execution_history_cannot_be_deleted(): void
    {
        $user = $this->makePpicUser();
        $plan = $this->createProductionPlan(['code' => 'DL-EXEC', 'qty_planned' => 250]);

        $order = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260825-DEL-007',
            'scheduled_date' => '2026-08-25',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);
        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 250,
            'item_name' => $plan->item_name,
        ]);

        $this->recordExecution($line, 100, 0);

        $response = $this->actingAs($user)->delete(route('lost-wax.print-orders.lines.destroy', [$order, $line]));

        $response->assertStatus(403);
        $this->assertDatabaseHas('lost_wax_print_order_lines', ['id' => $line->id]);
        $this->assertSame(1, \App\Models\LostWaxPrintExecution::count());
    }

    public function test_ppic_cannot_delete_line_from_other_scope(): void
    {
        $user = $this->makePpicUser();
        $otherPlan = $this->createProductionPlan([
            'code' => 'DL-OTHER',
            'qty_planned' => 100,
            'product_scope' => 'FLANGE_BESI',
        ]);

        $otherUser = User::factory()->create(['product_scope' => 'FLANGE_BESI']);
        $otherUser->assignRole('ppic');

        [$order, $line] = $this->createDraftOrderWithLine($otherUser, $otherPlan, 100, 'PC-20260825-DEL-008');

        $response = $this->actingAs($user)->delete(route('lost-wax.print-orders.lines.destroy', [$order, $line]));

        $response->assertStatus(403);
        $this->assertDatabaseHas('lost_wax_print_order_lines', ['id' => $line->id]);
    }
}
