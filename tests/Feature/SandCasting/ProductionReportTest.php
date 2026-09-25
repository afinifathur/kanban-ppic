<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use App\Services\SandCasting\SandCastingProductionReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductionReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $ppicUser;

    protected User $qcUser;

    protected User $spvUser;

    protected SandCastingProductionReportService $reportService;

    protected function setUp(): void
    {
        parent::setUp();

        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution']);

        $roleAdmin = Role::firstOrCreate(['name' => 'admin']);
        $roleAdmin->givePermissionTo([$permPlanning, $permExecution]);

        $rolePpic = Role::firstOrCreate(['name' => 'ppic']);
        $rolePpic->givePermissionTo([$permPlanning, $permExecution]);

        $roleQc = Role::firstOrCreate(['name' => 'qc']);
        $roleQc->givePermissionTo([$permExecution]);

        $roleSpv = Role::firstOrCreate(['name' => 'spv']);
        $roleSpv->givePermissionTo([$permExecution]);

        $this->adminUser = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@peroniks.com',
        ]);
        $this->adminUser->assignRole('admin');

        $this->ppicUser = User::factory()->create([
            'name' => 'Admin PPIC Flange',
            'email' => 'adminppicfl@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->ppicUser->assignRole('ppic');

        $this->qcUser = User::factory()->create([
            'name' => 'Admin QC Flange',
            'email' => 'adminqcflange@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->qcUser->assignRole('qc');

        $this->spvUser = User::factory()->create([
            'name' => 'SPV Netto',
            'email' => 'spvnettofl@peroniks.com',
            'assigned_stage' => 'netto',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvUser->assignRole('spv');

        $this->reportService = new SandCastingProductionReportService;
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        static $planSeq = 1;
        $seq = $planSeq++;

        return ProductionPlan::create(array_merge([
            'code' => '268ET'.$seq,
            'title' => 'Rencana SC '.$seq,
            'item_code' => '4.'.$seq,
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-'.$seq,
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'customer' => 'PT SINAR METAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ], $attributes));
    }

    protected function createKtrLine(array $attributes = [], ?ProductionPlan $plan = null, ?string $castDate = null): SandCastingCastingResultLine
    {
        static $ktrCounter = 1;
        $seq = $ktrCounter++;

        $plan = $plan ?? $this->createPlan();

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-'.now()->format('ymd').'-'.sprintf('%05d', $seq),
            'scheduled_date' => $castDate ?? now()->toDateString(),
            'status' => 'ISSUED',
            'notes' => 'Test order',
            'created_by' => $this->adminUser->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '2"',
            'aisi' => 'FC250',
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 250.00,
            'status' => 'issued',
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'HN-'.now()->format('ymd').'-'.sprintf('%05d', $seq),
            'cast_date' => $castDate ?? now()->toDateString(),
            'furnace' => 'F1',
            'shift' => '1',
            'operator_name' => 'Budi Cor',
            'recorded_by' => $this->adminUser->id,
        ]);

        $travelerNumber = 'KTR-'.now()->format('Ymd').'-'.sprintf('%06d', $seq);

        return $result->lines()->create(array_merge([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $travelerNumber,
            'qty_good' => 100,
            'qty_reject' => 0,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 250.00,
            'current_stage' => 'netto',
        ], $attributes));
    }

    /**
     * TEST 1 & 2: Authorization & Unauthenticated redirect
     */
    public function test_1_and_2_authorization_and_unauthenticated_redirect(): void
    {
        // Unauthenticated -> redirect to login
        $this->get('/sand-casting/report/production')->assertRedirect(route('login'));

        // Authorized roles
        $this->actingAs($this->adminUser)->get('/sand-casting/report/production')->assertStatus(200);
        $this->actingAs($this->ppicUser)->get('/sand-casting/report/production')->assertStatus(200);
        $this->actingAs($this->qcUser)->get('/sand-casting/report/production')->assertStatus(200);
        $this->actingAs($this->spvUser)->get('/sand-casting/report/production')->assertStatus(200);
    }

    /**
     * TEST 3 & 4: Single-day & Date-range filter
     */
    public function test_3_and_4_single_day_and_date_range_filter(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDays(1)->toDateString();

        $lineToday = $this->createKtrLine(['qty_good' => 50], null, $today);
        $lineYesterday = $this->createKtrLine(['qty_good' => 70], null, $yesterday);

        // Single-day (today only)
        $dataToday = $this->reportService->getProductionDataset(['date_from' => $today, 'date_to' => $today]);
        $corToday = $dataToday['stage_summaries']->where('stage', 'cor')->first();
        $this->assertEquals(1, $corToday['ktr_count']);
        $this->assertEquals(50, $corToday['good_pcs']);

        // Date-range (yesterday to today)
        $dataRange = $this->reportService->getProductionDataset(['date_from' => $yesterday, 'date_to' => $today]);
        $corRange = $dataRange['stage_summaries']->where('stage', 'cor')->first();
        $this->assertEquals(2, $corRange['ktr_count']);
        $this->assertEquals(120, $corRange['good_pcs']);
    }

    /**
     * TEST 5: Stage filter
     */
    public function test_5_stage_filter(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100]);
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 5,
            'good_qty' => 95,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        // Filter stage = netto
        $dataNetto = $this->reportService->getProductionDataset(['stage' => 'netto']);
        $this->assertCount(1, $dataNetto['stage_summaries']);
        $this->assertEquals('netto', $dataNetto['stage_summaries']->first()['stage']);
        $this->assertEquals(95, $dataNetto['stage_summaries']->first()['good_pcs']);

        // Filter stage = all (7 stages returned)
        $dataAll = $this->reportService->getProductionDataset(['stage' => 'all']);
        $this->assertCount(7, $dataAll['stage_summaries']);
    }

    /**
     * TEST 6: Search filter
     */
    public function test_6_search_filter(): void
    {
        $plan = $this->createPlan(['code' => '268ET999', 'item_name' => 'SPECIAL FLANGE SS']);
        $line = $this->createKtrLine(['qty_good' => 100], $plan);

        $dataFound = $this->reportService->getProductionDataset(['search' => 'SPECIAL FLANGE']);
        $this->assertCount(1, $dataFound['items']);
        $this->assertEquals('SPECIAL FLANGE SS', $dataFound['items']->first()['item_name']);

        $dataNotFound = $this->reportService->getProductionDataset(['search' => 'NONEXISTENT_ITEM']);
        $this->assertCount(0, $dataNotFound['items']);
    }

    /**
     * TEST 7, 8, 9, 11, 12, 13: Stage Calculations for Cor, Netto, OD, Bor, QC, Gudang
     */
    public function test_7_8_9_11_12_13_stage_calculations(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'qty_reject' => 10, 'unit_weight_kg' => 2.00]);

        // Netto: In 100, Def 5, Good 95
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 5,
            'good_qty' => 95,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        // Bubut OD: In 95, Def 3, Good 92
        $line->stageExecutions()->create([
            'stage' => 'bubut_od',
            'checkpoint_code' => 'OD_TURNING',
            'input_qty' => 95,
            'defect_qty' => 3,
            'good_qty' => 92,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        // Bor: In 92, Def 2, Good 90
        $line->stageExecutions()->create([
            'stage' => 'bor',
            'checkpoint_code' => 'BOR_DRILLING',
            'input_qty' => 92,
            'defect_qty' => 2,
            'good_qty' => 90,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        // QC: In 90, Def 1, Good 89
        $line->stageExecutions()->create([
            'stage' => 'qc',
            'checkpoint_code' => 'QC_FINAL_INSPECTION',
            'input_qty' => 90,
            'defect_qty' => 1,
            'good_qty' => 89,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        // Gudang: In 89, Def 0, Good 89
        $line->stageExecutions()->create([
            'stage' => 'gudang_jadi',
            'checkpoint_code' => 'GUDANG_RECEIVE',
            'input_qty' => 89,
            'defect_qty' => 0,
            'good_qty' => 89,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        $data = $this->reportService->getProductionDataset();
        $stages = $data['stage_summaries']->keyBy('stage');

        // Cor
        $this->assertEquals(110, $stages['cor']['input_pcs']);
        $this->assertEquals(10, $stages['cor']['defect_pcs']);
        $this->assertEquals(100, $stages['cor']['good_pcs']);

        // Netto
        $this->assertEquals(100, $stages['netto']['input_pcs']);
        $this->assertEquals(5, $stages['netto']['defect_pcs']);
        $this->assertEquals(95, $stages['netto']['good_pcs']);

        // Bubut OD
        $this->assertEquals(95, $stages['bubut_od']['input_pcs']);
        $this->assertEquals(3, $stages['bubut_od']['defect_pcs']);
        $this->assertEquals(92, $stages['bubut_od']['good_pcs']);

        // Bor
        $this->assertEquals(92, $stages['bor']['input_pcs']);
        $this->assertEquals(2, $stages['bor']['defect_pcs']);
        $this->assertEquals(90, $stages['bor']['good_pcs']);

        // QC
        $this->assertEquals(90, $stages['qc']['input_pcs']);
        $this->assertEquals(1, $stages['qc']['defect_pcs']);
        $this->assertEquals(89, $stages['qc']['good_pcs']);

        // Gudang Jadi
        $this->assertEquals(89, $stages['gudang_jadi']['input_pcs']);
        $this->assertEquals(0, $stages['gudang_jadi']['defect_pcs']);
        $this->assertEquals(89, $stages['gudang_jadi']['good_pcs']);
    }

    /**
     * TEST 10 & 18: CNC Anti-Double-Count & Single Department Handling for 3 Checkpoints
     */
    public function test_10_and_18_cnc_anti_double_count_and_single_department(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100]);

        // Checkpoint 1: CNC_MACHINING (Input 100, Def 2, Good 98)
        $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 100,
            'defect_qty' => 2,
            'good_qty' => 98,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now()->subMinutes(30),
            'executed_at' => now()->subMinutes(30),
            'operator_id' => $this->spvUser->id,
        ]);

        // Checkpoint 2: QC_POST_CNC (Input 98, Def 1, Good 97)
        $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'QC_POST_CNC',
            'input_qty' => 98,
            'defect_qty' => 1,
            'good_qty' => 97,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now()->subMinutes(20),
            'executed_at' => now()->subMinutes(20),
            'operator_id' => $this->spvUser->id,
        ]);

        // Checkpoint 3: QC_PRE_BOR (Input 97, Def 0, Good 97)
        $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'QC_PRE_BOR',
            'input_qty' => 97,
            'defect_qty' => 0,
            'good_qty' => 97,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now()->subMinutes(10),
            'executed_at' => now()->subMinutes(10),
            'operator_id' => $this->spvUser->id,
        ]);

        $data = $this->reportService->getProductionDataset();
        $cncSummary = $data['stage_summaries']->where('stage', 'bubut_cnc')->first();

        // Must count as 1 KTR, Initial Input = 100 (NOT 295!), Defect = 3, Good = 97
        $this->assertEquals(1, $cncSummary['ktr_count']);
        $this->assertEquals(100, $cncSummary['input_pcs']);
        $this->assertEquals(3, $cncSummary['defect_pcs']);
        $this->assertEquals(97, $cncSummary['good_pcs']);

        // In detail table, all 3 checkpoints are listed
        $cncDetails = $data['items']->where('stage', 'bubut_cnc');
        $this->assertCount(3, $cncDetails);
    }

    /**
     * TEST 14, 15, 16: Defect, Good, and Zero Defect calculations
     */
    public function test_14_15_16_defect_good_and_zero_defect_calculations(): void
    {
        $line = $this->createKtrLine(['qty_good' => 80]);
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 80,
            'defect_qty' => 0, // Zero defect!
            'good_qty' => 80,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        $data = $this->reportService->getProductionDataset();
        $netto = $data['stage_summaries']->where('stage', 'netto')->first();

        $this->assertEquals(80, $netto['input_pcs']);
        $this->assertEquals(0, $netto['defect_pcs']);
        $this->assertEquals(80, $netto['good_pcs']);
        $this->assertEquals(0.0, $netto['defect_rate']);
    }

    /**
     * TEST 17: Product scope isolation
     */
    public function test_17_product_scope_isolation(): void
    {
        $planFlange = $this->createPlan(['product_scope' => 'FLANGE_BESI']);
        $planFitting = $this->createPlan(['product_scope' => 'FITTING_BESI']);

        $lineFlange = $this->createKtrLine(['qty_good' => 100], $planFlange);
        $lineFitting = $this->createKtrLine(['qty_good' => 50], $planFitting);

        // PPIC user has scope FLANGE_BESI
        $dataScoped = $this->reportService->getProductionDataset([], $this->ppicUser);
        $corScoped = $dataScoped['stage_summaries']->where('stage', 'cor')->first();

        $this->assertEquals(1, $corScoped['ktr_count']);
        $this->assertEquals(100, $corScoped['good_pcs']);

        // Admin has no scope restriction -> sees both (150 pcs)
        $dataUnscoped = $this->reportService->getProductionDataset([], $this->adminUser);
        $corUnscoped = $dataUnscoped['stage_summaries']->where('stage', 'cor')->first();

        $this->assertEquals(2, $corUnscoped['ktr_count']);
        $this->assertEquals(150, $corUnscoped['good_pcs']);
    }

    /**
     * TEST 19 & 20: Export Excel & PDF / Print
     */
    public function test_19_and_20_export_excel_and_pdf_print(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100]);

        // PDF Print view
        $responsePdf = $this->actingAs($this->adminUser)->get('/sand-casting/report/production/export/pdf');
        $responsePdf->assertStatus(200);
        $responsePdf->assertSee('REPORT PRODUKSI SAND CASTING');
        $responsePdf->assertSee('TOTAL / RINGKASAN');
        $responsePdf->assertSee('Total Aktivitas KTR');
        $responsePdf->assertSee('Total Aktivitas Input PCS');
        $responsePdf->assertSee('Total Defect (Rusak)');
        $responsePdf->assertSee('Total Berat Aktivitas');
        $responsePdf->assertSee('Cetak Dokumen (Print / Save as PDF)');
        $responsePdf->assertSee('no-print');

        // Verify absence of auto-trigger print and external resources
        $responsePdf->assertDontSee('window.onload', false);
        $responsePdf->assertDontSee('addEventListener(\'load\'', false);
        $responsePdf->assertDontSee('DOMContentLoaded', false);
        $responsePdf->assertDontSee('setTimeout', false);
        $responsePdf->assertDontSee('<script', false);
        $responsePdf->assertDontSee('cdn.tailwindcss.com', false);
        $responsePdf->assertDontSee('layouts.app', false);

        // Excel export stream
        $responseExcel = $this->actingAs($this->adminUser)->get('/sand-casting/report/production/export/excel');
        $responseExcel->assertStatus(200);
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $responseExcel->headers->get('Content-Type'));
    }

    /**
     * TEST 21: Read-only check (No database mutations)
     */
    public function test_21_report_does_not_mutate_database(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100]);
        $exec = $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 5,
            'good_qty' => 95,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        $beforeCountExec = SandCastingStageExecution::count();
        $beforeCountLine = SandCastingCastingResultLine::count();

        $this->actingAs($this->adminUser)->get('/sand-casting/report/production');
        $this->actingAs($this->adminUser)->get('/sand-casting/report/production/export/excel');
        $this->actingAs($this->adminUser)->get('/sand-casting/report/production/export/pdf');

        $this->assertEquals($beforeCountExec, SandCastingStageExecution::count());
        $this->assertEquals($beforeCountLine, SandCastingCastingResultLine::count());

        $exec->refresh();
        $this->assertEquals(SandCastingStageExecution::STATUS_CONFIRMED, $exec->status);
    }

    /**
     * TEST 22: PDF Smoke Test with 46 KTRs (UAT Dataset Scale)
     */
    public function test_22_pdf_smoke_test_with_46_ktrs_dataset(): void
    {
        // Generate 46 KTRs with ~2,600 PCS total
        for ($i = 0; $i < 46; $i++) {
            $line = $this->createKtrLine(['qty_good' => 57]);
            $line->stageExecutions()->create([
                'stage' => 'netto',
                'checkpoint_code' => 'NETTO_CUT',
                'input_qty' => 57,
                'defect_qty' => 1,
                'good_qty' => 56,
                'status' => SandCastingStageExecution::STATUS_CONFIRMED,
                'physical_done_at' => now(),
                'executed_at' => now(),
                'operator_id' => $this->spvUser->id,
            ]);
        }

        $responsePdf = $this->actingAs($this->adminUser)->get('/sand-casting/report/production/export/pdf');
        $responsePdf->assertStatus(200);
        $responsePdf->assertSee('REPORT PRODUKSI SAND CASTING');
        $responsePdf->assertSee('Netto');
        $responsePdf->assertSee('46'); // 46 KTRs in Netto
    }
}
