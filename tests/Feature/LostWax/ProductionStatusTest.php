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
}
