<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\LostWaxTreeAllocation;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\PrintExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TreeDefectUxTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'name' => 'QC Admin',
            'email' => 'qcadmin@peroniks.com',
        ]);
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'code' => '268AB001',
            'customer' => 'PT PERONI INTI',
            'item_code' => '4.101105K.A0020',
            'item_name' => 'SS304 SQUARE DN 40',
            'aisi' => '304',
            'size' => 'DN 40',
            'weight' => 1.25,
            'po_number' => 'PO-2026-001',
            'po_quantity' => 1000,
            'qty_planned' => 1200,
            'qty_remaining' => 1200,
            'line_number' => 1,
            'status' => 'planning',
            'is_closed' => false,
        ], $attributes));
    }

    protected function createPrintOrderWithExecution(ProductionPlan $plan, int $good = 1280, int $defect = 20): LostWaxPrintOrder
    {
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0001',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => $good + $defect,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        $printService = app(PrintExecutionService::class);
        $printService->record($line, [
            'qty_good' => $good,
            'qty_defect' => $defect,
            'execution_date' => '2026-08-28',
            'status' => 'FINALIZED',
            'recorded_by' => $this->adminUser->id,
        ]);

        return $order;
    }

    protected function createTreeForLine($line, int $quantity = 32, ?string $currentStage = null): LostWaxTree
    {
        static $seq = 0;
        $seq++;

        return LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => '1280828'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'tree_number' => $seq,
            'quantity' => $quantity,
            'status' => 'in_coating',
            'current_stage' => $currentStage,
            'production_date' => '2026-08-28',
            'family_code' => '3',
            'daily_sequence' => $seq,
        ]);
    }

    /**
     * TEST 1: Admin can open Tree Detail and view the Quality & Defect section.
     */
    public function test_admin_can_open_tree_detail_and_view_defect_section(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan);
        $line = $order->lines->first();
        $tree = $this->createTreeForLine($line, 32, 'layer_1');

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.trees.show', $tree));

        $response->assertOk();
        $response->assertSee('LOG KUALITAS & DEFECT', false);
        $response->assertSee('Gross Qty');
        $response->assertSee('Sisa Usable');
        $response->assertSee('Catat Defect');
    }

    /**
     * TEST 2: Authorized user can submit defect via HTTP POST.
     */
    public function test_authorized_user_can_submit_defect(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan);
        $line = $order->lines->first();
        $tree = $this->createTreeForLine($line, 32, 'layer_1');

        $payload = [
            'stage' => 'layer_1',
            'defect_qty' => 2,
            'defect_reason' => 'retak_lapisan',
            'notes' => 'Retak pada gate bawah',
            'occurred_at' => '2026-08-28T09:30',
        ];

        $response = $this->actingAs($this->adminUser)
            ->from(route('lost-wax.trees.show', $tree))
            ->post(route('lost-wax.trees.defects.store', $tree), $payload);

        $response->assertRedirect(route('lost-wax.trees.show', $tree));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('lost_wax_tree_defects', [
            'lost_wax_tree_id' => $tree->id,
            'stage' => 'layer_1',
            'defect_qty' => 2,
            'defect_reason' => 'retak_lapisan',
            'notes' => 'Retak pada gate bawah',
            'recorded_by' => $this->adminUser->id,
        ]);
    }

    /**
     * TEST 3: Defect appears in Tree Detail view after submission.
     */
    public function test_defect_appears_in_tree_detail(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan);
        $line = $order->lines->first();
        $tree = $this->createTreeForLine($line, 32, 'layer_2');

        $this->actingAs($this->adminUser)->post(route('lost-wax.trees.defects.store', $tree), [
            'stage' => 'layer_2',
            'defect_qty' => 3,
            'defect_reason' => 'lapisan_rontok',
            'notes' => 'Slurry mengelupas',
        ]);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.trees.show', $tree));

        $response->assertOk();
        $response->assertSee('LAPISAN 2');
        $response->assertSee('3 pcs');
        $response->assertSee('Lapisan Rontok');
        $response->assertSee('Slurry mengelupas');
        $response->assertSee('QC Admin');
    }

    /**
     * TEST 4: Gross quantity remains unchanged when defect is recorded.
     */
    public function test_gross_quantity_remains_unchanged(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan);
        $line = $order->lines->first();
        $tree = $this->createTreeForLine($line, 32);

        $this->actingAs($this->adminUser)->post(route('lost-wax.trees.defects.store', $tree), [
            'stage' => 'assembly',
            'defect_qty' => 4,
            'defect_reason' => 'pola_patah',
        ]);

        $tree->refresh();
        $this->assertSame(32, $tree->quantity); // Gross physical count must remain 32
        $this->assertSame(4, $tree->total_defect_quantity);
        $this->assertSame(28, $tree->usable_quantity);
    }

    /**
     * TEST 5: Multiple defects accumulate across different stages.
     */
    public function test_multiple_defects_accumulate_across_stages(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan);
        $line = $order->lines->first();
        $tree = $this->createTreeForLine($line, 32, 'oven');

        $this->actingAs($this->adminUser)->post(route('lost-wax.trees.defects.store', $tree), [
            'stage' => 'assembly',
            'defect_qty' => 2,
            'defect_reason' => 'pola_patah',
        ]);

        $this->actingAs($this->adminUser)->post(route('lost-wax.trees.defects.store', $tree), [
            'stage' => 'layer_1',
            'defect_qty' => 3,
            'defect_reason' => 'retak_lapisan',
        ]);

        $this->actingAs($this->adminUser)->post(route('lost-wax.trees.defects.store', $tree), [
            'stage' => 'oven',
            'defect_qty' => 5,
            'defect_reason' => 'oven_pecah',
        ]);

        $tree->refresh();
        $this->assertSame(10, $tree->total_defect_quantity);
        $this->assertSame(22, $tree->usable_quantity);
    }

    /**
     * TEST 6: Invalid defect quantity (<= 0) is rejected.
     */
    public function test_invalid_defect_quantity_is_rejected(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan);
        $line = $order->lines->first();
        $tree = $this->createTreeForLine($line, 32);

        $response = $this->actingAs($this->adminUser)->post(route('lost-wax.trees.defects.store', $tree), [
            'stage' => 'layer_1',
            'defect_qty' => 0,
            'defect_reason' => 'retak_lapisan',
        ]);

        $response->assertSessionHasErrors('defect_qty');
        $this->assertSame(0, $tree->defects()->count());
    }

    /**
     * TEST 7: Defect quantity exceeding remaining physical quantity is rejected.
     */
    public function test_defect_exceeding_remaining_is_rejected(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan);
        $line = $order->lines->first();
        $tree = $this->createTreeForLine($line, 32);

        // Record 30 pcs first
        $this->actingAs($this->adminUser)->post(route('lost-wax.trees.defects.store', $tree), [
            'stage' => 'layer_1',
            'defect_qty' => 30,
            'defect_reason' => 'retak_lapisan',
        ]);

        // Remaining is 2 pcs, attempt to record 5 pcs
        $response = $this->actingAs($this->adminUser)
            ->from(route('lost-wax.trees.show', $tree))
            ->post(route('lost-wax.trees.defects.store', $tree), [
                'stage' => 'layer_2',
                'defect_qty' => 5,
                'defect_reason' => 'lapisan_rontok',
            ]);

        $response->assertRedirect(route('lost-wax.trees.show', $tree));
        $response->assertSessionHas('error');

        $tree->refresh();
        $this->assertSame(30, $tree->total_defect_quantity);
        $this->assertSame(2, $tree->usable_quantity);
    }

    /**
     * TEST 8: Cancelled tree cannot receive defect.
     */
    public function test_cancelled_tree_cannot_receive_defect(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan);
        $line = $order->lines->first();
        $tree = $this->createTreeForLine($line, 32);
        $tree->update(['status' => 'cancelled']);

        $response = $this->actingAs($this->adminUser)
            ->from(route('lost-wax.trees.show', $tree))
            ->post(route('lost-wax.trees.defects.store', $tree), [
                'stage' => 'layer_1',
                'defect_qty' => 2,
                'defect_reason' => 'retak_lapisan',
            ]);

        $response->assertRedirect(route('lost-wax.trees.show', $tree));
        $response->assertSessionHas('error');
        $this->assertSame(0, $tree->defects()->count());
    }

    /**
     * TEST 9: Late defect entry preserves actual occurred stage.
     */
    public function test_late_defect_stage_preserved(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan);
        $line = $order->lines->first();
        $tree = $this->createTreeForLine($line, 32, 'layer_4');

        $this->actingAs($this->adminUser)->post(route('lost-wax.trees.defects.store', $tree), [
            'stage' => 'layer_2',
            'defect_qty' => 2,
            'defect_reason' => 'lapisan_rontok',
        ]);

        $this->assertDatabaseHas('lost_wax_tree_defects', [
            'lost_wax_tree_id' => $tree->id,
            'stage' => 'layer_2',
            'defect_qty' => 2,
        ]);
        $this->assertSame('layer_4', $tree->current_stage);
    }

    /**
     * TEST 10: Occurred_at timestamp is preserved.
     */
    public function test_occurred_at_preserved(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan);
        $line = $order->lines->first();
        $tree = $this->createTreeForLine($line, 32);

        $this->actingAs($this->adminUser)->post(route('lost-wax.trees.defects.store', $tree), [
            'stage' => 'layer_1',
            'defect_qty' => 1,
            'defect_reason' => 'retak_lapisan',
            'occurred_at' => '2026-08-27T16:45',
        ]);

        $defect = $tree->defects()->first();
        $this->assertNotNull($defect);
        $this->assertSame('2026-08-27 16:45:00', $defect->occurred_at->format('Y-m-d H:i:s'));
    }

    /**
     * TEST 11: Scan history remains unaffected by defect logging.
     */
    public function test_scan_history_unchanged(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan);
        $line = $order->lines->first();
        $tree = $this->createTreeForLine($line, 32, 'layer_2');

        $scan = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'layer_1',
            'scanned_at' => now()->subHours(5),
            'operator_id' => $this->adminUser->id,
            'result' => 'success',
            'aging_minutes' => 300,
            'aging_status' => 'normal',
        ]);

        $this->actingAs($this->adminUser)->post(route('lost-wax.trees.defects.store', $tree), [
            'stage' => 'layer_1',
            'defect_qty' => 2,
            'defect_reason' => 'retak_lapisan',
        ]);

        $this->assertDatabaseHas('lost_wax_scan_events', [
            'id' => $scan->id,
            'tree_id' => $tree->id,
            'result' => 'success',
        ]);
    }

    /**
     * TEST 12: Multi-line allocation material sources are rendered in tree view.
     */
    public function test_multi_line_allocation_sources_rendered_in_view(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan);
        $lineA = $order->lines->first();

        // Create second line
        $lineB = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 50,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        $tree = $this->createTreeForLine($lineA, 30);

        LostWaxTreeAllocation::create([
            'lost_wax_tree_id' => $tree->id,
            'lost_wax_print_order_line_id' => $lineA->id,
            'allocated_qty' => 4,
        ]);

        LostWaxTreeAllocation::create([
            'lost_wax_tree_id' => $tree->id,
            'lost_wax_print_order_line_id' => $lineB->id,
            'allocated_qty' => 26,
        ]);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.trees.show', $tree));

        $response->assertOk();
        $response->assertSee('Alokasi FIFO:');
        $response->assertSee('4 pcs');
        $response->assertSee('26 pcs');
    }
}
