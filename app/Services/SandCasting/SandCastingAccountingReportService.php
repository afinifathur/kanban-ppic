<?php

namespace App\Services\SandCasting;

use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class SandCastingAccountingReportService
{
    public const TIMEZONE = 'Asia/Jakarta';

    public const STAGES = [
        'cor' => 'COR',
        'netto' => 'NETTO',
        'bubut_od' => 'BUBUT OD',
        'bubut_cnc' => 'BUBUT CNC',
        'bor' => 'BOR',
        'qc' => 'QC',
        'gudang_jadi' => 'GUDANG JADI',
    ];

    public const STAGE_ORDER = [
        'cor' => 1,
        'netto' => 2,
        'bubut_od' => 3,
        'bubut_cnc' => 4,
        'bor' => 5,
        'qc' => 6,
        'gudang_jadi' => 7,
    ];

    /**
     * Map checkpoint code to canonical stage.
     */
    public static function mapCheckpointToStage(?string $checkpointCode, ?string $rawStage = null): string
    {
        $code = strtoupper(trim((string) $checkpointCode));

        return match ($code) {
            'NETTO_CUT' => 'netto',
            'OD_TURNING' => 'bubut_od',
            'CNC_MACHINING', 'QC_POST_CNC', 'QC_PRE_BOR' => 'bubut_cnc',
            'BOR_DRILLING' => 'bor',
            'QC_FINAL_INSPECTION' => 'qc',
            'GUDANG_RECEIVE' => 'gudang_jadi',
            default => strtolower(trim((string) $rawStage)) ?: 'netto',
        };
    }

    /**
     * Resolve unit weight in KG with priority: line unit_weight_kg > plan weight > 0.
     */
    public static function resolveUnitWeightKg(SandCastingCastingResultLine $line): float
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
     * Resolve historical defect quantity for a stage execution at cutoff T using As-Recorded defect logs.
     */
    public function resolveHistoricalDefectQty(SandCastingStageExecution $execution, Carbon $cutoff): int
    {
        if ($execution->relationLoaded('defectLogs') && $execution->defectLogs->isNotEmpty()) {
            $log = $execution->defectLogs
                ->filter(fn ($l) => $l->created_at !== null && Carbon::parse($l->created_at)->lte($cutoff))
                ->sortByDesc(fn ($l) => [$l->created_at?->timestamp ?? 0, $l->id])
                ->first();

            return $log ? (int) $log->new_total : 0;
        }

        // Fallback for automated or legacy executions with no defect logs
        if ($execution->defect_entered_at !== null && Carbon::parse($execution->defect_entered_at)->lte($cutoff)) {
            return (int) $execution->defect_qty;
        }

        return 0;
    }

    /**
     * Build periodic accounting report dataset (Pergerakan Produksi Horizontal).
     *
     * @param  array{date_from: string, date_to: string, search?: string|null, product_scope?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function getAccountingReportData(array $filters, ?User $user = null, bool $includeWip = false): array
    {
        $dateFromStr = trim((string) ($filters['date_from'] ?? Carbon::now(self::TIMEZONE)->startOfMonth()->toDateString()));
        $dateToStr = trim((string) ($filters['date_to'] ?? Carbon::now(self::TIMEZONE)->toDateString()));

        $dateFrom = Carbon::parse($dateFromStr, self::TIMEZONE)->startOfDay();
        $dateTo = Carbon::parse($dateToStr, self::TIMEZONE)->endOfDay();
        $cutoffEnd = $dateTo->copy(); // e.g. 2026-10-31 23:59:59

        $search = trim((string) ($filters['search'] ?? ''));
        $scope = $filters['product_scope'] ?? $user?->product_scope;
        if ($scope === 'all') {
            $scope = null;
        }

        $format = in_array($filters['report_format'] ?? ($filters['format'] ?? 'detail'), ['summary', 'ringkas'], true) ? 'summary' : 'detail';

        // Fast query: Only select candidate lines with physical activity in the period
        $lineQuery = SandCastingCastingResultLine::query()
            ->where(function (Builder $q) use ($dateFrom, $dateTo) {
                $q->whereBetween('created_at', [$dateFrom, $dateTo])
                    ->orWhereHas('castingResult', function ($cq) use ($dateFrom, $dateTo) {
                        $cq->whereBetween('cast_date', [$dateFrom->toDateString(), $dateTo->toDateString()]);
                    })
                    ->orWhereHas('stageExecutions', function ($eq) use ($dateFrom, $dateTo) {
                        $eq->whereBetween('physical_done_at', [$dateFrom, $dateTo])
                            ->orWhereBetween('defect_entered_at', [$dateFrom, $dateTo])
                            ->orWhereHas('defectLogs', function ($lq) use ($dateFrom, $dateTo) {
                                $lq->whereBetween('created_at', [$dateFrom, $dateTo]);
                            });
                    });
            })
            ->with([
                'castingResult',
                'productionPlan',
                'castingOrderLine.castingOrder',
                'stageExecutions' => function ($eq) {
                    $eq->orderBy('physical_done_at', 'asc')->orderBy('id', 'asc')
                        ->with(['defectLogs' => fn ($lq) => $lq->orderBy('created_at', 'asc')->orderBy('id', 'asc')]);
                },
            ]);

        if ($scope) {
            $lineQuery->whereHas('productionPlan', fn ($pq) => $pq->where('product_scope', $scope));
        }

        if ($search !== '') {
            $lineQuery->where(function (Builder $q) use ($search) {
                $q->where('traveler_number', 'like', "%{$search}%")
                    ->orWhereHas('productionPlan', function ($pq) use ($search) {
                        $pq->where('code', 'like', "%{$search}%")
                            ->orWhere('item_name', 'like', "%{$search}%")
                            ->orWhere('customer', 'like', "%{$search}%")
                            ->orWhere('aisi', 'like', "%{$search}%");
                    })
                    ->orWhereHas('castingResult', function ($rq) use ($search) {
                        $rq->where('heat_number', 'like', "%{$search}%");
                    });
            });
        }

        /** @var Collection<int, SandCastingCastingResultLine> $candidateLines */
        $candidateLines = $lineQuery->orderBy('id', 'asc')->get();

        $pergerakanRows = [];
        foreach ($candidateLines as $line) {
            $unitWeight = self::resolveUnitWeightKg($line);
            $evalPergerakan = $this->evaluateLineAtCutoff($line, $cutoffEnd, $unitWeight);
            $pergerakanRows[] = $evalPergerakan['row_data'];
        }

        $aggregatedDetail = $this->aggregateMovementRows($pergerakanRows);
        $aggregatedSummary = $this->aggregateSummaryRows($aggregatedDetail['rows']);

        $allAnomalies = array_merge($aggregatedDetail['anomalies'], $aggregatedSummary['anomalies']);
        $displayRows = $format === 'summary' ? $aggregatedSummary['rows'] : $aggregatedDetail['rows'];
        $displayAnomalies = $format === 'summary' ? $allAnomalies : $aggregatedDetail['anomalies'];

        $result = [
            'meta' => [
                'date_from' => $dateFromStr,
                'date_to' => $dateToStr,
                'generated_at' => Carbon::now(self::TIMEZONE)->format('d/m/Y H:i:s'),
                'generated_by' => $user?->name ?? 'System',
                'product_scope' => $scope ?: 'Semua Scope',
                'report_format' => $format,
                'format_label' => $format === 'summary' ? 'Ringkas per Kode Produksi' : 'Detail per Heat Number',
            ],
            'pergerakan' => $displayRows,
            'pergerakan_raw' => $pergerakanRows,
            'pergerakan_detail' => $aggregatedDetail['rows'],
            'pergerakan_summary' => $aggregatedSummary['rows'],
            'anomalies' => $displayAnomalies,
        ];

        // Isolated WIP calculation path: Only executed when explicitly requested
        if ($includeWip) {
            $wipData = $this->calculateWipReconciliationData($filters, $user);
            $result = array_merge($result, $wipData);
        }

        return $result;
    }

    /**
     * Isolated helper to calculate opening/closing WIP and reconciliation math.
     * Preserved for future WIP-dedicated modules; NOT called in the main movement report flow.
     *
     * @param  array{date_from: string, date_to: string, search?: string|null, product_scope?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function calculateWipReconciliationData(array $filters, ?User $user = null): array
    {
        $dateFromStr = trim((string) ($filters['date_from'] ?? Carbon::now(self::TIMEZONE)->startOfMonth()->toDateString()));
        $dateToStr = trim((string) ($filters['date_to'] ?? Carbon::now(self::TIMEZONE)->toDateString()));

        $dateFrom = Carbon::parse($dateFromStr, self::TIMEZONE)->startOfDay();
        $dateTo = Carbon::parse($dateToStr, self::TIMEZONE)->endOfDay();

        $cutoffStart = $dateFrom->copy()->subSecond();
        $cutoffEnd = $dateTo->copy();

        $search = trim((string) ($filters['search'] ?? ''));
        $scope = $filters['product_scope'] ?? $user?->product_scope;
        if ($scope === 'all') {
            $scope = null;
        }

        $lineQuery = SandCastingCastingResultLine::query()
            ->where(function (Builder $q) use ($cutoffEnd) {
                $q->where('created_at', '<=', $cutoffEnd)
                    ->orWhereHas('castingResult', fn ($cq) => $cq->whereDate('cast_date', '<=', $cutoffEnd->toDateString()));
            })
            ->with([
                'castingResult',
                'productionPlan',
                'castingOrderLine.castingOrder',
                'stageExecutions' => function ($eq) {
                    $eq->orderBy('physical_done_at', 'asc')->orderBy('id', 'asc')
                        ->with(['defectLogs' => fn ($lq) => $lq->orderBy('created_at', 'asc')->orderBy('id', 'asc')]);
                },
            ]);

        if ($scope) {
            $lineQuery->whereHas('productionPlan', fn ($pq) => $pq->where('product_scope', $scope));
        }

        if ($search !== '') {
            $lineQuery->where(function (Builder $q) use ($search) {
                $q->where('traveler_number', 'like', "%{$search}%")
                    ->orWhereHas('productionPlan', function ($pq) use ($search) {
                        $pq->where('code', 'like', "%{$search}%")
                            ->orWhere('item_name', 'like', "%{$search}%")
                            ->orWhere('customer', 'like', "%{$search}%")
                            ->orWhere('aisi', 'like', "%{$search}%");
                    })
                    ->orWhereHas('castingResult', function ($rq) use ($search) {
                        $rq->where('heat_number', 'like', "%{$search}%");
                    });
            });
        }

        /** @var Collection<int, SandCastingCastingResultLine> $candidateLines */
        $candidateLines = $lineQuery->orderBy('id', 'asc')->get();

        $saldoAwalRows = [];
        $saldoAkhirRows = [];
        $totalSaldoAwalPcs = 0;
        $totalSaldoAwalKg = 0.0;
        $totalSaldoAkhirPcs = 0;
        $totalSaldoAkhirKg = 0.0;
        $inflowCorPcs = 0;
        $inflowCorKg = 0.0;
        $outflowGdPcs = 0;
        $outflowGdKg = 0.0;
        $totalDefectPeriodPcs = 0;
        $totalDefectPeriodKg = 0.0;

        $deptDistributionSaldoAkhir = [
            'netto' => ['label' => 'Netto', 'pcs' => 0, 'kg' => 0.0],
            'bubut_od' => ['label' => 'Bubut OD', 'pcs' => 0, 'kg' => 0.0],
            'bubut_cnc' => ['label' => 'Bubut CNC', 'pcs' => 0, 'kg' => 0.0],
            'bor' => ['label' => 'Bor', 'pcs' => 0, 'kg' => 0.0],
            'qc' => ['label' => 'QC', 'pcs' => 0, 'kg' => 0.0],
        ];

        foreach ($candidateLines as $line) {
            $unitWeight = self::resolveUnitWeightKg($line);
            $castDate = $line->castingResult?->cast_date
                ? Carbon::parse($line->castingResult->cast_date, self::TIMEZONE)->startOfDay()
                : ($line->created_at ? Carbon::parse($line->created_at, self::TIMEZONE)->startOfDay() : null);

            $isCastBeforeStart = $castDate && $castDate->lte($cutoffStart);
            if ($isCastBeforeStart) {
                $evalStart = $this->evaluateLineAtCutoff($line, $cutoffStart, $unitWeight);
                if ($evalStart['is_wip']) {
                    $saldoAwalRows[] = $evalStart['row_data'];
                    $totalSaldoAwalPcs += $evalStart['wip_pcs'];
                    $totalSaldoAwalKg += $evalStart['wip_kg'];
                }
            }

            $isCastInPeriod = $castDate && $castDate->betweenIncluded($dateFrom, $dateTo);
            if ($isCastInPeriod) {
                $goodQty = (int) $line->qty_good;
                $inflowCorPcs += $goodQty;
                $inflowCorKg += ($goodQty * $unitWeight);
            }

            $gdExecInPeriod = $line->stageExecutions->first(function ($e) use ($dateFrom, $dateTo) {
                if ($e->physical_done_at === null) {
                    return false;
                }
                $stg = self::mapCheckpointToStage($e->checkpoint_code, $e->stage);
                $doneAt = Carbon::parse($e->physical_done_at, self::TIMEZONE);

                return $stg === 'gudang_jadi' && $doneAt->betweenIncluded($dateFrom, $dateTo);
            });

            if ($gdExecInPeriod) {
                $evalAtGd = $this->evaluateLineAtCutoff($line, Carbon::parse($gdExecInPeriod->physical_done_at, self::TIMEZONE), $unitWeight);
                $gdOutPcs = (int) ($evalAtGd['stages']['gudang_jadi']['output_pcs'] ?? $evalAtGd['wip_pcs']);
                $outflowGdPcs += $gdOutPcs;
                $outflowGdKg += ($gdOutPcs * $unitWeight);
            }

            foreach ($line->stageExecutions as $exec) {
                $stg = self::mapCheckpointToStage($exec->checkpoint_code, $exec->stage);
                if ($stg === 'cor' || $stg === 'gudang_jadi') {
                    continue;
                }

                if ($exec->relationLoaded('defectLogs') && $exec->defectLogs->isNotEmpty()) {
                    $periodLogs = $exec->defectLogs->filter(function ($l) use ($dateFrom, $dateTo) {
                        if ($l->created_at === null) {
                            return false;
                        }
                        $cat = Carbon::parse($l->created_at, self::TIMEZONE);

                        return $cat->betweenIncluded($dateFrom, $dateTo);
                    });

                    foreach ($periodLogs as $pl) {
                        $delta = (int) $pl->added_qty;
                        $totalDefectPeriodPcs += $delta;
                        $totalDefectPeriodKg += ($delta * $unitWeight);
                    }
                } else {
                    if ($exec->defect_qty > 0) {
                        $defectDate = $exec->defect_entered_at ?? $exec->physical_done_at;
                        if ($defectDate && Carbon::parse($defectDate, self::TIMEZONE)->betweenIncluded($dateFrom, $dateTo)) {
                            $totalDefectPeriodPcs += (int) $exec->defect_qty;
                            $totalDefectPeriodKg += ((int) $exec->defect_qty * $unitWeight);
                        }
                    }
                }
            }

            $isCastBeforeEnd = $castDate && $castDate->lte($cutoffEnd);
            if ($isCastBeforeEnd) {
                $evalEnd = $this->evaluateLineAtCutoff($line, $cutoffEnd, $unitWeight);
                if ($evalEnd['is_wip']) {
                    $saldoAkhirRows[] = $evalEnd['row_data'];
                    $totalSaldoAkhirPcs += $evalEnd['wip_pcs'];
                    $totalSaldoAkhirKg += $evalEnd['wip_kg'];

                    $wipStage = $evalEnd['wip_stage'];
                    if (isset($deptDistributionSaldoAkhir[$wipStage])) {
                        $deptDistributionSaldoAkhir[$wipStage]['pcs'] += $evalEnd['wip_pcs'];
                        $deptDistributionSaldoAkhir[$wipStage]['kg'] += $evalEnd['wip_kg'];
                    }
                }
            }
        }

        $calculatedAkhirPcs = ($totalSaldoAwalPcs + $inflowCorPcs - $totalDefectPeriodPcs - $outflowGdPcs);
        $calculatedAkhirKg = round($totalSaldoAwalKg + $inflowCorKg - $totalDefectPeriodKg - $outflowGdKg, 2);
        $diffPcs = $totalSaldoAkhirPcs - $calculatedAkhirPcs;
        $diffKg = round($totalSaldoAkhirKg - $calculatedAkhirKg, 2);
        $isBalanced = ($diffPcs === 0) && (abs($diffKg) < 0.01);

        $reconciliationSummary = [
            'period_from' => $dateFromStr,
            'period_to' => $dateToStr,
            'saldo_awal_pcs' => $totalSaldoAwalPcs,
            'saldo_awal_kg' => round($totalSaldoAwalKg, 2),
            'inflow_cor_pcs' => $inflowCorPcs,
            'inflow_cor_kg' => round($inflowCorKg, 2),
            'defect_proses_pcs' => $totalDefectPeriodPcs,
            'defect_proses_kg' => round($totalDefectPeriodKg, 2),
            'outflow_gudang_jadi_pcs' => $outflowGdPcs,
            'outflow_gudang_jadi_kg' => round($outflowGdKg, 2),
            'saldo_akhir_pcs' => $totalSaldoAkhirPcs,
            'saldo_akhir_kg' => round($totalSaldoAkhirKg, 2),
            'calculated_saldo_akhir_pcs' => $calculatedAkhirPcs,
            'calculated_saldo_akhir_kg' => $calculatedAkhirKg,
            'discrepancy_pcs' => $diffPcs,
            'discrepancy_kg' => $diffKg,
            'diff_pcs' => $diffPcs,
            'diff_kg' => $diffKg,
            'is_balanced' => $isBalanced,
            'dept_distribution' => $deptDistributionSaldoAkhir,
            'sebaran_saldo_akhir' => $deptDistributionSaldoAkhir,
        ];

        $aggregatedSaldoAwal = $this->aggregateWipRows($saldoAwalRows);
        $aggregatedSaldoAkhir = $this->aggregateWipRows($saldoAkhirRows);

        return [
            'summary' => $reconciliationSummary,
            'saldo_awal' => $aggregatedSaldoAwal,
            'saldo_awal_wip' => $aggregatedSaldoAwal,
            'saldo_awal_raw' => $saldoAwalRows,
            'saldo_akhir' => $aggregatedSaldoAkhir,
            'saldo_akhir_wip' => $aggregatedSaldoAkhir,
            'saldo_akhir_raw' => $saldoAkhirRows,
        ];
    }

    /**
     * Group movement rows by Code + Heat Number into Horizontal Production Tracking format.
     *
     * @param  array<int, array<string, mixed>>  $rawRows
     * @return array{rows: array<int, array<string, mixed>>, anomalies: array<string, mixed>}
     */
    public function aggregateMovementRows(array $rawRows): array
    {
        $grouped = [];
        $anomalies = [];

        foreach ($rawRows as $r) {
            $code = trim((string) ($r['code'] ?? '-'));
            $heat = trim((string) ($r['heat_number'] ?? '-'));
            $key = $code.'|||'.$heat;

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'code' => $code,
                    'heat_number' => $heat,
                    'cast_date' => $r['cast_date'] ?? '-',
                    'item_name' => (string) ($r['item_name'] ?? '-'),
                    'aisi' => (string) ($r['aisi'] ?? '-'),
                    'size' => (string) ($r['size'] ?? '-'),
                    'customer' => (string) ($r['customer'] ?? '-'),
                    'po_qty' => $r['po_qty'] ?? ($r['po_number'] ?? ''),
                    'po_number' => $r['po_number'] ?? '',
                    'qty_planned' => $r['qty_planned'] ?? '',
                    'unit_weight_kg' => (float) ($r['unit_weight_kg'] ?? 0.0),
                    'total_weight_kg' => 0.0,
                    'batch_cor_good_pcs' => 0,
                    'is_weight_anomaly' => false,
                    'anomaly_notes' => null,
                    'ktr_count' => 0,
                    'traveler_numbers' => [],
                    'stages' => [
                        'cor' => ['hasil' => null, 'rusak' => null, 'output' => null, 'is_done' => false],
                        'netto' => ['hasil' => null, 'rusak' => null, 'output' => null, 'is_done' => false],
                        'bubut_od' => ['hasil' => null, 'rusak' => null, 'output' => null, 'is_done' => false],
                        'bubut_cnc' => ['hasil' => null, 'rusak' => null, 'output' => null, 'is_done' => false],
                        'bor' => ['hasil' => null, 'rusak' => null, 'output' => null, 'is_done' => false],
                        'qc' => ['hasil' => null, 'rusak' => null, 'output' => null, 'is_done' => false],
                        'gudang_jadi' => ['hasil' => null, 'rusak' => null, 'output' => null, 'is_done' => false],
                    ],
                ];
            }

            $g = &$grouped[$key];
            $g['ktr_count']++;
            $g['traveler_numbers'][] = $r['traveler_number'] ?? '';
            if (($g['po_number'] === '' || $g['po_number'] === null) && ! empty($r['po_number'])) {
                $g['po_number'] = $r['po_number'];
                $g['po_qty'] = $r['po_qty'] ?? $r['po_number'];
            }
            if (($g['qty_planned'] === '' || $g['qty_planned'] === null) && ! empty($r['qty_planned'])) {
                $g['qty_planned'] = $r['qty_planned'];
            }

            // Check weight consistency
            $currentUnitWeight = (float) ($r['unit_weight_kg'] ?? 0.0);
            if ($g['unit_weight_kg'] == 0.0 && $currentUnitWeight > 0.0) {
                $g['unit_weight_kg'] = $currentUnitWeight;
            } elseif ($currentUnitWeight > 0.0 && abs($g['unit_weight_kg'] - $currentUnitWeight) > 0.0001) {
                $g['is_weight_anomaly'] = true;
                $g['anomaly_notes'] = "Ditemukan variasi bobot satuan pada kombinasi ini ({$g['unit_weight_kg']} kg vs {$currentUnitWeight} kg)";
                $anomalies[$key] = [
                    'code' => $code,
                    'heat_number' => $heat,
                    'weights' => [$g['unit_weight_kg'], $currentUnitWeight],
                ];
            }

            // Accumulate COR batch good quantity
            $corGood = (int) ($r['stages']['cor']['hasil_pcs'] ?? $r['stages']['cor']['hasil'] ?? 0);
            $g['batch_cor_good_pcs'] += $corGood;

            // Aggregate each stage
            foreach (array_keys(self::STAGES) as $stg) {
                $stInfo = $r['stages'][$stg] ?? null;
                if ($stInfo && ($stInfo['is_done'] ?? false)) {
                    $h = (int) ($stInfo['hasil_pcs'] ?? $stInfo['hasil'] ?? 0);
                    $rus = (int) ($stInfo['rusak_pcs'] ?? $stInfo['rusak'] ?? 0);
                    $out = (int) ($stInfo['output_pcs'] ?? $stInfo['output'] ?? 0);

                    if ($g['stages'][$stg]['hasil'] === null) {
                        $g['stages'][$stg]['hasil'] = $h;
                        $g['stages'][$stg]['rusak'] = $rus;
                        $g['stages'][$stg]['output'] = $out;
                        $g['stages'][$stg]['is_done'] = true;
                    } else {
                        $g['stages'][$stg]['hasil'] += $h;
                        $g['stages'][$stg]['rusak'] += $rus;
                        $g['stages'][$stg]['output'] += $out;
                    }
                }
            }
            unset($g);
        }

        // Finalize Total Weight and alias keys
        foreach ($grouped as &$item) {
            foreach (array_keys(self::STAGES) as $stg) {
                $item['stages'][$stg]['hasil_pcs'] = $item['stages'][$stg]['hasil'];
                $item['stages'][$stg]['rusak_pcs'] = $item['stages'][$stg]['rusak'];
                $item['stages'][$stg]['output_pcs'] = $item['stages'][$stg]['output'];
            }

            $baseQty = $item['batch_cor_good_pcs'];
            if ($baseQty <= 0) {
                foreach (array_keys(self::STAGES) as $stg) {
                    if ($item['stages'][$stg]['hasil'] !== null && $item['stages'][$stg]['hasil'] > 0) {
                        $baseQty = max($baseQty, (int) $item['stages'][$stg]['hasil']);
                    }
                }
            }
            $item['total_weight_kg'] = round($baseQty * $item['unit_weight_kg'], 2);
        }
        unset($item);

        return [
            'rows' => array_values($grouped),
            'anomalies' => array_values($anomalies),
        ];
    }

    /**
     * Group detail rows by Production Code into Summary format (Ringkas per Kode Produksi).
     *
     * @param  array<int, array<string, mixed>>  $detailRows
     * @return array{rows: array<int, array<string, mixed>>, anomalies: array<int, array<string, mixed>>}
     */
    public function aggregateSummaryRows(array $detailRows): array
    {
        $grouped = [];
        $anomalies = [];

        foreach ($detailRows as $r) {
            $code = trim((string) ($r['code'] ?? '-'));

            if (! isset($grouped[$code])) {
                $grouped[$code] = [
                    'code' => $code,
                    'item_name' => (string) ($r['item_name'] ?? '-'),
                    'aisi' => (string) ($r['aisi'] ?? '-'),
                    'size' => (string) ($r['size'] ?? '-'),
                    'customer' => (string) ($r['customer'] ?? '-'),
                    'po_numbers' => [],
                    'po_quantities' => [],
                    'qty_plans' => [],
                    'unit_weights' => [],
                    'total_weight_kg' => 0.0,
                    'heat_numbers' => [],
                    'traveler_numbers' => [],
                    'heat_count' => 0,
                    'ktr_count' => 0,
                    'is_weight_anomaly' => false,
                    'is_po_anomaly' => false,
                    'is_plan_anomaly' => false,
                    'stages' => [
                        'cor' => ['hasil' => null, 'rusak' => null, 'output' => null, 'is_done' => false],
                        'netto' => ['hasil' => null, 'rusak' => null, 'output' => null, 'is_done' => false],
                        'bubut_od' => ['hasil' => null, 'rusak' => null, 'output' => null, 'is_done' => false],
                        'bubut_cnc' => ['hasil' => null, 'rusak' => null, 'output' => null, 'is_done' => false],
                        'bor' => ['hasil' => null, 'rusak' => null, 'output' => null, 'is_done' => false],
                        'qc' => ['hasil' => null, 'rusak' => null, 'output' => null, 'is_done' => false],
                        'gudang_jadi' => ['hasil' => null, 'rusak' => null, 'output' => null, 'is_done' => false],
                    ],
                ];
            }

            $g = &$grouped[$code];
            $g['heat_count']++;
            if (! empty($r['heat_number'])) {
                $g['heat_numbers'][] = (string) $r['heat_number'];
            }
            $g['ktr_count'] += (int) ($r['ktr_count'] ?? 1);
            if (! empty($r['traveler_numbers'])) {
                $g['traveler_numbers'] = array_merge($g['traveler_numbers'], $r['traveler_numbers']);
            }

            $poCandidate = $r['po_qty'] ?? ($r['po_number'] ?? null);
            if ($poCandidate !== null && $poCandidate !== '') {
                $g['po_quantities'][] = is_numeric($poCandidate) ? (int) $poCandidate : trim((string) $poCandidate);
            }

            if (isset($r['qty_planned']) && $r['qty_planned'] !== null && $r['qty_planned'] !== '') {
                $g['qty_plans'][] = is_numeric($r['qty_planned']) ? (int) $r['qty_planned'] : trim((string) $r['qty_planned']);
            }

            $wVal = (float) ($r['unit_weight_kg'] ?? 0.0);
            if ($wVal > 0.0) {
                $g['unit_weights'][] = $wVal;
            }

            // Total weight is summed across all merged Heat Numbers (batch weight sum, NOT multiplied by dept)
            $g['total_weight_kg'] += (float) ($r['total_weight_kg'] ?? 0.0);

            // Stages summation: separate Hasil and Rusak
            foreach (array_keys(self::STAGES) as $stg) {
                $stInfo = $r['stages'][$stg] ?? null;
                if ($stInfo && ($stInfo['is_done'] ?? false) && $stInfo['hasil'] !== null) {
                    $h = (int) $stInfo['hasil'];
                    $rus = (int) ($stInfo['rusak'] ?? 0);
                    $out = (int) ($stInfo['output'] ?? max(0, $h - $rus));

                    if ($g['stages'][$stg]['hasil'] === null) {
                        $g['stages'][$stg]['hasil'] = $h;
                        $g['stages'][$stg]['rusak'] = $rus;
                        $g['stages'][$stg]['output'] = $out;
                        $g['stages'][$stg]['is_done'] = true;
                    } else {
                        $g['stages'][$stg]['hasil'] += $h;
                        $g['stages'][$stg]['rusak'] += $rus;
                        $g['stages'][$stg]['output'] += $out;
                    }
                }
            }
            unset($g);
        }

        // Finalize rows
        foreach ($grouped as &$g) {
            foreach (array_keys(self::STAGES) as $stg) {
                $g['stages'][$stg]['hasil_pcs'] = $g['stages'][$stg]['hasil'];
                $g['stages'][$stg]['rusak_pcs'] = $g['stages'][$stg]['rusak'];
                $g['stages'][$stg]['output_pcs'] = $g['stages'][$stg]['output'];
            }

            // P.O and Plan resolution (Single value per Kode Produksi, Plan = PO * 1.10)
            $uniquePos = array_values(array_unique($g['po_quantities']));
            if (empty($uniquePos)) {
                $g['po_qty'] = '';
                $g['po_number'] = '';
                $g['qty_planned'] = '';
            } elseif (count($uniquePos) === 1) {
                $val = $uniquePos[0];
                if (is_numeric($val) && (int) $val > 0) {
                    $intVal = (int) $val;
                    $g['po_qty'] = $intVal;
                    $g['po_number'] = $intVal;
                    $g['qty_planned'] = (int) round($intVal * 1.10);
                } else {
                    $g['po_qty'] = $val;
                    $g['po_number'] = $val;
                    $g['qty_planned'] = '';
                }
            } else {
                $g['po_qty'] = 'Bervariasi';
                $g['po_number'] = 'Bervariasi';
                $g['qty_planned'] = 'Bervariasi';
                $g['is_po_anomaly'] = true;
                $g['is_plan_anomaly'] = true;
                $anomalies[] = [
                    'type' => 'po_variation',
                    'code' => $g['code'],
                    'heat_number' => '-',
                    'values' => $uniquePos,
                    'message' => "Ditemukan variasi kuantitas P.O pada Kode {$g['code']}: ".implode(', ', $uniquePos),
                ];
            }

            // Weight resolution
            $distinctWeights = array_values(array_unique(array_map(fn ($w) => (string) round($w, 4), $g['unit_weights'])));
            if (empty($distinctWeights)) {
                $g['unit_weight_kg'] = 0.0;
                $g['unit_weight_label'] = '—';
            } elseif (count($distinctWeights) === 1) {
                $w = (float) $distinctWeights[0];
                $g['unit_weight_kg'] = $w;
                $g['unit_weight_label'] = number_format($w, 2, '.', '');
            } else {
                $g['unit_weight_kg'] = null;
                $g['unit_weight_label'] = 'Bervariasi';
                $g['is_weight_anomaly'] = true;
                $anomalies[] = [
                    'type' => 'weight_variation',
                    'code' => $g['code'],
                    'heat_number' => '-',
                    'weights' => array_map('floatval', $distinctWeights),
                    'message' => "Ditemukan variasi bobot satuan pada Kode {$g['code']}: ".implode(' kg vs ', $distinctWeights).' kg',
                ];
            }

            $g['total_weight_kg'] = round($g['total_weight_kg'], 2);
        }
        unset($g);

        return [
            'rows' => array_values($grouped),
            'anomalies' => array_values($anomalies),
        ];
    }

    /**
     * Group WIP rows (Saldo Awal / Saldo Akhir) by Code + Heat Number (+ Stage).
     *
     * @param  array<int, array<string, mixed>>  $rawRows
     * @return array<int, array<string, mixed>>
     */
    public function aggregateWipRows(array $rawRows): array
    {
        $grouped = [];

        foreach ($rawRows as $r) {
            $code = trim((string) ($r['code'] ?? '-'));
            $heat = trim((string) ($r['heat_number'] ?? '-'));
            $wipStage = (string) ($r['wip_stage'] ?? 'netto');
            $key = $code.'|||'.$heat.'|||'.$wipStage;

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'code' => $code,
                    'heat_number' => $heat,
                    'cast_date' => $r['cast_date'] ?? '-',
                    'item_name' => $r['item_name'] ?? '-',
                    'aisi' => $r['aisi'] ?? '-',
                    'size' => $r['size'] ?? '-',
                    'customer' => $r['customer'] ?? '-',
                    'unit_weight_kg' => (float) ($r['unit_weight_kg'] ?? 0.0),
                    'wip_stage' => $wipStage,
                    'wip_stage_label' => $r['wip_stage_label'] ?? (self::STAGES[$wipStage] ?? strtoupper($wipStage)),
                    'wip_pcs' => 0,
                    'wip_kg' => 0.0,
                    'ktr_count' => 0,
                    'traveler_numbers' => [],
                ];
            }

            $grouped[$key]['ktr_count']++;
            $grouped[$key]['traveler_numbers'][] = $r['traveler_number'] ?? '';
            $grouped[$key]['wip_pcs'] += (int) ($r['wip_pcs'] ?? 0);
            $grouped[$key]['wip_kg'] += (float) ($r['wip_kg'] ?? 0.0);
        }

        foreach ($grouped as &$g) {
            $g['wip_kg'] = round($g['wip_pcs'] * $g['unit_weight_kg'], 2);
        }
        unset($g);

        return array_values($grouped);
    }

    /**
     * Evaluate single KTR line state as of cutoff timestamp.
     *
     * @return array<string, mixed>
     */
    protected function evaluateLineAtCutoff(SandCastingCastingResultLine $line, Carbon $cutoff, float $unitWeight): array
    {
        $rootGood = (int) $line->qty_good;
        $corReject = (int) $line->qty_reject;

        // Group stage executions by canonical stage, keeping only executions with physical_done_at <= cutoff
        $execsAtCutoff = $line->stageExecutions
            ->filter(function (SandCastingStageExecution $e) use ($cutoff) {
                if ($e->physical_done_at === null) {
                    return false;
                }

                return Carbon::parse($e->physical_done_at, self::TIMEZONE)->lte($cutoff);
            })
            ->groupBy(function (SandCastingStageExecution $e) {
                return self::mapCheckpointToStage($e->checkpoint_code, $e->stage);
            });

        // Stage-by-stage sequential computation
        $stageData = [];
        $runningInput = $rootGood;
        $highestCompletedStage = null;
        $highestCompletedOrder = 1; // 1 = COR

        // COR
        $stageData['cor'] = [
            'hasil_pcs' => $rootGood,
            'rusak_pcs' => $corReject,
            'output_pcs' => $rootGood,
            'hasil' => $rootGood,
            'rusak' => $corReject,
            'output' => $rootGood,
            'is_done' => true,
        ];

        $orderedStages = ['netto', 'bubut_od', 'bubut_cnc', 'bor', 'qc', 'gudang_jadi'];

        foreach ($orderedStages as $stg) {
            $hasExec = $execsAtCutoff->has($stg);

            if ($hasExec) {
                /** @var Collection<int, SandCastingStageExecution> $stgExecs */
                $stgExecs = $execsAtCutoff->get($stg);

                // Sum defects as-recorded up to cutoff across checkpoints of this stage
                $defectSum = 0;
                foreach ($stgExecs as $se) {
                    $defectSum += $this->resolveHistoricalDefectQty($se, $cutoff);
                }

                $hasil = $runningInput;
                $output = max(0, $hasil - $defectSum);

                $stageData[$stg] = [
                    'hasil_pcs' => $hasil,
                    'rusak_pcs' => $defectSum,
                    'output_pcs' => $output,
                    'hasil' => $hasil,
                    'rusak' => $defectSum,
                    'output' => $output,
                    'is_done' => true,
                ];

                $highestCompletedStage = $stg;
                $highestCompletedOrder = self::STAGE_ORDER[$stg];
                $runningInput = $output;
            } else {
                $stageData[$stg] = [
                    'hasil_pcs' => null,
                    'rusak_pcs' => null,
                    'output_pcs' => null,
                    'hasil' => null,
                    'rusak' => null,
                    'output' => null,
                    'is_done' => false,
                ];
            }
        }

        // Determine active WIP stage and active WIP quantity
        $wipStage = 'netto';
        $wipPcs = $rootGood;

        if ($highestCompletedStage === null) {
            $wipStage = 'netto';
            $wipPcs = $rootGood;
        } elseif ($highestCompletedStage === 'netto') {
            $wipStage = 'bubut_od';
            $wipPcs = (int) $stageData['netto']['output_pcs'];
        } elseif ($highestCompletedStage === 'bubut_od') {
            $wipStage = 'bubut_cnc';
            $wipPcs = (int) $stageData['bubut_od']['output_pcs'];
        } elseif ($highestCompletedStage === 'bubut_cnc') {
            $wipStage = 'bor';
            $wipPcs = (int) $stageData['bubut_cnc']['output_pcs'];
        } elseif ($highestCompletedStage === 'bor') {
            $wipStage = 'qc';
            $wipPcs = (int) $stageData['bor']['output_pcs'];
        } elseif ($highestCompletedStage === 'qc') {
            $wipStage = 'qc'; // pending gudang jadi receive
            $wipPcs = (int) $stageData['qc']['output_pcs'];
        } elseif ($highestCompletedStage === 'gudang_jadi') {
            $wipStage = 'completed';
            $wipPcs = 0;
        }

        // Determine if line is WIP at cutoff
        $isGudangJadiDone = ($stageData['gudang_jadi']['is_done'] === true);
        $isWip = ! $isGudangJadiDone && ($wipPcs > 0);

        $wipKg = round($wipPcs * $unitWeight, 2);

        $plan = $line->productionPlan ?? $line->castingOrderLine?->productionPlan;
        $result = $line->castingResult;
        $order = $line->castingOrderLine;

        // Resolve PO Quantity from ProductionPlan (or CastingOrderLine)
        $poQty = null;
        if ($plan && $plan->po_quantity !== null && (int) $plan->po_quantity > 0) {
            $poQty = (int) $plan->po_quantity;
        } elseif ($order && $order->qty_ordered !== null && (int) $order->qty_ordered > 0) {
            $poQty = (int) $order->qty_ordered;
        }

        // Plan = PO * 1.10 (mathematical rounding to nearest integer). Empty if PO is empty.
        $planQty = $poQty !== null ? (int) round($poQty * 1.10) : null;

        $rowData = [
            'id' => $line->id,
            'traveler_number' => (string) $line->traveler_number,
            'code' => (string) ($plan?->code ?? $order?->code ?? '-'),
            'heat_number' => (string) ($result?->heat_number ?? '-'),
            'cast_date' => $result?->cast_date ? Carbon::parse($result->cast_date)->format('d/m/Y') : '-',
            'item_name' => (string) ($plan?->item_name ?? $order?->item_name ?? '-'),
            'aisi' => (string) ($plan?->aisi ?? '-'),
            'size' => (string) ($plan?->size ?? $order?->size ?? '-'),
            'customer' => (string) ($plan?->customer ?? $order?->customer ?? '-'),
            'po_qty' => $poQty !== null ? $poQty : '',
            'po_number' => $poQty !== null ? $poQty : '',
            'qty_planned' => $planQty !== null ? $planQty : '',
            'unit_weight_kg' => $unitWeight,
            'wip_stage' => $wipStage,
            'wip_stage_label' => self::STAGES[$wipStage] ?? strtoupper($wipStage),
            'wip_pcs' => $wipPcs,
            'wip_kg' => $wipKg,
            'is_wip' => $isWip,
            'stages' => $stageData,
        ];

        return [
            'is_wip' => $isWip,
            'wip_stage' => $wipStage,
            'wip_pcs' => $wipPcs,
            'wip_kg' => $wipKg,
            'stages' => $stageData,
            'row_data' => $rowData,
        ];
    }

    /**
     * Build Single-Sheet Excel Spreadsheet (Pergerakan Produksi).
     */
    public function buildExcelSpreadsheet(array $reportData): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pergerakan Produksi');

        $meta = $reportData['meta'];
        $format = $meta['report_format'] ?? 'detail';

        if ($format === 'summary' || $format === 'ringkas') {
            $this->populateMovementSummarySheet($sheet, 'HORIZONTAL PRODUCTION TRACKING — RINGKAS PER KODE PRODUKSI', $reportData['pergerakan'], $meta);
        } else {
            $this->populateMovementSheet($sheet, 'HORIZONTAL PRODUCTION TRACKING', $reportData['pergerakan'], $meta);
        }

        return $spreadsheet;
    }

    /**
     * Populate Summary Sheet (Placed at the end of Workbook).
     */
    protected function populateSummarySheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $summary, array $meta): void
    {
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);

        $sheet->setCellValue('A1', 'LAPORAN ACCOUNTING SAND CASTING — REKONSILIASI SUMMARY');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('A2', 'Periode:');
        $sheet->setCellValue('B2', "{$meta['date_from']} s/d {$meta['date_to']}");
        $sheet->setCellValue('D2', 'Tanggal Cetak:');
        $sheet->setCellValue('E2', $meta['generated_at']);

        $sheet->setCellValue('A3', 'Product Scope:');
        $sheet->setCellValue('B3', $meta['product_scope']);
        $sheet->setCellValue('D3', 'Operator/User:');
        $sheet->setCellValue('E3', $meta['generated_by']);

        $sheet->getStyle('A2:A3')->getFont()->setBold(true);
        $sheet->getStyle('D2:D3')->getFont()->setBold(true);

        // Summary Table Header
        $headers1 = ['No', 'Komponen Rekonsiliasi', 'Rumus Akuntansi', 'Kuantitas (PCS)', 'Total Berat (KG)'];
        $sheet->fromArray($headers1, null, 'A5');
        $this->applyHeaderStyle($sheet, 'A5:E5');

        $rows1 = [
            ['1', 'Saldo Awal WIP', "Posisi WIP per akhir hari sebelum {$meta['date_from']}", (int) $summary['saldo_awal_pcs'], (float) $summary['saldo_awal_kg']],
            ['2', 'Inflow: Hasil Cor Baru', "KTR Cor baru yang terbit dalam periode {$meta['date_from']} s/d {$meta['date_to']}", (int) $summary['inflow_cor_pcs'], (float) $summary['inflow_cor_kg']],
            ['3', 'Defect: Kerusakan Proses', 'Akumulasi cacat Netto s/d QC yang dicatat dalam periode', -1 * abs((int) $summary['defect_proses_pcs']), -1 * abs((float) $summary['defect_proses_kg'])],
            ['4', 'Outflow: Selesai Gudang Jadi', 'KTR yang menyelesaikan GUDANG_RECEIVE dalam periode', -1 * abs((int) $summary['outflow_gudang_jadi_pcs']), -1 * abs((float) $summary['outflow_gudang_jadi_kg'])],
            ['5', 'Saldo Akhir WIP (Hasil Rekonsiliasi)', 'Baris 1 + Baris 2 - Baris 3 - Baris 4', (int) $summary['calculated_saldo_akhir_pcs'], (float) $summary['calculated_saldo_akhir_kg']],
            ['6', 'Saldo Akhir WIP (Fisik Detail Sheet 3)', "Posisi fisik WIP per akhir hari {$meta['date_to']}", (int) $summary['saldo_akhir_pcs'], (float) $summary['saldo_akhir_kg']],
            ['7', 'Selisih Rekonsiliasi (Discrepancy)', 'Baris 6 - Baris 5 (Wajib 0)', (int) $summary['discrepancy_pcs'], (float) $summary['discrepancy_kg']],
        ];

        $rIdx = 6;
        foreach ($rows1 as $r) {
            $sheet->fromArray($r, null, "A{$rIdx}");
            if ($rIdx === 10 || $rIdx === 11 || $rIdx === 12) {
                $sheet->getStyle("A{$rIdx}:E{$rIdx}")->getFont()->setBold(true);
            }
            if ($rIdx === 12) {
                $fillColor = ((int) $summary['discrepancy_pcs'] === 0) ? 'ECFDF5' : 'FEF2F2';
                $sheet->getStyle("A{$rIdx}:E{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fillColor);
            }
            $rIdx++;
        }
        $sheet->getStyle('A5:E12')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');

        // Department Distribution Table
        $sheet->setCellValue('A15', 'SEBARAN SALDO AKHIR WIP BERDASARKAN DEPARTEMEN');
        $sheet->getStyle('A15')->getFont()->setBold(true)->setSize(11);

        $headersDept = ['No', 'Departemen / Posisi Fisik', 'Kuantitas (PCS)', 'Total Berat (KG)', 'Persentase (%)'];
        $sheet->fromArray($headersDept, null, 'A16');
        $this->applyHeaderStyle($sheet, 'A16:E16');

        $dIdx = 17;
        $dNo = 1;
        $deptKeys = ['netto' => 'Netto', 'bubut_od' => 'Bubut OD', 'bubut_cnc' => 'Bubut CNC', 'bor' => 'Bor', 'qc' => 'QC'];
        foreach ($deptKeys as $dk => $dLabel) {
            $dPcs = (int) ($summary['dept_distribution'][$dk]['pcs'] ?? 0);
            $dKg = (float) ($summary['dept_distribution'][$dk]['kg'] ?? 0.0);
            $pct = $summary['saldo_akhir_pcs'] > 0 ? round(($dPcs / $summary['saldo_akhir_pcs']) * 100, 1) : 0.0;

            $sheet->setCellValue("A{$dIdx}", $dNo++);
            $sheet->setCellValue("B{$dIdx}", $dLabel);
            $sheet->setCellValue("C{$dIdx}", $dPcs);
            $sheet->setCellValue("D{$dIdx}", $dKg);
            $sheet->setCellValue("E{$dIdx}", "{$pct}%");
            $dIdx++;
        }
        $sheet->setCellValue("A{$dIdx}", 'TOTAL');
        $sheet->setCellValue("B{$dIdx}", 'Total Saldo Akhir WIP');
        $sheet->setCellValue("C{$dIdx}", (int) $summary['saldo_akhir_pcs']);
        $sheet->setCellValue("D{$dIdx}", (float) $summary['saldo_akhir_kg']);
        $sheet->setCellValue("E{$dIdx}", '100%');
        $sheet->getStyle("A{$dIdx}:E{$dIdx}")->getFont()->setBold(true);
        $sheet->getStyle("A16:E{$dIdx}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');

        $this->autoFitColumns($sheet, 'A', 'E');
    }

    /**
     * Populate WIP Detail Sheet (Sheet 2 Saldo Awal & Sheet 3 Saldo Akhir).
     */
    protected function populateWipSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $title, array $rows, array $meta): void
    {
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);

        $sheet->setCellValue('A1', "LAPORAN ACCOUNTING SAND CASTING — {$title}");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $sheet->setCellValue('A2', 'Periode:');
        $sheet->setCellValue('B2', "{$meta['date_from']} s/d {$meta['date_to']}");
        $sheet->setCellValue('D2', 'Total Baris:');
        $sheet->setCellValue('E2', count($rows).' Baris');

        // Headers without No KTR
        $headers = [
            'No', 'Kode Produksi', 'Heat Number', 'Tgl Cor',
            'Nama Item', 'AISI', 'Ukuran', 'Customer', 'Weight (KG/PCS)',
            'Posisi WIP', 'Sisa WIP (PCS)', 'Total Berat (KG)',
        ];
        $sheet->fromArray($headers, null, 'A4');
        $this->applyHeaderStyle($sheet, 'A4:L4');

        $rIdx = 5;
        $totalPcs = 0;
        $totalKg = 0.0;

        foreach ($rows as $idx => $r) {
            $sheet->setCellValue("A{$rIdx}", $idx + 1);
            $sheet->setCellValue("B{$rIdx}", $r['code']);
            $sheet->getStyle("B{$rIdx}")->getFont()->setBold(true);
            $sheet->setCellValue("C{$rIdx}", $r['heat_number']);
            $sheet->setCellValue("D{$rIdx}", $r['cast_date']);
            $sheet->setCellValue("E{$rIdx}", $r['item_name']);
            $sheet->setCellValue("F{$rIdx}", $r['aisi']);
            $sheet->setCellValue("G{$rIdx}", $r['size'] ?? '-');
            $sheet->setCellValue("H{$rIdx}", $r['customer']);
            $sheet->setCellValue("I{$rIdx}", (float) $r['unit_weight_kg']);
            $sheet->setCellValue("J{$rIdx}", $r['wip_stage_label']);
            $sheet->setCellValue("K{$rIdx}", (int) $r['wip_pcs']);
            $sheet->setCellValue("L{$rIdx}", (float) $r['wip_kg']);

            $totalPcs += (int) $r['wip_pcs'];
            $totalKg += (float) $r['wip_kg'];

            $sheet->getStyle("I{$rIdx}")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("K{$rIdx}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("L{$rIdx}")->getNumberFormat()->setFormatCode('#,##0.00');

            if ($rIdx % 2 === 0) {
                $sheet->getStyle("A{$rIdx}:L{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');
            }
            $rIdx++;
        }

        // Total Row
        $sheet->setCellValue("A{$rIdx}", 'TOTAL');
        $sheet->setCellValue("J{$rIdx}", 'TOTAL WIP');
        $sheet->setCellValue("K{$rIdx}", $totalPcs);
        $sheet->setCellValue("L{$rIdx}", round($totalKg, 2));
        $sheet->getStyle("A{$rIdx}:L{$rIdx}")->getFont()->setBold(true);
        $sheet->getStyle("K{$rIdx}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("L{$rIdx}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("A4:L{$rIdx}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');

        $sheet->freezePane('A5');
        $sheet->setAutoFilter('A4:L4');
        $this->autoFitColumns($sheet, 'A', 'L');
    }

    /**
     * Populate Horizontal Movement Sheet (Sheet 1 Pergerakan Produksi).
     */
    protected function populateMovementSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $title, array $rows, array $meta): void
    {
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);

        $sheet->setCellValue('A1', "LAPORAN ACCOUNTING SAND CASTING — {$title}");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $sheet->setCellValue('A2', 'Periode:');
        $sheet->setCellValue('B2', "{$meta['date_from']} s/d {$meta['date_to']}");
        $sheet->setCellValue('D2', 'Total Baris:');
        $sheet->setCellValue('E2', count($rows).' Kombinasi Kode + Heat');

        // Merged Header Rows: Row 4 (Parent) & Row 5 (Sub)
        // 8 Identity Columns (A through H)
        $sheet->setCellValue('A4', 'No');
        $sheet->mergeCells('A4:A5');
        $sheet->setCellValue('B4', 'Kode Produksi');
        $sheet->mergeCells('B4:B5');
        $sheet->setCellValue('C4', 'Heat Number');
        $sheet->mergeCells('C4:C5');
        $sheet->setCellValue('D4', 'Nama Item');
        $sheet->mergeCells('D4:D5');
        $sheet->setCellValue('E4', 'AISI');
        $sheet->mergeCells('E4:E5');
        $sheet->setCellValue('F4', 'Customer');
        $sheet->mergeCells('F4:F5');
        $sheet->setCellValue('G4', 'Weight (KG/PCS)');
        $sheet->mergeCells('G4:G5');
        $sheet->setCellValue('H4', 'Total Weight (KG)');
        $sheet->mergeCells('H4:H5');

        $this->applyHeaderStyle($sheet, 'A4:H5');

        // 7 Department Groups (14 Columns: I through V)
        $stageCols = [
            'COR' => ['I', 'J'],
            'NETTO' => ['K', 'L'],
            'BUBUT OD' => ['M', 'N'],
            'BUBUT CNC' => ['O', 'P'],
            'BOR' => ['Q', 'R'],
            'QC' => ['S', 'T'],
            'GUDANG JADI' => ['U', 'V'],
        ];

        foreach ($stageCols as $sLabel => $cols) {
            $sheet->setCellValue("{$cols[0]}4", $sLabel);
            $sheet->mergeCells("{$cols[0]}4:{$cols[1]}4");
            $sheet->setCellValue("{$cols[0]}5", 'Hasil');
            $sheet->setCellValue("{$cols[1]}5", 'Rusak');
        }

        // Apply light blue header styling for department headers (I4:V4)
        $deptHeaderStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0369A1']], // sky-700
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '334155']]],
        ];
        $sheet->getStyle('I4:V4')->applyFromArray($deptHeaderStyle);

        // Apply subheader styling (I5:V5)
        $subHeaderStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 8.5],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']], // slate-800
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '334155']]],
        ];
        $sheet->getStyle('I5:V5')->applyFromArray($subHeaderStyle);

        $rIdx = 6;
        $orderedStages = ['cor', 'netto', 'bubut_od', 'bubut_cnc', 'bor', 'qc', 'gudang_jadi'];

        foreach ($rows as $idx => $r) {
            $sheet->setCellValue("A{$rIdx}", $idx + 1);
            $sheet->setCellValue("B{$rIdx}", $r['code']);
            $sheet->getStyle("B{$rIdx}")->getFont()->setBold(true);
            $sheet->setCellValue("C{$rIdx}", $r['heat_number']);
            $sheet->setCellValue("D{$rIdx}", $r['item_name']);
            $sheet->setCellValue("E{$rIdx}", $r['aisi']);
            $sheet->setCellValue("F{$rIdx}", $r['customer']);
            $sheet->setCellValue("G{$rIdx}", (float) $r['unit_weight_kg']);
            $sheet->setCellValue("H{$rIdx}", (float) $r['total_weight_kg']);

            $sheet->getStyle("G{$rIdx}")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("H{$rIdx}")->getNumberFormat()->setFormatCode('#,##0.00');

            $colPointer = 8; // Column I is index 8 (0-based)
            foreach ($orderedStages as $stgKey) {
                $stgInfo = $r['stages'][$stgKey] ?? null;
                $hasilVal = ($stgInfo && $stgInfo['hasil_pcs'] !== null) ? (int) $stgInfo['hasil_pcs'] : '—';
                $rusakVal = ($stgInfo && $stgInfo['rusak_pcs'] !== null) ? (int) $stgInfo['rusak_pcs'] : '—';

                $c1 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colPointer + 1);
                $c2 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colPointer + 2);

                $sheet->setCellValue("{$c1}{$rIdx}", $hasilVal);
                $sheet->setCellValue("{$c2}{$rIdx}", $rusakVal);

                if (is_numeric($hasilVal)) {
                    $sheet->getStyle("{$c1}{$rIdx}")->getNumberFormat()->setFormatCode('#,##0');
                } else {
                    $sheet->getStyle("{$c1}{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                if (is_numeric($rusakVal)) {
                    $sheet->getStyle("{$c2}{$rIdx}")->getNumberFormat()->setFormatCode('#,##0');
                    if ((int) $rusakVal > 0) {
                        $sheet->getStyle("{$c2}{$rIdx}")->getFont()->getColor()->setRGB('E11D48'); // rose-600
                        $sheet->getStyle("{$c2}{$rIdx}")->getFont()->setBold(true);
                    }
                } else {
                    $sheet->getStyle("{$c2}{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                $colPointer += 2;
            }

            if ($rIdx % 2 === 0) {
                $sheet->getStyle("A{$rIdx}:V{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');
            }
            $rIdx++;
        }

        $sheet->getStyle('A4:V'.($rIdx - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');

        // Text wrap and center alignments for data cells
        if ($rIdx > 6) {
            $sheet->getStyle('A6:A'.($rIdx - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('D6:D'.($rIdx - 1))->getAlignment()->setWrapText(true);
            $sheet->getStyle('E6:E'.($rIdx - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        // Freeze pane on Column I (so columns A to H stay visible while scrolling horizontally)
        $sheet->freezePane('I6');
        $sheet->setAutoFilter('A5:V5');

        // Proportional column dimensions (prevent unconstrained autosize blowups)
        $columnWidths = [
            'A' => 6,
            'B' => 15,
            'C' => 15,
            'D' => 32,
            'E' => 8,
            'F' => 18,
            'G' => 14,
            'H' => 16,
        ];
        foreach ($columnWidths as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        for ($i = 9; $i <= 22; $i++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($colLetter)->setWidth(8.5);
        }
    }

    /**
     * Populate Horizontal Movement Sheet for Summary Format (23 Columns).
     */
    protected function populateMovementSummarySheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $title, array $rows, array $meta): void
    {
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);

        $sheet->setCellValue('A1', "LAPORAN ACCOUNTING SAND CASTING — {$title}");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $sheet->setCellValue('A2', 'Periode:');
        $sheet->setCellValue('B2', "{$meta['date_from']} s/d {$meta['date_to']}");
        $sheet->setCellValue('D2', 'Total Baris:');
        $sheet->setCellValue('E2', count($rows).' Kode Produksi');

        // Merged Header Rows: Row 4 (Parent) & Row 5 (Sub)
        // 9 Identity Columns (A through I)
        $sheet->setCellValue('A4', 'No');
        $sheet->mergeCells('A4:A5');
        $sheet->setCellValue('B4', 'Kode Produksi');
        $sheet->mergeCells('B4:B5');
        $sheet->setCellValue('C4', 'Nama Item');
        $sheet->mergeCells('C4:C5');
        $sheet->setCellValue('D4', 'AISI');
        $sheet->mergeCells('D4:D5');
        $sheet->setCellValue('E4', 'Customer');
        $sheet->mergeCells('E4:E5');
        $sheet->setCellValue('F4', 'P.O (PCS)');
        $sheet->mergeCells('F4:F5');
        $sheet->setCellValue('G4', 'Plan (PCS)');
        $sheet->mergeCells('G4:G5');
        $sheet->setCellValue('H4', 'Weight (KG/PCS)');
        $sheet->mergeCells('H4:H5');
        $sheet->setCellValue('I4', 'Total Weight (KG)');
        $sheet->mergeCells('I4:I5');

        $this->applyHeaderStyle($sheet, 'A4:I5');

        // 7 Department Groups (14 Columns: J through W)
        $stageCols = [
            'COR' => ['J', 'K'],
            'NETTO' => ['L', 'M'],
            'BUBUT OD' => ['N', 'O'],
            'BUBUT CNC' => ['P', 'Q'],
            'BOR' => ['R', 'S'],
            'QC' => ['T', 'U'],
            'GUDANG JADI' => ['V', 'W'],
        ];

        foreach ($stageCols as $sLabel => $cols) {
            $sheet->setCellValue("{$cols[0]}4", $sLabel);
            $sheet->mergeCells("{$cols[0]}4:{$cols[1]}4");
            $sheet->setCellValue("{$cols[0]}5", 'Hasil');
            $sheet->setCellValue("{$cols[1]}5", 'Rusak');
        }

        // Apply light blue header styling for department headers (J4:W4)
        $deptHeaderStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0369A1']], // sky-700
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '334155']]],
        ];
        $sheet->getStyle('J4:W4')->applyFromArray($deptHeaderStyle);

        // Apply subheader styling (J5:W5)
        $subHeaderStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 8.5],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']], // slate-800
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '334155']]],
        ];
        $sheet->getStyle('J5:W5')->applyFromArray($subHeaderStyle);

        $rIdx = 6;
        $orderedStages = ['cor', 'netto', 'bubut_od', 'bubut_cnc', 'bor', 'qc', 'gudang_jadi'];

        foreach ($rows as $idx => $r) {
            $sheet->setCellValue("A{$rIdx}", $idx + 1);
            $sheet->setCellValue("B{$rIdx}", $r['code']);
            $sheet->getStyle("B{$rIdx}")->getFont()->setBold(true);
            $sheet->setCellValue("C{$rIdx}", $r['item_name']);
            $sheet->setCellValue("D{$rIdx}", $r['aisi']);
            $sheet->setCellValue("E{$rIdx}", $r['customer']);
            $poVal = $r['po_qty'] ?? ($r['po_number'] ?? '');
            $sheet->setCellValue("F{$rIdx}", $poVal);
            if (is_numeric($poVal)) {
                $sheet->getStyle("F{$rIdx}")->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle("F{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            } else {
                $sheet->getStyle("F{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }

            $planVal = $r['qty_planned'] ?? '';
            $sheet->setCellValue("G{$rIdx}", $planVal);
            if (is_numeric($planVal)) {
                $sheet->getStyle("G{$rIdx}")->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle("G{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            } else {
                $sheet->getStyle("G{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }

            $weightLabel = $r['unit_weight_label'] ?? (isset($r['unit_weight_kg']) && (float) $r['unit_weight_kg'] > 0 ? (float) $r['unit_weight_kg'] : '—');
            if (is_numeric($weightLabel)) {
                $sheet->setCellValue("H{$rIdx}", (float) $weightLabel);
                $sheet->getStyle("H{$rIdx}")->getNumberFormat()->setFormatCode('#,##0.00');
            } else {
                $sheet->setCellValue("H{$rIdx}", $weightLabel);
                $sheet->getStyle("H{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                if ($weightLabel === 'Bervariasi') {
                    $sheet->getStyle("H{$rIdx}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('D97706')); // amber-600
                    $sheet->getStyle("H{$rIdx}")->getFont()->setBold(true);
                }
            }

            $sheet->setCellValue("I{$rIdx}", (float) $r['total_weight_kg']);
            $sheet->getStyle("I{$rIdx}")->getNumberFormat()->setFormatCode('#,##0.00');

            $colPointer = 9; // Column J is index 9 (0-based)
            foreach ($orderedStages as $stgKey) {
                $stgInfo = $r['stages'][$stgKey] ?? null;
                $hasilVal = ($stgInfo && $stgInfo['hasil_pcs'] !== null) ? (int) $stgInfo['hasil_pcs'] : '—';
                $rusakVal = ($stgInfo && $stgInfo['rusak_pcs'] !== null) ? (int) $stgInfo['rusak_pcs'] : '—';

                $c1 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colPointer + 1);
                $c2 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colPointer + 2);

                $sheet->setCellValue("{$c1}{$rIdx}", $hasilVal);
                $sheet->setCellValue("{$c2}{$rIdx}", $rusakVal);

                if (is_numeric($hasilVal)) {
                    $sheet->getStyle("{$c1}{$rIdx}")->getNumberFormat()->setFormatCode('#,##0');
                } else {
                    $sheet->getStyle("{$c1}{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                if (is_numeric($rusakVal)) {
                    $sheet->getStyle("{$c2}{$rIdx}")->getNumberFormat()->setFormatCode('#,##0');
                    if ((int) $rusakVal > 0) {
                        $sheet->getStyle("{$c2}{$rIdx}")->getFont()->getColor()->setRGB('E11D48'); // rose-600
                        $sheet->getStyle("{$c2}{$rIdx}")->getFont()->setBold(true);
                    }
                } else {
                    $sheet->getStyle("{$c2}{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                $colPointer += 2;
            }

            if ($rIdx % 2 === 0) {
                $sheet->getStyle("A{$rIdx}:W{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');
            }
            $rIdx++;
        }

        $sheet->getStyle('A4:W'.($rIdx - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');

        // Text wrap and center alignments for data cells
        if ($rIdx > 6) {
            $sheet->getStyle('A6:A'.($rIdx - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('C6:C'.($rIdx - 1))->getAlignment()->setWrapText(true);
            $sheet->getStyle('D6:D'.($rIdx - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        // Freeze pane on Column J (so columns A to I stay visible while scrolling horizontally)
        $sheet->freezePane('J6');
        $sheet->setAutoFilter('A5:W5');

        // Proportional column dimensions (prevent unconstrained autosize blowups)
        $columnWidths = [
            'A' => 6,
            'B' => 15,
            'C' => 32,
            'D' => 8,
            'E' => 18,
            'F' => 12,
            'G' => 12,
            'H' => 14,
            'I' => 16,
        ];
        foreach ($columnWidths as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        for ($i = 10; $i <= 23; $i++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($colLetter)->setWidth(8.5);
        }
    }

    protected function applyHeaderStyle(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $cellCoordinateRange): void
    {
        $style = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 9,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '0F172A'], // slate-900
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '334155'],
                ],
            ],
        ];

        $sheet->getStyle($cellCoordinateRange)->applyFromArray($style);
    }

    protected function autoFitColumns(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $fromCol, string $toCol): void
    {
        $fromIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($fromCol);
        $toIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($toCol);

        for ($i = $fromIdx; $i <= $toIdx; $i++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }
    }
}
