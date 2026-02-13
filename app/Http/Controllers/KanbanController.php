<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class KanbanController extends Controller
{
    public function index($dept)
    {
        $flow = ['rencana_cor', 'cor', 'netto', 'bubut_od', 'bubut_cnc', 'bor', 'finish'];

        if ($dept === 'rencana_cor') {
            $items = \App\Models\ProductionPlan::where('qty_remaining', '>', 0)
                ->orderBy('line_number')
                ->orderBy('created_at')
                ->get();

            $totalPcs = $items->sum('qty_remaining');
            $totalKg = $items->sum(fn($i) => $i->qty_remaining * $i->weight);
        } else {
            $items = \App\Models\ProductionItem::where('current_dept', $dept)
                ->where('qty_pcs', '>', 0)
                ->orderBy('line_number')
                ->orderBy('created_at')
                ->get();

            $totalPcs = $items->sum('qty_pcs');
            $totalKg = $items->sum(fn($i) => $i->qty_pcs * $i->weight_kg);
        }

        $lines = [
            1 => $items->where('line_number', 1),
            2 => $items->where('line_number', 2),
            3 => $items->where('line_number', 3),
            4 => $items->where('line_number', 4),
        ];

        // Next Department Logic
        $currentIndex = array_search($dept, $flow);
        $nextDept = ($currentIndex !== false && isset($flow[$currentIndex + 1])) ? $flow[$currentIndex + 1] : null;

        // Stats for All Departments (Navigation Bar)
        $itemStats = \App\Models\ProductionItem::where('qty_pcs', '>', 0)
            ->selectRaw('current_dept, SUM(qty_pcs) as total_pcs, SUM(qty_pcs * weight_kg) as total_kg')
            ->groupBy('current_dept')
            ->get()
            ->keyBy('current_dept');

        $planStats = \App\Models\ProductionPlan::where('qty_remaining', '>', 0)
            ->selectRaw('SUM(qty_remaining) as total_pcs, SUM(qty_remaining * weight) as total_kg')
            ->first();

        // Merge stats for navigation
        $allStats = $itemStats->map(function ($stat) {
            return (object) [
                'total_pcs' => $stat->total_pcs,
                'total_kg' => $stat->total_kg
            ];
        });

        $allStats['rencana_cor'] = (object) [
            'total_pcs' => $planStats->total_pcs ?? 0,
            'total_kg' => $planStats->total_kg ?? 0
        ];

        return view('kanban.index', compact('dept', 'lines', 'nextDept', 'totalPcs', 'totalKg', 'allStats', 'flow'));
    }

    public function move(Request $request)
    {
        $data = $request->validate([
            'item_ids' => 'required|array',
            'to_dept' => 'required|string',
        ]);

        $items = \App\Models\ProductionItem::whereIn('id', $data['item_ids'])->get();

        foreach ($items as $item) {
            $fromDept = $item->current_dept;

            // Move Item
            $item->update([
                'current_dept' => $data['to_dept'],
                'dept_entry_at' => now(), // Reset aging
                // Keep line_number or reset? "Data akan otomatis masuk ke Line 1 dep Cor". 
                // Usually FIFO maintains flow, but maybe reset to Line 1 is safer if other depts have different capacities.
                // For now, I'll keep the line number to simulate "flowing through the lines" 
                // UNLESS user specified otherwise. User said "1 batch barang tadi pindah ke kanban departemen netto". 
                // I'll keep line_number for now as it's cleaner visually.
            ]);

            // Log History
            \App\Models\ProductionHistory::create([
                'item_id' => $item->id,
                'from_dept' => $fromDept,
                'to_dept' => $data['to_dept'],
                'line_number' => $item->line_number, // Capture line number it was in
                'qty_pcs' => $item->qty_pcs,
                'weight_kg' => $item->weight_kg,
                'moved_at' => now(),
            ]);
        }

        return redirect()->route('kanban.index', $data['to_dept'])->with('success', count($items) . ' Items Moved to ' . ucfirst($data['to_dept']));
    }

    public function reorder(Request $request)
    {
        $request->validate([
            'department' => 'required',
            'line' => 'required',
            'from_pos' => 'required|integer',
            'to_pos' => 'required|integer'
        ]);

        $dept = $request->department;
        $line = $request->line;
        $fromPos = $request->from_pos;
        $toPos = $request->to_pos;

        if ($fromPos === $toPos) {
            return back()->with('success', 'Posisi sama, tidak ada perubahan.');
        }

        // Get items in standard order (FIFO)
        if ($dept === 'rencana_cor') {
            $items = \App\Models\ProductionPlan::where('line_number', $line)
                ->where('qty_remaining', '>', 0)
                ->orderBy('created_at', 'asc')
                ->get();
        } else {
            $items = \App\Models\ProductionItem::where('current_dept', $dept)
                ->where('line_number', $line)
                ->orderBy('created_at', 'asc')
                ->get();
        }

        // Validate positions
        if (!$items->has($fromPos - 1) || !$items->has($toPos - 1)) {
            return back()->withErrors(['msg' => 'Nomor antrian tidak valid.']);
        }

        $orderedItems = $items->all();

        // Remove item from old position
        $moved = array_splice($orderedItems, $fromPos - 1, 1)[0];

        // Insert item at new position
        array_splice($orderedItems, $toPos - 1, 0, [$moved]);

        // Re-timestamp
        $baseTime = $items->first()->created_at->subMinutes(10);

        foreach ($orderedItems as $index => $item) {
            $item->timestamps = false;
            $item->created_at = $baseTime->copy()->addSeconds($index);
            $item->save();
        }

        return back()->with('success', 'Antrian berhasil diubah.');
    }
}
