<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrintOrderController extends Controller
{
    /**
     * Display a listing of plans and print orders.
     */
    public function plans(Request $request)
    {
        $scope = auth()->user()->product_scope;
        $isPpic = auth()->user()->hasRole('ppic');

        // 1. Datalists for Autocomplete Search
        $uniqueCodesQuery = \App\Models\ProductionPlan::whereNotNull('code')->where('code', '!=', '');
        if ($isPpic && $scope) {
            $uniqueCodesQuery->where('product_scope', $scope);
        }
        $uniqueCodes = $uniqueCodesQuery->distinct()->orderBy('code')->pluck('code');

        $uniqueCustomersQuery = \App\Models\ProductionPlan::whereNotNull('customer')->where('customer', '!=', '');
        if ($isPpic && $scope) {
            $uniqueCustomersQuery->where('product_scope', $scope);
        }
        $uniqueCustomers = $uniqueCustomersQuery->distinct()->orderBy('customer')->pluck('customer');

        // 2. Rencana Cetak (Plan Items)
        $plansQuery = \App\Models\ProductionPlan::query()
            ->withSum(['printOrderLines as qty_scheduled' => function ($query) {
                $query->whereHas('printOrder', function ($q) {
                    $q->whereIn('status', ['DRAFT', 'ISSUED']);
                });
            }], 'qty_ordered');

        if ($isPpic && $scope) {
            $plansQuery->where('product_scope', $scope);
        }

        if ($request->filled('date')) {
            $plansQuery->whereDate('created_at', $request->date);
        }

        if ($request->filled('customer')) {
            $plansQuery->where('customer', 'like', '%'.$request->customer.'%');
        }

        if ($request->filled('code')) {
            $plansQuery->where('code', 'like', '%'.$request->code.'%');
        }

        $statusFilter = $request->input('status', 'active');
        if ($statusFilter === 'closed') {
            $plansQuery->where('is_closed', true);
        } elseif ($statusFilter === 'all') {
            // No filter on is_closed or remaining qty for 'Semua'
        } else {
            // Default: 'active'
            $plansQuery->where('is_closed', false);

            $subquery = DB::table('lost_wax_print_order_lines')
                ->join('lost_wax_print_orders', 'lost_wax_print_order_lines.lost_wax_print_order_id', '=', 'lost_wax_print_orders.id')
                ->whereColumn('lost_wax_print_order_lines.production_plan_id', 'production_plans.id')
                ->whereIn('lost_wax_print_orders.status', ['DRAFT', 'ISSUED'])
                ->selectRaw('COALESCE(SUM(lost_wax_print_order_lines.qty_ordered), 0)');

            $plansQuery->whereRaw('qty_planned > ('.$subquery->toSql().')', $subquery->getBindings());
        }

        $plans = $plansQuery->orderBy('id', 'desc')
            ->paginate(50, ['*'], 'plans_page')
            ->withQueryString();

        // 3. Dokumen Perintah Cetak (Print Orders)
        $printOrdersQuery = \App\Models\LostWaxPrintOrder::with(['creator', 'lines']);

        if ($isPpic && $scope) {
            $printOrdersQuery->whereHas('lines.productionPlan', function ($q) use ($scope) {
                $q->where('product_scope', $scope);
            });
        }

        if ($request->filled('print_order_number')) {
            $printOrdersQuery->where('print_order_number', 'like', '%'.$request->print_order_number.'%');
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $printOrdersQuery->whereHas('lines', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('code', 'like', '%'.$search.'%')
                        ->orWhere('item_name', 'like', '%'.$search.'%')
                        ->orWhereHas('productionPlan', function ($p) use ($search) {
                            $p->where('code', 'like', '%'.$search.'%')
                                ->orWhere('item_name', 'like', '%'.$search.'%');
                        });
                });
            });
        }

        $printOrders = $printOrdersQuery->orderBy('id', 'desc')
            ->paginate(15, ['*'], 'orders_page')
            ->withQueryString();

        $activeTab = $request->query('tab');
        if (! in_array($activeTab, ['plans', 'orders', 'recovery'])) {
            $activeTab = $request->query('orders_page') ? 'orders' : ($request->query('recovery_page') ? 'recovery' : 'plans');
        }

        // 4. Recovery Pool Data Preparation
        $qualityService = app(\App\Services\LostWaxQualityService::class);

        $recoveryBaseQuery = \App\Models\ProductionPlan::query()
            ->whereHas('printOrderLines')
            ->with([
                'printOrderLines.executions',
                'printOrderLines.printOrder',
                'printOrderLines.trees.defects',
                'printOrderLines.treeAllocations.tree.defects',
            ]);

        if ($isPpic && $scope) {
            $recoveryBaseQuery->where('product_scope', $scope);
        }

        if ($request->filled('recovery_code')) {
            $recoveryBaseQuery->where('code', 'like', '%'.$request->recovery_code.'%');
        }

        if ($request->filled('recovery_customer')) {
            $recoveryBaseQuery->where('customer', 'like', '%'.$request->recovery_customer.'%');
        }

        $recoveryStatusFilter = $request->input('recovery_status', 'active');
        if ($recoveryStatusFilter === 'closed') {
            $recoveryBaseQuery->where('is_closed', true);
        } elseif ($recoveryStatusFilter === 'all') {
            // No filter on is_closed
        } else {
            // 'active' (Perlu Tindakan)
            $recoveryBaseQuery->where('is_closed', false);
        }

        $candidatePlans = $recoveryBaseQuery->orderBy('id', 'desc')->get();

        $recoveryItems = collect();
        $totalActiveRecoveryCount = 0;

        foreach ($candidatePlans as $plan) {
            $breakdown = $qualityService->getProductionPlanQuantityBreakdown($plan);

            $activeReprint = $plan->printOrderLines
                ->map->printOrder
                ->filter(function ($po) {
                    return $po && $po->order_type === 'REPRINT' && in_array($po->status, ['DRAFT', 'ISSUED']);
                })
                ->first();

            $hasDeficit = $breakdown['deficit_vs_plan'] > 0;
            $isNotNormal = $breakdown['status'] !== 'NORMAL';
            $needsRecovery = $hasDeficit || $isNotNormal || $activeReprint !== null;

            if (! $plan->is_closed && $needsRecovery) {
                $totalActiveRecoveryCount++;
            }

            if ($recoveryStatusFilter === 'active') {
                if (! $needsRecovery) {
                    continue;
                }
            }

            $recoveryItems->push((object) [
                'plan' => $plan,
                'breakdown' => $breakdown,
                'active_reprint' => $activeReprint,
            ]);
        }

        $perPage = 25;
        $currentPage = \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPage('recovery_page');
        $currentItems = $recoveryItems->slice(($currentPage - 1) * $perPage, $perPage)->values();
        $recoveryPlans = new \Illuminate\Pagination\LengthAwarePaginator(
            $currentItems,
            $recoveryItems->count(),
            $perPage,
            $currentPage,
            ['path' => \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'recovery_page']
        );
        $recoveryPlans->withQueryString();

        return view('lost-wax.print-orders.plans', compact(
            'plans',
            'printOrders',
            'recoveryPlans',
            'totalActiveRecoveryCount',
            'activeTab',
            'uniqueCodes',
            'uniqueCustomers'
        ));
    }

    /**
     * Alias for index (redirects to plans list dashboard).
     */
    public function index(Request $request)
    {
        return redirect()->route('lost-wax.print-orders.plans', $request->all());
    }

    /**
     * Show the form for creating a new print order.
     */
    public function create(Request $request)
    {
        $planIds = $this->normalizeSelectedPlanIds($request->input('plan_ids'));

        if (empty($planIds)) {
            return redirect()->route('lost-wax.print-orders.plans')
                ->with('error', 'Pilih minimal satu item rencana untuk membuat perintah cetak.');
        }

        $scope = auth()->user()->product_scope;
        $isPpic = auth()->user()->hasRole('ppic');

        // Only PPIC with a product_scope may create Print Orders.
        // Admin (role=admin, product_scope=null) is read-only.
        if (! ($isPpic && $scope)) {
            abort(403, 'Hanya PPIC owner yang dapat membuat Perintah Cetak.');
        }

        $plans = \App\Models\ProductionPlan::whereIn('id', $planIds)->get();

        if ($plans->count() !== count($planIds)) {
            return redirect()->route('lost-wax.print-orders.plans')
                ->with('error', 'Item rencana tidak ditemukan.');
        }

        foreach ($plans as $plan) {
            if ($plan->product_scope !== $scope) {
                abort(403, 'Unauthorized.');
            }

            if ($plan->is_closed || $plan->qty_remaining_scheduled <= 0) {
                return redirect()->route('lost-wax.print-orders.plans')
                    ->with('error', 'Item Production Plan ini sudah tidak aktif dan tidak dapat dibuat menjadi Perintah Cetak baru.');
            }
        }

        if ($plans->isEmpty()) {
            return redirect()->route('lost-wax.print-orders.plans')
                ->with('error', 'Item rencana tidak ditemukan.');
        }

        $date = $request->input('scheduled_date', date('Y-m-d'));
        $printOrderNumber = $this->generateNextPrintOrderNumber($date);

        return view('lost-wax.print-orders.create', compact('plans', 'printOrderNumber', 'date'));
    }

    /**
     * Store a newly created print order in storage.
     */
    public function store(Request $request)
    {
        $scope = auth()->user()->product_scope;
        $isPpic = auth()->user()->hasRole('ppic');

        if ($request->input('action') === 'close_plan') {
            // Only PPIC with a product_scope may close plans.
            if (! ($isPpic && $scope)) {
                abort(403, 'Hanya PPIC owner yang dapat menutup rencana produksi.');
            }
            $planId = $request->input('production_plan_id');
            $plan = \App\Models\ProductionPlan::findOrFail($planId);
            if ($plan->product_scope !== $scope) {
                abort(403, 'Unauthorized.');
            }
            $plan->update(['is_closed' => true]);

            return redirect()->back()->with('success', 'Rencana produksi '.$plan->code.' berhasil ditutup (CLOSED).');
        }

        if ($request->input('action') === 'open_plan') {
            // Only PPIC with a product_scope may reopen plans.
            if (! ($isPpic && $scope)) {
                abort(403, 'Hanya PPIC owner yang dapat membuka kembali rencana produksi.');
            }
            $planId = $request->input('production_plan_id');
            $plan = \App\Models\ProductionPlan::findOrFail($planId);
            if ($plan->product_scope !== $scope) {
                abort(403, 'Unauthorized.');
            }
            $plan->update(['is_closed' => false]);

            return redirect()->back()->with('success', 'Rencana produksi '.$plan->code.' berhasil dibuka kembali (OPEN).');
        }

        if ($request->input('action') === 'bulk_close_plans') {
            // Only PPIC with a product_scope may bulk-close plans.
            if (! ($isPpic && $scope)) {
                abort(403, 'Hanya PPIC owner yang dapat menutup rencana produksi.');
            }
            $planIds = $this->normalizeSelectedPlanIds($request->input('plan_ids'));
            if (empty($planIds)) {
                return redirect()->back()->with('error', 'Pilih minimal satu item rencana untuk ditutup.');
            }

            $plans = \App\Models\ProductionPlan::whereIn('id', $planIds)->get();
            if ($plans->isEmpty()) {
                return redirect()->back()->with('error', 'Rencana produksi tidak ditemukan.');
            }

            foreach ($plans as $plan) {
                if ($plan->product_scope !== $scope) {
                    abort(403, 'Unauthorized.');
                }
            }

            DB::transaction(function () use ($plans) {
                foreach ($plans as $plan) {
                    $plan->update(['is_closed' => true]);
                }
            });

            return redirect()->back()->with('success', count($plans).' rencana produksi berhasil ditutup (CLOSED).');
        }

        // Creating a new Print Order: only PPIC with product_scope is allowed.
        if (! ($isPpic && $scope)) {
            abort(403, 'Hanya PPIC owner yang dapat membuat Perintah Cetak.');
        }

        $items = collect($request->input('items', []))
            ->filter(function ($item) {
                return is_array($item) && isset($item['production_plan_id']);
            })
            ->unique('production_plan_id')
            ->values();

        $request->merge([
            'items' => $items->all(),
        ]);

        $request->validate([
            'scheduled_date' => 'required|date',
            'print_order_number' => 'required|string|unique:lost_wax_print_orders,print_order_number',
            'items' => 'required|array|min:1',
            'items.*.production_plan_id' => 'required|integer|exists:production_plans,id',
            'items.*.qty_ordered' => 'required|integer|min:1',
        ]);

        foreach ($request->items as $itemData) {
            $plan = \App\Models\ProductionPlan::findOrFail($itemData['production_plan_id']);
            if ($plan->product_scope !== $scope) {
                abort(403, 'Unauthorized.');
            }
            if ($plan->is_closed || $plan->qty_remaining_scheduled <= 0) {
                return redirect()->route('lost-wax.print-orders.plans')
                    ->with('error', 'Item Production Plan ini sudah tidak aktif dan tidak dapat dibuat menjadi Perintah Cetak baru.');
            }
        }

        $printOrder = DB::transaction(function () use ($request) {
            $order = \App\Models\LostWaxPrintOrder::create([
                'print_order_number' => $request->print_order_number,
                'scheduled_date' => $request->scheduled_date,
                'status' => 'DRAFT',
                'created_by' => auth()->id(),
            ]);

            foreach ($request->items as $itemData) {
                $plan = \App\Models\ProductionPlan::findOrFail($itemData['production_plan_id']);

                $order->lines()->create([
                    'production_plan_id' => $plan->id,
                    'qty_ordered' => $itemData['qty_ordered'],
                    'code' => $plan->code,
                    'customer' => $plan->customer,
                    'item_name' => $plan->item_name,
                    'size' => $plan->size,
                    'aisi' => $plan->aisi,
                ]);
            }

            return $order;
        });

        return redirect()->route('lost-wax.print-orders.show', $printOrder)
            ->with('success', 'Dokumen Perintah Cetak berhasil dibuat.');
    }

    /**
     * Display the specified print order.
     */
    public function show(\App\Models\LostWaxPrintOrder $printOrder)
    {
        $this->authorizePrintOrder($printOrder);
        $printOrder->load(['creator', 'lines.productionPlan', 'lines.executions.recorder', 'lines.trees']);

        return view('lost-wax.print-orders.show', compact('printOrder'));
    }

    /**
     * Show the form for editing the specified print order.
     */
    public function edit(\App\Models\LostWaxPrintOrder $printOrder)
    {
        $this->authorizePrintOrder($printOrder);
        if ($printOrder->status !== 'DRAFT') {
            return redirect()->route('lost-wax.print-orders.show', $printOrder)
                ->with('error', 'Hanya dokumen berstatus DRAFT yang dapat diedit.');
        }

        $printOrder->load('lines.productionPlan');

        $scope = auth()->user()->product_scope;
        $existingPlanIds = $printOrder->lines->pluck('production_plan_id')->filter()->all();

        $availablePlansQuery = \App\Models\ProductionPlan::query()
            ->where('is_closed', false)
            ->whereNotIn('id', $existingPlanIds);

        if (auth()->user()->hasRole('ppic') && $scope) {
            $availablePlansQuery->where('product_scope', $scope);
        }

        $availablePlans = $availablePlansQuery->orderBy('code')->get()->filter(function ($plan) {
            return $plan->qty_remaining_scheduled > 0;
        })->values();

        return view('lost-wax.print-orders.edit', compact('printOrder', 'availablePlans'));
    }

    /**
     * Update the specified print order in storage.
     */
    public function update(Request $request, \App\Models\LostWaxPrintOrder $printOrder)
    {
        $this->authorizePrintOrder($printOrder);
        if ($printOrder->status !== 'DRAFT') {
            return redirect()->route('lost-wax.print-orders.show', $printOrder)
                ->with('error', 'Hanya dokumen berstatus DRAFT yang dapat diperbarui.');
        }

        $request->validate([
            'scheduled_date' => 'required|date',
            'print_order_number' => 'required|string|unique:lost_wax_print_orders,print_order_number,'.$printOrder->id,
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:lost_wax_print_order_lines,id',
            'items.*.qty_ordered' => 'required|integer|min:1',
        ]);

        DB::transaction(function () use ($request, $printOrder) {
            $printOrder->update([
                'scheduled_date' => $request->scheduled_date,
                'print_order_number' => $request->print_order_number,
            ]);

            foreach ($request->items as $itemData) {
                $line = $printOrder->lines()->findOrFail($itemData['id']);
                $line->update([
                    'qty_ordered' => $itemData['qty_ordered'],
                ]);
            }
        });

        return redirect()->route('lost-wax.print-orders.show', $printOrder)
            ->with('success', 'Dokumen Perintah Cetak berhasil diperbarui.');
    }

    /**
     * Transition status of the print order.
     */
    public function updateStatus(Request $request, \App\Models\LostWaxPrintOrder $printOrder)
    {
        $this->authorizePrintOrder($printOrder);
        $targetStatus = $request->input('status');

        if (! in_array($targetStatus, ['DRAFT', 'ISSUED', 'CANCELLED'])) {
            return back()->with('error', 'Status target tidak valid.');
        }

        if ($printOrder->status === 'CANCELLED') {
            return back()->with('error', 'Dokumen yang sudah dibatalkan tidak dapat diubah kembali.');
        }

        if ($targetStatus === 'CANCELLED') {
            if ($printOrder->hasRecordedOutcomes()) {
                return back()->with('error', 'Dokumen tidak dapat dibatalkan karena hasil cetak sudah dicatat.');
            }
        }

        if ($targetStatus === 'ISSUED') {
            if ($printOrder->status !== 'DRAFT') {
                return back()->with('error', 'Hanya dokumen DRAFT yang dapat diterbitkan.');
            }
        }

        if ($targetStatus === 'DRAFT') {
            if ($printOrder->status === 'ISSUED') {
                return back()->with('error', 'Dokumen yang sudah diterbitkan tidak dapat dikembalikan ke DRAFT.');
            }
        }

        $printOrder->update(['status' => $targetStatus]);

        return back()->with('success', 'Status dokumen berhasil diubah menjadi '.$targetStatus.'.');
    }

    /**
     * Render the printable version of the print order.
     */
    public function print(\App\Models\LostWaxPrintOrder $printOrder)
    {
        $this->authorizePrintOrder($printOrder);
        $printOrder->load(['creator', 'lines']);

        // Continuation print: only lines with remaining outstanding quantity are printed.
        $printableLines = $printOrder->lines->filter(function ($line) {
            return $line->qty_outstanding > 0;
        })->values();

        if ($printableLines->isEmpty()) {
            return redirect()->route('lost-wax.print-orders.show', $printOrder)
                ->with('error', 'Seluruh item sudah selesai dicetak, tidak ada sisa yang perlu dicetak ulang.');
        }

        $printOrder->setRelation('lines', $printableLines);

        return view('lost-wax.print-orders.print', compact('printOrder'));
    }

    /**
     * Remove the specified print order from storage.
     */
    public function destroy(\App\Models\LostWaxPrintOrder $printOrder)
    {
        $this->authorizePrintOrder($printOrder);
        if ($printOrder->status !== 'DRAFT') {
            return redirect()->route('lost-wax.print-orders.show', $printOrder)
                ->with('error', 'Hanya dokumen berstatus DRAFT yang dapat dihapus.');
        }

        $printOrder->delete();

        return redirect()->route('lost-wax.print-orders.plans')
            ->with('success', 'Dokumen Perintah Cetak berhasil dihapus.');
    }

    /**
     * Add a single Production Plan line to an existing DRAFT print order.
     */
    public function storeLine(Request $request, \App\Models\LostWaxPrintOrder $printOrder)
    {
        $this->authorizePrintOrder($printOrder);

        if ($printOrder->status !== 'DRAFT') {
            abort(403, 'Hanya dokumen berstatus DRAFT yang dapat ditambahkan item baru.');
        }

        $request->validate([
            'production_plan_id' => 'required|integer|exists:production_plans,id',
            'qty_ordered' => 'required|integer|min:1',
        ]);

        $scope = auth()->user()->product_scope;
        $isPpic = auth()->user()->hasRole('ppic');

        if (! ($isPpic && $scope)) {
            abort(403, 'Hanya PPIC owner yang dapat menambahkan item ke Perintah Cetak.');
        }

        $plan = DB::transaction(function () use ($request, $printOrder, $scope) {
            $plan = \App\Models\ProductionPlan::lockForUpdate()->findOrFail($request->production_plan_id);

            if ($plan->product_scope !== $scope) {
                abort(403, 'Unauthorized.');
            }

            if ($plan->is_closed) {
                return back()->with('error', 'Item rencana produksi ini sudah ditutup (CLOSED).');
            }

            if ($printOrder->lines()->where('production_plan_id', $plan->id)->exists()) {
                return back()->with('error', 'Item rencana produksi ini sudah ada di dalam dokumen Perintah Cetak.');
            }

            $remaining = $plan->qty_remaining_scheduled;
            if ($request->qty_ordered > $remaining) {
                return back()->with('error', 'Kuantitas melebihi sisa yang belum dijadwalkan (Maks: '.number_format($remaining).' pcs).');
            }

            $printOrder->lines()->create([
                'production_plan_id' => $plan->id,
                'qty_ordered' => $request->qty_ordered,
                'code' => $plan->code,
                'customer' => $plan->customer,
                'item_name' => $plan->item_name,
                'size' => $plan->size,
                'aisi' => $plan->aisi,
            ]);

            return $plan;
        });

        if ($plan instanceof \Illuminate\Http\RedirectResponse) {
            return $plan;
        }

        return redirect()->route('lost-wax.print-orders.edit', $printOrder)
            ->with('success', 'Item '.$plan->code.' ('.number_format($request->qty_ordered).' pcs) berhasil ditambahkan ke Perintah Cetak.');
    }

    /**
     * Remove a single line from a DRAFT print order, releasing its
     * allocation back to the originating Production Plan.
     */
    public function destroyLine(\App\Models\LostWaxPrintOrder $printOrder, \App\Models\LostWaxPrintOrderLine $line)
    {
        $this->authorizePrintOrder($printOrder);

        if ($line->lost_wax_print_order_id !== $printOrder->id) {
            abort(404);
        }

        if ($printOrder->status !== 'DRAFT') {
            abort(403, 'Hanya item dari dokumen berstatus DRAFT yang dapat dihapus.');
        }

        $wasLastLine = $printOrder->lines()->count() <= 1;
        $code = $line->code;
        $qtyReleased = $line->qty_ordered;

        DB::transaction(function () use ($printOrder, $line, $wasLastLine) {
            $line->delete();

            // Reuse the existing draft-deletion semantics when no items remain:
            // never leave an orphaned empty DRAFT behind.
            if ($wasLastLine) {
                $printOrder->delete();
            }
        });

        if ($wasLastLine) {
            return redirect()->route('lost-wax.print-orders.plans')
                ->with('success', 'Semua item telah dihapus. Draft Perintah Cetak telah dihapus.');
        }

        return redirect()->route('lost-wax.print-orders.edit', $printOrder)
            ->with('success', 'Item '.$code.' berhasil dihapus. '.number_format($qtyReleased).' pcs telah dikembalikan ke Rencana Cetak.');
    }

    /**
     * Generate sequential print order number inside a concurrency-safe lock block.
     */
    protected function generateNextPrintOrderNumber($date)
    {
        return DB::transaction(function () use ($date) {
            $dateStr = str_replace('-', '', $date);

            // Lock the table for writing/reading for this date
            $lastOrder = \App\Models\LostWaxPrintOrder::whereDate('scheduled_date', $date)
                ->lockForUpdate()
                ->orderBy('id', 'desc')
                ->first();

            $sequence = 1;
            if ($lastOrder) {
                $parts = explode('-', $lastOrder->print_order_number);
                if (count($parts) === 3) {
                    $sequence = ((int) $parts[2]) + 1;
                }
            }

            return 'PC-'.$dateStr.'-'.str_pad($sequence, 4, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Store a newly created reprint print order.
     */
    public function storeReprint(Request $request, \App\Services\LostWaxRecoveryService $recoveryService)
    {
        $scope = auth()->user()->product_scope;
        $isPpic = auth()->user()->hasRole('ppic');

        if (! ($isPpic && $scope)) {
            abort(403, 'Hanya PPIC owner yang dapat membuat Perintah Cetak Ulang.');
        }

        $request->validate([
            'production_plan_id' => 'required|integer|exists:production_plans,id',
            'quantity' => 'required|integer|min:1',
            'reprint_reason' => 'required|string|max:255',
            'scheduled_date' => 'nullable|date',
        ]);

        $plan = \App\Models\ProductionPlan::findOrFail($request->production_plan_id);
        if ($plan->product_scope !== $scope) {
            abort(403, 'Unauthorized.');
        }

        try {
            $printOrder = $recoveryService->createReprint(
                $plan,
                (int) $request->quantity,
                $request->reprint_reason,
                auth()->id(),
                $request->scheduled_date
            );

            return redirect()->route('lost-wax.print-orders.show', $printOrder)
                ->with('success', 'Dokumen Perintah Cetak Ulang (Cycle #'.$printOrder->reprint_cycle.') berhasil dibuat.');
        } catch (\DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Close a production plan without issuing a reprint.
     */
    public function closeWithoutReprint(Request $request, \App\Models\ProductionPlan $plan, \App\Services\LostWaxRecoveryService $recoveryService)
    {
        $scope = auth()->user()->product_scope;
        $isPpic = auth()->user()->hasRole('ppic');

        if (! ($isPpic && $scope)) {
            abort(403, 'Hanya PPIC owner yang dapat menutup rencana produksi.');
        }

        if ($plan->product_scope !== $scope) {
            abort(403, 'Unauthorized.');
        }

        $request->validate([
            'closure_reason' => 'required|string|min:3|max:255',
        ]);

        try {
            $recoveryService->closeWithoutReprint($plan, $request->closure_reason, auth()->id());

            return redirect()->back()->with('success', 'Rencana produksi '.$plan->code.' berhasil ditutup tanpa cetak ulang.');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Update PO quantity and PO number for a production plan.
     */
    public function updatePoQuantity(Request $request, \App\Models\ProductionPlan $plan, \App\Services\LostWaxRecoveryService $recoveryService)
    {
        $scope = auth()->user()->product_scope;
        $isPpic = auth()->user()->hasRole('ppic');

        if (! ($isPpic && $scope)) {
            abort(403, 'Hanya PPIC owner yang dapat mengubah kuantitas PO.');
        }

        if ($plan->product_scope !== $scope) {
            abort(403, 'Unauthorized.');
        }

        $request->validate([
            'po_quantity' => 'nullable|integer|min:0',
            'po_number' => 'nullable|string|max:100',
        ]);

        try {
            $recoveryService->updatePoQuantity(
                $plan,
                $request->filled('po_quantity') ? (int) $request->po_quantity : null,
                $request->po_number
            );

            return redirect()->back()->with('success', 'Data PO untuk rencana '.$plan->code.' berhasil diperbarui.');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    protected function authorizePrintOrder(\App\Models\LostWaxPrintOrder $printOrder)
    {
        $scope = auth()->user()->product_scope;
        if (auth()->user()->hasRole('ppic') && $scope) {
            $unauthorized = $printOrder->lines()->whereHas('productionPlan', function ($q) use ($scope) {
                $q->where('product_scope', '!=', $scope);
            })->exists();

            if ($unauthorized) {
                abort(403, 'Unauthorized.');
            }
        }
    }

    protected function normalizeSelectedPlanIds($planIds): array
    {
        return collect(is_array($planIds) ? $planIds : [$planIds])
            ->map(function ($planId) {
                return (int) $planId;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
