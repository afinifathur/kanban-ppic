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
        if (! Schema::hasTable('lost_wax_print_executions')) {
            Schema::create('lost_wax_print_executions', function (Blueprint $table) {
                $table->id();

                // Gunakan nama kustom fk_lw_pe_line agar nama foreign key constraint pendek
                $table->foreignId('lost_wax_print_order_line_id')
                    ->constrained('lost_wax_print_order_lines', 'id', 'fk_lw_pe_line')
                    ->cascadeOnDelete();

                $table->date('execution_date');
                $table->integer('qty_good')->unsigned();
                $table->integer('qty_defect')->unsigned()->default(0);
                $table->string('status', 20)->default('DRAFT');
                $table->text('notes')->nullable();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('recorded_at');
                $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('finalized_at')->nullable();
                $table->timestamps();

                $table->index(['lost_wax_print_order_line_id', 'execution_date'], 'idx_lw_print_exec_line_date');
                $table->index('status', 'idx_lw_print_exec_status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lost_wax_print_executions');
    }
};
