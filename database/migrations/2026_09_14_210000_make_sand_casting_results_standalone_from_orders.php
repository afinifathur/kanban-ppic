<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('sand_casting_casting_results') && Schema::hasColumn('sand_casting_casting_results', 'sand_casting_casting_order_id')) {
            if (DB::getDriverName() === 'sqlite') {
                Schema::disableForeignKeyConstraints();
                DB::statement('CREATE TABLE sand_casting_casting_results_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    heat_number VARCHAR(50) NOT NULL,
                    cast_date DATE NOT NULL,
                    furnace VARCHAR(50) NULL,
                    shift VARCHAR(20) NULL,
                    operator_name VARCHAR(100) NULL,
                    notes TEXT NULL,
                    recorded_by INTEGER NOT NULL,
                    created_at DATETIME NULL,
                    updated_at DATETIME NULL,
                    FOREIGN KEY (recorded_by) REFERENCES users (id)
                )');
                DB::statement('INSERT INTO sand_casting_casting_results_new (id, heat_number, cast_date, furnace, shift, operator_name, notes, recorded_by, created_at, updated_at)
                    SELECT id, heat_number, cast_date, furnace, shift, operator_name, notes, recorded_by, created_at, updated_at FROM sand_casting_casting_results');
                Schema::drop('sand_casting_casting_results');
                DB::statement('ALTER TABLE sand_casting_casting_results_new RENAME TO sand_casting_casting_results');
                DB::statement('CREATE INDEX idx_sc_results_heat_number ON sand_casting_casting_results (heat_number)');
                Schema::enableForeignKeyConstraints();
            } else {
                // Drop Foreign Key safely by checking existing constraint name in MySQL
                $foreignKeys = DB::select("
                    SELECT CONSTRAINT_NAME
                    FROM information_schema.TABLE_CONSTRAINTS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'sand_casting_casting_results'
                      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                ");
                $fkNames = array_column($foreignKeys, 'CONSTRAINT_NAME');

                if (in_array('fk_sc_results_order_id', $fkNames)) {
                    Schema::table('sand_casting_casting_results', function (Blueprint $table) {
                        $table->dropForeign('fk_sc_results_order_id');
                    });
                } elseif (in_array('sand_casting_casting_results_sand_casting_casting_order_id_foreign', $fkNames)) {
                    Schema::table('sand_casting_casting_results', function (Blueprint $table) {
                        $table->dropForeign(['sand_casting_casting_order_id']);
                    });
                }

                // Drop index safely if exists
                $indexes = DB::select("SHOW INDEX FROM sand_casting_casting_results WHERE Key_name = 'idx_sc_results_order_heat'");
                if (! empty($indexes)) {
                    Schema::table('sand_casting_casting_results', function (Blueprint $table) {
                        $table->dropIndex('idx_sc_results_order_heat');
                    });
                }

                // Drop column safely if exists
                if (Schema::hasColumn('sand_casting_casting_results', 'sand_casting_casting_order_id')) {
                    Schema::table('sand_casting_casting_results', function (Blueprint $table) {
                        $table->dropColumn('sand_casting_casting_order_id');
                    });
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('sand_casting_casting_results') && ! Schema::hasColumn('sand_casting_casting_results', 'sand_casting_casting_order_id')) {
            Schema::table('sand_casting_casting_results', function (Blueprint $table) {
                $table->unsignedBigInteger('sand_casting_casting_order_id')->nullable()->after('id');
                $table->foreign('sand_casting_casting_order_id', 'fk_sc_results_order_id')
                    ->references('id')
                    ->on('sand_casting_casting_orders')
                    ->restrictOnDelete();
                $table->index(['sand_casting_casting_order_id', 'heat_number'], 'idx_sc_results_order_heat');
            });
        }
    }
};
