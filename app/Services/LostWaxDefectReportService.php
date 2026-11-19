<?php

namespace App\Services;

use App\Models\LostWaxPrintExecution;
use App\Models\LostWaxTreeDefect;
use App\Models\User;
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

    public const CONTEXTS = [
        'all' => 'Semua Konteks',
        LostWaxTreeDefect::CONTEXT_PRE_SIAP_COR => 'Pra-Siap Cor',
        LostWaxTreeDefect::CONTEXT_POST_SIAP_COR => 'Pasca-Siap Cor',
    ];

    /**
     * Get the canonical defect dataset for the daily report, summary, and exports.
     *
     * @param  array{date_from?: string, date_to?: string, stage?: string, defect_context?: string, search?: string, production_code?: string, mode?: string}  $filters
     * @return array{items: Collection, summary: array<string, int>, filters: array}
     */
    public function getDefectDataset(array $filters = [], ?User $user = null): array
    {
        $currentUser = $user ?? auth()->user();
        $isPpic = $currentUser?->hasRole('ppic');
        $scope = ($isPpic && $currentUser?->product_scope) ? $currentUser->product_scope : null;

        $todayWib = Carbon::now('Asia/Jakarta')->format('Y-m-d');
        $dateFrom = ! empty($filters['date_from']) ? (string) $filters['date_from'] : $todayWib;
        $dateTo = ! empty($filters['date_to']) ? (string) $filters['date_to'] : $todayWib;
        $selectedStage = ! empty($filters['stage']) ? (string) $filters['stage'] : 'all';
        $rawContext = ! empty($filters['defect_context']) ? (string) $filters['defect_context'] : 'all';
        $selectedContext = array_key_exists($rawContext, self::CONTEXTS) ? $rawContext : 'all';
        $search = trim((string) ($filters['search'] ?? ''));
        $searchLower = strtolower($search);
        $productionCodeFilter = trim((string) ($filters['production_code'] ?? ''));
        $mode = in_array(strtolower($filters['mode'] ?? 'ringkas'), ['ringkas', 'detail'], true)
            ? strtolower($filters['mode'] ?? 'ringkas')
            : 'ringkas';

        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        // ±1 day SQL buffer for deterministic Asia/Jakarta effective_defect_date filtering in PHP
        $sqlBufferStart = Carbon::createFromFormat('!Y-m-d', $dateFrom, 'Asia/Jakarta')
            ->subDay()
            ->startOfDay()
            ->format('Y-m-d H:i:s');
        $sqlBufferEnd = Carbon::createFromFormat('!Y-m-d', $dateTo, 'Asia/Jakarta')
            ->addDay()
            ->endOfDay()
            ->format('Y-m-d H:i:s');

        $allRawItems = collect();

        // 1. Fetch Cetak Defects (from lost_wax_print_executions — always pre_siap_cor)
        if (($selectedStage === 'all' || $selectedStage === 'cetak') && $selectedContext !== LostWaxTreeDefect::CONTEXT_POST_SIAP_COR) {
            $printExecQuery = LostWaxPrintExecution::with([
                'printOrderLine.productionPlan',
                'printOrderLine.printOrder',
                'recorder',
            ])
                ->where('status', 'FINALIZED')
                ->where('qty_defect', '>', 0)
                ->where(function ($q) use ($dateFrom, $dateTo) {
                    $q->whereDate('execution_date', '>=', $dateFrom)
                        ->whereDate('execution_date', '<=', $dateTo);
                });

            if ($scope) {
                $printExecQuery->whereHas('printOrderLine.productionPlan', function ($q) use ($scope) {
                    $q->where('product_scope', $scope);
                });
            }

            $printExecutions = $printExecQuery->get();

            foreach ($printExecutions as $exec) {
                $line = $exec->printOrderLine;
                $prodCode = $line?->productionPlan?->code ?? $line?->code ?? '-';
                $itemName = $line?->item_name ?? $line?->productionPlan?->item_name ?? '-';
                $occurredAt = $exec->execution_date
                    ? Carbon::parse($exec->execution_date, 'Asia/Jakarta')->startOfDay()
                    : null;
                $effectiveAt = $occurredAt ?? $exec->created_at?->copy()->setTimezone('Asia/Jakarta');

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
                    'defect_context' => LostWaxTreeDefect::CONTEXT_PRE_SIAP_COR,
                    'context_label' => 'Pra-Siap Cor',
                    'is_late_entry' => false,
                    'scan_siap_cor_at' => null,
                    'defect_qty' => (int) $exec->qty_defect,
                    'defect_reason' => $exec->notes ?: 'Cacat Injeksi Lilin',
                    'notes' => $exec->notes,
                    'operator' => $exec->recorder?->name ?? 'System',
                    'occurred_at' => $occurredAt,
                    'effective_at' => $effectiveAt,
                    'created_at' => $exec->created_at,
                ]);
            }
        }

        // 2. Fetch Tree Defects (from lost_wax_tree_defects: assembly, layer_1..7, oven; active/non-void only)
        if ($selectedStage !== 'cetak') {
            $treeDefectsQuery = LostWaxTreeDefect::with([
                'tree.printOrderLine.productionPlan',
                'tree.printOrderLine.printOrder',
                'tree.workOrder.itemReference',
                'tree.allocations.printOrderLine.productionPlan',
                'recordedBy',
                'scanEvent',
            ])
                ->whereNull('voided_at')
                ->where('defect_qty', '>', 0)
                ->whereRaw('COALESCE(occurred_at, created_at) >= ?', [$sqlBufferStart])
                ->whereRaw('COALESCE(occurred_at, created_at) <= ?', [$sqlBufferEnd]);

            if ($selectedStage !== 'all') {
                $treeDefectsQuery->where('stage', $selectedStage);
            }

            if ($selectedContext === LostWaxTreeDefect::CONTEXT_POST_SIAP_COR) {
                $treeDefectsQuery->where('defect_context', LostWaxTreeDefect::CONTEXT_POST_SIAP_COR);
            } elseif ($selectedContext === LostWaxTreeDefect::CONTEXT_PRE_SIAP_COR) {
                $treeDefectsQuery->where(function ($q) {
                    $q->whereNull('defect_context')
                        ->orWhere('defect_context', LostWaxTreeDefect::CONTEXT_PRE_SIAP_COR);
                });
            }

            if ($scope) {
                $treeDefectsQuery->whereHas('tree', function ($t) use ($scope) {
                    $t->where(function ($q) use ($scope) {
                        $q->whereHas('printOrderLine.productionPlan', function ($p) use ($scope) {
                            $p->where('product_scope', $scope);
                        })->orWhereHas('allocations.printOrderLine.productionPlan', function ($p) use ($scope) {
                            $p->where('product_scope', $scope);
                        })->orWhereHas('workOrder', function ($w) use ($scope) {
                            if ($scope === 'FLANGE_STAINLESS') {
                                $w->whereIn('family_code', ['3', '4']);
                            } elseif ($scope === 'FLANGE_BESI') {
                                $w->whereIn('family_code', ['6']);
                            } elseif ($scope === 'FITTING_STAINLESS') {
                                $w->whereIn('family_code', ['1', '2']);
                            } else {
                                $w->whereRaw('1=0');
                            }
                        });
                    });
                });
            }

            $treeDefects = $treeDefectsQuery->get();

            foreach ($treeDefects as $defect) {
                // Canonical effective_defect_date in Asia/Jakarta (independent of DB session timezone)
                $effectiveAtWib = $defect->effective_at_wib;
                $effectiveDateWib = $effectiveAtWib?->format('Y-m-d');
                if (! $effectiveDateWib || $effectiveDateWib < $dateFrom || $effectiveDateWib > $dateTo) {
                    continue;
                }

                $tree = $defect->tree;
                $prodCode = $tree?->printOrderLine?->productionPlan?->code
                    ?? $tree?->getSourceCode()
                    ?? '-';
                $itemName = $tree?->getSourceProduct()
                    ?? $tree?->printOrderLine?->item_name
                    ?? '-';

                $reasonLabel = ucwords(str_replace('_', ' ', (string) $defect->defect_reason));
                $context = $defect->defect_context ?? LostWaxTreeDefect::CONTEXT_PRE_SIAP_COR;

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
                    'defect_context' => $context,
                    'context_label' => $defect->context_label,
                    'is_late_entry' => (bool) $defect->is_late_entry,
                    'scan_siap_cor_at' => $defect->scanEvent?->scanned_at,
                    'defect_qty' => (int) $defect->defect_qty,
                    'defect_reason' => $reasonLabel,
                    'notes' => $defect->notes,
                    'operator' => $defect->recordedBy?->name ?? 'System',
                    'occurred_at' => $defect->occurred_at,
                    'effective_at' => $effectiveAtWib,
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
                    || str_contains(strtolower($item['defect_reason']), $searchLower)
                    || str_contains(strtolower($item['context_label'] ?? ''), $searchLower);
            });
        }

        // 5. Calculate KPI Summaries across all stages matching active filters
        $ovenItems = $allRawItems->where('stage', 'oven');
        $ovenPreSiapCor = (int) $ovenItems
            ->filter(fn ($i) => ($i['defect_context'] ?? LostWaxTreeDefect::CONTEXT_PRE_SIAP_COR) !== LostWaxTreeDefect::CONTEXT_POST_SIAP_COR)
            ->sum('defect_qty');
        $ovenPostSiapCor = (int) $ovenItems
            ->where('defect_context', LostWaxTreeDefect::CONTEXT_POST_SIAP_COR)
            ->sum('defect_qty');

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
            'oven' => $ovenPreSiapCor + $ovenPostSiapCor,
            'oven_pre_siap_cor' => $ovenPreSiapCor,
            'oven_post_siap_cor' => $ovenPostSiapCor,
            'total_layers' => (int) $allRawItems->filter(fn ($i) => str_starts_with($i['stage'], 'layer_'))->sum('defect_qty'),
            'grand_total' => (int) $allRawItems->sum('defect_qty'),
            'total_records' => $allRawItems->count(),
        ];

        // 6. Build Display Items according to Mode
        if ($mode === 'detail') {
            $items = $allRawItems->sortBy([
                ['effective_at', 'desc'],
                ['production_code', 'asc'],
            ])->values();
        } else {
            // Mode Ringkas: Group by Production Code + Stage
            $items = $allRawItems->groupBy(function ($item) {
                return $item['production_code'].'__'.$item['stage'];
            })->map(function (Collection $group) {
                $first = $group->first();
                $postQty = (int) $group->where('defect_context', LostWaxTreeDefect::CONTEXT_POST_SIAP_COR)->sum('defect_qty');
                $totalQty = (int) $group->sum('defect_qty');

                return [
                    'production_code' => $first['production_code'],
                    'item_name' => $first['item_name'],
                    'stage' => $first['stage'],
                    'stage_label' => $first['stage_label'],
                    'stage_order' => $first['stage_order'],
                    'defect_qty' => $totalQty,
                    'pre_siap_cor_qty' => $totalQty - $postQty,
                    'post_siap_cor_qty' => $postQty,
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
                'defect_context' => $selectedContext,
                'search' => $search,
                'production_code' => $productionCodeFilter,
                'mode' => $mode,
            ],
        ];
    }
}
