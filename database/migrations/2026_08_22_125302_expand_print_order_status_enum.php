<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('lost_wax_print_orders')) {
            if (config('database.default') === 'mysql') {
                DB::statement("ALTER TABLE lost_wax_print_orders MODIFY COLUMN status ENUM('DRAFT', 'ISSUED', 'PARTIALLY_COMPLETED', 'COMPLETED', 'CANCELLED') NOT NULL DEFAULT 'DRAFT'");
            } else {
                // Untuk SQLite (dalam mode testing), ubah enum menjadi string agar SQLite membuang CHECK constraint
                Schema::table('lost_wax_print_orders', function (Blueprint $table) {
                    $table->string('status', 30)->default('DRAFT')->change();
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('lost_wax_print_orders')) {
            if (config('database.default') === 'mysql') {
                DB::statement("ALTER TABLE lost_wax_print_orders MODIFY COLUMN status ENUM('DRAFT', 'ISSUED', 'CANCELLED') NOT NULL DEFAULT 'DRAFT'");
            } else {
                Schema::table('lost_wax_print_orders', function (Blueprint $table) {
                    $table->string('status', 20)->default('DRAFT')->change();
                });
            }
        }
    }
};
