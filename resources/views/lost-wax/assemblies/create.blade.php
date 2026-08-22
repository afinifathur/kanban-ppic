@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Buat Perintah Rangkai (Work Order)</h1>
            <p class="text-gray-500 text-[10px]">Terbitkan rencana perangkaian pohon (tree) baru dari cetak lilin</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('lost-wax.assemblies.index') }}" class="text-slate-500 hover:text-slate-700 text-xs">
                <i class="fas fa-arrow-left"></i> Kembali
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="space-y-6 max-w-3xl">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Hasil Cetak Good</div>
                <div class="font-bold text-slate-800 text-lg">{{ number_format($line->qty_executed_good) }} pcs</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Tersedia untuk Rangkai</div>
                <div class="font-bold text-slate-800 text-lg text-amber-700">{{ number_format($availableQty) }} pcs</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Produk</div>
                <div class="font-bold text-slate-800 text-sm truncate">{{ $line->item_name }}</div>
                <div class="text-xs text-slate-500">AISI: {{ $line->aisi ?? '-' }} &middot; Size: {{ $line->size ?? '-' }}</div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <h2 class="font-bold text-slate-800 mb-4">Informasi Rencana Rangkai</h2>

            <form method="POST" action="{{ route('lost-wax.assemblies.work-orders.store', $line) }}" class="space-y-4">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Rencana Jumlah Tree (Pohon) <span class="text-red-500">*</span></label>
                        <input type="number" name="qty_trees_planned" id="qtyTreesPlanned" 
                            value="{{ old('qty_trees_planned', max(1, (int) ceil($availableQty / 20))) }}" min="1" required 
                            class="w-full rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500">
                        <span class="text-[10px] text-gray-400">Tentukan perkiraan jumlah pohon fisik yang akan dirangkai</span>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Kapasitas Standar per Tree <span class="text-red-500">*</span></label>
                        <input type="number" name="tree_capacity" id="treeCapacity" 
                            value="{{ old('tree_capacity', $line->standard_tree_capacity ?? 20) }}" min="1" required 
                            class="w-full rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500">
                        <span class="text-[10px] text-gray-400">Kapasitas maksimal pcs cetak lilin per pohon</span>
                    </div>
                </div>

                <div class="p-3 bg-slate-50 border border-slate-200 rounded-lg text-sm text-slate-600">
                    <div>Perkiraan Total Rencana Rangkai: <strong id="totalPlannedPcs" class="text-slate-800 text-base">0</strong> pcs &middot; Maksimum Tersedia: <strong>{{ number_format($availableQty) }}</strong> pcs</div>
                    
                    <div class="mt-3 border-t border-slate-200 pt-2">
                        <div class="text-xs font-bold text-slate-550 mb-1.5 uppercase">Perkiraan Pembagian Pohon (Tree Preview):</div>
                        <div id="treePreviewList" class="grid grid-cols-2 md:grid-cols-4 gap-2">
                            @php
                                $initialTrees = max(1, (int) ceil($availableQty / 20));
                            @endphp
                            @for($i = 1; $i <= $initialTrees; $i++)
                                <div class="text-xs text-slate-600 bg-white border border-slate-200 rounded px-2.5 py-1 flex items-center gap-2 preview-row">
                                    <i class="fas fa-tree text-amber-600"></i> Tree #{{ str_pad((string) $i, 3, '0', STR_PAD_LEFT) }}
                                </div>
                            @endfor
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2 py-2">
                    <input type="checkbox" name="require_layer_7" id="requireLayer7" value="1" 
                        {{ old('require_layer_7', $line->require_layer_7) ? 'checked' : '' }}
                        class="rounded border-slate-300 text-amber-600 focus:ring-amber-500">
                    <label for="requireLayer7" class="text-xs font-semibold text-slate-700 select-none">Wajib Layer 7 (Melalui coating lapisan ke-7)</label>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Catatan Internal</label>
                    <textarea name="notes" placeholder="Catatan opsional..." rows="3" 
                        class="w-full rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500">{{ old('notes') }}</textarea>
                </div>

                <div class="flex justify-end gap-3 border-t border-slate-200 pt-4">
                    <a href="{{ route('lost-wax.assemblies.index') }}" class="bg-slate-200 hover:bg-slate-300 text-slate-700 text-sm font-bold py-2 px-5 rounded-lg transition-all">Batal</a>
                    <button type="submit" id="submitBtn" class="bg-amber-600 hover:bg-amber-700 text-white text-sm font-bold py-2 px-6 rounded-lg transition-all shadow-sm">
                        <i class="fas fa-check"></i> Buat Work Order Rangkai
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const qtyInput = document.getElementById('qtyTreesPlanned');
            const capInput = document.getElementById('treeCapacity');
            const totalPlannedEl = document.getElementById('totalPlannedPcs');
            const treePreviewList = document.getElementById('treePreviewList');
            const submitBtn = document.getElementById('submitBtn');
            const maxAvailable = {{ $availableQty }};

            function calculatePlanned() {
                const qty = parseInt(qtyInput.value) || 0;
                const cap = parseInt(capInput.value) || 0;
                const total = qty * cap;

                totalPlannedEl.textContent = new Intl.NumberFormat().format(total);

                if (total > maxAvailable) {
                    totalPlannedEl.classList.add('text-red-600');
                    totalPlannedEl.classList.remove('text-slate-800');
                    submitBtn.disabled = true;
                    submitBtn.style.opacity = '0.5';
                } else {
                    totalPlannedEl.classList.remove('text-red-600');
                    totalPlannedEl.classList.add('text-slate-800');
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                }

                // Rebuild preview list
                treePreviewList.innerHTML = '';
                for (let i = 1; i <= qty; i++) {
                    const numStr = String(i).padStart(3, '0');
                    const row = document.createElement('div');
                    row.className = 'text-xs text-slate-600 bg-white border border-slate-200 rounded px-2.5 py-1 flex items-center gap-2 preview-row';
                    row.innerHTML = `<i class="fas fa-tree text-amber-600"></i> Tree #${numStr}`;
                    treePreviewList.appendChild(row);
                }
            }

            qtyInput.addEventListener('input', calculatePlanned);
            capInput.addEventListener('input', calculatePlanned);

            calculatePlanned();
        });
    </script>
@endsection
