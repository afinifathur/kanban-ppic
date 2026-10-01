<?php

namespace Tests\Feature\SandCasting;

use App\Exceptions\DuplicateCastingResultException;
use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use App\Services\SandCasting\SandCastingCastingResultService;
use App\Services\SandCasting\SandCastingStageExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CastingResultCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $ppicUser;

    protected User $adminUser;

    protected User $spvUser;

    protected User $otherScopeUser;

    protected SandCastingCastingResultService $resultService;

    protected SandCastingStageExecutionService $stageService;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::firstOrCreate(['name' => 'access_planning']);
        $rolePpic = Role::firstOrCreate(['name' => 'ppic']);
        $rolePpic->givePermissionTo($permission);

        $roleAdmin = Role::firstOrCreate(['name' => 'admin']);
        $roleAdmin->givePermissionTo($permission);

        $roleSpv = Role::firstOrCreate(['name' => 'spv']);

        $this->ppicUser = User::factory()->create([
            'email' => 'ppic_flange@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->ppicUser->assignRole('ppic');

        $this->adminUser = User::factory()->create([
            'email' => 'admin@peroniks.com',
            'product_scope' => null,
        ]);
        $this->adminUser->assignRole('admin');

        $this->spvUser = User::factory()->create([
            'email' => 'spv_netto@peroniks.com',
            'assigned_stage' => 'netto',
        ]);
        $this->spvUser->assignRole('spv');

        $this->otherScopeUser = User::factory()->create([
            'email' => 'ppic_fitting@peroniks.com',
            'product_scope' => 'FITTING_STAINLESS',
        ]);
        $this->otherScopeUser->assignRole('ppic');

        $this->resultService = new SandCastingCastingResultService;
        $this->stageService = new SandCastingStageExecutionService;
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

    protected function createOrder(ProductionPlan $plan, array $orderAttributes = []): SandCastingCastingOrder
    {
        $order = SandCastingCastingOrder::create(array_merge([
            'casting_order_number' => 'PCOR-'.date('Ymd').'-'.str_pad((string) rand(1, 9999), 4, '0', STR_PAD_LEFT),
            'scheduled_date' => date('Y-m-d'),
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ], $orderAttributes));

        $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'size' => $plan->size ?? '2"',
            'aisi' => $plan->aisi ?? 'FC20',
        ]);

        return $order;
    }

    protected function createResult(ProductionPlan $plan, array $headerOverrides = [], array $lineOverrides = []): SandCastingCastingResult
    {
        $order = $this->createOrder($plan);
        $orderLine = $order->lines->first();

        $headerData = array_merge([
            'heat_number' => 'A230092614',
            'cast_date' => '2026-10-30',
            'furnace' => '2',
            'shift' => '1',
            'operator_name' => 'Operator X',
            'notes' => 'Catatan awal',
        ], $headerOverrides);

        $linesData = [
            array_merge([
                'sand_casting_casting_order_line_id' => $orderLine->id,
                'qty_good' => 13,
                'qty_reject' => 0,
                'unit_weight_kg' => 6.48,
                'total_weight_kg' => 84.24,
                'notes' => 'Line note awal',
            ], $lineOverrides),
        ];

        return $this->resultService->recordResult($headerData, $linesData, $this->ppicUser->id);
    }

    // =========================================================================
    // 1. CAST DATE & KTR IMMUTABILITY (UAT ACTUAL CASE)
    // =========================================================================

    public function test_edit_cast_date_before_execution_success_and_preserves_ktr(): void
    {
        $plan = $this->createPlan(['code' => 'S797']);
        $result = $this->createResult($plan, [
            'heat_number' => 'A230092614',
            'cast_date' => '2026-10-30',
        ], [
            'qty_good' => 13,
        ]);

        $line = $result->lines->first();
        $originalKtr = $line->traveler_number;
        $this->assertStringStartsWith('KTR-20261030-', $originalKtr);

        // Correct cast_date to 2026-09-30
        $updated = $this->resultService->correctResult(
            $result,
            [
                'heat_number' => 'A230092614',
                'cast_date' => '2026-09-30',
                'furnace' => '2',
                'shift' => '1',
                'operator_name' => 'Operator X',
                'notes' => 'Koreksi tanggal UAT',
                'reason' => 'User salah input bulan 10 menjadi bulan 9',
            ],
            [
                [
                    'id' => $line->id,
                    'sand_casting_casting_order_line_id' => $line->sand_casting_casting_order_line_id,
                    'qty_good' => 13,
                    'qty_reject' => 0,
                    'unit_weight_kg' => 6.48,
                    'notes' => 'Line note',
                ],
            ],
            $this->ppicUser->id
        );

        $this->assertEquals('2026-09-30', $updated->cast_date->format('Y-m-d'));

        // CRITICAL: KTR traveler_number MUST remain unchanged!
        $freshLine = $line->fresh();
        $this->assertEquals($originalKtr, $freshLine->traveler_number);

        // Verify audit trail recorded
        $this->assertDatabaseHas('sand_casting_casting_result_corrections', [
            'sand_casting_casting_result_id' => $result->id,
            'field_name' => 'cast_date',
            'old_value' => '2026-10-30',
            'new_value' => '2026-09-30',
            'reason' => 'User salah input bulan 10 menjadi bulan 9',
            'corrected_by' => $this->ppicUser->id,
        ]);
    }

    public function test_edit_cast_date_after_execution_controlled_correction_with_audit(): void
    {
        $plan = $this->createPlan(['code' => 'S797']);
        $result = $this->createResult($plan);
        $line = $result->lines->first();
        $originalKtr = $line->traveler_number;

        // Perform physical execution at NETTO
        $this->stageService->markPhysicalDone($line->traveler_number, 'netto', $this->ppicUser->id);

        $this->assertTrue($line->fresh()->hasPhysicalExecution());

        // Correct cast_date post-gate with reason
        $updated = $this->resultService->correctResult(
            $result,
            [
                'heat_number' => $result->heat_number,
                'cast_date' => '2026-09-30',
                'furnace' => '2',
                'shift' => '1',
                'operator_name' => 'Operator X',
                'notes' => 'Koreksi tanggal post-gate',
                'reason' => 'Koreksi tanggal cor pelaporan',
            ],
            [
                [
                    'id' => $line->id,
                    'sand_casting_casting_order_line_id' => $line->sand_casting_casting_order_line_id,
                    'qty_good' => 13,
                    'qty_reject' => 0,
                    'unit_weight_kg' => 6.48,
                ],
            ],
            $this->ppicUser->id
        );

        $this->assertEquals('2026-09-30', $updated->cast_date->format('Y-m-d'));
        $this->assertEquals($originalKtr, $line->fresh()->traveler_number);

        // Stage execution remains unmodified
        $exec = $line->fresh()->stageExecutions->first();
        $this->assertEquals(13, $exec->input_qty);
        $this->assertEquals('WAITING_DEFECT', $exec->status);
    }

    // =========================================================================
    // 2. PRODUCTION CODE / ITEM EDITABILITY (PRE VS POST-GATE)
    // =========================================================================

    public function test_edit_production_code_before_execution_success(): void
    {
        $plan1 = $this->createPlan(['code' => 'S797', 'item_name' => 'Flange 2 Inch']);
        $plan2 = $this->createPlan(['code' => 'S798', 'item_name' => 'Flange 3 Inch']);

        $result = $this->createResult($plan1);
        $line = $result->lines->first();

        $order2 = $this->createOrder($plan2);
        $orderLine2 = $order2->lines->first();

        // Swap to Plan 2
        $updated = $this->resultService->correctResult(
            $result,
            [
                'heat_number' => $result->heat_number,
                'cast_date' => $result->cast_date->format('Y-m-d'),
                'furnace' => $result->furnace,
            ],
            [
                [
                    'id' => $line->id,
                    'sand_casting_casting_order_line_id' => $orderLine2->id,
                    'qty_good' => 13,
                    'qty_reject' => 0,
                    'unit_weight_kg' => 6.48,
                ],
            ],
            $this->ppicUser->id
        );

        $freshLine = $line->fresh();
        $this->assertEquals($plan2->id, $freshLine->production_plan_id);
        $this->assertEquals($orderLine2->id, $freshLine->sand_casting_casting_order_line_id);

        $this->assertDatabaseHas('sand_casting_casting_result_corrections', [
            'sand_casting_casting_result_line_id' => $line->id,
            'field_name' => 'production_plan_id',
            'old_value' => (string) $plan1->id,
            'new_value' => (string) $plan2->id,
        ]);
    }

    public function test_edit_production_code_after_execution_rejected(): void
    {
        $plan1 = $this->createPlan(['code' => 'S797']);
        $plan2 = $this->createPlan(['code' => 'S798']);

        $result = $this->createResult($plan1);
        $line = $result->lines->first();

        // Physical execution NETTO
        $this->stageService->markPhysicalDone($line->traveler_number, 'netto', $this->ppicUser->id);

        $order2 = $this->createOrder($plan2);
        $orderLine2 = $order2->lines->first();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Item/PCOR pada KTR '.$line->traveler_number.' terkunci');

        $this->resultService->correctResult(
            $result,
            [
                'heat_number' => $result->heat_number,
                'cast_date' => $result->cast_date->format('Y-m-d'),
                'reason' => 'Percobaan koreksi item post-gate',
            ],
            [
                [
                    'id' => $line->id,
                    'sand_casting_casting_order_line_id' => $orderLine2->id,
                    'qty_good' => 13,
                ],
            ],
            $this->ppicUser->id
        );
    }

    // =========================================================================
    // 3. QTY GOOD & REJECT (PRE VS POST-GATE)
    // =========================================================================

    public function test_edit_qty_good_before_execution_success(): void
    {
        $plan = $this->createPlan(['code' => 'S797']);
        $result = $this->createResult($plan, [], ['qty_good' => 13, 'unit_weight_kg' => 2.0]);
        $line = $result->lines->first();

        $updated = $this->resultService->correctResult(
            $result,
            [
                'heat_number' => $result->heat_number,
                'cast_date' => $result->cast_date->format('Y-m-d'),
            ],
            [
                [
                    'id' => $line->id,
                    'sand_casting_casting_order_line_id' => $line->sand_casting_casting_order_line_id,
                    'qty_good' => 20,
                    'qty_reject' => 2,
                    'unit_weight_kg' => 2.0,
                ],
            ],
            $this->ppicUser->id
        );

        $freshLine = $line->fresh();
        $this->assertEquals(20, $freshLine->qty_good);
        $this->assertEquals(2, $freshLine->qty_reject);
        $this->assertEquals(40.00, (float) $freshLine->total_weight_kg);

        $this->assertDatabaseHas('sand_casting_casting_result_corrections', [
            'sand_casting_casting_result_line_id' => $line->id,
            'field_name' => 'qty_good',
            'old_value' => '13',
            'new_value' => '20',
        ]);
    }

    public function test_edit_qty_good_after_execution_rejected(): void
    {
        $plan = $this->createPlan(['code' => 'S797']);
        $result = $this->createResult($plan, [], ['qty_good' => 13]);
        $line = $result->lines->first();

        // Physical execution NETTO
        $this->stageService->markPhysicalDone($line->traveler_number, 'netto', $this->ppicUser->id);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Kuantitas bagus (Qty Good) pada KTR '.$line->traveler_number.' terkunci');

        $this->resultService->correctResult(
            $result,
            [
                'heat_number' => $result->heat_number,
                'cast_date' => $result->cast_date->format('Y-m-d'),
                'reason' => 'Percobaan koreksi qty good post gate',
            ],
            [
                [
                    'id' => $line->id,
                    'sand_casting_casting_order_line_id' => $line->sand_casting_casting_order_line_id,
                    'qty_good' => 15,
                ],
            ],
            $this->ppicUser->id
        );
    }

    public function test_edit_qty_reject_after_execution_success_with_audit(): void
    {
        $plan = $this->createPlan(['code' => 'S797']);
        $result = $this->createResult($plan, [], ['qty_good' => 13, 'qty_reject' => 0]);
        $line = $result->lines->first();

        // Physical execution NETTO
        $this->stageService->markPhysicalDone($line->traveler_number, 'netto', $this->ppicUser->id);

        $updated = $this->resultService->correctResult(
            $result,
            [
                'heat_number' => $result->heat_number,
                'cast_date' => $result->cast_date->format('Y-m-d'),
                'reason' => 'Koreksi pencatatan scrap cor leburan',
            ],
            [
                [
                    'id' => $line->id,
                    'sand_casting_casting_order_line_id' => $line->sand_casting_casting_order_line_id,
                    'qty_good' => 13,
                    'qty_reject' => 4,
                    'unit_weight_kg' => 6.48,
                ],
            ],
            $this->ppicUser->id
        );

        $freshLine = $line->fresh();
        $this->assertEquals(13, $freshLine->qty_good);
        $this->assertEquals(4, $freshLine->qty_reject);

        $this->assertDatabaseHas('sand_casting_casting_result_corrections', [
            'sand_casting_casting_result_line_id' => $line->id,
            'field_name' => 'qty_reject',
            'old_value' => '0',
            'new_value' => '4',
        ]);
    }

    // =========================================================================
    // 4. HEAT NUMBER (PRE VS POST-GATE)
    // =========================================================================

    public function test_edit_heat_number_before_execution_success(): void
    {
        $plan = $this->createPlan(['code' => 'S797']);
        $result = $this->createResult($plan, ['heat_number' => 'HEAT-OLD']);
        $line = $result->lines->first();

        $updated = $this->resultService->correctResult(
            $result,
            [
                'heat_number' => 'HEAT-NEW',
                'cast_date' => $result->cast_date->format('Y-m-d'),
            ],
            [
                [
                    'id' => $line->id,
                    'sand_casting_casting_order_line_id' => $line->sand_casting_casting_order_line_id,
                    'qty_good' => 13,
                ],
            ],
            $this->ppicUser->id
        );

        $this->assertEquals('HEAT-NEW', $updated->heat_number);

        $this->assertDatabaseHas('sand_casting_casting_result_corrections', [
            'sand_casting_casting_result_id' => $result->id,
            'field_name' => 'heat_number',
            'old_value' => 'HEAT-OLD',
            'new_value' => 'HEAT-NEW',
        ]);
    }

    public function test_edit_heat_number_after_execution_rejected(): void
    {
        $plan = $this->createPlan(['code' => 'S797']);
        $result = $this->createResult($plan, ['heat_number' => 'HEAT-OLD']);
        $line = $result->lines->first();

        // Physical execution NETTO
        $this->stageService->markPhysicalDone($line->traveler_number, 'netto', $this->ppicUser->id);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Nomor Heat terkunci dan tidak dapat diubah');

        $this->resultService->correctResult(
            $result,
            [
                'heat_number' => 'HEAT-NEW',
                'cast_date' => $result->cast_date->format('Y-m-d'),
                'reason' => 'Percobaan ubah heat number',
            ],
            [
                [
                    'id' => $line->id,
                    'sand_casting_casting_order_line_id' => $line->sand_casting_casting_order_line_id,
                    'qty_good' => 13,
                ],
            ],
            $this->ppicUser->id
        );
    }

    // =========================================================================
    // 5. DUPLICATE DEFENSE (HEAT + PRODUCTION PLAN)
    // =========================================================================

    public function test_duplicate_heat_and_production_plan_through_edit_rejected(): void
    {
        $planA = $this->createPlan(['code' => 'S797']);
        $planB = $this->createPlan(['code' => 'S798']);

        $orderA = $this->createOrder($planA);
        $orderB = $this->createOrder($planB);

        // Result 1: Heat A + Plan A
        $result1 = $this->resultService->recordResult(
            ['heat_number' => 'HEAT-ALPHA', 'cast_date' => '2026-10-01'],
            [['sand_casting_casting_order_line_id' => $orderA->lines->first()->id, 'qty_good' => 10]],
            $this->ppicUser->id
        );

        // Result 2: Heat B + Plan B
        $result2 = $this->resultService->recordResult(
            ['heat_number' => 'HEAT-BETA', 'cast_date' => '2026-10-01'],
            [['sand_casting_casting_order_line_id' => $orderB->lines->first()->id, 'qty_good' => 10]],
            $this->ppicUser->id
        );

        // Try to edit Result 2 to Heat A + Plan A (which would duplicate Result 1)
        $line2 = $result2->lines->first();

        $this->expectException(DuplicateCastingResultException::class);

        $this->resultService->correctResult(
            $result2,
            [
                'heat_number' => 'HEAT-ALPHA', // Changed to Heat Alpha
                'cast_date' => '2026-10-01',
            ],
            [
                [
                    'id' => $line2->id,
                    'sand_casting_casting_order_line_id' => $orderA->lines->first()->id, // Changed to Plan A
                    'qty_good' => 10,
                ],
            ],
            $this->ppicUser->id
        );
    }

    public function test_multi_line_heat_duplicate_within_edit_request_rejected(): void
    {
        $planA = $this->createPlan(['code' => 'S797']);
        $planB = $this->createPlan(['code' => 'S798']);

        $orderA = $this->createOrder($planA);
        $orderB = $this->createOrder($planB);

        $result = $this->resultService->recordResult(
            ['heat_number' => 'HEAT-MULTI', 'cast_date' => '2026-10-01'],
            [
                ['sand_casting_casting_order_line_id' => $orderA->lines->first()->id, 'qty_good' => 10],
                ['sand_casting_casting_order_line_id' => $orderB->lines->first()->id, 'qty_good' => 10],
            ],
            $this->ppicUser->id
        );

        $line1 = $result->lines[0];
        $line2 = $result->lines[1];

        // Edit line 2 to point to Plan A as well (same heat with two Plan A lines)
        $this->expectException(DuplicateCastingResultException::class);

        $this->resultService->correctResult(
            $result,
            [
                'heat_number' => 'HEAT-MULTI',
                'cast_date' => '2026-10-01',
            ],
            [
                [
                    'id' => $line1->id,
                    'sand_casting_casting_order_line_id' => $orderA->lines->first()->id,
                    'qty_good' => 10,
                ],
                [
                    'id' => $line2->id,
                    'sand_casting_casting_order_line_id' => $orderA->lines->first()->id, // Duplicate in request!
                    'qty_good' => 10,
                ],
            ],
            $this->ppicUser->id
        );
    }

    // =========================================================================
    // 6. REPRINT TRACKING & STATUS
    // =========================================================================

    public function test_print_then_edit_identity_pre_gate_marks_needs_reprint_and_reprint_clears_it(): void
    {
        $plan = $this->createPlan(['code' => 'S797']);
        $result = $this->createResult($plan);
        $line = $result->lines->first();

        // Simulate Kitir printed
        $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.kitir', [$result, $line]));

        $this->assertEquals(1, $line->fresh()->print_count);
        $this->assertFalse($line->fresh()->needs_reprint);

        // Edit qty_good pre-gate
        $this->resultService->correctResult(
            $result,
            [
                'heat_number' => $result->heat_number,
                'cast_date' => $result->cast_date->format('Y-m-d'),
            ],
            [
                [
                    'id' => $line->id,
                    'sand_casting_casting_order_line_id' => $line->sand_casting_casting_order_line_id,
                    'qty_good' => 18,
                ],
            ],
            $this->ppicUser->id
        );

        // Line MUST be marked as needing reprint
        $this->assertTrue($line->fresh()->needs_reprint);

        // Re-print Kitir via controller
        $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.kitir', [$result, $line]));

        // needs_reprint is now cleared and print_count incremented
        $freshLine = $line->fresh();
        $this->assertFalse($freshLine->needs_reprint);
        $this->assertEquals(2, $freshLine->print_count);
    }

    // =========================================================================
    // 7. MULTI-LINE PARTIAL EXECUTION ISOLATION
    // =========================================================================

    public function test_multi_line_result_partial_execution_locking(): void
    {
        $plan1 = $this->createPlan(['code' => 'S797']);
        $plan2 = $this->createPlan(['code' => 'S798']);
        $plan3 = $this->createPlan(['code' => 'S799']);

        $order1 = $this->createOrder($plan1);
        $order2 = $this->createOrder($plan2);
        $order3 = $this->createOrder($plan3);

        $result = $this->resultService->recordResult(
            ['heat_number' => 'HEAT-MIXED', 'cast_date' => '2026-10-01'],
            [
                ['sand_casting_casting_order_line_id' => $order1->lines->first()->id, 'qty_good' => 10],
                ['sand_casting_casting_order_line_id' => $order2->lines->first()->id, 'qty_good' => 15],
            ],
            $this->ppicUser->id
        );

        $line1 = $result->lines[0];
        $line2 = $result->lines[1];

        // Line 1 is executed at Netto
        $this->stageService->markPhysicalDone($line1->traveler_number, 'netto', $this->ppicUser->id);

        $this->assertTrue($line1->fresh()->hasPhysicalExecution());
        $this->assertFalse($line2->fresh()->hasPhysicalExecution());

        // Line 2 can be edited (change item to plan3 and qty to 25)
        $updated = $this->resultService->correctResult(
            $result,
            [
                'heat_number' => 'HEAT-MIXED',
                'cast_date' => '2026-10-01',
                'reason' => 'Koreksi line 2 yang belum dipotong',
            ],
            [
                [
                    'id' => $line1->id,
                    'sand_casting_casting_order_line_id' => $order1->lines->first()->id,
                    'qty_good' => 10, // Line 1 identity unchanged
                ],
                [
                    'id' => $line2->id,
                    'sand_casting_casting_order_line_id' => $order3->lines->first()->id, // Line 2 changed
                    'qty_good' => 25,
                ],
            ],
            $this->ppicUser->id
        );

        $this->assertEquals($plan3->id, $line2->fresh()->production_plan_id);
        $this->assertEquals(25, $line2->fresh()->qty_good);
        $this->assertEquals(10, $line1->fresh()->qty_good);

        // Attempting to change Line 1's qty_good will fail
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Kuantitas bagus (Qty Good) pada KTR '.$line1->traveler_number.' terkunci');

        $this->resultService->correctResult(
            $result,
            [
                'heat_number' => 'HEAT-MIXED',
                'cast_date' => '2026-10-01',
                'reason' => 'Percobaan ubah line 1',
            ],
            [
                [
                    'id' => $line1->id,
                    'sand_casting_casting_order_line_id' => $order1->lines->first()->id,
                    'qty_good' => 12, // Invalid mutation!
                ],
                [
                    'id' => $line2->id,
                    'sand_casting_casting_order_line_id' => $order3->lines->first()->id,
                    'qty_good' => 25,
                ],
            ],
            $this->ppicUser->id
        );
    }

    // =========================================================================
    // 8. SECURITY & AUTHORIZATION TESTS
    // =========================================================================

    public function test_unauthorized_spv_cannot_access_correction_endpoint(): void
    {
        $plan = $this->createPlan();
        $result = $this->createResult($plan);

        // SPV GET edit
        $response = $this->actingAs($this->spvUser)
            ->get(route('sand-casting.casting-results.edit', $result));
        $response->assertStatus(403);

        // SPV PUT update
        $response = $this->actingAs($this->spvUser)
            ->put(route('sand-casting.casting-results.update', $result), [
                'heat_number' => $result->heat_number,
                'cast_date' => '2026-10-01',
                'items' => [
                    [
                        'id' => $result->lines->first()->id,
                        'sand_casting_casting_order_line_id' => $result->lines->first()->sand_casting_casting_order_line_id,
                        'qty_good' => 10,
                    ],
                ],
            ]);
        $response->assertStatus(403);
    }

    public function test_ppic_user_can_access_edit_and_update(): void
    {
        $plan = $this->createPlan();
        $result = $this->createResult($plan);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-results.edit', $result));
        $response->assertStatus(200);
        $response->assertSee('Koreksi Hasil Cor');

        $response = $this->actingAs($this->ppicUser)
            ->put(route('sand-casting.casting-results.update', $result), [
                'heat_number' => $result->heat_number,
                'cast_date' => '2026-09-30',
                'furnace' => 'F-02',
                'items' => [
                    [
                        'id' => $result->lines->first()->id,
                        'sand_casting_casting_order_line_id' => $result->lines->first()->sand_casting_casting_order_line_id,
                        'qty_good' => 13,
                        'qty_reject' => 1,
                        'unit_weight_kg' => 6.48,
                    ],
                ],
            ]);

        $response->assertRedirect(route('sand-casting.casting-results.show', $result));
        $this->assertEquals('2026-09-30', $result->fresh()->cast_date->format('Y-m-d'));
    }

    public function test_post_gate_correction_preserves_historical_stage_execution_state(): void
    {
        $plan = $this->createPlan(['code' => 'S797']);
        $result = $this->createResult($plan);
        $line = $result->lines->first();

        // 1. Operator marks physical done at Netto
        $execution = $this->stageService->markPhysicalDone($line->traveler_number, 'netto', $this->ppicUser->id);

        $this->assertEquals(13, $execution->input_qty);
        $this->assertEquals(13, $execution->good_qty);
        $this->assertEquals('bubut_od', $line->fresh()->current_stage);

        // 2. Controlled correction on header fields
        $this->resultService->correctResult(
            $result,
            [
                'heat_number' => $result->heat_number,
                'cast_date' => '2026-09-30',
                'furnace' => 'F-09',
                'operator_name' => 'Operator Updated',
                'reason' => 'Perbaikan nama operator dan tanggal',
            ],
            [
                [
                    'id' => $line->id,
                    'sand_casting_casting_order_line_id' => $line->sand_casting_casting_order_line_id,
                    'qty_good' => 13,
                    'qty_reject' => 2,
                    'unit_weight_kg' => 6.48,
                ],
            ],
            $this->ppicUser->id
        );

        // 3. Re-verify execution state is 100% untampered
        $freshExecution = SandCastingStageExecution::find($execution->id);
        $this->assertEquals(13, $freshExecution->input_qty);
        $this->assertEquals(13, $freshExecution->good_qty);
        $this->assertEquals('netto', $freshExecution->stage);
        $this->assertEquals('WAITING_DEFECT', $freshExecution->status);

        // Line operational stage is untampered
        $this->assertEquals('bubut_od', $line->fresh()->current_stage);
    }
}
