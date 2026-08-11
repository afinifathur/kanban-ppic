<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\LostWaxWorkOrder;
use Illuminate\Http\Request;

class ProductionStatusController extends Controller
{
    public function index(Request $request)
    {
        $search = trim($request->input('search', ''));
        $filter = $request->input('filter', 'active');

        $query = LostWaxWorkOrder::with(['itemReference', 'plans']);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('et_code', 'like', "%{$search}%")
                    ->orWhere('po_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhereHas('itemReference', function ($q2) use ($search) {
                        $q2->where('item_code_snapshot', 'like', "%{$search}%")
                            ->orWhere('item_name_snapshot', 'like', "%{$search}%");
                    });
            });
        }

        $allWorkOrders = $query->orderBy('et_code')->get();

        $woIds = $allWorkOrders->pluck('id')->toArray();
        $treeStats = collect();
        if ($woIds !== []) {
            $treeStats = \App\Models\LostWaxTree::whereIn('work_order_id', $woIds)
                ->selectRaw("work_order_id, COALESCE(current_stage, 'sebelum_scan') as stage_key, SUM(quantity) as total_qty")
                ->groupBy('work_order_id', 'current_stage')
                ->get()
                ->groupBy('work_order_id');
        }

        $rows = [];

        foreach ($allWorkOrders as $wo) {
            $stages = $treeStats->get($wo->id, collect());
            $stageMap = [];

            foreach ($stages as $stat) {
                $stageMap[$stat->stage_key] = (int) $stat->total_qty;
            }

            $layerQtys = [];

            for ($i = 1; $i <= 7; $i++) {
                $layerQtys["layer_{$i}"] = $stageMap["layer_{$i}"] ?? 0;
            }

            $ovenQty = $stageMap['oven'] ?? 0;
            $totalLap = array_sum($layerQtys);
            $totalTreeQty = $totalLap + $ovenQty + ($stageMap['sebelum_scan'] ?? 0);

            if ($totalTreeQty > 0) {
                if ($ovenQty > 0 && $ovenQty === $totalTreeQty) {
                    $prodStatus = 'COMPLETED';
                } else {
                    $prodStatus = 'ACTIVE';
                }
            } else {
                $prodStatus = strtoupper($wo->status);
            }

            $rows[] = [
                'wo_id' => $wo->id,
                'et_code' => $wo->et_code,
                'customer' => $wo->customer_name ?? '-',
                'item_name' => optional($wo->itemReference)->item_name_snapshot ?? '-',
                'aisi' => optional($wo->itemReference)->aisi_snapshot ?? '-',
                'po_qty' => (int) $wo->po_quantity,
                'plan_qty' => $wo->planned_quantity,
                'total_lap' => $totalLap,
                'total_rusak' => null,
                'layer_1' => $layerQtys['layer_1'],
                'layer_2' => $layerQtys['layer_2'],
                'layer_3' => $layerQtys['layer_3'],
                'layer_4' => $layerQtys['layer_4'],
                'layer_5' => $layerQtys['layer_5'],
                'layer_6' => $layerQtys['layer_6'],
                'layer_7' => $layerQtys['layer_7'],
                'oven' => $ovenQty,
                'total_qty' => $totalTreeQty,
                'prod_status' => $prodStatus,
                'before_scan_qty' => $stageMap['sebelum_scan'] ?? 0,
            ];
        }

        if ($filter === 'active') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['prod_status'] === 'ACTIVE'));
        } elseif ($filter === 'completed') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['prod_status'] === 'COMPLETED'));
        }

        return view('lost-wax.production-status.index', compact('rows', 'search', 'filter'));
    }

    public function trees(Request $request)
    {
        $woId = $request->input('work_order_id');

        if (! $woId) {
            return response()->json(['trees' => []]);
        }

        $wo = LostWaxWorkOrder::with('itemReference')->find($woId);

        if (! $wo) {
            return response()->json(['trees' => []]);
        }

        $trees = LostWaxTree::with(['workOrder.itemReference'])
            ->where('work_order_id', $wo->id)
            ->orderBy('tree_number')
            ->get();

        $treeIds = $trees->pluck('id')->toArray();
        $latestEvents = collect();

        if ($treeIds !== []) {
            $latestEventIds = LostWaxScanEvent::whereIn('tree_id', $treeIds)
                ->where('result', 'success')
                ->selectRaw('tree_id, MAX(id) as event_id')
                ->groupBy('tree_id');

            $latestEvents = LostWaxScanEvent::joinSub($latestEventIds, 'latest', function ($join) {
                $join->on('lost_wax_scan_events.id', '=', 'latest.event_id');
            })
                ->get()
                ->keyBy('tree_id');
        }

        return response()->json([
            'et_code' => $wo->et_code,
            'item_name' => optional($wo->itemReference)->item_name_snapshot,
            'trees' => $trees->map(function ($tree) use ($latestEvents) {
                $event = $latestEvents->get($tree->id);

                return [
                    'id' => $tree->id,
                    'barcode' => $tree->barcode,
                    'tree_number' => $tree->tree_number,
                    'quantity' => $tree->quantity,
                    'current_stage' => $tree->current_stage,
                    'current_stage_label' => $tree->current_stage_label,
                    'last_scan_at' => $tree->last_scan_at?->format('d/m/Y H:i'),
                    'aging_minutes' => $event->aging_minutes ?? null,
                    'aging_status' => $event->aging_status ?? null,
                    'aging_label' => $event->aging_label ?? null,
                ];
            }),
            'tree_count' => $trees->count(),
        ]);
    }

    public function exportCsv(Request $request)
    {
        $search = trim($request->input('search', ''));
        $filter = $request->input('filter', 'all');

        $query = LostWaxWorkOrder::with(['itemReference', 'plans']);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('et_code', 'like', "%{$search}%")
                    ->orWhere('po_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhereHas('itemReference', function ($q2) use ($search) {
                        $q2->where('item_code_snapshot', 'like', "%{$search}%")
                            ->orWhere('item_name_snapshot', 'like', "%{$search}%");
                    });
            });
        }

        $allWorkOrders = $query->orderBy('et_code')->get();

        $treeStats = collect();
        $woIds = $allWorkOrders->pluck('id')->toArray();

        if ($woIds !== []) {
            $treeStats = \App\Models\LostWaxTree::whereIn('work_order_id', $woIds)
                ->selectRaw("work_order_id, COALESCE(current_stage, 'sebelum_scan') as stage_key, SUM(quantity) as total_qty")
                ->groupBy('work_order_id', 'current_stage')
                ->get()
                ->groupBy('work_order_id');
        }

        $rows = [];

        foreach ($allWorkOrders as $wo) {
            $stages = $treeStats->get($wo->id, collect());
            $stageMap = [];

            foreach ($stages as $stat) {
                $stageMap[$stat->stage_key] = (int) $stat->total_qty;
            }

            $layerQtys = [];

            for ($i = 1; $i <= 7; $i++) {
                $layerQtys["layer_{$i}"] = $stageMap["layer_{$i}"] ?? 0;
            }

            $ovenQty = $stageMap['oven'] ?? 0;
            $totalLap = array_sum($layerQtys);
            $totalTreeQty = $totalLap + $ovenQty + ($stageMap['sebelum_scan'] ?? 0);

            if ($totalTreeQty > 0) {
                $prodStatus = ($ovenQty > 0 && $ovenQty === $totalTreeQty) ? 'COMPLETED' : 'ACTIVE';
            } else {
                $prodStatus = strtoupper($wo->status);
            }

            $rows[] = array_merge([
                'et_code' => $wo->et_code,
                'customer' => $wo->customer_name ?? '-',
                'item_name' => optional($wo->itemReference)->item_name_snapshot ?? '-',
                'aisi' => optional($wo->itemReference)->aisi_snapshot ?? '-',
                'po_qty' => (int) $wo->po_quantity,
                'plan_qty' => $wo->planned_quantity,
                'total_lap' => $totalLap,
                'total_rusak' => '-',
            ], $layerQtys, [
                'oven' => $ovenQty,
                'prod_status' => $prodStatus,
            ]);
        }

        if ($filter === 'active') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['prod_status'] === 'ACTIVE'));
        } elseif ($filter === 'completed') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['prod_status'] === 'COMPLETED'));
        }

        $filename = 'lost-wax-production-status-'.now()->format('Ymd-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        $callback = function () use ($rows) {
            $output = fopen('php://output', 'w');
            fputcsv($output, [
                'Kode Cust', 'Product Name', 'AISI',
                'PO Qty', 'Plan Qty', 'Total Lap.', 'Total Rusak',
                'Lap.1', 'Rusak', 'Lap.2', 'Rusak', 'Lap.3', 'Rusak',
                'Lap.4', 'Rusak', 'Lap.5', 'Rusak', 'Lap.6', 'Rusak',
                'Lap.7', 'Rusak', 'Oven', 'Status',
            ]);

            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['et_code'], $row['item_name'], $row['aisi'],
                    $row['po_qty'], $row['plan_qty'], $row['total_lap'], $row['total_rusak'],
                    $row['layer_1'], '-', $row['layer_2'], '-', $row['layer_3'], '-',
                    $row['layer_4'], '-', $row['layer_5'], '-', $row['layer_6'], '-',
                    $row['layer_7'], '-', $row['oven'], $row['prod_status'],
                ]);
            }

            fclose($output);
        };

        return response()->stream($callback, 200, $headers);
    }
}
