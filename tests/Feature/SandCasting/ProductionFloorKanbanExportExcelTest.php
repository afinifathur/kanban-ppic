<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\User;
use App\Services\SandCasting\SandCastingProductionFloorQueryService;
use App\Services\SandCasting\SandCastingStageExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductionFloorKanbanExportExcelTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $spvNetto;

    protected User $spvBubutOd;

    protected SandCastingStageExecutionService $executionService;

    protected SandCastingProductionFloorQueryService $queryService;

    protected static int $sequenceCounter = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $planPerm = Permission::firstOrCreate(['name' => 'access_planning']);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $spvRole = Role::firstOrCreate(['name' => 'spv']);

        $adminRole->givePermissionTo($planPerm);

        $this->adminUser = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin_sc_excel@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->adminUser->assignRole('admin');

        $this->spvNetto = User::factory()->create([
            'name' => 'SPV Netto',
            'email' => 'spv_netto_excel@peroniks.com',
            'assigned_stage' => 'netto',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvNetto->assignRole('spv');

        $this->spvBubutOd = User::factory()->create([
            'name' => 'SPV Bubut OD',
            'email' => 'spv_od_excel@peroniks.com',
            'assigned_stage' => 'bubut_od',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvBubutOd->assignRole('spv');

        $this->executionService = new SandCastingStageExecutionService;
        $this->queryService = app(SandCastingProductionFloorQueryService::class);
    }

    protected function createKtr(array $attributes = []): SandCastingCastingResultLine
    {
        $seq = self::$sequenceCounter++;

        $plan = ProductionPlan::create([
            'code' => $attributes['production_code'] ?? 'SPK-'.sprintf('%04d', $seq),
            'title' => 'Rencana SC '.$seq,
            'item_code' => $attributes['item_code'] ?? '4.'.sprintf('%04d', $seq),
            'item_name' => $attributes['item_name'] ?? 'FLANGE BLIND 2" 150# RF',
            'po_number' => 'PO-'.sprintf('%04d', $seq),
            'line_number' => $attributes['line_number'] ?? 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'customer' => $attributes['customer'] ?? 'PT SINAR METAL TEKNIK',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-EXCEL-'.sprintf('%05d', $seq),
            'scheduled_date' => '2026-10-24',
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => $attributes['size'] ?? '2"',
            'aisi' => 'FC250',
            'unit_weight_kg' => $attributes['unit_weight_kg'] ?? 2.50,
            'total_weight_kg' => 250.00,
            'status' => 'issued',
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => $attributes['heat_number'] ?? 'HEAT'.sprintf('%04d', $seq),
            'cast_date' => now()->toDateString(),
            'furnace' => 'F1',
            'shift' => '1',
            'operator_name' => 'Operator Cor',
            'recorded_by' => $this->adminUser->id,
            'total_qty_good' => 100,
            'total_qty_reject' => 0,
        ]);

        return $result->lines()->create(array_merge([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $attributes['traveler_number'] ?? 'KTR-EXCEL-'.sprintf('%05d', $seq),
            'qty_good' => $attributes['qty_good'] ?? 50,
            'qty_reject' => 0,
            'unit_weight_kg' => $attributes['unit_weight_kg'] ?? 2.50,
            'total_weight_kg' => $attributes['total_weight_kg'] ?? 125.00,
            'current_stage' => $attributes['current_stage'] ?? 'netto',
            'queue_position' => $attributes['queue_position'] ?? null,
            'is_urgent' => $attributes['is_urgent'] ?? false,
        ], $attributes));
    }

    /**
     * Helper to load streamed Excel response into PhpSpreadsheet instance.
     */
    protected function loadSpreadsheetFromResponse($response)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_kanban_');
        file_put_contents($tempFile, $response->streamedContent());
        $spreadsheet = IOFactory::load($tempFile);
        unlink($tempFile);

        return $spreadsheet;
    }

    /**
     * A.1 & A.4: Ekspor NETTO menghasilkan judul, data, dan nama file yang mencerminkan NETTO.
     */
    public function test_netto_export_produces_correct_title_data_and_filename(): void
    {
        $this->createKtr([
            'current_stage' => 'netto',
            'line_number' => 1,
            'item_name' => 'NETTO TEST ITEM 1',
            'heat_number' => 'A214001',
            'qty_good' => 30,
            'unit_weight_kg' => 2.0,
            'total_weight_kg' => 60.0,
        ]);

        $res = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/stage/netto/export/excel');
        $res->assertStatus(200);
        $res->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $disposition = (string) $res->headers->get('Content-Disposition');
        $this->assertStringContainsString('Kanban-NETTO-', $disposition);
        $this->assertStringEndsWith('.xlsx"', $disposition);

        $spreadsheet = $this->loadSpreadsheetFromResponse($res);
        $sheet1 = $spreadsheet->getSheetByName('Line 1');
        $this->assertNotNull($sheet1);

        $this->assertEquals('PT. PERONI KARYA SENTRA', $sheet1->getCell('A1')->getValue());
        $this->assertStringContainsString('KANBAN PRODUKSI — NETTO | LINE 1', $sheet1->getCell('A2')->getValue());
        $this->assertStringContainsString('Waktu Ekspor:', $sheet1->getCell('A3')->getValue());
        $this->assertStringContainsString('WIB', $sheet1->getCell('A3')->getValue());

        // Row 6 data
        $this->assertEquals(1, $sheet1->getCell('A6')->getValue());
        $this->assertEquals('NETTO TEST ITEM 1', $sheet1->getCell('B6')->getValue());
        $this->assertEquals('A214001', $sheet1->getCell('C6')->getValue());
        $this->assertEquals(30, $sheet1->getCell('D6')->getValue());
        $this->assertEquals(60.0, (float) $sheet1->getCell('E6')->getValue());
    }

    /**
     * A.2 & A.4: Ekspor tahap lain (BUBUT OD) menghasilkan judul dan nama file BUBUT OD.
     */
    public function test_bubut_od_export_produces_correct_title_and_filename(): void
    {
        $this->createKtr([
            'current_stage' => 'bubut_od',
            'line_number' => 2,
            'item_name' => 'BUBUT OD ITEM',
            'heat_number' => 'OD9901',
            'qty_good' => 40,
            'unit_weight_kg' => 3.0,
            'total_weight_kg' => 120.0,
        ]);

        $res = $this->actingAs($this->spvBubutOd)->get('/sand-casting/kanban/stage/bubut-od/export/excel');
        $res->assertStatus(200);

        $disposition = (string) $res->headers->get('Content-Disposition');
        $this->assertStringContainsString('Kanban-BUBUT-OD-', $disposition);

        $spreadsheet = $this->loadSpreadsheetFromResponse($res);
        $sheet2 = $spreadsheet->getSheetByName('Line 2');
        $this->assertNotNull($sheet2);
        $this->assertStringContainsString('KANBAN PRODUKSI — BUBUT OD | LINE 2', $sheet2->getCell('A2')->getValue());
        $this->assertEquals('BUBUT OD ITEM', $sheet2->getCell('B6')->getValue());
    }

    /**
     * A.3: Tahap tidak valid ditolak dengan 404.
     */
    public function test_invalid_stage_is_rejected_with_404(): void
    {
        $res = $this->actingAs($this->adminUser)->get('/sand-casting/kanban/stage/stage-invalid-xyz/export/excel');
        $res->assertStatus(404);
    }

    /**
     * A.5: Otorisasi tahap berlaku sama persis dengan PDF dan Kanban.
     */
    public function test_stage_authorization_enforced(): void
    {
        // Guest redirects to login
        $resGuest = $this->get('/sand-casting/kanban/stage/netto/export/excel');
        $resGuest->assertRedirect('/login');

        // SPV Netto cannot export Bubut OD (403)
        $resUnauthorized = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/stage/bubut-od/export/excel');
        $resUnauthorized->assertStatus(403);

        // Admin can export any stage
        $resAdminNetto = $this->actingAs($this->adminUser)->get('/sand-casting/kanban/stage/netto/export/excel');
        $resAdminNetto->assertStatus(200);

        $resAdminOd = $this->actingAs($this->adminUser)->get('/sand-casting/kanban/stage/bubut-od/export/excel');
        $resAdminOd->assertStatus(200);
    }

    /**
     * B.6, B.7, B.8, B.9, B.10: Workbook memiliki tepat 4 worksheet dengan nama, urutan, header kolom, dan baris TOTAL yang benar.
     */
    public function test_workbook_structure_four_sheets_headers_and_totals(): void
    {
        $this->createKtr(['current_stage' => 'netto', 'line_number' => 1, 'qty_good' => 10, 'total_weight_kg' => 25]);
        $this->createKtr(['current_stage' => 'netto', 'line_number' => 1, 'qty_good' => 20, 'total_weight_kg' => 50]);

        $res = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/stage/netto/export/excel');
        $res->assertStatus(200);

        $spreadsheet = $this->loadSpreadsheetFromResponse($res);

        // Exactly 4 worksheets
        $this->assertEquals(4, $spreadsheet->getSheetCount());
        $sheetNames = $spreadsheet->getSheetNames();
        $this->assertEquals(['Line 1', 'Line 2', 'Line 3', 'Line 4'], $sheetNames);

        $expectedHeaders = [
            'A' => 'No. Urut',
            'B' => 'Nama Item',
            'C' => 'No. Heat',
            'D' => 'Jumlah (pcs)',
            'E' => 'Berat (kg)',
            'F' => 'Aging',
            'G' => 'Customer',
        ];

        foreach ($sheetNames as $name) {
            $sheet = $spreadsheet->getSheetByName($name);

            // Company header
            $this->assertEquals('PT. PERONI KARYA SENTRA', $sheet->getCell('A1')->getValue());

            // Column headers on row 5
            foreach ($expectedHeaders as $col => $expectedText) {
                $this->assertEquals($expectedText, $sheet->getCell("{$col}5")->getValue());
            }

            // Freeze pane set
            $this->assertEquals('A6', $sheet->getFreezePane());
        }

        // Sheet 1 has 2 data rows (row 6 and 7) and total at row 8
        $sheet1 = $spreadsheet->getSheetByName('Line 1');
        $this->assertEquals('TOTAL', $sheet1->getCell('A8')->getValue());
        $this->assertEquals('=SUM(D6:D7)', $sheet1->getCell('D8')->getValue());
        $this->assertEquals('=SUM(E6:E7)', $sheet1->getCell('E8')->getValue());
        $this->assertEquals(30, $sheet1->getCell('D8')->getCalculatedValue());
        $this->assertEquals(75.0, (float) $sheet1->getCell('E8')->getCalculatedValue());

        // Sheet 2 is empty: total at row 6
        $sheet2 = $spreadsheet->getSheetByName('Line 2');
        $this->assertEquals('TOTAL', $sheet2->getCell('A6')->getValue());
        $this->assertEquals('=SUM(0)', $sheet2->getCell('D6')->getValue());
        $this->assertEquals('=SUM(0)', $sheet2->getCell('E6')->getValue());
        $this->assertEquals(0, $sheet2->getCell('D6')->getCalculatedValue());
        $this->assertEquals(0, $sheet2->getCell('E6')->getCalculatedValue());
    }

    /**
     * C.11, C.12, C.13: Data dari masing-masing line masuk ke sheet yang tepat dan line_number filter pada query string diabaikan.
     */
    public function test_line_separation_and_line_number_filter_ignored_in_excel(): void
    {
        $this->createKtr(['current_stage' => 'netto', 'line_number' => 1, 'item_name' => 'ITEM L1', 'qty_good' => 11]);
        $this->createKtr(['current_stage' => 'netto', 'line_number' => 2, 'item_name' => 'ITEM L2', 'qty_good' => 22]);
        $this->createKtr(['current_stage' => 'netto', 'line_number' => 3, 'item_name' => 'ITEM L3', 'qty_good' => 33]);
        $this->createKtr(['current_stage' => 'netto', 'line_number' => 4, 'item_name' => 'ITEM L4', 'qty_good' => 44]);

        // Request with ?line_number=2 should STILL export ALL 4 lines!
        $res = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/stage/netto/export/excel?line_number=2');
        $res->assertStatus(200);

        $spreadsheet = $this->loadSpreadsheetFromResponse($res);

        $sheet1 = $spreadsheet->getSheetByName('Line 1');
        $this->assertEquals('ITEM L1', $sheet1->getCell('B6')->getValue());
        $this->assertEquals(11, $sheet1->getCell('D6')->getValue());

        $sheet2 = $spreadsheet->getSheetByName('Line 2');
        $this->assertEquals('ITEM L2', $sheet2->getCell('B6')->getValue());
        $this->assertEquals(22, $sheet2->getCell('D6')->getValue());

        $sheet3 = $spreadsheet->getSheetByName('Line 3');
        $this->assertEquals('ITEM L3', $sheet3->getCell('B6')->getValue());
        $this->assertEquals(33, $sheet3->getCell('D6')->getValue());

        $sheet4 = $spreadsheet->getSheetByName('Line 4');
        $this->assertEquals('ITEM L4', $sheet4->getCell('B6')->getValue());
        $this->assertEquals(44, $sheet4->getCell('D6')->getValue());
    }

    /**
     * C.14: Filter search, customer, dan is_urgent tetap dihormati pada ekspor Excel.
     */
    public function test_search_customer_and_urgent_filters_are_respected(): void
    {
        $this->createKtr([
            'current_stage' => 'netto',
            'line_number' => 1,
            'item_name' => 'SPECIAL FLANGE XYZ',
            'customer' => 'PT CUSTOMER ALPHA',
            'is_urgent' => true,
        ]);
        $this->createKtr([
            'current_stage' => 'netto',
            'line_number' => 2,
            'item_name' => 'SPECIAL FLANGE XYZ',
            'customer' => 'PT CUSTOMER ALPHA',
            'is_urgent' => false,
        ]);
        $this->createKtr([
            'current_stage' => 'netto',
            'line_number' => 1,
            'item_name' => 'OTHER ITEM',
            'customer' => 'PT OTHER',
            'is_urgent' => true,
        ]);

        // Filter search=XYZ & is_urgent=1
        $res = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/stage/netto/export/excel?search=XYZ&is_urgent=1');
        $res->assertStatus(200);

        $spreadsheet = $this->loadSpreadsheetFromResponse($res);
        $sheet1 = $spreadsheet->getSheetByName('Line 1');
        $sheet2 = $spreadsheet->getSheetByName('Line 2');

        // Line 1 contains only the matching urgent item
        $this->assertEquals('SPECIAL FLANGE XYZ', $sheet1->getCell('B6')->getValue());
        $this->assertEquals('TOTAL', $sheet1->getCell('A7')->getValue());

        // Line 2 has no matching items (the item on Line 2 is not urgent)
        $this->assertEquals('TOTAL', $sheet2->getCell('A6')->getValue());

        // Row 4 metadata reflects active filters
        $this->assertStringContainsString('Pencarian: "XYZ"', $sheet1->getCell('A4')->getValue());
        $this->assertStringContainsString('HANYA URGENT', $sheet1->getCell('A4')->getValue());
    }

    /**
     * D.18: Heat number dengan leading zero seperti '00123' tersimpan sebagai teks eksplisit tanpa kehilangan leading zero.
     */
    public function test_heat_number_with_leading_zero_is_explicit_string(): void
    {
        $this->createKtr([
            'current_stage' => 'netto',
            'line_number' => 1,
            'heat_number' => '00123',
        ]);

        $res = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/stage/netto/export/excel');
        $res->assertStatus(200);

        $spreadsheet = $this->loadSpreadsheetFromResponse($res);
        $sheet1 = $spreadsheet->getSheetByName('Line 1');

        $cellC6 = $sheet1->getCell('C6');
        $this->assertEquals('00123', $cellC6->getValue());
        $this->assertEquals(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $cellC6->getDataType());
    }

    /**
     * D.16, D.17, D.19, D.20: Urutan antrean, penomoran urut, pcs, berat, dan aging konsisten dengan query service.
     */
    public function test_queue_ordering_and_data_parity_with_query_service(): void
    {
        $this->createKtr([
            'current_stage' => 'netto',
            'line_number' => 1,
            'item_name' => 'ITEM FIRST',
            'queue_position' => 1,
            'qty_good' => 15,
            'unit_weight_kg' => 2.0,
            'total_weight_kg' => 30.0,
            'customer' => 'CUST A',
        ]);
        $this->createKtr([
            'current_stage' => 'netto',
            'line_number' => 1,
            'item_name' => 'ITEM SECOND',
            'queue_position' => 2,
            'qty_good' => 25,
            'unit_weight_kg' => 4.0,
            'total_weight_kg' => 100.0,
            'customer' => 'CUST B',
        ]);

        $res = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/stage/netto/export/excel');
        $res->assertStatus(200);

        $spreadsheet = $this->loadSpreadsheetFromResponse($res);
        $sheet1 = $spreadsheet->getSheetByName('Line 1');

        // Check Row 6 (first item)
        $this->assertEquals(1, $sheet1->getCell('A6')->getValue());
        $this->assertEquals('ITEM FIRST', $sheet1->getCell('B6')->getValue());
        $this->assertEquals(15, $sheet1->getCell('D6')->getValue());
        $this->assertEquals(30.0, (float) $sheet1->getCell('E6')->getValue());
        $this->assertStringContainsString('Aging', (string) $sheet1->getCell('F6')->getValue());
        $this->assertEquals('CUST A', $sheet1->getCell('G6')->getValue());

        // Check Row 7 (second item)
        $this->assertEquals(2, $sheet1->getCell('A7')->getValue());
        $this->assertEquals('ITEM SECOND', $sheet1->getCell('B7')->getValue());
        $this->assertEquals(25, $sheet1->getCell('D7')->getValue());
        $this->assertEquals(100.0, (float) $sheet1->getCell('E7')->getValue());
        $this->assertEquals('CUST B', $sheet1->getCell('G7')->getValue());

        // Check Total Row (Row 8)
        $this->assertEquals(40, $sheet1->getCell('D8')->getCalculatedValue());
        $this->assertEquals(130.0, (float) $sheet1->getCell('E8')->getCalculatedValue());
    }

    /**
     * E.23 & E.24: Tombol EXPORT EXCEL ada pada UI web Kanban dan memanggil exportKanbanExcel() dengan stageSlug dinamis.
     */
    public function test_ui_contains_export_excel_button_with_dynamic_stageslug(): void
    {
        $this->createKtr(['current_stage' => 'netto']);

        $resNetto = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/netto');
        $resNetto->assertStatus(200);

        // Assert button exists next to print
        $resNetto->assertSee('id="btnKanbanExportExcel"', false);
        $resNetto->assertSee('EXPORT EXCEL');
        $resNetto->assertSee('fa-file-excel');
        $resNetto->assertSee('onclick="exportKanbanExcel()"', false);

        // Assert script function exists and uses dynamic route
        $resNetto->assertSee('function exportKanbanExcel()', false);
        $resNetto->assertSee(json_encode(route('sand-casting.kanban.export.excel', 'netto')), false);

        // Also test BUBUT OD tab view renders with bubut-od export URL
        $this->createKtr(['current_stage' => 'bubut_od']);
        $resOd = $this->actingAs($this->spvBubutOd)->get('/sand-casting/kanban/bubut-od');
        $resOd->assertStatus(200);
        $resOd->assertSee(json_encode(route('sand-casting.kanban.export.excel', 'bubut-od')), false);
    }
}
