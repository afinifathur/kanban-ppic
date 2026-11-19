<?php

namespace App\Services\Fitting;

use App\Models\FittingItem;
use App\Models\FittingStageExecution;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FittingKanbanQueryService
{
    public const BUCKET_UNASSIGNED = 'unassigned';

    public const ALL_BUCKETS = ['1', '2', '3', '4'];

    private readonly FittingQuantityResolverService $quantityResolver;

    public function __construct(
        private readonly FittingStageAuthorizationService $authService,
        ?FittingQuantityResolverService $quantityResolver = null
    ) {
        $this->quantityResolver = $quantityResolver ?? new FittingQuantityResolverService;
    }

    /**
     * Normalize a line bucket parameter to '1'..'4' or 'unassigned'.
     * Returns null if invalid.
     */
    public static function normalizeLineBucket(mixed $bucket): ?string
    {
        if ($bucket === null || $bucket === '') {
            return self::BUCKET_UNASSIGNED;
        }

        $cleaned = strtolower(trim((string) $bucket));
        if ($cleaned === self::BUCKET_UNASSIGNED || $cleaned === 'null') {
            return self::BUCKET_UNASSIGNED;
        }

        if (is_numeric($cleaned) && in_array((int) $cleaned, FittingItem::VALID_LINE_NUMBERS, true)) {
            return (string) ((int) $cleaned);
        }

        return null;
    }

    /**
     * Authoritative Kanban board data for a specific Fitting stage.
     * Groups ACTIVE travelers into 5 strict buckets: Line 1, Line 2, Line 3, Line 4, and Unassigned.
     * Never infers line from diameter/size and never mutates Unassigned items.
     *
     * @param  array{search?: string, line_bucket?: string, customer?: string, is_urgent?: bool}  $filters
     * @return array{
     *     stage: string,
     *     stage_label: string,
     *     cards: list<array>,
     *     buckets: array<string, list<array>>,
     *     summary: array{total_cards: int, total_pcs: int, total_weight_kg: float, unassigned_cards: int, bucket_counts: array<string, int>}
     * }
     */
    public function getStageKanbanData(string $stage, array $filters = []): array
    {
        $canonicalStage = FittingStageAuthorizationService::normalizeStage($stage);
        if ($canonicalStage === null) {
            throw new InvalidArgumentException("Tahap operasional '{$stage}' tidak valid dalam alur Fitting Production.");
        }

        $items = FittingItem::query()
            ->where('status', FittingItem::STATUS_ACTIVE)
            ->where('current_stage', $canonicalStage)
            ->with([
                'transfer.transferredBy',
                'allocations',
                'stageExecutions' => fn ($q) => $q->orderBy('executed_at', 'asc')->orderBy('id', 'asc')->with('operator'),
            ])
            ->get();

        // Format all cards first and group per bucket so queue_seq is authoritative before search/customer filtering
        $allFormattedByBucket = [
            '1' => [],
            '2' => [],
            '3' => [],
            '4' => [],
            self::BUCKET_UNASSIGNED => [],
        ];

        foreach ($items as $item) {
            $card = $this->formatKanbanCard($item, $canonicalStage);
            $bucketKey = $card['line_bucket'];
            $allFormattedByBucket[$bucketKey][] = $card;
        }

        // Sort each bucket deterministically and assign 1-based queue_seq within bucket
        foreach ($allFormattedByBucket as $bucketKey => &$bucketCards) {
            usort($bucketCards, fn (array $a, array $b) => self::compareKanbanCards($a, $b, $canonicalStage));
            foreach ($bucketCards as $idx => &$card) {
                $card['queue_seq'] = $idx + 1;
            }
            unset($card);
        }
        unset($bucketCards);

        // Apply optional view filters (without changing underlying queue_seq!)
        $search = isset($filters['search']) ? strtolower(trim((string) $filters['search'])) : '';
        $customerFilter = isset($filters['customer']) ? strtolower(trim((string) $filters['customer'])) : '';
        $lineBucketFilter = isset($filters['line_bucket']) && trim((string) $filters['line_bucket']) !== ''
            ? self::normalizeLineBucket($filters['line_bucket'])
            : null;
        $onlyUrgent = ! empty($filters['is_urgent']);

        $filteredBuckets = [
            '1' => [],
            '2' => [],
            '3' => [],
            '4' => [],
            self::BUCKET_UNASSIGNED => [],
        ];
        $flatCards = [];

        foreach ($allFormattedByBucket as $bucketKey => $bucketCards) {
            if ($lineBucketFilter !== null && (string) $bucketKey !== (string) $lineBucketFilter) {
                continue;
            }

            foreach ($bucketCards as $card) {
                if ($onlyUrgent && ! $card['is_urgent']) {
                    continue;
                }

                if ($customerFilter !== '' && ! str_contains(strtolower((string) ($card['customer'] ?? '')), $customerFilter)) {
                    continue;
                }

                if ($search !== '') {
                    $haystack = strtolower(implode(' ', [
                        $card['traveler_number'] ?? '',
                        $card['heat_number'] ?? '',
                        $card['production_code'] ?? '',
                        $card['item_code'] ?? '',
                        $card['item_name'] ?? '',
                        $card['customer'] ?? '',
                        $card['transfer_number'] ?? '',
                    ]));
                    if (! str_contains($haystack, $search)) {
                        continue;
                    }
                }

                $filteredBuckets[$bucketKey][] = $card;
                $flatCards[] = $card;
            }
        }

        $bucketCounts = [];
        foreach (self::ALL_BUCKETS as $bk) {
            $bucketCounts[$bk] = count($filteredBuckets[$bk]);
        }
        $bucketCounts[self::BUCKET_UNASSIGNED] = count($filteredBuckets[self::BUCKET_UNASSIGNED]);

        return [
            'stage' => $canonicalStage,
            'stage_label' => FittingStageAuthorizationService::STAGE_LABELS[$canonicalStage] ?? strtoupper($canonicalStage),
            'cards' => $flatCards,
            'buckets' => $filteredBuckets,
            'summary' => [
                'total_cards' => count($flatCards),
                'total_pcs' => (int) array_sum(array_column($flatCards, 'qty_transferred')),
                'total_effective_pcs' => (int) array_sum(array_column($flatCards, 'effective_input_qty')),
                'total_weight_kg' => round((float) array_sum(array_column($flatCards, 'total_weight_kg')), 2),
                'unassigned_cards' => count($filteredBuckets[self::BUCKET_UNASSIGNED]),
                'unassigned_items' => $filteredBuckets[self::BUCKET_UNASSIGNED],
                'bucket_counts' => $bucketCounts,
            ],
        ];
    }

    /**
     * Reorder active queue items for a specific Fitting stage and line bucket ('1'..'4' or 'unassigned').
     *
     * Concurrency & integrity guarantees:
     * - Authorized strictly via FittingStageAuthorizationService::authorizeReorderKanban() (PPIC Fitting + System Admin)
     * - Locks all matching ACTIVE `fitting_items` in `ORDER BY id ASC` inside `DB::transaction()`
     * - Produces dense, strictly unique 1..N `queue_position` within the (stage, line_bucket) scope
     * - Never touches `fitting_transfers`, `fitting_item_allocations`, or Lost Wax tables
     *
     * @return array{stage: string, line_bucket: string, total_reordered: int, from_pos: int, to_pos: int, ordered_ids: list<int>}
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws InvalidArgumentException
     */
    public function reorderStageBucketQueue(
        string $stage,
        mixed $lineBucket,
        int $fromPos,
        int $toPos,
        User $user
    ): array {
        $this->authService->authorizeReorderKanban($user);

        $canonicalStage = FittingStageAuthorizationService::normalizeStage($stage);
        if ($canonicalStage === null) {
            throw new InvalidArgumentException("Tahap operasional '{$stage}' tidak valid dalam alur Fitting Production.");
        }

        $normalizedBucket = self::normalizeLineBucket($lineBucket);
        if ($normalizedBucket === null) {
            throw new InvalidArgumentException("Bucket Line '{$lineBucket}' tidak valid (pilih 1, 2, 3, 4, atau unassigned).");
        }

        if ($fromPos < 1 || $toPos < 1) {
            throw new InvalidArgumentException('Posisi antrean asal dan tujuan harus minimal 1.');
        }

        return DB::transaction(function () use ($canonicalStage, $normalizedBucket, $fromPos, $toPos) {
            // Lock ALL active FittingItem rows in this (stage, line_bucket) strictly in ORDER BY id ASC
            $query = FittingItem::where('status', FittingItem::STATUS_ACTIVE)
                ->where('current_stage', $canonicalStage);

            if ($normalizedBucket === self::BUCKET_UNASSIGNED) {
                $query->whereNull('line_number');
            } else {
                $query->where('line_number', (int) $normalizedBucket);
            }

            $lockedItems = $query->orderBy('id', 'asc')
                ->lockForUpdate()
                ->with([
                    'transfer',
                    'stageExecutions' => fn ($q) => $q->orderBy('executed_at', 'asc')->orderBy('id', 'asc'),
                ])
                ->get();

            $count = $lockedItems->count();
            if ($count === 0) {
                throw new InvalidArgumentException('Tidak ada antrean aktif pada bucket yang dipilih.');
            }

            if ($fromPos > $count || $toPos > $count) {
                throw new InvalidArgumentException("Posisi antrean di luar rentang (jumlah antrean saat ini: {$count}).");
            }

            // Build formatted list & sort by authoritative comparator
            $sortable = [];
            $modelsById = [];
            foreach ($lockedItems as $item) {
                $modelsById[$item->id] = $item;
                $sortable[] = $this->formatKanbanCard($item, $canonicalStage);
            }

            usort($sortable, fn (array $a, array $b) => self::compareKanbanCards($a, $b, $canonicalStage));

            // Move item from 1-based $fromPos to 1-based $toPos
            $moved = array_splice($sortable, $fromPos - 1, 1);
            array_splice($sortable, $toPos - 1, 0, $moved);

            // Re-assign dense unique 1..N queue_position strictly on fitting_items (transfer history untouched!)
            $orderedIds = [];
            foreach ($sortable as $idx => $card) {
                $newPos = $idx + 1;
                $model = $modelsById[$card['id']];
                $orderedIds[] = (int) $model->id;

                if ((int) $model->queue_position !== $newPos) {
                    $model->queue_position = $newPos;
                    $model->save();
                }
            }

            return [
                'stage' => $canonicalStage,
                'line_bucket' => $normalizedBucket,
                'total_reordered' => count($orderedIds),
                'from_pos' => $fromPos,
                'to_pos' => $toPos,
                'ordered_ids' => $orderedIds,
            ];
        });
    }

    /**
     * Lookup a single Fitting traveler by PFKTR for Scanner identity card.
     */
    public function findTravelerForScanner(string $travelerNumber, ?string $targetStage = null): ?array
    {
        $pfktr = strtoupper(trim($travelerNumber));
        if ($pfktr === '') {
            return null;
        }

        // Prefer ACTIVE traveler; fall back to latest CANCELLED row so scanner displays exact reason
        $item = FittingItem::where('traveler_number', $pfktr)
            ->where('status', FittingItem::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->with([
                'transfer.transferredBy',
                'allocations',
                'stageExecutions' => fn ($q) => $q->orderBy('executed_at', 'asc')->orderBy('id', 'asc')->with('operator'),
            ])
            ->first();

        if (! $item) {
            $item = FittingItem::where('traveler_number', $pfktr)
                ->orderByDesc('id')
                ->with([
                    'transfer.transferredBy',
                    'allocations',
                    'stageExecutions' => fn ($q) => $q->orderBy('executed_at', 'asc')->orderBy('id', 'asc')->with('operator'),
                ])
                ->first();
        }

        if (! $item) {
            return null;
        }

        return $this->formatScannerPayload($item, $targetStage);
    }

    /**
     * Lookup candidate ACTIVE Fitting travelers by Heat Number (manual fallback ONLY).
     * Never executes scan; operator MUST choose a specific PFKTR from the returned list.
     *
     * @return Collection<int, array>
     */
    public function findTravelersByHeat(string $heatNumber, ?string $targetStage = null): Collection
    {
        $cleanHeat = trim($heatNumber);
        if ($cleanHeat === '') {
            return collect();
        }

        $items = FittingItem::query()
            ->where('status', FittingItem::STATUS_ACTIVE)
            ->where('heat_number', 'like', "%{$cleanHeat}%")
            ->with([
                'transfer.transferredBy',
                'allocations',
                'stageExecutions' => fn ($q) => $q->orderBy('executed_at', 'asc')->orderBy('id', 'asc')->with('operator'),
            ])
            ->orderByRaw('CASE WHEN current_stage = ? THEN 0 ELSE 1 END', [$targetStage ?? ''])
            ->orderBy('traveler_number', 'asc')
            ->limit(50)
            ->get();

        return $items->map(fn (FittingItem $item) => $this->formatScannerPayload($item, $targetStage))->values();
    }

    /**
     * Format a FittingItem into a Kanban Card DTO.
     */
    public function formatKanbanCard(FittingItem $item, string $stage): array
    {
        $normalizedLine = FittingItem::normalizeLineNumber($item->line_number);
        $lineBucket = $normalizedLine !== null ? (string) $normalizedLine : self::BUCKET_UNASSIGNED;

        $stageEntryAt = $this->resolveStageEntryTimestamp($item, $stage);
        $now = Carbon::now();
        $agingHours = $stageEntryAt ? max(0, (int) $stageEntryAt->diffInHours($now)) : 0;
        $agingDays = intdiv($agingHours, 24);
        $agingRemHours = $agingHours % 24;

        $allocations = $item->relationLoaded('allocations')
            ? $item->allocations->map(fn ($a) => [
                'production_code' => $a->production_code,
                'item_code' => $a->item_code,
                'item_name' => $a->item_name,
                'customer' => $a->customer,
                'line_number' => $a->line_number,
                'allocated_qty_transferred' => (int) $a->allocated_qty_transferred,
                'unit_weight_kg' => (float) $a->unit_weight_kg,
                'allocated_weight_kg' => (float) $a->allocated_weight_kg,
            ])->values()->all()
            : [];

        $hasPartialOrMissingWeight = (float) $item->unit_weight_kg <= 0.0;
        $effectiveInputQty = $this->quantityResolver->resolveEffectiveInputQty($item, $stage);
        $upstreamDefectQty = max(0, (int) $item->qty_transferred - $effectiveInputQty);

        return [
            'id' => (int) $item->id,
            'traveler_number' => $item->traveler_number,
            'heat_number' => $item->heat_number,
            'cast_date' => $item->cast_date?->format('Y-m-d'),
            'cast_date_formatted' => $item->cast_date?->format('d/m/Y') ?? '-',
            'transfer_number' => $item->transfer?->transfer_number ?? '-',
            'transfer_date' => $item->transfer?->transfer_date?->format('Y-m-d'),
            'transfer_date_formatted' => $item->transfer?->transfer_date?->format('d/m/Y') ?? '-',
            'transferred_at_raw' => $item->created_at?->format('Y-m-d H:i:s') ?? '9999-12-31 23:59:59',
            'production_code' => $item->production_code,
            'item_code' => $item->item_code,
            'item_name' => $item->item_name ?: '-',
            'customer' => $item->customer ?: '-',
            'line_number' => $normalizedLine,
            'line_bucket' => $lineBucket,
            'line_label' => FittingItem::lineLabelFor($normalizedLine),
            'is_line_assigned' => $normalizedLine !== null,
            'is_multi_allocation' => (bool) $item->is_multi_allocation,
            'tree_count' => (int) $item->tree_count,
            'qty_transferred' => (int) $item->qty_transferred,
            'effective_input_qty' => $effectiveInputQty,
            'upstream_defect_qty' => $upstreamDefectQty,
            'unit_weight_kg' => (float) $item->unit_weight_kg,
            'total_weight_kg' => (float) $item->total_weight_kg,
            'has_incomplete_weight' => $hasPartialOrMissingWeight,
            'current_stage' => $item->current_stage,
            'current_stage_label' => FittingStageAuthorizationService::STAGE_LABELS[$item->current_stage] ?? strtoupper((string) $item->current_stage),
            'queue_position' => $item->queue_position !== null ? (int) $item->queue_position : null,
            'queue_seq' => null,
            'is_urgent' => (bool) $item->is_urgent,
            'stage_entry_raw' => $stageEntryAt?->format('Y-m-d H:i:s') ?? '9999-12-31 23:59:59',
            'stage_entry_formatted' => $stageEntryAt?->format('d/m/Y H:i') ?? '-',
            'aging' => [
                'hours' => $agingHours,
                'days' => $agingDays,
                'label' => $agingDays > 0 ? sprintf('%dH %02dJ', $agingDays, $agingRemHours) : sprintf('%02dJ', $agingHours),
            ],
            'allocations' => $allocations,
        ];
    }

    /**
     * Format a FittingItem into a Scanner lookup payload with eligibility evaluation.
     */
    protected function formatScannerPayload(FittingItem $item, ?string $targetStage = null): array
    {
        $normalizedTarget = $targetStage ? FittingStageAuthorizationService::normalizeStage($targetStage) : null;
        $normalizedLine = FittingItem::normalizeLineNumber($item->line_number);

        $isEligible = false;
        $ineligibleReason = null;

        if ($item->status !== FittingItem::STATUS_ACTIVE) {
            $ineligibleReason = "Transfer traveler [{$item->traveler_number}] sudah DIBATALKAN (CANCELLED) dan tidak dapat dipindai.";
        } elseif ($item->current_stage === FittingItem::STAGE_COMPLETED) {
            $ineligibleReason = "Traveler [{$item->traveler_number}] sudah berstatus SELESAI (COMPLETED) di Gudang Jadi PF.";
        } elseif ($normalizedTarget !== null) {
            $currentIdx = array_search($item->current_stage, FittingItem::STAGES, true);
            $targetIdx = array_search($normalizedTarget, FittingItem::STAGES, true);
            $currentLabel = FittingStageAuthorizationService::STAGE_LABELS[$item->current_stage] ?? strtoupper((string) $item->current_stage);
            $targetLabel = FittingStageAuthorizationService::STAGE_LABELS[$normalizedTarget] ?? strtoupper($normalizedTarget);

            if ($currentIdx !== false && $targetIdx !== false) {
                if ($currentIdx === $targetIdx) {
                    $isEligible = true;
                } elseif ($targetIdx < $currentIdx) {
                    $ineligibleReason = "Traveler sudah melewati tahap {$targetLabel} (posisi saat ini: {$currentLabel}).";
                } else {
                    $ineligibleReason = "Tidak dapat melompati tahap. Traveler saat ini masih di {$currentLabel}, belum mencapai {$targetLabel}.";
                }
            }
        }

        $stageForQty = $normalizedTarget
            ?? ($item->current_stage && $item->current_stage !== FittingItem::STAGE_COMPLETED
                ? $item->current_stage
                : FittingItem::STAGE_GUDANG_JADI_PF);
        $effectiveInputQty = in_array($stageForQty, FittingItem::STAGES, true)
            ? $this->quantityResolver->resolveEffectiveInputQty($item, $stageForQty)
            : (int) $item->qty_transferred;
        $upstreamDefectQty = max(0, (int) $item->qty_transferred - $effectiveInputQty);

        if ($isEligible && $effectiveInputQty <= 0) {
            $isEligible = false;
            $ineligibleReason = "Traveler [{$item->traveler_number}] terhenti (HALTED): kuantitas bagus tersisa 0 PCS karena seluruh kuantitas telah tercatat defect pada tahap sebelumnya.";
        }

        $executions = $item->relationLoaded('stageExecutions')
            ? $item->stageExecutions->map(fn (FittingStageExecution $exec) => [
                'stage' => $exec->stage,
                'stage_label' => FittingStageAuthorizationService::STAGE_LABELS[$exec->stage] ?? strtoupper($exec->stage),
                'checkpoint_code' => $exec->checkpoint_code,
                'input_qty' => (int) $exec->input_qty,
                'defect_qty' => (int) $exec->defect_qty,
                'good_qty' => (int) $exec->good_qty,
                'status' => $exec->status,
                'operator_name' => $exec->operator?->name ?? '-',
                'physical_done_at' => $exec->physical_done_at?->format('d/m/Y H:i:s'),
            ])->values()->all()
            : [];

        $nextStage = FittingItem::nextStageFor($item->current_stage);

        return [
            'id' => (int) $item->id,
            'traveler_number' => $item->traveler_number,
            'heat_number' => $item->heat_number,
            'cast_date' => $item->cast_date?->format('d/m/Y') ?? '-',
            'transfer_number' => $item->transfer?->transfer_number ?? '-',
            'transfer_date' => $item->transfer?->transfer_date?->format('d/m/Y') ?? '-',
            'production_code' => $item->production_code,
            'item_code' => $item->item_code,
            'item_name' => $item->item_name ?: '-',
            'customer' => $item->customer ?: '-',
            'line_number' => $normalizedLine,
            'line_label' => FittingItem::lineLabelFor($normalizedLine),
            'is_multi_allocation' => (bool) $item->is_multi_allocation,
            'qty_transferred' => (int) $item->qty_transferred,
            'effective_input_qty' => $effectiveInputQty,
            'upstream_defect_qty' => $upstreamDefectQty,
            'unit_weight_kg' => (float) $item->unit_weight_kg,
            'total_weight_kg' => (float) $item->total_weight_kg,
            'status' => $item->status,
            'current_stage' => $item->current_stage,
            'current_stage_label' => $item->current_stage === FittingItem::STAGE_COMPLETED
                ? 'COMPLETED (SELESAI)'
                : (FittingStageAuthorizationService::STAGE_LABELS[$item->current_stage] ?? strtoupper((string) $item->current_stage)),
            'next_stage' => $nextStage,
            'next_stage_label' => $nextStage === FittingItem::STAGE_COMPLETED
                ? 'COMPLETED (SELESAI)'
                : ($nextStage ? (FittingStageAuthorizationService::STAGE_LABELS[$nextStage] ?? strtoupper($nextStage)) : null),
            'is_eligible_for_stage' => $isEligible,
            'ineligible_reason' => $ineligibleReason,
            'executions' => $executions,
        ];
    }

    /**
     * Resolve entry timestamp for a FittingItem at its current stage (for FIFO & aging).
     */
    protected function resolveStageEntryTimestamp(FittingItem $item, string $stage): ?Carbon
    {
        if ($stage === FittingItem::STAGE_NETTO_PF) {
            return $item->created_at ?? ($item->transfer?->transfer_date?->copy()->startOfDay());
        }

        $prevStage = FittingItem::previousStageFor($stage);
        if ($prevStage !== null) {
            $prevCheckpoint = FittingStageAuthorizationService::STAGE_CHECKPOINTS[$prevStage] ?? null;
            if ($prevCheckpoint !== null && $item->relationLoaded('stageExecutions')) {
                $prevExec = $item->stageExecutions->firstWhere('checkpoint_code', $prevCheckpoint);
                if ($prevExec) {
                    return $prevExec->physical_done_at ?? $prevExec->executed_at;
                }
            }
        }

        return $item->created_at;
    }

    /**
     * Authoritative deterministic comparator for Fitting Kanban cards within a bucket.
     *
     * Priority:
     * 1. Manual queue_position ASC (PPIC reorder override)
     * 2. is_urgent DESC
     * 3. FIFO:
     *    - For netto_pf: cast_date ASC -> transfer_date ASC -> transferred_at_raw ASC (stable across partial transfers on different days!)
     *    - For downstream stages (kimia..gudang_jadi_pf): stage_entry_raw ASC (earliest completion of predecessor stage)
     * 4. Final deterministic tie-breaker: id ASC
     */
    public static function compareKanbanCards(array $a, array $b, string $stage): int
    {
        $hasPosA = isset($a['queue_position']) && $a['queue_position'] !== null;
        $hasPosB = isset($b['queue_position']) && $b['queue_position'] !== null;

        if ($hasPosA && $hasPosB) {
            if ((int) $a['queue_position'] !== (int) $b['queue_position']) {
                return (int) $a['queue_position'] <=> (int) $b['queue_position'];
            }
        } elseif ($hasPosA) {
            return -1;
        } elseif ($hasPosB) {
            return 1;
        }

        if (($a['is_urgent'] ?? false) !== ($b['is_urgent'] ?? false)) {
            return ! empty($a['is_urgent']) ? -1 : 1;
        }

        if ($stage === FittingItem::STAGE_NETTO_PF) {
            $castA = $a['cast_date'] ?? '9999-12-31';
            $castB = $b['cast_date'] ?? '9999-12-31';
            if ($castA !== $castB) {
                return strcmp($castA, $castB);
            }

            $trfDateA = $a['transfer_date'] ?? '9999-12-31';
            $trfDateB = $b['transfer_date'] ?? '9999-12-31';
            if ($trfDateA !== $trfDateB) {
                return strcmp($trfDateA, $trfDateB);
            }

            $trfAtA = $a['transferred_at_raw'] ?? '9999-12-31 23:59:59';
            $trfAtB = $b['transferred_at_raw'] ?? '9999-12-31 23:59:59';
            if ($trfAtA !== $trfAtB) {
                return strcmp($trfAtA, $trfAtB);
            }
        } else {
            $entryA = $a['stage_entry_raw'] ?? '9999-12-31 23:59:59';
            $entryB = $b['stage_entry_raw'] ?? '9999-12-31 23:59:59';
            if ($entryA !== $entryB) {
                return strcmp($entryA, $entryB);
            }
        }

        return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
    }
}
