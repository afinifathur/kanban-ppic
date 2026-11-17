<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditorDisplayAuthTest extends TestCase
{
    use RefreshDatabase;

    private User $auditorUser;

    private User $scannerUser;

    private User $normalUser;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Permissions
        $permAuditor = Permission::firstOrCreate(['name' => 'access_auditor_visibility', 'guard_name' => 'web']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution', 'guard_name' => 'web']);
        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);

        // 2. Setup Roles
        $roleAuditor = Role::firstOrCreate(['name' => 'auditor', 'guard_name' => 'web']);
        $roleAuditor->syncPermissions([$permAuditor]);

        $roleSpv = Role::firstOrCreate(['name' => 'spv', 'guard_name' => 'web']);
        $roleSpv->syncPermissions([$permExecution]);

        $rolePpic = Role::firstOrCreate(['name' => 'ppic', 'guard_name' => 'web']);
        $rolePpic->syncPermissions([$permPlanning, $permExecution]);

        // 3. Create Users
        $this->auditorUser = User::create([
            'name' => 'Auditor Peroniks',
            'email' => 'auditor@peroniks.com',
            'password' => Hash::make('password123'),
            'remember_token' => null,
        ]);
        $this->auditorUser->assignRole('auditor');

        $this->scannerUser = User::create([
            'name' => 'SPV Lapisan',
            'email' => 'spvlapisan@peroniks.com',
            'password' => Hash::make('password123'),
            'remember_token' => null,
        ]);
        $this->scannerUser->assignRole('spv');

        $this->normalUser = User::create([
            'name' => 'PPIC Flange',
            'email' => 'ppicflange@peroniks.com',
            'password' => Hash::make('password123'),
            'remember_token' => null,
        ]);
        $this->normalUser->assignRole('ppic');
    }

    /**
     * 1. Login auditor@peroniks.com tanpa checkbox Remember-Me otomatis menghasilkan remember token.
     */
    public function test_01_auditor_login_without_remember_checkbox_gets_automatic_remember_token(): void
    {
        $this->assertNull($this->auditorUser->fresh()->remember_token);

        $response = $this->post('/login', [
            'email' => 'auditor@peroniks.com',
            'password' => 'password123',
            // No remember parameter passed
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->auditorUser);

        // Verify remember_token was populated on the user model in database
        $freshAuditor = $this->auditorUser->fresh();
        $this->assertNotNull($freshAuditor->remember_token);
        $this->assertNotEmpty($freshAuditor->remember_token);

        // Verify remember cookie is queued in response
        $response->assertCookie(Auth::guard()->getRecallerName());
    }

    /**
     * 2. Login spvlapisan@peroniks.com tetap berfungsi otomatis memperoleh remember token seperti sebelumnya.
     */
    public function test_02_spvlapisan_login_without_remember_checkbox_still_gets_automatic_remember_token(): void
    {
        $this->assertNull($this->scannerUser->fresh()->remember_token);

        $response = $this->post('/login', [
            'email' => 'spvlapisan@peroniks.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->scannerUser);

        $freshScanner = $this->scannerUser->fresh();
        $this->assertNotNull($freshScanner->remember_token);
        $this->assertNotEmpty($freshScanner->remember_token);
        $response->assertCookie(Auth::guard()->getRecallerName());
    }

    /**
     * 3. Pengguna biasa (normal user) tidak otomatis memperoleh Remember-Me kecuali jika mencentang checkbox.
     */
    public function test_03_normal_user_login_without_remember_does_not_get_automatic_remember_token(): void
    {
        $this->assertNull($this->normalUser->fresh()->remember_token);

        // A. Login without remember checkbox
        $response = $this->post('/login', [
            'email' => 'ppicflange@peroniks.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->normalUser);

        // Normal user must NOT have remember_token populated
        $this->assertNull($this->normalUser->fresh()->remember_token);
        $response->assertCookieMissing(Auth::guard()->getRecallerName());

        // B. Login with explicit remember checkbox
        Auth::logout();
        $responseWithRemember = $this->post('/login', [
            'email' => 'ppicflange@peroniks.com',
            'password' => 'password123',
            'remember' => '1',
        ]);

        $this->assertNotNull($this->normalUser->fresh()->remember_token);
        $responseWithRemember->assertCookie(Auth::guard()->getRecallerName());
    }

    /**
     * 4. Login auditor menangani whitespace dan huruf besar-kecil secara toleran.
     */
    public function test_04_auditor_login_handles_whitespace_and_mixed_case(): void
    {
        $response = $this->post('/login', [
            'email' => '   AUDITOR@Peroniks.Com   ',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->auditorUser);
        $this->assertNotNull($this->auditorUser->fresh()->remember_token);
    }

    /**
     * 5. Session yang kedaluwarsa dapat dipulihkan secara transparan melalui remember cookie.
     */
    public function test_05_expired_session_can_be_recovered_via_remember_cookie(): void
    {
        // Login to issue remember cookie and token
        $loginResponse = $this->post('/login', [
            'email' => 'auditor@peroniks.com',
            'password' => 'password123',
        ]);

        $recallerCookie = $loginResponse->getCookie(Auth::guard()->getRecallerName());
        $this->assertNotNull($recallerCookie);

        // Simulate session expiry: flush the active session
        session()->flush();
        Auth::forgetUser();
        $this->assertFalse(Auth::check());

        // Send request to dashboard with recaller cookie
        $response = $this->withCookie($recallerCookie->getName(), $recallerCookie->getValue())
            ->get('/sand-casting/dashboard');

        // Request must succeed (200 OK) instead of 302 redirecting to /login
        $response->assertOk();
        $this->assertTrue(Auth::check());
        $this->assertEquals($this->auditorUser->id, Auth::id());
    }

    /**
     * 6. Logout tetap mengakhiri autentikasi dan merotasi token sehingga tidak memulihkan session.
     */
    public function test_06_auditor_logout_clears_remember_token_and_invalidates_session(): void
    {
        $this->post('/login', [
            'email' => 'auditor@peroniks.com',
            'password' => 'password123',
        ]);
        $this->assertAuthenticated();

        $tokenBeforeLogout = $this->auditorUser->fresh()->remember_token;
        $this->assertNotEmpty($tokenBeforeLogout);

        $logoutResponse = $this->post('/logout');
        $logoutResponse->assertRedirect('/login');

        $this->assertGuest();

        // Token must be rotated on logout
        $this->assertNotEquals($tokenBeforeLogout, $this->auditorUser->fresh()->remember_token);

        // Accessing dashboard without session must redirect to /login
        $this->get('/sand-casting/dashboard')->assertRedirect('/login');
    }

    /**
     * 7. Auditor tetap dapat membuka dashboard dan endpoint data dashboard, serta view memuat guard recovery.
     */
    public function test_07_auditor_can_access_dashboard_and_view_contains_recovery_guard(): void
    {
        $response = $this->actingAs($this->auditorUser)->get('/sand-casting/dashboard');
        $response->assertOk();
        $response->assertViewIs('sand-casting.dashboard.index');

        // View contains authentication failure recovery logic
        $html = $response->getContent();
        $this->assertStringContainsString('handleAuthRecovery', $html);
        $this->assertStringContainsString('sand_casting_dashboard_auth_reload', $html);
        $this->assertStringContainsString('response.status === 401 || response.status === 419', $html);

        // Data polling endpoint returns 200 OK
        $dataResponse = $this->actingAs($this->auditorUser)->getJson('/sand-casting/dashboard/data');
        $dataResponse->assertOk();
        $dataResponse->assertJsonIsObject();
    }

    /**
     * 8. Auditor tetap tidak dapat melakukan mutasi data (backend strictly rejects mutations).
     */
    public function test_08_auditor_strictly_denied_from_mutations(): void
    {
        $this->actingAs($this->auditorUser);

        // Kanban queue reorder
        $this->post('/sand-casting/kanban/stage/netto/reorder', [
            'line_number' => 1,
            'from_position' => 2,
            'to_position' => 1,
        ])->assertForbidden();

        // Casting orders
        $this->post('/sand-casting/casting-orders', [])->assertForbidden();

        // Casting results
        $this->post('/sand-casting/casting-results', [])->assertForbidden();

        // Scanners
        $this->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => 'KTR-999',
        ])->assertForbidden();

        // Settings
        $this->post('/settings/defect-types', ['name' => 'Illegal Mutation'])->assertForbidden();
    }
}
