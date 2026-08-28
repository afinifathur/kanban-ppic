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
        if (Schema::hasTable('lost_wax_print_orders')) {
            Schema::table('lost_wax_print_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('lost_wax_print_orders', 'order_type')) {
                    $table->string('order_type', 20)->default('REGULAR')->after('status');
                }
                if (! Schema::hasColumn('lost_wax_print_orders', 'reprint_reason')) {
                    $table->string('reprint_reason', 255)->nullable()->after('order_type');
                }
                if (! Schema::hasColumn('lost_wax_print_orders', 'reprint_cycle')) {
                    $table->unsignedSmallInteger('reprint_cycle')->default(0)->after('reprint_reason');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('lost_wax_print_orders')) {
            Schema::table('lost_wax_print_orders', function (Blueprint $table) {
                if (Schema::hasColumn('lost_wax_print_orders', 'reprint_cycle')) {
                    $table->dropColumn('reprint_cycle');
                }
                if (Schema::hasColumn('lost_wax_print_orders', 'reprint_reason')) {
                    $table->dropColumn('reprint_reason');
                }
                if (Schema::hasColumn('lost_wax_print_orders', 'order_type')) {
                    $table->dropColumn('order_type');
                }
            });
        }
    }
};
