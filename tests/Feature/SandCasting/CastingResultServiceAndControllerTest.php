<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\User;
use App\Services\SandCasting\SandCastingCastingResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CastingResultServiceAndControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $ppicUser;

    protected User $otherScopeUser;

    protected User $adminUser;

    protected SandCastingCastingResultService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::firstOrCreate(['name' => 'access_planning']);
        $role = Role::firstOrCreate(['name' => 'ppic']);
        $role->givePermissionTo($permission);

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

        $this->service = new SandCastingCastingResultService;
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'code' => 'LH083',
            'title' => 'Rencana SC LH083',
            'item_code' => '4.101',
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-001',
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'weight' => 2.50,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ], $attributes));
    }

    public function test_issued_order_can_create_casting_result_via_service_and_http(): void
    {
        $plan = $this->createPlan();

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0001',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $payload = [
            'heat_number' => 'A213092601',
            'cast_date' => '2026-09-14',
            'furnace' => 'F-01',
            'shift' => '1',
            'operator_name' => 'Sutrisno',
            'notes' => 'Pengecoran lancar',
            'items' => [
                [
                    'sand_casting_casting_order_line_id' => $line->id,
                    'qty_good' => 85,
                    'qty_reject' => 5,
                    'unit_weight_kg' => 2.50,
                    'total_weight_kg' => 212.50,
                    'notes' => '5 reject pinhole',
                ],
            ],
        ];

        $response = $this->actingAs($this->ppicUser)->post(route('sand-casting.casting-results.store'), $payload);

        $result = SandCastingCastingResult::where('heat_number', 'A213092601')->firstOrFail();
        $response->assertRedirect(route('sand-casting.casting-results.show', $result));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('sand_casting_casting_results', [
            'id' => $result->id,
            'heat_number' => 'A213092601',
            'furnace' => 'F-01',
        ]);

        $this->assertDatabaseHas('sand_casting_casting_result_lines', [
            'sand_casting_casting_result_id' => $result->id,
            'sand_casting_casting_order_line_id' => $line->id,
            'production_plan_id' => $plan->id,
            'qty_good' => 85,
            'qty_reject' => 5,
            'total_weight_kg' => 212.50,
        ]);

        // Quantities on ProductionPlan must remain pristine
        $this->assertEquals(100, $plan->fresh()->qty_planned);
        $this->assertEquals(100, $plan->fresh()->qty_remaining);
    }

    public function test_single_heat_can_combine_items_from_multiple_pcor_orders_via_service_and_http(): void
    {
        $plan1 = $this->createPlan(['code' => '268ET001', 'item_code' => '4.101', 'qty_planned' => 100, 'qty_remaining' => 100]);
        $plan2 = $this->createPlan(['code' => '268AB002', 'item_code' => '4.102', 'qty_planned' => 5, 'qty_remaining' => 5]);
        $plan3 = $this->createPlan(['code' => '268L001', 'item_code' => '4.103', 'qty_planned' => 55, 'qty_remaining' => 55]);

        $order1 = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-001', 'scheduled_date' => '2026-09-14', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $line1 = $order1->lines()->create(['production_plan_id' => $plan1->id, 'qty_ordered' => 100, 'code' => '268ET001', 'item_name' => 'Item 1']);

        $order2 = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-002', 'scheduled_date' => '2026-09-15', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $line2 = $order2->lines()->create(['production_plan_id' => $plan2->id, 'qty_ordered' => 5, 'code' => '268AB002', 'item_name' => 'Item 2']);

        $order3 = SandCastingCastingOrder::create(['casting_order_number' => 'PCOR-004', 'scheduled_date' => '2026-09-16', 'status' => 'ISSUED', 'created_by' => $this->ppicUser->id]);
        $line3 = $order3->lines()->create(['production_plan_id' => $plan3->id, 'qty_ordered' => 55, 'code' => '268L001', 'item_name' => 'Item 3']);

        $payload = [
            'heat_number' => 'A214092601',
            'cast_date' => '2026-09-14',
            'furnace' => 'F-02',
            'shift' => '1',
            'operator_name' => 'Sutrisno',
            'items' => [
                ['sand_casting_casting_order_line_id' => $line1->id, 'qty_good' => 100, 'qty_reject' => 0],
                ['sand_casting_casting_order_line_id' => $line2->id, 'qty_good' => 5, 'qty_reject' => 0],
                ['sand_casting_casting_order_line_id' => $line3->id, 'qty_good' => 55, 'qty_reject' => 0],
            ],
        ];

        $response = $this->actingAs($this->ppicUser)->post(route('sand-casting.casting-results.store'), $payload);
        $response->assertRedirect();

        $result = SandCastingCastingResult::where('heat_number', 'A214092601')->firstOrFail();
        $this->assertEquals(3, $result->lines()->count());
        $this->assertEquals(160, $result->total_qty_good);

        // All 3 separate PCORs discover this Heat
        $this->assertTrue($order1->results()->get()->contains('id', $result->id));
        $this->assertTrue($order2->results()->get()->contains('id', $result->id));
        $this->assertTrue($order3->results()->get()->contains('id', $result->id));
    }

    public function test_draft_order_is_rejected(): void
    {
        $plan = $this->createPlan();

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0002',
            'scheduled_date' => '2026-09-14',
            'status' => 'DRAFT',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Perintah Cor PCOR-20260914-0002 harus berstatus ISSUED sebelum Hasil Cor dapat dicatat.');

        $this->service->recordResult([
            'heat_number' => 'A213092601',
            'cast_date' => '2026-09-14',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 50,
            ],
        ], $this->ppicUser->id);
    }

    public function test_cancelled_order_is_rejected(): void
    {
        $plan = $this->createPlan();

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0003',
            'scheduled_date' => '2026-09-14',
            'status' => 'CANCELLED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tidak dapat mencatat Hasil Cor pada Perintah Cor yang sudah dibatalkan (PCOR-20260914-0003).');

        $this->service->recordResult([
            'heat_number' => 'A213092601',
            'cast_date' => '2026-09-14',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 50,
            ],
        ], $this->ppicUser->id);
    }

    public function test_completed_order_can_receive_additional_outcomes_if_needed(): void
    {
        $plan = $this->createPlan();

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0004',
            'scheduled_date' => '2026-09-14',
            'status' => 'COMPLETED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $result = $this->service->recordResult([
            'heat_number' => 'A213092601',
            'cast_date' => '2026-09-14',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 100,
            ],
        ], $this->ppicUser->id);

        $this->assertNotNull($result->id);
        $this->assertEquals(100, $result->total_qty_good);
    }

    public function test_lost_wax_plan_is_strictly_rejected(): void
    {
        $lwPlan = ProductionPlan::create([
            'code' => 'LW001',
            'title' => 'Rencana LW',
            'item_code' => '4.101',
            'item_name' => 'FLANGE BESI JIS 10K 2"',
            'po_number' => 'PO-001',
            'line_number' => 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0005',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $lwPlan->id,
            'qty_ordered' => 100,
            'code' => $lwPlan->code,
            'item_name' => $lwPlan->item_name,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Item LW001 bukan bagian dari domain Sand Casting.');

        $this->service->recordResult([
            'heat_number' => 'A213092601',
            'cast_date' => '2026-09-14',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 50,
            ],
        ], $this->ppicUser->id);
    }

    public function test_invalid_order_line_id_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Baris Perintah Cor ID 999999 tidak valid atau tidak ditemukan.');

        $this->service->recordResult([
            'heat_number' => 'A213092601',
            'cast_date' => '2026-09-14',
        ], [
            [
                'sand_casting_casting_order_line_id' => 999999,
                'qty_good' => 50,
            ],
        ], $this->ppicUser->id);
    }

    public function test_casting_result_allows_exact_quota_and_over_casting(): void
    {
        // CASE A: PCOR remaining = 3, qty_good = 3 => PASS
        $planA = $this->createPlan(['qty_planned' => 3, 'qty_remaining' => 3]);
        $orderA = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-008A',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);
        $lineA = $orderA->lines()->create([
            'production_plan_id' => $planA->id,
            'qty_ordered' => 3,
            'code' => 'LHA01',
            'item_name' => 'FLANGE CASE A',
        ]);
        $resA = $this->service->recordResult([
            'heat_number' => 'A21309260A',
            'cast_date' => '2026-09-14',
        ], [
            [
                'sand_casting_casting_order_line_id' => $lineA->id,
                'qty_good' => 3,
            ],
        ], $this->ppicUser->id);
        $this->assertNotNull($resA);
        $this->assertEquals(3, $lineA->fresh()->qty_cast_good);
        $this->assertEquals(0, $lineA->fresh()->qty_remaining_to_cast);

        // CASE B: PCOR remaining = 3, qty_good = 4 => PASS (Over-casting allowed)
        $planB = $this->createPlan(['qty_planned' => 3, 'qty_remaining' => 3]);
        $orderB = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-008B',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);
        $lineB = $orderB->lines()->create([
            'production_plan_id' => $planB->id,
            'qty_ordered' => 3,
            'code' => 'LHB01',
            'item_name' => 'FLANGE CASE B',
        ]);
        $resB = $this->service->recordResult([
            'heat_number' => 'A21309260B',
            'cast_date' => '2026-09-14',
        ], [
            [
                'sand_casting_casting_order_line_id' => $lineB->id,
                'qty_good' => 4,
            ],
        ], $this->ppicUser->id);
        $this->assertNotNull($resB);
        $this->assertEquals(4, $lineB->fresh()->qty_cast_good);
        $this->assertEquals(0, $lineB->fresh()->qty_remaining_to_cast);

        // CASE C: PCOR remaining = 3, qty_good = 10 => PASS (Buffer / large overage allowed)
        $planC = $this->createPlan(['qty_planned' => 3, 'qty_remaining' => 3]);
        $orderC = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-008C',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);
        $lineC = $orderC->lines()->create([
            'production_plan_id' => $planC->id,
            'qty_ordered' => 3,
            'code' => 'LHC01',
            'item_name' => 'FLANGE CASE C',
        ]);
        $resC = $this->service->recordResult([
            'heat_number' => 'A21309260C',
            'cast_date' => '2026-09-14',
        ], [
            [
                'sand_casting_casting_order_line_id' => $lineC->id,
                'qty_good' => 10,
            ],
        ], $this->ppicUser->id);
        $this->assertNotNull($resC);
        $this->assertEquals(10, $lineC->fresh()->qty_cast_good);
        $this->assertEquals(0, $lineC->fresh()->qty_remaining_to_cast);

        // CASE D: qty_good negative => FAIL
        try {
            $this->service->recordResult([
                'heat_number' => 'A21309260D',
                'cast_date' => '2026-09-14',
            ], [
                [
                    'sand_casting_casting_order_line_id' => $lineC->id,
                    'qty_good' => -1,
                ],
            ], $this->ppicUser->id);
            $this->fail('Expected InvalidArgumentException was not thrown for negative qty_good.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak boleh negatif', $e->getMessage());
        }

        // CASE E: qty_reject negative => FAIL
        try {
            $this->service->recordResult([
                'heat_number' => 'A21309260E',
                'cast_date' => '2026-09-14',
            ], [
                [
                    'sand_casting_casting_order_line_id' => $lineC->id,
                    'qty_good' => 5,
                    'qty_reject' => -2,
                ],
            ], $this->ppicUser->id);
            $this->fail('Expected InvalidArgumentException was not thrown for negative qty_reject.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak boleh negatif', $e->getMessage());
        }
    }

    public function test_partial_casting_accumulates_accurately(): void
    {
        $plan = $this->createPlan(['qty_planned' => 550, 'qty_remaining' => 550]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0009',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 550,
            'code' => 'LH083',
            'item_name' => 'FLANGE BESI JIS 10K 2"',
        ]);

        // Heat 1: 300 pcs
        $res1 = $this->service->recordResult([
            'heat_number' => 'A213092601',
            'cast_date' => '2026-09-14',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 300,
                'qty_reject' => 10,
            ],
        ], $this->ppicUser->id);

        $this->assertEquals(300, $line->fresh()->qty_cast_good);
        $this->assertEquals(10, $line->fresh()->qty_cast_reject);
        $this->assertEquals(250, $line->fresh()->qty_remaining_to_cast);

        // Heat 2: 250 pcs
        $res2 = $this->service->recordResult([
            'heat_number' => 'A213092602',
            'cast_date' => '2026-09-14',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 250,
                'qty_reject' => 5,
            ],
        ], $this->ppicUser->id);

        $this->assertEquals(550, $line->fresh()->qty_cast_good);
        $this->assertEquals(15, $line->fresh()->qty_cast_reject);
        $this->assertEquals(0, $line->fresh()->qty_remaining_to_cast);

        $this->assertNotEquals(
            $res1->lines->first()->traveler_number,
            $res2->lines->first()->traveler_number
        );
    }

    public function test_transaction_rolls_back_if_any_line_fails(): void
    {
        $plan1 = $this->createPlan(['code' => 'LH083', 'item_code' => '4.101', 'qty_planned' => 100, 'qty_remaining' => 100]);
        $plan2 = $this->createPlan(['code' => 'LH084', 'item_code' => '4.102', 'qty_planned' => 50, 'qty_remaining' => 50]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0010',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line1 = $order->lines()->create([
            'production_plan_id' => $plan1->id,
            'qty_ordered' => 100,
            'code' => 'LH083',
            'item_name' => 'Item 1',
        ]);

        $line2 = $order->lines()->create([
            'production_plan_id' => $plan2->id,
            'qty_ordered' => 50,
            'code' => 'LH084',
            'item_name' => 'Item 2',
        ]);

        try {
            $this->service->recordResult([
                'heat_number' => 'A213092601',
                'cast_date' => '2026-09-14',
            ], [
                [
                    'sand_casting_casting_order_line_id' => $line1->id,
                    'qty_good' => 50,
                ],
                [
                    'sand_casting_casting_order_line_id' => $line2->id,
                    'qty_good' => -10, // Invalid negative quantity!
                ],
            ], $this->ppicUser->id);

            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak boleh negatif', $e->getMessage());
        }

        // Entire transaction must be rolled back: no results, no result lines
        $this->assertEquals(0, SandCastingCastingResult::count());
        $this->assertEquals(0, $line1->fresh()->qty_cast_good);
        $this->assertEquals(0, $line2->fresh()->qty_cast_good);
    }

    public function test_unauthorized_user_is_forbidden(): void
    {
        $plan = $this->createPlan(['product_scope' => 'FLANGE_BESI']);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0011',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        // User with scope FITTING_STAINLESS cannot submit FLANGE_BESI line
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unauthorized: Item LH083 berada di luar product scope.');

        $this->service->recordResult([
            'heat_number' => 'A213092601',
            'cast_date' => '2026-09-14',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 50,
            ],
        ], $this->otherScopeUser->id);
    }

    public function test_duplicate_order_line_in_same_heat_is_rejected(): void
    {
        $plan = $this->createPlan();

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20260914-0012',
            'scheduled_date' => '2026-09-14',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak boleh dimasukkan lebih dari satu kali');

        $this->service->recordResult([
            'heat_number' => 'A213092601',
            'cast_date' => '2026-09-14',
        ], [
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 20,
            ],
            [
                'sand_casting_casting_order_line_id' => $line->id,
                'qty_good' => 30,
            ],
        ], $this->ppicUser->id);
    }

    public function test_multi_line_heat_creates_one_heat_with_multiple_result_lines_and_distinct_travelers(): void
    {
        $plan1 = $this->createPlan(['code' => '268ET001', 'item_name' => 'Item 268ET001', 'title' => 'Rencana 268ET001']);
        $plan2 = $this->createPlan(['code' => '268AB002', 'item_name' => 'Item 268AB002', 'title' => 'Rencana 268AB002']);

        $order1 = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-001',
            'scheduled_date' => '2026-09-15',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $order2 = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-002',
            'scheduled_date' => '2026-09-15',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line1 = $order1->lines()->create([
            'production_plan_id' => $plan1->id,
            'qty_ordered' => 300,
            'code' => '268ET001',
            'item_name' => 'Item 268ET001',
        ]);

        $line2 = $order2->lines()->create([
            'production_plan_id' => $plan2->id,
            'qty_ordered' => 100,
            'code' => '268AB002',
            'item_name' => 'Item 268AB002',
        ]);

        $payload = [
            'heat_number' => 'A214092601',
            'cast_date' => '2026-09-15',
            'furnace' => 'F-01',
            'shift' => '1',
            'items' => [
                [
                    'sand_casting_casting_order_line_id' => $line1->id,
                    'qty_good' => 100,
                    'qty_reject' => 5,
                ],
                [
                    'sand_casting_casting_order_line_id' => $line2->id,
                    'qty_good' => 50,
                    'qty_reject' => 2,
                ],
            ],
        ];

        $response = $this->actingAs($this->ppicUser)->post(route('sand-casting.casting-results.store'), $payload);

        // Exactly 1 Heat created
        $this->assertEquals(1, SandCastingCastingResult::where('heat_number', 'A214092601')->count());
        $heat = SandCastingCastingResult::where('heat_number', 'A214092601')->firstOrFail();

        $response->assertRedirect(route('sand-casting.casting-results.show', $heat));

        // Exactly 2 ResultLines belong to this one Heat
        $this->assertCount(2, $heat->lines);

        $resultLine1 = $heat->lines->where('sand_casting_casting_order_line_id', $line1->id)->first();
        $resultLine2 = $heat->lines->where('sand_casting_casting_order_line_id', $line2->id)->first();

        $this->assertNotNull($resultLine1);
        $this->assertNotNull($resultLine2);

        $this->assertEquals(100, $resultLine1->qty_good);
        $this->assertEquals(5, $resultLine1->qty_reject);
        $this->assertEquals('Rencana 268ET001', $resultLine1->notes);

        $this->assertEquals(50, $resultLine2->qty_good);
        $this->assertEquals(2, $resultLine2->qty_reject);
        $this->assertEquals('Rencana 268AB002', $resultLine2->notes);

        // Unique Travelers
        $this->assertNotEmpty($resultLine1->traveler_number);
        $this->assertNotEmpty($resultLine2->traveler_number);
        $this->assertNotEquals($resultLine1->traveler_number, $resultLine2->traveler_number);
    }

    public function test_multi_line_heat_with_over_casting_maintains_accurate_allocations(): void
    {
        // CASE F: Multi-line / multiple PCOR order lines in one heat with overage
        $plan1 = $this->createPlan(['code' => '268ET010', 'item_name' => 'Item 10', 'qty_planned' => 50, 'qty_remaining' => 50]);
        $plan2 = $this->createPlan(['code' => '268AB020', 'item_name' => 'Item 20', 'qty_planned' => 30, 'qty_remaining' => 30]);

        $order1 = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-MULTI-01',
            'scheduled_date' => '2026-09-15',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $order2 = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-MULTI-02',
            'scheduled_date' => '2026-09-15',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        $line1 = $order1->lines()->create([
            'production_plan_id' => $plan1->id,
            'qty_ordered' => 50,
            'code' => '268ET010',
            'item_name' => 'Item 10',
        ]);

        $line2 = $order2->lines()->create([
            'production_plan_id' => $plan2->id,
            'qty_ordered' => 30,
            'code' => '268AB020',
            'item_name' => 'Item 20',
        ]);

        // Line 1 is over-cast (55 > 50), Line 2 is exact (30 == 30)
        $payload = [
            'heat_number' => 'A214092699',
            'cast_date' => '2026-09-15',
            'furnace' => 'F-02',
            'shift' => '2',
            'items' => [
                [
                    'sand_casting_casting_order_line_id' => $line1->id,
                    'qty_good' => 55,
                    'qty_reject' => 2,
                ],
                [
                    'sand_casting_casting_order_line_id' => $line2->id,
                    'qty_good' => 30,
                    'qty_reject' => 1,
                ],
            ],
        ];

        $response = $this->actingAs($this->ppicUser)->post(route('sand-casting.casting-results.store'), $payload);
        $heat = SandCastingCastingResult::where('heat_number', 'A214092699')->firstOrFail();
        $response->assertRedirect(route('sand-casting.casting-results.show', $heat));

        $this->assertEquals(55, $line1->fresh()->qty_cast_good);
        $this->assertEquals(0, $line1->fresh()->qty_remaining_to_cast);

        $this->assertEquals(30, $line2->fresh()->qty_cast_good);
        $this->assertEquals(0, $line2->fresh()->qty_remaining_to_cast);

        $this->assertEquals(85, $heat->total_qty_good);
        $this->assertEquals(3, $heat->total_qty_reject);
    }
}
