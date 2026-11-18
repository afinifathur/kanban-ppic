<?php

namespace App\Http\Controllers\Fitting;

use App\Http\Controllers\Controller;
use App\Services\Fitting\FittingKanbanQueryService;
use App\Services\Fitting\FittingStageAuthorizationService;
use App\Services\Fitting\FittingStageExecutionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class FittingScanController extends Controller
{
    public function __construct(
        private readonly FittingStageAuthorizationService $authService,
        private readonly FittingStageExecutionService $executionService,
        private readonly FittingKanbanQueryService $queryService
    ) {}

    /**
     * Entry route for Fitting Scanner: redirect SPV to their assigned stage or System Admin to netto-pf.
     */
    public function index()
    {
        $user = auth()->user();

        if ($user && $user->hasRole(FittingStageAuthorizationService::ROLE_SPV_FITTING) && ! empty($user->assigned_stage)) {
            $canonical = FittingStageAuthorizationService::normalizeStage($user->assigned_stage);
            if ($canonical !== null) {
                return redirect()->route('fitting.scan.stage', str_replace('_', '-', $canonical));
            }
        }

        if (FittingStageAuthorizationService::isFittingSystemAdmin($user)) {
            return redirect()->route('fitting.scan.stage', 'netto-pf');
        }

        abort(403, 'Anda tidak memiliki hak akses untuk membuka Scanner Fitting Production.');
    }

    /**
     * Render the Fitting Production Scanner page locked to a single stage.
     */
    public function scanStage(Request $request, string $stage)
    {
        $normalizedSlug = strtolower(trim($stage));
        $targetStage = FittingStageAuthorizationService::normalizeStage($normalizedSlug);

        if ($targetStage === null) {
            abort(404, "Tahap Fitting '{$stage}' tidak valid.");
        }

        $user = auth()->user();
        try {
            $this->authService->authorizeStageExecution($user, $targetStage);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $stageSlug = str_replace('_', '-', $targetStage);
        $stageLabel = FittingStageAuthorizationService::STAGE_LABELS[$targetStage] ?? strtoupper($targetStage);

        // Stage switcher for System Admin only (SPV is locked strictly to assigned_stage)
        $switchableStages = [];
        if (FittingStageAuthorizationService::isFittingSystemAdmin($user)) {
            foreach (FittingStageAuthorizationService::VALID_STAGES as $stg) {
                $stgSlug = str_replace('_', '-', $stg);
                $switchableStages[] = [
                    'stage' => $stg,
                    'slug' => $stgSlug,
                    'label' => FittingStageAuthorizationService::STAGE_LABELS[$stg],
                    'is_active' => $stg === $targetStage,
                    'url' => route('fitting.scan.stage', $stgSlug),
                ];
            }
        }

        $kanbanUrl = FittingStageAuthorizationService::hasFullKanbanVisibility($user)
            ? route('fitting.kanban.stage', $stageSlug)
            : route('fitting.kanban.show', $stageSlug);

        return view('fitting.scan.index', [
            'stage' => $targetStage,
            'stageSlug' => $stageSlug,
            'stageLabel' => $stageLabel,
            'executeUrl' => route('fitting.scan.execute', $stageSlug),
            'lookupPfktrUrl' => url('/fitting/scan/pfktr'),
            'lookupHeatUrl' => url('/fitting/scan/heat'),
            'kanbanUrl' => $kanbanUrl,
            'switchableStages' => $switchableStages,
        ]);
    }

    /**
     * Lookup a single Fitting traveler by PFKTR number (JSON).
     */
    public function lookupPfktr(Request $request, string $travelerNumber): JsonResponse
    {
        $user = auth()->user();
        $stageParam = $request->query('stage');
        $targetStage = $stageParam ? FittingStageAuthorizationService::normalizeStage((string) $stageParam) : null;

        try {
            $this->authorizeLookupUser($user, $targetStage);
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        }

        $pfktr = urldecode(trim($travelerNumber));
        $data = $this->queryService->findTravelerForScanner($pfktr, $targetStage);

        if (! $data) {
            return response()->json([
                'success' => false,
                'message' => "Nomor Kitir [{$pfktr}] tidak ditemukan pada Fitting Production.",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Fallback search: list candidate ACTIVE Fitting travelers matching a Heat Number.
     * NEVER executes scan; user must choose a specific PFKTR from the returned list.
     */
    public function lookupHeat(Request $request, string $heatNumber): JsonResponse
    {
        $user = auth()->user();
        $stageParam = $request->query('stage');
        $targetStage = $stageParam ? FittingStageAuthorizationService::normalizeStage((string) $stageParam) : null;

        try {
            $this->authorizeLookupUser($user, $targetStage);
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        }

        $cleanHeat = urldecode(trim($heatNumber));
        $candidates = $this->queryService->findTravelersByHeat($cleanHeat, $targetStage);

        if ($candidates->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => "Tidak ditemukan traveler aktif Fitting untuk Heat Number [{$cleanHeat}].",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $candidates->all(),
        ]);
    }

    /**
     * Execute physical scan for a SINGLE PFKTR traveler on a locked Fitting stage.
     * Strictly accepts only `traveler_number` (PFKTR) — never mass-scans by Heat Number.
     */
    public function execute(Request $request, string $stage): JsonResponse
    {
        $normalizedSlug = strtolower(trim($stage));
        $targetStage = FittingStageAuthorizationService::normalizeStage($normalizedSlug);

        if ($targetStage === null) {
            return response()->json([
                'success' => false,
                'message' => "Tahap target '{$stage}' tidak valid dalam alur Fitting Production.",
            ], 422);
        }

        $user = auth()->user();

        try {
            // 1. Strict RBAC authorization before validation or mutation
            $this->authService->authorizeStageExecution($user, $targetStage);

            // 2. Reject any attempt to submit heat_number or batch traveler_numbers array
            if ($request->has('heat_number') || $request->has('traveler_numbers')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Scan massal berdasarkan Heat Number tidak diperbolehkan. Pilih satu nomor PFKTR spesifik untuk dipindai.',
                ], 422);
            }

            $validated = $request->validate([
                'traveler_number' => 'required|string|max:64',
                'notes' => 'nullable|string|max:500',
            ], [
                'traveler_number.required' => 'Nomor Kitir (PFKTR) wajib diisi.',
            ]);

            $execution = $this->executionService->scanStage(
                $validated['traveler_number'],
                $targetStage,
                $user,
                $validated['notes'] ?? null
            );

            $freshItem = $execution->fittingItem;
            $latestPayload = $this->queryService->findTravelerForScanner($freshItem->traveler_number, $targetStage);

            $stageLabel = FittingStageAuthorizationService::STAGE_LABELS[$targetStage] ?? strtoupper($targetStage);
            $nextLabel = $freshItem->current_stage === \App\Models\FittingItem::STAGE_COMPLETED
                ? 'COMPLETED (SELESAI)'
                : (FittingStageAuthorizationService::STAGE_LABELS[$freshItem->current_stage] ?? strtoupper((string) $freshItem->current_stage));

            return response()->json([
                'success' => true,
                'message' => "Scan tahap {$stageLabel} untuk [{$freshItem->traveler_number}] berhasil. Posisi berikutnya: {$nextLabel}.",
                'data' => [
                    'execution_id' => $execution->id,
                    'traveler_number' => $freshItem->traveler_number,
                    'stage' => $execution->stage,
                    'checkpoint_code' => $execution->checkpoint_code,
                    'input_qty' => (int) $execution->input_qty,
                    'defect_qty' => (int) $execution->defect_qty,
                    'good_qty' => (int) $execution->good_qty,
                    'status' => $execution->status,
                    'current_stage' => $freshItem->current_stage,
                    'current_stage_label' => $nextLabel,
                    'executed_at' => $execution->executed_at?->format('d/m/Y H:i:s'),
                    'physical_done_at' => $execution->physical_done_at?->format('d/m/Y H:i:s'),
                    'operator_id' => $execution->operator_id,
                    'operator_name' => $execution->operator?->name,
                    'notes' => $execution->notes,
                    'traveler' => $latestPayload,
                ],
            ]);
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan sistem saat memproses scan PFKTR.',
            ], 500);
        }
    }

    /**
     * Authorize scanner lookup calls strictly to scanner-capable accounts.
     *
     * @throws AuthorizationException
     */
    protected function authorizeLookupUser(?\App\Models\User $user, ?string $targetStage): void
    {
        if (! $user) {
            throw new AuthorizationException('User belum terautentikasi.');
        }

        if ($targetStage !== null) {
            $this->authService->authorizeStageExecution($user, $targetStage);

            return;
        }

        if (FittingStageAuthorizationService::isFittingSystemAdmin($user)) {
            return;
        }

        if ($user->hasRole(FittingStageAuthorizationService::ROLE_SPV_FITTING) && ! empty($user->assigned_stage)) {
            $assigned = FittingStageAuthorizationService::normalizeStage($user->assigned_stage);
            if ($assigned !== null) {
                $this->authService->authorizeStageExecution($user, $assigned);

                return;
            }
        }

        throw new AuthorizationException('Anda tidak memiliki kewenangan menggunakan Scanner Fitting Production.');
    }
}
