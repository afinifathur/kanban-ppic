<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sand_casting_wip_snapshots')) {
            Schema::create('sand_casting_wip_snapshots', function (Blueprint $table) {
                $table->id();
                $table->date('snapshot_date')->comment('Tanggal operasional cutoff');
                $table->dateTime('scheduled_cutoff_at')->comment('Waktu cutoff terjadwal (07:00:00 WIB)');
                $table->dateTime('captured_at')->comment('Waktu aktual pengambilan snapshot');
                $table->string('stage', 30)->comment('Tahap: netto, bubut_od, bubut_cnc, bor, qc');
                $table->string('product_scope', 50)->default('all')->comment('Scope produk (all, FLANGE_BESI, dll)');
                $table->unsignedInteger('qty_pcs')->default(0)->comment('Total kuantitas PCS antrean aktif');
                $table->decimal('weight_kg', 12, 4)->default(0)->comment('Total berat dalam kg');
                $table->unsignedInteger('ktr_count')->default(0)->comment('Jumlah lembar KTR unik');
                $table->string('source_type', 20)->default('actual')->comment('actual atau reconstructed');
                $table->string('status', 20)->default('SUCCESS')->comment('SUCCESS atau FAILED');
                $table->json('details')->nullable()->comment('Metadata audit KTR/line');
                $table->timestamps();

                $table->unique(['snapshot_date', 'stage', 'product_scope'], 'uk_sc_wip_snapshot');
                $table->index(['stage', 'product_scope', 'snapshot_date'], 'idx_sc_wip_stage_date');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sand_casting_wip_snapshots');
    }
};
