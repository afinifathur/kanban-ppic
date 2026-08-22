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
        if (! Schema::hasTable('lost_wax_print_execution_corrections')) {
            Schema::create('lost_wax_print_execution_corrections', function (Blueprint $table) {
                $table->id();

                // Gunakan nama kustom fk_lw_pec_exec agar nama constraint pendek
                $table->foreignId('print_execution_id')
                    ->unique()
                    ->constrained('lost_wax_print_executions', 'id', 'fk_lw_pec_exec')
                    ->cascadeOnDelete();

                $table->integer('original_qty_good')->unsigned();
                $table->integer('original_qty_defect')->unsigned();
                $table->integer('corrected_qty_good')->unsigned();
                $table->integer('corrected_qty_defect')->unsigned();
                $table->foreignId('corrected_by')->constrained('users');
                $table->timestamp('corrected_at');
                $table->text('reason');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lost_wax_print_execution_corrections');
    }
};
