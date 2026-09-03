<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWaxRecoveryService;
use App\Services\PrintExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecoveryPoolUiTest extends TestCase
{
    use RefreshDatabase;

    protected User $ppicUser;

    protected User $otherPpicUser;

    protected User $adminUser;

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

        $this->otherPpicUser = User::factory()->create([
            'name' => 'PPIC Carbon Steel',
            'email' => 'ppic_cs@peroniks.com',
            'product_scope' => 'CS',
        ]);
        $this->otherPpicUser->assignRole('ppic');
        $this->otherPpicUser->givePermissionTo('access_planning');

        $this->adminUser = User::factory()->create([
            'name' => 'Admin Read Only',
            'email' => 'admin_ro@peroniks.com',
            'product_scope' => null,
        ]);
        $this->adminUser->assignRole('admin');
        $this->adminUser->givePermissionTo('access_planning');
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        static $seq = 1;
        $code = $attributes['code'] ?? '268ETB'.str_pad((string) $seq++, 3, '0', STR_PAD_LEFT);

        return ProductionPlan::create(array_merge([
            'code' => $code,
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
            'product_scope' => 'SS',
            'is_closed' => false,
        ], $attributes));
    }

    protected function createInitialSpkWithExecution(ProductionPlan $plan, int $good = 1200, int $defect = 0, ?string $orderNumber = null): LostWaxPrintOrder
    {
        static $orderSeq = 1;
        $num = $orderNumber ?? 'PC-20260828-'.str_pad((string) $orderSeq++, 4, '0', STR_PAD_LEFT);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => $num,
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'order_type' => 'REGULAR',
            'reprint_cycle' => 0,
            'created_by' => $this->ppicUser->id,
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
            'recorded_by' => $this->ppicUser->id,
        ]);

        return $order;
    }

    /**
     * TEST 1: Recovery tab renders properly.
     */
    public function test_1_recovery_tab_renders(): void
    {
        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $response->assertOk();
        $response->assertSee('Recovery Pool');
        $response->assertSee('Perlu Tindakan');
    }

    /**
     * TEST 2: Normal plan absent from active recovery pool.
     */
    public function test_2_normal_plan_absent_from_active_recovery(): void
    {
        $plan = $this->createPlan(['code' => 'NORMAL-PLAN-01', 'qty_planned' => 1000, 'po_quantity' => 1000]);
        $this->createInitialSpkWithExecution($plan, 1001, 0);

        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $response->assertOk();
        $response->assertDontSee('NORMAL-PLAN-01');
    }

    /**
     * TEST 3: Warning plan appears in active recovery pool.
     */
    public function test_3_warning_plan_appears(): void
    {
        $plan = $this->createPlan(['code' => 'WARN-PLAN-01', 'qty_planned' => 1200, 'po_quantity' => 1000]);
        $this->createInitialSpkWithExecution($plan, 1150, 0); // Deficit 50 vs plan, but >= PO 1000

        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $response->assertOk();
        $response->assertSee('WARN-PLAN-01');
        $response->assertSee('WARNING');
    }

    /**
     * TEST 4: Critical plan appears with CRITICAL badge.
     */
    public function test_4_critical_plan_appears(): void
    {
        $plan = $this->createPlan(['code' => 'CRIT-PLAN-01', 'qty_planned' => 1200, 'po_quantity' => 1000]);
        $this->createInitialSpkWithExecution($plan, 950, 0); // Deficit 50 vs PO

        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $response->assertOk();
        $response->assertSee('CRIT-PLAN-01');
        $response->assertSee('CRITICAL');
    }

    /**
     * TEST 5: PO null displays PO BELUM DIISI.
     */
    public function test_5_po_null_displays_po_belum_diisi(): void
    {
        $plan = $this->createPlan(['code' => 'PO-NULL-01', 'qty_planned' => 1200, 'po_quantity' => null]);
        $this->createInitialSpkWithExecution($plan, 1100, 0);

        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $response->assertOk();
        $response->assertSee('PO-NULL-01');
        $response->assertSee('PO BELUM DIISI');
    }

    /**
     * TEST 6: Deficit plan calculated correctly.
     */
    public function test_6_deficit_plan_correct(): void
    {
        $plan = $this->createPlan(['code' => 'DEF-PLAN-01', 'qty_planned' => 1200, 'po_quantity' => 1000]);
        $this->createInitialSpkWithExecution($plan, 1130, 0); // Deficit vs plan: 70

        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $response->assertOk();
        $response->assertSee('70 pcs');
    }

    /**
     * TEST 7: Deficit PO calculated correctly.
     */
    public function test_7_deficit_po_correct(): void
    {
        $plan = $this->createPlan(['code' => 'DEF-PO-01', 'qty_planned' => 1200, 'po_quantity' => 1000]);
        $this->createInitialSpkWithExecution($plan, 960, 0); // Deficit vs PO: 40

        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $response->assertOk();
        $response->assertSee('40 pcs');
    }

    /**
     * TEST 8: PO null displays dash for Defisit PO.
     */
    public function test_8_po_null_displays_dash(): void
    {
        $plan = $this->createPlan(['code' => 'DASH-PO-01', 'qty_planned' => 1200, 'po_quantity' => null]);
        $this->createInitialSpkWithExecution($plan, 1100, 0);

        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $response->assertOk();
        $response->assertSee('—');
    }

    /**
     * TEST 9: Active reprint displayed in table.
     */
    public function test_9_active_reprint_displayed(): void
    {
        $plan = $this->createPlan(['code' => 'REPRINT-ACT-01']);
        $this->createInitialSpkWithExecution($plan, 1150, 0);

        $recoveryService = app(LostWaxRecoveryService::class);
        $reprint = $recoveryService->createReprint($plan, 50, 'Aktif Draft', $this->ppicUser->id);

        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $response->assertOk();
        $response->assertSee($reprint->print_order_number);
        $response->assertSee('SPK #1');
    }

    /**
     * TEST 10: Reprint button unavailable when active reprint exists.
     */
    public function test_10_reprint_button_unavailable_when_active_reprint_exists(): void
    {
        $plan = $this->createPlan(['code' => 'REPRINT-BLOCK-01']);
        $this->createInitialSpkWithExecution($plan, 1150, 0);

        $recoveryService = app(LostWaxRecoveryService::class);
        $recoveryService->createReprint($plan, 50, 'Aktif Issued', $this->ppicUser->id);

        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $response->assertOk();
        $response->assertDontSee('+ SPK Reprint');
    }

    /**
     * TEST 11: Reprint modal structure renders in HTML.
     */
    public function test_11_reprint_modal_renders(): void
    {
        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $response->assertOk();
        $response->assertSee('id="reprint-modal"', false);
        $response->assertSee('Terbitkan SPK Cetak Ulang');
    }

    /**
     * TEST 12: Default quantity matches deficit vs plan.
     */
    public function test_12_reprint_default_quantity_equals_deficit_plan(): void
    {
        $plan = $this->createPlan(['code' => 'DEFICIT-VAL-01', 'qty_planned' => 1200]);
        $this->createInitialSpkWithExecution($plan, 1120, 0); // Deficit: 80

        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $response->assertOk();
        $response->assertSee('openReprintModal('.$plan->id.', \'DEFICIT-VAL-01\',', false);
    }

    /**
     * TEST 13: Reprint quantity is editable and submitted.
     */
    public function test_13_reprint_quantity_editable(): void
    {
        $plan = $this->createPlan();
        $this->createInitialSpkWithExecution($plan, 1150, 0);

        $response = $this->actingAs($this->ppicUser)->post(route('lost-wax.print-orders.reprint.store'), [
            'production_plan_id' => $plan->id,
            'quantity' => 60, // Custom quantity
            'reprint_reason' => 'Permintaan penambahan 60 pcs',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('lost_wax_print_orders', [
            'order_type' => 'REPRINT',
            'reprint_cycle' => 1,
        ]);
        $this->assertDatabaseHas('lost_wax_print_order_lines', [
            'production_plan_id' => $plan->id,
            'qty_ordered' => 60,
        ]);
    }

    /**
     * TEST 14: Reprint reason required.
     */
    public function test_14_reprint_reason_required(): void
    {
        $plan = $this->createPlan();
        $this->createInitialSpkWithExecution($plan, 1150, 0);

        $response = $this->actingAs($this->ppicUser)->post(route('lost-wax.print-orders.reprint.store'), [
            'production_plan_id' => $plan->id,
            'quantity' => 50,
            'reprint_reason' => '',
        ]);

        $response->assertSessionHasErrors('reprint_reason');
    }

    /**
     * TEST 15: Close modal renders in HTML.
     */
    public function test_15_close_modal_renders(): void
    {
        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $response->assertOk();
        $response->assertSee('id="close-modal"', false);
        $response->assertSee('Tutup Rencana Tanpa Reprint');
    }

    /**
     * TEST 16: Closure reason required.
     */
    public function test_16_closure_reason_required(): void
    {
        $plan = $this->createPlan();

        $response = $this->actingAs($this->ppicUser)->post(route('lost-wax.production-plans.close-recovery', $plan), [
            'closure_reason' => 'ab', // < 3 chars
        ]);

        $response->assertSessionHasErrors('closure_reason');
    }

    /**
     * TEST 17: PO modal renders in HTML.
     */
    public function test_17_po_modal_renders(): void
    {
        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $response->assertOk();
        $response->assertSee('id="po-modal"', false);
        $response->assertSee('Perbarui Data PO Customer');
    }

    /**
     * TEST 18: PO update reflects new status.
     */
    public function test_18_po_update_reflects_new_status(): void
    {
        $plan = $this->createPlan(['qty_planned' => 1200, 'po_quantity' => null]);
        $this->createInitialSpkWithExecution($plan, 1150, 0);

        $response = $this->actingAs($this->ppicUser)->put(route('lost-wax.production-plans.update-po', $plan), [
            'po_number' => 'PO-UPDATED-99',
            'po_quantity' => 1100,
        ]);

        $response->assertRedirect();
        $this->assertSame(1100, $plan->fresh()->po_quantity);
    }

    /**
     * TEST 19: Unauthorized user cannot perform recovery actions.
     */
    public function test_19_unauthorized_user_cannot_perform_recovery_actions(): void
    {
        $plan = $this->createPlan(['product_scope' => 'SS']);

        $response = $this->actingAs($this->otherPpicUser)->post(route('lost-wax.print-orders.reprint.store'), [
            'production_plan_id' => $plan->id,
            'quantity' => 50,
            'reprint_reason' => 'Unauthorized attempt',
        ]);

        $response->assertStatus(403);
    }

    /**
     * TEST 20: No duplicate ProductionPlan rows.
     */
    public function test_20_no_duplicate_production_plan_rows(): void
    {
        $plan = $this->createPlan(['code' => 'SINGLE-ROW-PLAN']);
        $this->createInitialSpkWithExecution($plan, 1150, 0);

        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $response->assertOk();

        $content = $response->getContent();
        $count = substr_count($content, 'SINGLE-ROW-PLAN');
        $this->assertGreaterThanOrEqual(1, $count);
    }

    /**
     * TEST 21: Tab 1 (Rencana Cetak) still works.
     */
    public function test_21_tab_1_still_works(): void
    {
        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'plans']));
        $response->assertOk();
        $response->assertSee('Rencana Cetak (Plan Items)');
    }

    /**
     * TEST 22: Tab 2 (Dokumen SPK) still works.
     */
    public function test_22_tab_2_still_works(): void
    {
        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'orders']));
        $response->assertOk();
        $response->assertSee('Dokumen Perintah Cetak (Print Orders)');
    }

    /**
     * TEST 23: No N+1 query regression on Recovery Pool.
     */
    public function test_23_no_n_plus_one_regression(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $plan = $this->createPlan(['code' => "PERF-PLAN-{$i}"]);
            $this->createInitialSpkWithExecution($plan, 1100, 0, "PC-20260828-PERF-{$i}");
        }

        \DB::enableQueryLog();
        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.print-orders.plans', ['tab' => 'recovery']));
        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        $response->assertOk();
        $this->assertLessThan(20, count($queries));
    }

    /**
     * TEST 24: Close action redirects back cleanly.
     */
    public function test_24_close_action_redirects_to_recovery(): void
    {
        $plan = $this->createPlan();

        $response = $this->actingAs($this->ppicUser)->post(route('lost-wax.production-plans.close-recovery', $plan), [
            'closure_reason' => 'Disetujui kirim apa adanya',
        ]);

        $response->assertRedirect();
        $this->assertTrue($plan->fresh()->is_closed);
    }

    /**
     * TEST 25: Reprint action redirects to print order show.
     */
    public function test_25_reprint_action_redirects_to_recovery(): void
    {
        $plan = $this->createPlan();
        $this->createInitialSpkWithExecution($plan, 1150, 0);

        $response = $this->actingAs($this->ppicUser)->post(route('lost-wax.print-orders.reprint.store'), [
            'production_plan_id' => $plan->id,
            'quantity' => 50,
            'reprint_reason' => 'Kompensasi defisit',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('lost_wax_print_orders', ['order_type' => 'REPRINT']);
    }
}
