<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use App\Models\LostWaxTree;
use App\Models\LostWaxWorkOrderPlan;
use App\Services\TreeGenerationService;
use Illuminate\Http\Request;
use Picqer\Barcode\BarcodeGeneratorPNG;

class TreeController extends Controller
{
    public function __construct(private readonly TreeGenerationService $treeService) {}

    public function index(Request $request)
    {
        $treesQuery = LostWaxTree::with([
            'workOrder.itemReference',
            'plan',
            'printOrderLine.printOrder',
            'printOrderLine.productionPlan',
            'coatingRack',
        ])->where('status', '!=', 'cancelled');

        $scope = auth()->user()->product_scope;
        $isPpic = auth()->user()->hasRole('ppic');

        if ($isPpic && $scope) {
            $treesQuery->whereHas('printOrderLine.productionPlan', function ($q) use ($scope) {
                $q->where('product_scope', $scope);
            });
        }

        if ($request->filled('barcode')) {
            $val = $request->barcode;
            $treesQuery->where(function ($q) use ($val) {
                $q->where('barcode', 'like', '%'.$val.'%')
                    ->orWhereHas('printOrderLine', fn ($qp) => $qp->where('code', 'like', '%'.$val.'%'))
                    ->orWhereHas('workOrder', fn ($qw) => $qw->where('et_code', 'like', '%'.$val.'%'));
            });
        }

        if ($request->filled('code')) {
            $val = $request->code;
            $treesQuery->where(function ($q) use ($val) {
                $q->whereHas('printOrderLine', fn ($qp) => $qp->where('code', 'like', '%'.$val.'%'))
                    ->orWhereHas('workOrder', fn ($qw) => $qw->where('et_code', 'like', '%'.$val.'%'));
            });
        }

        if ($request->filled('customer')) {
            $val = $request->customer;
            $treesQuery->where(function ($q) use ($val) {
                $q->whereHas('printOrderLine', fn ($qp) => $qp->where('customer', 'like', '%'.$val.'%'))
                    ->orWhereHas('workOrder', fn ($qw) => $qw->where('customer_name', 'like', '%'.$val.'%'));
            });
        }

        if ($request->filled('item')) {
            $val = $request->item;
            $treesQuery->where(function ($q) use ($val) {
                $q->whereHas('printOrderLine', fn ($qp) => $qp->where('item_name', 'like', '%'.$val.'%'))
                    ->orWhereHas('workOrder.itemReference', fn ($qi) => $qi->where('item_name_snapshot', 'like', '%'.$val.'%'));
            });
        }

        if ($request->filled('rack_id')) {
            if ($request->rack_id === 'none' || $request->rack_id === 'null') {
                $treesQuery->whereNull('rack_id');
            } else {
                $treesQuery->where('rack_id', $request->rack_id);
            }
        }

        $trees = $treesQuery->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        if ($isPpic && $scope) {
            $uniqueCodes = \DB::table('lost_wax_print_order_lines')
                ->join('production_plans', 'lost_wax_print_order_lines.production_plan_id', '=', 'production_plans.id')
                ->where('production_plans.product_scope', $scope)
                ->whereNotNull('lost_wax_print_order_lines.code')
                ->distinct()
                ->pluck('lost_wax_print_order_lines.code')
                ->toArray();

            $uniqueCustomers = \DB::table('lost_wax_print_order_lines')
                ->join('production_plans', 'lost_wax_print_order_lines.production_plan_id', '=', 'production_plans.id')
                ->where('production_plans.product_scope', $scope)
                ->whereNotNull('lost_wax_print_order_lines.customer')
                ->distinct()
                ->pluck('lost_wax_print_order_lines.customer')
                ->toArray();
        } else {
            $uniqueCodes = cache()->remember('lost_wax_trees_unique_codes', 60, function () {
                $newCodes = \DB::table('lost_wax_print_order_lines')->whereNotNull('code')->distinct()->pluck('code')->toArray();
                $legacyCodes = \DB::table('lost_wax_work_orders')->whereNotNull('et_code')->distinct()->pluck('et_code')->toArray();

                return array_unique(array_filter(array_merge($newCodes, $legacyCodes)));
            });

            $uniqueCustomers = cache()->remember('lost_wax_trees_unique_customers', 60, function () {
                $newCusts = \DB::table('lost_wax_print_order_lines')->whereNotNull('customer')->distinct()->pluck('customer')->toArray();
                $legacyCusts = \DB::table('lost_wax_work_orders')->whereNotNull('customer_name')->distinct()->pluck('customer_name')->toArray();

                return array_unique(array_filter(array_merge($newCusts, $legacyCusts)));
            });
        }

        $coatingRacks = \App\Models\LostWaxCoatingRack::where('status', 'active')
            ->orderBy('rack_number', 'asc')
            ->get();

        $rackCounts = \App\Models\LostWaxTree::whereNotNull('rack_id')
            ->groupBy('rack_id')
            ->select('rack_id', \DB::raw('count(*) as total'))
            ->pluck('total', 'rack_id')
            ->toArray();

        return view('lost-wax.trees.index', compact('trees', 'uniqueCodes', 'uniqueCustomers', 'coatingRacks', 'rackCounts'));
    }

    public function generate(LostWaxWorkOrderPlan $plan)
    {
        $scope = auth()->user()->product_scope;
        if (auth()->user()->hasRole('ppic') && $scope) {
            abort(403, 'Unauthorized.');
        }

        $plan->load('workOrder.itemReference');
        $workOrder = $plan->workOrder;

        $availableQty = $this->treeService->getRemainingQuantity($workOrder);

        if ($availableQty <= 0) {
            return redirect()->route('lost-wax.work-orders.show', $workOrder)
                ->with('error', 'Tidak ada quantity assembly tersedia untuk dialokasikan ke Tree.');
        }

        $defaultQtyPerTree = 15;

        $familyCode = $workOrder->family_code;
        $families = config('lost_wax.families', []);

        $proposed = $this->treeService->calculateProposedTrees($availableQty, $defaultQtyPerTree);
        $proposedCount = count($proposed);
        $proposedTotal = array_sum($proposed);
        $remaining = $availableQty - $proposedTotal;

        return view('lost-wax.trees.generate', compact(
            'plan',
            'workOrder',
            'availableQty',
            'defaultQtyPerTree',
            'familyCode',
            'families',
            'proposed',
            'proposedCount',
            'proposedTotal',
            'remaining'
        ));
    }

    public function store(Request $request, LostWaxWorkOrderPlan $plan)
    {
        $scope = auth()->user()->product_scope;
        if (auth()->user()->hasRole('ppic') && $scope) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'default_qty' => 'required|integer|min:1',
            'quantities' => 'required|array|min:1',
            'quantities.*' => 'required|integer|min:1',
            'family_code' => 'required|string|max:10',
        ]);

        $plan->load('workOrder');

        try {
            $trees = $this->treeService->generate(
                $plan,
                (int) $validated['default_qty'],
                array_map('intval', $validated['quantities']),
                $validated['family_code']
            );

            $count = count($trees);
            $workOrder = $plan->workOrder;

            return redirect()->route('lost-wax.work-orders.show', $workOrder)
                ->with('success', "{$count} Tree berhasil dibuat untuk Wave ".str_pad((string) $plan->wave_number, 3, '0', STR_PAD_LEFT).'.');
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(LostWaxTree $tree)
    {
        $this->authorizeTree($tree);
        $tree->load([
            'workOrder.itemReference',
            'plan',
            'printOrderLine.printOrder',
            'printOrderLine.productionPlan',
            'coatingRack',
            'allocations.printOrderLine.printOrder',
            'defects.recordedBy',
        ]);

        $events = \App\Models\LostWaxScanEvent::with(['operator', 'void.voidedByUser'])
            ->where('tree_id', $tree->id)
            ->orderBy('scanned_at', 'asc')
            ->get();

        $defects = $tree->defects()
            ->with('recordedBy')
            ->orderByRaw('COALESCE(occurred_at, created_at) DESC')
            ->orderByDesc('id')
            ->get();

        return view('lost-wax.trees.show', compact('tree', 'events', 'defects'));
    }

    public function storeDefect(Request $request, LostWaxTree $tree)
    {
        $this->authorizeTree($tree);

        $validated = $request->validate([
            'stage' => 'required|string|in:assembly,layer_1,layer_2,layer_3,layer_4,layer_5,layer_6,layer_7,oven',
            'defect_qty' => 'required|integer|min:1',
            'defect_reason' => 'required|string|max:100',
            'notes' => 'nullable|string|max:500',
            'occurred_at' => 'nullable|date',
        ]);

        try {
            $occurredAt = ! empty($validated['occurred_at'])
                ? \Carbon\Carbon::parse($validated['occurred_at'])
                : null;

            $qualityService = app(\App\Services\LostWaxQualityService::class);
            $qualityService->recordDefect(
                tree: $tree,
                stage: $validated['stage'],
                defectQty: (int) $validated['defect_qty'],
                defectReason: $validated['defect_reason'],
                notes: $validated['notes'] ?? null,
                occurredAt: $occurredAt,
                userId: auth()->id()
            );

            return back()->with('success', "Defect sebanyak {$validated['defect_qty']} pcs berhasil dicatat.");
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function update(Request $request, LostWaxTree $tree)
    {
        $this->authorizeTree($tree);
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        try {
            $this->treeService->adjustQuantity($tree, (int) $validated['quantity']);

            return back()->with('success', 'Quantity Tree berhasil diperbarui.');
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function traveler(Request $request, LostWaxTree $tree)
    {
        if ($request->has('ids')) {
            $ids = array_filter(explode(',', $request->input('ids')));
        } else {
            $ids = [$tree->id];
        }

        $allTrees = \App\Models\LostWaxTree::with([
            'workOrder.itemReference',
            'plan',
            'printOrderLine.printOrder',
            'printOrderLine.productionPlan',
            'coatingRack',
        ])
            ->whereIn('id', $ids)
            ->where('status', '!=', 'cancelled')
            ->get();

        $validTrees = [];
        $skippedBarcodes = [];

        foreach ($allTrees as $t) {
            $this->authorizeTree($t);
            if (is_null($t->rack_id)) {
                $skippedBarcodes[] = $t->barcode;
            } else {
                $validTrees[] = $t;
            }
        }

        $treesList = collect($validTrees);

        $warnings = [];
        if (count($skippedBarcodes) > 0) {
            $warnings[] = 'Rangkaian berikut dilewati karena belum memiliki Rack: '.implode(', ', $skippedBarcodes);
        }

        return view('lost-wax.trees.traveler', compact('treesList', 'warnings'));
    }

    public function printThermal(Request $request)
    {
        $request->validate([
            'ids' => 'required|string',
        ]);

        $ids = array_filter(explode(',', $request->ids));
        if (empty($ids)) {
            return response()->json(['success' => false, 'message' => 'Tidak ada Rangkaian terpilih.'], 400);
        }

        $trees = [];
        foreach ($ids as $id) {
            $tree = \App\Models\LostWaxTree::with([
                'workOrder.itemReference',
                'plan',
                'printOrderLine.printOrder',
                'printOrderLine.productionPlan',
                'coatingRack',
            ])->find($id);

            if (! $tree) {
                return response()->json(['success' => false, 'message' => "Rangkaian dengan ID {$id} tidak ditemukan."], 404);
            }

            if ($tree->status === 'cancelled') {
                return response()->json(['success' => false, 'message' => "Rangkaian {$tree->barcode} sudah dibatalkan."], 422);
            }

            $this->authorizeTree($tree);
            $trees[] = $tree;
        }

        $validTrees = [];
        $skippedTrees = [];

        foreach ($trees as $tree) {
            if (is_null($tree->rack_id)) {
                $skippedTrees[] = [
                    'barcode' => $tree->barcode,
                    'reason' => 'Nomor Rack belum diisi.',
                ];
            } else {
                $validTrees[] = $tree;
            }
        }

        $printerName = config('lost_wax.printer_name', 'TSC TE200');
        $tsplRenderer = new \App\Services\Barcode\Renderers\TsplRenderer;
        $printJobService = new \App\Services\Barcode\PrintJobService;

        foreach ($validTrees as $tree) {
            $payloadTspl = $tsplRenderer->render($tree);
            $job = $printJobService->createTscJob(
                $payloadTspl,
                $printerName,
                'TRAVELER_LABEL_90X50',
                1
            );
            $job->update(['tree_id' => $tree->id]);
        }

        $printedCount = count($validTrees);
        $skippedCount = count($skippedTrees);

        if ($printedCount > 0 && $skippedCount > 0) {
            $message = "{$printedCount} traveler berhasil diproses. {$skippedCount} tree dilewati karena belum memiliki Rack.";
        } elseif ($printedCount > 0) {
            $message = "{$printedCount} Rangkaian masuk antrean printer thermal.";
        } else {
            $message = "Tidak ada Rangkaian yang dicetak. {$skippedCount} tree dilewati karena belum memiliki Rack.";
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'printed_count' => $printedCount,
            'skipped_count' => $skippedCount,
            'printed' => collect($validTrees)->pluck('barcode')->toArray(),
            'skipped' => $skippedTrees,
        ]);
    }

    public function updateRack(Request $request, LostWaxTree $tree)
    {
        $this->authorizeTree($tree);

        $validated = $request->validate([
            'rack_id' => 'nullable|exists:lost_wax_coating_racks,id',
        ]);

        if ($validated['rack_id']) {
            $rack = \App\Models\LostWaxCoatingRack::find($validated['rack_id']);
            if (! $rack || $rack->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Rack tidak aktif atau tidak ditemukan.',
                ], 422);
            }
        }

        $tree->update([
            'rack_id' => $validated['rack_id'],
            'rack_assigned_at' => $validated['rack_id'] ? now() : null,
        ]);

        $count = $validated['rack_id']
            ? \App\Models\LostWaxTree::where('rack_id', $validated['rack_id'])->count()
            : 0;

        $rackLabel = $tree->coatingRack
            ? 'RAK-'.str_pad($tree->coatingRack->rack_number, 2, '0', STR_PAD_LEFT)
            : '-';

        return response()->json([
            'success' => true,
            'message' => $validated['rack_id']
                ? "Tree berhasil ditempatkan ke {$rackLabel}."
                : 'Tree berhasil dikeluarkan dari rak.',
            'rack_label' => $rackLabel,
            'is_over_capacity' => $count > 30,
            'count' => $count,
        ]);
    }

    public function bulkUpdateRack(Request $request)
    {
        $validated = $request->validate([
            'tree_ids' => 'required|array',
            'tree_ids.*' => 'required|integer',
            'rack_id' => 'required|exists:lost_wax_coating_racks,id',
        ]);

        $rack = \App\Models\LostWaxCoatingRack::find($validated['rack_id']);
        if (! $rack || $rack->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Rack tidak aktif atau tidak ditemukan.',
            ], 422);
        }

        $successList = [];
        $failList = [];

        \DB::transaction(function () use ($validated, &$successList, &$failList) {
            foreach ($validated['tree_ids'] as $id) {
                $tree = \App\Models\LostWaxTree::find($id);
                if (! $tree) {
                    $failList[] = [
                        'id' => $id,
                        'barcode' => 'ID '.$id,
                        'reason' => 'Tree tidak ditemukan.',
                    ];

                    continue;
                }

                try {
                    $this->authorizeTree($tree);

                    $tree->update([
                        'rack_id' => $validated['rack_id'],
                        'rack_assigned_at' => now(),
                    ]);

                    $successList[] = $tree->barcode;
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                    $failList[] = [
                        'id' => $id,
                        'barcode' => $tree->barcode,
                        'reason' => 'Tidak memiliki akses (Unauthorized).',
                    ];
                }
            }
        });

        $successCount = count($successList);
        $failCount = count($failList);

        $rackLabel = 'RAK-'.str_pad($rack->rack_number, 2, '0', STR_PAD_LEFT);

        if ($successCount > 0 && $failCount > 0) {
            $message = "{$successCount} tree berhasil ditempatkan ke {$rackLabel}. {$failCount} tree gagal.";
        } elseif ($successCount > 0) {
            $message = "{$successCount} tree berhasil ditempatkan ke {$rackLabel}.";
        } else {
            $message = 'Gagal memproses bulk assignment.';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'success_list' => $successList,
            'failures' => $failList,
        ]);
    }

    public function barcode(LostWaxTree $tree)
    {
        $this->authorizeTree($tree);
        $generator = new BarcodeGeneratorPNG;

        $barcodeData = $generator->getBarcode($tree->barcode, $generator::TYPE_CODE_128, 2, 60);

        return response($barcodeData)->header('Content-Type', 'image/png');
    }

    public function barcodeByValue($value)
    {
        $generator = new BarcodeGeneratorPNG;

        $barcodeData = $generator->getBarcode($value, $generator::TYPE_CODE_128, 2, 60);

        return response($barcodeData)->header('Content-Type', 'image/png');
    }

    protected function authorizeTree(LostWaxTree $tree)
    {
        $scope = auth()->user()->product_scope;
        if (auth()->user()->hasRole('ppic') && $scope) {
            $plan = $tree->printOrderLine?->productionPlan;
            if (! $plan || $plan->product_scope !== $scope) {
                abort(403, 'Unauthorized.');
            }
        }
    }
}
