@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Perintah Rangkai &mdash; Preview</h1>
            <p class="text-gray-500 text-[10px]">Atur pembagian Traveler Tree untuk item {{ $line->item_name }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('lost-wax.assemblies.index') }}" class="text-slate-500 hover:text-slate-700 text-xs">
                <i class="fas fa-arrow-left"></i> Kembali
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="space-y-6 max-w-4xl">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Hasil Cetak (Good)</div>
                <div class="font-bold text-slate-800 text-lg">{{ number_format($line->qty_actual_good) }} pcs</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Sudah Dirangkai</div>
                <div class="font-bold text-slate-800 text-lg">
                    @php
                        $allocated = $line->trees->sum('quantity');
                    @endphp
                    {{ number_format($allocated) }} pcs
                </div>
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
            <h2 class="font-bold text-slate-800 mb-4">Rincian Traveler Tree</h2>

            <form method="POST" action="{{ route('lost-wax.assemblies.store', $line) }}" id="generateForm">
                @csrf
                <div class="flex flex-wrap items-end gap-3 mb-6">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Kapasitas Standar / Tree</label>
                        <input type="number" id="capacityInput" value="{{ $capacity }}" min="1"
                            class="w-32 rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Moulding Family (Barcode prefix) <span class="text-red-500">*</span></label>
                        <select name="family_code" required class="w-64 rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500">
                            <option value="">-- Pilih Family --</option>
                            @foreach($families as $code => $label)
                                <option value="{{ $code }}" {{ $familyCode == $code ? 'selected' : '' }}>{{ $code }} - {{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <button type="button" id="recalculateBtn"
                            class="bg-slate-600 hover:bg-slate-700 text-white text-sm font-bold py-2 px-4 rounded-lg transition-all">
                            <i class="fas fa-calculator"></i> Hitung Ulang
                        </button>
                    </div>
                    <div>
                        <button type="button" id="addTreeBtn"
                            class="bg-amber-100 hover:bg-amber-200 text-amber-800 text-sm font-bold py-2 px-4 rounded-lg border border-amber-300 transition-all">
                            <i class="fas fa-plus"></i> Tambah Tree
                        </button>
                    </div>
                </div>

                <div id="treesContainer" class="space-y-2 mb-6">
                    @foreach($proposed as $i => $qty)
                        <div class="tree-row flex items-center gap-3 p-3 rounded-lg border border-slate-200 bg-slate-50">
                            <div class="w-24 text-sm font-semibold text-slate-700">Tree #{{ str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT) }}</div>
                            <div class="flex-1">
                                <input type="number" name="quantities[]" value="{{ $qty }}" min="1"
                                    class="tree-qty w-28 rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>
                            <div class="text-xs text-slate-400">pcs</div>
                            <button type="button" class="remove-tree-btn text-red-400 hover:text-red-600 text-sm" {{ count($proposed) <= 1 ? 'disabled style=opacity:0.3' : '' }}>
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    @endforeach
                </div>

                <div id="treeSummary" class="text-sm text-slate-600 mb-6 bg-slate-50 p-3 rounded-lg border border-slate-200">
                    Total Tree yang Dibuat: <strong id="totalQty" class="text-slate-800 text-base">0</strong> pcs
                    &mdash; <span id="treeCount" class="font-semibold">0</span> Tree
                    &middot; Maksimum Tersedia: <strong>{{ number_format($availableQty) }}</strong> pcs
                </div>

                <div class="flex justify-end gap-3 border-t border-slate-200 pt-4">
                    <a href="{{ route('lost-wax.assemblies.index') }}" class="bg-slate-200 hover:bg-slate-300 text-slate-700 text-sm font-bold py-2 px-5 rounded-lg transition-all">Batal</a>
                    <button type="submit" id="submitBtn" class="bg-amber-600 hover:bg-amber-700 text-white text-sm font-bold py-2 px-6 rounded-lg transition-all shadow-sm">
                        <i class="fas fa-sitemap"></i> Terbitkan Traveler
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const container = document.getElementById('treesContainer');
            const totalQtyEl = document.getElementById('totalQty');
            const treeCountEl = document.getElementById('treeCount');
            const capacityInput = document.getElementById('capacityInput');
            const maxAvailable = {{ $availableQty }};
            const submitBtn = document.getElementById('submitBtn');

            function updateSummary() {
                let total = 0;
                let count = 0;
                document.querySelectorAll('.tree-qty').forEach(function (input) {
                    total += parseInt(input.value) || 0;
                    count++;
                });
                totalQtyEl.textContent = new Intl.NumberFormat().format(total);
                treeCountEl.textContent = count;

                if (total > maxAvailable) {
                    totalQtyEl.classList.add('text-red-600');
                    totalQtyEl.classList.remove('text-slate-800');
                    submitBtn.disabled = true;
                    submitBtn.style.opacity = '0.5';
                } else {
                    totalQtyEl.classList.remove('text-red-600');
                    totalQtyEl.classList.add('text-slate-800');
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                }

                document.querySelectorAll('.remove-tree-btn').forEach(function (btn, i) {
                    if (count <= 1) {
                        btn.disabled = true;
                        btn.style.opacity = '0.3';
                    } else {
                        btn.disabled = false;
                        btn.style.opacity = '1';
                    }
                });
            }

            container.addEventListener('input', function (e) {
                if (e.target.classList.contains('tree-qty')) {
                    updateSummary();
                }
            });

            container.addEventListener('click', function (e) {
                const btn = e.target.closest('.remove-tree-btn');
                if (btn && !btn.disabled) {
                    btn.closest('.tree-row').remove();
                    updateSummary();
                }
            });

            document.getElementById('recalculateBtn').addEventListener('click', function () {
                const capacity = parseInt(capacityInput.value) || 20;
                recalculateTrees(capacity);
            });

            document.getElementById('addTreeBtn').addEventListener('click', function () {
                const capacity = parseInt(capacityInput.value) || 20;
                const index = container.querySelectorAll('.tree-row').length;
                addTreeRow(index + 1, capacity);
                updateSummary();
            });

            function addTreeRow(index, qty) {
                const row = document.createElement('div');
                row.className = 'tree-row flex items-center gap-3 p-3 rounded-lg border border-slate-200 bg-slate-50';
                row.innerHTML = `
                    <div class="w-24 text-sm font-semibold text-slate-700">Tree #${String(index).padStart(3, '0')}</div>
                    <div class="flex-1">
                        <input type="number" name="quantities[]" value="${qty}" min="1"
                            class="tree-qty w-28 rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500">
                    </div>
                    <div class="text-xs text-slate-400">pcs</div>
                    <button type="button" class="remove-tree-btn text-red-400 hover:text-red-600 text-sm">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                container.appendChild(row);
            }

            function recalculateTrees(capacity) {
                container.innerHTML = '';
                let remaining = maxAvailable;
                let count = 0;
                while (remaining > 0) {
                    count++;
                    const qty = Math.min(capacity, remaining);
                    addTreeRow(count, qty);
                    remaining -= qty;
                }
                updateSummary();
            }

            // Initial summary calculation
            updateSummary();
        });
    </script>
@endsection
