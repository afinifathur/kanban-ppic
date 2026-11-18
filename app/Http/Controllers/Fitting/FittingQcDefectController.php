<?php

namespace App\Http\Controllers\Fitting;

use App\Http\Controllers\Controller;
use App\Models\FittingItem;
use App\Models\FittingStageExecution;
use App\Services\Fitting\FittingDefectService;
use App\Services\Fitting\FittingQuantityResolverService;
use App\Services\Fitting\FittingStageAuthorizationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

class FittingQcDefectController extends Controller
{
    public function __construct(
        protected FittingDefectService $defectService,
        protected FittingQuantityResolverService $quantityResolver,
        protected FittingStageAuthorizationService $authorizationService
    ) {}

    /**
     * Render Fitting Production QC Defect Verification Dashboard (Admin QC Fitting + System Admin).
     */
    public function index(Request $request): View
    {
        $user = auth()->user();

        try {
            $this->authorizationService->authorizeVerifyQc($user);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $rawMode = (string) $request->query('mode', 'waiting');
        $mode = match ($rawMode) {
            'confirmed', 'recorded' => 'confirmed',
            default => 'waiting',
        };

        $rawStage = $request->query('stage');
        $selectedStage = null;
        if ($rawStage !== null && $rawStage !== '' && $rawStage !== 'all') {
            $selectedStage = FittingStageAuthorizationService::normalizeStage((string) $rawStage);
            if ($selectedStage === null) {
                abort(404, "Tahap operasional Fitting '{$rawStage}' tidak valid.");
            }
        }

        $search = trim((string) $request->query('search', ''));
        $draftStatus = (string) $request->query('draft_status', 'all');
        if (! in_array($draftStatus, ['all', 'has_draft', 'no_draft'], true)) {
            $draftStatus = 'all';
        }

        $baseActiveQuery = fn () => \App\Models\FittingStageExecution::query()
            ->where('defect_qty', '>', 0) // Phase 4.1: defect 0 (manual & Auto-Nihil) NEVER enters QC queue
            ->whereHas('fittingItem', fn ($q) => $q->where('status', FittingItem::STATUS_ACTIVE));

        $waitingCounts = $baseActiveQuery()
            ->where('status', FittingStageExecution::STATUS_WAITING_QC)
            ->selectRaw('stage, COUNT(*) as cnt')
            ->groupBy('stage')
            ->pluck('cnt', 'stage')
            ->all();

        $confirmedCounts = $baseActiveQuery()
            ->where('status', FittingStageExecution::STATUS_CONFIRMED)
            ->selectRaw('stage, COUNT(*) as cnt')
            ->groupBy('stage')
            ->pluck('cnt', 'stage')
            ->all();

        $reopenedTotal = (int) $baseActiveQuery()
            ->where('status', FittingStageExecution::STATUS_REOPENED_FOR_CORRECTION)
            ->count();

        $summary = [
            'waiting_total' => (int) array_sum($waitingCounts),
            'confirmed_total' => (int) array_sum($confirmedCounts),
            'reopened_total' => $reopenedTotal,
            'per_stage_waiting' => $waitingCounts,
            'per_stage_confirmed' => $confirmedCounts,
        ];

        $query = $baseActiveQuery()
            ->with([
                'fittingItem.stageExecutions',
                'fittingItem.transfer',
                'operator',
                'defectEnteredBy',
                'qcVerifiedBy',
                'defects.defectType',
                'defectLogs.user',
            ]);

        if ($mode === 'waiting') {
            $query->where('status', FittingStageExecution::STATUS_WAITING_QC)
                ->orderBy('defect_entered_at', 'asc')
                ->orderBy('id', 'asc');
        } else {
            $query->where('status', FittingStageExecution::STATUS_CONFIRMED)
                ->orderByDesc('qc_verified_at')
                ->orderByDesc('id');
        }

        if ($selectedStage !== null) {
            $query->where('stage', $selectedStage);
        }

        if ($draftStatus === 'has_draft') {
            $query->whereHas('defects');
        } elseif ($draftStatus === 'no_draft') {
            $query->whereDoesntHave('defects');
        }

        if ($search !== '') {
            $query->whereHas('fittingItem', function ($q) use ($search) {
                $q->where('traveler_number', 'like', "%{$search}%")
                    ->orWhere('heat_number', 'like', "%{$search}%")
                    ->orWhere('production_code', 'like', "%{$search}%")
                    ->orWhere('item_code', 'like', "%{$search}%")
                    ->orWhere('item_name', 'like', "%{$search}%")
                    ->orWhere('customer', 'like', "%{$search}%");
            });
        }

        $executions = $query->paginate(25)->appends($request->query());

        $executions->getCollection()->transform(function (FittingStageExecution $exec) {
            $item = $exec->fittingItem;
            $exec->setAttribute('effective_input_qty', $item ? $this->quantityResolver->resolveEffectiveInputQty($item, $exec->stage) : (int) $exec->input_qty);
            $exec->setAttribute('effective_good_qty', $item ? $this->quantityResolver->resolveEffectiveGoodQty($item, $exec->stage) : (int) $exec->good_qty);
            $exec->setAttribute('is_quality_locked', $item ? $this->defectService->isStageQualityLocked($exec, $item) : false);

            return $exec;
        });

        // Master defect types per Fitting stage (active only; stage-specific prioritized, fallback to all Fitting departments)
        $allActiveFittingDefects = \App\Models\DefectType::active()
            ->whereIn('department', FittingItem::STAGES)
            ->orderBy('department')
            ->orderBy('name')
            ->get();

        $defectTypesMap = [];
        foreach (FittingItem::STAGES as $stageKey) {
            $stageSpecific = $allActiveFittingDefects->where('department', $stageKey)->values();
            $list = $stageSpecific->isNotEmpty() ? $stageSpecific : $allActiveFittingDefects->values();

            $defectTypesMap[$stageKey] = $list->map(fn ($dt) => [
                'id' => (int) $dt->id,
                'name' => $dt->name,
                'department' => $dt->department,
            ])->all();
        }

        return view('fitting.qc-defects.index', [
            'summary' => $summary,
            'executions' => $executions,
            'mode' => $mode,
            'selectedStage' => $selectedStage,
            'stages' => FittingItem::STAGES,
            'stageLabels' => FittingStageAuthorizationService::STAGE_LABELS,
            'search' => $search,
            'draftStatus' => $draftStatus,
            'defectTypesMap' => $defectTypesMap,
        ]);
    }

    /**
     * Save draft QC defect classification breakdown (keeps WAITING_QC).
     */
    public function saveDraft(Request $request, FittingStageExecution $execution): JsonResponse|RedirectResponse
    {
        $user = auth()->user();

        try {
            $this->authorizationService->authorizeVerifyQc($user);

            $validated = $request->validate([
                'inspection_date' => 'nullable|date|before_or_equal:today',
                'defects' => 'required|array|min:1',
                'defects.*.defect_type_id' => 'required|integer|exists:defect_types,id',
                'defects.*.qty' => 'required|integer|min:1',
                'defects.*.notes' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:500',
            ], [
                'inspection_date.date' => 'Format tanggal pemeriksaan tidak valid.',
                'inspection_date.before_or_equal' => 'Tanggal pemeriksaan tidak boleh di masa depan.',
                'defects.required' => 'Rincian jenis kerusakan wajib diisi untuk menyimpan draft.',
                'defects.min' => 'Rincian jenis kerusakan minimal 1 baris.',
                'defects.*.defect_type_id.required' => 'Jenis kerusakan wajib dipilih.',
                'defects.*.defect_type_id.exists' => 'Jenis kerusakan yang dipilih tidak ditemukan di master.',
                'defects.*.qty.required' => 'Kuantitas kerusakan wajib diisi.',
                'defects.*.qty.min' => 'Kuantitas kerusakan minimal 1 PCS.',
            ]);

            $updated = $this->defectService->saveQcBreakdownDraft(
                executionOrId: $execution,
                defects: $validated['defects'],
                user: $user,
                notes: $validated['notes'] ?? null,
                inspectionDate: $validated['inspection_date'] ?? null
            );

            $pfktr = $updated->fittingItem?->traveler_number ?? 'PFKTR';
            $draftTotal = (int) $updated->defects->sum('qty');
            $message = "Draft rincian defect {$pfktr} berhasil disimpan ({$draftTotal}/{$updated->defect_qty} PCS).";

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'data' => [
                        'execution_id' => $updated->id,
                        'traveler_number' => $pfktr,
                        'stage' => $updated->stage,
                        'status' => $updated->status,
                        'defect_qty' => (int) $updated->defect_qty,
                        'draft_qty' => $draftTotal,
                        'defects' => $updated->defects->map(fn ($d) => [
                            'id' => $d->id,
                            'defect_type_id' => $d->defect_type_id,
                            'defect_name' => $d->defectType?->name,
                            'qty' => (int) $d->qty,
                            'notes' => $d->notes,
                        ])->values()->all(),
                    ],
                ]);
            }

            return redirect()
                ->route('fitting.qc-defects.index', ['mode' => 'waiting', 'stage' => $updated->stage])
                ->with('success', $message);
        } catch (AuthorizationException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
            }
            abort(403, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->withInput()->with('error', $e->getMessage());
        } catch (ValidationException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi data gagal.',
                    'errors' => $e->errors(),
                ], 422);
            }

            return back()->withInput()->withErrors($e->validator);
        } catch (Throwable $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => 'Terjadi kesalahan sistem saat menyimpan draft QC Fitting.'], 500);
            }

            return back()->withInput()->with('error', 'Terjadi kesalahan sistem: '.$e->getMessage());
        }
    }

    /**
     * Verify & confirm defect classification breakdown (WAITING_QC -> CONFIRMED).
     */
    public function verify(Request $request, FittingStageExecution $execution): JsonResponse|RedirectResponse
    {
        $user = auth()->user();

        try {
            $this->authorizationService->authorizeVerifyQc($user);

            $validated = $request->validate([
                'inspection_date' => 'nullable|date|before_or_equal:today',
                'defects' => 'nullable|array',
                'defects.*.defect_type_id' => 'required_with:defects|integer|exists:defect_types,id',
                'defects.*.qty' => 'required_with:defects|integer|min:1',
                'defects.*.notes' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:500',
            ], [
                'inspection_date.date' => 'Format tanggal pemeriksaan tidak valid.',
                'inspection_date.before_or_equal' => 'Tanggal pemeriksaan tidak boleh di masa depan.',
                'defects.*.defect_type_id.required_with' => 'Jenis kerusakan wajib dipilih.',
                'defects.*.defect_type_id.exists' => 'Jenis kerusakan yang dipilih tidak valid.',
                'defects.*.qty.required_with' => 'Kuantitas kerusakan wajib diisi.',
                'defects.*.qty.min' => 'Kuantitas kerusakan minimal 1 PCS.',
            ]);

            $confirmed = $this->defectService->verifyQcBreakdown(
                executionOrId: $execution,
                defects: $validated['defects'] ?? [],
                user: $user,
                notes: $validated['notes'] ?? null,
                inspectionDate: $validated['inspection_date'] ?? null
            );

            $pfktr = $confirmed->fittingItem?->traveler_number ?? 'PFKTR';
            $message = "Verifikasi QC {$pfktr} berhasil dikonfirmasi. Status: CONFIRMED.";

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'data' => [
                        'execution_id' => $confirmed->id,
                        'traveler_number' => $pfktr,
                        'stage' => $confirmed->stage,
                        'checkpoint_code' => $confirmed->checkpoint_code,
                        'defect_qty' => (int) $confirmed->defect_qty,
                        'good_qty' => (int) $confirmed->good_qty,
                        'status' => $confirmed->status,
                        'qc_verified_at' => $confirmed->qc_verified_at?->toIso8601String(),
                        'qc_verified_by' => $confirmed->qc_verified_by,
                    ],
                ]);
            }

            return redirect()
                ->route('fitting.qc-defects.index', ['mode' => 'waiting', 'stage' => $confirmed->stage])
                ->with('success', $message);
        } catch (AuthorizationException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
            }
            abort(403, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->withInput()->with('error', $e->getMessage());
        } catch (ValidationException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi data gagal.',
                    'errors' => $e->errors(),
                ], 422);
            }

            return back()->withInput()->withErrors($e->validator);
        } catch (Throwable $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => 'Terjadi kesalahan sistem saat verifikasi QC Fitting.'], 500);
            }

            return back()->withInput()->with('error', 'Terjadi kesalahan sistem: '.$e->getMessage());
        }
    }

    /**
     * Return a WAITING_QC execution back to WAITING_DEFECT for PPIC correction.
     */
    public function returnForCorrection(Request $request, FittingStageExecution $execution): JsonResponse|RedirectResponse
    {
        $user = auth()->user();

        try {
            $this->authorizationService->authorizeVerifyQc($user);

            $validated = $request->validate([
                'reason' => 'required|string|min:3|max:500',
            ], [
                'reason.required' => 'Alasan pengembalian untuk koreksi wajib diisi.',
                'reason.min' => 'Alasan pengembalian minimal 3 karakter.',
            ]);

            $returned = $this->defectService->returnForCorrection(
                executionOrId: $execution,
                user: $user,
                reason: $validated['reason']
            );

            $pfktr = $returned->fittingItem?->traveler_number ?? 'PFKTR';
            $message = "Eksekusi {$pfktr} dikembalikan ke Admin PPIC (WAITING_DEFECT) untuk koreksi.";

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'data' => [
                        'execution_id' => $returned->id,
                        'traveler_number' => $pfktr,
                        'stage' => $returned->stage,
                        'status' => $returned->status,
                        'notes' => $returned->notes,
                    ],
                ]);
            }

            return redirect()
                ->route('fitting.qc-defects.index', ['mode' => 'waiting', 'stage' => $returned->stage])
                ->with('success', $message);
        } catch (AuthorizationException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
            }
            abort(403, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->withInput()->with('error', $e->getMessage());
        } catch (ValidationException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi data gagal.',
                    'errors' => $e->errors(),
                ], 422);
            }

            return back()->withInput()->withErrors($e->validator);
        } catch (Throwable $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => 'Terjadi kesalahan sistem saat mengembalikan data ke PPIC.'], 500);
            }

            return back()->withInput()->with('error', 'Terjadi kesalahan sistem: '.$e->getMessage());
        }
    }

    /**
     * Reopen a CONFIRMED execution for two-party correction (Admin QC Fitting + System Admin).
     * Archives previous QC verification snapshot permanently without altering PPIC defect_qty.
     */
    public function reopen(Request $request, FittingStageExecution $execution): JsonResponse|RedirectResponse
    {
        $user = auth()->user();

        try {
            $this->authorizationService->authorizeVerifyQc($user);

            $validated = $request->validate([
                'reason' => 'required|string|min:3|max:1000',
            ], [
                'reason.required' => 'Alasan pembukaan kembali verifikasi QC wajib diisi.',
                'reason.min' => 'Alasan pembukaan kembali verifikasi QC minimal 3 karakter.',
            ]);

            $reopened = $this->defectService->reopenConfirmedForCorrection(
                executionOrId: $execution,
                user: $user,
                reason: $validated['reason']
            );

            $pfktr = $reopened->fittingItem?->traveler_number ?? 'PFKTR';
            $message = "Verifikasi QC {$pfktr} berhasil dibuka kembali (REOPENED_FOR_CORRECTION) untuk ditindaklanjuti Admin PPIC. Histori verifikasi sebelumnya telah diarsipkan.";

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'data' => [
                        'execution_id' => $reopened->id,
                        'traveler_number' => $pfktr,
                        'stage' => $reopened->stage,
                        'defect_qty' => (int) $reopened->defect_qty,
                        'status' => $reopened->status,
                        'notes' => $reopened->notes,
                    ],
                ]);
            }

            return redirect()
                ->route('fitting.qc-defects.index', ['mode' => 'confirmed', 'stage' => $reopened->stage])
                ->with('success', $message);
        } catch (AuthorizationException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
            }
            abort(403, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->withInput()->with('error', $e->getMessage());
        } catch (ValidationException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi data gagal.',
                    'errors' => $e->errors(),
                ], 422);
            }

            return back()->withInput()->withErrors($e->validator);
        } catch (Throwable $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => 'Terjadi kesalahan sistem saat membuka kembali verifikasi QC.'], 500);
            }

            return back()->withInput()->with('error', 'Terjadi kesalahan sistem: '.$e->getMessage());
        }
    }
}
