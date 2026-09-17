<?php

namespace App\Http\Controllers\SandCasting;

use App\Http\Controllers\Controller;
use App\Models\SandCastingCastingOrderLine;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Services\SandCasting\SandCastingCastingResultService;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Picqer\Barcode\BarcodeGeneratorPNG;

class CastingResultController extends Controller
{
    protected SandCastingCastingResultService $resultService;

    public function __construct(SandCastingCastingResultService $resultService)
    {
        $this->resultService = $resultService;
    }

    /**
     * Display a listing of casting results (Heat).
     */
    public function index(Request $request)
    {
        $scope = auth()->user()->product_scope;
        $isPpic = auth()->user()->hasRole('ppic');

        $query = SandCastingCastingResult::with([
            'recorder',
            'lines.castingOrderLine.castingOrder',
            'lines.productionPlan',
        ]);

        if ($isPpic && $scope) {
            $query->whereHas('lines.productionPlan', function ($q) use ($scope) {
                $q->where('product_scope', $scope);
            });
        }

        if ($request->filled('heat_number')) {
            $query->where('heat_number', 'like', '%'.$request->heat_number.'%');
        }

        if ($request->filled('date')) {
            $query->whereDate('cast_date', $request->date);
        }

        if ($request->filled('furnace')) {
            $query->where('furnace', 'like', '%'.$request->furnace.'%');
        }

        if ($request->filled('shift')) {
            $query->where('shift', $request->shift);
        }

        $results = $query->orderBy('id', 'desc')
            ->paginate(15)
            ->withQueryString();

        return view('sand-casting.casting-results.index', compact('results'));
    }

    /**
     * Show form for recording actual casting results (Heat).
     * Supports pool-oriented input, optionally pre-filtered by Perintah Cor.
     */
    public function create(Request $request)
    {
        $scope = auth()->user()->product_scope;
        $isPpic = auth()->user()->hasRole('ppic');
        $preselectedOrderId = $request->query('casting_order_id');

        $linesQuery = SandCastingCastingOrderLine::with(['castingOrder', 'productionPlan', 'resultLines'])
            ->whereHas('castingOrder', function ($q) {
                $q->whereIn('status', ['ISSUED', 'COMPLETED']);
            })
            ->whereHas('productionPlan', function ($q) {
                $q->where('production_domain', \App\Models\ProductionPlan::DOMAIN_SAND_CASTING);
            });

        if ($isPpic && $scope) {
            $linesQuery->whereHas('productionPlan', function ($q) use ($scope) {
                $q->where('product_scope', $scope);
            });
        }

        if ($preselectedOrderId) {
            $linesQuery->where('sand_casting_casting_order_id', $preselectedOrderId);
        }

        $availableLines = $linesQuery->get()->filter(function ($line) {
            return $line->qty_remaining_to_cast > 0;
        })->values();

        return view('sand-casting.casting-results.create', compact('availableLines', 'preselectedOrderId'));
    }

    /**
     * Store a newly recorded standalone casting result (Heat) in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'heat_number' => 'required|string|max:50',
            'cast_date' => 'required|date',
            'furnace' => 'nullable|string|max:50',
            'shift' => 'nullable|string|max:20',
            'operator_name' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.sand_casting_casting_order_line_id' => 'required|integer|exists:sand_casting_casting_order_lines,id',
            'items.*.qty_good' => 'required|integer|min:0',
            'items.*.qty_reject' => 'nullable|integer|min:0',
            'items.*.unit_weight_kg' => 'nullable|numeric|min:0',
            'items.*.total_weight_kg' => 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string|max:255',
        ]);

        $headerData = [
            'heat_number' => $validated['heat_number'],
            'cast_date' => $validated['cast_date'],
            'furnace' => $validated['furnace'] ?? null,
            'shift' => $validated['shift'] ?? null,
            'operator_name' => $validated['operator_name'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ];

        try {
            $result = $this->resultService->recordResult(
                $headerData,
                $validated['items'],
                auth()->id()
            );

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => "Hasil Cor Heat {$result->heat_number} berhasil disimpan.",
                    'result' => $result,
                ]);
            }

            return redirect()->route('sand-casting.casting-results.show', $result)
                ->with('success', "Hasil Cor untuk Heat {$result->heat_number} berhasil dicatat (Total: {$result->total_qty_good} pcs).");
        } catch (InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'bukan bagian dari domain Sand Casting') || str_contains($e->getMessage(), 'Unauthorized')) {
                abort(403, $e->getMessage());
            }

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Display the specified casting result detail.
     */
    public function show(SandCastingCastingResult $castingResult)
    {
        $castingResult->load([
            'lines.castingOrderLine.castingOrder.creator',
            'lines.productionPlan',
            'recorder',
        ]);

        $this->authorizeCastingResult($castingResult);

        return view('sand-casting.casting-results.show', compact('castingResult'));
    }

    /**
     * Display the printable Kitir Produksi (A4 Landscape) for an individual casting result line.
     */
    public function kitir(SandCastingCastingResult $castingResult, SandCastingCastingResultLine $line)
    {
        if ($line->sand_casting_casting_result_id !== $castingResult->id) {
            abort(404, 'Baris Hasil Cor tidak ditemukan pada Heat ini.');
        }

        $this->authorizeCastingResult($castingResult);

        $plan = $line->productionPlan;
        if ($plan && ! $plan->isSandCasting()) {
            abort(403, 'Item bukan bagian dari domain Sand Casting.');
        }

        $line->increment('print_count', 1, [
            'printed_at' => now(),
            'last_printed_by' => auth()->id(),
        ]);

        $line->load([
            'castingResult.recorder',
            'castingOrderLine.castingOrder.creator',
            'productionPlan',
        ]);

        $generator = new BarcodeGeneratorPNG;
        $barcodeBase64 = base64_encode($generator->getBarcode($line->traveler_number, $generator::TYPE_CODE_128, 3, 90));

        return view('sand-casting.casting-results.kitir', compact('castingResult', 'line', 'barcodeBase64'));
    }

    /**
     * Authorize user access to casting result based on product scope of its lines.
     */
    protected function authorizeCastingResult(SandCastingCastingResult $castingResult): void
    {
        $scope = auth()->user()->product_scope;
        if (auth()->user()->hasRole('ppic') && $scope) {
            $hasUnauthorizedLines = $castingResult->lines()->whereHas('productionPlan', function ($q) use ($scope) {
                $q->where('product_scope', '!=', $scope);
            })->exists();

            if ($hasUnauthorizedLines) {
                abort(403, 'Unauthorized.');
            }
        }
    }
}
