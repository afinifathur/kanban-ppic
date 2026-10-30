<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxItemReference;
use App\Models\LostWaxTree;
use App\Models\LostWaxWorkOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LostWaxScannerFeedbackTest extends TestCase
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
        bool $requireLayer7 = false
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
            'status' => 'generated',
            'production_date' => '2026-10-30',
            'family_code' => $familyCode,
            'daily_sequence' => 1,
            'require_layer_7' => $requireLayer7,
            'current_stage' => null,
            'last_scan_at' => null,
        ]);
    }

    public function test_valid_barcode_successfully_processed_returns_success(): void
    {
        $tree = $this->createTreeWithBarcode('1110826001');

        $response = $this->actingAs($this->user)
            ->postJson(route('lost-wax.scan.process'), [
                'barcode' => $tree->barcode,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);
        $this->assertNotNull($response->json('tree_info'));
        $this->assertSame('Hex Nipple SUS304', $response->json('tree_info.item_name'));
        $this->assertSame('layer_1', $response->json('tree.current_stage'));
    }

    public function test_unknown_barcode_returns_failed_state(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('lost-wax.scan.process'), [
                'barcode' => 'NON_EXISTENT_BARCODE',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'reason' => 'Barcode tidak ditemukan.',
        ]);
    }

    public function test_empty_barcode_returns_failed_state(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('lost-wax.scan.process'), [
                'barcode' => '   ',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'reason' => 'Barcode tidak boleh kosong.',
        ]);
    }

    public function test_barcode_duplicated_within_20_minutes_returns_failed_state(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 30, 8, 0, 0));

        $tree = $this->createTreeWithBarcode('1110826002');

        // First scan at 08:00
        $firstResponse = $this->actingAs($this->user)
            ->postJson(route('lost-wax.scan.process'), [
                'barcode' => $tree->barcode,
            ]);
        $firstResponse->assertJson(['success' => true]);

        // Duplicate scan 5 minutes later at 08:05 (< 20 mins)
        Carbon::setTestNow(Carbon::create(2026, 10, 30, 8, 5, 0));

        $secondResponse = $this->actingAs($this->user)
            ->postJson(route('lost-wax.scan.process'), [
                'barcode' => $tree->barcode,
            ]);

        $secondResponse->assertStatus(200);
        $secondResponse->assertJson([
            'success' => false,
        ]);
        $this->assertStringContainsString('Interval scan terlalu singkat', $secondResponse->json('reason'));

        Carbon::setTestNow(null);
    }

    public function test_wrong_expected_stage_returns_failed_state(): void
    {
        $tree = $this->createTreeWithBarcode('1110826003');

        // Expected stage is layer_3, but tree is at beginning (needs layer_1)
        $response = $this->actingAs($this->user)
            ->postJson(route('lost-wax.scan.process'), [
                'barcode' => $tree->barcode,
                'expected_stage' => 'layer_3',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
        ]);
        $this->assertStringContainsString('Lapisan berikutnya harus', $response->json('reason'));
    }

    public function test_tree_already_completed_all_stages_returns_failed_state(): void
    {
        $tree = $this->createTreeWithBarcode('1110826004');
        $tree->update(['current_stage' => 'layer_6']); // Layer 6 is final when require_layer_7 is false

        $response = $this->actingAs($this->user)
            ->postJson(route('lost-wax.scan.process'), [
                'barcode' => $tree->barcode,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'reason' => 'Tree sudah menyelesaikan semua tahapan.',
        ]);
    }

    public function test_scanner_blade_view_renders_two_feedback_states_and_audio_controls(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('lost-wax.scan.index'));

        $response->assertOk();

        // 1. Audio activation button
        $response->assertSee('audioToggleBtn');
        $response->assertSee('AKTIFKAN SUARA');
        $response->assertSee('toggleAudio()');

        // 2. Full-page feedback banner with exact text requirements
        $response->assertSee('fullFeedbackBanner');
        $response->assertSee('SCAN BERHASIL');
        $response->assertSee('SCAN GAGAL');

        // 3. Green color styling for success (#DCFCE7 background, #166534 text, #86EFAC border)
        $response->assertSee('#DCFCE7');
        $response->assertSee('#166534');
        $response->assertSee('#86EFAC');

        // 4. Red color styling for failure (#FEE2E2 background, #991B1B text, #FCA5A5 border)
        $response->assertSee('#FEE2E2');
        $response->assertSee('#991B1B');
        $response->assertSee('#FCA5A5');

        // 5. Local MP3 audio functions and asset paths
        $response->assertSee('playSuccessSound');
        $response->assertSee('playErrorSound');
        $response->assertSee('stopAllAudio');
        $response->assertSee('audio/lost-wax/success-bell.mp3');
        $response->assertSee('audio/lost-wax/failed-buzzer.mp3');
        $response->assertSee('AUDIO_VOLUME = 0.8', false);

        // 6. Five-second timeout mechanism and race-safe generation counter
        $response->assertSee('5000');
        $response->assertSee('clearFeedback');
        $response->assertSee('currentFeedbackId');
        $response->assertSee('thisFeedbackId');

        // 7. Input remains active and focused
        $response->assertSee('scanInput.focus()');
        $this->assertStringContainsString("scanInput.value = ''", $response->getContent());

        // 8. Error handling maps 419/401 and !r.ok to FAILED state
        $this->assertStringContainsString("showError({ reason: 'Sesi kedaluwarsa atau otentikasi gagal.", $response->getContent());
        $response->assertSee('Terjadi kesalahan server');
        $response->assertSee('Gagal terhubung ke server (Network Error).');
    }

    public function test_local_mp3_files_exist_in_public_directory(): void
    {
        $successFile = public_path('audio/lost-wax/success-bell.mp3');
        $failedFile = public_path('audio/lost-wax/failed-buzzer.mp3');

        $this->assertFileExists($successFile);
        $this->assertFileExists($failedFile);

        $this->assertGreaterThan(1000, filesize($successFile), 'success-bell.mp3 must be a valid non-empty audio file');
        $this->assertGreaterThan(1000, filesize($failedFile), 'failed-buzzer.mp3 must be a valid non-empty audio file');
    }

    public function test_local_mp3_audio_asset_specifications_in_blade(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('lost-wax.scan.index'));

        $content = $response->getContent();

        // 1. Audio preloading and native HTMLAudioElement instantiation
        $this->assertStringContainsString('new Audio(SUCCESS_AUDIO_URL)', $content);
        $this->assertStringContainsString('new Audio(FAILED_AUDIO_URL)', $content);
        $this->assertStringContainsString("preload = 'auto'", $content);

        // 2. Volume configuration constant
        $this->assertStringContainsString('const AUDIO_VOLUME = 0.8;', $content);

        // 3. Reset playback position before replaying
        $this->assertStringContainsString('currentTime = 0', $content);

        // 4. Stop previous sound on rapid successive scan
        $this->assertStringContainsString('stopAllAudio()', $content);

        // 5. Offline verification: No external audio dependencies or URLs
        $this->assertStringNotContainsString('https://', $content);
        $this->assertStringNotContainsString('pixabay', strtolower($content));
        $this->assertStringNotContainsString('motionarray', strtolower($content));
    }

    public function test_rapid_scan_and_timeout_cleanup_in_blade(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('lost-wax.scan.index'));

        $content = $response->getContent();

        // Managed timeout and sequence ID check
        $this->assertStringContainsString('currentFeedbackId++', $content);
        $this->assertStringContainsString('clearTimeout(feedbackTimeoutId)', $content);
        $this->assertStringContainsString('currentFeedbackId === thisFeedbackId', $content);
        $this->assertStringContainsString('clearFeedback()', $content);
    }
}
