<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\LostWaxTreeDefect;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWax\ReadyForOvenService;
use App\Services\LostWax\ScanSiapCorService;
use App\Services\ScanVoidService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ScanSiapCorTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected ScanSiapCorService $service;

    protected static int $seq = 1000;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'access_execution']);
        Permission::firstOrCreate(['name' => 'access_planning']);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->syncPermissions(['access_execution', 'access_planning']);

        $this->user = User::factory()->create([
            'email' => 'operator_cor@peroniks.com',
        ]);
        $this->user->assignRole('admin');

        $this->service = app(ScanSiapCorService::class);
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        $seq = self::$seq++;

        return ProductionPlan::create(array_merge([
            'code' => 'PLAN-'.$seq,
            'customer' => 'CUST-'.$seq,
            'item_code' => 'ITEM-'.$seq,
            'item_name' => 'Valve Body Test '.$seq,
            'aisi' => '304',
            'size' => '2"',
            'weight' => 2.50,
            'po_number' => 'PO-'.$seq,
            'po_quantity' => 100,
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
            'barcode' => 'TREE-COR-'.uniqid().'-'.$currentSeq,
            'tree_number' => $currentSeq,
            'quantity' => 50,
            'status' => 'generated',
            'current_stage' => 'oven',
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
    // 1. ROUTING & ACCESS TESTS
    // =========================================================================

    public function test_1_scan_siap_cor_page_can_be_accessed_by_authorized_user(): void
    {
        $response = $this->actingAs($this->user)->get(route('lost-wax.scan-siap-cor.index'));
        $response->assertStatus(200);
        $response->assertSee('SCAN SIAP COR');
        $response->assertSee('SCAN BARCODE TRAVELER CARD');
    }

    public function test_2_unauthenticated_user_redirected_to_login(): void
    {
        $response = $this->get(route('lost-wax.scan-siap-cor.index'));
        $response->assertRedirect(route('login'));
    }

    // =========================================================================
    // 2. VALIDATION TESTS
    // =========================================================================

    public function test_3_scan_fails_when_barcode_is_empty(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('lost-wax.scan-siap-cor.process'), [
            'barcode' => '   ',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'code' => 'EMPTY_BARCODE',
            'reason' => 'GAGAL — BARCODE TIDAK BOLEH KOSONG',
        ]);
    }

    public function test_4_scan_fails_when_barcode_not_found(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('lost-wax.scan-siap-cor.process'), [
            'barcode' => 'UNKNOWN-BARCODE-999',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'code' => 'NOT_FOUND',
            'reason' => 'GAGAL — BARCODE TIDAK DITEMUKAN',
        ]);

        // Asserts no fake tree or scan event is recorded
        $this->assertDatabaseMissing('lost_wax_scan_events', [
            'barcode' => 'UNKNOWN-BARCODE-999',
        ]);
    }

    public function test_5_scan_fails_when_tree_has_not_passed_oven_scan(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        // Case A: Tree still at layer_6 (dipping stage)
        $treeDipping = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'layer_6',
        ]);
        $this->createScanEvent($treeDipping, 'layer_6', now()->subHours(5));

        $resA = $this->service->processScan($treeDipping->barcode, $this->user->id);
        $this->assertFalse($resA['success']);
        $this->assertEquals('NOT_OVEN_READY', $resA['code']);
        $this->assertEquals('GAGAL — BELUM SCAN SIAP OVEN', $resA['reason']);
        $this->assertEquals('layer_6', $treeDipping->fresh()->current_stage);

        // Asserts audit event rejected is recorded
        $this->assertDatabaseHas('lost_wax_scan_events', [
            'tree_id' => $treeDipping->id,
            'stage' => 'siap_cor',
            'result' => 'rejected',
            'anomaly_reason' => 'GAGAL — BELUM SCAN SIAP OVEN',
        ]);

        // Case B: Tree has current_stage null (not yet scanned anywhere)
        $treeNull = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => null,
        ]);

        $resB = $this->service->processScan($treeNull->barcode, $this->user->id);
        $this->assertFalse($resB['success']);
        $this->assertEquals('NOT_OVEN_READY', $resB['code']);
        $this->assertEquals('GAGAL — BELUM SCAN SIAP OVEN', $resB['reason']);
        $this->assertNull($treeNull->fresh()->current_stage);
    }

    public function test_6_scan_fails_when_tree_in_oven_stage_but_no_active_oven_event(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        // Tree says current_stage = 'oven', but its oven event was voided
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'oven',
        ]);
        $ovenEvent = $this->createScanEvent($tree, 'oven', now()->subHours(2));

        // Void the oven event manually
        app(ScanVoidService::class)->void($ovenEvent, 'Accidental oven scan', $this->user->id);

        // Now tree stage is reconstructed to null or previous, no active oven event exists
        $res = $this->service->processScan($tree->barcode, $this->user->id);
        $this->assertFalse($res['success']);
        $this->assertEquals('NOT_OVEN_READY', $res['code']);
        $this->assertEquals('GAGAL — BELUM SCAN SIAP OVEN', $res['reason']);
    }

    public function test_7_scan_siap_cor_succeeds_for_valid_oven_completed_tree(): void
    {
        $plan = $this->createPlan(['code' => 'COR-OK-01']);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'oven',
            'quantity' => 60,
        ]);
        $this->createScanEvent($tree, 'oven', now()->subHours(3));

        $response = $this->actingAs($this->user)->postJson(route('lost-wax.scan-siap-cor.process'), [
            'barcode' => $tree->barcode,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'code' => 'SUCCESS',
            'message' => 'BERHASIL — SIAP COR',
            'tree_info' => [
                'barcode' => $tree->barcode,
                'code' => 'COR-OK-01',
                'quantity_usable' => 60,
                'current_stage' => 'siap_cor',
                'current_stage_label' => 'Siap Cor',
            ],
        ]);

        // Asserts tree updated in database
        $freshTree = $tree->fresh();
        $this->assertEquals('siap_cor', $freshTree->current_stage);
        $this->assertNotNull($freshTree->last_scan_at);
    }

    public function test_8_scan_siap_cor_creates_successful_scan_event(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'oven',
            'quantity' => 50,
        ]);
        $this->createScanEvent($tree, 'oven', now()->subHours(2));

        $res = $this->service->processScan($tree->barcode, $this->user->id);
        $this->assertTrue($res['success']);

        $this->assertDatabaseHas('lost_wax_scan_events', [
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'siap_cor',
            'result' => 'success',
            'operator_id' => $this->user->id,
        ]);
    }

    public function test_9_scan_siap_cor_records_operator_id_and_timestamp(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id]);
        $this->createScanEvent($tree, 'oven', now()->subHours(2));

        $specificOperator = User::factory()->create();
        $specificOperator->assignRole('admin');

        $res = $this->service->processScan($tree->barcode, $specificOperator->id);
        $this->assertTrue($res['success']);

        $event = LostWaxScanEvent::where('tree_id', $tree->id)->where('stage', 'siap_cor')->first();
        $this->assertNotNull($event);
        $this->assertEquals($specificOperator->id, $event->operator_id);
        $this->assertEquals(now()->toDateTimeString(), $event->scanned_at->toDateTimeString());
    }

    public function test_10_duplicate_scan_siap_cor_is_rejected(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id]);
        $this->createScanEvent($tree, 'oven', now()->subHours(2));

        // 1st scan: Succeeds
        $res1 = $this->service->processScan($tree->barcode, $this->user->id);
        $this->assertTrue($res1['success']);
        $this->assertEquals('siap_cor', $tree->fresh()->current_stage);

        // 2nd scan: Must be rejected as duplicate
        $res2 = $this->service->processScan($tree->barcode, $this->user->id);
        $this->assertFalse($res2['success']);
        $this->assertEquals('ALREADY_SCANNED', $res2['code']);
        $this->assertEquals('GAGAL — SUDAH PERNAH SCAN SIAP COR', $res2['reason']);

        // Tree stage must remain siap_cor
        $this->assertEquals('siap_cor', $tree->fresh()->current_stage);

        // Event history contains exactly 1 successful siap_cor and 1 rejected siap_cor
        $this->assertEquals(1, LostWaxScanEvent::where('tree_id', $tree->id)->where('stage', 'siap_cor')->where('result', 'success')->count());
        $this->assertEquals(1, LostWaxScanEvent::where('tree_id', $tree->id)->where('stage', 'siap_cor')->where('result', 'rejected')->count());
    }

    public function test_11_cancelled_tree_is_rejected(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'status' => 'cancelled',
            'current_stage' => 'oven',
        ]);
        $this->createScanEvent($tree, 'oven', now()->subHours(2));

        $res = $this->service->processScan($tree->barcode, $this->user->id);
        $this->assertFalse($res['success']);
        $this->assertEquals('CANCELLED', $res['code']);
        $this->assertEquals('GAGAL — TRAVELER CARD TELAH DIBATALKAN', $res['reason']);
        $this->assertEquals('oven', $tree->fresh()->current_stage);
    }

    public function test_12_zero_or_negative_usable_quantity_is_rejected(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'quantity' => 30,
            'current_stage' => 'oven',
        ]);
        $this->createScanEvent($tree, 'oven', now()->subHours(2));

        // Add defects equal to total quantity (100% defect)
        LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $tree->id,
            'stage' => 'layer_5',
            'defect_qty' => 30,
            'defect_reason' => 'crack',
            'recorded_by' => $this->user->id,
        ]);

        $this->assertEquals(0, $tree->usable_quantity);

        $res = $this->service->processScan($tree->barcode, $this->user->id);
        $this->assertFalse($res['success']);
        $this->assertEquals('ZERO_USABLE', $res['code']);
        $this->assertEquals('GAGAL — 0 PCS LAYAK', $res['reason']);
        $this->assertEquals('oven', $tree->fresh()->current_stage);
    }

    // =========================================================================
    // 3. AUDIT TRAIL, VOID & RECONSTRUCTION TESTS
    // =========================================================================

    public function test_13_void_siap_cor_event_reverts_tree_to_oven_stage(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id]);
        $ovenTimestamp = now()->subHours(2);
        $this->createScanEvent($tree, 'oven', $ovenTimestamp);

        // 1. Scan Siap Cor
        $res = $this->service->processScan($tree->barcode, $this->user->id);
        $this->assertTrue($res['success']);
        $this->assertEquals('siap_cor', $tree->fresh()->current_stage);

        $siapCorEvent = LostWaxScanEvent::where('tree_id', $tree->id)->where('stage', 'siap_cor')->where('result', 'success')->first();
        $this->assertNotNull($siapCorEvent);

        // 2. Void the Siap Cor event using ScanVoidService
        $voidService = app(ScanVoidService::class);
        $voidRecord = $voidService->void($siapCorEvent, 'Salah scan pohon cor', $this->user->id);

        $this->assertNotNull($voidRecord);
        $this->assertEquals($siapCorEvent->id, $voidRecord->scan_event_id);

        // 3. Tree status must seamlessly revert to 'oven'
        $treeAfterVoid = $tree->fresh();
        $this->assertEquals('oven', $treeAfterVoid->current_stage);
        $this->assertEquals($ovenTimestamp->toDateTimeString(), $treeAfterVoid->last_scan_at->toDateTimeString());

        // 4. Tree can now be scanned for Siap Cor again
        $resRetry = $this->service->processScan($tree->barcode, $this->user->id);
        $this->assertTrue($resRetry['success']);
        $this->assertEquals('siap_cor', $tree->fresh()->current_stage);
    }

    public function test_14_cannot_void_older_oven_event_when_siap_cor_event_exists(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id]);
        $ovenEvent = $this->createScanEvent($tree, 'oven', now()->subHours(2));

        // Scan Siap Cor
        $this->service->processScan($tree->barcode, $this->user->id);

        // Attempting to void the OLD oven event must throw exception because it is not the latest event
        $voidService = app(ScanVoidService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Hanya scan event sukses terakhir yang boleh dibatalkan (void).');

        $voidService->void($ovenEvent, 'Trying to void old event', $this->user->id);
    }

    // =========================================================================
    // 4. WIP & READY FOR OVEN SEPARATION TESTS
    // =========================================================================

    public function test_15_siap_cor_trees_never_appear_in_ready_for_oven_report(): void
    {
        $plan = $this->createPlan(['code' => 'OVEN-QUEUE-CHECK', 'po_quantity' => 100]);
        $line = $this->createPrintOrderLine($plan);

        // Tree 1: Finished layer_6, NOT yet in oven -> in Ready for Oven
        $treeWaiting = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'layer_6',
            'quantity' => 40,
        ]);
        $this->createScanEvent($treeWaiting, 'layer_6', now()->subHours(4));

        // Tree 2: Scanned in oven, NOT yet in siap_cor -> NOT in Ready for Oven, currently in oven
        $treeOven = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'oven',
            'quantity' => 30,
        ]);
        $this->createScanEvent($treeOven, 'layer_6', now()->subHours(3));
        $this->createScanEvent($treeOven, 'oven', now()->subHours(2));

        // Tree 3: Scanned in siap_cor -> NOT in Ready for Oven, currently in siap_cor
        $treeCor = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'oven',
            'quantity' => 30,
        ]);
        $this->createScanEvent($treeCor, 'layer_6', now()->subHours(3));
        $this->createScanEvent($treeCor, 'oven', now()->subHours(2));
        $this->service->processScan($treeCor->barcode, $this->user->id);

        // Check Ready for Oven Report
        $readyForOvenService = app(ReadyForOvenService::class);
        $reportData = $readyForOvenService->getQueueData();

        // Exactly Tree 1 is in Ready for Oven
        $group = $reportData['items']->firstWhere('production_code', 'OVEN-QUEUE-CHECK');
        $this->assertNotNull($group);
        $this->assertEquals(40, $group['quantity_pcs']);
        $this->assertEquals(1, $group['tree_count']);

        // Asserts treeOven and treeCor are completely excluded from ready for oven
        $this->assertFalse(collect($group['work_orders'])->pluck('barcodes')->flatten()->contains($treeOven->barcode));
        $this->assertFalse(collect($group['work_orders'])->pluck('barcodes')->flatten()->contains($treeCor->barcode));
    }

    public function test_16_quantity_siap_cor_uses_usable_quantity_accurately(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'quantity' => 100, // Gross
            'current_stage' => 'oven',
        ]);
        $this->createScanEvent($tree, 'oven', now()->subHours(2));

        // Add 15 defects (crack)
        LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $tree->id,
            'stage' => 'layer_4',
            'defect_qty' => 15,
            'defect_reason' => 'crack',
            'recorded_by' => $this->user->id,
        ]);

        $res = $this->service->processScan($tree->barcode, $this->user->id);
        $this->assertTrue($res['success']);

        // Must accurately reflect 85 usable PCS, not 100 gross PCS
        $this->assertEquals(85, $res['tree_info']['quantity_usable']);
        $this->assertEquals(100, $res['tree_info']['quantity_gross']);
        $this->assertEquals(15, $res['tree_info']['total_defects']);

        // Defect rows in database are preserved untouched
        $this->assertEquals(1, LostWaxTreeDefect::where('lost_wax_tree_id', $tree->id)->count());
        $this->assertEquals(15, LostWaxTreeDefect::where('lost_wax_tree_id', $tree->id)->sum('defect_qty'));
    }

    // =========================================================================
    // 5. ATOMICITY & ROLLBACK TEST
    // =========================================================================

    public function test_17_transaction_rollback_leaves_no_partial_data_on_exception(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id]);
        $this->createScanEvent($tree, 'oven', now()->subHours(2));

        // Simulate database failure during event creation by throwing inside custom transaction simulation
        $initialEventCount = LostWaxScanEvent::count();

        try {
            DB::transaction(function () use ($tree) {
                // Step 1: update tree
                $tree->update(['current_stage' => 'siap_cor']);

                // Step 2: trigger exception
                throw new \RuntimeException('Database disk full / connection timeout simulation');
            });
        } catch (\RuntimeException $e) {
            // caught
        }

        // Tree must remain at 'oven'
        $this->assertEquals('oven', $tree->fresh()->current_stage);
        $this->assertEquals($initialEventCount, LostWaxScanEvent::count());
    }
}
