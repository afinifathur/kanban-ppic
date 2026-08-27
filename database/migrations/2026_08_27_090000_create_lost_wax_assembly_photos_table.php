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
        if (! Schema::hasTable('lost_wax_assembly_photos')) {
            Schema::create('lost_wax_assembly_photos', function (Blueprint $table) {
                $table->id();
                $table->string('product_code')->index();
                $table->string('product_name')->nullable()->index();
                $table->unsignedInteger('version')->default(1);
                $table->string('front_image_path')->nullable();
                $table->string('side_image_path')->nullable();
                $table->boolean('is_current')->default(true)->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lost_wax_assembly_photos');
    }
};
