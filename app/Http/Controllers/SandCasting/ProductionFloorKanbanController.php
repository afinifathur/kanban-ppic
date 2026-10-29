<?php

namespace App\Http\Controllers\SandCasting;

use App\Http\Controllers\Controller;
use App\Services\SandCasting\SandCastingProductionFloorQueryService;
use App\Services\SandCasting\SandCastingStageAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductionFloorKanbanController extends Controller
{
    public const STAGE_LABELS = [
        'netto' => 'NETTO',
        'bubut_od' => 'BUBUT OD',
        'bubut_cnc' => 'BUBUT CNC',
        'bor' => 'BOR',
        'qc' => 'QC',
        'gudang_jadi' => 'GUDANG JADI',
    ];

    public function __construct(
        protected SandCastingProductionFloorQueryService $queryService,
        protected SandCastingStageAuthorizationService $authorizationService
    ) {}

    /**
     * Entry route for Sand Casting Operational Kanban:
     * - SPV defaults to their assigned stage.
     * - Admin / PPIC defaults to 'netto' or their first accessible stage.
     */
    public function index(Request $request): View|JsonResponse
    {
        $user = auth()->user();

        if ($user && $user->hasRole('spv') && ! empty($user->assigned_stage)) {
            return $this->show($request, $user->assigned_stage);
        }

        return $this->show($request, 'netto');
    }

    /**
     * Render the Operational Kanban board for a specific stage.
     */
    public function show(Request $request, string $stage): View|JsonResponse
    {
        $normalizedSlug = strtolower(trim($stage));
        $targetStage = SandCastingStageAuthorizationService::normalizeStage($normalizedSlug);

        if (! $targetStage) {
            abort(404, "Tahap operasional '{$stage}' tidak valid.");
        }

        $user = auth()->user();
        if (! $this->authorizationService->canViewStageKanban($user, $targetStage)) {
            $stageLabel = self::STAGE_LABELS[$targetStage] ?? strtoupper(str_replace('_', ' ', $targetStage));
            abort(403, "Anda tidak memiliki hak akses untuk membuka Kanban tahap {$stageLabel}.");
        }

        $stageLabel = self::STAGE_LABELS[$targetStage] ?? strtoupper(str_replace('_', ' ', $targetStage));

        // Extract and clean filters
        $filters = [];
        if ($request->filled('search')) {
            $filters['search'] = trim((string) $request->input('search'));
        }
        if ($request->filled('line_number') && is_numeric($request->input('line_number'))) {
            $filters['line_number'] = (int) $request->input('line_number');
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

        // Determine accessible stages for navigation tabs
        $availableStages = [];
        foreach (SandCastingStageAuthorizationService::VALID_STAGES as $stg) {
            if ($this->authorizationService->canViewStageKanban($user, $stg)) {
                $availableStages[$stg] = [
                    'stage' => $stg,
                    'slug' => str_replace('_', '-', $stg),
                    'label' => self::STAGE_LABELS[$stg] ?? strtoupper(str_replace('_', ' ', $stg)),
                    'is_active' => ($stg === $targetStage),
                    'url' => route('sand-casting.kanban.stage', str_replace('_', '-', $stg)),
                ];
            }
        }

        $scannerSlug = str_replace('_', '-', $targetStage);
        $scannerUrl = route('sand-casting.scan.stage', $scannerSlug);

        $canReorder = $this->authorizationService->canReorderQueue($user);

        return view('sand-casting.kanban.index', [
            'stage' => $targetStage,
            'stageSlug' => $scannerSlug,
            'stageLabel' => $stageLabel,
            'summary' => $kanbanData['summary'],
            'readyCards' => $kanbanData['ready'],
            'incomingCards' => $kanbanData['incoming'],
            'haltedCards' => $kanbanData['halted'],
            'filters' => $filters,
            'availableStages' => $availableStages,
            'scannerUrl' => $scannerUrl,
            'canReorder' => $canReorder,
            'lastRefreshedAt' => now()->format('H:i:s'),
        ]);
    }

    /**
     * Render the Print / PDF preview for Operational Kanban (A4 Landscape, 4-Line columns).
     */
    public function print(Request $request, string $stage): View
    {
        $normalizedSlug = strtolower(trim($stage));
        $targetStage = SandCastingStageAuthorizationService::normalizeStage($normalizedSlug);

        if (! $targetStage) {
            abort(404, "Tahap operasional '{$stage}' tidak valid.");
        }

        $user = auth()->user();
        if (! $this->authorizationService->canViewStageKanban($user, $targetStage)) {
            $stageLabel = self::STAGE_LABELS[$targetStage] ?? strtoupper(str_replace('_', ' ', $targetStage));
            abort(403, "Anda tidak memiliki hak akses untuk mencetak Kanban tahap {$stageLabel}.");
        }

        $stageLabel = self::STAGE_LABELS[$targetStage] ?? strtoupper(str_replace('_', ' ', $targetStage));

        // Extract and clean filters (exactly matching show())
        $filters = [];
        if ($request->filled('search')) {
            $filters['search'] = trim((string) $request->input('search'));
        }
        if ($request->filled('line_number') && is_numeric($request->input('line_number'))) {
            $filters['line_number'] = (int) $request->input('line_number');
        }
        if ($request->filled('customer')) {
            $filters['customer'] = trim((string) $request->input('customer'));
        }
        if ($request->filled('is_urgent')) {
            $filters['is_urgent'] = filter_var($request->input('is_urgent'), FILTER_VALIDATE_BOOLEAN);
        }

        $kanbanData = $this->queryService->getStageKanbanData($targetStage, $filters);
        $readyCards = $kanbanData['ready'];

        // Group cards strictly by production Line (1 to 4) preserving exact comparator order
        $readyByLine = [1 => [], 2 => [], 3 => [], 4 => []];
        foreach ($readyCards as $c) {
            $lNum = (int) ($c['line_number'] ?? 1);
            if ($lNum < 1 || $lNum > 4) {
                $lNum = 1;
            }
            $readyByLine[$lNum][] = $c;
        }

        $selectedLine = isset($filters['line_number']) ? (int) $filters['line_number'] : null;

        // Exact aggregates from printed ready cards ONLY
        $totalCards = count($readyCards);
        $totalPcs = (int) array_sum(array_column($readyCards, 'qty'));
        $totalWeightKg = (float) array_sum(array_column($readyCards, 'total_weight_kg'));

        // Generated timestamp in WIB (UTC+7)
        $printedAtWib = now()->setTimezone('Asia/Jakarta')->translatedFormat('d/m/Y H:i:s').' WIB';

        return view('sand-casting.kanban.print', [
            'stage' => $targetStage,
            'stageSlug' => str_replace('_', '-', $targetStage),
            'stageLabel' => $stageLabel,
            'readyCards' => $readyCards,
            'readyByLine' => $readyByLine,
            'selectedLine' => $selectedLine,
            'filters' => $filters,
            'totalCards' => $totalCards,
            'totalPcs' => $totalPcs,
            'totalWeightKg' => $totalWeightKg,
            'printedAtWib' => $printedAtWib,
        ]);
    }

    /**
     * Export Operational Kanban queue to multi-sheet Excel (XLSX: Line 1 to Line 4).
     */
    public function exportExcel(Request $request, string $stage): StreamedResponse
    {
        $normalizedSlug = strtolower(trim($stage));
        $targetStage = SandCastingStageAuthorizationService::normalizeStage($normalizedSlug);

        if (! $targetStage) {
            abort(404, "Tahap operasional '{$stage}' tidak valid.");
        }

        $user = auth()->user();
        if (! $this->authorizationService->canViewStageKanban($user, $targetStage)) {
            $stageLabel = self::STAGE_LABELS[$targetStage] ?? strtoupper(str_replace('_', ' ', $targetStage));
            abort(403, "Anda tidak memiliki hak akses untuk mengekspor Kanban tahap {$stageLabel}.");
        }

        $stageLabel = self::STAGE_LABELS[$targetStage] ?? strtoupper(str_replace('_', ' ', $targetStage));

        // Extract and clean filters, intentionally omitting line_number so all 4 lines are included
        $filters = [];
        if ($request->filled('search')) {
            $filters['search'] = trim((string) $request->input('search'));
        }
        if ($request->filled('customer')) {
            $filters['customer'] = trim((string) $request->input('customer'));
        }
        if ($request->filled('is_urgent')) {
            $filters['is_urgent'] = filter_var($request->input('is_urgent'), FILTER_VALIDATE_BOOLEAN);
        }

        $kanbanData = $this->queryService->getStageKanbanData($targetStage, $filters);
        $readyCards = $kanbanData['ready'];

        // Group cards strictly by production Line (1 to 4) preserving exact comparator order
        $readyByLine = [1 => [], 2 => [], 3 => [], 4 => []];
        foreach ($readyCards as $c) {
            $lNum = (int) ($c['line_number'] ?? 1);
            if ($lNum < 1 || $lNum > 4) {
                $lNum = 1;
            }
            $readyByLine[$lNum][] = $c;
        }

        // Export timestamp in WIB (Asia/Jakarta)
        $nowWib = now()->setTimezone('Asia/Jakarta');
        $exportedAtWib = $nowWib->translatedFormat('d/m/Y H:i:s').' WIB';

        // Prepare filter metadata description
        $filterParts = [];
        if (! empty($filters['search'])) {
            $filterParts[] = 'Pencarian: "'.$filters['search'].'"';
        }
        if (! empty($filters['customer'])) {
            $filterParts[] = 'Customer: "'.$filters['customer'].'"';
        }
        if (! empty($filters['is_urgent'])) {
            $filterParts[] = 'Status: HANYA URGENT';
        }
        $filterDescription = empty($filterParts)
            ? 'Filter: Semua Antrean (Tanpa Filter)'
            : 'Filter: '.implode(' | ', $filterParts);

        $spreadsheet = new Spreadsheet;

        // Aging formatting helper (matching PDF document)
        $formatAging = function ($agingData): string {
            $hours = is_array($agingData) ? (int) ($agingData['stage_aging_hours'] ?? 0) : 0;
            if ($hours >= 24) {
                $days = intdiv($hours, 24);
                $remHours = $hours % 24;

                return sprintf('Aging %dD %02dH', $days, $remHours);
            }

            return sprintf('Aging %02dH', $hours);
        };

        for ($line = 1; $line <= 4; $line++) {
            $sheet = ($line === 1) ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $sheet->setTitle("Line {$line}");

            // Page Setup
            $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
            $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);

            // Row 1: Company Header
            $sheet->setCellValue('A1', 'PT. PERONI KARYA SENTRA');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);

            // Row 2: Document Title & Line
            $sheet->setCellValue('A2', "KANBAN PRODUKSI — {$stageLabel} | LINE {$line}");
            $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11);

            // Row 3: Export Timestamp
            $sheet->setCellValue('A3', "Waktu Ekspor: {$exportedAtWib}");
            $sheet->getStyle('A3')->getFont()->setSize(9)->setItalic(true)->getColor()->setARGB('FF475569');

            // Row 4: Filter Metadata
            $sheet->setCellValue('A4', $filterDescription);
            $sheet->getStyle('A4')->getFont()->setSize(9)->getColor()->setARGB('FF64748B');

            // Merge A1:H1, A2:H2, A3:H3, A4:H4
            $sheet->mergeCells('A1:H1');
            $sheet->mergeCells('A2:H2');
            $sheet->mergeCells('A3:H3');
            $sheet->mergeCells('A4:H4');

            // Row 5: Table Headers
            $headers = [
                'A' => 'No. Urut',
                'B' => 'Nama Item',
                'C' => 'No. Heat',
                'D' => 'No. KTR',
                'E' => 'Jumlah (pcs)',
                'F' => 'Berat (kg)',
                'G' => 'Aging',
                'H' => 'Customer',
            ];

            foreach ($headers as $col => $title) {
                $cell = $col.'5';
                $sheet->setCellValue($cell, $title);
                $sheet->getStyle($cell)->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FFFFFFFF');
                $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0F172A'); // Slate 900
            }

            $sheet->getStyle('A5:H5')->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension(5)->setRowHeight(24);

            // Row 6 and onwards: Data Rows
            $cards = $readyByLine[$line];
            $count = count($cards);
            $currentRow = 6;

            foreach ($cards as $idx => $card) {
                $queueSeq = $card['queue_seq'] ?? ($idx + 1);
                $itemName = (string) ($card['item_name'] ?? '-');
                $heatNumber = (string) ($card['heat_number'] ?? '-');
                $ktrNumber = ! empty($card['traveler_number']) ? (string) $card['traveler_number'] : '';
                $qty = (int) ($card['qty'] ?? 0);
                $weight = (float) ($card['total_weight_kg'] ?? 0);
                $aging = $formatAging($card['aging'] ?? null);
                $customer = (string) ($card['customer'] ?? '-');

                $sheet->setCellValue("A{$currentRow}", $queueSeq);
                $sheet->setCellValue("B{$currentRow}", $itemName);
                $sheet->setCellValueExplicit("C{$currentRow}", $heatNumber, DataType::TYPE_STRING);
                if ($ktrNumber !== '') {
                    $sheet->setCellValueExplicit("D{$currentRow}", $ktrNumber, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue("D{$currentRow}", '');
                }
                $sheet->setCellValue("E{$currentRow}", $qty);
                $sheet->setCellValue("F{$currentRow}", $weight);
                $sheet->setCellValue("G{$currentRow}", $aging);
                $sheet->setCellValue("H{$currentRow}", $customer);

                // Alignments & Number formats
                $sheet->getStyle("A{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("B{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                $sheet->getStyle("C{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("D{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("E{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle("E{$currentRow}")->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle("F{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle("F{$currentRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle("G{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("H{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

                // Cell borders
                $sheet->getStyle("A{$currentRow}:H{$currentRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFCBD5E1');
                $sheet->getRowDimension($currentRow)->setRowHeight(19);

                $currentRow++;
            }

            // Total Row
            $totalRow = $currentRow;
            $sheet->mergeCells("A{$totalRow}:D{$totalRow}");
            $sheet->setCellValue("A{$totalRow}", 'TOTAL');

            if ($count > 0) {
                $lastDataRow = $currentRow - 1;
                $sheet->setCellValue("E{$totalRow}", "=SUM(E6:E{$lastDataRow})");
                $sheet->setCellValue("F{$totalRow}", "=SUM(F6:F{$lastDataRow})");
            } else {
                $sheet->setCellValue("E{$totalRow}", '=SUM(0)');
                $sheet->setCellValue("F{$totalRow}", '=SUM(0)');
            }

            $sheet->getStyle("A{$totalRow}:H{$totalRow}")->getFont()->setBold(true);
            $sheet->getStyle("A{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("E{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("E{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("F{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("F{$totalRow}")->getNumberFormat()->setFormatCode('#,##0.00');

            // Total row fill & border
            $sheet->getStyle("A{$totalRow}:H{$totalRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9');
            $sheet->getStyle("A{$totalRow}:H{$totalRow}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF94A3B8');
            $sheet->getStyle("A{$totalRow}:H{$totalRow}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE)->getColor()->setARGB('FF0F172A');
            $sheet->getRowDimension($totalRow)->setRowHeight(22);

            // AutoFilter & Freeze Panes
            $filterEndRow = max(5, $currentRow - 1);
            $sheet->setAutoFilter("A5:H{$filterEndRow}");
            $sheet->freezePane('A6');

            // Set column widths
            $columnWidths = [
                'A' => 10,
                'B' => 36,
                'C' => 16,
                'D' => 22,
                'E' => 14,
                'F' => 14,
                'G' => 18,
                'H' => 24,
            ];
            foreach ($columnWidths as $col => $w) {
                $sheet->getColumnDimension($col)->setWidth($w);
            }
        }

        // Reset active sheet to first sheet
        $spreadsheet->setActiveSheetIndex(0);

        // Sanitize stage name for filename
        $stageSlugForFile = strtoupper(str_replace(['_', ' '], '-', $targetStage));
        $timestampForFile = $nowWib->format('Ymd-His');
        $filename = "Kanban-{$stageSlugForFile}-{$timestampForFile}.xlsx";

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Reorder active ready queue items for a stage and line number.
     */
    public function reorder(Request $request, string $stage): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $user = auth()->user();
        if (! $this->authorizationService->canReorderQueue($user)) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya PPIC (ppicflange@peroniks.com) yang berwenang mengatur ulang antrean prioritas Kanban.',
                ], 403);
            }
            abort(403, 'Hanya PPIC (ppicflange@peroniks.com) yang berwenang mengatur ulang antrean prioritas Kanban.');
        }

        $validated = $request->validate([
            'line_number' => ['required', 'integer', 'min:1', 'max:4'],
            'from_position' => ['required', 'integer', 'min:1'],
            'to_position' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $result = $this->queryService->reorderStageLineQueue(
                $stage,
                (int) $validated['line_number'],
                (int) $validated['from_position'],
                (int) $validated['to_position']
            );

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => "Antrean Line {$validated['line_number']} berhasil diatur ulang (Posisi {$validated['from_position']} → {$validated['to_position']}).",
                    'data' => $result,
                ]);
            }

            return back()->with('success', "Antrean Line {$validated['line_number']} berhasil diatur ulang (Posisi {$validated['from_position']} → {$validated['to_position']}).");
        } catch (\InvalidArgumentException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return back()->withErrors(['reorder' => $e->getMessage()]);
        } catch (\Exception $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal mengatur ulang antrean: '.$e->getMessage(),
                ], 500);
            }

            return back()->withErrors(['reorder' => 'Gagal mengatur ulang antrean: '.$e->getMessage()]);
        }
    }
}
