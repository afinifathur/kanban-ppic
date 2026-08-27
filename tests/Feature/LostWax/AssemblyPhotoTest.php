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
use Illuminate\Support\Facades\Storage;
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

    public function test_upload_both_front_and_side_photos_creates_version_1(): void
    {
        $frontImage = UploadedFile::fake()->image('front.jpg', 800, 600);
        $sideImage = UploadedFile::fake()->image('side.png', 800, 600);

        $response = $this->actingAs($this->adminUser)
            ->post('/settings/assembly-photos', [
                'product_code' => '268ETB733',
                'product_name' => 'SS304 SQUARE DN 25',
                'front_photo' => $frontImage,
                'side_photo' => $sideImage,
                'notes' => 'Versi awal',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $photo = LostWaxAssemblyPhoto::where('product_code', '268ETB733')->where('is_current', true)->first();
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

        // Replace front only in V2
        $frontV2 = UploadedFile::fake()->image('front_v2.jpg', 1000, 800);
        $v2 = $service->storePhoto('268ETB733', 'SS304 SQUARE DN 25', $frontV2, null, $this->adminUser, 'V2 Front revised');

        $this->assertEquals(2, $v2->version);
        $this->assertTrue($v2->is_current);
        $this->assertFalse($v1->fresh()->is_current);

        // Front path updated, side path preserved from V1
        $this->assertNotEquals($v1->front_image_path, $v2->front_image_path);
        $this->assertEquals($v1->side_image_path, $v2->side_image_path);

        // Check history contains both versions
        $history = $service->getHistory('268ETB733');
        $this->assertCount(2, $history);
        $this->assertEquals(2, $history->first()->version);
        $this->assertEquals(1, $history->last()->version);
    }

    public function test_image_is_compressed_and_stored(): void
    {
        // 2000x2000 large fake image
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

    public function test_traveler_print_page_shows_clear_placeholder_when_photo_not_available(): void
    {
        $plan = ProductionPlan::create([
            'code' => 'PLAN-999',
            'item_code' => 'NO-PHOTO-ITEM',
            'item_name' => 'Produk Tanpa Foto',
            'po_number' => 'PO-99999',
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
        ]);

        $printOrder = LostWaxPrintOrder::create([
            'print_order_number' => 'PO-999',
            'scheduled_date' => now()->toDateString(),
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $line = LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $printOrder->id,
            'production_plan_id' => $plan->id,
            'qty_ordered' => 50,
            'code' => 'NO-PHOTO-ITEM',
            'item_name' => 'Produk Tanpa Foto',
            'qty_actual_good' => 50,
        ]);

        $workOrder = LostWaxRangkaiWorkOrder::create([
            'rangkai_order_number' => 'RWO-999',
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
        $response->assertSee('FOTO BELUM TERSEDIA');
    }

    public function test_photo_service_falls_back_to_product_name_if_code_differs(): void
    {
        $service = app(AssemblyPhotoService::class);
        $front = UploadedFile::fake()->image('front.jpg', 600, 600);
        $photo = $service->storePhoto('LEGACY_CODE', 'SS304 SQUARE DN 25', $front, null, $this->adminUser);

        // Search with unknown code but matching name
        $matched = $service->getCurrentPhoto('DIFFERENT_CODE', 'SS304 SQUARE DN 25');
        $this->assertNotNull($matched);
        $this->assertEquals($photo->id, $matched->id);
    }

    public function test_photo_service_does_not_false_match_unrelated_items(): void
    {
        $service = app(AssemblyPhotoService::class);
        $front = UploadedFile::fake()->image('front.jpg', 600, 600);
        $service->storePhoto('268ETB733', 'SS304 SQUARE DN 25', $front, null, $this->adminUser);

        $matched = $service->getCurrentPhoto('UNRELATED_CODE', 'UNRELATED_NAME');
        $this->assertNull($matched);
    }

    public function test_sidebar_contains_foto_rangkai_link(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/dashboard');
        $response->assertOk();
        $response->assertSee('Foto Rangkai');
        $response->assertSee(route('settings.assembly-photos.index'));
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
}
