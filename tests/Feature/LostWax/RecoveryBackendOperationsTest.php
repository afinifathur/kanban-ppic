<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWaxQualityService;
use App\Services\LostWaxRecoveryService;
use App\Services\PrintExecutionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class RecoveryBackendOperationsTest extends TestCase
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
            'email' => 'ppic@peroniks.com',
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
            'email' => 'admin@peroniks.com',
            'product_scope' => null,
        ]);
        $this->adminUser->assignRole('admin');
        $this->adminUser->givePermissionTo('access_planning');
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'code' => '268ETB827',
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

    protected function createInitialSpkWithExecution(ProductionPlan $plan, int $good = 1200, int $defect = 0): LostWaxPrintOrder
    {
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0001',
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
     * CASE 1: Create first reprint SPK.
     */
    public function test_case_1_create_first_reprint(): void
    {
        $plan = $this->createPlan();
        $this->createInitialSpkWithExecution($plan, 1200, 0);

        $recoveryService = app(LostWaxRecoveryService::class);
        $reprintOrder = $recoveryService->createReprint(
            $plan,
            50,
            'Kompensasi 50 pcs retak lapisan di Layer 3',
            $this->ppicUser->id,
            '2026-08-28'
        );

        $this->assertNotNull($reprintOrder->id);
        $this->assertSame('REPRINT', $reprintOrder->order_type);
        $this->assertSame(1, $reprintOrder->reprint_cycle);
        $this->assertSame('Kompensasi 50 pcs retak lapisan di Layer 3', $reprintOrder->reprint_reason);
        $this->assertCount(1, $reprintOrder->lines);

        $line = $reprintOrder->lines->first();
        $this->assertSame($plan->id, $line->production_plan_id);
        $this->assertSame(50, $line->qty_ordered);
        $this->assertSame('268ETB827', $line->code);
    }

    /**
     * CASE 2: Create second reprint after first reprint is finalized.
     */
    public function test_case_2_create_second_reprint(): void
    {
        $plan = $this->createPlan();
        $this->createInitialSpkWithExecution($plan, 1200, 0);

        $recoveryService = app(LostWaxRecoveryService::class);
        $reprint1 = $recoveryService->createReprint($plan, 50, 'Cycle 1', $this->ppicUser->id);
        $reprint1->update(['status' => 'COMPLETED']);

        $reprint2 = $recoveryService->createReprint($plan, 20, 'Cycle 2', $this->ppicUser->id);

        $this->assertSame(1, $reprint1->fresh()->reprint_cycle);
        $this->assertSame(2, $reprint2->reprint_cycle);
        $this->assertSame('Cycle 2', $reprint2->reprint_reason);
    }

    /**
     * CASE 3: Existing regular order remains REGULAR and cycle 0.
     */
    public function test_case_3_existing_regular_order_remains_regular(): void
    {
        $plan = $this->createPlan();
        $initialOrder = $this->createInitialSpkWithExecution($plan, 1200, 0);

        $recoveryService = app(LostWaxRecoveryService::class);
        $recoveryService->createReprint($plan, 50, 'Cycle 1', $this->ppicUser->id);

        $initialFresh = $initialOrder->fresh();
        $this->assertSame('REGULAR', $initialFresh->order_type);
        $this->assertSame(0, $initialFresh->reprint_cycle);
        $this->assertNull($initialFresh->reprint_reason);
    }

    /**
     * CASE 4: Closed plan cannot create reprint.
     */
    public function test_case_4_closed_plan_cannot_create_reprint(): void
    {
        $plan = $this->createPlan(['is_closed' => true]);

        $recoveryService = app(LostWaxRecoveryService::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sudah ditutup (CLOSED)');

        $recoveryService->createReprint($plan, 50, 'Gagal karena ditutup', $this->ppicUser->id);
    }

    /**
     * CASE 5: Close without reprint stores audit trail fields.
     */
    public function test_case_5_close_without_reprint_stores_audit_trail(): void
    {
        $plan = $this->createPlan();

        $recoveryService = app(LostWaxRecoveryService::class);
        $closedPlan = $recoveryService->closeWithoutReprint(
            $plan,
            'Disetujui kirim 1150 pcs sesuai toleransi customer',
            $this->ppicUser->id
        );

        $this->assertTrue($closedPlan->is_closed);
        $this->assertSame('Disetujui kirim 1150 pcs sesuai toleransi customer', $closedPlan->closure_reason);
        $this->assertSame($this->ppicUser->id, $closedPlan->closed_by);
        $this->assertNotNull($closedPlan->closed_at);
    }

    /**
     * CASE 6: Closed plan closing is idempotent.
     */
    public function test_case_6_close_is_idempotent(): void
    {
        $plan = $this->createPlan(['is_closed' => true, 'closure_reason' => 'Already closed']);

        $recoveryService = app(LostWaxRecoveryService::class);
        $closedAgain = $recoveryService->closeWithoutReprint($plan, 'Second try', $this->ppicUser->id);

        $this->assertTrue($closedAgain->is_closed);
        $this->assertSame('Already closed', $closedAgain->closure_reason);
    }

    /**
     * CASE 7: PO update changes evaluator deterministically.
     */
    public function test_case_7_po_update_changes_evaluator(): void
    {
        $plan = $this->createPlan(['qty_planned' => 1200, 'po_quantity' => null]);
        $this->createInitialSpkWithExecution($plan, 1150, 0);

        $qualityService = app(LostWaxQualityService::class);
        $breakdown1 = $qualityService->getProductionPlanQuantityBreakdown($plan);
        $this->assertSame('WARNING', $breakdown1['status']);
        $this->assertNull($breakdown1['po_quantity']);

        // Update PO to 1000 (usable 1150 >= PO 1000 -> WARNING)
        $recoveryService = app(LostWaxRecoveryService::class);
        $recoveryService->updatePoQuantity($plan, 1000);

        $breakdown2 = $qualityService->getProductionPlanQuantityBreakdown($plan->fresh());
        $this->assertSame('WARNING', $breakdown2['status']);
        $this->assertSame(1000, $breakdown2['po_quantity']);
        $this->assertSame(0, $breakdown2['deficit_vs_po']);

        // Update PO to 1200 (usable 1150 < PO 1200 -> CRITICAL)
        $recoveryService->updatePoQuantity($plan, 1200);

        $breakdown3 = $qualityService->getProductionPlanQuantityBreakdown($plan->fresh());
        $this->assertSame('CRITICAL', $breakdown3['status']);
        $this->assertSame(50, $breakdown3['deficit_vs_po']);
    }

    /**
     * CASE 8: PO NULL + deficit remains safe as WARNING.
     */
    public function test_case_8_po_null_remains_safe(): void
    {
        $plan = $this->createPlan(['qty_planned' => 1200, 'po_quantity' => null]);
        $this->createInitialSpkWithExecution($plan, 1100, 0);

        $qualityService = app(LostWaxQualityService::class);
        $breakdown = $qualityService->getProductionPlanQuantityBreakdown($plan);

        $this->assertSame('WARNING', $breakdown['status']);
        $this->assertNull($breakdown['deficit_vs_po']);
        $this->assertSame(100, $breakdown['deficit_vs_plan']);
    }

    /**
     * CASE 9: Recalculate deficit and validate requested quantity.
     */
    public function test_case_9_recalculate_deficit_via_quality_service(): void
    {
        $plan = $this->createPlan(['qty_planned' => 1200, 'po_quantity' => 1000]);
        $this->createInitialSpkWithExecution($plan, 1150, 0);

        $qualityService = app(LostWaxQualityService::class);
        $breakdown = $qualityService->getProductionPlanQuantityBreakdown($plan);

        $this->assertSame(50, $breakdown['deficit_vs_plan']);
    }

    /**
     * CASE 10: Requested quantity <= 0 is rejected.
     */
    public function test_case_10_quantity_less_than_one_rejected(): void
    {
        $plan = $this->createPlan();

        $recoveryService = app(LostWaxRecoveryService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lebih besar dari 0');

        $recoveryService->createReprint($plan, 0, 'Zero qty', $this->ppicUser->id);
    }

    /**
     * CASE 11: Active unfinalized reprint SPK blocks duplicate reprint creation.
     */
    public function test_case_11_active_reprint_blocks_duplicate_reprint(): void
    {
        $plan = $this->createPlan();
        $this->createInitialSpkWithExecution($plan, 1200, 0);

        $recoveryService = app(LostWaxRecoveryService::class);
        $recoveryService->createReprint($plan, 50, 'Cycle 1 Draft', $this->ppicUser->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Sudah ada SPK Cetak Ulang aktif');

        $recoveryService->createReprint($plan, 50, 'Cycle 1 Duplicate Attempt', $this->ppicUser->id);
    }

    /**
     * CASE 12: Close vs reprint atomic state guard.
     */
    public function test_case_12_close_vs_reprint_guard(): void
    {
        $plan = $this->createPlan();

        $recoveryService = app(LostWaxRecoveryService::class);
        $recoveryService->closeWithoutReprint($plan, 'Closed first', $this->ppicUser->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sudah ditutup');

        $recoveryService->createReprint($plan, 50, 'Reprint after close', $this->ppicUser->id);
    }

    /**
     * CASE 13: Historical SPK remains unchanged after reprint.
     */
    public function test_case_13_historical_spk_unchanged(): void
    {
        $plan = $this->createPlan();
        $initial = $this->createInitialSpkWithExecution($plan, 1200, 0);

        $recoveryService = app(LostWaxRecoveryService::class);
        $reprint = $recoveryService->createReprint($plan, 50, 'Cycle 1', $this->ppicUser->id);

        $this->assertNotEquals($initial->id, $reprint->id);
        $this->assertSame('REGULAR', $initial->fresh()->order_type);
        $this->assertSame(1200, $initial->lines->first()->qty_ordered);
    }

    /**
     * CASE 14: Reprint remains associated with same ProductionPlan.
     */
    public function test_case_14_reprint_linked_to_same_production_plan(): void
    {
        $plan = $this->createPlan();
        $recoveryService = app(LostWaxRecoveryService::class);
        $reprint = $recoveryService->createReprint($plan, 40, 'Cycle 1', $this->ppicUser->id);

        $this->assertSame($plan->id, $reprint->lines->first()->production_plan_id);
        $this->assertSame($plan->code, $reprint->lines->first()->code);
    }

    /**
     * CASE 15: Authorization checks on HTTP routes.
     */
    public function test_case_15_authorization_rules(): void
    {
        $plan = $this->createPlan(['product_scope' => 'SS']);

        // 1. Authorized PPIC (SS scope) -> SUCCESS
        $response = $this->actingAs($this->ppicUser)->post(route('lost-wax.print-orders.reprint.store'), [
            'production_plan_id' => $plan->id,
            'quantity' => 50,
            'reprint_reason' => 'Defisit Layer 3',
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('lost_wax_print_orders', ['order_type' => 'REPRINT']);

        // 2. Unauthorized PPIC (CS scope) -> 403
        $response2 = $this->actingAs($this->otherPpicUser)->post(route('lost-wax.print-orders.reprint.store'), [
            'production_plan_id' => $plan->id,
            'quantity' => 20,
            'reprint_reason' => 'Unauthorized attempt',
        ]);
        $response2->assertStatus(403);

        // 3. Admin read-only -> 403
        $response3 = $this->actingAs($this->adminUser)->post(route('lost-wax.print-orders.reprint.store'), [
            'production_plan_id' => $plan->id,
            'quantity' => 20,
            'reprint_reason' => 'Admin attempt',
        ]);
        $response3->assertStatus(403);
    }

    /**
     * CASE 16: Close without reprint HTTP endpoint.
     */
    public function test_case_16_close_without_reprint_http_endpoint(): void
    {
        $plan = $this->createPlan(['product_scope' => 'SS']);

        $response = $this->actingAs($this->ppicUser)->post(route('lost-wax.production-plans.close-recovery', $plan), [
            'closure_reason' => 'Disetujui kirim parsial',
        ]);

        $response->assertRedirect();
        $this->assertTrue($plan->fresh()->is_closed);
        $this->assertSame('Disetujui kirim parsial', $plan->fresh()->closure_reason);
    }

    /**
     * CASE 17: Update PO quantity HTTP endpoint.
     */
    public function test_case_17_update_po_quantity_http_endpoint(): void
    {
        $plan = $this->createPlan(['product_scope' => 'SS', 'po_quantity' => null]);

        $response = $this->actingAs($this->ppicUser)->put(route('lost-wax.production-plans.update-po', $plan), [
            'po_quantity' => 1050,
            'po_number' => 'PO-NEW-999',
        ]);

        $response->assertRedirect();
        $this->assertSame(1050, $plan->fresh()->po_quantity);
        $this->assertSame('PO-NEW-999', $plan->fresh()->po_number);
    }

    /**
     * CASE 18: Multiple recovery cycles remain ordered and traceable.
     */
    public function test_case_18_multiple_recovery_cycles_traceable(): void
    {
        $plan = $this->createPlan();
        $this->createInitialSpkWithExecution($plan, 1200, 0);

        $recoveryService = app(LostWaxRecoveryService::class);

        // Cycle 1
        $r1 = $recoveryService->createReprint($plan, 50, 'Cycle 1 reason', $this->ppicUser->id);
        $r1->update(['status' => 'COMPLETED']);

        // Cycle 2
        $r2 = $recoveryService->createReprint($plan, 30, 'Cycle 2 reason', $this->ppicUser->id);
        $r2->update(['status' => 'COMPLETED']);

        // Cycle 3
        $r3 = $recoveryService->createReprint($plan, 10, 'Cycle 3 reason', $this->ppicUser->id);

        $reprints = $plan->printOrderLines()
            ->whereHas('printOrder', fn ($q) => $q->where('order_type', 'REPRINT'))
            ->with('printOrder')
            ->get()
            ->sortBy(fn ($l) => $l->printOrder->reprint_cycle)
            ->values();

        $this->assertCount(3, $reprints);
        $this->assertSame(1, $reprints[0]->printOrder->reprint_cycle);
        $this->assertSame(2, $reprints[1]->printOrder->reprint_cycle);
        $this->assertSame(3, $reprints[2]->printOrder->reprint_cycle);
    }
}
