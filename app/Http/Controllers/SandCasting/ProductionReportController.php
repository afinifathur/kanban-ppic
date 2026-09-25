<?php

namespace App\Http\Controllers\SandCasting;

use App\Http\Controllers\Controller;
use App\Services\SandCasting\SandCastingProductionReportService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductionReportController extends Controller
{
    public function __construct(private readonly SandCastingProductionReportService $reportService) {}

    public function index(Request $request)
    {
        $filters = [
            'date_from' => $request->query('date_from', date('Y-m-d')),
            'date_to' => $request->query('date_to', date('Y-m-d')),
            'stage' => $request->query('stage', 'all'),
            'search' => $request->query('search', ''),
        ];

        $data = $this->reportService->getProductionDataset($filters, null, true);

        $stageSummaries = $data['stage_summaries'];
        $items = $data['items'];
        $summary = $data['summary'];
        $activeFilters = $data['filters'];
        $stages = $data['stages'];

        return view('sand-casting.report.production.index', compact(
            'stageSummaries',
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

        $data = $this->reportService->getProductionDataset($filters, null, false);

        $stageSummaries = $data['stage_summaries'];
        $summary = $data['summary'];
        $activeFilters = $data['filters'];
        $stages = $data['stages'];

        return view('sand-casting.report.production.print', compact(
            'stageSummaries',
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

        $data = $this->reportService->getProductionDataset($filters, null, true);
        $stageSummaries = $data['stage_summaries'];
        $items = $data['items'];
        $summary = $data['summary'];
        $activeFilters = $data['filters'];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Laporan Produksi SC');

        // 1. Header Title
        $sheet->mergeCells('A1:L1');
        $sheet->setCellValue('A1', 'REPORT PRODUKSI SAND CASTING');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells('A2:L2');
        $stageName = $activeFilters['stage'] === 'all'
            ? 'SEMUA TAHAPAN'
            : strtoupper(SandCastingProductionReportService::STAGES[$activeFilters['stage']] ?? $activeFilters['stage']);
        $dateLabel = "Periode: {$activeFilters['date_from']} s/d {$activeFilters['date_to']} | Tahapan: {$stageName}";
        $sheet->setCellValue('A2', $dateLabel);
        $sheet->getStyle('A2')->getFont()->setSize(10)->setItalic(true);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // 2. Summary KPI Box
        $sheet->setCellValue('A4', 'RINGKASAN TOTAL AKTIVITAS:');
        $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(10);

        $sheet->setCellValue('A5', 'TOTAL AKTIVITAS KTR');
        $sheet->setCellValue('A6', number_format($summary['total_ktr_activities']).' KTR');

        $sheet->setCellValue('B5', 'TOTAL OUTPUT BAIK');
        $sheet->setCellValue('B6', number_format($summary['total_qty_output']).' pcs');

        $sheet->setCellValue('C5', 'TOTAL BERAT OUTPUT');
        $sheet->setCellValue('C6', number_format($summary['total_weight'], 2).' kg');

        $sheet->setCellValue('D5', 'TOTAL RUSAK (DEFECT)');
        $sheet->setCellValue('D6', number_format($summary['total_defect']).' pcs');

        foreach (['A', 'B', 'C', 'D'] as $kpiCol) {
            $sheet->getStyle($kpiCol.'5')->getFont()->setBold(true)->setSize(9);
            $sheet->getStyle($kpiCol.'6')->getFont()->setBold(true)->setSize(11);
            $sheet->getStyle($kpiCol.'5:'.$kpiCol.'6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle($kpiCol.'5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9');
            $sheet->getStyle($kpiCol.'6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        }
        $sheet->getStyle('A5:D6')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // 3. Stage Summary Table (7 Rows)
        $rowIdx = 8;
        $sheet->setCellValue('A'.$rowIdx, 'RINGKASAN OUTPUT PER TAHAPAN:');
        $sheet->getStyle('A'.$rowIdx)->getFont()->setBold(true)->setSize(10);

        $rowIdx = 9;
        $stageHeaders = [
            'A' => 'Tahapan',
            'B' => 'KTR Diproses',
            'C' => 'Input (pcs)',
            'D' => 'Rusak (pcs)',
            'E' => 'Good (pcs)',
            'F' => 'Berat (kg)',
            'G' => '% Rusak',
        ];

        foreach ($stageHeaders as $colChar => $h) {
            $sheet->setCellValue($colChar.$rowIdx, $h);
            $sheet->getStyle($colChar.$rowIdx)->getFont()->setBold(true)->setSize(9);
            $sheet->getStyle($colChar.$rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E8F0');
            $sheet->getStyle($colChar.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $rowIdx++;
        foreach ($stageSummaries as $stg) {
            $sheet->setCellValue('A'.$rowIdx, $stg['stage_label']);
            $sheet->setCellValue('B'.$rowIdx, $stg['ktr_count']);
            $sheet->setCellValue('C'.$rowIdx, $stg['input_pcs']);
            $sheet->setCellValue('D'.$rowIdx, $stg['defect_pcs']);
            $sheet->setCellValue('E'.$rowIdx, $stg['good_pcs']);
            $sheet->setCellValue('F'.$rowIdx, $stg['weight_kg']);
            $sheet->setCellValue('G'.$rowIdx, $stg['defect_rate'].'%');

            $sheet->getStyle('A'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle('B'.$rowIdx.':E'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('F'.$rowIdx.':G'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            $sheet->getStyle('B'.$rowIdx)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('C'.$rowIdx)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('D'.$rowIdx)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('E'.$rowIdx)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('F'.$rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');

            $rowIdx++;
        }
        $sheet->getStyle('A9:G'.($rowIdx - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // 4. Detail Table Headers
        $rowIdx += 2;
        $sheet->setCellValue('A'.$rowIdx, 'RINCIAN AKTIVITAS PRODUKSI:');
        $sheet->getStyle('A'.$rowIdx)->getFont()->setBold(true)->setSize(10);

        $rowIdx++;
        $detailHeaders = [
            'A' => 'No',
            'B' => 'KTR',
            'C' => 'Kode Produksi',
            'D' => 'Customer',
            'E' => 'Nama Barang',
            'F' => 'Heat Number',
            'G' => 'Tahapan',
            'H' => 'Checkpoint',
            'I' => 'Input (pcs)',
            'J' => 'Rusak (pcs)',
            'K' => 'Good (pcs)',
            'L' => 'Waktu Selesai',
            'M' => 'Operator',
        ];

        foreach ($detailHeaders as $colChar => $h) {
            $sheet->setCellValue($colChar.$rowIdx, $h);
            $sheet->getStyle($colChar.$rowIdx)->getFont()->setBold(true)->setSize(9);
            $sheet->getStyle($colChar.$rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDBEAFE');
            $sheet->getStyle($colChar.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $rowIdx++;
        foreach ($items as $idx => $item) {
            $sheet->setCellValue('A'.$rowIdx, $idx + 1);
            $sheet->setCellValue('B'.$rowIdx, $item['ktr']);
            $sheet->setCellValue('C'.$rowIdx, $item['production_code']);
            $sheet->setCellValue('D'.$rowIdx, $item['customer']);
            $sheet->setCellValue('E'.$rowIdx, $item['item_name']);
            $sheet->setCellValue('F'.$rowIdx, $item['heat_number']);
            $sheet->setCellValue('G'.$rowIdx, $item['stage_label']);
            $sheet->setCellValue('H'.$rowIdx, $item['checkpoint_code']);
            $sheet->setCellValue('I'.$rowIdx, $item['input_qty']);
            $sheet->setCellValue('J'.$rowIdx, $item['defect_qty']);
            $sheet->setCellValue('K'.$rowIdx, $item['good_qty']);
            $sheet->setCellValue('L'.$rowIdx, $item['physical_done_at']);
            $sheet->setCellValue('M'.$rowIdx, $item['operator']);

            $sheet->getStyle('A'.$rowIdx.':D'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('F'.$rowIdx.':H'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('I'.$rowIdx.':K'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('L'.$rowIdx.':M'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $sheet->getStyle('I'.$rowIdx.':K'.$rowIdx)->getNumberFormat()->setFormatCode('#,##0');

            $rowIdx++;
        }
        $sheet->getStyle('A11:M'.($rowIdx - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        foreach (range('A', 'M') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'Report_Produksi_Sand_Casting_'.$activeFilters['date_from'].'_'.$activeFilters['date_to'].'.xlsx';

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
