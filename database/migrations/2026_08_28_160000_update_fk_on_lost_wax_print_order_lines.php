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
        if (Schema::hasTable('lost_wax_print_order_lines')) {
            Schema::table('lost_wax_print_order_lines', function (Blueprint $table) {
                // Drop existing SET NULL foreign key constraint
                $table->dropForeign(['production_plan_id']);

                // Re-add foreign key constraint with RESTRICT on delete
                $table->foreign('production_plan_id')
                    ->references('id')
                    ->on('production_plans')
                    ->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('lost_wax_print_order_lines')) {
            Schema::table('lost_wax_print_order_lines', function (Blueprint $table) {
                $table->dropForeign(['production_plan_id']);

                $table->foreign('production_plan_id')
                    ->references('id')
                    ->on('production_plans')
                    ->nullOnDelete();
            });
        }
    }
};
