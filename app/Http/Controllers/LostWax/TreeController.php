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
        ]);

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

        $trees = $treesQuery->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

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

        return view('lost-wax.trees.index', compact('trees', 'uniqueCodes', 'uniqueCustomers'));
    }

    public function generate(LostWaxWorkOrderPlan $plan)
    {
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
        $tree->load(['workOrder.itemReference', 'plan', 'printOrderLine.printOrder', 'printOrderLine.productionPlan']);

        return view('lost-wax.trees.show', compact('tree'));
    }

    public function update(Request $request, LostWaxTree $tree)
    {
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

    public function traveler(LostWaxTree $tree)
    {
        $tree->load(['workOrder.itemReference', 'plan', 'printOrderLine.printOrder', 'printOrderLine.productionPlan']);

        return view('lost-wax.trees.traveler', compact('tree'));
    }

    public function barcode(LostWaxTree $tree)
    {
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
}
