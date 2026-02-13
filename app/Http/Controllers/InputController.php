<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class InputController extends Controller
{
    public function index($dept)
    {
        // Source from history TO see what was DONE in this department
        $dailyStats = \App\Models\ProductionHistory::where('from_dept', $dept)
            ->join('production_items', 'production_histories.item_id', '=', 'production_items.id')
            ->selectRaw('COALESCE(production_items.production_date, DATE(production_histories.moved_at)) as date, COUNT(DISTINCT production_histories.id) as items_count, SUM(production_histories.qty_pcs) as total_pcs, SUM(production_histories.qty_pcs * production_histories.weight_kg) as total_kg')
            ->groupBy('date')
            ->orderByDesc('date')
            ->get();

        return view('input.index', compact('dept', 'dailyStats'));
    }

    public function create($dept)
    {
        return view('input.create', compact('dept'));
    }

    public function show($dept, $date)
    {
        $items = \App\Models\ProductionHistory::where('from_dept', $dept)
            ->join('production_items', 'production_histories.item_id', '=', 'production_items.id')
            ->where(function ($q) use ($date) {
                // Filter by production_date (Tanggal Pekerjaan)
                $q->where('production_items.production_date', $date)
                    ->orWhere(function ($sq) use ($date) {
                    $sq->whereNull('production_items.production_date')
                        ->whereDate('production_histories.moved_at', $date);
                });
            })
            ->select('production_items.*', 'production_histories.id as history_id', 'production_histories.qty_pcs as history_qty', 'production_histories.weight_kg as history_weight')
            ->get();

        // Map history values to item values for the view (show.blade.php uses qty_pcs/weight_kg)
        foreach ($items as $item) {
            $item->qty_pcs = $item->history_qty;
            $item->weight_kg = $item->history_weight;
        }

        $customers = \App\Models\Customer::where('is_active', true)->orderBy('name')->get();

        return view('input.show', compact('dept', 'date', 'items', 'customers'));
    }

    public function store(Request $request, $dept)
    {
        $data = $request->validate([
            'production_date' => 'required|date',
            'items' => 'required|array',
            'items.*.code' => 'nullable|string',
            'items.*.heat_number' => 'nullable|string',
            'items.*.item_code' => 'nullable|string',
            'items.*.item_name' => 'nullable|string',
            'items.*.qty_pcs' => 'required|integer',
            'items.*.hasil' => 'nullable|integer', // Qty being moved forward
            'items.*.rusak' => 'nullable|integer', // Qty being scrapped
            'items.*.weight_kg' => 'nullable|numeric',
            'items.*.bruto_weight' => 'nullable|numeric',
            'items.*.netto_weight' => 'nullable|numeric',
            'items.*.bubut_weight' => 'nullable|numeric',
            'items.*.finish_weight' => 'nullable|numeric',
            'items.*.po_number' => 'nullable|string',
            'items.*.customer' => 'nullable|string',
            'items.*.line_number' => 'nullable',
        ]);

        $productionDate = $data['production_date'];
        $flow = ['cor', 'netto', 'bubut_od', 'bubut_cnc', 'bor', 'finish', 'completed'];
        $currentIndex = array_search($dept, $flow);
        $nextDept = $flow[$currentIndex + 1] ?? 'completed';

        $processedCount = 0;
        $errors = [];

        try {
            foreach ($data['items'] as $item) {
                $lineNumber = null;
                if (isset($item['line_number'])) {
                    $lineNumber = (int) filter_var($item['line_number'], FILTER_SANITIZE_NUMBER_INT) ?: null;
                }

                if ($dept === 'cor') {
                    $plan = \App\Models\ProductionPlan::where('item_code', $item['item_code'])
                        ->where('line_number', $lineNumber)
                        ->where('qty_remaining', '>', 0)
                        ->orderBy('created_at', 'asc')
                        ->first();

                    $planId = null;
                    if ($plan) {
                        $planId = $plan->id;
                        $plan->decrement('qty_remaining', $item['qty_pcs']);
                        $plan->update(['status' => ($plan->qty_remaining <= 0 ? 'completed' : 'active')]);
                    }

                    $newItem = \App\Models\ProductionItem::create([
                        'plan_id' => $planId,
                        'code' => $item['code'] ?? null,
                        'heat_number' => $item['heat_number'] ?? null,
                        'item_code' => $item['item_code'],
                        'item_name' => $item['item_name'],
                        'qty_pcs' => $item['qty_pcs'],
                        'weight_kg' => $item['weight_kg'] ?? null,
                        'bruto_weight' => $item['bruto_weight'] ?? null,
                        'netto_weight' => $item['netto_weight'] ?? null,
                        'bubut_weight' => $item['bubut_weight'] ?? null,
                        'finish_weight' => $item['finish_weight'] ?? null,
                        'po_number' => $item['po_number'] ?? ($plan->po_number ?? null),
                        'customer' => $item['customer'] ?? ($plan->customer ?? null),
                        'current_dept' => 'netto',
                        'line_number' => $lineNumber,
                        'dept_entry_at' => now(),
                        'production_date' => $productionDate,
                    ]);

                    \App\Models\ProductionHistory::create([
                        'item_id' => $newItem->id,
                        'from_dept' => 'cor',
                        'to_dept' => 'netto',
                        'line_number' => $lineNumber,
                        'qty_pcs' => $item['qty_pcs'],
                        'weight_kg' => $newItem->weight_kg,
                        'moved_at' => now(),
                    ]);

                    $processedCount++;
                } else {
                    $qtyHasil = (int) ($item['hasil'] ?? 0);
                    $qtyRusak = (int) ($item['rusak'] ?? 0);
                    $totalReported = $qtyHasil + $qtyRusak;

                    if ($totalReported <= 0)
                        continue;

                    $sourceItem = \App\Models\ProductionItem::where('current_dept', $dept)
                        ->where('code', $item['code'])
                        ->where('heat_number', $item['heat_number'])
                        ->first();

                    if (!$sourceItem) {
                        $errors[] = "Item {$item['code']} #{$item['heat_number']} tidak ditemukan di departemen " . ucfirst($dept);
                        continue;
                    }

                    if ($totalReported > $sourceItem->qty_pcs) {
                        $errors[] = "Item {$item['code']} #{$item['heat_number']} melebihi stok: reported $totalReported, available {$sourceItem->qty_pcs}";
                        continue;
                    }

                    $nextItem = $sourceItem->replicate();
                    $nextItem->current_dept = $nextDept;
                    $nextItem->qty_pcs = $qtyHasil;
                    $nextItem->scrap_qty = 0;
                    $nextItem->dept_entry_at = now();
                    $nextItem->production_date = $productionDate;

                    if (isset($item['bubut_weight']))
                        $nextItem->bubut_weight = $item['bubut_weight'];
                    if (isset($item['finish_weight']))
                        $nextItem->finish_weight = $item['finish_weight'];
                    if (isset($item['weight_kg']))
                        $nextItem->weight_kg = $item['weight_kg'];

                    $nextItem->save();

                    $sourceItem->decrement('qty_pcs', $totalReported);
                    $sourceItem->increment('scrap_qty', $qtyRusak);

                    \App\Models\ProductionHistory::create([
                        'item_id' => $nextItem->id, // Use nextItem id for history trace
                        'from_dept' => $dept,
                        'to_dept' => $nextDept,
                        'line_number' => $sourceItem->line_number,
                        'qty_pcs' => $qtyHasil,
                        'weight_kg' => $nextItem->weight_kg,
                        'moved_at' => now(),
                    ]);

                    $processedCount++;
                }
            }
        } catch (\Exception $e) {
            \Log::error("Input production error: " . $e->getMessage());
            $errors[] = "System Error: " . $e->getMessage();
        }

        return response()->json([
            'success' => $processedCount > 0,
            'processed' => $processedCount,
            'errors' => $errors,
            'message' => $processedCount . " Heat Number berhasil diproses. " . (count($errors) > 0 ? count($errors) . " gagal." : ""),
            'redirect' => route('input.index', $dept)
        ]);
    }

    public function updateHistory(Request $request, \App\Models\ProductionHistory $history)
    {
        $data = $request->validate([
            'qty_pcs' => 'required|integer|min:1',
            'weight_kg' => 'required|numeric|min:0',
            'bruto_weight' => 'nullable|numeric|min:0',
            'netto_weight' => 'nullable|numeric|min:0',
            'customer' => 'nullable|string',
        ]);

        $diffQty = $data['qty_pcs'] - $history->qty_pcs;
        $diffWeight = $data['weight_kg'] - $history->weight_kg;

        // 1. Update the associated item
        $item = $history->item;
        if ($item) {
            $item->qty_pcs += $diffQty;
            $item->weight_kg = $data['weight_kg']; // Update to new weight

            // Sync weights based on department if needed
            if ($history->from_dept === 'cor')
                $item->weight_kg = $data['weight_kg'];
            if ($history->from_dept === 'netto')
                $item->weight_kg = $data['weight_kg'];
            if ($history->from_dept === 'bubut_od')
                $item->bubut_weight = $data['weight_kg'];
            if ($history->from_dept === 'finish')
                $item->finish_weight = $data['weight_kg'];

            $item->save();
        }

        // 2. If Cor, update the plan
        if ($history->from_dept === 'cor' && $item && $item->plan_id) {
            $plan = \App\Models\ProductionPlan::find($item->plan_id);
            if ($plan) {
                $plan->decrement('qty_remaining', $diffQty);
                $plan->update(['status' => ($plan->qty_remaining <= 0 ? 'completed' : 'active')]);
            }
        }

        // 3. Update history record itself
        $history->update([
            'qty_pcs' => $data['qty_pcs'],
            'weight_kg' => $data['weight_kg'],
        ]);

        // 4. Update item metadata
        if ($item) {
            $item->update([
                'bruto_weight' => $data['bruto_weight'],
                'netto_weight' => $data['netto_weight'],
                'customer' => $data['customer'],
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Data berhasil diperbarui.']);
    }

    public function destroyHistory(\App\Models\ProductionHistory $history)
    {
        $item = $history->item;

        if ($item) {
            // 1. If Cor, revert plan
            if ($history->from_dept === 'cor' && $item->plan_id) {
                $plan = \App\Models\ProductionPlan::find($item->plan_id);
                if ($plan) {
                    $plan->increment('qty_remaining', $history->qty_pcs);
                    $plan->update(['status' => 'active']);
                }
            } else {
                // Revert qty to previous item if it was a movement
                // This is complex because we replicate items. 
                // For simplicity, we just delete the "next" item created by this history.
            }

            // 2. Delete the item created/moved by this history
            $item->delete();
        }

        // 3. Delete the history record
        $history->delete();

        return response()->json(['success' => true, 'message' => 'Data berhasil dihapus.']);
    }
}
