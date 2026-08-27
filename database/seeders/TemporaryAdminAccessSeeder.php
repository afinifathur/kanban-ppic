<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class TemporaryAdminAccessSeeder extends Seeder
{
    /**
     * Run the database seeds to grant temporary admin role to MR and Direktur.
     */
    public function run(): void
    {
        // 1. Ensure admin role and permissions exist
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $accessPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution']);

        $adminRole->syncPermissions([$accessPlanning, $accessExecution]);

        // 2. Assign admin role to MR (mr@peroniks.com) - preserve existing password
        $mrUser = User::where('email', 'mr@peroniks.com')->first();
        if ($mrUser) {
            $mrUser->syncRoles(['admin']);
        } else {
            $mrPassword = (string) env('MR_INITIAL_PASSWORD', '');
            if ($mrPassword === '') {
                throw new \RuntimeException('Environment variable MR_INITIAL_PASSWORD is required to create MR account.');
            }
            $mrUser = User::create([
                'name' => 'MR Peroniks',
                'email' => 'mr@peroniks.com',
                'password' => Hash::make($mrPassword),
                'product_scope' => null,
            ]);
            $mrUser->syncRoles(['admin']);
        }

        // 3. Create or update Direktur (direktur@peroniks.com) with admin role
        $direkturUser = User::where('email', 'direktur@peroniks.com')->first();
        if ($direkturUser) {
            $direkturUser->update([
                'name' => 'Direktur Utama',
                'product_scope' => null,
            ]);
            $direkturUser->syncRoles(['admin']);
        } else {
            $initialPassword = (string) env('DIREKTUR_INITIAL_PASSWORD', '');
            if ($initialPassword === '') {
                throw new \RuntimeException('Environment variable DIREKTUR_INITIAL_PASSWORD is required to create Direktur account. Default/fallback passwords are not allowed.');
            }

            $direkturUser = User::create([
                'name' => 'Direktur Utama',
                'email' => 'direktur@peroniks.com',
                'password' => Hash::make($initialPassword),
                'product_scope' => null,
            ]);
            $direkturUser->syncRoles(['admin']);
        }
    }
}
