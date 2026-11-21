<?php

namespace App\Http\Controllers\Fitting;

use App\Http\Controllers\Controller;
use App\Models\FittingItem;
use App\Services\Fitting\FittingProductionReportService;
use App\Services\Fitting\FittingStageAuthorizationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FittingProductionReportController extends Controller
{
    public function __construct(
        protected readonly FittingProductionReportService $reportService,
        protected readonly FittingStageAuthorizationService $authService
    ) {}

    /**
     * Validate and normalize filter inputs for Fitting Production Report.
     *
     * @return array{date_from: string, date_to: string, stage: string, search: string, line: int|null}
     *
     * @throws ValidationException
     */
    protected function validateReportFilters(Request $request): array
    {
        $validStages = array_merge(['all'], array_keys(FittingProductionReportService::STAGES));

        $validated = $request->validate([
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d',
            'stage' => 'nullable|string',
            'search' => 'nullable|string|max:100',
            'line' => 'nullable',
        ]);

        $defaultDate = date('Y-m-d');
        $dateFrom = $validated['date_from'] ?? $defaultDate;
        $dateTo = $validated['date_to'] ?? $defaultDate;
        $stage = ! empty($validated['stage']) ? strtolower(trim((string) $validated['stage'])) : 'all';
        $search = $validated['search'] ?? '';
        $line = FittingItem::normalizeLineNumber($validated['line'] ?? null);

        // 1. Stage validation
        if (! in_array($stage, $validStages, true)) {
            throw ValidationException::withMessages([
                'stage' => ["Tahapan (stage) '{$stage}' tidak valid dalam alur Fitting Production."],
            ]);
        }

        // 2. Chronological order validation: date_from <= date_to
        $fromCarbon = Carbon::parse($dateFrom)->startOfDay();
        $toCarbon = Carbon::parse($dateTo)->startOfDay();

        if ($fromCarbon->gt($toCarbon)) {
            throw ValidationException::withMessages([
                'date_from' => ['Tanggal Dari tidak boleh lebih besar dari Tanggal Sampai.'],
            ]);
        }

        // 3. Enforce Maximum 45 calendar days inclusive
        $inclusiveDays = $fromCarbon->diffInDays($toCarbon) + 1;
        if ($inclusiveDays > 45) {
            throw ValidationException::withMessages([
                'date_to' => ['Rentang tanggal laporan maksimal 45 hari.'],
            ]);
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'stage' => $stage,
            'search' => $search,
            'line' => $line,
        ];
    }

    /**
     * Render the interactive Fitting Production Report page.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        if (! FittingStageAuthorizationService::canViewReports($user)) {
            abort(403, 'Anda tidak memiliki hak akses untuk melihat Laporan Produksi Fitting.');
        }

        $filters = $this->validateReportFilters($request);
        $data = $this->reportService->getProductionDataset($filters, $user, includeDetails: true);

        return view('fitting.report.production.index', array_merge($data, [
            'activeFilters' => $data['filters'],
        ]));
    }

    /**
     * Return JSON dataset for asynchronous / AJAX table refresh.
     */
    public function data(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! FittingStageAuthorizationService::canViewReports($user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $filters = $this->validateReportFilters($request);
        $data = $this->reportService->getProductionDataset($filters, $user, includeDetails: true);

        return response()->json($data);
    }

    /**
     * Export Fitting Production Report to printable view (PDF ready).
     */
    public function exportPdf(Request $request): View
    {
        $user = $request->user();
        if (! FittingStageAuthorizationService::canViewReports($user)) {
            abort(403, 'Anda tidak memiliki hak akses untuk mencetak Laporan Produksi Fitting.');
        }

        $filters = $this->validateReportFilters($request);
        $data = $this->reportService->getProductionDataset($filters, $user, includeDetails: true);

        return view('fitting.report.production.print', array_merge($data, [
            'activeFilters' => $data['filters'],
        ]));
    }

    /**
     * Export Fitting Production Report to Excel (.xlsx).
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        $user = $request->user();
        if (! FittingStageAuthorizationService::canViewReports($user)) {
            abort(403, 'Anda tidak memiliki hak akses untuk mengekspor Laporan Produksi Fitting.');
        }

        $filters = $this->validateReportFilters($request);
        $data = $this->reportService->getProductionDataset($filters, $user, includeDetails: true);

        $stageSummaries = $data['stage_summaries'];
        $items = $data['items'];
        $summary = $data['summary'];
        $activeFilters = $data['filters'];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Laporan Produksi Fitting');

        // 1. Header Title
        $sheet->mergeCells('A1:N1');
        $sheet->setCellValue('A1', 'REPORT PRODUKSI FITTING PRODUCTION');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells('A2:N2');
        $stageName = $activeFilters['stage'] === 'all'
            ? 'SEMUA TAHAPAN (6 TAHAP)'
            : strtoupper(FittingProductionReportService::STAGES[$activeFilters['stage']] ?? $activeFilters['stage']);
        $lineLabel = $activeFilters['line'] ? ' | Line: '.FittingItem::lineLabelFor($activeFilters['line']) : '';
        $dateLabel = "Periode: {$activeFilters['date_from']} s/d {$activeFilters['date_to']} | Tahapan: {$stageName}{$lineLabel}";
        $sheet->setCellValue('A2', $dateLabel);
        $sheet->getStyle('A2')->getFont()->setSize(10)->setItalic(true);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // 2. Summary KPI Box
        $sheet->setCellValue('A4', 'RINGKASAN TOTAL AKTIVITAS:');
        $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(10);

        $sheet->setCellValue('A5', 'TOTAL AKTIVITAS PFKTR');
        $sheet->setCellValue('A6', number_format($summary['total_pfktr_activities']).' PFKTR');

        $sheet->setCellValue('B5', 'HASIL KERJA (INPUT)');
        $sheet->setCellValue('B6', number_format($summary['total_input_pcs']).' pcs');

        $sheet->setCellValue('C5', 'TOTAL REJECT (RUSAK)');
        $sheet->setCellValue('C6', number_format($summary['total_defect_pcs']).' pcs');

        $sheet->setCellValue('D5', 'TOTAL GOOD FORWARD');
        $sheet->setCellValue('D6', number_format($summary['total_good_pcs']).' pcs');

        $sheet->setCellValue('E5', 'TOTAL BERAT GOOD');
        $sheet->setCellValue('E6', number_format($summary['total_weight_kg'], 2).' kg');

        foreach (['A', 'B', 'C', 'D', 'E'] as $kpiCol) {
            $sheet->getStyle($kpiCol.'5')->getFont()->setBold(true)->setSize(9);
            $sheet->getStyle($kpiCol.'6')->getFont()->setBold(true)->setSize(11);
            $sheet->getStyle($kpiCol.'5:'.$kpiCol.'6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle($kpiCol.'5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9');
            $sheet->getStyle($kpiCol.'6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        }
        $sheet->getStyle('A5:E6')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // 3. Stage Summary Table (6 Rows)
        $rowIdx = 8;
        $sheet->setCellValue('A'.$rowIdx, 'RINGKASAN OUTPUT PER TAHAPAN:');
        $sheet->getStyle('A'.$rowIdx)->getFont()->setBold(true)->setSize(10);

        $rowIdx = 9;
        $stageHeaders = [
            'A' => 'No',
            'B' => 'Tahapan',
            'C' => 'PFKTR Diproses',
            'D' => 'Hasil Kerja (Input)',
            'E' => 'Reject (pcs)',
            'F' => 'Good Forward (pcs)',
            'G' => 'Berat Good (kg)',
            'H' => '% Reject',
            'I' => 'Line 1 (pcs)',
            'J' => 'Line 2 (pcs)',
            'K' => 'Line 3 (pcs)',
            'L' => 'Line 4 (pcs)',
            'M' => 'Unassigned (pcs)',
        ];

        foreach ($stageHeaders as $colChar => $h) {
            $sheet->setCellValue($colChar.$rowIdx, $h);
            $sheet->getStyle($colChar.$rowIdx)->getFont()->setBold(true)->setSize(9);
            $sheet->getStyle($colChar.$rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E8F0');
            $sheet->getStyle($colChar.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $rowIdx++;
        $stgNo = 1;
        foreach ($stageSummaries as $stg) {
            $sheet->setCellValue('A'.$rowIdx, $stgNo++);
            $sheet->setCellValue('B'.$rowIdx, $stg['stage_label']);
            $sheet->setCellValue('C'.$rowIdx, $stg['ktr_count']);
            $sheet->setCellValue('D'.$rowIdx, $stg['input_pcs']);
            $sheet->setCellValue('E'.$rowIdx, $stg['defect_pcs']);
            $sheet->setCellValue('F'.$rowIdx, $stg['good_pcs']);
            $sheet->setCellValue('G'.$rowIdx, $stg['weight_kg']);
            $sheet->setCellValue('H'.$rowIdx, $stg['defect_rate'].'%');
            $sheet->setCellValue('I'.$rowIdx, $stg['line_breakdown'][1]['pcs'] ?? 0);
            $sheet->setCellValue('J'.$rowIdx, $stg['line_breakdown'][2]['pcs'] ?? 0);
            $sheet->setCellValue('K'.$rowIdx, $stg['line_breakdown'][3]['pcs'] ?? 0);
            $sheet->setCellValue('L'.$rowIdx, $stg['line_breakdown'][4]['pcs'] ?? 0);
            $sheet->setCellValue('M'.$rowIdx, $stg['line_breakdown']['unassigned']['pcs'] ?? 0);

            $sheet->getStyle('A'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('B'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle('C'.$rowIdx.':M'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            $sheet->getStyle('C'.$rowIdx.':F'.$rowIdx)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('G'.$rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('I'.$rowIdx.':M'.$rowIdx)->getNumberFormat()->setFormatCode('#,##0');

            $rowIdx++;
        }
        $sheet->getStyle('A9:M'.($rowIdx - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // 4. Detail Table Headers
        $rowIdx += 2;
        $sheet->setCellValue('A'.$rowIdx, 'RINCIAN AKTIVITAS PRODUKSI PER PFKTR:');
        $sheet->getStyle('A'.$rowIdx)->getFont()->setBold(true)->setSize(10);

        $rowIdx++;
        $detailHeaders = [
            'A' => 'No',
            'B' => 'Tanggal Selesai',
            'C' => 'PFKTR',
            'D' => 'Line',
            'E' => 'Heat Number',
            'F' => 'Kode SPK',
            'G' => 'Customer',
            'H' => 'Nama Barang',
            'I' => 'Tahapan',
            'J' => 'Hasil Kerja (In)',
            'K' => 'Reject (pcs)',
            'L' => 'Good Forward',
            'M' => 'Berat (kg)',
            'N' => 'Operator',
        ];

        foreach ($detailHeaders as $colChar => $h) {
            $sheet->setCellValue($colChar.$rowIdx, $h);
            $sheet->getStyle($colChar.$rowIdx)->getFont()->setBold(true)->setSize(9);
            $sheet->getStyle($colChar.$rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDBEAFE');
            $sheet->getStyle($colChar.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $rowIdx++;
        $dNo = 1;
        foreach ($items as $item) {
            $sheet->setCellValue('A'.$rowIdx, $dNo++);
            $sheet->setCellValue('B'.$rowIdx, $item['physical_done_at']);
            $sheet->setCellValue('C'.$rowIdx, $item['traveler_number']);
            $sheet->setCellValue('D'.$rowIdx, $item['line_label']);
            $sheet->setCellValue('E'.$rowIdx, $item['heat_number']);
            $sheet->setCellValue('F'.$rowIdx, $item['is_multi_allocation'] ? 'MULTI-SPK ('.count($item['allocations']).')' : $item['production_code']);
            $sheet->setCellValue('G'.$rowIdx, $item['customer']);
            $sheet->setCellValue('H'.$rowIdx, $item['item_name']);
            $sheet->setCellValue('I'.$rowIdx, $item['stage_label']);
            $sheet->setCellValue('J'.$rowIdx, $item['input_qty']);
            $sheet->setCellValue('K'.$rowIdx, $item['defect_qty']);
            $sheet->setCellValue('L'.$rowIdx, $item['good_qty']);
            $sheet->setCellValue('M'.$rowIdx, $item['total_weight']);
            $sheet->setCellValue('N'.$rowIdx, $item['operator']);

            $sheet->getStyle('A'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('B'.$rowIdx.':D'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('E'.$rowIdx.':I'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle('J'.$rowIdx.':M'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('N'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            $sheet->getStyle('J'.$rowIdx.':L'.$rowIdx)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('M'.$rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');

            $rowIdx++;
        }
        $sheet->getStyle('A'.($rowIdx - count($items) - 1).':N'.($rowIdx - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Auto-size columns
        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'Laporan_Produksi_Fitting_'.str_replace('-', '', $activeFilters['date_from']).'_'.str_replace('-', '', $activeFilters['date_to']).'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'max-age=0',
        ]);
    }
}
