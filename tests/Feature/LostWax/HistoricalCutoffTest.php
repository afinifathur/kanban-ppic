<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxCastingResult;
use App\Models\LostWaxCastingResultTree;
use App\Models\LostWaxHistoricalCutoff;
use App\Models\LostWaxHistoricalCutoffTree;
use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxScanEventVoid;
use App\Models\LostWaxTree;
use App\Models\LostWaxWorkOrder;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWax\HistoricalCutoffService;
use App\Services\PrintExecutionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoricalCutoffTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected HistoricalCutoffService $cutoffService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'name' => 'PPIC Admin',
            'email' => 'ppic@peroniks.com',
        ]);

        $this->cutoffService = app(HistoricalCutoffService::class);
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'code' => '268ETB827',
            'customer' => 'PT PERONI INTI',
            'item_code' => '4.101105K.A0020',
            'item_name' => 'SS304 SQUARE DN 40',
            'aisi' => '304',
            'size' => 'DN 40',
            'weight' => 1.25,
            'po_number' => 'PO-2026-001',
            'po_quantity' => 100,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'status' => 'planning',
            'is_closed' => false,
        ], $attributes));
    }

    protected function createPrintOrderWithLine(ProductionPlan $plan, int $good = 100): LostWaxPrintOrder
    {
        static $poSeq = 0;
        $poSeq++;

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-'.str_pad((string) $poSeq, 4, '0', STR_PAD_LEFT),
            'scheduled_date' => '2026-08-28',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => $good,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ]);

        $printService = app(PrintExecutionService::class);
        $printService->record($line, [
            'qty_good' => $good,
            'qty_defect' => 0,
            'execution_date' => '2026-08-28',
            'status' => 'FINALIZED',
            'recorded_by' => $this->user->id,
        ]);

        return $order;
    }

    protected function createLegacyWorkOrder(array $attributes = []): LostWaxWorkOrder
    {
        $itemRef = \App\Models\LostWaxItemReference::firstOrCreate(
            ['master_item_key' => 'LY026'],
            [
                'item_code_snapshot' => 'LY026',
                'item_name_snapshot' => 'Hex Nipple SUS304',
                'aisi_snapshot' => '304',
            ]
        );

        return LostWaxWorkOrder::create(array_merge([
            'et_code' => 'ET26-0100',
            'item_reference_id' => $itemRef->id,
            'po_number' => 'PO-LEG-001',
            'customer_name' => 'PT CUSTOMER LEGACY',
            'po_quantity' => 100,
            'stock_quantity' => 0,
            'net_requirement_quantity' => 100,
            'status' => 'released',
            'production_date' => '2026-08-01',
            'created_by' => $this->user->id,
        ], $attributes));
    }

    protected function createTreeForLine($line, int $quantity = 25, ?string $currentStage = 'oven', $productionDate = '2026-08-28'): LostWaxTree
    {
        static $seq = 0;
        $seq++;

        return LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => '3'.Carbon::parse($productionDate)->format('dmy').str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'tree_number' => $seq,
            'quantity' => $quantity,
            'status' => 'generated',
            'current_stage' => $currentStage,
            'production_date' => $productionDate,
            'family_code' => '3',
            'daily_sequence' => $seq,
        ]);
    }

    protected function createTreeForWorkOrder(LostWaxWorkOrder $wo, int $quantity = 25, ?string $currentStage = 'oven', $productionDate = '2026-08-01'): LostWaxTree
    {
        static $seq = 500;
        $seq++;

        return LostWaxTree::create([
            'work_order_id' => $wo->id,
            'barcode' => '3'.Carbon::parse($productionDate)->format('dmy').str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'tree_number' => $seq,
            'quantity' => $quantity,
            'status' => 'generated',
            'current_stage' => $currentStage,
            'production_date' => $productionDate,
            'family_code' => '3',
            'daily_sequence' => $seq,
        ]);
    }

    protected function createOvenScanEvent(LostWaxTree $tree, string $scannedAt = '2026-08-10 10:00:00'): LostWaxScanEvent
    {
        return LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'oven',
            'scanned_at' => $scannedAt,
            'operator_id' => $this->user->id,
            'result' => 'success',
            'source' => 'physical_scan',
        ]);
    }

    /**
     * 1. Test Candidate Discovery: Barcode age alone does NOT qualify without valid Oven scan > 30 days.
     * - Tree A: Barcode age 60 days, NO oven scan -> NOT candidate.
     * - Tree B: Barcode age 60 days, Oven scan exactly 30 days ago (<= 30 days) -> NOT candidate.
     * - Tree C: Barcode age 20 days (young), Oven scan 31 days ago (> 30 days) -> CANDIDATE.
     * - Tree D: Barcode age 60 days, Oven scan 31 days ago (> 30 days) -> CANDIDATE.
     */
    public function test_candidate_barcode_age_does_not_qualify_without_oven_scan_over_30_days(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');

        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithLine($plan);
        $line = $order->lines()->first();

        // Tree A: Barcode age 60 days (2026-09-17), NO oven scan
        $treeOldNoOven = $this->createTreeForLine($line, 20, 'layer_3', '2026-09-17');

        // Tree B: Barcode age 60 days (2026-09-17), Oven scan exactly 30 days ago (2026-10-17 10:00:00)
        $treeOldOven30 = $this->createTreeForLine($line, 20, 'siap_cor', '2026-09-17');
        $this->createOvenScanEvent($treeOldOven30, '2026-10-17 10:00:00');

        // Tree C: Barcode age 20 days (2026-10-27), Oven scan 31 days ago (2026-10-16 10:00:00)
        $treeYoungOven31 = $this->createTreeForLine($line, 20, 'siap_cor', '2026-10-27');
        $this->createOvenScanEvent($treeYoungOven31, '2026-10-16 10:00:00');

        // Tree D: Barcode age 60 days, Oven scan 31 days ago (2026-10-16 10:00:00)
        $treeOldOven31 = $this->createTreeForLine($line, 20, 'siap_cor', '2026-09-17');
        $this->createOvenScanEvent($treeOldOven31, '2026-10-16 10:00:00');

        $candidates = $this->cutoffService->getCandidates();
        $candidateIds = collect($candidates)->pluck('tree_id')->all();

        $this->assertNotContains($treeOldNoOven->id, $candidateIds, 'Pohon umur >50 hari tanpa scan oven tidak boleh lolos.');
        $this->assertNotContains($treeOldOven30->id, $candidateIds, 'Pohon umur >50 hari dengan scan oven <=30 hari tidak boleh lolos.');
        $this->assertContains($treeYoungOven31->id, $candidateIds, 'Pohon dengan scan oven 31 hari harus lolos meski umur barcode muda.');
        $this->assertContains($treeOldOven31->id, $candidateIds, 'Pohon dengan scan oven 31 hari harus lolos.');
    }

    /**
     * 2. Test Candidate Discovery Boundary for Oven Scan Age (30 days vs 31 days) & Void handling.
     */
    public function test_candidate_oven_scan_age_boundary_and_void(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');

        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithLine($plan);
        $line = $order->lines()->first();

        // Young tree (barcode age 20 days: 2026-10-27)
        // Tree A: Oven scan 30 days ago (2026-10-17) -> NOT candidate
        $tree30 = $this->createTreeForLine($line, 20, 'siap_cor', '2026-10-27');
        LostWaxScanEvent::create([
            'tree_id' => $tree30->id,
            'barcode' => $tree30->barcode,
            'stage' => 'oven',
            'scanned_at' => '2026-10-17 10:00:00',
            'operator_id' => $this->user->id,
            'result' => 'success',
            'source' => 'physical_scan',
        ]);

        // Tree B: Oven scan 31 days ago (2026-10-16) -> CANDIDATE
        $tree31 = $this->createTreeForLine($line, 20, 'siap_cor', '2026-10-27');
        LostWaxScanEvent::create([
            'tree_id' => $tree31->id,
            'barcode' => $tree31->barcode,
            'stage' => 'oven',
            'scanned_at' => '2026-10-16 10:00:00',
            'operator_id' => $this->user->id,
            'result' => 'success',
            'source' => 'physical_scan',
        ]);

        // Tree C: Oven scan 35 days ago BUT voided -> NOT candidate
        $treeVoid = $this->createTreeForLine($line, 20, 'siap_cor', '2026-10-27');
        $voidEvent = LostWaxScanEvent::create([
            'tree_id' => $treeVoid->id,
            'barcode' => $treeVoid->barcode,
            'stage' => 'oven',
            'scanned_at' => '2026-10-12 10:00:00',
            'operator_id' => $this->user->id,
            'result' => 'success',
            'source' => 'physical_scan',
        ]);
        LostWaxScanEventVoid::create([
            'scan_event_id' => $voidEvent->id,
            'tree_id' => $treeVoid->id,
            'voided_by' => $this->user->id,
            'voided_at' => '2026-10-12 12:00:00',
            'void_reason' => 'Salah scan',
        ]);

        $candidates = $this->cutoffService->getCandidates();
        $candidateIds = collect($candidates)->pluck('tree_id')->all();

        $this->assertNotContains($tree30->id, $candidateIds, 'Pohon dengan oven scan 30 hari tidak boleh lolos.');
        $this->assertContains($tree31->id, $candidateIds, 'Pohon dengan oven scan 31 hari harus lolos.');
        $this->assertNotContains($treeVoid->id, $candidateIds, 'Pohon dengan oven scan yang di-void tidak boleh lolos.');
    }

    /**
     * 3. Milestone calculation priority for Siap Cor:
     * - Physical scan valid -> scanned_at
     * - Historical reconciliation (12-11-2026) -> IGNORED -> falls back to Oven + 1 day
     * - No Siap Cor scan, valid Oven scan -> Oven + 1 day
     * - No valid Oven scan -> Production date + 6 days
     */
    public function test_siap_cor_milestone_priorities(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');

        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithLine($plan);
        $line = $order->lines()->first();

        // Tree 1: Has valid physical scan for siap_cor
        $tree1 = $this->createTreeForLine($line, 20, 'siap_cor', '2026-08-01');
        LostWaxScanEvent::create([
            'tree_id' => $tree1->id,
            'barcode' => $tree1->barcode,
            'stage' => 'oven',
            'scanned_at' => '2026-08-10 10:00:00',
            'operator_id' => $this->user->id,
            'result' => 'success',
            'source' => 'physical_scan',
        ]);
        LostWaxScanEvent::create([
            'tree_id' => $tree1->id,
            'barcode' => $tree1->barcode,
            'stage' => 'siap_cor',
            'scanned_at' => '2026-08-11 14:30:00',
            'operator_id' => $this->user->id,
            'result' => 'success',
            'source' => 'physical_scan',
        ]);

        // Tree 2: Has historical_reconciliation scan on 12-11-2026 -> must be ignored!
        $tree2 = $this->createTreeForLine($line, 20, 'siap_cor', '2026-08-01');
        LostWaxScanEvent::create([
            'tree_id' => $tree2->id,
            'barcode' => $tree2->barcode,
            'stage' => 'oven',
            'scanned_at' => '2026-08-10 10:00:00',
            'operator_id' => $this->user->id,
            'result' => 'success',
            'source' => 'physical_scan',
        ]);
        LostWaxScanEvent::create([
            'tree_id' => $tree2->id,
            'barcode' => $tree2->barcode,
            'stage' => 'siap_cor',
            'scanned_at' => '2026-11-12 15:52:23',
            'operator_id' => $this->user->id,
            'result' => 'success',
            'source' => 'historical_reconciliation',
        ]);

        // Tree 3: No siap_cor scan, but has valid oven scan
        $tree3 = $this->createTreeForLine($line, 20, 'oven', '2026-08-01');
        LostWaxScanEvent::create([
            'tree_id' => $tree3->id,
            'barcode' => $tree3->barcode,
            'stage' => 'oven',
            'scanned_at' => '2026-08-15 09:00:00',
            'operator_id' => $this->user->id,
            'result' => 'success',
            'source' => 'physical_scan',
        ]);

        // Tree 4: No siap_cor scan and no oven scan
        $tree4 = $this->createTreeForLine($line, 20, 'layer_2', '2026-08-01');

        $candidates = collect($this->cutoffService->getCandidates())->keyBy('tree_id');

        // Verify Tree 1
        $c1 = $candidates[$tree1->id];
        $this->assertEquals('PHYSICAL_SCAN', $c1['siap_cor_milestone_source']);
        $this->assertEquals('11/08/2026', $c1['siap_cor_milestone_date']);

        // Verify Tree 2
        $c2 = $candidates[$tree2->id];
        $this->assertEquals('OVEN_ESTIMATE', $c2['siap_cor_milestone_source'], 'Rekonsiliasi historis harus diabaikan dan jatuh ke OVEN_ESTIMATE');
        $this->assertEquals('11/08/2026', $c2['siap_cor_milestone_date'], 'Oven 10/08 + 1 hari = 11/08');

        // Verify Tree 3
        $c3 = $candidates[$tree3->id];
        $this->assertEquals('OVEN_ESTIMATE', $c3['siap_cor_milestone_source']);
        $this->assertEquals('16/08/2026', $c3['siap_cor_milestone_date'], 'Oven 15/08 + 1 hari = 16/08');

        // Verify Tree 4 (Direct milestone calculation priority test for trees without oven scan)
        $m4 = $this->cutoffService->calculateMilestoneDates($tree4);
        $this->assertEquals('PRODUCTION_DATE_ESTIMATE', $m4['siap_cor_date_source']);
        $this->assertEquals('2026-08-07 00:00:00', $m4['siap_cor_date'], 'Prod 01/08 + 6 hari = 07/08');
    }

    /**
     * 4. Milestone calculation priority for Cor:
     * - Valid Oven scan -> Oven + 2 days (OVEN_ESTIMATE)
     * - No valid Oven scan -> Production date + 7 days (PRODUCTION_DATE_ESTIMATE)
     */
    public function test_cor_milestone_priorities(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');

        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithLine($plan);
        $line = $order->lines()->first();

        // Tree with Oven scan: 2026-08-10
        $treeOven = $this->createTreeForLine($line, 20, 'siap_cor', '2026-08-01');
        $this->createOvenScanEvent($treeOven, '2026-08-10 10:00:00');

        // Tree without Oven scan: Prod date 2026-08-01
        $treeNoOven = $this->createTreeForLine($line, 20, 'layer_1', '2026-08-01');

        $candidates = collect($this->cutoffService->getCandidates())->keyBy('tree_id');

        $this->assertEquals('OVEN_ESTIMATE', $candidates[$treeOven->id]['cor_milestone_source']);
        $this->assertEquals('12/08/2026', $candidates[$treeOven->id]['cor_milestone_date'], 'Oven 10/08 + 2 hari = 12/08');

        $mNoOven = $this->cutoffService->calculateMilestoneDates($treeNoOven);
        $this->assertEquals('PRODUCTION_DATE_ESTIMATE', $mNoOven['cor_date_source']);
        $this->assertEquals('2026-08-08', $mNoOven['cor_date'], 'Prod 01/08 + 7 hari = 08/08');
    }

    /**
     * 5. Tree Exclusions:
     * - Status cancelled -> excluded
     * - Cor Verified active -> excluded
     * - Active Historical Cut-off -> excluded
     */
    public function test_candidate_exclusions(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');

        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithLine($plan);
        $line = $order->lines()->first();

        // 1. Cancelled tree
        $treeCancelled = $this->createTreeForLine($line, 20, 'oven', '2026-08-01');
        $treeCancelled->update(['status' => 'cancelled']);
        $this->createOvenScanEvent($treeCancelled, '2026-08-10 10:00:00');

        // 2. Cor Verified tree (active casting result tree with COMPLETED result)
        $treeCor = $this->createTreeForLine($line, 20, 'cor', '2026-08-01');
        $this->createOvenScanEvent($treeCor, '2026-08-10 10:00:00');
        $castingResult = LostWaxCastingResult::create([
            'heat_number' => 'HN-1234',
            'cast_date' => '2026-08-15',
            'furnace' => 'F1',
            'shift' => '1',
            'total_trees' => 1,
            'total_pcs' => 20,
            'status' => 'COMPLETED',
            'recorded_by' => $this->user->id,
        ]);
        LostWaxCastingResultTree::create([
            'lost_wax_casting_result_id' => $castingResult->id,
            'lost_wax_tree_id' => $treeCor->id,
            'active_tree_id' => $treeCor->id,
            'barcode' => $treeCor->barcode,
            'production_code' => $line->code,
            'quantity_pcs' => 20,
        ]);

        // 3. Tree already cutoff
        $treeCutoff = $this->createTreeForLine($line, 20, 'siap_cor', '2026-08-01');
        $this->createOvenScanEvent($treeCutoff, '2026-08-10 10:00:00');
        $cutoff = LostWaxHistoricalCutoff::create([
            'cutoff_number' => 'HCO-20261116-0001',
            'cutoff_date' => now(),
            'total_trees' => 1,
            'total_quantity' => 20,
            'total_defect_quantity' => 0,
            'total_usable_quantity' => 20,
            'reason' => 'Existing cutoff',
            'status' => 'ACTIVE',
            'executed_by' => $this->user->id,
        ]);
        LostWaxHistoricalCutoffTree::create([
            'lost_wax_historical_cutoff_id' => $cutoff->id,
            'lost_wax_tree_id' => $treeCutoff->id,
            'active_tree_id' => $treeCutoff->id,
            'barcode' => $treeCutoff->barcode,
            'production_code' => $line->code,
            'previous_stage' => 'siap_cor',
            'previous_status' => 'generated',
            'quantity' => 20,
            'defect_quantity' => 0,
            'usable_quantity' => 20,
            'siap_cor_date' => '2026-08-07',
            'siap_cor_date_source' => 'PRODUCTION_DATE_ESTIMATE',
            'cor_date' => '2026-08-08',
            'cor_date_source' => 'PRODUCTION_DATE_ESTIMATE',
        ]);
        $treeCutoff->update(['status' => 'historical_cutoff', 'current_stage' => 'historical_cutoff']);

        // 4. Regular valid candidate
        $treeValid = $this->createTreeForLine($line, 20, 'siap_cor', '2026-08-01');
        $this->createOvenScanEvent($treeValid, '2026-08-10 10:00:00');

        $candidates = collect($this->cutoffService->getCandidates())->pluck('tree_id')->all();

        $this->assertNotContains($treeCancelled->id, $candidates, 'Cancelled tree must be excluded.');
        $this->assertNotContains($treeCor->id, $candidates, 'Cor Verified tree must be excluded.');
        $this->assertNotContains($treeCutoff->id, $candidates, 'Already cutoff tree must be excluded.');
        $this->assertContains($treeValid->id, $candidates, 'Valid tree must be included.');
    }

    /**
     * 6. Execution in DB transaction creates header + detail, updates tree status and stage, prevents double execution.
     */
    public function test_execution_in_db_transaction_and_idempotency(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');

        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithLine($plan);
        $line = $order->lines()->first();

        $tree1 = $this->createTreeForLine($line, 25, 'siap_cor', '2026-08-01');
        $tree2 = $this->createTreeForLine($line, 25, 'oven', '2026-08-01');

        $result = $this->cutoffService->executeCutoff([$tree1->id, $tree2->id], $this->user->id, 'Batch cutoff test', 'Catatan test');

        $this->assertNotNull($result);
        $this->assertDatabaseHas('lost_wax_historical_cutoffs', [
            'id' => $result->id,
            'total_trees' => 2,
            'total_quantity' => 50,
            'total_usable_quantity' => 50,
            'reason' => 'Batch cutoff test',
        ]);

        $this->assertDatabaseHas('lost_wax_historical_cutoff_trees', [
            'lost_wax_historical_cutoff_id' => $result->id,
            'lost_wax_tree_id' => $tree1->id,
            'active_tree_id' => $tree1->id,
            'previous_stage' => 'siap_cor',
        ]);

        $this->assertDatabaseHas('lost_wax_historical_cutoff_trees', [
            'lost_wax_historical_cutoff_id' => $result->id,
            'lost_wax_tree_id' => $tree2->id,
            'active_tree_id' => $tree2->id,
            'previous_stage' => 'oven',
        ]);

        // Refresh trees
        $tree1->refresh();
        $tree2->refresh();
        $this->assertEquals('historical_cutoff', $tree1->status);
        $this->assertEquals('historical_cutoff', $tree1->current_stage);
        $this->assertTrue($tree1->is_historical_cutoff);

        // Attempt double execution on same trees must fail
        $this->expectException(\InvalidArgumentException::class);
        $this->cutoffService->executeCutoff([$tree1->id], $this->user->id, 'Double cutoff');
    }

    /**
     * 7. Production Status Integration:
     * - Cutoff trees removed from floor buckets (layer_1..7=0, oven=0, siap_cor=0)
     * - Cutoff trees do not increase Cor actual
     * - Cutoff trees count in Total
     * - Status becomes COMPLETED (HISTORICAL CUTOFF) when all trees cutoff
     * - Status remains ACTIVE when some trees cutoff but others remain active
     */
    public function test_production_status_integration_and_status_determination(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');
        $this->actingAs($this->user);

        $plan = $this->createPlan(['qty_planned' => 50, 'po_quantity' => 50]);
        $order = $this->createPrintOrderWithLine($plan, 50);
        $line = $order->lines()->first();

        // Two trees: 25 pcs each
        $treeA = $this->createTreeForLine($line, 25, 'siap_cor', '2026-08-01');
        $treeB = $this->createTreeForLine($line, 25, 'oven', '2026-08-01');

        $controller = app(\App\Http\Controllers\LostWax\ProductionStatusController::class);

        // Before cutoff: Both trees in floor buckets (siap_cor=25, oven=25, total=50, status=ACTIVE)
        $refMethod = new \ReflectionMethod($controller, 'getAggregatedRows');
        $refMethod->setAccessible(true);
        $rowsBefore = $refMethod->invoke($controller);
        $rowBefore = collect($rowsBefore)->firstWhere('code', $plan->code);

        $this->assertEquals(25, $rowBefore['siap_cor_qty']);
        $this->assertEquals(25, $rowBefore['oven_qty']);
        $this->assertEquals(0, $rowBefore['cor_qty']);
        $this->assertEquals(50, $rowBefore['total_lap']);
        $this->assertEquals('ACTIVE', $rowBefore['status']);

        // Partially cut off treeA only (25 pcs)
        $this->cutoffService->executeCutoff([$treeA->id], $this->user->id, 'Partial cutoff');

        $rowsPartial = $refMethod->invoke($controller);
        $rowPartial = collect($rowsPartial)->firstWhere('code', $plan->code);

        $this->assertEquals(0, $rowPartial['siap_cor_qty'], 'Tree A should be removed from siap_cor');
        $this->assertEquals(25, $rowPartial['oven_qty'], 'Tree B remains in oven');
        $this->assertEquals(0, $rowPartial['cor_qty'], 'Cutoff does NOT increase Cor actual');
        $this->assertEquals(50, $rowPartial['total_lap'], 'Total must still include cutoff tree: 25 active + 25 cutoff = 50');
        $this->assertEquals('ACTIVE', $rowPartial['status'], 'Status must remain ACTIVE because Tree B is still in oven');

        // Now cut off treeB as well
        $this->cutoffService->executeCutoff([$treeB->id], $this->user->id, 'Final cutoff');

        $rowsFinal = $refMethod->invoke($controller);
        $rowFinal = collect($rowsFinal)->firstWhere('code', $plan->code);

        $this->assertEquals(0, $rowFinal['siap_cor_qty']);
        $this->assertEquals(0, $rowFinal['oven_qty']);
        $this->assertEquals(0, $rowFinal['cor_qty'], 'Cor actual remains 0');
        $this->assertEquals(50, $rowFinal['total_lap'], 'Total remains 50');
        $this->assertEquals('COMPLETED (HISTORICAL CUTOFF)', $rowFinal['status'], 'Status becomes COMPLETED (HISTORICAL CUTOFF)');
    }

    /**
     * 8. Parity between Legacy Work Order and New Flow:
     * Candidate discovery, execution, and Production Status aggregation work identically on Legacy.
     */
    public function test_legacy_work_order_parity(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');
        $this->actingAs($this->user);

        $wo = $this->createLegacyWorkOrder(['et_code' => 'ET26-PARITY', 'po_quantity' => 50]);
        $treeLeg1 = $this->createTreeForWorkOrder($wo, 25, 'siap_cor', '2026-08-01');
        $treeLeg2 = $this->createTreeForWorkOrder($wo, 25, 'oven', '2026-08-01');
        $this->createOvenScanEvent($treeLeg1, '2026-08-10 10:00:00');
        $this->createOvenScanEvent($treeLeg2, '2026-08-10 10:00:00');

        $candidates = collect($this->cutoffService->getCandidates())->keyBy('tree_id');
        $this->assertArrayHasKey($treeLeg1->id, $candidates);
        $this->assertArrayHasKey($treeLeg2->id, $candidates);
        $this->assertEquals('legacy', $candidates[$treeLeg1->id]['flow_type']);

        // Execute cutoff for Legacy
        $this->cutoffService->executeCutoff([$treeLeg1->id, $treeLeg2->id], $this->user->id, 'Legacy cutoff');

        $controller = app(\App\Http\Controllers\LostWax\ProductionStatusController::class);
        $refMethod = new \ReflectionMethod($controller, 'getAggregatedRows');
        $refMethod->setAccessible(true);
        $rows = $refMethod->invoke($controller);
        $row = collect($rows)->firstWhere('code', 'ET26-PARITY');

        $this->assertNotNull($row);
        $this->assertEquals(0, $row['siap_cor_qty']);
        $this->assertEquals(0, $row['oven_qty']);
        $this->assertEquals(0, $row['cor_qty']);
        $this->assertEquals(50, $row['total_lap']);
        $this->assertEquals('COMPLETED (HISTORICAL CUTOFF)', $row['status']);
    }

    /**
     * 9. Artisan Command tests:
     * - Safeguard: refuses execution without explicit barcodes or file.
     * - Preview mode: lists candidates and summaries.
     * - Dry-run simulation: validates barcodes and outputs simulation table without altering DB.
     * - Live execution: atomically processes valid barcodes, ignores/reports invalid barcodes.
     */
    public function test_artisan_command_safeguard_refuses_execution_without_explicit_barcodes(): void
    {
        $this->artisan('lost-wax:historical-cutoff')
            ->expectsOutputToContain('Eksekusi otomatis terhadap seluruh kandidat DILARANG')
            ->assertExitCode(1);
    }

    public function test_artisan_command_preview_mode(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');

        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithLine($plan);
        $line = $order->lines()->first();
        $tree = $this->createTreeForLine($line, 30, 'siap_cor', '2026-08-01');
        $this->createOvenScanEvent($tree, '2026-08-10 10:00:00');

        $this->artisan('lost-wax:historical-cutoff', ['--preview' => true])
            ->expectsOutputToContain('RINGKASAN KANDIDAT')
            ->expectsOutputToContain('Total Barcode Kandidat')
            ->expectsOutputToContain($tree->barcode)
            ->assertExitCode(0);
    }

    public function test_artisan_command_export_csv_safeguards(): void
    {
        // 1. Export without preview must fail
        $this->artisan('lost-wax:historical-cutoff', ['--export' => 'storage/test.csv'])
            ->expectsOutputToContain('Opsi --export hanya dapat digunakan bersama opsi --preview')
            ->assertExitCode(1);

        // 2. Export combined with barcodes must fail
        $this->artisan('lost-wax:historical-cutoff', [
            '--preview' => true,
            '--export' => 'storage/test.csv',
            '--barcodes' => '12345',
        ])
            ->expectsOutputToContain('Opsi --export tidak dapat digabungkan dengan --barcodes atau --file')
            ->assertExitCode(1);
    }

    public function test_artisan_command_preview_export_csv(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');

        $plan = $this->createPlan(['code' => 'PLAN-EXPORT-TEST']);
        $order = $this->createPrintOrderWithLine($plan);
        $line = $order->lines()->first();
        $treeNewFlow = $this->createTreeForLine($line, 25, 'siap_cor', '2026-08-01');
        $this->createOvenScanEvent($treeNewFlow, '2026-08-10 10:00:00');

        $wo = $this->createLegacyWorkOrder(['et_code' => 'WO-EXPORT-TEST']);
        $treeLegacy = $this->createTreeForWorkOrder($wo, 15, 'oven', '2026-08-01');
        $this->createOvenScanEvent($treeLegacy, '2026-08-10 10:00:00');

        $exportPath = storage_path('framework/testing/test_candidates_export.csv');
        @unlink($exportPath);

        try {
            $this->artisan('lost-wax:historical-cutoff', [
                '--preview' => true,
                '--export' => $exportPath,
                '--limit' => 1, // Limit is 1, but CSV must contain ALL candidates!
            ])
                ->expectsOutputToContain('EKSPOR CSV BERHASIL')
                ->expectsOutputToContain('Total Kolom        : 17 kolom')
                ->assertExitCode(0);

            $this->assertFileExists($exportPath);

            $content = file_get_contents($exportPath);
            // Verify UTF-8 BOM
            $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'CSV must start with UTF-8 BOM');

            $lines = array_filter(preg_split('/[\r\n]+/', $content));
            // 1 header + 2 data rows = 3 rows
            $this->assertCount(3, $lines, 'Must contain 1 header line and 2 data rows (not limited by --limit=1)');

            // Check header has exactly 17 columns
            $headerCols = str_getcsv(substr($lines[0], 3)); // strip BOM
            $this->assertCount(17, $headerCols, 'Header must have exactly 17 columns');
            $expectedHeaders = [
                'Barcode',
                'Tree ID',
                'Tree Number',
                'Flow',
                'Production Code',
                'Item Code',
                'Item Name',
                'Production Plan ID / Work Order ID',
                'Current Stage',
                'Current Status',
                'Gross Quantity',
                'Defect Quantity',
                'Net Good Quantity',
                'Barcode Age (Hari)',
                'Last Valid Oven Scan Date',
                'Oven Scan Age (Hari)',
                'Candidate Reason',
            ];
            $this->assertEquals($expectedHeaders, $headerCols);

            // Verify Legacy row
            $rowLegacy = str_getcsv($lines[1]);
            $rowNewFlow = str_getcsv($lines[2]);
            $rowsCombined = [$rowLegacy, $rowNewFlow];

            $legFound = collect($rowsCombined)->firstWhere(0, $treeLegacy->barcode);
            $this->assertNotNull($legFound);
            $this->assertEquals('Legacy', $legFound[3]);
            $this->assertEquals('WO-EXPORT-TEST', $legFound[4]);
            $this->assertStringStartsWith('WO#', $legFound[7]);

            $nfFound = collect($rowsCombined)->firstWhere(0, $treeNewFlow->barcode);
            $this->assertNotNull($nfFound);
            $this->assertEquals('New Flow', $nfFound[3]);
            $this->assertEquals('PLAN-EXPORT-TEST', $nfFound[4]);
            $this->assertStringStartsWith('Plan#', $nfFound[7]);

            // Verify database immutability: zero DB mutations
            $treeLegacy->refresh();
            $treeNewFlow->refresh();
            $this->assertEquals('generated', $treeLegacy->status);
            $this->assertEquals('generated', $treeNewFlow->status);
            $this->assertDatabaseCount('lost_wax_historical_cutoffs', 0);
            $this->assertDatabaseCount('lost_wax_historical_cutoff_trees', 0);

            // Verify flow filter: export only legacy
            $exportPathLegacyOnly = storage_path('framework/testing/test_legacy_only.csv');
            @unlink($exportPathLegacyOnly);

            $this->artisan('lost-wax:historical-cutoff', [
                '--preview' => true,
                '--flow' => 'legacy',
                '--export' => $exportPathLegacyOnly,
            ])->assertExitCode(0);

            $contentLeg = file_get_contents($exportPathLegacyOnly);
            $linesLeg = array_filter(preg_split('/[\r\n]+/', $contentLeg));
            // 1 header + 1 legacy row = 2 rows
            $this->assertCount(2, $linesLeg, 'Legacy filter export must contain only 1 legacy candidate row');
            @unlink($exportPathLegacyOnly);

        } finally {
            if (file_exists($exportPath)) {
                @unlink($exportPath);
            }
        }
    }

    public function test_artisan_command_dry_run_simulation(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');

        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithLine($plan);
        $line = $order->lines()->first();
        $treeValid = $this->createTreeForLine($line, 25, 'siap_cor', '2026-08-01');

        // Invalid tree: non-existent barcode
        $invalidBarcode = 'NONEXISTENT999';

        $this->artisan('lost-wax:historical-cutoff', [
            '--barcodes' => "{$treeValid->barcode},{$invalidBarcode}",
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('STATUS MODE: DRY-RUN')
            ->expectsOutputToContain('Daftar Barcode Tidak Valid / Ditolak')
            ->expectsOutputToContain('NONEXISTENT999')
            ->expectsOutputToContain($treeValid->barcode)
            ->expectsOutputToContain('SIMULASI SELESAI')
            ->assertExitCode(0);

        // Verify no changes made to DB
        $treeValid->refresh();
        $this->assertEquals('generated', $treeValid->status);
        $this->assertEquals('siap_cor', $treeValid->current_stage);
        $this->assertDatabaseMissing('lost_wax_historical_cutoffs', ['total_trees' => 1]);
    }

    public function test_artisan_command_live_execution(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');

        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithLine($plan);
        $line = $order->lines()->first();
        $tree1 = $this->createTreeForLine($line, 20, 'siap_cor', '2026-08-01');
        $tree2 = $this->createTreeForLine($line, 15, 'oven', '2026-08-01');

        $this->artisan('lost-wax:historical-cutoff', [
            '--barcodes' => "{$tree1->barcode},{$tree2->barcode}",
            '--reason' => 'Command Live Execution Test',
            '--notes' => 'Catatan audit test',
            '--user-id' => $this->user->id,
            '--force' => true,
        ])
            ->expectsOutputToContain('HISTORICAL CUT-OFF BERHASIL DIEKSEKUSI')
            ->expectsOutputToContain('LW-CUTOFF-')
            ->expectsOutputToContain('Command Live Execution Test')
            ->assertExitCode(0);

        // Verify DB update
        $tree1->refresh();
        $tree2->refresh();
        $this->assertEquals('historical_cutoff', $tree1->status);
        $this->assertEquals('historical_cutoff', $tree2->status);

        $this->assertDatabaseHas('lost_wax_historical_cutoffs', [
            'total_trees' => 2,
            'total_quantity' => 35,
            'total_usable_quantity' => 35,
            'reason' => 'Command Live Execution Test',
            'executed_by' => $this->user->id,
        ]);
    }

    public function test_artisan_command_live_execution_with_file_input(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');

        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithLine($plan);
        $line = $order->lines()->first();
        $tree1 = $this->createTreeForLine($line, 10, 'siap_cor', '2026-08-01');
        $tree2 = $this->createTreeForLine($line, 15, 'oven', '2026-08-01');

        $tempFilePath = storage_path('framework/testing/test_cutoff_barcodes.txt');
        @mkdir(dirname($tempFilePath), 0777, true);
        file_put_contents($tempFilePath, "{$tree1->barcode}\n{$tree2->barcode}\n");

        try {
            $this->artisan('lost-wax:historical-cutoff', [
                '--file' => $tempFilePath,
                '--reason' => 'File input cutoff test',
                '--user-id' => $this->user->id,
                '--force' => true,
            ])
                ->expectsOutputToContain('HISTORICAL CUT-OFF BERHASIL DIEKSEKUSI')
                ->assertExitCode(0);

            $tree1->refresh();
            $tree2->refresh();
            $this->assertEquals('historical_cutoff', $tree1->status);
            $this->assertEquals('historical_cutoff', $tree2->status);
        } finally {
            if (file_exists($tempFilePath)) {
                @unlink($tempFilePath);
            }
        }
    }

    /**
     * 10. Mixed Completion Prioritizes COMPLETED (HISTORICAL CUTOFF) on both Legacy and New Flow.
     * Order of priority:
     * 1. CLOSED (MANUAL)
     * 2. COMPLETED (HISTORICAL CUTOFF) if cutoff exists and WIP is empty
     * 3. COMPLETED (COR VERIFIED) if Cor exists, NO cutoff, and WIP is empty
     * 4. ACTIVE
     */
    public function test_mixed_completion_status_priority_across_legacy_and_new_flow(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');
        $this->actingAs($this->user);

        $controller = app(\App\Http\Controllers\LostWax\ProductionStatusController::class);
        $refMethod = new \ReflectionMethod($controller, 'getAggregatedRows');
        $refMethod->setAccessible(true);

        // --- SUBCASE A: LEGACY MIXED (Cor + Cutoff) ---
        $woMixed = $this->createLegacyWorkOrder(['et_code' => 'ET26-LEG-MIX', 'po_quantity' => 40]);
        $treeLegCor = $this->createTreeForWorkOrder($woMixed, 20, 'cor', '2026-08-01');
        $treeLegCut = $this->createTreeForWorkOrder($woMixed, 20, 'siap_cor', '2026-08-01');

        $cr = LostWaxCastingResult::create([
            'heat_number' => 'HN-LEG-MIX',
            'cast_date' => '2026-08-15',
            'furnace' => 'F1',
            'shift' => '1',
            'total_trees' => 1,
            'total_pcs' => 20,
            'status' => 'COMPLETED',
            'recorded_by' => $this->user->id,
        ]);
        LostWaxCastingResultTree::create([
            'lost_wax_casting_result_id' => $cr->id,
            'lost_wax_tree_id' => $treeLegCor->id,
            'active_tree_id' => $treeLegCor->id,
            'barcode' => $treeLegCor->barcode,
            'production_code' => 'ET26-LEG-MIX',
            'quantity_pcs' => 20,
        ]);

        $this->cutoffService->executeCutoff([$treeLegCut->id], $this->user->id, 'Cutoff partial legacy');

        $rows = $refMethod->invoke($controller);
        $rowLegMixed = collect($rows)->firstWhere('code', 'ET26-LEG-MIX');

        $this->assertNotNull($rowLegMixed);
        $this->assertEquals(20, $rowLegMixed['cor_qty'], 'Cor aktual harus tetap tercatat 20 PCS');
        $this->assertEquals(20, $rowLegMixed['cutoff_qty'], 'Cutoff harus tercatat 20 PCS');
        $this->assertEquals(40, $rowLegMixed['total_lap'], 'Total harus 40 PCS');
        $this->assertEquals('COMPLETED (HISTORICAL CUTOFF)', $rowLegMixed['status'], 'Mixed completion harus berstatus COMPLETED (HISTORICAL CUTOFF)');

        // If manual closed, must be CLOSED (MANUAL)
        $woMixed->update(['status' => 'closed']);
        $rowsAfterClose = $refMethod->invoke($controller);
        $rowLegClosed = collect($rowsAfterClose)->firstWhere('code', 'ET26-LEG-MIX');
        $this->assertEquals('CLOSED (MANUAL)', $rowLegClosed['status']);

        // --- SUBCASE B: LEGACY PURE COR (No Cutoff) ---
        $woPureCor = $this->createLegacyWorkOrder(['et_code' => 'ET26-LEG-COR', 'po_quantity' => 20]);
        $treeLegPure = $this->createTreeForWorkOrder($woPureCor, 20, 'cor', '2026-08-01');
        LostWaxCastingResultTree::create([
            'lost_wax_casting_result_id' => $cr->id,
            'lost_wax_tree_id' => $treeLegPure->id,
            'active_tree_id' => $treeLegPure->id,
            'barcode' => $treeLegPure->barcode,
            'production_code' => 'ET26-LEG-COR',
            'quantity_pcs' => 20,
        ]);

        $rowsPure = $refMethod->invoke($controller);
        $rowLegPure = collect($rowsPure)->firstWhere('code', 'ET26-LEG-COR');
        $this->assertEquals('COMPLETED (COR VERIFIED)', $rowLegPure['status']);

        // --- SUBCASE C: NEW FLOW MIXED (Cor + Cutoff) ---
        $planMixed = $this->createPlan(['code' => '268NF-MIX', 'qty_planned' => 40, 'po_quantity' => 40]);
        $orderMixed = $this->createPrintOrderWithLine($planMixed, 40);
        $lineMixed = $orderMixed->lines()->first();

        $treeNFCor = $this->createTreeForLine($lineMixed, 20, 'cor', '2026-08-01');
        $treeNFCut = $this->createTreeForLine($lineMixed, 20, 'siap_cor', '2026-08-01');

        LostWaxCastingResultTree::create([
            'lost_wax_casting_result_id' => $cr->id,
            'lost_wax_tree_id' => $treeNFCor->id,
            'active_tree_id' => $treeNFCor->id,
            'barcode' => $treeNFCor->barcode,
            'production_code' => '268NF-MIX',
            'quantity_pcs' => 20,
        ]);

        $this->cutoffService->executeCutoff([$treeNFCut->id], $this->user->id, 'Cutoff partial new flow');

        $rowsNF = $refMethod->invoke($controller);
        $rowNFMixed = collect($rowsNF)->firstWhere('code', '268NF-MIX');

        $this->assertNotNull($rowNFMixed);
        $this->assertEquals(20, $rowNFMixed['cor_qty'], 'Cor aktual New Flow harus tetap 20 PCS');
        $this->assertEquals(20, $rowNFMixed['cutoff_qty'], 'Cutoff New Flow harus tetap 20 PCS');
        $this->assertEquals(40, $rowNFMixed['total_lap']);
        $this->assertEquals('COMPLETED (HISTORICAL CUTOFF)', $rowNFMixed['status'], 'Mixed New Flow harus COMPLETED (HISTORICAL CUTOFF)');

        // --- SUBCASE D: NEW FLOW PURE COR (No Cutoff) ---
        $planPureCor = $this->createPlan(['code' => '268NF-COR', 'qty_planned' => 20, 'po_quantity' => 20]);
        $orderPureCor = $this->createPrintOrderWithLine($planPureCor, 20);
        $linePureCor = $orderPureCor->lines()->first();

        $treeNFPure = $this->createTreeForLine($linePureCor, 20, 'cor', '2026-08-01');
        LostWaxCastingResultTree::create([
            'lost_wax_casting_result_id' => $cr->id,
            'lost_wax_tree_id' => $treeNFPure->id,
            'active_tree_id' => $treeNFPure->id,
            'barcode' => $treeNFPure->barcode,
            'production_code' => '268NF-COR',
            'quantity_pcs' => 20,
        ]);

        $rowsNFPure = $refMethod->invoke($controller);
        $rowNFPure = collect($rowsNFPure)->firstWhere('code', '268NF-COR');
        $this->assertEquals('COMPLETED (COR VERIFIED)', $rowNFPure['status']);

        // --- SUBCASE E: NEW FLOW CLOSED (MANUAL) ---
        $planPureCor->update(['is_closed' => true]);
        $rowsNFClosed = $refMethod->invoke($controller);
        $rowNFClosed = collect($rowsNFClosed)->firstWhere('code', '268NF-COR');
        $this->assertEquals('CLOSED (MANUAL)', $rowNFClosed['status']);
    }

    /**
     * 11. New Flow Anomalies (unknown stage / status) prevent COMPLETED status.
     */
    public function test_new_flow_anomalies_prevent_completed_status(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');
        $this->actingAs($this->user);

        $controller = app(\App\Http\Controllers\LostWax\ProductionStatusController::class);
        $refMethod = new \ReflectionMethod($controller, 'getAggregatedRows');
        $refMethod->setAccessible(true);

        // Case 1: Tree with unknown stage (e.g. layer_99)
        $planAnomalyStage = $this->createPlan(['code' => '268NF-ANOM-STAGE', 'qty_planned' => 25, 'po_quantity' => 25]);
        $orderStage = $this->createPrintOrderWithLine($planAnomalyStage, 25);
        $lineStage = $orderStage->lines()->first();
        $treeAnomalyStage = $this->createTreeForLine($lineStage, 25, 'layer_99', '2026-08-01');

        $rows1 = $refMethod->invoke($controller);
        $rowStage = collect($rows1)->firstWhere('code', '268NF-ANOM-STAGE');

        $this->assertNotNull($rowStage);
        $this->assertTrue($rowStage['has_anomaly'], 'Harus ditandai has_anomaly = true');
        $this->assertStringContainsString('layer_99', $rowStage['anomaly_message']);
        $this->assertEquals('ACTIVE', $rowStage['status'], 'Pohon dengan unknown stage tidak boleh COMPLETED');

        // Case 2: Tree with unknown status (e.g. testing_status)
        $planAnomalyStatus = $this->createPlan(['code' => '268NF-ANOM-STAT', 'qty_planned' => 25, 'po_quantity' => 25]);
        $orderStatus = $this->createPrintOrderWithLine($planAnomalyStatus, 25);
        $lineStatus = $orderStatus->lines()->first();
        $treeAnomalyStatus = $this->createTreeForLine($lineStatus, 25, 'siap_cor', '2026-08-01');
        $treeAnomalyStatus->update(['status' => 'corrupted_state']);

        $rows2 = $refMethod->invoke($controller);
        $rowStatus = collect($rows2)->firstWhere('code', '268NF-ANOM-STAT');

        $this->assertNotNull($rowStatus);
        $this->assertTrue($rowStatus['has_anomaly'], 'Harus ditandai has_anomaly = true');
        $this->assertStringContainsString('corrupted_state', $rowStatus['anomaly_message']);
        $this->assertEquals('ACTIVE', $rowStatus['status'], 'Pohon dengan unknown status tidak boleh COMPLETED');
    }

    /**
     * 12. Database Restrict On Delete rejects header deletion when details exist.
     */
    public function test_database_restrict_on_delete_rejects_header_deletion(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');

        $plan = $this->createPlan();
        $order = $this->createPrintOrderWithLine($plan);
        $line = $order->lines()->first();
        $tree = $this->createTreeForLine($line, 20, 'siap_cor', '2026-08-01');

        $cutoff = $this->cutoffService->executeCutoff([$tree->id], $this->user->id, 'Testing FK restrict');

        $this->assertDatabaseHas('lost_wax_historical_cutoffs', ['id' => $cutoff->id]);
        $this->assertDatabaseHas('lost_wax_historical_cutoff_trees', ['lost_wax_historical_cutoff_id' => $cutoff->id]);

        // Attempting to delete header directly must fail with QueryException
        try {
            $cutoff->delete();
            $this->fail('Header deletion should have failed due to foreign key RESTRICT constraint.');
        } catch (\Illuminate\Database\QueryException $e) {
            // Expected foreign key constraint violation
            $this->assertTrue(true, 'QueryException was thrown as expected.');
        }

        // Verify data integrity: detail record still exists
        $this->assertDatabaseHas('lost_wax_historical_cutoff_trees', ['lost_wax_historical_cutoff_id' => $cutoff->id]);
    }
}
