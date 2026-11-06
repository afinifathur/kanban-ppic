<?php

namespace PhpOffice\PhpSpreadsheet\Shared {
    if (! function_exists('PhpOffice\PhpSpreadsheet\Shared\is_readable')) {
        function is_readable(string $filename): bool
        {
            return file_exists($filename);
        }
    }
}

namespace PhpOffice\PhpSpreadsheet\Reader {
    if (! function_exists('PhpOffice\PhpSpreadsheet\Reader\is_readable')) {
        function is_readable(string $filename): bool
        {
            return file_exists($filename);
        }
    }
}

namespace Tests\Feature\SandCasting {

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductionStatusExportRbacScopeTest extends TestCase
{
    use RefreshDatabase;

    protected User $plannerUser;

    protected User $viewerUser;

    protected User $unauthorizedUser;

    protected static int $seq = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $planPerm = Permission::firstOrCreate(['name' => 'access_planning']);
        $visibilityPerm = Permission::firstOrCreate(['name' => 'access_production_visibility']);

        $plannerRole = Role::firstOrCreate(['name' => 'planner']);
        $plannerRole->givePermissionTo($planPerm);

        $viewerRole = Role::firstOrCreate(['name' => 'viewer']);
        $viewerRole->givePermissionTo($visibilityPerm);

        $this->plannerUser = User::factory()->create([
            'name' => 'Admin PPIC Sand Casting',
            'email' => 'planner_sc@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->plannerUser->assignRole('planner');

        $this->viewerUser = User::factory()->create([
            'name' => 'Auditor Sand Casting',
            'email' => 'viewer_sc@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->viewerUser->assignRole('viewer');

        $this->unauthorizedUser = User::factory()->create([
            'name' => 'Operator Sand Casting',
            'email' => 'operator_sc@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);

        $this->app[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Helper to load streamed Excel response into PhpSpreadsheet instance.
     */
    protected function loadSpreadsheetFromResponse($response)
    {
        $tempDir = storage_path('framework/testing');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        $tempFile = $tempDir.DIRECTORY_SEPARATOR.'test_sc_status_'.uniqid().'.xlsx';
        file_put_contents($tempFile, $response->streamedContent());
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx;
        $spreadsheet = $reader->load($tempFile);
        @unlink($tempFile);

        return $spreadsheet;
    }

    /**
     * Helper to create a ProductionPlan with linked casting results and executions.
     */
    protected function createPlanWithChain(array $overrides = []): ProductionPlan
    {
        $idx = self::$seq++;

        $plan = ProductionPlan::create([
            'code' => $overrides['code'] ?? 'SPK-SC-'.sprintf('%04d', $idx),
            'title' => $overrides['title'] ?? 'BATCH-'.sprintf('%02d', $idx),
            'item_code' => $overrides['item_code'] ?? '4.'.sprintf('%04d', $idx),
            'item_name' => $overrides['item_name'] ?? 'FLANGE BLIND 2" 150# RF',
            'po_number' => $overrides['po_number'] ?? 'PO-'.sprintf('%04d', $idx),
            'line_number' => $overrides['line_number'] ?? 1,
            'po_quantity' => $overrides['po_quantity'] ?? 100,
            'qty_planned' => $overrides['qty_planned'] ?? 100,
            'qty_remaining' => 100,
            'customer' => $overrides['customer'] ?? 'PT SINAR METAL TEKNIK',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-'.sprintf('%05d', $idx),
            'scheduled_date' => now()->toDateString(),
            'status' => 'ISSUED',
            'created_by' => $this->plannerUser->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => $plan->qty_planned,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '2"',
            'aisi' => 'FC250',
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 250.00,
            'status' => 'issued',
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => $overrides['heat_number'] ?? 'HEAT-'.sprintf('%04d', $idx),
            'cast_date' => now()->toDateString(),
            'furnace' => 'F1',
            'shift' => '1',
            'operator_name' => 'Operator Cor',
            'recorded_by' => $this->plannerUser->id,
            'total_qty_good' => $overrides['qty_good'] ?? 50,
            'total_qty_reject' => $overrides['qty_reject'] ?? 2,
        ]);

        $ktr = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $overrides['traveler_number'] ?? 'KTR-SC-'.sprintf('%05d', $idx),
            'qty_good' => $overrides['qty_good'] ?? 50,
            'qty_reject' => $overrides['qty_reject'] ?? 2,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 125.00,
            'current_stage' => $overrides['current_stage'] ?? 'netto',
        ]);

        if (! empty($overrides['executions'])) {
            foreach ($overrides['executions'] as $exec) {
                SandCastingStageExecution::create(array_merge([
                    'sand_casting_casting_result_line_id' => $ktr->id,
                    'stage' => $exec['stage'] ?? 'netto',
                    'checkpoint_code' => $exec['checkpoint_code'] ?? 'NETTO_CUT',
                    'operator_id' => $this->plannerUser->id,
                    'executed_at' => now(),
                    'input_qty' => $exec['input_qty'] ?? 50,
                    'good_qty' => $exec['good_qty'] ?? 48,
                    'defect_qty' => $exec['defect_qty'] ?? 2,
                ], $exec));
            }
        }

        return $plan;
    }

    /**
     * 1. Test authorized users can export and receive 200 with valid Excel headers and filename.
     */
    public function test_authorized_user_can_export_production_status_excel(): void
    {
        $this->createPlanWithChain();

        $response = $this->actingAs($this->plannerUser)->get(route('sand-casting.production-status.export'));

        $response->assertStatus(200);
        $this->assertEquals(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );

        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment;', $disposition);
        $this->assertStringContainsString('Sand_Casting_Production_Status_', $disposition);
        $this->assertStringEndsWith('.xlsx', $disposition);

        // Also test viewer with access_production_visibility
        $viewerResponse = $this->actingAs($this->viewerUser)->get(route('sand-casting.production-status.export'));
        $viewerResponse->assertStatus(200);
    }

    /**
     * 2. Test unauthorized user and guest access is denied.
     */
    public function test_unauthorized_user_and_guest_cannot_access_export(): void
    {
        // Guest redirected to login
        $guestResponse = $this->get(route('sand-casting.production-status.export'));
        $guestResponse->assertRedirect('/login');

        // User without permission gets 403 Forbidden
        $unauthorizedResponse = $this->actingAs($this->unauthorizedUser)->get(route('sand-casting.production-status.export'));
        $unauthorizedResponse->assertStatus(403);
    }

    /**
     * 3. Test generated file is valid XLSX and readable by PhpSpreadsheet with correct worksheet name.
     */
    public function test_generated_file_is_valid_xlsx_with_correct_sheet_title(): void
    {
        $this->createPlanWithChain();

        $response = $this->actingAs($this->plannerUser)->get(route('sand-casting.production-status.export'));
        $response->assertStatus(200);

        $spreadsheet = $this->loadSpreadsheetFromResponse($response);
        $this->assertNotNull($spreadsheet);

        $sheet = $spreadsheet->getActiveSheet();
        $this->assertEquals('Production Status', $sheet->getTitle());
    }

    /**
     * 4. Test table headers match 19 columns and correct labels on Row 6.
     */
    public function test_headers_and_19_columns_structure(): void
    {
        $this->createPlanWithChain();

        $response = $this->actingAs($this->plannerUser)->get(route('sand-casting.production-status.export'));
        $spreadsheet = $this->loadSpreadsheetFromResponse($response);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals('No', $sheet->getCell('A6')->getValue());
        $this->assertEquals('Kode', $sheet->getCell('B6')->getValue());
        $this->assertEquals('Product Name', $sheet->getCell('C6')->getValue());
        $this->assertEquals('Customer', $sheet->getCell('D6')->getValue());
        $this->assertEquals('PO (pcs)', $sheet->getCell('E6')->getValue());
        $this->assertEquals('Plan (pcs)', $sheet->getCell('F6')->getValue());
        $this->assertEquals('COR', $sheet->getCell('G6')->getValue());
        $this->assertEquals('Netto (pcs)', $sheet->getCell('H6')->getValue());
        $this->assertEquals('R Netto (pcs)', $sheet->getCell('I6')->getValue());
        $this->assertEquals('Bubut OD (pcs)', $sheet->getCell('J6')->getValue());
        $this->assertEquals('R OD (pcs)', $sheet->getCell('K6')->getValue());
        $this->assertEquals('Bubut CNC (pcs)', $sheet->getCell('L6')->getValue());
        $this->assertEquals('R CNC (pcs)', $sheet->getCell('M6')->getValue());
        $this->assertEquals('Bor (pcs)', $sheet->getCell('N6')->getValue());
        $this->assertEquals('R Bor (pcs)', $sheet->getCell('O6')->getValue());
        $this->assertEquals('QC (pcs)', $sheet->getCell('P6')->getValue());
        $this->assertEquals('R QC (pcs)', $sheet->getCell('Q6')->getValue());
        $this->assertEquals('GD (pcs)', $sheet->getCell('R6')->getValue());
        $this->assertEquals('Status', $sheet->getCell('S6')->getValue());
    }

    /**
     * 5. Test metadata section contains report title, generated date, user name, and filter info.
     */
    public function test_metadata_section_contents(): void
    {
        $this->createPlanWithChain(['customer' => 'PT ASTRA OTOPARTS', 'code' => 'SPK-META-001']);

        $response = $this->actingAs($this->plannerUser)->get(route('sand-casting.production-status.export', [
            'filter' => 'active',
            'search' => 'SPK-META',
            'customers' => ['PT ASTRA OTOPARTS'],
        ]));

        $spreadsheet = $this->loadSpreadsheetFromResponse($response);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals('SAND CASTING — PRODUCTION STATUS REPORT', $sheet->getCell('A1')->getValue());
        $this->assertEquals('Generated:', $sheet->getCell('A2')->getValue());
        $this->assertNotEmpty($sheet->getCell('B2')->getValue());
        $this->assertEquals('Generated By:', $sheet->getCell('D2')->getValue());
        $this->assertEquals('Admin PPIC Sand Casting', $sheet->getCell('E2')->getValue());
        $this->assertEquals('Status Filter:', $sheet->getCell('A3')->getValue());
        $this->assertEquals('ACTIVE', $sheet->getCell('B3')->getValue());
        $this->assertEquals('Search:', $sheet->getCell('A4')->getValue());
        $this->assertEquals('SPK-META', $sheet->getCell('B4')->getValue());
        $this->assertEquals('Customer:', $sheet->getCell('D4')->getValue());
        $this->assertEquals('PT ASTRA OTOPARTS', $sheet->getCell('E4')->getValue());
    }

    /**
     * 6. Test filters apply correctly to exported rows (filter=active, filter=completed, search, customer, code).
     */
    public function test_filters_are_honored_in_export(): void
    {
        $planActive = $this->createPlanWithChain([
            'code' => 'CODE-ACTIVE',
            'customer' => 'PT CUSTOMER ALPHA',
            'current_stage' => 'netto',
            'po_quantity' => 100,
        ]);

        // Completed item (has GUDANG_RECEIVE execution fulfilling PO)
        $planCompleted = $this->createPlanWithChain([
            'code' => 'CODE-COMPLETED',
            'customer' => 'PT CUSTOMER BETA',
            'current_stage' => 'completed',
            'po_quantity' => 50,
            'qty_good' => 50,
            'executions' => [
                [
                    'stage' => 'gudang_jadi',
                    'checkpoint_code' => 'GUDANG_RECEIVE',
                    'input_qty' => 50,
                    'good_qty' => 50,
                    'defect_qty' => 0,
                ],
            ],
        ]);

        // Filter: active only
        $resActive = $this->actingAs($this->plannerUser)->get(route('sand-casting.production-status.export', ['filter' => 'active']));
        $sheetActive = $this->loadSpreadsheetFromResponse($resActive)->getActiveSheet();
        $this->assertEquals('CODE-ACTIVE', $sheetActive->getCell('B7')->getValue());
        $this->assertNull($sheetActive->getCell('B8')->getValue()); // Only 1 row

        // Filter: completed only
        $resCompleted = $this->actingAs($this->plannerUser)->get(route('sand-casting.production-status.export', ['filter' => 'completed']));
        $sheetCompleted = $this->loadSpreadsheetFromResponse($resCompleted)->getActiveSheet();
        $this->assertEquals('CODE-COMPLETED', $sheetCompleted->getCell('B7')->getValue());
        $this->assertNull($sheetCompleted->getCell('B8')->getValue()); // Only 1 row

        // Filter: search by code
        $resSearch = $this->actingAs($this->plannerUser)->get(route('sand-casting.production-status.export', [
            'filter' => 'all',
            'search' => 'CODE-ACTIVE',
        ]));
        $sheetSearch = $this->loadSpreadsheetFromResponse($resSearch)->getActiveSheet();
        $this->assertEquals('CODE-ACTIVE', $sheetSearch->getCell('B7')->getValue());
        $this->assertNull($sheetSearch->getCell('B8')->getValue());
    }

    /**
     * 7. Test data values and numeric integrity (E, F, H to R are real numeric integers).
     */
    public function test_data_values_and_numeric_cell_integrity(): void
    {
        $plan = $this->createPlanWithChain([
            'code' => 'CODE-NUMERIC',
            'item_name' => 'VALVE BODY 3 INCH',
            'customer' => 'PT VALVE NUSANTARA',
            'po_quantity' => 150,
            'qty_planned' => 160,
            'current_stage' => 'netto',
            'qty_good' => 80,
            'qty_reject' => 5,
        ]);

        $res = $this->actingAs($this->plannerUser)->get(route('sand-casting.production-status.export', ['filter' => 'all']));
        $sheet = $this->loadSpreadsheetFromResponse($res)->getActiveSheet();

        // Row 7 is the first data row
        $this->assertEquals(1, $sheet->getCell('A7')->getValue());
        $this->assertEquals('CODE-NUMERIC', $sheet->getCell('B7')->getValue());
        $this->assertEquals('VALVE BODY 3 INCH', $sheet->getCell('C7')->getValue());
        $this->assertEquals('PT VALVE NUSANTARA', $sheet->getCell('D7')->getValue());

        // Numeric checks
        $this->assertSame(150, $sheet->getCell('E7')->getValue());
        $this->assertSame(160, $sheet->getCell('F7')->getValue());
        $this->assertIsInt($sheet->getCell('E7')->getValue());
        $this->assertIsInt($sheet->getCell('F7')->getValue());

        // Netto quantity (usable good = 80)
        $this->assertSame(80, $sheet->getCell('H7')->getValue());

        // R Netto display = r_cor (5) + r_netto (0) = 5
        $this->assertSame(5, $sheet->getCell('I7')->getValue());

        // Check number formats
        $this->assertEquals('#,##0;(#,##0);"-"', $sheet->getStyle('E7')->getNumberFormat()->getFormatCode());
        $this->assertEquals('#,##0;(#,##0);"-"', $sheet->getStyle('H7')->getNumberFormat()->getFormatCode());
    }

    /**
     * 8. Test empty dataset produces a valid workbook with informative message and no error.
     */
    public function test_empty_dataset_produces_valid_workbook_with_empty_message(): void
    {
        $res = $this->actingAs($this->plannerUser)->get(route('sand-casting.production-status.export', [
            'search' => 'NON_EXISTENT_CODE_XYZ_9999',
        ]));

        $res->assertStatus(200);
        $spreadsheet = $this->loadSpreadsheetFromResponse($res);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals('Production Status', $sheet->getTitle());
        $this->assertEquals('Tidak ada data yang sesuai dengan filter.', $sheet->getCell('A7')->getValue());
    }

    /**
     * 9. Test web UI contains Download Excel button with proper route and active filter params.
     */
    public function test_web_ui_renders_download_excel_button(): void
    {
        $res = $this->actingAs($this->plannerUser)->get(route('sand-casting.production-status.index', [
            'filter' => 'completed',
            'search' => 'FLANGE',
        ]));

        $res->assertStatus(200);
        $res->assertSee('Download Excel');
        $res->assertSee('fa-file-excel');
        $res->assertSee(route('sand-casting.production-status.export', [
            'filter' => 'completed',
            'search' => 'FLANGE',
        ]));
    }
}
}

