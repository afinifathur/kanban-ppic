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
        if (! Schema::hasTable('lost_wax_rangkai_executions')) {
            Schema::create('lost_wax_rangkai_executions', function (Blueprint $table) {
                $table->id();

                // Gunakan nama kustom fk_lw_re_wo agar nama constraint pendek
                $table->foreignId('rangkai_work_order_id')
                    ->constrained('lost_wax_rangkai_work_orders', 'id', 'fk_lw_re_wo')
                    ->cascadeOnDelete();

                $table->date('execution_date');
                $table->integer('trees_created')->unsigned();
                $table->string('family_code', 10);
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('recorded_at');
                $table->timestamps();

                $table->index(['rangkai_work_order_id', 'execution_date'], 'idx_lw_rangkai_exec_wo_date');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lost_wax_rangkai_executions');
    }
};
