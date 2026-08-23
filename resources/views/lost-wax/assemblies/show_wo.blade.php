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
            <a href="{{ route('lost-wax.assemblies.index') }}?tab=work-orders" class="bg-white hover:bg-slate-50 text-slate-700 font-semibold text-xs px-3 py-2 rounded-lg transition-all flex items-center gap-1.5 border border-slate-200 shadow-sm">
                <i class="fas fa-arrow-left"></i> Kembali
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="space-y-6 max-w-6xl">
        
        <!-- HEADER / IDENTITAS DOKUMEN -->
        <div class="bg-slate-900 text-white rounded-xl shadow-sm border border-slate-800 overflow-hidden p-5 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <span class="text-[9px] uppercase font-bold text-slate-400 tracking-wider block">Lost Wax Assembly Work Order</span>
                <div class="text-xl font-extrabold flex items-center gap-2 mt-0.5">
                    <i class="fas fa-file-invoice text-amber-400"></i>
                    No. WO: <span class="text-amber-400">{{ $workOrder->rangkai_order_number }}</span>
                </div>
            </div>
            <div>
                <span class="px-3 py-1 rounded-full text-xs font-black uppercase border
                    {{ $workOrder->status === 'COMPLETED' ? 'bg-emerald-950 text-emerald-350 border-emerald-800' : ($workOrder->status === 'IN_PROGRESS' ? 'bg-amber-950 text-amber-355 border-amber-800' : 'bg-slate-850 text-slate-300 border-slate-700') }}">
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
                <div class="text-[9px] uppercase font-bold text-slate-400 tracking-wider">Sudah Dirangkai</div>
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
                                        min="1" max="{{ min($workOrder->qty_outstanding, $line->qty_available_for_rangkai) }}" required
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
                                    <div>Maksimum Diizinkan: <strong class="text-slate-800">{{ number_format(min($workOrder->qty_outstanding, $line->qty_available_for_rangkai)) }}</strong> pcs</div>
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

                <!-- RIWAYAT EKSEKUSI -->
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 space-y-4">
                    <h2 class="font-bold text-slate-800 text-xs uppercase tracking-wide border-b border-slate-100 pb-2">
                        Riwayat Eksekusi Rangkai (Chronological)
                    </h2>

                    <div class="space-y-4">
                        @forelse($workOrder->executions->sortBy('created_at') as $exec)
                            <div class="p-4 rounded-lg border border-slate-200 bg-slate-50 flex flex-col gap-3">
                                <div class="flex items-center justify-between text-xs text-slate-500 border-b border-slate-200 pb-2">
                                    <span class="font-bold text-slate-700">Eksekusi Rangkai #{{ $loop->iteration }}</span>
                                    <span>Tanggal Rangkai: <strong class="text-slate-700">{{ $exec->execution_date->format('d-m-Y') }}</strong></span>
                                </div>

                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-xs">
                                    <div>
                                        <span class="text-slate-400 block mb-0.5">Pohon Dibuat</span>
                                        <strong class="text-slate-700 text-sm">{{ $exec->trees_created }} Tree</strong>
                                    </div>
                                    <div>
                                        <span class="text-slate-400 block mb-0.5">Total Pcs Good</span>
                                        <strong class="text-slate-700 text-sm">{{ number_format($exec->trees->sum('quantity')) }} pcs</strong>
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

                                <div class="mt-2 border-t border-slate-200 pt-2.5">
                                    <div class="text-[9px] font-bold text-slate-400 mb-1.5 uppercase tracking-wide">Pohon Fisik (LostWaxTree) Terbentuk:</div>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($exec->trees as $tree)
                                            <a href="{{ route('lost-wax.trees.show', $tree) }}" class="inline-flex items-center gap-1.5 bg-white border border-slate-200 hover:border-amber-400 text-slate-700 hover:text-amber-800 text-[10px] font-bold px-2 py-1 rounded transition-all shadow-sm">
                                                <i class="fas fa-barcode text-slate-400"></i>
                                                {{ $tree->barcode }} ({{ $tree->quantity }} pcs)
                                            </a>
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
                            <span class="font-bold text-slate-800">{{ $workOrder->rangkai_order_number }}</span>
                        </div>
                        <div class="flex justify-between border-b border-slate-50 pb-1.5">
                            <span class="text-slate-500">Kode Produksi:</span>
                            <span class="font-bold text-slate-800">{{ $line->code ?? '-' }}</span>
                        </div>
                        <div class="flex justify-between border-b border-slate-50 pb-1.5">
                            <span class="text-slate-500">No Perintah Cetak:</span>
                            <a href="{{ route('lost-wax.print-orders.show', $line->lost_wax_print_order_id) }}" class="font-bold text-amber-600 hover:underline">
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
                        <div>
                            <span class="text-slate-500 block mb-1">Catatan WO:</span>
                            <p class="p-2.5 rounded bg-slate-50 border border-slate-100 text-slate-650 italic text-[11px]">
                                {{ $workOrder->notes ?? 'Tidak ada catatan.' }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- REFERENSI VISUAL RANGKAI -->
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 space-y-4">
                    <h2 class="font-bold text-slate-800 text-xs uppercase tracking-wide border-b border-slate-100 pb-2">
                        Referensi Visual Rangkai (A5)
                    </h2>
                    <div class="space-y-3">
                        <div class="border-2 border-dashed border-slate-200 rounded-lg p-4 bg-slate-50/50 flex flex-col items-center justify-center text-center">
                            <i class="fas fa-camera text-slate-300 text-lg mb-1.5"></i>
                            <div class="text-[10px] font-semibold text-slate-500">Foto Referensi Tampak Depan</div>
                            <div class="text-[8px] text-slate-400 mt-0.5">Placeholder</div>
                        </div>
                        <div class="border-2 border-dashed border-slate-200 rounded-lg p-4 bg-slate-50/50 flex flex-col items-center justify-center text-center">
                            <i class="fas fa-camera text-slate-300 text-lg mb-1.5"></i>
                            <div class="text-[10px] font-semibold text-slate-500">Foto Referensi Tampak Samping</div>
                            <div class="text-[8px] text-slate-400 mt-0.5">Placeholder</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if($workOrder->qty_outstanding > 0)
        <!-- Real-time calculation script -->
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const qtyInput = document.getElementById('qtyExecutionInput');
                const capacityInput = document.getElementById('capacityGuideInput');
                const treesCountInput = document.getElementById('treesCreatedInput');
                const container = document.getElementById('treesContainer');
                const totalQtyEl = document.getElementById('totalQty');
                const submitBtn = document.getElementById('submitBtn');
                const validationWarning = document.getElementById('validationWarning');
                
                const outstanding = {{ $workOrder->qty_outstanding }};
                const available = {{ $line->qty_available_for_rangkai }};
                const maxAvailable = Math.min(outstanding, available);

                // Dynamically distribute quantity and render row inputs
                function autoDistribute() {
                    const qty = parseInt(qtyInput.value) || 0;
                    const capacity = parseInt(capacityInput.value) || 0;
                    
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
                    } else if (expectedTotal > maxAvailable) {
                        isValid = false;
                        warningText = `Kuantitas eksekusi tidak boleh melebihi sisa outstanding/ketersediaan cetak (${maxAvailable} pcs).`;
                    } else if (total !== expectedTotal) {
                        isValid = false;
                        warningText = `Total distribusi tree (${total} pcs) tidak sama dengan Qty yang Akan Dirangkai (${expectedTotal} pcs).`;
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

                qtyInput.addEventListener('input', autoDistribute);
                capacityInput.addEventListener('input', autoDistribute);

                // Run first-time distribution on load
                autoDistribute();
            });
        </script>
    @endif
@endsection
