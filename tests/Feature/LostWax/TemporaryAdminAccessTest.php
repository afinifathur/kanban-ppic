<?php

namespace Tests\Feature\LostWax;

use App\Models\User;
use Database\Seeders\TemporaryAdminAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TemporaryAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Base setup: roles & permissions
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $accessPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution']);
        $adminRole->syncPermissions([$accessPlanning, $accessExecution]);

        // Create reference admin
        $adminPpic = User::create([
            'name' => 'Admin PPIC',
            'email' => 'adminppicpf@peroniks.com',
            'password' => Hash::make('reference_admin_password'),
            'product_scope' => null,
        ]);
        $adminPpic->assignRole('admin');

        // Create existing MR user without role
        User::create([
            'name' => 'MR Peroniks',
            'email' => 'mr@peroniks.com',
            'password' => Hash::make('mr_existing_secret_password'),
            'product_scope' => null,
        ]);
    }

    protected function tearDown(): void
    {
        putenv('DIREKTUR_INITIAL_PASSWORD');
        putenv('MR_INITIAL_PASSWORD');
        parent::tearDown();
    }

    public function test_seeder_fails_loud_if_direktur_password_missing_and_user_not_exists(): void
    {
        putenv('DIREKTUR_INITIAL_PASSWORD=');
        unset($_ENV['DIREKTUR_INITIAL_PASSWORD']);
        unset($_SERVER['DIREKTUR_INITIAL_PASSWORD']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DIREKTUR_INITIAL_PASSWORD is required');

        $this->seed(TemporaryAdminAccessSeeder::class);

        // Ensure Direktur was NOT created
        $this->assertNull(User::where('email', 'direktur@peroniks.com')->first());
    }

    public function test_creates_direktur_with_hashed_password_when_initial_password_provided(): void
    {
        putenv('DIREKTUR_INITIAL_PASSWORD=SecretDirektur123!');
        $_ENV['DIREKTUR_INITIAL_PASSWORD'] = 'SecretDirektur123!';

        $this->seed(TemporaryAdminAccessSeeder::class);

        $direktur = User::where('email', 'direktur@peroniks.com')->first();
        $this->assertNotNull($direktur);
        $this->assertEquals('Direktur Utama', $direktur->name);
        $this->assertTrue($direktur->hasRole('admin'));
        $this->assertTrue($direktur->can('access_planning'));
        $this->assertTrue($direktur->can('access_execution'));
        $this->assertNull($direktur->product_scope);

        $this->assertTrue(Hash::check('SecretDirektur123!', $direktur->password));
    }

    public function test_mr_receives_admin_role_while_preserving_existing_password(): void
    {
        putenv('DIREKTUR_INITIAL_PASSWORD=SecretDirektur123!');
        $_ENV['DIREKTUR_INITIAL_PASSWORD'] = 'SecretDirektur123!';

        $mrBefore = User::where('email', 'mr@peroniks.com')->first();
        $originalHash = $mrBefore->password;

        $this->seed(TemporaryAdminAccessSeeder::class);

        $mrAfter = User::where('email', 'mr@peroniks.com')->first();
        $this->assertNotNull($mrAfter);
        $this->assertTrue($mrAfter->hasRole('admin'));
        $this->assertTrue($mrAfter->can('access_planning'));
        $this->assertTrue($mrAfter->can('access_execution'));
        $this->assertNull($mrAfter->product_scope);
        $this->assertEquals($originalHash, $mrAfter->password, 'MR password must remain untouched.');
        $this->assertTrue(Hash::check('mr_existing_secret_password', $mrAfter->password));
    }

    public function test_subsequent_runs_do_not_require_password_and_preserve_all_existing_passwords(): void
    {
        // 1. First run creates Direktur
        putenv('DIREKTUR_INITIAL_PASSWORD=SecretDirektur123!');
        $_ENV['DIREKTUR_INITIAL_PASSWORD'] = 'SecretDirektur123!';
        $this->seed(TemporaryAdminAccessSeeder::class);

        $direkturInitial = User::where('email', 'direktur@peroniks.com')->first();
        $mrInitial = User::where('email', 'mr@peroniks.com')->first();
        $adminPpicInitial = User::where('email', 'adminppicpf@peroniks.com')->first();

        $direkturHash = $direkturInitial->password;
        $mrHash = $mrInitial->password;
        $adminPpicHash = $adminPpicInitial->password;

        // 2. Second run with empty/unset DIREKTUR_INITIAL_PASSWORD
        putenv('DIREKTUR_INITIAL_PASSWORD=');
        unset($_ENV['DIREKTUR_INITIAL_PASSWORD']);
        unset($_SERVER['DIREKTUR_INITIAL_PASSWORD']);

        // Should NOT throw exception because Direktur already exists
        $this->seed(TemporaryAdminAccessSeeder::class);

        $direkturAfter = User::where('email', 'direktur@peroniks.com')->first();
        $mrAfter = User::where('email', 'mr@peroniks.com')->first();
        $adminPpicAfter = User::where('email', 'adminppicpf@peroniks.com')->first();

        $this->assertEquals(1, User::where('email', 'direktur@peroniks.com')->count());
        $this->assertEquals(1, User::where('email', 'mr@peroniks.com')->count());
        $this->assertEquals(1, User::where('email', 'adminppicpf@peroniks.com')->count());

        $this->assertEquals($direkturHash, $direkturAfter->password, 'Direktur password must not be overwritten.');
        $this->assertEquals($mrHash, $mrAfter->password, 'MR password must not be overwritten.');
        $this->assertEquals($adminPpicHash, $adminPpicAfter->password, 'Admin PPIC password must not be overwritten.');
    }

    public function test_mr_and_direktur_can_access_admin_planning_routes(): void
    {
        putenv('DIREKTUR_INITIAL_PASSWORD=SecretDirektur123!');
        $_ENV['DIREKTUR_INITIAL_PASSWORD'] = 'SecretDirektur123!';
        $this->seed(TemporaryAdminAccessSeeder::class);

        $mr = User::where('email', 'mr@peroniks.com')->first();
        $direktur = User::where('email', 'direktur@peroniks.com')->first();

        // MR can access plan and settings
        $responseMr = $this->actingAs($mr)->get('/lost-wax/print-orders/plans');
        $responseMr->assertOk();

        // Direktur can access plan and settings
        $responseDir = $this->actingAs($direktur)->get('/lost-wax/print-orders/plans');
        $responseDir->assertOk();

        // Both can access master foto rangkai
        $this->actingAs($mr)->get('/settings/assembly-photos')->assertOk();
        $this->actingAs($direktur)->get('/settings/assembly-photos')->assertOk();
    }
}
