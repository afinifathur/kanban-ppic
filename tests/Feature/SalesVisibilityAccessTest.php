<?php

namespace Tests\Feature;

use App\Models\ProductionPlan;
use App\Models\User;
use Database\Seeders\SalesVisibilityUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesVisibilityAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $salesUser;

    private User $adminUser;

    private User $ppicUser;

    private User $spvNetto;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Permissions
        $permVisibility = Permission::firstOrCreate(['name' => 'access_production_visibility']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution']);
        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning']);

        // 2. Setup Roles
        $roleSales = Role::firstOrCreate(['name' => 'sales']);
        $roleSales->syncPermissions([$permVisibility]);

        $roleAdmin = Role::firstOrCreate(['name' => 'admin']);
        $roleAdmin->syncPermissions([$permPlanning, $permExecution]);

        $rolePpic = Role::firstOrCreate(['name' => 'ppic']);
        $rolePpic->syncPermissions([$permPlanning, $permExecution]);

        $roleSpv = Role::firstOrCreate(['name' => 'spv']);
        $roleSpv->syncPermissions([$permExecution]);

        // 3. Create Users
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
    // 1. ALLOWED ACCESS TESTS
    // ==========================================

    public function test_sales_user_can_access_sand_casting_kanban_entry(): void
    {
        $response = $this->actingAs($this->salesUser)->get('/sand-casting/kanban');

        $response->assertOk();
        $response->assertViewIs('sand-casting.kanban.index');
        $response->assertSee('KANBAN PRODUKSI');
        $response->assertSee('NETTO');
    }

    public function test_sales_user_can_access_all_six_kanban_stages(): void
    {
        $stages = ['netto', 'bubut-od', 'bubut-cnc', 'bor', 'qc', 'gudang-jadi'];

        foreach ($stages as $stage) {
            $response = $this->actingAs($this->salesUser)->get("/sand-casting/kanban/stage/{$stage}");
            $response->assertOk();
            $response->assertViewIs('sand-casting.kanban.index');
        }
    }

    public function test_sales_user_can_access_kanban_print_preview_and_excel_export(): void
    {
        $printResponse = $this->actingAs($this->salesUser)->get('/sand-casting/kanban/stage/netto/print');
        $printResponse->assertOk();
        $printResponse->assertViewIs('sand-casting.kanban.print');

        $exportResponse = $this->actingAs($this->salesUser)->get('/sand-casting/kanban/stage/netto/export/excel');
        $exportResponse->assertOk();
        $this->assertTrue(str_contains(
            (string) $exportResponse->headers->get('content-type'),
            'spreadsheetml'
        ));
    }

    public function test_sales_user_can_access_sand_casting_production_status_and_details(): void
    {
        $statusResponse = $this->actingAs($this->salesUser)->get('/sand-casting/production-status');
        $statusResponse->assertOk();
        $statusResponse->assertViewIs('sand-casting.production-status.index');

        // Test details endpoint with a sample plan ID
        $plan = ProductionPlan::create([
            'code' => 'TEST-PLAN-01',
            'title' => 'Test Plan',
            'customer' => 'Customer A',
            'po_number' => 'PO-TEST-01',
            'line_number' => 1,
            'item_code' => 'ITM-TEST-01',
            'item_name' => 'FLANGE 2 INCH',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'weight' => 5.5,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'is_closed' => false,
        ]);

        $detailsResponse = $this->actingAs($this->salesUser)->getJson("/sand-casting/production-status/details?production_plan_id={$plan->id}");
        $detailsResponse->assertOk();
    }

    public function test_sales_user_can_access_lost_wax_production_status_and_endpoints(): void
    {
        $response = $this->actingAs($this->salesUser)->get('/lost-wax/production-status');
        $response->assertOk();
        $response->assertViewIs('lost-wax.production-status.index');

        $treesResponse = $this->actingAs($this->salesUser)->getJson('/lost-wax/production-status/trees?code=UNKNOWN');
        $treesResponse->assertOk();

        $exportResponse = $this->actingAs($this->salesUser)->get('/lost-wax/production-status/export');
        $exportResponse->assertOk();
    }

    // ==========================================
    // 2. DENIED ACCESS TESTS (LEAST PRIVILEGE)
    // ==========================================

    public function test_sales_user_strictly_denied_from_kanban_queue_reorder(): void
    {
        $response = $this->actingAs($this->salesUser)->post('/sand-casting/kanban/stage/netto/reorder', [
            'line_number' => 1,
            'from_position' => 2,
            'to_position' => 1,
        ]);

        $response->assertForbidden();
    }

    public function test_sales_user_strictly_denied_from_sand_casting_scanners(): void
    {
        $this->actingAs($this->salesUser)->get('/sand-casting/scan')->assertForbidden();
        $this->actingAs($this->salesUser)->get('/sand-casting/scan/netto')->assertForbidden();
        $this->actingAs($this->salesUser)->get('/sand-casting/scan/stage/netto')->assertForbidden();
        $this->actingAs($this->salesUser)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => 'KTR-001',
        ])->assertForbidden();

        // Generic alias scanner
        $this->actingAs($this->salesUser)->postJson('/scan/netto/execute', [
            'traveler_number' => 'KTR-001',
        ])->assertForbidden();
    }

    public function test_sales_user_strictly_denied_from_lost_wax_scanners_void_and_mutations(): void
    {
        // Lost Wax Scanners
        $this->actingAs($this->salesUser)->get('/lost-wax/scan')->assertForbidden();
        $this->actingAs($this->salesUser)->post('/lost-wax/scan', ['barcode' => 'TEST'])->assertForbidden();
        $this->actingAs($this->salesUser)->get('/lost-wax/scan-oven')->assertForbidden();
        $this->actingAs($this->salesUser)->post('/lost-wax/scan-oven', ['barcode' => 'TEST'])->assertForbidden();

        // Trees endpoints
        $this->actingAs($this->salesUser)->get('/lost-wax/trees')->assertForbidden();
        $this->actingAs($this->salesUser)->post('/lost-wax/trees/print-thermal')->assertForbidden();
        $this->actingAs($this->salesUser)->post('/lost-wax/trees/bulk-rack')->assertForbidden();
    }

    public function test_sales_user_strictly_denied_from_planning_settings_and_reports(): void
    {
        // Planning
        $this->actingAs($this->salesUser)->get('/plan')->assertForbidden();
        $this->actingAs($this->salesUser)->get('/sand-casting/casting-orders/plans')->assertForbidden();
        $this->actingAs($this->salesUser)->get('/lost-wax/print-orders/plans')->assertForbidden();

        // Floor Defect Recording
        $this->actingAs($this->salesUser)->get('/sand-casting/defects')->assertForbidden();
        $this->actingAs($this->salesUser)->get('/sand-casting/qc-defects')->assertForbidden();

        // Lost Wax Rack Monitor
        $this->actingAs($this->salesUser)->get('/lost-wax/rack-monitor')->assertForbidden();

        // Lost Wax Reports
        $this->actingAs($this->salesUser)->get('/lost-wax/reports/ready-for-oven')->assertForbidden();
        $this->actingAs($this->salesUser)->get('/lost-wax/report/production')->assertForbidden();
    }

    public function test_sales_user_strictly_denied_from_settings_and_master_data(): void
    {
        $defect = \App\Models\DefectType::create(['name' => 'Porosity', 'department' => 'netto']);
        $customer = \App\Models\Customer::create(['name' => 'Test Customer', 'code' => 'TC01']);

        // Defect Types Settings
        $this->actingAs($this->salesUser)->get('/settings/defect-types')->assertForbidden();
        $this->actingAs($this->salesUser)->post('/settings/defect-types', ['name' => 'Crack'])->assertForbidden();
        $this->actingAs($this->salesUser)->put("/settings/defect-types/{$defect->id}", ['name' => 'Crack'])->assertForbidden();

        // Customer Settings
        $this->actingAs($this->salesUser)->get('/settings/customers')->assertForbidden();
        $this->actingAs($this->salesUser)->post('/settings/customers', ['name' => 'Cust'])->assertForbidden();
        $this->actingAs($this->salesUser)->delete("/settings/customers/{$customer->id}")->assertForbidden();

        // Assembly Photos Settings
        $this->actingAs($this->salesUser)->get('/settings/assembly-photos')->assertForbidden();
        $this->actingAs($this->salesUser)->post('/settings/assembly-photos', [])->assertForbidden();
    }

    public function test_sales_user_strictly_denied_from_reports_and_analytics(): void
    {
        // Defect Dashboard
        $this->actingAs($this->salesUser)->get('/dashboard/defects')->assertForbidden();

        // Production Reports (Cast Iron)
        $this->actingAs($this->salesUser)->get('/report')->assertForbidden();
        $this->actingAs($this->salesUser)->get('/report/export/daily')->assertForbidden();

        // Defect Reports (Cast Iron)
        $this->actingAs($this->salesUser)->get('/report-defects')->assertForbidden();
        $this->actingAs($this->salesUser)->get('/report-defects/summary')->assertForbidden();
        $this->actingAs($this->salesUser)->get('/report-defects/export/excel')->assertForbidden();

        // Sand Casting Reports
        $this->actingAs($this->salesUser)->get('/sand-casting/report/production')->assertForbidden();
        $this->actingAs($this->salesUser)->get('/sand-casting/report/production/export/excel')->assertForbidden();
        $this->actingAs($this->salesUser)->get('/sand-casting/report/qc-defects')->assertForbidden();
        $this->actingAs($this->salesUser)->get('/sand-casting/report/qc-defects/export/excel')->assertForbidden();

        // Sand Casting Dashboard
        $this->actingAs($this->salesUser)->get('/sand-casting/dashboard')->assertForbidden();
        $this->actingAs($this->salesUser)->get('/sand-casting/dashboard/data')->assertForbidden();
    }

    public function test_sales_user_strictly_denied_from_floor_inputs_kanban_wip_and_defects(): void
    {
        $item = \App\Models\ProductionItem::create([
            'code' => 'TEST-01',
            'heat_number' => 'HN-01',
            'item_name' => 'Flange Test',
            'current_dept' => 'netto',
            'line_number' => 1,
            'dept_entry_at' => now(),
            'qty_pcs' => 10,
            'weight_kg' => 10.0,
        ]);

        $history = \App\Models\ProductionHistory::create([
            'item_id' => $item->id,
            'from_dept' => 'cor',
            'to_dept' => 'netto',
            'qty_pcs' => 10,
            'weight_kg' => 10.0,
            'moved_at' => now(),
        ]);

        // Legacy Inputs
        $this->actingAs($this->salesUser)->get('/input/netto')->assertForbidden();
        $this->actingAs($this->salesUser)->get('/input/netto/create')->assertForbidden();
        $this->actingAs($this->salesUser)->post('/input/netto', [])->assertForbidden();
        $this->actingAs($this->salesUser)->patch("/input/history/{$history->id}", [])->assertForbidden();
        $this->actingAs($this->salesUser)->delete("/input/history/{$history->id}")->assertForbidden();

        // Legacy Kanban
        $this->actingAs($this->salesUser)->get('/kanban/netto')->assertForbidden();
        $this->actingAs($this->salesUser)->post('/kanban/move', [])->assertForbidden();
        $this->actingAs($this->salesUser)->post('/kanban/reorder', [])->assertForbidden();

        // Defect Entry
        $this->actingAs($this->salesUser)->get('/defects/netto')->assertForbidden();
        $this->actingAs($this->salesUser)->post("/defects/{$item->id}", [])->assertForbidden();

        // WIP
        $this->actingAs($this->salesUser)->get('/wip')->assertForbidden();
        $this->actingAs($this->salesUser)->post('/wip/update', [])->assertForbidden();
        $this->actingAs($this->salesUser)->get('/report/wip')->assertForbidden();
    }

    public function test_unauthenticated_user_redirected_to_login(): void
    {
        $this->get('/sand-casting/kanban')->assertRedirect('/login');
        $this->get('/sand-casting/production-status')->assertRedirect('/login');
        $this->get('/lost-wax/production-status')->assertRedirect('/login');
    }

    // ==========================================
    // 3. UI, REDIRECT, AND REGRESSION TESTS
    // ==========================================

    public function test_sales_user_redirected_to_production_status_from_root_and_dashboard(): void
    {
        $this->actingAs($this->salesUser)->get('/')->assertRedirect('/sand-casting/production-status');
        $this->actingAs($this->salesUser)->get('/dashboard')->assertRedirect('/sand-casting/production-status');
    }

    public function test_sales_user_sidebar_displays_only_three_authorized_links(): void
    {
        $response = $this->actingAs($this->salesUser)->get('/sand-casting/production-status');
        $response->assertOk();

        // Should see the 3 authorized links in sidebar
        $response->assertSee(route('sand-casting.kanban.index'));
        $response->assertSee(route('sand-casting.production-status.index'));
        $response->assertSee(route('lost-wax.production-status'));

        // Should NOT see unauthorized sidebar items
        $response->assertDontSee(route('sand-casting.scan.index'));
        $response->assertDontSee(route('lost-wax.scan.index'));
        $response->assertDontSee(route('plan.index'));
        $response->assertDontSee(route('settings.customers.index'));
        $response->assertDontSee(route('settings.defect-types.index'));
    }

    public function test_kanban_view_does_not_display_scan_or_reorder_buttons_to_sales(): void
    {
        $response = $this->actingAs($this->salesUser)->get('/sand-casting/kanban');
        $response->assertOk();

        $response->assertDontSee('EDIT ANTRIAN');
        $response->assertDontSee(route('sand-casting.scan.stage', 'netto'));
    }

    public function test_user_operational_identity_for_sales(): void
    {
        $this->assertEquals('Sales Visibility', $this->salesUser->getOperationalTitle());
        $this->assertEquals('MONITORING', $this->salesUser->getOperationalSubtitle());
    }

    public function test_existing_spv_kanban_access_remains_intact(): void
    {
        // SPV Netto can view Netto Kanban
        $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/stage/netto')->assertOk();

        // SPV Netto cannot view Bubut OD Kanban (stage isolation preserved)
        $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/stage/bubut-od')->assertForbidden();
    }

    public function test_sales_seeder_is_idempotent_and_requires_password_for_new_account(): void
    {
        // Calling seeder when user already exists should not fail or reset password
        $originalHash = $this->salesUser->password;
        $seeder = new SalesVisibilityUserSeeder;
        $seeder->run();

        $this->assertEquals($originalHash, $this->salesUser->fresh()->password);
        $this->assertTrue($this->salesUser->fresh()->hasRole('sales'));
        $this->assertTrue($this->salesUser->fresh()->can('access_production_visibility'));
        $this->assertFalse($this->salesUser->fresh()->can('access_execution'));

        // Test exception on missing env var when creating new user
        $this->salesUser->delete();
        putenv('SALES_INITIAL_PASSWORD=');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SALES_INITIAL_PASSWORD');
        $seeder->run();
    }

    public function test_existing_roles_retain_access_to_remediated_routes(): void
    {
        // Admin can access settings
        $this->actingAs($this->adminUser)->get('/settings/defect-types')->assertOk();
        $this->actingAs($this->adminUser)->get('/settings/customers')->assertOk();
        $this->actingAs($this->adminUser)->get('/settings/assembly-photos')->assertOk();

        // PPIC can access settings and reports
        $this->actingAs($this->ppicUser)->get('/settings/defect-types')->assertOk();
        $this->actingAs($this->ppicUser)->get('/report')->assertOk();
        $this->actingAs($this->ppicUser)->get('/sand-casting/report/production')->assertOk();

        // SPV can access floor input and kanban
        $this->actingAs($this->spvNetto)->get('/input/netto')->assertOk();
        $this->actingAs($this->spvNetto)->get('/kanban/netto')->assertOk();
        $this->actingAs($this->spvNetto)->get('/wip')->assertOk();
    }
}
