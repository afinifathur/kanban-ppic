<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductionPlan;
use App\Models\ProductionItem;

class PlanController extends Controller
{
    public function index()
    {
        $plans = ProductionPlan::orderBy('created_at', 'desc')->get();
        return view('plan.index', compact('plans'));
    }

    public function create()
    {
        return view('plan.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'plans' => 'required|array',
            'plans.*.item_code' => 'required|string',
            'plans.*.item_name' => 'required|string',
            'plans.*.po_number' => 'required|string',
            'plans.*.qty_planned' => 'required|integer',
            'plans.*.line_number' => 'required',
        ]);

        foreach ($data['plans'] as $plan) {
            $lineNumber = (int) filter_var($plan['line_number'], FILTER_SANITIZE_NUMBER_INT) ?: null;

            ProductionPlan::create([
                'code' => $plan['code'] ?? null,
                'item_code' => $plan['item_code'],
                'item_name' => $plan['item_name'],
                'aisi' => $plan['aisi'] ?? null,
                'size' => $plan['size'] ?? null,
                'weight' => $plan['weight'] ?? null,
                'po_number' => $plan['po_number'],
                'qty_planned' => $plan['qty_planned'],
                'qty_remaining' => $plan['qty_planned'],
                'line_number' => $lineNumber,
                'customer' => $plan['customer'] ?? null,
                'status' => 'planning',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => count($data['plans']) . ' Plans Added Successfully!',
            'redirect' => route('plan.index')
        ]);
    }
}
