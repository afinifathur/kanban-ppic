<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lost_wax_moulding_instances')) {
            return;
        }

        Schema::create('lost_wax_moulding_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('moulding_family_id')->constrained('lost_wax_moulding_families')->cascadeOnDelete();
            $table->string('instance_code', 100);
            $table->string('label')->nullable();
            $table->foreignId('rack_id')->nullable()->constrained('lost_wax_racks')->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['rack_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_wax_moulding_instances');
    }
};
