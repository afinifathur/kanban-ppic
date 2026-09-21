<?php

namespace App\Http\Controllers\SandCasting;

use App\Http\Controllers\Controller;
use App\Services\SandCasting\SandCastingProductionFloorQueryService;
use App\Services\SandCasting\SandCastingStageExecutionService;
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
        'marking' => 'marking',
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
        'marking' => 'MARKING',
        'bubut_cnc' => 'BUBUT CNC',
        'bor' => 'BOR',
        'qc' => 'QC',
        'gudang_jadi' => 'GUDANG JADI',
    ];

    public function __construct(
        protected SandCastingProductionFloorQueryService $queryService,
        protected SandCastingStageExecutionService $executionService
    ) {}

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
        $targetStage = self::STAGE_SLUG_MAP[$normalizedSlug] ?? $normalizedSlug;

        if (! in_array($targetStage, SandCastingStageExecutionService::STAGES, true)) {
            abort(404, "Tahap '{$stage}' tidak valid.");
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
        $targetStage = self::STAGE_SLUG_MAP[$normalizedSlug] ?? $normalizedSlug;

        if (! in_array($targetStage, SandCastingStageExecutionService::STAGES, true)) {
            return response()->json([
                'success' => false,
                'message' => "Tahap target '{$stage}' tidak valid dalam alur eksekusi Sand Casting.",
            ], 422);
        }

        $validated = $request->validate([
            'traveler_number' => 'required|string',
            'defect_qty' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $operatorId = auth()->id();

        try {
            $execution = $this->executionService->execute(
                travelerNumber: $validated['traveler_number'],
                targetStage: $targetStage,
                defectQty: (int) $validated['defect_qty'],
                operatorId: (int) $operatorId,
                notes: $validated['notes'] ?? null
            );

            // Re-query latest state for authoritative fresh payload
            $latestKtr = $this->queryService->findByTraveler($validated['traveler_number']);

            return response()->json([
                'success' => true,
                'message' => 'KTR berhasil diproses.',
                'data' => [
                    'traveler_number' => $execution->castingResultLine?->traveler_number ?? $validated['traveler_number'],
                    'stage' => $execution->stage,
                    'input_qty' => (int) $execution->input_qty,
                    'defect_qty' => (int) $execution->defect_qty,
                    'good_qty' => (int) $execution->good_qty,
                    'current_stage' => $latestKtr['current_stage'] ?? null,
                    'operational_status' => $latestKtr['operational_status'] ?? null,
                    'next_stage' => $latestKtr['next_stage'] ?? null,
                    'executed_at' => $execution->executed_at,
                    'operator_id' => $execution->operator_id,
                    'operator_name' => $execution->operator?->name,
                    'notes' => $execution->notes,
                    'latest_ktr' => $latestKtr,
                ],
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan sistem saat memproses eksekusi KTR.',
            ], 500);
        }
    }
}
