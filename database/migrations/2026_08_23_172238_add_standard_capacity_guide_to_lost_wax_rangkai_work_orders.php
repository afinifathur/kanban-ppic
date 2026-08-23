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
        Schema::table('lost_wax_rangkai_work_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('lost_wax_rangkai_work_orders', 'standard_capacity_guide')) {
                $table->integer('standard_capacity_guide')->nullable()->after('tree_capacity');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lost_wax_rangkai_work_orders', function (Blueprint $table) {
            if (Schema::hasColumn('lost_wax_rangkai_work_orders', 'standard_capacity_guide')) {
                $table->dropColumn('standard_capacity_guide');
            }
        });
    }
};
