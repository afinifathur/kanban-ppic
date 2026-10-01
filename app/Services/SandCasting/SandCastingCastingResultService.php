<?php

namespace App\Services\SandCasting;

use App\Exceptions\DuplicateCastingResultException;
use App\Jobs\SyncCastingResultToMasterDataJob;
use App\Models\SandCastingCastingOrderLine;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
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
     * @throws DuplicateCastingResultException
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
            $seenPlanIds = [];

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

                // Lock the individual order line
                $orderLine = SandCastingCastingOrderLine::lockForUpdate()->find($lineId);
                if (! $orderLine) {
                    throw new InvalidArgumentException("Baris Perintah Cor ID {$lineId} tidak valid atau tidak ditemukan.");
                }

                $planId = $orderLine->production_plan_id;
                $planCode = $orderLine->productionPlan ? $orderLine->productionPlan->code : ($orderLine->code ?? '-');
                $planItem = $orderLine->productionPlan ? $orderLine->productionPlan->item_name : ($orderLine->item_name ?? '-');

                // 1. Intra-request duplicate check by order line ID
                if (in_array($lineId, $seenLineIds, true)) {
                    throw new DuplicateCastingResultException(
                        "Baris Perintah Cor ID {$lineId} ({$planCode} - {$planItem}) tidak boleh dimasukkan lebih dari satu kali dalam satu Heat.",
                        [
                            'heat_number' => $heatNumber,
                            'production_plan_id' => $planId,
                            'production_code' => $planCode,
                            'item_name' => $planItem,
                            'existing_traveler_number' => 'Dalam Request Ini',
                            'existing_qty_good' => $qtyGood,
                            'existing_current_stage' => 'NEW',
                        ]
                    );
                }
                $seenLineIds[] = $lineId;

                // 2. Intra-request duplicate check by production plan ID
                if ($planId && in_array($planId, $seenPlanIds, true)) {
                    throw new DuplicateCastingResultException(
                        "Item {$planCode} ({$planItem}) dimasukkan lebih dari satu kali dalam request Heat yang sama.",
                        [
                            'heat_number' => $heatNumber,
                            'production_plan_id' => $planId,
                            'production_code' => $planCode,
                            'item_name' => $planItem,
                            'existing_traveler_number' => 'Dalam Request Ini',
                            'existing_qty_good' => $qtyGood,
                            'existing_current_stage' => 'NEW',
                        ]
                    );
                }
                if ($planId) {
                    $seenPlanIds[] = $planId;
                }

                // 3. Database cross-request duplicate check: SAME HEAT + SAME PRODUCTION PLAN
                if ($planId) {
                    $existingResultLine = SandCastingCastingResultLine::where('production_plan_id', $planId)
                        ->whereHas('castingResult', function ($q) use ($heatNumber) {
                            $q->where('heat_number', $heatNumber);
                        })
                        ->with(['productionPlan', 'castingResult'])
                        ->first();

                    if ($existingResultLine) {
                        $existingPlan = $existingResultLine->productionPlan ?? $orderLine->productionPlan;
                        $existingCode = $existingPlan ? $existingPlan->code : ($orderLine->code ?? '-');
                        $existingItem = $existingPlan ? $existingPlan->item_name : ($orderLine->item_name ?? '-');
                        $existingStage = strtoupper(str_replace('_', ' ', $existingResultLine->current_stage ?? 'netto'));

                        throw new DuplicateCastingResultException(
                            "Hasil Cor untuk Heat '{$heatNumber}' dengan Item '{$existingCode}' ({$existingItem}) sudah pernah dicatat dengan nomor KTR {$existingResultLine->traveler_number} (Qty Good: {$existingResultLine->qty_good} pcs, Stage: {$existingStage}).",
                            [
                                'heat_number' => $heatNumber,
                                'production_plan_id' => $planId,
                                'production_code' => $existingCode,
                                'item_name' => $existingItem,
                                'existing_traveler_number' => $existingResultLine->traveler_number,
                                'existing_qty_good' => $existingResultLine->qty_good,
                                'existing_current_stage' => $existingStage,
                            ]
                        );
                    }
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

    /**
     * Correct an existing Casting Result (Heat / Pouring Event).
     *
     * Invariants:
     * - Pre-Gate (lines with 0 executions): can edit heat_number, cast_date, furnace, shift, operator_name, notes,
     *   production_plan_id, sand_casting_casting_order_line_id, qty_good, qty_reject, unit_weight_kg.
     * - Post-Gate (lines with >= 1 execution):
     *   - Locked: traveler_number, heat_number, production_plan_id, sand_casting_casting_order_line_id, qty_good.
     *   - Controlled (requires reason, records audit trail): cast_date, furnace, shift, operator_name, notes, qty_reject, unit_weight_kg.
     * - traveler_number is NEVER changed under any circumstances.
     * - Duplicate check on (Heat + ProductionPlan) enforced across lines, excluding current line ID.
     * - If printed line has identity/qty changed pre-gate, mark needs_reprint = true.
     * - Master Data KPI re-synced if heat_number, cast_date, production_plan_id, or qty_good changed.
     *
     * @param  array  $headerData  ['heat_number', 'cast_date', 'furnace', 'shift', 'operator_name', 'notes', 'reason']
     * @param  array  $linesData  Array of ['id', 'sand_casting_casting_order_line_id', 'qty_good', 'qty_reject', 'unit_weight_kg', 'notes']
     * @param  int  $correctedBy  User ID
     *
     * @throws DuplicateCastingResultException
     * @throws InvalidArgumentException
     */
    public function correctResult(
        int|SandCastingCastingResult $castingResult,
        array $headerData,
        array $linesData,
        int $correctedBy
    ): SandCastingCastingResult {
        $resultId = $castingResult instanceof SandCastingCastingResult ? $castingResult->id : (int) $castingResult;

        $user = User::find($correctedBy);
        if (! $user) {
            throw new InvalidArgumentException("User dengan ID {$correctedBy} tidak ditemukan.");
        }

        $heatNumber = trim($headerData['heat_number'] ?? '');
        if ($heatNumber === '') {
            throw new InvalidArgumentException('Nomor Heat wajib diisi.');
        }

        $castDate = $headerData['cast_date'] ?? null;
        if (! $castDate) {
            throw new InvalidArgumentException('Tanggal Cor wajib diisi.');
        }

        $reason = trim($headerData['reason'] ?? '');

        if (empty($linesData)) {
            throw new InvalidArgumentException('Minimal satu baris hasil cor harus disertakan.');
        }

        $needsMasterDataSync = false;

        $updatedResult = DB::transaction(function () use (
            $resultId,
            $headerData,
            $linesData,
            $correctedBy,
            $heatNumber,
            $castDate,
            $reason,
            $user,
            &$needsMasterDataSync
        ) {
            /** @var SandCastingCastingResult $result */
            $result = SandCastingCastingResult::where('id', $resultId)
                ->lockForUpdate()
                ->first();

            if (! $result) {
                throw new InvalidArgumentException("Hasil Cor dengan ID {$resultId} tidak ditemukan.");
            }

            $hasAnyExecution = $result->hasPhysicalExecution();

            // 1. Post-Gate Header Rule: Heat Number is locked if ANY line has physical execution
            $oldHeatNumber = $result->heat_number;
            if ($hasAnyExecution && $heatNumber !== $oldHeatNumber) {
                throw new InvalidArgumentException('Nomor Heat terkunci dan tidak dapat diubah karena KTR pada Heat ini sudah memiliki riwayat eksekusi fisik di lantai produksi.');
            }

            // Post-Gate Header Rule: Reason is mandatory if any physical execution exists
            if ($hasAnyExecution && $reason === '') {
                throw new InvalidArgumentException('Alasan koreksi wajib diisi untuk Hasil Cor yang sudah memiliki eksekusi fisik.');
            }

            $oldCastDate = $result->cast_date ? $result->cast_date->format('Y-m-d') : null;
            $formattedNewCastDate = is_string($castDate) ? substr($castDate, 0, 10) : (is_object($castDate) ? $castDate->format('Y-m-d') : $castDate);

            if ($oldHeatNumber !== $heatNumber || $oldCastDate !== $formattedNewCastDate) {
                $needsMasterDataSync = true;
            }

            // Record Header Audits
            $headerChanges = [
                'heat_number' => [$oldHeatNumber, $heatNumber],
                'cast_date' => [$oldCastDate, $formattedNewCastDate],
                'furnace' => [$result->furnace, $headerData['furnace'] ?? null],
                'shift' => [$result->shift, $headerData['shift'] ?? null],
                'operator_name' => [$result->operator_name, $headerData['operator_name'] ?? null],
                'notes' => [$result->notes, $headerData['notes'] ?? null],
            ];

            foreach ($headerChanges as $fieldName => [$oldVal, $newVal]) {
                $oldNorm = $oldVal !== null ? (string) $oldVal : '';
                $newNorm = $newVal !== null ? (string) $newVal : '';
                if ($oldNorm !== $newNorm) {
                    \App\Models\SandCastingCastingResultCorrection::create([
                        'sand_casting_casting_result_id' => $result->id,
                        'sand_casting_casting_result_line_id' => null,
                        'target_type' => 'HEADER',
                        'field_name' => $fieldName,
                        'old_value' => $oldVal,
                        'new_value' => $newVal,
                        'reason' => $reason !== '' ? $reason : null,
                        'corrected_by' => $correctedBy,
                        'corrected_at' => now(),
                    ]);
                }
            }

            // Update Header
            $result->heat_number = $heatNumber;
            $result->cast_date = $castDate;
            $result->furnace = $headerData['furnace'] ?? null;
            $result->shift = $headerData['shift'] ?? null;
            $result->operator_name = $headerData['operator_name'] ?? null;
            $result->notes = $headerData['notes'] ?? null;
            $result->save();

            // 2. Validate and Update Lines
            $seenLineIds = [];
            $seenPlanIds = [];

            foreach ($linesData as $itemData) {
                $lineId = (int) ($itemData['id'] ?? 0);
                if (! $lineId) {
                    throw new InvalidArgumentException('ID baris Hasil Cor wajib disertakan.');
                }

                /** @var SandCastingCastingResultLine $line */
                $line = $result->lines()->where('id', $lineId)->lockForUpdate()->first();
                if (! $line) {
                    throw new InvalidArgumentException("Baris Hasil Cor ID {$lineId} tidak valid atau tidak ditemukan pada Heat ini.");
                }

                $lineHasExecution = $line->hasPhysicalExecution();

                $newLineOrderLineId = (int) ($itemData['sand_casting_casting_order_line_id'] ?? $line->sand_casting_casting_order_line_id);
                $newQtyGood = isset($itemData['qty_good']) ? (int) $itemData['qty_good'] : (int) $line->qty_good;
                $newQtyReject = isset($itemData['qty_reject']) ? (int) $itemData['qty_reject'] : (int) $line->qty_reject;

                if ($newQtyGood < 0 || $newQtyReject < 0) {
                    throw new InvalidArgumentException('Jumlah hasil cor atau reject tidak boleh negatif.');
                }

                if ($newQtyGood === 0 && $newQtyReject === 0) {
                    throw new InvalidArgumentException("Baris KTR {$line->traveler_number} harus memiliki kuantitas (Good atau Reject > 0).");
                }

                // 2A. POST-GATE LINE ENFORCEMENT: Locked Identity and Locked Baseline Qty Good
                if ($lineHasExecution) {
                    if ($newLineOrderLineId !== (int) $line->sand_casting_casting_order_line_id) {
                        throw new InvalidArgumentException("Item/PCOR pada KTR {$line->traveler_number} terkunci dan tidak dapat diubah karena KTR sudah memiliki eksekusi fisik.");
                    }

                    if ($newQtyGood !== (int) $line->qty_good) {
                        throw new InvalidArgumentException("Kuantitas bagus (Qty Good) pada KTR {$line->traveler_number} terkunci dan tidak dapat diubah karena KTR sudah memiliki eksekusi fisik.");
                    }
                }

                // Lock and retrieve Order Line
                $orderLine = SandCastingCastingOrderLine::lockForUpdate()->find($newLineOrderLineId);
                if (! $orderLine) {
                    throw new InvalidArgumentException("Baris Perintah Cor ID {$newLineOrderLineId} tidak valid atau tidak ditemukan.");
                }

                $planId = $orderLine->production_plan_id;
                $planCode = $orderLine->productionPlan ? $orderLine->productionPlan->code : ($orderLine->code ?? '-');
                $planItem = $orderLine->productionPlan ? $orderLine->productionPlan->item_name : ($orderLine->item_name ?? '-');

                // Intra-request duplicate check by order line ID
                if (in_array($newLineOrderLineId, $seenLineIds, true)) {
                    throw new DuplicateCastingResultException(
                        "Baris Perintah Cor ID {$newLineOrderLineId} ({$planCode} - {$planItem}) tidak boleh dimasukkan lebih dari satu kali dalam satu Heat.",
                        [
                            'heat_number' => $heatNumber,
                            'production_plan_id' => $planId,
                            'production_code' => $planCode,
                            'item_name' => $planItem,
                            'existing_traveler_number' => 'Dalam Request Ini',
                            'existing_qty_good' => $newQtyGood,
                            'existing_current_stage' => 'EDIT',
                        ]
                    );
                }
                $seenLineIds[] = $newLineOrderLineId;

                // Intra-request duplicate check by production plan ID
                if ($planId && in_array($planId, $seenPlanIds, true)) {
                    throw new DuplicateCastingResultException(
                        "Item {$planCode} ({$planItem}) dimasukkan lebih dari satu kali dalam request Heat yang sama.",
                        [
                            'heat_number' => $heatNumber,
                            'production_plan_id' => $planId,
                            'production_code' => $planCode,
                            'item_name' => $planItem,
                            'existing_traveler_number' => 'Dalam Request Ini',
                            'existing_qty_good' => $newQtyGood,
                            'existing_current_stage' => 'EDIT',
                        ]
                    );
                }
                if ($planId) {
                    $seenPlanIds[] = $planId;
                }

                // 2B. Cross-request duplicate check: SAME HEAT + SAME PRODUCTION PLAN (exclude current line ID)
                if ($planId) {
                    $existingResultLine = SandCastingCastingResultLine::where('production_plan_id', $planId)
                        ->where('id', '!=', $line->id)
                        ->whereHas('castingResult', function ($q) use ($heatNumber) {
                            $q->where('heat_number', $heatNumber);
                        })
                        ->with(['productionPlan', 'castingResult'])
                        ->first();

                    if ($existingResultLine) {
                        $existingPlan = $existingResultLine->productionPlan ?? $orderLine->productionPlan;
                        $existingCode = $existingPlan ? $existingPlan->code : ($orderLine->code ?? '-');
                        $existingItem = $existingPlan ? $existingPlan->item_name : ($orderLine->item_name ?? '-');
                        $existingStage = strtoupper(str_replace('_', ' ', $existingResultLine->current_stage ?? 'netto'));

                        throw new DuplicateCastingResultException(
                            "Hasil Cor untuk Heat '{$heatNumber}' dengan Item '{$existingCode}' ({$existingItem}) sudah pernah dicatat dengan nomor KTR {$existingResultLine->traveler_number} (Qty Good: {$existingResultLine->qty_good} pcs, Stage: {$existingStage}).",
                            [
                                'heat_number' => $heatNumber,
                                'production_plan_id' => $planId,
                                'production_code' => $existingCode,
                                'item_name' => $existingItem,
                                'existing_traveler_number' => $existingResultLine->traveler_number,
                                'existing_qty_good' => $existingResultLine->qty_good,
                                'existing_current_stage' => $existingStage,
                            ]
                        );
                    }
                }

                // Verify parent order status if changing order line
                if (! $lineHasExecution && $newLineOrderLineId !== (int) $line->sand_casting_casting_order_line_id) {
                    $order = $orderLine->castingOrder;
                    if (! $order || ! in_array($order->status, ['ISSUED', 'COMPLETED'], true)) {
                        throw new InvalidArgumentException("Perintah Cor {$orderLine->code} tidak dalam status yang valid.");
                    }
                }

                // Scope authorization check
                if ($user && $user->hasRole('ppic') && $user->product_scope) {
                    if ($orderLine->productionPlan && $orderLine->productionPlan->product_scope !== $user->product_scope) {
                        throw new InvalidArgumentException("Unauthorized: Item {$orderLine->code} berada di luar product scope.");
                    }
                }

                // Weight calculations
                $defaultUnitWeight = $orderLine->productionPlan ? (float) ($orderLine->productionPlan->weight ?? 0) : (float) $line->unit_weight_kg;
                $newUnitWeight = isset($itemData['unit_weight_kg']) && is_numeric($itemData['unit_weight_kg'])
                    ? (float) $itemData['unit_weight_kg']
                    : $defaultUnitWeight;

                $newTotalWeight = round($newQtyGood * $newUnitWeight, 2);

                $newLineNotes = isset($itemData['notes'])
                    ? trim($itemData['notes'])
                    : $line->notes;

                // Track MasterData KPI sync requirement
                if (
                    (int) $line->production_plan_id !== (int) $planId ||
                    (int) $line->qty_good !== $newQtyGood
                ) {
                    $needsMasterDataSync = true;
                }

                // 2C. REPRINT TRACKING: If line was already printed and printed identity/qty changed pre-gate
                $printedIdentityChanged = (
                    $oldHeatNumber !== $heatNumber ||
                    $oldCastDate !== $formattedNewCastDate ||
                    (int) $line->sand_casting_casting_order_line_id !== $newLineOrderLineId ||
                    (int) $line->production_plan_id !== (int) $planId ||
                    (int) $line->qty_good !== $newQtyGood
                );

                $isAlreadyPrinted = ($line->printed_at !== null || (int) $line->print_count > 0);
                if (! $lineHasExecution && $isAlreadyPrinted && $printedIdentityChanged) {
                    $line->needs_reprint = true;
                }

                // Record Line Audits
                $lineChanges = [
                    'sand_casting_casting_order_line_id' => [$line->sand_casting_casting_order_line_id, $newLineOrderLineId],
                    'production_plan_id' => [$line->production_plan_id, $planId],
                    'qty_good' => [$line->qty_good, $newQtyGood],
                    'qty_reject' => [$line->qty_reject, $newQtyReject],
                    'unit_weight_kg' => [$line->unit_weight_kg, $newUnitWeight],
                    'total_weight_kg' => [$line->total_weight_kg, $newTotalWeight],
                    'notes' => [$line->notes, $newLineNotes !== '' ? $newLineNotes : null],
                ];

                foreach ($lineChanges as $fieldName => [$oldVal, $newVal]) {
                    $oldNorm = $oldVal !== null ? (string) $oldVal : '';
                    $newNorm = $newVal !== null ? (string) $newVal : '';
                    if ($oldNorm !== $newNorm) {
                        \App\Models\SandCastingCastingResultCorrection::create([
                            'sand_casting_casting_result_id' => $result->id,
                            'sand_casting_casting_result_line_id' => $line->id,
                            'target_type' => 'LINE',
                            'field_name' => $fieldName,
                            'old_value' => $oldVal,
                            'new_value' => $newVal,
                            'reason' => $reason !== '' ? $reason : null,
                            'corrected_by' => $correctedBy,
                            'corrected_at' => now(),
                        ]);
                    }
                }

                // Save Line Mutations (traveler_number and current_stage are strictly preserved)
                $line->sand_casting_casting_order_line_id = $newLineOrderLineId;
                $line->production_plan_id = $planId;
                $line->qty_good = $newQtyGood;
                $line->qty_reject = $newQtyReject;
                $line->unit_weight_kg = $newUnitWeight;
                $line->total_weight_kg = $newTotalWeight;
                $line->notes = $newLineNotes !== '' ? $newLineNotes : null;
                $line->save();
            }

            return $result->load([
                'lines.castingOrderLine.castingOrder',
                'lines.productionPlan',
                'lines.stageExecutions',
                'lines.corrections.corrector',
                'corrections.corrector',
                'recorder',
            ]);
        });

        // Re-sync Master Data KPI if key fields changed
        if ($needsMasterDataSync) {
            SyncCastingResultToMasterDataJob::dispatch($updatedResult->id)->afterCommit();
        }

        return $updatedResult;
    }
}
