<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\LostWaxWorkOrder;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $scope = auth()->user()->product_scope;
        $isPpic = auth()->user()->hasRole('ppic');

        $search = trim($request->input('search', ''));
        $filterEt = trim($request->input('et', ''));
        $filterStage = trim($request->input('stage', ''));
        $filterAging = trim($request->input('aging', ''));
        $filterAnomaly = $request->has('anomaly');

        $overview = $this->buildOverview($isPpic, $scope);
        $stageDistribution = $this->buildStageDistribution($isPpic, $scope);
        $agingByStage = $this->buildAgingByStage($isPpic, $scope);
        $hotList = $this->buildHotList($filterEt, $filterStage, $filterAging, $isPpic, $scope);
        $searchResult = $search ? $this->barcodeSearch($search, $isPpic, $scope) : null;
        $etAggregate = $this->buildEtAggregate($filterEt, $isPpic, $scope);

        if ($isPpic && $scope) {
            $allEts = \App\Models\LostWaxPrintOrderLine::whereHas('productionPlan', function ($q) use ($scope) {
                $q->where('product_scope', $scope);
            })->pluck('code')->unique()->sort()->values();
        } else {
            $legacyEts = LostWaxWorkOrder::pluck('et_code');
            $newEts = \App\Models\LostWaxPrintOrderLine::pluck('code');
            $allEts = $legacyEts->concat($newEts)->unique()->sort()->values();
        }

        $filters = [
            'ets' => $allEts,
            'stages' => config('lost_wax.stages', []),
            'aging' => ['too_fast' => 'Terlalu Cepat', 'normal' => 'Normal', 'too_long' => 'Terlalu Lama'],
            'current_et' => $filterEt,
            'current_stage' => $filterStage,
            'current_aging' => $filterAging,
            'current_anomaly' => $filterAnomaly,
        ];

        return view('lost-wax.dashboard', compact(
            'overview',
            'stageDistribution',
            'agingByStage',
            'hotList',
            'searchResult',
            'etAggregate',
            'filters',
            'search',
            'filterEt',
            'filterStage',
            'filterAging',
        ));
    }

    private function buildOverview(bool $isPpic, ?string $scope): array
    {
        if ($isPpic && $scope) {
            $activeWos = \App\Models\LostWaxPrintOrder::whereIn('status', ['DRAFT', 'ISSUED', 'PRINTED'])
                ->whereHas('lines.productionPlan', function ($q) use ($scope) {
                    $q->where('product_scope', $scope);
                })->count();

            $totalTrees = LostWaxTree::whereHas('printOrderLine.productionPlan', function ($q) use ($scope) {
                $q->where('product_scope', $scope);
            })->count();

            $inProcess = LostWaxTree::whereNotNull('current_stage')
                ->where('current_stage', '!=', 'oven')
                ->whereHas('printOrderLine.productionPlan', function ($q) use ($scope) {
                    $q->where('product_scope', $scope);
                })->count();

            $completed = LostWaxTree::where('current_stage', 'oven')
                ->whereHas('printOrderLine.productionPlan', function ($q) use ($scope) {
                    $q->where('product_scope', $scope);
                })->count();

            $agingAnomaly = LostWaxScanEvent::where('result', 'success')
                ->where('aging_status', 'too_long')
                ->whereRaw('id IN (SELECT MAX(id) FROM lost_wax_scan_events WHERE result = ? GROUP BY tree_id)', ['success'])
                ->whereHas('tree.printOrderLine.productionPlan', function ($q) use ($scope) {
                    $q->where('product_scope', $scope);
                })->count();

            $rejectedCount = LostWaxScanEvent::where('result', 'rejected')
                ->whereHas('tree.printOrderLine.productionPlan', function ($q) use ($scope) {
                    $q->where('product_scope', $scope);
                })->count();
        } else {
            $activeLegacy = LostWaxWorkOrder::whereIn('status', ['draft', 'planned', 'active'])->count();
            $activePrintOrders = \App\Models\LostWaxPrintOrder::whereIn('status', ['DRAFT', 'ISSUED', 'PRINTED'])->count();
            $activeWos = $activeLegacy + $activePrintOrders;

            $totalTrees = LostWaxTree::count();
            $inProcess = LostWaxTree::whereNotNull('current_stage')
                ->where('current_stage', '!=', 'oven')
                ->count();
            $completed = LostWaxTree::where('current_stage', 'oven')->count();

            $agingAnomaly = LostWaxScanEvent::where('result', 'success')
                ->where('aging_status', 'too_long')
                ->whereRaw('id IN (SELECT MAX(id) FROM lost_wax_scan_events WHERE result = ? GROUP BY tree_id)', ['success'])
                ->count();

            $rejectedCount = LostWaxScanEvent::where('result', 'rejected')->count();
        }

        return compact('activeWos', 'totalTrees', 'inProcess', 'completed', 'agingAnomaly', 'rejectedCount');
    }

    private function buildStageDistribution(bool $isPpic, ?string $scope): array
    {
        $query = LostWaxTree::query();
        if ($isPpic && $scope) {
            $query->whereHas('printOrderLine.productionPlan', function ($q) use ($scope) {
                $q->where('product_scope', $scope);
            });
        }

        $raw = $query->selectRaw("COALESCE(current_stage, 'sebelum_scan') as stage_key, COUNT(*) as count")
            ->groupBy('current_stage')
            ->orderByRaw("CASE current_stage
                WHEN NULL THEN 0
                WHEN 'layer_1' THEN 1
                WHEN 'layer_2' THEN 2
                WHEN 'layer_3' THEN 3
                WHEN 'layer_4' THEN 4
                WHEN 'layer_5' THEN 5
                WHEN 'layer_6' THEN 6
                WHEN 'layer_7' THEN 7
                WHEN 'oven' THEN 8
                ELSE 9 END")
            ->get();

        $stages = config('lost_wax.stages', []);
        $distribution = [];

        $totalQuery = LostWaxTree::query();
        if ($isPpic && $scope) {
            $totalQuery->whereHas('printOrderLine.productionPlan', function ($q) use ($scope) {
                $q->where('product_scope', $scope);
            });
        }
        $total = $totalQuery->count();

        foreach ($raw as $row) {
            $key = $row->stage_key;
            $label = $key === 'sebelum_scan' ? 'Sebelum Scan' : ($stages[$key] ?? ucfirst(str_replace('_', ' ', (string) $key)));
            $distribution[] = [
                'key' => $key,
                'label' => $label,
                'count' => $row->count,
                'pct' => $total > 0 ? round(($row->count / $total) * 100, 1) : 0,
            ];
        }

        return $distribution;
    }

    private function buildAgingByStage(bool $isPpic, ?string $scope): array
    {
        $latestEvents = LostWaxScanEvent::where('result', 'success')
            ->whereNotNull('aging_status')
            ->selectRaw('tree_id, MAX(id) as event_id')
            ->groupBy('tree_id');

        $query = LostWaxScanEvent::joinSub($latestEvents, 'latest', function ($join) {
            $join->on('lost_wax_scan_events.id', '=', 'latest.event_id');
        })
            ->join('lost_wax_trees', 'lost_wax_scan_events.tree_id', '=', 'lost_wax_trees.id');

        if ($isPpic && $scope) {
            $query->whereHas('tree.printOrderLine.productionPlan', function ($q) use ($scope) {
                $q->where('product_scope', $scope);
            });
        }

        $agingRaw = $query->selectRaw("COALESCE(lost_wax_trees.current_stage, 'sebelum_scan') as stage_key, lost_wax_scan_events.aging_status, COUNT(*) as count")
            ->groupBy('lost_wax_trees.current_stage', 'lost_wax_scan_events.aging_status')
            ->orderBy('lost_wax_trees.current_stage')
            ->orderBy('lost_wax_scan_events.aging_status')
            ->get();

        $stages = config('lost_wax.stages', []);
        $result = [];

        foreach ($agingRaw as $row) {
            $key = $row->stage_key;
            $label = $key === 'sebelum_scan' ? 'Sebelum Scan' : ($stages[$key] ?? $key);

            if (! isset($result[$key])) {
                $result[$key] = ['label' => $label, 'normal' => 0, 'too_fast' => 0, 'too_long' => 0, 'total' => 0];
            }

            $status = $row->aging_status ?? 'normal';
            $result[$key][$status] = ($result[$key][$status] ?? 0) + $row->count;
            $result[$key]['total'] += $row->count;
        }

        return $result;
    }

    private function buildHotList(string $filterEt, string $filterStage, string $filterAging, bool $isPpic, ?string $scope): array
    {
        $latestEventIds = LostWaxScanEvent::where('result', 'success')
            ->selectRaw('tree_id, MAX(id) as event_id')
            ->groupBy('tree_id');

        $query = LostWaxTree::with(['workOrder.itemReference', 'printOrderLine.printOrder', 'printOrderLine.productionPlan', 'scanEvents' => function ($q) {
            $q->where('result', 'success')->latest()->limit(2);
        }])
            ->leftJoinSub($latestEventIds, 'latest_event', function ($join) {
                $join->on('lost_wax_trees.id', '=', 'latest_event.tree_id');
            })
            ->leftJoin('lost_wax_scan_events as le', 'le.id', '=', 'latest_event.event_id')
            ->select('lost_wax_trees.*', 'le.aging_status', 'le.aging_minutes', 'le.scanned_at as event_scanned_at')
            ->where(function ($q) {
                $q->where('le.aging_status', 'too_long')
                    ->orWhereNull('le.aging_status'); // include never-scanned or without aging
            });

        if ($isPpic && $scope) {
            $query->whereHas('printOrderLine.productionPlan', function ($q) use ($scope) {
                $q->where('product_scope', $scope);
            });
        }

        if ($filterEt) {
            $woIds = LostWaxWorkOrder::where('et_code', 'like', "%{$filterEt}%")->pluck('id')->toArray();
            $lineIds = \App\Models\LostWaxPrintOrderLine::where('code', 'like', "%{$filterEt}%")->pluck('id')->toArray();
            $query->where(function ($q) use ($woIds, $lineIds) {
                $q->whereIn('lost_wax_trees.work_order_id', $woIds)
                    ->orWhereIn('lost_wax_trees.lost_wax_print_order_line_id', $lineIds);
            });
        }

        if ($filterStage) {
            if ($filterStage === 'sebelum_scan') {
                $query->whereNull('lost_wax_trees.current_stage');
            } else {
                $query->where('lost_wax_trees.current_stage', $filterStage);
            }
        }

        if ($filterAging) {
            $query->where('le.aging_status', $filterAging);
        }

        $hotList = $query
            ->orderByRaw("CASE WHEN le.aging_status = 'too_long' THEN 0 ELSE 1 END")
            ->orderBy('le.aging_minutes', 'desc')
            ->orderBy('lost_wax_trees.id')
            ->limit(20)
            ->get();

        $result = [];

        foreach ($hotList as $tree) {
            $lastSuccess = $tree->scanEvents->first();

            $result[] = [
                'tree' => $tree,
                'barcode' => $tree->barcode,
                'tree_number' => $tree->tree_number,
                'et_code' => $tree->getSourceCode() ?? '-',
                'item_code' => $tree->getSourceItemCode() ?? '-',
                'item_name' => $tree->getSourceProduct() ?? '-',
                'quantity' => $tree->quantity,
                'current_stage' => $tree->current_stage,
                'current_stage_label' => $tree->current_stage_label,
                'last_scan_at' => $tree->last_scan_at,
                'aging_minutes' => $tree->aging_minutes,
                'aging_status' => $tree->aging_status,
                'aging_label' => $lastSuccess?->aging_label,
                'has_anomaly' => $tree->scanEvents()->where('result', 'rejected')->exists(),
            ];
        }

        return $result;
    }

    private function barcodeSearch(string $search, bool $isPpic, ?string $scope): ?array
    {
        $treeQuery = LostWaxTree::with(['workOrder.itemReference', 'printOrderLine.printOrder', 'printOrderLine.productionPlan', 'scanEvents' => function ($q) {
            $q->where('result', 'success')->latest()->limit(1);
        }])
            ->where('barcode', $search);

        if ($isPpic && $scope) {
            $treeQuery->whereHas('printOrderLine.productionPlan', function ($q) use ($scope) {
                $q->where('product_scope', $scope);
            });
        }

        $tree = $treeQuery->first();

        if ($tree) {
            $lastEvent = $tree->scanEvents->first();

            return [
                'found' => true,
                'tree' => $tree,
                'barcode' => $tree->barcode,
                'tree_number' => $tree->tree_number,
                'et_code' => $tree->getSourceCode() ?? '-',
                'item_code' => $tree->getSourceItemCode() ?? '-',
                'item_name' => $tree->getSourceProduct() ?? '-',
                'aisi' => $tree->getSourceAisi() ?? '-',
                'quantity' => $tree->quantity,
                'current_stage' => $tree->current_stage,
                'current_stage_label' => $tree->current_stage_label,
                'last_scan_at' => $tree->last_scan_at,
                'aging_label' => $lastEvent?->aging_label,
                'aging_status' => $tree->aging_status ?? $lastEvent?->aging_status,
                'next_stage' => $tree->nextStage(),
                'next_stage_label' => $tree->nextStage() ? (config('lost_wax.stages.'.$tree->nextStage(), '') ?? $tree->nextStage()) : null,
            ];
        }

        if ($isPpic && $scope) {
            // PPIC users shouldn't match legacy work orders which have NULL scope
            return [
                'found' => false,
                'matched_wos' => collect(),
            ];
        }

        // Search by ET for non-PPIC
        $workOrders = LostWaxWorkOrder::with(['itemReference', 'trees' => function ($q) {
            $q->orderBy('tree_number')->limit(5);
        }])
            ->where('et_code', 'like', "%{$search}%")
            ->orWhere('po_number', 'like', "%{$search}%")
            ->orWhereHas('itemReference', function ($q) use ($search) {
                $q->where('item_code_snapshot', 'like', "%{$search}%");
            })
            ->limit(5)
            ->get();

        if ($workOrders->isNotEmpty()) {
            return [
                'found' => false,
                'matched_wos' => $workOrders->map(fn ($wo) => [
                    'et_code' => $wo->et_code,
                    'item_code' => optional($wo->itemReference)->item_code_snapshot ?? '-',
                    'item_name' => optional($wo->itemReference)->item_name_snapshot ?? '-',
                    'tree_count' => $wo->trees->count(),
                    'trees' => $wo->trees,
                ]),
            ];
        }

        return [
            'found' => false,
            'matched_wos' => collect(),
        ];
    }

    private function buildEtAggregate(string $filterEt, bool $isPpic, ?string $scope): array
    {
        $result = [];

        // 1. Legacy active work orders (only if NOT PPIC)
        if (! ($isPpic && $scope)) {
            $orderQuery = LostWaxWorkOrder::with(['itemReference', 'plans', 'wipEntries'])
                ->whereIn('status', ['draft', 'planned', 'active']);

            if ($filterEt) {
                $orderQuery->where('et_code', 'like', "%{$filterEt}%");
            }

            $workOrders = $orderQuery->orderByDesc('id')->limit(15)->get();

            foreach ($workOrders as $wo) {
                $treeStats = LostWaxTree::where('work_order_id', $wo->id)
                    ->selectRaw("COALESCE(current_stage, 'sebelum_scan') as stage_key, COUNT(*) as count, SUM(quantity) as total_qty")
                    ->groupBy('current_stage')
                    ->orderBy('current_stage')
                    ->get();

                $stages = config('lost_wax.stages', []);
                $distribution = [];

                foreach ($treeStats as $stat) {
                    $key = $stat->stage_key;
                    $label = $key === 'sebelum_scan' ? 'Sebelum Scan' : ($stages[$key] ?? $key);
                    $distribution[$key] = [
                        'label' => $label,
                        'count' => $stat->count,
                        'qty' => (int) $stat->total_qty,
                    ];
                }

                $hasAnomaly = LostWaxScanEvent::whereHas('tree', function ($q) use ($wo) {
                    $q->where('work_order_id', $wo->id);
                })->where('result', 'rejected')->exists();

                $hasAgingIssue = LostWaxScanEvent::whereHas('tree', function ($q) use ($wo) {
                    $q->where('work_order_id', $wo->id);
                })->where('aging_status', 'too_long')
                    ->where('result', 'success')
                    ->exists();

                $result[] = [
                    'wo' => $wo,
                    'et_code' => $wo->et_code,
                    'item_code' => optional($wo->itemReference)->item_code_snapshot ?? '-',
                    'item_name' => optional($wo->itemReference)->item_name_snapshot ?? '-',
                    'po_number' => $wo->po_number,
                    'po_quantity' => $wo->po_quantity,
                    'net_requirement' => $wo->net_requirement_quantity,
                    'planned_quantity' => $wo->planned_quantity,
                    'assembly_output' => $wo->assembly_output_quantity,
                    'tree_count' => $wo->tree_count,
                    'tree_quantity' => $wo->tree_quantity,
                    'distribution' => $distribution,
                    'has_anomaly' => $hasAnomaly,
                    'has_aging_issue' => $hasAgingIssue,
                ];
            }
        }

        // 2. Active print order lines (New flow)
        $lineQuery = \App\Models\LostWaxPrintOrderLine::with(['printOrder', 'productionPlan', 'trees'])
            ->whereHas('printOrder', function ($q) {
                $q->whereIn('status', ['DRAFT', 'ISSUED', 'PRINTED']);
            });

        if ($isPpic && $scope) {
            $lineQuery->whereHas('productionPlan', function ($q) use ($scope) {
                $q->where('product_scope', $scope);
            });
        }

        if ($filterEt) {
            $lineQuery->where('code', 'like', "%{$filterEt}%");
        }

        $lines = $lineQuery->orderByDesc('id')->limit(15)->get();

        foreach ($lines as $line) {
            $treeStats = LostWaxTree::where('lost_wax_print_order_line_id', $line->id)
                ->selectRaw("COALESCE(current_stage, 'sebelum_scan') as stage_key, COUNT(*) as count, SUM(quantity) as total_qty")
                ->groupBy('current_stage')
                ->orderBy('current_stage')
                ->get();

            $stages = config('lost_wax.stages', []);
            $distribution = [];

            foreach ($treeStats as $stat) {
                $key = $stat->stage_key;
                $label = $key === 'sebelum_scan' ? 'Sebelum Scan' : ($stages[$key] ?? $key);
                $distribution[$key] = [
                    'label' => $label,
                    'count' => $stat->count,
                    'qty' => (int) $stat->total_qty,
                ];
            }

            $hasAnomaly = LostWaxScanEvent::whereHas('tree', function ($q) use ($line) {
                $q->where('lost_wax_print_order_line_id', $line->id);
            })->where('result', 'rejected')->exists();

            $hasAgingIssue = LostWaxScanEvent::whereHas('tree', function ($q) use ($line) {
                $q->where('lost_wax_print_order_line_id', $line->id);
            })->where('aging_status', 'too_long')
                ->where('result', 'success')
                ->exists();

            $result[] = [
                'wo' => null,
                'print_order_line' => $line,
                'et_code' => $line->code,
                'item_code' => $line->code,
                'item_name' => $line->item_name,
                'po_number' => $line->printOrder?->print_order_number ?? '-',
                'po_quantity' => $line->productionPlan?->qty_planned ?? 0,
                'net_requirement' => $line->qty_ordered,
                'planned_quantity' => $line->qty_ordered,
                'assembly_output' => $line->qty_actual_good ?? 0,
                'tree_count' => $line->trees->count(),
                'tree_quantity' => $line->trees->sum('quantity'),
                'distribution' => $distribution,
                'has_anomaly' => $hasAnomaly,
                'has_aging_issue' => $hasAgingIssue,
            ];
        }

        return $result;
    }
}
