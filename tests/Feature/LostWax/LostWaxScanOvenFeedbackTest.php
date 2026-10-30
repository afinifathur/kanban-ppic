<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxItemReference;
use App\Models\LostWaxTree;
use App\Models\LostWaxWorkOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LostWaxScanOvenFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

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
        string $barcode = '1110826001',
        string $familyCode = '1',
        bool $requireLayer7 = false,
        ?string $currentStage = 'layer_6'
    ): LostWaxTree {
        $reference = LostWaxItemReference::create([
            'master_source' => 'masterdata_kpi',
            'master_item_key' => 'LY026',
            'item_code_snapshot' => 'LY026',
            'item_name_snapshot' => 'Hex Nipple SUS304',
            'aisi_snapshot' => 'SCS 13 A',
            'standard_snapshot' => 'JIS',
            'unit_weight_snapshot' => 0.300,
        ]);

        $workOrder = LostWaxWorkOrder::create([
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
            'work_order_id' => $workOrder->id,
            'barcode' => $barcode,
            'tree_number' => 1,
            'quantity' => 10,
            'status' => 'ready_for_coating',
            'production_date' => '2026-10-30',
            'family_code' => $familyCode,
            'daily_sequence' => 1,
            'require_layer_7' => $requireLayer7,
            'current_stage' => $currentStage,
            'last_scan_at' => Carbon::now()->subMinutes(30),
        ]);
    }

    public function test_valid_scan_oven_transaction_triggers_success(): void
    {
        $tree = $this->createTreeWithBarcode('1110826001', currentStage: 'layer_6');

        $response = $this->actingAs($this->user)
            ->postJson(route('lost-wax.scan-oven.process'), [
                'barcode' => $tree->barcode,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'stage_label' => 'Oven',
        ]);
        $this->assertNotNull($response->json('tree_info'));
        $this->assertSame('Hex Nipple SUS304', $response->json('tree_info.item_name'));
        $this->assertSame('oven', $response->json('tree.current_stage'));
    }

    public function test_empty_barcode_triggers_failed(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('lost-wax.scan-oven.process'), [
                'barcode' => '   ',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'reason' => 'Barcode tidak boleh kosong.',
        ]);
    }

    public function test_unknown_barcode_triggers_failed(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('lost-wax.scan-oven.process'), [
                'barcode' => 'UNKNOWN_OVEN_BARCODE',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'reason' => 'Barcode tidak ditemukan.',
        ]);
    }

    public function test_invalid_oven_stage_condition_triggers_failed(): void
    {
        // Tree is only at layer_2 (must complete layer 6 before entering oven)
        $tree = $this->createTreeWithBarcode('1110826002', currentStage: 'layer_2');

        $response = $this->actingAs($this->user)
            ->postJson(route('lost-wax.scan-oven.process'), [
                'barcode' => $tree->barcode,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
        ]);
        $this->assertStringContainsString('Tree masih berada di', $response->json('reason'));
    }

    public function test_tree_already_in_oven_triggers_failed(): void
    {
        $tree = $this->createTreeWithBarcode('1110826003', currentStage: 'oven');

        $response = $this->actingAs($this->user)
            ->postJson(route('lost-wax.scan-oven.process'), [
                'barcode' => $tree->barcode,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'reason' => 'Tree sudah masuk Oven / sudah selesai.',
        ]);
    }

    public function test_scan_oven_within_20_minutes_triggers_failed(): void
    {
        $now = Carbon::create(2026, 10, 30, 9, 0, 0);
        Carbon::setTestNow($now);

        $tree = $this->createTreeWithBarcode('1110826004', currentStage: 'layer_6');
        $tree->update(['last_scan_at' => $now->copy()->subMinutes(5)]); // Only 5 mins ago

        $response = $this->actingAs($this->user)
            ->postJson(route('lost-wax.scan-oven.process'), [
                'barcode' => $tree->barcode,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
        ]);
        $this->assertStringContainsString('Interval scan terlalu singkat', $response->json('reason'));

        Carbon::setTestNow(null);
    }

    public function test_scan_oven_blade_view_renders_two_feedback_states_and_local_audio(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('lost-wax.scan-oven.index'));

        $response->assertOk();

        // 1. Existing elements preserved
        $response->assertSee('SCAN OVEN');
        $response->assertSee('SIAP SCAN OVEN');
        $response->assertSee('SCAN BARCODE OVEN');

        // 2. Audio activation button
        $response->assertSee('audioToggleBtn');
        $response->assertSee('AKTIFKAN SUARA');
        $response->assertSee('toggleAudio()');

        // 3. Full-page feedback banner with headings
        $response->assertSee('fullFeedbackBanner');
        $response->assertSee('SCAN BERHASIL');
        $response->assertSee('SCAN GAGAL');

        // 4. Green color styling for success (#DCFCE7 background, #166534 text, #86EFAC border)
        $response->assertSee('#DCFCE7');
        $response->assertSee('#166534');
        $response->assertSee('#86EFAC');

        // 5. Red color styling for failure (#FEE2E2 background, #991B1B text, #FCA5A5 border)
        $response->assertSee('#FEE2E2');
        $response->assertSee('#991B1B');
        $response->assertSee('#FCA5A5');

        // 6. Local MP3 audio paths and volume
        $response->assertSee('audio/lost-wax/success-bell.mp3');
        $response->assertSee('audio/lost-wax/failed-buzzer.mp3');
        $response->assertSee('const AUDIO_VOLUME = 0.8;', false);
        $response->assertSee('playSuccessSound');
        $response->assertSee('playErrorSound');
        $response->assertSee('stopAllAudio');

        // 7. Five-second timeout and race-safe generation counter
        $response->assertSee('5000');
        $response->assertSee('clearFeedback');
        $response->assertSee('currentFeedbackId');
        $response->assertSee('thisFeedbackId');

        // 8. Asynchronous sequence ordering protection
        $response->assertSee('thisRequestSeq < latestHandledRequestSeq', false);
        $response->assertSee('latestHandledRequestSeq = thisRequestSeq', false);

        // 9. Input remains focused and is cleared immediately
        $response->assertSee('scanInput.focus()');
        $this->assertStringContainsString("scanInput.value = ''", $response->getContent());

        // 10. Offline verification: No external audio dependencies
        $content = $response->getContent();
        $this->assertStringNotContainsString('https://', $content);
        $this->assertStringNotContainsString('pixabay', strtolower($content));
        $this->assertStringNotContainsString('motionarray', strtolower($content));
    }
}

