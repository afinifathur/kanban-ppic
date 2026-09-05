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
        Schema::table('lost_wax_rangkai_executions', function (Blueprint $table) {
            if (! Schema::hasColumn('lost_wax_rangkai_executions', 'additional_source_line_id')) {
                $table->foreignId('additional_source_line_id')->nullable()->after('family_code')->constrained('lost_wax_print_order_lines')->nullOnDelete();
            }
            if (! Schema::hasColumn('lost_wax_rangkai_executions', 'additional_source_code')) {
                $table->string('additional_source_code', 50)->nullable()->after('additional_source_line_id');
            }
            if (! Schema::hasColumn('lost_wax_rangkai_executions', 'additional_source_qty')) {
                $table->integer('additional_source_qty')->default(0)->after('additional_source_code');
            }
            if (! Schema::hasColumn('lost_wax_rangkai_executions', 'additional_source_reason')) {
                $table->text('additional_source_reason')->nullable()->after('additional_source_qty');
            }
        });

        Schema::table('lost_wax_print_order_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('lost_wax_print_order_lines', 'excess_closure_reason')) {
                $table->text('excess_closure_reason')->nullable()->after('qty_excess_closed');
            }
            if (! Schema::hasColumn('lost_wax_print_order_lines', 'excess_closed_by')) {
                $table->foreignId('excess_closed_by')->nullable()->after('excess_closure_reason')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('lost_wax_print_order_lines', 'excess_closed_at')) {
                $table->dateTime('excess_closed_at')->nullable()->after('excess_closed_by');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lost_wax_rangkai_executions', function (Blueprint $table) {
            if (Schema::hasColumn('lost_wax_rangkai_executions', 'additional_source_line_id')) {
                $table->dropForeign(['additional_source_line_id']);
                $table->dropColumn(['additional_source_line_id', 'additional_source_code', 'additional_source_qty', 'additional_source_reason']);
            }
        });

        Schema::table('lost_wax_print_order_lines', function (Blueprint $table) {
            if (Schema::hasColumn('lost_wax_print_order_lines', 'excess_closed_by')) {
                $table->dropForeign(['excess_closed_by']);
                $table->dropColumn(['excess_closure_reason', 'excess_closed_by', 'excess_closed_at']);
            }
        });
    }
};
