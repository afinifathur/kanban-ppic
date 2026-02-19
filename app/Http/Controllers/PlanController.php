<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductionPlan;
use App\Models\ProductionItem;

class PlanController extends Controller
{
    public function index(Request $request)
    {
        $date = $request->query('date');

        if ($date) {
            // Detail View for a specific date
            $plans = ProductionPlan::whereDate('created_at', $date)
                ->orderBy('line_number', 'asc')
                ->orderBy('id', 'asc')
                ->get();
            return view('plan.list', compact('plans', 'date'));
        }

        // Summary View (Default)
        $dailyStats = ProductionPlan::selectRaw('
                DATE(created_at) as date, 
                COUNT(*) as items_count, 
                SUM(qty_planned) as total_planned, 
                SUM(qty_remaining) as total_remaining,
                COUNT(CASE WHEN status = "planning" THEN 1 END) as planning_count,
                COUNT(CASE WHEN status = "active" THEN 1 END) as active_count,
                COUNT(CASE WHEN status = "completed" THEN 1 END) as completed_count,
                GROUP_CONCAT(DISTINCT customer SEPARATOR ", ") as unique_customers
            ')
            ->groupBy('date')
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
        ]);

        $customDate = $data['date'] ?? null;

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

            $newPlan = [
                'code' => $code,
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
                'status' => 'planning',
            ];

            if ($customDate) {
                $newPlan['created_at'] = $customDate . ' ' . now()->format('H:i:s');
                $newPlan['updated_at'] = $customDate . ' ' . now()->format('H:i:s');
            }

            ProductionPlan::create($newPlan);
        }

        $processedCount = count($data['plans']) - $skippedCount;
        $message = $processedCount . ' Plans Added Successfully!';
        if ($skippedCount > 0) {
            $message .= " ($skippedCount baris duplikat dilewati)";
        }

        return response()->json([
            'success' => $processedCount > 0 || $skippedCount > 0,
            'message' => $message,
            'redirect' => route('plan.index')
        ]);
    }

    public function edit(ProductionPlan $plan)
    {
        return view('plan.edit', compact('plan'));
    }

    public function update(Request $request, ProductionPlan $plan)
    {
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
        ]);

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
        // Check if there are items associated with this plan
        if ($plan->items()->exists()) {
            return back()->with('error', 'Tidak bisa menghapus rencana yang sudah memiliki data produksi.');
        }

        $plan->delete();

        return back()->with('success', 'Rencana berhasil dihapus.');
    }
}
