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
        // 1. Add current_stage and urgent metadata to sand_casting_casting_result_lines
        if (Schema::hasTable('sand_casting_casting_result_lines')) {
            Schema::table('sand_casting_casting_result_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('sand_casting_casting_result_lines', 'current_stage')) {
                    $table->string('current_stage', 30)->nullable()->after('last_printed_by');
                    $table->index('current_stage', 'idx_sc_res_lines_current_stage');
                }

                if (! Schema::hasColumn('sand_casting_casting_result_lines', 'is_urgent')) {
                    $table->boolean('is_urgent')->default(false)->after('current_stage');
                    $table->index('is_urgent', 'idx_sc_res_lines_is_urgent');
                }

                if (! Schema::hasColumn('sand_casting_casting_result_lines', 'urgent_set_at')) {
                    $table->timestamp('urgent_set_at')->nullable()->after('is_urgent');
                }

                if (! Schema::hasColumn('sand_casting_casting_result_lines', 'urgent_set_by')) {
                    $table->foreignId('urgent_set_by')
                        ->nullable()
                        ->after('urgent_set_at')
                        ->constrained('users')
                        ->nullOnDelete();
                }
            });
        }

        // 2. Create sand_casting_stage_executions table
        if (! Schema::hasTable('sand_casting_stage_executions')) {
            Schema::create('sand_casting_stage_executions', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('sand_casting_casting_result_line_id');
                $table->foreign('sand_casting_casting_result_line_id', 'fk_sc_stage_exec_line_id')
                    ->references('id')
                    ->on('sand_casting_casting_result_lines')
                    ->restrictOnDelete();

                $table->string('stage', 30);
                $table->unsignedInteger('input_qty');
                $table->unsignedInteger('defect_qty')->default(0);
                $table->unsignedInteger('good_qty');

                $table->foreignId('operator_id')
                    ->constrained('users')
                    ->restrictOnDelete();

                $table->timestamp('executed_at');
                $table->text('notes')->nullable();
                $table->timestamps();

                // Unique constraint: 1 KTR + 1 Stage = maksimal 1 execution record
                $table->unique(['sand_casting_casting_result_line_id', 'stage'], 'uniq_sc_stage_exec_line_stage');

                // Performance indexes
                $table->index('sand_casting_casting_result_line_id', 'idx_sc_stage_exec_line_id');
                $table->index('stage', 'idx_sc_stage_exec_stage');
                $table->index('executed_at', 'idx_sc_stage_exec_executed_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sand_casting_stage_executions');

        if (Schema::hasTable('sand_casting_casting_result_lines')) {
            Schema::table('sand_casting_casting_result_lines', function (Blueprint $table) {
                if (Schema::hasColumn('sand_casting_casting_result_lines', 'urgent_set_by')) {
                    $table->dropConstrainedForeignId('urgent_set_by');
                }
                if (Schema::hasColumn('sand_casting_casting_result_lines', 'urgent_set_at')) {
                    $table->dropColumn('urgent_set_at');
                }
                if (Schema::hasColumn('sand_casting_casting_result_lines', 'is_urgent')) {
                    $table->dropColumn('is_urgent');
                }
                if (Schema::hasColumn('sand_casting_casting_result_lines', 'current_stage')) {
                    $table->dropColumn('current_stage');
                }
            });
        }
    }
};
