<?php

namespace Tests\Feature;

use App\Models\ProductionPlan;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlanAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    private User $adminQcUser;

    private User $ppicFlange;

    private User $ppicFlangeBesi;

    private User $ppicFitting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $accessPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $accessExecution = Permission::firstOrCreate(['name' => 'access_execution']);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $ppicRole = Role::firstOrCreate(['name' => 'ppic']);
        $adminQcRole = Role::firstOrCreate(['name' => 'admin_qc_fitting']);

        $adminRole->syncPermissions([$accessPlanning, $accessExecution]);
        $ppicRole->syncPermissions([$accessPlanning, $accessExecution]);
        $adminQcRole->syncPermissions([$accessPlanning, $accessExecution]);

        $this->adminUser = User::create([
            'name' => 'Admin PPIC',
            'email' => 'adminppicpf@peroniks.com',
            'password' => Hash::make('password'),
            'product_scope' => null,
        ]);
        $this->adminUser->assignRole('admin');

        $this->adminQcUser = User::where('email', 'adminqcfitting@peroniks.com')->firstOrFail();

        $this->ppicFlange = User::create([
            'name' => 'PPIC Flange',
            'email' => 'ppicflange@peroniks.com',
            'password' => Hash::make('password'),
            'product_scope' => 'FLANGE_STAINLESS',
        ]);
        $this->ppicFlange->assignRole('ppic');

        $this->ppicFlangeBesi = User::create([
            'name' => 'PPIC Flange Besi',
            'email' => 'ppicflangebesi@peroniks.com',
            'password' => Hash::make('password'),
            'product_scope' => 'FLANGE_BESI',
        ]);
        $this->ppicFlangeBesi->assignRole('ppic');

        $this->ppicFitting = User::create([
            'name' => 'PPIC Fitting',
            'email' => 'ppicfitting@peroniks.com',
            'password' => Hash::make('password'),
            'product_scope' => 'FITTING_STAINLESS',
        ]);
        $this->ppicFitting->assignRole('ppic');
    }

    private function createPlan(string $scope, string $code = 'PLAN-001', string $domain = ProductionPlan::DOMAIN_LOST_WAX): ProductionPlan
    {
        return ProductionPlan::create([
            'code' => $code,
            'title' => 'Batch Test',
            'customer' => 'PT CUSTOMER',
            'item_code' => 'ITM-'.$code,
            'item_name' => 'Item '.$code,
            'product_scope' => $scope,
            'production_domain' => $domain,
            'po_number' => 'PO-'.$code,
            'qty_planned' => 100,
            'qty_remaining' => 100,
            'line_number' => 1,
            'status' => 'planning',
        ]);
    }

    // ==========================================
    // A. ADMIN MUTATION RESTRICTIONS & READ ACCESS
    // ==========================================

    public function test_admin_cannot_access_create_plan_page(): void
    {
        $response = $this->actingAs($this->adminUser)->get(route('plan.create'));
        $response->assertStatus(403);
    }

    public function test_admin_cannot_store_plan(): void
    {
        $response = $this->actingAs($this->adminUser)->postJson(route('plan.store'), [
            'title' => 'Rencana Admin',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'plans' => [
                [
                    'code' => 'ADM-01',
                    'item_code' => 'ITM-ADM',
                    'item_name' => 'Admin Flange',
                    'po_number' => 'PO-ADM-01',
                    'qty_planned' => 50,
                    'line_number' => 1,
                ],
            ],
        ]);

        $response->assertStatus(403);
        $this->assertEquals(0, ProductionPlan::where('code', 'ADM-01')->count());
    }

    public function test_admin_cannot_access_edit_plan_page(): void
    {
        $plan = $this->createPlan('FLANGE_STAINLESS', 'ADM-EDIT-01');

        $response = $this->actingAs($this->adminUser)->get(route('plan.edit', $plan->id));
        $response->assertStatus(403);
    }

    public function test_admin_cannot_update_plan(): void
    {
        $plan = $this->createPlan('FLANGE_STAINLESS', 'ADM-UPD-01');

        $response = $this->actingAs($this->adminUser)->put(route('plan.update', $plan->id), [
            'line_number' => 1,
            'po_number' => 'PO-MOD',
            'item_code' => 'ITM-ADM',
            'item_name' => 'Modified Flange',
            'qty_planned' => 200,
            'status' => 'planning',
        ]);

        $response->assertStatus(403);
        $this->assertEquals(100, $plan->fresh()->qty_planned);
    }

    public function test_admin_cannot_delete_plan(): void
    {
        $plan = $this->createPlan('FLANGE_STAINLESS', 'ADM-DEL-01');

        $response = $this->actingAs($this->adminUser)->delete(route('plan.destroy', $plan->id));
        $response->assertStatus(403);
        $this->assertDatabaseHas('production_plans', ['id' => $plan->id]);
    }

    public function test_admin_cannot_update_plan_title(): void
    {
        $plan = $this->createPlan('FLANGE_STAINLESS', 'ADM-TTL-01');

        $response = $this->actingAs($this->adminUser)->post(route('plan.updateTitle'), [
            'date' => now()->toDateString(),
            'title' => 'New Admin Title',
        ]);

        $response->assertStatus(403);
        $this->assertEquals('Batch Test', $plan->fresh()->title);
    }

    public function test_admin_can_read_all_plans_across_scopes_and_domains(): void
    {
        $planLW = $this->createPlan('FLANGE_STAINLESS', 'FS-READ-01', ProductionPlan::DOMAIN_LOST_WAX);
        $planSC = $this->createPlan('FLANGE_BESI', 'FB-READ-01', ProductionPlan::DOMAIN_SAND_CASTING);
        $planFT = $this->createPlan('FITTING_STAINLESS', 'FT-READ-01', ProductionPlan::DOMAIN_LOST_WAX);

        $response = $this->actingAs($this->adminUser)->get(route('plan.index'));
        $response->assertStatus(200);

        $detailResponse = $this->actingAs($this->adminUser)->get(route('plan.index', ['date' => now()->toDateString()]));
        $detailResponse->assertStatus(200);
        $detailResponse->assertSee('FS-READ-01');
        $detailResponse->assertSee('FB-READ-01');
        $detailResponse->assertSee('FT-READ-01');
    }

    // ==========================================
    // B. ADMIN QC FITTING RESTRICTIONS
    // ==========================================

    public function test_admin_qc_fitting_cannot_mutate_plans(): void
    {
        $plan = $this->createPlan('FITTING_STAINLESS', 'QC-MUT-01');

        // Create -> 403
        $this->actingAs($this->adminQcUser)->get(route('plan.create'))->assertStatus(403);

        // Store -> 403
        $this->actingAs($this->adminQcUser)->postJson(route('plan.store'), [
            'title' => 'QC Plan',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'plans' => [
                [
                    'code' => 'QC-01',
                    'item_code' => 'ITM-QC',
                    'item_name' => 'QC Item',
                    'po_number' => 'PO-QC-01',
                    'qty_planned' => 50,
                    'line_number' => 1,
                ],
            ],
        ])->assertStatus(403);

        // Edit -> 403
        $this->actingAs($this->adminQcUser)->get(route('plan.edit', $plan->id))->assertStatus(403);

        // Update -> 403
        $this->actingAs($this->adminQcUser)->put(route('plan.update', $plan->id), [
            'line_number' => 1,
            'po_number' => 'PO-QC-MOD',
            'item_code' => 'ITM-QC',
            'item_name' => 'QC Item Mod',
            'qty_planned' => 150,
            'status' => 'planning',
        ])->assertStatus(403);

        // Delete -> 403
        $this->actingAs($this->adminQcUser)->delete(route('plan.destroy', $plan->id))->assertStatus(403);

        // Update title -> 403
        $this->actingAs($this->adminQcUser)->post(route('plan.updateTitle'), [
            'date' => now()->toDateString(),
            'title' => 'QC Title Update',
        ])->assertStatus(403);

        // Read -> 200
        $this->actingAs($this->adminQcUser)->get(route('plan.index'))->assertStatus(200);
    }

    // ==========================================
    // C. PPIC OWNER CRUD & SCOPE ENFORCEMENT
    // ==========================================

    public function test_ppic_flange_can_create_store_edit_update_delete_and_update_title(): void
    {
        // 1. Create page accessible
        $response = $this->actingAs($this->ppicFlange)->get(route('plan.create'));
        $response->assertStatus(200);

        // 2. Store succeeds and enforces FLANGE_STAINLESS
        $response = $this->actingAs($this->ppicFlange)->postJson(route('plan.store'), [
            'title' => 'Rencana Flange SS',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
            'plans' => [
                [
                    'code' => 'FS-TEST-01',
                    'item_code' => 'ITM-FS-01',
                    'item_name' => 'SS304 Blind Flange',
                    'po_number' => 'PO-FS-001',
                    'qty_planned' => 75,
                    'line_number' => 1,
                    'customer' => 'PT SS Buyer',
                ],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $plan = ProductionPlan::where('code', 'FS-TEST-01')->firstOrFail();
        $this->assertEquals('FLANGE_STAINLESS', $plan->product_scope);
        $this->assertEquals('SAND_CASTING', $plan->production_domain);

        // 3. Edit page accessible
        $response = $this->actingAs($this->ppicFlange)->get(route('plan.edit', $plan->id));
        $response->assertStatus(200);

        // 4. Update succeeds
        $response = $this->actingAs($this->ppicFlange)->put(route('plan.update', $plan->id), [
            'line_number' => 1,
            'po_number' => 'PO-FS-001-MOD',
            'item_code' => 'ITM-FS-01',
            'item_name' => 'SS304 Blind Flange Updated',
            'qty_planned' => 90,
            'status' => 'planning',
            'production_domain' => ProductionPlan::DOMAIN_SAND_CASTING,
        ]);

        $response->assertRedirect();
        $this->assertEquals(90, $plan->fresh()->qty_planned);
        $this->assertEquals('FLANGE_STAINLESS', $plan->fresh()->product_scope);

        // 5. Update title succeeds
        $response = $this->actingAs($this->ppicFlange)->post(route('plan.updateTitle'), [
            'date' => now()->toDateString(),
            'title' => 'Rencana Flange SS Updated',
        ]);
        $response->assertRedirect();
        $this->assertEquals('Rencana Flange SS Updated', $plan->fresh()->title);

        // 6. Delete succeeds
        $response = $this->actingAs($this->ppicFlange)->delete(route('plan.destroy', $plan->id));
        $response->assertRedirect();
        $this->assertDatabaseMissing('production_plans', ['id' => $plan->id]);
    }

    // ==========================================
    // D. CROSS-SCOPE PPIC REJECTIONS (403)
    // ==========================================

    public function test_cross_scope_ppic_tampering_is_blocked_with_403(): void
    {
        $planStainless = $this->createPlan('FLANGE_STAINLESS', 'FS-CROSS-01');
        $planBesi = $this->createPlan('FLANGE_BESI', 'FB-CROSS-01');
        $planFitting = $this->createPlan('FITTING_STAINLESS', 'FT-CROSS-01');

        // ppicflange attempts to access planBesi -> 403
        $this->actingAs($this->ppicFlange)->get(route('plan.edit', $planBesi->id))->assertStatus(403);
        $this->actingAs($this->ppicFlange)->put(route('plan.update', $planBesi->id), [
            'line_number' => 1,
            'po_number' => 'PO-TAMPER',
            'item_code' => 'ITM-TAMPER',
            'item_name' => 'Tampered',
            'qty_planned' => 100,
            'status' => 'planning',
        ])->assertStatus(403);
        $this->actingAs($this->ppicFlange)->delete(route('plan.destroy', $planBesi->id))->assertStatus(403);

        // ppicflangebesi attempts to access planStainless -> 403
        $this->actingAs($this->ppicFlangeBesi)->get(route('plan.edit', $planStainless->id))->assertStatus(403);
        $this->actingAs($this->ppicFlangeBesi)->put(route('plan.update', $planStainless->id), [
            'line_number' => 1,
            'po_number' => 'PO-TAMPER',
            'item_code' => 'ITM-TAMPER',
            'item_name' => 'Tampered',
            'qty_planned' => 100,
            'status' => 'planning',
        ])->assertStatus(403);
        $this->actingAs($this->ppicFlangeBesi)->delete(route('plan.destroy', $planStainless->id))->assertStatus(403);

        // ppicfitting attempts to access planStainless -> 403
        $this->actingAs($this->ppicFitting)->get(route('plan.edit', $planStainless->id))->assertStatus(403);
        $this->actingAs($this->ppicFitting)->put(route('plan.update', $planStainless->id), [
            'line_number' => 1,
            'po_number' => 'PO-TAMPER',
            'item_code' => 'ITM-TAMPER',
            'item_name' => 'Tampered',
            'qty_planned' => 100,
            'status' => 'planning',
        ])->assertStatus(403);
        $this->actingAs($this->ppicFitting)->delete(route('plan.destroy', $planStainless->id))->assertStatus(403);
    }

    // ==========================================
    // E. SCOPE TAMPERING IN PAYLOAD OVERRIDDEN
    // ==========================================

    public function test_payload_scope_tampering_is_overridden_by_authenticated_user_scope(): void
    {
        // 1. Store with spoofed scope in payload
        $response = $this->actingAs($this->ppicFlange)->postJson(route('plan.store'), [
            'title' => 'Spoof Attempt',
            'production_domain' => ProductionPlan::DOMAIN_LOST_WAX,
            'plans' => [
                [
                    'code' => 'SPOOF-01',
                    'item_code' => 'ITM-SPOOF',
                    'item_name' => 'Spoofed Flange',
                    'po_number' => 'PO-SPOOF-01',
                    'qty_planned' => 100,
                    'line_number' => 1,
                    'product_scope' => 'FLANGE_BESI', // Malicious attempt to assign FLANGE_BESI
                ],
            ],
        ]);

        $response->assertStatus(200);
        $plan = ProductionPlan::where('code', 'SPOOF-01')->firstOrFail();
        $this->assertEquals('FLANGE_STAINLESS', $plan->product_scope, 'Server must enforce authenticated user scope');

        // 2. Update with spoofed scope in payload
        $response = $this->actingAs($this->ppicFlange)->put(route('plan.update', $plan->id), [
            'line_number' => 1,
            'po_number' => 'PO-SPOOF-01',
            'item_code' => 'ITM-SPOOF',
            'item_name' => 'Spoofed Flange Updated',
            'qty_planned' => 120,
            'status' => 'planning',
            'product_scope' => 'FITTING_STAINLESS', // Malicious attempt to change scope
        ]);

        $response->assertRedirect();
        $this->assertEquals('FLANGE_STAINLESS', $plan->fresh()->product_scope, 'Server must keep authenticated user scope');
    }
}
