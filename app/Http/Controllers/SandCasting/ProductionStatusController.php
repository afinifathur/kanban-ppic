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

        $codes = $this->statusService->parseFilterArray($request->input('codes') ?? $request->input('production_code') ?? $request->input('code'));
        $products = $this->statusService->parseFilterArray($request->input('products') ?? $request->input('product') ?? $request->input('item_names') ?? $request->input('item_name'));
        $customers = $this->statusService->parseFilterArray($request->input('customers') ?? $request->input('customer'));
        $planningGroups = $this->statusService->parseFilterArray($request->input('planning_groups') ?? $request->input('planning_group') ?? $request->input('plan_titles') ?? $request->input('plan_title') ?? $request->input('title'));
        $poNumbers = $this->statusService->parseFilterArray($request->input('po_numbers') ?? $request->input('po_number'));
        $search = trim((string) $request->input('search', ''));

        $filters = [
            'search' => $search,
            'filter' => $filter,
            'status' => $filter,
            'codes' => $codes,
            'products' => $products,
            'customers' => $customers,
            'planning_groups' => $planningGroups,
            'po_numbers' => $poNumbers,
        ];

        $rows = $this->statusService->getAggregatedRows($filters, $request->user());
        $summary = $this->statusService->getSummaryCounts($filters, $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'summary' => $summary,
                'rows' => $rows,
            ]);
        }

        $options = $this->statusService->getFilterOptions(['status' => $filter], $request->user());
        $allPlanningGroups = collect($options['all_planning_groups'] ?? [])->merge($planningGroups)->unique()->filter(fn ($v) => $v !== '' && $v !== null)->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->toArray();
        $allCodes = collect($options['all_codes'])->merge($codes)->unique()->filter(fn ($v) => $v !== '' && $v !== null && $v !== '-')->sort()->values()->toArray();
        $allProducts = collect($options['all_products'])->merge($products)->unique()->filter(fn ($v) => $v !== '' && $v !== null && $v !== '-')->sort()->values()->toArray();
        $allCustomers = collect($options['all_customers'])->merge($customers)->unique()->filter(fn ($v) => $v !== '' && $v !== null && $v !== '-')->sort()->values()->toArray();

        return view('sand-casting.production-status.index', [
            'rows' => $rows,
            'summary' => $summary,
            'filters' => $filters,
            'filter' => $filter,
            'activeFilter' => $filter,
            'search' => $search,
            'planningGroups' => $planningGroups,
            'codes' => $codes,
            'products' => $products,
            'customers' => $customers,
            'allPlanningGroups' => $allPlanningGroups,
            'allCodes' => $allCodes,
            'allProducts' => $allProducts,
            'allCustomers' => $allCustomers,
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
