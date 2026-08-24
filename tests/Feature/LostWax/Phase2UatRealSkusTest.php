<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxTree;
use App\Models\LostWaxWorkOrder;
use App\Models\LostWaxWorkOrderPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase2UatRealSkusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            \App\Contracts\ItemMasterRepository::class,
            new \Tests\Fakes\ArrayItemMasterRepository([
                [
                    'code' => '4.101105K.A0015',
                    'name' => 'SS304 CASTED PLANE FLANGE JIS 5K 1/2"',
                    'aisi' => 'SCS 13 A',
                    'standard' => 'JIS',
                    'unit_weight' => '0.300',
                    'status' => 'active',
                ],
                [
                    'code' => '4.101105K.A0080',
                    'name' => 'SS304 CASTED PLANE FLANGE JIS 5K 3"',
                    'aisi' => 'SCS 13 A',
                    'standard' => 'JIS',
                    'unit_weight' => '1.980',
                    'status' => 'active',
                ],
                [
                    'code' => '4.104210K.A0015',
                    'name' => 'SS316 CASTED RAISED FLANGE JIS 10K 1/2"',
                    'aisi' => 'SCS 14 A',
                    'standard' => 'JIS',
                    'unit_weight' => '0.450',
                    'status' => 'active',
                ],
            ])
        );
    }

    public function test_uat_with_real_masterdata_skus_and_full_workflow(): void
    {
        $user = User::factory()->create();

        // SKUs selected from actual local masterdata_kpi.md_items:
        // 1. 4.101105K.A0015 — SS304 CASTED PLANE FLANGE JIS 5K 1/2"       (SCS 13 A / SS304)
        // 2. 4.101105K.A0080 — SS304 CASTED PLANE FLANGE JIS 5K 3"          (SCS 13 A / SS304)
        // 3. 4.104210K.A0015 — SS316 CASTED RAISED FLANGE JIS 10K 1/2"      (SCS 14 A / SS316)

        Carbon::setTestNow(Carbon::create(2026, 8, 11));

        // --- BULK CREATE 3 WORK ORDERS ---
        $this->actingAs($user)
            ->postJson(route('lost-wax.work-orders.bulk.store'), [
                'rows' => [
                    [
                        'et_code' => 'LWDEMO001',
                        'item_code' => '4.101105K.A0015',
                        'po_number' => 'PO-DEMO-001',
                        'po_quantity' => 1000,
                        'stock_quantity' => 500,
                        'net_requirement_quantity' => 500,
                        'family_code' => '3',
                        'status' => 'draft',
                    ],
                    [
                        'et_code' => 'LWDEMO002',
                        'item_code' => '4.101105K.A0080',
                        'po_number' => 'PO-DEMO-002',
                        'po_quantity' => 500,
                        'stock_quantity' => 100,
                        'net_requirement_quantity' => 400,
                        'family_code' => '3',
                        'status' => 'draft',
                    ],
                    [
                        'et_code' => 'LWDEMO003',
                        'item_code' => '4.104210K.A0015',
                        'po_number' => 'PO-DEMO-003',
                        'po_quantity' => 2000,
                        'stock_quantity' => 1000,
                        'net_requirement_quantity' => 1000,
                        'family_code' => '4',
                        'status' => 'draft',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseCount('lost_wax_work_orders', 3);

        $wo1 = LostWaxWorkOrder::where('et_code', 'LWDEMO001')->firstOrFail();
        $wo2 = LostWaxWorkOrder::where('et_code', 'LWDEMO002')->firstOrFail();
        $wo3 = LostWaxWorkOrder::where('et_code', 'LWDEMO003')->firstOrFail();

        // --- WORK ORDER PLANS ---
        $this->actingAs($user)
            ->post(route('lost-wax.work-orders.plans.store', $wo1), [
                'wave_number' => 1,
                'plan_type' => 'initial',
                'planned_quantity' => 705,
                'status' => 'planned',
            ])
            ->assertSessionHasNoErrors();

        $plan1 = LostWaxWorkOrderPlan::where('work_order_id', $wo1->id)->first();

        $this->actingAs($user)
            ->post(route('lost-wax.work-orders.plans.store', $wo2), [
                'wave_number' => 1,
                'plan_type' => 'initial',
                'planned_quantity' => 449,
                'status' => 'planned',
            ])
            ->assertSessionHasNoErrors();

        $plan2 = LostWaxWorkOrderPlan::where('work_order_id', $wo2->id)->first();

        $this->actingAs($user)
            ->post(route('lost-wax.work-orders.plans.store', $wo3), [
                'wave_number' => 1,
                'plan_type' => 'initial',
                'planned_quantity' => 900,
                'status' => 'planned',
            ])
            ->assertSessionHasNoErrors();

        $plan3 = LostWaxWorkOrderPlan::where('work_order_id', $wo3->id)->first();

        // --- WIP BEFORE TREE ---
        $this->actingAs($user)
            ->post(route('lost-wax.work-orders.wip.store', $wo1), [
                'stage' => 'assembly',
                'quantity' => 450,
                'status' => 'recorded',
                'produced_at' => '2026-08-11',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('lost-wax.work-orders.wip.store', $wo2), [
                'stage' => 'assembly',
                'quantity' => 449,
                'status' => 'recorded',
                'produced_at' => '2026-08-11',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('lost-wax.work-orders.wip.store', $wo3), [
                'stage' => 'assembly',
                'quantity' => 300,
                'status' => 'recorded',
                'produced_at' => '2026-08-11',
            ])
            ->assertSessionHasNoErrors();

        // --- TREE GENERATION: LWDEMO001 (Family 3, 30 trees × 15 pcs) ---
        $this->actingAs($user)
            ->get(route('lost-wax.trees.generate', $plan1))
            ->assertOk()
            ->assertSee('450');

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan1), [
                'default_qty' => 15,
                'quantities' => array_fill(0, 30, 15),
                'family_code' => '3',
            ])
            ->assertRedirect()
            ->assertSessionMissing('error');

        $treesW01 = LostWaxTree::where('work_order_id', $wo1->id)->orderBy('tree_number')->get();
        $this->assertCount(30, $treesW01);
        $this->assertSame(450, $treesW01->sum('quantity'));

        // Family 3 barcode format: 3110826001 ... 3110826030
        $this->assertSame('3110826001', $treesW01[0]->barcode);
        $this->assertSame('3110826030', $treesW01[29]->barcode);
        $this->assertSame(1, $treesW01[0]->daily_sequence);
        $this->assertSame(30, $treesW01[29]->daily_sequence);

        // --- TREE GENERATION: LWDEMO002 (Family 3, variable: 29×15 + 1×14) ---
        $quantities = array_merge(array_fill(0, 29, 15), [14]);
        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan2), [
                'default_qty' => 15,
                'quantities' => $quantities,
                'family_code' => '3',
            ])
            ->assertRedirect()
            ->assertSessionMissing('error');

        $treesW02 = LostWaxTree::where('work_order_id', $wo2->id)->orderBy('tree_number')->get();
        $this->assertCount(30, $treesW02);
        $this->assertSame(14, $treesW02[29]->quantity);
        $this->assertSame(449, $treesW02->sum('quantity'));

        // Family 3 sequence continues: 031 ... 060
        $this->assertSame(31, $treesW02[0]->daily_sequence);
        $this->assertSame(60, $treesW02[29]->daily_sequence);
        $this->assertSame('3110826031', $treesW02[0]->barcode);
        $this->assertSame('3110826060', $treesW02[29]->barcode);

        // --- TREE GENERATION: LWDEMO003 (Family 4, independent sequence) ---
        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan3), [
                'default_qty' => 15,
                'quantities' => array_fill(0, 20, 15),
                'family_code' => '4',
            ])
            ->assertRedirect()
            ->assertSessionMissing('error');

        $treesW03 = LostWaxTree::where('work_order_id', $wo3->id)->orderBy('tree_number')->get();
        $this->assertCount(20, $treesW03);

        // Family 4 starts at sequence 1 (independent)
        $this->assertSame(1, $treesW03[0]->daily_sequence);
        $this->assertSame('4', $treesW03[0]->family_code);
        $this->assertTrue(str_starts_with($treesW03[0]->barcode, '4'));
        $this->assertSame('4110826001', $treesW03[0]->barcode);

        // --- TRAVELER ---
        $rack = \App\Models\LostWaxCoatingRack::create(['rack_number' => 1, 'status' => 'active']);
        $treesW01[0]->update(['rack_id' => $rack->id, 'rack_assigned_at' => now()]);

        $this->actingAs($user)
            ->get(route('lost-wax.trees.traveler', $treesW01[0]))
            ->assertOk()
            ->assertSee('LOST WAX TRAVELER')
            ->assertSee('3110826001')
            ->assertSee('4.101105K.A0015')
            ->assertSee('SS304 CASTED PLANE FLANGE')
            ->assertSee('SCS 13 A');

        // Barcode image
        $this->actingAs($user)
            ->get(route('lost-wax.trees.barcode', $treesW01[0]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        // --- TREE CORRECTION ---
        $this->actingAs($user)
            ->patch(route('lost-wax.trees.update', $treesW01[29]), [
                'quantity' => 14,
            ])
            ->assertSessionHas('success');

        $this->assertSame(14, $treesW01[29]->fresh()->quantity);
        $this->assertSame(449, (int) LostWaxTree::where('work_order_id', $wo1->id)->sum('quantity'));

        Carbon::setTestNow(null);

        // --- TOTAL VERIFICATION ---
        $totalTrees = LostWaxTree::count();
        $this->assertSame(80, $totalTrees);

        // Family 3: 30 + 30 = 60 trees, sequences 001-060
        $fam3Count = LostWaxTree::where('family_code', '3')->count();
        $this->assertSame(60, $fam3Count);

        // Family 4: 20 trees, sequences 001-020
        $fam4Count = LostWaxTree::where('family_code', '4')->count();
        $this->assertSame(20, $fam4Count);

        echo "\n══════════════════════════════════════════════════\n";
        echo "  PHASE 2 UAT — REAL MASTERDATA SKUs\n";
        echo "══════════════════════════════════════════════════\n";
        echo "  Total Work Orders:     3\n";
        echo "  Total Trees:           80\n";
        echo "  Family 3 trees:        60 (seq 001–060)\n";
        echo "  Family 4 trees:        20 (seq 001–020)\n";
        echo "  Family 1 trees:        0 (none generated)\n";
        echo "  Variable qty tested:   14 pcs (final tree)\n";
        echo "  Correction tested:     15 → 14 pcs\n";
        echo "  Traveler tested:       PASS\n";
        echo "  Barcode image tested:  PASS\n";
        echo "══════════════════════════════════════════════════\n";
        echo "  PHASE 2 UAT STATUS: PASS\n";
        echo "══════════════════════════════════════════════════\n";
    }

    public function test_single_work_order_form_page_loads_with_real_skus(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.work-orders.create'));

        $response->assertOk();
        $response->assertSee('Tambah Work Order');

        $this->assertNotNull($this->app->make(\App\Contracts\ItemMasterRepository::class)->findByCode('4.101105K.A0015'));
        $this->assertNotNull($this->app->make(\App\Contracts\ItemMasterRepository::class)->findByCode('4.104210K.A0015'));
    }

    public function test_bulk_page_loads_with_skus(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('lost-wax.work-orders.bulk.create'));

        $response->assertOk();
        $response->assertSee('Bulk Input');
        $response->assertSee('Handsontable');
    }

    public function test_tree_show_page_displays_all_fields(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 11));

        $user = User::factory()->create();

        // Quick create: work order + plan + wip + tree
        $this->actingAs($user)
            ->postJson(route('lost-wax.work-orders.bulk.store'), [
                'rows' => [
                    [
                        'et_code' => 'LWDEMO001',
                        'item_code' => '4.101105K.A0015',
                        'po_number' => 'PO-001',
                        'po_quantity' => 100,
                        'stock_quantity' => 0,
                        'net_requirement_quantity' => 100,
                        'family_code' => '3',
                        'status' => 'draft',
                    ],
                ],
            ])
            ->assertJson(['success' => true]);

        $wo = LostWaxWorkOrder::firstOrFail();

        $this->actingAs($user)
            ->post(route('lost-wax.work-orders.plans.store', $wo), [
                'wave_number' => 1,
                'plan_type' => 'initial',
                'planned_quantity' => 100,
                'status' => 'planned',
            ])
            ->assertSessionHasNoErrors();

        $plan = LostWaxWorkOrderPlan::firstOrFail();

        $this->actingAs($user)
            ->post(route('lost-wax.work-orders.wip.store', $wo), [
                'stage' => 'assembly',
                'quantity' => 15,
                'status' => 'recorded',
                'produced_at' => '2026-08-11',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan), [
                'default_qty' => 15,
                'quantities' => [15],
                'family_code' => '3',
            ])
            ->assertRedirect()
            ->assertSessionMissing('error');

        $tree = LostWaxTree::firstOrFail();

        // Tree show page
        $response = $this->actingAs($user)
            ->get(route('lost-wax.trees.show', $tree));

        $response->assertOk();
        $response->assertSee($tree->barcode);
        $response->assertSee('001');  // tree number
        $response->assertSee('15');   // quantity
        $response->assertSee('Generated');
        $response->assertSee('11-08-2026');
        $response->assertSee('Koreksi');

        Carbon::setTestNow(null);
    }
}
