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
        // 1. Add assigned_stage attribute to users table
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'assigned_stage')) {
                    $table->string('assigned_stage', 30)->nullable()->after('product_scope');
                }
            });
        }

        // 2. Enhance sand_casting_stage_executions with checkpoint and lifecycle columns
        if (Schema::hasTable('sand_casting_stage_executions')) {
            Schema::table('sand_casting_stage_executions', function (Blueprint $table) {
                if (! Schema::hasColumn('sand_casting_stage_executions', 'checkpoint_code')) {
                    $table->string('checkpoint_code', 50)->after('stage');
                    $table->index('checkpoint_code', 'idx_sc_stage_exec_chk_code');
                }

                if (! Schema::hasColumn('sand_casting_stage_executions', 'status')) {
                    $table->string('status', 30)->default('READY')->after('good_qty');
                    $table->index('status', 'idx_sc_stage_exec_status');
                }

                if (! Schema::hasColumn('sand_casting_stage_executions', 'physical_done_at')) {
                    $table->timestamp('physical_done_at')->nullable()->after('executed_at');
                }

                if (! Schema::hasColumn('sand_casting_stage_executions', 'defect_entered_at')) {
                    $table->timestamp('defect_entered_at')->nullable()->after('physical_done_at');
                }

                if (! Schema::hasColumn('sand_casting_stage_executions', 'defect_entered_by')) {
                    $table->foreignId('defect_entered_by')
                        ->nullable()
                        ->after('defect_entered_at')
                        ->constrained('users')
                        ->nullOnDelete();
                }

                if (! Schema::hasColumn('sand_casting_stage_executions', 'qc_verified_at')) {
                    $table->timestamp('qc_verified_at')->nullable()->after('defect_entered_by');
                }

                if (! Schema::hasColumn('sand_casting_stage_executions', 'qc_verified_by')) {
                    $table->foreignId('qc_verified_by')
                        ->nullable()
                        ->after('qc_verified_at')
                        ->constrained('users')
                        ->nullOnDelete();
                }
            });

            // Safe drop of legacy unique index if it exists in MySQL/SQLite
            try {
                Schema::table('sand_casting_stage_executions', function (Blueprint $table) {
                    $table->dropUnique('uniq_sc_stage_exec_line_stage');
                });
            } catch (\Throwable $e) {
                // Index may already be dropped or not present
            }

            // Create new unique index on (line_id, checkpoint_code) if not present
            try {
                Schema::table('sand_casting_stage_executions', function (Blueprint $table) {
                    $table->unique(['sand_casting_casting_result_line_id', 'checkpoint_code'], 'uniq_sc_stage_exec_line_chk');
                });
            } catch (\Throwable $e) {
                // Index may already exist
            }
        }

        // 3. Create sand_casting_stage_execution_defects breakdown table
        if (! Schema::hasTable('sand_casting_stage_execution_defects')) {
            Schema::create('sand_casting_stage_execution_defects', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('sand_casting_stage_execution_id');
                $table->foreign('sand_casting_stage_execution_id', 'fk_sc_exec_def_exec_id')
                    ->references('id')
                    ->on('sand_casting_stage_executions')
                    ->cascadeOnDelete();

                $table->unsignedBigInteger('defect_type_id');
                $table->foreign('defect_type_id', 'fk_sc_exec_def_type_id')
                    ->references('id')
                    ->on('defect_types')
                    ->restrictOnDelete();

                $table->unsignedInteger('qty');
                $table->string('notes', 255)->nullable();
                $table->timestamps();

                $table->index(['sand_casting_stage_execution_id', 'defect_type_id'], 'idx_sc_exec_def_pair');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sand_casting_stage_execution_defects');

        if (Schema::hasTable('sand_casting_stage_executions')) {
            try {
                Schema::table('sand_casting_stage_executions', function (Blueprint $table) {
                    $table->dropUnique('uniq_sc_stage_exec_line_chk');
                });
            } catch (\Throwable $e) {
                // Ignore
            }

            Schema::table('sand_casting_stage_executions', function (Blueprint $table) {
                if (Schema::hasColumn('sand_casting_stage_executions', 'qc_verified_by')) {
                    $table->dropConstrainedForeignId('qc_verified_by');
                }
                if (Schema::hasColumn('sand_casting_stage_executions', 'qc_verified_at')) {
                    $table->dropColumn('qc_verified_at');
                }
                if (Schema::hasColumn('sand_casting_stage_executions', 'defect_entered_by')) {
                    $table->dropConstrainedForeignId('defect_entered_by');
                }
                if (Schema::hasColumn('sand_casting_stage_executions', 'defect_entered_at')) {
                    $table->dropColumn('defect_entered_at');
                }
                if (Schema::hasColumn('sand_casting_stage_executions', 'physical_done_at')) {
                    $table->dropColumn('physical_done_at');
                }
                if (Schema::hasColumn('sand_casting_stage_executions', 'status')) {
                    $table->dropColumn('status');
                }
                if (Schema::hasColumn('sand_casting_stage_executions', 'checkpoint_code')) {
                    $table->dropColumn('checkpoint_code');
                }
            });

            try {
                Schema::table('sand_casting_stage_executions', function (Blueprint $table) {
                    $table->unique(['sand_casting_casting_result_line_id', 'stage'], 'uniq_sc_stage_exec_line_stage');
                });
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (Schema::hasColumn('users', 'assigned_stage')) {
                    $table->dropColumn('assigned_stage');
                }
            });
        }
    }
};
