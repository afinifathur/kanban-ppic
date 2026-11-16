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
        if (! Schema::hasTable('lost_wax_historical_cutoffs')) {
            Schema::create('lost_wax_historical_cutoffs', function (Blueprint $table) {
                $table->id();
                $table->string('cutoff_number', 64)->unique('uk_lw_hc_number');
                $table->date('cutoff_date')->index('idx_lw_hc_date');
                $table->string('reason');
                $table->text('notes')->nullable();
                $table->unsignedInteger('total_trees')->default(0);
                $table->unsignedInteger('total_quantity')->default(0);
                $table->unsignedInteger('total_defect_quantity')->default(0);
                $table->unsignedInteger('total_usable_quantity')->default(0);
                $table->string('status', 32)->default('ACTIVE')->index('idx_lw_hc_status');

                // Audit metadata
                $table->foreignId('executed_by')
                    ->constrained('users', 'id', 'fk_lw_hc_executed_by');
                $table->timestamp('executed_at')->nullable();

                // Reversal audit support (database-level only, no UI execution until approved)
                $table->foreignId('voided_by')
                    ->nullable()
                    ->constrained('users', 'id', 'fk_lw_hc_voided_by');
                $table->timestamp('voided_at')->nullable();
                $table->text('void_reason')->nullable();

                $table->timestamps();
            });
        }

        if (! Schema::hasTable('lost_wax_historical_cutoff_trees')) {
            Schema::create('lost_wax_historical_cutoff_trees', function (Blueprint $table) {
                $table->id();

                $table->foreignId('lost_wax_historical_cutoff_id')
                    ->constrained('lost_wax_historical_cutoffs', 'id', 'fk_lw_hct_cutoff')
                    ->restrictOnDelete();

                $table->foreignId('lost_wax_tree_id')
                    ->constrained('lost_wax_trees', 'id', 'fk_lw_hct_tree');

                // Active tree constraint: UNIQUE when not null.
                // When transaction is ACTIVE: active_tree_id = lost_wax_tree_id.
                // When transaction is VOIDED/CANCELLED: active_tree_id = NULL.
                // Guarantees at database level that one tree can never have more than one active cutoff.
                $table->unsignedBigInteger('active_tree_id')->nullable()->unique('uk_lw_hct_active_tree');
                $table->foreign('active_tree_id', 'fk_lw_hct_active_tree')
                    ->references('id')
                    ->on('lost_wax_trees')
                    ->nullOnDelete();

                // Snapshot & context fields
                $table->string('barcode')->index('idx_lw_hct_barcode');
                $table->unsignedInteger('tree_number')->nullable();
                $table->foreignId('work_order_id')
                    ->nullable()
                    ->constrained('lost_wax_work_orders', 'id', 'fk_lw_hct_wo');
                $table->foreignId('lost_wax_print_order_line_id')
                    ->nullable()
                    ->constrained('lost_wax_print_order_lines', 'id', 'fk_lw_hct_pol');
                $table->string('production_code')->nullable()->index('idx_lw_hct_code');
                $table->string('customer')->nullable();
                $table->string('item_name')->nullable();

                // Previous state for complete historical audit
                $table->string('previous_stage')->nullable();
                $table->string('previous_status')->default('generated');

                // Quantities
                $table->unsignedInteger('quantity')->default(0);
                $table->unsignedInteger('defect_quantity')->default(0);
                $table->unsignedInteger('usable_quantity')->default(0);

                // Milestone dates & explicit source tracking
                $table->datetime('siap_cor_date')->nullable();
                $table->string('siap_cor_date_source', 40);
                $table->date('cor_date')->nullable();
                $table->string('cor_date_source', 40);

                $table->timestamp('recorded_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lost_wax_historical_cutoff_trees');
        Schema::dropIfExists('lost_wax_historical_cutoffs');
    }
};

