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

        // Exactly 6 stages (Phase 5G: Cor excluded from Zone 1 throughput chart)
        $this->assertCount(6, $stages);
        $this->assertFalse($stages->has('cor'));
        $this->assertTrue($stages->has(['netto', 'bubut_od', 'bubut_cnc', 'bor', 'qc', 'gudang_jadi']));

        // Fixed order: Netto, Bubut OD, Bubut CNC, Bor, QC, Gudang Jadi
        $stageOrder = collect($res->json('zone_1.daily_throughput_stages'))->pluck('stage')->all();
        $this->assertSame(['netto', 'bubut_od', 'bubut_cnc', 'bor', 'qc', 'gudang_jadi'], $stageOrder);

        // Verify COR is still present in casting_today and other aggregates
        $this->assertSame(100, $res->json('zone_1.casting_today.good_pcs'));
        $this->assertEquals(0.20, (float) $res->json('zone_1.casting_today.good_tonnage'));
        $this->assertSame(10, $res->json('zone_1.casting_today.reject_pcs'));

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

    public function test_zone_3_quality_analytics_30_day_trend_verified_filtering_and_empty_handling(): void
    {
        [$plan1, $line1] = $this->createPlanWithKtrLine(['qty_good' => 50]);
        [$plan2, $line2] = $this->createPlanWithKtrLine(['qty_good' => 50]);
        [$plan3, $line3] = $this->createPlanWithKtrLine(['qty_good' => 50]);

        $tenDaysAgo = now()->subDays(10)->toDateString();
        $threeDaysAgo = now()->subDays(3)->toDateString();

        // 1. Confirmed defect 10 days ago (must appear in 30-day trend)
        $line1->stageExecutions()->create([
            'stage' => 'bubut_od',
            'checkpoint_code' => 'OD_TURNING',
            'input_qty' => 50,
            'defect_qty' => 7,
            'good_qty' => 43,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'inspection_date' => $tenDaysAgo,
            'qc_verified_at' => now()->subDays(10),
            'operator_id' => $this->spvUser->id,
            'executed_at' => now()->subDays(10),
            'physical_done_at' => now()->subDays(10),
        ]);

        // 2. Unverified defect 3 days ago (WAITING_QC) - MUST NOT appear in 30-day trend
        $line2->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 50,
            'defect_qty' => 12,
            'good_qty' => 38,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'inspection_date' => $threeDaysAgo,
            'operator_id' => $this->spvUser->id,
            'executed_at' => now()->subDays(3),
            'physical_done_at' => now()->subDays(3),
        ]);

        // 3. Confirmed zero-defect execution 3 days ago - MUST have status 'zero_defect' and defect_pcs = 0
        $line3->stageExecutions()->create([
            'stage' => 'bor',
            'checkpoint_code' => 'BOR_DRILLING',
            'input_qty' => 50,
            'defect_qty' => 0,
            'good_qty' => 50,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'inspection_date' => $threeDaysAgo,
            'qc_verified_at' => now()->subDays(3),
            'operator_id' => $this->spvUser->id,
            'executed_at' => now()->subDays(3),
            'physical_done_at' => now()->subDays(3),
        ]);

        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=3&fresh=1');
        $res->assertStatus(200);

        $trend30 = $res->json('zone_3.defect_trend_30_days');
        $this->assertCount(30, $trend30);

        $trendMap = collect($trend30)->keyBy('date');

        // Ten days ago has 7 confirmed defect pcs
        $this->assertSame(7, $trendMap[$tenDaysAgo]['defect_pcs']);
        $this->assertTrue($trendMap[$tenDaysAgo]['is_data_available']);
        $this->assertSame('has_defect', $trendMap[$tenDaysAgo]['status']);

        // Three days ago only has confirmed zero defect (unverified 12 pcs excluded)
        $this->assertSame(0, $trendMap[$threeDaysAgo]['defect_pcs']);
        $this->assertTrue($trendMap[$threeDaysAgo]['is_data_available']);
        $this->assertSame('zero_defect', $trendMap[$threeDaysAgo]['status']);

        // A day with no inspections at all (e.g. 20 days ago) has is_data_available = false & defect_pcs = null
        $twentyDaysAgo = now()->subDays(20)->toDateString();
        $this->assertNull($trendMap[$twentyDaysAgo]['defect_pcs']);
        $this->assertFalse($trendMap[$twentyDaysAgo]['is_data_available']);
        $this->assertSame('no_data', $trendMap[$twentyDaysAgo]['status']);
    }

    public function test_zone_3_six_stage_defect_contribution_and_composition(): void
    {
        $defectPorosity = \App\Models\DefectType::firstOrCreate(['name' => 'Porosity'], ['department' => 'COR', 'is_active' => true]);
        $defectRetak = \App\Models\DefectType::firstOrCreate(['name' => 'Retak'], ['department' => 'COR', 'is_active' => true]);

        [$plan1, $line1] = $this->createPlanWithKtrLine(['qty_good' => 50]);
        [$plan2, $line2] = $this->createPlanWithKtrLine(['qty_good' => 50]);

        $twoDaysAgo = now()->subDays(2)->toDateString();

        // Execution 1: Netto with 6 pcs defect (4 porosity, 2 retak)
        $exec1 = $line1->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 50,
            'defect_qty' => 6,
            'good_qty' => 44,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'inspection_date' => $twoDaysAgo,
            'qc_verified_at' => now()->subDays(2),
            'operator_id' => $this->spvUser->id,
            'executed_at' => now()->subDays(2),
            'physical_done_at' => now()->subDays(2),
        ]);
        $exec1->defects()->create(['defect_type_id' => $defectPorosity->id, 'qty' => 4]);
        $exec1->defects()->create(['defect_type_id' => $defectRetak->id, 'qty' => 2]);

        // Execution 2: Bubut OD with 4 pcs defect (4 retak)
        $exec2 = $line2->stageExecutions()->create([
            'stage' => 'bubut_od',
            'checkpoint_code' => 'OD_TURNING',
            'input_qty' => 50,
            'defect_qty' => 4,
            'good_qty' => 46,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'inspection_date' => $twoDaysAgo,
            'qc_verified_at' => now()->subDays(2),
            'operator_id' => $this->spvUser->id,
            'executed_at' => now()->subDays(2),
            'physical_done_at' => now()->subDays(2),
        ]);
        $exec2->defects()->create(['defect_type_id' => $defectRetak->id, 'qty' => 4]);

        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=3&fresh=1');
        $res->assertStatus(200);

        // 1. Stage Contribution: Netto = 6 pcs (15.0 kg, 60%), Bubut OD = 4 pcs (10.0 kg, 40%), Total = 10 pcs (25.0 kg)
        $contrib = collect($res->json('zone_3.stage_defect_contribution'))->keyBy('stage');
        $this->assertCount(6, $contrib);
        $this->assertSame(6, $contrib['netto']['pcs_defect']);
        $this->assertSame(6, $contrib['netto']['defect_pcs']);
        $this->assertEquals(15.0, (float) $contrib['netto']['estimated_defect_kg']);
        $this->assertEquals(15.0, (float) $contrib['netto']['weight_kg']);
        $this->assertEquals(60.0, (float) $contrib['netto']['weight_contribution_pct']);
        $this->assertEquals(60.0, (float) $contrib['netto']['pcs_contribution_pct']);
        $this->assertEquals(60.0, (float) $contrib['netto']['percentage']);
        $this->assertTrue($contrib['netto']['is_weight_available']);
        $this->assertTrue($contrib['netto']['is_weight_complete']);

        $this->assertSame(4, $contrib['bubut_od']['pcs_defect']);
        $this->assertSame(4, $contrib['bubut_od']['defect_pcs']);
        $this->assertEquals(10.0, (float) $contrib['bubut_od']['estimated_defect_kg']);
        $this->assertEquals(40.0, (float) $contrib['bubut_od']['weight_contribution_pct']);
        $this->assertEquals(40.0, (float) $contrib['bubut_od']['percentage']);

        $this->assertSame(0, $contrib['bor']['pcs_defect']);
        $this->assertEquals(0.0, (float) $contrib['bor']['estimated_defect_kg']);
        $this->assertEquals(0.0, (float) $contrib['bor']['weight_contribution_pct']);
        $this->assertEquals(0.0, (float) $contrib['bor']['percentage']);

        // 2. Overall Composition: Porosity = 4 pcs (10.0 kg, 40%), Retak = 6 pcs (15.0 kg, 60%)
        $overall = collect($res->json('zone_3.overall_defect_types'))->keyBy('name');
        $this->assertSame(4, $overall['Porosity']['qty_pcs']);
        $this->assertEquals(10.0, (float) $overall['Porosity']['estimated_defect_kg']);
        $this->assertEquals(40.0, (float) $overall['Porosity']['weight_percentage']);
        $this->assertEquals(40.0, (float) $overall['Porosity']['percentage']);

        $this->assertSame(6, $overall['Retak']['qty_pcs']);
        $this->assertEquals(15.0, (float) $overall['Retak']['estimated_defect_kg']);
        $this->assertEquals(60.0, (float) $overall['Retak']['weight_percentage']);
        $this->assertEquals(60.0, (float) $overall['Retak']['percentage']);

        // 3. Stage Defect Types for Netto & Bor
        $stageTypes = $res->json('zone_3.stage_defect_types');
        $this->assertSame(6, $stageTypes['netto']['total_pcs']);
        $this->assertEquals(15.0, (float) $stageTypes['netto']['total_estimated_defect_kg']);
        $this->assertCount(2, $stageTypes['netto']['types']);

        // Netto defect types: Porosity (4 pcs, 10.0 kg -> 66.7%), Retak (2 pcs, 5.0 kg -> 33.3%)
        $nettoTypes = collect($stageTypes['netto']['types'])->keyBy('name');
        $this->assertEquals(10.0, (float) $nettoTypes['Porosity']['estimated_defect_kg']);
        $this->assertEquals(66.7, (float) $nettoTypes['Porosity']['weight_percentage']);
        $this->assertEquals(5.0, (float) $nettoTypes['Retak']['estimated_defect_kg']);
        $this->assertEquals(33.3, (float) $nettoTypes['Retak']['weight_percentage']);

        // Bor has 0 pcs, types array is empty
        $this->assertSame(0, $stageTypes['bor']['total_pcs']);
        $this->assertEquals(0.0, (float) $stageTypes['bor']['total_estimated_defect_kg']);
        $this->assertCount(0, $stageTypes['bor']['types']);

        // 4. Quality Analytics Summary
        $summary = $res->json('zone_3.quality_analytics_summary');
        $this->assertSame(10, $summary['total_verified_pcs']);
        $this->assertEquals(25.0, (float) $summary['total_estimated_defect_kg']);
        $this->assertSame(10, $summary['valid_weight_pcs']);
        $this->assertSame(0, $summary['unavailable_weight_pcs']);
        $this->assertEquals(100.0, (float) $summary['weight_coverage_pct']);
        $this->assertTrue($summary['is_weight_fully_available']);
    }

    public function test_zone_3_quality_analytics_weight_resolution_hierarchy_and_data_quality_metadata(): void
    {
        $defectRough = \App\Models\DefectType::firstOrCreate(['name' => 'Kasar / Erosi'], ['department' => 'COR', 'is_active' => true]);
        $defectPin = \App\Models\DefectType::firstOrCreate(['name' => 'Pin Hole'], ['department' => 'COR', 'is_active' => true]);

        $twoDaysAgo = now()->subDays(2)->toDateString();

        // 1. Line 1: Snapshot precedence (line.unit_weight_kg = 8.0, plan.weight = 2.0). 3 pcs defect in Netto -> 24.0 kg
        [$plan1, $line1] = $this->createPlanWithKtrLine(['qty_good' => 50, 'unit_weight_kg' => 8.0], ['weight' => 2.0]);
        $exec1 = $line1->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 50,
            'defect_qty' => 3,
            'good_qty' => 47,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'inspection_date' => $twoDaysAgo,
            'qc_verified_at' => now()->subDays(2),
            'operator_id' => $this->spvUser->id,
            'executed_at' => now()->subDays(2),
            'physical_done_at' => now()->subDays(2),
        ]);
        $exec1->defects()->create(['defect_type_id' => $defectRough->id, 'qty' => 3]);

        // 2. Line 2: Fallback to Plan weight (line.unit_weight_kg = 0.0, plan.weight = 4.0). 2 pcs defect in Bubut OD -> 8.0 kg
        [$plan2, $line2] = $this->createPlanWithKtrLine(['qty_good' => 50, 'unit_weight_kg' => 0.0], ['weight' => 4.0]);
        $exec2 = $line2->stageExecutions()->create([
            'stage' => 'bubut_od',
            'checkpoint_code' => 'OD_TURNING',
            'input_qty' => 50,
            'defect_qty' => 2,
            'good_qty' => 48,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'inspection_date' => $twoDaysAgo,
            'qc_verified_at' => now()->subDays(2),
            'operator_id' => $this->spvUser->id,
            'executed_at' => now()->subDays(2),
            'physical_done_at' => now()->subDays(2),
        ]);
        $exec2->defects()->create(['defect_type_id' => $defectPin->id, 'qty' => 2]);

        // 3. Line 3: Both weights invalid (line.unit_weight_kg = 0.0, plan.weight = 0.0). 5 pcs defect in Bubut CNC -> Weight unavailable
        [$plan3, $line3] = $this->createPlanWithKtrLine(['qty_good' => 50, 'unit_weight_kg' => 0.0], ['weight' => 0.0]);
        $exec3 = $line3->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 50,
            'defect_qty' => 5,
            'good_qty' => 45,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'inspection_date' => $twoDaysAgo,
            'qc_verified_at' => now()->subDays(2),
            'operator_id' => $this->spvUser->id,
            'executed_at' => now()->subDays(2),
            'physical_done_at' => now()->subDays(2),
        ]);
        $exec3->defects()->create(['defect_type_id' => $defectPin->id, 'qty' => 5]);

        // 4. Line 4: WAITING_QC status (must NOT be counted in 30-day analytics)
        [$plan4, $line4] = $this->createPlanWithKtrLine(['qty_good' => 50, 'unit_weight_kg' => 10.0], ['weight' => 10.0]);
        $line4->stageExecutions()->create([
            'stage' => 'bor',
            'checkpoint_code' => 'BOR_DRILLING',
            'input_qty' => 50,
            'defect_qty' => 10,
            'good_qty' => 40,
            'status' => SandCastingStageExecution::STATUS_WAITING_QC,
            'inspection_date' => $twoDaysAgo,
            'operator_id' => $this->spvUser->id,
            'executed_at' => now()->subDays(2),
            'physical_done_at' => now()->subDays(2),
        ]);

        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=3&fresh=1');
        $res->assertStatus(200);

        // Assert 1: Summary metadata
        $summary = $res->json('zone_3.quality_analytics_summary');
        $this->assertSame(10, $summary['total_verified_pcs']); // 3 + 2 + 5 pcs (unverified 10 excluded)
        $this->assertSame(5, $summary['valid_weight_pcs']);    // 3 (netto) + 2 (bubut_od)
        $this->assertSame(5, $summary['unavailable_weight_pcs']); // 5 (bubut_cnc)
        $this->assertEquals(32.0, (float) $summary['total_estimated_defect_kg']); // 24.0 kg + 8.0 kg
        $this->assertEquals(50.0, (float) $summary['weight_coverage_pct']); // 5 / 10 = 50.0%
        $this->assertFalse($summary['is_weight_fully_available']);
        $this->assertSame(1, $summary['unweighted_records_count']);

        // Assert 2: Stage Contributions
        $contrib = collect($res->json('zone_3.stage_defect_contribution'))->keyBy('stage');

        // Netto: 3 pcs, 24.0 kg. Weight contribution % = 24.0 / 32.0 * 100 = 75.0%
        $this->assertSame(3, $contrib['netto']['defect_pcs']);
        $this->assertEquals(24.0, (float) $contrib['netto']['estimated_defect_kg']);
        $this->assertEquals(75.0, (float) $contrib['netto']['weight_contribution_pct']);
        $this->assertEquals(30.0, (float) $contrib['netto']['pcs_contribution_pct']); // 3 / 10 = 30%
        $this->assertTrue($contrib['netto']['is_weight_available']);
        $this->assertTrue($contrib['netto']['is_weight_complete']);

        // Bubut OD: 2 pcs, 8.0 kg. Weight contribution % = 8.0 / 32.0 * 100 = 25.0%
        $this->assertSame(2, $contrib['bubut_od']['defect_pcs']);
        $this->assertEquals(8.0, (float) $contrib['bubut_od']['estimated_defect_kg']);
        $this->assertEquals(25.0, (float) $contrib['bubut_od']['weight_contribution_pct']);
        $this->assertEquals(20.0, (float) $contrib['bubut_od']['pcs_contribution_pct']); // 2 / 10 = 20%
        $this->assertTrue($contrib['bubut_od']['is_weight_available']);
        $this->assertTrue($contrib['bubut_od']['is_weight_complete']);

        // Bubut CNC: 5 pcs, 0.0 kg valid. Weight unavailable
        $this->assertSame(5, $contrib['bubut_cnc']['defect_pcs']);
        $this->assertEquals(0.0, (float) $contrib['bubut_cnc']['estimated_defect_kg']);
        $this->assertEquals(0.0, (float) $contrib['bubut_cnc']['weight_contribution_pct']);
        $this->assertEquals(50.0, (float) $contrib['bubut_cnc']['pcs_contribution_pct']); // 5 / 10 = 50%
        $this->assertFalse($contrib['bubut_cnc']['is_weight_available']);
        $this->assertFalse($contrib['bubut_cnc']['is_weight_complete']);
        $this->assertSame(5, $contrib['bubut_cnc']['unavailable_weight_pcs']);

        // Sum of weight contributions for stages with valid weights equals 100% (75% + 25%)
        $totalWeightPct = (float) $contrib['netto']['weight_contribution_pct'] + (float) $contrib['bubut_od']['weight_contribution_pct'];
        $this->assertEquals(100.0, $totalWeightPct);

        // Assert 3: Overall Defect Types
        $overall = collect($res->json('zone_3.overall_defect_types'))->keyBy('name');
        // Rough (Kasar): 3 pcs, 24.0 kg -> 24 / 32 * 100 = 75.0%
        $this->assertSame(3, $overall['Kasar / Erosi']['qty_pcs']);
        $this->assertEquals(24.0, (float) $overall['Kasar / Erosi']['estimated_defect_kg']);
        $this->assertEquals(75.0, (float) $overall['Kasar / Erosi']['weight_percentage']);
        $this->assertTrue($overall['Kasar / Erosi']['is_weight_available']);

        // Pin Hole: 7 pcs total (2 valid = 8.0 kg, 5 unavailable = 0 kg) -> 8 / 32 * 100 = 25.0%
        $this->assertSame(7, $overall['Pin Hole']['qty_pcs']);
        $this->assertEquals(8.0, (float) $overall['Pin Hole']['estimated_defect_kg']);
        $this->assertEquals(25.0, (float) $overall['Pin Hole']['weight_percentage']);
        $this->assertSame(2, $overall['Pin Hole']['valid_weight_pcs']);
        $this->assertSame(5, $overall['Pin Hole']['unavailable_weight_pcs']);

        // Assert 4: 30-Day Trend on two days ago
        $trendMap = collect($res->json('zone_3.defect_trend_30_days'))->keyBy('date');
        $this->assertSame(10, $trendMap[$twoDaysAgo]['defect_pcs']);
        $this->assertEquals(32.0, (float) $trendMap[$twoDaysAgo]['estimated_defect_kg']);
        $this->assertSame(5, $trendMap[$twoDaysAgo]['valid_weight_pcs']);
        $this->assertSame(5, $trendMap[$twoDaysAgo]['unavailable_weight_pcs']);
        $this->assertFalse($trendMap[$twoDaysAgo]['is_weight_complete']);
    }

    public function test_admin_qc_flange_can_view_zone_1_throughput_chart_across_product_scopes(): void
    {
        // 1. Setup multi-scope data
        // Scope A: FLANGE_BESI (100 pcs good, 2.0 kg/pcs => 0.20 Ton)
        [$planBesi, $lineBesi] = $this->createPlanWithKtrLine(
            ['qty_good' => 100, 'unit_weight_kg' => 2.0],
            ['product_scope' => 'FLANGE_BESI']
        );
        $lineBesi->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 0,
            'good_qty' => 100,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        // Scope B: FLANGE_STAINLESS (50 pcs good, 4.0 kg/pcs => 0.20 Ton)
        [$planStainless, $lineStainless] = $this->createPlanWithKtrLine(
            ['qty_good' => 50, 'unit_weight_kg' => 4.0],
            ['product_scope' => 'FLANGE_STAINLESS']
        );
        $lineStainless->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 50,
            'defect_qty' => 0,
            'good_qty' => 50,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        // 2. Admin QC Flange (adminqcflange@peroniks.com) requests Zone 1
        $resQc = $this->actingAs($this->qcUser)->getJson('/sand-casting/dashboard/data?zone=1');
        $resQc->assertStatus(200);

        $stagesQc = collect($resQc->json('zone_1.daily_throughput_stages'))->keyBy('stage');
        $this->assertCount(6, $stagesQc);
        $this->assertFalse($stagesQc->has('cor'));

        // Throughput must combine all scopes for adminqcflange@peroniks.com on Netto (100 Besi + 50 Stainless = 150 pcs, 0.40 Ton)
        $this->assertSame(150, $stagesQc['netto']['good_pcs']);
        $this->assertEquals(0.40, (float) $stagesQc['netto']['good_tonnage']);

        // Verify casting_today still has Cor combined:
        $this->assertSame(150, $resQc->json('zone_1.casting_today.good_pcs'));
        $this->assertEquals(0.40, (float) $resQc->json('zone_1.casting_today.good_tonnage'));

        // 3. PPIC user with scope FLANGE_BESI still only sees FLANGE_BESI
        $resPpic = $this->actingAs($this->ppicUser)->getJson('/sand-casting/dashboard/data?zone=1');
        $resPpic->assertStatus(200);

        $stagesPpic = collect($resPpic->json('zone_1.daily_throughput_stages'))->keyBy('stage');
        $this->assertCount(6, $stagesPpic);
        $this->assertFalse($stagesPpic->has('cor'));
        $this->assertSame(100, $stagesPpic['netto']['good_pcs']);
        $this->assertEquals(0.20, (float) $stagesPpic['netto']['good_tonnage']);

        // 4. Other QC user with scope FLANGE_BESI does NOT bypass scope
        $otherQcUser = User::factory()->create([
            'name' => 'Other QC User',
            'email' => 'otherqc@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $otherQcUser->assignRole('qc');

        $resOtherQc = $this->actingAs($otherQcUser)->getJson('/sand-casting/dashboard/data?zone=1&fresh=1');
        $resOtherQc->assertStatus(200);

        $stagesOtherQc = collect($resOtherQc->json('zone_1.daily_throughput_stages'))->keyBy('stage');
        $this->assertCount(6, $stagesOtherQc);
        $this->assertFalse($stagesOtherQc->has('cor'));
        $this->assertSame(100, $stagesOtherQc['netto']['good_pcs']);
        $this->assertEquals(0.20, (float) $stagesOtherQc['netto']['good_tonnage']);

        // 5. Verification: adminqcflange on Production Report page maintains its scope (not bypassed)
        $reportRes = $this->actingAs($this->qcUser)->get('/sand-casting/report/production?stage=cor');
        $reportRes->assertStatus(200);
        $reportRes->assertViewHas('items', function ($items) {
            return count($items) === 1;
        });

        // 6. Cache isolation check:
        // Cache keys are separated: PPIC uses sc_dashboard_data_FLANGE_BESI_zone_1, admin/adminqcflange uses sc_dashboard_data_all_zone_1
        $this->assertTrue(Cache::has('sc_dashboard_data_FLANGE_BESI_zone_1'));
        $this->assertTrue(Cache::has('sc_dashboard_data_all_zone_1'));
    }

    public function test_phase_5g_zone_1_throughput_chart_specifications(): void
    {
        [$plan, $line, $result] = $this->createPlanWithKtrLine([
            'qty_good' => 60,
            'qty_reject' => 5,
            'unit_weight_kg' => 2.0,
            'current_stage' => 'netto',
        ]);

        SandCastingStageExecution::create([
            'sand_casting_casting_result_line_id' => $line->id,
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 60,
            'defect_qty' => 2,
            'good_qty' => 58,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->spvUser->id,
        ]);

        // 1. Endpoint returns exactly 6 categories in fixed order
        $res = $this->actingAs($this->adminUser)->getJson('/sand-casting/dashboard/data?zone=1&fresh=1');
        $res->assertStatus(200);

        $throughputStages = $res->json('zone_1.daily_throughput_stages');
        $this->assertCount(6, $throughputStages);

        $expectedStages = ['netto', 'bubut_od', 'bubut_cnc', 'bor', 'qc', 'gudang_jadi'];
        $expectedLabels = ['Netto', 'Bubut OD', 'Bubut CNC', 'Bor', 'QC', 'Gudang Jadi'];

        $this->assertSame($expectedStages, array_column($throughputStages, 'stage'));
        $this->assertSame($expectedLabels, array_column($throughputStages, 'label'));

        // 2. Cor is absent from throughput stages
        $stagesKeyed = collect($throughputStages)->keyBy('stage');
        $this->assertFalse($stagesKeyed->has('cor'));

        // 3. TON and PCS metrics for Netto match aggregation correctly
        $netto = $stagesKeyed['netto'];
        $this->assertSame(60, $netto['input_pcs']);
        $this->assertSame(58, $netto['good_pcs']);
        $this->assertEquals(0.12, (float) $netto['good_tonnage']);
        $this->assertSame(2, $netto['defect_pcs']);

        // 4. Cor data is preserved in casting_today
        $castingToday = $res->json('zone_1.casting_today');
        $this->assertSame(60, $castingToday['good_pcs']);
        $this->assertEquals(0.12, (float) $castingToday['good_tonnage']);
        $this->assertSame(5, $castingToday['reject_pcs']);

        // 5. Cor data is preserved in production report dataset
        $reportService = app(\App\Services\SandCasting\SandCastingProductionReportService::class);
        $reportData = $reportService->getProductionDataset(['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()], $this->adminUser);
        $reportStages = collect($reportData['stage_summaries'])->keyBy('stage');
        $this->assertTrue($reportStages->has('cor'));
        $this->assertSame(60, $reportStages['cor']['good_pcs']);

        // 6. Blade template contains explicit #E5E7EB tick color and does not re-add Cor
        $viewRes = $this->actingAs($this->adminUser)->get('/sand-casting/dashboard');
        $viewRes->assertStatus(200);
        $viewContent = $viewRes->getContent();

        // Check explicit X-axis tick color in initCharts and in updateChartsInPlace
        $this->assertStringContainsString("ticks: { color: '#E5E7EB', font: { weight: 'bold', size: 12 } }", $viewContent);
        $this->assertStringContainsString("dashboardCharts.throughput.options.scales.x.ticks.color = '#E5E7EB';", $viewContent);

        // Check that Cor filter is present in JS
        $this->assertStringContainsString("filter(s => s.stage !== 'cor')", $viewContent);
    }
}
