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
            ->paginate(15, ['*'], 'plans_page')
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

        $printOrders = $printOrdersQuery->orderBy('id', 'desc')
            ->paginate(15, ['*'], 'orders_page')
            ->withQueryString();

        $activeTab = $request->query('tab');
        if (! in_array($activeTab, ['plans', 'orders'])) {
            $activeTab = $request->query('orders_page') ? 'orders' : 'plans';
        }

        return view('lost-wax.print-orders.plans', compact('plans', 'printOrders', 'activeTab', 'uniqueCodes', 'uniqueCustomers'));
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
        $planIds = $request->input('plan_ids');

        if (empty($planIds) || ! is_array($planIds)) {
            return redirect()->route('lost-wax.print-orders.plans')
                ->with('error', 'Pilih minimal satu item rencana untuk membuat perintah cetak.');
        }

        $scope = auth()->user()->product_scope;
        $isPpic = auth()->user()->hasRole('ppic');

        if ($isPpic && $scope) {
            $unauthorizedCount = \App\Models\ProductionPlan::whereIn('id', $planIds)
                ->where('product_scope', '!=', $scope)
                ->count();
            if ($unauthorizedCount > 0) {
                abort(403, 'Unauthorized.');
            }
        }

        $plans = \App\Models\ProductionPlan::whereIn('id', $planIds)->get();

        if ($plans->isEmpty()) {
            return redirect()->route('lost-wax.print-orders.plans')
                ->with('error', 'Item rencana tidak ditemukan.');
        }

        if ($plans->contains('is_closed', true)) {
            return redirect()->route('lost-wax.print-orders.plans')
                ->with('error', 'Item Production Plan ini sudah ditutup dan tidak dapat dibuat menjadi Perintah Cetak baru.');
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
            $planId = $request->input('production_plan_id');
            $plan = \App\Models\ProductionPlan::findOrFail($planId);
            if ($isPpic && $scope && $plan->product_scope !== $scope) {
                abort(403, 'Unauthorized.');
            }
            $plan->update(['is_closed' => true]);

            return redirect()->back()->with('success', 'Rencana produksi '.$plan->code.' berhasil ditutup (CLOSED).');
        }

        if ($request->input('action') === 'open_plan') {
            $planId = $request->input('production_plan_id');
            $plan = \App\Models\ProductionPlan::findOrFail($planId);
            if ($isPpic && $scope && $plan->product_scope !== $scope) {
                abort(403, 'Unauthorized.');
            }
            $plan->update(['is_closed' => false]);

            return redirect()->back()->with('success', 'Rencana produksi '.$plan->code.' berhasil dibuka kembali (OPEN).');
        }

        $request->validate([
            'scheduled_date' => 'required|date',
            'print_order_number' => 'required|string|unique:lost_wax_print_orders,print_order_number',
            'items' => 'required|array|min:1',
            'items.*.production_plan_id' => 'required|exists:production_plans,id',
            'items.*.qty_ordered' => 'required|integer|min:1',
        ]);

        foreach ($request->items as $itemData) {
            $plan = \App\Models\ProductionPlan::findOrFail($itemData['production_plan_id']);
            if ($isPpic && $scope && $plan->product_scope !== $scope) {
                abort(403, 'Unauthorized.');
            }
            if ($plan->is_closed) {
                return redirect()->route('lost-wax.print-orders.plans')
                    ->with('error', 'Item Production Plan ini sudah ditutup dan tidak dapat dibuat menjadi Perintah Cetak baru.');
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
        $printOrder->load(['creator', 'lines.productionPlan']);

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

        return view('lost-wax.print-orders.edit', compact('printOrder'));
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
}
