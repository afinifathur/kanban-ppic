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

class ProductionFloorScannerUiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::firstOrCreate(['name' => 'access_planning']);
        $role = Role::firstOrCreate(['name' => 'ppic']);
        $role->givePermissionTo($permission);

        $this->user = User::factory()->create([
            'name' => 'Operator Netto',
            'email' => 'operator_netto@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->user->assignRole('ppic');
    }

    protected function createKtrLine(array $attributes = [], ?SandCastingCastingResult $existingResult = null): SandCastingCastingResultLine
    {
        $plan = ProductionPlan::create([
            'code' => '268ET'.rand(100, 999),
            'title' => 'Rencana SC '.rand(100, 999),
            'item_code' => '4.'.rand(100, 999),
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-'.rand(100, 999),
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'customer' => 'PT SINAR METAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260920-'.rand(1000, 9999),
            'scheduled_date' => '2026-09-20',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '2"',
            'aisi' => 'FC250',
        ]);

        $result = $existingResult ?? SandCastingCastingResult::create([
            'heat_number' => 'A21409200'.rand(1, 9),
            'cast_date' => '2026-09-20',
            'furnace' => 'F-01',
            'shift' => '1',
            'operator_name' => 'Sutrisno',
            'recorded_by' => $this->user->id,
        ]);

        $travelerNumber = TravelerNumberGenerator::generateNext('2026-09-20');

        return $result->lines()->create(array_merge([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $travelerNumber,
            'qty_good' => 100,
            'qty_reject' => 2,
            'unit_weight_kg' => 3.25,
            'total_weight_kg' => 325.00,
            'print_count' => 1,
            'printed_at' => now(),
            'last_printed_by' => $this->user->id,
            'current_stage' => 'netto',
        ], $attributes));
    }

    /**
     * TEST 1: Unauthenticated access to /sand-casting/scan/netto redirects to login
     */
    public function test_unauthenticated_access_redirects_to_login(): void
    {
        $response = $this->get('/sand-casting/scan/netto');
        $response->assertRedirect('/login');
    }

    /**
     * TEST 2: Authenticated user can open /sand-casting/scan/netto
     */
    public function test_authenticated_user_can_open_netto_scanner_page(): void
    {
        $response = $this->actingAs($this->user)->get('/sand-casting/scan/netto');

        $response->assertStatus(200);
        $response->assertSee('SCANNER PRODUKSI');
        $response->assertSee('NETTO');
        $response->assertSee('BUKA KAMERA SCANNER');
        $response->assertSee('BARCODE TIDAK TERBACA / CARI HEAT');
        $response->assertDontSee('<select id="stageSelect"', false);
    }

    /**
     * TEST 3: Page contains required context and backend URLs
     */
    public function test_scanner_page_contains_stage_context_and_urls(): void
    {
        $response = $this->actingAs($this->user)->get('/sand-casting/scan/netto');

        $response->assertStatus(200);
        $response->assertSee('sand-casting/scan/netto/execute');
        $response->assertSee('sand-casting/scan/ktr');
        $response->assertSee('sand-casting/scan/heat');
    }

    /**
     * TEST 4: End-to-end scanner flow: lookup -> execute NETTO physical done -> WAITING_DEFECT
     */
    public function test_end_to_end_scanner_lookup_and_execution_flow(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        // Step 1: Lookup KTR
        $lookupRes = $this->actingAs($this->user)->getJson("/sand-casting/scan/ktr/{$line->traveler_number}");
        $lookupRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'traveler_number' => $line->traveler_number,
                    'current_stage' => 'netto',
                    'active_checkpoint' => 'NETTO_CUT',
                    'current_input_qty' => 100,
                    'operational_status' => 'READY',
                ],
            ]);

        // Step 2: Execute NETTO physical done
        $execRes = $this->actingAs($this->user)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line->traveler_number,
            'notes' => 'Netto pilot test',
        ]);

        $execRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'traveler_number' => $line->traveler_number,
                    'stage' => 'netto',
                    'checkpoint_code' => 'NETTO_CUT',
                    'input_qty' => 100,
                    'defect_qty' => 0,
                    'good_qty' => 100,
                    'status' => 'WAITING_DEFECT',
                    'current_stage' => 'netto',
                    'operational_status' => 'WAITING_DEFECT',
                ],
            ]);

        // Step 3: Verify subsequent lookup shows fresh status WAITING_DEFECT
        $subsequentLookup = $this->actingAs($this->user)->getJson("/sand-casting/scan/ktr/{$line->traveler_number}");
        $subsequentLookup->assertStatus(200)
            ->assertJson([
                'data' => [
                    'current_stage' => 'netto',
                    'current_input_qty' => 100,
                    'operational_status' => 'WAITING_DEFECT',
                ],
            ]);
    }

    /**
     * TEST 6: Heat fallback lookup delivers all candidates for operator selection
     */
    public function test_heat_fallback_returns_all_candidates_for_selection(): void
    {
        $heatResult = SandCastingCastingResult::create([
            'heat_number' => 'A214092999',
            'cast_date' => '2026-09-20',
            'furnace' => 'F-03',
            'shift' => '1',
            'operator_name' => 'Sutrisno',
            'recorded_by' => $this->user->id,
        ]);

        $line1 = $this->createKtrLine(['qty_good' => 60, 'current_stage' => 'netto'], $heatResult);
        $line2 = $this->createKtrLine(['qty_good' => 40, 'current_stage' => 'netto'], $heatResult);

        $response = $this->actingAs($this->user)->getJson('/sand-casting/scan/heat/A214092999');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertEquals($line1->traveler_number, $data[0]['traveler_number']);
        $this->assertEquals($line2->traveler_number, $data[1]['traveler_number']);
    }
}
