<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        // 1. Live Stock Stats
        $stock = \App\Models\ProductionItem::selectRaw('current_dept, SUM(qty_pcs) as total_pcs, SUM(qty_pcs * weight_kg) as total_kg')
            ->groupBy('current_dept')
            ->get()
            ->keyBy('current_dept');

        $depts = ['cor', 'netto', 'bubut_od', 'bubut_cnc', 'bor', 'finish'];
        $stats = [];
        foreach ($depts as $dept) {
            if ($dept === 'cor') {
                // For 'Cor', we show 'Rencana Cor' (Queue/Remaining)
                $planSummary = \App\Models\ProductionPlan::selectRaw('SUM(qty_remaining) as total_pcs, SUM(qty_remaining * weight) as total_kg')
                    ->first();
                $stats['cor'] = [
                    'total_pcs' => $planSummary->total_pcs ?? 0,
                    'total_kg' => $planSummary->total_kg ?? 0,
                ];
            } else {
                $stats[$dept] = [
                    'total_pcs' => $stock[$dept]->total_pcs ?? 0,
                    'total_kg' => $stock[$dept]->total_kg ?? 0,
                ];
            }
        }

        // 2. Movement History (Last 7 Days)
        $dates = [];
        for ($i = 6; $i >= 0; $i--) {
            $dates[] = now()->subDays($i)->format('Y-m-d');
        }

        // Fetch history with item details to get production_date if needed
        $movements = \App\Models\ProductionHistory::with('item')
            ->where('moved_at', '>=', now()->subDays(7))
            ->get();

        // Stages to track (6 movements)
        $stages = [
            'netto' => 'Hasil Cor',
            'bubut_od' => 'Hasil Netto',
            'bubut_cnc' => 'Hasil Bubut OD',
            'bor' => 'Hasil Bubut CNC',
            'finish' => 'Hasil Bor',
            'completed' => 'Hasil Finish',
        ];

        $lineStats = [
            'pcs' => [],
            'kg' => [],
        ];

        foreach ($stages as $stageKey => $stageName) {
            $lineStats['pcs'][$stageName] = [];
            $lineStats['kg'][$stageName] = [];

            // Initialize for lines 1-4
            for ($l = 1; $l <= 4; $l++) {
                $lineStats['pcs'][$stageName][$l] = [];
                $lineStats['kg'][$stageName][$l] = [];
            }

            foreach ($dates as $date) {
                // Filter movements for this stage and date
                // Priority: Use the associated item's production_date for reporting alignment
                $dayMoves = $movements->filter(function ($m) use ($date, $stageKey) {
                    $dateToUse = ($m->item && $m->item->production_date)
                        ? $m->item->production_date->format('Y-m-d')
                        : $m->moved_at->format('Y-m-d');
                    return $dateToUse === $date && $m->to_dept === $stageKey;
                });

                for ($l = 1; $l <= 4; $l++) {
                    $lineMoves = $dayMoves->filter(fn($m) => (int) $m->line_number === $l);
                    $lineStats['pcs'][$stageName][$l][] = $lineMoves->sum('qty_pcs');
                    $lineStats['kg'][$stageName][$l][] = $lineMoves->sum(fn($m) => $m->qty_pcs * $m->weight_kg);
                }
            }
        }

        return view('dashboard', compact('stats', 'depts', 'dates', 'lineStats', 'stages'));
    }

    public function getChartData()
    {
        // ... AJAX structure if needed, or pass in view.
        // I will do migration first if I want to be 100% accurate.
    }
}
