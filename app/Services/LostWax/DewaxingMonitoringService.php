<?php

namespace App\Services\LostWax;

use App\Models\LostWaxTree;
use App\Models\LostWaxTreeDefect;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class DewaxingMonitoringService
{
    /**
     * Get monitoring data for trees currently in dewaxing and recently completed trees.
     */
    public function getMonitoringData(int $perPage = 30, ?int $page = null): array
    {
        Carbon::setLocale('id');
        $now = Carbon::now(config('app.timezone'));

        // 1. Base Query untuk Pohon Menunggu Selesai Dewaxing (FIFO)
        $baseWaitingQuery = LostWaxTree::query()
            ->where('current_stage', 'oven')
            ->where('status', '!=', 'cancelled')
            ->whereHas('scanEvents', function ($q) {
                $q->where('stage', 'oven')
                    ->where('result', 'success')
                    ->whereDoesntHave('void');
            })
            ->whereDoesntHave('scanEvents', function ($q) {
                $q->where('stage', 'siap_cor')
                    ->where('result', 'success')
                    ->whereDoesntHave('void');
            });

        // 2. Summary KPI (Dihitung di level database untuk performa tinggi pada dataset ribuan baris)
        $totalTrees = (clone $baseWaitingQuery)->count();
        $totalGrossPcs = (int) (clone $baseWaitingQuery)->sum('quantity');
        $totalDefectPcs = (int) LostWaxTreeDefect::whereIn('lost_wax_tree_id', (clone $baseWaitingQuery)->select('id'))->sum('defect_qty');
        $totalPcs = max(0, $totalGrossPcs - $totalDefectPcs);

        // Cari pohon dengan durasi terlama di oven (posisi pertama antrean FIFO)
        $oldestTree = (clone $baseWaitingQuery)
            ->with([
                'scanEvents' => function ($q) {
                    $q->where('stage', 'oven')
                        ->where('result', 'success')
                        ->whereDoesntHave('void')
                        ->orderBy('scanned_at', 'desc')
                        ->orderBy('id', 'desc');
                },
            ])
            ->orderBy('last_scan_at', 'asc')
            ->orderBy('id', 'asc')
            ->first();

        if ($oldestTree) {
            $oldestOvenEvent = $oldestTree->scanEvents->first();
            $oldestEntryTime = $oldestOvenEvent?->scanned_at ?? $oldestTree->last_scan_at;
            $longestDuration = $oldestEntryTime
                ? $oldestEntryTime->diffForHumans($now, ['parts' => 2, 'syntax' => CarbonInterface::DIFF_ABSOLUTE])
                : '-';
        } else {
            $longestDuration = '-';
        }

        // 3. Server-Side Pagination untuk Antrean Menunggu Selesai Dewaxing
        $paginatedTrees = (clone $baseWaitingQuery)
            ->with([
                'defects',
                'printOrderLine.productionPlan',
                'printOrderLine.printOrder',
                'rangkaiExecution.workOrder',
                'workOrder.itemReference',
                'allocations.printOrderLine.productionPlan',
                'allocations.printOrderLine.printOrder',
                'scanEvents' => function ($q) {
                    $q->where('result', 'success')
                        ->whereDoesntHave('void')
                        ->orderBy('scanned_at', 'desc')
                        ->orderBy('id', 'desc');
                },
            ])
            ->orderBy('last_scan_at', 'asc')
            ->orderBy('id', 'asc')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();

        // Transformasi koleksi per halaman ke array terformat
        $mappedWaitingItems = $paginatedTrees->getCollection()->map(function ($tree) use ($now) {
            $usableQty = (int) $tree->usable_quantity;

            // Cari event oven aktif
            $ovenEvent = $tree->scanEvents
                ->where('stage', 'oven')
                ->where('result', 'success')
                ->first();

            $entryTime = $ovenEvent?->scanned_at ?? $tree->last_scan_at;
            $durationFormatted = $entryTime
                ? $entryTime->diffForHumans($now, ['parts' => 2, 'syntax' => CarbonInterface::DIFF_ABSOLUTE])
                : '-';

            $details = $this->resolveTreeDetails($tree);

            return [
                'tree_id' => $tree->id,
                'barcode' => $tree->barcode,
                'human_barcode' => $tree->human_barcode,
                'tree_number' => (int) $tree->tree_number,
                'production_code' => $details['code'],
                'customer' => $details['customer'],
                'item_name' => $details['product'],
                'spk_number' => $details['spk'],
                'quantity_pcs' => $usableQty,
                'entry_time' => $entryTime,
                'entry_time_formatted' => $entryTime ? $entryTime->translatedFormat('d/m/Y H:i:s') : '-',
                'duration' => $durationFormatted,
                'duration_timestamp' => $entryTime ? $entryTime->timestamp : PHP_INT_MAX,
            ];
        });

        $paginatedTrees->setCollection($mappedWaitingItems);

        // 4. Riwayat Selesai Dewaxing (20 Terakhir)
        $completedTreesRaw = LostWaxTree::query()
            ->with([
                'defects',
                'printOrderLine.productionPlan',
                'printOrderLine.printOrder',
                'rangkaiExecution.workOrder',
                'workOrder.itemReference',
                'allocations.printOrderLine.productionPlan',
                'allocations.printOrderLine.printOrder',
                'scanEvents' => function ($q) {
                    $q->where('stage', 'siap_cor')
                        ->where('result', 'success')
                        ->whereDoesntHave('void')
                        ->orderBy('scanned_at', 'desc')
                        ->orderBy('id', 'desc');
                },
            ])
            ->where('current_stage', 'siap_cor')
            ->where('status', '!=', 'cancelled')
            ->whereHas('scanEvents', function ($q) {
                $q->where('stage', 'siap_cor')
                    ->where('result', 'success')
                    ->whereDoesntHave('void');
            })
            ->get();

        $completedItems = collect();

        foreach ($completedTreesRaw as $tree) {
            $siapCorEvent = $tree->scanEvents
                ->where('stage', 'siap_cor')
                ->where('result', 'success')
                ->first();

            $completedAt = $siapCorEvent?->scanned_at ?? $tree->last_scan_at;
            $details = $this->resolveTreeDetails($tree);

            $completedItems->push([
                'tree_id' => $tree->id,
                'barcode' => $tree->barcode,
                'human_barcode' => $tree->human_barcode,
                'production_code' => $details['code'],
                'item_name' => $details['product'],
                'customer' => $details['customer'],
                'quantity_pcs' => (int) $tree->usable_quantity,
                'completed_at' => $completedAt,
                'completed_at_formatted' => $completedAt ? $completedAt->translatedFormat('d/m/Y H:i:s') : '-',
                'completed_timestamp' => $completedAt ? $completedAt->timestamp : 0,
            ]);
        }

        // Sort Descending: Newest completion first, take 20
        $completedItems = $completedItems->sortByDesc('completed_timestamp')->take(20)->values();

        return [
            'waiting_items' => $paginatedTrees,
            'completed_items' => $completedItems,
            'summary' => [
                'total_trees' => $totalTrees,
                'total_pcs' => $totalPcs,
                'longest_duration' => $longestDuration,
            ],
            'updated_at' => $now,
        ];
    }

    /**
     * Resolve production code, customer, product name, and SPK for a tree.
     */
    private function resolveTreeDetails(LostWaxTree $tree): array
    {
        $codes = [];
        $customers = [];
        $products = [];

        if ($tree->allocations && $tree->allocations->isNotEmpty()) {
            foreach ($tree->allocations as $alloc) {
                $line = $alloc->printOrderLine;
                $plan = $line?->productionPlan;
                $c = $plan?->code ?? $line?->code ?? null;
                $cust = $plan?->customer ?? $line?->customer ?? null;
                $prod = $plan?->item_name ?? $line?->item_name ?? null;

                if ($c && ! in_array($c, $codes, true)) {
                    $codes[] = $c;
                }
                if ($cust && ! in_array($cust, $customers, true)) {
                    $customers[] = $cust;
                }
                if ($prod && ! in_array($prod, $products, true)) {
                    $products[] = $prod;
                }
            }
        }

        $primaryCode = $tree->getSourceCode() ?? '-';
        $primaryCustomer = $tree->getSourceCustomer() ?? '-';
        $primaryProduct = $tree->getSourceProduct() ?? '-';

        if (empty($codes) && $primaryCode !== '-') {
            $codes[] = $primaryCode;
        }
        if (empty($customers) && $primaryCustomer !== '-') {
            $customers[] = $primaryCustomer;
        }
        if (empty($products) && $primaryProduct !== '-') {
            $products[] = $primaryProduct;
        }

        $displayCode = ! empty($codes) ? implode(', ', $codes) : $primaryCode;
        $displayCustomer = ! empty($customers) ? implode(', ', $customers) : $primaryCustomer;
        $displayProduct = ! empty($products) ? implode(', ', $products) : $primaryProduct;

        // SPK / WO Number resolution
        $spkNumber = $tree->rangkaiExecution?->workOrder?->rangkai_order_number
            ?? $tree->workOrder?->wo_number
            ?? $tree->printOrderLine?->printOrder?->print_order_number;

        if (! $spkNumber && $tree->allocations && $tree->allocations->isNotEmpty()) {
            foreach ($tree->allocations as $alloc) {
                $allocSpk = $alloc->printOrderLine?->printOrder?->print_order_number;
                if ($allocSpk) {
                    $spkNumber = $allocSpk;
                    break;
                }
            }
        }

        return [
            'code' => $displayCode,
            'customer' => $displayCustomer,
            'product' => $displayProduct,
            'spk' => $spkNumber ?: '-',
        ];
    }
}
