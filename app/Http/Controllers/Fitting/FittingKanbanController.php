<?php

namespace App\Http\Controllers\Fitting;

use App\Http\Controllers\Controller;
use App\Services\Fitting\FittingKanbanQueryService;
use App\Services\Fitting\FittingStageAuthorizationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class FittingKanbanController extends Controller
{
    public function __construct(
        private readonly FittingStageAuthorizationService $authService,
        private readonly FittingKanbanQueryService $queryService
    ) {}

    /**
     * Entry route for Fitting Floor Kanban:
     * - Full visibility users (Admin, PPIC Fitting, Admin PPIC PF, Admin QC Fitting, SPV GD PF, Auditor, Sales) -> netto_pf
     * - Single-stage SPV Fitting -> their assigned_stage
     */
    public function index(Request $request): View|JsonResponse
    {
        $user = auth()->user();

        if (FittingStageAuthorizationService::hasFullKanbanVisibility($user)) {
            return $this->show($request, 'netto-pf');
        }

        if ($user && $user->hasRole(FittingStageAuthorizationService::ROLE_SPV_FITTING) && ! empty($user->assigned_stage)) {
            $canonical = FittingStageAuthorizationService::normalizeStage($user->assigned_stage);
            if ($canonical !== null) {
                return $this->show($request, str_replace('_', '-', $canonical));
            }
        }

        return $this->show($request, 'netto-pf');
    }

    /**
     * Display Fitting Kanban Floor board for a specific stage (Line 1..4 + Unassigned).
     */
    public function show(Request $request, string $stage): View|JsonResponse
    {
        $normalizedSlug = strtolower(trim($stage));
        $targetStage = FittingStageAuthorizationService::normalizeStage($normalizedSlug);

        if ($targetStage === null) {
            abort(404, "Tahap operasional Fitting '{$stage}' tidak valid.");
        }

        $user = auth()->user();
        try {
            $this->authService->authorizeKanbanView($user, $targetStage);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $filters = [];
        if ($request->filled('search')) {
            $filters['search'] = trim((string) $request->input('search'));
        }
        if ($request->filled('line_bucket')) {
            $filters['line_bucket'] = trim((string) $request->input('line_bucket'));
        }
        if ($request->filled('customer')) {
            $filters['customer'] = trim((string) $request->input('customer'));
        }
        if ($request->filled('is_urgent')) {
            $filters['is_urgent'] = filter_var($request->input('is_urgent'), FILTER_VALIDATE_BOOLEAN);
        }

        $kanbanData = $this->queryService->getStageKanbanData($targetStage, $filters);

        if ($request->wantsJson()) {
            return response()->json($kanbanData);
        }

        // Build accessible stage switcher tabs
        $availableStages = [];
        foreach (FittingStageAuthorizationService::VALID_STAGES as $stg) {
            if ($this->authService->canViewStageKanban($user, $stg)) {
                $stgSlug = str_replace('_', '-', $stg);
                $availableStages[$stg] = [
                    'stage' => $stg,
                    'slug' => $stgSlug,
                    'label' => FittingStageAuthorizationService::STAGE_LABELS[$stg],
                    'is_active' => $stg === $targetStage,
                    'url' => route('fitting.kanban.stage', $stgSlug),
                ];
            }
        }

        $stageSlug = str_replace('_', '-', $targetStage);
        $canReorder = $this->authService->canReorderKanban($user);
        $canScan = $this->authService->canExecuteStage($user, $targetStage);

        return view('fitting.kanban.index', [
            'stage' => $targetStage,
            'stageSlug' => $stageSlug,
            'stageLabel' => $kanbanData['stage_label'],
            'cards' => $kanbanData['cards'],
            'buckets' => $kanbanData['buckets'],
            'summary' => $kanbanData['summary'],
            'filters' => $filters,
            'availableStages' => $availableStages,
            'canReorder' => $canReorder,
            'canScan' => $canScan,
            'scannerUrl' => $canScan ? route('fitting.scan.stage', $stageSlug) : null,
            'reorderUrl' => route('fitting.kanban.reorder', $stageSlug),
            'lastRefreshedAt' => now()->format('H:i:s'),
        ]);
    }

    /**
     * Reorder active queue items within a stage and line bucket ('1'..'4' or 'unassigned').
     * Strictly authorized to PPIC Fitting (`manage_fitting_kanban`) and System Admin.
     */
    public function reorder(Request $request, string $stage): JsonResponse|RedirectResponse
    {
        $user = auth()->user();
        try {
            $this->authService->authorizeReorderKanban($user);
        } catch (AuthorizationException $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
            }
            abort(403, $e->getMessage());
        }

        $validated = $request->validate([
            'line_bucket' => ['required', 'string', 'in:1,2,3,4,unassigned'],
            'from_position' => ['required', 'integer', 'min:1'],
            'to_position' => ['required', 'integer', 'min:1'],
        ], [
            'line_bucket.in' => 'Bucket Line harus bernilai 1, 2, 3, 4, atau unassigned.',
        ]);

        try {
            $result = $this->queryService->reorderStageBucketQueue(
                $stage,
                $validated['line_bucket'],
                (int) $validated['from_position'],
                (int) $validated['to_position'],
                $user
            );

            $bucketLabel = $validated['line_bucket'] === 'unassigned'
                ? 'Unassigned'
                : "Line {$validated['line_bucket']}";
            $msg = "Antrean {$bucketLabel} berhasil diatur ulang (Posisi {$validated['from_position']} → {$validated['to_position']}).";

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $msg,
                    'data' => $result,
                ]);
            }

            return back()->with('success', $msg);
        } catch (AuthorizationException $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
            }
            abort(403, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->withErrors(['reorder' => $e->getMessage()]);
        }
    }
}
