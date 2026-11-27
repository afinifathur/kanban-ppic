<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxCastingResult;
use App\Models\LostWaxCastingResultTree;
use App\Models\LostWaxPrintExecution;
use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\LostWaxTreeAllocation;
use App\Models\LostWaxTreeDefect;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWax\LostWaxAccountingReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LostWaxAccountingReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $auditorUser;

    protected LostWaxAccountingReportService $reportService;

    protected function setUp(): void
    {
        parent::setUp();

        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution']);
        $permProdVisibility = Permission::firstOrCreate(['name' => 'access_production_visibility']);
        $permAuditor = Permission::firstOrCreate(['name' => 'access_auditor_visibility']);

        $roleAdmin = Role::firstOrCreate(['name' => 'admin']);
        $roleAdmin->givePermissionTo([$permPlanning, $permExecution, $permProdVisibility, $permAuditor]);

        $roleAuditor = Role::firstOrCreate(['name' => 'auditor']);
        $roleAuditor->givePermissionTo([$permAuditor]);

        $this->adminUser = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@peroniks.com',
        ]);
        $this->adminUser->assignRole('admin');

        $this->auditorUser = User::factory()->create([
            'name' => 'Auditor Accounting',
            'email' => 'accounting@peroniks.com',
        ]);
        $this->auditorUser->assignRole('auditor');

        $this->reportService = app(LostWaxAccountingReportService::class);
    }

    protected function createPlan(array $options = []): ProductionPlan
    {
        static $seq = 1;
        $id = $seq++;

        $po = array_key_exists('po_quantity', $options) ? $options['po_quantity'] : 1000;

        return ProductionPlan::create([
            'code' => $options['code'] ?? 'LW-PLAN-'.$id,
            'title' => 'Rencana LW '.$id,
            'item_code' => 'LW.'.$id,
            'item_name' => $options['item_name'] ?? 'FLANGE SS304 '.$id.'"',
            'po_number' => $options['po_number'] ?? 'PO-'.$id,
            'po_quantity' => $po,
            'line_number' => 1,
            'qty_planned' => $po !== null ? (int) round($po * 1.10) : 0,
            'qty_remaining' => 100,
            'customer' => $options['customer'] ?? 'PT SINAR METAL',
            'product_scope' => $options['product_scope'] ?? 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'status' => 'planning',
            'weight' => array_key_exists('weight', $options) ? $options['weight'] : 2.50,
            'aisi' => $options['aisi'] ?? '304',
        ]);
    }

    protected function createPrintExecution(ProductionPlan $plan, array $options = []): LostWaxPrintExecution
    {
        static $pSeq = 1;
        $id = $pSeq++;

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PO-PRT-'.$id,
            'scheduled_date' => $options['execution_date'] ?? '2026-10-01',
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $line = LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $order->id,
            'production_plan_id' => $plan->id,
            'qty_ordered' => $plan->po_quantity ?? 1000,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => '2"',
            'aisi' => $plan->aisi,
            'execution_status' => 'completed',
        ]);

        $good = $options['qty_good'] ?? 100;
        $defect = $options['qty_defect'] ?? 0;
        $gross = array_key_exists('qty_gross_output', $options) ? $options['qty_gross_output'] : ($good + $defect);

        return LostWaxPrintExecution::create([
            'lost_wax_print_order_line_id' => $line->id,
            'execution_date' => $options['execution_date'] ?? '2026-10-01',
            'qty_gross_output' => $gross,
            'qty_good' => $good,
            'qty_defect' => $defect,
            'status' => $options['status'] ?? 'FINALIZED',
            'recorded_by' => $this->adminUser->id,
            'recorded_at' => Carbon::parse($options['execution_date'] ?? '2026-10-01')->setTime(9, 0),
            'finalized_by' => $this->adminUser->id,
            'finalized_at' => Carbon::parse($options['execution_date'] ?? '2026-10-01')->setTime(10, 0),
        ]);
    }

    protected function createTree(ProductionPlan $plan, array $options = []): LostWaxTree
    {
        static $tSeq = 1;
        $id = $tSeq++;

        $qty = $options['quantity'] ?? 16;
        $prodDate = $options['production_date'] ?? '2026-10-02';

        $pol = LostWaxPrintOrderLine::where('production_plan_id', $plan->id)->first();
        if (! $pol) {
            $order = LostWaxPrintOrder::create([
                'print_order_number' => 'PO-PRT-DEF-'.$id,
                'scheduled_date' => $prodDate,
                'status' => 'ISSUED',
                'created_by' => $this->adminUser->id,
            ]);
            $pol = LostWaxPrintOrderLine::create([
                'lost_wax_print_order_id' => $order->id,
                'production_plan_id' => $plan->id,
                'qty_ordered' => $plan->po_quantity ?? 1000,
                'code' => $plan->code,
                'customer' => $plan->customer,
                'item_name' => $plan->item_name,
                'size' => '2"',
                'aisi' => $plan->aisi,
                'execution_status' => 'completed',
            ]);
        }

        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $pol->id,
            'barcode' => 'TREE-'.sprintf('%06d', $id),
            'tree_number' => $id,
            'quantity' => $qty,
            'family_code' => $options['family_code'] ?? '3',
            'daily_sequence' => $id,
            'status' => $options['status'] ?? 'generated',
            'current_stage' => $options['current_stage'] ?? 'cor',
            'production_date' => $prodDate,
            'created_at' => Carbon::parse($prodDate)->setTime(8, 0),
        ]);

        if (! ($options['skip_allocation'] ?? false)) {
            LostWaxTreeAllocation::create([
                'lost_wax_tree_id' => $tree->id,
                'lost_wax_print_order_line_id' => $pol->id,
                'allocated_qty' => $options['allocated_qty'] ?? $qty,
            ]);
        }

        return $tree;
    }

    protected function addScan(LostWaxTree $tree, string $stage, string $scannedAt, string $result = 'success'): LostWaxScanEvent
    {
        return LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => $stage,
            'scanned_qty' => null,
            'scanned_at' => Carbon::parse($scannedAt),
            'operator_id' => $this->adminUser->id,
            'result' => $result,
        ]);
    }

    protected function addDefect(LostWaxTree $tree, string $stage, int $defectQty, string $occurredAt, ?string $voidedAt = null): LostWaxTreeDefect
    {
        $d = new LostWaxTreeDefect([
            'lost_wax_tree_id' => $tree->id,
            'stage' => $stage,
            'defect_context' => LostWaxTreeDefect::CONTEXT_PRE_SIAP_COR,
            'defect_qty' => $defectQty,
            'defect_reason' => 'Defect test',
            'recorded_by' => $this->adminUser->id,
            'occurred_at' => Carbon::parse($occurredAt),
        ]);

        if ($voidedAt !== null) {
            $d->voided_at = Carbon::parse($voidedAt);
            $d->voided_by = $this->adminUser->id;
            $d->void_reason = 'Void test';
        }

        $d->save();

        return $d;
    }

    protected function createCastingResult(string $code, array $heatsData): void
    {
        static $cSeq = 1;

        foreach ($heatsData as $h) {
            $id = $cSeq++;
            $treeId = $h['tree_id'] ?? null;
            if (! $treeId) {
                $plan = ProductionPlan::where('code', $code)->first() ?? $this->createPlan(['code' => $code]);
                $tree = $this->createTree($plan, ['production_date' => $h['cast_date'] ?? '2026-10-15', 'quantity' => $h['gross_pcs'] ?? 100]);
                $treeId = $tree->id;
            }

            $cr = LostWaxCastingResult::create([
                'heat_number' => $h['heat_number'] ?? 'HEAT-LW-'.$id,
                'cast_date' => $h['cast_date'] ?? '2026-10-15',
                'furnace' => 'F1',
                'shift' => '1',
                'total_trees' => count($h['trees'] ?? [1]),
                'total_pcs' => $h['gross_pcs'] ?? 100,
                'status' => $h['status'] ?? 'COMPLETED',
                'recorded_by' => $this->adminUser->id,
            ]);

            $gross = $h['gross_pcs'] ?? 100;
            $defect = $h['defect_pcs'] ?? 0;
            $good = $h['good_pcs'] ?? max(0, $gross - $defect);

            LostWaxCastingResultTree::create([
                'lost_wax_casting_result_id' => $cr->id,
                'lost_wax_tree_id' => $treeId,
                'active_tree_id' => $treeId,
                'barcode' => 'BC-'.$id,
                'production_code' => $code,
                'customer' => 'PT SINAR METAL',
                'item_name' => 'FLANGE TEST',
                'quantity_pcs' => $good,
                'gross_pcs' => $gross,
                'defect_pcs' => $defect,
            ]);
        }
    }

    /**
     * Requirement 1: Satu Kode Produksi menghasilkan satu baris.
     */
    public function test_01_satu_kode_produksi_satu_baris(): void
    {
        $plan = $this->createPlan(['code' => 'LW-SINGLE-01']);
        $this->createPrintExecution($plan, ['execution_date' => '2026-10-02', 'qty_good' => 100]);
        $tree1 = $this->createTree($plan, ['production_date' => '2026-10-03', 'quantity' => 50]);
        $tree2 = $this->createTree($plan, ['production_date' => '2026-10-04', 'quantity' => 50]);

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $matched = collect($data['pergerakan'])->where('code', 'LW-SINGLE-01');

        $this->assertCount(1, $matched);
    }

    /**
     * Requirement 2: Banyak Heat Number dalam satu Kode Produksi diagregasi.
     */
    public function test_02_banyak_heat_number_dalam_satu_kode_produksi_diagregasi(): void
    {
        $plan = $this->createPlan(['code' => 'LW-MULTI-HEAT']);
        $this->createCastingResult('LW-MULTI-HEAT', [
            ['heat_number' => 'HEAT-A', 'cast_date' => '2026-10-05', 'gross_pcs' => 100, 'defect_pcs' => 5, 'good_pcs' => 95],
            ['heat_number' => 'HEAT-B', 'cast_date' => '2026-10-06', 'gross_pcs' => 150, 'defect_pcs' => 10, 'good_pcs' => 140],
        ]);

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $row = collect($data['pergerakan'])->firstWhere('code', 'LW-MULTI-HEAT');

        $this->assertNotNull($row);
        $this->assertEquals(250, $row['stages']['cor']['hasil']);
        $this->assertEquals(15, $row['stages']['cor']['rusak']);
        $this->assertEquals(235, $row['stages']['cor']['output']);
    }

    /**
     * Requirement 3: Cetak teragregasi melalui ProductionPlan/PrintOrderLine.
     */
    public function test_03_cetak_teragregasi_melalui_production_plan_atau_print_order_line(): void
    {
        $plan = $this->createPlan(['code' => 'LW-CETAK-AGG']);
        $this->createPrintExecution($plan, ['execution_date' => '2026-10-02', 'qty_gross_output' => 300, 'qty_good' => 290, 'qty_defect' => 10]);
        $this->createPrintExecution($plan, ['execution_date' => '2026-10-03', 'qty_gross_output' => 200, 'qty_good' => 195, 'qty_defect' => 5]);

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $row = collect($data['pergerakan'])->firstWhere('code', 'LW-CETAK-AGG');

        $this->assertNotNull($row);
        $this->assertEquals(500, $row['stages']['cetak']['hasil']);
        $this->assertEquals(15, $row['stages']['cetak']['rusak']);
        $this->assertEquals(485, $row['stages']['cetak']['output']);
    }

    /**
     * Requirement 4: Rangkai menggunakan Tree Allocation tanpa duplikasi.
     */
    public function test_04_rangkai_menggunakan_tree_allocation_tanpa_duplikasi(): void
    {
        $plan = $this->createPlan(['code' => 'LW-RANGKAI-ALLOC']);
        $tree1 = $this->createTree($plan, ['production_date' => '2026-10-02', 'quantity' => 16, 'allocated_qty' => 16]);
        $tree2 = $this->createTree($plan, ['production_date' => '2026-10-03', 'quantity' => 16, 'allocated_qty' => 16]);
        $this->addDefect($tree1, 'assembly', 2, '2026-10-02 11:00:00');

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $row = collect($data['pergerakan'])->firstWhere('code', 'LW-RANGKAI-ALLOC');

        $this->assertNotNull($row);
        $this->assertEquals(32, $row['stages']['rangkai']['hasil']);
        $this->assertEquals(2, $row['stages']['rangkai']['rusak']);
        $this->assertEquals(30, $row['stages']['rangkai']['output']);
    }

    /**
     * Requirement 5: Lapisan 1–6 hanya menghitung scan success.
     */
    public function test_05_lapisan_1_sampai_6_hanya_menghitung_scan_success(): void
    {
        $plan = $this->createPlan(['code' => 'LW-SCAN-SUCCESS']);
        $tree = $this->createTree($plan, ['production_date' => '2026-10-02', 'allocated_qty' => 20]);
        $this->addScan($tree, 'layer_1', '2026-10-03 10:00:00', 'success');
        $this->addScan($tree, 'layer_2', '2026-10-04 10:00:00', 'success');

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $row = collect($data['pergerakan'])->firstWhere('code', 'LW-SCAN-SUCCESS');

        $this->assertEquals(20, $row['stages']['layer_1']['hasil']);
        $this->assertEquals(20, $row['stages']['layer_2']['hasil']);
        $this->assertNull($row['stages']['layer_3']['hasil']);
    }

    /**
     * Requirement 6: Lapisan 7 tidak ikut laporan.
     */
    public function test_06_lapisan_7_tidak_ikut_laporan(): void
    {
        $plan = $this->createPlan(['code' => 'LW-NO-LAYER7']);
        $tree = $this->createTree($plan, ['production_date' => '2026-10-02', 'allocated_qty' => 15]);
        $this->addScan($tree, 'layer_7', '2026-10-03 10:00:00', 'success');

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $row = collect($data['pergerakan'])->firstWhere('code', 'LW-NO-LAYER7');

        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('layer_7', $row['stages']);
    }

    /**
     * Requirement 7: Scan rejected tidak dihitung.
     */
    public function test_07_scan_rejected_tidak_dihitung(): void
    {
        $plan = $this->createPlan(['code' => 'LW-REJECTED-SCAN']);
        $tree = $this->createTree($plan, ['production_date' => '2026-10-02', 'allocated_qty' => 15]);
        $this->addScan($tree, 'layer_1', '2026-10-03 10:00:00', 'rejected');

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $row = collect($data['pergerakan'])->firstWhere('code', 'LW-REJECTED-SCAN');

        $this->assertNotNull($row);
        $this->assertNull($row['stages']['layer_1']['hasil']);
    }

    /**
     * Requirement 8: Scan sukses berulang tidak menggandakan kuantitas.
     */
    public function test_08_scan_sukses_berulang_tidak_menggandakan_kuantitas(): void
    {
        $plan = $this->createPlan(['code' => 'LW-REPEAT-SCAN']);
        $tree = $this->createTree($plan, ['production_date' => '2026-10-02', 'allocated_qty' => 25]);
        // Duplicate success scans
        $this->addScan($tree, 'layer_1', '2026-10-03 10:00:00', 'success');
        $this->addScan($tree, 'layer_1', '2026-10-03 10:05:00', 'success');

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $row = collect($data['pergerakan'])->firstWhere('code', 'LW-REPEAT-SCAN');

        $this->assertEquals(25, $row['stages']['layer_1']['hasil']);
    }

    /**
     * Requirement 9: Oven dan Siap Cor dihitung satu kali per Tree.
     */
    public function test_09_oven_dan_siap_cor_dihitung_satu_kali_per_tree(): void
    {
        $plan = $this->createPlan(['code' => 'LW-OVEN-DEDUP']);
        $tree = $this->createTree($plan, ['production_date' => '2026-10-02', 'allocated_qty' => 30]);
        // Both oven and siap_cor scans
        $this->addScan($tree, 'oven', '2026-10-10 08:00:00', 'success');
        $this->addScan($tree, 'siap_cor', '2026-10-10 14:00:00', 'success');

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $row = collect($data['pergerakan'])->firstWhere('code', 'LW-OVEN-DEDUP');

        $this->assertEquals(30, $row['stages']['oven']['hasil']);
    }

    /**
     * Requirement 10: Oven Oktober dan Siap Cor November tetap tercatat sebagai aktivitas Oven Oktober.
     */
    public function test_10_oven_oktober_dan_siap_cor_november_tetap_tercatat_sebagai_aktivitas_oven_oktober(): void
    {
        $plan = $this->createPlan(['code' => 'LW-OVEN-OCT-NOV']);
        $tree = $this->createTree($plan, ['production_date' => '2026-10-02', 'allocated_qty' => 20]);
        $this->addScan($tree, 'oven', '2026-10-25 09:00:00', 'success');
        $this->addScan($tree, 'siap_cor', '2026-11-02 10:00:00', 'success');

        // October report: must count the tree in Oven
        $dataOct = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $rowOct = collect($dataOct['pergerakan'])->firstWhere('code', 'LW-OVEN-OCT-NOV');

        $this->assertNotNull($rowOct);
        $this->assertEquals(20, $rowOct['stages']['oven']['hasil']);
    }

    /**
     * Requirement 11: Defect yang di-void setelah cutoff tetap dihitung pada cutoff sebelumnya.
     */
    public function test_11_defect_yang_di_void_setelah_cutoff_tetap_dihitung_pada_cutoff_sebelumnya(): void
    {
        $plan = $this->createPlan(['code' => 'LW-VOID-LATER']);
        $tree = $this->createTree($plan, ['production_date' => '2026-10-02', 'allocated_qty' => 50]);
        $this->addScan($tree, 'layer_1', '2026-10-05 10:00:00', 'success');
        // Defect occurred on Oct 20, voided on Nov 05
        $this->addDefect($tree, 'layer_1', 5, '2026-10-20 14:00:00', '2026-11-05 10:00:00');

        $dataOct = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $rowOct = collect($dataOct['pergerakan'])->firstWhere('code', 'LW-VOID-LATER');

        $this->assertEquals(5, $rowOct['stages']['layer_1']['rusak']);
    }

    /**
     * Requirement 12: Defect yang di-void sebelum cutoff tidak dihitung.
     */
    public function test_12_defect_yang_di_void_sebelum_cutoff_tidak_dihitung(): void
    {
        $plan = $this->createPlan(['code' => 'LW-VOID-EARLY']);
        $tree = $this->createTree($plan, ['production_date' => '2026-10-02', 'allocated_qty' => 50]);
        $this->addScan($tree, 'layer_1', '2026-10-05 10:00:00', 'success');
        // Defect occurred on Oct 20, voided on Oct 25 (before cutoff Oct 31)
        $this->addDefect($tree, 'layer_1', 5, '2026-10-20 14:00:00', '2026-10-25 10:00:00');

        $dataOct = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $rowOct = collect($dataOct['pergerakan'])->firstWhere('code', 'LW-VOID-EARLY');

        $this->assertEquals(0, $rowOct['stages']['layer_1']['rusak']);
    }

    /**
     * Requirement 13: Hasil Cor menghitung gross dan defect secara benar.
     */
    public function test_13_hasil_cor_menghitung_gross_dan_defect_secara_benar(): void
    {
        $plan = $this->createPlan(['code' => 'LW-COR-CALC']);
        $this->createCastingResult('LW-COR-CALC', [
            ['heat_number' => 'HEAT-CALC', 'cast_date' => '2026-10-10', 'gross_pcs' => 120, 'defect_pcs' => 15, 'good_pcs' => 105],
        ]);

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $row = collect($data['pergerakan'])->firstWhere('code', 'LW-COR-CALC');

        $this->assertEquals(120, $row['stages']['cor']['hasil']);
        $this->assertEquals(15, $row['stages']['cor']['rusak']);
        $this->assertEquals(105, $row['stages']['cor']['output']);
    }

    /**
     * Requirement 14: Beberapa Heat Number pada satu Kode Produksi diagregasi di Hasil Cor.
     */
    public function test_14_beberapa_heat_number_pada_satu_kode_produksi_diagregasi_di_hasil_cor(): void
    {
        $plan = $this->createPlan(['code' => 'LW-COR-MULTI']);
        $this->createCastingResult('LW-COR-MULTI', [
            ['heat_number' => 'H1', 'cast_date' => '2026-10-10', 'gross_pcs' => 60, 'defect_pcs' => 2, 'good_pcs' => 58],
            ['heat_number' => 'H2', 'cast_date' => '2026-10-12', 'gross_pcs' => 80, 'defect_pcs' => 3, 'good_pcs' => 77],
            ['heat_number' => 'H3', 'cast_date' => '2026-10-14', 'gross_pcs' => 100, 'defect_pcs' => 5, 'good_pcs' => 95],
        ]);

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $row = collect($data['pergerakan'])->firstWhere('code', 'LW-COR-MULTI');

        $this->assertEquals(240, $row['stages']['cor']['hasil']);
        $this->assertEquals(10, $row['stages']['cor']['rusak']);
        $this->assertEquals(230, $row['stages']['cor']['output']);
    }

    /**
     * Requirement 15: P.O dan Plan tidak diduplikasi.
     */
    public function test_15_po_dan_plan_tidak_diduplikasi(): void
    {
        $plan = $this->createPlan(['code' => 'LW-PO-NODUP', 'po_quantity' => 500]);
        // Multiple heats for this code
        $this->createCastingResult('LW-PO-NODUP', [
            ['heat_number' => 'H-A', 'cast_date' => '2026-10-05', 'gross_pcs' => 100],
            ['heat_number' => 'H-B', 'cast_date' => '2026-10-06', 'gross_pcs' => 100],
        ]);

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $row = collect($data['pergerakan'])->firstWhere('code', 'LW-PO-NODUP');

        $this->assertEquals(500, $row['po_qty']);
        $this->assertEquals(550, $row['qty_planned']);
    }

    /**
     * Requirement 16: Plan dihitung 110% PO.
     */
    public function test_16_plan_dihitung_110_persen_po(): void
    {
        $plan = $this->createPlan(['code' => 'LW-PLAN-110', 'po_quantity' => 1000]);
        $this->createPrintExecution($plan, ['execution_date' => '2026-10-02']);

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $row = collect($data['pergerakan'])->firstWhere('code', 'LW-PLAN-110');

        $this->assertEquals(1100, $row['qty_planned']);
    }

    /**
     * Requirement 17: Weight dan Total Weight tidak double-count.
     */
    public function test_17_weight_dan_total_weight_tidak_double_count(): void
    {
        $plan = $this->createPlan(['code' => 'LW-WT-CALC', 'weight' => 1.50]);
        $this->createCastingResult('LW-WT-CALC', [
            ['heat_number' => 'H-WT1', 'cast_date' => '2026-10-05', 'gross_pcs' => 100, 'defect_pcs' => 10, 'good_pcs' => 90],
            ['heat_number' => 'H-WT2', 'cast_date' => '2026-10-06', 'gross_pcs' => 100, 'defect_pcs' => 20, 'good_pcs' => 80],
        ]);

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $row = collect($data['pergerakan'])->firstWhere('code', 'LW-WT-CALC');

        // Total good = 170. Total weight = 170 * 1.50 = 255.00 kg
        $this->assertEquals(1.50, $row['unit_weight_kg']);
        $this->assertEquals(255.00, $row['total_weight_kg']);
    }

    /**
     * Requirement 18: Tahap belum tercapai ditampilkan strip (null).
     */
    public function test_18_tahap_belum_tercapai_ditampilkan_strip(): void
    {
        $plan = $this->createPlan(['code' => 'LW-UNREACHED']);
        $this->createPrintExecution($plan, ['execution_date' => '2026-10-05', 'qty_good' => 100]);

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $row = collect($data['pergerakan'])->firstWhere('code', 'LW-UNREACHED');

        $this->assertNotNull($row['stages']['cetak']['hasil']);
        $this->assertNull($row['stages']['rangkai']['hasil']);
        $this->assertNull($row['stages']['layer_1']['hasil']);
        $this->assertNull($row['stages']['oven']['hasil']);
        $this->assertNull($row['stages']['cor']['hasil']);
    }

    /**
     * Requirement 19: Tahap selesai tanpa defect menampilkan Rusak 0.
     */
    public function test_19_tahap_selesai_tanpa_defect_menampilkan_rusak_nol(): void
    {
        $plan = $this->createPlan(['code' => 'LW-ZERO-DEFECT']);
        $tree = $this->createTree($plan, ['production_date' => '2026-10-02', 'allocated_qty' => 50]);
        $this->addScan($tree, 'layer_1', '2026-10-05 10:00:00', 'success');

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $row = collect($data['pergerakan'])->firstWhere('code', 'LW-ZERO-DEFECT');

        $this->assertSame(0, $row['stages']['rangkai']['rusak']);
        $this->assertSame(0, $row['stages']['layer_1']['rusak']);
    }

    /**
     * Requirement 20: Historical cutoff menggunakan WIB.
     */
    public function test_20_historical_cutoff_menggunakan_wib(): void
    {
        $plan = $this->createPlan(['code' => 'LW-WIB-CUTOFF']);
        $tree = $this->createTree($plan, ['production_date' => '2026-10-02', 'allocated_qty' => 20]);
        // Scan executed inside October WIB (2026-10-31 23:30:00)
        $this->addScan($tree, 'layer_1', '2026-10-31 23:30:00', 'success');
        // Scan executed outside October WIB (2026-11-01 00:05:00)
        $this->addScan($tree, 'layer_2', '2026-11-01 00:05:00', 'success');

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $row = collect($data['pergerakan'])->firstWhere('code', 'LW-WIB-CUTOFF');

        $this->assertEquals(20, $row['stages']['layer_1']['hasil']);
        $this->assertNull($row['stages']['layer_2']['hasil']);
    }

    /**
     * Requirement 21: Aktivitas periode sebelumnya yang baru dicor dalam periode berjalan tetap masuk.
     */
    public function test_21_aktivitas_periode_sebelumnya_yang_baru_dicor_dalam_periode_berjalan_tetap_masuk(): void
    {
        $plan = $this->createPlan(['code' => 'LW-PREV-COR']);
        // Prior stages happened in September
        $this->createPrintExecution($plan, ['execution_date' => '2026-09-15', 'qty_good' => 100]);
        $tree = $this->createTree($plan, ['production_date' => '2026-09-18', 'allocated_qty' => 100]);
        $this->addScan($tree, 'oven', '2026-09-25 10:00:00', 'success');
        // Poured in October
        $this->createCastingResult('LW-PREV-COR', [
            ['heat_number' => 'HEAT-OCT-POUR', 'cast_date' => '2026-10-05', 'gross_pcs' => 100, 'good_pcs' => 100],
        ]);

        // October report: must include LW-PREV-COR
        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $row = collect($data['pergerakan'])->firstWhere('code', 'LW-PREV-COR');

        $this->assertNotNull($row);
        $this->assertEquals(100, $row['stages']['cor']['hasil']);
    }

    /**
     * Requirement 22: Kode Produksi tanpa aktivitas relevan tidak ikut.
     */
    public function test_22_kode_produksi_tanpa_aktivitas_relevan_tidak_ikut(): void
    {
        $plan = $this->createPlan(['code' => 'LW-INACTIVE']);
        // Activity completely done in August
        $this->createPrintExecution($plan, ['execution_date' => '2026-08-10', 'qty_good' => 50]);
        $this->createCastingResult('LW-INACTIVE', [
            ['heat_number' => 'HEAT-AUG', 'cast_date' => '2026-08-15', 'gross_pcs' => 50],
        ]);

        // October report: LW-INACTIVE must NOT be present
        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $row = collect($data['pergerakan'])->firstWhere('code', 'LW-INACTIVE');

        $this->assertNull($row);
    }

    /**
     * Requirement 23: Export Excel menghasilkan 29 kolom.
     */
    public function test_23_export_excel_menghasilkan_29_kolom(): void
    {
        $plan = $this->createPlan(['code' => 'LW-EXCEL-29']);
        $this->createPrintExecution($plan, ['execution_date' => '2026-10-05', 'qty_good' => 100]);

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $spreadsheet = $this->reportService->buildExcelSpreadsheet($data);
        $sheet = $spreadsheet->getActiveSheet();

        // 29 columns: A to AC
        $this->assertEquals('No', $sheet->getCell('A4')->getValue());
        $this->assertEquals('Kode Produksi', $sheet->getCell('B4')->getValue());
        $this->assertEquals('Nama Item', $sheet->getCell('C4')->getValue());
        $this->assertEquals('AISI', $sheet->getCell('D4')->getValue());
        $this->assertEquals('Customer', $sheet->getCell('E4')->getValue());
        $this->assertEquals('P.O (PCS)', $sheet->getCell('F4')->getValue());
        $this->assertEquals('Plan (PCS)', $sheet->getCell('G4')->getValue());
        $this->assertEquals('Weight (KG/PCS)', $sheet->getCell('H4')->getValue());
        $this->assertEquals('Total Weight (KG)', $sheet->getCell('I4')->getValue());
        $this->assertEquals('CETAK', $sheet->getCell('J4')->getValue());
        $this->assertEquals('RANGKAI', $sheet->getCell('L4')->getValue());
        $this->assertEquals('LAPISAN 1', $sheet->getCell('N4')->getValue());
        $this->assertEquals('LAPISAN 2', $sheet->getCell('P4')->getValue());
        $this->assertEquals('LAPISAN 3', $sheet->getCell('R4')->getValue());
        $this->assertEquals('LAPISAN 4', $sheet->getCell('T4')->getValue());
        $this->assertEquals('LAPISAN 5', $sheet->getCell('V4')->getValue());
        $this->assertEquals('LAPISAN 6', $sheet->getCell('X4')->getValue());
        $this->assertEquals('OVEN', $sheet->getCell('Z4')->getValue());
        $this->assertEquals('HASIL COR', $sheet->getCell('AB4')->getValue());
    }

    /**
     * Requirement 24: Format angka, freeze pane, autofilter, dan lebar kolom sesuai.
     */
    public function test_24_format_angka_freeze_pane_autofilter_dan_lebar_kolom_sesuai(): void
    {
        $plan = $this->createPlan(['code' => 'LW-EXCEL-FMT', 'po_quantity' => 1000, 'weight' => 2.50]);
        $this->createCastingResult('LW-EXCEL-FMT', [
            ['heat_number' => 'HEAT-FMT', 'cast_date' => '2026-10-05', 'gross_pcs' => 100, 'good_pcs' => 100],
        ]);

        $data = $this->reportService->getAccountingReportData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $spreadsheet = $this->reportService->buildExcelSpreadsheet($data);
        $sheet = $spreadsheet->getActiveSheet();

        // Freeze pane J6
        $this->assertEquals('J6', $sheet->getFreezePane());

        // AutoFilter A5:AC5
        $this->assertEquals('A5:AC5', $sheet->getAutoFilter()->getRange());

        // Column widths
        $this->assertEquals(6, $sheet->getColumnDimension('A')->getWidth());
        $this->assertEquals(15, $sheet->getColumnDimension('B')->getWidth());
        $this->assertEquals(32, $sheet->getColumnDimension('C')->getWidth());
        $this->assertEquals(8, $sheet->getColumnDimension('D')->getWidth());
        $this->assertEquals(18, $sheet->getColumnDimension('E')->getWidth());
        $this->assertEquals(12, $sheet->getColumnDimension('F')->getWidth());
        $this->assertEquals(12, $sheet->getColumnDimension('G')->getWidth());
        $this->assertEquals(14, $sheet->getColumnDimension('H')->getWidth());
        $this->assertEquals(16, $sheet->getColumnDimension('I')->getWidth());
        $this->assertEquals(8.5, $sheet->getColumnDimension('J')->getWidth());
        $this->assertEquals(8.5, $sheet->getColumnDimension('AC')->getWidth());

        // WrapText on Column C
        $this->assertTrue($sheet->getStyle('C6')->getAlignment()->getWrapText());
    }

    /**
     * Requirement 25: Web UI renders horizontal production tracking with 29 columns.
     */
    public function test_25_web_ui_renders_successfully(): void
    {
        $plan = $this->createPlan(['code' => 'LW-UI-TEST', 'po_quantity' => 1000]);
        $this->createPrintExecution($plan, ['execution_date' => '2026-10-05', 'qty_good' => 100]);

        $response = $this->actingAs($this->auditorUser)->get('/lost-wax/report/accounting?date_from=2026-10-01&date_to=2026-10-31');
        $response->assertStatus(200);
        $response->assertSee('LAPORAN ACCOUNTING');
        $response->assertSee('LW-UI-TEST');
        $response->assertSee('Ringkas per Kode Produksi');
        $response->assertSee('P.O (PCS)');
        $response->assertSee('Plan (PCS)');
        $response->assertSee('1.000');
        $response->assertSee('1.100');
    }

    /**
     * Requirement 26: Excel export downloads valid spreadsheet.
     */
    public function test_26_excel_export_download_response(): void
    {
        $plan = $this->createPlan(['code' => 'LW-EXCEL-DL']);
        $this->createPrintExecution($plan, ['execution_date' => '2026-10-05', 'qty_good' => 100]);

        $response = $this->actingAs($this->auditorUser)->get('/lost-wax/report/accounting/export/excel?date_from=2026-10-01&date_to=2026-10-31');
        $response->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml.sheet', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Laporan_Accounting_Lost_Wax_Ringkas_2026-10-01_2026-10-31.xlsx', (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * Requirement 27: PDF print view renders printable landscape report.
     */
    public function test_27_pdf_export_renders_successfully(): void
    {
        $plan = $this->createPlan(['code' => 'LW-PDF-TEST']);
        $this->createPrintExecution($plan, ['execution_date' => '2026-10-05', 'qty_good' => 100]);

        $response = $this->actingAs($this->auditorUser)->get('/lost-wax/report/accounting/export/pdf?date_from=2026-10-01&date_to=2026-10-31');
        $response->assertStatus(200);
        $response->assertSee('Laporan Accounting Lost Wax');
        $response->assertSee('LW-PDF-TEST');
    }
}
