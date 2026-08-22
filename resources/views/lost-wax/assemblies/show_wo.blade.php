@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Detail Perintah Rangkai (Work Order)</h1>
            <p class="text-gray-500 text-[10px]">Dokumen {{ $workOrder->rangkai_order_number }} &middot; Status: {{ $workOrder->status }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('lost-wax.assemblies.index', ['tab' => 'work-orders']) }}" class="text-slate-500 hover:text-slate-700 text-xs">
                <i class="fas fa-arrow-left"></i> Kembali
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="space-y-6 max-w-6xl">
        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Rencana Total Rangkai</div>
                <div class="font-bold text-slate-800 text-lg">{{ number_format($workOrder->qty_planned_pcs) }} pcs</div>
                <div class="text-[10px] text-slate-400 font-semibold">{{ $workOrder->qty_trees_planned }} Tree × {{ $workOrder->tree_capacity }} pcs</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Realisasi (Good)</div>
                <div class="font-bold text-emerald-700 text-lg">{{ number_format($workOrder->qty_executed_pcs) }} pcs</div>
                <div class="text-[10px] text-slate-400 font-semibold">{{ $workOrder->trees_completed }} Tree Selesai</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Sisa Outstanding Rangkai</div>
                <div class="font-bold text-lg {{ $workOrder->qty_outstanding > 0 ? 'text-amber-700' : 'text-emerald-600' }}">
                    {{ number_format($workOrder->qty_outstanding) }} pcs
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Traceability & Identity</div>
                <div class="font-bold text-slate-800 text-sm truncate">{{ $line->item_name }}</div>
                <div class="text-[10px] text-slate-400 font-semibold uppercase">
                    Kode Produksi: <strong>{{ $line->code ?? '-' }}</strong> &middot; AISI: {{ $line->aisi ?? '-' }}
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left: Exec Form (only if OPEN/IN_PROGRESS) -->
            <div class="lg:col-span-2 space-y-6">
                @if($workOrder->qty_outstanding > 0)
                    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                        <h2 class="font-bold text-slate-800 mb-4 flex items-center gap-1">
                            <i class="fas fa-sitemap text-amber-600"></i> Catat Eksekusi Rangkai Fisik
                        </h2>

                        <form method="POST" action="{{ route('lost-wax.assemblies.work-orders.execution.store', $workOrder) }}" id="executionForm" class="space-y-4">
                            @csrf
                            
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 mb-1">Tanggal Eksekusi <span class="text-red-500">*</span></label>
                                    <input type="date" name="execution_date" value="{{ date('Y-m-d') }}" required
                                        class="w-full rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 mb-1">Jumlah Tree Dirangkai <span class="text-red-500">*</span></label>
                                    <input type="number" name="trees_created" id="treesCreatedInput" 
                                        value="{{ count($proposed) }}" min="1" required
                                        class="w-full rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 mb-1">Moulding Family (Barcode prefix) <span class="text-red-500">*</span></label>
                                    <select name="family_code" required class="w-full rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500">
                                        <option value="">-- Pilih Family --</option>
                                        @foreach($families as $code => $label)
                                            <option value="{{ $code }}" {{ $familyCode == $code ? 'selected' : '' }}>{{ $code }} - {{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="bg-slate-50 p-4 border border-slate-200 rounded-lg">
                                <div class="text-xs font-bold text-slate-700 mb-3">Distribusi Kuantitas per Tree:</div>
                                <div id="treesContainer" class="space-y-2">
                                    @foreach($proposed as $i => $qty)
                                        <div class="tree-row flex items-center gap-3 p-2.5 rounded-lg border border-slate-200 bg-white">
                                            <div class="w-20 text-xs font-semibold text-slate-500">Tree #{{ $i + 1 }}</div>
                                            <div class="flex-1">
                                                <input type="number" name="quantities[]" value="{{ $qty }}" min="1" max="{{ $workOrder->tree_capacity }}"
                                                    class="tree-qty w-28 rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500">
                                            </div>
                                            <div class="text-xs text-slate-400">pcs</div>
                                            <button type="button" class="remove-tree-btn text-red-400 hover:text-red-600 text-sm" {{ count($proposed) <= 1 ? 'disabled style=opacity:0.3' : '' }}>
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="p-3 bg-slate-50 border border-slate-200 rounded-lg text-sm text-slate-600">
                                Total Eksekusi Rangkai: <strong id="totalQty" class="text-slate-800 text-base">0</strong> pcs
                                &middot; Sisa Outstanding: <strong>{{ number_format($workOrder->qty_outstanding) }}</strong> pcs
                                &middot; Tersedia dari Cetak: <strong>{{ number_format($line->qty_available_for_rangkai) }}</strong> pcs
                            </div>

                            <div class="flex justify-end gap-3 pt-2">
                                <button type="submit" id="submitBtn" class="bg-amber-600 hover:bg-amber-700 text-white text-sm font-bold py-2 px-6 rounded-lg transition-all shadow-sm">
                                    <i class="fas fa-check-circle mr-1"></i> Simpan Eksekusi Rangkai (Terbitkan Traveler)
                                </button>
                            </div>
                        </form>
                    </div>
                @else
                    <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-5 text-emerald-800 flex items-center gap-3">
                        <i class="fas fa-check-circle text-2xl text-emerald-600"></i>
                        <div>
                            <h3 class="font-bold">Work Order Rangkai Selesai</h3>
                            <p class="text-xs text-emerald-700 mt-0.5">Seluruh rencana perangkaian pohon lilin untuk perintah cetak ini telah diselesaikan secara penuh.</p>
                        </div>
                    </div>
                @endif

                <!-- Execution History list -->
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                    <h2 class="font-bold text-slate-800 mb-4">Riwayat Eksekusi Rangkai (Chronological)</h2>

                    <div class="space-y-4">
                        @forelse($workOrder->executions->sortBy('created_at') as $exec)
                            <div class="p-4 rounded-lg border border-slate-200 bg-slate-50 flex flex-col gap-3">
                                <div class="flex items-center justify-between text-xs text-slate-500 border-b border-slate-200 pb-2">
                                    <span>Eksekusi Rangkai #{{ $loop->iteration }}</span>
                                    <span>Tanggal: <strong class="text-slate-700">{{ $exec->execution_date->format('d-m-Y') }}</strong></span>
                                </div>

                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-xs">
                                    <div>
                                        <span class="text-slate-400 block">Pohon Dibuat</span>
                                        <strong class="text-slate-700 text-sm">{{ $exec->trees_created }} Tree</strong>
                                    </div>
                                    <div>
                                        <span class="text-slate-400 block">Total Pcs Good</span>
                                        <strong class="text-slate-700 text-sm">{{ number_format($exec->trees->sum('quantity')) }} pcs</strong>
                                    </div>
                                    <div>
                                        <span class="text-slate-400 block">Dicatat Oleh</span>
                                        <strong class="text-slate-700 text-sm">{{ $exec->recorder?->name ?? 'System' }}</strong>
                                    </div>
                                    <div>
                                        <span class="text-slate-400 block">Recorded At</span>
                                        <strong class="text-slate-700 text-sm">{{ $exec->created_at->format('d-m-Y H:i') }}</strong>
                                    </div>
                                </div>

                                <div class="mt-2">
                                    <div class="text-[10px] font-bold text-slate-400 mb-1.5 uppercase tracking-wide">Pohon Fisik (LostWaxTree) Terbentuk:</div>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($exec->trees as $tree)
                                            <a href="{{ route('lost-wax.trees.show', $tree) }}" class="inline-flex items-center gap-1 bg-white border border-slate-200 hover:border-amber-400 text-slate-700 hover:text-amber-800 text-[10px] font-bold px-2 py-1 rounded transition-all">
                                                <i class="fas fa-barcode text-slate-400"></i>
                                                {{ $tree->barcode }} ({{ $tree->quantity }} pcs)
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="p-8 text-center text-slate-400 italic text-sm">
                                Belum ada eksekusi rangkai yang tercatat. Gunakan formulir di atas untuk memulai.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            <!-- Right: WO Identity Details -->
            <div class="space-y-6">
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 space-y-4">
                    <h2 class="font-bold text-slate-800 border-b border-slate-100 pb-2">Informasi Rencana</h2>
                    
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
                            <span class="font-bold px-2 py-0.5 rounded text-[10px] {{ $workOrder->require_layer_7 ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-800' }}">
                                {{ $workOrder->require_layer_7 ? 'YA' : 'TIDAK (SKIP)' }}
                            </span>
                        </div>
                        <div class="flex justify-between border-b border-slate-50 pb-1.5">
                            <span class="text-slate-500">Kapasitas Tree:</span>
                            <span class="font-bold text-slate-800">{{ $workOrder->tree_capacity }} pcs / tree</span>
                        </div>
                        <div>
                            <span class="text-slate-500 block mb-1">Catatan WO:</span>
                            <p class="p-2.5 rounded bg-slate-50 border border-slate-100 text-slate-600 italic">
                                {{ $workOrder->notes ?? 'Tidak ada catatan.' }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if($workOrder->qty_outstanding > 0)
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const container = document.getElementById('treesContainer');
                const treesCreatedInput = document.getElementById('treesCreatedInput');
                const totalQtyEl = document.getElementById('totalQty');
                const submitBtn = document.getElementById('submitBtn');
                const capacity = {{ $workOrder->tree_capacity }};
                const outstanding = {{ $workOrder->qty_outstanding }};
                const available = {{ $line->qty_available_for_rangkai }};

                function updateSummary() {
                    let total = 0;
                    document.querySelectorAll('.tree-qty').forEach(function (input) {
                        total += parseInt(input.value) || 0;
                    });
                    totalQtyEl.textContent = new Intl.NumberFormat().format(total);

                    const limit = Math.min(outstanding, available);

                    if (total > limit || total <= 0) {
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
                }

                function rebuildRows() {
                    const count = parseInt(treesCreatedInput.value) || 0;
                    if (count < 1) return;

                    const currentRows = container.querySelectorAll('.tree-row');
                    const currentCount = currentRows.length;

                    if (count > currentCount) {
                        // Add rows
                        let remaining = Math.min(outstanding, available);
                        // Subtract already allocated in current rows
                        currentRows.forEach(row => {
                            const val = parseInt(row.querySelector('.tree-qty').value) || 0;
                            remaining -= val;
                        });

                        for (let i = currentCount; i < count; i++) {
                            const nextQty = Math.max(1, Math.min(capacity, remaining));
                            remaining -= nextQty;

                            const row = document.createElement('div');
                            row.className = 'tree-row flex items-center gap-3 p-2.5 rounded-lg border border-slate-200 bg-white';
                            row.innerHTML = `
                                <div class="w-20 text-xs font-semibold text-slate-500">Tree #${i + 1}</div>
                                <div class="flex-1">
                                    <input type="number" name="quantities[]" value="${nextQty}" min="1" max="${capacity}"
                                        class="tree-qty w-28 rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500">
                                </div>
                                <div class="text-xs text-slate-400">pcs</div>
                                <button type="button" class="remove-tree-btn text-red-400 hover:text-red-600 text-sm">
                                    <i class="fas fa-times"></i>
                                </button>
                            `;
                            container.appendChild(row);
                        }
                    } else if (count < currentCount) {
                        // Remove rows from end
                        for (let i = currentCount - 1; i >= count; i--) {
                            currentRows[i].remove();
                        }
                    }

                    bindEvents();
                    updateSummary();
                }

                function bindEvents() {
                    container.querySelectorAll('.tree-qty').forEach(input => {
                        input.removeEventListener('input', updateSummary);
                        input.addEventListener('input', updateSummary);
                    });

                    container.querySelectorAll('.remove-tree-btn').forEach(btn => {
                        btn.removeEventListener('click', removeRow);
                        btn.addEventListener('click', removeRow);
                    });

                    // Manage remove buttons visibility
                    const rows = container.querySelectorAll('.tree-row');
                    rows.forEach(row => {
                        const btn = row.querySelector('.remove-tree-btn');
                        if (rows.length <= 1) {
                            btn.disabled = true;
                            btn.style.opacity = '0.3';
                        } else {
                            btn.disabled = false;
                            btn.style.opacity = '1';
                        }
                    });
                }

                function removeRow(e) {
                    const row = e.target.closest('.tree-row');
                    if (row) {
                        row.remove();
                        treesCreatedInput.value = container.querySelectorAll('.tree-row').length;
                        rebuildRows();
                    }
                }

                treesCreatedInput.addEventListener('change', rebuildRows);
                treesCreatedInput.addEventListener('input', rebuildRows);

                bindEvents();
                updateSummary();
            });
        </script>
    @endif
@endsection
