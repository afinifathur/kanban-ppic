<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\User;
use App\Services\SandCasting\SandCastingStageExecutionService;
use App\Services\SandCasting\TravelerNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductionFloorScanControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected SandCastingStageExecutionService $executionService;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::firstOrCreate(['name' => 'access_planning']);
        $role = Role::firstOrCreate(['name' => 'ppic']);
        $role->givePermissionTo($permission);

        $this->user = User::factory()->create([
            'name' => 'Operator Sand Casting',
            'email' => 'operator_sc@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->user->assignRole('ppic');

        $this->executionService = new SandCastingStageExecutionService;
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
     * TEST 1: Lookup valid KTR returns HTTP 200
     */
    public function test_lookup_valid_ktr_returns_200(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        $response = $this->actingAs($this->user)->getJson("/sand-casting/scan/ktr/{$line->traveler_number}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'traveler_number' => $line->traveler_number,
                    'heat_number' => $line->castingResult->heat_number,
                    'current_stage' => 'netto',
                    'current_input_qty' => 100,
                    'operational_status' => 'READY',
                    'next_stage' => 'bubut_od',
                ],
            ]);
    }

    /**
     * TEST 2: Lookup unknown KTR returns HTTP 404
     */
    public function test_lookup_unknown_ktr_returns_404(): void
    {
        $response = $this->actingAs($this->user)->getJson('/sand-casting/scan/ktr/KTR-99999999-9999');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'KTR tidak ditemukan.',
            ]);
    }

    /**
     * TEST 3: Lookup KTR with lowercase and whitespace resolves correctly
     */
    public function test_lookup_ktr_with_lowercase_and_whitespace_resolves(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto']);

        $slug = rawurlencode('  '.strtolower($line->traveler_number).'  ');
        $response = $this->actingAs($this->user)->getJson("/sand-casting/scan/ktr/{$slug}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'traveler_number' => $line->traveler_number,
                ],
            ]);
    }

    /**
     * TEST 4: Lookup Heat with multiple KTRs returns all candidate KTRs
     */
    public function test_lookup_heat_returns_all_candidate_ktrs(): void
    {
        $heatResult = SandCastingCastingResult::create([
            'heat_number' => 'A214092601',
            'cast_date' => '2026-09-20',
            'furnace' => 'F-02',
            'shift' => '2',
            'operator_name' => 'Bambang',
            'recorded_by' => $this->user->id,
        ]);

        $line1 = $this->createKtrLine(['current_stage' => 'netto', 'qty_good' => 77], $heatResult);
        $line2 = $this->createKtrLine(['current_stage' => 'netto', 'qty_good' => 52], $heatResult);
        $line3 = $this->createKtrLine(['current_stage' => 'netto', 'qty_good' => 30], $heatResult);

        $response = $this->actingAs($this->user)->getJson('/sand-casting/scan/heat/A214092601');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $data = $response->json('data');
        $this->assertCount(3, $data);
        $travelers = array_column($data, 'traveler_number');
        $this->assertContains($line1->traveler_number, $travelers);
        $this->assertContains($line2->traveler_number, $travelers);
        $this->assertContains($line3->traveler_number, $travelers);
    }

    /**
     * TEST 5: Lookup unknown Heat returns HTTP 404
     */
    public function test_lookup_unknown_heat_returns_404(): void
    {
        $response = $this->actingAs($this->user)->getJson('/sand-casting/scan/heat/HEAT-NON-EXISTENT');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Data KTR untuk Heat Number tersebut tidak ditemukan.',
            ]);
    }

    /**
     * TEST 6: Valid NETTO execution via endpoint
     */
    public function test_valid_netto_execution_succeeds(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        $response = $this->actingAs($this->user)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 5,
            'notes' => 'Netto potong selesai',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'KTR berhasil diproses.',
                'data' => [
                    'traveler_number' => $line->traveler_number,
                    'stage' => 'netto',
                    'input_qty' => 100,
                    'defect_qty' => 5,
                    'good_qty' => 95,
                    'current_stage' => 'bubut_od',
                    'operational_status' => 'READY',
                    'next_stage' => 'marking',
                ],
            ]);

        $line->refresh();
        $this->assertEquals('bubut_od', $line->current_stage);
    }

    /**
     * TEST 7: Valid downstream execution (bubut-od)
     */
    public function test_valid_downstream_execution_succeeds(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        // Execute NETTO first
        $this->executionService->execute($line->traveler_number, 'netto', 5, $this->user->id);

        // Execute BUBUT-OD via endpoint
        $response = $this->actingAs($this->user)->postJson('/sand-casting/scan/bubut-od/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 3,
            'notes' => 'Bubut OD selesai',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'traveler_number' => $line->traveler_number,
                    'stage' => 'bubut_od',
                    'input_qty' => 95,
                    'defect_qty' => 3,
                    'good_qty' => 92,
                    'current_stage' => 'marking',
                    'operational_status' => 'READY',
                    'next_stage' => 'bubut_cnc',
                ],
            ]);
    }

    /**
     * TEST 8: Execution with defect 0 -> good = input
     */
    public function test_execution_with_zero_defect_produces_equal_good_qty(): void
    {
        $line = $this->createKtrLine(['qty_good' => 80, 'current_stage' => 'netto']);

        $response = $this->actingAs($this->user)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 0,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'input_qty' => 80,
                    'defect_qty' => 0,
                    'good_qty' => 80,
                ],
            ]);
    }

    /**
     * TEST 9: Execution with defect = input -> good_qty = 0 and status HALTED
     */
    public function test_execution_with_all_defect_halts_stage(): void
    {
        $line = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'netto']);

        $response = $this->actingAs($this->user)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 50,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'input_qty' => 50,
                    'defect_qty' => 50,
                    'good_qty' => 0,
                    'current_stage' => 'netto',
                    'operational_status' => 'HALTED',
                ],
            ]);

        $line->refresh();
        $this->assertEquals('netto', $line->current_stage);
    }

    /**
     * TEST 10: Reject defect > input
     */
    public function test_reject_defect_greater_than_input(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        $response = $this->actingAs($this->user)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 105,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Jumlah defect (105) tidak boleh melebihi jumlah input (100).',
            ]);
    }

    /**
     * TEST 11: Reject negative defect
     */
    public function test_reject_negative_defect(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        $response = $this->actingAs($this->user)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => -5,
        ]);

        $response->assertStatus(422);
    }

    /**
     * TEST 12: Stage skip rejection (NETTO KTR called on BUBUT OD endpoint)
     */
    public function test_reject_stage_skip_via_endpoint(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        $response = $this->actingAs($this->user)->postJson('/sand-casting/scan/bubut-od/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 0,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => "KTR {$line->traveler_number} saat ini berada di stage NETTO. Tahap yang valid adalah NETTO, bukan BUBUT OD.",
            ]);
    }

    /**
     * TEST 13: Stage skip rejection (NETTO KTR called on BUBUT CNC endpoint)
     */
    public function test_reject_stage_skip_to_cnc_via_endpoint(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        $response = $this->actingAs($this->user)->postJson('/sand-casting/scan/bubut-cnc/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 0,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => "KTR {$line->traveler_number} saat ini berada di stage NETTO. Tahap yang valid adalah NETTO, bukan BUBUT CNC.",
            ]);
    }

    /**
     * TEST 14: Historical KTR with current_stage = null is rejected
     */
    public function test_reject_historical_null_stage_ktr(): void
    {
        $line = $this->createKtrLine(['current_stage' => null]);

        $response = $this->actingAs($this->user)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 0,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => "KTR {$line->traveler_number} belum memiliki operational stage dan tidak dapat diproses.",
            ]);
    }

    /**
     * TEST 15: Completed KTR execution is rejected
     */
    public function test_reject_execution_on_completed_ktr(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'completed']);

        $response = $this->actingAs($this->user)->postJson('/sand-casting/scan/gudang-jadi/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 0,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => "KTR {$line->traveler_number} sudah selesai diproses (completed) dan tidak dapat dieksekusi lagi.",
            ]);
    }

    /**
     * TEST 16: Security - Client-supplied operator_id is ignored, Auth::id() is used
     */
    public function test_client_operator_id_is_ignored_and_authenticated_user_used(): void
    {
        $otherUser = User::factory()->create();
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        $response = $this->actingAs($this->user)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 2,
            'operator_id' => $otherUser->id, // Malicious or spoofed operator ID
        ]);

        $response->assertStatus(200);

        $execution = $line->stageExecutions()->first();
        $this->assertEquals($this->user->id, $execution->operator_id);
        $this->assertNotEquals($otherUser->id, $execution->operator_id);
    }

    /**
     * TEST 17: Security - Unauthenticated requests are rejected
     */
    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/sand-casting/scan/ktr/KTR-20260920-0001');
        $response->assertStatus(401);

        $execResponse = $this->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => 'KTR-20260920-0001',
            'defect_qty' => 0,
        ]);
        $execResponse->assertStatus(401);
    }

    /**
     * TEST 18: Data integrity - Client cannot manipulate input_qty, good_qty, or current_stage
     */
    public function test_client_cannot_manipulate_calculated_quantities(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        $response = $this->actingAs($this->user)->postJson('/sand-casting/scan/netto/execute', [
            'traveler_number' => $line->traveler_number,
            'defect_qty' => 10,
            'input_qty' => 9999, // Should be ignored (actual is 100)
            'good_qty' => 9999,  // Should be ignored (actual is 90)
            'current_stage' => 'completed', // Should be ignored (actual next is bubut_od)
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'input_qty' => 100,
                    'defect_qty' => 10,
                    'good_qty' => 90,
                    'current_stage' => 'bubut_od',
                ],
            ]);
    }
}
