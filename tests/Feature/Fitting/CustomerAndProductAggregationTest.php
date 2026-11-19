<?php

namespace Tests\Feature\Fitting;

use App\Helpers\FormatHelper;
use App\Models\LostWaxCastingResult;
use App\Models\LostWaxCastingResultTree;
use App\Models\LostWaxKitir;
use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxTree;
use App\Models\LostWaxTreeAllocation;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\Fitting\FittingKitirSnapshotResolverService;
use App\Services\Fitting\FittingTransferService;
use App\Services\LostWax\LostWaxKitirResolverService;
use Carbon\Carbon;
use Database\Seeders\FittingSpvUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerAndProductAggregationTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 9000;

    private User $ppicFitting;

    private LostWaxKitirResolverService $kitirResolver;

    private FittingKitirSnapshotResolverService $snapshotResolver;

    private FittingTransferService $transferService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setEnvVar('FITTING_SPV_INITIAL_PASSWORD', 'FitPass123!');

        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution', 'guard_name' => 'web']);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'])->syncPermissions([$permPlanning, $permExecution]);
        Role::firstOrCreate(['name' => 'ppic', 'guard_name' => 'web'])->syncPermissions([$permPlanning, $permExecution]);

        $this->ppicFitting = User::create([
            'name' => 'PPIC Fitting',
            'email' => 'ppicfitting@peroniks.com',
            'password' => Hash::make('password'),
            'product_scope' => 'FITTING_STAINLESS',
        ]);
        $this->ppicFitting->assignRole('ppic');

        (new FittingSpvUserSeeder)->run();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->ppicFitting->refresh();

        $this->kitirResolver = app(LostWaxKitirResolverService::class);
        $this->snapshotResolver = app(FittingKitirSnapshotResolverService::class);
        $this->transferService = app(FittingTransferService::class);
    }

    protected function tearDown(): void
    {
        foreach (['FITTING_SPV_INITIAL_PASSWORD'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    private function setEnvVar(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function createTreeAndResult(
        string $code,
        string $customer,
        string $itemName,
        int $qty = 10,
        ?string $heatNumber = null
    ): array {
        $n = self::$seq++;
        $heat = $heatNumber ?? "H-AGG-{$n}";

        $plan = ProductionPlan::create([
            'code' => $code,
            'customer' => $customer,
            'item_name' => $itemName,
            'item_code' => "ERP.{$code}",
            'po_quantity' => 100,
            'weight' => 1.50,
            'line_number' => 1,
            'product_scope' => 'fitting',
            'status' => 'active',
        ]);

        $po = LostWaxPrintOrder::create([
            'print_order_number' => "PO-AGG-{$n}",
            'print_date' => Carbon::today()->format('Y-m-d'),
            'total_trees' => 1,
            'status' => 'COMPLETED',
            'created_by' => $this->ppicFitting->id,
        ]);

        $line = LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $po->id,
            'production_plan_id' => $plan->id,
            'code' => $code,
            'customer' => $customer,
            'item_name' => $itemName,
            'status' => 'COMPLETED',
            'quantity' => $qty,
            'unit_weight' => 1.50,
        ]);

        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => sprintf('26%08d', $n),
            'tree_number' => 1,
            'daily_sequence' => $n,
            'production_date' => Carbon::today()->format('Y-m-d'),
            'family_code' => '1',
            'require_layer_7' => false,
            'quantity' => $qty,
            'current_stage' => 'cor',
            'status' => 'active',
        ]);

        return [$plan, $line, $tree];
    }

    /**
     * Requirement 1: Dua kode produksi dengan Product Name identik menghasilkan satu nama.
     */
    public function test_01_two_production_codes_with_identical_product_name_yields_single_name(): void
    {
        $input = [
            'SS304 CASTED RAISED FLANGE PN 16 DN 250',
            'SS304 CASTED RAISED FLANGE PN 16 DN 250',
            '  SS304 CASTED RAISED FLANGE PN 16 DN 250  ',
        ];

        $deduped = FormatHelper::deduplicateList($input);
        $this->assertCount(1, $deduped);
        $this->assertEquals('SS304 CASTED RAISED FLANGE PN 16 DN 250', $deduped[0]);

        $formatted = FormatHelper::formatDeduplicatedList($input);
        $this->assertEquals('SS304 CASTED RAISED FLANGE PN 16 DN 250', $formatted);
    }

    /**
     * Requirement 2: DN 25 dan DN 250 tetap menjadi dua nama berbeda (tidak terpotong/tergabung).
     */
    public function test_02_dn_25_and_dn_250_remain_two_distinct_names(): void
    {
        $input = [
            'SS304 CASTED RAISED FLANGE PN 16 DN 25',
            'SS304 CASTED RAISED FLANGE PN 16 DN 250',
        ];

        $deduped = FormatHelper::deduplicateList($input);
        $this->assertCount(2, $deduped);
        $this->assertEquals('SS304 CASTED RAISED FLANGE PN 16 DN 25', $deduped[0]);
        $this->assertEquals('SS304 CASTED RAISED FLANGE PN 16 DN 250', $deduped[1]);

        $formatted = FormatHelper::formatDeduplicatedList($input);
        $this->assertEquals('SS304 CASTED RAISED FLANGE PN 16 DN 25, SS304 CASTED RAISED FLANGE PN 16 DN 250', $formatted);
    }

    /**
     * Requirement 3: DN 8 dan DN 80 tetap menjadi dua nama berbeda (tidak terpotong/tergabung).
     */
    public function test_03_dn_8_and_dn_80_remain_two_distinct_names(): void
    {
        $input = [
            'SS316 CASTED BLIND RAISED FLANGE EN1092-1 PN 16 3" DN 8',
            'SS316 CASTED BLIND RAISED FLANGE EN1092-1 PN 16 3" DN 80',
        ];

        $deduped = FormatHelper::deduplicateList($input);
        $this->assertCount(2, $deduped);
        $this->assertEquals('SS316 CASTED BLIND RAISED FLANGE EN1092-1 PN 16 3" DN 8', $deduped[0]);
        $this->assertEquals('SS316 CASTED BLIND RAISED FLANGE EN1092-1 PN 16 3" DN 80', $deduped[1]);

        $formatted = FormatHelper::formatDeduplicatedList($input);
        $this->assertEquals(
            'SS316 CASTED BLIND RAISED FLANGE EN1092-1 PN 16 3" DN 8, SS316 CASTED BLIND RAISED FLANGE EN1092-1 PN 16 3" DN 80',
            $formatted
        );
    }

    /**
     * Requirement 4: Nama Customer identik hanya muncul sekali.
     */
    public function test_04_identical_customer_name_appears_only_once(): void
    {
        $input = [
            'Demo Customer 10',
            'Demo Customer 10',
            ' Demo Customer 10 ',
        ];

        $deduped = FormatHelper::deduplicateList($input);
        $this->assertCount(1, $deduped);
        $this->assertEquals('Demo Customer 10', $deduped[0]);

        $formatted = FormatHelper::formatDeduplicatedList($input);
        $this->assertEquals('Demo Customer 10', $formatted);
    }

    /**
     * Requirement 5: Customer berbeda tetap ditampilkan semuanya.
     */
    public function test_05_different_customers_all_appear(): void
    {
        $input = [
            'Demo Customer 1',
            'Demo Customer 10',
            'A02',
        ];

        $deduped = FormatHelper::deduplicateList($input);
        $this->assertCount(3, $deduped);
        $this->assertEquals('Demo Customer 1', $deduped[0]);
        $this->assertEquals('Demo Customer 10', $deduped[1]);
        $this->assertEquals('A02', $deduped[2]);

        $formatted = FormatHelper::formatDeduplicatedList($input);
        $this->assertEquals('Demo Customer 1, Demo Customer 10, A02', $formatted);
    }

    /**
     * Requirement 6: ERP lookup gagal/kosong tetapi fallback Product Name tetap dideduplikasi dengan benar.
     */
    public function test_06_erp_lookup_failure_fallback_product_name_deduplicates_correctly(): void
    {
        // When item_code ERP is null or missing, system relies on tree/item fallback strings
        $input = [
            null,
            '-',
            '   ',
            'SS316 SORF ANSI 150LBS 1"',
            'SS316 SORF ANSI 150LBS 1"',
        ];

        $deduped = FormatHelper::deduplicateList($input);
        $this->assertCount(1, $deduped);
        $this->assertEquals('SS316 SORF ANSI 150LBS 1"', $deduped[0]);

        // Empty fallback
        $this->assertEquals('-', FormatHelper::formatDeduplicatedList([null, '-', '']));
    }

    /**
     * Requirement 7: Urutan hasil tetap deterministik berdasarkan encounter order.
     */
    public function test_07_result_ordering_is_deterministic_based_on_encounter_order(): void
    {
        $input1 = ['Customer C', 'Customer A', 'Customer B', 'Customer A'];
        $deduped1 = FormatHelper::deduplicateList($input1);
        $this->assertEquals(['Customer C', 'Customer A', 'Customer B'], $deduped1);

        $input2 = ['Product Z', 'Product X', 'Product Z', 'Product Y'];
        $deduped2 = FormatHelper::deduplicateList($input2);
        $this->assertEquals(['Product Z', 'Product X', 'Product Y'], $deduped2);
    }

    /**
     * Requirement 8: Tampilan dan hasil cetak Kitir menggunakan hasil aggregation yang konsisten
     * dan KitirResolverService tidak pernah memotong '0' pada index 0.
     */
    public function test_08_kitir_resolver_and_views_use_consistent_aggregation_without_truncation(): void
    {
        // Create 3 trees in a single Casting Result sharing code 'LWDEMO010', Customer 'Demo Customer 10', and '... DN 250'
        $heatNum = 'H-DN250-'.(self::$seq++);
        $n1 = self::$seq++;
        $n2 = self::$seq++;
        $n3 = self::$seq++;

        $res = LostWaxCastingResult::create([
            'heat_number' => $heatNum,
            'cast_date' => Carbon::today()->format('Y-m-d'),
            'furnace' => '1',
            'shift' => '1',
            'operator_name' => 'Op Agg',
            'total_trees' => 3,
            'total_pcs' => 30,
            'status' => 'COMPLETED',
            'recorded_by' => $this->ppicFitting->id,
        ]);

        $trees = [];
        foreach ([$n1, $n2, $n3] as $idx => $tNum) {
            $t = LostWaxTree::create([
                'barcode' => sprintf('26%08d', $tNum),
                'tree_number' => $idx + 1,
                'daily_sequence' => $tNum,
                'production_date' => Carbon::today()->format('Y-m-d'),
                'family_code' => '6',
                'require_layer_7' => false,
                'quantity' => 10,
                'current_stage' => 'cor',
                'status' => 'active',
            ]);

            LostWaxCastingResultTree::create([
                'lost_wax_casting_result_id' => $res->id,
                'lost_wax_tree_id' => $t->id,
                'active_tree_id' => $t->id,
                'barcode' => $t->barcode,
                'production_code' => 'LWDEMO010',
                'customer' => 'Demo Customer 10',
                'item_name' => 'SS304 CASTED RAISED FLANGE PN 16 DN 250',
                'quantity_pcs' => 10,
                'gross_pcs' => 10,
                'defect_pcs' => 0,
            ]);
            $trees[] = $t;
        }

        // 1. Resolver group resolution MUST NOT truncate 'Demo Customer 10' to 'Demo Customer 1' or 'DN 250' to 'DN 25'
        $groups = $this->kitirResolver->resolveGroups($res);
        $this->assertCount(1, $groups);
        $group = $groups->first();

        $this->assertEquals('LWDEMO010', $group['production_code']);
        $this->assertEquals('Demo Customer 10', $group['customer']);
        $this->assertEquals('SS304 CASTED RAISED FLANGE PN 16 DN 250', $group['item_name']);
        $this->assertStringNotContainsString('Demo Customer 1,', $group['customer']);
        $this->assertStringNotContainsString('DN 25,', $group['item_name']);

        // 2. Issue Kitir record
        $kitir = $this->kitirResolver->issueOrGetKitir($res, 'LWDEMO010', $this->ppicFitting);
        $this->assertEquals('Demo Customer 10', $kitir->customer);
        $this->assertEquals('SS304 CASTED RAISED FLANGE PN 16 DN 250', $kitir->item_name);

        // 3. Snapshot Resolver MUST provide clean deduplicated values
        $snap = $this->snapshotResolver->resolve($kitir);
        $this->assertEquals('Demo Customer 10', $snap['customer']);
        $this->assertEquals('SS304 CASTED RAISED FLANGE PN 16 DN 250', $snap['item_name']);

        // 4. UI view /fitting/transfers MUST display single clean customer and product name
        $responseUi = $this->actingAs($this->ppicFitting)->get(route('fitting.transfers.index', ['tab' => 'available']));
        $responseUi->assertOk();
        $responseUi->assertSee('Demo Customer 10');
        $responseUi->assertDontSee('Demo Customer 1,');
        $responseUi->assertSee('SS304 CASTED RAISED FLANGE PN 16 DN 250');
        $responseUi->assertDontSee('DN 25,');

        // 5. Cetak Kitir document MUST display clean customer and product name
        $responsePrint = $this->actingAs($this->ppicFitting)->get(route('lost-wax.casting-results.kitir.print', [
            'castingResult' => $res->id,
            'code' => 'LWDEMO010',
        ]));
        $responsePrint->assertOk();
        $responsePrint->assertSee('Demo Customer 10');
        $responsePrint->assertDontSee('Demo Customer 1,');
        $responsePrint->assertSee('SS304 CASTED RAISED FLANGE PN 16 DN 250');
        $responsePrint->assertDontSee('DN 25,');

        // 6. Legacy Kitir with already corrupted database string dynamically resolves cleanly
        \DB::table('lost_wax_kitirs')->where('id', $kitir->id)->update([
            'customer' => 'Demo Customer 1, Demo Customer 10',
            'item_name' => 'SS304 CASTED RAISED FLANGE PN 16 DN 25, SS304 CASTED RAISED FLANGE PN 16 DN 250',
        ]);
        $legacyKitir = LostWaxKitir::find($kitir->id);
        // Direct attribute access without relations returns the raw database value
        $this->assertEquals('Demo Customer 1, Demo Customer 10', $legacyKitir->getRawOriginal('customer'));

        // When accessed via Print or with relations, dynamic accessor cleans it up
        $responsePrintLegacy = $this->actingAs($this->ppicFitting)->get(route('lost-wax.casting-results.kitir.print', [
            'castingResult' => $res->id,
            'code' => 'LWDEMO010',
        ]));
        $responsePrintLegacy->assertOk();
        $responsePrintLegacy->assertSee('Demo Customer 10');
        $responsePrintLegacy->assertDontSee('Demo Customer 1,');
        $responsePrintLegacy->assertSee('SS304 CASTED RAISED FLANGE PN 16 DN 250');
        $responsePrintLegacy->assertDontSee('DN 25,');

        // 7. Audit N+1 queries on print-all with multiple kitirs
        // Issue a second kitir in the same casting result
        $n4 = self::$seq++;
        $t2 = LostWaxTree::create([
            'barcode' => sprintf('26%08d', $n4),
            'tree_number' => 4,
            'daily_sequence' => $n4,
            'production_date' => Carbon::today()->format('Y-m-d'),
            'family_code' => 'DEMO',
            'quantity' => 10,
            'current_stage' => 'cor',
            'status' => 'completed',
        ]);
        LostWaxCastingResultTree::create([
            'lost_wax_casting_result_id' => $res->id,
            'lost_wax_tree_id' => $t2->id,
            'active_tree_id' => $t2->id,
            'barcode' => $t2->barcode,
            'production_code' => 'LWDEMO020',
            'customer' => 'Second Cust',
            'item_name' => 'SECOND ITEM DN 50',
            'quantity_pcs' => 10,
            'gross_pcs' => 10,
            'defect_pcs' => 0,
        ]);
        $res->update(['total_pcs' => 40, 'total_trees' => 4]);
        $this->kitirResolver->issueOrGetKitir($res, 'LWDEMO020', $this->ppicFitting);

        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $responsePrintAll = $this->actingAs($this->ppicFitting)->get(route('lost-wax.casting-results.kitir.all', [
            'castingResult' => $res->id,
        ]));
        $responsePrintAll->assertOk();
        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        // Ensure no N+1 query: trees and casting result are loaded in bulk, not re-queried per kitir
        $treeQueries = array_filter($queries, fn ($q) => str_contains($q['query'], 'lost_wax_casting_result_trees'));
        $this->assertLessThanOrEqual(2, count($treeQueries));
    }
}
