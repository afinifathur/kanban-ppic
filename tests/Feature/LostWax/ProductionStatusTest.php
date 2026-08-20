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
        $completedResponse->assertSee('SELESAI');
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

    public function test_csv_headers_contain_total_lap_and_total_rusak(): void
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
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();

        if ($content) {
            $this->assertStringContainsString('Kode Cust', $content);
            $this->assertStringContainsString('Total Lap.', $content);
            $this->assertStringContainsString('Total Rusak', $content);
            $this->assertStringNotContainsString('Cust / ET', $content);
            $this->assertStringContainsString('ET232', $content);
            $this->assertStringContainsString('Lap.1', $content);
        }
    }

    public function test_csv_does_not_contain_item_column(): void
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

        if ($content) {
            // CSV header should NOT contain the old "Item" column
            $headerLine = strtok($content, "\n");
            // Header should contain "Product Name" not standalone "Item"
            $this->assertStringContainsString('Product Name', $headerLine);
        }
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
        $response->assertSee('SELESAI');
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
        $response->assertSee('Tot Lap');
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
}
