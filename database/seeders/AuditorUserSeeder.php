<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AuditorUserSeeder extends Seeder
{
    public const TARGET_ACCOUNT = [
        'email' => 'auditor@peroniks.com',
        'name' => 'Auditor Peroniks',
        'assigned_stage' => null,
        'product_scope' => null,
    ];

    /**
     * Run the database seeds idempotently.
     */
    public function run(): void
    {
        // 1. Ensure dedicated permission access_auditor_visibility exists
        $permission = Permission::firstOrCreate([
            'name' => 'access_auditor_visibility',
            'guard_name' => 'web',
        ]);

        // 2. Ensure dedicated role auditor exists and only has access_auditor_visibility
        $role = Role::firstOrCreate([
            'name' => 'auditor',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions([$permission]);

        // 3. Create or update user without resetting password if user already exists
        $user = User::where('email', self::TARGET_ACCOUNT['email'])->first();

        if ($user) {
            $user->update([
                'name' => self::TARGET_ACCOUNT['name'],
                'assigned_stage' => self::TARGET_ACCOUNT['assigned_stage'],
                'product_scope' => self::TARGET_ACCOUNT['product_scope'],
            ]);
            $user->syncRoles(['auditor']);
        } else {
            $initialPassword = (string) env('AUDITOR_INITIAL_PASSWORD', '');
            if ($initialPassword === '') {
                throw new \RuntimeException(
                    'Environment variable AUDITOR_INITIAL_PASSWORD is required to create Auditor account. Default/fallback passwords are not allowed for production security.'
                );
            }

            $user = User::create([
                'name' => self::TARGET_ACCOUNT['name'],
                'email' => self::TARGET_ACCOUNT['email'],
                'password' => Hash::make($initialPassword),
                'assigned_stage' => self::TARGET_ACCOUNT['assigned_stage'],
                'product_scope' => self::TARGET_ACCOUNT['product_scope'],
            ]);

            $user->syncRoles(['auditor']);
        }
    }
}

