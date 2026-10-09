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
        // 1. Add inspection_date and auto_nihil columns to sand_casting_stage_executions
        if (Schema::hasTable('sand_casting_stage_executions')) {
            Schema::table('sand_casting_stage_executions', function (Blueprint $table) {
                if (! Schema::hasColumn('sand_casting_stage_executions', 'inspection_date')) {
                    $table->date('inspection_date')->nullable()->after('physical_done_at');
                }

                if (! Schema::hasColumn('sand_casting_stage_executions', 'is_auto_nihil')) {
                    $table->boolean('is_auto_nihil')->default(false)->after('qc_verified_by');
                    $table->index('is_auto_nihil', 'idx_sc_stage_exec_is_auto_nihil');
                }

                if (! Schema::hasColumn('sand_casting_stage_executions', 'auto_nihil_at')) {
                    $table->timestamp('auto_nihil_at')->nullable()->after('is_auto_nihil');
                }
            });
        }

        // 2. Enhance sand_casting_stage_execution_defect_logs with is_system_action and nullable user_id
        if (Schema::hasTable('sand_casting_stage_execution_defect_logs')) {
            Schema::table('sand_casting_stage_execution_defect_logs', function (Blueprint $table) {
                if (! Schema::hasColumn('sand_casting_stage_execution_defect_logs', 'is_system_action')) {
                    $table->boolean('is_system_action')->default(false)->after('user_id');
                }
                $table->unsignedBigInteger('user_id')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('sand_casting_stage_execution_defect_logs')) {
            Schema::table('sand_casting_stage_execution_defect_logs', function (Blueprint $table) {
                if (Schema::hasColumn('sand_casting_stage_execution_defect_logs', 'is_system_action')) {
                    $table->dropColumn('is_system_action');
                }
            });
        }

        if (Schema::hasTable('sand_casting_stage_executions')) {
            Schema::table('sand_casting_stage_executions', function (Blueprint $table) {
                if (Schema::hasColumn('sand_casting_stage_executions', 'auto_nihil_at')) {
                    $table->dropColumn('auto_nihil_at');
                }
                if (Schema::hasColumn('sand_casting_stage_executions', 'is_auto_nihil')) {
                    $table->dropColumn('is_auto_nihil');
                }
                if (Schema::hasColumn('sand_casting_stage_executions', 'inspection_date')) {
                    $table->dropColumn('inspection_date');
                }
            });
        }
    }
};
