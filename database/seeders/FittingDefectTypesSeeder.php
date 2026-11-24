<?php

namespace Database\Seeders;

use App\Models\DefectType;
use Illuminate\Database\Seeder;

class FittingDefectTypesSeeder extends Seeder
{
    /**
     * Canonical 14 universal defect types for Fitting Production.
     * Applicable across all 6 operational stages (netto_pf, kimia, marking, bubut_cnc_pf, qc_pf, gudang_jadi_pf).
     *
     * @var list<string>
     */
    public const UNIVERSAL_DEFECT_NAMES = [
        'KEROPOS',
        'PUTUS',
        'RUSAK POTONG',
        'RUSAK VIEW',
        'RANGKAIAN BOCOR',
        'BUNGA COR',
        'TIDAK SAMBUNG',
        'RUSAK DRAT',
        'RUSAK SETTING',
        'RUSAK BUBUT',
        'BARANG MIRING',
        'DRAT BLONG',
        'OVAL',
        'BOCOR TES',
    ];

    public const CANONICAL_DEPARTMENT = 'fitting';

    /**
     * Run the database seeds.
     * Strictly idempotent: preserves existing IDs, sets is_active = true, never duplicates or deletes.
     */
    public function run(): void
    {
        foreach (self::UNIVERSAL_DEFECT_NAMES as $name) {
            DefectType::updateOrCreate(
                [
                    'department' => self::CANONICAL_DEPARTMENT,
                    'name' => $name,
                ],
                [
                    'is_active' => true,
                ]
            );
        }
    }
}
