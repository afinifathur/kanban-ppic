@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-xl font-bold text-slate-900 leading-tight">Buat Perintah Rangkai (Work Order)</h1>
            <p class="text-slate-500 text-[10px]">Formulir penerbitan dokumen perintah perangkaian pohon lilin (Rangkai)</p>
        </div>
        <div>
            <a href="{{ route('lost-wax.assemblies.index') }}" class="bg-white hover:bg-slate-50 text-slate-700 font-semibold text-xs px-3 py-2 rounded-lg transition-all flex items-center gap-1.5 border border-slate-200 shadow-sm">
                <i class="fas fa-arrow-left"></i> Kembali
            </a>
        </div>
    </div>
@endsection

@section('content')
    <form method="POST" action="{{ route('lost-wax.assemblies.work-orders.store', $line) }}" class="max-w-6xl">
        @csrf

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left 2 Columns: Document Details & Input Form -->
            <div class="lg:col-span-2 space-y-6">
                
                <!-- 1. IDENTITAS WORK ORDER -->
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="bg-slate-900 text-white px-5 py-4 flex flex-col md:flex-row md:items-center justify-between gap-3">
                        <div>
                            <span class="text-[9px] uppercase font-bold text-slate-400 tracking-wider block">Dokumen Perintah Kerja (Rangkai Work Order)</span>
                            <div class="text-lg font-extrabold flex items-center gap-2 mt-0.5">
                                <i class="fas fa-file-invoice text-amber-400"></i>
                                Kode Produksi: <span class="text-amber-400">{{ $line->code ?? '-' }}</span>
                            </div>
                        </div>
                        <div class="bg-slate-800 px-3 py-1.5 rounded-lg border border-slate-700 text-right">
                            <span class="text-[8px] text-slate-400 block uppercase font-semibold">Tipe Dokumen</span>
                            <span class="text-xs font-bold text-white uppercase">Lost Wax Assembly</span>
                        </div>
                    </div>

                    <div class="p-5 space-y-5">
                        <!-- Specs Grid -->
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-xs">
                            <div class="bg-slate-50 p-2.5 rounded-lg border border-slate-100">
                                <span class="text-slate-400 block mb-0.5">Nama Produk</span>
                                <strong class="text-slate-800 font-bold block truncate" title="{{ $line->item_name }}">{{ $line->item_name }}</strong>
                            </div>
                            <div class="bg-slate-50 p-2.5 rounded-lg border border-slate-100">
                                <span class="text-slate-400 block mb-0.5">Customer</span>
                                <strong class="text-slate-800 font-bold block">{{ $line->customer ?? '-' }}</strong>
                            </div>
                            <div class="bg-slate-50 p-2.5 rounded-lg border border-slate-100">
                                <span class="text-slate-400 block mb-0.5">Size</span>
                                <strong class="text-slate-800 font-bold block">{{ $line->size ?? '-' }}</strong>
                            </div>
                            <div class="bg-slate-50 p-2.5 rounded-lg border border-slate-100">
                                <span class="text-slate-400 block mb-0.5">Material (AISI)</span>
                                <strong class="text-slate-800 font-bold block">{{ $line->aisi ?? '-' }}</strong>
                            </div>
                        </div>

                        <!-- Divider -->
                        <div class="border-t border-slate-100 pt-4">
                            <span class="text-[10px] uppercase font-bold text-slate-500 tracking-wider block mb-3">Hasil Cetak & Ketersediaan Material</span>
                            
                            <div class="grid grid-cols-3 gap-4">
                                <div class="bg-slate-50 border border-slate-200 rounded-lg p-3 text-center">
                                    <span class="text-[9px] uppercase font-bold text-slate-400 block tracking-wider mb-0.5">Hasil Cetak Good</span>
                                    <strong class="text-slate-800 text-base font-bold">{{ number_format($line->qty_executed_good ?: ($line->qty_actual_good ?? 0)) }} <span class="text-[10px] font-normal text-slate-500">pcs</span></strong>
                                </div>
                                <div class="bg-slate-50 border border-slate-200 rounded-lg p-3 text-center">
                                    <span class="text-[9px] uppercase font-bold text-slate-400 block tracking-wider mb-0.5">Sudah Dirangkai</span>
                                    <strong class="text-emerald-700 text-base font-bold">{{ number_format($line->trees()->sum('quantity')) }} <span class="text-[10px] font-normal text-slate-500">pcs</span></strong>
                                </div>
                                <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-center">
                                    <span class="text-[9px] uppercase font-bold text-amber-600 block tracking-wider mb-0.5">Tersedia untuk Rangkai</span>
                                    <strong class="text-amber-800 text-base font-extrabold">{{ number_format($availableQty) }} <span class="text-[10px] font-bold text-amber-700">pcs</span></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. PARAMETER PERINTAH RANGKAI -->
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 space-y-5">
                    <h2 class="font-bold text-slate-800 text-xs mb-1 uppercase tracking-wide flex items-center gap-1.5 border-b border-slate-100 pb-2">
                        <i class="fas fa-cogs text-amber-600"></i> Detail Perintah Kerja Rangkai
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-xs font-semibold text-slate-800 mb-1.5">Quantity Diperintahkan (Pcs) <span class="text-red-500">*</span></label>
                            <input type="number" name="qty_ordered" id="qtyOrdered" 
                                value="{{ old('qty_ordered', $availableQty) }}" min="1" max="{{ $availableQty }}" required 
                                class="w-full rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500 shadow-sm">
                            <span class="text-[9px] text-slate-400 block mt-1.5">Tentukan jumlah pcs hasil cetak yang diperintahkan untuk dirangkai.</span>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-800 mb-1.5">Kapasitas Standar / Pedoman Tree (Pcs/Tree) <span class="text-red-500">*</span></label>
                            <input type="number" name="standard_capacity_guide" id="standardCapacityGuide" 
                                value="{{ old('standard_capacity_guide', $line->standard_tree_capacity ?? 20) }}" min="1" required 
                                class="w-full rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500 shadow-sm">
                            <span class="text-[9px] text-slate-400 block mt-1.5">Pedoman kapasitas per pohon. Pembagian aktual tree ditentukan saat operator melakukan proses rangkai.</span>
                        </div>
                    </div>

                    <!-- Persyaratan Proses (Checkbox Layer 7) -->
                    <div class="bg-amber-50/50 border border-amber-100 rounded-lg p-3 flex items-start gap-3">
                        <input type="checkbox" name="require_layer_7" id="requireLayer7" value="1" 
                            {{ old('require_layer_7', $line->require_layer_7) ? 'checked' : '' }}
                            class="rounded border-slate-300 text-amber-600 focus:ring-amber-500 mt-0.5">
                        <div>
                            <label for="requireLayer7" class="text-xs font-bold text-slate-800 select-none block">Wajib Layer 7 (Melalui coating lapisan ke-7)</label>
                            <span class="text-[10px] text-slate-500 block mt-0.5">Parameter proses kritis: menginstruksikan operator untuk melakukan coating hingga lapisan ke-7 sebelum proses oven.</span>
                        </div>
                    </div>

                    <!-- Catatan untuk SPV/Operator -->
                    <div>
                        <label class="block text-xs font-semibold text-slate-800 mb-1.5">Catatan/Instruksi Khusus untuk SPV & Operator</label>
                        <textarea name="notes" placeholder="Tulis instruksi pengerjaan jika ada..." rows="3" 
                            class="w-full rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500 shadow-sm">{{ old('notes') }}</textarea>
                    </div>
                </div>
            </div>

            <!-- Right 1 Column: Visual References & System Box & Submit Actions -->
            <div class="space-y-6">
                
                <!-- 3. REFERENSI VISUAL RANGKAI -->
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 space-y-4">
                    <div class="border-b border-slate-100 pb-2">
                        <h2 class="font-bold text-slate-800 text-xs uppercase tracking-wide">Referensi Visual Rangkai</h2>
                        <span class="text-[9px] text-slate-400 block mt-0.5">Referensi visual — akan digunakan pada traveler.</span>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="border-2 border-dashed border-slate-200 rounded-lg p-4 bg-slate-50/50 flex flex-col items-center justify-center text-center">
                            <i class="fas fa-camera text-slate-300 text-lg mb-1.5"></i>
                            <div class="text-[9px] font-bold text-slate-600">Tampak Depan</div>
                            <div class="text-[8px] text-slate-400 mt-0.5">Belum Ada Foto</div>
                        </div>
                        <div class="border-2 border-dashed border-slate-200 rounded-lg p-4 bg-slate-50/50 flex flex-col items-center justify-center text-center">
                            <i class="fas fa-camera text-slate-300 text-lg mb-1.5"></i>
                            <div class="text-[9px] font-bold text-slate-600">Tampak Samping</div>
                            <div class="text-[8px] text-slate-400 mt-0.5">Belum Ada Foto</div>
                        </div>
                    </div>
                </div>

                <!-- 4. ALUR PROSES INFO BOX -->
                <div class="bg-blue-50/60 border border-blue-200 rounded-xl p-4 text-xs text-blue-800 space-y-2.5">
                    <div class="flex items-center gap-1.5 font-bold">
                        <i class="fas fa-info-circle text-blue-600"></i>
                        Informasi Alur Produksi
                    </div>
                    <p class="text-blue-700 leading-relaxed text-[11px]">
                        Perintah ini <strong>tidak langsung membuat Tree fisik</strong> pada sistem.
                    </p>
                    <p class="text-blue-700 leading-relaxed text-[11px]">
                        Pohon lilin (Physical Tree) beserta pembagian kuantitas realisasinya akan dibuat secara dinamis saat operator/SPV mencatat <strong>Rangkai Execution</strong>.
                    </p>
                </div>

                <!-- 5. ACTION CARD -->
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 space-y-3">
                    <button type="submit" id="submitBtn" class="w-full bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold py-2.5 px-4 rounded-lg transition-all shadow-sm flex items-center justify-center gap-1.5">
                        <i class="fas fa-paper-plane"></i> Terbitkan Perintah Rangkai
                    </button>
                    <a href="{{ route('lost-wax.assemblies.index') }}" class="w-full bg-slate-50 hover:bg-slate-100 text-slate-700 text-xs font-semibold py-2 px-4 rounded-lg transition-all text-center block border border-slate-200">
                        Batal
                    </a>
                </div>
            </div>
        </div>
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const qtyInput = document.getElementById('qtyOrdered');
            const submitBtn = document.getElementById('submitBtn');
            const maxAvailable = {{ $availableQty }};

            qtyInput.addEventListener('input', function() {
                const val = parseInt(qtyInput.value) || 0;
                if (val > maxAvailable || val <= 0) {
                    submitBtn.disabled = true;
                    submitBtn.style.opacity = '0.5';
                } else {
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                }
            });
        });
    </script>
@endsection
