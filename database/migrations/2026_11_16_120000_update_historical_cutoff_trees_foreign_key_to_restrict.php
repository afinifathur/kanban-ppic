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
        if (Schema::hasTable('lost_wax_historical_cutoff_trees')) {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql') {
                Schema::table('lost_wax_historical_cutoff_trees', function (Blueprint $table) {
                    $table->dropForeign('fk_lw_hct_cutoff');
                    $table->foreign('lost_wax_historical_cutoff_id', 'fk_lw_hct_cutoff')
                        ->references('id')
                        ->on('lost_wax_historical_cutoffs')
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
        if (Schema::hasTable('lost_wax_historical_cutoff_trees')) {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql') {
                Schema::table('lost_wax_historical_cutoff_trees', function (Blueprint $table) {
                    $table->dropForeign('fk_lw_hct_cutoff');
                    $table->foreign('lost_wax_historical_cutoff_id', 'fk_lw_hct_cutoff')
                        ->references('id')
                        ->on('lost_wax_historical_cutoffs')
                        ->cascadeOnDelete();
                });
            }
        }
    }
};

