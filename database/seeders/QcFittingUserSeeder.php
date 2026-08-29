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
        // 1. Ensure permissions exist
        $accessPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution']);

        // 2. Ensure admin_qc_fitting role exists and has read permissions
        $qcRole = Role::firstOrCreate(['name' => 'admin_qc_fitting']);
        $qcRole->syncPermissions([$accessPlanning, $accessExecution]);

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
