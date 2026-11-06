<?php

namespace App\Services\SandCasting;

use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\SandCastingWipSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class SandCastingWipSnapshotService
{
    public const TIMEZONE = 'Asia/Jakarta';

    public const CUTOFF_TIME = '07:00:00';

    public const WIP_STAGES = [
        'netto' => 'NETTO',
        'bubut_od' => 'Bubut OD',
        'bubut_cnc' => 'Bubut CNC',
        'bor' => 'BOR',
        'qc' => 'QC',
    ];

    public function __construct(
        protected ?SandCastingProductionStatusService $statusService = null,
        protected ?SandCastingQuantityResolverService $quantityResolver = null,
        protected ?SandCastingDashboardService $dashboardService = null
    ) {
        $this->quantityResolver = $quantityResolver ?? new SandCastingQuantityResolverService;
        $this->statusService = $statusService ?? new SandCastingProductionStatusService($this->quantityResolver);
    }

    public function getDashboardService(): SandCastingDashboardService
    {
        return $this->dashboardService ??= app(SandCastingDashboardService::class);
    }

    public function setDashboardService(SandCastingDashboardService $dashboardService): self
    {
        $this->dashboardService = $dashboardService;

        return $this;
    }

    /**
     * Resolve effective unit weight in KG for a KTR line.
     */
    public function resolveUnitWeightKg(SandCastingCastingResultLine $line): float
    {
        if ($line->unit_weight_kg !== null && (float) $line->unit_weight_kg > 0) {
            return (float) $line->unit_weight_kg;
        }

        if ($line->productionPlan && $line->productionPlan->weight !== null && (float) $line->productionPlan->weight > 0) {
            return (float) $line->productionPlan->weight;
        }

        return 0.0;
    }

    /**
     * Calculate live active WIP metrics for a specific stage and optional scope.
     * Reuses the official Zone 2 pipeline aggregation as the single source of truth.
     *
     * @return array{qty_pcs: int, weight_kg: float, ktr_count: int, details: array}
     */
    public function computeLiveActiveWip(string $stage, ?string $scope = null): array
    {
        $normalizedStage = strtolower(trim($stage));
        if (! array_key_exists($normalizedStage, self::WIP_STAGES)) {
            throw new InvalidArgumentException("Tahap '{$stage}' bukan tahap WIP aktif Sand Casting.");
        }

        $pipeline = $this->getDashboardService()->getZone2ActiveWipPipelineData($scope);
        $stageData = $pipeline['stages'][$normalizedStage] ?? null;

        if (! $stageData) {
            throw new InvalidArgumentException("Tahap '{$stage}' tidak ditemukan di pipeline resmi Zona 2.");
        }

        return [
            'qty_pcs' => (int) $stageData['qty_pcs'],
            'weight_kg' => (float) $stageData['weight_kg'],
            'ktr_count' => (int) $stageData['ktr_count'],
            'details' => $stageData['details'],
        ];
    }

    /**
     * Capture daily WIP snapshot for a specific cutoff date/time.
     * Guaranteed idempotent and protects valid actual snapshots from being overwritten.
     * Reuses official Zone 2 pipeline metrics.
     *
     * @param  Carbon|string|null  $date  Operational snapshot date (Y-m-d)
     * @param  string|null  $scope  Product scope (e.g. 'FLANGE_BESI', 'FLANGE_STAINLESS', or 'all')
     * @param  bool  $force  If true, forces overwrite of existing snapshot
     * @param  string  $sourceType  'actual' or 'reconstructed'
     * @return Collection<int, SandCastingWipSnapshot>
     */
    public function captureSnapshot(
        Carbon|string|null $date = null,
        ?string $scope = null,
        bool $force = false,
        string $sourceType = SandCastingWipSnapshot::SOURCE_ACTUAL
    ): Collection {
        $now = Carbon::now(self::TIMEZONE);
        $snapshotDate = $date ? Carbon::parse($date, self::TIMEZONE)->toDateString() : $now->toDateString();
        $cutoffScheduled = Carbon::parse("{$snapshotDate} ".self::CUTOFF_TIME, self::TIMEZONE);
        $scopeKey = ($scope && $scope !== '') ? $scope : 'all';

        // Check explicit lateness relative to 07:00:00 cutoff (+30m grace)
        $todayStr = $now->toDateString();
        $isDelayed = ($snapshotDate < $todayStr) || ($snapshotDate === $todayStr && $now->greaterThan($cutoffScheduled->copy()->addMinutes(30)));
        $delayMinutes = $isDelayed ? (int) $cutoffScheduled->diffInMinutes($now, false) : 0;

        $savedSnapshots = collect();
        $pipelineData = $this->getDashboardService()->getZone2ActiveWipPipelineData($scopeKey);

        foreach (array_keys(self::WIP_STAGES) as $stage) {
            $existing = SandCastingWipSnapshot::where('snapshot_date', $snapshotDate)
                ->where('stage', $stage)
                ->where('product_scope', $scopeKey)
                ->first();

            // Protection: valid actual snapshot cannot be overwritten by subsequent scheduler runs unless forced
            if ($existing && ! $force && $existing->status === SandCastingWipSnapshot::STATUS_SUCCESS && $existing->source_type === SandCastingWipSnapshot::SOURCE_ACTUAL) {
                Log::info("[WipSnapshot] Snapshot untuk stage {$stage} tanggal {$snapshotDate} (scope: {$scopeKey}) sudah ada dan valid. Lewati.");
                $savedSnapshots->push($existing);

                continue;
            }

            try {
                $stageData = $pipelineData['stages'][$stage] ?? null;
                if (! $stageData) {
                    throw new InvalidArgumentException("Data tahap {$stage} tidak ditemukan di pipeline Zona 2.");
                }

                $stageDetails = $stageData['details'];
                if ($isDelayed && $sourceType === SandCastingWipSnapshot::SOURCE_ACTUAL) {
                    $stageDetails['delayed_capture'] = true;
                    $stageDetails['delay_minutes'] = $delayMinutes;
                    $stageDetails['observation_note'] = "Observasi aktual diambil pada {$now->format('H:i:s')} WIB (terlambat {$delayMinutes} menit dari jadwal cutoff 07:00 WIB).";
                }

                $snapshot = SandCastingWipSnapshot::updateOrCreate(
                    [
                        'snapshot_date' => $snapshotDate,
                        'stage' => $stage,
                        'product_scope' => $scopeKey,
                    ],
                    [
                        'scheduled_cutoff_at' => $cutoffScheduled,
                        'captured_at' => $now,
                        'qty_pcs' => (int) $stageData['qty_pcs'],
                        'weight_kg' => (float) $stageData['weight_kg'],
                        'ktr_count' => (int) $stageData['ktr_count'],
                        'source_type' => $sourceType,
                        'status' => SandCastingWipSnapshot::STATUS_SUCCESS,
                        'details' => $stageDetails,
                    ]
                );

                $savedSnapshots->push($snapshot);
            } catch (\Throwable $e) {
                Log::error("[WipSnapshot] Gagal merekam snapshot {$stage} pada {$snapshotDate}: {$e->getMessage()}");

                // Record failure without corrupting or zeroing existing valid actual data
                if (! $existing) {
                    $failedSnapshot = SandCastingWipSnapshot::create([
                        'snapshot_date' => $snapshotDate,
                        'stage' => $stage,
                        'product_scope' => $scopeKey,
                        'scheduled_cutoff_at' => $cutoffScheduled,
                        'captured_at' => $now,
                        'qty_pcs' => 0,
                        'weight_kg' => 0.0,
                        'ktr_count' => 0,
                        'source_type' => $sourceType,
                        'status' => SandCastingWipSnapshot::STATUS_FAILED,
                        'details' => ['error' => $e->getMessage()],
                    ]);
                    $savedSnapshots->push($failedSnapshot);
                }
            }
        }

        return $savedSnapshots;
    }

    /**
     * Capture snapshots for all relevant scopes ('all' + distinct Sand Casting scopes).
     *
     * @return Collection<int, SandCastingWipSnapshot>
     */
    public function captureAllScopesSnapshot(
        Carbon|string|null $date = null,
        bool $force = false,
        string $sourceType = SandCastingWipSnapshot::SOURCE_ACTUAL
    ): Collection {
        $scopes = array_unique(array_merge(['all'], $this->getDashboardService()->getDistinctProductScopesForSnapshot()));
        $allSnapshots = collect();

        foreach ($scopes as $sc) {
            $snaps = $this->captureSnapshot($date, $sc, $force, $sourceType);
            $allSnapshots = $allSnapshots->merge($snaps);
        }

        return $allSnapshots;
    }

    /**
     * Reconstruct historical WIP snapshot for past dates using historical execution logs.
     * Ensures historical evidence is verified; does not invent ungrounded data.
     */
    public function reconstructHistoricalSnapshot(
        Carbon|string $targetDate,
        ?string $scope = null,
        bool $force = false
    ): Collection {
        $dateStr = Carbon::parse($targetDate, self::TIMEZONE)->toDateString();
        $cutoff = Carbon::parse("{$dateStr} ".self::CUTOFF_TIME, self::TIMEZONE);
        $scopeKey = ($scope && $scope !== '') ? $scope : 'all';

        $savedSnapshots = collect();
        $categoryMap = \App\Models\Customer::getCategoryMap();

        foreach (array_keys(self::WIP_STAGES) as $stage) {
            $existing = SandCastingWipSnapshot::where('snapshot_date', $dateStr)
                ->where('stage', $stage)
                ->where('product_scope', $scopeKey)
                ->first();

            if ($existing && ! $force && $existing->status === SandCastingWipSnapshot::STATUS_SUCCESS) {
                $savedSnapshots->push($existing);

                continue;
            }

            // Find all lines that were created/cast on or before the cutoff timestamp
            $linesQuery = SandCastingCastingResultLine::query()
                ->where('created_at', '<=', $cutoff)
                ->with([
                    'castingResult',
                    'productionPlan',
                    'castingOrderLine',
                    'stageExecutions' => fn ($q) => $q->orderBy('executed_at', 'asc')->orderBy('id', 'asc'),
                ]);

            if ($scopeKey !== 'all') {
                $linesQuery->whereHas('productionPlan', fn ($p) => $p->where('product_scope', $scopeKey));
            }

            $candidateLines = $linesQuery->get();
            $stageOrder = SandCastingQuantityResolverService::STAGE_ORDER[$stage] ?? 99;

            $pcs = 0;
            $kg = 0.0;
            $ktrCount = 0;
            $details = [];
            $reconBreakdown = [
                'local' => ['pcs' => 0, 'kg' => 0.0],
                'export' => ['pcs' => 0, 'kg' => 0.0],
                'unclassified' => ['pcs' => 0, 'kg' => 0.0],
            ];

            foreach ($candidateLines as $line) {
                $execsAtCutoff = $line->stageExecutions->filter(function (SandCastingStageExecution $e) use ($cutoff) {
                    $ts = $e->physical_done_at ?? $e->executed_at ?? $e->created_at;

                    return $ts && $ts <= $cutoff;
                });

                // Determine if KTR was in this stage at the cutoff time
                // Check if it had finished this stage or any downstream stage by cutoff
                $hasFinishedThisOrLater = $execsAtCutoff->contains(function (SandCastingStageExecution $e) use ($stageOrder) {
                    $norm = SandCastingQuantityResolverService::normalizeStageName($e->stage);
                    $ord = SandCastingQuantityResolverService::STAGE_ORDER[$norm] ?? 0;

                    return $ord >= $stageOrder && $e->physical_done_at !== null;
                });

                if ($hasFinishedThisOrLater) {
                    continue; // Already finished this stage before cutoff
                }

                // Check if it had reached this stage before cutoff
                $hasReachedStage = false;
                if ($stage === 'netto') {
                    // Netto is initial stage right after pouring
                    $castDate = $line->castingResult?->cast_date;
                    $hasReachedStage = ($line->created_at <= $cutoff) || ($castDate && $castDate <= $cutoff);
                } else {
                    // Must have completed previous upstream stage before cutoff
                    $prevOrder = $stageOrder - 1;
                    $hasReachedStage = $execsAtCutoff->contains(function (SandCastingStageExecution $e) use ($prevOrder) {
                        $norm = SandCastingQuantityResolverService::normalizeStageName($e->stage);
                        $ord = SandCastingQuantityResolverService::STAGE_ORDER[$norm] ?? 0;

                        return $ord === $prevOrder && $e->physical_done_at !== null;
                    });
                }

                if (! $hasReachedStage) {
                    continue; // Has not reached this stage yet
                }

                // Calculate historical effective quantity
                $rootGood = (int) ($line->qty_good ?? 0);
                if ($rootGood <= 0) {
                    continue;
                }

                $defectsBefore = $execsAtCutoff
                    ->filter(function (SandCastingStageExecution $e) use ($stageOrder) {
                        $norm = SandCastingQuantityResolverService::normalizeStageName($e->stage);
                        $ord = SandCastingQuantityResolverService::STAGE_ORDER[$norm] ?? 0;

                        return $ord < $stageOrder;
                    })
                    ->sum('defect_qty');

                $historicalUsable = max(0, $rootGood - (int) $defectsBefore);
                if ($historicalUsable <= 0) {
                    continue;
                }

                $w = $this->resolveUnitWeightKg($line);
                $lineKg = ($historicalUsable * $w);

                $pcs += $historicalUsable;
                $kg += $lineKg;
                $ktrCount++;

                $custName = $line->productionPlan?->customer ?: ($line->castingOrderLine?->customer ?? '');
                $cat = \App\Models\Customer::resolveCategory($custName, $categoryMap);
                $catKey = strtolower($cat);
                if (! isset($reconBreakdown[$catKey])) {
                    $catKey = 'unclassified';
                }

                $reconBreakdown[$catKey]['pcs'] += $historicalUsable;
                $reconBreakdown[$catKey]['kg'] += $lineKg;

                $details[] = [
                    'traveler_number' => $line->traveler_number,
                    'usable_pcs' => $historicalUsable,
                    'kg' => round($lineKg, 4),
                ];
            }

            $roundLocalKg = round((float) $reconBreakdown['local']['kg'], 4);
            $roundExportKg = round((float) $reconBreakdown['export']['kg'], 4);
            $roundUnclassKg = round((float) $reconBreakdown['unclassified']['kg'], 4);
            $totalReconKg = round($kg, 4);

            $diff = round($totalReconKg - ($roundLocalKg + $roundExportKg + $roundUnclassKg), 4);
            if ($diff !== 0.0) {
                if ($roundLocalKg > 0) {
                    $roundLocalKg = round($roundLocalKg + $diff, 4);
                } elseif ($roundExportKg > 0) {
                    $roundExportKg = round($roundExportKg + $diff, 4);
                } elseif ($roundUnclassKg > 0) {
                    $roundUnclassKg = round($roundUnclassKg + $diff, 4);
                }
            }

            $breakdownFinal = [
                'local' => ['pcs' => (int) $reconBreakdown['local']['pcs'], 'kg' => $roundLocalKg],
                'export' => ['pcs' => (int) $reconBreakdown['export']['pcs'], 'kg' => $roundExportKg],
                'unclassified' => ['pcs' => (int) $reconBreakdown['unclassified']['pcs'], 'kg' => $roundUnclassKg],
            ];

            $snapshot = SandCastingWipSnapshot::updateOrCreate(
                [
                    'snapshot_date' => $dateStr,
                    'stage' => $stage,
                    'product_scope' => $scopeKey,
                ],
                [
                    'scheduled_cutoff_at' => $cutoff,
                    'captured_at' => Carbon::now(self::TIMEZONE),
                    'qty_pcs' => $pcs,
                    'weight_kg' => round($kg, 4),
                    'ktr_count' => $ktrCount,
                    'source_type' => SandCastingWipSnapshot::SOURCE_RECONSTRUCTED,
                    'status' => SandCastingWipSnapshot::STATUS_SUCCESS,
                    'details' => [
                        'reconstructed' => true,
                        'breakdown' => $breakdownFinal,
                        'sample_ktrs' => array_slice($details, 0, 10),
                    ],
                ]
            );

            $savedSnapshots->push($snapshot);
        }

        return $savedSnapshots;
    }

    /**
     * Reconstruct historical snapshots for all relevant scopes ('all' + distinct Sand Casting scopes).
     *
     * @return Collection<int, SandCastingWipSnapshot>
     */
    public function reconstructAllScopesHistoricalSnapshot(
        Carbon|string $targetDate,
        bool $force = false
    ): Collection {
        $scopes = array_unique(array_merge(['all'], $this->getDashboardService()->getDistinctProductScopesForSnapshot()));
        $allSnapshots = collect();

        foreach ($scopes as $sc) {
            $snaps = $this->reconstructHistoricalSnapshot($targetDate, $sc, $force);
            $allSnapshots = $allSnapshots->merge($snaps);
        }

        return $allSnapshots;
    }

    /**
     * Get 30-day daily WIP snapshot trend and latest summary for Zona 4.
     *
     * @param  Carbon|string|null  $endDate  End date of period (defaults to today)
     * @param  int  $days  Number of consecutive calendar days (default 30)
     * @param  string|null  $scope  Product scope
     * @return array<string, mixed>
     */
    public function getZone4WipSnapshotPayload(
        Carbon|string|null $endDate = null,
        int $days = 30,
        ?string $scope = null
    ): array {
        $end = $endDate ? Carbon::parse($endDate, self::TIMEZONE) : Carbon::now(self::TIMEZONE);
        $start = $end->copy()->subDays($days - 1);
        $startDateStr = $start->toDateString();
        $endDateStr = $end->toDateString();
        $scopeKey = ($scope && $scope !== '') ? $scope : 'all';

        // 30 consecutive calendar days
        $calendarDays = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = $end->copy()->subDays($i);
            $calendarDays[] = [
                'date' => $d->toDateString(),
                'day_label' => $d->format('d M'),
                'year' => $d->format('Y'),
            ];
        }

        // Fetch all stored snapshots for this period and scope
        $snapshots = SandCastingWipSnapshot::query()
            ->whereIn('stage', array_keys(self::WIP_STAGES))
            ->where('product_scope', $scopeKey)
            ->whereDate('snapshot_date', '>=', $startDateStr)
            ->whereDate('snapshot_date', '<=', $endDateStr)
            ->orderBy('snapshot_date', 'asc')
            ->get();

        $stagesData = [];

        foreach (self::WIP_STAGES as $stageKey => $stageLabel) {
            $stageSnaps = $snapshots->where('stage', $stageKey)->keyBy(fn ($s) => $s->snapshot_date->toDateString());

            $daily = [];

            foreach ($calendarDays as $dayInfo) {
                $dStr = $dayInfo['date'];
                $snap = $stageSnaps->get($dStr);

                if ($snap && $snap->status === SandCastingWipSnapshot::STATUS_SUCCESS) {
                    $kg = (float) $snap->weight_kg;
                    $pcs = (int) $snap->qty_pcs;
                    $ktr = (int) $snap->ktr_count;
                    $isReconstructed = ($snap->source_type === SandCastingWipSnapshot::SOURCE_RECONSTRUCTED);
                    $breakdown = $this->resolveSnapshotBreakdown($snap, $stageKey, $scopeKey);

                    $daily[] = [
                        'date' => $dStr,
                        'day_label' => $dayInfo['day_label'],
                        'good_kg' => round($kg, 4),
                        'weight_kg' => round($kg, 4),
                        'good_pcs' => $pcs,
                        'ktr_count' => $ktr,
                        'breakdown' => $breakdown,
                        'is_available' => true,
                        'source_type' => (string) $snap->source_type,
                        'is_reconstructed' => $isReconstructed,
                    ];
                } else {
                    // Not available: Must NOT be rendered as zero!
                    $daily[] = [
                        'date' => $dStr,
                        'day_label' => $dayInfo['day_label'],
                        'good_kg' => null,
                        'weight_kg' => null,
                        'good_pcs' => null,
                        'ktr_count' => null,
                        'breakdown' => [
                            'local' => ['pcs' => null, 'kg' => null],
                            'export' => ['pcs' => null, 'kg' => null],
                            'unclassified' => ['pcs' => null, 'kg' => null],
                        ],
                        'is_available' => false,
                        'source_type' => null,
                        'is_reconstructed' => false,
                    ];
                }
            }

            // Latest valid snapshot: taken from the most recent valid snapshot, NOT the sum of 30 days!
            $latestValidSnap = SandCastingWipSnapshot::where('stage', $stageKey)
                ->where('product_scope', $scopeKey)
                ->where('status', SandCastingWipSnapshot::STATUS_SUCCESS)
                ->whereDate('snapshot_date', '<=', $endDateStr)
                ->orderBy('snapshot_date', 'desc')
                ->first();

            $latestKg = $latestValidSnap ? (float) $latestValidSnap->weight_kg : 0.0;
            $latestPcs = $latestValidSnap ? (int) $latestValidSnap->qty_pcs : 0;
            $latestKtr = $latestValidSnap ? (int) $latestValidSnap->ktr_count : 0;
            $latestDate = $latestValidSnap ? $latestValidSnap->snapshot_date->toDateString() : null;
            $latestIsRecon = $latestValidSnap ? ($latestValidSnap->source_type === SandCastingWipSnapshot::SOURCE_RECONSTRUCTED) : false;
            $latestBreakdown = $latestValidSnap ? $this->resolveSnapshotBreakdown($latestValidSnap, $stageKey, $scopeKey) : [
                'local' => ['pcs' => 0, 'kg' => 0.0],
                'export' => ['pcs' => 0, 'kg' => 0.0],
                'unclassified' => ['pcs' => 0, 'kg' => 0.0],
            ];

            $stagesData[$stageKey] = [
                'stage' => $stageKey,
                'stage_label' => $stageLabel,
                'is_wip_stage' => true,
                'total_weight_kg' => round($latestKg, 4),
                'total_good_pcs' => $latestPcs,
                'total_ktr_count' => $latestKtr,
                'breakdown' => $latestBreakdown,
                'latest_snapshot' => [
                    'snapshot_date' => $latestDate,
                    'weight_kg' => round($latestKg, 4),
                    'wip_ton' => round($latestKg / 1000, 2),
                    'qty_pcs' => $latestPcs,
                    'ktr_count' => $latestKtr,
                    'breakdown' => $latestBreakdown,
                    'is_available' => ($latestValidSnap !== null),
                    'source_type' => $latestValidSnap?->source_type,
                    'is_reconstructed' => $latestIsRecon,
                ],
                'daily' => $daily,
            ];
        }

        return [
            'default_stage' => 'qc',
            'period_start' => $startDateStr,
            'period_end' => $endDateStr,
            'stages' => $stagesData,
        ];
    }

    /**
     * Resolve customer category breakdown for a snapshot row.
     * Guarantees invariant:
     * - local.pcs + export.pcs + unclassified.pcs === snap.qty_pcs
     * - local.kg + export.kg + unclassified.kg === snap.weight_kg
     *
     * @return array<string, array{pcs: int, kg: float}>
     */
    public function resolveSnapshotBreakdown(SandCastingWipSnapshot $snap, string $stageKey, string $scopeKey): array
    {
        $snapPcs = (int) $snap->qty_pcs;
        $snapKg = round((float) $snap->weight_kg, 4);

        if ($snapPcs <= 0 && $snapKg <= 0.0) {
            return [
                'local' => ['pcs' => 0, 'kg' => 0.0],
                'export' => ['pcs' => 0, 'kg' => 0.0],
                'unclassified' => ['pcs' => 0, 'kg' => 0.0],
            ];
        }

        $details = is_array($snap->details) ? $snap->details : (json_decode($snap->details ?? '[]', true) ?: []);

        if (isset($details['breakdown']) && is_array($details['breakdown'])) {
            $b = $details['breakdown'];
            $locPcs = (int) ($b['local']['pcs'] ?? 0);
            $locKg = round((float) ($b['local']['kg'] ?? 0.0), 4);
            $expPcs = (int) ($b['export']['pcs'] ?? 0);
            $expKg = round((float) ($b['export']['kg'] ?? 0.0), 4);
            $uncPcs = (int) ($b['unclassified']['pcs'] ?? 0);
            $uncKg = round((float) ($b['unclassified']['kg'] ?? 0.0), 4);

            if (($locPcs + $expPcs + $uncPcs) === $snapPcs) {
                $diff = round($snapKg - ($locKg + $expKg + $uncKg), 4);
                if ($diff !== 0.0) {
                    if ($locKg > 0) {
                        $locKg = round($locKg + $diff, 4);
                    } elseif ($expKg > 0) {
                        $expKg = round($expKg + $diff, 4);
                    } else {
                        $uncKg = round($uncKg + $diff, 4);
                    }
                }

                return [
                    'local' => ['pcs' => $locPcs, 'kg' => $locKg],
                    'export' => ['pcs' => $expPcs, 'kg' => $expKg],
                    'unclassified' => ['pcs' => $uncPcs, 'kg' => $uncKg],
                ];
            }
        }

        // Fallback: Compute dynamically from historical candidate lines at cutoff
        return $this->computeBreakdownForSnapshotCutoff($snap, $stageKey, $scopeKey);
    }

    /**
     * Reconstruct breakdown at snapshot cutoff when breakdown is missing in stored details.
     *
     * @return array<string, array{pcs: int, kg: float}>
     */
    protected function computeBreakdownForSnapshotCutoff(SandCastingWipSnapshot $snap, string $stageKey, string $scopeKey): array
    {
        $snapPcs = (int) $snap->qty_pcs;
        $snapKg = round((float) $snap->weight_kg, 4);

        $cutoff = $snap->scheduled_cutoff_at
            ? Carbon::parse($snap->scheduled_cutoff_at, self::TIMEZONE)
            : Carbon::parse("{$snap->snapshot_date->toDateString()} ".self::CUTOFF_TIME, self::TIMEZONE);

        $linesQuery = SandCastingCastingResultLine::query()
            ->where('created_at', '<=', $cutoff)
            ->with([
                'castingResult',
                'productionPlan',
                'castingOrderLine',
                'stageExecutions' => fn ($q) => $q->orderBy('executed_at', 'asc')->orderBy('id', 'asc'),
            ]);

        if ($scopeKey !== 'all') {
            $linesQuery->whereHas('productionPlan', fn ($p) => $p->where('product_scope', $scopeKey));
        }

        $candidateLines = $linesQuery->get();
        $stageOrder = SandCastingQuantityResolverService::STAGE_ORDER[$stageKey] ?? 99;
        $categoryMap = \App\Models\Customer::getCategoryMap();

        $reconBreakdown = [
            'local' => ['pcs' => 0, 'kg' => 0.0],
            'export' => ['pcs' => 0, 'kg' => 0.0],
            'unclassified' => ['pcs' => 0, 'kg' => 0.0],
        ];

        foreach ($candidateLines as $line) {
            $execsAtCutoff = $line->stageExecutions->filter(function (SandCastingStageExecution $e) use ($cutoff) {
                $ts = $e->physical_done_at ?? $e->executed_at ?? $e->created_at;

                return $ts && $ts <= $cutoff;
            });

            $hasFinishedThisOrLater = $execsAtCutoff->contains(function (SandCastingStageExecution $e) use ($stageOrder) {
                $norm = SandCastingQuantityResolverService::normalizeStageName($e->stage);
                $ord = SandCastingQuantityResolverService::STAGE_ORDER[$norm] ?? 0;

                return $ord >= $stageOrder && $e->physical_done_at !== null;
            });

            if ($hasFinishedThisOrLater) {
                continue;
            }

            $hasReachedStage = false;
            if ($stageKey === 'netto') {
                $castDate = $line->castingResult?->cast_date;
                $hasReachedStage = ($line->created_at <= $cutoff) || ($castDate && $castDate <= $cutoff);
            } else {
                $prevOrder = $stageOrder - 1;
                $hasReachedStage = $execsAtCutoff->contains(function (SandCastingStageExecution $e) use ($prevOrder) {
                    $norm = SandCastingQuantityResolverService::normalizeStageName($e->stage);
                    $ord = SandCastingQuantityResolverService::STAGE_ORDER[$norm] ?? 0;

                    return $ord === $prevOrder && $e->physical_done_at !== null;
                });
            }

            if (! $hasReachedStage) {
                continue;
            }

            $rootGood = (int) ($line->qty_good ?? 0);
            if ($rootGood <= 0) {
                continue;
            }

            $defectsBefore = $execsAtCutoff
                ->filter(function (SandCastingStageExecution $e) use ($stageOrder) {
                    $norm = SandCastingQuantityResolverService::normalizeStageName($e->stage);
                    $ord = SandCastingQuantityResolverService::STAGE_ORDER[$norm] ?? 0;

                    return $ord < $stageOrder;
                })
                ->sum('defect_qty');

            $historicalUsable = max(0, $rootGood - (int) $defectsBefore);
            if ($historicalUsable <= 0) {
                continue;
            }

            $w = $this->resolveUnitWeightKg($line);
            $lineKg = ($historicalUsable * $w);

            $custName = $line->productionPlan?->customer ?: ($line->castingOrderLine?->customer ?? '');
            $category = \App\Models\Customer::resolveCategory($custName, $categoryMap);
            $catKey = strtolower($category);
            if (! isset($reconBreakdown[$catKey])) {
                $catKey = 'unclassified';
            }

            $reconBreakdown[$catKey]['pcs'] += $historicalUsable;
            $reconBreakdown[$catKey]['kg'] += $lineKg;
        }

        $calcPcs = (int) ($reconBreakdown['local']['pcs'] + $reconBreakdown['export']['pcs'] + $reconBreakdown['unclassified']['pcs']);

        if ($calcPcs === $snapPcs) {
            $roundLocalKg = round((float) $reconBreakdown['local']['kg'], 4);
            $roundExportKg = round((float) $reconBreakdown['export']['kg'], 4);
            $roundUnclassKg = round((float) $reconBreakdown['unclassified']['kg'], 4);

            $diff = round($snapKg - ($roundLocalKg + $roundExportKg + $roundUnclassKg), 4);
            if ($diff !== 0.0) {
                if ($roundLocalKg > 0) {
                    $roundLocalKg = round($roundLocalKg + $diff, 4);
                } elseif ($roundExportKg > 0) {
                    $roundExportKg = round($roundExportKg + $diff, 4);
                } elseif ($roundUnclassKg > 0) {
                    $roundUnclassKg = round($roundUnclassKg + $diff, 4);
                }
            }

            return [
                'local' => ['pcs' => (int) $reconBreakdown['local']['pcs'], 'kg' => $roundLocalKg],
                'export' => ['pcs' => (int) $reconBreakdown['export']['pcs'], 'kg' => $roundExportKg],
                'unclassified' => ['pcs' => (int) $reconBreakdown['unclassified']['pcs'], 'kg' => $roundUnclassKg],
            ];
        }

        // Safe fallback: assign to unclassified to preserve mathematical invariants
        return [
            'local' => ['pcs' => 0, 'kg' => 0.0],
            'export' => ['pcs' => 0, 'kg' => 0.0],
            'unclassified' => ['pcs' => $snapPcs, 'kg' => $snapKg],
        ];
    }
}
