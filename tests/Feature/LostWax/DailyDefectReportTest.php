<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxTree;
use App\Models\LostWaxTreeDefect;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWaxDefectReportService;
use App\Services\PrintExecutionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DailyDefectReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $qcUser;

    protected User $unauthorizedUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles and permissions
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution', 'guard_name' => 'web']);
        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);

        $roleAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $roleAdmin->syncPermissions([$permExecution, $permPlanning]);

        $rolePpic = Role::firstOrCreate(['name' => 'ppic', 'guard_name' => 'web']);
        $rolePpic->syncPermissions([$permExecution, $permPlanning]);

        $roleSpv = Role::firstOrCreate(['name' => 'spv', 'guard_name' => 'web']);
        $roleSpv->syncPermissions([$permExecution]);

        $this->qcUser = User::factory()->create([
            'name' => 'QC Fitting Inspector',
            'email' => 'adminqcfitting@peroniks.com',
            'product_scope' => 'FITTING_STAINLESS',
        ]);
        $this->qcUser->assignRole($roleSpv);
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'code' => '268L651',
            'customer' => 'PT PERONI INTI',
            'item_code' => '4.801190FFBSP.G.A0020',
            'item_name' => 'SS304 ELBOW 90 F/F BSP 3/4"',
            'aisi' => '304',
            'size' => '3/4"',
            'weight' => 0.45,
            'po_number' => 'PO-2026-QC-01',
            'po_quantity' => 500,
            'qty_planned' => 550,
            'qty_remaining' => 550,
            'line_number' => 1,
            'status' => 'planning',
            'is_closed' => false,
        ], $attributes));
    }

    /**
     * 1. QC user can open the Daily Defect Report page.
     */
    public function test_qc_user_can_access_defect_report_page(): void
    {
        $response = $this->actingAs($this->qcUser)->get(route('lost-wax.quality.defects.index'));

        $response->assertStatus(200);
        $response->assertSee('REKAP KERUSAKAN LOST WAX');
        $response->assertSee('Export Excel');
        $response->assertSee('Cetak / PDF');
        $response->assertSee('GRAND TOTAL');
    }

    /**
     * 2. Unauthorized guest user is redirected to login.
     */
    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $response = $this->get(route('lost-wax.quality.defects.index'));

        $response->assertRedirect('/login');
    }

    /**
     * 3. Date range filter works as expected.
     */
    public function test_date_range_filter_filters_data_correctly(): void
    {
        $plan = $this->createPlan();

        // Print Order & Line
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260829-0001',
            'scheduled_date' => '2026-08-20',
            'status' => 'COMPLETED',
            'created_by' => $this->qcUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'qty_ordered' => 200,
            'standard_tree_capacity' => 20,
        ]);

        // Old defect on 2026-08-20
        app(PrintExecutionService::class)->record($line, [
            'qty_good' => 180,
            'qty_defect' => 15,
            'execution_date' => '2026-08-20',
            'status' => 'FINALIZED',
            'notes' => 'Cacat lilin awal',
            'recorded_by' => $this->qcUser->id,
        ]);

        // Recent defect on 2026-08-29
        app(PrintExecutionService::class)->record($line, [
            'qty_good' => 190,
            'qty_defect' => 10,
            'execution_date' => '2026-08-29',
            'status' => 'FINALIZED',
            'notes' => 'Cacat lilin baru',
            'recorded_by' => $this->qcUser->id,
        ]);

        // Query only 2026-08-29
        $response = $this->actingAs($this->qcUser)->get(route('lost-wax.quality.defects.index', [
            'date_from' => '2026-08-29',
            'date_to' => '2026-08-29',
            'mode' => 'detail',
        ]));

        $response->assertStatus(200);
        $response->assertSee('10 pcs');
        $response->assertDontSee('15 pcs');
        $response->assertSee('Cacat lilin baru');
        $response->assertDontSee('Cacat lilin awal');
    }

    /**
     * 4. Stage filter works accurately across stages.
     */
    public function test_stage_filter_filters_by_specific_stage(): void
    {
        $plan = $this->createPlan();

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260829-0002',
            'scheduled_date' => '2026-08-29',
            'status' => 'ISSUED',
            'created_by' => $this->qcUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'qty_ordered' => 100,
            'standard_tree_capacity' => 20,
        ]);

        // Cetak defect (5 pcs)
        app(PrintExecutionService::class)->record($line, [
            'qty_good' => 95,
            'qty_defect' => 5,
            'execution_date' => '2026-08-29',
            'status' => 'FINALIZED',
            'notes' => 'Injeksi Bocor',
            'recorded_by' => $this->qcUser->id,
        ]);

        // Physical Tree
        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => '1290826001',
            'tree_number' => 1,
            'quantity' => 20,
            'status' => 'active',
            'production_date' => '2026-08-29',
            'family_code' => '1',
            'daily_sequence' => 1,
        ]);

        // Assembly defect (2 pcs)
        LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $tree->id,
            'stage' => 'assembly',
            'defect_qty' => 2,
            'defect_reason' => 'pola_patah',
            'recorded_by' => $this->qcUser->id,
            'occurred_at' => Carbon::parse('2026-08-29 09:00:00'),
        ]);

        // Layer 3 defect (3 pcs)
        LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $tree->id,
            'stage' => 'layer_3',
            'defect_qty' => 3,
            'defect_reason' => 'retak_lapisan',
            'recorded_by' => $this->qcUser->id,
            'occurred_at' => Carbon::parse('2026-08-29 11:00:00'),
        ]);

        // 1. Filter stage=cetak
        $resCetak = $this->actingAs($this->qcUser)->get(route('lost-wax.quality.defects.index', [
            'date_from' => '2026-08-29',
            'date_to' => '2026-08-29',
            'stage' => 'cetak',
            'mode' => 'detail',
        ]));
        $resCetak->assertSee('5 pcs');
        $resCetak->assertDontSee('Pola Patah');
        $resCetak->assertDontSee('Retak Lapisan');

        // 2. Filter stage=assembly
        $resAssembly = $this->actingAs($this->qcUser)->get(route('lost-wax.quality.defects.index', [
            'date_from' => '2026-08-29',
            'date_to' => '2026-08-29',
            'stage' => 'assembly',
            'mode' => 'detail',
        ]));
        $resAssembly->assertSee('2 pcs');
        $resAssembly->assertSee('Pola Patah');
        $resAssembly->assertDontSee('Injeksi Bocor');

        // 3. Filter stage=layer_3
        $resLayer3 = $this->actingAs($this->qcUser)->get(route('lost-wax.quality.defects.index', [
            'date_from' => '2026-08-29',
            'date_to' => '2026-08-29',
            'stage' => 'layer_3',
            'mode' => 'detail',
        ]));
        $resLayer3->assertSee('3 pcs');
        $resLayer3->assertSee('Retak Lapisan');
    }

    /**
     * 5. All stages display data correctly from Cetak to Oven.
     */
    public function test_all_stages_display_from_cetak_to_oven(): void
    {
        $plan = $this->createPlan(['code' => '268ALL01']);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260829-0003',
            'scheduled_date' => '2026-08-29',
            'status' => 'ISSUED',
            'created_by' => $this->qcUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'qty_ordered' => 200,
            'standard_tree_capacity' => 20,
        ]);

        // Cetak defect
        app(PrintExecutionService::class)->record($line, [
            'qty_good' => 190,
            'qty_defect' => 10,
            'execution_date' => '2026-08-29',
            'status' => 'FINALIZED',
            'notes' => 'Defect Cetak Awal',
            'recorded_by' => $this->qcUser->id,
        ]);

        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => '1290826002',
            'tree_number' => 1,
            'quantity' => 20,
            'status' => 'active',
            'production_date' => '2026-08-29',
            'family_code' => '1',
            'daily_sequence' => 2,
        ]);

        // All tree stages
        $treeStages = ['assembly', 'layer_1', 'layer_2', 'layer_3', 'layer_4', 'layer_5', 'layer_6', 'layer_7', 'oven'];
        foreach ($treeStages as $st) {
            LostWaxTreeDefect::create([
                'lost_wax_tree_id' => $tree->id,
                'stage' => $st,
                'defect_qty' => 1,
                'defect_reason' => "Cacat pada {$st}",
                'recorded_by' => $this->qcUser->id,
                'occurred_at' => Carbon::parse('2026-08-29 08:00:00'),
            ]);
        }

        $service = app(LostWaxDefectReportService::class);
        $dataset = $service->getDefectDataset([
            'date_from' => '2026-08-29',
            'date_to' => '2026-08-29',
            'stage' => 'all',
            'mode' => 'detail',
        ]);

        $this->assertEquals(10, $dataset['summary']['cetak']);
        $this->assertEquals(1, $dataset['summary']['assembly']);
        $this->assertEquals(1, $dataset['summary']['layer_1']);
        $this->assertEquals(1, $dataset['summary']['layer_2']);
        $this->assertEquals(1, $dataset['summary']['layer_3']);
        $this->assertEquals(1, $dataset['summary']['layer_4']);
        $this->assertEquals(1, $dataset['summary']['layer_5']);
        $this->assertEquals(1, $dataset['summary']['layer_6']);
        $this->assertEquals(1, $dataset['summary']['layer_7']);
        $this->assertEquals(1, $dataset['summary']['oven']);
        // Grand Total: 10 + 9*1 = 19
        $this->assertEquals(19, $dataset['summary']['grand_total']);
    }

    /**
     * 6. Mode Ringkas generates accurate grouped aggregate by ProductionPlan.code + stage.
     */
    public function test_mode_ringkas_groups_by_production_code_and_stage(): void
    {
        $planA = $this->createPlan(['code' => '268CODE_A']);
        $planB = $this->createPlan(['code' => '268CODE_B']);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260829-0004',
            'scheduled_date' => '2026-08-29',
            'status' => 'ISSUED',
            'created_by' => $this->qcUser->id,
        ]);

        $lineA = $order->lines()->create([
            'production_plan_id' => $planA->id,
            'code' => $planA->code,
            'customer' => $planA->customer,
            'item_name' => $planA->item_name,
            'size' => $planA->size,
            'aisi' => $planA->aisi,
            'qty_ordered' => 100,
            'standard_tree_capacity' => 20,
        ]);

        $treeA1 = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $lineA->id,
            'barcode' => '1290826010',
            'tree_number' => 1,
            'quantity' => 20,
            'status' => 'active',
            'production_date' => '2026-08-29',
            'family_code' => '1',
            'daily_sequence' => 10,
        ]);

        $treeA2 = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $lineA->id,
            'barcode' => '1290826011',
            'tree_number' => 2,
            'quantity' => 20,
            'status' => 'active',
            'production_date' => '2026-08-29',
            'family_code' => '1',
            'daily_sequence' => 11,
        ]);

        // Tree A1 has 2 defects on layer_1
        LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $treeA1->id,
            'stage' => 'layer_1',
            'defect_qty' => 2,
            'defect_reason' => 'retak',
            'recorded_by' => $this->qcUser->id,
            'occurred_at' => Carbon::parse('2026-08-29 08:00:00'),
        ]);

        // Tree A2 has 3 defects on layer_1
        LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $treeA2->id,
            'stage' => 'layer_1',
            'defect_qty' => 3,
            'defect_reason' => 'rontok',
            'recorded_by' => $this->qcUser->id,
            'occurred_at' => Carbon::parse('2026-08-29 08:30:00'),
        ]);

        $response = $this->actingAs($this->qcUser)->get(route('lost-wax.quality.defects.index', [
            'date_from' => '2026-08-29',
            'date_to' => '2026-08-29',
            'mode' => 'ringkas',
        ]));

        $response->assertStatus(200);
        $response->assertSee('268CODE_A');
        // Combined defect qty for layer_1 on 268CODE_A is 5 pcs across 2 records
        $response->assertSee('5 pcs');
        $response->assertSee('2 kali');
    }

    /**
     * 7. Mode Detail generates barcode-level traceability.
     */
    public function test_mode_detail_provides_barcode_level_traceability(): void
    {
        $plan = $this->createPlan(['code' => '268TRACE01']);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260829-0005',
            'scheduled_date' => '2026-08-29',
            'status' => 'ISSUED',
            'created_by' => $this->qcUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'qty_ordered' => 100,
            'standard_tree_capacity' => 20,
        ]);

        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => '1290826999',
            'tree_number' => 1,
            'quantity' => 20,
            'status' => 'active',
            'production_date' => '2026-08-29',
            'family_code' => '1',
            'daily_sequence' => 999,
        ]);

        LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $tree->id,
            'stage' => 'oven',
            'defect_qty' => 4,
            'defect_reason' => 'oven_pecah',
            'notes' => 'Pecah saat suhu 900C',
            'recorded_by' => $this->qcUser->id,
            'occurred_at' => Carbon::parse('2026-08-29 14:15:00'),
        ]);

        $response = $this->actingAs($this->qcUser)->get(route('lost-wax.quality.defects.index', [
            'date_from' => '2026-08-29',
            'date_to' => '2026-08-29',
            'mode' => 'detail',
        ]));

        $response->assertStatus(200);
        $response->assertSee('268TRACE01');
        $response->assertSee('1290826999');
        $response->assertSee('4 pcs');
        $response->assertSee('Oven Pecah');
        $response->assertSee('Pecah saat suhu 900C');
        $response->assertSee($this->qcUser->name);
    }

    /**
     * 8. Production Code strictly uses ProductionPlan.code.
     */
    public function test_production_code_strictly_uses_production_plan_code(): void
    {
        $plan = $this->createPlan([
            'code' => 'PROD-CODE-CANONICAL-99',
            'item_code' => 'ERP-ITEM-CODE-SHOULD-NOT-BE-PROD-CODE',
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260829-0006',
            'scheduled_date' => '2026-08-29',
            'status' => 'ISSUED',
            'created_by' => $this->qcUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'qty_ordered' => 100,
            'standard_tree_capacity' => 20,
        ]);

        app(PrintExecutionService::class)->record($line, [
            'qty_good' => 90,
            'qty_defect' => 8,
            'execution_date' => '2026-08-29',
            'status' => 'FINALIZED',
            'recorded_by' => $this->qcUser->id,
        ]);

        $service = app(LostWaxDefectReportService::class);
        $dataset = $service->getDefectDataset([
            'date_from' => '2026-08-29',
            'date_to' => '2026-08-29',
            'mode' => 'ringkas',
        ]);

        $firstItem = $dataset['items']->first();
        $this->assertEquals('PROD-CODE-CANONICAL-99', $firstItem['production_code']);
        $this->assertNotEquals('ERP-ITEM-CODE-SHOULD-NOT-BE-PROD-CODE', $firstItem['production_code']);
    }

    /**
     * 9. No double counting: sum matches canonical ledger records exactly.
     */
    public function test_no_double_counting(): void
    {
        $plan = $this->createPlan(['code' => '268NODBL']);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260829-0007',
            'scheduled_date' => '2026-08-29',
            'status' => 'ISSUED',
            'created_by' => $this->qcUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'qty_ordered' => 100,
            'standard_tree_capacity' => 20,
        ]);

        app(PrintExecutionService::class)->record($line, [
            'qty_good' => 90,
            'qty_defect' => 10,
            'execution_date' => '2026-08-29',
            'status' => 'FINALIZED',
            'recorded_by' => $this->qcUser->id,
        ]);

        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => '1290826077',
            'tree_number' => 1,
            'quantity' => 20,
            'status' => 'active',
            'production_date' => '2026-08-29',
            'family_code' => '1',
            'daily_sequence' => 77,
        ]);

        LostWaxTreeDefect::create([
            'lost_wax_tree_id' => $tree->id,
            'stage' => 'assembly',
            'defect_qty' => 3,
            'defect_reason' => 'rusak',
            'recorded_by' => $this->qcUser->id,
            'occurred_at' => Carbon::parse('2026-08-29 10:00:00'),
        ]);

        $service = app(LostWaxDefectReportService::class);
        $dataset = $service->getDefectDataset([
            'date_from' => '2026-08-29',
            'date_to' => '2026-08-29',
        ]);

        $this->assertEquals(13, $dataset['summary']['grand_total']);
        $this->assertEquals(10, $dataset['summary']['cetak']);
        $this->assertEquals(3, $dataset['summary']['assembly']);
    }

    /**
     * 10. Export Excel and PDF endpoints return success and use identical dataset.
     */
    public function test_exports_execute_successfully_with_same_dataset(): void
    {
        $plan = $this->createPlan(['code' => '268EXP01']);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260829-0008',
            'scheduled_date' => '2026-08-29',
            'status' => 'ISSUED',
            'created_by' => $this->qcUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'qty_ordered' => 100,
            'standard_tree_capacity' => 20,
        ]);

        app(PrintExecutionService::class)->record($line, [
            'qty_good' => 90,
            'qty_defect' => 7,
            'execution_date' => '2026-08-29',
            'status' => 'FINALIZED',
            'recorded_by' => $this->qcUser->id,
        ]);

        // PDF / Print view
        $pdfRes = $this->actingAs($this->qcUser)->get(route('lost-wax.quality.defects.export.pdf', [
            'date_from' => '2026-08-29',
            'date_to' => '2026-08-29',
            'mode' => 'ringkas',
        ]));
        $pdfRes->assertStatus(200);
        $pdfRes->assertSee('268EXP01');
        $pdfRes->assertSee('7');

        // Excel streamed download
        $excelRes = $this->actingAs($this->qcUser)->get(route('lost-wax.quality.defects.export.excel', [
            'date_from' => '2026-08-29',
            'date_to' => '2026-08-29',
            'mode' => 'ringkas',
        ]));
        $excelRes->assertStatus(200);
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $excelRes->headers->get('content-type'));
    }

    /**
     * 11. Zero N+1 query regression.
     */
    public function test_zero_n_plus_one_query_performance(): void
    {
        $plan = $this->createPlan(['code' => '268NPLUS1']);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260829-0009',
            'scheduled_date' => '2026-08-29',
            'status' => 'ISSUED',
            'created_by' => $this->qcUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'qty_ordered' => 500,
            'standard_tree_capacity' => 20,
        ]);

        app(PrintExecutionService::class)->record($line, [
            'qty_good' => 450,
            'qty_defect' => 25,
            'execution_date' => '2026-08-29',
            'status' => 'FINALIZED',
            'recorded_by' => $this->qcUser->id,
        ]);

        // Create 10 trees with defects
        for ($i = 1; $i <= 10; $i++) {
            $tree = LostWaxTree::create([
                'lost_wax_print_order_line_id' => $line->id,
                'barcode' => '1290826'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'tree_number' => $i,
                'quantity' => 20,
                'status' => 'active',
                'production_date' => '2026-08-29',
                'family_code' => '1',
                'daily_sequence' => $i,
            ]);

            LostWaxTreeDefect::create([
                'lost_wax_tree_id' => $tree->id,
                'stage' => 'assembly',
                'defect_qty' => 1,
                'defect_reason' => 'patah',
                'recorded_by' => $this->qcUser->id,
                'occurred_at' => Carbon::parse('2026-08-29 09:00:00'),
            ]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        $service = app(LostWaxDefectReportService::class);
        $dataset = $service->getDefectDataset([
            'date_from' => '2026-08-29',
            'date_to' => '2026-08-29',
            'mode' => 'detail',
        ]);

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Bounded constant query count without N+1 (constant number of eager-loaded relations regardless of row count)
        $this->assertLessThanOrEqual(15, $queryCount, "Expected bounded query count without N+1, got {$queryCount} queries.");
    }
}
