<?php

namespace Tests\Feature\SandCasting;

use App\Models\DefectType;
use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use App\Services\SandCasting\SandCastingQcDefectReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class QcDefectReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $ppicUser;

    protected User $ppicStainlessUser;

    protected User $qcUser;

    protected User $qcFittingUser;

    protected User $spvUser;

    protected User $unauthorizedUser;

    protected DefectType $defectPorosity;

    protected DefectType $defectRetak;

    protected DefectType $defectFinish;

    protected SandCastingQcDefectReportService $reportService;

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

        $roleAdminQcFitting = Role::firstOrCreate(['name' => 'admin_qc_fitting']);
        $roleAdminQcFitting->givePermissionTo([$permExecution]);

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

        $this->ppicStainlessUser = User::factory()->create([
            'name' => 'PPIC Flange Stainless',
            'email' => 'ppicflange@peroniks.com',
            'product_scope' => 'FLANGE_STAINLESS',
        ]);
        $this->ppicStainlessUser->assignRole('ppic');

        $this->qcUser = User::factory()->create([
            'name' => 'Admin QC Flange',
            'email' => 'adminqcflange@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->qcUser->assignRole('qc');

        $this->qcFittingUser = User::factory()->create([
            'name' => 'Admin QC Fitting',
            'email' => 'adminqcfitting@peroniks.com',
            'product_scope' => 'FITTING_BESI',
        ]);
        $this->qcFittingUser->assignRole('admin_qc_fitting');

        $this->spvUser = User::factory()->create([
            'name' => 'SPV Netto',
            'email' => 'spvnettofl@peroniks.com',
            'assigned_stage' => 'netto',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvUser->assignRole('spv');

        $this->unauthorizedUser = User::factory()->create([
            'name' => 'General Operator',
            'email' => 'operator@peroniks.com',
        ]);

        $this->defectPorosity = DefectType::firstOrCreate(
            ['name' => 'Porosity'],
            ['department' => 'COR', 'is_active' => true]
        );

        $this->defectRetak = DefectType::firstOrCreate(
            ['name' => 'Retak'],
            ['department' => 'COR', 'is_active' => true]
        );

        // Department finish to test qc/finish compatibility
        $this->defectFinish = DefectType::firstOrCreate(
            ['name' => 'Cacat Ulir Finish'],
            ['department' => 'finish', 'is_active' => true]
        );

        $this->reportService = app(SandCastingQcDefectReportService::class);
    }

    /**
     * Helper to create a complete hierarchy of Plan -> CastingOrder -> Result -> Line -> StageExecution.
     */
    protected function createExecutionWithDefects(array $options = []): array
    {
        static $seq = 1;
        $id = $seq++;

        $scope = $options['product_scope'] ?? 'FLANGE_BESI';
        $weight = array_key_exists('weight', $options) ? $options['weight'] : 2.50;
        $lineUnitWeight = array_key_exists('unit_weight_kg', $options) ? $options['unit_weight_kg'] : 2.50;

        $plan = ProductionPlan::create([
            'code' => $options['code'] ?? 'PLAN-'.$id,
            'title' => 'Rencana SC '.$id,
            'item_code' => '4.'.$id,
            'item_name' => $options['item_name'] ?? 'FLANGE BESI JIS 10K '.$id.'"',
            'po_number' => $options['po_number'] ?? 'PO-'.$id,
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'customer' => $options['customer'] ?? 'PT SINAR METAL '.$id,
            'product_scope' => $scope,
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
            'weight' => $weight,
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-'.$id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '2"',
            'unit_weight_kg' => $lineUnitWeight,
            'total_weight_kg' => ($lineUnitWeight ?? 0) * 100,
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => $options['heat_number'] ?? 'HEAT-'.$id,
            'cast_date' => now()->toDateString(),
            'furnace' => 'F1',
            'shift' => '1',
            'recorded_by' => $this->adminUser->id,
        ]);

        $line = SandCastingCastingResultLine::create([
            'sand_casting_casting_result_id' => $result->id,
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $options['traveler_number'] ?? 'KTR-'.now()->format('Ymd').'-'.sprintf('%04d', $id),
            'qty_good' => 50,
            'qty_reject' => 0,
            'unit_weight_kg' => $lineUnitWeight,
            'total_weight_kg' => ($lineUnitWeight ?? 0) * 50,
            'current_stage' => $options['stage'] ?? 'netto',
        ]);

        $stage = $options['stage'] ?? 'netto';
        $status = $options['status'] ?? SandCastingStageExecution::STATUS_CONFIRMED;
        $date = $options['date'] ?? now()->toDateString();
        $defectQty = $options['defect_qty'] ?? 0;

        $execution = SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => $stage,
            'checkpoint_code' => $options['checkpoint_code'] ?? ($stage === 'netto' ? 'NETTO_CUT' : 'QC_FINAL'),
            'input_qty' => 50,
            'defect_qty' => $defectQty,
            'good_qty' => 50 - $defectQty,
            'status' => $status,
            'inspection_date' => $options['inspection_date'] ?? $date,
            'qc_verified_at' => $options['qc_verified_at'] ?? now(),
            'qc_verified_by' => $this->qcUser->id,
            'operator_id' => $this->spvUser->id,
            'executed_at' => now(),
            'physical_done_at' => now(),
            'notes' => $options['notes'] ?? 'Catatan QC '.$id,
        ]);

        $defectRows = [];
        if (! empty($options['defects'])) {
            foreach ($options['defects'] as $def) {
                $defectRows[] = $execution->defects()->create([
                    'defect_type_id' => $def['defect_type_id'],
                    'qty' => $def['qty'],
                    'notes' => $def['notes'] ?? null,
                ]);
            }
        }

        return [$plan, $line, $execution, $defectRows];
    }

    // 1. Sidebar menu and report route access
    public function test_sidebar_menu_and_route_access(): void
    {
        // Guest is redirected
        $this->get('/sand-casting/report/qc-defects')
            ->assertRedirect(route('login'));

        // Admin can access and see sidebar link
        $resAdmin = $this->actingAs($this->adminUser)->get('/sand-casting/report/qc-defects');
        $resAdmin->assertStatus(200);
        $resAdmin->assertSee('Laporan Defect (QC)');
        $resAdmin->assertSee(route('sand-casting.report.qc-defects.index'));

        // PPIC can access and see sidebar link
        $resPpic = $this->actingAs($this->ppicUser)->get('/sand-casting/report/qc-defects');
        $resPpic->assertStatus(200);
        $resPpic->assertSee('Laporan Defect (QC)');

        // QC can access and see sidebar link
        $resQc = $this->actingAs($this->qcUser)->get('/sand-casting/report/qc-defects');
        $resQc->assertStatus(200);
        $resQc->assertSee('Laporan Defect (QC)');

        // SPV user does not see the Laporan Defect (QC) sidebar link
        $resSpv = $this->actingAs($this->spvUser)->get('/sand-casting/dashboard');
        $resSpv->assertStatus(200);
        $resSpv->assertDontSee('Laporan Defect (QC)');
    }

    // 2. Default date range and valid custom date filters
    public function test_default_date_range_and_valid_custom_date_filters(): void
    {
        $today = Carbon::now()->toDateString();
        $thirtyDaysAgo = Carbon::now()->subDays(29)->toDateString();

        $this->createExecutionWithDefects([
            'date' => $today,
            'defect_qty' => 5,
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 5],
            ],
        ]);

        $res = $this->actingAs($this->adminUser)->get('/sand-casting/report/qc-defects');
        $res->assertStatus(200);
        $res->assertViewHas('filters', function ($filters) use ($thirtyDaysAgo, $today) {
            return $filters['date_from'] === $thirtyDaysAgo && $filters['date_to'] === $today;
        });
        $res->assertSee('Porosity');

        // Valid custom date range
        $resCustom = $this->actingAs($this->adminUser)->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}");
        $resCustom->assertStatus(200);
        $resCustom->assertViewHas('filters', function ($filters) use ($today) {
            return $filters['date_from'] === $today && $filters['date_to'] === $today;
        });
    }

    // 3. Invalid date ranges and maximum allowed date range (45 calendar days inclusive)
    public function test_invalid_date_range_and_maximum_allowed_date_range(): void
    {
        // 3a. Start date later than end date is rejected
        $resInvalidOrder = $this->actingAs($this->adminUser)
            ->get('/sand-casting/report/qc-defects?date_from=2026-10-15&date_to=2026-10-10');
        $resInvalidOrder->assertSessionHasErrors(['date_from']);
        $resInvalidOrder->assertRedirect(route('sand-casting.report.qc-defects.index'));
        $resInvalidOrder->assertSessionHasInput('date_from', '2026-10-15');
        $resInvalidOrder->assertSessionHasInput('date_to', '2026-10-10');

        // 3b. Exactly 46 inclusive calendar days is rejected (2026-08-31 through 2026-10-15)
        $res46Days = $this->actingAs($this->adminUser)
            ->get('/sand-casting/report/qc-defects?date_from=2026-08-31&date_to=2026-10-15');
        $res46Days->assertSessionHasErrors(['date_to']);
        $res46Days->assertRedirect(route('sand-casting.report.qc-defects.index'));
        $res46Days->assertSessionHasInput('date_from', '2026-08-31');
        $res46Days->assertSessionHasInput('date_to', '2026-10-15');

        // 3c. Exactly 45 inclusive calendar days is accepted (2026-09-01 through 2026-10-15)
        $res45Days = $this->actingAs($this->adminUser)
            ->get('/sand-casting/report/qc-defects?date_from=2026-09-01&date_to=2026-10-15');
        $res45Days->assertStatus(200);
        $res45Days->assertViewHas('filters', function ($filters) {
            return $filters['date_from'] === '2026-09-01' && $filters['date_to'] === '2026-10-15';
        });

        // 3d. Relative 45-day calculation from today is also accepted
        $from45 = Carbon::now()->subDays(44)->toDateString();
        $to45 = Carbon::now()->toDateString();
        $resRelative45 = $this->actingAs($this->adminUser)
            ->get("/sand-casting/report/qc-defects?date_from={$from45}&date_to={$to45}");
        $resRelative45->assertStatus(200);
    }

    // 3e. Date range validations enforced identically on Excel and Print/PDF exports
    public function test_excel_and_print_export_enforce_date_validations(): void
    {
        // Excel: exactly 45 inclusive days is accepted
        $resExcel45 = $this->actingAs($this->adminUser)
            ->get('/sand-casting/report/qc-defects/export/excel?date_from=2026-09-01&date_to=2026-10-15&stage=all');
        $resExcel45->assertStatus(200);

        // Excel: 46 inclusive days is rejected, does not generate export file
        $resExcel46 = $this->actingAs($this->adminUser)
            ->get('/sand-casting/report/qc-defects/export/excel?date_from=2026-08-31&date_to=2026-10-15&stage=all');
        $resExcel46->assertSessionHasErrors(['date_to']);
        $resExcel46->assertRedirect(route('sand-casting.report.qc-defects.index'));

        // Excel: start date > end date is rejected, does not generate export file
        $resExcelInvalid = $this->actingAs($this->adminUser)
            ->get('/sand-casting/report/qc-defects/export/excel?date_from=2026-10-15&date_to=2026-10-10&stage=all');
        $resExcelInvalid->assertSessionHasErrors(['date_from']);
        $resExcelInvalid->assertRedirect(route('sand-casting.report.qc-defects.index'));

        // Print/PDF: exactly 45 inclusive days is accepted
        $resPrint45 = $this->actingAs($this->adminUser)
            ->get('/sand-casting/report/qc-defects/export/pdf?date_from=2026-09-01&date_to=2026-10-15&stage=all');
        $resPrint45->assertStatus(200);
        $resPrint45->assertViewIs('sand-casting.report.qc-defects.print');

        // Print/PDF: 46 inclusive days is rejected, does not render print template
        $resPrint46 = $this->actingAs($this->adminUser)
            ->get('/sand-casting/report/qc-defects/export/pdf?date_from=2026-08-31&date_to=2026-10-15&stage=all');
        $resPrint46->assertSessionHasErrors(['date_to']);
        $resPrint46->assertRedirect(route('sand-casting.report.qc-defects.index'));

        // Print/PDF: start date > end date is rejected, does not render print template
        $resPrintInvalid = $this->actingAs($this->adminUser)
            ->get('/sand-casting/report/qc-defects/export/pdf?date_from=2026-10-15&date_to=2026-10-10&stage=all');
        $resPrintInvalid->assertSessionHasErrors(['date_from']);
        $resPrintInvalid->assertRedirect(route('sand-casting.report.qc-defects.index'));
    }

    // 4. All-stage and individual-stage filtering
    public function test_all_stage_and_individual_stage_filtering(): void
    {
        $today = Carbon::now()->toDateString();

        // Netto defect
        $this->createExecutionWithDefects([
            'stage' => 'netto',
            'date' => $today,
            'defect_qty' => 3,
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 3],
            ],
        ]);

        // Bubut OD defect
        $this->createExecutionWithDefects([
            'stage' => 'bubut_od',
            'date' => $today,
            'defect_qty' => 7,
            'defects' => [
                ['defect_type_id' => $this->defectRetak->id, 'qty' => 7],
            ],
        ]);

        // Filter: all stages -> sees both
        $resAll = $this->actingAs($this->adminUser)->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}&stage=all");
        $resAll->assertStatus(200);
        $resAll->assertSee('Porosity');
        $resAll->assertSee('Retak');
        $summaryAll = $resAll->viewData('summary');
        $this->assertSame(10, $summaryAll['total_defect_pcs']);

        // Filter: netto only -> sees only Netto
        $resNetto = $this->actingAs($this->adminUser)->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}&stage=netto");
        $resNetto->assertStatus(200);
        $summaryNetto = $resNetto->viewData('summary');
        $this->assertSame(3, $summaryNetto['total_defect_pcs']);
        $this->assertSame('netto', $resNetto->viewData('filters')['stage']);

        // Filter: bubut_od only -> sees only Bubut OD
        $resOd = $this->actingAs($this->adminUser)->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}&stage=bubut_od");
        $resOd->assertStatus(200);
        $summaryOd = $resOd->viewData('summary');
        $this->assertSame(7, $summaryOd['total_defect_pcs']);
        $this->assertSame('bubut_od', $resOd->viewData('filters')['stage']);
    }

    // 5. Defect-type filtering, including the qc/finish compatibility rule
    public function test_defect_type_filtering_and_qc_finish_compatibility(): void
    {
        $today = Carbon::now()->toDateString();

        // Create QC stage execution with defect of type department 'finish'
        $this->createExecutionWithDefects([
            'stage' => 'qc',
            'date' => $today,
            'defect_qty' => 4,
            'defects' => [
                ['defect_type_id' => $this->defectFinish->id, 'qty' => 4],
            ],
        ]);

        // Dropdown options for stage 'qc' must include defect types with department 'finish'
        $stageDefectTypes = $this->reportService->getDefectTypesForStage('qc');
        $this->assertTrue($stageDefectTypes->contains('id', $this->defectFinish->id));

        // Filtering by defect_type_id of defectFinish works and returns the record
        $res = $this->actingAs($this->adminUser)->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}&stage=qc&defect_type_id={$this->defectFinish->id}");
        $res->assertStatus(200);
        $summary = $res->viewData('summary');
        $this->assertSame(4, $summary['total_defect_pcs']);
        $res->assertSee('Cacat Ulir Finish');
    }

    // 6. Exclusion of unconfirmed executions from defect totals
    public function test_exclusion_of_unconfirmed_executions(): void
    {
        $today = Carbon::now()->toDateString();

        // Confirmed execution: 5 pcs
        $this->createExecutionWithDefects([
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'date' => $today,
            'defect_qty' => 5,
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 5],
            ],
        ]);

        // Waiting QC execution: 8 pcs (MUST BE EXCLUDED)
        $this->createExecutionWithDefects([
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'date' => $today,
            'defect_qty' => 8,
            'defects' => [
                ['defect_type_id' => $this->defectRetak->id, 'qty' => 8],
            ],
        ]);

        // Waiting Defect execution: 12 pcs (MUST BE EXCLUDED)
        $this->createExecutionWithDefects([
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'date' => $today,
            'defect_qty' => 12,
            'defects' => [
                ['defect_type_id' => $this->defectRetak->id, 'qty' => 12],
            ],
        ]);

        $res = $this->actingAs($this->adminUser)->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}");
        $res->assertStatus(200);
        $summary = $res->viewData('summary');

        // Only confirmed defect qty (5 pcs) is included
        $this->assertSame(5, $summary['total_defect_pcs']);
        $this->assertSame(1, $summary['affected_ktr_count']);

        // Pareto summary only contains confirmed defect (Porosity), Retak is excluded
        $pareto = $res->viewData('paretoSummary');
        $this->assertCount(1, $pareto);
        $this->assertSame('Porosity', $pareto[0]['defect_name']);

        // Detailed records only contain confirmed defect
        $records = $res->viewData('records');
        $this->assertCount(1, $records);
        $this->assertSame('Porosity', $records[0]['defect_name']);
    }

    // 7. Correct aggregation of multiple defect-detail rows without double-counting parent defect_qty
    public function test_correct_aggregation_without_double_counting_parent_defect_qty(): void
    {
        $today = Carbon::now()->toDateString();

        // Parent execution has defect_qty = 10, split into 6 Porosity and 4 Retak
        $this->createExecutionWithDefects([
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'date' => $today,
            'defect_qty' => 10,
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 6],
                ['defect_type_id' => $this->defectRetak->id, 'qty' => 4],
            ],
        ]);

        $res = $this->actingAs($this->adminUser)->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}");
        $res->assertStatus(200);
        $summary = $res->viewData('summary');

        // Total should be exactly 6 + 4 = 10 pcs, NOT 10 (parent) + 10 (details) = 20
        $this->assertSame(10, $summary['total_defect_pcs']);
        $this->assertSame(1, $summary['affected_ktr_count']);
    }

    // 8. Correct grouping by defect type and stage in Pareto summary table
    public function test_pareto_grouping_by_defect_type_and_stage(): void
    {
        $today = Carbon::now()->toDateString();

        // Exec 1: Netto with 5 Porosity
        $this->createExecutionWithDefects([
            'stage' => 'netto',
            'date' => $today,
            'defect_qty' => 5,
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 5],
            ],
        ]);

        // Exec 2: Bubut OD with 3 Porosity
        $this->createExecutionWithDefects([
            'stage' => 'bubut_od',
            'date' => $today,
            'defect_qty' => 3,
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 3],
            ],
        ]);

        // Exec 3: Netto with 2 Retak
        $this->createExecutionWithDefects([
            'stage' => 'netto',
            'date' => $today,
            'defect_qty' => 2,
            'defects' => [
                ['defect_type_id' => $this->defectRetak->id, 'qty' => 2],
            ],
        ]);

        $res = $this->actingAs($this->adminUser)->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}");
        $res->assertStatus(200);
        $pareto = $res->viewData('paretoSummary');

        // Pareto must contain 3 groups: [Porosity @ Netto (5)], [Porosity @ Bubut OD (3)], [Retak @ Netto (2)]
        $this->assertCount(3, $pareto);

        $first = $pareto[0];
        $this->assertSame('Porosity', $first['defect_name']);
        $this->assertSame('netto', $first['stage']);
        $this->assertSame(5, $first['qty_pcs']);

        $second = $pareto[1];
        $this->assertSame('Porosity', $second['defect_name']);
        $this->assertSame('bubut_od', $second['stage']);
        $this->assertSame(3, $second['qty_pcs']);

        $third = $pareto[2];
        $this->assertSame('Retak', $third['defect_name']);
        $this->assertSame('netto', $third['stage']);
        $this->assertSame(2, $third['qty_pcs']);
    }

    // 9. Correct contribution percentages and zero-denominator handling
    public function test_contribution_percentages_and_zero_denominator_handling(): void
    {
        $today = Carbon::now()->toDateString();

        // Case A: Normal calculation with total = 20 pcs
        // Porosity: 15 pcs (75.0%), Retak: 5 pcs (25.0%)
        $this->createExecutionWithDefects([
            'stage' => 'netto',
            'date' => $today,
            'defect_qty' => 20,
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 15],
                ['defect_type_id' => $this->defectRetak->id, 'qty' => 5],
            ],
        ]);

        $res = $this->actingAs($this->adminUser)->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}");
        $pareto = $res->viewData('paretoSummary');

        $this->assertEquals(75.0, $pareto[0]['contribution_pct']);
        $this->assertEquals(25.0, $pareto[1]['contribution_pct']);

        // Case B: Zero denominator handling (empty date range)
        $pastDate = Carbon::now()->subDays(20)->toDateString();
        $resEmpty = $this->actingAs($this->adminUser)->get("/sand-casting/report/qc-defects?date_from={$pastDate}&date_to={$pastDate}");
        $resEmpty->assertStatus(200);
        $summaryEmpty = $resEmpty->viewData('summary');
        $this->assertSame(0, $summaryEmpty['total_defect_pcs']);
        $this->assertSame(0.0, $summaryEmpty['total_estimated_kg']);
        $this->assertCount(0, $resEmpty->viewData('paretoSummary'));
    }

    // 10. Search by KTR, Heat Number, production code, PO, item name, customer
    public function test_free_text_search_across_supported_fields(): void
    {
        $today = Carbon::now()->toDateString();

        $this->createExecutionWithDefects([
            'code' => 'CODE-ALPHA-99',
            'po_number' => 'PO-SPECIAL-888',
            'customer' => 'PT TARGET CUSTOMER',
            'item_name' => 'VALVE CASTING SPECIAL',
            'heat_number' => 'HEAT-OMEGA-77',
            'traveler_number' => 'KTR-ALPHA-12345',
            'date' => $today,
            'defect_qty' => 4,
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 4],
            ],
        ]);

        $this->createExecutionWithDefects([
            'code' => 'CODE-BETA-00',
            'po_number' => 'PO-OTHER-111',
            'customer' => 'PT OTHER CUSTOMER',
            'item_name' => 'OTHER FLANGE',
            'heat_number' => 'HEAT-BETA-22',
            'traveler_number' => 'KTR-OTHER-99999',
            'date' => $today,
            'defect_qty' => 2,
            'defects' => [
                ['defect_type_id' => $this->defectRetak->id, 'qty' => 2],
            ],
        ]);

        // Search by KTR
        $resKtr = $this->actingAs($this->adminUser)->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}&search=KTR-ALPHA");
        $resKtr->assertStatus(200);
        $this->assertSame(4, $resKtr->viewData('summary')['total_defect_pcs']);

        // Search by Heat Number
        $resHeat = $this->actingAs($this->adminUser)->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}&search=HEAT-OMEGA");
        $resHeat->assertStatus(200);
        $this->assertSame(4, $resHeat->viewData('summary')['total_defect_pcs']);

        // Search by Code
        $resCode = $this->actingAs($this->adminUser)->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}&search=ALPHA-99");
        $resCode->assertStatus(200);
        $this->assertSame(4, $resCode->viewData('summary')['total_defect_pcs']);

        // Search by Customer
        $resCust = $this->actingAs($this->adminUser)->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}&search=TARGET+CUSTOMER");
        $resCust->assertStatus(200);
        $this->assertSame(4, $resCust->viewData('summary')['total_defect_pcs']);

        // Search by Item Name
        $resItem = $this->actingAs($this->adminUser)->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}&search=VALVE+CASTING");
        $resItem->assertStatus(200);
        $this->assertSame(4, $resItem->viewData('summary')['total_defect_pcs']);
    }

    // 11. Product-scope and authorization isolation
    public function test_product_scope_isolation(): void
    {
        $today = Carbon::now()->toDateString();

        // Flange Besi defect: 5 pcs
        $this->createExecutionWithDefects([
            'product_scope' => 'FLANGE_BESI',
            'date' => $today,
            'defect_qty' => 5,
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 5],
            ],
        ]);

        // Flange Stainless defect: 10 pcs
        $this->createExecutionWithDefects([
            'product_scope' => 'FLANGE_STAINLESS',
            'date' => $today,
            'defect_qty' => 10,
            'defects' => [
                ['defect_type_id' => $this->defectRetak->id, 'qty' => 10],
            ],
        ]);

        // 1. QC Flange user whose stored product_scope is FLANGE_BESI can see both (15 pcs total), consistent with Zone 3
        $resQc = $this->actingAs($this->qcUser)->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}");
        $resQc->assertStatus(200);
        $this->assertSame(15, $resQc->viewData('summary')['total_defect_pcs']);

        // 2. PPIC Besi user remains strictly restricted to FLANGE_BESI (5 pcs)
        $resPpicBesi = $this->actingAs($this->ppicUser)->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}");
        $resPpicBesi->assertStatus(200);
        $this->assertSame(5, $resPpicBesi->viewData('summary')['total_defect_pcs']);

        // 3. PPIC Stainless user remains strictly restricted to FLANGE_STAINLESS (10 pcs)
        $resPpicStainless = $this->actingAs($this->ppicStainlessUser)->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}");
        $resPpicStainless->assertStatus(200);
        $this->assertSame(10, $resPpicStainless->viewData('summary')['total_defect_pcs']);

        // 4. Admin sees all 15 pcs
        $resAdmin = $this->actingAs($this->adminUser)->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}");
        $resAdmin->assertStatus(200);
        $this->assertSame(15, $resAdmin->viewData('summary')['total_defect_pcs']);

        // 5. A user cannot use a client-supplied filter parameter to bypass their authorized product scope
        $resBypassAttempt = $this->actingAs($this->ppicUser)
            ->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}&scope=FLANGE_STAINLESS");
        $resBypassAttempt->assertStatus(200);
        $this->assertSame(5, $resBypassAttempt->viewData('summary')['total_defect_pcs']);
    }

    // 11b. UAT exact scenario: QC user can see confirmed Netto PASIR JATUH on FLANGE_STAINLESS
    public function test_uat_netto_pasir_jatuh_scenario_for_qc_user(): void
    {
        $today = Carbon::now()->toDateString();

        // Confirmed Netto execution with PASIR JATUH (defect_type_id = defectPorosity) on FLANGE_STAINLESS plan
        $this->createExecutionWithDefects([
            'stage' => 'netto',
            'product_scope' => 'FLANGE_STAINLESS',
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'date' => $today,
            'defect_qty' => 8,
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 8],
            ],
        ]);

        // QC user with FLANGE_BESI in profile accesses report filtering for netto and defect
        $res = $this->actingAs($this->qcUser)
            ->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}&stage=netto&defect_type_id={$this->defectPorosity->id}");
        $res->assertStatus(200);
        $this->assertSame(8, $res->viewData('summary')['total_defect_pcs']);
        $this->assertCount(1, $res->viewData('records'));
    }

    // 12. Correct estimated-weight calculation and unavailable-weight handling
    public function test_estimated_weight_calculation_and_unavailable_weight_handling(): void
    {
        $today = Carbon::now()->toDateString();

        // Record with snapshot unit weight 3.50 kg: 4 pcs * 3.5 = 14.00 kg
        $this->createExecutionWithDefects([
            'unit_weight_kg' => 3.50,
            'weight' => 3.00,
            'date' => $today,
            'defect_qty' => 4,
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 4],
            ],
        ]);

        // Record without unit weight (0 on line, 0 on plan)
        $this->createExecutionWithDefects([
            'unit_weight_kg' => 0.00,
            'weight' => 0.00,
            'date' => $today,
            'defect_qty' => 2,
            'defects' => [
                ['defect_type_id' => $this->defectRetak->id, 'qty' => 2],
            ],
        ]);

        $res = $this->actingAs($this->adminUser)->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}");
        $res->assertStatus(200);
        $summary = $res->viewData('summary');

        // Total pcs is 6
        $this->assertSame(6, $summary['total_defect_pcs']);
        // Total estimated weight is 14.00 kg
        $this->assertEquals(14.00, $summary['total_estimated_kg']);
        // Data coverage is partial (4 of 6 pcs has weight, 66.7%)
        $this->assertSame(4, $summary['valid_weight_pcs']);
        $this->assertSame(2, $summary['unavailable_weight_pcs']);
        $this->assertFalse($summary['is_weight_fully_available']);
        $this->assertEquals(66.7, $summary['weight_coverage_pct']);
    }

    // 13. Screen summary totals matching export totals for the same filters
    public function test_screen_and_export_totals_parity(): void
    {
        $today = Carbon::now()->toDateString();

        $this->createExecutionWithDefects([
            'unit_weight_kg' => 2.00,
            'date' => $today,
            'defect_qty' => 12,
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 8],
                ['defect_type_id' => $this->defectRetak->id, 'qty' => 4],
            ],
        ]);

        $filters = [
            'date_from' => $today,
            'date_to' => $today,
            'stage' => 'all',
            'defect_type_id' => null,
            'search' => '',
        ];

        // Service called for screen (paginated)
        $screenData = $this->reportService->getReportDataset($filters, $this->adminUser, true, 25);
        // Service called for export (unpaginated)
        $exportData = $this->reportService->getReportDataset($filters, $this->adminUser, false);

        $this->assertSame($screenData['summary']['total_defect_pcs'], $exportData['summary']['total_defect_pcs']);
        $this->assertSame($screenData['summary']['total_estimated_kg'], $exportData['summary']['total_estimated_kg']);
        $this->assertSame($screenData['summary']['affected_ktr_count'], $exportData['summary']['affected_ktr_count']);
        $this->assertSame($screenData['summary']['top_defect_type'], $exportData['summary']['top_defect_type']);
        $this->assertCount(count($screenData['pareto_summary']), $exportData['pareto_summary']);
    }

    // 14. Excel response, headers, and exported data
    public function test_excel_export_response_and_headers(): void
    {
        $today = Carbon::now()->toDateString();

        $this->createExecutionWithDefects([
            'unit_weight_kg' => 2.50,
            'date' => $today,
            'defect_qty' => 6,
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 6],
            ],
        ]);

        $res = $this->actingAs($this->adminUser)
            ->get("/sand-casting/report/qc-defects/export/excel?date_from={$today}&date_to={$today}&stage=all");

        $res->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml.sheet', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Laporan_Defect_QC_Sand_Casting_', $res->headers->get('Content-Disposition'));
    }

    // 15. Print view renders the correct filtered report data
    public function test_print_view_renders_correct_data(): void
    {
        $today = Carbon::now()->toDateString();

        $this->createExecutionWithDefects([
            'unit_weight_kg' => 2.50,
            'date' => $today,
            'defect_qty' => 5,
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 5],
            ],
        ]);

        $res = $this->actingAs($this->adminUser)
            ->get("/sand-casting/report/qc-defects/export/pdf?date_from={$today}&date_to={$today}&stage=all");

        $res->assertStatus(200);
        $res->assertSee('LAPORAN RINCIAN DEFECT QC — SAND CASTING');
        $res->assertSee('Porosity');
        $res->assertSee('5 PCS');
        $res->assertSee('Estimasi Berat');
        $res->assertSee('window.print()');
    }

    // 16. Date fallback rule: inspection_date preferred, qc_verified_at fallback
    public function test_date_fallback_rule_inspection_date_vs_qc_verified_at(): void
    {
        $yesterday = Carbon::now()->subDays(1)->toDateString();
        $today = Carbon::now()->toDateString();

        // Execution inspected yesterday, but verified today
        $this->createExecutionWithDefects([
            'inspection_date' => $yesterday,
            'qc_verified_at' => Carbon::now(),
            'defect_qty' => 7,
            'defects' => [
                ['defect_type_id' => $this->defectPorosity->id, 'qty' => 7],
            ],
        ]);

        // When filtering for yesterday: should match based on inspection_date
        $resYesterday = $this->actingAs($this->adminUser)
            ->get("/sand-casting/report/qc-defects?date_from={$yesterday}&date_to={$yesterday}");
        $resYesterday->assertStatus(200);
        $this->assertSame(7, $resYesterday->viewData('summary')['total_defect_pcs']);

        // When filtering for today: should NOT match because inspection_date takes precedence over qc_verified_at
        $resToday = $this->actingAs($this->adminUser)
            ->get("/sand-casting/report/qc-defects?date_from={$today}&date_to={$today}");
        $resToday->assertStatus(200);
        $this->assertSame(0, $resToday->viewData('summary')['total_defect_pcs']);
    }
}
