<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxItemReference;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\LostWaxWorkOrder;
use App\Models\User;
use App\Services\ScanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScanOvenTest extends TestCase
{
    use RefreshDatabase;

    private ScanService $scanService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scanService = new ScanService;

        $this->app->instance(
            \App\Contracts\ItemMasterRepository::class,
            new \Tests\Fakes\ArrayItemMasterRepository([
                [
                    'code' => 'LY026',
                    'name' => 'Hex Nipple SUS304',
                    'aisi' => 'SCS 13 A',
                    'standard' => 'JIS',
                    'unit_weight' => '0.300',
                    'status' => 'active',
                ],
            ])
        );
    }

    private function createTreeWithBarcode(
        ?string $barcode = '1110826001',
        string $familyCode = '1',
        bool $requireLayer7 = false
    ): LostWaxTree {
        $reference = LostWaxItemReference::create([
            'master_source' => 'masterdata_kpi',
            'master_item_key' => 'LY026',
            'item_code_snapshot' => 'LY026',
            'item_name_snapshot' => 'Hex Nipple SUS304',
            'aisi_snapshot' => 'SCS 13 A',
        ]);

        $wo = LostWaxWorkOrder::create([
            'item_reference_id' => $reference->id,
            'et_code' => 'ET232',
            'po_number' => 'PO-001',
            'po_quantity' => 1000,
            'stock_quantity' => 500,
            'net_requirement_quantity' => 500,
            'status' => 'active',
            'family_code' => $familyCode,
            'require_layer_7' => $requireLayer7,
        ]);

        return LostWaxTree::create([
            'work_order_id' => $wo->id,
            'barcode' => $barcode,
            'tree_number' => 1,
            'quantity' => 15,
            'status' => 'generated',
            'production_date' => '2026-08-11',
            'family_code' => $familyCode,
            'daily_sequence' => 1,
        ]);
    }

    private function advanceTreeToStage(LostWaxTree $tree, int $userId, int $targetLayer): void
    {
        for ($i = 1; $i <= $targetLayer; $i++) {
            Carbon::setTestNow(Carbon::create(2026, 8, 11, 8 + $i, 0, 0));
            $this->scanService->process($tree->barcode, $userId);
        }
    }

    public function test_scan_layer_6_to_oven_succeeds(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode('1110826001', '1', false);

        $this->advanceTreeToStage($tree, $user->id, 6);
        $tree->refresh();
        $this->assertSame('layer_6', $tree->current_stage);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 16, 0, 0));

        $result = $this->scanService->processOvenScan($tree->barcode, $user->id);

        $this->assertTrue($result['success']);
        $this->assertSame('oven', $result['tree']->current_stage);
        $this->assertSame('oven', $result['event']->stage);
        $this->assertSame('success', $result['event']->result);
        $this->assertSame('Oven', $result['stage_label']);

        $this->assertDatabaseHas('lost_wax_scan_events', [
            'tree_id' => $tree->id,
            'stage' => 'oven',
            'result' => 'success',
            'operator_id' => $user->id,
        ]);

        Carbon::setTestNow(null);
    }

    public function test_scan_layer_7_to_oven_succeeds(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode('1110826001', '1', true);

        $this->advanceTreeToStage($tree, $user->id, 7);
        $tree->refresh();
        $this->assertSame('layer_7', $tree->current_stage);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 17, 0, 0));

        $result = $this->scanService->processOvenScan($tree->barcode, $user->id);

        $this->assertTrue($result['success']);
        $this->assertSame('oven', $result['tree']->current_stage);
        $this->assertSame('oven', $result['event']->stage);

        Carbon::setTestNow(null);
    }

    public function test_before_scan_to_oven_rejected(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode();

        $this->assertNull($tree->current_stage);

        $result = $this->scanService->processOvenScan($tree->barcode, $user->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('belum memulai', $result['reason']);

        $tree->refresh();
        $this->assertNull($tree->current_stage);

        Carbon::setTestNow(null);
    }

    public function test_layer_1_to_oven_rejected(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode();

        $this->scanService->process($tree->barcode, $user->id);
        $tree->refresh();
        $this->assertSame('layer_1', $tree->current_stage);

        $result = $this->scanService->processOvenScan($tree->barcode, $user->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Lapisan 1', $result['reason']);
        $this->assertStringContainsString('Lapisan 6', $result['reason']);

        $tree->refresh();
        $this->assertSame('layer_1', $tree->current_stage);

        Carbon::setTestNow(null);
    }

    public function test_layer_2_to_oven_rejected(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode();

        $this->advanceTreeToStage($tree, $user->id, 2);
        $tree->refresh();
        $this->assertSame('layer_2', $tree->current_stage);

        $result = $this->scanService->processOvenScan($tree->barcode, $user->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Lapisan 2', $result['reason']);

        Carbon::setTestNow(null);
    }

    public function test_layer_3_to_oven_rejected(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode();

        $this->advanceTreeToStage($tree, $user->id, 3);
        $tree->refresh();
        $this->assertSame('layer_3', $tree->current_stage);

        $result = $this->scanService->processOvenScan($tree->barcode, $user->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Lapisan 3', $result['reason']);

        Carbon::setTestNow(null);
    }

    public function test_layer_4_to_oven_rejected(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode();

        $this->advanceTreeToStage($tree, $user->id, 4);
        $tree->refresh();
        $this->assertSame('layer_4', $tree->current_stage);

        $result = $this->scanService->processOvenScan($tree->barcode, $user->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Lapisan 4', $result['reason']);

        Carbon::setTestNow(null);
    }

    public function test_layer_5_to_oven_rejected(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode();

        $this->advanceTreeToStage($tree, $user->id, 5);
        $tree->refresh();
        $this->assertSame('layer_5', $tree->current_stage);

        $result = $this->scanService->processOvenScan($tree->barcode, $user->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Lapisan 5', $result['reason']);

        Carbon::setTestNow(null);
    }

    public function test_oven_to_oven_rejected(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode('1110826001', '1', false);

        $this->advanceTreeToStage($tree, $user->id, 6);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 16, 0, 0));
        $result = $this->scanService->processOvenScan($tree->barcode, $user->id);
        $this->assertTrue($result['success']);
        $this->assertSame('oven', $result['tree']->current_stage);

        $result2 = $this->scanService->processOvenScan($tree->barcode, $user->id);

        $this->assertFalse($result2['success']);
        $this->assertStringContainsString('sudah masuk Oven', $result2['reason']);

        Carbon::setTestNow(null);
    }

    public function test_invalid_barcode_rejected(): void
    {
        $user = User::factory()->create();

        $result = $this->scanService->processOvenScan('NOEXIST', $user->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('tidak ditemukan', $result['reason']);
    }

    public function test_successful_oven_scan_creates_audit_event(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode('1110826001', '1', false);

        $this->advanceTreeToStage($tree, $user->id, 6);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 16, 0, 0));

        $result = $this->scanService->processOvenScan($tree->barcode, $user->id);
        $this->assertTrue($result['success']);

        $event = LostWaxScanEvent::where('tree_id', $tree->id)
            ->where('stage', 'oven')
            ->where('result', 'success')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame($tree->barcode, $event->barcode);
        $this->assertSame($user->id, $event->operator_id);

        Carbon::setTestNow(null);
    }

    public function test_rejected_oven_scan_creates_anomaly_event(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode();

        $result = $this->scanService->processOvenScan($tree->barcode, $user->id);

        $this->assertFalse($result['success']);

        $anomaly = LostWaxScanEvent::where('tree_id', $tree->id)
            ->where('result', 'rejected')
            ->first();

        $this->assertNotNull($anomaly);
        $this->assertTrue($anomaly->is_anomaly);
        $this->assertStringContainsString('belum memulai', $anomaly->anomaly_reason);

        Carbon::setTestNow(null);
    }

    public function test_oven_scan_preserves_tree_quantity(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode('1110826001', '1', false);

        $this->advanceTreeToStage($tree, $user->id, 6);

        $tree->refresh();
        $originalQty = $tree->quantity;

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 16, 0, 0));
        $result = $this->scanService->processOvenScan($tree->barcode, $user->id);

        $this->assertTrue($result['success']);
        $this->assertSame($originalQty, $result['tree']->quantity);

        $tree->refresh();
        $this->assertSame($originalQty, $tree->quantity);

        Carbon::setTestNow(null);
    }

    public function test_oven_scan_preserves_barcode(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode('1110826001', '1', false);

        $this->advanceTreeToStage($tree, $user->id, 6);

        $originalBarcode = $tree->barcode;

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 16, 0, 0));
        $result = $this->scanService->processOvenScan($tree->barcode, $user->id);

        $this->assertTrue($result['success']);
        $this->assertSame($originalBarcode, $result['tree']->barcode);

        Carbon::setTestNow(null);
    }

    public function test_oven_scan_preserves_relationships(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode('1110826001', '1', false);

        $originalWoId = $tree->work_order_id;
        $originalPlanId = $tree->work_order_plan_id;
        $originalFamilyCode = $tree->family_code;

        $this->advanceTreeToStage($tree, $user->id, 6);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 16, 0, 0));
        $result = $this->scanService->processOvenScan($tree->barcode, $user->id);

        $this->assertTrue($result['success']);
        $this->assertSame($originalWoId, $result['tree']->work_order_id);
        $this->assertSame($originalPlanId, $result['tree']->work_order_plan_id);
        $this->assertSame($originalFamilyCode, $result['tree']->family_code);

        Carbon::setTestNow(null);
    }

    public function test_scan_lapisan_cannot_advance_oven_tree(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode('1110826001', '1', false);

        $this->advanceTreeToStage($tree, $user->id, 6);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 16, 0, 0));
        $result = $this->scanService->processOvenScan($tree->barcode, $user->id);
        $this->assertTrue($result['success']);

        $tree->refresh();
        $this->assertSame('oven', $tree->current_stage);
        $this->assertNull($tree->nextStage());

        $result2 = $this->scanService->process($tree->barcode, $user->id);
        $this->assertFalse($result2['success']);
        $this->assertStringContainsString('menyelesaikan semua tahapan', $result2['reason']);

        Carbon::setTestNow(null);
    }

    public function test_duplicate_oven_submission_rejected(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode('1110826001', '1', false);

        $this->advanceTreeToStage($tree, $user->id, 6);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 16, 0, 0));
        $result1 = $this->scanService->processOvenScan($tree->barcode, $user->id);
        $this->assertTrue($result1['success']);

        $result2 = $this->scanService->processOvenScan($tree->barcode, $user->id);
        $this->assertFalse($result2['success']);

        $successCount = LostWaxScanEvent::where('tree_id', $tree->id)
            ->where('stage', 'oven')
            ->where('result', 'success')
            ->count();

        $this->assertSame(1, $successCount);

        Carbon::setTestNow(null);
    }

    public function test_scan_oven_page_loads(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('lost-wax.scan-oven.index'))
            ->assertOk()
            ->assertSee('SCAN OVEN')
            ->assertSee('SIAP SCAN OVEN')
            ->assertSee('SCAN BARCODE OVEN');
    }

    public function test_scan_oven_endpoint_returns_json(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode('1110826001', '1', false);

        $this->advanceTreeToStage($tree, $user->id, 6);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 16, 0, 0));

        $response = $this->actingAs($user)
            ->postJson(route('lost-wax.scan-oven.process'), [
                'barcode' => $tree->barcode,
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $response->assertJsonPath('stage_label', 'Oven');

        Carbon::setTestNow(null);
    }

    public function test_existing_scan_lapisan_still_works(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode('1110826001', '1', false);

        $result = $this->scanService->process($tree->barcode, $user->id);
        $this->assertTrue($result['success']);
        $this->assertSame('layer_1', $result['tree']->current_stage);

        $this->actingAs($user)
            ->get(route('lost-wax.scan.index'))
            ->assertOk()
            ->assertSee('SCAN LAPISAN');

        Carbon::setTestNow(null);
    }

    public function test_oven_scan_records_aging(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode('1110826001', '1', false);

        $this->advanceTreeToStage($tree, $user->id, 6);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 18, 0, 0));

        $result = $this->scanService->processOvenScan($tree->barcode, $user->id);

        $this->assertTrue($result['success']);
        $this->assertSame(240, $result['event']->aging_minutes);
        $this->assertSame('normal', $result['aging_status']);

        Carbon::setTestNow(null);
    }

    public function test_layer_6_to_oven_works_when_require_layer_7(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode('1110826001', '1', true);

        $this->advanceTreeToStage($tree, $user->id, 6);
        $tree->refresh();
        $this->assertSame('layer_6', $tree->current_stage);

        Carbon::setTestNow(Carbon::create(2026, 8, 11, 16, 0, 0));
        $result = $this->scanService->processOvenScan($tree->barcode, $user->id);

        $this->assertTrue($result['success']);
        $this->assertSame('oven', $result['tree']->current_stage);

        Carbon::setTestNow(null);
    }

    public function test_scan_lapisan_on_layer_6_does_not_produce_oven(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode('1110826001', '1', false);

        $this->advanceTreeToStage($tree, $user->id, 6);
        $tree->refresh();
        $this->assertSame('layer_6', $tree->current_stage);

        $result = $this->scanService->process($tree->barcode, $user->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('menyelesaikan semua tahapan', $result['reason']);

        $tree->refresh();
        $this->assertNotSame('oven', $tree->current_stage);
        $this->assertSame('layer_6', $tree->current_stage);

        Carbon::setTestNow(null);
    }

    public function test_scan_lapisan_on_layer_7_does_not_produce_oven(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11, 8, 0, 0));

        $user = User::factory()->create();
        $tree = $this->createTreeWithBarcode('1110826001', '1', true);

        $this->advanceTreeToStage($tree, $user->id, 7);
        $tree->refresh();
        $this->assertSame('layer_7', $tree->current_stage);

        $result = $this->scanService->process($tree->barcode, $user->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('menyelesaikan semua tahapan', $result['reason']);

        $tree->refresh();
        $this->assertNotSame('oven', $tree->current_stage);
        $this->assertSame('layer_7', $tree->current_stage);

        Carbon::setTestNow(null);
    }
}
