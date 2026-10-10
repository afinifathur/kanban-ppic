<?php

namespace App\Services\SandCasting;

use App\Models\DefectType;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\SandCastingStageExecutionDefect;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class SandCastingDashboardService
{
    public const TIMEZONE = 'Asia/Jakarta';

    public const VALID_ZONES = ['all', '1', '2', '3'];

    public const CACHE_TTL_SECONDS = 45;

    public function __construct(
        protected ?SandCastingProductionReportService $reportService = null,
        protected ?SandCastingProductionStatusService $statusService = null,
        protected ?SandCastingProductionFloorQueryService $floorQueryService = null,
        protected ?SandCastingQuantityResolverService $quantityResolver = null
    ) {
        $this->quantityResolver = $quantityResolver ?? new SandCastingQuantityResolverService;
        $this->reportService = $reportService ?? new SandCastingProductionReportService($this->quantityResolver);
        $this->statusService = $statusService ?? new SandCastingProductionStatusService($this->quantityResolver);
        $this->floorQueryService = $floorQueryService ?? new SandCastingProductionFloorQueryService(quantityResolver: $this->quantityResolver);
    }

    /**
     * Get aggregated Sand Casting dashboard payload for specified zone or all zones.
     * Uses RBAC/scope-aware caching by default.
     *
     * @param  string  $zone  'all', '1', '2', or '3'
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
            throw new InvalidArgumentException("Zone '{$zone}' tidak valid. Pilihan: all, 1, 2, 3.");
        }

        $currentUser = $user ?? auth()->user();
        $scope = ($currentUser && $currentUser->hasRole('ppic')) ? $currentUser->product_scope : null;
        $scopeKey = $scope ?: 'all';

        $cacheKey = "sc_dashboard_data_{$scopeKey}_zone_{$zoneNormalized}";

        if ($bypassCache) {
            return $this->computeDashboardPayload($zoneNormalized, $currentUser, $scope);
        }

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($zoneNormalized, $currentUser, $scope) {
            return $this->computeDashboardPayload($zoneNormalized, $currentUser, $scope);
        });
    }

    /**
     * Compute raw dashboard payload without cache wrapper.
     *
     * @return array<string, mixed>
     */
    protected function computeDashboardPayload(string $zone, ?User $user, ?string $scope): array
    {
        $now = Carbon::now(self::TIMEZONE);
        $todayStr = $now->toDateString();

        $payload = [
            'meta' => [
                'timestamp' => $now->toIso8601String(),
                'formatted_time' => $now->format('d/m/Y H:i:s'),
                'timezone' => self::TIMEZONE,
                'requested_zone' => $zone,
                'user_scope' => $scope ?: 'all',
                'cache_ttl_seconds' => self::CACHE_TTL_SECONDS,
            ],
        ];

        if ($zone === 'all' || $zone === '1') {
            $payload['zone_1'] = $this->buildZone1ExecutiveSummary($todayStr, $user, $scope);
        }

        if ($zone === 'all' || $zone === '2') {
            $payload['zone_2'] = $this->buildZone2WipAndFlow($todayStr, $user, $scope);
        }

        if ($zone === 'all' || $zone === '3') {
            $payload['zone_3'] = $this->buildZone3QualityAndAction($todayStr, $user, $scope);
        }

        return $payload;
    }

    // =========================================================================
    // ZONE 1: EXECUTIVE PRODUCTION SUMMARY
    // =========================================================================

    /**
     * Build Zone 1 payload: Casting output, 7-stage throughput, warehouse receipts, and order statuses.
     *
     * @return array<string, mixed>
     */
    protected function buildZone1ExecutiveSummary(string $todayStr, ?User $user, ?string $scope): array
    {
        // 1. Casting Output Today (Reconciles with Report Service stage: cor)
        $corQuery = SandCastingCastingResultLine::query()
            ->with(['castingResult', 'productionPlan'])
            ->whereHas('castingResult', function ($q) use ($todayStr) {
                $q->whereDate('cast_date', $todayStr);
            });

        if ($scope) {
            $corQuery->whereHas('productionPlan', function ($p) use ($scope) {
                $p->where('product_scope', $scope);
            });
        }

        $corLines = $corQuery->get();
        $goodCorPcs = (int) $corLines->sum('qty_good');
        $rejectCorPcs = (int) $corLines->sum('qty_reject');

        $goodCorTon = 0.0;
        $rejectCorTon = 0.0;
        foreach ($corLines as $line) {
            $w = $this->resolveEffectiveUnitWeight($line);
            $goodCorTon += ((int) $line->qty_good * $w);
            $rejectCorTon += ((int) $line->qty_reject * $w);
        }
        $goodCorTon = round($goodCorTon / 1000, 2);
        $rejectCorTon = round($rejectCorTon / 1000, 2);

        $distinctHeatCount = $corLines->pluck('sand_casting_casting_result_id')->unique()->count();

        // 2. Daily Throughput by Stage (Reuses ProductionReportService for 100% reconciliation)
        $reportDataset = $this->reportService->getProductionDataset([
            'date_from' => $todayStr,
            'date_to' => $todayStr,
            'stage' => 'all',
        ], $user, includeDetails: false);

        /** @var Collection $stageSummaries */
        $stageSummaries = $reportDataset['stage_summaries'] ?? collect();

        $throughputStages = [];
        foreach ($stageSummaries as $stg) {
            $stgKey = (string) $stg['stage'];
            $throughputStages[] = [
                'stage' => $stgKey,
                'label' => (string) $stg['stage_label'],
                'order' => (int) $stg['stage_order'],
                'ktr_count' => (int) $stg['ktr_count'],
                'input_pcs' => (int) ($stg['input_pcs'] ?? 0),
                'good_pcs' => (int) $stg['good_pcs'],
                'good_ton' => round(((float) $stg['weight_kg']) / 1000, 2),
                'good_tonnage' => round(((float) $stg['weight_kg']) / 1000, 2),
                'defect_pcs' => (int) $stg['defect_pcs'],
                'defect_rate' => (float) $stg['defect_rate'],
                'defect_rate_pct' => (float) $stg['defect_rate'],
            ];
        }

        // 3. Warehouse Receipt Today (Gudang Jadi terminal output)
        $gdSummary = $stageSummaries->firstWhere('stage', 'gudang_jadi');
        $warehouseReceiptToday = [
            'checkpoint_code' => 'GUDANG_RECEIVE',
            'is_terminal' => true,
            'ktr_count' => (int) ($gdSummary['ktr_count'] ?? 0),
            'ktr_received_today' => (int) ($gdSummary['ktr_count'] ?? 0),
            'good_pcs' => (int) ($gdSummary['good_pcs'] ?? 0),
            'good_ton' => round(((float) ($gdSummary['weight_kg'] ?? 0.0)) / 1000, 2),
            'good_tonnage' => round(((float) ($gdSummary['weight_kg'] ?? 0.0)) / 1000, 2),
        ];

        // 4. Production Code Health & Plan (PO) Health Summary
        $statusRows = $this->statusService->getAggregatedRows(['status' => 'all'], $user);
        $activePlanCount = 0;
        $completedPlanCount = 0;
        $corShortageCount = 0;
        $activeCodes = [];
        $completedCodes = [];
        $allCodes = [];

        foreach ($statusRows as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code !== '') {
                $allCodes[$code] = true;
            }

            if (($row['status'] ?? '') === 'ACTIVE') {
                $activePlanCount++;
                if ($code !== '') {
                    $activeCodes[$code] = true;
                }
            } elseif (($row['status'] ?? '') === 'COMPLETED') {
                $completedPlanCount++;
                if ($code !== '') {
                    $completedCodes[$code] = true;
                }
            }

            if (($row['cor_indicator'] ?? '') === 'COR') {
                $corShortageCount++;
            }
        }

        $productionCodeHealth = [
            'active_production_code_count' => count($activeCodes),
            'cor_shortage_count' => $corShortageCount,
            'completed_production_code_count' => count($completedCodes),
            'total_production_code_count' => count($allCodes),
            'active_plan_count' => $activePlanCount,
            'completed_plan_count' => $completedPlanCount,
            'total_plan_count' => count($statusRows),
        ];

        return [
            'date' => $todayStr,
            'casting_today' => [
                'good_pcs' => $goodCorPcs,
                'good_ton' => $goodCorTon,
                'good_tonnage' => $goodCorTon,
                'reject_pcs' => $rejectCorPcs,
                'reject_ton' => $rejectCorTon,
                'reject_tonnage' => $rejectCorTon,
                'heat_count' => $distinctHeatCount,
            ],
            'warehouse_receipt_today' => $warehouseReceiptToday,
            'daily_throughput_stages' => $throughputStages,
            'production_code_health' => $productionCodeHealth,
            'production_plans_summary' => [
                'total_count' => count($statusRows),
                'active_count' => $activePlanCount,
                'completed_count' => $completedPlanCount,
                'cor_shortage_count' => $corShortageCount,
            ],
        ];
    }

    // =========================================================================
    // ZONE 2: CURRENT WIP & PRODUCTION FLOW
    // =========================================================================

    /**
     * Build Zone 2 payload: Pipeline stages, physical WIP donut data, and FIFO aging watchlist.
     *
     * @return array<string, mixed>
     */
    protected function buildZone2WipAndFlow(string $todayStr, ?User $user, ?string $scope): array
    {
        $wipStagesMap = [
            'netto' => ['label' => 'Netto', 'order' => 2],
            'bubut_od' => ['label' => 'Bubut OD', 'order' => 3],
            'bubut_cnc' => ['label' => 'Bubut CNC', 'order' => 4],
            'bor' => ['label' => 'Bor', 'order' => 5],
            'qc' => ['label' => 'QC', 'order' => 6],
        ];

        $linesQuery = SandCastingCastingResultLine::query()
            ->whereIn('current_stage', array_keys($wipStagesMap))
            ->with([
                'castingResult',
                'productionPlan',
                'castingOrderLine.castingOrder',
                'stageExecutions' => fn ($q) => $q->orderBy('executed_at', 'asc')->orderBy('id', 'asc'),
            ]);

        if ($scope) {
            $linesQuery->whereHas('productionPlan', function ($p) use ($scope) {
                $p->where('product_scope', $scope);
            });
        }

        $activeLines = $linesQuery->get();

        $stagePcs = [];
        $stageTon = [];
        $stageKtrCount = [];

        foreach (array_keys($wipStagesMap) as $stgKey) {
            $stagePcs[$stgKey] = 0;
            $stageTon[$stgKey] = 0.0;
            $stageKtrCount[$stgKey] = 0;
        }

        $watchlistCandidates = [];

        foreach ($activeLines as $line) {
            $stage = (string) $line->current_stage;
            if (! isset($wipStagesMap[$stage])) {
                continue;
            }

            // Centralized usable input qty (0 if halted/confirmed 0)
            $usableQty = $this->statusService->resolveKtrUsableQty($line);
            if ($usableQty <= 0) {
                continue;
            }

            $w = $this->resolveEffectiveUnitWeight($line);
            $stagePcs[$stage] += $usableQty;
            $stageTon[$stage] += ($usableQty * $w);
            $stageKtrCount[$stage]++;

            // Calculate aging for FIFO watchlist candidate
            $aging = $this->floorQueryService->calculateAging($line);
            $watchlistCandidates[] = [
                'traveler_number' => (string) $line->traveler_number,
                'heat_number' => (string) ($line->castingResult?->heat_number ?? '-'),
                'production_code' => (string) ($line->productionPlan?->code ?? $line->castingOrderLine?->code ?? '-'),
                'item_name' => (string) ($line->productionPlan?->item_name ?? $line->castingOrderLine?->item_name ?? '-'),
                'customer' => (string) ($line->productionPlan?->customer ?? $line->castingOrderLine?->customer ?? '-'),
                'stage' => $stage,
                'stage_label' => $wipStagesMap[$stage]['label'],
                'qty_pcs' => $usableQty,
                'total_aging_days' => (int) ($aging['total_aging_days'] ?? 0),
                'stage_aging_days' => (int) ($aging['stage_aging_days'] ?? 0),
                'stage_aging_hours' => (int) ($aging['stage_aging_hours'] ?? 0),
                'stage_aging_label' => (string) ($aging['stage_aging_label'] ?? '0j'),
                'is_urgent' => (bool) $line->is_urgent,
            ];
        }

        $totalActiveWipTon = 0.0;
        $totalActiveWipPcs = 0;
        foreach (array_keys($wipStagesMap) as $stgKey) {
            $stageTon[$stgKey] = round($stageTon[$stgKey] / 1000, 2);
            $totalActiveWipTon += $stageTon[$stgKey];
            $totalActiveWipPcs += $stagePcs[$stgKey];
        }
        $totalActiveWipTon = round($totalActiveWipTon, 2);

        $wipDistribution = [];
        foreach ($wipStagesMap as $stgKey => $info) {
            $ton = $stageTon[$stgKey];
            $pct = $totalActiveWipTon > 0 ? round(($ton / $totalActiveWipTon) * 100, 1) : 0.0;

            $wipDistribution[] = [
                'stage' => $stgKey,
                'label' => $info['label'],
                'order' => $info['order'],
                'wip_pcs' => $stagePcs[$stgKey],
                'wip_ton' => $ton,
                'percentage' => $pct,
                'ktr_count' => $stageKtrCount[$stgKey],
            ];
        }

        // Sort watchlist by oldest stage aging (descending)
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
            'wip_distribution' => $wipDistribution,
            'terminal_stage' => [
                'stage' => 'gudang_jadi',
                'label' => 'Gudang Jadi (Terminal)',
                'is_wip' => false,
                'note' => 'Tahap terminal dikeluarkan dari perhitungan WIP aktif.',
            ],
            'aging_watchlist' => $top5Watchlist,
        ];
    }

    // =========================================================================
    // ZONE 3: QUALITY, DEFECT & ACTION BACKLOG
    // =========================================================================

    /**
     * Build Zone 3 payload: 3 distinct backlog action cards, defects today, breakdown, and 7-day trend.
     *
     * @return array<string, mixed>
     */
    protected function buildZone3QualityAndAction(string $todayStr, ?User $user, ?string $scope): array
    {
        $activationCutoff = config('sand_casting.auto_nihil.activation_date', '2026-10-09');

        // 1. Action Cards (Isolated state queries)
        $baseExecQuery = SandCastingStageExecution::query()
            ->with(['castingResultLine.productionPlan']);

        if ($scope) {
            $baseExecQuery->whereHas('castingResultLine.productionPlan', function ($p) use ($scope) {
                $p->where('product_scope', $scope);
            });
        }

        $hasAutoNihil = $this->hasAutoNihilColumn();
        $hasInspectionDate = $this->hasInspectionDateColumn();

        // Card 1: WAITING_DEFECT (PPIC belum catat defect)
        $unrecordedQuery = (clone $baseExecQuery)
            ->where('status', SandCastingStageExecution::STATUS_WAITING_DEFECT)
            ->where(function ($q) use ($activationCutoff) {
                $q->whereNull('physical_done_at')
                    ->orWhere('physical_done_at', '>=', $activationCutoff);
            });

        if ($hasAutoNihil) {
            $unrecordedQuery->where('is_auto_nihil', false);
        }

        $unrecordedExecs = $unrecordedQuery->get();

        $unrecordedCard = [
            'status_code' => 'WAITING_DEFECT',
            'title' => 'Belum Dicatat PPIC',
            'ktr_count' => $unrecordedExecs->count(),
            'pcs_waiting' => (int) $unrecordedExecs->sum('input_qty'),
            'action_route' => 'sand-casting.defects.index',
        ];

        // Card 2: Auto-Nihil Menunggu QC (is_auto_nihil = true, WAITING_QC)
        if ($hasAutoNihil) {
            $autoNihilExecs = (clone $baseExecQuery)
                ->where('is_auto_nihil', true)
                ->where('status', SandCastingStageExecution::STATUS_WAITING_QC)
                ->get();
        } else {
            $autoNihilExecs = collect();
        }

        $autoNihilCard = [
            'status_code' => 'AUTO_NIHIL_WAITING_QC',
            'title' => 'Auto-Nihil Menunggu QC',
            'ktr_count' => $autoNihilExecs->count(),
            'pcs_defect' => 0,
            'is_system_timeout' => true,
            'note' => 'Penutupan otomatis timeout 5 hari kalender. Belum diverifikasi QC fisik.',
            'action_route' => 'sand-casting.qc-defects.index',
        ];

        // Card 3: Defect Riil Menunggu QC (is_auto_nihil = false, WAITING_QC)
        $ppicDefectQuery = (clone $baseExecQuery)
            ->where('status', SandCastingStageExecution::STATUS_WAITING_QC);

        if ($hasAutoNihil) {
            $ppicDefectQuery->where('is_auto_nihil', false);
        }

        $ppicDefectExecs = $ppicDefectQuery->get();

        $ppicDefectCard = [
            'status_code' => 'DEFECT_WAITING_QC',
            'title' => 'Defect Menunggu QC',
            'ktr_count' => $ppicDefectExecs->count(),
            'pcs_defect' => (int) $ppicDefectExecs->sum('defect_qty'),
            'action_route' => 'sand-casting.qc-defects.index',
        ];

        // Pre-Activation Backlog (< 2026-10-09)
        $preActivationExecs = (clone $baseExecQuery)
            ->where('status', SandCastingStageExecution::STATUS_WAITING_DEFECT)
            ->whereNotNull('physical_done_at')
            ->where('physical_done_at', '<', $activationCutoff)
            ->get();

        $preActivationCard = [
            'status_code' => 'PRE_ACTIVATION_BACKLOG',
            'title' => 'Backlog Historis (< 09/10/2026)',
            'ktr_count' => $preActivationExecs->count(),
            'pcs_waiting' => (int) $preActivationExecs->sum('input_qty'),
            'note' => 'Data historis sebelum aktivasi kebijakan dilindungi dari Auto-Nihil.',
        ];

        // 2. Defects Today (Inspection basis with fallback to entry date)
        $todayDefectExecs = (clone $baseExecQuery)
            ->where(function ($q) use ($todayStr, $hasInspectionDate) {
                if ($hasInspectionDate) {
                    $q->whereDate('inspection_date', $todayStr)
                        ->orWhere(function ($sq) use ($todayStr) {
                            $sq->whereNull('inspection_date')
                                ->whereDate('defect_entered_at', $todayStr);
                        });
                } else {
                    $q->whereDate('defect_entered_at', $todayStr);
                }
            })
            ->where('defect_qty', '>', 0)
            ->get();

        $totalDefectTodayPcs = (int) $todayDefectExecs->sum('defect_qty');
        $totalDefectTodayTon = 0.0;
        foreach ($todayDefectExecs as $e) {
            $line = $e->castingResultLine;
            $w = $line ? $this->resolveEffectiveUnitWeight($line) : 0.0;
            $totalDefectTodayTon += ((int) $e->defect_qty * $w);
        }
        $totalDefectTodayTon = round($totalDefectTodayTon / 1000, 2);

        // Verified breakdown from sand_casting_stage_execution_defects
        $verifiedDefectQuery = SandCastingStageExecutionDefect::query()
            ->whereHas('stageExecution', function ($q) use ($todayStr, $scope) {
                $q->where('status', SandCastingStageExecution::STATUS_CONFIRMED)
                    ->where(function ($sq) use ($todayStr) {
                        $sq->whereDate('inspection_date', $todayStr)
                            ->orWhere(function ($ssq) use ($todayStr) {
                                $ssq->whereNull('inspection_date')
                                    ->whereDate('qc_verified_at', $todayStr);
                            });
                    });

                if ($scope) {
                    $q->whereHas('castingResultLine.productionPlan', function ($p) use ($scope) {
                        $p->where('product_scope', $scope);
                    });
                }
            })
            ->with('defectType');

        $verifiedDefects = $verifiedDefectQuery->get();
        $verifiedTotalPcs = (int) $verifiedDefects->sum('qty');

        $groupedBreakdown = $verifiedDefects->groupBy('defect_type_id');
        $verifiedBreakdown = [];

        foreach ($groupedBreakdown as $typeId => $items) {
            /** @var DefectType|null $typeModel */
            $typeModel = $items->first()?->defectType;
            $qty = (int) $items->sum('qty');
            $pct = $verifiedTotalPcs > 0 ? round(($qty / $verifiedTotalPcs) * 100, 1) : 0.0;

            $verifiedBreakdown[] = [
                'defect_type_id' => (int) $typeId,
                'name' => (string) ($typeModel?->name ?? 'Lain-lain'),
                'department' => (string) ($typeModel?->department ?? '-'),
                'qty_pcs' => $qty,
                'percentage' => $pct,
            ];
        }

        usort($verifiedBreakdown, fn ($a, $b) => $b['qty_pcs'] <=> $a['qty_pcs']);

        $unverifiedBreakdownPcs = (int) (clone $baseExecQuery)
            ->where('status', SandCastingStageExecution::STATUS_WAITING_QC)
            ->where('defect_qty', '>', 0)
            ->where(function ($q) use ($todayStr) {
                $q->whereDate('inspection_date', $todayStr)
                    ->orWhereDate('defect_entered_at', $todayStr);
            })
            ->sum('defect_qty');

        // 3. 7-Day Defect Trend (Consecutive 7 calendar days)
        $trendDays = [];
        for ($i = 6; $i >= 0; $i--) {
            $targetDate = Carbon::parse($todayStr, self::TIMEZONE)->subDays($i)->toDateString();
            $dayLabel = Carbon::parse($targetDate, self::TIMEZONE)->format('d M');

            $dayExecs = (clone $baseExecQuery)
                ->where(function ($q) use ($targetDate) {
                    $q->whereDate('inspection_date', $targetDate)
                        ->orWhere(function ($sq) use ($targetDate) {
                            $sq->whereNull('inspection_date')
                                ->whereDate('defect_entered_at', $targetDate);
                        });
                })
                ->where('defect_qty', '>', 0)
                ->get();

            $dayPcs = (int) $dayExecs->sum('defect_qty');
            $dayTon = 0.0;
            foreach ($dayExecs as $de) {
                $line = $de->castingResultLine;
                $w = $line ? $this->resolveEffectiveUnitWeight($line) : 0.0;
                $dayTon += ((int) $de->defect_qty * $w);
            }

            $trendDays[] = [
                'date' => $targetDate,
                'day_label' => $dayLabel,
                'defect_pcs' => $dayPcs,
                'defect_ton' => round($dayTon / 1000, 2),
                'defect_tonnage' => round($dayTon / 1000, 2),
            ];
        }

        // 4. Top Action Required Items (Up to 5 actionable KTRs)
        $topActionItems = $this->resolveTopActionItems($scope);

        return [
            'action_cards' => [
                'unrecorded_defects' => $unrecordedCard,
                'auto_nihil_waiting_qc' => $autoNihilCard,
                'ppic_defect_waiting_qc' => $ppicDefectCard,
                'pre_activation_backlog' => $preActivationCard,
            ],
            'defects_today' => [
                'total_defect_pcs' => $totalDefectTodayPcs,
                'total_defect_ton' => $totalDefectTodayTon,
                'total_defect_tonnage' => $totalDefectTodayTon,
                'verified_breakdown' => $verifiedBreakdown,
                'unverified_breakdown_pcs' => $unverifiedBreakdownPcs,
                'verified_source_label' => 'Berdasarkan Verifikasi QC (CONFIRMED)',
            ],
            'defect_trend_7_days' => $trendDays,
            'top_action_items' => $topActionItems,
        ];
    }

    /**
     * Resolve up to 5 top priority action items for PPIC and QC intervention.
     *
     * @return list<array<string, mixed>>
     */
    protected function resolveTopActionItems(?string $scope): array
    {
        $items = [];

        // 1. Halted KTR lines (confirmed good_qty === 0)
        $haltedExecs = SandCastingStageExecution::query()
            ->where('status', SandCastingStageExecution::STATUS_CONFIRMED)
            ->where('good_qty', 0)
            ->with(['castingResultLine.productionPlan', 'castingResultLine.castingResult'])
            ->orderBy('qc_verified_at', 'desc')
            ->limit(5)
            ->get();

        foreach ($haltedExecs as $he) {
            $line = $he->castingResultLine;
            if (! $line) {
                continue;
            }
            if ($scope && $line->productionPlan?->product_scope !== $scope) {
                continue;
            }

            $items[] = [
                'execution_id' => $he->id,
                'type' => 'HALTED',
                'severity' => 'CRITICAL',
                'traveler_number' => (string) $line->traveler_number,
                'heat_number' => (string) ($line->castingResult?->heat_number ?? '-'),
                'production_code' => (string) ($line->productionPlan?->code ?? '-'),
                'stage' => (string) $he->stage,
                'checkpoint' => (string) $he->checkpoint_code,
                'status' => (string) $he->status,
                'is_auto_nihil' => (bool) $he->is_auto_nihil,
                'issue' => "KTR Berhenti Total (Good Qty 0 pcs pada {$he->checkpoint_code})",
                'action_recommendation' => 'Investigasi PPIC / Buat Rencana Cetak Pengganti',
                'action_route' => 'sand-casting.defects.index',
            ];
            if (count($items) >= 5) {
                return $items;
            }
        }

        // 2. Overdue WAITING_DEFECT approaching Auto-Nihil (> 3 days physical_done_at)
        $nearTimeoutQuery = SandCastingStageExecution::query()
            ->where('status', SandCastingStageExecution::STATUS_WAITING_DEFECT)
            ->whereNotNull('physical_done_at')
            ->where('physical_done_at', '<=', now()->subDays(3));

        if ($this->hasAutoNihilColumn()) {
            $nearTimeoutQuery->where('is_auto_nihil', false);
        }

        $nearTimeoutExecs = $nearTimeoutQuery
            ->with(['castingResultLine.productionPlan', 'castingResultLine.castingResult'])
            ->orderBy('physical_done_at', 'asc')
            ->limit(5)
            ->get();

        foreach ($nearTimeoutExecs as $te) {
            $line = $te->castingResultLine;
            if (! $line) {
                continue;
            }
            if ($scope && $line->productionPlan?->product_scope !== $scope) {
                continue;
            }

            $agingDays = (int) $te->physical_done_at?->diffInDays(now());
            $items[] = [
                'execution_id' => $te->id,
                'type' => 'NEAR_TIMEOUT',
                'severity' => 'WARNING',
                'traveler_number' => (string) $line->traveler_number,
                'heat_number' => (string) ($line->castingResult?->heat_number ?? '-'),
                'production_code' => (string) ($line->productionPlan?->code ?? '-'),
                'stage' => (string) $te->stage,
                'checkpoint' => (string) $te->checkpoint_code,
                'status' => (string) $te->status,
                'is_auto_nihil' => false,
                'issue' => "Menunggu Pencatatan Defect PPIC ({$agingDays} hari)",
                'action_recommendation' => 'Catat Defect sebelum Auto-Nihil 5 hari',
                'action_route' => 'sand-casting.defects.index',
            ];
            if (count($items) >= 5) {
                return $items;
            }
        }

        return $items;
    }

    /**
     * Resolve effective unit weight in KG with standard application fallback:
     * line.unit_weight_kg > 0 ? line.unit_weight_kg : (plan.weight ?? 0.0)
     */
    protected function resolveEffectiveUnitWeight(SandCastingCastingResultLine $line): float
    {
        if ($line->unit_weight_kg !== null && (float) $line->unit_weight_kg > 0) {
            return (float) $line->unit_weight_kg;
        }

        if ($line->productionPlan && $line->productionPlan->weight !== null && (float) $line->productionPlan->weight > 0) {
            return (float) $line->productionPlan->weight;
        }

        return 0.0;
    }

    protected static ?bool $hasAutoNihilColumn = null;

    protected static ?bool $hasInspectionDateColumn = null;

    protected function hasAutoNihilColumn(): bool
    {
        if (static::$hasAutoNihilColumn === null) {
            static::$hasAutoNihilColumn = Schema::hasColumn('sand_casting_stage_executions', 'is_auto_nihil');
        }

        return static::$hasAutoNihilColumn;
    }

    protected function hasInspectionDateColumn(): bool
    {
        if (static::$hasInspectionDateColumn === null) {
            static::$hasInspectionDateColumn = Schema::hasColumn('sand_casting_stage_executions', 'inspection_date');
        }

        return static::$hasInspectionDateColumn;
    }

    public static function resetSchemaCache(): void
    {
        static::$hasAutoNihilColumn = null;
        static::$hasInspectionDateColumn = null;
    }
}
