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
        if (Schema::hasTable('lost_wax_kitirs')) {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql') {
                Schema::table('lost_wax_kitirs', function (Blueprint $table) {
                    $table->dropForeign('fk_lw_kitirs_result');
                    $table->foreign('lost_wax_casting_result_id', 'fk_lw_kitirs_result')
                        ->references('id')
                        ->on('lost_wax_casting_results')
                        ->restrictOnDelete();
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('lost_wax_kitirs')) {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql') {
                Schema::table('lost_wax_kitirs', function (Blueprint $table) {
                    $table->dropForeign('fk_lw_kitirs_result');
                    $table->foreign('lost_wax_casting_result_id', 'fk_lw_kitirs_result')
                        ->references('id')
                        ->on('lost_wax_casting_results')
                        ->cascadeOnDelete();
                });
            }
        }
    }
};
