<?php

namespace App\Services\Fitting;

use App\Models\FittingItem;
use App\Models\FittingStageExecution;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class FittingStageAuthorizationService
{
    public const ROLE_SPV_FITTING = 'spv_fitting';

    public const ROLE_ADMIN_PPIC_FITTING = 'admin_ppic_fitting';

    public const PERMISSION_EXECUTION = 'access_fitting_execution';

    public const PERMISSION_ALL_KANBAN = 'access_all_fitting_kanban';

    public const PERMISSION_VISIBILITY = 'access_fitting_visibility';

    public const PERMISSION_MANAGE_KANBAN = 'manage_fitting_kanban';

    public const PERMISSION_TRANSFER = 'transfer_fitting_kitir';

    public const PERMISSION_RECORD_DEFECT = 'record_fitting_defect';

    public const PERMISSION_VERIFY_QC = 'verify_fitting_qc';

    public const ALL_PERMISSIONS = [
        self::PERMISSION_EXECUTION,
        self::PERMISSION_ALL_KANBAN,
        self::PERMISSION_VISIBILITY,
        self::PERMISSION_MANAGE_KANBAN,
        self::PERMISSION_TRANSFER,
        self::PERMISSION_RECORD_DEFECT,
        self::PERMISSION_VERIFY_QC,
    ];

    /**
     * Exact permissions granted to the dedicated admin_ppic_fitting operational role.
     * Deliberately excludes manage_fitting_kanban, access_fitting_execution, and verify_fitting_qc.
     */
    public const ADMIN_PPIC_FITTING_PERMISSIONS = [
        self::PERMISSION_ALL_KANBAN,
        self::PERMISSION_VISIBILITY,
        self::PERMISSION_TRANSFER,
        self::PERMISSION_RECORD_DEFECT,
    ];

    /**
     * Canonical 6 digital Fitting Production stages in strict sequence.
     */
    public const VALID_STAGES = [
        FittingItem::STAGE_NETTO_PF,
        FittingItem::STAGE_KIMIA,
        FittingItem::STAGE_MARKING,
        FittingItem::STAGE_BUBUT_CNC_PF,
        FittingItem::STAGE_QC_PF,
        FittingItem::STAGE_GUDANG_JADI_PF,
    ];

    /**
     * URL slug / alias to canonical stage mapping.
     */
    public const STAGE_SLUG_MAP = [
        'netto-pf' => FittingItem::STAGE_NETTO_PF,
        'netto_pf' => FittingItem::STAGE_NETTO_PF,
        'kimia' => FittingItem::STAGE_KIMIA,
        'marking' => FittingItem::STAGE_MARKING,
        'bubut-cnc-pf' => FittingItem::STAGE_BUBUT_CNC_PF,
        'bubut_cnc_pf' => FittingItem::STAGE_BUBUT_CNC_PF,
        'qc-pf' => FittingItem::STAGE_QC_PF,
        'qc_pf' => FittingItem::STAGE_QC_PF,
        'gudang-jadi-pf' => FittingItem::STAGE_GUDANG_JADI_PF,
        'gudang_jadi_pf' => FittingItem::STAGE_GUDANG_JADI_PF,
    ];

    public const STAGE_LABELS = [
        FittingItem::STAGE_NETTO_PF => 'NETTO PF',
        FittingItem::STAGE_KIMIA => 'KIMIA',
        FittingItem::STAGE_MARKING => 'MARKING',
        FittingItem::STAGE_BUBUT_CNC_PF => 'BUBUT CNC PF',
        FittingItem::STAGE_QC_PF => 'QC PF',
        FittingItem::STAGE_GUDANG_JADI_PF => 'GUDANG JADI PF',
    ];

    public const STAGE_CHECKPOINTS = [
        FittingItem::STAGE_NETTO_PF => FittingStageExecution::CHECKPOINT_NETTO,
        FittingItem::STAGE_KIMIA => FittingStageExecution::CHECKPOINT_KIMIA,
        FittingItem::STAGE_MARKING => FittingStageExecution::CHECKPOINT_MARKING,
        FittingItem::STAGE_BUBUT_CNC_PF => FittingStageExecution::CHECKPOINT_CNC,
        FittingItem::STAGE_QC_PF => FittingStageExecution::CHECKPOINT_QC,
        FittingItem::STAGE_GUDANG_JADI_PF => FittingStageExecution::CHECKPOINT_GUDANG_JADI,
    ];

    public const EMAIL_PPIC_FITTING = 'ppicfitting@peroniks.com';

    public const EMAIL_ADMIN_PPIC_PF = 'adminppicpf@peroniks.com';

    public const EMAIL_ADMIN_QC_FITTING = 'adminqcfitting@peroniks.com';

    public const EMAIL_SPV_GD_PF = 'spvgdpf@peroniks.com';

    /**
     * Normalize stage input to canonical Fitting stage code.
     */
    public static function normalizeStage(?string $stage): ?string
    {
        if ($stage === null) {
            return null;
        }

        $cleaned = strtolower(trim($stage));
        $canonical = self::STAGE_SLUG_MAP[$cleaned] ?? $cleaned;

        return in_array($canonical, self::VALID_STAGES, true) ? $canonical : null;
    }

    /**
     * Safely check a Spatie permission, catching only PermissionDoesNotExist
     * so real runtime errors are never silently swallowed.
     */
    protected static function safeHasPermission(?User $user, string $permission): bool
    {
        if (! $user) {
            return false;
        }

        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist $e) {
            return false;
        }
    }

    /**
     * Determine whether user is a true System Administrator in Fitting Production.
     * Accounts scoped by role `admin_ppic_fitting` (e.g. adminppicpf@peroniks.com)
     * are intentionally excluded from Super Admin bypass in Fitting operations,
     * while preserving their existing roles in other modules (Sand Casting / Lost Wax).
     */
    public static function isFittingSystemAdmin(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->hasRole('admin') && ! $user->hasRole(self::ROLE_ADMIN_PPIC_FITTING);
    }

    /**
     * Determine whether user is strictly a read-only observer (auditor / sales).
     */
    public static function isReadOnlyObserver(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return ($user->hasRole('auditor') || $user->hasRole('sales'))
            && ! self::isFittingSystemAdmin($user);
    }

    /**
     * Check if user has full read visibility across all 6 Fitting Kanban stages.
     * Driven strictly by Fitting System Admin or Spatie Permissions (no hardcoded email bypass).
     */
    public static function hasFullKanbanVisibility(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (self::isFittingSystemAdmin($user)) {
            return true;
        }

        return self::safeHasPermission($user, self::PERMISSION_ALL_KANBAN)
            || self::safeHasPermission($user, self::PERMISSION_VISIBILITY)
            || self::safeHasPermission($user, 'access_auditor_visibility')
            || self::safeHasPermission($user, 'access_production_visibility');
    }

    /**
     * Check if user can view Kanban for a specific Fitting stage.
     */
    public function canViewStageKanban(?User $user, string $stage): bool
    {
        try {
            $this->authorizeKanbanView($user, $stage);

            return true;
        } catch (AuthorizationException|InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * Authorize viewing a specific Fitting stage Kanban.
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function authorizeKanbanView(?User $user, string $stage): void
    {
        $targetStage = self::normalizeStage($stage);
        if ($targetStage === null) {
            throw new InvalidArgumentException("Tahap operasional '{$stage}' tidak valid dalam alur Fitting Production.");
        }

        if (! $user) {
            throw new AuthorizationException('User belum terautentikasi.');
        }

        if (self::hasFullKanbanVisibility($user)) {
            return;
        }

        $this->authorizeStageExecution($user, $targetStage);
    }

    /**
     * Check if user can physically scan / execute a specific Fitting stage.
     */
    public function canExecuteStage(?User $user, string $stage): bool
    {
        try {
            $this->authorizeStageExecution($user, $stage);

            return true;
        } catch (AuthorizationException|InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * Authorize physical scan execution on a specific Fitting stage.
     * Allows Fitting System Admin, or spv_fitting holding `access_fitting_execution`
     * whose assigned_stage matches $stage.
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function authorizeStageExecution(?User $user, string $stage): void
    {
        $targetStage = self::normalizeStage($stage);
        if ($targetStage === null) {
            throw new InvalidArgumentException("Tahap operasional '{$stage}' tidak valid dalam alur Fitting Production.");
        }

        if (! $user) {
            throw new AuthorizationException('User belum terautentikasi.');
        }

        if (self::isReadOnlyObserver($user)) {
            throw new AuthorizationException('Akun read-only tidak diperkenankan mengeksekusi scan tahap Fitting Production.');
        }

        if (self::isFittingSystemAdmin($user)) {
            return;
        }

        if ($user->hasRole(self::ROLE_SPV_FITTING)) {
            if (! self::safeHasPermission($user, self::PERMISSION_EXECUTION)) {
                throw new AuthorizationException("User '{$user->name}' tidak memiliki permission eksekusi Fitting (access_fitting_execution).");
            }

            if (empty($user->assigned_stage)) {
                throw new AuthorizationException("Akun SPV Fitting '{$user->name}' belum memiliki penugasan stage (assigned_stage).");
            }

            $assigned = self::normalizeStage($user->assigned_stage);
            if ($assigned === null) {
                throw new AuthorizationException("Penugasan stage '{$user->assigned_stage}' pada akun SPV Fitting '{$user->name}' tidak valid.");
            }

            if ($assigned !== $targetStage) {
                $assignedLabel = self::STAGE_LABELS[$assigned] ?? strtoupper($assigned);
                $targetLabel = self::STAGE_LABELS[$targetStage] ?? strtoupper($targetStage);

                throw new AuthorizationException(
                    "Akses ditolak. Akun Anda hanya ditugaskan pada tahap {$assignedLabel}, bukan tahap {$targetLabel}."
                );
            }

            return;
        }

        throw new AuthorizationException("User '{$user->name}' tidak memiliki hak eksekusi tahap Fitting Production.");
    }

    /**
     * Check if user can transfer Lost Wax Kitirs to Fitting or mark/undo NOT_CONTINUED.
     */
    public function canTransferKitir(?User $user): bool
    {
        try {
            $this->authorizeTransferKitir($user);

            return true;
        } catch (AuthorizationException $e) {
            return false;
        }
    }

    /**
     * Authorize transferring Lost Wax Kitirs to Fitting / managing NOT_CONTINUED.
     * Driven strictly by Fitting System Admin or `transfer_fitting_kitir` permission.
     *
     * @throws AuthorizationException
     */
    public function authorizeTransferKitir(?User $user): void
    {
        if (! $user) {
            throw new AuthorizationException('User belum terautentikasi.');
        }

        if (self::isReadOnlyObserver($user)) {
            throw new AuthorizationException('Akun read-only tidak memiliki kewenangan transfer Kitir Fitting.');
        }

        if (self::isFittingSystemAdmin($user)) {
            return;
        }

        if (self::safeHasPermission($user, self::PERMISSION_TRANSFER)) {
            return;
        }

        throw new AuthorizationException("User '{$user->name}' tidak berwenang melakukan transfer Kitir atau menandai NOT_CONTINUED.");
    }

    /**
     * Check if user can reorder Fitting Kanban queues.
     */
    public function canReorderKanban(?User $user): bool
    {
        try {
            $this->authorizeReorderKanban($user);

            return true;
        } catch (AuthorizationException $e) {
            return false;
        }
    }

    /**
     * Authorize reordering Fitting Kanban queue priorities.
     * Driven strictly by Fitting System Admin or `manage_fitting_kanban` permission.
     *
     * @throws AuthorizationException
     */
    public function authorizeReorderKanban(?User $user): void
    {
        if (! $user) {
            throw new AuthorizationException('User belum terautentikasi.');
        }

        if (self::isReadOnlyObserver($user)) {
            throw new AuthorizationException('Akun read-only tidak berwenang mengatur ulang antrean Kanban Fitting.');
        }

        if (self::isFittingSystemAdmin($user)) {
            return;
        }

        if (self::safeHasPermission($user, self::PERMISSION_MANAGE_KANBAN)) {
            return;
        }

        throw new AuthorizationException('Hanya PPIC Fitting atau Administrator Sistem yang berwenang mengatur ulang antrean Kanban Fitting.');
    }

    /**
     * Check if user can record or adjust asynchronous Fitting production defects.
     */
    public function canRecordDefect(?User $user): bool
    {
        try {
            $this->authorizeRecordDefect($user);

            return true;
        } catch (AuthorizationException $e) {
            return false;
        }
    }

    /**
     * Authorize asynchronous Fitting defect input / adjustment.
     * Driven strictly by Fitting System Admin or `record_fitting_defect` permission.
     *
     * @throws AuthorizationException
     */
    public function authorizeRecordDefect(?User $user): void
    {
        if (! $user) {
            throw new AuthorizationException('User belum terautentikasi.');
        }

        if (self::isReadOnlyObserver($user)) {
            throw new AuthorizationException('Akun read-only tidak berwenang mencatat defect Fitting Production.');
        }

        if (self::isFittingSystemAdmin($user)) {
            return;
        }

        if (self::safeHasPermission($user, self::PERMISSION_RECORD_DEFECT)) {
            return;
        }

        throw new AuthorizationException("User '{$user->name}' tidak berwenang mencatat kerusakan produksi Fitting.");
    }

    /**
     * Check if user can verify Fitting QC defect types / breakdowns.
     */
    public function canVerifyQc(?User $user): bool
    {
        try {
            $this->authorizeVerifyQc($user);

            return true;
        } catch (AuthorizationException $e) {
            return false;
        }
    }

    /**
     * Authorize Fitting QC defect verification.
     * Driven strictly by Fitting System Admin or `verify_fitting_qc` permission.
     *
     * @throws AuthorizationException
     */
    public function authorizeVerifyQc(?User $user): void
    {
        if (! $user) {
            throw new AuthorizationException('User belum terautentikasi.');
        }

        if (self::isReadOnlyObserver($user)) {
            throw new AuthorizationException('Akun read-only tidak berwenang memverifikasi defect QC Fitting.');
        }

        if (self::isFittingSystemAdmin($user)) {
            return;
        }

        if (self::safeHasPermission($user, self::PERMISSION_VERIFY_QC)) {
            return;
        }

        throw new AuthorizationException("User '{$user->name}' tidak berwenang memverifikasi jenis defect QC Fitting.");
    }

    /**
     * Check if user can view Fitting Production Status (includes read-only Auditor & Sales).
     * Excludes single-stage SPV Fitting.
     */
    public static function canViewProductionStatus(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (self::isFittingSystemAdmin($user)) {
            return true;
        }

        if ($user->hasRole(self::ROLE_SPV_FITTING)) {
            return false;
        }

        return self::safeHasPermission($user, self::PERMISSION_VISIBILITY)
            || self::safeHasPermission($user, 'access_auditor_visibility')
            || self::safeHasPermission($user, 'access_production_visibility');
    }

    /**
     * Authorize user to view Fitting Production Status.
     * Throws AuthorizationException if denied.
     *
     * @throws AuthorizationException
     */
    public function authorizeViewProductionStatus(?User $user): void
    {
        if (! self::canViewProductionStatus($user)) {
            throw new AuthorizationException('Anda tidak memiliki hak akses untuk melihat Status Produksi Fitting.');
        }
    }

    /**
     * Check if user can view Fitting Management Dashboard.
     * Strictly excludes Sales and ALL `spv_fitting` accounts (including single-stage & spvgdpf).
     */
    public static function canViewDashboard(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (self::isFittingSystemAdmin($user)) {
            return true;
        }

        if ($user->hasRole('sales') || $user->hasRole(self::ROLE_SPV_FITTING)) {
            return false;
        }

        return self::safeHasPermission($user, 'access_auditor_visibility')
            || self::safeHasPermission($user, self::PERMISSION_MANAGE_KANBAN)
            || self::safeHasPermission($user, self::PERMISSION_TRANSFER)
            || self::safeHasPermission($user, self::PERMISSION_RECORD_DEFECT)
            || self::safeHasPermission($user, self::PERMISSION_VERIFY_QC);
    }

    /**
     * Authorize user to view Fitting Dashboard or throw AuthorizationException.
     *
     * @throws AuthorizationException
     */
    public function authorizeViewDashboard(?User $user): void
    {
        if (! self::canViewDashboard($user)) {
            throw new AuthorizationException('Anda tidak memiliki wewenang untuk mengakses dashboard Fitting.');
        }
    }

    /**
     * Check if user can view Fitting Reports (Production Report & QC Defect Report).
     * Strictly excludes Sales and ALL `spv_fitting` accounts.
     */
    public static function canViewReports(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (self::isFittingSystemAdmin($user)) {
            return true;
        }

        if ($user->hasRole('sales') || $user->hasRole(self::ROLE_SPV_FITTING)) {
            return false;
        }

        return self::safeHasPermission($user, 'access_auditor_visibility')
            || self::safeHasPermission($user, self::PERMISSION_MANAGE_KANBAN)
            || self::safeHasPermission($user, self::PERMISSION_RECORD_DEFECT)
            || self::safeHasPermission($user, self::PERMISSION_VERIFY_QC);
    }

    /**
     * Authorize user to view Fitting Reports or throw AuthorizationException.
     *
     * @throws AuthorizationException
     */
    public function authorizeViewReports(?User $user): void
    {
        if (! self::canViewReports($user)) {
            throw new AuthorizationException('Anda tidak memiliki hak akses untuk melihat Laporan Fitting Production.');
        }
    }

    /**
     * Helper to determine whether the Fitting Production navigation group should appear for a user.
     */
    public static function canAccessFittingModule(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $instance = new self;

        return $user->hasRole(self::ROLE_SPV_FITTING)
            || self::hasFullKanbanVisibility($user)
            || self::canViewProductionStatus($user)
            || self::canViewDashboard($user)
            || $instance->canTransferKitir($user)
            || $instance->canReorderKanban($user)
            || $instance->canRecordDefect($user)
            || $instance->canVerifyQc($user);
    }
}
