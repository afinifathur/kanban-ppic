<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductionDefect;
use App\Models\DefectType;
use App\Models\ProductionItem;
use Illuminate\Support\Facades\Response;

class DefectReportController extends Controller
{
    public function index(Request $request)
    {
        $departments = ['cor', 'netto', 'bubut_od', 'bubut_cnc', 'bor', 'finish'];
        $defectTypes = [];

        if ($request->department) {
            $defectTypes = DefectType::where('department', $request->department)->active()->get();
        }

        $results = null;
        $totalQty = 0;

        if ($request->has('generate')) {
            $request->validate([
                'date' => 'required|date',
                'department' => 'required|string',
                'defect_type_id' => 'nullable|exists:defect_types,id',
                'count' => 'required|integer|min:1|max:100',
            ]);

            $selectedDept = $request->department;

            $query = ProductionItem::whereDate('production_date', $request->date)
                ->whereHas('defects.defectType', function ($q) use ($request, $selectedDept) {
                    if ($selectedDept !== 'all') {
                        $q->where('department', $selectedDept);
                    }
                    if ($request->defect_type_id && $selectedDept !== 'all') {
                        $q->where('id', $request->defect_type_id);
                    }
                })
                ->with([
                    'defects' => function ($q) use ($request, $selectedDept) {
                        $q->whereHas('defectType', function ($sq) use ($selectedDept) {
                            if ($selectedDept !== 'all') {
                                $sq->where('department', $selectedDept);
                            }
                        })->with('defectType');

                        if ($request->defect_type_id && $selectedDept !== 'all') {
                            $q->where('defect_type_id', $request->defect_type_id);
                        }
                    }
                ])
                ->orderBy('created_at', 'desc');

            if ($selectedDept !== 'all') {
                $query->limit($request->count);
            }

            $items = $query->get()->map(function ($item) {
                // Pre-calculate aggregated summary for the view
                $details = $item->defects->groupBy('defect_type_id')->map(function ($group) {
                    $type = $group->first()->defectType->name;
                    $qty = $group->sum('qty');
                    return "{$type} {$qty}";
                })->implode(', ');

                $item->total_defect_qty = $item->defects->sum('qty');
                $item->defect_summary = $details;

                // Keep track of which department this item belongs to based on its first defect (for grouping when 'all')
                $item->dept_name = $item->defects->first() ? $item->defects->first()->defectType->department : 'unknown';

                return $item;
            });

            if ($selectedDept === 'all') {
                // Group by department
                $results = $items->groupBy('dept_name');
            } else {
                $results = $items;
            }

            $totalQty = $items->sum('total_defect_qty');
        }

        $defectType = $request->defect_type_id ? DefectType::find($request->defect_type_id) : null;

        return view('report.defects', [
            'departments' => $departments,
            'defectTypes' => $defectTypes,
            'results' => $results,
            'totalQty' => $totalQty,
            'selectedDate' => $request->date ?? date('Y-m-d'),
            'selectedDept' => $request->department,
            'selectedDefectType' => $request->defect_type_id,
            'defectType' => $defectType,
            'selectedCount' => $request->count ?? 10
        ]);
    }

    public function summary(Request $request)
    {
        $departments = ['cor', 'netto', 'bubut_od', 'bubut_cnc', 'bor', 'finish'];
        $results = null;
        $totalDefects = 0;
        $totalDistribution = 0;

        if ($request->has('generate')) {
            $request->validate([
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'department' => 'required|string|in:cor,netto,bubut_od,bubut_cnc,bor,finish',
            ]);

            $start = $request->start_date;
            $end = $request->end_date;
            $dept = $request->department;

            // 1. Find Heat Numbers that "started" in this department within the date range
            // We look at production_items for this department and find the MIN(production_date) for each HN
            $hnsInRange = ProductionItem::select('heat_number')
                ->where('current_dept', $dept) // This might need history check if item moved
                ->orWhereHas('defects.defectType', function ($q) use ($dept) {
                    $q->where('department', $dept);
                })
                ->groupBy('heat_number')
                ->havingRaw('MIN(production_date) BETWEEN ? AND ?', [$start, $end])
                ->pluck('heat_number');

            // If we want to be more accurate, we should look at history for distribution
            // But if we stick to items:
            $itemsQuery = ProductionItem::whereIn('heat_number', $hnsInRange);

            // Total Distribution: Sum of qty_pcs for these HNs that entered this dept
            // Since items are replicated, we need to find the records representing their entry into this dept.
            // When moving Cor -> Netto, a record with current_dept=netto is created.
            // When moving Netto -> Bubut, the netto record's qty is decremented.
            // So for distribution, we should look at HISTORY.

            $totalDistribution = \App\Models\ProductionHistory::where('to_dept', $dept)
                ->whereHas('item', function ($q) use ($hnsInRange) {
                    $q->whereIn('heat_number', $hnsInRange);
                })
                ->sum('qty_pcs');

            // 2. Aggregate Defects for these HNs in this department
            $defects = ProductionDefect::whereHas('item', function ($q) use ($hnsInRange) {
                $q->whereIn('heat_number', $hnsInRange);
            })
                ->whereHas('defectType', function ($q) use ($dept) {
                    $q->where('department', $dept);
                })
                ->with('defectType')
                ->get();

            $results = $defects->groupBy('defect_type_id')->map(function ($group) {
                return [
                    'name' => $group->first()->defectType->name,
                    'qty' => $group->sum('qty')
                ];
            })->sortByDesc('qty');

            $totalDefects = $results->sum('qty');
        }

        return view('report.summary_defects', [
            'departments' => $departments,
            'results' => $results,
            'totalDefects' => $totalDefects,
            'totalDistribution' => $totalDistribution,
            'startDate' => $request->start_date ?? date('Y-m-01'),
            'endDate' => $request->end_date ?? date('Y-m-d'),
            'selectedDept' => $request->department,
        ]);
    }

    public function export(Request $request, $type)
    {
        $request->validate([
            'date' => 'required|date',
            'department' => 'required|string',
            'defect_type_id' => 'nullable|exists:defect_types,id',
            'count' => 'required|integer|min:1|max:50',
        ]);

        $selectedDept = $request->department;

        $query = ProductionItem::whereDate('production_date', $request->date)
            ->whereHas('defects.defectType', function ($q) use ($request, $selectedDept) {
                if ($selectedDept !== 'all') {
                    $q->where('department', $selectedDept);
                }
                if ($request->defect_type_id && $selectedDept !== 'all') {
                    $q->where('id', $request->defect_type_id);
                }
            })
            ->with([
                'defects' => function ($q) use ($request, $selectedDept) {
                    $q->whereHas('defectType', function ($sq) use ($selectedDept) {
                        if ($selectedDept !== 'all') {
                            $sq->where('department', $selectedDept);
                        }
                    })->with('defectType');

                    if ($request->defect_type_id && $selectedDept !== 'all') {
                        $q->where('defect_type_id', $request->defect_type_id);
                    }
                }
            ])
            ->orderBy('created_at', 'desc');

        if ($selectedDept !== 'all') {
            $query->limit($request->count);
        }

        $items = $query->get()->map(function ($item) {
            $details = $item->defects->groupBy('defect_type_id')->map(function ($group) {
                $type = $group->first()->defectType->name;
                $qty = $group->sum('qty');
                return "{$type} {$qty}";
            })->implode(', ');

            $item->total_defect_qty = $item->defects->sum('qty');
            $item->defect_summary = $details;
            $item->dept_name = $item->defects->first() ? $item->defects->first()->defectType->department : 'unknown';
            return $item;
        });

        if ($selectedDept === 'all') {
            $results = $items->groupBy('dept_name');
        } else {
            $results = $items;
        }

        $totalQty = $items->sum('total_defect_qty');
        $defectType = $request->defect_type_id ? DefectType::find($request->defect_type_id) : null;

        $data = [
            'date' => $request->date,
            'department' => $selectedDept,
            'defectType' => $defectType,
            'results' => $results,
            'totalQty' => $totalQty
        ];

        if ($type === 'pdf') {
            return view('report.defects_print', $data);
        } elseif ($type === 'excel') {
            $typeName = $defectType ? $defectType->name : 'Semua';
            $filename = "Report_Kerusakan_{$selectedDept}_{$typeName}_{$request->date}.xls";

            return Response::make(view('report.defects_excel', $data), 200, [
                'Content-Type' => 'application/vnd.ms-excel',
                'Content-Disposition' => "attachment; filename=\"$filename\"",
            ]);
        }

        return back();
    }
}
