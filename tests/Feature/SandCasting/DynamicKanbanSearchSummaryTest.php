<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\User;
use App\Services\SandCasting\SandCastingStageExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DynamicKanbanSearchSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $spvNetto;

    protected User $salesUser;

    protected SandCastingStageExecutionService $executionService;

    protected function setUp(): void
    {
        parent::setUp();

        $planPerm = Permission::firstOrCreate(['name' => 'access_planning']);
        $visPerm = Permission::firstOrCreate(['name' => 'access_production_visibility']);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->givePermissionTo($planPerm);

        $spvRole = Role::firstOrCreate(['name' => 'spv']);

        $salesRole = Role::firstOrCreate(['name' => 'sales']);
        $salesRole->givePermissionTo($visPerm);

        $this->adminUser = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->adminUser->assignRole('admin');

        $this->spvNetto = User::factory()->create([
            'name' => 'SPV Netto',
            'email' => 'spv_netto@peroniks.com',
            'assigned_stage' => 'netto',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvNetto->assignRole('spv');

        $this->salesUser = User::factory()->create([
            'name' => 'Sales Staff',
            'email' => 'sales@peroniks.com',
            'product_scope' => null,
        ]);
        $this->salesUser->assignRole('sales');

        $this->executionService = new SandCastingStageExecutionService;
    }

    protected function createKtr(array $attributes = []): SandCastingCastingResultLine
    {
        static $seq = 1;
        $idx = $seq++;

        $plan = ProductionPlan::create([
            'code' => $attributes['production_code'] ?? '268ET'.str_pad((string) $idx, 3, '0', STR_PAD_LEFT),
            'title' => 'Rencana SC '.$idx,
            'item_code' => $attributes['item_code'] ?? '4.'.str_pad((string) $idx, 3, '0', STR_PAD_LEFT),
            'item_name' => $attributes['item_name'] ?? 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-'.$idx,
            'line_number' => $attributes['line_number'] ?? 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'customer' => $attributes['customer'] ?? 'PT SINAR METAL',
            'product_scope' => $attributes['product_scope'] ?? 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261030-'.str_pad((string) $idx, 4, '0', STR_PAD_LEFT),
            'scheduled_date' => '2026-10-30',
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $unitWeight = (float) ($attributes['unit_weight_kg'] ?? 2.50);
        $qtyGood = (int) ($attributes['qty_good'] ?? 10);
        $totalWeight = isset($attributes['total_weight_kg']) ? (float) $attributes['total_weight_kg'] : round($qtyGood * $unitWeight, 2);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '2"',
            'unit_weight_kg' => $unitWeight,
            'total_weight_kg' => $totalWeight,
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => $attributes['heat_number'] ?? 'HEAT-'.$idx,
            'cast_date' => '2026-10-30',
            'furnace' => 'F1',
            'shift' => '1',
            'recorded_by' => $this->adminUser->id,
        ]);

        return SandCastingCastingResultLine::create([
            'sand_casting_casting_result_id' => $result->id,
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $attributes['traveler_number'] ?? 'KTR-20261030-'.str_pad((string) $idx, 4, '0', STR_PAD_LEFT),
            'qty_good' => $qtyGood,
            'qty_reject' => 0,
            'unit_weight_kg' => $unitWeight,
            'total_weight_kg' => $totalWeight,
            'current_stage' => $attributes['current_stage'] ?? 'netto',
        ]);
    }

    /**
     * Seed a standard 4-line dataset with known quantities and keywords.
     *
     * Line 1:
     *   - Card 1: 50 PCS, 100 KG (item: 'FLANGE LOKAL 2 INCH', customer: 'PT A')
     *   - Card 2: 30 PCS, 60 KG (item: 'VALVE BODY 3 INCH', customer: 'PT B')
     *   Line 1 Total: 80 PCS, 160 KG.
     *
     * Line 2:
     *   - Card 3: 40 PCS, 120 KG (item: 'SS304 LOKAL SPECIAL', customer: 'PT C')
     *   Line 2 Total: 40 PCS, 120 KG.
     *
     * Line 3:
     *   - Card 4: 20 PCS, 80 KG (item: 'ELBOW 90 DEGREE', customer: 'CV LOKAL JAYA')
     *   - Card 5: 10 PCS, 40 KG (item: 'TEE STANDARD', customer: 'PT E')
     *   Line 3 Total: 30 PCS, 120 KG.
     *
     * Line 4:
     *   - Card 6: 10 PCS, 50 KG (item: 'BLIND FLANGE 4 INCH', customer: 'PT F')
     *   Line 4 Total: 10 PCS, 50 KG.
     *
     * Grand Total (All Lines):
     *   - Total Cards: 6 Kartu
     *   - Total PCS: 160 PCS
     *   - Total KG: 450 KG
     *
     * Keyword 'lokal' matches:
     *   - Card 1 (Line 1): 50 PCS, 100 KG
     *   - Card 3 (Line 2): 40 PCS, 120 KG
     *   - Card 4 (Line 3): 20 PCS, 80 KG
     *   Lokal Matches Total: 3 Kartu, 110 PCS, 300 KG.
     *   Line 1: 50 PCS, 100 KG
     *   Line 2: 40 PCS, 120 KG
     *   Line 3: 20 PCS, 80 KG
     *   Line 4: 0 PCS, 0 KG
     */
    protected function seedStandardKanbanDataset(): array
    {
        $c1 = $this->createKtr([
            'line_number' => 1,
            'qty_good' => 50,
            'unit_weight_kg' => 2.0,
            'total_weight_kg' => 100.0,
            'item_name' => 'FLANGE LOKAL 2 INCH',
            'customer' => 'PT A',
            'traveler_number' => 'KTR-L1-01',
            'heat_number' => 'HEAT-L1-01',
        ]);

        $c2 = $this->createKtr([
            'line_number' => 1,
            'qty_good' => 30,
            'unit_weight_kg' => 2.0,
            'total_weight_kg' => 60.0,
            'item_name' => 'VALVE BODY 3 INCH',
            'customer' => 'PT B',
            'traveler_number' => 'KTR-L1-02',
            'heat_number' => 'HEAT-L1-02',
        ]);

        $c3 = $this->createKtr([
            'line_number' => 2,
            'qty_good' => 40,
            'unit_weight_kg' => 3.0,
            'total_weight_kg' => 120.0,
            'item_name' => 'SS304 LOKAL SPECIAL',
            'customer' => 'PT C',
            'traveler_number' => 'KTR-L2-01',
            'heat_number' => 'HEAT-L2-01',
        ]);

        $c4 = $this->createKtr([
            'line_number' => 3,
            'qty_good' => 20,
            'unit_weight_kg' => 4.0,
            'total_weight_kg' => 80.0,
            'item_name' => 'ELBOW 90 DEGREE',
            'customer' => 'CV LOKAL JAYA',
            'traveler_number' => 'KTR-L3-01',
            'heat_number' => 'HEAT-L3-01',
        ]);

        $c5 = $this->createKtr([
            'line_number' => 3,
            'qty_good' => 10,
            'unit_weight_kg' => 4.0,
            'total_weight_kg' => 40.0,
            'item_name' => 'TEE STANDARD',
            'customer' => 'PT E',
            'traveler_number' => 'KTR-L3-02',
            'heat_number' => 'HEAT-L3-02',
        ]);

        $c6 = $this->createKtr([
            'line_number' => 4,
            'qty_good' => 10,
            'unit_weight_kg' => 5.0,
            'total_weight_kg' => 50.0,
            'item_name' => 'BLIND FLANGE 4 INCH',
            'customer' => 'PT F',
            'traveler_number' => 'KTR-L4-01',
            'heat_number' => 'HEAT-L4-01',
        ]);

        return [$c1, $c2, $c3, $c4, $c5, $c6];
    }

    public function test_no_search_filter_displays_original_totals_in_web_and_pdf(): void
    {
        $this->seedStandardKanbanDataset();

        // 1. Web View
        $response = $this->actingAs($this->spvNetto)
            ->get('/sand-casting/kanban/stage/netto');

        $response->assertStatus(200);

        // Top Hero Panel
        $response->assertSee('id="kanbanReadyTotalPcs"', false);
        $response->assertSee('id="kanbanReadyTotalKg"', false);
        $response->assertSee('id="kanbanReadyCardCount"', false);

        $html = $response->getContent();
        $this->assertStringContainsString('id="kanbanReadyTotalPcs">160</span>', $html);
        $this->assertStringContainsString('id="kanbanReadyTotalKg">450</span>', $html);
        $this->assertStringContainsString('id="kanbanReadyCardCount">6</span>', $html);

        // Per-line KG headers in 4-column layout
        $this->assertStringContainsString('data-line-kg="1">160</span>', $html);
        $this->assertStringContainsString('data-line-kg="2">120</span>', $html);
        $this->assertStringContainsString('data-line-kg="3">120</span>', $html);
        $this->assertStringContainsString('data-line-kg="4">50</span>', $html);

        // Per-line PCS headers stacked above KG in 4-column layout
        $this->assertStringContainsString('data-line-pcs="1">80</span>', $html);
        $this->assertStringContainsString('data-line-pcs="2">40</span>', $html);
        $this->assertStringContainsString('data-line-pcs="3">30</span>', $html);
        $this->assertStringContainsString('data-line-pcs="4">10</span>', $html);

        // Verify line total sum equals overall total (160 + 120 + 120 + 50 = 450 KG, 80 + 40 + 30 + 10 = 160 PCS)
        $this->assertSame(450, 160 + 120 + 120 + 50);
        $this->assertSame(160, 80 + 40 + 30 + 10);

        // 2. PDF Print View
        $pdfRes = $this->actingAs($this->spvNetto)
            ->get('/sand-casting/kanban/stage/netto/print');

        $pdfRes->assertStatus(200);
        $pdfRes->assertSee('6 Kartu Aktif');
        $pdfRes->assertSee('160 PCS');
        $pdfRes->assertSee('450 KG');
        $pdfRes->assertSee('LINE 1');
        $pdfRes->assertSee('LINE 2');
        $pdfRes->assertSee('LINE 3');
        $pdfRes->assertSee('LINE 4');
    }

    public function test_search_lokal_only_matching_cards_contribute_to_totals_matching_pdf(): void
    {
        $this->seedStandardKanbanDataset();

        // Server-rendered search request for 'lokal'
        $response = $this->actingAs($this->spvNetto)
            ->get('/sand-casting/kanban/stage/netto?search=lokal');

        $response->assertStatus(200);
        $html = $response->getContent();

        // Overall matching cards: 3 Kartu, 110 PCS, 300 KG
        $this->assertStringContainsString('id="kanbanReadyTotalPcs">110</span>', $html);
        $this->assertStringContainsString('id="kanbanReadyTotalKg">300</span>', $html);
        $this->assertStringContainsString('id="kanbanReadyCardCount">3</span>', $html);

        // Per-line headers for 'lokal' matches:
        // Line 1: Card 1 (50 PCS, 100 KG)
        $this->assertStringContainsString('data-line-pcs="1">50</span>', $html);
        $this->assertStringContainsString('data-line-kg="1">100</span>', $html);
        // Line 2: Card 3 (40 PCS, 120 KG)
        $this->assertStringContainsString('data-line-pcs="2">40</span>', $html);
        $this->assertStringContainsString('data-line-kg="2">120</span>', $html);
        // Line 3: Card 4 (20 PCS, 80 KG)
        $this->assertStringContainsString('data-line-pcs="3">20</span>', $html);
        $this->assertStringContainsString('data-line-kg="3">80</span>', $html);
        // Line 4: 0 PCS, 0 KG
        $this->assertStringContainsString('data-line-pcs="4">0</span>', $html);
        $this->assertStringContainsString('data-line-kg="4">0</span>', $html);

        // Matching items are present, non-matching excluded
        $response->assertSee('FLANGE LOKAL 2 INCH');
        $response->assertSee('SS304 LOKAL SPECIAL');
        $response->assertSee('ELBOW 90 DEGREE');
        $response->assertDontSee('VALVE BODY 3 INCH');
        $response->assertDontSee('TEE STANDARD');
        $response->assertDontSee('BLIND FLANGE 4 INCH');

        // Check exact match with PDF
        $pdfRes = $this->actingAs($this->spvNetto)
            ->get('/sand-casting/kanban/stage/netto/print?search=lokal');

        $pdfRes->assertStatus(200);
        $pdfRes->assertSee('3 Kartu Aktif');
        $pdfRes->assertSee('110 PCS');
        $pdfRes->assertSee('300 KG');
        $pdfRes->assertSee('Pencarian "<strong>lokal</strong>"', false);
    }

    public function test_search_term_matching_no_cards_yields_zero_totals(): void
    {
        $this->seedStandardKanbanDataset();

        $response = $this->actingAs($this->spvNetto)
            ->get('/sand-casting/kanban/stage/netto?search=TIDAKADAMATCH999');

        $response->assertStatus(200);
        $html = $response->getContent();

        // All summary numbers become zero
        $this->assertStringContainsString('id="kanbanReadyTotalPcs">0</span>', $html);
        $this->assertStringContainsString('id="kanbanReadyTotalKg">0</span>', $html);
        $this->assertStringContainsString('id="kanbanReadyCardCount">0</span>', $html);

        // Empty state is rendered when 0 cards match
        $response->assertSee('Tidak ada antrean pekerjaan.');

        // PDF print also renders 0 totals
        $pdfRes = $this->actingAs($this->spvNetto)
            ->get('/sand-casting/kanban/stage/netto/print?search=TIDAKADAMATCH999');

        $pdfRes->assertStatus(200);
        $pdfRes->assertSee('0 Kartu Aktif');
        $pdfRes->assertSee('0 PCS');
        $pdfRes->assertSee('0 KG');
    }

    public function test_line_with_zero_matching_cards_displays_zero_pcs_and_kg(): void
    {
        $this->seedStandardKanbanDataset();

        // Search for 'ELBOW' which only exists on Line 3 (Card 4: 20 PCS, 80 KG)
        $response = $this->actingAs($this->spvNetto)
            ->get('/sand-casting/kanban/stage/netto?search=ELBOW');

        $response->assertStatus(200);
        $html = $response->getContent();

        // Line 1, 2, 4 have 0 matching cards and display 0 PCS and 0 KG
        $this->assertStringContainsString('data-line-pcs="1">0</span>', $html);
        $this->assertStringContainsString('data-line-kg="1">0</span>', $html);
        $this->assertStringContainsString('data-line-pcs="2">0</span>', $html);
        $this->assertStringContainsString('data-line-kg="2">0</span>', $html);
        $this->assertStringContainsString('data-line-pcs="3">20</span>', $html);
        $this->assertStringContainsString('data-line-kg="3">80</span>', $html);
        $this->assertStringContainsString('data-line-pcs="4">0</span>', $html);
        $this->assertStringContainsString('data-line-kg="4">0</span>', $html);

        $this->assertStringContainsString('id="kanbanReadyTotalPcs">20</span>', $html);
        $this->assertStringContainsString('id="kanbanReadyTotalKg">80</span>', $html);
        $this->assertStringContainsString('id="kanbanReadyCardCount">1</span>', $html);
    }

    public function test_single_line_filter_totals_reflect_selected_line(): void
    {
        $this->seedStandardKanbanDataset();

        // Filter only Line 1
        $response = $this->actingAs($this->spvNetto)
            ->get('/sand-casting/kanban/stage/netto?line_number=1');

        $response->assertStatus(200);
        $html = $response->getContent();

        // Line 1 has Card 1 (50 pcs, 100 kg) + Card 2 (30 pcs, 60 kg) = 80 pcs, 160 kg, 2 cards
        $this->assertStringContainsString('id="kanbanReadyTotalPcs">80</span>', $html);
        $this->assertStringContainsString('id="kanbanReadyTotalKg">160</span>', $html);
        $this->assertStringContainsString('id="kanbanReadyCardCount">2</span>', $html);

        // Single line header
        $this->assertStringContainsString('data-single-line-count="1">2</span>', $html);
        $this->assertStringContainsString('data-single-line-pcs="1">80</span>', $html);
        $this->assertStringContainsString('data-line-pcs="1"', $html);
        $this->assertStringContainsString('data-line-kg="1">160</span>', $html);

        // Other lines not rendered
        $response->assertDontSee('SS304 LOKAL SPECIAL');
        $response->assertDontSee('ELBOW 90 DEGREE');
        $response->assertDontSee('BLIND FLANGE 4 INCH');

        // Matches PDF with line_number=1
        $pdfRes = $this->actingAs($this->spvNetto)
            ->get('/sand-casting/kanban/stage/netto/print?line_number=1');

        $pdfRes->assertStatus(200);
        $pdfRes->assertSee('2 Kartu Aktif');
        $pdfRes->assertSee('80 PCS');
        $pdfRes->assertSee('160 KG');
        $pdfRes->assertSee('Line 1');
    }

    public function test_search_plus_line_filter_applied_together(): void
    {
        $this->seedStandardKanbanDataset();

        // Search 'lokal' on Line 1 -> only Card 1 (50 pcs, 100 kg)
        $response = $this->actingAs($this->spvNetto)
            ->get('/sand-casting/kanban/stage/netto?line_number=1&search=lokal');

        $response->assertStatus(200);
        $html = $response->getContent();

        $this->assertStringContainsString('id="kanbanReadyTotalPcs">50</span>', $html);
        $this->assertStringContainsString('id="kanbanReadyTotalKg">100</span>', $html);
        $this->assertStringContainsString('id="kanbanReadyCardCount">1</span>', $html);

        $this->assertStringContainsString('data-single-line-count="1">1</span>', $html);
        $this->assertStringContainsString('data-single-line-pcs="1">50</span>', $html);
        $this->assertStringContainsString('data-line-pcs="1"', $html);
        $this->assertStringContainsString('data-line-kg="1">100</span>', $html);

        $response->assertSee('FLANGE LOKAL 2 INCH');
        $response->assertDontSee('VALVE BODY 3 INCH');

        // Matches PDF with both line_number=1 and search=lokal
        $pdfRes = $this->actingAs($this->spvNetto)
            ->get('/sand-casting/kanban/stage/netto/print?line_number=1&search=lokal');

        $pdfRes->assertStatus(200);
        $pdfRes->assertSee('1 Kartu Aktif');
        $pdfRes->assertSee('50 PCS');
        $pdfRes->assertSee('100 KG');
    }

    public function test_cards_have_required_dynamic_attributes_and_js_handlers(): void
    {
        $this->seedStandardKanbanDataset();

        $response = $this->actingAs($this->spvNetto)
            ->get('/sand-casting/kanban/stage/netto');

        $response->assertStatus(200);
        $html = $response->getContent();

        // Cards must have data-card-qty, data-card-weight, data-card-line, data-search-text
        $this->assertStringContainsString('data-card-qty="50"', $html);
        $this->assertStringContainsString('data-card-weight="100"', $html);
        $this->assertStringContainsString('data-card-line="1"', $html);
        $this->assertStringContainsString('data-search-text="', $html);

        // Javascript functions must be present in DOM
        $this->assertStringContainsString('function formatNumberId', $html);
        $this->assertStringContainsString('function applyKanbanSearch', $html);
        $this->assertStringContainsString('function clearKanbanSearch', $html);
        $this->assertStringContainsString('kanbanReadyTotalPcs', $html);
        $this->assertStringContainsString('kanbanReadyTotalKg', $html);
        $this->assertStringContainsString('kanbanReadyCardCount', $html);
        $this->assertStringContainsString('data-line-kg', $html);
        $this->assertStringContainsString('data-line-pcs', $html);
    }

    public function test_sales_and_admin_see_same_dynamically_filtered_summaries(): void
    {
        $this->seedStandardKanbanDataset();

        // Sales user (read-only visibility)
        $salesRes = $this->actingAs($this->salesUser)
            ->get('/sand-casting/kanban/stage/netto?search=lokal');

        $salesRes->assertStatus(200);
        $salesHtml = $salesRes->getContent();

        $this->assertStringContainsString('id="kanbanReadyTotalPcs">110</span>', $salesHtml);
        $this->assertStringContainsString('id="kanbanReadyTotalKg">300</span>', $salesHtml);
        $this->assertStringContainsString('id="kanbanReadyCardCount">3</span>', $salesHtml);

        // Admin user
        $adminRes = $this->actingAs($this->adminUser)
            ->get('/sand-casting/kanban/stage/netto?search=lokal');

        $adminRes->assertStatus(200);
        $adminHtml = $adminRes->getContent();

        $this->assertStringContainsString('id="kanbanReadyTotalPcs">110</span>', $adminHtml);
        $this->assertStringContainsString('id="kanbanReadyTotalKg">300</span>', $adminHtml);
        $this->assertStringContainsString('id="kanbanReadyCardCount">3</span>', $adminHtml);
    }

    public function test_sum_of_lines_strictly_reconciles_with_overall_kg(): void
    {
        $this->seedStandardKanbanDataset();

        $response = $this->actingAs($this->spvNetto)
            ->get('/sand-casting/kanban/stage/netto');

        $response->assertStatus(200);
        $html = $response->getContent();

        // Extract line weights from HTML
        preg_match('/data-line-kg="1">([0-9.,]+)<\/span>/', $html, $m1);
        preg_match('/data-line-kg="2">([0-9.,]+)<\/span>/', $html, $m2);
        preg_match('/data-line-kg="3">([0-9.,]+)<\/span>/', $html, $m3);
        preg_match('/data-line-kg="4">([0-9.,]+)<\/span>/', $html, $m4);
        preg_match('/id="kanbanReadyTotalKg">([0-9.,]+)<\/span>/', $html, $mTotal);

        $w1 = (int) str_replace(['.', ','], '', $m1[1]);
        $w2 = (int) str_replace(['.', ','], '', $m2[1]);
        $w3 = (int) str_replace(['.', ','], '', $m3[1]);
        $w4 = (int) str_replace(['.', ','], '', $m4[1]);
        $wTotal = (int) str_replace(['.', ','], '', $mTotal[1]);

        $this->assertSame($wTotal, $w1 + $w2 + $w3 + $w4);
    }

    public function test_sum_of_lines_strictly_reconciles_with_overall_pcs(): void
    {
        $this->seedStandardKanbanDataset();

        $response = $this->actingAs($this->spvNetto)
            ->get('/sand-casting/kanban/stage/netto');

        $response->assertStatus(200);
        $html = $response->getContent();

        // Extract line PCS from HTML
        preg_match('/data-line-pcs="1">([0-9.,]+)<\/span>/', $html, $m1);
        preg_match('/data-line-pcs="2">([0-9.,]+)<\/span>/', $html, $m2);
        preg_match('/data-line-pcs="3">([0-9.,]+)<\/span>/', $html, $m3);
        preg_match('/data-line-pcs="4">([0-9.,]+)<\/span>/', $html, $m4);
        preg_match('/id="kanbanReadyTotalPcs">([0-9.,]+)<\/span>/', $html, $mTotal);

        $pcs1 = (int) str_replace(['.', ','], '', $m1[1]);
        $pcs2 = (int) str_replace(['.', ','], '', $m2[1]);
        $pcs3 = (int) str_replace(['.', ','], '', $m3[1]);
        $pcs4 = (int) str_replace(['.', ','], '', $m4[1]);
        $pcsTotal = (int) str_replace(['.', ','], '', $mTotal[1]);

        $this->assertSame($pcsTotal, $pcs1 + $pcs2 + $pcs3 + $pcs4);
        $this->assertSame(160, $pcsTotal);
        $this->assertSame(80, $pcs1);
        $this->assertSame(40, $pcs2);
        $this->assertSame(30, $pcs3);
        $this->assertSame(10, $pcs4);
    }
}
