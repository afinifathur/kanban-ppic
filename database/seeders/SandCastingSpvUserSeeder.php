<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SandCastingSpvUserSeeder extends Seeder
{
    /**
     * Target SPV accounts for Modern Sand Casting (Flange).
     */
    public const SPV_ACCOUNTS = [
        [
            'email' => 'spvnettofl@peroniks.com',
            'name' => 'SPV Netto Flange',
            'assigned_stage' => 'netto',
            'product_scope' => 'FLANGE_BESI',
        ],
        [
            'email' => 'spvbubutod@peroniks.com',
            'name' => 'SPV Bubut OD Flange',
            'assigned_stage' => 'bubut_od',
            'product_scope' => 'FLANGE_BESI',
        ],
        [
            'email' => 'spvbubutcncfl@peroniks.com',
            'name' => 'SPV Bubut CNC Flange',
            'assigned_stage' => 'bubut_cnc',
            'product_scope' => 'FLANGE_BESI',
        ],
        [
            'email' => 'spvborfl@peroniks.com',
            'name' => 'SPV Bor Flange',
            'assigned_stage' => 'bor',
            'product_scope' => 'FLANGE_BESI',
        ],
        [
            'email' => 'spvqcfl@peroniks.com',
            'name' => 'SPV QC Flange',
            'assigned_stage' => 'qc',
            'product_scope' => 'FLANGE_BESI',
        ],
        [
            'email' => 'spvgdfl@peroniks.com',
            'name' => 'SPV Gudang Jadi Flange',
            'assigned_stage' => 'gudang_jadi',
            'product_scope' => 'FLANGE_BESI',
        ],
    ];

    /**
     * Run the database seeds idempotently.
     */
    public function run(): void
    {
        // 1. Ensure permissions and SPV role exist
        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution']);
        $spvRole = Role::firstOrCreate(['name' => 'spv']);
        $spvRole->givePermissionTo($accessExecution);

        // 2. Idempotently create or update each SPV user
        foreach (self::SPV_ACCOUNTS as $account) {
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

            $user->syncRoles(['spv']);
        }
    }
}
