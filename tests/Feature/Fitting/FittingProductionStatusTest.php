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
use App\Services\Fitting\FittingProductionStatusService;
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

class FittingProductionStatusTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 8000;

    private User $systemAdmin;

    private User $ppicFitting;

    private User $adminPpicPf;

    private User $adminQcFitting;

    private User $auditor;

    private User $sales;

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
        $this->adminQcFitting = User::where('email', 'adminqcfitting@peroniks.com')->firstOrFail();
        $this->auditor = User::where('email', 'auditor@peroniks.com')->firstOrFail();
        $this->sales = User::where('email', 'sales@peroniks.com')->firstOrFail();
        $this->spvNettoPf = User::where('email', 'spvnettopf@peroniks.com')->firstOrFail();

        $this->defectPorosity = DefectType::create([
            'department' => FittingItem::STAGE_NETTO_PF,
            'name' => 'Porosity',
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
            'code' => "SPK-TEST-{$n}",
            'title' => "Batch Plan {$n}",
            'item_code' => "ITM-{$n}",
            'item_name' => "ELBOW 90 DEG 1/2 INCH {$n}",
            'customer' => "PT Customer {$n}",
            'line_number' => 1,
            'po_number' => "PO-CUST-{$n}",
            'po_quantity' => 100,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'weight' => 0.45,
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
            'heat_number' => "H-STATUS-{$n}",
            'cast_date' => $transferDate,
            'furnace' => '1',
            'shift' => '1',
            'operator_name' => 'Op Status',
            'total_trees' => 1,
            'total_pcs' => $qty,
            'status' => 'COMPLETED',
            'recorded_by' => $this->ppicFitting->id,
        ]);

        $kitir = LostWaxKitir::create([
            'lost_wax_casting_result_id' => $res->id,
            'traveler_number' => "PFKTR-STATUS-{$n}",
            'production_code' => $plan->code,
            'quantity_pcs' => $qty,
            'tree_count' => 1,
            'issued_at' => Carbon::now(),
            'issued_by' => $this->ppicFitting->id,
        ]);

        $transfer = FittingTransfer::create([
            'transfer_number' => "PFTRF-STATUS-{$n}",
            'transfer_date' => $transferDate,
            'transferred_by' => $this->ppicFitting->id,
            'total_kitirs' => 1,
            'total_pcs' => $qty,
            'total_weight_kg' => round($qty * 0.45, 2),
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
            'unit_weight_kg' => 0.45,
            'total_weight_kg' => round($qty * 0.45, 2),
            'is_multi_allocation' => false,
            'current_stage' => $stage,
            'status' => FittingItem::STATUS_ACTIVE,
        ]);
    }

    /**
     * Requirement 1: Single allocation normal flow, stage progression, defect, WIP, and KITIR badge.
     */
    public function test_01_single_allocation_normal_flow_and_kpi(): void
    {
        $plan = $this->createProductionPlan([
            'po_quantity' => 100,
            'qty_planned' => 100,
            'line_number' => 1,
        ]);

        $item = $this->createTransferWithSingleItem($plan, 100, 1, FittingItem::STAGE_QC_PF);

        // Record Netto defect = 2 PCS (CONFIRMED by QC)
        FittingStageExecution::create([
            'fitting_item_id' => $item->id,
            'stage' => FittingItem::STAGE_NETTO_PF,
            'checkpoint_code' => FittingStageExecution::CHECKPOINT_NETTO,
            'input_qty' => 100,
            'defect_qty' => 2,
            'good_qty' => 98,
            'status' => FittingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->spvNettoPf->id,
            'executed_at' => Carbon::now()->subHours(5),
            'qc_verified_at' => Carbon::now()->subHours(4),
            'qc_verified_by' => $this->adminQcFitting->id,
        ]);

        // Record Bubut CNC defect = 3 PCS (CONFIRMED by QC)
        FittingStageExecution::create([
            'fitting_item_id' => $item->id,
            'stage' => FittingItem::STAGE_BUBUT_CNC_PF,
            'checkpoint_code' => FittingStageExecution::CHECKPOINT_CNC,
            'input_qty' => 98,
            'defect_qty' => 3,
            'good_qty' => 95,
            'status' => FittingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->spvNettoPf->id,
            'executed_at' => Carbon::now()->subHours(2),
            'qc_verified_at' => Carbon::now()->subHours(1),
            'qc_verified_by' => $this->adminQcFitting->id,
        ]);

        $service = app(FittingProductionStatusService::class);
        $data = $service->getProductionStatusData(['tab' => 'active'], $this->ppicFitting);

        $this->assertEquals(1, $data['summary']['total_spk_aktif']);
        $this->assertEquals(100, $data['summary']['total_target_po']);
        $this->assertEquals(0, $data['summary']['total_selesai_gd']);
        $this->assertEquals(100, $data['summary']['total_sisa_target']);

        $row = collect($data['rows']->items())->firstWhere('production_plan_id', $plan->id);
        $this->assertNotNull($row);
        $this->assertEquals(100, $row['po_target']);
        $this->assertEquals(100, $row['kitir_transfer']);
        $this->assertEquals(2, $row['r_netto']);
        $this->assertEquals(3, $row['r_cnc']);
        $this->assertEquals(95, $row['wip_qc']); // Effective good entering QC
        $this->assertEquals(0, $row['wip_netto']);
        $this->assertEquals(0, $row['wip_cnc']);
        $this->assertEquals(0, $row['gd']);
        $this->assertEquals(100, $row['remaining_po']);
        $this->assertEquals('ACTIVE', $row['status']);

        // Available Good = 100 - (2 + 3) = 95 < 100 -> Badge KITIR
        $this->assertEquals(95, $row['available_good']);
        $this->assertEquals(5, $row['shortage']);
        $this->assertEquals('KITIR', $row['kitir_badge']);
    }

    /**
     * Requirement 2: Multi-allocation integer distribution via sequential mass balance.
     */
    public function test_02_multi_allocation_integer_distribution(): void
    {
        $planA = $this->createProductionPlan(['code' => 'SPK-MA-A', 'po_quantity' => 60, 'qty_planned' => 60]);
        $planB = $this->createProductionPlan(['code' => 'SPK-MA-B', 'po_quantity' => 40, 'qty_planned' => 40]);

        $n = self::$seq++;
        $res = LostWaxCastingResult::create([
            'heat_number' => "H-MA-{$n}",
            'cast_date' => '2026-11-20',
            'furnace' => '1',
            'shift' => '1',
            'operator_name' => 'Op MA',
            'total_trees' => 1,
            'total_pcs' => 100,
            'status' => 'COMPLETED',
            'recorded_by' => $this->ppicFitting->id,
        ]);

        $kitir = LostWaxKitir::create([
            'lost_wax_casting_result_id' => $res->id,
            'traveler_number' => "PFKTR-MA-{$n}",
            'production_code' => 'SPK-MA-A, SPK-MA-B',
            'quantity_pcs' => 100,
            'tree_count' => 1,
            'issued_at' => Carbon::now(),
            'issued_by' => $this->ppicFitting->id,
        ]);

        $transfer = FittingTransfer::create([
            'transfer_number' => "PFTRF-MA-{$n}",
            'transfer_date' => '2026-11-20',
            'transferred_by' => $this->ppicFitting->id,
            'total_kitirs' => 1,
            'total_pcs' => 100,
            'total_weight_kg' => 45.0,
            'status' => FittingTransfer::STATUS_ACTIVE,
        ]);

        $parentItem = FittingItem::create([
            'fitting_transfer_id' => $transfer->id,
            'lost_wax_kitir_id' => $kitir->id,
            'active_kitir_id' => $kitir->id,
            'lost_wax_casting_result_id' => $res->id,
            'production_plan_id' => null, // Multi-allocation has null plan_id
            'traveler_number' => $kitir->traveler_number,
            'heat_number' => $res->heat_number,
            'cast_date' => $res->cast_date,
            'production_code' => 'SPK-MA-A, SPK-MA-B',
            'item_code' => 'MA-COMBO',
            'item_name' => 'Multi Item',
            'customer' => 'Customer Multi',
            'line_number' => 2,
            'tree_count' => 1,
            'qty_transferred' => 100,
            'unit_weight_kg' => 0.45,
            'total_weight_kg' => 45.0,
            'is_multi_allocation' => true,
            'current_stage' => FittingItem::STAGE_QC_PF,
            'status' => FittingItem::STATUS_ACTIVE,
        ]);

        // 60% Plan A, 40% Plan B
        $allocA = FittingItemAllocation::create([
            'fitting_item_id' => $parentItem->id,
            'production_plan_id' => $planA->id,
            'production_code' => $planA->code,
            'item_code' => $planA->item_code,
            'item_name' => $planA->item_name,
            'customer' => $planA->customer,
            'line_number' => 2,
            'allocated_qty_transferred' => 60,
            'unit_weight_kg' => 0.45,
            'allocated_weight_kg' => 27.0,
        ]);

        $allocB = FittingItemAllocation::create([
            'fitting_item_id' => $parentItem->id,
            'production_plan_id' => $planB->id,
            'production_code' => $planB->code,
            'item_code' => $planB->item_code,
            'item_name' => $planB->item_name,
            'customer' => $planB->customer,
            'line_number' => 2,
            'allocated_qty_transferred' => 40,
            'unit_weight_kg' => 0.45,
            'allocated_weight_kg' => 18.0,
        ]);

        // Record physical defect = 10 PCS at Bubut CNC
        FittingStageExecution::create([
            'fitting_item_id' => $parentItem->id,
            'stage' => FittingItem::STAGE_BUBUT_CNC_PF,
            'checkpoint_code' => FittingStageExecution::CHECKPOINT_CNC,
            'input_qty' => 100,
            'defect_qty' => 10,
            'good_qty' => 90,
            'status' => FittingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->spvNettoPf->id,
            'executed_at' => Carbon::now()->subHours(2),
            'qc_verified_at' => Carbon::now()->subHours(1),
            'qc_verified_by' => $this->adminQcFitting->id,
        ]);

        $service = app(FittingProductionStatusService::class);
        $data = $service->getProductionStatusData(['tab' => 'active'], $this->ppicFitting);

        $rowA = collect($data['rows']->items())->firstWhere('production_plan_id', $planA->id);
        $rowB = collect($data['rows']->items())->firstWhere('production_plan_id', $planB->id);

        $this->assertNotNull($rowA);
        $this->assertNotNull($rowB);

        // Plan A: 60% of 10 defect = 6 PCS, WIP QC = 60 - 6 = 54 PCS
        $this->assertEquals(60, $rowA['kitir_transfer']);
        $this->assertEquals(6, $rowA['r_cnc']);
        $this->assertEquals(54, $rowA['wip_qc']);

        // Plan B: 40% of 10 defect = 4 PCS, WIP QC = 40 - 4 = 36 PCS
        $this->assertEquals(40, $rowB['kitir_transfer']);
        $this->assertEquals(4, $rowB['r_cnc']);
        $this->assertEquals(36, $rowB['wip_qc']);

        // Total check
        $this->assertEquals(10, $rowA['r_cnc'] + $rowB['r_cnc']);
        $this->assertEquals(90, $rowA['wip_qc'] + $rowB['wip_qc']);
    }

    /**
     * Requirement 3: Largest Remainder Method with odd defect and allocation.id ASC tie-breaker.
     */
    public function test_03_largest_remainder_with_odd_defect_and_tie_breaker(): void
    {
        // Direct algorithmic unit test of distributeCapConstrainedLargestRemainder
        // 50% vs 50%, defect = 3 PCS -> quotas 1.5 vs 1.5 -> remainder 0.5 vs 0.5
        // Tie-breaker: id 10 < id 20 -> ID 10 receives +1
        $entries = [
            ['id' => 10, 'weight' => 50.0, 'capacity' => 50],
            ['id' => 20, 'weight' => 50.0, 'capacity' => 50],
        ];

        $dist = FittingProductionStatusService::distributeCapConstrainedLargestRemainder(3, $entries);

        $this->assertEquals(2, $dist[10]);
        $this->assertEquals(1, $dist[20]);
        $this->assertEquals(3, $dist[10] + $dist[20]);

        // If ID order reversed: ID 5 < ID 8
        $entries2 = [
            ['id' => 8, 'weight' => 50.0, 'capacity' => 50],
            ['id' => 5, 'weight' => 50.0, 'capacity' => 50],
        ];
        $dist2 = FittingProductionStatusService::distributeCapConstrainedLargestRemainder(3, $entries2);
        $this->assertEquals(2, $dist2[5]);
        $this->assertEquals(1, $dist2[8]);
    }

    /**
     * Requirement 4: Cap-constrained redistribution when one SPK reaches its capacity limit.
     * Dedicated clean fixture (as instructed by user):
     * Capacity A = 1, Capacity B = 90. Defect to distribute = 20.
     * Initial proportional quota would assign A = 2, B = 18.
     * But A has only 1 capacity! So A is clamped to 1, and excess 1 spills over to B (B gets 19).
     */
    public function test_04_cap_constrained_redistribution_when_one_spk_hits_capacity(): void
    {
        $entries = [
            ['id' => 1, 'weight' => 10.0, 'capacity' => 1],
            ['id' => 2, 'weight' => 90.0, 'capacity' => 90],
        ];

        $dist = FittingProductionStatusService::distributeCapConstrainedLargestRemainder(20, $entries);

        // SPK A clamped to 1 (never exceeds 1, good will be exactly 0, never -1)
        $this->assertEquals(1, $dist[1]);
        // SPK B absorbs the overflow (18 + 1 = 19)
        $this->assertEquals(19, $dist[2]);
        $this->assertEquals(20, $dist[1] + $dist[2]);
    }

    /**
     * Requirement 5: Defect status treatment:
     * - WAITING_QC: reduces WIP but does NOT trigger KITIR badge (not verified).
     * - CONFIRMED: reduces WIP AND officially triggers KITIR badge.
     * - REOPENED_FOR_CORRECTION: does NOT trigger KITIR badge.
     * - STATUS_CANCELLED: completely excluded.
     */
    public function test_05_defect_status_treatment_waiting_qc_confirmed_reopened_cancelled(): void
    {
        $plan = $this->createProductionPlan(['po_quantity' => 100, 'qty_planned' => 100]);
        $item = $this->createTransferWithSingleItem($plan, 100, 1, FittingItem::STAGE_QC_PF);

        // Record defect = 10 PCS in Netto but status is WAITING_QC (unverified by QC)
        $exec = FittingStageExecution::create([
            'fitting_item_id' => $item->id,
            'stage' => FittingItem::STAGE_NETTO_PF,
            'checkpoint_code' => FittingStageExecution::CHECKPOINT_NETTO,
            'input_qty' => 100,
            'defect_qty' => 10,
            'good_qty' => 90,
            'status' => FittingStageExecution::STATUS_WAITING_QC,
            'operator_id' => $this->spvNettoPf->id,
            'executed_at' => Carbon::now()->subHours(2),
        ]);

        $service = app(FittingProductionStatusService::class);
        $dataMid = $service->getProductionStatusData(['tab' => 'active'], $this->ppicFitting);
        $rowMid = collect($dataMid['rows']->items())->firstWhere('production_plan_id', $plan->id);

        // WIP at QC reduced to 90
        $this->assertEquals(90, $rowMid['wip_qc']);
        // But Available Good remains 100 because defect is NOT YET confirmed by QC!
        $this->assertEquals(100, $rowMid['available_good']);
        $this->assertEquals(0, $rowMid['shortage']);
        $this->assertEmpty($rowMid['kitir_badge']);

        // Now Admin QC CONFIRMS the defect
        $exec->update([
            'status' => FittingStageExecution::STATUS_CONFIRMED,
            'qc_verified_at' => Carbon::now(),
            'qc_verified_by' => $this->adminQcFitting->id,
        ]);

        $dataConfirmed = $service->getProductionStatusData(['tab' => 'active'], $this->ppicFitting);
        $rowConfirmed = collect($dataConfirmed['rows']->items())->firstWhere('production_plan_id', $plan->id);

        // Now Available Good is officially 90 < 100 -> Shortage 10 -> Badge KITIR!
        $this->assertEquals(90, $rowConfirmed['available_good']);
        $this->assertEquals(10, $rowConfirmed['shortage']);
        $this->assertEquals('KITIR', $rowConfirmed['kitir_badge']);

        // Now QC REOPENS for correction (status REOPENED_FOR_CORRECTION)
        $exec->update([
            'status' => FittingStageExecution::STATUS_REOPENED_FOR_CORRECTION,
            'qc_verified_at' => null,
            'qc_verified_by' => null,
        ]);

        $dataReopened = $service->getProductionStatusData(['tab' => 'active'], $this->ppicFitting);
        $rowReopened = collect($dataReopened['rows']->items())->firstWhere('production_plan_id', $plan->id);

        // Once reopened, Available Good returns to 100 (unconfirmed), removing KITIR badge
        $this->assertEquals(100, $rowReopened['available_good']);
        $this->assertEmpty($rowReopened['kitir_badge']);

        // Cancelled traveler is completely excluded
        $item->update(['status' => FittingItem::STATUS_CANCELLED]);
        $dataCancelled = $service->getProductionStatusData(['tab' => 'all'], $this->ppicFitting);
        $rowCancelled = collect($dataCancelled['rows']->items())->firstWhere('production_plan_id', $plan->id);
        $this->assertNull($rowCancelled);
    }

    /**
     * Requirement 6: Traveler completed transitions status to SELESAI and moves to Completed tab.
     */
    public function test_06_traveler_completed_transitions_status_to_selesai(): void
    {
        $plan = $this->createProductionPlan(['po_quantity' => 50, 'qty_planned' => 50]);
        $item = $this->createTransferWithSingleItem($plan, 50, 1, FittingItem::STAGE_COMPLETED);

        // Gudang receive completed with 50 pcs good
        FittingStageExecution::create([
            'fitting_item_id' => $item->id,
            'stage' => FittingItem::STAGE_GUDANG_JADI_PF,
            'checkpoint_code' => FittingStageExecution::CHECKPOINT_GUDANG_JADI,
            'input_qty' => 50,
            'defect_qty' => 0,
            'good_qty' => 50,
            'status' => FittingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->spvNettoPf->id,
            'executed_at' => Carbon::now(),
        ]);

        $service = app(FittingProductionStatusService::class);

        // Check active tab -> should be empty
        $dataActive = $service->getProductionStatusData(['tab' => 'active'], $this->ppicFitting);
        $this->assertEmpty($dataActive['rows']->items());

        // Check completed tab -> should contain this plan
        $dataCompleted = $service->getProductionStatusData(['tab' => 'completed'], $this->ppicFitting);
        $rowDone = collect($dataCompleted['rows']->items())->firstWhere('production_plan_id', $plan->id);

        $this->assertNotNull($rowDone);
        $this->assertEquals('COMPLETED', $rowDone['status']);
        $this->assertEquals(50, $rowDone['gd']);
        $this->assertEquals(0, $rowDone['remaining_po']);
    }

    /**
     * Requirement 7: Non-SPK PFKTR isolation from SPK aggregation.
     */
    public function test_07_non_spk_isolation(): void
    {
        $n = self::$seq++;
        $res = LostWaxCastingResult::create([
            'heat_number' => "H-UNLINK-{$n}",
            'cast_date' => '2026-11-20',
            'furnace' => '1',
            'shift' => '1',
            'operator_name' => 'Op Unlink',
            'total_trees' => 1,
            'total_pcs' => 20,
            'status' => 'COMPLETED',
            'recorded_by' => $this->ppicFitting->id,
        ]);

        $kitir = LostWaxKitir::create([
            'lost_wax_casting_result_id' => $res->id,
            'traveler_number' => "PFKTR-UNLINK-{$n}",
            'production_code' => 'NO-SPK',
            'quantity_pcs' => 20,
            'tree_count' => 1,
            'issued_at' => Carbon::now(),
            'issued_by' => $this->ppicFitting->id,
        ]);

        $transfer = FittingTransfer::create([
            'transfer_number' => "PFTRF-UNLINK-{$n}",
            'transfer_date' => '2026-11-20',
            'transferred_by' => $this->ppicFitting->id,
            'total_kitirs' => 1,
            'total_pcs' => 20,
            'total_weight_kg' => 9.0,
            'status' => FittingTransfer::STATUS_ACTIVE,
        ]);

        $unassignedItem = FittingItem::create([
            'fitting_transfer_id' => $transfer->id,
            'lost_wax_kitir_id' => $kitir->id,
            'active_kitir_id' => $kitir->id,
            'lost_wax_casting_result_id' => $res->id,
            'production_plan_id' => null, // NO SPK
            'traveler_number' => $kitir->traveler_number,
            'heat_number' => $res->heat_number,
            'cast_date' => $res->cast_date,
            'production_code' => 'NO-SPK',
            'item_code' => 'UNLINKED',
            'item_name' => 'Unlinked Item',
            'customer' => 'No Customer',
            'line_number' => 1,
            'tree_count' => 1,
            'qty_transferred' => 20,
            'unit_weight_kg' => 0.45,
            'total_weight_kg' => 9.0,
            'is_multi_allocation' => false,
            'current_stage' => FittingItem::STAGE_NETTO_PF,
            'status' => FittingItem::STATUS_ACTIVE,
        ]);

        $service = app(FittingProductionStatusService::class);
        $data = $service->getProductionStatusData(['tab' => 'all'], $this->ppicFitting);

        // Does NOT appear in standard SPK table rows
        $this->assertEmpty($data['rows']->items());
        // Counter indicates 1 non_spk
        $this->assertEquals(1, $data['tab_counts']['non_spk']);
        // Appears in non_spk_rows collection
        $this->assertEquals(1, $data['non_spk_rows']->count());
        $this->assertEquals($unassignedItem->traveler_number, $data['non_spk_rows']->first()['traveler_number']);
    }

    /**
     * Requirement 8: Line filter, search, and date filter behavior.
     */
    public function test_08_line_filter_search_and_date_behavior(): void
    {
        $planL1 = $this->createProductionPlan(['code' => 'SPK-LINE-1', 'line_number' => 1, 'customer' => 'Customer A']);
        $this->createTransferWithSingleItem($planL1, 50, 1, FittingItem::STAGE_NETTO_PF, '2026-11-10');

        $planL2 = $this->createProductionPlan(['code' => 'SPK-LINE-2', 'line_number' => 2, 'customer' => 'Customer B']);
        $this->createTransferWithSingleItem($planL2, 40, 2, FittingItem::STAGE_NETTO_PF, '2026-11-20');

        $service = app(FittingProductionStatusService::class);

        // Filter by Line 1
        $dataLine1 = $service->getProductionStatusData(['line' => 1], $this->ppicFitting);
        $this->assertEquals(1, $dataLine1['rows']->total());
        $this->assertEquals('SPK-LINE-1', $dataLine1['rows']->first()['code']);

        // Search by Code
        $dataSearch = $service->getProductionStatusData(['search' => 'SPK-LINE-2'], $this->ppicFitting);
        $this->assertEquals(1, $dataSearch['rows']->total());
        $this->assertEquals('SPK-LINE-2', $dataSearch['rows']->first()['code']);

        // Date filter on transfer date range: 2026-11-15 to 2026-11-25 selects only Plan L2
        $dataDate = $service->getProductionStatusData([
            'date_from' => '2026-11-15',
            'date_to' => '2026-11-25',
            'date_basis' => 'transfer',
        ], $this->ppicFitting);
        $this->assertEquals(1, $dataDate['rows']->total());
        $this->assertEquals('SPK-LINE-2', $dataDate['rows']->first()['code']);
    }

    /**
     * Requirement 9: Details endpoint returns full PFKTR drilldown data.
     */
    public function test_09_details_endpoint_returns_accurate_drilldown(): void
    {
        $plan = $this->createProductionPlan(['code' => 'SPK-DRILL-01']);
        $item = $this->createTransferWithSingleItem($plan, 80, 1, FittingItem::STAGE_NETTO_PF);

        $response = $this->actingAs($this->ppicFitting)
            ->get(route('fitting.production-status.details', ['plan_id' => $plan->id]));

        $response->assertOk();
        $response->assertJsonPath('plan.code', 'SPK-DRILL-01');
        $response->assertJsonPath('items.0.traveler_number', $item->traveler_number);
        $response->assertJsonPath('items.0.qty_transferred', 80);
    }

    /**
     * Requirement 10: Export Excel endpoint returns XLSX stream download.
     */
    public function test_10_export_xlsx_stream_download(): void
    {
        $plan = $this->createProductionPlan();
        $this->createTransferWithSingleItem($plan, 30);

        $response = $this->actingAs($this->ppicFitting)
            ->get(route('fitting.production-status.export', ['tab' => 'active']));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('Fitting_Production_Status_', (string) $response->headers->get('content-disposition'));
    }

    /**
     * Requirement 11: RBAC authorization:
     * - Authorized: Admin, PPIC, Auditor, Sales.
     * - Unauthorized: Single-stage SPV Fitting receives 403.
     */
    public function test_11_rbac_authorization(): void
    {
        // Authorized users can access HTML index
        foreach ([$this->systemAdmin, $this->ppicFitting, $this->adminPpicPf, $this->auditor, $this->sales] as $authUser) {
            $resp = $this->actingAs($authUser)->get(route('fitting.production-status.index'));
            $resp->assertOk();
            $resp->assertSee('STATUS PRODUKSI');
        }

        // Single-stage SPV is forbidden
        $this->actingAs($this->spvNettoPf)->get(route('fitting.production-status.index'))->assertForbidden();
        $this->actingAs($this->spvNettoPf)->get(route('fitting.production-status.export'))->assertForbidden();
    }
}
