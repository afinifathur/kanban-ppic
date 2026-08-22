<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\LostWaxWorkOrder;
use App\Models\ProductionPlan;
use Illuminate\Http\Request;

class ProductionStatusController extends Controller
{
    public function index(Request $request)
    {
        $search = trim($request->input('search', ''));
        $filter = $request->input('filter', 'active');
        $customer = trim($request->input('customer', ''));
        $po_number = trim($request->input('po_number', ''));
        $aisi = trim($request->input('aisi', ''));

        $rows = $this->getAggregatedRows($search, $filter, $customer, $po_number, $aisi);

        $allCustomers = $this->getDistinctFieldValues('customer');
        $allPos = $this->getDistinctFieldValues('po_number');
        $allAisi = $this->getDistinctFieldValues('aisi');

        return view('lost-wax.production-status.index', compact(
            'rows', 'search', 'filter', 'customer', 'po_number', 'aisi',
            'allCustomers', 'allPos', 'allAisi'
        ));
    }

    public function trees(Request $request)
    {
        $woId = $request->input('work_order_id');
        $lineId = $request->input('print_order_line_id');

        if ($woId) {
            $wo = LostWaxWorkOrder::with('itemReference')->find($woId);

            if (! $wo) {
                return response()->json(['trees' => []]);
            }

            $trees = LostWaxTree::with(['workOrder.itemReference'])
                ->where('work_order_id', $wo->id)
                ->orderBy('tree_number')
                ->get();

            $etCode = $wo->et_code;
            $itemName = optional($wo->itemReference)->item_name_snapshot;
        } elseif ($lineId) {
            $line = LostWaxPrintOrderLine::find($lineId);

            if (! $line) {
                return response()->json(['trees' => []]);
            }

            // Get all trees for this print order line's plan
            $lineIds = LostWaxPrintOrderLine::where('production_plan_id', $line->production_plan_id)
                ->pluck('id')
                ->toArray();

            $trees = LostWaxTree::with(['printOrderLine.printOrder', 'printOrderLine.productionPlan'])
                ->whereIn('lost_wax_print_order_line_id', $lineIds)
                ->orderBy('tree_number')
                ->get();

            $etCode = $line->code;
            $itemName = $line->item_name;
        } else {
            return response()->json(['trees' => []]);
        }

        $treeIds = $trees->pluck('id')->toArray();
        $latestEvents = collect();

        if ($treeIds !== []) {
            $latestEventIds = LostWaxScanEvent::whereIn('tree_id', $treeIds)
                ->where('result', 'success')
                ->whereDoesntHave('void')
                ->selectRaw('tree_id, MAX(id) as event_id')
                ->groupBy('tree_id');

            $latestEvents = LostWaxScanEvent::joinSub($latestEventIds, 'latest', function ($join) {
                $join->on('lost_wax_scan_events.id', '=', 'latest.event_id');
            })
                ->get()
                ->keyBy('tree_id');
        }

        return response()->json([
            'et_code' => $etCode,
            'item_name' => $itemName,
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
        $customer = trim($request->input('customer', ''));
        $po_number = trim($request->input('po_number', ''));
        $aisi = trim($request->input('aisi', ''));

        $rows = $this->getAggregatedRows($search, $filter, $customer, $po_number, $aisi);

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
                'Cetak', 'R', 'Rangkai', 'R',
                'Lap.1', 'R', 'Lap.2', 'R', 'Lap.3', 'R',
                'Lap.4', 'R', 'Lap.5', 'R', 'Lap.6', 'R',
                'Lap.7', 'R', 'Oven', 'Status',
            ]);

            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['code'], $row['product_name'], $row['aisi'],
                    $row['planned_qty'] > 0 ? $row['planned_qty'] : '-',
                    $row['scheduled_qty'] > 0 ? $row['scheduled_qty'] : '-',
                    $row['total_lap'] > 0 ? $row['total_lap'] : '-',
                    $row['overall_defect'] > 0 ? $row['overall_defect'] : '-',
                    $row['actual_good'] > 0 ? $row['actual_good'] : '-',
                    $row['actual_defect'] > 0 ? $row['actual_defect'] : '-',
                    $row['assembly_qty'] > 0 ? $row['assembly_qty'] : '-',
                    $row['before_scan_qty'] > 0 ? $row['before_scan_qty'] : '-',
                    $row['layer_1'] > 0 ? $row['layer_1'] : '-', '-',
                    $row['layer_2'] > 0 ? $row['layer_2'] : '-', '-',
                    $row['layer_3'] > 0 ? $row['layer_3'] : '-', '-',
                    $row['layer_4'] > 0 ? $row['layer_4'] : '-', '-',
                    $row['layer_5'] > 0 ? $row['layer_5'] : '-', '-',
                    $row['layer_6'] > 0 ? $row['layer_6'] : '-', '-',
                    $row['layer_7'] > 0 ? $row['layer_7'] : '-', '-',
                    $row['oven_qty'] > 0 ? $row['oven_qty'] : '-',
                    $row['status'],
                ]);
            }

            fclose($output);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function getAggregatedRows(string $search = '', string $filter = 'all', string $customer = '', string $po_number = '', string $aisi = ''): array
    {
        // 1. Fetch Legacy Work Orders
        $woQuery = LostWaxWorkOrder::with(['itemReference', 'plans']);
        if ($search !== '') {
            $woQuery->where(function ($q) use ($search) {
                $q->where('et_code', 'like', "%{$search}%")
                    ->orWhere('po_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhereHas('itemReference', function ($q2) use ($search) {
                        $q2->where('item_code_snapshot', 'like', "%{$search}%")
                            ->orWhere('item_name_snapshot', 'like', "%{$search}%");
                    });
            });
        }
        if ($customer !== '') {
            $woQuery->where('customer_name', 'like', "%{$customer}%");
        }
        if ($po_number !== '') {
            $woQuery->where('po_number', 'like', "%{$po_number}%");
        }
        if ($aisi !== '') {
            $woQuery->whereHas('itemReference', function ($q) use ($aisi) {
                $q->where('aisi_snapshot', 'like', "%{$aisi}%");
            });
        }

        // Apply RBAC Product Scope
        $scope = auth()->user()->product_scope;
        if (auth()->user()->hasRole('ppic') && $scope) {
            if ($scope === 'FLANGE_STAINLESS') {
                $woQuery->whereIn('family_code', ['3', '4']);
            } elseif ($scope === 'FLANGE_BESI') {
                $woQuery->whereIn('family_code', ['6']);
            } elseif ($scope === 'FITTING_STAINLESS') {
                $woQuery->whereIn('family_code', ['1', '2']);
            } else {
                $woQuery->whereRaw('1=0');
            }
        }

        $legacyWos = $woQuery->orderBy('et_code')->get();

        $woIds = $legacyWos->pluck('id')->toArray();
        $legacyTreeStats = collect();
        if ($woIds !== []) {
            $legacyTreeStats = LostWaxTree::whereIn('work_order_id', $woIds)
                ->selectRaw("work_order_id, COALESCE(current_stage, 'sebelum_scan') as stage_key, SUM(quantity) as total_qty")
                ->groupBy('work_order_id', 'current_stage')
                ->get()
                ->groupBy('work_order_id');
        }

        $rows = [];

        foreach ($legacyWos as $wo) {
            $stages = $legacyTreeStats->get($wo->id, collect());
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

            $scheduledQty = (int) $wo->planned_quantity;
            $cetak_good = (int) $wo->moulding_output_quantity;
            $cetak_defect = ($cetak_good > 0) ? max(0, $scheduledQty - $cetak_good) : 0;

            $rangkai_good = (int) $wo->assembly_output_quantity;
            if ($totalTreeQty > 0) {
                $rangkai_good = $totalTreeQty;
            }
            $rangkai_defect = ($rangkai_good > 0) ? max(0, $cetak_good - $rangkai_good) : 0;

            // Display values based on stage movement:
            $ctk_display = 0;
            $r_ctk_display = 0;
            if ($rangkai_good > 0) {
                if ($rangkai_good < $cetak_good) {
                    $ctk_display = $cetak_good;
                    $r_ctk_display = $cetak_defect;
                }
            } else {
                $ctk_display = $cetak_good;
                $r_ctk_display = $cetak_defect;
            }

            $total_scanned = $totalLap + $ovenQty;
            $rgki_display = 0;
            $r_rgki_display = 0;
            if ($total_scanned > 0) {
                if ($total_scanned < $rangkai_good) {
                    $rgki_display = $rangkai_good;
                    $r_rgki_display = $rangkai_defect;
                }
            } else {
                if ($rangkai_good > 0) {
                    $rgki_display = $rangkai_good;
                    $r_rgki_display = $rangkai_defect;
                }
            }

            $before_scan_qty = $stageMap['sebelum_scan'] ?? 0;
            if ($totalTreeQty == 0 && $rangkai_good > 0) {
                $before_scan_qty = $rangkai_good;
            }

            if ($totalTreeQty > 0) {
                $prodStatus = ($ovenQty > 0 && $ovenQty === $totalTreeQty) ? 'COMPLETED' : 'ACTIVE';
            } else {
                $prodStatus = strtoupper($wo->status);
            }

            $rows[] = [
                'source_type' => 'legacy_work_order',
                'source_id' => $wo->id,
                'code' => $wo->et_code,
                'production_plan' => '-',
                'customer' => $wo->customer_name ?? '-',
                'product_name' => optional($wo->itemReference)->item_name_snapshot ?? '-',
                'aisi' => optional($wo->itemReference)->aisi_snapshot ?? '-',
                'size' => '-',
                'planned_qty' => (int) $wo->po_quantity,
                'scheduled_qty' => $scheduledQty,
                'actual_good' => $cetak_good,
                'actual_defect' => $cetak_defect,
                'assembly_qty' => $rangkai_good,
                'before_scan_qty' => $before_scan_qty,
                'ctk_display' => $ctk_display,
                'r_ctk_display' => $r_ctk_display,
                'rgki_display' => $rgki_display,
                'r_rgki_display' => $r_rgki_display,
                'overall_defect' => $cetak_defect + $rangkai_defect,
                'total_lap' => $totalLap,
                'tree_count' => $wo->trees()->count(),
                'layer_1' => $layerQtys['layer_1'],
                'layer_2' => $layerQtys['layer_2'],
                'layer_3' => $layerQtys['layer_3'],
                'layer_4' => $layerQtys['layer_4'],
                'layer_5' => $layerQtys['layer_5'],
                'layer_6' => $layerQtys['layer_6'],
                'layer_7' => $layerQtys['layer_7'],
                'oven_qty' => $ovenQty,
                'status' => $prodStatus,
                'prod_status' => $prodStatus,
            ];
        }

        // 2. Fetch Production Plans with print order lines (New Flow)
        $planQuery = ProductionPlan::with(['printOrderLines.printOrder', 'printOrderLines.trees'])
            ->has('printOrderLines');

        if ($search !== '') {
            $planQuery->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('po_number', 'like', "%{$search}%")
                    ->orWhere('customer', 'like', "%{$search}%")
                    ->orWhere('item_name', 'like', "%{$search}%")
                    ->orWhereHas('printOrderLines.printOrder', function ($q2) use ($search) {
                        $q2->where('print_order_number', 'like', "%{$search}%");
                    });
            });
        }
        if ($customer !== '') {
            $planQuery->where('customer', 'like', "%{$customer}%");
        }
        if ($po_number !== '') {
            $planQuery->where('po_number', 'like', "%{$po_number}%");
        }
        if ($aisi !== '') {
            $planQuery->where('aisi', 'like', "%{$aisi}%");
        }

        // Apply RBAC Product Scope
        if (auth()->user()->hasRole('ppic') && $scope) {
            $planQuery->where('product_scope', $scope);
        }

        $plans = $planQuery->get();

        foreach ($plans as $plan) {
            $lines = $plan->printOrderLines;
            $scheduledQty = 0;
            $cetak_good = 0;
            $cetak_defect = 0;

            foreach ($lines as $line) {
                // Exclude cancelled print orders
                if ($line->printOrder && $line->printOrder->status !== 'CANCELLED') {
                    $scheduledQty += $line->qty_ordered;
                    $cetak_good += $line->qty_actual_good ?? 0;
                    $cetak_defect += $line->qty_actual_defect ?? 0;
                }
            }

            $lineIds = $lines->pluck('id')->toArray();
            $planTrees = LostWaxTree::whereIn('lost_wax_print_order_line_id', $lineIds)->get();

            $stageMap = [
                'sebelum_scan' => 0,
                'layer_1' => 0,
                'layer_2' => 0,
                'layer_3' => 0,
                'layer_4' => 0,
                'layer_5' => 0,
                'layer_6' => 0,
                'layer_7' => 0,
                'oven' => 0,
            ];

            foreach ($planTrees as $tree) {
                $stageKey = $tree->current_stage ?: 'sebelum_scan';
                $stageMap[$stageKey] = ($stageMap[$stageKey] ?? 0) + $tree->quantity;
            }

            $layerQtys = [];
            for ($i = 1; $i <= 7; $i++) {
                $layerQtys["layer_{$i}"] = $stageMap["layer_{$i}"] ?? 0;
            }

            $ovenQty = $stageMap['oven'] ?? 0;
            $totalLap = array_sum($layerQtys);
            $totalTreeQty = $totalLap + $ovenQty + $stageMap['sebelum_scan'];

            $rangkai_good = $totalTreeQty;
            $rangkai_defect = ($rangkai_good > 0) ? max(0, $cetak_good - $rangkai_good) : 0;

            // Display values:
            $ctk_display = 0;
            $r_ctk_display = 0;
            if ($rangkai_good > 0) {
                if ($rangkai_good < $cetak_good) {
                    $ctk_display = $cetak_good;
                    $r_ctk_display = $cetak_defect;
                }
            } else {
                $ctk_display = $cetak_good;
                $r_ctk_display = $cetak_defect;
            }

            $total_scanned = $totalLap + $ovenQty;
            $rgki_display = 0;
            $r_rgki_display = 0;
            if ($total_scanned > 0) {
                if ($total_scanned < $rangkai_good) {
                    $rgki_display = $rangkai_good;
                    $r_rgki_display = $rangkai_defect;
                }
            } else {
                if ($rangkai_good > 0) {
                    $rgki_display = $rangkai_good;
                    $r_rgki_display = $rangkai_defect;
                }
            }

            if ($totalTreeQty > 0) {
                $prodStatus = ($ovenQty > 0 && $ovenQty === $totalTreeQty) ? 'COMPLETED' : 'ACTIVE';
            } else {
                $prodStatus = 'ACTIVE';
            }

            $firstLine = $lines->first();

            $rows[] = [
                'source_type' => 'print_order_line',
                'source_id' => $firstLine ? $firstLine->id : null,
                'code' => $plan->code,
                'production_plan' => $plan->code,
                'customer' => $plan->customer ?? '-',
                'product_name' => $plan->item_name ?? '-',
                'aisi' => $plan->aisi ?? '-',
                'size' => $plan->size ?? '-',
                'planned_qty' => $plan->qty_planned,
                'scheduled_qty' => $scheduledQty,
                'actual_good' => $cetak_good,
                'actual_defect' => $cetak_defect,
                'assembly_qty' => $totalTreeQty,
                'before_scan_qty' => $stageMap['sebelum_scan'],
                'ctk_display' => $ctk_display,
                'r_ctk_display' => $r_ctk_display,
                'rgki_display' => $rgki_display,
                'r_rgki_display' => $r_rgki_display,
                'overall_defect' => $cetak_defect + $rangkai_defect,
                'total_lap' => $totalLap,
                'tree_count' => $planTrees->count(),
                'layer_1' => $layerQtys['layer_1'],
                'layer_2' => $layerQtys['layer_2'],
                'layer_3' => $layerQtys['layer_3'],
                'layer_4' => $layerQtys['layer_4'],
                'layer_5' => $layerQtys['layer_5'],
                'layer_6' => $layerQtys['layer_6'],
                'layer_7' => $layerQtys['layer_7'],
                'oven_qty' => $ovenQty,
                'status' => $prodStatus,
                'prod_status' => $prodStatus,
            ];
        }

        // Apply status filters
        if ($filter === 'active') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['status'] === 'ACTIVE'));
        } elseif ($filter === 'completed') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['status'] === 'COMPLETED'));
        }

        return $rows;
    }

    private function getDistinctFieldValues(string $field): array
    {
        $scope = auth()->user()->product_scope;

        if ($field === 'customer') {
            $legacyQuery = LostWaxWorkOrder::whereNotNull('customer_name')->distinct();
            $newFlowQuery = ProductionPlan::whereNotNull('customer')->distinct();

            if (auth()->user()->hasRole('ppic') && $scope) {
                if ($scope === 'FLANGE_STAINLESS') {
                    $legacyQuery->whereIn('family_code', ['3', '4']);
                } elseif ($scope === 'FLANGE_BESI') {
                    $legacyQuery->whereIn('family_code', ['6']);
                } elseif ($scope === 'FITTING_STAINLESS') {
                    $legacyQuery->whereIn('family_code', ['1', '2']);
                } else {
                    $legacyQuery->whereRaw('1=0');
                }

                $newFlowQuery->where('product_scope', $scope);
            }

            $legacy = $legacyQuery->pluck('customer_name')->toArray();
            $newFlow = $newFlowQuery->pluck('customer')->toArray();

            return array_values(array_unique(array_filter(array_merge($legacy, $newFlow))));
        }

        if ($field === 'po_number') {
            $legacyQuery = LostWaxWorkOrder::whereNotNull('po_number')->distinct();
            $newFlowQuery = ProductionPlan::whereNotNull('po_number')->distinct();

            if (auth()->user()->hasRole('ppic') && $scope) {
                if ($scope === 'FLANGE_STAINLESS') {
                    $legacyQuery->whereIn('family_code', ['3', '4']);
                } elseif ($scope === 'FLANGE_BESI') {
                    $legacyQuery->whereIn('family_code', ['6']);
                } elseif ($scope === 'FITTING_STAINLESS') {
                    $legacyQuery->whereIn('family_code', ['1', '2']);
                } else {
                    $legacyQuery->whereRaw('1=0');
                }

                $newFlowQuery->where('product_scope', $scope);
            }

            $legacy = $legacyQuery->pluck('po_number')->toArray();
            $newFlow = $newFlowQuery->pluck('po_number')->toArray();

            return array_values(array_unique(array_filter(array_merge($legacy, $newFlow))));
        }

        if ($field === 'aisi') {
            $legacyQuery = \App\Models\LostWaxItemReference::whereNotNull('aisi_snapshot')->distinct();
            $newFlowQuery = ProductionPlan::whereNotNull('aisi')->distinct();

            if (auth()->user()->hasRole('ppic') && $scope) {
                $legacyQuery->whereHas('workOrders', function ($q) use ($scope) {
                    if ($scope === 'FLANGE_STAINLESS') {
                        $q->whereIn('family_code', ['3', '4']);
                    } elseif ($scope === 'FLANGE_BESI') {
                        $q->whereIn('family_code', ['6']);
                    } elseif ($scope === 'FITTING_STAINLESS') {
                        $q->whereIn('family_code', ['1', '2']);
                    } else {
                        $q->whereRaw('1=0');
                    }
                });

                $newFlowQuery->where('product_scope', $scope);
            }

            $legacy = $legacyQuery->pluck('aisi_snapshot')->toArray();
            $newFlow = $newFlowQuery->pluck('aisi')->toArray();

            return array_values(array_unique(array_filter(array_merge($legacy, $newFlow))));
        }

        return [];
    }
}
