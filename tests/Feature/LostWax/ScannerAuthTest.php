<?php

namespace Tests\Feature\LostWax;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScannerAuthTest extends TestCase
{
    use RefreshDatabase;

    private User $scannerUser;

    private User $normalUser;

    protected function setUp(): void
    {
        parent::setUp();

        $accessPlanning = \Spatie\Permission\Models\Permission::findOrCreate('access_planning');
        $accessExecution = \Spatie\Permission\Models\Permission::findOrCreate('access_execution');

        $spvRole = \Spatie\Permission\Models\Role::findOrCreate('spv');
        $ppicRole = \Spatie\Permission\Models\Role::findOrCreate('ppic');

        $spvRole->givePermissionTo([$accessExecution]);
        $ppicRole->givePermissionTo([$accessPlanning, $accessExecution]);

        $this->scannerUser = User::factory()->create([
            'name' => 'SPV Lapisan',
            'email' => 'spvlapisan@peroniks.com',
            'password' => bcrypt('password123'),
            'remember_token' => null,
        ]);
        $this->scannerUser->assignRole('spv');

        $this->normalUser = User::factory()->create([
            'name' => 'PPIC Flange',
            'email' => 'ppicflange@peroniks.com',
            'password' => bcrypt('password123'),
            'remember_token' => null,
        ]);
        $this->normalUser->assignRole('ppic');
    }

    public function test_scanner_login_without_remember_checkbox_gets_automatic_remember_token(): void
    {
        $this->assertNull($this->scannerUser->fresh()->remember_token);

        $response = $this->post('/login', [
            'email' => 'spvlapisan@peroniks.com',
            'password' => 'password123',
            // No 'remember' field passed
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->scannerUser);

        // Verify remember_token was populated on the user model
        $freshScanner = $this->scannerUser->fresh();
        $this->assertNotNull($freshScanner->remember_token);
        $this->assertNotEmpty($freshScanner->remember_token);
    }

    public function test_scanner_login_handles_whitespace_and_mixed_case(): void
    {
        $response = $this->post('/login', [
            'email' => '  SPVlapisan@peroniks.COM  ',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->scannerUser);
        $this->assertNotNull($this->scannerUser->fresh()->remember_token);
    }

    public function test_normal_user_login_without_remember_does_not_get_remember_token(): void
    {
        $this->assertNull($this->normalUser->fresh()->remember_token);

        $response = $this->post('/login', [
            'email' => 'ppicflange@peroniks.com',
            'password' => 'password123',
            // No remember
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->normalUser);

        // Normal user should NOT have remember_token populated
        $this->assertNull($this->normalUser->fresh()->remember_token);
    }

    public function test_normal_user_login_with_remember_checkbox_gets_remember_token(): void
    {
        $response = $this->post('/login', [
            'email' => 'ppicflange@peroniks.com',
            'password' => 'password123',
            'remember' => 'on',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->normalUser);
        $this->assertNotNull($this->normalUser->fresh()->remember_token);
    }

    public function test_scanner_logout_clears_remember_token_and_invalidates_session(): void
    {
        // Login scanner
        $this->post('/login', [
            'email' => 'spvlapisan@peroniks.com',
            'password' => 'password123',
        ]);
        $this->assertAuthenticated();

        $tokenBeforeLogout = $this->scannerUser->fresh()->remember_token;
        $this->assertNotEmpty($tokenBeforeLogout);

        // Logout
        $logoutResponse = $this->post('/logout');
        $logoutResponse->assertRedirect('/login');

        $this->assertGuest();

        // Laravel cycles the remember token upon logout to invalidate the old recaller cookie
        $this->assertNotEquals($tokenBeforeLogout, $this->scannerUser->fresh()->remember_token);
    }

    public function test_scanner_routes_require_authentication(): void
    {
        $responseScan = $this->get('/lost-wax/scan');
        $responseScan->assertRedirect('/login');

        $responseOven = $this->get('/lost-wax/scan-oven');
        $responseOven->assertRedirect('/login');

        $responseKeepalive = $this->get('/lost-wax/scan/keepalive');
        $responseKeepalive->assertRedirect('/login');
    }

    public function test_scanner_keepalive_endpoint_returns_json_for_authenticated_scanner(): void
    {
        $response = $this->actingAs($this->scannerUser)
            ->getJson('/lost-wax/scan/keepalive');

        $response->assertOk();
        $response->assertJson([
            'ok' => true,
        ]);
    }

    public function test_scanner_user_retains_existing_permissions_and_restrictions(): void
    {
        // Has role spv
        $this->assertTrue($this->scannerUser->hasRole('spv'));
        $this->assertFalse($this->scannerUser->hasRole('admin'));
        $this->assertFalse($this->scannerUser->hasRole('ppic'));

        // Role permissions
        $spvRole = \Spatie\Permission\Models\Role::findByName('spv');
        $this->assertTrue($spvRole->hasPermissionTo('access_execution'));
        $this->assertFalse($spvRole->hasPermissionTo('access_planning'));
    }

    public function test_scanner_views_contain_keepalive_and_recovery_guards(): void
    {
        $responseScan = $this->actingAs($this->scannerUser)->get('/lost-wax/scan');
        $responseScan->assertOk();
        $responseScan->assertSee('handleSessionRecovery');
        $responseScan->assertSee('KEEPALIVE_INTERVAL');
        $responseScan->assertSee('/lost-wax/scan/keepalive');

        $responseOven = $this->actingAs($this->scannerUser)->get('/lost-wax/scan-oven');
        $responseOven->assertOk();
        $responseOven->assertSee('handleSessionRecovery');
        $responseOven->assertSee('KEEPALIVE_INTERVAL');
        $responseOven->assertSee('/lost-wax/scan/keepalive');
    }
}
