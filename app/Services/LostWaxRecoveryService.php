<?php

namespace App\Services;

use App\Models\LostWaxPrintOrder;
use App\Models\ProductionPlan;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LostWaxRecoveryService
{
    /**
     * Create a new Reprint SPK associated with a ProductionPlan.
     */
    public function createReprint(
        int|ProductionPlan $plan,
        int $quantity,
        ?string $reason = null,
        ?int $userId = null,
        ?string $scheduledDate = null
    ): LostWaxPrintOrder {
        $planId = $plan instanceof ProductionPlan ? $plan->id : $plan;

        if ($quantity <= 0) {
            throw new InvalidArgumentException('Kuantitas cetak ulang harus lebih besar dari 0.');
        }

        return DB::transaction(function () use ($planId, $quantity, $reason, $userId, $scheduledDate) {
            $planModel = ProductionPlan::lockForUpdate()->findOrFail($planId);

            if ($planModel->is_closed) {
                throw new DomainException("Rencana produksi {$planModel->code} sudah ditutup (CLOSED).");
            }

            // Check if there is already an active unfinalized reprint SPK for this plan
            $hasActiveReprint = $planModel->printOrderLines()
                ->whereHas('printOrder', function ($q) {
                    $q->where('order_type', 'REPRINT')
                        ->whereIn('status', ['DRAFT', 'ISSUED']);
                })->exists();

            if ($hasActiveReprint) {
                throw new DomainException("Sudah ada SPK Cetak Ulang aktif (DRAFT/ISSUED) untuk rencana {$planModel->code}. Selesaikan atau batalkan SPK tersebut terlebih dahulu.");
            }

            // Calculate next reprint cycle for this plan inside locked transaction
            $existingReprintCount = $planModel->printOrderLines()
                ->whereHas('printOrder', function ($q) {
                    $q->where('order_type', 'REPRINT');
                })->count();

            $nextCycle = $existingReprintCount + 1;

            $date = $scheduledDate ?: date('Y-m-d');
            $printOrderNumber = $this->generateNextPrintOrderNumber($date);

            $order = LostWaxPrintOrder::create([
                'print_order_number' => $printOrderNumber,
                'scheduled_date' => $date,
                'status' => 'DRAFT',
                'order_type' => 'REPRINT',
                'reprint_reason' => $reason,
                'reprint_cycle' => $nextCycle,
                'created_by' => $userId ?? auth()->id() ?? 1,
            ]);

            $order->lines()->create([
                'production_plan_id' => $planModel->id,
                'qty_ordered' => $quantity,
                'code' => $planModel->code,
                'customer' => $planModel->customer,
                'item_name' => $planModel->item_name,
                'size' => $planModel->size,
                'aisi' => $planModel->aisi,
            ]);

            return $order;
        });
    }

    /**
     * Close a ProductionPlan without issuing a reprint, storing audit trail.
     */
    public function closeWithoutReprint(
        int|ProductionPlan $plan,
        string $closureReason,
        ?int $userId = null
    ): ProductionPlan {
        $planId = $plan instanceof ProductionPlan ? $plan->id : $plan;
        $reason = trim($closureReason);

        if ($reason === '') {
            throw new InvalidArgumentException('Alasan penutupan rencana produksi wajib diisi.');
        }

        return DB::transaction(function () use ($planId, $reason, $userId) {
            $planModel = ProductionPlan::lockForUpdate()->findOrFail($planId);

            if ($planModel->is_closed) {
                return $planModel; // Idempotent
            }

            $planModel->update([
                'is_closed' => true,
                'closure_reason' => $reason,
                'closed_by' => $userId ?? auth()->id() ?? 1,
                'closed_at' => Carbon::now(),
            ]);

            return $planModel;
        });
    }

    /**
     * Update PO quantity and optionally PO number for a ProductionPlan.
     */
    public function updatePoQuantity(
        int|ProductionPlan $plan,
        ?int $poQuantity,
        ?string $poNumber = null
    ): ProductionPlan {
        $planId = $plan instanceof ProductionPlan ? $plan->id : $plan;

        if ($poQuantity !== null && $poQuantity < 0) {
            throw new InvalidArgumentException('Kuantitas PO tidak boleh negatif.');
        }

        return DB::transaction(function () use ($planId, $poQuantity, $poNumber) {
            $planModel = ProductionPlan::lockForUpdate()->findOrFail($planId);

            $updates = ['po_quantity' => $poQuantity];
            if ($poNumber !== null) {
                $updates['po_number'] = $poNumber;
            }

            $planModel->update($updates);

            return $planModel;
        });
    }

    /**
     * Generate sequential print order number inside a concurrency-safe lock block.
     */
    public function generateNextPrintOrderNumber(string $date): string
    {
        $dateStr = str_replace('-', '', $date);

        $lastOrder = LostWaxPrintOrder::whereDate('scheduled_date', $date)
            ->lockForUpdate()
            ->orderBy('id', 'desc')
            ->first();

        $sequence = 1;
        if ($lastOrder) {
            $parts = explode('-', $lastOrder->print_order_number);
            if (count($parts) === 3) {
                $sequence = ((int) $parts[2]) + 1;
            }
        }

        return 'PC-'.$dateStr.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
