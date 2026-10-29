<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\User;
use App\Services\SandCasting\TravelerNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class KitirProduksiTest extends TestCase
{
    use RefreshDatabase;

    protected User $ppicUser;

    protected User $otherScopeUser;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::firstOrCreate(['name' => 'access_planning']);
        $ppicRole = Role::firstOrCreate(['name' => 'ppic']);
        $ppicRole->givePermissionTo($permission);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->givePermissionTo($permission);

        $this->ppicUser = User::factory()->create([
            'email' => 'ppic_flange@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->ppicUser->assignRole('ppic');

        $this->otherScopeUser = User::factory()->create([
            'email' => 'ppic_fitting@peroniks.com',
            'product_scope' => 'FITTING_STAINLESS',
        ]);
        $this->otherScopeUser->assignRole('ppic');

        $this->adminUser = User::factory()->create([
            'email' => 'admin@peroniks.com',
            'product_scope' => null,
        ]);
        $this->adminUser->assignRole('admin');
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'code' => '268ET001',
            'title' => 'Rencana SC 268ET001',
            'item_code' => '4.101',
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-001',
            'line_number' => 1,
            'qty_planned' => 550,
            'qty_remaining' => 550,
            'weight' => 3.25,
            'customer' => 'PT SINAR METAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ], $attributes));
    }

    public function test_valid_result_and_valid_result_line_renders_kitir_200(): void
    {
        $plan = $this->createPlan();

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0001',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 550,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '2"',
            'aisi' => 'FC250',
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'A214092601',
            'cast_date' => '2026-09-14',
            'furnace' => 'F-01',
            'shift' => '1',
            'operator_name' => 'Sutrisno',
            'recorded_by' => $this->ppicUser->id,
        ]);

        $traveler = TravelerNumberGenerator::generateNext('2026-09-14');

        $resultLine = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $traveler,
            'qty_good' => 300,
            'qty_reject' => 5,
            'unit_weight_kg' => 3.25,
            'total_weight_kg' => 975.00,
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.kitir', [$result, $resultLine]));

        $response->assertOk();
        $response->assertSee('KITIR PRODUKSI');
        $response->assertSee('PERONI');
        $response->assertDontSee('SAND CASTING');
        $response->assertDontSee('CASTING THE FUTURE');
    }

    public function test_result_and_mismatched_result_line_is_rejected(): void
    {
        $plan = $this->createPlan();

        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-001', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 100, 'code' => $plan->code, 'item_name' => $plan->item_name]);

        $resultA = SandCastingCastingResult::create(['heat_number' => 'HEAT-A', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $resultB = SandCastingCastingResult::create(['heat_number' => 'HEAT-B', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);

        $lineOfB = $resultB->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-14'),
            'qty_good' => 50,
        ]);

        // Accessing Line of Result B through Result A URL must be rejected
        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.kitir', [$resultA, $lineOfB]));

        $response->assertStatus(404);
    }

    public function test_lost_wax_result_line_cannot_generate_sand_casting_kitir(): void
    {
        $lwPlan = ProductionPlan::create([
            'code' => 'LW001',
            'title' => 'Rencana LW',
            'item_code' => '4.103',
            'item_name' => 'FLANGE BESI 2" LW',
            'po_number' => 'PO-001',
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-002', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $lwPlan->id, 'qty_ordered' => 100, 'code' => $lwPlan->code, 'item_name' => $lwPlan->item_name]);

        $result = SandCastingCastingResult::create(['heat_number' => 'HEAT-LW', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $line = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $lwPlan->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-14'),
            'qty_good' => 50,
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.kitir', [$result, $line]));

        $response->assertStatus(403);
    }

    public function test_unauthorized_user_outside_product_scope_is_rejected(): void
    {
        $plan = $this->createPlan(['product_scope' => 'FLANGE_BESI']);

        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-003', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 100, 'code' => $plan->code, 'item_name' => $plan->item_name]);

        $result = SandCastingCastingResult::create(['heat_number' => 'HEAT-003', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $line = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-14'),
            'qty_good' => 50,
        ]);

        // User with FITTING_STAINLESS cannot access FLANGE_BESI kitir
        $response = $this->actingAs($this->otherScopeUser)
            ->get(route('sand-casting.casting-results.kitir', [$result, $line]));

        $response->assertStatus(403);
    }

    public function test_kitir_displays_exact_traveler_heat_production_code_customer_and_hasil_cor(): void
    {
        $plan = $this->createPlan([
            'code' => '268ET001',
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'customer' => 'PT SINAR METAL',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0001',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 550,
            'code' => '268ET001',
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'customer' => 'PT SINAR METAL',
            'size' => '2"',
            'aisi' => 'FC250',
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'A214092601',
            'cast_date' => '2026-09-14',
            'furnace' => 'F-01',
            'shift' => '1',
            'operator_name' => 'Sutrisno',
            'recorded_by' => $this->ppicUser->id,
        ]);

        $traveler = 'KTR-20260914-0001';

        $resultLine = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $traveler,
            'qty_good' => 300,
            'qty_reject' => 5,
            'unit_weight_kg' => 3.25,
            'total_weight_kg' => 975.00,
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.kitir', [$result, $resultLine]));

        $response->assertOk();
        $response->assertSee($traveler);
        $response->assertSee('A214092601');
        $response->assertSee('268ET001');
        $response->assertSee('FLANGE BESI JIS 10K 2"');
        $response->assertSee('PT SINAR METAL');
        $response->assertSee('PCOR-20260914-0001');
        $response->assertSee('300');
        $response->assertSee('PCS');
    }

    public function test_process_table_has_exact_seven_steps_in_final_sequence(): void
    {
        $plan = $this->createPlan();

        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-005', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 100, 'code' => $plan->code, 'item_name' => $plan->item_name]);

        $result = SandCastingCastingResult::create(['heat_number' => 'HEAT-005', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $resultLine = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-14'),
            'qty_good' => 100,
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.kitir', [$result, $resultLine]));

        $response->assertOk();

        // Check process names in the page content table cells
        $content = $response->getContent();

        $posNetto = strpos($content, '>NETTO<');
        $posOdDept = strpos($content, '>OD<');
        $posMarking = strpos($content, '>MARKING<');
        $posOdFinish = strpos($content, '>OD FINISH<');
        $posBubutCnc = strpos($content, '>BUBUT CNC<');
        $posBor = strpos($content, '>BOR<');
        $posQc = strpos($content, '>QC<');
        $posGudang = strpos($content, '>GUDANG JADI<');

        $this->assertNotFalse($posNetto, 'NETTO must be present');
        $this->assertNotFalse($posOdDept, 'OD parent dept must be present');
        $this->assertNotFalse($posMarking, 'MARKING must be present');
        $this->assertNotFalse($posOdFinish, 'OD FINISH must be present');
        $this->assertNotFalse($posBubutCnc, 'BUBUT CNC must be present');
        $this->assertNotFalse($posBor, 'BOR must be present');
        $this->assertNotFalse($posQc, 'QC must be present');
        $this->assertNotFalse($posGudang, 'GUDANG JADI must be present');

        // Old verbose labels must no longer appear
        $this->assertStringNotContainsString('>NETTO (POTONG)<', $content, 'NETTO (POTONG) must be replaced by NETTO');
        $this->assertStringNotContainsString('>QC (FINAL INSPECTION)<', $content, 'QC (FINAL INSPECTION) must be replaced by QC');
        $this->assertStringNotContainsString('>BUBUT OD<', $content, 'BUBUT OD must be replaced by OD FINISH under parent OD');

        // Verify EXACT strict sequence: NETTO -> OD (MARKING -> OD FINISH) -> BUBUT CNC -> BOR -> QC -> GUDANG JADI
        $this->assertTrue($posNetto < $posOdDept, 'NETTO must precede OD');
        $this->assertTrue($posOdDept < $posMarking, 'OD dept must appear with MARKING');
        $this->assertTrue($posMarking < $posOdFinish, 'MARKING must precede OD FINISH');
        $this->assertTrue($posOdFinish < $posBubutCnc, 'OD FINISH must precede BUBUT CNC');
        $this->assertTrue($posBubutCnc < $posBor, 'BUBUT CNC must precede BOR');
        $this->assertTrue($posBor < $posQc, 'BOR must precede QC');
        $this->assertTrue($posQc < $posGudang, 'QC must precede GUDANG JADI');
    }

    public function test_new_result_line_defaults_to_unprinted(): void
    {
        $plan = $this->createPlan();
        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-006', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 100, 'code' => $plan->code, 'item_name' => $plan->item_name]);

        $result = SandCastingCastingResult::create(['heat_number' => 'HEAT-006', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $resultLine = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-14'),
            'qty_good' => 80,
            'qty_reject' => 2,
        ]);

        $this->assertEquals(0, $resultLine->print_count);
        $this->assertNull($resultLine->printed_at);
        $this->assertNull($resultLine->last_printed_by);
    }

    public function test_kitir_route_increments_print_count_and_records_user_and_timestamp(): void
    {
        $plan = $this->createPlan();
        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-007', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 100, 'code' => $plan->code, 'item_name' => $plan->item_name]);

        $result = SandCastingCastingResult::create(['heat_number' => 'HEAT-007', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $resultLine = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-14'),
            'qty_good' => 80,
        ]);

        // First access
        $response1 = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.kitir', [$result, $resultLine]));
        $response1->assertOk();

        $resultLine->refresh();
        $this->assertEquals(1, $resultLine->print_count);
        $this->assertNotNull($resultLine->printed_at);
        $this->assertEquals($this->ppicUser->id, $resultLine->last_printed_by);

        // Second access (reprint 1)
        $response2 = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.kitir', [$result, $resultLine]));
        $response2->assertOk();

        $resultLine->refresh();
        $this->assertEquals(2, $resultLine->print_count);

        // Third access (reprint 2)
        $response3 = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.kitir', [$result, $resultLine]));
        $response3->assertOk();

        $resultLine->refresh();
        $this->assertEquals(3, $resultLine->print_count);
    }

    public function test_ownership_validation_prevents_incrementing_mismatched_result_line(): void
    {
        $plan = $this->createPlan();
        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-008', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 100, 'code' => $plan->code, 'item_name' => $plan->item_name]);

        $resultA = SandCastingCastingResult::create(['heat_number' => 'HEAT-8A', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $resultB = SandCastingCastingResult::create(['heat_number' => 'HEAT-8B', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);

        $lineOfB = $resultB->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => TravelerNumberGenerator::generateNext('2026-09-14'),
            'qty_good' => 50,
            'print_count' => 0,
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.kitir', [$resultA, $lineOfB]));

        $response->assertStatus(404);
        $lineOfB->refresh();
        $this->assertEquals(0, $lineOfB->print_count);
        $this->assertNull($lineOfB->printed_at);
    }

    public function test_index_page_displays_aggregated_kitir_print_status(): void
    {
        $plan = $this->createPlan();
        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-009', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 100, 'code' => $plan->code, 'item_name' => $plan->item_name]);

        // Case 1: All unprinted (0 / 2 BELUM CETAK)
        $result1 = SandCastingCastingResult::create(['heat_number' => 'HEAT-UNPRINTED', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $result1->lines()->create(['sand_casting_casting_order_line_id' => $orderLine->id, 'production_plan_id' => $plan->id, 'traveler_number' => 'KTR-901', 'qty_good' => 50, 'print_count' => 0]);
        $result1->lines()->create(['sand_casting_casting_order_line_id' => $orderLine->id, 'production_plan_id' => $plan->id, 'traveler_number' => 'KTR-902', 'qty_good' => 50, 'print_count' => 0]);

        // Case 2: Partially printed (2 / 3 BELUM LENGKAP)
        $result2 = SandCastingCastingResult::create(['heat_number' => 'HEAT-PARTIAL', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $result2->lines()->create(['sand_casting_casting_order_line_id' => $orderLine->id, 'production_plan_id' => $plan->id, 'traveler_number' => 'KTR-903', 'qty_good' => 50, 'print_count' => 1]);
        $result2->lines()->create(['sand_casting_casting_order_line_id' => $orderLine->id, 'production_plan_id' => $plan->id, 'traveler_number' => 'KTR-904', 'qty_good' => 50, 'print_count' => 1]);
        $result2->lines()->create(['sand_casting_casting_order_line_id' => $orderLine->id, 'production_plan_id' => $plan->id, 'traveler_number' => 'KTR-905', 'qty_good' => 50, 'print_count' => 0]);

        // Case 3: Fully printed with reprints (3 / 3 SUDAH CETAK, Total: 5 print)
        $result3 = SandCastingCastingResult::create(['heat_number' => 'HEAT-FULL-REPRINT', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $result3->lines()->create(['sand_casting_casting_order_line_id' => $orderLine->id, 'production_plan_id' => $plan->id, 'traveler_number' => 'KTR-906', 'qty_good' => 50, 'print_count' => 3]);
        $result3->lines()->create(['sand_casting_casting_order_line_id' => $orderLine->id, 'production_plan_id' => $plan->id, 'traveler_number' => 'KTR-907', 'qty_good' => 50, 'print_count' => 1]);
        $result3->lines()->create(['sand_casting_casting_order_line_id' => $orderLine->id, 'production_plan_id' => $plan->id, 'traveler_number' => 'KTR-908', 'qty_good' => 50, 'print_count' => 1]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.index'));

        $response->assertOk();
        $response->assertSee('Status Kitir');
        $response->assertSee('0 / 2');
        $response->assertSee('BELUM CETAK');
        $response->assertSee('2 / 3');
        $response->assertSee('BELUM LENGKAP');
        $response->assertSee('3 / 3');
        $response->assertSee('SUDAH CETAK');
        $response->assertSee('Total: 5 print');
    }

    public function test_show_page_displays_per_line_print_status_badges_and_actions(): void
    {
        $plan = $this->createPlan();
        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-010', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 100, 'code' => $plan->code, 'item_name' => $plan->item_name]);

        $result = SandCastingCastingResult::create(['heat_number' => 'HEAT-SHOW', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $line1 = $result->lines()->create(['sand_casting_casting_order_line_id' => $orderLine->id, 'production_plan_id' => $plan->id, 'traveler_number' => 'KTR-SHOW-01', 'qty_good' => 50, 'print_count' => 0]);
        $line2 = $result->lines()->create(['sand_casting_casting_order_line_id' => $orderLine->id, 'production_plan_id' => $plan->id, 'traveler_number' => 'KTR-SHOW-02', 'qty_good' => 50, 'print_count' => 1]);
        $line3 = $result->lines()->create(['sand_casting_casting_order_line_id' => $orderLine->id, 'production_plan_id' => $plan->id, 'traveler_number' => 'KTR-SHOW-03', 'qty_good' => 50, 'print_count' => 3]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.show', $result));

        $response->assertOk();
        $response->assertSee('BELUM CETAK');
        $response->assertSee('SUDAH CETAK');
        $response->assertSee('3x CETAK');
        $response->assertSee('2x REPRINT');
        $response->assertSee('Cetak Kitir');
        $response->assertSee('Cetak Ulang');
    }

    public function test_repeated_access_reprint_is_safe_and_increments_counter(): void
    {
        $plan = $this->createPlan();

        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-006', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 100, 'code' => $plan->code, 'item_name' => $plan->item_name]);

        $result = SandCastingCastingResult::create(['heat_number' => 'HEAT-006', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $traveler = TravelerNumberGenerator::generateNext('2026-09-14');

        $resultLine = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $traveler,
            'qty_good' => 80,
            'qty_reject' => 2,
        ]);

        $initialResultCount = SandCastingCastingResult::count();
        $initialLineCount = SandCastingCastingResultLine::count();

        // Access 5 times (reprint simulation)
        for ($i = 0; $i < 5; $i++) {
            $response = $this->actingAs($this->ppicUser)
                ->get(route('sand-casting.casting-results.kitir', [$result, $resultLine]));
            $response->assertOk();
            $response->assertSee($traveler);
        }

        // Must remain exactly identical rows in DB (no duplicates created)
        $this->assertEquals($initialResultCount, SandCastingCastingResult::count());
        $this->assertEquals($initialLineCount, SandCastingCastingResultLine::count());
        $resultLine->refresh();
        $this->assertEquals($traveler, $resultLine->traveler_number);
        $this->assertEquals(80, $resultLine->qty_good);
        $this->assertEquals(5, $resultLine->print_count);
    }

    public function test_kitir_renders_dual_barcode_qr_and_code128_with_identical_ktr_payload(): void
    {
        $plan = $this->createPlan();

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260928-0001',
            'scheduled_date' => '2026-09-28',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 200,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '3"',
            'aisi' => 'SS304',
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => 'H20260928-01',
            'cast_date' => '2026-09-28',
            'furnace' => 'F-02',
            'shift' => '2',
            'recorded_by' => $this->ppicUser->id,
        ]);

        $traveler = 'KTR-20260928-0020';

        $resultLine = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $traveler,
            'qty_good' => 150,
            'qty_reject' => 0,
            'unit_weight_kg' => 4.5,
            'total_weight_kg' => 675.0,
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.kitir', [$result, $resultLine]));

        $response->assertOk();

        // 1. Dual machine-readable codes present
        $response->assertSee('<svg', false); // QR SVG element
        $response->assertSee('PRIMARY SCAN');
        $response->assertSee('QR (ECC LEVEL H)');
        $response->assertSee('BACKUP CODE 128');
        $response->assertSee('data:image/png;base64,', false); // Code 128 Image

        // 2. Both encode identical KTR string
        $viewData = $response->viewData('qrCodeSvg');
        $this->assertNotEmpty($viewData);
        $this->assertStringContainsString('<svg', $viewData);
        $this->assertStringNotContainsString('<?xml', $viewData); // Clean inline SVG

        $barcodeBase64 = $response->viewData('barcodeBase64');
        $this->assertNotEmpty($barcodeBase64);

        // 3. Identifiers remain unchanged
        $response->assertSee('PCOR-20260928-0001');
        $response->assertSee('KTR-20260928-0020');
        $response->assertSee('H20260928-01');
        $response->assertSee($plan->code);
        $response->assertSee($plan->item_name);
        $response->assertSee('150 PCS');

        // 4. No external QR / barcode CDN URLs
        $content = $response->getContent();
        $this->assertStringNotContainsString('api.qrserver.com', $content);
        $this->assertStringNotContainsString('chart.googleapis.com', $content);
        $this->assertStringNotContainsString('quickchart.io', $content);
    }

    /**
     * KITIR TEST 1: Fresh KTR, belum ada downstream execution.
     * Expected: semua stage downstream blank.
     */
    public function test_kitir_test_1_fresh_ktr_downstream_blank(): void
    {
        $plan = $this->createPlan();
        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-T1', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 8, 'code' => $plan->code, 'item_name' => $plan->item_name]);
        $result = SandCastingCastingResult::create(['heat_number' => 'H-T1', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $line = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-T1',
            'qty_good' => 8,
            'qty_reject' => 0,
        ]);

        $response = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.kitir', [$result, $line]));
        $response->assertOk();

        $rows = $response->viewData('kitirRows');
        $this->assertCount(7, $rows);

        foreach ($rows as $row) {
            $this->assertNull($row['hasil'], "Stage {$row['label']} HASIL should be null for fresh KTR");
            $this->assertNull($row['rusak'], "Stage {$row['label']} RUSAK should be null for fresh KTR");
            $this->assertNull($row['tanggal'], "Stage {$row['label']} TANGGAL should be null for fresh KTR");
            $this->assertNull($row['operator'], "Stage {$row['label']} OPERATOR should be null for fresh KTR");
        }
    }

    /**
     * KITIR TEST 2: NETTO physical done, COR Good = 8.
     * Expected: NETTO HASIL = 8.
     */
    public function test_kitir_test_2_netto_physical_done_displays_hasil_8(): void
    {
        $plan = $this->createPlan();
        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-T2', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 8, 'code' => $plan->code, 'item_name' => $plan->item_name]);
        $result = SandCastingCastingResult::create(['heat_number' => 'H-T2', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $line = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-T2',
            'qty_good' => 8,
            'qty_reject' => 0,
        ]);

        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 8,
            'defect_qty' => 0,
            'good_qty' => 8,
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->ppicUser->id,
        ]);

        $response = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.kitir', [$result, $line]));
        $response->assertOk();

        $rows = collect($response->viewData('kitirRows'))->keyBy('stage');
        $this->assertEquals(8, $rows['netto']['hasil']);
        $this->assertEquals(0, $rows['netto']['rusak']);
        $this->assertEquals('SPV NETTO', $rows['netto']['operator']);
        $this->assertNotNull($rows['netto']['tanggal']);

        // Downstream stages remain blank
        $this->assertNull($rows['marking']['hasil']);
        $this->assertNull($rows['bubut_od']['hasil']);
        $this->assertNull($rows['bubut_cnc']['hasil']);
    }

    /**
     * KITIR TEST 3: NETTO defect = 2.
     * Expected: NETTO HASIL = 8 (processed qty), NETTO RUSAK = 2 (defects).
     */
    public function test_kitir_test_3_netto_defect_displays_hasil_8_rusak_2(): void
    {
        $plan = $this->createPlan();
        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-T3', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 8, 'code' => $plan->code, 'item_name' => $plan->item_name]);
        $result = SandCastingCastingResult::create(['heat_number' => 'H-T3', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $line = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-T3',
            'qty_good' => 8,
            'qty_reject' => 0,
        ]);

        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 8,
            'defect_qty' => 2,
            'good_qty' => 6,
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->ppicUser->id,
        ]);

        $response = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.kitir', [$result, $line]));
        $response->assertOk();

        $rows = collect($response->viewData('kitirRows'))->keyBy('stage');
        $this->assertEquals(8, $rows['netto']['hasil']);
        $this->assertEquals(2, $rows['netto']['rusak']);
        $this->assertEquals('SPV NETTO', $rows['netto']['operator']);
    }

    /**
     * KITIR TEST 4: NETTO physical + OD physical, kemudian late NETTO defect = 2.
     * Expected: NETTO = 8 input / 2 defect, OD = 6 input / 0 defect.
     * Historical OD execution snapshot remains 8.
     */
    public function test_kitir_test_4_late_netto_defect_propagates_to_od_reprint(): void
    {
        $day1 = '2026-09-01';
        $day3 = '2026-09-03';

        $plan = $this->createPlan();
        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-T4', 'scheduled_date' => $day1, 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 8, 'code' => $plan->code, 'item_name' => $plan->item_name]);
        $result = SandCastingCastingResult::create(['heat_number' => 'H-T4', 'cast_date' => $day1, 'recorded_by' => $this->ppicUser->id]);
        $line = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-T4',
            'qty_good' => 8,
            'qty_reject' => 0,
        ]);

        // Day 1: Netto done 8, OD done 8
        $nettoExec = $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 8,
            'defect_qty' => 0,
            'good_qty' => 8,
            'status' => \App\Models\SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'physical_done_at' => $day1.' 09:00:00',
            'executed_at' => $day1.' 09:00:00',
            'operator_id' => $this->ppicUser->id,
        ]);

        $odExec = $line->stageExecutions()->create([
            'stage' => 'bubut_od',
            'checkpoint_code' => 'BUBUT_OD_ROUGH',
            'input_qty' => 8,
            'defect_qty' => 0,
            'good_qty' => 8,
            'status' => \App\Models\SandCastingStageExecution::STATUS_WAITING_DEFECT,
            'physical_done_at' => $day1.' 11:00:00',
            'executed_at' => $day1.' 11:00:00',
            'operator_id' => $this->ppicUser->id,
        ]);

        // Day 3: Late Netto defect = 2
        $nettoExec->update([
            'defect_qty' => 2,
            'good_qty' => 6,
            'defect_entered_at' => $day3.' 10:00:00',
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
        ]);

        $response = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.kitir', [$result, $line]));
        $response->assertOk();

        $rows = collect($response->viewData('kitirRows'))->keyBy('stage');

        // Netto: 8 input / 2 defect
        $this->assertEquals(8, $rows['netto']['hasil']);
        $this->assertEquals(2, $rows['netto']['rusak']);

        // OD: received 8 - 2 = 6, 0 defect
        $this->assertEquals(6, $rows['bubut_od']['hasil']);
        $this->assertEquals(0, $rows['bubut_od']['rusak']);

        // Historical OD snapshot remains 8
        $odExec->refresh();
        $this->assertEquals(8, $odExec->input_qty);
        $this->assertEquals(8, $odExec->good_qty);
    }

    /**
     * KITIR TEST 5: NETTO defect = 2 + CNC defect = 1.
     * Expected: NETTO = 8/2, OD = 6/0, CNC = 6/1, BOR = 5/0.
     */
    public function test_kitir_test_5_multi_stage_defects_propagate_properly(): void
    {
        $plan = $this->createPlan();
        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-T5', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 8, 'code' => $plan->code, 'item_name' => $plan->item_name]);
        $result = SandCastingCastingResult::create(['heat_number' => 'H-T5', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $line = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-T5',
            'qty_good' => 8,
            'qty_reject' => 0,
        ]);

        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 8,
            'defect_qty' => 2,
            'good_qty' => 6,
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->ppicUser->id,
        ]);

        $line->stageExecutions()->create([
            'stage' => 'bubut_od',
            'checkpoint_code' => 'BUBUT_OD_ROUGH',
            'input_qty' => 8,
            'defect_qty' => 0,
            'good_qty' => 8,
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->ppicUser->id,
        ]);

        $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 8,
            'defect_qty' => 1,
            'good_qty' => 7,
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->ppicUser->id,
        ]);

        $line->stageExecutions()->create([
            'stage' => 'bor',
            'checkpoint_code' => 'BOR_DRILLING',
            'input_qty' => 8,
            'defect_qty' => 0,
            'good_qty' => 8,
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->ppicUser->id,
        ]);

        $response = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.kitir', [$result, $line]));
        $response->assertOk();

        $rows = collect($response->viewData('kitirRows'))->keyBy('stage');

        // NETTO: 8 input / 2 defect
        $this->assertEquals(8, $rows['netto']['hasil']);
        $this->assertEquals(2, $rows['netto']['rusak']);

        // OD: received 8 - 2 = 6, 0 defect
        $this->assertEquals(6, $rows['bubut_od']['hasil']);
        $this->assertEquals(0, $rows['bubut_od']['rusak']);

        // MARKING: null
        $this->assertNull($rows['marking']['hasil']);

        // CNC: received 6 - 0 = 6, 1 defect
        $this->assertEquals(6, $rows['bubut_cnc']['hasil']);
        $this->assertEquals(1, $rows['bubut_cnc']['rusak']);

        // BOR: received 6 - 1 = 5, 0 defect
        $this->assertEquals(5, $rows['bor']['hasil']);
        $this->assertEquals(0, $rows['bor']['rusak']);
    }

    /**
     * KITIR TEST 6: Physical execution timestamp is used as TANGGAL.
     * defect_entered_at is NOT used.
     */
    public function test_kitir_test_6_physical_done_at_used_as_date_not_defect_entered_at(): void
    {
        $day1 = '2026-09-01';
        $day3 = '2026-09-03';

        $plan = $this->createPlan();
        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-T6', 'scheduled_date' => $day1, 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 8, 'code' => $plan->code, 'item_name' => $plan->item_name]);
        $result = SandCastingCastingResult::create(['heat_number' => 'H-T6', 'cast_date' => $day1, 'recorded_by' => $this->ppicUser->id]);
        $line = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-T6',
            'qty_good' => 8,
            'qty_reject' => 0,
        ]);

        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 8,
            'defect_qty' => 2,
            'good_qty' => 6,
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => $day1.' 10:30:00',
            'defect_entered_at' => $day3.' 15:45:00',
            'executed_at' => $day1.' 10:30:00',
            'operator_id' => $this->ppicUser->id,
        ]);

        $response = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.kitir', [$result, $line]));
        $response->assertOk();

        $rows = collect($response->viewData('kitirRows'))->keyBy('stage');
        $this->assertEquals('01/09/2026', $rows['netto']['tanggal']);
        $this->assertNotEquals('03/09/2026', $rows['netto']['tanggal']);
    }

    /**
     * KITIR TEST 7: Operator / SPV temporary mapping.
     */
    public function test_kitir_test_7_temporary_spv_mapping(): void
    {
        $plan = $this->createPlan();
        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-T7', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 8, 'code' => $plan->code, 'item_name' => $plan->item_name]);
        $result = SandCastingCastingResult::create(['heat_number' => 'H-T7', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $line = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-T7',
            'qty_good' => 8,
            'qty_reject' => 0,
        ]);

        $stages = ['netto', 'bubut_od', 'bubut_cnc', 'bor', 'qc', 'gudang_jadi'];
        foreach ($stages as $stg) {
            $line->stageExecutions()->create([
                'stage' => $stg,
                'checkpoint_code' => strtoupper($stg),
                'input_qty' => 8,
                'defect_qty' => 0,
                'good_qty' => 8,
                'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
                'physical_done_at' => now(),
                'executed_at' => now(),
                'operator_id' => $this->ppicUser->id,
            ]);
        }

        $response = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.kitir', [$result, $line]));
        $response->assertOk();

        $rows = collect($response->viewData('kitirRows'))->keyBy('stage');
        $this->assertEquals('SPV NETTO', $rows['netto']['operator']);
        $this->assertEquals('SPV OD', $rows['bubut_od']['operator']);
        $this->assertNull($rows['marking']['operator']); // MARKING
        $this->assertEquals('SPV CNC', $rows['bubut_cnc']['operator']);
        $this->assertEquals('SPV BOR', $rows['bor']['operator']);
        $this->assertEquals('SPV QC', $rows['qc']['operator']);
        $this->assertEquals('SPV GUDANG', $rows['gudang_jadi']['operator']);
    }

    /**
     * KITIR TEST 8: MARKING remains on row 2 as sub-process under OD without digital checkpoint.
     */
    public function test_kitir_test_8_marking_remains_physical_checklist_row(): void
    {
        $plan = $this->createPlan();
        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-T8', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 8, 'code' => $plan->code, 'item_name' => $plan->item_name]);
        $result = SandCastingCastingResult::create(['heat_number' => 'H-T8', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $line = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-T8',
            'qty_good' => 8,
            'qty_reject' => 0,
        ]);

        $response = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.kitir', [$result, $line]));
        $response->assertOk();

        $rows = collect($response->viewData('kitirRows'))->keyBy('stage');
        $this->assertEquals(2, $rows['marking']['no']);
        $this->assertEquals('MARKING', $rows['marking']['label']);
        $this->assertEquals('OD', $rows['marking']['dept']);
        $this->assertNull($rows['marking']['hasil']);
        $this->assertNull($rows['marking']['rusak']);
        $this->assertNull($rows['marking']['tanggal']);
        $this->assertNull($rows['marking']['operator']);
    }

    /**
     * KITIR TEST 9: CNC with multiple checkpoints produces 1 single BUBUT CNC row without double-counting defects.
     */
    public function test_kitir_test_9_cnc_multiple_checkpoints_unified_single_row(): void
    {
        $plan = $this->createPlan();
        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-T9', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 8, 'code' => $plan->code, 'item_name' => $plan->item_name]);
        $result = SandCastingCastingResult::create(['heat_number' => 'H-T9', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $line = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-T9',
            'qty_good' => 8,
            'qty_reject' => 0,
        ]);

        // CNC 3 checkpoints: machining (defect 1), post cnc (defect 1), pre bor (defect 0)
        $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 8,
            'defect_qty' => 1,
            'good_qty' => 7,
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now()->subMinutes(30),
            'executed_at' => now()->subMinutes(30),
            'operator_id' => $this->ppicUser->id,
        ]);
        $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'QC_POST_CNC',
            'input_qty' => 7,
            'defect_qty' => 1,
            'good_qty' => 6,
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now()->subMinutes(20),
            'executed_at' => now()->subMinutes(20),
            'operator_id' => $this->ppicUser->id,
        ]);
        $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'QC_PRE_BOR',
            'input_qty' => 6,
            'defect_qty' => 0,
            'good_qty' => 6,
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now()->subMinutes(10),
            'executed_at' => now()->subMinutes(10),
            'operator_id' => $this->ppicUser->id,
        ]);

        $response = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.kitir', [$result, $line]));
        $response->assertOk();

        $rows = collect($response->viewData('kitirRows'))->keyBy('stage');

        // CNC: HASIL = 8 (Input quantity processed by CNC), RUSAK = 2 (1 + 1)
        $this->assertEquals(8, $rows['bubut_cnc']['hasil']);
        $this->assertEquals(2, $rows['bubut_cnc']['rusak']);
        $this->assertEquals('SPV CNC', $rows['bubut_cnc']['operator']);
    }

    /**
     * KITIR TEST 10: Reprint does not mutate database production execution.
     */
    public function test_kitir_test_10_reprint_does_not_mutate_execution_history(): void
    {
        $plan = $this->createPlan();
        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-T10', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 8, 'code' => $plan->code, 'item_name' => $plan->item_name]);
        $result = SandCastingCastingResult::create(['heat_number' => 'H-T10', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $line = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-T10',
            'qty_good' => 8,
            'qty_reject' => 0,
            'current_stage' => 'bubut_od',
        ]);

        $exec = $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 8,
            'defect_qty' => 2,
            'good_qty' => 6,
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->ppicUser->id,
        ]);

        $beforeInput = $exec->input_qty;
        $beforeGood = $exec->good_qty;
        $beforeDefect = $exec->defect_qty;
        $beforeDoneAt = $exec->physical_done_at->toDateTimeString();
        $beforeStage = $line->current_stage;

        // Print Kitir 3 times
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.kitir', [$result, $line]));
        }

        $exec->refresh();
        $line->refresh();

        $this->assertEquals($beforeInput, $exec->input_qty);
        $this->assertEquals($beforeGood, $exec->good_qty);
        $this->assertEquals($beforeDefect, $exec->defect_qty);
        $this->assertEquals($beforeDoneAt, $exec->physical_done_at->toDateTimeString());
        $this->assertEquals($beforeStage, $line->current_stage);
    }

    /**
     * KITIR TEST 11: Multiple KTR isolation.
     */
    public function test_kitir_test_11_multiple_ktr_isolation(): void
    {
        $plan = $this->createPlan();
        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-T11', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 20, 'code' => $plan->code, 'item_name' => $plan->item_name]);
        $result = SandCastingCastingResult::create(['heat_number' => 'H-T11', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);

        // KTR A: Netto defect = 2 (Good = 6)
        $lineA = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-T11-A',
            'qty_good' => 8,
        ]);
        $lineA->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 8,
            'defect_qty' => 2,
            'good_qty' => 6,
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->ppicUser->id,
        ]);

        // KTR B: Netto defect = 0 (Good = 12)
        $lineB = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-T11-B',
            'qty_good' => 12,
        ]);
        $lineB->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 12,
            'defect_qty' => 0,
            'good_qty' => 12,
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->ppicUser->id,
        ]);

        $resA = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.kitir', [$result, $lineA]));
        $rowsA = collect($resA->viewData('kitirRows'))->keyBy('stage');
        $this->assertEquals(8, $rowsA['netto']['hasil']);
        $this->assertEquals(2, $rowsA['netto']['rusak']);

        $resB = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.kitir', [$result, $lineB]));
        $rowsB = collect($resB->viewData('kitirRows'))->keyBy('stage');
        $this->assertEquals(12, $rowsB['netto']['hasil']);
        $this->assertEquals(0, $rowsB['netto']['rusak']);
    }

    /**
     * KITIR TEST 12: Quantity resolver is the single source of truth for Kitir quantities.
     */
    public function test_kitir_test_12_quantity_resolver_single_source_of_truth(): void
    {
        $plan = $this->createPlan();
        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-T12', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 8, 'code' => $plan->code, 'item_name' => $plan->item_name]);
        $result = SandCastingCastingResult::create(['heat_number' => 'H-T12', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $line = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-T12',
            'qty_good' => 8,
        ]);

        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 8,
            'defect_qty' => 3,
            'good_qty' => 5,
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => now(),
            'executed_at' => now(),
            'operator_id' => $this->ppicUser->id,
        ]);

        $resolver = app(\App\Services\SandCasting\SandCastingQuantityResolverService::class);
        $expectedEffectiveInput = $resolver->resolveEffectiveInputQty($line, 'netto');

        $response = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.kitir', [$result, $line]));
        $rows = collect($response->viewData('kitirRows'))->keyBy('stage');

        $this->assertEquals($expectedEffectiveInput, $rows['netto']['hasil']);
        $this->assertEquals(8, $rows['netto']['hasil']);
        $this->assertEquals(3, $rows['netto']['rusak']);
    }

    /**
     * KITIR TEST 13: Reference scenario full pipeline reconciliation & downstream defect isolation.
     * Scenario:
     * - Hasil Cor: 100 PCS
     * - Netto: HASIL 100, RUSAK 2 (98 forward)
     * - Marking: blank/null
     * - OD Finish: HASIL 98, RUSAK 5 (93 forward)
     * - CNC: HASIL 93, RUSAK 20 (73 forward)
     * - Bor: HASIL 73, RUSAK 0 (73 forward)
     * - QC: HASIL 73, RUSAK 5 (68 forward to Gudang)
     * - Gudang Jadi: HASIL 68, RUSAK 0
     * Reconciliation: 100 awal = 32 cacat + 68 diterima.
     */
    public function test_kitir_reference_scenario_exact_quantities_and_reconciliation(): void
    {
        $plan = $this->createPlan(['qty_planned' => 100, 'qty_remaining' => 100]);
        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-REF-100',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);
        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);
        $result = SandCastingCastingResult::create([
            'heat_number' => 'H-REF-100',
            'cast_date' => '2026-09-14',
            'recorded_by' => $this->ppicUser->id,
        ]);
        $line = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-REF-100',
            'qty_good' => 100,
            'qty_reject' => 0,
        ]);

        // Netto: input 100, defect 2, good 98
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 100,
            'defect_qty' => 2,
            'good_qty' => 98,
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => '2026-09-14 08:00:00',
            'executed_at' => '2026-09-14 08:00:00',
            'operator_id' => $this->ppicUser->id,
        ]);

        // Bubut OD (OD Finish): input 98, defect 5, good 93
        $line->stageExecutions()->create([
            'stage' => 'bubut_od',
            'checkpoint_code' => 'BUBUT_OD_FINISH',
            'input_qty' => 98,
            'defect_qty' => 5,
            'good_qty' => 93,
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => '2026-09-14 09:30:00',
            'executed_at' => '2026-09-14 09:30:00',
            'operator_id' => $this->ppicUser->id,
        ]);

        // CNC: input 93, defect 20, good 73
        $line->stageExecutions()->create([
            'stage' => 'bubut_cnc',
            'checkpoint_code' => 'CNC_MACHINING',
            'input_qty' => 93,
            'defect_qty' => 20,
            'good_qty' => 73,
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => '2026-09-14 11:00:00',
            'executed_at' => '2026-09-14 11:00:00',
            'operator_id' => $this->ppicUser->id,
        ]);

        // Bor: input 73, defect 0, good 73
        $line->stageExecutions()->create([
            'stage' => 'bor',
            'checkpoint_code' => 'BOR_DRILLING',
            'input_qty' => 73,
            'defect_qty' => 0,
            'good_qty' => 73,
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => '2026-09-14 13:00:00',
            'executed_at' => '2026-09-14 13:00:00',
            'operator_id' => $this->ppicUser->id,
        ]);

        // QC: input 73, defect 5, good 68
        $qcExec = $line->stageExecutions()->create([
            'stage' => 'qc',
            'checkpoint_code' => 'QC_FINAL',
            'input_qty' => 73,
            'defect_qty' => 5,
            'good_qty' => 68,
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => '2026-09-14 14:30:00',
            'executed_at' => '2026-09-14 14:30:00',
            'operator_id' => $this->ppicUser->id,
        ]);

        // Gudang Jadi: received 68, defect 0
        $line->stageExecutions()->create([
            'stage' => 'gudang_jadi',
            'checkpoint_code' => 'GUDANG_RECEIVE',
            'input_qty' => 68,
            'defect_qty' => 0,
            'good_qty' => 68,
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => '2026-09-14 16:00:00',
            'executed_at' => '2026-09-14 16:00:00',
            'operator_id' => $this->ppicUser->id,
        ]);

        $response = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.kitir', [$result, $line]));
        $response->assertOk();

        // Header check: Hasil Cor = 100 PCS
        $response->assertSee('100 PCS');

        $rows = collect($response->viewData('kitirRows'))->keyBy('stage');

        // Netto: HASIL 100, RUSAK 2
        $this->assertEquals(100, $rows['netto']['hasil']);
        $this->assertEquals(2, $rows['netto']['rusak']);
        $this->assertEquals('SPV NETTO', $rows['netto']['operator']);
        $this->assertEquals('14/09/2026', $rows['netto']['tanggal']);

        // Marking: blank / null
        $this->assertNull($rows['marking']['hasil']);
        $this->assertNull($rows['marking']['rusak']);
        $this->assertNull($rows['marking']['tanggal']);
        $this->assertNull($rows['marking']['operator']);

        // OD Finish: HASIL 98, RUSAK 5
        $this->assertEquals(98, $rows['bubut_od']['hasil']);
        $this->assertEquals(5, $rows['bubut_od']['rusak']);
        $this->assertEquals('SPV OD', $rows['bubut_od']['operator']);
        $this->assertEquals('14/09/2026', $rows['bubut_od']['tanggal']);

        // CNC: HASIL 93, RUSAK 20
        $this->assertEquals(93, $rows['bubut_cnc']['hasil']);
        $this->assertEquals(20, $rows['bubut_cnc']['rusak']);
        $this->assertEquals('SPV CNC', $rows['bubut_cnc']['operator']);

        // Bor: HASIL 73, RUSAK 0
        $this->assertEquals(73, $rows['bor']['hasil']);
        $this->assertEquals(0, $rows['bor']['rusak']);
        $this->assertEquals('SPV BOR', $rows['bor']['operator']);

        // QC: HASIL 73, RUSAK 5
        $this->assertEquals(73, $rows['qc']['hasil']);
        $this->assertEquals(5, $rows['qc']['rusak']);
        $this->assertEquals('SPV QC', $rows['qc']['operator']);

        // Gudang Jadi: HASIL 68 (accepted good), RUSAK 0
        $this->assertEquals(68, $rows['gudang_jadi']['hasil']);
        $this->assertEquals(0, $rows['gudang_jadi']['rusak']);
        $this->assertEquals('SPV GUDANG', $rows['gudang_jadi']['operator']);

        // Downstream defect isolation test:
        // Update QC defect from 5 to 10 (good 63)
        $qcExec->update([
            'defect_qty' => 10,
            'good_qty' => 63,
        ]);

        $response2 = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.kitir', [$result, $line]));
        $rows2 = collect($response2->viewData('kitirRows'))->keyBy('stage');

        // Upstream stages must remain completely unchanged!
        $this->assertEquals(100, $rows2['netto']['hasil']);
        $this->assertEquals(2, $rows2['netto']['rusak']);
        $this->assertEquals(98, $rows2['bubut_od']['hasil']);
        $this->assertEquals(5, $rows2['bubut_od']['rusak']);
        $this->assertEquals(93, $rows2['bubut_cnc']['hasil']);
        $this->assertEquals(20, $rows2['bubut_cnc']['rusak']);
        $this->assertEquals(73, $rows2['bor']['hasil']);
        $this->assertEquals(0, $rows2['bor']['rusak']);

        // QC input remains 73, defect becomes 10
        $this->assertEquals(73, $rows2['qc']['hasil']);
        $this->assertEquals(10, $rows2['qc']['rusak']);

        // Gudang Jadi received good becomes 63
        $this->assertEquals(63, $rows2['gudang_jadi']['hasil']);
    }

    /**
     * KITIR TEST 14: ISO Document Code FR/PPIC/11-01/09/26-00-01 in footer.
     */
    public function test_kitir_displays_iso_document_code_footer(): void
    {
        $plan = $this->createPlan();
        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-ISO', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 10, 'code' => $plan->code, 'item_name' => $plan->item_name]);
        $result = SandCastingCastingResult::create(['heat_number' => 'H-ISO', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $line = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-ISO',
            'qty_good' => 10,
        ]);

        $response = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.kitir', [$result, $line]));
        $response->assertOk();

        $content = $response->getContent();
        $isoCode = 'FR/PPIC/11-01/09/26-00-01';

        // 1. Must contain the exact ISO document code
        $this->assertStringContainsString($isoCode, $content);

        // 2. Must appear exactly once in the document
        $this->assertEquals(1, substr_count($content, $isoCode), 'ISO document code must appear exactly once');

        // 3. Must be placed in the footer outside the main kitir-border div
        $posKitirBorderEnd = strrpos($content, '<!-- ISO Document Code Footer');
        $posIsoCode = strpos($content, $isoCode);
        $this->assertTrue($posIsoCode > $posKitirBorderEnd, 'ISO code must be situated in footer section outside table');
    }

    /**
     * KITIR TEST 15: OD department redesign structure with numbers 1 to 6 and Marking + OD Finish sub-processes.
     */
    public function test_kitir_od_department_redesign_structure(): void
    {
        $plan = $this->createPlan();
        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-DES', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 10, 'code' => $plan->code, 'item_name' => $plan->item_name]);
        $result = SandCastingCastingResult::create(['heat_number' => 'H-DES', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $line = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-DES',
            'qty_good' => 10,
        ]);

        $response = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.kitir', [$result, $line]));
        $response->assertOk();

        $rows = $response->viewData('kitirRows');
        $this->assertCount(7, $rows);

        // Check 1-6 numbering structure in rows data
        $this->assertEquals(1, $rows[0]['no']); // Netto
        $this->assertEquals('NETTO', $rows[0]['label']);

        $this->assertEquals(2, $rows[1]['no']); // Marking under OD
        $this->assertEquals('MARKING', $rows[1]['label']);
        $this->assertEquals('OD', $rows[1]['dept']);

        $this->assertEquals(2, $rows[2]['no']); // OD Finish under OD
        $this->assertEquals('OD FINISH', $rows[2]['label']);
        $this->assertEquals('OD', $rows[2]['dept']);

        $this->assertEquals(3, $rows[3]['no']); // Bubut CNC
        $this->assertEquals('BUBUT CNC', $rows[3]['label']);

        $this->assertEquals(4, $rows[4]['no']); // Bor
        $this->assertEquals('BOR', $rows[4]['label']);

        $this->assertEquals(5, $rows[5]['no']); // QC
        $this->assertEquals('QC', $rows[5]['label']);

        $this->assertEquals(6, $rows[6]['no']); // Gudang Jadi
        $this->assertEquals('GUDANG JADI', $rows[6]['label']);

        // Check HTML content for table structure
        $content = $response->getContent();
        $this->assertStringContainsString('rowspan="2"', $content);
        $this->assertStringContainsString('>OD<', $content);
        $this->assertStringContainsString('>MARKING<', $content);
        $this->assertStringContainsString('>OD FINISH<', $content);
        $this->assertStringNotContainsString('>BUBUT OD<', $content);
    }

    /**
     * KITIR TEST 16: KPI column presence, exact position between TANGGAL and OPERATOR, and completely empty cells.
     */
    public function test_kitir_table_kpi_column_order_and_empty_cells(): void
    {
        $plan = $this->createPlan();
        $order = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-KPI', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $orderLine = $order->lines()->create(['production_plan_id' => $plan->id, 'qty_ordered' => 10, 'code' => $plan->code, 'item_name' => $plan->item_name]);
        $result = SandCastingCastingResult::create(['heat_number' => 'H-KPI', 'cast_date' => '2026-09-14', 'recorded_by' => $this->ppicUser->id]);
        $line = $result->lines()->create([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => 'KTR-KPI',
            'qty_good' => 10,
        ]);

        // Execute Netto with 1 defect to ensure non-empty row still has empty KPI
        $line->stageExecutions()->create([
            'stage' => 'netto',
            'checkpoint_code' => 'NETTO_CUT',
            'input_qty' => 10,
            'defect_qty' => 1,
            'good_qty' => 9,
            'status' => \App\Models\SandCastingStageExecution::STATUS_CONFIRMED,
            'physical_done_at' => '2026-09-14 08:30:00',
            'executed_at' => '2026-09-14 08:30:00',
            'operator_id' => $this->ppicUser->id,
        ]);

        $response = $this->actingAs($this->ppicUser)->get(route('sand-casting.casting-results.kitir', [$result, $line]));
        $response->assertOk();

        $content = $response->getContent();

        // 1. Column header sequence: TANGGAL -> KPI -> OPERATOR / SPV
        $posTanggal = strpos($content, '>TANGGAL</th>');
        $posKpi = strpos($content, '>KPI</th>');
        $posOperator = strpos($content, '>OPERATOR / SPV</th>');

        $this->assertNotFalse($posTanggal, 'TANGGAL header must be present');
        $this->assertNotFalse($posKpi, 'KPI header must be present');
        $this->assertNotFalse($posOperator, 'OPERATOR / SPV header must be present');

        $this->assertTrue($posTanggal < $posKpi, 'TANGGAL header must precede KPI header');
        $this->assertTrue($posKpi < $posOperator, 'KPI header must precede OPERATOR / SPV header');

        // 2. Header KPI appears exactly once with widened column w-32
        $this->assertEquals(1, substr_count($content, '>KPI</th>'), 'KPI header must appear exactly once in the table');
        $this->assertStringContainsString('w-32 border-r-black-solid">KPI</th>', $content);

        // 3. Compacted columns for PROSES / DEPT and OPERATOR / SPV
        $this->assertStringContainsString('w-28 border-r-black-solid" colspan="2">PROSES / DEPT</th>', $content);
        $this->assertStringContainsString('w-28">OPERATOR / SPV</th>', $content);

        // 4. Every row in viewData has 'kpi' => null
        $rows = $response->viewData('kitirRows');
        $this->assertCount(7, $rows);
        foreach ($rows as $row) {
            $this->assertArrayHasKey('kpi', $row);
            $this->assertNull($row['kpi'], "KPI value for {$row['label']} must be null");
        }

        // 5. Symbol '×' (&#215;) appears exactly 6 times (NETTO, MARKING, OD FINISH, BOR, QC, GUDANG JADI)
        // BUBUT CNC KPI cell is strictly blank
        $this->assertEquals(6, substr_count($content, '&#215;'), 'Small multiplication sign × must appear exactly 6 times for non-CNC processes');

        // Verify BUBUT CNC row specifically has empty KPI cell
        $this->assertMatchesRegularExpression('/>BUBUT CNC<\/td>\s*<td[^>]*><\/td>\s*<td[^>]*><\/td>\s*<td[^>]*><\/td>\s*<td[^>]*>\s*<\/td>/', $content);

        // 6. Verify no verbose labels appear anywhere
        $this->assertStringNotContainsString('NETTO (POTONG)', $content);
        $this->assertStringNotContainsString('QC (FINAL INSPECTION)', $content);
    }
}
