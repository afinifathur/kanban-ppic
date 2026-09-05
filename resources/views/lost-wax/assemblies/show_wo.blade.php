@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-xl font-bold text-slate-900 leading-tight">Detail Perintah Rangkai</h1>
            <p class="text-slate-500 text-[10px]">Pantau progres dan catat eksekusi perangkaian pohon lilin (Work Order)</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('lost-wax.assemblies.work-orders.print', $workOrder) }}" target="_blank" class="bg-amber-600 hover:bg-amber-700 text-white font-bold text-xs px-3 py-2 rounded-lg transition-all flex items-center gap-1.5 shadow-sm">
                <i class="fas fa-print"></i> Print A5
            </a>
            <a href="{{ route('lost-wax.assemblies.work-orders.index') }}" class="bg-white hover:bg-slate-50 text-slate-700 font-semibold text-xs px-3 py-2 rounded-lg transition-all flex items-center gap-1.5 border border-slate-200 shadow-sm">
                <i class="fas fa-arrow-left"></i> Kembali
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="space-y-6 max-w-6xl">
        
        <!-- HEADER / IDENTITAS DOKUMEN (BAGIAN B) -->
        <div class="bg-slate-900 text-white rounded-xl shadow-sm border border-slate-800 overflow-hidden p-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="space-y-1.5">
                <span class="text-[10px] uppercase font-bold text-amber-400 tracking-wider block">LOST WAX ASSEMBLY WORK ORDER</span>
                <!-- NAMA PRODUK UTAMA (LEBIH BESAR DARI NO WO) -->
                <h1 class="text-2xl md:text-3xl font-black text-white tracking-tight leading-tight">
                    {{ $productName ?? $line->item_name ?? 'PRODUK RANGKAI' }}
                </h1>
                <div class="flex flex-wrap items-center gap-2 md:gap-3 text-xs text-slate-300 pt-1 font-mono">
                    <span class="flex items-center gap-1.5">
                        <i class="fas fa-file-invoice text-amber-400"></i>
                        No. WO: <strong class="text-amber-300 font-bold">{{ $workOrder->rangkai_order_number }}</strong>
                    </span>
                    <span>&bull;</span>
                    <span>Kode: <strong class="text-white">{{ $line->code ?? '-' }}</strong></span>
                    @if($line->customer)
                        <span>&bull;</span>
                        <span>Customer: <strong class="text-white">{{ $line->customer }}</strong></span>
                    @endif
                </div>
            </div>
            <div class="shrink-0">
                <span class="px-4 py-1.5 rounded-full text-xs font-black uppercase border tracking-wider
                    {{ $workOrder->status === 'COMPLETED' ? 'bg-emerald-950 text-emerald-300 border-emerald-700' : ($workOrder->status === 'IN_PROGRESS' ? 'bg-amber-950 text-amber-300 border-amber-700' : 'bg-slate-800 text-slate-300 border-slate-700') }}">
                    STATUS: {{ $workOrder->status }}
                </span>
            </div>
        </div>

        <!-- PROGRESS SUMMARY (4 Cards) -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-[9px] uppercase font-bold text-slate-400 tracking-wider">Rencana Rangkai</div>
                <div class="font-extrabold text-lg text-slate-800 mt-1">
                    {{ number_format($workOrder->qty_planned_pcs) }} <span class="text-xs font-normal text-slate-500">pcs</span>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-[9px] uppercase font-bold text-slate-400 tracking-wider">Sudah Dirangkai (Aktif)</div>
                <div class="font-extrabold text-lg text-emerald-600 mt-1">
                    {{ number_format($workOrder->qty_executed_pcs) }} <span class="text-xs font-normal text-emerald-500">pcs</span>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-[9px] uppercase font-bold text-slate-400 tracking-wider">Outstanding WO</div>
                <div class="font-extrabold text-lg {{ $workOrder->qty_outstanding > 0 ? 'text-amber-700' : 'text-slate-500' }} mt-1">
                    {{ number_format($workOrder->qty_outstanding) }} <span class="text-xs font-normal text-slate-500">pcs</span>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-[9px] uppercase font-bold text-slate-400 tracking-wider">Tersedia Cetak</div>
                <div class="font-extrabold text-lg text-indigo-700 mt-1">
                    {{ number_format($line->qty_available_for_rangkai) }} <span class="text-xs font-normal text-indigo-500">pcs</span>
                </div>
            </div>
        </div>

        <!-- MAIN LAYOUT GRID -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- Sisi Kiri (2 Kolom): Formulir Eksekusi & Riwayat -->
            <div class="lg:col-span-2 space-y-6">
                
                @if($workOrder->qty_outstanding > 0)
                    <!-- CATAT EKSEKUSI RANGKAI -->
                    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 space-y-5">
                        <div class="border-b border-slate-100 pb-2">
                            <h2 class="font-bold text-slate-800 text-sm uppercase tracking-wide flex items-center gap-1.5">
                                <i class="fas fa-sitemap text-amber-600"></i> Catat Eksekusi Rangkai Fisik
                            </h2>
                            <p class="text-[10px] text-slate-400 mt-0.5">Sistem akan secara otomatis mendistribusikan kuantitas ke dalam tree fisik.</p>
                        </div>

                        <form method="POST" action="{{ route('lost-wax.assemblies.work-orders.execution.store', $workOrder) }}" id="executionForm" class="space-y-5">
                            @csrf
                            
                            <!-- Input Utama -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">Tanggal Eksekusi <span class="text-red-500">*</span></label>
                                    <input type="date" name="execution_date" value="{{ date('Y-m-d') }}" required
                                        class="w-full rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500 shadow-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">Moulding Family (Barcode prefix) <span class="text-red-500">*</span></label>
                                    <select name="family_code" required class="w-full rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500 shadow-sm">
                                        <option value="">-- Pilih Family --</option>
                                        @foreach($families as $code => $label)
                                            <option value="{{ $code }}" {{ $familyCode == $code ? 'selected' : '' }}>{{ $code }} - {{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 border-t border-slate-100 pt-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-800 mb-1">Qty yang Akan Dirangkai <span class="text-red-500">*</span></label>
                                    <input type="number" id="qtyExecutionInput" 
                                        value="{{ min($workOrder->qty_outstanding, $line->qty_available_for_rangkai) }}" 
                                        min="1" required
                                        class="w-full rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500 shadow-sm font-semibold">
                                    <span class="text-[9px] text-slate-400 block mt-1">Jumlah produk fisik yang akan mulai dirangkai hari ini.</span>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-800 mb-1">Pedoman Kapasitas Tree <span class="text-red-500">*</span></label>
                                    <input type="number" id="capacityGuideInput" 
                                        value="{{ $capacity }}" min="1" required
                                        class="w-full rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500 shadow-sm font-semibold">
                                    <span class="text-[9px] text-slate-400 block mt-1">Kapasitas maksimum lilin per pohon fisik (sebagai pedoman pembagian).</span>
                                </div>
                            </div>

                            <!-- ADDITIONAL PHYSICAL SOURCE SECTION (SHOWS IF QTY > AVAILABLE) -->
                            <div id="additionalSourceSection" class="hidden p-4 rounded-xl bg-amber-50/70 border border-amber-200 space-y-3">
                                <div class="flex items-start justify-between gap-2 border-b border-amber-200/60 pb-2">
                                    <div class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-lg bg-amber-200 text-amber-800 flex items-center justify-center font-bold text-xs">
                                            <i class="fas fa-plus"></i>
                                        </div>
                                        <div>
                                            <h3 class="text-xs font-bold text-amber-900">Sumber Lilin Fisik Tambahan (Additional Source)</h3>
                                            <p class="text-[10px] text-amber-800/90" id="additionalSourceDiffNotice">
                                                Kuantitas melebihi ketersediaan. Harap cantumkan sumber lilin fisik yang digunakan.
                                            </p>
                                        </div>
                                    </div>
                                    <button type="button" id="revertQtyBtn" class="text-[10px] font-bold text-amber-800 hover:text-amber-950 bg-amber-200/60 hover:bg-amber-200 px-2.5 py-1 rounded transition-colors">
                                        Sesuaikan ke Ketersediaan
                                    </button>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">
                                            Pilih Sumber Lilin (Print Order Line) <span class="text-red-500">*</span>
                                        </label>
                                        
                                        <!-- Hidden input for selected Print Order Line ID -->
                                        <input type="hidden" name="additional_source_line_id" id="additionalSourceLineIdInput" value="{{ old('additional_source_line_id') }}">

                                        <!-- Autocomplete Input with Dropdown -->
                                        <div class="relative" id="sourceAutocompleteContainer">
                                            <div class="relative flex items-center">
                                                <input type="text" id="sourceSearchInput" autocomplete="off"
                                                    placeholder="Ketik Production Code / Nama Produk / SPK..."
                                                    class="w-full rounded-lg border-amber-300 text-xs focus:border-amber-500 focus:ring-amber-500 shadow-sm bg-white pr-8 py-2">
                                                <button type="button" id="clearSourceBtn" title="Hapus Pilihan"
                                                    class="hidden absolute right-2 text-slate-400 hover:text-slate-600 focus:outline-none p-1 text-xs transition-colors">
                                                    <i class="fas fa-times-circle"></i>
                                                </button>
                                            </div>

                                            <!-- Suggestions Dropdown List -->
                                            <div id="sourceSuggestionsList" 
                                                class="hidden absolute z-50 left-0 right-0 top-full mt-1 bg-white border border-slate-200 rounded-xl shadow-2xl max-h-56 overflow-y-auto divide-y divide-slate-100 text-xs">
                                                <!-- Dynamic suggestions rendered by JS -->
                                            </div>
                                        </div>

                                        <!-- Selected Source Summary Card -->
                                        <div id="selectedSourceCard" class="hidden mt-2 p-2.5 rounded-lg bg-amber-50/80 border border-amber-200 text-xs space-y-1">
                                            <div class="flex items-center justify-between">
                                                <span class="font-bold text-slate-900 truncate pr-2" id="selectedSourceTitle">-</span>
                                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800 shrink-0" id="selectedSourceBadge">Tersedia: 0 pcs</span>
                                            </div>
                                            <div class="text-[11px] text-slate-500 flex items-center justify-between">
                                                <span id="selectedSourceSpk">SPK: -</span>
                                                <span class="text-amber-800 font-semibold" id="selectedSourceUsage">Tambahan: 0 pcs</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">
                                            Kuantitas Tambahan (pcs) <span class="text-red-500">*</span>
                                        </label>
                                        <input type="number" name="additional_source_qty" id="additionalSourceQtyInput" value="{{ old('additional_source_qty', 0) }}" readonly
                                            class="w-full rounded-lg border-amber-300 bg-amber-100/50 text-xs font-bold text-slate-800 shadow-sm py-2">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">
                                        Alasan Penambahan Lilin Fisik <span class="text-red-500">*</span>
                                    </label>
                                    <textarea name="additional_source_reason" id="additionalSourceReasonInput" rows="2"
                                        placeholder="Contoh: Menggunakan sisa lilin sehat dari rangkaian produksi sebelumnya..."
                                        class="w-full rounded-lg border-amber-300 text-xs focus:border-amber-500 focus:ring-amber-500 shadow-sm bg-white"></textarea>
                                    <span class="text-[9px] text-amber-800 block mt-0.5">Alasan penambahan wajib diisi untuk rekam jejak audit (traceability).</span>
                                </div>
                            </div>

                            <!-- Automatic calculation results -->
                            <div class="bg-slate-50 border border-slate-200 rounded-lg p-4 space-y-4">
                                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                                    <span class="text-xs font-bold text-slate-700">Hasil Perhitungan Tree:</span>
                                    <div class="flex items-center gap-2">
                                        <span class="text-[10px] text-slate-500 uppercase font-semibold">Jumlah Tree Otomatis:</span>
                                        <strong id="autoTreeCountLabel" class="bg-amber-100 text-amber-800 text-xs px-2 py-0.5 rounded-md font-bold">0 Tree</strong>
                                    </div>
                                </div>

                                <!-- Hidden count of trees to submit to the backend controller -->
                                <input type="hidden" name="trees_created" id="treesCreatedInput" value="0">

                                <!-- Tree rows container -->
                                <div id="treesContainer" class="space-y-2">
                                    <!-- Dynamic rows will be inserted here by JS -->
                                </div>

                                <!-- Validation message -->
                                <div id="validationWarning" class="hidden p-2.5 bg-red-50 border border-red-200 text-red-800 rounded-lg text-xs font-semibold">
                                    <!-- Dynamic validation text -->
                                </div>

                                <div class="p-3 bg-white border border-slate-200 rounded-lg text-xs text-slate-650 flex flex-col sm:flex-row sm:justify-between gap-2">
                                    <div>Total Eksekusi Rangkai: <strong id="totalQty" class="text-slate-900 text-sm font-bold">0</strong> pcs</div>
                                    <div>Ketersediaan Normal WO: <strong class="text-slate-800">{{ number_format(min($workOrder->qty_outstanding, $line->qty_available_for_rangkai)) }}</strong> pcs</div>
                                </div>
                            </div>

                            <div class="flex justify-end pt-2">
                                <button type="submit" id="submitBtn" class="bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold py-2.5 px-6 rounded-lg transition-all shadow-sm flex items-center gap-1.5">
                                    <i class="fas fa-check-circle"></i> Simpan Eksekusi &amp; Terbitkan Traveler
                                </button>
                            </div>
                        </form>
                    </div>
                @else
                    <!-- WORK ORDER COMPLETED NOTICE -->
                    <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-5 text-emerald-800 flex items-center gap-3">
                        <i class="fas fa-check-circle text-2xl text-emerald-600"></i>
                        <div>
                            <h3 class="font-bold">Work Order Rangkai Selesai</h3>
                            <p class="text-xs text-emerald-700 mt-0.5">Seluruh rencana perangkaian pohon lilin untuk perintah cetak ini telah diselesaikan secara penuh.</p>
                        </div>
                    </div>
                @endif

                <!-- RIWAYAT EKSEKUSI (BAGIAN E & K) -->
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                        <h2 class="font-bold text-slate-800 text-xs uppercase tracking-wide">
                            Riwayat Eksekusi Rangkai (Chronological)
                        </h2>
                        <span class="text-[10px] text-slate-400">Total: {{ $workOrder->executions->count() }} Eksekusi</span>
                    </div>

                    <div class="space-y-4">
                        @forelse($workOrder->executions->sortBy('created_at') as $exec)
                            <div class="p-4 rounded-xl border {{ $exec->is_cancelled ? 'border-red-200 bg-red-50/40 opacity-80' : 'border-slate-200 bg-slate-50' }} flex flex-col gap-3 transition-all">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs text-slate-500 border-b {{ $exec->is_cancelled ? 'border-red-100' : 'border-slate-200' }} pb-2">
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold {{ $exec->is_cancelled ? 'text-red-800' : 'text-slate-800' }}">Eksekusi Rangkai #{{ $loop->iteration }}</span>
                                        <span>&bull;</span>
                                        <span>Tanggal: <strong class="text-slate-700">{{ $exec->execution_date->format('d-m-Y') }}</strong></span>
                                    </div>
                                    
                                    <!-- UI STATUS BADGES & CANCEL ACTION (BAGIAN K) -->
                                    <div class="flex items-center gap-2">
                                        @if($exec->is_cancelled)
                                            <span class="px-2.5 py-1 rounded text-[10px] font-extrabold bg-red-100 text-red-700 border border-red-300 uppercase tracking-wide flex items-center gap-1 shadow-2xs">
                                                <i class="fas fa-ban"></i> Traveler Dibatalkan
                                            </span>
                                        @elseif($exec->is_scanned)
                                            <span class="px-2.5 py-1 rounded text-[10px] font-extrabold bg-emerald-100 text-emerald-800 border border-emerald-300 uppercase tracking-wide flex items-center gap-1 shadow-2xs">
                                                <i class="fas fa-check-double"></i> Traveler Sudah Diproses (Layer 1)
                                            </span>
                                        @else
                                            <span class="px-2.5 py-1 rounded text-[10px] font-extrabold bg-blue-100 text-blue-800 border border-blue-300 uppercase tracking-wide flex items-center gap-1 shadow-2xs">
                                                <i class="fas fa-file-invoice"></i> Traveler Sudah Terbit
                                            </span>
                                            <button type="button" 
                                                onclick="openCancelModal({{ $exec->id }}, '{{ $exec->execution_date->format('d-m-Y') }}', {{ $exec->trees_created }}, {{ $exec->trees->sum('quantity') }})"
                                                class="bg-white hover:bg-red-50 text-red-600 hover:text-red-700 border border-red-200 text-[10px] font-bold px-2.5 py-1 rounded-md transition-colors flex items-center gap-1 shadow-2xs">
                                                <i class="fas fa-times-circle"></i> Batalkan Traveler
                                            </button>
                                        @endif
                                    </div>
                                </div>

                                @if($exec->is_cancelled)
                                    <!-- AUDIT INFO DIBATALKAN -->
                                    <div class="p-2.5 rounded-lg bg-red-100/70 border border-red-200 text-xs text-red-800 space-y-1">
                                        <div class="flex items-center gap-1.5 font-bold">
                                            <i class="fas fa-info-circle"></i> Informasi Pembatalan:
                                        </div>
                                        <div class="text-[11px] leading-relaxed">
                                            Dibatalkan oleh: <strong>{{ $exec->canceller?->name ?? 'User' }}</strong> 
                                            pada <strong>{{ $exec->cancelled_at ? $exec->cancelled_at->format('d-m-Y H:i') : '-' }}</strong>
                                        </div>
                                        <div class="text-[11px] italic bg-white/70 p-1.5 rounded border border-red-200/50">
                                            "{{ $exec->cancellation_reason }}"
                                        </div>
                                    </div>
                                @endif

                                @if($exec->additional_source_qty > 0)
                                    <!-- ADDITIONAL SOURCE AUDIT INFO -->
                                    <div class="p-2.5 rounded-lg bg-amber-50 border border-amber-200 text-xs text-amber-900 flex items-start gap-2">
                                        <i class="fas fa-plus-circle text-amber-600 mt-0.5"></i>
                                        <div class="space-y-0.5">
                                            <span class="font-bold">Termasuk Sumber Lilin Tambahan (+{{ $exec->additional_source_qty }} pcs):</span>
                                            <div class="text-[11px] text-slate-700">
                                                Kode Produksi: <strong>{{ $exec->additional_source_code }}</strong>
                                                @if($exec->additionalSourceLine?->printOrder)
                                                    (No. SPK: <strong>{{ $exec->additionalSourceLine->printOrder->print_order_number }}</strong>)
                                                @endif
                                            </div>
                                            @if($exec->additional_source_reason)
                                                <div class="text-[11px] italic text-slate-600">"{{ $exec->additional_source_reason }}"</div>
                                            @endif
                                        </div>
                                    </div>
                                @endif

                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-xs">
                                    <div>
                                        <span class="text-slate-400 block mb-0.5">Pohon Dibuat</span>
                                        <strong class="{{ $exec->is_cancelled ? 'text-slate-400 line-through' : 'text-slate-700' }} text-sm">{{ $exec->trees_created }} Tree</strong>
                                    </div>
                                    <div>
                                        <span class="text-slate-400 block mb-0.5">Total Pcs Good</span>
                                        <strong class="{{ $exec->is_cancelled ? 'text-slate-400 line-through' : 'text-slate-700' }} text-sm">{{ number_format($exec->trees->sum('quantity')) }} pcs</strong>
                                    </div>
                                    <div>
                                        <span class="text-slate-400 block mb-0.5">Dicatat Oleh</span>
                                        <strong class="text-slate-700 text-sm">{{ $exec->recorder?->name ?? 'System' }}</strong>
                                    </div>
                                    <div>
                                        <span class="text-slate-400 block mb-0.5">Recorded At</span>
                                        <strong class="text-slate-700 text-sm">{{ $exec->created_at->format('d-m-Y H:i') }}</strong>
                                    </div>
                                </div>

                                <div class="mt-2 border-t {{ $exec->is_cancelled ? 'border-red-100' : 'border-slate-200' }} pt-2.5">
                                    <div class="text-[9px] font-bold text-slate-400 mb-1.5 uppercase tracking-wide">
                                        Pohon Fisik (LostWaxTree) {{ $exec->is_cancelled ? 'Non-Aktif / Dibatalkan' : 'Terbentuk' }}:
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($exec->trees as $tree)
                                            @if($exec->is_cancelled)
                                                <span class="inline-flex items-center gap-1.5 bg-slate-100 border border-slate-200 text-slate-400 line-through text-[10px] font-medium px-2 py-1 rounded">
                                                    <i class="fas fa-barcode"></i>
                                                    {{ $tree->barcode }} ({{ $tree->quantity }} pcs)
                                                </span>
                                            @else
                                                <a href="{{ route('lost-wax.trees.show', $tree) }}" class="inline-flex items-center gap-1.5 bg-white border border-slate-200 hover:border-amber-400 text-slate-700 hover:text-amber-800 text-[10px] font-bold px-2 py-1 rounded transition-all shadow-sm">
                                                    <i class="fas fa-barcode text-slate-400"></i>
                                                    {{ $tree->barcode }} ({{ $tree->quantity }} pcs)
                                                </a>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="p-8 text-center text-slate-400 italic text-xs">
                                <i class="fas fa-info-circle text-2xl mb-2 block text-slate-350"></i>
                                Belum ada eksekusi rangkai yang tercatat. Gunakan formulir di atas untuk mencatat.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            <!-- Sisi Kanan (1 Kolom): Traceability & Visual References -->
            <div class="space-y-6">
                
                <!-- DETAIL INFORMASI WORK ORDER -->
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 space-y-4">
                    <h2 class="font-bold text-slate-800 text-xs uppercase tracking-wide border-b border-slate-100 pb-2">
                        Informasi Detail Work Order
                    </h2>
                    
                    <div class="space-y-3 text-xs">
                        <div class="flex justify-between border-b border-slate-50 pb-1.5">
                            <span class="text-slate-500">No WO Rangkai:</span>
                            <span class="font-bold text-slate-800 font-mono">{{ $workOrder->rangkai_order_number }}</span>
                        </div>
                        <div class="flex justify-between border-b border-slate-50 pb-1.5">
                            <span class="text-slate-500">Kode Produksi:</span>
                            <span class="font-bold text-slate-800">{{ $line->code ?? '-' }}</span>
                        </div>
                        <div class="flex justify-between border-b border-slate-50 pb-1.5">
                            <span class="text-slate-500">No Perintah Cetak:</span>
                            <a href="{{ route('lost-wax.print-orders.show', $line->lost_wax_print_order_id) }}" class="font-bold text-amber-600 hover:underline font-mono">
                                {{ $line->printOrder->print_order_number }}
                            </a>
                        </div>
                        <div class="flex justify-between border-b border-slate-50 pb-1.5">
                            <span class="text-slate-500">Customer:</span>
                            <span class="font-bold text-slate-800">{{ $line->customer ?? '-' }}</span>
                        </div>
                        <div class="flex justify-between border-b border-slate-50 pb-1.5">
                            <span class="text-slate-500">AISI / Material:</span>
                            <span class="font-bold text-slate-800">{{ $line->aisi ?? '-' }}</span>
                        </div>
                        <div class="flex justify-between border-b border-slate-50 pb-1.5">
                            <span class="text-slate-500">Ukuran (Size):</span>
                            <span class="font-bold text-slate-800">{{ $line->size ?? '-' }}</span>
                        </div>
                        <div class="flex justify-between border-b border-slate-50 pb-1.5">
                            <span class="text-slate-500">Wajib Layer 7:</span>
                            <span class="font-bold px-2 py-0.5 rounded text-[9px] {{ $workOrder->require_layer_7 ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-800' }}">
                                {{ $workOrder->require_layer_7 ? 'YA' : 'TIDAK (SKIP)' }}
                            </span>
                        </div>
                        <div class="flex justify-between border-b border-slate-50 pb-1.5">
                            <span class="text-slate-500">Kapasitas Tree:</span>
                            @if($workOrder->tree_capacity === 1)
                                <span class="font-bold text-slate-800">{{ $capacity }} pcs / tree <span class="text-[9px] text-slate-400 font-normal">(Pedoman)</span></span>
                            @else
                                <span class="font-bold text-slate-800">{{ $workOrder->tree_capacity }} pcs / tree</span>
                            @endif
                        </div>
                        <div class="flex justify-between border-b border-slate-50 pb-1.5 items-center">
                            <span class="text-slate-500">Saldo Tersedia Line:</span>
                            <div class="flex items-center gap-1.5">
                                <span class="font-bold text-slate-800">{{ $line->qty_available_for_rangkai }} pcs</span>
                                @if($line->qty_available_for_rangkai > 0)
                                    <button type="button" onclick="openScrapModal({{ $line->id }}, '{{ $line->code }}', {{ $line->qty_available_for_rangkai }})" 
                                        class="px-2 py-0.5 rounded bg-red-50 hover:bg-red-100 text-red-700 border border-red-200 text-[10px] font-bold transition-colors">
                                        <i class="fas fa-trash-alt mr-0.5"></i> Afkir Sisa
                                    </button>
                                @endif
                            </div>
                        </div>
                        @if($line->qty_excess_closed > 0)
                            <div class="p-2.5 rounded-lg bg-red-50/60 border border-red-200 text-[11px] text-red-800 space-y-1">
                                <div class="font-bold flex items-center gap-1 text-red-900">
                                    <i class="fas fa-ban"></i> Sisa Lilin Diafkir: {{ $line->qty_excess_closed }} pcs
                                </div>
                                <div class="text-[10px] text-red-700">
                                    Alasan: {{ $line->excess_closure_reason ?? '-' }}
                                    @if($line->excess_closed_at)
                                        <br>Ditutup: {{ $line->excess_closed_at->format('d/m/Y H:i') }} ({{ $line->excessCloser?->name ?? 'User' }})
                                    @endif
                                </div>
                            </div>
                        @endif
                        <div>
                            <span class="text-slate-500 block mb-1">Catatan WO:</span>
                            <p class="p-2.5 rounded bg-slate-50 border border-slate-100 text-slate-650 italic text-[11px]">
                                {{ $workOrder->notes ?? 'Tidak ada catatan.' }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- REFERENSI VISUAL RANGKAI (AUTO PHOTO ON TRAVELER & WO DETAIL) -->
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                        <h2 class="font-bold text-slate-800 text-xs uppercase tracking-wide">
                            Referensi Visual Rangkai
                        </h2>
                        @if($assemblyPhoto)
                            <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-emerald-100 text-emerald-800">Master Foto Aktif</span>
                        @else
                            <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-slate-100 text-slate-500">Placeholder</span>
                        @endif
                    </div>
                    
                    <div class="space-y-4">
                        <!-- Foto Depan -->
                        <div>
                            <div class="text-[10px] font-bold text-slate-600 mb-1 flex items-center gap-1">
                                <i class="fas fa-camera text-slate-400"></i> Foto Tampak Depan
                            </div>
                            @if($assemblyPhoto && $assemblyPhoto->front_photo_url)
                                <div class="rounded-lg border border-slate-200 overflow-hidden bg-slate-100 flex items-center justify-center">
                                    <img src="{{ $assemblyPhoto->front_photo_url }}" alt="Foto Tampak Depan" class="w-full h-44 object-contain">
                                </div>
                            @else
                                <div class="border-2 border-dashed border-slate-200 rounded-lg p-6 bg-slate-50/50 flex flex-col items-center justify-center text-center">
                                    <i class="fas fa-camera text-slate-300 text-xl mb-1.5"></i>
                                    <div class="text-[10px] font-semibold text-slate-500">Belum Ada Foto Depan</div>
                                    <div class="text-[8px] text-slate-400 mt-0.5">Kelola di Setting &rarr; Foto Rangkai</div>
                                </div>
                            @endif
                        </div>

                        <!-- Foto Samping -->
                        <div>
                            <div class="text-[10px] font-bold text-slate-600 mb-1 flex items-center gap-1">
                                <i class="fas fa-camera text-slate-400"></i> Foto Tampak Samping
                            </div>
                            @if($assemblyPhoto && $assemblyPhoto->side_photo_url)
                                <div class="rounded-lg border border-slate-200 overflow-hidden bg-slate-100 flex items-center justify-center">
                                    <img src="{{ $assemblyPhoto->side_photo_url }}" alt="Foto Tampak Samping" class="w-full h-44 object-contain">
                                </div>
                            @else
                                <div class="border-2 border-dashed border-slate-200 rounded-lg p-6 bg-slate-50/50 flex flex-col items-center justify-center text-center">
                                    <i class="fas fa-camera text-slate-300 text-xl mb-1.5"></i>
                                    <div class="text-[10px] font-semibold text-slate-500">Belum Ada Foto Samping</div>
                                    <div class="text-[8px] text-slate-400 mt-0.5">Kelola di Setting &rarr; Foto Rangkai</div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if($workOrder->qty_outstanding > 0)
        <!-- CONFIRMATION MODAL TERBITKAN TRAVELER (BAGIAN C) -->
        <div id="confirmTravelerModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-slate-200 transform transition-all">
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-amber-100 text-amber-700 flex items-center justify-center shrink-0">
                        <i class="fas fa-clipboard-check text-xl"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <span class="text-[10px] font-extrabold uppercase text-amber-700 tracking-wider block">Konfirmasi Perangkaian</span>
                        <h3 class="text-base font-black text-slate-900 leading-snug" id="modal-title">
                            KONFIRMASI TERBITKAN TRAVELER
                        </h3>
                        <p class="text-xs font-bold text-slate-700 mt-0.5 truncate">
                            {{ $productName ?? $line->item_name }}
                        </p>
                        <p class="text-[11px] font-mono text-amber-600 font-bold">
                            {{ $workOrder->rangkai_order_number }}
                        </p>
                    </div>
                </div>

                <!-- DETAIL AKTUAL EKSEKUSI (MENCEGAH HUMAN ERROR TERTUKAR TREE vs PCS) -->
                <div class="mt-5 bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-3">
                    <div class="grid grid-cols-2 gap-3 text-center">
                        <div class="bg-white border border-slate-200 rounded-lg p-3 shadow-2xs">
                            <span class="text-[9px] uppercase font-bold text-slate-400 tracking-wider block mb-1">JUMLAH RANGKAIAN</span>
                            <div class="text-xl font-black text-slate-900">
                                <span id="modalTreesCount">0</span> <span class="text-xs font-semibold text-slate-500">tree</span>
                            </div>
                        </div>
                        <div class="bg-white border border-slate-200 rounded-lg p-3 shadow-2xs">
                            <span class="text-[9px] uppercase font-bold text-slate-400 tracking-wider block mb-1">ISI SETIAP RANGKAIAN</span>
                            <div class="text-xl font-black text-slate-900">
                                <span id="modalTreeCapacity">0</span> <span class="text-xs font-semibold text-slate-500">pcs/tree</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-amber-50/80 border border-amber-200/80 rounded-lg p-3 text-center">
                        <span class="text-[9px] uppercase font-bold text-amber-800 tracking-wider block mb-0.5">TOTAL HASIL RANGKAI</span>
                        <div class="text-2xl font-black text-amber-700">
                            <span id="modalTotalPcs">0</span> <span class="text-sm font-bold text-amber-800">pcs</span>
                        </div>
                        <div id="modalBreakdownNote" class="text-[10px] text-amber-700/90 font-medium mt-1"></div>
                    </div>
                </div>

                <div class="mt-4 p-3 bg-red-50 border border-red-200 rounded-xl text-xs text-red-800 space-y-1">
                    <p class="font-bold text-slate-800 text-xs">Apakah data eksekusi di atas sudah benar?</p>
                    <p class="text-[11px] text-red-700 leading-relaxed">
                        <strong>Warning:</strong> Setelah Traveler diterbitkan, dokumen akan menjadi hasil eksekusi fisik dan tidak dapat diterbitkan secara tidak sengaja.
                    </p>
                </div>

                <div class="mt-6 flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                    <button type="button" id="cancelConfirmBtn" class="px-4 py-2.5 text-xs font-bold text-slate-600 hover:text-slate-800 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-slate-300">
                        Batal
                    </button>
                    <button type="button" id="proceedConfirmBtn" class="px-5 py-2.5 text-xs font-black text-white bg-amber-600 hover:bg-amber-700 rounded-lg transition-colors shadow-sm flex items-center gap-1.5 focus:outline-none focus:ring-2 focus:ring-amber-500">
                        <i class="fas fa-check"></i> Ya, Terbitkan Traveler
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- MODAL BATALKAN TRAVELER (BAGIAN E) -->
    <div id="cancelTravelerModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4" aria-labelledby="cancel-modal-title" role="dialog" aria-modal="true">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl border border-slate-200 transform transition-all">
            <form id="cancelTravelerForm" method="POST" action="">
                @csrf
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-red-100 text-red-700 flex items-center justify-center shrink-0">
                        <i class="fas fa-exclamation-circle text-xl"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <span class="text-[10px] font-extrabold uppercase text-red-700 tracking-wider block">Pembatalan Traveler</span>
                        <h3 class="text-base font-black text-slate-900 leading-snug" id="cancel-modal-title">
                            BATALKAN TRAVELER?
                        </h3>
                        <p class="text-xs font-bold text-slate-700 mt-0.5 truncate">
                            {{ $productName ?? $line->item_name }}
                        </p>
                        <p class="text-[11px] font-mono text-amber-600 font-bold">
                            {{ $workOrder->rangkai_order_number }}
                        </p>
                    </div>
                </div>

                <div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-900">
                    <div class="font-bold flex items-center gap-1.5 mb-1 text-amber-900">
                        <i class="fas fa-info-circle"></i> Info Boundary:
                    </div>
                    <p class="text-[11px] leading-relaxed">
                        Traveler ini <strong>belum melakukan Scan Layer 1</strong> dan masih dapat dibatalkan. Seluruh rangkaian (tree) terkait akan dinonaktifkan dan kuantitas akan dikembalikan ke outstanding.
                    </p>
                </div>

                <div class="mt-4 space-y-2">
                    <label class="block text-xs font-bold text-slate-800">
                        Alasan Pembatalan <span class="text-red-500">*</span>
                    </label>
                    <textarea name="cancellation_reason" id="cancellationReasonInput" rows="3" required
                        placeholder="Contoh: Salah jumlah rangkaian / Salah isi per rangkaian / Salah setting..."
                        class="w-full rounded-lg border-slate-300 text-xs focus:border-red-500 focus:ring-red-500 shadow-sm leading-relaxed"></textarea>
                    <span class="text-[10px] text-slate-400 block">Alasan pembatalan wajib diisi sebagai audit trail.</span>
                </div>

                <div class="mt-6 flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                    <button type="button" onclick="closeCancelModal()" class="px-4 py-2.5 text-xs font-bold text-slate-600 hover:text-slate-800 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
                        Kembali
                    </button>
                    <button type="submit" id="submitCancelBtn" class="px-5 py-2.5 text-xs font-black text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors shadow-sm flex items-center gap-1.5">
                        <i class="fas fa-trash-alt"></i> Ya, Batalkan Traveler
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL AFKIR SISA LILIN (SCRAP / CLOSE EXCESS) -->
    <div id="scrapModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4" aria-labelledby="scrap-modal-title" role="dialog" aria-modal="true">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl border border-slate-200 transform transition-all">
            <form id="scrapForm" method="POST" action="">
                @csrf
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-red-100 text-red-700 flex items-center justify-center shrink-0">
                        <i class="fas fa-trash-alt text-xl"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <span class="text-[10px] font-extrabold uppercase text-red-700 tracking-wider block">Afkir / Tutup Sisa Lilin</span>
                        <h3 class="text-base font-black text-slate-900 leading-snug" id="scrap-modal-title">
                            AFKIR SISA LILIN CETAK?
                        </h3>
                        <p class="text-xs font-bold text-slate-700 mt-0.5" id="scrapItemInfo">
                            {{ $line->code ?? '-' }} &bull; {{ $productName ?? $line->item_name }}
                        </p>
                    </div>
                </div>

                <div class="mt-4 p-3 bg-red-50 border border-red-200 rounded-xl text-xs text-red-900">
                    <div class="font-bold flex items-center gap-1.5 mb-1 text-red-900">
                        <i class="fas fa-exclamation-triangle"></i> Perhatian:
                    </div>
                    <p class="text-[11px] leading-relaxed">
                        Kuantitas lilin yang diafkir akan <strong>ditutup secara permanen</strong> dari saldo tersedia sistem, tidak dapat digunakan untuk assembly/sumber tambahan, dan dicatat dalam audit trail.
                    </p>
                </div>

                <div class="mt-4 space-y-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-800 mb-1">
                            Jumlah Lilin yang Diafkir (pcs) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="qty_to_close" id="scrapQtyInput" min="1" required
                            class="w-full rounded-lg border-slate-300 text-xs focus:border-red-500 focus:ring-red-500 shadow-sm font-bold">
                        <span class="text-[10px] text-slate-400 block mt-0.5" id="scrapMaxHint">Maksimal: 0 pcs</span>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-800 mb-1">
                            Alasan Afkir / Scrap <span class="text-red-500">*</span>
                        </label>
                        <textarea name="excess_closure_reason" id="scrapReasonInput" rows="3" required
                            placeholder="Contoh: Lilin cacat/patah, pattern rusak, disposal sisa cetak..."
                            class="w-full rounded-lg border-slate-300 text-xs focus:border-red-500 focus:ring-red-500 shadow-sm leading-relaxed"></textarea>
                        <span class="text-[10px] text-slate-400 block">Alasan afkir wajib diisi untuk rekam jejak audit.</span>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                    <button type="button" onclick="closeScrapModal()" class="px-4 py-2.5 text-xs font-bold text-slate-600 hover:text-slate-800 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="px-5 py-2.5 text-xs font-black text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors shadow-sm flex items-center gap-1.5">
                        <i class="fas fa-ban"></i> Konfirmasi Afkir Sisa
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- SCRIPT REAL-TIME CALCULATION, CONFIRMATION MODAL & CANCELLATION MODAL -->
    <script>
        // Global helper for opening cancellation modal
        function openCancelModal(executionId, executionDate, treesCount, totalPcs) {
            const modal = document.getElementById('cancelTravelerModal');
            const form = document.getElementById('cancelTravelerForm');
            const reasonInput = document.getElementById('cancellationReasonInput');
            
            form.action = `/lost-wax/assemblies/executions/${executionId}/cancel`;
            reasonInput.value = '';
            
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => reasonInput.focus(), 50);
        }

        function closeCancelModal() {
            const modal = document.getElementById('cancelTravelerModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        // Global helper for opening scrap modal
        function openScrapModal(lineId, lineCode, maxAvailable) {
            const modal = document.getElementById('scrapModal');
            const form = document.getElementById('scrapForm');
            const qtyInput = document.getElementById('scrapQtyInput');
            const reasonInput = document.getElementById('scrapReasonInput');
            const maxHint = document.getElementById('scrapMaxHint');

            form.action = `/lost-wax/assemblies/lines/${lineId}/close-excess`;
            qtyInput.max = maxAvailable;
            qtyInput.value = maxAvailable;
            maxHint.textContent = `Maksimal tersedia: ${maxAvailable} pcs`;
            reasonInput.value = '';

            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => reasonInput.focus(), 50);
        }

        function closeScrapModal() {
            const modal = document.getElementById('scrapModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        document.addEventListener('DOMContentLoaded', function () {
            const cancelModal = document.getElementById('cancelTravelerModal');
            if (cancelModal) {
                cancelModal.addEventListener('click', function (e) {
                    if (e.target === cancelModal) {
                        closeCancelModal();
                    }
                });
            }

            @if($workOrder->qty_outstanding > 0)
                const qtyInput = document.getElementById('qtyExecutionInput');
                const capacityInput = document.getElementById('capacityGuideInput');
                const treesCountInput = document.getElementById('treesCreatedInput');
                const container = document.getElementById('treesContainer');
                const totalQtyEl = document.getElementById('totalQty');
                const submitBtn = document.getElementById('submitBtn');
                const validationWarning = document.getElementById('validationWarning');
                const form = document.getElementById('executionForm');
                const modal = document.getElementById('confirmTravelerModal');
                const cancelBtn = document.getElementById('cancelConfirmBtn');
                const proceedBtn = document.getElementById('proceedConfirmBtn');

                const modalTreesCount = document.getElementById('modalTreesCount');
                const modalTreeCapacity = document.getElementById('modalTreeCapacity');
                const modalTotalPcs = document.getElementById('modalTotalPcs');
                const modalBreakdownNote = document.getElementById('modalBreakdownNote');

                const additionalSourceSection = document.getElementById('additionalSourceSection');
                const additionalSourceDiffNotice = document.getElementById('additionalSourceDiffNotice');
                const revertQtyBtn = document.getElementById('revertQtyBtn');
                
                // Autocomplete Elements
                const additionalSourceLineIdInput = document.getElementById('additionalSourceLineIdInput');
                const sourceSearchInput = document.getElementById('sourceSearchInput');
                const clearSourceBtn = document.getElementById('clearSourceBtn');
                const sourceSuggestionsList = document.getElementById('sourceSuggestionsList');
                const selectedSourceCard = document.getElementById('selectedSourceCard');
                const selectedSourceTitle = document.getElementById('selectedSourceTitle');
                const selectedSourceBadge = document.getElementById('selectedSourceBadge');
                const selectedSourceSpk = document.getElementById('selectedSourceSpk');
                const selectedSourceUsage = document.getElementById('selectedSourceUsage');
                const sourceAutocompleteContainer = document.getElementById('sourceAutocompleteContainer');

                const additionalSourceQtyInput = document.getElementById('additionalSourceQtyInput');
                const additionalSourceReasonInput = document.getElementById('additionalSourceReasonInput');

                @php
                    $sourceLinesJson = [];
                    if (isset($availableSourceLines)) {
                        foreach ($availableSourceLines as $s) {
                            $sourceLinesJson[] = [
                                'id' => (int) $s->id,
                                'code' => (string) ($s->code ?? ''),
                                'item_name' => (string) ($s->item_name ?? ''),
                                'spk' => (string) ($s->printOrder?->print_order_number ?? '-'),
                                'avail' => (int) $s->qty_available_for_rangkai,
                            ];
                        }
                    }
                @endphp
                const availableSourceLinesData = {!! json_encode($sourceLinesJson) !!};

                let selectedSourceItem = null;
                let activeSuggestionIndex = -1;
                let confirmedSubmit = false;
                
                const outstanding = {{ $workOrder->qty_outstanding }};
                const available = {{ $line->qty_available_for_rangkai }};
                const maxAvailable = Math.min(outstanding, available);

                function escapeHtml(text) {
                    const div = document.createElement('div');
                    div.textContent = text;
                    return div.innerHTML;
                }

                function renderSuggestions(query) {
                    if (!sourceSuggestionsList) return;
                    const q = (query || '').trim().toLowerCase();
                    if (!q) {
                        sourceSuggestionsList.innerHTML = '';
                        sourceSuggestionsList.classList.add('hidden');
                        activeSuggestionIndex = -1;
                        return;
                    }

                    const matches = availableSourceLinesData.filter(item => {
                        return item.code.toLowerCase().includes(q) ||
                               item.item_name.toLowerCase().includes(q) ||
                               item.spk.toLowerCase().includes(q);
                    }).slice(0, 10);

                    if (matches.length === 0) {
                        sourceSuggestionsList.innerHTML = `
                            <div class="p-3 text-center text-slate-400 italic text-[11px]">
                                <i class="fas fa-search mb-1 block text-slate-300"></i>
                                Tidak ditemukan sumber lilin yang cocok untuk "${escapeHtml(query)}".
                            </div>
                        `;
                        sourceSuggestionsList.classList.remove('hidden');
                        activeSuggestionIndex = -1;
                        return;
                    }

                    let html = '';
                    matches.forEach((item, idx) => {
                        html += `
                            <div class="suggestion-item p-2.5 hover:bg-amber-50 cursor-pointer transition-colors" data-id="${item.id}" data-index="${idx}">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="font-bold text-slate-800 truncate">${escapeHtml(item.code)} &mdash; <span class="font-medium text-slate-650">${escapeHtml(item.item_name)}</span></div>
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800 shrink-0">Tersedia: ${item.avail} pcs</span>
                                </div>
                                <div class="text-[10px] text-slate-400 mt-0.5">SPK: ${escapeHtml(item.spk)}</div>
                            </div>
                        `;
                    });

                    sourceSuggestionsList.innerHTML = html;
                    sourceSuggestionsList.classList.remove('hidden');
                    activeSuggestionIndex = -1;

                    sourceSuggestionsList.querySelectorAll('.suggestion-item').forEach(el => {
                        el.addEventListener('click', function () {
                            const id = parseInt(this.getAttribute('data-id'));
                            const item = availableSourceLinesData.find(s => s.id === id);
                            if (item) {
                                selectSourceItem(item);
                            }
                        });
                    });
                }

                function selectSourceItem(item) {
                    selectedSourceItem = item;
                    if (additionalSourceLineIdInput) {
                        additionalSourceLineIdInput.value = item.id;
                    }
                    if (sourceSearchInput) {
                        sourceSearchInput.value = `${item.code} — ${item.item_name}`;
                    }
                    if (clearSourceBtn) {
                        clearSourceBtn.classList.remove('hidden');
                    }
                    if (sourceSuggestionsList) {
                        sourceSuggestionsList.classList.add('hidden');
                    }

                    const expectedTotal = parseInt(qtyInput.value) || 0;
                    const diff = Math.max(0, expectedTotal - maxAvailable);

                    if (selectedSourceTitle) selectedSourceTitle.textContent = `${item.code} — ${item.item_name}`;
                    if (selectedSourceBadge) selectedSourceBadge.textContent = `Tersedia: ${item.avail} pcs`;
                    if (selectedSourceSpk) selectedSourceSpk.textContent = `SPK: ${item.spk}`;
                    if (selectedSourceUsage) selectedSourceUsage.textContent = `Tambahan yang digunakan: ${diff} pcs`;
                    if (selectedSourceCard) selectedSourceCard.classList.remove('hidden');

                    updateSummary();
                }

                function clearSelectedSource() {
                    selectedSourceItem = null;
                    if (additionalSourceLineIdInput) additionalSourceLineIdInput.value = '';
                    if (sourceSearchInput) sourceSearchInput.value = '';
                    if (clearSourceBtn) clearSourceBtn.classList.add('hidden');
                    if (sourceSuggestionsList) sourceSuggestionsList.classList.add('hidden');
                    if (selectedSourceCard) selectedSourceCard.classList.add('hidden');
                    updateSummary();
                }

                if (sourceSearchInput) {
                    sourceSearchInput.addEventListener('input', function () {
                        // Reset selected item and ID while user is freely typing
                        selectedSourceItem = null;
                        if (additionalSourceLineIdInput) additionalSourceLineIdInput.value = '';
                        if (selectedSourceCard) selectedSourceCard.classList.add('hidden');
                        
                        if (this.value.trim().length > 0) {
                            if (clearSourceBtn) clearSourceBtn.classList.remove('hidden');
                            renderSuggestions(this.value);
                        } else {
                            if (clearSourceBtn) clearSourceBtn.classList.add('hidden');
                            if (sourceSuggestionsList) sourceSuggestionsList.classList.add('hidden');
                        }
                        updateSummary();
                    });

                    sourceSearchInput.addEventListener('focus', function () {
                        if (!selectedSourceItem && this.value.trim().length > 0) {
                            renderSuggestions(this.value);
                        }
                    });

                    sourceSearchInput.addEventListener('keydown', function (e) {
                        if (!sourceSuggestionsList || sourceSuggestionsList.classList.contains('hidden')) return;

                        const items = sourceSuggestionsList.querySelectorAll('.suggestion-item');
                        if (items.length === 0) return;

                        if (e.key === 'ArrowDown') {
                            e.preventDefault();
                            activeSuggestionIndex = (activeSuggestionIndex + 1) % items.length;
                            updateActiveSuggestion(items);
                        } else if (e.key === 'ArrowUp') {
                            e.preventDefault();
                            activeSuggestionIndex = (activeSuggestionIndex - 1 + items.length) % items.length;
                            updateActiveSuggestion(items);
                        } else if (e.key === 'Enter') {
                            if (activeSuggestionIndex >= 0 && items[activeSuggestionIndex]) {
                                e.preventDefault();
                                items[activeSuggestionIndex].click();
                            }
                        } else if (e.key === 'Escape') {
                            sourceSuggestionsList.classList.add('hidden');
                        }
                    });
                }

                function updateActiveSuggestion(items) {
                    items.forEach((el, idx) => {
                        if (idx === activeSuggestionIndex) {
                            el.classList.add('bg-amber-100');
                            el.scrollIntoView({ block: 'nearest' });
                        } else {
                            el.classList.remove('bg-amber-100');
                        }
                    });
                }

                if (clearSourceBtn) {
                    clearSourceBtn.addEventListener('click', function () {
                        clearSelectedSource();
                        if (sourceSearchInput) sourceSearchInput.focus();
                    });
                }

                // Close suggestions on click outside
                document.addEventListener('click', function (e) {
                    if (sourceAutocompleteContainer && !sourceAutocompleteContainer.contains(e.target)) {
                        if (sourceSuggestionsList) {
                            sourceSuggestionsList.classList.add('hidden');
                        }
                    }
                });

                if (revertQtyBtn) {
                    revertQtyBtn.addEventListener('click', function () {
                        qtyInput.value = maxAvailable;
                        clearSelectedSource();
                        autoDistribute();
                    });
                }

                if (additionalSourceReasonInput) {
                    additionalSourceReasonInput.addEventListener('input', updateSummary);
                }

                // Initial hydration if old value exists
                if (additionalSourceLineIdInput && additionalSourceLineIdInput.value) {
                    const existingId = parseInt(additionalSourceLineIdInput.value);
                    const matched = availableSourceLinesData.find(s => s.id === existingId);
                    if (matched) {
                        selectSourceItem(matched);
                    }
                }

                // Dynamically distribute quantity and render row inputs
                function autoDistribute() {
                    const qty = parseInt(qtyInput.value) || 0;
                    const capacity = parseInt(capacityInput.value) || 0;

                    // Handle Additional Source visibility & calculation
                    if (additionalSourceSection) {
                        if (qty > maxAvailable) {
                            const diff = qty - maxAvailable;
                            additionalSourceSection.classList.remove('hidden');
                            if (additionalSourceDiffNotice) {
                                additionalSourceDiffNotice.textContent = diff + ' pcs';
                            }
                            if (additionalSourceQtyInput) {
                                additionalSourceQtyInput.value = diff;
                            }
                            if (selectedSourceUsage) {
                                selectedSourceUsage.textContent = 'Tambahan yang digunakan: ' + diff + ' pcs';
                            }
                            if (additionalSourceReasonInput) {
                                additionalSourceReasonInput.required = true;
                            }
                        } else {
                            additionalSourceSection.classList.add('hidden');
                            if (additionalSourceQtyInput) {
                                additionalSourceQtyInput.value = 0;
                            }
                            if (additionalSourceReasonInput) {
                                additionalSourceReasonInput.required = false;
                                additionalSourceReasonInput.value = '';
                            }
                            clearSelectedSource();
                        }
                    }
                    
                    if (qty <= 0 || capacity <= 0) {
                        container.innerHTML = '<div class="text-xs text-slate-400 italic text-center py-2">Masukkan kuantitas dan pedoman kapasitas untuk melihat distribusi.</div>';
                        treesCountInput.value = 0;
                        document.getElementById('autoTreeCountLabel').textContent = '0 Tree';
                        updateSummary();
                        return;
                    }

                    // Formula: ceil(qty / capacity)
                    const numTrees = Math.ceil(qty / capacity);
                    treesCountInput.value = numTrees;
                    document.getElementById('autoTreeCountLabel').textContent = numTrees + ' Tree';
                    
                    let remaining = qty;
                    let html = '';
                    
                    for (let i = 0; i < numTrees; i++) {
                        const currentTreeQty = Math.min(capacity, remaining);
                        remaining -= currentTreeQty;
                        
                        html += `
                            <div class="tree-row flex items-center gap-3 p-2.5 rounded-lg border border-slate-200 bg-white">
                                <div class="w-20 text-xs font-semibold text-slate-500">Tree #${i + 1}</div>
                                <div class="flex-1">
                                    <input type="number" name="quantities[]" value="${currentTreeQty}" min="1" max="${qty}"
                                        class="tree-qty w-28 rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500">
                                </div>
                                <div class="text-xs text-slate-400">pcs</div>
                            </div>
                        `;
                    }
                    
                    container.innerHTML = html;
                    
                    bindEvents();
                    updateSummary();
                }

                function updateSummary() {
                    let total = 0;
                    const qtyRows = document.querySelectorAll('.tree-qty');
                    qtyRows.forEach(function (input) {
                        total += parseInt(input.value) || 0;
                    });
                    
                    totalQtyEl.textContent = new Intl.NumberFormat().format(total);
                    
                    const expectedTotal = parseInt(qtyInput.value) || 0;
                    
                    let isValid = true;
                    let warningText = '';

                    if (expectedTotal <= 0) {
                        isValid = false;
                        warningText = 'Kuantitas eksekusi harus lebih besar dari 0.';
                    } else if (total !== expectedTotal) {
                        isValid = false;
                        warningText = `Total distribusi tree (${total} pcs) tidak sama dengan Qty yang Akan Dirangkai (${expectedTotal} pcs).`;
                    } else if (expectedTotal > maxAvailable) {
                        const diff = expectedTotal - maxAvailable;
                        const hasSelectedSource = additionalSourceLineIdInput && additionalSourceLineIdInput.value && selectedSourceItem;
                        if (!hasSelectedSource) {
                            isValid = false;
                            warningText = `Kuantitas eksekusi (${expectedTotal} pcs) melebihi ketersediaan normal WO (${maxAvailable} pcs). Wajib memilih Sumber Lilin Tambahan untuk ${diff} pcs selisih fisik.`;
                        } else {
                            const avail = selectedSourceItem.avail || 0;
                            if (avail < diff) {
                                isValid = false;
                                warningText = `Sumber lilin yang dipilih hanya memiliki saldo tersedia ${avail} pcs, tidak mencukupi untuk selisih ${diff} pcs.`;
                            } else if (!additionalSourceReasonInput || !additionalSourceReasonInput.value.trim()) {
                                isValid = false;
                                warningText = 'Alasan penambahan lilin fisik wajib diisi sebagai audit trail.';
                            }
                        }
                    }

                    if (!isValid) {
                        totalQtyEl.classList.add('text-red-600');
                        totalQtyEl.classList.remove('text-slate-800');
                        validationWarning.textContent = warningText;
                        validationWarning.classList.remove('hidden');
                        submitBtn.disabled = true;
                        submitBtn.style.opacity = '0.5';
                    } else {
                        totalQtyEl.classList.remove('text-red-600');
                        totalQtyEl.classList.add('text-slate-800');
                        validationWarning.textContent = '';
                        validationWarning.classList.add('hidden');
                        submitBtn.disabled = false;
                        submitBtn.style.opacity = '1';
                    }
                }

                function bindEvents() {
                    document.querySelectorAll('.tree-qty').forEach(input => {
                        input.removeEventListener('input', updateSummary);
                        input.addEventListener('input', updateSummary);
                    });
                }

                function openConfirmationModal() {
                    // Populate modal with actual values
                    const qtyRows = document.querySelectorAll('.tree-qty');
                    const numTrees = qtyRows.length;
                    let total = 0;
                    const values = [];
                    qtyRows.forEach(input => {
                        const val = parseInt(input.value) || 0;
                        total += val;
                        values.push(val);
                    });

                    modalTreesCount.textContent = numTrees;
                    modalTotalPcs.textContent = new Intl.NumberFormat().format(total);

                    const expectedTotal = parseInt(qtyInput.value) || 0;
                    let sourceBreakdownHtml = '';
                    if (expectedTotal > maxAvailable && selectedSourceItem) {
                        const diff = expectedTotal - maxAvailable;
                        sourceBreakdownHtml = `<div class="mt-1 text-[10px] text-amber-900 bg-amber-100/60 p-1.5 rounded border border-amber-200">
                            <strong>Sumber Lilin:</strong> ${maxAvailable} pcs (WO Ini) + ${diff} pcs (Sumber Tambahan: <strong>${escapeHtml(selectedSourceItem.code)}</strong> &mdash; ${escapeHtml(selectedSourceItem.item_name)})
                        </div>`;
                    }

                    // Check if uniform
                    const allEqual = values.every(v => v === values[0]);
                    if (allEqual && numTrees > 0) {
                        modalTreeCapacity.textContent = values[0];
                        modalBreakdownNote.innerHTML = `<div>${numTrees} tree × ${values[0]} pcs = ${total} pcs</div>${sourceBreakdownHtml}`;
                    } else {
                        const avg = numTrees > 0 ? (total / numTrees).toFixed(1) : 0;
                        modalTreeCapacity.textContent = avg;
                        modalBreakdownNote.innerHTML = `<div>Distribusi bervariasi (${values.join(', ')} pcs)</div>${sourceBreakdownHtml}`;
                    }

                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                    if (proceedBtn) {
                        proceedBtn.focus();
                    }
                }

                function closeConfirmationModal() {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                }

                qtyInput.addEventListener('input', autoDistribute);
                capacityInput.addEventListener('input', autoDistribute);

                // Run first-time distribution on load
                autoDistribute();

                // Form submit interception (covers click, Enter key in any input field, etc.)
                if (form) {
                    form.addEventListener('submit', function (e) {
                        if (confirmedSubmit) {
                            return; // Allow native submission
                        }

                        e.preventDefault();

                        // Check standard HTML5 validation first
                        if (!form.checkValidity()) {
                            form.reportValidity();
                            return;
                        }

                        // Check distribution calculation validation
                        const expectedTotal = parseInt(qtyInput.value) || 0;
                        let total = 0;
                        document.querySelectorAll('.tree-qty').forEach(function (input) {
                            total += parseInt(input.value) || 0;
                        });

                        if (expectedTotal <= 0 || total !== expectedTotal) {
                            updateSummary();
                            return;
                        }

                        if (expectedTotal > maxAvailable) {
                            if (!additionalSourceLineIdInput || !additionalSourceLineIdInput.value || !selectedSourceItem || !additionalSourceReasonInput || !additionalSourceReasonInput.value.trim()) {
                                updateSummary();
                                return;
                            }
                        }

                        // Open confirmation modal
                        openConfirmationModal();
                    });
                }

                if (cancelBtn) {
                    cancelBtn.addEventListener('click', function () {
                        closeConfirmationModal();
                    });
                }

                if (modal) {
                    modal.addEventListener('click', function (e) {
                        if (e.target === modal) {
                            closeConfirmationModal();
                        }
                    });
                }

                document.addEventListener('keydown', function (e) {
                    if (modal && !modal.classList.contains('hidden') && e.key === 'Escape') {
                        closeConfirmationModal();
                    }
                    if (cancelModal && !cancelModal.classList.contains('hidden') && e.key === 'Escape') {
                        closeCancelModal();
                    }
                });

                if (proceedBtn) {
                    proceedBtn.addEventListener('click', function () {
                        if (confirmedSubmit) {
                            return;
                        }

                        confirmedSubmit = true;
                        proceedBtn.disabled = true;
                        cancelBtn.disabled = true;
                        proceedBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1.5"></i> Menerbitkan...';

                        if (submitBtn) {
                            submitBtn.disabled = true;
                            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1.5"></i> Memproses...';
                        }

                        form.submit();
                    });
                }
            @endif
        });
    </script>
@endsection
