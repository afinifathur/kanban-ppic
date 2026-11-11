<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWax\ReadyForOvenService;
use App\Services\ScanVoidService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReadyForOvenPhaseATest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected ReadyForOvenService $service;

    protected static int $sequence = 500;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'access_execution']);
        Permission::firstOrCreate(['name' => 'access_planning']);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->syncPermissions(['access_execution', 'access_planning']);

        $this->adminUser = User::factory()->create([
            'email' => 'admin_phase_a@peroniks.com',
        ]);
        $this->adminUser->assignRole('admin');

        $this->service = app(ReadyForOvenService::class);
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        $seq = self::$sequence++;

        return ProductionPlan::create(array_merge([
            'code' => 'PLAN-A-'.$seq,
            'customer' => 'CUST-A-'.$seq,
            'item_code' => 'ITEM-A-'.$seq,
            'item_name' => 'Flange Phase A '.$seq,
            'aisi' => '304',
            'size' => '2"',
            'weight' => 2.50,
            'po_number' => 'PO-A-'.$seq,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'status' => 'planning',
        ], $attributes));
    }

    protected function createPrintOrderLine(ProductionPlan $plan, array $attributes = []): LostWaxPrintOrderLine
    {
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PO-ORD-A-'.uniqid(),
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
        $seq = self::$sequence++;

        return LostWaxTree::create(array_merge([
            'barcode' => 'TREE-PHA-'.uniqid().'-'.$seq,
            'tree_number' => $seq,
            'quantity' => 20,
            'status' => 'generated',
            'current_stage' => 'layer_6',
            'require_layer_7' => false,
            'production_date' => now()->toDateString(),
            'family_code' => '1',
            'daily_sequence' => $seq,
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

    /**
     * Test 1: Tree Layer 6 muncul pada antrean siap oven.
     */
    public function test_01_tree_layer_6_appears_in_queue(): void
    {
        $plan = $this->createPlan(['code' => 'PLAN-L6']);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'layer_6',
            'require_layer_7' => false,
            'quantity' => 20,
        ]);
        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        $data = $this->service->getQueueData();

        $this->assertEquals(1, $data['summary']['total_unique_trees']);
        $item = $data['items']->firstWhere('production_code', 'PLAN-L6');
        $this->assertNotNull($item);
        $this->assertEquals('layer_6', $item['finished_stage']);
        $this->assertEquals(20, $item['quantity_pcs']);
    }

    /**
     * Test 2: Tree Layer 7 dengan require_layer_7 = true muncul pada antrean.
     */
    public function test_02_tree_layer_7_with_require_layer_7_true_appears(): void
    {
        $plan = $this->createPlan(['code' => 'PLAN-L7-SPEC']);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'layer_7',
            'require_layer_7' => true,
            'quantity' => 18,
        ]);
        $this->createScanEvent($tree, 'layer_7', now()->subHours(1));

        $data = $this->service->getQueueData();

        $this->assertEquals(1, $data['summary']['total_unique_trees']);
        $item = $data['items']->firstWhere('production_code', 'PLAN-L7-SPEC');
        $this->assertNotNull($item);
        $this->assertEquals('layer_7', $item['finished_stage']);
        $this->assertEquals(18, $item['quantity_pcs']);
    }

    /**
     * Test 3: Tree Layer 7 dengan require_layer_7 = false (dinamis tanpa flag) tetap muncul.
     */
    public function test_03_tree_layer_7_with_require_layer_7_false_appears(): void
    {
        $plan = $this->createPlan(['code' => 'PLAN-L7-DYN']);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'layer_7',
            'require_layer_7' => false, // Default false, tapi dilapisi hingga layer 7 di lapangan!
            'quantity' => 24,
        ]);
        $this->createScanEvent($tree, 'layer_7', now()->subHours(1));

        $data = $this->service->getQueueData();

        $this->assertEquals(1, $data['summary']['total_unique_trees']);
        $item = $data['items']->firstWhere('production_code', 'PLAN-L7-DYN');
        $this->assertNotNull($item);
        $this->assertEquals('layer_7', $item['finished_stage']);
        $this->assertEquals(24, $item['quantity_pcs']);
    }

    /**
     * Test 4: Tree yang sudah masuk oven tidak muncul lagi pada antrean siap oven.
     */
    public function test_04_tree_already_in_oven_does_not_appear(): void
    {
        $plan = $this->createPlan(['code' => 'PLAN-OVEN']);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'oven',
            'quantity' => 20,
        ]);
        $this->createScanEvent($tree, 'layer_6', now()->subHours(3));
        $this->createScanEvent($tree, 'oven', now()->subHours(1));

        $data = $this->service->getQueueData();

        $this->assertEquals(0, $data['summary']['total_unique_trees']);
        $this->assertNull($data['items']->firstWhere('production_code', 'PLAN-OVEN'));
    }

    /**
     * Test 5: Tree void atau cancelled tidak muncul.
     */
    public function test_05_cancelled_or_voided_trees_do_not_appear(): void
    {
        // A. Cancelled tree
        $planA = $this->createPlan(['code' => 'PLAN-CANCEL']);
        $lineA = $this->createPrintOrderLine($planA);
        $treeA = $this->createTree([
            'lost_wax_print_order_line_id' => $lineA->id,
            'current_stage' => 'layer_6',
            'status' => 'cancelled',
            'quantity' => 20,
        ]);
        $this->createScanEvent($treeA, 'layer_6', now()->subHours(2));

        // B. Tree with voided final stage scan event
        $planB = $this->createPlan(['code' => 'PLAN-VOID']);
        $lineB = $this->createPrintOrderLine($planB);
        $treeB = $this->createTree([
            'lost_wax_print_order_line_id' => $lineB->id,
            'current_stage' => 'layer_6',
            'quantity' => 20,
        ]);
        $eventB = $this->createScanEvent($treeB, 'layer_6', now()->subHours(2));
        app(ScanVoidService::class)->void($eventB, 'Scan salah input', $this->adminUser->id);

        $data = $this->service->getQueueData();

        $this->assertEquals(0, $data['summary']['total_unique_trees']);
        $this->assertNull($data['items']->firstWhere('production_code', 'PLAN-CANCEL'));
        $this->assertNull($data['items']->firstWhere('production_code', 'PLAN-VOID'));
    }

    /**
     * Test 6: Filter dan grouping tetap berjalan konsisten tanpa duplikasi.
     */
    public function test_06_filter_and_grouping_remain_functional(): void
    {
        $plan1 = $this->createPlan(['code' => 'CODE-GRP-1', 'customer' => 'CUST-A']);
        $line1 = $this->createPrintOrderLine($plan1);
        $tree1 = $this->createTree(['lost_wax_print_order_line_id' => $line1->id, 'current_stage' => 'layer_6', 'quantity' => 10]);
        $this->createScanEvent($tree1, 'layer_6', now()->subHours(3));

        $tree2 = $this->createTree(['lost_wax_print_order_line_id' => $line1->id, 'current_stage' => 'layer_6', 'quantity' => 15]);
        $this->createScanEvent($tree2, 'layer_6', now()->subHours(2));

        $plan2 = $this->createPlan(['code' => 'CODE-GRP-2', 'customer' => 'CUST-B']);
        $line2 = $this->createPrintOrderLine($plan2);
        $tree3 = $this->createTree(['lost_wax_print_order_line_id' => $line2->id, 'current_stage' => 'layer_7', 'require_layer_7' => false, 'quantity' => 20]);
        $this->createScanEvent($tree3, 'layer_7', now()->subHours(1));

        // Total without filter: 2 groups, 3 trees, 45 pcs
        $allData = $this->service->getQueueData();
        $this->assertEquals(3, $allData['summary']['total_unique_trees']);
        $this->assertEquals(45, $allData['summary']['total_usable_pcs']);
        $this->assertEquals(2, $allData['summary']['total_groups']);

        // Filter by single code: CODE-GRP-1
        $filteredData = $this->service->getQueueData(null, null, null, 'all', ['CODE-GRP-1']);
        $this->assertEquals(2, $filteredData['summary']['total_unique_trees']);
        $this->assertEquals(25, $filteredData['summary']['total_usable_pcs']);
        $this->assertEquals(1, $filteredData['summary']['total_groups']);
        $this->assertEquals('CODE-GRP-1', $filteredData['items']->first()['production_code']);
    }

    /**
     * Test 7: KPI tetap konsisten dengan data yang ditampilkan.
     */
    public function test_07_kpi_summary_consistent_with_displayed_data(): void
    {
        $plan = $this->createPlan(['code' => 'CODE-KPI', 'po_quantity' => 50]);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'current_stage' => 'layer_6', 'quantity' => 30]);
        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        $data = $this->service->getQueueData();
        $summary = $data['summary'];

        $this->assertEquals(1, $summary['total_unique_trees']);
        $this->assertEquals(1, $summary['total_rangkai']);
        $this->assertEquals(30, $summary['total_usable_pcs']);
        $this->assertEquals(50, $summary['total_po_pcs']);
        $this->assertEquals('KURANG', $summary['overall_po_variance_status']);
        $this->assertEquals(20, $summary['overall_po_variance_pcs']);
    }

    /**
     * Test 8: Cetak laporan mengikuti hasil filter dan menggunakan judul baru.
     */
    public function test_08_print_report_follows_active_filters(): void
    {
        $plan = $this->createPlan(['code' => 'PRINT-FILTER-1']);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'current_stage' => 'layer_6', 'quantity' => 20]);
        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.reports.ready-for-oven.print', ['codes' => ['PRINT-FILTER-1']]));
        $response->assertOk();
        $response->assertSee('ANTREAN SIAP OVEN DEWAXING');
        $response->assertSee('PRINT-FILTER-1');
    }

    /**
     * Test 9: Label baru tampil pada halaman yang benar (Sidebar & Header Halaman).
     */
    public function test_09_new_terminology_labels_rendered_on_correct_pages(): void
    {
        // 1. Sidebar contains all 4 new labels
        $sidebarResponse = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status'));
        $sidebarResponse->assertOk();
        $sidebarResponse->assertSee('Scan Masuk Oven Dewaxing');
        $sidebarResponse->assertSee('Antrean Siap Oven Dewaxing');
        $sidebarResponse->assertSee('Scan Selesai Dewaxing');
        $sidebarResponse->assertSee('Persediaan Pasca Dewaxing');

        // 2. Scan Masuk Oven Dewaxing page renders new title & subtitle
        $scanOvenResponse = $this->actingAs($this->adminUser)->get(route('lost-wax.scan-oven.index'));
        $scanOvenResponse->assertOk();
        $scanOvenResponse->assertSee('LOST WAX &mdash; SCAN MASUK OVEN DEWAXING', false);
        $scanOvenResponse->assertSee('Scan saat pohon cangkang masuk ke oven untuk proses pelelehan lilin');

        // 3. Antrean Siap Oven Dewaxing page renders new title & subtitle
        $readyOvenResponse = $this->actingAs($this->adminUser)->get(route('lost-wax.reports.ready-for-oven.index'));
        $readyOvenResponse->assertOk();
        $readyOvenResponse->assertSee('LOST WAX &mdash; ANTREAN SIAP OVEN DEWAXING', false);
        $readyOvenResponse->assertSee('Monitoring antrean pohon cangkang yang telah tuntas pelapisan dan siap masuk ke oven untuk proses pelelehan lilin (FIFO)');

        // 4. Scan Selesai Dewaxing page renders new title & subtitle
        $scanSiapCorResponse = $this->actingAs($this->adminUser)->get(route('lost-wax.scan-siap-cor.index'));
        $scanSiapCorResponse->assertOk();
        $scanSiapCorResponse->assertSee('LOST WAX &mdash; SCAN SELESAI DEWAXING', false);
        $scanSiapCorResponse->assertSee('Scan saat pohon keluar dari oven dan ditempatkan di rak buffer');

        // 5. Persediaan Pasca Dewaxing page renders new title & subtitle
        $readyCastingResponse = $this->actingAs($this->adminUser)->get(route('lost-wax.reports.ready-for-casting.index'));
        $readyCastingResponse->assertOk();
        $readyCastingResponse->assertSee('LOST WAX &mdash; PERSEDIAAN PASCA DEWAXING', false);
        $readyCastingResponse->assertSee('Daftar seluruh pohon cangkang yang telah selesai dewaxing dan belum dicor (Buffer Inventory - FIFO)');
    }
}

