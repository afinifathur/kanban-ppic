<?php

namespace App\Services\LostWax;

use App\Models\LostWaxCoatingRack;
use App\Models\LostWaxTree;
use Carbon\Carbon;

class RackMonitorService
{
    /**
     * Allowed stages for trees physically residing on a coating rack.
     */
    public const RACK_STAGES = [
        'layer_1',
        'layer_2',
        'layer_3',
        'layer_4',
        'layer_5',
        'layer_6',
        'layer_7',
    ];

    /**
     * Get aggregated information for all active coating racks that contain at least one tree on Layer 1-7.
     */
    public function getActiveRacks(): array
    {
        // Fetch all trees that are currently on Layer 1-7 assigned to active coating racks
        $trees = LostWaxTree::whereNotNull('rack_id')
            ->whereIn('current_stage', self::RACK_STAGES)
            ->whereHas('coatingRack', function ($query) {
                $query->where('status', 'active');
            })
            ->with(['coatingRack', 'printOrderLine', 'workOrder.itemReference'])
            ->get();

        if ($trees->isEmpty()) {
            return [];
        }

        // Group trees by rack_id
        $grouped = $trees->groupBy('rack_id');

        $result = [];

        foreach ($grouped as $rackId => $treesInRack) {
            $rack = $treesInRack->first()->coatingRack;
            if (! $rack) {
                continue;
            }

            $result[] = $this->aggregateRack($rack, $treesInRack);
        }

        // Sort the result: late first, then ready, then normal. Within groups, sort by rack_age_minutes desc.
        usort($result, function ($a, $b) {
            $statusOrder = ['late' => 0, 'ready' => 1, 'normal' => 2];
            $orderA = $statusOrder[$a['aging_status']] ?? 2;
            $orderB = $statusOrder[$b['aging_status']] ?? 2;

            if ($orderA !== $orderB) {
                return $orderA <=> $orderB;
            }

            $ageA = $a['rack_age_minutes'] ?? -1;
            $ageB = $b['rack_age_minutes'] ?? -1;

            return $ageB <=> $ageA;
        });

        return $result;
    }

    /**
     * Get detail of a specific rack containing trees on Layer 1-7.
     */
    public function getRackDetail(int $rackId): ?array
    {
        $rack = LostWaxCoatingRack::where('id', $rackId)
            ->where('status', 'active')
            ->first();

        if (! $rack) {
            return null;
        }

        $trees = LostWaxTree::where('rack_id', $rackId)
            ->whereIn('current_stage', self::RACK_STAGES)
            ->with(['coatingRack', 'printOrderLine', 'workOrder.itemReference'])
            ->get();
        if ($trees->isEmpty()) {
            return null;
        }

        return $this->aggregateRack($rack, $trees);
    }

    /**
     * Count unassigned trees (trees with no rack_id assigned and not completed/in oven).
     */
    public function getUnassignedTreeCount(): int
    {
        return LostWaxTree::whereNull('rack_id')
            ->where(function ($query) {
                $query->whereNull('current_stage')
                    ->orWhereIn('current_stage', self::RACK_STAGES);
            })
            ->count();
    }

    /**
     * Aggregate trees assigned to a rack to determine dominant stage, age, split status, and aging status.
     *
     * @param  \Illuminate\Support\Collection  $treesInRack
     */
    private function aggregateRack(LostWaxCoatingRack $rack, $treesInRack): array
    {
        $treeCount = $treesInRack->count();
        $totalQuantity = $treesInRack->sum('quantity');

        // Build stage distribution for physical rack stages
        $distribution = [
            'layer_1' => 0,
            'layer_2' => 0,
            'layer_3' => 0,
            'layer_4' => 0,
            'layer_5' => 0,
            'layer_6' => 0,
            'layer_7' => 0,
        ];

        $distinctStages = [];
        $stageCounts = [];

        foreach ($treesInRack as $tree) {
            $stageKey = $tree->current_stage;
            if ($stageKey) {
                $distribution[$stageKey] = ($distribution[$stageKey] ?? 0) + 1;
                $distinctStages[$stageKey] = true;
                $stageCounts[$stageKey] = ($stageCounts[$stageKey] ?? 0) + 1;
            }
        }

        $isMixed = count($distinctStages) > 1;

        // Determine dominant stage with deterministic tie-breaker
        $stagesOrder = array_keys(config('lost_wax.stages', []));
        $dominantStage = null;
        $maxCount = -1;

        foreach ($stageCounts as $stage => $count) {
            if ($count > $maxCount) {
                $maxCount = $count;
                $dominantStage = $stage;
            } elseif ($count === $maxCount) {
                $idx1 = array_search($stage, $stagesOrder);
                $idx2 = array_search($dominantStage, $stagesOrder);

                $idx1 = $idx1 === false ? 999 : $idx1;
                $idx2 = $idx2 === false ? 999 : $idx2;

                if ($idx1 < $idx2) {
                    $dominantStage = $stage;
                }
            }
        }

        $dominantStageResult = $dominantStage;

        // Calculate rack_stage_started_at (MAX of valid scanned_at of trees in the dominant stage)
        $rackStageStartedAt = null;
        if ($dominantStageResult !== null) {
            foreach ($treesInRack as $tree) {
                if ($tree->current_stage === $dominantStageResult && $tree->last_scan_at) {
                    if ($rackStageStartedAt === null || $tree->last_scan_at->gt($rackStageStartedAt)) {
                        $rackStageStartedAt = $tree->last_scan_at;
                    }
                }
            }
        }

        // Calculate rack_age_minutes and aging_status
        $rackAgeMinutes = null;
        $agingStatus = 'normal';

        if ($rackStageStartedAt !== null) {
            $now = Carbon::now(config('app.timezone'));
            $rackAgeMinutes = (int) round($rackStageStartedAt->diffInMinutes($now));
            $ageHours = $rackAgeMinutes / 60.0;

            $stageConfig = config("lost_wax.aging.stages.{$dominantStageResult}");
            if ($stageConfig) {
                $minHours = (float) $stageConfig['min_hours'];
                $maxHours = (float) $stageConfig['max_hours'];
                $bufferHours = (float) $stageConfig['buffer_hours'];
            } else {
                $minHours = (float) config('lost_wax.aging.min_hours', 4);
                $maxHours = (float) config('lost_wax.aging.max_hours', 6);
                $bufferHours = $maxHours;
            }

            if ($ageHours < $minHours) {
                $agingStatus = 'normal';
            } elseif ($ageHours >= $minHours && $ageHours <= $bufferHours) {
                $agingStatus = 'ready';
            } else {
                $agingStatus = 'late';
            }
        }

        // Layer 7 split detection
        $isLayer7Split = false;
        $hasLayer7 = ($distribution['layer_7'] ?? 0) > 0;
        $hasLayer6 = ($distribution['layer_6'] ?? 0) > 0;

        if ($hasLayer7 && ($hasLayer6 || count(array_filter($distribution)) > 1)) {
            $isLayer7Split = true;
        }

        $productionCodes = [];
        $itemNames = [];
        $treesDetail = [];
        foreach ($treesInRack as $tree) {
            $code = $tree->getSourceCode() ?: 'UNKNOWN';
            $itemName = $tree->getSourceProduct() ?: 'UNKNOWN';

            $productionCodes[$code] = ($productionCodes[$code] ?? 0) + 1;
            $itemNames[$itemName] = ($itemNames[$itemName] ?? 0) + 1;

            $cleanBarcode = preg_replace('/[\s\-]/', '', (string) $tree->barcode);

            $treesDetail[] = [
                'id' => $tree->id,
                'barcode' => $cleanBarcode,
                'human_barcode' => $cleanBarcode,
                'quantity' => $tree->quantity,
                'current_stage' => $tree->current_stage,
                'current_stage_label' => $tree->current_stage_label,
                'last_scan_at' => $tree->last_scan_at ? $tree->last_scan_at->toIso8601String() : null,
                'production_code' => $code,
                'item_name' => $itemName,
            ];
        }

        return [
            'rack_id' => $rack->id,
            'rack_number' => $rack->rack_number,
            'rack_label' => $rack->label ?: 'RAK-'.str_pad($rack->rack_number, 2, '0', STR_PAD_LEFT),
            'tree_count' => $treeCount,
            'total_quantity' => $totalQuantity,
            'dominant_stage' => $dominantStageResult,
            'stage_distribution' => $distribution,
            'rack_stage_started_at' => $rackStageStartedAt,
            'rack_age_minutes' => $rackAgeMinutes,
            'aging_status' => $agingStatus,
            'is_mixed' => $isMixed,
            'is_layer7_split' => $isLayer7Split,
            'trees_without_stage' => 0,
            'production_codes' => $productionCodes,
            'item_names' => $itemNames,
            'trees' => $treesDetail,
        ];
    }
}
