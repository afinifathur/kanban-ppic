<?php

namespace App\Http\Controllers\SandCasting;

use App\Http\Controllers\Controller;
use App\Services\SandCasting\SandCastingProductionFloorQueryService;
use App\Services\SandCasting\SandCastingStageAuthorizationService;
use App\Services\SandCasting\SandCastingStageExecutionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class ProductionFloorScanController extends Controller
{
    /**
     * URL Slug to Domain Stage mapping.
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
     * Entry route for sand-casting scanner: redirect to user's assigned stage scanner or default.
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        if ($user && $user->hasRole('spv') && ! empty($user->assigned_stage)) {
            $stageSlug = str_replace('_', '-', $user->assigned_stage);

            return redirect()->route('sand-casting.scan.stage', $stageSlug);
        }

        return redirect()->route('sand-casting.scan.stage', 'netto');
    }

    /**
     * Render the NETTO scanner pilot page.
     */
    public function scanNetto(Request $request)
    {
        return $this->scanStage($request, 'netto');
    }

    /**
     * Render a stage scanner page based on context.
     */
    public function scanStage(Request $request, string $stage = 'netto')
    {
        $normalizedSlug = strtolower(trim($stage));
        $targetStage = SandCastingStageAuthorizationService::normalizeStage($normalizedSlug);

        if (! $targetStage) {
            abort(404, "Tahap '{$stage}' tidak valid.");
        }

        $user = auth()->user();
        if (! $this->authorizationService->canAccessStage($user, $targetStage)) {
            $stageLabel = self::STAGE_LABELS[$targetStage] ?? strtoupper(str_replace('_', ' ', $targetStage));
            abort(403, "Anda tidak memiliki hak akses untuk membuka scanner tahap {$stageLabel}.");
        }

        $stageLabel = self::STAGE_LABELS[$targetStage] ?? strtoupper(str_replace('_', ' ', $targetStage));

        return view('sand-casting.scan.index', [
            'stage' => $targetStage,
            'stageSlug' => $normalizedSlug,
            'stageLabel' => $stageLabel,
            'executeUrl' => route('sand-casting.scan.execute', $normalizedSlug),
            'lookupKtrUrl' => url('/sand-casting/scan/ktr'),
            'lookupHeatUrl' => url('/sand-casting/scan/heat'),
        ]);
    }

    /**
     * Lookup operational state and history for a single KTR traveler.
     */
    public function lookupKtr(Request $request, string $travelerNumber): JsonResponse
    {
        $travelerNumber = urldecode(trim($travelerNumber));
        $data = $this->queryService->findByTraveler($travelerNumber);

        if (! $data) {
            return response()->json([
                'success' => false,
                'message' => 'KTR tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Lookup candidate KTR travelers associated with a Heat Number (manual fallback).
     */
    public function lookupHeat(Request $request, string $heatNumber): JsonResponse
    {
        $heatNumber = urldecode(trim($heatNumber));
        $data = $this->queryService->findByHeat($heatNumber);

        if ($data->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Data KTR untuk Heat Number tersebut tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $data->values()->all(),
        ]);
    }

    /**
     * Execute stage for a physical KTR traveler. Stage is locked by endpoint/context.
     */
    public function execute(Request $request, string $stage): JsonResponse
    {
        $normalizedSlug = strtolower(trim($stage));
        $targetStage = SandCastingStageAuthorizationService::normalizeStage($normalizedSlug);

        if (! $targetStage) {
            return response()->json([
                'success' => false,
                'message' => "Tahap target '{$stage}' tidak valid dalam alur eksekusi Sand Casting.",
            ], 422);
        }

        $user = auth()->user();

        try {
            // 1. Authorization check strictly BEFORE validation or mutation
            $this->authorizationService->authorize($user, $targetStage);

            // 2. Validate request payload
            $validated = $request->validate([
                'traveler_number' => 'required|string',
                'defect_qty' => 'nullable|integer|min:0',
                'notes' => 'nullable|string|max:500',
            ]);

            // 3. State machine execution: Operator/SPV marks physical completion (transitions to WAITING_DEFECT)
            $execution = $this->executionService->markPhysicalDone(
                travelerNumber: $validated['traveler_number'],
                targetStage: $targetStage,
                operatorId: (int) $user->id,
                notes: $validated['notes'] ?? null
            );

            // Re-query latest state for authoritative fresh payload
            $latestKtr = $this->queryService->findByTraveler($validated['traveler_number']);

            return response()->json([
                'success' => true,
                'message' => 'Proses fisik KTR berhasil diselesaikan. Status: Menunggu input defect (WAITING_DEFECT).',
                'data' => [
                    'traveler_number' => $execution->castingResultLine?->traveler_number ?? $validated['traveler_number'],
                    'stage' => $execution->stage,
                    'checkpoint_code' => $execution->checkpoint_code,
                    'input_qty' => (int) $execution->input_qty,
                    'defect_qty' => (int) $execution->defect_qty,
                    'good_qty' => (int) $execution->good_qty,
                    'status' => $execution->status,
                    'current_stage' => $latestKtr['current_stage'] ?? null,
                    'operational_status' => $execution->status,
                    'next_stage' => $latestKtr['next_stage'] ?? null,
                    'executed_at' => $execution->executed_at,
                    'physical_done_at' => $execution->physical_done_at,
                    'operator_id' => $execution->operator_id,
                    'operator_name' => $execution->operator?->name,
                    'notes' => $execution->notes,
                    'latest_ktr' => $latestKtr,
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
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan sistem saat memproses eksekusi KTR.',
            ], 500);
        }
    }
}
