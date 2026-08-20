<?php

namespace App\Http\Controllers;

use App\Models\ProductionPlan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index(Request $request)
    {
        $date = $request->query('date');
        $scope = auth()->user()->product_scope;
        $isPpic = auth()->user()->hasRole('ppic');

        if ($date) {
            // Detail View for a specific date
            $sort = $request->query('sort');
            $direction = $request->query('direction', 'asc');

            $query = ProductionPlan::whereDate('created_at', $date);

            if ($isPpic && $scope) {
                $query->where('product_scope', $scope);
            }

            if ($sort) {
                if ($sort === 'hasil_cor') {
                    $query->orderByRaw('(qty_planned - qty_remaining) '.$direction);
                } else {
                    $query->orderBy($sort, $direction);
                }
                $query->orderBy('id', 'asc'); // secondary sort for stability
            } else {
                $query->orderBy('line_number', 'asc')->orderBy('id', 'asc');
            }

            $plans = $query->get();

            $titleQuery = ProductionPlan::whereDate('created_at', $date)->whereNotNull('title');
            if ($isPpic && $scope) {
                $titleQuery->where('product_scope', $scope);
            }
            $planTitle = $titleQuery->value('title');

            return view('plan.list', compact('plans', 'date', 'planTitle', 'sort', 'direction'));
        }

        // Summary View (Default)
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
        $groupConcat = $driver === 'sqlite'
            ? 'GROUP_CONCAT(DISTINCT customer)'
            : 'GROUP_CONCAT(DISTINCT customer SEPARATOR ", ")';

        $statsQuery = ProductionPlan::selectRaw("
                DATE(created_at) as date, 
                MAX(title) as title,
                COUNT(*) as items_count, 
                SUM(qty_planned) as total_planned, 
                SUM(qty_remaining) as total_remaining,
                COUNT(CASE WHEN status = 'planning' THEN 1 END) as planning_count,
                COUNT(CASE WHEN status = 'active' THEN 1 END) as active_count,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count,
                {$groupConcat} as unique_customers
            ");

        if ($isPpic && $scope) {
            $statsQuery->where('product_scope', $scope);
        }

        $dailyStats = $statsQuery->groupBy('date')
            ->orderByDesc('date')
            ->get();

        return view('plan.index', compact('dailyStats'));
    }

    public function create()
    {
        $customers = \App\Models\Customer::where('is_active', true)->orderBy('name')->get();

        return view('plan.create', compact('customers'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => 'nullable|date',
            'title' => 'required|string|max:255',
            'plans' => 'required|array',
            'plans.*.code' => 'nullable|string',
            'plans.*.item_code' => 'required|string',
            'plans.*.item_name' => 'required|string',
            'plans.*.aisi' => 'nullable|string',
            'plans.*.size' => 'nullable|string',
            'plans.*.weight' => 'nullable|numeric',
            'plans.*.po_number' => 'required|string',
            'plans.*.qty_planned' => 'required|integer',
            'plans.*.line_number' => 'required',
            'plans.*.customer' => 'nullable|string',
            'plans.*.product_scope' => 'nullable|string|in:FLANGE_STAINLESS,FLANGE_BESI,FITTING_STAINLESS',
        ]);

        $customDate = $data['date'] ?? null;
        $customTitle = $data['title'] ?? null;
        $user = auth()->user();
        $userScope = $user->product_scope;
        $isPpic = $user->hasRole('ppic');

        $skippedCount = 0;
        foreach ($data['plans'] as $plan) {
            $lineNumber = (int) filter_var($plan['line_number'], FILTER_SANITIZE_NUMBER_INT) ?: null;
            $code = $plan['code'] ?? null;
            $itemCode = $plan['item_code'];
            $poNumber = $plan['po_number'];

            // Check for potential duplicate
            $exists = ProductionPlan::where('code', $code)
                ->where('item_code', $itemCode)
                ->where('po_number', $poNumber)
                ->exists();

            if ($exists) {
                $skippedCount++;

                continue;
            }

            // Determine or force scope
            $itemScope = $plan['product_scope'] ?? null;
            if ($isPpic && $userScope) {
                $itemScope = $userScope;
            } else {
                if (! $itemScope) {
                    $itemScope = ProductionPlan::determineProductScopeFromItem($plan['item_name'], $plan['aisi']);
                }
            }

            $newPlan = [
                'code' => $code,
                'title' => $customTitle,
                'item_code' => $itemCode,
                'item_name' => $plan['item_name'],
                'aisi' => $plan['aisi'] ?? null,
                'size' => $plan['size'] ?? null,
                'weight' => $plan['weight'] ?? null,
                'po_number' => $poNumber,
                'qty_planned' => $plan['qty_planned'],
                'qty_remaining' => $plan['qty_planned'],
                'line_number' => $lineNumber,
                'customer' => $plan['customer'] ?? null,
                'product_scope' => $itemScope,
                'status' => 'planning',
            ];

            if ($customDate) {
                $newPlan['created_at'] = $customDate.' '.now()->format('H:i:s');
                $newPlan['updated_at'] = $customDate.' '.now()->format('H:i:s');
            }

            $createdPlan = ProductionPlan::create($newPlan);

            // Auto-detect and link any existing unassigned "Cor" items
            $query = \App\Models\ProductionItem::whereNull('plan_id')
                ->where('item_code', $itemCode);

            if ($lineNumber !== null) {
                $query->where('line_number', $lineNumber);
            }

            $unassignedItems = $query->orderBy('created_at', 'asc')->get();

            foreach ($unassignedItems as $item) {
                // Only fill until plan is satisfied
                if ($createdPlan->qty_remaining <= 0) {
                    break;
                }

                // Map to plan and enrich metadata
                /** @var \App\Models\ProductionItem $item */
                $item->update([
                    'plan_id' => $createdPlan->id,
                    'po_number' => $item->po_number ?? $createdPlan->po_number,
                    'customer' => $item->customer ?? $createdPlan->customer,
                ]);

                // Reduce remaining allocation on the plan
                $createdPlan->decrement('qty_remaining', $item->qty_pcs);
            }

            // Ensure plan status accurately reflects early completions
            if ($createdPlan->qty_remaining <= 0) {
                $createdPlan->update(['status' => 'completed']);
            }
        }

        $processedCount = count($data['plans']) - $skippedCount;
        $message = $processedCount.' Plans Added Successfully!';
        if ($skippedCount > 0) {
            $message .= " ($skippedCount baris duplikat dilewati)";
        }

        return response()->json([
            'success' => $processedCount > 0 || $skippedCount > 0,
            'message' => $message,
            'redirect' => route('plan.index'),
        ]);
    }

    public function edit(ProductionPlan $plan)
    {
        $user = auth()->user();
        if ($user->hasRole('ppic') && $user->product_scope && $plan->product_scope !== $user->product_scope) {
            abort(403, 'Unauthorized.');
        }

        return view('plan.edit', compact('plan'));
    }

    public function update(Request $request, ProductionPlan $plan)
    {
        $user = auth()->user();
        if ($user->hasRole('ppic') && $user->product_scope && $plan->product_scope !== $user->product_scope) {
            abort(403, 'Unauthorized.');
        }

        $data = $request->validate([
            'line_number' => 'required|integer',
            'customer' => 'nullable|string',
            'po_number' => 'required|string',
            'item_code' => 'required|string',
            'item_name' => 'required|string',
            'aisi' => 'nullable|string',
            'size' => 'nullable|string',
            'weight' => 'nullable|numeric',
            'qty_planned' => 'required|integer|min:1',
            'status' => 'required|in:planning,active,completed',
            'product_scope' => 'nullable|string|in:FLANGE_STAINLESS,FLANGE_BESI,FITTING_STAINLESS',
        ]);

        if ($user->hasRole('ppic') && $user->product_scope) {
            $data['product_scope'] = $user->product_scope;
        }

        // Calculate new qty_remaining if qty_planned changed
        if ($data['qty_planned'] != $plan->qty_planned) {
            $diff = $data['qty_planned'] - $plan->qty_planned;
            $data['qty_remaining'] = max(0, $plan->qty_remaining + $diff);
        }

        $plan->update($data);

        return redirect()->route('plan.index', ['date' => $plan->created_at->format('Y-m-d')])
            ->with('success', 'Rencana berhasil diperbarui.');
    }

    public function destroy(ProductionPlan $plan)
    {
        $user = auth()->user();
        if ($user->hasRole('ppic') && $user->product_scope && $plan->product_scope !== $user->product_scope) {
            abort(403, 'Unauthorized.');
        }

        // Check if there are items associated with this plan
        if ($plan->items()->exists()) {
            return back()->with('error', 'Tidak bisa menghapus rencana yang sudah memiliki data produksi.');
        }

        $plan->delete();

        return back()->with('success', 'Rencana berhasil dihapus.');
    }

    public function updateTitle(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'title' => 'required|string|max:255',
        ]);

        $query = ProductionPlan::whereDate('created_at', $request->date);
        $user = auth()->user();
        if ($user->hasRole('ppic') && $user->product_scope) {
            $query->where('product_scope', $user->product_scope);
        }

        $query->update(['title' => $request->title]);

        return back()->with('success', 'Judul Rencana berhasil diperbarui.');
    }
}
