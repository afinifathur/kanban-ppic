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

    public function index()
    {
        $trees = LostWaxTree::with(['workOrder.itemReference', 'plan', 'printOrderLine.printOrder', 'printOrderLine.productionPlan'])
            ->orderByDesc('id')
            ->paginate(20);

        $families = config('lost_wax.families', []);

        return view('lost-wax.trees.index', compact('trees', 'families'));
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
