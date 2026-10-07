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

        $this->reportService = app(SandCastingProductionReportService::class);
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
     * TEST A: Valid 1 day filter
     */
    public function test_a_valid_single_day_filter(): void
    {
        $today = now()->toDateString();
        $this->createKtrLine(['qty_good' => 75], null, $today);

        $response = $this->actingAs($this->adminUser)->get("/sand-casting/report/production?date_from={$today}&date_to={$today}&stage=cor");
        $response->assertStatus(200);
        $response->assertSee('75');
    }

    /**
     * TEST B: Valid 45 calendar days (01/09/2026 -> 15/10/2026 PASS)
     */
    public function test_b_valid_45_calendar_days_filter(): void
    {
        $from = '2026-09-01';
        $to = '2026-10-15'; // 30 days in Sept - 1 + 1 = 30 days + 15 in Oct = 45 days

        $response = $this->actingAs($this->adminUser)->get("/sand-casting/report/production?date_from={$from}&date_to={$to}&stage=cor");
        $response->assertStatus(200);
    }

    /**
     * TEST C: Invalid 46 days REJECT
     */
    public function test_c_invalid_46_days_filter_rejected(): void
    {
        $from = '2026-09-01';
        $to = '2026-10-16'; // 46 days

        $response = $this->actingAs($this->adminUser)->get("/sand-casting/report/production?date_from={$from}&date_to={$to}&stage=cor");
        $response->assertSessionHasErrors(['date_to']);

        $responsePdf = $this->actingAs($this->adminUser)->get("/sand-casting/report/production/export/pdf?date_from={$from}&date_to={$to}&stage=cor");
        $responsePdf->assertSessionHasErrors(['date_to']);
    }

    /**
     * TEST D: Invalid 47 days (15/08/2026 -> 30/09/2026 REJECT)
     */
    public function test_d_invalid_47_days_filter_rejected(): void
    {
        $from = '2026-08-15';
        $to = '2026-09-30'; // 17 days in Aug + 30 in Sept = 47 days

        $response = $this->actingAs($this->adminUser)->get("/sand-casting/report/production?date_from={$from}&date_to={$to}&stage=cor");
        $response->assertSessionHasErrors(['date_to']);
    }

    /**
     * TEST E: Stage filter tidak memiliki option "all"
     */
    public function test_e_stage_filter_ui_has_no_all_option(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/sand-casting/report/production');
        $response->assertStatus(200);
        $response->assertDontSee('Semua Tahapan (7 Tahap)', false);
        $response->assertDontSee('value="all"', false);
    }

    /**
     * TEST F: Request tanpa valid stage / stage 'all' ditolak atau tidak menghasilkan full 7-stage report
     */
    public function test_f_request_with_stage_all_or_invalid_stage_is_rejected(): void
    {
        // Stage 'all' is rejected
        $responseAll = $this->actingAs($this->adminUser)->get('/sand-casting/report/production?stage=all');
        $responseAll->assertSessionHasErrors(['stage']);

        // Stage 'invalid_xyz' is rejected
        $responseInvalid = $this->actingAs($this->adminUser)->get('/sand-casting/report/production?stage=invalid_xyz');
        $responseInvalid->assertSessionHasErrors(['stage']);
    }

    /**
     * TEST G: PDF detail memiliki 10 columns:
     * NO, KODE PRODUKSI, CUSTOMER, HEAT, NAMA ITEM, BERAT ITEM, INPUT, RUSAK, TOTAL BERAT INPUT, WAKTU
     */
    public function test_g_pdf_detail_has_exact_10_columns(): void
    {
        $line = $this->createKtrLine(['qty_good' => 50, 'unit_weight_kg' => 2.35]);
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 50,
            'defect_qty' => 1,
            'good_qty' => 49,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)->get('/sand-casting/report/production/export/pdf?stage=netto');
        $response->assertStatus(200);

        // 10 required column headers
        $response->assertSee('No');
        $response->assertSee('Kode Produksi');
        $response->assertSee('Customer');
        $response->assertSee('Heat');
        $response->assertSee('Nama Item');
        $response->assertSee('Berat Item');
        $response->assertSee('Input');
        $response->assertSee('Rusak');
        $response->assertSee('Total Berat Input');
        $response->assertSee('Waktu');
    }

    /**
     * TEST H: PDF detail TIDAK memiliki: KTR, TAHAPAN, GOOD, OPERATOR
     */
    public function test_h_pdf_detail_excludes_ktr_tahapan_good_operator(): void
    {
        $line = $this->createKtrLine(['qty_good' => 50, 'unit_weight_kg' => 2.35]);
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 50,
            'defect_qty' => 1,
            'good_qty' => 49,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)->get('/sand-casting/report/production/export/pdf?stage=netto');
        $response->assertStatus(200);

        // In detail table header
        $content = $response->getContent();
        $this->assertStringNotContainsString('<th>KTR</th>', $content);
        $this->assertStringNotContainsString('<th>Operator</th>', $content);
        $this->assertStringNotContainsString('<th>Good</th>', $content);
        // Note: Summary table still has Good and Tahapan, but detail table does not
        $this->assertStringNotContainsString('<th class="text-center" style="width: 15%;">KTR</th>', $content);
    }

    /**
     * TEST I: KTR tetap ada di underlying dataset/internal data
     */
    public function test_i_ktr_remains_in_underlying_dataset(): void
    {
        $line = $this->createKtrLine(['qty_good' => 50]);

        $data = $this->reportService->getProductionDataset(['stage' => 'cor']);
        $this->assertNotEmpty($data['items']);
        $this->assertArrayHasKey('ktr', $data['items']->first());
        $this->assertEquals($line->traveler_number, $data['items']->first()['ktr']);
    }

    /**
     * TEST J: Nama Item berasal dari relation/data existing
     */
    public function test_j_item_name_from_existing_relation(): void
    {
        $plan = $this->createPlan(['item_name' => 'SS304 JIS 10K NS 4"']);
        $line = $this->createKtrLine([], $plan);

        $response = $this->actingAs($this->adminUser)->get('/sand-casting/report/production/export/pdf?stage=cor');
        $response->assertStatus(200);
        $response->assertSee('SS304 JIS 10K NS 4"');
    }

    /**
     * TEST K: Berat Item berasal dari unit_weight_kg
     */
    public function test_k_unit_weight_displayed_in_pdf(): void
    {
        $line = $this->createKtrLine(['qty_good' => 50, 'unit_weight_kg' => 3.75]);

        $response = $this->actingAs($this->adminUser)->get('/sand-casting/report/production/export/pdf?stage=cor');
        $response->assertStatus(200);
        $response->assertSee('3.75 kg');
    }

    /**
     * TEST L: Total Berat Input = input * unit_weight_kg
     */
    public function test_l_total_input_weight_formula(): void
    {
        // Input = 100, Unit Weight = 2.35 kg -> Total Berat Input = 235.00 kg
        $line = $this->createKtrLine(['qty_good' => 100, 'qty_reject' => 0, 'unit_weight_kg' => 2.35]);

        $response = $this->actingAs($this->adminUser)->get('/sand-casting/report/production/export/pdf?stage=cor');
        $response->assertStatus(200);
        $response->assertSee('235.00 kg');
    }

    /**
     * TEST M: Physical date tetap menggunakan physical_done_at
     */
    public function test_m_physical_date_uses_physical_done_at(): void
    {
        $line = $this->createKtrLine(['qty_good' => 50]);
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 50,
            'defect_qty' => 2,
            'good_qty' => 48,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => '2026-09-24 23:23:00',
            'executed_at' => '2026-09-24 23:23:00',
            'operator_id' => $this->spvUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)->get('/sand-casting/report/production/export/pdf?date_from=2026-09-24&date_to=2026-09-24&stage=netto');
        $response->assertStatus(200);
        $response->assertSee('24/09 23:23');
    }

    /**
     * TEST N: WAITING_DEFECT tetap muncul jika physical_done_at ada
     */
    public function test_n_waiting_defect_appears_with_physical_done_at(): void
    {
        $line = $this->createKtrLine(['qty_good' => 50]);
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 50,
            'defect_qty' => 0,
            'good_qty' => 50,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)->get('/sand-casting/report/production/export/pdf?stage=netto');
        $response->assertStatus(200);
        $response->assertSee('50');
    }

    /**
     * TEST O: Excel tetap berfungsi dan tidak rusak
     */
    public function test_o_excel_export_intact(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100]);

        $response = $this->actingAs($this->adminUser)->get('/sand-casting/report/production/export/excel?stage=cor');
        $response->assertStatus(200);
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
    }

    /**
     * TEST P: Summary KPI tetap konsisten
     */
    public function test_p_summary_kpi_consistency(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'qty_reject' => 5, 'unit_weight_kg' => 2.00]);

        $response = $this->actingAs($this->adminUser)->get('/sand-casting/report/production/export/pdf?stage=cor');
        $response->assertStatus(200);
        $response->assertSee('Total Aktivitas KTR');
        $response->assertSee('Total Output Baik');
        $response->assertSee('Total Berat Output');
        $response->assertSee('Total Defect (Rusak)');
        $response->assertSee('100 pcs');
        $response->assertSee('5 pcs');
        $response->assertSee('200.00 kg');
    }

    /**
     * TEST Q: CNC anti-double-count tetap berfungsi
     */
    public function test_q_cnc_anti_double_count_preserved(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100]);

        // 3 CNC checkpoints
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

        $response = $this->actingAs($this->adminUser)->get('/sand-casting/report/production/export/pdf?stage=bubut_cnc');
        $response->assertStatus(200);

        // 1 KTR in summary count
        $response->assertSee('1 KTR');
        // 3 activity rows in detail
        $response->assertSee('Total: <strong>3</strong> baris aktivitas', false);
    }

    /**
     * Additional Security & Scope Isolation Tests
     */
    public function test_authorization_and_unauthenticated_redirect(): void
    {
        $this->get('/sand-casting/report/production')->assertRedirect(route('login'));
        $this->actingAs($this->adminUser)->get('/sand-casting/report/production')->assertStatus(200);
        $this->actingAs($this->ppicUser)->get('/sand-casting/report/production')->assertStatus(200);
        $this->actingAs($this->qcUser)->get('/sand-casting/report/production')->assertStatus(200);
        $this->actingAs($this->spvUser)->get('/sand-casting/report/production')->assertStatus(200);
    }

    public function test_product_scope_isolation(): void
    {
        $planFlange = $this->createPlan(['product_scope' => 'FLANGE_BESI']);
        $planFitting = $this->createPlan(['product_scope' => 'FITTING_BESI']);

        $this->createKtrLine(['qty_good' => 100], $planFlange);
        $this->createKtrLine(['qty_good' => 50], $planFitting);

        $dataScoped = $this->reportService->getProductionDataset(['stage' => 'cor'], $this->ppicUser);
        $corScoped = $dataScoped['stage_summaries']->where('stage', 'cor')->first();
        $this->assertEquals(1, $corScoped['ktr_count']);
        $this->assertEquals(100, $corScoped['good_pcs']);

        $dataUnscoped = $this->reportService->getProductionDataset(['stage' => 'cor'], $this->adminUser);
        $corUnscoped = $dataUnscoped['stage_summaries']->where('stage', 'cor')->first();
        $this->assertEquals(2, $corUnscoped['ktr_count']);
        $this->assertEquals(150, $corUnscoped['good_pcs']);
    }

    public function test_report_does_not_mutate_database(): void
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

        $this->actingAs($this->adminUser)->get('/sand-casting/report/production?stage=netto');
        $this->actingAs($this->adminUser)->get('/sand-casting/report/production/export/excel?stage=netto');
        $this->actingAs($this->adminUser)->get('/sand-casting/report/production/export/pdf?stage=netto');

        $this->assertEquals($beforeCountExec, SandCastingStageExecution::count());
        $this->assertEquals($beforeCountLine, SandCastingCastingResultLine::count());

        $exec->refresh();
        $this->assertEquals(SandCastingStageExecution::STATUS_CONFIRMED, $exec->status);
    }

    /**
     * REPORT TEST 1: NETTO: Input 40, Defect 5, Good 35
     */
    public function test_report_scenario_1_netto_effective_quantities(): void
    {
        $line = $this->createKtrLine(['qty_good' => 40]);
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 5,
            'good_qty' => 35,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        $dataset = $this->reportService->getProductionDataset(['stage' => 'netto']);
        $item = $dataset['items']->first();
        $summary = $dataset['stage_summaries']->where('stage', 'netto')->first();

        $this->assertEquals(40, $item['input_qty']);
        $this->assertEquals(5, $item['defect_qty']);
        $this->assertEquals(35, $item['good_qty']);

        $this->assertEquals(40, $summary['input_pcs']);
        $this->assertEquals(5, $summary['defect_pcs']);
        $this->assertEquals(35, $summary['good_pcs']);
    }

    /**
     * REPORT TEST 2: NETTO defect 5, CNC defect 2 -> CNC: Input 35, Defect 2, Good 33
     */
    public function test_report_scenario_2_cnc_effective_quantities_with_upstream_defect(): void
    {
        $line = $this->createKtrLine(['qty_good' => 40]);

        // Netto with defect 5
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 5,
            'good_qty' => 35,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        // Bubut OD with defect 0
        $line->stageExecutions()->create([
            'stage' => 'bubut_od',
            'checkpoint_code' => 'BUBUT_OD_ROUGH',
            'input_qty' => 40, // Snapshot
            'defect_qty' => 0,
            'good_qty' => 40,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        // Bubut CNC with defect 2
        $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 40, // Snapshot
            'defect_qty' => 2,
            'good_qty' => 38,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        $dataset = $this->reportService->getProductionDataset(['stage' => 'bubut_cnc']);
        $item = $dataset['items']->first();
        $summary = $dataset['stage_summaries']->where('stage', 'bubut_cnc')->first();

        // CNC Input must be 35 (40 - 5), Defect 2, Good 33 (35 - 2)
        $this->assertEquals(35, $item['input_qty']);
        $this->assertEquals(2, $item['defect_qty']);
        $this->assertEquals(33, $item['good_qty']);

        $this->assertEquals(35, $summary['input_pcs']);
        $this->assertEquals(2, $summary['defect_pcs']);
        $this->assertEquals(33, $summary['good_pcs']);
    }

    /**
     * REPORT TEST 3: BOR: Input 33, Defect 0, Good 33
     */
    public function test_report_scenario_3_bor_effective_quantities_with_upstream_defects(): void
    {
        $line = $this->createKtrLine(['qty_good' => 40]);

        // Netto defect 5
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 5,
            'good_qty' => 35,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        // OD defect 0
        $line->stageExecutions()->create([
            'stage' => 'bubut_od',
            'checkpoint_code' => 'BUBUT_OD_ROUGH',
            'input_qty' => 40,
            'defect_qty' => 0,
            'good_qty' => 40,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        // CNC defect 2
        $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 40,
            'defect_qty' => 2,
            'good_qty' => 38,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        // Bor defect 0
        $line->stageExecutions()->create([
            'stage' => 'bor',
            'checkpoint_code' => 'BOR_DRILLING',
            'input_qty' => 40,
            'defect_qty' => 0,
            'good_qty' => 40,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        $dataset = $this->reportService->getProductionDataset(['stage' => 'bor']);
        $item = $dataset['items']->first();
        $summary = $dataset['stage_summaries']->where('stage', 'bor')->first();

        // Bor Input must be 33 (40 - 5 - 2), Defect 0, Good 33
        $this->assertEquals(33, $item['input_qty']);
        $this->assertEquals(0, $item['defect_qty']);
        $this->assertEquals(33, $item['good_qty']);

        $this->assertEquals(33, $summary['input_pcs']);
        $this->assertEquals(0, $summary['defect_pcs']);
        $this->assertEquals(33, $summary['good_pcs']);
    }

    /**
     * REPORT TEST 4: Late defect - Physical activity Day 1, Defect entered Day 3.
     * Report date stays Day 1, quantity is reconciled.
     */
    public function test_report_scenario_4_late_defect_reconciliation_preserves_physical_activity_date(): void
    {
        $day1 = '2026-09-01';
        $day3 = '2026-09-03';

        $line = $this->createKtrLine(['qty_good' => 40], null, $day1);

        // Physical executions done on Day 1 (Snapshot input 40)
        $nettoExec = $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 0,
            'good_qty' => 40,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'physical_done_at' => $day1.' 09:00:00',
            'executed_at' => $day1.' 09:00:00',
            'operator_id' => $this->spvUser->id,
        ]);

        $odExec = $line->stageExecutions()->create([
            'stage' => 'bubut_od',
            'checkpoint_code' => 'BUBUT_OD_ROUGH',
            'input_qty' => 40,
            'defect_qty' => 0,
            'good_qty' => 40,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'physical_done_at' => $day1.' 11:00:00',
            'executed_at' => $day1.' 11:00:00',
            'operator_id' => $this->spvUser->id,
        ]);

        $cncExec = $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 40,
            'defect_qty' => 0,
            'good_qty' => 40,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'physical_done_at' => $day1.' 14:00:00',
            'executed_at' => $day1.' 14:00:00',
            'operator_id' => $this->spvUser->id,
        ]);

        $borExec = $line->stageExecutions()->create([
            'stage' => 'bor',
            'checkpoint_code' => 'BOR_DRILLING',
            'input_qty' => 40,
            'defect_qty' => 0,
            'good_qty' => 40,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'physical_done_at' => $day1.' 16:00:00',
            'executed_at' => $day1.' 16:00:00',
            'operator_id' => $this->spvUser->id,
        ]);

        // Day 3: Defects entered by Admin PPIC
        $nettoExec->update([
            'defect_qty' => 5,
            'good_qty' => 35,
            'defect_entered_at' => $day3.' 10:00:00',
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
        ]);

        $cncExec->update([
            'defect_qty' => 2,
            'good_qty' => 38,
            'defect_entered_at' => $day3.' 11:00:00',
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
        ]);

        // Report for Day 1 physical activities
        // NETTO: 40 / 5 / 35
        $datasetNetto = $this->reportService->getProductionDataset(['date_from' => $day1, 'date_to' => $day1, 'stage' => 'netto']);
        $itemNetto = $datasetNetto['items']->first();
        $this->assertEquals(40, $itemNetto['input_qty']);
        $this->assertEquals(5, $itemNetto['defect_qty']);
        $this->assertEquals(35, $itemNetto['good_qty']);

        // OD: 35 / 0 / 35
        $datasetOd = $this->reportService->getProductionDataset(['date_from' => $day1, 'date_to' => $day1, 'stage' => 'bubut_od']);
        $itemOd = $datasetOd['items']->first();
        $this->assertEquals(35, $itemOd['input_qty']);
        $this->assertEquals(0, $itemOd['defect_qty']);
        $this->assertEquals(35, $itemOd['good_qty']);

        // CNC: 35 / 2 / 33
        $datasetCnc = $this->reportService->getProductionDataset(['date_from' => $day1, 'date_to' => $day1, 'stage' => 'bubut_cnc']);
        $itemCnc = $datasetCnc['items']->first();
        $this->assertEquals(35, $itemCnc['input_qty']);
        $this->assertEquals(2, $itemCnc['defect_qty']);
        $this->assertEquals(33, $itemCnc['good_qty']);

        // BOR: 33 / 0 / 33
        $datasetBor = $this->reportService->getProductionDataset(['date_from' => $day1, 'date_to' => $day1, 'stage' => 'bor']);
        $itemBor = $datasetBor['items']->first();
        $this->assertEquals(33, $itemBor['input_qty']);
        $this->assertEquals(0, $itemBor['defect_qty']);
        $this->assertEquals(33, $itemBor['good_qty']);
    }

    /**
     * REPORT TEST 5: HTML / Excel / PDF numeric consistency
     */
    public function test_report_scenario_5_html_excel_pdf_dataset_numeric_consistency(): void
    {
        $line = $this->createKtrLine(['qty_good' => 40, 'unit_weight_kg' => 2.00]);
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 5,
            'good_qty' => 35,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);
        $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 40,
            'defect_qty' => 2,
            'good_qty' => 38,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        // 1. HTML View
        $resHtml = $this->actingAs($this->adminUser)->get('/sand-casting/report/production?stage=bubut_cnc');
        $resHtml->assertStatus(200);
        $resHtml->assertSee('35 pcs'); // Input pcs in summary
        $resHtml->assertSee('2 pcs');  // Defect pcs
        $resHtml->assertSee('33 pcs'); // Good pcs
        $resHtml->assertSee('66.00 kg'); // Weight (33 * 2.00)

        // 2. PDF View
        $resPdf = $this->actingAs($this->adminUser)->get('/sand-casting/report/production/export/pdf?stage=bubut_cnc');
        $resPdf->assertStatus(200);
        $resPdf->assertSee('35');
        $resPdf->assertSee('33 pcs');
        $resPdf->assertSee('66.00 kg');

        // 3. Excel Export
        $resExcel = $this->actingAs($this->adminUser)->get('/sand-casting/report/production/export/excel?stage=bubut_cnc');
        $resExcel->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml.sheet', $resExcel->headers->get('Content-Type'));
    }

    /**
     * REPORT TEST 6: CNC 3 checkpoints do not double count Stage Summary
     */
    public function test_report_scenario_6_cnc_checkpoints_do_not_double_count_summary(): void
    {
        $line = $this->createKtrLine(['qty_good' => 40]);

        // Netto defect = 5
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 5,
            'good_qty' => 35,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        // CNC 3 checkpoints (machining defect 2, post cnc defect 1, pre bor defect 0)
        $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 35,
            'defect_qty' => 2,
            'good_qty' => 33,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now()->subMinutes(30),
            'executed_at' => now()->subMinutes(30),
            'operator_id' => $this->spvUser->id,
        ]);
        $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'QC_POST_CNC',
            'input_qty' => 33,
            'defect_qty' => 1,
            'good_qty' => 32,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now()->subMinutes(20),
            'executed_at' => now()->subMinutes(20),
            'operator_id' => $this->spvUser->id,
        ]);
        $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'QC_PRE_BOR',
            'input_qty' => 32,
            'defect_qty' => 0,
            'good_qty' => 32,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now()->subMinutes(10),
            'executed_at' => now()->subMinutes(10),
            'operator_id' => $this->spvUser->id,
        ]);

        $dataset = $this->reportService->getProductionDataset(['stage' => 'bubut_cnc']);
        $summary = $dataset['stage_summaries']->where('stage', 'bubut_cnc')->first();

        // Single KTR
        $this->assertEquals(1, $summary['ktr_count']);
        // Effective input = 35
        $this->assertEquals(35, $summary['input_pcs']);
        // Cumulative defect across CNC checkpoints = 3 (2 + 1)
        $this->assertEquals(3, $summary['defect_pcs']);
        // Effective good = 32 (35 - 3)
        $this->assertEquals(32, $summary['good_pcs']);

        // 3 Detail items exist
        $this->assertCount(3, $dataset['items']);
    }

    /**
     * REPORT TEST 7: Multiple KTR isolation
     */
    public function test_report_scenario_7_multiple_ktr_isolation(): void
    {
        $line1 = $this->createKtrLine(['qty_good' => 40]);
        $line1->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 5,
            'good_qty' => 35,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        $line2 = $this->createKtrLine(['qty_good' => 60]);
        $line2->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 60,
            'defect_qty' => 10,
            'good_qty' => 50,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        $dataset = $this->reportService->getProductionDataset(['stage' => 'netto']);
        $summary = $dataset['stage_summaries']->where('stage', 'netto')->first();

        $this->assertEquals(2, $summary['ktr_count']);
        $this->assertEquals(100, $summary['input_pcs']); // 40 + 60
        $this->assertEquals(15, $summary['defect_pcs']);  // 5 + 10
        $this->assertEquals(85, $summary['good_pcs']);   // 35 + 50
    }

    /**
     * REPORT TEST 8: Physical database snapshot remains immutable
     */
    public function test_report_scenario_8_physical_database_snapshot_remains_immutable(): void
    {
        $line = $this->createKtrLine(['qty_good' => 40]);
        $exec = $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 5,
            'good_qty' => 35,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        $dataset = $this->reportService->getProductionDataset(['stage' => 'netto']);

        $exec->refresh();
        $this->assertEquals(40, $exec->input_qty);
        $this->assertEquals(5, $exec->defect_qty);
        $this->assertEquals(35, $exec->good_qty);
    }

    /**
     * REPORT TEST 9: Multiple execution on stage does not double count Stage Summary
     */
    public function test_report_scenario_9_multiple_execution_on_stage_does_not_double_count_summary(): void
    {
        $line = $this->createKtrLine(['qty_good' => 40]);

        // Netto execution 1: defect 2
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 2,
            'good_qty' => 38,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now()->subMinutes(10),
            'executed_at' => now()->subMinutes(10),
            'operator_id' => $this->spvUser->id,
        ]);

        // Netto execution 2 (e.g. second cut/re-work): defect 1
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_FINISH',
            'input_qty' => 38,
            'defect_qty' => 1,
            'good_qty' => 37,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        $dataset = $this->reportService->getProductionDataset(['stage' => 'netto']);
        $summary = $dataset['stage_summaries']->where('stage', 'netto')->first();

        // 1 KTR
        $this->assertEquals(1, $summary['ktr_count']);
        // Effective input = 40 (COR good)
        $this->assertEquals(40, $summary['input_pcs']);
        // Cumulative defect = 3 (2 + 1)
        $this->assertEquals(3, $summary['defect_pcs']);
        // Effective good = 37 (40 - 3)
        $this->assertEquals(37, $summary['good_pcs']);
    }

    /**
     * REPORT TEST 10: Weight calculated from effective good
     */
    public function test_report_scenario_10_weight_calculated_from_effective_good(): void
    {
        $line = $this->createKtrLine(['qty_good' => 40, 'unit_weight_kg' => 2.50]);
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 5,
            'good_qty' => 35,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        $dataset = $this->reportService->getProductionDataset(['stage' => 'netto']);
        $summary = $dataset['stage_summaries']->where('stage', 'netto')->first();

        // Effective good 35 * 2.50 = 87.50 kg
        $this->assertEquals(87.50, $summary['weight_kg']);
    }

    /**
     * REPORT TEST 11: PDF "Total Berat Input" calculated from effective input
     */
    public function test_report_scenario_11_pdf_total_berat_input_calculated_from_effective_input(): void
    {
        $line = $this->createKtrLine(['qty_good' => 40, 'unit_weight_kg' => 2.50]);

        // Netto defect 5
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 40,
            'defect_qty' => 5,
            'good_qty' => 35,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        // Bor execution: effective input = 35 -> Total Berat Input = 35 * 2.50 = 87.50 kg
        $line->stageExecutions()->create([
            'stage' => 'bor',
            'checkpoint_code' => 'BOR_DRILLING',
            'input_qty' => 40, // Snapshot
            'defect_qty' => 0,
            'good_qty' => 40,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)->get('/sand-casting/report/production/export/pdf?stage=bor');
        $response->assertStatus(200);
        // Total Berat Input for 35 * 2.50 = 87.50 kg
        $response->assertSee('87.50 kg');
    }
}
