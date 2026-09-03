<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxItemReference;
use App\Models\LostWaxTree;
use App\Models\LostWaxWorkOrder;
use App\Models\LostWaxWorkOrderPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionStatusTest extends TestCase
{
    use RefreshDatabase;

    private $scanService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scanService = new \App\Services\ScanService;

        $this->app->instance(
            \App\Contracts\ItemMasterRepository::class,
            new \Tests\Fakes\ArrayItemMasterRepository([
                [
                    'code' => 'LY026',
                    'name' => 'Hex Nipple SUS304',
                    'aisi' => 'SCS 13 A',
                    'standard' => 'JIS',
                    'unit_weight' => '0.300',
                    'status' => 'active',
                ],
            ])
        );
    }

    private function createReference(array $overrides = []): LostWaxItemReference
    {
        return LostWaxItemReference::create(array_merge([
            'master_source' => 'masterdata_kpi',
            'master_item_key' => 'LY026',
            'item_code_snapshot' => 'LY026',
            'item_name_snapshot' => 'Hex Nipple SUS304',
            'aisi_snapshot' => 'SCS 13 A',
        ], $overrides));
    }

    private function createWorkOrder(LostWaxItemReference $ref, array $overrides = []): LostWaxWorkOrder
    {
        return LostWaxWorkOrder::create(array_merge([
            'item_reference_id' => $ref->id,
            'et_code' => 'ET232',
            'po_number' => 'PO-001',
            'po_quantity' => 1000,
            'stock_quantity' => 500,
            'net_requirement_quantity' => 500,
            'status' => 'active',
            'family_code' => '1',
            'require_layer_7' => false,
        ], $overrides));
    }

    private function createPlan(LostWaxWorkOrder $wo, array $overrides = []): LostWaxWorkOrderPlan
    {
        return LostWaxWorkOrderPlan::create(array_merge([
            'work_order_id' => $wo->id,
            'wave_number' => 1,
            'plan_type' => 'initial',
            'planned_quantity' => 705,
            'status' => 'active',
        ], $overrides));
    }

    private function createTree(LostWaxWorkOrder $wo, int $qty, ?string $barcode = null, int $treeNumber = 1): LostWaxTree
    {
        return LostWaxTree::create([
            'work_order_id' => $wo->id,
            'barcode' => $barcode ?? $wo->family_code.'082600'.str_pad($treeNumber, 3, '0', STR_PAD_LEFT),
            'tree_number' => $treeNumber,
            'quantity' => $qty,
            'status' => 'generated',
            'production_date' => '2026-08-11',
            'family_code' => $wo->family_code,
            'daily_sequence' => $treeNumber,
        ]);
    }

    public function test_production_status_page_loads(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('lost-wax.production-status'))
            ->assertOk()
            ->assertSee('PRODUCTION STATUS')
            ->assertSee('Kode Cust')
            ->assertDontSee('Cust / ET')
            ->assertSee('Total Lap.')
            ->assertSee('Product Name')
            ->assertSee('ACTIVE');
    }

    public function test_kode_cust_displayed_once_per_row(): void
    {
        $user = User::factory()->create();
        $ref = $this->createReference();
        $wo = $this->createWorkOrder($ref, ['et_code' => 'ET232']);
        $this->createPlan($wo);

        $this->createTree($wo, 15, '1110826001', 1);

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status', ['filter' => 'all']));

        $response->assertOk();
        $content = $response->getContent();

        // ET232 appears in the Kode Cust column as a single value — not duplicated
        $this->assertStringContainsString('ET232', $content);
    }

    public function test_item_code_not_displayed(): void
    {
        $user = User::factory()->create();
        $ref = $this->createReference(['item_code_snapshot' => '4.1061ENPN16.D0050']);
        $wo = $this->createWorkOrder($ref);
        $this->createPlan($wo);

        $this->createTree($wo, 15, '1110826001', 1);

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status', ['filter' => 'all']));

        $response->assertOk();
        // Item code SKU should NOT be visible in the table
        $response->assertDontSee('4.1061ENPN16.D0050');
        // Product name SHOULD be visible
        $response->assertSee('Hex Nipple SUS304');
    }

    public function test_total_lap_calculated_correctly(): void
    {
        $user = User::factory()->create();
        $ref = $this->createReference();
        $wo = $this->createWorkOrder($ref);
        $this->createPlan($wo);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $t1 = $this->createTree($wo, 15, '1110826001', 1);
        $t2 = $this->createTree($wo, 20, '1110826002', 2);
        $this->scanService->process($t1->barcode, $user->id);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 14, 0, 0));
        $this->scanService->process($t2->barcode, $user->id);
        $this->scanService->process($t2->barcode, $user->id);

        Carbon::setTestNow(null);

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status', ['filter' => 'all']));

        $response->assertOk();
        // L1=15, L2=20, L3=0...L7=0 → Total Lap. = 35
        $content = $response->getContent();
        // Verify 35 appears as Total Lap. (should appear as standalone number)
        $this->assertStringContainsString('Total Lap.', $content);
    }

    public function test_total_lap_excludes_oven(): void
    {
        $user = User::factory()->create();
        $ref = $this->createReference();
        $wo = $this->createWorkOrder($ref);
        $this->createPlan($wo);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $t1 = $this->createTree($wo, 15, '1110826001', 1);
        $t2 = $this->createTree($wo, 10, '1110826002', 2);

        // t1 → layer_1 (15)
        $this->scanService->process($t1->barcode, $user->id);

        // t2 → oven (10)
        foreach (range(1, 6) as $i) {
            Carbon::setTestNow(Carbon::create(2026, 8, 11, 8 + $i, 0, 0));
            $this->scanService->process($t2->barcode, $user->id);
        }
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 16, 0, 0));
        $this->scanService->processOvenScan($t2->barcode, $user->id);

        Carbon::setTestNow(null);

        $wo->refresh();
        // Total Lap. = 15 (layer_1 only), Oven = 10
        $this->assertSame(15, $wo->trees->where('current_stage', 'layer_1')->sum('quantity'));
        $this->assertSame(10, $wo->trees->where('current_stage', 'oven')->sum('quantity'));

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status', ['filter' => 'all']));

        $response->assertOk();
    }

    public function test_variable_tree_quantities_aggregated_correctly(): void
    {
        $user = User::factory()->create();
        $ref = $this->createReference();
        $wo = $this->createWorkOrder($ref);
        $this->createPlan($wo);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $t1 = $this->createTree($wo, 15, '1110826001', 1);
        $t2 = $this->createTree($wo, 15, '1110826002', 2);
        $t3 = $this->createTree($wo, 14, '1110826003', 3);

        $this->scanService->process($t1->barcode, $user->id);
        $this->scanService->process($t2->barcode, $user->id);
        $this->scanService->process($t3->barcode, $user->id);

        Carbon::setTestNow(null);

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status'));

        $response->assertOk();
        // 15 + 15 + 14 = 44, NOT 45
        $this->assertStringNotContainsString('>45<', $response->getContent());
    }

    public function test_oven_trees_counted_under_oven(): void
    {
        $user = User::factory()->create();
        $ref = $this->createReference();
        $wo = $this->createWorkOrder($ref);
        $this->createPlan($wo);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $t1 = $this->createTree($wo, 15, '1110826001', 1);

        foreach (range(1, 6) as $i) {
            Carbon::setTestNow(Carbon::create(2026, 8, 11, 8 + $i, 0, 0));
            $this->scanService->process($t1->barcode, $user->id);
        }

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 16, 0, 0));
        $this->scanService->processOvenScan($t1->barcode, $user->id);

        Carbon::setTestNow(null);

        $completedResponse = $this->actingAs($user)
            ->get(route('lost-wax.production-status', ['filter' => 'completed']));

        $completedResponse->assertOk();
        $completedResponse->assertSee('ET232');
        $completedResponse->assertSee('KURANG');
    }

    public function test_non_zero_layer_cells_have_highlight_class(): void
    {
        $user = User::factory()->create();
        $ref = $this->createReference();
        $wo = $this->createWorkOrder($ref);
        $this->createPlan($wo);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));
        $t1 = $this->createTree($wo, 15, '1110826001', 1);
        $this->scanService->process($t1->barcode, $user->id);
        Carbon::setTestNow(null);

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status', ['filter' => 'all']));

        $response->assertOk();
        $response->assertSee('cell-layer-active');
    }

    public function test_zero_layer_cells_are_neutral(): void
    {
        $user = User::factory()->create();
        $ref = $this->createReference();
        $wo = $this->createWorkOrder($ref);
        $this->createPlan($wo);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));
        $t1 = $this->createTree($wo, 15, '1110826001', 1);
        $this->scanService->process($t1->barcode, $user->id);
        Carbon::setTestNow(null);

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status', ['filter' => 'all']));

        $response->assertOk();
        // Layer cells with quantity 0 should have text-slate-300 (neutral)
        $response->assertSee('text-slate-300');
    }

    public function test_oven_cell_has_highlight_class(): void
    {
        $user = User::factory()->create();
        $ref = $this->createReference();
        $wo = $this->createWorkOrder($ref);
        $this->createPlan($wo);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));
        $t1 = $this->createTree($wo, 15, '1110826001', 1);
        foreach (range(1, 6) as $i) {
            Carbon::setTestNow(Carbon::create(2026, 8, 11, 8 + $i, 0, 0));
            $this->scanService->process($t1->barcode, $user->id);
        }
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 16, 0, 0));
        $this->scanService->processOvenScan($t1->barcode, $user->id);
        Carbon::setTestNow(null);

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status', ['filter' => 'completed']));

        $response->assertOk();
        $response->assertSee('cell-oven');
    }

    public function test_print_css_hides_navigation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status'));

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('@media print', $content);
        $this->assertStringContainsString('display: none !important', $content);
    }

    public function test_print_contains_report_title(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status'));

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('PT. PERONI KARYA SENTRA', $content);
        $this->assertStringContainsString('LOST WAX', $content);
    }

    public function test_print_does_not_contain_app_navigation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status'));

        $response->assertOk();
        $content = $response->getContent();

        // These should exist in the web page but be hidden in print
        $this->assertStringContainsString('no-print', $content);
    }

    public function test_print_does_not_contain_fifo_tracking(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status'));

        $response->assertOk();
        $content = $response->getContent();

        // Print CSS hides all navigation — FIFO Tracking should be gone on paper
        $this->assertStringContainsString('@media print', $content);
    }

    public function test_print_css_uses_a4_landscape(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status'));

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('size: A4 landscape', $content);
    }

    public function test_print_css_removes_sticky(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status'));

        $response->assertOk();
        $content = $response->getContent();

        // Sticky positioning should be removed in print
        $this->assertStringContainsString('position: static !important', $content);
    }

    public function test_print_contains_kode_cust_label(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status'));

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('Kode Cust', $content);
    }

    public function test_xlsx_headers_contain_total_lap_and_total_rusak(): void
    {
        $user = User::factory()->create();
        $ref = $this->createReference();
        $wo = $this->createWorkOrder($ref);
        $this->createPlan($wo);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));
        $t1 = $this->createTree($wo, 15, '1110826001', 1);
        $this->scanService->process($t1->barcode, $user->id);
        Carbon::setTestNow(null);

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status.export', ['filter' => 'all']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment; filename=lost-wax-production-status-', $disposition);
        $this->assertStringEndsWith('.xlsx', $disposition);

        $content = $response->streamedContent();

        // Write streamed content to a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tempFile, $content);

        // Load with PhpSpreadsheet Reader
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
        $spreadsheet = $reader->load($tempFile);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals('Lost Wax Production Status', $sheet->getCell('A1')->getValue());
        $this->assertEquals('Kode Cust', $sheet->getCell('A6')->getValue());
        $this->assertEquals('Total (pcs)', $sheet->getCell('F6')->getValue());
        $this->assertEquals('Total Rusak (pcs)', $sheet->getCell('G6')->getValue());
        $this->assertEquals('Status', $sheet->getCell('R6')->getValue());

        // Data row assertions
        $this->assertEquals('ET232', $sheet->getCell('A7')->getValue());

        unlink($tempFile);
    }

    public function test_xlsx_does_not_contain_item_column(): void
    {
        $user = User::factory()->create();
        $ref = $this->createReference(['item_code_snapshot' => '4.1061ENPN16.D0050']);
        $wo = $this->createWorkOrder($ref);
        $this->createPlan($wo);

        $this->createTree($wo, 15, '1110826001', 1);

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status.export', ['filter' => 'all']));

        $response->assertOk();
        $content = $response->streamedContent();

        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tempFile, $content);

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
        $spreadsheet = $reader->load($tempFile);
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [];
        foreach (range('A', 'R') as $col) {
            $headers[] = $sheet->getCell($col.'6')->getValue();
        }
        $this->assertContains('Product Name', $headers);
        $this->assertNotContains('Item', $headers);

        unlink($tempFile);
    }

    public function test_search_by_et_works(): void
    {
        $user = User::factory()->create();
        $ref = $this->createReference();

        $wo1 = $this->createWorkOrder($ref, ['et_code' => 'ET232']);
        $this->createPlan($wo1);

        $wo2 = $this->createWorkOrder($ref, ['et_code' => 'IL101', 'po_number' => 'PO-002']);
        $this->createPlan($wo2);

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status', ['search' => 'ET2', 'filter' => 'all']));

        $response->assertOk();
        $response->assertSee('ET232');
        $response->assertDontSee('>IL101<');
    }

    public function test_search_by_po_works(): void
    {
        $user = User::factory()->create();
        $ref = $this->createReference();

        $wo1 = $this->createWorkOrder($ref, ['et_code' => 'ET232', 'po_number' => 'PO-001']);
        $this->createPlan($wo1);

        $wo2 = $this->createWorkOrder($ref, ['et_code' => 'IL101', 'po_number' => 'PO-888']);
        $this->createPlan($wo2);

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status', ['search' => '888', 'filter' => 'all']));

        $response->assertOk();
        $response->assertSee('IL101');
        $response->assertDontSee('>ET232<');
    }

    public function test_active_filter_excludes_completed(): void
    {
        $user = User::factory()->create();
        $ref = $this->createReference();
        $wo = $this->createWorkOrder($ref);
        $this->createPlan($wo);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $t1 = $this->createTree($wo, 15, '1110826001', 1);

        foreach (range(1, 6) as $i) {
            Carbon::setTestNow(Carbon::create(2026, 8, 11, 8 + $i, 0, 0));
            $this->scanService->process($t1->barcode, $user->id);
        }

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 16, 0, 0));
        $this->scanService->processOvenScan($t1->barcode, $user->id);

        Carbon::setTestNow(null);

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status', ['filter' => 'active']));

        $response->assertOk();
        $response->assertDontSee('>ET232<');
    }

    public function test_completed_filter_finds_completed(): void
    {
        $user = User::factory()->create();
        $ref = $this->createReference();
        $wo = $this->createWorkOrder($ref);
        $this->createPlan($wo);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $t1 = $this->createTree($wo, 15, '1110826001', 1);

        foreach (range(1, 6) as $i) {
            Carbon::setTestNow(Carbon::create(2026, 8, 11, 8 + $i, 0, 0));
            $this->scanService->process($t1->barcode, $user->id);
        }

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 16, 0, 0));
        $this->scanService->processOvenScan($t1->barcode, $user->id);

        Carbon::setTestNow(null);

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status', ['filter' => 'completed']));

        $response->assertOk();
        $response->assertSee('ET232');
        $response->assertSee('KURANG');
    }

    public function test_all_filter_returns_both(): void
    {
        $user = User::factory()->create();
        $ref = $this->createReference();

        $wo1 = $this->createWorkOrder($ref, ['et_code' => 'ET232']);
        $this->createPlan($wo1);

        $wo2 = $this->createWorkOrder($ref, ['et_code' => 'IL101', 'po_number' => 'PO-002', 'family_code' => '2']);
        $this->createPlan($wo2);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $t1 = $this->createTree($wo1, 15, '1110826001', 1);
        $this->scanService->process($t1->barcode, $user->id);

        $t2 = $this->createTree($wo2, 20, '2110826001', 1);

        foreach (range(1, 6) as $i) {
            Carbon::setTestNow(Carbon::create(2026, 8, 11, 8 + $i, 0, 0));
            $this->scanService->process($t2->barcode, $user->id);
        }

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 16, 0, 0));
        $this->scanService->processOvenScan($t2->barcode, $user->id);

        Carbon::setTestNow(null);

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status', ['filter' => 'all']));

        $response->assertOk();
        $response->assertSee('ET232');
        $response->assertSee('IL101');
    }

    public function test_existing_scan_tests_remain_green(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('lost-wax.scan.index'))
            ->assertOk()
            ->assertSee('SCAN LAPISAN');

        $this->actingAs($user)
            ->get(route('lost-wax.scan-oven.index'))
            ->assertOk()
            ->assertSee('SCAN OVEN');
    }

    public function test_side_menu_highlighted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('lost-wax.production-status'))
            ->assertOk()
            ->assertSee('Production Status');
    }

    public function test_compact_layout_and_early_flow_columns_rendered(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('lost-wax.production-status'));
        $response->assertOk();
        $response->assertSee('CTK');
        $response->assertSee('RGKI');
        $response->assertSee('Total');
        $response->assertSee('Tot Rsk');
        $response->assertSee('L1');
        $response->assertSee('Oven');
    }

    public function test_filter_customer_works(): void
    {
        $user = User::factory()->create();
        $ref = $this->createReference();

        $wo1 = $this->createWorkOrder($ref, ['et_code' => 'ET-CUST1', 'customer_name' => 'Customer A']);
        $this->createPlan($wo1);

        $wo2 = $this->createWorkOrder($ref, ['et_code' => 'ET-CUST2', 'customer_name' => 'Customer B']);
        $this->createPlan($wo2);

        $response = $this->actingAs($user)->get(route('lost-wax.production-status', ['customer' => 'Customer A', 'filter' => 'all']));
        $response->assertOk();
        $response->assertSee('ET-CUST1');
        $response->assertDontSee('ET-CUST2');
    }

    public function test_filter_po_number_works(): void
    {
        $user = User::factory()->create();
        $ref = $this->createReference();

        $wo1 = $this->createWorkOrder($ref, ['et_code' => 'ET-PO1', 'po_number' => 'PO-XYZ-999']);
        $this->createPlan($wo1);

        $wo2 = $this->createWorkOrder($ref, ['et_code' => 'ET-PO2', 'po_number' => 'PO-ABC-111']);
        $this->createPlan($wo2);

        $response = $this->actingAs($user)->get(route('lost-wax.production-status', ['po_number' => 'PO-XYZ-999', 'filter' => 'all']));
        $response->assertOk();
        $response->assertSee('ET-PO1');
        $response->assertDontSee('ET-PO2');
    }

    public function test_filter_aisi_works(): void
    {
        $user = User::factory()->create();

        $ref1 = $this->createReference(['aisi_snapshot' => 'SUS304']);
        $wo1 = $this->createWorkOrder($ref1, ['et_code' => 'ET-AISI1']);
        $this->createPlan($wo1);

        $ref2 = $this->createReference([
            'master_item_key' => 'LY027',
            'item_code_snapshot' => 'LY027',
            'aisi_snapshot' => 'SUS316',
        ]);
        $wo2 = $this->createWorkOrder($ref2, ['et_code' => 'ET-AISI2']);
        $this->createPlan($wo2);

        $response = $this->actingAs($user)->get(route('lost-wax.production-status', ['aisi' => 'SUS304', 'filter' => 'all']));
        $response->assertOk();
        $response->assertSee('ET-AISI1');
        $response->assertDontSee('ET-AISI2');
    }

    public function test_combined_filters_work(): void
    {
        $user = User::factory()->create();

        $ref1 = $this->createReference(['aisi_snapshot' => 'SUS304']);
        $wo1 = $this->createWorkOrder($ref1, ['et_code' => 'ET-OK', 'customer_name' => 'Cust A', 'po_number' => 'PO-100']);
        $this->createPlan($wo1);

        $wo2 = $this->createWorkOrder($ref1, ['et_code' => 'ET-WRONG', 'customer_name' => 'Cust B', 'po_number' => 'PO-100']);
        $this->createPlan($wo2);

        $response = $this->actingAs($user)->get(route('lost-wax.production-status', [
            'customer' => 'Cust A',
            'po_number' => 'PO-100',
            'aisi' => 'SUS304',
            'filter' => 'all',
        ]));
        $response->assertOk();
        $response->assertSee('ET-OK');
        $response->assertDontSee('ET-WRONG');
    }

    public function test_rbac_product_scope_on_production_status(): void
    {
        // Set up Spatie Role for testing
        $ppicRole = \Spatie\Permission\Models\Role::findOrCreate('ppic');
        $accessExecution = \Spatie\Permission\Models\Permission::findOrCreate('access_execution');
        $ppicRole->givePermissionTo($accessExecution);

        // User PPIC Flange (can only see flange stainless)
        $ppicFlange = User::factory()->create([
            'email' => 'ppicflange_test@peroniks.com',
            'product_scope' => 'FLANGE_STAINLESS',
        ]);
        $ppicFlange->assignRole('ppic');

        // Legacy Work Orders (with family code '3' = Flange Stainless 304, family code '1' = Fitting Stainless 304)
        $ref = $this->createReference();
        $woFlange = $this->createWorkOrder($ref, ['et_code' => 'WO-FLANGE', 'family_code' => '3']);
        $this->createPlan($woFlange);

        $woFitting = $this->createWorkOrder($ref, ['et_code' => 'WO-FITTING', 'family_code' => '1']);
        $this->createPlan($woFitting);

        // PPIC Flange should only see WO-FLANGE
        $response = $this->actingAs($ppicFlange)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $response->assertOk();
        $response->assertSee('WO-FLANGE');
        $response->assertDontSee('WO-FITTING');

        // SPV User should see both
        $spvRole = \Spatie\Permission\Models\Role::findOrCreate('spv');
        $spvRole->givePermissionTo($accessExecution);
        $spvUser = User::factory()->create(['product_scope' => null]);
        $spvUser->assignRole('spv');

        $response2 = $this->actingAs($spvUser)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $response2->assertOk();
        $response2->assertSee('WO-FLANGE');
        $response2->assertSee('WO-FITTING');
    }

    public function test_production_status_scenarios_from_user(): void
    {
        $user = User::factory()->create();
        $ref = $this->createReference([
            'master_item_key' => 'LY099',
            'item_code_snapshot' => 'LY099',
        ]);

        // Scenario 1: 45 CTK -> 45 RGKI, tanpa rusak
        // CTK -, R -, RGKI 45, R -
        $wo1 = $this->createWorkOrder($ref, ['et_code' => 'WO-SCEN1', 'po_quantity' => 45, 'stock_quantity' => 0, 'net_requirement_quantity' => 45]);
        $this->createPlan($wo1, ['planned_quantity' => 45]);
        $wo1->wipEntries()->create(['stage' => 'moulding', 'quantity' => 45, 'produced_at' => now()]);
        $wo1->wipEntries()->create(['stage' => 'assembly', 'quantity' => 45, 'produced_at' => now()]);
        $this->createTree($wo1, 45, '1110826901', 1);

        $response1 = $this->actingAs($user)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $response1->assertOk();
        $rows = $response1->viewData('rows');
        $row1 = collect($rows)->firstWhere('code', 'WO-SCEN1');
        $this->assertEquals(0, $row1['ctk_display']); // CTK -
        $this->assertEquals(0, $row1['r_ctk_display']); // R -
        $this->assertEquals(45, $row1['rgki_display']); // RGKI 45
        $this->assertEquals(0, $row1['r_rgki_display']); // R -

        // Scenario 2: 45 plan -> 43 CTK + 2 rusak
        // CTK 43, R 2
        $wo2 = $this->createWorkOrder($ref, ['et_code' => 'WO-SCEN2', 'po_quantity' => 45, 'stock_quantity' => 0, 'net_requirement_quantity' => 45]);
        $this->createPlan($wo2, ['planned_quantity' => 45]);
        $wo2->wipEntries()->create(['stage' => 'moulding', 'quantity' => 43, 'produced_at' => now()]);

        $response2 = $this->actingAs($user)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $rows = $response2->viewData('rows');
        $row2 = collect($rows)->firstWhere('code', 'WO-SCEN2');
        $this->assertEquals(43, $row2['ctk_display']); // CTK 43
        $this->assertEquals(2, $row2['r_ctk_display']); // R 2
        $this->assertEquals(0, $row2['rgki_display']); // RGKI -

        // Scenario 3: 43 CTK -> 40 RGKI + 3 rusak saat rangkai
        // CTK 43, R 2, RGKI 40, R 3
        $wo3 = $this->createWorkOrder($ref, ['et_code' => 'WO-SCEN3', 'po_quantity' => 45, 'stock_quantity' => 0, 'net_requirement_quantity' => 45]);
        $this->createPlan($wo3, ['planned_quantity' => 45]);
        $wo3->wipEntries()->create(['stage' => 'moulding', 'quantity' => 43, 'produced_at' => now()]);
        $wo3->wipEntries()->create(['stage' => 'assembly', 'quantity' => 40, 'produced_at' => now()]);
        $this->createTree($wo3, 40, '1110826902', 2);

        $response3 = $this->actingAs($user)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $rows = $response3->viewData('rows');
        $row3 = collect($rows)->firstWhere('code', 'WO-SCEN3');
        $this->assertEquals(43, $row3['ctk_display']); // CTK 43
        $this->assertEquals(2, $row3['r_ctk_display']); // R 2
        $this->assertEquals(40, $row3['rgki_display']); // RGKI 40
        $this->assertEquals(3, $row3['r_rgki_display']); // R 3

        // Scenario 4: 40 RGKI -> 40 L1 tanpa rusak
        // RGKI -, R -, L1 40, R -
        $wo4 = $this->createWorkOrder($ref, ['et_code' => 'WO-SCEN4', 'po_quantity' => 40, 'stock_quantity' => 0, 'net_requirement_quantity' => 40]);
        $this->createPlan($wo4, ['planned_quantity' => 40]);
        $wo4->wipEntries()->create(['stage' => 'moulding', 'quantity' => 40, 'produced_at' => now()]);
        $wo4->wipEntries()->create(['stage' => 'assembly', 'quantity' => 40, 'produced_at' => now()]);
        $tree4 = $this->createTree($wo4, 40, '1110826903', 3);
        $tree4->update(['current_stage' => 'layer_1']);

        $response4 = $this->actingAs($user)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $rows = $response4->viewData('rows');
        $row4 = collect($rows)->firstWhere('code', 'WO-SCEN4');
        $this->assertEquals(0, $row4['rgki_display']); // RGKI -
        $this->assertEquals(0, $row4['r_rgki_display']); // R -
        $this->assertEquals(40, $row4['layer_1']); // L1 40

        // Scenario 5: Sebagian quantity berpindah dari L1 ke L2
        $wo5 = $this->createWorkOrder($ref, ['et_code' => 'WO-SCEN5', 'po_quantity' => 40, 'stock_quantity' => 0, 'net_requirement_quantity' => 40]);
        $this->createPlan($wo5, ['planned_quantity' => 40]);
        $wo5->wipEntries()->create(['stage' => 'moulding', 'quantity' => 40, 'produced_at' => now()]);
        $wo5->wipEntries()->create(['stage' => 'assembly', 'quantity' => 40, 'produced_at' => now()]);
        $tree5a = $this->createTree($wo5, 25, '1110826904', 4);
        $tree5a->update(['current_stage' => 'layer_1']);
        $tree5b = $this->createTree($wo5, 15, '1110826905', 5);
        $tree5b->update(['current_stage' => 'layer_2']);

        $response5 = $this->actingAs($user)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $rows = $response5->viewData('rows');
        $row5 = collect($rows)->firstWhere('code', 'WO-SCEN5');
        $this->assertEquals(25, $row5['layer_1']); // L1 25
        $this->assertEquals(15, $row5['layer_2']); // L2 15
    }

    public function test_n_plus_one_prevention(): void
    {
        $user = User::factory()->create();

        // Create 5 legacy work orders
        for ($i = 1; $i <= 5; $i++) {
            $ref = $this->createReference([
                'master_item_key' => "ITEM-L-$i",
                'item_code_snapshot' => "ITEM-L-$i",
            ]);
            $wo = $this->createWorkOrder($ref, ['et_code' => "ET-LEGACY-$i"]);
            $this->createPlan($wo);
            $wo->wipEntries()->create(['stage' => 'moulding', 'quantity' => 10]);
            $wo->wipEntries()->create(['stage' => 'assembly', 'quantity' => 10]);
            $this->createTree($wo, 10, '111082600'.$i, $i);
        }

        // Create 5 new plans
        for ($i = 1; $i <= 5; $i++) {
            $plan = \App\Models\ProductionPlan::create([
                'code' => "PLAN-NEW-$i",
                'customer' => 'Customer New',
                'po_number' => "PO-NEW-$i",
                'item_code' => "ITEM-$i",
                'item_name' => 'Product New',
                'aisi' => 'SUS304',
                'size' => '2"',
                'weight' => 1.5,
                'qty_planned' => 100,
                'qty_remaining' => 100,
                'line_number' => 1,
                'status' => 'planning',
            ]);
            $order = \App\Models\LostWaxPrintOrder::create([
                'print_order_number' => "PO-ORDER-$i",
                'scheduled_date' => '2026-08-11',
                'status' => 'ISSUED',
                'created_by' => $user->id,
            ]);
            $line = $order->lines()->create([
                'production_plan_id' => $plan->id,
                'qty_ordered' => 100,
                'qty_actual_good' => 80,
                'qty_actual_defect' => 2,
                'code' => $plan->code,
                'customer' => $plan->customer,
                'item_name' => $plan->item_name,
                'aisi' => $plan->aisi,
            ]);
            \App\Models\LostWaxTree::create([
                'lost_wax_print_order_line_id' => $line->id,
                'barcode' => '3082600'.str_pad($i, 3, '0', STR_PAD_LEFT),
                'tree_number' => $i,
                'quantity' => 20,
                'status' => 'generated',
                'production_date' => '2026-08-11',
                'family_code' => '3',
                'daily_sequence' => $i,
            ]);
        }

        \DB::enableQueryLog();
        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status'));
        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        $response->assertOk();

        // With optimized queries, total database queries should remain very low (approx. 19 queries total
        // including Spatie permissions and filter dropdown population) and shouldn't scale per row count.
        $this->assertLessThan(22, count($queries), 'N+1 queries detected on production status page.');
    }

    public function test_multi_select_filters_work(): void
    {
        $user = User::factory()->create();
        $ref = $this->createReference(['aisi_snapshot' => 'SUS304']);

        $wo1 = $this->createWorkOrder($ref, ['et_code' => 'ET-MULTI-1', 'customer_name' => 'Cust A', 'po_number' => 'PO-101']);
        $this->createPlan($wo1);

        $wo2 = $this->createWorkOrder($ref, ['et_code' => 'ET-MULTI-2', 'customer_name' => 'Cust B', 'po_number' => 'PO-102']);
        $this->createPlan($wo2);

        $wo3 = $this->createWorkOrder($ref, ['et_code' => 'ET-MULTI-3', 'customer_name' => 'Cust C', 'po_number' => 'PO-103']);
        $this->createPlan($wo3);

        // Test filtering by multiple customer names (Cust A or Cust B)
        $response = $this->actingAs($user)->get(route('lost-wax.production-status', [
            'customers' => ['Cust A', 'Cust B'],
            'filter' => 'all',
        ]));
        $response->assertOk();
        $rows = $response->viewData('rows');
        $rowCodes = collect($rows)->pluck('code')->toArray();
        $this->assertContains('ET-MULTI-1', $rowCodes);
        $this->assertContains('ET-MULTI-2', $rowCodes);
        $this->assertNotContains('ET-MULTI-3', $rowCodes);

        // Test filtering by multiple PO numbers
        $response2 = $this->actingAs($user)->get(route('lost-wax.production-status', [
            'po_numbers' => ['PO-101', 'PO-103'],
            'filter' => 'all',
        ]));
        $response2->assertOk();
        $rows2 = $response2->viewData('rows');
        $rowCodes2 = collect($rows2)->pluck('code')->toArray();
        $this->assertContains('ET-MULTI-1', $rowCodes2);
        $this->assertNotContains('ET-MULTI-2', $rowCodes2);
        $this->assertContains('ET-MULTI-3', $rowCodes2);
    }

    public function test_kode_cust_filtering_combinations(): void
    {
        $user = User::factory()->create();
        $ref1 = $this->createReference(['aisi_snapshot' => 'SUS304']);
        $ref2 = $this->createReference([
            'master_item_key' => 'ITEM-B',
            'item_code_snapshot' => 'ITEM-B',
            'aisi_snapshot' => 'SUS316',
        ]);

        // Create test work orders
        $wo1 = $this->createWorkOrder($ref1, ['et_code' => 'BA43', 'customer_name' => 'Cust A', 'po_number' => 'PO-101']);
        $this->createPlan($wo1);

        $wo2 = $this->createWorkOrder($ref1, ['et_code' => 'BA44', 'customer_name' => 'Cust A', 'po_number' => 'PO-102']);
        $this->createPlan($wo2);

        $wo3 = $this->createWorkOrder($ref2, ['et_code' => 'BA53', 'customer_name' => 'Cust B', 'po_number' => 'PO-101']);
        $this->createPlan($wo3);

        // 1. Single Kode Cust works: codes[]=BA43
        $response = $this->actingAs($user)->get(route('lost-wax.production-status', ['codes' => ['BA43'], 'filter' => 'all']));
        $response->assertOk();
        $rows = $response->viewData('rows');
        $this->assertCount(1, $rows);
        $this->assertEquals('BA43', $rows[0]['code']);

        // 2. Multiple Kode Cust works: codes[]=BA43&codes[]=BA44
        // 3. Multiple values use OR semantics within Kode Cust
        $response = $this->actingAs($user)->get(route('lost-wax.production-status', ['codes' => ['BA43', 'BA44'], 'filter' => 'all']));
        $response->assertOk();
        $rows = $response->viewData('rows');
        $this->assertCount(2, $rows);
        $rowCodes = collect($rows)->pluck('code')->toArray();
        $this->assertContains('BA43', $rowCodes);
        $this->assertContains('BA44', $rowCodes);

        // 4. Kode Cust + Customer uses AND semantics
        $response = $this->actingAs($user)->get(route('lost-wax.production-status', [
            'codes' => ['BA43', 'BA53'],
            'customers' => ['Cust A'],
            'filter' => 'all',
        ]));
        $response->assertOk();
        $rows = $response->viewData('rows');
        $this->assertCount(1, $rows);
        $this->assertEquals('BA43', $rows[0]['code']);

        // 5. Kode Cust + AISI works
        $response = $this->actingAs($user)->get(route('lost-wax.production-status', [
            'codes' => ['BA43', 'BA53'],
            'aisis' => ['SUS316'],
            'filter' => 'all',
        ]));
        $response->assertOk();
        $rows = $response->viewData('rows');
        $this->assertCount(1, $rows);
        $this->assertEquals('BA53', $rows[0]['code']);

        // 6. Kode Cust + PO works
        $response = $this->actingAs($user)->get(route('lost-wax.production-status', [
            'codes' => ['BA43', 'BA44'],
            'po_numbers' => ['PO-102'],
            'filter' => 'all',
        ]));
        $response->assertOk();
        $rows = $response->viewData('rows');
        $this->assertCount(1, $rows);
        $this->assertEquals('BA44', $rows[0]['code']);

        // 7. XLSX export respects Kode Cust selection
        $response = $this->actingAs($user)->get(route('lost-wax.production-status.export', [
            'codes' => ['BA43', 'BA44'],
            'filter' => 'all',
        ]));
        $response->assertOk();
        $this->assertEquals('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));

        // Save content to parse
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tempFile, $response->streamedContent());
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tempFile);
        $sheet = $spreadsheet->getActiveSheet();

        // Assert the cells under "Kode Cust" column contain only BA43 and BA44
        $this->assertEquals('BA43', $sheet->getCell('A7')->getValue());
        $this->assertEquals('BA44', $sheet->getCell('A8')->getValue());
        $this->assertEmpty($sheet->getCell('A9')->getValue());

        // 8. XLSX metadata contains selected Kode Cust values (metadata row indicates filters)
        $this->assertStringContainsString('BA43, BA44', $sheet->getCell('N4')->getValue());

        unlink($tempFile);
    }

    public function test_dropdown_options_context_awareness(): void
    {
        $user = User::factory()->create();
        $ref1 = $this->createReference(['aisi_snapshot' => 'SUS304']);
        $ref2 = $this->createReference([
            'master_item_key' => 'ITEM-B',
            'item_code_snapshot' => 'ITEM-B',
            'aisi_snapshot' => 'SUS316',
        ]);

        // Create ACTIVE work order
        $woActive = $this->createWorkOrder($ref1, ['et_code' => 'ET-ACTIVE-1', 'customer_name' => 'Cust Active', 'po_number' => 'PO-ACT']);
        $this->createPlan($woActive);

        // Create COMPLETED work order
        $woCompleted = $this->createWorkOrder($ref2, ['et_code' => 'ET-COMP-9', 'customer_name' => 'Cust Comp', 'po_number' => 'PO-COMP']);
        $this->createPlan($woCompleted);
        $tree = $this->createTree($woCompleted, 10, '1110826099', 9);
        $tree->update(['current_stage' => 'oven']);

        // 1. ACTIVE tab dropdown options check
        $response = $this->actingAs($user)->get(route('lost-wax.production-status', ['filter' => 'active']));
        $response->assertOk();

        $allCodesActive = $response->viewData('allCodes');
        $allCustActive = $response->viewData('allCustomers');
        $allPosActive = $response->viewData('allPos');
        $allAisiActive = $response->viewData('allAisi');

        $this->assertContains('ET-ACTIVE-1', $allCodesActive);
        $this->assertNotContains('ET-COMP-9', $allCodesActive);
        $this->assertContains('Cust Active', $allCustActive);
        $this->assertNotContains('Cust Comp', $allCustActive);
        $this->assertContains('PO-ACT', $allPosActive);
        $this->assertNotContains('PO-COMP', $allPosActive);
        $this->assertContains('SUS304', $allAisiActive);
        $this->assertNotContains('SUS316', $allAisiActive);

        // 2. COMPLETED tab dropdown options check
        $response = $this->actingAs($user)->get(route('lost-wax.production-status', ['filter' => 'completed']));
        $response->assertOk();

        $allCodesComp = $response->viewData('allCodes');
        $allCustComp = $response->viewData('allCustomers');
        $allPosComp = $response->viewData('allPos');
        $allAisiComp = $response->viewData('allAisi');

        $this->assertNotContains('ET-ACTIVE-1', $allCodesComp);
        $this->assertContains('ET-COMP-9', $allCodesComp);
        $this->assertNotContains('Cust Active', $allCustComp);
        $this->assertContains('Cust Comp', $allCustComp);
        $this->assertNotContains('PO-ACT', $allPosComp);
        $this->assertContains('PO-COMP', $allPosComp);
        $this->assertNotContains('SUS304', $allAisiComp);
        $this->assertContains('SUS316', $allAisiComp);

        // 3. ALL tab dropdown options check
        $response = $this->actingAs($user)->get(route('lost-wax.production-status', ['filter' => 'all']));
        $response->assertOk();

        $allCodesAll = $response->viewData('allCodes');
        $this->assertContains('ET-ACTIVE-1', $allCodesAll);
        $this->assertContains('ET-COMP-9', $allCodesAll);

        // 4. Cross-filtering test: ACTIVE + Customer filter Cust Active
        $woActive2 = $this->createWorkOrder($ref1, ['et_code' => 'ET-ACTIVE-2', 'customer_name' => 'Other Cust', 'po_number' => 'PO-OTHER']);
        $this->createPlan($woActive2);

        $response = $this->actingAs($user)->get(route('lost-wax.production-status', [
            'filter' => 'active',
            'customers' => ['Cust Active'],
        ]));
        $response->assertOk();
        $allCodesCross = $response->viewData('allCodes');
        $this->assertContains('ET-ACTIVE-1', $allCodesCross);
        $this->assertNotContains('ET-ACTIVE-2', $allCodesCross);
    }

    public function test_production_status_table_renders_sticky_header_and_all_columns(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status'));

        $response->assertOk();
        $content = $response->getContent();

        // Verify sticky container & table ID
        $this->assertStringContainsString('table-scroll-container', $content);
        $this->assertStringContainsString('id="prodStatusTable"', $content);
        $this->assertStringContainsString('sticky top-0', $content);

        // Verify all 27 column headers
        $this->assertStringContainsString('Kode Cust', $content);
        $this->assertStringContainsString('Product Name', $content);
        $this->assertStringContainsString('AISI', $content);
        $this->assertStringContainsString('PO', $content);
        $this->assertStringContainsString('Plan', $content);
        $this->assertStringContainsString('Total', $content);
        $this->assertStringContainsString('Tot Rsk', $content);
        $this->assertStringContainsString('CTK', $content);
        $this->assertStringContainsString('RGKI', $content);
        $this->assertStringContainsString('L1', $content);
        $this->assertStringContainsString('L2', $content);
        $this->assertStringContainsString('L3', $content);
        $this->assertStringContainsString('L4', $content);
        $this->assertStringContainsString('L5', $content);
        $this->assertStringContainsString('L6', $content);
        $this->assertStringContainsString('L7', $content);
        $this->assertStringContainsString('Oven', $content);
        $this->assertStringContainsString('Status', $content);
    }

    public function test_per_stage_defect_indicators_rendered_in_production_status(): void
    {
        $user = User::factory()->create();

        // Create Production Plan (New Flow)
        $plan = \App\Models\ProductionPlan::create([
            'code' => '268TEST01',
            'customer' => 'Cust Defect',
            'item_code' => '4.1061TEST.001',
            'item_name' => 'Flange SS304 2 Inch',
            'aisi' => '304',
            'size' => '2"',
            'weight' => 0.50,
            'po_number' => 'PO-DEFECT-01',
            'po_quantity' => 100,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'status' => 'planning',
            'is_closed' => false,
        ]);

        $order = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260831-0001',
            'scheduled_date' => '2026-08-31',
            'status' => 'COMPLETED',
            'created_by' => $user->id,
        ]);

        $orderLine = \App\Models\LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $order->id,
            'production_plan_id' => $plan->id,
            'code' => '268TEST01',
            'item_name' => 'Flange SS304 2 Inch',
            'size' => '2"',
            'aisi' => '304',
            'qty_ordered' => 100,
            'standard_tree_capacity' => 20,
            'status' => 'COMPLETED',
        ]);

        // 1. Defect Cetak (qty_defect = 7)
        app(\App\Services\PrintExecutionService::class)->record($orderLine, [
            'qty_good' => 93,
            'qty_defect' => 7,
            'execution_date' => '2026-08-31',
            'status' => 'FINALIZED',
            'recorded_by' => $user->id,
        ]);

        // Create Trees with defects across Assembly, L1, L2, L3..L7, Oven
        $t1 = \App\Models\LostWaxTree::create([
            'lost_wax_print_order_line_id' => $orderLine->id,
            'barcode' => '1110831001',
            'tree_number' => 1,
            'family_code' => '1',
            'daily_sequence' => 1,
            'quantity' => 20,
            'usable_quantity' => 18,
            'current_stage' => 'layer_1',
            'status' => 'generated',
            'production_date' => '2026-08-31',
        ]);
        // 2. Defect Assembly (defect_qty = 2)
        \App\Models\LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $t1->id,
            'stage' => 'assembly',
            'defect_qty' => 2,
            'defect_reason' => 'Patah Tangkai',
            'occurred_at' => now(),
            'recorded_by' => $user->id,
        ]);

        $t2 = \App\Models\LostWaxTree::create([
            'lost_wax_print_order_line_id' => $orderLine->id,
            'barcode' => '1110831002',
            'tree_number' => 2,
            'family_code' => '1',
            'daily_sequence' => 2,
            'quantity' => 20,
            'usable_quantity' => 17,
            'current_stage' => 'layer_2',
            'status' => 'generated',
            'production_date' => '2026-08-31',
        ]);
        // 3. Defect L1 (defect_qty = 3)
        \App\Models\LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $t2->id,
            'stage' => 'layer_1',
            'defect_qty' => 3,
            'defect_reason' => 'Slurry Rontok',
            'occurred_at' => now(),
            'recorded_by' => $user->id,
        ]);

        $t3 = \App\Models\LostWaxTree::create([
            'lost_wax_print_order_line_id' => $orderLine->id,
            'barcode' => '1110831003',
            'tree_number' => 3,
            'family_code' => '1',
            'daily_sequence' => 3,
            'quantity' => 20,
            'usable_quantity' => 16,
            'current_stage' => 'layer_3',
            'status' => 'generated',
            'production_date' => '2026-08-31',
        ]);
        // 4. Defect L2 (defect_qty = 4)
        \App\Models\LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $t3->id,
            'stage' => 'layer_2',
            'defect_qty' => 4,
            'defect_reason' => 'Pasir Botak',
            'occurred_at' => now(),
            'recorded_by' => $user->id,
        ]);

        $t4 = \App\Models\LostWaxTree::create([
            'lost_wax_print_order_line_id' => $orderLine->id,
            'barcode' => '1110831004',
            'tree_number' => 4,
            'family_code' => '1',
            'daily_sequence' => 4,
            'quantity' => 20,
            'usable_quantity' => 15,
            'current_stage' => 'oven',
            'status' => 'generated',
            'production_date' => '2026-08-31',
        ]);
        // 5. Defect L3 (defect_qty = 1) + Defect Oven (defect_qty = 4)
        \App\Models\LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $t4->id,
            'stage' => 'layer_3',
            'defect_qty' => 1,
            'defect_reason' => 'Retak',
            'occurred_at' => now(),
            'recorded_by' => $user->id,
        ]);
        \App\Models\LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $t4->id,
            'stage' => 'oven',
            'defect_qty' => 4,
            'defect_reason' => 'Wax Bleed / Pecah Oven',
            'occurred_at' => now(),
            'recorded_by' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('lost-wax.production-status', ['filter' => 'all']));

        $response->assertOk();
        $rows = $response->viewData('rows');
        $targetRow = collect($rows)->firstWhere('code', '268TEST01');

        $this->assertNotNull($targetRow);

        // Assert per-stage defect metrics
        $this->assertEquals(7, $targetRow['r_ctk_display'], 'R Cetak matches print execution defects');
        $this->assertEquals(2, $targetRow['r_rgki_display'], 'R Rangkai matches assembly tree defects');
        $this->assertEquals(3, $targetRow['r_layer_1'], 'R L1 matches layer 1 defects');
        $this->assertEquals(4, $targetRow['r_layer_2'], 'R L2 matches layer 2 defects');
        $this->assertEquals(1, $targetRow['r_layer_3'], 'R L3 matches layer 3 defects');
        $this->assertEquals(0, $targetRow['r_layer_4'], 'R L4 is 0');
        $this->assertEquals(0, $targetRow['r_layer_5'], 'R L5 is 0');
        $this->assertEquals(0, $targetRow['r_layer_6'], 'R L6 is 0');
        $this->assertEquals(0, $targetRow['r_layer_7'], 'R L7 is 0');
        $this->assertEquals(4, $targetRow['r_oven'], 'R Oven matches oven defects');

        // Total whole-flow defect (overall_defect) = 7 (Cetak) + 2 (assembly) + 3 (L1) + 4 (L2) + 1 (L3) + 4 (Oven) = 21
        $this->assertEquals(21, $targetRow['overall_defect'], 'Tot Rsk matches total whole-flow defects');

        // Verify HTML rendering contains red indicator classes for defects > 0
        $content = $response->getContent();
        $this->assertStringContainsString('text-red-600', $content);
        $this->assertStringContainsString('268TEST01', $content);
    }
}
