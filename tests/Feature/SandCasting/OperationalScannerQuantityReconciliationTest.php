<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use App\Services\SandCasting\SandCastingProductionFloorQueryService;
use App\Services\SandCasting\SandCastingQuantityResolverService;
use App\Services\SandCasting\SandCastingStageExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class OperationalScannerQuantityReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected SandCastingStageExecutionService $executionService;

    protected SandCastingProductionFloorQueryService $queryService;

    protected SandCastingQuantityResolverService $resolver;

    protected User $adminUser;

    protected User $spvUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new SandCastingQuantityResolverService;
        $this->executionService = new SandCastingStageExecutionService($this->resolver);
        $this->queryService = new SandCastingProductionFloorQueryService($this->executionService, $this->resolver);

        $this->adminUser = User::factory()->create([
            'name' => 'Admin PPIC',
            'email' => 'adminppic@peroniks.com',
        ]);

        $this->spvUser = User::factory()->create([
            'name' => 'SPV Bor',
            'email' => 'spvbor@peroniks.com',
            'assigned_stage' => 'bor',
        ]);
    }

    protected function createKtrLine(int $qtyGood = 40, ?string $currentStage = 'netto'): SandCastingCastingResultLine
    {
        $plan = ProductionPlan::create([
            'code' => 'PLAN-'.uniqid(),
            'po_number' => 'PO-'.uniqid(),
            'item_code' => 'ITM-001',
            'item_name' => 'Flange 2 Inch',
            'customer' => 'PT Test Customer',
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-'.uniqid().'-'.rand(1000, 9999),
            'scheduled_date' => now()->toDateString(),
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '2"',
            'aisi' => 'FC250',
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 250.00,
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'HEAT-'.uniqid(),
            'cast_date' => now()->toDateString(),
            'furnace' => 'F1',
            'shift' => '1',
            'recorded_by' => $this->adminUser->id,
        ]);

        return SandCastingCastingResultLine::create([
            'sand_casting_casting_result_id' => $result->id,
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-'.strtoupper(uniqid()),
            'qty_good' => $qtyGood,
            'qty_reject' => 0,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => $qtyGood * 2.50,
            'current_stage' => $currentStage,
        ]);
    }

    /**
     * SCANNER TEST 1: No defect -> BOR input = 40, New execution input_qty = 40.
     */
    public function test_01_scanner_no_defect_displays_40_and_records_input_40(): void
    {
        $ktr = $this->createKtrLine(40, 'netto');

        // Netto done (40)
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->adminUser->id);
        $ktr->refresh();

        // OD done (40)
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->adminUser->id);
        $ktr->refresh();

        // CNC done (40)
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_cnc', $this->adminUser->id);
        $ktr->refresh();

        $this->assertSame('bor', $ktr->current_stage);

        // Scanner lookup
        $scannerData = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertSame(40, $scannerData['current_input_qty']);
        $this->assertSame('BOR_DRILLING', $scannerData['active_checkpoint']);
        $this->assertSame('READY', $scannerData['operational_status']);

        // Mark physical done on BOR
        $borExec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bor', $this->spvUser->id);

        $this->assertSame(40, (int) $borExec->input_qty);
        $this->assertSame(40, (int) $borExec->good_qty);
        $this->assertSame(0, (int) $borExec->defect_qty);
        $this->assertSame(SandCastingStageExecution::STATUS_WAITING_DEFECT, $borExec->status);
    }

    /**
     * SCANNER TEST 2: NETTO defect = 5, BOR not yet executed -> Scanner = 35, New BOR input_qty = 35.
     */
    public function test_02_scanner_netto_defect_5_displays_35_and_records_input_35_at_bor(): void
    {
        $ktr = $this->createKtrLine(40, 'netto');

        // Netto done, defect 5 recorded
        $nettoExec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->adminUser->id);
        $this->executionService->recordDefectQty($nettoExec, 5, $this->adminUser->id);
        $ktr->refresh();

        // OD done (35)
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->adminUser->id);
        $ktr->refresh();

        // CNC done (35)
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_cnc', $this->adminUser->id);
        $ktr->refresh();

        $this->assertSame('bor', $ktr->current_stage);

        // Scanner lookup
        $scannerData = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertSame(35, $scannerData['current_input_qty']);

        // Mark physical done on BOR
        $borExec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bor', $this->spvUser->id);

        $this->assertSame(35, (int) $borExec->input_qty);
        $this->assertSame(35, (int) $borExec->good_qty);
    }

    /**
     * SCANNER TEST 3: NETTO defect = 5, CNC defect = 2 -> BOR scanner = 33, New BOR input_qty = 33.
     */
    public function test_03_scanner_netto_5_plus_cnc_2_displays_33_and_records_input_33_at_bor(): void
    {
        $ktr = $this->createKtrLine(40, 'netto');

        // Netto done, defect 5
        $nettoExec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->adminUser->id);
        $this->executionService->recordDefectQty($nettoExec, 5, $this->adminUser->id);
        $ktr->refresh();

        // OD done
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->adminUser->id);
        $ktr->refresh();

        // CNC done, defect 2
        $cncExec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_cnc', $this->adminUser->id);
        $this->executionService->recordDefectQty($cncExec, 2, $this->adminUser->id);
        $ktr->refresh();

        $this->assertSame('bor', $ktr->current_stage);

        // Scanner lookup
        $scannerData = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertSame(33, $scannerData['current_input_qty']);

        // Mark physical done on BOR
        $borExec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bor', $this->spvUser->id);

        $this->assertSame(33, (int) $borExec->input_qty);
        $this->assertSame(33, (int) $borExec->good_qty);
    }

    /**
     * SCANNER TEST 4: Historical BOR already input 40. Late NETTO defect = 5.
     * Historical input remains 40. Current scanner/operational quantity = 35.
     */
    public function test_04_scanner_late_netto_defect_reconciles_operational_quantity_while_historical_input_remains_40(): void
    {
        $ktr = $this->createKtrLine(40, 'netto');

        // Day 1: Fast physical flow Netto -> OD -> CNC -> BOR (all recorded with 40)
        $nettoExec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->adminUser->id);
        $odExec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->adminUser->id);
        $cncExec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_cnc', $this->adminUser->id);
        $borExec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bor', $this->spvUser->id);
        $ktr->refresh();

        $this->assertSame('qc', $ktr->current_stage);
        $this->assertSame(40, (int) $borExec->input_qty);

        // Day 3: Late defect recorded at Netto = 5
        $this->executionService->recordDefectQty($nettoExec, 5, $this->adminUser->id);

        // Historical BOR execution remains 40
        $borExec->refresh();
        $this->assertSame(40, (int) $borExec->input_qty);
        $this->assertSame(40, (int) $borExec->good_qty);

        // Historical OD and CNC execution also remain 40
        $odExec->refresh();
        $cncExec->refresh();
        $this->assertSame(40, (int) $odExec->input_qty);
        $this->assertSame(40, (int) $cncExec->input_qty);

        // Operational quantity for next stage (QC) is reconciled dynamically to 35
        $scannerData = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertSame(35, $scannerData['current_input_qty']);
        $this->assertSame('QC_FINAL_INSPECTION', $scannerData['active_checkpoint']);
    }

    /**
     * SCANNER TEST 5: Late NETTO 5 + CNC 2 after historical physical BOR done.
     * Historical BOR input remains 40. Operational quantity for QC = 33.
     */
    public function test_05_scanner_late_netto_5_plus_cnc_2_reconciles_operational_quantity_while_historical_input_remains_40(): void
    {
        $ktr = $this->createKtrLine(40, 'netto');

        $nettoExec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->adminUser->id);
        $odExec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->adminUser->id);
        $cncExec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_cnc', $this->adminUser->id);
        $borExec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bor', $this->spvUser->id);
        $ktr->refresh();

        // Late defect 1: Netto defect = 5
        $this->executionService->recordDefectQty($nettoExec, 5, $this->adminUser->id);

        // Late defect 2: CNC defect = 2
        $this->executionService->recordDefectQty($cncExec, 2, $this->adminUser->id);

        // Historical snapshots remain 40
        $borExec->refresh();
        $this->assertSame(40, (int) $borExec->input_qty);
        $odExec->refresh();
        $this->assertSame(40, (int) $odExec->input_qty);

        // Current operational quantity for QC scanner is 33 (40 - 5 - 2)
        $scannerData = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertSame(33, $scannerData['current_input_qty']);
    }

    /**
     * SCANNER TEST 6: Current stage never moves backward because of late defect.
     */
    public function test_06_scanner_current_stage_never_moves_backward_because_of_late_defect(): void
    {
        $ktr = $this->createKtrLine(40, 'netto');

        $nettoExec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->adminUser->id);
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->adminUser->id);
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_cnc', $this->adminUser->id);
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bor', $this->spvUser->id);
        $ktr->refresh();

        $this->assertSame('qc', $ktr->current_stage);

        // Admin records Netto defect
        $this->executionService->recordDefectQty($nettoExec, 5, $this->adminUser->id);
        $ktr->refresh();

        // Stage remains 'qc', does not rewind
        $this->assertSame('qc', $ktr->current_stage);
    }

    /**
     * SCANNER TEST 7: Duplicate scan protection remains.
     */
    public function test_07_scanner_duplicate_scan_protection_remains(): void
    {
        $ktr = $this->createKtrLine(40, 'netto');

        $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->adminUser->id);
        $ktr->refresh();

        $this->assertSame('bubut_od', $ktr->current_stage);

        // First scan at OD succeeds
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->adminUser->id);
        $ktr->refresh();

        // Duplicate scan on OD must be rejected because KTR already advanced to bubut_cnc
        $this->expectException(InvalidArgumentException::class);
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->adminUser->id);
    }

    /**
     * SCANNER TEST 8: Checkpoint CNC single checkpoint execution remains correct.
     */
    public function test_08_scanner_checkpoint_cnc_regression_sequence_and_quantity(): void
    {
        $ktr = $this->createKtrLine(40, 'netto');

        $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->adminUser->id);
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->adminUser->id);
        $ktr->refresh();

        $this->assertSame('bubut_cnc', $ktr->current_stage);

        // CNC Machining execution
        $cncExec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_cnc', $this->adminUser->id);
        $ktr->refresh();

        $this->assertSame('CNC_MACHINING', $cncExec->checkpoint_code);
        $this->assertSame(40, (int) $cncExec->input_qty);
        // Stage immediately advances to bor
        $this->assertSame('bor', $ktr->current_stage);
    }

    /**
     * SCANNER TEST 9: OD regression remains correct.
     */
    public function test_09_scanner_od_regression_remains_correct(): void
    {
        $ktr = $this->createKtrLine(40, 'netto');

        $nettoExec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->adminUser->id);
        $this->executionService->recordDefectQty($nettoExec, 4, $this->adminUser->id);
        $ktr->refresh();

        $this->assertSame('bubut_od', $ktr->current_stage);

        // OD Scanner lookup
        $scannerData = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertSame(36, $scannerData['current_input_qty']);
        $this->assertSame('OD_TURNING', $scannerData['active_checkpoint']);

        // Execute OD
        $odExec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->adminUser->id);
        $this->assertSame(36, (int) $odExec->input_qty);
    }

    /**
     * SCANNER TEST 10: BOR regression remains correct.
     */
    public function test_10_scanner_bor_regression_remains_correct(): void
    {
        $ktr = $this->createKtrLine(40, 'netto');

        $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->adminUser->id);
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->adminUser->id);
        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_cnc', $this->adminUser->id);
        $ktr->refresh();

        $this->assertSame('bor', $ktr->current_stage);

        $borExec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bor', $this->spvUser->id);
        $ktr->refresh();

        $this->assertSame('BOR_DRILLING', $borExec->checkpoint_code);
        $this->assertSame(40, (int) $borExec->input_qty);
        $this->assertSame('qc', $ktr->current_stage);
    }

    /**
     * SCANNER TEST 11: Physical completion still advances current_stage correctly.
     */
    public function test_11_scanner_physical_completion_advances_current_stage_correctly(): void
    {
        $ktr = $this->createKtrLine(40, 'netto');
        $this->assertSame('netto', $ktr->current_stage);

        $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->adminUser->id);
        $ktr->refresh();
        $this->assertSame('bubut_od', $ktr->current_stage);

        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->adminUser->id);
        $ktr->refresh();
        $this->assertSame('bubut_cnc', $ktr->current_stage);

        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_cnc', $this->adminUser->id);
        $ktr->refresh();
        $this->assertSame('bor', $ktr->current_stage);

        $this->executionService->markPhysicalDone($ktr->traveler_number, 'bor', $this->spvUser->id);
        $ktr->refresh();
        $this->assertSame('qc', $ktr->current_stage);

        $this->executionService->markPhysicalDone($ktr->traveler_number, 'qc', $this->adminUser->id);
        $ktr->refresh();
        $this->assertSame('gudang_jadi', $ktr->current_stage);

        $this->executionService->markPhysicalDone($ktr->traveler_number, 'gudang_jadi', $this->adminUser->id);
        $ktr->refresh();
        $this->assertSame('completed', $ktr->current_stage);
    }

    /**
     * SCANNER TEST 12: Multiple KTR isolation.
     */
    public function test_12_scanner_multiple_ktr_isolation(): void
    {
        $ktrA = $this->createKtrLine(40, 'netto');
        $ktrB = $this->createKtrLine(50, 'netto');

        // KTR A has Netto defect = 5
        $nettoExecA = $this->executionService->markPhysicalDone($ktrA->traveler_number, 'netto', $this->adminUser->id);
        $this->executionService->recordDefectQty($nettoExecA, 5, $this->adminUser->id);
        $ktrA->refresh();

        // KTR B has no defect
        $this->executionService->markPhysicalDone($ktrB->traveler_number, 'netto', $this->adminUser->id);
        $ktrB->refresh();

        // KTR A on OD scanner: 35
        $scannerA = $this->queryService->findByTraveler($ktrA->traveler_number);
        $this->assertSame(35, $scannerA['current_input_qty']);

        // KTR B on OD scanner: 50
        $scannerB = $this->queryService->findByTraveler($ktrB->traveler_number);
        $this->assertSame(50, $scannerB['current_input_qty']);

        // Execute OD for both
        $odExecA = $this->executionService->markPhysicalDone($ktrA->traveler_number, 'bubut_od', $this->adminUser->id);
        $odExecB = $this->executionService->markPhysicalDone($ktrB->traveler_number, 'bubut_od', $this->adminUser->id);

        $this->assertSame(35, (int) $odExecA->input_qty);
        $this->assertSame(50, (int) $odExecB->input_qty);
    }
}
