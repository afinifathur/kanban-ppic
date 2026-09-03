<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AssemblyController extends Controller
{
    /**
     * Display listing of print order lines with available quantities for assembly (Rangkai).
     */
    public function index(Request $request)
    {
        // Backward compatibility: redirect old tab query to dedicated Hasil Rangkai route
        if ($request->query('tab') === 'work-orders') {
            return redirect()->route('lost-wax.assemblies.work-orders.index', $request->except('tab'));
        }

        // Get lines where actual outcomes have been recorded
        $query = \App\Models\LostWaxPrintOrderLine::with(['printOrder', 'trees.allocations', 'treeAllocations.tree'])
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
                    })
                    ->orWhereHas('productionPlan', function ($q3) use ($search) {
                        $q3->where('item_name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%")
                            ->orWhere('customer', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('code')) {
            $code = $request->code;
            $query->where(function ($q) use ($code) {
                $q->where('code', 'like', "%{$code}%")
                    ->orWhereHas('productionPlan', function ($q2) use ($code) {
                        $q2->where('code', 'like', "%{$code}%");
                    });
            });
        }

        if ($request->filled('customer')) {
            $customer = $request->customer;
            $query->where(function ($q) use ($customer) {
                $q->where('customer', 'like', "%{$customer}%")
                    ->orWhereHas('productionPlan', function ($q2) use ($customer) {
                        $q2->where('customer', 'like', "%{$customer}%");
                    });
            });
        }

        if ($request->filled('size')) {
            $size = $request->size;
            $query->where(function ($q) use ($size) {
                $q->where('size', 'like', "%{$size}%")
                    ->orWhereHas('productionPlan', function ($q2) use ($size) {
                        $q2->where('size', 'like', "%{$size}%");
                    });
            });
        }

        // Suggestions for autocomplete datalists based on relevant matching candidates
        $suggestionBaseQuery = clone $query;

        $codeSuggestions = (clone $suggestionBaseQuery)
            ->whereNotNull('code')
            ->where('code', '!=', '')
            ->distinct()
            ->orderBy('code')
            ->limit(50)
            ->pluck('code');

        $customerSuggestions = (clone $suggestionBaseQuery)
            ->whereNotNull('customer')
            ->where('customer', '!=', '')
            ->distinct()
            ->orderBy('customer')
            ->limit(50)
            ->pluck('customer');

        $sizeSuggestions = (clone $suggestionBaseQuery)
            ->whereNotNull('size')
            ->where('size', '!=', '')
            ->distinct()
            ->orderBy('size')
            ->limit(50)
            ->pluck('size');

        $itemSuggestions = (clone $suggestionBaseQuery)
            ->whereNotNull('item_name')
            ->where('item_name', '!=', '')
            ->distinct()
            ->orderBy('item_name')
            ->limit(50)
            ->pluck('item_name');

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

        return view('lost-wax.assemblies.index', [
            'lines' => $paginatedLines,
            'codeSuggestions' => $codeSuggestions,
            'customerSuggestions' => $customerSuggestions,
            'sizeSuggestions' => $sizeSuggestions,
            'itemSuggestions' => $itemSuggestions,
        ]);
    }

    /**
     * Display listing of created Rangkai Work Orders (Hasil Rangkai).
     */
    public function workOrdersIndex(Request $request)
    {
        $scope = auth()->user()->product_scope;
        $woQuery = \App\Models\LostWaxRangkaiWorkOrder::with(['printOrderLine.printOrder', 'executions.trees']);

        if (auth()->user()->hasRole('ppic') && $scope) {
            $woQuery->whereHas('printOrderLine.productionPlan', function ($q) use ($scope) {
                $q->where('product_scope', $scope);
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $woQuery->whereHas('printOrderLine', function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('item_name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('customer', 'like', "%{$search}%")
                        ->orWhereHas('printOrder', function ($q3) use ($search) {
                            $q3->where('print_order_number', 'like', "%{$search}%");
                        });
                });
            });
        }

        if ($request->filled('code')) {
            $woQuery->whereHas('printOrderLine', function ($q) use ($request) {
                $q->where('code', $request->code);
            });
        }

        if ($request->filled('customer')) {
            $woQuery->whereHas('printOrderLine', function ($q) use ($request) {
                $q->where('customer', $request->customer);
            });
        }

        if ($request->filled('size')) {
            $woQuery->whereHas('printOrderLine', function ($q) use ($request) {
                $q->where('size', $request->size);
            });
        }

        $workOrders = $woQuery->orderBy('id', 'desc')->paginate(15);

        return view('lost-wax.assemblies.work_orders', [
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
            'qty_ordered' => 'required|integer|min:1',
            'standard_capacity_guide' => 'required|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        $service = app(\App\Services\RangkaiExecutionService::class);
        $totalPoolAvailable = $service->getTotalAvailablePool($line->code);
        if ($request->qty_ordered > $totalPoolAvailable) {
            return back()->withInput()->with('error', "Total rencana rangkai ({$request->qty_ordered} pcs) tidak boleh melebihi hasil cetak tersedia untuk Kode Produksi {$line->code} ({$totalPoolAvailable} pcs).");
        }

        try {
            $service->createWorkOrder($line, [
                'qty_trees_planned' => $request->qty_ordered,
                'tree_capacity' => 1, // Store 1 as compatibility bridge
                'standard_capacity_guide' => $request->standard_capacity_guide,
                'require_layer_7' => false,
                'notes' => $request->notes,
            ]);

            return redirect()->route('lost-wax.assemblies.work-orders.index')
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
            'printOrderLine.productionPlan',
            'executions.recorder',
            'executions.canceller',
            'executions.trees.scanEvents',
        ]);

        $line = $workOrder->printOrderLine;
        $proposed = [];
        $remaining = $workOrder->qty_outstanding;

        $capacity = $workOrder->tree_capacity === 1
            ? ($workOrder->standard_capacity_guide ?: 20)
            : $workOrder->tree_capacity;

        while ($remaining > 0) {
            $qty = min($capacity, $remaining);
            $proposed[] = $qty;
            $remaining -= $qty;
        }

        $families = config('lost_wax.families', []);
        $familyCode = $this->guessFamilyCode($line->aisi ?? '', $line->item_name);

        $productCode = $line->productionPlan?->item_code ?? $line->code;
        $productName = $line->item_name ?? $line->productionPlan?->item_name;

        $photoService = app(\App\Services\AssemblyPhotoService::class);
        $assemblyPhoto = $photoService->getCurrentPhoto($productCode, $productName);

        return view('lost-wax.assemblies.show_wo', compact(
            'workOrder',
            'line',
            'proposed',
            'families',
            'familyCode',
            'capacity',
            'productName',
            'productCode',
            'assemblyPhoto'
        ));
    }

    /**
     * Print Rangkai Work Order (A5 landscape form) on A4 portrait media.
     */
    public function printWorkOrder(\App\Models\LostWaxRangkaiWorkOrder $workOrder)
    {
        $this->authorizeWorkOrder($workOrder);
        $workOrder->load([
            'printOrderLine.printOrder',
            'printOrderLine.productionPlan',
        ]);
        $line = $workOrder->printOrderLine;
        $availableQty = $line->qty_available_for_rangkai;

        $productCode = $line->productionPlan?->item_code ?? $line->code;
        $productName = $line->item_name ?? $line->productionPlan?->item_name;

        $photoService = app(\App\Services\AssemblyPhotoService::class);
        $assemblyPhoto = $photoService->getCurrentPhoto($productCode, $productName);

        return view('lost-wax.assemblies.print_wo', compact('workOrder', 'line', 'availableQty', 'assemblyPhoto'));
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
                ->with('success', 'Hasil eksekusi rangkai berhasil dicatat dan Traveler berhasil diterbitkan.');
        } catch (\Exception $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Cancel an issued Rangkai Execution (Traveler) before Layer 1 scan.
     */
    public function cancelExecution(Request $request, \App\Models\LostWaxRangkaiExecution $execution)
    {
        $this->authorizeWorkOrder($execution->workOrder);
        $request->validate([
            'cancellation_reason' => 'required|string|max:500',
        ]);

        try {
            $service = app(\App\Services\RangkaiExecutionService::class);
            $service->cancelExecution($execution, $request->cancellation_reason, auth()->user());

            return redirect()->route('lost-wax.assemblies.work-orders.show', $execution->workOrder)
                ->with('success', 'Traveler eksekusi tanggal '.$execution->execution_date->format('d-m-Y').' berhasil dibatalkan.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Close a Rangkai Work Order with shortage.
     */
    public function closeShortage(Request $request, \App\Models\LostWaxRangkaiWorkOrder $workOrder)
    {
        $this->authorizeWorkOrder($workOrder);
        $request->validate([
            'closure_reason' => 'required|string|max:500',
        ]);

        try {
            $service = app(\App\Services\RangkaiExecutionService::class);
            $service->closeWorkOrderWithShortage($workOrder, $request->closure_reason, auth()->user());

            return redirect()->route('lost-wax.assemblies.work-orders.show', $workOrder)
                ->with('success', 'Work Order berhasil ditutup dengan status Shortage.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Close excess balance on a Print Order Line.
     */
    public function closeExcess(Request $request, \App\Models\LostWaxPrintOrderLine $line)
    {
        $this->authorizeLine($line);
        $request->validate([
            'qty_to_close' => 'required|integer|min:1',
        ]);

        try {
            $service = app(\App\Services\RangkaiExecutionService::class);
            $service->closeExcessBalance($line, (int) $request->qty_to_close);

            return redirect()->route('lost-wax.assemblies.index')
                ->with('success', 'Saldo excess berhasil ditutup (closed/recycled).');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
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

        $service = app(\App\Services\RangkaiExecutionService::class);
        $totalPoolAvailable = $service->getTotalAvailablePool($line->code);
        if ($totalPoolAvailable <= 0) {
            return redirect()->route('lost-wax.assemblies.index')
                ->with('error', 'Seluruh hasil cetak untuk kode produksi ini sudah dirangkai.');
        }

        $poolLines = $service->getAvailableLinesByProductionCode($line->code)->filter(function ($l) {
            return $l->qty_available_for_rangkai > 0;
        });

        $capacity = (int) $request->input('standard_tree_capacity', $line->standard_tree_capacity);
        if ($capacity < 1) {
            $capacity = 20;
        }

        // Distribute mathematically: pool available quantity sequentially split
        $remaining = $totalPoolAvailable;
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
            'totalPoolAvailable',
            'poolLines',
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
