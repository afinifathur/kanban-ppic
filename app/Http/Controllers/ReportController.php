<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductionItem;
use Illuminate\Support\Facades\Response;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $departments = ['cor', 'netto', 'bubut_od', 'bubut_cnc', 'bor', 'finish'];

        $results = null;
        $totalPcs = 0;
        $totalKg = 0;

        if ($request->has('generate')) {
            $request->validate([
                'date' => 'required|date',
                'department' => 'required|string',
                'line' => 'required|integer|min:1|max:4',
                'count' => 'required|integer|min:1|max:20',
            ]);

            // Logic: Get top N items from the specified department and line
            // If specific date is needed implicitly (e.g. items created on that date or just current backlog?), 
            // the user said "hari ini kita akan perintahkan... 10 heat number harus selesai".
            // It implies taking the *available* stock in that department/line to *assign* as work.
            // So we query the current backlog in that department/line. The 'date' parameter is likely just for the report header "Date of SPK".

            $query = ProductionItem::where('current_dept', $request->department)
                ->where('line_number', $request->line)
                ->orderBy('created_at') // FIFO
                ->limit($request->count);

            $results = $query->get();
            $totalPcs = $results->sum('qty_pcs');
            $totalKg = $results->sum(fn($r) => $r->qty_pcs * $r->weight_kg);
        }

        return view('report.index', [
            'departments' => $departments,
            'results' => $results,
            'totalPcs' => $totalPcs,
            'totalKg' => $totalKg,
            // Pass back input
            'selectedDate' => $request->date ?? date('Y-m-d'),
            'selectedDept' => $request->department,
            'selectedLine' => $request->line,
            'selectedCount' => $request->count ?? 10
        ]);
    }

    public function export(Request $request, $type)
    {
        $request->validate([
            'date' => 'required|date',
            'department' => 'required|string',
            'line' => 'required|integer|min:1|max:4',
            'count' => 'required|integer|min:1|max:20',
        ]);

        $results = ProductionItem::where('current_dept', $request->department)
            ->where('line_number', $request->line)
            ->orderBy('created_at')
            ->limit($request->count)
            ->get();

        $totalPcs = $results->sum('qty_pcs');
        $totalKg = $results->sum(fn($r) => $r->qty_pcs * $r->weight_kg);

        $data = [
            'date' => $request->date,
            'department' => $request->department,
            'line' => $request->line,
            'results' => $results,
            'totalPcs' => $totalPcs,
            'totalKg' => $totalKg
        ];

        if ($type === 'pdf') {
            // Return view optimized for printing (browser print)
            return view('report.print', $data);
        } elseif ($type === 'excel') {
            // Generate HTML table for Excel
            $filename = "SPK_{$request->department}_Line{$request->line}_{$request->date}.xls";

            return Response::make(view('report.excel', $data), 200, [
                'Content-Type' => 'application/vnd.ms-excel',
                'Content-Disposition' => "attachment; filename=\"$filename\"",
            ]);
        }

        return back();
    }
}
