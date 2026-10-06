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
        if (! Schema::hasTable('sand_casting_stage_execution_defect_logs')) {
            Schema::create('sand_casting_stage_execution_defect_logs', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('sand_casting_stage_execution_id');
                $table->foreign('sand_casting_stage_execution_id', 'fk_sc_exec_def_log_exec_id')
                    ->references('id')
                    ->on('sand_casting_stage_executions')
                    ->cascadeOnDelete();

                $table->unsignedInteger('added_qty');
                $table->unsignedInteger('previous_total');
                $table->unsignedInteger('new_total');

                $table->unsignedBigInteger('user_id');
                $table->foreign('user_id', 'fk_sc_exec_def_log_user_id')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();

                $table->string('notes', 500)->nullable();
                $table->timestamps();

                $table->index('sand_casting_stage_execution_id', 'idx_sc_exec_def_log_exec_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sand_casting_stage_execution_defect_logs');
    }
};
