<?php

namespace App\Services\SandCasting;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class SandCastingStageAuthorizationService
{
    /**
     * Canonical Sand Casting operational stages.
     */
    public const VALID_STAGES = [
        'netto',
        'bubut_od',
        'bubut_cnc',
        'bor',
        'qc',
        'gudang_jadi',
    ];

    /**
     * URL Slug / Alternate name to Canonical Stage mapping.
     */
    public const STAGE_SLUG_MAP = [
        'netto' => 'netto',
        'bubut-od' => 'bubut_od',
        'bubut_od' => 'bubut_od',
        'bubut-cnc' => 'bubut_cnc',
        'bubut_cnc' => 'bubut_cnc',
        'bor' => 'bor',
        'qc' => 'qc',
        'gudang-jadi' => 'gudang_jadi',
        'gudang_jadi' => 'gudang_jadi',
    ];

    /**
     * Normalize stage string to canonical form.
     * Returns null if stage is not recognized.
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
     * Check if a user is authorized to view or execute a specific stage.
     */
    public function canAccessStage(?User $user, string $stage): bool
    {
        try {
            $this->authorize($user, $stage);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Strictly authorize a user for a specific Sand Casting stage.
     * Throws AuthorizationException on unauthorized access.
     * Throws InvalidArgumentException on invalid stage name.
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function authorize(?User $user, string $stage): void
    {
        $targetStage = self::normalizeStage($stage);

        if ($targetStage === null) {
            throw new InvalidArgumentException("Tahap operasional '{$stage}' tidak valid dalam alur Sand Casting.");
        }

        if (! $user) {
            throw new AuthorizationException('User belum terautentikasi.');
        }

        // 1. Admin users retain administrative access
        if ($user->hasRole('admin')) {
            return;
        }

        // 2. PPIC users retain planning and operational access
        if ($user->hasRole('ppic')) {
            return;
        }

        // 3. SPV users are strictly limited to their single assigned stage
        if ($user->hasRole('spv')) {
            if (empty($user->assigned_stage)) {
                throw new AuthorizationException('User SPV belum memiliki penugasan tahap operasional (assigned_stage).');
            }

            $userAssignedStage = self::normalizeStage($user->assigned_stage);

            if ($userAssignedStage !== $targetStage) {
                $userAssignedLabel = strtoupper(str_replace('_', ' ', (string) $user->assigned_stage));
                $targetLabel = strtoupper(str_replace('_', ' ', $targetStage));
                throw new AuthorizationException("User SPV dengan penugasan '{$userAssignedLabel}' tidak memiliki hak akses untuk mengeksekusi tahap '{$targetLabel}'.");
            }

            return;
        }

        // 4. admin_qc_fitting role does NOT have generic Sand Casting SPV execution access
        if ($user->hasRole('admin_qc_fitting')) {
            throw new AuthorizationException('Role QC Fitting tidak memiliki hak akses eksekusi lantai produksi Sand Casting.');
        }

        // 5. Default reject for any other role or user without authorized roles
        throw new AuthorizationException("User '{$user->name}' tidak memiliki wewenang untuk mengeksekusi tahap Sand Casting.");
    }

    /**
     * Check if a user is authorized to perform manual PPIC queue priority reordering.
     */
    public function canReorderQueue(?User $user): bool
    {
        try {
            $this->authorizeReorderQueue($user);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Authorize user for manual queue priority override.
     * Controlled specifically for authorized PPIC account.
     *
     * @throws AuthorizationException
     */
    public function authorizeReorderQueue(?User $user): void
    {
        if (! $user) {
            throw new AuthorizationException('User belum terautentikasi.');
        }

        // Temporary strict authorization rule: only ppicflange@peroniks.com with ppic role
        if ($user->hasRole('ppic') && strtolower(trim($user->email)) === 'ppicflange@peroniks.com') {
            return;
        }

        throw new AuthorizationException('Hanya akun PPIC Flange berwenang yang dapat mengubah urutan prioritas antrian.');
    }

    /**
     * Check if a user is authorized to view and record defects as Admin PPIC.
     */
    public function canRecordDefect(?User $user): bool
    {
        try {
            $this->authorizeRecordDefect($user);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Authorize user for Sand Casting PPIC defect recording.
     * Controlled for PPIC and Admin roles, with primary user adminppicfl@peroniks.com.
     *
     * @throws AuthorizationException
     */
    public function authorizeRecordDefect(?User $user): void
    {
        if (! $user) {
            throw new AuthorizationException('User belum terautentikasi.');
        }

        // 1. Admin role
        if ($user->hasRole('admin')) {
            return;
        }

        // 2. PPIC role
        if ($user->hasRole('ppic')) {
            return;
        }

        // 3. Specifically authorized email accounts
        $email = strtolower(trim((string) $user->email));
        if (in_array($email, ['adminppicfl@peroniks.com', 'ppicflange@peroniks.com', 'adminppicpf@peroniks.com'], true)) {
            return;
        }

        throw new AuthorizationException("User '{$user->name}' tidak memiliki hak akses untuk mencatat defect PPIC Sand Casting.");
    }

    /**
     * Check if a user is authorized to view and verify defects as Admin QC.
     */
    public function canVerifyQc(?User $user): bool
    {
        try {
            $this->authorizeVerifyQc($user);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Authorize user for Sand Casting QC defect verification.
     * Controlled for QC and Admin roles, with primary user adminqcflange@peroniks.com.
     *
     * @throws AuthorizationException
     */
    public function authorizeVerifyQc(?User $user): void
    {
        if (! $user) {
            throw new AuthorizationException('User belum terautentikasi.');
        }

        // 1. Admin role
        if ($user->hasRole('admin')) {
            return;
        }

        // 2. QC roles
        if ($user->hasRole('qc') || $user->hasRole('admin_qc') || $user->hasRole('admin_qc_fitting')) {
            return;
        }

        // 3. Specifically authorized QC email accounts
        $email = strtolower(trim((string) $user->email));
        if (in_array($email, ['adminqcflange@peroniks.com', 'adminqcfitting@peroniks.com'], true)) {
            return;
        }

        throw new AuthorizationException("User '{$user->name}' tidak memiliki hak akses untuk memverifikasi defect QC Sand Casting.");
    }
}
