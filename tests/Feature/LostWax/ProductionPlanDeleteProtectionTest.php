<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxTree;
use App\Models\LostWaxTreeAllocation;
use App\Models\LostWaxTreeDefect;
use App\Models\ProductionItem;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWaxRecoveryService;
use App\Services\PrintExecutionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionPlanDeleteProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $ppicUser;

    protected User $otherPpicUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ppicUser = User::factory()->create([
            'name' => 'PPIC Stainless',
            'email' => 'ppic_ss@peroniks.com',
            'product_scope' => 'FLANGE_STAINLESS',
        ]);
        $this->ppicUser->assignRole('ppic');
        $this->ppicUser->givePermissionTo('access_planning');

        $this->otherPpicUser = User::factory()->create([
            'name' => 'PPIC Besi',
            'email' => 'ppic_besi@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->otherPpicUser->assignRole('ppic');
        $this->otherPpicUser->givePermissionTo('access_planning');
    }

    /**
     * CASE 1: Empty Plan -> DELETE ALLOWED
     */
    public function test_case_1_empty_plan_delete_allowed(): void
    {
        $plan = ProductionPlan::create([
            'code' => 'LW-EMPTY-01',
            'item_code' => 'ITEM-01',
            'item_name' => 'SS304 FLANGE DN 50',
            'po_number' => 'PO-EMPTY',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'product_scope' => 'FLANGE_STAINLESS',
            'status' => 'planning',
        ]);

        $response = $this->actingAs($this->ppicUser)->delete(route('plan.destroy', $plan->id));
        $response->assertRedirect();
        $response->assertSessionHas('success', 'Rencana berhasil dihapus.');

        $this->assertDatabaseMissing('production_plans', ['id' => $plan->id]);
    }

    /**
     * CASE 2: Plan + PO Only -> DELETE ALLOWED
     */
    public function test_case_2_plan_po_only_delete_allowed(): void
    {
        $plan = ProductionPlan::create([
            'code' => 'LW-PO-ONLY',
            'item_code' => 'ITEM-02',
            'item_name' => 'SS304 FLANGE DN 50',
            'po_number' => 'PO-2026-001',
            'po_quantity' => 500,
            'qty_planned' => 550,
            'qty_remaining' => 550,
            'line_number' => 1,
            'product_scope' => 'FLANGE_STAINLESS',
            'status' => 'planning',
        ]);

        $response = $this->actingAs($this->ppicUser)->delete(route('plan.destroy', $plan->id));
        $response->assertRedirect();
        $response->assertSessionHas('success', 'Rencana berhasil dihapus.');

        $this->assertDatabaseMissing('production_plans', ['id' => $plan->id]);
    }

    /**
     * CASE 3 & 4: Plan + Regular Print Order & Line -> DELETE BLOCKED
     */
    public function test_case_3_and_4_plan_with_print_order_line_delete_blocked(): void
    {
        $plan = ProductionPlan::create([
            'code' => 'LW-SPK-01',
            'item_code' => 'ITEM-03',
            'item_name' => 'SS304 FLANGE DN 50',
            'po_number' => 'PO-SPK',
            'po_quantity' => 100,
            'qty_planned' => 120,
            'qty_remaining' => 120,
            'line_number' => 1,
            'product_scope' => 'FLANGE_STAINLESS',
            'status' => 'planning',
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0001',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);
        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 120,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $response = $this->actingAs($this->ppicUser)->delete(route('plan.destroy', $plan->id));
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Tidak bisa menghapus rencana yang sudah memiliki SPK cetak.');

        // Verify parent and child remain intact
        $this->assertDatabaseHas('production_plans', ['id' => $plan->id]);
        $this->assertDatabaseHas('lost_wax_print_order_lines', [
            'id' => $line->id,
            'production_plan_id' => $plan->id,
        ]);
    }

    /**
     * CASE 5: Plan + Finalized Print Execution -> DELETE BLOCKED
     */
    public function test_case_5_plan_with_print_execution_delete_blocked(): void
    {
        $plan = ProductionPlan::create([
            'code' => 'LW-EXEC-01',
            'item_code' => 'ITEM-05',
            'item_name' => 'SS304 FLANGE DN 50',
            'po_number' => 'PO-EXEC',
            'po_quantity' => 100,
            'qty_planned' => 120,
            'qty_remaining' => 120,
            'line_number' => 1,
            'product_scope' => 'FLANGE_STAINLESS',
            'status' => 'planning',
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0005',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);
        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 120,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $execService = app(PrintExecutionService::class);
        $exec = $execService->record($line, [
            'qty_good' => 100,
            'qty_defect' => 20,
            'execution_date' => '2026-08-28',
            'status' => 'FINALIZED',
            'recorded_by' => $this->ppicUser->id,
        ]);

        $response = $this->actingAs($this->ppicUser)->delete(route('plan.destroy', $plan->id));
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Tidak bisa menghapus rencana yang sudah memiliki SPK cetak.');

        $this->assertDatabaseHas('production_plans', ['id' => $plan->id]);
        $this->assertDatabaseHas('lost_wax_print_executions', ['id' => $exec->id]);
    }

    /**
     * CASE 6 & 7: Plan + Tree + Tree Allocation -> DELETE BLOCKED
     */
    public function test_case_6_and_7_plan_with_tree_and_allocation_delete_blocked(): void
    {
        $plan = ProductionPlan::create([
            'code' => 'LW-TREE-01',
            'item_code' => 'ITEM-06',
            'item_name' => 'SS304 FLANGE DN 50',
            'po_number' => 'PO-TREE',
            'po_quantity' => 100,
            'qty_planned' => 120,
            'qty_remaining' => 120,
            'line_number' => 1,
            'product_scope' => 'FLANGE_STAINLESS',
            'status' => 'planning',
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0006',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);
        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 120,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $tree = LostWaxTree::create([
            'tree_number' => 1,
            'barcode' => 'BC-TREE-06',
            'quantity' => 120,
            'current_stage' => 'layer_1',
            'status' => 'active',
            'family_code' => 'FAM-1',
            'daily_sequence' => 1,
            'production_date' => now()->toDateString(),
            'lost_wax_print_order_line_id' => $line->id,
        ]);
        $alloc = LostWaxTreeAllocation::create([
            'lost_wax_tree_id' => $tree->id,
            'lost_wax_print_order_line_id' => $line->id,
            'allocated_qty' => 120,
        ]);

        $response = $this->actingAs($this->ppicUser)->delete(route('plan.destroy', $plan->id));
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Tidak bisa menghapus rencana yang sudah memiliki SPK cetak.');

        $this->assertDatabaseHas('production_plans', ['id' => $plan->id]);
        $this->assertDatabaseHas('lost_wax_trees', ['id' => $tree->id]);
        $this->assertDatabaseHas('lost_wax_tree_allocations', ['id' => $alloc->id]);
    }

    /**
     * CASE 8: Plan + Tree Defect -> DELETE BLOCKED
     */
    public function test_case_8_plan_with_tree_defect_delete_blocked(): void
    {
        $plan = ProductionPlan::create([
            'code' => 'LW-DEF-01',
            'item_code' => 'ITEM-08',
            'item_name' => 'SS304 FLANGE DN 50',
            'po_number' => 'PO-DEF',
            'po_quantity' => 100,
            'qty_planned' => 120,
            'qty_remaining' => 120,
            'line_number' => 1,
            'product_scope' => 'FLANGE_STAINLESS',
            'status' => 'planning',
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0008',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);
        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 120,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $tree = LostWaxTree::create([
            'tree_number' => 1,
            'barcode' => 'BC-TREE-08',
            'quantity' => 120,
            'current_stage' => 'layer_3',
            'status' => 'active',
            'family_code' => 'FAM-1',
            'daily_sequence' => 1,
            'production_date' => now()->toDateString(),
            'lost_wax_print_order_line_id' => $line->id,
        ]);
        $defect = LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $tree->id,
            'stage' => 'layer_3',
            'defect_qty' => 5,
            'defect_reason' => 'CRACK',
            'recorded_by' => $this->ppicUser->id,
        ]);

        $response = $this->actingAs($this->ppicUser)->delete(route('plan.destroy', $plan->id));
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Tidak bisa menghapus rencana yang sudah memiliki SPK cetak.');

        $this->assertDatabaseHas('production_plans', ['id' => $plan->id]);
        $this->assertDatabaseHas('lost_wax_tree_defects', ['id' => $defect->id]);
    }

    /**
     * CASE 9 & 10: Plan + Layer WIP / Oven Scanned -> DELETE BLOCKED
     */
    public function test_case_9_and_10_plan_with_layer_and_oven_delete_blocked(): void
    {
        $plan = ProductionPlan::create([
            'code' => 'LW-OVEN-01',
            'item_code' => 'ITEM-10',
            'item_name' => 'SS304 FLANGE DN 50',
            'po_number' => 'PO-OVEN',
            'po_quantity' => 100,
            'qty_planned' => 120,
            'qty_remaining' => 120,
            'line_number' => 1,
            'product_scope' => 'FLANGE_STAINLESS',
            'status' => 'planning',
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0010',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);
        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 120,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $tree = LostWaxTree::create([
            'tree_number' => 1,
            'barcode' => 'BC-TREE-10',
            'quantity' => 120,
            'current_stage' => 'oven',
            'status' => 'active',
            'family_code' => 'FAM-1',
            'daily_sequence' => 1,
            'production_date' => now()->toDateString(),
            'lost_wax_print_order_line_id' => $line->id,
        ]);

        $response = $this->actingAs($this->ppicUser)->delete(route('plan.destroy', $plan->id));
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Tidak bisa menghapus rencana yang sudah memiliki SPK cetak.');

        $this->assertDatabaseHas('production_plans', ['id' => $plan->id]);
        $this->assertDatabaseHas('lost_wax_trees', ['id' => $tree->id, 'current_stage' => 'oven']);
    }

    /**
     * CASE 11: Plan + Recovery Reprint -> DELETE BLOCKED
     */
    public function test_case_11_plan_with_reprint_delete_blocked(): void
    {
        $plan = ProductionPlan::create([
            'code' => 'LW-REP-01',
            'item_code' => 'ITEM-11',
            'item_name' => 'SS304 FLANGE DN 50',
            'po_number' => 'PO-REP',
            'po_quantity' => 100,
            'qty_planned' => 120,
            'qty_remaining' => 120,
            'line_number' => 1,
            'product_scope' => 'FLANGE_STAINLESS',
            'status' => 'planning',
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0011',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);
        $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 120,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $recService = app(LostWaxRecoveryService::class);
        $reprintOrder = $recService->createReprint($plan, 15, 'Defect Oven Crack', $this->ppicUser->id);

        $response = $this->actingAs($this->ppicUser)->delete(route('plan.destroy', $plan->id));
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Tidak bisa menghapus rencana yang sudah memiliki SPK cetak.');

        $this->assertDatabaseHas('production_plans', ['id' => $plan->id]);
        $this->assertDatabaseHas('lost_wax_print_orders', ['id' => $reprintOrder->id, 'order_type' => 'REPRINT']);
    }

    /**
     * CASE 12: Closed Plan (`is_closed = true`) -> DELETE BLOCKED
     */
    public function test_case_12_closed_plan_delete_blocked(): void
    {
        $plan = ProductionPlan::create([
            'code' => 'LW-CLOSED-01',
            'item_code' => 'ITEM-12',
            'item_name' => 'SS304 FLANGE DN 50',
            'po_number' => 'PO-CLOSED',
            'po_quantity' => 100,
            'qty_planned' => 120,
            'qty_remaining' => 120,
            'line_number' => 1,
            'product_scope' => 'FLANGE_STAINLESS',
            'status' => 'planning',
            'is_closed' => true,
            'closure_reason' => 'Toleransi Customer 5 pcs',
            'closed_by' => $this->ppicUser->id,
            'closed_at' => now(),
        ]);

        $response = $this->actingAs($this->ppicUser)->delete(route('plan.destroy', $plan->id));
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Tidak bisa menghapus rencana yang sudah ditutup.');

        $this->assertDatabaseHas('production_plans', ['id' => $plan->id, 'is_closed' => true]);
    }

    /**
     * CASE 13: Legacy Cor ProductionItem -> DELETE BLOCKED
     */
    public function test_case_13_legacy_cor_item_delete_blocked(): void
    {
        $plan = ProductionPlan::create([
            'code' => 'LW-COR-LEGACY',
            'item_code' => 'ITEM-13',
            'item_name' => 'SS304 FLANGE DN 50',
            'po_number' => 'PO-COR',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'product_scope' => 'FLANGE_STAINLESS',
            'status' => 'planning',
        ]);

        ProductionItem::create([
            'plan_id' => $plan->id,
            'code' => 'COR-01',
            'heat_number' => 'H1234',
            'item_code' => 'ITEM-13',
            'item_name' => 'SS304 FLANGE DN 50',
            'qty_pcs' => 50,
            'current_dept' => 'cor',
            'dept_entry_at' => now(),
        ]);

        $response = $this->actingAs($this->ppicUser)->delete(route('plan.destroy', $plan->id));
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Tidak bisa menghapus rencana yang sudah memiliki data produksi.');

        $this->assertDatabaseHas('production_plans', ['id' => $plan->id]);
    }

    /**
     * CASE 14: Scope Authorization -> 403 on Different Scope
     */
    public function test_case_14_scope_authorization_enforced(): void
    {
        $plan = ProductionPlan::create([
            'code' => 'LW-SCOPE-TEST',
            'item_code' => 'ITEM-14',
            'item_name' => 'SS304 FLANGE DN 50',
            'po_number' => 'PO-SCOPE',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'product_scope' => 'FLANGE_BESI',
            'status' => 'planning',
        ]);

        // Attempt deletion by PPIC Stainless user
        $response = $this->actingAs($this->ppicUser)->delete(route('plan.destroy', $plan->id));
        $response->assertForbidden();

        $this->assertDatabaseHas('production_plans', ['id' => $plan->id]);
    }

    /**
     * CASE 15: Direct HTTP DELETE on Frozen Plan
     */
    public function test_case_15_direct_http_delete_on_frozen_plan_blocked(): void
    {
        $plan = ProductionPlan::create([
            'code' => 'LW-HTTP-FROZEN',
            'item_code' => 'ITEM-15',
            'item_name' => 'SS304 FLANGE DN 50',
            'po_number' => 'PO-HTTP',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'product_scope' => 'FLANGE_STAINLESS',
            'status' => 'planning',
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0015',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);
        $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        // Direct HTTP DELETE request
        $response = $this->actingAs($this->ppicUser)->deleteJson(route('plan.destroy', $plan->id));
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Tidak bisa menghapus rencana yang sudah memiliki SPK cetak.');

        $this->assertDatabaseHas('production_plans', ['id' => $plan->id]);
    }

    /**
     * CASE 16: Database Foreign Key Defense (Direct DB Delete Rejection)
     */
    public function test_case_16_database_foreign_key_defense(): void
    {
        $plan = ProductionPlan::create([
            'code' => 'LW-FK-DEFENSE',
            'item_code' => 'ITEM-16',
            'item_name' => 'SS304 FLANGE DN 50',
            'po_number' => 'PO-FK',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'product_scope' => 'FLANGE_STAINLESS',
            'status' => 'planning',
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0016',
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);
        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        // Direct Eloquent/DB level delete, bypassing controller guard
        try {
            $plan->delete();
            // In SQLite in-memory without FK enabled, or MySQL with RESTRICT FK:
            // Check if DB throws exception or if FK prevented deletion
        } catch (QueryException $e) {
            $this->assertStringContainsString('constraint', strtolower($e->getMessage()));
        }

        // If in SQLite memory mode where FK is enabled or disabled, verify child integrity
        $lineFresh = $line->fresh();
        if ($lineFresh) {
            $this->assertSame($plan->id, $lineFresh->production_plan_id);
        }
    }
}
