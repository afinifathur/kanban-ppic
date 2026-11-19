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
        if (Schema::hasTable('lost_wax_tree_defects')) {
            $addContext = ! Schema::hasColumn('lost_wax_tree_defects', 'defect_context');
            $addLateEntry = ! Schema::hasColumn('lost_wax_tree_defects', 'is_late_entry');
            $addScanEventId = ! Schema::hasColumn('lost_wax_tree_defects', 'scan_event_id');
            $addVoidedAt = ! Schema::hasColumn('lost_wax_tree_defects', 'voided_at');
            $addVoidedBy = ! Schema::hasColumn('lost_wax_tree_defects', 'voided_by');
            $addVoidReason = ! Schema::hasColumn('lost_wax_tree_defects', 'void_reason');

            if ($addContext || $addLateEntry || $addScanEventId || $addVoidedAt || $addVoidedBy || $addVoidReason) {
                Schema::table('lost_wax_tree_defects', function (Blueprint $table) use (
                    $addContext,
                    $addLateEntry,
                    $addScanEventId,
                    $addVoidedAt,
                    $addVoidedBy,
                    $addVoidReason
                ) {
                    if ($addContext) {
                        $table->string('defect_context', 24)->default('pre_siap_cor')->after('stage');
                    }
                    if ($addLateEntry) {
                        $table->boolean('is_late_entry')->default(false)->after('defect_context');
                    }
                    if ($addScanEventId) {
                        $table->unsignedBigInteger('scan_event_id')->nullable()->after('is_late_entry');
                        $table->foreign('scan_event_id', 'fk_lw_td_scan_event')
                            ->references('id')
                            ->on('lost_wax_scan_events')
                            ->nullOnDelete();
                    }
                    if ($addVoidedAt) {
                        $table->timestamp('voided_at')->nullable()->after('occurred_at');
                    }
                    if ($addVoidedBy) {
                        $table->unsignedBigInteger('voided_by')->nullable()->after('voided_at');
                        $table->foreign('voided_by', 'fk_lw_td_voided_by')
                            ->references('id')
                            ->on('users')
                            ->restrictOnDelete();
                    }
                    if ($addVoidReason) {
                        $table->string('void_reason', 255)->nullable()->after('voided_by');
                    }
                    if ($addVoidedAt) {
                        $table->index(['lost_wax_tree_id', 'voided_at'], 'idx_lw_td_tree_voided');
                    }
                    if ($addContext && $addVoidedAt) {
                        $table->index(['stage', 'defect_context', 'voided_at'], 'idx_lw_td_stage_ctx_voided');
                    }
                });
            }
        }

        if (Schema::hasTable('lost_wax_scan_events') && ! Schema::hasColumn('lost_wax_scan_events', 'scanned_qty')) {
            Schema::table('lost_wax_scan_events', function (Blueprint $table) {
                $table->unsignedInteger('scanned_qty')->nullable()->after('stage');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('lost_wax_scan_events') && Schema::hasColumn('lost_wax_scan_events', 'scanned_qty')) {
            Schema::table('lost_wax_scan_events', function (Blueprint $table) {
                $table->dropColumn('scanned_qty');
            });
        }

        if (Schema::hasTable('lost_wax_tree_defects')) {
            $hasScanEventId = Schema::hasColumn('lost_wax_tree_defects', 'scan_event_id');
            $hasVoidedBy = Schema::hasColumn('lost_wax_tree_defects', 'voided_by');
            $hasVoidedAt = Schema::hasColumn('lost_wax_tree_defects', 'voided_at');
            $hasVoidReason = Schema::hasColumn('lost_wax_tree_defects', 'void_reason');
            $hasLateEntry = Schema::hasColumn('lost_wax_tree_defects', 'is_late_entry');
            $hasContext = Schema::hasColumn('lost_wax_tree_defects', 'defect_context');

            if ($hasScanEventId || $hasVoidedBy || $hasVoidedAt || $hasVoidReason || $hasLateEntry || $hasContext) {
                Schema::table('lost_wax_tree_defects', function (Blueprint $table) use (
                    $hasScanEventId,
                    $hasVoidedBy,
                    $hasVoidedAt,
                    $hasVoidReason,
                    $hasLateEntry,
                    $hasContext
                ) {
                    if ($hasContext && $hasVoidedAt) {
                        $table->dropIndex('idx_lw_td_stage_ctx_voided');
                    }
                    if ($hasVoidedAt) {
                        $table->dropIndex('idx_lw_td_tree_voided');
                    }
                    if ($hasVoidedBy) {
                        $table->dropForeign('fk_lw_td_voided_by');
                    }
                    if ($hasScanEventId) {
                        $table->dropForeign('fk_lw_td_scan_event');
                    }

                    $dropCols = array_values(array_filter([
                        $hasVoidReason ? 'void_reason' : null,
                        $hasVoidedBy ? 'voided_by' : null,
                        $hasVoidedAt ? 'voided_at' : null,
                        $hasScanEventId ? 'scan_event_id' : null,
                        $hasLateEntry ? 'is_late_entry' : null,
                        $hasContext ? 'defect_context' : null,
                    ]));
                    if ($dropCols !== []) {
                        $table->dropColumn($dropCols);
                    }
                });
            }
        }
    }
};
