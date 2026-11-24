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

class FittingDefectController extends Controller
{
    public function __construct(
        protected FittingDefectService $defectService,
        protected FittingQuantityResolverService $quantityResolver,
        protected FittingStageAuthorizationService $authorizationService
    ) {}

    /**
     * Render Fitting Production Defect Recording Dashboard (Admin PPIC Fitting + System Admin).
     */
    public function index(Request $request): View
    {
        $user = auth()->user();

        try {
            $this->authorizationService->authorizeRecordDefect($user);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $mode = (string) $request->query('mode', 'unrecorded');
        if (! in_array($mode, ['unrecorded', 'recorded'], true)) {
            $mode = 'unrecorded';
        }

        $rawStage = $request->query('stage');
        $selectedStage = null;
        $stageError = null;

        if ($rawStage !== null && $rawStage !== '') {
            $selectedStage = FittingStageAuthorizationService::normalizeStage((string) $rawStage);
            if ($selectedStage === null) {
                $stageError = "Tahap operasional Fitting '{$rawStage}' tidak valid. Silakan pilih tahap produksi yang tersedia di bawah ini.";
            }
        }

        $search = trim((string) $request->query('search', ''));

        $baseActiveQuery = fn () => \App\Models\FittingStageExecution::query()
            ->whereHas('fittingItem', fn ($q) => $q->where('status', FittingItem::STATUS_ACTIVE));

        $unrecordedCounts = $baseActiveQuery()
            ->where('status', FittingStageExecution::STATUS_WAITING_DEFECT)
            ->selectRaw('stage, COUNT(*) as cnt')
            ->groupBy('stage')
            ->pluck('cnt', 'stage')
            ->all();

        $recordedStatuses = [
            FittingStageExecution::STATUS_NO_DEFECT,
            FittingStageExecution::STATUS_WAITING_QC,
            FittingStageExecution::STATUS_REOPENED_FOR_CORRECTION,
            FittingStageExecution::STATUS_CONFIRMED,
        ];

        $recordedCounts = $baseActiveQuery()
            ->whereIn('status', $recordedStatuses)
            ->selectRaw('stage, COUNT(*) as cnt')
            ->groupBy('stage')
            ->pluck('cnt', 'stage')
            ->all();

        // Scope 5 KPI totals specifically to selectedStage if present, or overall aggregate if on initial stage selector
        $scopeQuery = fn () => $baseActiveQuery()
            ->when($selectedStage !== null, fn ($q) => $q->where('stage', $selectedStage));

        $summary = [
            'unrecorded_total' => $selectedStage !== null
                ? (int) ($unrecordedCounts[$selectedStage] ?? 0)
                : (int) array_sum($unrecordedCounts),
            'recorded_total' => $selectedStage !== null
                ? (int) ($recordedCounts[$selectedStage] ?? 0)
                : (int) array_sum($recordedCounts),
            'no_defect_total' => (int) $scopeQuery()->where('status', FittingStageExecution::STATUS_NO_DEFECT)->count(),
            'waiting_qc_total' => (int) $scopeQuery()->where('status', FittingStageExecution::STATUS_WAITING_QC)->where('defect_qty', '>', 0)->count(),
            'reopened_total' => (int) $scopeQuery()->where('status', FittingStageExecution::STATUS_REOPENED_FOR_CORRECTION)->count(),
            'confirmed_total' => (int) $scopeQuery()->where('status', FittingStageExecution::STATUS_CONFIRMED)->count(),
            'auto_nihil_total' => (int) $scopeQuery()->where('status', FittingStageExecution::STATUS_NO_DEFECT)->where('is_auto_nihil', true)->count(),
            'per_stage_unrecorded' => $unrecordedCounts,
            'per_stage_recorded' => $recordedCounts,
        ];

        if ($selectedStage === null) {
            // Explicit Stage Selection: Do NOT query or mix travelers across all stages
            $executions = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25, 1, [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);
        } else {
            $query = $baseActiveQuery()
                ->where('stage', $selectedStage)
                ->with([
                    'fittingItem.stageExecutions',
                    'fittingItem.transfer',
                    'operator',
                    'defectEnteredBy',
                    'qcVerifiedBy',
                    'defects.defectType',
                    'defectLogs.user',
                ]);

            if ($mode === 'unrecorded') {
                $query->where('status', FittingStageExecution::STATUS_WAITING_DEFECT)
                    ->orderBy('physical_done_at', 'asc')
                    ->orderBy('id', 'asc');
            } else {
                $query->whereIn('status', $recordedStatuses)
                    ->orderByRaw("CASE WHEN status = 'REOPENED_FOR_CORRECTION' THEN 0 ELSE 1 END")
                    ->orderByDesc('defect_entered_at')
                    ->orderByDesc('id');
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

            // Enrich each paginated execution with dynamic effective quantities & Final Quality Lock state
            $executions->getCollection()->transform(function (FittingStageExecution $exec) {
                $item = $exec->fittingItem;
                $exec->setAttribute('effective_input_qty', $item ? $this->quantityResolver->resolveEffectiveInputQty($item, $exec->stage) : (int) $exec->input_qty);
                $exec->setAttribute('effective_good_qty', $item ? $this->quantityResolver->resolveEffectiveGoodQty($item, $exec->stage) : (int) $exec->good_qty);
                $exec->setAttribute('max_allowed_defect_qty', $item ? $this->quantityResolver->resolveMaxAllowedDefectQty($item, $exec->stage) : (int) $exec->input_qty);
                $exec->setAttribute('is_quality_locked', $item ? $this->defectService->isStageQualityLocked($exec, $item) : false);

                return $exec;
            });
        }

        return view('fitting.defects.index', [
            'summary' => $summary,
            'executions' => $executions,
            'mode' => $mode,
            'selectedStage' => $selectedStage,
            'stageError' => $stageError,
            'stages' => FittingItem::STAGES,
            'stageLabels' => FittingStageAuthorizationService::STAGE_LABELS,
            'search' => $search,
        ]);
    }

    /**
     * Record initial defect quantity for a WAITING_DEFECT stage execution.
     */
    public function record(Request $request, FittingStageExecution $execution): JsonResponse|RedirectResponse
    {
        $user = auth()->user();

        try {
            $this->authorizationService->authorizeRecordDefect($user);

            $validated = $request->validate([
                'defect_qty' => 'required|integer|min:0',
                'notes' => 'nullable|string|max:500',
                'inspection_date' => 'nullable|date|before_or_equal:today',
                'idempotency_key' => 'nullable|string|max:64',
                'stage' => [
                    'nullable',
                    'string',
                    function ($attribute, $value, $fail) use ($execution) {
                        $canonical = FittingStageAuthorizationService::normalizeStage((string) $value);
                        if ($canonical === null) {
                            $fail("Tahap operasional '{$value}' tidak valid.");

                            return;
                        }
                        if ($canonical !== $execution->stage) {
                            $fail("Tahap '{$value}' tidak sesuai dengan tahap eksekusi PFKTR ({$execution->stage}).");
                        }
                    },
                ],
            ], [
                'defect_qty.required' => 'Jumlah rusak (defect) wajib diisi.',
                'defect_qty.integer' => 'Jumlah rusak harus berupa angka bulat.',
                'defect_qty.min' => 'Jumlah rusak tidak boleh bernilai negatif.',
                'inspection_date.date' => 'Format tanggal pemeriksaan tidak valid.',
                'inspection_date.before_or_equal' => 'Tanggal pemeriksaan tidak boleh di masa depan.',
            ]);

            $idempotencyKey = $request->header('X-Idempotency-Key') ?? ($validated['idempotency_key'] ?? null);

            $inspectionDate = ! empty($validated['inspection_date'])
                ? $validated['inspection_date']
                : $execution->resolveDefaultInspectionDate();

            $updated = $this->defectService->recordDefectQty(
                executionOrId: $execution,
                defectQty: (int) $validated['defect_qty'],
                user: $user,
                notes: $validated['notes'] ?? null,
                inspectionDate: $inspectionDate,
                idempotencyKey: $idempotencyKey
            );

            $pfktr = $updated->fittingItem?->traveler_number ?? 'PFKTR';
            $stageLabel = FittingStageAuthorizationService::STAGE_LABELS[$updated->stage] ?? strtoupper($updated->stage);
            $effectiveInput = $updated->fittingItem
                ? $this->quantityResolver->resolveEffectiveInputQty($updated->fittingItem, $updated->stage)
                : (int) $updated->input_qty;
            $effectiveGood = $updated->fittingItem
                ? $this->quantityResolver->resolveEffectiveGoodQty($updated->fittingItem, $updated->stage)
                : (int) $updated->good_qty;

            $statusMsg = $updated->status === FittingStageExecution::STATUS_NO_DEFECT
                ? 'Status: NO_DEFECT (0 PCS, tidak masuk antrean QC).'
                : 'Status beralih ke Menunggu QC.';
            $message = "Defect {$updated->defect_qty} PCS berhasil dicatat untuk {$pfktr} ({$stageLabel}). {$statusMsg}";

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'data' => [
                        'execution_id' => $updated->id,
                        'traveler_number' => $pfktr,
                        'stage' => $updated->stage,
                        'checkpoint_code' => $updated->checkpoint_code,
                        'input_qty' => (int) $updated->input_qty,
                        'effective_input_qty' => $effectiveInput,
                        'defect_qty' => (int) $updated->defect_qty,
                        'good_qty' => (int) $updated->good_qty,
                        'effective_good_qty' => $effectiveGood,
                        'status' => $updated->status,
                        'inspection_date' => $updated->inspection_date?->format('Y-m-d'),
                        'defect_entered_at' => $updated->defect_entered_at?->toIso8601String(),
                        'defect_entered_by' => $updated->defect_entered_by,
                    ],
                ]);
            }

            $redirectParams = [
                'mode' => 'unrecorded',
                'stage' => $request->input('stage') ?: $updated->stage,
            ];
            if ($request->filled('search')) {
                $redirectParams['search'] = $request->input('search');
            }

            return redirect()->route('fitting.defects.index', $redirectParams)->with('success', $message);
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
                return response()->json(['success' => false, 'message' => 'Terjadi kesalahan sistem saat menyimpan defect Fitting.'], 500);
            }

            return back()->withInput()->with('error', 'Terjadi kesalahan sistem: '.$e->getMessage());
        }
    }

    /**
     * Adjust defect quantity (+/- delta) on an already-recorded execution.
     */
    public function adjust(Request $request, FittingStageExecution $execution): JsonResponse|RedirectResponse
    {
        $user = auth()->user();

        try {
            $this->authorizationService->authorizeRecordDefect($user);

            $qtyField = $request->has('adjustment_qty') ? 'adjustment_qty' : 'added_qty';
            $isReopened = $execution->status === FittingStageExecution::STATUS_REOPENED_FOR_CORRECTION;
            $deltaRule = $isReopened ? 'integer' : 'integer|not_in:0';

            $validated = $request->validate([
                'added_qty' => 'required_without:adjustment_qty|'.$deltaRule,
                'adjustment_qty' => 'sometimes|'.$deltaRule,
                'idempotency_key' => 'nullable|string|max:64',
                'notes' => [
                    'nullable',
                    'string',
                    'max:1000',
                    function ($attribute, $value, $fail) use ($request, $qtyField, $isReopened) {
                        $deltaVal = (int) $request->input($qtyField);
                        if (($deltaVal < 0 || $isReopened) && strlen(trim((string) $value)) < 3) {
                            $fail($isReopened
                                ? 'Alasan koreksi wajib diisi (minimal 3 karakter) untuk tindak lanjut pasca Reopen QC.'
                                : 'Catatan / alasan wajib diisi untuk pengurangan (koreksi negatif) defect.');
                        }
                    },
                ],
                'stage' => [
                    'nullable',
                    'string',
                    function ($attribute, $value, $fail) use ($execution) {
                        $canonical = FittingStageAuthorizationService::normalizeStage((string) $value);
                        if ($canonical === null || $canonical !== $execution->stage) {
                            $fail("Tahap '{$value}' tidak sesuai dengan tahap eksekusi PFKTR ({$execution->stage}).");
                        }
                    },
                ],
            ], [
                'added_qty.required_without' => 'Nilai penyesuaian defect wajib diisi.',
                'added_qty.integer' => 'Nilai penyesuaian defect harus berupa angka bulat.',
                'added_qty.not_in' => 'Nilai penyesuaian defect tidak boleh 0.',
                'adjustment_qty.integer' => 'Nilai penyesuaian defect harus berupa angka bulat.',
                'adjustment_qty.not_in' => 'Nilai penyesuaian defect tidak boleh 0.',
            ]);

            $delta = (int) ($validated['adjustment_qty'] ?? $validated['added_qty']);
            $idempotencyKey = $request->header('X-Idempotency-Key') ?? ($validated['idempotency_key'] ?? null);

            $updated = $this->defectService->adjustDefectQty(
                executionOrId: $execution,
                adjustmentQty: $delta,
                user: $user,
                notes: $validated['notes'] ?? null,
                idempotencyKey: $idempotencyKey
            );

            $pfktr = $updated->fittingItem?->traveler_number ?? 'PFKTR';
            $effectiveInput = $updated->fittingItem
                ? $this->quantityResolver->resolveEffectiveInputQty($updated->fittingItem, $updated->stage)
                : (int) $updated->input_qty;
            $effectiveGood = $updated->fittingItem
                ? $this->quantityResolver->resolveEffectiveGoodQty($updated->fittingItem, $updated->stage)
                : (int) $updated->good_qty;
            $actionText = $delta === 0
                ? 'Konfirmasi jumlah defect tetap'
                : ($delta > 0 ? "Penambahan defect +{$delta} PCS" : "Koreksi defect {$delta} PCS");
            $statusLabel = $updated->status === FittingStageExecution::STATUS_NO_DEFECT
                ? 'NO_DEFECT (keluar antrean QC)'
                : 'Menunggu QC';
            $message = "{$actionText} berhasil disimpan untuk {$pfktr}. Total defect kini {$updated->defect_qty} PCS (Status: {$statusLabel}).";

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'data' => [
                        'execution_id' => $updated->id,
                        'traveler_number' => $pfktr,
                        'stage' => $updated->stage,
                        'checkpoint_code' => $updated->checkpoint_code,
                        'input_qty' => (int) $updated->input_qty,
                        'effective_input_qty' => $effectiveInput,
                        'defect_qty' => (int) $updated->defect_qty,
                        'good_qty' => (int) $updated->good_qty,
                        'effective_good_qty' => $effectiveGood,
                        'status' => $updated->status,
                        'defect_entered_at' => $updated->defect_entered_at?->toIso8601String(),
                        'defect_entered_by' => $updated->defect_entered_by,
                    ],
                ]);
            }

            return redirect()
                ->route('fitting.defects.index', ['mode' => 'recorded', 'stage' => $updated->stage])
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
                return response()->json(['success' => false, 'message' => 'Terjadi kesalahan sistem saat menyesuaikan defect Fitting.'], 500);
            }

            return back()->withInput()->with('error', 'Terjadi kesalahan sistem: '.$e->getMessage());
        }
    }
}
