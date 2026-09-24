<?php

namespace App\Http\Controllers\SandCasting;

use App\Http\Controllers\Controller;
use App\Services\SandCasting\SandCastingProductionFloorQueryService;
use App\Services\SandCasting\SandCastingStageAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductionFloorKanbanController extends Controller
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
        protected SandCastingStageAuthorizationService $authorizationService
    ) {}

    /**
     * Entry route for Sand Casting Operational Kanban:
     * - SPV defaults to their assigned stage.
     * - Admin / PPIC defaults to 'netto' or their first accessible stage.
     */
    public function index(Request $request): View|JsonResponse
    {
        $user = auth()->user();

        if ($user && $user->hasRole('spv') && ! empty($user->assigned_stage)) {
            return $this->show($request, $user->assigned_stage);
        }

        return $this->show($request, 'netto');
    }

    /**
     * Render the Operational Kanban board for a specific stage.
     */
    public function show(Request $request, string $stage): View|JsonResponse
    {
        $normalizedSlug = strtolower(trim($stage));
        $targetStage = SandCastingStageAuthorizationService::normalizeStage($normalizedSlug);

        if (! $targetStage) {
            abort(404, "Tahap operasional '{$stage}' tidak valid.");
        }

        $user = auth()->user();
        if (! $this->authorizationService->canAccessStage($user, $targetStage)) {
            $stageLabel = self::STAGE_LABELS[$targetStage] ?? strtoupper(str_replace('_', ' ', $targetStage));
            abort(403, "Anda tidak memiliki hak akses untuk membuka Kanban tahap {$stageLabel}.");
        }

        $stageLabel = self::STAGE_LABELS[$targetStage] ?? strtoupper(str_replace('_', ' ', $targetStage));

        // Extract and clean filters
        $filters = [];
        if ($request->filled('search')) {
            $filters['search'] = trim((string) $request->input('search'));
        }
        if ($request->filled('line_number') && is_numeric($request->input('line_number'))) {
            $filters['line_number'] = (int) $request->input('line_number');
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

        // Determine accessible stages for navigation tabs
        $availableStages = [];
        foreach (SandCastingStageAuthorizationService::VALID_STAGES as $stg) {
            if ($this->authorizationService->canAccessStage($user, $stg)) {
                $availableStages[$stg] = [
                    'stage' => $stg,
                    'slug' => str_replace('_', '-', $stg),
                    'label' => self::STAGE_LABELS[$stg] ?? strtoupper(str_replace('_', ' ', $stg)),
                    'is_active' => ($stg === $targetStage),
                    'url' => route('sand-casting.kanban.stage', str_replace('_', '-', $stg)),
                ];
            }
        }

        $scannerSlug = str_replace('_', '-', $targetStage);
        $scannerUrl = route('sand-casting.scan.stage', $scannerSlug);

        $canReorder = $this->authorizationService->canReorderQueue($user);

        return view('sand-casting.kanban.index', [
            'stage' => $targetStage,
            'stageSlug' => $scannerSlug,
            'stageLabel' => $stageLabel,
            'summary' => $kanbanData['summary'],
            'readyCards' => $kanbanData['ready'],
            'incomingCards' => $kanbanData['incoming'],
            'haltedCards' => $kanbanData['halted'],
            'filters' => $filters,
            'availableStages' => $availableStages,
            'scannerUrl' => $scannerUrl,
            'canReorder' => $canReorder,
            'lastRefreshedAt' => now()->format('H:i:s'),
        ]);
    }

    /**
     * Reorder active ready queue items for a stage and line number.
     */
    public function reorder(Request $request, string $stage): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $user = auth()->user();
        if (! $this->authorizationService->canReorderQueue($user)) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya PPIC (ppicflange@peroniks.com) yang berwenang mengatur ulang antrean prioritas Kanban.',
                ], 403);
            }
            abort(403, 'Hanya PPIC (ppicflange@peroniks.com) yang berwenang mengatur ulang antrean prioritas Kanban.');
        }

        $validated = $request->validate([
            'line_number' => ['required', 'integer', 'min:1', 'max:4'],
            'from_position' => ['required', 'integer', 'min:1'],
            'to_position' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $result = $this->queryService->reorderStageLineQueue(
                $stage,
                (int) $validated['line_number'],
                (int) $validated['from_position'],
                (int) $validated['to_position']
            );

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => "Antrean Line {$validated['line_number']} berhasil diatur ulang (Posisi {$validated['from_position']} → {$validated['to_position']}).",
                    'data' => $result,
                ]);
            }

            return back()->with('success', "Antrean Line {$validated['line_number']} berhasil diatur ulang (Posisi {$validated['from_position']} → {$validated['to_position']}).");
        } catch (\InvalidArgumentException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return back()->withErrors(['reorder' => $e->getMessage()]);
        } catch (\Exception $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal mengatur ulang antrean: '.$e->getMessage(),
                ], 500);
            }

            return back()->withErrors(['reorder' => 'Gagal mengatur ulang antrean: '.$e->getMessage()]);
        }
    }
}
