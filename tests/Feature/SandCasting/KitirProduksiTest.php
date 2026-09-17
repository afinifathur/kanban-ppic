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

        $posNetto = strpos($content, '>NETTO (POTONG)<');
        $posBubutOd = strpos($content, '>BUBUT OD<');
        $posMarking = strpos($content, '>MARKING<');
        $posBubutCnc = strpos($content, '>BUBUT CNC<');
        $posBor = strpos($content, '>BOR<');
        $posQc = strpos($content, '>QC (FINAL INSPECTION)<');
        $posGudang = strpos($content, '>GUDANG JADI<');

        $this->assertNotFalse($posNetto, 'NETTO (POTONG) must be present');
        $this->assertNotFalse($posBubutOd, 'BUBUT OD must be present');
        $this->assertNotFalse($posMarking, 'MARKING must be present');
        $this->assertNotFalse($posBubutCnc, 'BUBUT CNC must be present');
        $this->assertNotFalse($posBor, 'BOR must be present');
        $this->assertNotFalse($posQc, 'QC (FINAL INSPECTION) must be present');
        $this->assertNotFalse($posGudang, 'GUDANG JADI must be present');

        // Verify EXACT strict sequence: NETTO -> BUBUT OD -> MARKING -> BUBUT CNC -> BOR -> QC -> GUDANG JADI
        $this->assertTrue($posNetto < $posBubutOd, 'NETTO must precede BUBUT OD');
        $this->assertTrue($posBubutOd < $posMarking, 'BUBUT OD must precede MARKING (Bubut OD before Marking rule)');
        $this->assertTrue($posMarking < $posBubutCnc, 'MARKING must precede BUBUT CNC');
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
}
