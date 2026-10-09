<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\User;
use App\Services\SandCasting\SandCastingProductionFloorQueryService;
use App\Services\SandCasting\SandCastingStageExecutionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class KanbanAgingAndFifoCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $spvNetto;

    protected User $spvBubutOd;

    protected User $spvBubutCnc;

    protected User $spvBor;

    protected User $qcInspector;

    protected SandCastingStageExecutionService $executionService;

    protected SandCastingProductionFloorQueryService $queryService;

    protected function setUp(): void
    {
        parent::setUp();

        $spvRole = Role::firstOrCreate(['name' => 'spv']);

        $this->admin = User::create([
            'name' => 'Admin PPIC',
            'email' => 'admin_fifo_test@peroniks.com',
            'password' => bcrypt('password'),
        ]);

        $this->spvNetto = User::create([
            'name' => 'SPV Netto',
            'email' => 'spv_netto_fifo@peroniks.com',
            'password' => bcrypt('password'),
            'assigned_stage' => 'netto',
        ]);
        $this->spvNetto->assignRole('spv');

        $this->spvBubutOd = User::create([
            'name' => 'SPV Bubut OD',
            'email' => 'spv_od_fifo@peroniks.com',
            'password' => bcrypt('password'),
            'assigned_stage' => 'bubut_od',
        ]);
        $this->spvBubutOd->assignRole('spv');

        $this->spvBubutCnc = User::create([
            'name' => 'SPV Bubut CNC',
            'email' => 'spv_cnc_fifo@peroniks.com',
            'password' => bcrypt('password'),
            'assigned_stage' => 'bubut_cnc',
        ]);
        $this->spvBubutCnc->assignRole('spv');

        $this->spvBor = User::create([
            'name' => 'SPV Bor',
            'email' => 'spv_bor_fifo@peroniks.com',
            'password' => bcrypt('password'),
            'assigned_stage' => 'bor',
        ]);
        $this->spvBor->assignRole('spv');

        $this->qcInspector = User::create([
            'name' => 'QC Inspector',
            'email' => 'qc_fifo_test@peroniks.com',
            'password' => bcrypt('password'),
        ]);

        $this->executionService = new SandCastingStageExecutionService;
        $this->queryService = new SandCastingProductionFloorQueryService($this->executionService);
    }

    protected function createKtrLine(array $attributes = []): SandCastingCastingResultLine
    {
        $plan = ProductionPlan::create([
            'code' => '268ET'.rand(100, 999),
            'title' => 'Rencana SC '.rand(100, 999),
            'item_code' => '4.'.rand(100, 999),
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-'.rand(100, 999),
            'line_number' => $attributes['line_number'] ?? 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'customer' => 'PT SINAR METAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260920-'.rand(1000, 9999),
            'scheduled_date' => '2026-09-20',
            'status' => 'ISSUED',
            'created_by' => $this->admin->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '2"',
            'aisi' => 'FC250',
        ]);

        $castDate = $attributes['cast_date'] ?? '2026-09-20';
        $heatNumber = $attributes['heat_number'] ?? ('A2'.date('dmy', strtotime($castDate)).sprintf('%02d', rand(1, 18)));

        $result = isset($attributes['sand_casting_casting_result_id'])
            ? SandCastingCastingResult::find($attributes['sand_casting_casting_result_id'])
            : SandCastingCastingResult::create([
                'heat_number' => $heatNumber,
                'cast_date' => $castDate,
                'shift' => 1,
                'furnace' => 'F1',
                'recorded_by' => $this->admin->id,
            ]);

        $lineAttributes = array_merge([
            'sand_casting_casting_result_id' => $result->id,
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-'.str_replace('-', '', $castDate).'-'.sprintf('%04d', rand(1000, 9999)),
            'qty_good' => $attributes['qty_good'] ?? 100,
            'qty_reject' => 0,
            'unit_weight_kg' => 4.50,
            'total_weight_kg' => 450.00,
            'current_stage' => $attributes['current_stage'] ?? 'netto',
            'queue_position' => $attributes['queue_position'] ?? null,
            'is_urgent' => $attributes['is_urgent'] ?? false,
        ], $attributes);

        // Remove virtual helper keys before creating DB model
        unset($lineAttributes['cast_date'], $lineAttributes['heat_number'], $lineAttributes['line_number']);

        return SandCastingCastingResultLine::create($lineAttributes);
    }

    /**
     * 1. Netto aging menggunakan cast_date, bukan created_at
     */
    public function test_1_netto_aging_uses_cast_date_not_created_at(): void
    {
        Carbon::setTestNow('2026-10-09 10:00:00');

        $result = SandCastingCastingResult::create([
            'heat_number' => 'A218092604',
            'cast_date' => '2026-09-18', // 21 days ago
            'shift' => 1,
            'furnace' => 'F1',
            'recorded_by' => $this->admin->id,
        ]);

        // Simulated record inserted only 2 days ago
        $ktr = $this->createKtrLine([
            'sand_casting_casting_result_id' => $result->id,
            'current_stage' => 'netto',
            'created_at' => Carbon::parse('2026-10-07 14:00:00'),
        ]);

        $card = $this->queryService->resolveKanbanCard($ktr);

        $this->assertNotNull($card);
        $this->assertEquals(21, $card['aging']['total_aging_days']);
        $this->assertEquals(21, $card['aging']['stage_aging_days']);
        // 21 days * 24h + 10 hours = 514 hours
        $this->assertEquals(514, $card['aging']['stage_aging_hours']);
        $this->assertEquals('21h', $card['aging']['stage_aging_label']);

        Carbon::setTestNow();
    }

    /**
     * 2. Tanggal cor lama yang baru diinput tetap memiliki aging lama
     */
    public function test_2_old_cast_date_freshly_created_maintains_old_aging(): void
    {
        Carbon::setTestNow('2026-10-09 08:00:00');

        $result = SandCastingCastingResult::create([
            'heat_number' => 'A201092601',
            'cast_date' => '2026-09-01', // 38 days ago
            'shift' => 1,
            'furnace' => 'F1',
            'recorded_by' => $this->admin->id,
        ]);

        // Input 5 minutes ago
        $ktr = $this->createKtrLine([
            'sand_casting_casting_result_id' => $result->id,
            'current_stage' => 'netto',
            'created_at' => Carbon::parse('2026-10-09 07:55:00'),
        ]);

        $card = $this->queryService->resolveKanbanCard($ktr);

        $this->assertEquals(38, $card['aging']['total_aging_days']);
        $this->assertEquals(38, $card['aging']['stage_aging_days']);
        $this->assertEquals(920, $card['aging']['stage_aging_hours']); // 38*24 + 8 = 920

        Carbon::setTestNow();
    }

    /**
     * 3. Netto sorting menggunakan cast_date lalu nomor urut tuang numerik
     */
    public function test_3_netto_sorting_orders_by_cast_date_then_numeric_pouring_sequence(): void
    {
        // Same cast date (2026-09-20), different pouring sequence: 01, 02, 04
        $res04 = SandCastingCastingResult::create([
            'heat_number' => 'A220092604',
            'cast_date' => '2026-09-20',
            'recorded_by' => $this->admin->id,
        ]);
        $res01 = SandCastingCastingResult::create([
            'heat_number' => 'A220092601',
            'cast_date' => '2026-09-20',
            'recorded_by' => $this->admin->id,
        ]);
        $res02 = SandCastingCastingResult::create([
            'heat_number' => 'A220092602',
            'cast_date' => '2026-09-20',
            'recorded_by' => $this->admin->id,
        ]);

        // Insert in reverse order
        $ktr04 = $this->createKtrLine(['sand_casting_casting_result_id' => $res04->id, 'current_stage' => 'netto']);
        $ktr01 = $this->createKtrLine(['sand_casting_casting_result_id' => $res01->id, 'current_stage' => 'netto']);
        $ktr02 = $this->createKtrLine(['sand_casting_casting_result_id' => $res02->id, 'current_stage' => 'netto']);

        $data = $this->queryService->getStageKanbanData('netto');
        $ready = $data['ready'];

        $this->assertCount(3, $ready);
        $this->assertEquals($ktr01->traveler_number, $ready[0]['traveler_number'], 'Pouring 01 must be first');
        $this->assertEquals($ktr02->traveler_number, $ready[1]['traveler_number'], 'Pouring 02 must be second');
        $this->assertEquals($ktr04->traveler_number, $ready[2]['traveler_number'], 'Pouring 04 must be third');
    }

    /**
     * 4. Nomor 02 mendahului 10 (mencegah lexicographical sorting bug)
     */
    public function test_4_pouring_sequence_02_precedes_10_numeric_comparison(): void
    {
        $res10 = SandCastingCastingResult::create([
            'heat_number' => 'A220092610',
            'cast_date' => '2026-09-20',
            'recorded_by' => $this->admin->id,
        ]);
        $res02 = SandCastingCastingResult::create([
            'heat_number' => 'A220092602',
            'cast_date' => '2026-09-20',
            'recorded_by' => $this->admin->id,
        ]);

        $ktr10 = $this->createKtrLine(['sand_casting_casting_result_id' => $res10->id, 'current_stage' => 'netto']);
        $ktr02 = $this->createKtrLine(['sand_casting_casting_result_id' => $res02->id, 'current_stage' => 'netto']);

        $data = $this->queryService->getStageKanbanData('netto');
        $ready = $data['ready'];

        $this->assertCount(2, $ready);
        $this->assertEquals($ktr02->traveler_number, $ready[0]['traveler_number'], 'Sequence 2 must precede sequence 10');
        $this->assertEquals($ktr10->traveler_number, $ready[1]['traveler_number']);
    }

    /**
     * 5. Heat Number invalid menggunakan fallback deterministik tanpa crash
     */
    public function test_5_invalid_heat_number_uses_deterministic_fallback(): void
    {
        $resValid = SandCastingCastingResult::create([
            'heat_number' => 'A220092601',
            'cast_date' => '2026-09-20',
            'recorded_by' => $this->admin->id,
        ]);
        $resInvalid = SandCastingCastingResult::create([
            'heat_number' => 'NON-STANDARD-HEAT',
            'cast_date' => '2026-09-20',
            'recorded_by' => $this->admin->id,
        ]);

        $ktrValid = $this->createKtrLine(['sand_casting_casting_result_id' => $resValid->id, 'current_stage' => 'netto']);
        $ktrInvalid = $this->createKtrLine(['sand_casting_casting_result_id' => $resInvalid->id, 'current_stage' => 'netto']);

        $data = $this->queryService->getStageKanbanData('netto');
        $ready = $data['ready'];

        $this->assertCount(2, $ready);
        // Valid pouring sequence (1) precedes fallback (999999)
        $this->assertEquals($ktrValid->traveler_number, $ready[0]['traveler_number']);
        $this->assertEquals($ktrInvalid->traveler_number, $ready[1]['traveler_number']);
    }

    /**
     * 6. Downstream FIFO memakai timestamp physical completion tahap sebelumnya
     */
    public function test_6_downstream_fifo_uses_predecessor_physical_done_timestamp(): void
    {
        // KTR 1 and KTR 2 created at Netto
        $ktr1 = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'netto']);
        $ktr2 = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'netto']);

        // KTR 1 completes Netto at 10:00 (arrives at Bubut OD at 10:00)
        Carbon::setTestNow('2026-10-09 10:00:00');
        $this->executionService->markPhysicalDone($ktr1->traveler_number, 'netto', $this->spvNetto->id);

        // KTR 2 completes Netto at 10:15 (arrives at Bubut OD at 10:15)
        Carbon::setTestNow('2026-10-09 10:15:00');
        $this->executionService->markPhysicalDone($ktr2->traveler_number, 'netto', $this->spvNetto->id);

        // Check Bubut OD kanban board at 11:00
        Carbon::setTestNow('2026-10-09 11:00:00');
        $odData = $this->queryService->getStageKanbanData('bubut_od');
        $ready = $odData['ready'];

        $this->assertCount(2, $ready);
        // KTR 1 entered OD earlier (10:00) so must be #1
        $this->assertEquals($ktr1->traveler_number, $ready[0]['traveler_number']);
        $this->assertEquals(1, $ready[0]['aging']['stage_aging_hours']); // 1 hour waiting in OD

        // KTR 2 entered OD later (10:15) so must be #2
        $this->assertEquals($ktr2->traveler_number, $ready[1]['traveler_number']);
        $this->assertEquals(0, $ready[1]['aging']['stage_aging_hours']); // 45 min = 0 full hours

        Carbon::setTestNow();
    }

    /**
     * 7. cast_date tidak mendahului timestamp antrean downstream (FIFO downstream priority)
     */
    public function test_7_cast_date_does_not_override_downstream_queue_entry_fifo(): void
    {
        // KTR Old Cast (cast Sept 10) vs KTR New Cast (cast Sept 25)
        $resOldCast = SandCastingCastingResult::create([
            'heat_number' => 'A210092601',
            'cast_date' => '2026-09-10',
            'recorded_by' => $this->admin->id,
        ]);
        $resNewCast = SandCastingCastingResult::create([
            'heat_number' => 'A225092601',
            'cast_date' => '2026-09-25',
            'recorded_by' => $this->admin->id,
        ]);

        $ktrOld = $this->createKtrLine(['sand_casting_casting_result_id' => $resOldCast->id, 'current_stage' => 'netto']);
        $ktrNew = $this->createKtrLine(['sand_casting_casting_result_id' => $resNewCast->id, 'current_stage' => 'netto']);

        // KTR New completes Netto EARLIER at 08:00 (enters OD at 08:00)
        Carbon::setTestNow('2026-10-09 08:00:00');
        $this->executionService->markPhysicalDone($ktrNew->traveler_number, 'netto', $this->spvNetto->id);

        // KTR Old completes Netto LATER at 11:00 (enters OD at 11:00)
        Carbon::setTestNow('2026-10-09 11:00:00');
        $this->executionService->markPhysicalDone($ktrOld->traveler_number, 'netto', $this->spvNetto->id);

        // Check Bubut OD Kanban
        $odData = $this->queryService->getStageKanbanData('bubut_od');
        $ready = $odData['ready'];

        $this->assertCount(2, $ready);
        // Even though ktrOld has an older cast date (Sept 10), ktrNew arrived at OD first (08:00 vs 11:00)
        $this->assertEquals($ktrNew->traveler_number, $ready[0]['traveler_number'], 'Newer cast item that entered OD first must be ahead in OD queue');
        $this->assertEquals($ktrOld->traveler_number, $ready[1]['traveler_number']);

        Carbon::setTestNow();
    }

    /**
     * 8. Timestamp identik tetap deterministik melalui id ASC
     */
    public function test_8_identical_timestamp_uses_deterministic_id_tie_breaker(): void
    {
        Carbon::setTestNow('2026-10-09 10:00:00');

        $ktr1 = $this->createKtrLine(['current_stage' => 'netto']);
        $ktr2 = $this->createKtrLine(['current_stage' => 'netto']);

        // Mark done in the exact same second
        $this->executionService->markPhysicalDone($ktr1->traveler_number, 'netto', $this->spvNetto->id);
        $this->executionService->markPhysicalDone($ktr2->traveler_number, 'netto', $this->spvNetto->id);

        $odData1 = $this->queryService->getStageKanbanData('bubut_od');
        $odData2 = $this->queryService->getStageKanbanData('bubut_od');

        // Order is 100% deterministic and identical on multiple reads
        $this->assertEquals($odData1['ready'][0]['traveler_number'], $odData2['ready'][0]['traveler_number']);
        $this->assertEquals($ktr1->traveler_number, $odData1['ready'][0]['traveler_number']);
        $this->assertEquals($ktr2->traveler_number, $odData1['ready'][1]['traveler_number']);

        Carbon::setTestNow();
    }

    /**
     * 9. Queue position PPIC tetap mengalahkan prioritas lainnya sesuai existing behavior
     */
    public function test_9_manual_queue_position_beats_all_fifo_priorities(): void
    {
        // Normal item entering first
        $ktrNormal = $this->createKtrLine([
            'is_urgent' => false,
            'queue_position' => null,
            'current_stage' => 'bubut_od',
        ]);

        // Urgent item
        $ktrUrgent = $this->createKtrLine([
            'is_urgent' => true,
            'queue_position' => null,
            'current_stage' => 'bubut_od',
        ]);

        // Manual queue position item set by PPIC to position #1
        $ktrManual = $this->createKtrLine([
            'is_urgent' => false,
            'queue_position' => 1,
            'current_stage' => 'bubut_od',
        ]);

        $odData = $this->queryService->getStageKanbanData('bubut_od');
        $ready = $odData['ready'];

        $this->assertCount(3, $ready);
        $this->assertEquals($ktrManual->traveler_number, $ready[0]['traveler_number'], 'Manual queue position #1 must come first');
        $this->assertEquals($ktrUrgent->traveler_number, $ready[1]['traveler_number'], 'Urgent must come second');
        $this->assertEquals($ktrNormal->traveler_number, $ready[2]['traveler_number'], 'Normal must come third');
    }

    /**
     * 10. Urgent tetap mengalahkan FIFO normal sesuai existing behavior
     */
    public function test_10_urgent_beats_normal_downstream_fifo(): void
    {
        // Normal item entered OD at 08:00
        $ktrNormal = $this->createKtrLine(['is_urgent' => false, 'current_stage' => 'netto']);
        Carbon::setTestNow('2026-10-09 08:00:00');
        $this->executionService->markPhysicalDone($ktrNormal->traveler_number, 'netto', $this->spvNetto->id);

        // Urgent item entered OD LATER at 11:00
        $ktrUrgent = $this->createKtrLine(['is_urgent' => true, 'current_stage' => 'netto']);
        Carbon::setTestNow('2026-10-09 11:00:00');
        $this->executionService->markPhysicalDone($ktrUrgent->traveler_number, 'netto', $this->spvNetto->id);

        $odData = $this->queryService->getStageKanbanData('bubut_od');
        $ready = $odData['ready'];

        $this->assertCount(2, $ready);
        $this->assertEquals($ktrUrgent->traveler_number, $ready[0]['traveler_number'], 'Urgent item must precede normal item regardless of entry time');
        $this->assertEquals($ktrNormal->traveler_number, $ready[1]['traveler_number']);

        Carbon::setTestNow();
    }

    /**
     * 11. Transisi stage dan reset queue position tetap berfungsi
     */
    public function test_11_stage_transition_resets_queue_position_and_establishes_new_stage_aging(): void
    {
        $ktr = $this->createKtrLine([
            'qty_good' => 60,
            'current_stage' => 'netto',
            'queue_position' => 2,
        ]);

        Carbon::setTestNow('2026-10-09 10:00:00');
        $execNetto = $this->executionService->markPhysicalDone($ktr->traveler_number, 'netto', $this->spvNetto->id);

        // Verify stage advanced to bubut_od and queue_position reset to null
        $ktr->refresh();
        $this->assertEquals('bubut_od', $ktr->current_stage);
        $this->assertNull($ktr->queue_position);

        // Advance to Bubut CNC at 12:00
        Carbon::setTestNow('2026-10-09 12:00:00');
        $execOd = $this->executionService->markPhysicalDone($ktr->traveler_number, 'bubut_od', $this->spvBubutOd->id);

        $ktr->refresh();
        $this->assertEquals('bubut_cnc', $ktr->current_stage);
        $this->assertNull($ktr->queue_position);

        // Check Bubut CNC Kanban at 15:00
        Carbon::setTestNow('2026-10-09 15:00:00');
        $cncData = $this->queryService->getStageKanbanData('bubut_cnc');
        $card = $cncData['ready'][0];

        $this->assertEquals($ktr->traveler_number, $card['traveler_number']);
        // Aging in CNC is 3 hours (from 12:00 to 15:00)
        $this->assertEquals(3, $card['aging']['stage_aging_hours']);
        $this->assertEquals('3j', $card['aging']['stage_aging_label']);

        Carbon::setTestNow();
    }

    /**
     * 12. Aging dinamis bertambah ketika waktu berjalan (Carbon travel)
     */
    public function test_12_dynamic_aging_advances_as_time_passes(): void
    {
        Carbon::setTestNow('2026-10-01 00:00:00');

        $result = SandCastingCastingResult::create([
            'heat_number' => 'A201102601',
            'cast_date' => '2026-10-01',
            'recorded_by' => $this->admin->id,
        ]);

        $ktr = $this->createKtrLine([
            'sand_casting_casting_result_id' => $result->id,
            'current_stage' => 'netto',
        ]);

        // Day 1
        $cardDay1 = $this->queryService->resolveKanbanCard($ktr);
        $this->assertEquals(0, $cardDay1['aging']['stage_aging_days']);

        // Fast-forward 5 days without any scanner mutation
        Carbon::setTestNow('2026-10-06 14:00:00');

        $cardDay6 = $this->queryService->resolveKanbanCard($ktr);
        $this->assertEquals(5, $cardDay6['aging']['stage_aging_days']);
        $this->assertEquals(134, $cardDay6['aging']['stage_aging_hours']); // 5*24 + 14 = 134

        Carbon::setTestNow();
    }

    /**
     * 13. Timezone konsisten dengan Asia/Jakarta
     */
    public function test_13_timezone_consistency_with_asia_jakarta(): void
    {
        $this->assertEquals('Asia/Jakarta', config('app.timezone'));

        Carbon::setTestNow(Carbon::parse('2026-10-09 12:00:00', 'Asia/Jakarta'));

        $result = SandCastingCastingResult::create([
            'heat_number' => 'A208102601',
            'cast_date' => '2026-10-08',
            'recorded_by' => $this->admin->id,
        ]);

        $ktr = $this->createKtrLine([
            'sand_casting_casting_result_id' => $result->id,
            'current_stage' => 'netto',
        ]);

        $card = $this->queryService->resolveKanbanCard($ktr);

        // From 2026-10-08 00:00:00 to 2026-10-09 12:00:00 is 36 hours (1 day 12 hours)
        $this->assertEquals(1, $card['aging']['stage_aging_days']);
        $this->assertEquals(36, $card['aging']['stage_aging_hours']);

        Carbon::setTestNow();
    }
}
