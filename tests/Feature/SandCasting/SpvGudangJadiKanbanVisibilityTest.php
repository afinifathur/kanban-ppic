<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\User;
use Database\Seeders\SandCastingSpvUserSeeder;
use Database\Seeders\SpvGudangJadiKanbanVisibilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SpvGudangJadiKanbanVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $spvGudangJadi;

    private User $spvNetto;

    private User $salesUser;

    private User $adminUser;

    private User $ppicUser;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Permissions
        $accessPlanning = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);
        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution', 'guard_name' => 'web']);
        $accessProdVisibility = Permission::firstOrCreate(['name' => 'access_production_visibility', 'guard_name' => 'web']);
        $accessAllKanban = Permission::firstOrCreate(['name' => 'access_all_sand_casting_kanban', 'guard_name' => 'web']);

        // 2. Setup Roles
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $ppicRole = Role::firstOrCreate(['name' => 'ppic', 'guard_name' => 'web']);
        $spvRole = Role::firstOrCreate(['name' => 'spv', 'guard_name' => 'web']);
        $salesRole = Role::firstOrCreate(['name' => 'sales', 'guard_name' => 'web']);

        $adminRole->givePermissionTo([$accessPlanning, $accessExecution]);
        $ppicRole->givePermissionTo([$accessPlanning, $accessExecution]);
        $spvRole->givePermissionTo([$accessExecution]);
        $salesRole->givePermissionTo([$accessProdVisibility]);

        // 3. Setup Target User: SPV Gudang Jadi (spvgdfl@peroniks.com)
        $this->spvGudangJadi = User::factory()->create([
            'name' => 'SPV Gudang Jadi Flange',
            'email' => 'spvgdfl@peroniks.com',
            'password' => bcrypt('password'),
            'assigned_stage' => 'gudang_jadi',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvGudangJadi->assignRole('spv');
        $this->spvGudangJadi->givePermissionTo($accessAllKanban);

        // 4. Setup Other SPV User: SPV Netto (spvnettofl@peroniks.com)
        $this->spvNetto = User::factory()->create([
            'name' => 'SPV Netto Flange',
            'email' => 'spvnettofl@peroniks.com',
            'password' => bcrypt('password'),
            'assigned_stage' => 'netto',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvNetto->assignRole('spv');

        // 5. Setup Sales User
        $this->salesUser = User::factory()->create([
            'name' => 'Sales Visibility',
            'email' => 'sales@peroniks.com',
            'password' => bcrypt('password'),
            'assigned_stage' => null,
            'product_scope' => null,
        ]);
        $this->salesUser->assignRole('sales');

        // 6. Setup Admin & PPIC Users
        $this->adminUser = User::factory()->create([
            'name' => 'Admin PPIC',
            'email' => 'adminppicpf@peroniks.com',
        ]);
        $this->adminUser->assignRole('admin');

        $this->ppicUser = User::factory()->create([
            'name' => 'PPIC Flange',
            'email' => 'ppicflange@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->ppicUser->assignRole('ppic');
    }

    private function createSampleKtr(array $attributes = []): SandCastingCastingResultLine
    {
        $plan = ProductionPlan::create([
            'code' => $attributes['code'] ?? 'SPK-'.rand(100, 999),
            'title' => $attributes['title'] ?? 'Test Plan',
            'customer' => $attributes['customer'] ?? 'Customer Test',
            'po_number' => $attributes['po_number'] ?? 'PO-'.rand(100, 999),
            'line_number' => $attributes['line_number'] ?? 1,
            'item_code' => $attributes['item_code'] ?? 'ITM-'.rand(100, 999),
            'item_name' => $attributes['item_name'] ?? 'FLANGE SAMPLE TEST',
            'qty_planned' => $attributes['qty_good'] ?? 10,
            'qty_remaining' => $attributes['qty_good'] ?? 10,
            'weight' => $attributes['unit_weight_kg'] ?? 5.0,
            'product_scope' => $attributes['product_scope'] ?? 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-'.rand(1000, 9999),
            'scheduled_date' => now()->toDateString(),
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => $attributes['qty_good'] ?? 10,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '2"',
            'aisi' => 'FC250',
        ]);

        $casting = SandCastingCastingResult::create([
            'heat_number' => $attributes['heat_number'] ?? 'HN-'.rand(1000, 9999),
            'cast_date' => now()->toDateString(),
            'shift' => 1,
            'recorded_by' => $this->adminUser->id,
        ]);

        return SandCastingCastingResultLine::create([
            'sand_casting_casting_result_id' => $casting->id,
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $attributes['traveler_number'] ?? 'KTR-'.rand(1000, 9999),
            'current_stage' => $attributes['current_stage'] ?? 'netto',
            'qty_poured' => $attributes['qty_good'] ?? 10,
            'qty_good' => $attributes['qty_good'] ?? 10,
            'qty_reject' => 0,
            'unit_weight_kg' => $attributes['unit_weight_kg'] ?? 5.0,
            'total_weight_kg' => ($attributes['qty_good'] ?? 10) * ($attributes['unit_weight_kg'] ?? 5.0),
            'line_number' => $attributes['line_number'] ?? 1,
            'queue_position' => $attributes['queue_position'] ?? 1,
        ]);
    }

    // =========================================================================
    // GENERAL KANBAN TESTS (1 to 7)
    // =========================================================================

    public function test_01_target_user_can_open_general_kanban(): void
    {
        $response = $this->actingAs($this->spvGudangJadi)->get('/sand-casting/kanban');

        $response->assertOk();
        $response->assertViewIs('sand-casting.kanban.index');
    }

    public function test_02_default_entry_is_not_locked_to_gudang_jadi(): void
    {
        $response = $this->actingAs($this->spvGudangJadi)->get('/sand-casting/kanban');

        $response->assertOk();
        // By default enters general pipeline at NETTO stage, NOT gudang_jadi
        $response->assertSee('KANBAN PRODUKSI &mdash; NETTO', false);
        $response->assertDontSee('KANBAN PRODUKSI &mdash; GUDANG JADI', false);
    }

    public function test_03_target_user_can_open_all_six_stage_pages(): void
    {
        $stages = [
            'netto' => 'NETTO',
            'bubut-od' => 'BUBUT OD',
            'bubut-cnc' => 'BUBUT CNC',
            'bor' => 'BOR',
            'qc' => 'QC',
            'gudang-jadi' => 'GUDANG JADI',
        ];

        foreach ($stages as $slug => $label) {
            $response = $this->actingAs($this->spvGudangJadi)->get("/sand-casting/kanban/stage/{$slug}");
            $response->assertOk();
            $response->assertViewIs('sand-casting.kanban.index');
            $response->assertSee("KANBAN PRODUKSI &mdash; {$label}", false);
        }

        // Print preview
        $printResponse = $this->actingAs($this->spvGudangJadi)->get('/sand-casting/kanban/stage/netto/print');
        $printResponse->assertOk();
        $printResponse->assertViewIs('sand-casting.kanban.print');

        // Excel export
        $exportResponse = $this->actingAs($this->spvGudangJadi)->get('/sand-casting/kanban/stage/netto/export/excel');
        $exportResponse->assertOk();
        $this->assertTrue(str_contains(
            (string) $exportResponse->headers->get('content-type'),
            'spreadsheetml'
        ));
    }

    public function test_04_all_product_scopes_are_visible(): void
    {
        $this->createSampleKtr([
            'item_name' => 'FLANGE BESI JIS 10K',
            'product_scope' => 'FLANGE_BESI',
            'current_stage' => 'netto',
        ]);

        $this->createSampleKtr([
            'item_name' => 'FLANGE STAINLESS ANSI 150',
            'product_scope' => 'FLANGE_STAINLESS',
            'current_stage' => 'netto',
        ]);

        $response = $this->actingAs($this->spvGudangJadi)->get('/sand-casting/kanban/stage/netto');
        $response->assertOk();
        $response->assertSee('FLANGE BESI JIS 10K');
        $response->assertSee('FLANGE STAINLESS ANSI 150');
    }

    public function test_05_kanban_card_presentation_follows_sales_read_only_presentation(): void
    {
        $this->createSampleKtr([
            'item_name' => 'FLANGE SALES STYLE TEST',
            'heat_number' => 'HN-SALES-888',
            'qty_good' => 25,
            'unit_weight_kg' => 4.0,
            'customer' => 'PT SINAR UTAMA',
            'current_stage' => 'netto',
        ]);

        $response = $this->actingAs($this->spvGudangJadi)->get('/sand-casting/kanban/stage/netto');
        $response->assertOk();

        // Visible card information
        $response->assertSee('FLANGE SALES STYLE TEST');
        $response->assertSee('HN-SALES-888');
        $response->assertSee('25');
        $response->assertSee('100');
        $response->assertSee('Aging');
        $response->assertSee('PT SINAR UTAMA');
    }

    public function test_06_ppic_queue_numbers_and_tooltips_are_hidden(): void
    {
        $this->createSampleKtr([
            'item_name' => 'FLANGE QUEUE TEST',
            'queue_position' => 1,
            'current_stage' => 'netto',
        ]);

        $response = $this->actingAs($this->spvGudangJadi)->get('/sand-casting/kanban/stage/netto');
        $response->assertOk();

        // Queue number badges & tooltips are hidden
        $response->assertDontSee('#01');
        $response->assertDontSee('title="Nomor Antrian"', false);
    }

    public function test_07_edit_antrian_controls_are_hidden(): void
    {
        $response = $this->actingAs($this->spvGudangJadi)->get('/sand-casting/kanban/stage/netto');
        $response->assertOk();

        $response->assertDontSee('EDIT ANTRIAN');
        $response->assertDontSee('openReorderModal()', false);
    }

    // =========================================================================
    // SCANNER TESTS (8 to 16)
    // =========================================================================

    public function test_08_scanner_sidebar_is_visible(): void
    {
        $response = $this->actingAs($this->spvGudangJadi)->get('/sand-casting/kanban');
        $response->assertOk();

        // Both Kanban Floor and Scanner are rendered in sidebar
        $response->assertSee('Kanban Floor');
        $response->assertSee('Scanner');
    }

    public function test_09_scanner_menu_points_to_gudang_jadi_scanner_route(): void
    {
        $response = $this->actingAs($this->spvGudangJadi)->get('/sand-casting/kanban');
        $response->assertOk();

        // Sidebar Kanban Floor points to general Kanban
        $response->assertSee(route('sand-casting.kanban.index'));

        // Sidebar Scanner points to Gudang Jadi scanner
        $response->assertSee(route('sand-casting.scan.stage', 'gudang-jadi'));
    }

    public function test_10_gudang_jadi_scanner_page_is_accessible(): void
    {
        // 1. Direct stage scanner route
        $response = $this->actingAs($this->spvGudangJadi)->get('/sand-casting/scan/stage/gudang-jadi');
        $response->assertOk();
        $response->assertViewIs('sand-casting.scan.index');
        $response->assertSee('GUDANG JADI');

        // 2. Index redirect to assigned stage scanner
        $indexResponse = $this->actingAs($this->spvGudangJadi)->get('/sand-casting/scan');
        $indexResponse->assertRedirect(route('sand-casting.scan.stage', 'gudang-jadi'));
    }

    public function test_11_gudang_jadi_scanner_execution_remains_functional(): void
    {
        $line = $this->createSampleKtr([
            'traveler_number' => 'KTR-GD-EXEC-01',
            'current_stage' => 'gudang_jadi',
            'qty_good' => 30,
        ]);

        // Previous stage execution (QC_FINAL_INSPECTION) is required by state machine
        $line->stageExecutions()->create([
            'stage' => 'qc',
            'checkpoint_code' => 'QC_FINAL_INSPECTION',
            'input_qty' => 30,
            'good_qty' => 30,
            'defect_qty' => 0,
            'status' => 'CONFIRMED',
            'operator_id' => $this->adminUser->id,
            'executed_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($this->spvGudangJadi)->postJson('/sand-casting/scan/gudang-jadi/execute', [
            'traveler_number' => $line->traveler_number,
            'notes' => 'Penerimaan Gudang Jadi oleh SPV Gudang',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.stage', 'gudang_jadi');
        $response->assertJsonPath('data.input_qty', 30);

        $this->assertDatabaseHas('sand_casting_stage_executions', [
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'gudang_jadi',
            'checkpoint_code' => 'GUDANG_RECEIVE',
            'operator_id' => $this->spvGudangJadi->id,
        ]);
    }

    public function test_12_scanner_access_for_netto_is_denied(): void
    {
        $this->actingAs($this->spvGudangJadi)->get('/sand-casting/scan/netto')->assertForbidden();
        $this->actingAs($this->spvGudangJadi)->get('/sand-casting/scan/stage/netto')->assertForbidden();
        $this->actingAs($this->spvGudangJadi)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => 'KTR-TEST',
        ])->assertForbidden();
    }

    public function test_13_scanner_access_for_bubut_od_is_denied(): void
    {
        $this->actingAs($this->spvGudangJadi)->get('/sand-casting/scan/stage/bubut-od')->assertForbidden();
        $this->actingAs($this->spvGudangJadi)->postJson('/sand-casting/scan/bubut-od/execute', [
            'traveler_number' => 'KTR-TEST',
        ])->assertForbidden();
    }

    public function test_14_scanner_access_for_bubut_cnc_is_denied(): void
    {
        $this->actingAs($this->spvGudangJadi)->get('/sand-casting/scan/stage/bubut-cnc')->assertForbidden();
        $this->actingAs($this->spvGudangJadi)->postJson('/sand-casting/scan/bubut-cnc/execute', [
            'traveler_number' => 'KTR-TEST',
        ])->assertForbidden();
    }

    public function test_15_scanner_access_for_bor_is_denied(): void
    {
        $this->actingAs($this->spvGudangJadi)->get('/sand-casting/scan/stage/bor')->assertForbidden();
        $this->actingAs($this->spvGudangJadi)->postJson('/sand-casting/scan/bor/execute', [
            'traveler_number' => 'KTR-TEST',
        ])->assertForbidden();
    }

    public function test_16_scanner_access_for_qc_is_denied(): void
    {
        $this->actingAs($this->spvGudangJadi)->get('/sand-casting/scan/stage/qc')->assertForbidden();
        $this->actingAs($this->spvGudangJadi)->postJson('/sand-casting/scan/qc/execute', [
            'traveler_number' => 'KTR-TEST',
        ])->assertForbidden();
    }

    // =========================================================================
    // OTHER RESTRICTIONS (17 to 20)
    // =========================================================================

    public function test_17_ppic_queue_reorder_post_returns_403(): void
    {
        $response = $this->actingAs($this->spvGudangJadi)->post('/sand-casting/kanban/stage/netto/reorder', [
            'line_number' => 1,
            'from_position' => 2,
            'to_position' => 1,
        ]);

        $response->assertForbidden();
    }

    public function test_18_sand_casting_production_status_remains_denied(): void
    {
        $response = $this->actingAs($this->spvGudangJadi)->get('/sand-casting/production-status');
        $response->assertForbidden();

        $details = $this->actingAs($this->spvGudangJadi)->getJson('/sand-casting/production-status/details?code=TEST');
        $details->assertForbidden();
    }

    public function test_19_lost_wax_production_status_remains_denied(): void
    {
        $response = $this->actingAs($this->spvGudangJadi)->get('/lost-wax/production-status');
        $response->assertForbidden();

        $trees = $this->actingAs($this->spvGudangJadi)->getJson('/lost-wax/production-status/trees?code=TEST');
        $trees->assertForbidden();

        $export = $this->actingAs($this->spvGudangJadi)->get('/lost-wax/production-status/export');
        $export->assertForbidden();
    }

    public function test_20_planning_settings_and_unrelated_mutations_remain_denied(): void
    {
        // Planning
        $this->actingAs($this->spvGudangJadi)->get('/plan')->assertForbidden();
        $this->actingAs($this->spvGudangJadi)->get('/sand-casting/casting-orders/plans')->assertForbidden();

        // Settings / Master Data
        $this->actingAs($this->spvGudangJadi)->get('/settings/defect-types')->assertForbidden();
        $this->actingAs($this->spvGudangJadi)->get('/settings/customers')->assertForbidden();

        // Floor Defect Recording & QC Verification
        $this->actingAs($this->spvGudangJadi)->get('/sand-casting/defects')->assertForbidden();
        $this->actingAs($this->spvGudangJadi)->get('/sand-casting/qc-defects')->assertForbidden();
    }

    // =========================================================================
    // REGRESSION TESTS (21 to 24)
    // =========================================================================

    public function test_21_sales_visibility_remains_unchanged(): void
    {
        // Sales can access Sand Casting Kanban
        $this->actingAs($this->salesUser)->get('/sand-casting/kanban')->assertOk();
        $this->actingAs($this->salesUser)->get('/sand-casting/kanban/stage/netto')->assertOk();

        // Sales can access Sand Casting Production Status
        $this->actingAs($this->salesUser)->get('/sand-casting/production-status')->assertOk();

        // Sales can access Lost Wax Production Status
        $this->actingAs($this->salesUser)->get('/lost-wax/production-status')->assertOk();

        // Sales is still strictly denied from mutations & scanners
        $this->actingAs($this->salesUser)->post('/sand-casting/kanban/stage/netto/reorder', [])->assertForbidden();
        $this->actingAs($this->salesUser)->get('/sand-casting/scan/netto')->assertForbidden();
    }

    public function test_22_existing_spv_production_users_remain_restricted_to_their_assigned_stages(): void
    {
        // SPV Netto can view Netto Kanban
        $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/stage/netto')->assertOk();

        // SPV Netto CANNOT view Bubut OD Kanban (isolation preserved)
        $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/stage/bubut-od')->assertForbidden();

        // SPV Netto CANNOT view Bubut CNC Kanban
        $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/stage/bubut-cnc')->assertForbidden();

        // SPV Netto CANNOT access Sand Casting Production Status
        $this->actingAs($this->spvNetto)->get('/sand-casting/production-status')->assertForbidden();
    }

    public function test_23_admin_and_ppic_workflows_remain_unchanged(): void
    {
        // Admin can access planning, settings, and all stages
        $this->actingAs($this->adminUser)->get('/plan')->assertOk();
        $this->actingAs($this->adminUser)->get('/settings/customers')->assertOk();
        $this->actingAs($this->adminUser)->get('/sand-casting/kanban/stage/netto')->assertOk();
        $this->actingAs($this->adminUser)->get('/sand-casting/kanban/stage/gudang-jadi')->assertOk();
        $this->actingAs($this->adminUser)->get('/sand-casting/production-status')->assertOk();

        // PPIC can access planning, reports, and kanban reordering
        $this->actingAs($this->ppicUser)->get('/plan')->assertOk();
        $this->actingAs($this->ppicUser)->get('/sand-casting/report/production')->assertOk();
        $this->actingAs($this->ppicUser)->get('/sand-casting/production-status')->assertOk();
    }

    public function test_24_existing_scanner_functionality_for_other_spv_roles_remains_unchanged(): void
    {
        // SPV Netto can view and execute Netto scanner
        $this->actingAs($this->spvNetto)->get('/sand-casting/scan/stage/netto')->assertOk();

        $line = $this->createSampleKtr([
            'traveler_number' => 'KTR-NETTO-EXEC-01',
            'current_stage' => 'netto',
            'qty_good' => 20,
        ]);

        $response = $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line->traveler_number,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.stage', 'netto');

        // SPV Netto cannot execute Gudang Jadi scanner
        $this->actingAs($this->spvNetto)->postJson('/sand-casting/scan/gudang-jadi/execute', [
            'traveler_number' => $line->traveler_number,
        ])->assertForbidden();
    }

    // =========================================================================
    // SEEDER IDEMPOTENCY TEST (25)
    // =========================================================================

    public function test_25_seeder_is_idempotent(): void
    {
        // Running Dedicated Seeder
        $seeder = new SpvGudangJadiKanbanVisibilitySeeder;
        $seeder->run();
        $seeder->run();

        $this->assertTrue($this->spvGudangJadi->fresh()->hasPermissionTo('access_all_sand_casting_kanban'));
        $this->assertTrue($this->spvGudangJadi->fresh()->hasRole('spv'));
        $this->assertFalse($this->spvGudangJadi->fresh()->hasPermissionTo('access_production_visibility'));
        $this->assertFalse($this->spvGudangJadi->fresh()->hasPermissionTo('access_planning'));

        // Running Main SPV Seeder
        $mainSeeder = new SandCastingSpvUserSeeder;
        $mainSeeder->run();

        $this->assertTrue($this->spvGudangJadi->fresh()->hasPermissionTo('access_all_sand_casting_kanban'));
    }
}
