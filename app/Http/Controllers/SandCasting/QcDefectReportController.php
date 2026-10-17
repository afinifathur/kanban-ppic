<?php

namespace App\Http\Controllers\SandCasting;

use App\Http\Controllers\Controller;
use App\Services\SandCasting\SandCastingQcDefectReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QcDefectReportController extends Controller
{
    public function __construct(
        protected readonly SandCastingQcDefectReportService $reportService
    ) {}

    /**
     * Validate and normalize filter inputs for QC Defect Detail Report.
     *
     * @return array{date_from: string, date_to: string, stage: string, defect_type_id: int|string|null, search: string}
     *
     * @throws ValidationException
     */
    protected function validateReportFilters(Request $request): array
    {
        $validStages = array_merge(['all'], array_keys(SandCastingQcDefectReportService::STAGES));

        try {
            $validated = $request->validate([
                'date_from' => 'nullable|date_format:Y-m-d',
                'date_to' => 'nullable|date_format:Y-m-d',
                'stage' => 'nullable|string',
                'defect_type_id' => 'nullable',
                'search' => 'nullable|string|max:100',
            ]);
        } catch (ValidationException $e) {
            $e->redirectTo = route('sand-casting.report.qc-defects.index');
            throw $e;
        }

        $defaultDateFrom = Carbon::now()->subDays(29)->toDateString();
        $defaultDateTo = Carbon::now()->toDateString();

        $dateFrom = $validated['date_from'] ?? $defaultDateFrom;
        $dateTo = $validated['date_to'] ?? $defaultDateTo;
        $stage = $validated['stage'] ?? 'all';
        $defectTypeId = ! empty($validated['defect_type_id']) && $validated['defect_type_id'] !== 'all'
            ? (int) $validated['defect_type_id']
            : null;
        $search = $validated['search'] ?? '';

        // 1. Stage validation
        if (! in_array($stage, $validStages, true)) {
            $exception = ValidationException::withMessages([
                'stage' => ["Tahapan '{$stage}' tidak valid dalam filter laporan QC."],
            ]);
            $exception->redirectTo = route('sand-casting.report.qc-defects.index');
            throw $exception;
        }

        // 2. Chronological order validation: date_from <= date_to
        $fromCarbon = Carbon::parse($dateFrom)->startOfDay();
        $toCarbon = Carbon::parse($dateTo)->startOfDay();

        if ($fromCarbon->gt($toCarbon)) {
            $exception = ValidationException::withMessages([
                'date_from' => ['Tanggal Dari tidak boleh lebih besar dari Tanggal Sampai.'],
            ]);
            $exception->redirectTo = route('sand-casting.report.qc-defects.index');
            throw $exception;
        }

        // 3. Enforce maximum 45 calendar days inclusive
        $inclusiveDays = $fromCarbon->diffInDays($toCarbon) + 1;
        if ($inclusiveDays > 45) {
            $exception = ValidationException::withMessages([
                'date_to' => ['Rentang tanggal laporan maksimal 45 hari kalender inklusif. Untuk periode lebih panjang, silakan bagi menjadi beberapa laporan terpisah.'],
            ]);
            $exception->redirectTo = route('sand-casting.report.qc-defects.index');
            throw $exception;
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'stage' => $stage,
            'defect_type_id' => $defectTypeId,
            'search' => $search,
        ];
    }

    /**
     * Display the QC Defect Detail Report page.
     */
    public function index(Request $request)
    {
        $filters = $this->validateReportFilters($request);

        $data = $this->reportService->getReportDataset($filters, $request->user(), true, 25);
        $data['records']->appends($request->query());

        return view('sand-casting.report.qc-defects.index', [
            'records' => $data['records'],
            'paretoSummary' => $data['pareto_summary'],
            'summary' => $data['summary'],
            'filters' => $data['filters'],
            'stages' => $data['stages'],
            'stageLabels' => $data['stage_labels'],
            'availableDefectTypes' => $data['available_defect_types'],
        ]);
    }

    /**
     * Render the print / PDF preview page for QC Defect Detail Report.
     */
    public function exportPdf(Request $request)
    {
        $filters = $this->validateReportFilters($request);

        $data = $this->reportService->getReportDataset($filters, $request->user(), false);

        return view('sand-casting.report.qc-defects.print', [
            'records' => $data['all_records'],
            'paretoSummary' => $data['pareto_summary'],
            'summary' => $data['summary'],
            'filters' => $data['filters'],
            'stages' => $data['stages'],
            'stageLabels' => $data['stage_labels'],
        ]);
    }

    /**
     * Stream an Excel XLSX file containing the full report dataset.
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        $filters = $this->validateReportFilters($request);

        $data = $this->reportService->getReportDataset($filters, $request->user(), false);

        $summary = $data['summary'];
        $pareto = $data['pareto_summary'];
        $records = $data['all_records'];
        $activeFilters = $data['filters'];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Laporan Defect QC');

        // 1. Header Title
        $sheet->mergeCells('A1:N1');
        $sheet->setCellValue('A1', 'LAPORAN RINCIAN DEFECT QC — SAND CASTING');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells('A2:N2');
        $stageName = $activeFilters['stage'] === 'all'
            ? 'SEMUA TAHAPAN'
            : strtoupper(SandCastingQcDefectReportService::STAGE_LABELS[$activeFilters['stage']] ?? $activeFilters['stage']);
        $dateLabel = "Periode: {$activeFilters['date_from']} s/d {$activeFilters['date_to']} | Tahapan: {$stageName} | Sumber: Verifikasi QC (CONFIRMED)";
        $sheet->setCellValue('A2', $dateLabel);
        $sheet->getStyle('A2')->getFont()->setSize(10)->setItalic(true);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // 2. Summary KPI Box
        $sheet->setCellValue('A4', 'RINGKASAN METRIK KUALITAS QC:');
        $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(10);

        $sheet->setCellValue('A5', 'TOTAL DEFECT (PCS)');
        $sheet->setCellValue('A6', number_format($summary['total_defect_pcs']).' PCS');

        $sheet->setCellValue('B5', 'ESTIMASI BERAT DEFECT (KG)');
        $sheet->setCellValue('B6', number_format($summary['total_estimated_kg'], 2).' KG');

        $sheet->setCellValue('C5', 'KTR TERDAMPAK');
        $sheet->setCellValue('C6', number_format($summary['affected_ktr_count']).' KTR');

        $sheet->setCellValue('D5', 'JENIS CACAT TERBANYAK');
        $sheet->setCellValue('D6', (string) $summary['top_defect_type']);

        $sheet->setCellValue('E5', 'KTR BEBAS CACAT (0 DEFECT)');
        $sheet->setCellValue('E6', number_format($summary['zero_defect_ktr_count']).' KTR');

        foreach (['A', 'B', 'C', 'D', 'E'] as $kpiCol) {
            $sheet->getStyle($kpiCol.'5')->getFont()->setBold(true)->setSize(9);
            $sheet->getStyle($kpiCol.'6')->getFont()->setBold(true)->setSize(11);
            $sheet->getStyle($kpiCol.'5:'.$kpiCol.'6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle($kpiCol.'5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9');
            $sheet->getStyle($kpiCol.'6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        }
        $sheet->getStyle('A5:E6')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // 3. Pareto Summary Table (Grouped by Defect Type & Stage)
        $rowIdx = 8;
        $sheet->setCellValue('A'.$rowIdx, 'RINGKASAN PARETO DEFECT PER JENIS CACAT & TAHAPAN:');
        $sheet->getStyle('A'.$rowIdx)->getFont()->setBold(true)->setSize(10);

        $rowIdx = 9;
        $paretoHeaders = [
            'A' => 'No',
            'B' => 'Jenis Cacat',
            'C' => 'Tahap Produksi',
            'D' => 'Kuantitas (PCS)',
            'E' => 'Kontribusi (%)',
            'F' => 'Estimasi Berat (KG)',
            'G' => 'Frekuensi KTR',
        ];

        foreach ($paretoHeaders as $colChar => $h) {
            $sheet->setCellValue($colChar.$rowIdx, $h);
            $sheet->getStyle($colChar.$rowIdx)->getFont()->setBold(true)->setSize(9);
            $sheet->getStyle($colChar.$rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E8F0');
            $sheet->getStyle($colChar.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $rowIdx++;
        $startParetoRow = $rowIdx;
        foreach ($pareto as $pIdx => $p) {
            $sheet->setCellValue('A'.$rowIdx, $pIdx + 1);
            $sheet->setCellValue('B'.$rowIdx, $p['defect_name']);
            $sheet->setCellValue('C'.$rowIdx, $p['stage_label']);
            $sheet->setCellValue('D'.$rowIdx, $p['qty_pcs']);
            $sheet->setCellValue('E'.$rowIdx, $p['contribution_pct'].'%');
            $sheet->setCellValue('F'.$rowIdx, $p['estimated_kg']);
            $sheet->setCellValue('G'.$rowIdx, $p['ktr_count']);

            $sheet->getStyle('A'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('B'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle('C'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('D'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('E'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('F'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('G'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            $sheet->getStyle('D'.$rowIdx)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('F'.$rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('G'.$rowIdx)->getNumberFormat()->setFormatCode('#,##0');

            $rowIdx++;
        }
        if ($rowIdx > $startParetoRow) {
            $sheet->getStyle('A9:G'.($rowIdx - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }

        // 4. Detail Traceability Table
        $rowIdx += 2;
        $sheet->setCellValue('A'.$rowIdx, 'RINCIAN DEFECT QC (TRACEABILITY DETAIL):');
        $sheet->getStyle('A'.$rowIdx)->getFont()->setBold(true)->setSize(10);

        $rowIdx++;
        $detailHeaders = [
            'A' => 'No',
            'B' => 'Tanggal Inspeksi',
            'C' => 'KTR (Traveler)',
            'D' => 'Heat Number',
            'E' => 'Kode Produksi',
            'F' => 'PO',
            'G' => 'Nama Barang',
            'H' => 'Customer',
            'I' => 'Tahapan',
            'J' => 'Checkpoint',
            'K' => 'Jenis Cacat',
            'L' => 'Qty (PCS)',
            'M' => 'Estimasi Berat (KG)',
            'N' => 'Verifikator QC',
        ];

        foreach ($detailHeaders as $colChar => $h) {
            $sheet->setCellValue($colChar.$rowIdx, $h);
            $sheet->getStyle($colChar.$rowIdx)->getFont()->setBold(true)->setSize(9);
            $sheet->getStyle($colChar.$rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDBEAFE');
            $sheet->getStyle($colChar.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $rowIdx++;
        $startDetailRow = $rowIdx;
        foreach ($records as $rIdx => $r) {
            $sheet->setCellValue('A'.$rowIdx, $rIdx + 1);
            $sheet->setCellValue('B'.$rowIdx, $r['date']);
            $sheet->setCellValue('C'.$rowIdx, $r['traveler_number']);
            $sheet->setCellValue('D'.$rowIdx, $r['heat_number']);
            $sheet->setCellValue('E'.$rowIdx, $r['production_code']);
            $sheet->setCellValue('F'.$rowIdx, $r['po_number']);
            $sheet->setCellValue('G'.$rowIdx, $r['item_name']);
            $sheet->setCellValue('H'.$rowIdx, $r['customer']);
            $sheet->setCellValue('I'.$rowIdx, $r['stage_label']);
            $sheet->setCellValue('J'.$rowIdx, $r['checkpoint_code']);
            $sheet->setCellValue('K'.$rowIdx, $r['defect_name']);
            $sheet->setCellValue('L'.$rowIdx, $r['qty']);
            $sheet->setCellValue('M'.$rowIdx, $r['has_weight'] ? $r['estimated_kg'] : '—');
            $sheet->setCellValue('N'.$rowIdx, $r['qc_verifier']);

            $sheet->getStyle('A'.$rowIdx.':F'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('G'.$rowIdx.':H'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle('I'.$rowIdx.':K'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('L'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('M'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('N'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $sheet->getStyle('L'.$rowIdx)->getNumberFormat()->setFormatCode('#,##0');
            if ($r['has_weight']) {
                $sheet->getStyle('M'.$rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');
            }

            $rowIdx++;
        }
        if ($rowIdx > $startDetailRow) {
            $sheet->getStyle('A'.($startDetailRow - 1).':N'.($rowIdx - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }

        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'Laporan_Defect_QC_Sand_Casting_'.$activeFilters['date_from'].'_'.$activeFilters['date_to'].'.xlsx';

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
