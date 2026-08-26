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

    public function test_outcome_validation_allows_exceed_ordered(): void
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

        // Attempt total 120 (115 good + 5 defect) on qty_ordered 100 (valid overprint now)
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

        $response->assertRedirect(route('lost-wax.outcomes.index'));
        $response->assertSessionHas('success');
        $line->refresh();
        $this->assertEquals(115, $line->qty_actual_good);
        $this->assertEquals(5, $line->qty_actual_defect);
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

        // Case 1: 118 available
        $response = $this->actingAs($user)
            ->get(route('lost-wax.assemblies.create', ['line' => $line->id, 'standard_tree_capacity' => 20]));
        $response->assertOk();
        $response->assertSee('118');

        // Case 2: 43 available
        $line->update(['qty_actual_good' => 43]);
        $response = $this->actingAs($user)
            ->get(route('lost-wax.assemblies.create', ['line' => $line->id, 'standard_tree_capacity' => 20]));
        $response->assertOk();
        $response->assertSee('43');

        // Case 3: 7 available
        $line->update(['qty_actual_good' => 7]);
        $response = $this->actingAs($user)
            ->get(route('lost-wax.assemblies.create', ['line' => $line->id, 'standard_tree_capacity' => 20]));
        $response->assertOk();
        $response->assertSee('7');
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

    public function test_cancelled_print_orders_are_filtered_from_outcomes_list(): void
    {
        $user = User::factory()->create();

        // 1. Create ISSUED print order (should be visible)
        $orderIssued = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-ISSUED-01',
            'scheduled_date' => '2026-08-18',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        // 2. Create CANCELLED print order (should NOT be visible)
        $orderCancelled = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-CANCELLED-02',
            'scheduled_date' => '2026-08-18',
            'status' => 'CANCELLED',
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('lost-wax.outcomes.index'));
        $response->assertOk();

        // Assert we see the issued one and do NOT see the cancelled one
        $printOrders = $response->original->getData()['printOrders'];
        $this->assertTrue($printOrders->contains('id', $orderIssued->id));
        $this->assertFalse($printOrders->contains('id', $orderCancelled->id));
    }

    public function test_searching_for_cancelled_document_returns_empty_result(): void
    {
        $user = User::factory()->create();

        // Create CANCELLED print order
        $orderCancelled = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-CANCELLED-99',
            'scheduled_date' => '2026-08-18',
            'status' => 'CANCELLED',
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('lost-wax.outcomes.index', [
            'print_order_number' => 'PC-CANCELLED-99',
        ]));
        $response->assertOk();

        // Assert it does not return the cancelled document
        $printOrders = $response->original->getData()['printOrders'];
        $this->assertFalse($printOrders->contains('id', $orderCancelled->id));
        $this->assertCount(0, $printOrders);
    }

    public function test_assembly_work_order_print_view_renders_correct_data_and_layout(): void
    {
        $user = User::factory()->create();
        $plan = $this->createProductionPlan([
            'code' => 'KODE-PROD-999',
            'item_name' => 'CS VALVES ASME B16.34 2"',
            'customer' => 'CUST-VALVE-A',
            'aisi' => 'Q235',
            'size' => '2"',
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-PRINT-TEST-12',
            'scheduled_date' => '2026-08-24',
            'status' => 'ISSUED',
            'created_by' => $user->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 600,
            'qty_actual_good' => 600,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        $workOrder = \App\Models\LostWaxRangkaiWorkOrder::create([
            'rangkai_order_number' => 'RWO-PRINT-TEST-99',
            'lost_wax_print_order_line_id' => $line->id,
            'qty_trees_planned' => 100,
            'tree_capacity' => 1,
            'standard_capacity_guide' => 20,
            'require_layer_7' => true,
            'status' => 'OPEN',
            'notes' => 'Harap dirangkai secara hati-hati.',
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('lost-wax.assemblies.work-orders.print', $workOrder));
        $response->assertOk();

        // Assert crucial traceability fields are fully present
        $response->assertSee('RWO-PRINT-TEST-99');
        $response->assertSee('KODE-PROD-999');
        $response->assertSee('100 PCS');
        $response->assertSee('CS VALVES ASME B16.34 2"');
        $response->assertSee('CUST-VALVE-A');
        $response->assertSee('Q235');
        $response->assertSee('2"');
        $response->assertSee('PC-PRINT-TEST-12');
        $response->assertSee('Harap dirangkai secara hati-hati.');

        // Assert shop-floor ticket layout elements and operator feedback areas
        $response->assertSee('LOST WAX ASSEMBLY - TRACEABILITY PICKING TICKET');
        $response->assertSee('HASIL AKTUAL RANGKAI');
        $response->assertSee('Qty Diambil:');
        $response->assertSee('Qty Good:');
        $response->assertSee('Qty Defect:');
        $response->assertSee('Tanggal:');
        $response->assertSee('Jam:');
        $response->assertSee('Mulai:');
        $response->assertSee('Selesai:');
        $response->assertSee('OPERATOR RANGKAI:');
        $response->assertSee('SUPERVISOR RANGKAI:');
        $response->assertSee('PPIC / ADMIN:');

        // Assert CSS layout: A4 portrait media with A5 landscape form (210mm x 148mm)
        $html = $response->getContent();
        $this->assertStringContainsString('size: A4 portrait;', $html);
        $this->assertStringNotContainsString('size: A5 landscape;', $html);
        $this->assertStringContainsString('width: 210mm;', $html);
        $this->assertStringContainsString('height: 148mm;', $html);
        $this->assertStringContainsString('cut-guide', $html);
        $this->assertStringNotContainsString('CUT / POTONG', $html);

        // Assert that CONTEXT BATCH PRINT is removed
        $this->assertStringNotContainsString('CONTEXT BATCH PRINT', $html);
        $this->assertStringNotContainsString('Total Hasil Cetak Good', $html);
        $this->assertStringNotContainsString('Sisa Tersedia Rangkai', $html);

        // Assert that REFERENSI GAMBAR is present
        $this->assertStringContainsString('REFERENSI GAMBAR', $html);
        $this->assertStringContainsString('TAMPAK DEPAN', $html);
        $this->assertStringContainsString('TAMPAK SAMPING', $html);

        // Assert web controls are hidden on print
        $this->assertStringContainsString('no-print', $html);
        $this->assertStringNotContainsString('truncate', $html);
    }
}
