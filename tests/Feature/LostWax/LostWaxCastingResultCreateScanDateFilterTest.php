<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\ProductionPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LostWaxCastingResultCreateScanDateFilterTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 1;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $permExec = Permission::firstOrCreate(['name' => 'access_execution', 'guard_name' => 'web']);
        $permPlan = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions([$permExec, $permPlan]);

        $this->admin = User::create([
            'name' => 'Admin Test Casting Create',
            'email' => 'admin-casting-create@peroniks.com',
            'password' => Hash::make('password'),
        ]);
        $this->admin->assignRole('admin');
    }

    private function createTreeWithScanDate(string $code, string $customer, int $qty, string|Carbon $scannedAt): LostWaxTree
    {
        $n = self::$seq++;
        $scannedAtCarbon = $scannedAt instanceof Carbon
            ? $scannedAt
            : Carbon::parse($scannedAt, 'Asia/Jakarta');

        $plan = ProductionPlan::create([
            'code' => $code,
            'title' => "Plan {$code}",
            'item_code' => "ITM-{$n}",
            'item_name' => "Item Flange {$code}",
            'aisi' => '304',
            'size' => '2"',
            'weight' => 0.60,
            'po_number' => "PO-{$n}",
            'po_quantity' => 200,
            'qty_planned' => 200,
            'qty_remaining' => 200,
            'line_number' => 1,
            'customer' => $customer,
            'product_scope' => 'FLANGE_STAINLESS',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'status' => 'active',
        ]);

        $printOrder = LostWaxPrintOrder::create([
            'print_order_number' => "LW-PO-{$n}",
            'scheduled_date' => '2026-11-10',
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
            'qty_ordered' => 200,
        ]);

        $barcode = sprintf('7191126%03d', $n);
        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => $barcode,
            'tree_number' => $n,
            'daily_sequence' => $n,
            'production_date' => '2026-11-10',
            'family_code' => '6',
            'require_layer_7' => false,
            'quantity' => $qty,
            'current_stage' => 'siap_cor',
            'last_scan_at' => $scannedAtCarbon,
            'status' => 'active',
        ]);

        LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $barcode,
            'stage' => 'siap_cor',
            'scanned_at' => $scannedAtCarbon,
            'operator_id' => $this->admin->id,
            'result' => 'success',
            'source' => LostWaxScanEvent::SOURCE_PHYSICAL_SCAN,
        ]);

        return $tree->fresh();
    }

    public function test_01_shift_1_is_default_and_shift_2_and_3_can_be_selected_and_stored(): void
    {
        $tree1 = $this->createTreeWithScanDate('CODE-S1', 'CUST-S', 10, '2026-11-18 09:00:00');
        $tree2 = $this->createTreeWithScanDate('CODE-S2', 'CUST-S', 12, '2026-11-18 09:30:00');
        $tree3 = $this->createTreeWithScanDate('CODE-S3', 'CUST-S', 15, '2026-11-18 10:00:00');

        // 1. GET create page -> Shift 1 is selected by default in HTML
        $response = $this->actingAs($this->admin)->get(route('lost-wax.casting-results.create'));
        $response->assertOk();
        $response->assertSee('<option value="1" selected>Shift 1</option>', false);
        $response->assertSee('<option value="2" >Shift 2</option>', false);
        $response->assertSee('<option value="3" >Shift 3</option>', false);

        // 2. Store with default Shift 1
        $postDefault = $this->actingAs($this->admin)->post(route('lost-wax.casting-results.store'), [
            'heat_number' => 'HEAT-SHIFT-1',
            'cast_date' => '2026-11-18',
            'shift' => '1',
            'tree_ids' => [$tree1->id],
        ]);
        $postDefault->assertRedirect();
        $this->assertDatabaseHas('lost_wax_casting_results', [
            'heat_number' => 'HEAT-SHIFT-1',
            'shift' => '1',
            'total_trees' => 1,
            'total_pcs' => 10,
        ]);

        // 3. Store with Shift 2
        $postShift2 = $this->actingAs($this->admin)->post(route('lost-wax.casting-results.store'), [
            'heat_number' => 'HEAT-SHIFT-2',
            'cast_date' => '2026-11-18',
            'shift' => '2',
            'tree_ids' => [$tree2->id],
        ]);
        $postShift2->assertRedirect();
        $this->assertDatabaseHas('lost_wax_casting_results', [
            'heat_number' => 'HEAT-SHIFT-2',
            'shift' => '2',
            'total_trees' => 1,
            'total_pcs' => 12,
        ]);

        // 4. Store with Shift 3
        $postShift3 = $this->actingAs($this->admin)->post(route('lost-wax.casting-results.store'), [
            'heat_number' => 'HEAT-SHIFT-3',
            'cast_date' => '2026-11-18',
            'shift' => '3',
            'tree_ids' => [$tree3->id],
        ]);
        $postShift3->assertRedirect();
        $this->assertDatabaseHas('lost_wax_casting_results', [
            'heat_number' => 'HEAT-SHIFT-3',
            'shift' => '3',
            'total_trees' => 1,
            'total_pcs' => 15,
        ]);
    }

    public function test_02_filter_tanggal_scan_menampilkan_kartu_sesuai_rentang_dan_pertahankan_fifo(): void
    {
        // Tree 1: 16-11-2026 09:00:00 (oldest FIFO)
        $t1 = $this->createTreeWithScanDate('CODE-D16', 'CUST-A', 10, '2026-11-16 09:00:00');
        // Tree 2: 17-11-2026 14:00:00
        $t2 = $this->createTreeWithScanDate('CODE-D17', 'CUST-B', 20, '2026-11-17 14:00:00');
        // Tree 3: 18-11-2026 08:30:00
        $t3 = $this->createTreeWithScanDate('CODE-D18', 'CUST-C', 30, '2026-11-18 08:30:00');

        // Query date range: 17-11-2026 to 18-11-2026
        $response = $this->actingAs($this->admin)->get(route('lost-wax.casting-results.create', [
            'scan_date_from' => '2026-11-17',
            'scan_date_to' => '2026-11-18',
        ]));
        $response->assertOk();

        /** @var \Illuminate\Support\Collection $items */
        $items = $response->viewData('items');
        $this->assertCount(2, $items);

        // Strict FIFO assertion: Tree 2 (17-11) MUST come before Tree 3 (18-11)
        $this->assertSame([$t2->id, $t3->id], $items->pluck('tree_id')->all());

        // Assert Tree 1 (16-11) is excluded
        $this->assertFalse($items->contains('tree_id', $t1->id));

        // HTML assertion
        $response->assertSee($t2->barcode);
        $response->assertSee($t3->barcode);
        $response->assertDontSee($t1->barcode);
    }

    public function test_03_filter_tanggal_scan_single_day_and_graceful_inverted_dates(): void
    {
        $t1 = $this->createTreeWithScanDate('CODE-S16', 'CUST-1', 11, '2026-11-16 10:00:00');
        $t2 = $this->createTreeWithScanDate('CODE-S17', 'CUST-2', 22, '2026-11-17 11:00:00');
        $t3 = $this->createTreeWithScanDate('CODE-S18', 'CUST-3', 33, '2026-11-18 12:00:00');

        // 1. Single day (16-11 only)
        $respSingle = $this->actingAs($this->admin)->get(route('lost-wax.casting-results.create', [
            'scan_date_from' => '2026-11-16',
            'scan_date_to' => '2026-11-16',
        ]));
        $respSingle->assertOk();
        $itemsSingle = $respSingle->viewData('items');
        $this->assertCount(1, $itemsSingle);
        $this->assertSame([$t1->id], $itemsSingle->pluck('tree_id')->all());

        // 2. Inverted dates (from: 18-11, to: 17-11) gracefully swapped
        $respInverted = $this->actingAs($this->admin)->get(route('lost-wax.casting-results.create', [
            'scan_date_from' => '2026-11-18',
            'scan_date_to' => '2026-11-17',
        ]));
        $respInverted->assertOk();
        $itemsInverted = $respInverted->viewData('items');
        $this->assertCount(2, $itemsInverted);
        // FIFO preserved
        $this->assertSame([$t2->id, $t3->id], $itemsInverted->pluck('tree_id')->all());
    }

    public function test_04_ui_elements_and_data_attributes_for_client_side_filtering(): void
    {
        $t = $this->createTreeWithScanDate('CODE-UI', 'CUST-UI', 18, '2026-11-18 14:25:30');

        $response = $this->actingAs($this->admin)->get(route('lost-wax.casting-results.create'));
        $response->assertOk();

        // Check date filter controls
        $response->assertSee('id="scan_date_from"', false);
        $response->assertSee('id="scan_date_to"', false);
        $response->assertSee('id="btnApplyDateFilter"', false);
        $response->assertSee('id="btnResetDateFilter"', false);
        $response->assertSee('Reset Filter');

        // Check batch actions
        $response->assertSee('id="btnSelectAll"', false);
        $response->assertSee('Pilih Semua (Terlihat)');
        $response->assertSee('id="btnDeselectAll"', false);
        $response->assertSee('Batalkan');

        // Check top_bar compact header and navigation links
        $response->assertSee('Pengecoran');
        $response->assertSee('Phase 4');
        $response->assertSee('Input Hasil Cor');
        $response->assertSee('Riwayat Hasil Cor');
        $response->assertSee('Antrean Siap Cor');
        $response->assertSee('Hubungkan Traveler Card dari antrean Barang Siap Cor ke satu Heat Number penuangan aktual.');

        // Check row data-scan-date attribute for client-side filter
        $response->assertSee('data-scan-date="2026-11-18"', false);
        $response->assertSee('18/11/2026 14:25:30');
    }
}
