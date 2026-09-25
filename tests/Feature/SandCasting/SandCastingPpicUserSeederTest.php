<?php

namespace Tests\Feature\SandCasting;

use App\Models\User;
use Database\Seeders\SandCastingPpicUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SandCastingPpicUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_admin_ppic_flange_user_idempotently(): void
    {
        $this->assertDatabaseMissing('users', [
            'email' => 'adminppicfl@peroniks.com',
        ]);

        $this->seed(SandCastingPpicUserSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => 'adminppicfl@peroniks.com',
            'name' => 'Admin PPIC Flange',
            'product_scope' => 'FLANGE_BESI',
            'assigned_stage' => null,
        ]);

        $user = User::where('email', 'adminppicfl@peroniks.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('ppic'));
        $this->assertTrue($user->hasPermissionTo('access_execution'));
        $this->assertNull($user->assigned_stage);
        $this->assertEquals('FLANGE_BESI', $user->product_scope);

        // Run seeder second time to ensure idempotency
        $this->seed(SandCastingPpicUserSeeder::class);

        $this->assertEquals(1, User::where('email', 'adminppicfl@peroniks.com')->count());
    }

    public function test_seeder_does_not_overwrite_custom_password_on_rerun(): void
    {
        // Pre-create user with custom password
        $user = User::create([
            'name' => 'Admin PPIC Flange Existing',
            'email' => 'adminppicfl@peroniks.com',
            'password' => Hash::make('custom-secret-password-123'),
            'product_scope' => 'FLANGE_BESI',
            'assigned_stage' => null,
        ]);

        $this->seed(SandCastingPpicUserSeeder::class);

        $user->refresh();
        $this->assertTrue(Hash::check('custom-secret-password-123', $user->password));
        $this->assertTrue($user->hasRole('ppic'));
        $this->assertTrue($user->hasPermissionTo('access_execution'));
        $this->assertEquals('Admin PPIC Flange', $user->name);
    }
}
