<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $customers = [
            'E01',
            'E02',
            'E03',
            'E04',
            'E05',
            'E06',
            'A01',
            'A02',
            'A03',
            'A04',
            'A05',
            'A06',
            'LOKAL',
            'STOK NON PO',
            'STOK EXP'
        ];

        foreach ($customers as $name) {
            Customer::updateOrCreate(['name' => $name], ['is_active' => true]);
        }
    }
}
