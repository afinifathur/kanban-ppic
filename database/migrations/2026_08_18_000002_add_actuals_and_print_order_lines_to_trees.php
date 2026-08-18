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
        // 1. Add columns to lost_wax_print_order_lines
        Schema::table('lost_wax_print_order_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('lost_wax_print_order_lines', 'qty_actual_good')) {
                $table->integer('qty_actual_good')->nullable();
            }
            if (! Schema::hasColumn('lost_wax_print_order_lines', 'qty_actual_defect')) {
                $table->integer('qty_actual_defect')->nullable();
            }
            if (! Schema::hasColumn('lost_wax_print_order_lines', 'standard_tree_capacity')) {
                $table->integer('standard_tree_capacity')->default(20);
            }
            if (! Schema::hasColumn('lost_wax_print_order_lines', 'actual_recorded_at')) {
                $table->timestamp('actual_recorded_at')->nullable();
            }
            if (! Schema::hasColumn('lost_wax_print_order_lines', 'actual_recorded_by')) {
                $table->foreignId('actual_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            }
        });

        // 2. Modify lost_wax_trees to make work_order_id nullable and add lost_wax_print_order_line_id
        Schema::table('lost_wax_trees', function (Blueprint $table) {
            if (! Schema::hasColumn('lost_wax_trees', 'lost_wax_print_order_line_id')) {
                $table->foreignId('lost_wax_print_order_line_id')
                    ->nullable()
                    ->after('work_order_plan_id')
                    ->constrained('lost_wax_print_order_lines')
                    ->nullOnDelete();
            }

            $table->foreignId('work_order_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lost_wax_trees', function (Blueprint $table) {
            $table->foreignId('work_order_id')->nullable(false)->change();
            if (Schema::hasColumn('lost_wax_trees', 'lost_wax_print_order_line_id')) {
                $table->dropForeign(['lost_wax_print_order_line_id']);
                $table->dropColumn('lost_wax_print_order_line_id');
            }
        });

        Schema::table('lost_wax_print_order_lines', function (Blueprint $table) {
            $columns = ['qty_actual_good', 'qty_actual_defect', 'standard_tree_capacity', 'actual_recorded_at', 'actual_recorded_by'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('lost_wax_print_order_lines', $column)) {
                    if ($column === 'actual_recorded_by') {
                        $table->dropForeign(['actual_recorded_by']);
                    }
                    $table->dropColumn($column);
                }
            }
        });
    }
};
