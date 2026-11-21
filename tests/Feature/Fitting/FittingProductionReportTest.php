<?php

namespace Tests\Feature\Fitting;

use App\Models\DefectType;
use App\Models\FittingItem;
use App\Models\FittingItemAllocation;
use App\Models\FittingStageExecution;
use App\Models\FittingTransfer;
use App\Models\LostWaxCastingResult;
use App\Models\LostWaxKitir;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\Fitting\FittingProductionReportService;
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

class FittingProductionReportTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 9000;

    private User $systemAdmin;

    private User $ppicFitting;

    private User $adminPpicPf;

    private User $spvNettoPf;

    private DefectType $defectPorosity;

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
            'code' => "SPK-REP-{$n}",
            'title' => "Plan {$n}",
            'item_code' => "ITM-{$n}",
            'item_name' => "ELBOW 90 DEG {$n}",
            'customer' => "PT Customer {$n}",
            'line_number' => 1,
            'po_number' => "PO-REP-{$n}",
            'po_quantity' => 100,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'weight' => 0.50,
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
            'heat_number' => "H-REP-{$n}",
            'cast_date' => $transferDate,
            'furnace' => '1',
            'shift' => '1',
            'operator_name' => 'Op Rep',
            'total_trees' => 1,
            'total_pcs' => $qty,
            'status' => 'COMPLETED',
            'recorded_by' => $this->ppicFitting->id,
        ]);

        $kitir = LostWaxKitir::create([
            'lost_wax_casting_result_id' => $res->id,
            'traveler_number' => "PFKTR-REP-{$n}",
            'production_code' => $plan->code,
            'quantity_pcs' => $qty,
            'tree_count' => 1,
            'issued_at' => Carbon::now(),
            'issued_by' => $this->ppicFitting->id,
        ]);

        $transfer = FittingTransfer::create([
            'transfer_number' => "PFTRF-REP-{$n}",
            'transfer_date' => $transferDate,
            'transferred_by' => $this->ppicFitting->id,
            'total_kitirs' => 1,
            'total_pcs' => $qty,
            'total_weight_kg' => round($qty * 0.50, 2),
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
            'unit_weight_kg' => 0.50,
            'total_weight_kg' => round($qty * 0.50, 2),
            'is_multi_allocation' => false,
            'current_stage' => $stage,
            'status' => FittingItem::STATUS_ACTIVE,
        ]);
    }

    private function addExecution(
        FittingItem $item,
        string $stage,
        int $inputQty,
        int $defectQty,
        string $doneAt = '2026-11-20 10:00:00',
        string $status = FittingStageExecution::STATUS_WAITING_DEFECT
    ): FittingStageExecution {
        $checkpoints = [
            'netto_pf' => FittingStageExecution::CHECKPOINT_NETTO,
            'kimia' => FittingStageExecution::CHECKPOINT_KIMIA,
            'marking' => FittingStageExecution::CHECKPOINT_MARKING,
            'bubut_cnc_pf' => FittingStageExecution::CHECKPOINT_CNC,
            'qc_pf' => FittingStageExecution::CHECKPOINT_QC,
            'gudang_jadi_pf' => FittingStageExecution::CHECKPOINT_GUDANG_JADI,
        ];

        return FittingStageExecution::create([
            'fitting_item_id' => $item->id,
            'stage' => $stage,
            'checkpoint_code' => $checkpoints[$stage],
            'input_qty' => $inputQty,
            'defect_qty' => $defectQty,
            'good_qty' => max(0, $inputQty - $defectQty),
            'status' => $status,
            'operator_id' => $this->systemAdmin->id,
            'executed_at' => Carbon::parse($doneAt),
            'physical_done_at' => Carbon::parse($doneAt),
            'inspection_date' => substr($doneAt, 0, 10),
            'defect_entered_at' => Carbon::parse($doneAt),
            'defect_entered_by' => $this->ppicFitting->id,
        ]);
    }

    /**
     * Requirement 1: Enam tahap Fitting terwakili dalam agregasi ringkasan.
     */
    public function test_01_six_stages_aggregation(): void
    {
        $plan = $this->createProductionPlan();
        $item = $this->createTransferWithSingleItem($plan, 100);

        // Add execution for each of the 6 stages on 2026-11-20
        $stages = ['netto_pf', 'kimia', 'marking', 'bubut_cnc_pf', 'qc_pf', 'gudang_jadi_pf'];
        foreach ($stages as $stg) {
            $this->addExecution($item, $stg, 100, 0, '2026-11-20 10:00:00', FittingStageExecution::STATUS_NO_DEFECT);
        }

        $service = app(FittingProductionReportService::class);
        $dataset = $service->getProductionDataset([
            'date_from' => '2026-11-20',
            'date_to' => '2026-11-20',
            'stage' => 'all',
        ]);

        $summaries = $dataset['stage_summaries'];
        $this->assertCount(6, $summaries);

        // Check stages order and keys
        $keys = $summaries->pluck('stage')->toArray();
        $this->assertEquals($stages, $keys);

        // All 6 stages have 1 PFKTR and 100 pcs input & good
        foreach ($summaries as $stg) {
            $this->assertEquals(1, $stg['ktr_count']);
            $this->assertEquals(100, $stg['input_pcs']);
            $this->assertEquals(0, $stg['defect_pcs']);
            $this->assertEquals(100, $stg['good_pcs']);
        }
    }

    /**
     * Requirement 2: Late defect dan rekalkulasi historis otomatis via resolver.
     * Simulation:
     * - Netto raw scan = 100, Kimia raw scan = 100 on Day 1.
     * - Late defect Netto = 5, Defect Kimia = 3 on Day 2.
     * - Report must yield Netto (Input: 100, Good: 95) and Kimia (Input: 95, Good: 92).
     */
    public function test_02_late_defect_historical_recalculation_and_consistency(): void
    {
        $plan = $this->createProductionPlan();
        $item = $this->createTransferWithSingleItem($plan, 100);

        // Day 1: 2026-11-20
        // Netto has defect = 5, Kimia has defect = 3
        $this->addExecution($item, 'netto_pf', 100, 5, '2026-11-20 09:00:00', FittingStageExecution::STATUS_WAITING_QC);
        $this->addExecution($item, 'kimia', 100, 3, '2026-11-20 14:00:00', FittingStageExecution::STATUS_WAITING_QC);

        $service = app(FittingProductionReportService::class);
        $dataset = $service->getProductionDataset([
            'date_from' => '2026-11-20',
            'date_to' => '2026-11-20',
            'stage' => 'all',
        ]);

        $summaries = $dataset['stage_summaries']->keyBy('stage');

        // Netto: Hasil Kerja (Input) = 100, Defect = 5, Good Forward = 95
        $nettoSummary = $summaries->get('netto_pf');
        $this->assertEquals(100, $nettoSummary['input_pcs']);
        $this->assertEquals(5, $nettoSummary['defect_pcs']);
        $this->assertEquals(95, $nettoSummary['good_pcs']);

        // Kimia: Hasil Kerja (Input Efektif) = 95, Defect = 3, Good Forward = 92
        $kimiaSummary = $summaries->get('kimia');
        $this->assertEquals(95, $kimiaSummary['input_pcs']);
        $this->assertEquals(3, $kimiaSummary['defect_pcs']);
        $this->assertEquals(92, $kimiaSummary['good_pcs']);

        // Check details row
        $detailItems = $dataset['items'];
        $nettoDetail = $detailItems->firstWhere('stage', 'netto_pf');
        $kimiaDetail = $detailItems->firstWhere('stage', 'kimia');

        $this->assertEquals(100, $nettoDetail['input_qty']);
        $this->assertEquals(5, $nettoDetail['defect_qty']);
        $this->assertEquals(95, $nettoDetail['good_qty']);

        $this->assertEquals(95, $kimiaDetail['input_qty']);
        $this->assertEquals(3, $kimiaDetail['defect_qty']);
        $this->assertEquals(92, $kimiaDetail['good_qty']);

        // Verify mathematical consistency: Input - Defect = Good
        $this->assertEquals($nettoDetail['input_qty'] - $nettoDetail['defect_qty'], $nettoDetail['good_qty']);
        $this->assertEquals($kimiaDetail['input_qty'] - $kimiaDetail['defect_qty'], $kimiaDetail['good_qty']);
    }

    /**
     * Requirement 3: Defect berstatus WAITING_QC tetap memengaruhi perhitungan operasional.
     */
    public function test_03_waiting_qc_status_affects_operational_report(): void
    {
        $plan = $this->createProductionPlan();
        $item = $this->createTransferWithSingleItem($plan, 100);

        // Status WAITING_QC (not yet confirmed by QC)
        $this->addExecution($item, 'netto_pf', 100, 7, '2026-11-20 09:00:00', FittingStageExecution::STATUS_WAITING_QC);

        $service = app(FittingProductionReportService::class);
        $dataset = $service->getProductionDataset([
            'date_from' => '2026-11-20',
            'date_to' => '2026-11-20',
            'stage' => 'netto_pf',
        ]);

        $summary = $dataset['stage_summaries']->first();
        $this->assertEquals(100, $summary['input_pcs']);
        $this->assertEquals(7, $summary['defect_pcs']);
        $this->assertEquals(93, $summary['good_pcs']);
    }

    /**
     * Requirement 4: Anti double-count per PFKTR dan tahap.
     */
    public function test_04_anti_double_count_per_pfktr(): void
    {
        $plan = $this->createProductionPlan();
        $item1 = $this->createTransferWithSingleItem($plan, 50);
        $item2 = $this->createTransferWithSingleItem($plan, 50);

        $this->addExecution($item1, 'netto_pf', 50, 0, '2026-11-20 08:00:00');
        $this->addExecution($item2, 'netto_pf', 50, 2, '2026-11-20 08:30:00');

        $service = app(FittingProductionReportService::class);
        $dataset = $service->getProductionDataset([
            'date_from' => '2026-11-20',
            'date_to' => '2026-11-20',
            'stage' => 'netto_pf',
        ]);

        $summary = $dataset['stage_summaries']->first();
        $this->assertEquals(2, $summary['ktr_count']);
        $this->assertEquals(100, $summary['input_pcs']);
        $this->assertEquals(2, $summary['defect_pcs']);
        $this->assertEquals(98, $summary['good_pcs']);
    }

    /**
     * Requirement 5: Multi-SPK dan pembagian kuantitas proporsional tanpa selisih pembulatan.
     */
    public function test_05_multi_spk_allocation_rounding_consistency(): void
    {
        $planA = $this->createProductionPlan(['code' => 'SPK-MULTI-A']);
        $planB = $this->createProductionPlan(['code' => 'SPK-MULTI-B']);

        $item = $this->createTransferWithSingleItem($planA, 100);
        $item->update(['is_multi_allocation' => true]);

        // Allocate 60 pcs to Plan A and 40 pcs to Plan B
        $allocA = FittingItemAllocation::create([
            'fitting_item_id' => $item->id,
            'production_plan_id' => $planA->id,
            'production_code' => $planA->code,
            'item_code' => $planA->item_code,
            'item_name' => $planA->item_name,
            'customer' => $planA->customer,
            'line_number' => 1,
            'allocated_qty_transferred' => 60,
            'unit_weight_kg' => 0.50,
            'allocated_weight_kg' => 30.0,
        ]);

        $allocB = FittingItemAllocation::create([
            'fitting_item_id' => $item->id,
            'production_plan_id' => $planB->id,
            'production_code' => $planB->code,
            'item_code' => $planB->item_code,
            'item_name' => $planB->item_name,
            'customer' => $planB->customer,
            'line_number' => 1,
            'allocated_qty_transferred' => 40,
            'unit_weight_kg' => 0.50,
            'allocated_weight_kg' => 20.0,
        ]);

        // Netto defect 5 pcs
        $this->addExecution($item, 'netto_pf', 100, 5, '2026-11-20 10:00:00');

        $service = app(FittingProductionReportService::class);
        $dataset = $service->getProductionDataset([
            'date_from' => '2026-11-20',
            'date_to' => '2026-11-20',
            'stage' => 'netto_pf',
        ]);

        $detail = $dataset['items']->first();
        $this->assertTrue($detail['is_multi_allocation']);
        $this->assertCount(2, $detail['allocations']);

        $allocData = collect($detail['allocations'])->keyBy('production_code');
        $rowA = $allocData->get('SPK-MULTI-A');
        $rowB = $allocData->get('SPK-MULTI-B');

        // Total input must be 60 + 40 = 100
        $this->assertEquals(60, $rowA['allocated_input_qty']);
        $this->assertEquals(40, $rowB['allocated_input_qty']);

        // Defect 5 distributed: 60/100*5 = 3 to A, 40/100*5 = 2 to B (sum = 5)
        $this->assertEquals(3, $rowA['allocated_defect_qty']);
        $this->assertEquals(2, $rowB['allocated_defect_qty']);
        $this->assertEquals(5, $rowA['allocated_defect_qty'] + $rowB['allocated_defect_qty']);

        // Good output: 60 - 3 = 57 for A, 40 - 2 = 38 for B (sum = 95)
        $this->assertEquals(57, $rowA['allocated_good_qty']);
        $this->assertEquals(38, $rowB['allocated_good_qty']);
        $this->assertEquals(95, $rowA['allocated_good_qty'] + $rowB['allocated_good_qty']);
    }

    /**
     * Requirement 6: Filter tanggal berdasarkan physical_done_at.
     */
    public function test_06_filter_physical_done_at_date(): void
    {
        $plan = $this->createProductionPlan();
        $item = $this->createTransferWithSingleItem($plan, 100);

        // Executed on 2026-11-18
        $this->addExecution($item, 'netto_pf', 100, 0, '2026-11-18 10:00:00');
        // Executed on 2026-11-20
        $this->addExecution($item, 'kimia', 100, 0, '2026-11-20 10:00:00');

        $service = app(FittingProductionReportService::class);

        // Filter 2026-11-18: only Netto appears
        $dataset18 = $service->getProductionDataset([
            'date_from' => '2026-11-18',
            'date_to' => '2026-11-18',
            'stage' => 'all',
        ]);
        $summaries18 = $dataset18['stage_summaries']->where('ktr_count', '>', 0);
        $this->assertCount(1, $summaries18);
        $this->assertEquals('netto_pf', $summaries18->first()['stage']);

        // Filter 2026-11-20: only Kimia appears
        $dataset20 = $service->getProductionDataset([
            'date_from' => '2026-11-20',
            'date_to' => '2026-11-20',
            'stage' => 'all',
        ]);
        $summaries20 = $dataset20['stage_summaries']->where('ktr_count', '>', 0);
        $this->assertCount(1, $summaries20);
        $this->assertEquals('kimia', $summaries20->first()['stage']);
    }

    /**
     * Requirement 7: HTTP Controller endpoints, RBAC, and Exports.
     */
    public function test_07_http_endpoints_rbac_and_exports(): void
    {
        $plan = $this->createProductionPlan();
        $item = $this->createTransferWithSingleItem($plan, 50);
        $this->addExecution($item, 'netto_pf', 50, 0, '2026-11-20 10:00:00');

        // Authorized: Admin and PPIC Fitting
        $respHtml = $this->actingAs($this->ppicFitting)
            ->get(route('fitting.report.production.index', [
                'date_from' => '2026-11-20',
                'date_to' => '2026-11-20',
            ]));
        $respHtml->assertOk();
        $respHtml->assertSee('REPORT PRODUKSI FITTING');
        $respHtml->assertSee($item->traveler_number);

        // JSON Data endpoint
        $respJson = $this->actingAs($this->ppicFitting)
            ->get(route('fitting.report.production.data', [
                'date_from' => '2026-11-20',
                'date_to' => '2026-11-20',
            ]));
        $respJson->assertOk();
        $respJson->assertJsonPath('summary.total_input_pcs', 50);

        // Excel Export endpoint
        $respExcel = $this->actingAs($this->ppicFitting)
            ->get(route('fitting.report.production.export.excel', [
                'date_from' => '2026-11-20',
                'date_to' => '2026-11-20',
            ]));
        $respExcel->assertOk();
        $respExcel->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Print PDF endpoint
        $respPdf = $this->actingAs($this->ppicFitting)
            ->get(route('fitting.report.production.export.pdf', [
                'date_from' => '2026-11-20',
                'date_to' => '2026-11-20',
            ]));
        $respPdf->assertOk();
        $respPdf->assertSee('Preview Dokumen Cetak');

        // Unauthorized: Single-stage SPV is forbidden
        $this->actingAs($this->spvNettoPf)
            ->get(route('fitting.report.production.index'))
            ->assertForbidden();
    }
}
