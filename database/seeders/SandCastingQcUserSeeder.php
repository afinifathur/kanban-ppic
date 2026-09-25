<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SandCastingQcUserSeeder extends Seeder
{
    /**
     * Target Admin QC account for Modern Sand Casting (Flange).
     */
    public const QC_ACCOUNT = [
        'email' => 'adminqcflange@peroniks.com',
        'name' => 'Admin QC Flange',
        'assigned_stage' => null,
        'product_scope' => 'FLANGE_BESI',
    ];

    /**
     * Run the database seeds idempotently.
     */
    public function run(): void
    {
        // 1. Ensure permission access_execution and role qc exist
        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution']);
        $qcRole = Role::firstOrCreate(['name' => 'qc']);
        $qcRole->givePermissionTo($accessExecution);

        // 2. Idempotently create or update Admin QC Flange user
        $account = self::QC_ACCOUNT;
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

        // 3. Sync role qc
        $user->syncRoles(['qc']);
    }
}
