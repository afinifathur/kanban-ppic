<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxCoatingRack;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ScanSiapCorVerificationPopupTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $unauthorizedUser;

    protected static int $seq = 1000;

    protected function setUp(): void
    {
        parent::setUp();

        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution']);
        $accessPlanning = Permission::firstOrCreate(['name' => 'access_planning']);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->syncPermissions([$accessExecution, $accessPlanning]);

        $this->user = User::factory()->create([
            'id' => 3,
            'name' => 'Admin PPIC',
            'email' => 'adminppicpf@peroniks.com',
        ]);
        $this->user->assignRole($adminRole);

        $this->unauthorizedUser = User::factory()->create([
            'id' => 99,
            'name' => 'Unauthorized Operator',
            'email' => 'unauth@peroniks.com',
        ]);
    }

    protected function createTree(array $attributes = []): LostWaxTree
    {
        $currentSeq = self::$seq++;

        return LostWaxTree::create(array_merge([
            'barcode' => 'TREE-POP-'.$currentSeq,
            'tree_number' => $currentSeq,
            'quantity' => 20,
            'status' => 'generated',
            'current_stage' => 'oven',
            'family_code' => '1',
            'daily_sequence' => $currentSeq,
            'production_date' => Carbon::now()->toDateString(),
        ], $attributes));
    }

    protected function createScanEvent(LostWaxTree $tree, string $stage, ?Carbon $scannedAt = null, string $result = 'success'): LostWaxScanEvent
    {
        return LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => $stage,
            'scanned_at' => $scannedAt ?? Carbon::now(),
            'operator_id' => $this->user->id,
            'result' => $result,
            'source' => 'physical_scan',
        ]);
    }

    /**
     * 1. Scan valid menampilkan data preview tanpa mengubah database.
     */
    public function test_01_preview_valid_scan_returns_preview_data_without_database_changes(): void
    {
        $rack = LostWaxCoatingRack::create([
            'rack_number' => 12,
            'label' => 'Buffer Dewaxing Timur',
            'status' => 'active',
        ]);

        $tree = $this->createTree([
            'quantity' => 30,
            'rack_id' => $rack->id,
        ]);
        $this->createScanEvent($tree, 'oven', Carbon::now()->subHour());

        $eventsBefore = LostWaxScanEvent::count();

        $response = $this->actingAs($this->user)->postJson(route('lost-wax.scan-siap-cor.preview'), [
            'barcode' => $tree->barcode,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'code' => 'PREVIEW_READY',
            'message' => 'DATA TERSEDIA — MENUNGGU KONFIRMASI',
            'tree_info' => [
                'barcode' => $tree->barcode,
                'quantity_usable' => 30,
                'rack_location' => 'Rak 12 (Buffer Dewaxing Timur)',
            ],
        ]);

        // Assert NO database modifications
        $this->assertEquals($eventsBefore, LostWaxScanEvent::count());
        $tree->refresh();
        $this->assertEquals('oven', $tree->current_stage);
    }

    /**
     * 2. Batal tidak membuat event siap_cor.
     */
    public function test_02_cancel_action_does_not_create_siap_cor_event(): void
    {
        $tree = $this->createTree();
        $this->createScanEvent($tree, 'oven', Carbon::now()->subHour());

        // Step 1: Preview requested
        $this->actingAs($this->user)->postJson(route('lost-wax.scan-siap-cor.preview'), [
            'barcode' => $tree->barcode,
        ])->assertStatus(200);

        // Operator decides to cancel (no confirm request sent)
        $this->assertEquals(0, LostWaxScanEvent::where('stage', 'siap_cor')->count());
        $tree->refresh();
        $this->assertEquals('oven', $tree->current_stage);
    }

    /**
     * 3. Konfirmasi valid membuat tepat satu event.
     */
    public function test_03_confirm_valid_creates_exactly_one_event(): void
    {
        $tree = $this->createTree();
        $this->createScanEvent($tree, 'oven', Carbon::now()->subHour());

        $response = $this->actingAs($this->user)->postJson(route('lost-wax.scan-siap-cor.process'), [
            'barcode' => $tree->barcode,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'code' => 'SUCCESS',
        ]);

        $siapCorEvents = LostWaxScanEvent::where('tree_id', $tree->id)->where('stage', 'siap_cor')->get();
        $this->assertCount(1, $siapCorEvents);
        $this->assertEquals('success', $siapCorEvents->first()->result);
        $this->assertEquals('physical_scan', $siapCorEvents->first()->source);
    }

    /**
     * 4. Konfirmasi memperbarui stage menjadi siap_cor.
     */
    public function test_04_confirm_updates_stage_to_siap_cor(): void
    {
        $tree = $this->createTree();
        $this->createScanEvent($tree, 'oven', Carbon::now()->subHour());

        $this->actingAs($this->user)->postJson(route('lost-wax.scan-siap-cor.process'), [
            'barcode' => $tree->barcode,
        ])->assertStatus(200);

        $tree->refresh();
        $this->assertEquals('siap_cor', $tree->current_stage);
        $this->assertNotNull($tree->last_scan_at);
    }

    /**
     * 5. Barcode tidak dikenal ditolak.
     */
    public function test_05_unknown_barcode_is_rejected(): void
    {
        // On preview
        $previewResp = $this->actingAs($this->user)->postJson(route('lost-wax.scan-siap-cor.preview'), [
            'barcode' => 'UNKNOWN-999999',
        ]);
        $previewResp->assertStatus(200);
        $previewResp->assertJson([
            'success' => false,
            'code' => 'NOT_FOUND',
            'reason' => 'GAGAL — BARCODE TIDAK DITEMUKAN',
        ]);

        // On confirm
        $confirmResp = $this->actingAs($this->user)->postJson(route('lost-wax.scan-siap-cor.process'), [
            'barcode' => 'UNKNOWN-999999',
        ]);
        $confirmResp->assertStatus(200);
        $confirmResp->assertJson([
            'success' => false,
            'code' => 'NOT_FOUND',
        ]);
    }

    /**
     * 6. Barcode yang sudah siap_cor ditolak.
     */
    public function test_06_already_siap_cor_barcode_is_rejected(): void
    {
        $tree = $this->createTree(['current_stage' => 'siap_cor']);
        $this->createScanEvent($tree, 'oven', Carbon::now()->subHours(3));
        $this->createScanEvent($tree, 'siap_cor', Carbon::now()->subHour());

        $response = $this->actingAs($this->user)->postJson(route('lost-wax.scan-siap-cor.preview'), [
            'barcode' => $tree->barcode,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'code' => 'ALREADY_SCANNED',
            'reason' => 'GAGAL — SUDAH PERNAH SCAN SIAP COR',
        ]);
    }

    /**
     * 7. Pohon cancelled ditolak.
     */
    public function test_07_cancelled_tree_is_rejected(): void
    {
        $tree = $this->createTree([
            'status' => 'cancelled',
            'current_stage' => 'oven',
        ]);
        $this->createScanEvent($tree, 'oven', Carbon::now()->subHours(2));

        $response = $this->actingAs($this->user)->postJson(route('lost-wax.scan-siap-cor.preview'), [
            'barcode' => $tree->barcode,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'code' => 'CANCELLED',
            'reason' => 'GAGAL — TRAVELER CARD TELAH DIBATALKAN',
        ]);
    }

    /**
     * 8. Pohon yang sudah masuk pengecoran ditolak.
     */
    public function test_08_tree_already_consumed_in_casting_is_rejected(): void
    {
        // Tree consumed in casting result
        $tree = $this->createTree([
            'current_stage' => 'cor',
        ]);
        $this->createScanEvent($tree, 'oven', Carbon::now()->subHours(4));
        $this->createScanEvent($tree, 'siap_cor', Carbon::now()->subHours(2));

        $response = $this->actingAs($this->user)->postJson(route('lost-wax.scan-siap-cor.preview'), [
            'barcode' => $tree->barcode,
        ]);

        $response->assertStatus(200);
        $this->assertFalse($response->json('success'));
        $this->assertContains($response->json('code'), ['ALREADY_CAST', 'ALREADY_SCANNED', 'NOT_OVEN_READY']);
    }

    /**
     * 9. Double-submit tidak menghasilkan duplikasi.
     */
    public function test_09_double_submit_does_not_create_duplicate_events(): void
    {
        $tree = $this->createTree();
        $this->createScanEvent($tree, 'oven', Carbon::now()->subHour());

        // First submit: succeeds
        $resp1 = $this->actingAs($this->user)->postJson(route('lost-wax.scan-siap-cor.process'), [
            'barcode' => $tree->barcode,
        ]);
        $resp1->assertJson(['success' => true]);

        // Second immediate submit: rejected as already scanned
        $resp2 = $this->actingAs($this->user)->postJson(route('lost-wax.scan-siap-cor.process'), [
            'barcode' => $tree->barcode,
        ]);
        $resp2->assertJson(['success' => false, 'code' => 'ALREADY_SCANNED']);

        // Assert strictly 1 success scan event
        $this->assertEquals(1, LostWaxScanEvent::where('tree_id', $tree->id)->where('stage', 'siap_cor')->where('result', 'success')->count());
    }

    /**
     * 10. Data yang berubah setelah preview ditolak saat konfirmasi.
     */
    public function test_10_data_changed_after_preview_is_rejected_on_confirm(): void
    {
        $tree = $this->createTree();
        $this->createScanEvent($tree, 'oven', Carbon::now()->subHour());

        // Step 1: Preview requested when tree is valid
        $previewResp = $this->actingAs($this->user)->postJson(route('lost-wax.scan-siap-cor.preview'), [
            'barcode' => $tree->barcode,
        ]);
        $previewResp->assertJson(['success' => true]);

        // Intervening event: tree gets cancelled before operator hits confirm
        $tree->update(['status' => 'cancelled']);

        // Step 2: Operator confirms, backend must re-validate and reject
        $confirmResp = $this->actingAs($this->user)->postJson(route('lost-wax.scan-siap-cor.process'), [
            'barcode' => $tree->barcode,
        ]);
        $confirmResp->assertJson([
            'success' => false,
            'code' => 'CANCELLED',
        ]);

        $this->assertEquals(0, LostWaxScanEvent::where('tree_id', $tree->id)->where('stage', 'siap_cor')->where('result', 'success')->count());
    }

    /**
     * 11. User tanpa izin ditolak.
     */
    public function test_11_unauthorized_user_is_forbidden(): void
    {
        $tree = $this->createTree();
        $this->createScanEvent($tree, 'oven', Carbon::now()->subHour());

        // Enforce strict Spatie permission check on Gate
        $gate = \Illuminate\Support\Facades\Gate::getFacadeRoot();
        $ref = new \ReflectionClass($gate);
        $prop = $ref->getProperty('beforeCallbacks');
        $prop->setAccessible(true);
        $prop->setValue($gate, [
            function ($user, $ability) {
                if (method_exists($user, 'hasPermissionTo')) {
                    try {
                        return $user->hasPermissionTo($ability) ? true : null;
                    } catch (\Throwable $e) {
                        return null;
                    }
                }

                return null;
            },
        ]);

        $previewResp = $this->actingAs($this->unauthorizedUser)->postJson(route('lost-wax.scan-siap-cor.preview'), [
            'barcode' => $tree->barcode,
        ]);
        $previewResp->assertStatus(403);

        $confirmResp = $this->actingAs($this->unauthorizedUser)->postJson(route('lost-wax.scan-siap-cor.process'), [
            'barcode' => $tree->barcode,
        ]);
        $confirmResp->assertStatus(403);
    }

    /**
     * 12. Scan normal yang sudah ada tetap berfungsi.
     */
    public function test_12_normal_direct_scan_still_works(): void
    {
        $tree = $this->createTree();
        $this->createScanEvent($tree, 'oven', Carbon::now()->subHour());

        // Direct call to scan-siap-cor.process (e.g. from USB scanner or direct API)
        $response = $this->actingAs($this->user)->postJson(route('lost-wax.scan-siap-cor.process'), [
            'barcode' => $tree->barcode,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'code' => 'SUCCESS',
            'message' => 'BERHASIL — SELESAI DEWAXING',
            'tree_info' => [
                'barcode' => $tree->barcode,
            ],
        ]);

        $tree->refresh();
        $this->assertEquals('siap_cor', $tree->current_stage);
    }

    /**
     * 13. Halaman Scan Selesai Dewaxing memuat tombol kamera, viewport kamera, dan library ZXing.
     */
    public function test_13_scan_siap_cor_view_renders_camera_scanner_elements_and_zxing_library(): void
    {
        $response = $this->actingAs($this->user)->get(route('lost-wax.scan-siap-cor.index'));

        $response->assertStatus(200);
        $response->assertSee('btnToggleCamera');
        $response->assertSee('Aktifkan Kamera');
        $response->assertSee('Matikan Kamera');
        $response->assertSee('cameraScannerArea');
        $response->assertSee('cameraVideo');
        $response->assertSee('zxing-browser.min.js');
        $response->assertSee('scanInput');
        $response->assertSee('verifyModal');
        $response->assertSee('BATAL');
        $response->assertSee('KONFIRMASI');
    }
}
