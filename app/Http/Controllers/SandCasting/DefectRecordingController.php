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

class DefectRecordingController extends Controller
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
     * Render the PPIC Defect Recording main 6-tab dashboard.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $user = auth()->user();

        try {
            $this->authorizationService->authorizeRecordDefect($user);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $filters = [
            'search' => $request->query('search'),
        ];

        $summary = $this->queryService->getDefectRecordingSummary();
        $queues = $this->queryService->getAllDefectRecordingQueues($filters);

        $activeTab = $request->query('tab', 'netto');
        $activeTab = SandCastingStageAuthorizationService::normalizeStage($activeTab) ?? 'netto';

        return view('sand-casting.defects.index', [
            'summary' => $summary,
            'queues' => $queues,
            'stages' => SandCastingStageExecutionService::STAGES,
            'stageLabels' => self::STAGE_LABELS,
            'activeTab' => $activeTab,
            'search' => $filters['search'],
        ]);
    }

    /**
     * Record total defect quantity for a stage execution (PPIC Step 2).
     */
    public function record(Request $request, SandCastingStageExecution $execution): JsonResponse|RedirectResponse
    {
        $user = auth()->user();

        try {
            // 1. Authorization check
            $this->authorizationService->authorizeRecordDefect($user);

            // 2. Validate request
            $validated = $request->validate([
                'defect_qty' => 'required|integer|min:0',
                'notes' => 'nullable|string|max:500',
                'process_date' => 'nullable|date',
            ], [
                'defect_qty.required' => 'Jumlah rusak (defect) wajib diisi.',
                'defect_qty.integer' => 'Jumlah rusak harus berupa angka bulat.',
                'defect_qty.min' => 'Jumlah rusak tidak boleh bernilai negatif.',
            ]);

            $defectQty = (int) $validated['defect_qty'];

            // 3. State Machine transition: Step 2 Admin PPIC records total defect
            $updatedExecution = $this->executionService->recordDefectQty(
                executionOrId: $execution,
                defectQty: $defectQty,
                adminId: (int) $user->id,
                notes: $validated['notes'] ?? null
            );

            $travelerNumber = $updatedExecution->castingResultLine?->traveler_number ?? 'KTR';
            $successMessage = "Defect sebanyak {$defectQty} pcs berhasil dicatat untuk KTR {$travelerNumber}. Status dialihkan ke Menunggu QC.";

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $successMessage,
                    'data' => [
                        'execution_id' => $updatedExecution->id,
                        'traveler_number' => $travelerNumber,
                        'stage' => $updatedExecution->stage,
                        'checkpoint_code' => $updatedExecution->checkpoint_code,
                        'input_qty' => (int) $updatedExecution->input_qty,
                        'defect_qty' => (int) $updatedExecution->defect_qty,
                        'good_qty' => (int) $updatedExecution->good_qty,
                        'status' => $updatedExecution->status,
                        'defect_entered_at' => $updatedExecution->defect_entered_at,
                        'defect_entered_by' => $updatedExecution->defect_entered_by,
                    ],
                ]);
            }

            return redirect()
                ->route('sand-casting.defects.index', ['tab' => $updatedExecution->stage])
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
                return response()->json(['success' => false, 'message' => 'Terjadi kesalahan sistem saat menyimpan defect.'], 500);
            }

            return back()->withInput()->with('error', 'Terjadi kesalahan sistem: '.$e->getMessage());
        }
    }
}
