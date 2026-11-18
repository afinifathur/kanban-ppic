<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive, idempotent Phase 4.1 migration for Fitting QC Reopen archival:
     * - Widens `fitting_stage_execution_defect_logs.notes` from VARCHAR(500) to TEXT (lossless).
     * - Adds `qc_snapshot_json` JSON NULL to store structured pre-reopen QC verification snapshots.
     * - Zero backfill, zero deletion of historical rows.
     */
    public function up(): void
    {
        if (! Schema::hasTable('fitting_stage_execution_defect_logs')) {
            return;
        }

        if (Schema::hasColumn('fitting_stage_execution_defect_logs', 'notes')) {
            Schema::table('fitting_stage_execution_defect_logs', function (Blueprint $table) {
                $table->text('notes')->nullable()->change();
            });
        }

        if (! Schema::hasColumn('fitting_stage_execution_defect_logs', 'qc_snapshot_json')) {
            Schema::table('fitting_stage_execution_defect_logs', function (Blueprint $table) {
                $table->json('qc_snapshot_json')->nullable()->after('notes');
            });
        }
    }

    /**
     * Safe, lossless rollback:
     * - Drops `qc_snapshot_json` if present.
     * - Only narrows `notes` back to VARCHAR(500) if NO row exceeds 500 characters (prevents truncation).
     */
    public function down(): void
    {
        if (! Schema::hasTable('fitting_stage_execution_defect_logs')) {
            return;
        }

        if (Schema::hasColumn('fitting_stage_execution_defect_logs', 'qc_snapshot_json')) {
            Schema::table('fitting_stage_execution_defect_logs', function (Blueprint $table) {
                $table->dropColumn('qc_snapshot_json');
            });
        }

        if (Schema::hasColumn('fitting_stage_execution_defect_logs', 'notes')) {
            $driver = DB::connection()->getDriverName();
            $lengthFn = $driver === 'mysql' ? 'CHAR_LENGTH' : 'LENGTH';
            $maxLen = (int) (DB::table('fitting_stage_execution_defect_logs')
                ->selectRaw("COALESCE(MAX({$lengthFn}(notes)), 0) as max_len")
                ->value('max_len') ?? 0);

            if ($maxLen <= 500) {
                Schema::table('fitting_stage_execution_defect_logs', function (Blueprint $table) {
                    $table->string('notes', 500)->nullable()->change();
                });
            }
        }
    }
};
