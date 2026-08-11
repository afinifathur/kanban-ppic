<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lost_wax_scan_events')) {
            return;
        }

        Schema::create('lost_wax_scan_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tree_id')->constrained('lost_wax_trees')->cascadeOnDelete();
            $table->string('barcode', 30);
            $table->string('stage', 20)->nullable();
            $table->timestamp('scanned_at');
            $table->foreignId('operator_id')->constrained('users');
            $table->string('result', 20)->default('success');
            $table->text('anomaly_reason')->nullable();
            $table->integer('aging_minutes')->nullable();
            $table->string('aging_status', 20)->nullable();
            $table->timestamps();

            $table->index(['tree_id', 'scanned_at']);
            $table->index('barcode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_wax_scan_events');
    }
};
