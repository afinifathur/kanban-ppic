<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxRangkaiExecution;
use App\Models\LostWaxRangkaiWorkOrder;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\ProductionPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadyForOvenPrintLayoutRefinedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private static int $seq = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Operator Lapisan']);
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        $seq = self::$seq++;

        return ProductionPlan::create(array_merge([
            'code' => 'PLAN-'.$seq,
            'customer' => 'CUST-'.$seq,
            'item_code' => 'ITEM-'.$seq,
            'item_name' => 'Flange 2 Inch Test '.$seq,
            'aisi' => '304',
            'size' => '2"',
            'weight' => 2.50,
            'po_number' => 'PO-'.$seq,
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
            'barcode' => 'TREE-P1-'.uniqid().'-'.$currentSeq,
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

    private function createFixturesForMultipleCodes(): array
    {
        // 1. Code 0805KSBN08 - 2 Trees, 50 PCS total, PO: 200, weight: 2.5
        $plan1 = $this->createPlan([
            'code' => '0805KSBN08',
            'item_name' => 'Flange 2 Inch KSBN',
            'customer' => 'PT KSB',
            'po_quantity' => 200,
            'weight' => 2.5,
        ]);
        $line1 = $this->createPrintOrderLine($plan1, ['scheduled_qty' => 3]);

        $rwo1 = LostWaxRangkaiWorkOrder::create([
            'lost_wax_print_order_line_id' => $line1->id,
            'rangkai_order_number' => 'RWO-0805-01',
            'qty_trees_planned' => 2,
            'tree_capacity' => 25,
            'status' => 'COMPLETED',
            'created_by' => $this->user->id,
        ]);
        $exec1 = LostWaxRangkaiExecution::create([
            'rangkai_work_order_id' => $rwo1->id,
            'execution_date' => now()->toDateString(),
            'trees_created' => 2,
            'family_code' => '6',
            'recorded_by' => $this->user->id,
            'recorded_at' => now(),
            'status' => 'COMPLETED',
        ]);
        $t1 = $this->createTree([
            'barcode' => '0805001',
            'lost_wax_print_order_line_id' => $line1->id,
            'rangkai_execution_id' => $exec1->id,
            'current_stage' => 'layer_6',
            'require_layer_7' => false,
            'quantity' => 25,
            'status' => 'in_progress',
        ]);
        $this->createScanEvent($t1, 'layer_6', Carbon::now()->subHours(6));

        $t2 = $this->createTree([
            'barcode' => '0805002',
            'lost_wax_print_order_line_id' => $line1->id,
            'rangkai_execution_id' => $exec1->id,
            'current_stage' => 'layer_6',
            'require_layer_7' => false,
            'quantity' => 25,
            'status' => 'in_progress',
        ]);
        $this->createScanEvent($t2, 'layer_6', Carbon::now()->subHours(5));

        // 2. Code 2110KSBN08 - 1 Tree, 60 PCS, PO: 50, weight: 1.8
        $plan2 = $this->createPlan([
            'code' => '2110KSBN08',
            'item_name' => 'Elbow 90 Deg 1 Inch',
            'customer' => 'PT KSB',
            'po_quantity' => 50,
            'weight' => 1.8,
        ]);
        $line2 = $this->createPrintOrderLine($plan2, ['scheduled_qty' => 1]);

        $t3 = $this->createTree([
            'barcode' => '2110001',
            'lost_wax_print_order_line_id' => $line2->id,
            'current_stage' => 'layer_6',
            'require_layer_7' => false,
            'quantity' => 60,
            'status' => 'in_progress',
        ]);
        $this->createScanEvent($t3, 'layer_6', Carbon::now()->subHours(4));

        // 3. Code 2610AB58 - 1 Tree, 80 PCS, PO: 100, weight: 3.2
        $plan3 = $this->createPlan([
            'code' => '2610AB58',
            'item_name' => 'Valve Body AB58',
            'customer' => 'PT ALFA',
            'po_quantity' => 100,
            'weight' => 3.2,
        ]);
        $line3 = $this->createPrintOrderLine($plan3, ['scheduled_qty' => 1]);

        $t4 = $this->createTree([
            'barcode' => '2610001',
            'lost_wax_print_order_line_id' => $line3->id,
            'current_stage' => 'layer_6',
            'require_layer_7' => false,
            'quantity' => 80,
            'status' => 'in_progress',
        ]);
        $this->createScanEvent($t4, 'layer_6', Carbon::now()->subHours(3));

        // 4. Code UNRELATED - should be filtered out when testing codes[]
        $planOther = $this->createPlan([
            'code' => '9999OTHER',
            'item_name' => 'Other Item',
            'customer' => 'PT OTHER',
            'po_quantity' => 50,
            'weight' => 1.0,
        ]);
        $lineOther = $this->createPrintOrderLine($planOther, ['scheduled_qty' => 1]);

        $tOther = $this->createTree([
            'barcode' => '9999001',
            'lost_wax_print_order_line_id' => $lineOther->id,
            'current_stage' => 'layer_6',
            'require_layer_7' => false,
            'quantity' => 20,
            'status' => 'in_progress',
        ]);
        $this->createScanEvent($tOther, 'layer_6', Carbon::now()->subHours(2));

        return compact('plan1', 'plan2', 'plan3', 'planOther');
    }

    /**
     * 1. Header is minimal: title is fixed 'LAPORAN ANTREAN SIAP OVEN DEWAXING' without code list,
     * no 'Filter: Kode: ...' in header metadata bar.
     */
    public function test_01_header_is_minimalist_without_filter_code_pollution(): void
    {
        $this->createFixturesForMultipleCodes();

        $url = route('lost-wax.reports.ready-for-oven.print', [
            'codes' => ['0805KSBN08', '2110KSBN08', '2610AB58'],
        ]);

        $response = $this->actingAs($this->user)->get($url);
        $response->assertOk();

        // Title is strictly fixed
        $response->assertSee('<h1>LAPORAN ANTREAN SIAP OVEN DEWAXING</h1>', false);
        $response->assertDontSee('LAPORAN ANTREAN SIAP OVEN DEWAXING —');
        $response->assertDontSee('LAPORAN ANTREAN SIAP OVEN DEWAXING -');

        // Header meta-bar does not show Filter: Kode: ...
        $response->assertDontSee('<strong>Filter:</strong>', false);
        $response->assertDontSee('Filter: Kode:');

        // Retains company and metadata
        $response->assertSee('PT. PERONI KARYA SENTRA');
        $response->assertSee('KANBAN PPIC LOST WAX');
        $response->assertSee('Waktu Cetak:');
        $response->assertSee('Prioritas:');
        $response->assertSee('FIFO (Waktu Selesai Lapisan Terawal)');
        $response->assertSee('Dicetak Oleh:');
        $response->assertSee('Operator Lapisan');
    }

    /**
     * 2. No KPI cards on top (summary-box removed from page 1 top).
     */
    public function test_02_no_kpi_cards_above_the_table(): void
    {
        $this->createFixturesForMultipleCodes();

        $url = route('lost-wax.reports.ready-for-oven.print', [
            'codes' => ['0805KSBN08', '2110KSBN08', '2610AB58'],
        ]);

        $response = $this->actingAs($this->user)->get($url);
        $response->assertOk();

        // The old top summary-box and kpi-cell are gone
        $response->assertDontSee('class="summary-box"', false);
        $response->assertDontSee('class="kpi-cell"', false);
    }

    /**
     * 3. Table has NO <tfoot> element to prevent repeated footer on page 1 and middle pages.
     */
    public function test_03_table_has_no_tfoot_preventing_repeated_footers(): void
    {
        $this->createFixturesForMultipleCodes();

        $url = route('lost-wax.reports.ready-for-oven.print', [
            'codes' => ['0805KSBN08', '2110KSBN08', '2610AB58'],
        ]);

        $response = $this->actingAs($this->user)->get($url);
        $response->assertOk();

        // No <tfoot> or TOTAL HASIL FILTER inside table
        $response->assertDontSee('<tfoot>', false);
        $response->assertDontSee('</tfoot>', false);
        $response->assertDontSee('TOTAL HASIL FILTER');
    }

    /**
     * 4. Dedicated summary section appears once at the bottom with all 10 required metrics.
     */
    public function test_04_summary_section_rendered_with_accurate_metrics(): void
    {
        $this->createFixturesForMultipleCodes();

        $url = route('lost-wax.reports.ready-for-oven.print', [
            'codes' => ['0805KSBN08', '2110KSBN08', '2610AB58'],
        ]);

        $response = $this->actingAs($this->user)->get($url);
        $response->assertOk();

        // Summary section title
        $response->assertSee('RINGKASAN TOTAL AKUMULASI ANTREAN');
        $response->assertSee('summary-section');

        // 1. Pohon Fisik Unik & Rangkai (4 unique trees, 3 codes, 3 distinct groups)
        $response->assertSee('Pohon Fisik & Rangkai');
        $response->assertSee('4 <span class="summary-unit">Pohon</span>', false);
        $response->assertSee('3 Kode');

        // 2. Siap Oven / PO (Pcs: 50 + 60 + 80 = 190 PCS, PO: 200 + 50 + 100 = 350 PCS)
        $response->assertSee('Siap Oven / PO');
        $response->assertSee('190');
        $response->assertSee('/ 350 PCS');
        $response->assertSee('Kurang 160 PCS');

        // 3. Total Berat Siap Oven (50*2.5 + 60*1.8 + 80*3.2 = 125 + 108 + 256 = 489.00 kg)
        $response->assertSee('Total Berat Siap Oven');
        $response->assertSee('489,00');

        // 4. Estimasi Coverage Oven
        $response->assertSee('Estimasi Coverage Oven');
        $response->assertSee('Target Dapur:');

        // 5. Report Notes and Signatures placed after summary
        $response->assertSee('report-notes');
        $response->assertSee('sign-section');
        $response->assertSee('Dibuat Oleh,<br>(SPV / Operator Lapisan)', false);
        $response->assertSee('Diterima Oleh,<br>(Operator Oven)', false);
        $response->assertSee('Mengetahui,<br>(SPV PPIC / QC)', false);
    }

    /**
     * 5. Table retains all operational details and filters by codes[] accurately.
     */
    public function test_05_table_retains_operational_details_and_filters_accurately(): void
    {
        $this->createFixturesForMultipleCodes();

        $url = route('lost-wax.reports.ready-for-oven.print', [
            'codes' => ['0805KSBN08', '2110KSBN08', '2610AB58'],
        ]);

        $response = $this->actingAs($this->user)->get($url);
        $response->assertOk();

        // All 3 selected codes are present
        $response->assertSee('0805KSBN08');
        $response->assertSee('Flange 2 Inch KSBN');
        $response->assertSee('2110KSBN08');
        $response->assertSee('Elbow 90 Deg 1 Inch');
        $response->assertSee('2610AB58');
        $response->assertSee('Valve Body AB58');

        // Unselected code is excluded
        $response->assertDontSee('9999OTHER');
        $response->assertDontSee('Other Item');

        // Barcode and SPK details
        $response->assertSee('0805001');
        $response->assertSee('0805002');
        $response->assertSee('2110001');
        $response->assertSee('2610001');
    }
}
