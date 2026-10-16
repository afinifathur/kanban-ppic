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
        if (Schema::hasTable('sand_casting_stage_execution_defect_logs')) {
            Schema::table('sand_casting_stage_execution_defect_logs', function (Blueprint $table) {
                // Change added_qty from unsigned to signed integer to support negative corrections
                $table->integer('added_qty')->change();

                // Add idempotency_key to prevent duplicate client submissions
                if (! Schema::hasColumn('sand_casting_stage_execution_defect_logs', 'idempotency_key')) {
                    $table->string('idempotency_key', 64)->nullable()->unique();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('sand_casting_stage_execution_defect_logs')) {
            // Guard against data loss: refuse rollback if negative adjustment records exist
            $hasNegativeLogs = \Illuminate\Support\Facades\DB::table('sand_casting_stage_execution_defect_logs')
                ->where('added_qty', '<', 0)
                ->exists();

            if ($hasNegativeLogs) {
                throw new \RuntimeException('Tidak dapat melakukan rollback migration: tabel sand_casting_stage_execution_defect_logs masih memiliki rekaman koreksi defect negatif (added_qty < 0). Selesaikan atau arsipkan rekaman tersebut terlebih dahulu.');
            }

            Schema::table('sand_casting_stage_execution_defect_logs', function (Blueprint $table) {
                if (Schema::hasColumn('sand_casting_stage_execution_defect_logs', 'idempotency_key')) {
                    $table->dropUnique(['idempotency_key']);
                    $table->dropColumn('idempotency_key');
                }
                $table->unsignedInteger('added_qty')->change();
            });
        }
    }
};
