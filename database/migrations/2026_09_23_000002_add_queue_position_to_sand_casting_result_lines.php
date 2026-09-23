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
        if (Schema::hasTable('sand_casting_casting_result_lines')) {
            Schema::table('sand_casting_casting_result_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('sand_casting_casting_result_lines', 'queue_position')) {
                    $table->unsignedInteger('queue_position')->nullable()->after('current_stage');
                    $table->index('queue_position', 'idx_sc_res_lines_queue_pos');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('sand_casting_casting_result_lines')) {
            Schema::table('sand_casting_casting_result_lines', function (Blueprint $table) {
                if (Schema::hasColumn('sand_casting_casting_result_lines', 'queue_position')) {
                    $table->dropIndex('idx_sc_res_lines_queue_pos');
                    $table->dropColumn('queue_position');
                }
            });
        }
    }
};
