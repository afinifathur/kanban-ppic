<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Set up roles and permissions
        $adminRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
        $ppicRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ppic']);
        $spvRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'spv']);

        $accessPlanning = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'access_planning']);
        $accessExecution = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'access_execution']);

        $adminRole->syncPermissions([$accessPlanning, $accessExecution]);
        $ppicRole->syncPermissions([$accessPlanning, $accessExecution]);
        $spvRole->syncPermissions([$accessExecution]);

        $this->seedUser('Admin PPIC', 'adminppicpf@peroniks.com', 'password', 'admin');
        $this->seedUser('MR Peroniks', 'mr@peroniks.com', 'password123');

        // Seed new RBAC / product scope users
        $this->seedUser('PPIC Flange', 'ppicflange@peroniks.com', 'password', 'ppic', 'FLANGE_STAINLESS');
        $this->seedUser('PPIC Flange Besi', 'ppicflangebesi@peroniks.com', 'password', 'ppic', 'FLANGE_BESI');
        $this->seedUser('PPIC Fitting', 'ppicfitting@peroniks.com', 'password', 'ppic', 'FITTING_STAINLESS');
        $this->seedUser('Admin Fitting', 'adminfitting@peroniks.com', 'password', 'admin');
        $this->seedUser('SPV Lapisan', 'spvlapisan@peroniks.com', 'password', 'spv');

        $this->call([
            CustomerSeeder::class,
        ]);

        if (app()->environment('local', 'testing', 'dev')) {
            $this->call([
                ProductionDummySeeder::class,
            ]);
        }
    }

    /**
     * Seed or update a user idempotently.
     */
    private function seedUser(string $name, string $email, string $defaultPassword, ?string $role = null, ?string $productScope = null): void
    {
        $user = User::where('email', $email)->first();

        if ($user) {
            // User exists: update name and product scope, but preserve password
            $user->update([
                'name' => $name,
                'product_scope' => $productScope,
            ]);
        } else {
            // User does not exist: create user with default password
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => bcrypt($defaultPassword),
                'product_scope' => $productScope,
            ]);
        }

        // Sync Spatie role if provided
        if ($role) {
            $user->syncRoles([$role]);
        }
    }
}
