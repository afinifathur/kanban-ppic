<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\User;
use Database\Seeders\SandCastingSpvUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SpvOperationalIdentityAndSidebarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $accessPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution']);

        $roleAdmin = Role::firstOrCreate(['name' => 'admin']);
        $roleAdmin->givePermissionTo([$accessPlanning, $accessExecution]);

        $roleSpv = Role::firstOrCreate(['name' => 'spv']);
        $roleSpv->givePermissionTo([$accessExecution]);

        // Run SPV seeder
        $this->seed(SandCastingSpvUserSeeder::class);
    }

    /**
     * TEST 1: Existing SPV Netto dapat login dengan password 'password'.
     */
    public function test_1_spv_netto_can_login(): void
    {
        $user = User::where('email', 'spvnettofl@peroniks.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('password', $user->password));

        $response = $this->post('/login', [
            'email' => 'spvnettofl@peroniks.com',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    /**
     * TEST 2 - 7: SPV accounts have correct assigned_stage.
     */
    public function test_2_to_7_spv_accounts_have_correct_assigned_stages(): void
    {
        $netto = User::where('email', 'spvnettofl@peroniks.com')->first();
        $this->assertEquals('netto', $netto->assigned_stage);
        $this->assertEquals('SPV Netto Flange', $netto->name);

        $od = User::where('email', 'spvbubutod@peroniks.com')->first();
        $this->assertEquals('bubut_od', $od->assigned_stage);
        $this->assertEquals('SPV Bubut OD Flange', $od->name);

        $cnc = User::where('email', 'spvbubutcncfl@peroniks.com')->first();
        $this->assertEquals('bubut_cnc', $cnc->assigned_stage);
        $this->assertEquals('SPV Bubut CNC Flange', $cnc->name);

        $bor = User::where('email', 'spvborfl@peroniks.com')->first();
        $this->assertEquals('bor', $bor->assigned_stage);
        $this->assertEquals('SPV Bor Flange', $bor->name);

        $qc = User::where('email', 'spvqcfl@peroniks.com')->first();
        $this->assertEquals('qc', $qc->assigned_stage);
        $this->assertEquals('SPV QC Flange', $qc->name);

        $gudang = User::where('email', 'spvgdfl@peroniks.com')->first();
        $this->assertEquals('gudang_jadi', $gudang->assigned_stage);
        $this->assertEquals('SPV Gudang Jadi Flange', $gudang->name);
    }

    /**
     * TEST 8: Semua SPV menggunakan role 'spv'.
     */
    public function test_8_all_spv_users_have_spv_role(): void
    {
        $emails = [
            'spvnettofl@peroniks.com',
            'spvbubutod@peroniks.com',
            'spvbubutcncfl@peroniks.com',
            'spvborfl@peroniks.com',
            'spvqcfl@peroniks.com',
            'spvgdfl@peroniks.com',
        ];

        foreach ($emails as $email) {
            $user = User::where('email', $email)->first();
            $this->assertNotNull($user, "User {$email} must exist.");
            $this->assertTrue($user->hasRole('spv'), "User {$email} must have 'spv' role.");
            $this->assertEquals(['spv'], $user->getRoleNames()->toArray());
        }
    }

    /**
     * TEST 9: Tidak ada SPV dengan assigned_stage = marking.
     */
    public function test_9_no_spv_with_marking_stage(): void
    {
        $markingUsers = User::where('assigned_stage', 'marking')->count();
        $this->assertEquals(0, $markingUsers, 'No user should have assigned_stage = marking');
    }

    /**
     * TEST 10: SPV tidak dapat mengakses stage lain (strict authorization).
     */
    public function test_10_spv_cannot_access_other_stages(): void
    {
        $spvNetto = User::where('email', 'spvnettofl@peroniks.com')->first();
        $spvOd = User::where('email', 'spvbubutod@peroniks.com')->first();

        // SPV Netto cannot access OD kanban or OD scan
        $responseOdKanban = $this->actingAs($spvNetto)->get('/sand-casting/kanban/bubut-od');
        $responseOdKanban->assertForbidden();

        $responseOdScan = $this->actingAs($spvNetto)->get('/sand-casting/scan/stage/bubut-od');
        $responseOdScan->assertForbidden();

        // SPV OD cannot access Netto scan or CNC scan
        $responseNettoScan = $this->actingAs($spvOd)->get('/sand-casting/scan/stage/netto');
        $responseNettoScan->assertForbidden();

        $responseCncScan = $this->actingAs($spvOd)->get('/sand-casting/scan/stage/bubut-cnc');
        $responseCncScan->assertForbidden();
    }

    /**
     * TEST 11: Sidebar SPV hanya menampilkan menu operasional.
     */
    public function test_11_spv_sidebar_shows_only_operational_menu(): void
    {
        $spvOd = User::where('email', 'spvbubutod@peroniks.com')->first();

        $response = $this->actingAs($spvOd)->get('/sand-casting/kanban/bubut-od');
        $response->assertOk();

        // Operational Menu is present
        $response->assertSee('Operasional');
        $response->assertSee('Kanban Floor');
        $response->assertSee('Scanner');
        $response->assertSee('/sand-casting/kanban/bubut-od');
        $response->assertSee('/sand-casting/scan/stage/bubut-od');

        // Non-operational items are NOT present in SPV sidebar
        $response->assertDontSee('Dashboard Kerusakan');
        $response->assertDontSee('Production Status');
        $response->assertDontSee('Rack Monitoring');
        $response->assertDontSee('Perintah Cor');
        $response->assertDontSee('Hasil Cor');
        $response->assertDontSee('Input Harian (WIP)');
        $response->assertDontSee('Report WIP');
        $response->assertDontSee('Setting Kerusakan');
        $response->assertDontSee('Setting Customer');
    }

    /**
     * TEST 12: "FLANGE_BESI" tidak muncul sebagai nickname utama SPV.
     */
    public function test_12_flange_besi_does_not_appear_as_primary_nickname(): void
    {
        $spvNetto = User::where('email', 'spvnettofl@peroniks.com')->first();
        $this->assertEquals('SPV NETTO', $spvNetto->getOperationalTitle());

        $response = $this->actingAs($spvNetto)->get('/sand-casting/kanban/netto');
        $response->assertOk();

        // Must not see raw "SPV - FLANGE_BESI" or "FLANGE_BESI" in the user card
        $response->assertDontSee('SPV - FLANGE_BESI');
        $response->assertDontSee('spv - FLANGE_BESI');
    }

    /**
     * TEST 13: Header SPV menggunakan nama stage operasional.
     */
    public function test_13_header_spv_uses_operational_stage_names(): void
    {
        $spvNetto = User::where('email', 'spvnettofl@peroniks.com')->first();
        $this->assertEquals('SPV NETTO', $spvNetto->getOperationalTitle());

        $spvOd = User::where('email', 'spvbubutod@peroniks.com')->first();
        $this->assertEquals('SPV BUBUT OD', $spvOd->getOperationalTitle());

        $spvCnc = User::where('email', 'spvbubutcncfl@peroniks.com')->first();
        $this->assertEquals('SPV BUBUT CNC', $spvCnc->getOperationalTitle());

        $spvBor = User::where('email', 'spvborfl@peroniks.com')->first();
        $this->assertEquals('SPV BOR', $spvBor->getOperationalTitle());

        $spvQc = User::where('email', 'spvqcfl@peroniks.com')->first();
        $this->assertEquals('SPV QC', $spvQc->getOperationalTitle());

        $spvGudang = User::where('email', 'spvgdfl@peroniks.com')->first();
        $this->assertEquals('SPV GUDANG JADI', $spvGudang->getOperationalTitle());
    }

    /**
     * TEST 14: Domain label UI menggunakan "FLANGE".
     */
    public function test_14_domain_subtitle_uses_flange(): void
    {
        $spvOd = User::where('email', 'spvbubutod@peroniks.com')->first();
        $this->assertEquals('FLANGE', $spvOd->getOperationalSubtitle());

        $response = $this->actingAs($spvOd)->get('/sand-casting/kanban/bubut-od');
        $response->assertOk();
        $response->assertSee('SPV BUBUT OD');
        $response->assertSee('FLANGE');
    }

    /**
     * TEST 15: Tidak ada duplicate user (seeder idempotent).
     */
    public function test_15_no_duplicate_users_when_seeded_multiple_times(): void
    {
        $initialCount = User::count();

        // Seed second and third time
        $this->seed(SandCastingSpvUserSeeder::class);
        $this->seed(SandCastingSpvUserSeeder::class);

        $this->assertEquals($initialCount, User::count(), 'Re-running seeder must be idempotent and not create duplicate users.');
    }

    /**
     * TEST 16: Kitir produksi tidak berubah.
     */
    public function test_16_kitir_produksi_unmodified(): void
    {
        $plan = ProductionPlan::create([
            'code' => '268ET001',
            'title' => 'Rencana SC 268ET001',
            'item_code' => '4.101',
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-001',
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'customer' => 'PT SINAR METAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260920-0001',
            'scheduled_date' => '2026-09-20',
            'status' => 'ISSUED',
            'created_by' => $admin->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '2"',
            'aisi' => 'FC250',
            'target_shape' => 'FLANGE',
            'line_number' => 1,
            'product_scope' => 'FLANGE_BESI',
        ]);

        $castResult = SandCastingCastingResult::create([
            'heat_number' => 'A221092601',
            'cast_date' => '2026-09-20',
            'furnace' => 'F-01',
            'shift' => '1',
            'operator_name' => 'Sutrisno',
            'recorded_by' => $admin->id,
        ]);

        $line = $castResult->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-20260920-0001',
            'heat_number' => $castResult->heat_number,
            'line_code' => 'FLANGE_1',
            'item_code' => $plan->item_code,
            'item_name' => $plan->item_name,
            'qty_pcor' => 100,
            'qty_cor' => 100,
            'qty_good' => 100,
            'qty_reject' => 0,
            'current_stage' => 'bubut_od',
        ]);

        $response = $this->actingAs($admin)->get('/sand-casting/casting-results/'.$castResult->id.'/lines/'.$line->id.'/kitir');
        $response->assertOk();

        $content = $response->getContent();
        $this->assertStringContainsString('MARKING', $content);
        $this->assertStringContainsString('BUBUT OD', $content);
        $this->assertStringContainsString('BUBUT CNC', $content);
    }
}
