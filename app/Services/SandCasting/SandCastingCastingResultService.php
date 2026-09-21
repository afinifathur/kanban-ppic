<?php

namespace App\Services\SandCasting;

use App\Jobs\SyncCastingResultToMasterDataJob;
use App\Models\SandCastingCastingOrderLine;
use App\Models\SandCastingCastingResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SandCastingCastingResultService
{
    /**
     * Record a new Casting Result (Heat / Pouring Event).
     *
     * A Heat is an independent metallurgical event that may combine
     * multiple Casting Order Lines from different Perintah Cor (PCOR) documents.
     *
     * @param  array  $headerData  ['heat_number', 'cast_date', 'furnace', 'shift', 'operator_name', 'notes']
     * @param  array  $linesData  Array of ['sand_casting_casting_order_line_id', 'qty_good', 'qty_reject', 'unit_weight_kg', 'total_weight_kg', 'notes']
     * @param  int  $recordedBy  User ID
     *
     * @throws InvalidArgumentException
     */
    public function recordResult(
        array $headerData,
        array $linesData,
        int $recordedBy
    ): SandCastingCastingResult {
        // 1. Header Validation
        $heatNumber = trim($headerData['heat_number'] ?? '');
        if ($heatNumber === '') {
            throw new InvalidArgumentException('Nomor Heat wajib diisi.');
        }

        $castDate = $headerData['cast_date'] ?? null;
        if (! $castDate) {
            throw new InvalidArgumentException('Tanggal Cor wajib diisi.');
        }

        if (empty($linesData)) {
            throw new InvalidArgumentException('Minimal satu item harus memiliki hasil cor (good atau reject > 0).');
        }

        $user = User::find($recordedBy);

        $result = DB::transaction(function () use ($headerData, $linesData, $recordedBy, $heatNumber, $castDate, $user) {
            $validLines = [];

            $seenLineIds = [];

            foreach ($linesData as $index => $itemData) {
                $lineId = (int) ($itemData['sand_casting_casting_order_line_id'] ?? 0);
                $qtyGood = (int) ($itemData['qty_good'] ?? 0);
                $qtyReject = (int) ($itemData['qty_reject'] ?? 0);

                if ($qtyGood < 0 || $qtyReject < 0) {
                    throw new InvalidArgumentException('Jumlah hasil cor atau reject tidak boleh negatif.');
                }

                // Only process lines with non-zero output
                if ($qtyGood === 0 && $qtyReject === 0) {
                    continue;
                }

                if (in_array($lineId, $seenLineIds, true)) {
                    throw new InvalidArgumentException("Baris Perintah Cor ID {$lineId} tidak boleh dimasukkan lebih dari satu kali dalam satu Heat.");
                }
                $seenLineIds[] = $lineId;

                // Lock the individual order line
                $orderLine = SandCastingCastingOrderLine::lockForUpdate()->find($lineId);
                if (! $orderLine) {
                    throw new InvalidArgumentException("Baris Perintah Cor ID {$lineId} tidak valid atau tidak ditemukan.");
                }

                $order = $orderLine->castingOrder;
                if (! $order) {
                    throw new InvalidArgumentException("Dokumen Perintah Cor untuk baris {$orderLine->code} tidak ditemukan.");
                }

                // Verify parent order status
                if ($order->status === 'DRAFT') {
                    throw new InvalidArgumentException("Perintah Cor {$order->casting_order_number} harus berstatus ISSUED sebelum Hasil Cor dapat dicatat.");
                }

                if ($order->status === 'CANCELLED') {
                    throw new InvalidArgumentException("Tidak dapat mencatat Hasil Cor pada Perintah Cor yang sudah dibatalkan ({$order->casting_order_number}).");
                }

                if (! in_array($order->status, ['ISSUED', 'COMPLETED'])) {
                    throw new InvalidArgumentException("Perintah Cor {$order->casting_order_number} tidak dalam status yang valid untuk pencatatan Hasil Cor.");
                }

                // Verify domain on individual plan
                if ($orderLine->productionPlan && ! $orderLine->productionPlan->isSandCasting()) {
                    throw new InvalidArgumentException("Item {$orderLine->code} bukan bagian dari domain Sand Casting.");
                }

                // Verify product scope if user has scope restrictions
                if ($user && $user->hasRole('ppic') && $user->product_scope) {
                    if ($orderLine->productionPlan && $orderLine->productionPlan->product_scope !== $user->product_scope) {
                        throw new InvalidArgumentException("Unauthorized: Item {$orderLine->code} berada di luar product scope.");
                    }
                }

                // Determine weight calculations
                $defaultUnitWeight = $orderLine->productionPlan ? (float) ($orderLine->productionPlan->weight ?? 0) : 0;
                $unitWeight = isset($itemData['unit_weight_kg']) && is_numeric($itemData['unit_weight_kg'])
                    ? (float) $itemData['unit_weight_kg']
                    : $defaultUnitWeight;

                $totalWeight = isset($itemData['total_weight_kg']) && is_numeric($itemData['total_weight_kg'])
                    ? (float) $itemData['total_weight_kg']
                    : round($qtyGood * $unitWeight, 2);

                $resolvedNotes = ! empty($itemData['notes'])
                    ? $itemData['notes']
                    : ($orderLine->notes ?: ($orderLine->productionPlan ? $orderLine->productionPlan->title : null));

                $validLines[] = [
                    'order_line' => $orderLine,
                    'qty_good' => $qtyGood,
                    'qty_reject' => $qtyReject,
                    'unit_weight_kg' => $unitWeight,
                    'total_weight_kg' => $totalWeight,
                    'notes' => $resolvedNotes,
                ];
            }

            if (empty($validLines)) {
                throw new InvalidArgumentException('Minimal satu item harus memiliki hasil cor (good atau reject > 0).');
            }

            // Create standalone Casting Result (Heat Header)
            $result = SandCastingCastingResult::create([
                'heat_number' => $heatNumber,
                'cast_date' => $castDate,
                'furnace' => $headerData['furnace'] ?? null,
                'shift' => $headerData['shift'] ?? null,
                'operator_name' => $headerData['operator_name'] ?? null,
                'notes' => $headerData['notes'] ?? null,
                'recorded_by' => $recordedBy,
            ]);

            // Create Result Lines & generate concurrency-safe Travelers
            foreach ($validLines as $item) {
                $travelerNumber = TravelerNumberGenerator::generateNext($castDate);

                $result->lines()->create([
                    'sand_casting_casting_order_line_id' => $item['order_line']->id,
                    'production_plan_id' => $item['order_line']->production_plan_id,
                    'traveler_number' => $travelerNumber,
                    'qty_good' => $item['qty_good'],
                    'qty_reject' => $item['qty_reject'],
                    'unit_weight_kg' => $item['unit_weight_kg'],
                    'total_weight_kg' => $item['total_weight_kg'],
                    'current_stage' => 'netto',
                    'notes' => $item['notes'],
                ]);
            }

            return $result->load(['lines.castingOrderLine.castingOrder', 'lines.productionPlan', 'recorder']);
        });

        // Dispatch asynchronous sync job to Master Data KPI after commit
        SyncCastingResultToMasterDataJob::dispatch($result->id)->afterCommit();

        return $result;
    }
}
