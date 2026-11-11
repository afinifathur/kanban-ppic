<?php

namespace App\Services\LostWax;

use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ScanSiapCorService
{
    /**
     * Process Scan Siap Cor for a given tree barcode.
     */
    public function processScan(string $barcode, int $operatorId): array
    {
        return DB::transaction(function () use ($barcode, $operatorId) {
            // 1. Acquire pessimistic lock on the tree row
            $tree = LostWaxTree::with([
                'workOrder.itemReference',
                'printOrderLine.printOrder',
                'printOrderLine.productionPlan',
                'defects',
            ])
                ->where('barcode', $barcode)
                ->lockForUpdate()
                ->first();

            // Validation 1: Barcode not found
            if (! $tree) {
                return [
                    'success' => false,
                    'code' => 'NOT_FOUND',
                    'reason' => 'GAGAL — BARCODE TIDAK DITEMUKAN',
                ];
            }

            // Validation 2: Traveler card cancelled
            if ($tree->status === 'cancelled') {
                $reason = 'GAGAL — TRAVELER CARD TELAH DIBATALKAN';
                $this->recordRejection($tree, $operatorId, $reason);

                return [
                    'success' => false,
                    'code' => 'CANCELLED',
                    'reason' => $reason,
                    'tree' => $tree,
                    'tree_info' => $this->treeInfo($tree),
                ];
            }

            // Validation 3: Duplicate scan (already at siap_cor)
            if ($tree->current_stage === 'siap_cor') {
                $reason = 'GAGAL — SUDAH PERNAH SCAN SIAP COR';
                $this->recordRejection($tree, $operatorId, $reason);

                return [
                    'success' => false,
                    'code' => 'ALREADY_SCANNED',
                    'reason' => $reason,
                    'tree' => $tree,
                    'tree_info' => $this->treeInfo($tree),
                ];
            }

            $alreadyScannedEvent = LostWaxScanEvent::where('tree_id', $tree->id)
                ->where('stage', 'siap_cor')
                ->where('result', 'success')
                ->whereDoesntHave('void')
                ->exists();

            if ($alreadyScannedEvent) {
                $reason = 'GAGAL — SUDAH PERNAH SCAN SIAP COR';
                $this->recordRejection($tree, $operatorId, $reason);

                return [
                    'success' => false,
                    'code' => 'ALREADY_SCANNED',
                    'reason' => $reason,
                    'tree' => $tree,
                    'tree_info' => $this->treeInfo($tree),
                ];
            }

            // Validation 4: Must have completed Scan Oven (current_stage === 'oven' and active oven event)
            $hasActiveOven = LostWaxScanEvent::where('tree_id', $tree->id)
                ->where('stage', 'oven')
                ->where('result', 'success')
                ->whereDoesntHave('void')
                ->exists();

            if ($tree->current_stage !== 'oven' || ! $hasActiveOven) {
                $reason = 'GAGAL — BELUM SCAN SIAP OVEN';
                $this->recordRejection($tree, $operatorId, $reason);

                return [
                    'success' => false,
                    'code' => 'NOT_OVEN_READY',
                    'reason' => $reason,
                    'tree' => $tree,
                    'tree_info' => $this->treeInfo($tree),
                ];
            }

            // Validation 5: Usable quantity must be greater than zero
            if ($tree->usable_quantity <= 0) {
                $reason = 'GAGAL — 0 PCS LAYAK';
                $this->recordRejection($tree, $operatorId, $reason);

                return [
                    'success' => false,
                    'code' => 'ZERO_USABLE',
                    'reason' => $reason,
                    'tree' => $tree,
                    'tree_info' => $this->treeInfo($tree),
                ];
            }

            // 6. Execute atomic transition to siap_cor
            $scannedAt = Carbon::now(config('app.timezone'));

            $event = LostWaxScanEvent::create([
                'tree_id' => $tree->id,
                'barcode' => $barcode,
                'stage' => 'siap_cor',
                'scanned_at' => $scannedAt,
                'operator_id' => $operatorId,
                'result' => 'success',
            ]);

            $tree->update([
                'current_stage' => 'siap_cor',
                'last_scan_at' => $scannedAt,
            ]);

            // Reload relationships for updated presentation
            $tree->load([
                'workOrder.itemReference',
                'printOrderLine.printOrder',
                'printOrderLine.productionPlan',
                'defects',
            ]);

            return [
                'success' => true,
                'code' => 'SUCCESS',
                'message' => 'BERHASIL — SIAP COR',
                'tree' => $tree,
                'event' => $event,
                'tree_info' => $this->treeInfo($tree),
            ];
        });
    }

    /**
     * Record rejected scan event for audit trail without changing tree state.
     */
    protected function recordRejection(LostWaxTree $tree, int $operatorId, string $reason): void
    {
        LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'siap_cor',
            'scanned_at' => Carbon::now(config('app.timezone')),
            'operator_id' => $operatorId,
            'result' => 'rejected',
            'anomaly_reason' => $reason,
        ]);
    }

    /**
     * Format tree information for scanner operator presentation.
     */
    public function treeInfo(LostWaxTree $tree): array
    {
        $plan = $tree->printOrderLine?->productionPlan;
        $workOrder = $tree->workOrder;

        $code = $tree->getSourceCode() ?? '-';
        $productName = $tree->getSourceProduct() ?? '-';
        $customer = $tree->getSourceCustomer() ?? '-';
        $aisi = $tree->getSourceAisi() ?? ($plan?->aisi ?? '-');

        return [
            'id' => $tree->id,
            'barcode' => $tree->barcode,
            'tree_number' => $tree->tree_number,
            'quantity_gross' => (int) $tree->quantity,
            'quantity_usable' => (int) $tree->usable_quantity,
            'total_defects' => (int) $tree->total_defect_quantity,
            'code' => $code,
            'product_name' => $productName,
            'customer' => $customer,
            'aisi' => $aisi,
            'current_stage' => $tree->current_stage,
            'current_stage_label' => $tree->current_stage_label,
            'last_scan_at' => $tree->last_scan_at?->format('H:i:s'),
            'scanned_date' => $tree->last_scan_at?->format('d/m/Y H:i:s'),
        ];
    }
}
