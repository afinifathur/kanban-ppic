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
        if (! Schema::hasTable('sand_casting_casting_results')) {
            Schema::create('sand_casting_casting_results', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sand_casting_casting_order_id');
                $table->foreign('sand_casting_casting_order_id', 'fk_sc_results_order_id')
                    ->references('id')
                    ->on('sand_casting_casting_orders')
                    ->restrictOnDelete();

                $table->string('heat_number', 50);
                $table->date('cast_date');
                $table->string('furnace', 50)->nullable();
                $table->string('shift', 20)->nullable();
                $table->string('operator_name', 100)->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('recorded_by')->constrained('users');
                $table->timestamps();

                $table->index('heat_number', 'idx_sc_results_heat_number');
                $table->index(['sand_casting_casting_order_id', 'heat_number'], 'idx_sc_results_order_heat');
            });
        }

        if (! Schema::hasTable('sand_casting_casting_result_lines')) {
            Schema::create('sand_casting_casting_result_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sand_casting_casting_result_id');
                $table->foreign('sand_casting_casting_result_id', 'fk_sc_result_lines_result_id')
                    ->references('id')
                    ->on('sand_casting_casting_results')
                    ->cascadeOnDelete();

                $table->unsignedBigInteger('sand_casting_casting_order_line_id');
                $table->foreign('sand_casting_casting_order_line_id', 'fk_sc_result_lines_order_line_id')
                    ->references('id')
                    ->on('sand_casting_casting_order_lines')
                    ->restrictOnDelete();

                $table->unsignedBigInteger('production_plan_id')->nullable();
                $table->foreign('production_plan_id', 'fk_sc_result_lines_plan_id')
                    ->references('id')
                    ->on('production_plans')
                    ->nullOnDelete();

                $table->string('traveler_number', 60)->unique();
                $table->integer('qty_good');
                $table->integer('qty_reject')->default(0);
                $table->decimal('unit_weight_kg', 10, 2)->default(0);
                $table->decimal('total_weight_kg', 10, 2)->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['sand_casting_casting_result_id', 'production_plan_id'], 'idx_sc_res_lines_res_plan');
                $table->index('sand_casting_casting_order_line_id', 'idx_sc_res_lines_order_line');
                $table->index('production_plan_id', 'idx_sc_res_lines_plan_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sand_casting_casting_result_lines');
        Schema::dropIfExists('sand_casting_casting_results');
    }
};
