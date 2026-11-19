<?php

namespace Tests\Feature\Fitting;

use App\Models\LostWaxCastingResult;
use App\Models\LostWaxCastingResultTree;
use App\Models\LostWaxKitir;
use App\Models\LostWaxTree;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\Fitting\FittingKanbanQueryService;
use App\Services\Fitting\FittingTransferService;
use Database\Seeders\FittingSpvUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FittingKanbanRefinementTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 500;

    private User $ppicFitting;

    private FittingKanbanQueryService $kanbanService;

    private FittingTransferService $transferService;

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
    }

    private function setEnvVar(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function createReadyKitir(string $code, string $customer, string $itemName, int $line = 1, int $qty = 30): array
    {
        $seq = self::$seq++;
        $plan = ProductionPlan::create([
            'code' => $code,
            'customer' => $customer,
            'item_code' => '4.101.'.$code,
            'item_name' => $itemName,
            'aisi' => '304',
            'size' => '1"',
            'weight' => 0.5,
            'po_number' => 'PO-'.$seq,
            'po_quantity' => 100,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => $line,
            'scheduled_date' => now()->format('Y-m-d'),
            'status' => 'planning',
            'product_scope' => 'FITTING_STAINLESS',
        ]);

        $printOrder = \App\Models\LostWaxPrintOrder::create([
            'print_order_number' => "LW-PO-{$seq}",
            'scheduled_date' => now()->format('Y-m-d'),
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
        $result = LostWaxCastingResult::create([
            'heat_number' => $heat,
            'cast_date' => now()->format('Y-m-d'),
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
            'production_date' => now()->format('Y-m-d'),
            'family_code' => 'DEMO',
            'quantity' => $qty,
            'current_stage' => 'cor',
            'status' => 'completed',
        ]);

        LostWaxCastingResultTree::create([
            'lost_wax_casting_result_id' => $result->id,
            'lost_wax_tree_id' => $tree->id,
            'active_tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'production_code' => $code,
            'customer' => $customer,
            'item_name' => $itemName,
            'quantity_pcs' => $qty,
            'gross_pcs' => $qty,
            'defect_pcs' => 0,
        ]);

        $kitirResolver = app(\App\Services\LostWax\LostWaxKitirResolverService::class);
        $kitir = $kitirResolver->issueOrGetKitir($result, $code, $this->ppicFitting);

        return [$plan, $result, $kitir];
    }

    public function test_01_customer_code_search_filters_travelers_accurately(): void
    {
        [$plan1, $res1, $kitir1] = $this->createReadyKitir('CODE-A02-1', 'A02', 'ELBOW 90 A02', 1, 20);
        [$plan2, $res2, $kitir2] = $this->createReadyKitir('CODE-A06-1', 'A06', 'TEE 90 A06', 2, 25);
        [$plan3, $res3, $kitir3] = $this->createReadyKitir('CODE-A02-2', 'A02', 'UNION A02', 3, 30);

        $this->transferService->transferKitirs(
            [$kitir1->id, $kitir2->id, $kitir3->id],
            $this->ppicFitting,
            null,
            null,
            now()->format('Y-m-d'),
            [
                $kitir1->id => 1,
                $kitir2->id => 2,
                $kitir3->id => 3,
            ]
        );

        // 1. Search 'A02' -> should return ONLY Kitir 1 and Kitir 3 across lines
        $dataA02 = $this->kanbanService->getStageKanbanData('netto_pf', ['search' => 'A02']);
        $this->assertEquals(2, $dataA02['summary']['total_cards']);
        $matchedCustomers = collect($dataA02['cards'])->pluck('customer')->all();
        $this->assertEquals(['A02', 'A02'], $matchedCustomers);

        // 2. Search 'A06' -> should return ONLY Kitir 2
        $dataA06 = $this->kanbanService->getStageKanbanData('netto_pf', ['search' => 'A06']);
        $this->assertEquals(1, $dataA06['summary']['total_cards']);
        $this->assertEquals('A06', $dataA06['cards'][0]['customer']);

        // 3. Search by item name 'UNION' -> should return Kitir 3
        $dataUnion = $this->kanbanService->getStageKanbanData('netto_pf', ['search' => 'UNION']);
        $this->assertEquals(1, $dataUnion['summary']['total_cards']);
        $this->assertEquals('CODE-A02-2', $dataUnion['cards'][0]['production_code']);

        // 4. Customer filter field 'customer' => 'A02'
        $dataCustFilter = $this->kanbanService->getStageKanbanData('netto_pf', ['customer' => 'A02']);
        $this->assertEquals(2, $dataCustFilter['summary']['total_cards']);
    }

    public function test_02_kanban_view_renders_compact_cards_and_independent_scroll_containers(): void
    {
        [$plan, $res, $kitir] = $this->createReadyKitir('CODE-VIEW-1', 'A02', 'SS304 ELBOW 90 F/F 1"', 1, 20);

        $this->transferService->transferKitirs(
            [$kitir->id],
            $this->ppicFitting,
            null,
            null,
            now()->format('Y-m-d'),
            [$kitir->id => 1]
        );

        $response = $this->actingAs($this->ppicFitting)->get(route('fitting.kanban.stage', 'netto-pf'));
        $response->assertOk();

        $content = $response->getContent();

        // 1. Independent scroll containers per column
        $this->assertStringContainsString('data-line-scroll="1"', $content);
        $this->assertStringContainsString('data-line-scroll="2"', $content);
        $this->assertStringContainsString('data-line-scroll="3"', $content);
        $this->assertStringContainsString('data-line-scroll="4"', $content);
        $this->assertStringContainsString('overflow-y-auto overscroll-contain', $content);
        $this->assertStringContainsString('h-[calc(100vh-215px)]', $content);

        // 2. Compact card elements
        $this->assertStringContainsString('kanban-card', $content);
        $this->assertStringContainsString('SS304 ELBOW 90 F/F', $content);
        $this->assertStringContainsString('CODE-VIEW-1', $content);
        $this->assertStringContainsString('A02', $content);
        $this->assertStringContainsString('Heat:', $content);
        $this->assertStringContainsString('PCS', $content);
        $this->assertStringContainsString('KG', $content);

        // 3. Export & Print Buttons
        $this->assertStringContainsString('CETAK PDF', $content);
        $this->assertStringContainsString('EXPORT EXCEL', $content);
        $this->assertStringContainsString(route('fitting.kanban.print', 'netto-pf'), $content);
        $this->assertStringContainsString(route('fitting.kanban.export.excel', 'netto-pf'), $content);
    }

    public function test_03_print_pdf_view_renders_correctly_with_filters(): void
    {
        [$plan, $res, $kitir] = $this->createReadyKitir('CODE-PRINT-1', 'A02', 'SS304 TEE 1"', 2, 15);

        $this->transferService->transferKitirs(
            [$kitir->id],
            $this->ppicFitting,
            null,
            null,
            now()->format('Y-m-d'),
            [$kitir->id => 2]
        );

        // Access print endpoint
        $response = $this->actingAs($this->ppicFitting)->get(route('fitting.kanban.print', [
            'stage' => 'netto-pf',
            'search' => 'A02',
        ]));

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('KANBAN FITTING PRODUCTION', $content);
        $this->assertStringContainsString('NETTO PF', $content);
        $this->assertStringContainsString('CODE-PRINT-1', $content);
        $this->assertStringContainsString('SS304 TEE', $content);
        $this->assertStringContainsString('[A02]', $content);
        $this->assertStringContainsString('Waktu Cetak:', $content);
        $this->assertStringContainsString('window.print()', $content);
    }

    public function test_04_export_excel_generates_valid_streamed_xlsx(): void
    {
        [$plan, $res, $kitir] = $this->createReadyKitir('CODE-EXCEL-1', 'A02', 'SS304 UNION 1"', 3, 40);

        $this->transferService->transferKitirs(
            [$kitir->id],
            $this->ppicFitting,
            null,
            null,
            now()->format('Y-m-d'),
            [$kitir->id => 3]
        );

        $response = $this->actingAs($this->ppicFitting)->get(route('fitting.kanban.export.excel', [
            'stage' => 'netto-pf',
            'search' => 'A02',
        ]));

        $response->assertOk();
        $this->assertEquals(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type')
        );
        $this->assertStringContainsString('Kanban-Fitting-netto-pf-', $response->headers->get('content-disposition'));
    }
}
