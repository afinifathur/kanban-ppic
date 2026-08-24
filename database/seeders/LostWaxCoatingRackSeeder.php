<?php

namespace Database\Seeders;

use App\Models\LostWaxCoatingRack;
use Illuminate\Database\Seeder;

class LostWaxCoatingRackSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        for ($i = 1; $i <= 35; $i++) {
            LostWaxCoatingRack::firstOrCreate(
                ['rack_number' => $i],
                [
                    'label' => 'Coating Rack '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                    'status' => 'active',
                ]
            );
        }
    }
}
