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
use App\Services\LostWax\DewaxingMonitoringService;
use App\Services\LostWax\ScanSiapCorService;
use App\Services\ScanVoidService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DewaxingMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $unauthorizedUser;

    protected DewaxingMonitoringService $monitoringService;

    protected ScanSiapCorService $scanSiapCorService;

    protected ScanVoidService $scanVoidService;

    protected static int $seq = 5000;

    protected function setUp(): void
    {
        parent::setUp();

        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution']);
        $accessPlanning = Permission::firstOrCreate(['name' => 'access_planning']);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->syncPermissions([$accessExecution, $accessPlanning]);

        $spvRole = Role::firstOrCreate(['name' => 'spv']);
        $spvRole->syncPermissions([$accessExecution]);

        $unauthorizedRole = Role::firstOrCreate(['name' => 'sales']);

        $this->user = User::factory()->create([
            'email' => 'operator_dewaxing@peroniks.com',
        ]);
        $this->user->assignRole('admin');

        $this->unauthorizedUser = User::factory()->create([
            'email' => 'unauth_dewaxing@peroniks.com',
        ]);
        $this->unauthorizedUser->assignRole('sales');

        $this->monitoringService = app(DewaxingMonitoringService::class);
        $this->scanSiapCorService = app(ScanSiapCorService::class);
        $this->scanVoidService = app(ScanVoidService::class);
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        $seq = self::$seq++;

        return ProductionPlan::create(array_merge([
            'code' => 'PLAN-'.$seq,
            'customer' => 'CUST-'.$seq,
            'item_code' => 'ITEM-'.$seq,
            'item_name' => 'Valve Body Test '.$seq,
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
            'barcode' => 'TREE-DWX-'.uniqid().'-'.$currentSeq,
            'tree_number' => $currentSeq,
            'quantity' => 20,
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

    /**
     * Skenario 1: Tree dengan scan oven aktif muncul pada monitoring.
     */
    public function test_01_tree_with_active_oven_scan_appears_on_monitoring(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'oven',
            'last_scan_at' => Carbon::now()->subHours(2),
        ]);
        $this->createScanEvent($tree, 'oven', Carbon::now()->subHours(2));

        $data = $this->monitoringService->getMonitoringData();

        $this->assertCount(1, $data['waiting_items']);
        $this->assertEquals($tree->barcode, $data['waiting_items']->first()['barcode']);
        $this->assertEquals(20, $data['summary']['total_pcs']);
        $this->assertEquals(1, $data['summary']['total_trees']);
    }

    /**
     * Skenario 2: Tree dengan stage Layer 6 atau Layer 7 tidak muncul pada monitoring oven.
     */
    public function test_02_trees_in_layer_stages_do_not_appear_in_dewaxing_queue(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        $treeL6 = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'layer_6',
            'last_scan_at' => Carbon::now()->subHours(1),
        ]);
        $this->createScanEvent($treeL6, 'layer_6', Carbon::now()->subHours(1));

        $treeL7 = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'layer_7',
            'last_scan_at' => Carbon::now()->subHours(1),
        ]);
        $this->createScanEvent($treeL7, 'layer_7', Carbon::now()->subHours(1));

        $data = $this->monitoringService->getMonitoringData();

        $this->assertCount(0, $data['waiting_items']);
        $this->assertEquals(0, $data['summary']['total_trees']);
    }

    /**
     * Skenario 3: Tree dengan scan selesai dewaxing aktif tidak muncul pada antrean oven.
     */
    public function test_03_tree_with_active_siap_cor_does_not_appear_in_waiting_queue(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'siap_cor',
            'last_scan_at' => Carbon::now()->subMinutes(10),
        ]);
        $this->createScanEvent($tree, 'oven', Carbon::now()->subHours(3));
        $this->createScanEvent($tree, 'siap_cor', Carbon::now()->subMinutes(10));

        $data = $this->monitoringService->getMonitoringData();

        $this->assertCount(0, $data['waiting_items']);
        $this->assertCount(1, $data['completed_items']);
        $this->assertEquals($tree->barcode, $data['completed_items']->first()['barcode']);
    }

    /**
     * Skenario 4: Tree yang scan siap_cor-nya di-void kembali muncul pada antrean oven.
     */
    public function test_04_voiding_siap_cor_event_returns_tree_to_dewaxing_queue(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'siap_cor',
            'last_scan_at' => Carbon::now()->subMinutes(15),
        ]);
        $ovenEvent = $this->createScanEvent($tree, 'oven', Carbon::now()->subHours(4));
        $siapCorEvent = $this->createScanEvent($tree, 'siap_cor', Carbon::now()->subMinutes(15));

        // Prior to void: tree is in completed, not in waiting
        $beforeVoid = $this->monitoringService->getMonitoringData();
        $this->assertCount(0, $beforeVoid['waiting_items']);
        $this->assertCount(1, $beforeVoid['completed_items']);

        // Void the siap_cor event using ScanVoidService
        $this->scanVoidService->void($siapCorEvent, 'Salah scan barcode pohon', $this->user->id);

        $tree->refresh();
        $this->assertEquals('oven', $tree->current_stage);

        // After void: tree is back in waiting, removed from completed
        $afterVoid = $this->monitoringService->getMonitoringData();
        $this->assertCount(1, $afterVoid['waiting_items']);
        $this->assertEquals($tree->barcode, $afterVoid['waiting_items']->first()['barcode']);
        $this->assertCount(0, $afterVoid['completed_items']);
    }

    /**
     * Skenario 5: Durasi setelah void tetap dihitung dari timestamp scan oven asli.
     */
    public function test_05_duration_after_void_uses_original_oven_scan_timestamp(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        $originalOvenTime = Carbon::now()->subHours(5)->subMinutes(30);

        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'siap_cor',
            'last_scan_at' => Carbon::now()->subMinutes(10),
        ]);
        $ovenEvent = $this->createScanEvent($tree, 'oven', $originalOvenTime);
        $siapCorEvent = $this->createScanEvent($tree, 'siap_cor', Carbon::now()->subMinutes(10));

        // Void the siap_cor event
        $this->scanVoidService->void($siapCorEvent, 'Koreksi void', $this->user->id);

        $data = $this->monitoringService->getMonitoringData();
        $waitingItem = $data['waiting_items']->first();

        $this->assertNotNull($waitingItem);
        // Duration should reflect ~5 hours (from original oven time), not 0 minutes
        $this->assertStringContainsString('5 jam', $waitingItem['duration']);
        $this->assertEquals($originalOvenTime->timestamp, $waitingItem['entry_time']->timestamp);
    }

    /**
     * Skenario 6: Tree yang scan oven-nya di-void tidak muncul.
     */
    public function test_06_tree_with_voided_oven_scan_does_not_appear(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'oven',
            'last_scan_at' => Carbon::now()->subHours(2),
        ]);
        $this->createScanEvent($tree, 'layer_6', Carbon::now()->subHours(3));
        $ovenEvent = $this->createScanEvent($tree, 'oven', Carbon::now()->subHours(2));

        // Void the oven scan
        $this->scanVoidService->void($ovenEvent, 'Batal masuk oven', $this->user->id);

        $tree->refresh();
        $this->assertEquals('layer_6', $tree->current_stage);

        $data = $this->monitoringService->getMonitoringData();
        $this->assertCount(0, $data['waiting_items']);
    }

    /**
     * Skenario 7: Tree cancelled tidak muncul.
     */
    public function test_07_cancelled_tree_does_not_appear(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'oven',
            'status' => 'cancelled',
            'last_scan_at' => Carbon::now()->subHours(2),
        ]);
        $this->createScanEvent($tree, 'oven', Carbon::now()->subHours(2));

        $data = $this->monitoringService->getMonitoringData();
        $this->assertCount(0, $data['waiting_items']);
    }

    /**
     * Skenario 8: Setiap Traveler Card hanya muncul satu kali.
     */
    public function test_08_each_traveler_card_appears_strictly_once(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'oven',
            'last_scan_at' => Carbon::now()->subHours(2),
        ]);
        $this->createScanEvent($tree, 'oven', Carbon::now()->subHours(2));

        $data = $this->monitoringService->getMonitoringData();

        $this->assertCount(1, $data['waiting_items']);
        $uniqueBarcodes = $data['waiting_items']->pluck('barcode')->unique();
        $this->assertCount(1, $uniqueBarcodes);
    }

    /**
     * Skenario 9: FIFO mengurutkan tree berdasarkan waktu masuk oven paling lama.
     */
    public function test_09_fifo_orders_oldest_in_oven_first(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        // Tree 1 entered 6 hours ago
        $treeOldest = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'oven',
            'last_scan_at' => Carbon::now()->subHours(6),
        ]);
        $this->createScanEvent($treeOldest, 'oven', Carbon::now()->subHours(6));

        // Tree 2 entered 1 hour ago
        $treeNewest = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'oven',
            'last_scan_at' => Carbon::now()->subHours(1),
        ]);
        $this->createScanEvent($treeNewest, 'oven', Carbon::now()->subHours(1));

        // Tree 3 entered 3 hours ago
        $treeMiddle = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'oven',
            'last_scan_at' => Carbon::now()->subHours(3),
        ]);
        $this->createScanEvent($treeMiddle, 'oven', Carbon::now()->subHours(3));

        $data = $this->monitoringService->getMonitoringData();

        $this->assertCount(3, $data['waiting_items']);
        $this->assertEquals($treeOldest->barcode, $data['waiting_items'][0]['barcode']);
        $this->assertEquals($treeMiddle->barcode, $data['waiting_items'][1]['barcode']);
        $this->assertEquals($treeNewest->barcode, $data['waiting_items'][2]['barcode']);

        // Longest duration in summary must match the first item
        $this->assertEquals($data['waiting_items'][0]['duration'], $data['summary']['longest_duration']);
        $this->assertStringContainsString('6 jam', $data['summary']['longest_duration']);
    }

    /**
     * Skenario 10 & 11: Total pohon & total PCS sesuai jumlah baris dan quantity aktual.
     */
    public function test_10_and_11_total_trees_and_pcs_reconcile_accurately(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        // Tree A: quantity 20, 0 defect => usable 20
        $treeA = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'quantity' => 20,
            'current_stage' => 'oven',
            'last_scan_at' => Carbon::now()->subHours(3),
        ]);
        $this->createScanEvent($treeA, 'oven', Carbon::now()->subHours(3));

        // Tree B: quantity 25, defect 3 => usable 22
        $treeB = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'quantity' => 25,
            'current_stage' => 'oven',
            'last_scan_at' => Carbon::now()->subHours(2),
        ]);
        LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $treeB->id,
            'defect_qty' => 3,
            'defect_reason' => 'Retak permukaan',
            'stage' => 'layer_5',
            'recorded_by' => $this->user->id,
        ]);
        $this->createScanEvent($treeB, 'oven', Carbon::now()->subHours(2));

        $data = $this->monitoringService->getMonitoringData();

        $this->assertEquals(2, $data['summary']['total_trees']);
        $this->assertEquals(42, $data['summary']['total_pcs']); // 20 + 22
    }

    /**
     * Skenario 12 & 13: Pohon selesai dewaxing tampil di tabel riwayat (maksimal 20 terakhir).
     */
    public function test_12_and_13_completed_trees_show_in_history_limited_to_20(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        // Create 25 completed trees
        for ($i = 1; $i <= 25; $i++) {
            $time = Carbon::now()->subMinutes(100 - $i * 2);
            $tree = $this->createTree([
                'lost_wax_print_order_line_id' => $line->id,
                'current_stage' => 'siap_cor',
                'last_scan_at' => $time,
            ]);
            $this->createScanEvent($tree, 'oven', $time->copy()->subHours(2));
            $this->createScanEvent($tree, 'siap_cor', $time);
        }

        $data = $this->monitoringService->getMonitoringData();

        // Exactly 20 records
        $this->assertCount(20, $data['completed_items']);

        // First item is the newest completion
        $this->assertTrue(
            $data['completed_items'][0]['completed_timestamp'] >= $data['completed_items'][1]['completed_timestamp']
        );
    }

    /**
     * Skenario 14: Halaman dapat diakses oleh pengguna yang mempunyai akses Scan Selesai Dewaxing.
     */
    public function test_14_monitoring_page_accessible_by_authorized_user(): void
    {
        $response = $this->actingAs($this->user)->get(route('lost-wax.dewaxing-monitoring'));

        $response->assertStatus(200);
        $response->assertViewIs('lost-wax.dewaxing-monitoring.index');
        $response->assertSee('MONITORING PROSES DEWAXING');
        $response->assertSee('TOTAL POHON DI OVEN');
        $response->assertSee('TOTAL PCS DI OVEN');
        $response->assertSee('POHON TERLAMA DI OVEN');
        $response->assertSee('Antrean Menunggu Selesai Dewaxing');
        $response->assertSee('Riwayat Selesai Dewaxing (20 Terakhir)');
    }

    /**
     * Skenario 15: Pengguna tanpa permission yang sesuai ditolak (403 Forbidden).
     */
    public function test_15_unauthorized_user_is_forbidden(): void
    {
        // Enforce strict Spatie permission check on Gate
        $gate = Gate::getFacadeRoot();
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

        $response = $this->actingAs($this->unauthorizedUser)->get(route('lost-wax.dewaxing-monitoring'));

        $response->assertStatus(403);
    }

    /**
     * Skenario 16: Tombol Monitoring Dewaxing tampil di halaman Scan Selesai Dewaxing.
     */
    public function test_16_monitoring_button_is_rendered_on_scan_siap_cor_page(): void
    {
        $response = $this->actingAs($this->user)->get(route('lost-wax.scan-siap-cor.index'));

        $response->assertStatus(200);
        $response->assertSee(route('lost-wax.dewaxing-monitoring'));
        $response->assertSee('MONITORING PROSES DEWAXING');
    }

    /**
     * Skenario 17: Fungsi scan eksisting tetap berjalan normal.
     */
    public function test_17_existing_scan_siap_cor_processing_remains_fully_functional(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'oven',
            'last_scan_at' => Carbon::now()->subHours(2),
        ]);
        $this->createScanEvent($tree, 'oven', Carbon::now()->subHours(2));

        $response = $this->actingAs($this->user)->postJson(route('lost-wax.scan-siap-cor.process'), [
            'barcode' => $tree->barcode,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $tree->refresh();
        $this->assertEquals('siap_cor', $tree->current_stage);
    }

    /**
     * Skenario 18 (BUG FIX): Tree dengan relasi allocation dimuat tanpa RelationNotFoundException.
     */
    public function test_18_tree_with_allocations_loads_without_relation_not_found_exception(): void
    {
        $plan1 = $this->createPlan(['code' => 'CODE-AL1', 'customer' => 'CUST-1', 'item_name' => 'Item 1']);
        $line1 = $this->createPrintOrderLine($plan1);

        $plan2 = $this->createPlan(['code' => 'CODE-AL2', 'customer' => 'CUST-2', 'item_name' => 'Item 2']);
        $line2 = $this->createPrintOrderLine($plan2);

        $tree = $this->createTree([
            'current_stage' => 'oven',
            'last_scan_at' => Carbon::now()->subHours(1),
            'quantity' => 30,
        ]);
        $this->createScanEvent($tree, 'oven', Carbon::now()->subHours(1));

        // Create 2 allocations on this tree
        LostWaxTreeAllocation::create([
            'lost_wax_tree_id' => $tree->id,
            'lost_wax_print_order_line_id' => $line1->id,
            'allocated_qty' => 15,
        ]);
        LostWaxTreeAllocation::create([
            'lost_wax_tree_id' => $tree->id,
            'lost_wax_print_order_line_id' => $line2->id,
            'allocated_qty' => 15,
        ]);

        // Should execute query without RelationNotFoundException
        $data = $this->monitoringService->getMonitoringData();

        $this->assertCount(1, $data['waiting_items']);
        $item = $data['waiting_items']->first();
        $this->assertEquals($tree->barcode, $item['barcode']);
        $this->assertStringContainsString('CODE-AL1', $item['production_code']);
        $this->assertStringContainsString('CODE-AL2', $item['production_code']);
        $this->assertStringContainsString('Item 1', $item['item_name']);
        $this->assertStringContainsString('Item 2', $item['item_name']);
        $this->assertNotEmpty($item['spk_number']);

        // Verify HTTP endpoint loads with status 200
        $response = $this->actingAs($this->user)->get(route('lost-wax.dewaxing-monitoring'));
        $response->assertStatus(200);
        $response->assertSee('CODE-AL1');
        $response->assertSee('CODE-AL2');
    }

    /**
     * Skenario 19 (BUG FIX): Tree tanpa relasi SPK menampilkan fallback '-' tanpa error.
     */
    public function test_19_tree_without_spk_relation_gracefully_displays_fallback_dash(): void
    {
        // Tree with neither printOrderLine, workOrder, nor allocations
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => null,
            'work_order_id' => null,
            'current_stage' => 'oven',
            'last_scan_at' => Carbon::now()->subMinutes(40),
            'quantity' => 10,
        ]);
        $this->createScanEvent($tree, 'oven', Carbon::now()->subMinutes(40));

        $data = $this->monitoringService->getMonitoringData();

        $this->assertCount(1, $data['waiting_items']);
        $item = $data['waiting_items']->first();
        $this->assertEquals('-', $item['spk_number']);
        $this->assertEquals('-', $item['production_code']);
        $this->assertEquals('-', $item['item_name']);

        $response = $this->actingAs($this->user)->get(route('lost-wax.dewaxing-monitoring'));
        $response->assertStatus(200);
    }

    /**
     * Skenario 20 (PAGINATION): Server-side pagination default 30 per halaman, FIFO, dan navigasi halaman.
     */
    public function test_20_server_side_pagination_defaults_to_30_and_supports_page_query(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);

        // Buat 35 pohon di oven dengan timestamp bertingkat (FIFO)
        for ($i = 1; $i <= 35; $i++) {
            $entryTime = Carbon::now()->subMinutes(100 - $i);
            $tree = $this->createTree([
                'lost_wax_print_order_line_id' => $line->id,
                'current_stage' => 'oven',
                'last_scan_at' => $entryTime,
                'quantity' => 10,
            ]);
            $this->createScanEvent($tree, 'oven', $entryTime);
        }

        // 1. Halaman 1 (default 30 items)
        $dataPage1 = $this->monitoringService->getMonitoringData(30, 1);
        $paginator1 = $dataPage1['waiting_items'];

        $this->assertEquals(35, $paginator1->total());
        $this->assertCount(30, $paginator1);
        $this->assertEquals(1, $paginator1->currentPage());
        $this->assertEquals(2, $paginator1->lastPage());
        $this->assertEquals(35, $dataPage1['summary']['total_trees']);
        $this->assertEquals(350, $dataPage1['summary']['total_pcs']); // 35 * 10

        // 2. Halaman 2 (?page=2)
        $dataPage2 = $this->monitoringService->getMonitoringData(30, 2);
        $paginator2 = $dataPage2['waiting_items'];

        $this->assertCount(5, $paginator2);
        $this->assertEquals(2, $paginator2->currentPage());

        // 3. Verifikasi HTTP Endpoint render informasi pagination
        $response = $this->actingAs($this->user)->get(route('lost-wax.dewaxing-monitoring'));
        $response->assertStatus(200);
        $response->assertSee('Menampilkan:');
        $response->assertSee('1&ndash;30', false);
        $response->assertSee('dari <strong class="text-slate-800">35 Pohon</strong>', false);

        // HTTP Endpoint Halaman 2
        $responsePage2 = $this->actingAs($this->user)->get(route('lost-wax.dewaxing-monitoring', ['page' => 2]));
        $responsePage2->assertStatus(200);
        $responsePage2->assertSee('31&ndash;35', false);
    }
}
