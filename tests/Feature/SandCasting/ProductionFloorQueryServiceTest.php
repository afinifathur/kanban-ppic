<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\User;
use App\Services\SandCasting\SandCastingProductionFloorQueryService;
use App\Services\SandCasting\SandCastingStageExecutionService;
use App\Services\SandCasting\TravelerNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductionFloorQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected SandCastingProductionFloorQueryService $queryService;

    protected SandCastingStageExecutionService $executionService;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::firstOrCreate(['name' => 'access_planning']);
        $role = Role::firstOrCreate(['name' => 'ppic']);
        $role->givePermissionTo($permission);

        $this->user = User::factory()->create([
            'name' => 'Operator Sutrisno',
            'email' => 'operator_sutrisno@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->user->assignRole('ppic');

        $this->queryService = new SandCastingProductionFloorQueryService;
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
     * Test 1: Find valid KTR returns complete operational payload
     */
    public function test_find_by_traveler_returns_complete_payload(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto', 'qty_good' => 100]);

        $data = $this->queryService->findByTraveler($line->traveler_number);

        $this->assertNotNull($data);
        $this->assertEquals($line->traveler_number, $data['traveler_number']);
        $this->assertEquals($line->castingResult->heat_number, $data['heat_number']);
        $this->assertEquals('2026-09-20', $data['cast_date']);
        $this->assertEquals('F-01', $data['furnace']);
        $this->assertEquals('1', $data['shift']);
        $this->assertEquals('Sutrisno', $data['operator_name']);
        $this->assertEquals($line->productionPlan->code, $data['production_code']);
        $this->assertEquals($line->productionPlan->item_code, $data['item_code']);
        $this->assertEquals($line->productionPlan->item_name, $data['item_name']);
        $this->assertEquals('PT SINAR METAL', $data['customer']);
        $this->assertEquals(1, $data['line_number']);
        $this->assertEquals(100, $data['qty_cor']);
        $this->assertEquals(2, $data['qty_reject_cor']);
        $this->assertEquals('netto', $data['current_stage']);
        $this->assertEquals(100, $data['current_input_qty']);
        $this->assertEquals('READY', $data['operational_status']);
        $this->assertEquals('bubut_od', $data['next_stage']);
        $this->assertFalse($data['is_urgent']);
        $this->assertIsArray($data['stage_history']);
        $this->assertEmpty($data['stage_history']);
    }

    /**
     * Test 2: Non-existent KTR returns null
     */
    public function test_find_by_traveler_returns_null_when_not_found(): void
    {
        $this->assertNull($this->queryService->findByTraveler('KTR-NON-EXISTENT'));
        $this->assertNull($this->queryService->findByTraveler(''));
    }

    /**
     * Test 3: KTR whitespace and case normalization
     */
    public function test_find_by_traveler_normalizes_case_and_whitespace(): void
    {
        $line = $this->createKtrLine(['current_stage' => 'netto']);

        $input = '   '.strtolower($line->traveler_number).'  ';
        $data = $this->queryService->findByTraveler($input);

        $this->assertNotNull($data);
        $this->assertEquals($line->traveler_number, $data['traveler_number']);
    }

    /**
     * Test 4: Manual Heat fallback returns all KTRs in that Heat
     */
    public function test_find_by_heat_returns_all_ktrs_for_heat(): void
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

        // Execute NETTO on line 3 to advance to bubut_od
        $this->executionService->execute($line3->traveler_number, 'netto', 0, $this->user->id);

        $results = $this->queryService->findByHeat('a214092601');

        $this->assertCount(3, $results);
        $travelers = $results->pluck('traveler_number')->all();
        $this->assertContains($line1->traveler_number, $travelers);
        $this->assertContains($line2->traveler_number, $travelers);
        $this->assertContains($line3->traveler_number, $travelers);

        $line3Data = $results->firstWhere('traveler_number', $line3->traveler_number);
        $this->assertEquals('bubut_od', $line3Data['current_stage']);
        $this->assertEquals(30, $line3Data['current_input_qty']);
    }

    /**
     * Test 5: Heat not found returns empty collection
     */
    public function test_find_by_heat_returns_empty_collection_when_not_found(): void
    {
        $results = $this->queryService->findByHeat('HEAT-NON-EXISTENT');
        $this->assertTrue($results->isEmpty());

        $emptyResults = $this->queryService->findByHeat('');
        $this->assertTrue($emptyResults->isEmpty());
    }

    /**
     * Test 6: Current input quantity continuity across stages
     */
    public function test_current_input_qty_continuity_across_stages(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        // 1. Initial stage (netto): current_input_qty = 100
        $dataNetto = $this->queryService->findByTraveler($line->traveler_number);
        $this->assertEquals(100, $dataNetto['current_input_qty']);
        $this->assertEquals('READY', $dataNetto['operational_status']);
        $this->assertEquals('bubut_od', $dataNetto['next_stage']);

        // Execute NETTO (100 in, 5 defect -> 95 good)
        $this->executionService->execute($line->traveler_number, 'netto', 5, $this->user->id);

        // 2. Stage bubut_od: current_input_qty = 95
        $dataOd = $this->queryService->findByTraveler($line->traveler_number);
        $this->assertEquals('bubut_od', $dataOd['current_stage']);
        $this->assertEquals(95, $dataOd['current_input_qty']);
        $this->assertEquals('READY', $dataOd['operational_status']);
        $this->assertEquals('marking', $dataOd['next_stage']);

        // Execute BUBUT_OD (95 in, 3 defect -> 92 good)
        $this->executionService->execute($line->traveler_number, 'bubut_od', 3, $this->user->id);

        // 3. Stage marking: current_input_qty = 92
        $dataMarking = $this->queryService->findByTraveler($line->traveler_number);
        $this->assertEquals('marking', $dataMarking['current_stage']);
        $this->assertEquals(92, $dataMarking['current_input_qty']);
        $this->assertEquals('READY', $dataMarking['operational_status']);
        $this->assertEquals('bubut_cnc', $dataMarking['next_stage']);
    }

    /**
     * Test 7: Operational Status HALTED when previous stage good_qty = 0
     */
    public function test_operational_status_is_halted_when_input_qty_is_zero(): void
    {
        $line = $this->createKtrLine(['qty_good' => 50, 'current_stage' => 'netto']);

        // Execute NETTO where all 50 units are defect (good_qty = 0)
        $this->executionService->execute($line->traveler_number, 'netto', 50, $this->user->id);

        $data = $this->queryService->findByTraveler($line->traveler_number);

        // current_stage was halted at netto in Phase 2
        $this->assertEquals('netto', $data['current_stage']);
        $this->assertEquals('HALTED', $data['operational_status']);
    }

    /**
     * Test 8: Operational Status COMPLETED when current_stage = completed
     */
    public function test_operational_status_completed_and_next_stage_null(): void
    {
        $line = $this->createKtrLine(['qty_good' => 10, 'current_stage' => 'netto']);

        // Execute full chain to completion
        $this->executionService->execute($line->traveler_number, 'netto', 0, $this->user->id);
        $this->executionService->execute($line->traveler_number, 'bubut_od', 0, $this->user->id);
        $this->executionService->execute($line->traveler_number, 'marking', 0, $this->user->id);
        $this->executionService->execute($line->traveler_number, 'bubut_cnc', 0, $this->user->id);
        $this->executionService->execute($line->traveler_number, 'bor', 0, $this->user->id);
        $this->executionService->execute($line->traveler_number, 'qc', 0, $this->user->id);
        $this->executionService->execute($line->traveler_number, 'gudang_jadi', 0, $this->user->id);

        $data = $this->queryService->findByTraveler($line->traveler_number);

        $this->assertEquals('completed', $data['current_stage']);
        $this->assertEquals(0, $data['current_input_qty']);
        $this->assertEquals('COMPLETED', $data['operational_status']);
        $this->assertNull($data['next_stage']);
    }

    /**
     * Test 9: Historical KTR with current_stage = null has status NO_STAGE
     */
    public function test_historical_ktr_with_null_stage_has_no_stage_status(): void
    {
        $line = $this->createKtrLine(['current_stage' => null]);

        $data = $this->queryService->findByTraveler($line->traveler_number);

        $this->assertNotNull($data);
        $this->assertNull($data['current_stage']);
        $this->assertNull($data['current_input_qty']);
        $this->assertEquals('NO_STAGE', $data['operational_status']);
        $this->assertNull($data['next_stage']);
    }

    /**
     * Test 10: Stage history is chronological and preserves exact stored values
     */
    public function test_stage_history_is_chronological_and_preserves_stored_data(): void
    {
        $line = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);

        $this->executionService->execute($line->traveler_number, 'netto', 5, $this->user->id, 'Netto ok');
        $this->executionService->execute($line->traveler_number, 'bubut_od', 3, $this->user->id, 'OD ok');

        $history = $this->queryService->getStageHistory($line->traveler_number);

        $this->assertCount(2, $history);

        $first = $history->first();
        $this->assertEquals('netto', $first['stage']);
        $this->assertEquals(100, $first['input_qty']);
        $this->assertEquals(5, $first['defect_qty']);
        $this->assertEquals(95, $first['good_qty']);
        $this->assertEquals('Operator Sutrisno', $first['operator_name']);
        $this->assertEquals('Netto ok', $first['notes']);

        $second = $history->last();
        $this->assertEquals('bubut_od', $second['stage']);
        $this->assertEquals(95, $second['input_qty']);
        $this->assertEquals(3, $second['defect_qty']);
        $this->assertEquals(92, $second['good_qty']);
        $this->assertEquals('Operator Sutrisno', $second['operator_name']);
        $this->assertEquals('OD ok', $second['notes']);
    }

    /**
     * Test 11: Data isolation between different KTR travelers
     */
    public function test_stage_history_data_isolation_between_ktrs(): void
    {
        $lineA = $this->createKtrLine(['qty_good' => 100, 'current_stage' => 'netto']);
        $lineB = $this->createKtrLine(['qty_good' => 80, 'current_stage' => 'netto']);

        $this->executionService->execute($lineA->traveler_number, 'netto', 10, $this->user->id);

        $historyA = $this->queryService->getStageHistory($lineA->traveler_number);
        $historyB = $this->queryService->getStageHistory($lineB->traveler_number);

        $this->assertCount(1, $historyA);
        $this->assertCount(0, $historyB);
    }
}
