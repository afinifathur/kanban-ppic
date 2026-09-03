<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\ProductionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PrintOrderSearchTest extends TestCase
{
    use RefreshDatabase;

    protected User $ppicUser;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'ppic']);
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'operator']);

        $permission = Permission::firstOrCreate(['name' => 'access_planning']);
        Role::findByName('ppic')->givePermissionTo($permission);
        Role::findByName('admin')->givePermissionTo($permission);

        $this->ppicUser = User::factory()->create(['product_scope' => 'FITTING_STAINLESS']);
        $this->ppicUser->assignRole('ppic');
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

    protected function createPrintOrderWithLine(ProductionPlan $plan, array $orderAttrs = [], array $lineAttrs = []): LostWaxPrintOrder
    {
        static $seq = 1;
        $order = LostWaxPrintOrder::create(array_merge([
            'print_order_number' => sprintf('PC-20260903-%04d', $seq++),
            'scheduled_date' => '2026-09-03',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ], $orderAttrs));

        LostWaxPrintOrderLine::create(array_merge([
            'lost_wax_print_order_id' => $order->id,
            'production_plan_id' => $plan->id,
            'qty_ordered' => 50,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
        ], $lineAttrs));

        return $order;
    }

    /** 1. Search berdasarkan exact production code */
    public function test_search_by_exact_production_code()
    {
        $plan1 = $this->createPlan(['code' => '268KS758']);
        $order1 = $this->createPrintOrderWithLine($plan1, ['print_order_number' => 'PC-20260903-0001']);

        $plan2 = $this->createPlan(['code' => '100OTHER']);
        $order2 = $this->createPrintOrderWithLine($plan2, ['print_order_number' => 'PC-20260903-0002']);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('lost-wax.print-orders.plans', ['tab' => 'orders', 'search' => '268KS758']));

        $response->assertOk();
        $response->assertSee('PC-20260903-0001');
        $response->assertDontSee('PC-20260903-0002');
    }

    /** 2. Search berdasarkan partial production code */
    public function test_search_by_partial_production_code()
    {
        $plan = $this->createPlan(['code' => '268KS758']);
        $order = $this->createPrintOrderWithLine($plan, ['print_order_number' => 'PC-20260903-0001']);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('lost-wax.print-orders.plans', ['tab' => 'orders', 'search' => 'KS758']));

        $response->assertOk();
        $response->assertSee('PC-20260903-0001');
    }

    /** 3. Search "758" menemukan "268KS758" */
    public function test_search_758_finds_268ks758()
    {
        $plan = $this->createPlan(['code' => '268KS758']);
        $order = $this->createPrintOrderWithLine($plan, ['print_order_number' => 'PC-20260903-0004']);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('lost-wax.print-orders.plans', ['tab' => 'orders', 'search' => '758']));

        $response->assertOk();
        $response->assertSee('PC-20260903-0004');
    }

    /** 4. Search "758" menemukan "268CB758" dan "267AB758" */
    public function test_search_758_finds_multiple_matching_production_codes()
    {
        $plan1 = $this->createPlan(['code' => '268KS758']);
        $order1 = $this->createPrintOrderWithLine($plan1, ['print_order_number' => 'PC-20260903-0004']);

        $plan2 = $this->createPlan(['code' => '268CB758']);
        $order2 = $this->createPrintOrderWithLine($plan2, ['print_order_number' => 'PC-20260902-0002']);

        $plan3 = $this->createPlan(['code' => '267AB758']);
        $order3 = $this->createPrintOrderWithLine($plan3, ['print_order_number' => 'PC-20260831-0006']);

        $plan4 = $this->createPlan(['code' => '999XX000']);
        $order4 = $this->createPrintOrderWithLine($plan4, ['print_order_number' => 'PC-20260830-0099']);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('lost-wax.print-orders.plans', ['tab' => 'orders', 'search' => '758']));

        $response->assertOk();
        $response->assertSee('PC-20260903-0004');
        $response->assertSee('PC-20260902-0002');
        $response->assertSee('PC-20260831-0006');
        $response->assertDontSee('PC-20260830-0099');
    }

    /** 5. Search berdasarkan sebagian nama item */
    public function test_search_by_partial_item_name()
    {
        $plan1 = $this->createPlan(['item_name' => 'SS304 ELBOW 90 F/F BSP 3/4"']);
        $order1 = $this->createPrintOrderWithLine($plan1, ['print_order_number' => 'PC-20260903-0010']);

        $plan2 = $this->createPlan(['item_name' => 'SS304 TEE 90 F/F BSP 1"']);
        $order2 = $this->createPrintOrderWithLine($plan2, ['print_order_number' => 'PC-20260903-0020']);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('lost-wax.print-orders.plans', ['tab' => 'orders', 'search' => 'SS304 ELBOW']));

        $response->assertOk();
        $response->assertSee('PC-20260903-0010');
        $response->assertDontSee('PC-20260903-0020');
    }

    /** 6. Search case-insensitive */
    public function test_search_case_insensitive()
    {
        $plan = $this->createPlan(['item_name' => 'SS304 ELBOW 90 F/F BSP 3/4"', 'code' => '268ks758']);
        $order = $this->createPrintOrderWithLine($plan, ['print_order_number' => 'PC-20260903-0015']);

        $response1 = $this->actingAs($this->ppicUser)
            ->get(route('lost-wax.print-orders.plans', ['tab' => 'orders', 'search' => 'ss304 elbow']));
        $response1->assertOk();
        $response1->assertSee('PC-20260903-0015');

        $response2 = $this->actingAs($this->ppicUser)
            ->get(route('lost-wax.print-orders.plans', ['tab' => 'orders', 'search' => 'KS758']));
        $response2->assertOk();
        $response2->assertSee('PC-20260903-0015');
    }

    /** 7. Print Order tetap muncul jika hanya satu item di dalamnya yang match (multi-item print order) */
    public function test_print_order_appears_if_at_least_one_item_matches()
    {
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260902-0002',
            'scheduled_date' => '2026-09-02',
            'status' => 'ISSUED',
            'created_by' => $this->ppicUser->id,
        ]);

        // Add 5 non-matching items
        for ($i = 1; $i <= 5; $i++) {
            $unmatchedPlan = $this->createPlan([
                'code' => "UNMATCH{$i}",
                'item_name' => "UNMATCH ITEM {$i}",
            ]);
            LostWaxPrintOrderLine::create([
                'lost_wax_print_order_id' => $order->id,
                'production_plan_id' => $unmatchedPlan->id,
                'qty_ordered' => 10,
                'code' => $unmatchedPlan->code,
                'customer' => $unmatchedPlan->customer,
                'item_name' => $unmatchedPlan->item_name,
            ]);
        }

        // Add 1 matching item
        $matchingPlan = $this->createPlan([
            'code' => '268KS758',
            'item_name' => 'MATCHING ITEM TARGET',
        ]);
        LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $order->id,
            'production_plan_id' => $matchingPlan->id,
            'qty_ordered' => 10,
            'code' => $matchingPlan->code,
            'customer' => $matchingPlan->customer,
            'item_name' => $matchingPlan->item_name,
        ]);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('lost-wax.print-orders.plans', ['tab' => 'orders', 'search' => '758']));

        $response->assertOk();
        $response->assertSee('PC-20260902-0002');
        $response->assertSee('6 item');
    }

    /** 8. Search tidak match menghasilkan empty state normal */
    public function test_search_unmatched_shows_normal_empty_state()
    {
        $plan = $this->createPlan(['code' => '268KS758']);
        $order = $this->createPrintOrderWithLine($plan, ['print_order_number' => 'PC-20260903-0001']);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('lost-wax.print-orders.plans', ['tab' => 'orders', 'search' => 'NONEXISTENT999']));

        $response->assertOk();
        $response->assertDontSee('PC-20260903-0001');
        $response->assertSee('Tidak ada dokumen Perintah Cetak ditemukan');
    }

    /** 9. Search tetap bekerja dengan pagination */
    public function test_search_works_with_pagination()
    {
        for ($i = 1; $i <= 20; $i++) {
            $plan = $this->createPlan(['code' => sprintf('CODE758-%02d', $i)]);
            $this->createPrintOrderWithLine($plan, ['print_order_number' => sprintf('PC-PAGINATE-%02d', $i)]);
        }

        $responsePage1 = $this->actingAs($this->ppicUser)
            ->get(route('lost-wax.print-orders.plans', ['tab' => 'orders', 'search' => '758']));

        $responsePage1->assertOk();
        $responsePage1->assertSee('orders_page=2');
        $responsePage1->assertSee('search=758');

        $responsePage2 = $this->actingAs($this->ppicUser)
            ->get(route('lost-wax.print-orders.plans', ['tab' => 'orders', 'search' => '758', 'orders_page' => 2]));

        $responsePage2->assertOk();
        $responsePage2->assertSee('search=758');
    }

    /** 10. Reset menghapus semua filter */
    public function test_reset_clears_filters()
    {
        $response = $this->actingAs($this->ppicUser)
            ->get(route('lost-wax.print-orders.plans', ['tab' => 'orders']));

        $response->assertOk();
        $response->assertSee(route('lost-wax.print-orders.plans', ['tab' => 'orders']));
    }

    /** 11. Existing No. Perintah Cetak search tetap bekerja */
    public function test_existing_print_order_number_search_still_works()
    {
        $plan1 = $this->createPlan();
        $order1 = $this->createPrintOrderWithLine($plan1, ['print_order_number' => 'PC-20260903-TARGET']);

        $plan2 = $this->createPlan();
        $order2 = $this->createPrintOrderWithLine($plan2, ['print_order_number' => 'PC-20260903-OTHER']);

        $response = $this->actingAs($this->ppicUser)
            ->get(route('lost-wax.print-orders.plans', ['tab' => 'orders', 'print_order_number' => 'TARGET']));

        $response->assertOk();
        $response->assertSee('PC-20260903-TARGET');
        $response->assertDontSee('PC-20260903-OTHER');
    }

    /** 12. Tab orders tetap dipertahankan */
    public function test_tab_orders_is_retained()
    {
        $response = $this->actingAs($this->ppicUser)
            ->get(route('lost-wax.print-orders.plans', ['tab' => 'orders', 'search' => '758']));

        $response->assertOk();
        $response->assertSee('name="tab" value="orders"', false);
        $response->assertSee('name="search" value="758"', false);
    }

    /** 13. Tidak terjadi N+1 query */
    public function test_no_n_plus_one_query()
    {
        for ($i = 1; $i <= 10; $i++) {
            $plan = $this->createPlan(['code' => sprintf('CODE758-%02d', $i)]);
            $this->createPrintOrderWithLine($plan, ['print_order_number' => sprintf('PC-QUERY-%02d', $i)]);
        }

        DB::enableQueryLog();

        $response = $this->actingAs($this->ppicUser)
            ->get(route('lost-wax.print-orders.plans', ['tab' => 'orders', 'search' => '758']));

        $response->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Query count should remain small and constant regardless of number of print order items
        // Eager loaded creator & lines prevents N+1 per row in table.
        $this->assertLessThan(25, count($queries), 'Query count exceeds expectation indicating potential N+1');
    }
}
