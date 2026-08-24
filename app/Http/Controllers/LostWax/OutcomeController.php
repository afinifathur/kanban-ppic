<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OutcomeController extends Controller
{
    /**
     * Display list of Print Orders ready for actual outcomes entry (status ISSUED or PARTIALLY_COMPLETED).
     */
    public function index(Request $request)
    {
        $query = \App\Models\LostWaxPrintOrder::with(['lines.trees', 'lines.executions', 'creator'])
            ->whereNotIn('status', ['DRAFT', 'CANCELLED']);

        $scope = auth()->user()->product_scope;
        if (auth()->user()->hasRole('ppic') && $scope) {
            $query->whereHas('lines.productionPlan', function ($q) use ($scope) {
                $q->where('product_scope', $scope);
            });
        }

        if ($request->filled('print_order_number')) {
            $query->where('print_order_number', 'like', '%'.$request->print_order_number.'%');
        }

        $printOrders = $query->orderBy('id', 'desc')->paginate(15);

        return view('lost-wax.outcomes.index', compact('printOrders'));
    }

    /**
     * Show form to record actual good/defect outputs.
     */
    public function editOutcome(\App\Models\LostWaxPrintOrder $printOrder)
    {
        $this->authorizePrintOrder($printOrder);
        if (! in_array($printOrder->status, ['ISSUED', 'PARTIALLY_COMPLETED'])) {
            return redirect()->route('lost-wax.outcomes.index')
                ->with('error', 'Hasil cetak hanya dapat dicatat untuk dokumen berstatus ISSUED atau PARTIALLY_COMPLETED.');
        }

        $printOrder->load('lines.trees', 'lines.executions.recorder');

        return view('lost-wax.outcomes.edit', compact('printOrder'));
    }

    /**
     * Update actual outcomes for the print order lines by diff calculation to executions.
     */
    public function updateOutcome(Request $request, \App\Models\LostWaxPrintOrder $printOrder)
    {
        $this->authorizePrintOrder($printOrder);
        if (! in_array($printOrder->status, ['ISSUED', 'PARTIALLY_COMPLETED'])) {
            return redirect()->route('lost-wax.outcomes.index')
                ->with('error', 'Hasil cetak hanya dapat dicatat untuk dokumen berstatus ISSUED atau PARTIALLY_COMPLETED.');
        }

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:lost_wax_print_order_lines,id',
            'items.*.qty_actual_good' => 'required|integer|min:0',
            'items.*.qty_actual_defect' => 'required|integer|min:0',
            'items.*.standard_tree_capacity' => 'required|integer|min:1',
        ]);

        $service = app(\App\Services\PrintExecutionService::class);

        try {
            DB::transaction(function () use ($request, $printOrder, $service) {
                foreach ($request->items as $itemData) {
                    $line = $printOrder->lines()->lockForUpdate()->findOrFail($itemData['id']);

                    $newGood = (int) $itemData['qty_actual_good'];
                    $newDefect = (int) $itemData['qty_actual_defect'];

                    $oldGood = (int) ($line->qty_executed_good ?? 0);
                    $oldDefect = (int) ($line->qty_executed_defect ?? 0);

                    // Update standard capacity
                    $line->standard_tree_capacity = $itemData['standard_tree_capacity'];
                    $line->save();

                    // Calculate diff
                    $diffGood = $newGood - $oldGood;
                    $diffDefect = $newDefect - $oldDefect;

                    if ($diffGood < 0 || $diffDefect < 0) {
                        $this->adjustExecutionsToMatch($line, $newGood, $newDefect);
                    } elseif ($diffGood > 0 || $diffDefect > 0) {
                        // Add execution record for the positive diff
                        $service->record($line, [
                            'qty_good' => $diffGood,
                            'qty_defect' => $diffDefect,
                            'execution_date' => now()->format('Y-m-d'),
                            'status' => 'FINALIZED',
                        ]);
                    } else {
                        // No changes to quantities, just trigger update to verify aggregates
                        $service->updateLineAggregates($line);
                    }
                }
            });

            return redirect()->route('lost-wax.outcomes.index')
                ->with('success', 'Actual Hasil Cetak berhasil disimpan.');
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Record a new execution for a specific line (micro-interaction).
     */
    public function recordExecution(Request $request, \App\Models\LostWaxPrintOrderLine $line)
    {
        $this->authorizePrintOrder($line->printOrder);

        $request->validate([
            'qty_good' => 'required|integer|min:0',
            'qty_defect' => 'required|integer|min:0',
            'execution_date' => 'required|date|before_or_equal:today',
            'status' => 'required|in:DRAFT,FINALIZED',
            'notes' => 'nullable|string',
        ]);

        try {
            $service = app(\App\Services\PrintExecutionService::class);
            $service->record($line, $request->only(['qty_good', 'qty_defect', 'execution_date', 'status', 'notes']));

            return response()->json([
                'success' => true,
                'message' => 'Execution berhasil dicatat.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Finalize a draft execution.
     */
    public function finalizeExecution(\App\Models\LostWaxPrintExecution $execution)
    {
        $this->authorizePrintOrder($execution->printOrderLine->printOrder);

        try {
            $service = app(\App\Services\PrintExecutionService::class);
            $service->finalize($execution);

            return response()->json([
                'success' => true,
                'message' => 'Execution berhasil difinalisasi.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Update a draft execution.
     */
    public function updateExecution(Request $request, \App\Models\LostWaxPrintExecution $execution)
    {
        $this->authorizePrintOrder($execution->printOrderLine->printOrder);

        $request->validate([
            'qty_good' => 'required|integer|min:0',
            'qty_defect' => 'required|integer|min:0',
            'execution_date' => 'required|date|before_or_equal:today',
            'notes' => 'nullable|string',
        ]);

        try {
            $service = app(\App\Services\PrintExecutionService::class);
            $service->update($execution, $request->only(['qty_good', 'qty_defect', 'execution_date', 'notes']));

            return response()->json([
                'success' => true,
                'message' => 'Execution draft berhasil diperbarui.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    protected function adjustExecutionsToMatch(\App\Models\LostWaxPrintOrderLine $line, int $targetGood, int $targetDefect)
    {
        $allocatedTreeQty = (int) $line->trees()->sum('quantity');
        if ($targetGood < $allocatedTreeQty) {
            throw new \InvalidArgumentException("Hasil tidak boleh kurang dari quantity tree yang sudah dibuat ({$allocatedTreeQty} pcs) untuk item {$line->item_name}.");
        }

        $totalGood = $line->executions()->sum('qty_good');
        $totalDefect = $line->executions()->sum('qty_defect');

        $lastExec = $line->executions()->orderBy('id', 'desc')->first();

        if (! $lastExec) {
            $lastExec = $line->executions()->create([
                'execution_date' => now()->format('Y-m-d'),
                'qty_good' => $targetGood,
                'qty_defect' => $targetDefect,
                'status' => 'FINALIZED',
                'recorded_by' => auth()->id() ?? 1,
                'recorded_at' => now(),
                'finalized_by' => auth()->id() ?? 1,
                'finalized_at' => now(),
            ]);
        } else {
            $diffGood = $targetGood - ($totalGood - $lastExec->qty_good);
            $diffDefect = $targetDefect - ($totalDefect - $lastExec->qty_defect);

            $newGood = max(0, $diffGood);
            $newDefect = max(0, $diffDefect);

            \App\Models\LostWaxPrintExecutionCorrection::create([
                'print_execution_id' => $lastExec->id,
                'original_qty_good' => $lastExec->qty_good,
                'original_qty_defect' => $lastExec->qty_defect,
                'corrected_qty_good' => $newGood,
                'corrected_qty_defect' => $newDefect,
                'corrected_by' => auth()->id() ?? 1,
                'corrected_at' => now(),
                'reason' => 'Adjustment via outcome form submission',
            ]);

            $lastExec->update([
                'qty_good' => $newGood,
                'qty_defect' => $newDefect,
            ]);
        }

        app(\App\Services\PrintExecutionService::class)->updateLineAggregates($line);
    }

    protected function authorizePrintOrder(\App\Models\LostWaxPrintOrder $printOrder)
    {
        $scope = auth()->user()->product_scope;
        if (auth()->user()->hasRole('ppic') && $scope) {
            $unauthorized = $printOrder->lines()->whereHas('productionPlan', function ($q) use ($scope) {
                $q->where('product_scope', '!=', $scope);
            })->exists();

            if ($unauthorized) {
                abort(403, 'Unauthorized.');
            }
        }
    }
}
