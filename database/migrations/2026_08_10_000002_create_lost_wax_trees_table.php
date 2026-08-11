<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lost_wax_trees')) {
            return;
        }

        Schema::create('lost_wax_trees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('lost_wax_work_orders')->cascadeOnDelete();
            $table->foreignId('work_order_plan_id')->nullable()->constrained('lost_wax_work_order_plans')->nullOnDelete();
            $table->string('barcode', 30)->unique();
            $table->integer('tree_number');
            $table->integer('quantity');
            $table->string('status')->default('generated');
            $table->date('production_date');
            $table->string('family_code', 10);
            $table->integer('daily_sequence');
            $table->timestamps();

            $table->unique(['family_code', 'production_date', 'daily_sequence'], 'uq_lw_tree_fam_date_seq');
            $table->index(['work_order_id', 'work_order_plan_id']);
            $table->index(['family_code', 'production_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_wax_trees');
    }
};
