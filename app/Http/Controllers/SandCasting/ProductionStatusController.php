<?php

namespace App\Http\Controllers\SandCasting;

use App\Http\Controllers\Controller;
use App\Services\SandCasting\SandCastingProductionStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductionStatusController extends Controller
{
    public function __construct(
        private readonly SandCastingProductionStatusService $statusService
    ) {}

    /**
     * Display Sand Casting production status read model.
     */
    public function index(Request $request): View|JsonResponse
    {
        $filter = $request->input('filter', $request->input('status', 'active'));

        $codes = $this->statusService->parseFilterArray($request->input('codes') ?? $request->input('production_code') ?? $request->input('code'));
        $products = $this->statusService->parseFilterArray($request->input('products') ?? $request->input('product') ?? $request->input('item_names') ?? $request->input('item_name'));
        $customers = $this->statusService->parseFilterArray($request->input('customers') ?? $request->input('customer'));
        $planningGroups = $this->statusService->parseFilterArray($request->input('planning_groups') ?? $request->input('planning_group') ?? $request->input('plan_titles') ?? $request->input('plan_title') ?? $request->input('title'));
        $poNumbers = $this->statusService->parseFilterArray($request->input('po_numbers') ?? $request->input('po_number'));
        $search = trim((string) $request->input('search', ''));

        $filters = [
            'search' => $search,
            'filter' => $filter,
            'status' => $filter,
            'codes' => $codes,
            'products' => $products,
            'customers' => $customers,
            'planning_groups' => $planningGroups,
            'po_numbers' => $poNumbers,
        ];

        $rows = $this->statusService->getAggregatedRows($filters, $request->user());
        $summary = $this->statusService->getSummaryCounts($filters, $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'summary' => $summary,
                'rows' => $rows,
            ]);
        }

        $options = $this->statusService->getFilterOptions(['status' => $filter], $request->user());
        $allPlanningGroups = collect($options['all_planning_groups'] ?? [])->merge($planningGroups)->unique()->filter(fn ($v) => $v !== '' && $v !== null)->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->toArray();
        $allCodes = collect($options['all_codes'])->merge($codes)->unique()->filter(fn ($v) => $v !== '' && $v !== null && $v !== '-')->sort()->values()->toArray();
        $allProducts = collect($options['all_products'])->merge($products)->unique()->filter(fn ($v) => $v !== '' && $v !== null && $v !== '-')->sort()->values()->toArray();
        $allCustomers = collect($options['all_customers'])->merge($customers)->unique()->filter(fn ($v) => $v !== '' && $v !== null && $v !== '-')->sort()->values()->toArray();

        return view('sand-casting.production-status.index', [
            'rows' => $rows,
            'summary' => $summary,
            'filters' => $filters,
            'filter' => $filter,
            'activeFilter' => $filter,
            'search' => $search,
            'planningGroups' => $planningGroups,
            'codes' => $codes,
            'products' => $products,
            'customers' => $customers,
            'allPlanningGroups' => $allPlanningGroups,
            'allCodes' => $allCodes,
            'allProducts' => $allProducts,
            'allCustomers' => $allCustomers,
            'activeCount' => $summary['active_count'] ?? 0,
            'completedCount' => $summary['completed_count'] ?? 0,
            'totalCount' => $summary['total_count'] ?? 0,
        ]);
    }

    /**
     * Export Sand Casting production status to Excel (.xlsx).
     */
    public function exportXlsx(Request $request): StreamedResponse
    {
        $filter = $request->input('filter', $request->input('status', 'active'));

        $codes = $this->statusService->parseFilterArray($request->input('codes') ?? $request->input('production_code') ?? $request->input('code'));
        $products = $this->statusService->parseFilterArray($request->input('products') ?? $request->input('product') ?? $request->input('item_names') ?? $request->input('item_name'));
        $customers = $this->statusService->parseFilterArray($request->input('customers') ?? $request->input('customer'));
        $planningGroups = $this->statusService->parseFilterArray($request->input('planning_groups') ?? $request->input('planning_group') ?? $request->input('plan_titles') ?? $request->input('plan_title') ?? $request->input('title'));
        $poNumbers = $this->statusService->parseFilterArray($request->input('po_numbers') ?? $request->input('po_number'));
        $search = trim((string) $request->input('search', ''));

        $filters = [
            'search' => $search,
            'filter' => $filter,
            'status' => $filter,
            'codes' => $codes,
            'products' => $products,
            'customers' => $customers,
            'planning_groups' => $planningGroups,
            'po_numbers' => $poNumbers,
        ];

        $rows = $this->statusService->getAggregatedRows($filters, $request->user());

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Production Status');

        // Page Setup
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);

        // Metadata rows
        $sheet->setCellValue('A1', 'SAND CASTING — PRODUCTION STATUS REPORT');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('A2', 'Generated:');
        $sheet->setCellValue('B2', now()->format('Y-m-d H:i:s'));
        $sheet->setCellValue('D2', 'Generated By:');
        $sheet->setCellValue('E2', $request->user()?->name ?? 'System');

        $sheet->setCellValue('A3', 'Status Filter:');
        $sheet->setCellValue('B3', strtoupper($filter));
        $sheet->setCellValue('D3', 'Total Records:');
        $sheet->setCellValue('E3', count($rows).' Production Codes');

        $sheet->getStyle('A2:A3')->getFont()->setBold(true);
        $sheet->getStyle('D2:D3')->getFont()->setBold(true);

        // Active filters summary
        $sheet->setCellValue('A4', 'Search:');
        $sheet->setCellValue('B4', $search ?: '-');
        $sheet->setCellValue('D4', 'Customer:');
        $sheet->setCellValue('E4', empty($customers) ? '-' : implode(', ', $customers));
        $sheet->setCellValue('G4', 'Kelompok:');
        $sheet->setCellValue('H4', empty($planningGroups) ? '-' : implode(', ', $planningGroups));
        $sheet->setCellValue('J4', 'Kode:');
        $sheet->setCellValue('K4', empty($codes) ? '-' : implode(', ', $codes));
        $sheet->setCellValue('M4', 'Product:');
        $sheet->setCellValue('N4', empty($products) ? '-' : implode(', ', $products));

        $sheet->getStyle('A4')->getFont()->setBold(true);
        $sheet->getStyle('D4')->getFont()->setBold(true);
        $sheet->getStyle('G4')->getFont()->setBold(true);
        $sheet->getStyle('J4')->getFont()->setBold(true);
        $sheet->getStyle('M4')->getFont()->setBold(true);

        // Metadata font styling
        $sheet->getStyle('A2:S4')->getFont()->setSize(9);

        // Table Headers (Row 6)
        $headers = [
            'No',
            'Kode',
            'Product Name',
            'Customer',
            'PO (pcs)',
            'Plan (pcs)',
            'COR',
            'Netto (pcs)',
            'R Netto (pcs)',
            'Bubut OD (pcs)',
            'R OD (pcs)',
            'Bubut CNC (pcs)',
            'R CNC (pcs)',
            'Bor (pcs)',
            'R Bor (pcs)',
            'QC (pcs)',
            'R QC (pcs)',
            'GD (pcs)',
            'Status',
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

        $sheet->getStyle('A'.$headerRow.':S'.$headerRow)->applyFromArray($headerStyle);
        $sheet->getRowDimension($headerRow)->setRowHeight(28);

        // Data Rows
        $startRow = 7;
        $currentRow = $startRow;

        if (empty($rows)) {
            $sheet->setCellValue('A'.$currentRow, 'Tidak ada data yang sesuai dengan filter.');
            $sheet->mergeCells('A'.$currentRow.':S'.$currentRow);
            $sheet->getStyle('A'.$currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('A'.$currentRow)->getFont()->setItalic(true)->setSize(10);
            $sheet->getStyle('A'.$currentRow.':S'.$currentRow)->getBorders()
                ->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E2E8F0');
            $sheet->getRowDimension($currentRow)->setRowHeight(24);
        } else {
            foreach ($rows as $row) {
                $rNettoDisplay = (int) ($row['r_cor'] ?? 0) + (int) ($row['r_netto'] ?? 0);
                $corDisplay = ($row['cor_indicator'] ?? '') === 'COR' ? 'COR' : '-';

                $sheet->setCellValue('A'.$currentRow, $row['no'] ?? ($currentRow - $startRow + 1));
                $sheet->setCellValue('B'.$currentRow, $row['code'] ?? '-');
                $sheet->setCellValue('C'.$currentRow, $row['item_name'] ?? '-');
                $sheet->setCellValue('D'.$currentRow, $row['customer'] ?? '-');
                $sheet->setCellValue('E'.$currentRow, (int) ($row['po_target'] ?? 0));
                $sheet->setCellValue('F'.$currentRow, (int) ($row['planned_qty'] ?? 0));
                $sheet->setCellValue('G'.$currentRow, $corDisplay);
                $sheet->setCellValue('H'.$currentRow, (int) ($row['netto'] ?? 0));
                $sheet->setCellValue('I'.$currentRow, $rNettoDisplay);
                $sheet->setCellValue('J'.$currentRow, (int) ($row['bubut_od'] ?? 0));
                $sheet->setCellValue('K'.$currentRow, (int) ($row['r_od'] ?? 0));
                $sheet->setCellValue('L'.$currentRow, (int) ($row['bubut_cnc'] ?? 0));
                $sheet->setCellValue('M'.$currentRow, (int) ($row['r_cnc'] ?? 0));
                $sheet->setCellValue('N'.$currentRow, (int) ($row['bor'] ?? 0));
                $sheet->setCellValue('O'.$currentRow, (int) ($row['r_bor'] ?? 0));
                $sheet->setCellValue('P'.$currentRow, (int) ($row['qc'] ?? 0));
                $sheet->setCellValue('Q'.$currentRow, (int) ($row['r_qc'] ?? 0));
                $sheet->setCellValue('R'.$currentRow, (int) ($row['gd'] ?? 0));
                $sheet->setCellValue('S'.$currentRow, $row['status'] ?? 'ACTIVE');

                // Zebra styling
                if ($currentRow % 2 === 0) {
                    $sheet->getStyle('A'.$currentRow.':S'.$currentRow)->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('F8FAFC'); // slate-50
                }

                // Cell borders
                $sheet->getStyle('A'.$currentRow.':S'.$currentRow)->getBorders()
                    ->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E2E8F0');

                $currentRow++;
            }

            $lastRow = $currentRow - 1;

            // Number formatting for numeric columns (E, F, and H to R)
            $sheet->getStyle('E'.$startRow.':F'.$lastRow)->getNumberFormat()->setFormatCode('#,##0;(#,##0);"-"');
            $sheet->getStyle('H'.$startRow.':R'.$lastRow)->getNumberFormat()->setFormatCode('#,##0;(#,##0);"-"');

            // Alignments
            $sheet->getStyle('A'.$startRow.':A'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('B'.$startRow.':B'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle('C'.$startRow.':C'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle('D'.$startRow.':D'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle('E'.$startRow.':F'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('G'.$startRow.':G'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('H'.$startRow.':R'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('S'.$startRow.':S'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Auto filter
            $sheet->setAutoFilter('A'.$headerRow.':S'.$lastRow);
        }

        // Freeze pane (keep header row visible)
        $sheet->freezePane('A'.($headerRow + 1));

        // Auto widths & wrap text for Product Name
        foreach (range('A', 'S') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('C')->setAutoSize(false)->setWidth(32);
        if (! empty($rows)) {
            $sheet->getStyle('C'.$startRow.':C'.$lastRow)->getAlignment()->setWrapText(true);
        }

        $filename = 'Sand_Casting_Production_Status_'.now()->format('Y-m-d').'.xlsx';

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

    /**
     * Get detailed physical KTR barcode items for a specific Production Code / Plan.
     */
    public function details(Request $request): JsonResponse
    {
        $planIdOrCode = $request->input('production_plan_id')
            ?? $request->input('plan_id')
            ?? $request->input('code')
            ?? $request->input('production_code');

        if (! $planIdOrCode) {
            return response()->json([
                'message' => 'Parameter production_plan_id atau code harus diisi.',
                'items' => [],
            ], 422);
        }

        $details = $this->statusService->getProductionCodeDetails($planIdOrCode, $request->user());

        if (! $details) {
            return response()->json([
                'message' => 'Data detail Kode Produksi tidak ditemukan.',
                'items' => [],
            ], 404);
        }

        return response()->json($details);
    }
}
