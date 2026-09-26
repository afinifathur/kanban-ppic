<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use App\Services\SandCasting\SandCastingStageExecutionService;
use App\Services\SandCasting\TravelerNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductionFloorKanbanUiTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $spvNetto;

    protected User $spvBubutOd;

    protected SandCastingStageExecutionService $executionService;

    protected \App\Models\DefectType $defectPinhole;

    protected function setUp(): void
    {
        parent::setUp();

        // Roles & Permissions setup
        $planPerm = Permission::firstOrCreate(['name' => 'access_planning']);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $ppicRole = Role::firstOrCreate(['name' => 'ppic']);
        $spvRole = Role::firstOrCreate(['name' => 'spv']);

        $adminRole->givePermissionTo($planPerm);
        $ppicRole->givePermissionTo($planPerm);

        $this->adminUser = User::factory()->create([
            'name' => 'Admin Sand Casting',
            'email' => 'admin_sc@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->adminUser->assignRole('admin');

        $this->spvNetto = User::factory()->create([
            'name' => 'SPV Netto',
            'email' => 'spv_netto@peroniks.com',
            'assigned_stage' => 'netto',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvNetto->assignRole('spv');

        $this->spvBubutOd = User::factory()->create([
            'name' => 'SPV Bubut OD',
            'email' => 'spv_bubut_od@peroniks.com',
            'assigned_stage' => 'bubut_od',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvBubutOd->assignRole('spv');

        $this->defectPinhole = \App\Models\DefectType::create([
            'department' => 'netto',
            'name' => 'Pinhole Porosity',
            'is_active' => true,
        ]);

        $this->executionService = new SandCastingStageExecutionService;
    }

    protected function createKtrLine(array $attributes = []): SandCastingCastingResultLine
    {
        $plan = ProductionPlan::create([
            'code' => $attributes['production_code'] ?? '268ET'.rand(100, 999),
            'title' => 'Rencana SC '.rand(100, 999),
            'item_code' => '4.'.rand(100, 999),
            'item_name' => $attributes['item_name'] ?? 'SS304 EN1092-1 PN16 DN350',
            'po_number' => 'PO-'.rand(100, 999),
            'line_number' => $attributes['line_number'] ?? 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'customer' => $attributes['customer'] ?? 'PT SINAR METAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260920-'.rand(1000, 9999),
            'scheduled_date' => '2026-09-20',
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => $attributes['size'] ?? '2"',
            'aisi' => 'FC250',
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => $attributes['heat_number'] ?? 'LA'.date('ymd').rand(10, 99),
            'cast_date' => $attributes['cast_date'] ?? '2026-09-20',
            'shift' => 1,
            'furnace' => 'F-01',
            'recorded_by' => $this->adminUser->id,
        ]);

        $lineAttributes = array_merge([
            'sand_casting_casting_result_id' => $result->id,
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'product_scope' => 'FLANGE_BESI',
            'item_code' => '4.101',
            'item_name' => $plan->item_name,
            'code' => $plan->code,
            'heat_number' => $result->heat_number,
            'qty_good' => $attributes['qty_good'] ?? 24,
            'qty_reject' => 0,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => ($attributes['qty_good'] ?? 24) * 2.50,
            'current_stage' => $attributes['current_stage'] ?? 'netto',
            'is_urgent' => $attributes['is_urgent'] ?? false,
        ], $attributes);

        if (! isset($lineAttributes['traveler_number'])) {
            $lineAttributes['traveler_number'] = TravelerNumberGenerator::generateNext('2026-09-20');
        }

        return SandCastingCastingResultLine::create($lineAttributes);
    }

    /**
     * Requirement: Unauthenticated user redirected to login.
     */
    public function test_unauthenticated_user_redirected_to_login(): void
    {
        $response = $this->get('/sand-casting/kanban');
        $response->assertRedirect('/login');
    }

    /**
     * Requirement: SPV assigned stage opens their stage kanban by default with simplified SPV view.
     */
    public function test_spv_opens_assigned_stage_kanban(): void
    {
        $this->createKtrLine([
            'current_stage' => 'netto',
            'qty_good' => 38,
            'unit_weight_kg' => 6.447,
            'total_weight_kg' => 245.0,
        ]);

        $response = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban');

        $response->assertOk();
        $response->assertSee('KANBAN PRODUKSI &mdash; NETTO', false);
        $response->assertSee('BELUM SELESAI');
        $response->assertSee('38');
        $response->assertSee('PCS');
        $response->assertSee('KG');
    }

    /**
     * Requirement: SPV cannot open unauthorized stages (403).
     */
    public function test_spv_cannot_open_other_stages(): void
    {
        // Netto SPV attempts to access Bubut OD kanban
        $response = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/bubut-od');
        $response->assertForbidden();

        // Netto SPV attempts to access QC kanban
        $responseQc = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/qc');
        $responseQc->assertForbidden();
    }

    /**
     * Requirement: SPV without assigned_stage gets 403.
     */
    public function test_spv_without_assigned_stage_is_forbidden(): void
    {
        $spvNull = User::factory()->create([
            'name' => 'SPV No Stage',
            'email' => 'spv_null@peroniks.com',
            'assigned_stage' => null,
            'product_scope' => 'FLANGE_BESI',
        ]);
        $spvNull->assignRole('spv');

        $response = $this->actingAs($spvNull)->get('/sand-casting/kanban');
        $response->assertForbidden();
    }

    /**
     * Admin can view any valid Sand Casting stage and sees stage switcher tabs.
     */
    public function test_admin_can_view_any_stage_and_sees_switcher_tabs(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/sand-casting/kanban/bubut-od');

        $response->assertOk();
        $response->assertSee('KANBAN PRODUKSI &mdash; BUBUT OD', false);
        $response->assertSee('NETTO');
        $response->assertSee('BUBUT OD');
        $response->assertDontSee('/sand-casting/kanban/marking');
        $response->assertSee('BUBUT CNC');
        $response->assertSee('BOR');
        $response->assertSee('QC');
        $response->assertSee('GUDANG JADI');
    }

    /**
     * Invalid stage slug returns 404.
     */
    public function test_invalid_stage_returns_404(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/sand-casting/kanban/invalid-xyz');
        $response->assertNotFound();
    }

    /**
     * Requirement: SPV Card renders only Item Name, Heat, PCS, KG, Aging, and NO per-card button.
     * Global Scanner button exists in header.
     */
    public function test_spv_compact_card_renders_essential_data_without_card_buttons_or_ktr(): void
    {
        $this->createKtrLine([
            'traveler_number' => 'KTR-20260923-0001',
            'heat_number' => 'A221092604',
            'production_code' => '268ET623',
            'item_name' => 'SS304 RAISED ANSI 300LBS 3"',
            'customer' => 'E02',
            'line_number' => 2,
            'qty_good' => 38,
            'unit_weight_kg' => 6.447368,
            'total_weight_kg' => 245.0,
            'current_stage' => 'netto',
        ]);

        $response = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/netto');

        $response->assertOk();

        // Must see:
        $response->assertSee('SS304 RAISED ANSI 300LBS 3"');
        $response->assertSee('Heat A221092604');
        $response->assertSee('38');
        $response->assertSee('245');
        $response->assertSee('Aging');
        $response->assertSee('LINE 2');
        $response->assertSee('BELUM SELESAI');

        // Global scanner button in top header:
        $response->assertSee(route('sand-casting.scan.stage', 'netto'));

        // Must NOT see in SPV mode:
        $response->assertDontSee('KTR-20260923-0001'); // No KTR on card
        $response->assertDontSee('268ET623'); // No prod code on card
        $response->assertDontSee('SCAN / PROSES'); // No per-card scan/process button
        $response->assertDontSee('ANTREAN SIAP PROSES (READY)'); // No verbose section heading
    }

    /**
     * Requirement Upgrade #1: Aging Semantic Color Indicator (<5d Green, 5-7d Yellow, >7d Red).
     */
    public function test_aging_semantic_color_indicators(): void
    {
        // Line 1: 2 days old (< 5 days) -> Green
        $l1 = $this->createKtrLine([
            'item_name' => 'ITEM-GREEN-AGING',
            'line_number' => 1,
            'current_stage' => 'netto',
        ]);
        $l1->created_at = now()->subDays(2)->subHours(8);
        $l1->saveQuietly();

        // Line 2: 6 days old (5 to 7 days) -> Yellow
        $l2 = $this->createKtrLine([
            'item_name' => 'ITEM-YELLOW-AGING',
            'line_number' => 2,
            'current_stage' => 'netto',
        ]);
        $l2->created_at = now()->subDays(6)->subHours(2);
        $l2->saveQuietly();

        // Line 3: 9 days old (> 7 days) -> Red
        $l3 = $this->createKtrLine([
            'item_name' => 'ITEM-RED-AGING',
            'line_number' => 3,
            'current_stage' => 'netto',
        ]);
        $l3->created_at = now()->subDays(9)->subHours(4);
        $l3->saveQuietly();

        $response = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/netto');

        $response->assertOk();
        $response->assertSee('data-aging-color="green"', false);
        $response->assertSee('data-aging-color="yellow"', false);
        $response->assertSee('data-aging-color="red"', false);

        // Assert card background stays neutral white
        $response->assertSee('bg-white');
    }

    /**
     * Requirement Upgrade #2: Desktop Independent Scroll Containers Per Line.
     */
    public function test_independent_line_scroll_containers_rendered_on_desktop(): void
    {
        $this->createKtrLine([
            'item_name' => 'LINE-1-ITEM',
            'line_number' => 1,
            'current_stage' => 'netto',
        ]);
        $this->createKtrLine([
            'item_name' => 'LINE-2-ITEM',
            'line_number' => 2,
            'current_stage' => 'netto',
        ]);

        $response = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/netto');

        $response->assertOk();
        $response->assertSee('data-line-scroll="1"', false);
        $response->assertSee('data-line-scroll="2"', false);
        $response->assertSee('data-line-scroll="3"', false);
        $response->assertSee('data-line-scroll="4"', false);
        $response->assertSee('overscroll-contain');
    }

    /**
     * Requirement: Urgent indicator is rendered when is_urgent is true.
     */
    public function test_urgent_indicator_rendered_when_urgent(): void
    {
        $this->createKtrLine([
            'item_name' => 'URGENT FLANGE ITEM 100',
            'is_urgent' => true,
            'current_stage' => 'netto',
        ]);

        $response = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/netto');

        $response->assertOk();
        $response->assertSee('URGENT FLANGE ITEM 100');
        $response->assertSee('URGENT');
    }

    /**
     * FIFO backend ordering is preserved in HTML view.
     */
    public function test_fifo_ordering_preserved_in_html_view(): void
    {
        // Line 1: Normal, cast yesterday
        $this->createKtrLine([
            'item_name' => 'ITEM-FIFO-NORMAL-OLD',
            'cast_date' => '2026-09-18',
            'is_urgent' => false,
            'line_number' => 1,
            'current_stage' => 'netto',
        ]);

        // Line 2: Urgent, cast today (must come BEFORE normal old)
        $this->createKtrLine([
            'item_name' => 'ITEM-FIFO-URGENT-NEW',
            'cast_date' => '2026-09-20',
            'is_urgent' => true,
            'line_number' => 1,
            'current_stage' => 'netto',
        ]);

        // Line 3: Normal, cast today (must come last)
        $this->createKtrLine([
            'item_name' => 'ITEM-FIFO-NORMAL-NEW',
            'cast_date' => '2026-09-20',
            'is_urgent' => false,
            'line_number' => 1,
            'current_stage' => 'netto',
        ]);

        $response = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/netto');
        $response->assertOk();

        $content = $response->getContent();
        $posUrgent = strpos($content, 'ITEM-FIFO-URGENT-NEW');
        $posNormalOld = strpos($content, 'ITEM-FIFO-NORMAL-OLD');
        $posNormalNew = strpos($content, 'ITEM-FIFO-NORMAL-NEW');

        $this->assertNotFalse($posUrgent);
        $this->assertNotFalse($posNormalOld);
        $this->assertNotFalse($posNormalNew);

        $this->assertTrue($posUrgent < $posNormalOld, 'Urgent must be rendered before normal old');
        $this->assertTrue($posNormalOld < $posNormalNew, 'Old normal must be rendered before new normal');
    }

    /**
     * Filter by search keyword and line number works.
     */
    public function test_filter_and_search_works(): void
    {
        $this->createKtrLine([
            'item_name' => 'ALPHA VALVE CASTING',
            'heat_number' => 'HEATALPHA1',
            'line_number' => 1,
            'current_stage' => 'netto',
        ]);

        $this->createKtrLine([
            'item_name' => 'BETA FLANGE CASTING',
            'heat_number' => 'HEATBETA2',
            'line_number' => 2,
            'current_stage' => 'netto',
        ]);

        // Search for 'ALPHA'
        $respSearch = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/netto?search=ALPHA');
        $respSearch->assertOk();
        $respSearch->assertSee('ALPHA VALVE CASTING');
        $respSearch->assertDontSee('BETA FLANGE CASTING');

        // Filter by Line 2
        $respLine = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/netto?line_number=2');
        $respLine->assertOk();
        $respLine->assertDontSee('ALPHA VALVE CASTING');
        $respLine->assertSee('BETA FLANGE CASTING');
    }

    /**
     * Read-only safety: GET /sand-casting/kanban creates NO mutations.
     */
    public function test_read_only_safety_no_mutations_occur(): void
    {
        $this->createKtrLine([
            'traveler_number' => 'KTR-SAFETY-01',
            'current_stage' => 'netto',
        ]);

        $countExecBefore = SandCastingStageExecution::count();
        $countLinesBefore = SandCastingCastingResultLine::count();

        $response = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban');
        $response->assertOk();

        $this->assertEquals($countExecBefore, SandCastingStageExecution::count());
        $this->assertEquals($countLinesBefore, SandCastingCastingResultLine::count());
    }

    /**
     * JSON response is supported when requested with Accept: application/json.
     */
    public function test_json_response_supported_for_api_clients(): void
    {
        $this->createKtrLine([
            'traveler_number' => 'KTR-JSON-01',
            'current_stage' => 'netto',
        ]);

        $response = $this->actingAs($this->spvNetto)->getJson('/sand-casting/kanban/netto');

        $response->assertOk();
        $response->assertJsonStructure([
            'stage',
            'summary' => [
                'ready_count',
                'incoming_count',
                'halted_count',
                'total_count',
                'ready_qty',
                'incoming_qty',
                'halted_qty',
            ],
            'ready',
            'incoming',
            'halted',
        ]);
        $response->assertJsonPath('stage', 'netto');
        $response->assertJsonPath('summary.ready_count', 1);
    }

    /**
     * Incoming card (e.g. from Netto WAITING_DEFECT) is NOT rendered in Floor Kanban HTML view (UI policy),
     * but once upstream completes physical work, it transitions to READY and appears.
     */
    public function test_incoming_card_renders_in_html_view_when_upstream_in_progress(): void
    {
        $ktr = $this->createKtrLine([
            'traveler_number' => 'KTR-20260912-0005',
            'current_stage' => 'netto',
            'qty_good' => 12,
            'item_name' => 'CS Q235 SORF FLANGE 2"',
            'heat_number' => 'A212092604',
        ]);

        // KTR is in Netto (upstream) -> Bubut OD does NOT render it in HTML view (Floor Kanban only shows physically ready items)
        $response = $this->actingAs($this->spvBubutOd)->get('/sand-casting/kanban/bubut-od');

        $response->assertOk();
        $response->assertDontSee('CS Q235 SORF FLANGE 2"');
        $response->assertDontSee('INCOMING');

        // When SPV Netto marks physical work done -> Bubut OD renders it as READY
        $this->executionService->markPhysicalDone(
            travelerNumber: $ktr->traveler_number,
            targetStage: 'netto',
            operatorId: $this->spvNetto->id
        );

        $responseAfter = $this->actingAs($this->spvBubutOd)->get('/sand-casting/kanban/bubut-od');
        $responseAfter->assertOk();
        $responseAfter->assertSee('CS Q235 SORF FLANGE 2"');
        $responseAfter->assertSee('BELUM SELESAI (READY)');
        $responseAfter->assertDontSee('INCOMING');
    }

    /**
     * Phase 3B: Kanban view renders full-width layout and auto-refresh elements.
     */
    public function test_kanban_view_renders_full_width_layout_and_auto_refresh_badge(): void
    {
        $this->createKtrLine([
            'traveler_number' => 'KTR-20260924-0001',
            'current_stage' => 'netto',
            'qty_good' => 25,
            'line_number' => 1,
        ]);

        $response = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/netto');

        $response->assertOk();
        // Full width container
        $response->assertSee('class="space-y-3 w-full pb-6"', false);
        $response->assertDontSee('max-w-7xl mx-auto');

        // Auto-refresh elements
        $response->assertSee('id="autoRefreshBadge"', false);
        $response->assertSee('id="autoRefreshTimer"', false);
        $response->assertSee('02:00');
        $response->assertSee('AUTO:');
    }
}
