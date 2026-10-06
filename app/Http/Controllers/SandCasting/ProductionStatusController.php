<?php

namespace App\Http\Controllers\SandCasting;

use App\Http\Controllers\Controller;
use App\Services\SandCasting\SandCastingProductionStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductionStatusController extends Controller
{
    public function __construct(
        private readonly SandCastingProductionStatusService $statusService
    ) {}

    /**
     * Display Sand Casting production status read model.
     */
    public function index(Request $request): View|JsonResponse
    {
        $filter = $request->input('filter', $request->input('status', 'active'));
        $filters = [
            'search' => $request->input('search'),
            'filter' => $filter,
            'status' => $filter,
            'codes' => $request->input('production_code') ?? $request->input('codes') ?? $request->input('code'),
            'customers' => $request->input('customer') ?? $request->input('customers'),
            'po_numbers' => $request->input('po_number') ?? $request->input('po_numbers'),
        ];

        $rows = $this->statusService->getAggregatedRows($filters, $request->user());
        $summary = $this->statusService->getSummaryCounts($filters, $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'summary' => $summary,
                'rows' => $rows,
            ]);
        }

        return view('sand-casting.production-status.index', [
            'rows' => $rows,
            'summary' => $summary,
            'filters' => $filters,
            'filter' => $filter,
            'activeFilter' => $filter,
            'search' => $filters['search'] ?? '',
            'activeCount' => $summary['active_count'] ?? 0,
            'completedCount' => $summary['completed_count'] ?? 0,
            'totalCount' => $summary['total_count'] ?? 0,
        ]);
    }

    /**
     * Get detailed physical KTR barcode items for a specific Production Code / Plan.
     */
    public function details(Request $request): JsonResponse
    {
        $planIdOrCode = $request->input('production_plan_id')
            ?? $request->input('plan_id')
            ?? $request->input('code')
            ?? $request->input('production_code');

        if (! $planIdOrCode) {
            return response()->json([
                'message' => 'Parameter production_plan_id atau code harus diisi.',
                'items' => [],
            ], 422);
        }

        $details = $this->statusService->getProductionCodeDetails($planIdOrCode, $request->user());

        if (! $details) {
            return response()->json([
                'message' => 'Data detail Kode Produksi tidak ditemukan.',
                'items' => [],
            ], 404);
        }

        return response()->json($details);
    }
}
