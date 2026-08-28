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
        // 1. Add po_quantity & closure fields to production_plans
        if (Schema::hasTable('production_plans')) {
            Schema::table('production_plans', function (Blueprint $table) {
                if (! Schema::hasColumn('production_plans', 'po_quantity')) {
                    $table->unsignedInteger('po_quantity')->nullable()->after('po_number');
                }
                if (! Schema::hasColumn('production_plans', 'closure_reason')) {
                    $table->string('closure_reason', 255)->nullable()->after('is_closed');
                }
                if (! Schema::hasColumn('production_plans', 'closed_by')) {
                    $table->foreignId('closed_by')->nullable()->after('closure_reason')->constrained('users')->onDelete('restrict');
                }
                if (! Schema::hasColumn('production_plans', 'closed_at')) {
                    $table->timestamp('closed_at')->nullable()->after('closed_by');
                }
            });
        }

        // 2. Create lost_wax_tree_defects table
        if (! Schema::hasTable('lost_wax_tree_defects')) {
            Schema::create('lost_wax_tree_defects', function (Blueprint $table) {
                $table->id();
                $table->foreignId('lost_wax_tree_id')->constrained('lost_wax_trees')->onDelete('cascade');
                $table->string('stage', 20);
                $table->unsignedInteger('defect_qty');
                $table->string('defect_reason', 100);
                $table->text('notes')->nullable();
                $table->foreignId('recorded_by')->constrained('users')->onDelete('restrict');
                $table->timestamp('occurred_at')->nullable();
                $table->timestamps();

                $table->index('lost_wax_tree_id');
                $table->index('stage');
                $table->index('recorded_by');
                $table->index('occurred_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lost_wax_tree_defects');

        if (Schema::hasTable('production_plans')) {
            Schema::table('production_plans', function (Blueprint $table) {
                if (Schema::hasColumn('production_plans', 'closed_by')) {
                    $table->dropForeign(['closed_by']);
                    $table->dropColumn('closed_by');
                }
                if (Schema::hasColumn('production_plans', 'closure_reason')) {
                    $table->dropColumn('closure_reason');
                }
                if (Schema::hasColumn('production_plans', 'closed_at')) {
                    $table->dropColumn('closed_at');
                }
                if (Schema::hasColumn('production_plans', 'po_quantity')) {
                    $table->dropColumn('po_quantity');
                }
            });
        }
    }
};
