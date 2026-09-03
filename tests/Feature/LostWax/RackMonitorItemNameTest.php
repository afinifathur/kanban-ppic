<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxCoatingRack;
use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxTree;
use App\Models\ProductionPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RackMonitorItemNameTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected LostWaxCoatingRack $rack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->rack = LostWaxCoatingRack::create([
            'rack_number' => 1,
            'label' => 'RAK-01',
            'status' => 'active',
        ]);
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'code' => '268KS758',
            'customer' => 'CUST01',
            'item_code' => 'ITM-001',
            'item_name' => 'SS304 ELBOW 90 F/F BSP 3/4"',
            'aisi' => '304',
            'size' => '3/4"',
            'weight' => 0.75,
            'po_number' => 'PO-001',
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'status' => 'planning',
            'product_scope' => 'FITTING_STAINLESS',
        ], $attributes));
    }

    protected function createPrintOrderLine(ProductionPlan $plan): LostWaxPrintOrderLine
    {
        static $seq = 1;
        $order = LostWaxPrintOrder::create([
            'print_order_number' => sprintf('PC-20260903-%04d', $seq++),
            'scheduled_date' => '2026-09-03',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        return LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $order->id,
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'qty_actual_good' => 50,
            'qty_executed_good' => 50,
        ]);
    }

    protected function createTreeForLine(LostWaxPrintOrderLine $line, array $attributes = []): LostWaxTree
    {
        static $sequence = 1;
        $seq = $sequence++;

        return LostWaxTree::create(array_merge([
            'lost_wax_print_order_line_id' => $line->id,
            'rack_id' => $this->rack->id,
            'barcode' => '99'.str_pad($seq, 7, '0', STR_PAD_LEFT),
            'tree_number' => 1,
            'quantity' => 20,
            'status' => 'generated',
            'require_layer_7' => false,
            'production_date' => '2026-09-03',
            'family_code' => '4',
            'daily_sequence' => $seq,
            'current_stage' => 'layer_1',
            'last_scan_at' => Carbon::now()->subHours(2),
        ], $attributes));
    }

    /** 1. Rack card displays item name */
    public function test_rack_card_displays_item_name(): void
    {
        $plan = $this->createPlan(['item_name' => 'SS304 ELBOW 90 F/F BSP 3/4"', 'code' => '268KS758']);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTreeForLine($line);

        $response = $this->actingAs($this->user)->get(route('lost-wax.rack-monitor.index'));

        $response->assertOk();
        $response->assertSee('SS304 ELBOW 90 F/F BSP 3/4"');
    }

    /** 2. Rack detail data contains item_names, production_codes, and tree details */
    public function test_rack_data_contains_item_names_and_traceability(): void
    {
        $plan = $this->createPlan(['item_name' => 'SS316 BLIND 2"', 'code' => '268CB758']);
        $line = $this->createPrintOrderLine($plan);
        $tree = $this->createTreeForLine($line);

        $response = $this->actingAs($this->user)->get(route('lost-wax.rack-monitor.index'));

        $response->assertOk();
        $viewRacks = $response->viewData('racks');

        $this->assertNotEmpty($viewRacks);
        $rackData = $viewRacks[0];

        // Item name is present in aggregated item_names
        $this->assertArrayHasKey('item_names', $rackData);
        $this->assertArrayHasKey('SS316 BLIND 2"', $rackData['item_names']);

        // Production code is preserved in production_codes for traceability
        $this->assertArrayHasKey('production_codes', $rackData);
        $this->assertArrayHasKey('268CB758', $rackData['production_codes']);

        // Tree detail contains both item_name, production_code, and barcode
        $this->assertNotEmpty($rackData['trees']);
        $firstTree = $rackData['trees'][0];
        $this->assertEquals('SS316 BLIND 2"', $firstTree['item_name']);
        $this->assertEquals('268CB758', $firstTree['production_code']);
        $this->assertEquals($tree->barcode, $firstTree['barcode']);
    }

    /** 3. Multiple items on a single rack are aggregated correctly */
    public function test_mixed_items_on_rack_are_aggregated(): void
    {
        $plan1 = $this->createPlan(['item_name' => 'SS304 ELBOW 90 3/4"', 'code' => 'CODE-A']);
        $line1 = $this->createPrintOrderLine($plan1);
        $tree1 = $this->createTreeForLine($line1);

        $plan2 = $this->createPlan(['item_name' => 'SS316 TEE 1"', 'code' => 'CODE-B']);
        $line2 = $this->createPrintOrderLine($plan2);
        $tree2 = $this->createTreeForLine($line2);

        $response = $this->actingAs($this->user)->get(route('lost-wax.rack-monitor.index'));

        $response->assertOk();
        $viewRacks = $response->viewData('racks');
        $rackData = $viewRacks[0];

        $this->assertCount(2, $rackData['item_names']);
        $this->assertArrayHasKey('SS304 ELBOW 90 3/4"', $rackData['item_names']);
        $this->assertArrayHasKey('SS316 TEE 1"', $rackData['item_names']);

        $response->assertSee('SS304 ELBOW 90 3/4"');
        $response->assertSee('SS316 TEE 1"');
    }

    /** 4. No N+1 query regression with eager-loaded relations */
    public function test_no_n_plus_one_query_in_rack_monitor(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $rack = LostWaxCoatingRack::create([
                'rack_number' => 10 + $i,
                'label' => sprintf('RAK-%02d', 10 + $i),
                'status' => 'active',
            ]);

            $plan = $this->createPlan(['item_name' => "ITEM N1 {$i}", 'code' => "CODE-{$i}"]);
            $line = $this->createPrintOrderLine($plan);

            $this->createTreeForLine($line, ['rack_id' => $rack->id]);
        }

        DB::enableQueryLog();

        $response = $this->actingAs($this->user)->get(route('lost-wax.rack-monitor.index'));

        $response->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Eager-loading ensures query count remains low (< 15) regardless of number of racks
        $this->assertLessThan(15, count($queries), 'Query count exceeds expectation indicating potential N+1');
    }
}
