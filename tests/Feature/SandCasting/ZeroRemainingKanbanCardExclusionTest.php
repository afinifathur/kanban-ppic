<?php

namespace Tests\Feature\SandCasting;

use App\Models\DefectType;
use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\User;
use App\Services\SandCasting\SandCastingProductionFloorQueryService;
use App\Services\SandCasting\SandCastingQuantityResolverService;
use App\Services\SandCasting\SandCastingStageExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ZeroRemainingKanbanCardExclusionTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $spvNetto;

    protected User $spvBubutOd;

    protected User $spvBubutCnc;

    protected User $qcUser;

    protected DefectType $defectType;

    protected SandCastingStageExecutionService $executionService;

    protected SandCastingProductionFloorQueryService $queryService;

    protected SandCastingQuantityResolverService $quantityResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $planPerm = Permission::firstOrCreate(['name' => 'access_planning']);
        $visPerm = Permission::firstOrCreate(['name' => 'access_production_visibility']);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->givePermissionTo($planPerm);

        $spvRole = Role::firstOrCreate(['name' => 'spv']);
        $qcRole = Role::firstOrCreate(['name' => 'qc']);

        $this->adminUser = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin_test@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->adminUser->assignRole('admin');

        $this->spvNetto = User::factory()->create([
            'name' => 'SPV Netto',
            'email' => 'spv_netto_test@peroniks.com',
            'assigned_stage' => 'netto',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvNetto->assignRole('spv');

        $this->spvBubutOd = User::factory()->create([
            'name' => 'SPV Bubut OD',
            'email' => 'spv_od_test@peroniks.com',
            'assigned_stage' => 'bubut_od',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvBubutOd->assignRole('spv');

        $this->spvBubutCnc = User::factory()->create([
            'name' => 'SPV Bubut CNC',
            'email' => 'spv_cnc_test@peroniks.com',
            'assigned_stage' => 'bubut_cnc',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvBubutCnc->assignRole('spv');

        $this->qcUser = User::factory()->create([
            'name' => 'QC Inspector',
            'email' => 'qc_test@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->qcUser->assignRole('qc');

        $this->defectType = DefectType::create([
            'department' => 'netto',
            'name' => 'Retak / Porositas',
            'is_active' => true,
        ]);

        $this->quantityResolver = new SandCastingQuantityResolverService;
        $this->executionService = new SandCastingStageExecutionService($this->quantityResolver);
        $this->queryService = new SandCastingProductionFloorQueryService($this->executionService, $this->quantityResolver);
    }

    protected function createKtr(array $attributes = []): SandCastingCastingResultLine
    {
        static $seq = 1;
        $idx = $seq++;

        $plan = ProductionPlan::create([
            'code' => $attributes['production_code'] ?? '268ET'.str_pad((string) $idx, 3, '0', STR_PAD_LEFT),
            'title' => 'Rencana SC '.$idx,
            'item_code' => $attributes['item_code'] ?? '4.'.str_pad((string) $idx, 3, '0', STR_PAD_LEFT),
            'item_name' => $attributes['item_name'] ?? 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-'.$idx,
            'line_number' => $attributes['line_number'] ?? 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'customer' => $attributes['customer'] ?? 'PT SINAR METAL',
            'product_scope' => $attributes['product_scope'] ?? 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261031-'.str_pad((string) $idx, 4, '0', STR_PAD_LEFT),
            'scheduled_date' => '2026-10-31',
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $unitWeight = (float) ($attributes['unit_weight_kg'] ?? 2.50);
        $qtyGood = (int) ($attributes['qty_good'] ?? 20);
        $totalWeight = isset($attributes['total_weight_kg']) ? (float) $attributes['total_weight_kg'] : round($qtyGood * $unitWeight, 2);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => $attributes['size'] ?? '2"',
            'unit_weight_kg' => $unitWeight,
            'total_weight_kg' => $totalWeight,
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => $attributes['heat_number'] ?? 'HEAT-'.$idx,
            'cast_date' => '2026-10-31',
            'furnace' => 'F1',
            'shift' => '1',
            'recorded_by' => $this->adminUser->id,
        ]);

        return SandCastingCastingResultLine::create([
            'sand_casting_casting_result_id' => $result->id,
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $attributes['traveler_number'] ?? 'KTR-20261031-'.str_pad((string) $idx, 4, '0', STR_PAD_LEFT),
            'qty_good' => $qtyGood,
            'qty_reject' => 0,
            'unit_weight_kg' => $unitWeight,
            'total_weight_kg' => $totalWeight,
            'current_stage' => $attributes['current_stage'] ?? 'netto',
        ]);
    }

    /**
     * Skenario 1 (Kondisi A):
     * Kartu memiliki laporan kerusakan WAITING_QC, hasil sementara 0 PCS:
     * Kartu tetap tampil di Kanban dengan status menunggu verifikasi QC.
     */
    public function test_01_card_with_waiting_qc_defect_preliminary_zero_remains_visible_with_pending_status(): void
    {
        $ktr = $this->createKtr([
            'heat_number' => 'A215092602',
            'item_name' => 'SS304 JIS 20K 4"',
            'qty_good' => 20,
            'current_stage' => 'netto',
        ]);

        // Physical flow: Netto -> Bubut OD -> Bubut CNC
        $eNetto = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $eOd = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->spvBubutOd->id);
        $ktr->refresh();
        $this->assertEquals('bubut_cnc', $ktr->current_stage);

        // PPIC records 20 defect at Netto (status is WAITING_QC, pending Admin QC verification)
        $this->executionService->recordDefectQty($eNetto, 20, $this->adminUser->id);

        $kanbanData = $this->queryService->getStageKanbanData('bubut_cnc');

        // MUST be present in ready cards (active Kanban) because QC verification is still pending
        $this->assertEquals(1, $kanbanData['summary']['ready_count']);
        $this->assertCount(1, $kanbanData['ready']);

        $card = $kanbanData['ready'][0];
        $this->assertEquals('A215092602', $card['heat_number']);
        $this->assertEquals('WAITING_QC', $card['display_status']);
        $this->assertTrue($card['has_pending_qc']);
        $this->assertEquals(0, $card['qty']);

        // Check Web View renders badge
        $response = $this->actingAs($this->adminUser)
            ->get(route('sand-casting.kanban.stage', 'bubut-cnc'));

        $response->assertOk();
        $response->assertSee('MENUNGGU VERIFIKASI QC');
        $response->assertSee('Heat A215092602');
    }

    /**
     * Skenario 2 (Kondisi D):
     * Kartu memiliki beberapa laporan kerusakan, satu sudah diverifikasi dan satu masih WAITING_QC:
     * Kartu tetap tampil di Kanban dengan status MENUNGGU VERIFIKASI QC.
     */
    public function test_02_card_with_multiple_defects_one_confirmed_one_waiting_qc_remains_visible(): void
    {
        $ktr = $this->createKtr([
            'heat_number' => 'HEAT-MULTI-DEFECT',
            'qty_good' => 20,
            'current_stage' => 'netto',
        ]);

        // Netto: 5 defect recorded and verified by QC (CONFIRMED)
        $eNetto = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $eNetto = $this->executionService->recordDefectQty($eNetto, 5, $this->adminUser->id);
        $this->executionService->verifyQcBreakdown($eNetto, [
            ['defect_type_id' => $this->defectType->id, 'qty' => 5],
        ], $this->qcUser->id);

        // Bubut OD: physical done, 15 defect recorded (WAITING_QC)
        $eOd = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->spvBubutOd->id);
        $this->executionService->recordDefectQty($eOd, 15, $this->adminUser->id);

        $ktr->refresh();
        $this->assertEquals('bubut_cnc', $ktr->current_stage);

        $kanbanData = $this->queryService->getStageKanbanData('bubut_cnc');

        // Because Bubut OD report is still WAITING_QC, card must remain visible
        $this->assertEquals(1, $kanbanData['summary']['ready_count']);
        $card = $kanbanData['ready'][0];
        $this->assertEquals('WAITING_QC', $card['display_status']);
        $this->assertTrue($card['has_pending_qc']);

        $response = $this->actingAs($this->adminUser)
            ->get(route('sand-casting.kanban.stage', 'bubut-cnc'));
        $response->assertOk();
        $response->assertSee('MENUNGGU VERIFIKASI QC');
    }

    /**
     * Skenario 3 (Kondisi B):
     * Semua laporan selesai diverifikasi dan masih tersisa 10 PCS OK:
     * Kartu tampil dengan 10 PCS, status normal.
     */
    public function test_03_all_defects_verified_with_remaining_good_pcs_displays_correct_ok_quantity(): void
    {
        $ktr = $this->createKtr([
            'heat_number' => 'HEAT-VERIFIED-OK',
            'qty_good' => 20,
            'current_stage' => 'netto',
        ]);

        // Netto: 5 defect, confirmed
        $eNetto = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $eNetto = $this->executionService->recordDefectQty($eNetto, 5, $this->adminUser->id);
        $this->executionService->verifyQcBreakdown($eNetto, [
            ['defect_type_id' => $this->defectType->id, 'qty' => 5],
        ], $this->qcUser->id);

        // Bubut OD: 5 defect, confirmed
        $eOd = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->spvBubutOd->id);
        $eOd = $this->executionService->recordDefectQty($eOd, 5, $this->adminUser->id);
        $this->executionService->verifyQcBreakdown($eOd, [
            ['defect_type_id' => $this->defectType->id, 'qty' => 5],
        ], $this->qcUser->id);

        $ktr->refresh();
        $this->assertEquals('bubut_cnc', $ktr->current_stage);

        $kanbanData = $this->queryService->getStageKanbanData('bubut_cnc');

        // All confirmed, remaining is 10 PCS
        $this->assertEquals(1, $kanbanData['summary']['ready_count']);
        $card = $kanbanData['ready'][0];
        $this->assertEquals(10, $card['qty']);
        $this->assertEquals('READY', $card['display_status']);
        $this->assertFalse($card['has_pending_qc']);

        $response = $this->actingAs($this->adminUser)
            ->get(route('sand-casting.kanban.stage', 'bubut-cnc'));
        $response->assertOk();
        $response->assertDontSee('MENUNGGU VERIFIKASI QC');
        $response->assertSee('10');
    }

    /**
     * Skenario 4 (Kondisi C):
     * Semua laporan selesai diverifikasi dan seluruh kuantitas NG (0 PCS remaining):
     * Kartu otomatis tidak tampil di Kanban aktif.
     */
    public function test_04_all_defects_verified_and_total_quantity_ng_is_excluded_from_active_kanban(): void
    {
        $ktr = $this->createKtr([
            'heat_number' => 'HEAT-ALL-NG',
            'qty_good' => 20,
            'current_stage' => 'netto',
        ]);

        $eNetto = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $eNetto = $this->executionService->recordDefectQty($eNetto, 20, $this->adminUser->id);
        // Admin QC verifies full rejection (status = CONFIRMED)
        $this->executionService->verifyQcBreakdown($eNetto, [
            ['defect_type_id' => $this->defectType->id, 'qty' => 20],
        ], $this->qcUser->id);

        // KTR is now at bubut_od with 0 pcs confirmed
        $ktr->refresh();
        $kanbanData = $this->queryService->getStageKanbanData('bubut_od');

        // Must NOT appear in active ready cards
        $this->assertEquals(0, $kanbanData['summary']['ready_count']);
        $this->assertEmpty($kanbanData['ready']);

        // Must appear in halted bucket
        $this->assertEquals(1, $kanbanData['summary']['halted_count']);
        $this->assertEquals('HALTED', $kanbanData['halted'][0]['display_status']);

        // Web view must not show the card
        $response = $this->actingAs($this->adminUser)
            ->get(route('sand-casting.kanban.stage', 'bubut-od'));
        $response->assertOk();
        $response->assertDontSee('Heat HEAT-ALL-NG');
    }

    /**
     * Skenario 5:
     * Kartu tidak memiliki laporan kerusakan:
     * Perilaku Kanban normal tidak berubah.
     */
    public function test_05_card_without_defect_reports_maintains_normal_behavior(): void
    {
        $ktr = $this->createKtr([
            'heat_number' => 'HEAT-NORMAL',
            'qty_good' => 25,
            'current_stage' => 'bubut_cnc',
        ]);

        $kanbanData = $this->queryService->getStageKanbanData('bubut_cnc');

        $this->assertEquals(1, $kanbanData['summary']['ready_count']);
        $card = $kanbanData['ready'][0];
        $this->assertEquals(25, $card['qty']);
        $this->assertEquals('READY', $card['display_status']);
        $this->assertFalse($card['has_pending_qc']);
    }

    /**
     * Skenario 6:
     * Kartu dengan sebagian NG tetap tampil sesuai sisa kuantitas OK.
     */
    public function test_06_card_with_partial_confirmed_ng_displays_remaining_ok_quantity(): void
    {
        $ktr = $this->createKtr([
            'heat_number' => 'HEAT-PARTIAL-NG',
            'qty_good' => 30,
            'current_stage' => 'netto',
        ]);

        $eNetto = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $eNetto = $this->executionService->recordDefectQty($eNetto, 10, $this->adminUser->id);
        $this->executionService->verifyQcBreakdown($eNetto, [
            ['defect_type_id' => $this->defectType->id, 'qty' => 10],
        ], $this->qcUser->id);

        $ktr->refresh();
        $kanbanData = $this->queryService->getStageKanbanData('bubut_od');

        $this->assertEquals(1, $kanbanData['summary']['ready_count']);
        $card = $kanbanData['ready'][0];
        $this->assertEquals(20, $card['qty']);
        $this->assertEquals('READY', $card['display_status']);
        $this->assertFalse($card['has_pending_qc']);
    }

    /**
     * Skenario 7:
     * PCS positif tetapi KG nol tetap mengikuti aturan sebelumnya.
     */
    public function test_07_positive_pcs_with_zero_kg_remains_visible(): void
    {
        $ktr = $this->createKtr([
            'heat_number' => 'HEAT-ZERO-KG',
            'qty_good' => 15,
            'unit_weight_kg' => 0.0,
            'total_weight_kg' => 0.0,
            'current_stage' => 'bubut_cnc',
        ]);

        $kanbanData = $this->queryService->getStageKanbanData('bubut_cnc');

        $this->assertEquals(1, $kanbanData['summary']['ready_count']);
        $card = $kanbanData['ready'][0];
        $this->assertEquals(15, $card['qty']);
        $this->assertEquals(0.0, (float) $card['total_weight_kg']);
        $this->assertEquals('READY', $card['display_status']);
    }

    /**
     * Skenario 8:
     * PDF dan ringkasan Kanban konsisten dengan daftar kartu yang ditampilkan.
     */
    public function test_08_pdf_print_and_kanban_summary_consistent_with_displayed_cards(): void
    {
        // Card 1: Normal ready (10 PCS, 20 KG)
        $this->createKtr([
            'heat_number' => 'HEAT-PRINT-1',
            'qty_good' => 10,
            'unit_weight_kg' => 2.0,
            'total_weight_kg' => 20.0,
            'current_stage' => 'bubut_cnc',
            'line_number' => 1,
        ]);

        // Card 2: Pending QC card (0 PCS preliminary)
        $cardPending = $this->createKtr([
            'heat_number' => 'HEAT-PRINT-PENDING',
            'qty_good' => 15,
            'unit_weight_kg' => 2.0,
            'total_weight_kg' => 30.0,
            'current_stage' => 'netto',
            'line_number' => 1,
        ]);
        $eNetto = $this->executionService->markPhysicalDone($cardPending->traveler_number, 'netto', $this->spvNetto->id);
        $eOd = $this->executionService->markPhysicalDone($cardPending->traveler_number, 'bubut_od', $this->spvBubutOd->id);
        $cardPending->refresh();
        $this->assertEquals('bubut_cnc', $cardPending->current_stage);
        $this->executionService->recordDefectQty($eNetto, 15, $this->adminUser->id);

        $response = $this->actingAs($this->adminUser)
            ->get(route('sand-casting.kanban.print', 'bubut-cnc'));

        $response->assertOk();
        // Both cards must appear in print view
        $response->assertSee('Heat: HEAT-PRINT-1');
        $response->assertSee('Heat: HEAT-PRINT-PENDING');
        $response->assertSee('MENUNGGU VERIFIKASI QC');

        // Totals: 2 cards visible, 10 PCS total (10 + 0), 20 KG total (20 + 0)
        $response->assertViewHas('totalCards', 2);
        $response->assertViewHas('totalPcs', 10);
        $response->assertViewHas('totalWeightKg', 20.0);
    }

    /**
     * Skenario 9:
     * Kartu yang sudah keluar dari Kanban tetap memiliki riwayat produksi dan laporan kerusakan.
     */
    public function test_09_excluded_card_preserves_database_records_and_history(): void
    {
        $ktr = $this->createKtr([
            'heat_number' => 'HEAT-PRESERVED',
            'qty_good' => 20,
            'current_stage' => 'netto',
        ]);

        $execNetto = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $execNetto = $this->executionService->recordDefectQty($execNetto, 20, $this->adminUser->id);
        $this->executionService->verifyQcBreakdown($execNetto, [
            ['defect_type_id' => $this->defectType->id, 'qty' => 20],
        ], $this->qcUser->id);

        // Record still in DB
        $this->assertDatabaseHas('sand_casting_casting_result_lines', [
            'traveler_number' => $ktr->traveler_number,
        ]);

        // Historical query
        $travelerDto = $this->queryService->findByTraveler($ktr->traveler_number);
        $this->assertNotNull($travelerDto);
        $this->assertEquals('HALTED', $travelerDto['operational_status']);
        $this->assertCount(1, $travelerDto['stage_history']);
        $this->assertEquals(20, $travelerDto['stage_history'][0]['defect_qty']);

        // Stage history collection
        $history = $this->queryService->getStageHistory($ktr->traveler_number);
        $this->assertCount(1, $history);
        $this->assertEquals(20, $history[0]['defect_qty']);
    }

    /**
     * Skenario 10:
     * Tidak ada duplikasi perhitungan kerusakan ketika laporan berpindah status.
     */
    public function test_10_no_duplicate_defect_calculation_across_status_transitions(): void
    {
        $ktr = $this->createKtr([
            'heat_number' => 'HEAT-LIFECYCLE',
            'qty_good' => 30,
            'current_stage' => 'netto',
        ]);

        // 1. Initial WAITING_DEFECT
        $exec = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $effective1 = $this->quantityResolver->resolveEffectiveGoodQty($ktr, 'netto');
        $this->assertEquals(30, $effective1);

        // 2. Transition to WAITING_QC
        $exec = $this->executionService->recordDefectQty($exec, 8, $this->adminUser->id);
        $effective2 = $this->quantityResolver->resolveEffectiveGoodQty($ktr, 'netto');
        $this->assertEquals(22, $effective2);

        // 3. Transition to CONFIRMED
        $exec = $this->executionService->verifyQcBreakdown($exec, [
            ['defect_type_id' => $this->defectType->id, 'qty' => 8],
        ], $this->qcUser->id);
        $effective3 = $this->quantityResolver->resolveEffectiveGoodQty($ktr, 'netto');
        $this->assertEquals(22, $effective3);

        // Verify exactly one execution record and no defect double-counting
        $this->assertCount(1, $ktr->fresh()->stageExecutions);
    }

    /**
     * Helper to render the Kanban Blade view with arbitrary card datasets.
     */
    protected function renderKanbanBlade(array $readyCards, array $filters = []): string
    {
        $this->actingAs($this->adminUser);

        return view('sand-casting.kanban.index', [
            'errors' => new \Illuminate\Support\ViewErrorBag,
            'stage' => 'bubut_cnc',
            'stageSlug' => 'bubut-cnc',
            'stageLabel' => 'BUBUT CNC',
            'summary' => [
                'ready_count' => count($readyCards),
                'incoming_count' => 0,
                'halted_count' => 0,
                'total_count' => count($readyCards),
                'ready_qty' => (int) array_sum(array_column($readyCards, 'qty')),
                'incoming_qty' => 0,
                'halted_qty' => 0,
            ],
            'readyCards' => $readyCards,
            'incomingCards' => [],
            'haltedCards' => [],
            'filters' => $filters,
            'availableStages' => [],
            'scannerUrl' => '/sand-casting/scan/stage/bubut-cnc',
            'canScan' => true,
            'canReorder' => false,
            'showQueueNumbers' => true,
            'todayOutput' => null,
            'lastRefreshedAt' => '10:00:00',
        ])->render();
    }

    /**
     * Kondisi 1:
     * Kartu WAITING_QC dengan 0 PCS / 0 KG menampilkan badge MENUNGGU VERIFIKASI QC dan border oranye.
     */
    public function test_condition_1_waiting_qc_zero_pcs_zero_kg_shows_badge_and_amber_border(): void
    {
        $ktr = $this->createKtr([
            'heat_number' => 'HEAT-C1-0PCS-0KG',
            'qty_good' => 20,
            'unit_weight_kg' => 2.5,
            'line_number' => 1,
            'current_stage' => 'netto',
        ]);

        $eNetto = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $this->executionService->recordDefectQty($eNetto, 20, $this->adminUser->id);

        $response = $this->actingAs($this->adminUser)
            ->get(route('sand-casting.kanban.stage', 'bubut-od'));

        $response->assertOk();
        $response->assertSee('Heat HEAT-C1-0PCS-0KG');
        $response->assertSee('MENUNGGU VERIFIKASI QC');
        $response->assertSee('border-amber-400');
        $response->assertSee('0 PCS OK');
    }

    /**
     * Kondisi 2:
     * Kartu WAITING_QC dengan 78 PCS / 171 KG tampil di Kanban tetapi TIDAK menampilkan badge
     * MENUNGGU VERIFIKASI QC dan TIDAK menggunakan border oranye.
     */
    public function test_condition_2_waiting_qc_positive_pcs_and_kg_hides_badge_and_amber_border(): void
    {
        $ktr = $this->createKtr([
            'heat_number' => 'HEAT-C2-78PCS-171KG',
            'qty_good' => 100,
            'unit_weight_kg' => 2.19,
            'line_number' => 1,
            'current_stage' => 'netto',
        ]);

        // Netto: 22 defect recorded (WAITING_QC). Remaining good = 78 PCS, total weight = 78 * 2.19 = 170.82 KG (rounded to 171 KG in integer display).
        $eNetto = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $this->executionService->recordDefectQty($eNetto, 22, $this->adminUser->id);

        $kanbanData = $this->queryService->getStageKanbanData('bubut_od');
        $this->assertCount(1, $kanbanData['ready']);
        $card = $kanbanData['ready'][0];
        $this->assertEquals(78, $card['qty']);
        $this->assertEquals(170.82, (float) $card['total_weight_kg']);
        $this->assertEquals(171, round((float) $card['total_weight_kg']));
        $this->assertTrue($card['has_pending_qc']);

        $response = $this->actingAs($this->adminUser)
            ->get(route('sand-casting.kanban.stage', 'bubut-od'));

        $response->assertOk();
        $response->assertSee('Heat HEAT-C2-78PCS-171KG');
        $response->assertSee('78');
        $response->assertSee('171');
        // Badge and amber border must NOT be rendered
        $response->assertDontSee('MENUNGGU VERIFIKASI QC');
        $response->assertDontSee('border-amber-400');
        $response->assertDontSee('0 PCS OK');
    }

    /**
     * Kondisi 3:
     * Kartu WAITING_QC dengan 0 PCS / 15 KG TIDAK menampilkan badge MENUNGGU VERIFIKASI QC.
     */
    public function test_condition_3_waiting_qc_zero_pcs_positive_kg_hides_badge(): void
    {
        $card = [
            'id' => 301,
            'traveler_number' => 'KTR-C3',
            'heat_number' => 'HEAT-C3-0PCS-15KG',
            'pouring_sequence' => 1,
            'production_code' => 'SPK-C3',
            'item_code' => '4.301',
            'item_name' => 'FLANGE TEST 0 PCS 15 KG',
            'customer' => 'PT METAL',
            'size' => '2"',
            'line_number' => 1,
            'qty' => 0,
            'total_weight_kg' => 15.0,
            'has_pending_qc' => true,
            'is_urgent' => false,
            'queue_number' => '#01',
            'aging' => '1D 02H',
        ];

        // 4-line view
        $html4Line = $this->renderKanbanBlade([$card]);
        $this->assertStringContainsString('Heat HEAT-C3-0PCS-15KG', $html4Line);
        $this->assertStringNotContainsString('MENUNGGU VERIFIKASI QC', $html4Line);
        $this->assertStringNotContainsString('border-amber-400', $html4Line);

        // Single-line view
        $htmlSingleLine = $this->renderKanbanBlade([$card], ['line_number' => 1]);
        $this->assertStringContainsString('Heat HEAT-C3-0PCS-15KG', $htmlSingleLine);
        $this->assertStringNotContainsString('MENUNGGU VERIFIKASI QC', $htmlSingleLine);
        $this->assertStringNotContainsString('border-amber-400', $htmlSingleLine);
    }

    /**
     * Kondisi 4:
     * Kartu WAITING_QC dengan 10 PCS / 0 KG TIDAK menampilkan badge MENUNGGU VERIFIKASI QC.
     */
    public function test_condition_4_waiting_qc_positive_pcs_zero_kg_hides_badge(): void
    {
        $ktr = $this->createKtr([
            'heat_number' => 'HEAT-C4-10PCS-0KG',
            'qty_good' => 20,
            'unit_weight_kg' => 0.0,
            'total_weight_kg' => 0.0,
            'line_number' => 1,
            'current_stage' => 'netto',
        ]);

        $eNetto = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $this->executionService->recordDefectQty($eNetto, 10, $this->adminUser->id);

        $kanbanData = $this->queryService->getStageKanbanData('bubut_od');
        $this->assertCount(1, $kanbanData['ready']);
        $card = $kanbanData['ready'][0];
        $this->assertEquals(10, $card['qty']);
        $this->assertEquals(0.0, (float) $card['total_weight_kg']);
        $this->assertTrue($card['has_pending_qc']);

        $response = $this->actingAs($this->adminUser)
            ->get(route('sand-casting.kanban.stage', 'bubut-od'));

        $response->assertOk();
        $response->assertSee('Heat HEAT-C4-10PCS-0KG');
        $response->assertSee('10');
        $response->assertDontSee('MENUNGGU VERIFIKASI QC');
        $response->assertDontSee('border-amber-400');
    }

    /**
     * Kondisi 5:
     * Kartu non-WAITING_QC dengan 0 PCS / 0 KG TIDAK menampilkan badge (mengikuti logika exclude yang sudah ada).
     */
    public function test_condition_5_non_waiting_qc_zero_pcs_zero_kg_is_excluded_from_kanban(): void
    {
        $ktr = $this->createKtr([
            'heat_number' => 'HEAT-C5-EXCLUDED',
            'qty_good' => 15,
            'unit_weight_kg' => 2.0,
            'line_number' => 1,
            'current_stage' => 'netto',
        ]);

        $eNetto = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        $eNetto = $this->executionService->recordDefectQty($eNetto, 15, $this->adminUser->id);
        $this->executionService->verifyQcBreakdown($eNetto, [
            ['defect_type_id' => $this->defectType->id, 'qty' => 15],
        ], $this->qcUser->id);

        $kanbanData = $this->queryService->getStageKanbanData('bubut_od');
        $this->assertCount(0, $kanbanData['ready']);

        $response = $this->actingAs($this->adminUser)
            ->get(route('sand-casting.kanban.stage', 'bubut-od'));

        $response->assertOk();
        $response->assertDontSee('Heat HEAT-C5-EXCLUDED');
        $response->assertDontSee('MENUNGGU VERIFIKASI QC');
    }

    /**
     * Kondisi 6:
     * Kartu dengan pending QC tetap masuk ke bucket Kanban yang benar (tidak hilang dari Kanban hanya karena badgenya disembunyikan).
     */
    public function test_condition_6_card_with_pending_qc_remains_in_ready_bucket_when_badge_is_hidden(): void
    {
        $ktr = $this->createKtr([
            'heat_number' => 'HEAT-C6-IN-READY-BUCKET',
            'qty_good' => 50,
            'unit_weight_kg' => 3.0,
            'line_number' => 2,
            'current_stage' => 'netto',
        ]);

        $eNetto = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);
        // 10 defect recorded, remaining good = 40 PCS
        $this->executionService->recordDefectQty($eNetto, 10, $this->adminUser->id);

        $kanbanData = $this->queryService->getStageKanbanData('bubut_od');
        $this->assertEquals(1, $kanbanData['summary']['ready_count']);
        $this->assertCount(1, $kanbanData['ready']);

        $card = $kanbanData['ready'][0];
        $this->assertEquals('HEAT-C6-IN-READY-BUCKET', $card['heat_number']);
        $this->assertEquals(40, $card['qty']);
        $this->assertEquals(120.0, (float) $card['total_weight_kg']);
        $this->assertTrue($card['has_pending_qc']);
        $this->assertEquals('READY', $card['display_status']);
    }

    /**
     * Kondisi 7:
     * Tampilan single line dan 4-line view konsisten dalam menerapkan aturan ini.
     */
    public function test_condition_7_single_line_and_four_line_views_are_consistent(): void
    {
        // Card 1: 0 PCS / 0 KG pending QC (Line 1) -> must show badge
        $ktrZero = $this->createKtr([
            'heat_number' => 'HEAT-C7-ZERO',
            'qty_good' => 10,
            'unit_weight_kg' => 2.0,
            'line_number' => 1,
            'current_stage' => 'netto',
        ]);
        $eNettoZero = $this->executionService->markPhysicalDone($ktrZero->traveler_number, 'netto', $this->spvNetto->id);
        $this->executionService->recordDefectQty($eNettoZero, 10, $this->adminUser->id);

        // Card 2: 78 PCS / 171 KG pending QC (Line 1) -> must hide badge
        $ktrPositive = $this->createKtr([
            'heat_number' => 'HEAT-C7-POS',
            'qty_good' => 100,
            'unit_weight_kg' => 2.1923076923077,
            'line_number' => 1,
            'current_stage' => 'netto',
        ]);
        $eNettoPos = $this->executionService->markPhysicalDone($ktrPositive->traveler_number, 'netto', $this->spvNetto->id);
        $this->executionService->recordDefectQty($eNettoPos, 22, $this->adminUser->id);

        // 1. Four-line view
        $response4Line = $this->actingAs($this->adminUser)
            ->get(route('sand-casting.kanban.stage', 'bubut-od'));
        $response4Line->assertOk();
        $response4Line->assertSee('Heat HEAT-C7-ZERO');
        $response4Line->assertSee('Heat HEAT-C7-POS');
        $response4Line->assertSee('MENUNGGU VERIFIKASI QC'); // shown for HEAT-C7-ZERO

        // 2. Single-line view
        $responseSingle = $this->actingAs($this->adminUser)
            ->get(route('sand-casting.kanban.stage', ['stage' => 'bubut-od', 'line_number' => 1]));
        $responseSingle->assertOk();
        $responseSingle->assertSee('Heat HEAT-C7-ZERO');
        $responseSingle->assertSee('Heat HEAT-C7-POS');
        $responseSingle->assertSee('MENUNGGU VERIFIKASI QC'); // shown for HEAT-C7-ZERO
    }

    /**
     * Kondisi 8:
     * Ringkasan Kanban (READY PCS, READY KG, card count) tetap akurat dan tidak terpengaruh oleh perubahan tampilan kartu.
     */
    public function test_condition_8_kanban_summary_totals_accurate_with_pending_qc_cards(): void
    {
        // Card 1: 0 PCS / 0 KG (WAITING_QC)
        $ktr1 = $this->createKtr([
            'heat_number' => 'HEAT-C8-ZERO',
            'qty_good' => 10,
            'unit_weight_kg' => 2.0,
            'line_number' => 1,
            'current_stage' => 'netto',
        ]);
        $e1 = $this->executionService->markPhysicalDone($ktr1->traveler_number, 'netto', $this->spvNetto->id);
        $this->executionService->recordDefectQty($e1, 10, $this->adminUser->id);

        // Card 2: 78 PCS / 171 KG (WAITING_QC)
        $ktr2 = $this->createKtr([
            'heat_number' => 'HEAT-C8-PENDING-POS',
            'qty_good' => 100,
            'unit_weight_kg' => 2.1923076923077,
            'line_number' => 1,
            'current_stage' => 'netto',
        ]);
        $e2 = $this->executionService->markPhysicalDone($ktr2->traveler_number, 'netto', $this->spvNetto->id);
        $this->executionService->recordDefectQty($e2, 22, $this->adminUser->id);

        // Card 3: 10 PCS / 25 KG (Normal READY, no pending defect)
        $this->createKtr([
            'heat_number' => 'HEAT-C8-NORMAL',
            'qty_good' => 10,
            'unit_weight_kg' => 2.5,
            'total_weight_kg' => 25.0,
            'line_number' => 2,
            'current_stage' => 'bubut_od',
        ]);

        $kanbanData = $this->queryService->getStageKanbanData('bubut_od');
        $this->assertEquals(3, $kanbanData['summary']['ready_count']);
        $this->assertEquals(88, $kanbanData['summary']['ready_qty']); // 0 + 78 + 10 = 88 PCS

        $response = $this->actingAs($this->adminUser)
            ->get(route('sand-casting.kanban.stage', 'bubut-od'));
        $response->assertOk();
        // Total READY summary is 88 PCS and 196 KG (0 + 171 + 25)
        $response->assertSee('88');
        $response->assertSee('196');
    }
}
