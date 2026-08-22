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
                if (! Schema::hasColumn('lost_wax_print_order_lines', 'execution_status')) {
                    $table->string('execution_status', 30)->default('PENDING')->after('qty_ordered');
                }
                if (! Schema::hasColumn('lost_wax_print_order_lines', 'require_layer_7')) {
                    $table->boolean('require_layer_7')->default(false)->after('execution_status');
                }
                if (! Schema::hasColumn('lost_wax_print_order_lines', 'qty_executed_good')) {
                    $table->integer('qty_executed_good')->unsigned()->nullable()->after('require_layer_7');
                }
                if (! Schema::hasColumn('lost_wax_print_order_lines', 'qty_executed_defect')) {
                    $table->integer('qty_executed_defect')->unsigned()->nullable()->after('qty_executed_good');
                }
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
                $columns = ['execution_status', 'require_layer_7', 'qty_executed_good', 'qty_executed_defect'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('lost_wax_print_order_lines', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
