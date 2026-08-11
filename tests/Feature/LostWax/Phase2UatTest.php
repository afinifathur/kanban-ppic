<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxTree;
use App\Models\LostWaxWorkOrder;
use App\Models\LostWaxWorkOrderPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase2UatTest extends TestCase
{
    use RefreshDatabase;

    public function test_uat_complete_phase2_workflow(): void
    {
        $user = User::factory()->create();

        // ==========================================
        // UAT STEP 1: BULK WORK ORDER INPUT
        // ==========================================
        // Simulate: PPIC copies from Excel, pastes into Handsontable
        // Creates 3 demo ETs with real MasterDataKPI SKUs.
        // These SKUs exist in the actual local masterdata_kpi.md_items table.
        //
        // Selected items:
        //   LY026 (test fake) — Hex Nipple SUS304        (SCS 13 A)
        //   LY027 (test fake) — Flange X                  (SCS 14 A)
        //   LY028 (test fake) — Elbow 90                  (SCS 304)

        $this->app->instance(
            \App\Contracts\ItemMasterRepository::class,
            new \Tests\Fakes\ArrayItemMasterRepository([
                [
                    'code' => 'LY026',
                    'name' => 'Hex Nipple SUS304',
                    'aisi' => 'SCS 13 A',
                    'standard' => 'JIS',
                    'unit_weight' => '0.300',
                    'status' => 'active',
                ],
                [
                    'code' => 'LY027',
                    'name' => 'Flange X',
                    'aisi' => 'SCS 14 A',
                    'standard' => 'JIS',
                    'unit_weight' => '1.980',
                    'status' => 'active',
                ],
                [
                    'code' => 'LY028',
                    'name' => 'Elbow 90',
                    'aisi' => 'SCS 13 A',
                    'standard' => 'JIS',
                    'unit_weight' => '0.450',
                    'status' => 'active',
                ],
            ])
        );

        $bulkResponse = $this->actingAs($user)
            ->postJson(route('lost-wax.work-orders.bulk.store'), [
                'rows' => [
                    [
                        'et_code' => 'LWDEMO001',
                        'item_code' => 'LY026',
                        'po_number' => 'PO-DEMO-001',
                        'customer_name' => 'Demo Customer A',
                        'po_quantity' => 1000,
                        'stock_quantity' => 500,
                        'net_requirement_quantity' => 500,
                        'family_code' => '1',
                        'status' => 'draft',
                        'notes' => 'UAT Demo 1',
                        'due_date' => '',
                    ],
                    [
                        'et_code' => 'LWDEMO002',
                        'item_code' => 'LY027',
                        'po_number' => 'PO-DEMO-002',
                        'customer_name' => 'Demo Customer B',
                        'po_quantity' => 500,
                        'stock_quantity' => 100,
                        'net_requirement_quantity' => 400,
                        'family_code' => '1',
                        'status' => 'draft',
                        'notes' => 'UAT Demo 2',
                        'due_date' => '',
                    ],
                    [
                        'et_code' => 'LWDEMO003',
                        'item_code' => 'LY028',
                        'po_number' => 'PO-DEMO-003',
                        'customer_name' => 'Demo Customer C',
                        'po_quantity' => 2000,
                        'stock_quantity' => 1000,
                        'net_requirement_quantity' => 1000,
                        'family_code' => '2',
                        'status' => 'draft',
                        'notes' => 'UAT Demo 3',
                        'due_date' => '',
                    ],
                ],
            ]);

        $bulkResponse->assertOk();
        $bulkResponse->assertJson(['success' => true]);

        $this->assertDatabaseCount('lost_wax_work_orders', 3);
        $this->assertDatabaseHas('lost_wax_work_orders', [
            'et_code' => 'LWDEMO001',
            'po_quantity' => 1000,
            'stock_quantity' => 500,
            'net_requirement_quantity' => 500,
            'family_code' => '1',
        ]);
        $this->assertDatabaseHas('lost_wax_work_orders', [
            'et_code' => 'LWDEMO002',
            'po_quantity' => 500,
            'stock_quantity' => 100,
            'net_requirement_quantity' => 400,
            'family_code' => '1',
        ]);
        $this->assertDatabaseHas('lost_wax_work_orders', [
            'et_code' => 'LWDEMO003',
            'po_quantity' => 2000,
            'stock_quantity' => 1000,
            'net_requirement_quantity' => 1000,
            'family_code' => '2',
        ]);

        // Item references created
        $this->assertDatabaseHas('lost_wax_item_references', ['master_item_key' => 'LY026']);
        $this->assertDatabaseHas('lost_wax_item_references', ['master_item_key' => 'LY027']);
        $this->assertDatabaseHas('lost_wax_item_references', ['master_item_key' => 'LY028']);

        $step1 = 'PASS';

        // ==========================================
        // UAT STEP 2: VERIFY WORK ORDER INDEX
        // ==========================================
        $this->actingAs($user)
            ->get(route('lost-wax.work-orders.index'))
            ->assertOk()
            ->assertSee('LWDEMO001')
            ->assertSee('LWDEMO002')
            ->assertSee('LWDEMO003');

        $wo1 = LostWaxWorkOrder::where('et_code', 'LWDEMO001')->firstOrFail();
        $wo2 = LostWaxWorkOrder::where('et_code', 'LWDEMO002')->firstOrFail();
        $wo3 = LostWaxWorkOrder::where('et_code', 'LWDEMO003')->firstOrFail();

        $step2 = 'PASS';

        // ==========================================
        // UAT STEP 3: WORK ORDER PLAN FOR LWDEMO001
        // ==========================================
        $this->actingAs($user)
            ->post(route('lost-wax.work-orders.plans.store', $wo1), [
                'wave_number' => 1,
                'plan_type' => 'initial',
                'planned_quantity' => 705,
                'status' => 'planned',
            ])
            ->assertSessionHasNoErrors();

        $plan1 = LostWaxWorkOrderPlan::where('work_order_id', $wo1->id)->first();
        $this->assertNotNull($plan1);
        $this->assertSame(705, $plan1->planned_quantity);
        $this->assertSame('initial', $plan1->plan_type);

        // Create plan for LWDEMO002 (for variable tree test)
        $this->actingAs($user)
            ->post(route('lost-wax.work-orders.plans.store', $wo2), [
                'wave_number' => 1,
                'plan_type' => 'initial',
                'planned_quantity' => 449,
                'status' => 'planned',
            ])
            ->assertSessionHasNoErrors();

        $plan2 = LostWaxWorkOrderPlan::where('work_order_id', $wo2->id)->first();
        $this->assertNotNull($plan2);
        $this->assertSame(449, $plan2->planned_quantity);

        $step3 = 'PASS';

        // ==========================================
        // UAT STEP 4: WIP BEFORE TREE
        // ==========================================
        // LWDEMO001: assembly output = 450 (target from 705)
        $this->actingAs($user)
            ->post(route('lost-wax.work-orders.wip.store', $wo1), [
                'stage' => 'assembly',
                'quantity' => 450,
                'status' => 'recorded',
                'produced_at' => now()->format('Y-m-d'),
            ])
            ->assertSessionHasNoErrors();

        // LWDEMO002: assembly output = 449 (uneven, for variable tree test)
        $this->actingAs($user)
            ->post(route('lost-wax.work-orders.wip.store', $wo2), [
                'stage' => 'assembly',
                'quantity' => 449,
                'status' => 'recorded',
                'produced_at' => now()->format('Y-m-d'),
            ])
            ->assertSessionHasNoErrors();

        // LWDEMO003: assembly output = 900
        $this->actingAs($user)
            ->post(route('lost-wax.work-orders.wip.store', $wo3), [
                'stage' => 'assembly',
                'quantity' => 900,
                'status' => 'recorded',
                'produced_at' => now()->format('Y-m-d'),
            ])
            ->assertSessionHasNoErrors();

        $wo1->load('wipEntries');
        $wo2->load('wipEntries');
        $wo3->load('wipEntries');

        $this->assertSame(450, $wo1->assembly_output_quantity);
        $this->assertSame(449, $wo2->assembly_output_quantity);
        $this->assertSame(900, $wo3->assembly_output_quantity);

        $step4 = 'PASS';

        // ==========================================
        // UAT STEP 5: TREE GENERATION — LWDEMO001
        // ==========================================
        // 450 pcs / 15 = 30 trees
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 8, 11));

        $this->actingAs($user)
            ->get(route('lost-wax.trees.generate', $plan1))
            ->assertOk()
            ->assertSee('450')
            ->assertSee('30');

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan1), [
                'default_qty' => 15,
                'quantities' => array_fill(0, 30, 15),
                'family_code' => '1',
            ])
            ->assertRedirect()
            ->assertSessionMissing('error');

        $treesW01 = LostWaxTree::where('work_order_id', $wo1->id)
            ->orderBy('tree_number')
            ->get();

        $this->assertCount(30, $treesW01);
        $this->assertSame(15, $treesW01[0]->quantity);
        $this->assertSame(15, $treesW01[29]->quantity);
        $this->assertSame(450, $treesW01->sum('quantity'));

        // Verify tree numbers
        $this->assertSame(1, $treesW01[0]->tree_number);
        $this->assertSame(30, $treesW01[29]->tree_number);

        $step5 = 'PASS';

        // ==========================================
        // UAT STEP 6: BARCODE SEQUENCE — LWDEMO001
        // ==========================================
        // Family 1, Date 11-08-2026
        // Expected: 1110826001 ... 1110826030
        $this->assertSame('1110826001', $treesW01[0]->barcode);
        $this->assertSame('1110826030', $treesW01[29]->barcode);

        // Verify barcode format
        foreach ($treesW01 as $i => $tree) {
            $this->assertStringStartsWith('1', $tree->barcode);
            $this->assertStringContainsString('110826', $tree->barcode);
            $expectedSeq = str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
            $this->assertStringEndsWith($expectedSeq, $tree->barcode);
            $this->assertSame(10, strlen($tree->barcode));
            $this->assertSame('1', $tree->family_code);
            $this->assertSame($i + 1, $tree->daily_sequence);
        }

        $step6 = 'PASS';

        // ==========================================
        // UAT STEP 7: VARIABLE TREE QUANTITY — LWDEMO002
        // ==========================================
        // 449 pcs / 15 = 29 trees × 15 + 1 tree × 14
        $quantities = array_merge(
            array_fill(0, 29, 15),
            [14]
        );

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan2), [
                'default_qty' => 15,
                'quantities' => $quantities,
                'family_code' => '1',
            ])
            ->assertRedirect()
            ->assertSessionMissing('error');

        $treesW02 = LostWaxTree::where('work_order_id', $wo2->id)
            ->orderBy('tree_number')
            ->get();

        $this->assertCount(30, $treesW02);
        $this->assertSame(15, $treesW02[0]->quantity);
        $this->assertSame(14, $treesW02[29]->quantity);
        $this->assertSame(449, $treesW02->sum('quantity'));

        $step7 = 'PASS';

        // ==========================================
        // UAT STEP 8: SEQUENCE ACROSS ETs — SAME FAMILY
        // ==========================================
        // LWDEMO001: daily_sequence 001–030 (family 1)
        // LWDEMO002: daily_sequence SHOULD continue at 031 (family 1, same date)
        $this->assertSame(31, $treesW02[0]->daily_sequence);
        $this->assertSame(60, $treesW02[29]->daily_sequence);

        $this->assertSame('1110826031', $treesW02[0]->barcode);
        $this->assertSame('1110826060', $treesW02[29]->barcode);

        $step8 = 'PASS';

        // ==========================================
        // UAT STEP 9: DIFFERENT FAMILY — INDEPENDENT SEQUENCE
        // ==========================================
        // LWDEMO003: family 2, same date
        // Expected: family 2 sequence starts at 001 (independent)
        $plan3 = LostWaxWorkOrderPlan::create([
            'work_order_id' => $wo3->id,
            'wave_number' => 1,
            'plan_type' => 'initial',
            'planned_quantity' => 900,
            'status' => 'planned',
        ]);

        $this->actingAs($user)
            ->post(route('lost-wax.trees.store', $plan3), [
                'default_qty' => 15,
                'quantities' => array_fill(0, 60, 15),
                'family_code' => '2',
            ])
            ->assertRedirect()
            ->assertSessionMissing('error');

        $treesW03 = LostWaxTree::where('work_order_id', $wo3->id)
            ->orderBy('tree_number')
            ->get();

        $this->assertCount(60, $treesW03);

        // Family 2 starts at daily_sequence 1
        $this->assertSame(1, $treesW03[0]->daily_sequence);
        $this->assertSame('2', $treesW03[0]->family_code);
        $this->assertTrue(str_starts_with($treesW03[0]->barcode, '2'));

        // Family 2 goes to 60
        $this->assertSame(60, $treesW03[59]->daily_sequence);

        // Family 1 trees are NOT affected (still 001-060 across LWDEMO001 + LWDEMO002)
        $allFamily1 = LostWaxTree::where('family_code', '1')->orderBy('daily_sequence')->get();
        $this->assertCount(60, $allFamily1);
        $this->assertSame(1, $allFamily1[0]->daily_sequence);
        $this->assertSame(60, $allFamily1[59]->daily_sequence);

        // Family 2 trees have their own sequence
        $allFamily2 = LostWaxTree::where('family_code', '2')->orderBy('daily_sequence')->get();
        $this->assertCount(60, $allFamily2);
        $this->assertSame(1, $allFamily2[0]->daily_sequence);
        $this->assertSame(60, $allFamily2[59]->daily_sequence);

        $step9 = 'PASS';

        // ==========================================
        // UAT STEP 10: TRAVELER
        // ==========================================
        $firstTree = $treesW01[0];

        $travelerResponse = $this->actingAs($user)
            ->get(route('lost-wax.trees.traveler', $firstTree));

        $travelerResponse->assertOk();
        $travelerResponse->assertSee('LOST WAX TRAVELER');
        $travelerResponse->assertSee('LWDEMO001');
        $travelerResponse->assertSee($firstTree->barcode);
        $travelerResponse->assertSee('LY026');
        $travelerResponse->assertSee('Hex Nipple SUS304');
        $travelerResponse->assertSee('SCS 13 A');
        $travelerResponse->assertSee('001');
        $travelerResponse->assertSee('15 PCS');
        $travelerResponse->assertSee('11-08-2026');

        // Barcode image is accessible
        $barcodeResponse = $this->actingAs($user)
            ->get(route('lost-wax.trees.barcode', $firstTree));

        $barcodeResponse->assertOk();
        $barcodeResponse->assertHeader('Content-Type', 'image/png');

        $step10 = 'PASS';

        // ==========================================
        // UAT STEP 11: TREE CORRECTION
        // ==========================================
        $lastTree = $treesW01[29]; // Tree 030, qty 15

        // Correct from 15 to 14
        $this->actingAs($user)
            ->patch(route('lost-wax.trees.update', $lastTree), [
                'quantity' => 14,
            ])
            ->assertSessionHas('success');

        $lastTree->refresh();

        $this->assertSame(14, $lastTree->quantity);
        $this->assertSame('1110826030', $lastTree->barcode); // barcode unchanged
        $this->assertSame(30, $lastTree->tree_number);         // tree identity unchanged
        $this->assertSame('1', $lastTree->family_code);       // family unchanged

        // Total allocated adjusts: was 450, now 449
        $this->assertSame(449, (int) LostWaxTree::where('work_order_id', $wo1->id)->sum('quantity'));

        // Cannot correct to exceed available
        $this->actingAs($user)
            ->patch(route('lost-wax.trees.update', $lastTree), [
                'quantity' => 999,
            ])
            ->assertSessionHas('error');

        $step11 = 'PASS';

        \Carbon\Carbon::setTestNow(null);

        // ==========================================
        // UAT SUMMARY
        // ==========================================
        echo "\n";
        echo "══════════════════════════════════════════\n";
        echo "  PHASE 2 UAT RESULTS\n";
        echo "══════════════════════════════════════════\n";
        echo "  Step  1 — Bulk Work Order Input:   $step1\n";
        echo "  Step  2 — Work Order Index:        $step2\n";
        echo "  Step  3 — Work Order Plan:         $step3\n";
        echo "  Step  4 — WIP Before Tree:         $step4\n";
        echo "  Step  5 — Tree Generation (30×15): $step5\n";
        echo "  Step  6 — Barcode Sequence:        $step6\n";
        echo "  Step  7 — Variable Tree Qty (14):  $step7\n";
        echo "  Step  8 — Multi-ET Same Family:    $step8\n";
        echo "  Step  9 — Different Family:        $step9\n";
        echo "  Step 10 — Traveler:                $step10\n";
        echo "  Step 11 — Tree Correction:         $step11\n";
        echo "══════════════════════════════════════════\n";
        echo "  PHASE 2 UAT STATUS: PASS\n";
        echo "══════════════════════════════════════════\n";
    }
}
