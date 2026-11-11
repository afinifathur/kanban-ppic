<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxItemReference;
use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxRangkaiExecution;
use App\Models\LostWaxRangkaiWorkOrder;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\LostWaxTreeAllocation;
use App\Models\LostWaxTreeDefect;
use App\Models\LostWaxWorkOrder;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWax\ReadyForOvenService;
use App\Services\ScanVoidService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReadyForOvenPhase1Test extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected ReadyForOvenService $service;

    protected static int $seq = 100;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'access_execution']);
        Permission::firstOrCreate(['name' => 'access_planning']);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->syncPermissions(['access_execution', 'access_planning']);

        $this->user = User::factory()->create([
            'email' => 'admin_phase1@peroniks.com',
        ]);
        $this->user->assignRole('admin');

        $this->service = app(ReadyForOvenService::class);
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        $seq = self::$seq++;

        return ProductionPlan::create(array_merge([
            'code' => 'PLAN-'.$seq,
            'customer' => 'CUST-'.$seq,
            'item_code' => 'ITEM-'.$seq,
            'item_name' => 'Flange 2 Inch Test '.$seq,
            'aisi' => '304',
            'size' => '2"',
            'weight' => 2.50,
            'po_number' => 'PO-'.$seq,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'status' => 'planning',
        ], $attributes));
    }

    protected function createPrintOrderLine(ProductionPlan $plan, array $attributes = []): LostWaxPrintOrderLine
    {
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PO-ORD-'.uniqid(),
            'scheduled_date' => now()->toDateString(),
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        return LostWaxPrintOrderLine::create(array_merge([
            'lost_wax_print_order_id' => $order->id,
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'qty_ordered' => 50,
            'execution_status' => 'COMPLETED',
        ], $attributes));
    }

    protected function createTree(array $attributes = []): LostWaxTree
    {
        $currentSeq = self::$seq++;

        return LostWaxTree::create(array_merge([
            'barcode' => 'TREE-P1-'.uniqid().'-'.$currentSeq,
            'tree_number' => $currentSeq,
            'quantity' => 20,
            'status' => 'generated',
            'current_stage' => 'layer_6',
            'require_layer_7' => false,
            'production_date' => now()->toDateString(),
            'family_code' => '1',
            'daily_sequence' => $currentSeq,
        ], $attributes));
    }

    protected function createScanEvent(LostWaxTree $tree, string $stage, Carbon $scannedAt, string $result = 'success'): LostWaxScanEvent
    {
        return LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => $stage,
            'scanned_at' => $scannedAt,
            'operator_id' => $this->user->id,
            'result' => $result,
        ]);
    }

    // =========================================================================
    // 1. KUANTITAS PCS (Gross, Defect, Usable PCS, Multi-Alloc Prorata)
    // =========================================================================

    public function test_gross_defect_and_usable_pcs_displayed_correctly(): void
    {
        $plan = $this->createPlan(['code' => 'QTY-TEST-1']);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'quantity' => 50,
        ]);

        // Defect 5 pcs recorded during dipping
        LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $tree->id,
            'stage' => 'layer_3',
            'defect_qty' => 5,
            'defect_reason' => 'crack',
            'recorded_by' => $this->user->id,
        ]);

        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        $data = $this->service->getQueueData();
        $group = $data['items']->firstWhere('production_code', 'QTY-TEST-1');

        $this->assertNotNull($group);
        $this->assertEquals(50, $group['gross_pcs']);
        $this->assertEquals(5, $group['defect_pcs']);
        $this->assertEquals(45, $group['quantity_pcs']); // Usable = 50 - 5 = 45

        $this->assertEquals(50, $data['summary']['total_gross_pcs']);
        $this->assertEquals(5, $data['summary']['total_defect_pcs']);
        $this->assertEquals(45, $data['summary']['total_usable_pcs']);
    }

    public function test_usable_pcs_never_negative_when_defect_exceeds_gross(): void
    {
        $plan = $this->createPlan(['code' => 'DEFECT-EXCEED']);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'quantity' => 10,
        ]);

        // Defect is 15 (exceeds gross 10)
        LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $tree->id,
            'stage' => 'layer_2',
            'defect_qty' => 15,
            'defect_reason' => 'dropped',
            'recorded_by' => $this->user->id,
        ]);

        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        $data = $this->service->getQueueData();

        // 100% defect tree has usable_quantity = 0 and is excluded from the queue
        $this->assertEquals(0, $data['summary']['total_unique_trees']);
        $this->assertEquals(0, $data['summary']['total_usable_pcs']);
    }

    public function test_multi_allocation_does_not_double_count_and_remainder_is_accurate(): void
    {
        $planA = $this->createPlan(['code' => 'ALLOC-A', 'weight' => 1.0]);
        $lineA = $this->createPrintOrderLine($planA);

        $planB = $this->createPlan(['code' => 'ALLOC-B', 'weight' => 2.0]);
        $lineB = $this->createPrintOrderLine($planB);

        // Physical tree of 33 pcs: allocated 11 pcs to Line A, 22 pcs to Line B
        $tree = $this->createTree([
            'quantity' => 33,
            'current_stage' => 'layer_6',
        ]);

        LostWaxTreeAllocation::create([
            'lost_wax_tree_id' => $tree->id,
            'lost_wax_print_order_line_id' => $lineA->id,
            'allocated_qty' => 11,
        ]);
        LostWaxTreeAllocation::create([
            'lost_wax_tree_id' => $tree->id,
            'lost_wax_print_order_line_id' => $lineB->id,
            'allocated_qty' => 22,
        ]);

        // Defect 3 pcs on physical tree -> usable = 30 pcs
        LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $tree->id,
            'stage' => 'layer_4',
            'defect_qty' => 3,
            'defect_reason' => 'damage',
            'recorded_by' => $this->user->id,
        ]);

        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        $data = $this->service->getQueueData();

        // 1 physical tree, 2 allocation groups
        $this->assertEquals(1, $data['summary']['total_unique_trees']);
        $this->assertEquals(2, $data['summary']['total_rangkai']);
        $this->assertEquals(33, $data['summary']['total_gross_pcs']);
        $this->assertEquals(3, $data['summary']['total_defect_pcs']);
        $this->assertEquals(30, $data['summary']['total_usable_pcs']);

        $rowA = $data['items']->firstWhere('production_code', 'ALLOC-A');
        $rowB = $data['items']->firstWhere('production_code', 'ALLOC-B');

        $this->assertNotNull($rowA);
        $this->assertNotNull($rowB);
        // Sum of both rows must equal exactly 30 usable pcs
        $this->assertEquals(30, $rowA['quantity_pcs'] + $rowB['quantity_pcs']);
        $this->assertEquals(33, $rowA['gross_pcs'] + $rowB['gross_pcs']);
    }

    // =========================================================================
    // 2. BERAT KG (Plan, Fallback, Null/Zero Guard, Incomplete Indicator)
    // =========================================================================

    public function test_weight_calculated_from_production_plan(): void
    {
        $plan = $this->createPlan([
            'code' => 'KG-PLAN',
            'weight' => 2.50, // 2.5 kg/pc
        ]);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'quantity' => 20,
        ]);
        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        $data = $this->service->getQueueData();
        $group = $data['items']->firstWhere('production_code', 'KG-PLAN');

        $this->assertNotNull($group);
        $this->assertTrue($group['has_weight']);
        $this->assertEquals(2.5, $group['unit_weight']);
        $this->assertEquals(50.0, $group['weight_kg']); // 20 pcs * 2.5 kg = 50 kg
        $this->assertEquals(50.0, $data['summary']['total_weight_kg']);
        $this->assertFalse($data['summary']['has_incomplete_weights']);
    }

    public function test_weight_fallback_to_lost_wax_item_reference_snapshot(): void
    {
        // Legacy item reference with snapshot weight 3.25 kg
        $ref = LostWaxItemReference::create([
            'master_source' => 'database',
            'master_item_key' => 'ITEM-KEY-01',
            'item_code_snapshot' => 'LEGACY-01',
            'item_name_snapshot' => 'Legacy Elbow',
            'unit_weight_snapshot' => 3.250,
            'status_snapshot' => 'active',
        ]);

        $legacyWo = LostWaxWorkOrder::create([
            'item_reference_id' => $ref->id,
            'et_code' => 'LWO-2026-01',
            'po_number' => 'PO-LEGACY',
            'customer_name' => 'Legacy Customer',
            'po_quantity' => 50,
            'net_requirement_quantity' => 50,
            'status' => 'OPEN',
        ]);

        $tree = $this->createTree([
            'work_order_id' => $legacyWo->id,
            'lost_wax_print_order_line_id' => null,
            'quantity' => 10,
        ]);
        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        $data = $this->service->getQueueData();
        $group = $data['items']->first();

        $this->assertNotNull($group);
        $this->assertTrue($group['has_weight']);
        $this->assertEquals(3.25, $group['unit_weight']);
        $this->assertEquals(32.5, $group['weight_kg']); // 10 * 3.25 = 32.5 kg
    }

    public function test_null_and_zero_weight_handled_gracefully_without_error(): void
    {
        $planNull = $this->createPlan(['code' => 'NULL-WEIGHT', 'weight' => null]);
        $lineNull = $this->createPrintOrderLine($planNull);
        $treeNull = $this->createTree([
            'lost_wax_print_order_line_id' => $lineNull->id,
            'quantity' => 20,
        ]);
        $this->createScanEvent($treeNull, 'layer_6', now()->subHours(2));

        $planZero = $this->createPlan(['code' => 'ZERO-WEIGHT', 'weight' => 0.00]);
        $lineZero = $this->createPrintOrderLine($planZero);
        $treeZero = $this->createTree([
            'lost_wax_print_order_line_id' => $lineZero->id,
            'quantity' => 15,
        ]);
        $this->createScanEvent($treeZero, 'layer_6', now()->subHours(1));

        $data = $this->service->getQueueData();

        $rowNull = $data['items']->firstWhere('production_code', 'NULL-WEIGHT');
        $rowZero = $data['items']->firstWhere('production_code', 'ZERO-WEIGHT');

        $this->assertNotNull($rowNull);
        $this->assertNotNull($rowZero);

        $this->assertFalse($rowNull['has_weight']);
        $this->assertNull($rowNull['weight_kg']);
        $this->assertEquals('-', $rowNull['weight_kg_formatted']);

        $this->assertFalse($rowZero['has_weight']);
        $this->assertNull($rowZero['weight_kg']);
        $this->assertEquals('-', $rowZero['weight_kg_formatted']);

        // PCS calculated normally: 20 + 15 = 35 pcs
        $this->assertEquals(35, $data['summary']['total_usable_pcs']);
        // Total weight is 0 because no valid weights exist
        $this->assertEquals(0.0, $data['summary']['total_weight_kg']);
        $this->assertTrue($data['summary']['has_incomplete_weights']);
        $this->assertEquals(2, $data['summary']['groups_without_weight_count']);
    }

    public function test_groups_without_weight_excluded_from_total_kg_and_flags_incomplete_indicator(): void
    {
        // 1 item with weight, 1 item without weight
        $planA = $this->createPlan(['code' => 'HAS-WEIGHT', 'weight' => 5.0]);
        $lineA = $this->createPrintOrderLine($planA);
        $treeA = $this->createTree([
            'lost_wax_print_order_line_id' => $lineA->id,
            'quantity' => 10,
        ]);
        $this->createScanEvent($treeA, 'layer_6', now()->subHours(2));

        $planB = $this->createPlan(['code' => 'NO-WEIGHT', 'weight' => null]);
        $lineB = $this->createPrintOrderLine($planB);
        $treeB = $this->createTree([
            'lost_wax_print_order_line_id' => $lineB->id,
            'quantity' => 10,
        ]);
        $this->createScanEvent($treeB, 'layer_6', now()->subHours(1));

        $data = $this->service->getQueueData();

        // Total weight must only be 10 * 5.0 = 50.0 kg (item B is excluded)
        $this->assertEquals(50.0, $data['summary']['total_weight_kg']);
        $this->assertEquals(1, $data['summary']['groups_with_weight_count']);
        $this->assertEquals(1, $data['summary']['groups_without_weight_count']);
        $this->assertTrue($data['summary']['has_incomplete_weights']);
    }

    public function test_multi_allocation_uses_respective_item_weights(): void
    {
        $planX = $this->createPlan(['code' => 'ITEM-X', 'weight' => 2.0]);
        $lineX = $this->createPrintOrderLine($planX);

        $planY = $this->createPlan(['code' => 'ITEM-Y', 'weight' => 4.0]);
        $lineY = $this->createPrintOrderLine($planY);

        $tree = $this->createTree(['quantity' => 20]);
        LostWaxTreeAllocation::create([
            'lost_wax_tree_id' => $tree->id,
            'lost_wax_print_order_line_id' => $lineX->id,
            'allocated_qty' => 10,
        ]);
        LostWaxTreeAllocation::create([
            'lost_wax_tree_id' => $tree->id,
            'lost_wax_print_order_line_id' => $lineY->id,
            'allocated_qty' => 10,
        ]);
        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        $data = $this->service->getQueueData();

        $rowX = $data['items']->firstWhere('production_code', 'ITEM-X');
        $rowY = $data['items']->firstWhere('production_code', 'ITEM-Y');

        $this->assertEquals(20.0, $rowX['weight_kg']); // 10 * 2.0 = 20 kg
        $this->assertEquals(40.0, $rowY['weight_kg']); // 10 * 4.0 = 40 kg
        $this->assertEquals(60.0, $data['summary']['total_weight_kg']);
    }

    // =========================================================================
    // 3. COVERAGE DAYS & TARGET SIMULATION
    // =========================================================================

    public function test_coverage_days_calculated_correctly_with_default_target(): void
    {
        // 6000 kg total WIP, default target 2000 kg -> 3.0 days
        $plan = $this->createPlan(['code' => 'COV-DEFAULT', 'weight' => 100.0]);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'quantity' => 60, // 60 * 100 kg = 6000 kg
        ]);
        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        $data = $this->service->getQueueData();

        $this->assertEquals(6000.0, $data['summary']['total_weight_kg']);
        $this->assertEquals(2000.0, $data['summary']['daily_target_kg']);
        $this->assertEquals(3.0, $data['summary']['coverage_days']);
    }

    public function test_coverage_days_updates_when_daily_target_is_customized(): void
    {
        // 6000 kg total WIP, target 3000 kg -> 2.0 days
        $plan = $this->createPlan(['code' => 'COV-CUSTOM', 'weight' => 100.0]);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'quantity' => 60,
        ]);
        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        $data = $this->service->getQueueData(null, null, 3000);

        $this->assertEquals(3000.0, $data['summary']['daily_target_kg']);
        $this->assertEquals(2.0, $data['summary']['coverage_days']);
    }

    public function test_zero_or_negative_daily_target_rejected_and_falls_back_to_default(): void
    {
        $plan = $this->createPlan(['code' => 'COV-FALLBACK', 'weight' => 100.0]);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'quantity' => 40, // 4000 kg
        ]);
        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        // Test with 0, negative, and invalid string
        $dataZero = $this->service->getQueueData(null, null, 0);
        $this->assertEquals(2000.0, $dataZero['summary']['daily_target_kg']);

        $dataNeg = $this->service->getQueueData(null, null, -500);
        $this->assertEquals(2000.0, $dataNeg['summary']['daily_target_kg']);

        $dataInvalid = $this->service->getQueueData(null, null, 'invalid');
        $this->assertEquals(2000.0, $dataInvalid['summary']['daily_target_kg']);
    }

    // =========================================================================
    // 4. COATING COMPLETION (Full Batch, Partial Batch, Oven Preservation, Identity)
    // =========================================================================

    public function test_work_order_with_all_trees_coated_yields_full_batch(): void
    {
        $plan = $this->createPlan(['code' => 'FULL-WO']);
        $line = $this->createPrintOrderLine($plan);

        $rwo = LostWaxRangkaiWorkOrder::create([
            'lost_wax_print_order_line_id' => $line->id,
            'rangkai_order_number' => 'RWO-FULL-01',
            'qty_trees_planned' => 5,
            'tree_capacity' => 1,
            'require_layer_7' => false,
            'status' => 'OPEN',
            'created_by' => $this->user->id,
        ]);

        $exec = LostWaxRangkaiExecution::create([
            'rangkai_work_order_id' => $rwo->id,
            'execution_date' => now()->toDateString(),
            'trees_created' => 5,
            'family_code' => '1',
            'recorded_by' => $this->user->id,
            'recorded_at' => now(),
            'status' => 'COMPLETED',
        ]);

        // 5 trees all completed layer 6
        for ($i = 1; $i <= 5; $i++) {
            $tree = $this->createTree([
                'lost_wax_print_order_line_id' => $line->id,
                'rangkai_execution_id' => $exec->id,
                'current_stage' => 'layer_6',
            ]);
            $this->createScanEvent($tree, 'layer_6', now()->subHours(2));
        }

        $data = $this->service->getQueueData();
        $group = $data['items']->firstWhere('production_code', 'FULL-WO');

        $this->assertNotNull($group);
        $this->assertTrue($group['is_full_batch']);
        $this->assertEquals('FULL BATCH', $group['coating_status']);
        $this->assertEquals('5 / 5 TREE COATED', $group['coating_progress_text']);
        $this->assertEquals(100, $group['coating_percentage']);
    }

    public function test_work_order_with_some_trees_still_dipping_yields_partial_batch(): void
    {
        $plan = $this->createPlan(['code' => 'PARTIAL-WO']);
        $line = $this->createPrintOrderLine($plan);

        $rwo = LostWaxRangkaiWorkOrder::create([
            'lost_wax_print_order_line_id' => $line->id,
            'rangkai_order_number' => 'RWO-PARTIAL-01',
            'qty_trees_planned' => 10,
            'tree_capacity' => 1,
            'require_layer_7' => false,
            'status' => 'OPEN',
            'created_by' => $this->user->id,
        ]);

        $exec = LostWaxRangkaiExecution::create([
            'rangkai_work_order_id' => $rwo->id,
            'execution_date' => now()->toDateString(),
            'trees_created' => 10,
            'family_code' => '1',
            'recorded_by' => $this->user->id,
            'recorded_at' => now(),
            'status' => 'COMPLETED',
        ]);

        // 8 trees completed layer 6 (ready for oven)
        for ($i = 1; $i <= 8; $i++) {
            $tree = $this->createTree([
                'lost_wax_print_order_line_id' => $line->id,
                'rangkai_execution_id' => $exec->id,
                'current_stage' => 'layer_6',
            ]);
            $this->createScanEvent($tree, 'layer_6', now()->subHours(2));
        }

        // 2 trees still in layer 3 (still dipping)
        for ($i = 9; $i <= 10; $i++) {
            $this->createTree([
                'lost_wax_print_order_line_id' => $line->id,
                'rangkai_execution_id' => $exec->id,
                'current_stage' => 'layer_3',
            ]);
        }

        $data = $this->service->getQueueData();
        $group = $data['items']->firstWhere('production_code', 'PARTIAL-WO');

        $this->assertNotNull($group);
        $this->assertFalse($group['is_full_batch']);
        $this->assertEquals('PARTIAL BATCH', $group['coating_status']);
        $this->assertEquals('8 / 10 TREE COATED', $group['coating_progress_text']);
        $this->assertEquals(80, $group['coating_percentage']);
        // Only 8 trees are ready for oven
        $this->assertEquals(8, $group['tree_count']);
    }

    public function test_trees_in_oven_remain_counted_as_coated_and_progress_does_not_drop(): void
    {
        // 10 trees total: 3 already scanned into oven, 7 waiting in Ready for Oven
        $plan = $this->createPlan(['code' => 'OVEN-PERSIST']);
        $line = $this->createPrintOrderLine($plan);

        $rwo = LostWaxRangkaiWorkOrder::create([
            'lost_wax_print_order_line_id' => $line->id,
            'rangkai_order_number' => 'RWO-PERSIST-01',
            'qty_trees_planned' => 10,
            'tree_capacity' => 1,
            'require_layer_7' => false,
            'status' => 'OPEN',
            'created_by' => $this->user->id,
        ]);

        $exec = LostWaxRangkaiExecution::create([
            'rangkai_work_order_id' => $rwo->id,
            'execution_date' => now()->toDateString(),
            'trees_created' => 10,
            'family_code' => '1',
            'recorded_by' => $this->user->id,
            'recorded_at' => now(),
            'status' => 'COMPLETED',
        ]);

        // 3 trees in oven
        for ($i = 1; $i <= 3; $i++) {
            $treeOven = $this->createTree([
                'lost_wax_print_order_line_id' => $line->id,
                'rangkai_execution_id' => $exec->id,
                'current_stage' => 'oven',
            ]);
            $this->createScanEvent($treeOven, 'layer_6', now()->subHours(5));
            $this->createScanEvent($treeOven, 'oven', now()->subHours(1));
        }

        // 7 trees waiting in layer 6
        for ($i = 4; $i <= 10; $i++) {
            $treeWaiting = $this->createTree([
                'lost_wax_print_order_line_id' => $line->id,
                'rangkai_execution_id' => $exec->id,
                'current_stage' => 'layer_6',
            ]);
            $this->createScanEvent($treeWaiting, 'layer_6', now()->subHours(2));
        }

        $data = $this->service->getQueueData();
        $group = $data['items']->firstWhere('production_code', 'OVEN-PERSIST');

        $this->assertNotNull($group);
        // Queue shows only the 7 trees waiting
        $this->assertEquals(7, $group['tree_count']);
        // Coating completion is 10/10 FULL BATCH (oven trees DO NOT drop progress!)
        $this->assertTrue($group['is_full_batch']);
        $this->assertEquals('FULL BATCH', $group['coating_status']);
        $this->assertEquals('10 / 10 TREE COATED', $group['coating_progress_text']);
        $this->assertEquals(100, $group['coating_percentage']);
    }

    public function test_two_different_work_orders_do_not_mix_into_same_row(): void
    {
        $plan = $this->createPlan(['code' => 'SPLIT-WO']);
        $line = $this->createPrintOrderLine($plan);

        // WO #1
        $rwo1 = LostWaxRangkaiWorkOrder::create([
            'lost_wax_print_order_line_id' => $line->id,
            'rangkai_order_number' => 'RWO-BATCH-A',
            'qty_trees_planned' => 5,
            'tree_capacity' => 1,
            'require_layer_7' => false,
            'status' => 'OPEN',
            'created_by' => $this->user->id,
        ]);
        $exec1 = LostWaxRangkaiExecution::create([
            'rangkai_work_order_id' => $rwo1->id,
            'execution_date' => now()->toDateString(),
            'trees_created' => 5,
            'family_code' => '1',
            'recorded_by' => $this->user->id,
            'recorded_at' => now(),
            'status' => 'COMPLETED',
        ]);
        for ($i = 1; $i <= 5; $i++) {
            $tree = $this->createTree([
                'lost_wax_print_order_line_id' => $line->id,
                'rangkai_execution_id' => $exec1->id,
                'current_stage' => 'layer_6',
            ]);
            $this->createScanEvent($tree, 'layer_6', now()->subHours(3));
        }

        // WO #2
        $rwo2 = LostWaxRangkaiWorkOrder::create([
            'lost_wax_print_order_line_id' => $line->id,
            'rangkai_order_number' => 'RWO-BATCH-B',
            'qty_trees_planned' => 3,
            'tree_capacity' => 1,
            'require_layer_7' => false,
            'status' => 'OPEN',
            'created_by' => $this->user->id,
        ]);
        $exec2 = LostWaxRangkaiExecution::create([
            'rangkai_work_order_id' => $rwo2->id,
            'execution_date' => now()->toDateString(),
            'trees_created' => 3,
            'family_code' => '1',
            'recorded_by' => $this->user->id,
            'recorded_at' => now(),
            'status' => 'COMPLETED',
        ]);
        for ($i = 1; $i <= 3; $i++) {
            $tree = $this->createTree([
                'lost_wax_print_order_line_id' => $line->id,
                'rangkai_execution_id' => $exec2->id,
                'current_stage' => 'layer_6',
            ]);
            $this->createScanEvent($tree, 'layer_6', now()->subHours(2));
        }

        $data = $this->service->getQueueData();

        // Phase 1.2: Two different Work Orders for the SAME code produce ONE master row
        $woRows = $data['items']->where('production_code', 'SPLIT-WO');
        $this->assertEquals(1, $woRows->count());

        $master = $woRows->first();
        $this->assertEquals(8, $master['tree_count']); // 5 + 3 = 8 pohon
        $this->assertEquals(2, $master['work_order_count']);
        $this->assertCount(2, $master['work_orders']);

        $rwoA = collect($master['work_orders'])->firstWhere('work_order_number', 'RWO-BATCH-A');
        $rwoB = collect($master['work_orders'])->firstWhere('work_order_number', 'RWO-BATCH-B');

        $this->assertNotNull($rwoA);
        $this->assertNotNull($rwoB);
        $this->assertEquals(5, $rwoA['tree_count']);
        $this->assertEquals(3, $rwoB['tree_count']);
    }

    public function test_cancelled_trees_are_excluded_from_coating_completion(): void
    {
        $plan = $this->createPlan(['code' => 'CANCEL-EXCLUDE']);
        $line = $this->createPrintOrderLine($plan);

        $rwo = LostWaxRangkaiWorkOrder::create([
            'lost_wax_print_order_line_id' => $line->id,
            'rangkai_order_number' => 'RWO-CANCEL-01',
            'qty_trees_planned' => 4,
            'tree_capacity' => 1,
            'require_layer_7' => false,
            'status' => 'OPEN',
            'created_by' => $this->user->id,
        ]);

        $exec = LostWaxRangkaiExecution::create([
            'rangkai_work_order_id' => $rwo->id,
            'execution_date' => now()->toDateString(),
            'trees_created' => 4,
            'family_code' => '1',
            'recorded_by' => $this->user->id,
            'recorded_at' => now(),
            'status' => 'COMPLETED',
        ]);

        // 3 active trees finished layer 6
        for ($i = 1; $i <= 3; $i++) {
            $tree = $this->createTree([
                'lost_wax_print_order_line_id' => $line->id,
                'rangkai_execution_id' => $exec->id,
                'current_stage' => 'layer_6',
            ]);
            $this->createScanEvent($tree, 'layer_6', now()->subHours(2));
        }

        // 1 tree cancelled during dipping
        $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'rangkai_execution_id' => $exec->id,
            'status' => 'cancelled',
            'current_stage' => 'layer_2',
        ]);

        $data = $this->service->getQueueData();
        $group = $data['items']->firstWhere('production_code', 'CANCEL-EXCLUDE');

        $this->assertNotNull($group);
        // Active trees = 3, coated = 3 -> 100% FULL BATCH
        $this->assertEquals(3, $group['tree_count']);
        $this->assertTrue($group['is_full_batch']);
        $this->assertEquals('3 / 3 TREE COATED', $group['coating_progress_text']);
    }

    // =========================================================================
    // 5. REGRESI (FIFO, Void, Layer 6 & Layer 7)
    // =========================================================================

    public function test_fifo_sorting_priority_maintained_with_weights_and_completion(): void
    {
        $planOld = $this->createPlan(['code' => 'OLD-FIFO', 'weight' => 1.0]);
        $lineOld = $this->createPrintOrderLine($planOld);
        $treeOld = $this->createTree([
            'lost_wax_print_order_line_id' => $lineOld->id,
            'quantity' => 10,
        ]);
        $this->createScanEvent($treeOld, 'layer_6', now()->subHours(5)); // Finished 5h ago

        $planNew = $this->createPlan(['code' => 'NEW-FIFO', 'weight' => 10.0]);
        $lineNew = $this->createPrintOrderLine($planNew);
        $treeNew = $this->createTree([
            'lost_wax_print_order_line_id' => $lineNew->id,
            'quantity' => 10,
        ]);
        $this->createScanEvent($treeNew, 'layer_6', now()->subHours(1)); // Finished 1h ago

        $data = $this->service->getQueueData();

        // Earliest finished must be first regardless of weight or quantity
        $this->assertEquals('OLD-FIFO', $data['items']->first()['production_code']);
        $this->assertEquals('NEW-FIFO', $data['items']->last()['production_code']);
    }

    public function test_voiding_oven_scan_restores_tree_to_queue_with_original_timestamp(): void
    {
        $plan = $this->createPlan(['code' => 'VOID-RESTORE', 'weight' => 2.0]);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'quantity' => 25,
            'current_stage' => 'layer_6',
        ]);

        $finishTime = now()->subHours(4);
        $this->createScanEvent($tree, 'layer_6', $finishTime);

        // Scan oven 1 hour ago -> tree enters oven and disappears from queue
        $ovenEvent = $this->createScanEvent($tree, 'oven', now()->subHours(1));
        $tree->update(['current_stage' => 'oven']);

        $dataBeforeVoid = $this->service->getQueueData();
        $this->assertNull($dataBeforeVoid['items']->firstWhere('production_code', 'VOID-RESTORE'));

        // Void the oven scan
        $voidService = app(ScanVoidService::class);
        $voidService->void($ovenEvent, 'Operator salah pilih rak oven', $this->user->id);

        // Tree must immediately return to queue with original layer 6 finish timestamp
        $dataAfterVoid = $this->service->getQueueData();
        $group = $dataAfterVoid['items']->firstWhere('production_code', 'VOID-RESTORE');

        $this->assertNotNull($group);
        $this->assertEquals(25, $group['quantity_pcs']);
        $this->assertEquals(50.0, $group['weight_kg']);
        $this->assertEquals($finishTime->timestamp, $group['finished_at']->timestamp);
    }

    public function test_layer_6_and_layer_7_behavior_unchanged(): void
    {
        // 7-layer tree not at layer 7 -> does NOT appear
        $plan7 = $this->createPlan(['code' => 'REQ-L7']);
        $line7 = $this->createPrintOrderLine($plan7);
        $tree7NotDone = $this->createTree([
            'lost_wax_print_order_line_id' => $line7->id,
            'require_layer_7' => true,
            'current_stage' => 'layer_6',
        ]);
        $this->createScanEvent($tree7NotDone, 'layer_6', now()->subHours(3));

        $data1 = $this->service->getQueueData();
        $this->assertNull($data1['items']->firstWhere('production_code', 'REQ-L7'));

        // 7-layer tree completes layer 7 -> appears with finished_stage = layer_7
        $tree7NotDone->update(['current_stage' => 'layer_7']);
        $this->createScanEvent($tree7NotDone, 'layer_7', now()->subHours(1));

        $data2 = $this->service->getQueueData();
        $group7 = $data2['items']->firstWhere('production_code', 'REQ-L7');
        $this->assertNotNull($group7);
        $this->assertEquals('layer_7', $group7['finished_stage']);
    }

    // =========================================================================
    // PHASE 1.1: SIAP OVEN / PO INTEGRATION & VARIANCE VALIDATION
    // =========================================================================

    /**
     * 1. Siap Oven 220 PCS, PO 200 PCS -> Lebih 20 PCS
     */
    public function test_siap_oven_above_po_displays_lebih(): void
    {
        $plan = $this->createPlan(['code' => 'PO-HIGH-01', 'po_quantity' => 200]);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'quantity' => 220,
        ]);
        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        $data = $this->service->getQueueData();
        $group = $data['items']->firstWhere('production_code', 'PO-HIGH-01');

        $this->assertNotNull($group);
        $this->assertEquals(200, $group['po_quantity']);
        $this->assertEquals(220, $group['siap_oven_code_pcs']);
        $this->assertEquals(20, $group['po_variance_pcs']);
        $this->assertEquals('LEBIH', $group['po_variance_status']);
        $this->assertEquals('Lebih 20 PCS', $group['po_variance_text']);

        $response = $this->actingAs($this->user)->get(route('lost-wax.reports.ready-for-oven.index'));
        $response->assertStatus(200);
        $response->assertSee('220');
        $response->assertSee('200');
        $response->assertSee('Lebih 20 PCS');
        $response->assertSee('PO-HIGH-01');
        $response->assertSee('Lihat Rincian');
    }

    /**
     * 2. Siap Oven 180 PCS, PO 200 PCS -> Kurang 20 PCS
     */
    public function test_siap_oven_below_po_displays_kurang(): void
    {
        $plan = $this->createPlan(['code' => 'PO-LOW-01', 'po_quantity' => 200]);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'quantity' => 180,
        ]);
        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        $data = $this->service->getQueueData();
        $group = $data['items']->firstWhere('production_code', 'PO-LOW-01');

        $this->assertNotNull($group);
        $this->assertEquals(200, $group['po_quantity']);
        $this->assertEquals(180, $group['siap_oven_code_pcs']);
        $this->assertEquals(20, $group['po_variance_pcs']);
        $this->assertEquals('KURANG', $group['po_variance_status']);
        $this->assertEquals('Kurang 20 PCS', $group['po_variance_text']);

        $response = $this->actingAs($this->user)->get(route('lost-wax.reports.ready-for-oven.index'));
        $response->assertStatus(200);
        $response->assertSee('180');
        $response->assertSee('200');
        $response->assertSee('Kurang 20 PCS');
        $response->assertSee('PO-LOW-01');
    }

    /**
     * 3. Siap Oven 200 PCS, PO 200 PCS -> Sesuai PO
     */
    public function test_siap_oven_equal_po_displays_sesuai_po(): void
    {
        $plan = $this->createPlan(['code' => 'PO-EXACT-01', 'po_quantity' => 200]);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'quantity' => 200,
        ]);
        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        $data = $this->service->getQueueData();
        $group = $data['items']->firstWhere('production_code', 'PO-EXACT-01');

        $this->assertNotNull($group);
        $this->assertEquals(200, $group['po_quantity']);
        $this->assertEquals(200, $group['siap_oven_code_pcs']);
        $this->assertEquals(0, $group['po_variance_pcs']);
        $this->assertEquals('SESUAI', $group['po_variance_status']);
        $this->assertEquals('Sesuai PO', $group['po_variance_text']);

        $response = $this->actingAs($this->user)->get(route('lost-wax.reports.ready-for-oven.index'));
        $response->assertStatus(200);
        $response->assertSee('200');
        $response->assertSee('Sesuai PO');
        $response->assertSee('PO-EXACT-01');
    }

    /**
     * 4. FULL BATCH dengan PCS di bawah PO
     */
    public function test_full_batch_with_pcs_below_po(): void
    {
        $plan = $this->createPlan(['code' => 'FB-UNDER-PO', 'po_quantity' => 300]);
        $line = $this->createPrintOrderLine($plan);

        $rwo = LostWaxRangkaiWorkOrder::create([
            'lost_wax_print_order_line_id' => $line->id,
            'rangkai_order_number' => 'RWO-FB-UNDER',
            'qty_trees_planned' => 5,
            'tree_capacity' => 1,
            'require_layer_7' => false,
            'status' => 'OPEN',
            'created_by' => $this->user->id,
        ]);

        $exec = LostWaxRangkaiExecution::create([
            'rangkai_work_order_id' => $rwo->id,
            'execution_date' => now()->toDateString(),
            'trees_created' => 5,
            'family_code' => '1',
            'recorded_by' => $this->user->id,
            'recorded_at' => now(),
            'status' => 'COMPLETED',
        ]);

        // All 5 trees completed coating (each 40 pcs -> total 200 pcs vs PO 300)
        for ($i = 1; $i <= 5; $i++) {
            $tree = $this->createTree([
                'lost_wax_print_order_line_id' => $line->id,
                'rangkai_execution_id' => $exec->id,
                'quantity' => 40,
                'current_stage' => 'layer_6',
            ]);
            $this->createScanEvent($tree, 'layer_6', now()->subHours(2));
        }

        $data = $this->service->getQueueData();
        $group = $data['items']->firstWhere('production_code', 'FB-UNDER-PO');

        $this->assertNotNull($group);
        // Batch status is FULL BATCH because 5/5 trees coated
        $this->assertTrue($group['is_full_batch']);
        $this->assertEquals('FULL BATCH', $group['coating_status']);
        // But PO variance is KURANG 100 PCS because 200 < 300
        $this->assertEquals(200, $group['siap_oven_code_pcs']);
        $this->assertEquals(300, $group['po_quantity']);
        $this->assertEquals('KURANG', $group['po_variance_status']);
        $this->assertEquals('Kurang 100 PCS', $group['po_variance_text']);
    }

    /**
     * 5. FULL BATCH dengan PCS di atas PO
     */
    public function test_full_batch_with_pcs_above_po(): void
    {
        $plan = $this->createPlan(['code' => 'FB-OVER-PO', 'po_quantity' => 150]);
        $line = $this->createPrintOrderLine($plan);

        $rwo = LostWaxRangkaiWorkOrder::create([
            'lost_wax_print_order_line_id' => $line->id,
            'rangkai_order_number' => 'RWO-FB-OVER',
            'qty_trees_planned' => 4,
            'tree_capacity' => 1,
            'require_layer_7' => false,
            'status' => 'OPEN',
            'created_by' => $this->user->id,
        ]);

        $exec = LostWaxRangkaiExecution::create([
            'rangkai_work_order_id' => $rwo->id,
            'execution_date' => now()->toDateString(),
            'trees_created' => 4,
            'family_code' => '1',
            'recorded_by' => $this->user->id,
            'recorded_at' => now(),
            'status' => 'COMPLETED',
        ]);

        // All 4 trees completed coating (each 50 pcs -> total 200 pcs vs PO 150)
        for ($i = 1; $i <= 4; $i++) {
            $tree = $this->createTree([
                'lost_wax_print_order_line_id' => $line->id,
                'rangkai_execution_id' => $exec->id,
                'quantity' => 50,
                'current_stage' => 'layer_6',
            ]);
            $this->createScanEvent($tree, 'layer_6', now()->subHours(2));
        }

        $data = $this->service->getQueueData();
        $group = $data['items']->firstWhere('production_code', 'FB-OVER-PO');

        $this->assertNotNull($group);
        $this->assertTrue($group['is_full_batch']);
        $this->assertEquals('FULL BATCH', $group['coating_status']);
        $this->assertEquals(200, $group['siap_oven_code_pcs']);
        $this->assertEquals(150, $group['po_quantity']);
        $this->assertEquals('LEBIH', $group['po_variance_status']);
        $this->assertEquals('Lebih 50 PCS', $group['po_variance_text']);
    }

    /**
     * 6. PARTIAL BATCH dengan PCS di atas PO
     */
    public function test_partial_batch_with_pcs_above_po(): void
    {
        $plan = $this->createPlan(['code' => 'PB-OVER-PO', 'po_quantity' => 120]);
        $line = $this->createPrintOrderLine($plan);

        $rwo = LostWaxRangkaiWorkOrder::create([
            'lost_wax_print_order_line_id' => $line->id,
            'rangkai_order_number' => 'RWO-PB-OVER',
            'qty_trees_planned' => 6,
            'tree_capacity' => 1,
            'require_layer_7' => false,
            'status' => 'OPEN',
            'created_by' => $this->user->id,
        ]);

        $exec = LostWaxRangkaiExecution::create([
            'rangkai_work_order_id' => $rwo->id,
            'execution_date' => now()->toDateString(),
            'trees_created' => 6,
            'family_code' => '1',
            'recorded_by' => $this->user->id,
            'recorded_at' => now(),
            'status' => 'COMPLETED',
        ]);

        // 3 trees completed coating (each 50 pcs -> 150 pcs ready, exceeding PO 120)
        for ($i = 1; $i <= 3; $i++) {
            $tree = $this->createTree([
                'lost_wax_print_order_line_id' => $line->id,
                'rangkai_execution_id' => $exec->id,
                'quantity' => 50,
                'current_stage' => 'layer_6',
            ]);
            $this->createScanEvent($tree, 'layer_6', now()->subHours(2));
        }

        // 3 trees still in layer 2 (still dipping)
        for ($i = 4; $i <= 6; $i++) {
            $this->createTree([
                'lost_wax_print_order_line_id' => $line->id,
                'rangkai_execution_id' => $exec->id,
                'current_stage' => 'layer_2',
            ]);
        }

        $data = $this->service->getQueueData();
        $group = $data['items']->firstWhere('production_code', 'PB-OVER-PO');

        $this->assertNotNull($group);
        // Batch remains PARTIAL BATCH because only 3 of 6 trees finished coating!
        $this->assertFalse($group['is_full_batch']);
        $this->assertEquals('PARTIAL BATCH', $group['coating_status']);
        $this->assertEquals('3 / 6 TREE COATED', $group['coating_progress_text']);
        // But PO variance is LEBIH 30 PCS because 150 > 120
        $this->assertEquals(150, $group['siap_oven_code_pcs']);
        $this->assertEquals(120, $group['po_quantity']);
        $this->assertEquals('LEBIH', $group['po_variance_status']);
        $this->assertEquals('Lebih 30 PCS', $group['po_variance_text']);
    }

    /**
     * 7. Satu kode produksi dengan beberapa Work Order: dikelompokkan menjadi 1 baris master (Phase 1.2)
     *    dan PO tidak terduplikasi di total keseluruhan (summary).
     */
    public function test_single_code_multiple_work_orders_deduplicates_po_in_summary(): void
    {
        $plan = $this->createPlan(['code' => 'CODE-MULTI-WO', 'po_quantity' => 500]);
        $line = $this->createPrintOrderLine($plan);

        // WO 1: 3 trees of 50 pcs = 150 pcs
        $rwo1 = LostWaxRangkaiWorkOrder::create([
            'lost_wax_print_order_line_id' => $line->id,
            'rangkai_order_number' => 'RWO-DEDUP-A',
            'qty_trees_planned' => 3,
            'tree_capacity' => 1,
            'require_layer_7' => false,
            'status' => 'OPEN',
            'created_by' => $this->user->id,
        ]);
        $exec1 = LostWaxRangkaiExecution::create([
            'rangkai_work_order_id' => $rwo1->id,
            'execution_date' => now()->toDateString(),
            'trees_created' => 3,
            'family_code' => '1',
            'recorded_by' => $this->user->id,
            'recorded_at' => now(),
            'status' => 'COMPLETED',
        ]);
        for ($i = 1; $i <= 3; $i++) {
            $tree = $this->createTree([
                'lost_wax_print_order_line_id' => $line->id,
                'rangkai_execution_id' => $exec1->id,
                'quantity' => 50,
                'current_stage' => 'layer_6',
            ]);
            $this->createScanEvent($tree, 'layer_6', now()->subHours(3));
        }

        // WO 2: 4 trees of 50 pcs = 200 pcs
        $rwo2 = LostWaxRangkaiWorkOrder::create([
            'lost_wax_print_order_line_id' => $line->id,
            'rangkai_order_number' => 'RWO-DEDUP-B',
            'qty_trees_planned' => 4,
            'tree_capacity' => 1,
            'require_layer_7' => false,
            'status' => 'OPEN',
            'created_by' => $this->user->id,
        ]);
        $exec2 = LostWaxRangkaiExecution::create([
            'rangkai_work_order_id' => $rwo2->id,
            'execution_date' => now()->toDateString(),
            'trees_created' => 4,
            'family_code' => '1',
            'recorded_by' => $this->user->id,
            'recorded_at' => now(),
            'status' => 'COMPLETED',
        ]);
        for ($i = 1; $i <= 4; $i++) {
            $tree = $this->createTree([
                'lost_wax_print_order_line_id' => $line->id,
                'rangkai_execution_id' => $exec2->id,
                'quantity' => 50,
                'current_stage' => 'layer_6',
            ]);
            $this->createScanEvent($tree, 'layer_6', now()->subHours(2));
        }

        $data = $this->service->getQueueData();

        // Phase 1.2: Exact 1 master row for this code
        $groups = $data['items']->where('production_code', 'CODE-MULTI-WO')->values();
        $this->assertCount(1, $groups);

        $master = $groups[0];
        $this->assertEquals(7, $master['tree_count']); // 3 + 4 = 7 trees
        $this->assertEquals(350, $master['quantity_pcs']); // 150 + 200 = 350 pcs
        $this->assertEquals(500, $master['po_quantity']);
        $this->assertEquals(2, $master['work_order_count']);
        $this->assertTrue($master['has_multiple_spk']);
        $this->assertCount(2, $master['work_orders']);

        $spkA = collect($master['work_orders'])->firstWhere('work_order_number', 'RWO-DEDUP-A');
        $spkB = collect($master['work_orders'])->firstWhere('work_order_number', 'RWO-DEDUP-B');
        $this->assertNotNull($spkA);
        $this->assertNotNull($spkB);
        $this->assertEquals(150, $spkA['quantity_pcs']);
        $this->assertEquals(200, $spkB['quantity_pcs']);

        // CRITICAL: In the summary, total_po_pcs MUST be 500, NOT 1,000!
        $this->assertEquals(500, $data['summary']['total_po_pcs']);
        $this->assertEquals(350, $data['summary']['total_usable_pcs']);
        $this->assertEquals(1, $data['summary']['distinct_plans_count']);
        $this->assertEquals(2, $data['summary']['total_spk_count']);
        $this->assertEquals(150, $data['summary']['overall_po_variance_pcs']);
        $this->assertEquals('KURANG', $data['summary']['overall_po_variance_status']);
        $this->assertEquals('Kurang 150 PCS', $data['summary']['overall_po_variance_text']);
    }

    /**
     * 8. Tampilan layar dan cetak menampilkan angka yang konsisten (Preview & Print)
     */
    public function test_preview_and_print_render_identical_siap_oven_po_metrics(): void
    {
        $plan = $this->createPlan(['code' => 'SYNC-VIEW-PO', 'po_quantity' => 200]);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'quantity' => 220,
            'current_stage' => 'layer_6',
        ]);
        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        $previewResponse = $this->actingAs($this->user)->get(route('lost-wax.reports.ready-for-oven.index'));
        $previewResponse->assertStatus(200);
        $previewResponse->assertSee('SIAP OVEN / PO');
        $previewResponse->assertSee('220 / 200');
        $previewResponse->assertSee('Lebih 20 PCS');
        $previewResponse->assertSee('Lihat Rincian');
        $previewResponse->assertDontSee('Status Batch');
        $previewResponse->assertDontSee('FULL BATCH');
        $previewResponse->assertDontSee('PARTIAL BATCH');
        $previewResponse->assertDontSee('<th>Estimasi Berat</th>', false);

        $printResponse = $this->actingAs($this->user)->get(route('lost-wax.reports.ready-for-oven.print'));
        $printResponse->assertStatus(200);
        $printResponse->assertSee('SIAP OVEN / PO');
        $printResponse->assertSee('220 / 200');
        $printResponse->assertSee('Lebih 20 PCS');
        $printResponse->assertDontSee('Status Batch');
        $printResponse->assertDontSee('FULL BATCH');
        $printResponse->assertDontSee('PARTIAL BATCH');
        $printResponse->assertDontSee('<th>Berat (Kg)</th>', false);
    }

    // =========================================================================
    // PHASE 1.2: MASTER-DETAIL & REPRODUCTION OF 268IL435 SCENARIO
    // =========================================================================

    /**
     * Test full scenario reproducing production finding 268IL435:
     * - PO: 1.440 PCS
     * - 5 SPKs (3, 1, 7, 3, 6 trees @ 16 pcs = 48, 16, 112, 48, 96 pcs = 320 pcs total)
     * - Exactly 1 master row returned
     * - Master quantity: 320 / 1.440 PCS with Kurang 1.120 PCS badge
     * - Exactly 5 work orders in master row's detail, sorted by earliest finished_at
     * - Summary KPI: total_po_pcs = 1.440 (no duplication), total_spk_count = 5
     */
    public function test_phase1_2_code_268il435_exact_uat_scenario(): void
    {
        $plan = $this->createPlan([
            'code' => '268IL435',
            'item_name' => 'BRACKET HOUSING 435',
            'customer' => 'E01',
            'po_quantity' => 1440,
            'weight' => 0.75,
        ]);
        $line = $this->createPrintOrderLine($plan);

        // 5 SPKs with tree counts [3, 1, 7, 3, 6]
        $spkConfigs = [
            ['rwo' => 'RWO-20261104-0030', 'trees' => 3, 'hours_ago' => 10],
            ['rwo' => 'RWO-20261104-0040', 'trees' => 7, 'hours_ago' => 8],
            ['rwo' => 'RWO-20261104-0056', 'trees' => 1, 'hours_ago' => 6],
            ['rwo' => 'RWO-20261104-0058', 'trees' => 3, 'hours_ago' => 5],
            ['rwo' => 'RWO-20261105-0018', 'trees' => 6, 'hours_ago' => 2],
        ];

        foreach ($spkConfigs as $cfg) {
            $rwo = LostWaxRangkaiWorkOrder::create([
                'lost_wax_print_order_line_id' => $line->id,
                'rangkai_order_number' => $cfg['rwo'],
                'qty_trees_planned' => $cfg['trees'],
                'tree_capacity' => 1,
                'require_layer_7' => false,
                'status' => 'OPEN',
                'created_by' => $this->user->id,
            ]);

            $exec = LostWaxRangkaiExecution::create([
                'rangkai_work_order_id' => $rwo->id,
                'execution_date' => now()->toDateString(),
                'trees_created' => $cfg['trees'],
                'family_code' => '1',
                'recorded_by' => $this->user->id,
                'recorded_at' => now(),
                'status' => 'COMPLETED',
            ]);

            for ($i = 1; $i <= $cfg['trees']; $i++) {
                $tree = $this->createTree([
                    'lost_wax_print_order_line_id' => $line->id,
                    'rangkai_execution_id' => $exec->id,
                    'quantity' => 16,
                    'current_stage' => 'layer_6',
                ]);
                $this->createScanEvent($tree, 'layer_6', now()->subHours($cfg['hours_ago']));
            }
        }

        $data = $this->service->getQueueData();

        // 1. Exactly 1 row for 268IL435
        $groups = $data['items']->where('production_code', '268IL435')->values();
        $this->assertCount(1, $groups, 'Production code 268IL435 must appear as exactly 1 row, not 5 separate rows');

        $master = $groups[0];
        // 2. Quantity metrics
        $this->assertEquals(20, $master['tree_count'], '20 trees physical total (3+1+7+3+6)');
        $this->assertEquals(320, $master['quantity_pcs'], '320 pcs total ready for oven (20 trees * 16 pcs)');
        $this->assertEquals(1440, $master['po_quantity'], 'PO quantity is 1.440');
        $this->assertEquals(1120, $master['po_variance_pcs']);
        $this->assertEquals('KURANG', $master['po_variance_status']);
        $this->assertEquals('Kurang 1.120 PCS', $master['po_variance_text']);
        $this->assertEquals(5, $master['work_order_count']);
        $this->assertTrue($master['has_multiple_spk']);

        // 3. SPK sub-collection has 5 entries and sorted FIFO by earliest finished_at
        $this->assertCount(5, $master['work_orders']);
        $woNumbers = $master['work_orders']->pluck('work_order_number')->all();
        $this->assertEquals([
            'RWO-20261104-0030', // finished 10 hours ago
            'RWO-20261104-0040', // finished 8 hours ago
            'RWO-20261104-0056', // finished 6 hours ago
            'RWO-20261104-0058', // finished 5 hours ago
            'RWO-20261105-0018', // finished 2 hours ago
        ], $woNumbers, 'SPK list inside master row must be sorted FIFO by earliest finished_at');

        // Check SPK 1 (RWO-20261104-0030: 3 trees, 48 pcs)
        $firstSpk = $master['work_orders']->first();
        $this->assertEquals(3, $firstSpk['tree_count']);
        $this->assertEquals(48, $firstSpk['quantity_pcs']);

        // 4. Summary KPI deduplication
        $this->assertEquals(1440, $data['summary']['total_po_pcs'], 'Total PO in summary must be 1.440, NOT 5 * 1.440 = 7.200');
        $this->assertEquals(320, $data['summary']['total_usable_pcs']);
        $this->assertEquals(1, $data['summary']['distinct_plans_count']);
        $this->assertEquals(5, $data['summary']['total_spk_count']);
        $this->assertEquals(1120, $data['summary']['overall_po_variance_pcs']);
        $this->assertEquals('KURANG', $data['summary']['overall_po_variance_status']);

        // 5. Web view assertion
        $webResponse = $this->actingAs($this->user)->get(route('lost-wax.reports.ready-for-oven.index'));
        $webResponse->assertStatus(200);
        $webResponse->assertSee('268IL435');
        $webResponse->assertSee('320 / 1.440');
        $webResponse->assertSee('Kurang 1.120 PCS');
        $webResponse->assertSee('Lihat Rincian');
        $webResponse->assertSee('5 SPK');
        $webResponse->assertSee('RWO-20261104-0030');
        $webResponse->assertSee('RWO-20261105-0018');
        $webResponse->assertDontSee('Status Batch');
        $webResponse->assertDontSee('FULL BATCH');
        $webResponse->assertDontSee('PARTIAL BATCH');
        $webResponse->assertDontSee('Baris: 48 dari 320');

        // 6. Print view assertion
        $printResponse = $this->actingAs($this->user)->get(route('lost-wax.reports.ready-for-oven.print'));
        $printResponse->assertStatus(200);
        $printResponse->assertSee('268IL435');
        $printResponse->assertSee('320 / 1.440');
        $printResponse->assertSee('Kurang 1.120 PCS');
        $printResponse->assertSee('5 SPK');
        $printResponse->assertSee('RWO-20261104-0030');
        $printResponse->assertDontSee('Status Batch');
    }

    // =========================================================================
    // PHASE 1.3: PO ACHIEVEMENT STATUS FILTER & PRINT INTEGRATION
    // =========================================================================

    /**
     * Helper to set up a standard test environment with 4 distinct PO status scenarios:
     * 1. CODE-SESUAI: 200 Siap Oven / 200 PO -> SESUAI (5 hours ago, FIFO #1, Customer: CUST-A)
     * 2. CODE-LEBIH:  220 Siap Oven / 200 PO -> COMPLETED / LEBIH (4 hours ago, FIFO #2, Customer: CUST-A)
     * 3. CODE-KURANG: 180 Siap Oven / 200 PO -> KURANG (2 SPKs: 100 pcs 3h ago, 80 pcs 2h ago, FIFO #3, Customer: CUST-B)
     * 4. CODE-NO-PO:   50 Siap Oven / null PO -> BELUM_DITENTUKAN (1 hour ago, FIFO #4, Customer: CUST-C)
     */
    private function createPhase13Fixture(): array
    {
        // 1. SESUAI: 200 / 200, Customer: CUST-A
        $planSesuai = $this->createPlan(['code' => 'CODE-SESUAI', 'customer' => 'CUST-A', 'po_quantity' => 200, 'weight' => 1.0]);
        $lineSesuai = $this->createPrintOrderLine($planSesuai);
        $treeSesuai = $this->createTree(['lost_wax_print_order_line_id' => $lineSesuai->id, 'quantity' => 200]);
        $this->createScanEvent($treeSesuai, 'layer_6', now()->subHours(5));

        // 2. LEBIH (COMPLETED): 220 / 200, Customer: CUST-A
        $planLebih = $this->createPlan(['code' => 'CODE-LEBIH', 'customer' => 'CUST-A', 'po_quantity' => 200, 'weight' => 1.0]);
        $lineLebih = $this->createPrintOrderLine($planLebih);
        $treeLebih = $this->createTree(['lost_wax_print_order_line_id' => $lineLebih->id, 'quantity' => 220]);
        $this->createScanEvent($treeLebih, 'layer_6', now()->subHours(4));

        // 3. KURANG: 180 / 200 across 2 SPKs (100 pcs & 80 pcs), Customer: CUST-B
        $planKurang = $this->createPlan(['code' => 'CODE-KURANG', 'customer' => 'CUST-B', 'po_quantity' => 200, 'weight' => 1.0]);
        $lineKurang = $this->createPrintOrderLine($planKurang);

        $rwo1 = LostWaxRangkaiWorkOrder::create([
            'lost_wax_print_order_line_id' => $lineKurang->id,
            'rangkai_order_number' => 'RWO-KURANG-01',
            'qty_trees_planned' => 1,
            'tree_capacity' => 1,
            'status' => 'OPEN',
            'created_by' => $this->user->id,
        ]);
        $exec1 = LostWaxRangkaiExecution::create([
            'rangkai_work_order_id' => $rwo1->id,
            'execution_date' => now()->toDateString(),
            'trees_created' => 1,
            'family_code' => '1',
            'recorded_by' => $this->user->id,
            'recorded_at' => now(),
            'status' => 'COMPLETED',
        ]);
        $treeK1 = $this->createTree(['lost_wax_print_order_line_id' => $lineKurang->id, 'rangkai_execution_id' => $exec1->id, 'quantity' => 100]);
        $this->createScanEvent($treeK1, 'layer_6', now()->subHours(3));

        $rwo2 = LostWaxRangkaiWorkOrder::create([
            'lost_wax_print_order_line_id' => $lineKurang->id,
            'rangkai_order_number' => 'RWO-KURANG-02',
            'qty_trees_planned' => 1,
            'tree_capacity' => 1,
            'status' => 'OPEN',
            'created_by' => $this->user->id,
        ]);
        $exec2 = LostWaxRangkaiExecution::create([
            'rangkai_work_order_id' => $rwo2->id,
            'execution_date' => now()->toDateString(),
            'trees_created' => 1,
            'family_code' => '1',
            'recorded_by' => $this->user->id,
            'recorded_at' => now(),
            'status' => 'COMPLETED',
        ]);
        $treeK2 = $this->createTree(['lost_wax_print_order_line_id' => $lineKurang->id, 'rangkai_execution_id' => $exec2->id, 'quantity' => 80]);
        $this->createScanEvent($treeK2, 'layer_6', now()->subHours(2));

        // 4. NO PO: 50 / null, Customer: CUST-C
        $planNoPo = $this->createPlan(['code' => 'CODE-NO-PO', 'customer' => 'CUST-C', 'po_quantity' => null, 'weight' => 1.0]);
        $lineNoPo = $this->createPrintOrderLine($planNoPo);
        $treeNoPo = $this->createTree(['lost_wax_print_order_line_id' => $lineNoPo->id, 'quantity' => 50]);
        $this->createScanEvent($treeNoPo, 'layer_6', now()->subHours(1));

        return compact('planSesuai', 'planLebih', 'planKurang', 'planNoPo');
    }

    /**
     * Requirement 1: Default filter menampilkan seluruh data (tanpa filter aktif).
     */
    public function test_phase1_3_req1_default_filter_displays_all_data(): void
    {
        $this->createPhase13Fixture();

        $data = $this->service->getQueueData();

        $this->assertCount(4, $data['items']);
        $this->assertEquals(['CODE-SESUAI', 'CODE-LEBIH', 'CODE-KURANG', 'CODE-NO-PO'], $data['items']->pluck('production_code')->all());
        $this->assertFalse($data['summary']['is_filtered']);
        $this->assertFalse($data['summary']['is_po_status_filtered']);
        $this->assertEquals('all', $data['summary']['po_status']);
        $this->assertEquals('ALL', $data['summary']['po_status_label']);
        $this->assertEquals(4, $data['summary']['queue_total_groups']);
        $this->assertEquals(4, $data['summary']['total_groups']);

        // Assert all available options populated for dropdowns
        $this->assertEquals(['CODE-KURANG', 'CODE-LEBIH', 'CODE-NO-PO', 'CODE-SESUAI'], $data['all_codes']);
        $this->assertEquals(['CUST-A', 'CUST-B', 'CUST-C'], $data['all_customers']);
    }

    /**
     * Requirement 2: Filter single kode produksi berfungsi.
     */
    public function test_phase1_3_req2_filter_single_production_code_works(): void
    {
        $this->createPhase13Fixture();

        $data = $this->service->getQueueData(null, null, null, 'all', ['CODE-LEBIH']);

        $this->assertCount(1, $data['items']);
        $item = $data['items']->first();
        $this->assertEquals('CODE-LEBIH', $item['production_code']);
        $this->assertTrue($data['summary']['is_filtered']);
        $this->assertEquals(['CODE-LEBIH'], $data['summary']['selected_codes']);
        $this->assertEquals(1, $data['summary']['total_groups']);
        $this->assertEquals(4, $data['summary']['queue_total_groups']);

        // Dropdown options must not shrink when filter is applied
        $this->assertCount(4, $data['all_codes']);
    }

    /**
     * Requirement 3: Filter multiple kode produksi berfungsi.
     */
    public function test_phase1_3_req3_filter_multiple_production_codes_works(): void
    {
        $this->createPhase13Fixture();

        $data = $this->service->getQueueData(null, null, null, 'all', ['CODE-LEBIH', 'CODE-KURANG']);

        $this->assertCount(2, $data['items']);
        $codes = $data['items']->pluck('production_code')->all();
        $this->assertEquals(['CODE-LEBIH', 'CODE-KURANG'], $codes);
        $this->assertTrue($data['summary']['is_filtered']);
        $this->assertEquals(2, $data['summary']['total_groups']);
        $this->assertEquals(4, $data['summary']['queue_total_groups']);
    }

    /**
     * Requirement 4: Filter status COMPLETED berfungsi (Siap Oven > PO).
     */
    public function test_phase1_3_req4_filter_status_completed_works(): void
    {
        $this->createPhase13Fixture();

        // 1. Using 'completed'
        $data = $this->service->getQueueData(null, null, null, 'completed');
        $this->assertCount(1, $data['items']);
        $item = $data['items']->first();
        $this->assertEquals('CODE-LEBIH', $item['production_code']);
        $this->assertEquals(220, $item['quantity_pcs']);
        $this->assertEquals(200, $item['po_quantity']);
        $this->assertEquals('LEBIH', $item['po_variance_status']);
        $this->assertTrue($data['summary']['is_filtered']);
        $this->assertTrue($data['summary']['is_po_status_filtered']);
        $this->assertEquals('COMPLETED', $data['summary']['po_status_label']);

        // 2. Using legacy alias 'lebih'
        $dataAlias = $this->service->getQueueData(null, null, null, 'lebih');
        $this->assertCount(1, $dataAlias['items']);
        $this->assertEquals('CODE-LEBIH', $dataAlias['items']->first()['production_code']);
    }

    /**
     * Requirement 5: Filter status KURANG berfungsi (Siap Oven < PO).
     */
    public function test_phase1_3_req5_filter_status_kurang_works(): void
    {
        $this->createPhase13Fixture();

        $data = $this->service->getQueueData(null, null, null, 'kurang');

        $this->assertCount(1, $data['items']);
        $item = $data['items']->first();
        $this->assertEquals('CODE-KURANG', $item['production_code']);
        $this->assertEquals(180, $item['quantity_pcs']);
        $this->assertEquals(200, $item['po_quantity']);
        $this->assertEquals('KURANG', $item['po_variance_status']);
        $this->assertEquals('Kurang 20 PCS', $item['po_variance_text']);
        $this->assertTrue($data['summary']['is_filtered']);
        $this->assertEquals('KURANG', $data['summary']['po_status_label']);
    }

    /**
     * Requirement 6: Filter status SESUAI berfungsi (Siap Oven == PO).
     */
    public function test_phase1_3_req6_filter_status_sesuai_works(): void
    {
        $this->createPhase13Fixture();

        $data = $this->service->getQueueData(null, null, null, 'sesuai');

        $this->assertCount(1, $data['items']);
        $item = $data['items']->first();
        $this->assertEquals('CODE-SESUAI', $item['production_code']);
        $this->assertEquals(200, $item['quantity_pcs']);
        $this->assertEquals(200, $item['po_quantity']);
        $this->assertEquals('SESUAI', $item['po_variance_status']);
        $this->assertEquals('Sesuai PO', $item['po_variance_text']);
        $this->assertTrue($data['summary']['is_filtered']);
        $this->assertEquals('SESUAI', $data['summary']['po_status_label']);
    }

    /**
     * Requirement 7: Filter status PO BELUM DITENTUKAN berfungsi (PO null).
     */
    public function test_phase1_3_req7_filter_status_po_belum_ditentukan_works(): void
    {
        $this->createPhase13Fixture();

        $data = $this->service->getQueueData(null, null, null, 'belum_ditentukan');

        $this->assertCount(1, $data['items']);
        $item = $data['items']->first();
        $this->assertEquals('CODE-NO-PO', $item['production_code']);
        $this->assertNull($item['po_quantity']);
        $this->assertEquals('BELUM_DITENTUKAN', $item['po_variance_status']);
        $this->assertEquals('PO belum ditentukan', $item['po_variance_text']);
        $this->assertTrue($data['summary']['is_filtered']);
        $this->assertEquals('PO BELUM DITENTUKAN', $data['summary']['po_status_label']);
    }

    /**
     * Requirement 8: Filter single dan multiple customer berfungsi.
     */
    public function test_phase1_3_req8_filter_single_and_multiple_customer_works(): void
    {
        $this->createPhase13Fixture();

        // 1. Single Customer: CUST-B -> Only CODE-KURANG
        $dataSingle = $this->service->getQueueData(null, null, null, 'all', [], ['CUST-B']);
        $this->assertCount(1, $dataSingle['items']);
        $this->assertEquals('CODE-KURANG', $dataSingle['items']->first()['production_code']);
        $this->assertEquals(['CUST-B'], $dataSingle['summary']['selected_customers']);

        // 2. Multiple Customers: CUST-A and CUST-C -> CODE-SESUAI, CODE-LEBIH, CODE-NO-PO (excludes CUST-B)
        $dataMulti = $this->service->getQueueData(null, null, null, 'all', [], ['CUST-A', 'CUST-C']);
        $this->assertCount(3, $dataMulti['items']);
        $codes = $dataMulti['items']->pluck('production_code')->all();
        $this->assertContains('CODE-SESUAI', $codes);
        $this->assertContains('CODE-LEBIH', $codes);
        $this->assertContains('CODE-NO-PO', $codes);
        $this->assertNotContains('CODE-KURANG', $codes);
    }

    /**
     * Requirement 9: Kombinasi multiple filter (Kode + Status PO + Customer) berfungsi bersamaan.
     */
    public function test_phase1_3_req9_multi_filter_combination_works_simultaneously(): void
    {
        $this->createPhase13Fixture();

        // Matching combination: Code LEBIH, Status COMPLETED, Customer CUST-A -> exactly 1 item
        $dataMatch = $this->service->getQueueData(null, null, null, 'completed', ['CODE-LEBIH'], ['CUST-A']);
        $this->assertCount(1, $dataMatch['items']);
        $this->assertEquals('CODE-LEBIH', $dataMatch['items']->first()['production_code']);
        $this->assertTrue($dataMatch['summary']['is_filtered']);
        $this->assertStringContainsString('Kode: CODE-LEBIH', $dataMatch['summary']['active_filter_text']);
        $this->assertStringContainsString('Status PO: COMPLETED', $dataMatch['summary']['active_filter_text']);
        $this->assertStringContainsString('Customer: CUST-A', $dataMatch['summary']['active_filter_text']);

        // Disjoint combination: Code LEBIH with Customer CUST-B -> 0 items
        $dataDisjoint = $this->service->getQueueData(null, null, null, 'completed', ['CODE-LEBIH'], ['CUST-B']);
        $this->assertCount(0, $dataDisjoint['items']);
        $this->assertEquals(0, $dataDisjoint['summary']['total_groups']);
    }

    /**
     * Requirement 10: Cetak Hasil Filter menampilkan data yang identik dengan hasil filter di layar.
     */
    public function test_phase1_3_req10_preview_and_print_render_identical_filtered_data(): void
    {
        $this->createPhase13Fixture();

        $params = [
            'codes' => ['CODE-LEBIH'],
            'po_status' => 'completed',
            'customers' => ['CUST-A'],
        ];

        // 1. Web View
        $web = $this->actingAs($this->user)->get(route('lost-wax.reports.ready-for-oven.index', $params));
        $web->assertStatus(200);
        $this->assertEquals(['CODE-LEBIH'], $web->viewData('items')->pluck('production_code')->all());
        $web->assertSee('Kode: CODE-LEBIH');
        $web->assertSee('Status PO: COMPLETED');
        $web->assertSee('Cust: CUST-A');
        $web->assertSee('Cetak Hasil Filter');
        $web->assertSee('Cetak Semua');

        // 2. Print View
        $print = $this->actingAs($this->user)->get(route('lost-wax.reports.ready-for-oven.print', $params));
        $print->assertStatus(200);
        $print->assertSee('LAPORAN BARANG SIAP OVEN — Kode: CODE-LEBIH | Status PO: COMPLETED | Customer: CUST-A');
        $print->assertSee('CODE-LEBIH');
        $print->assertDontSee('CODE-KURANG');
        $print->assertDontSee('CODE-SESUAI');
        $print->assertDontSee('CODE-NO-PO');
        $print->assertSee('+Lebih 20 PCS');
    }

    /**
     * Requirement 11: Cetak Semua tetap mencetak seluruh antrean meski filter sedang aktif.
     */
    public function test_phase1_3_req11_print_all_action_displays_entire_queue(): void
    {
        $this->createPhase13Fixture();

        // When invoking print with po_status=all (as linked by the Cetak Semua button)
        $printAll = $this->actingAs($this->user)->get(route('lost-wax.reports.ready-for-oven.print', ['po_status' => 'all']));
        $printAll->assertStatus(200);
        $printAll->assertSee('LAPORAN BARANG SIAP OVEN');
        $printAll->assertSee('CODE-LEBIH');
        $printAll->assertSee('CODE-KURANG');
        $printAll->assertSee('CODE-SESUAI');
        $printAll->assertSee('CODE-NO-PO');
    }

    /**
     * Requirement 12: Urutan FIFO dan detail SPK tetap akurat dalam semua kondisi filter.
     */
    public function test_phase1_3_req12_fifo_order_and_spk_breakdown_remain_accurate(): void
    {
        $this->createPhase13Fixture();

        // 1. FIFO ordering
        $planOld = $this->createPlan(['code' => 'KURANG-OLD', 'customer' => 'CUST-B', 'po_quantity' => 500]);
        $lineOld = $this->createPrintOrderLine($planOld);
        $treeOld = $this->createTree(['lost_wax_print_order_line_id' => $lineOld->id, 'quantity' => 100]);
        $this->createScanEvent($treeOld, 'layer_6', now()->subHours(6)); // Older finish

        $data = $this->service->getQueueData(null, null, null, 'kurang');
        $codes = $data['items']->pluck('production_code')->all();
        $this->assertEquals(['KURANG-OLD', 'CODE-KURANG'], $codes, 'FIFO sequence must remain strictly earliest finished_at ASC');

        // 2. SPK Breakdown
        $item = $data['items']->firstWhere('production_code', 'CODE-KURANG');
        $this->assertNotNull($item);
        $this->assertCount(2, $item['work_orders']);

        $spk1 = collect($item['work_orders'])->firstWhere('work_order_number', 'RWO-KURANG-01');
        $spk2 = collect($item['work_orders'])->firstWhere('work_order_number', 'RWO-KURANG-02');
        $this->assertNotNull($spk1);
        $this->assertNotNull($spk2);
        $this->assertEquals(100, $spk1['quantity_pcs']);
        $this->assertEquals(80, $spk2['quantity_pcs']);
    }

    /**
     * Requirement 13: Query parameter status PO tidak valid kembali ke ALL.
     */
    public function test_phase1_3_invalid_query_parameter_falls_back_to_all(): void
    {
        $this->createPhase13Fixture();

        $data = $this->service->getQueueData(null, null, null, 'non_existent_filter');
        $this->assertEquals('all', $data['summary']['po_status']);
        $this->assertEquals('ALL', $data['summary']['po_status_label']);
        $this->assertFalse($data['summary']['is_po_status_filtered']);
        $this->assertCount(4, $data['items']);

        // Test via HTTP
        $web = $this->actingAs($this->user)->get(route('lost-wax.reports.ready-for-oven.index', ['po_status' => 'garbage_value']));
        $web->assertStatus(200);
        $web->assertSee('CODE-LEBIH');
        $web->assertSee('CODE-KURANG');
        $web->assertSee('CODE-SESUAI');
        $web->assertSee('CODE-NO-PO');
    }

    /**
     * Requirement 14: Filter aktif diteruskan dan dipertahankan pada input dan badge.
     */
    public function test_phase1_3_active_filter_preserved_in_search_and_navigation(): void
    {
        $this->createPhase13Fixture();

        $web = $this->actingAs($this->user)->get(route('lost-wax.reports.ready-for-oven.index', [
            'po_status' => 'kurang',
            'codes' => ['CODE-KURANG'],
            'customers' => ['CUST-B'],
        ]));
        $web->assertStatus(200);
        $web->assertSee('<input type="hidden" name="po_status" value="kurang">', false);
        $web->assertSee('<input type="hidden" name="codes[]" value="CODE-KURANG">', false);
        $web->assertSee('<input type="hidden" name="customers[]" value="CUST-B">', false);
    }

    /**
     * Requirement 15: KPI Summary menghitung metrik agregat yang akurat untuk subset filter.
     */
    public function test_phase1_3_kpi_summary_accurately_represents_filtered_subset(): void
    {
        $this->createPhase13Fixture();

        // 1. Under COMPLETED filter:
        $dataCompleted = $this->service->getQueueData(null, null, null, 'completed');
        $this->assertEquals(200, $dataCompleted['summary']['total_po_pcs']);
        $this->assertEquals(220, $dataCompleted['summary']['total_usable_pcs']);
        $this->assertEquals(1, $dataCompleted['summary']['total_groups']);
        $this->assertEquals(4, $dataCompleted['summary']['queue_total_groups']);
        $this->assertEquals('LEBIH', $dataCompleted['summary']['overall_po_variance_status']);
        $this->assertEquals('Lebih 20 PCS', $dataCompleted['summary']['overall_po_variance_text']);

        // 2. Under KURANG PO filter:
        $dataKurang = $this->service->getQueueData(null, null, null, 'kurang');
        $this->assertEquals(200, $dataKurang['summary']['total_po_pcs']);
        $this->assertEquals(180, $dataKurang['summary']['total_usable_pcs']);
        $this->assertEquals(1, $dataKurang['summary']['total_groups']);
        $this->assertEquals(4, $dataKurang['summary']['queue_total_groups']);
        $this->assertEquals('KURANG', $dataKurang['summary']['overall_po_variance_status']);
        $this->assertEquals('Kurang 20 PCS', $dataKurang['summary']['overall_po_variance_text']);

        // 3. Under PO BELUM DITENTUKAN filter:
        $dataNoPo = $this->service->getQueueData(null, null, null, 'belum_ditentukan');
        $this->assertEquals(0, $dataNoPo['summary']['total_po_pcs']);
        $this->assertEquals(50, $dataNoPo['summary']['total_usable_pcs']);
        $this->assertEquals(1, $dataNoPo['summary']['total_groups']);
        $this->assertNull($dataNoPo['summary']['overall_po_variance_status']);
    }

    /**
     * Requirement 16: Empty state ketika tidak ada kode yang cocok dengan filter aktif.
     */
    public function test_phase1_3_empty_state_rendered_when_no_codes_match_filter(): void
    {
        // Only create an item with PO SESUAI
        $plan = $this->createPlan(['code' => 'ONLY-SESUAI', 'customer' => 'CUST-A', 'po_quantity' => 100]);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 100]);
        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        // Requesting COMPLETED yields 0 items
        $web = $this->actingAs($this->user)->get(route('lost-wax.reports.ready-for-oven.index', ['po_status' => 'completed']));
        $web->assertStatus(200);
        $web->assertSee('Tidak ada kode produksi yang memenuhi kriteria filter.');
        $web->assertSee('Reset Semua Filter');

        $print = $this->actingAs($this->user)->get(route('lost-wax.reports.ready-for-oven.print', ['po_status' => 'completed']));
        $print->assertStatus(200);
        $print->assertSee('Tidak ada kode produksi yang memenuhi kriteria filter <strong>Status PO: COMPLETED</strong>', false);
    }
}
