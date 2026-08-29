<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\LostWaxScanEvent;
use App\Models\LostWaxTree;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\PrintExecutionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class QcFittingCrossScopeRbacTest extends TestCase
{
    use RefreshDatabase;

    protected User $qcUser;

    protected User $ppicUser;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Run production-safe DatabaseSeeder
        $this->seed(DatabaseSeeder::class);

        $this->qcUser = User::where('email', 'adminqcfitting@peroniks.com')->firstOrFail();

        // Set up roles & permissions for reference users
        $accessPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution']);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $ppicRole = Role::firstOrCreate(['name' => 'ppic']);

        $adminRole->syncPermissions([$accessPlanning, $accessExecution]);
        $ppicRole->syncPermissions([$accessPlanning, $accessExecution]);

        $this->adminUser = User::create([
            'name' => 'Admin PPIC',
            'email' => 'adminppicpf@peroniks.com',
            'password' => Hash::make('password'),
            'product_scope' => null,
        ]);
        $this->adminUser->syncRoles(['admin']);

        $this->ppicUser = User::create([
            'name' => 'PPIC Fitting',
            'email' => 'ppicfitting@peroniks.com',
            'password' => Hash::make('password'),
            'product_scope' => 'FITTING_STAINLESS',
        ]);
        $this->ppicUser->syncRoles(['ppic']);
    }

    protected function createPlan(string $scope, string $code = 'PLAN-001'): ProductionPlan
    {
        return ProductionPlan::create([
            'code' => $code,
            'customer' => 'PT PERONI INTI',
            'item_code' => 'ITEM-'.$code,
            'item_name' => 'PRODUCT '.$code.' ('.$scope.')',
            'product_scope' => $scope,
            'aisi' => '304',
            'size' => '1"',
            'weight' => 0.5,
            'po_number' => 'PO-'.$code,
            'po_quantity' => 100,
            'qty_planned' => 120,
            'qty_remaining' => 120,
            'line_number' => 1,
            'status' => 'planning',
            'is_closed' => false,
        ]);
    }

    /**
     * 1. User adminqcfitting@peroniks.com can login successfully.
     */
    public function test_1_qc_user_can_login(): void
    {
        $response = $this->post(route('login.post'), [
            'email' => 'adminqcfitting@peroniks.com',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->qcUser);
        $this->assertTrue($this->qcUser->hasRole('admin_qc_fitting'));
        $this->assertNull($this->qcUser->product_scope);
    }

    /**
     * 2. User can read Lost Wax FLANGE_STAINLESS.
     */
    public function test_2_qc_user_can_read_flange_stainless(): void
    {
        $plan = $this->createPlan('FLANGE_STAINLESS', '268FS01');

        $response = $this->actingAs($this->qcUser)->get(route('plan.index', ['date' => date('Y-m-d')]));
        $response->assertStatus(200);
        $response->assertSee('268FS01');
    }

    /**
     * 3. User can read Lost Wax FITTING_STAINLESS.
     */
    public function test_3_qc_user_can_read_fitting_stainless(): void
    {
        $plan = $this->createPlan('FITTING_STAINLESS', '268FT01');

        $response = $this->actingAs($this->qcUser)->get(route('plan.index', ['date' => date('Y-m-d')]));
        $response->assertStatus(200);
        $response->assertSee('268FT01');
    }

    /**
     * 4. User can read Lost Wax FLANGE_BESI.
     */
    public function test_4_qc_user_can_read_flange_besi(): void
    {
        $plan = $this->createPlan('FLANGE_BESI', '268FB01');

        $response = $this->actingAs($this->qcUser)->get(route('plan.index', ['date' => date('Y-m-d')]));
        $response->assertStatus(200);
        $response->assertSee('268FB01');
    }

    /**
     * 5. User can open Daily Defect Report page.
     */
    public function test_5_qc_user_can_open_daily_defect_report(): void
    {
        $response = $this->actingAs($this->qcUser)->get(route('lost-wax.quality.defects.index'));

        $response->assertStatus(200);
        $response->assertSee('REKAP KERUSAKAN LOST WAX');
    }

    /**
     * 6. Daily Defect Report displays cross-scope data from all 3 scopes simultaneously.
     */
    public function test_6_report_displays_cross_scope_data_from_all_three_scopes(): void
    {
        $planFS = $this->createPlan('FLANGE_STAINLESS', '268FS_REP');
        $planFT = $this->createPlan('FITTING_STAINLESS', '268FT_REP');
        $planFB = $this->createPlan('FLANGE_BESI', '268FB_REP');

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260829-QC01',
            'scheduled_date' => '2026-08-29',
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $lineFS = $order->lines()->create([
            'production_plan_id' => $planFS->id,
            'code' => $planFS->code,
            'customer' => $planFS->customer,
            'item_name' => $planFS->item_name,
            'size' => $planFS->size,
            'aisi' => $planFS->aisi,
            'qty_ordered' => 100,
            'standard_tree_capacity' => 20,
        ]);

        $lineFT = $order->lines()->create([
            'production_plan_id' => $planFT->id,
            'code' => $planFT->code,
            'customer' => $planFT->customer,
            'item_name' => $planFT->item_name,
            'size' => $planFT->size,
            'aisi' => $planFT->aisi,
            'qty_ordered' => 100,
            'standard_tree_capacity' => 20,
        ]);

        $lineFB = $order->lines()->create([
            'production_plan_id' => $planFB->id,
            'code' => $planFB->code,
            'customer' => $planFB->customer,
            'item_name' => $planFB->item_name,
            'size' => $planFB->size,
            'aisi' => $planFB->aisi,
            'qty_ordered' => 100,
            'standard_tree_capacity' => 20,
        ]);

        // Record defects on each scope
        app(PrintExecutionService::class)->record($lineFS, [
            'qty_good' => 90,
            'qty_defect' => 10,
            'execution_date' => '2026-08-29',
            'status' => 'FINALIZED',
            'recorded_by' => $this->adminUser->id,
        ]);

        app(PrintExecutionService::class)->record($lineFT, [
            'qty_good' => 85,
            'qty_defect' => 15,
            'execution_date' => '2026-08-29',
            'status' => 'FINALIZED',
            'recorded_by' => $this->adminUser->id,
        ]);

        app(PrintExecutionService::class)->record($lineFB, [
            'qty_good' => 80,
            'qty_defect' => 20,
            'execution_date' => '2026-08-29',
            'status' => 'FINALIZED',
            'recorded_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->qcUser)->get(route('lost-wax.quality.defects.index', [
            'date_from' => '2026-08-29',
            'date_to' => '2026-08-29',
            'mode' => 'ringkas',
        ]));

        $response->assertStatus(200);
        // All 3 codes must be visible to QC user
        $response->assertSee('268FS_REP');
        $response->assertSee('268FT_REP');
        $response->assertSee('268FB_REP');
        // Total Defect: 10 + 15 + 20 = 45 pcs
        $response->assertSee('45 pcs');
    }

    /**
     * 7. Date range filter works.
     */
    public function test_7_date_range_filter_works(): void
    {
        $plan = $this->createPlan('FITTING_STAINLESS', '268DATE01');

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260829-QC02',
            'scheduled_date' => '2026-08-20',
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'qty_ordered' => 100,
            'standard_tree_capacity' => 20,
        ]);

        app(PrintExecutionService::class)->record($line, [
            'qty_good' => 90,
            'qty_defect' => 5,
            'execution_date' => '2026-08-20',
            'status' => 'FINALIZED',
            'recorded_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->qcUser)->get(route('lost-wax.quality.defects.index', [
            'date_from' => '2026-08-25',
            'date_to' => '2026-08-29',
        ]));

        $response->assertStatus(200);
        $response->assertDontSee('268DATE01');
    }

    /**
     * 8. Stage filter works.
     */
    public function test_8_stage_filter_works(): void
    {
        $plan = $this->createPlan('FITTING_STAINLESS', '268STG01');

        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260829-QC03',
            'scheduled_date' => '2026-08-29',
            'status' => 'ISSUED',
            'created_by' => $this->adminUser->id,
        ]);

        $line = $order->lines()->create([
            'production_plan_id' => $plan->id,
            'code' => $plan->code,
            'customer' => $plan->customer,
            'item_name' => $plan->item_name,
            'size' => $plan->size,
            'aisi' => $plan->aisi,
            'qty_ordered' => 100,
            'standard_tree_capacity' => 20,
        ]);

        app(PrintExecutionService::class)->record($line, [
            'qty_good' => 90,
            'qty_defect' => 8,
            'execution_date' => '2026-08-29',
            'status' => 'FINALIZED',
            'recorded_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->qcUser)->get(route('lost-wax.quality.defects.index', [
            'date_from' => '2026-08-29',
            'date_to' => '2026-08-29',
            'stage' => 'assembly',
        ]));

        $response->assertStatus(200);
        $response->assertDontSee('268STG01');
    }

    /**
     * 9. Export Excel works for QC user.
     */
    public function test_9_export_excel_works(): void
    {
        $response = $this->actingAs($this->qcUser)->get(route('lost-wax.quality.defects.export.excel', [
            'date_from' => '2026-08-29',
            'date_to' => '2026-08-29',
        ]));

        $response->assertStatus(200);
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('content-type'));
    }

    /**
     * 10. Export PDF works for QC user.
     */
    public function test_10_export_pdf_works(): void
    {
        $response = $this->actingAs($this->qcUser)->get(route('lost-wax.quality.defects.export.pdf', [
            'date_from' => '2026-08-29',
            'date_to' => '2026-08-29',
        ]));

        $response->assertStatus(200);
        $response->assertSee('Rekap Kerusakan Lost Wax');
    }

    /**
     * 11. User cannot create Production Plan (POST /plan yields 403).
     */
    public function test_11_user_cannot_create_production_plan(): void
    {
        $response = $this->actingAs($this->qcUser)->post(route('plan.store'), [
            'plans' => [
                [
                    'code' => '268MUT01',
                    'customer' => 'PT PERONI',
                    'item_code' => 'ITEM-01',
                    'item_name' => 'ELBOW',
                    'qty_planned' => 100,
                ],
            ],
        ]);

        $response->assertStatus(403);
    }

    /**
     * 12. User cannot create/edit/delete SPK.
     */
    public function test_12_user_cannot_mutate_spk(): void
    {
        $plan = $this->createPlan('FITTING_STAINLESS', '268SPK01');

        // Create SPK
        $resStore = $this->actingAs($this->qcUser)->post(route('lost-wax.print-orders.store'), [
            'scheduled_date' => '2026-08-29',
            'selected_plan_ids' => [$plan->id],
        ]);
        $resStore->assertStatus(403);

        // Update SPK
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260829-QC04',
            'scheduled_date' => '2026-08-29',
            'status' => 'DRAFT',
            'created_by' => $this->adminUser->id,
        ]);

        $resUpdate = $this->actingAs($this->qcUser)->put(route('lost-wax.print-orders.update', $order), [
            'scheduled_date' => '2026-08-30',
        ]);
        $resUpdate->assertStatus(403);

        // Delete SPK
        $resDelete = $this->actingAs($this->qcUser)->delete(route('lost-wax.print-orders.destroy', $order));
        $resDelete->assertStatus(403);
    }

    /**
     * 13. User cannot scan.
     */
    public function test_13_user_cannot_scan(): void
    {
        $resScan = $this->actingAs($this->qcUser)->post(route('lost-wax.scan.process'), [
            'barcode' => '1290826001',
        ]);
        $resScan->assertStatus(403);

        $resScanOven = $this->actingAs($this->qcUser)->post(route('lost-wax.scan-oven.process'), [
            'barcode' => '1290826001',
        ]);
        $resScanOven->assertStatus(403);
    }

    /**
     * 14. User cannot void scan.
     */
    public function test_14_user_cannot_void_scan(): void
    {
        $plan = $this->createPlan('FITTING_STAINLESS', '268VOID01');
        $tree = LostWaxTree::create([
            'barcode' => '1290826099',
            'tree_number' => 1,
            'quantity' => 20,
            'status' => 'active',
            'production_date' => '2026-08-29',
            'family_code' => '1',
            'daily_sequence' => 99,
        ]);

        $event = LostWaxScanEvent::create([
            'tree_id' => $tree->id,
            'barcode' => $tree->barcode,
            'stage' => 'layer_1',
            'scanned_at' => now(),
            'operator_id' => $this->adminUser->id,
            'result' => 'success',
        ]);

        $response = $this->actingAs($this->qcUser)->post(route('lost-wax.scan-events.void', $event), [
            'reason' => 'Salah scan',
        ]);

        $response->assertStatus(403);
    }

    /**
     * 15. User cannot input defect.
     */
    public function test_15_user_cannot_input_defect(): void
    {
        $tree = LostWaxTree::create([
            'barcode' => '1290826088',
            'tree_number' => 1,
            'quantity' => 20,
            'status' => 'active',
            'production_date' => '2026-08-29',
            'family_code' => '1',
            'daily_sequence' => 88,
        ]);

        $response = $this->actingAs($this->qcUser)->post(route('lost-wax.trees.defects.store', $tree), [
            'stage' => 'assembly',
            'defect_qty' => 1,
            'defect_reason' => 'rusak',
        ]);

        $response->assertStatus(403);
    }

    /**
     * 16. User cannot update PO quantity.
     */
    public function test_16_user_cannot_update_po_quantity(): void
    {
        $plan = $this->createPlan('FITTING_STAINLESS', '268PO01');

        $response = $this->actingAs($this->qcUser)->put(route('lost-wax.production-plans.update-po', $plan), [
            'po_quantity' => 800,
        ]);

        $response->assertStatus(403);
    }

    /**
     * 17. User cannot close plan.
     */
    public function test_17_user_cannot_close_plan(): void
    {
        $plan = $this->createPlan('FITTING_STAINLESS', '268CLS01');

        $response = $this->actingAs($this->qcUser)->post(route('lost-wax.production-plans.close-recovery', $plan), [
            'closure_reason' => 'Tutup batch',
        ]);

        $response->assertStatus(403);
    }

    /**
     * 18. User cannot create reprint.
     */
    public function test_18_user_cannot_create_reprint(): void
    {
        $plan = $this->createPlan('FITTING_STAINLESS', '268RPR01');

        $response = $this->actingAs($this->qcUser)->post(route('lost-wax.print-orders.reprint.store'), [
            'production_plan_id' => $plan->id,
            'reprint_qty' => 50,
            'reprint_reason' => 'Deficit',
        ]);

        $response->assertStatus(403);
    }

    /**
     * 19. User cannot modify Assembly Photo.
     */
    public function test_19_user_cannot_modify_assembly_photo(): void
    {
        $response = $this->actingAs($this->qcUser)->post(route('settings.assembly-photos.store'), [
            'product_code' => '4.801190FFBSP.G.A0020',
            'product_name' => 'SS304 ELBOW',
        ]);

        $response->assertStatus(403);
    }

    /**
     * 20. Direct HTTP mutation requests (POST/PUT/PATCH/DELETE) yield HTTP 403.
     */
    public function test_20_direct_http_mutations_yield_403(): void
    {
        $plan = $this->createPlan('FITTING_STAINLESS', '268DIR01');
        $tree = LostWaxTree::create([
            'barcode' => '1290826066',
            'tree_number' => 1,
            'quantity' => 20,
            'status' => 'active',
            'production_date' => '2026-08-29',
            'family_code' => '1',
            'daily_sequence' => 66,
        ]);

        $this->actingAs($this->qcUser)->post(route('plan.store'), [])->assertStatus(403);
        $this->actingAs($this->qcUser)->put(route('plan.update', $plan), [])->assertStatus(403);
        $this->actingAs($this->qcUser)->delete(route('plan.destroy', $plan))->assertStatus(403);
        $this->actingAs($this->qcUser)->patch(route('lost-wax.trees.update', $tree), [])->assertStatus(403);
    }

    /**
     * 21. Existing PPIC and Admin permissions remain unaltered.
     */
    public function test_21_existing_ppic_and_admin_permissions_unaltered(): void
    {
        $this->assertTrue($this->adminUser->hasRole('admin'));
        $this->assertTrue($this->adminUser->can('access_planning'));
        $this->assertTrue($this->adminUser->can('access_execution'));

        $this->assertTrue($this->ppicUser->hasRole('ppic'));
        $this->assertEquals('FITTING_STAINLESS', $this->ppicUser->product_scope);
        $this->assertTrue($this->ppicUser->can('access_planning'));
        $this->assertTrue($this->ppicUser->can('access_execution'));
    }

    /**
     * 22. QC User can successfully log out (POST /logout) and become guest.
     */
    public function test_22_qc_user_can_logout_and_become_guest(): void
    {
        $response = $this->actingAs($this->qcUser)->post(route('logout'));

        $response->assertRedirect('/login');
        $this->assertGuest();
    }

    /**
     * 23. PPIC User can successfully log out (POST /logout) and become guest.
     */
    public function test_23_ppic_user_can_logout_and_become_guest(): void
    {
        $response = $this->actingAs($this->ppicUser)->post(route('logout'));

        $response->assertRedirect('/login');
        $this->assertGuest();
    }

    /**
     * 24. After QC logs out, session does not maintain QC identity.
     */
    public function test_24_after_qc_logout_session_does_not_retain_identity(): void
    {
        // 1. QC logs in
        $this->actingAs($this->qcUser);
        $this->assertAuthenticatedAs($this->qcUser);

        // 2. QC logs out
        $resLogout = $this->post(route('logout'));
        $resLogout->assertRedirect('/login');
        $this->assertGuest();

        // 3. Visiting protected route redirects to login, not authenticated as QC
        $resVisit = $this->get(route('dashboard'));
        $resVisit->assertRedirect(route('login'));
    }

    /**
     * 25. PPIC user retains mutation rights for their own scope while QC remains blocked.
     */
    public function test_25_ppic_user_retains_mutation_rights_for_own_scope(): void
    {
        $plan = $this->createPlan('FITTING_STAINLESS', '268PPIC_MUT');

        // PPIC can create print order for their own scope
        $resPpic = $this->actingAs($this->ppicUser)->post(route('lost-wax.print-orders.store'), [
            'scheduled_date' => '2026-08-29',
            'selected_plan_ids' => [$plan->id],
        ]);
        $resPpic->assertRedirect();

        // QC is blocked with 403 on the exact same endpoint
        $resQc = $this->actingAs($this->qcUser)->post(route('lost-wax.print-orders.store'), [
            'scheduled_date' => '2026-08-29',
            'selected_plan_ids' => [$plan->id],
        ]);
        $resQc->assertStatus(403);
    }
}
