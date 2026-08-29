<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use App\Services\LostWaxDefectReportService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DefectReportController extends Controller
{
    public function __construct(private readonly LostWaxDefectReportService $reportService) {}

    public function index(Request $request)
    {
        $filters = [
            'date_from' => $request->query('date_from', date('Y-m-d')),
            'date_to' => $request->query('date_to', date('Y-m-d')),
            'stage' => $request->query('stage', 'all'),
            'search' => $request->query('search', ''),
            'production_code' => $request->query('production_code', ''),
            'mode' => $request->query('mode', 'ringkas'),
        ];

        $data = $this->reportService->getDefectDataset($filters);

        $items = $data['items'];
        $summary = $data['summary'];
        $activeFilters = $data['filters'];
        $stages = LostWaxDefectReportService::STAGES;

        return view('lost-wax.quality.defects.index', compact(
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
            'production_code' => $request->query('production_code', ''),
            'mode' => $request->query('mode', 'ringkas'),
        ];

        $data = $this->reportService->getDefectDataset($filters);

        $items = $data['items'];
        $summary = $data['summary'];
        $activeFilters = $data['filters'];
        $stages = LostWaxDefectReportService::STAGES;

        return view('lost-wax.quality.defects.print', compact(
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
            'production_code' => $request->query('production_code', ''),
            'mode' => $request->query('mode', 'ringkas'),
        ];

        $data = $this->reportService->getDefectDataset($filters);
        $items = $data['items'];
        $summary = $data['summary'];
        $activeFilters = $data['filters'];
        $mode = $activeFilters['mode'];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rekap Kerusakan Lost Wax');

        // Header Title
        $sheet->mergeCells('A1:I1');
        $sheet->setCellValue('A1', 'REKAP KERUSAKAN LOST WAX (DAILY DEFECT REPORT)');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells('A2:I2');
        $dateLabel = "Periode: {$activeFilters['date_from']} s/d {$activeFilters['date_to']} | Tahapan: ".strtoupper($activeFilters['stage']).' | Mode: '.strtoupper($mode);
        $sheet->setCellValue('A2', $dateLabel);
        $sheet->getStyle('A2')->getFont()->setSize(10)->setItalic(true);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Summary KPI Table
        $sheet->setCellValue('A4', 'RINGKASAN DEFECT PER TAHAPAN:');
        $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(10);

        $kpis = [
            ['Cetak', $summary['cetak']],
            ['Rangkai', $summary['assembly']],
            ['Lapisan 1', $summary['layer_1']],
            ['Lapisan 2', $summary['layer_2']],
            ['Lapisan 3', $summary['layer_3']],
            ['Lapisan 4', $summary['layer_4']],
            ['Lapisan 5', $summary['layer_5']],
            ['Lapisan 6', $summary['layer_6']],
            ['Lapisan 7', $summary['layer_7']],
            ['Oven', $summary['oven']],
            ['GRAND TOTAL', $summary['grand_total']],
        ];

        $colLetter = 'A';
        foreach ($kpis as [$label, $val]) {
            $sheet->setCellValue($colLetter.'5', $label);
            $sheet->setCellValue($colLetter.'6', $val);
            $sheet->getStyle($colLetter.'5')->getFont()->setBold(true)->setSize(9);
            $sheet->getStyle($colLetter.'6')->getFont()->setBold(true)->setSize(11);
            $sheet->getStyle($colLetter.'5:'.$colLetter.'6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle($colLetter.'5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9');
            $sheet->getStyle($colLetter.'6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($label === 'GRAND TOTAL' ? 'FFFEE2E2' : 'FFFFFFFF');
            $colLetter++;
        }
        $lastCol = chr(ord($colLetter) - 1);
        $sheet->getStyle("A5:{$lastCol}6")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Main Data Table Headers
        $rowIdx = 8;
        if ($mode === 'detail') {
            $headers = ['No', 'Kode Produksi', 'Barcode Tree', 'Nama Item', 'Tahapan (Stage)', 'Jumlah Rusak (pcs)', 'Alasan Kerusakan', 'Operator', 'Waktu Kejadian'];
        } else {
            $headers = ['No', 'Kode Produksi', 'Nama Item', 'Tahapan (Stage)', 'Jumlah Rusak (pcs)', 'Jumlah Kejadian'];
        }

        $colChar = 'A';
        foreach ($headers as $h) {
            $sheet->setCellValue($colChar.$rowIdx, $h);
            $sheet->getStyle($colChar.$rowIdx)->getFont()->setBold(true)->setSize(10);
            $sheet->getStyle($colChar.$rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E8F0');
            $sheet->getStyle($colChar.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $colChar++;
        }
        $maxColHeader = chr(ord($colChar) - 1);

        // Data Rows
        $startRow = $rowIdx + 1;
        $currRow = $startRow;

        foreach ($items as $idx => $item) {
            if ($mode === 'detail') {
                $sheet->setCellValue('A'.$currRow, $idx + 1);
                $sheet->setCellValue('B'.$currRow, $item['production_code']);
                $sheet->setCellValue('C'.$currRow, $item['barcode']);
                $sheet->setCellValue('D'.$currRow, $item['item_name']);
                $sheet->setCellValue('E'.$currRow, $item['stage_label']);
                $sheet->setCellValue('F'.$currRow, $item['defect_qty']);
                $sheet->setCellValue('G'.$currRow, $item['defect_reason']);
                $sheet->setCellValue('H'.$currRow, $item['operator']);
                $sheet->setCellValue('I'.$currRow, $item['occurred_at'] ? Carbon::parse($item['occurred_at'])->format('d-m-Y H:i') : '-');

                $sheet->getStyle('A'.$currRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('B'.$currRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('C'.$currRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('E'.$currRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('F'.$currRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle('I'.$currRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            } else {
                $sheet->setCellValue('A'.$currRow, $idx + 1);
                $sheet->setCellValue('B'.$currRow, $item['production_code']);
                $sheet->setCellValue('C'.$currRow, $item['item_name']);
                $sheet->setCellValue('D'.$currRow, $item['stage_label']);
                $sheet->setCellValue('E'.$currRow, $item['defect_qty']);
                $sheet->setCellValue('F'.$currRow, $item['record_count']);

                $sheet->getStyle('A'.$currRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('B'.$currRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('D'.$currRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('E'.$currRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle('F'.$currRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
            $currRow++;
        }

        if ($currRow > $startRow) {
            $sheet->getStyle("A{$rowIdx}:{$maxColHeader}".($currRow - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }

        // Auto-fit column widths
        foreach (range('A', $maxColHeader) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'Rekap_Kerusakan_Lost_Wax_'.$activeFilters['date_from'].'_'.$activeFilters['date_to'].'.xlsx';

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
