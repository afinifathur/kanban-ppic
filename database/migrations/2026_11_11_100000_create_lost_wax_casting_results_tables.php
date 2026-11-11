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
        if (! Schema::hasTable('lost_wax_casting_results')) {
            Schema::create('lost_wax_casting_results', function (Blueprint $table) {
                $table->id();
                $table->string('heat_number')->index();
                $table->date('cast_date')->index();
                $table->string('furnace')->nullable();
                $table->string('shift')->nullable();
                $table->string('operator_name')->nullable();
                $table->unsignedInteger('total_trees')->default(0);
                $table->unsignedInteger('total_pcs')->default(0);
                $table->text('notes')->nullable();
                $table->string('status', 32)->default('COMPLETED')->index();

                // Audit metadata
                $table->foreignId('recorded_by')
                    ->constrained('users', 'id', 'fk_lw_cr_recorded_by');
                $table->foreignId('voided_by')
                    ->nullable()
                    ->constrained('users', 'id', 'fk_lw_cr_voided_by');
                $table->timestamp('voided_at')->nullable();
                $table->text('void_reason')->nullable();

                $table->timestamps();
            });
        }

        if (! Schema::hasTable('lost_wax_casting_result_trees')) {
            Schema::create('lost_wax_casting_result_trees', function (Blueprint $table) {
                $table->id();

                $table->foreignId('lost_wax_casting_result_id')
                    ->constrained('lost_wax_casting_results', 'id', 'fk_lw_crt_result')
                    ->cascadeOnDelete();

                $table->foreignId('lost_wax_tree_id')
                    ->constrained('lost_wax_trees', 'id', 'fk_lw_crt_tree');

                // Active tree constraint: UNIQUE when not null.
                // When transaction is COMPLETED: active_tree_id = lost_wax_tree_id.
                // When transaction is CANCELLED: active_tree_id = NULL.
                // This permits tree reuse while enforcing database-level active single-consumption.
                $table->unsignedBigInteger('active_tree_id')->nullable()->unique('uk_lw_crt_active_tree');
                $table->foreign('active_tree_id', 'fk_lw_crt_active_tree')
                    ->references('id')
                    ->on('lost_wax_trees')
                    ->nullOnDelete();

                // Snapshot fields
                $table->string('barcode')->index();
                $table->string('production_code')->index();
                $table->string('customer')->nullable();
                $table->string('item_name')->nullable();
                $table->unsignedInteger('quantity_pcs');
                $table->unsignedInteger('gross_pcs')->default(0);
                $table->unsignedInteger('defect_pcs')->default(0);

                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lost_wax_casting_result_trees');
        Schema::dropIfExists('lost_wax_casting_results');
    }
};
