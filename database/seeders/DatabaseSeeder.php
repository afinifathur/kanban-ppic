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

        $adminUser = User::factory()->create([
            'name' => 'Admin PPIC',
            'email' => 'adminppicpf@peroniks.com',
            'password' => bcrypt('password'),
        ]);
        $adminUser->assignRole($adminRole);

        User::factory()->create([
            'name' => 'MR Peroniks',
            'email' => 'mr@peroniks.com',
            'password' => bcrypt('password123'),
        ]);

        // Seed new RBAC / product scope users
        $u1 = User::factory()->create([
            'name' => 'PPIC Flange',
            'email' => 'ppicflange@peroniks.com',
            'password' => bcrypt('password'),
            'product_scope' => 'FLANGE_STAINLESS',
        ]);
        $u1->assignRole($ppicRole);

        $u2 = User::factory()->create([
            'name' => 'PPIC Flange Besi',
            'email' => 'ppicflangebesi@peroniks.com',
            'password' => bcrypt('password'),
            'product_scope' => 'FLANGE_BESI',
        ]);
        $u2->assignRole($ppicRole);

        $u3 = User::factory()->create([
            'name' => 'PPIC Fitting',
            'email' => 'ppicfitting@peroniks.com',
            'password' => bcrypt('password'),
            'product_scope' => 'FITTING_STAINLESS',
        ]);
        $u3->assignRole($ppicRole);

        $u4 = User::factory()->create([
            'name' => 'Admin Fitting',
            'email' => 'adminfitting@peroniks.com',
            'password' => bcrypt('password'),
            'product_scope' => null,
        ]);
        $u4->assignRole($adminRole);

        $u5 = User::factory()->create([
            'name' => 'SPV Lapisan',
            'email' => 'spvlapisan@peroniks.com',
            'password' => bcrypt('password'),
            'product_scope' => null,
        ]);
        $u5->assignRole($spvRole);

        $this->call([
            ProductionDummySeeder::class,
        ]);
    }
}
