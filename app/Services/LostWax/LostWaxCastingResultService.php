<?php

namespace App\Services\LostWax;

use App\Models\LostWaxCastingResult;
use App\Models\LostWaxCastingResultTree;
use App\Models\LostWaxTree;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LostWaxCastingResultService
{
    public function __construct(
        private readonly ReadyForCastingService $readyForCastingService
    ) {}

    /**
     * Get the queue of Traveler Cards eligible for casting input.
     * Delegates to ReadyForCastingService to ensure 100% domain consistency.
     *
     * @return array{items: \Illuminate\Support\Collection, raw_items: \Illuminate\Support\Collection, summary: array, all_codes: array, all_customers: array}
     */
    public function getAvailableQueue(
        ?string $search = null,
        ?string $scope = null,
        array $codes = [],
        array $customers = []
    ): array {
        return $this->readyForCastingService->getQueueData($search, $scope, $codes, $customers);
    }

    /**
     * Atomically store a new Lost Wax casting result (Heat) and consume selected Traveler Cards.
     *
     * @param  array{
     *     heat_number: string,
     *     cast_date?: string,
     *     furnace?: string|null,
     *     shift?: string|null,
     *     operator_name?: string|null,
     *     notes?: string|null,
     *     tree_ids: array<int>
     * }  $data
     *
     * @throws InvalidArgumentException
     */
    public function storeCastingResult(array $data, User $user): LostWaxCastingResult
    {
        $heatNumber = trim((string) ($data['heat_number'] ?? ''));
        if ($heatNumber === '') {
            throw new InvalidArgumentException('Heat Number wajib diisi.');
        }

        $rawTreeIds = (array) ($data['tree_ids'] ?? []);
        $treeIds = array_values(array_unique(array_filter(array_map('intval', $rawTreeIds))));

        if (empty($treeIds)) {
            throw new InvalidArgumentException('Minimal satu Traveler Card harus dipilih untuk dicor.');
        }

        return DB::transaction(function () use ($data, $heatNumber, $treeIds, $user) {
            // 1. Pessimistic lock on all candidate trees in consistent order
            sort($treeIds);
            $trees = LostWaxTree::whereIn('id', $treeIds)
                ->with([
                    'defects',
                    'allocations.printOrderLine.productionPlan',
                    'printOrderLine.productionPlan',
                    'workOrder',
                ])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($trees->count() !== count($treeIds)) {
                throw new InvalidArgumentException('Sebagian Traveler Card yang dipilih tidak ditemukan dalam sistem.');
            }

            $totalPcs = 0;
            $preparedDetails = [];

            // 2. Strict server-side verification of each tree
            foreach ($treeIds as $id) {
                /** @var LostWaxTree $tree */
                $tree = $trees->get($id);

                if (! $tree) {
                    throw new InvalidArgumentException("Traveler Card ID [{$id}] tidak valid.");
                }

                if ($tree->status === 'cancelled') {
                    throw new InvalidArgumentException("Traveler Card [{$tree->barcode}] berstatus DIBATALKAN dan tidak dapat diproses.");
                }

                if ($tree->current_stage !== 'siap_cor') {
                    throw new InvalidArgumentException("Traveler Card [{$tree->barcode}] tidak berada dalam antrean Siap Cor (Status saat ini: {$tree->current_stage}).");
                }

                // Verify non-voided active scan siap cor event
                $hasActiveScan = $tree->scanEvents()
                    ->where('stage', 'siap_cor')
                    ->where('result', 'success')
                    ->whereDoesntHave('void')
                    ->exists();

                if (! $hasActiveScan) {
                    throw new InvalidArgumentException("Traveler Card [{$tree->barcode}] belum melalui verifikasi Scan Siap Cor yang sah.");
                }

                // Verify tree is not already actively consumed in another completed casting result
                $alreadyActive = LostWaxCastingResultTree::where('active_tree_id', $tree->id)->exists();
                if ($alreadyActive) {
                    throw new InvalidArgumentException("Traveler Card [{$tree->barcode}] sudah dikonsumsi oleh transaksi hasil cor aktif lain.");
                }

                // Calculate usable quantity fresh from DB (never trust browser)
                $usableQty = (int) $tree->usable_quantity;
                if ($usableQty <= 0) {
                    throw new InvalidArgumentException("Traveler Card [{$tree->barcode}] memiliki Qty Siap Cor 0 PCS (Gross: {$tree->quantity}, Defect: {$tree->total_defect_quantity}).");
                }

                $totalPcs += $usableQty;

                // Resolve snapshot metadata
                $code = $tree->getSourceCode() ?? '-';
                $customer = $tree->getSourceCustomer() ?? '-';
                $product = $tree->getSourceProduct() ?? '-';
                $grossQty = (int) $tree->quantity;
                $defectQty = (int) $tree->total_defect_quantity;

                // If multi-allocation exists, resolve joined summary for snapshot
                if ($tree->allocations->isNotEmpty()) {
                    $codes = [];
                    $custs = [];
                    $prods = [];
                    foreach ($tree->allocations as $alloc) {
                        $p = $alloc->printOrderLine?->productionPlan;
                        $l = $alloc->printOrderLine;
                        $c = $p?->code ?? $l?->code;
                        $cu = $p?->customer ?? $l?->customer;
                        $it = $p?->item_name ?? $l?->item_name;

                        if ($c && ! in_array($c, $codes)) {
                            $codes[] = $c;
                        }
                        if ($cu && ! in_array($cu, $custs)) {
                            $custs[] = $cu;
                        }
                        if ($it && ! in_array($it, $prods)) {
                            $prods[] = $it;
                        }
                    }
                    if (! empty($codes)) {
                        $code = implode(', ', $codes);
                    }
                    if (! empty($custs)) {
                        $customer = implode(', ', $custs);
                    }
                    if (! empty($prods)) {
                        $product = implode(', ', $prods);
                    }
                }

                $preparedDetails[] = [
                    'tree' => $tree,
                    'barcode' => $tree->barcode,
                    'production_code' => $code,
                    'customer' => $customer !== '-' ? $customer : null,
                    'item_name' => $product !== '-' ? $product : null,
                    'quantity_pcs' => $usableQty,
                    'gross_pcs' => $grossQty,
                    'defect_pcs' => $defectQty,
                ];
            }

            // 3. Create Casting Result Header
            $castDate = ! empty($data['cast_date']) ? Carbon::parse($data['cast_date'])->format('Y-m-d') : Carbon::today()->format('Y-m-d');

            $castingResult = LostWaxCastingResult::create([
                'heat_number' => $heatNumber,
                'cast_date' => $castDate,
                'furnace' => ! empty($data['furnace']) ? trim($data['furnace']) : null,
                'shift' => ! empty($data['shift']) ? trim($data['shift']) : null,
                'operator_name' => ! empty($data['operator_name']) ? trim($data['operator_name']) : null,
                'total_trees' => count($preparedDetails),
                'total_pcs' => $totalPcs,
                'notes' => ! empty($data['notes']) ? trim($data['notes']) : null,
                'status' => 'COMPLETED',
                'recorded_by' => $user->id,
            ]);

            // 4. Create Detail Records and Transition Tree Stages
            foreach ($preparedDetails as $detail) {
                /** @var LostWaxTree $tree */
                $tree = $detail['tree'];

                LostWaxCastingResultTree::create([
                    'lost_wax_casting_result_id' => $castingResult->id,
                    'lost_wax_tree_id' => $tree->id,
                    'active_tree_id' => $tree->id, // Database UNIQUE enforced
                    'barcode' => $detail['barcode'],
                    'production_code' => $detail['production_code'],
                    'customer' => $detail['customer'],
                    'item_name' => $detail['item_name'],
                    'quantity_pcs' => $detail['quantity_pcs'],
                    'gross_pcs' => $detail['gross_pcs'],
                    'defect_pcs' => $detail['defect_pcs'],
                ]);

                // Transition tree to 'cor'
                $tree->update([
                    'current_stage' => 'cor',
                ]);
            }

            return $castingResult->load(['trees', 'recorder']);
        });
    }

    /**
     * Atomically cancel / void a casting result transaction.
     * Releases active tree constraints and returns trees to 'siap_cor' WIP queue.
     * Detail history and snapshots remain preserved.
     *
     *
     * @throws InvalidArgumentException
     */
    public function cancelCastingResult(LostWaxCastingResult $castingResult, User $user, string $reason): LostWaxCastingResult
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Alasan pembatalan transaksi wajib diisi.');
        }

        return DB::transaction(function () use ($castingResult, $user, $reason) {
            // 1. Lock header
            $header = LostWaxCastingResult::where('id', $castingResult->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($header->status === 'CANCELLED') {
                throw new InvalidArgumentException('Transaksi ini sudah berstatus DIBATALKAN sebelumnya.');
            }

            // 2. Fetch linked tree IDs and lock physical trees
            $detailRows = LostWaxCastingResultTree::where('lost_wax_casting_result_id', $header->id)->get();
            $treeIds = $detailRows->pluck('lost_wax_tree_id')->all();

            $trees = LostWaxTree::whereIn('id', $treeIds)
                ->lockForUpdate()
                ->get();

            // 3. Mark header as CANCELLED with audit trail
            $header->update([
                'status' => 'CANCELLED',
                'voided_by' => $user->id,
                'voided_at' => Carbon::now(),
                'void_reason' => $reason,
            ]);

            // 4. Release active_tree_id on detail rows to allow trees to be reused
            LostWaxCastingResultTree::where('lost_wax_casting_result_id', $header->id)
                ->update(['active_tree_id' => null]);

            // 5. Return trees back to 'siap_cor' stage
            foreach ($trees as $tree) {
                if ($tree->current_stage === 'cor') {
                    $tree->update([
                        'current_stage' => 'siap_cor',
                    ]);
                }
            }

            return $header->fresh(['trees', 'recorder', 'voider']);
        });
    }

    /**
     * Build query for casting results history listing.
     */
    public function getHistoryQuery(
        ?string $search = null,
        ?string $status = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): Builder {
        $query = LostWaxCastingResult::query()
            ->with(['recorder', 'voider', 'trees'])
            ->orderByDesc('cast_date')
            ->orderByDesc('id');

        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            $query->where(function ($q) use ($term) {
                $q->where('heat_number', 'like', "%{$term}%")
                    ->orWhere('furnace', 'like', "%{$term}%")
                    ->orWhere('operator_name', 'like', "%{$term}%")
                    ->orWhereHas('trees', function ($tq) use ($term) {
                        $tq->where('barcode', 'like', "%{$term}%")
                            ->orWhere('production_code', 'like', "%{$term}%")
                            ->orWhere('customer', 'like', "%{$term}%")
                            ->orWhere('item_name', 'like', "%{$term}%");
                    });
            });
        }

        if ($status !== null && $status !== '' && in_array(strtoupper($status), ['COMPLETED', 'CANCELLED'])) {
            $query->where('status', strtoupper($status));
        }

        if ($dateFrom !== null && $dateFrom !== '') {
            $query->whereDate('cast_date', '>=', $dateFrom);
        }

        if ($dateTo !== null && $dateTo !== '') {
            $query->whereDate('cast_date', '<=', $dateTo);
        }

        return $query;
    }
}
