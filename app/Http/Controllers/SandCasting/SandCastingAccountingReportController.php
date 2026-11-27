<?php

namespace App\Http\Controllers\SandCasting;

use App\Http\Controllers\Controller;
use App\Services\SandCasting\SandCastingAccountingReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SandCastingAccountingReportController extends Controller
{
    public function __construct(private readonly SandCastingAccountingReportService $reportService) {}

    /**
     * Validate and normalize filter inputs for Sand Casting Accounting Report.
     *
     * @return array{date_from: string, date_to: string, search: string, product_scope: ?string}
     *
     * @throws ValidationException
     */
    protected function validateReportFilters(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d',
            'search' => 'nullable|string|max:100',
            'product_scope' => 'nullable|string|max:50',
            'report_format' => 'nullable|string|in:detail,summary,ringkas',
            'format' => 'nullable|string|in:detail,summary,ringkas',
        ]);

        $defaultFrom = Carbon::now(SandCastingAccountingReportService::TIMEZONE)->startOfMonth()->toDateString();
        $defaultTo = Carbon::now(SandCastingAccountingReportService::TIMEZONE)->toDateString();

        $dateFrom = $validated['date_from'] ?? $defaultFrom;
        $dateTo = $validated['date_to'] ?? $defaultTo;
        $search = trim((string) ($validated['search'] ?? ''));
        $productScope = ! empty($validated['product_scope']) ? trim((string) $validated['product_scope']) : null;
        $rawFormat = $validated['report_format'] ?? ($validated['format'] ?? 'detail');
        $reportFormat = in_array($rawFormat, ['summary', 'ringkas'], true) ? 'summary' : 'detail';

        $fromCarbon = Carbon::parse($dateFrom)->startOfDay();
        $toCarbon = Carbon::parse($dateTo)->startOfDay();

        if ($fromCarbon->gt($toCarbon)) {
            throw ValidationException::withMessages([
                'date_from' => ['Tanggal Dari tidak boleh lebih besar dari Tanggal Sampai.'],
            ]);
        }

        // Validate Maximum 92 calendar days (approx 1 quarter)
        $inclusiveDays = $fromCarbon->diffInDays($toCarbon) + 1;
        if ($inclusiveDays > 92) {
            throw ValidationException::withMessages([
                'date_to' => ['Rentang tanggal laporan accounting maksimal 92 hari (1 kuartal).'],
            ]);
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'search' => $search,
            'product_scope' => $productScope,
            'report_format' => $reportFormat,
        ];
    }

    /**
     * Display the Accounting Report UI.
     */
    public function index(Request $request)
    {
        $filters = $this->validateReportFilters($request);
        $data = $this->reportService->getAccountingReportData($filters, $request->user());

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json($data);
        }

        return view('sand-casting.report.accounting.index', compact('data', 'filters'));
    }

    /**
     * Export the 4-Sheet Excel Workbook (.xlsx).
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        $filters = $this->validateReportFilters($request);
        $data = $this->reportService->getAccountingReportData($filters, $request->user());

        $spreadsheet = $this->reportService->buildExcelSpreadsheet($data);
        $formatSuffix = $filters['report_format'] === 'summary' ? '_Ringkas' : '';
        $filename = 'Laporan_Accounting_Sand_Casting'.$formatSuffix.'_'.$filters['date_from'].'_'.$filters['date_to'].'.xlsx';

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Export / Print printable landscape PDF view.
     */
    public function exportPdf(Request $request)
    {
        $filters = $this->validateReportFilters($request);
        $data = $this->reportService->getAccountingReportData($filters, $request->user());

        return view('sand-casting.report.accounting.print', compact('data', 'filters'));
    }
}
