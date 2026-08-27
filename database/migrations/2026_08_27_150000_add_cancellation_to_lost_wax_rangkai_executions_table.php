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
        if (Schema::hasTable('lost_wax_rangkai_executions')) {
            Schema::table('lost_wax_rangkai_executions', function (Blueprint $table) {
                if (! Schema::hasColumn('lost_wax_rangkai_executions', 'status')) {
                    $table->string('status', 30)->default('ACTIVE')->after('family_code');
                }
                if (! Schema::hasColumn('lost_wax_rangkai_executions', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable()->after('recorded_at');
                }
                if (! Schema::hasColumn('lost_wax_rangkai_executions', 'cancelled_by')) {
                    $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('lost_wax_rangkai_executions', 'cancellation_reason')) {
                    $table->text('cancellation_reason')->nullable()->after('cancelled_by');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('lost_wax_rangkai_executions')) {
            Schema::table('lost_wax_rangkai_executions', function (Blueprint $table) {
                if (Schema::hasColumn('lost_wax_rangkai_executions', 'cancellation_reason')) {
                    $table->dropColumn('cancellation_reason');
                }
                if (Schema::hasColumn('lost_wax_rangkai_executions', 'cancelled_by')) {
                    $table->dropForeign(['cancelled_by']);
                    $table->dropColumn('cancelled_by');
                }
                if (Schema::hasColumn('lost_wax_rangkai_executions', 'cancelled_at')) {
                    $table->dropColumn('cancelled_at');
                }
                if (Schema::hasColumn('lost_wax_rangkai_executions', 'status')) {
                    $table->dropColumn('status');
                }
            });
        }
    }
};
