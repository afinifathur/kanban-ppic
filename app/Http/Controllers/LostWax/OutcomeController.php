<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OutcomeController extends Controller
{
    /**
     * Display list of Print Orders ready for actual outcomes entry (status ISSUED or CANCELLED).
     */
    public function index(Request $request)
    {
        $query = \App\Models\LostWaxPrintOrder::with(['lines.trees', 'creator'])
            ->where('status', '!=', 'DRAFT');

        if ($request->filled('print_order_number')) {
            $query->where('print_order_number', 'like', '%'.$request->print_order_number.'%');
        }

        $printOrders = $query->orderBy('id', 'desc')->paginate(15);

        return view('lost-wax.outcomes.index', compact('printOrders'));
    }

    /**
     * Show form to record actual good/defect outputs.
     */
    public function editOutcome(\App\Models\LostWaxPrintOrder $printOrder)
    {
        if ($printOrder->status !== 'ISSUED') {
            return redirect()->route('lost-wax.outcomes.index')
                ->with('error', 'Hasil cetak hanya dapat dicatat untuk dokumen berstatus ISSUED.');
        }

        $printOrder->load('lines.trees');

        return view('lost-wax.outcomes.edit', compact('printOrder'));
    }

    /**
     * Update actual outcomes for the print order lines.
     */
    public function updateOutcome(Request $request, \App\Models\LostWaxPrintOrder $printOrder)
    {
        if ($printOrder->status !== 'ISSUED') {
            return redirect()->route('lost-wax.outcomes.index')
                ->with('error', 'Hasil cetak hanya dapat dicatat untuk dokumen berstatus ISSUED.');
        }

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:lost_wax_print_order_lines,id',
            'items.*.qty_actual_good' => 'required|integer|min:0',
            'items.*.qty_actual_defect' => 'required|integer|min:0',
            'items.*.standard_tree_capacity' => 'required|integer|min:1',
        ]);

        try {
            DB::transaction(function () use ($request, $printOrder) {
                foreach ($request->items as $itemData) {
                    $line = $printOrder->lines()->lockForUpdate()->findOrFail($itemData['id']);

                    // Invariant: qty_actual_good + qty_actual_defect <= qty_ordered
                    $totalActual = (int) $itemData['qty_actual_good'] + (int) $itemData['qty_actual_defect'];
                    if ($totalActual > $line->qty_ordered) {
                        throw new \InvalidArgumentException("Total Hasil ({$itemData['qty_actual_good']} pcs) + Rusak ({$itemData['qty_actual_defect']} pcs) tidak boleh melebihi Qty Perintah ({$line->qty_ordered} pcs) untuk item {$line->item_name}.");
                    }

                    // Invariant: If Trees are already committed, we cannot reduce qty_actual_good below total allocated quantities
                    $allocatedTreeQty = (int) $line->trees()->sum('quantity');
                    if ((int) $itemData['qty_actual_good'] < $allocatedTreeQty) {
                        throw new \InvalidArgumentException("Hasil tidak boleh kurang dari quantity tree yang sudah dibuat ({$allocatedTreeQty} pcs) untuk item {$line->item_name}.");
                    }

                    $line->update([
                        'qty_actual_good' => $itemData['qty_actual_good'],
                        'qty_actual_defect' => $itemData['qty_actual_defect'],
                        'standard_tree_capacity' => $itemData['standard_tree_capacity'],
                        'actual_recorded_at' => now(),
                        'actual_recorded_by' => auth()->id(),
                    ]);
                }
            });

            return redirect()->route('lost-wax.outcomes.index')
                ->with('success', 'Actual Hasil Cetak berhasil disimpan.');
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }
}
