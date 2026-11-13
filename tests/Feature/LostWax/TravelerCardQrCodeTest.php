<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxCoatingRack;
use App\Models\LostWaxItemReference;
use App\Models\LostWaxTree;
use App\Models\LostWaxWorkOrder;
use App\Models\LostWaxWorkOrderPlan;
use App\Models\User;
use App\Services\LostWax\LostWaxQrCodeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class TravelerCardQrCodeTest extends TestCase
{
    use RefreshDatabase;

    private LostWaxQrCodeService $qrCodeService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->qrCodeService = new LostWaxQrCodeService;
        config(['lost_wax.printer_name' => 'TSC TE200']);
    }

    /**
     * Helper to set up item reference and work order.
     */
    private function createTreeFixture(string $barcode = '3110826005', int $treeNumber = 1): LostWaxTree
    {
        $itemRef = LostWaxItemReference::firstOrCreate(
            ['master_item_key' => '4.101105K.A0080'],
            [
                'master_source' => 'masterdata_kpi',
                'item_code_snapshot' => '4.101105K.A0080',
                'item_name_snapshot' => 'SS304 SQUARE DN 25',
                'standard_capacity' => 15,
                'family_code' => '1',
                'aisi_snapshot' => '304',
            ]
        );

        $workOrder = LostWaxWorkOrder::create([
            'item_reference_id' => $itemRef->id,
            'et_code' => 'WO-TEST-'.uniqid(),
            'po_number' => 'PO-001',
            'customer_name' => 'PT Contoh',
            'po_quantity' => 100,
            'stock_quantity' => 0,
            'net_requirement_quantity' => 100,
            'status' => 'active',
            'family_code' => '1',
        ]);

        $plan = LostWaxWorkOrderPlan::create([
            'work_order_id' => $workOrder->id,
            'wave_number' => 1,
            'plan_type' => 'initial',
            'planned_quantity' => 100,
            'status' => 'planned',
        ]);

        $rack = LostWaxCoatingRack::firstOrCreate(
            ['rack_number' => 1],
            ['status' => 'active']
        );

        return LostWaxTree::create([
            'work_order_id' => $workOrder->id,
            'work_order_plan_id' => $plan->id,
            'barcode' => $barcode,
            'family_code' => '1',
            'daily_sequence' => $treeNumber,
            'tree_number' => $treeNumber,
            'quantity' => 15,
            'production_date' => Carbon::create(2026, 8, 11),
            'status' => 'assembled',
            'rack_id' => $rack->id,
            'rack_assigned_at' => now(),
        ]);
    }

    public function test_qr_service_generates_valid_svg(): void
    {
        $barcode = '3110826005';
        $svg = $this->qrCodeService->generateSvg($barcode);

        $this->assertNotEmpty($svg);
        $this->assertStringStartsWith('<?xml', $svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);
    }

    public function test_qr_service_generates_valid_base64_data_uri(): void
    {
        $barcode = '3110826005';
        $dataUri = $this->qrCodeService->generateBase64DataUri($barcode);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $dataUri);

        $base64Content = substr($dataUri, strlen('data:image/svg+xml;base64,'));
        $decodedSvg = base64_decode($base64Content, true);

        $this->assertNotFalse($decodedSvg);
        $this->assertStringContainsString('<svg', $decodedSvg);
        $this->assertStringContainsString('</svg>', $decodedSvg);
    }

    public function test_qr_service_throws_on_empty_barcode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->qrCodeService->generateSvg('   ');
    }

    public function test_qr_service_batch_generation(): void
    {
        $tree1 = $this->createTreeFixture('3110826001', 1);
        $tree2 = $this->createTreeFixture('3110826002', 2);

        $batch = $this->qrCodeService->generateBatchDataUris([$tree1, $tree2]);

        $this->assertArrayHasKey($tree1->id, $batch);
        $this->assertArrayHasKey($tree2->id, $batch);
        $this->assertNotEquals($batch[$tree1->id], $batch[$tree2->id]);
    }

    public function test_traveler_card_page_renders_1d_barcode_and_qr_code_side_by_side(): void
    {
        $user = User::factory()->create();
        $tree = $this->createTreeFixture('3110826005');

        $response = $this->actingAs($user)
            ->get(route('lost-wax.trees.traveler', $tree));

        $response->assertOk();
        $response->assertSee('FORM BARCODE LAPISAN');
        $response->assertSee('LOST WAX TRAVELER');

        // Check Product Information fields
        $response->assertSee('1. KODE PRODUKSI');
        $response->assertSee('2. KODE ITEM');
        $response->assertSee('3. NAMA ITEM');
        $response->assertSee('4. UKURAN');
        $response->assertSee('5. AISI');
        $response->assertSee('6. KODE CUST');

        // Check 1D Barcode elements
        $response->assertSee(route('lost-wax.trees.barcode', $tree));
        $response->assertSee('3110826005');

        // Check QR Code elements
        $response->assertSee('data:image/svg+xml;base64,');
        $response->assertSee('SCAN QR');

        // Check Quantity and Footer elements
        $response->assertSee('ISI RANGKAIAN');
        $response->assertSee('15 PCS');
        $response->assertSee('TGL PRINT');
        $response->assertSee('JAM PRINT');
        $response->assertSee('KODE RAK');
        $response->assertSee('KETERANGAN');

        // Check view data bindings
        $response->assertViewHas('qrCodes', function ($qrCodes) use ($tree) {
            return isset($qrCodes[$tree->id]) && str_starts_with($qrCodes[$tree->id], 'data:image/svg+xml;base64,');
        });
    }

    public function test_bulk_traveler_cards_render_each_unique_qr_code(): void
    {
        $user = User::factory()->create();
        $tree1 = $this->createTreeFixture('3110826001', 1);
        $tree2 = $this->createTreeFixture('3110826002', 2);
        $tree3 = $this->createTreeFixture('3110826003', 3);

        $response = $this->actingAs($user)
            ->get(route('lost-wax.trees.traveler', [
                'tree' => $tree1->id,
                'ids' => implode(',', [$tree1->id, $tree2->id, $tree3->id]),
            ]));

        $response->assertOk();

        $response->assertViewHas('qrCodes', function ($qrCodes) use ($tree1, $tree2, $tree3) {
            $this->assertCount(3, $qrCodes);
            $this->assertArrayHasKey($tree1->id, $qrCodes);
            $this->assertArrayHasKey($tree2->id, $qrCodes);
            $this->assertArrayHasKey($tree3->id, $qrCodes);

            // Make sure all 3 are distinct
            $this->assertNotEquals($qrCodes[$tree1->id], $qrCodes[$tree2->id]);
            $this->assertNotEquals($qrCodes[$tree2->id], $qrCodes[$tree3->id]);
            $this->assertNotEquals($qrCodes[$tree1->id], $qrCodes[$tree3->id]);

            return true;
        });

        $response->assertSee($tree1->barcode);
        $response->assertSee($tree2->barcode);
        $response->assertSee($tree3->barcode);
    }

    public function test_1d_barcode_streaming_endpoint_remains_functional(): void
    {
        $user = User::factory()->create();
        $tree = $this->createTreeFixture('3110826005');

        $response = $this->actingAs($user)
            ->get(route('lost-wax.trees.barcode', $tree));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function test_thermal_print_flow_remains_functional(): void
    {
        $user = User::factory()->create();
        $tree = $this->createTreeFixture('3110826005');

        $response = $this->actingAs($user)
            ->post(route('lost-wax.trees.print-thermal'), [
                'ids' => (string) $tree->id,
            ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
        ]);
    }

    public function test_tspl_renderer_generates_dual_identifiers_side_by_side(): void
    {
        $tree = $this->createTreeFixture('2210826020');
        $renderer = new \App\Services\Barcode\Renderers\TsplRenderer;

        $tspl = $renderer->render($tree);

        // Verify label physical dimensions and box
        $this->assertStringContainsString('SIZE 90 mm, 50 mm', $tspl);
        $this->assertStringContainsString('BOX 20,10,700,390,3', $tspl);

        // Verify 1D Barcode with narrow 2 dots and centered text
        $this->assertStringContainsString('BARCODE 60,205,"128",90,0,0,2,4,"2210826020"', $tspl);
        $this->assertStringContainsString('TEXT 150,312,"2",0,1,1,2,"2210826020"', $tspl);

        // Verify native TSPL QRCODE enlarged 1.5x (cell_width=6, 126x126 dots, ISO 4-module quiet zone)
        $this->assertStringContainsString('QRCODE 306,210,M,6,A,0,"2210826020"', $tspl);

        // Verify dividers
        $this->assertStringContainsString('BAR 20,38,680,3', $tspl);
        $this->assertStringContainsString('BAR 20,183,680,3', $tspl);
        $this->assertStringContainsString('BAR 20,360,680,3', $tspl);
        $this->assertStringContainsString('BAR 275,183,3,177', $tspl);
        $this->assertStringContainsString('BAR 460,183,3,177', $tspl);
        $this->assertStringContainsString('BAR 360,360,3,30', $tspl);

        // Verify no URL, JSON, or extra wrapping in TSPL
        $this->assertStringNotContainsString('http://', $tspl);
        $this->assertStringNotContainsString('https://', $tspl);
        $this->assertStringNotContainsString('{"', $tspl);

        // Verify ISI RANGKAIAN column and PCS are intact
        $this->assertStringContainsString('ISI RANGKAIAN', $tspl);
        $this->assertStringContainsString('15 PCS', $tspl);

        // Verify production info and metadata blocks
        $this->assertStringContainsString('FORM BARCODE LAPISAN', $tspl);
        $this->assertStringContainsString('KODE PRODUKSI :', $tspl);
        $this->assertStringContainsString('KODE ITEM     :', $tspl);
        $this->assertStringContainsString('NAMA ITEM     :', $tspl);
        $this->assertStringContainsString('UKURAN        :', $tspl);
        $this->assertStringContainsString('AISI          :', $tspl);
        $this->assertStringContainsString('TEXT 30,364,"1",0,1,1,"TGL PRINT', $tspl);
        $this->assertStringContainsString('TEXT 30,376,"1",0,1,1,"JAM PRINT', $tspl);
    }

    public function test_traveler_card_thermal_format_mode_renders_correctly(): void
    {
        $user = User::factory()->create();
        $tree = $this->createTreeFixture('3110826005');

        $response = $this->actingAs($user)
            ->get(route('lost-wax.trees.traveler', [
                'tree' => $tree->id,
                'format' => 'thermal',
            ]));

        $response->assertOk();
        $response->assertSee('traveler-card-thermal');
        $response->assertSee('print-page-thermal');
        $response->assertSee('FORM BARCODE LAPISAN');
        $response->assertSee('3110826005');
        $response->assertSee('15 PCS');
    }
}
