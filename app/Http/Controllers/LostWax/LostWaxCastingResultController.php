<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use App\Models\LostWaxCastingResult;
use App\Services\LostWax\LostWaxCastingResultService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use InvalidArgumentException;

class LostWaxCastingResultController extends Controller
{
    public function __construct(
        private readonly LostWaxCastingResultService $castingResultService
    ) {}

    /**
     * Display listing of Lost Wax casting results (Heat History).
     */
    public function index(Request $request)
    {
        $search = trim($request->input('search', ''));
        $status = $request->input('status', 'ALL');
        $dateFrom = $request->input('date_from', '');
        $dateTo = $request->input('date_to', '');

        $query = $this->castingResultService->getHistoryQuery(
            $search !== '' ? $search : null,
            $status !== 'ALL' ? $status : null,
            $dateFrom !== '' ? $dateFrom : null,
            $dateTo !== '' ? $dateTo : null
        );

        $results = $query->paginate(20)->withQueryString();

        // Calculate summary cards
        $totalTransactions = LostWaxCastingResult::count();
        $completedResults = LostWaxCastingResult::where('status', 'COMPLETED');
        $totalCompleted = (clone $completedResults)->count();
        $totalTrees = (int) (clone $completedResults)->sum('total_trees');
        $totalPcs = (int) (clone $completedResults)->sum('total_pcs');
        $totalCancelled = LostWaxCastingResult::where('status', 'CANCELLED')->count();

        return view('lost-wax.casting-results.index', [
            'results' => $results,
            'search' => $search,
            'status' => $status,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'summary' => [
                'total_transactions' => $totalTransactions,
                'total_completed' => $totalCompleted,
                'total_trees' => $totalTrees,
                'total_pcs' => $totalPcs,
                'total_cancelled' => $totalCancelled,
            ],
        ]);
    }

    /**
     * Show form to record a new Lost Wax casting result.
     */
    public function create(Request $request)
    {
        $search = trim($request->input('search', ''));
        $codes = (array) $request->input('codes', []);
        $customers = (array) $request->input('customers', []);

        $codes = array_values(array_filter($codes, fn ($c) => $c !== null && $c !== ''));
        $customers = array_values(array_filter($customers, fn ($c) => $c !== null && $c !== ''));

        $scope = null;
        if (auth()->check() && auth()->user()->hasRole('ppic')) {
            $scope = auth()->user()->product_scope;
        }

        $queueData = $this->castingResultService->getAvailableQueue($search, $scope, $codes, $customers);

        return view('lost-wax.casting-results.create', [
            'items' => $queueData['items'],
            'summary' => $queueData['summary'],
            'search' => $search,
            'codes' => $codes,
            'customers' => $customers,
            'allCodes' => $queueData['all_codes'],
            'allCustomers' => $queueData['all_customers'],
            'defaultDate' => Carbon::today()->format('Y-m-d'),
        ]);
    }

    /**
     * Store newly created casting result transaction.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'heat_number' => 'required|string|max:100',
            'cast_date' => 'required|date',
            'furnace' => 'nullable|string|max:50',
            'shift' => 'nullable|string|max:20',
            'operator_name' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
            'tree_ids' => 'required|array|min:1',
            'tree_ids.*' => 'required|integer',
        ], [
            'heat_number.required' => 'Heat Number wajib diisi.',
            'cast_date.required' => 'Tanggal cor wajib diisi.',
            'tree_ids.required' => 'Minimal satu Traveler Card harus dipilih.',
            'tree_ids.min' => 'Minimal satu Traveler Card harus dipilih.',
        ]);

        try {
            $castingResult = $this->castingResultService->storeCastingResult($validated, auth()->user());

            return redirect()
                ->route('lost-wax.casting-results.show', $castingResult)
                ->with('success', "Transaksi Hasil Cor untuk Heat Number [{$castingResult->heat_number}] berhasil disimpan ({$castingResult->total_trees} pohon, {$castingResult->total_pcs} PCS).");
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['general' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['general' => 'Terjadi kesalahan sistem saat menyimpan hasil cor: '.$e->getMessage()]);
        }
    }

    /**
     * Display detail of a single casting result transaction.
     */
    public function show(LostWaxCastingResult $castingResult)
    {
        $castingResult->load(['trees.tree', 'recorder', 'voider']);

        return view('lost-wax.casting-results.show', [
            'castingResult' => $castingResult,
        ]);
    }

    /**
     * Display printable A4 portrait document for casting result.
     */
    public function print(LostWaxCastingResult $castingResult)
    {
        $castingResult->load(['trees.tree', 'recorder', 'voider']);

        return view('lost-wax.casting-results.print', [
            'castingResult' => $castingResult,
        ]);
    }

    /**
     * Display printable A4 aggregate daily casting report document.
     */
    public function dailyReport(Request $request)
    {
        $dateInput = $request->input('date');

        try {
            $parsedDate = $dateInput ? Carbon::parse($dateInput)->format('Y-m-d') : Carbon::today()->format('Y-m-d');
        } catch (\Throwable $e) {
            $parsedDate = Carbon::today()->format('Y-m-d');
        }

        $reportData = $this->castingResultService->getDailyReportData($parsedDate);

        return view('lost-wax.casting-results.daily-report', $reportData);
    }

    /**
     * Cancel / void an existing casting result transaction.
     */
    public function cancel(Request $request, LostWaxCastingResult $castingResult)
    {
        $request->validate([
            'reason' => 'required|string|min:3|max:500',
        ], [
            'reason.required' => 'Alasan pembatalan transaksi wajib diisi.',
            'reason.min' => 'Alasan pembatalan minimal 3 karakter.',
        ]);

        try {
            $updated = $this->castingResultService->cancelCastingResult(
                $castingResult,
                auth()->user(),
                $request->input('reason')
            );

            return redirect()
                ->route('lost-wax.casting-results.show', $updated)
                ->with('success', "Transaksi Heat [{$updated->heat_number}] berhasil dibatalkan. Traveler Card telah dikembalikan ke antrean Barang Siap Cor.");
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->back()
                ->withErrors(['cancel_error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return redirect()
                ->back()
                ->withErrors(['cancel_error' => 'Gagal membatalkan transaksi: '.$e->getMessage()]);
        }
    }
}
