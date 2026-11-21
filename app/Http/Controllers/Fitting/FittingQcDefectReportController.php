<?php

namespace App\Http\Controllers\Fitting;

use App\Http\Controllers\Controller;
use App\Models\FittingItem;
use App\Services\Fitting\FittingQcDefectReportService;
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

class FittingQcDefectReportController extends Controller
{
    public function __construct(
        protected readonly FittingQcDefectReportService $reportService,
        protected readonly FittingStageAuthorizationService $authService
    ) {}

    /**
     * Validate and normalize filter inputs for Fitting QC Defect Report.
     *
     * @return array{date_from: string, date_to: string, stage: string, line: int|null, defect_type_id: int|null, search: string}
     *
     * @throws ValidationException
     */
    protected function validateReportFilters(Request $request): array
    {
        $validStages = array_merge(['all'], array_keys(FittingQcDefectReportService::STAGES));

        $validated = $request->validate([
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d',
            'stage' => 'nullable|string',
            'line' => 'nullable',
            'defect_type_id' => 'nullable',
            'search' => 'nullable|string|max:100',
        ]);

        $defaultDate = date('Y-m-d');
        $dateFrom = $validated['date_from'] ?? $defaultDate;
        $dateTo = $validated['date_to'] ?? $defaultDate;
        $stage = ! empty($validated['stage']) ? strtolower(trim((string) $validated['stage'])) : 'all';
        $line = FittingItem::normalizeLineNumber($validated['line'] ?? null);
        $defectTypeId = ! empty($validated['defect_type_id']) && $validated['defect_type_id'] !== 'all'
            ? (int) $validated['defect_type_id']
            : null;
        $search = $validated['search'] ?? '';

        // 1. Stage validation
        if (! in_array($stage, $validStages, true)) {
            throw ValidationException::withMessages([
                'stage' => ["Tahapan (stage) '{$stage}' tidak valid dalam filter laporan QC Fitting."],
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

        // 3. Enforce maximum 45 calendar days inclusive
        $inclusiveDays = $fromCarbon->diffInDays($toCarbon) + 1;
        if ($inclusiveDays > 45) {
            throw ValidationException::withMessages([
                'date_to' => ['Rentang tanggal laporan maksimal 45 hari kalender inklusif.'],
            ]);
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'stage' => $stage,
            'line' => $line,
            'defect_type_id' => $defectTypeId,
            'search' => $search,
        ];
    }

    /**
     * Render the interactive Fitting QC Defect Report page.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        if (! FittingStageAuthorizationService::canViewReports($user)) {
            abort(403, 'Anda tidak memiliki hak akses untuk melihat Laporan QC Defect Fitting.');
        }

        $filters = $this->validateReportFilters($request);
        $data = $this->reportService->getReportDataset($filters, $user, paginate: true, perPage: 25);
        $data['records']->appends($request->query());

        return view('fitting.report.qc-defects.index', array_merge($data, [
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
        $data = $this->reportService->getReportDataset($filters, $user, paginate: false);

        return response()->json($data);
    }

    /**
     * Export Fitting QC Defect Report to printable view (PDF ready).
     */
    public function exportPdf(Request $request): View
    {
        $user = $request->user();
        if (! FittingStageAuthorizationService::canViewReports($user)) {
            abort(403, 'Anda tidak memiliki hak akses untuk mencetak Laporan QC Defect Fitting.');
        }

        $filters = $this->validateReportFilters($request);
        $data = $this->reportService->getReportDataset($filters, $user, paginate: false);

        return view('fitting.report.qc-defects.print', array_merge($data, [
            'activeFilters' => $data['filters'],
        ]));
    }

    /**
     * Export Fitting QC Defect Report to Excel (.xlsx) file.
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        $user = $request->user();
        if (! FittingStageAuthorizationService::canViewReports($user)) {
            abort(403, 'Anda tidak memiliki hak akses untuk mengekspor Laporan QC Defect Fitting.');
        }

        $filters = $this->validateReportFilters($request);
        $data = $this->reportService->getReportDataset($filters, $user, paginate: false);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator('Kanban PPIC System')
            ->setTitle('Laporan QC Defect Fitting Production')
            ->setSubject('Rekapitulasi Kerusakan Fisik QC Fitting');

        // ==========================================
        // SHEET 1: Rincian Data Defect (Traceability)
        // ==========================================
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('Rincian Defect QC');

        // Document Title Banner
        $sheet1->mergeCells('A1:P1');
        $sheet1->setCellValue('A1', 'LAPORAN RINCIAN QC DEFECT FITTING PRODUCTION (STATUS: CONFIRMED)');
        $sheet1->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('064E3B'));

        // Subtitle & Filter Info
        $sheet1->mergeCells('A2:P2');
        $sheet1->setCellValue('A2', "Periode: {$filters['date_from']} s/d {$filters['date_to']} | Tahapan: ".strtoupper($filters['stage']).' | Line: '.($filters['line'] ? FittingItem::lineLabelFor($filters['line']) : 'SEMUA LINE').' | Dicetak: '.now()->format('d/m/Y H:i'));
        $sheet1->getStyle('A2')->getFont()->setSize(10)->setItalic(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('475569'));

        // Summary KPI Banner
        $sheet1->mergeCells('A4:D4');
        $sheet1->setCellValue('A4', "TOTAL DEFECT: {$data['summary']['total_defect_pcs']} PCS");
        $sheet1->getStyle('A4')->getFont()->setBold(true)->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('BE123C'));

        $sheet1->mergeCells('E4:H4');
        $sheet1->setCellValue('E4', "ESTIMASI BERAT: {$data['summary']['total_estimated_kg']} KG");
        $sheet1->getStyle('E4')->getFont()->setBold(true)->setSize(11);

        $sheet1->mergeCells('I4:L4');
        $sheet1->setCellValue('I4', "PFKTR TERDAMPAK: {$data['summary']['affected_ktr_count']} TRAVELER");
        $sheet1->getStyle('I4')->getFont()->setBold(true)->setSize(11);

        $sheet1->mergeCells('M4:P4');
        $sheet1->setCellValue('M4', "TOP DEFECT: {$data['summary']['top_defect_type']}");
        $sheet1->getStyle('M4')->getFont()->setBold(true)->setSize(11);

        // Header Row for Sheet 1
        $headers1 = [
            'A6' => 'No',
            'B6' => 'Tgl Kejadian',
            'C6' => 'Waktu Verifikasi QC',
            'D6' => 'PFKTR (Traveler)',
            'E6' => 'Heat Number',
            'F6' => 'Kode Produksi',
            'G6' => 'PO Number',
            'H6' => 'Nama Produk',
            'I6' => 'Customer',
            'J6' => 'Line',
            'K6' => 'Tahapan',
            'L6' => 'Jenis Cacat (Defect)',
            'M6' => 'Qty Rusak (PCS)',
            'N6' => 'Estimasi Berat (KG)',
            'O6' => 'Petugas QC Verifikator',
            'P6' => 'Catatan',
        ];

        foreach ($headers1 as $cell => $text) {
            $sheet1->setCellValue($cell, $text);
        }

        $headerStyle1 = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '064E3B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ];
        $sheet1->getStyle('A6:P6')->applyFromArray($headerStyle1);

        $row1 = 7;
        foreach ($data['all_records'] as $idx => $r) {
            $sheet1->setCellValue("A{$row1}", $idx + 1);
            $sheet1->setCellValue("B{$row1}", $r['date']);
            $sheet1->setCellValue("C{$row1}", $r['verified_at_formatted']);
            $sheet1->setCellValue("D{$row1}", $r['traveler_number']);
            $sheet1->setCellValue("E{$row1}", $r['heat_number']);
            $sheet1->setCellValue("F{$row1}", $r['production_code']);
            $sheet1->setCellValue("G{$row1}", $r['po_number']);
            $sheet1->setCellValue("H{$row1}", $r['item_name']);
            $sheet1->setCellValue("I{$row1}", $r['customer']);
            $sheet1->setCellValue("J{$row1}", "Line {$r['line_number']}");
            $sheet1->setCellValue("K{$row1}", $r['stage_label']);
            $sheet1->setCellValue("L{$row1}", $r['defect_name']);
            $sheet1->setCellValue("M{$row1}", $r['qty']);
            $sheet1->setCellValue("N{$row1}", $r['estimated_kg'] ?? 0);
            $sheet1->setCellValue("O{$row1}", $r['qc_verifier']);
            $sheet1->setCellValue("P{$row1}", $r['notes']);

            // Formatting
            $sheet1->getStyle("A{$row1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet1->getStyle("B{$row1}:C{$row1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet1->getStyle("D{$row1}:E{$row1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet1->getStyle("J{$row1}:K{$row1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet1->getStyle("M{$row1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet1->getStyle("N{$row1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet1->getStyle("M{$row1}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet1->getStyle("N{$row1}")->getNumberFormat()->setFormatCode('#,##0.00');

            $row1++;
        }

        // Sheet 1 Total row
        if ($row1 > 7) {
            $lastDataRow1 = $row1 - 1;
            $sheet1->setCellValue("A{$row1}", 'TOTAL:');
            $sheet1->mergeCells("A{$row1}:L{$row1}");
            $sheet1->setCellValue("M{$row1}", "=SUM(M7:M{$lastDataRow1})");
            $sheet1->setCellValue("N{$row1}", "=SUM(N7:N{$lastDataRow1})");

            $sheet1->getStyle("A{$row1}:P{$row1}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            ]);
            $sheet1->getStyle("A{$row1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet1->getStyle("M{$row1}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet1->getStyle("N{$row1}")->getNumberFormat()->setFormatCode('#,##0.00');
        }

        foreach (range('A', 'P') as $col) {
            $sheet1->getColumnDimension($col)->setAutoSize(true);
        }

        // ==========================================
        // SHEET 2: Ringkasan Pareto Defect
        // ==========================================
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Ringkasan Pareto');

        $sheet2->mergeCells('A1:L1');
        $sheet2->setCellValue('A1', 'RINGKASAN PARETO DEFECT QC FITTING PRODUCTION');
        $sheet2->getStyle('A1')->getFont()->setBold(true)->setSize(13)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('064E3B'));

        $headers2 = [
            'A3' => 'No',
            'B3' => 'Jenis Cacat (Defect Type)',
            'C3' => 'Total Qty (PCS)',
            'D3' => 'Kontribusi (%)',
            'E3' => 'Estimasi Berat (KG)',
            'F3' => 'PFKTR Terdampak',
            'G3' => 'Netto PF',
            'H3' => 'Kimia',
            'I3' => 'Marking',
            'J3' => 'Bubut CNC PF',
            'K3' => 'QC PF',
            'L3' => 'Gudang Jadi PF',
        ];

        foreach ($headers2 as $cell => $text) {
            $sheet2->setCellValue($cell, $text);
        }
        $sheet2->getStyle('A3:L3')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        $row2 = 4;
        foreach ($data['pareto_summary'] as $pIdx => $p) {
            $sheet2->setCellValue("A{$row2}", $pIdx + 1);
            $sheet2->setCellValue("B{$row2}", $p['defect_name']);
            $sheet2->setCellValue("C{$row2}", $p['qty_pcs']);
            $sheet2->setCellValue("D{$row2}", ($p['contribution_pct'] / 100));
            $sheet2->setCellValue("E{$row2}", $p['estimated_kg']);
            $sheet2->setCellValue("F{$row2}", $p['ktr_count']);
            $sheet2->setCellValue("G{$row2}", $p['stage_breakdown']['netto_pf'] ?? 0);
            $sheet2->setCellValue("H{$row2}", $p['stage_breakdown']['kimia'] ?? 0);
            $sheet2->setCellValue("I{$row2}", $p['stage_breakdown']['marking'] ?? 0);
            $sheet2->setCellValue("J{$row2}", $p['stage_breakdown']['bubut_cnc_pf'] ?? 0);
            $sheet2->setCellValue("K{$row2}", $p['stage_breakdown']['qc_pf'] ?? 0);
            $sheet2->setCellValue("L{$row2}", $p['stage_breakdown']['gudang_jadi_pf'] ?? 0);

            $sheet2->getStyle("A{$row2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet2->getStyle("C{$row2}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet2->getStyle("D{$row2}")->getNumberFormat()->setFormatCode('0.0%');
            $sheet2->getStyle("E{$row2}")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet2->getStyle("F{$row2}:L{$row2}")->getNumberFormat()->setFormatCode('#,##0');

            $row2++;
        }

        if ($row2 > 4) {
            $lastDataRow2 = $row2 - 1;
            $sheet2->setCellValue("A{$row2}", 'TOTAL:');
            $sheet2->mergeCells("A{$row2}:B{$row2}");
            $sheet2->setCellValue("C{$row2}", "=SUM(C4:C{$lastDataRow2})");
            $sheet2->setCellValue("D{$row2}", "=SUM(D4:D{$lastDataRow2})");
            $sheet2->setCellValue("E{$row2}", "=SUM(E4:E{$lastDataRow2})");
            $sheet2->setCellValue("F{$row2}", $data['summary']['affected_ktr_count']);
            $sheet2->setCellValue("G{$row2}", "=SUM(G4:G{$lastDataRow2})");
            $sheet2->setCellValue("H{$row2}", "=SUM(H4:H{$lastDataRow2})");
            $sheet2->setCellValue("I{$row2}", "=SUM(I4:I{$lastDataRow2})");
            $sheet2->setCellValue("J{$row2}", "=SUM(J4:J{$lastDataRow2})");
            $sheet2->setCellValue("K{$row2}", "=SUM(K4:K{$lastDataRow2})");
            $sheet2->setCellValue("L{$row2}", "=SUM(L4:L{$lastDataRow2})");

            $sheet2->getStyle("A{$row2}:L{$row2}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            ]);
            $sheet2->getStyle("A{$row2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet2->getStyle("D{$row2}")->getNumberFormat()->setFormatCode('0.0%');
        }

        foreach (range('A', 'L') as $col) {
            $sheet2->getColumnDimension($col)->setAutoSize(true);
        }

        // Set active sheet to 1
        $spreadsheet->setActiveSheetIndex(0);

        $filename = 'Laporan_QC_Defect_Fitting_'.str_replace('-', '', $filters['date_from']).'_'.str_replace('-', '', $filters['date_to']).'.xlsx';

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
