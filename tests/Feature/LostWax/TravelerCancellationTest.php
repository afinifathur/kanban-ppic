<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\RangkaiExecutionService;
use App\Services\ScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TravelerCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $accessPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution']);
        $adminRole->syncPermissions([$accessPlanning, $accessExecution]);

        $this->adminUser = User::factory()->create(['email' => 'adminppicpf@peroniks.com']);
        $this->adminUser->assignRole('admin');
    }

    protected function createPlanAndPrintOrder(int $good = 100): array
    {
        $plan = ProductionPlan::create([
            'code' => 'SS304SQ25',
            'customer' => 'PT PERONI KARYA UTAMA',
            'item_code' => '4.101105K.A0080',
            'item_name' => 'SS304 SQUARE DN 25',
            'aisi' => '304',
            'size' => '1"',
            'weight' => 1.25,
            'po_number' => 'PO-2026-08',
            'qty_planned' => $good,
            'qty_remaining' => $good,
            'line_number' => 1,
            'status' => 'planning',
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260827-0001',
            'scheduled_date' => '2026-08-27',
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => $good,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'qty_executed_good' => $good,
            'qty_executed_defect' => 0,
        ]);

        $rangkaiService = app(RangkaiExecutionService::class);
        $workOrder = $rangkaiService->createWorkOrder($line, [
            'qty_trees_planned' => $good,
            'tree_capacity' => 1,
            'standard_capacity_guide' => 4,
            'require_layer_7' => false,
            'notes' => 'Catatan WO Uji Coba',
            'created_by' => $this->adminUser->id,
        ]);

        return [$plan, $line, $workOrder];
    }

    public function test_show_wo_header_contains_prominent_product_name(): void
    {
        [, , $workOrder] = $this->createPlanAndPrintOrder(80);

        $response = $this->actingAs($this->adminUser)
            ->get(route('lost-wax.assemblies.work-orders.show', $workOrder));

        $response->assertOk();
        $response->assertSee('SS304 SQUARE DN 25');
        $response->assertSee($workOrder->rangkai_order_number);
        $response->assertSee('LOST WAX ASSEMBLY WORK ORDER', false);
        $response->assertSee('STATUS: OPEN');
        $response->assertSee('KONFIRMASI TERBITKAN TRAVELER', false);
    }

    public function test_issue_traveler_generates_active_trees(): void
    {
        [, , $workOrder] = $this->createPlanAndPrintOrder(80);

        $response = $this->actingAs($this->adminUser)
            ->post(route('lost-wax.assemblies.work-orders.execution.store', $workOrder), [
                'execution_date' => '2026-08-27',
                'trees_created' => 20,
                'quantities' => array_fill(0, 20, 4),
                'family_code' => 'M',
            ]);

        $response->assertRedirect(route('lost-wax.assemblies.work-orders.show', $workOrder));

        $workOrder->refresh();
        $this->assertEquals('COMPLETED', $workOrder->status);
        $this->assertEquals(80, $workOrder->qty_executed_pcs);
        $this->assertEquals(0, $workOrder->qty_outstanding);
        $this->assertCount(1, $workOrder->executions);

        $execution = $workOrder->executions->first();
        $this->assertEquals('ACTIVE', $execution->status);
        $this->assertCount(20, $execution->trees);

        // Active trees appear in /lost-wax/trees
        $treeResponse = $this->actingAs($this->adminUser)->get(route('lost-wax.trees.index'));
        $treeResponse->assertSee($execution->trees->first()->barcode);
    }

    public function test_cancel_traveler_before_layer_1_scan_succeeds_and_restores_outstanding(): void
    {
        [, , $workOrder] = $this->createPlanAndPrintOrder(80);

        $service = app(RangkaiExecutionService::class);
        $execution = $service->recordExecution($workOrder, [
            'execution_date' => '2026-08-27',
            'trees_created' => 20,
            'quantities' => array_fill(0, 20, 4),
            'family_code' => 'M',
        ]);

        $workOrder->refresh();
        $this->assertEquals(0, $workOrder->qty_outstanding);

        // Cancel execution
        $response = $this->actingAs($this->adminUser)
            ->post(route('lost-wax.assemblies.executions.cancel', $execution), [
                'cancellation_reason' => 'Salah jumlah rangkaian operator',
            ]);

        $response->assertRedirect(route('lost-wax.assemblies.work-orders.show', $workOrder));

        $execution->refresh();
        $workOrder->refresh();

        $this->assertEquals('CANCELLED', $execution->status);
        $this->assertEquals('Salah jumlah rangkaian operator', $execution->cancellation_reason);
        $this->assertEquals($this->adminUser->id, $execution->cancelled_by);
        $this->assertNotNull($execution->cancelled_at);

        // Outstanding is restored
        $this->assertEquals(80, $workOrder->qty_outstanding);
        $this->assertEquals(0, $workOrder->qty_executed_pcs);
        $this->assertEquals('OPEN', $workOrder->status);

        // Trees are marked cancelled
        foreach ($execution->trees as $tree) {
            $this->assertEquals('cancelled', $tree->fresh()->status);
        }

        // Cancelled trees do not appear in active tree list
        $treeIndexResponse = $this->actingAs($this->adminUser)->get(route('lost-wax.trees.index'));
        $treeIndexResponse->assertDontSee($execution->trees->first()->barcode);
    }

    public function test_cancelled_tree_cannot_be_scanned(): void
    {
        [, , $workOrder] = $this->createPlanAndPrintOrder(80);

        $service = app(RangkaiExecutionService::class);
        $execution = $service->recordExecution($workOrder, [
            'execution_date' => '2026-08-27',
            'trees_created' => 20,
            'quantities' => array_fill(0, 20, 4),
            'family_code' => 'M',
        ]);

        $barcode = $execution->trees->first()->barcode;

        // Cancel traveler
        $service->cancelExecution($execution, 'Salah Work Order', $this->adminUser);

        // Attempt scan
        $scanService = app(ScanService::class);
        $result = $scanService->process($barcode, $this->adminUser->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('dibatalkan', $result['reason']);
    }

    public function test_cannot_cancel_traveler_if_layer_1_already_scanned(): void
    {
        [, , $workOrder] = $this->createPlanAndPrintOrder(80);

        $service = app(RangkaiExecutionService::class);
        $execution = $service->recordExecution($workOrder, [
            'execution_date' => '2026-08-27',
            'trees_created' => 20,
            'quantities' => array_fill(0, 20, 4),
            'family_code' => 'M',
        ]);

        $firstTree = $execution->trees->first();

        // Scan layer 1
        $scanService = app(ScanService::class);
        $scanResult = $scanService->process($firstTree->barcode, $this->adminUser->id);
        $this->assertTrue($scanResult['success']);

        // Attempt cancellation
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sudah melalui Scan Layer 1');

        $service->cancelExecution($execution, 'Ingin batalkan setelah scan', $this->adminUser);
    }

    public function test_cancellation_requires_non_empty_reason(): void
    {
        [, , $workOrder] = $this->createPlanAndPrintOrder(80);

        $service = app(RangkaiExecutionService::class);
        $execution = $service->recordExecution($workOrder, [
            'execution_date' => '2026-08-27',
            'trees_created' => 20,
            'quantities' => array_fill(0, 20, 4),
            'family_code' => 'M',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Alasan pembatalan wajib diisi');

        $service->cancelExecution($execution, '   ', $this->adminUser);
    }

    public function test_double_cancellation_is_rejected(): void
    {
        [, , $workOrder] = $this->createPlanAndPrintOrder(80);

        $service = app(RangkaiExecutionService::class);
        $execution = $service->recordExecution($workOrder, [
            'execution_date' => '2026-08-27',
            'trees_created' => 20,
            'quantities' => array_fill(0, 20, 4),
            'family_code' => 'M',
        ]);

        $service->cancelExecution($execution, 'Pembatalan pertama', $this->adminUser);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sudah dalam status dibatalkan');

        $service->cancelExecution($execution, 'Pembatalan kedua', $this->adminUser);
    }
}
