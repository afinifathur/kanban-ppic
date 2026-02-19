<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductionItem;
use App\Models\ProductionHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class WipController extends Controller
{
    public function index()
    {
        // Similar to Input index but for WIP context (Daily casting summaries)
        $dailyStats = ProductionHistory::where('from_dept', 'cor')
            ->join('production_items', 'production_histories.item_id', '=', 'production_items.id')
            ->selectRaw('COALESCE(production_items.production_date, DATE(production_histories.moved_at)) as date, 
                        COUNT(DISTINCT production_items.heat_number) as heat_count, 
                        COUNT(DISTINCT production_histories.item_id) as items_count, 
                        SUM(production_histories.qty_pcs) as total_pcs, 
                        SUM(production_histories.qty_pcs * production_histories.weight_kg) as total_kg')
            ->groupBy('date')
            ->orderByDesc('date')
            ->get();

        return view('wip.index', compact('dailyStats'));
    }

    public function show($date)
    {
        // Fetch all items cast on this date
        $items = ProductionHistory::where('from_dept', 'cor')
            ->join('production_items', 'production_histories.item_id', '=', 'production_items.id')
            ->where(function ($q) use ($date) {
                $q->where('production_items.production_date', $date)
                    ->orWhere(function ($sq) use ($date) {
                        $sq->whereNull('production_items.production_date')
                            ->whereDate('production_histories.moved_at', $date);
                    });
            })
            ->select('production_items.*', 'production_histories.id as history_id', 'production_histories.qty_pcs as history_qty')
            ->get();

        // Group by Heat Number
        $groups = $items->groupBy('heat_number');

        return view('wip.show', compact('date', 'groups'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'date' => 'required|date',
            'heat_number' => 'required|string',
            'bruto_weight' => 'required|numeric|min:0',
        ]);

        // Update all items in this Heat Number on this date
        $items = ProductionItem::where('heat_number', $data['heat_number'])
            ->whereDate('production_date', $data['date'])
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Heat Number tidak ditemukan pada tanggal ini.']);
        }

        // Total finished weight for this group
        $totalFinishedWeight = $items->sum(fn($i) => $i->qty_pcs * $i->weight_kg);

        DB::beginTransaction();
        try {
            foreach ($items as $item) {
                // We distribute the bruto weight proportionally based on finished weight
                // Ratio = (Item Finished Weight) / (Total Finished Weight)
                // Item Bruto = Total Bruto * Ratio
                $ratio = $totalFinishedWeight > 0 ? ($item->qty_pcs * $item->weight_kg) / $totalFinishedWeight : 1 / $items->count();

                $item->update([
                    'bruto_weight' => $data['bruto_weight'] * $ratio,
                    // Netto calculation: for now we assume Netto is what we move forward (products only)
                    // The user mentioned Netto is after separating from riser.
                    // We can refine this if the user wants separate Netto input.
                ]);
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Gagal mengupdate data: ' . $e->getMessage()]);
        }

        return response()->json(['success' => true, 'message' => 'Data WIP berhasil diperbarui.']);
    }

    public function report(Request $request)
    {
        // Simple WIP report logic
        $date = $request->get('date', now()->format('Y-m-d'));

        $stats = ProductionItem::where('production_date', $date)
            ->selectRaw('heat_number, SUM(qty_pcs) as total_pcs, SUM(qty_pcs * weight_kg) as total_finished_kg, MAX(bruto_weight) as total_bruto_kg')
            ->groupBy('heat_number')
            ->get();

        return view('wip.report', compact('date', 'stats'));
    }
}
