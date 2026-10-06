<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductionStatusControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $ppicUser;

    protected User $qcUser;

    protected User $spvUser;

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
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        static $seq = 1;
        $num = $seq++;

        return ProductionPlan::create(array_merge([
            'code' => 'S'.sprintf('%03d', $num),
            'title' => 'Rencana Sand Casting '.$num,
            'item_code' => 'ITEM-'.$num,
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-SC-'.$num,
            'line_number' => 1,
            'po_quantity' => 1000,
            'qty_planned' => 1100,
            'qty_remaining' => 1100,
            'customer' => 'PT SINAR METAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ], $attributes));
    }

    protected function createKtr(
        ProductionPlan $plan,
        int $qtyGood = 100,
        int $qtyReject = 0,
        string $stage = 'netto',
        ?string $heatNumber = null
    ): SandCastingCastingResultLine {
        static $ktrSeq = 1;
        $num = $ktrSeq++;

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-'.now()->format('ymd').'-'.sprintf('%05d', $num),
            'scheduled_date' => now()->toDateString(),
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => $qtyGood + $qtyReject,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '2"',
            'aisi' => 'FC250',
        ]);

        $heat = $heatNumber ?? 'HN-'.now()->format('ymd').'-'.sprintf('%04d', $num);
        $result = SandCastingCastingResult::firstOrCreate(
            ['heat_number' => $heat],
            [
                'cast_date' => now()->toDateString(),
                'furnace' => 'F1',
                'shift' => '1',
                'operator_name' => 'Budi Cor',
                'recorded_by' => $this->adminUser->id,
            ]
        );

        return $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-'.now()->format('Ymd').'-'.sprintf('%06d', $num),
            'qty_good' => $qtyGood,
            'qty_reject' => $qtyReject,
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => ($qtyGood + $qtyReject) * 2.50,
            'current_stage' => $stage,
        ]);
    }

    /**
     * TEST 1: Route is protected by auth middleware (guests redirected to login)
     */
    public function test_01_unauthenticated_guest_is_redirected(): void
    {
        $response = $this->get('/sand-casting/production-status');
        $response->assertRedirect('/login');
    }

    /**
     * TEST 2: Route name and URL match sand-casting.production-status.index
     */
    public function test_02_route_name_resolves_correctly(): void
    {
        $url = route('sand-casting.production-status.index');
        $this->assertSame(url('/sand-casting/production-status'), $url);
    }

    /**
     * TEST 3: Authenticated admin can access and see production status page
     */
    public function test_03_admin_can_access_production_status_page(): void
    {
        $plan = $this->createPlan(['code' => 'S797', 'po_quantity' => 1000]);
        $this->createKtr($plan, 500, 0, 'netto');

        $response = $this->actingAs($this->adminUser)->get('/sand-casting/production-status');

        $response->assertStatus(200);
        $response->assertViewIs('sand-casting.production-status.index');
        $response->assertViewHas('rows');
        $response->assertViewHas('summary');
        $response->assertSee('S797');
        $response->assertSee('PT SINAR METAL');
        $response->assertSee('COR');
    }

    /**
     * TEST 4: JSON response support for programmatic / AJAX access
     */
    public function test_04_json_response_returns_read_model(): void
    {
        $plan = $this->createPlan(['code' => 'S888', 'po_quantity' => 1000, 'qty_planned' => 1100]);
        $this->createKtr($plan, 1101, 0, 'netto');

        $response = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'summary' => ['active_count', 'completed_count', 'total_count'],
            'rows' => [
                '*' => [
                    'code',
                    'customer',
                    'po_target',
                    'planned_qty',
                    'cor_indicator',
                    'cast_good_total',
                    'net_available_good',
                    'status',
                ],
            ],
        ]);

        $data = $response->json();
        $this->assertSame(1, $data['summary']['total_count']);
        $this->assertSame('S888', $data['rows'][0]['code']);
        $this->assertSame('', $data['rows'][0]['cor_indicator']);
    }

    /**
     * TEST 5: Production code filter filters correctly
     */
    public function test_05_production_code_filter_isolates_records(): void
    {
        $plan1 = $this->createPlan(['code' => 'S101']);
        $plan2 = $this->createPlan(['code' => 'S202']);

        $this->createKtr($plan1, 100, 0, 'netto');
        $this->createKtr($plan2, 100, 0, 'netto');

        $response = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?production_code=S101');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertCount(1, $data['rows']);
        $this->assertSame('S101', $data['rows'][0]['code']);
    }

    /**
     * TEST 6: Customer filter isolates records
     */
    public function test_06_customer_filter_isolates_records(): void
    {
        $plan1 = $this->createPlan(['code' => 'S301', 'customer' => 'PT KARYA UTAMA']);
        $plan2 = $this->createPlan(['code' => 'S302', 'customer' => 'PT BINA TEKNIK']);

        $this->createKtr($plan1, 100, 0, 'netto');
        $this->createKtr($plan2, 100, 0, 'netto');

        $response = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?customer=PT KARYA UTAMA');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertCount(1, $data['rows']);
        $this->assertSame('PT KARYA UTAMA', $data['rows'][0]['customer']);
    }

    /**
     * TEST 7: Status filter (active vs completed) works correctly
     */
    public function test_07_status_filter_active_and_completed(): void
    {
        // Active plan
        $planActive = $this->createPlan(['code' => 'S401', 'po_quantity' => 1000]);
        $this->createKtr($planActive, 500, 0, 'netto');

        // Completed plan (GD = 1000 >= PO 1000)
        $planCompleted = $this->createPlan(['code' => 'S402', 'po_quantity' => 1000]);
        $ktrCompleted = $this->createKtr($planCompleted, 1000, 0, 'completed');
        $ktrCompleted->stageExecutions()->create([
            'stage' => 'gudang_jadi',
            'checkpoint_code' => 'GUDANG_RECEIVE',
            'input_qty' => 1000,
            'defect_qty' => 0,
            'good_qty' => 1000,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->adminUser->id,
            'executed_at' => now(),
            'physical_done_at' => now(),
        ]);

        // Filter active using ?filter=active
        $responseActive = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?filter=active');
        $responseActive->assertStatus(200);
        $dataActive = $responseActive->json();
        $this->assertCount(1, $dataActive['rows']);
        $this->assertSame('S401', $dataActive['rows'][0]['code']);

        // Filter completed using ?filter=completed
        $responseCompleted = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?filter=completed');
        $responseCompleted->assertStatus(200);
        $dataCompleted = $responseCompleted->json();
        $this->assertCount(1, $dataCompleted['rows']);
        $this->assertSame('S402', $dataCompleted['rows'][0]['code']);

        // Filter all using ?filter=all
        $responseAll = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?filter=all');
        $responseAll->assertStatus(200);
        $dataAll = $responseAll->json();
        $this->assertCount(2, $dataAll['rows']);

        // Backward compatibility: ?status=completed
        $responseLegacy = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?status=completed');
        $responseLegacy->assertStatus(200);
        $this->assertCount(1, $responseLegacy->json('rows'));
    }

    /**
     * TEST 8: PPIC role respects product_scope isolation
     */
    public function test_08_ppic_role_respects_product_scope(): void
    {
        // FLANGE_BESI plan (matching ppic user scope)
        $planBesi = $this->createPlan([
            'code' => 'SBESI',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
        ]);
        $this->createKtr($planBesi, 100, 0, 'netto');

        // FLANGE_STAINLESS plan (different scope)
        $planSS = $this->createPlan([
            'code' => 'SSS',
            'product_scope' => 'FLANGE_STAINLESS',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
        ]);
        $this->createKtr($planSS, 100, 0, 'netto');

        $response = $this->actingAs($this->ppicUser)
            ->getJson('/sand-casting/production-status');

        $response->assertStatus(200);
        $data = $response->json();

        $codes = collect($data['rows'])->pluck('code')->all();
        $this->assertContains('SBESI', $codes);
        $this->assertNotContains('SSS', $codes);
    }

    /**
     * TEST 9: Endpoint is completely read-only (database untouched)
     */
    public function test_09_endpoint_is_completely_read_only(): void
    {
        $plan = $this->createPlan(['code' => 'S900', 'po_quantity' => 1000]);
        $this->createKtr($plan, 100, 0, 'netto');

        $planCountBefore = ProductionPlan::count();
        $ktrCountBefore = SandCastingCastingResultLine::count();
        $execCountBefore = SandCastingStageExecution::count();

        $response = $this->actingAs($this->adminUser)->get('/sand-casting/production-status');
        $response->assertStatus(200);

        $this->assertSame($planCountBefore, ProductionPlan::count());
        $this->assertSame($ktrCountBefore, SandCastingCastingResultLine::count());
        $this->assertSame($execCountBefore, SandCastingStageExecution::count());
    }

    /**
     * TEST 10: Search filter matches across code, po_number, customer, and item_name
     */
    public function test_10_search_filter_matches_fields(): void
    {
        $plan = $this->createPlan([
            'code' => 'S999',
            'po_number' => 'PO-SPECIAL-999',
            'customer' => 'PT TARGET KHUSUS',
            'item_name' => 'FLANGE KHUSUS 4 INCH',
        ]);
        $this->createKtr($plan, 100, 0, 'netto');

        $response = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?search=TARGET KHUSUS');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data['rows']);
        $this->assertSame('S999', $data['rows'][0]['code']);
    }

    /**
     * TEST 11: Detail endpoint returns 401/redirect for unauthenticated guest
     */
    public function test_11_detail_endpoint_requires_auth(): void
    {
        $response = $this->get('/sand-casting/production-status/details?code=S101');
        $response->assertRedirect('/login');
    }

    /**
     * TEST 12: Detail endpoint returns 422 if code / production_plan_id is missing
     */
    public function test_12_detail_endpoint_validates_required_parameters(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status/details');

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'items']);
    }

    /**
     * TEST 13: Detail endpoint returns 404 if plan is not found
     */
    public function test_13_detail_endpoint_returns_404_when_not_found(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status/details?code=NON_EXISTENT');

        $response->assertStatus(404);
    }

    /**
     * TEST 14: Detail endpoint returns structured physical KTR data
     */
    public function test_14_detail_endpoint_returns_physical_ktr_data(): void
    {
        $plan = $this->createPlan(['code' => 'S801', 'customer' => 'PT PERONIS']);
        $this->createKtr($plan, 150, 2, 'netto', 'HN-TEST-801');

        $response = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status/details?production_plan_id='.$plan->id);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'production_plan_id',
            'production_code',
            'customer',
            'item_code',
            'item_name',
            'po_target',
            'planned_qty',
            'status',
            'cor_indicator',
            'total_physical_qty',
            'ktr_count',
            'items' => [
                '*' => [
                    'id',
                    'traveler_number',
                    'heat_number',
                    'cast_date',
                    'furnace',
                    'shift',
                    'operator_name',
                    'current_stage',
                    'current_stage_label',
                    'quantity',
                    'qty_cor_good',
                    'defect_qty',
                    'last_activity_at',
                ],
            ],
        ]);

        $data = $response->json();
        $this->assertSame('S801', $data['production_code']);
        $this->assertSame('PT PERONIS', $data['customer']);
        $this->assertSame(1, $data['ktr_count']);
        $this->assertSame(150, $data['total_physical_qty']);
        $this->assertSame('NETTO', $data['items'][0]['current_stage_label']);
        $this->assertSame('HN-TEST-801', $data['items'][0]['heat_number']);
    }

    /**
     * TEST 15: Detail endpoint respects user product_scope authorization
     */
    public function test_15_detail_endpoint_respects_product_scope_authorization(): void
    {
        // FLANGE_STAINLESS plan (outside ppicUser scope FLANGE_BESI)
        $planSS = $this->createPlan([
            'code' => 'SS-SCOPE-01',
            'product_scope' => 'FLANGE_STAINLESS',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
        ]);
        $this->createKtr($planSS, 100, 0, 'netto');

        $response = $this->actingAs($this->ppicUser)
            ->getJson('/sand-casting/production-status/details?code=SS-SCOPE-01');

        $response->assertStatus(404);
    }
}
