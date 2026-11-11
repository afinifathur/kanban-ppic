<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\LostWaxTreeAllocation;
use App\Models\LostWaxTreeDefect;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWax\ReadyForCastingService;
use App\Services\ScanVoidService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReadyForCastingReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $ppicUser;

    protected User $spvUser;

    protected User $qcFittingUser;

    protected User $unauthorizedUser;

    protected ReadyForCastingService $service;

    protected static int $seq = 5000;

    protected function setUp(): void
    {
        parent::setUp();

        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution']);
        $accessPlanning = Permission::firstOrCreate(['name' => 'access_planning']);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->syncPermissions([$accessExecution, $accessPlanning]);

        $ppicRole = Role::firstOrCreate(['name' => 'ppic']);
        $ppicRole->givePermissionTo($accessExecution);

        $spvRole = Role::firstOrCreate(['name' => 'spv']);
        $spvRole->givePermissionTo($accessExecution);

        $qcRole = Role::firstOrCreate(['name' => 'admin_qc_fitting']);
        $qcRole->syncPermissions([$accessExecution, $accessPlanning]);

        $unauthorizedRole = Role::firstOrCreate(['name' => 'sales']);

        $this->adminUser = User::factory()->create(['email' => 'admin@peroniks.com']);
        $this->adminUser->assignRole('admin');

        $this->ppicUser = User::factory()->create(['email' => 'ppic@peroniks.com']);
        $this->ppicUser->assignRole('ppic');

        $this->spvUser = User::factory()->create(['email' => 'spv@peroniks.com']);
        $this->spvUser->assignRole('spv');

        $this->qcFittingUser = User::factory()->create(['email' => 'qcfitting@peroniks.com']);
        $this->qcFittingUser->assignRole('admin_qc_fitting');

        $this->unauthorizedUser = User::factory()->create(['email' => 'sales@peroniks.com']);
        $this->unauthorizedUser->assignRole('sales');

        $this->service = app(ReadyForCastingService::class);
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        $seq = self::$seq++;

        return ProductionPlan::create(array_merge([
            'code' => 'CODE-'.$seq,
            'customer' => 'CUST-'.$seq,
            'item_code' => 'ITEM-'.$seq,
            'item_name' => 'Flange Casting '.$seq,
            'aisi' => 'CF8M',
            'size' => '2 Inch',
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
            'created_by' => $this->adminUser->id,
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
            'barcode' => 'P'.now()->format('ymd').str_pad((string) $currentSeq, 4, '0', STR_PAD_LEFT),
            'tree_number' => $currentSeq,
            'quantity' => 50,
            'status' => 'generated',
            'current_stage' => 'siap_cor',
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
            'operator_id' => $this->adminUser->id,
            'result' => $result,
        ]);
    }

    // =========================================================================
    // 1. KELAYAKAN DATA & WIP
    // =========================================================================

    public function test_1_valid_traveler_card_with_successful_scan_siap_cor_appears(): void
    {
        $plan = $this->createPlan(['code' => '268IL435', 'customer' => 'PT PINDAD']);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'quantity' => 48,
        ]);
        $this->createScanEvent($tree, 'siap_cor', now()->subHours(2));

        $data = $this->service->getQueueData();

        $this->assertCount(1, $data['items']);
        $this->assertEquals(1, $data['summary']['total_trees']);
        $this->assertEquals(48, $data['summary']['total_usable_pcs']);
        $this->assertEquals('268IL435', $data['items']->first()['production_code']);

        // Check Web endpoint
        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.reports.ready-for-casting.index'));
        $response->assertStatus(200);
        $response->assertSee($tree->barcode);
        $response->assertSee('268IL435');
        $response->assertSee('48 PCS');
    }

    public function test_2_tree_not_yet_scanned_siap_cor_does_not_appear(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        // Tree is at oven stage, hasn't scanned siap_cor
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'oven',
            'quantity' => 48,
        ]);
        $this->createScanEvent($tree, 'oven', now()->subHours(4));

        $data = $this->service->getQueueData();
        $this->assertCount(0, $data['items']);
    }

    public function test_3_tree_with_voided_siap_cor_event_does_not_appear(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'siap_cor',
            'quantity' => 48,
        ]);
        // Valid oven event, then siap_cor event
        $this->createScanEvent($tree, 'oven', now()->subHours(5));
        $siapCorEvent = $this->createScanEvent($tree, 'siap_cor', now()->subHours(2));

        // Void the siap_cor event
        app(ScanVoidService::class)->void($siapCorEvent, 'Accidental scan', $this->adminUser->id);

        $data = $this->service->getQueueData();
        $this->assertCount(0, $data['items']);
        $this->assertEquals(0, $data['summary']['total_trees']);
    }

    public function test_4_cancelled_tree_does_not_appear(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'status' => 'cancelled',
            'current_stage' => 'siap_cor',
            'quantity' => 48,
        ]);
        $this->createScanEvent($tree, 'siap_cor', now()->subHours(1));

        $data = $this->service->getQueueData();
        $this->assertCount(0, $data['items']);
    }

    public function test_5_tree_with_zero_usable_quantity_does_not_appear(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'quantity' => 24,
        ]);
        $this->createScanEvent($tree, 'siap_cor', now()->subHours(1));

        // 100% defect
        LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $tree->id,
            'defect_qty' => 24,
            'defect_reason' => 'pecah',
            'stage' => 'oven',
            'recorded_by' => $this->adminUser->id,
        ]);

        $data = $this->service->getQueueData();
        $this->assertCount(0, $data['items']);
    }

    public function test_6_tree_with_defect_uses_correct_usable_quantity(): void
    {
        $plan = $this->createPlan(['code' => 'CODE-DEFECT']);
        $line = $this->createPrintOrderLine($plan);

        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'quantity' => 50,
        ]);
        $this->createScanEvent($tree, 'siap_cor', now()->subHours(1));

        // 12 defect pcs -> usable = 38
        LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $tree->id,
            'defect_qty' => 12,
            'defect_reason' => 'gompal',
            'stage' => 'oven',
            'recorded_by' => $this->adminUser->id,
        ]);

        $data = $this->service->getQueueData();
        $this->assertCount(1, $data['items']);
        $this->assertEquals(38, $data['items']->first()['quantity_pcs']);
        $this->assertEquals(50, $data['items']->first()['gross_pcs']);
        $this->assertEquals(12, $data['items']->first()['defect_pcs']);
        $this->assertEquals(38, $data['summary']['total_usable_pcs']);
    }

    // =========================================================================
    // 2. FIFO & FILTERING
    // =========================================================================

    public function test_7_fifo_ordered_by_active_scan_siap_cor_timestamp(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        // Tree A scanned earliest (08:00)
        $treeA = $this->createTree(['lost_wax_print_order_line_id' => $line->id]);
        $this->createScanEvent($treeA, 'siap_cor', now()->startOfDay()->addHours(8));

        // Tree B scanned latest (12:00)
        $treeB = $this->createTree(['lost_wax_print_order_line_id' => $line->id]);
        $this->createScanEvent($treeB, 'siap_cor', now()->startOfDay()->addHours(12));

        // Tree C scanned in between (10:00)
        $treeC = $this->createTree(['lost_wax_print_order_line_id' => $line->id]);
        $this->createScanEvent($treeC, 'siap_cor', now()->startOfDay()->addHours(10));

        $data = $this->service->getQueueData();

        $this->assertCount(3, $data['items']);
        $barcodes = $data['items']->pluck('barcode')->all();

        // Must be in chronological FIFO order: Tree A, Tree C, Tree B
        $this->assertEquals([$treeA->barcode, $treeC->barcode, $treeB->barcode], $barcodes);
    }

    public function test_8_filter_kode_produksi_works(): void
    {
        $planA = $this->createPlan(['code' => 'CODE-AAA']);
        $lineA = $this->createPrintOrderLine($planA);
        $treeA = $this->createTree(['lost_wax_print_order_line_id' => $lineA->id]);
        $this->createScanEvent($treeA, 'siap_cor', now()->subHours(3));

        $planB = $this->createPlan(['code' => 'CODE-BBB']);
        $lineB = $this->createPrintOrderLine($planB);
        $treeB = $this->createTree(['lost_wax_print_order_line_id' => $lineB->id]);
        $this->createScanEvent($treeB, 'siap_cor', now()->subHours(2));

        $data = $this->service->getQueueData(codes: ['CODE-AAA']);

        $this->assertCount(1, $data['items']);
        $this->assertEquals($treeA->barcode, $data['items']->first()['barcode']);
        $this->assertTrue($data['summary']['is_filtered']);
    }

    public function test_9_filter_customer_works(): void
    {
        $planA = $this->createPlan(['customer' => 'PT ASTRA']);
        $lineA = $this->createPrintOrderLine($planA);
        $treeA = $this->createTree(['lost_wax_print_order_line_id' => $lineA->id]);
        $this->createScanEvent($treeA, 'siap_cor', now()->subHours(3));

        $planB = $this->createPlan(['customer' => 'PT BARATA']);
        $lineB = $this->createPrintOrderLine($planB);
        $treeB = $this->createTree(['lost_wax_print_order_line_id' => $lineB->id]);
        $this->createScanEvent($treeB, 'siap_cor', now()->subHours(2));

        $data = $this->service->getQueueData(customers: ['PT BARATA']);

        $this->assertCount(1, $data['items']);
        $this->assertEquals($treeB->barcode, $data['items']->first()['barcode']);
    }

    public function test_10_search_barcode_works(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        $treeTarget = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => 'TARGET-BARCODE-999',
        ]);
        $this->createScanEvent($treeTarget, 'siap_cor', now()->subHours(3));

        $treeOther = $this->createTree(['lost_wax_print_order_line_id' => $line->id]);
        $this->createScanEvent($treeOther, 'siap_cor', now()->subHours(2));

        $data = $this->service->getQueueData(search: 'TARGET-BARCODE');

        $this->assertCount(1, $data['items']);
        $this->assertEquals('TARGET-BARCODE-999', $data['items']->first()['barcode']);
    }

    public function test_11_combined_filters_work_with_and_logic(): void
    {
        $planA = $this->createPlan(['code' => 'CODE-MATCH', 'customer' => 'CUST-MATCH']);
        $lineA = $this->createPrintOrderLine($planA);
        $treeA = $this->createTree(['lost_wax_print_order_line_id' => $lineA->id, 'barcode' => 'BC-MATCH-01']);
        $this->createScanEvent($treeA, 'siap_cor', now()->subHours(3));

        // Matches code but wrong customer
        $planB = $this->createPlan(['code' => 'CODE-MATCH', 'customer' => 'CUST-WRONG']);
        $lineB = $this->createPrintOrderLine($planB);
        $treeB = $this->createTree(['lost_wax_print_order_line_id' => $lineB->id, 'barcode' => 'BC-MATCH-02']);
        $this->createScanEvent($treeB, 'siap_cor', now()->subHours(2));

        $data = $this->service->getQueueData(
            search: 'BC-MATCH',
            codes: ['CODE-MATCH'],
            customers: ['CUST-MATCH']
        );

        $this->assertCount(1, $data['items']);
        $this->assertEquals('BC-MATCH-01', $data['items']->first()['barcode']);
    }

    public function test_12_multi_allocation_tree_does_not_duplicate_rows_or_pcs(): void
    {
        $planA = $this->createPlan(['code' => 'ALLOC-X']);
        $lineA = $this->createPrintOrderLine($planA);

        $planB = $this->createPlan(['code' => 'ALLOC-Y']);
        $lineB = $this->createPrintOrderLine($planB);

        $tree = $this->createTree([
            'quantity' => 33,
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

        // Defect 3 pcs -> usable = 30 pcs
        LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $tree->id,
            'defect_qty' => 3,
            'defect_reason' => 'damage',
            'stage' => 'oven',
            'recorded_by' => $this->adminUser->id,
        ]);

        $this->createScanEvent($tree, 'siap_cor', now()->subHours(2));

        $data = $this->service->getQueueData();

        // 1 physical tree MUST be represented as EXACTLY 1 row
        $this->assertCount(1, $data['items']);
        $this->assertEquals(1, $data['summary']['total_trees']);
        $this->assertEquals(30, $data['summary']['total_usable_pcs']);
        $this->assertEquals(33, $data['summary']['total_gross_pcs']);
        $this->assertEquals(3, $data['summary']['total_defect_pcs']);

        $row = $data['items']->first();
        $this->assertTrue($row['is_multi_allocated']);
        $this->assertCount(2, $row['codes']);
        $this->assertContains('ALLOC-X', $row['codes']);
        $this->assertContains('ALLOC-Y', $row['codes']);
    }

    // =========================================================================
    // 3. WIP INTEGRITY (READ-ONLY) & ACCESS PERMISSION
    // =========================================================================

    public function test_13_web_page_does_not_alter_wip(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 48]);
        $event = $this->createScanEvent($tree, 'siap_cor', now()->subHours(1));

        $initialEventCount = LostWaxScanEvent::count();

        // Visit web report
        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.reports.ready-for-casting.index'));
        $response->assertStatus(200);

        // Verify zero database changes
        $this->assertEquals($initialEventCount, LostWaxScanEvent::count());
        $freshTree = $tree->fresh();
        $this->assertEquals('siap_cor', $freshTree->current_stage);
        $this->assertEquals('generated', $freshTree->status);
        $this->assertEquals(48, $freshTree->quantity);
    }

    public function test_14_print_page_does_not_alter_wip(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 48]);
        $event = $this->createScanEvent($tree, 'siap_cor', now()->subHours(1));

        $initialEventCount = LostWaxScanEvent::count();

        // Visit print document
        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.reports.ready-for-casting.print'));
        $response->assertStatus(200);
        $response->assertSee('PT. PERONI KARYA SENTRA');
        $response->assertSee('LAPORAN BARANG SIAP COR');
        $response->assertSee('HEAT NUMBER');

        // Verify zero database changes
        $this->assertEquals($initialEventCount, LostWaxScanEvent::count());
        $freshTree = $tree->fresh();
        $this->assertEquals('siap_cor', $freshTree->current_stage);
        $this->assertEquals('generated', $freshTree->status);
        $this->assertEquals(48, $freshTree->quantity);
    }

    public function test_15_role_permission_and_access_control(): void
    {
        // Replace TestCase's unconditional bypass with Spatie's real permission evaluation
        $gate = \Illuminate\Support\Facades\Gate::getFacadeRoot();
        $ref = new \ReflectionClass($gate);
        $prop = $ref->getProperty('beforeCallbacks');
        $prop->setAccessible(true);
        $prop->setValue($gate, [
            function ($user, $ability) {
                if (method_exists($user, 'hasPermissionTo')) {
                    try {
                        return $user->hasPermissionTo($ability) ? true : null;
                    } catch (\Throwable $e) {
                        return null;
                    }
                }

                return null;
            },
        ]);

        // 1. Unauthorized role (sales) receives 403 Forbidden
        $responseUnauthIndex = $this->actingAs($this->unauthorizedUser)->get(route('lost-wax.reports.ready-for-casting.index'));
        $responseUnauthIndex->assertStatus(403);

        $responseUnauthPrint = $this->actingAs($this->unauthorizedUser)->get(route('lost-wax.reports.ready-for-casting.print'));
        $responseUnauthPrint->assertStatus(403);

        // 2. Authorized roles can access with 200 OK
        $this->actingAs($this->adminUser)->get(route('lost-wax.reports.ready-for-casting.index'))->assertStatus(200);
        $this->actingAs($this->ppicUser)->get(route('lost-wax.reports.ready-for-casting.index'))->assertStatus(200);
        $this->actingAs($this->spvUser)->get(route('lost-wax.reports.ready-for-casting.index'))->assertStatus(200);
        $this->actingAs($this->qcFittingUser)->get(route('lost-wax.reports.ready-for-casting.index'))->assertStatus(200);
    }

    public function test_16_print_page_groups_trees_by_production_code_and_item(): void
    {
        // 1. Group A: 3 trees with same plan (2610AB58) and item (STUB END 3") with varying quantities (8, 10, 6 pcs) -> 24 pcs total
        $planA = $this->createPlan([
            'code' => '2610AB58',
            'customer' => 'PT ALPHA MEGA',
            'item_name' => 'A06 • SS304 BUTT WELD STUB END SCH40 3"',
            'aisi' => 'SS304',
        ]);
        $lineA = $this->createPrintOrderLine($planA);

        $treeA1 = $this->createTree(['lost_wax_print_order_line_id' => $lineA->id, 'quantity' => 8]);
        $this->createScanEvent($treeA1, 'siap_cor', now()->subHours(3));

        $treeA2 = $this->createTree(['lost_wax_print_order_line_id' => $lineA->id, 'quantity' => 10]);
        $this->createScanEvent($treeA2, 'siap_cor', now()->subHours(2));

        $treeA3 = $this->createTree(['lost_wax_print_order_line_id' => $lineA->id, 'quantity' => 6]);
        $this->createScanEvent($treeA3, 'siap_cor', now()->subHours(1));

        // 2. Group B: 1 tree with different plan (2610CD99) and item (VALVE BODY 2"), 15 pcs
        $planB = $this->createPlan([
            'code' => '2610CD99',
            'customer' => 'PT BETA TEKNIK',
            'item_name' => 'B12 • VALVE BODY 2"',
            'aisi' => 'CF8M',
        ]);
        $lineB = $this->createPrintOrderLine($planB);

        $treeB1 = $this->createTree(['lost_wax_print_order_line_id' => $lineB->id, 'quantity' => 15]);
        $this->createScanEvent($treeB1, 'siap_cor', now()->subMinutes(30));

        // Visit print document
        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.reports.ready-for-casting.print'));
        $response->assertStatus(200);

        // Header check
        $response->assertSee('LAPORAN BARANG SIAP COR');
        $response->assertSee('HEAT NUMBER');

        // Group A assertions: sum 24 PCS, (3 Pohon), code and item visible
        $response->assertSee('2610AB58');
        $response->assertSee('A06 • SS304 BUTT WELD STUB END SCH40 3"');
        $response->assertSee('24');
        $response->assertSee('(3 Pohon)');

        // Individual Traveler Card and their own respective quantity
        $response->assertSee($treeA1->human_barcode ?? $treeA1->barcode);
        $response->assertSee($treeA2->human_barcode ?? $treeA2->barcode);
        $response->assertSee($treeA3->human_barcode ?? $treeA3->barcode);
        $response->assertSee('—');

        // Group B assertions: 15 PCS, single tree
        $response->assertSee('2610CD99');
        $response->assertSee('B12 • VALVE BODY 2"');
        $response->assertSee('15');
        $response->assertSee($treeB1->human_barcode ?? $treeB1->barcode);

        // Summary totals: 4 trees physical, 39 PCS total (8 + 10 + 6 + 15)
        $response->assertSee('4 Pohon');
        $response->assertSee('39 PCS');

        // Verify long notes paragraph is absent
        $response->assertDontSee('LEMBAR KONTROL PENGECORAN — PERSEDIAAN PASCA DEWAXING');
        $response->assertDontSee('Dokumen ini mencatat seluruh Traveler Card');
    }
}
