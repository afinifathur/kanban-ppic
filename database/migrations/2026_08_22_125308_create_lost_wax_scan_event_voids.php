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
        if (! Schema::hasTable('lost_wax_scan_event_voids')) {
            Schema::create('lost_wax_scan_event_voids', function (Blueprint $table) {
                $table->id();

                // Gunakan nama kustom fk_lw_sev_event agar nama constraint pendek
                $table->foreignId('scan_event_id')
                    ->unique()
                    ->constrained('lost_wax_scan_events', 'id', 'fk_lw_sev_event')
                    ->cascadeOnDelete();

                $table->foreignId('voided_by')->constrained('users');
                $table->timestamp('voided_at');
                $table->text('void_reason');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lost_wax_scan_event_voids');
    }
};
