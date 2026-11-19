<?php

namespace Database\Seeders;

use App\Models\FittingItem;
use App\Models\User;
use App\Services\Fitting\FittingStageAuthorizationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class FittingSpvAccountSeeder extends Seeder
{
    /**
     * The 6 canonical SPV accounts planned for Fitting Production stages.
     */
    public const SPV_ACCOUNTS = [
        [
            'email' => 'spvnettopf@peroniks.com',
            'name' => 'SPV Netto PF',
            'assigned_stage' => FittingItem::STAGE_NETTO_PF,
            'product_scope' => 'FITTING_STAINLESS',
        ],
        [
            'email' => 'spvkimiapf@peroniks.com',
            'name' => 'SPV Kimia PF',
            'assigned_stage' => FittingItem::STAGE_KIMIA,
            'product_scope' => 'FITTING_STAINLESS',
        ],
        [
            'email' => 'spvmarkingpf@peroniks.com',
            'name' => 'SPV Marking PF',
            'assigned_stage' => FittingItem::STAGE_MARKING,
            'product_scope' => 'FITTING_STAINLESS',
        ],
        [
            'email' => 'spvcncpf@peroniks.com',
            'name' => 'SPV Bubut CNC PF',
            'assigned_stage' => FittingItem::STAGE_BUBUT_CNC_PF,
            'product_scope' => 'FITTING_STAINLESS',
        ],
        [
            'email' => 'spvqcpf@peroniks.com',
            'name' => 'SPV QC PF',
            'assigned_stage' => FittingItem::STAGE_QC_PF,
            'product_scope' => 'FITTING_STAINLESS',
        ],
        [
            'email' => 'spvgdpf@peroniks.com',
            'name' => 'SPV Gudang Jadi PF',
            'assigned_stage' => FittingItem::STAGE_GUDANG_JADI_PF,
            'product_scope' => 'FITTING_STAINLESS',
        ],
    ];

    /**
     * Run the database seeds idempotently for Fitting Production SPV accounts.
     * Sets password to 'password' using Laravel Hash for all 6 accounts.
     * Does not mutate other accounts, global roles, or passwords of non-SPV accounts.
     */
    public function run(): void
    {
        DB::transaction(function () {
            // 1. Ensure required permissions exist (guard: web)
            $permExecution = Permission::firstOrCreate([
                'name' => FittingStageAuthorizationService::PERMISSION_EXECUTION,
                'guard_name' => 'web',
            ]);

            $permAllKanban = Permission::firstOrCreate([
                'name' => FittingStageAuthorizationService::PERMISSION_ALL_KANBAN,
                'guard_name' => 'web',
            ]);

            // 2. Ensure dedicated Fitting SPV role exists with execution permission
            $spvFittingRole = Role::firstOrCreate([
                'name' => FittingStageAuthorizationService::ROLE_SPV_FITTING,
                'guard_name' => 'web',
            ]);
            $spvFittingRole->givePermissionTo($permExecution);

            // 3. Provision or update each of the 6 SPV accounts idempotently
            foreach (self::SPV_ACCOUNTS as $account) {
                $user = User::updateOrCreate(
                    ['email' => $account['email']],
                    [
                        'name' => $account['name'],
                        'password' => Hash::make('password'),
                        'assigned_stage' => $account['assigned_stage'],
                        'product_scope' => $account['product_scope'],
                    ]
                );

                $user->syncRoles([FittingStageAuthorizationService::ROLE_SPV_FITTING]);

                // SPV Gudang Jadi gets access_all_fitting_kanban to oversee dispatch across all stages
                if ($account['email'] === FittingStageAuthorizationService::EMAIL_SPV_GD_PF) {
                    $user->givePermissionTo($permAllKanban);
                } else {
                    if ($user->hasDirectPermission(FittingStageAuthorizationService::PERMISSION_ALL_KANBAN)) {
                        $user->revokePermissionTo($permAllKanban);
                    }
                }
            }
        });

        // 4. Invalidate Spatie permission cache to take effect immediately
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (isset($this->command)) {
            $this->command->info('Seeding completed: 6 Fitting Production SPV accounts are ready with password "password".');
        }
    }
}
