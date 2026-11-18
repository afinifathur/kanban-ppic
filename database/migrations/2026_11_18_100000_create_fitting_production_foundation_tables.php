<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates isolated Fitting Production foundation tables (Fase 1).
     */
    public function up(): void
    {
        // 1. fitting_transfers: Batch transfer header (Lost Wax Kitir -> Fitting Production)
        if (! Schema::hasTable('fitting_transfers')) {
            Schema::create('fitting_transfers', function (Blueprint $table) {
                $table->id();
                $table->string('transfer_number', 64)->unique('uk_fit_transfers_number');
                $table->date('transfer_date')->index('idx_fit_transfers_date');
                $table->foreignId('transferred_by')
                    ->constrained('users', 'id', 'fk_fit_transfers_by')
                    ->restrictOnDelete();

                $table->unsignedInteger('total_kitirs')->default(0);
                $table->unsignedInteger('total_pcs')->default(0);
                $table->decimal('total_weight_kg', 12, 2)->default(0);

                $table->string('status', 32)->default('ACTIVE')->index('idx_fit_transfers_status');
                $table->text('notes')->nullable();

                $table->foreignId('cancelled_by')
                    ->nullable()
                    ->constrained('users', 'id', 'fk_fit_transfers_cancelled_by')
                    ->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancel_reason')->nullable();

                $table->string('idempotency_key', 64)->nullable()->unique('uk_fit_transfers_idempotency');
                $table->timestamps();
            });
        }

        // 2. fitting_items: Operational Fitting traveler item (1 active row per transferred LostWaxKitir)
        if (! Schema::hasTable('fitting_items')) {
            Schema::create('fitting_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('fitting_transfer_id')
                    ->constrained('fitting_transfers', 'id', 'fk_fit_items_transfer')
                    ->restrictOnDelete();

                // Audit references to immutable Lost Wax source records (RESTRICT protects historical data)
                $table->foreignId('lost_wax_kitir_id')
                    ->constrained('lost_wax_kitirs', 'id', 'fk_fit_items_kitir')
                    ->restrictOnDelete();

                // Anti-double-transfer constraint: equals lost_wax_kitir_id while ACTIVE, NULL when CANCELLED
                $table->unsignedBigInteger('active_kitir_id')->nullable()->unique('uk_fit_items_active_kitir');
                $table->foreign('active_kitir_id', 'fk_fit_items_active_kitir')
                    ->references('id')
                    ->on('lost_wax_kitirs')
                    ->restrictOnDelete();

                $table->foreignId('lost_wax_casting_result_id')
                    ->constrained('lost_wax_casting_results', 'id', 'fk_fit_items_result')
                    ->restrictOnDelete();

                // Nullable for multi-allocation kitirs or legacy items without a single plan
                $table->foreignId('production_plan_id')
                    ->nullable()
                    ->constrained('production_plans', 'id', 'fk_fit_items_plan')
                    ->nullOnDelete();

                // Immutable source snapshots (decoupled from future ProductionPlan edits)
                $table->string('traveler_number', 64)->index('idx_fit_items_traveler');
                $table->string('heat_number', 64)->index('idx_fit_items_heat');
                $table->date('cast_date')->nullable()->index('idx_fit_items_cast_date');
                $table->string('production_code', 255)->index('idx_fit_items_prod_code');
                $table->string('item_code', 100)->nullable()->index('idx_fit_items_item_code');
                $table->string('item_name', 255)->nullable();
                $table->string('customer', 255)->nullable()->index('idx_fit_items_customer');

                // Line 1..4 directly from ProductionPlan; NULL represents Unassigned (never inferred from size)
                $table->unsignedTinyInteger('line_number')->nullable()->index('idx_fit_items_line');

                $table->unsignedInteger('tree_count')->default(0);
                $table->unsignedInteger('qty_transferred')->default(0);
                $table->decimal('unit_weight_kg', 10, 4)->default(0);
                $table->decimal('total_weight_kg', 12, 2)->default(0);
                $table->boolean('is_multi_allocation')->default(false);

                // Floor operational state (6 digital stages -> completed)
                $table->string('current_stage', 32)->nullable()->default('netto_pf')->index('idx_fit_items_stage');
                $table->unsignedInteger('queue_position')->nullable()->index('idx_fit_items_queue_pos');
                $table->boolean('is_urgent')->default(false)->index('idx_fit_items_urgent');
                $table->timestamp('urgent_set_at')->nullable();
                $table->foreignId('urgent_set_by')
                    ->nullable()
                    ->constrained('users', 'id', 'fk_fit_items_urgent_by')
                    ->nullOnDelete();

                // Lifecycle & cancellation audit
                $table->string('status', 32)->default('ACTIVE')->index('idx_fit_items_status');
                $table->foreignId('cancelled_by')
                    ->nullable()
                    ->constrained('users', 'id', 'fk_fit_items_cancelled_by')
                    ->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancel_reason')->nullable();
                $table->text('notes')->nullable();

                $table->timestamps();
            });
        }

        // 3. fitting_item_allocations: Per-plan allocation snapshot (supports multi-allocation & anti-PO-double-counting)
        if (! Schema::hasTable('fitting_item_allocations')) {
            Schema::create('fitting_item_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('fitting_item_id')
                    ->constrained('fitting_items', 'id', 'fk_fit_alloc_item')
                    ->cascadeOnDelete();
                $table->foreignId('production_plan_id')
                    ->nullable()
                    ->constrained('production_plans', 'id', 'fk_fit_alloc_plan')
                    ->nullOnDelete();

                $table->string('production_code', 100)->index('idx_fit_alloc_prod_code');
                $table->string('item_code', 100)->nullable();
                $table->string('item_name', 255)->nullable();
                $table->string('customer', 255)->nullable();

                // Preserves per-allocation Line even when parent multi-allocation item is Unassigned
                $table->unsignedTinyInteger('line_number')->nullable()->index('idx_fit_alloc_line');

                $table->unsignedInteger('allocated_qty_transferred')->default(0);
                $table->decimal('unit_weight_kg', 10, 4)->default(0);
                $table->decimal('allocated_weight_kg', 12, 2)->default(0);

                $table->timestamps();
            });
        }

        // 4. fitting_kitir_decisions: Terminal NOT_CONTINUED decisions (replaces hard delete) + reopen audit
        if (! Schema::hasTable('fitting_kitir_decisions')) {
            Schema::create('fitting_kitir_decisions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('lost_wax_kitir_id')
                    ->constrained('lost_wax_kitirs', 'id', 'fk_fit_dec_kitir')
                    ->restrictOnDelete();

                // Active unique guard: equals lost_wax_kitir_id while ACTIVE, NULL when REOPENED
                $table->unsignedBigInteger('active_kitir_id')->nullable()->unique('uk_fit_dec_active_kitir');
                $table->foreign('active_kitir_id', 'fk_fit_dec_active_kitir')
                    ->references('id')
                    ->on('lost_wax_kitirs')
                    ->restrictOnDelete();

                $table->foreignId('lost_wax_casting_result_id')
                    ->constrained('lost_wax_casting_results', 'id', 'fk_fit_dec_result')
                    ->restrictOnDelete();

                $table->string('decision', 32)->default('NOT_CONTINUED')->index('idx_fit_dec_decision');
                $table->text('reason')->nullable();

                $table->foreignId('decided_by')
                    ->constrained('users', 'id', 'fk_fit_dec_by')
                    ->restrictOnDelete();
                $table->timestamp('decided_at')->index('idx_fit_dec_at');

                $table->string('status', 32)->default('ACTIVE')->index('idx_fit_dec_status');
                $table->foreignId('reopened_by')
                    ->nullable()
                    ->constrained('users', 'id', 'fk_fit_dec_reopened_by')
                    ->nullOnDelete();
                $table->timestamp('reopened_at')->nullable();
                $table->text('reopen_reason')->nullable();

                $table->timestamps();
            });
        }

        // 5. fitting_stage_executions: 6-stage sequential physical execution & async defect/QC verification
        if (! Schema::hasTable('fitting_stage_executions')) {
            Schema::create('fitting_stage_executions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('fitting_item_id')
                    ->constrained('fitting_items', 'id', 'fk_fit_exec_item')
                    ->restrictOnDelete();

                $table->string('stage', 32)->index('idx_fit_exec_stage');
                $table->string('checkpoint_code', 50)->index('idx_fit_exec_chk');

                $table->unsignedInteger('input_qty')->default(0);
                $table->unsignedInteger('defect_qty')->default(0);
                $table->unsignedInteger('good_qty')->default(0);
                $table->string('status', 32)->default('READY')->index('idx_fit_exec_status');

                $table->foreignId('operator_id')
                    ->constrained('users', 'id', 'fk_fit_exec_operator')
                    ->restrictOnDelete();
                $table->timestamp('executed_at')->index('idx_fit_exec_at');
                $table->timestamp('physical_done_at')->nullable()->index('idx_fit_exec_phys_done');
                $table->date('inspection_date')->nullable()->index('idx_fit_exec_insp_date');

                $table->timestamp('defect_entered_at')->nullable();
                $table->foreignId('defect_entered_by')
                    ->nullable()
                    ->constrained('users', 'id', 'fk_fit_exec_def_by')
                    ->nullOnDelete();

                $table->timestamp('qc_verified_at')->nullable();
                $table->foreignId('qc_verified_by')
                    ->nullable()
                    ->constrained('users', 'id', 'fk_fit_exec_qc_by')
                    ->nullOnDelete();

                $table->boolean('is_auto_nihil')->default(false)->index('idx_fit_exec_auto_nihil');
                $table->timestamp('auto_nihil_at')->nullable();
                $table->text('notes')->nullable();

                $table->timestamps();

                $table->unique(['fitting_item_id', 'checkpoint_code'], 'uk_fit_exec_item_chk');
            });
        }

        // 6. fitting_stage_execution_defects: QC defect type breakdown (draft / confirmed)
        if (! Schema::hasTable('fitting_stage_execution_defects')) {
            Schema::create('fitting_stage_execution_defects', function (Blueprint $table) {
                $table->id();
                $table->foreignId('fitting_stage_execution_id')
                    ->constrained('fitting_stage_executions', 'id', 'fk_fit_exec_def_exec')
                    ->cascadeOnDelete();
                $table->foreignId('defect_type_id')
                    ->constrained('defect_types', 'id', 'fk_fit_exec_def_type')
                    ->restrictOnDelete();

                $table->unsignedInteger('qty');
                $table->string('notes', 255)->nullable();
                $table->timestamps();

                $table->index(['fitting_stage_execution_id', 'defect_type_id'], 'idx_fit_exec_def_pair');
            });
        }

        // 7. fitting_stage_execution_defect_logs: Signed delta +/- audit log & system auto-nihil actions
        if (! Schema::hasTable('fitting_stage_execution_defect_logs')) {
            Schema::create('fitting_stage_execution_defect_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('fitting_stage_execution_id')
                    ->constrained('fitting_stage_executions', 'id', 'fk_fit_def_log_exec')
                    ->cascadeOnDelete();

                // Signed integer to support both positive additions and negative corrections
                $table->integer('added_qty');
                $table->unsignedInteger('previous_total')->default(0);
                $table->unsignedInteger('new_total')->default(0);

                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained('users', 'id', 'fk_fit_def_log_user')
                    ->restrictOnDelete();
                $table->boolean('is_system_action')->default(false)->index('idx_fit_def_log_sys');
                $table->string('notes', 500)->nullable();
                $table->string('idempotency_key', 64)->nullable()->unique('uk_fit_def_log_idempotency');

                $table->timestamps();
            });
        }

        // 8. fitting_wip_snapshots: Daily 07:00 WIB WIP snapshot for Fitting Dashboard Zone 4
        if (! Schema::hasTable('fitting_wip_snapshots')) {
            Schema::create('fitting_wip_snapshots', function (Blueprint $table) {
                $table->id();
                $table->date('snapshot_date');
                $table->dateTime('scheduled_cutoff_at');
                $table->dateTime('captured_at');
                $table->string('stage', 32);
                $table->string('product_scope', 50)->default('FITTING_STAINLESS');
                $table->unsignedInteger('qty_pcs')->default(0);
                $table->decimal('weight_kg', 12, 4)->default(0);
                $table->unsignedInteger('ktr_count')->default(0);
                $table->string('source_type', 20)->default('actual');
                $table->string('status', 20)->default('SUCCESS');
                $table->json('details')->nullable();
                $table->timestamps();

                $table->unique(['snapshot_date', 'stage', 'product_scope'], 'uk_fit_wip_snapshot');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fitting_wip_snapshots');
        Schema::dropIfExists('fitting_stage_execution_defect_logs');
        Schema::dropIfExists('fitting_stage_execution_defects');
        Schema::dropIfExists('fitting_stage_executions');
        Schema::dropIfExists('fitting_kitir_decisions');
        Schema::dropIfExists('fitting_item_allocations');
        Schema::dropIfExists('fitting_items');
        Schema::dropIfExists('fitting_transfers');
    }
};
