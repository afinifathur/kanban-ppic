<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DefectType;
use App\Models\ProductionPlan;
use App\Models\User;
use Database\Seeders\AuditorUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditorRbacAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $auditorUser;

    private User $salesUser;

    private User $adminUser;

    private User $ppicUser;

    private User $spvNetto;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Permissions
        $permAuditor = Permission::firstOrCreate(['name' => 'access_auditor_visibility', 'guard_name' => 'web']);
        $permSales = Permission::firstOrCreate(['name' => 'access_production_visibility', 'guard_name' => 'web']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution', 'guard_name' => 'web']);
        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);

        // 2. Setup Roles
        $roleAuditor = Role::firstOrCreate(['name' => 'auditor', 'guard_name' => 'web']);
        $roleAuditor->syncPermissions([$permAuditor]);

        $roleSales = Role::firstOrCreate(['name' => 'sales', 'guard_name' => 'web']);
        $roleSales->syncPermissions([$permSales]);

        $roleAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $roleAdmin->syncPermissions([$permPlanning, $permExecution]);

        $rolePpic = Role::firstOrCreate(['name' => 'ppic', 'guard_name' => 'web']);
        $rolePpic->syncPermissions([$permPlanning, $permExecution]);

        $roleSpv = Role::firstOrCreate(['name' => 'spv', 'guard_name' => 'web']);
        $roleSpv->syncPermissions([$permExecution]);

        // 3. Create Users
        $this->auditorUser = User::create([
            'name' => 'Auditor Peroniks',
            'email' => 'auditor@peroniks.com',
            'password' => Hash::make('password123'),
            'product_scope' => null,
            'assigned_stage' => null,
        ]);
        $this->auditorUser->assignRole('auditor');

        $this->salesUser = User::create([
            'name' => 'Sales Visibility',
            'email' => 'sales@peroniks.com',
            'password' => Hash::make('password123'),
            'product_scope' => null,
            'assigned_stage' => null,
        ]);
        $this->salesUser->assignRole('sales');

        $this->adminUser = User::create([
            'name' => 'Admin PPIC',
            'email' => 'adminppicpf@peroniks.com',
            'password' => Hash::make('password123'),
        ]);
        $this->adminUser->assignRole('admin');

        $this->ppicUser = User::create([
            'name' => 'PPIC Flange',
            'email' => 'ppicflange@peroniks.com',
            'password' => Hash::make('password123'),
            'product_scope' => 'FLANGE_STAINLESS',
        ]);
        $this->ppicUser->assignRole('ppic');

        $this->spvNetto = User::create([
            'name' => 'SPV Netto',
            'email' => 'spvnettofl@peroniks.com',
            'password' => Hash::make('password123'),
            'assigned_stage' => 'netto',
        ]);
        $this->spvNetto->assignRole('spv');
    }

    // ==========================================
    // 1. ROLE & USER PROVISIONING
    // ==========================================

    public function test_01_role_auditor_exists_with_guard_web_and_dedicated_permission(): void
    {
        $role = Role::findByName('auditor', 'web');
        $this->assertNotNull($role);
        $this->assertTrue($role->hasPermissionTo('access_auditor_visibility'));
        $this->assertFalse($role->hasPermissionTo('access_planning'));
        $this->assertFalse($role->hasPermissionTo('access_execution'));
    }

    public function test_02_auditor_user_is_connected_to_auditor_role(): void
    {
        $this->assertTrue($this->auditorUser->hasRole('auditor'));
        $this->assertTrue($this->auditorUser->can('access_auditor_visibility'));
        $this->assertFalse($this->auditorUser->can('access_planning'));
        $this->assertFalse($this->auditorUser->can('access_execution'));
    }

    public function test_03_auditor_seeder_is_idempotent_and_does_not_duplicate_or_reset_password(): void
    {
        $customHash = Hash::make('my_custom_secret');
        $this->auditorUser->update(['password' => $customHash]);

        $seeder = new AuditorUserSeeder;
        $seeder->run();

        // Check user still single
        $this->assertEquals(1, User::where('email', 'auditor@peroniks.com')->count());

        // Password must NOT be overwritten
        $refreshed = User::where('email', 'auditor@peroniks.com')->first();
        $this->assertEquals($customHash, $refreshed->password);
        $this->assertTrue($refreshed->hasRole('auditor'));

        // Test exception on missing env var when creating new user
        $this->auditorUser->delete();
        putenv('AUDITOR_INITIAL_PASSWORD=');
        $_ENV['AUDITOR_INITIAL_PASSWORD'] = '';
        $_SERVER['AUDITOR_INITIAL_PASSWORD'] = '';

        try {
            $seeder->run();
            $this->fail('Expected RuntimeException was not thrown when AUDITOR_INITIAL_PASSWORD is empty.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('AUDITOR_INITIAL_PASSWORD is required', $e->getMessage());
        }

        // Test successful creation when env var is set
        putenv('AUDITOR_INITIAL_PASSWORD=SuperSecretPass123!');
        $_ENV['AUDITOR_INITIAL_PASSWORD'] = 'SuperSecretPass123!';
        $_SERVER['AUDITOR_INITIAL_PASSWORD'] = 'SuperSecretPass123!';

        $seeder->run();

        $newAuditor = User::where('email', 'auditor@peroniks.com')->first();
        $this->assertNotNull($newAuditor);
        $this->assertTrue(Hash::check('SuperSecretPass123!', $newAuditor->password));
        $this->assertTrue($newAuditor->hasRole('auditor'));
    }

    // ==========================================
    // 2. ALLOWED ACCESS TO 8 SPECIFIC PAGES
    // ==========================================

    public function test_04_auditor_can_access_sand_casting_kanban_and_stages(): void
    {
        // Entry page
        $response = $this->actingAs($this->auditorUser)->get('/sand-casting/kanban');
        $response->assertOk();
        $response->assertViewIs('sand-casting.kanban.index');

        // All 6 stages
        $stages = ['netto', 'bubut-od', 'bubut-cnc', 'bor', 'qc', 'gudang-jadi'];
        foreach ($stages as $stage) {
            $stageResponse = $this->actingAs($this->auditorUser)->get("/sand-casting/kanban/stage/{$stage}");
            $stageResponse->assertOk();
            $stageResponse->assertViewIs('sand-casting.kanban.index');
        }

        // Print preview & Excel export
        $this->actingAs($this->auditorUser)->get('/sand-casting/kanban/stage/netto/print')->assertOk();
        $this->actingAs($this->auditorUser)->get('/sand-casting/kanban/stage/netto/export/excel')->assertOk();
    }

    public function test_05_auditor_can_access_sand_casting_production_status_and_details(): void
    {
        $response = $this->actingAs($this->auditorUser)->get('/sand-casting/production-status');
        $response->assertOk();
        $response->assertViewIs('sand-casting.production-status.index');

        // Excel export
        $this->actingAs($this->auditorUser)->get('/sand-casting/production-status/export')->assertOk();

        // Details modal endpoint
        $plan = ProductionPlan::create([
            'code' => 'AUD-TEST-PLAN',
            'title' => 'Audit Plan',
            'customer' => 'Customer Audit',
            'po_number' => 'PO-AUD-01',
            'line_number' => 1,
            'item_code' => 'ITM-AUD-01',
            'item_name' => 'FLANGE 4 INCH',
            'qty_planned' => 50,
            'qty_remaining' => 50,
            'weight' => 10.0,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'is_closed' => false,
        ]);

        $detailsResponse = $this->actingAs($this->auditorUser)->getJson("/sand-casting/production-status/details?production_plan_id={$plan->id}");
        $detailsResponse->assertOk();
    }

    public function test_06_auditor_can_access_sand_casting_dashboard_and_ajax_data(): void
    {
        $response = $this->actingAs($this->auditorUser)->get('/sand-casting/dashboard');
        $response->assertOk();
        $response->assertViewIs('sand-casting.dashboard.index');

        // AJAX polling data endpoint
        $dataResponse = $this->actingAs($this->auditorUser)->getJson('/sand-casting/dashboard/data');
        $dataResponse->assertOk();
        $dataResponse->assertJsonIsObject();
    }

    public function test_07_auditor_can_access_sand_casting_report_production_and_exports(): void
    {
        $response = $this->actingAs($this->auditorUser)->get('/sand-casting/report/production');
        $response->assertOk();
        $response->assertViewIs('sand-casting.report.production.index');

        $this->actingAs($this->auditorUser)->get('/sand-casting/report/production/export/excel')->assertOk();
        $this->actingAs($this->auditorUser)->get('/sand-casting/report/production/export/pdf')->assertOk();
    }

    public function test_08_auditor_can_access_sand_casting_report_qc_defects_and_exports(): void
    {
        $response = $this->actingAs($this->auditorUser)->get('/sand-casting/report/qc-defects');
        $response->assertOk();
        $response->assertViewIs('sand-casting.report.qc-defects.index');

        $this->actingAs($this->auditorUser)->get('/sand-casting/report/qc-defects/export/excel')->assertOk();
        $this->actingAs($this->auditorUser)->get('/sand-casting/report/qc-defects/export/pdf')->assertOk();
    }

    public function test_09_auditor_can_access_lost_wax_production_status_and_trees(): void
    {
        $response = $this->actingAs($this->auditorUser)->get('/lost-wax/production-status');
        $response->assertOk();
        $response->assertViewIs('lost-wax.production-status.index');

        // Trees AJAX endpoint
        $this->actingAs($this->auditorUser)->getJson('/lost-wax/production-status/trees?code=UNKNOWN')->assertOk();

        // Export XLSX
        $this->actingAs($this->auditorUser)->get('/lost-wax/production-status/export')->assertOk();
    }

    public function test_10_auditor_can_access_lost_wax_quality_defects_and_exports(): void
    {
        $response = $this->actingAs($this->auditorUser)->get('/lost-wax/quality/defects');
        $response->assertOk();
        $response->assertViewIs('lost-wax.quality.defects.index');

        $this->actingAs($this->auditorUser)->get('/lost-wax/quality/defects/export/excel')->assertOk();
        $this->actingAs($this->auditorUser)->get('/lost-wax/quality/defects/export/pdf')->assertOk();
    }

    public function test_11_auditor_can_access_lost_wax_report_production_and_exports(): void
    {
        $response = $this->actingAs($this->auditorUser)->get('/lost-wax/report/production');
        $response->assertOk();
        $response->assertViewIs('lost-wax.report.production.index');

        $this->actingAs($this->auditorUser)->get('/lost-wax/report/production/export/excel')->assertOk();
        $this->actingAs($this->auditorUser)->get('/lost-wax/report/production/export/pdf')->assertOk();
    }

    // ==========================================
    // 3. FILTERING & SEARCH CAPABILITY
    // ==========================================

    public function test_12_auditor_can_use_filters_on_reports_and_status(): void
    {
        // Sand casting production status with search filter
        $this->actingAs($this->auditorUser)
            ->get('/sand-casting/production-status?search=FLANGE&filter=active')
            ->assertOk();

        // Sand casting production report with date and stage filters
        $this->actingAs($this->auditorUser)
            ->get('/sand-casting/report/production?date_from=2026-01-01&date_to=2026-01-31&stage=netto&search=test')
            ->assertOk();

        // Sand casting QC defect report with date and stage filters
        $this->actingAs($this->auditorUser)
            ->get('/sand-casting/report/qc-defects?date_from=2026-01-01&date_to=2026-01-31&stage=all')
            ->assertOk();

        // Lost wax defect report with filters
        $this->actingAs($this->auditorUser)
            ->get('/lost-wax/quality/defects?date_from=2026-01-01&date_to=2026-01-31&stage=all&mode=ringkas')
            ->assertOk();

        // Lost wax production report with filters
        $this->actingAs($this->auditorUser)
            ->get('/lost-wax/report/production?date_from=2026-01-01&date_to=2026-01-31&stage=all')
            ->assertOk();
    }

    // ==========================================
    // 4. DENIED ACCESS: ADMINISTRATION & SETTINGS
    // ==========================================

    public function test_13_auditor_strictly_denied_from_settings_and_admin(): void
    {
        $defect = DefectType::create(['name' => 'Porosity', 'department' => 'netto']);
        $customer = Customer::create(['name' => 'Test Customer', 'code' => 'TC01']);

        // Defect settings
        $this->actingAs($this->auditorUser)->get('/settings/defect-types')->assertForbidden();
        $this->actingAs($this->auditorUser)->post('/settings/defect-types', ['name' => 'Crack'])->assertForbidden();
        $this->actingAs($this->auditorUser)->put("/settings/defect-types/{$defect->id}", ['name' => 'Crack'])->assertForbidden();

        // Customer settings
        $this->actingAs($this->auditorUser)->get('/settings/customers')->assertForbidden();
        $this->actingAs($this->auditorUser)->post('/settings/customers', ['name' => 'Cust'])->assertForbidden();
        $this->actingAs($this->auditorUser)->delete("/settings/customers/{$customer->id}")->assertForbidden();

        // Assembly photos
        $this->actingAs($this->auditorUser)->get('/settings/assembly-photos')->assertForbidden();
        $this->actingAs($this->auditorUser)->post('/settings/assembly-photos', [])->assertForbidden();
    }

    // ==========================================
    // 5. DENIED ACCESS: WRITE & OPERATIONAL MUTATIONS
    // ==========================================

    public function test_14_auditor_strictly_denied_from_kanban_queue_reorder(): void
    {
        $response = $this->actingAs($this->auditorUser)->post('/sand-casting/kanban/stage/netto/reorder', [
            'line_number' => 1,
            'from_position' => 2,
            'to_position' => 1,
        ]);

        $response->assertForbidden();
    }

    public function test_15_auditor_strictly_denied_from_sand_casting_scanners_and_defects(): void
    {
        // Scanners
        $this->actingAs($this->auditorUser)->get('/sand-casting/scan')->assertForbidden();
        $this->actingAs($this->auditorUser)->get('/sand-casting/scan/netto')->assertForbidden();
        $this->actingAs($this->auditorUser)->get('/sand-casting/scan/stage/netto')->assertForbidden();
        $this->actingAs($this->auditorUser)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => 'KTR-001',
        ])->assertForbidden();

        // Generic alias scanner
        $this->actingAs($this->auditorUser)->postJson('/scan/netto/execute', [
            'traveler_number' => 'KTR-001',
        ])->assertForbidden();

        // Defect recording
        $this->actingAs($this->auditorUser)->get('/sand-casting/defects')->assertForbidden();

        // QC Defect verification
        $this->actingAs($this->auditorUser)->get('/sand-casting/qc-defects')->assertForbidden();
    }

    public function test_16_auditor_strictly_denied_from_lost_wax_scanners_trees_and_operations(): void
    {
        // Trees
        $this->actingAs($this->auditorUser)->get('/lost-wax/trees')->assertForbidden();
        $this->actingAs($this->auditorUser)->post('/lost-wax/trees/print-thermal')->assertForbidden();
        $this->actingAs($this->auditorUser)->post('/lost-wax/trees/bulk-rack')->assertForbidden();

        // Scanners
        $this->actingAs($this->auditorUser)->get('/lost-wax/scan')->assertForbidden();
        $this->actingAs($this->auditorUser)->post('/lost-wax/scan', ['barcode' => 'TEST'])->assertForbidden();
        $this->actingAs($this->auditorUser)->get('/lost-wax/scan-oven')->assertForbidden();
        $this->actingAs($this->auditorUser)->post('/lost-wax/scan-oven', ['barcode' => 'TEST'])->assertForbidden();
        $this->actingAs($this->auditorUser)->get('/lost-wax/scan-siap-cor')->assertForbidden();
        $this->actingAs($this->auditorUser)->post('/lost-wax/scan-siap-cor', ['barcode' => 'TEST'])->assertForbidden();

        // Dewaxing monitoring
        $this->actingAs($this->auditorUser)->get('/lost-wax/dewaxing-monitoring')->assertForbidden();

        // Rack monitor
        $this->actingAs($this->auditorUser)->get('/lost-wax/rack-monitor')->assertForbidden();

        // Ready for oven & casting queues
        $this->actingAs($this->auditorUser)->get('/lost-wax/reports/ready-for-oven')->assertForbidden();
        $this->actingAs($this->auditorUser)->get('/lost-wax/reports/ready-for-casting')->assertForbidden();

        // Casting results
        $this->actingAs($this->auditorUser)->get('/lost-wax/casting-results')->assertForbidden();
        $this->actingAs($this->auditorUser)->post('/lost-wax/casting-results', [])->assertForbidden();
    }

    public function test_17_auditor_strictly_denied_from_planning_orders(): void
    {
        // Legacy plan
        $this->actingAs($this->auditorUser)->get('/plan')->assertForbidden();
        $this->actingAs($this->auditorUser)->post('/plan', [])->assertForbidden();

        // Sand casting casting orders
        $this->actingAs($this->auditorUser)->get('/sand-casting/casting-orders/plans')->assertForbidden();
        $this->actingAs($this->auditorUser)->get('/sand-casting/casting-orders')->assertForbidden();
        $this->actingAs($this->auditorUser)->post('/sand-casting/casting-orders', [])->assertForbidden();

        // Lost wax print orders
        $this->actingAs($this->auditorUser)->get('/lost-wax/print-orders/plans')->assertForbidden();
        $this->actingAs($this->auditorUser)->get('/lost-wax/print-orders')->assertForbidden();
        $this->actingAs($this->auditorUser)->post('/lost-wax/print-orders', [])->assertForbidden();

        // Assemblies & Outcomes
        $this->actingAs($this->auditorUser)->get('/lost-wax/outcomes')->assertForbidden();
        $this->actingAs($this->auditorUser)->get('/lost-wax/assemblies')->assertForbidden();
        $this->actingAs($this->auditorUser)->get('/lost-wax/work-orders')->assertForbidden();
    }

    // ==========================================
    // 6. REDIRECTIONS & GUEST ACCESS
    // ==========================================

    public function test_18_auditor_redirected_to_sand_casting_dashboard_from_root_and_legacy_dashboard(): void
    {
        $this->actingAs($this->auditorUser)->get('/')->assertRedirect('/sand-casting/dashboard');
        $this->actingAs($this->auditorUser)->get('/dashboard')->assertRedirect('/sand-casting/dashboard');
    }

    public function test_19_unauthenticated_guest_denied_from_all_eight_pages(): void
    {
        $routes = [
            '/sand-casting/kanban',
            '/sand-casting/production-status',
            '/sand-casting/dashboard',
            '/sand-casting/report/production',
            '/sand-casting/report/qc-defects',
            '/lost-wax/production-status',
            '/lost-wax/quality/defects',
            '/lost-wax/report/production',
        ];

        foreach ($routes as $route) {
            $this->get($route)->assertRedirect('/login');
        }
    }

    // ==========================================
    // 7. ROLE REGRESSION TESTS (ADMIN & SALES UNCHANGED)
    // ==========================================

    public function test_20_admin_retains_full_planning_execution_and_settings_access(): void
    {
        $this->actingAs($this->adminUser)->get('/plan')->assertOk();
        $this->actingAs($this->adminUser)->get('/sand-casting/kanban')->assertOk();
        $this->actingAs($this->adminUser)->get('/sand-casting/dashboard')->assertOk();
        $this->actingAs($this->adminUser)->get('/lost-wax/trees')->assertOk();
        $this->actingAs($this->adminUser)->get('/settings/defect-types')->assertOk();
    }

    public function test_21_sales_role_access_remains_strictly_unchanged(): void
    {
        // Sales STILL has access to its 3 visibility pages:
        $this->actingAs($this->salesUser)->get('/sand-casting/kanban')->assertOk();
        $this->actingAs($this->salesUser)->get('/sand-casting/production-status')->assertOk();
        $this->actingAs($this->salesUser)->get('/lost-wax/production-status')->assertOk();

        // Sales STILL DENIED from the pages reserved for auditor / internal teams:
        $this->actingAs($this->salesUser)->get('/sand-casting/dashboard')->assertForbidden();
        $this->actingAs($this->salesUser)->get('/sand-casting/report/production')->assertForbidden();
        $this->actingAs($this->salesUser)->get('/sand-casting/report/qc-defects')->assertForbidden();
        $this->actingAs($this->salesUser)->get('/lost-wax/quality/defects')->assertForbidden();
        $this->actingAs($this->salesUser)->get('/lost-wax/report/production')->assertForbidden();
    }

    // ==========================================
    // 8. SIDEBAR ISOLATION TEST
    // ==========================================

    public function test_22_sidebar_renders_only_eight_permitted_menus_for_auditor(): void
    {
        $response = $this->actingAs($this->auditorUser)->get('/sand-casting/dashboard');
        $response->assertOk();

        // Must see permitted menus:
        $response->assertSee('Dashboard Sand Casting');
        $response->assertSee(route('sand-casting.dashboard'));
        $response->assertSee(route('sand-casting.kanban.index'));
        $response->assertSee(route('sand-casting.production-status.index'));
        $response->assertSee(route('sand-casting.report.production.index'));
        $response->assertSee(route('sand-casting.report.qc-defects.index'));
        $response->assertSee(route('lost-wax.production-status'));
        $response->assertSee(route('lost-wax.quality.defects.index'));
        $response->assertSee(route('lost-wax.report.production.index'));

        // Must NOT see forbidden menus:
        $response->assertDontSee(route('sand-casting.scan.index'));
        $response->assertDontSee(route('sand-casting.defects.index'));
        $response->assertDontSee(route('sand-casting.qc-defects.index'));
        $response->assertDontSee(route('sand-casting.casting-orders.plans'));
        $response->assertDontSee(route('sand-casting.casting-results.index'));
        $response->assertDontSee(route('lost-wax.scan.index'));
        $response->assertDontSee(route('lost-wax.scan-oven.index'));
        $response->assertDontSee(route('lost-wax.scan-siap-cor.index'));
        $response->assertDontSee(route('lost-wax.trees.index'));
        $response->assertDontSee(route('settings.defect-types.index'));
        $response->assertDontSee(route('plan.index'));
    }

    // ==========================================
    // 9. ALTERNATIVE & HIDDEN MUTATION ENDPOINTS
    // ==========================================

    public function test_23_auditor_strictly_denied_from_hidden_or_alternative_mutation_endpoints(): void
    {
        // Legacy input and tracking mutations
        $this->actingAs($this->auditorUser)->post('/kanban/move', [])->assertForbidden();
        $this->actingAs($this->auditorUser)->post('/kanban/reorder', [])->assertForbidden();
        $this->actingAs($this->auditorUser)->post('/wip/update', [])->assertForbidden();
        $this->actingAs($this->auditorUser)->post('/input/netto', [])->assertForbidden();

        // Orders and Casting results mutations (both Lost Wax and Sand Casting)
        $this->actingAs($this->auditorUser)->post('/lost-wax/print-orders', [])->assertForbidden();
        $this->actingAs($this->auditorUser)->post('/lost-wax/casting-results', [])->assertForbidden();
        $this->actingAs($this->auditorUser)->post('/sand-casting/casting-orders', [])->assertForbidden();
        $this->actingAs($this->auditorUser)->post('/sand-casting/casting-results', [])->assertForbidden();
    }

    public function test_24_redirect_behavior_for_other_roles_remains_unchanged(): void
    {
        // Admin
        $this->actingAs($this->adminUser)->get('/')->assertRedirect('/dashboard');
        $this->actingAs($this->adminUser)->get('/dashboard')->assertOk();

        // PPIC
        $this->actingAs($this->ppicUser)->get('/')->assertRedirect('/dashboard');
        $this->actingAs($this->ppicUser)->get('/dashboard')->assertOk();

        // SPV
        $this->actingAs($this->spvNetto)->get('/')->assertRedirect('/dashboard');
        $this->actingAs($this->spvNetto)->get('/dashboard')->assertOk();

        // Sales
        $this->actingAs($this->salesUser)->get('/')->assertRedirect('/sand-casting/production-status');
        $this->actingAs($this->salesUser)->get('/dashboard')->assertRedirect('/sand-casting/production-status');

        // Auditor
        $this->actingAs($this->auditorUser)->get('/')->assertRedirect('/sand-casting/dashboard');
        $this->actingAs($this->auditorUser)->get('/dashboard')->assertRedirect('/sand-casting/dashboard');
    }
}
