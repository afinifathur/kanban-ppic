<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\LostWaxTreeAllocation;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWax\LostWaxCastingResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LostWaxDailyCastingReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $ppicUser;

    protected User $unauthorizedUser;

    protected LostWaxCastingResultService $castingResultService;

    protected static int $seq = 9000;

    protected function setUp(): void
    {
        parent::setUp();

        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution']);
        $accessPlanning = Permission::firstOrCreate(['name' => 'access_planning']);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->syncPermissions([$accessExecution, $accessPlanning]);

        $ppicRole = Role::firstOrCreate(['name' => 'ppic']);
        $ppicRole->syncPermissions([$accessPlanning, $accessExecution]);

        $unauthorizedRole = Role::firstOrCreate(['name' => 'sales']);

        $this->adminUser = User::factory()->create(['email' => 'admin_daily_report@peroniks.com']);
        $this->adminUser->assignRole('admin');

        $this->ppicUser = User::factory()->create(['email' => 'ppic_daily_report@peroniks.com']);
        $this->ppicUser->assignRole('ppic');

        $this->unauthorizedUser = User::factory()->create(['email' => 'unauth_daily_report@peroniks.com']);
        $this->unauthorizedUser->assignRole('sales');

        $this->castingResultService = app(LostWaxCastingResultService::class);
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        $seq = self::$seq++;

        return ProductionPlan::create(array_merge([
            'code' => 'CODE-'.$seq,
            'customer' => 'CUST-'.$seq,
            'item_code' => 'ITEM-'.$seq,
            'item_name' => 'Flange Daily '.$seq,
            'aisi' => 'CF8M',
            'size' => '2 Inch',
            'weight' => 2.50,
            'po_number' => 'PO-'.$seq,
            'po_quantity' => 200,
            'qty_planned' => 200,
            'qty_remaining' => 200,
            'line_number' => 1,
            'status' => 'planning',
        ], $attributes));
    }

    protected function createPrintOrderLine(ProductionPlan $plan, array $attributes = []): LostWaxPrintOrderLine
    {
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PO-ORD-'.uniqid(),
            'scheduled_date' => now()->toDateString(),
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        return LostWaxPrintOrderLine::create(array_merge([
            'lost_wax_print_order_id' => $order->id,
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'qty_ordered' => 50,
            'execution_status' => 'COMPLETED',
        ], $attributes));
    }

    protected function createTree(array $attributes = []): LostWaxTree
    {
        $currentSeq = self::$seq++;

        return LostWaxTree::create(array_merge([
            'barcode' => 'P'.now()->format('ymd').str_pad((string) $currentSeq, 4, '0', STR_PAD_LEFT),
            'tree_number' => $currentSeq,
            'quantity' => 48,
            'status' => 'generated',
            'current_stage' => 'siap_cor',
            'require_layer_7' => false,
            'production_date' => now()->toDateString(),
            'family_code' => '1',
            'daily_sequence' => $currentSeq,
        ], $attributes));
    }

    protected function createTreeWithFlow(ProductionPlan $plan, int $quantity = 48): LostWaxTree
    {
        $line = $this->createPrintOrderLine($plan, ['qty_ordered' => $quantity]);
        $tree = $this->createTree([
            'lost_wax_print_order_line_id' => $line->id,
            'quantity' => $quantity,
        ]);

        LostWaxTreeAllocation::create([
            'lost_wax_tree_id' => $tree->id,
            'lost_wax_print_order_line_id' => $line->id,
            'allocated_qty' => $quantity,
        ]);

        LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'operator_id' => $this->adminUser->id,
            'stage' => 'oven',
            'result' => 'success',
            'scanned_at' => now()->subHours(6),
        ]);

        LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'operator_id' => $this->adminUser->id,
            'stage' => 'siap_cor',
            'result' => 'success',
            'scanned_at' => now()->subHours(2),
        ]);

        return $tree;
    }

    /**
     * 1. Laporan menggabungkan beberapa Heat Number pada tanggal yang sama.
     */
    public function test_aggregates_multiple_heats_on_the_same_date(): void
    {
        $targetDate = '2026-11-12';
        $plan = $this->createPlan();

        $tree1 = $this->createTreeWithFlow($plan, 30);
        $tree2 = $this->createTreeWithFlow($plan, 20);

        $result1 = $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-DAY-001',
            'cast_date' => $targetDate,
            'furnace' => '1',
            'shift' => '1',
            'operator_name' => 'Budi',
            'tree_ids' => [$tree1->id],
        ], $this->adminUser);

        $result2 = $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-DAY-002',
            'cast_date' => $targetDate,
            'furnace' => '2',
            'shift' => '2',
            'operator_name' => 'Joko',
            'tree_ids' => [$tree2->id],
        ], $this->adminUser);

        $response = $this->actingAs($this->adminUser)
            ->get(route('lost-wax.casting-results.daily-report', ['date' => $targetDate]));

        $response->assertOk();
        $response->assertSee('LAPORAN HASIL PENGECORAN HARIAN');
        $response->assertSee('HEAT-DAY-001');
        $response->assertSee('HEAT-DAY-002');
        $response->assertSee($tree1->barcode);
        $response->assertSee($tree2->barcode);
    }

    /**
     * 2. Tanggal yang berbeda terisolasi dan tidak bercampur.
     */
    public function test_different_dates_are_isolated_and_not_mixed(): void
    {
        $targetDate = '2026-11-12';
        $otherDate = '2026-11-13';
        $plan = $this->createPlan();

        $treeTarget = $this->createTreeWithFlow($plan, 40);
        $treeOther = $this->createTreeWithFlow($plan, 50);

        $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-TODAY',
            'cast_date' => $targetDate,
            'tree_ids' => [$treeTarget->id],
        ], $this->adminUser);

        $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-TOMORROW',
            'cast_date' => $otherDate,
            'tree_ids' => [$treeOther->id],
        ], $this->adminUser);

        $response = $this->actingAs($this->adminUser)
            ->get(route('lost-wax.casting-results.daily-report', ['date' => $targetDate]));

        $response->assertOk();
        $response->assertSee('HEAT-TODAY');
        $response->assertSee($treeTarget->barcode);
        $response->assertDontSee('HEAT-TOMORROW');
        $response->assertDontSee($treeOther->barcode);
    }

    /**
     * 3. Transaksi dibatalkan (CANCELLED) secara ketat dikecualikan dari laporan harian.
     */
    public function test_cancelled_transactions_are_strictly_excluded(): void
    {
        $targetDate = '2026-11-12';
        $plan = $this->createPlan();

        $treeValid = $this->createTreeWithFlow($plan, 25);
        $treeCancelled = $this->createTreeWithFlow($plan, 35);

        $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-VALID',
            'cast_date' => $targetDate,
            'tree_ids' => [$treeValid->id],
        ], $this->adminUser);

        $cancelledResult = $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-CANCELLED',
            'cast_date' => $targetDate,
            'tree_ids' => [$treeCancelled->id],
        ], $this->adminUser);

        // Cancel the second result
        $this->castingResultService->cancelCastingResult(
            $cancelledResult,
            $this->adminUser,
            'Uji pembatalan laporan harian'
        );

        $response = $this->actingAs($this->adminUser)
            ->get(route('lost-wax.casting-results.daily-report', ['date' => $targetDate]));

        $response->assertOk();
        $response->assertSee('HEAT-VALID');
        $response->assertSee($treeValid->barcode);
        $response->assertDontSee('HEAT-CANCELLED');
        $response->assertDontSee($treeCancelled->barcode);
    }

    /**
     * 4. Detail pohon dan kuantitas PCS snapshot sesuai dengan transaksi.
     */
    public function test_tree_details_and_snapshot_pcs_match_transaction(): void
    {
        $targetDate = '2026-11-12';
        $plan = $this->createPlan([
            'code' => 'KODE-SNAP-01',
            'customer' => 'PT Pelanggan Setia',
            'item_name' => 'Valve Body SS316',
        ]);

        $tree = $this->createTreeWithFlow($plan, 44);

        $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-SNAP-TEST',
            'cast_date' => $targetDate,
            'tree_ids' => [$tree->id],
        ], $this->adminUser);

        $response = $this->actingAs($this->adminUser)
            ->get(route('lost-wax.casting-results.daily-report', ['date' => $targetDate]));

        $response->assertOk();
        $response->assertSee($tree->barcode);
        $response->assertSee('KODE-SNAP-01');
        $response->assertSee('PT Pelanggan Setia');
        $response->assertSee('Valve Body SS316');
        $response->assertSee('44');
    }

    /**
     * 5. Subtotal per Heat Number akurat.
     */
    public function test_subtotal_per_heat_is_accurate(): void
    {
        $targetDate = '2026-11-12';
        $plan = $this->createPlan();

        $tree1 = $this->createTreeWithFlow($plan, 15);
        $tree2 = $this->createTreeWithFlow($plan, 25);

        $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-SUBTOTAL',
            'cast_date' => $targetDate,
            'tree_ids' => [$tree1->id, $tree2->id],
        ], $this->adminUser);

        $response = $this->actingAs($this->adminUser)
            ->get(route('lost-wax.casting-results.daily-report', ['date' => $targetDate]));

        $response->assertOk();
        $response->assertSee('SUBTOTAL HEAT HEAT-SUBTOTAL (2 Pohon)');
        $response->assertSee('40'); // 15 + 25 = 40
    }

    /**
     * 6. Grand total akurat dengan terminologi Total PCS Dituang (tanpa NG/yield).
     */
    public function test_grand_total_is_accurate_with_strict_total_pcs_dituang(): void
    {
        $targetDate = '2026-11-12';
        $plan = $this->createPlan();

        $tree1 = $this->createTreeWithFlow($plan, 30);
        $tree2 = $this->createTreeWithFlow($plan, 20);
        $tree3 = $this->createTreeWithFlow($plan, 50);

        $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-GT-1',
            'cast_date' => $targetDate,
            'tree_ids' => [$tree1->id, $tree2->id], // 2 pohon, 50 pcs
        ], $this->adminUser);

        $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-GT-2',
            'cast_date' => $targetDate,
            'tree_ids' => [$tree3->id], // 1 pohon, 50 pcs
        ], $this->adminUser);

        $response = $this->actingAs($this->adminUser)
            ->get(route('lost-wax.casting-results.daily-report', ['date' => $targetDate]));

        $response->assertOk();
        $response->assertSee('TOTAL HEAT NUMBER:');
        $response->assertSee('2 Heat');
        $response->assertSee('TOTAL POHON DICOR:');
        $response->assertSee('3 Pohon');
        $response->assertSee('TOTAL PCS DITUANG:');
        $response->assertSee('100 PCS');
        $response->assertDontSee('Total NG');
        $response->assertDontSee('Yield');
    }

    /**
     * 7. Tanggal tanpa transaksi menampilkan empty state yang rapi tanpa error.
     */
    public function test_empty_date_displays_friendly_empty_state(): void
    {
        $emptyDate = '2026-12-01';

        $response = $this->actingAs($this->adminUser)
            ->get(route('lost-wax.casting-results.daily-report', ['date' => $emptyDate]));

        $response->assertOk();
        $response->assertSee('Tidak Ada Transaksi Hasil Pengecoran');
        $response->assertSee('01/12/2026');
        $response->assertSee('Total Heat Number');
        $response->assertSee('Total Pohon Dicor');
        $response->assertSee('Total PCS Dituang');
    }

    /**
     * 8. Rute cetak individual tetap berfungsi dan tidak bentrok dengan rute laporan harian.
     */
    public function test_individual_print_route_preserved_and_no_route_collision(): void
    {
        $plan = $this->createPlan();
        $tree = $this->createTreeWithFlow($plan, 30);

        $result = $this->castingResultService->storeCastingResult([
            'heat_number' => 'HEAT-INDIVIDUAL-PRINT',
            'cast_date' => '2026-11-12',
            'tree_ids' => [$tree->id],
        ], $this->adminUser);

        // Test individual print route
        $individualResponse = $this->actingAs($this->adminUser)
            ->get(route('lost-wax.casting-results.print', $result));

        $individualResponse->assertOk();
        $individualResponse->assertSee('BUKTI PENCATATAN HASIL COR — LOST WAX');
        $individualResponse->assertSee('HEAT-INDIVIDUAL-PRINT');

        // Test daily report route
        $dailyResponse = $this->actingAs($this->adminUser)
            ->get(route('lost-wax.casting-results.daily-report', ['date' => '2026-11-12']));

        $dailyResponse->assertOk();
        $dailyResponse->assertSee('LAPORAN HASIL PENGECORAN HARIAN');
        $dailyResponse->assertSee('HEAT-INDIVIDUAL-PRINT');
    }
}
