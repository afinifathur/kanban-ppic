<?php

namespace App\Services\LostWax;

use App\Models\LostWaxTree;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ReadyForCastingService
{
    /**
     * Retrieve the queue of Traveler Cards that have completed Scan Siap Cor and are ready for casting.
     * Each Traveler Card is represented as exactly one row (FIFO ordered by Scan Siap Cor timestamp).
     *
     * @param  string|null  $search  Search keyword (barcode, production code, customer, SPK, item name)
     * @param  string|null  $scope  Optional RBAC product scope
     * @param  array  $codes  Optional array of production codes to filter
     * @param  array  $customers  Optional array of customers to filter
     * @param  array  $scanDates  Optional array of scan dates (Y-m-d or d-m-Y in Asia/Jakarta) to filter
     * @param  string|null  $scanDateFrom  Optional start of scan date range (Y-m-d or d-m-Y in Asia/Jakarta)
     * @param  string|null  $scanDateTo  Optional end of scan date range (Y-m-d or d-m-Y in Asia/Jakarta)
     * @return array{items: Collection, raw_items: Collection, summary: array, all_codes: array, all_customers: array, all_scan_dates: array}
     */
    public function getQueueData(
        ?string $search = null,
        ?string $scope = null,
        array $codes = [],
        array $customers = [],
        array $scanDates = [],
        ?string $scanDateFrom = null,
        ?string $scanDateTo = null
    ): array {
        // 1. Fetch eligible tree candidates with eager loading
        $query = LostWaxTree::query()
            ->with([
                'defects',
                'printOrderLine.productionPlan',
                'printOrderLine.printOrder',
                'rangkaiExecution.workOrder',
                'workOrder.itemReference',
                'allocations.printOrderLine.productionPlan',
                'scanEvents' => function ($q) {
                    $q->where('stage', 'siap_cor')
                        ->where('result', 'success')
                        ->whereDoesntHave('void')
                        ->orderByDesc('scanned_at')
                        ->orderByDesc('id');
                },
            ])
            ->where('status', '!=', 'cancelled')
            ->where('current_stage', 'siap_cor')
            ->whereHas('scanEvents', function ($q) {
                $q->where('stage', 'siap_cor')
                    ->where('result', 'success')
                    ->whereDoesntHave('void');
            })
            ->whereDoesntHave('castingResultTrees', function ($q) {
                $q->whereHas('castingResult', function ($hq) {
                    $hq->where('status', 'COMPLETED');
                });
            });

        // 2. Apply RBAC product scope when available
        if ($scope) {
            $query->where(function ($q) use ($scope) {
                $q->whereHas('printOrderLine.productionPlan', function ($p) use ($scope) {
                    $p->where('product_scope', $scope);
                })
                    ->orWhereHas('allocations.printOrderLine.productionPlan', function ($p) use ($scope) {
                        $p->where('product_scope', $scope);
                    })
                    ->orWhereHas('workOrder', function ($w) use ($scope) {
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

        $trees = $query->get();

        $rawRows = collect();

        foreach ($trees as $tree) {
            // A. Usable quantity must be greater than zero
            $usableQty = (int) $tree->usable_quantity;
            if ($usableQty <= 0) {
                continue;
            }

            // B. Active non-voided Scan Siap Cor event check
            $siapCorEvent = $tree->scanEvents
                ->where('stage', 'siap_cor')
                ->where('result', 'success')
                ->sortByDesc('scanned_at')
                ->first();

            if (! $siapCorEvent) {
                continue;
            }

            $scannedAt = $siapCorEvent->scanned_at;
            $scannedAtWib = $scannedAt ? $scannedAt->copy()->setTimezone('Asia/Jakarta') : null;
            $scanDateKey = $scannedAtWib ? $scannedAtWib->format('Y-m-d') : null;
            $scanDateLabel = $scannedAtWib ? $scannedAtWib->format('d-m-Y') : '-';
            $grossQty = (int) $tree->quantity;
            $defectQty = (int) $tree->total_defect_quantity;

            // C. Metadata resolution (handles multi-allocation without duplicating tree or PCS)
            $hasAllocations = $tree->allocations->isNotEmpty();
            $treeCodes = [];
            $treeCustomers = [];
            $treeProducts = [];
            $treeMaterials = [];
            $allocationBreakdown = [];

            if ($hasAllocations) {
                foreach ($tree->allocations as $alloc) {
                    $line = $alloc->printOrderLine;
                    $plan = $line?->productionPlan;
                    $c = $plan?->code ?? $line?->code ?? $tree->getSourceCode() ?? '-';
                    $cust = $plan?->customer ?? $line?->customer ?? $tree->getSourceCustomer() ?? '-';
                    $prod = $plan?->item_name ?? $line?->item_name ?? $tree->getSourceProduct() ?? '-';
                    $mat = $plan?->aisi ?? $line?->aisi ?? $tree->getSourceAisi() ?? '-';

                    if ($c !== '-' && ! in_array($c, $treeCodes)) {
                        $treeCodes[] = $c;
                    }
                    if ($cust !== '-' && ! in_array($cust, $treeCustomers)) {
                        $treeCustomers[] = $cust;
                    }
                    if ($prod !== '-' && ! in_array($prod, $treeProducts)) {
                        $treeProducts[] = $prod;
                    }
                    if ($mat !== '-' && ! in_array($mat, $treeMaterials)) {
                        $treeMaterials[] = $mat;
                    }

                    $allocationBreakdown[] = [
                        'code' => $c,
                        'customer' => $cust,
                        'item_name' => $prod,
                        'allocated_qty' => (int) $alloc->allocated_qty,
                    ];
                }
            }

            // Fallbacks for single order / non-allocated tree
            $primaryCode = $tree->getSourceCode() ?? '-';
            $primaryCustomer = $tree->getSourceCustomer() ?? '-';
            $primaryProduct = $tree->getSourceProduct() ?? '-';
            $primaryMaterial = $tree->getSourceAisi() ?? ($tree->printOrderLine?->productionPlan?->aisi ?? '-');

            if (empty($treeCodes) && $primaryCode !== '-') {
                $treeCodes[] = $primaryCode;
            }
            if (empty($treeCustomers) && $primaryCustomer !== '-') {
                $treeCustomers[] = $primaryCustomer;
            }
            if (empty($treeProducts) && $primaryProduct !== '-') {
                $treeProducts[] = $primaryProduct;
            }
            if (empty($treeMaterials) && $primaryMaterial !== '-') {
                $treeMaterials[] = $primaryMaterial;
            }

            $displayCode = ! empty($treeCodes) ? \App\Helpers\FormatHelper::formatDeduplicatedList($treeCodes, ', ', $primaryCode) : $primaryCode;
            $displayCustomer = ! empty($treeCustomers) ? \App\Helpers\FormatHelper::formatDeduplicatedList($treeCustomers, ', ', $primaryCustomer) : $primaryCustomer;
            $displayProduct = ! empty($treeProducts) ? \App\Helpers\FormatHelper::formatDeduplicatedList($treeProducts, ', ', $primaryProduct) : $primaryProduct;
            $displayMaterial = ! empty($treeMaterials) ? \App\Helpers\FormatHelper::formatDeduplicatedList($treeMaterials, ', ', $primaryMaterial) : $primaryMaterial;

            // SPK / WO Number resolution
            $spkNumber = $tree->rangkaiExecution?->workOrder?->rangkai_order_number
                ?? $tree->workOrder?->wo_number
                ?? $tree->printOrderLine?->printOrder?->print_order_number
                ?? '-';

            $rawRows->push([
                'tree_id' => $tree->id,
                'barcode' => $tree->barcode,
                'human_barcode' => $tree->human_barcode,
                'tree_number' => (int) $tree->tree_number,
                'production_code' => $displayCode,
                'codes' => $treeCodes,
                'customer' => $displayCustomer,
                'customers' => $treeCustomers,
                'item_name' => $displayProduct,
                'material' => $displayMaterial,
                'spk_number' => $spkNumber,
                'quantity_pcs' => $usableQty,
                'gross_pcs' => $grossQty,
                'defect_pcs' => $defectQty,
                'scanned_at' => $scannedAt,
                'scan_date' => $scanDateKey,
                'scan_date_label' => $scanDateLabel,
                'scanned_at_formatted' => $scannedAtWib ? $scannedAtWib->translatedFormat('d/m/Y H:i:s') : '-',
                'scanned_time' => $scannedAtWib ? $scannedAtWib->format('H:i') : '-',
                'scanned_diff' => $scannedAtWib ? $scannedAtWib->diffForHumans() : '-',
                'is_multi_allocated' => count($treeCodes) > 1 || count($allocationBreakdown) > 1,
                'allocations' => $allocationBreakdown,
            ]);
        }

        // 3. FIFO Sort: Primary = scanned_at ASC, Secondary = tree_id ASC
        $sortedRows = $rawRows->sort(function ($a, $b) {
            $timeA = $a['scanned_at'] ? $a['scanned_at']->timestamp : 0;
            $timeB = $b['scanned_at'] ? $b['scanned_at']->timestamp : 0;
            if ($timeA === $timeB) {
                return $a['tree_id'] <=> $b['tree_id'];
            }

            return $timeA <=> $timeB;
        })->values();

        // 4. Extract distinct filter options from current eligible queue
        $allCodes = $sortedRows->flatMap(fn ($r) => $r['codes'])
            ->filter(fn ($c) => $c !== '-' && ! empty($c))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $allCustomers = $sortedRows->flatMap(fn ($r) => $r['customers'])
            ->filter(fn ($c) => $c !== '-' && ! empty($c))
            ->unique()
            ->sort()
            ->values()
            ->all();

        // Dynamic scan date options built ONLY from active Ready for Casting rows (Asia/Jakarta, newest first)
        $scanDateCounts = [];
        foreach ($sortedRows as $row) {
            $dKey = $row['scan_date'] ?? null;
            if (! $dKey) {
                continue;
            }
            if (! isset($scanDateCounts[$dKey])) {
                $scanDateCounts[$dKey] = [
                    'value' => $dKey,
                    'label' => $row['scan_date_label'],
                    'tree_count' => 0,
                ];
            }
            $scanDateCounts[$dKey]['tree_count']++;
        }
        krsort($scanDateCounts);
        $allScanDates = array_values($scanDateCounts);

        // Normalize requested scan date filters to canonical Y-m-d (supports both Y-m-d and d-m-Y inputs)
        $normalizedScanDates = self::normalizeScanDateParams($scanDates);

        $normalizedFrom = self::normalizeSingleDate($scanDateFrom);
        $normalizedTo = self::normalizeSingleDate($scanDateTo);
        if ($normalizedFrom !== null && $normalizedTo !== null && $normalizedFrom > $normalizedTo) {
            [$normalizedFrom, $normalizedTo] = [$normalizedTo, $normalizedFrom];
        }

        // 5. Apply filters with AND combination (multi-select within each filter uses OR)
        $filteredRows = $sortedRows->filter(function ($item) use ($search, $codes, $customers, $normalizedScanDates, $normalizedFrom, $normalizedTo) {
            // A. Search keyword filter
            if ($search !== null && $search !== '') {
                $searchLower = strtolower(trim($search));
                $matchesBarcode = str_contains(strtolower($item['barcode']), $searchLower)
                    || str_contains(strtolower($item['human_barcode']), $searchLower);
                $matchesCode = false;
                foreach ($item['codes'] as $c) {
                    if (str_contains(strtolower($c), $searchLower)) {
                        $matchesCode = true;
                        break;
                    }
                }
                $matchesCustomer = false;
                foreach ($item['customers'] as $cust) {
                    if (str_contains(strtolower($cust), $searchLower)) {
                        $matchesCustomer = true;
                        break;
                    }
                }
                $matchesSpk = str_contains(strtolower($item['spk_number']), $searchLower);
                $matchesItem = str_contains(strtolower($item['item_name']), $searchLower);

                if (! ($matchesBarcode || $matchesCode || $matchesCustomer || $matchesSpk || $matchesItem)) {
                    return false;
                }
            }

            // B. Kode Produksi multi-select filter
            if (! empty($codes)) {
                $hasMatchingCode = ! empty(array_intersect($item['codes'], $codes));
                if (! $hasMatchingCode) {
                    return false;
                }
            }

            // C. Customer multi-select filter
            if (! empty($customers)) {
                $hasMatchingCustomer = ! empty(array_intersect($item['customers'], $customers));
                if (! $hasMatchingCustomer) {
                    return false;
                }
            }

            // D. Scan Siap Cor Date multi-select filter (OR across selected dates, Asia/Jakarta)
            if (! empty($normalizedScanDates)) {
                if (empty($item['scan_date']) || ! in_array($item['scan_date'], $normalizedScanDates, true)) {
                    return false;
                }
            }

            // E. Scan Siap Cor Date Range filter (Asia/Jakarta)
            if ($normalizedFrom !== null) {
                if (empty($item['scan_date']) || $item['scan_date'] < $normalizedFrom) {
                    return false;
                }
            }
            if ($normalizedTo !== null) {
                if (empty($item['scan_date']) || $item['scan_date'] > $normalizedTo) {
                    return false;
                }
            }

            return true;
        })->values();

        // 6. Compute Summary KPIs
        $isFiltered = ($search !== null && $search !== '')
            || ! empty($codes)
            || ! empty($customers)
            || ! empty($normalizedScanDates)
            || $normalizedFrom !== null
            || $normalizedTo !== null;

        $activeFilterParts = [];
        if ($search !== null && $search !== '') {
            $activeFilterParts[] = 'Cari: "'.$search.'"';
        }
        if (! empty($codes)) {
            $activeFilterParts[] = 'Kode: '.implode(', ', $codes);
        }
        if (! empty($customers)) {
            $activeFilterParts[] = 'Customer: '.implode(', ', $customers);
        }
        if (! empty($normalizedScanDates)) {
            $formattedFilterDates = array_map(
                fn (string $d) => Carbon::createFromFormat('Y-m-d', $d, 'Asia/Jakarta')->format('d-m-Y'),
                $normalizedScanDates
            );
            $activeFilterParts[] = 'Tgl Scan: '.implode(', ', $formattedFilterDates);
        }
        if ($normalizedFrom !== null || $normalizedTo !== null) {
            $fromLabel = $normalizedFrom ? Carbon::createFromFormat('Y-m-d', $normalizedFrom, 'Asia/Jakarta')->format('d-m-Y') : '...';
            $toLabel = $normalizedTo ? Carbon::createFromFormat('Y-m-d', $normalizedTo, 'Asia/Jakarta')->format('d-m-Y') : '...';
            $activeFilterParts[] = 'Rentang Scan: '.$fromLabel.' s/d '.$toLabel;
        }
        $activeFilterText = ! empty($activeFilterParts) ? implode(' | ', $activeFilterParts) : '';

        $now = Carbon::now('Asia/Jakarta');

        $distinctCodesCount = $filteredRows->flatMap(fn ($r) => $r['codes'])
            ->filter(fn ($c) => $c !== '-' && ! empty($c))
            ->unique()
            ->count();

        $summary = [
            'total_trees' => $filteredRows->count(),
            'total_usable_pcs' => (int) $filteredRows->sum('quantity_pcs'),
            'total_gross_pcs' => (int) $filteredRows->sum('gross_pcs'),
            'total_defect_pcs' => (int) $filteredRows->sum('defect_pcs'),
            'total_codes' => $distinctCodesCount,
            'queue_total_trees' => $sortedRows->count(),
            'queue_total_codes' => count($allCodes),
            'is_filtered' => $isFiltered,
            'active_filter_text' => $activeFilterText,
            'generated_at' => $now,
            'generated_at_formatted' => $now->translatedFormat('d F Y'),
            'generated_time' => $now->format('H:i:s'),
        ];

        return [
            'items' => $filteredRows,
            'raw_items' => $sortedRows,
            'summary' => $summary,
            'all_codes' => $allCodes,
            'all_customers' => $allCustomers,
            'all_scan_dates' => $allScanDates,
            'selected_scan_dates' => $normalizedScanDates,
            'scan_date_from' => $normalizedFrom,
            'scan_date_to' => $normalizedTo,
        ];
    }

    /**
     * Normalize incoming scan_dates filter array into unique canonical Y-m-d strings (descending).
     * Accepts both Y-m-d (2026-11-18) and d-m-Y (18-11-2026); ignores invalid values safely.
     *
     * @param  array<int, mixed>  $rawDates
     * @return array<int, string>
     */
    public static function normalizeScanDateParams(array $rawDates): array
    {
        $normalized = [];

        foreach ($rawDates as $raw) {
            if (! is_string($raw) && ! is_numeric($raw)) {
                continue;
            }
            $val = trim((string) $raw);
            if ($val === '') {
                continue;
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                $parsed = Carbon::createFromFormat('!Y-m-d', $val, 'Asia/Jakarta');
                if ($parsed && $parsed->format('Y-m-d') === $val) {
                    $normalized[$val] = $val;
                }
            } elseif (preg_match('/^\d{2}-\d{2}-\d{4}$/', $val)) {
                $parsed = Carbon::createFromFormat('!d-m-Y', $val, 'Asia/Jakarta');
                if ($parsed && $parsed->format('d-m-Y') === $val) {
                    $key = $parsed->format('Y-m-d');
                    $normalized[$key] = $key;
                }
            }
        }

        krsort($normalized);

        return array_values($normalized);
    }

    /**
     * Normalize a single date string or Carbon instance into canonical Y-m-d string (Asia/Jakarta).
     */
    public static function normalizeSingleDate(mixed $date): ?string
    {
        if ($date instanceof Carbon) {
            return $date->copy()->setTimezone('Asia/Jakarta')->format('Y-m-d');
        }

        if (! is_string($date) && ! is_numeric($date)) {
            return null;
        }

        $val = trim((string) $date);
        if ($val === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            $parsed = Carbon::createFromFormat('!Y-m-d', $val, 'Asia/Jakarta');
            if ($parsed && $parsed->format('Y-m-d') === $val) {
                return $val;
            }
        } elseif (preg_match('/^\d{2}-\d{2}-\d{4}$/', $val)) {
            $parsed = Carbon::createFromFormat('!d-m-Y', $val, 'Asia/Jakarta');
            if ($parsed && $parsed->format('d-m-Y') === $val) {
                return $parsed->format('Y-m-d');
            }
        }

        try {
            return Carbon::parse($val, 'Asia/Jakarta')->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
