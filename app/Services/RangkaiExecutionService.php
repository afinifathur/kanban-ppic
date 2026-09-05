<?php

namespace App\Services;

use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxRangkaiExecution;
use App\Models\LostWaxRangkaiWorkOrder;
use App\Models\LostWaxTree;
use App\Models\LostWaxTreeAllocation;
use App\Models\ProductionPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RangkaiExecutionService
{
    /**
     * Get available Print Order Lines for a given Production Code sorted FIFO.
     */
    public function getAvailableLinesByProductionCode(string $code, bool $lock = false): Collection
    {
        $query = LostWaxPrintOrderLine::where('code', $code)
            ->where('execution_status', '!=', 'CANCELLED')
            ->with(['executions', 'treeAllocations.tree', 'trees']);

        if ($lock) {
            $query->lockForUpdate();
        }

        $lines = $query->get();

        // Sort FIFO: Earliest finalized print execution -> actual_recorded_at -> created_at -> id ASC
        return $lines->sort(function ($a, $b) {
            $timeA = $a->executions->where('status', 'FINALIZED')->min('finalized_at')
                ?? $a->actual_recorded_at
                ?? $a->created_at;
            $timeB = $b->executions->where('status', 'FINALIZED')->min('finalized_at')
                ?? $b->actual_recorded_at
                ?? $b->created_at;

            if ($timeA == $timeB) {
                return $a->id <=> $b->id;
            }

            return $timeA <=> $timeB;
        })->values();
    }

    /**
     * Calculate total available pool for a Production Code.
     */
    public function getTotalAvailablePool(string $code, bool $lock = false): int
    {
        $lines = $this->getAvailableLinesByProductionCode($code, $lock);

        return (int) $lines->sum(fn ($l) => $l->qty_available_for_rangkai);
    }

    /**
     * Create a new Rangkai Work Order.
     */
    public function createWorkOrder(LostWaxPrintOrderLine $line, array $data): LostWaxRangkaiWorkOrder
    {
        return DB::transaction(function () use ($line, $data) {
            // Lock production plan and relevant pool lines
            if ($line->production_plan_id) {
                ProductionPlan::where('id', $line->production_plan_id)->lockForUpdate()->first();
            }

            $productionCode = $line->code;
            $availablePool = $this->getTotalAvailablePool($productionCode, true);
            $treeCapacity = (int) ($data['tree_capacity'] ?? 20);
            $qtyTreesPlanned = (int) ($data['qty_trees_planned'] ?? 1);
            $requestedQty = $treeCapacity === 1 ? $qtyTreesPlanned : ($qtyTreesPlanned * $treeCapacity);

            if ($requestedQty > $availablePool) {
                throw new \InvalidArgumentException("Total rencana rangkai ({$requestedQty} pcs) melebihi hasil cetak tersedia untuk Kode {$productionCode} ({$availablePool} pcs).");
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
                'standard_capacity_guide' => $data['standard_capacity_guide'] ?? null,
                'require_layer_7' => (bool) ($data['require_layer_7'] ?? false),
                'status' => 'OPEN',
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id() ?? 1,
            ]);
        });
    }

    /**
     * Record a new Rangkai Execution and create physical LostWaxTrees with FIFO allocation ledger.
     */
    public function recordExecution(LostWaxRangkaiWorkOrder $workOrder, array $data): LostWaxRangkaiExecution
    {
        return DB::transaction(function () use ($workOrder, $data) {
            // 1. Lock the Rangkai Work Order and its main line
            $lockedWo = LostWaxRangkaiWorkOrder::lockForUpdate()->findOrFail($workOrder->id);
            $mainLine = LostWaxPrintOrderLine::lockForUpdate()->findOrFail($lockedWo->lost_wax_print_order_line_id);

            // 2. Lock Production Plan boundary
            if ($mainLine->production_plan_id) {
                ProductionPlan::where('id', $mainLine->production_plan_id)->lockForUpdate()->first();
            }

            // 3. Lock all lines belonging to the same Production Code pool
            $productionCode = $mainLine->code;
            $fifoLines = $this->getAvailableLinesByProductionCode($productionCode, true);

            $treesCreated = (int) $data['trees_created'];
            $quantities = array_map('intval', $data['quantities'] ?? []);
            $requestedQty = array_sum($quantities);

            if ($requestedQty <= 0) {
                throw new \InvalidArgumentException('Total kuantitas rangkai harus lebih dari 0.');
            }

            $outstanding = $lockedWo->qty_outstanding;
            if ($outstanding <= 0 || in_array($lockedWo->status, ['COMPLETED', 'CLOSED_WITH_SHORTAGE'])) {
                throw new \InvalidArgumentException('Work Order ini sudah selesai atau tidak memiliki sisa outstanding.');
            }

            // Total available across the Production Code pool
            $totalAvailable = (int) $fifoLines->sum(fn ($l) => $l->qty_available_for_rangkai);
            $availableForWo = min($outstanding, $totalAvailable);

            // Additional Physical Source Handling
            $additionalSourceLineId = $data['additional_source_line_id'] ?? null;
            $additionalSourceQty = (int) ($data['additional_source_qty'] ?? 0);
            $additionalSourceReason = isset($data['additional_source_reason']) ? trim((string) $data['additional_source_reason']) : null;

            $additionalLine = null;
            $additionalSourceCode = null;
            $additionalLineBalance = 0;

            if ($requestedQty > $availableForWo) {
                $diff = $requestedQty - $availableForWo;

                if (! $additionalSourceLineId || $additionalSourceQty <= 0) {
                    throw new \InvalidArgumentException("Total kuantitas rangkai ({$requestedQty} pcs) melebihi ketersediaan ({$availableForWo} pcs) sebesar {$diff} pcs. Harap cantumkan Sumber Lilin Tambahan.");
                }

                if ($additionalSourceQty !== $diff) {
                    throw new \InvalidArgumentException("Kuantitas sumber tambahan ({$additionalSourceQty} pcs) harus sama persis dengan selisih kuantitas ({$diff} pcs).");
                }

                if (empty($additionalSourceReason)) {
                    throw new \InvalidArgumentException('Alasan penambahan sumber lilin fisik wajib diisi.');
                }

                $additionalLine = LostWaxPrintOrderLine::lockForUpdate()->findOrFail($additionalSourceLineId);

                // If additional line is one of the fifoLines, ensure its balance is evaluated correctly
                $availInAdditional = $additionalLine->qty_available_for_rangkai;
                if ($availInAdditional < $additionalSourceQty) {
                    throw new \InvalidArgumentException("Kuantitas lilin tersedia pada sumber tambahan Kode {$additionalLine->code} ({$availInAdditional} pcs) tidak mencukupi untuk tambahan {$additionalSourceQty} pcs.");
                }

                $additionalSourceCode = $additionalLine->code;
                $additionalLineBalance = $additionalSourceQty;
            } else {
                if ($additionalSourceQty > 0) {
                    throw new \InvalidArgumentException("Sumber tambahan tidak diperlukan karena kuantitas rangkai ({$requestedQty} pcs) tidak melebihi ketersediaan ({$availableForWo} pcs).");
                }
                $additionalSourceReason = null;
            }

            $familyCode = $data['family_code'] ?? $mainLine->code ?? 'TREE';
            $executionDate = Carbon::parse($data['execution_date']);
            $recordedBy = $data['recorded_by'] ?? auth()->id() ?? 1;

            // Create execution record with explicit additional source traceability
            $execution = LostWaxRangkaiExecution::create([
                'rangkai_work_order_id' => $lockedWo->id,
                'execution_date' => $executionDate->format('Y-m-d'),
                'trees_created' => $treesCreated,
                'family_code' => $familyCode,
                'additional_source_line_id' => $additionalLine?->id,
                'additional_source_code' => $additionalSourceCode,
                'additional_source_qty' => $additionalSourceQty,
                'additional_source_reason' => $additionalSourceReason,
                'variance_qty' => 0,
                'is_anomaly' => false,
                'anomaly_notes' => null,
                'recorded_by' => $recordedBy,
                'recorded_at' => now(),
            ]);

            // Track line available balances for FIFO allocation deduction
            $lineBalances = [];
            foreach ($fifoLines as $fLine) {
                $lineBalances[$fLine->id] = $fLine->qty_available_for_rangkai;
            }

            // 4. Create physical LostWaxTree units inside transaction with sequence retries
            $productionDate = Carbon::now(config('app.timezone'));
            $maxRetries = 5;

            for ($retry = 0; $retry < $maxRetries; $retry++) {
                $startingSeq = (int) (LostWaxTree::where('family_code', $familyCode)
                    ->whereDate('production_date', $productionDate->format('Y-m-d'))
                    ->max('daily_sequence') ?? 0);

                $maxSeq = $startingSeq;
                $currentTreeCount = LostWaxTree::where('lost_wax_print_order_line_id', $mainLine->id)->count();

                try {
                    DB::transaction(function () use ($execution, $mainLine, $fifoLines, &$lineBalances, $quantities, &$maxSeq, &$currentTreeCount, $productionDate, $familyCode, $lockedWo, $additionalLine, &$additionalLineBalance) {
                        foreach ($quantities as $qty) {
                            $maxSeq++;
                            $currentTreeCount++;

                            $barcode = $familyCode
                                .$productionDate->format('dmy')
                                .str_pad((string) $maxSeq, 3, '0', STR_PAD_LEFT);

                            $tree = LostWaxTree::create([
                                'work_order_id' => null,
                                'work_order_plan_id' => null,
                                'lost_wax_print_order_line_id' => $mainLine->id,
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

                            // Deduct FIFO from primary available pool lines
                            $neededForTree = (int) $qty;

                            foreach ($fifoLines as $fLine) {
                                if ($neededForTree <= 0) {
                                    break;
                                }

                                $availInLine = $lineBalances[$fLine->id] ?? 0;
                                if ($availInLine <= 0) {
                                    continue;
                                }

                                $take = min($neededForTree, $availInLine);
                                if ($take > 0) {
                                    LostWaxTreeAllocation::create([
                                        'lost_wax_tree_id' => $tree->id,
                                        'lost_wax_print_order_line_id' => $fLine->id,
                                        'allocated_qty' => $take,
                                    ]);

                                    $lineBalances[$fLine->id] -= $take;
                                    $neededForTree -= $take;
                                }
                            }

                            // If tree still needs physical wax and additional source line was provided
                            if ($neededForTree > 0 && $additionalLine && $additionalLineBalance > 0) {
                                $take = min($neededForTree, $additionalLineBalance);
                                if ($take > 0) {
                                    LostWaxTreeAllocation::create([
                                        'lost_wax_tree_id' => $tree->id,
                                        'lost_wax_print_order_line_id' => $additionalLine->id,
                                        'allocated_qty' => $take,
                                    ]);

                                    $additionalLineBalance -= $take;
                                    $neededForTree -= $take;
                                }
                            }
                        }
                    }, 5);

                    break; // Success, escape retry loop
                } catch (UniqueConstraintViolationException $e) {
                    if ($retry === $maxRetries - 1) {
                        throw $e;
                    }
                }
            }

            // 5. Recalculate Work Order Status
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

    /**
     * Cancel an existing Rangkai Execution and its associated physical trees before Layer 1 scan.
     */
    public function cancelExecution(LostWaxRangkaiExecution $execution, string $reason, ?User $user = null): LostWaxRangkaiExecution
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Alasan pembatalan wajib diisi.');
        }

        return DB::transaction(function () use ($execution, $reason, $user) {
            $lockedExecution = LostWaxRangkaiExecution::with('trees.scanEvents')->lockForUpdate()->findOrFail($execution->id);
            $lockedWo = LostWaxRangkaiWorkOrder::lockForUpdate()->findOrFail($lockedExecution->rangkai_work_order_id);

            // Guard 1: Cannot cancel if already cancelled
            if ($lockedExecution->status === 'CANCELLED') {
                throw new \InvalidArgumentException('Traveler ini sudah dalam status dibatalkan.');
            }

            // Guard 2: Strict Scan Boundary Check (Cannot cancel if ANY tree has been scanned)
            $scannedTrees = $lockedExecution->trees->filter(function ($tree) {
                return $tree->current_stage !== null || $tree->scanEvents()->where('result', 'success')->exists();
            });

            if ($scannedTrees->isNotEmpty()) {
                throw new \InvalidArgumentException('Traveler tidak dapat dibatalkan karena satu atau lebih rangkaian (tree) sudah melalui Scan Layer 1.');
            }

            // 1. Mark execution as CANCELLED
            $lockedExecution->update([
                'status' => 'CANCELLED',
                'cancelled_at' => now(),
                'cancelled_by' => $user?->id ?? auth()->id() ?? 1,
                'cancellation_reason' => $reason,
            ]);

            // 2. Mark all linked trees as cancelled
            $treeIds = $lockedExecution->trees->pluck('id')->toArray();
            foreach ($lockedExecution->trees as $tree) {
                $tree->update([
                    'status' => 'cancelled',
                ]);
            }

            // 3. Delete allocation ledger rows to release FIFO balances back to source lines (including additional source lines)
            LostWaxTreeAllocation::whereIn('lost_wax_tree_id', $treeIds)->delete();

            // 4. Recalculate Work Order Status
            $lockedWo->load('executions.trees');
            $newOutstanding = $lockedWo->qty_outstanding;
            $executedPcs = $lockedWo->qty_executed_pcs;

            if ($executedPcs === 0) {
                $lockedWo->status = 'OPEN';
            } elseif ($newOutstanding === 0) {
                $lockedWo->status = 'COMPLETED';
            } else {
                $lockedWo->status = 'IN_PROGRESS';
            }
            $lockedWo->save();

            return $lockedExecution;
        });
    }

    /**
     * Close a Rangkai Work Order with shortage.
     */
    public function closeWorkOrderWithShortage(LostWaxRangkaiWorkOrder $workOrder, string $reason, ?User $user = null): LostWaxRangkaiWorkOrder
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Alasan penutupan shortage wajib diisi.');
        }

        return DB::transaction(function () use ($workOrder, $reason, $user) {
            $lockedWo = LostWaxRangkaiWorkOrder::lockForUpdate()->findOrFail($workOrder->id);

            $lockedWo->update([
                'status' => 'CLOSED_WITH_SHORTAGE',
                'closure_reason' => $reason,
                'closed_by' => $user?->id ?? auth()->id() ?? 1,
                'closed_at' => now(),
            ]);

            return $lockedWo;
        });
    }

    /**
     * Close excess balance on a Print Order Line (Afkir Sisa Lilin).
     */
    public function closeExcessBalance(LostWaxPrintOrderLine $line, int $qtyToClose, ?string $reason = null, ?User $user = null): LostWaxPrintOrderLine
    {
        if ($qtyToClose <= 0) {
            throw new \InvalidArgumentException('Kuantitas excess yang ditutup harus lebih dari 0.');
        }

        return DB::transaction(function () use ($line, $qtyToClose, $reason, $user) {
            $lockedLine = LostWaxPrintOrderLine::lockForUpdate()->findOrFail($line->id);
            $available = $lockedLine->qty_available_for_rangkai;

            if ($qtyToClose > $available) {
                throw new \InvalidArgumentException("Kuantitas excess ({$qtyToClose} pcs) melebihi saldo tersedia ({$available} pcs).");
            }

            $lockedLine->qty_excess_closed = ($lockedLine->qty_excess_closed ?? 0) + $qtyToClose;
            $lockedLine->excess_closure_reason = $reason ? trim($reason) : ($lockedLine->excess_closure_reason ?? 'Afkir / closed sisa lilin');
            $lockedLine->excess_closed_by = $user?->id ?? auth()->id() ?? 1;
            $lockedLine->excess_closed_at = now();
            $lockedLine->save();

            return $lockedLine;
        });
    }
}
