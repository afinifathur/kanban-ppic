<?php

namespace App\Http\Controllers;

use App\Models\ProductionDefect;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DefectDashboardController extends Controller
{
    public function index(Request $request)
    {
        // Date Range: Default to last 6 months (26 weeks)
        $endDate = $request->input('end_date') ? Carbon::parse($request->input('end_date')) : now();
        $startDate = $request->input('start_date') ? Carbon::parse($request->input('start_date')) : now()->subMonths(6);

        // Filter only items where the logged defects sum >= scrap_qty (Completed Status)
        $completedItemFilter = function ($q) use ($startDate, $endDate) {
            $q->whereBetween('production_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                ->whereRaw('production_items.scrap_qty > 0')
                ->whereRaw('(SELECT COALESCE(SUM(qty), 0) FROM production_defects WHERE production_item_id = production_items.id) >= production_items.scrap_qty');
        };

        $selectedDefectType = $request->input('defect_type');
        $selectedDepartment = $request->input('department');

        $defectTypesList = DB::table('defect_types')->select('name')->distinct()->orderBy('name')->pluck('name');
        $departmentsList = ['netto', 'bubut_od', 'bubut_cnc', 'bor', 'finish'];

        $baseDefectQuery = ProductionDefect::query()
            ->whereHas('item', $completedItemFilter)
            ->join('defect_types', 'production_defects.defect_type_id', '=', 'defect_types.id');

        if ($selectedDefectType) {
            $baseDefectQuery->where('defect_types.name', $selectedDefectType);
        }
        if ($selectedDepartment) {
            $baseDefectQuery->where('defect_types.department', $selectedDepartment);
        }

        // 1. Donut Chart Data
        // A. By Defect Type (Global)
        $byTypeData = (clone $baseDefectQuery)
            ->select('defect_types.name', DB::raw('SUM(production_defects.qty) as total_qty'))
            ->groupBy('defect_types.name')
            ->orderByDesc('total_qty')
            ->get();

        $chartByType = [
            'labels' => $byTypeData->pluck('name'),
            'data' => $byTypeData->pluck('total_qty'),
        ];

        // B. By Department (Global)
        $byDeptData = (clone $baseDefectQuery)
            ->select('defect_types.department', DB::raw('SUM(production_defects.qty) as total_qty'))
            ->groupBy('defect_types.department')
            ->orderByDesc('total_qty')
            ->get();

        $chartByDept = [
            'labels' => $byDeptData->pluck('department')->map(fn ($d) => ucfirst(str_replace('_', ' ', $d))),
            'data' => $byDeptData->pluck('total_qty'),
        ];

        // 2. Line Chart Data: Weekly Trend (PCS & Tonnage)
        // Group by Week Number
        // Note: weight_kg is in ProductionItem, we need to approximate defect weight or use specific items.
        // User said: "pcs vs tonase". Heavy logic: defect weight = (defect_qty / item_qty) * item_weight_kg ? or just use average item weight?
        // Let's assume defect items account for the scrap weight.
        // Ideally ProductionItem has 'weight_kg'. If scrap_qty > 0, we should calculate specific scrap weight?
        // Let's rely on item's unit weight: item->weight_kg / item->qty_pcs * defect->qty

        $weeklyData = ProductionDefect::whereHas('item', function ($q) use ($startDate, $endDate) {
            $q->whereBetween('production_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
        })
            ->join('production_items', 'production_defects.production_item_id', '=', 'production_items.id')
            ->select(
                DB::raw('YEARWEEK(production_items.production_date, 1) as yearweek'),
                DB::raw('SUM(production_defects.qty) as total_pcs'),
                // Calculate weight: distinct items might have different weights.
                // Doing sum in DB is complex if weight varies per item.
                // We'll fetch and aggregate in PHP for accuracy or use simplified avg if too heavy.
                // Let's try DB raw for approximate if possible, or fetch.
                // Faster approach: Join production_items, use (production_defects.qty * (production_items.weight_kg / production_items.qty_pcs)) ?
                // production_items.qty_pcs is total qty produced? typically yes.
                // To be safe, let's just summed pcs for now and try to estimate weight if possible or just use a generic factor if data missing.
                // BETTER: Just select all defects and aggregate in collection
            )
            ->groupBy('yearweek')
            ->orderBy('yearweek')
            ->get();

        // Refined Line Chart Data (PHP Aggregation for Weight Accuracy)
        $lineChartRaw = (clone $baseDefectQuery)
            ->with('item')
            ->select('production_defects.*')
            ->get()
            ->groupBy(function ($defect) {
                return $defect->item->production_date ? Carbon::parse($defect->item->production_date)->format('Y-W') : 'Unknown';
            });

        $lineChartLabels = [];
        $lineChartPcs = [];
        $lineChartKg = [];

        // Fill missing weeks? User asked "minggu ke 33 dibawahnya ags...".
        // Let's iterate through weeks in range.
        $current = $startDate->copy()->startOfWeek();
        $end = $endDate->copy()->endOfWeek();

        $lastMonth = null;

        while ($current <= $end) {
            $key = $current->format('Y-W');

            $weekNum = $current->weekOfYear;
            $monthName = $current->format('M');

            if ($lastMonth !== $monthName) {
                $lineChartLabels[] = [$weekNum, $monthName];
                $lastMonth = $monthName;
            } else {
                $lineChartLabels[] = [$weekNum];
            }

            $weekData = $lineChartRaw->get($key);
            $pcs = 0;
            $kg = 0;

            if ($weekData) {
                foreach ($weekData as $defect) {
                    $pcs += $defect->qty;
                    // Calculate weight safely
                    if ($defect->item) {
                        // Priority: finish_weight > netto_weight > bruto_weight > weight_kg
                        // Note: These columns represent the weight PER PIECE
                        $unitWeight = $defect->item->finish_weight ?? $defect->item->netto_weight ?? $defect->item->bruto_weight ?? $defect->item->weight_kg ?? 0;

                        $kg += ($unitWeight * $defect->qty);
                    }
                }
            }

            $lineChartPcs[] = $pcs;
            $lineChartKg[] = round($kg, 2); // Use KG directly

            $current->addWeek();
        }

        return view('dashboard.defects', compact(
            'startDate',
            'endDate',
            'chartByType',
            'chartByDept',
            'lineChartLabels',
            'lineChartPcs',
            'lineChartKg',
            'defectTypesList',
            'departmentsList',
            'selectedDefectType',
            'selectedDepartment'
        ));
    }
}
