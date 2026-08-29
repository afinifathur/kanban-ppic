<?php

namespace Tests\Feature\LostWax;

use App\Models\Customer;
use App\Models\ProductionItem;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\QcFittingUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. QcFittingUserSeeder creates the QC account.
     */
    public function test_1_qc_fitting_user_seeder_creates_qc_account(): void
    {
        $this->seed(QcFittingUserSeeder::class);

        $user = User::where('email', 'adminqcfitting@peroniks.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('Admin QC Fitting', $user->name);
    }

    /**
     * 2. QcFittingUserSeeder can be run repeatedly without duplicate users or errors.
     */
    public function test_2_qc_fitting_user_seeder_is_idempotent(): void
    {
        $this->seed(QcFittingUserSeeder::class);
        $this->seed(QcFittingUserSeeder::class);
        $this->seed(QcFittingUserSeeder::class);

        $this->assertEquals(1, User::where('email', 'adminqcfitting@peroniks.com')->count());
    }

    /**
     * 3. Password "password" is valid and hashed.
     */
    public function test_3_qc_account_password_is_valid_and_hashed(): void
    {
        $this->seed(QcFittingUserSeeder::class);

        $user = User::where('email', 'adminqcfitting@peroniks.com')->firstOrFail();
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertNotEquals('password', $user->password);
    }

    /**
     * 4. Role admin_qc_fitting is correctly assigned.
     */
    public function test_4_role_admin_qc_fitting_is_assigned(): void
    {
        $this->seed(QcFittingUserSeeder::class);

        $user = User::where('email', 'adminqcfitting@peroniks.com')->firstOrFail();
        $this->assertTrue($user->hasRole('admin_qc_fitting'));
        $this->assertTrue($user->can('access_planning'));
        $this->assertTrue($user->can('access_execution'));
    }

    /**
     * 5. Scope is null (cross-scope read-only).
     */
    public function test_5_product_scope_is_null(): void
    {
        $this->seed(QcFittingUserSeeder::class);

        $user = User::where('email', 'adminqcfitting@peroniks.com')->firstOrFail();
        $this->assertNull($user->product_scope);
    }

    /**
     * 6. DatabaseSeeder does not run ProductionDummySeeder (0 ProductionItem created).
     */
    public function test_6_database_seeder_does_not_create_dummy_production_items(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertEquals(0, ProductionItem::count());
    }

    /**
     * 7. DatabaseSeeder does not run CustomerSeeder (0 Customer created).
     */
    public function test_7_database_seeder_does_not_create_dummy_customers(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertEquals(0, Customer::count());
    }

    /**
     * 8. DatabaseSeeder does not run TemporaryAdminAccessSeeder (no Direktur created).
     */
    public function test_8_database_seeder_does_not_create_temporary_admin_users(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertNull(User::where('email', 'direktur@peroniks.com')->first());
    }

    /**
     * 9. DatabaseSeeder only runs the approved QC seeder and creates only 1 user.
     */
    public function test_9_database_seeder_only_creates_qc_user(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertEquals(1, User::count());
        $this->assertNotNull(User::where('email', 'adminqcfitting@peroniks.com')->first());
    }
}
