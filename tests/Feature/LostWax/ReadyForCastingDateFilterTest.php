<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxCastingResult;
use App\Models\LostWaxCastingResultTree;
use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWax\ReadyForCastingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReadyForCastingDateFilterTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 1;

    private User $admin;

    private ReadyForCastingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $permExec = Permission::firstOrCreate(['name' => 'access_execution', 'guard_name' => 'web']);
        $permPlan = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions([$permExec, $permPlan]);

        $this->admin = User::create([
            'name' => 'Admin Lost Wax Test',
            'email' => 'admin-lw-rfc@peroniks.com',
            'password' => Hash::make('password'),
        ]);
        $this->admin->assignRole('admin');

        $this->service = app(ReadyForCastingService::class);
    }

    private function createReadyTree(array $opts): LostWaxTree
    {
        $n = self::$seq++;
        $code = $opts['code'] ?? "RFC-CODE-{$n}";
        $customer = $opts['customer'] ?? 'CUST-ALPHA';
        $qty = $opts['qty'] ?? 10;
        $scannedAt = $opts['scanned_at'] instanceof Carbon
            ? $opts['scanned_at']
            : Carbon::parse($opts['scanned_at'] ?? '2026-11-18 09:00:00', 'Asia/Jakarta');

        $plan = ProductionPlan::create([
            'code' => $code,
            'title' => "Plan {$code}",
            'item_code' => "ITM-{$n}",
            'item_name' => $opts['item_name'] ?? "Flange Test {$code}",
            'aisi' => '304',
            'size' => '2"',
            'weight' => 0.50,
            'po_number' => "PO-{$n}",
            'po_quantity' => 100,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'customer' => $customer,
            'product_scope' => 'FLANGE_STAINLESS',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'status' => 'active',
        ]);
        // Force old plan created_at to prove filter NEVER uses production_plans.created_at
        $plan->forceFill(['created_at' => Carbon::parse('2026-10-01 08:00:00', 'Asia/Jakarta')])->save();

        $printOrder = LostWaxPrintOrder::create([
            'print_order_number' => "LW-PO-{$n}",
            'scheduled_date' => '2026-10-05',
            'status' => 'ISSUED',
            'created_by' => $this->admin->id,
        ]);

        $line = LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $printOrder->id,
            'production_plan_id' => $plan->id,
            'code' => $code,
            'customer' => $customer,
            'item_name' => $plan->item_name,
            'aisi' => '304',
            'size' => '2"',
            'qty_ordered' => 100,
        ]);

        $barcode = sprintf('6181126%03d', $n);
        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => $barcode,
            'tree_number' => $n,
            'daily_sequence' => $n,
            'production_date' => '2026-10-05',
            'family_code' => '6',
            'require_layer_7' => false,
            'quantity' => $qty,
            'current_stage' => 'siap_cor',
            'last_scan_at' => $scannedAt,
            'status' => 'active',
        ]);

        LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $barcode,
            'stage' => 'siap_cor',
            'scanned_at' => $scannedAt,
            'operator_id' => $this->admin->id,
            'result' => 'success',
            'source' => LostWaxScanEvent::SOURCE_PHYSICAL_SCAN,
        ]);

        return $tree->fresh();
    }

    private function markTreeCastCompleted(LostWaxTree $tree, string $heatNumber): void
    {
        $cr = LostWaxCastingResult::create([
            'heat_number' => $heatNumber,
            'cast_date' => '2026-11-18',
            'total_trees' => 1,
            'total_pcs' => $tree->quantity,
            'status' => 'COMPLETED',
            'recorded_by' => $this->admin->id,
        ]);

        LostWaxCastingResultTree::create([
            'lost_wax_casting_result_id' => $cr->id,
            'lost_wax_tree_id' => $tree->id,
            'active_tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'production_code' => $tree->printOrderLine->code,
            'customer' => $tree->printOrderLine->customer,
            'item_name' => $tree->printOrderLine->item_name,
            'quantity_pcs' => $tree->quantity,
            'gross_pcs' => $tree->quantity,
            'defect_pcs' => 0,
        ]);

        $tree->update(['current_stage' => 'cor']);
    }

    public function test_01_default_single_multi_select_all_clear_and_combined_filters(): void
    {
        // Create trees across 3 scan dates with distinct codes & customers
        $t18a = $this->createReadyTree(['code' => 'CODE-A', 'customer' => 'CUST-X', 'qty' => 20, 'scanned_at' => '2026-11-18 10:00:00']);
        $t18b = $this->createReadyTree(['code' => 'CODE-B', 'customer' => 'CUST-Y', 'qty' => 30, 'scanned_at' => '2026-11-18 14:30:00']);
        $t17a = $this->createReadyTree(['code' => 'CODE-A', 'customer' => 'CUST-X', 'qty' => 40, 'scanned_at' => '2026-11-17 09:00:00']);
        $t17b = $this->createReadyTree(['code' => 'CODE-C', 'customer' => 'CUST-Z', 'qty' => 50, 'scanned_at' => '2026-11-17 16:00:00']);
        $t16a = $this->createReadyTree(['code' => 'CODE-A', 'customer' => 'CUST-X', 'qty' => 60, 'scanned_at' => '2026-11-16 11:00:00']);

        // 1. Default (no scan_dates selected) -> ALL trees returned, NOT restricted to latest date
        $defaultData = $this->service->getQueueData();
        $this->assertFalse($defaultData['summary']['is_filtered']);
        $this->assertSame(5, $defaultData['summary']['total_trees']);
        $this->assertSame(200, $defaultData['summary']['total_usable_pcs']);

        // Dynamic date options sorted DESCENDING with Traveler Card count (NOT PCS)
        $this->assertSame([
            ['value' => '2026-11-18', 'label' => '18-11-2026', 'tree_count' => 2],
            ['value' => '2026-11-17', 'label' => '17-11-2026', 'tree_count' => 2],
            ['value' => '2026-11-16', 'label' => '16-11-2026', 'tree_count' => 1],
        ], $defaultData['all_scan_dates']);

        // 2. Single date (18-11-2026 only) -> 2 trees, 50 PCS
        $only18 = $this->service->getQueueData(null, null, [], [], ['2026-11-18']);
        $this->assertTrue($only18['summary']['is_filtered']);
        $this->assertSame(2, $only18['summary']['total_trees']);
        $this->assertSame(50, $only18['summary']['total_usable_pcs']);
        $this->assertSame([$t18a->id, $t18b->id], $only18['items']->pluck('tree_id')->all());
        $this->assertStringContainsString('Tgl Scan: 18-11-2026', $only18['summary']['active_filter_text']);

        // 3. Multi-select OR (18 + 17) -> 4 trees, 140 PCS
        $union1817 = $this->service->getQueueData(null, null, [], [], ['2026-11-18', '2026-11-17']);
        $this->assertSame(4, $union1817['summary']['total_trees']);
        $this->assertSame(140, $union1817['summary']['total_usable_pcs']);
        $this->assertEqualsCanonicalizing(
            [$t18a->id, $t18b->id, $t17a->id, $t17b->id],
            $union1817['items']->pluck('tree_id')->all()
        );
        // FIFO order preserved (17-11 before 18-11)
        $this->assertSame([$t17a->id, $t17b->id, $t18a->id, $t18b->id], $union1817['items']->pluck('tree_id')->all());

        // 4. Select All -> all 5 trees, 200 PCS
        $selectAll = $this->service->getQueueData(null, null, [], [], ['2026-11-18', '2026-11-17', '2026-11-16']);
        $this->assertSame(5, $selectAll['summary']['total_trees']);
        $this->assertSame(200, $selectAll['summary']['total_usable_pcs']);

        // 5. Clear (empty array) -> back to unfiltered all dates
        $cleared = $this->service->getQueueData(null, null, [], [], []);
        $this->assertFalse($cleared['summary']['is_filtered']);
        $this->assertSame(5, $cleared['summary']['total_trees']);
        $this->assertSame(200, $cleared['summary']['total_usable_pcs']);

        // 6. Combined AND filter: scan_dates=[18, 17] + codes=[CODE-A] + customers=[CUST-X]
        $combined = $this->service->getQueueData(null, null, ['CODE-A'], ['CUST-X'], ['2026-11-18', '2026-11-17']);
        $this->assertSame(2, $combined['summary']['total_trees']);
        $this->assertSame(60, $combined['summary']['total_usable_pcs']); // 20 + 40 (excludes t16a!)
        $this->assertSame([$t17a->id, $t18a->id], $combined['items']->pluck('tree_id')->all());
    }

    public function test_02_http_ui_reload_consistency_and_print_integration_for_one_and_two_dates(): void
    {
        $t18 = $this->createReadyTree(['code' => 'PRT-18', 'customer' => 'CUST-A', 'qty' => 25, 'scanned_at' => '2026-11-18 08:30:00']);
        $t17 = $this->createReadyTree(['code' => 'PRT-17', 'customer' => 'CUST-B', 'qty' => 35, 'scanned_at' => '2026-11-17 08:30:00']);
        $t16 = $this->createReadyTree(['code' => 'PRT-16', 'customer' => 'CUST-C', 'qty' => 45, 'scanned_at' => '2026-11-16 08:30:00']);

        // Index page with 1 date selected (tests UI, reload persistence & print URL forwarding)
        $indexOne = $this->actingAs($this->admin)->get(route('lost-wax.reports.ready-for-casting.index', [
            'scan_dates' => ['2026-11-18'],
        ]));
        $indexOne->assertOk();
        $indexOne->assertSee('Tanggal Scan');
        $indexOne->assertSee('Filter Tanggal Scan');
        $indexOne->assertSee('18-11-2026');
        $indexOne->assertSee('17-11-2026');
        $indexOne->assertSee('16-11-2026');
        $indexOne->assertSee('Tgl Scan: 18-11-2026');
        $indexOne->assertSee($t18->barcode);
        $indexOne->assertDontSee($t17->barcode);
        $indexOne->assertDontSee($t16->barcode);
        $indexOne->assertSee('scan_dates%5B0%5D=2026-11-18', false);

        // Reload identical URL -> consistent state
        $reloadOne = $this->actingAs($this->admin)->get(route('lost-wax.reports.ready-for-casting.index', [
            'scan_dates' => ['2026-11-18'],
        ]));
        $reloadOne->assertOk();
        $reloadOne->assertSee('Tgl Scan: 18-11-2026');
        $reloadOne->assertSee($t18->barcode);
        $reloadOne->assertDontSee($t17->barcode);

        // Print 1 date -> ONLY date 18 trees & recalculated KPI (25 PCS, 1 Pohon)
        $printOne = $this->actingAs($this->admin)->get(route('lost-wax.reports.ready-for-casting.print', [
            'scan_dates' => ['2026-11-18'],
        ]));
        $printOne->assertOk();
        $printOne->assertSee('PRT-18');
        $printOne->assertDontSee('PRT-17');
        $printOne->assertDontSee('PRT-16');
        $printOne->assertSee('Tgl Scan: 18-11-2026');
        $printOne->assertSee('25 PCS');

        // Print 2 dates -> union of date 18 + date 17 (60 PCS, 2 Pohon), excludes date 16
        $printTwo = $this->actingAs($this->admin)->get(route('lost-wax.reports.ready-for-casting.print', [
            'scan_dates' => ['2026-11-18', '2026-11-17'],
        ]));
        $printTwo->assertOk();
        $printTwo->assertSee('PRT-18');
        $printTwo->assertSee('PRT-17');
        $printTwo->assertDontSee('PRT-16');
        $printTwo->assertSee('Tgl Scan: 18-11-2026, 17-11-2026');
        $printTwo->assertSee('60 PCS');
    }

    public function test_03_dynamic_date_disappears_when_all_trees_cast_and_remains_on_partial_cast(): void
    {
        $t18a = $this->createReadyTree(['code' => 'DYN-18', 'qty' => 10, 'scanned_at' => '2026-11-18 09:00:00']);
        $t17a = $this->createReadyTree(['code' => 'DYN-17A', 'qty' => 15, 'scanned_at' => '2026-11-17 09:00:00']);
        $t17b = $this->createReadyTree(['code' => 'DYN-17B', 'qty' => 20, 'scanned_at' => '2026-11-17 14:00:00']);
        $t16a = $this->createReadyTree(['code' => 'DYN-16', 'qty' => 25, 'scanned_at' => '2026-11-16 10:00:00']);

        // Initial state: 3 dates present (18: 1, 17: 2, 16: 1)
        $before = $this->service->getQueueData();
        $this->assertSame(['2026-11-18', '2026-11-17', '2026-11-16'], array_column($before['all_scan_dates'], 'value'));

        // Cast ALL trees from 18-11-2026 -> 18-11-2026 MUST disappear automatically!
        $this->markTreeCastCompleted($t18a, 'H-CAST-18');

        // Cast PARTIAL (1 of 2) trees from 17-11-2026 -> 17-11-2026 MUST remain with tree_count = 1!
        $this->markTreeCastCompleted($t17a, 'H-CAST-17A');

        $after = $this->service->getQueueData();
        $this->assertSame([
            ['value' => '2026-11-17', 'label' => '17-11-2026', 'tree_count' => 1],
            ['value' => '2026-11-16', 'label' => '16-11-2026', 'tree_count' => 1],
        ], $after['all_scan_dates']);
        $this->assertSame([$t16a->id, $t17b->id], $after['items']->pluck('tree_id')->all());
        $this->assertSame(45, $after['summary']['total_usable_pcs']);

        // Dropdown HTML no longer shows 18-11-2026
        $resp = $this->actingAs($this->admin)->get(route('lost-wax.reports.ready-for-casting.index'));
        $resp->assertOk();
        $resp->assertDontSee('18-11-2026');
        $resp->assertSee('17-11-2026');
        $resp->assertSee('16-11-2026');
    }

    public function test_04_wib_asia_jakarta_midnight_timezone_boundary_grouping(): void
    {
        // Real WIB wall-clock timestamps stored in DB (matches ScanSiapCorService::processScan using Asia/Jakarta):
        // 2026-11-17 16:59:59 UTC == 2026-11-17 23:59:59 WIB -> MUST be 17-11-2026
        $beforeMidnightWib = Carbon::create(2026, 11, 17, 16, 59, 59, 'UTC')->setTimezone('Asia/Jakarta');
        // 2026-11-17 17:00:01 UTC == 2026-11-18 00:00:01 WIB -> MUST be 18-11-2026
        $afterMidnightWib = Carbon::create(2026, 11, 17, 17, 0, 1, 'UTC')->setTimezone('Asia/Jakarta');

        $tree17 = $this->createReadyTree(['code' => 'WIB-17', 'qty' => 12, 'scanned_at' => $beforeMidnightWib]);
        $tree18 = $this->createReadyTree(['code' => 'WIB-18', 'qty' => 18, 'scanned_at' => $afterMidnightWib]);

        $all = $this->service->getQueueData();
        $this->assertSame([
            ['value' => '2026-11-18', 'label' => '18-11-2026', 'tree_count' => 1],
            ['value' => '2026-11-17', 'label' => '17-11-2026', 'tree_count' => 1],
        ], $all['all_scan_dates']);

        $only17 = $this->service->getQueueData(null, null, [], [], ['2026-11-17']);
        $this->assertSame([$tree17->id], $only17['items']->pluck('tree_id')->all());
        $this->assertSame('17-11-2026', $only17['items']->first()['scan_date_label']);
        $this->assertSame('17/11/2026 23:59:59', $only17['items']->first()['scanned_at_formatted']);

        $only18 = $this->service->getQueueData(null, null, [], [], ['2026-11-18']);
        $this->assertSame([$tree18->id], $only18['items']->pluck('tree_id')->all());
        $this->assertSame('18-11-2026', $only18['items']->first()['scan_date_label']);
        $this->assertSame('18/11/2026 00:00:01', $only18['items']->first()['scanned_at_formatted']);
    }
}
