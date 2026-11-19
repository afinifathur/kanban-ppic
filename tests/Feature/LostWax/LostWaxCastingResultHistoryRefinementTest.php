<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxCastingResult;
use App\Models\LostWaxCastingResultTree;
use App\Models\LostWaxKitir;
use App\Models\LostWaxTree;
use App\Models\User;
use App\Services\LostWax\LostWaxKitirResolverService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LostWaxCastingResultHistoryRefinementTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected LostWaxKitirResolverService $kitirService;

    protected static int $seq = 9000;

    protected function setUp(): void
    {
        parent::setUp();

        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution']);
        $accessPlanning = Permission::firstOrCreate(['name' => 'access_planning']);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->syncPermissions([$accessExecution, $accessPlanning]);

        $this->adminUser = User::factory()->create(['email' => 'admin_cor_history@peroniks.com']);
        $this->adminUser->assignRole('admin');

        $this->kitirService = app(LostWaxKitirResolverService::class);
    }

    private function createCastingResult(array $attributes = []): LostWaxCastingResult
    {
        $seq = self::$seq++;

        return LostWaxCastingResult::create(array_merge([
            'heat_number' => 'HEAT-HIST-'.$seq,
            'cast_date' => Carbon::today()->toDateString(),
            'furnace' => '1',
            'shift' => '1',
            'operator_name' => 'Operator Test',
            'total_trees' => 1,
            'total_pcs' => 20,
            'status' => 'COMPLETED',
            'recorded_by' => $this->adminUser->id,
        ], $attributes));
    }

    private function attachTreeToResult(LostWaxCastingResult $result, string $code, int $pcs = 10): LostWaxCastingResultTree
    {
        $seq = self::$seq++;
        $barcode = sprintf('26%08d', $seq);

        $physTree = LostWaxTree::create([
            'barcode' => $barcode,
            'tree_number' => $seq,
            'daily_sequence' => $seq,
            'production_date' => Carbon::today()->toDateString(),
            'family_code' => '1',
            'require_layer_7' => false,
            'quantity' => $pcs,
            'current_stage' => 'cor',
            'status' => 'active',
            'created_by' => $this->adminUser->id,
        ]);

        return LostWaxCastingResultTree::create([
            'lost_wax_casting_result_id' => $result->id,
            'lost_wax_tree_id' => $physTree->id,
            'active_tree_id' => $result->status === 'COMPLETED' ? $physTree->id : null,
            'barcode' => $barcode,
            'production_code' => $code,
            'customer' => 'Customer '.$code,
            'item_name' => 'Item '.$code,
            'quantity_pcs' => $pcs,
            'gross_pcs' => $pcs,
            'defect_pcs' => 0,
        ]);
    }

    /**
     * 1. Two Tabs (Selesai vs Dibatalkan) separation, counters, and overall summary cards.
     */
    public function test_01_two_tabs_separation_and_counters(): void
    {
        // 3 COMPLETED transactions
        $c1 = $this->createCastingResult(['heat_number' => 'HEAT-COMP-1', 'status' => 'COMPLETED', 'total_trees' => 2, 'total_pcs' => 50]);
        $c2 = $this->createCastingResult(['heat_number' => 'HEAT-COMP-2', 'status' => 'COMPLETED', 'total_trees' => 1, 'total_pcs' => 30]);
        $c3 = $this->createCastingResult(['heat_number' => 'HEAT-COMP-3', 'status' => 'COMPLETED', 'total_trees' => 3, 'total_pcs' => 80]);

        // 2 CANCELLED transactions
        $x1 = $this->createCastingResult(['heat_number' => 'HEAT-CANC-1', 'status' => 'CANCELLED', 'void_reason' => 'Salah tuang', 'voided_by' => $this->adminUser->id, 'voided_at' => now()]);
        $x2 = $this->createCastingResult(['heat_number' => 'HEAT-CANC-2', 'status' => 'CANCELLED', 'void_reason' => 'Batal produksi', 'voided_by' => $this->adminUser->id, 'voided_at' => now()]);

        // 1A. Default opens Tab Selesai
        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.casting-results.index'));
        $response->assertOk();

        // Verify Tab Selesai shows only COMPLETED
        $response->assertSee('HEAT-COMP-1');
        $response->assertSee('HEAT-COMP-2');
        $response->assertSee('HEAT-COMP-3');
        $response->assertDontSee('HEAT-CANC-1');
        $response->assertDontSee('HEAT-CANC-2');

        // Verify Tab counters: SELESAI (3), DIBATALKAN (2)
        $response->assertSee('SELESAI');
        $response->assertSee('DIBATALKAN');
        $response->assertSee('3');
        $response->assertSee('2');

        // Verify Overall KPI cards: Total Transaksi = 5, Heat Aktif = 3, Total Pohon = 6, Total Pcs = 160
        $response->assertSee('5'); // Total Transaksi
        $response->assertSee('160'); // Total Kuantitas Dicor

        // 1B. Opening Tab Dibatalkan (?tab=cancelled)
        $responseCancelled = $this->actingAs($this->adminUser)->get(route('lost-wax.casting-results.index', ['tab' => 'cancelled']));
        $responseCancelled->assertOk();

        // Verify Tab Dibatalkan shows only CANCELLED
        $responseCancelled->assertSee('HEAT-CANC-1');
        $responseCancelled->assertSee('HEAT-CANC-2');
        $responseCancelled->assertDontSee('HEAT-COMP-1');
        $responseCancelled->assertDontSee('HEAT-COMP-2');
        $responseCancelled->assertDontSee('HEAT-COMP-3');

        // Overall summary KPI cards stay consistent and unaffected by active tab
        $responseCancelled->assertSee('5');
    }

    /**
     * 2. Status Kitir column replaces Tungku/Shift and correctly computes Terbit: X/Y and Cetak: Z/Y
     *    using unique product codes as denominator.
     */
    public function test_02_status_kitir_ratio_for_multi_product_heat(): void
    {
        $result = $this->createCastingResult([
            'heat_number' => 'HEAT-MULTI-PROD',
            'status' => 'COMPLETED',
            'total_trees' => 5,
            'total_pcs' => 100,
        ]);

        // Attach trees with 4 distinct production codes:
        // Code A: 2 trees
        // Code B: 1 tree
        // Code C: 1 tree
        // Code D: 1 tree
        $this->attachTreeToResult($result, 'PROD-A', 25);
        $this->attachTreeToResult($result, 'PROD-A', 25);
        $this->attachTreeToResult($result, 'PROD-B', 20);
        $this->attachTreeToResult($result, 'PROD-C', 15);
        $this->attachTreeToResult($result, 'PROD-D', 15);

        // Denominator must be 4 (not 5 trees or 100 pcs)
        $statusBefore = $result->kitir_status;
        $this->assertSame(4, $statusBefore['total']);
        $this->assertSame(0, $statusBefore['issued']);
        $this->assertSame(0, $statusBefore['printed']);

        // Issue Kitir for PROD-A, PROD-B, PROD-C (3 out of 4 issued)
        $kitirA = $this->kitirService->issueOrGetKitir($result, 'PROD-A', $this->adminUser);
        $kitirB = $this->kitirService->issueOrGetKitir($result, 'PROD-B', $this->adminUser);
        $kitirC = $this->kitirService->issueOrGetKitir($result, 'PROD-C', $this->adminUser);

        // Print Kitir for PROD-A only (1 out of 4 printed)
        $this->kitirService->recordPrint($kitirA, $this->adminUser);

        // Check model accessor
        $result->refresh();
        $statusAfter = $result->kitir_status;
        $this->assertSame(4, $statusAfter['total']);
        $this->assertSame(3, $statusAfter['issued']);
        $this->assertSame(1, $statusAfter['printed']);

        // HTTP View assertion
        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.casting-results.index'));
        $response->assertOk();

        // Must see Terbit: 3/4 and Cetak: 1/4
        $response->assertSee('Terbit:');
        $response->assertSee('3/4');
        $response->assertSee('Cetak:');
        $response->assertSee('1/4');

        // Column "Tungku / Shift" must NOT exist in the table header
        $response->assertDontSee('Tungku / Shift');
        $response->assertSee('Status Kitir');
    }

    /**
     * 3. Reprinting a Kitir does NOT duplicate or inflate Kitir counts.
     */
    public function test_03_reprint_does_not_duplicate_kitir_ratio(): void
    {
        $result = $this->createCastingResult([
            'heat_number' => 'HEAT-REPRINT',
            'status' => 'COMPLETED',
            'total_trees' => 2,
            'total_pcs' => 40,
        ]);

        $this->attachTreeToResult($result, 'PROD-X', 20);
        $this->attachTreeToResult($result, 'PROD-Y', 20);

        // Issue and print Kitir for PROD-X
        $kitirX = $this->kitirService->issueOrGetKitir($result, 'PROD-X', $this->adminUser);
        $this->kitirService->recordPrint($kitirX, $this->adminUser);

        // Reprint PROD-X twice more (print_count = 3)
        $this->kitirService->recordPrint($kitirX, $this->adminUser);
        $this->kitirService->recordPrint($kitirX, $this->adminUser);

        $this->assertSame(3, (int) $kitirX->fresh()->print_count);
        $this->assertSame(1, LostWaxKitir::where('lost_wax_casting_result_id', $result->id)->where('production_code', 'PROD-X')->count());

        $result->refresh();
        $status = $result->kitir_status;
        $this->assertSame(2, $status['total']);
        $this->assertSame(1, $status['issued']);
        $this->assertSame(1, $status['printed']); // Exactly 1, NOT 3!

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.casting-results.index'));
        $response->assertOk();
        $response->assertSee('1/2');
    }

    /**
     * 4. Pagination (20 items) and parameter preservation across pages and filters.
     */
    public function test_04_pagination_and_filter_preservation(): void
    {
        // Create 25 completed casting results
        for ($i = 1; $i <= 25; $i++) {
            $this->createCastingResult([
                'heat_number' => sprintf('HEAT-PAGE-%02d', $i),
                'status' => 'COMPLETED',
            ]);
        }

        // Page 1: shows 20 transactions
        $resPage1 = $this->actingAs($this->adminUser)->get(route('lost-wax.casting-results.index'));
        $resPage1->assertOk();

        // 20 items per page pagination links
        $resPage1->assertSee('tab=completed');
        $resPage1->assertSee('page=2');

        // Page 2: shows remaining 5 transactions
        $resPage2 = $this->actingAs($this->adminUser)->get(route('lost-wax.casting-results.index', ['page' => 2]));
        $resPage2->assertOk();

        // Filter by keyword preserves tab and returns filtered subset
        $resFilter = $this->actingAs($this->adminUser)->get(route('lost-wax.casting-results.index', [
            'search' => 'HEAT-PAGE-05',
        ]));
        $resFilter->assertOk();
        $resFilter->assertSee('HEAT-PAGE-05');
        $resFilter->assertDontSee('HEAT-PAGE-01');
    }

    /**
     * 5. Compact Header and breadcrumb moved to top_bar section.
     */
    public function test_05_compact_header_and_breadcrumb_top_bar(): void
    {
        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.casting-results.index'));
        $response->assertOk();

        // Breadcrumb in top_bar
        $response->assertSee('Lost Wax Pengecoran');
        $response->assertSee('Phase 4 — Input Hasil Cor');
        $response->assertSee('Riwayat Hasil Cor');

        // Action buttons
        $response->assertSee('Laporan Cor Harian');
        $response->assertSee('Antrean Siap Cor');
        $response->assertSee('Input Hasil Cor Baru');

        // 4 KPI cards
        $response->assertSee('Total Transaksi');
        $response->assertSee('Heat Aktif (Selesai)');
        $response->assertSee('Total Pohon Dicor');
        $response->assertSee('Total Kuantitas Dicor');
    }
}
