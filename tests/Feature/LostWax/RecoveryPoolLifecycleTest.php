<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWaxRecoveryService;
use App\Services\PrintExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecoveryPoolLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $ppicUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ppicUser = User::factory()->create([
            'name' => 'PPIC Stainless',
            'email' => 'ppic_ss@peroniks.com',
            'product_scope' => 'SS',
        ]);
        $this->ppicUser->assignRole('ppic');
        $this->ppicUser->givePermissionTo('access_planning');
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        static $seq = 1;
        $code = $attributes['code'] ?? 'TEST-CODE-'.str_pad((string) $seq++, 3, '0', STR_PAD_LEFT);

        return ProductionPlan::create(array_merge([
            'code' => $code,
            'customer' => 'PT PERONI INTI',
            'item_code' => '4.101105K.A0020',
            'item_name' => 'SS304 BLIND 2 INCH',
            'aisi' => '304',
            'size' => '2"',
            'weight' => 1.25,
            'po_number' => 'PO-2026-001',
            'po_quantity' => 100,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'status' => 'planning',
            'product_scope' => 'SS',
            'is_closed' => false,
        ], $attributes));
    }

    /**
     * TEST 1: Cancelled-only PO, no physical outcome
     * Planning Pool = YES (available for scheduling)
     * Recovery Pool = NO
     */
    public function test_1_cancelled_only_po_appears_in_planning_pool_not_recovery_pool(): void
    {
        $plan = $this->createPlan(['code' => 'BA43-SIM', 'qty_planned' => 25]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260820-0001',
            'scheduled_date' => '2026-08-20',
            'status' => 'CANCELLED',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);

        $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 25,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
        ]);

        // Planning Pool (Tab 1): Available
        $this->assertEquals(25, $plan->fresh()->qty_remaining_scheduled);
        $resTab1 = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'plans']));
        $resTab1->assertOk();
        $resTab1->assertSee('BA43-SIM');

        // Recovery Pool (Tab 3): Excluded
        $resTab3 = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $resTab3->assertOk();
        $resTab3->assertDontSee('BA43-SIM');
    }

    /**
     * TEST 2: Draft line deleted
     * Planning Pool = YES
     * Recovery Pool = NO
     */
    public function test_2_draft_line_deleted_returns_to_planning_pool(): void
    {
        $plan = $this->createPlan(['code' => 'DRAFT-DEL-01', 'qty_planned' => 50]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260820-0002',
            'scheduled_date' => '2026-08-20',
            'status' => 'DRAFT',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 50,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
        ]);

        // Delete line from draft
        $line->delete();

        // Planning Pool: Available
        $this->assertEquals(50, $plan->fresh()->qty_remaining_scheduled);
        $resTab1 = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'plans']));
        $resTab1->assertSee('DRAFT-DEL-01');

        // Recovery Pool: Excluded
        $resTab3 = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $resTab3->assertDontSee('DRAFT-DEL-01');
    }

    /**
     * TEST 3: Cancelled PO + active PO
     * Historical cancelled PO ignored for current calculation, active PO remains.
     */
    public function test_3_cancelled_po_plus_active_po_uses_active_po(): void
    {
        $plan = $this->createPlan(['code' => 'MULTI-PO-01', 'qty_planned' => 30, 'po_quantity' => 30]);

        // PO #1 (CANCELLED)
        $order1 = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260820-0010',
            'scheduled_date' => '2026-08-20',
            'status' => 'CANCELLED',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);
        $order1->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 30,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
        ]);

        // PO #2 (ISSUED with partial outcome 20 pcs)
        $order2 = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260820-0011',
            'scheduled_date' => '2026-08-20',
            'status' => 'ISSUED',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);
        $line2 = $order2->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 30,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
        ]);

        $printService = app(PrintExecutionService::class);
        $printService->record($line2, [
            'qty_good' => 20,
            'qty_defect' => 0,
            'execution_date' => '2026-08-20',
            'status' => 'FINALIZED',
            'recorded_by' => $this->ppicUser->id,
        ]);

        $resTab3 = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $resTab3->assertOk();
        $resTab3->assertSee('MULTI-PO-01');
        $resTab3->assertSee('10 pcs'); // Deficit vs PO (30 - 20)
    }

    /**
     * TEST 4: Issued PO, Good < required
     * Recovery Pool = YES, Deficit correctly calculated.
     */
    public function test_4_issued_po_good_less_than_required_enters_recovery(): void
    {
        $plan = $this->createPlan(['code' => 'SHORT-01', 'qty_planned' => 100, 'po_quantity' => 100]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260820-0020',
            'scheduled_date' => '2026-08-20',
            'status' => 'ISSUED',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);
        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
        ]);

        $printService = app(PrintExecutionService::class);
        $printService->record($line, [
            'qty_good' => 70,
            'qty_defect' => 10,
            'execution_date' => '2026-08-20',
            'status' => 'FINALIZED',
            'recorded_by' => $this->ppicUser->id,
        ]);

        $resTab3 = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $resTab3->assertOk();
        $resTab3->assertSee('SHORT-01');
        $resTab3->assertSee('30 pcs'); // Deficit vs plan: 100 - 70 = 30
        $resTab3->assertSee('DEFISIT PO');
    }

    /**
     * TEST 5: Issued PO, Good + usable >= Target PO
     * No false deficit vs PO.
     */
    public function test_5_issued_po_fulfilled_not_in_active_recovery_when_normal(): void
    {
        $plan = $this->createPlan(['code' => 'FULFILLED-01', 'qty_planned' => 100, 'po_quantity' => 100]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260820-0030',
            'scheduled_date' => '2026-08-20',
            'status' => 'ISSUED',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);
        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
        ]);

        $printService = app(PrintExecutionService::class);
        $printService->record($line, [
            'qty_good' => 105,
            'qty_defect' => 0,
            'execution_date' => '2026-08-20',
            'status' => 'FINALIZED',
            'recorded_by' => $this->ppicUser->id,
        ]);

        $resTab3 = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $resTab3->assertOk();
        $resTab3->assertDontSee('FULFILLED-01');
    }

    /**
     * TEST 6: Physical output exists, Defect creates shortage
     * Recovery Pool = YES.
     */
    public function test_6_defect_creates_shortage_appears_in_recovery(): void
    {
        $plan = $this->createPlan(['code' => 'DEFECT-SHORT-01', 'qty_planned' => 50, 'po_quantity' => 50]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260820-0040',
            'scheduled_date' => '2026-08-20',
            'status' => 'ISSUED',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);
        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 50,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
        ]);

        $printService = app(PrintExecutionService::class);
        $printService->record($line, [
            'qty_good' => 40,
            'qty_defect' => 10,
            'execution_date' => '2026-08-20',
            'status' => 'FINALIZED',
            'recorded_by' => $this->ppicUser->id,
        ]);

        $resTab3 = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $resTab3->assertOk();
        $resTab3->assertSee('DEFECT-SHORT-01');
        $resTab3->assertSee('10 pcs');
    }

    /**
     * TEST 7: Excess Closed creates shortage
     * Recovery Pool = YES.
     */
    public function test_7_excess_closed_creates_shortage_appears_in_recovery(): void
    {
        $plan = $this->createPlan(['code' => 'EXCESS-SHORT-01', 'qty_planned' => 20, 'po_quantity' => 20]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260820-0050',
            'scheduled_date' => '2026-08-20',
            'status' => 'ISSUED',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);
        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 20,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'qty_excess_closed' => 20,
            'excess_closure_reason' => 'Trial run closed',
        ]);

        $printService = app(PrintExecutionService::class);
        $printService->record($line, [
            'qty_good' => 20,
            'qty_defect' => 0,
            'execution_date' => '2026-08-20',
            'status' => 'FINALIZED',
            'recorded_by' => $this->ppicUser->id,
        ]);

        $resTab3 = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $resTab3->assertOk();
        $resTab3->assertSee('EXCESS-SHORT-01');
        $resTab3->assertSee('20 pcs');
        $resTab3->assertSee('Tutup Sisa: 20');
    }

    /**
     * TEST 8: Active Reprint exists
     * No duplicate reprint can be created.
     */
    public function test_8_active_reprint_blocks_duplicate_reprint(): void
    {
        $plan = $this->createPlan(['code' => 'REPRINT-BLOCK-01', 'qty_planned' => 40, 'po_quantity' => 40]);

        $recoveryService = app(LostWaxRecoveryService::class);
        $reprintOrder = $recoveryService->createReprint($plan, 15, 'Defect correction', $this->ppicUser->id);

        $this->assertEquals('DRAFT', $reprintOrder->status);
        $this->assertEquals('REPRINT', $reprintOrder->order_type);

        $this->expectException(\DomainException::class);
        $recoveryService->createReprint($plan, 15, 'Second reprint attempt', $this->ppicUser->id);
    }

    /**
     * TEST 9: Close Recovery
     * Removed from active Recovery Pool, history remains.
     */
    public function test_9_close_recovery_removes_from_active_pool(): void
    {
        $plan = $this->createPlan(['code' => 'CLOSE-TEST-01', 'qty_planned' => 30, 'po_quantity' => 30]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260820-0060',
            'scheduled_date' => '2026-08-20',
            'status' => 'ISSUED',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);
        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 30,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
        ]);

        $printService = app(PrintExecutionService::class);
        $printService->record($line, [
            'qty_good' => 15,
            'qty_defect' => 5,
            'execution_date' => '2026-08-20',
            'status' => 'FINALIZED',
            'recorded_by' => $this->ppicUser->id,
        ]);

        $recoveryService = app(LostWaxRecoveryService::class);
        $recoveryService->closeWithoutReprint($plan, 'Customer tolerance accepted', $this->ppicUser->id);

        $this->assertTrue($plan->fresh()->is_closed);

        // Active filter: Excluded
        $resActive = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery', 'recovery_status' => 'active']));
        $resActive->assertDontSee('CLOSE-TEST-01');

        // Closed filter: Included with audit reason
        $resClosed = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery', 'recovery_status' => 'closed']));
        $resClosed->assertSee('CLOSE-TEST-01');
        $resClosed->assertSee('Customer tolerance accepted');
    }

    /**
     * TEST 10: BA61 Simulation
     * BA61 remains Recovery case according to actual physical state (excess closed).
     * Cancelled historical PO is not the reason for its membership.
     */
    public function test_10_ba61_remains_recovery_due_to_physical_deficit(): void
    {
        $plan = $this->createPlan(['code' => 'BA61', 'qty_planned' => 11, 'po_quantity' => null]);

        // Historical Cancelled PO
        $po1 = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260820-0001',
            'scheduled_date' => '2026-08-20',
            'status' => 'CANCELLED',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);
        $po1->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 11,
            'code' => 'BA61',
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
        ]);

        // Active Issued PO with Excess Closed
        $po2 = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260820-0003',
            'scheduled_date' => '2026-08-20',
            'status' => 'ISSUED',
            'order_type' => 'REGULAR',
            'created_by' => $this->ppicUser->id,
        ]);
        $line2 = $po2->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 11,
            'code' => 'BA61',
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'qty_excess_closed' => 11,
            'excess_closure_reason' => 'hanya untuk uji coba',
        ]);

        $printService = app(PrintExecutionService::class);
        $printService->record($line2, [
            'qty_good' => 11,
            'qty_defect' => 0,
            'execution_date' => '2026-08-20',
            'status' => 'FINALIZED',
            'recorded_by' => $this->ppicUser->id,
        ]);

        $resTab3 = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $resTab3->assertOk();
        $resTab3->assertSee('BA61');
        $resTab3->assertSee('11 pcs'); // Deficit Plan
        $resTab3->assertSee('PERLU COVERAGE'); // Status WATCH because PO is null
        $resTab3->assertSee('Tutup Sisa: 11');
    }

    /**
     * TEST 11: Status Rendering
     * KURANG -> DEFISIT PO
     * WATCH -> PERLU COVERAGE
     * NORMAL -> TERPENUHI
     */
    public function test_11_status_badge_rendering(): void
    {
        // 1. KURANG (Deficit PO)
        $planKurang = $this->createPlan(['code' => 'STATUS-KURANG', 'qty_planned' => 100, 'po_quantity' => 100]);
        $poK = LostWaxPrintOrder::create(['print_order_number' => 'PC-KURANG-01', 'scheduled_date' => '2026-09-07', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $lineK = $poK->lines()->create(['production_plan_id' => $planKurang->id, 'qty_ordered' => 100, 'code' => $planKurang->code, 'customer' => $planKurang->customer, 'item_name' => $planKurang->item_name]);
        app(PrintExecutionService::class)->record($lineK, ['qty_good' => 80, 'qty_defect' => 0, 'status' => 'FINALIZED', 'recorded_by' => $this->ppicUser->id]);

        // 2. WATCH (Perlu Coverage / PO null)
        $planWatch = $this->createPlan(['code' => 'STATUS-WATCH', 'qty_planned' => 100, 'po_quantity' => null]);
        $poW = LostWaxPrintOrder::create(['print_order_number' => 'PC-WATCH-01', 'scheduled_date' => '2026-09-07', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $lineW = $poW->lines()->create(['production_plan_id' => $planWatch->id, 'qty_ordered' => 100, 'code' => $planWatch->code, 'customer' => $planWatch->customer, 'item_name' => $planWatch->item_name]);
        app(PrintExecutionService::class)->record($lineW, ['qty_good' => 90, 'qty_defect' => 0, 'status' => 'FINALIZED', 'recorded_by' => $this->ppicUser->id]);

        $res = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $res->assertOk();
        $res->assertSee('STATUS-KURANG');
        $res->assertSee('DEFISIT PO');
        $res->assertSee('STATUS-WATCH');
        $res->assertSee('PERLU COVERAGE');
    }

    /**
     * TEST 12: Recovery action does not create duplicate quantity
     */
    public function test_12_reprint_action_creates_clean_separate_spk(): void
    {
        $plan = $this->createPlan(['code' => 'DUP-CHECK-01', 'qty_planned' => 50, 'po_quantity' => 50]);

        $po = LostWaxPrintOrder::create(['print_order_number' => 'PC-DUP-01', 'scheduled_date' => '2026-09-07', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $line = $po->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 50, 'code' => $plan->code, 'customer' => $plan->customer, 'item_name' => $plan->item_name]);
        app(PrintExecutionService::class)->record($line, ['qty_good' => 35, 'qty_defect' => 15, 'status' => 'FINALIZED', 'recorded_by' => $this->ppicUser->id]);

        // Submit reprint via endpoint
        $res = $this->actingAs($this->ppicUser)->post(route('lost-wax.print-orders.reprint.store'), [
            'production_plan_id' => $plan->id,
            'quantity' => 15,
            'reprint_reason' => 'Cover 15 defect pcs',
        ]);

        $reprints = LostWaxPrintOrder::where('order_type', 'REPRINT')->get();
        $this->assertCount(1, $reprints);
        $res->assertRedirect(route('lost-wax.print-orders.show', $reprints->first()));
        $this->assertEquals(15, $reprints->first()->lines()->sum('qty_ordered'));
    }
}
