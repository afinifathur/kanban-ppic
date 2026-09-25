<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SandCastingPpicUserSeeder extends Seeder
{
    /**
     * Target Admin PPIC account for Modern Sand Casting (Flange).
     */
    public const PPIC_ACCOUNT = [
        'email' => 'adminppicfl@peroniks.com',
        'name' => 'Admin PPIC Flange',
        'assigned_stage' => null,
        'product_scope' => 'FLANGE_BESI',
    ];

    /**
     * Run the database seeds idempotently.
     */
    public function run(): void
    {
        // 1. Ensure permission access_execution and role ppic exist
        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution']);
        $ppicRole = Role::firstOrCreate(['name' => 'ppic']);
        $ppicRole->givePermissionTo($accessExecution);

        // 2. Idempotently create or update Admin PPIC Flange user
        $account = self::PPIC_ACCOUNT;
        $user = User::where('email', $account['email'])->first();

        if ($user) {
            $user->update([
                'name' => $account['name'],
                'assigned_stage' => $account['assigned_stage'],
                'product_scope' => $account['product_scope'],
            ]);
        } else {
            $user = User::create([
                'name' => $account['name'],
                'email' => $account['email'],
                'password' => Hash::make('password'),
                'assigned_stage' => $account['assigned_stage'],
                'product_scope' => $account['product_scope'],
            ]);
        }

        // 3. Sync role ppic
        $user->syncRoles(['ppic']);
    }
}
