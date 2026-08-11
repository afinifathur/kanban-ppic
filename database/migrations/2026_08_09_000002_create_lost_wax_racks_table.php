<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lost_wax_racks')) {
            return;
        }

        Schema::create('lost_wax_racks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('label')->nullable();
            $table->string('location')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_wax_racks');
    }
};
