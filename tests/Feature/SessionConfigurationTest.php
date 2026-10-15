<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SessionConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_lifetime_is_configured_to_480_minutes(): void
    {
        // When not overridden, config('session.lifetime') is 480 (8 hours)
        $this->assertEquals(480, config('session.lifetime'));
    }

    public function test_session_expire_on_close_is_false(): void
    {
        $this->assertFalse((bool) config('session.expire_on_close'));
    }

    public function test_user_can_login_and_logout_with_session_invalidation(): void
    {
        $user = User::factory()->create([
            'email' => 'operasional@peroniks.com',
            'password' => Hash::make('password123'),
        ]);

        // Attempt login with valid credentials
        $response = $this->post('/login', [
            'email' => 'operasional@peroniks.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        // Attempt logout
        $logoutResponse = $this->post('/logout');
        $logoutResponse->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_with_invalid_credentials_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'operasional@peroniks.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => 'operasional@peroniks.com',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
