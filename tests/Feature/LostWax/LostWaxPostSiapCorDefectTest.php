<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxHistoricalCutoff;
use App\Models\LostWaxHistoricalCutoffTree;
use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxPrintOrderLine;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\LostWaxTreeDefect;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\LostWax\LostWaxCastingResultService;
use App\Services\LostWax\ReadyForCastingService;
use App\Services\LostWax\ScanSiapCorService;
use App\Services\LostWaxDefectReportService;
use App\Services\LostWaxProductionReportService;
use App\Services\LostWaxQualityService;
use App\Services\ScanVoidService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LostWaxPostSiapCorDefectTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 1;

    private User $admin;

    private User $operator;

    private LostWaxQualityService $qualityService;

    private ScanSiapCorService $scanSiapCorService;

    private LostWaxCastingResultService $castingResultService;

    private ReadyForCastingService $readyForCastingService;

    private LostWaxDefectReportService $defectReportService;

    private LostWaxProductionReportService $productionReportService;

    private ScanVoidService $scanVoidService;

    protected function setUp(): void
    {
        parent::setUp();

        $permExec = Permission::firstOrCreate(['name' => 'access_execution', 'guard_name' => 'web']);
        $permPlan = Permission::firstOrCreate(['name' => 'access_planning', 'guard_name' => 'web']);
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions([$permExec, $permPlan]);

        $this->admin = User::create([
            'name' => 'Admin Lost Wax Post-Oven',
            'email' => 'admin-post-oven@peroniks.com',
            'password' => Hash::make('password'),
        ]);
        $this->admin->assignRole('admin');

        $this->operator = User::create([
            'name' => 'Operator Oven',
            'email' => 'op-oven@peroniks.com',
            'password' => Hash::make('password'),
        ]);
        $this->operator->assignRole('admin');

        $this->qualityService = app(LostWaxQualityService::class);
        $this->scanSiapCorService = app(ScanSiapCorService::class);
        $this->castingResultService = app(LostWaxCastingResultService::class);
        $this->readyForCastingService = app(ReadyForCastingService::class);
        $this->defectReportService = app(LostWaxDefectReportService::class);
        $this->productionReportService = app(LostWaxProductionReportService::class);
        $this->scanVoidService = app(ScanVoidService::class);
    }

    /**
     * Create a Lost Wax tree already at 'oven' stage with a valid oven scan event.
     *
     * @return array{plan: ProductionPlan, line: LostWaxPrintOrderLine, tree: LostWaxTree, ovenEvent: LostWaxScanEvent}
     */
    private function createOvenTree(array $opts = []): array
    {
        $n = self::$seq++;
        $code = $opts['code'] ?? sprintf('0926OVN%03d', $n);
        $qty = $opts['quantity'] ?? 100;
        $rawOvenAt = $opts['oven_scanned_at'] ?? null;
        $ovenScannedAt = $rawOvenAt instanceof Carbon
            ? $rawOvenAt
            : Carbon::parse($rawOvenAt ?? '2026-11-18 08:00:00', 'Asia/Jakarta');

        $plan = ProductionPlan::create([
            'code' => $code,
            'title' => "Plan {$code}",
            'item_code' => "ITM-OVN-{$n}",
            'item_name' => $opts['item_name'] ?? "Flange Oven Test {$code}",
            'aisi' => '304',
            'size' => '2"',
            'weight' => 1.50,
            'po_number' => "PO-OVN-{$n}",
            'po_quantity' => $qty,
            'qty_planned' => $qty,
            'qty_remaining' => $qty,
            'line_number' => 1,
            'customer' => $opts['customer'] ?? 'CUST-OVN',
            'product_scope' => 'FLANGE_STAINLESS',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'status' => 'active',
        ]);

        $printOrder = LostWaxPrintOrder::create([
            'print_order_number' => "PO-LW-OVN-{$n}",
            'scheduled_date' => '2026-11-18',
            'status' => 'ISSUED',
            'created_by' => $this->admin->id,
        ]);

        $line = LostWaxPrintOrderLine::create([
            'lost_wax_print_order_id' => $printOrder->id,
            'production_plan_id' => $plan->id,
            'code' => $code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'aisi' => '304',
            'size' => '2"',
            'qty_ordered' => $qty,
        ]);

        $barcode = sprintf('6181126%03d', $n + 100);
        $tree = LostWaxTree::create([
            'lost_wax_print_order_line_id' => $line->id,
            'barcode' => $barcode,
            'tree_number' => $n,
            'daily_sequence' => $n,
            'production_date' => '2026-11-18',
            'family_code' => '6',
            'require_layer_7' => false,
            'quantity' => $qty,
            'current_stage' => 'oven',
            'last_scan_at' => $ovenScannedAt,
            'status' => 'active',
        ]);

        $ovenEvent = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $barcode,
            'stage' => 'oven',
            'scanned_at' => $ovenScannedAt,
            'operator_id' => $this->operator->id,
            'result' => 'success',
        ]);

        return [
            'plan' => $plan,
            'line' => $line,
            'tree' => $tree,
            'ovenEvent' => $ovenEvent,
        ];
    }

    public function test_full_mass_balance_100_to_90_siap_cor_to_80_post_defect_and_cor_across_all_reports_and_excel(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 09:00:00', 'Asia/Jakarta'));
        $this->actingAs($this->admin);

        ['plan' => $plan, 'tree' => $tree] = $this->createOvenTree([
            'code' => '0926BAL001',
            'quantity' => 100,
            'oven_scanned_at' => Carbon::parse('2026-11-18 08:00:00', 'Asia/Jakarta'),
        ]);

        // 1. Record 10 PCS pre-Siap Cor Oven defect
        $preDefect = $this->qualityService->recordDefect(
            tree: $tree,
            stage: 'oven',
            defectQty: 10,
            defectReason: 'keramik_retak',
            notes: 'Retak saat keluar oven',
            occurredAt: Carbon::parse('2026-11-18 08:30:00', 'Asia/Jakarta'),
            userId: $this->operator->id,
            defectContext: LostWaxTreeDefect::CONTEXT_PRE_SIAP_COR
        );

        $this->assertEquals('oven', $preDefect->stage);
        $this->assertEquals(LostWaxTreeDefect::CONTEXT_PRE_SIAP_COR, $preDefect->defect_context);
        $this->assertNull($preDefect->scan_event_id);
        $this->assertFalse($preDefect->is_late_entry);
        $this->assertEquals(90, $tree->fresh()->usable_quantity);

        // 2. Scan Siap Cor -> scanned_qty snapshot = 90
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00', 'Asia/Jakarta'));
        $scanResult = $this->scanSiapCorService->processScan($tree->barcode, $this->operator->id);
        $this->assertTrue($scanResult['success']);

        $siapCorEvent = $scanResult['event'];
        $this->assertEquals(90, $siapCorEvent->scanned_qty);
        $this->assertEquals('siap_cor', $tree->fresh()->current_stage);
        $this->assertEquals(90, $tree->fresh()->siap_cor_historical_quantity);
        $this->assertEquals(90, $tree->fresh()->usable_quantity);

        // 3. Record 10 PCS post-Siap Cor defect (still stage = 'oven', linked to siap_cor scan_event_id)
        Carbon::setTestNow(Carbon::parse('2026-11-18 11:00:00', 'Asia/Jakarta'));
        $postDefect = $this->qualityService->recordDefect(
            tree: $tree,
            stage: 'oven',
            defectQty: 10,
            defectReason: 'jatuh_handling',
            notes: 'Tersenggol rak antrean cor',
            occurredAt: Carbon::parse('2026-11-18 10:45:00', 'Asia/Jakarta'),
            userId: $this->operator->id,
            defectContext: LostWaxTreeDefect::CONTEXT_POST_SIAP_COR,
            scanEventId: $siapCorEvent->id
        );

        $this->assertEquals('oven', $postDefect->stage);
        $this->assertEquals(LostWaxTreeDefect::CONTEXT_POST_SIAP_COR, $postDefect->defect_context);
        $this->assertEquals($siapCorEvent->id, $postDefect->scan_event_id);
        $this->assertFalse($postDefect->is_late_entry);

        // Verify model quantities: historical stays 90, active becomes 80, snapshot stays 90!
        $tree->refresh();
        $siapCorEvent->refresh();
        $this->assertEquals(90, $siapCorEvent->scanned_qty);
        $this->assertEquals(90, $tree->siap_cor_historical_quantity);
        $this->assertEquals(80, $tree->usable_quantity);
        $this->assertEquals(20, $tree->total_defect_quantity);
        $this->assertEquals(10, $tree->pre_siap_cor_defect_quantity);
        $this->assertEquals(10, $tree->post_siap_cor_defect_quantity);

        // 4. Verify Ready For Casting Queue reflects 80 PCS active (20 defect)
        $rfc = $this->readyForCastingService->getQueueData(null, null, [$plan->code]);
        $this->assertCount(1, $rfc['items']);
        $this->assertEquals(80, $rfc['items'][0]['quantity_pcs']);
        $this->assertEquals(20, $rfc['items'][0]['defect_pcs']);

        // 5. Verify /lost-wax/production-status Web + Excel before casting
        $psResponse = $this->get(route('lost-wax.production-status', ['codes' => [$plan->code]]));
        $psResponse->assertOk();
        $psResponse->assertSee('0926BAL001');
        $psResponse->assertSee('Hist: 90');
        $psRows = $psResponse->viewData('rows');
        $this->assertCount(1, $psRows);
        $this->assertEquals(20, $psRows[0]['r_oven']);
        $this->assertEquals(10, $psRows[0]['r_oven_pre_siap_cor']);
        $this->assertEquals(10, $psRows[0]['r_oven_post_siap_cor']);
        $this->assertEquals(80, $psRows[0]['siap_cor_qty']);
        $this->assertEquals(90, $psRows[0]['siap_cor_historical_qty']);
        $this->assertEquals(90, $psRows[0]['siap_cor_scanned_qty']);
        $this->assertEquals(0, $psRows[0]['cor_qty']);
        $this->assertEquals(80, $psRows[0]['total_distributed']);
        $this->assertEquals(20, $psRows[0]['overall_defect']);

        // Verify Excel export has 'Siap Cor Historis (pcs)' right of 'Siap Cor (pcs)'
        $xlsxResponse = $this->get(route('lost-wax.production-status.export', ['codes' => [$plan->code]]));
        $xlsxResponse->assertOk();
        $tmpDir = storage_path('framework'.DIRECTORY_SEPARATOR.'testing');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0777, true);
        }
        $xlsxBytes = $xlsxResponse->streamedContent();
        $this->assertNotEmpty($xlsxBytes, 'Expected non-empty XLSX streamed content');
        $tmpFile = $tmpDir.DIRECTORY_SEPARATOR.'ps_xlsx_'.uniqid().'.xlsx';
        $written = file_put_contents($tmpFile, $xlsxBytes);
        $this->assertNotFalse($written, "Failed to write temp XLSX at {$tmpFile}");
        $cells = [];
        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($tmpFile) === true, 'Valid XLSX ZIP archive expected');
            $sharedStrings = [];
            $ssXmlStr = $zip->getFromName('xl/sharedStrings.xml');
            if ($ssXmlStr !== false) {
                $ssXml = simplexml_load_string($ssXmlStr);
                foreach ($ssXml->si as $si) {
                    $sharedStrings[] = (string) ($si->t ?? '');
                }
            }
            $sheetXml = simplexml_load_string((string) $zip->getFromName('xl/worksheets/sheet1.xml'));
            foreach ($sheetXml->sheetData->row as $row) {
                foreach ($row->c as $c) {
                    $ref = (string) $c['r'];
                    $type = (string) $c['t'];
                    if ($type === 's') {
                        $cells[$ref] = $sharedStrings[(int) $c->v] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $cells[$ref] = (string) ($c->is->t ?? '');
                    } else {
                        $cells[$ref] = (string) ($c->v ?? '');
                    }
                }
            }
            $zip->close();
        } finally {
            @unlink($tmpFile);
        }

        $this->assertEquals('Siap Cor (pcs)', $cells['R6'] ?? null);
        $this->assertEquals('Siap Cor Historis (pcs)', $cells['S6'] ?? null);
        $this->assertEquals('Cor (pcs)', $cells['T6'] ?? null);
        $this->assertEquals('Kecukupan', $cells['U6'] ?? null);
        $this->assertEquals('80', $cells['R7'] ?? null);
        $this->assertEquals('90', $cells['S7'] ?? null);
        $this->assertEquals('-', $cells['T7'] ?? null);

        // 6. Verify /lost-wax/quality/defects (all + post_siap_cor filter)
        $defAll = $this->defectReportService->getDefectDataset([
            'date_from' => '2026-11-18',
            'date_to' => '2026-11-18',
            'mode' => 'detail',
        ], $this->admin);
        $this->assertEquals(20, $defAll['summary']['oven']);
        $this->assertEquals(10, $defAll['summary']['oven_pre_siap_cor']);
        $this->assertEquals(10, $defAll['summary']['oven_post_siap_cor']);
        $this->assertEquals(20, $defAll['summary']['grand_total']);

        $defPostOnly = $this->defectReportService->getDefectDataset([
            'date_from' => '2026-11-18',
            'date_to' => '2026-11-18',
            'defect_context' => LostWaxTreeDefect::CONTEXT_POST_SIAP_COR,
            'mode' => 'detail',
        ], $this->admin);
        $this->assertEquals(10, $defPostOnly['summary']['oven']);
        $this->assertEquals(0, $defPostOnly['summary']['oven_pre_siap_cor']);
        $this->assertEquals(10, $defPostOnly['summary']['oven_post_siap_cor']);
        $this->assertCount(1, $defPostOnly['items']);

        // 7. Verify /lost-wax/report/production has ZERO double-counting
        $prodRep = $this->productionReportService->getProductionDataset([
            'date_from' => '2026-11-18',
            'date_to' => '2026-11-18',
            'stage' => 'oven',
        ], $this->admin);
        $this->assertCount(1, $prodRep['items']);
        $this->assertEquals(100, $prodRep['items'][0]['qty_processed']);
        $this->assertEquals(20, $prodRep['items'][0]['qty_defect']);

        // 8. Execute Casting Result -> consumes 80 PCS active, historical stays 90!
        $castingResult = $this->castingResultService->storeCastingResult([
            'heat_number' => 'HT-BAL-001',
            'cast_date' => '2026-11-18',
            'tree_ids' => [$tree->id],
        ], $this->admin);
        $this->assertEquals(80, $castingResult->total_pcs);
        $this->assertEquals(80, $castingResult->trees->first()->quantity_pcs);
        $this->assertEquals(20, $castingResult->trees->first()->defect_pcs);

        // Verify Production Status after casting: Siap Cor active = 0, Historical = 90, Cor = 80, R Oven = 20
        $psAfter = $this->get(route('lost-wax.production-status', ['codes' => [$plan->code], 'filter' => 'all']));
        $psAfter->assertOk();
        $afterRows = $psAfter->viewData('rows');
        $this->assertEquals(0, $afterRows[0]['siap_cor_qty']);
        $this->assertEquals(90, $afterRows[0]['siap_cor_historical_qty']);
        $this->assertEquals(80, $afterRows[0]['cor_qty']);
        $this->assertEquals(20, $afterRows[0]['r_oven']);
        $this->assertEquals(80, $afterRows[0]['total_distributed']);
        $this->assertEquals(20, $afterRows[0]['overall_defect']);

        Carbon::setTestNow();
    }

    public function test_scanned_qty_immutability_and_strict_scan_event_id_validation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00', 'Asia/Jakarta'));
        ['tree' => $treeA, 'ovenEvent' => $ovenEventA] = $this->createOvenTree(['code' => '0926IMM001', 'quantity' => 50]);
        ['tree' => $treeB] = $this->createOvenTree(['code' => '0926IMM002', 'quantity' => 50]);

        $resA = $this->scanSiapCorService->processScan($treeA->barcode, $this->operator->id);
        $resB = $this->scanSiapCorService->processScan($treeB->barcode, $this->operator->id);
        $eventA = $resA['event'];
        $eventB = $resB['event'];

        $this->assertEquals(50, $eventA->scanned_qty);

        // 1. Modifying scanned_qty on existing event MUST throw LogicException
        try {
            $eventA->update(['scanned_qty' => 40]);
            $this->fail('Expected LogicException when mutating scanned_qty');
        } catch (LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
        $this->assertEquals(50, $eventA->fresh()->scanned_qty);

        // 2. Legacy scan event with scanned_qty = NULL must stay NULL and cannot be backfilled via update
        $legacyEvent = LostWaxScanEvent::create([
            'tree_id' => $treeA->id,
            'barcode' => $treeA->barcode,
            'stage' => 'layer_1',
            'scanned_at' => Carbon::parse('2026-11-17 08:00:00', 'Asia/Jakarta'),
            'operator_id' => $this->operator->id,
            'result' => 'success',
            'scanned_qty' => null,
        ]);
        $this->assertNull($legacyEvent->scanned_qty);
        try {
            $legacyEvent->update(['scanned_qty' => 50]);
            $this->fail('Expected LogicException when backfilling legacy NULL scanned_qty');
        } catch (LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
        $this->assertNull($legacyEvent->fresh()->scanned_qty);

        // 3. Strict scan_event_id validation: wrong tree's siap_cor event rejected
        try {
            $this->qualityService->recordDefect(
                tree: $treeA,
                stage: 'oven',
                defectQty: 5,
                defectReason: 'jatuh_handling',
                userId: $this->operator->id,
                defectContext: LostWaxTreeDefect::CONTEXT_POST_SIAP_COR,
                scanEventId: $eventB->id
            );
            $this->fail('Expected InvalidArgumentException for cross-tree scan_event_id');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak cocok', $e->getMessage());
        }

        // 4. Wrong stage (oven event instead of siap_cor) rejected
        try {
            $this->qualityService->recordDefect(
                tree: $treeA,
                stage: 'oven',
                defectQty: 5,
                defectReason: 'jatuh_handling',
                userId: $this->operator->id,
                defectContext: LostWaxTreeDefect::CONTEXT_POST_SIAP_COR,
                scanEventId: $ovenEventA->id
            );
            $this->fail('Expected InvalidArgumentException for non-siap_cor scan_event_id');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak cocok', $e->getMessage());
        }

        // 5. Rejected scan event rejected
        $rejectedEvent = LostWaxScanEvent::create([
            'tree_id' => $treeA->id,
            'barcode' => $treeA->barcode,
            'stage' => 'siap_cor',
            'scanned_at' => Carbon::parse('2026-11-18 10:05:00', 'Asia/Jakarta'),
            'operator_id' => $this->operator->id,
            'result' => 'rejected',
            'reject_reason' => 'duplicate',
        ]);
        try {
            $this->qualityService->recordDefect(
                tree: $treeA,
                stage: 'oven',
                defectQty: 5,
                defectReason: 'jatuh_handling',
                userId: $this->operator->id,
                defectContext: LostWaxTreeDefect::CONTEXT_POST_SIAP_COR,
                scanEventId: $rejectedEvent->id
            );
            $this->fail('Expected InvalidArgumentException for rejected scan_event_id');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak cocok', $e->getMessage());
        }

        // 6. Voided scan_event_id rejected
        $this->scanVoidService->void($eventB, 'Salah scan', $this->admin->id);
        try {
            $this->qualityService->recordDefect(
                tree: $treeB,
                stage: 'oven',
                defectQty: 5,
                defectReason: 'jatuh_handling',
                userId: $this->operator->id,
                defectContext: LostWaxTreeDefect::CONTEXT_POST_SIAP_COR,
                scanEventId: $eventB->id
            );
            $this->fail('Expected InvalidArgumentException for voided scan_event_id');
        } catch (InvalidArgumentException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        Carbon::setTestNow();
    }

    public function test_late_pre_siap_cor_correction_and_honest_null_occurred_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00', 'Asia/Jakarta'));
        $this->actingAs($this->admin);

        ['tree' => $tree] = $this->createOvenTree([
            'code' => '0926LATE01',
            'quantity' => 100,
            'oven_scanned_at' => Carbon::parse('2026-11-18 08:00:00', 'Asia/Jakarta'),
        ]);

        // Scan Siap Cor at 10:00 WIB -> scanned_qty = 100
        $scanRes = $this->scanSiapCorService->processScan($tree->barcode, $this->operator->id);
        $siapCorEvent = $scanRes['event'];
        $this->assertEquals(100, $siapCorEvent->scanned_qty);

        // A. Late pre_siap_cor without notes -> rejected
        Carbon::setTestNow(Carbon::parse('2026-11-18 11:30:00', 'Asia/Jakarta'));
        try {
            $this->qualityService->recordDefect(
                tree: $tree,
                stage: 'oven',
                defectQty: 10,
                defectReason: 'keramik_retak',
                notes: 'ok', // too short
                occurredAt: Carbon::parse('2026-11-18 09:00:00', 'Asia/Jakarta'),
                userId: $this->operator->id,
                defectContext: LostWaxTreeDefect::CONTEXT_PRE_SIAP_COR
            );
            $this->fail('Expected rejection for missing/short notes on late pre_siap_cor correction');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('catatan', strtolower($e->getMessage()));
        }

        // B. Late pre_siap_cor with occurred_at AFTER siap_cor scanned_at -> rejected
        try {
            $this->qualityService->recordDefect(
                tree: $tree,
                stage: 'oven',
                defectQty: 10,
                defectReason: 'keramik_retak',
                notes: 'Lupa input saat keluar oven',
                occurredAt: Carbon::parse('2026-11-18 10:30:00', 'Asia/Jakarta'), // after 10:00!
                userId: $this->operator->id,
                defectContext: LostWaxTreeDefect::CONTEXT_PRE_SIAP_COR
            );
            $this->fail('Expected rejection when occurred_at > siap_cor scanned_at for pre_siap_cor');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('lebih baru dari waktu Scan Siap Cor', $e->getMessage());
        }

        // C. Late pre_siap_cor with NULL occurred_at WITHOUT explicit confirmation -> rejected
        try {
            $this->qualityService->recordDefect(
                tree: $tree,
                stage: 'oven',
                defectQty: 10,
                defectReason: 'keramik_retak',
                notes: 'Lupa input saat keluar oven',
                occurredAt: null,
                userId: $this->operator->id,
                defectContext: LostWaxTreeDefect::CONTEXT_PRE_SIAP_COR,
                scanEventId: null,
                confirmUnknownOccurredAt: false
            );
            $this->fail('Expected rejection when occurred_at is NULL without confirm_unknown_occurred_at');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('waktu kejadian', strtolower($e->getMessage()));
        }

        // D. Late pre_siap_cor with NULL occurred_at + explicit confirmation -> ACCEPTED, occurred_at stays NULL!
        $lateDefect = $this->qualityService->recordDefect(
            tree: $tree,
            stage: 'oven',
            defectQty: 10,
            defectReason: 'keramik_retak',
            notes: 'Operator oven lupa catat sebelum scan siap cor',
            occurredAt: null,
            userId: $this->operator->id,
            defectContext: LostWaxTreeDefect::CONTEXT_PRE_SIAP_COR,
            scanEventId: null,
            confirmUnknownOccurredAt: true
        );

        $this->assertTrue($lateDefect->is_late_entry);
        $this->assertEquals(LostWaxTreeDefect::CONTEXT_PRE_SIAP_COR, $lateDefect->defect_context);
        $this->assertNull($lateDefect->scan_event_id);
        $this->assertNull($lateDefect->occurred_at); // MUST NOT be fabricated as now()!
        $this->assertNotNull($lateDefect->created_at);

        // scanned_qty stays 100 (immutable snapshot), siap_cor_historical_quantity adjusts to 90!
        $this->assertEquals(100, $siapCorEvent->fresh()->scanned_qty);
        $this->assertEquals(90, $tree->fresh()->siap_cor_historical_quantity);
        $this->assertEquals(90, $tree->fresh()->usable_quantity);

        // E. HTTP POST omitting occurred_at for post_siap_cor also keeps occurred_at = NULL
        $httpRes = $this->post(route('lost-wax.trees.defects.store', $tree), [
            'stage' => 'oven',
            'defect_context' => LostWaxTreeDefect::CONTEXT_POST_SIAP_COR,
            'scan_event_id' => $siapCorEvent->id,
            'defect_qty' => 5,
            'defect_reason' => 'jatuh_handling',
            'occurred_at' => '',
            'notes' => 'Tersenggol saat pemindahan rak',
        ]);
        $httpRes->assertRedirect();
        $httpRes->assertSessionHas('success');

        $savedHttpDefect = LostWaxTreeDefect::where('lost_wax_tree_id', $tree->id)
            ->where('defect_context', LostWaxTreeDefect::CONTEXT_POST_SIAP_COR)
            ->first();
        $this->assertNotNull($savedHttpDefect);
        $this->assertNull($savedHttpDefect->occurred_at);
        $this->assertEquals(85, $tree->fresh()->usable_quantity);
        $this->assertEquals(90, $tree->fresh()->siap_cor_historical_quantity);

        Carbon::setTestNow();
    }

    public function test_full_100_percent_post_siap_cor_defect_removes_tree_from_ready_for_casting_and_blocks_casting(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00', 'Asia/Jakarta'));
        $this->actingAs($this->admin);

        ['plan' => $plan, 'tree' => $tree] = $this->createOvenTree([
            'code' => '0926ZERO01',
            'quantity' => 90,
        ]);

        $scanRes = $this->scanSiapCorService->processScan($tree->barcode, $this->operator->id);
        $siapCorEvent = $scanRes['event'];

        // Record 90/90 post-Siap Cor breakage -> usable = 0
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:35:00', 'Asia/Jakarta'));
        $this->qualityService->recordDefect(
            tree: $tree,
            stage: 'oven',
            defectQty: 90,
            defectReason: 'pecah_terjatuh',
            notes: 'Pohon jatuh seluruhnya pecah',
            occurredAt: Carbon::parse('2026-11-18 10:30:00', 'Asia/Jakarta'),
            userId: $this->operator->id,
            defectContext: LostWaxTreeDefect::CONTEXT_POST_SIAP_COR,
            scanEventId: $siapCorEvent->id
        );

        $tree->refresh();
        $this->assertEquals(0, $tree->usable_quantity);
        $this->assertEquals(90, $tree->siap_cor_historical_quantity);
        $this->assertEquals(90, $siapCorEvent->fresh()->scanned_qty);

        // Removed from Ready For Casting queue & dynamic scan dates
        $rfc = $this->readyForCastingService->getQueueData(null, null, [$plan->code]);
        $this->assertCount(0, $rfc['items']);
        $this->assertCount(0, $rfc['all_scan_dates']);

        // Blocked from Casting Result creation
        try {
            $this->castingResultService->storeCastingResult([
                'heat_number' => 'HT-ZERO-01',
                'cast_date' => '2026-11-18',
                'tree_ids' => [$tree->id],
            ], $this->admin);
            $this->fail('Expected InvalidArgumentException when casting a 0-usable-qty tree');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('0 PCS', $e->getMessage());
        }

        Carbon::setTestNow();
    }

    public function test_soft_void_with_audit_trail_and_defense_in_depth_locks(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00', 'Asia/Jakarta'));
        $this->actingAs($this->admin);

        ['plan' => $plan, 'tree' => $tree] = $this->createOvenTree([
            'code' => '0926VOID01',
            'quantity' => 100,
        ]);

        $scanRes = $this->scanSiapCorService->processScan($tree->barcode, $this->operator->id);
        $siapCorEvent = $scanRes['event'];

        Carbon::setTestNow(Carbon::parse('2026-11-18 10:20:00', 'Asia/Jakarta'));
        $postDefect = $this->qualityService->recordDefect(
            tree: $tree,
            stage: 'oven',
            defectQty: 15,
            defectReason: 'jatuh_handling',
            notes: 'Salah hitung awal',
            occurredAt: Carbon::parse('2026-11-18 10:15:00', 'Asia/Jakarta'),
            userId: $this->operator->id,
            defectContext: LostWaxTreeDefect::CONTEXT_POST_SIAP_COR,
            scanEventId: $siapCorEvent->id
        );
        $this->assertEquals(85, $tree->fresh()->usable_quantity);

        // 1. Model guards: hard delete blocked, editing original columns blocked
        try {
            $postDefect->delete();
            $this->fail('Expected LogicException on hard delete of LostWaxTreeDefect');
        } catch (LogicException $e) {
            $this->assertStringContainsString('soft-void', strtolower($e->getMessage()));
        }

        try {
            $postDefect->update(['defect_qty' => 5]);
            $this->fail('Expected LogicException on mutating defect_qty');
        } catch (LogicException $e) {
            $this->assertStringContainsString('audit trail', strtolower($e->getMessage()));
        }

        // 2. ScanVoidService MUST block voiding siap_cor while active post_siap_cor defect is linked
        try {
            $this->scanVoidService->void($siapCorEvent, 'Coba void scan', $this->admin->id);
            $this->fail('Expected InvalidArgumentException when voiding siap_cor with linked active post_siap_cor defect');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('defect Pasca-Siap Cor aktif', $e->getMessage());
        }

        // 3. Soft-void via HTTP endpoint restores usable_quantity and preserves audit trail
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:45:00', 'Asia/Jakarta'));
        $voidRes = $this->post(route('lost-wax.trees.defects.void', [$tree, $postDefect]), [
            'void_reason' => 'Salah input barcode pohon',
        ]);
        $voidRes->assertRedirect();
        $voidRes->assertSessionHas('success');

        $postDefect->refresh();
        $this->assertTrue($postDefect->is_voided);
        $this->assertNotNull($postDefect->voided_at);
        $this->assertEquals($this->admin->id, $postDefect->voided_by);
        $this->assertEquals('Salah input barcode pohon', $postDefect->void_reason);
        $this->assertEquals($this->operator->id, $postDefect->recorded_by); // Original creator preserved!
        $this->assertEquals(15, $postDefect->defect_qty); // Original qty preserved!
        $this->assertEquals(100, $tree->fresh()->usable_quantity);

        // Excluded from all 3 reports & ReadyForCasting
        $rfc = $this->readyForCastingService->getQueueData(null, null, [$plan->code]);
        $this->assertEquals(100, $rfc['items'][0]['quantity_pcs']);
        $this->assertEquals(0, $rfc['items'][0]['defect_pcs']);

        $defRep = $this->defectReportService->getDefectDataset([
            'date_from' => '2026-11-18',
            'date_to' => '2026-11-18',
        ], $this->admin);
        $this->assertEquals(0, $defRep['summary']['oven']);

        $prodRep = $this->productionReportService->getProductionDataset([
            'date_from' => '2026-11-18',
            'date_to' => '2026-11-18',
            'stage' => 'oven',
        ], $this->admin);
        $this->assertEquals(0, $prodRep['items'][0]['qty_defect']);

        // 4. Double void is rejected
        try {
            $this->qualityService->voidDefect($tree, $postDefect, 'Void ulang', $this->admin->id);
            $this->fail('Expected InvalidArgumentException on double void');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('sudah dibatalkan (VOIDED)', $e->getMessage());
        }

        // 5. Record a new active defect, cast the tree -> verify BOTH recordDefect AND voidDefect are locked post-Cor!
        Carbon::setTestNow(Carbon::parse('2026-11-18 11:05:00', 'Asia/Jakarta'));
        $activeDefect = $this->qualityService->recordDefect(
            tree: $tree,
            stage: 'oven',
            defectQty: 10,
            defectReason: 'jatuh_handling',
            notes: 'Pecah 10 pcs',
            occurredAt: Carbon::parse('2026-11-18 11:00:00', 'Asia/Jakarta'),
            userId: $this->operator->id,
            defectContext: LostWaxTreeDefect::CONTEXT_POST_SIAP_COR,
            scanEventId: $siapCorEvent->id
        );

        $this->castingResultService->storeCastingResult([
            'heat_number' => 'HT-LOCK-01',
            'cast_date' => '2026-11-18',
            'tree_ids' => [$tree->id],
        ], $this->admin);

        try {
            $this->qualityService->recordDefect(
                tree: $tree->fresh(),
                stage: 'oven',
                defectQty: 5,
                defectReason: 'jatuh_handling',
                userId: $this->operator->id
            );
            $this->fail('Expected defense-in-depth lock on recordDefect after Casting Result');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Casting Result', $e->getMessage());
        }

        try {
            $this->qualityService->voidDefect($tree->fresh(), $activeDefect->fresh(), 'Coba void pasca cor', $this->admin->id);
            $this->fail('Expected defense-in-depth lock on voidDefect after Casting Result');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Casting Result', $e->getMessage());
        }

        // 6. Historical cutoff tree is also locked
        ['tree' => $cutoffTree] = $this->createOvenTree(['code' => '0926CUT01', 'quantity' => 40]);
        $cutoff = LostWaxHistoricalCutoff::create([
            'cutoff_number' => 'CO-TEST-01',
            'cutoff_date' => '2026-11-18',
            'reason' => 'Test cutoff',
            'total_trees' => 1,
            'total_quantity' => 40,
            'total_defect_quantity' => 0,
            'total_usable_quantity' => 40,
            'status' => 'COMPLETED',
            'executed_by' => $this->admin->id,
            'executed_at' => Carbon::now('Asia/Jakarta'),
        ]);
        LostWaxHistoricalCutoffTree::create([
            'lost_wax_historical_cutoff_id' => $cutoff->id,
            'lost_wax_tree_id' => $cutoffTree->id,
            'active_tree_id' => $cutoffTree->id,
            'barcode' => $cutoffTree->barcode,
            'tree_number' => $cutoffTree->tree_number,
            'production_code' => '0926CUT01',
            'previous_stage' => 'oven',
            'previous_status' => 'active',
            'quantity' => 40,
            'defect_quantity' => 0,
            'usable_quantity' => 40,
            'cor_date' => '2026-11-18',
            'cor_date_source' => 'manual',
            'siap_cor_date' => Carbon::now('Asia/Jakarta'),
            'siap_cor_date_source' => 'manual',
            'recorded_at' => Carbon::now('Asia/Jakarta'),
        ]);
        $cutoffTree->update(['status' => 'historical_cutoff']);

        try {
            $this->qualityService->recordDefect(
                tree: $cutoffTree->fresh(),
                stage: 'oven',
                defectQty: 5,
                defectReason: 'keramik_retak',
                userId: $this->operator->id
            );
            $this->fail('Expected lock on historical_cutoff tree');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Historical Cutoff', $e->getMessage());
        }

        Carbon::setTestNow();
    }

    public function test_wib_timezone_boundary_and_defect_only_day_in_production_report(): void
    {
        $this->actingAs($this->admin);

        // Oven scanned on 2026-11-18 15:00 WIB
        Carbon::setTestNow(Carbon::parse('2026-11-18 15:00:00', 'Asia/Jakarta'));
        ['plan' => $plan, 'tree' => $tree] = $this->createOvenTree([
            'code' => '0926WIB001',
            'quantity' => 100,
            'oven_scanned_at' => Carbon::parse('2026-11-18 15:00:00', 'Asia/Jakarta'),
        ]);

        // Scan Siap Cor on 2026-11-18 16:00 WIB
        Carbon::setTestNow(Carbon::parse('2026-11-18 16:00:00', 'Asia/Jakarta'));
        $scanRes = $this->scanSiapCorService->processScan($tree->barcode, $this->operator->id);
        $siapCorEvent = $scanRes['event'];

        // Defect 1: occurred_at = 2026-11-18 23:59:59 WIB (belongs to 2026-11-18)
        Carbon::setTestNow(Carbon::parse('2026-11-19 00:02:00', 'Asia/Jakarta'));
        $this->qualityService->recordDefect(
            tree: $tree,
            stage: 'oven',
            defectQty: 7,
            defectReason: 'jatuh_handling',
            notes: 'Kejadian sebelum tengah malam',
            occurredAt: Carbon::parse('2026-11-18 23:59:59', 'Asia/Jakarta'),
            userId: $this->operator->id,
            defectContext: LostWaxTreeDefect::CONTEXT_POST_SIAP_COR,
            scanEventId: $siapCorEvent->id
        );

        // Defect 2: occurred_at = NULL, created_at = 2026-11-19 00:00:05 WIB (belongs to 2026-11-19, where ZERO oven scans occurred!)
        Carbon::setTestNow(Carbon::parse('2026-11-19 00:00:05', 'Asia/Jakarta'));
        $this->qualityService->recordDefect(
            tree: $tree,
            stage: 'oven',
            defectQty: 10,
            defectReason: 'pecah_terjatuh',
            notes: 'Pecah di hari berikutnya (waktu kejadian tidak diketahui)',
            occurredAt: null,
            userId: $this->operator->id,
            defectContext: LostWaxTreeDefect::CONTEXT_POST_SIAP_COR,
            scanEventId: $siapCorEvent->id
        );

        // Verify 2026-11-18 in both Quality Defects and Production Report -> ONLY 7 pcs!
        $defDay1 = $this->defectReportService->getDefectDataset([
            'date_from' => '2026-11-18',
            'date_to' => '2026-11-18',
            'stage' => 'oven',
        ], $this->admin);
        $this->assertEquals(7, $defDay1['summary']['oven']);

        $prodDay1 = $this->productionReportService->getProductionDataset([
            'date_from' => '2026-11-18',
            'date_to' => '2026-11-18',
            'stage' => 'oven',
        ], $this->admin);
        $this->assertCount(1, $prodDay1['items']);
        $this->assertEquals(100, $prodDay1['items'][0]['qty_processed']);
        $this->assertEquals(7, $prodDay1['items'][0]['qty_defect']);

        // Verify 2026-11-19 in both Quality Defects and Production Report -> ONLY 10 pcs, and Production Report emits defect-only row (qty_processed = 0)!
        $defDay2 = $this->defectReportService->getDefectDataset([
            'date_from' => '2026-11-19',
            'date_to' => '2026-11-19',
            'stage' => 'oven',
            'mode' => 'detail',
        ], $this->admin);
        $this->assertEquals(10, $defDay2['summary']['oven']);
        $this->assertNull($defDay2['items'][0]['occurred_at']); // Honest NULL preserved!

        $prodDay2 = $this->productionReportService->getProductionDataset([
            'date_from' => '2026-11-19',
            'date_to' => '2026-11-19',
            'stage' => 'oven',
        ], $this->admin);
        $this->assertCount(1, $prodDay2['items']);
        $this->assertEquals($plan->code, $prodDay2['items'][0]['production_code']);
        $this->assertEquals(0, $prodDay2['items'][0]['qty_processed']);
        $this->assertEquals(0, $prodDay2['items'][0]['barcode_count']);
        $this->assertEquals(0.0, $prodDay2['items'][0]['total_weight']);
        $this->assertEquals(10, $prodDay2['items'][0]['qty_defect']);

        // Verify combined 2026-11-18..2026-11-19 -> 100 processed, 17 defect (zero double-counting!)
        $prodBoth = $this->productionReportService->getProductionDataset([
            'date_from' => '2026-11-18',
            'date_to' => '2026-11-19',
            'stage' => 'oven',
        ], $this->admin);
        $this->assertCount(1, $prodBoth['items']);
        $this->assertEquals(100, $prodBoth['items'][0]['qty_processed']);
        $this->assertEquals(17, $prodBoth['items'][0]['qty_defect']);

        Carbon::setTestNow();
    }
}
