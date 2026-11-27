<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use App\Services\LostWax\LostWaxAccountingReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LostWaxAccountingReportController extends Controller
{
    public function __construct(private readonly LostWaxAccountingReportService $reportService) {}

    /**
     * Validate and normalize filter inputs for Lost Wax Accounting Report.
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
        ]);

        $defaultFrom = Carbon::now(LostWaxAccountingReportService::TIMEZONE)->startOfMonth()->toDateString();
        $defaultTo = Carbon::now(LostWaxAccountingReportService::TIMEZONE)->toDateString();

        $dateFrom = $validated['date_from'] ?? $defaultFrom;
        $dateTo = $validated['date_to'] ?? $defaultTo;
        $search = trim((string) ($validated['search'] ?? ''));
        $productScope = ! empty($validated['product_scope']) ? trim((string) $validated['product_scope']) : null;

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
        ];
    }

    /**
     * Display the Accounting Report UI (Single format: Ringkas per Kode Produksi).
     */
    public function index(Request $request)
    {
        $filters = $this->validateReportFilters($request);
        $data = $this->reportService->getAccountingReportData($filters, $request->user());

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json($data);
        }

        return view('lost-wax.report.accounting.index', compact('data', 'filters'));
    }

    /**
     * Export the Single-Sheet 29-Column Excel Workbook (.xlsx).
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        $filters = $this->validateReportFilters($request);
        $data = $this->reportService->getAccountingReportData($filters, $request->user());

        $spreadsheet = $this->reportService->buildExcelSpreadsheet($data);
        $filename = 'Laporan_Accounting_Lost_Wax_Ringkas_'.$filters['date_from'].'_'.$filters['date_to'].'.xlsx';

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

        return view('lost-wax.report.accounting.print', compact('data', 'filters'));
    }
}
