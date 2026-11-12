<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use App\Models\LostWaxScanEvent;
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
     * Preview barcode scan for Siap Cor (returns item details for popup verification without modifying DB).
     */
    public function preview(Request $request): JsonResponse
    {
        $barcode = trim((string) $request->input('barcode', ''));

        if ($barcode === '') {
            return response()->json([
                'success' => false,
                'code' => 'EMPTY_BARCODE',
                'reason' => 'GAGAL — BARCODE TIDAK BOLEH KOSONG',
            ]);
        }

        $result = $this->scanSiapCorService->previewScan($barcode, auth()->user());

        return response()->json($result);
    }

    /**
     * Process / Confirm barcode scan for Siap Cor (atomic transition to siap_cor).
     */
    public function process(Request $request): JsonResponse
    {
        $barcode = trim((string) $request->input('barcode', ''));

        if ($barcode === '') {
            return response()->json([
                'success' => false,
                'code' => 'EMPTY_BARCODE',
                'reason' => 'GAGAL — BARCODE TIDAK BOLEH KOSONG',
            ]);
        }

        $operatorId = auth()->id() ?? 1;
        $result = $this->scanSiapCorService->processScan($barcode, $operatorId, auth()->user());

        return response()->json($result);
    }
}
