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

class FittingSpvUserSeeder extends Seeder
{
    /**
     * Target 6 SPV accounts for Fitting Production (isolated from Sand Casting SPVs).
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
     * Run the database seeds atomically and idempotently without modifying existing passwords or Sand Casting RBAC.
     */
    public function run(): void
    {
        // 0. Upfront password guard BEFORE any database mutation (prevents partial seeding on missing env var)
        $missingSpvEmails = collect(self::SPV_ACCOUNTS)
            ->pluck('email')
            ->filter(fn (string $email) => ! User::where('email', $email)->exists());

        $initialPassword = (string) env('FITTING_SPV_INITIAL_PASSWORD', '');
        if ($missingSpvEmails->isNotEmpty() && $initialPassword === '') {
            throw new \RuntimeException(
                'Environment variable FITTING_SPV_INITIAL_PASSWORD is required to create Fitting SPV accounts. Default/fallback passwords are not allowed for production security.'
            );
        }

        DB::transaction(function () use ($initialPassword) {
            // 1. Ensure all dedicated Fitting permissions exist (guard: web)
            $permissions = [];
            foreach (FittingStageAuthorizationService::ALL_PERMISSIONS as $permName) {
                $permissions[$permName] = Permission::firstOrCreate([
                    'name' => $permName,
                    'guard_name' => 'web',
                ]);
            }

            // 2. Ensure dedicated Fitting SPV role exists (isolated from Sand Casting 'spv' role)
            $spvFittingRole = Role::firstOrCreate([
                'name' => FittingStageAuthorizationService::ROLE_SPV_FITTING,
                'guard_name' => 'web',
            ]);
            $spvFittingRole->syncPermissions([
                $permissions[FittingStageAuthorizationService::PERMISSION_EXECUTION],
            ]);

            // 3. Ensure dedicated admin_ppic_fitting role exists with strictly scoped Fitting permissions
            //    (NO manage_fitting_kanban, NO access_fitting_execution, NO verify_fitting_qc)
            $adminPpicFittingRole = Role::firstOrCreate([
                'name' => FittingStageAuthorizationService::ROLE_ADMIN_PPIC_FITTING,
                'guard_name' => 'web',
            ]);
            $adminPpicFittingRole->syncPermissions(
                array_map(
                    fn (string $name) => $permissions[$name],
                    FittingStageAuthorizationService::ADMIN_PPIC_FITTING_PERMISSIONS
                )
            );

            // 4. Grant Fitting permissions additively to existing roles/accounts (NEVER reset passwords or strip existing perms)
            $this->grantFittingPermissionsToExistingRolesAndUsers($permissions, $adminPpicFittingRole);

            // 5. Idempotently provision the 6 Fitting SPV accounts
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
                        'password' => Hash::make($initialPassword),
                        'assigned_stage' => $account['assigned_stage'],
                        'product_scope' => $account['product_scope'],
                    ]);
                }

                $user->syncRoles([FittingStageAuthorizationService::ROLE_SPV_FITTING]);

                if ($account['email'] === FittingStageAuthorizationService::EMAIL_SPV_GD_PF) {
                    $user->givePermissionTo($permissions[FittingStageAuthorizationService::PERMISSION_ALL_KANBAN]);
                } else {
                    // Ensure the other 5 SPV accounts do NOT hold full-kanban permission
                    if ($user->hasDirectPermission(FittingStageAuthorizationService::PERMISSION_ALL_KANBAN)) {
                        $user->revokePermissionTo($permissions[FittingStageAuthorizationService::PERMISSION_ALL_KANBAN]);
                    }
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Additively grant Fitting permissions to existing roles and accounts without mutating
     * passwords, existing Sand Casting/Lost Wax roles, or existing permissions.
     *
     * @param  array<string, Permission>  $permissions
     */
    protected function grantFittingPermissionsToExistingRolesAndUsers(array $permissions, Role $adminPpicFittingRole): void
    {
        // IMPORTANT (H2): Do NOT attach Fitting operational permissions to the global 'admin' role,
        // so accounts that carry both 'admin' (for legacy/Lost Wax/Sand Casting) and 'admin_ppic_fitting'
        // (such as adminppicpf@peroniks.com) cannot inherit verify_fitting_qc / manage_fitting_kanban /
        // access_fitting_execution via Spatie role inheritance. True system administrators (role 'admin'
        // without 'admin_ppic_fitting') receive full Fitting access via FittingStageAuthorizationService::isFittingSystemAdmin().
        $adminRole = Role::where('name', 'admin')->where('guard_name', 'web')->first();
        if ($adminRole) {
            foreach (FittingStageAuthorizationService::ALL_PERMISSIONS as $permName) {
                if ($adminRole->hasPermissionTo($permName)) {
                    $adminRole->revokePermissionTo($permissions[$permName]);
                }
            }
        }

        // Auditor & Sales roles receive read-only Fitting visibility additively
        foreach (['auditor', 'sales'] as $observerRoleName) {
            $observerRole = Role::where('name', $observerRoleName)->where('guard_name', 'web')->first();
            if ($observerRole) {
                $observerRole->givePermissionTo($permissions[FittingStageAuthorizationService::PERMISSION_VISIBILITY]);
            }
        }

        // Role admin_qc_fitting receives QC verification & read visibility additively
        $qcFittingRole = Role::where('name', 'admin_qc_fitting')->where('guard_name', 'web')->first();
        if ($qcFittingRole) {
            $qcFittingRole->givePermissionTo([
                $permissions[FittingStageAuthorizationService::PERMISSION_VERIFY_QC],
                $permissions[FittingStageAuthorizationService::PERMISSION_ALL_KANBAN],
                $permissions[FittingStageAuthorizationService::PERMISSION_VISIBILITY],
            ]);
        }

        // Account: ppicfitting@peroniks.com -> Direct permissions (shares 'ppic' role with Flange PPIC accounts)
        $ppicFitting = User::where('email', FittingStageAuthorizationService::EMAIL_PPIC_FITTING)->first();
        if ($ppicFitting) {
            $ppicFitting->givePermissionTo([
                $permissions[FittingStageAuthorizationService::PERMISSION_MANAGE_KANBAN],
                $permissions[FittingStageAuthorizationService::PERMISSION_TRANSFER],
                $permissions[FittingStageAuthorizationService::PERMISSION_ALL_KANBAN],
                $permissions[FittingStageAuthorizationService::PERMISSION_VISIBILITY],
            ]);
        }

        // Account: adminppicpf@peroniks.com -> Additively assign dedicated 'admin_ppic_fitting' role
        // while preserving its existing role(s) for Sand Casting / Lost Wax / Planning compatibility.
        // Clean redundant direct Fitting permissions so Fitting access is governed cleanly by the role.
        $adminPpicPf = User::where('email', FittingStageAuthorizationService::EMAIL_ADMIN_PPIC_PF)->first();
        if ($adminPpicPf) {
            if (! $adminPpicPf->hasRole($adminPpicFittingRole->name)) {
                $adminPpicPf->assignRole($adminPpicFittingRole);
            }
            foreach (FittingStageAuthorizationService::ALL_PERMISSIONS as $permName) {
                if ($adminPpicPf->hasDirectPermission($permName)) {
                    $adminPpicPf->revokePermissionTo($permissions[$permName]);
                }
            }
        }

        // Account: adminqcfitting@peroniks.com -> Clean redundant direct Fitting perms if role already grants them
        $adminQcFitting = User::where('email', FittingStageAuthorizationService::EMAIL_ADMIN_QC_FITTING)->first();
        if ($adminQcFitting && $adminQcFitting->hasRole('admin_qc_fitting')) {
            foreach (FittingStageAuthorizationService::ALL_PERMISSIONS as $permName) {
                if ($adminQcFitting->hasDirectPermission($permName)) {
                    $adminQcFitting->revokePermissionTo($permissions[$permName]);
                }
            }
        }
    }
}
