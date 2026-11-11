<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Services\LostWax\ScanSiapCorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScanSiapCorController extends Controller
{
    public function __construct(
        protected ScanSiapCorService $scanSiapCorService
    ) {}

    /**
     * Display the Scan Siap Cor interface.
     */
    public function index()
    {
        $recentEvents = LostWaxScanEvent::with([
            'tree.workOrder.itemReference',
            'tree.printOrderLine.productionPlan',
            'operator',
        ])
            ->where('stage', 'siap_cor')
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('lost-wax.scan-siap-cor.index', compact('recentEvents'));
    }

    /**
     * Process barcode scan for Siap Cor.
     */
    public function process(Request $request): JsonResponse
    {
        $barcode = trim($request->input('barcode', ''));

        if ($barcode === '') {
            return response()->json([
                'success' => false,
                'code' => 'EMPTY_BARCODE',
                'reason' => 'GAGAL — BARCODE TIDAK BOLEH KOSONG',
            ]);
        }

        // Product scope RBAC check for PPIC users
        $user = auth()->user();
        if ($user && $user->hasRole('ppic') && $user->product_scope) {
            $tree = LostWaxTree::with(['printOrderLine.productionPlan'])
                ->where('barcode', $barcode)
                ->first();

            if ($tree) {
                $plan = $tree->printOrderLine?->productionPlan;
                if (! $plan || $plan->product_scope !== $user->product_scope) {
                    return response()->json([
                        'success' => false,
                        'code' => 'UNAUTHORIZED_SCOPE',
                        'reason' => 'GAGAL — BUKAN LINGKUP PRODUK ANDA',
                    ]);
                }
            }
        }

        $operatorId = auth()->id() ?? 1;
        $result = $this->scanSiapCorService->processScan($barcode, $operatorId);

        return response()->json($result);
    }
}
