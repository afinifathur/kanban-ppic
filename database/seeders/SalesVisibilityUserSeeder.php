<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SalesVisibilityUserSeeder extends Seeder
{
    public const TARGET_ACCOUNT = [
        'email' => 'sales@peroniks.com',
        'name' => 'Sales Peroniks',
        'assigned_stage' => null,
        'product_scope' => null,
    ];

    /**
     * Run the database seeds idempotently.
     */
    public function run(): void
    {
        // 1. Ensure dedicated permission access_production_visibility exists
        $permission = Permission::firstOrCreate(['name' => 'access_production_visibility']);

        // 2. Ensure dedicated role sales exists and only has access_production_visibility
        $role = Role::firstOrCreate(['name' => 'sales']);
        $role->syncPermissions([$permission]);

        // 3. Create or update user without resetting password if user already exists
        $user = User::where('email', self::TARGET_ACCOUNT['email'])->first();

        if ($user) {
            $user->update([
                'name' => self::TARGET_ACCOUNT['name'],
                'assigned_stage' => self::TARGET_ACCOUNT['assigned_stage'],
                'product_scope' => self::TARGET_ACCOUNT['product_scope'],
            ]);
            $user->syncRoles(['sales']);
        } else {
            $initialPassword = (string) env('SALES_INITIAL_PASSWORD', '');
            if ($initialPassword === '') {
                throw new \RuntimeException(
                    'Environment variable SALES_INITIAL_PASSWORD is required to create Sales Visibility account. Default/fallback passwords are not allowed.'
                );
            }

            $user = User::create([
                'name' => self::TARGET_ACCOUNT['name'],
                'email' => self::TARGET_ACCOUNT['email'],
                'password' => Hash::make($initialPassword),
                'assigned_stage' => self::TARGET_ACCOUNT['assigned_stage'],
                'product_scope' => self::TARGET_ACCOUNT['product_scope'],
            ]);

            $user->syncRoles(['sales']);
        }
    }
}
