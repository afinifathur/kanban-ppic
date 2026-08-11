<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxItemReference;
use App\Models\LostWaxMouldingFamily;
use App\Models\LostWaxMouldingInstance;
use App\Models\LostWaxRack;
use App\Models\LostWaxWorkOrder;
use App\Models\LostWaxWorkOrderPlan;
use App\Models\LostWaxWorkOrderWip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\ArrayItemMasterRepository;
use Tests\TestCase;

class WorkOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            \App\Contracts\ItemMasterRepository::class,
            new ArrayItemMasterRepository([
                [
                    'code' => 'LY026',
                    'name' => 'Hex Nipple SUS304',
                    'aisi' => 'SUS304',
                    'standard' => 'STD',
                    'unit_weight' => '0.125',
                    'status' => 'active',
                ],
                [
                    'code' => 'LY027',
                    'name' => 'Flange X',
                    'aisi' => 'SUS316',
                    'standard' => 'STD',
                    'unit_weight' => '0.235',
                    'status' => 'inactive',
                ],
            ])
        );
    }

    public function test_work_order_creation_persists_reference_and_quantities(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('lost-wax.work-orders.store'), [
            'et_code' => 'ET26-0232',
            'item_code' => 'LY026',
            'po_number' => 'PO-001',
            'customer_name' => 'PT Contoh',
            'po_quantity' => 1000,
            'stock_quantity' => 500,
            'net_requirement_quantity' => 500,
            'status' => 'planned',
            'notes' => 'Test',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('lost_wax_item_references', [
            'master_item_key' => 'LY026',
            'item_code_snapshot' => 'LY026',
            'item_name_snapshot' => 'Hex Nipple SUS304',
        ]);

        $this->assertDatabaseHas('lost_wax_work_orders', [
            'et_code' => 'ET26-0232',
            'po_number' => 'PO-001',
            'po_quantity' => 1000,
            'stock_quantity' => 500,
            'net_requirement_quantity' => 500,
        ]);
    }

    public function test_work_order_create_and_edit_pages_load(): void
    {
        $user = User::factory()->create();
        $workOrder = $this->createWorkOrder();

        $this->actingAs($user)
            ->get(route('lost-wax.work-orders.create'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('lost-wax.work-orders.edit', $workOrder))
            ->assertOk();
    }

    public function test_duplicate_et_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('lost-wax.work-orders.store'), [
            'et_code' => 'ET26-0232',
            'item_code' => 'LY026',
            'po_number' => 'PO-001',
            'po_quantity' => 100,
            'stock_quantity' => 0,
            'net_requirement_quantity' => 100,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($user)->post(route('lost-wax.work-orders.store'), [
            'et_code' => 'ET26-0232',
            'item_code' => 'LY026',
            'po_number' => 'PO-002',
            'po_quantity' => 50,
            'stock_quantity' => 0,
            'net_requirement_quantity' => 50,
            'status' => 'draft',
        ]);

        $response->assertSessionHasErrors('et_code');
    }

    public function test_work_order_can_have_multiple_plans_under_one_et(): void
    {
        $workOrder = $this->createWorkOrder();

        $user = User::factory()->create();

        $this->actingAs($user)->post(route('lost-wax.work-orders.plans.store', $workOrder), [
            'wave_number' => 1,
            'plan_type' => 'initial',
            'planned_quantity' => 705,
            'status' => 'planned',
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->post(route('lost-wax.work-orders.plans.store', $workOrder), [
            'wave_number' => 2,
            'plan_type' => 'additional',
            'planned_quantity' => 105,
            'status' => 'planned',
            'reason' => 'Tambahan produksi',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('lost_wax_work_order_plans', 2);
        $this->assertEquals(2, $workOrder->fresh()->plans()->count());
    }

    public function test_initial_and_additional_plan_are_stored(): void
    {
        $workOrder = $this->createWorkOrder();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('lost-wax.work-orders.plans.store', $workOrder), [
            'wave_number' => 1,
            'plan_type' => 'initial',
            'planned_quantity' => 705,
            'status' => 'planned',
        ]);

        $this->actingAs($user)->post(route('lost-wax.work-orders.plans.store', $workOrder), [
            'wave_number' => 2,
            'plan_type' => 'additional',
            'planned_quantity' => 105,
            'status' => 'planned',
        ]);

        $plans = $workOrder->fresh()->plans()->orderBy('wave_number')->get();

        $this->assertSame('initial', $plans[0]->plan_type);
        $this->assertSame(705, $plans[0]->planned_quantity);
        $this->assertSame('additional', $plans[1]->plan_type);
        $this->assertSame(105, $plans[1]->planned_quantity);
    }

    public function test_quantity_separation_and_remaining_calculation(): void
    {
        $workOrder = $this->createWorkOrder([
            'po_quantity' => 1000,
            'stock_quantity' => 500,
            'net_requirement_quantity' => 500,
        ]);

        LostWaxWorkOrderPlan::create([
            'work_order_id' => $workOrder->id,
            'wave_number' => 1,
            'plan_type' => 'initial',
            'planned_quantity' => 705,
            'status' => 'planned',
        ]);

        LostWaxWorkOrderWip::create([
            'work_order_id' => $workOrder->id,
            'stage' => 'moulding',
            'quantity' => 500,
            'status' => 'recorded',
            'produced_at' => now(),
        ]);

        LostWaxWorkOrderWip::create([
            'work_order_id' => $workOrder->id,
            'stage' => 'assembly',
            'quantity' => 450,
            'status' => 'recorded',
            'produced_at' => now(),
        ]);

        $workOrder = $workOrder->fresh(['plans', 'wipEntries']);

        $this->assertSame(1000, $workOrder->po_quantity);
        $this->assertSame(500, $workOrder->stock_quantity);
        $this->assertSame(500, $workOrder->net_requirement_quantity);
        $this->assertSame(705, $workOrder->planned_quantity);
        $this->assertSame(500, $workOrder->moulding_output_quantity);
        $this->assertSame(450, $workOrder->assembly_output_quantity);
        $this->assertSame(50, $workOrder->remaining_before_tree_quantity);
    }

    public function test_sku_reference_validation_requires_master_item(): void
    {
        $this->app->instance(\App\Contracts\ItemMasterRepository::class, new ArrayItemMasterRepository([]));

        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('lost-wax.work-orders.create'))
            ->post(route('lost-wax.work-orders.store'), [
                'et_code' => 'ET26-0999',
                'item_code' => 'UNKNOWN',
                'po_number' => 'PO-999',
                'po_quantity' => 10,
                'stock_quantity' => 0,
                'net_requirement_quantity' => 10,
                'status' => 'draft',
            ])
            ->assertSessionHasErrors('item_code');
    }

    public function test_moulding_family_to_instance_relationship(): void
    {
        $reference = LostWaxItemReference::create([
            'master_source' => 'masterdata_kpi',
            'master_item_key' => 'LY026',
            'item_code_snapshot' => 'LY026',
            'item_name_snapshot' => 'Hex Nipple SUS304',
        ]);

        $family = LostWaxMouldingFamily::create([
            'item_reference_id' => $reference->id,
            'family_code' => 'LY026',
            'name' => 'LY026 Family',
            'status' => 'active',
        ]);

        $instance = LostWaxMouldingInstance::create([
            'moulding_family_id' => $family->id,
            'instance_code' => 'LY026-A',
            'label' => 'Instance A',
            'status' => 'active',
        ]);

        $this->assertSame($family->id, $instance->family->id);
        $this->assertSame($reference->id, $family->itemReference->id);
        $this->assertSame('LY026-A', $family->fresh()->instances->first()->instance_code);
    }

    public function test_moulding_instance_can_point_to_rack(): void
    {
        $reference = LostWaxItemReference::create([
            'master_source' => 'masterdata_kpi',
            'master_item_key' => 'LY026',
            'item_code_snapshot' => 'LY026',
            'item_name_snapshot' => 'Hex Nipple SUS304',
        ]);

        $family = LostWaxMouldingFamily::create([
            'item_reference_id' => $reference->id,
            'family_code' => 'LY026',
            'name' => 'LY026 Family',
            'status' => 'active',
        ]);

        $rack = LostWaxRack::create([
            'code' => 'B-3',
            'label' => 'Rak B-3',
            'location' => 'Gudang A',
            'status' => 'active',
        ]);

        $instance = LostWaxMouldingInstance::create([
            'moulding_family_id' => $family->id,
            'instance_code' => 'LY026-B',
            'label' => 'Instance B',
            'rack_id' => $rack->id,
            'status' => 'active',
        ]);

        $this->assertSame($rack->id, $instance->rack->id);
        $this->assertSame('B-3', $instance->rack->code);
    }

    protected function createWorkOrder(array $overrides = []): LostWaxWorkOrder
    {
        $reference = LostWaxItemReference::create([
            'master_source' => 'masterdata_kpi',
            'master_item_key' => 'LY026',
            'item_code_snapshot' => 'LY026',
            'item_name_snapshot' => 'Hex Nipple SUS304',
        ]);

        return LostWaxWorkOrder::create(array_merge([
            'item_reference_id' => $reference->id,
            'et_code' => 'ET26-0232',
            'po_number' => 'PO-001',
            'customer_name' => 'PT Contoh',
            'po_quantity' => 1000,
            'stock_quantity' => 500,
            'net_requirement_quantity' => 500,
            'status' => 'planned',
        ], $overrides));
    }
}
