<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\SandCastingStageExecutionDefectLog;
use App\Models\User;
use App\Services\SandCasting\SandCastingAccountingReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SandCastingAccountingReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $ppicUser;

    protected User $qcUser;

    protected User $auditorUser;

    protected SandCastingAccountingReportService $reportService;

    protected function setUp(): void
    {
        parent::setUp();

        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution']);
        $permProdVisibility = Permission::firstOrCreate(['name' => 'access_production_visibility']);
        $permAuditor = Permission::firstOrCreate(['name' => 'access_auditor_visibility']);

        $roleAdmin = Role::firstOrCreate(['name' => 'admin']);
        $roleAdmin->givePermissionTo([$permPlanning, $permExecution, $permProdVisibility, $permAuditor]);

        $rolePpic = Role::firstOrCreate(['name' => 'ppic']);
        $rolePpic->givePermissionTo([$permPlanning, $permExecution, $permProdVisibility]);

        $roleQc = Role::firstOrCreate(['name' => 'qc']);
        $roleQc->givePermissionTo([$permExecution]);

        $roleAuditor = Role::firstOrCreate(['name' => 'auditor']);
        $roleAuditor->givePermissionTo([$permAuditor]);

        $this->adminUser = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@peroniks.com',
        ]);
        $this->adminUser->assignRole('admin');

        $this->ppicUser = User::factory()->create([
            'name' => 'Admin PPIC Flange',
            'email' => 'adminppicfl@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->ppicUser->assignRole('ppic');

        $this->qcUser = User::factory()->create([
            'name' => 'Admin QC Flange',
            'email' => 'adminqcflange@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->qcUser->assignRole('qc');

        $this->auditorUser = User::factory()->create([
            'name' => 'Auditor Accounting',
            'email' => 'accounting@peroniks.com',
        ]);
        $this->auditorUser->assignRole('auditor');

        $this->reportService = app(SandCastingAccountingReportService::class);
    }

    protected function createKtr(array $options = []): SandCastingCastingResultLine
    {
        static $seq = 1;
        $id = $seq++;

        $scope = $options['product_scope'] ?? 'FLANGE_BESI';
        $weight = array_key_exists('weight', $options) ? $options['weight'] : 2.50;
        $lineUnitWeight = array_key_exists('unit_weight_kg', $options) ? $options['unit_weight_kg'] : 2.50;
        $castDate = $options['cast_date'] ?? '2026-10-01';
        $poQuantity = array_key_exists('po_quantity', $options) ? $options['po_quantity'] : (array_key_exists('po_qty', $options) ? $options['po_qty'] : 1000);

        $plan = ProductionPlan::create([
            'code' => $options['code'] ?? 'PLAN-'.$id,
            'title' => 'Rencana SC '.$id,
            'item_code' => '4.'.$id,
            'item_name' => $options['item_name'] ?? 'FLANGE BESI JIS 10K '.$id.'"',
            'po_number' => $options['po_number'] ?? 'PO-'.$id,
            'po_quantity' => $poQuantity,
            'line_number' => 1,
            'qty_planned' => $poQuantity !== null ? (int) round($poQuantity * 1.10) : 0,
            'qty_remaining' => 100,
            'customer' => $options['customer'] ?? 'PT SINAR METAL',
            'product_scope' => $scope,
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
            'weight' => $weight,
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-'.$id,
            'scheduled_date' => $castDate,
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => (int) ($poQuantity ?? 0),
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '2"',
            'unit_weight_kg' => $lineUnitWeight,
            'total_weight_kg' => ($lineUnitWeight ?? 0) * 100,
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => $options['heat_number'] ?? 'HEAT-'.$id,
            'cast_date' => $castDate,
            'furnace' => 'F1',
            'shift' => '1',
            'recorded_by' => $this->adminUser->id,
        ]);

        $qtyGood = $options['qty_good'] ?? 100;
        $qtyReject = $options['qty_reject'] ?? 0;

        $line = SandCastingCastingResultLine::create([
            'sand_casting_casting_result_id' => $result->id,
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $options['traveler_number'] ?? 'KTR-'.Carbon::parse($castDate)->format('Ymd').'-'.sprintf('%04d', $id),
            'qty_good' => $qtyGood,
            'qty_reject' => $qtyReject,
            'unit_weight_kg' => $lineUnitWeight,
            'total_weight_kg' => ($lineUnitWeight ?? 0) * $qtyGood,
            'current_stage' => $options['current_stage'] ?? 'netto',
            'created_at' => Carbon::parse($castDate)->startOfDay(),
            'updated_at' => Carbon::parse($castDate)->startOfDay(),
        ]);

        return $line;
    }

    protected function addStageExecution(
        SandCastingCastingResultLine $line,
        string $stage,
        string $checkpointCode,
        int $inputQty,
        int $goodQty,
        int $defectQty,
        string $physicalDoneAt,
        ?array $defectLogs = null
    ): SandCastingStageExecution {
        $defectDate = null;
        if ($defectLogs !== null && count($defectLogs) > 0) {
            $defectDate = Carbon::parse($defectLogs[0]['created_at']);
        } elseif ($defectQty > 0) {
            $defectDate = Carbon::parse($physicalDoneAt);
        }

        $exec = SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => $stage,
            'checkpoint_code' => $checkpointCode,
            'input_qty' => $inputQty,
            'good_qty' => $goodQty,
            'defect_qty' => $defectQty,
            'operator_id' => $this->adminUser->id,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => Carbon::parse($physicalDoneAt),
            'executed_at' => Carbon::parse($physicalDoneAt),
            'defect_entered_at' => $defectDate,
            'created_at' => Carbon::parse($physicalDoneAt),
            'updated_at' => Carbon::parse($physicalDoneAt),
        ]);

        if ($defectLogs !== null) {
            foreach ($defectLogs as $log) {
                $dl = new SandCastingStageExecutionDefectLog([
                    'sand_casting_stage_execution_id' => $exec->id,
                    'added_qty' => $log['added_qty'],
                    'previous_total' => $log['previous_total'],
                    'new_total' => $log['new_total'],
                    'user_id' => $this->qcUser->id,
                ]);
                $dl->created_at = Carbon::parse($log['created_at']);
                $dl->updated_at = Carbon::parse($log['created_at']);
                $dl->save();
            }
        } elseif ($defectQty > 0) {
            $dl = new SandCastingStageExecutionDefectLog([
                'sand_casting_stage_execution_id' => $exec->id,
                'added_qty' => $defectQty,
                'previous_total' => 0,
                'new_total' => $defectQty,
                'user_id' => $this->qcUser->id,
            ]);
            $dl->created_at = Carbon::parse($physicalDoneAt);
            $dl->updated_at = Carbon::parse($physicalDoneAt);
            $dl->save();
        }

        return $exec;
    }

    /**
     * Requirement 1: Web UI only renders Pergerakan Produksi (no WIP tabs, no WIP summary).
     */
    public function test_01_web_ui_renders_only_production_movement(): void
    {
        $line = $this->createKtr(['cast_date' => '2026-10-01', 'qty_good' => 50]);
        $this->addStageExecution($line, 'netto', 'NETTO_CUT', 50, 50, 0, '2026-10-02 10:00:00');

        $response = $this->actingAs($this->auditorUser)->get('/sand-casting/report/accounting?date_from=2026-10-01&date_to=2026-10-31');
        $response->assertStatus(200);

        // Retained elements
        $response->assertSee('LAPORAN ACCOUNTING');
        $response->assertSee('Matriks Tracking Produksi Horizontal');
        $response->assertSee('Weight (KG/PCS)');
        $response->assertSee('Total Weight (KG)');
        $response->assertSee('Export Excel');
        $response->assertSee('Cetak / PDF');

        // Removed elements (WIP tabs and WIP summary)
        $response->assertDontSee('Saldo Awal WIP');
        $response->assertDontSee('Saldo Akhir WIP');
        $response->assertDontSee('Summary & Rekonsiliasi WIP');
        $response->assertDontSee('Summary &amp; Rekonsiliasi WIP');
        $response->assertDontSee('Persamaan Matematis Rekonsiliasi');
    }

    /**
     * Requirement 2: Excel has exactly one sheet 'Pergerakan Produksi' with 22 columns and freeze pane.
     */
    public function test_02_excel_export_has_single_movement_sheet(): void
    {
        $line = $this->createKtr(['cast_date' => '2026-10-01', 'qty_good' => 50]);
        $this->addStageExecution($line, 'netto', 'NETTO_CUT', 50, 50, 0, '2026-10-02 10:00:00');

        // HTTP endpoint test
        $excelResponse = $this->actingAs($this->auditorUser)->get('/sand-casting/report/accounting/export/excel?date_from=2026-10-01&date_to=2026-10-31');
        $excelResponse->assertStatus(200);
        $this->assertEquals('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $excelResponse->headers->get('Content-Type'));
        $this->assertStringContainsString('Laporan_Accounting_Sand_Casting_2026-10-01_2026-10-31.xlsx', (string) $excelResponse->headers->get('Content-Disposition'));

        // Spreadsheet structure test
        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $spreadsheet = $this->reportService->buildExcelSpreadsheet($data);

        $this->assertEquals(1, $spreadsheet->getSheetCount(), 'Excel must contain exactly one sheet');
        $sheet = $spreadsheet->getSheet(0);
        $this->assertEquals('Pergerakan Produksi', $sheet->getTitle());
        $this->assertEquals('Kode Produksi', $sheet->getCell('B4')->getValue());
        $this->assertEquals('I6', $sheet->getFreezePane());
    }

    /**
     * Requirement 3: PDF printable view only contains horizontal production tracking table.
     */
    public function test_03_pdf_export_contains_only_horizontal_tracking(): void
    {
        $line = $this->createKtr(['cast_date' => '2026-10-01', 'qty_good' => 50]);
        $this->addStageExecution($line, 'netto', 'NETTO_CUT', 50, 50, 0, '2026-10-02 10:00:00');

        $pdfResponse = $this->actingAs($this->auditorUser)->get('/sand-casting/report/accounting/export/pdf?date_from=2026-10-01&date_to=2026-10-31');
        $pdfResponse->assertStatus(200);

        // Retained elements
        $pdfResponse->assertSee('LAPORAN ACCOUNTING SAND CASTING');
        $pdfResponse->assertSee('HORIZONTAL PRODUCTION TRACKING');
        $pdfResponse->assertSee('Keterangan Matriks Pergerakan Produksi');

        // Removed elements
        $pdfResponse->assertDontSee('KESIMPULAN & REKONSILIASI WIP');
        $pdfResponse->assertDontSee('KESIMPULAN &amp; REKONSILIASI WIP');
        $pdfResponse->assertDontSee('SEBARAN SALDO AKHIR WIP');
        $pdfResponse->assertDontSee('A. REKONSILIASI ARUS WIP');
        $pdfResponse->assertDontSee('sign-table');
    }

    /**
     * Requirement 4: Aggregation by Code + Heat Number correctly merges batches.
     */
    public function test_04_aggregation_code_and_heat_number(): void
    {
        // Batch 1 (KTR 1)
        $ktr1 = $this->createKtr([
            'code' => 'CODE-A',
            'heat_number' => 'HEAT-X',
            'cast_date' => '2026-10-01',
            'qty_good' => 40,
        ]);
        $this->addStageExecution($ktr1, 'netto', 'NETTO_CUT', 40, 40, 0, '2026-10-02 10:00:00');

        // Batch 2 (KTR 2) - same code and heat
        $ktr2 = $this->createKtr([
            'code' => 'CODE-A',
            'heat_number' => 'HEAT-X',
            'cast_date' => '2026-10-01',
            'qty_good' => 60,
        ]);
        $this->addStageExecution($ktr2, 'netto', 'NETTO_CUT', 60, 60, 0, '2026-10-02 11:00:00');

        // Batch 3 (KTR 3) - different code and heat
        $ktr3 = $this->createKtr([
            'code' => 'CODE-B',
            'heat_number' => 'HEAT-Y',
            'cast_date' => '2026-10-01',
            'qty_good' => 75,
        ]);
        $this->addStageExecution($ktr3, 'netto', 'NETTO_CUT', 75, 75, 0, '2026-10-02 12:00:00');

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);

        // KTR 1 and KTR 2 merge into 1 row, KTR 3 is row 2
        $this->assertCount(2, $data['pergerakan']);

        $rowA = collect($data['pergerakan'])->firstWhere('code', 'CODE-A');
        $this->assertNotNull($rowA);
        $this->assertEquals('HEAT-X', $rowA['heat_number']);
        $this->assertEquals(2, $rowA['ktr_count']);
        $this->assertEquals(100, $rowA['stages']['cor']['hasil']);
        $this->assertEquals(100, $rowA['stages']['netto']['hasil']);

        $rowB = collect($data['pergerakan'])->firstWhere('code', 'CODE-B');
        $this->assertNotNull($rowB);
        $this->assertEquals('HEAT-Y', $rowB['heat_number']);
        $this->assertEquals(1, $rowB['ktr_count']);
        $this->assertEquals(75, $rowB['stages']['cor']['hasil']);
    }

    /**
     * Requirement 5: Hasil and Rusak per department are preserved and accurate.
     */
    public function test_05_stage_hasil_and_rusak_quantities_preserved(): void
    {
        $line = $this->createKtr(['code' => 'FLOW-01', 'heat_number' => 'HEAT-01', 'qty_good' => 100]);
        // Netto: input 100, defect 5 -> output 95
        $this->addStageExecution($line, 'netto', 'NETTO_CUT', 100, 95, 5, '2026-10-02 10:00:00');
        // OD: input 95, defect 0 -> output 95
        $this->addStageExecution($line, 'bubut_od', 'OD_TURNING', 95, 95, 0, '2026-10-03 10:00:00');
        // CNC: input 95, defect 90 -> output 5
        $this->addStageExecution($line, 'bubut_cnc', 'CNC_MACHINING', 95, 5, 90, '2026-10-04 10:00:00');

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $row = collect($data['pergerakan'])->firstWhere('code', 'FLOW-01');

        $this->assertNotNull($row);
        $this->assertEquals(100, $row['stages']['cor']['hasil']);
        $this->assertEquals(0, $row['stages']['cor']['rusak']);

        $this->assertEquals(100, $row['stages']['netto']['hasil']);
        $this->assertEquals(5, $row['stages']['netto']['rusak']);
        $this->assertEquals(95, $row['stages']['netto']['output']);

        $this->assertEquals(95, $row['stages']['bubut_od']['hasil']);
        $this->assertEquals(0, $row['stages']['bubut_od']['rusak']);
        $this->assertEquals(95, $row['stages']['bubut_od']['output']);

        $this->assertEquals(95, $row['stages']['bubut_cnc']['hasil']);
        $this->assertEquals(90, $row['stages']['bubut_cnc']['rusak']);
        $this->assertEquals(5, $row['stages']['bubut_cnc']['output']);

        // Unreached stages remain null
        $this->assertNull($row['stages']['bor']['hasil']);
        $this->assertNull($row['stages']['qc']['hasil']);
        $this->assertNull($row['stages']['gudang_jadi']['hasil']);
    }

    /**
     * Requirement 6: Historical cutoff strictly excludes executions after cutoff date.
     */
    public function test_06_historical_cutoff_filters_future_executions(): void
    {
        $line = $this->createKtr(['cast_date' => '2026-10-01', 'qty_good' => 50]);
        // Executed in October
        $this->addStageExecution($line, 'netto', 'NETTO_CUT', 50, 50, 0, '2026-10-15 10:00:00');
        // Executed in November
        $this->addStageExecution($line, 'bubut_od', 'OD_TURNING', 50, 50, 0, '2026-11-05 10:00:00');

        // October report: Bubut OD must be null (unreached)
        $dataOct = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $rowOct = $dataOct['pergerakan'][0];
        $this->assertEquals(50, $rowOct['stages']['netto']['hasil']);
        $this->assertNull($rowOct['stages']['bubut_od']['hasil']);

        // November report: Bubut OD is completed
        $dataNov = $this->reportService->getAccountingReportData(['date_from' => '2026-11-01', 'date_to' => '2026-11-30']);
        $rowNov = $dataNov['pergerakan'][0];
        $this->assertEquals(50, $rowNov['stages']['bubut_od']['hasil']);
    }

    /**
     * Requirement 7: Defect recorded after cutoff date does not affect historical period.
     */
    public function test_07_defect_entered_after_cutoff_ignored(): void
    {
        $line = $this->createKtr(['cast_date' => '2026-10-05', 'qty_good' => 100]);
        // Physical done on Oct 10 with defect entered on Nov 05
        $this->addStageExecution(
            $line,
            'netto',
            'NETTO_CUT',
            100,
            80,
            20,
            '2026-10-10 10:00:00',
            [
                ['added_qty' => 20, 'previous_total' => 0, 'new_total' => 20, 'created_at' => '2026-11-05 14:00:00'],
            ]
        );

        // October report: defect must be 0
        $dataOct = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $rowOct = $dataOct['pergerakan'][0];
        $this->assertEquals(0, $rowOct['stages']['netto']['rusak']);

        // November report: defect is 20
        $dataNov = $this->reportService->getAccountingReportData(['date_from' => '2026-11-01', 'date_to' => '2026-11-30']);
        $rowNov = $dataNov['pergerakan'][0];
        $this->assertEquals(20, $rowNov['stages']['netto']['rusak']);
    }

    /**
     * Requirement 8: Weight (KG/PCS) and Total Weight (KG) are accurate and not double-counted.
     */
    public function test_08_weight_and_total_weight_calculation(): void
    {
        $line1 = $this->createKtr([
            'code' => 'WT-01',
            'heat_number' => 'HEAT-WT',
            'qty_good' => 50,
            'unit_weight_kg' => 2.50,
        ]);
        $this->addStageExecution($line1, 'netto', 'NETTO_CUT', 50, 50, 0, '2026-10-02 10:00:00');
        $this->addStageExecution($line1, 'bubut_od', 'OD_TURNING', 50, 50, 0, '2026-10-03 10:00:00');

        $line2 = $this->createKtr([
            'code' => 'WT-01',
            'heat_number' => 'HEAT-WT',
            'qty_good' => 50,
            'unit_weight_kg' => 2.50,
        ]);
        $this->addStageExecution($line2, 'netto', 'NETTO_CUT', 50, 50, 0, '2026-10-02 11:00:00');

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $row = $data['pergerakan'][0];

        // Unit weight is not summed across KTRs
        $this->assertEquals(2.50, $row['unit_weight_kg']);

        // Total weight = 100 pcs * 2.50 = 250.00 kg (not multiplied by number of departments)
        $this->assertEquals(250.00, $row['total_weight_kg']);
    }

    /**
     * Requirement 9: Weight anomaly across KTRs in the same code+heat group is detected.
     */
    public function test_09_weight_anomaly_detection(): void
    {
        $ktr1 = $this->createKtr([
            'code' => 'ANOM-01',
            'heat_number' => 'HEAT-ANOM',
            'qty_good' => 50,
            'unit_weight_kg' => 2.50,
        ]);
        $this->addStageExecution($ktr1, 'netto', 'NETTO_CUT', 50, 50, 0, '2026-10-02 10:00:00');

        $ktr2 = $this->createKtr([
            'code' => 'ANOM-01',
            'heat_number' => 'HEAT-ANOM',
            'qty_good' => 50,
            'unit_weight_kg' => 2.80, // different weight
        ]);
        $this->addStageExecution($ktr2, 'netto', 'NETTO_CUT', 50, 50, 0, '2026-10-02 11:00:00');

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $row = $data['pergerakan'][0];

        $this->assertTrue($row['is_weight_anomaly']);
        $this->assertNotEmpty($data['anomalies']);
        $this->assertEquals('ANOM-01', $data['anomalies'][0]['code']);
        $this->assertEquals('HEAT-ANOM', $data['anomalies'][0]['heat_number']);
    }

    /**
     * Requirement 10: Main report flow excludes all unnecessary WIP calculations.
     */
    public function test_10_main_report_flow_excludes_wip_calculations(): void
    {
        $line = $this->createKtr(['cast_date' => '2026-10-01', 'qty_good' => 50]);
        $this->addStageExecution($line, 'netto', 'NETTO_CUT', 50, 50, 0, '2026-10-02 10:00:00');

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);

        // Main dataset must ONLY contain movement report components
        $this->assertArrayHasKey('meta', $data);
        $this->assertArrayHasKey('pergerakan', $data);
        $this->assertArrayHasKey('pergerakan_raw', $data);
        $this->assertArrayHasKey('anomalies', $data);

        // WIP components must NOT be present in the main report flow
        $this->assertArrayNotHasKey('summary', $data);
        $this->assertArrayNotHasKey('saldo_awal', $data);
        $this->assertArrayNotHasKey('saldo_akhir', $data);
        $this->assertArrayNotHasKey('saldo_awal_wip', $data);
        $this->assertArrayNotHasKey('saldo_akhir_wip', $data);
    }

    /**
     * Requirement 11: WIP calculation logic is preserved and functional when explicitly requested.
     */
    public function test_11_isolated_wip_calculation_can_be_invoked_when_explicitly_requested(): void
    {
        $line = $this->createKtr(['cast_date' => '2026-10-01', 'qty_good' => 50]);
        $this->addStageExecution($line, 'netto', 'NETTO_CUT', 50, 50, 0, '2026-10-02 10:00:00');

        // Explicitly requesting WIP
        $dataWithWip = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31'], null, true);

        $this->assertArrayHasKey('summary', $dataWithWip);
        $this->assertArrayHasKey('saldo_awal', $dataWithWip);
        $this->assertArrayHasKey('saldo_akhir', $dataWithWip);
        $this->assertTrue($dataWithWip['summary']['is_balanced']);
        $this->assertEquals(50, $dataWithWip['summary']['inflow_cor_pcs']);
        $this->assertEquals(50, $dataWithWip['summary']['saldo_akhir_pcs']);
    }

    /**
     * Requirement 12 (Summary Req 1): Detail format remains completely unchanged.
     */
    public function test_12_format_detail_preserves_original_behavior(): void
    {
        $line1 = $this->createKtr(['code' => 'DET-01', 'heat_number' => 'HEAT-D1', 'qty_good' => 30]);
        $this->addStageExecution($line1, 'netto', 'NETTO_CUT', 30, 30, 0, '2026-10-02 10:00:00');

        $line2 = $this->createKtr(['code' => 'DET-01', 'heat_number' => 'HEAT-D2', 'qty_good' => 40]);
        $this->addStageExecution($line2, 'netto', 'NETTO_CUT', 40, 40, 0, '2026-10-02 11:00:00');

        $dataDetail = $this->reportService->getAccountingReportData([
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-31',
            'report_format' => 'detail',
        ]);

        $this->assertEquals('detail', $dataDetail['meta']['report_format']);
        $this->assertCount(2, $dataDetail['pergerakan']);
        $this->assertEquals('HEAT-D1', $dataDetail['pergerakan'][0]['heat_number']);
        $this->assertEquals('HEAT-D2', $dataDetail['pergerakan'][1]['heat_number']);
    }

    /**
     * Requirement 13 (Summary Req 2 & 3): Five heats with same Code are merged into one row with stage sums.
     */
    public function test_13_summary_format_merges_five_heats_into_one_row(): void
    {
        // 5 Heats for 268UN62 as specified in prompt example: 18, 1, 5, 12, 16
        $heats = [
            ['heat' => 'A212092601', 'qty' => 18, 'netto_def' => 0],
            ['heat' => 'A212092602', 'qty' => 1, 'netto_def' => 0],
            ['heat' => 'A212092603', 'qty' => 5, 'netto_def' => 1],
            ['heat' => 'A212092604', 'qty' => 12, 'netto_def' => 2],
            ['heat' => 'A212092605', 'qty' => 16, 'netto_def' => 0],
        ];

        foreach ($heats as $h) {
            $line = $this->createKtr([
                'code' => '268UN62',
                'heat_number' => $h['heat'],
                'cast_date' => '2026-10-05',
                'qty_good' => $h['qty'],
            ]);
            $goodNetto = $h['qty'] - $h['netto_def'];
            $this->addStageExecution($line, 'netto', 'NETTO_CUT', $h['qty'], $goodNetto, $h['netto_def'], '2026-10-06 10:00:00');
        }

        $dataSummary = $this->reportService->getAccountingReportData([
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-31',
            'report_format' => 'summary',
        ]);

        $this->assertEquals('summary', $dataSummary['meta']['report_format']);
        $row = collect($dataSummary['pergerakan'])->firstWhere('code', '268UN62');

        $this->assertNotNull($row);
        $this->assertEquals(5, $row['heat_count']);
        // Total COR Hasil = 18 + 1 + 5 + 12 + 16 = 52
        $this->assertEquals(52, $row['stages']['cor']['hasil']);
        $this->assertEquals(0, $row['stages']['cor']['rusak']);

        // NETTO Hasil = 52, Rusak = 0 + 0 + 1 + 2 + 0 = 3
        $this->assertEquals(52, $row['stages']['netto']['hasil']);
        $this->assertEquals(3, $row['stages']['netto']['rusak']);
        $this->assertEquals(49, $row['stages']['netto']['output']);
    }

    /**
     * Requirement 14 (Summary Req 4 & 5): Unreached stages show dash (null) and zero defect shows 0.
     */
    public function test_14_unreached_stages_and_zero_defects_distinguished(): void
    {
        $line1 = $this->createKtr(['code' => 'STAGE-01', 'heat_number' => 'HEAT-S1', 'qty_good' => 50]);
        // Reached Netto with 0 defect
        $this->addStageExecution($line1, 'netto', 'NETTO_CUT', 50, 50, 0, '2026-10-02 10:00:00');
        // Reached CNC with 0 defect
        $this->addStageExecution($line1, 'bubut_cnc', 'CNC_MACHINING', 50, 50, 0, '2026-10-03 10:00:00');

        $line2 = $this->createKtr(['code' => 'STAGE-01', 'heat_number' => 'HEAT-S2', 'qty_good' => 30]);
        // Reached Netto only
        $this->addStageExecution($line2, 'netto', 'NETTO_CUT', 30, 30, 0, '2026-10-02 11:00:00');

        $data = $this->reportService->getAccountingReportData([
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-31',
            'report_format' => 'summary',
        ]);

        $row = collect($data['pergerakan'])->firstWhere('code', 'STAGE-01');

        // Netto: both reached -> Hasil = 80, Rusak = 0
        $this->assertEquals(80, $row['stages']['netto']['hasil']);
        $this->assertEquals(0, $row['stages']['netto']['rusak']);

        // CNC: only Heat 1 reached -> Hasil = 50, Rusak = 0 (partial heat sum)
        $this->assertEquals(50, $row['stages']['bubut_cnc']['hasil']);
        $this->assertEquals(0, $row['stages']['bubut_cnc']['rusak']);

        // Bor & QC & Gudang Jadi: NO heat reached -> must be null (rendered as dash)
        $this->assertNull($row['stages']['bor']['hasil']);
        $this->assertNull($row['stages']['bor']['rusak']);
        $this->assertNull($row['stages']['qc']['hasil']);
        $this->assertNull($row['stages']['gudang_jadi']['hasil']);
    }

    /**
     * Requirement 15 (Summary Req 6): Total weight is batch sum, not multiplied across stages.
     */
    public function test_15_total_weight_is_batch_sum_not_multiplied_across_stages(): void
    {
        $line1 = $this->createKtr(['code' => 'WT-SUM', 'heat_number' => 'HEAT-W1', 'qty_good' => 40, 'unit_weight_kg' => 3.0]);
        $this->addStageExecution($line1, 'netto', 'NETTO_CUT', 40, 40, 0, '2026-10-02 10:00:00');
        $this->addStageExecution($line1, 'bubut_od', 'OD_TURNING', 40, 40, 0, '2026-10-03 10:00:00');
        $this->addStageExecution($line1, 'bubut_cnc', 'CNC_MACHINING', 40, 40, 0, '2026-10-04 10:00:00');

        $line2 = $this->createKtr(['code' => 'WT-SUM', 'heat_number' => 'HEAT-W2', 'qty_good' => 60, 'unit_weight_kg' => 3.0]);
        $this->addStageExecution($line2, 'netto', 'NETTO_CUT', 60, 60, 0, '2026-10-02 11:00:00');

        $data = $this->reportService->getAccountingReportData([
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-31',
            'report_format' => 'summary',
        ]);

        $row = collect($data['pergerakan'])->firstWhere('code', 'WT-SUM');

        // Unit weight is 3.00
        $this->assertEquals('3.00', $row['unit_weight_label']);
        // Total weight = (40 + 60) * 3.0 = 300.00 KG (not multiplied by number of completed stages)
        $this->assertEquals(300.00, $row['total_weight_kg']);
    }

    /**
     * Requirement 16 (Summary Req 7): Weight variation displays Bervariasi and flags anomaly.
     */
    public function test_16_weight_variation_displays_bervariasi_and_flags_anomaly(): void
    {
        $line1 = $this->createKtr(['code' => 'VAR-WT', 'heat_number' => 'HEAT-V1', 'qty_good' => 50, 'unit_weight_kg' => 2.50]);
        $this->addStageExecution($line1, 'netto', 'NETTO_CUT', 50, 50, 0, '2026-10-02 10:00:00');

        $line2 = $this->createKtr(['code' => 'VAR-WT', 'heat_number' => 'HEAT-V2', 'qty_good' => 50, 'unit_weight_kg' => 2.80]);
        $this->addStageExecution($line2, 'netto', 'NETTO_CUT', 50, 50, 0, '2026-10-02 11:00:00');

        $data = $this->reportService->getAccountingReportData([
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-31',
            'report_format' => 'summary',
        ]);

        $row = collect($data['pergerakan'])->firstWhere('code', 'VAR-WT');

        $this->assertTrue($row['is_weight_anomaly']);
        $this->assertEquals('Bervariasi', $row['unit_weight_label']);
        // Total weight is sum of batch weights: (50 * 2.50) + (50 * 2.80) = 125 + 140 = 265.00 kg
        $this->assertEquals(265.00, $row['total_weight_kg']);
    }

    /**
     * Requirement 17 (Summary Req 8, 9, 10): P.O and Plan display, empty handling, and anomaly detection.
     */
    public function test_17_po_and_plan_handling_and_anomaly(): void
    {
        // 1. Consistent PO and Plan (PO = 1.000 PCS -> Plan = 1.100 PCS)
        $line1 = $this->createKtr([
            'code' => 'PO-OK',
            'heat_number' => 'HEAT-P1',
            'po_quantity' => 1000,
            'qty_good' => 50,
        ]);
        $this->addStageExecution($line1, 'netto', 'NETTO_CUT', 50, 50, 0, '2026-10-02 10:00:00');

        // 2. Empty PO and Plan
        $lineEmpty = $this->createKtr([
            'code' => 'PO-EMPTY',
            'heat_number' => 'HEAT-PE',
            'po_quantity' => null,
            'qty_good' => 30,
        ]);
        $lineEmpty->productionPlan->update(['qty_planned' => 0, 'po_number' => '', 'po_quantity' => null]);
        $lineEmpty->castingOrderLine->update(['qty_ordered' => 0]);
        $this->addStageExecution($lineEmpty, 'netto', 'NETTO_CUT', 30, 30, 0, '2026-10-02 10:00:00');

        // 3. Differing PO on same Code (Anomaly: PO 500 vs PO 700)
        $lineVar1 = $this->createKtr([
            'code' => 'PO-DIFF',
            'heat_number' => 'HEAT-PD1',
            'po_quantity' => 500,
            'qty_good' => 20,
        ]);
        $this->addStageExecution($lineVar1, 'netto', 'NETTO_CUT', 20, 20, 0, '2026-10-02 10:00:00');

        $lineVar2 = $this->createKtr([
            'code' => 'PO-DIFF',
            'heat_number' => 'HEAT-PD2',
            'po_quantity' => 700,
            'qty_good' => 20,
        ]);
        $this->addStageExecution($lineVar2, 'netto', 'NETTO_CUT', 20, 20, 0, '2026-10-02 11:00:00');

        $data = $this->reportService->getAccountingReportData([
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-31',
            'report_format' => 'summary',
        ]);

        // Consistent: PO = 1000, Plan = 1100 (1000 * 1.10)
        $rowOk = collect($data['pergerakan'])->firstWhere('code', 'PO-OK');
        $this->assertEquals(1000, $rowOk['po_qty']);
        $this->assertEquals(1000, $rowOk['po_number']);
        $this->assertEquals(1100, $rowOk['qty_planned']);

        // Empty PO and Plan remain empty string
        $rowEmpty = collect($data['pergerakan'])->firstWhere('code', 'PO-EMPTY');
        $this->assertSame('', $rowEmpty['po_qty']);
        $this->assertSame('', $rowEmpty['po_number']);
        $this->assertSame('', $rowEmpty['qty_planned']);

        // Anomaly: Differing PO
        $rowDiff = collect($data['pergerakan'])->firstWhere('code', 'PO-DIFF');
        $this->assertTrue($rowDiff['is_po_anomaly']);
        $this->assertEquals('Bervariasi', $rowDiff['po_qty']);
        $this->assertEquals('Bervariasi', $rowDiff['po_number']);
        $this->assertEquals('Bervariasi', $rowDiff['qty_planned']);
    }

    /**
     * Requirement 18 (Summary Req 11): Multiple checkpoints on same stage do not duplicate quantity.
     */
    public function test_18_legacy_checkpoint_does_not_duplicate_quantity(): void
    {
        $line = $this->createKtr(['code' => 'MULTI-CP', 'heat_number' => 'HEAT-CP', 'qty_good' => 60]);
        $this->addStageExecution($line, 'netto', 'NETTO_CUT', 60, 60, 0, '2026-10-02 10:00:00');
        // Checkpoint 1 on CNC
        $this->addStageExecution($line, 'bubut_cnc', 'CNC_MACHINING', 60, 60, 0, '2026-10-03 10:00:00');
        // Checkpoint 2 on CNC (e.g. QC_POST_CNC)
        $this->addStageExecution($line, 'bubut_cnc', 'QC_POST_CNC', 60, 58, 2, '2026-10-03 11:00:00');

        $data = $this->reportService->getAccountingReportData([
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-31',
            'report_format' => 'summary',
        ]);

        $row = collect($data['pergerakan'])->firstWhere('code', 'MULTI-CP');

        // CNC Hasil must NOT be 120 (sum of input), but 60 (sequential runningInput)
        $this->assertEquals(60, $row['stages']['bubut_cnc']['hasil']);
        $this->assertEquals(2, $row['stages']['bubut_cnc']['rusak']);
        $this->assertEquals(58, $row['stages']['bubut_cnc']['output']);
    }

    /**
     * Requirement 19 (Summary Req 12): Excel export follows chosen format.
     */
    public function test_19_excel_export_respects_chosen_format(): void
    {
        $line = $this->createKtr(['code' => 'XLS-SUM', 'heat_number' => 'HEAT-X1', 'po_quantity' => 1000, 'qty_good' => 50]);
        $this->addStageExecution($line, 'netto', 'NETTO_CUT', 50, 50, 0, '2026-10-02 10:00:00');

        // Summary Excel
        $responseSummary = $this->actingAs($this->auditorUser)->get('/sand-casting/report/accounting/export/excel?date_from=2026-10-01&date_to=2026-10-31&report_format=summary');
        $responseSummary->assertStatus(200);
        $this->assertStringContainsString('Laporan_Accounting_Sand_Casting_Ringkas_', (string) $responseSummary->headers->get('Content-Disposition'));

        $dataSummary = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'report_format' => 'summary']);
        $spreadsheetSummary = $this->reportService->buildExcelSpreadsheet($dataSummary);
        $sheetSummary = $spreadsheetSummary->getSheet(0);

        // Column F is P.O (PCS) and Column G is Plan (PCS), Freeze pane is J6
        $this->assertEquals('P.O (PCS)', $sheetSummary->getCell('F4')->getValue());
        $this->assertEquals('Plan (PCS)', $sheetSummary->getCell('G4')->getValue());
        $this->assertEquals('J6', $sheetSummary->getFreezePane());

        // Validate summary column widths
        $this->assertEquals(6, $sheetSummary->getColumnDimension('A')->getWidth());
        $this->assertEquals(15, $sheetSummary->getColumnDimension('B')->getWidth());
        $this->assertEquals(32, $sheetSummary->getColumnDimension('C')->getWidth());
        $this->assertEquals(8, $sheetSummary->getColumnDimension('D')->getWidth());
        $this->assertEquals(18, $sheetSummary->getColumnDimension('E')->getWidth());
        $this->assertEquals(12, $sheetSummary->getColumnDimension('F')->getWidth());
        $this->assertEquals(12, $sheetSummary->getColumnDimension('G')->getWidth());
        $this->assertEquals(14, $sheetSummary->getColumnDimension('H')->getWidth());
        $this->assertEquals(16, $sheetSummary->getColumnDimension('I')->getWidth());
        $this->assertEquals(8.5, $sheetSummary->getColumnDimension('J')->getWidth());
        $this->assertEquals(8.5, $sheetSummary->getColumnDimension('W')->getWidth());

        // Detail Excel
        $responseDetail = $this->actingAs($this->auditorUser)->get('/sand-casting/report/accounting/export/excel?date_from=2026-10-01&date_to=2026-10-31&report_format=detail');
        $responseDetail->assertStatus(200);
        $this->assertStringNotContainsString('Ringkas', (string) $responseDetail->headers->get('Content-Disposition'));

        $dataDetail = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'report_format' => 'detail']);
        $spreadsheetDetail = $this->reportService->buildExcelSpreadsheet($dataDetail);
        $sheetDetail = $spreadsheetDetail->getSheet(0);

        // Column C is Heat Number, Freeze pane is I6
        $this->assertEquals('Heat Number', $sheetDetail->getCell('C4')->getValue());
        $this->assertEquals('I6', $sheetDetail->getFreezePane());

        // Validate detail column widths
        $this->assertEquals(6, $sheetDetail->getColumnDimension('A')->getWidth());
        $this->assertEquals(15, $sheetDetail->getColumnDimension('B')->getWidth());
        $this->assertEquals(15, $sheetDetail->getColumnDimension('C')->getWidth());
        $this->assertEquals(32, $sheetDetail->getColumnDimension('D')->getWidth());
        $this->assertEquals(8, $sheetDetail->getColumnDimension('E')->getWidth());
        $this->assertEquals(18, $sheetDetail->getColumnDimension('F')->getWidth());
        $this->assertEquals(14, $sheetDetail->getColumnDimension('G')->getWidth());
        $this->assertEquals(16, $sheetDetail->getColumnDimension('H')->getWidth());
        $this->assertEquals(8.5, $sheetDetail->getColumnDimension('I')->getWidth());
        $this->assertEquals(8.5, $sheetDetail->getColumnDimension('V')->getWidth());
    }

    /**
     * Requirement 20 (Summary Req 13): Historical cutoff and date filter identical on both formats.
     */
    public function test_20_historical_cutoff_and_date_filter_consistent_on_both_formats(): void
    {
        $line = $this->createKtr(['code' => 'CUTOFF-SYNC', 'heat_number' => 'HEAT-CS', 'qty_good' => 50]);
        // October execution
        $this->addStageExecution($line, 'netto', 'NETTO_CUT', 50, 50, 0, '2026-10-15 10:00:00');
        // November execution
        $this->addStageExecution($line, 'bubut_cnc', 'CNC_MACHINING', 50, 50, 0, '2026-11-05 10:00:00');

        // October report: Bubut CNC must be null on BOTH formats
        $dataOctDetail = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'report_format' => 'detail']);
        $dataOctSummary = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'report_format' => 'summary']);

        $rowDetail = collect($dataOctDetail['pergerakan'])->firstWhere('code', 'CUTOFF-SYNC');
        $rowSummary = collect($dataOctSummary['pergerakan'])->firstWhere('code', 'CUTOFF-SYNC');

        $this->assertEquals(50, $rowDetail['stages']['netto']['hasil']);
        $this->assertNull($rowDetail['stages']['bubut_cnc']['hasil']);

        $this->assertEquals(50, $rowSummary['stages']['netto']['hasil']);
        $this->assertNull($rowSummary['stages']['bubut_cnc']['hasil']);

        // November report: Bubut CNC is completed on BOTH formats
        $dataNovDetail = $this->reportService->getAccountingReportData(['date_from' => '2026-11-01', 'date_to' => '2026-11-30', 'report_format' => 'detail']);
        $dataNovSummary = $this->reportService->getAccountingReportData(['date_from' => '2026-11-01', 'date_to' => '2026-11-30', 'report_format' => 'summary']);

        $this->assertEquals(50, $dataNovDetail['pergerakan'][0]['stages']['bubut_cnc']['hasil']);
        $this->assertEquals(50, $dataNovSummary['pergerakan'][0]['stages']['bubut_cnc']['hasil']);
    }

    /**
     * Requirement 21: Web UI in summary format renders P.O and Plan and hides Heat Number.
     */
    public function test_21_web_ui_summary_format_renders_po_and_plan(): void
    {
        $line = $this->createKtr([
            'code' => 'UI-SUM',
            'heat_number' => 'HEAT-UI-1',
            'po_quantity' => 1000,
            'qty_good' => 50,
        ]);
        $this->addStageExecution($line, 'netto', 'NETTO_CUT', 50, 50, 0, '2026-10-02 10:00:00');

        // Summary format request
        $responseSummary = $this->actingAs($this->auditorUser)->get('/sand-casting/report/accounting?date_from=2026-10-01&date_to=2026-10-31&report_format=summary');
        $responseSummary->assertStatus(200);

        $responseSummary->assertSee('Ringkas per Kode Produksi');
        $responseSummary->assertSee('P.O (PCS)');
        $responseSummary->assertSee('Plan (PCS)');
        $responseSummary->assertSee('1.000');
        $responseSummary->assertSee('1.100');

        // Detail format request
        $responseDetail = $this->actingAs($this->auditorUser)->get('/sand-casting/report/accounting?date_from=2026-10-01&date_to=2026-10-31&report_format=detail');
        $responseDetail->assertStatus(200);

        $responseDetail->assertSee('Heat Number');
        $responseDetail->assertSee('HEAT-UI-1');
    }

    /**
     * Requirement 22: PDF printable view in summary format renders 23-column layout.
     */
    public function test_22_pdf_export_follows_format(): void
    {
        $line = $this->createKtr([
            'code' => 'PDF-SUM',
            'heat_number' => 'HEAT-PDF-1',
            'po_quantity' => 1000,
            'qty_good' => 50,
        ]);
        $this->addStageExecution($line, 'netto', 'NETTO_CUT', 50, 50, 0, '2026-10-02 10:00:00');

        $pdfSummary = $this->actingAs($this->auditorUser)->get('/sand-casting/report/accounting/export/pdf?date_from=2026-10-01&date_to=2026-10-31&report_format=summary');
        $pdfSummary->assertStatus(200);

        $pdfSummary->assertSee('RINGKAS PER KODE PRODUKSI');
        $pdfSummary->assertSee('P.O (PCS)');
        $pdfSummary->assertSee('Plan (PCS)');
        $pdfSummary->assertSee('1.000');
        $pdfSummary->assertSee('1.100');

        $pdfDetail = $this->actingAs($this->auditorUser)->get('/sand-casting/report/accounting/export/pdf?date_from=2026-10-01&date_to=2026-10-31&report_format=detail');
        $pdfDetail->assertStatus(200);

        $pdfDetail->assertSee('KODE PRODUKSI + HEAT NUMBER');
        $pdfDetail->assertSee('HEAT-PDF-1');
    }
}
