<?php

namespace App\Services;

use App\Models\LostWaxPrintExecution;
use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use Illuminate\Support\Facades\DB;

class PrintExecutionService
{
    /**
     * Record a new print execution for a print order line.
     */
    public function record(LostWaxPrintOrderLine $line, array $data): LostWaxPrintExecution
    {
        return DB::transaction(function () use ($line, $data) {
            // Lock print order line
            $line->lockForUpdate();

            $qtyDefect = (int) ($data['qty_defect'] ?? 0);

            // 1. Validate negative quantities and calculate Net Good
            if ($qtyDefect < 0) {
                throw new \InvalidArgumentException('Quantity tidak boleh negatif.');
            }

            if (array_key_exists('qty_gross_output', $data) && $data['qty_gross_output'] !== null) {
                $qtyGross = (int) $data['qty_gross_output'];
                if ($qtyGross < 0) {
                    throw new \InvalidArgumentException('Quantity tidak boleh negatif.');
                }
                if ($qtyDefect > $qtyGross) {
                    throw new \InvalidArgumentException("Kuantitas defect ({$qtyDefect} pcs) tidak boleh melebihi hasil cetak gross/counter ({$qtyGross} pcs).");
                }
                $qtyGood = max(0, $qtyGross - $qtyDefect);
            } else {
                $qtyGood = (int) ($data['qty_good'] ?? 0);
                if ($qtyGood < 0) {
                    throw new \InvalidArgumentException('Quantity tidak boleh negatif.');
                }
                $qtyGross = $qtyGood + $qtyDefect;
            }

            $executionDate = $data['execution_date'] ?? now()->format('Y-m-d');
            $status = $data['status'] ?? 'DRAFT';
            $notes = $data['notes'] ?? null;
            $userId = $data['recorded_by'] ?? auth()->id();

            // 2. Validate tree allocation
            // If we add this, will the total good be less than allocated trees?
            // Since this is a new execution, it adds to the total good, so it's always >= current total.
            // But just in case, we will run the check during finalize/update.

            $execution = $line->executions()->create([
                'execution_date' => $executionDate,
                'qty_gross_output' => $qtyGross,
                'qty_good' => $qtyGood,
                'qty_defect' => $qtyDefect,
                'status' => $status,
                'notes' => $notes,
                'recorded_by' => $userId,
                'recorded_at' => now(),
            ]);

            if ($status === 'FINALIZED') {
                $execution->finalized_by = $userId;
                $execution->finalized_at = now();
                $execution->save();
            }

            $this->updateLineAggregates($line);

            return $execution;
        });
    }

    /**
     * Update an existing print execution (only allowed if DRAFT).
     */
    public function update(LostWaxPrintExecution $execution, array $data): LostWaxPrintExecution
    {
        if ($execution->status === 'FINALIZED') {
            throw new \InvalidArgumentException('Execution yang sudah FINALIZED tidak dapat di-edit langsung.');
        }

        return DB::transaction(function () use ($execution, $data) {
            $line = $execution->printOrderLine;
            $line->lockForUpdate();
            $execution->lockForUpdate();

            $qtyDefect = (int) ($data['qty_defect'] ?? $execution->qty_defect);

            if ($qtyDefect < 0) {
                throw new \InvalidArgumentException('Quantity tidak boleh negatif.');
            }

            if (array_key_exists('qty_gross_output', $data) && $data['qty_gross_output'] !== null) {
                $qtyGross = (int) $data['qty_gross_output'];
                if ($qtyGross < 0) {
                    throw new \InvalidArgumentException('Quantity tidak boleh negatif.');
                }
                if ($qtyDefect > $qtyGross) {
                    throw new \InvalidArgumentException("Kuantitas defect ({$qtyDefect} pcs) tidak boleh melebihi hasil cetak gross/counter ({$qtyGross} pcs).");
                }
                $qtyGood = max(0, $qtyGross - $qtyDefect);
            } else {
                $qtyGood = (int) ($data['qty_good'] ?? $execution->qty_good);
                if ($qtyGood < 0) {
                    throw new \InvalidArgumentException('Quantity tidak boleh negatif.');
                }
                $qtyGross = $qtyGood + $qtyDefect;
            }

            $executionDate = $data['execution_date'] ?? $execution->execution_date;
            $status = $data['status'] ?? $execution->status;
            $notes = $data['notes'] ?? $execution->notes;
            $userId = auth()->id();

            // Calculate outstanding without this current execution
            $otherGood = $line->executions()->where('id', '!=', $execution->id)->where('status', 'FINALIZED')->sum('qty_good');
            $otherDefect = $line->executions()->where('id', '!=', $execution->id)->where('status', 'FINALIZED')->sum('qty_defect');

            // Validate trees vs new good total
            $newGoodTotal = $otherGood + ($status === 'FINALIZED' ? $qtyGood : 0);
            $allocatedTreeQty = (int) $line->trees()->where('status', '!=', 'cancelled')->sum('quantity');
            if ($status === 'FINALIZED' && $newGoodTotal < $allocatedTreeQty) {
                throw new \InvalidArgumentException("Total Hasil Good ({$newGoodTotal} pcs) tidak boleh kurang dari quantity tree yang sudah dibuat ({$allocatedTreeQty} pcs) untuk item {$line->item_name}.");
            }

            $execution->update([
                'qty_gross_output' => $qtyGross,
                'qty_good' => $qtyGood,
                'qty_defect' => $qtyDefect,
                'execution_date' => $executionDate,
                'status' => $status,
                'notes' => $notes,
            ]);

            if ($status === 'FINALIZED') {
                $execution->finalized_by = $userId;
                $execution->finalized_at = now();
                $execution->save();
            }

            $this->updateLineAggregates($line);

            return $execution;
        });
    }

    /**
     * Finalize a DRAFT execution.
     */
    public function finalize(LostWaxPrintExecution $execution): LostWaxPrintExecution
    {
        return $this->update($execution, ['status' => 'FINALIZED']);
    }

    /**
     * Recalculate and update aggregates on the Print Order Line.
     */
    public function updateLineAggregates(LostWaxPrintOrderLine $line): void
    {
        // Sum only from FINALIZED print executions
        $goodSum = (int) $line->executions()->where('status', 'FINALIZED')->sum('qty_good');
        $defectSum = (int) $line->executions()->where('status', 'FINALIZED')->sum('qty_defect');

        $line->qty_executed_good = $goodSum;
        $line->qty_executed_defect = $defectSum;

        // Backward compatibility
        $line->qty_actual_good = $goodSum;
        $line->qty_actual_defect = $defectSum;
        $line->actual_recorded_at = now();
        $line->actual_recorded_by = auth()->id() ?? 1;

        // Determine execution_status (without clamping outstanding mathematically to allow overprint to mark it as COMPLETED)
        $outstanding = $line->qty_ordered - $goodSum - $defectSum;
        if ($goodSum === 0 && $defectSum === 0) {
            $line->execution_status = 'PENDING';
        } elseif ($outstanding <= 0) {
            $line->execution_status = 'COMPLETED';
        } else {
            $line->execution_status = 'IN_PROGRESS';
        }

        $line->save();

        // Update parent Print Order status
        $this->checkAndTransitionPrintOrderStatus($line->printOrder);
    }

    /**
     * Check and transition parent Print Order status based on lines status.
     */
    public function checkAndTransitionPrintOrderStatus(LostWaxPrintOrder $order): void
    {
        $order->load('lines');

        $allDone = $order->lines->every(function ($line) {
            return in_array($line->execution_status, ['COMPLETED', 'CLOSED_WITH_EXCEPTION']);
        });

        // Check if there is at least one FINALIZED execution
        $anyExecution = $order->lines->some(function ($line) {
            return $line->executions()->where('status', 'FINALIZED')->exists();
        });

        $oldStatus = $order->status;
        $newStatus = $oldStatus;

        if ($allDone) {
            $newStatus = 'COMPLETED';
        } elseif ($anyExecution) {
            $newStatus = 'PARTIALLY_COMPLETED';
        } else {
            // No executions: if it was DRAFT, ISSUED or CANCELLED, keep it as is (or ISSUED)
            if ($oldStatus === 'PARTIALLY_COMPLETED' || $oldStatus === 'COMPLETED') {
                $newStatus = 'ISSUED';
            }
        }

        if ($newStatus !== $oldStatus) {
            $order->status = $newStatus;
            $order->save();
        }
    }
}
