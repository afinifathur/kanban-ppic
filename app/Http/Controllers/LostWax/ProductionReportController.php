<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use App\Services\LostWaxProductionReportService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductionReportController extends Controller
{
    public function __construct(private readonly LostWaxProductionReportService $reportService) {}

    public function index(Request $request)
    {
        $filters = [
            'date_from' => $request->query('date_from', date('Y-m-d')),
            'date_to' => $request->query('date_to', date('Y-m-d')),
            'stage' => $request->query('stage', 'all'),
            'search' => $request->query('search', ''),
        ];

        $data = $this->reportService->getProductionDataset($filters);

        $items = $data['items'];
        $summary = $data['summary'];
        $activeFilters = $data['filters'];
        $stages = LostWaxProductionReportService::STAGES;

        return view('lost-wax.report.production.index', compact(
            'items',
            'summary',
            'activeFilters',
            'stages'
        ));
    }

    public function exportPdf(Request $request)
    {
        $filters = [
            'date_from' => $request->query('date_from', date('Y-m-d')),
            'date_to' => $request->query('date_to', date('Y-m-d')),
            'stage' => $request->query('stage', 'all'),
            'search' => $request->query('search', ''),
        ];

        $data = $this->reportService->getProductionDataset($filters);

        $items = $data['items'];
        $summary = $data['summary'];
        $activeFilters = $data['filters'];
        $stages = LostWaxProductionReportService::STAGES;

        return view('lost-wax.report.production.print', compact(
            'items',
            'summary',
            'activeFilters',
            'stages'
        ));
    }

    public function exportExcel(Request $request): StreamedResponse
    {
        $filters = [
            'date_from' => $request->query('date_from', date('Y-m-d')),
            'date_to' => $request->query('date_to', date('Y-m-d')),
            'stage' => $request->query('stage', 'all'),
            'search' => $request->query('search', ''),
        ];

        $data = $this->reportService->getProductionDataset($filters);
        $items = $data['items'];
        $summary = $data['summary'];
        $activeFilters = $data['filters'];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Report Produksi Lost Wax');

        // Header Title
        $sheet->mergeCells('A1:I1');
        $sheet->setCellValue('A1', 'REPORT PRODUKSI LOST WAX');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells('A2:I2');
        $stageName = $activeFilters['stage'] === 'all'
            ? 'SEMUA TAHAPAN'
            : strtoupper(LostWaxProductionReportService::STAGES[$activeFilters['stage']] ?? $activeFilters['stage']);
        $dateLabel = "Periode: {$activeFilters['date_from']} s/d {$activeFilters['date_to']} | Tahapan: {$stageName}";
        $sheet->setCellValue('A2', $dateLabel);
        $sheet->getStyle('A2')->getFont()->setSize(10)->setItalic(true);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Summary KPI Box
        $sheet->setCellValue('A4', 'RINGKASAN TOTAL PRODUKSI:');
        $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(10);

        $sheet->setCellValue('A5', 'TOTAL QTY DIKERJAKAN');
        $sheet->setCellValue('A6', number_format($summary['total_qty']).' pcs');

        $sheet->setCellValue('B5', 'TOTAL BERAT');
        $sheet->setCellValue('B6', number_format($summary['total_weight'], 2).' kg');

        $sheet->setCellValue('C5', 'TOTAL RUSAK');
        $sheet->setCellValue('C6', number_format($summary['total_defect']).' pcs');

        $sheet->setCellValue('D5', 'JUMLAH ITEM / BARIS');
        $sheet->setCellValue('D6', number_format($summary['total_records']).' baris');

        foreach (['A', 'B', 'C', 'D'] as $kpiCol) {
            $sheet->getStyle($kpiCol.'5')->getFont()->setBold(true)->setSize(9);
            $sheet->getStyle($kpiCol.'6')->getFont()->setBold(true)->setSize(11);
            $sheet->getStyle($kpiCol.'5:'.$kpiCol.'6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle($kpiCol.'5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9');
            $sheet->getStyle($kpiCol.'6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        }
        $sheet->getStyle('A5:D6')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Main Data Table Headers
        $rowIdx = 8;
        $headers = [
            'A' => 'No',
            'B' => 'Kode Produksi',
            'C' => 'Kode Customer',
            'D' => 'Nama Barang',
            'E' => 'Tahapan',
            'F' => 'Berat (kg/pcs)',
            'G' => 'Qty Dikerjakan (pcs)',
            'H' => 'Total Berat (kg)',
            'I' => 'Qty Rusak (pcs)',
        ];

        foreach ($headers as $colChar => $h) {
            $sheet->setCellValue($colChar.$rowIdx, $h);
            $sheet->getStyle($colChar.$rowIdx)->getFont()->setBold(true)->setSize(10);
            $sheet->getStyle($colChar.$rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E8F0');
            $sheet->getStyle($colChar.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        // Data Rows
        $startRow = $rowIdx + 1;
        $currRow = $startRow;

        foreach ($items as $idx => $item) {
            $sheet->setCellValue('A'.$currRow, $idx + 1);
            $sheet->setCellValue('B'.$currRow, $item['production_code']);
            $sheet->setCellValue('C'.$currRow, $item['customer_code']);
            $sheet->setCellValue('D'.$currRow, $item['item_name']);
            $sheet->setCellValue('E'.$currRow, $item['stage_label']);
            $sheet->setCellValue('F'.$currRow, $item['weight']);
            $sheet->setCellValue('G'.$currRow, $item['qty_processed']);
            $sheet->setCellValue('H'.$currRow, $item['total_weight']);
            $sheet->setCellValue('I'.$currRow, $item['qty_defect']);

            $sheet->getStyle('A'.$currRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('B'.$currRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('C'.$currRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('E'.$currRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('F'.$currRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('G'.$currRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('H'.$currRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('I'.$currRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            // Number formats
            $sheet->getStyle('F'.$currRow)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('G'.$currRow)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('H'.$currRow)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('I'.$currRow)->getNumberFormat()->setFormatCode('#,##0');

            $currRow++;
        }

        // Total Row at the bottom of the table
        $sheet->mergeCells("A{$currRow}:E{$currRow}");
        $sheet->setCellValue("A{$currRow}", 'TOTAL');
        $sheet->getStyle("A{$currRow}")->getFont()->setBold(true)->setSize(10);
        $sheet->getStyle("A{$currRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue("F{$currRow}", '-');
        $sheet->getStyle("F{$currRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue("G{$currRow}", $summary['total_qty']);
        $sheet->setCellValue("H{$currRow}", $summary['total_weight']);
        $sheet->setCellValue("I{$currRow}", $summary['total_defect']);

        $sheet->getStyle("A{$currRow}:I{$currRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$currRow}:I{$currRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9');
        $sheet->getStyle("G{$currRow}:I{$currRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $sheet->getStyle("G{$currRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("H{$currRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("I{$currRow}")->getNumberFormat()->setFormatCode('#,##0');

        $sheet->getStyle("A{$rowIdx}:I{$currRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Auto-fit column widths
        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'Report_Produksi_Lost_Wax_'.$activeFilters['date_from'].'_'.$activeFilters['date_to'].'.xlsx';

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'max-age=0',
        ]);
    }
}
