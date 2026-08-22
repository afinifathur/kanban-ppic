<?php

namespace App\Services;

use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxRangkaiExecution;
use App\Models\LostWaxRangkaiWorkOrder;
use App\Models\LostWaxTree;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RangkaiExecutionService
{
    /**
     * Create a new Rangkai Work Order.
     */
    public function createWorkOrder(LostWaxPrintOrderLine $line, array $data): LostWaxRangkaiWorkOrder
    {
        return DB::transaction(function () use ($line, $data) {
            // Check concurrency / available
            $available = $line->qty_available_for_rangkai;
            $treeCapacity = (int) ($data['tree_capacity'] ?? 20);
            $qtyTreesPlanned = (int) ($data['qty_trees_planned'] ?? 1);
            $requestedQty = $qtyTreesPlanned * $treeCapacity;

            if ($requestedQty > $available) {
                throw new \InvalidArgumentException("Total rencana rangkai ({$requestedQty} pcs) tidak boleh melebihi hasil cetak tersedia ({$available} pcs).");
            }

            // Generate unique order number: RWO-YYYYMMDD-XXXX
            $datePrefix = 'RWO-'.date('Ymd');
            $lastOrder = LostWaxRangkaiWorkOrder::where('rangkai_order_number', 'like', $datePrefix.'%')
                ->orderBy('rangkai_order_number', 'desc')
                ->first();

            if ($lastOrder) {
                $lastNum = (int) substr($lastOrder->rangkai_order_number, -4);
                $nextNum = str_pad((string) ($lastNum + 1), 4, '0', STR_PAD_LEFT);
            } else {
                $nextNum = '0001';
            }
            $orderNumber = $datePrefix.'-'.$nextNum;

            return LostWaxRangkaiWorkOrder::create([
                'rangkai_order_number' => $orderNumber,
                'lost_wax_print_order_line_id' => $line->id,
                'qty_trees_planned' => $qtyTreesPlanned,
                'tree_capacity' => $treeCapacity,
                'require_layer_7' => (bool) ($data['require_layer_7'] ?? false),
                'status' => 'OPEN',
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id() ?? 1,
            ]);
        });
    }

    /**
     * Record a new Rangkai Execution and create physical LostWaxTrees.
     */
    public function recordExecution(LostWaxRangkaiWorkOrder $workOrder, array $data): LostWaxRangkaiExecution
    {
        return DB::transaction(function () use ($workOrder, $data) {
            // 1. Lock the Rangkai Work Order to prevent concurrent allocation races
            $lockedWo = LostWaxRangkaiWorkOrder::lockForUpdate()->findOrFail($workOrder->id);
            $line = LostWaxPrintOrderLine::lockForUpdate()->findOrFail($lockedWo->lost_wax_print_order_line_id);

            $treesCreated = (int) $data['trees_created'];
            $quantities = $data['quantities'] ?? [];
            $requestedQty = array_sum(array_map('intval', $quantities));

            // Verify outstanding constraints
            $outstanding = $lockedWo->qty_outstanding;
            if ($requestedQty > $outstanding) {
                throw new \InvalidArgumentException("Total kuantitas rangkai ({$requestedQty} pcs) melebihi sisa outstanding work order ({$outstanding} pcs).");
            }

            // Verify print execution good availability constraints
            $available = $line->qty_available_for_rangkai;
            if ($requestedQty > $available) {
                throw new \InvalidArgumentException("Total kuantitas rangkai ({$requestedQty} pcs) melebihi hasil cetak tersedia ({$available} pcs).");
            }

            $familyCode = $data['family_code'];
            $executionDate = Carbon::parse($data['execution_date']);
            $recordedBy = $data['recorded_by'] ?? auth()->id() ?? 1;

            // Create execution record
            $execution = LostWaxRangkaiExecution::create([
                'rangkai_work_order_id' => $lockedWo->id,
                'execution_date' => $executionDate->format('Y-m-d'),
                'trees_created' => $treesCreated,
                'family_code' => $familyCode,
                'recorded_by' => $recordedBy,
                'recorded_at' => now(),
            ]);

            // 2. Create physical LostWaxTree units inside transaction
            $productionDate = Carbon::now(config('app.timezone'));
            $maxRetries = 5;

            for ($retry = 0; $retry < $maxRetries; $retry++) {
                // Get starting daily sequence for this family
                $startingSeq = (int) (LostWaxTree::where('family_code', $familyCode)
                    ->whereDate('production_date', $productionDate->format('Y-m-d'))
                    ->max('daily_sequence') ?? 0);

                $maxSeq = $startingSeq;
                $currentTreeCount = LostWaxTree::where('lost_wax_print_order_line_id', $line->id)->count();

                try {
                    DB::transaction(function () use ($execution, $line, $quantities, &$maxSeq, &$currentTreeCount, $productionDate, $familyCode, $lockedWo) {
                        foreach ($quantities as $qty) {
                            $maxSeq++;
                            $currentTreeCount++;

                            $barcode = $familyCode
                                .$productionDate->format('dmy')
                                .str_pad((string) $maxSeq, 3, '0', STR_PAD_LEFT);

                            LostWaxTree::create([
                                'work_order_id' => null,
                                'work_order_plan_id' => null,
                                'lost_wax_print_order_line_id' => $line->id,
                                'rangkai_execution_id' => $execution->id,
                                'require_layer_7' => $lockedWo->require_layer_7,
                                'barcode' => $barcode,
                                'tree_number' => $currentTreeCount,
                                'quantity' => (int) $qty,
                                'status' => 'generated',
                                'production_date' => $productionDate->format('Y-m-d'),
                                'family_code' => $familyCode,
                                'daily_sequence' => $maxSeq,
                            ]);
                        }
                    }, 5);

                    break; // Success, escape retry loop
                } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                    if ($retry === $maxRetries - 1) {
                        throw $e;
                    }
                }
            }

            // 3. Recalculate Work Order Status
            $lockedWo->refresh();
            $newOutstanding = $lockedWo->qty_outstanding;
            if ($newOutstanding === 0) {
                $lockedWo->status = 'COMPLETED';
            } else {
                $lockedWo->status = 'IN_PROGRESS';
            }
            $lockedWo->save();

            return $execution;
        });
    }
}
