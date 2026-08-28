<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWaxQualityService;
use App\Services\PrintExecutionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class LostWaxDefectAndRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected LostWaxQualityService $qualityService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'QC Admin']);
        $this->qualityService = app(LostWaxQualityService::class);
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
            'created_by' => $this->user->id,
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
            'recorded_by' => $this->user->id,
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
     * TEST 1: Print defect is strictly excluded from print good (no double count).
     */
    public function test_print_defect_is_strictly_excluded_from_print_good(): void
    {
        $plan = $this->createPlan();
        $this->createPrintOrderWithExecution($plan, 1280, 20);

        $breakdown = $this->qualityService->getProductionPlanQuantityBreakdown($plan);

        $this->assertSame(1280, $breakdown['q_print_good']);
        $this->assertSame(20, $breakdown['q_print_defect']);
        $this->assertSame(1280, $breakdown['q_usable']);
    }

    /**
     * TEST 2: Single tree defect reduces usable quantity.
     */
    public function test_single_tree_defect_reduces_usable(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan, 1280, 20);
        $line = $order->lines->first();

        $tree = $this->createTreeForLine($line, 32, 'layer_1');

        $this->qualityService->recordDefect(
            tree: $tree,
            stage: 'layer_1',
            defectQty: 2,
            defectReason: 'retak_lapisan',
            notes: 'Retak halus di sambungan gate',
            userId: $this->user->id
        );

        $tree->refresh();
        $this->assertSame(2, $tree->total_defect_quantity);
        $this->assertSame(30, $tree->usable_quantity);
        $this->assertSame(32, $tree->quantity); // Original gross quantity is never mutated
    }

    /**
     * TEST 3: Multiple stage defects accumulate on a single tree.
     */
    public function test_multiple_stage_defects_accumulate(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan, 1280, 20);
        $line = $order->lines->first();

        $tree = $this->createTreeForLine($line, 32, 'layer_3');

        $this->qualityService->recordDefect($tree, 'layer_1', 2, 'retak_lapisan');
        $this->qualityService->recordDefect($tree, 'layer_2', 3, 'lapisan_rontok');
        $this->qualityService->recordDefect($tree, 'layer_3', 1, 'lapisan_tipis');

        $tree->refresh();
        $this->assertSame(6, $tree->total_defect_quantity);
        $this->assertSame(26, $tree->usable_quantity);
        $this->assertCount(3, $tree->defects);
    }

    /**
     * TEST 4: Defect exceeding tree physical quantity is rejected.
     */
    public function test_defect_exceeding_tree_quantity_is_rejected(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan, 1280, 20);
        $line = $order->lines->first();

        $tree = $this->createTreeForLine($line, 32, 'layer_2');
        $this->qualityService->recordDefect($tree, 'layer_1', 5, 'retak_lapisan');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Kuantitas defect baru (30 pcs) melebihi sisa fisik pohon yang tersedia (27 pcs dari total 32 pcs).');

        // Remaining physical is 27, attempting to log 30 must fail
        $this->qualityService->recordDefect($tree, 'layer_2', 30, 'lapisan_rontok');
    }

    /**
     * TEST 5: Fully defective tree cannot accept any more defects.
     */
    public function test_fully_defective_tree_cannot_accept_more_defect(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan, 1280, 20);
        $line = $order->lines->first();

        $tree = $this->createTreeForLine($line, 32, 'oven');
        $this->qualityService->recordDefect($tree, 'oven', 32, 'oven_pecah');

        $tree->refresh();
        $this->assertSame(32, $tree->total_defect_quantity);
        $this->assertSame(0, $tree->usable_quantity);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Kuantitas defect baru (1 pcs) melebihi sisa fisik pohon yang tersedia (0 pcs dari total 32 pcs).');

        $this->qualityService->recordDefect($tree, 'oven', 1, 'oven_pecah');
    }

    /**
     * TEST 6: Reject defect with negative or zero quantity.
     */
    public function test_reject_defect_with_invalid_quantity(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan, 1280, 20);
        $line = $order->lines->first();
        $tree = $this->createTreeForLine($line, 32);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Kuantitas defect harus lebih besar dari 0.');

        $this->qualityService->recordDefect($tree, 'layer_1', 0, 'retak_lapisan');
    }

    /**
     * TEST 7: Scan events remain completely intact when defect is logged.
     */
    public function test_scan_events_unaffected_when_defect_is_logged(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan, 1280, 20);
        $line = $order->lines->first();
        $tree = $this->createTreeForLine($line, 32, 'layer_2');

        $scanEvent = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'layer_1',
            'scanned_at' => now()->subHours(4),
            'operator_id' => $this->user->id,
            'result' => 'success',
            'aging_minutes' => 240,
            'aging_status' => 'normal',
        ]);

        $this->qualityService->recordDefect($tree, 'layer_1', 2, 'retak_lapisan');

        $this->assertDatabaseHas('lost_wax_scan_events', [
            'id' => $scanEvent->id,
            'tree_id' => $tree->id,
            'result' => 'success',
        ]);
        $this->assertSame(1, LostWaxScanEvent::where('tree_id', $tree->id)->count());
    }

    /**
     * TEST 8: Late defect entry records the actual occurred stage, not current stage.
     */
    public function test_late_defect_records_correct_stage(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan, 1280, 20);
        $line = $order->lines->first();

        // Tree is physically at layer_4
        $tree = $this->createTreeForLine($line, 32, 'layer_4');

        $defect = $this->qualityService->recordDefect(
            tree: $tree,
            stage: 'layer_2',
            defectQty: 3,
            defectReason: 'lapisan_rontok',
            notes: 'Terlihat rontok sejak pengeringan layer 2'
        );

        $this->assertSame('layer_2', $defect->stage);
        $this->assertSame('layer_4', $tree->current_stage);
    }

    /**
     * TEST 9: Occurred_at timestamp persists correctly and defaults to now if null.
     */
    public function test_occurred_at_nullable_default_behavior(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan, 1280, 20);
        $line = $order->lines->first();
        $tree = $this->createTreeForLine($line, 32, 'layer_1');

        $customTime = Carbon::create(2026, 8, 28, 8, 30, 0);

        $defect1 = $this->qualityService->recordDefect(
            tree: $tree,
            stage: 'layer_1',
            defectQty: 1,
            defectReason: 'retak_lapisan',
            occurredAt: $customTime
        );

        $defect2 = $this->qualityService->recordDefect(
            tree: $tree,
            stage: 'layer_1',
            defectQty: 1,
            defectReason: 'retak_lapisan',
            occurredAt: null
        );

        $this->assertEquals($customTime->format('Y-m-d H:i:s'), $defect1->occurred_at->format('Y-m-d H:i:s'));
        $this->assertNotNull($defect2->occurred_at);
    }

    /**
     * TEST 10: Legacy tree without defect records returns full usable quantity.
     */
    public function test_legacy_tree_without_defect_has_full_usable(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan, 1280, 20);
        $line = $order->lines->first();

        $legacyTree = $this->createTreeForLine($line, 32);

        $this->assertSame(0, $legacyTree->total_defect_quantity);
        $this->assertSame(32, $legacyTree->usable_quantity);
        $this->assertSame(32, $this->qualityService->calculateTreeUsableQuantity($legacyTree));
    }

    /**
     * TEST 11: Multiple trees correctly aggregate for Production Plan.
     */
    public function test_multiple_trees_aggregate_correctly_for_production_plan(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan, 1280, 20);
        $line = $order->lines->first();

        $tree1 = $this->createTreeForLine($line, 32, 'layer_2');
        $tree2 = $this->createTreeForLine($line, 32, 'layer_5');
        $tree3 = $this->createTreeForLine($line, 32, 'oven');

        $this->qualityService->recordDefect($tree1, 'layer_1', 2, 'retak_lapisan');
        $this->qualityService->recordDefect($tree2, 'layer_3', 3, 'lapisan_rontok');
        $this->qualityService->recordDefect($tree3, 'oven', 5, 'oven_pecah');

        $breakdown = $this->qualityService->getProductionPlanQuantityBreakdown($plan);

        $this->assertSame(1280, $breakdown['q_print_good']);
        $this->assertSame(20, $breakdown['q_print_defect']);
        $this->assertSame(96, $breakdown['q_active_trees_gross']);
        $this->assertSame(10, $breakdown['q_tree_defect']); // 2 + 3 + 5
        $this->assertSame(1270, $breakdown['q_usable']); // 1280 - 10
        $this->assertSame(27, $breakdown['q_final_usable']); // 32 - 5 in oven
        $this->assertSame(59, $breakdown['q_wip_net']); // (32-2) + (32-3) in layer 2 & 5
    }

    /**
     * TEST 12: Stage breakdown categorizes assembly, layer, and oven defects.
     */
    public function test_multi_stage_tree_defect_breakdown(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan, 1280, 20);
        $line = $order->lines->first();

        $tree = $this->createTreeForLine($line, 32, 'oven');

        $this->qualityService->recordDefect($tree, 'assembly', 1, 'pola_patah');
        $this->qualityService->recordDefect($tree, 'layer_1', 2, 'retak_lapisan');
        $this->qualityService->recordDefect($tree, 'layer_7', 1, 'lapisan_tipis');
        $this->qualityService->recordDefect($tree, 'oven', 4, 'oven_pecah');

        $breakdown = $this->qualityService->getProductionPlanQuantityBreakdown($plan);

        $this->assertSame(1, $breakdown['q_assembly_defect']);
        $this->assertSame(3, $breakdown['q_layer_defect']); // layer_1 (2) + layer_7 (1)
        $this->assertSame(4, $breakdown['q_oven_defect']);
        $this->assertSame(8, $breakdown['q_tree_defect']);
        $this->assertSame(1272, $breakdown['q_usable']); // 1280 - 8
    }

    /**
     * TEST 13: Tree usable quantity is clamped at min 0.
     */
    public function test_tree_usable_can_never_become_negative(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan, 1280, 20);
        $line = $order->lines->first();

        $tree = $this->createTreeForLine($line, 32);
        $this->qualityService->recordDefect($tree, 'layer_1', 32, 'retak_lapisan');

        $tree->refresh();
        $this->assertSame(0, $tree->usable_quantity);
    }

    /**
     * TEST 14: Canonical calculation matches the specification exactly (1280 - 40 = 1240).
     */
    public function test_print_good_and_tree_defect_canonical_calculation_exact(): void
    {
        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithExecution($plan, 1280, 20); // Output = 1300, defect = 20, good = 1280
        $line = $order->lines->first();

        $tree = $this->createTreeForLine($line, 100, 'oven');

        $this->qualityService->recordDefect($tree, 'assembly', 10, 'pola_patah');
        $this->qualityService->recordDefect($tree, 'layer_1', 10, 'retak_lapisan');
        $this->qualityService->recordDefect($tree, 'layer_2', 5, 'lapisan_rontok');
        $this->qualityService->recordDefect($tree, 'layer_7', 5, 'lapisan_tipis');
        $this->qualityService->recordDefect($tree, 'oven', 10, 'oven_pecah');

        $breakdown = $this->qualityService->getProductionPlanQuantityBreakdown($plan);

        $this->assertSame(1280, $breakdown['q_print_good']);
        $this->assertSame(40, $breakdown['q_tree_defect']);
        $this->assertSame(1240, $breakdown['q_usable']);
        $this->assertSame('NORMAL', $breakdown['status']); // 1240 >= planned 1200
    }

    /**
     * TEST 15: Deterministic status boundaries (NORMAL / WARNING / CRITICAL).
     */
    public function test_po_plan_status_boundaries(): void
    {
        $plan = $this->createPlan(['po_quantity' => 1000, 'qty_planned' => 1200]);

        $this->assertSame('NORMAL', $plan->evaluateProductionStatus(1300));
        $this->assertSame('NORMAL', $plan->evaluateProductionStatus(1200));
        $this->assertSame('WARNING', $plan->evaluateProductionStatus(1150));
        $this->assertSame('WARNING', $plan->evaluateProductionStatus(1000));
        $this->assertSame('CRITICAL', $plan->evaluateProductionStatus(999));
        $this->assertSame('CRITICAL', $plan->evaluateProductionStatus(0));
    }

    /**
     * TEST 16: Null PO quantity fallback behavior.
     */
    public function test_null_po_fallback(): void
    {
        $legacyPlan = $this->createPlan(['po_quantity' => null, 'qty_planned' => 1200]);

        $this->assertSame('NORMAL', $legacyPlan->evaluateProductionStatus(1200));
        $this->assertSame('NORMAL', $legacyPlan->evaluateProductionStatus(1300));
        $this->assertSame('WARNING', $legacyPlan->evaluateProductionStatus(1199));
        $this->assertSame('WARNING', $legacyPlan->evaluateProductionStatus(500));
    }

    /**
     * TEST 17: Production plan closure fields persist correctly.
     */
    public function test_production_plan_closure_fields_persist_correctly(): void
    {
        $plan = $this->createPlan();
        $closingUser = User::factory()->create(['name' => 'PPIC Manager']);

        $plan->update([
            'is_closed' => true,
            'closure_reason' => 'Customer menerima partial quantity 1150 pcs.',
            'closed_by' => $closingUser->id,
            'closed_at' => now(),
        ]);

        $plan->refresh();
        $this->assertTrue($plan->is_closed);
        $this->assertSame('Customer menerima partial quantity 1150 pcs.', $plan->closure_reason);
        $this->assertSame($closingUser->id, $plan->closed_by);
        $this->assertSame('PPIC Manager', $plan->closedBy->name);
        $this->assertNotNull($plan->closed_at);
    }
}
