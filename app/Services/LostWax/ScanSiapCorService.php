<?php

namespace App\Services\LostWax;

use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ScanSiapCorService
{
    /**
     * Preview tree identity and validation for confirmation popup without changing database.
     */
    public function previewScan(string $barcode, ?User $user = null): array
    {
        $barcode = trim($barcode);

        if ($barcode === '') {
            return [
                'success' => false,
                'code' => 'EMPTY_BARCODE',
                'reason' => 'GAGAL — BARCODE TIDAK BOLEH KOSONG',
            ];
        }

        $tree = LostWaxTree::with([
            'workOrder.itemReference',
            'printOrderLine.printOrder',
            'printOrderLine.productionPlan',
            'allocations.printOrderLine.productionPlan',
            'defects',
            'coatingRack',
        ])
            ->where('barcode', $barcode)
            ->first();

        if (! $tree) {
            return [
                'success' => false,
                'code' => 'NOT_FOUND',
                'reason' => 'GAGAL — BARCODE TIDAK DITEMUKAN',
            ];
        }

        $validationError = $this->validateTree($tree, 0, false, $user);
        if ($validationError) {
            return $validationError;
        }

        return [
            'success' => true,
            'code' => 'PREVIEW_READY',
            'message' => 'DATA TERSEDIA — MENUNGGU KONFIRMASI',
            'tree' => $tree,
            'tree_info' => $this->treeInfo($tree),
        ];
    }

    /**
     * Process Scan Siap Cor for a given tree barcode (atomic confirmation).
     */
    public function processScan(string $barcode, int $operatorId, ?User $user = null): array
    {
        $barcode = trim($barcode);

        if ($barcode === '') {
            return [
                'success' => false,
                'code' => 'EMPTY_BARCODE',
                'reason' => 'GAGAL — BARCODE TIDAK BOLEH KOSONG',
            ];
        }

        return DB::transaction(function () use ($barcode, $operatorId, $user) {
            // 1. Acquire pessimistic lock on the tree row
            $tree = LostWaxTree::with([
                'workOrder.itemReference',
                'printOrderLine.printOrder',
                'printOrderLine.productionPlan',
                'allocations.printOrderLine.productionPlan',
                'defects',
                'coatingRack',
            ])
                ->where('barcode', $barcode)
                ->lockForUpdate()
                ->first();

            // Validation: Barcode not found
            if (! $tree) {
                return [
                    'success' => false,
                    'code' => 'NOT_FOUND',
                    'reason' => 'GAGAL — BARCODE TIDAK DITEMUKAN',
                ];
            }

            // Run business validation and record rejection if invalid
            $validationError = $this->validateTree($tree, $operatorId, true, $user);
            if ($validationError) {
                return $validationError;
            }

            // Execute atomic transition to siap_cor
            $scannedAt = Carbon::now(config('app.timezone'));

            $event = LostWaxScanEvent::create([
                'tree_id' => $tree->id,
                'barcode' => $barcode,
                'stage' => 'siap_cor',
                'scanned_at' => $scannedAt,
                'operator_id' => $operatorId,
                'result' => 'success',
                'source' => LostWaxScanEvent::SOURCE_PHYSICAL_SCAN,
                'notes' => null,
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
                'allocations.printOrderLine.productionPlan',
                'defects',
                'coatingRack',
            ]);

            return [
                'success' => true,
                'code' => 'SUCCESS',
                'message' => 'BERHASIL — SELESAI DEWAXING',
                'tree' => $tree,
                'event' => $event,
                'tree_info' => $this->treeInfo($tree),
            ];
        });
    }

    /**
     * Validate tree suitability for transition to siap_cor.
     */
    protected function validateTree(LostWaxTree $tree, int $operatorId, bool $recordRejections, ?User $user): ?array
    {
        // Validation: Product scope RBAC for PPIC users
        if ($user && method_exists($user, 'hasRole') && $user->hasRole('ppic') && $user->product_scope) {
            $plan = $tree->printOrderLine?->productionPlan;
            if (! $plan && $tree->allocations && $tree->allocations->isNotEmpty()) {
                $plan = $tree->allocations->first()?->printOrderLine?->productionPlan;
            }
            if ($plan && $plan->product_scope && $plan->product_scope !== $user->product_scope) {
                return [
                    'success' => false,
                    'code' => 'UNAUTHORIZED_SCOPE',
                    'reason' => 'GAGAL — BUKAN LINGKUP PRODUK ANDA',
                    'tree_info' => $this->treeInfo($tree),
                ];
            }
        }

        // Validation: Traveler card cancelled
        if ($tree->status === 'cancelled') {
            $reason = 'GAGAL — TRAVELER CARD TELAH DIBATALKAN';
            if ($recordRejections) {
                $this->recordRejection($tree, $operatorId, $reason);
            }

            return [
                'success' => false,
                'code' => 'CANCELLED',
                'reason' => $reason,
                'tree' => $tree,
                'tree_info' => $this->treeInfo($tree),
            ];
        }

        // Validation: Duplicate scan (already at siap_cor)
        if ($tree->current_stage === 'siap_cor') {
            $reason = 'GAGAL — SUDAH PERNAH SCAN SIAP COR';
            if ($recordRejections) {
                $this->recordRejection($tree, $operatorId, $reason);
            }

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
            if ($recordRejections) {
                $this->recordRejection($tree, $operatorId, $reason);
            }

            return [
                'success' => false,
                'code' => 'ALREADY_SCANNED',
                'reason' => $reason,
                'tree' => $tree,
                'tree_info' => $this->treeInfo($tree),
            ];
        }

        // Validation: Already consumed by casting
        $isConsumedByCasting = DB::table('lost_wax_casting_result_trees')
            ->where('active_tree_id', $tree->id)
            ->exists();

        if ($tree->current_stage === 'cor' || $isConsumedByCasting) {
            $reason = 'GAGAL — POHON SUDAH MASUK TRANSAKSI PENGECORAN';
            if ($recordRejections) {
                $this->recordRejection($tree, $operatorId, $reason);
            }

            return [
                'success' => false,
                'code' => 'ALREADY_CAST',
                'reason' => $reason,
                'tree' => $tree,
                'tree_info' => $this->treeInfo($tree),
            ];
        }

        // Validation: Must have completed Scan Oven (current_stage === 'oven' and active oven event)
        $hasActiveOven = LostWaxScanEvent::where('tree_id', $tree->id)
            ->where('stage', 'oven')
            ->where('result', 'success')
            ->whereDoesntHave('void')
            ->exists();

        if ($tree->current_stage !== 'oven' || ! $hasActiveOven) {
            $reason = 'GAGAL — BELUM SCAN SIAP OVEN';
            if ($recordRejections) {
                $this->recordRejection($tree, $operatorId, $reason);
            }

            return [
                'success' => false,
                'code' => 'NOT_OVEN_READY',
                'reason' => $reason,
                'tree' => $tree,
                'tree_info' => $this->treeInfo($tree),
            ];
        }

        // Validation: Usable quantity must be greater than zero
        if ($tree->usable_quantity <= 0) {
            $reason = 'GAGAL — 0 PCS LAYAK';
            if ($recordRejections) {
                $this->recordRejection($tree, $operatorId, $reason);
            }

            return [
                'success' => false,
                'code' => 'ZERO_USABLE',
                'reason' => $reason,
                'tree' => $tree,
                'tree_info' => $this->treeInfo($tree),
            ];
        }

        return null;
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
            'source' => LostWaxScanEvent::SOURCE_PHYSICAL_SCAN,
            'anomaly_reason' => $reason,
        ]);
    }

    /**
     * Format tree information for scanner operator presentation.
     */
    public function treeInfo(LostWaxTree $tree): array
    {
        $plan = $tree->printOrderLine?->productionPlan;
        $code = $tree->getSourceCode() ?? '-';
        $productName = $tree->getSourceProduct() ?? '-';
        $customer = $tree->getSourceCustomer() ?? '-';
        $aisi = $tree->getSourceAisi() ?? ($plan?->aisi ?? '-');

        // Fallback to allocations if not directly present on tree
        if (($code === '-' || $productName === '-') && $tree->allocations && $tree->allocations->isNotEmpty()) {
            $firstAlloc = $tree->allocations->first();
            $allocLine = $firstAlloc?->printOrderLine;
            $allocPlan = $allocLine?->productionPlan;
            if ($code === '-') {
                $code = $allocPlan?->code ?? $allocLine?->code ?? '-';
            }
            if ($productName === '-') {
                $productName = $allocPlan?->item_name ?? $allocLine?->item_name ?? '-';
            }
            if ($customer === '-') {
                $customer = $allocPlan?->customer ?? $allocLine?->customer ?? '-';
            }
            if ($aisi === '-') {
                $aisi = $allocPlan?->aisi ?? $allocLine?->aisi ?? '-';
            }
        }

        // Rak / Lokasi Buffer
        $rackLocation = 'Belum ditentukan';
        if ($tree->coatingRack) {
            $rackNumber = $tree->coatingRack->rack_number;
            $rackLabel = $tree->coatingRack->label ? ' ('.$tree->coatingRack->label.')' : '';
            $rackLocation = 'Rak '.$rackNumber.$rackLabel;
        }

        return [
            'id' => $tree->id,
            'barcode' => $tree->barcode,
            'human_barcode' => $tree->human_barcode,
            'tree_number' => $tree->tree_number,
            'quantity_gross' => (int) $tree->quantity,
            'quantity_usable' => (int) $tree->usable_quantity,
            'total_defects' => (int) $tree->total_defect_quantity,
            'code' => $code,
            'product_name' => $productName,
            'customer' => $customer,
            'aisi' => $aisi,
            'rack_location' => $rackLocation,
            'current_stage' => $tree->current_stage,
            'current_stage_label' => $tree->current_stage_label,
            'last_scan_at' => $tree->last_scan_at?->format('H:i:s'),
            'scanned_date' => $tree->last_scan_at?->format('d/m/Y H:i:s'),
        ];
    }
}
