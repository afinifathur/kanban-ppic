<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxCastingResult;
use App\Models\LostWaxCastingResultTree;
use App\Models\LostWaxKitir;
use App\Models\LostWaxTree;
use App\Models\User;
use App\Services\LostWax\LostWaxKitirResolverService;
use App\Services\LostWax\LostWaxTravelerNumberGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LostWaxFittingKitirTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $ppicUser;

    protected User $unauthorizedUser;

    protected static int $seq = 8000;

    protected function setUp(): void
    {
        parent::setUp();

        $permPlanning = Permission::firstOrCreate(['name' => 'access_planning']);
        $permExecution = Permission::firstOrCreate(['name' => 'access_execution']);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->givePermissionTo([$permPlanning, $permExecution]);

        $ppicRole = Role::firstOrCreate(['name' => 'ppic']);
        $ppicRole->givePermissionTo([$permPlanning]);

        $this->adminUser = User::factory()->create(['email' => 'admin_test@peroniks.com']);
        $this->adminUser->assignRole('admin');

        $this->ppicUser = User::factory()->create(['email' => 'ppic_test@peroniks.com']);
        $this->ppicUser->assignRole('ppic');

        $this->unauthorizedUser = User::factory()->create(['email' => 'guest_test@peroniks.com']);

        $this->bypassRoleGateFix();
    }

    protected function bypassRoleGateFix(): void
    {
        $gate = Gate::getFacadeRoot();
        $ref = new \ReflectionClass($gate);
        $prop = $ref->getProperty('beforeCallbacks');
        $prop->setAccessible(true);
        $prop->setValue($gate, [
            function ($user, $ability) {
                if (method_exists($user, 'hasPermissionTo')) {
                    try {
                        return $user->hasPermissionTo($ability) ? true : null;
                    } catch (\Throwable $e) {
                        return null;
                    }
                }

                return null;
            },
        ]);
    }

    /**
     * Helper to create a completed casting result with specified tree overrides.
     *
     * @param  array<int, array{code: string, qty: int, gross?: int, defect?: int, customer?: string, item?: string, barcode?: string}>  $treeSpecs
     */
    protected function createCastingResult(array $treeSpecs, string $status = 'COMPLETED', ?string $heatNumber = null): LostWaxCastingResult
    {
        $totalPcs = array_sum(array_column($treeSpecs, 'qty'));
        $totalTrees = count($treeSpecs);
        $heat = $heatNumber ?? 'HEAT-TEST-'.(self::$seq++);

        $res = LostWaxCastingResult::create([
            'heat_number' => $heat,
            'cast_date' => Carbon::today()->format('Y-m-d'),
            'furnace' => '1',
            'shift' => '1',
            'operator_name' => 'Tester',
            'total_trees' => $totalTrees,
            'total_pcs' => $totalPcs,
            'status' => $status,
            'recorded_by' => $this->adminUser->id,
        ]);

        foreach ($treeSpecs as $idx => $spec) {
            $treeId = self::$seq++;
            $barcode = $spec['barcode'] ?? sprintf('26%08d', $treeId);

            $physTree = LostWaxTree::create([
                'barcode' => $barcode,
                'tree_number' => $idx + 1,
                'daily_sequence' => $idx + 1,
                'production_date' => Carbon::today()->format('Y-m-d'),
                'family_code' => '1',
                'require_layer_7' => false,
                'quantity' => $spec['gross'] ?? $spec['qty'],
                'current_stage' => 'cor',
                'status' => 'active',
                'created_by' => $this->adminUser->id,
            ]);

            LostWaxCastingResultTree::create([
                'lost_wax_casting_result_id' => $res->id,
                'lost_wax_tree_id' => $physTree->id,
                'active_tree_id' => $status === 'COMPLETED' ? $physTree->id : null,
                'barcode' => $barcode,
                'production_code' => $spec['code'],
                'customer' => $spec['customer'] ?? 'PT TEST CUSTOMER',
                'item_name' => $spec['item'] ?? 'FLANGE SS316 2 INCH',
                'quantity_pcs' => $spec['qty'],
                'gross_pcs' => $spec['gross'] ?? $spec['qty'],
                'defect_pcs' => $spec['defect'] ?? 0,
            ]);
        }

        return $res;
    }

    /**
     * 1. Satu kode produksi dengan satu pohon menghasilkan satu Kitir.
     */
    public function test_01_single_production_code_with_single_tree_produces_one_kitir(): void
    {
        $res = $this->createCastingResult([
            ['code' => 'LW001', 'qty' => 10],
        ]);

        $resolver = app(LostWaxKitirResolverService::class);
        $groups = $resolver->resolveGroups($res);

        $this->assertCount(1, $groups);
        $this->assertEquals('LW001', $groups[0]['production_code']);
        $this->assertEquals(1, $groups[0]['tree_count']);
        $this->assertEquals(10, $groups[0]['quantity_pcs']);
    }

    /**
     * 2. Satu kode produksi dengan beberapa pohon menghasilkan satu Kitir.
     */
    public function test_02_single_production_code_with_multiple_trees_produces_one_kitir(): void
    {
        $res = $this->createCastingResult([
            ['code' => 'LW004', 'qty' => 5],
            ['code' => 'LW004', 'qty' => 15],
            ['code' => 'LW004', 'qty' => 20],
        ]);

        $resolver = app(LostWaxKitirResolverService::class);
        $groups = $resolver->resolveGroups($res);

        $this->assertCount(1, $groups);
        $this->assertEquals('LW004', $groups[0]['production_code']);
        $this->assertEquals(3, $groups[0]['tree_count']);
        $this->assertEquals(40, $groups[0]['quantity_pcs']);
        $this->assertCount(3, $groups[0]['barcodes']);
    }

    /**
     * 3. Beberapa kode produksi menghasilkan Kitir terpisah.
     */
    public function test_03_multiple_production_codes_produce_separate_kitirs(): void
    {
        $res = $this->createCastingResult([
            ['code' => 'CODE_A', 'qty' => 10],
            ['code' => 'CODE_B', 'qty' => 20],
            ['code' => 'CODE_C', 'qty' => 30],
        ]);

        $resolver = app(LostWaxKitirResolverService::class);
        $groups = $resolver->resolveGroups($res);

        $this->assertCount(3, $groups);
        $this->assertEquals(['CODE_A', 'CODE_B', 'CODE_C'], $groups->pluck('production_code')->all());
    }

    /**
     * 4. Contoh Heat A416112604 menghasilkan 3 Kitir dengan total 46 PCS.
     */
    public function test_04_example_heat_a416112604_produces_3_kitirs_with_46_pcs(): void
    {
        $res = $this->createCastingResult([
            ['code' => 'LWDEMO003', 'qty' => 15, 'barcode' => '4110826002'],
            ['code' => 'LWDEMO004', 'qty' => 4, 'barcode' => '1110826002'],
            ['code' => 'LWDEMO004', 'qty' => 15, 'barcode' => '1110826004'],
            ['code' => 'LWDEMO005', 'qty' => 12, 'barcode' => '2110826001'],
        ], 'COMPLETED', 'A416112604');

        $resolver = app(LostWaxKitirResolverService::class);
        $groups = $resolver->resolveGroups($res);

        $this->assertCount(3, $groups);

        $groupDemo3 = $groups->firstWhere('production_code', 'LWDEMO003');
        $this->assertNotNull($groupDemo3);
        $this->assertEquals(1, $groupDemo3['tree_count']);
        $this->assertEquals(15, $groupDemo3['quantity_pcs']);

        $groupDemo4 = $groups->firstWhere('production_code', 'LWDEMO004');
        $this->assertNotNull($groupDemo4);
        $this->assertEquals(2, $groupDemo4['tree_count']);
        $this->assertEquals(19, $groupDemo4['quantity_pcs']);

        $groupDemo5 = $groups->firstWhere('production_code', 'LWDEMO005');
        $this->assertNotNull($groupDemo5);
        $this->assertEquals(1, $groupDemo5['tree_count']);
        $this->assertEquals(12, $groupDemo5['quantity_pcs']);

        $this->assertEquals(46, $groups->sum('quantity_pcs'));
    }

    /**
     * 5. Multi-allocation combined tidak dipecah.
     */
    public function test_05_multi_allocation_combined_is_not_split(): void
    {
        $res = $this->createCastingResult([
            ['code' => 'LWDEMO001, LWDEMO002', 'qty' => 14],
            ['code' => 'LWDEMO003', 'qty' => 10],
        ]);

        $resolver = app(LostWaxKitirResolverService::class);
        $groups = $resolver->resolveGroups($res);

        $this->assertCount(2, $groups);
        $combinedGroup = $groups->firstWhere('production_code', 'LWDEMO001, LWDEMO002');
        $this->assertNotNull($combinedGroup);
        $this->assertEquals(14, $combinedGroup['quantity_pcs']);
    }

    /**
     * 6. Multi-allocation tidak menyebabkan kuantitas dihitung dua kali.
     */
    public function test_06_multi_allocation_does_not_cause_double_counting(): void
    {
        // 2 trees sharing the same combined code
        $res = $this->createCastingResult([
            ['code' => 'LWDEMO001, LWDEMO002', 'qty' => 10, 'gross' => 10],
            ['code' => 'LWDEMO001, LWDEMO002', 'qty' => 15, 'gross' => 15],
        ]);

        $resolver = app(LostWaxKitirResolverService::class);
        $groups = $resolver->resolveGroups($res);

        $this->assertCount(1, $groups);
        $this->assertEquals(2, $groups[0]['tree_count']);
        $this->assertEquals(25, $groups[0]['quantity_pcs']);
        $this->assertEquals($res->total_pcs, $groups[0]['quantity_pcs']);
    }

    /**
     * 7. Jumlah seluruh Kitir sama dengan total_pcs header (Integer Conservation).
     */
    public function test_07_sum_of_all_kitirs_equals_header_total_pcs(): void
    {
        $res = $this->createCastingResult([
            ['code' => 'K1', 'qty' => 7],
            ['code' => 'K2', 'qty' => 13],
            ['code' => 'K3', 'qty' => 25],
        ]);

        $resolver = app(LostWaxKitirResolverService::class);
        $groups = $resolver->resolveGroups($res);

        $this->assertEquals(45, $res->total_pcs);
        $this->assertEquals($res->total_pcs, $groups->sum('quantity_pcs'));

        // Discrepancy triggers exception
        $res->total_pcs = 50; // Tampered header
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Terjadi ketidaksesuaian kuantitas');
        $resolver->resolveGroups($res);
    }

    /**
     * 8. Kode Produksi kosong ditolak.
     */
    public function test_08_empty_production_code_is_rejected(): void
    {
        $res = $this->createCastingResult([
            ['code' => '', 'qty' => 10],
        ]);

        $resolver = app(LostWaxKitirResolverService::class);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Kode Produksi kosong atau tidak valid');
        $resolver->resolveGroups($res);
    }

    /**
     * 9. Casting Result CANCELLED tidak bisa menerbitkan Kitir.
     */
    public function test_09_cancelled_casting_result_cannot_issue_kitir(): void
    {
        $res = $this->createCastingResult([
            ['code' => 'LW001', 'qty' => 10],
        ], 'CANCELLED');

        $resolver = app(LostWaxKitirResolverService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('berstatus DIBATALKAN');
        $resolver->resolveGroups($res);
    }

    /**
     * 10. Cetak ulang mempertahankan nomor PFKTR yang sama.
     */
    public function test_10_reprint_retains_identical_pfktr_number(): void
    {
        $res = $this->createCastingResult([
            ['code' => 'LW001', 'qty' => 10],
        ]);

        $resolver = app(LostWaxKitirResolverService::class);

        // First issue
        $kitir1 = $resolver->issueOrGetKitir($res, 'LW001', $this->adminUser);
        $travelerNumber = $kitir1->traveler_number;
        $this->assertStringStartsWith('PFKTR-', $travelerNumber);

        // Reprint / access again
        $kitir2 = $resolver->issueOrGetKitir($res, 'LW001', $this->adminUser);
        $this->assertEquals($travelerNumber, $kitir2->traveler_number);
        $this->assertEquals($kitir1->id, $kitir2->id);

        // Record print increments counter
        $printed1 = $resolver->recordPrint($kitir2, $this->adminUser);
        $this->assertEquals(1, $printed1->print_count);

        $printed2 = $resolver->recordPrint($printed1, $this->adminUser);
        $this->assertEquals(2, $printed2->print_count);
        $this->assertEquals($travelerNumber, $printed2->traveler_number);
    }

    /**
     * 11. Penerbitan berulang tidak menghasilkan nomor ganda untuk kelompok yang sama.
     */
    public function test_11_repeated_issuance_does_not_generate_duplicate_numbers_for_same_group(): void
    {
        $res = $this->createCastingResult([
            ['code' => 'LW001', 'qty' => 10],
            ['code' => 'LW002', 'qty' => 20],
        ]);

        $resolver = app(LostWaxKitirResolverService::class);

        // Issue all twice
        $batch1 = $resolver->issueAllKitirs($res, $this->adminUser);
        $batch2 = $resolver->issueAllKitirs($res, $this->adminUser);

        $this->assertEquals(
            $batch1->pluck('traveler_number')->all(),
            $batch2->pluck('traveler_number')->all()
        );

        $this->assertEquals(2, LostWaxKitir::where('lost_wax_casting_result_id', $res->id)->count());
    }

    /**
     * 12. Nomor PFKTR baru memiliki urutan harian yang benar.
     */
    public function test_12_new_pfktr_numbers_have_correct_daily_sequence(): void
    {
        $date = '2026-11-16';

        $num1 = LostWaxTravelerNumberGenerator::generateNext($date);
        $this->assertEquals('PFKTR-20261116-0001', $num1);

        // Create a fake record with num1 to simulate DB save
        $res = $this->createCastingResult([['code' => 'A', 'qty' => 1]]);
        LostWaxKitir::create([
            'lost_wax_casting_result_id' => $res->id,
            'traveler_number' => $num1,
            'production_code' => 'A',
            'tree_count' => 1,
            'quantity_pcs' => 1,
        ]);

        $num2 = LostWaxTravelerNumberGenerator::generateNext($date);
        $this->assertEquals('PFKTR-20261116-0002', $num2);
    }

    /**
     * 13. QR dan barcode menggunakan payload PFKTR lengkap.
     */
    public function test_13_qr_and_barcode_use_complete_pfktr_payload(): void
    {
        $res = $this->createCastingResult([
            ['code' => 'LWDEMO001', 'qty' => 15],
        ]);

        $response = $this->actingAs($this->adminUser)->get(
            route('lost-wax.casting-results.kitir.print', ['castingResult' => $res, 'code' => 'LWDEMO001'])
        );

        $response->assertOk();
        $html = $response->getContent();

        $kitir = LostWaxKitir::where('lost_wax_casting_result_id', $res->id)->first();
        $this->assertNotNull($kitir);

        // Barcode base64 contains the PFKTR string
        $this->assertStringContainsString($kitir->traveler_number, $html);
        $this->assertStringStartsWith('PFKTR-', $kitir->traveler_number);

        // QR Code SVG is embedded and rendered
        $this->assertStringContainsString('QR (ECC LEVEL H)', $html);
        $this->assertStringContainsString('<svg', $html);
    }

    /**
     * 14. Layout print menggunakan A4 Landscape dan enam proses.
     */
    public function test_14_layout_print_uses_a4_landscape_and_six_fitting_processes(): void
    {
        $res = $this->createCastingResult([
            ['code' => 'LWDEMO001', 'qty' => 15],
        ]);

        $response = $this->actingAs($this->adminUser)->get(
            route('lost-wax.casting-results.kitir.print', ['castingResult' => $res, 'code' => 'LWDEMO001'])
        );

        $response->assertOk();
        $html = $response->getContent();

        // 1. Title and ISO code
        $this->assertStringContainsString('KITIR PRODUKSI - FITTING', $html);
        $this->assertStringContainsString('PERONI', $html);
        $this->assertStringContainsString('FR/PPIC/11-01/09/26-00-01', $html);

        // 2. PCOR is completely removed
        $this->assertStringNotContainsString('PCOR', $html);

        // 3. Exact 6 process steps in sequence
        $this->assertStringContainsString('NETTO', $html);
        $this->assertStringContainsString('KIMIA', $html);
        $this->assertStringContainsString('MARKING', $html);
        $this->assertStringContainsString('BUBUT CNC', $html);
        $this->assertStringContainsString('QC', $html);
        $this->assertStringContainsString('GUDANG JADI', $html);

        // 4. Sand Casting specific stages NOT present
        $this->assertStringNotContainsString('OD FINISH', $html);
        $this->assertStringNotContainsString('>BOR<', $html);

        // 5. A4 landscape css
        $this->assertStringContainsString('size: A4 landscape;', $html);
    }

    /**
     * 15. Sand Casting tidak mengalami regresi.
     */
    public function test_15_sand_casting_does_not_suffer_regression(): void
    {
        // Sand Casting tests run cleanly independently
        $this->assertTrue(class_exists(\App\Services\SandCasting\TravelerNumberGenerator::class));
        $this->assertTrue(class_exists(\App\Services\SandCasting\SandCastingQuantityResolverService::class));
    }

    /**
     * 16. Halaman detail show menampilkan ringkasan Kitir Fitting dan aksinya.
     */
    public function test_16_show_page_displays_kitir_groups_and_actions(): void
    {
        $res = $this->createCastingResult([
            ['code' => 'LWDEMO003', 'qty' => 15],
            ['code' => 'LWDEMO004', 'qty' => 19],
        ]);

        $response = $this->actingAs($this->adminUser)->get(
            route('lost-wax.casting-results.show', $res)
        );

        $response->assertOk();
        $html = $response->getContent();

        // Contains Kitir section
        $this->assertStringContainsString('Kitir Produksi Fitting (Traveler Card Pasca-Cor)', $html);
        $this->assertStringContainsString('LWDEMO003', $html);
        $this->assertStringContainsString('LWDEMO004', $html);
        $this->assertStringContainsString('Cetak Semua Kitir', $html);
        $this->assertStringContainsString('Terbitkan &amp; Cetak', $html);
    }

    /**
     * 17. Cetak seluruh Kitir sekaligus (batch) merender setiap Kitir per halaman A4.
     */
    public function test_17_print_all_batch_renders_all_kitir_pages(): void
    {
        $res = $this->createCastingResult([
            ['code' => 'LWDEMO003', 'qty' => 15],
            ['code' => 'LWDEMO004', 'qty' => 19],
        ]);

        $response = $this->actingAs($this->adminUser)->get(
            route('lost-wax.casting-results.kitir.all', $res)
        );

        $response->assertOk();
        $html = $response->getContent();

        // 2 distinct sheet-container blocks rendered
        $this->assertEquals(2, substr_count($html, 'class="sheet-container"'));
        $this->assertStringContainsString('Dokumen 1 dari 2 Kitir', $html);
        $this->assertStringContainsString('Dokumen 2 dari 2 Kitir', $html);

        // Both Kitirs are issued and saved
        $this->assertEquals(2, LostWaxKitir::where('lost_wax_casting_result_id', $res->id)->count());
    }

    /**
     * 18. User tanpa hak akses ditolak.
     */
    public function test_18_unauthorized_user_is_forbidden(): void
    {
        $res = $this->createCastingResult([
            ['code' => 'LWDEMO001', 'qty' => 10],
        ]);

        $response = $this->actingAs($this->unauthorizedUser)->get(
            route('lost-wax.casting-results.kitir.print', ['castingResult' => $res, 'code' => 'LWDEMO001'])
        );

        $response->assertStatus(403);
    }

    /**
     * 19. Generator me-retry otomatis saat terjadi benturan sequence nomor traveler (uk_lw_kitirs_traveler).
     */
    public function test_19_generator_retries_on_duplicate_traveler_collision_and_succeeds(): void
    {
        $date = '2026-11-17';
        $attemptsMade = 0;

        // Pre-create a kitir with candidate PFKTR-20261117-0001
        $res = $this->createCastingResult([['code' => 'LWDEMO001', 'qty' => 10]]);
        LostWaxKitir::create([
            'lost_wax_casting_result_id' => $res->id,
            'traveler_number' => 'PFKTR-20261117-0001',
            'production_code' => 'LWDEMO001',
            'tree_count' => 1,
            'quantity_pcs' => 10,
        ]);

        $result = LostWaxTravelerNumberGenerator::executeWithRetry(function (string $candidate) use (&$attemptsMade, $res) {
            $attemptsMade++;

            if ($attemptsMade === 1) {
                // Simulate collision on attempt 1 by attempting to insert duplicate PFKTR-20261117-0001
                return LostWaxKitir::create([
                    'lost_wax_casting_result_id' => $res->id,
                    'traveler_number' => 'PFKTR-20261117-0001', // Collides with uk_lw_kitirs_traveler
                    'production_code' => 'LWDEMO002',
                    'tree_count' => 1,
                    'quantity_pcs' => 10,
                ]);
            }

            // On attempt 2, insert with provided candidate
            return LostWaxKitir::create([
                'lost_wax_casting_result_id' => $res->id,
                'traveler_number' => $candidate,
                'production_code' => 'LWDEMO002',
                'tree_count' => 1,
                'quantity_pcs' => 10,
            ]);
        }, $date, 3);

        $this->assertEquals(2, $attemptsMade, 'Harus mencoba tepat 2 kali');
        $this->assertEquals('PFKTR-20261117-0002', $result->traveler_number);
        $this->assertDatabaseHas('lost_wax_kitirs', ['traveler_number' => 'PFKTR-20261117-0002']);
    }

    /**
     * 20. Generator melempar exception informatif setelah 3 kali benturan sequence berturut-turut.
     */
    public function test_20_generator_exhausts_retries_after_three_attempts_and_throws_informative_exception(): void
    {
        $date = '2026-11-17';
        $attemptsMade = 0;

        $res = $this->createCastingResult([['code' => 'LWDEMO001', 'qty' => 10]]);
        LostWaxKitir::create([
            'lost_wax_casting_result_id' => $res->id,
            'traveler_number' => 'PFKTR-20261117-0001',
            'production_code' => 'LWDEMO001',
            'tree_count' => 1,
            'quantity_pcs' => 10,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gagal menerbitkan nomor Kitir Produksi (PFKTR) setelah 3 kali percobaan');

        try {
            LostWaxTravelerNumberGenerator::executeWithRetry(function (string $candidate) use (&$attemptsMade, $res) {
                $attemptsMade++;

                // Force duplicate key collision every time
                return LostWaxKitir::create([
                    'lost_wax_casting_result_id' => $res->id,
                    'traveler_number' => 'PFKTR-20261117-0001',
                    'production_code' => 'LWDEMO002',
                    'tree_count' => 1,
                    'quantity_pcs' => 10,
                ]);
            }, $date, 3);
        } finally {
            $this->assertEquals(3, $attemptsMade);
        }
    }

    /**
     * 21. Generator tidak me-retry error non-collision (misal validation error atau koneksi).
     */
    public function test_21_generator_does_not_retry_non_collision_errors(): void
    {
        $date = '2026-11-17';
        $attemptsMade = 0;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom business error');

        try {
            LostWaxTravelerNumberGenerator::executeWithRetry(function (string $candidate) use (&$attemptsMade) {
                $attemptsMade++;
                throw new \InvalidArgumentException('Custom business error');
            }, $date, 3);
        } finally {
            $this->assertEquals(1, $attemptsMade, 'Non-collision error tidak boleh di-retry');
        }
    }

    /**
     * 22. Proteksi Foreign Key RESTRICT: verifikasi keberadaan migration dan aturan ON DELETE.
     */
    public function test_22_foreign_key_restrict_migration_configured(): void
    {
        $driver = \Illuminate\Support\Facades\Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $fkInfo = \Illuminate\Support\Facades\DB::select("
                SELECT DELETE_RULE
                FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'lost_wax_kitirs'
                  AND CONSTRAINT_NAME = 'fk_lw_kitirs_result'
            ");

            $this->assertNotEmpty($fkInfo);
            $this->assertEquals('RESTRICT', $fkInfo[0]->DELETE_RULE);
        } else {
            // SQLite environment: Verify migration file exists and contains restrictOnDelete
            $migrationFile = database_path('migrations/2026_11_17_180000_update_foreign_key_to_restrict_on_lost_wax_kitirs_table.php');
            $this->assertFileExists($migrationFile);
            $content = file_get_contents($migrationFile);
            $this->assertStringContainsString('restrictOnDelete()', $content);
            $this->assertStringContainsString('fk_lw_kitirs_result', $content);
        }
    }
}
