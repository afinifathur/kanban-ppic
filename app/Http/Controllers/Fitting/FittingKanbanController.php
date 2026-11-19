<?php

namespace App\Http\Controllers\Fitting;

use App\Http\Controllers\Controller;
use App\Models\FittingItem;
use App\Services\Fitting\FittingKanbanQueryService;
use App\Services\Fitting\FittingStageAuthorizationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FittingKanbanController extends Controller
{
    public function __construct(
        private readonly FittingStageAuthorizationService $authService,
        private readonly FittingKanbanQueryService $queryService
    ) {}

    /**
     * Entry route for Fitting Floor Kanban:
     * - Full visibility users (Admin, PPIC Fitting, Admin PPIC PF, Admin QC Fitting, SPV GD PF, Auditor, Sales) -> netto_pf
     * - Single-stage SPV Fitting -> their assigned_stage
     */
    public function index(Request $request): View|JsonResponse
    {
        $user = auth()->user();

        if (FittingStageAuthorizationService::hasFullKanbanVisibility($user)) {
            return $this->show($request, 'netto-pf');
        }

        if ($user && $user->hasRole(FittingStageAuthorizationService::ROLE_SPV_FITTING) && ! empty($user->assigned_stage)) {
            $canonical = FittingStageAuthorizationService::normalizeStage($user->assigned_stage);
            if ($canonical !== null) {
                return $this->show($request, str_replace('_', '-', $canonical));
            }
        }

        return $this->show($request, 'netto-pf');
    }

    /**
     * Display Fitting Kanban Floor board for a specific stage (Line 1..4 + Unassigned).
     */
    public function show(Request $request, string $stage): View|JsonResponse
    {
        $normalizedSlug = strtolower(trim($stage));
        $targetStage = FittingStageAuthorizationService::normalizeStage($normalizedSlug);

        if ($targetStage === null) {
            abort(404, "Tahap operasional Fitting '{$stage}' tidak valid.");
        }

        $user = auth()->user();
        try {
            $this->authService->authorizeKanbanView($user, $targetStage);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $filters = [];
        if ($request->filled('search')) {
            $filters['search'] = trim((string) $request->input('search'));
        }
        if ($request->filled('line_bucket')) {
            $filters['line_bucket'] = trim((string) $request->input('line_bucket'));
        }
        if ($request->filled('customer')) {
            $filters['customer'] = trim((string) $request->input('customer'));
        }
        if ($request->filled('is_urgent')) {
            $filters['is_urgent'] = filter_var($request->input('is_urgent'), FILTER_VALIDATE_BOOLEAN);
        }

        $kanbanData = $this->queryService->getStageKanbanData($targetStage, $filters);

        if ($request->wantsJson()) {
            return response()->json($kanbanData);
        }

        // Build accessible stage switcher tabs
        $availableStages = [];
        foreach (FittingStageAuthorizationService::VALID_STAGES as $stg) {
            if ($this->authService->canViewStageKanban($user, $stg)) {
                $stgSlug = str_replace('_', '-', $stg);
                $availableStages[$stg] = [
                    'stage' => $stg,
                    'slug' => $stgSlug,
                    'label' => FittingStageAuthorizationService::STAGE_LABELS[$stg],
                    'is_active' => $stg === $targetStage,
                    'url' => route('fitting.kanban.stage', $stgSlug),
                ];
            }
        }

        $stageSlug = str_replace('_', '-', $targetStage);
        $canReorder = $this->authService->canReorderKanban($user);
        $canScan = $this->authService->canExecuteStage($user, $targetStage);

        return view('fitting.kanban.index', [
            'stage' => $targetStage,
            'stageSlug' => $stageSlug,
            'stageLabel' => $kanbanData['stage_label'],
            'cards' => $kanbanData['cards'],
            'buckets' => $kanbanData['buckets'],
            'summary' => $kanbanData['summary'],
            'filters' => $filters,
            'availableStages' => $availableStages,
            'canReorder' => $canReorder,
            'canScan' => $canScan,
            'scannerUrl' => $canScan ? route('fitting.scan.stage', $stageSlug) : null,
            'reorderUrl' => route('fitting.kanban.reorder', $stageSlug),
            'lastRefreshedAt' => now()->format('H:i:s'),
        ]);
    }

    /**
     * Print view for Fitting Floor Kanban (A4 Landscape PDF printable).
     */
    public function print(Request $request, string $stage): View
    {
        $normalizedSlug = strtolower(trim($stage));
        $targetStage = FittingStageAuthorizationService::normalizeStage($normalizedSlug);

        if ($targetStage === null) {
            abort(404, "Tahap operasional Fitting '{$stage}' tidak valid.");
        }

        $user = auth()->user();
        try {
            $this->authService->authorizeKanbanView($user, $targetStage);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $filters = [];
        if ($request->filled('search')) {
            $filters['search'] = trim((string) $request->input('search'));
        }
        if ($request->filled('line_bucket')) {
            $filters['line_bucket'] = trim((string) $request->input('line_bucket'));
        }
        if ($request->filled('customer')) {
            $filters['customer'] = trim((string) $request->input('customer'));
        }
        if ($request->filled('is_urgent')) {
            $filters['is_urgent'] = filter_var($request->input('is_urgent'), FILTER_VALIDATE_BOOLEAN);
        }

        $kanbanData = $this->queryService->getStageKanbanData($targetStage, $filters);
        $stageSlug = str_replace('_', '-', $targetStage);
        $printedAtWib = now()->setTimezone('Asia/Jakarta')->translatedFormat('d/m/Y H:i:s').' WIB';

        return view('fitting.kanban.print', [
            'stage' => $targetStage,
            'stageSlug' => $stageSlug,
            'stageLabel' => $kanbanData['stage_label'],
            'cards' => $kanbanData['cards'],
            'buckets' => $kanbanData['buckets'],
            'summary' => $kanbanData['summary'],
            'filters' => $filters,
            'printedAtWib' => $printedAtWib,
        ]);
    }

    /**
     * Export Fitting Kanban queue to multi-sheet Excel (XLSX).
     */
    public function exportExcel(Request $request, string $stage): StreamedResponse
    {
        $normalizedSlug = strtolower(trim($stage));
        $targetStage = FittingStageAuthorizationService::normalizeStage($normalizedSlug);

        if ($targetStage === null) {
            abort(404, "Tahap operasional Fitting '{$stage}' tidak valid.");
        }

        $user = auth()->user();
        try {
            $this->authService->authorizeKanbanView($user, $targetStage);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $filters = [];
        if ($request->filled('search')) {
            $filters['search'] = trim((string) $request->input('search'));
        }
        if ($request->filled('line_bucket')) {
            $filters['line_bucket'] = trim((string) $request->input('line_bucket'));
        }
        if ($request->filled('customer')) {
            $filters['customer'] = trim((string) $request->input('customer'));
        }
        if ($request->filled('is_urgent')) {
            $filters['is_urgent'] = filter_var($request->input('is_urgent'), FILTER_VALIDATE_BOOLEAN);
        }

        $kanbanData = $this->queryService->getStageKanbanData($targetStage, $filters);
        $stageLabel = $kanbanData['stage_label'];
        $stageSlug = str_replace('_', '-', $targetStage);
        $buckets = $kanbanData['buckets'];

        $nowWib = now()->setTimezone('Asia/Jakarta');
        $exportedAtWib = $nowWib->translatedFormat('d/m/Y H:i:s').' WIB';

        $filterParts = [];
        if (! empty($filters['search'])) {
            $filterParts[] = 'Pencarian: "'.$filters['search'].'"';
        }
        if (! empty($filters['customer'])) {
            $filterParts[] = 'Customer: "'.$filters['customer'].'"';
        }
        if (! empty($filters['line_bucket'])) {
            $filterParts[] = 'Line: '.FittingItem::lineLabelFor((int) $filters['line_bucket']);
        }
        if (! empty($filters['is_urgent'])) {
            $filterParts[] = 'Status: HANYA URGENT';
        }
        $filterDescription = empty($filterParts)
            ? 'Filter: Semua Antrean (Tanpa Filter)'
            : 'Filter: '.implode(' | ', $filterParts);

        $spreadsheet = new Spreadsheet;

        $linesToExport = ! empty($filters['line_bucket']) && in_array($filters['line_bucket'], ['1', '2', '3', '4'], true)
            ? [(int) $filters['line_bucket']]
            : [1, 2, 3, 4];

        $sheetIndex = 0;
        foreach ($linesToExport as $line) {
            $sheet = ($sheetIndex === 0) ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $sheetIndex++;

            $lineName = FittingItem::lineLabelFor($line);
            $sheetTitle = substr("Line {$line}", 0, 31);
            $sheet->setTitle($sheetTitle);

            // Page Setup
            $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
            $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);

            // Row 1: Company Header
            $sheet->setCellValue('A1', 'PT. PERONI KARYA SENTRA');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);

            // Row 2: Document Title & Line
            $sheet->setCellValue('A2', "KANBAN FITTING PRODUCTION — {$stageLabel} | {$lineName}");
            $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11);

            // Row 3: Export Timestamp
            $sheet->setCellValue('A3', "Waktu Ekspor: {$exportedAtWib}");
            $sheet->getStyle('A3')->getFont()->setSize(9)->setItalic(true)->getColor()->setARGB('FF475569');

            // Row 4: Filter Metadata
            $sheet->setCellValue('A4', $filterDescription);
            $sheet->getStyle('A4')->getFont()->setSize(9)->getColor()->setARGB('FF64748B');

            $sheet->mergeCells('A1:L1');
            $sheet->mergeCells('A2:L2');
            $sheet->mergeCells('A3:L3');
            $sheet->mergeCells('A4:L4');

            // Table Headers
            $headers = [
                'A' => 'No. Urut',
                'B' => 'No. Traveler (PFKTR)',
                'C' => 'No. Heat',
                'D' => 'Kode Produksi',
                'E' => 'Nama Produk',
                'F' => 'Customer Code',
                'G' => 'Line Fitting',
                'H' => 'Jumlah (pcs)',
                'I' => 'Berat (kg)',
                'J' => 'Tgl Transfer',
                'K' => 'Aging',
                'L' => 'Stage',
            ];

            foreach ($headers as $col => $title) {
                $cell = $col.'5';
                $sheet->setCellValue($cell, $title);
                $sheet->getStyle($cell)->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FFFFFFFF');
                $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0F172A');
            }

            $sheet->getStyle('A5:L5')->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension(5)->setRowHeight(24);

            $cards = $buckets[(string) $line] ?? [];
            $currentRow = 6;

            foreach ($cards as $idx => $card) {
                $queueSeq = $card['queue_seq'] ?? ($idx + 1);
                $traveler = (string) ($card['traveler_number'] ?? '-');
                $heatNumber = (string) ($card['heat_number'] ?? '-');
                $prodCode = (string) ($card['production_code'] ?? '-');
                $itemName = (string) ($card['item_name'] ?? '-');
                $customer = (string) ($card['customer'] ?? '-');
                $lineLabel = (string) ($card['line_label'] ?? "Line {$line}");
                $qty = (int) ($card['effective_input_qty'] ?? $card['qty_transferred'] ?? 0);
                $weight = (float) ($card['total_weight_kg'] ?? 0.0);
                $transferDate = (string) ($card['transfer_date_formatted'] ?? '-');
                $aging = (string) ($card['aging']['label'] ?? '-');
                $stageText = (string) ($card['current_stage_label'] ?? $stageLabel);

                $sheet->setCellValue("A{$currentRow}", $queueSeq);
                $sheet->setCellValueExplicit("B{$currentRow}", $traveler, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("C{$currentRow}", $heatNumber, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("D{$currentRow}", $prodCode, DataType::TYPE_STRING);
                $sheet->setCellValue("E{$currentRow}", $itemName);
                $sheet->setCellValueExplicit("F{$currentRow}", $customer, DataType::TYPE_STRING);
                $sheet->setCellValue("G{$currentRow}", $lineLabel);
                $sheet->setCellValue("H{$currentRow}", $qty);
                $sheet->setCellValue("I{$currentRow}", $weight);
                $sheet->setCellValue("J{$currentRow}", $transferDate);
                $sheet->setCellValue("K{$currentRow}", $aging);
                $sheet->setCellValue("L{$currentRow}", $stageText);

                $sheet->getStyle("A{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("B{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("C{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("D{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("E{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                $sheet->getStyle("F{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("G{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                $sheet->getStyle("H{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle("H{$currentRow}")->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle("I{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle("I{$currentRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle("J{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("K{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("L{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $sheet->getStyle("A{$currentRow}:L{$currentRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFCBD5E1');
                $sheet->getRowDimension($currentRow)->setRowHeight(19);

                $currentRow++;
            }

            // Total Row
            $sheet->setCellValue("A{$currentRow}", 'TOTAL');
            $sheet->mergeCells("A{$currentRow}:G{$currentRow}");
            $sheet->getStyle("A{$currentRow}")->getFont()->setBold(true);
            $sheet->getStyle("A{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            $lastDataRow = $currentRow - 1;
            if ($lastDataRow >= 6) {
                $sheet->setCellValue("H{$currentRow}", "=SUM(H6:H{$lastDataRow})");
                $sheet->setCellValue("I{$currentRow}", "=SUM(I6:I{$lastDataRow})");
            } else {
                $sheet->setCellValue("H{$currentRow}", 0);
                $sheet->setCellValue("I{$currentRow}", 0);
            }

            $sheet->getStyle("H{$currentRow}:I{$currentRow}")->getFont()->setBold(true);
            $sheet->getStyle("H{$currentRow}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("I{$currentRow}")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("H{$currentRow}:I{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("A{$currentRow}:L{$currentRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF94A3B8');
            $sheet->getStyle("A{$currentRow}:L{$currentRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9');

            // Auto-size columns
            foreach (range('A', 'L') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
        }

        // Set first sheet active
        $spreadsheet->setActiveSheetIndex(0);

        $filename = "Kanban-Fitting-{$stageSlug}-".$nowWib->format('Ymd-His').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Reorder active queue items within a stage and line bucket ('1'..'4' or 'unassigned').
     * Strictly authorized to PPIC Fitting (`manage_fitting_kanban`) and System Admin.
     */
    public function reorder(Request $request, string $stage): JsonResponse|RedirectResponse
    {
        $user = auth()->user();
        try {
            $this->authService->authorizeReorderKanban($user);
        } catch (AuthorizationException $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
            }
            abort(403, $e->getMessage());
        }

        $validated = $request->validate([
            'line_bucket' => ['required', 'string', 'in:1,2,3,4,unassigned'],
            'from_position' => ['required', 'integer', 'min:1'],
            'to_position' => ['required', 'integer', 'min:1'],
        ], [
            'line_bucket.in' => 'Bucket Line harus bernilai 1, 2, 3, 4, atau unassigned.',
        ]);

        try {
            $result = $this->queryService->reorderStageBucketQueue(
                $stage,
                $validated['line_bucket'],
                (int) $validated['from_position'],
                (int) $validated['to_position'],
                $user
            );

            $bucketLabel = $validated['line_bucket'] === 'unassigned'
                ? 'Unassigned'
                : "Line {$validated['line_bucket']}";
            $msg = "Antrean {$bucketLabel} berhasil diatur ulang (Posisi {$validated['from_position']} → {$validated['to_position']}).";

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $msg,
                    'data' => $result,
                ]);
            }

            return back()->with('success', $msg);
        } catch (AuthorizationException $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
            }
            abort(403, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->withErrors(['reorder' => $e->getMessage()]);
        }
    }
}
