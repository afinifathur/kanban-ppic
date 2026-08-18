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
        // 1. Rencana Cetak (Plan Items)
        $plansQuery = \App\Models\ProductionPlan::query();

        if ($request->filled('date')) {
            $plansQuery->whereDate('created_at', $request->date);
        }

        if ($request->filled('customer')) {
            $plansQuery->where('customer', 'like', '%'.$request->customer.'%');
        }

        if ($request->filled('code')) {
            $plansQuery->where('code', 'like', '%'.$request->code.'%');
        }

        $plans = $plansQuery->orderBy('id', 'desc')
            ->paginate(15, ['*'], 'plans_page')
            ->withQueryString();

        // 2. Dokumen Perintah Cetak (Print Orders)
        $printOrdersQuery = \App\Models\LostWaxPrintOrder::with(['creator', 'lines']);

        if ($request->filled('print_order_number')) {
            $printOrdersQuery->where('print_order_number', 'like', '%'.$request->print_order_number.'%');
        }

        $printOrders = $printOrdersQuery->orderBy('id', 'desc')
            ->paginate(15, ['*'], 'orders_page')
            ->withQueryString();

        $activeTab = $request->query('plans_page') ? 'plans' : ($request->query('orders_page') ? 'orders' : 'plans');

        return view('lost-wax.print-orders.plans', compact('plans', 'printOrders', 'activeTab'));
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

        $plans = \App\Models\ProductionPlan::whereIn('id', $planIds)->get();

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
        $request->validate([
            'scheduled_date' => 'required|date',
            'print_order_number' => 'required|string|unique:lost_wax_print_orders,print_order_number',
            'items' => 'required|array|min:1',
            'items.*.production_plan_id' => 'required|exists:production_plans,id',
            'items.*.qty_ordered' => 'required|integer|min:1',
        ]);

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
        $printOrder->load(['creator', 'lines.productionPlan']);

        return view('lost-wax.print-orders.show', compact('printOrder'));
    }

    /**
     * Show the form for editing the specified print order.
     */
    public function edit(\App\Models\LostWaxPrintOrder $printOrder)
    {
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
        $printOrder->load(['creator', 'lines']);

        return view('lost-wax.print-orders.print', compact('printOrder'));
    }

    /**
     * Remove the specified print order from storage.
     */
    public function destroy(\App\Models\LostWaxPrintOrder $printOrder)
    {
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
}
