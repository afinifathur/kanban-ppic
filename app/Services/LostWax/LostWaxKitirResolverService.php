<?php

namespace App\Services\LostWax;

use App\Models\FittingItem;
use App\Models\LostWaxCastingResult;
use App\Models\LostWaxKitir;
use App\Models\User;
use App\Services\Fitting\FittingQuantityResolverService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LostWaxKitirResolverService
{
    public function __construct(
        protected ?FittingQuantityResolverService $quantityResolver = null
    ) {
        $this->quantityResolver = $quantityResolver ?? new FittingQuantityResolverService;
    }

    /**
     * Resolve groups of Production Codes for a Casting Result and validate total quantity conservation.
     *
     * @return Collection<int, array{
     *     production_code: string,
     *     customer: string,
     *     item_name: string,
     *     tree_count: int,
     *     quantity_pcs: int,
     *     barcodes: array<string>,
     *     is_issued: bool,
     *     kitir: LostWaxKitir|null,
     *     traveler_number: string|null,
     *     print_count: int,
     *     issued_at: Carbon|null
     * }>
     *
     * @throws InvalidArgumentException
     */
    public function resolveGroups(LostWaxCastingResult $castingResult): Collection
    {
        if ($castingResult->status === 'CANCELLED') {
            throw new InvalidArgumentException("Transaksi Hasil Cor Heat [{$castingResult->heat_number}] berstatus DIBATALKAN dan tidak dapat menerbitkan Kitir Produksi.");
        }

        // Ensure trees relation is loaded
        if (! $castingResult->relationLoaded('trees')) {
            $castingResult->load('trees');
        }

        $trees = $castingResult->trees;
        if ($trees->isEmpty()) {
            throw new InvalidArgumentException("Transaksi Hasil Cor Heat [{$castingResult->heat_number}] tidak memiliki data pohon / Traveler Card.");
        }

        // Validate each tree detail
        foreach ($trees as $tree) {
            $code = trim((string) ($tree->production_code ?? ''));
            if ($code === '' || $code === '-') {
                throw new InvalidArgumentException("Terdapat Traveler Card [{$tree->barcode}] dengan Kode Produksi kosong atau tidak valid.");
            }
        }

        // Group by production_code (multi-allocation composite codes e.g. 'LWDEMO001, LWDEMO002' remain unified)
        $grouped = $trees->groupBy(function ($tree) {
            return trim((string) $tree->production_code);
        });

        // Load existing issued Kitir records for this casting result
        $existingKitirs = LostWaxKitir::where('lost_wax_casting_result_id', $castingResult->id)
            ->get()
            ->keyBy('production_code');

        $groups = collect();
        $totalGroupPcs = 0;

        foreach ($grouped as $code => $groupTrees) {
            $treeCount = $groupTrees->count();
            $qtyPcs = (int) $groupTrees->sum('quantity_pcs');
            $totalGroupPcs += $qtyPcs;

            // Resolve customer: first non-empty, or comma-separated unique (exact equality, preserved order)
            $customerStr = \App\Helpers\FormatHelper::formatDeduplicatedList($groupTrees->pluck('customer'));

            // Resolve item name: first non-empty, or comma-separated unique (exact equality, preserved order)
            $itemStr = \App\Helpers\FormatHelper::formatDeduplicatedList($groupTrees->pluck('item_name'));

            $barcodes = $groupTrees->pluck('barcode')->filter()->values()->all();

            /** @var LostWaxKitir|null $existingKitir */
            $existingKitir = $existingKitirs->get($code);

            $groups->push([
                'production_code' => $code,
                'customer' => $customerStr,
                'item_name' => $itemStr,
                'tree_count' => $treeCount,
                'quantity_pcs' => $qtyPcs,
                'barcodes' => $barcodes,
                'is_issued' => $existingKitir !== null,
                'is_printed' => $existingKitir !== null && $existingKitir->print_count > 0,
                'kitir' => $existingKitir,
                'traveler_number' => $existingKitir?->traveler_number,
                'print_count' => $existingKitir ? $existingKitir->print_count : 0,
                'issued_at' => $existingKitir?->issued_at,
            ]);
        }

        // Strict validation: Integer conservation check
        $headerPcs = (int) $castingResult->total_pcs;
        if ($totalGroupPcs !== $headerPcs) {
            $diff = $totalGroupPcs - $headerPcs;
            $sign = $diff > 0 ? "+{$diff}" : "{$diff}";
            throw new InvalidArgumentException(
                "Terjadi ketidaksesuaian kuantitas: Total PCS kelompok Kitir ({$totalGroupPcs} PCS) tidak sama dengan Total Hasil Cor transaksi ({$headerPcs} PCS). Selisih: {$sign} PCS."
            );
        }

        return $groups;
    }

    /**
     * Atomically issue a new Kitir or return existing Kitir for a given Production Code group.
     * Concurrency-safe: multiple clicks will NEVER create duplicate traveler numbers.
     *
     * @throws InvalidArgumentException
     */
    public function issueOrGetKitir(
        LostWaxCastingResult $castingResult,
        string $productionCode,
        User $user,
        ?string $issueDate = null
    ): LostWaxKitir {
        $productionCode = trim($productionCode);
        if ($productionCode === '' || $productionCode === '-') {
            throw new InvalidArgumentException('Kode Produksi tidak boleh kosong.');
        }

        $targetDate = $issueDate ?? Carbon::today()->format('Y-m-d');

        return LostWaxTravelerNumberGenerator::executeWithRetry(function (string $travelerNumber) use ($castingResult, $productionCode, $user) {
            return DB::transaction(function () use ($castingResult, $productionCode, $user, $travelerNumber) {
                // Lock parent casting result
                $header = LostWaxCastingResult::where('id', $castingResult->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($header->status === 'CANCELLED') {
                    throw new InvalidArgumentException("Transaksi Hasil Cor Heat [{$header->heat_number}] berstatus DIBATALKAN dan tidak dapat menerbitkan Kitir Produksi.");
                }

                // 1. Check if Kitir already exists
                $existing = LostWaxKitir::where('lost_wax_casting_result_id', $header->id)
                    ->where('production_code', $productionCode)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $existing;
                }

                // 2. Resolve groups to get validated snapshot data
                $groups = $this->resolveGroups($header);
                $targetGroup = $groups->firstWhere('production_code', $productionCode);

                if (! $targetGroup) {
                    throw new InvalidArgumentException("Kelompok Kode Produksi [{$productionCode}] tidak ditemukan pada Heat [{$header->heat_number}].");
                }

                // 3. Create Kitir record permanently
                return LostWaxKitir::create([
                    'lost_wax_casting_result_id' => $header->id,
                    'traveler_number' => $travelerNumber,
                    'production_code' => $targetGroup['production_code'],
                    'customer' => $targetGroup['customer'],
                    'item_name' => $targetGroup['item_name'],
                    'tree_count' => $targetGroup['tree_count'],
                    'quantity_pcs' => $targetGroup['quantity_pcs'],
                    'print_count' => 0,
                    'issued_at' => Carbon::now(),
                    'issued_by' => $user->id,
                ]);
            });
        }, $targetDate);
    }

    /**
     * Atomically issue all unissued Kitirs for a Casting Result.
     *
     * @return Collection<int, LostWaxKitir>
     */
    public function issueAllKitirs(LostWaxCastingResult $castingResult, User $user, ?string $issueDate = null): Collection
    {
        $groups = $this->resolveGroups($castingResult);
        $kitirs = collect();

        foreach ($groups as $group) {
            $kitirs->push(
                $this->issueOrGetKitir($castingResult, $group['production_code'], $user, $issueDate)
            );
        }

        return $kitirs;
    }

    /**
     * Record a print / reprint event on a Kitir.
     */
    public function recordPrint(LostWaxKitir $kitir, User $user): LostWaxKitir
    {
        $kitir->increment('print_count', 1, [
            'printed_at' => Carbon::now(),
            'last_printed_by' => $user->id,
        ]);

        return $kitir->fresh();
    }

    /**
     * Build the 6 standard process rows for Fitting Production Kitir.
     * Evaluates active FittingItem stageExecutions when available (reprint / in-progress),
     * or returns blank operational fields if unexecuted / untransferred.
     * Sequence: NETTO -> KIMIA -> MARKING -> BUBUT CNC -> QC -> GUDANG JADI
     *
     * @return array<int, array{no: int, stage: string, label: string, hasil: int|null, rusak: int|null, tanggal: string|null, kpi: string, operator: string|null}>
     */
    public function buildFittingProcessRows(?LostWaxKitir $kitir = null): array
    {
        $stageConfigs = [
            [
                'no' => 1,
                'stage' => FittingItem::STAGE_NETTO_PF,
                'label' => 'NETTO',
                'spv_label' => 'SPV NETTO',
                'kpi' => '&#215;',
            ],
            [
                'no' => 2,
                'stage' => FittingItem::STAGE_KIMIA,
                'label' => 'KIMIA',
                'spv_label' => 'SPV KIMIA',
                'kpi' => '&#215;',
            ],
            [
                'no' => 3,
                'stage' => FittingItem::STAGE_MARKING,
                'label' => 'MARKING',
                'spv_label' => 'SPV MARKING',
                'kpi' => '&#215;',
            ],
            [
                'no' => 4,
                'stage' => FittingItem::STAGE_BUBUT_CNC_PF,
                'label' => 'BUBUT CNC',
                'spv_label' => 'SPV CNC',
                'kpi' => '', // CNC KPI column is blank
            ],
            [
                'no' => 5,
                'stage' => FittingItem::STAGE_QC_PF,
                'label' => 'QC',
                'spv_label' => 'SPV QC',
                'kpi' => '&#215;',
            ],
            [
                'no' => 6,
                'stage' => FittingItem::STAGE_GUDANG_JADI_PF,
                'label' => 'GUDANG JADI',
                'spv_label' => 'SPV GUDANG',
                'kpi' => '&#215;',
            ],
        ];

        $fittingItem = $kitir?->activeFittingItem;
        $executions = $fittingItem
            ? ($fittingItem->relationLoaded('stageExecutions') ? $fittingItem->stageExecutions : $fittingItem->stageExecutions()->get())
            : collect();

        $rows = [];

        foreach ($stageConfigs as $cfg) {
            $stage = $cfg['stage'];
            $exec = $executions->firstWhere('stage', $stage);

            $doneDate = $exec ? ($exec->physical_done_at ?? $exec->executed_at) : null;

            if (! $exec || ! $doneDate) {
                $rows[] = [
                    'no' => $cfg['no'],
                    'stage' => $stage,
                    'label' => $cfg['label'],
                    'hasil' => null,
                    'rusak' => null,
                    'tanggal' => null,
                    'kpi' => $cfg['kpi'],
                    'operator' => null,
                ];

                continue;
            }

            // Gudang Jadi displays final approved good quantity; processing stages display effective input
            $hasil = ($stage === FittingItem::STAGE_GUDANG_JADI_PF)
                ? $this->quantityResolver->resolveEffectiveGoodQty($fittingItem, $stage)
                : $this->quantityResolver->resolveEffectiveInputQty($fittingItem, $stage);

            $rows[] = [
                'no' => $cfg['no'],
                'stage' => $stage,
                'label' => $cfg['label'],
                'hasil' => $hasil,
                'rusak' => (int) $exec->defect_qty,
                'tanggal' => $doneDate->format('d/m/Y'),
                'kpi' => $cfg['kpi'],
                'operator' => $cfg['spv_label'],
            ];
        }

        return $rows;
    }
}
