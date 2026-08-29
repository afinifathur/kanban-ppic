<?php

namespace App\Services;

use App\Models\LostWaxPrintExecution;
use App\Models\LostWaxTreeDefect;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LostWaxDefectReportService
{
    public const STAGES = [
        'cetak' => 'Cetak',
        'assembly' => 'Rangkai',
        'layer_1' => 'Lapisan 1',
        'layer_2' => 'Lapisan 2',
        'layer_3' => 'Lapisan 3',
        'layer_4' => 'Lapisan 4',
        'layer_5' => 'Lapisan 5',
        'layer_6' => 'Lapisan 6',
        'layer_7' => 'Lapisan 7',
        'oven' => 'Oven',
    ];

    public static function getStageOrder(string $stage): int
    {
        $order = [
            'cetak' => 1,
            'assembly' => 2,
            'layer_1' => 3,
            'layer_2' => 4,
            'layer_3' => 5,
            'layer_4' => 6,
            'layer_5' => 7,
            'layer_6' => 8,
            'layer_7' => 9,
            'oven' => 10,
        ];

        return $order[$stage] ?? 99;
    }

    /**
     * Get the canonical defect dataset for the daily report, summary, and exports.
     *
     * @param  array{date_from?: string, date_to?: string, stage?: string, search?: string, production_code?: string, mode?: string}  $filters
     * @return array{items: Collection, summary: array<string, int>, filters: array}
     */
    public function getDefectDataset(array $filters = []): array
    {
        $dateFrom = ! empty($filters['date_from']) ? $filters['date_from'] : date('Y-m-d');
        $dateTo = ! empty($filters['date_to']) ? $filters['date_to'] : date('Y-m-d');
        $selectedStage = ! empty($filters['stage']) ? $filters['stage'] : 'all';
        $search = trim((string) ($filters['search'] ?? ''));
        $searchLower = strtolower($search);
        $productionCodeFilter = trim((string) ($filters['production_code'] ?? ''));
        $mode = in_array(strtolower($filters['mode'] ?? 'ringkas'), ['ringkas', 'detail'], true)
            ? strtolower($filters['mode'] ?? 'ringkas')
            : 'ringkas';

        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $allRawItems = collect();

        // 1. Fetch Cetak Defects (from lost_wax_print_executions)
        if ($selectedStage === 'all' || $selectedStage === 'cetak') {
            $printExecutions = LostWaxPrintExecution::with([
                'printOrderLine.productionPlan',
                'printOrderLine.printOrder',
                'recorder',
            ])
                ->where('status', 'FINALIZED')
                ->where('qty_defect', '>', 0)
                ->where(function ($q) use ($dateFrom, $dateTo) {
                    $q->whereDate('execution_date', '>=', $dateFrom)
                        ->whereDate('execution_date', '<=', $dateTo);
                })
                ->get();

            foreach ($printExecutions as $exec) {
                $line = $exec->printOrderLine;
                $prodCode = $line?->productionPlan?->code ?? $line?->code ?? '-';
                $itemName = $line?->item_name ?? $line?->productionPlan?->item_name ?? '-';

                $allRawItems->push([
                    'id' => 'cetak_'.$exec->id,
                    'raw_id' => $exec->id,
                    'type' => 'cetak',
                    'production_code' => (string) $prodCode,
                    'item_name' => (string) $itemName,
                    'barcode' => '-',
                    'stage' => 'cetak',
                    'stage_label' => 'Cetak',
                    'stage_order' => self::getStageOrder('cetak'),
                    'defect_qty' => (int) $exec->qty_defect,
                    'defect_reason' => $exec->notes ?: 'Cacat Injeksi Lilin',
                    'notes' => $exec->notes,
                    'operator' => $exec->recorder?->name ?? 'System',
                    'occurred_at' => $exec->execution_date ? Carbon::parse($exec->execution_date)->startOfDay() : $exec->created_at,
                    'created_at' => $exec->created_at,
                ]);
            }
        }

        // 2. Fetch Tree Defects (from lost_wax_tree_defects: assembly, layer_1..7, oven)
        if ($selectedStage !== 'cetak') {
            $treeDefectsQuery = LostWaxTreeDefect::with([
                'tree.printOrderLine.productionPlan',
                'tree.printOrderLine.printOrder',
                'tree.workOrder.itemReference',
                'tree.allocations.printOrderLine.productionPlan',
                'recordedBy',
            ])
                ->where('defect_qty', '>', 0)
                ->where(function ($q) use ($dateFrom, $dateTo) {
                    $q->whereDate(DB::raw('COALESCE(occurred_at, created_at)'), '>=', $dateFrom)
                        ->whereDate(DB::raw('COALESCE(occurred_at, created_at)'), '<=', $dateTo);
                });

            if ($selectedStage !== 'all') {
                $treeDefectsQuery->where('stage', $selectedStage);
            }

            $treeDefects = $treeDefectsQuery->get();

            foreach ($treeDefects as $defect) {
                $tree = $defect->tree;
                $prodCode = $tree?->printOrderLine?->productionPlan?->code
                    ?? $tree?->getSourceCode()
                    ?? '-';
                $itemName = $tree?->getSourceProduct()
                    ?? $tree?->printOrderLine?->item_name
                    ?? '-';

                $reasonLabel = ucwords(str_replace('_', ' ', (string) $defect->defect_reason));

                $allRawItems->push([
                    'id' => 'tree_'.$defect->id,
                    'raw_id' => $defect->id,
                    'type' => 'tree',
                    'production_code' => (string) $prodCode,
                    'item_name' => (string) $itemName,
                    'barcode' => (string) ($tree?->barcode ?? '-'),
                    'stage' => $defect->stage,
                    'stage_label' => $defect->stage_label,
                    'stage_order' => self::getStageOrder($defect->stage),
                    'defect_qty' => (int) $defect->defect_qty,
                    'defect_reason' => $reasonLabel,
                    'notes' => $defect->notes,
                    'operator' => $defect->recordedBy?->name ?? 'System',
                    'occurred_at' => $defect->occurred_at ?? $defect->created_at,
                    'created_at' => $defect->created_at,
                ]);
            }
        }

        // 3. Apply Production Code Drill-down filter
        if ($productionCodeFilter !== '') {
            $allRawItems = $allRawItems->filter(function ($item) use ($productionCodeFilter) {
                return strcasecmp($item['production_code'], $productionCodeFilter) === 0;
            });
        }

        // 4. Apply Search Filter
        if ($searchLower !== '') {
            $allRawItems = $allRawItems->filter(function ($item) use ($searchLower) {
                return str_contains(strtolower($item['production_code']), $searchLower)
                    || str_contains(strtolower($item['item_name']), $searchLower)
                    || str_contains(strtolower($item['barcode']), $searchLower)
                    || str_contains(strtolower($item['defect_reason']), $searchLower);
            });
        }

        // 5. Calculate KPI Summaries across all stages matching active filters
        $summary = [
            'cetak' => (int) $allRawItems->where('stage', 'cetak')->sum('defect_qty'),
            'assembly' => (int) $allRawItems->where('stage', 'assembly')->sum('defect_qty'),
            'layer_1' => (int) $allRawItems->where('stage', 'layer_1')->sum('defect_qty'),
            'layer_2' => (int) $allRawItems->where('stage', 'layer_2')->sum('defect_qty'),
            'layer_3' => (int) $allRawItems->where('stage', 'layer_3')->sum('defect_qty'),
            'layer_4' => (int) $allRawItems->where('stage', 'layer_4')->sum('defect_qty'),
            'layer_5' => (int) $allRawItems->where('stage', 'layer_5')->sum('defect_qty'),
            'layer_6' => (int) $allRawItems->where('stage', 'layer_6')->sum('defect_qty'),
            'layer_7' => (int) $allRawItems->where('stage', 'layer_7')->sum('defect_qty'),
            'oven' => (int) $allRawItems->where('stage', 'oven')->sum('defect_qty'),
            'total_layers' => (int) $allRawItems->filter(fn ($i) => str_starts_with($i['stage'], 'layer_'))->sum('defect_qty'),
            'grand_total' => (int) $allRawItems->sum('defect_qty'),
            'total_records' => $allRawItems->count(),
        ];

        // 6. Build Display Items according to Mode
        if ($mode === 'detail') {
            $items = $allRawItems->sortBy([
                ['occurred_at', 'desc'],
                ['production_code', 'asc'],
            ])->values();
        } else {
            // Mode Ringkas: Group by Production Code + Stage
            $items = $allRawItems->groupBy(function ($item) {
                return $item['production_code'].'__'.$item['stage'];
            })->map(function (Collection $group) {
                $first = $group->first();

                return [
                    'production_code' => $first['production_code'],
                    'item_name' => $first['item_name'],
                    'stage' => $first['stage'],
                    'stage_label' => $first['stage_label'],
                    'stage_order' => $first['stage_order'],
                    'defect_qty' => (int) $group->sum('defect_qty'),
                    'record_count' => $group->count(),
                ];
            })->sortBy([
                ['production_code', 'asc'],
                ['stage_order', 'asc'],
            ])->values();
        }

        return [
            'items' => $items,
            'summary' => $summary,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'stage' => $selectedStage,
                'search' => $search,
                'production_code' => $productionCodeFilter,
                'mode' => $mode,
            ],
        ];
    }
}
