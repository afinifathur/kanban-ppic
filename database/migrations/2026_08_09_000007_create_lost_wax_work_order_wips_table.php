<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lost_wax_work_order_wips')) {
            return;
        }

        Schema::create('lost_wax_work_order_wips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('lost_wax_work_orders')->cascadeOnDelete();
            $table->foreignId('work_order_plan_id')->nullable()->constrained('lost_wax_work_order_plans')->nullOnDelete();
            $table->string('stage', 20);
            $table->unsignedInteger('quantity');
            $table->string('status', 20)->default('recorded');
            $table->text('notes')->nullable();
            $table->timestamp('produced_at')->nullable();
            $table->timestamps();

            $table->index(['work_order_id', 'stage', 'produced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_wax_work_order_wips');
    }
};
