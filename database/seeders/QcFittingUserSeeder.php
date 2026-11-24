<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class QcFittingUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Idempotently create/ensure admin_qc_fitting role and adminqcfitting@peroniks.com user.
     */
    public function run(): void
    {
        // 1. Ensure required permissions exist idempotently
        $accessPlanning = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);
        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution', 'guard_name' => 'web']);
        $verifyQc = Permission::firstOrCreate(['name' => \App\Services\Fitting\FittingStageAuthorizationService::PERMISSION_VERIFY_QC, 'guard_name' => 'web']);
        $allKanban = Permission::firstOrCreate(['name' => \App\Services\Fitting\FittingStageAuthorizationService::PERMISSION_ALL_KANBAN, 'guard_name' => 'web']);
        $visibility = Permission::firstOrCreate(['name' => \App\Services\Fitting\FittingStageAuthorizationService::PERMISSION_VISIBILITY, 'guard_name' => 'web']);

        // 2. Ensure admin_qc_fitting role exists and explicitly receives QC verification & read visibility
        $qcRole = Role::firstOrCreate(['name' => 'admin_qc_fitting', 'guard_name' => 'web']);
        $qcRole->syncPermissions([
            $verifyQc,
            $allKanban,
            $visibility,
            $accessPlanning,
            $accessExecution,
        ]);

        // 3. Ensure user adminqcfitting@peroniks.com exists idempotently
        $user = User::where('email', 'adminqcfitting@peroniks.com')->first();

        if ($user) {
            $user->update([
                'name' => 'Admin QC Fitting',
                'product_scope' => null,
            ]);
        } else {
            $user = User::create([
                'name' => 'Admin QC Fitting',
                'email' => 'adminqcfitting@peroniks.com',
                'password' => Hash::make('password'),
                'product_scope' => null,
            ]);
        }

        // 4. Sync role
        $user->syncRoles(['admin_qc_fitting']);
    }
}
