<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class InputController extends Controller
{
    public function index(Request $request, $dept)
    {
        $search = $request->input('search');
        $searchResults = collect();

        if ($search) {
            $rawResults = \App\Models\ProductionItem::with('histories')
                ->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                        ->orWhere('item_code', 'like', "%{$search}%")
                        ->orWhere('item_name', 'like', "%{$search}%")
                        ->orWhere('heat_number', 'like', "%{$search}%");
                })
                ->orderBy('created_at', 'desc')
                ->take(100)
                ->get();

            // Group by code and heat_number to combine histories from different departments
            $grouped = [];
            foreach ($rawResults as $item) {
                $key = $item->code.'|'.$item->heat_number;
                if (! isset($grouped[$key])) {
                    // Start with the most advanced state item as the base
                    $grouped[$key] = $item;
                    $grouped[$key]->all_histories = collect();
                } else {
                    // If we find an item with a more advanced department, update the base item info
                    // This can be complex depending on flow, but simpler is just taking the one with the latest update
                    if ($item->updated_at > $grouped[$key]->updated_at) {
                        $tempHistories = $grouped[$key]->all_histories;
                        $grouped[$key] = $item;
                        $grouped[$key]->all_histories = $tempHistories;
                    }
                }

                // Merge histories from ALL items that share the same code and heat number
                $allItemIds = \App\Models\ProductionItem::where('code', $item->code)
                    ->where('heat_number', $item->heat_number)
                    ->pluck('id');

                $allHistories = \App\Models\ProductionHistory::whereIn('item_id', $allItemIds)
                    ->orderBy('moved_at', 'asc')
                    ->get();

                // Add to our group
                $grouped[$key]->all_histories = collect();

                foreach ($allHistories as $history) {
                    // Avoid duplicating history entries if they were somehow loaded multiple times
                    if (! $grouped[$key]->all_histories->contains('id', $history->id)) {
                        // attach the item for production_date reference
                        $history_item = \App\Models\ProductionItem::find($history->item_id);
                        $history->setRelation('item', $history_item);

                        $grouped[$key]->all_histories->push($history);
                    }
                }
            }

            $searchResults = collect(array_values($grouped));
        }

        // Source from history TO see what was DONE in this department
        $dailyStats = \App\Models\ProductionHistory::where('from_dept', $dept)
            ->join('production_items', 'production_histories.item_id', '=', 'production_items.id')
            ->selectRaw('COALESCE(production_items.production_date, DATE(production_histories.moved_at)) as date, COUNT(DISTINCT production_histories.id) as items_count, SUM(production_histories.qty_pcs) as total_pcs, SUM(production_histories.qty_pcs * production_histories.weight_kg) as total_kg')
            ->groupBy('date')
            ->orderByDesc('date')
            ->get();

        return view('input.index', compact('dept', 'dailyStats', 'search', 'searchResults'));
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
            'items.*.grid_row_index' => 'nullable|integer',
        ]);

        $productionDate = $data['production_date'];
        $flow = ['cor', 'netto', 'bubut_od', 'bubut_cnc', 'bor', 'finish', 'completed'];
        $currentIndex = array_search($dept, $flow);
        $nextDept = $flow[$currentIndex + 1] ?? 'completed';

        $processedCount = 0;
        $errors = [];
        $validItems = [];
        $failedRows = [];

        // PRE-VALIDATION PHASE
        if ($dept === 'cor') {
            $groupedCor = [];
            foreach ($data['items'] as $index => $item) {
                if (empty($item['qty_pcs'])) {
                    continue;
                }

                $gridIndex = $item['grid_row_index'] ?? $index;
                $rowLabel = 'Baris '.($gridIndex + 1);

                $code = $item['code'] ?? '';
                $heat = $item['heat_number'] ?? '';
                if (empty($code) || empty($heat)) {
                    $errors[] = "{$rowLabel}: Code dan Heat Number wajib diisi.";
                    $failedRows[] = $gridIndex;

                    continue;
                }

                $key = $code.'|'.$heat;
                if (isset($groupedCor[$key])) {
                    $errors[] = "{$rowLabel}: Duplikasi Code {$code} dan Heat {$heat} dalam satu kali simpan.";
                    $failedRows[] = $gridIndex;
                } else {
                    $groupedCor[$key] = true;
                    // Check DB if already exists in cor (to enforce unique code+heat overall)
                    $exists = \App\Models\ProductionItem::where('code', $code)
                        ->where('heat_number', $heat)
                        ->exists();
                    if ($exists) {
                        $errors[] = "{$rowLabel}: Item {$code} #{$heat} sudah pernah di-input di Cor (kombinasi ini harus unik).";
                        $failedRows[] = $gridIndex;
                    } else {
                        $validItems[] = $item;
                    }
                }
            }
        } else {
            $groupedItems = [];
            foreach ($data['items'] as $index => $item) {
                $qtyHasil = (int) ($item['hasil'] ?? 0);
                $qtyRusak = (int) ($item['rusak'] ?? 0);
                $totalReported = $qtyHasil + $qtyRusak;

                if ($totalReported <= 0) {
                    continue;
                }

                $gridIndex = $item['grid_row_index'] ?? $index;
                $rowLabel = 'Baris '.($gridIndex + 1);

                $code = $item['code'] ?? '';
                $heat = $item['heat_number'] ?? '';

                if (empty($code) || empty($heat)) {
                    $errors[] = "{$rowLabel}: Code dan Heat Number wajib diisi.";
                    $failedRows[] = $gridIndex;

                    continue;
                }

                $key = $code.'|'.$heat;
                if (! isset($groupedItems[$key])) {
                    $groupedItems[$key] = [
                        'code' => $code,
                        'heat_number' => $heat,
                        'total_reported' => 0,
                        'rows' => [],
                        'items' => [],
                    ];
                }

                $groupedItems[$key]['total_reported'] += $totalReported;
                $groupedItems[$key]['rows'][] = $gridIndex;
                $groupedItems[$key]['items'][] = $item;
            }

            foreach ($groupedItems as $key => $group) {
                // Sum qty_pcs because theoretically there could be multiple splits? But code+heat is unique, so first() is fine, but sum is safer
                $sourceItem = \App\Models\ProductionItem::where('current_dept', $dept)
                    ->where('code', $group['code'])
                    ->where('heat_number', $group['heat_number'])
                    ->first();

                $isValidGroup = true;
                if (! $sourceItem) {
                    $everExisted = \App\Models\ProductionItem::where('code', $group['code'])
                        ->where('heat_number', $group['heat_number'])
                        ->exists();

                    $rowNumbers = implode(', ', array_map(function ($idx) {
                        return $idx + 1;
                    }, $group['rows']));
                    if ($everExisted) {
                        $errors[] = "Baris {$rowNumbers}: Item {$group['code']} #{$group['heat_number']} tidak memiliki stok di ".ucfirst($dept).' (sudah habis atau dipindah).';
                    } else {
                        $errors[] = "Baris {$rowNumbers}: Item {$group['code']} #{$group['heat_number']} ilegal: Belum pernah diproses di Cor.";
                    }
                    $isValidGroup = false;
                } else {
                    if ($group['total_reported'] > $sourceItem->qty_pcs) {
                        $rowNumbers = implode(', ', array_map(function ($idx) {
                            return $idx + 1;
                        }, $group['rows']));
                        $errors[] = "Baris {$rowNumbers}: Item {$group['code']} #{$group['heat_number']} melebihi stok: total input {$group['total_reported']} pcs, tersedia {$sourceItem->qty_pcs} pcs di ".ucfirst($dept).'.';
                        $isValidGroup = false;
                    }
                }

                if ($isValidGroup) {
                    foreach ($group['items'] as $item) {
                        $validItems[] = $item;
                    }
                } else {
                    foreach ($group['rows'] as $invalidIndex) {
                        $failedRows[] = $invalidIndex;
                    }
                }
            }
        }

        if (count($validItems) === 0) {
            return response()->json([
                'success' => false,
                'partial' => false,
                'processed' => 0,
                'errors' => $errors,
                'failed_rows' => $failedRows,
                'success_rows' => [],
                'message' => 'Gagal menyimpan karena tidak ada baris yang valid. Periksa daftar error.',
            ]);
        }

        $successRows = [];
        try {
            \Illuminate\Support\Facades\DB::beginTransaction();

            foreach ($validItems as $item) {
                $lineNumber = null;
                if (isset($item['line_number'])) {
                    $lineNumber = (int) filter_var($item['line_number'], FILTER_SANITIZE_NUMBER_INT) ?: null;
                }

                if ($dept === 'cor') {
                    if (empty($item['qty_pcs'])) {
                        continue;
                    }

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

                    if ($totalReported <= 0) {
                        continue;
                    }

                    $sourceItem = \App\Models\ProductionItem::where('current_dept', $dept)
                        ->where('code', $item['code'])
                        ->where('heat_number', $item['heat_number'])
                        ->first();

                    // Validation already ensured this is safe
                    if (! $sourceItem || $totalReported > $sourceItem->qty_pcs) {
                        continue;
                    }

                    $nextItem = $sourceItem->replicate();
                    $nextItem->current_dept = $nextDept;
                    $nextItem->qty_pcs = $qtyHasil;
                    $nextItem->scrap_qty = 0;
                    $nextItem->dept_entry_at = now();
                    $nextItem->production_date = $productionDate;

                    if (isset($item['bubut_weight'])) {
                        $nextItem->bubut_weight = $item['bubut_weight'];
                    }
                    if (isset($item['finish_weight'])) {
                        $nextItem->finish_weight = $item['finish_weight'];
                    }
                    if (isset($item['weight_kg'])) {
                        $nextItem->weight_kg = $item['weight_kg'];
                    }

                    $nextItem->save();

                    $sourceItem->decrement('qty_pcs', $totalReported);
                    $sourceItem->increment('scrap_qty', $qtyRusak);

                    \App\Models\ProductionHistory::create([
                        'item_id' => $nextItem->id, // Use nextItem id for history trace
                        'from_dept' => $dept,
                        'to_dept' => $nextDept,
                        'line_number' => $sourceItem->line_number,
                        'qty_pcs' => $qtyHasil,
                        'scrap_qty' => $qtyRusak,
                        'weight_kg' => $nextItem->weight_kg,
                        'moved_at' => now(),
                    ]);

                    $processedCount++;
                }

                if (isset($item['grid_row_index'])) {
                    $successRows[] = $item['grid_row_index'];
                }
            }

            \Illuminate\Support\Facades\DB::commit();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            \Illuminate\Support\Facades\Log::error('Input production error: '.$e->getMessage());
            $errors[] = 'System Error: '.$e->getMessage();
        }

        $allSuccess = $processedCount > 0 && count($errors) == 0;
        $partialSuccess = $processedCount > 0 && count($errors) > 0;

        return response()->json([
            'success' => $allSuccess || $partialSuccess,
            'partial' => $partialSuccess,
            'processed' => $processedCount,
            'errors' => $errors,
            'failed_rows' => $failedRows,
            'success_rows' => $successRows,
            'message' => $processedCount.' Kombinasi Heat Number berhasil diproses. '.(count($errors) > 0 ? count($errors).' gagal.' : ''),
            'redirect' => $allSuccess ? route('input.index', $dept) : null,
        ]);
    }

    public function updateHistory(Request $request, \App\Models\ProductionHistory $history)
    {
        $input = $request->all();
        // Normalize decimals from comma to dot if passed as string
        foreach (['weight_kg', 'bruto_weight', 'netto_weight'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = str_replace(',', '.', $input[$field]);
                $request->merge([$field => $input[$field]]);
            }
        }

        $data = $request->validate([
            'qty_pcs' => 'required|integer|min:1',
            'weight_kg' => 'required|numeric|min:0',
            'bruto_weight' => 'nullable|numeric|min:0',
            'netto_weight' => 'nullable|numeric|min:0',
            'customer' => 'nullable|string',
        ]);

        $diffQty = $data['qty_pcs'] - $history->qty_pcs;
        $item = $history->item;

        if ($item) {
            $item->qty_pcs += $diffQty;

            // Sync weights based on department
            if ($history->from_dept === 'cor') {
                $item->weight_kg = $data['weight_kg'];
            }
            if ($history->from_dept === 'netto') {
                $item->weight_kg = $data['weight_kg'];
            }
            if ($history->from_dept === 'bubut_od') {
                $item->bubut_weight = $data['weight_kg'];
            }
            if ($history->from_dept === 'finish') {
                $item->finish_weight = $data['weight_kg'];
            }

            // Also update generic weight_kg for consistency across WIP views
            $item->weight_kg = $data['weight_kg'];

            if (isset($data['bruto_weight'])) {
                $item->bruto_weight = $data['bruto_weight'];
            }
            if (isset($data['netto_weight'])) {
                $item->netto_weight = $data['netto_weight'];
            }

            if (isset($data['customer'])) {
                $item->customer = $data['customer'];
            }

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

        return response()->json(['success' => true]);
    }

    public function destroyHistory(\App\Models\ProductionHistory $history)
    {
        $item = $history->item;

        if ($item) {
            // Safety check: Prevent deletion if the item has already been moved to the next department
            if ($history->from_dept !== 'cor') {
                if ($item->qty_pcs < $history->qty_pcs || $item->current_dept !== $history->to_dept) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Gagal menghapus: Item ini sudah diproses ke departemen selanjutnya.',
                    ], 400);
                }
            }

            // 1. If Cor, revert plan
            if ($history->from_dept === 'cor' && $item->plan_id) {
                $plan = \App\Models\ProductionPlan::find($item->plan_id);
                if ($plan) {
                    $plan->increment('qty_remaining', $history->qty_pcs);
                    $plan->update(['status' => 'active']);
                }
            } else {
                // Revert qty to source item if it was a movement
                $sourceItem = \App\Models\ProductionItem::where('current_dept', $history->from_dept)
                    ->where('code', $item->code)
                    ->where('heat_number', $item->heat_number)
                    ->first();

                if ($sourceItem) {
                    $sourceItem->increment('qty_pcs', $history->qty_pcs);

                    if ($history->scrap_qty > 0) {
                        // Prevent negative scrap_qty just in case
                        $newScrapQty = max(0, $sourceItem->scrap_qty - $history->scrap_qty);
                        $sourceItem->update(['scrap_qty' => $newScrapQty]);

                        // Cleanup excessive defects if any
                        $defectCount = $sourceItem->defects()->sum('qty');
                        if ($defectCount > $newScrapQty) {
                            $sourceItem->defects()->delete(); // Reset all defects to avoid orphan/over-reported details
                        }
                    }
                }
            }

            // 2. Delete the item created/moved by this history
            $item->delete();
        }

        // 3. Delete the history record
        $history->delete();

        return response()->json(['success' => true, 'message' => 'Data berhasil dihapus dan jumlah stok dikembalikan.']);
    }
}
