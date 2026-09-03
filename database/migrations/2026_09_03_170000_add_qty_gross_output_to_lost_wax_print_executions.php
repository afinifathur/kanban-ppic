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
        if (Schema::hasTable('lost_wax_print_executions')) {
            Schema::table('lost_wax_print_executions', function (Blueprint $table) {
                if (! Schema::hasColumn('lost_wax_print_executions', 'qty_gross_output')) {
                    $table->integer('qty_gross_output')->unsigned()->nullable()->after('execution_date');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('lost_wax_print_executions')) {
            Schema::table('lost_wax_print_executions', function (Blueprint $table) {
                if (Schema::hasColumn('lost_wax_print_executions', 'qty_gross_output')) {
                    $table->dropColumn('qty_gross_output');
                }
            });
        }
    }
};
