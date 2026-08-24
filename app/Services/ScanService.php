<?php

namespace App\Services;

use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ScanService
{
    public function process(string $barcode, int $operatorId): array
    {
        return DB::transaction(function () use ($barcode, $operatorId) {
            $tree = LostWaxTree::with(['workOrder', 'printOrderLine.printOrder', 'printOrderLine.productionPlan'])
                ->lockForUpdate()
                ->where('barcode', $barcode)
                ->first();

            if (! $tree) {
                return $this->reject('Barcode tidak ditemukan.', $barcode, null, $operatorId);
            }

            $nextStage = $tree->nextStage();

            if (! $nextStage) {
                return $this->reject('Tree sudah menyelesaikan semua tahapan.', $barcode, $tree->id, $operatorId);
            }

            $scannedAt = Carbon::now(config('app.timezone'));
            $agingMinutes = null;
            $agingStatus = null;

            if ($tree->last_scan_at) {
                $agingMinutes = (int) round($tree->last_scan_at->diffInMinutes($scannedAt));
                $agingStatus = $this->classifyAging($agingMinutes, $tree->current_stage);
            }

            $event = LostWaxScanEvent::create([
                'tree_id' => $tree->id,
                'barcode' => $barcode,
                'stage' => $nextStage,
                'scanned_at' => $scannedAt,
                'operator_id' => $operatorId,
                'result' => 'success',
                'aging_minutes' => $agingMinutes,
                'aging_status' => $agingStatus,
            ]);

            $tree->update([
                'current_stage' => $nextStage,
                'last_scan_at' => $scannedAt,
            ]);

            $tree->load(['workOrder.itemReference', 'printOrderLine.printOrder', 'printOrderLine.productionPlan']);

            return [
                'success' => true,
                'tree' => $tree,
                'event' => $event,
                'stage_label' => $event->stage_label,
                'aging_label' => $event->aging_label,
                'aging_status' => $agingStatus,
            ];
        });
    }

    public function validateSequential(string $barcode, ?string $expectedStage, ?string $actualCurrentStage, ?string $nextStage): bool
    {
        if ($expectedStage === null || $actualCurrentStage === null) {
            return false;
        }

        return $nextStage === $expectedStage;
    }

    public function isDuplicate(string $barcode, ?string $currentStage, ?string $incomingStage): bool
    {
        if ($currentStage === null || $incomingStage === null) {
            return false;
        }

        return $currentStage === $incomingStage;
    }

    public function rejectSkippedScan(LostWaxTree $tree, int $operatorId, string $invalidStage, string $reason): array
    {
        return DB::transaction(function () use ($tree, $operatorId, $invalidStage, $reason) {
            $scannedAt = Carbon::now(config('app.timezone'));

            LostWaxScanEvent::create([
                'tree_id' => $tree->id,
                'barcode' => $tree->barcode,
                'stage' => $invalidStage,
                'scanned_at' => $scannedAt,
                'operator_id' => $operatorId,
                'result' => 'rejected',
                'anomaly_reason' => $reason,
            ]);

            $tree->load(['workOrder.itemReference', 'printOrderLine.printOrder', 'printOrderLine.productionPlan']);

            return [
                'success' => false,
                'tree' => $tree,
                'reason' => $reason,
                'current_stage' => $tree->current_stage,
                'current_stage_label' => $tree->current_stage_label,
            ];
        });
    }

    public function classifyAging(int $minutes, ?string $stage = null): string
    {
        $minHours = null;
        $maxHours = null;

        if ($stage !== null) {
            $stageConfig = config("lost_wax.aging.stages.{$stage}");
            if ($stageConfig) {
                $minHours = isset($stageConfig['min_hours']) ? (float) $stageConfig['min_hours'] : null;
                $maxHours = isset($stageConfig['max_hours']) ? (float) $stageConfig['max_hours'] : null;
            }
        }

        if ($minHours === null) {
            $minHours = (float) config('lost_wax.aging.min_hours', 4);
        }

        if ($maxHours === null) {
            $maxHours = (float) config('lost_wax.aging.max_hours', 6);
        }

        $hours = $minutes / 60;

        if ($hours < $minHours) {
            return 'too_fast';
        }

        if ($hours > $maxHours) {
            return 'too_long';
        }

        return 'normal';
    }

    public function getNextExpectedStage(LostWaxTree $tree): ?string
    {
        return $tree->nextStage();
    }

    public function formatAgingLabel(?int $minutes): ?string
    {
        if ($minutes === null) {
            return null;
        }

        $hours = intdiv(abs($minutes), 60);
        $mins = abs($minutes) % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours.' jam';
        }
        $parts[] = $mins.' menit';

        return implode(' ', $parts);
    }

    public function processOvenScan(string $barcode, int $operatorId): array
    {
        return DB::transaction(function () use ($barcode, $operatorId) {
            $tree = LostWaxTree::with(['workOrder', 'printOrderLine.printOrder', 'printOrderLine.productionPlan'])
                ->lockForUpdate()
                ->where('barcode', $barcode)
                ->first();

            if (! $tree) {
                return $this->reject('Barcode tidak ditemukan.', $barcode, null, $operatorId);
            }

            if ($tree->current_stage === 'oven') {
                $reason = 'Tree sudah masuk Oven / sudah selesai.';

                return $this->rejectOvenScan($tree, $operatorId, 'oven', $reason);
            }

            $existingOven = LostWaxScanEvent::where('tree_id', $tree->id)
                ->where('stage', 'oven')
                ->where('result', 'success')
                ->whereDoesntHave('void')
                ->exists();

            if ($existingOven) {
                $reason = 'Tree sudah memiliki event Oven.';

                return $this->rejectOvenScan($tree, $operatorId, 'oven', $reason);
            }

            $allowedStages = ['layer_6', 'layer_7'];

            if (! in_array($tree->current_stage, $allowedStages)) {
                $stageLabel = $tree->current_stage_label;

                if ($tree->current_stage === null) {
                    $reason = 'Tree belum memulai proses coating. Tree harus menyelesaikan Lapisan 6 sebelum masuk Oven.';
                } else {
                    $reason = "Tree masih berada di {$stageLabel}. Tree harus menyelesaikan Lapisan 6 terlebih dahulu.";
                }

                return $this->rejectOvenScan($tree, $operatorId, 'oven', $reason);
            }

            $scannedAt = Carbon::now(config('app.timezone'));
            $agingMinutes = null;
            $agingStatus = null;

            if ($tree->last_scan_at) {
                $agingMinutes = (int) round($tree->last_scan_at->diffInMinutes($scannedAt));
                $agingStatus = $this->classifyAging($agingMinutes, $tree->current_stage);
            }

            $event = LostWaxScanEvent::create([
                'tree_id' => $tree->id,
                'barcode' => $barcode,
                'stage' => 'oven',
                'scanned_at' => $scannedAt,
                'operator_id' => $operatorId,
                'result' => 'success',
                'aging_minutes' => $agingMinutes,
                'aging_status' => $agingStatus,
            ]);

            $tree->update([
                'current_stage' => 'oven',
                'last_scan_at' => $scannedAt,
            ]);

            $tree->load(['workOrder.itemReference', 'printOrderLine.printOrder', 'printOrderLine.productionPlan']);

            return [
                'success' => true,
                'tree' => $tree,
                'event' => $event,
                'stage_label' => $event->stage_label,
                'aging_label' => $event->aging_label,
                'aging_status' => $agingStatus,
            ];
        });
    }

    private function rejectOvenScan(LostWaxTree $tree, int $operatorId, string $stage, string $reason): array
    {
        LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => $stage,
            'scanned_at' => Carbon::now(config('app.timezone')),
            'operator_id' => $operatorId,
            'result' => 'rejected',
            'anomaly_reason' => $reason,
        ]);

        $tree->load(['workOrder.itemReference', 'printOrderLine.printOrder', 'printOrderLine.productionPlan']);

        return [
            'success' => false,
            'tree' => $tree,
            'reason' => $reason,
            'current_stage' => $tree->current_stage,
            'current_stage_label' => $tree->current_stage_label,
        ];
    }

    private function reject(string $reason, string $barcode, ?int $treeId, int $operatorId): array
    {
        if ($treeId) {
            LostWaxScanEvent::create([
                'tree_id' => $treeId,
                'barcode' => $barcode,
                'stage' => null,
                'scanned_at' => Carbon::now(config('app.timezone')),
                'operator_id' => $operatorId,
                'result' => 'rejected',
                'anomaly_reason' => $reason,
            ]);
        }

        return [
            'success' => false,
            'reason' => $reason,
        ];
    }
}
