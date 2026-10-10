<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $ppicUser;

    protected User $qcUser;

    protected User $spvUser;

    protected User $unauthorizedUser;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

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

        $this->unauthorizedUser = User::factory()->create([
            'name' => 'General Staff',
            'email' => 'staff@peroniks.com',
        ]);
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
            'current_stage' => 'netto',
        ], $lineAttributes));

        return [$plan, $line, $result];
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get('/sand-casting/dashboard')->assertRedirect(route('login'));
        $this->get('/sand-casting/dashboard/data')->assertRedirect(route('login'));
    }

    public function test_unauthorized_user_is_forbidden_403(): void
    {
        $this->actingAs($this->unauthorizedUser)
            ->get('/sand-casting/dashboard')
            ->assertStatus(403);

        $this->actingAs($this->unauthorizedUser)
            ->get('/sand-casting/dashboard/data')
            ->assertStatus(403);
    }

    public function test_authorized_roles_can_access_dashboard_and_data(): void
    {
        foreach ([$this->adminUser, $this->ppicUser, $this->qcUser, $this->spvUser] as $user) {
            // HTML Browser request renders dashboard Blade view
            $this->actingAs($user)
                ->get('/sand-casting/dashboard')
                ->assertStatus(200)
                ->assertViewIs('sand-casting.dashboard.index')
                ->assertViewHas('activeZone', 'all');

            // JSON API / AJAX request returns JSON status
            $this->actingAs($user)
                ->getJson('/sand-casting/dashboard')
                ->assertStatus(200)
                ->assertJsonStructure(['status', 'active_zone', 'data_endpoint']);

            // Data endpoint returns structured 3-zone payload
            $this->actingAs($user)
                ->get('/sand-casting/dashboard/data')
                ->assertStatus(200)
                ->assertJsonStructure(['meta', 'zone_1', 'zone_2', 'zone_3']);
        }
    }

    public function test_dashboard_blade_view_respects_zone_query_parameters(): void
    {
        foreach (['1', '2', '3'] as $z) {
            $this->actingAs($this->adminUser)
                ->get("/sand-casting/dashboard?zone={$z}")
                ->assertStatus(200)
                ->assertViewIs('sand-casting.dashboard.index')
                ->assertViewHas('activeZone', $z);
        }
    }

    public function test_invalid_zone_parameter_is_rejected_422(): void
    {
        $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/dashboard/data?zone=invalid')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['zone']);

        $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/dashboard/data?zone=4')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['zone']);
    }

    public function test_valid_zone_filters_return_exact_requested_sections(): void
    {
        // Zone = all
        $resAll = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=all');
        $resAll->assertStatus(200);
        $resAll->assertJsonStructure(['meta', 'zone_1', 'zone_2', 'zone_3']);
        $this->assertSame('all', $resAll->json('meta.requested_zone'));

        // Zone = 1
        $res1 = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=1');
        $res1->assertStatus(200);
        $res1->assertJsonStructure(['meta', 'zone_1']);
        $this->assertArrayNotHasKey('zone_2', $res1->json());
        $this->assertArrayNotHasKey('zone_3', $res1->json());
        $this->assertSame('1', $res1->json('meta.requested_zone'));

        // Zone = 2
        $res2 = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=2');
        $res2->assertStatus(200);
        $res2->assertJsonStructure(['meta', 'zone_2']);
        $this->assertArrayNotHasKey('zone_1', $res2->json());
        $this->assertArrayNotHasKey('zone_3', $res2->json());
        $this->assertSame('2', $res2->json('meta.requested_zone'));

        // Zone = 3
        $res3 = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=3');
        $res3->assertStatus(200);
        $res3->assertJsonStructure(['meta', 'zone_3']);
        $this->assertArrayNotHasKey('zone_1', $res3->json());
        $this->assertArrayNotHasKey('zone_2', $res3->json());
        $this->assertSame('3', $res3->json('meta.requested_zone'));
    }

    public function test_empty_state_returns_predictable_zero_structures(): void
    {
        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=all');
        $res->assertStatus(200);

        // Zone 1 zeros
        $this->assertSame(0, $res->json('zone_1.casting_today.good_pcs'));
        $this->assertSame(0.0, (float) $res->json('zone_1.casting_today.good_ton'));
        $this->assertSame(0, $res->json('zone_1.casting_today.reject_pcs'));
        $this->assertSame(0, $res->json('zone_1.casting_today.heat_count'));
        $this->assertSame(0, $res->json('zone_1.warehouse_receipt_today.good_pcs'));

        // Zone 2 zeros
        $this->assertSame(0, $res->json('zone_2.total_active_wip_pcs'));
        $this->assertSame(0.0, (float) $res->json('zone_2.total_active_wip_ton'));
        $this->assertCount(5, $res->json('zone_2.wip_distribution'));
        $this->assertCount(0, $res->json('zone_2.aging_watchlist'));

        // Zone 3 zeros
        $this->assertSame(0, $res->json('zone_3.action_cards.unrecorded_defects.ktr_count'));
        $this->assertSame(0, $res->json('zone_3.action_cards.auto_nihil_waiting_qc.ktr_count'));
        $this->assertSame(0, $res->json('zone_3.action_cards.ppic_defect_waiting_qc.ktr_count'));
        $this->assertSame(0, $res->json('zone_3.defects_today.total_defect_pcs'));
    }

    public function test_zone_1_reconciles_casting_output_and_warehouse_receipt(): void
    {
        // Line 1: good 100 pcs, reject 5 pcs, weight 2.0 kg
        [$plan1, $line1, $result1] = $this->createPlanWithKtrLine([
            'qty_good' => 100,
            'qty_reject' => 5,
            'unit_weight_kg' => 2.00,
            'total_weight_kg' => 200.00,
        ]);

        // Line 2: good 50 pcs, reject 2 pcs, weight 3.0 kg
        [$plan2, $line2, $result2] = $this->createPlanWithKtrLine([
            'qty_good' => 50,
            'qty_reject' => 2,
            'unit_weight_kg' => 3.00,
            'total_weight_kg' => 150.00,
        ]);

        // Gudang receive checkpoint for Line 1
        $line1->stageExecutions()->create([
            'stage' => 'gudang_jadi',
            'checkpoint_code' => 'GUDANG_RECEIVE',
            'input_qty' => 100,
            'defect_qty' => 0,
            'good_qty' => 100,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=1&fresh=1');
        $res->assertStatus(200);

        // Good: 100 * 2.0 = 200kg, 50 * 3.0 = 150kg => 350kg = 0.35 Ton
        $this->assertSame(150, $res->json('zone_1.casting_today.good_pcs'));
        $this->assertEquals(0.35, $res->json('zone_1.casting_today.good_ton'));

        // Reject: 5 * 2.0 = 10kg, 2 * 3.0 = 6kg => 16kg = 0.02 Ton
        $this->assertSame(7, $res->json('zone_1.casting_today.reject_pcs'));
        $this->assertEquals(0.02, $res->json('zone_1.casting_today.reject_ton'));

        $this->assertSame(2, $res->json('zone_1.casting_today.heat_count'));

        // Warehouse receipt: 100 pcs, 200kg = 0.20 Ton
        $this->assertSame(100, $res->json('zone_1.warehouse_receipt_today.good_pcs'));
        $this->assertEquals(0.20, $res->json('zone_1.warehouse_receipt_today.good_ton'));
    }

    public function test_zone_2_wip_isolates_terminal_gudang_jadi_and_halted_ktrs(): void
    {
        // 1. Active KTR in Netto (usable 40 pcs, 2.5 kg)
        [$plan1, $line1] = $this->createPlanWithKtrLine([
            'qty_good' => 40,
            'current_stage' => 'netto',
            'unit_weight_kg' => 2.50,
        ]);

        // 2. Active KTR in Bubut OD (usable 30 pcs, 2.0 kg)
        [$plan2, $line2] = $this->createPlanWithKtrLine([
            'qty_good' => 30,
            'current_stage' => 'bubut_od',
            'unit_weight_kg' => 2.00,
        ]);

        // 3. Completed KTR in Gudang Jadi (terminal, must not be WIP)
        [$plan3, $line3] = $this->createPlanWithKtrLine([
            'qty_good' => 50,
            'current_stage' => 'completed',
            'unit_weight_kg' => 2.00,
        ]);

        // 4. Halted KTR in Bubut CNC (confirmed good_qty = 0)
        [$plan4, $line4] = $this->createPlanWithKtrLine([
            'qty_good' => 20,
            'current_stage' => 'bubut_cnc',
            'unit_weight_kg' => 2.00,
        ]);
        $line4->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 20,
            'defect_qty' => 20,
            'good_qty' => 0,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=2&fresh=1');
        $res->assertStatus(200);

        // Active WIP should sum ONLY Line 1 (40 pcs) + Line 2 (30 pcs) = 70 pcs
        $this->assertSame(70, $res->json('zone_2.total_active_wip_pcs'));

        // Weight: 40 * 2.5 = 100kg (0.10 Ton), 30 * 2.0 = 60kg (0.06 Ton) => 0.16 Ton
        $this->assertEquals(0.16, $res->json('zone_2.total_active_wip_ton'));

        $dist = collect($res->json('zone_2.wip_distribution'))->keyBy('stage');
        $this->assertSame(40, $dist['netto']['wip_pcs']);
        $this->assertSame(30, $dist['bubut_od']['wip_pcs']);
        $this->assertSame(0, $dist['bubut_cnc']['wip_pcs']); // Halted excluded
        $this->assertSame(0, $dist['bor']['wip_pcs']);
        $this->assertSame(0, $dist['qc']['wip_pcs']);

        // Gudang jadi is designated terminal non-wip
        $this->assertFalse($res->json('zone_2.terminal_stage.is_wip'));
    }

    public function test_zone_3_separates_unrecorded_auto_nihil_and_ppic_qc_backlog(): void
    {
        [$plan, $line] = $this->createPlanWithKtrLine();

        // 1. WAITING_DEFECT (recent physical done)
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

        // 2. AUTO-NIHIL WAITING_QC
        $line->stageExecutions()->create([
            'stage' => 'bubut_od',
            'checkpoint_code' => 'OD_TURNING',
            'input_qty' => 50,
            'defect_qty' => 0,
            'good_qty' => 50,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'is_auto_nihil' => true,
            'auto_nihil_at' => now(),
            'physical_done_at' => now()->subDays(6),
            'executed_at' => now()->subDays(6),
            'operator_id' => $this->spvUser->id,
        ]);

        // 3. DEFECT WAITING_QC (PPIC entered defect 4 pcs)
        $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 50,
            'defect_qty' => 4,
            'good_qty' => 46,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'is_auto_nihil' => false,
            'defect_entered_at' => now(),
            'inspection_date' => now()->toDateString(),
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        // 4. PRE-ACTIVATION WAITING_DEFECT (physical done before 2026-10-09)
        $line->stageExecutions()->create([
            'stage' => 'bor',
            'checkpoint_code' => 'BOR_DRILLING',
            'input_qty' => 50,
            'defect_qty' => 0,
            'good_qty' => 50,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'physical_done_at' => Carbon::parse('2026-10-01 10:00:00'),
            'executed_at' => Carbon::parse('2026-10-01 10:00:00'),
            'operator_id' => $this->spvUser->id,
        ]);

        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=3&fresh=1');
        $res->assertStatus(200);

        $cards = $res->json('zone_3.action_cards');
        $this->assertSame(1, $cards['unrecorded_defects']['ktr_count']);
        $this->assertSame(1, $cards['auto_nihil_waiting_qc']['ktr_count']);
        $this->assertSame(1, $cards['ppic_defect_waiting_qc']['ktr_count']);
        $this->assertSame(4, $cards['ppic_defect_waiting_qc']['pcs_defect']);
        $this->assertSame(1, $cards['pre_activation_backlog']['ktr_count']);
    }

    public function test_cache_isolation_by_product_scope_and_zone(): void
    {
        // Flange Plan & KTR (product_scope = FLANGE_BESI)
        $this->createPlanWithKtrLine(
            ['qty_good' => 100],
            ['product_scope' => 'FLANGE_BESI']
        );

        // Fitting Plan & KTR (product_scope = FITTING_BESI)
        $this->createPlanWithKtrLine(
            ['qty_good' => 50],
            ['product_scope' => 'FITTING_BESI']
        );

        // 1. PPIC user with scope FLANGE_BESI
        $resScoped = $this->actingAs($this->ppicUser)->getJson('/sand-casting/dashboard/data?zone=1');
        $resScoped->assertStatus(200);
        $this->assertSame(100, $resScoped->json('zone_1.casting_today.good_pcs'));
        $this->assertSame('FLANGE_BESI', $resScoped->json('meta.user_scope'));

        // 2. Admin user without scope (sees all: 100 + 50 = 150 pcs)
        $resUnscoped = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=1');
        $resUnscoped->assertStatus(200);
        $this->assertSame(150, $resUnscoped->json('zone_1.casting_today.good_pcs'));
        $this->assertSame('all', $resUnscoped->json('meta.user_scope'));

        // Verify distinct cache keys exist
        $this->assertTrue(Cache::has('sc_dashboard_data_FLANGE_BESI_zone_1'));
        $this->assertTrue(Cache::has('sc_dashboard_data_all_zone_1'));
    }

    public function test_fallback_weight_when_line_weight_is_zero(): void
    {
        [$plan, $line] = $this->createPlanWithKtrLine(
            [
                'qty_good' => 100,
                'unit_weight_kg' => 0.00, // zero on line
                'total_weight_kg' => 0.00,
            ],
            [
                'weight' => 4.00, // fallback on plan
            ]
        );

        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=1&fresh=1');
        $res->assertStatus(200);

        // 100 * 4.0kg = 400kg = 0.40 Ton
        $this->assertEquals(0.40, $res->json('zone_1.casting_today.good_ton'));
    }

    public function test_inspection_date_precedence_and_fallback(): void
    {
        [$plan1, $line1] = $this->createPlanWithKtrLine(['qty_good' => 50]);
        [$plan2, $line2] = $this->createPlanWithKtrLine(['qty_good' => 50]);

        $twoDaysAgo = now()->subDays(2)->toDateString();
        $today = now()->toDateString();

        // Line 1: inspection_date is 2 days ago, but defect was recorded today
        $line1->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 50,
            'defect_qty' => 8,
            'good_qty' => 42,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'is_auto_nihil' => false,
            'inspection_date' => $twoDaysAgo,
            'defect_entered_at' => now(),
            'executed_at' => now()->subDays(2),
            'physical_done_at' => now()->subDays(2),
            'operator_id' => $this->spvUser->id,
        ]);

        // Line 2: inspection_date is NULL, defect entered today
        $line2->stageExecutions()->create([
            'stage' => 'bubut_od',
            'checkpoint_code' => 'OD_TURNING',
            'input_qty' => 50,
            'defect_qty' => 5,
            'good_qty' => 45,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'is_auto_nihil' => false,
            'inspection_date' => null,
            'defect_entered_at' => now(),
            'executed_at' => now(),
            'physical_done_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=3&fresh=1');
        $res->assertStatus(200);

        $trendByDate = collect($res->json('zone_3.defect_trend_7_days'))->keyBy('date');

        // Line 1 must be bucketed on twoDaysAgo (inspection_date precedence)
        $this->assertSame(8, $trendByDate[$twoDaysAgo]['defect_pcs']);

        // Line 2 must be bucketed on today (fallback when inspection_date is null)
        $this->assertSame(5, $trendByDate[$today]['defect_pcs']);
    }

    public function test_seven_day_defect_trend_completeness_and_zero_values(): void
    {
        [$plan, $line] = $this->createPlanWithKtrLine(['qty_good' => 50]);

        // Only one defect 4 days ago
        $fourDaysAgo = now()->subDays(4)->toDateString();
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 50,
            'defect_qty' => 3,
            'good_qty' => 47,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'is_auto_nihil' => false,
            'inspection_date' => $fourDaysAgo,
            'executed_at' => now()->subDays(4),
            'physical_done_at' => now()->subDays(4),
            'operator_id' => $this->spvUser->id,
        ]);

        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=3&fresh=1');
        $res->assertStatus(200);

        $trend = $res->json('zone_3.defect_trend_7_days');

        // Must contain exactly 7 consecutive days
        $this->assertCount(7, $trend);

        // First day is 6 days ago, last day is today
        $this->assertSame(now()->subDays(6)->toDateString(), $trend[0]['date']);
        $this->assertSame(now()->toDateString(), $trend[6]['date']);

        $trendMap = collect($trend)->keyBy('date');
        $this->assertSame(3, $trendMap[$fourDaysAgo]['defect_pcs']);

        // Days with no defects must have exact zero values
        $zeroDay = now()->subDays(5)->toDateString();
        $this->assertSame(0, $trendMap[$zeroDay]['defect_pcs']);
        $this->assertEquals(0.0, (float) $trendMap[$zeroDay]['defect_ton']);
    }

    public function test_per_stage_throughput_tonnage_and_metrics(): void
    {
        [$plan, $line] = $this->createPlanWithKtrLine([
            'qty_good' => 100,
            'qty_reject' => 10,
            'unit_weight_kg' => 2.00,
        ]);

        // Netto execution
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

        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=1&fresh=1');
        $res->assertStatus(200);

        $stages = collect($res->json('zone_1.daily_throughput_stages'))->keyBy('stage');

        // Exactly 7 stages
        $this->assertCount(7, $stages);
        $this->assertTrue($stages->has(['cor', 'netto', 'bubut_od', 'bubut_cnc', 'bor', 'qc', 'gudang_jadi']));

        // COR: good 100 pcs, 200kg = 0.20 Ton, reject 10 pcs
        $cor = $stages['cor'];
        $this->assertSame(100, $cor['good_pcs']);
        $this->assertEquals(0.20, $cor['good_tonnage']);
        $this->assertSame(10, $cor['defect_pcs']);

        // Netto: input 100, good 95 pcs (190kg = 0.19 Ton), defect 5 pcs (defect rate 5%)
        $netto = $stages['netto'];
        $this->assertSame(100, $netto['input_pcs']);
        $this->assertSame(95, $netto['good_pcs']);
        $this->assertEquals(0.19, $netto['good_tonnage']);
        $this->assertSame(5, $netto['defect_pcs']);
        $this->assertEquals(5.0, (float) $netto['defect_rate_pct']);
    }

    public function test_zone_1_production_code_health_metrics(): void
    {
        // Plan 1: Active, short on COR (po_quantity 100, cast 50)
        [$plan1, $line1] = $this->createPlanWithKtrLine(
            ['qty_good' => 50],
            ['code' => 'CODE-A', 'po_quantity' => 100, 'qty_planned' => 100]
        );

        // Plan 2: Completed (po_quantity 50, received 50 in gudang jadi)
        [$plan2, $line2] = $this->createPlanWithKtrLine(
            ['qty_good' => 50],
            ['code' => 'CODE-B', 'po_quantity' => 50, 'qty_planned' => 50]
        );
        $line2->stageExecutions()->create([
            'stage' => 'gudang_jadi',
            'checkpoint_code' => 'GUDANG_RECEIVE',
            'input_qty' => 50,
            'defect_qty' => 0,
            'good_qty' => 50,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=1&fresh=1');
        $res->assertStatus(200);

        $health = $res->json('zone_1.production_code_health');
        $this->assertSame(1, $health['active_production_code_count']);
        $this->assertSame(1, $health['cor_shortage_count']);
        $this->assertSame(1, $health['completed_production_code_count']);

        // Gudang jadi received today counts completed terminal KTRs
        $this->assertSame(1, $res->json('zone_1.warehouse_receipt_today.ktr_received_today'));
    }

    public function test_wip_behavior_when_upstream_defect_remains_unresolved(): void
    {
        // Line starts with 50 good pieces at Cor, now physically in Bubut OD
        [$plan, $line] = $this->createPlanWithKtrLine([
            'qty_good' => 50,
            'current_stage' => 'bubut_od',
            'unit_weight_kg' => 2.00,
        ]);

        // Netto execution: physical done, but defect pending (WAITING_DEFECT, defect_qty = 0)
        $nettoExec = $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 50,
            'defect_qty' => 0,
            'good_qty' => 50,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'is_auto_nihil' => false,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        // Phase A: Unresolved defect - Bubut OD usable WIP is 50
        $resA = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=2&fresh=1');
        $resA->assertStatus(200);
        $wipA = collect($resA->json('zone_2.wip_distribution'))->keyBy('stage');
        $this->assertSame(50, $wipA['bubut_od']['wip_pcs']);

        // Phase B: PPIC records 10 defects on Netto (WAITING_QC)
        $nettoExec->update([
            'defect_qty' => 10,
            'good_qty' => 40,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'defect_entered_at' => now(),
        ]);

        // Bubut OD usable WIP immediately resolves to 40 (50 - 10)
        $resB = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=2&fresh=1');
        $resB->assertStatus(200);
        $wipB = collect($resB->json('zone_2.wip_distribution'))->keyBy('stage');
        $this->assertSame(40, $wipB['bubut_od']['wip_pcs']);
    }

    public function test_legacy_dashboard_and_defects_routes_unaffected(): void
    {
        if (\Illuminate\Support\Facades\DB::connection()->getDriverName() === 'sqlite') {
            /** @var \PDO $pdo */
            $pdo = \Illuminate\Support\Facades\DB::connection()->getPdo();
            $pdo->sqliteCreateFunction('YEARWEEK', function ($date, $mode = 0) {
                if (! $date) {
                    return null;
                }

                return (int) date('oW', strtotime($date));
            });
        }

        $this->actingAs($this->adminUser)
            ->get('/dashboard')
            ->assertStatus(200);

        $this->actingAs($this->adminUser)
            ->get('/dashboard/defects')
            ->assertStatus(200);
    }

    public function test_sidebar_displays_sand_casting_dashboard_for_authorized_users(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/sand-casting/dashboard');
        $response->assertOk();
        $response->assertSee('Sand Casting Dashboard');
        $response->assertSee(route('sand-casting.dashboard'));
        $response->assertSee('border-blue-500');
    }

    public function test_sidebar_maintains_active_state_for_all_zone_query_parameters(): void
    {
        foreach (['1', '2', '3'] as $z) {
            $response = $this->actingAs($this->adminUser)->get("/sand-casting/dashboard?zone={$z}");
            $response->assertOk();
            $response->assertSee('Sand Casting Dashboard');
            $response->assertSee('border-blue-500');
        }
    }

    public function test_sidebar_hides_sand_casting_dashboard_for_unauthorized_users(): void
    {
        $response = $this->actingAs($this->unauthorizedUser)->get('/dashboard');
        $response->assertOk();
        $response->assertDontSee('Sand Casting Dashboard');
    }

    public function test_zone_3_queues_are_mutually_exclusive_and_never_double_count(): void
    {
        [$plan, $line] = $this->createPlanWithKtrLine();

        // 1. WAITING_DEFECT (active recent) -> Card 1
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

        // 2. AUTO-NIHIL WAITING_QC (is_auto_nihil = true) -> Card 2
        $line->stageExecutions()->create([
            'stage' => 'bubut_od',
            'checkpoint_code' => 'OD_TURNING',
            'input_qty' => 50,
            'defect_qty' => 0,
            'good_qty' => 50,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'is_auto_nihil' => true,
            'auto_nihil_at' => now(),
            'physical_done_at' => now()->subDays(6),
            'executed_at' => now()->subDays(6),
            'operator_id' => $this->spvUser->id,
        ]);

        // 3. DEFECT WAITING_QC (PPIC input defect 7 pcs, is_auto_nihil = false) -> Card 3
        $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 50,
            'defect_qty' => 7,
            'good_qty' => 43,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'is_auto_nihil' => false,
            'defect_entered_at' => now(),
            'inspection_date' => now()->toDateString(),
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        // 4. MANUAL NIHIL WAITING_QC (PPIC manually recorded 0 defect, is_auto_nihil = false) -> Card 3
        $line->stageExecutions()->create([
            'stage' => 'bor',
            'checkpoint_code' => 'BOR_DRILLING',
            'input_qty' => 50,
            'defect_qty' => 0,
            'good_qty' => 50,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'is_auto_nihil' => false,
            'defect_entered_at' => now(),
            'inspection_date' => now()->toDateString(),
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        // 5. PRE-ACTIVATION WAITING_DEFECT (physical done before 2026-10-09) -> Card 4
        $line->stageExecutions()->create([
            'stage' => 'qc',
            'checkpoint_code' => 'QC_FINAL',
            'input_qty' => 50,
            'defect_qty' => 0,
            'good_qty' => 50,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'physical_done_at' => Carbon::parse('2026-10-01 10:00:00'),
            'executed_at' => Carbon::parse('2026-10-01 10:00:00'),
            'operator_id' => $this->spvUser->id,
        ]);

        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=3&fresh=1');
        $res->assertStatus(200);

        $cards = $res->json('zone_3.action_cards');
        $this->assertSame(1, $cards['unrecorded_defects']['ktr_count']);
        $this->assertSame(1, $cards['auto_nihil_waiting_qc']['ktr_count']);
        $this->assertSame(2, $cards['ppic_defect_waiting_qc']['ktr_count'], 'Card 3 must contain both positive defect and manual PPIC zero-defect');
        $this->assertSame(7, $cards['ppic_defect_waiting_qc']['pcs_defect']);
        $this->assertSame(1, $cards['pre_activation_backlog']['ktr_count']);

        // Verify total across all queues is exactly 5 (zero double counting)
        $totalQueued = $cards['unrecorded_defects']['ktr_count']
            + $cards['auto_nihil_waiting_qc']['ktr_count']
            + $cards['ppic_defect_waiting_qc']['ktr_count']
            + $cards['pre_activation_backlog']['ktr_count'];
        $this->assertSame(5, $totalQueued);
    }

    public function test_zone_3_gracefully_handles_schema_without_auto_nihil_column(): void
    {
        [$plan, $line] = $this->createPlanWithKtrLine();
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 20,
            'defect_qty' => 0,
            'good_qty' => 20,
            'status' => SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        // Temporarily simulate missing is_auto_nihil column via reflection
        $ref = new \ReflectionClass(\App\Services\SandCasting\SandCastingDashboardService::class);
        $prop = $ref->getProperty('hasAutoNihilColumn');
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        try {
            /** @var \App\Services\SandCasting\SandCastingDashboardService $service */
            $service = app(\App\Services\SandCasting\SandCastingDashboardService::class);
            $data = $service->getData('3', $this->adminUser, true);

            $this->assertIsArray($data['zone_3']);
            $cards = $data['zone_3']['action_cards'];
            $this->assertSame(1, $cards['unrecorded_defects']['ktr_count']);
            $this->assertSame(0, $cards['auto_nihil_waiting_qc']['ktr_count']);
        } finally {
            \App\Services\SandCasting\SandCastingDashboardService::resetSchemaCache();
        }
    }
}
