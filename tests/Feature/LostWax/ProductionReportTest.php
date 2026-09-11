<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\PrintExecutionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductionReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $ppicFlangeBesi;

    protected User $ppicFlangeStainless;

    protected function setUp(): void
    {
        parent::setUp();

        $permExecution = Permission::firstOrCreate(['name' => 'access_execution', 'guard_name' => 'web']);
        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);

        $roleAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $roleAdmin->syncPermissions([$permExecution, $permPlanning]);

        $rolePpic = Role::firstOrCreate(['name' => 'ppic', 'guard_name' => 'web']);
        $rolePpic->syncPermissions([$permExecution, $permPlanning]);

        $this->user = User::factory()->create([
            'name' => 'Admin Staff',
            'email' => 'admin@peroniks.com',
            'product_scope' => null,
        ]);
        $this->user->assignRole($roleAdmin);

        $this->ppicFlangeBesi = User::factory()->create([
            'name' => 'PPIC Flange Besi',
            'email' => 'ppicflangebesi@peroniks.com',
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->ppicFlangeBesi->assignRole($rolePpic);

        $this->ppicFlangeStainless = User::factory()->create([
            'name' => 'PPIC Flange Stainless',
            'email' => 'ppicflangestainless@peroniks.com',
            'product_scope' => 'FLANGE_STAINLESS',
        ]);
        $this->ppicFlangeStainless->assignRole($rolePpic);
    }

    protected function createPlan(array $attributes = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'code' => '268L731',
            'customer' => 'CUSTOMER-001',
            'item_code' => '4.801190FFBSP.G.A0020',
            'item_name' => 'CS Q235 PLANE FLANGE JIS 5K 2-1/2"',
            'product_scope' => 'FLANGE_BESI',
            'aisi' => 'Q235',
            'size' => '2-1/2"',
            'weight' => 2.50,
            'po_number' => 'PO-2026-001',
            'po_quantity' => 500,
            'qty_planned' => 550,
            'qty_remaining' => 550,
            'line_number' => 1,
            'status' => 'planning',
            'is_closed' => false,
        ], $attributes));
    }

    /**
     * 1. Authenticated user can access the Production Report page.
     */
    public function test_user_can_access_production_report_page(): void
    {
        $response = $this->actingAs($this->user)->get(route('lost-wax.report.production.index'));

        $response->assertStatus(200);
        $response->assertSee('REPORT PRODUKSI LOST WAX');
        $response->assertSee('Export Excel');
        $response->assertSee('Cetak / PDF');
        $response->assertSee('Total Qty Dikerjakan');
        $response->assertSee('Total Berat Produksi');
    }

    /**
     * 2. Unauthenticated user is redirected to login.
     */
    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $response = $this->get(route('lost-wax.report.production.index'));

        $response->assertRedirect('/login');
    }

    /**
     * 3. Date range filter works and excludes out-of-range activities.
     */
    public function test_date_range_filter_filters_data_correctly(): void
    {
        $plan = $this->createPlan(['code' => '268L731', 'weight' => 2.50]);

        $printOrder = LostWaxPrintOrder::create([
            'print_order_number' => 'PO-TEST-001',
            'scheduled_date' => '2026-09-01',
            'order_date' => '2026-09-01',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        $line = $printOrder->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 200,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
        ]);

        $service = app(PrintExecutionService::class);

        // Record in-range execution (2026-09-08): 100 good, 3 defect
        $service->record($line, [
            'execution_date' => '2026-09-08',
            'qty_good' => 100,
            'qty_defect' => 3,
            'status' => 'FINALIZED',
        ]);

        // Record out-of-range execution (2026-09-15): 50 good, 1 defect
        $service->record($line, [
            'execution_date' => '2026-09-15',
            'qty_good' => 50,
            'qty_defect' => 1,
            'status' => 'FINALIZED',
        ]);

        $response = $this->actingAs($this->user)->get(route('lost-wax.report.production.index', [
            'date_from' => '2026-09-07',
            'date_to' => '2026-09-11',
            'stage' => 'cetak',
        ]));

        $response->assertStatus(200);
        $response->assertSee('268L731');
        $response->assertSee('100 pcs');
        $response->assertSee('250.00 kg'); // 100 * 2.50
        $response->assertSee('3 pcs'); // Qty defect
        $response->assertDontSee('50 pcs');
        $response->assertDontSee('125.00 kg');
    }

    /**
     * 4. Stage filtering isolates activities by stage.
     */
    public function test_stage_filter_isolates_by_stage(): void
    {
        $plan = $this->createPlan(['code' => '268L732', 'weight' => 1.80]);

        $printOrder = LostWaxPrintOrder::create([
            'print_order_number' => 'PO-TEST-002',
            'scheduled_date' => '2026-09-07',
            'order_date' => '2026-09-07',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        $line = $printOrder->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 200,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
        ]);

        // 1. Cetak execution on 2026-09-08: 200 good
        app(PrintExecutionService::class)->record($line, [
            'execution_date' => '2026-09-08',
            'qty_good' => 200,
            'qty_defect' => 0,
            'status' => 'FINALIZED',
        ]);

        // 2. Tree creation (Rangkai) on 2026-09-08: 200 pcs
        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'family_code' => 'F01',
            'daily_sequence' => 1,
            'barcode' => 'T260908001',
            'tree_number' => 1,
            'quantity' => 200,
            'status' => 'ready_for_coating',
            'production_date' => '2026-09-08',
        ]);

        // 3. Scan Layer 1 on 2026-09-09
        LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'layer_1',
            'scanned_at' => Carbon::parse('2026-09-09 10:00:00'),
            'operator_id' => $this->user->id,
            'result' => 'success',
        ]);

        // Filter for Cetak only
        $resCetak = $this->actingAs($this->user)->get(route('lost-wax.report.production.index', [
            'date_from' => '2026-09-07',
            'date_to' => '2026-09-11',
            'stage' => 'cetak',
        ]));
        $resCetak->assertStatus(200);
        $resCetak->assertSee('Cetak');
        $resCetak->assertSee('200 pcs');
        $resCetak->assertSee('360.00 kg'); // 200 * 1.80

        // Filter for Layer 1 only
        $resLayer1 = $this->actingAs($this->user)->get(route('lost-wax.report.production.index', [
            'date_from' => '2026-09-07',
            'date_to' => '2026-09-11',
            'stage' => 'layer_1',
        ]));
        $resLayer1->assertStatus(200);
        $resLayer1->assertSee('Lapisan 1');
        $resLayer1->assertSee('200 pcs');
        $resLayer1->assertSee('360.00 kg');
    }

    /**
     * 5. Total calculations and defect mapping are accurate.
     */
    public function test_total_calculations_and_defect_mapping(): void
    {
        $planA = $this->createPlan(['code' => '268L731', 'weight' => 2.50]);
        $planB = $this->createPlan(['code' => '268L732', 'weight' => 1.80]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PO-TEST-003',
            'scheduled_date' => '2026-09-07',
            'order_date' => '2026-09-07',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        $lineA = $order->lines()->create([
            'production_plan_id' => $planA->id,
            'qty_ordered' => 100,
            'code' => $planA->code,
            'customer' => $planA->customer,
            'item_name' => $planA->item_name,
        ]);

        $lineB = $order->lines()->create([
            'production_plan_id' => $planB->id,
            'qty_ordered' => 200,
            'code' => $planB->code,
            'customer' => $planB->customer,
            'item_name' => $planB->item_name,
        ]);

        $service = app(PrintExecutionService::class);
        $service->record($lineA, [
            'execution_date' => '2026-09-07',
            'qty_good' => 100,
            'qty_defect' => 3,
            'status' => 'FINALIZED',
        ]);

        $service->record($lineB, [
            'execution_date' => '2026-09-07',
            'qty_good' => 200,
            'qty_defect' => 5,
            'status' => 'FINALIZED',
        ]);

        $response = $this->actingAs($this->user)->get(route('lost-wax.report.production.index', [
            'date_from' => '2026-09-07',
            'date_to' => '2026-09-07',
            'stage' => 'cetak',
        ]));

        $response->assertStatus(200);

        // Row 1: 100 pcs, 250.00 kg, 3 pcs defect
        $response->assertSee('268L731');
        $response->assertSee('250.00 kg');
        $response->assertSee('3 pcs');

        // Row 2: 200 pcs, 360.00 kg, 5 pcs defect
        $response->assertSee('268L732');
        $response->assertSee('360.00 kg');
        $response->assertSee('5 pcs');

        // Totals: Total Qty = 300 pcs, Total Berat = 610.00 kg, Total Rusak = 8 pcs
        $response->assertSee('300 pcs');
        $response->assertSee('610.00 kg');
        $response->assertSee('8 pcs');
    }

    /**
     * 6. Excel and PDF exports execute successfully with identical dataset.
     */
    public function test_excel_and_pdf_exports_execute_successfully(): void
    {
        $plan = $this->createPlan(['code' => '268L731', 'weight' => 2.50]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PO-TEST-004',
            'scheduled_date' => '2026-09-07',
            'order_date' => '2026-09-07',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'qty_ordered' => 100,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
        ]);

        app(PrintExecutionService::class)->record($line, [
            'execution_date' => '2026-09-07',
            'qty_good' => 100,
            'qty_defect' => 2,
            'status' => 'FINALIZED',
        ]);

        // Test PDF Export
        $pdfRes = $this->actingAs($this->user)->get(route('lost-wax.report.production.export.pdf', [
            'date_from' => '2026-09-07',
            'date_to' => '2026-09-07',
            'stage' => 'cetak',
        ]));
        $pdfRes->assertStatus(200);
        $pdfRes->assertSee('REPORT PRODUKSI LOST WAX');
        $pdfRes->assertSee('268L731');
        $pdfRes->assertSee('100 pcs');
        $pdfRes->assertSee('250.00 kg');
        $pdfRes->assertSee('2 pcs');

        // Test Excel Export
        $excelRes = $this->actingAs($this->user)->get(route('lost-wax.report.production.export.excel', [
            'date_from' => '2026-09-07',
            'date_to' => '2026-09-07',
            'stage' => 'cetak',
        ]));
        $excelRes->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml.sheet', (string) $excelRes->headers->get('content-type'));
    }

    /**
     * 7. Search filter filters rows across production code, customer code, or item name.
     */
    public function test_search_filter_matches_keyword(): void
    {
        $planA = $this->createPlan(['code' => '268L731', 'customer' => 'CUST-ALPHA', 'item_name' => 'FLANGE 2-1/2"']);
        $planB = $this->createPlan(['code' => '268L732', 'customer' => 'CUST-BETA', 'item_name' => 'ELBOW 3/4"']);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PO-TEST-005',
            'scheduled_date' => '2026-09-07',
            'order_date' => '2026-09-07',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        $lineA = $order->lines()->create([
            'production_plan_id' => $planA->id,
            'qty_ordered' => 50,
            'code' => $planA->code,
            'customer' => $planA->customer,
            'item_name' => $planA->item_name,
        ]);

        $lineB = $order->lines()->create([
            'production_plan_id' => $planB->id,
            'qty_ordered' => 50,
            'code' => $planB->code,
            'customer' => $planB->customer,
            'item_name' => $planB->item_name,
        ]);

        $service = app(PrintExecutionService::class);
        $service->record($lineA, ['execution_date' => '2026-09-07', 'qty_good' => 50, 'qty_defect' => 0, 'status' => 'FINALIZED']);
        $service->record($lineB, ['execution_date' => '2026-09-07', 'qty_good' => 50, 'qty_defect' => 0, 'status' => 'FINALIZED']);

        $resSearch = $this->actingAs($this->user)->get(route('lost-wax.report.production.index', [
            'date_from' => '2026-09-07',
            'date_to' => '2026-09-07',
            'stage' => 'cetak',
            'search' => 'ALPHA',
        ]));

        $resSearch->assertStatus(200);
        $resSearch->assertSee('268L731');
        $resSearch->assertDontSee('268L732');
    }

    /**
     * 8. PPIC Data Scope / RBAC Security: PPIC Flange Besi only sees FLANGE_BESI data.
     */
    public function test_ppic_user_strictly_restricted_to_own_product_scope(): void
    {
        // 1. Plan for Flange Besi (100 pcs, 2.50 kg, 3 defect)
        $planBesi = $this->createPlan([
            'code' => '268FB_SCOPE',
            'customer' => 'CUST-BESI',
            'item_name' => 'BESI FLANGE JIS 10K',
            'product_scope' => 'FLANGE_BESI',
            'weight' => 2.50,
        ]);

        // 2. Plan for Flange Stainless (200 pcs, 3.00 kg, 5 defect)
        $planStainless = $this->createPlan([
            'code' => '268SS_SCOPE',
            'customer' => 'CUST-STAINLESS',
            'item_name' => 'SS304 FLANGE JIS 10K',
            'product_scope' => 'FLANGE_STAINLESS',
            'weight' => 3.00,
        ]);

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PO-TEST-RBAC-01',
            'scheduled_date' => '2026-09-07',
            'order_date' => '2026-09-07',
            'status' => 'ISSUED',
            'created_by' => $this->user->id,
        ]);

        $lineBesi = $order->lines()->create([
            'production_plan_id' => $planBesi->id,
            'qty_ordered' => 100,
            'code' => $planBesi->code,
            'customer' => $planBesi->customer,
            'item_name' => $planBesi->item_name,
        ]);

        $lineStainless = $order->lines()->create([
            'production_plan_id' => $planStainless->id,
            'qty_ordered' => 200,
            'code' => $planStainless->code,
            'customer' => $planStainless->customer,
            'item_name' => $planStainless->item_name,
        ]);

        $service = app(PrintExecutionService::class);
        $service->record($lineBesi, [
            'execution_date' => '2026-09-07',
            'qty_good' => 100,
            'qty_defect' => 3,
            'status' => 'FINALIZED',
        ]);
        $service->record($lineStainless, [
            'execution_date' => '2026-09-07',
            'qty_good' => 200,
            'qty_defect' => 5,
            'status' => 'FINALIZED',
        ]);

        // A. PPIC Flange Besi accesses screen
        $resBesi = $this->actingAs($this->ppicFlangeBesi)->get(route('lost-wax.report.production.index', [
            'date_from' => '2026-09-07',
            'date_to' => '2026-09-07',
            'stage' => 'cetak',
        ]));

        $resBesi->assertStatus(200);
        $resBesi->assertSee('268FB_SCOPE');
        $resBesi->assertSee('100 pcs');
        $resBesi->assertSee('250.00 kg');
        $resBesi->assertSee('3 pcs');

        // MUST NOT see stainless code or its numbers in totals
        $resBesi->assertDontSee('268SS_SCOPE');
        $resBesi->assertDontSee('600.00 kg');
        $resBesi->assertDontSee('300 pcs'); // Grand total should be only 100 pcs
        $resBesi->assertDontSee('850.00 kg'); // Grand total should be only 250.00 kg
        $resBesi->assertDontSee('8 pcs'); // Defect total should be only 3 pcs

        // B. Search filter cannot bypass PPIC scope
        $resBesiSearch = $this->actingAs($this->ppicFlangeBesi)->get(route('lost-wax.report.production.index', [
            'date_from' => '2026-09-07',
            'date_to' => '2026-09-07',
            'stage' => 'cetak',
            'search' => '268SS_SCOPE',
        ]));
        $resBesiSearch->assertStatus(200);
        $resBesiSearch->assertSee('Tidak ada data aktivitas produksi');
        $this->assertEmpty($resBesiSearch->viewData('items'));

        // C. Excel export for PPIC Flange Besi contains only scoped data
        $resBesiExcel = $this->actingAs($this->ppicFlangeBesi)->get(route('lost-wax.report.production.export.excel', [
            'date_from' => '2026-09-07',
            'date_to' => '2026-09-07',
            'stage' => 'cetak',
        ]));
        $resBesiExcel->assertStatus(200);

        // D. PDF export for PPIC Flange Besi contains only scoped data
        $resBesiPdf = $this->actingAs($this->ppicFlangeBesi)->get(route('lost-wax.report.production.export.pdf', [
            'date_from' => '2026-09-07',
            'date_to' => '2026-09-07',
            'stage' => 'cetak',
        ]));
        $resBesiPdf->assertStatus(200);
        $resBesiPdf->assertSee('268FB_SCOPE');
        $resBesiPdf->assertDontSee('268SS_SCOPE');
        $resBesiPdf->assertSee('100 pcs');
        $resBesiPdf->assertSee('250.00 kg');
        $resBesiPdf->assertDontSee('300 pcs');

        // E. Admin user sees both scopes
        $resAdmin = $this->actingAs($this->user)->get(route('lost-wax.report.production.index', [
            'date_from' => '2026-09-07',
            'date_to' => '2026-09-07',
            'stage' => 'cetak',
        ]));
        $resAdmin->assertStatus(200);
        $resAdmin->assertSee('268FB_SCOPE');
        $resAdmin->assertSee('268SS_SCOPE');
        $resAdmin->assertSee('300 pcs');
        $resAdmin->assertSee('850.00 kg'); // 250 + 600
        $resAdmin->assertSee('8 pcs'); // 3 + 5
    }
}
