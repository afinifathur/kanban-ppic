<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxCastingResult;
use App\Models\LostWaxCastingResultTree;
use App\Models\LostWaxTree;
use App\Models\PrintJob;
use App\Models\User;
use App\Services\Barcode\Renderers\LostWaxBasketLabelTsplRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LostWaxBasketLabelPrintTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $ppicUser;
    protected User $spvUser;
    protected User $qcUser;
    protected User $salesUser;

    protected LostWaxBasketLabelTsplRenderer $renderer;
    protected static int $seq = 9500;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lost_wax.printer_name' => 'TSC TE200']);

        // Roles & Permissions
        $accessPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution']);
        $accessProductionVisibility = Permission::firstOrCreate(['name' => 'access_production_visibility']);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->syncPermissions([$accessPlanning, $accessExecution]);

        $ppicRole = Role::firstOrCreate(['name' => 'ppic']);
        $ppicRole->syncPermissions([$accessPlanning, $accessExecution]);

        $spvRole = Role::firstOrCreate(['name' => 'spv']);
        $spvRole->syncPermissions([$accessExecution]);

        $qcRole = Role::firstOrCreate(['name' => 'admin_qc_fitting']);
        $qcRole->syncPermissions([$accessPlanning, $accessExecution]);

        $salesRole = Role::firstOrCreate(['name' => 'sales']);
        $salesRole->syncPermissions([$accessProductionVisibility]);

        $this->adminUser = User::factory()->create(['email' => 'admin_print@peroniks.com']);
        $this->adminUser->assignRole('admin');

        $this->ppicUser = User::factory()->create(['email' => 'ppic_print@peroniks.com']);
        $this->ppicUser->assignRole('ppic');

        $this->spvUser = User::factory()->create(['email' => 'spv_print@peroniks.com']);
        $this->spvUser->assignRole('spv');

        $this->qcUser = User::factory()->create(['email' => 'qc_print@peroniks.com']);
        $this->qcUser->assignRole('admin_qc_fitting');

        $this->salesUser = User::factory()->create(['email' => 'sales_print@peroniks.com']);
        $this->salesUser->assignRole('sales');

        $this->renderer = new LostWaxBasketLabelTsplRenderer;
    }

    protected function createCastingResultWithTrees(int $treeCount, array $treeOverrides = [], string $status = 'COMPLETED'): LostWaxCastingResult
    {
        $heatNumber = 'HEAT-202611-' . (self::$seq++);

        $res = LostWaxCastingResult::create([
            'heat_number' => $heatNumber,
            'cast_date' => now()->toDateString(),
            'furnace' => 'INDUCTION-1',
            'shift' => '1',
            'operator_name' => 'Operator A',
            'total_trees' => $treeCount,
            'total_pcs' => $treeCount * 24,
            'status' => $status,
            'recorded_by' => $this->adminUser->id,
            'voided_by' => $status === 'CANCELLED' ? $this->adminUser->id : null,
            'voided_at' => $status === 'CANCELLED' ? now() : null,
            'void_reason' => $status === 'CANCELLED' ? 'Uji Void' : null,
        ]);

        for ($i = 1; $i <= $treeCount; $i++) {
            $currentSeq = self::$seq++;
            $barcode = sprintf('P26%04d', $currentSeq);

            $physicalTree = LostWaxTree::create([
                'barcode' => $barcode,
                'tree_number' => $currentSeq,
                'daily_sequence' => $currentSeq,
                'quantity' => 24,
                'status' => 'poured',
                'current_stage' => 'siap_cor',
                'require_layer_7' => false,
                'production_date' => now()->toDateString(),
                'family_code' => '1',
            ]);

            $customData = $treeOverrides[$i - 1] ?? [];

            LostWaxCastingResultTree::create([
                'lost_wax_casting_result_id' => $res->id,
                'lost_wax_tree_id' => $physicalTree->id,
                'active_tree_id' => $status === 'COMPLETED' ? $physicalTree->id : null,
                'barcode' => $customData['barcode'] ?? $barcode,
                'production_code' => $customData['production_code'] ?? sprintf('PRD-%04d', $i),
                'customer' => $customData['customer'] ?? 'PT. PERONIKS',
                'item_name' => $customData['item_name'] ?? sprintf('FLANGE SS316 150LBS %d IN', $i),
                'quantity_pcs' => $customData['quantity_pcs'] ?? 24,
                'gross_pcs' => 24,
                'defect_pcs' => 0,
            ]);
        }

        return $res->fresh(['trees']);
    }

    /**
     * 1. Header baru: Teks "HEAT NUMBER" dihapus, Heat Number langsung di atas dalam Font 4.
     */
    public function test_01_header_displays_heat_number_directly_without_heat_number_label(): void
    {
        $res = $this->createCastingResultWithTrees(1);

        $tspl = $this->renderer->render($res);

        // Does NOT contain the old redundant label "HEAT NUMBER"
        $this->assertStringNotContainsString('"HEAT NUMBER"', $tspl);

        // Contains the Heat Number value directly in Font 4 at Y=16
        $this->assertStringContainsString('TEXT 30,16,"4",0,1,1,"' . $res->heat_number . '"', $tspl);
    }

    /**
     * 2. Header meta: HAL dan TGL disusun horizontal dalam satu baris.
     */
    public function test_02_header_meta_hal_and_date_are_horizontal_inline(): void
    {
        $res = $this->createCastingResultWithTrees(1);

        $tspl = $this->renderer->render($res);

        $expectedMeta = 'HAL 1/1 | TGL: ' . $res->cast_date->format('d/m/Y');
        $this->assertStringContainsString($expectedMeta, $tspl);
        $this->assertStringContainsString(',22,"2",0,1,1,"' . $expectedMeta . '"', $tspl);
    }

    /**
     * 3. Barcode Traveler Card tidak lagi dicetak pada baris tabel (hanya nama barang & Qty).
     */
    public function test_03_barcode_is_removed_from_table_lines(): void
    {
        $res = $this->createCastingResultWithTrees(2, [
            0 => ['barcode' => 'P26001001', 'item_name' => 'SS304 CASTED PLANE FLANGE 1/2 IN'],
            1 => ['barcode' => 'P26001002', 'item_name' => 'SS316 CASTED RAISED FLANGE 1/2 IN'],
        ]);

        $tspl = $this->renderer->render($res);

        // Barcodes must NOT appear in the rendered TSPL table lines
        $this->assertStringNotContainsString('P26001001', $tspl);
        $this->assertStringNotContainsString('P26001002', $tspl);

        // Product names appear cleanly
        $this->assertStringContainsString('SS304 CASTED PLANE FLANGE 1/2 IN', $tspl);
    }

    /**
     * 4. Header tabel tiga kolom: NAMA BARANG, QTY, RUSAK.
     */
    public function test_04_table_headers_are_nama_barang_qty_rusak(): void
    {
        $res = $this->createCastingResultWithTrees(1);

        $tspl = $this->renderer->render($res);

        $this->assertStringContainsString('TEXT 30,58,"2",0,1,1,"NAMA BARANG"', $tspl);
        $this->assertStringContainsString('TEXT 512,58,"2",0,1,1,"QTY"', $tspl);
        $this->assertStringContainsString('TEXT 605,58,"2",0,1,1,"RUSAK"', $tspl);

        // Vertical dividers present
        $this->assertStringContainsString('BAR 490,52,2,338', $tspl);
        $this->assertStringContainsString('BAR 570,52,2,338', $tspl);
    }

    /**
     * 5. Alignment QTY presisi dengan Font 1: Angka kuantitas 1, 2, 3, 4 digit tepat di tengah kolom QTY (midpoint 530).
     */
    public function test_05_qty_alignment_is_mathematically_centered_under_qty_header(): void
    {
        $res = $this->createCastingResultWithTrees(4, [
            0 => ['quantity_pcs' => 8,    'item_name' => 'ITEM 1 DIGIT'],
            1 => ['quantity_pcs' => 15,   'item_name' => 'ITEM 2 DIGIT'],
            2 => ['quantity_pcs' => 150,  'item_name' => 'ITEM 3 DIGIT'],
            3 => ['quantity_pcs' => 1500, 'item_name' => 'ITEM 4 DIGIT'],
        ]);

        $tspl = $this->renderer->render($res, LostWaxBasketLabelTsplRenderer::FONT_MODE_FONT1);

        // Midpoint of QTY column (490 to 570) is 530.
        // Font 1 is 8 dots/char.
        // Formula: X_start = 530 - (chars * 4)
        // 1 digit:  8 dots wide  -> X = 530 - 4 = 526
        // 2 digits: 16 dots wide -> X = 530 - 8 = 522
        // 3 digits: 24 dots wide -> X = 530 - 12 = 518
        // 4 digits: 32 dots wide -> X = 530 - 16 = 514
        $this->assertStringContainsString('TEXT 526,', $tspl); // Qty "8"
        $this->assertStringContainsString(',"1",0,1,1,"8"', $tspl);

        $this->assertStringContainsString('TEXT 522,', $tspl); // Qty "15"
        $this->assertStringContainsString(',"1",0,1,1,"15"', $tspl);

        $this->assertStringContainsString('TEXT 518,', $tspl); // Qty "150"
        $this->assertStringContainsString(',"1",0,1,1,"150"', $tspl);

        $this->assertStringContainsString('TEXT 514,', $tspl); // Qty "1500"
        $this->assertStringContainsString(',"1",0,1,1,"1500"', $tspl);

        // Verify none of the QTY texts enter the RUSAK column (X >= 570) or spill before (X <= 490)
        preg_match_all('/TEXT ([0-9]+),[0-9]+,"1",0,1,1,"([0-9]+)"/', $tspl, $matches);
        $xCoords = $matches[1];
        foreach ($xCoords as $x) {
            $this->assertGreaterThan(490, (int) $x, "Qty berada di sebelah kiri batas kolom QTY!");
            $this->assertLessThan(570, (int) $x, "Qty melewati batas garis pemisah dan masuk ke kolom Rusak!");
        }
    }

    /**
     * 5b. Pengujian Khusus Bagian F: Seluruh 8 skenario kombinasi kuantitas Qty (8, 15, 150, 1500)
     * pada nama barang 1-baris dan 2-baris wrapping dengan Font 1.
     */
    public function test_05b_section_f_eight_scenarios_qty_combinations(): void
    {
        // Long name that definitely wraps beyond 50 characters in Font 1:
        $longName = 'SS316 WELDING NECK FLANGE ANSI 150# 1/2 IN SCH40 HIGH PRESSURE CLASS 300 HEAVY DUTY';

        // Skenario 1-4: 1-baris nama barang, Qty = 8, 15, 150, 1500
        // Row starts at Y = 82, height = 28 dots -> Qty Y = 82 + (28 - 12)/2 = 90
        $scenariosSingle = [8, 15, 150, 1500];
        $expectedX = [8 => 526, 15 => 522, 150 => 518, 1500 => 514];

        foreach ($scenariosSingle as $qty) {
            $res = $this->createCastingResultWithTrees(1, [
                0 => ['quantity_pcs' => $qty, 'item_name' => 'SHORT PRODUCT FLANGE 2 IN'],
            ]);
            $tspl = $this->renderer->render($res, LostWaxBasketLabelTsplRenderer::FONT_MODE_FONT1);
            $expX = $expectedX[$qty];
            $this->assertStringContainsString("TEXT {$expX},90,\"1\",0,1,1,\"{$qty}\"", $tspl, "Skenario 1-baris Qty {$qty} koordinat tidak sesuai!");
        }

        // Skenario 5-8: 2-baris wrapping nama barang, Qty = 8, 15, 150, 1500
        // Row starts at Y = 82, height = 44 dots -> Qty Y = 82 + (44 - 12)/2 = 98
        foreach ($scenariosSingle as $qty) {
            $res = $this->createCastingResultWithTrees(1, [
                0 => ['quantity_pcs' => $qty, 'item_name' => $longName],
            ]);
            $tspl = $this->renderer->render($res, LostWaxBasketLabelTsplRenderer::FONT_MODE_FONT1);
            $expX = $expectedX[$qty];
            $this->assertStringContainsString("TEXT {$expX},98,\"1\",0,1,1,\"{$qty}\"", $tspl, "Skenario 2-baris Qty {$qty} koordinat tidak sesuai!");
        }

        // Verify continuous vertical dividers
        $this->assertStringContainsString('BAR 490,52,2,338', $tspl);
        $this->assertStringContainsString('BAR 570,52,2,338', $tspl);
    }

    /**
     * 5c. Mode Font 2 sebelumnya tetap didukung untuk perbandingan (backward compatibility).
     */
    public function test_05c_font2_backward_compatibility_mode(): void
    {
        $res = $this->createCastingResultWithTrees(1, [
            0 => ['quantity_pcs' => 15, 'item_name' => 'SHORT ITEM NAME'],
        ]);
        $tsplFont2 = $this->renderer->render($res, LostWaxBasketLabelTsplRenderer::FONT_MODE_FONT2);

        // In Font 2 mode: X = 518, Y = 89, Font "2"
        $this->assertStringContainsString('TEXT 518,89,"2",0,1,1,"15"', $tsplFont2);

        // Blade view supports font query parameter
        $this->actingAs($this->adminUser);
        $responseFont1 = $this->get(route('lost-wax.casting-results.print-thermal', ['castingResult' => $res, 'font' => 1]));
        $responseFont1->assertOk();
        $responseFont1->assertSee('Font 1 (Baru)', false);

        $responseFont2 = $this->get(route('lost-wax.casting-results.print-thermal', ['castingResult' => $res, 'font' => 2]));
        $responseFont2->assertOk();
        $responseFont2->assertSee('Font 2 (Lama)', false);
    }

    /**
     * 6. Nama barang pendek: Tampil dalam 1 baris (Font 1 tinggi 28 dots).
     */
    public function test_06_short_item_name_renders_in_single_line(): void
    {
        $res = $this->createCastingResultWithTrees(1, [
            0 => ['item_name' => 'FLANGE JIS 10K 2 IN', 'quantity_pcs' => 20],
        ]);

        $chunks = $this->renderer->chunkItems($res->trees, LostWaxBasketLabelTsplRenderer::FONT_MODE_FONT1);
        $this->assertCount(1, $chunks);
        $this->assertCount(1, $chunks[0]);
        $this->assertCount(1, $chunks[0][0]['lines']);
        $this->assertEquals(LostWaxBasketLabelTsplRenderer::FONT1_ROW_SINGLE, $chunks[0][0]['height']);
    }

    /**
     * 7. Nama barang panjang: Otomatis wrapping ke baris ke-2 (Font 1 tinggi 44 dots).
     */
    public function test_07_long_item_name_wraps_to_second_line(): void
    {
        $longName = 'SS316 CASTED RAISED FLANGE JIS 10K 1/2" CLASS 150 HEAVY DUTY CHEMICAL SERVICE';
        $res = $this->createCastingResultWithTrees(1, [
            0 => ['item_name' => $longName, 'quantity_pcs' => 15],
        ]);

        $chunks = $this->renderer->chunkItems($res->trees, LostWaxBasketLabelTsplRenderer::FONT_MODE_FONT1);
        $this->assertCount(1, $chunks);
        $this->assertCount(1, $chunks[0]);
        $this->assertCount(2, $chunks[0][0]['lines']);
        $this->assertEquals(LostWaxBasketLabelTsplRenderer::FONT1_ROW_DOUBLE, $chunks[0][0]['height']);

        $tspl = $this->renderer->render($res, LostWaxBasketLabelTsplRenderer::FONT_MODE_FONT1);
        $this->assertStringContainsString('SS316 CASTED RAISED FLANGE JIS 10K', $tspl);
    }

    /**
     * 8. Kapasitas dinamis label: Font 1 menampung hingga 11 item single-line per label.
     */
    public function test_08_dynamic_label_capacity_based_on_actual_row_heights(): void
    {
        // 11 short items fit on 1 label in Font 1 (11 * 28 = 308 dots <= 308 dots)
        $shortItems = [];
        for ($i = 1; $i <= 11; $i++) {
            $shortItems[] = ['item_name' => "ITEM SHORT {$i}"];
        }
        $res11 = $this->createCastingResultWithTrees(11, $shortItems);

        $tspl11 = $this->renderer->render($res11, LostWaxBasketLabelTsplRenderer::FONT_MODE_FONT1);
        $this->assertEquals(1, substr_count($tspl11, 'PRINT 1,1'));
        $this->assertStringContainsString('HAL 1/1', $tspl11);

        // 12 short items exceed 308 dots (12 * 28 = 336 > 308) -> Chunks into 2 labels
        $res12 = $this->createCastingResultWithTrees(12);
        $tspl12 = $this->renderer->render($res12, LostWaxBasketLabelTsplRenderer::FONT_MODE_FONT1);
        $this->assertEquals(2, substr_count($tspl12, 'PRINT 1,1'));
        $this->assertStringContainsString('HAL 1/2', $tspl12);
        $this->assertStringContainsString('HAL 2/2', $tspl12);
    }

    /**
     * 9. Integritas: Tidak ada item yang hilang atau tercetak dua kali saat wrapping dan chunking.
     */
    public function test_09_no_items_lost_or_duplicated_with_dynamic_wrapping(): void
    {
        $mixedOverrides = [];
        for ($k = 1; $k <= 14; $k++) {
            $isLong = ($k % 2 === 0);
            $tag = sprintf('PROD_SKU_%02d', $k);
            $mixedOverrides[] = [
                'item_name' => $isLong
                    ? "VERY LONG FLANGE SPECIFICATION {$tag} EXTRA DETAILS"
                    : "SHORT FLANGE {$tag}",
                'quantity_pcs' => $k * 5,
            ];
        }

        $res = $this->createCastingResultWithTrees(14, $mixedOverrides);

        $tspl = $this->renderer->render($res);

        // Verify each item's unique SKU appears exactly once in the rendered TSPL stream
        for ($k = 1; $k <= 14; $k++) {
            $tag = sprintf('PROD_SKU_%02d', $k);
            $this->assertEquals(1, substr_count($tspl, $tag), "Item {$tag} hilang atau duplikat pada TSPL!");
        }
    }

    /**
     * 10. Kolom Rusak selalu kosong (tidak ada teks di kolom Rusak kecuali header).
     */
    public function test_10_column_rusak_is_always_empty(): void
    {
        $res = $this->createCastingResultWithTrees(5);

        $tspl = $this->renderer->render($res);

        // Header has RUSAK at X=605
        $this->assertStringContainsString('TEXT 605,58,"2",0,1,1,"RUSAK"', $tspl);

        // No other text in X >= 570 below Y=80
        preg_match_all('/TEXT (5[7-9][0-9]|6[0-9]{2}),([0-9]+),"/', $tspl, $matches);
        $xCoords = $matches[1];
        $yCoords = $matches[2];

        for ($idx = 0; $idx < count($xCoords); $idx++) {
            $y = (int) $yCoords[$idx];
            if ($y >= 80) {
                $this->fail("Ditemukan teks di kolom Rusak pada koordinat Y={$y}!");
            }
        }
    }

    /**
     * 11. Sanitasi tanda kutip inch (") diganti menjadi ' IN' dan aman dari TSPL syntax error.
     */
    public function test_11_quotes_and_slashes_are_sanitized(): void
    {
        $res = $this->createCastingResultWithTrees(1, [
            0 => ['item_name' => 'VALVE 1.5" \\ SPECIAL EDITION'],
        ]);

        $tspl = $this->renderer->render($res);

        $this->assertStringNotContainsString('1.5"', $tspl);
        $this->assertStringContainsString('1.5 IN', $tspl);
        $this->assertStringNotContainsString('\\', $tspl);
    }

    /**
     * 12. Transaksi VOID (CANCELLED) ditolak pada antrean cetak thermal.
     */
    public function test_12_cancelled_transaction_is_rejected_by_queue_endpoint(): void
    {
        $res = $this->createCastingResultWithTrees(2, [], 'CANCELLED');

        $response = $this->actingAs($this->adminUser)->postJson(route('lost-wax.casting-results.queue-thermal', $res));

        $response->assertStatus(422);
        $response->assertJsonFragment(['success' => false]);
        $this->assertStringContainsString('VOID', $response->json('message'));
        $this->assertEquals(0, PrintJob::count());
    }

    /**
     * 13. User tanpa izin (sales / non-authorized) ditolak dengan 403.
     */
    public function test_13_unauthorized_user_is_forbidden_from_preview_and_queue(): void
    {
        $this->bypassRoleGateFix();

        $res = $this->createCastingResultWithTrees(2);

        $this->actingAs($this->salesUser)->get(route('lost-wax.casting-results.print-thermal', $res))->assertStatus(403);
        $this->actingAs($this->salesUser)->postJson(route('lost-wax.casting-results.queue-thermal', $res))->assertStatus(403);
    }

    /**
     * 14. Browser preview GET bersifat murni read-only dan tidak membuat print job.
     */
    public function test_14_browser_preview_get_is_strictly_read_only_and_creates_no_jobs(): void
    {
        $res = $this->createCastingResultWithTrees(6);

        $initialJobCount = PrintJob::count();

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.casting-results.print-thermal', $res));

        $response->assertStatus(200);
        $response->assertSee($res->heat_number);
        $response->assertSee('NAMA BARANG');
        $response->assertSee('QTY');
        $response->assertSee('RUSAK');
        $this->assertStringNotContainsString('HEAT NUMBER', $response->getContent());

        $this->assertEquals($initialJobCount, PrintJob::count());
    }

    /**
     * 15. Direct queue POST menghasilkan record PrintJob dengan payload TSPL yang valid.
     */
    public function test_15_direct_queue_post_creates_valid_tspl_print_job(): void
    {
        $res = $this->createCastingResultWithTrees(3);

        $response = $this->actingAs($this->ppicUser)->postJson(route('lost-wax.casting-results.queue-thermal', $res));

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'label_count' => 1,
            'tree_count' => 3,
        ]);

        $this->assertEquals(1, PrintJob::count());

        $job = PrintJob::first();
        $this->assertEquals('TSC TE200', $job->printer_name);
        $this->assertEquals('BASKET_LABEL_90X50', $job->template_type);
        $this->assertEquals('pending', $job->status);
        $this->assertStringContainsString('SIZE 90 mm, 50 mm', $job->payload_tspl);
        $this->assertStringContainsString('BOX 20,10,700,390,3', $job->payload_tspl);
        $this->assertStringContainsString('PRINT 1,1', $job->payload_tspl);
    }

    /**
     * 16. Deduplikasi aman: Request queue berulang cepat tidak menghasilkan job ganda.
     */
    public function test_16_duplicate_queue_requests_within_window_are_deduplicated(): void
    {
        $res = $this->createCastingResultWithTrees(4);

        $res1 = $this->actingAs($this->adminUser)->postJson(route('lost-wax.casting-results.queue-thermal', $res));
        $res1->assertStatus(200);
        $this->assertEquals(1, PrintJob::count());
        $jobId1 = $res1->json('job_id');

        $res2 = $this->actingAs($this->adminUser)->postJson(route('lost-wax.casting-results.queue-thermal', $res));
        $res2->assertStatus(200);
        $jobId2 = $res2->json('job_id');

        $this->assertEquals($jobId1, $jobId2);
        $this->assertEquals(1, PrintJob::count());
    }

    /**
     * 17. Cetak PDF A4 lama (lost-wax.casting-results.print) tetap berfungsi normal tanpa regresi.
     */
    public function test_17_old_a4_pdf_document_remains_functional_without_regression(): void
    {
        $res = $this->createCastingResultWithTrees(4);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.casting-results.print', $res));

        $response->assertStatus(200);
        $response->assertSee('BUKTI PENCATATAN HASIL COR');
        $response->assertSee($res->heat_number);
        $response->assertSee($res->transaction_number);
    }

    /**
     * 18. Browser preview HTML merender semantic <table> dengan <colgroup> dan Qty strictly pada kolom ke-2.
     */
    public function test_18_actual_app_render_dom_structure_and_column_containment(): void
    {
        $res = LostWaxCastingResult::create([
            'heat_number' => 'A413112601',
            'cast_date' => '2026-11-13',
            'furnace' => 'INDUCTION-1',
            'shift' => '1',
            'operator_name' => 'Operator A',
            'total_trees' => 3,
            'total_pcs' => 38,
            'status' => 'COMPLETED',
            'recorded_by' => $this->adminUser->id,
        ]);

        $items = [
            ['name' => 'SS304 CASTED PLANE FLANGE JIS 5K 1/2 IN', 'qty' => 15],
            ['name' => 'SS316 CASTED RAISED FLANGE JIS 10K 1/2 IN', 'qty' => 15],
            ['name' => 'HOUSING FLANGE DN100 PN16 STAINLESS STEEL 316L SPECIAL FORGING LOT 24', 'qty' => 8],
        ];

        foreach ($items as $idx => $it) {
            $treeNum = 8800 + $idx;
            $physicalTree = LostWaxTree::create([
                'barcode' => sprintf('P26%04d', $treeNum),
                'tree_number' => $treeNum,
                'daily_sequence' => $idx + 1,
                'quantity' => $it['qty'],
                'status' => 'poured',
                'current_stage' => 'siap_cor',
                'require_layer_7' => false,
                'production_date' => now()->toDateString(),
                'family_code' => '1',
            ]);

            LostWaxCastingResultTree::create([
                'lost_wax_casting_result_id' => $res->id,
                'lost_wax_tree_id' => $physicalTree->id,
                'active_tree_id' => $physicalTree->id,
                'barcode' => sprintf('P26%04d', $treeNum),
                'item_name' => $it['name'],
                'production_code' => 'KODE-' . ($idx + 1),
                'tree_order' => $idx + 1,
                'quantity_pcs' => $it['qty'],
                'gross_weight' => 10.5,
                'net_weight' => 8.2,
            ]);
        }

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.casting-results.print-thermal', ['castingResult' => $res, 'font' => 1]));

        $response->assertStatus(200);
        $html = $response->getContent();

        // 1. Table structure
        $this->assertStringContainsString('<table class="basket-table">', $html);
        $this->assertStringContainsString('<col class="col-item" style="width: 58.75mm;">', $html);
        $this->assertStringContainsString('<col class="col-qty" style="width: 10.00mm;">', $html);
        $this->assertStringContainsString('<col class="col-rusak" style="width: 16.25mm;">', $html);

        // 2. DOM parsing
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $rows = $xpath->query('//table[contains(@class, "basket-table")]/tbody/tr[contains(@class, "item-row") and not(contains(@class, "row-empty"))]');
        $this->assertEquals(3, $rows->length);

        foreach ($rows as $rowIndex => $row) {
            $cells = $xpath->query('td', $row);
            $this->assertEquals(3, $cells->length, "Row {$rowIndex} must have exactly 3 cells");

            $itemCell = $cells->item(0);
            $qtyCell = $cells->item(1);
            $rusakCell = $cells->item(2);

            $this->assertStringContainsString('td-item', $itemCell->getAttribute('class'));
            $this->assertStringContainsString('td-qty', $qtyCell->getAttribute('class'));
            $this->assertStringContainsString('td-rusak', $rusakCell->getAttribute('class'));

            $expectedQty = (string) $items[$rowIndex]['qty'];
            $this->assertStringContainsString($expectedQty, $qtyCell->textContent);

            // Kolom 1 (Nama Barang) MUST NOT contain the Qty string as its own text
            $normalizedItemText = preg_replace('/\s+/', ' ', trim($itemCell->textContent));
            $this->assertEquals($items[$rowIndex]['name'], $normalizedItemText);
            // Kolom 3 (Rusak) must be blank
            $this->assertEmpty(trim($rusakCell->textContent));
        }

        // 3. Write actual rendered output to artifact for headless Chrome preview inspection
        $outPath = 'C:\\Users\\ppic2\\.gemini\\antigravity\\brain\\a50dc154-9197-499c-83bc-c055c599db4e\\actual_app_render.html';
        file_put_contents($outPath, $html);
    }

    /**
     * Helper to ensure Gate beforeCallbacks evaluate Spatie permissions cleanly in test environment.
     */
    protected function bypassRoleGateFix(): void
    {
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
    }
}
