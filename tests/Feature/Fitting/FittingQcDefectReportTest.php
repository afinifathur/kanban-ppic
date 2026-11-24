<?php

namespace Tests\Feature\Fitting;

use App\Models\DefectType;
use App\Models\FittingItem;
use App\Models\FittingStageExecution;
use App\Models\FittingStageExecutionDefect;
use App\Models\FittingTransfer;
use App\Models\LostWaxCastingResult;
use App\Models\LostWaxKitir;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\Fitting\FittingQcDefectReportService;
use Carbon\Carbon;
use Database\Seeders\AuditorUserSeeder;
use Database\Seeders\FittingSpvUserSeeder;
use Database\Seeders\QcFittingUserSeeder;
use Database\Seeders\SalesVisibilityUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FittingQcDefectReportTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 8000;

    private User $systemAdmin;

    private User $ppicFitting;

    private User $adminPpicPf;

    private User $spvNettoPf;

    private DefectType $defectPorosity;

    private DefectType $defectCrack;

    private DefectType $defectDimension;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setEnvVar('AUDITOR_INITIAL_PASSWORD', 'AudPass123!');
        $this->setEnvVar('SALES_INITIAL_PASSWORD', 'SalesPass123!');
        $this->setEnvVar('FITTING_SPV_INITIAL_PASSWORD', 'FitPass123!');

        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution', 'guard_name' => 'web']);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'])->syncPermissions([$permPlanning, $permExecution]);
        Role::firstOrCreate(['name' => 'ppic', 'guard_name' => 'web'])->syncPermissions([$permPlanning, $permExecution]);

        $this->systemAdmin = User::create([
            'name' => 'MR System Admin',
            'email' => 'mr@peroniks.com',
            'password' => Hash::make('password'),
        ]);
        $this->systemAdmin->assignRole('admin');

        $this->ppicFitting = User::create([
            'name' => 'PPIC Fitting',
            'email' => 'ppicfitting@peroniks.com',
            'password' => Hash::make('password'),
            'product_scope' => 'FITTING_STAINLESS',
        ]);
        $this->ppicFitting->assignRole('ppic');

        $this->adminPpicPf = User::create([
            'name' => 'Admin PPIC PF',
            'email' => 'adminppicpf@peroniks.com',
            'password' => Hash::make('password'),
        ]);
        $this->adminPpicPf->assignRole('admin');

        (new QcFittingUserSeeder)->run();
        (new AuditorUserSeeder)->run();
        (new SalesVisibilityUserSeeder)->run();
        (new FittingSpvUserSeeder)->run();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->systemAdmin->refresh();
        $this->ppicFitting->refresh();
        $this->adminPpicPf->refresh();
        $this->spvNettoPf = User::where('email', 'spvnettopf@peroniks.com')->firstOrFail();

        $this->defectPorosity = DefectType::create([
            'department' => FittingItem::STAGE_NETTO_PF,
            'name' => 'Porosity Netto',
            'is_active' => true,
        ]);

        $this->defectCrack = DefectType::create([
            'department' => FittingItem::STAGE_KIMIA,
            'name' => 'Crack Kimia',
            'is_active' => true,
        ]);

        $this->defectDimension = DefectType::create([
            'department' => FittingItem::STAGE_BUBUT_CNC_PF,
            'name' => 'Dimensi Bubut',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['AUDITOR_INITIAL_PASSWORD', 'SALES_INITIAL_PASSWORD', 'FITTING_SPV_INITIAL_PASSWORD'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    private function setEnvVar(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function createProductionPlan(array $attributes = []): ProductionPlan
    {
        $n = self::$seq++;

        return ProductionPlan::create(array_merge([
            'code' => "SPK-QC-{$n}",
            'title' => "Plan QC {$n}",
            'item_code' => "ITM-QC-{$n}",
            'item_name' => "TEE 1 INCH {$n}",
            'customer' => "PT Mitra QC {$n}",
            'line_number' => 1,
            'po_number' => "PO-QC-{$n}",
            'po_quantity' => 100,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'weight' => 0.75,
            'product_scope' => 'FITTING_STAINLESS',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'status' => 'IN_PROGRESS',
        ], $attributes));
    }

    private function createTransferWithSingleItem(
        ProductionPlan $plan,
        int $qty = 100,
        int $lineNumber = 1,
        string $stage = FittingItem::STAGE_NETTO_PF,
        string $transferDate = '2026-11-20'
    ): FittingItem {
        $n = self::$seq++;

        $res = LostWaxCastingResult::create([
            'heat_number' => "H-QC-{$n}",
            'cast_date' => $transferDate,
            'furnace' => '1',
            'shift' => '1',
            'operator_name' => 'Op QC Rep',
            'total_trees' => 1,
            'total_pcs' => $qty,
            'status' => 'COMPLETED',
            'recorded_by' => $this->ppicFitting->id,
        ]);

        $kitir = LostWaxKitir::create([
            'lost_wax_casting_result_id' => $res->id,
            'traveler_number' => "PFKTR-QC-{$n}",
            'production_code' => $plan->code,
            'quantity_pcs' => $qty,
            'tree_count' => 1,
            'issued_at' => Carbon::now(),
            'issued_by' => $this->ppicFitting->id,
        ]);

        $transfer = FittingTransfer::create([
            'transfer_number' => "PFTRF-QC-{$n}",
            'transfer_date' => $transferDate,
            'transferred_by' => $this->ppicFitting->id,
            'total_kitirs' => 1,
            'total_pcs' => $qty,
            'total_weight_kg' => round($qty * 0.75, 2),
            'status' => FittingTransfer::STATUS_ACTIVE,
        ]);

        return FittingItem::create([
            'fitting_transfer_id' => $transfer->id,
            'lost_wax_kitir_id' => $kitir->id,
            'active_kitir_id' => $kitir->id,
            'lost_wax_casting_result_id' => $res->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $kitir->traveler_number,
            'heat_number' => $res->heat_number,
            'cast_date' => $res->cast_date,
            'production_code' => $plan->code,
            'item_code' => $plan->item_code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'line_number' => $lineNumber,
            'tree_count' => 1,
            'qty_transferred' => $qty,
            'unit_weight_kg' => 0.75,
            'total_weight_kg' => round($qty * 0.75, 2),
            'is_multi_allocation' => false,
            'current_stage' => $stage,
            'status' => FittingItem::STATUS_ACTIVE,
        ]);
    }

    private function addExecutionWithDefects(
        FittingItem $item,
        string $stage,
        int $inputQty,
        array $defects, // array of [DefectType $type, int $qty, string $notes]
        string $status = FittingStageExecution::STATUS_CONFIRMED,
        ?string $inspectionDate = null,
        ?string $physicalDoneAt = '2026-11-20 10:00:00',
        ?string $qcVerifiedAt = '2026-11-20 14:00:00'
    ): FittingStageExecution {
        $checkpoints = [
            'netto_pf' => FittingStageExecution::CHECKPOINT_NETTO,
            'kimia' => FittingStageExecution::CHECKPOINT_KIMIA,
            'marking' => FittingStageExecution::CHECKPOINT_MARKING,
            'bubut_cnc_pf' => FittingStageExecution::CHECKPOINT_CNC,
            'qc_pf' => FittingStageExecution::CHECKPOINT_QC,
            'gudang_jadi_pf' => FittingStageExecution::CHECKPOINT_GUDANG_JADI,
        ];

        $totalDefect = array_sum(array_column($defects, 'qty'));

        $exec = FittingStageExecution::create([
            'fitting_item_id' => $item->id,
            'stage' => $stage,
            'checkpoint_code' => $checkpoints[$stage],
            'input_qty' => $inputQty,
            'defect_qty' => $totalDefect,
            'good_qty' => max(0, $inputQty - $totalDefect),
            'status' => $status,
            'operator_id' => $this->ppicFitting->id,
            'executed_at' => $physicalDoneAt ? Carbon::parse($physicalDoneAt) : Carbon::now(),
            'physical_done_at' => $physicalDoneAt ? Carbon::parse($physicalDoneAt) : null,
            'inspection_date' => $inspectionDate ? Carbon::parse($inspectionDate) : null,
            'defect_entered_at' => Carbon::now(),
            'defect_entered_by' => $this->ppicFitting->id,
            'qc_verified_at' => $qcVerifiedAt ? Carbon::parse($qcVerifiedAt) : null,
            'qc_verified_by' => $this->systemAdmin->id,
        ]);

        foreach ($defects as $d) {
            FittingStageExecutionDefect::create([
                'fitting_stage_execution_id' => $exec->id,
                'defect_type_id' => $d['type']->id,
                'qty' => $d['qty'],
                'notes' => $d['notes'] ?? null,
            ]);
        }

        return $exec;
    }

    /**
     * Requirement 1 & 2: Only CONFIRMED defects appear; WAITING_QC, WAITING_DEFECT, and REOPENED do not.
     */
    public function test_01_only_confirmed_defects_appear_in_official_report(): void
    {
        $plan = $this->createProductionPlan();
        $item = $this->createTransferWithSingleItem($plan, 100);

        // 1. Confirmed defect (5 pcs)
        $this->addExecutionWithDefects($item, 'netto_pf', 100, [
            ['type' => $this->defectPorosity, 'qty' => 5, 'notes' => 'Confirmed porosity'],
        ], status: FittingStageExecution::STATUS_CONFIRMED);

        // 2. Waiting QC defect (8 pcs)
        $this->addExecutionWithDefects($item, 'kimia', 95, [
            ['type' => $this->defectCrack, 'qty' => 8, 'notes' => 'Draft waiting qc'],
        ], status: FittingStageExecution::STATUS_WAITING_QC);

        // 3. Reopened for correction (4 pcs)
        $this->addExecutionWithDefects($item, 'marking', 87, [
            ['type' => $this->defectDimension, 'qty' => 4, 'notes' => 'Reopened'],
        ], status: FittingStageExecution::STATUS_REOPENED_FOR_CORRECTION);

        /** @var FittingQcDefectReportService $service */
        $service = app(FittingQcDefectReportService::class);
        $dataset = $service->getReportDataset([
            'date_from' => '2026-11-20',
            'date_to' => '2026-11-20',
        ], $this->ppicFitting, paginate: false);

        // Assert: Only 5 pcs from CONFIRMED should appear
        $this->assertEquals(5, $dataset['summary']['total_defect_pcs']);
        $this->assertEquals(1, $dataset['summary']['affected_ktr_count']);
        $this->assertCount(1, $dataset['all_records']);
        $this->assertEquals('Porosity Netto', $dataset['all_records']->first()['defect_name']);
        $this->assertEquals(5, $dataset['all_records']->first()['qty']);
    }

    /**
     * Requirement 3: Late defect uses correct event date, not administrative input/verification date.
     */
    public function test_02_late_defect_historical_event_date(): void
    {
        $plan = $this->createProductionPlan();
        $item = $this->createTransferWithSingleItem($plan, 100, transferDate: '2026-11-10');

        // Event occurred on 2026-11-10 (physical done)
        // QC verified late on 2026-11-20
        $this->addExecutionWithDefects(
            $item,
            'netto_pf',
            100,
            [['type' => $this->defectPorosity, 'qty' => 7]],
            status: FittingStageExecution::STATUS_CONFIRMED,
            inspectionDate: null,
            physicalDoneAt: '2026-11-10 09:30:00',
            qcVerifiedAt: '2026-11-20 16:00:00'
        );

        /** @var FittingQcDefectReportService $service */
        $service = app(FittingQcDefectReportService::class);

        // Query on event date (2026-11-10) -> MUST be included
        $datasetEvent = $service->getReportDataset([
            'date_from' => '2026-11-10',
            'date_to' => '2026-11-10',
        ], $this->ppicFitting, paginate: false);

        $this->assertEquals(7, $datasetEvent['summary']['total_defect_pcs']);
        $this->assertCount(1, $datasetEvent['all_records']);

        // Query on late verification date (2026-11-20) -> MUST NOT be included
        $datasetVerification = $service->getReportDataset([
            'date_from' => '2026-11-20',
            'date_to' => '2026-11-20',
        ], $this->ppicFitting, paginate: false);

        $this->assertEquals(0, $datasetVerification['summary']['total_defect_pcs']);
        $this->assertCount(0, $datasetVerification['all_records']);
    }

    /**
     * Requirement 4: Date hierarchy (inspection_date priority, physical_done_at fallback).
     */
    public function test_03_date_hierarchy_inspection_date_and_fallback(): void
    {
        $plan = $this->createProductionPlan();
        $item = $this->createTransferWithSingleItem($plan, 100);

        // Case A: inspection_date exists (2026-11-15), physical_done_at is 2026-11-12
        // Effective date MUST be 2026-11-15
        $execA = $this->addExecutionWithDefects(
            $item,
            'netto_pf',
            100,
            [['type' => $this->defectPorosity, 'qty' => 3]],
            status: FittingStageExecution::STATUS_CONFIRMED,
            inspectionDate: '2026-11-15',
            physicalDoneAt: '2026-11-12 10:00:00'
        );

        $this->assertEquals('2026-11-15', $execA->resolveEffectiveDefectDate());

        // Case B: inspection_date is null, physical_done_at is 2026-11-18
        // Effective date MUST fallback to 2026-11-18
        $execB = $this->addExecutionWithDefects(
            $item,
            'kimia',
            97,
            [['type' => $this->defectCrack, 'qty' => 2]],
            status: FittingStageExecution::STATUS_CONFIRMED,
            inspectionDate: null,
            physicalDoneAt: '2026-11-18 11:00:00'
        );

        $this->assertEquals('2026-11-18', $execB->resolveEffectiveDefectDate());

        /** @var FittingQcDefectReportService $service */
        $service = app(FittingQcDefectReportService::class);

        // Querying on 2026-11-15 finds Case A (3 pcs)
        $data15 = $service->getReportDataset(['date_from' => '2026-11-15', 'date_to' => '2026-11-15'], paginate: false);
        $this->assertEquals(3, $data15['summary']['total_defect_pcs']);

        // Querying on 2026-11-18 finds Case B (2 pcs)
        $data18 = $service->getReportDataset(['date_from' => '2026-11-18', 'date_to' => '2026-11-18'], paginate: false);
        $this->assertEquals(2, $data18['summary']['total_defect_pcs']);
    }

    /**
     * Requirement 5: Aggregation across six Fitting stages.
     */
    public function test_04_aggregation_across_six_fitting_stages(): void
    {
        $plan = $this->createProductionPlan();
        $item = $this->createTransferWithSingleItem($plan, 100);

        // Add confirmed defects to all 6 stages
        $stages = [
            'netto_pf' => 3,
            'kimia' => 2,
            'marking' => 1,
            'bubut_cnc_pf' => 4,
            'qc_pf' => 5,
            'gudang_jadi_pf' => 2,
        ];

        foreach ($stages as $stg => $defQty) {
            $this->addExecutionWithDefects($item, $stg, 100, [
                ['type' => $this->defectPorosity, 'qty' => $defQty],
            ], physicalDoneAt: '2026-11-20 10:00:00');
        }

        /** @var FittingQcDefectReportService $service */
        $service = app(FittingQcDefectReportService::class);
        $dataset = $service->getReportDataset([
            'date_from' => '2026-11-20',
            'date_to' => '2026-11-20',
        ], paginate: false);

        $this->assertEquals(17, $dataset['summary']['total_defect_pcs']);

        // Assert all 6 stages present in stage_distribution
        $dist = $dataset['stage_distribution'];
        $this->assertCount(6, $dist);
        $this->assertEquals(3, $dist['netto_pf']['defect_pcs']);
        $this->assertEquals(2, $dist['kimia']['defect_pcs']);
        $this->assertEquals(1, $dist['marking']['defect_pcs']);
        $this->assertEquals(4, $dist['bubut_cnc_pf']['defect_pcs']);
        $this->assertEquals(5, $dist['qc_pf']['defect_pcs']);
        $this->assertEquals(2, $dist['gudang_jadi_pf']['defect_pcs']);
    }

    /**
     * Requirement 6: Pareto defect ranking and percentage calculation.
     */
    public function test_05_pareto_ranking_and_percentage(): void
    {
        $plan = $this->createProductionPlan();
        $item = $this->createTransferWithSingleItem($plan, 100);

        // Porosity: 20 pcs
        // Crack: 10 pcs
        // Dimension: 5 pcs
        // Total = 35 pcs
        $this->addExecutionWithDefects($item, 'netto_pf', 100, [
            ['type' => $this->defectPorosity, 'qty' => 20],
            ['type' => $this->defectCrack, 'qty' => 10],
            ['type' => $this->defectDimension, 'qty' => 5],
        ], physicalDoneAt: '2026-11-20 10:00:00');

        /** @var FittingQcDefectReportService $service */
        $service = app(FittingQcDefectReportService::class);
        $dataset = $service->getReportDataset([
            'date_from' => '2026-11-20',
            'date_to' => '2026-11-20',
        ], paginate: false);

        $pareto = $dataset['pareto_summary'];
        $this->assertCount(3, $pareto);

        // Ranked descending
        $this->assertEquals('Porosity Netto', $pareto[0]['defect_name']);
        $this->assertEquals(20, $pareto[0]['qty_pcs']);
        $this->assertEquals(57.1, $pareto[0]['contribution_pct']); // 20/35 * 100

        $this->assertEquals('Crack Kimia', $pareto[1]['defect_name']);
        $this->assertEquals(10, $pareto[1]['qty_pcs']);
        $this->assertEquals(28.6, $pareto[1]['contribution_pct']); // 10/35 * 100

        $this->assertEquals('Dimensi Bubut', $pareto[2]['defect_name']);
        $this->assertEquals(5, $pareto[2]['qty_pcs']);
        $this->assertEquals(14.3, $pareto[2]['contribution_pct']); // 5/35 * 100

        $this->assertEquals('Porosity Netto', $dataset['summary']['top_defect_type']);
    }

    /**
     * Requirement 7: Filter by stage, line, and search.
     */
    public function test_06_filtering_by_stage_line_and_search(): void
    {
        $planLine1 = $this->createProductionPlan(['line_number' => 1, 'code' => 'SPK-LINE-1']);
        $itemLine1 = $this->createTransferWithSingleItem($planLine1, 100, lineNumber: 1);

        $planLine2 = $this->createProductionPlan(['line_number' => 2, 'code' => 'SPK-LINE-2']);
        $itemLine2 = $this->createTransferWithSingleItem($planLine2, 100, lineNumber: 2);

        $this->addExecutionWithDefects($itemLine1, 'netto_pf', 100, [
            ['type' => $this->defectPorosity, 'qty' => 6],
        ], physicalDoneAt: '2026-11-20 10:00:00');

        $this->addExecutionWithDefects($itemLine2, 'bubut_cnc_pf', 100, [
            ['type' => $this->defectDimension, 'qty' => 9],
        ], physicalDoneAt: '2026-11-20 10:00:00');

        /** @var FittingQcDefectReportService $service */
        $service = app(FittingQcDefectReportService::class);

        // Filter Stage 'netto_pf'
        $dataStage = $service->getReportDataset([
            'date_from' => '2026-11-20',
            'date_to' => '2026-11-20',
            'stage' => 'netto_pf',
        ], paginate: false);
        $this->assertEquals(6, $dataStage['summary']['total_defect_pcs']);

        // Filter Line 2
        $dataLine = $service->getReportDataset([
            'date_from' => '2026-11-20',
            'date_to' => '2026-11-20',
            'line' => 2,
        ], paginate: false);
        $this->assertEquals(9, $dataLine['summary']['total_defect_pcs']);

        // Search by SPK code
        $dataSearch = $service->getReportDataset([
            'date_from' => '2026-11-20',
            'date_to' => '2026-11-20',
            'search' => 'SPK-LINE-1',
        ], paginate: false);
        $this->assertEquals(6, $dataSearch['summary']['total_defect_pcs']);
    }

    /**
     * Requirement 8: Anti double-count with multi-breakdown executions.
     */
    public function test_07_anti_double_count_with_multi_breakdowns(): void
    {
        $plan = $this->createProductionPlan();
        $item = $this->createTransferWithSingleItem($plan, 100);

        // 1 execution with 3 defect breakdowns
        $this->addExecutionWithDefects($item, 'netto_pf', 100, [
            ['type' => $this->defectPorosity, 'qty' => 4],
            ['type' => $this->defectCrack, 'qty' => 3],
            ['type' => $this->defectDimension, 'qty' => 1],
        ], physicalDoneAt: '2026-11-20 10:00:00');

        /** @var FittingQcDefectReportService $service */
        $service = app(FittingQcDefectReportService::class);
        $dataset = $service->getReportDataset([
            'date_from' => '2026-11-20',
            'date_to' => '2026-11-20',
        ], paginate: false);

        // Total pcs is 8
        $this->assertEquals(8, $dataset['summary']['total_defect_pcs']);
        // Affected PFKTR must be 1, NOT 3!
        $this->assertEquals(1, $dataset['summary']['affected_ktr_count']);
    }

    /**
     * Requirement 9: HTTP endpoints, RBAC, Excel & Print PDF exports.
     */
    public function test_08_http_endpoints_rbac_and_exports(): void
    {
        $plan = $this->createProductionPlan();
        $item = $this->createTransferWithSingleItem($plan, 100);
        $this->addExecutionWithDefects($item, 'netto_pf', 100, [
            ['type' => $this->defectPorosity, 'qty' => 5],
        ], physicalDoneAt: '2026-11-20 10:00:00');

        // 1. HTML Index (Authorized: PPIC Fitting)
        $respHtml = $this->actingAs($this->ppicFitting)
            ->get(route('fitting.report.qc-defects.index', [
                'date_from' => '2026-11-20',
                'date_to' => '2026-11-20',
            ]));
        $respHtml->assertOk();
        $respHtml->assertSee('LAPORAN DEFECT QC FITTING');
        $respHtml->assertSee($item->traveler_number);
        $respHtml->assertSee('Porosity Netto');

        // 2. JSON Data endpoint
        $respJson = $this->actingAs($this->ppicFitting)
            ->get(route('fitting.report.qc-defects.data', [
                'date_from' => '2026-11-20',
                'date_to' => '2026-11-20',
            ]));
        $respJson->assertOk();
        $respJson->assertJsonPath('summary.total_defect_pcs', 5);

        // 3. Excel Export endpoint
        $respExcel = $this->actingAs($this->ppicFitting)
            ->get(route('fitting.report.qc-defects.export.excel', [
                'date_from' => '2026-11-20',
                'date_to' => '2026-11-20',
            ]));
        $respExcel->assertOk();
        $respExcel->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // 4. Print / PDF endpoint
        $respPdf = $this->actingAs($this->ppicFitting)
            ->get(route('fitting.report.qc-defects.export.pdf', [
                'date_from' => '2026-11-20',
                'date_to' => '2026-11-20',
            ]));
        $respPdf->assertOk();
        $respPdf->assertSee('Laporan Rincian QC Defect Fitting Production');

        // 5. Unauthorized (SPV Netto cannot view general reports)
        $this->actingAs($this->spvNettoPf)
            ->get(route('fitting.report.qc-defects.index'))
            ->assertForbidden();
    }
}
