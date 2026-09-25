<?php

namespace App\Services\SandCasting;

use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use Illuminate\Support\Collection;

class SandCastingProductionReportService
{
    /**
     * Canonical 7 stages of Sand Casting production in strict pipeline order.
     */
    public const STAGES = [
        'cor' => 'Hasil Cor',
        'netto' => 'Netto',
        'bubut_od' => 'Bubut OD',
        'bubut_cnc' => 'Bubut CNC',
        'bor' => 'Bor',
        'qc' => 'QC',
        'gudang_jadi' => 'Gudang Jadi',
    ];

    /**
     * Stage ordering for consistent sorting.
     */
    public static function getStageOrder(string $stage): int
    {
        $order = [
            'cor' => 1,
            'netto' => 2,
            'bubut_od' => 3,
            'bubut_cnc' => 4,
            'bor' => 5,
            'qc' => 6,
            'gudang_jadi' => 7,
        ];

        return $order[$stage] ?? 99;
    }

    /**
     * Get the canonical normalized production report dataset.
     *
     * @param  array{date_from?: string, date_to?: string, stage?: string, search?: string}  $filters
     * @return array{
     *     stage_summaries: Collection,
     *     items: Collection,
     *     summary: array{total_ktr_activities: int, total_qty_output: int, total_defect: int, total_weight: float, total_records: int},
     *     filters: array{date_from: string, date_to: string, stage: string, search: string},
     *     stages: array<string, string>
     * }
     */
    public function getProductionDataset(array $filters = [], ?User $user = null, bool $includeDetails = true): array
    {
        $currentUser = $user ?? auth()->user();
        $scope = $currentUser?->product_scope;

        $dateFrom = ! empty($filters['date_from']) ? $filters['date_from'] : date('Y-m-d');
        $dateTo = ! empty($filters['date_to']) ? $filters['date_to'] : date('Y-m-d');
        $selectedStage = ! empty($filters['stage']) ? $filters['stage'] : 'all';
        $search = trim((string) ($filters['search'] ?? ''));
        $searchLower = strtolower($search);

        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $detailItems = collect();

        // -------------------------------------------------------------
        // 1. DATA SOURCE: HASIL COR (Casting Results & Lines)
        // -------------------------------------------------------------
        $corLines = collect();
        if ($selectedStage === 'all' || $selectedStage === 'cor') {
            $eagerCor = $includeDetails
                ? ['castingResult.recorder', 'productionPlan', 'castingOrderLine']
                : ['castingResult', 'productionPlan'];

            $corQuery = SandCastingCastingResultLine::with($eagerCor)
                ->whereHas('castingResult', function ($q) use ($dateFrom, $dateTo) {
                    $q->whereDate('cast_date', '>=', $dateFrom)
                        ->whereDate('cast_date', '<=', $dateTo);
                });

            if ($scope) {
                $corQuery->whereHas('productionPlan', function ($p) use ($scope) {
                    $p->where('product_scope', $scope);
                });
            }

            $corLines = $corQuery->get();

            if ($includeDetails) {
                foreach ($corLines as $line) {
                    $plan = $line->productionPlan;
                    $result = $line->castingResult;
                    $prodCode = $plan?->code ?? '-';
                    $customer = $plan?->customer ?? '-';
                    $itemName = $plan?->item_name ?? '-';
                    $heatNumber = $result?->heat_number ?? '-';
                    $weight = (float) ($line->unit_weight_kg ?? $plan?->weight ?? 0.0);
                    $goodQty = (int) $line->qty_good;
                    $defectQty = (int) $line->qty_reject;
                    $inputQty = $goodQty + $defectQty;
                    $totalWeight = round($goodQty * $weight, 2);
                    $doneDate = $result?->cast_date ? $result->cast_date->format('Y-m-d') : ($line->created_at ? $line->created_at->format('Y-m-d') : '-');
                    $operator = $result?->operator_name ?: ($result?->recorder?->name ?: '-');

                    $detailItems->push([
                        'ktr' => (string) $line->traveler_number,
                        'production_code' => (string) $prodCode,
                        'customer' => (string) $customer,
                        'item_name' => (string) $itemName,
                        'heat_number' => (string) $heatNumber,
                        'stage' => 'cor',
                        'stage_label' => 'Hasil Cor',
                        'stage_order' => self::getStageOrder('cor'),
                        'checkpoint_code' => 'COR_POURING',
                        'input_qty' => $inputQty,
                        'defect_qty' => $defectQty,
                        'good_qty' => $goodQty,
                        'weight' => $weight,
                        'total_weight' => $totalWeight,
                        'physical_done_at' => $doneDate,
                        'operator' => (string) $operator,
                    ]);
                }
            }
        }

        // -------------------------------------------------------------
        // 2. DATA SOURCE: STAGE EXECUTIONS (Netto s/d Gudang Jadi)
        // -------------------------------------------------------------
        $execStages = [];
        if ($selectedStage === 'all') {
            $execStages = ['netto', 'bubut_od', 'bubut_cnc', 'bor', 'qc', 'gudang_jadi'];
        } elseif (in_array($selectedStage, ['netto', 'bubut_od', 'bubut_cnc', 'bor', 'qc', 'gudang_jadi'], true)) {
            $execStages = [$selectedStage];
        }

        $executions = collect();
        if (! empty($execStages)) {
            $eagerExec = $includeDetails
                ? ['castingResultLine.castingResult', 'castingResultLine.productionPlan', 'castingResultLine.castingOrderLine', 'operator', 'defects.defectType']
                : ['castingResultLine.productionPlan'];

            $execQuery = SandCastingStageExecution::with($eagerExec)
                ->whereIn('stage', $execStages)
                ->whereNotNull('physical_done_at')
                ->whereDate('physical_done_at', '>=', $dateFrom)
                ->whereDate('physical_done_at', '<=', $dateTo);

            if ($scope) {
                $execQuery->whereHas('castingResultLine.productionPlan', function ($p) use ($scope) {
                    $p->where('product_scope', $scope);
                });
            }

            $executions = $execQuery->orderBy('physical_done_at', 'asc')->get();

            if ($includeDetails) {
                foreach ($executions as $exec) {
                    $line = $exec->castingResultLine;
                    $plan = $line?->productionPlan;
                    $result = $line?->castingResult;
                    $prodCode = $plan?->code ?? '-';
                    $customer = $plan?->customer ?? '-';
                    $itemName = $plan?->item_name ?? '-';
                    $heatNumber = $result?->heat_number ?? '-';
                    $weight = (float) ($line?->unit_weight_kg ?? $plan?->weight ?? 0.0);
                    $goodQty = (int) $exec->good_qty;
                    $defectQty = (int) $exec->defect_qty;
                    $inputQty = (int) $exec->input_qty;
                    $totalWeight = round($goodQty * $weight, 2);
                    $doneDate = $exec->physical_done_at ? $exec->physical_done_at->format('Y-m-d H:i') : '-';
                    $operator = $exec->operator?->name ?: '-';

                    $stageLabel = self::STAGES[$exec->stage] ?? ucfirst(str_replace('_', ' ', $exec->stage));

                    $detailItems->push([
                        'ktr' => (string) ($line?->traveler_number ?? '-'),
                        'production_code' => (string) $prodCode,
                        'customer' => (string) $customer,
                        'item_name' => (string) $itemName,
                        'heat_number' => (string) $heatNumber,
                        'stage' => (string) $exec->stage,
                        'stage_label' => (string) $stageLabel,
                        'stage_order' => self::getStageOrder((string) $exec->stage),
                        'checkpoint_code' => (string) $exec->checkpoint_code,
                        'input_qty' => $inputQty,
                        'defect_qty' => $defectQty,
                        'good_qty' => $goodQty,
                        'weight' => $weight,
                        'total_weight' => $totalWeight,
                        'physical_done_at' => $doneDate,
                        'operator' => (string) $operator,
                    ]);
                }
            }
        }

        // -------------------------------------------------------------
        // 3. STAGE SUMMARY AGGREGATION (Strict 7 Rows & Anti Double-Count)
        // -------------------------------------------------------------
        $stageSummaries = collect();
        $allStageKeys = array_keys(self::STAGES);

        foreach ($allStageKeys as $stgKey) {
            if ($selectedStage !== 'all' && $selectedStage !== $stgKey) {
                continue;
            }

            $stgLabel = self::STAGES[$stgKey];
            $ktrCount = 0;
            $inputPcs = 0;
            $defectPcs = 0;
            $goodPcs = 0;
            $weightKg = 0.0;

            if ($stgKey === 'cor') {
                // 1. HASIL COR
                $ktrCount = $corLines->count();
                $goodPcs = (int) $corLines->sum('qty_good');
                $defectPcs = (int) $corLines->sum('qty_reject');
                $inputPcs = $goodPcs + $defectPcs;
                $weightKg = (float) $corLines->sum(function ($l) {
                    $w = (float) ($l->unit_weight_kg ?? $l->productionPlan?->weight ?? 0.0);

                    return (int) $l->qty_good * $w;
                });
            } elseif ($stgKey === 'bubut_cnc') {
                // 4. BUBUT CNC (Special Anti-Double-Count Handling for 3 Checkpoints)
                $cncExecs = $executions->where('stage', 'bubut_cnc');
                $groupedByLine = $cncExecs->groupBy('sand_casting_casting_result_line_id');
                $ktrCount = $groupedByLine->count();

                foreach ($groupedByLine as $lineId => $lineExecs) {
                    // Checkpoint order: CNC_MACHINING -> QC_POST_CNC -> QC_PRE_BOR
                    $sorted = $lineExecs->sortBy('physical_done_at');
                    $firstExec = $sorted->first();
                    $initialInput = (int) ($firstExec?->input_qty ?? 0);
                    $totalDefectForLine = (int) $lineExecs->sum('defect_qty');
                    $finalGoodForLine = max(0, $initialInput - $totalDefectForLine);

                    $lineModel = $firstExec?->castingResultLine;
                    $w = (float) ($lineModel?->unit_weight_kg ?? $lineModel?->productionPlan?->weight ?? 0.0);

                    $inputPcs += $initialInput;
                    $defectPcs += $totalDefectForLine;
                    $goodPcs += $finalGoodForLine;
                    $weightKg += ($finalGoodForLine * $w);
                }
            } else {
                // 2, 3, 5, 6, 7 (Netto, Bubut OD, Bor, QC, Gudang Jadi)
                $stgExecs = $executions->where('stage', $stgKey);
                $ktrCount = $stgExecs->pluck('sand_casting_casting_result_line_id')->unique()->count();
                $inputPcs = (int) $stgExecs->sum('input_qty');
                $defectPcs = (int) $stgExecs->sum('defect_qty');
                $goodPcs = (int) $stgExecs->sum('good_qty');
                $weightKg = (float) $stgExecs->sum(function ($e) {
                    $w = (float) ($e->castingResultLine?->unit_weight_kg ?? $e->castingResultLine?->productionPlan?->weight ?? 0.0);

                    return (int) $e->good_qty * $w;
                });
            }

            $defectRate = $inputPcs > 0 ? round(($defectPcs / $inputPcs) * 100, 2) : 0.0;

            $stageSummaries->push([
                'stage' => $stgKey,
                'stage_label' => $stgLabel,
                'stage_order' => self::getStageOrder($stgKey),
                'ktr_count' => $ktrCount,
                'input_pcs' => $inputPcs,
                'defect_pcs' => $defectPcs,
                'good_pcs' => $goodPcs,
                'weight_kg' => round($weightKg, 2),
                'defect_rate' => $defectRate,
            ]);
        }

        // -------------------------------------------------------------
        // 4. SEARCH FILTER (Applied on Detail Items if included)
        // -------------------------------------------------------------
        $sortedItems = collect();
        if ($includeDetails) {
            if ($searchLower !== '') {
                $detailItems = $detailItems->filter(function ($item) use ($searchLower) {
                    return str_contains(strtolower($item['ktr']), $searchLower)
                        || str_contains(strtolower($item['production_code']), $searchLower)
                        || str_contains(strtolower($item['customer']), $searchLower)
                        || str_contains(strtolower($item['item_name']), $searchLower)
                        || str_contains(strtolower($item['heat_number']), $searchLower)
                        || str_contains(strtolower($item['stage_label']), $searchLower)
                        || str_contains(strtolower($item['checkpoint_code']), $searchLower)
                        || str_contains(strtolower($item['operator']), $searchLower);
                });
            }

            $sortedItems = $detailItems->sortBy([
                ['stage_order', 'asc'],
                ['ktr', 'asc'],
                ['checkpoint_code', 'asc'],
            ])->values();
        }

        // -------------------------------------------------------------
        // 5. GLOBAL KPI SUMMARY
        // -------------------------------------------------------------
        $summary = [
            'total_ktr_activities' => (int) $stageSummaries->sum('ktr_count'),
            'total_qty_output' => (int) $stageSummaries->sum('good_pcs'),
            'total_defect' => (int) $stageSummaries->sum('defect_pcs'),
            'total_weight' => (float) round($stageSummaries->sum('weight_kg'), 2),
            'total_records' => $sortedItems->count(),
        ];

        return [
            'stage_summaries' => $stageSummaries,
            'items' => $sortedItems,
            'summary' => $summary,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'stage' => $selectedStage,
                'search' => $search,
            ],
            'stages' => self::STAGES,
        ];
    }
}
