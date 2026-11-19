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
    protected function authorizeAccess(Request $request): void
    {
        $user = $request->user();
        if ($user && ! empty($user->assigned_stage)) {
            abort(403, 'Akses ditolak. Halaman status produksi ini khusus untuk lini Lost Wax.');
        }
    }

    public function index(Request $request)
    {
        $this->authorizeAccess($request);

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
        $this->authorizeAccess($request);

        $woId = $request->input('work_order_id');
        $lineId = $request->input('print_order_line_id');

        if ($woId) {
            $wo = LostWaxWorkOrder::with('itemReference')->find($woId);

            if (! $wo) {
                return response()->json(['trees' => []]);
            }

            $trees = LostWaxTree::with(['workOrder.itemReference', 'activeCastingResultTree.castingResult', 'activeHistoricalCutoffTree.cutoff'])
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

            $trees = LostWaxTree::with(['printOrderLine.printOrder', 'printOrderLine.productionPlan', 'activeCastingResultTree.castingResult', 'activeHistoricalCutoffTree.cutoff'])
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

                $rawStatus = trim((string) ($tree->status ?? 'generated'));
                $statusLower = strtolower($rawStatus);
                $isCancelled = ($statusLower === 'cancelled');
                $isCutoff = ($statusLower === 'historical_cutoff' || $tree->activeHistoricalCutoffTree !== null);
                $validActiveStatuses = ['generated', 'in_coating', 'ready_for_coating', 'in_dewaxing', 'active', 'in_process', 'completed'];
                $isUnknownStatus = (! $isCancelled && ! $isCutoff && ! in_array($statusLower, $validActiveStatuses, true));

                $hasActiveCor = $tree->activeCastingResultTree !== null
                    && $tree->activeCastingResultTree->castingResult?->status === 'COMPLETED';

                $rawStage = $tree->current_stage;
                $isUnknownStage = false;

                if ($isCancelled) {
                    $stageKey = 'cancelled';
                    $stageLabel = 'DIBATALKAN';
                    $isUnknownStage = false;
                    $isAnomaly = false;
                } elseif ($isCutoff) {
                    $stageKey = 'historical_cutoff';
                    $stageLabel = 'HISTORICAL CUTOFF';
                    $isUnknownStage = false;
                    $isAnomaly = false;
                } else {
                    if ($hasActiveCor) {
                        $stageKey = 'cor';
                        $stageLabel = 'Cor';
                    } elseif ($rawStage === 'siap_cor') {
                        $stageKey = 'siap_cor';
                        $stageLabel = 'Siap Cor';
                    } elseif ($rawStage === 'oven') {
                        $stageKey = 'oven';
                        $stageLabel = 'Oven';
                    } elseif (is_string($rawStage) && in_array($rawStage, ['layer_1', 'layer_2', 'layer_3', 'layer_4', 'layer_5', 'layer_6', 'layer_7'], true)) {
                        $stageKey = $rawStage;
                        $stageLabel = $tree->current_stage_label;
                    } elseif ($rawStage === null || $rawStage === '' || $rawStage === 'sebelum_scan') {
                        $stageKey = 'sebelum_scan';
                        $stageLabel = 'Sebelum Lapisan 1';
                    } else {
                        $stageKey = 'unknown';
                        $stageLabel = 'ANOMALI: '.$rawStage;
                        $isUnknownStage = true;
                    }

                    if ($isUnknownStatus) {
                        $stageLabel .= ' (STATUS: '.$rawStatus.')';
                    }

                    $isAnomaly = $isUnknownStage || $isUnknownStatus;
                }

                $cutoffDetail = $tree->activeHistoricalCutoffTree;

                return [
                    'id' => $tree->id,
                    'barcode' => $tree->barcode,
                    'tree_number' => $tree->tree_number,
                    'quantity' => $tree->quantity,
                    'current_stage' => $stageKey,
                    'current_stage_label' => $stageLabel,
                    'status' => $rawStatus,
                    'is_cancelled' => $isCancelled,
                    'is_cutoff' => $isCutoff,
                    'is_anomaly' => $isAnomaly,
                    'cutoff_number' => $cutoffDetail?->cutoff?->cutoff_number,
                    'cutoff_cor_date' => $cutoffDetail?->cor_date?->format('d/m/Y'),
                    'cutoff_cor_date_source' => $cutoffDetail?->cor_date_source,
                    'cutoff_siap_cor_date' => $cutoffDetail?->siap_cor_date?->format('d/m/Y'),
                    'cutoff_siap_cor_date_source' => $cutoffDetail?->siap_cor_date_source,
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
        $this->authorizeAccess($request);

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
        $sheet->setCellValue('M4', 'Kode Produksi:');
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
            'Kode Produksi', 'Product Name', 'AISI', 'PO Qty', 'Plan Qty',
            'Total (pcs)', 'Total Rusak (pcs)', 'Cetak (pcs)', 'Rangkai (pcs)',
            'Lapisan 1 (pcs)', 'Lapisan 2 (pcs)', 'Lapisan 3 (pcs)', 'Lapisan 4 (pcs)',
            'Lapisan 5 (pcs)', 'Lapisan 6 (pcs)', 'Lapisan 7 (pcs)', 'Oven (pcs)',
            'Siap Cor (pcs)', 'Siap Cor Historis (pcs)', 'Cor (pcs)', 'Kecukupan',
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
        $sheet->getStyle('A'.$headerRow.':U'.$headerRow)->applyFromArray($headerStyle);
        $sheet->getRowDimension($headerRow)->setRowHeight(28);

        // Data Rows
        $startRow = 7;
        $currentRow = $startRow;

        foreach ($rows as $row) {
            $sheet->setCellValue('A'.$currentRow, $row['code']);
            $sheet->setCellValue('B'.$currentRow, $row['product_name']);
            $sheet->setCellValue('C'.$currentRow, $row['aisi']);
            $sheet->setCellValue('D'.$currentRow, $row['po_quantity'] !== null && $row['po_quantity'] > 0 ? $row['po_quantity'] : '-');
            $sheet->setCellValue('E'.$currentRow, $row['planned_qty']);
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
            $sheet->setCellValue('R'.$currentRow, ($row['siap_cor_qty'] ?? 0) > 0 ? $row['siap_cor_qty'] : '-');
            $sheet->setCellValue('S'.$currentRow, ($row['siap_cor_historical_qty'] ?? 0) > 0 ? $row['siap_cor_historical_qty'] : '-');
            $sheet->setCellValue('T'.$currentRow, ($row['cor_qty'] ?? 0) > 0 ? $row['cor_qty'] : '-');
            $sheet->setCellValue('U'.$currentRow, $row['quality_status'] ?? 'WATCH');

            // Zebra styling
            if ($currentRow % 2 === 0) {
                $sheet->getStyle('A'.$currentRow.':U'.$currentRow)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F8FAFC'); // slate-50
            }

            // Cell borders
            $sheet->getStyle('A'.$currentRow.':U'.$currentRow)->getBorders()
                ->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E2E8F0');

            $currentRow++;
        }

        $lastRow = $currentRow - 1;

        if ($lastRow >= $startRow) {
            // Number formatting for numeric columns (D to T)
            $sheet->getStyle('D'.$startRow.':T'.$lastRow)->getNumberFormat()->setFormatCode('#,##0;(#,##0);"-"');

            // Alignments
            $sheet->getStyle('A'.$startRow.':A'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle('C'.$startRow.':C'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('D'.$startRow.':T'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('U'.$startRow.':U'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Auto filter
            $sheet->setAutoFilter('A'.$headerRow.':U'.$lastRow);
        }

        // Freeze pane (keep header row visible)
        $sheet->freezePane('A'.($headerRow + 1));

        // Auto widths & wrap text for Product Name
        foreach (range('A', 'U') as $col) {
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
        // 1. Fetch Legacy Work Orders (Eager load itemReference, plans, wipEntries, trees.defects, trees.scanEvents, trees.activeCastingResultTree.castingResult & withCount('trees') to prevent N+1)
        $woQuery = LostWaxWorkOrder::with([
            'itemReference',
            'plans',
            'wipEntries',
            'trees.defects',
            'trees.scanEvents' => function ($q) {
                $q->where('stage', 'siap_cor')
                    ->where('result', 'success')
                    ->whereDoesntHave('void')
                    ->orderByDesc('scanned_at')
                    ->orderByDesc('id');
            },
            'trees.activeCastingResultTree.castingResult',
            'trees.activeHistoricalCutoffTree',
        ])->withCount('trees');
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
        $scope = auth()->user()?->product_scope;
        if (auth()->user()?->hasRole('ppic') && $scope) {
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

        $rows = [];

        foreach ($legacyWos as $wo) {
            $scheduledQty = (int) $wo->planned_quantity;
            $cetak_good = (int) $wo->moulding_output_quantity;
            $cetak_defect = ($cetak_good > 0) ? max(0, $scheduledQty - $cetak_good) : 0;
            $assembly_output = (int) $wo->assembly_output_quantity;

            // Total Gross trees ever generated (including cancelled and unknown status)
            $allTreesEverGeneratedGross = (int) $wo->trees->sum('quantity');
            $remainingUnallocated = max(0, $assembly_output - $allTreesEverGeneratedGross);

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
                'siap_cor' => 0,
                'cor' => 0,
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

            $treeDefectTotal = 0;
            $rOvenPreSiapCor = 0;
            $rOvenPostSiapCor = 0;
            $siapCorHistoricalQty = 0;
            $siapCorScannedQty = 0;
            $hasScannedSnapshot = false;
            $cutoffQty = 0;
            $unknownStageQty = 0;
            $unknownStatusQty = 0;
            $hasAnomaly = false;
            $anomalyMessages = [];
            $activeTreeCount = 0;

            foreach ($wo->trees as $tree) {
                $rawStatus = trim((string) ($tree->status ?? 'generated'));
                $statusLower = strtolower($rawStatus);

                if ($statusLower === 'cancelled') {
                    // Tree cancelled: excluded from active floor buckets and its defect is excluded from totals.
                    // Its gross quantity is already counted in $allTreesEverGeneratedGross.
                    continue;
                }

                $activeDefects = $tree->defects->whereNull('voided_at');

                $isCutoff = ($statusLower === 'historical_cutoff' || $tree->activeHistoricalCutoffTree !== null);
                if ($isCutoff) {
                    $activeTreeCount++;
                    $usableQty = (int) $tree->usable_quantity;
                    $cutoffQty += $usableQty;

                    // Sum active (non-void) defects across cutoff trees for Tot Rsk
                    foreach ($activeDefects as $d) {
                        $defectQty = (int) $d->defect_qty;
                        $treeDefectTotal += $defectQty;
                        if (isset($stageDefectMap[$d->stage])) {
                            $stageDefectMap[$d->stage] += $defectQty;
                        }
                        if ($d->stage === 'oven') {
                            if (($d->defect_context ?? \App\Models\LostWaxTreeDefect::CONTEXT_PRE_SIAP_COR) === \App\Models\LostWaxTreeDefect::CONTEXT_POST_SIAP_COR) {
                                $rOvenPostSiapCor += $defectQty;
                            } else {
                                $rOvenPreSiapCor += $defectQty;
                            }
                        }
                    }

                    continue;
                }

                // Sum active (non-void) defects across non-cancelled trees (generated and unknown status) for Tot Rsk
                foreach ($activeDefects as $d) {
                    $defectQty = (int) $d->defect_qty;
                    $treeDefectTotal += $defectQty;
                    if (isset($stageDefectMap[$d->stage])) {
                        $stageDefectMap[$d->stage] += $defectQty;
                    }
                    if ($d->stage === 'oven') {
                        if (($d->defect_context ?? \App\Models\LostWaxTreeDefect::CONTEXT_PRE_SIAP_COR) === \App\Models\LostWaxTreeDefect::CONTEXT_POST_SIAP_COR) {
                            $rOvenPostSiapCor += $defectQty;
                        } else {
                            $rOvenPreSiapCor += $defectQty;
                        }
                    }
                }

                $validActiveStatuses = ['generated', 'in_coating', 'ready_for_coating', 'in_dewaxing', 'active', 'in_process', 'completed'];
                if (! in_array($statusLower, $validActiveStatuses, true)) {
                    // Unknown status anomaly!
                    $hasAnomaly = true;
                    $unknownStatusQty += (int) $tree->usable_quantity;
                    $anomalyMessages[] = "Pohon {$tree->barcode}: Status '{$rawStatus}'";
                    \Illuminate\Support\Facades\Log::error("Legacy Work Order {$wo->et_code} has tree {$tree->barcode} with unknown status '{$rawStatus}'.");

                    continue;
                }

                // Tree is active (generated)
                $activeTreeCount++;
                $usableQty = (int) $tree->usable_quantity;

                // Track historical Siap Cor qty & original scan snapshot for trees with an active siap_cor scan
                $activeSiapCorEvent = $tree->scanEvents
                    ->where('stage', 'siap_cor')
                    ->where('result', 'success')
                    ->first();
                if ($activeSiapCorEvent) {
                    $siapCorHistoricalQty += (int) $tree->siap_cor_historical_quantity;
                    if ($activeSiapCorEvent->scanned_qty !== null) {
                        $siapCorScannedQty += (int) $activeSiapCorEvent->scanned_qty;
                        $hasScannedSnapshot = true;
                    }
                }

                $hasActiveCor = $tree->activeCastingResultTree !== null
                    && $tree->activeCastingResultTree->castingResult?->status === 'COMPLETED';

                $rawStage = $tree->current_stage;

                if ($hasActiveCor) {
                    $stageMap['cor'] += (int) ($tree->activeCastingResultTree->quantity_pcs ?? $usableQty);
                } elseif ($rawStage === 'siap_cor') {
                    $stageMap['siap_cor'] += $usableQty;
                } elseif ($rawStage === 'oven') {
                    $stageMap['oven'] += $usableQty;
                } elseif (is_string($rawStage) && in_array($rawStage, ['layer_1', 'layer_2', 'layer_3', 'layer_4', 'layer_5', 'layer_6', 'layer_7'])) {
                    $stageMap[$rawStage] += $usableQty;
                } elseif ($rawStage === null || $rawStage === '' || $rawStage === 'sebelum_scan') {
                    $stageMap['sebelum_scan'] += $usableQty;
                } else {
                    // Unknown stage anomaly!
                    $hasAnomaly = true;
                    $unknownStageQty += $usableQty;
                    $anomalyMessages[] = "Pohon {$tree->barcode}: Stage '{$rawStage}'";
                    \Illuminate\Support\Facades\Log::warning("Legacy Work Order {$wo->et_code} has tree {$tree->barcode} with unknown stage '{$rawStage}'.");
                }
            }

            $layerQtys = [];
            $layerDefects = [];
            for ($i = 1; $i <= 7; $i++) {
                $layerQtys["layer_{$i}"] = $stageMap["layer_{$i}"];
                $layerDefects["layer_{$i}"] = $stageDefectMap["layer_{$i}"];
            }

            $ovenQty = $stageMap['oven'];
            $siapCorQty = $stageMap['siap_cor'];
            $corQty = $stageMap['cor'];
            $ovenDefect = $stageDefectMap['oven'];
            $assemblyDefect = $stageDefectMap['assembly'];
            $totalLap = array_sum($layerQtys);

            // CTK = Moulding Output - Assembly Output, minimum nol.
            $ctk_display = max(0, $cetak_good - $assembly_output);
            $r_ctk_display = $cetak_defect;

            // RGKI = Remaining Unallocated + Sebelum Scan.
            $before_scan_qty = $stageMap['sebelum_scan'];
            $rgki_display = $remainingUnallocated + $before_scan_qty;
            $r_rgki_display = $assemblyDefect;

            // Total = Net Good Distributed = CTK + RGKI + Layer 1-7 + Oven + Siap Cor + Cor + Cutoff Qty
            // Tot Rsk = Cetak Defect + seluruh Tree Defect
            $wipBeforeCor = $ctk_display + $rgki_display + $totalLap + $ovenQty + $siapCorQty + $unknownStageQty + $unknownStatusQty;
            $totalDistributed = $wipBeforeCor + $corQty + $cutoffQty;
            $overallDefect = $cetak_defect + $treeDefectTotal;

            $isWipEmpty = ($wipBeforeCor <= 0.000001);
            $hasCorOutput = ($corQty > 0.000001);
            $hasCutoffOutput = ($cutoffQty > 0.000001);
            $hasNoAnomalies = ($unknownStageQty <= 0 && $unknownStatusQty <= 0);

            if (strtolower((string) $wo->status) === 'closed') {
                $prodStatus = 'CLOSED (MANUAL)';
            } elseif ($hasCutoffOutput && $isWipEmpty && $hasNoAnomalies) {
                $prodStatus = 'COMPLETED (HISTORICAL CUTOFF)';
            } elseif ($hasCorOutput && ! $hasCutoffOutput && $isWipEmpty && $hasNoAnomalies) {
                $prodStatus = 'COMPLETED (COR VERIFIED)';
            } else {
                $prodStatus = 'ACTIVE';
            }

            $legacyPo = $wo->po_quantity !== null ? (int) $wo->po_quantity : null;
            $legacyPlan = (int) ($wo->moulding_output_quantity ?: ($wo->planned_quantity ?: $wo->po_quantity));
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
                'po_quantity' => $legacyPo,
                'planned_qty' => $legacyPlan,
                'scheduled_qty' => $scheduledQty,
                'actual_good' => $cetak_good,
                'actual_defect' => $cetak_defect,
                'assembly_qty' => $allTreesEverGeneratedGross,
                'before_scan_qty' => $before_scan_qty,
                'ctk_display' => $ctk_display,
                'r_ctk_display' => $r_ctk_display,
                'rgki_display' => $rgki_display,
                'r_rgki_display' => $r_rgki_display,
                'overall_defect' => $overallDefect,
                'total_lap' => $totalDistributed,
                'total_distributed' => $totalDistributed,
                'tree_count' => $activeTreeCount,
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
                'r_oven_pre_siap_cor' => $rOvenPreSiapCor,
                'r_oven_post_siap_cor' => $rOvenPostSiapCor,
                'siap_cor_qty' => $siapCorQty,
                'siap_cor_active_qty' => $siapCorQty,
                'siap_cor_historical_qty' => $siapCorHistoricalQty,
                'siap_cor_scanned_qty' => $hasScannedSnapshot ? $siapCorScannedQty : null,
                'cor_qty' => $corQty,
                'cutoff_qty' => $cutoffQty,
                'wip_before_cor' => $wipBeforeCor,
                'status' => $prodStatus,
                'prod_status' => $prodStatus,
                'quality_status' => $legacyQualityStatus,
                'has_anomaly' => $hasAnomaly,
                'anomaly_message' => implode('; ', $anomalyMessages),
                'unknown_stage_qty' => $unknownStageQty,
                'unknown_status_qty' => $unknownStatusQty,
            ];
        }

        // 2. Fetch Production Plans with print order lines (New Flow)
        $siapCorScanFilter = function ($q) {
            $q->where('stage', 'siap_cor')
                ->where('result', 'success')
                ->whereDoesntHave('void')
                ->orderByDesc('scanned_at')
                ->orderByDesc('id');
        };

        $planQuery = ProductionPlan::with([
            'printOrderLines.printOrder',
            'printOrderLines.executions',
            'printOrderLines.trees.defects',
            'printOrderLines.trees.scanEvents' => $siapCorScanFilter,
            'printOrderLines.trees.activeCastingResultTree.castingResult',
            'printOrderLines.trees.activeHistoricalCutoffTree',
            'printOrderLines.treeAllocations.tree.defects',
            'printOrderLines.treeAllocations.tree.scanEvents' => $siapCorScanFilter,
            'printOrderLines.treeAllocations.tree.activeCastingResultTree.castingResult',
            'printOrderLines.treeAllocations.tree.activeHistoricalCutoffTree',
        ])->whereHas('printOrderLines.printOrder', function ($q) {
            $q->where('status', '!=', 'CANCELLED');
        });

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

            if ($lines->isEmpty()) {
                continue;
            }

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
                'siap_cor' => 0,
                'cor' => 0,
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

            $rOvenPreSiapCor = 0;
            $rOvenPostSiapCor = 0;
            $siapCorHistoricalQty = 0;
            $siapCorScannedQty = 0;
            $hasScannedSnapshot = false;
            $cutoffQty = 0;
            $unknownStageQty = 0;
            $unknownStatusQty = 0;
            $hasAnomaly = false;
            $anomalyMessages = [];

            foreach ($activeTrees as $tree) {
                $rawStatus = trim((string) ($tree->status ?? 'generated'));
                $statusLower = strtolower($rawStatus);

                if ($statusLower === 'cancelled') {
                    continue;
                }

                $activeDefects = $tree->defects->whereNull('voided_at');

                $isCutoff = ($statusLower === 'historical_cutoff' || $tree->activeHistoricalCutoffTree !== null);

                if ($isCutoff) {
                    $cutoffQty += (int) $tree->usable_quantity;

                    foreach ($activeDefects as $d) {
                        $defectQty = (int) $d->defect_qty;
                        if (isset($stageDefectMap[$d->stage])) {
                            $stageDefectMap[$d->stage] += $defectQty;
                        }
                        if ($d->stage === 'oven') {
                            if (($d->defect_context ?? \App\Models\LostWaxTreeDefect::CONTEXT_PRE_SIAP_COR) === \App\Models\LostWaxTreeDefect::CONTEXT_POST_SIAP_COR) {
                                $rOvenPostSiapCor += $defectQty;
                            } else {
                                $rOvenPreSiapCor += $defectQty;
                            }
                        }
                    }

                    continue;
                }

                // Sum active (non-void) defects across non-cancelled trees for stageDefectMap
                foreach ($activeDefects as $d) {
                    $defectQty = (int) $d->defect_qty;
                    if (isset($stageDefectMap[$d->stage])) {
                        $stageDefectMap[$d->stage] += $defectQty;
                    }
                    if ($d->stage === 'oven') {
                        if (($d->defect_context ?? \App\Models\LostWaxTreeDefect::CONTEXT_PRE_SIAP_COR) === \App\Models\LostWaxTreeDefect::CONTEXT_POST_SIAP_COR) {
                            $rOvenPostSiapCor += $defectQty;
                        } else {
                            $rOvenPreSiapCor += $defectQty;
                        }
                    }
                }

                $validActiveStatuses = ['generated', 'in_coating', 'ready_for_coating', 'in_dewaxing', 'active', 'in_process', 'completed'];
                if (! in_array($statusLower, $validActiveStatuses, true)) {
                    // Unknown status anomaly!
                    $hasAnomaly = true;
                    $unknownStatusQty += (int) $tree->usable_quantity;
                    $anomalyMessages[] = "Pohon {$tree->barcode}: Status '{$rawStatus}'";
                    \Illuminate\Support\Facades\Log::error("Production Plan {$plan->code} has tree {$tree->barcode} with unknown status '{$rawStatus}'.");

                    continue;
                }

                $usableQty = (int) $tree->usable_quantity;

                // Track historical Siap Cor qty & original scan snapshot for trees with an active siap_cor scan
                $activeSiapCorEvent = $tree->scanEvents
                    ->where('stage', 'siap_cor')
                    ->where('result', 'success')
                    ->first();
                if ($activeSiapCorEvent) {
                    $siapCorHistoricalQty += (int) $tree->siap_cor_historical_quantity;
                    if ($activeSiapCorEvent->scanned_qty !== null) {
                        $siapCorScannedQty += (int) $activeSiapCorEvent->scanned_qty;
                        $hasScannedSnapshot = true;
                    }
                }

                $hasActiveCor = $tree->activeCastingResultTree !== null
                    && $tree->activeCastingResultTree->castingResult?->status === 'COMPLETED';

                $rawStage = $tree->current_stage;

                if ($hasActiveCor) {
                    $stageMap['cor'] += (int) ($tree->activeCastingResultTree->quantity_pcs ?? $usableQty);
                } elseif ($rawStage === 'siap_cor') {
                    $stageMap['siap_cor'] += $usableQty;
                } elseif ($rawStage === 'oven') {
                    $stageMap['oven'] += $usableQty;
                } elseif (is_string($rawStage) && in_array($rawStage, ['layer_1', 'layer_2', 'layer_3', 'layer_4', 'layer_5', 'layer_6', 'layer_7'], true)) {
                    $stageMap[$rawStage] += $usableQty;
                } elseif ($rawStage === null || $rawStage === '' || $rawStage === 'sebelum_scan') {
                    $stageMap['sebelum_scan'] += $usableQty;
                } else {
                    // Unknown stage anomaly!
                    $hasAnomaly = true;
                    $unknownStageQty += $usableQty;
                    $anomalyMessages[] = "Pohon {$tree->barcode}: Stage '{$rawStage}'";
                    \Illuminate\Support\Facades\Log::warning("Production Plan {$plan->code} has tree {$tree->barcode} with unknown stage '{$rawStage}'.");
                }
            }

            $layerQtys = [];
            $layerDefects = [];
            for ($i = 1; $i <= 7; $i++) {
                $layerQtys["layer_{$i}"] = $stageMap["layer_{$i}"] ?? 0;
                $layerDefects["layer_{$i}"] = $stageDefectMap["layer_{$i}"] ?? 0;
            }

            $ovenQty = $stageMap['oven'] ?? 0;
            $siapCorQty = $stageMap['siap_cor'] ?? 0;
            $corQty = $stageMap['cor'] ?? 0;
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

            $wipBeforeCor = $qStandby + ($stageMap['sebelum_scan'] ?? 0) + $totalLap + $ovenQty + $siapCorQty + $unknownStageQty + $unknownStatusQty;
            $totalDistributed = $wipBeforeCor + $corQty + $cutoffQty;

            $isWipEmpty = ($wipBeforeCor <= 0.000001);
            $hasCorOutput = ($corQty > 0.000001);
            $hasCutoffOutput = ($cutoffQty > 0.000001);
            $hasNoAnomalies = ($unknownStageQty <= 0 && $unknownStatusQty <= 0);

            if ((bool) ($plan->is_closed ?? false)) {
                $prodStatus = 'CLOSED (MANUAL)';
            } elseif ($hasCutoffOutput && $isWipEmpty && $hasNoAnomalies) {
                $prodStatus = 'COMPLETED (HISTORICAL CUTOFF)';
            } elseif ($hasCorOutput && ! $hasCutoffOutput && $isWipEmpty && $hasNoAnomalies) {
                $prodStatus = 'COMPLETED (COR VERIFIED)';
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
                'total_distributed' => $totalDistributed,
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
                'r_oven_pre_siap_cor' => $rOvenPreSiapCor,
                'r_oven_post_siap_cor' => $rOvenPostSiapCor,
                'siap_cor_qty' => $siapCorQty,
                'siap_cor_active_qty' => $siapCorQty,
                'siap_cor_historical_qty' => $siapCorHistoricalQty,
                'siap_cor_scanned_qty' => $hasScannedSnapshot ? $siapCorScannedQty : null,
                'cor_qty' => $corQty,
                'cutoff_qty' => $cutoffQty,
                'wip_before_cor' => $wipBeforeCor,
                'status' => $prodStatus,
                'prod_status' => $prodStatus,
                'q_standby' => $qStandby,
                'q_wip_net' => $qWipNet,
                'q_final_usable' => $qFinalUsable,
                'q_usable' => $qUsable,
                'quality_status' => $qualityStatus,
                'deficit_vs_plan' => $breakdown['deficit_vs_plan'],
                'deficit_vs_po' => $breakdown['deficit_vs_po'],
                'has_anomaly' => $hasAnomaly,
                'anomaly_message' => implode('; ', $anomalyMessages),
                'unknown_stage_qty' => $unknownStageQty,
                'unknown_status_qty' => $unknownStatusQty,
            ];
        }

        // Apply status filters
        if ($filter === 'active') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['status'] === 'ACTIVE'));
        } elseif ($filter === 'completed') {
            $rows = array_values(array_filter($rows, fn ($r) => str_starts_with($r['status'], 'COMPLETED') || $r['status'] === 'CLOSED (MANUAL)'));
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
            $newFlowQuery = ProductionPlan::whereHas('printOrderLines.printOrder', function ($q) {
                $q->where('status', '!=', 'CANCELLED');
            })->whereNotNull('code')->distinct();

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
            $newFlowQuery = ProductionPlan::whereHas('printOrderLines.printOrder', function ($q) {
                $q->where('status', '!=', 'CANCELLED');
            })->whereNotNull('customer')->distinct();

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
            $newFlowQuery = ProductionPlan::whereHas('printOrderLines.printOrder', function ($q) {
                $q->where('status', '!=', 'CANCELLED');
            })->whereNotNull('po_number')->distinct();

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
            $newFlowQuery = ProductionPlan::whereHas('printOrderLines.printOrder', function ($q) {
                $q->where('status', '!=', 'CANCELLED');
            })->whereNotNull('aisi')->distinct();

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
