<?php

namespace App\Services\Fitting;

use App\Models\FittingItem;
use App\Models\FittingItemAllocation;
use App\Models\FittingKitirDecision;
use App\Models\FittingTransfer;
use App\Models\LostWaxCastingResult;
use App\Models\LostWaxKitir;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FittingTransferService
{
    public function __construct(
        private readonly FittingStageAuthorizationService $authService,
        private readonly FittingKitirSnapshotResolverService $snapshotResolver
    ) {}

    /**
     * Atomically transfer one or more issued Lost Wax Kitirs (PFKTR) into Fitting Production (NETTO PF).
     * Supports Partial Transfer per Kitir without waiting for PO or Casting Result completion.
     *
     * Canonical lock hierarchy (aligned with LostWaxCastingResultService & FittingKitirDecisionService
     * to prevent AB-BA lock-order inversion):
     *   1. lost_wax_casting_results (ORDER BY id ASC)
     *   2. lost_wax_kitirs          (ORDER BY id ASC)
     *   3. fitting_items
     *   4. fitting_kitir_decisions
     *   5. fitting_transfers        (idempotency re-check + FittingTransferNumberGenerator::generateNext())
     *
     * @param  array<int|string>  $rawKitirIds
     *
     * @throws InvalidArgumentException
     */
    public function transferKitirs(
        array $rawKitirIds,
        User $user,
        ?string $notes = null,
        ?string $idempotencyKey = null,
        ?string $transferDate = null,
        array $kitirLines = []
    ): FittingTransfer {
        $this->authService->authorizeTransferKitir($user);

        $kitirIds = array_values(array_unique(array_filter(array_map('intval', $rawKitirIds), fn (int $id) => $id > 0)));
        if (empty($kitirIds)) {
            throw new InvalidArgumentException('Minimal satu Kitir Produksi (PFKTR) harus dipilih untuk ditransfer ke Fitting Production.');
        }
        sort($kitirIds);

        // Normalize & pre-parse kitir_lines map strictly (1..4 integer only; never inferred from item name or ProductionPlan)
        $parsedLinesByKitirId = [];
        foreach ($kitirIds as $kId) {
            $rawVal = null;
            if (array_key_exists($kId, $kitirLines)) {
                $rawVal = $kitirLines[$kId];
            } elseif (array_key_exists((string) $kId, $kitirLines)) {
                $rawVal = $kitirLines[(string) $kId];
            }
            $parsedLinesByKitirId[$kId] = self::parseStrictOperationalLine($rawVal);
        }

        $cleanNotes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;
        $cleanIdempotencyKey = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? substr(trim($idempotencyKey), 0, 64)
            : null;
        $parsedDate = $transferDate ? Carbon::parse($transferDate)->format('Y-m-d') : Carbon::today()->format('Y-m-d');

        // Fast non-locking idempotency check before entering transaction
        if ($cleanIdempotencyKey !== null) {
            $existingByKey = FittingTransfer::with(['items.allocations', 'transferredBy'])
                ->where('idempotency_key', $cleanIdempotencyKey)
                ->first();
            if ($existingByKey) {
                return $existingByKey;
            }
        }

        try {
            return FittingTransferNumberGenerator::runInTransactionWithRetry(function () use (
                $kitirIds,
                $parsedLinesByKitirId,
                $user,
                $cleanNotes,
                $cleanIdempotencyKey,
                $parsedDate
            ) {
                // Pre-read immutable parent Casting Result IDs so parent rows are locked BEFORE child Kitir rows
                $castingResultIds = LostWaxKitir::whereIn('id', $kitirIds)
                    ->pluck('lost_wax_casting_result_id')
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                if (empty($castingResultIds)) {
                    throw new InvalidArgumentException('Sebagian Kitir Produksi yang dipilih tidak ditemukan atau belum diterbitkan di Lost Wax.');
                }

                // 1. Lock parent LostWaxCastingResult headers FIRST (ORDER BY id ASC)
                $castingResults = LostWaxCastingResult::whereIn('id', $castingResultIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                // 2. Lock child LostWaxKitir rows SECOND (ORDER BY id ASC)
                $kitirs = LostWaxKitir::whereIn('id', $kitirIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($kitirs->count() !== count($kitirIds)) {
                    throw new InvalidArgumentException('Sebagian Kitir Produksi yang dipilih tidak ditemukan atau belum diterbitkan di Lost Wax.');
                }

                // Ensure any casting result referenced by locked kitirs is present in locked map
                $missingParentIds = $kitirs->pluck('lost_wax_casting_result_id')
                    ->unique()
                    ->diff($castingResults->keys())
                    ->sort()
                    ->values()
                    ->all();
                if (! empty($missingParentIds)) {
                    $extraParents = LostWaxCastingResult::whereIn('id', $missingParentIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');
                    $castingResults = $castingResults->union($extraParents);
                }

                // 2.5. Strict Fail-All Line Validation BEFORE any transfer write:
                // Every selected Kitir MUST have a valid PPIC-selected Fitting Line (1..4).
                $missingLineTravelers = [];
                foreach ($kitirIds as $kitirId) {
                    if (($parsedLinesByKitirId[$kitirId] ?? null) === null) {
                        $missingKitir = $kitirs->get($kitirId);
                        $missingLineTravelers[] = $missingKitir?->traveler_number ?? "Kitir #{$kitirId}";
                    }
                }

                if (! empty($missingLineTravelers)) {
                    $missingCount = count($missingLineTravelers);
                    $totalSelected = count($kitirIds);
                    $listPreview = implode(', ', array_slice($missingLineTravelers, 0, 5));
                    if ($missingCount > 5) {
                        $listPreview .= ' (+'.($missingCount - 5).' lainnya)';
                    }

                    throw new InvalidArgumentException(
                        "Transfer ditolak: masih ada {$missingCount} dari {$totalSelected} Kitir terpilih yang belum memiliki pilihan Line Fitting yang valid (1–4): {$listPreview}. Pilih Line Fitting untuk seluruh Kitir terpilih sebelum mentransfer."
                    );
                }

                // Eager load source relations needed for immutable snapshot resolution (read-only)
                $kitirs->load([
                    'castingResult.trees.tree.allocations.printOrderLine.productionPlan',
                    'castingResult.trees.tree.printOrderLine.productionPlan',
                    'castingResult.trees.tree.workOrder.itemReference',
                ]);

                // 3 & 4. Validate every selected Kitir atomically (locking fitting_items -> fitting_kitir_decisions)
                $preparedItems = [];
                $totalPcs = 0;
                $totalWeightKg = 0.0;

                foreach ($kitirIds as $kitirId) {
                    /** @var LostWaxKitir $kitir */
                    $kitir = $kitirs->get($kitirId);
                    if (! $kitir) {
                        throw new InvalidArgumentException("Kitir ID [{$kitirId}] tidak ditemukan.");
                    }

                    if ($kitir->issued_at === null) {
                        throw new InvalidArgumentException("Kitir [{$kitir->traveler_number}] belum berstatus diterbitkan (issued_at kosong) sehingga tidak dapat ditransfer.");
                    }

                    /** @var LostWaxCastingResult|null $castingResult */
                    $castingResult = $castingResults->get($kitir->lost_wax_casting_result_id);
                    if (! $castingResult || $castingResult->status !== 'COMPLETED') {
                        $heatLabel = $castingResult?->heat_number ?? '-';
                        throw new InvalidArgumentException("Kitir [{$kitir->traveler_number}] berasal dari Heat [{$heatLabel}] yang telah dibatalkan (CANCELLED) dan tidak dapat ditransfer.");
                    }

                    // 3. Lock & verify fitting_items (not already actively transferred / completed)
                    $hasActiveItem = FittingItem::where(function ($q) use ($kitir) {
                        $q->where('active_kitir_id', $kitir->id)
                            ->orWhere(function ($q2) use ($kitir) {
                                $q2->where('lost_wax_kitir_id', $kitir->id)
                                    ->where('status', FittingItem::STATUS_ACTIVE);
                            });
                    })->lockForUpdate()->exists();

                    if ($hasActiveItem) {
                        throw new InvalidArgumentException("Kitir [{$kitir->traveler_number}] sudah ditransfer ke Fitting Production dan tidak dapat ditransfer ulang.");
                    }

                    // 4. Lock & verify fitting_kitir_decisions (not currently marked NOT_CONTINUED)
                    $hasActiveDecision = FittingKitirDecision::where(function ($q) use ($kitir) {
                        $q->where('active_kitir_id', $kitir->id)
                            ->orWhere(function ($q2) use ($kitir) {
                                $q2->where('lost_wax_kitir_id', $kitir->id)
                                    ->where('status', FittingKitirDecision::STATUS_ACTIVE);
                            });
                    })->lockForUpdate()->exists();

                    if ($hasActiveDecision) {
                        throw new InvalidArgumentException("Kitir [{$kitir->traveler_number}] berstatus TIDAK DILANJUTKAN (NOT_CONTINUED). Kembalikan ke Daftar Transfer terlebih dahulu jika ingin mentransfer.");
                    }

                    if ((int) $kitir->quantity_pcs <= 0) {
                        throw new InvalidArgumentException("Kitir [{$kitir->traveler_number}] memiliki kuantitas 0 PCS dan tidak dapat ditransfer.");
                    }

                    $snapshot = $this->snapshotResolver->resolve($kitir);

                    $totalPcs += $snapshot['qty_transferred'];
                    $totalWeightKg += $snapshot['total_weight_kg'];

                    $preparedItems[] = [
                        'kitir' => $kitir,
                        'casting_result' => $castingResult,
                        'snapshot' => $snapshot,
                        'line_number' => $parsedLinesByKitirId[$kitirId],
                    ];
                }

                $totalWeightKg = round($totalWeightKg, 2);

                // 5. Lock fitting_transfers LAST: re-check idempotency & generate sequential number INSIDE transaction
                if ($cleanIdempotencyKey !== null) {
                    $existingByKey = FittingTransfer::where('idempotency_key', $cleanIdempotencyKey)
                        ->lockForUpdate()
                        ->first();
                    if ($existingByKey) {
                        return $existingByKey->load(['items.allocations', 'transferredBy']);
                    }
                }

                $transferNumber = FittingTransferNumberGenerator::generateNext($parsedDate);

                // 6. Create FittingTransfer batch header
                $transfer = FittingTransfer::create([
                    'transfer_number' => $transferNumber,
                    'transfer_date' => $parsedDate,
                    'transferred_by' => $user->id,
                    'total_kitirs' => count($preparedItems),
                    'total_pcs' => $totalPcs,
                    'total_weight_kg' => $totalWeightKg,
                    'status' => FittingTransfer::STATUS_ACTIVE,
                    'notes' => $cleanNotes,
                    'idempotency_key' => $cleanIdempotencyKey,
                ]);

                // 7. Create FittingItem (initial stage: netto_pf) + FittingItemAllocation snapshots
                foreach ($preparedItems as $entry) {
                    /** @var LostWaxKitir $kitir */
                    $kitir = $entry['kitir'];
                    /** @var LostWaxCastingResult $castingResult */
                    $castingResult = $entry['casting_result'];
                    $snap = $entry['snapshot'];
                    $finalLineNumber = (int) $entry['line_number'];

                    $fittingItem = FittingItem::create([
                        'fitting_transfer_id' => $transfer->id,
                        'lost_wax_kitir_id' => $kitir->id,
                        'active_kitir_id' => $kitir->id, // DB UNIQUE anti-double-transfer
                        'lost_wax_casting_result_id' => $castingResult->id,
                        'production_plan_id' => $snap['production_plan_id'],
                        'traveler_number' => $kitir->traveler_number,
                        'heat_number' => $castingResult->heat_number,
                        'cast_date' => $castingResult->cast_date,
                        'production_code' => $snap['production_code'],
                        'item_code' => $snap['item_code'],
                        'item_name' => $snap['item_name'],
                        'customer' => $snap['customer'],
                        'line_number' => $finalLineNumber, // Final Operational Line (1..4) selected by PPIC at transfer
                        'tree_count' => $snap['tree_count'],
                        'qty_transferred' => $snap['qty_transferred'],
                        'unit_weight_kg' => $snap['unit_weight_kg'],
                        'total_weight_kg' => $snap['total_weight_kg'],
                        'is_multi_allocation' => $snap['is_multi_allocation'],
                        'current_stage' => FittingItem::STAGE_NETTO_PF,
                        'status' => FittingItem::STATUS_ACTIVE,
                        'notes' => $cleanNotes,
                    ]);

                    foreach ($snap['allocations'] as $alloc) {
                        FittingItemAllocation::create([
                            'fitting_item_id' => $fittingItem->id,
                            'production_plan_id' => $alloc['production_plan_id'],
                            'production_code' => $alloc['production_code'],
                            'item_code' => $alloc['item_code'],
                            'item_name' => $alloc['item_name'],
                            'customer' => $alloc['customer'],
                            'line_number' => $finalLineNumber, // Aligned 100% with parent traveler's Final Operational Line
                            'allocated_qty_transferred' => $alloc['allocated_qty_transferred'],
                            'unit_weight_kg' => $alloc['unit_weight_kg'],
                            'allocated_weight_kg' => $alloc['allocated_weight_kg'],
                        ]);
                    }
                }

                return $transfer->load(['items.allocations', 'transferredBy']);
            });
        } catch (QueryException $e) {
            if ($this->isActiveKitirUniqueViolation($e)) {
                throw new InvalidArgumentException('Salah satu Kitir yang dipilih baru saja ditransfer oleh transaksi bersamaan. Silakan muat ulang daftar transfer.', 0, $e);
            }

            if ($cleanIdempotencyKey !== null && $this->isIdempotencyViolation($e)) {
                $existing = FittingTransfer::with(['items.allocations', 'transferredBy'])
                    ->where('idempotency_key', $cleanIdempotencyKey)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            throw $e;
        }
    }

    /**
     * Cancel an active FittingItem transfer ONLY if no physical scan (`fitting_stage_executions`) has occurred.
     * Per Phase 2 M4 contract: sets `status = CANCELLED`, `active_kitir_id = null`, `current_stage = null`.
     *
     * Canonical lock hierarchy:
     *   1. lost_wax_casting_results -> 2. lost_wax_kitirs -> 3. fitting_items -> 5. fitting_transfers
     * Phase 3 requirement: Scanner MUST lock `fitting_items` (`lockForUpdate()`) and check `status === 'ACTIVE'`
     * before inserting into `fitting_stage_executions` to serialize against `cancelTransferItem()`.
     *
     * @throws InvalidArgumentException
     */
    public function cancelTransferItem(FittingItem $item, User $user, string $reason): FittingItem
    {
        $this->authService->authorizeTransferKitir($user);

        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw new InvalidArgumentException('Alasan pembatalan transfer wajib diisi.');
        }

        return DB::transaction(function () use ($item, $user, $cleanReason) {
            // 1. Lock parent LostWaxCastingResult FIRST
            LostWaxCastingResult::where('id', $item->lost_wax_casting_result_id)
                ->lockForUpdate()
                ->first();

            // 2. Lock child LostWaxKitir SECOND
            LostWaxKitir::where('id', $item->lost_wax_kitir_id)
                ->lockForUpdate()
                ->firstOrFail();

            // 3. Lock FittingItem THIRD
            $lockedItem = FittingItem::where('id', $item->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedItem->status === FittingItem::STATUS_CANCELLED) {
                throw new InvalidArgumentException("Transfer Kitir [{$lockedItem->traveler_number}] sudah dibatalkan sebelumnya.");
            }

            if ($lockedItem->stageExecutions()->lockForUpdate()->exists()) {
                throw new InvalidArgumentException("Transfer Kitir [{$lockedItem->traveler_number}] tidak dapat dibatalkan karena sudah memiliki riwayat scan di lantai produksi Fitting.");
            }

            $lockedItem->update([
                'status' => FittingItem::STATUS_CANCELLED,
                'active_kitir_id' => null,
                'current_stage' => null,
                'cancelled_by' => $user->id,
                'cancelled_at' => Carbon::now(),
                'cancel_reason' => $cleanReason,
            ]);

            // 5. Lock parent FittingTransfer LAST; if all items are cancelled, mark header CANCELLED too
            $transfer = FittingTransfer::where('id', $lockedItem->fitting_transfer_id)
                ->lockForUpdate()
                ->first();

            if ($transfer && ! $transfer->items()->where('status', FittingItem::STATUS_ACTIVE)->exists()) {
                $transfer->update([
                    'status' => FittingTransfer::STATUS_CANCELLED,
                    'cancelled_by' => $user->id,
                    'cancelled_at' => Carbon::now(),
                    'cancel_reason' => $cleanReason,
                ]);
            }

            return $lockedItem->fresh(['transfer', 'lostWaxKitir', 'allocations', 'cancelledBy']);
        });
    }

    /**
     * Get enriched data for the Transfer Kitir workspace (Tabs: available, transferred, decisions).
     *
     * @param  array{tab?: string, search?: string|null, date_from?: string|null, date_to?: string|null, line?: string|null}  $filters
     * @return array{
     *     tab: string,
     *     available_rows: Collection<int, array>,
     *     transferred_items: \Illuminate\Contracts\Pagination\LengthAwarePaginator,
     *     decisions: \Illuminate\Contracts\Pagination\LengthAwarePaginator,
     *     counts: array{available: int, available_pcs: int, transferred_active: int, transferred_cancelled: int, decisions_active: int, decisions_total: int}
     * }
     */
    public function getWorkspaceData(array $filters = []): array
    {
        $tab = in_array($filters['tab'] ?? 'available', ['available', 'transferred', 'decisions'], true)
            ? $filters['tab']
            : 'available';

        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        $dateFrom = isset($filters['date_from']) ? trim((string) $filters['date_from']) : '';
        $dateTo = isset($filters['date_to']) ? trim((string) $filters['date_to']) : '';
        $lineFilter = isset($filters['line']) ? trim((string) $filters['line']) : '';

        // Global summary counts (unfiltered for tab badges)
        $baseAvailableQuery = LostWaxKitir::query()
            ->whereNotNull('issued_at')
            ->whereHas('castingResult', fn ($q) => $q->where('status', 'COMPLETED'))
            ->whereDoesntHave('activeFittingItem')
            ->whereDoesntHave('activeFittingDecision');

        $counts = [
            'available' => (clone $baseAvailableQuery)->count(),
            'available_pcs' => (int) (clone $baseAvailableQuery)->sum('quantity_pcs'),
            'transferred_active' => FittingItem::where('status', FittingItem::STATUS_ACTIVE)->count(),
            'transferred_cancelled' => FittingItem::where('status', FittingItem::STATUS_CANCELLED)->count(),
            'decisions_active' => FittingKitirDecision::where('status', FittingKitirDecision::STATUS_ACTIVE)->count(),
            'decisions_total' => FittingKitirDecision::count(),
        ];

        // 1. Tab: Available Kitirs for Transfer
        $availableQuery = (clone $baseAvailableQuery)
            ->with([
                'issuer',
                'castingResult.trees.tree.allocations.printOrderLine.productionPlan',
                'castingResult.trees.tree.printOrderLine.productionPlan',
                'castingResult.trees.tree.workOrder.itemReference',
            ])
            ->orderByDesc('issued_at')
            ->orderByDesc('id');

        if ($search !== '') {
            $availableQuery->where(function ($q) use ($search) {
                $q->where('traveler_number', 'like', "%{$search}%")
                    ->orWhere('production_code', 'like', "%{$search}%")
                    ->orWhere('customer', 'like', "%{$search}%")
                    ->orWhere('item_name', 'like', "%{$search}%")
                    ->orWhereHas('castingResult', fn ($cq) => $cq->where('heat_number', 'like', "%{$search}%"));
            });
        }

        if ($dateFrom !== '') {
            $availableQuery->whereHas('castingResult', fn ($cq) => $cq->whereDate('cast_date', '>=', $dateFrom));
        }

        if ($dateTo !== '') {
            $availableQuery->whereHas('castingResult', fn ($cq) => $cq->whereDate('cast_date', '<=', $dateTo));
        }

        $availableRows = $availableQuery->get()->map(function (LostWaxKitir $kitir) {
            $snapshot = $this->snapshotResolver->resolve($kitir);

            return [
                'kitir' => $kitir,
                'casting_result' => $kitir->castingResult,
                'snapshot' => $snapshot,
            ];
        });

        // Note: Line Fitting is chosen right before transfer, so Line filter applies only to `tab=transferred` (snapshot).

        // 2. Tab: Transferred Items (Masuk Netto PF & Riwayat Transfer)
        $transferredQuery = FittingItem::query()
            ->with(['transfer.transferredBy', 'lostWaxKitir', 'lostWaxCastingResult', 'allocations', 'cancelledBy'])
            ->withCount('stageExecutions')
            ->orderByRaw("CASE WHEN status = 'ACTIVE' THEN 0 ELSE 1 END")
            ->orderByDesc('id');

        if ($search !== '') {
            $transferredQuery->where(function ($q) use ($search) {
                $q->where('traveler_number', 'like', "%{$search}%")
                    ->orWhere('heat_number', 'like', "%{$search}%")
                    ->orWhere('production_code', 'like', "%{$search}%")
                    ->orWhere('customer', 'like', "%{$search}%")
                    ->orWhere('item_name', 'like', "%{$search}%")
                    ->orWhereHas('transfer', fn ($tq) => $tq->where('transfer_number', 'like', "%{$search}%"));
            });
        }

        if ($dateFrom !== '') {
            $transferredQuery->whereHas('transfer', fn ($tq) => $tq->whereDate('transfer_date', '>=', $dateFrom));
        }

        if ($dateTo !== '') {
            $transferredQuery->whereHas('transfer', fn ($tq) => $tq->whereDate('transfer_date', '<=', $dateTo));
        }

        if ($lineFilter !== '') {
            if ($lineFilter === 'unassigned') {
                $transferredQuery->whereNull('line_number');
            } elseif (in_array((int) $lineFilter, FittingItem::VALID_LINE_NUMBERS, true)) {
                $transferredQuery->where('line_number', (int) $lineFilter);
            }
        }

        $transferredItems = $transferredQuery->paginate(25, ['*'], 'transferred_page')->withQueryString();

        // 3. Tab: Decision History (NOT_CONTINUED & REOPENED)
        $decisionsQuery = FittingKitirDecision::query()
            ->with([
                'lostWaxKitir.activeFittingItem',
                'lostWaxCastingResult',
                'decidedBy',
                'reopenedBy',
            ])
            ->orderByRaw("CASE WHEN status = 'ACTIVE' THEN 0 ELSE 1 END")
            ->orderByDesc('decided_at')
            ->orderByDesc('id');

        if ($search !== '') {
            $decisionsQuery->where(function ($q) use ($search) {
                $q->where('reason', 'like', "%{$search}%")
                    ->orWhereHas('lostWaxKitir', function ($kq) use ($search) {
                        $kq->where('traveler_number', 'like', "%{$search}%")
                            ->orWhere('production_code', 'like', "%{$search}%")
                            ->orWhere('customer', 'like', "%{$search}%")
                            ->orWhere('item_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('lostWaxCastingResult', fn ($cq) => $cq->where('heat_number', 'like', "%{$search}%"));
            });
        }

        if ($dateFrom !== '') {
            $decisionsQuery->whereDate('decided_at', '>=', $dateFrom);
        }

        if ($dateTo !== '') {
            $decisionsQuery->whereDate('decided_at', '<=', $dateTo);
        }

        $decisions = $decisionsQuery->paginate(25, ['*'], 'decisions_page')->withQueryString();

        return [
            'tab' => $tab,
            'available_rows' => $availableRows,
            'transferred_items' => $transferredItems,
            'decisions' => $decisions,
            'counts' => $counts,
        ];
    }

    /**
     * Strictly parse a PPIC-submitted operational Fitting Line (only integer 1..4 accepted).
     */
    public static function parseStrictOperationalLine(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return in_array($raw, FittingItem::VALID_LINE_NUMBERS, true) ? $raw : null;
        }

        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed !== '' && ctype_digit($trimmed)) {
                $val = (int) $trimmed;

                return in_array($val, FittingItem::VALID_LINE_NUMBERS, true) ? $val : null;
            }
        }

        return null;
    }

    protected function isActiveKitirUniqueViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'uk_fit_items_active_kitir')
            || str_contains($message, 'fitting_items.active_kitir_id');
    }

    protected function isIdempotencyViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'uk_fit_transfers_idempotency')
            || str_contains($message, 'fitting_transfers.idempotency_key');
    }
}
