<?php

namespace Tests\Feature\SandCasting;

use App\Models\User;
use Database\Seeders\SandCastingQcUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SandCastingQcUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_admin_qc_flange_user_idempotently(): void
    {
        $this->assertDatabaseMissing('users', [
            'email' => 'adminqcflange@peroniks.com',
        ]);

        $this->seed(SandCastingQcUserSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => 'adminqcflange@peroniks.com',
            'name' => 'Admin QC Flange',
            'product_scope' => 'FLANGE_BESI',
            'assigned_stage' => null,
        ]);

        $user = User::where('email', 'adminqcflange@peroniks.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('qc'));
        $this->assertTrue($user->hasPermissionTo('access_execution'));
        $this->assertNull($user->assigned_stage);
        $this->assertEquals('FLANGE_BESI', $user->product_scope);

        // Run seeder second time to ensure idempotency
        $this->seed(SandCastingQcUserSeeder::class);

        $this->assertEquals(1, User::where('email', 'adminqcflange@peroniks.com')->count());
    }

    public function test_seeder_does_not_overwrite_custom_password_on_rerun(): void
    {
        // Pre-create user with custom password
        $user = User::create([
            'name' => 'Admin QC Flange Existing',
            'email' => 'adminqcflange@peroniks.com',
            'password' => Hash::make('custom-secret-password-123'),
            'product_scope' => 'FLANGE_BESI',
            'assigned_stage' => null,
        ]);

        $this->seed(SandCastingQcUserSeeder::class);

        $user->refresh();
        $this->assertTrue(Hash::check('custom-secret-password-123', $user->password));
        $this->assertTrue($user->hasRole('qc'));
        $this->assertTrue($user->hasPermissionTo('access_execution'));
        $this->assertEquals('Admin QC Flange', $user->name);
    }
}
