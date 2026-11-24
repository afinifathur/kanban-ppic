<?php

namespace Tests\Feature\Fitting;

use App\Models\FittingItem;
use App\Models\FittingStageExecution;
use App\Models\FittingTransfer;
use App\Models\LostWaxCastingResult;
use App\Models\LostWaxKitir;
use App\Models\User;
use App\Services\Fitting\FittingKanbanQueryService;
use App\Services\Fitting\FittingStageAuthorizationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FittingKanbanAgingTest extends TestCase
{
    use RefreshDatabase;

    private User $ppicFitting;

    private FittingKanbanQueryService $queryService;

    protected function setUp(): void
    {
        parent::setUp();

        putenv('FITTING_SPV_INITIAL_PASSWORD=FitPass123!');
        $_ENV['FITTING_SPV_INITIAL_PASSWORD'] = 'FitPass123!';

        (new \Database\Seeders\FittingSpvUserSeeder)->run();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->ppicFitting = User::create([
            'email' => 'ppicfitting@peroniks.com',
            'name' => 'PPIC Fitting Test',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'product_scope' => 'FITTING_STAINLESS',
        ]);
        $this->ppicFitting->assignRole(FittingStageAuthorizationService::ROLE_ADMIN_PPIC_FITTING);

        $this->queryService = app(FittingKanbanQueryService::class);
    }

    private function createFittingItemWithStage(string $stage, ?Carbon $createdAt = null): FittingItem
    {
        $transfer = FittingTransfer::create([
            'transfer_number' => 'TRF-TEST-'.uniqid(),
            'transfer_date' => now()->subDays(3)->format('Y-m-d'),
            'transferred_by' => $this->ppicFitting->id,
            'total_kitirs' => 1,
            'total_pcs' => 50,
            'total_weight_kg' => 25.0,
            'status' => FittingTransfer::STATUS_ACTIVE,
        ]);

        $castingResult = LostWaxCastingResult::create([
            'heat_number' => 'HEAT-'.uniqid(),
            'cast_date' => now()->format('Y-m-d'),
            'furnace' => '1',
            'shift' => '1',
            'operator_name' => 'Operator',
            'total_trees' => 1,
            'total_pcs' => 50,
            'status' => 'COMPLETED',
            'recorded_by' => $this->ppicFitting->id,
        ]);

        $kitir = LostWaxKitir::create([
            'lost_wax_casting_result_id' => $castingResult->id,
            'traveler_number' => 'FKTR-'.uniqid(),
            'production_code' => 'CODE-TEST',
            'customer' => 'CUST-TEST',
            'item_name' => 'FITTING ELBOW 90 1"',
            'quantity_pcs' => 50,
            'issued_at' => now(),
            'issued_by' => $this->ppicFitting->id,
        ]);

        $item = FittingItem::create([
            'fitting_transfer_id' => $transfer->id,
            'lost_wax_kitir_id' => $kitir->id,
            'lost_wax_casting_result_id' => $castingResult->id,
            'traveler_number' => 'PFKTR-'.uniqid(),
            'heat_number' => 'HEAT-'.uniqid(),
            'production_code' => 'CODE-TEST',
            'item_code' => 'ITEM-001',
            'item_name' => 'FITTING ELBOW 90 1"',
            'customer' => 'CUST-TEST',
            'line_number' => 1,
            'qty_transferred' => 50,
            'unit_weight_kg' => 0.5,
            'total_weight_kg' => 25.0,
            'current_stage' => $stage,
            'status' => FittingItem::STATUS_ACTIVE,
        ]);

        if ($createdAt !== null) {
            $item->created_at = $createdAt;
            $item->save();
        }

        return $item;
    }

    public function test_01_netto_stage_entry_uses_created_at_and_fallbacks_to_transfer_date(): void
    {
        // 1. With created_at set to 15 hours ago
        $tenHoursAgo = Carbon::now()->subHours(15);
        $itemWithCreated = $this->createFittingItemWithStage(FittingItem::STAGE_NETTO_PF, $tenHoursAgo);

        $resolved = $this->queryService->resolveStageEntryTimestamp($itemWithCreated, FittingItem::STAGE_NETTO_PF);
        $this->assertEquals($tenHoursAgo->toDateTimeString(), $resolved->toDateTimeString());

        // 2. Fallback when created_at is null: uses transfer_date at startOfDay
        $itemWithoutCreated = $this->createFittingItemWithStage(FittingItem::STAGE_NETTO_PF);
        $itemWithoutCreated->created_at = null;
        $itemWithoutCreated->save();

        $resolvedFallback = $this->queryService->resolveStageEntryTimestamp($itemWithoutCreated, FittingItem::STAGE_NETTO_PF);
        $expectedDate = $itemWithoutCreated->transfer->transfer_date->copy()->startOfDay();
        $this->assertEquals($expectedDate->toDateTimeString(), $resolvedFallback->toDateTimeString());
    }

    public function test_02_downstream_stages_use_predecessor_checkpoint_physical_done_at(): void
    {
        // Check stage KIMIA uses NETTO checkpoint physical_done_at
        $itemKimia = $this->createFittingItemWithStage(FittingItem::STAGE_KIMIA, now()->subDays(5));

        $nettoDoneAt = Carbon::now()->subHours(6);
        FittingStageExecution::create([
            'fitting_item_id' => $itemKimia->id,
            'stage' => FittingItem::STAGE_NETTO_PF,
            'checkpoint_code' => FittingStageExecution::CHECKPOINT_NETTO,
            'input_qty' => 50,
            'good_qty' => 50,
            'defect_qty' => 0,
            'status' => FittingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->ppicFitting->id,
            'executed_at' => $nettoDoneAt->copy()->subMinutes(10),
            'physical_done_at' => $nettoDoneAt,
        ]);

        $itemKimia->load('stageExecutions');
        $resolvedKimia = $this->queryService->resolveStageEntryTimestamp($itemKimia, FittingItem::STAGE_KIMIA);
        $this->assertEquals($nettoDoneAt->toDateTimeString(), $resolvedKimia->toDateTimeString());

        // Check stage BUBUT_CNC_PF uses MARKING checkpoint physical_done_at
        $itemCnc = $this->createFittingItemWithStage(FittingItem::STAGE_BUBUT_CNC_PF, now()->subDays(5));
        $markingDoneAt = Carbon::now()->subHours(3);
        FittingStageExecution::create([
            'fitting_item_id' => $itemCnc->id,
            'stage' => FittingItem::STAGE_MARKING,
            'checkpoint_code' => FittingStageExecution::CHECKPOINT_MARKING,
            'input_qty' => 50,
            'good_qty' => 50,
            'defect_qty' => 0,
            'status' => FittingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->ppicFitting->id,
            'executed_at' => $markingDoneAt,
            'physical_done_at' => $markingDoneAt,
        ]);

        $itemCnc->load('stageExecutions');
        $resolvedCnc = $this->queryService->resolveStageEntryTimestamp($itemCnc, FittingItem::STAGE_BUBUT_CNC_PF);
        $this->assertEquals($markingDoneAt->toDateTimeString(), $resolvedCnc->toDateTimeString());
    }

    public function test_03_stage_aging_resets_upon_stage_progression(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00'));

        // Item created in Netto 48 hours ago
        $createdAt = Carbon::parse('2026-11-22 12:00:00');
        $item = $this->createFittingItemWithStage(FittingItem::STAGE_NETTO_PF, $createdAt);

        // Before Netto completion: Netto stage aging is 48h
        $agingNetto = $this->queryService->calculateAging($item, FittingItem::STAGE_NETTO_PF);
        $this->assertEquals(48, $agingNetto['stage_aging_hours']);
        $this->assertEquals(2, $agingNetto['stage_aging_days']);
        $this->assertEquals('2h', $agingNetto['stage_aging_label']);
        $this->assertEquals('Aging 2D 00H', $agingNetto['label']);

        // Now Netto completes 2 hours ago (10:00), item advances to KIMIA
        $nettoDoneAt = Carbon::parse('2026-11-24 10:00:00');
        FittingStageExecution::create([
            'fitting_item_id' => $item->id,
            'stage' => FittingItem::STAGE_NETTO_PF,
            'checkpoint_code' => FittingStageExecution::CHECKPOINT_NETTO,
            'input_qty' => 50,
            'good_qty' => 50,
            'defect_qty' => 0,
            'status' => FittingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->ppicFitting->id,
            'executed_at' => $nettoDoneAt,
            'physical_done_at' => $nettoDoneAt,
        ]);
        $item->current_stage = FittingItem::STAGE_KIMIA;
        $item->save();
        $item->load('stageExecutions');

        // At KIMIA: stage aging is reset to 2 hours! (NOT 48 hours)
        $agingKimia = $this->queryService->calculateAging($item, FittingItem::STAGE_KIMIA);
        $this->assertEquals(2, $agingKimia['stage_aging_hours']);
        $this->assertEquals(0, $agingKimia['stage_aging_days']);
        $this->assertEquals('2j', $agingKimia['stage_aging_label']);
        $this->assertEquals('Aging 02H', $agingKimia['label']);

        Carbon::setTestNow();
    }

    public function test_04_aging_labels_and_threshold_boundaries(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00'));

        $testCases = [
            [
                'hours_ago' => 5,
                'expected_hours' => 5,
                'expected_days' => 0,
                'expected_stage_label' => '5j',
                'expected_label' => 'Aging 05H',
            ],
            [
                'hours_ago' => 25,
                'expected_hours' => 25,
                'expected_days' => 1,
                'expected_stage_label' => '1h',
                'expected_label' => 'Aging 1D 01H',
            ],
            [
                'hours_ago' => 119, // Just under 5 days (120h) -> GREEN
                'expected_hours' => 119,
                'expected_days' => 4,
                'expected_stage_label' => '4h',
                'expected_label' => 'Aging 4D 23H',
            ],
            [
                'hours_ago' => 120, // Exactly 5 days (120h) -> YELLOW
                'expected_hours' => 120,
                'expected_days' => 5,
                'expected_stage_label' => '5h',
                'expected_label' => 'Aging 5D 00H',
            ],
            [
                'hours_ago' => 168, // Exactly 7 days (168h) -> YELLOW boundary
                'expected_hours' => 168,
                'expected_days' => 7,
                'expected_stage_label' => '7h',
                'expected_label' => 'Aging 7D 00H',
            ],
            [
                'hours_ago' => 169, // Over 7 days (>168h) -> RED
                'expected_hours' => 169,
                'expected_days' => 7,
                'expected_stage_label' => '7h',
                'expected_label' => 'Aging 7D 01H',
            ],
        ];

        foreach ($testCases as $case) {
            $entryAt = Carbon::now()->subHours($case['hours_ago']);
            $item = $this->createFittingItemWithStage(FittingItem::STAGE_NETTO_PF, $entryAt);

            $aging = $this->queryService->calculateAging($item, FittingItem::STAGE_NETTO_PF);

            $this->assertEquals($case['expected_hours'], $aging['stage_aging_hours'], "Failed for {$case['hours_ago']}h (stage_aging_hours)");
            $this->assertEquals($case['expected_days'], $aging['stage_aging_days'], "Failed for {$case['hours_ago']}h (stage_aging_days)");
            $this->assertEquals($case['expected_stage_label'], $aging['stage_aging_label'], "Failed for {$case['hours_ago']}h (stage_aging_label)");
            $this->assertEquals($case['expected_label'], $aging['label'], "Failed for {$case['hours_ago']}h (label)");

            // Verify backward compatibility keys
            $this->assertEquals($case['expected_hours'], $aging['hours']);
            $this->assertEquals($case['expected_days'], $aging['days']);
        }

        Carbon::setTestNow();
    }

    public function test_05_format_kanban_card_and_stage_kanban_data_contain_standardized_dto(): void
    {
        $item = $this->createFittingItemWithStage(FittingItem::STAGE_NETTO_PF, now()->subHours(14));

        $card = $this->queryService->formatKanbanCard($item, FittingItem::STAGE_NETTO_PF);

        $this->assertArrayHasKey('aging', $card);
        $this->assertArrayHasKey('stage_aging_hours', $card['aging']);
        $this->assertArrayHasKey('stage_aging_days', $card['aging']);
        $this->assertArrayHasKey('stage_aging_label', $card['aging']);
        $this->assertArrayHasKey('hours', $card['aging']);
        $this->assertArrayHasKey('days', $card['aging']);
        $this->assertArrayHasKey('label', $card['aging']);

        $this->assertEquals(14, $card['aging']['stage_aging_hours']);
        $this->assertEquals('Aging 14H', $card['aging']['label']);
        $this->assertEquals('14j', $card['aging']['stage_aging_label']);

        // Test through getStageKanbanData
        $kanbanData = $this->queryService->getStageKanbanData(FittingItem::STAGE_NETTO_PF);
        $this->assertNotEmpty($kanbanData['cards']);
        $retrievedCard = collect($kanbanData['cards'])->firstWhere('id', $item->id);
        $this->assertNotNull($retrievedCard);
        $this->assertEquals('Aging 14H', $retrievedCard['aging']['label']);
    }

    public function test_06_web_kanban_renders_aging_badge_with_color_thresholds_and_preserves_card_info(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00'));

        // 1. Green card (<120h, e.g. 5h)
        $itemGreen = $this->createFittingItemWithStage(FittingItem::STAGE_NETTO_PF, Carbon::now()->subHours(5));
        $itemGreen->traveler_number = 'PFKTR-GREEN-005';
        $itemGreen->customer = 'CUST-GREEN';
        $itemGreen->save();

        // 2. Yellow card (120-168h, e.g. 125h)
        $itemYellow = $this->createFittingItemWithStage(FittingItem::STAGE_NETTO_PF, Carbon::now()->subHours(125));
        $itemYellow->traveler_number = 'PFKTR-YELLOW-125';
        $itemYellow->customer = 'CUST-YELLOW';
        $itemYellow->save();

        // 3. Red card (>168h, e.g. 175h)
        $itemRed = $this->createFittingItemWithStage(FittingItem::STAGE_NETTO_PF, Carbon::now()->subHours(175));
        $itemRed->traveler_number = 'PFKTR-RED-175';
        $itemRed->customer = 'CUST-RED';
        $itemRed->save();

        $response = $this->actingAs($this->ppicFitting)->get(route('fitting.kanban.stage', 'netto-pf'));
        $response->assertOk();

        $html = $response->getContent();

        // Verify Green badge
        $this->assertStringContainsString('Aging 05H', $html);
        $this->assertStringContainsString('data-aging-color="green"', $html);
        $this->assertStringContainsString('bg-emerald-50 text-emerald-700 border-emerald-200', $html);
        $this->assertStringContainsString('bg-emerald-500', $html);

        // Verify Yellow badge
        $this->assertStringContainsString('Aging 5D 05H', $html);
        $this->assertStringContainsString('data-aging-color="yellow"', $html);
        $this->assertStringContainsString('bg-amber-50 text-amber-800 border-amber-300', $html);
        $this->assertStringContainsString('bg-amber-500', $html);

        // Verify Red badge
        $this->assertStringContainsString('Aging 7D 07H', $html);
        $this->assertStringContainsString('data-aging-color="red"', $html);
        $this->assertStringContainsString('bg-rose-50 text-rose-700 border-rose-200', $html);
        $this->assertStringContainsString('bg-rose-500', $html);

        // Verify existing card elements remain intact
        $this->assertStringContainsString('[CUST-GREEN]', $html);
        $this->assertStringContainsString('[CUST-YELLOW]', $html);
        $this->assertStringContainsString('[CUST-RED]', $html);
        $this->assertStringContainsString('PFKTR-GREEN-005', $html);
        $this->assertStringContainsString('PFKTR-YELLOW-125', $html);
        $this->assertStringContainsString('PFKTR-RED-175', $html);
        $this->assertStringContainsString('NETTO PF', $html);

        Carbon::setTestNow();
    }

    public function test_07_overdue_red_card_remains_scannable_and_fifo_preserved(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00'));

        // Oldest card (180h ago) -> RED
        $itemOld = $this->createFittingItemWithStage(FittingItem::STAGE_NETTO_PF, Carbon::now()->subHours(180));
        $itemOld->traveler_number = 'PFKTR-OLD-180';
        $itemOld->save();

        // Newer card (10h ago) -> GREEN
        $itemNew = $this->createFittingItemWithStage(FittingItem::STAGE_NETTO_PF, Carbon::now()->subHours(10));
        $itemNew->traveler_number = 'PFKTR-NEW-010';
        $itemNew->save();

        // 1. Kanban FIFO check: oldest card must be sorted first in queue (queue_seq #1)
        $kanbanData = $this->queryService->getStageKanbanData(FittingItem::STAGE_NETTO_PF);
        $cards = $kanbanData['cards'];
        $firstCard = $cards[0];
        $secondCard = $cards[1];

        $this->assertEquals($itemOld->id, $firstCard['id']);
        $this->assertEquals(1, $firstCard['queue_seq']);
        $this->assertEquals('red', $firstCard['aging']['stage_aging_hours'] > 168 ? 'red' : 'green');

        $this->assertEquals($itemNew->id, $secondCard['id']);
        $this->assertEquals(2, $secondCard['queue_seq']);

        // 2. Overdue Red card remains 100% scannable via Scanner service
        $scannerPayload = $this->queryService->findTravelerForScanner($itemOld->traveler_number, FittingItem::STAGE_NETTO_PF);
        $this->assertNotNull($scannerPayload);
        $this->assertTrue($scannerPayload['is_eligible_for_stage']);
        $this->assertNull($scannerPayload['ineligible_reason']);

        Carbon::setTestNow();
    }
}
