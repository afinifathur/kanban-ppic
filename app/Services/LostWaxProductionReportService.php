<?php

namespace App\Services;

use App\Models\LostWaxPrintExecution;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\LostWaxTreeDefect;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LostWaxProductionReportService
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
     * Get the canonical normalized production report dataset scoped by user permissions.
     *
     * @param  array{date_from?: string, date_to?: string, stage?: string, search?: string}  $filters
     * @return array{items: Collection, summary: array{total_qty: int, total_weight: float, total_defect: int, total_records: int}, filters: array}
     */
    public function getProductionDataset(array $filters = [], ?User $user = null): array
    {
        $currentUser = $user ?? auth()->user();
        $isPpic = $currentUser?->hasRole('ppic');
        $scope = ($isPpic && $currentUser?->product_scope) ? $currentUser->product_scope : null;

        $todayWib = Carbon::now('Asia/Jakarta')->format('Y-m-d');
        $dateFrom = ! empty($filters['date_from']) ? (string) $filters['date_from'] : $todayWib;
        $dateTo = ! empty($filters['date_to']) ? (string) $filters['date_to'] : $todayWib;
        $selectedStage = ! empty($filters['stage']) ? (string) $filters['stage'] : 'all';
        $search = trim((string) ($filters['search'] ?? ''));
        $searchLower = strtolower($search);

        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $sqlBufferStart = Carbon::createFromFormat('!Y-m-d', $dateFrom, 'Asia/Jakarta')
            ->subDay()
            ->startOfDay()
            ->format('Y-m-d H:i:s');
        $sqlBufferEnd = Carbon::createFromFormat('!Y-m-d', $dateTo, 'Asia/Jakarta')
            ->addDay()
            ->endOfDay()
            ->format('Y-m-d H:i:s');

        $rawEntries = collect();
        $defectsMap = [];
        $defectMetadata = [];

        // -------------------------------------------------------------
        // 1. STAGE: CETAK (LostWaxPrintExecution)
        // -------------------------------------------------------------
        if ($selectedStage === 'all' || $selectedStage === 'cetak') {
            $printExecQuery = LostWaxPrintExecution::with([
                'printOrderLine.productionPlan',
                'printOrderLine.printOrder',
            ])
                ->where('status', 'FINALIZED')
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
                $plan = $line?->productionPlan;
                $prodCode = $plan?->code ?? $line?->code ?? '-';
                $customerCode = $plan?->customer ?? $line?->customer ?? '-';
                $itemName = $plan?->item_name ?? $line?->item_name ?? '-';
                $weight = (float) ($plan?->weight ?? 0.0);

                $key = ((string) $prodCode).'__cetak';
                $defectsMap[$key] = ($defectsMap[$key] ?? 0) + (int) $exec->qty_defect;

                $rawEntries->push([
                    'stage' => 'cetak',
                    'stage_label' => 'Cetak',
                    'stage_order' => self::getStageOrder('cetak'),
                    'production_code' => (string) $prodCode,
                    'customer_code' => (string) $customerCode,
                    'item_name' => (string) $itemName,
                    'barcode' => null,
                    'weight' => $weight,
                    'qty_processed' => (int) $exec->qty_good,
                ]);
            }
        }

        // -------------------------------------------------------------
        // 2. STAGE: RANGKAI / ASSEMBLY (LostWaxTree)
        // -------------------------------------------------------------
        if ($selectedStage === 'all' || $selectedStage === 'assembly') {
            $treesQuery = LostWaxTree::with([
                'printOrderLine.productionPlan',
                'workOrder.itemReference',
                'allocations.printOrderLine.productionPlan',
            ])
                ->where('status', '!=', 'cancelled')
                ->where(function ($q) use ($dateFrom, $dateTo) {
                    $q->whereDate(DB::raw('COALESCE(production_date, created_at)'), '>=', $dateFrom)
                        ->whereDate(DB::raw('COALESCE(production_date, created_at)'), '<=', $dateTo);
                });

            if ($scope) {
                $treesQuery->where(function ($q) use ($scope) {
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
            }

            $trees = $treesQuery->get();

            foreach ($trees as $tree) {
                $treeBarcode = (string) ($tree->barcode ?: ($tree->id ? 'TREE-'.$tree->id : null));

                if ($tree->allocations->isNotEmpty()) {
                    foreach ($tree->allocations as $alloc) {
                        $line = $alloc->printOrderLine;
                        $plan = $line?->productionPlan;

                        // Extra guard if tree is multi-allocation with cross-scope lines
                        if ($scope && $plan && $plan->product_scope !== $scope) {
                            continue;
                        }

                        $prodCode = $plan?->code ?? $line?->code ?? '-';
                        $customerCode = $plan?->customer ?? $line?->customer ?? '-';
                        $itemName = $plan?->item_name ?? $line?->item_name ?? '-';
                        $weight = (float) ($plan?->weight ?? 0.0);

                        $rawEntries->push([
                            'stage' => 'assembly',
                            'stage_label' => 'Rangkai',
                            'stage_order' => self::getStageOrder('assembly'),
                            'production_code' => (string) $prodCode,
                            'customer_code' => (string) $customerCode,
                            'item_name' => (string) $itemName,
                            'barcode' => $treeBarcode,
                            'weight' => $weight,
                            'qty_processed' => (int) $alloc->allocated_qty,
                        ]);
                    }
                } else {
                    $line = $tree->printOrderLine;
                    $plan = $line?->productionPlan;
                    $wo = $tree->workOrder;

                    if ($scope && $plan && $plan->product_scope !== $scope) {
                        continue;
                    }

                    $prodCode = $plan?->code ?? $tree->getSourceCode() ?? '-';
                    $customerCode = $plan?->customer ?? $tree->getSourceCustomer() ?? '-';
                    $itemName = $plan?->item_name ?? $tree->getSourceProduct() ?? '-';
                    $weight = (float) ($plan?->weight ?? $wo?->itemReference?->unit_weight_snapshot ?? 0.0);

                    $rawEntries->push([
                        'stage' => 'assembly',
                        'stage_label' => 'Rangkai',
                        'stage_order' => self::getStageOrder('assembly'),
                        'production_code' => (string) $prodCode,
                        'customer_code' => (string) $customerCode,
                        'item_name' => (string) $itemName,
                        'barcode' => $treeBarcode,
                        'weight' => $weight,
                        'qty_processed' => (int) $tree->quantity,
                    ]);
                }
            }
        }

        // -------------------------------------------------------------
        // 3. STAGES: LAPISAN 1-7 & OVEN (LostWaxScanEvent)
        // -------------------------------------------------------------
        $scanStages = [];
        if ($selectedStage === 'all') {
            $scanStages = ['layer_1', 'layer_2', 'layer_3', 'layer_4', 'layer_5', 'layer_6', 'layer_7', 'oven'];
        } elseif (in_array($selectedStage, ['layer_1', 'layer_2', 'layer_3', 'layer_4', 'layer_5', 'layer_6', 'layer_7', 'oven'], true)) {
            $scanStages = [$selectedStage];
        }

        if (! empty($scanStages)) {
            $scanEventsQuery = LostWaxScanEvent::with([
                'tree.printOrderLine.productionPlan',
                'tree.workOrder.itemReference',
                'tree.allocations.printOrderLine.productionPlan',
            ])
                ->whereIn('stage', $scanStages)
                ->where('result', 'success')
                ->whereDoesntHave('void')
                ->whereHas('tree', function ($q) {
                    $q->where('status', '!=', 'cancelled');
                })
                ->where(function ($q) use ($dateFrom, $dateTo) {
                    $q->whereDate('scanned_at', '>=', $dateFrom)
                        ->whereDate('scanned_at', '<=', $dateTo);
                });

            if ($scope) {
                $scanEventsQuery->whereHas('tree', function ($t) use ($scope) {
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

            $scanEvents = $scanEventsQuery->get();

            foreach ($scanEvents as $event) {
                $tree = $event->tree;
                if (! $tree) {
                    continue;
                }

                $stage = $event->stage;
                $stageLabel = self::STAGES[$stage] ?? ucfirst(str_replace('_', ' ', (string) $stage));
                $stageOrder = self::getStageOrder((string) $stage);
                $eventBarcode = (string) ($event->barcode ?: $tree->barcode ?: ($tree->id ? 'TREE-'.$tree->id : null));

                if ($tree->allocations->isNotEmpty()) {
                    foreach ($tree->allocations as $alloc) {
                        $line = $alloc->printOrderLine;
                        $plan = $line?->productionPlan;

                        if ($scope && $plan && $plan->product_scope !== $scope) {
                            continue;
                        }

                        $prodCode = $plan?->code ?? $line?->code ?? '-';
                        $customerCode = $plan?->customer ?? $line?->customer ?? '-';
                        $itemName = $plan?->item_name ?? $line?->item_name ?? '-';
                        $weight = (float) ($plan?->weight ?? 0.0);

                        $rawEntries->push([
                            'stage' => $stage,
                            'stage_label' => $stageLabel,
                            'stage_order' => $stageOrder,
                            'production_code' => (string) $prodCode,
                            'customer_code' => (string) $customerCode,
                            'item_name' => (string) $itemName,
                            'barcode' => $eventBarcode,
                            'weight' => $weight,
                            'qty_processed' => (int) $alloc->allocated_qty,
                        ]);
                    }
                } else {
                    $line = $tree->printOrderLine;
                    $plan = $line?->productionPlan;
                    $wo = $tree->workOrder;

                    if ($scope && $plan && $plan->product_scope !== $scope) {
                        continue;
                    }

                    $prodCode = $plan?->code ?? $tree->getSourceCode() ?? '-';
                    $customerCode = $plan?->customer ?? $tree->getSourceCustomer() ?? '-';
                    $itemName = $plan?->item_name ?? $tree->getSourceProduct() ?? '-';
                    $weight = (float) ($plan?->weight ?? $wo?->itemReference?->unit_weight_snapshot ?? 0.0);

                    $rawEntries->push([
                        'stage' => $stage,
                        'stage_label' => $stageLabel,
                        'stage_order' => $stageOrder,
                        'production_code' => (string) $prodCode,
                        'customer_code' => (string) $customerCode,
                        'item_name' => (string) $itemName,
                        'barcode' => $eventBarcode,
                        'weight' => $weight,
                        'qty_processed' => (int) $tree->quantity,
                    ]);
                }
            }
        }

        // -------------------------------------------------------------
        // 4. TREE DEFECTS MAPPING (LostWaxTreeDefect — active/non-void only, WIB effective date)
        // -------------------------------------------------------------
        if ($selectedStage !== 'cetak') {
            $treeDefectsQuery = LostWaxTreeDefect::with([
                'tree.printOrderLine.productionPlan',
                'tree.workOrder.itemReference',
                'tree.allocations.printOrderLine.productionPlan',
            ])
                ->whereNull('voided_at')
                ->where('defect_qty', '>', 0)
                ->whereRaw('COALESCE(occurred_at, created_at) >= ?', [$sqlBufferStart])
                ->whereRaw('COALESCE(occurred_at, created_at) <= ?', [$sqlBufferEnd]);

            if ($selectedStage !== 'all') {
                $treeDefectsQuery->where('stage', $selectedStage);
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

            foreach ($treeDefectsQuery->get() as $defect) {
                // Canonical effective_defect_date in Asia/Jakarta (independent of DB session timezone)
                $effectiveDateWib = $defect->effective_date_wib;
                if (! $effectiveDateWib || $effectiveDateWib < $dateFrom || $effectiveDateWib > $dateTo) {
                    continue;
                }

                $tree = $defect->tree;
                $plan = $tree?->printOrderLine?->productionPlan;
                $wo = $tree?->workOrder;
                $prodCode = $plan?->code
                    ?? $tree?->getSourceCode()
                    ?? '-';
                $customerCode = $plan?->customer
                    ?? $tree?->getSourceCustomer()
                    ?? '-';
                $itemName = $plan?->item_name
                    ?? $tree?->getSourceProduct()
                    ?? '-';
                $weight = (float) ($plan?->weight ?? $wo?->itemReference?->unit_weight_snapshot ?? 0.0);

                $stage = (string) $defect->stage;
                $key = ((string) $prodCode).'__'.$stage;
                $defectsMap[$key] = ($defectsMap[$key] ?? 0) + (int) $defect->defect_qty;

                if (! isset($defectMetadata[$key])) {
                    $defectMetadata[$key] = [
                        'production_code' => (string) $prodCode,
                        'customer_code' => (string) $customerCode,
                        'item_name' => (string) $itemName,
                        'stage' => $stage,
                        'stage_label' => self::STAGES[$stage] ?? ucfirst(str_replace('_', ' ', $stage)),
                        'stage_order' => self::getStageOrder($stage),
                        'weight' => $weight,
                    ];
                }
            }
        }

        // -------------------------------------------------------------
        // 5. GROUPING: 1 Production Code + Stage = 1 Row
        // -------------------------------------------------------------
        $grouped = $rawEntries->groupBy(function ($entry) {
            return $entry['production_code'].'__'.$entry['stage'];
        })->map(function (Collection $group, $groupKey) use ($defectsMap) {
            $first = $group->first();
            $qtyProcessed = (int) $group->sum('qty_processed');
            $weight = (float) ($first['weight'] ?? 0.0);
            $totalWeight = round($qtyProcessed * $weight, 2);
            $qtyDefect = (int) ($defectsMap[$groupKey] ?? 0);
            $barcodeCount = $group->pluck('barcode')->filter(function ($b) {
                return ! empty($b) && $b !== '-';
            })->unique()->count();

            return [
                'group_key' => $groupKey,
                'production_code' => (string) $first['production_code'],
                'customer_code' => (string) $first['customer_code'],
                'item_name' => (string) $first['item_name'],
                'stage' => (string) $first['stage'],
                'stage_label' => (string) $first['stage_label'],
                'stage_order' => (int) $first['stage_order'],
                'barcode_count' => $barcodeCount,
                'weight' => $weight,
                'qty_processed' => $qtyProcessed,
                'total_weight' => $totalWeight,
                'qty_defect' => $qtyDefect,
            ];
        });

        // Emit defect-only rows (qty_processed = 0) when active defects occur on dates with zero scans for that code+stage
        // (e.g., post-Siap Cor breakage or late pre-Siap Cor correction recorded on a subsequent day)
        foreach ($defectsMap as $defectKey => $qtyDefect) {
            if ($qtyDefect <= 0 || $grouped->has($defectKey) || ! isset($defectMetadata[$defectKey])) {
                continue;
            }
            $meta = $defectMetadata[$defectKey];
            $grouped->put($defectKey, [
                'group_key' => $defectKey,
                'production_code' => (string) $meta['production_code'],
                'customer_code' => (string) $meta['customer_code'],
                'item_name' => (string) $meta['item_name'],
                'stage' => (string) $meta['stage'],
                'stage_label' => (string) $meta['stage_label'],
                'stage_order' => (int) $meta['stage_order'],
                'barcode_count' => 0,
                'weight' => (float) ($meta['weight'] ?? 0.0),
                'qty_processed' => 0,
                'total_weight' => 0.0,
                'qty_defect' => (int) $qtyDefect,
            ]);
        }

        // -------------------------------------------------------------
        // 6. SEARCH FILTER
        // -------------------------------------------------------------
        if ($searchLower !== '') {
            $grouped = $grouped->filter(function ($item) use ($searchLower) {
                return str_contains(strtolower($item['production_code']), $searchLower)
                    || str_contains(strtolower($item['customer_code']), $searchLower)
                    || str_contains(strtolower($item['item_name']), $searchLower)
                    || str_contains(strtolower($item['stage_label']), $searchLower);
            });
        }

        // -------------------------------------------------------------
        // 7. SORTING & SUMMARY
        // -------------------------------------------------------------
        $items = $grouped->sortBy([
            ['production_code', 'asc'],
            ['stage_order', 'asc'],
        ])->values();

        $summary = [
            'total_qty' => (int) $items->sum('qty_processed'),
            'total_weight' => (float) round($items->sum('total_weight'), 2),
            'total_defect' => (int) $items->sum('qty_defect'),
            'total_records' => $items->count(),
            'total_barcodes' => (int) $items->sum('barcode_count'),
        ];

        return [
            'items' => $items,
            'summary' => $summary,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'stage' => $selectedStage,
                'search' => $search,
            ],
        ];
    }
}
