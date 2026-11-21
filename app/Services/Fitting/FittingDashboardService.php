<?php

namespace App\Services\Fitting;

use App\Models\FittingItem;
use App\Models\FittingStageExecution;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class FittingDashboardService
{
    public const TIMEZONE = 'Asia/Jakarta';

    public const VALID_ZONES = ['all', '1', '2', '3', '4'];

    public const CACHE_TTL_SECONDS = 30;

    /**
     * Official 6 Fitting stages sequence.
     */
    public const STAGES = [
        'netto_pf' => 'Netto PF',
        'kimia' => 'Kimia',
        'marking' => 'Marking',
        'bubut_cnc_pf' => 'Bubut CNC PF',
        'qc_pf' => 'QC PF',
        'gudang_jadi_pf' => 'Gudang Jadi PF',
    ];

    public const STAGE_LABELS = [
        'netto_pf' => 'NETTO PF',
        'kimia' => 'KIMIA',
        'marking' => 'MARKING',
        'bubut_cnc_pf' => 'BUBUT CNC PF',
        'qc_pf' => 'QC PF',
        'gudang_jadi_pf' => 'GUDANG JADI PF',
    ];

    public const ACTIVE_WIP_STAGES = [
        'netto_pf',
        'kimia',
        'marking',
        'bubut_cnc_pf',
        'qc_pf',
    ];

    public function __construct(
        protected ?FittingProductionReportService $reportService = null,
        protected ?FittingQuantityResolverService $quantityResolver = null,
        protected ?FittingQcDefectReportService $qcReportService = null
    ) {
        $this->quantityResolver = $quantityResolver ?? new FittingQuantityResolverService;
        $this->reportService = $reportService ?? new FittingProductionReportService($this->quantityResolver);
        $this->qcReportService = $qcReportService ?? new FittingQcDefectReportService;
    }

    /**
     * Get aggregated Fitting dashboard payload for specified zone or all zones.
     *
     * @param  string  $zone  'all', '1', '2', '3', '4'
     * @param  User|null  $user  Authenticated user context
     * @param  bool  $bypassCache  If true, forces fresh calculation
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function getData(string $zone = 'all', ?User $user = null, bool $bypassCache = false): array
    {
        $zoneNormalized = strtolower(trim($zone));
        if (! in_array($zoneNormalized, self::VALID_ZONES, true)) {
            $zoneNormalized = 'all';
        }

        $currentUser = $user ?? auth()->user();
        $cacheKey = "fitting_dashboard_zone_{$zoneNormalized}";

        if ($bypassCache) {
            return $this->computeDashboardPayload($zoneNormalized, $currentUser);
        }

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($zoneNormalized, $currentUser) {
            return $this->computeDashboardPayload($zoneNormalized, $currentUser);
        });
    }

    /**
     * Compute raw dashboard payload without cache wrapper.
     *
     * @return array<string, mixed>
     */
    protected function computeDashboardPayload(string $zone, ?User $user): array
    {
        $now = Carbon::now(self::TIMEZONE);
        $todayStr = $now->toDateString();

        $payload = [
            'meta' => [
                'timestamp' => $now->toIso8601String(),
                'formatted_time' => $now->format('d/m/Y H:i:s'),
                'timezone' => self::TIMEZONE,
                'date' => $todayStr,
                'formatted_date' => $now->format('d/m/Y'),
                'requested_zone' => $zone,
                'cache_ttl_seconds' => self::CACHE_TTL_SECONDS,
            ],
        ];

        if ($zone === 'all' || $zone === '1') {
            $payload['zone_1'] = $this->buildZone1ExecutiveSummary($todayStr, $user);
        }

        if ($zone === 'all' || $zone === '2') {
            $payload['zone_2'] = $this->buildZone2WipAndFlow($todayStr, $user);
        }

        if ($zone === 'all' || $zone === '3') {
            $payload['zone_3'] = $this->buildZone3QualityAndAction($todayStr, $user);
        }

        if ($zone === 'all' || $zone === '4') {
            $payload['zone_4'] = $this->buildZone4DailyThroughputTonnage($todayStr, $user);
        }

        return $payload;
    }

    // =========================================================================
    // ZONE 1: EXECUTIVE PRODUCTION SUMMARY
    // =========================================================================

    /**
     * Build Zone 1 payload: Yesterday verified defect, 6-stage operational throughput, and terminal warehouse receipt.
     *
     * @return array<string, mixed>
     */
    protected function buildZone1ExecutiveSummary(string $todayStr, ?User $user): array
    {
        // 1. Yesterday Verified Defect (QC Confirmed)
        $yesterday = Carbon::parse($todayStr, self::TIMEZONE)->subDay()->toDateString();
        $yesterdayDataset = $this->qcReportService->getReportDataset([
            'date_from' => $yesterday,
            'date_to' => $yesterday,
        ], $user, paginate: false);

        $yesterdayDefect = [
            'pcs' => (int) ($yesterdayDataset['summary']['total_defect_pcs'] ?? 0),
            'weight_kg' => (float) ($yesterdayDataset['summary']['total_estimated_kg'] ?? 0.0),
        ];

        // 2. Daily Throughput by Stage (6 Stages: Netto PF to Gudang Jadi PF)
        $reportFilters = [
            'date_from' => $todayStr,
            'date_to' => $todayStr,
            'stage' => 'all',
        ];

        $reportDataset = $this->reportService->getProductionDataset($reportFilters, $user, includeDetails: false);

        /** @var Collection $stageSummaries */
        $stageSummaries = $reportDataset['stage_summaries'] ?? collect();

        $throughputStages = [];
        $totalActivities = 0;
        $totalInput = 0;
        $totalDefect = 0;
        $totalGood = 0;
        $totalWeight = 0.0;

        foreach ($stageSummaries as $stg) {
            $stgKey = (string) $stg['stage'];
            $weightKg = (float) ($stg['weight_kg'] ?? 0.0);
            $weightTon = (float) ($stg['weight_ton'] ?? round($weightKg / 1000, 2));
            $inputPcs = (int) ($stg['input_pcs'] ?? 0);
            $defectPcs = (int) ($stg['defect_pcs'] ?? 0);
            $goodPcs = (int) ($stg['good_pcs'] ?? 0);
            $ktrCount = (int) ($stg['ktr_count'] ?? 0);
            $defectRate = (float) ($stg['defect_rate'] ?? 0.0);

            $totalActivities += $ktrCount;
            $totalInput += $inputPcs;
            $totalDefect += $defectPcs;
            $totalGood += $goodPcs;
            $totalWeight += $weightKg;

            $lineBrk = $stg['line_breakdown'] ?? [];
            $throughputStages[] = [
                'stage' => $stgKey,
                'label' => (string) ($stg['stage_label'] ?? strtoupper($stgKey)),
                'order' => (int) ($stg['stage_order'] ?? 1),
                'checkpoint_code' => (string) ($stg['checkpoint_code'] ?? ''),
                'ktr_count' => $ktrCount,
                'input_pcs' => $inputPcs,
                'good_pcs' => $goodPcs,
                'good_kg' => round($weightKg, 2),
                'weight_kg' => round($weightKg, 2),
                'good_ton' => $weightTon,
                'good_tonnage' => $weightTon,
                'defect_pcs' => $defectPcs,
                'defect_rate' => $defectRate,
                'defect_rate_pct' => $defectRate,
                'line_breakdown' => $lineBrk,
                'lines' => [
                    1 => ['kg' => (float) ($lineBrk[1]['kg'] ?? 0.0), 'pcs' => (int) ($lineBrk[1]['pcs'] ?? 0)],
                    2 => ['kg' => (float) ($lineBrk[2]['kg'] ?? 0.0), 'pcs' => (int) ($lineBrk[2]['pcs'] ?? 0)],
                    3 => ['kg' => (float) ($lineBrk[3]['kg'] ?? 0.0), 'pcs' => (int) ($lineBrk[3]['pcs'] ?? 0)],
                    4 => ['kg' => (float) ($lineBrk[4]['kg'] ?? 0.0), 'pcs' => (int) ($lineBrk[4]['pcs'] ?? 0)],
                ],
            ];
        }

        // 3. Warehouse Receipt Today (Gudang Jadi PF terminal output)
        $gdSummary = $stageSummaries->firstWhere('stage', 'gudang_jadi_pf');
        $gdWeightKg = (float) ($gdSummary['weight_kg'] ?? 0.0);
        $warehouseReceiptToday = [
            'checkpoint_code' => 'PF_GUDANG_JADI',
            'is_terminal' => true,
            'ktr_count' => (int) ($gdSummary['ktr_count'] ?? 0),
            'good_pcs' => (int) ($gdSummary['good_pcs'] ?? 0),
            'weight_kg' => round($gdWeightKg, 2),
            'good_ton' => round($gdWeightKg / 1000, 2),
            'good_tonnage' => round($gdWeightKg / 1000, 2),
        ];

        $overallDefectRate = $totalInput > 0 ? round(($totalDefect / $totalInput) * 100, 2) : 0.0;
        $activityTotals = [
            'total_stage_activities' => $totalActivities,
            'total_input_pcs' => $totalInput,
            'total_defect_pcs' => $totalDefect,
            'total_good_pcs' => $totalGood,
            'total_weight_kg' => round($totalWeight, 2),
            'total_weight_ton' => round($totalWeight / 1000, 2),
            'overall_defect_rate' => $overallDefectRate,
            'disclaimer' => 'Angka total merupakan akumulasi aktivitas pemrosesan pada 6 checkpoint hari ini, bukan jumlah unit fisik unik antar tahap.',
        ];

        return [
            'date' => $todayStr,
            'formatted_date' => Carbon::parse($todayStr)->format('d/m/Y'),
            'yesterday_verified_defect' => $yesterdayDefect,
            'stages' => $throughputStages,
            'daily_throughput_stages' => $throughputStages,
            'warehouse_receipt_today' => $warehouseReceiptToday,
            'activity_totals' => $activityTotals,
        ];
    }

    // =========================================================================
    // ZONE 2: WIP & PRODUCTION FLOW
    // =========================================================================

    /**
     * Build Zone 2 payload: 6-stage pipeline flow (5 active WIP + 1 terminal), WIP donut distribution, and FIFO aging watchlist.
     *
     * @return array<string, mixed>
     */
    protected function buildZone2WipAndFlow(string $todayStr, ?User $user): array
    {
        $now = Carbon::now(self::TIMEZONE);

        // Fetch all ACTIVE items that are currently in the pipeline
        $activeItems = FittingItem::query()
            ->with(['productionPlan', 'transfer', 'stageExecutions'])
            ->where('status', FittingItem::STATUS_ACTIVE)
            ->whereIn('current_stage', array_keys(self::STAGES))
            ->get();

        $stageLabels = [
            'netto_pf' => ['num' => '1', 'label' => 'NETTO PF', 'color' => '#10b981'],
            'kimia' => ['num' => '2', 'label' => 'KIMIA', 'color' => '#06b6d4'],
            'marking' => ['num' => '3', 'label' => 'MARKING', 'color' => '#8b5cf6'],
            'bubut_cnc_pf' => ['num' => '4', 'label' => 'BUBUT CNC PF', 'color' => '#2563eb'],
            'qc_pf' => ['num' => '5', 'label' => 'QC PF', 'color' => '#4f46e5'],
        ];

        $wipDistribution = [];
        $totalActiveWipPcs = 0;
        $totalActiveWipKg = 0.0;
        $watchlistCandidates = [];

        foreach (self::ACTIVE_WIP_STAGES as $stageKey) {
            $itemsInStage = $activeItems->where('current_stage', $stageKey);
            $stagePcs = 0;
            $stageKg = 0.0;
            $stageKtr = $itemsInStage->count();

            $lineTotals = [
                1 => ['pcs' => 0, 'kg' => 0.0],
                2 => ['pcs' => 0, 'kg' => 0.0],
                3 => ['pcs' => 0, 'kg' => 0.0],
                4 => ['pcs' => 0, 'kg' => 0.0],
            ];

            foreach ($itemsInStage as $item) {
                // Effective usable WIP at this stage
                $effectiveQty = $this->quantityResolver->resolveEffectiveInputQty($item, $stageKey);
                $unitWeight = $this->resolveEffectiveUnitWeightKg($item) ?? 0.0;
                $weight = $effectiveQty * $unitWeight;

                $stagePcs += $effectiveQty;
                $stageKg += $weight;

                $lineNum = FittingItem::normalizeLineNumber($item->line_number) ?? 1;
                $lineTotals[$lineNum]['pcs'] += $effectiveQty;
                $lineTotals[$lineNum]['kg'] += $weight;

                // FIFO Aging calculation based on actual historical timestamps
                $transferDate = $item->transfer?->transfer_date
                    ? Carbon::parse($item->transfer->transfer_date)
                    : ($item->created_at ? Carbon::parse($item->created_at) : $now);
                $totalAgingDays = max(0, $now->diffInDays($transferDate));

                // Time entered current stage: predecessor stage physical_done_at or transferDate
                $prevStage = FittingItem::previousStageFor($stageKey);
                $stageEntryTime = $transferDate;

                if ($prevStage) {
                    $prevExec = $item->stageExecutions->firstWhere('stage', $prevStage);
                    if ($prevExec && $prevExec->physical_done_at) {
                        $stageEntryTime = Carbon::parse($prevExec->physical_done_at);
                    }
                }

                $stageAgingHours = max(0, $now->diffInHours($stageEntryTime));
                $stageAgingDays = max(0, $now->diffInDays($stageEntryTime));
                $stageAgingLabel = $stageAgingHours >= 48
                    ? round($stageAgingHours / 24, 1).' hari'
                    : $stageAgingHours.' jam';

                $watchlistCandidates[] = [
                    'item_id' => $item->id,
                    'traveler_number' => (string) $item->traveler_number,
                    'heat_number' => (string) ($item->heat_number ?? '—'),
                    'production_code' => (string) ($item->production_code ?: ($item->productionPlan?->code ?? '—')),
                    'item_name' => (string) ($item->item_name ?: ($item->productionPlan?->item_name ?? '—')),
                    'customer' => (string) ($item->customer ?: ($item->productionPlan?->customer ?? '—')),
                    'line_number' => $lineNum,
                    'stage' => $stageKey,
                    'stage_label' => self::STAGE_LABELS[$stageKey] ?? strtoupper($stageKey),
                    'qty_pcs' => $effectiveQty,
                    'weight_kg' => round($weight, 2),
                    'stage_aging_hours' => $stageAgingHours,
                    'stage_aging_days' => $stageAgingDays,
                    'stage_aging_label' => $stageAgingLabel,
                    'total_aging_days' => $totalAgingDays,
                ];
            }

            $totalActiveWipPcs += $stagePcs;
            $totalActiveWipKg += $stageKg;
            $stageTon = round($stageKg / 1000, 2);

            $wipDistribution[$stageKey] = [
                'stage' => $stageKey,
                'num' => $stageLabels[$stageKey]['num'],
                'label' => $stageLabels[$stageKey]['label'],
                'color' => $stageLabels[$stageKey]['color'],
                'wip_pcs' => $stagePcs,
                'weight_kg' => round($stageKg, 2),
                'wip_ton' => $stageTon,
                'ktr_count' => $stageKtr,
                'percentage' => 0.0,
                'line_breakdown' => $lineTotals,
            ];
        }

        $totalActiveWipTon = round($totalActiveWipKg / 1000, 2);

        // Calculate distribution percentage and flag highest WIP stage
        $maxWipTon = collect($wipDistribution)->max('wip_ton') ?? 0;
        foreach ($wipDistribution as $stgKey => &$stg) {
            $stg['percentage'] = $totalActiveWipTon > 0 ? round(($stg['wip_ton'] / $totalActiveWipTon) * 100, 1) : 0.0;
            $stg['is_highest'] = ($maxWipTon > 0 && (float) $stg['wip_ton'] === (float) $maxWipTon);
        }
        unset($stg);

        // Terminal stage info
        $itemsInGudang = $activeItems->where('current_stage', 'gudang_jadi_pf');
        $terminalStage = [
            'stage' => 'gudang_jadi_pf',
            'num' => '6',
            'label' => 'GUDANG JADI PF',
            'is_wip' => false,
            'ktr_count' => $itemsInGudang->count(),
            'note' => 'Tahap terminal dikeluarkan dari perhitungan WIP aktif.',
        ];

        // Sort FIFO Watchlist by oldest stage aging desc
        usort($watchlistCandidates, function ($a, $b) {
            if ($a['stage_aging_hours'] !== $b['stage_aging_hours']) {
                return $b['stage_aging_hours'] <=> $a['stage_aging_hours'];
            }

            return $b['total_aging_days'] <=> $a['total_aging_days'];
        });

        $top5Watchlist = array_slice($watchlistCandidates, 0, 5);

        return [
            'total_active_wip_pcs' => $totalActiveWipPcs,
            'total_active_wip_ton' => $totalActiveWipTon,
            'wip_distribution' => array_values($wipDistribution),
            'terminal_stage' => $terminalStage,
            'aging_watchlist' => $top5Watchlist,
        ];
    }

    // =========================================================================
    // ZONE 3: QUALITY & DEFECT ANALYSIS
    // =========================================================================

    /**
     * Build Zone 3 payload: Action backlog cards, 30-day defect trend in KG/PCS, overall defect donut, and stage defect contribution.
     *
     * @return array<string, mixed>
     */
    protected function buildZone3QualityAndAction(string $todayStr, ?User $user): array
    {
        $today = Carbon::parse($todayStr, self::TIMEZONE);
        $startDate = $today->copy()->subDays(29);
        $startDateStr = $startDate->toDateString();

        // 1. Action Cards (Backlog state queries on fitting_stage_executions)
        $baseExecQuery = FittingStageExecution::query()
            ->whereHas('fittingItem', fn ($q) => $q->where('status', FittingItem::STATUS_ACTIVE));

        $waitingDefectCount = (clone $baseExecQuery)->where('status', FittingStageExecution::STATUS_WAITING_DEFECT)->count();
        $waitingDefectPcs = (int) (clone $baseExecQuery)->where('status', FittingStageExecution::STATUS_WAITING_DEFECT)->sum('input_qty');

        $waitingQcCount = (clone $baseExecQuery)->where('status', FittingStageExecution::STATUS_WAITING_QC)->count();
        $waitingQcPcs = (int) (clone $baseExecQuery)->where('status', FittingStageExecution::STATUS_WAITING_QC)->sum('defect_qty');

        $reopenedCount = (clone $baseExecQuery)->where('status', FittingStageExecution::STATUS_REOPENED_FOR_CORRECTION)->count();
        $reopenedPcs = (int) (clone $baseExecQuery)->where('status', FittingStageExecution::STATUS_REOPENED_FOR_CORRECTION)->sum('defect_qty');

        $actionCards = [
            'waiting_defect' => [
                'status_code' => 'WAITING_DEFECT',
                'title' => 'Belum Dicatat Defect',
                'ktr_count' => $waitingDefectCount,
                'pcs' => $waitingDefectPcs,
                'action_route' => 'fitting.defects.index',
            ],
            'waiting_qc' => [
                'status_code' => 'WAITING_QC',
                'title' => 'Menunggu Verifikasi QC',
                'ktr_count' => $waitingQcCount,
                'pcs' => $waitingQcPcs,
                'action_route' => 'fitting.qc-defects.index',
            ],
            'reopened' => [
                'status_code' => 'REOPENED_FOR_CORRECTION',
                'title' => 'Dikembalikan untuk Koreksi',
                'ktr_count' => $reopenedCount,
                'pcs' => $reopenedPcs,
                'action_route' => 'fitting.qc-defects.index',
            ],
        ];

        // 2. 30-Day Defect Dataset (CONFIRMED status only, date hierarchy inspection_date -> physical_done_at)
        $defectDataset = $this->qcReportService->getReportDataset([
            'date_from' => $startDateStr,
            'date_to' => $todayStr,
            'stage' => 'all',
        ], $user, paginate: false);

        $allDefectRecords = $defectDataset['all_records'] ?? collect();
        $summary = $defectDataset['summary'] ?? [];

        // 3. 30 Consecutive Calendar Days Trend
        $calendarDays = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = $today->copy()->subDays($i);
            $dStr = $d->toDateString();
            $dayRecords = $allDefectRecords->where('date_raw', $dStr);
            $dayPcs = (int) $dayRecords->sum('qty');
            $dayKg = round((float) $dayRecords->where('has_weight', true)->sum('estimated_kg'), 2);
            $dayUnavail = (int) $dayRecords->where('has_weight', false)->sum('qty');

            $calendarDays[] = [
                'date' => $dStr,
                'day_label' => $d->format('d M'),
                'defect_pcs' => $dayPcs,
                'estimated_kg' => $dayKg,
                'estimated_defect_kg' => $dayKg,
                'is_weight_available' => ($dayUnavail === 0),
                'unavailable_weight_pcs' => $dayUnavail,
            ];
        }

        // 4. Overall Defect Types (Donut)
        $overallDefectTypes = collect($defectDataset['pareto_summary'] ?? [])
            ->map(function ($p) {
                return [
                    'defect_type_id' => $p['defect_type_id'],
                    'name' => $p['defect_name'],
                    'qty_pcs' => (int) $p['qty_pcs'],
                    'estimated_defect_kg' => (float) $p['estimated_kg'],
                    'weight_percentage' => (float) $p['contribution_pct'],
                ];
            })->all();

        // 5. Stage Defect Contribution (6 Stages)
        $stageContrib = [];
        $totalDefectKg = (float) ($summary['total_estimated_kg'] ?? 0.0);

        foreach (self::STAGES as $stgKey => $stgLabel) {
            $stgRecords = $allDefectRecords->where('stage', $stgKey);
            $pcs = (int) $stgRecords->sum('qty');
            $kg = round((float) $stgRecords->where('has_weight', true)->sum('estimated_kg'), 2);
            $pct = $totalDefectKg > 0 ? round(($kg / $totalDefectKg) * 100, 1) : 0.0;
            $unavail = $stgRecords->where('has_weight', false)->sum('qty');

            $stageContrib[] = [
                'stage' => $stgKey,
                'label' => $stgLabel,
                'defect_pcs' => $pcs,
                'estimated_defect_kg' => $kg,
                'weight_contribution_pct' => $pct,
                'is_weight_complete' => ($unavail === 0),
            ];
        }

        // 6. Six Stages Mini Donut Grid
        $stageDefectTypes = [];
        foreach (self::STAGES as $stgKey => $stgLabel) {
            $stgRecords = $allDefectRecords->where('stage', $stgKey);
            $stgPcs = (int) $stgRecords->sum('qty');
            $stgKg = round((float) $stgRecords->where('has_weight', true)->sum('estimated_kg'), 2);

            $types = $stgRecords->groupBy('defect_type_id')->map(function ($recs) use ($stgKg) {
                $first = $recs->first();
                $pcs = (int) $recs->sum('qty');
                $kg = round((float) $recs->where('has_weight', true)->sum('estimated_kg'), 2);
                $pct = $stgKg > 0 ? round(($kg / $stgKg) * 100, 1) : 0.0;

                return [
                    'defect_type_id' => $first['defect_type_id'],
                    'name' => $first['defect_name'],
                    'qty_pcs' => $pcs,
                    'estimated_defect_kg' => $kg,
                    'weight_percentage' => $pct,
                ];
            })->sortByDesc('qty_pcs')->values()->all();

            $stageDefectTypes[$stgKey] = [
                'stage' => $stgKey,
                'label' => $stgLabel,
                'total_pcs' => $stgPcs,
                'total_estimated_defect_kg' => $stgKg,
                'is_weight_available' => ($stgKg > 0 || $stgPcs === 0),
                'types' => $types,
            ];
        }

        return [
            'action_cards' => $actionCards,
            'quality_analytics_summary' => [
                'total_verified_pcs' => (int) ($summary['total_defect_pcs'] ?? 0),
                'total_estimated_defect_kg' => round($totalDefectKg, 2),
                'weight_coverage_pct' => (float) ($summary['weight_coverage_pct'] ?? 100.0),
                'is_weight_fully_available' => (bool) ($summary['is_weight_fully_available'] ?? true),
                'unavailable_weight_pcs' => (int) ($summary['unavailable_weight_pcs'] ?? 0),
                'period_start' => $startDate->format('d/m/Y'),
                'period_end' => $today->format('d/m/Y'),
            ],
            'defect_trend_30_days' => $calendarDays,
            'overall_defect_types' => $overallDefectTypes,
            'stage_defect_contribution' => $stageContrib,
            'stage_defect_types' => $stageDefectTypes,
        ];
    }

    // =========================================================================
    // ZONE 4: DAILY PRODUCTION TONNAGE TREND — 30 DAYS
    // =========================================================================

    /**
     * Build Zone 4 payload: 30-day daily throughput bar chart and line distribution chart for 6 Fitting stages.
     *
     * @return array<string, mixed>
     */
    public function buildZone4DailyThroughputTonnage(string $todayStr, ?User $user): array
    {
        $today = Carbon::parse($todayStr, self::TIMEZONE);
        $startDate = $today->copy()->subDays(29);
        $startDateStr = $startDate->toDateString();
        $endDateStr = $todayStr;

        // Fetch completed stage executions in 30 days
        $execQuery = FittingStageExecution::query()
            ->with(['fittingItem.productionPlan', 'fittingItem.allocations'])
            ->whereIn('stage', array_keys(self::STAGES))
            ->whereNotNull('physical_done_at')
            ->whereBetween('physical_done_at', [$startDateStr.' 00:00:00', $endDateStr.' 23:59:59'])
            ->whereHas('fittingItem', fn ($q) => $q->where('status', FittingItem::STATUS_ACTIVE));

        $executions = $execQuery->orderBy('physical_done_at', 'asc')->get();

        // 30 consecutive calendar days
        $calendarDays = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = $today->copy()->subDays($i);
            $calendarDays[] = [
                'date' => $d->toDateString(),
                'day_label' => $d->format('d M'),
            ];
        }

        $stagesData = [];

        foreach (self::STAGES as $stageKey => $stageLabel) {
            $stgExecs = $executions->where('stage', $stageKey);

            $daily = [];
            $totalStageKg = 0.0;
            $totalStagePcs = 0;
            $totalStageKtr = 0;

            $seriesByLine = [
                1 => ['line_number' => 1, 'label' => 'Line 1 (Fitting)', 'data' => [], 'pcs_data' => []],
                2 => ['line_number' => 2, 'label' => 'Line 2 (Union)', 'data' => [], 'pcs_data' => []],
                3 => ['line_number' => 3, 'label' => 'Line 3 (Ball Valve)', 'data' => [], 'pcs_data' => []],
                4 => ['line_number' => 4, 'label' => 'Line 4 (BW)', 'data' => [], 'pcs_data' => []],
            ];

            foreach ($calendarDays as $dayInfo) {
                $dStr = $dayInfo['date'];
                // Group by fitting_item_id to prevent double count per PFKTR
                $dayExecs = $stgExecs->filter(fn ($e) => $e->physical_done_at && $e->physical_done_at->toDateString() === $dStr)
                    ->groupBy('fitting_item_id');

                $dayKg = 0.0;
                $dayPcs = 0;
                $dayKtr = $dayExecs->count();

                $dayLineKg = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0];
                $dayLinePcs = [1 => 0, 2 => 0, 3 => 0, 4 => 0];

                foreach ($dayExecs as $itemExecs) {
                    $exec = $itemExecs->last();
                    $item = $exec->fittingItem;
                    if (! $item) {
                        continue;
                    }

                    $goodQty = $this->quantityResolver->resolveEffectiveGoodQty($item, $stageKey);
                    $unitWeight = $this->resolveEffectiveUnitWeightKg($item) ?? 0.0;
                    $weight = round($goodQty * $unitWeight, 2);

                    $dayPcs += $goodQty;
                    $dayKg += $weight;

                    $normLine = FittingItem::normalizeLineNumber($item->line_number) ?? 1;
                    $dayLineKg[$normLine] += $weight;
                    $dayLinePcs[$normLine] += $goodQty;
                }

                $totalStageKg += $dayKg;
                $totalStagePcs += $dayPcs;
                $totalStageKtr += $dayKtr;

                $daily[] = [
                    'date' => $dStr,
                    'day_label' => $dayInfo['day_label'],
                    'good_kg' => round($dayKg, 2),
                    'good_pcs' => $dayPcs,
                    'ktr_count' => $dayKtr,
                    'lines' => [
                        1 => ['good_kg' => round($dayLineKg[1], 2), 'good_pcs' => $dayLinePcs[1]],
                        2 => ['good_kg' => round($dayLineKg[2], 2), 'good_pcs' => $dayLinePcs[2]],
                        3 => ['good_kg' => round($dayLineKg[3], 2), 'good_pcs' => $dayLinePcs[3]],
                        4 => ['good_kg' => round($dayLineKg[4], 2), 'good_pcs' => $dayLinePcs[4]],
                    ],
                ];

                foreach ([1, 2, 3, 4] as $lNum) {
                    $seriesByLine[$lNum]['data'][] = round($dayLineKg[$lNum], 2);
                    $seriesByLine[$lNum]['pcs_data'][] = $dayLinePcs[$lNum];
                }
            }

            $stagesData[$stageKey] = [
                'stage' => $stageKey,
                'stage_label' => $stageLabel,
                'total_weight_kg' => round($totalStageKg, 2),
                'total_good_pcs' => $totalStagePcs,
                'total_ktr_count' => $totalStageKtr,
                'daily' => $daily,
                'series_by_line' => $seriesByLine,
            ];
        }

        return [
            'period_start' => $startDate->format('d/m/Y'),
            'period_end' => $today->format('d/m/Y'),
            'default_stage' => 'qc_pf',
            'stages' => $stagesData,
        ];
    }

    /**
     * Resolve effective unit weight in KG for a Fitting Item.
     */
    public function resolveEffectiveUnitWeightKg(?FittingItem $item): ?float
    {
        if (! $item) {
            return null;
        }

        if ($item->unit_weight_kg !== null && (float) $item->unit_weight_kg > 0) {
            return (float) $item->unit_weight_kg;
        }

        if ($item->productionPlan && $item->productionPlan->weight !== null && (float) $item->productionPlan->weight > 0) {
            return (float) $item->productionPlan->weight;
        }

        return null;
    }
}
