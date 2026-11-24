<?php

namespace App\Services\Fitting;

use App\Models\DefectType;
use App\Models\FittingItem;
use App\Models\FittingStageExecution;
use App\Models\FittingStageExecutionDefectLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Transactional service for Fitting Production Phase 4:
 * - Asynchronous Defect Recording & Cumulative Delta Adjustment (Admin PPIC Fitting)
 * - QC Breakdown Draft, Verification & Return for Correction (Admin QC Fitting)
 * - Auto-Nihil 5-Day Timeout (System)
 * - Final Quality Lock Guard (locks stages 1..5 once Gudang Jadi PF is physically done)
 *
 * Canonical Lock Hierarchy (100% compatible with Phase 2.1 & Phase 3 — ZERO AB-BA inversion):
 *   1. fitting_items (parent traveler)
 *   2. fitting_stage_executions (ORDER BY id ASC for parent traveler)
 *   3. fitting_stage_execution_defects / fitting_stage_execution_defect_logs
 */
class FittingDefectService
{
    public function __construct(
        private readonly FittingStageAuthorizationService $authService,
        private readonly FittingQuantityResolverService $quantityResolver
    ) {}

    /**
     * Step 2A: Initial defect quantity recording by Admin PPIC Fitting (WAITING_DEFECT -> WAITING_QC).
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws InvalidArgumentException
     */
    public function recordDefectQty(
        int|FittingStageExecution $executionOrId,
        int $defectQty,
        User $user,
        ?string $notes = null,
        ?string $inspectionDate = null,
        ?string $idempotencyKey = null
    ): FittingStageExecution {
        $this->authService->authorizeRecordDefect($user);

        if ($defectQty < 0) {
            throw new InvalidArgumentException('Kuantitas defect tidak boleh negatif.');
        }

        $cleanNotes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;
        $cleanKey = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? substr(trim($idempotencyKey), 0, 64)
            : null;
        $parsedInspDate = $this->parseInspectionDate($inspectionDate);

        return DB::transaction(function () use ($executionOrId, $defectQty, $user, $cleanNotes, $parsedInspDate, $cleanKey) {
            [$item, $execution] = $this->lockItemAndExecutions($executionOrId);

            // Idempotency check inside lock
            if ($cleanKey !== null) {
                $existingLog = FittingStageExecutionDefectLog::where('idempotency_key', $cleanKey)
                    ->lockForUpdate()
                    ->first();
                if ($existingLog) {
                    if ((int) $existingLog->fitting_stage_execution_id !== (int) $execution->id) {
                        throw new InvalidArgumentException("Idempotency key '{$cleanKey}' telah digunakan pada eksekusi PFKTR lain.");
                    }
                    if ((int) $existingLog->new_total !== $defectQty) {
                        throw new InvalidArgumentException("Idempotency key '{$cleanKey}' telah digunakan sebelumnya dengan kuantitas berbeda ({$existingLog->new_total} PCS).");
                    }

                    return $execution->load(['fittingItem', 'operator', 'defectEnteredBy', 'qcVerifiedBy', 'defects.defectType', 'defectLogs.user']);
                }
            }

            $this->assertStageQualityNotLocked($execution, $item);

            if ($execution->status !== FittingStageExecution::STATUS_WAITING_DEFECT) {
                throw new InvalidArgumentException(
                    "Eksekusi tahap {$execution->checkpoint_code} sudah tidak berstatus WAITING_DEFECT (Status saat ini: {$execution->status}). Gunakan menu penyesuaian/koreksi defect."
                );
            }

            $effectiveInput = $this->quantityResolver->resolveEffectiveInputQty($item, $execution->stage);
            if ($defectQty > $effectiveInput) {
                throw new InvalidArgumentException(
                    "Kuantitas defect ({$defectQty} PCS) melebihi kuantitas input efektif tahap ini ({$effectiveInput} PCS)."
                );
            }

            $maxAllowed = $this->quantityResolver->resolveMaxAllowedDefectQty($item, $execution->stage);
            if ($defectQty > $maxAllowed) {
                throw new InvalidArgumentException(
                    "Kuantitas defect ({$defectQty} PCS) melebihi sisa kapasitas kuantitas traveler ({$maxAllowed} PCS) setelah memperhitungkan defect tahap lain."
                );
            }

            $previousTotal = (int) $execution->defect_qty;
            $delta = $defectQty - $previousTotal;
            $newGoodQty = max(0, $effectiveInput - $defectQty);
            $now = Carbon::now();

            $execution->defect_qty = $defectQty;
            $execution->good_qty = $newGoodQty;
            // Phase 4.1: defect = 0 transitions to NO_DEFECT (never enters QC queue); > 0 transitions to WAITING_QC
            $execution->status = $defectQty === 0
                ? FittingStageExecution::STATUS_NO_DEFECT
                : FittingStageExecution::STATUS_WAITING_QC;
            $execution->defect_entered_at = $now;
            $execution->defect_entered_by = $user->id;
            $execution->is_auto_nihil = false;
            // Preserve historical `auto_nihil_at` provenance permanently if it was ever set
            if ($parsedInspDate !== null) {
                $execution->inspection_date = $parsedInspDate;
            }
            if ($cleanNotes !== null) {
                $execution->notes = $cleanNotes;
            }
            $execution->save();

            // Clear stale QC breakdown if defect became 0
            if ($defectQty === 0) {
                $execution->defects()->delete();
            }

            $this->createDefectLogSafe($execution, [
                'added_qty' => $delta,
                'previous_total' => $previousTotal,
                'new_total' => $defectQty,
                'user_id' => $user->id,
                'is_system_action' => false,
                'notes' => $cleanNotes ?? ($previousTotal > 0 ? 'Koreksi ulang pasca pengembalian QC' : ($defectQty === 0 ? 'Pencatatan defect awal (Nihil / 0 PCS)' : 'Pencatatan defect awal')),
                'idempotency_key' => $cleanKey,
            ]);

            return $execution->fresh(['fittingItem', 'operator', 'defectEnteredBy', 'qcVerifiedBy', 'defects.defectType', 'defectLogs.user']);
        });
    }

    /**
     * Step 2B: Adjust defect quantity by signed delta (+/-) with immutable audit trail.
     * Phase 4.1 rules:
     * - STRICTLY rejects direct edit on CONFIRMED executions (Admin QC must reopen first).
     * - Allowed on NO_DEFECT, WAITING_QC, and REOPENED_FOR_CORRECTION.
     * - On REOPENED_FOR_CORRECTION, `adjustmentQty === 0` is allowed with mandatory reason to confirm
     *   unchanged quantity and return the execution to WAITING_QC for QC re-verification.
     * - Result `0` transitions to NO_DEFECT (exits QC queue); result `> 0` transitions to WAITING_QC.
     * - Preserves `auto_nihil_at` permanently while setting `is_auto_nihil = false`.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws InvalidArgumentException
     */
    public function adjustDefectQty(
        int|FittingStageExecution $executionOrId,
        int $adjustmentQty,
        User $user,
        ?string $notes = null,
        ?string $idempotencyKey = null
    ): FittingStageExecution {
        $this->authService->authorizeRecordDefect($user);

        $cleanNotes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;
        if ($adjustmentQty < 0 && $cleanNotes === null) {
            throw new InvalidArgumentException('Catatan / alasan wajib diisi untuk pengurangan (koreksi negatif) kuantitas defect.');
        }

        $cleanKey = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? substr(trim($idempotencyKey), 0, 64)
            : null;

        return DB::transaction(function () use ($executionOrId, $adjustmentQty, $user, $cleanNotes, $cleanKey) {
            [$item, $execution] = $this->lockItemAndExecutions($executionOrId);

            if ($cleanKey !== null) {
                $existingLog = FittingStageExecutionDefectLog::where('idempotency_key', $cleanKey)
                    ->lockForUpdate()
                    ->first();
                if ($existingLog) {
                    if ((int) $existingLog->fitting_stage_execution_id !== (int) $execution->id) {
                        throw new InvalidArgumentException("Idempotency key '{$cleanKey}' telah digunakan pada eksekusi PFKTR lain.");
                    }
                    if ((int) $existingLog->added_qty !== $adjustmentQty) {
                        throw new InvalidArgumentException("Idempotency key '{$cleanKey}' telah digunakan sebelumnya dengan delta berbeda ({$existingLog->added_qty} PCS).");
                    }

                    return $execution->load(['fittingItem', 'operator', 'defectEnteredBy', 'qcVerifiedBy', 'defects.defectType', 'defectLogs.user']);
                }
            }

            $this->assertStageQualityNotLocked($execution, $item);

            if ($execution->status === FittingStageExecution::STATUS_WAITING_DEFECT) {
                throw new InvalidArgumentException(
                    "Eksekusi {$execution->checkpoint_code} belum pernah dicatat defect. Gunakan pencatatan defect awal terlebih dahulu."
                );
            }

            if ($execution->status === FittingStageExecution::STATUS_CONFIRMED) {
                throw new InvalidArgumentException(
                    "Eksekusi {$execution->checkpoint_code} sudah terverifikasi QC (CONFIRMED) dan tidak boleh diedit langsung oleh PPIC. Admin QC harus membuka kembali (Reopen) verifikasi terlebih dahulu."
                );
            }

            $allowedStatuses = [
                FittingStageExecution::STATUS_NO_DEFECT,
                FittingStageExecution::STATUS_WAITING_QC,
                FittingStageExecution::STATUS_REOPENED_FOR_CORRECTION,
            ];

            if (! in_array($execution->status, $allowedStatuses, true)) {
                throw new InvalidArgumentException("Status eksekusi ({$execution->status}) tidak valid untuk penyesuaian defect.");
            }

            $isReopened = $execution->status === FittingStageExecution::STATUS_REOPENED_FOR_CORRECTION;

            if ($adjustmentQty === 0) {
                if (! $isReopened) {
                    throw new InvalidArgumentException('Nilai penyesuaian defect (delta) tidak boleh 0.');
                }
                if ($cleanNotes === null || strlen($cleanNotes) < 3) {
                    throw new InvalidArgumentException('Alasan koreksi wajib diisi (minimal 3 karakter) untuk mengonfirmasi jumlah defect tetap pasca Reopen QC.');
                }
            } elseif ($isReopened && ($cleanNotes === null || strlen($cleanNotes) < 3)) {
                throw new InvalidArgumentException('Alasan koreksi wajib diisi (minimal 3 karakter) untuk penyesuaian defect pasca Reopen QC.');
            }

            $currentDefect = (int) $execution->defect_qty;
            $newTotalDefect = $currentDefect + $adjustmentQty;

            if ($newTotalDefect < 0) {
                throw new InvalidArgumentException(
                    "Total defect baru tidak boleh negatif ({$newTotalDefect} PCS). Koreksi {$adjustmentQty} PCS ditolak."
                );
            }

            $effectiveInput = $this->quantityResolver->resolveEffectiveInputQty($item, $execution->stage);
            if ($newTotalDefect > $effectiveInput) {
                throw new InvalidArgumentException(
                    "Total defect baru ({$newTotalDefect} PCS) melebihi kuantitas input efektif ({$effectiveInput} PCS)."
                );
            }

            $maxAllowed = $this->quantityResolver->resolveMaxAllowedDefectQty($item, $execution->stage);
            if ($newTotalDefect > $maxAllowed) {
                throw new InvalidArgumentException(
                    "Total defect baru ({$newTotalDefect} PCS) melebihi sisa kapasitas kuantitas traveler ({$maxAllowed} PCS)."
                );
            }

            $now = Carbon::now();
            $execution->defect_qty = $newTotalDefect;
            $execution->good_qty = max(0, $effectiveInput - $newTotalDefect);
            $execution->defect_entered_at = $now;
            $execution->defect_entered_by = $user->id;
            // Current state is now PPIC-managed; NEVER clear `auto_nihil_at` so historical Auto-Nihil provenance stays intact
            $execution->is_auto_nihil = false;

            // Phase 4.1: defect = 0 -> NO_DEFECT (exits QC queue); defect > 0 -> WAITING_QC (enters QC queue)
            $execution->status = $newTotalDefect === 0
                ? FittingStageExecution::STATUS_NO_DEFECT
                : FittingStageExecution::STATUS_WAITING_QC;
            $execution->qc_verified_at = null;
            $execution->qc_verified_by = null;

            $logNote = $cleanNotes;
            if ($isReopened && $adjustmentQty === 0) {
                $logNote = "[KONFIRMASI TETAP] {$cleanNotes}";
            }

            if ($logNote !== null) {
                $execution->notes = $logNote;
            }
            $execution->save();

            // If defect reduced to 0 or below existing breakdown, clear inconsistent active breakdown
            if ($newTotalDefect === 0 || (int) $execution->defects()->sum('qty') > $newTotalDefect) {
                $execution->defects()->delete();
            }

            $this->createDefectLogSafe($execution, [
                'added_qty' => $adjustmentQty,
                'previous_total' => $currentDefect,
                'new_total' => $newTotalDefect,
                'user_id' => $user->id,
                'is_system_action' => false,
                'notes' => $logNote,
                'idempotency_key' => $cleanKey,
            ]);

            return $execution->fresh(['fittingItem', 'operator', 'defectEnteredBy', 'qcVerifiedBy', 'defects.defectType', 'defectLogs.user']);
        });
    }

    /**
     * Step 2C: Confirm unchanged defect quantity after QC Reopen (e.g., 5 -> CONFIRMED -> QC REOPEN -> tetap 5 -> WAITING_QC).
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws InvalidArgumentException
     */
    public function confirmUnchangedAfterReopen(
        int|FittingStageExecution $executionOrId,
        User $user,
        string $reason,
        ?string $idempotencyKey = null
    ): FittingStageExecution {
        $cleanReason = trim($reason);
        if (strlen($cleanReason) < 3) {
            throw new InvalidArgumentException('Alasan konfirmasi wajib diisi (minimal 3 karakter).');
        }

        return $this->adjustDefectQty($executionOrId, 0, $user, $cleanReason, $idempotencyKey);
    }

    /**
     * Step 3A: Admin QC Fitting saves draft defect classification breakdown (keeps WAITING_QC).
     *
     * @param  array<int, array{defect_type_id: int, qty: int, notes?: string|null}>  $defects
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws InvalidArgumentException
     */
    public function saveQcBreakdownDraft(
        int|FittingStageExecution $executionOrId,
        array $defects,
        User $user,
        ?string $notes = null,
        ?string $inspectionDate = null
    ): FittingStageExecution {
        $this->authService->authorizeVerifyQc($user);

        $cleanNotes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;
        $parsedInspDate = $this->parseInspectionDate($inspectionDate);

        return DB::transaction(function () use ($executionOrId, $defects, $cleanNotes, $parsedInspDate) {
            [$item, $execution] = $this->lockItemAndExecutions($executionOrId);

            $this->assertStageQualityNotLocked($execution, $item);

            if ($execution->status === FittingStageExecution::STATUS_CONFIRMED) {
                throw new InvalidArgumentException("Eksekusi {$execution->checkpoint_code} sudah berstatus CONFIRMED. Draft tidak dapat diubah.");
            }

            if ($execution->status !== FittingStageExecution::STATUS_WAITING_QC) {
                throw new InvalidArgumentException("Eksekusi {$execution->checkpoint_code} belum dalam status WAITING_QC (Status: {$execution->status}).");
            }

            if ((int) $execution->defect_qty <= 0) {
                throw new InvalidArgumentException('Tidak dapat menyimpan draft rincian cacat karena total defect adalah 0 PCS.');
            }

            [$validatedDefects, $totalBreakdown] = $this->validateDefectBreakdown($defects, $execution->stage);

            if ($totalBreakdown <= 0) {
                throw new InvalidArgumentException('Total kuantitas draft rincian cacat harus lebih dari 0 PCS.');
            }

            if ($totalBreakdown > (int) $execution->defect_qty) {
                throw new InvalidArgumentException(
                    "Total draft rincian cacat ({$totalBreakdown} PCS) melebihi total defect yang dicatat PPIC ({$execution->defect_qty} PCS)."
                );
            }

            $execution->defects()->delete();
            foreach ($validatedDefects as $row) {
                $execution->defects()->create($row);
            }

            if ($parsedInspDate !== null) {
                $execution->inspection_date = $parsedInspDate;
            }
            if ($cleanNotes !== null) {
                $execution->notes = $cleanNotes;
            }
            $execution->save();

            return $execution->fresh(['fittingItem', 'operator', 'defectEnteredBy', 'qcVerifiedBy', 'defects.defectType', 'defectLogs.user']);
        });
    }

    /**
     * Step 3B: Admin QC Fitting verifies defect breakdown (WAITING_QC -> CONFIRMED).
     *
     * @param  array<int, array{defect_type_id: int, qty: int, notes?: string|null}>  $defects
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws InvalidArgumentException
     */
    public function verifyQcBreakdown(
        int|FittingStageExecution $executionOrId,
        array $defects,
        User $user,
        ?string $notes = null,
        ?string $inspectionDate = null
    ): FittingStageExecution {
        $this->authService->authorizeVerifyQc($user);

        $cleanNotes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;
        $parsedInspDate = $this->parseInspectionDate($inspectionDate);

        return DB::transaction(function () use ($executionOrId, $defects, $user, $cleanNotes, $parsedInspDate) {
            [$item, $execution] = $this->lockItemAndExecutions($executionOrId);

            $this->assertStageQualityNotLocked($execution, $item);

            if ($execution->status === FittingStageExecution::STATUS_CONFIRMED) {
                throw new InvalidArgumentException("Eksekusi {$execution->checkpoint_code} sudah berstatus CONFIRMED.");
            }

            if ($execution->status !== FittingStageExecution::STATUS_WAITING_QC) {
                throw new InvalidArgumentException("Eksekusi {$execution->checkpoint_code} belum dalam status WAITING_QC (Status: {$execution->status}).");
            }

            if ((int) $execution->defect_qty <= 0) {
                throw new InvalidArgumentException('Eksekusi dengan defect 0 PCS tidak memerlukan verifikasi QC.');
            }

            [$validatedDefects, $totalBreakdown] = $this->validateDefectBreakdown($defects, $execution->stage);

            if ($totalBreakdown !== (int) $execution->defect_qty) {
                throw new InvalidArgumentException(
                    "Total klasifikasi cacat ({$totalBreakdown} PCS) harus sama persis dengan total defect ({$execution->defect_qty} PCS)."
                );
            }

            $execution->defects()->delete();
            foreach ($validatedDefects as $row) {
                $execution->defects()->create($row);
            }

            $execution->status = FittingStageExecution::STATUS_CONFIRMED;
            $execution->qc_verified_at = Carbon::now();
            $execution->qc_verified_by = $user->id;
            if ($parsedInspDate !== null) {
                $execution->inspection_date = $parsedInspDate;
            }
            if ($cleanNotes !== null) {
                $execution->notes = $cleanNotes;
            }
            $execution->save();

            return $execution->fresh(['fittingItem', 'operator', 'defectEnteredBy', 'qcVerifiedBy', 'defects.defectType', 'defectLogs.user']);
        });
    }

    /**
     * Step 3C: Admin QC Fitting returns a WAITING_QC execution back to WAITING_DEFECT for PPIC correction.
     * Permanently logs the QC return reason in `fitting_stage_execution_defect_logs` without losing audit history.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws InvalidArgumentException
     */
    public function returnForCorrection(
        int|FittingStageExecution $executionOrId,
        User $user,
        string $reason
    ): FittingStageExecution {
        $this->authService->authorizeVerifyQc($user);

        $cleanReason = trim($reason);
        if (strlen($cleanReason) < 3) {
            throw new InvalidArgumentException('Alasan pengembalian koreksi wajib diisi (minimal 3 karakter).');
        }

        return DB::transaction(function () use ($executionOrId, $user, $cleanReason) {
            [$item, $execution] = $this->lockItemAndExecutions($executionOrId);

            $this->assertStageQualityNotLocked($execution, $item);

            if ($execution->status !== FittingStageExecution::STATUS_WAITING_QC) {
                throw new InvalidArgumentException("Hanya eksekusi berstatus WAITING_QC yang dapat dikembalikan untuk koreksi (Status saat ini: {$execution->status}).");
            }

            $currentDefect = (int) $execution->defect_qty;

            $execution->defects()->delete();
            $execution->status = FittingStageExecution::STATUS_WAITING_DEFECT;
            $execution->qc_verified_at = null;
            $execution->qc_verified_by = null;
            $execution->is_auto_nihil = false;
            // Preserve historical `auto_nihil_at` provenance if ever set
            $execution->notes = "[QC RETURN] {$cleanReason}";
            $execution->save();

            $execution->defectLogs()->create([
                'added_qty' => 0,
                'previous_total' => $currentDefect,
                'new_total' => $currentDefect,
                'user_id' => $user->id,
                'is_system_action' => false,
                'notes' => "[QC RETURN] {$cleanReason}",
            ]);

            return $execution->fresh(['fittingItem', 'operator', 'defectEnteredBy', 'qcVerifiedBy', 'defects.defectType', 'defectLogs.user']);
        });
    }

    /**
     * Step 3D (Phase 4.1): Admin QC Fitting reopens a CONFIRMED execution for two-party correction.
     * - Strictly requires mandatory reason and respects Final Quality Lock.
     * - NEVER mutates PPIC's `defect_qty` or `good_qty`.
     * - Permanently archives previous QC verification metadata & full breakdown into `qc_snapshot_json`.
     * - Transitions status CONFIRMED -> REOPENED_FOR_CORRECTION so Admin PPIC can adjust or confirm unchanged.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws InvalidArgumentException
     */
    public function reopenConfirmedForCorrection(
        int|FittingStageExecution $executionOrId,
        User $user,
        string $reason
    ): FittingStageExecution {
        $this->authService->authorizeVerifyQc($user);

        $cleanReason = trim($reason);
        if (strlen($cleanReason) < 3) {
            throw new InvalidArgumentException('Alasan pembukaan kembali verifikasi QC wajib diisi (minimal 3 karakter).');
        }

        return DB::transaction(function () use ($executionOrId, $user, $cleanReason) {
            [$item, $execution] = $this->lockItemAndExecutions($executionOrId);

            $this->assertStageQualityNotLocked($execution, $item);

            if ($execution->status !== FittingStageExecution::STATUS_CONFIRMED) {
                throw new InvalidArgumentException("Hanya eksekusi berstatus CONFIRMED yang dapat dibuka kembali (Reopen) oleh QC (Status saat ini: {$execution->status}).");
            }

            $execution->load(['qcVerifiedBy', 'defects.defectType']);
            $now = Carbon::now();
            $currentDefect = (int) $execution->defect_qty;

            $previousDefects = $execution->defects->map(fn ($d) => [
                'defect_type_id' => (int) $d->defect_type_id,
                'defect_type_name' => $d->defectType?->name ?? 'Unknown',
                'qty' => (int) $d->qty,
                'notes' => $d->notes,
            ])->values()->all();

            $qcSnapshot = [
                'action' => 'QC_REOPEN',
                'reopened_at' => $now->toIso8601String(),
                'reopened_by' => $user->id,
                'reopened_by_name' => $user->name,
                'reopen_reason' => $cleanReason,
                'previous_status' => $execution->status,
                'previous_defect_qty' => $currentDefect,
                'previous_good_qty' => (int) $execution->good_qty,
                'previous_qc_verified_by' => $execution->qc_verified_by,
                'previous_qc_verified_by_name' => $execution->qcVerifiedBy?->name,
                'previous_qc_verified_at' => $execution->qc_verified_at?->toIso8601String(),
                'previous_inspection_date' => $execution->inspection_date?->toDateString(),
                'previous_defects' => $previousDefects,
            ];

            // Transition CONFIRMED -> REOPENED_FOR_CORRECTION; NEVER modify PPIC's defect_qty or good_qty
            $execution->status = FittingStageExecution::STATUS_REOPENED_FOR_CORRECTION;
            $execution->qc_verified_at = null;
            $execution->qc_verified_by = null;
            $execution->notes = "[QC REOPEN] {$cleanReason}";
            $execution->save();

            $execution->defectLogs()->create([
                'added_qty' => 0,
                'previous_total' => $currentDefect,
                'new_total' => $currentDefect,
                'user_id' => $user->id,
                'is_system_action' => false,
                'notes' => "[QC REOPEN] {$cleanReason}",
                'qc_snapshot_json' => $qcSnapshot,
            ]);

            return $execution->fresh(['fittingItem', 'operator', 'defectEnteredBy', 'qcVerifiedBy', 'defects.defectType', 'defectLogs.user']);
        });
    }

    /**
     * Step 5 (System Auto-Nihil): Apply 0 PCS defect automatically after 5 calendar days timeout.
     * Phase 4.1 rules:
     * - Transitions WAITING_DEFECT -> NO_DEFECT (NEVER enters QC queue; NOT a QC verification).
     * - Sets `is_auto_nihil = true` and permanent `auto_nihil_at = now()`.
     * - Strictly idempotent, atomic under canonical lock order, respects Final Quality Lock, never alters traveler stage/status.
     *
     * @throws InvalidArgumentException
     */
    public function timeoutToAutoNihil(
        int|FittingStageExecution $executionOrId,
        ?int $systemActorId = null,
        ?string $notes = null,
        int $timeoutDays = 5
    ): FittingStageExecution {
        $effectiveDays = $timeoutDays > 0 ? $timeoutDays : 5;

        return DB::transaction(function () use ($executionOrId, $systemActorId, $notes, $effectiveDays) {
            [$item, $execution] = $this->lockItemAndExecutions($executionOrId);

            // Idempotent return if already Auto-Nihil
            if ($execution->is_auto_nihil && $execution->status === FittingStageExecution::STATUS_NO_DEFECT && $execution->auto_nihil_at !== null) {
                return $execution->load(['fittingItem', 'operator', 'defectLogs.user']);
            }

            if ($execution->auto_nihil_at !== null) {
                throw new InvalidArgumentException("Eksekusi {$execution->checkpoint_code} sudah pernah diproses Auto-Nihil sebelumnya pada {$execution->auto_nihil_at->format('Y-m-d H:i:s')}.");
            }

            if ($item->status !== FittingItem::STATUS_ACTIVE) {
                throw new InvalidArgumentException("Traveler [{$item->traveler_number}] tidak berstatus ACTIVE sehingga Auto-Nihil dilewati.");
            }

            // Respect Final Quality Lock (never mutate locked stages 1..5 after Gudang Jadi PF)
            $this->assertStageQualityNotLocked($execution, $item);

            if ($execution->status !== FittingStageExecution::STATUS_WAITING_DEFECT) {
                throw new InvalidArgumentException(
                    "Eksekusi {$execution->checkpoint_code} sudah tidak dalam status WAITING_DEFECT (Status: {$execution->status})."
                );
            }

            if ($execution->physical_done_at === null) {
                throw new InvalidArgumentException("Eksekusi {$execution->checkpoint_code} tidak memiliki physical_done_at yang valid.");
            }

            $cutoff = Carbon::now()->subDays($effectiveDays);
            if ($execution->physical_done_at->gt($cutoff)) {
                throw new InvalidArgumentException(
                    "Eksekusi {$execution->checkpoint_code} belum melewati batas {$effectiveDays} hari kalender (selesai fisik: {$execution->physical_done_at->format('Y-m-d H:i:s')})."
                );
            }

            $now = Carbon::now();
            $effectiveInput = $this->quantityResolver->resolveEffectiveInputQty($item, $execution->stage);
            $logNote = $notes !== null && trim($notes) !== ''
                ? trim($notes)
                : "Penetapan Nihil Otomatis (Timeout {$effectiveDays} Hari Kalender)";

            $execution->defect_qty = 0;
            $execution->good_qty = $effectiveInput;
            $execution->defect_entered_at = $now;
            $execution->defect_entered_by = $systemActorId;
            $execution->status = FittingStageExecution::STATUS_NO_DEFECT;
            $execution->is_auto_nihil = true;
            $execution->auto_nihil_at = $now;
            $execution->notes = $logNote;
            $execution->save();

            $execution->defectLogs()->create([
                'added_qty' => 0,
                'previous_total' => 0,
                'new_total' => 0,
                'user_id' => $systemActorId,
                'is_system_action' => true,
                'notes' => $logNote,
            ]);

            return $execution->fresh(['fittingItem', 'operator', 'defectLogs.user']);
        });
    }

    /**
     * Final Quality Lock Guard:
     * Once a FittingItem has completed physical scan at Gudang Jadi PF (`current_stage === 'completed'`
     * OR `PF_GUDANG_JADI` execution exists with `physical_done_at IS NOT NULL`),
     * stages 1..5 (`netto_pf`, `kimia`, `marking`, `bubut_cnc_pf`, `qc_pf`) are permanently LOCKED.
     * Stage 6 (`gudang_jadi_pf`) remains governed by its own defect/QC authorization & audit trail.
     *
     * @throws InvalidArgumentException
     */
    public function assertStageQualityNotLocked(FittingStageExecution $execution, ?FittingItem $item = null): void
    {
        $canonicalStage = FittingStageAuthorizationService::normalizeStage($execution->stage);

        // Stage 6 (gudang_jadi_pf) is not blocked by Final Quality Lock
        if ($canonicalStage === FittingItem::STAGE_GUDANG_JADI_PF) {
            return;
        }

        $parentItem = $item ?? $execution->fittingItem;
        if (! $parentItem) {
            throw new InvalidArgumentException("Data traveler Fitting induk tidak ditemukan untuk eksekusi ID {$execution->id}. Mutasi ditolak (Fail-Closed).");
        }

        $hasGudangJadiDone = $parentItem->relationLoaded('stageExecutions')
            ? $parentItem->stageExecutions->contains(
                fn ($se) => $se->checkpoint_code === FittingStageExecution::CHECKPOINT_GUDANG_JADI && $se->physical_done_at !== null
            )
            : $parentItem->stageExecutions()
                ->where('checkpoint_code', FittingStageExecution::CHECKPOINT_GUDANG_JADI)
                ->whereNotNull('physical_done_at')
                ->exists();

        if ($parentItem->current_stage === FittingItem::STAGE_COMPLETED || $hasGudangJadiDone) {
            $stageLabel = FittingStageAuthorizationService::STAGE_LABELS[$canonicalStage] ?? strtoupper((string) $execution->stage);
            throw new InvalidArgumentException(
                "Tahap {$stageLabel} pada [{$parentItem->traveler_number}] telah TERKUNCI oleh Final Quality Lock karena tahap Gudang Jadi PF sudah selesai dipindai. Koreksi hanya diperbolehkan pada tahap GUDANG JADI PF."
            );
        }
    }

    /**
     * Check boolean lock state for UI badges / read-only indicators.
     */
    public function isStageQualityLocked(FittingStageExecution $execution, ?FittingItem $item = null): bool
    {
        try {
            $this->assertStageQualityNotLocked($execution, $item);

            return false;
        } catch (InvalidArgumentException) {
            return true;
        }
    }

    /**
     * Acquire locks strictly in canonical hierarchy:
     *   1. fitting_items (parent)
     *   2. fitting_stage_executions (all executions of parent ORDER BY id ASC)
     *
     * @return array{0: FittingItem, 1: FittingStageExecution}
     */
    protected function lockItemAndExecutions(int|FittingStageExecution $executionOrId): array
    {
        $executionId = $executionOrId instanceof FittingStageExecution ? $executionOrId->id : (int) $executionOrId;

        // Pre-read immutable FK fitting_item_id without lock so parent item is locked FIRST
        $fittingItemId = $executionOrId instanceof FittingStageExecution
            ? (int) $executionOrId->fitting_item_id
            : (int) FittingStageExecution::where('id', $executionId)->value('fitting_item_id');

        if ($fittingItemId <= 0) {
            throw new InvalidArgumentException("Eksekusi tahap Fitting ID {$executionId} tidak ditemukan.");
        }

        // 1. Lock parent FittingItem FIRST
        $item = FittingItem::where('id', $fittingItemId)
            ->lockForUpdate()
            ->first();

        if (! $item) {
            throw new InvalidArgumentException("Traveler Fitting induk ID {$fittingItemId} tidak ditemukan.");
        }

        // 2. Lock ALL stage executions of this traveler SECOND in deterministic ORDER BY id ASC
        $executions = FittingStageExecution::where('fitting_item_id', $item->id)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();

        $item->setRelation('stageExecutions', $executions);

        /** @var FittingStageExecution|null $targetExecution */
        $targetExecution = $executions->firstWhere('id', $executionId);
        if (! $targetExecution) {
            throw new InvalidArgumentException("Eksekusi tahap Fitting ID {$executionId} tidak ditemukan.");
        }

        return [$item, $targetExecution];
    }

    /**
     * Validate breakdown array against master `defect_types`.
     *
     * @return array{0: list<array{defect_type_id: int, qty: int, notes: string|null}>, 1: int}
     */
    protected function validateDefectBreakdown(array $defects, string $stage): array
    {
        if (empty($defects)) {
            throw new InvalidArgumentException('Rincian jenis kerusakan (defects) wajib diisi.');
        }

        $validated = [];
        $total = 0;

        foreach ($defects as $row) {
            $qty = (int) ($row['qty'] ?? 0);
            if ($qty <= 0) {
                throw new InvalidArgumentException('Kuantitas setiap jenis kerusakan harus lebih dari 0 PCS.');
            }

            $typeId = (int) ($row['defect_type_id'] ?? 0);
            $defectType = DefectType::find($typeId);
            if (! $defectType) {
                throw new InvalidArgumentException("Master jenis kerusakan dengan ID {$typeId} tidak ditemukan.");
            }

            if (! $defectType->is_active) {
                throw new InvalidArgumentException("Master jenis kerusakan '{$defectType->name}' sudah nonaktif dan tidak dapat dipilih.");
            }

            if ($defectType->department !== 'fitting' && ! in_array($defectType->department, \App\Models\FittingItem::STAGES, true)) {
                throw new InvalidArgumentException("Master jenis kerusakan '{$defectType->name}' bukan jenis kerusakan resmi Fitting.");
            }

            $validated[] = [
                'defect_type_id' => $typeId,
                'qty' => $qty,
                'notes' => isset($row['notes']) && trim((string) $row['notes']) !== '' ? trim((string) $row['notes']) : null,
            ];
            $total += $qty;
        }

        return [$validated, $total];
    }

    protected function parseInspectionDate(?string $inspectionDate): ?string
    {
        if ($inspectionDate === null || trim($inspectionDate) === '') {
            return null;
        }

        $parsed = Carbon::parse($inspectionDate)->startOfDay();
        if ($parsed->isFuture()) {
            throw new InvalidArgumentException('Tanggal pemeriksaan defect tidak boleh di masa depan.');
        }

        return $parsed->toDateString();
    }

    protected function createDefectLogSafe(FittingStageExecution $execution, array $payload): void
    {
        try {
            $execution->defectLogs()->create($payload);
        } catch (QueryException $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'uk_fit_def_log_idempotency') || str_contains($msg, 'idempotency_key')) {
                throw new InvalidArgumentException('Konflik idempotency key: permintaan penyesuaian defect telah diproses secara bersamaan.', 0, $e);
            }
            throw $e;
        }
    }
}
