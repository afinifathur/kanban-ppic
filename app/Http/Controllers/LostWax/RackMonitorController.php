<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use App\Services\LostWax\RackMonitorService;
use Illuminate\Http\Request;

class RackMonitorController extends Controller
{
    protected RackMonitorService $rackMonitorService;

    public function __construct(RackMonitorService $rackMonitorService)
    {
        $this->rackMonitorService = $rackMonitorService;
    }

    public function index(Request $request)
    {
        // 1. Get active racks from service
        $activeRacks = $this->rackMonitorService->getActiveRacks();

        // 2. Map and classify each rack with its Presentation State (NORMAL, NEAR_READY, READY, LATE)
        $mappedRacks = array_map(function ($rack) {
            $dominantStage = $rack['dominant_stage'];
            $rackAgeMinutes = $rack['rack_age_minutes'];

            $presentationState = 'NORMAL';
            $remainingMinutes = null;
            $overdueMinutes = null;

            // Default thresholds
            $minHours = (float) config('lost_wax.aging.min_hours', 4);
            $maxHours = (float) config('lost_wax.aging.max_hours', 6);
            $bufferHours = $maxHours;

            if ($dominantStage) {
                $stageConfig = config("lost_wax.aging.stages.{$dominantStage}");
                if ($stageConfig) {
                    $minHours = (float) $stageConfig['min_hours'];
                    $maxHours = (float) $stageConfig['max_hours'];
                    $bufferHours = (float) $stageConfig['buffer_hours'];
                }
            }

            if ($rackAgeMinutes !== null) {
                $minMinutes = $minHours * 60;
                $bufferMinutes = $bufferHours * 60;

                if ($rackAgeMinutes < $minMinutes) {
                    $remainingMinutes = $minMinutes - $rackAgeMinutes;
                    if ($remainingMinutes <= 60) {
                        $presentationState = 'NEAR_READY';
                    } else {
                        $presentationState = 'NORMAL';
                    }
                } elseif ($rackAgeMinutes >= $minMinutes && $rackAgeMinutes <= $bufferMinutes) {
                    $presentationState = 'READY';
                } else {
                    $presentationState = 'LATE';
                    $overdueMinutes = $rackAgeMinutes - $bufferMinutes;
                }
            }

            $rack['presentation_state'] = $presentationState;
            $rack['remaining_minutes'] = $remainingMinutes;
            $rack['overdue_minutes'] = $overdueMinutes;
            $rack['min_hours'] = $minHours;
            $rack['max_hours'] = $maxHours;
            $rack['buffer_hours'] = $bufferHours;

            return $rack;
        }, $activeRacks);

        // 3. Sort mapped racks by priority: LATE -> READY -> NEAR_READY -> NORMAL
        // Within same group, oldest age first (descending rack_age_minutes)
        usort($mappedRacks, function ($a, $b) {
            $priority = [
                'LATE' => 0,
                'READY' => 1,
                'NEAR_READY' => 2,
                'NORMAL' => 3,
            ];
            $orderA = $priority[$a['presentation_state']] ?? 3;
            $orderB = $priority[$b['presentation_state']] ?? 3;
            if ($orderA !== $orderB) {
                return $orderA <=> $orderB;
            }

            return ($b['rack_age_minutes'] ?? 0) <=> ($a['rack_age_minutes'] ?? 0);
        });

        // 4. Calculate Summary Bar counts
        $summary = [
            'total_active' => count($mappedRacks),
            'late' => count(array_filter($mappedRacks, fn ($r) => $r['presentation_state'] === 'LATE')),
            'ready' => count(array_filter($mappedRacks, fn ($r) => $r['presentation_state'] === 'READY')),
            'near_ready' => count(array_filter($mappedRacks, fn ($r) => $r['presentation_state'] === 'NEAR_READY')),
            'normal' => count(array_filter($mappedRacks, fn ($r) => $r['presentation_state'] === 'NORMAL')),
            'split' => count(array_filter($mappedRacks, fn ($r) => $r['is_layer7_split'])),
            'unassigned' => $this->rackMonitorService->getUnassignedTreeCount(),
        ];

        // 5. Build Stage and Aging Monitor table rows
        $stages = config('lost_wax.stages', []);
        $stageReport = [];

        // Add 'Sebelum Scan' as a virtual stage at the beginning
        $stageReport['sebelum_scan'] = [
            'label' => 'Sebelum Scan',
            'rack_count' => 0,
            'normal' => 0,
            'ready' => 0,
            'late' => 0,
        ];

        foreach ($stages as $stageKey => $stageLabel) {
            $stageReport[$stageKey] = [
                'label' => $stageLabel,
                'rack_count' => 0,
                'normal' => 0,
                'ready' => 0,
                'late' => 0,
            ];
        }

        foreach ($mappedRacks as $rack) {
            $stageKey = $rack['dominant_stage'] ?: 'sebelum_scan';
            if (! isset($stageReport[$stageKey])) {
                $stageReport[$stageKey] = [
                    'label' => ucfirst(str_replace('_', ' ', $stageKey)),
                    'rack_count' => 0,
                    'normal' => 0,
                    'ready' => 0,
                    'late' => 0,
                ];
            }

            $stageReport[$stageKey]['rack_count']++;
            if ($rack['presentation_state'] === 'LATE') {
                $stageReport[$stageKey]['late']++;
            } elseif ($rack['presentation_state'] === 'READY') {
                $stageReport[$stageKey]['ready']++;
            } else {
                $stageReport[$stageKey]['normal']++;
            }
        }

        return view('lost-wax.rack-monitor.index', [
            'racks' => $mappedRacks,
            'summary' => $summary,
            'stageReport' => $stageReport,
        ]);
    }
}
