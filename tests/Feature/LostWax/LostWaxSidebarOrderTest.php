<?php

namespace Tests\Feature\LostWax;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LostWaxSidebarOrderTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $ppicUser;

    protected User $spvUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions
        $pPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $pExecution = Permission::firstOrCreate(['name' => 'access_execution']);

        // Roles
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $ppicRole = Role::firstOrCreate(['name' => 'ppic']);
        $spvRole = Role::firstOrCreate(['name' => 'spv']);
        $qcFittingRole = Role::firstOrCreate(['name' => 'admin_qc_fitting']);

        $adminRole->syncPermissions([$pPlanning, $pExecution]);
        $ppicRole->syncPermissions([$pPlanning]);
        $spvRole->syncPermissions([$pExecution]);
        $qcFittingRole->syncPermissions([$pPlanning, $pExecution]);

        $this->adminUser = User::factory()->create(['email' => 'admin@peroniks.com']);
        $this->adminUser->assignRole('admin');

        $this->ppicUser = User::factory()->create(['email' => 'ppic@peroniks.com']);
        $this->ppicUser->assignRole('ppic');

        $this->spvUser = User::factory()->create(['email' => 'spv@peroniks.com']);
        $this->spvUser->assignRole('spv');
    }

    public function test_lost_wax_sidebar_renders_exact_15_menus_in_correct_order_for_admin(): void
    {
        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status'));
        $response->assertOk();

        $content = $response->getContent();

        // Extract lostWaxMenu content
        $this->assertStringContainsString('id="lostWaxMenu"', $content);
        preg_match('/<ul id="lostWaxMenu"[^>]*>(.*?)<\/ul>/s', $content, $matches);
        $this->assertNotEmpty($matches, 'Could not find #lostWaxMenu in response HTML');
        $menuHtml = $matches[1];

        // Extract menu link spans
        preg_match_all('/<span class="text-sm sidebar-text">([^<]+)<\/span>/', $menuHtml, $itemMatches);
        $menuTitles = array_map('trim', $itemMatches[1]);

        $expectedOrder = [
            'Perintah Cetak',
            'Hasil Cetak',
            'Rangkai',
            'Hasil Rangkai',
            'Rangkaian / Traveler',
            'Scan Lapisan',
            'Scan Masuk Oven Dewaxing',
            'Antrean Siap Oven Dewaxing',
            'Scan Selesai Dewaxing',
            'Persediaan Pasca Dewaxing',
            'Input Hasil Cor',
            'Rack Monitoring',
            'Production Status',
            'Rekap Kerusakan',
            'Report Produksi',
        ];

        $this->assertCount(15, $menuTitles, 'Sidebar must contain exactly 15 menu items');
        $this->assertSame($expectedOrder, $menuTitles, 'Sidebar menu order does not match expected production workflow order');
    }

    public function test_sidebar_menu_routes_and_urls_match_exactly(): void
    {
        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.production-status'));
        $response->assertOk();

        $expectedRoutes = [
            route('lost-wax.print-orders.plans'),
            route('lost-wax.outcomes.index'),
            route('lost-wax.assemblies.index'),
            route('lost-wax.assemblies.work-orders.index'),
            route('lost-wax.trees.index'),
            route('lost-wax.scan.index'),
            route('lost-wax.scan-oven.index'),
            route('lost-wax.reports.ready-for-oven.index'),
            route('lost-wax.scan-siap-cor.index'),
            route('lost-wax.reports.ready-for-casting.index'),
            route('lost-wax.casting-results.index'),
            route('lost-wax.rack-monitor.index'),
            route('lost-wax.production-status'),
            route('lost-wax.quality.defects.index'),
            route('lost-wax.report.production.index'),
        ];

        foreach ($expectedRoutes as $routeUrl) {
            $response->assertSee($routeUrl, false);
        }
    }

    public function test_sidebar_role_visibility_for_ppic(): void
    {
        $response = $this->actingAs($this->ppicUser)->get(route('lost-wax.production-status'));
        $response->assertOk();

        $content = $response->getContent();
        preg_match('/<ul id="lostWaxMenu"[^>]*>(.*?)<\/ul>/s', $content, $matches);
        $menuHtml = $matches[1];

        preg_match_all('/<span class="text-sm sidebar-text">([^<]+)<\/span>/', $menuHtml, $itemMatches);
        $menuTitles = array_map('trim', $itemMatches[1]);

        // PPIC should see: Perintah Cetak, Antrean Siap Oven Dewaxing, Persediaan Pasca Dewaxing, Input Hasil Cor, Rack Monitoring, Production Status, Rekap Kerusakan, Report Produksi
        $this->assertContains('Perintah Cetak', $menuTitles);
        $this->assertContains('Antrean Siap Oven Dewaxing', $menuTitles);
        $this->assertContains('Persediaan Pasca Dewaxing', $menuTitles);
        $this->assertContains('Input Hasil Cor', $menuTitles);
        $this->assertContains('Rack Monitoring', $menuTitles);
        $this->assertContains('Production Status', $menuTitles);

        // PPIC should NOT see execution scanner menus:
        $this->assertNotContains('Scan Lapisan', $menuTitles);
        $this->assertNotContains('Scan Masuk Oven Dewaxing', $menuTitles);
        $this->assertNotContains('Scan Selesai Dewaxing', $menuTitles);
    }

    public function test_sidebar_role_visibility_for_spv(): void
    {
        $response = $this->actingAs($this->spvUser)->get(route('lost-wax.production-status'));
        $response->assertOk();

        $content = $response->getContent();
        preg_match('/<ul id="lostWaxMenu"[^>]*>(.*?)<\/ul>/s', $content, $matches);
        $menuHtml = $matches[1];

        preg_match_all('/<span class="text-sm sidebar-text">([^<]+)<\/span>/', $menuHtml, $itemMatches);
        $menuTitles = array_map('trim', $itemMatches[1]);

        // SPV should NOT see Perintah Cetak
        $this->assertNotContains('Perintah Cetak', $menuTitles);

        // SPV should see execution items and reports
        $this->assertContains('Hasil Cetak', $menuTitles);
        $this->assertContains('Rangkai', $menuTitles);
        $this->assertContains('Hasil Rangkai', $menuTitles);
        $this->assertContains('Rangkaian / Traveler', $menuTitles);
        $this->assertContains('Scan Lapisan', $menuTitles);
        $this->assertContains('Scan Masuk Oven Dewaxing', $menuTitles);
        $this->assertContains('Antrean Siap Oven Dewaxing', $menuTitles);
        $this->assertContains('Scan Selesai Dewaxing', $menuTitles);
        $this->assertContains('Persediaan Pasca Dewaxing', $menuTitles);
        $this->assertContains('Input Hasil Cor', $menuTitles);
        $this->assertContains('Rack Monitoring', $menuTitles);
        $this->assertContains('Production Status', $menuTitles);
    }
}

