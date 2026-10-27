<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxScanEventVoid;
use App\Models\LostWaxTree;
use App\Models\LostWaxTreeAllocation;
use App\Models\LostWaxTreeDefect;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWax\ReadyForOvenService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReadyForOvenReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected ReadyForOvenService $service;

    protected static int $seq = 1;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup permissions & roles
        Permission::firstOrCreate(['name' => 'access_execution']);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->givePermissionTo('access_execution');

        $this->user = User::factory()->create([
            'email' => 'admin_test@peroniks.com',
        ]);
        $this->user->assignRole('admin');

        $this->service = app(ReadyForOvenService::class);
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'code' => 'PLAN-001',
            'customer' => 'CUST-A',
            'item_code' => 'ITEM-001',
            'item_name' => 'Flange 2 Inch',
            'aisi' => '304',
            'size' => '2"',
            'weight' => 1.5,
            'po_number' => 'PO-001',
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
            'barcode' => 'TREE-'.uniqid().'-'.$currentSeq,
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

    /**
     * 1. Pohon enam lapis yang memenuhi syarat muncul.
     */
    public function test_six_layer_tree_meeting_criteria_appears(): void
    {
        $plan = $this->createPlan(['code' => 'PLAN-6L']);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'require_layer_7' => false,
            'current_stage' => 'layer_6',
            'quantity' => 20,
        ]);

        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        $data = $this->service->getQueueData();

        $this->assertEquals(1, $data['summary']['total_unique_trees']);
        $this->assertEquals(1, $data['summary']['total_rangkai']);
        $this->assertEquals(20, $data['summary']['total_usable_pcs']);
        $this->assertEquals('PLAN-6L', $data['items']->first()['production_code']);
        $this->assertEquals('layer_6', $data['items']->first()['finished_stage']);
    }

    /**
     * 2. Pohon tujuh lapis yang belum menyelesaikan Lapisan 7 tidak muncul.
     */
    public function test_seven_layer_tree_not_finished_layer_7_does_not_appear(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'require_layer_7' => true,
            'current_stage' => 'layer_6', // Masih di layer_6 padahal butuh layer_7
        ]);

        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        $data = $this->service->getQueueData();

        $this->assertEquals(0, $data['summary']['total_unique_trees']);
        $this->assertTrue($data['items']->isEmpty());
    }

    /**
     * 3. Pohon tujuh lapis yang telah menyelesaikan Lapisan 7 dapat muncul.
     */
    public function test_seven_layer_tree_finished_layer_7_appears(): void
    {
        $plan = $this->createPlan(['code' => 'PLAN-7L']);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'require_layer_7' => true,
            'current_stage' => 'layer_7',
            'quantity' => 15,
        ]);

        $this->createScanEvent($tree, 'layer_7', now()->subHour());

        $data = $this->service->getQueueData();

        $this->assertEquals(1, $data['summary']['total_unique_trees']);
        $this->assertEquals(1, $data['summary']['total_rangkai']);
        $this->assertEquals(15, $data['summary']['total_usable_pcs']);
        $this->assertEquals('layer_7', $data['items']->first()['finished_stage']);
        $this->assertEquals('PLAN-7L', $data['items']->first()['production_code']);
    }

    /**
     * 4. Pohon cancelled tidak muncul.
     */
    public function test_cancelled_tree_does_not_appear(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'status' => 'cancelled',
            'current_stage' => 'layer_6',
            'require_layer_7' => false,
        ]);

        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        $data = $this->service->getQueueData();

        $this->assertEquals(0, $data['summary']['total_unique_trees']);
    }

    /**
     * 5. Pohon dengan usable quantity nol atau negatif tidak muncul.
     */
    public function test_zero_usable_quantity_tree_does_not_appear(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'layer_6',
            'quantity' => 20,
        ]);

        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        // Catat defect sebanyak 20 pcs (100% rusak)
        LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $tree->id,
            'stage' => 'layer_6',
            'defect_qty' => 20,
            'defect_reason' => 'Rontok Total',
            'recorded_by' => $this->user->id,
            'occurred_at' => now(),
        ]);

        $this->assertEquals(0, $tree->usable_quantity);

        $data = $this->service->getQueueData();

        $this->assertEquals(0, $data['summary']['total_unique_trees']);
    }

    /**
     * 6. Pohon dengan scan Oven sukses aktif tidak muncul.
     */
    public function test_tree_with_active_oven_scan_does_not_appear(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'oven',
            'quantity' => 20,
        ]);

        $this->createScanEvent($tree, 'layer_6', now()->subHours(5));
        $this->createScanEvent($tree, 'oven', now()->subHour());

        $data = $this->service->getQueueData();

        $this->assertEquals(0, $data['summary']['total_unique_trees']);
    }

    /**
     * 7. Pohon dengan scan Oven yang di-void dapat kembali ke antrean jika persyaratan lainnya terpenuhi.
     */
    public function test_tree_with_voided_oven_scan_returns_to_queue(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'layer_6', // Reverted back to layer_6 after void
            'quantity' => 25,
        ]);

        $this->createScanEvent($tree, 'layer_6', now()->subHours(4));
        $ovenEvent = $this->createScanEvent($tree, 'oven', now()->subHour());

        // Void the oven event
        LostWaxScanEventVoid::create([
            'scan_event_id' => $ovenEvent->id,
            'voided_by' => $this->user->id,
            'voided_at' => now(),
            'void_reason' => 'Salah scan barcode ke Oven',
        ]);

        $data = $this->service->getQueueData();

        $this->assertEquals(1, $data['summary']['total_unique_trees']);
        $this->assertEquals(25, $data['summary']['total_usable_pcs']);
    }

    /**
     * 8. Scan gagal atau event void tidak dianggap sebagai penyelesaian lapisan yang valid.
     */
    public function test_failed_or_voided_scan_not_considered_valid_completion(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'layer_6',
            'quantity' => 10,
        ]);

        // Event scan layer 6 rejected (misal interval double scan rejected)
        $this->createScanEvent($tree, 'layer_6', now()->subHours(2), 'rejected');

        $data = $this->service->getQueueData();

        // Tidak ada scan success non-voided untuk layer 6, maka tidak muncul
        $this->assertEquals(0, $data['summary']['total_unique_trees']);
    }

    /**
     * 9. Rescan does not increase barcode count.
     */
    public function test_rescan_does_not_increase_barcode_count(): void
    {
        $plan = $this->createPlan();
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree([
            'barcode' => 'TREE-REPEAT',
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'layer_6',
            'quantity' => 20,
        ]);

        // Scan awal sukses
        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));
        // Scan ulang ditolak (double scan)
        $this->createScanEvent($tree, 'layer_6', now()->subHours(2)->addMinutes(5), 'rejected');

        $data = $this->service->getQueueData();

        $this->assertEquals(1, $data['summary']['total_unique_trees']);
        $this->assertEquals(1, $data['summary']['total_rangkai']);
        $this->assertEquals(1, $data['items']->count());
        $this->assertEquals(20, $data['summary']['total_usable_pcs']);
    }

    /**
     * 10. Multi-allocation does not mix codes, customers, or quantities.
     */
    public function test_multi_allocation_does_not_mix_codes_customers_or_quantities(): void
    {
        $planX = $this->createPlan(['code' => 'CODE-X', 'customer' => 'CUST-X', 'item_name' => 'Item X']);
        $lineX = $this->createPrintOrderLine($planX);

        $planY = $this->createPlan(['code' => 'CODE-Y', 'customer' => 'CUST-Y', 'item_name' => 'Item Y']);
        $lineY = $this->createPrintOrderLine($planY);

        // Tree fisik 1 dengan 2 alokasi
        $tree = $this->createTree([
            'barcode' => 'TREE-MULTI-01',
            'current_stage' => 'layer_6',
            'quantity' => 30,
        ]);

        LostWaxTreeAllocation::create([
            'lost_wax_tree_id' => $tree->id,
            'lost_wax_print_order_line_id' => $lineX->id,
            'allocated_qty' => 10,
        ]);

        LostWaxTreeAllocation::create([
            'lost_wax_tree_id' => $tree->id,
            'lost_wax_print_order_line_id' => $lineY->id,
            'allocated_qty' => 20,
        ]);

        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        $data = $this->service->getQueueData();

        // Fisik pohon tetap 1, tapi baris kelompok ada 2 (karena beda kode)
        $this->assertEquals(1, $data['summary']['total_unique_trees']);
        $this->assertEquals(2, $data['summary']['total_rangkai']);
        $this->assertEquals(2, $data['summary']['total_groups']);
        $this->assertEquals(30, $data['summary']['total_usable_pcs']);

        $rowX = $data['items']->firstWhere('production_code', 'CODE-X');
        $this->assertNotNull($rowX);
        $this->assertEquals('CUST-X', $rowX['customer']);
        $this->assertEquals('Item X', $rowX['item_name']);
        $this->assertEquals(10, $rowX['quantity_pcs']);
        $this->assertTrue($rowX['is_multi_allocated']);

        $rowY = $data['items']->firstWhere('production_code', 'CODE-Y');
        $this->assertNotNull($rowY);
        $this->assertEquals('CUST-Y', $rowY['customer']);
        $this->assertEquals('Item Y', $rowY['item_name']);
        $this->assertEquals(20, $rowY['quantity_pcs']);
        $this->assertTrue($rowY['is_multi_allocated']);
    }

    /**
     * 11. Dua rangkai identik digabung menjadi satu baris dengan total 2 rangkai dan pcs benar (Requirement B).
     */
    public function test_two_identical_trees_merge_into_one_row(): void
    {
        $plan = $this->createPlan([
            'code' => '269ET265',
            'item_name' => 'SS316 UNION RING BSP ET 1"',
            'customer' => 'E02',
        ]);
        $line = $this->createPrintOrderLine($plan);

        // Rangkai 1: 48 pcs
        $tree1 = $this->createTree([
            'barcode' => 'BARCODE-01',
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'layer_6',
            'quantity' => 48,
        ]);
        $this->createScanEvent($tree1, 'layer_6', now()->subHours(3));

        // Rangkai 2: 48 pcs
        $tree2 = $this->createTree([
            'barcode' => 'BARCODE-02',
            'lost_wax_print_order_line_id' => $line->id,
            'current_stage' => 'layer_6',
            'quantity' => 48,
        ]);
        $this->createScanEvent($tree2, 'layer_6', now()->subHours(2));

        $data = $this->service->getQueueData();

        // Harapan: 1 baris kelompok, Jumlah rangkai: 2, Jumlah pcs: 96
        $this->assertEquals(1, $data['summary']['total_groups']);
        $this->assertEquals(2, $data['summary']['total_unique_trees']);
        $this->assertEquals(2, $data['summary']['total_rangkai']);
        $this->assertEquals(96, $data['summary']['total_usable_pcs']);

        $group = $data['items']->first();
        $this->assertEquals('269ET265', $group['production_code']);
        $this->assertEquals('SS316 UNION RING BSP ET 1"', $group['item_name']);
        $this->assertEquals('E02', $group['customer']);
        $this->assertEquals(2, $group['tree_count']);
        $this->assertEquals(96, $group['quantity_pcs']);

        // Ketertelusuran barcode
        $this->assertContains('BARCODE-01', $group['barcodes']);
        $this->assertContains('BARCODE-02', $group['barcodes']);
    }

    /**
     * 12. Sepuluh rangkai identik menjadi satu kelompok dengan 10 rangkai dan total pcs yang benar.
     */
    public function test_ten_identical_trees_merge_into_one_group(): void
    {
        $plan = $this->createPlan(['code' => 'BATCH-10']);
        $line = $this->createPrintOrderLine($plan);

        for ($i = 1; $i <= 10; $i++) {
            $tree = $this->createTree([
                'barcode' => "TREE-TEN-{$i}",
                'lost_wax_print_order_line_id' => $line->id,
                'current_stage' => 'layer_6',
                'quantity' => 20,
            ]);
            $this->createScanEvent($tree, 'layer_6', now()->subHours(10 - $i));
        }

        $data = $this->service->getQueueData();

        $this->assertEquals(1, $data['summary']['total_groups']);
        $this->assertEquals(10, $data['summary']['total_unique_trees']);
        $this->assertEquals(10, $data['summary']['total_rangkai']);
        $this->assertEquals(200, $data['summary']['total_usable_pcs']);

        $group = $data['items']->first();
        $this->assertEquals(10, $group['tree_count']);
        $this->assertEquals(200, $group['quantity_pcs']);
        $this->assertCount(10, $group['barcodes']);
    }

    /**
     * 13. Kode produksi sama tetapi customer berbeda tetap menjadi kelompok berbeda.
     */
    public function test_same_code_different_customer_remain_separate_groups(): void
    {
        $planA = $this->createPlan(['code' => 'SAME-CODE', 'customer' => 'CUST-ALPHA', 'item_name' => 'Item Shared']);
        $lineA = $this->createPrintOrderLine($planA);
        $treeA = $this->createTree(['lost_wax_print_order_line_id' => $lineA->id, 'quantity' => 15]);
        $this->createScanEvent($treeA, 'layer_6', now()->subHours(2));

        $planB = $this->createPlan(['code' => 'SAME-CODE', 'customer' => 'CUST-BETA', 'item_name' => 'Item Shared']);
        $lineB = $this->createPrintOrderLine($planB);
        $treeB = $this->createTree(['lost_wax_print_order_line_id' => $lineB->id, 'quantity' => 25]);
        $this->createScanEvent($treeB, 'layer_6', now()->subHours(2));

        $data = $this->service->getQueueData();

        // 2 kelompok terpisah karena customer berbeda
        $this->assertEquals(2, $data['summary']['total_groups']);
        $this->assertEquals(2, $data['summary']['total_unique_trees']);
        $this->assertEquals(40, $data['summary']['total_usable_pcs']);
    }

    /**
     * 14. Nama item berbeda pada kode produksi sama tetap menjadi kelompok berbeda.
     */
    public function test_different_item_name_remain_separate_groups(): void
    {
        $plan1 = $this->createPlan(['code' => 'CODE-MULTI', 'customer' => 'CUST-SAME', 'item_name' => 'Flange 2"']);
        $line1 = $this->createPrintOrderLine($plan1);
        $tree1 = $this->createTree(['lost_wax_print_order_line_id' => $line1->id, 'quantity' => 10]);
        $this->createScanEvent($tree1, 'layer_6', now()->subHours(2));

        $plan2 = $this->createPlan(['code' => 'CODE-MULTI', 'customer' => 'CUST-SAME', 'item_name' => 'Flange 3"']);
        $line2 = $this->createPrintOrderLine($plan2);
        $tree2 = $this->createTree(['lost_wax_print_order_line_id' => $line2->id, 'quantity' => 15]);
        $this->createScanEvent($tree2, 'layer_6', now()->subHours(2));

        $data = $this->service->getQueueData();

        $this->assertEquals(2, $data['summary']['total_groups']);
        $this->assertEquals(25, $data['summary']['total_usable_pcs']);
    }

    /**
     * 15. FIFO kelompok menggunakan waktu selesai lapisan paling awal dari anggota kelompok.
     */
    public function test_group_fifo_order_uses_earliest_finished_timestamp(): void
    {
        $planLate = $this->createPlan(['code' => 'GROUP-LATE']);
        $lineLate = $this->createPrintOrderLine($planLate);
        $treeLate = $this->createTree(['barcode' => 'TREE-LATE', 'lost_wax_print_order_line_id' => $lineLate->id]);
        // Selesai 2 jam lalu
        $this->createScanEvent($treeLate, 'layer_6', now()->subHours(2));

        $planEarly = $this->createPlan(['code' => 'GROUP-EARLY']);
        $lineEarly = $this->createPrintOrderLine($planEarly);
        $treeEarly1 = $this->createTree(['barcode' => 'TREE-E1', 'lost_wax_print_order_line_id' => $lineEarly->id]);
        // Selesai 5 jam lalu (earliest!)
        $this->createScanEvent($treeEarly1, 'layer_6', now()->subHours(5));

        $treeEarly2 = $this->createTree(['barcode' => 'TREE-E2', 'lost_wax_print_order_line_id' => $lineEarly->id]);
        // Selesai 1 jam lalu
        $this->createScanEvent($treeEarly2, 'layer_6', now()->subHour());

        $data = $this->service->getQueueData();

        // GROUP-EARLY punya anggota selesai 5 jam lalu -> harus menjadi nomor 1
        // GROUP-LATE punya anggota selesai 2 jam lalu -> harus menjadi nomor 2
        $this->assertEquals(2, $data['items']->count());
        $this->assertEquals('GROUP-EARLY', $data['items'][0]['production_code']);
        $this->assertEquals('GROUP-LATE', $data['items'][1]['production_code']);
        $this->assertEquals(2, $data['items'][0]['tree_count']);
    }

    /**
     * 16. Preview dan print memiliki jumlah baris, nilai, dan urutan yang konsisten.
     */
    public function test_preview_and_print_have_consistent_rows_values_and_order(): void
    {
        $plan = $this->createPlan(['code' => 'PLAN-SYNC']);
        $line = $this->createPrintOrderLine($plan);

        $tree1 = $this->createTree(['barcode' => 'TREE-A', 'lost_wax_print_order_line_id' => $line->id, 'quantity' => 10]);
        $this->createScanEvent($tree1, 'layer_6', now()->subHours(4));

        $tree2 = $this->createTree(['barcode' => 'TREE-B', 'lost_wax_print_order_line_id' => $line->id, 'quantity' => 20]);
        $this->createScanEvent($tree2, 'layer_6', now()->subHours(2));

        $previewResponse = $this->actingAs($this->user)->get(route('lost-wax.reports.ready-for-oven.index'));
        $previewResponse->assertStatus(200);
        $previewItems = $previewResponse->viewData('items');

        $printResponse = $this->actingAs($this->user)->get(route('lost-wax.reports.ready-for-oven.print'));
        $printResponse->assertStatus(200);
        $printItems = $printResponse->viewData('items');

        // Digabung menjadi 1 kelompok baris
        $this->assertCount(1, $previewItems);
        $this->assertCount(1, $printItems);

        $this->assertEquals($previewItems->first()['tree_count'], $printItems->first()['tree_count']);
        $this->assertEquals(2, $previewItems->first()['tree_count']);
        $this->assertEquals(30, $previewItems->first()['quantity_pcs']);
        $this->assertEquals($previewItems->first()['quantity_pcs'], $printItems->first()['quantity_pcs']);
    }

    /**
     * 17. Format print A4 portrait merender PT. PERONI KARYA SENTRA dan seluruh kolom penting.
     */
    public function test_print_view_a4_portrait_renders_all_required_columns_and_company_header(): void
    {
        $plan = $this->createPlan(['code' => 'PRINT-CODE-01', 'item_name' => 'Elbow 90 Deg', 'customer' => 'PT Test']);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTree(['lost_wax_print_order_line_id' => $line->id, 'quantity' => 12]);
        $this->createScanEvent($tree, 'layer_6', now()->subHours(2));

        $response = $this->actingAs($this->user)->get(route('lost-wax.reports.ready-for-oven.print'));

        $response->assertStatus(200);
        $response->assertSee('PT. PERONI KARYA SENTRA');
        $response->assertDontSee('PT. PERONIKS CITRA BERSAUDARA');
        $response->assertSee('BARANG SIAP OVEN');
        $response->assertSee('PRINT-CODE-01');
        $response->assertSee('Elbow 90 Deg');
        $response->assertSee('PT Test');
        $response->assertSee('Waktu Selesai Lapisan');
        $response->assertSee('Jumlah Rangkai');
        $response->assertSee('Jumlah Pcs');
        $response->assertSee('Dibuat Oleh');
        $response->assertSee('Diterima Oleh');
        $response->assertSee('size: A4 portrait', false);
    }

    /**
     * 18. Sidebar contains Barang Siap Oven link and displays active state when viewing Ready for Oven page.
     */
    public function test_sidebar_contains_ready_for_oven_link_and_displays_active_state(): void
    {
        $response = $this->actingAs($this->user)->get(route('lost-wax.reports.ready-for-oven.index'));

        $response->assertStatus(200);

        // Link with route URL
        $response->assertSee(route('lost-wax.reports.ready-for-oven.index'), false);

        // Link label, tooltip, and icon
        $response->assertSee('Barang Siap Oven');
        $response->assertSee('title="Barang Siap Masuk Oven"', false);
        $response->assertSee('fa-temperature-high');

        // Active state class
        $html = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/href="[^"]*\/lost-wax\/reports\/ready-for-oven"[^>]*class="[^"]*text-white font-medium border-l-2 border-amber-400/',
            $html
        );
    }

    /**
     * 19. Sidebar does not activate Barang Siap Oven link when viewing other Lost Wax pages.
     */
    public function test_sidebar_ready_for_oven_link_is_inactive_on_other_pages(): void
    {
        $response = $this->actingAs($this->user)->get(route('lost-wax.production-status'));

        $response->assertStatus(200);
        $response->assertSee(route('lost-wax.reports.ready-for-oven.index'), false);
        $response->assertSee('Barang Siap Oven');

        $html = $response->getContent();
        $this->assertDoesNotMatchRegularExpression(
            '/href="[^"]*\/lost-wax\/reports\/ready-for-oven"[^>]*class="[^"]*border-amber-400/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/href="[^"]*\/lost-wax\/reports\/ready-for-oven"[^>]*class="[^"]*text-slate-300/',
            $html
        );
    }
}
