<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database in a production-safe manner.
     * Only executes production-approved operational seeders.
     */
    public function run(): void
    {
        $this->call([
            QcFittingUserSeeder::class,
        ]);
    }
}
