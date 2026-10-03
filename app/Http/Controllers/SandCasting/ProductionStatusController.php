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
}
