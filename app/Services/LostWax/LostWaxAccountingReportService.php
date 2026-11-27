<?php

namespace App\Services\LostWax;

use App\Models\ProductionPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LostWaxAccountingReportService
{
    public const TIMEZONE = 'Asia/Jakarta';

    public const STAGES = [
        'cetak' => 'CETAK',
        'rangkai' => 'RANGKAI',
        'layer_1' => 'LAPISAN 1',
        'layer_2' => 'LAPISAN 2',
        'layer_3' => 'LAPISAN 3',
        'layer_4' => 'LAPISAN 4',
        'layer_5' => 'LAPISAN 5',
        'layer_6' => 'LAPISAN 6',
        'oven' => 'OVEN',
        'cor' => 'HASIL COR',
    ];

    public const STAGE_ORDER = [
        'cetak' => 1,
        'rangkai' => 2,
        'layer_1' => 3,
        'layer_2' => 4,
        'layer_3' => 5,
        'layer_4' => 6,
        'layer_5' => 7,
        'layer_6' => 8,
        'oven' => 9,
        'cor' => 10,
    ];

    /**
     * Get Periodic Accounting Report Data for Lost Wax (Single format: Ringkas per Kode Produksi).
     *
     * @param  array{date_from?: string|null, date_to?: string|null, search?: string|null, product_scope?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function getAccountingReportData(array $filters, ?User $user = null): array
    {
        $dateFromStr = trim((string) ($filters['date_from'] ?? Carbon::now(self::TIMEZONE)->startOfMonth()->toDateString()));
        $dateToStr = trim((string) ($filters['date_to'] ?? Carbon::now(self::TIMEZONE)->toDateString()));

        $dateFrom = Carbon::parse($dateFromStr, self::TIMEZONE)->startOfDay();
        $dateTo = Carbon::parse($dateToStr, self::TIMEZONE)->endOfDay();
        $cutoffEnd = $dateTo->copy();

        $search = trim((string) ($filters['search'] ?? ''));
        $scope = $filters['product_scope'] ?? $user?->product_scope;
        if ($scope === 'all') {
            $scope = null;
        }

        $dateFromFormatted = $dateFrom->format('Y-m-d H:i:s');
        $cutoffFormatted = $cutoffEnd->format('Y-m-d H:i:s');
        $dateFromDate = $dateFrom->toDateString();
        $dateToDate = $dateTo->toDateString();

        // 1. Identify Candidate Production Codes with physical activity in the period
        // A. Print executions finalized in period
        $printCodes = DB::table('lost_wax_print_executions as pe')
            ->join('lost_wax_print_order_lines as pol', 'pol.id', '=', 'pe.lost_wax_print_order_line_id')
            ->where('pe.status', 'FINALIZED')
            ->whereBetween('pe.execution_date', [$dateFromDate, $dateToDate])
            ->pluck('pol.code')->toArray();

        // B. Trees produced in period
        $treeCodes = DB::table('lost_wax_trees as t')
            ->leftJoin('lost_wax_tree_allocations as ta', 'ta.lost_wax_tree_id', '=', 't.id')
            ->leftJoin('lost_wax_print_order_lines as pol', 'pol.id', '=', 'ta.lost_wax_print_order_line_id')
            ->leftJoin('lost_wax_print_order_lines as pol_dir', 'pol_dir.id', '=', 't.lost_wax_print_order_line_id')
            ->leftJoin('lost_wax_work_orders as wo', 'wo.id', '=', 't.work_order_id')
            ->where('t.status', '!=', 'cancelled')
            ->whereBetween('t.production_date', [$dateFromDate, $dateToDate])
            ->select(DB::raw('COALESCE(pol.code, pol_dir.code, wo.et_code) as code'))
            ->pluck('code')->toArray();

        // C. Scans in period (layers 1-6, oven, siap_cor with result = success)
        $scanCodes = DB::table('lost_wax_scan_events as se')
            ->join('lost_wax_trees as t', 't.id', '=', 'se.tree_id')
            ->leftJoin('lost_wax_tree_allocations as ta', 'ta.lost_wax_tree_id', '=', 't.id')
            ->leftJoin('lost_wax_print_order_lines as pol', 'pol.id', '=', 'ta.lost_wax_print_order_line_id')
            ->leftJoin('lost_wax_print_order_lines as pol_dir', 'pol_dir.id', '=', 't.lost_wax_print_order_line_id')
            ->leftJoin('lost_wax_work_orders as wo', 'wo.id', '=', 't.work_order_id')
            ->where('se.result', 'success')
            ->whereIn('se.stage', ['layer_1', 'layer_2', 'layer_3', 'layer_4', 'layer_5', 'layer_6', 'oven', 'siap_cor'])
            ->whereBetween('se.scanned_at', [$dateFromFormatted, $cutoffFormatted])
            ->select(DB::raw('COALESCE(pol.code, pol_dir.code, wo.et_code) as code'))
            ->pluck('code')->toArray();

        // D. Casting results completed in period
        $castCodes = DB::table('lost_wax_casting_result_trees as crt')
            ->join('lost_wax_casting_results as cr', 'cr.id', '=', 'crt.lost_wax_casting_result_id')
            ->where('cr.status', 'COMPLETED')
            ->whereBetween('cr.cast_date', [$dateFromDate, $dateToDate])
            ->pluck('crt.production_code')->toArray();

        // E. Active defects occurred in period
        $defectCodes = DB::table('lost_wax_tree_defects as td')
            ->join('lost_wax_trees as t', 't.id', '=', 'td.lost_wax_tree_id')
            ->leftJoin('lost_wax_tree_allocations as ta', 'ta.lost_wax_tree_id', '=', 't.id')
            ->leftJoin('lost_wax_print_order_lines as pol', 'pol.id', '=', 'ta.lost_wax_print_order_line_id')
            ->leftJoin('lost_wax_print_order_lines as pol_dir', 'pol_dir.id', '=', 't.lost_wax_print_order_line_id')
            ->leftJoin('lost_wax_work_orders as wo', 'wo.id', '=', 't.work_order_id')
            ->where('td.occurred_at', '<=', $cutoffFormatted)
            ->where(function ($q) use ($cutoffFormatted) {
                $q->whereNull('td.voided_at')->orWhere('td.voided_at', '>', $cutoffFormatted);
            })
            ->whereBetween('td.occurred_at', [$dateFromFormatted, $cutoffFormatted])
            ->select(DB::raw('COALESCE(pol.code, pol_dir.code, wo.et_code) as code'))
            ->pluck('code')->toArray();

        $candidateCodes = array_values(array_unique(array_filter(array_merge(
            $printCodes,
            $treeCodes,
            $scanCodes,
            $castCodes,
            $defectCodes
        ))));

        if (empty($candidateCodes)) {
            return $this->buildEmptyReportResult($dateFromStr, $dateToStr, $user, $scope);
        }

        // 2. Fetch plans & print order lines for candidate codes
        $planQuery = ProductionPlan::query()->whereIn('code', $candidateCodes);
        if ($scope) {
            $planQuery->where('product_scope', $scope);
        }
        /** @var Collection<string, ProductionPlan> $plans */
        $plans = $planQuery->get()->keyBy('code');

        if ($scope) {
            // Only keep candidate codes belonging to the requested scope
            $candidateCodes = array_values(array_intersect($candidateCodes, $plans->keys()->all()));
            if (empty($candidateCodes)) {
                return $this->buildEmptyReportResult($dateFromStr, $dateToStr, $user, $scope);
            }
        }

        $printLines = DB::table('lost_wax_print_order_lines')
            ->whereIn('code', $candidateCodes)
            ->get()
            ->groupBy('code');

        // Apply text search filter if provided
        if ($search !== '') {
            $lowerSearch = mb_strtolower($search);
            $candidateCodes = array_values(array_filter($candidateCodes, function ($code) use ($lowerSearch, $plans, $printLines) {
                if (str_contains(mb_strtolower($code), $lowerSearch)) {
                    return true;
                }
                $plan = $plans->get($code);
                if ($plan) {
                    if (str_contains(mb_strtolower((string) $plan->item_name), $lowerSearch)
                        || str_contains(mb_strtolower((string) $plan->customer), $lowerSearch)
                        || str_contains(mb_strtolower((string) $plan->aisi), $lowerSearch)) {
                        return true;
                    }
                }
                $pols = $printLines->get($code);
                if ($pols && $pols->isNotEmpty()) {
                    $firstPol = $pols->first();
                    if (str_contains(mb_strtolower((string) $firstPol->item_name), $lowerSearch)
                        || str_contains(mb_strtolower((string) $firstPol->customer), $lowerSearch)
                        || str_contains(mb_strtolower((string) $firstPol->aisi), $lowerSearch)) {
                        return true;
                    }
                }

                return false;
            }));

            if (empty($candidateCodes)) {
                return $this->buildEmptyReportResult($dateFromStr, $dateToStr, $user, $scope);
            }
        }

        sort($candidateCodes);

        // 3. Stage 1: CETAK (LostWaxPrintExecution)
        $cetakData = DB::table('lost_wax_print_executions as pe')
            ->join('lost_wax_print_order_lines as pol', 'pol.id', '=', 'pe.lost_wax_print_order_line_id')
            ->whereIn('pol.code', $candidateCodes)
            ->where('pe.status', 'FINALIZED')
            ->whereBetween('pe.execution_date', [$dateFromDate, $dateToDate])
            ->select(
                'pol.code',
                DB::raw('count(*) as count'),
                DB::raw('sum(coalesce(pe.qty_gross_output, pe.qty_good + pe.qty_defect)) as gross'),
                DB::raw('sum(pe.qty_defect) as defect')
            )
            ->groupBy('pol.code')
            ->get()->keyBy('code');

        // 4. Stage 2: RANGKAI (LostWaxTree)
        $rangkaiData = DB::table('lost_wax_trees as t')
            ->leftJoin('lost_wax_tree_allocations as ta', 'ta.lost_wax_tree_id', '=', 't.id')
            ->leftJoin('lost_wax_print_order_lines as pol', 'pol.id', '=', 'ta.lost_wax_print_order_line_id')
            ->leftJoin('lost_wax_print_order_lines as pol_dir', 'pol_dir.id', '=', 't.lost_wax_print_order_line_id')
            ->leftJoin('lost_wax_work_orders as wo', 'wo.id', '=', 't.work_order_id')
            ->where('t.status', '!=', 'cancelled')
            ->whereBetween('t.production_date', [$dateFromDate, $dateToDate])
            ->whereIn(DB::raw('COALESCE(pol.code, pol_dir.code, wo.et_code)'), $candidateCodes)
            ->select(
                DB::raw('COALESCE(pol.code, pol_dir.code, wo.et_code) as code'),
                DB::raw('count(distinct t.id) as tree_count'),
                DB::raw('sum(coalesce(ta.allocated_qty, t.quantity, 0)) as qty')
            )
            ->groupBy(DB::raw('COALESCE(pol.code, pol_dir.code, wo.et_code)'))
            ->get()->keyBy('code');

        // Rangkai Defects (assembly)
        $rangkaiDefects = DB::table('lost_wax_tree_defects as td')
            ->join('lost_wax_trees as t', 't.id', '=', 'td.lost_wax_tree_id')
            ->leftJoin('lost_wax_tree_allocations as ta', 'ta.lost_wax_tree_id', '=', 't.id')
            ->leftJoin('lost_wax_print_order_lines as pol', 'pol.id', '=', 'ta.lost_wax_print_order_line_id')
            ->leftJoin('lost_wax_print_order_lines as pol_dir', 'pol_dir.id', '=', 't.lost_wax_print_order_line_id')
            ->leftJoin('lost_wax_work_orders as wo', 'wo.id', '=', 't.work_order_id')
            ->where('td.stage', 'assembly')
            ->where('td.occurred_at', '<=', $cutoffFormatted)
            ->where(function ($q) use ($cutoffFormatted) {
                $q->whereNull('td.voided_at')->orWhere('td.voided_at', '>', $cutoffFormatted);
            })
            ->whereBetween('td.occurred_at', [$dateFromFormatted, $cutoffFormatted])
            ->whereIn(DB::raw('COALESCE(pol.code, pol_dir.code, wo.et_code)'), $candidateCodes)
            ->select(
                DB::raw('COALESCE(pol.code, pol_dir.code, wo.et_code) as code'),
                DB::raw('sum(td.defect_qty) as defect')
            )
            ->groupBy(DB::raw('COALESCE(pol.code, pol_dir.code, wo.et_code)'))
            ->get()->keyBy('code');

        // 5. Stages 3-8: LAPISAN 1 to 6 (LostWaxScanEvent)
        $layerScansRaw = DB::table('lost_wax_scan_events as se')
            ->join('lost_wax_trees as t', 't.id', '=', 'se.tree_id')
            ->leftJoin('lost_wax_tree_allocations as ta', 'ta.lost_wax_tree_id', '=', 't.id')
            ->leftJoin('lost_wax_print_order_lines as pol', 'pol.id', '=', 'ta.lost_wax_print_order_line_id')
            ->leftJoin('lost_wax_print_order_lines as pol_dir', 'pol_dir.id', '=', 't.lost_wax_print_order_line_id')
            ->leftJoin('lost_wax_work_orders as wo', 'wo.id', '=', 't.work_order_id')
            ->where('se.result', 'success')
            ->whereIn('se.stage', ['layer_1', 'layer_2', 'layer_3', 'layer_4', 'layer_5', 'layer_6'])
            ->where('se.scanned_at', '<=', $cutoffFormatted)
            ->whereIn(DB::raw('COALESCE(pol.code, pol_dir.code, wo.et_code)'), $candidateCodes)
            ->select(
                'se.tree_id',
                'se.stage',
                DB::raw('COALESCE(pol.code, pol_dir.code, wo.et_code) as code'),
                DB::raw('min(se.scanned_at) as first_scan'),
                DB::raw('max(coalesce(ta.allocated_qty, t.quantity, 0)) as qty')
            )
            ->groupBy('se.tree_id', 'se.stage', DB::raw('COALESCE(pol.code, pol_dir.code, wo.et_code)'))
            ->get();

        // Group layer scans by code and stage for scans whose first scan was in the period
        $layerData = [];
        foreach ($layerScansRaw as $ls) {
            if ($ls->first_scan >= $dateFromFormatted && $ls->first_scan <= $cutoffFormatted) {
                $codeKey = (string) $ls->code;
                $stageKey = (string) $ls->stage;
                if (! isset($layerData[$codeKey][$stageKey])) {
                    $layerData[$codeKey][$stageKey] = 0;
                }
                $layerData[$codeKey][$stageKey] += (int) $ls->qty;
            }
        }

        // Layer Defects
        $layerDefects = DB::table('lost_wax_tree_defects as td')
            ->join('lost_wax_trees as t', 't.id', '=', 'td.lost_wax_tree_id')
            ->leftJoin('lost_wax_tree_allocations as ta', 'ta.lost_wax_tree_id', '=', 't.id')
            ->leftJoin('lost_wax_print_order_lines as pol', 'pol.id', '=', 'ta.lost_wax_print_order_line_id')
            ->leftJoin('lost_wax_print_order_lines as pol_dir', 'pol_dir.id', '=', 't.lost_wax_print_order_line_id')
            ->leftJoin('lost_wax_work_orders as wo', 'wo.id', '=', 't.work_order_id')
            ->whereIn('td.stage', ['layer_1', 'layer_2', 'layer_3', 'layer_4', 'layer_5', 'layer_6'])
            ->where('td.occurred_at', '<=', $cutoffFormatted)
            ->where(function ($q) use ($cutoffFormatted) {
                $q->whereNull('td.voided_at')->orWhere('td.voided_at', '>', $cutoffFormatted);
            })
            ->whereBetween('td.occurred_at', [$dateFromFormatted, $cutoffFormatted])
            ->whereIn(DB::raw('COALESCE(pol.code, pol_dir.code, wo.et_code)'), $candidateCodes)
            ->select(
                DB::raw('COALESCE(pol.code, pol_dir.code, wo.et_code) as code'),
                'td.stage',
                DB::raw('sum(td.defect_qty) as defect')
            )
            ->groupBy(DB::raw('COALESCE(pol.code, pol_dir.code, wo.et_code)'), 'td.stage')
            ->get();

        $layerDefectMap = [];
        foreach ($layerDefects as $ld) {
            $layerDefectMap[(string) $ld->code][(string) $ld->stage] = (int) $ld->defect;
        }

        // 6. Stage 9: OVEN (Scan events 'oven' and 'siap_cor' combined, single count per tree)
        $ovenScansRaw = DB::table('lost_wax_scan_events as se')
            ->join('lost_wax_trees as t', 't.id', '=', 'se.tree_id')
            ->leftJoin('lost_wax_tree_allocations as ta', 'ta.lost_wax_tree_id', '=', 't.id')
            ->leftJoin('lost_wax_print_order_lines as pol', 'pol.id', '=', 'ta.lost_wax_print_order_line_id')
            ->leftJoin('lost_wax_print_order_lines as pol_dir', 'pol_dir.id', '=', 't.lost_wax_print_order_line_id')
            ->leftJoin('lost_wax_work_orders as wo', 'wo.id', '=', 't.work_order_id')
            ->where('se.result', 'success')
            ->whereIn('se.stage', ['oven', 'siap_cor'])
            ->where('se.scanned_at', '<=', $cutoffFormatted)
            ->whereIn(DB::raw('COALESCE(pol.code, pol_dir.code, wo.et_code)'), $candidateCodes)
            ->select(
                'se.tree_id',
                DB::raw('COALESCE(pol.code, pol_dir.code, wo.et_code) as code'),
                DB::raw('min(se.scanned_at) as first_scan'),
                DB::raw('max(coalesce(ta.allocated_qty, t.quantity, 0)) as qty')
            )
            ->groupBy('se.tree_id', DB::raw('COALESCE(pol.code, pol_dir.code, wo.et_code)'))
            ->get();

        $ovenData = [];
        foreach ($ovenScansRaw as $os) {
            if ($os->first_scan >= $dateFromFormatted && $os->first_scan <= $cutoffFormatted) {
                $codeKey = (string) $os->code;
                if (! isset($ovenData[$codeKey])) {
                    $ovenData[$codeKey] = 0;
                }
                $ovenData[$codeKey] += (int) $os->qty;
            }
        }

        // Oven Defects
        $ovenDefects = DB::table('lost_wax_tree_defects as td')
            ->join('lost_wax_trees as t', 't.id', '=', 'td.lost_wax_tree_id')
            ->leftJoin('lost_wax_tree_allocations as ta', 'ta.lost_wax_tree_id', '=', 't.id')
            ->leftJoin('lost_wax_print_order_lines as pol', 'pol.id', '=', 'ta.lost_wax_print_order_line_id')
            ->leftJoin('lost_wax_print_order_lines as pol_dir', 'pol_dir.id', '=', 't.lost_wax_print_order_line_id')
            ->leftJoin('lost_wax_work_orders as wo', 'wo.id', '=', 't.work_order_id')
            ->where('td.stage', 'oven')
            ->where('td.occurred_at', '<=', $cutoffFormatted)
            ->where(function ($q) use ($cutoffFormatted) {
                $q->whereNull('td.voided_at')->orWhere('td.voided_at', '>', $cutoffFormatted);
            })
            ->whereBetween('td.occurred_at', [$dateFromFormatted, $cutoffFormatted])
            ->whereIn(DB::raw('COALESCE(pol.code, pol_dir.code, wo.et_code)'), $candidateCodes)
            ->select(
                DB::raw('COALESCE(pol.code, pol_dir.code, wo.et_code) as code'),
                DB::raw('sum(td.defect_qty) as defect')
            )
            ->groupBy(DB::raw('COALESCE(pol.code, pol_dir.code, wo.et_code)'))
            ->get()->keyBy('code');

        // 7. Stage 10: HASIL COR (LostWaxCastingResultTree & LostWaxCastingResult)
        $corData = DB::table('lost_wax_casting_result_trees as crt')
            ->join('lost_wax_casting_results as cr', 'cr.id', '=', 'crt.lost_wax_casting_result_id')
            ->where('cr.status', 'COMPLETED')
            ->whereBetween('cr.cast_date', [$dateFromDate, $dateToDate])
            ->whereIn('crt.production_code', $candidateCodes)
            ->select(
                'crt.production_code as code',
                DB::raw('count(distinct cr.heat_number) as heat_count'),
                DB::raw('sum(coalesce(crt.gross_pcs, crt.quantity_pcs + crt.defect_pcs)) as gross'),
                DB::raw('sum(crt.defect_pcs) as defect'),
                DB::raw('sum(crt.quantity_pcs) as good')
            )
            ->groupBy('crt.production_code')
            ->get()->keyBy('code');

        // 8. Assemble rows per Kode Produksi
        $rows = [];
        $anomalies = [];

        foreach ($candidateCodes as $code) {
            $plan = $plans->get($code);
            $pols = $printLines->get($code);
            $firstPol = $pols?->first();

            $itemName = (string) ($plan?->item_name ?? $firstPol?->item_name ?? '-');
            $aisi = (string) ($plan?->aisi ?? $firstPol?->aisi ?? '-');
            $customer = (string) ($plan?->customer ?? $firstPol?->customer ?? '-');

            // PO Quantity resolution
            $poQty = null;
            if ($plan && $plan->po_quantity !== null && (int) $plan->po_quantity > 0) {
                $poQty = (int) $plan->po_quantity;
            } elseif ($firstPol && $firstPol->qty_ordered !== null && (int) $firstPol->qty_ordered > 0) {
                $poQty = (int) $firstPol->qty_ordered;
            }

            // Plan = (int) round(PO * 1.10). Empty if PO is empty.
            $planQty = $poQty !== null ? (int) round($poQty * 1.10) : '';

            // Unit Weight resolution
            $unitWeight = 0.0;
            if ($plan && $plan->weight !== null && (float) $plan->weight > 0) {
                $unitWeight = (float) $plan->weight;
            }

            // Stages Evaluation
            $stages = [];

            // 1. Cetak
            $cRow = $cetakData->get($code);
            if ($cRow && (int) $cRow->count > 0) {
                $h = (int) $cRow->gross;
                $r = (int) $cRow->defect;
                $stages['cetak'] = [
                    'hasil' => $h, 'rusak' => $r, 'output' => max(0, $h - $r),
                    'hasil_pcs' => $h, 'rusak_pcs' => $r, 'output_pcs' => max(0, $h - $r),
                    'is_done' => true,
                ];
            } else {
                $stages['cetak'] = [
                    'hasil' => null, 'rusak' => null, 'output' => null,
                    'hasil_pcs' => null, 'rusak_pcs' => null, 'output_pcs' => null,
                    'is_done' => false,
                ];
            }

            // 2. Rangkai
            $rRow = $rangkaiData->get($code);
            if ($rRow && (int) $rRow->tree_count > 0) {
                $h = (int) $rRow->qty;
                $rDefectRow = $rangkaiDefects->get($code);
                $r = $rDefectRow ? (int) $rDefectRow->defect : 0;
                $stages['rangkai'] = [
                    'hasil' => $h, 'rusak' => $r, 'output' => max(0, $h - $r),
                    'hasil_pcs' => $h, 'rusak_pcs' => $r, 'output_pcs' => max(0, $h - $r),
                    'is_done' => true,
                ];
            } else {
                $stages['rangkai'] = [
                    'hasil' => null, 'rusak' => null, 'output' => null,
                    'hasil_pcs' => null, 'rusak_pcs' => null, 'output_pcs' => null,
                    'is_done' => false,
                ];
            }

            // 3-8. Lapisan 1 to 6
            for ($k = 1; $k <= 6; $k++) {
                $stgKey = "layer_{$k}";
                if (isset($layerData[$code][$stgKey])) {
                    $h = (int) $layerData[$code][$stgKey];
                    $r = isset($layerDefectMap[$code][$stgKey]) ? (int) $layerDefectMap[$code][$stgKey] : 0;
                    $stages[$stgKey] = [
                        'hasil' => $h, 'rusak' => $r, 'output' => max(0, $h - $r),
                        'hasil_pcs' => $h, 'rusak_pcs' => $r, 'output_pcs' => max(0, $h - $r),
                        'is_done' => true,
                    ];
                } else {
                    $stages[$stgKey] = [
                        'hasil' => null, 'rusak' => null, 'output' => null,
                        'hasil_pcs' => null, 'rusak_pcs' => null, 'output_pcs' => null,
                        'is_done' => false,
                    ];
                }
            }

            // 9. Oven
            if (isset($ovenData[$code])) {
                $h = (int) $ovenData[$code];
                $oDefectRow = $ovenDefects->get($code);
                $r = $oDefectRow ? (int) $oDefectRow->defect : 0;
                $stages['oven'] = [
                    'hasil' => $h, 'rusak' => $r, 'output' => max(0, $h - $r),
                    'hasil_pcs' => $h, 'rusak_pcs' => $r, 'output_pcs' => max(0, $h - $r),
                    'is_done' => true,
                ];
            } else {
                $stages['oven'] = [
                    'hasil' => null, 'rusak' => null, 'output' => null,
                    'hasil_pcs' => null, 'rusak_pcs' => null, 'output_pcs' => null,
                    'is_done' => false,
                ];
            }

            // 10. Hasil Cor
            $corRow = $corData->get($code);
            if ($corRow && (int) $corRow->heat_count > 0) {
                $h = (int) $corRow->gross;
                $r = (int) $corRow->defect;
                $good = (int) $corRow->good;
                $stages['cor'] = [
                    'hasil' => $h, 'rusak' => $r, 'output' => $good,
                    'hasil_pcs' => $h, 'rusak_pcs' => $r, 'output_pcs' => $good,
                    'is_done' => true,
                ];
            } else {
                $stages['cor'] = [
                    'hasil' => null, 'rusak' => null, 'output' => null,
                    'hasil_pcs' => null, 'rusak_pcs' => null, 'output_pcs' => null,
                    'is_done' => false,
                ];
            }

            // Total Weight calculation:
            // Lost Wax metal product weight is determined by cast product output, not wax or ceramic shell.
            $goodCorPcs = $stages['cor']['output'] ?? 0;
            $totalWeightKg = $goodCorPcs > 0 ? round($goodCorPcs * $unitWeight, 2) : 0.0;

            $rows[] = [
                'code' => $code,
                'item_name' => $itemName,
                'aisi' => $aisi,
                'customer' => $customer,
                'po_qty' => $poQty !== null ? $poQty : '',
                'po_number' => $poQty !== null ? $poQty : '',
                'qty_planned' => $planQty,
                'unit_weight_kg' => $unitWeight,
                'unit_weight_label' => $unitWeight > 0 ? number_format($unitWeight, 2, '.', '') : '—',
                'total_weight_kg' => $totalWeightKg,
                'stages' => $stages,
            ];
        }

        return [
            'meta' => [
                'date_from' => $dateFromStr,
                'date_to' => $dateToStr,
                'generated_at' => Carbon::now(self::TIMEZONE)->format('d/m/Y H:i:s'),
                'generated_by' => $user?->name ?? 'System',
                'product_scope' => $scope ?: 'Semua Scope',
                'report_format' => 'summary',
                'format_label' => 'Ringkas per Kode Produksi',
            ],
            'pergerakan' => $rows,
            'anomalies' => $anomalies,
        ];
    }

    /**
     * Build Empty Report Result Structure.
     *
     * @return array<string, mixed>
     */
    protected function buildEmptyReportResult(string $dateFromStr, string $dateToStr, ?User $user, ?string $scope): array
    {
        return [
            'meta' => [
                'date_from' => $dateFromStr,
                'date_to' => $dateToStr,
                'generated_at' => Carbon::now(self::TIMEZONE)->format('d/m/Y H:i:s'),
                'generated_by' => $user?->name ?? 'System',
                'product_scope' => $scope ?: 'Semua Scope',
                'report_format' => 'summary',
                'format_label' => 'Ringkas per Kode Produksi',
            ],
            'pergerakan' => [],
            'anomalies' => [],
        ];
    }

    /**
     * Build Single-Sheet Excel Spreadsheet (29 Columns: Ringkas per Kode Produksi).
     */
    public function buildExcelSpreadsheet(array $reportData): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pergerakan Produksi');

        $meta = $reportData['meta'];
        $this->populateMovementSummarySheet($sheet, 'HORIZONTAL PRODUCTION TRACKING — RINGKAS PER KODE PRODUKSI', $reportData['pergerakan'], $meta);

        return $spreadsheet;
    }

    /**
     * Populate Horizontal Movement Sheet for Summary Format (29 Columns).
     */
    protected function populateMovementSummarySheet(Worksheet $sheet, string $title, array $rows, array $meta): void
    {
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);

        $sheet->setCellValue('A1', "LAPORAN ACCOUNTING LOST WAX — {$title}");
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

        // 10 Department Groups (20 Columns: J through AC)
        $stageCols = [
            'CETAK' => ['J', 'K'],
            'RANGKAI' => ['L', 'M'],
            'LAPISAN 1' => ['N', 'O'],
            'LAPISAN 2' => ['P', 'Q'],
            'LAPISAN 3' => ['R', 'S'],
            'LAPISAN 4' => ['T', 'U'],
            'LAPISAN 5' => ['V', 'W'],
            'LAPISAN 6' => ['X', 'Y'],
            'OVEN' => ['Z', 'AA'],
            'HASIL COR' => ['AB', 'AC'],
        ];

        foreach ($stageCols as $sLabel => $cols) {
            $sheet->setCellValue("{$cols[0]}4", $sLabel);
            $sheet->mergeCells("{$cols[0]}4:{$cols[1]}4");
            $sheet->setCellValue("{$cols[0]}5", 'Hasil');
            $sheet->setCellValue("{$cols[1]}5", 'Rusak');
        }

        // Apply sky blue header styling for department headers (J4:AC4)
        $deptHeaderStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0369A1']], // sky-700
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '334155']]],
        ];
        $sheet->getStyle('J4:AC4')->applyFromArray($deptHeaderStyle);

        // Apply subheader styling (J5:AC5)
        $subHeaderStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 8.5],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']], // slate-800
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '334155']]],
        ];
        $sheet->getStyle('J5:AC5')->applyFromArray($subHeaderStyle);

        $rIdx = 6;
        $orderedStages = array_keys(self::STAGES);

        foreach ($rows as $idx => $r) {
            $sheet->setCellValue("A{$rIdx}", $idx + 1);
            $sheet->setCellValue("B{$rIdx}", $r['code']);
            $sheet->getStyle("B{$rIdx}")->getFont()->setBold(true);
            $sheet->setCellValue("C{$rIdx}", $r['item_name']);
            $sheet->setCellValue("D{$rIdx}", $r['aisi']);
            $sheet->setCellValue("E{$rIdx}", $r['customer']);

            $poVal = $r['po_qty'] ?? '';
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
                    $sheet->getStyle("H{$rIdx}")->getFont()->setColor(new Color('D97706')); // amber-600
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

                $c1 = Coordinate::stringFromColumnIndex($colPointer + 1);
                $c2 = Coordinate::stringFromColumnIndex($colPointer + 2);

                $sheet->setCellValue("{$c1}{$rIdx}", $hasilVal);
                $sheet->setCellValue("{$c2}{$rIdx}", $rusakVal);

                if (is_numeric($hasilVal)) {
                    $sheet->getStyle("{$c1}{$rIdx}")->getNumberFormat()->setFormatCode('#,##0');
                    $sheet->getStyle("{$c1}{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                } else {
                    $sheet->getStyle("{$c1}{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                if (is_numeric($rusakVal)) {
                    $sheet->getStyle("{$c2}{$rIdx}")->getNumberFormat()->setFormatCode('#,##0');
                    $sheet->getStyle("{$c2}{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
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
                $sheet->getStyle("A{$rIdx}:AC{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');
            }
            $rIdx++;
        }

        $lastRow = max(5, $rIdx - 1);
        $sheet->getStyle("A4:AC{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');

        // Text wrap and alignments for data cells
        if ($rIdx > 6) {
            $sheet->getStyle('A6:A'.($rIdx - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('C6:C'.($rIdx - 1))->getAlignment()->setWrapText(true);
            $sheet->getStyle('D6:D'.($rIdx - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        // Freeze pane on Column J (so columns A to I stay visible while scrolling horizontally)
        $sheet->freezePane('J6');
        $sheet->setAutoFilter('A5:AC5');

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
        for ($i = 10; $i <= 29; $i++) {
            $colLetter = Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($colLetter)->setWidth(8.5);
        }
    }

    protected function applyHeaderStyle(Worksheet $sheet, string $cellCoordinateRange): void
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
}
