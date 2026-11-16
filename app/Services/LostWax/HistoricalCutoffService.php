<?php

namespace App\Services\LostWax;

use App\Models\LostWaxHistoricalCutoff;
use App\Models\LostWaxHistoricalCutoffTree;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class HistoricalCutoffService
{
    public const SOURCE_PHYSICAL_SCAN = 'PHYSICAL_SCAN';

    public const SOURCE_OVEN_ESTIMATE = 'OVEN_ESTIMATE';

    public const SOURCE_PROD_DATE_ESTIMATE = 'PRODUCTION_DATE_ESTIMATE';

    public const VALID_ACTIVE_STATUSES = [
        'generated',
        'in_coating',
        'ready_for_coating',
        'in_dewaxing',
        'active',
        'in_process',
        'completed',
    ];

    public const STANDARD_STAGES = [
        'sebelum_scan',
        'layer_1',
        'layer_2',
        'layer_3',
        'layer_4',
        'layer_5',
        'layer_6',
        'layer_7',
        'oven',
        'siap_cor',
    ];

    /**
     * Get candidate trees eligible for historical cutoff.
     *
     * Criterion:
     * - Must have a valid Oven scan with age > 30 days (last_valid_oven_scan_age_days > 30).
     *
     * Excludes: cancelled, cor verified, already cutoff, anomaly stage/status, or oven scan <= 30 days / missing.
     */
    public function getCandidates(array $filters = [], ?Carbon $referenceDate = null): Collection
    {
        $now = $referenceDate ?: Carbon::now(config('app.timezone'));

        $query = LostWaxTree::with([
            'workOrder.itemReference',
            'printOrderLine.printOrder',
            'printOrderLine.productionPlan',
            'allocations.printOrderLine.productionPlan',
            'defects',
            'activeCastingResultTree.castingResult',
            'activeHistoricalCutoffTree',
            'scanEvents' => function ($q) {
                $q->whereDoesntHave('void')->orderBy('scanned_at', 'desc');
            },
        ])
            ->where('status', '!=', 'cancelled')
            ->where('status', '!=', 'historical_cutoff')
            ->whereDoesntHave('activeHistoricalCutoffTree')
            ->whereDoesntHave('activeCastingResultTree', function ($q) {
                $q->whereHas('castingResult', function ($q2) {
                    $q2->where('status', 'COMPLETED');
                });
            });

        // Filter: alur (all, legacy, new_flow)
        if (! empty($filters['source_type'])) {
            if ($filters['source_type'] === 'legacy') {
                $query->whereNotNull('work_order_id');
            } elseif ($filters['source_type'] === 'new_flow') {
                $query->whereNull('work_order_id')->whereNotNull('lost_wax_print_order_line_id');
            }
        }

        // Filter: search keyword
        if (! empty($filters['search'])) {
            $term = trim($filters['search']);
            $query->where(function ($q) use ($term) {
                $q->where('barcode', 'like', "%{$term}%")
                    ->orWhereHas('workOrder', function ($q2) use ($term) {
                        $q2->where('et_code', 'like', "%{$term}%")
                            ->orWhere('customer_name', 'like', "%{$term}%");
                    })
                    ->orWhereHas('printOrderLine', function ($q3) use ($term) {
                        $q3->where('code', 'like', "%{$term}%")
                            ->orWhere('customer', 'like', "%{$term}%")
                            ->orWhere('item_name', 'like', "%{$term}%");
                    });
            });
        }

        $trees = $query->orderBy('production_date', 'asc')->orderBy('barcode', 'asc')->get();

        $candidates = collect();

        foreach ($trees as $tree) {
            // Check anomaly status or stage
            $stage = $tree->current_stage ?: 'sebelum_scan';
            if (! in_array($tree->status, self::VALID_ACTIVE_STATUSES, true) || ! in_array($stage, self::STANDARD_STAGES, true)) {
                continue;
            }

            // Calculate Barcode Age (informative)
            $prodDate = $tree->production_date ? Carbon::parse($tree->production_date) : $tree->created_at;
            $barcodeAgeDays = (int) $prodDate->diffInDays($now);

            // Calculate Last Valid Oven Scan Age (ELIGIBILITY CRITERION)
            $lastOvenScan = $tree->scanEvents
                ->where('stage', 'oven')
                ->where('result', 'success')
                ->sortByDesc('scanned_at')
                ->first();

            // Candidate MUST have a valid oven scan
            if (! $lastOvenScan || ! $lastOvenScan->scanned_at) {
                continue;
            }

            $ovenAgeDays = (int) $lastOvenScan->scanned_at->diffInDays($now);

            // Strict boundary: last_valid_oven_scan_age_days > 30 days (30 days exactly does NOT qualify)
            if ($ovenAgeDays <= 30) {
                continue;
            }

            $candidateReason = "Scan Oven > 30 hari ({$ovenAgeDays} hari)";

            $milestones = $this->calculateMilestoneDates($tree, $lastOvenScan);

            $sourceType = $tree->work_order_id ? 'legacy_work_order' : 'print_order_line';
            $productionCode = $this->resolveProductionCode($tree);
            $customer = $tree->workOrder?->customer_name ?? $tree->printOrderLine?->customer ?? '-';
            $itemName = $tree->workOrder?->itemReference?->item_name_snapshot ?? $tree->printOrderLine?->item_name ?? '-';

            $itemCode = $tree->workOrder?->itemReference?->item_code_snapshot
                ?? $tree->printOrderLine?->code
                ?? $tree->printOrderLine?->productionPlan?->item_code
                ?? $tree->getSourceItemCode()
                ?? '-';

            $planOrWoId = $tree->work_order_id
                ? "WO# {$tree->work_order_id}"
                : ($tree->printOrderLine?->production_plan_id ? "Plan# {$tree->printOrderLine->production_plan_id}" : '-');

            $lastOvenScanDate = $lastOvenScan && $lastOvenScan->scanned_at
                ? $lastOvenScan->scanned_at->format('Y-m-d H:i:s')
                : '-';

            $candidates->push([
                'id' => $tree->id,
                'tree_id' => $tree->id,
                'barcode' => $tree->barcode,
                'tree_number' => $tree->tree_number,
                'source_type' => $sourceType,
                'flow_type' => $tree->work_order_id ? 'legacy' : 'new_flow',
                'work_order_id' => $tree->work_order_id,
                'production_plan_id' => $tree->printOrderLine?->production_plan_id,
                'plan_or_wo_id' => $planOrWoId,
                'production_code' => $productionCode,
                'et_code' => $productionCode,
                'customer' => $customer,
                'item_code' => $itemCode,
                'item_name' => $itemName,
                'current_stage' => $stage,
                'current_stage_label' => $tree->current_stage_label,
                'current_status' => $tree->status,
                'quantity' => (int) $tree->quantity,
                'gross_qty' => (int) $tree->quantity,
                'defect_quantity' => (int) $tree->total_defect_quantity,
                'defect_qty' => (int) $tree->total_defect_quantity,
                'usable_quantity' => (int) $tree->usable_quantity,
                'net_qty' => (int) $tree->usable_quantity,
                'production_date' => $tree->production_date?->format('d/m/Y') ?? '-',
                'barcode_age_days' => $barcodeAgeDays,
                'last_oven_scan_date' => $lastOvenScanDate,
                'oven_age_days' => $ovenAgeDays,
                'oven_scan_age_days' => $ovenAgeDays,
                'is_barcode_age_candidate' => ($barcodeAgeDays > 50),
                'is_oven_candidate' => true,
                'candidate_reason' => $candidateReason,
                'siap_cor_date' => $milestones['siap_cor_date'],
                'siap_cor_date_formatted' => $milestones['siap_cor_date'] ? Carbon::parse($milestones['siap_cor_date'])->format('d/m/Y') : '-',
                'siap_cor_milestone_date' => $milestones['siap_cor_date'] ? Carbon::parse($milestones['siap_cor_date'])->format('d/m/Y') : '-',
                'siap_cor_date_source' => $milestones['siap_cor_date_source'],
                'siap_cor_milestone_source' => $milestones['siap_cor_date_source'],
                'cor_date' => $milestones['cor_date'],
                'cor_date_formatted' => $milestones['cor_date'] ? Carbon::parse($milestones['cor_date'])->format('d/m/Y') : '-',
                'cor_milestone_date' => $milestones['cor_date'] ? Carbon::parse($milestones['cor_date'])->format('d/m/Y') : '-',
                'cor_date_source' => $milestones['cor_date_source'],
                'cor_milestone_source' => $milestones['cor_date_source'],
            ]);
        }

        return $candidates;
    }

    /**
     * Determine milestone dates for Siap Cor and Cor based on Section C rules.
     *
     * Siap Cor:
     * 1. Physical scan Siap Cor valid (source = physical_scan) -> actual scanned_at
     * 2. If not available, valid scan Oven date + 1 day
     * 3. If scan Oven not available, production_date + 6 days
     * Note: historical_reconciliation is explicitly ignored for physical Siap Cor date!
     *
     * Cor Historical Cut-off:
     * 1. Valid scan Oven date + 2 days
     * 2. If scan Oven not available, production_date + 7 days
     */
    public function calculateMilestoneDates(LostWaxTree $tree, ?LostWaxScanEvent $lastOvenScan = null): array
    {
        // 1. Resolve Last Valid Oven Scan if not passed
        if ($lastOvenScan === null) {
            $lastOvenScan = $tree->scanEvents
                ->where('stage', 'oven')
                ->where('result', 'success')
                ->sortByDesc('scanned_at')
                ->first();
        }

        // 2. Siap Cor Milestone
        // Priority 1: Physical scan Siap Cor valid
        $physicalSiapCor = $tree->scanEvents
            ->where('stage', 'siap_cor')
            ->where('result', 'success')
            ->where('source', LostWaxScanEvent::SOURCE_PHYSICAL_SCAN)
            ->sortByDesc('scanned_at')
            ->first();

        if ($physicalSiapCor && $physicalSiapCor->scanned_at) {
            $siapCorDate = $physicalSiapCor->scanned_at;
            $siapCorSource = self::SOURCE_PHYSICAL_SCAN;
        } elseif ($lastOvenScan && $lastOvenScan->scanned_at) {
            // Priority 2: Valid scan Oven + 1 day
            $siapCorDate = $lastOvenScan->scanned_at->copy()->addDay();
            $siapCorSource = self::SOURCE_OVEN_ESTIMATE;
        } else {
            // Priority 3: production_date + 6 days
            $prodDate = $tree->production_date ? Carbon::parse($tree->production_date) : $tree->created_at;
            $siapCorDate = $prodDate->copy()->addDays(6);
            $siapCorSource = self::SOURCE_PROD_DATE_ESTIMATE;
        }

        // 3. Cor Historical Cut-off Milestone
        if ($lastOvenScan && $lastOvenScan->scanned_at) {
            // Priority 1: Valid scan Oven + 2 days
            $corDate = $lastOvenScan->scanned_at->copy()->addDays(2)->toDateString();
            $corSource = self::SOURCE_OVEN_ESTIMATE;
        } else {
            // Priority 2: production_date + 7 days
            $prodDate = $tree->production_date ? Carbon::parse($tree->production_date) : $tree->created_at;
            $corDate = $prodDate->copy()->addDays(7)->toDateString();
            $corSource = self::SOURCE_PROD_DATE_ESTIMATE;
        }

        return [
            'siap_cor_date' => $siapCorDate instanceof Carbon ? $siapCorDate->format('Y-m-d H:i:s') : (string) $siapCorDate,
            'siap_cor_date_source' => $siapCorSource,
            'cor_date' => $corDate,
            'cor_date_source' => $corSource,
        ];
    }

    /**
     * Execute atomic Historical Cut-off transaction.
     */
    public function executeCutoff(array $treeIds, int $executedBy, string $reason, ?string $notes = null): LostWaxHistoricalCutoff
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Alasan Historical Cut-off wajib diisi.');
        }

        if (empty($treeIds)) {
            throw new InvalidArgumentException('Minimal pilih 1 pohon untuk Historical Cut-off.');
        }

        $user = User::find($executedBy);
        if (! $user) {
            throw new InvalidArgumentException("User pelaksana dengan ID {$executedBy} tidak ditemukan.");
        }

        return DB::transaction(function () use ($treeIds, $executedBy, $reason, $notes) {
            $now = Carbon::now(config('app.timezone'));

            // 1. Lock candidate trees with pessimistic lock
            $trees = LostWaxTree::with([
                'workOrder.itemReference',
                'printOrderLine.printOrder',
                'printOrderLine.productionPlan',
                'defects',
                'activeCastingResultTree.castingResult',
                'activeHistoricalCutoffTree',
                'scanEvents' => function ($q) {
                    $q->whereDoesntHave('void')->orderBy('scanned_at', 'desc');
                },
            ])
                ->whereIn('id', $treeIds)
                ->lockForUpdate()
                ->get();

            if ($trees->count() !== count(array_unique($treeIds))) {
                throw new InvalidArgumentException('Satu atau lebih pohon tidak ditemukan di database.');
            }

            // 2. Validate eligibility of every tree
            foreach ($trees as $tree) {
                if ($tree->status === 'cancelled') {
                    throw new InvalidArgumentException("Pohon [{$tree->barcode}] berstatus DIBATALKAN dan tidak dapat diproses.");
                }

                if ($tree->status === 'historical_cutoff' || $tree->activeHistoricalCutoffTree !== null) {
                    throw new InvalidArgumentException("Pohon [{$tree->barcode}] sudah memiliki Historical Cut-off aktif.");
                }

                $hasActiveCor = $tree->activeCastingResultTree !== null
                    && $tree->activeCastingResultTree->castingResult?->status === 'COMPLETED';

                if ($hasActiveCor) {
                    throw new InvalidArgumentException("Pohon [{$tree->barcode}] sudah memiliki transaksi Cor Verified aktif dan tidak dapat di-cutoff.");
                }

                $stage = $tree->current_stage ?: 'sebelum_scan';
                if (! in_array($tree->status, self::VALID_ACTIVE_STATUSES, true) || ! in_array($stage, self::STANDARD_STAGES, true)) {
                    throw new InvalidArgumentException("Pohon [{$tree->barcode}] memiliki anomali status/stage ('{$tree->status}' / '{$stage}').");
                }
            }

            // 3. Generate sequential unique cutoff number (LW-CUTOFF-YYYYMMDD-XXXX)
            $datePrefix = $now->format('Ymd');
            $dailyCount = LostWaxHistoricalCutoff::whereDate('cutoff_date', $now->toDateString())->count();
            $seq = $dailyCount + 1;
            $cutoffNumber = sprintf('LW-CUTOFF-%s-%04d', $datePrefix, $seq);

            // Double check uniqueness
            while (LostWaxHistoricalCutoff::where('cutoff_number', $cutoffNumber)->exists()) {
                $seq++;
                $cutoffNumber = sprintf('LW-CUTOFF-%s-%04d', $datePrefix, $seq);
            }

            // 4. Create Header
            $cutoff = LostWaxHistoricalCutoff::create([
                'cutoff_number' => $cutoffNumber,
                'cutoff_date' => $now->toDateString(),
                'reason' => $reason,
                'notes' => $notes,
                'total_trees' => $trees->count(),
                'total_quantity' => 0, // Will sum below
                'total_defect_quantity' => 0,
                'total_usable_quantity' => 0,
                'status' => 'ACTIVE',
                'executed_by' => $executedBy,
                'executed_at' => $now,
            ]);

            $totalQty = 0;
            $totalDefect = 0;
            $totalUsable = 0;

            // 5. Create Detail rows & update tree states
            foreach ($trees as $tree) {
                $milestones = $this->calculateMilestoneDates($tree);
                $usableQty = (int) $tree->usable_quantity;
                $defectQty = (int) $tree->total_defect_quantity;
                $grossQty = (int) $tree->quantity;

                $totalQty += $grossQty;
                $totalDefect += $defectQty;
                $totalUsable += $usableQty;

                $productionCode = $this->resolveProductionCode($tree);
                $customer = $tree->workOrder?->customer_name ?? $tree->printOrderLine?->customer ?? null;
                $itemName = $tree->workOrder?->itemReference?->item_name_snapshot ?? $tree->printOrderLine?->item_name ?? null;

                LostWaxHistoricalCutoffTree::create([
                    'lost_wax_historical_cutoff_id' => $cutoff->id,
                    'lost_wax_tree_id' => $tree->id,
                    'active_tree_id' => $tree->id, // Enforces unique active cutoff constraint
                    'barcode' => $tree->barcode,
                    'tree_number' => $tree->tree_number,
                    'work_order_id' => $tree->work_order_id,
                    'lost_wax_print_order_line_id' => $tree->lost_wax_print_order_line_id,
                    'production_code' => $productionCode,
                    'customer' => $customer,
                    'item_name' => $itemName,
                    'previous_stage' => $tree->current_stage,
                    'previous_status' => $tree->status,
                    'quantity' => $grossQty,
                    'defect_quantity' => $defectQty,
                    'usable_quantity' => $usableQty,
                    'siap_cor_date' => $milestones['siap_cor_date'],
                    'siap_cor_date_source' => $milestones['siap_cor_date_source'],
                    'cor_date' => $milestones['cor_date'],
                    'cor_date_source' => $milestones['cor_date_source'],
                    'recorded_at' => $now,
                ]);

                // Update tree state: mark status as historical_cutoff and current_stage as historical_cutoff
                $tree->update([
                    'status' => 'historical_cutoff',
                    'current_stage' => 'historical_cutoff',
                    'updated_at' => $now,
                ]);
            }

            // 6. Finalize header aggregates
            $cutoff->update([
                'total_quantity' => $totalQty,
                'total_defect_quantity' => $totalDefect,
                'total_usable_quantity' => $totalUsable,
            ]);

            return $cutoff->load(['trees.tree', 'executor']);
        });
    }

    /**
     * Resolve production code identifier across legacy and new flow trees.
     */
    protected function resolveProductionCode(LostWaxTree $tree): string
    {
        if ($tree->workOrder) {
            return $tree->workOrder->et_code ?? '-';
        }

        if ($tree->printOrderLine) {
            return $tree->printOrderLine->code ?? ($tree->printOrderLine->productionPlan?->code ?? '-');
        }

        return $tree->getSourceItemCode() ?? '-';
    }
}
