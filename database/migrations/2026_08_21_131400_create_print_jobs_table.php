<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('print_jobs')) {
            return;
        }

        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('job_uuid')->unique();
            $table->string('printer_name'); // Nama printer target di Windows (e.g. 'TSC TE200')
            $table->longText('payload_tspl'); // Perintah RAW TSPL
            $table->string('payload_hash', 64); // SHA256 dari payload_tspl untuk integritas data
            $table->integer('copies')->default(1);
            $table->enum('status', ['pending', 'processing', 'printed', 'failed'])->default('pending');

            // Tracking
            $table->string('claimed_by_machine')->nullable(); // Identitas PC yang mencetak
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);

            // Metadata
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('template_type')->nullable(); // e.g. TRAVELER_LABEL_90X50

            $table->timestamps();

            // Index untuk optimasi performa polling & claim secara atomik
            $table->index('status');
            $table->index('printer_name');
            $table->index('claimed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
    }
};
