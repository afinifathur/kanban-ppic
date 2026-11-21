<?php

namespace Tests\Feature\Fitting;

use App\Models\DefectType;
use App\Models\FittingItem;
use App\Models\FittingStageExecution;
use App\Models\FittingTransfer;
use App\Models\LostWaxCastingResult;
use App\Models\LostWaxKitir;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\Fitting\FittingDashboardService;
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

class FittingDashboardZone1Test extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 7000;

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
        Carbon::setTestNow();

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
            'code' => "SPK-DASH-{$n}",
            'title' => "Plan DASH {$n}",
            'item_code' => "ITM-DASH-{$n}",
            'item_name' => "ELBOW 90 DEG {$n}",
            'customer' => "PT Customer {$n}",
            'line_number' => 1,
            'po_number' => "PO-DASH-{$n}",
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
        string $transferDate = '2026-11-21'
    ): FittingItem {
        $n = self::$seq++;

        $res = LostWaxCastingResult::create([
            'heat_number' => "H-DASH-{$n}",
            'cast_date' => $transferDate,
            'furnace' => '1',
            'shift' => '1',
            'operator_name' => 'Op Dash',
            'total_trees' => 1,
            'total_pcs' => $qty,
            'status' => 'COMPLETED',
            'recorded_by' => $this->ppicFitting->id,
        ]);

        $kitir = LostWaxKitir::create([
            'lost_wax_casting_result_id' => $res->id,
            'traveler_number' => "PFKTR-DASH-{$n}",
            'production_code' => $plan->code,
            'quantity_pcs' => $qty,
            'tree_count' => 1,
            'issued_at' => Carbon::now(),
            'issued_by' => $this->ppicFitting->id,
        ]);

        $transfer = FittingTransfer::create([
            'transfer_number' => "PFTRF-DASH-{$n}",
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
        string $doneAt = '2026-11-21 10:00:00',
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
            'operator_id' => $this->ppicFitting->id,
            'executed_at' => Carbon::parse($doneAt),
            'physical_done_at' => Carbon::parse($doneAt),
        ]);
    }

    /**
     * Requirement 1: Only executions with physical_done_at today (Asia/Jakarta) appear in Zone 1.
     */
    public function test_01_activity_strictly_filters_by_today_physical_done_at(): void
    {
        Carbon::setTestNow('2026-11-21 15:00:00');

        $plan = $this->createProductionPlan();
        $item = $this->createTransferWithSingleItem($plan, 100);

        // Execution done TODAY (2026-11-21)
        $this->addExecution($item, 'netto_pf', 100, 0, '2026-11-21 08:30:00');

        // Execution done YESTERDAY (2026-11-20)
        $this->addExecution($item, 'kimia', 100, 0, '2026-11-20 16:00:00');

        /** @var FittingDashboardService $service */
        $service = app(FittingDashboardService::class);
        $data = $service->getData('1', $this->ppicFitting, bypassCache: true);

        $stages = collect($data['zone_1']['stages']);
        $netto = $stages->firstWhere('stage', 'netto_pf');
        $kimia = $stages->firstWhere('stage', 'kimia');

        $this->assertEquals(1, $netto['ktr_count']);
        $this->assertEquals(100, $netto['input_pcs']);

        $this->assertEquals(0, $kimia['ktr_count']);
        $this->assertEquals(0, $kimia['input_pcs']);
    }

    /**
     * Requirement 2: All 6 stages are aggregated correctly.
     */
    public function test_02_all_six_stages_aggregation_in_zone_1(): void
    {
        Carbon::setTestNow('2026-11-21 15:00:00');

        $plan = $this->createProductionPlan();
        $item = $this->createTransferWithSingleItem($plan, 100);

        // Execute all 6 stages today
        $this->addExecution($item, 'netto_pf', 100, 2, '2026-11-21 08:00:00');
        $this->addExecution($item, 'kimia', 98, 1, '2026-11-21 09:00:00');
        $this->addExecution($item, 'marking', 97, 0, '2026-11-21 10:00:00');
        $this->addExecution($item, 'bubut_cnc_pf', 97, 3, '2026-11-21 11:00:00');
        $this->addExecution($item, 'qc_pf', 94, 4, '2026-11-21 12:00:00');
        $this->addExecution($item, 'gudang_jadi_pf', 90, 0, '2026-11-21 13:00:00');

        /** @var FittingDashboardService $service */
        $service = app(FittingDashboardService::class);
        $data = $service->getData('1', $this->ppicFitting, bypassCache: true);

        $stages = collect($data['zone_1']['stages']);
        $this->assertCount(6, $stages);

        // Gudang Jadi terminal output
        $wh = $data['zone_1']['warehouse_receipt_today'];
        $this->assertEquals(90, $wh['good_pcs']);
        $this->assertEquals(1, $wh['ktr_count']);

        // Activity totals
        $totals = $data['zone_1']['activity_totals'];
        $this->assertEquals(6, $totals['total_stage_activities']); // 6 checkpoint processing activities
        $this->assertEquals(10, $totals['total_defect_pcs']); // 2 + 1 + 0 + 3 + 4 + 0
    }

    /**
     * Requirement 3 & 4: WAITING_QC defects affect operational numbers in Zone 1.
     */
    public function test_03_waiting_qc_defects_affect_operational_throughput(): void
    {
        Carbon::setTestNow('2026-11-21 15:00:00');

        $plan = $this->createProductionPlan();
        $item = $this->createTransferWithSingleItem($plan, 100);

        // Netto execution with defect status WAITING_QC (not yet confirmed)
        $this->addExecution($item, 'netto_pf', 100, 8, '2026-11-21 09:00:00', FittingStageExecution::STATUS_WAITING_QC);

        /** @var FittingDashboardService $service */
        $service = app(FittingDashboardService::class);
        $data = $service->getData('1', $this->ppicFitting, bypassCache: true);

        $netto = collect($data['zone_1']['stages'])->firstWhere('stage', 'netto_pf');

        $this->assertEquals(100, $netto['input_pcs']);
        $this->assertEquals(8, $netto['defect_pcs']);
        $this->assertEquals(92, $netto['good_pcs']); // 100 - 8
        $this->assertEquals(8.0, $netto['defect_rate']);
    }

    /**
     * Requirement 5: Late defect recalculation consistency.
     */
    public function test_04_late_defect_recalculation(): void
    {
        Carbon::setTestNow('2026-11-21 15:00:00');

        $plan = $this->createProductionPlan();
        $item = $this->createTransferWithSingleItem($plan, 100);

        // Netto recorded raw input 100, then later 5 defect recorded
        $this->addExecution($item, 'netto_pf', 100, 5, '2026-11-21 08:00:00');

        // Kimia recorded raw input 100, defect 3
        $this->addExecution($item, 'kimia', 100, 3, '2026-11-21 09:00:00');

        /** @var FittingDashboardService $service */
        $service = app(FittingDashboardService::class);
        $data = $service->getData('1', $this->ppicFitting, bypassCache: true);

        $stages = collect($data['zone_1']['stages']);
        $netto = $stages->firstWhere('stage', 'netto_pf');
        $kimia = $stages->firstWhere('stage', 'kimia');

        // Netto: input 100, defect 5, good 95
        $this->assertEquals(100, $netto['input_pcs']);
        $this->assertEquals(5, $netto['defect_pcs']);
        $this->assertEquals(95, $netto['good_pcs']);

        // Kimia: effective input 95 (derived from predecessor good), defect 3, good 92
        $this->assertEquals(95, $kimia['input_pcs']);
        $this->assertEquals(3, $kimia['defect_pcs']);
        $this->assertEquals(92, $kimia['good_pcs']);
    }

    /**
     * Requirement 6: Anti double-count per PFKTR dan tahap.
     */
    public function test_05_anti_double_count_per_pfktr_stage(): void
    {
        Carbon::setTestNow('2026-11-21 15:00:00');

        $plan = $this->createProductionPlan();
        $item1 = $this->createTransferWithSingleItem($plan, 50);
        $item2 = $this->createTransferWithSingleItem($plan, 50);

        // 2 distinct items processed at Netto PF today
        $this->addExecution($item1, 'netto_pf', 50, 0, '2026-11-21 08:00:00');
        $this->addExecution($item2, 'netto_pf', 50, 2, '2026-11-21 08:30:00');

        /** @var FittingDashboardService $service */
        $service = app(FittingDashboardService::class);
        $data = $service->getData('1', $this->ppicFitting, bypassCache: true);

        $netto = collect($data['zone_1']['stages'])->firstWhere('stage', 'netto_pf');

        // PFKTR count must be exactly 2, and input pcs exactly 100
        $this->assertEquals(2, $netto['ktr_count']);
        $this->assertEquals(100, $netto['input_pcs']);
        $this->assertEquals(2, $netto['defect_pcs']);
        $this->assertEquals(98, $netto['good_pcs']);
    }

    /**
     * Requirement 7: HTTP Controller endpoints and RBAC.
     */
    public function test_06_http_endpoints_and_rbac(): void
    {
        Carbon::setTestNow('2026-11-21 15:00:00');

        $plan = $this->createProductionPlan();
        $item = $this->createTransferWithSingleItem($plan, 50);
        $this->addExecution($item, 'netto_pf', 50, 0, '2026-11-21 08:00:00');

        // 1. Authorized: PPIC Fitting
        $respHtml = $this->actingAs($this->ppicFitting)
            ->get(route('fitting.dashboard', ['zone' => '1']));
        $respHtml->assertOk();
        $respHtml->assertSee('DASHBOARD OPERASIONAL FITTING');
        $respHtml->assertSee('Zona 1');

        // 2. JSON Data endpoint
        $respJson = $this->actingAs($this->ppicFitting)
            ->get(route('fitting.dashboard.data', ['zone' => '1', 'fresh' => 1]));
        $respJson->assertOk();
        $respJson->assertJsonPath('zone_1.stages.0.stage', 'netto_pf');
        $respJson->assertJsonPath('zone_1.stages.0.input_pcs', 50);

        // 3. Unauthorized: Single-stage floor SPV cannot access management dashboard
        $this->actingAs($this->spvNettoPf)
            ->get(route('fitting.dashboard'))
            ->assertForbidden();
    }

    /**
     * Requirement 8: Regression protection for Production Report and QC Defect Report.
     */
    public function test_07_regression_protection_for_reports(): void
    {
        $plan = $this->createProductionPlan();
        $item = $this->createTransferWithSingleItem($plan, 50);
        $this->addExecution($item, 'netto_pf', 50, 0, '2026-11-21 08:00:00');

        // Ensure both report routes continue to return 200 OK
        $this->actingAs($this->ppicFitting)
            ->get(route('fitting.report.production.index'))
            ->assertOk();

        $this->actingAs($this->ppicFitting)
            ->get(route('fitting.report.qc-defects.index'))
            ->assertOk();
    }
}
