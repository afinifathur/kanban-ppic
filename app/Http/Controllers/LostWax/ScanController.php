<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Services\ScanService;
use Illuminate\Http\Request;

class ScanController extends Controller
{
    public function __construct(private readonly ScanService $scanService) {}

    public function index()
    {
        return view('lost-wax.scan.index');
    }

    public function process(Request $request)
    {
        $barcode = trim($request->input('barcode', ''));
        $expectedStage = trim($request->input('expected_stage', ''));

        if ($barcode === '') {
            return response()->json([
                'success' => false,
                'reason' => 'Barcode tidak boleh kosong.',
            ]);
        }

        $tree = LostWaxTree::with(['workOrder', 'printOrderLine.printOrder', 'printOrderLine.productionPlan'])->where('barcode', $barcode)->first();

        if (! $tree) {
            return response()->json([
                'success' => false,
                'reason' => 'Barcode tidak ditemukan.',
            ]);
        }

        $scope = auth()->user()->product_scope;
        if (auth()->user()->hasRole('ppic') && $scope) {
            $plan = $tree->printOrderLine?->productionPlan;
            if (! $plan || $plan->product_scope !== $scope) {
                return response()->json([
                    'success' => false,
                    'reason' => 'Unauthorized product scope.',
                ]);
            }
        }

        $nextStage = $this->scanService->getNextExpectedStage($tree);

        if (! $nextStage) {
            return response()->json([
                'success' => false,
                'reason' => 'Tree sudah menyelesaikan semua tahapan.',
                'tree' => $this->treeInfo($tree),
            ]);
        }

        if ($expectedStage !== '' && $expectedStage !== $nextStage) {
            $reason = sprintf(
                'Tree saat ini berada di %s. Lapisan berikutnya harus %s.',
                $tree->current_stage_label,
                config('lost_wax.stages.'.$nextStage, $nextStage)
            );

            return response()->json(
                $this->scanService->rejectSkippedScan($tree, auth()->id() ?? 1, $expectedStage ?: $nextStage, $reason)
            );
        }

        $result = $this->scanService->process($barcode, auth()->id() ?? 1);

        if ($result['success']) {
            $result['tree_info'] = $this->treeInfo($result['tree']);
            $result['next_stage'] = $result['tree']->nextStage();
            $result['next_stage_label'] = $result['next_stage']
                ? (config('lost_wax.stages.'.$result['next_stage'], $result['next_stage']))
                : null;
        }

        return response()->json($result);
    }

    public function history(LostWaxTree $tree)
    {
        $scope = auth()->user()->product_scope;
        if (auth()->user()->hasRole('ppic') && $scope) {
            $plan = $tree->printOrderLine?->productionPlan;
            if (! $plan || $plan->product_scope !== $scope) {
                abort(403, 'Unauthorized.');
            }
        }

        $tree->load(['workOrder.itemReference', 'workOrder.plans', 'printOrderLine.printOrder', 'printOrderLine.productionPlan']);

        $events = LostWaxScanEvent::with(['operator', 'void.voidedByUser'])
            ->where('tree_id', $tree->id)
            ->orderBy('scanned_at')
            ->get();

        return view('lost-wax.trees.history', compact('tree', 'events'));
    }

    public function stageLabel(Request $request)
    {
        $stage = $request->input('stage', '');
        $stages = config('lost_wax.stages', []);

        return response()->json(['label' => $stages[$stage] ?? $stage]);
    }

    public function scanOven()
    {
        return view('lost-wax.scan-oven.index');
    }

    public function processOven(Request $request)
    {
        $barcode = trim($request->input('barcode', ''));

        if ($barcode === '') {
            return response()->json([
                'success' => false,
                'reason' => 'Barcode tidak boleh kosong.',
            ]);
        }

        $tree = LostWaxTree::with(['workOrder', 'printOrderLine.printOrder', 'printOrderLine.productionPlan'])->where('barcode', $barcode)->first();

        if (! $tree) {
            return response()->json([
                'success' => false,
                'reason' => 'Barcode tidak ditemukan.',
            ]);
        }

        $scope = auth()->user()->product_scope;
        if (auth()->user()->hasRole('ppic') && $scope) {
            $plan = $tree->printOrderLine?->productionPlan;
            if (! $plan || $plan->product_scope !== $scope) {
                return response()->json([
                    'success' => false,
                    'reason' => 'Unauthorized product scope.',
                ]);
            }
        }

        $result = $this->scanService->processOvenScan($barcode, auth()->id() ?? 1);

        if ($result['success']) {
            $result['tree_info'] = $this->treeInfo($result['tree']);
        }

        return response()->json($result);
    }

    /**
     * Void the given scan event.
     */
    public function voidEvent(Request $request, \App\Models\LostWaxScanEvent $event)
    {
        if (! auth()->user()->hasRole('ppic') && ! auth()->user()->hasRole('admin')) {
            abort(403, 'Anda tidak memiliki hak akses untuk membatalkan scan event ini.');
        }

        $request->validate([
            'void_reason' => 'required|string',
        ]);

        try {
            $service = app(\App\Services\ScanVoidService::class);
            $service->void($event, $request->void_reason, auth()->id());

            return back()->with('success', 'Scan event berhasil dibatalkan (void).');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    private function treeInfo(LostWaxTree $tree): array
    {
        $tree->load(['workOrder.itemReference', 'printOrderLine.printOrder', 'printOrderLine.productionPlan']);

        return [
            'id' => $tree->id,
            'barcode' => $tree->barcode,
            'tree_number' => $tree->tree_number,
            'quantity' => $tree->quantity,
            'et_code' => $tree->getSourceCode() ?? '-',
            'item_code' => $tree->getSourceItemCode() ?? '-',
            'item_name' => $tree->getSourceProduct() ?? '-',
            'current_stage' => $tree->current_stage,
            'current_stage_label' => $tree->current_stage_label,
            'last_scan_at' => $tree->last_scan_at?->format('H:i:s'),
        ];
    }
}
