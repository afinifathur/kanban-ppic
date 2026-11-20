<?php

namespace App\Http\Controllers\Fitting;

use App\Http\Controllers\Controller;
use App\Models\FittingItem;
use App\Services\Fitting\FittingProductionStatusService;
use App\Services\Fitting\FittingStageAuthorizationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FittingProductionStatusController extends Controller
{
    public function __construct(
        protected FittingProductionStatusService $statusService,
        protected FittingStageAuthorizationService $authService
    ) {}

    /**
     * Render Fitting Production Status Dashboard (or return JSON if AJAX).
     */
    public function index(Request $request): View|JsonResponse
    {
        $user = $request->user();

        try {
            $this->authService->authorizeViewProductionStatus($user);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $filters = [
            'tab' => $request->query('tab', $request->query('status', 'active')),
            'status' => $request->query('tab', $request->query('status', 'active')),
            'line' => $request->query('line'),
            'search' => $request->query('search'),
            'customer' => $request->query('customer'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'date_basis' => $request->query('date_basis', 'transfer'),
            'sort_by' => $request->query('sort_by', 'code'),
            'sort_dir' => $request->query('sort_dir', 'asc'),
            'per_page' => $request->query('per_page', 25),
            'page' => $request->query('page', 1),
        ];

        $data = $this->statusService->getProductionStatusData($filters, $user);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json($data);
        }

        return view('fitting.production-status.index', array_merge($data, [
            'lines' => FittingItem::LINE_LABELS,
        ]));
    }

    /**
     * Get detailed physical PFKTR traveler items for a specific Production Plan.
     */
    public function details(Request $request, mixed $plan = null): JsonResponse
    {
        $user = $request->user();

        try {
            $this->authService->authorizeViewProductionStatus($user);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $target = $plan ?? $request->input('production_plan_id')
            ?? $request->input('plan_id')
            ?? $request->input('code')
            ?? $request->input('production_code');

        if (! $target) {
            return response()->json([
                'message' => 'Parameter plan_id atau code harus diisi.',
                'items' => [],
            ], 422);
        }

        $details = $this->statusService->getPlanDetails($target, $user);

        if (! $details) {
            return response()->json([
                'message' => 'Data detail SPK Fitting tidak ditemukan.',
                'items' => [],
            ], 404);
        }

        return response()->json($details);
    }

    /**
     * Export Fitting Production Status to Excel (.xlsx).
     */
    public function exportXlsx(Request $request): StreamedResponse
    {
        $user = $request->user();

        try {
            $this->authService->authorizeViewProductionStatus($user);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $filters = [
            'tab' => $request->query('tab', $request->query('status', 'active')),
            'status' => $request->query('tab', $request->query('status', 'active')),
            'line' => $request->query('line'),
            'search' => $request->query('search'),
            'customer' => $request->query('customer'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'date_basis' => $request->query('date_basis', 'transfer'),
            'sort_by' => $request->query('sort_by', 'code'),
            'sort_dir' => $request->query('sort_dir', 'asc'),
            'per_page' => 10000, // Export all matching
            'page' => 1,
        ];

        $data = $this->statusService->getProductionStatusData($filters, $user);
        $paginator = $data['rows'];
        $rows = is_array($paginator) ? $paginator : $paginator->items();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Fitting Production Status');

        // Page setup
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);

        // Header metadata
        $sheet->setCellValue('A1', 'FITTING PRODUCTION — STATUS PRODUKSI');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('A2', 'Generated:');
        $sheet->setCellValue('B2', now()->format('Y-m-d H:i:s'));
        $sheet->setCellValue('D2', 'Generated By:');
        $sheet->setCellValue('E2', $user?->name ?? 'System');

        $sheet->setCellValue('A3', 'Status Tab:');
        $sheet->setCellValue('B3', strtoupper((string) ($filters['tab'] ?? 'ACTIVE')));
        $sheet->setCellValue('D3', 'Total SPK:');
        $sheet->setCellValue('E3', count($rows).' SPK');

        $lineLabel = $filters['line'] ? FittingItem::lineLabelFor((int) $filters['line']) : 'Semua Line';
        $sheet->setCellValue('A4', 'Line:');
        $sheet->setCellValue('B4', $lineLabel);
        $sheet->setCellValue('D4', 'Pencarian:');
        $sheet->setCellValue('E4', $filters['search'] ?: '-');

        $sheet->getStyle('A2:A4')->getFont()->setBold(true);
        $sheet->getStyle('D2:D4')->getFont()->setBold(true);

        // Table Two-Tier Header
        // Tier 1 (Row 6)
        $sheet->setCellValue('A6', 'INFORMASI SPK');
        $sheet->mergeCells('A6:E6');

        $sheet->setCellValue('F6', 'PASOKAN KITIR');
        $sheet->mergeCells('F6:G6');

        $sheet->setCellValue('H6', 'PROSES PRODUKSI FITTING (WIP & REJECT)');
        $sheet->mergeCells('H6:Q6');

        $sheet->setCellValue('R6', 'GUDANG JADI & STATUS');
        $sheet->mergeCells('R6:T6');

        // Tier 2 (Row 7)
        $headers = [
            'A7' => 'No',
            'B7' => 'Kode Produksi',
            'C7' => 'Customer',
            'D7' => 'Nama Item / Part',
            'E7' => 'Target PO',
            'F7' => 'Transfer (PCS)',
            'G7' => 'Status Kitir',
            'H7' => 'Netto (WIP)',
            'I7' => 'Netto (R)',
            'J7' => 'Kimia (WIP)',
            'K7' => 'Kimia (R)',
            'L7' => 'Marking (WIP)',
            'M7' => 'Marking (R)',
            'N7' => 'CNC (WIP)',
            'O7' => 'CNC (R)',
            'P7' => 'QC (WIP)',
            'Q7' => 'QC (R)',
            'R7' => 'Gudang Jadi',
            'S7' => 'Sisa PO',
            'T7' => 'Status SPK',
        ];

        foreach ($headers as $cell => $title) {
            $sheet->setCellValue($cell, $title);
        }

        // Header Styling
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F172A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '334155']]],
        ];
        $sheet->getStyle('A6:T6')->applyFromArray($headerStyle);
        $sheet->getStyle('A7:T7')->applyFromArray(array_merge($headerStyle, [
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
        ]));

        $sheet->getRowDimension(6)->setRowHeight(20);
        $sheet->getRowDimension(7)->setRowHeight(22);

        // Data Rows
        $rowIdx = 8;
        $num = 1;

        foreach ($rows as $r) {
            $sheet->setCellValue('A'.$rowIdx, $num++);
            $sheet->setCellValue('B'.$rowIdx, $r['code']);
            $sheet->setCellValue('C'.$rowIdx, $r['customer']);
            $sheet->setCellValue('D'.$rowIdx, $r['item_name']);
            $sheet->setCellValue('E'.$rowIdx, (int) $r['po_target']);
            $sheet->setCellValue('F'.$rowIdx, (int) $r['kitir_transfer']);
            $sheet->setCellValue('G'.$rowIdx, $r['kitir_badge'] === 'KITIR' ? "KITIR (-{$r['shortage']})" : 'CUKUP');
            $sheet->setCellValue('H'.$rowIdx, (int) $r['wip_netto']);
            $sheet->setCellValue('I'.$rowIdx, (int) $r['r_netto']);
            $sheet->setCellValue('J'.$rowIdx, (int) $r['wip_kimia']);
            $sheet->setCellValue('K'.$rowIdx, (int) $r['r_kimia']);
            $sheet->setCellValue('L'.$rowIdx, (int) $r['wip_marking']);
            $sheet->setCellValue('M'.$rowIdx, (int) $r['r_marking']);
            $sheet->setCellValue('N'.$rowIdx, (int) $r['wip_cnc']);
            $sheet->setCellValue('O'.$rowIdx, (int) $r['r_cnc']);
            $sheet->setCellValue('P'.$rowIdx, (int) $r['wip_qc']);
            $sheet->setCellValue('Q'.$rowIdx, (int) $r['r_qc']);
            $sheet->setCellValue('R'.$rowIdx, (int) $r['gd']);
            $sheet->setCellValue('S'.$rowIdx, (int) $r['remaining_po']);
            $sheet->setCellValue('T'.$rowIdx, $r['status']);

            // Alignments
            $sheet->getStyle('A'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('B'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle('C'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle('D'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle('E'.$rowIdx.':F'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('G'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('H'.$rowIdx.':S'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('T'.$rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Numbers format
            $sheet->getStyle('E'.$rowIdx.':F'.$rowIdx)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('H'.$rowIdx.':S'.$rowIdx)->getNumberFormat()->setFormatCode('#,##0');

            // Zebra striping
            if ($num % 2 === 0) {
                $sheet->getStyle('A'.$rowIdx.':T'.$rowIdx)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F8FAFC');
            }

            // Cell border
            $sheet->getStyle('A'.$rowIdx.':T'.$rowIdx)->getBorders()
                ->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E2E8F0');

            $rowIdx++;
        }

        // Auto widths
        foreach (range('A', 'T') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('D')->setAutoSize(false)->setWidth(30);

        // Freeze pane below headers
        $sheet->freezePane('A8');

        $filename = 'Fitting_Production_Status_'.now()->format('Y-m-d').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'max-age=0',
        ]);
    }
}
