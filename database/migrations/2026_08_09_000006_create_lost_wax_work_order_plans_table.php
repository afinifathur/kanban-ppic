<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lost_wax_work_order_plans')) {
            return;
        }

        Schema::create('lost_wax_work_order_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('lost_wax_work_orders')->cascadeOnDelete();
            $table->unsignedInteger('wave_number');
            $table->string('plan_type', 20);
            $table->unsignedInteger('planned_quantity');
            $table->string('status', 20)->default('planned');
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['work_order_id', 'wave_number']);
            $table->index(['work_order_id', 'plan_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_wax_work_order_plans');
    }
};
