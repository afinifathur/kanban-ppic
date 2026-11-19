<?php

namespace Tests\Feature\Fitting;

use App\Models\FittingItem;
use App\Models\LostWaxCastingResult;
use App\Models\LostWaxCastingResultTree;
use App\Models\LostWaxKitir;
use App\Models\LostWaxTree;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\Fitting\FittingKanbanQueryService;
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

class FittingLineMappingAndKanbanFourBucketsTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 100;

    private User $ppicFitting;

    private FittingKanbanQueryService $kanbanService;

    private FittingTransferService $transferService;

    private FittingKitirSnapshotResolverService $snapshotResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setEnvVar('FITTING_SPV_INITIAL_PASSWORD', 'FitPass123!');

        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution', 'guard_name' => 'web']);
        $permKanban = Permission::firstOrCreate(['name' => 'manage_fitting_kanban', 'guard_name' => 'web']);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'])->syncPermissions([$permPlanning, $permExecution, $permKanban]);
        Role::firstOrCreate(['name' => 'ppic', 'guard_name' => 'web'])->syncPermissions([$permPlanning, $permExecution, $permKanban]);

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

        $this->kanbanService = app(FittingKanbanQueryService::class);
        $this->transferService = app(FittingTransferService::class);
        $this->snapshotResolver = app(FittingKitirSnapshotResolverService::class);
    }

    private function setEnvVar(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    /**
     * Helper to create an issued Kitir ready for transfer.
     */
    private function createReadyKitir(string $code, string $itemName, int $planLineNumber = 1, int $qty = 20): array
    {
        $seq = self::$seq++;
        $plan = ProductionPlan::create([
            'code' => $code,
            'customer' => 'Customer Test',
            'item_code' => '4.101.'.$code,
            'item_name' => $itemName,
            'aisi' => '304',
            'size' => '1"',
            'weight' => 0.5,
            'po_number' => 'PO-'.$seq,
            'po_quantity' => 100,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => $planLineNumber,
            'status' => 'planning',
            'product_scope' => 'FITTING_STAINLESS',
        ]);

        $printOrder = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => "LW-PO-{$seq}",
            'scheduled_date' => Carbon::today()->format('Y-m-d'),
            'status' => 'ISSUED',
            'created_by' => $this->ppicFitting->id,
        ]);

        $printLine = \App\Models\LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $printOrder->id,
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'aisi' => $plan->aisi,
            'size' => $plan->size,
            'qty_ordered' => $plan->qty_planned,
        ]);

        $heat = 'H-LINE-'.$seq;
        $res = LostWaxCastingResult::create([
            'heat_number' => $heat,
            'cast_date' => Carbon::today()->format('Y-m-d'),
            'furnace' => '1',
            'shift' => '1',
            'operator_name' => 'Op Line',
            'total_trees' => 1,
            'total_pcs' => $qty,
            'status' => 'COMPLETED',
            'recorded_by' => $this->ppicFitting->id,
        ]);

        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $printLine->id,
            'barcode' => sprintf('26%08d', $seq),
            'tree_number' => 1,
            'daily_sequence' => $seq,
            'production_date' => Carbon::today()->format('Y-m-d'),
            'family_code' => 'DEMO',
            'quantity' => $qty,
            'current_stage' => 'cor',
            'status' => 'completed',
        ]);

        LostWaxCastingResultTree::create([
            'lost_wax_casting_result_id' => $res->id,
            'lost_wax_tree_id' => $tree->id,
            'active_tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'production_code' => $code,
            'customer' => 'Customer Test',
            'item_name' => $itemName,
            'quantity_pcs' => $qty,
            'gross_pcs' => $qty,
            'defect_pcs' => 0,
        ]);

        $kitirResolver = app(LostWaxKitirResolverService::class);
        $kitir = $kitirResolver->issueOrGetKitir($res, $code, $this->ppicFitting);

        return [$plan, $res, $kitir];
    }

    /**
     * Requirement 1: Mapping Line 1–4 sesuai keputusan final operasional.
     * Line 1 = Elbow / Tee
     * Line 2 = Union
     * Line 3 = Ball Valve
     * Line 4 = BW
     */
    public function test_01_line_mapping_constants_and_helper(): void
    {
        $expected = [
            1 => 'Line 1 — Fitting biasa',
            2 => 'Line 2 — Union',
            3 => 'Line 3 — Ball Valve',
            4 => 'Line 4 — BW',
        ];

        $this->assertEquals($expected, FittingItem::LINE_LABELS);
        $this->assertEquals('Line 1 — Fitting biasa', FittingItem::lineLabelFor(1));
        $this->assertEquals('Line 2 — Union', FittingItem::lineLabelFor(2));
        $this->assertEquals('Line 3 — Ball Valve', FittingItem::lineLabelFor(3));
        $this->assertEquals('Line 4 — BW', FittingItem::lineLabelFor(4));
        $this->assertEquals('Unassigned', FittingItem::lineLabelFor(null));
        $this->assertEquals('Line 99', FittingItem::lineLabelFor(99));
    }

    /**
     * Requirement 2: Dropdown Line Fitting pada Transfer Kitir menampilkan mapping baru.
     */
    public function test_02_transfer_kitir_dropdown_renders_new_mapping(): void
    {
        [$plan, $res, $kitir] = $this->createReadyKitir('KTR-MAP-01', 'ITEM BV', 3, 25);

        $response = $this->actingAs($this->ppicFitting)->get(route('fitting.transfers.index', ['tab' => 'available']));
        $response->assertOk();

        // Check dropdown options in the Transfer Kitir UI
        $response->assertSee('Line 1 — Fitting biasa');
        $response->assertSee('Line 2 — Union');
        $response->assertSee('Line 3 — Ball Valve');
        $response->assertSee('Line 4 — BW');

        // Old mapping MUST NOT exist
        $response->assertDontSee('Line 3 — BW');
        $response->assertDontSee('Line 4 — Ball Valve');
    }

    /**
     * Requirement 3: Prefill Line Fitting tetap berjalan dengan aturan yang ada
     * (menggunakan Line Rencana, tanpa inferensi nama produk).
     */
    public function test_03_prefill_suggested_line_respects_plan_line_without_inference(): void
    {
        // Item with BW in name but plan line_number = 3 -> suggested line is 3 (now Ball Valve)!
        [$plan3, $res3, $kitir3] = $this->createReadyKitir('KTR-BW-PLAN3', 'BUTT WELD ELBOW 90', 3, 20);
        $snap3 = $this->snapshotResolver->resolve($kitir3);
        $this->assertEquals(3, $snap3['suggested_line_number']);
        $this->assertEquals('Line 3', $snap3['suggested_line_label']);

        // Item with Ball Valve in name but plan line_number = 4 -> suggested line is 4 (now BW)!
        [$plan4, $res4, $kitir4] = $this->createReadyKitir('KTR-BV-PLAN4', 'BALL VALVE 1000WOG', 4, 20);
        $snap4 = $this->snapshotResolver->resolve($kitir4);
        $this->assertEquals(4, $snap4['suggested_line_number']);
        $this->assertEquals('Line 4', $snap4['suggested_line_label']);
    }

    /**
     * Requirement 4: Kanban hanya memiliki empat bucket (Line 1–4) dan kolom UNASSIGNED ditiadakan dari grid.
     */
    public function test_04_kanban_four_buckets_layout_and_no_unassigned_column(): void
    {
        $this->assertEquals(['1', '2', '3', '4'], FittingKanbanQueryService::ALL_BUCKETS);

        $response = $this->actingAs($this->ppicFitting)->get(route('fitting.kanban.stage', 'netto-pf'));
        $response->assertOk();

        $content = $response->getContent();

        // Grid layout MUST be 4-column optimized
        $this->assertStringContainsString('xl:grid-cols-4', $content);
        $this->assertStringNotContainsString('xl:grid-cols-5', $content);

        // 4 Columns Headers MUST be rendered
        $this->assertStringContainsString('LINE 1 — FITTING BIASA', $content);
        $this->assertStringContainsString('LINE 2 — UNION', $content);
        $this->assertStringContainsString('LINE 3 — BALL VALVE', $content);
        $this->assertStringContainsString('LINE 4 — BW', $content);

        // No 5th column header for UNASSIGNED on the Kanban board
        $this->assertStringNotContainsString('text-xs font-black tracking-wider">UNASSIGNED', $content);
    }

    /**
     * Requirement 5: Traveler Line 3 tampil di bucket Ball Valve.
     * Requirement 6: Traveler Line 4 tampil di bucket BW.
     */
    public function test_05_and_06_travelers_in_line_3_and_4_appear_in_respective_buckets(): void
    {
        [$p3, $r3, $k3] = $this->createReadyKitir('KTR-L3', 'BALL VALVE 1"', 3, 15);
        [$p4, $r4, $k4] = $this->createReadyKitir('KTR-L4', 'BW TEE 1"', 4, 25);

        // PPIC transfers Kitir 3 to Line 3 (Ball Valve) and Kitir 4 to Line 4 (BW)
        $this->actingAs($this->ppicFitting)->post(route('fitting.transfers.store'), [
            'kitir_ids' => [$k3->id, $k4->id],
            'kitir_lines' => [
                $k3->id => 3,
                $k4->id => 4,
            ],
            'transfer_date' => Carbon::today()->format('Y-m-d'),
        ])->assertRedirect(route('fitting.transfers.index', ['tab' => 'transferred']));

        $item3 = FittingItem::where('lost_wax_kitir_id', $k3->id)->firstOrFail();
        $item4 = FittingItem::where('lost_wax_kitir_id', $k4->id)->firstOrFail();

        $this->assertEquals(3, $item3->line_number);
        $this->assertEquals(4, $item4->line_number);
        $this->assertEquals('Line 3 — Ball Valve', FittingItem::lineLabelFor($item3->line_number));
        $this->assertEquals('Line 4 — BW', FittingItem::lineLabelFor($item4->line_number));

        // Query Kanban data
        $kanbanData = $this->kanbanService->getStageKanbanData('netto_pf');

        // Bucket 3 has item 3 (Ball Valve)
        $bucket3Ids = array_column($kanbanData['buckets']['3'], 'id');
        $this->assertContains($item3->id, $bucket3Ids);
        $this->assertNotContains($item4->id, $bucket3Ids);

        // Bucket 4 has item 4 (BW)
        $bucket4Ids = array_column($kanbanData['buckets']['4'], 'id');
        $this->assertContains($item4->id, $bucket4Ids);
        $this->assertNotContains($item3->id, $bucket4Ids);

        // HTML view test
        $response = $this->actingAs($this->ppicFitting)->get(route('fitting.kanban.stage', 'netto-pf'));
        $response->assertOk();
        $response->assertSee($item3->traveler_number);
        $response->assertSee($item4->traveler_number);
    }

    /**
     * Requirement 7: Filter Line pada Kanban dan Transferred Kitir menggunakan mapping baru.
     */
    public function test_07_filters_use_new_line_mapping(): void
    {
        [$p3, $r3, $k3] = $this->createReadyKitir('KTR-FLT3', 'VALVE ITEM', 3, 10);
        $this->actingAs($this->ppicFitting)->post(route('fitting.transfers.store'), [
            'kitir_ids' => [$k3->id],
            'kitir_lines' => [$k3->id => 3],
            'transfer_date' => Carbon::today()->format('Y-m-d'),
        ]);
        $item3 = FittingItem::where('lost_wax_kitir_id', $k3->id)->firstOrFail();

        // 1. Kanban filtered by line_bucket=3 returns ONLY Line 3 (Ball Valve)
        $kanbanFiltered3 = $this->kanbanService->getStageKanbanData('netto_pf', ['line_bucket' => '3']);
        $this->assertEquals(1, count($kanbanFiltered3['buckets']['3']));
        $this->assertEquals(0, count($kanbanFiltered3['buckets']['4']));

        // 2. Transfer Kitir transferred tab filtered by line=3
        $respTransfer3 = $this->actingAs($this->ppicFitting)->get(route('fitting.transfers.index', [
            'tab' => 'transferred',
            'line' => '3',
        ]));
        $respTransfer3->assertOk();
        $respTransfer3->assertSee($item3->traveler_number);
        $respTransfer3->assertSee('Line 3 — Ball Valve');
    }

    /**
     * Requirement 8: Data historis yang sudah selesai tidak berubah.
     */
    public function test_08_historical_completed_data_preserves_line_and_identity(): void
    {
        [$p, $r, $k] = $this->createReadyKitir('KTR-HIST-04', 'COMPLETED ITEM', 4, 50);
        $this->actingAs($this->ppicFitting)->post(route('fitting.transfers.store'), [
            'kitir_ids' => [$k->id],
            'kitir_lines' => [$k->id => 4],
            'transfer_date' => Carbon::today()->format('Y-m-d'),
        ]);

        $item = FittingItem::where('lost_wax_kitir_id', $k->id)->firstOrFail();
        $item->update([
            'current_stage' => FittingItem::STAGE_COMPLETED,
            'status' => FittingItem::STATUS_ACTIVE,
        ]);

        $itemFresh = $item->fresh();
        $this->assertEquals(4, $itemFresh->line_number);
        $this->assertEquals(FittingItem::STAGE_COMPLETED, $itemFresh->current_stage);
        $this->assertEquals('Line 4 — BW', FittingItem::lineLabelFor($itemFresh->line_number));
    }
}
