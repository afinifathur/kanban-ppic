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

            $qtyGood = (int) $data['qty_good'];
            $qtyDefect = (int) $data['qty_defect'];
            $executionDate = $data['execution_date'] ?? now()->format('Y-m-d');
            $status = $data['status'] ?? 'DRAFT';
            $notes = $data['notes'] ?? null;
            $userId = $data['recorded_by'] ?? auth()->id();

            // 1. Calculate current outstanding (excluding any DRAFT execution being updated if we're editing)
            // But this function is for RECORDing (creating a new one).
            $currentOutstanding = $line->qty_outstanding;

            $newTotal = $qtyGood + $qtyDefect;
            if ($newTotal > $currentOutstanding) {
                throw new \InvalidArgumentException("Total Hasil ({$qtyGood} pcs) + Rusak ({$qtyDefect} pcs) tidak boleh melebihi sisa outstanding ({$currentOutstanding} pcs) untuk item {$line->item_name}.");
            }

            // 2. Validate tree allocation
            // If we add this, will the total good be less than allocated trees?
            // Since this is a new execution, it adds to the total good, so it's always >= current total.
            // But just in case, we will run the check during finalize/update.

            $execution = $line->executions()->create([
                'execution_date' => $executionDate,
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

            $qtyGood = (int) ($data['qty_good'] ?? $execution->qty_good);
            $qtyDefect = (int) ($data['qty_defect'] ?? $execution->qty_defect);
            $executionDate = $data['execution_date'] ?? $execution->execution_date;
            $status = $data['status'] ?? $execution->status;
            $notes = $data['notes'] ?? $execution->notes;
            $userId = auth()->id();

            // Calculate outstanding without this current execution
            $otherGood = $line->executions()->where('id', '!=', $execution->id)->sum('qty_good');
            $otherDefect = $line->executions()->where('id', '!=', $execution->id)->sum('qty_defect');
            $currentOutstanding = max(0, $line->qty_ordered - $otherGood - $otherDefect);

            $newTotal = $qtyGood + $qtyDefect;
            if ($newTotal > $currentOutstanding) {
                throw new \InvalidArgumentException("Total Hasil ({$qtyGood} pcs) + Rusak ({$qtyDefect} pcs) tidak boleh melebihi sisa outstanding ({$currentOutstanding} pcs) untuk item {$line->item_name}.");
            }

            // Validate trees vs new good total
            $newGoodTotal = $otherGood + $qtyGood;
            $allocatedTreeQty = (int) $line->trees()->sum('quantity');
            if ($newGoodTotal < $allocatedTreeQty) {
                throw new \InvalidArgumentException("Total Hasil Good ({$newGoodTotal} pcs) tidak boleh kurang dari quantity tree yang sudah dibuat ({$allocatedTreeQty} pcs) untuk item {$line->item_name}.");
            }

            $execution->update([
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

        // Determine execution_status
        $outstanding = max(0, $line->qty_ordered - $goodSum - $defectSum);
        if ($goodSum === 0 && $defectSum === 0) {
            $line->execution_status = 'PENDING';
        } elseif ($outstanding === 0) {
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
