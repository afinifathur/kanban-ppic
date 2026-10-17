<?php

namespace Tests\Feature\SandCasting;

use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingResult;
use App\Models\SandCastingCastingResultLine;
use App\Models\SandCastingStageExecution;
use App\Models\User;
use App\Services\SandCasting\SandCastingStageExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductionFloorKanbanPrintTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $spvNetto;

    protected User $spvBubutOd;

    protected SandCastingStageExecutionService $executionService;

    protected function setUp(): void
    {
        parent::setUp();

        $planPerm = Permission::firstOrCreate(['name' => 'access_planning']);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $spvRole = Role::firstOrCreate(['name' => 'spv']);

        $adminRole->givePermissionTo($planPerm);

        $this->adminUser = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->adminUser->assignRole('admin');

        $this->spvNetto = User::factory()->create([
            'name' => 'SPV Netto',
            'email' => 'spv_netto@peroniks.com',
            'assigned_stage' => 'netto',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvNetto->assignRole('spv');

        $this->spvBubutOd = User::factory()->create([
            'name' => 'SPV Bubut OD',
            'email' => 'spv_bubut_od@peroniks.com',
            'assigned_stage' => 'bubut_od',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->spvBubutOd->assignRole('spv');

        $this->executionService = new SandCastingStageExecutionService;
    }

    protected function createKtr(array $attributes = []): SandCastingCastingResultLine
    {
        $plan = ProductionPlan::create([
            'code' => $attributes['production_code'] ?? '268ET'.rand(100, 999),
            'title' => 'Rencana SC '.rand(100, 999),
            'item_code' => $attributes['item_code'] ?? '4.'.rand(100, 999),
            'item_name' => $attributes['item_name'] ?? 'SS304 EN1092-1 PN16 DN350',
            'po_number' => 'PO-'.rand(100, 999),
            'line_number' => $attributes['line_number'] ?? 1,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'customer' => $attributes['customer'] ?? 'PT SINAR METAL',
            'product_scope' => 'FLANGE_BESI',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'status' => 'planning',
        ]);

        $order = SandCastingCastingOrder::create([
            'casting_order_number' => 'PCOR-20261017-'.rand(1000, 9999),
            'scheduled_date' => '2026-10-17',
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $orderLine = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'item_name' => $plan->item_name,
            'customer' => $plan->customer,
            'size' => '2"',
            'aisi' => 'FC250',
            'unit_weight_kg' => 2.50,
            'total_weight_kg' => 250.00,
            'status' => 'issued',
        ]);

        $result = SandCastingCastingResult::create([
            'heat_number' => $attributes['heat_number'] ?? 'HEAT'.rand(1000, 9999),
            'cast_date' => now()->toDateString(),
            'furnace' => 'F1',
            'shift' => '1',
            'operator_name' => 'Operator Cor',
            'recorded_by' => $this->adminUser->id,
            'total_qty_good' => 100,
            'total_qty_reject' => 0,
        ]);

        return $result->lines()->create(array_merge([
            'sand_casting_casting_order_line_id' => $orderLine->id,
            'production_plan_id' => $plan->id,
            'traveler_number' => $attributes['traveler_number'] ?? 'KTR-'.now()->format('Ymd').'-'.sprintf('%04d', rand(1, 9999)),
            'qty_good' => $attributes['qty_good'] ?? 50,
            'qty_reject' => 0,
            'unit_weight_kg' => $attributes['unit_weight_kg'] ?? 2.50,
            'total_weight_kg' => $attributes['total_weight_kg'] ?? 125.00,
            'current_stage' => $attributes['current_stage'] ?? 'netto',
            'queue_position' => $attributes['queue_position'] ?? null,
            'is_urgent' => $attributes['is_urgent'] ?? false,
        ], $attributes));
    }

    /**
     * TEST 1: Tombol Cetak PDF ada pada UI Kanban, di samping tombol Scan, dan disembunyikan di mobile.
     */
    public function test_kanban_index_contains_print_button_hidden_on_mobile_and_desktop_only(): void
    {
        $this->createKtr(['current_stage' => 'netto']);

        $res = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/netto');
        $res->assertStatus(200);

        // Scan button exists
        $res->assertSee('SCAN');
        $res->assertSee('fa-qrcode');

        // Print PDF button exists next to scan
        $res->assertSee('id="btnKanbanPrintPdf"', false);
        $res->assertSee('CETAK PDF');
        $res->assertSee('fa-print');

        // Desktop only class: hidden on mobile (<768px), inline-flex on md and up
        $res->assertSee('hidden md:inline-flex', false);

        // JavaScript function openKanbanPrintPdf exists
        $res->assertSee('function openKanbanPrintPdf()', false);
    }

    /**
     * TEST 2: Keamanan dan otorisasi tahap berlaku pada route cetak.
     */
    public function test_unauthenticated_and_unauthorized_user_cannot_access_print_route(): void
    {
        // 1. Unauthenticated redirects to login
        $resGuest = $this->get('/sand-casting/kanban/stage/netto/print');
        $resGuest->assertRedirect('/login');

        // 2. SPV Netto cannot print Bubut OD
        $resUnauthorized = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/stage/bubut-od/print');
        $resUnauthorized->assertStatus(403);

        // 3. Invalid stage returns 404
        $resNotFound = $this->actingAs($this->adminUser)->get('/sand-casting/kanban/stage/invalid-stage/print');
        $resNotFound->assertStatus(404);
    }

    /**
     * TEST 3: Cetak tanpa filter memuat seluruh kartu aktif per line dengan total yang tepat.
     */
    public function test_print_pdf_without_filters_contains_all_active_ready_cards_and_aggregates(): void
    {
        // Line 1: 2 cards (30 pcs + 20 pcs = 50 pcs, 100 kg)
        $c1 = $this->createKtr([
            'current_stage' => 'netto',
            'line_number' => 1,
            'qty_good' => 30,
            'total_weight_kg' => 60,
            'item_name' => 'FLANGE JIS 10K 2 INCH',
            'traveler_number' => 'KTR-L1-01',
        ]);
        $c2 = $this->createKtr([
            'current_stage' => 'netto',
            'line_number' => 1,
            'qty_good' => 20,
            'total_weight_kg' => 40,
            'item_name' => 'FLANGE JIS 10K 3 INCH',
            'traveler_number' => 'KTR-L1-02',
        ]);

        // Line 2: 1 card (15 pcs, 30 kg)
        $c3 = $this->createKtr([
            'current_stage' => 'netto',
            'line_number' => 2,
            'qty_good' => 15,
            'total_weight_kg' => 30,
            'item_name' => 'FITTING TEE 2 INCH',
            'traveler_number' => 'KTR-L2-01',
        ]);

        // Line 3: 1 card (25 pcs, 50 kg)
        $c4 = $this->createKtr([
            'current_stage' => 'netto',
            'line_number' => 3,
            'qty_good' => 25,
            'total_weight_kg' => 50,
            'item_name' => 'ELBOW 90 DEGREE',
            'traveler_number' => 'KTR-L3-01',
        ]);

        $res = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/stage/netto/print');
        $res->assertStatus(200);

        // Check header elements
        $res->assertSee('KANBAN PRODUKSI SAND CASTING &mdash; TAHAP NETTO', false);
        $res->assertSee('WIB');
        $res->assertSee('size: A4 landscape', false);
        $res->assertSee('4 Kartu Aktif');
        $res->assertSee('90 PCS');
        $res->assertSee('225 KG');

        // Check 4 columns exist
        $res->assertSee('LINE 1');
        $res->assertSee('LINE 2');
        $res->assertSee('LINE 3');
        $res->assertSee('LINE 4');

        // Check all items are present
        $res->assertSee('KTR-L1-01');
        $res->assertSee('KTR-L1-02');
        $res->assertSee('KTR-L2-01');
        $res->assertSee('KTR-L3-01');

        // Check queue numbers assigned
        $res->assertSee('#01');
        $res->assertSee('#02');
    }

    /**
     * TEST 4: Pencarian teks 'E02' menyaring tepat kartu yang cocok dan mempertahankan urutan Kanban.
     */
    public function test_print_pdf_search_e02_filters_exact_cards_in_same_order(): void
    {
        // 3 matching cards with 'E02'
        $match1 = $this->createKtr([
            'current_stage' => 'netto',
            'line_number' => 1,
            'traveler_number' => 'KTR-20261017-E02-01',
            'item_name' => 'VALVE BODY 2 INCH',
            'heat_number' => 'A214001',
            'qty_good' => 10,
            'total_weight_kg' => 20,
        ]);
        $match2 = $this->createKtr([
            'current_stage' => 'netto',
            'line_number' => 2,
            'traveler_number' => 'KTR-20261017-0099',
            'item_name' => 'FLANGE E02 SPECIAL',
            'heat_number' => 'A214002',
            'qty_good' => 15,
            'total_weight_kg' => 30,
        ]);
        $match3 = $this->createKtr([
            'current_stage' => 'netto',
            'line_number' => 3,
            'traveler_number' => 'KTR-20261017-0088',
            'item_name' => 'STANDARD FLANGE 3 INCH',
            'heat_number' => 'HEATE0299',
            'qty_good' => 5,
            'total_weight_kg' => 10,
        ]);

        // 2 non-matching cards
        $other1 = $this->createKtr([
            'current_stage' => 'netto',
            'line_number' => 1,
            'traveler_number' => 'KTR-20261017-0010',
            'item_name' => 'OTHER ITEM A',
            'heat_number' => 'A214003',
            'qty_good' => 50,
            'total_weight_kg' => 100,
        ]);
        $other2 = $this->createKtr([
            'current_stage' => 'netto',
            'line_number' => 4,
            'traveler_number' => 'KTR-20261017-0020',
            'item_name' => 'OTHER ITEM B',
            'heat_number' => 'A214004',
            'qty_good' => 20,
            'total_weight_kg' => 40,
        ]);

        $res = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/stage/netto/print?search=E02');
        $res->assertStatus(200);

        // Header shows search filter active
        $res->assertSee('Pencarian "<strong>E02</strong>"', false);
        $res->assertSee('3 Kartu Aktif');
        $res->assertSee('30 PCS');
        $res->assertSee('75 KG');

        // Matching items are included
        $res->assertSee('KTR-20261017-E02-01');
        $res->assertSee('FLANGE E02 SPECIAL');
        $res->assertSee('HEATE0299');

        // Non-matching items are EXCLUDED
        $res->assertDontSee('OTHER ITEM A');
        $res->assertDontSee('OTHER ITEM B');
        $res->assertDontSee('KTR-20261017-0010');
        $res->assertDontSee('KTR-20261017-0020');
    }

    /**
     * TEST 5: Fitur cetak berfungsi pada seluruh kelima tahap Kanban Sand Casting.
     */
    public function test_print_pdf_works_across_all_five_stages(): void
    {
        $stages = ['netto', 'bubut_od', 'bubut_cnc', 'bor', 'qc'];

        foreach ($stages as $stageKey) {
            $slug = str_replace('_', '-', $stageKey);
            $this->createKtr([
                'current_stage' => $stageKey,
                'traveler_number' => "KTR-TEST-{$slug}",
                'item_name' => "ITEM FOR {$stageKey}",
            ]);

            $res = $this->actingAs($this->adminUser)->get("/sand-casting/kanban/stage/{$slug}/print");
            $res->assertStatus(200);
            $res->assertSee("KTR-TEST-{$slug}");
            $res->assertSee("ITEM FOR {$stageKey}");
            $res->assertSee('size: A4 landscape', false);
            $res->assertSee('LINE 1');
            $res->assertSee('LINE 4');
        }
    }

    /**
     * TEST 6: Kartu incoming dan kartu halted tidak ikut tercetak.
     */
    public function test_incoming_and_halted_cards_are_never_printed(): void
    {
        // 1. Ready card in Bubut OD
        $readyKtr = $this->createKtr([
            'current_stage' => 'bubut_od',
            'traveler_number' => 'KTR-READY-OD',
            'item_name' => 'READY ITEM OD',
            'qty_good' => 20,
        ]);

        // 2. Incoming card (still in Netto)
        $incomingKtr = $this->createKtr([
            'current_stage' => 'netto',
            'traveler_number' => 'KTR-INCOMING-NETTO',
            'item_name' => 'INCOMING ITEM NETTO',
            'qty_good' => 30,
        ]);

        // 3. Halted card in Bubut OD (good_qty confirmed = 0)
        $haltedKtr = $this->createKtr([
            'current_stage' => 'bubut_od',
            'traveler_number' => 'KTR-HALTED-OD',
            'item_name' => 'HALTED ITEM OD',
            'qty_good' => 0,
        ]);
        $haltedKtr->stageExecutions()->create([
            'stage' => 'bubut_od',
            'checkpoint_code' => 'BUBUT_OD_ROUGH',
            'input_qty' => 15,
            'defect_qty' => 15,
            'good_qty' => 0,
            'status' => SandCastingStageExecution::STATUS_CONFIRMED,
            'operator_id' => $this->spvBubutOd->id,
            'defect_entered_by' => $this->adminUser->id,
            'qc_verified_by' => $this->adminUser->id,
            'executed_at' => now(),
        ]);

        $res = $this->actingAs($this->spvBubutOd)->get('/sand-casting/kanban/stage/bubut-od/print');
        $res->assertStatus(200);

        // Only ready card is printed
        $res->assertSee('KTR-READY-OD');
        $res->assertSee('READY ITEM OD');

        // Incoming and halted cards are NOT printed
        $res->assertDontSee('KTR-INCOMING-NETTO');
        $res->assertDontSee('INCOMING ITEM NETTO');
        $res->assertDontSee('KTR-HALTED-OD');
        $res->assertDontSee('HALTED ITEM OD');

        // Totals only count ready card (1 card, 20 pcs)
        $res->assertSee('1 Kartu Aktif');
        $res->assertSee('20 PCS');
    }

    /**
     * TEST 7: Seluruh field kartu Kanban tercetak lengkap dan akurat.
     */
    public function test_all_card_fields_rendered_in_print_view(): void
    {
        $this->createKtr([
            'current_stage' => 'netto',
            'line_number' => 2,
            'traveler_number' => 'KTR-FIELD-CHECK-01',
            'item_name' => 'SPECIAL FLANGE DN200 PN25',
            'heat_number' => 'HEAT998877',
            'production_code' => 'SPK-2026-X1',
            'customer' => 'PT SINAR METAL TEKNIK',
            'qty_good' => 42,
            'total_weight_kg' => 84,
            'is_urgent' => true,
        ]);

        $res = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/stage/netto/print');
        $res->assertStatus(200);

        $res->assertSee('KTR-FIELD-CHECK-01');
        $res->assertSee('SPECIAL FLANGE DN200 PN25');
        $res->assertSee('Heat: HEAT998877');
        $res->assertSee('SPK: SPK-2026-X1');
        $res->assertSee('PT SINAR METAL TEKNIK');
        $res->assertSee('42 PCS');
        $res->assertSee('105 KG');
        $res->assertSee('URGENT');
        $res->assertSee('#01');
        $res->assertSee('Aging');
    }

    /**
     * TEST 8: Filter per Line pada print view hanya mencetak kartu line tersebut.
     */
    public function test_single_line_filter_on_print_view(): void
    {
        $this->createKtr(['current_stage' => 'netto', 'line_number' => 1, 'item_name' => 'LINE 1 ONLY ITEM']);
        $this->createKtr(['current_stage' => 'netto', 'line_number' => 3, 'item_name' => 'LINE 3 ONLY ITEM']);

        $res = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/stage/netto/print?line_number=3');
        $res->assertStatus(200);

        $res->assertSee('Line 3');
        $res->assertSee('LINE 3 ONLY ITEM');
        $res->assertDontSee('LINE 1 ONLY ITEM');
        $res->assertSee('1 Kartu Aktif');
    }

    /**
     * TEST 9: Kondisi filter tanpa hasil menampilkan pesan ramah tanpa error.
     */
    public function test_empty_results_renders_clean_empty_message_without_errors(): void
    {
        $res = $this->actingAs($this->spvNetto)->get('/sand-casting/kanban/stage/netto/print?search=NONEXISTENT');
        $res->assertStatus(200);

        $res->assertSee('0 Kartu Aktif');
        $res->assertSee('0 PCS');
        $res->assertSee('0 KG');
        $res->assertSee('Tidak ada antrean kartu Kanban aktif yang sesuai dengan kriteria filter saat ini.');
    }
}
