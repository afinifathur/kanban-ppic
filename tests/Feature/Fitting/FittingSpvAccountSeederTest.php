<?php

namespace Tests\Feature\Fitting;

use App\Models\FittingItem;
use App\Models\User;
use App\Services\Fitting\FittingStageAuthorizationService;
use Database\Seeders\FittingSpvAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FittingSpvAccountSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_01_seeder_creates_all_six_spv_accounts_with_hashed_password(): void
    {
        $this->assertEquals(0, User::count());

        $this->seed(FittingSpvAccountSeeder::class);

        $this->assertEquals(6, User::count());

        $expectedStages = [
            'spvnettopf@peroniks.com' => FittingItem::STAGE_NETTO_PF,
            'spvkimiapf@peroniks.com' => FittingItem::STAGE_KIMIA,
            'spvmarkingpf@peroniks.com' => FittingItem::STAGE_MARKING,
            'spvcncpf@peroniks.com' => FittingItem::STAGE_BUBUT_CNC_PF,
            'spvqcpf@peroniks.com' => FittingItem::STAGE_QC_PF,
            'spvgdpf@peroniks.com' => FittingItem::STAGE_GUDANG_JADI_PF,
        ];

        foreach ($expectedStages as $email => $stage) {
            $user = User::where('email', $email)->first();
            $this->assertNotNull($user, "Account {$email} must exist.");
            $this->assertEquals($stage, $user->assigned_stage);
            $this->assertEquals('FITTING_STAINLESS', $user->product_scope);

            // Verify password is NOT plaintext and validates with 'password'
            $this->assertNotEquals('password', $user->password);
            $this->assertTrue(Hash::check('password', $user->password), "Password for {$email} must match 'password'.");

            // Verify role
            $this->assertTrue($user->hasRole(FittingStageAuthorizationService::ROLE_SPV_FITTING));
            $this->assertTrue($user->can(FittingStageAuthorizationService::PERMISSION_EXECUTION));

            // Verify kanban permission scoping
            if ($email === FittingStageAuthorizationService::EMAIL_SPV_GD_PF) {
                $this->assertTrue($user->hasDirectPermission(FittingStageAuthorizationService::PERMISSION_ALL_KANBAN));
            } else {
                $this->assertFalse($user->hasDirectPermission(FittingStageAuthorizationService::PERMISSION_ALL_KANBAN));
            }
        }
    }

    public function test_02_seeder_is_idempotent_and_updates_drifted_password_without_modifying_other_users(): void
    {
        // Pre-create an unrelated user (e.g. PPIC)
        $unrelatedUser = User::create([
            'name' => 'Admin PPIC PF',
            'email' => 'adminppicpf@peroniks.com',
            'password' => Hash::make('original_admin_pass'),
            'product_scope' => null,
        ]);
        $roleAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $unrelatedUser->assignRole($roleAdmin);

        // Pre-create one SPV account with an incorrect/drifted password
        $spvNetto = User::create([
            'name' => 'SPV Netto PF Old',
            'email' => 'spvnettopf@peroniks.com',
            'password' => Hash::make('wrong_forgotten_pass'),
            'assigned_stage' => 'invalid_stage',
            'product_scope' => 'OLD_SCOPE',
        ]);

        $this->seed(FittingSpvAccountSeeder::class);

        // Verify SPV account was updated correctly
        $spvNetto->refresh();
        $this->assertEquals('SPV Netto PF', $spvNetto->name);
        $this->assertEquals(FittingItem::STAGE_NETTO_PF, $spvNetto->assigned_stage);
        $this->assertEquals('FITTING_STAINLESS', $spvNetto->product_scope);
        $this->assertTrue(Hash::check('password', $spvNetto->password), 'Password should be updated to "password".');
        $this->assertTrue($spvNetto->hasRole(FittingStageAuthorizationService::ROLE_SPV_FITTING));

        // Verify unrelated user was completely untouched
        $unrelatedUser->refresh();
        $this->assertTrue(Hash::check('original_admin_pass', $unrelatedUser->password), 'Unrelated user password must not be touched.');
        $this->assertFalse(Hash::check('password', $unrelatedUser->password));
        $this->assertTrue($unrelatedUser->hasRole('admin'));
        $this->assertFalse($unrelatedUser->hasRole(FittingStageAuthorizationService::ROLE_SPV_FITTING));

        // Run seeder again to ensure true idempotency (zero exceptions, same state)
        $this->seed(FittingSpvAccountSeeder::class);
        $this->assertEquals(7, User::count()); // 1 unrelated + 6 SPV
    }

    public function test_03_login_authentication_succeeds_with_password(): void
    {
        $this->seed(FittingSpvAccountSeeder::class);

        // Test web auth attempt through AuthController endpoint
        $response = $this->post('/login', [
            'email' => 'spvnettopf@peroniks.com',
            'password' => 'password',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();

        // Also test invalid password is rejected
        $this->post('/logout');
        $failResponse = $this->post('/login', [
            'email' => 'spvnettopf@peroniks.com',
            'password' => 'wrong_password',
        ]);
        $failResponse->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
