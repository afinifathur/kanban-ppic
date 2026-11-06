<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PcorPrintHeaderLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $ppicUser;

    protected function setUp(): void
    {
        parent::setUp();

        $planPerm = Permission::firstOrCreate(['name' => 'access_planning']);
        $ppicRole = Role::firstOrCreate(['name' => 'ppic']);
        $ppicRole->givePermissionTo($planPerm);

        $this->ppicUser = User::factory()->create([
            'name' => 'PPIC Admin Cor Pasir',
            'email' => 'ppic_print_layout@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->ppicUser->assignRole('ppic');
    }

    /**
     * Test header columns, widths, and styling in PCOR print layout.
     */
    public function test_pcor_print_table_headers_and_proportions(): void
    {
        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261106-0001',
            'scheduled_date' => '2026-11-06',
            'status' => 'ISSUED',
            'notes' => 'Uji layout header',
            'created_by' => $this->ppicUser->id,
        ]);

        // Create 5 items matching typical screenshot scenario
        $sampleItems = [
            ['code' => 'FL.BL.4011', 'name' => 'FLANGE BLIND 2" 150# RF A105', 'size' => '2"', 'aisi' => 'FC250', 'qty' => 100],
            ['code' => 'FL.SO.4012', 'name' => 'FLANGE SLIP-ON 3" 150# RF FC250', 'size' => '3"', 'aisi' => 'FC250', 'qty' => 50],
            ['code' => 'FL.WN.4013', 'name' => 'FLANGE WELDING NECK 4" 300# RF', 'size' => '4"', 'aisi' => 'FC300', 'qty' => 75],
            ['code' => 'VL.BD.2001', 'name' => 'VALVE BODY 2 1/2" JIS 10K', 'size' => '2 1/2"', 'aisi' => 'FCD450', 'qty' => 30],
            ['code' => 'SP.CL.1005', 'name' => 'SPECIAL CLAMP 1/2" CUSTOM SIZE EXTRA LONG', 'size' => '1/2"', 'aisi' => 'WCB', 'qty' => 20],
        ];

        foreach ($sampleItems as $i => $item) {
            $plan = ProductionPlan::create([
                'code' => $item['code'],
                'title' => "Rencana {$i}",
                'item_code' => "4.80{$i}",
                'item_name' => $item['name'],
                'po_number' => "PO-ITEM-{$i}",
                'line_number' => ($i % 4) + 1,
                'qty_planned' => $item['qty'],
                'qty_remaining' => $item['qty'],
                'size' => $item['size'],
                'aisi' => $item['aisi'],
                'customer' => 'PT METAL PRESISI NUSANTARA',
                'product_scope' => 'FLANGE_BESI',
                'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
                'status' => 'planning',
            ]);

            $order->lines()->create([
                'production_plan_id' => $plan->id,
                'line_number' => $plan->line_number,
                'qty_ordered' => $item['qty'],
                'code' => $plan->code,
                'item_name' => $plan->item_name,
                'size' => $plan->size,
                'aisi' => $plan->aisi,
                'customer' => $plan->customer,
                'notes' => 'Catatan test layout',
            ]);
        }

        $res = $this->actingAs($this->ppicUser)
            ->get(route('sand-casting.casting-orders.print', $order));

        $res->assertOk();

        // 1. Column proportions in colgroup
        $res->assertSee('width: 3.5%;', false);   // No
        $res->assertSee('width: 11%;', false);     // Kode Customer & Customer
        $res->assertSee('width: 26.5%;', false);   // Nama Produk
        $res->assertSee('width: 8%;', false);      // Ukuran
        $res->assertSee('width: 6%;', false);      // AISI, Good, Reject
        $res->assertSee('width: 10%;', false);     // Qty Perintah (Sisa)
        $res->assertSee('width: 12%;', false);     // Keterangan

        // 2. All 10 table headers exist and are intact
        $res->assertSee('No');
        $res->assertSee('Kode Customer');
        $res->assertSee('Customer');
        $res->assertSee('Nama Produk');
        $res->assertSee('Ukuran');
        $res->assertSee('AISI');
        $res->assertSee('Qty Perintah (Sisa)');
        $res->assertSee('Good');
        $res->assertSee('Reject');
        $res->assertSee('Keterangan');

        // 3. Header styling rules
        $res->assertSee('table-layout: fixed', false);
        $res->assertSee('word-break: normal', false);
        $res->assertSee('overflow-wrap: normal', false);
        $res->assertSee('hyphens: none', false);

        // 4. Verification that Ukuran and others use nowrap
        $res->assertSee('class="text-center whitespace-nowrap">Ukuran<', false);
        $res->assertSee('class="text-center whitespace-nowrap">AISI<', false);
        $res->assertSee('class="text-center whitespace-nowrap">Good<', false);
        $res->assertSee('class="text-center whitespace-nowrap">Reject<', false);

        // 5. Total and balance sections remain intact
        $res->assertSee('275 pcs');
        $res->assertSee('Total Perintah Cor:');
        $res->assertSee('Rekap Berdasarkan Kategori Customer');
        $res->assertSee('Rekap Berdasarkan Line Produksi');
        $res->assertSee('Dibuat Oleh (PPIC)');
        $res->assertSee('Disetujui (Kabag Cor Pasir)');
        $res->assertSee('Diterima (SPV Cor)');
    }
}

