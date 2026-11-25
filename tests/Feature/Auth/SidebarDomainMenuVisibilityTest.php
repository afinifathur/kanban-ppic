<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Fitting\FittingStageAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SidebarDomainMenuVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminPpicPf;

    protected User $adminPpicFl;

    protected User $otherAdmin;

    protected User $salesUser;

    protected User $auditorUser;

    protected User $spvSandUser;

    protected User $spvFittingUser;

    protected User $qcFittingUser;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create standard permissions
        $pPlanning = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);
        $pExecution = Permission::firstOrCreate(['name' => 'access_execution', 'guard_name' => 'web']);
        $pFittingExec = Permission::firstOrCreate(['name' => 'access_fitting_execution', 'guard_name' => 'web']);
        $pVerifyQc = Permission::firstOrCreate(['name' => FittingStageAuthorizationService::PERMISSION_VERIFY_QC, 'guard_name' => 'web']);
        $pAllFittingKanban = Permission::firstOrCreate(['name' => FittingStageAuthorizationService::PERMISSION_ALL_KANBAN, 'guard_name' => 'web']);
        $pFittingVisibility = Permission::firstOrCreate(['name' => FittingStageAuthorizationService::PERMISSION_VISIBILITY, 'guard_name' => 'web']);

        // 2. Create roles
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $ppicRole = Role::firstOrCreate(['name' => 'ppic', 'guard_name' => 'web']);
        $spvRole = Role::firstOrCreate(['name' => 'spv', 'guard_name' => 'web']);
        $spvFittingRole = Role::firstOrCreate(['name' => 'spv_fitting', 'guard_name' => 'web']);
        $salesRole = Role::firstOrCreate(['name' => 'sales', 'guard_name' => 'web']);
        $auditorRole = Role::firstOrCreate(['name' => 'auditor', 'guard_name' => 'web']);
        $qcFittingRole = Role::firstOrCreate(['name' => 'admin_qc_fitting', 'guard_name' => 'web']);
        $qcRole = Role::firstOrCreate(['name' => 'qc', 'guard_name' => 'web']);

        $adminRole->syncPermissions([$pPlanning, $pExecution]);
        $ppicRole->syncPermissions([$pPlanning, $pExecution]);
        $spvRole->syncPermissions([$pExecution]);
        $spvFittingRole->syncPermissions([$pExecution, $pFittingExec]);
        $qcFittingRole->syncPermissions([$pPlanning, $pExecution, $pVerifyQc, $pAllFittingKanban, $pFittingVisibility]);

        // 3. Target 1: adminppicpf@peroniks.com (Role: admin, Scope: NULL)
        $this->adminPpicPf = User::factory()->create([
            'name' => 'Admin PPIC',
            'email' => 'adminppicpf@peroniks.com',
            'product_scope' => null,
        ]);
        $this->adminPpicPf->assignRole('admin');

        // 4. Target 2: adminppicfl@peroniks.com (Role: ppic, Scope: FLANGE_BESI)
        $this->adminPpicFl = User::factory()->create([
            'name' => 'Admin PPIC Flange',
            'email' => 'adminppicfl@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->adminPpicFl->assignRole('ppic');
        $this->adminPpicFl->givePermissionTo('access_execution');

        // 5. Control accounts
        $this->otherAdmin = User::factory()->create([
            'name' => 'Super Administrator',
            'email' => 'admin@peroniks.com',
            'product_scope' => null,
        ]);
        $this->otherAdmin->assignRole('admin');

        $this->salesUser = User::factory()->create([
            'name' => 'Sales Officer',
            'email' => 'sales@peroniks.com',
        ]);
        $this->salesUser->assignRole('sales');

        $this->auditorUser = User::factory()->create([
            'name' => 'Internal Auditor',
            'email' => 'auditor@peroniks.com',
        ]);
        $this->auditorUser->assignRole('auditor');

        $this->spvSandUser = User::factory()->create([
            'name' => 'SPV Netto Sand',
            'email' => 'spvnettofl@peroniks.com',
            'assigned_stage' => 'netto',
        ]);
        $this->spvSandUser->assignRole('spv');

        $this->spvFittingUser = User::factory()->create([
            'name' => 'SPV Netto Fitting',
            'email' => 'spvnettopf@peroniks.com',
            'assigned_stage' => 'netto_pf',
        ]);
        $this->spvFittingUser->assignRole('spv_fitting');

        $this->qcFittingUser = User::factory()->create([
            'name' => 'Admin QC Fitting',
            'email' => 'adminqcfitting@peroniks.com',
        ]);
        $this->qcFittingUser->assignRole('admin_qc_fitting');
    }

    public function test_adminppicpf_sidebar_hides_sand_casting_and_shows_lost_wax_and_fitting(): void
    {
        $response = $this->actingAs($this->adminPpicPf)->get(route('plan.index'));
        $response->assertOk();

        $content = $response->getContent();

        // Sand Casting MUST be completely hidden
        $this->assertStringNotContainsString('id="sandCastingMenu"', $content);
        $this->assertStringNotContainsString('onclick="toggleSandCastingMenu()"', $content);
        $this->assertStringNotContainsString('<span class="sidebar-text">Sand Casting</span>', $content);
        $this->assertStringNotContainsString(route('sand-casting.dashboard'), $content);
        $this->assertStringNotContainsString(route('sand-casting.casting-orders.plans'), $content);
        $this->assertStringNotContainsString(route('sand-casting.casting-results.index'), $content);
        $this->assertStringNotContainsString(route('sand-casting.kanban.index'), $content);
        $this->assertStringNotContainsString(route('sand-casting.production-status.index'), $content);
        $this->assertStringNotContainsString(route('sand-casting.report.production.index'), $content);

        // Lost Wax MUST be visible
        $this->assertStringContainsString('id="lostWaxMenu"', $content);
        $this->assertStringContainsString('onclick="toggleLostWaxMenu()"', $content);
        $this->assertStringContainsString('<span class="sidebar-text">Lost Wax</span>', $content);
        $this->assertStringContainsString(route('lost-wax.print-orders.plans'), $content);
        $this->assertStringContainsString(route('lost-wax.reports.ready-for-oven.index'), $content);
        $this->assertStringContainsString(route('lost-wax.reports.ready-for-casting.index'), $content);

        // Fitting Production MUST be visible
        $this->assertStringContainsString('id="fittingMenu"', $content);
        $this->assertStringContainsString('onclick="toggleFittingMenu()"', $content);
        $this->assertStringContainsString('<span class="sidebar-text">Fitting Production</span>', $content);
        $this->assertStringContainsString(route('fitting.dashboard'), $content);
        $this->assertStringContainsString(route('fitting.transfers.index'), $content);
        $this->assertStringContainsString(route('fitting.kanban.index'), $content);

        // Shared Menus MUST remain visible
        $this->assertStringContainsString(route('plan.index'), $content);
        $this->assertStringContainsString('Daftar Rencana', $content);
        $this->assertStringContainsString(route('settings.defect-types.index'), $content);
        $this->assertStringContainsString('Setting Kerusakan', $content);
        $this->assertStringContainsString(route('settings.customers.index'), $content);
        $this->assertStringContainsString('Setting Customer', $content);
        $this->assertStringContainsString(route('settings.assembly-photos.index'), $content);
        $this->assertStringContainsString('Foto Rangkai', $content);
    }

    public function test_adminppicfl_sidebar_shows_sand_casting_and_hides_lost_wax_and_fitting(): void
    {
        $response = $this->actingAs($this->adminPpicFl)->get(route('plan.index'));
        $response->assertOk();

        $content = $response->getContent();

        // Sand Casting MUST be visible
        $this->assertStringContainsString('id="sandCastingMenu"', $content);
        $this->assertStringContainsString('toggleSandCastingMenu()', $content);
        $this->assertStringContainsString(route('sand-casting.dashboard'), $content);
        $this->assertStringContainsString(route('sand-casting.casting-orders.plans'), $content);
        $this->assertStringContainsString(route('sand-casting.casting-results.index'), $content);
        $this->assertStringContainsString(route('sand-casting.kanban.index'), $content);
        $this->assertStringContainsString(route('sand-casting.production-status.index'), $content);
        $this->assertStringContainsString(route('sand-casting.report.production.index'), $content);

        // Lost Wax MUST be completely hidden
        $this->assertStringNotContainsString('id="lostWaxMenu"', $content);
        $this->assertStringNotContainsString('onclick="toggleLostWaxMenu()"', $content);
        $this->assertStringNotContainsString('<span class="sidebar-text">Lost Wax</span>', $content);
        $this->assertStringNotContainsString(route('lost-wax.print-orders.plans'), $content);
        $this->assertStringNotContainsString(route('lost-wax.reports.ready-for-oven.index'), $content);
        $this->assertStringNotContainsString(route('lost-wax.reports.ready-for-casting.index'), $content);
        $this->assertStringNotContainsString(route('lost-wax.rack-monitor.index'), $content);

        // Fitting Production MUST be hidden
        $this->assertStringNotContainsString('id="fittingMenu"', $content);
        $this->assertStringNotContainsString('onclick="toggleFittingMenu()"', $content);
        $this->assertStringNotContainsString('<span class="sidebar-text">Fitting Production</span>', $content);
        $this->assertStringNotContainsString(route('fitting.dashboard'), $content);
        $this->assertStringNotContainsString(route('fitting.transfers.index'), $content);

        // Shared Menus MUST remain visible
        $this->assertStringContainsString(route('plan.index'), $content);
        $this->assertStringContainsString('Daftar Rencana', $content);
        $this->assertStringContainsString(route('settings.defect-types.index'), $content);
        $this->assertStringContainsString('Setting Kerusakan', $content);
        $this->assertStringContainsString(route('settings.customers.index'), $content);
        $this->assertStringContainsString('Setting Customer', $content);
        $this->assertStringContainsString(route('settings.assembly-photos.index'), $content);
        $this->assertStringContainsString('Foto Rangkai', $content);
    }

    public function test_other_admin_sees_both_sand_casting_and_lost_wax_and_fitting(): void
    {
        $response = $this->actingAs($this->otherAdmin)->get(route('plan.index'));
        $response->assertOk();

        $content = $response->getContent();

        // Other admin retains access to ALL domains
        $this->assertStringContainsString('id="sandCastingMenu"', $content);
        $this->assertStringContainsString('id="lostWaxMenu"', $content);
        $this->assertStringContainsString('id="fittingMenu"', $content);
        $this->assertStringContainsString(route('plan.index'), $content);
        $this->assertStringContainsString(route('settings.defect-types.index'), $content);
    }

    public function test_sales_role_sidebar_behavior_remains_unchanged(): void
    {
        $response = $this->actingAs($this->salesUser)->get(route('sand-casting.production-status.index'));
        $response->assertOk();

        $content = $response->getContent();

        // Sales branch is rendered
        $this->assertStringContainsString('Sales Visibility', $content);
        $this->assertStringContainsString(route('sand-casting.production-status.index'), $content);
        $this->assertStringContainsString(route('lost-wax.production-status'), $content);

        // Admin-only toggle menus are not rendered
        $this->assertStringNotContainsString('id="sandCastingMenu"', $content);
        $this->assertStringNotContainsString('id="lostWaxMenu"', $content);
    }

    public function test_auditor_role_sidebar_behavior_remains_unchanged(): void
    {
        $response = $this->actingAs($this->auditorUser)->get(route('sand-casting.dashboard'));
        $response->assertOk();

        $content = $response->getContent();

        // Auditor branch is rendered
        $this->assertStringContainsString('Dashboard Sand Casting', $content);
        $this->assertStringContainsString(route('sand-casting.dashboard'), $content);
        $this->assertStringContainsString(route('lost-wax.production-status'), $content);

        // Admin-only toggle menus are not rendered
        $this->assertStringNotContainsString('id="sandCastingMenu"', $content);
        $this->assertStringNotContainsString('id="lostWaxMenu"', $content);
    }

    public function test_spv_sand_casting_sidebar_behavior_remains_unchanged(): void
    {
        $response = $this->actingAs($this->spvSandUser)->get(route('sand-casting.kanban.show', 'netto'));
        $response->assertOk();

        $content = $response->getContent();

        $this->assertStringContainsString('SPV NETTO', $content);
        $this->assertStringContainsString('Kanban Floor', $content);
        $this->assertStringContainsString('Scanner', $content);
    }

    public function test_spv_fitting_sidebar_behavior_remains_unchanged(): void
    {
        $response = $this->actingAs($this->spvFittingUser)->get(route('fitting.kanban.show', 'netto-pf'));
        $response->assertOk();

        $content = $response->getContent();

        $this->assertStringContainsString('Operasional Fitting', $content);
        $this->assertStringContainsString('Kanban Floor', $content);
    }

    public function test_qc_fitting_sidebar_behavior_remains_unchanged(): void
    {
        $response = $this->actingAs($this->qcFittingUser)->get(route('plan.index'));
        $response->assertOk();

        $content = $response->getContent();

        // QC Fitting sees Sand Casting, Lost Wax, and Fitting Production
        $this->assertStringContainsString('id="sandCastingMenu"', $content);
        $this->assertStringContainsString('id="lostWaxMenu"', $content);
        $this->assertStringContainsString('id="fittingMenu"', $content);
        $this->assertStringContainsString(route('fitting.qc-defects.index'), $content);
    }
}
