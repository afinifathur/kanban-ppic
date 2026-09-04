<?php

namespace Tests\Feature\LostWax;

use App\Contracts\ItemMasterRepository;
use App\Models\LostWaxAssemblyPhoto;
use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxRangkaiWorkOrder;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Repositories\ArrayItemMasterRepository;
use App\Services\AssemblyPhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class AssemblyPhotoTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    private User $ppicUser;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $accessPlanning = \Spatie\Permission\Models\Permission::findOrCreate('access_planning');
        $accessExecution = \Spatie\Permission\Models\Permission::findOrCreate('access_execution');

        $adminRole = \Spatie\Permission\Models\Role::findOrCreate('admin');
        $ppicRole = \Spatie\Permission\Models\Role::findOrCreate('ppic');

        $adminRole->givePermissionTo([$accessPlanning, $accessExecution]);
        $ppicRole->givePermissionTo([$accessPlanning, $accessExecution]);

        $this->adminUser = User::factory()->create([
            'name' => 'Admin PPIC',
            'email' => 'adminppicpf@peroniks.com',
            'password' => bcrypt('password123'),
        ]);
        $this->adminUser->assignRole('admin');

        $this->ppicUser = User::factory()->create([
            'name' => 'PPIC Flange',
            'email' => 'ppicflange@peroniks.com',
            'password' => bcrypt('password123'),
        ]);
        $this->ppicUser->assignRole('ppic');

        // Bind mock item master repository
        $this->app->instance(ItemMasterRepository::class, new ArrayItemMasterRepository([
            [
                'code' => '4.801190FFBSP.G.A0020',
                'name' => 'SS304 ELBOW 90 F/F BSP 3/4"',
                'aisi' => 'ANSI',
                'standard' => 'CF8',
                'status' => 'active',
            ],
            [
                'code' => '268ETB733',
                'name' => 'SS304 SQUARE DN 25',
                'aisi' => '304',
                'standard' => 'JIS',
                'status' => 'active',
            ],
            [
                'code' => 'FL-DN50-304',
                'name' => 'FLANGE 10K DN 50 SS304',
                'aisi' => '304',
                'standard' => 'JIS 10K',
                'status' => 'active',
            ],
        ]));
    }

    public function test_assembly_photos_page_can_be_accessed_by_authorized_user(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/settings/assembly-photos');
        $response->assertOk();
        $response->assertSee('MASTER FOTO RANGKAI');
        $response->assertSee('productSearchInput');
        $response->assertSee('Daftar Master Foto');
    }

    public function test_unauthenticated_user_cannot_access_assembly_photos_page(): void
    {
        $response = $this->get('/settings/assembly-photos');
        $response->assertRedirect('/login');
    }

    public function test_product_search_endpoint_returns_matching_products(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/settings/assembly-photos/search?q=square');

        $response->assertOk();
        $response->assertJson([
            'success' => true,
        ]);
        $this->assertCount(1, $response->json('products'));
        $this->assertEquals('268ETB733', $response->json('products.0.code'));
        $this->assertEquals('SS304 SQUARE DN 25', $response->json('products.0.name'));
    }

    public function test_invalid_placeholder_xx_is_filtered_from_search_and_not_displayed_as_product_identity(): void
    {
        // Insert a dirty production plan with placeholder XX
        ProductionPlan::create([
            'code' => '268L651',
            'item_code' => 'XX',
            'item_name' => 'SS304 ELBOW 90 F/F BSP 3/4"',
            'po_number' => 'SL1200-1318',
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/settings/assembly-photos/search?q=ELBOW');

        $response->assertOk();
        $products = $response->json('products');

        $this->assertNotEmpty($products);
        foreach ($products as $p) {
            $this->assertNotEquals('XX', $p['code'], 'Placeholder XX must never be returned as product code.');
            $this->assertNotEquals('X09', $p['code']);
            $this->assertNotEquals('-', $p['code']);
        }

        // Must return the actual item code from master data
        $this->assertEquals('4.801190FFBSP.G.A0020', $products[0]['code']);
        $this->assertEquals('SS304 ELBOW 90 F/F BSP 3/4"', $products[0]['name']);
    }

    public function test_master_item_is_primary_product_identity(): void
    {
        $service = app(AssemblyPhotoService::class);
        $results = $service->searchProducts('ELBOW');

        $this->assertNotEmpty($results);
        $first = $results->first();
        $this->assertEquals('4.801190FFBSP.G.A0020', $first['code']);
        $this->assertEquals('SS304 ELBOW 90 F/F BSP 3/4"', $first['name']);
        $this->assertEquals('ANSI', $first['aisi']);
        $this->assertEquals('CF8', $first['standard']);
    }

    public function test_future_upload_cannot_save_placeholder_codes_like_xx(): void
    {
        $frontImage = UploadedFile::fake()->image('front.jpg', 800, 600);

        // Via HTTP request
        $response = $this->actingAs($this->adminUser)
            ->post('/settings/assembly-photos', [
                'product_code' => 'XX',
                'product_name' => 'Invalid Product',
                'front_photo' => $frontImage,
            ]);

        $response->assertSessionHas('error');
        $this->assertEquals(0, LostWaxAssemblyPhoto::where('product_code', 'XX')->count());

        // Via Service directly
        $service = app(AssemblyPhotoService::class);
        $this->expectException(InvalidArgumentException::class);
        $service->storePhoto('XX', 'Invalid Product', $frontImage);
    }

    public function test_upload_both_front_and_side_photos_creates_version_1(): void
    {
        $frontImage = UploadedFile::fake()->image('front.jpg', 800, 600);
        $sideImage = UploadedFile::fake()->image('side.png', 800, 600);

        $response = $this->actingAs($this->adminUser)
            ->post('/settings/assembly-photos', [
                'product_code' => '4.801190FFBSP.G.A0020',
                'product_name' => 'SS304 ELBOW 90 F/F BSP 3/4"',
                'front_photo' => $frontImage,
                'side_photo' => $sideImage,
                'notes' => 'Versi awal',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $photo = LostWaxAssemblyPhoto::where('product_code', '4.801190FFBSP.G.A0020')->where('is_current', true)->first();
        $this->assertNotNull($photo);
        $this->assertEquals(1, $photo->version);
        $this->assertNotNull($photo->front_image_path);
        $this->assertNotNull($photo->side_image_path);
        $this->assertEquals('Versi awal', $photo->notes);

        Storage::disk('public')->assertExists($photo->front_image_path);
        Storage::disk('public')->assertExists($photo->side_image_path);
    }

    public function test_replacing_photo_creates_new_version_and_preserves_history(): void
    {
        $frontV1 = UploadedFile::fake()->image('front_v1.jpg', 800, 600);
        $sideV1 = UploadedFile::fake()->image('side_v1.jpg', 800, 600);

        $service = app(AssemblyPhotoService::class);
        $v1 = $service->storePhoto('268ETB733', 'SS304 SQUARE DN 25', $frontV1, $sideV1, $this->adminUser, 'V1 Initial');

        $this->assertEquals(1, $v1->version);
        $this->assertTrue($v1->is_current);

        // Upload both front and side for V2
        $frontV2 = UploadedFile::fake()->image('front_v2.jpg', 1000, 800);
        $sideV2 = UploadedFile::fake()->image('side_v2.jpg', 1000, 800);
        $v2 = $service->storePhoto('268ETB733', 'SS304 SQUARE DN 25', $frontV2, $sideV2, $this->adminUser, 'V2 Revised');

        $this->assertEquals(2, $v2->version);
        $this->assertTrue($v2->is_current);
        $this->assertFalse($v1->fresh()->is_current);

        // Check history contains both versions
        $history = $service->getHistory('268ETB733');
        $this->assertCount(2, $history);
        $this->assertEquals(2, $history->first()->version);
        $this->assertEquals(1, $history->last()->version);
    }

    public function test_version_semantics_and_incomplete_status_calculation(): void
    {
        $service = app(AssemblyPhotoService::class);
        $frontV1 = UploadedFile::fake()->image('front_v1.jpg', 800, 600);

        // 1. Upload Front only -> V1 Incomplete (1/2 foto)
        $v1 = $service->storePhoto('268ETB733', 'SS304 SQUARE DN 25', $frontV1, null, $this->adminUser);
        $photos = LostWaxAssemblyPhoto::where('product_code', '268ETB733')->get();
        $status1 = $service->computeStatusForPhotos($photos);

        $this->assertEquals('incomplete', $status1['status_key']);
        $this->assertEquals('INCOMPLETE v.1', $status1['label']);
        $this->assertEquals('1/2 foto', $status1['detail']);

        // 2. Upload Side only -> Completes V1 (2/2 foto)
        $sideV1 = UploadedFile::fake()->image('side_v1.jpg', 800, 600);
        $v1Completed = $service->storePhoto('268ETB733', 'SS304 SQUARE DN 25', null, $sideV1, $this->adminUser);
        $this->assertEquals(1, $v1Completed->version);

        $photos = LostWaxAssemblyPhoto::where('product_code', '268ETB733')->get();
        $status2 = $service->computeStatusForPhotos($photos);
        $this->assertEquals('complete', $status2['status_key']);
        $this->assertEquals('FOTO TERSEDIA v.1', $status2['label']);
        $this->assertEquals('2 foto', $status2['detail']);

        // 3. Upload both Front and Side for V2 -> Complete V2 (4/4 foto)
        $frontV2 = UploadedFile::fake()->image('front_v2.jpg', 800, 600);
        $sideV2 = UploadedFile::fake()->image('side_v2.jpg', 800, 600);
        $v2 = $service->storePhoto('268ETB733', 'SS304 SQUARE DN 25', $frontV2, $sideV2, $this->adminUser);
        $this->assertEquals(2, $v2->version);

        $photos = LostWaxAssemblyPhoto::where('product_code', '268ETB733')->get();
        $status3 = $service->computeStatusForPhotos($photos);
        $this->assertEquals('complete', $status3['status_key']);
        $this->assertEquals('FOTO TERSEDIA v.2', $status3['label']);
        $this->assertEquals('4 foto', $status3['detail']);
    }

    public function test_audit_index_page_loads_and_displays_deterministic_status(): void
    {
        $service = app(AssemblyPhotoService::class);
        $front = UploadedFile::fake()->image('front.jpg', 600, 600);
        $side = UploadedFile::fake()->image('side.jpg', 600, 600);

        // 1 item complete v1
        $service->storePhoto('4.801190FFBSP.G.A0020', 'SS304 ELBOW 90 F/F BSP 3/4"', $front, $side, $this->adminUser);

        // 1 item incomplete v1
        $service->storePhoto('268ETB733', 'SS304 SQUARE DN 25', $front, null, $this->adminUser);

        // 1 item has no photo: FL-DN50-304

        $response = $this->actingAs($this->adminUser)->get('/settings/assembly-photos/index');
        $response->assertOk();
        $response->assertSee('MASTER FOTO RANGKAI — AUDIT STATUS');
        $response->assertSee('4.801190FFBSP.G.A0020');
        $response->assertSee('FOTO TERSEDIA v.1');
        $response->assertSee('268ETB733');
        $response->assertSee('INCOMPLETE v.1');
        $response->assertSee('FL-DN50-304');
        $response->assertSee('BELUM ADA');
        $response->assertSee('Kelola');
    }

    public function test_audit_index_runs_without_n_plus_one_queries(): void
    {
        $service = app(AssemblyPhotoService::class);
        $front = UploadedFile::fake()->image('front.jpg', 600, 600);
        $side = UploadedFile::fake()->image('side.jpg', 600, 600);

        $service->storePhoto('4.801190FFBSP.G.A0020', 'SS304 ELBOW 90 F/F BSP 3/4"', $front, $side, $this->adminUser);
        $service->storePhoto('268ETB733', 'SS304 SQUARE DN 25', $front, null, $this->adminUser);

        DB::enableQueryLog();

        $response = $this->actingAs($this->adminUser)->get('/settings/assembly-photos/index');
        $response->assertOk();

        $queries = DB::getQueryLog();
        // Zero N+1: Should only execute a bounded minimal number of queries (session/user/photos)
        $this->assertLessThanOrEqual(5, count($queries), 'Audit index must execute a constant number of queries without N+1.');
    }

    public function test_image_is_compressed_and_stored(): void
    {
        $largeImage = UploadedFile::fake()->image('large.jpg', 2400, 1800);

        $service = app(AssemblyPhotoService::class);
        $photo = $service->storePhoto('268ETB733', 'SS304 SQUARE DN 25', $largeImage, null, $this->adminUser);

        $storedPath = Storage::disk('public')->path($photo->front_image_path);
        $this->assertFileExists($storedPath);

        // Verify dimensions were downscaled to maxDimension <= 1600
        $sizeInfo = getimagesize($storedPath);
        $this->assertLessThanOrEqual(1600, $sizeInfo[0]);
        $this->assertLessThanOrEqual(1600, $sizeInfo[1]);
    }

    public function test_traveler_print_page_renders_current_front_and_side_photos_by_product_code(): void
    {
        $service = app(AssemblyPhotoService::class);
        $front = UploadedFile::fake()->image('front.jpg', 600, 600);
        $side = UploadedFile::fake()->image('side.jpg', 600, 600);
        $photo = $service->storePhoto('268ETB733', 'SS304 SQUARE DN 25', $front, $side, $this->adminUser);

        // Create Work Order with matching product code
        $plan = ProductionPlan::create([
            'code' => 'PLAN-001',
            'item_code' => '268ETB733',
            'item_name' => 'SS304 SQUARE DN 25',
            'po_number' => 'PO-12345',
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
        ]);

        $printOrder = LostWaxPrintOrder::create([
            'print_order_number' => 'PO-001',
            'scheduled_date' => now()->toDateString(),
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $line = LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $printOrder->id,
            'production_plan_id' => $plan->id,
            'qty_ordered' => 50,
            'code' => '268ETB733',
            'item_name' => 'SS304 SQUARE DN 25',
            'qty_actual_good' => 50,
        ]);

        $workOrder = LostWaxRangkaiWorkOrder::create([
            'rangkai_order_number' => 'RWO-001',
            'lost_wax_print_order_line_id' => $line->id,
            'qty_trees_planned' => 50,
            'tree_capacity' => 1,
            'status' => 'PENDING',
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get("/lost-wax/assemblies/work-orders/{$workOrder->id}/print");

        $response->assertOk();
        $response->assertSee('REFERENSI GAMBAR');
        $response->assertSee($photo->front_image_url);
        $response->assertSee($photo->side_image_url);
    }

    public function test_detail_endpoint_returns_current_and_history_json(): void
    {
        $service = app(AssemblyPhotoService::class);
        $front = UploadedFile::fake()->image('front.jpg', 600, 600);
        $service->storePhoto('268ETB733', 'SS304 SQUARE DN 25', $front, null, $this->adminUser, 'Catatan v1');

        $response = $this->actingAs($this->adminUser)
            ->getJson('/settings/assembly-photos/detail?product_code=268ETB733');

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'current' => [
                'product_code' => '268ETB733',
                'version' => 1,
            ],
        ]);
        $this->assertCount(1, $response->json('history'));
    }

    public function test_assembly_photos_page_contains_camera_and_gallery_inputs_with_environment_capture(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/settings/assembly-photos');

        $response->assertOk();
        $response->assertSee('Ambil Foto');
        $response->assertSee('Pilih Galeri');
        $response->assertSee('capture="environment"', false);
        $response->assertSee('id="frontCameraInput"', false);
        $response->assertSee('id="sideCameraInput"', false);
        $response->assertSee('id="frontGalleryInput"', false);
        $response->assertSee('id="sideGalleryInput"', false);
    }

    public function test_valid_webp_and_png_uploads_succeed(): void
    {
        $frontWebp = UploadedFile::fake()->image('camera_capture.webp', 1200, 900);
        $sidePng = UploadedFile::fake()->image('gallery_photo.png', 1200, 900);

        $response = $this->actingAs($this->adminUser)
            ->post('/settings/assembly-photos', [
                'product_code' => '268ETB733',
                'product_name' => 'SS304 SQUARE DN 25',
                'front_photo' => $frontWebp,
                'side_photo' => $sidePng,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $photo = LostWaxAssemblyPhoto::where('product_code', '268ETB733')->where('is_current', true)->first();
        $this->assertNotNull($photo);
        $this->assertNotNull($photo->front_image_path);
        $this->assertNotNull($photo->side_image_path);
        Storage::disk('public')->assertExists($photo->front_image_path);
        Storage::disk('public')->assertExists($photo->side_image_path);
    }

    public function test_large_mobile_photo_up_to_20mb_succeeds(): void
    {
        // 15MB fake image (15360 KB)
        $largeFrontImage = UploadedFile::fake()->image('high_res_camera.jpg', 2000, 1500)->size(15360);

        $response = $this->actingAs($this->adminUser)
            ->post('/settings/assembly-photos', [
                'product_code' => 'FL-DN50-304',
                'product_name' => 'FLANGE 10K DN 50 SS304',
                'front_photo' => $largeFrontImage,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $photo = LostWaxAssemblyPhoto::where('product_code', 'FL-DN50-304')->where('is_current', true)->first();
        $this->assertNotNull($photo);
        $this->assertNotNull($photo->front_image_path);
        Storage::disk('public')->assertExists($photo->front_image_path);
    }

    public function test_oversized_photo_exceeding_20mb_is_rejected(): void
    {
        // 25MB fake image (25600 KB)
        $hugeImage = UploadedFile::fake()->image('too_large.jpg', 3000, 3000)->size(25600);

        $response = $this->actingAs($this->adminUser)
            ->post('/settings/assembly-photos', [
                'product_code' => 'FL-DN50-304',
                'product_name' => 'FLANGE 10K DN 50 SS304',
                'front_photo' => $hugeImage,
            ]);

        $response->assertSessionHasErrors(['front_photo']);
    }

    public function test_invalid_non_image_file_is_rejected(): void
    {
        $fakePdf = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->adminUser)
            ->post('/settings/assembly-photos', [
                'product_code' => 'FL-DN50-304',
                'product_name' => 'FLANGE 10K DN 50 SS304',
                'front_photo' => $fakePdf,
            ]);

        $response->assertSessionHasErrors(['front_photo']);
    }

    public function test_audit_index_renders_both_desktop_table_and_mobile_cards(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/settings/assembly-photos/index');

        $response->assertOk();
        $response->assertSee('MASTER FOTO RANGKAI — AUDIT STATUS');
        // Desktop table check
        $response->assertSee('hidden md:block', false);
        // Mobile card list check
        $response->assertSee('block md:hidden', false);
        $response->assertSee('Kelola Foto');
        $response->assertSee('268ETB733');
    }

    public function test_masterdata_kpi_connection_configuration_honors_dedicated_environment_variables(): void
    {
        $conn = config('database.connections.masterdata_kpi');

        $this->assertIsArray($conn);
        $this->assertEquals('mysql', $conn['driver']);
        $this->assertArrayHasKey('host', $conn);
        $this->assertArrayHasKey('database', $conn);
        $this->assertArrayHasKey('username', $conn);
        $this->assertArrayHasKey('password', $conn);
    }
}
