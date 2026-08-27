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
        // 1. Create lost_wax_tree_allocations ledger table
        if (! Schema::hasTable('lost_wax_tree_allocations')) {
            Schema::create('lost_wax_tree_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('lost_wax_tree_id')->constrained('lost_wax_trees')->onDelete('cascade');
                $table->foreignId('lost_wax_print_order_line_id')->constrained('lost_wax_print_order_lines')->onDelete('restrict');
                $table->unsignedInteger('allocated_qty');
                $table->timestamps();

                $table->index(['lost_wax_print_order_line_id', 'lost_wax_tree_id'], 'idx_lwta_line_tree');
            });
        }

        // 2. Add qty_excess_closed to lost_wax_print_order_lines
        if (Schema::hasTable('lost_wax_print_order_lines')) {
            Schema::table('lost_wax_print_order_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('lost_wax_print_order_lines', 'qty_excess_closed')) {
                    $table->integer('qty_excess_closed')->default(0)->after('qty_executed_defect');
                }
            });
        }

        // 3. Add variance & anomaly fields to lost_wax_rangkai_executions
        if (Schema::hasTable('lost_wax_rangkai_executions')) {
            Schema::table('lost_wax_rangkai_executions', function (Blueprint $table) {
                if (! Schema::hasColumn('lost_wax_rangkai_executions', 'variance_qty')) {
                    $table->integer('variance_qty')->default(0)->after('status');
                }
                if (! Schema::hasColumn('lost_wax_rangkai_executions', 'is_anomaly')) {
                    $table->boolean('is_anomaly')->default(false)->after('variance_qty');
                }
                if (! Schema::hasColumn('lost_wax_rangkai_executions', 'anomaly_notes')) {
                    $table->string('anomaly_notes')->nullable()->after('is_anomaly');
                }
            });
        }

        // 4. Add closure fields to lost_wax_rangkai_work_orders
        if (Schema::hasTable('lost_wax_rangkai_work_orders')) {
            Schema::table('lost_wax_rangkai_work_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('lost_wax_rangkai_work_orders', 'closure_reason')) {
                    $table->string('closure_reason')->nullable()->after('notes');
                }
                if (! Schema::hasColumn('lost_wax_rangkai_work_orders', 'closed_by')) {
                    $table->foreignId('closed_by')->nullable()->after('closure_reason')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('lost_wax_rangkai_work_orders', 'closed_at')) {
                    $table->timestamp('closed_at')->nullable()->after('closed_by');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('lost_wax_rangkai_work_orders')) {
            Schema::table('lost_wax_rangkai_work_orders', function (Blueprint $table) {
                if (Schema::hasColumn('lost_wax_rangkai_work_orders', 'closed_by')) {
                    $table->dropForeign(['closed_by']);
                    $table->dropColumn('closed_by');
                }
                if (Schema::hasColumn('lost_wax_rangkai_work_orders', 'closure_reason')) {
                    $table->dropColumn('closure_reason');
                }
                if (Schema::hasColumn('lost_wax_rangkai_work_orders', 'closed_at')) {
                    $table->dropColumn('closed_at');
                }
            });
        }

        if (Schema::hasTable('lost_wax_rangkai_executions')) {
            Schema::table('lost_wax_rangkai_executions', function (Blueprint $table) {
                if (Schema::hasColumn('lost_wax_rangkai_executions', 'anomaly_notes')) {
                    $table->dropColumn('anomaly_notes');
                }
                if (Schema::hasColumn('lost_wax_rangkai_executions', 'is_anomaly')) {
                    $table->dropColumn('is_anomaly');
                }
                if (Schema::hasColumn('lost_wax_rangkai_executions', 'variance_qty')) {
                    $table->dropColumn('variance_qty');
                }
            });
        }

        if (Schema::hasTable('lost_wax_print_order_lines')) {
            Schema::table('lost_wax_print_order_lines', function (Blueprint $table) {
                if (Schema::hasColumn('lost_wax_print_order_lines', 'qty_excess_closed')) {
                    $table->dropColumn('qty_excess_closed');
                }
            });
        }

        Schema::dropIfExists('lost_wax_tree_allocations');
    }
};
