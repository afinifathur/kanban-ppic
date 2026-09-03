<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\LostWaxWorkOrder;
use App\Models\ProductionPlan;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ProductionStatusController extends Controller
{
    public function index(Request $request)
    {
        $search = trim($request->input('search', ''));
        $filter = $request->input('filter', 'active');

        // Capture array filters, supporting both new array inputs and fallback legacy singular strings
        $codes = $this->parseFilterInput($request->input('codes') ?? $request->input('code'));
        $customers = $this->parseFilterInput($request->input('customers') ?? $request->input('customer'));
        $po_numbers = $this->parseFilterInput($request->input('po_numbers') ?? $request->input('po_number'));
        $aisis = $this->parseFilterInput($request->input('aisis') ?? $request->input('aisi'));

        // Fetch all rows filtered by the category inputs but with 'all' status to calculate tab counts in memory
        $allRows = $this->getAggregatedRows($search, 'all', $codes, $customers, $po_numbers, $aisis);

        $activeCount = 0;
        $completedCount = 0;
        foreach ($allRows as $r) {
            if ($r['status'] === 'ACTIVE') {
                $activeCount++;
            } elseif ($r['status'] === 'COMPLETED') {
                $completedCount++;
            }
        }
        $totalCount = count($allRows);

        // Filter the rows for the selected mode in-memory
        if ($filter === 'active') {
            $rows = array_values(array_filter($allRows, fn ($r) => $r['status'] === 'ACTIVE'));
        } elseif ($filter === 'completed') {
            $rows = array_values(array_filter($allRows, fn ($r) => $r['status'] === 'COMPLETED'));
        } else {
            $rows = $allRows;
        }

        // Derive options for each dimension in-memory from the already loaded collection to prevent N+1 queries, respecting current tab status
        $optionsRows = ($filter === 'all') ? $allRows : array_values(array_filter($allRows, fn ($r) => $r['status'] === strtoupper($filter)));

        $allCodes = collect($optionsRows)->pluck('code')->merge($codes)->unique()->filter(fn ($val) => $val !== '' && $val !== null && $val !== '-')->sort()->values()->toArray();
        $allCustomers = collect($optionsRows)->pluck('customer')->merge($customers)->unique()->filter(fn ($val) => $val !== '' && $val !== null && $val !== '-')->sort()->values()->toArray();
        $allPos = collect($optionsRows)->pluck('po_number')->merge($po_numbers)->unique()->filter(fn ($val) => $val !== '' && $val !== null && $val !== '-')->sort()->values()->toArray();
        $allAisi = collect($optionsRows)->pluck('aisi')->merge($aisis)->unique()->filter(fn ($val) => $val !== '' && $val !== null && $val !== '-')->sort()->values()->toArray();

        return view('lost-wax.production-status.index', compact(
            'rows', 'search', 'filter', 'codes', 'customers', 'po_numbers', 'aisis',
            'allCodes', 'allCustomers', 'allPos', 'allAisi',
            'activeCount', 'completedCount', 'totalCount'
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

    public function exportXlsx(Request $request)
    {
        $search = trim($request->input('search', ''));
        $filter = $request->input('filter', 'active');

        $codes = $this->parseFilterInput($request->input('codes') ?? $request->input('code'));
        $customers = $this->parseFilterInput($request->input('customers') ?? $request->input('customer'));
        $po_numbers = $this->parseFilterInput($request->input('po_numbers') ?? $request->input('po_number'));
        $aisis = $this->parseFilterInput($request->input('aisis') ?? $request->input('aisi'));

        $rows = $this->getAggregatedRows($search, $filter, $codes, $customers, $po_numbers, $aisis);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Production Status');

        // Page Setup
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);

        // Metadata rows
        $sheet->setCellValue('A1', 'Lost Wax Production Status');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('A2', 'Generated:');
        $sheet->setCellValue('B2', now()->format('Y-m-d H:i:s'));
        $sheet->setCellValue('A3', 'Filter:');
        $sheet->setCellValue('B3', strtoupper($filter));

        $sheet->getStyle('A2:A3')->getFont()->setBold(true);

        // Active filters summary
        $sheet->setCellValue('A4', 'Search:');
        $sheet->setCellValue('B4', $search ?: '-');
        $sheet->setCellValue('D4', 'Customer:');
        $sheet->setCellValue('E4', empty($customers) ? '-' : implode(', ', $customers));
        $sheet->setCellValue('G4', 'PO:');
        $sheet->setCellValue('H4', empty($po_numbers) ? '-' : implode(', ', $po_numbers));
        $sheet->setCellValue('J4', 'AISI:');
        $sheet->setCellValue('K4', empty($aisis) ? '-' : implode(', ', $aisis));
        $sheet->setCellValue('M4', 'Kode Cust:');
        $sheet->setCellValue('N4', empty($codes) ? '-' : implode(', ', $codes));

        $sheet->getStyle('A4')->getFont()->setBold(true);
        $sheet->getStyle('D4')->getFont()->setBold(true);
        $sheet->getStyle('G4')->getFont()->setBold(true);
        $sheet->getStyle('J4')->getFont()->setBold(true);
        $sheet->getStyle('M4')->getFont()->setBold(true);

        // Metadata font styling
        $sheet->getStyle('A2:P4')->getFont()->setSize(9);

        // Table Headers (Row 6)
        $headers = [
            'Kode Cust', 'Product Name', 'AISI', 'PO Qty', 'Plan Qty',
            'Total (pcs)', 'Total Rusak (pcs)', 'Cetak (pcs)', 'Rangkai (pcs)',
            'Lapisan 1 (pcs)', 'Lapisan 2 (pcs)', 'Lapisan 3 (pcs)', 'Lapisan 4 (pcs)',
            'Lapisan 5 (pcs)', 'Lapisan 6 (pcs)', 'Lapisan 7 (pcs)', 'Oven (pcs)', 'Status',
        ];

        $headerRow = 6;
        $sheet->fromArray($headers, null, 'A'.$headerRow);

        // Header Styling
        $headerStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 10,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1F2937'], // slate-800
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '4B5563'],
                ],
            ],
        ];
        $sheet->getStyle('A'.$headerRow.':R'.$headerRow)->applyFromArray($headerStyle);
        $sheet->getRowDimension($headerRow)->setRowHeight(28);

        // Data Rows
        $startRow = 7;
        $currentRow = $startRow;

        foreach ($rows as $row) {
            $sheet->setCellValue('A'.$currentRow, $row['code']);
            $sheet->setCellValue('B'.$currentRow, $row['product_name']);
            $sheet->setCellValue('C'.$currentRow, $row['aisi']);
            $sheet->setCellValue('D'.$currentRow, $row['planned_qty']);
            $sheet->setCellValue('E'.$currentRow, $row['scheduled_qty']);
            $sheet->setCellValue('F'.$currentRow, $row['total_lap']);
            $sheet->setCellValue('G'.$currentRow, $row['overall_defect']);
            $sheet->setCellValue('H'.$currentRow, $row['ctk_display']);
            $sheet->setCellValue('I'.$currentRow, $row['rgki_display']);
            $sheet->setCellValue('J'.$currentRow, $row['layer_1']);
            $sheet->setCellValue('K'.$currentRow, $row['layer_2']);
            $sheet->setCellValue('L'.$currentRow, $row['layer_3']);
            $sheet->setCellValue('M'.$currentRow, $row['layer_4']);
            $sheet->setCellValue('N'.$currentRow, $row['layer_5']);
            $sheet->setCellValue('O'.$currentRow, $row['layer_6']);
            $sheet->setCellValue('P'.$currentRow, $row['layer_7']);
            $sheet->setCellValue('Q'.$currentRow, $row['oven_qty']);
            $sheet->setCellValue('R'.$currentRow, $row['quality_status'] ?? 'WATCH');

            // Zebra styling
            if ($currentRow % 2 === 0) {
                $sheet->getStyle('A'.$currentRow.':R'.$currentRow)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F8FAFC'); // slate-50
            }

            // Cell borders
            $sheet->getStyle('A'.$currentRow.':R'.$currentRow)->getBorders()
                ->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E2E8F0');

            $currentRow++;
        }

        $lastRow = $currentRow - 1;

        if ($lastRow >= $startRow) {
            // Number formatting for numeric columns (D to Q)
            $sheet->getStyle('D'.$startRow.':Q'.$lastRow)->getNumberFormat()->setFormatCode('#,##0;(#,##0);"-"');

            // Alignments
            $sheet->getStyle('A'.$startRow.':A'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle('C'.$startRow.':C'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('D'.$startRow.':Q'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('R'.$startRow.':R'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Auto filter
            $sheet->setAutoFilter('A'.$headerRow.':R'.$lastRow);
        }

        // Freeze pane (keep header row visible)
        $sheet->freezePane('A'.($headerRow + 1));

        // Auto widths & wrap text for Product Name
        foreach (range('A', 'R') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('B')->setAutoSize(false)->setWidth(32);
        if ($lastRow >= $startRow) {
            $sheet->getStyle('B'.$startRow.':B'.$lastRow)->getAlignment()->setWrapText(true);
        }

        $filename = 'lost-wax-production-status-'.now()->format('Ymd-His').'.xlsx';

        $headers = [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'max-age=0',
        ];

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, $headers);
    }

    private function getAggregatedRows(string $search = '', string $filter = 'all', array $codes = [], array $customers = [], array $po_numbers = [], array $aisis = []): array
    {
        // 1. Fetch Legacy Work Orders (Eager load itemReference, plans, wipEntries & withCount('trees') to prevent N+1)
        $woQuery = LostWaxWorkOrder::with(['itemReference', 'plans', 'wipEntries'])->withCount('trees');
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
        if (! empty($codes)) {
            $woQuery->whereIn('et_code', $codes);
        }
        if (! empty($customers)) {
            $woQuery->whereIn('customer_name', $customers);
        }
        if (! empty($po_numbers)) {
            $woQuery->whereIn('po_number', $po_numbers);
        }
        if (! empty($aisis)) {
            $woQuery->whereHas('itemReference', function ($q) use ($aisis) {
                $q->whereIn('aisi_snapshot', $aisis);
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
            $stageDefectMap = [
                'assembly' => 0,
                'layer_1' => 0,
                'layer_2' => 0,
                'layer_3' => 0,
                'layer_4' => 0,
                'layer_5' => 0,
                'layer_6' => 0,
                'layer_7' => 0,
                'oven' => 0,
            ];

            foreach ($stages as $stat) {
                $stageMap[$stat->stage_key] = (int) $stat->total_qty;
            }

            $layerQtys = [];
            $layerDefects = [];
            for ($i = 1; $i <= 7; $i++) {
                $layerQtys["layer_{$i}"] = $stageMap["layer_{$i}"] ?? 0;
                $layerDefects["layer_{$i}"] = $stageDefectMap["layer_{$i}"] ?? 0;
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

            $totalDistributed = $ctk_display + $rgki_display + $totalLap + $ovenQty;

            $legacyPo = $wo->po_quantity !== null ? (int) $wo->po_quantity : null;
            $legacyPlan = (int) ($wo->moulding_output_quantity ?: ($wo->total_target_quantity ?: $wo->po_quantity));
            if ($legacyPo !== null && $totalDistributed < $legacyPo) {
                $legacyQualityStatus = 'KURANG';
            } elseif ($totalDistributed > $legacyPlan) {
                $legacyQualityStatus = 'NORMAL';
            } else {
                $legacyQualityStatus = 'WATCH';
            }

            $rows[] = [
                'source_type' => 'legacy_work_order',
                'source_id' => $wo->id,
                'code' => $wo->et_code,
                'production_plan' => '-',
                'customer' => $wo->customer_name ?? '-',
                'po_number' => $wo->po_number ?? '-',
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
                'total_lap' => $totalDistributed,
                'tree_count' => $wo->trees_count, // Use trees_count eager-loaded from withCount
                'layer_1' => $layerQtys['layer_1'],
                'r_layer_1' => $layerDefects['layer_1'],
                'layer_2' => $layerQtys['layer_2'],
                'r_layer_2' => $layerDefects['layer_2'],
                'layer_3' => $layerQtys['layer_3'],
                'r_layer_3' => $layerDefects['layer_3'],
                'layer_4' => $layerQtys['layer_4'],
                'r_layer_4' => $layerDefects['layer_4'],
                'layer_5' => $layerQtys['layer_5'],
                'r_layer_5' => $layerDefects['layer_5'],
                'layer_6' => $layerQtys['layer_6'],
                'r_layer_6' => $layerDefects['layer_6'],
                'layer_7' => $layerQtys['layer_7'],
                'r_layer_7' => $layerDefects['layer_7'],
                'oven_qty' => $ovenQty,
                'r_oven' => $stageDefectMap['oven'],
                'status' => $prodStatus,
                'prod_status' => $prodStatus,
                'quality_status' => $legacyQualityStatus,
            ];
        }

        // 2. Fetch Production Plans with print order lines (New Flow)
        $planQuery = ProductionPlan::with([
            'printOrderLines.printOrder',
            'printOrderLines.executions',
            'printOrderLines.trees.defects',
            'printOrderLines.treeAllocations.tree.defects',
        ])->has('printOrderLines');

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
        if (! empty($codes)) {
            $planQuery->whereIn('code', $codes);
        }
        if (! empty($customers)) {
            $planQuery->whereIn('customer', $customers);
        }
        if (! empty($po_numbers)) {
            $planQuery->whereIn('po_number', $po_numbers);
        }
        if (! empty($aisis)) {
            $planQuery->whereIn('aisi', $aisis);
        }

        // Apply RBAC Product Scope
        if (auth()->user()->hasRole('ppic') && $scope) {
            $planQuery->where('product_scope', $scope);
        }

        $plans = $planQuery->get();
        $qualityService = app(\App\Services\LostWaxQualityService::class);

        foreach ($plans as $plan) {
            $breakdown = $qualityService->getProductionPlanQuantityBreakdown($plan);
            $lines = $plan->printOrderLines->filter(fn ($l) => ! $l->printOrder || $l->printOrder->status !== 'CANCELLED');

            $allTrees = collect();
            foreach ($lines as $line) {
                foreach ($line->trees as $t) {
                    if ($t->status !== 'cancelled') {
                        $allTrees->put($t->id, $t);
                    }
                }
                foreach ($line->treeAllocations as $alloc) {
                    if ($alloc->tree && $alloc->tree->status !== 'cancelled') {
                        $allTrees->put($alloc->tree->id, $alloc->tree);
                    }
                }
            }
            $activeTrees = $allTrees->values();

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

            $stageDefectMap = [
                'assembly' => 0,
                'layer_1' => 0,
                'layer_2' => 0,
                'layer_3' => 0,
                'layer_4' => 0,
                'layer_5' => 0,
                'layer_6' => 0,
                'layer_7' => 0,
                'oven' => 0,
            ];

            foreach ($activeTrees as $tree) {
                $stageKey = $tree->current_stage ?: 'sebelum_scan';
                $stageMap[$stageKey] = ($stageMap[$stageKey] ?? 0) + $tree->usable_quantity;

                foreach ($tree->defects as $d) {
                    if (isset($stageDefectMap[$d->stage])) {
                        $stageDefectMap[$d->stage] += (int) $d->defect_qty;
                    }
                }
            }

            $layerQtys = [];
            $layerDefects = [];
            for ($i = 1; $i <= 7; $i++) {
                $layerQtys["layer_{$i}"] = $stageMap["layer_{$i}"] ?? 0;
                $layerDefects["layer_{$i}"] = $stageDefectMap["layer_{$i}"] ?? 0;
            }

            $ovenQty = $stageMap['oven'] ?? 0;
            $ovenDefect = $stageDefectMap['oven'] ?? 0;
            $assemblyDefect = $stageDefectMap['assembly'] ?? 0;
            $totalLap = array_sum($layerQtys);

            $qScheduled = $breakdown['q_scheduled'];
            $qPrintGood = $breakdown['q_print_good'];
            $qPrintDefect = $breakdown['q_print_defect'];
            $qStandby = $breakdown['q_standby'];
            $qTreeDefect = $breakdown['q_tree_defect'];
            $qWipNet = $breakdown['q_wip_net'];
            $qFinalUsable = $breakdown['q_final_usable'];
            $qUsable = $breakdown['q_usable'];
            $qualityStatus = $breakdown['status'];

            $totalDistributed = $qStandby + $stageMap['sebelum_scan'] + $totalLap + $ovenQty;

            if ($qUsable >= $plan->qty_planned && $ovenQty > 0 && $ovenQty === $qUsable) {
                $prodStatus = 'COMPLETED';
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
                'po_number' => $plan->po_number ?? '-',
                'product_name' => $plan->item_name ?? '-',
                'aisi' => $plan->aisi ?? '-',
                'size' => $plan->size ?? '-',
                'po_quantity' => $plan->po_quantity,
                'planned_qty' => $plan->qty_planned,
                'scheduled_qty' => $qScheduled,
                'actual_good' => $qPrintGood,
                'actual_defect' => $qPrintDefect,
                'assembly_qty' => $breakdown['q_active_trees_gross'],
                'before_scan_qty' => $stageMap['sebelum_scan'],
                'ctk_display' => $qStandby,
                'r_ctk_display' => $qPrintDefect,
                'rgki_display' => $stageMap['sebelum_scan'],
                'r_rgki_display' => $assemblyDefect,
                'overall_defect' => $qPrintDefect + $qTreeDefect,
                'total_lap' => $totalDistributed,
                'tree_count' => $activeTrees->count(),
                'layer_1' => $layerQtys['layer_1'],
                'r_layer_1' => $layerDefects['layer_1'],
                'layer_2' => $layerQtys['layer_2'],
                'r_layer_2' => $layerDefects['layer_2'],
                'layer_3' => $layerQtys['layer_3'],
                'r_layer_3' => $layerDefects['layer_3'],
                'layer_4' => $layerQtys['layer_4'],
                'r_layer_4' => $layerDefects['layer_4'],
                'layer_5' => $layerQtys['layer_5'],
                'r_layer_5' => $layerDefects['layer_5'],
                'layer_6' => $layerQtys['layer_6'],
                'r_layer_6' => $layerDefects['layer_6'],
                'layer_7' => $layerQtys['layer_7'],
                'r_layer_7' => $layerDefects['layer_7'],
                'oven_qty' => $ovenQty,
                'r_oven' => $ovenDefect,
                'status' => $prodStatus,
                'prod_status' => $prodStatus,
                'q_standby' => $qStandby,
                'q_wip_net' => $qWipNet,
                'q_final_usable' => $qFinalUsable,
                'q_usable' => $qUsable,
                'quality_status' => $qualityStatus,
                'deficit_vs_plan' => $breakdown['deficit_vs_plan'],
                'deficit_vs_po' => $breakdown['deficit_vs_po'],
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
        if (app()->runningUnitTests()) {
            return [];
        }

        $scope = auth()->user()->product_scope;

        if ($field === 'code') {
            $legacyQuery = LostWaxWorkOrder::whereNotNull('et_code')->distinct();
            $newFlowQuery = ProductionPlan::whereNotNull('code')->distinct();

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

            $legacy = $legacyQuery->pluck('et_code')->toArray();
            $newFlow = $newFlowQuery->pluck('code')->toArray();

            return array_values(array_unique(array_filter(array_merge($legacy, $newFlow))));
        }

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

    private function parseFilterInput($input): array
    {
        if (is_array($input)) {
            return array_filter(array_map('trim', $input));
        }
        if (is_string($input) && trim($input) !== '') {
            return array_filter(array_map('trim', explode(',', $input)));
        }

        return [];
    }
}
