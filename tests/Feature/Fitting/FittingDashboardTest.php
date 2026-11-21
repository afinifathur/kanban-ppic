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

class FittingDashboardTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 8000;

    private User $systemAdmin;

    private User $ppicFitting;

    private User $adminPpicPf;

    private User $spvNettoPf;

    private DefectType $defectPorosity;

    private DefectType $defectCrack;

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
            'department' => FittingItem::STAGE_QC_PF,
            'name' => 'Crack QC',
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
            'code' => "SPK-FD-{$n}",
            'title' => "Plan FD {$n}",
            'item_code' => "ITM-FD-{$n}",
            'item_name' => "ELBOW 90 DEG {$n}",
            'customer' => "PT Pelanggan {$n}",
            'line_number' => 1,
            'po_number' => "PO-FD-{$n}",
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
            'heat_number' => "H-FD-{$n}",
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
            'traveler_number' => "PFKTR-FD-{$n}",
            'production_code' => $plan->code,
            'quantity_pcs' => $qty,
            'tree_count' => 1,
            'issued_at' => Carbon::now(),
            'issued_by' => $this->ppicFitting->id,
        ]);

        $transfer = FittingTransfer::create([
            'transfer_number' => "PFTRF-FD-{$n}",
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
     * Test Zone 1: Executive throughput and terminal warehouse receipt.
     */
    public function test_zone_1_executive_throughput_and_terminal_receipt(): void
    {
        Carbon::setTestNow('2026-11-21 15:00:00');

        $plan = $this->createProductionPlan();
        $item = $this->createTransferWithSingleItem($plan, 100, lineNumber: 1);

        $this->addExecution($item, 'netto_pf', 100, 2, '2026-11-21 08:00:00');
        $this->addExecution($item, 'gudang_jadi_pf', 98, 0, '2026-11-21 13:00:00');

        /** @var FittingDashboardService $service */
        $service = app(FittingDashboardService::class);
        $data = $service->getData('1', $this->ppicFitting, bypassCache: true);

        $this->assertArrayHasKey('zone_1', $data);
        $z1 = $data['zone_1'];

        $stages = collect($z1['stages']);
        $this->assertCount(6, $stages);

        $netto = $stages->firstWhere('stage', 'netto_pf');
        $this->assertEquals(100, $netto['input_pcs']);
        $this->assertEquals(2, $netto['defect_pcs']);
        $this->assertEquals(98, $netto['good_pcs']);

        // Terminal Gudang Jadi
        $this->assertEquals(98, $z1['warehouse_receipt_today']['good_pcs']);
        $this->assertEquals(1, $z1['warehouse_receipt_today']['ktr_count']);
    }

    /**
     * Test Zone 2: WIP Pipeline (5 Active stages + 1 Terminal stage) and FIFO aging watchlist.
     */
    public function test_zone_2_wip_pipeline_and_aging_watchlist(): void
    {
        Carbon::setTestNow('2026-11-21 15:00:00');

        $plan = $this->createProductionPlan(['code' => 'SPK-FIFO-1', 'item_name' => 'Flange 2 Inch']);

        // Item 1: in Netto PF transferred 3 days ago
        $item1 = $this->createTransferWithSingleItem($plan, 50, lineNumber: 1, stage: 'netto_pf', transferDate: '2026-11-18');

        // Item 2: in QC PF transferred 5 days ago
        $item2 = $this->createTransferWithSingleItem($plan, 60, lineNumber: 2, stage: 'qc_pf', transferDate: '2026-11-16');

        // Item 3: in Gudang Jadi PF (terminal output - must NOT be in active WIP)
        $item3 = $this->createTransferWithSingleItem($plan, 70, lineNumber: 3, stage: 'gudang_jadi_pf', transferDate: '2026-11-15');

        /** @var FittingDashboardService $service */
        $service = app(FittingDashboardService::class);
        $data = $service->getData('2', $this->ppicFitting, bypassCache: true);

        $this->assertArrayHasKey('zone_2', $data);
        $z2 = $data['zone_2'];

        // Active WIP is strictly item1 (50) + item2 (60) = 110 PCS (terminal item3 excluded)
        $this->assertEquals(110, $z2['total_active_wip_pcs']);

        // Exactly 5 active stages in distribution
        $this->assertCount(5, $z2['wip_distribution']);

        // Terminal stage info
        $this->assertFalse($z2['terminal_stage']['is_wip']);
        $this->assertEquals(1, $z2['terminal_stage']['ktr_count']);

        // FIFO Watchlist: top candidates sorted by stage aging
        $watchlist = collect($z2['aging_watchlist']);
        $this->assertNotEmpty($watchlist);
        $this->assertLessThanOrEqual(5, $watchlist->count());
    }

    /**
     * Test Zone 3: Quality Analytics, CONFIRMED defect filter, and action backlog cards.
     */
    public function test_zone_3_quality_analytics_and_action_cards(): void
    {
        Carbon::setTestNow('2026-11-21 15:00:00');

        $plan = $this->createProductionPlan();
        $item = $this->createTransferWithSingleItem($plan, 100);

        // Execution with WAITING_DEFECT
        $exec1 = $this->addExecution($item, 'netto_pf', 100, 0, '2026-11-21 08:00:00', FittingStageExecution::STATUS_WAITING_DEFECT);

        // Execution with WAITING_QC
        $exec2 = $this->addExecution($item, 'kimia', 100, 5, '2026-11-21 09:00:00', FittingStageExecution::STATUS_WAITING_QC);

        // Execution with CONFIRMED defect (QC verified)
        $exec3 = $this->addExecution($item, 'qc_pf', 95, 4, '2026-11-20 10:00:00', FittingStageExecution::STATUS_CONFIRMED);
        FittingStageExecutionDefect::create([
            'fitting_stage_execution_id' => $exec3->id,
            'defect_type_id' => $this->defectCrack->id,
            'qty' => 4,
            'status' => 'CONFIRMED',
            'inspection_date' => '2026-11-20',
            'inspected_by' => $this->ppicFitting->id,
        ]);

        /** @var FittingDashboardService $service */
        $service = app(FittingDashboardService::class);
        $data = $service->getData('3', $this->ppicFitting, bypassCache: true);

        $this->assertArrayHasKey('zone_3', $data);
        $z3 = $data['zone_3'];

        // Action backlog cards
        $this->assertArrayHasKey('action_cards', $z3);
        $this->assertGreaterThanOrEqual(1, $z3['action_cards']['waiting_defect']['ktr_count']);
        $this->assertGreaterThanOrEqual(1, $z3['action_cards']['waiting_qc']['ktr_count']);

        // 30-day defect trend has 30 days
        $this->assertCount(30, $z3['defect_trend_30_days']);

        // Quality analytics summary
        $this->assertGreaterThanOrEqual(4, $z3['quality_analytics_summary']['total_verified_pcs']);

        // 6 stages in stage defect contribution
        $this->assertCount(6, $z3['stage_defect_contribution']);
    }

    /**
     * Test Zone 4: Daily production tonnage trend across 30 calendar days for 6 stages.
     */
    public function test_zone_4_daily_throughput_tonnage_30_days(): void
    {
        Carbon::setTestNow('2026-11-21 15:00:00');

        $plan = $this->createProductionPlan();
        $item = $this->createTransferWithSingleItem($plan, 100, lineNumber: 2);

        $this->addExecution($item, 'bubut_cnc_pf', 100, 0, '2026-11-21 10:00:00');

        /** @var FittingDashboardService $service */
        $service = app(FittingDashboardService::class);
        $data = $service->getData('4', $this->ppicFitting, bypassCache: true);

        $this->assertArrayHasKey('zone_4', $data);
        $z4 = $data['zone_4'];

        $this->assertEquals('qc_pf', $z4['default_stage']);
        $this->assertArrayHasKey('stages', $z4);
        $this->assertCount(6, $z4['stages']);

        $cncData = $z4['stages']['bubut_cnc_pf'];
        $this->assertEquals(100, $cncData['total_good_pcs']);
        $this->assertCount(30, $cncData['daily']);
    }

    /**
     * Test HTTP endpoints and Zone switching.
     */
    public function test_http_dashboard_and_zone_switching(): void
    {
        Carbon::setTestNow('2026-11-21 15:00:00');

        // All Zones view
        $respAll = $this->actingAs($this->ppicFitting)->get(route('fitting.dashboard'));
        $respAll->assertOk();
        $respAll->assertSee('DASHBOARD OPERASIONAL FITTING');
        $respAll->assertSee('Z1');
        $respAll->assertSee('Z2');
        $respAll->assertSee('Z3');
        $respAll->assertSee('Z4');

        // Single Zone 1 view
        $respZ1 = $this->actingAs($this->ppicFitting)->get(route('fitting.dashboard', ['zone' => '1']));
        $respZ1->assertOk();
        $respZ1->assertSee('Z1');

        // Single Zone 2 view
        $respZ2 = $this->actingAs($this->ppicFitting)->get(route('fitting.dashboard', ['zone' => '2']));
        $respZ2->assertOk();
        $respZ2->assertSee('Z2');

        // JSON endpoint
        $respJson = $this->actingAs($this->ppicFitting)->get(route('fitting.dashboard.data', ['zone' => 'all', 'fresh' => 1]));
        $respJson->assertOk();
        $respJson->assertJsonStructure([
            'meta',
            'zone_1',
            'zone_2',
            'zone_3',
            'zone_4',
        ]);
    }

    /**
     * Test RBAC permissions.
     */
    public function test_rbac_authorization(): void
    {
        // PPIC Fitting is authorized
        $this->actingAs($this->ppicFitting)->get(route('fitting.dashboard'))->assertOk();

        // Admin PPIC PF is authorized
        $this->actingAs($this->adminPpicPf)->get(route('fitting.dashboard'))->assertOk();

        // Single-stage floor SPV is forbidden
        $this->actingAs($this->spvNettoPf)->get(route('fitting.dashboard'))->assertForbidden();
    }
}
