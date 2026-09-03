<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\PrintExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompletedPrintOrderDetailTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $operatorUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'name' => 'PPIC Admin',
            'email' => 'ppicadmin@peroniks.com',
        ]);

        $this->operatorUser = User::factory()->create([
            'name' => 'Budi Operator Wax',
            'email' => 'operatorwax@peroniks.com',
        ]);
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'code' => '268L651',
            'customer' => 'PT PERONI INTI',
            'item_code' => '4.101105K.A0020',
            'item_name' => 'SS304 SQUARE DN 40',
            'aisi' => '304',
            'size' => 'DN 40',
            'weight' => 1.25,
            'po_number' => 'PO-2026-001',
            'po_quantity' => 1000,
            'qty_planned' => 1200,
            'qty_remaining' => 1200,
            'line_number' => 1,
            'status' => 'planning',
            'is_closed' => false,
        ], $attributes));
    }

    /**
     * TEST 1: Completed Print Order detail loads with rich outcome summary, item results, and history.
     */
    public function test_completed_print_order_detail_loads_rich_outcome_and_history(): void
    {
        $plan1 = $this->createPlan([
            'code' => '268L651',
            'item_name' => 'Flange SS304 2 Inch',
            'qty_planned' => 500,
            'po_quantity' => 450,
        ]);

        $plan2 = $this->createPlan([
            'code' => '268KS758',
            'item_name' => 'Elbow SUS316 1 Inch',
            'qty_planned' => 300,
            'po_quantity' => 300,
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260903-0044',
            'scheduled_date' => '2026-09-03',
            'status' => 'COMPLETED',
            'created_by' => $this->adminUser->id,
        ]);

        $line1 = $order->lines()->create([
            'production_plan_id' => $plan1->id,
            'qty_ordered' => 500,
            'code' => $plan1->code,
            'customer' => $plan1->customer,
            'item_name' => $plan1->item_name,
            'size' => $plan1->size,
            'aisi' => $plan1->aisi,
            'standard_tree_capacity' => 20,
        ]);

        $line2 = $order->lines()->create([
            'production_plan_id' => $plan2->id,
            'qty_ordered' => 300,
            'code' => $plan2->code,
            'customer' => $plan2->customer,
            'item_name' => $plan2->item_name,
            'size' => $plan2->size,
            'aisi' => $plan2->aisi,
            'standard_tree_capacity' => 15,
        ]);

        $printService = app(PrintExecutionService::class);

        // Record execution 1 for line 1 (Batch 1: 300 good, 10 defect)
        $printService->record($line1, [
            'qty_good' => 300,
            'qty_defect' => 10,
            'execution_date' => '2026-09-03',
            'status' => 'FINALIZED',
            'notes' => 'Shift 1 Pagi Cetak Awal',
            'recorded_by' => $this->operatorUser->id,
        ]);

        // Record execution 2 for line 1 (Batch 2: 190 good, 0 defect -> Total 490 good, 10 defect = 500 ordered)
        $printService->record($line1, [
            'qty_good' => 190,
            'qty_defect' => 0,
            'execution_date' => '2026-09-03',
            'status' => 'FINALIZED',
            'notes' => 'Shift 2 Siang Penyelesaian Line 1',
            'recorded_by' => $this->operatorUser->id,
        ]);

        // Record execution for line 2 (300 good, 0 defect)
        $printService->record($line2, [
            'qty_good' => 300,
            'qty_defect' => 0,
            'execution_date' => '2026-09-03',
            'status' => 'FINALIZED',
            'notes' => 'Line 2 Selesai Bersih',
            'recorded_by' => $this->operatorUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.print-orders.show', $order));

        $response->assertOk();

        // 1. Header Information
        $response->assertSee('PC-20260903-0044');
        $response->assertSee('COMPLETED');
        $response->assertSee('PPIC Admin');
        $response->assertSee('2 item');

        // 2. Summary KPI Banner
        $response->assertSee('Hasil Perintah Cetak');
        $response->assertSee('Total Good');
        $response->assertSee('790'); // 490 + 300 = 790
        $response->assertSee('Total Defect');
        $response->assertSee('10');
        $response->assertSee('Outstanding');
        $response->assertSee('0');
        $response->assertSee('Progress: 100%');

        // 3. Items list with individual Good / Defect / Outstanding
        $response->assertSee('268L651');
        $response->assertSee('Flange SS304 2 Inch');
        $response->assertSee('490 pcs');
        $response->assertSee('10 pcs');

        $response->assertSee('268KS758');
        $response->assertSee('Elbow SUS316 1 Inch');
        $response->assertSee('300 pcs');

        // 4. Riwayat Pencatatan Hasil
        $response->assertSee('Riwayat Pencatatan Hasil');
        $response->assertSee('Shift 1 Pagi Cetak Awal');
        $response->assertSee('Shift 2 Siang Penyelesaian Line 1');
        $response->assertSee('Line 2 Selesai Bersih');
        $response->assertSee('Budi Operator Wax');
        $response->assertSee('+300 pcs');
        $response->assertSee('+190 pcs');
        $response->assertSee('FINALIZED');

        // 5. Read-Only verification: No action buttons to edit or record
        $content = $response->getContent();
        $this->assertStringNotContainsString('Catat Hasil', $content);
        $this->assertStringNotContainsString('Hapus Draft', $content);
        $this->assertStringNotContainsString('Edit Dokumen', $content);
        $this->assertStringContainsString('Cetak Dokumen', $content);
        $this->assertStringContainsString('Dokumen berstatus <strong>COMPLETED</strong>', $content);
    }

    /**
     * TEST 2: Draft Print Order shows draft actions without outcome banner.
     */
    public function test_draft_print_order_shows_draft_actions(): void
    {
        $plan = $this->createPlan();

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260903-0001',
            'scheduled_date' => '2026-09-03',
            'status' => 'DRAFT',
            'created_by' => $this->adminUser->id,
        ]);

        $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 200,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
        ]);

        $response = $this->actingAs($this->adminUser)->get(route('lost-wax.print-orders.show', $order));

        $response->assertOk();
        $response->assertSee('DRAFT');
        $response->assertSee('Edit Dokumen');
        $response->assertSee('Terbitkan (Issue)');
        $response->assertSee('Hapus Draft');
    }
}
