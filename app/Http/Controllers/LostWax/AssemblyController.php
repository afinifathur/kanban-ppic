<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AssemblyController extends Controller
{
    /**
     * Display listing of print order lines with available quantities for assembly.
     */
    public function index(Request $request)
    {
        // Get lines where actual outcomes have been recorded
        $query = \App\Models\LostWaxPrintOrderLine::with(['printOrder', 'trees'])
            ->whereNotNull('qty_actual_good');

        $scope = auth()->user()->product_scope;
        if (auth()->user()->hasRole('ppic') && $scope) {
            $query->whereHas('productionPlan', function ($q) use ($scope) {
                $q->where('product_scope', $scope);
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('item_name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('customer', 'like', "%{$search}%")
                    ->orWhereHas('printOrder', function ($q2) use ($search) {
                        $q2->where('print_order_number', 'like', "%{$search}%");
                    });
            });
        }

        // Fetch all lines first to filter by dynamic attribute available_qty
        // Fetch all lines first to filter by dynamic attribute available_qty
        $lines = $query->orderBy('id', 'desc')->get()->filter(function ($line) {
            return $line->qty_available_for_rangkai > 0;
        });

        // Paginate manually (since we filtered on collection)
        $page = $request->input('page', 1);
        $perPage = 15;
        $paginatedLines = new \Illuminate\Pagination\LengthAwarePaginator(
            $lines->forPage($page, $perPage)->values(),
            $lines->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // Fetch Rangkai Work Orders
        $woQuery = \App\Models\LostWaxRangkaiWorkOrder::with(['printOrderLine.printOrder', 'executions.trees']);
        if (auth()->user()->hasRole('ppic') && $scope) {
            $woQuery->whereHas('printOrderLine.productionPlan', function ($q) use ($scope) {
                $q->where('product_scope', $scope);
            });
        }
        $workOrders = $woQuery->orderBy('id', 'desc')->paginate(15, ['*'], 'wo_page');

        return view('lost-wax.assemblies.index', [
            'lines' => $paginatedLines,
            'workOrders' => $workOrders,
        ]);
    }

    /**
     * Store a new Rangkai Work Order.
     */
    public function storeWorkOrder(\App\Models\LostWaxPrintOrderLine $line, Request $request)
    {
        $this->authorizeLine($line);
        $request->validate([
            'qty_trees_planned' => 'required|integer|min:1',
            'tree_capacity' => 'required|integer|min:1',
            'require_layer_7' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);

        try {
            $service = app(\App\Services\RangkaiExecutionService::class);
            $service->createWorkOrder($line, [
                'qty_trees_planned' => $request->qty_trees_planned,
                'tree_capacity' => $request->tree_capacity,
                'require_layer_7' => $request->has('require_layer_7'),
                'notes' => $request->notes,
            ]);

            return redirect()->route('lost-wax.assemblies.index', ['tab' => 'work-orders'])
                ->with('success', 'Rangkai Work Order berhasil dibuat.');
        } catch (\Exception $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Show Rangkai Work Order detail and execution form.
     */
    public function showWorkOrder(\App\Models\LostWaxRangkaiWorkOrder $workOrder)
    {
        $this->authorizeWorkOrder($workOrder);
        $workOrder->load([
            'printOrderLine.printOrder',
            'executions.recorder',
            'executions.trees',
        ]);

        $line = $workOrder->printOrderLine;
        $proposed = [];
        $remaining = $workOrder->qty_outstanding;
        while ($remaining > 0) {
            $qty = min($workOrder->tree_capacity, $remaining);
            $proposed[] = $qty;
            $remaining -= $qty;
        }

        $families = config('lost_wax.families', []);
        $familyCode = $this->guessFamilyCode($line->aisi ?? '', $line->item_name);

        return view('lost-wax.assemblies.show_wo', compact(
            'workOrder',
            'line',
            'proposed',
            'families',
            'familyCode'
        ));
    }

    /**
     * Store Rangkai Execution and generate physical trees.
     */
    public function storeExecution(Request $request, \App\Models\LostWaxRangkaiWorkOrder $workOrder)
    {
        $this->authorizeWorkOrder($workOrder);
        $request->validate([
            'execution_date' => 'required|date',
            'trees_created' => 'required|integer|min:1',
            'quantities' => 'required|array|min:1',
            'quantities.*' => 'required|integer|min:1',
            'family_code' => 'required|string|max:10',
        ]);

        try {
            $service = app(\App\Services\RangkaiExecutionService::class);
            $service->recordExecution($workOrder, [
                'execution_date' => $request->execution_date,
                'trees_created' => $request->trees_created,
                'quantities' => $request->quantities,
                'family_code' => $request->family_code,
            ]);

            return redirect()->route('lost-wax.assemblies.work-orders.show', $workOrder)
                ->with('success', 'Hasil eksekusi rangkai berhasil dicatat.');
        } catch (\Exception $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    protected function authorizeWorkOrder(\App\Models\LostWaxRangkaiWorkOrder $workOrder)
    {
        $this->authorizeLine($workOrder->printOrderLine);
    }

    /**
     * Show preview screen for tree distribution before generation.
     */
    public function create(\App\Models\LostWaxPrintOrderLine $line, Request $request)
    {
        $this->authorizeLine($line);
        if ($line->qty_actual_good === null) {
            return redirect()->route('lost-wax.assemblies.index')
                ->with('error', 'Hasil cetak belum dicatat untuk item ini.');
        }

        $availableQty = $line->qty_available_for_rangkai;
        if ($availableQty <= 0) {
            return redirect()->route('lost-wax.assemblies.index')
                ->with('error', 'Seluruh hasil cetak item ini sudah dirangkai.');
        }

        $capacity = (int) $request->input('standard_tree_capacity', $line->standard_tree_capacity);
        if ($capacity < 1) {
            $capacity = 20;
        }

        // Distribute mathematically: remaining quantity sequentially split
        $remaining = $availableQty;
        $proposed = [];
        while ($remaining > 0) {
            $qty = min($capacity, $remaining);
            $proposed[] = $qty;
            $remaining -= $qty;
        }

        $families = config('lost_wax.families', []);
        $familyCode = $this->guessFamilyCode($line->aisi ?? '', $line->item_name);

        return view('lost-wax.assemblies.create', compact(
            'line',
            'availableQty',
            'capacity',
            'proposed',
            'families',
            'familyCode'
        ));
    }

    /**
     * Commit tree generation inside a row-locked transaction.
     */
    public function store(Request $request, \App\Models\LostWaxPrintOrderLine $line)
    {
        $this->authorizeLine($line);
        $request->validate([
            'quantities' => 'required|array|min:1',
            'quantities.*' => 'required|integer|min:1',
            'family_code' => 'required|string|max:10',
        ]);

        try {
            $trees = DB::transaction(function () use ($request, $line) {
                // 1. Lock the print order line row to prevent concurrent tree allocation races
                $lockedLine = \App\Models\LostWaxPrintOrderLine::lockForUpdate()->findOrFail($line->id);

                $availableQty = $lockedLine->qty_available_for_rangkai;
                $requestedQty = array_sum(array_map('intval', $request->quantities));

                if ($requestedQty > $availableQty) {
                    throw new \InvalidArgumentException("Total quantity tree ({$requestedQty} pcs) melebihi quantity tersedia ({$availableQty} pcs) untuk dirangkai.");
                }

                $familyCode = $request->family_code;
                $productionDate = \Carbon\Carbon::now(config('app.timezone'));
                $maxRetries = 5;
                $generatedTrees = [];

                for ($retry = 0; $retry < $maxRetries; $retry++) {
                    // Lock harian sequence untuk family_code ini pada production_date ini
                    $startingSeq = (int) (\App\Models\LostWaxTree::where('family_code', $familyCode)
                        ->whereDate('production_date', $productionDate->format('Y-m-d'))
                        ->max('daily_sequence') ?? 0);

                    $maxSeq = $startingSeq;
                    $currentTreeCount = \App\Models\LostWaxTree::where('lost_wax_print_order_line_id', $lockedLine->id)->count();

                    try {
                        $innerTrees = [];
                        foreach ($request->quantities as $quantity) {
                            $maxSeq++;
                            $currentTreeCount++;

                            $barcode = $familyCode
                                .$productionDate->format('dmy')
                                .str_pad((string) $maxSeq, 3, '0', STR_PAD_LEFT);

                            $tree = \App\Models\LostWaxTree::create([
                                'work_order_id' => null,
                                'work_order_plan_id' => null,
                                'lost_wax_print_order_line_id' => $lockedLine->id,
                                'barcode' => $barcode,
                                'tree_number' => $currentTreeCount,
                                'quantity' => (int) $quantity,
                                'status' => 'generated',
                                'production_date' => $productionDate->format('Y-m-d'),
                                'family_code' => $familyCode,
                                'daily_sequence' => $maxSeq,
                            ]);

                            $innerTrees[] = $tree;
                        }

                        $generatedTrees = $innerTrees;
                        break; // Success, escape retry loop
                    } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                        if ($retry === $maxRetries - 1) {
                            throw $e;
                        }
                        $generatedTrees = [];
                    }
                }

                return $generatedTrees;
            });

            $count = count($trees);

            return redirect()->route('lost-wax.trees.index')
                ->with('success', "{$count} Traveler Tree berhasil diterbitkan.");

        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Guess family code based on product AISI and name to minimize data entry clicks.
     */
    protected function guessFamilyCode(string $aisi, string $itemName): string
    {
        $aisiClean = trim(str_replace('SS', '', strtoupper($aisi)));
        $itemNameLower = strtolower($itemName);

        if (str_contains($itemNameLower, 'flange') || str_contains($itemNameLower, 'blind')) {
            if ($aisiClean === '316') {
                return '4'; // Flange SS316
            }

            return '3'; // Flange SS304
        }

        if ($aisiClean === '316') {
            return '2'; // Fitting SS316
        }

        return '1'; // Fitting SS304 (Default)
    }

    protected function authorizeLine(\App\Models\LostWaxPrintOrderLine $line)
    {
        $scope = auth()->user()->product_scope;
        if (auth()->user()->hasRole('ppic') && $scope) {
            $plan = $line->productionPlan;
            if (! $plan || $plan->product_scope !== $scope) {
                abort(403, 'Unauthorized.');
            }
        }
    }
}
