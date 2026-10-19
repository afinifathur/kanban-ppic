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

    /**
     * TEST 16: Search filter matches Heat Number via HTTP request
     */
    public function test_16_search_filter_matches_heat_number(): void
    {
        $plan = $this->createPlan(['code' => 'S160-HN']);
        $this->createKtr($plan, 100, 0, 'netto', 'HN-HEAT-SPECIAL-160');

        $otherPlan = $this->createPlan(['code' => 'S161-OTHER']);
        $this->createKtr($otherPlan, 100, 0, 'netto', 'HN-OTHER-999');

        $response = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?search=HEAT-SPECIAL');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data['rows']);
        $this->assertSame('S160-HN', $data['rows'][0]['code']);
    }

    /**
     * TEST 17: Search filter matches KTR traveler number via HTTP request
     */
    public function test_17_search_filter_matches_ktr_traveler_number(): void
    {
        $plan = $this->createPlan(['code' => 'S170-KTR']);
        $ktr = $this->createKtr($plan, 100, 0, 'netto');

        $response = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?search='.$ktr->traveler_number);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data['rows']);
        $this->assertSame('S170-KTR', $data['rows'][0]['code']);
    }

    /**
     * TEST 18: Heat number search respects status filter (active vs completed)
     */
    public function test_18_search_heat_number_respects_status_filter(): void
    {
        // Active plan with heat HN-FILTER-STATUS
        $planActive = $this->createPlan(['code' => 'S180-ACTIVE', 'po_quantity' => 1000]);
        $this->createKtr($planActive, 500, 0, 'netto', 'HN-FILTER-STATUS-ACTIVE');

        // Completed plan with heat HN-FILTER-STATUS
        $planCompleted = $this->createPlan(['code' => 'S180-COMPLETED', 'po_quantity' => 100]);
        $ktrCompleted = $this->createKtr($planCompleted, 100, 0, 'completed', 'HN-FILTER-STATUS-COMP');
        $ktrCompleted->stageExecutions()->create([
            'stage' => 'gudang_jadi',
            'checkpoint_code' => 'GUDANG_RECEIVE',
            'input_qty' => 100,
            'defect_qty' => 0,
            'good_qty' => 100,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->adminUser->id,
            'executed_at' => now(),
            'physical_done_at' => now(),
        ]);

        // Search with filter=completed
        $responseCompleted = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?search=HN-FILTER-STATUS&filter=completed');
        $responseCompleted->assertStatus(200);
        $dataCompleted = $responseCompleted->json();
        $this->assertCount(1, $dataCompleted['rows']);
        $this->assertSame('S180-COMPLETED', $dataCompleted['rows'][0]['code']);

        // Search with filter=active
        $responseActive = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?search=HN-FILTER-STATUS&filter=active');
        $responseActive->assertStatus(200);
        $dataActive = $responseActive->json();
        $this->assertCount(1, $dataActive['rows']);
        $this->assertSame('S180-ACTIVE', $dataActive['rows'][0]['code']);
    }

    /**
     * TEST 19: Heat number search respects user product_scope authorization
     */
    public function test_19_search_heat_number_respects_product_scope(): void
    {
        // FLANGE_STAINLESS plan with heat HN-SS-001 (outside ppicUser scope FLANGE_BESI)
        $planSS = $this->createPlan([
            'code' => 'S190-SS',
            'product_scope' => 'FLANGE_STAINLESS',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
        ]);
        $this->createKtr($planSS, 100, 0, 'netto', 'HN-SS-001');

        // PPIC user searches for HN-SS-001 -> should return 0 rows
        $response = $this->actingAs($this->ppicUser)
            ->getJson('/sand-casting/production-status?search=HN-SS-001');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(0, $data['rows']);

        // Admin searches for HN-SS-001 -> should return 1 row
        $responseAdmin = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?search=HN-SS-001');

        $responseAdmin->assertStatus(200);
        $this->assertCount(1, $responseAdmin->json('rows'));
    }

    /**
     * TEST 20: View renders the updated placeholder for Heat Number and KTR
     */
    public function test_20_view_displays_updated_search_placeholder(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/sand-casting/production-status');

        $response->assertStatus(200);
        $response->assertSee('placeholder="Cari Kode / PO / Item / Heat Number / KTR..."', false);
    }

    /**
     * TEST 21: Column filters codes, products, and customers isolate data individually and in combination
     */
    public function test_21_column_filters_codes_products_customers_individually_and_in_combination(): void
    {
        $planA = $this->createPlan(['code' => 'SPK-A', 'item_name' => 'Flange SS304 4 Inch', 'customer' => 'PT SINAR UTAMA']);
        $planB = $this->createPlan(['code' => 'SPK-B', 'item_name' => 'Flange SS316 6 Inch', 'customer' => 'PT BINA TEKNIK']);
        $planC = $this->createPlan(['code' => 'SPK-C', 'item_name' => 'Flange SS304 4 Inch', 'customer' => 'PT BINA TEKNIK']);

        $this->createKtr($planA, 100, 0, 'netto');
        $this->createKtr($planB, 100, 0, 'netto');
        $this->createKtr($planC, 100, 0, 'netto');

        // 1. Filter codes[]
        $resCode = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?codes[]=SPK-A&codes[]=SPK-B');
        $resCode->assertStatus(200);
        $this->assertCount(2, $resCode->json('rows'));

        // 2. Filter products[]
        $resProd = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?products[]='.urlencode('Flange SS304 4 Inch'));
        $resProd->assertStatus(200);
        $this->assertCount(2, $resProd->json('rows'));
        $this->assertEqualsCanonicalizing(['SPK-A', 'SPK-C'], collect($resProd->json('rows'))->pluck('code')->all());

        // 3. Filter customers[]
        $resCust = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?customers[]='.urlencode('PT BINA TEKNIK'));
        $resCust->assertStatus(200);
        $this->assertCount(2, $resCust->json('rows'));
        $this->assertEqualsCanonicalizing(['SPK-B', 'SPK-C'], collect($resCust->json('rows'))->pluck('code')->all());

        // 4. Combination: products=Flange SS304 4 Inch AND customers=PT BINA TEKNIK -> only SPK-C
        $resCombo = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?products[]='.urlencode('Flange SS304 4 Inch').'&customers[]='.urlencode('PT BINA TEKNIK'));
        $resCombo->assertStatus(200);
        $this->assertCount(1, $resCombo->json('rows'));
        $this->assertSame('SPK-C', $resCombo->json('rows.0.code'));

        // 5. Triple combination including codes[]
        $resTriple = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?codes[]=SPK-C&products[]='.urlencode('Flange SS304 4 Inch').'&customers[]='.urlencode('PT BINA TEKNIK'));
        $resTriple->assertStatus(200);
        $this->assertCount(1, $resTriple->json('rows'));
        $this->assertSame('SPK-C', $resTriple->json('rows.0.code'));

        // Mismatching triple combination returns 0 rows
        $resEmpty = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?codes[]=SPK-A&products[]='.urlencode('Flange SS316 6 Inch'));
        $resEmpty->assertStatus(200);
        $this->assertCount(0, $resEmpty->json('rows'));
    }

    /**
     * TEST 22: Column filters work together with global search
     */
    public function test_22_column_filters_work_together_with_global_search(): void
    {
        $plan1 = $this->createPlan(['code' => 'SPK-01', 'item_name' => 'Elbow 90 SS304', 'customer' => 'PT ALPHA', 'po_number' => 'PO-100']);
        $plan2 = $this->createPlan(['code' => 'SPK-02', 'item_name' => 'Elbow 90 SS304', 'customer' => 'PT ALPHA', 'po_number' => 'PO-200']);

        $this->createKtr($plan1, 50, 0, 'netto');
        $this->createKtr($plan2, 50, 0, 'netto');

        // Filter products[] AND search=PO-200
        $res = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?products[]='.urlencode('Elbow 90 SS304').'&search=PO-200');

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('rows'));
        $this->assertSame('SPK-02', $res->json('rows.0.code'));
    }

    /**
     * TEST 23: Column filters maintain consistency across status tabs
     */
    public function test_23_column_filters_remain_consistent_across_status_tabs(): void
    {
        $planActive = $this->createPlan(['code' => 'SPK-ACT', 'customer' => 'PT TARGET', 'qty_planned' => 100, 'po_quantity' => 100]);
        $this->createKtr($planActive, 50, 0, 'netto'); // Incomplete, still in netto -> ACTIVE

        $planComp = $this->createPlan(['code' => 'SPK-COMP', 'customer' => 'PT TARGET', 'qty_planned' => 100, 'po_quantity' => 100]);
        $ktrComp = $this->createKtr($planComp, 100, 0, 'completed');
        $ktrComp->stageExecutions()->create([
            'stage' => 'gudang_jadi',
            'checkpoint_code' => 'GUDANG_RECEIVE',
            'input_qty' => 100,
            'defect_qty' => 0,
            'good_qty' => 100,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->adminUser->id,
            'executed_at' => now(),
            'physical_done_at' => now(),
        ]);

        // Filter customer=PT TARGET under active tab
        $resActive = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?customers[]=PT TARGET&filter=active');
        $resActive->assertStatus(200);
        $this->assertCount(1, $resActive->json('rows'));
        $this->assertSame('SPK-ACT', $resActive->json('rows.0.code'));
        $this->assertSame(1, $resActive->json('summary.active_count'));
        $this->assertSame(1, $resActive->json('summary.completed_count'));
        $this->assertSame(2, $resActive->json('summary.total_count'));

        // Switch to completed tab with same customer filter
        $resComp = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?customers[]=PT TARGET&filter=completed');
        $resComp->assertStatus(200);
        $this->assertCount(1, $resComp->json('rows'));
        $this->assertSame('SPK-COMP', $resComp->json('rows.0.code'));

        // Switch to all tab with same customer filter
        $resAll = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?customers[]=PT TARGET&filter=all');
        $resAll->assertStatus(200);
        $this->assertCount(2, $resAll->json('rows'));
    }

    /**
     * TEST 24: View renders Excel filter triggers, popover dropdowns, and active badges
     */
    public function test_24_view_renders_excel_filter_triggers_popovers_and_active_badges(): void
    {
        $plan = $this->createPlan(['code' => 'SPK-VIEW', 'item_name' => 'Valve Body 2 Inch', 'customer' => 'PT METAL JAYA']);
        $this->createKtr($plan, 100, 0, 'netto');

        // Request view with active filters
        $response = $this->actingAs($this->adminUser)
            ->get('/sand-casting/production-status?codes[]=SPK-VIEW&products[]='.urlencode('Valve Body 2 Inch').'&customers[]='.urlencode('PT METAL JAYA'));

        $response->assertStatus(200);

        // Header buttons exist with active filter icon classes
        $response->assertSee('data-filter-type="codes"', false);
        $response->assertSee('data-filter-type="products"', false);
        $response->assertSee('data-filter-type="customers"', false);
        $response->assertSee('title="Filter Kode Produksi"', false);
        $response->assertSee('title="Filter Nama Produk"', false);
        $response->assertSee('title="Filter Customer"', false);

        // Popovers exist with search, select all, clear, and action buttons
        $response->assertSee('id="codes-filter-dropdown"', false);
        $response->assertSee('id="products-filter-dropdown"', false);
        $response->assertSee('id="customers-filter-dropdown"', false);
        $response->assertSee('Filter Kode Produksi', false);
        $response->assertSee('Filter Nama Produk', false);
        $response->assertSee('Filter Customer', false);
        $response->assertSee('applyFilter(\'codes\')', false);
        $response->assertSee('applyFilter(\'products\')', false);
        $response->assertSee('applyFilter(\'customers\')', false);

        // Active filter pills and Clear All link exist
        $response->assertSee('Active Filters:', false);
        $response->assertSee('Kode: SPK-VIEW', false);
        $response->assertSee('Product: Valve Body 2 Inch', false);
        $response->assertSee('Customer: PT METAL JAYA', false);
        $response->assertSee('Clear All', false);

        // View data has options collections
        $response->assertViewHas('allCodes');
        $response->assertViewHas('allProducts');
        $response->assertViewHas('allCustomers');
    }

    /**
     * TEST 25: Dropdown options are not lost when filtering other dimensions
     */
    public function test_25_dropdown_options_not_lost_when_filtering_other_columns(): void
    {
        $plan1 = $this->createPlan(['code' => 'SPK-OP1', 'item_name' => 'Item Alpha', 'customer' => 'Customer One']);
        $plan2 = $this->createPlan(['code' => 'SPK-OP2', 'item_name' => 'Item Beta', 'customer' => 'Customer Two']);

        $this->createKtr($plan1, 50, 0, 'netto');
        $this->createKtr($plan2, 50, 0, 'netto');

        // When filtering code=SPK-OP1, products dropdown should still contain 'Item Beta' and customers should contain 'Customer Two'
        $response = $this->actingAs($this->adminUser)
            ->get('/sand-casting/production-status?codes[]=SPK-OP1');

        $response->assertStatus(200);

        $allCodes = $response->viewData('allCodes');
        $allProducts = $response->viewData('allProducts');
        $allCustomers = $response->viewData('allCustomers');

        $this->assertContains('SPK-OP1', $allCodes);
        $this->assertContains('SPK-OP2', $allCodes);

        $this->assertContains('Item Alpha', $allProducts);
        $this->assertContains('Item Beta', $allProducts);

        $this->assertContains('Customer One', $allCustomers);
        $this->assertContains('Customer Two', $allCustomers);
    }

    /**
     * TEST 26: Planning group filter isolates records and prevents cross-group leakage
     */
    public function test_26_planning_group_filter_isolates_records_and_prevents_cross_group_leakage(): void
    {
        // Two different planning groups with identical item_code and item_name
        $planNov = $this->createPlan([
            'title' => 'A02 NOVEMBER',
            'code' => 'SPK-NOV-01',
            'item_code' => 'FLG.SS304.10K',
            'item_name' => 'SS304 JIS 10K 10 Inch',
            'customer' => 'A02',
        ]);
        $planSep = $this->createPlan([
            'title' => 'A02 SEPTEMBER',
            'code' => 'SPK-SEP-01',
            'item_code' => 'FLG.SS304.10K',
            'item_name' => 'SS304 JIS 10K 10 Inch',
            'customer' => 'A02',
        ]);
        $planStok = $this->createPlan([
            'title' => 'STOK OKTOBER',
            'code' => 'SPK-STOK-01',
            'item_code' => 'FLG.SS304.16K',
            'item_name' => 'SS304 JIS 16K 10 Inch',
            'customer' => 'INTERNAL',
        ]);

        $this->createKtr($planNov, 50, 0, 'netto');
        $this->createKtr($planSep, 50, 0, 'netto');
        $this->createKtr($planStok, 30, 0, 'netto');

        // Filter by A02 NOVEMBER
        $response = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?planning_groups[]='.urlencode('A02 NOVEMBER'));

        $response->assertStatus(200);
        $codes = collect($response->json('rows'))->pluck('code')->all();

        $this->assertContains('SPK-NOV-01', $codes);
        $this->assertNotContains('SPK-SEP-01', $codes);
        $this->assertNotContains('SPK-STOK-01', $codes);

        // Also test alias parameter 'planning_group'
        $responseAlias = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?planning_group='.urlencode('A02 NOVEMBER'));

        $responseAlias->assertStatus(200);
        $aliasCodes = collect($responseAlias->json('rows'))->pluck('code')->all();
        $this->assertEquals(['SPK-NOV-01'], $aliasCodes);
    }

    /**
     * TEST 27: Planning group filter combines with code, product, customer, and search
     */
    public function test_27_planning_group_combines_with_code_product_customer_and_search(): void
    {
        $plan1 = $this->createPlan([
            'title' => 'A02 NOVEMBER',
            'code' => 'SPK-NOV-01',
            'item_name' => 'Flange A',
            'customer' => 'A02',
        ]);
        $plan2 = $this->createPlan([
            'title' => 'A02 NOVEMBER',
            'code' => 'SPK-NOV-02',
            'item_name' => 'Flange B',
            'customer' => 'A02',
        ]);
        $plan3 = $this->createPlan([
            'title' => 'E02 OKTOBER',
            'code' => 'SPK-OCT-01',
            'item_name' => 'Flange A',
            'customer' => 'E02',
        ]);

        $this->createKtr($plan1, 20, 0, 'netto');
        $this->createKtr($plan2, 20, 0, 'netto');
        $this->createKtr($plan3, 20, 0, 'netto');

        // Combination: planning_group = A02 NOVEMBER AND products[] = Flange A
        $response = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?planning_groups[]='.urlencode('A02 NOVEMBER').'&products[]='.urlencode('Flange A'));

        $response->assertStatus(200);
        $codes = collect($response->json('rows'))->pluck('code')->all();
        $this->assertEquals(['SPK-NOV-01'], $codes);

        // Global search within planning group
        $responseSearch = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?planning_groups[]='.urlencode('A02 NOVEMBER').'&search=NOV-02');

        $responseSearch->assertStatus(200);
        $searchCodes = collect($responseSearch->json('rows'))->pluck('code')->all();
        $this->assertEquals(['SPK-NOV-02'], $searchCodes);
    }

    /**
     * TEST 28: Summary counts reflect planning group filter
     */
    public function test_28_summary_counts_reflect_planning_group_filter(): void
    {
        // In A02 NOVEMBER: 1 active plan, 1 completed plan
        $planNovActive = $this->createPlan(['title' => 'A02 NOVEMBER', 'code' => 'NOV-ACT', 'po_quantity' => 50]);
        $this->createKtr($planNovActive, 50, 0, 'netto');

        $planNovComp = $this->createPlan(['title' => 'A02 NOVEMBER', 'code' => 'NOV-CMP', 'po_quantity' => 40]);
        $ktrComp = $this->createKtr($planNovComp, 40, 0, 'completed');
        $ktrComp->stageExecutions()->create([
            'stage' => 'gudang_jadi',
            'checkpoint_code' => 'GUDANG_RECEIVE',
            'input_qty' => 40,
            'defect_qty' => 0,
            'good_qty' => 40,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->adminUser->id,
            'executed_at' => now(),
            'physical_done_at' => now(),
        ]);

        // In OTHER GROUP: 2 active plans
        $planOther1 = $this->createPlan(['title' => 'OTHER GROUP', 'code' => 'OTH-01', 'po_quantity' => 10]);
        $this->createKtr($planOther1, 10, 0, 'netto');
        $planOther2 = $this->createPlan(['title' => 'OTHER GROUP', 'code' => 'OTH-02', 'po_quantity' => 20]);
        $this->createKtr($planOther2, 20, 0, 'netto');

        $response = $this->actingAs($this->adminUser)
            ->getJson('/sand-casting/production-status?planning_groups[]='.urlencode('A02 NOVEMBER'));

        $response->assertStatus(200);
        $summary = $response->json('summary');

        $this->assertEquals(1, $summary['active_count']);
        $this->assertEquals(1, $summary['completed_count']);
        $this->assertEquals(2, $summary['total_count']);
    }

    /**
     * TEST 29: Planning group options respect domain and product scope
     */
    public function test_29_planning_group_options_respect_domain_and_product_scope(): void
    {
        // Sand casting plans with different scopes
        $this->createPlan([
            'title' => 'GROUP FLANGE BESI',
            'code' => 'SC-BESI',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
        ]);
        $this->createPlan([
            'title' => 'GROUP FLANGE STAINLESS',
            'code' => 'SC-SS',
            'product_scope' => 'FLANGE_STAINLESS',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
        ]);
        // Lost wax plan
        $this->createPlan([
            'title' => 'GROUP LOST WAX ONLY',
            'code' => 'LW-01',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
        ]);

        // When accessed by PPIC user with FLANGE_BESI scope
        $response = $this->actingAs($this->ppicUser)->get('/sand-casting/production-status');
        $response->assertStatus(200);

        $allPlanningGroups = $response->viewData('allPlanningGroups');

        $this->assertContains('GROUP FLANGE BESI', $allPlanningGroups);
        $this->assertNotContains('GROUP FLANGE STAINLESS', $allPlanningGroups);
        $this->assertNotContains('GROUP LOST WAX ONLY', $allPlanningGroups);
    }

    /**
     * TEST 30: View renders planning group trigger, popover, and active filter badge
     */
    public function test_30_view_renders_planning_group_trigger_popover_and_badge(): void
    {
        $plan = $this->createPlan(['title' => 'A02 NOVEMBER', 'code' => 'SPK-VIEW-PG']);
        $this->createKtr($plan, 30, 0, 'netto');

        $response = $this->actingAs($this->adminUser)
            ->get('/sand-casting/production-status?planning_groups[]='.urlencode('A02 NOVEMBER'));

        $response->assertStatus(200);

        // Header trigger button exists
        $response->assertSee('data-filter-type="planning_groups"', false);
        $response->assertSee('Kelompok', false);

        // Popover exists
        $response->assertSee('id="planning_groups-filter-dropdown"', false);
        $response->assertSee('Filter Kelompok Perencanaan', false);
        $response->assertSee('applyFilter(\'planning_groups\')', false);

        // Active badge and remove button exist
        $response->assertSee('Kelompok: A02 NOVEMBER', false);
        $response->assertSee('removeFilterValue(\'planning_groups\', \'A02 NOVEMBER\')', false);

        // View data has allPlanningGroups
        $response->assertViewHas('allPlanningGroups');
        $this->assertContains('A02 NOVEMBER', $response->viewData('allPlanningGroups'));
    }
}
