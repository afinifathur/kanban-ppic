<?php

namespace App\Http\Controllers\SandCasting;

use App\Http\Controllers\Controller;
use App\Models\SandCastingStageExecution;
use App\Services\SandCasting\SandCastingProductionFloorQueryService;
use App\Services\SandCasting\SandCastingStageAuthorizationService;
use App\Services\SandCasting\SandCastingStageExecutionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

class QcDefectVerificationController extends Controller
{
    public const STAGE_LABELS = [
        'netto' => 'NETTO',
        'bubut_od' => 'BUBUT OD',
        'bubut_cnc' => 'BUBUT CNC',
        'bor' => 'BOR',
        'qc' => 'QC',
        'gudang_jadi' => 'GUDANG JADI',
    ];

    public function __construct(
        protected SandCastingProductionFloorQueryService $queryService,
        protected SandCastingStageExecutionService $executionService,
        protected SandCastingStageAuthorizationService $authorizationService
    ) {}

    /**
     * Render the QC Defect Verification 6-tab dashboard.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $user = auth()->user();

        try {
            $this->authorizationService->authorizeVerifyQc($user);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $filters = [
            'search' => $request->query('search'),
        ];

        $summary = $this->queryService->getQcVerificationSummary();
        $queues = $this->queryService->getAllQcVerificationQueues($filters);

        $activeTab = $request->query('tab', 'netto');
        $activeTab = SandCastingStageAuthorizationService::normalizeStage($activeTab) ?? 'netto';

        // Defect types map per stage for modal dropdown
        $defectTypesMap = [];
        foreach (SandCastingStageExecutionService::STAGES as $stageKey) {
            $defectTypesMap[$stageKey] = $this->queryService->getStageDefectTypes($stageKey)->map(fn ($dt) => [
                'id' => $dt->id,
                'name' => $dt->name,
                'department' => $dt->department,
            ])->values()->all();
        }

        return view('sand-casting.qc-defects.index', [
            'summary' => $summary,
            'queues' => $queues,
            'stages' => SandCastingStageExecutionService::STAGES,
            'stageLabels' => self::STAGE_LABELS,
            'activeTab' => $activeTab,
            'search' => $filters['search'],
            'defectTypesMap' => $defectTypesMap,
        ]);
    }

    /**
     * Verify defect breakdown for a stage execution (QC Step 3).
     */
    public function verify(Request $request, SandCastingStageExecution $execution): JsonResponse|RedirectResponse
    {
        $user = auth()->user();

        try {
            // 1. Authorization check
            $this->authorizationService->authorizeVerifyQc($user);

            // 2. Validate request
            $validated = $request->validate([
                'defects' => 'nullable|array',
                'defects.*.defect_type_id' => 'required_with:defects|integer|exists:defect_types,id',
                'defects.*.qty' => 'required_with:defects|integer|min:1',
                'defects.*.notes' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:500',
            ], [
                'defects.*.defect_type_id.required_with' => 'Jenis cacat wajib dipilih untuk setiap baris.',
                'defects.*.defect_type_id.exists' => 'Jenis cacat yang dipilih tidak valid.',
                'defects.*.qty.required_with' => 'Kuantitas cacat wajib diisi.',
                'defects.*.qty.min' => 'Kuantitas cacat minimal 1 PCS.',
            ]);

            $rawDefects = $validated['defects'] ?? [];

            // 3. State machine execution: QC Inspector verifies defect classification breakdown
            $confirmedExecution = $this->executionService->verifyQcBreakdown(
                executionOrId: $execution,
                defects: $rawDefects,
                qcUserId: (int) $user->id,
                notes: $validated['notes'] ?? null
            );

            $travelerNumber = $confirmedExecution->castingResultLine?->traveler_number ?? 'KTR';
            $successMessage = "Verifikasi defect KTR {$travelerNumber} berhasil dikonfirmasi. Status: CONFIRMED.";

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $successMessage,
                    'data' => [
                        'execution_id' => $confirmedExecution->id,
                        'traveler_number' => $travelerNumber,
                        'stage' => $confirmedExecution->stage,
                        'checkpoint_code' => $confirmedExecution->checkpoint_code,
                        'input_qty' => (int) $confirmedExecution->input_qty,
                        'defect_qty' => (int) $confirmedExecution->defect_qty,
                        'good_qty' => (int) $confirmedExecution->good_qty,
                        'status' => $confirmedExecution->status,
                        'qc_verified_at' => $confirmedExecution->qc_verified_at,
                        'qc_verified_by' => $confirmedExecution->qc_verified_by,
                    ],
                ]);
            }

            return redirect()
                ->route('sand-casting.qc-defects.index', ['tab' => $confirmedExecution->stage])
                ->with('success', $successMessage);

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
        } catch (\Illuminate\Validation\ValidationException $e) {
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
                return response()->json(['success' => false, 'message' => 'Terjadi kesalahan sistem saat memverifikasi defect.'], 500);
            }

            return back()->withInput()->with('error', 'Terjadi kesalahan sistem: '.$e->getMessage());
        }
    }
}
