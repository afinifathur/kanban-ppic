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

class AssemblySmartFilterTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'ppic']);
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'operator']);

        $permission = Permission::firstOrCreate(['name' => 'access_planning']);
        Role::findByName('ppic')->givePermissionTo($permission);
        Role::findByName('admin')->givePermissionTo($permission);

        $this->user = User::factory()->create(['product_scope' => 'FITTING_STAINLESS']);
        $this->user->assignRole('ppic');
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

    protected function createAssemblyLine(ProductionPlan $plan, array $orderAttrs = [], array $lineAttrs = []): LostWaxPrintOrderLine
    {
        static $seq = 1;
        $order = LostWaxPrintOrder::create(array_merge([
            'print_order_number' => sprintf('PC-20260903-%04d', $seq++),
            'scheduled_date' => '2026-09-03',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ], $orderAttrs));

        return LostWaxPrintOrderLine::create(array_merge([
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
        ], $lineAttrs));
    }

    /** 1. Filter partial Item Name */
    public function test_filter_partial_item_name(): void
    {
        $plan1 = $this->createPlan(['item_name' => 'SS304 ELBOW 90 F/F BSP 3/4"']);
        $line1 = $this->createAssemblyLine($plan1, ['print_order_number' => 'PC-ELBOW-01']);

        $plan2 = $this->createPlan(['item_name' => 'SS316 TEE 90 F/F BSP 1"']);
        $line2 = $this->createAssemblyLine($plan2, ['print_order_number' => 'PC-TEE-02']);

        $response = $this->actingAs($this->user)
            ->get(route('lost-wax.assemblies.index', ['search' => 'ELBOW']));

        $response->assertOk();
        $response->assertSee('PC-ELBOW-01');
        $response->assertDontSee('PC-TEE-02');
    }

    /** 2. Filter partial No Perintah */
    public function test_filter_partial_no_perintah(): void
    {
        $plan1 = $this->createPlan();
        $line1 = $this->createAssemblyLine($plan1, ['print_order_number' => 'PC-20260903-8888']);

        $plan2 = $this->createPlan();
        $line2 = $this->createAssemblyLine($plan2, ['print_order_number' => 'PC-20260903-9999']);

        $response = $this->actingAs($this->user)
            ->get(route('lost-wax.assemblies.index', ['search' => '8888']));

        $response->assertOk();
        $response->assertSee('PC-20260903-8888');
        $response->assertDontSee('PC-20260903-9999');
    }

    /** 3. Filter partial Production Code */
    public function test_filter_partial_production_code(): void
    {
        $plan1 = $this->createPlan(['code' => '268KS758']);
        $line1 = $this->createAssemblyLine($plan1, ['print_order_number' => 'PC-20260903-0001']);

        $plan2 = $this->createPlan(['code' => '100OTHER']);
        $line2 = $this->createAssemblyLine($plan2, ['print_order_number' => 'PC-20260903-0002']);

        $response = $this->actingAs($this->user)
            ->get(route('lost-wax.assemblies.index', ['code' => 'KS758']));

        $response->assertOk();
        $response->assertSee('PC-20260903-0001');
        $response->assertDontSee('PC-20260903-0002');
    }

    /** 4. "758" menemukan beberapa production code yang mengandung 758 */
    public function test_filter_758_finds_multiple_production_codes(): void
    {
        $plan1 = $this->createPlan(['code' => '268KS758']);
        $line1 = $this->createAssemblyLine($plan1, ['print_order_number' => 'PC-20260903-0001']);

        $plan2 = $this->createPlan(['code' => '268CB758']);
        $line2 = $this->createAssemblyLine($plan2, ['print_order_number' => 'PC-20260903-0002']);

        $plan3 = $this->createPlan(['code' => '267AB758']);
        $line3 = $this->createAssemblyLine($plan3, ['print_order_number' => 'PC-20260903-0003']);

        $plan4 = $this->createPlan(['code' => '999NOMATCH']);
        $line4 = $this->createAssemblyLine($plan4, ['print_order_number' => 'PC-20260903-0004']);

        $response = $this->actingAs($this->user)
            ->get(route('lost-wax.assemblies.index', ['code' => '758']));

        $response->assertOk();
        $response->assertSee('PC-20260903-0001');
        $response->assertSee('PC-20260903-0002');
        $response->assertSee('PC-20260903-0003');
        $response->assertDontSee('PC-20260903-0004');
    }

    /** 5. Filter partial Customer Code */
    public function test_filter_partial_customer_code(): void
    {
        $plan1 = $this->createPlan(['customer' => 'CUST-ABC-01']);
        $line1 = $this->createAssemblyLine($plan1, ['print_order_number' => 'PC-CUST-01']);

        $plan2 = $this->createPlan(['customer' => 'CUST-XYZ-02']);
        $line2 = $this->createAssemblyLine($plan2, ['print_order_number' => 'PC-CUST-02']);

        $response = $this->actingAs($this->user)
            ->get(route('lost-wax.assemblies.index', ['customer' => 'ABC']));

        $response->assertOk();
        $response->assertSee('PC-CUST-01');
        $response->assertDontSee('PC-CUST-02');
    }

    /** 6. Filter partial Size */
    public function test_filter_partial_size(): void
    {
        $plan1 = $this->createPlan(['size' => '1/2"']);
        $line1 = $this->createAssemblyLine($plan1, ['print_order_number' => 'PC-SIZE-01']);

        $plan2 = $this->createPlan(['size' => '2"']);
        $line2 = $this->createAssemblyLine($plan2, ['print_order_number' => 'PC-SIZE-02']);

        $response = $this->actingAs($this->user)
            ->get(route('lost-wax.assemblies.index', ['size' => '1/2']));

        $response->assertOk();
        $response->assertSee('PC-SIZE-01');
        $response->assertDontSee('PC-SIZE-02');
    }

    /** 7. Kombinasi dua filter menggunakan AND */
    public function test_filter_two_fields_combination_and(): void
    {
        // Matches customer ABC and size 1/2"
        $plan1 = $this->createPlan(['customer' => 'ABC-CORP', 'size' => '1/2"']);
        $line1 = $this->createAssemblyLine($plan1, ['print_order_number' => 'PC-COMB-01']);

        // Matches customer ABC but size 2"
        $plan2 = $this->createPlan(['customer' => 'ABC-CORP', 'size' => '2"']);
        $line2 = $this->createAssemblyLine($plan2, ['print_order_number' => 'PC-COMB-02']);

        // Matches size 1/2" but customer XYZ
        $plan3 = $this->createPlan(['customer' => 'XYZ-INC', 'size' => '1/2"']);
        $line3 = $this->createAssemblyLine($plan3, ['print_order_number' => 'PC-COMB-03']);

        $response = $this->actingAs($this->user)
            ->get(route('lost-wax.assemblies.index', [
                'customer' => 'ABC',
                'size' => '1/2',
            ]));

        $response->assertOk();
        $response->assertSee('PC-COMB-01');
        $response->assertDontSee('PC-COMB-02');
        $response->assertDontSee('PC-COMB-03');
    }

    /** 8. Kombinasi tiga / empat filter */
    public function test_filter_multi_fields_combination(): void
    {
        $plan1 = $this->createPlan([
            'item_name' => 'SS304 ELBOW 90 F/F BSP 3/4"',
            'code' => '268KS758',
            'customer' => 'CUST-ABC',
            'size' => '3/4"',
        ]);
        $line1 = $this->createAssemblyLine($plan1, ['print_order_number' => 'PC-MULTI-01']);

        $plan2 = $this->createPlan([
            'item_name' => 'SS304 ELBOW 90 F/F BSP 3/4"',
            'code' => '268CB758',
            'customer' => 'CUST-XYZ',
            'size' => '3/4"',
        ]);
        $line2 = $this->createAssemblyLine($plan2, ['print_order_number' => 'PC-MULTI-02']);

        $response = $this->actingAs($this->user)
            ->get(route('lost-wax.assemblies.index', [
                'search' => 'ELBOW',
                'code' => '758',
                'customer' => 'ABC',
                'size' => '3/4',
            ]));

        $response->assertOk();
        $response->assertSee('PC-MULTI-01');
        $response->assertDontSee('PC-MULTI-02');
    }

    /** 9. Case-insensitive matching */
    public function test_filter_case_insensitive(): void
    {
        $plan = $this->createPlan([
            'item_name' => 'SS304 ELBOW 90 F/F BSP 3/4"',
            'code' => '268KS758',
            'customer' => 'ABC-CORP',
        ]);
        $line = $this->createAssemblyLine($plan, ['print_order_number' => 'PC-CASE-01']);

        $response = $this->actingAs($this->user)
            ->get(route('lost-wax.assemblies.index', [
                'search' => 'elbow',
                'code' => 'ks758',
                'customer' => 'abc',
            ]));

        $response->assertOk();
        $response->assertSee('PC-CASE-01');
    }

    /** 10. No-result produces clean empty state */
    public function test_filter_no_result(): void
    {
        $plan = $this->createPlan();
        $line = $this->createAssemblyLine($plan, ['print_order_number' => 'PC-EXIST-01']);

        $response = $this->actingAs($this->user)
            ->get(route('lost-wax.assemblies.index', ['code' => 'NONEXISTENT999']));

        $response->assertOk();
        $response->assertDontSee('PC-EXIST-01');
        $response->assertSee('Tidak ada hasil cetak yang tersedia untuk dirangkai');
    }

    /** 11. Reset URL is present and clean */
    public function test_reset_button_clears_filters(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('lost-wax.assemblies.index', ['code' => '758']));

        $response->assertOk();
        $response->assertSee(route('lost-wax.assemblies.index'));
    }

    /** 12. Pagination mempertahankan semua filter */
    public function test_pagination_retains_all_filters(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $plan = $this->createPlan(['code' => sprintf('CODE758-%02d', $i)]);
            $this->createAssemblyLine($plan, ['print_order_number' => sprintf('PC-PAGINATE-%02d', $i)]);
        }

        $responsePage1 = $this->actingAs($this->user)
            ->get(route('lost-wax.assemblies.index', [
                'code' => '758',
                'customer' => 'CUST',
                'size' => '3/4',
            ]));

        $responsePage1->assertOk();
        $responsePage1->assertSee('page=2');
        $responsePage1->assertSee('code=758');
        $responsePage1->assertSee('customer=CUST');
        $responsePage1->assertSee('size=3%2F4');
    }

    /** 13. Suggestion hanya mengembalikan kandidat relevan */
    public function test_suggestions_contain_relevant_candidates(): void
    {
        $plan1 = $this->createPlan(['code' => 'SUGG-CODE-1', 'customer' => 'SUGG-CUST-1']);
        $this->createAssemblyLine($plan1);

        $response = $this->actingAs($this->user)
            ->get(route('lost-wax.assemblies.index'));

        $response->assertOk();
        $response->assertSee('value="SUGG-CODE-1"', false);
        $response->assertSee('value="SUGG-CUST-1"', false);
    }

    /** 14. Suggestion memiliki limit */
    public function test_suggestions_have_limit(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $plan = $this->createPlan(['code' => sprintf('LIMIT-CODE-%03d', $i)]);
            $this->createAssemblyLine($plan);
        }

        $response = $this->actingAs($this->user)
            ->get(route('lost-wax.assemblies.index'));

        $response->assertOk();
        $codeSuggestions = $response->viewData('codeSuggestions');
        $this->assertLessThanOrEqual(50, $codeSuggestions->count());
    }

    /** 15. Tidak terjadi N+1 query */
    public function test_no_n_plus_one_query(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $plan = $this->createPlan(['code' => sprintf('N1-CODE-%02d', $i)]);
            $this->createAssemblyLine($plan);
        }

        DB::enableQueryLog();

        $response = $this->actingAs($this->user)
            ->get(route('lost-wax.assemblies.index', ['code' => 'N1']));

        $response->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThan(20, count($queries), 'Query count exceeds expectation indicating potential N+1');
    }

    /** 16. Existing assembly result tetap sama ketika filter kosong */
    public function test_unfiltered_baseline_remains_intact(): void
    {
        $plan1 = $this->createPlan(['code' => 'BASE-01']);
        $line1 = $this->createAssemblyLine($plan1, ['print_order_number' => 'PC-BASE-01']);

        $plan2 = $this->createPlan(['code' => 'BASE-02']);
        $line2 = $this->createAssemblyLine($plan2, ['print_order_number' => 'PC-BASE-02']);

        $response = $this->actingAs($this->user)
            ->get(route('lost-wax.assemblies.index'));

        $response->assertOk();
        $response->assertSee('PC-BASE-01');
        $response->assertSee('PC-BASE-02');
    }
}
