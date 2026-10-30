<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use App\Services\SandCasting\SandCastingDashboardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DailyOutputKanbanWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $spvCncUser;

    protected User $spvQcUser;

    protected User $spvNettoUser;

    protected SandCastingDashboardService $dashboardService;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution']);

        $roleAdmin = Role::firstOrCreate(['name' => 'admin']);
        $roleAdmin->givePermissionTo([$permPlanning, $permExecution]);

        $roleSpv = Role::firstOrCreate(['name' => 'spv']);
        $roleSpv->givePermissionTo([$permExecution]);

        $this->adminUser = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@peroniks.com',
        ]);
        $this->adminUser->assignRole('admin');

        $this->spvCncUser = User::factory()->create([
            'name' => 'SPV Bubut CNC',
            'email' => 'spvcncfl@peroniks.com',
            'assigned_stage' => 'bubut_cnc',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvCncUser->assignRole('spv');

        $this->spvQcUser = User::factory()->create([
            'name' => 'SPV QC',
            'email' => 'spvqcfl@peroniks.com',
            'assigned_stage' => 'qc',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvQcUser->assignRole('spv');

        $this->spvNettoUser = User::factory()->create([
            'name' => 'SPV Netto',
            'email' => 'spvnettofl@peroniks.com',
            'assigned_stage' => 'netto',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvNettoUser->assignRole('spv');

        $this->dashboardService = app(SandCastingDashboardService::class);
    }

    protected function createPlanWithKtrLine(array $lineAttributes = [], array $planAttributes = []): array
    {
        static $seq = 1;
        $idx = $seq++;

        $plan = ProductionPlan::create(array_merge([
            'code' => 'PLAN-'.$idx,
            'title' => 'Rencana SC '.$idx,
            'item_code' => '4.'.$idx,
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-'.$idx,
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'customer' => 'PT SINAR METAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
            'weight' => 2.50,
        ], $planAttributes));

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-'.$idx,
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
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 250.00,
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'HEAT-'.$idx,
            'cast_date' => now()->toDateString(),
            'furnace' => 'F1',
            'shift' => '1',
            'recorded_by' => $this->adminUser->id,
        ]);

        $line = SandCastingCastingResultLine::create(array_merge([
            'sand_casting_casting_result_id' => $result->id,
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-'.now()->format('Ymd').'-'.sprintf('%04d', $idx),
            'qty_good' => 50,
            'qty_reject' => 0,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 125.00,
            'current_stage' => 'bubut_cnc',
        ], $lineAttributes));

        return [$plan, $line, $result];
    }

    public function test_cnc_kanban_displays_daily_output_widget_matching_zone_1(): void
    {
        [$plan, $line] = $this->createPlanWithKtrLine([
            'qty_good' => 80,
            'unit_weight_kg' => 2.0,
            'current_stage' => 'bubut_cnc',
        ]);

        // Record completed execution for Bubut CNC today
        $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_FINISH',
            'input_qty' => 80,
            'good_qty' => 75,
            'defect_qty' => 5,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => Carbon::now('Asia/Jakarta'),
            'executed_at' => Carbon::now('Asia/Jakarta'),
            'operator_id' => $this->spvCncUser->id,
        ]);

        Cache::flush();

        // 1. Verify Zone 1 throughput data
        $zone1Output = $this->dashboardService->getTodayStageOutput('bubut_cnc', $this->spvCncUser);
        $this->assertSame(75, $zone1Output['good_pcs']);
        $this->assertEquals(150.0, (float) $zone1Output['weight_kg']); // 75 pcs * 2.0 kg

        // 2. SPV CNC accesses CNC Kanban page
        $response = $this->actingAs($this->spvCncUser)
            ->get('/sand-casting/kanban/stage/bubut-cnc');

        $response->assertStatus(200);
        $response->assertSee('HASIL CNC HARI INI');
        $response->assertSee('75');
        $response->assertSee('150');
        $response->assertSee('PCS');
        $response->assertSee('KG');
    }

    public function test_qc_kanban_displays_daily_output_widget_matching_zone_1(): void
    {
        [$plan, $line] = $this->createPlanWithKtrLine([
            'qty_good' => 60,
            'unit_weight_kg' => 3.0,
            'current_stage' => 'qc',
        ]);

        // Record completed execution for QC today
        $line->stageExecutions()->create([
            'stage' => 'qc',
            'checkpoint_code' => 'QC_FINAL',
            'input_qty' => 60,
            'good_qty' => 58,
            'defect_qty' => 2,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => Carbon::now('Asia/Jakarta'),
            'executed_at' => Carbon::now('Asia/Jakarta'),
            'operator_id' => $this->spvQcUser->id,
        ]);

        Cache::flush();

        // 1. Verify Zone 1 throughput data
        $zone1Output = $this->dashboardService->getTodayStageOutput('qc', $this->spvQcUser);
        $this->assertSame(58, $zone1Output['good_pcs']);
        $this->assertEquals(174.0, (float) $zone1Output['weight_kg']); // 58 pcs * 3.0 kg

        // 2. SPV QC accesses QC Kanban page
        $response = $this->actingAs($this->spvQcUser)
            ->get('/sand-casting/kanban/stage/qc');

        $response->assertStatus(200);
        $response->assertSee('HASIL QC HARI INI');
        $response->assertSee('58');
        $response->assertSee('174');
        $response->assertSee('PCS');
        $response->assertSee('KG');
    }

    public function test_zero_output_displays_zero_pcs_and_zero_kg(): void
    {
        Cache::flush();

        // CNC zero output
        $cncRes = $this->actingAs($this->spvCncUser)
            ->get('/sand-casting/kanban/stage/bubut-cnc');

        $cncRes->assertStatus(200);
        $cncRes->assertSee('HASIL CNC HARI INI');
        $cncRes->assertSee('0');

        // QC zero output
        $qcRes = $this->actingAs($this->spvQcUser)
            ->get('/sand-casting/kanban/stage/qc');

        $qcRes->assertStatus(200);
        $qcRes->assertSee('HASIL QC HARI INI');
        $qcRes->assertSee('0');
    }

    public function test_other_stages_do_not_render_the_output_widget(): void
    {
        Cache::flush();

        $nonTargetStages = ['netto', 'bubut-od', 'bor', 'gudang-jadi'];

        foreach ($nonTargetStages as $stage) {
            $res = $this->actingAs($this->adminUser)
                ->get("/sand-casting/kanban/stage/{$stage}");

            $res->assertStatus(200);
            $res->assertDontSee('HASIL CNC HARI INI');
            $res->assertDontSee('HASIL QC HARI INI');
        }
    }

    public function test_widget_container_has_desktop_only_responsive_classes(): void
    {
        $response = $this->actingAs($this->spvCncUser)
            ->get('/sand-casting/kanban/stage/bubut-cnc');

        $response->assertStatus(200);

        // Verify container has hidden lg:flex classes for desktop-only rendering
        $html = $response->getContent();
        $this->assertStringContainsString('hidden lg:flex', $html);
        $this->assertStringContainsString('HASIL CNC HARI INI', $html);
    }

    public function test_unauthorized_user_cannot_access_full_dashboard(): void
    {
        $unauthorizedUser = User::factory()->create([
            'name' => 'General Staff',
            'email' => 'staff@peroniks.com',
        ]);

        $this->actingAs($unauthorizedUser)
            ->get('/sand-casting/dashboard')
            ->assertStatus(403);

        $this->actingAs($unauthorizedUser)
            ->getJson('/sand-casting/dashboard/data?zone=1')
            ->assertStatus(403);
    }

    public function test_kanban_does_not_require_dashboard_route_permissions(): void
    {
        // User with stage Kanban access can view Kanban with output widget
        // without altering or granting additional dashboard permissions
        $response = $this->actingAs($this->spvCncUser)
            ->get('/sand-casting/kanban/stage/bubut-cnc');

        $response->assertStatus(200);
        $response->assertSee('HASIL CNC HARI INI');

        $qcResponse = $this->actingAs($this->spvQcUser)
            ->get('/sand-casting/kanban/stage/qc');

        $qcResponse->assertStatus(200);
        $qcResponse->assertSee('HASIL QC HARI INI');
    }

    public function test_json_endpoint_includes_today_output_for_cnc_and_qc(): void
    {
        // Bubut CNC JSON includes today_output
        $cncRes = $this->actingAs($this->spvCncUser)
            ->getJson('/sand-casting/kanban/stage/bubut-cnc');

        $cncRes->assertStatus(200);
        $cncRes->assertJsonStructure([
            'today_output' => [
                'title',
                'good_pcs',
                'weight_kg',
                'stage',
            ],
        ]);
        $this->assertSame('HASIL CNC HARI INI', $cncRes->json('today_output.title'));
        $this->assertSame('bubut_cnc', $cncRes->json('today_output.stage'));

        // Netto JSON does not include today_output
        $nettoRes = $this->actingAs($this->spvNettoUser)
            ->getJson('/sand-casting/kanban/stage/netto');

        $nettoRes->assertStatus(200);
        $this->assertArrayNotHasKey('today_output', $nettoRes->json());
    }

    public function test_spv_with_different_product_scope_sees_actual_stage_output_when_production_is_under_another_scope(): void
    {
        Cache::flush();

        // SPV user has product_scope = FLANGE_BESI, but actual production plan today is FLANGE_STAINLESS
        [$plan, $line] = $this->createPlanWithKtrLine([
            'qty_good' => 100,
            'unit_weight_kg' => 4.0,
            'current_stage' => 'bubut_cnc',
        ], [
            'product_scope' => 'FLANGE_STAINLESS',
            'item_name' => 'FLANGE STAINLESS 304 3"',
        ]);

        $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_FINISH',
            'input_qty' => 100,
            'good_qty' => 90,
            'defect_qty' => 10,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => Carbon::now('Asia/Jakarta'),
            'executed_at' => Carbon::now('Asia/Jakarta'),
            'operator_id' => $this->spvCncUser->id,
        ]);

        Cache::flush();

        // SPV CNC must see 90 pcs / 360 kg even though user's product_scope is FLANGE_BESI
        $zone1Output = $this->dashboardService->getTodayStageOutput('bubut_cnc', $this->spvCncUser);
        $this->assertSame(90, $zone1Output['good_pcs']);
        $this->assertEquals(360.0, (float) $zone1Output['weight_kg']);

        $response = $this->actingAs($this->spvCncUser)
            ->get('/sand-casting/kanban/stage/bubut-cnc');

        $response->assertStatus(200);
        $response->assertSee('HASIL CNC HARI INI');
        $response->assertSee('90');
        $response->assertSee('360');

        // Sales user (scope null) must also see the same data from cache or fresh calculation
        $roleSales = Role::firstOrCreate(['name' => 'sales']);
        $permVisibility = Permission::firstOrCreate(['name' => 'access_production_visibility']);
        $roleSales->givePermissionTo($permVisibility);

        $salesUser = User::factory()->create([
            'name' => 'Sales Staff',
            'email' => 'sales_test@peroniks.com',
            'product_scope' => null,
        ]);
        $salesUser->assignRole('sales');

        $salesResponse = $this->actingAs($salesUser)
            ->get('/sand-casting/kanban/stage/bubut-cnc');

        $salesResponse->assertStatus(200);
        $salesResponse->assertSee('HASIL CNC HARI INI');
        $salesResponse->assertSee('90');
        $salesResponse->assertSee('360');
    }

    public function test_spv_initial_load_does_not_pollute_unscoped_cache(): void
    {
        Cache::flush();

        [$plan, $line] = $this->createPlanWithKtrLine([
            'qty_good' => 50,
            'unit_weight_kg' => 2.0,
            'current_stage' => 'qc',
        ], [
            'product_scope' => 'FLANGE_STAINLESS',
        ]);

        $line->stageExecutions()->create([
            'stage' => 'qc',
            'checkpoint_code' => 'QC_FINAL',
            'input_qty' => 50,
            'good_qty' => 45,
            'defect_qty' => 5,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => Carbon::now('Asia/Jakarta'),
            'executed_at' => Carbon::now('Asia/Jakarta'),
            'operator_id' => $this->spvQcUser->id,
        ]);

        Cache::flush();

        // SPV QC loads first, populating sc_dashboard_data_all_zone_1 cache
        $spvRes = $this->actingAs($this->spvQcUser)
            ->get('/sand-casting/kanban/stage/qc');
        $spvRes->assertStatus(200);
        $spvRes->assertSee('45');

        // Admin loads next - must see 45 pcs from cached or fresh data, not 0
        $adminRes = $this->actingAs($this->adminUser)
            ->get('/sand-casting/kanban/stage/qc');
        $adminRes->assertStatus(200);
        $adminRes->assertSee('45');
    }
}
