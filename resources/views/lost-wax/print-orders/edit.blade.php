@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full gap-4">
        <div class="shrink-0">
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Edit Perintah Cetak: {{ $printOrder->print_order_number }}</h1>
            <p class="text-gray-500 text-[10px]">Ubah informasi dokumen atau kuantitas untuk Perintah Cetak draft ini</p>
        </div>
        <div class="flex items-stretch divide-x divide-slate-200 bg-white border border-slate-200 rounded-lg shadow-sm shrink-0">
            <div class="flex items-center gap-2 px-3 py-1.5">
                <span class="text-blue-500 text-sm" aria-hidden="true"><i class="fas fa-list"></i></span>
                <div class="text-right leading-tight">
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider whitespace-nowrap">Item</div>
                    <div id="summary-item-count" class="text-sm font-bold text-slate-800 whitespace-nowrap" aria-live="polite">0 ITEM</div>
                </div>
            </div>
            <div class="flex items-center gap-2 px-3 py-1.5">
                <span class="text-indigo-500 text-sm" aria-hidden="true"><i class="fas fa-cubes"></i></span>
                <div class="text-right leading-tight">
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider whitespace-nowrap">Total Qty (PCS)</div>
                    <div id="summary-total-pcs" class="text-sm font-bold text-slate-800 whitespace-nowrap" aria-live="polite">0 pcs</div>
                </div>
            </div>
            <div class="flex items-center gap-2 px-3 py-1.5">
                <span class="text-emerald-500 text-sm" aria-hidden="true"><i class="fas fa-weight-hanging"></i></span>
                <div class="text-right leading-tight">
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider whitespace-nowrap">Total Berat (KG)</div>
                    <div id="summary-total-kg" class="text-sm font-bold text-emerald-600 whitespace-nowrap" aria-live="polite">0 kg</div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <div class="max-w-6xl mx-auto">
        <form action="{{ route('lost-wax.print-orders.update', $printOrder) }}" method="POST" class="space-y-6">
            @csrf
            @method('PUT')

            <!-- Card Header info -->
            <div class="bg-white shadow-sm rounded-xl border border-slate-200 p-6">
                <h3 class="text-slate-800 font-bold mb-4 flex items-center gap-2 border-b border-slate-100 pb-2">
                    <i class="fas fa-file-invoice text-amber-500"></i> Informasi Dokumen Perintah Cetak
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">No Dokumen Perintah Cetak</label>
                        <input type="text" name="print_order_number" value="{{ old('print_order_number', $printOrder->print_order_number) }}" required
                            class="w-full bg-white border @error('print_order_number') border-red-500 @else border-slate-300 @enderror rounded-lg px-3 py-2 text-sm text-slate-700 font-mono focus:outline-none focus:border-amber-500">
                        @error('print_order_number')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Tanggal Cetak / Produksi</label>
                        <input type="date" name="scheduled_date" id="scheduled_date" value="{{ old('scheduled_date', $printOrder->scheduled_date->format('Y-m-d')) }}" required
                            class="w-full bg-white border @error('scheduled_date') border-red-500 @else border-slate-300 @enderror rounded-lg px-3 py-2 text-sm text-slate-700 focus:outline-none focus:border-amber-500">
                        @error('scheduled_date')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <!-- Card Items table -->
            <div class="bg-white shadow-sm rounded-xl border border-slate-200 p-6">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
                    <h3 class="text-slate-800 font-bold flex items-center gap-2">
                        <i class="fas fa-list text-amber-500"></i> Daftar Item Cetak Lilin
                    </h3>
                    @if($printOrder->status === 'DRAFT')
                        <button type="button" id="openAddItemModalBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-1.5 px-3.5 rounded-lg text-xs shadow-sm transition-all flex items-center gap-1.5 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <i class="fas fa-plus-circle"></i> Tambah Item
                        </button>
                    @endif
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full border-collapse border border-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="border border-slate-200 p-3 text-center w-12">No</th>
                                <th class="border border-slate-200 p-3 text-left">Kode Cust (Snapshot)</th>
                                <th class="border border-slate-200 p-3 text-left">Customer (Snapshot)</th>
                                <th class="border border-slate-200 p-3 text-left">Nama Produk (Snapshot)</th>
                                <th class="border border-slate-200 p-3 text-center">Planned Qty</th>
                                <th class="border border-slate-200 p-3 text-center">Belum Dijadwalkan (Sisa)</th>
                                <th class="border border-slate-200 p-3 text-center w-40">Qty Perintah Cetak</th>
                                <th class="border border-slate-200 p-3 text-center w-16">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($printOrder->lines as $index => $line)
                                @php
                                    $plan = $line->productionPlan;
                                    // Sisa excluding current line quantity (so we add it back to show the sisa available before this line was made)
                                    $sisa = $plan ? ($plan->qty_remaining_scheduled + $line->qty_ordered) : 0;
                                @endphp
                                <tr class="hover:bg-slate-50/50 transition-colors">
                                    <td class="border border-slate-200 p-3 text-center text-slate-400">
                                        {{ $index + 1 }}
                                        <input type="hidden" name="items[{{ $index }}][id]" value="{{ $line->id }}">
                                    </td>
                                    <td class="border border-slate-200 p-3 font-mono text-xs font-bold text-slate-700">
                                        {{ $line->code }}
                                    </td>
                                    <td class="border border-slate-200 p-3 font-bold text-slate-700 uppercase">
                                        {{ $line->customer ?: '-' }}
                                    </td>
                                    <td class="border border-slate-200 p-3">
                                        <div class="font-bold text-slate-800">{{ $line->item_name }}</div>
                                        <div class="text-[10px] text-slate-500 font-mono">Size {{ $line->size ?: '-' }} | AISI {{ $line->aisi ?: '-' }}</div>
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center font-bold text-slate-600">
                                        {{ $plan ? number_format($plan->qty_planned) : '-' }}
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center font-bold text-slate-500" data-remaining-qty="{{ $sisa }}">
                                        @if($plan)
                                            @if($sisa < 0)
                                                <span class="text-amber-600">{{ number_format($sisa) }} (Lebih)</span>
                                            @else
                                                <span>{{ number_format($sisa) }}</span>
                                            @endif
                                        @else
                                            <span class="text-slate-400 font-normal italic">Rencana dihapus</span>
                                        @endif
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center">
                                        <input type="number" name="items[{{ $index }}][qty_ordered]" 
                                             value="{{ old("items.{$index}.qty_ordered", $line->qty_ordered) }}" 
                                             min="1" required
                                             data-weight-per-piece="{{ $line->productionPlan?->weight ?? 0 }}"
                                             class="qty-input w-full bg-white border border-slate-300 rounded px-3 py-1.5 text-sm text-center font-bold text-slate-800 focus:outline-none focus:border-amber-500">
                                        <div class="warning-text text-[10px] text-amber-600 mt-1 font-semibold hidden">
                                            Over-scheduled!
                                        </div>
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center">
                                        <button type="button"
                                            data-delete-url="{{ route('lost-wax.print-orders.lines.destroy', [$printOrder, $line->id]) }}"
                                            data-line-code="{{ $line->code }}"
                                            data-line-qty="{{ $line->qty_ordered }}"
                                            class="delete-line-btn bg-red-50 hover:bg-red-100 text-red-600 font-bold py-1.5 px-2.5 rounded text-xs border border-red-200 transition-all"
                                            title="Hapus Item">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Submit action -->
            <div class="flex justify-end gap-3">
                <a href="{{ route('lost-wax.print-orders.show', $printOrder) }}" class="bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold py-2 px-6 rounded-lg text-sm transition-all">
                    Batal
                </a>
                <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white font-bold py-2 px-6 rounded-lg text-sm shadow transition-all flex items-center gap-2">
                    <i class="fas fa-save"></i> Perbarui Perintah Cetak
                </button>
            </div>
        </form>
    </div>

    <!-- Hidden helper form for deleting a single line -->
    <form id="delete-line-form" method="POST" class="hidden">
        @csrf
        @method('DELETE')
    </form>

    @if($printOrder->status === 'DRAFT')
        <!-- MODAL TAMBAH ITEM -->
        <div id="addItemModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4" aria-labelledby="add-item-modal-title" role="dialog" aria-modal="true">
            <div class="bg-white rounded-2xl max-w-2xl w-full p-6 shadow-2xl border border-slate-200 transform transition-all flex flex-col max-h-[90vh]">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center">
                            <i class="fas fa-plus"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-slate-900" id="add-item-modal-title">Tambah Item ke Perintah Cetak</h3>
                            <p class="text-[11px] text-slate-500">Pilih Production Plan aktif untuk ditambahkan sebagai line baru</p>
                        </div>
                    </div>
                    <button type="button" id="closeAddItemModalBtn" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg text-sm">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form action="{{ route('lost-wax.print-orders.lines.store', $printOrder) }}" method="POST" id="addItemForm" class="flex flex-col flex-1 overflow-hidden mt-4 space-y-4">
                    @csrf

                    <!-- Search Box -->
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-2.5 text-slate-400 text-xs"></i>
                        <input type="text" id="planSearchInput" placeholder="Cari Kode Produksi / Customer / Nama Produk..."
                            class="w-full pl-9 pr-4 py-2 border border-slate-300 rounded-lg text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                    </div>

                    <!-- Available Plans Table -->
                    <div class="border border-slate-200 rounded-lg overflow-y-auto max-h-56">
                        <table class="min-w-full text-xs divide-y divide-slate-200">
                            <thead class="bg-slate-50 sticky top-0">
                                <tr>
                                    <th class="p-2 text-left font-bold text-slate-600">Kode</th>
                                    <th class="p-2 text-left font-bold text-slate-600">Customer</th>
                                    <th class="p-2 text-left font-bold text-slate-600">Produk</th>
                                    <th class="p-2 text-center font-bold text-slate-600">Sisa Tersedia</th>
                                    <th class="p-2 text-center font-bold text-slate-600 w-16">Pilih</th>
                                </tr>
                            </thead>
                            <tbody id="availablePlansTableBody" class="divide-y divide-slate-100 bg-white">
                                @forelse($availablePlans ?? [] as $avPlan)
                                    @php
                                        $remaining = $avPlan->qty_remaining_scheduled;
                                    @endphp
                                    <tr class="plan-row hover:bg-indigo-50/50 cursor-pointer transition-colors"
                                        data-plan-id="{{ $avPlan->id }}"
                                        data-plan-code="{{ $avPlan->code }}"
                                        data-plan-customer="{{ $avPlan->customer }}"
                                        data-plan-product="{{ $avPlan->item_name }}"
                                        data-plan-size="{{ $avPlan->size }}"
                                        data-plan-aisi="{{ $avPlan->aisi }}"
                                        data-plan-remaining="{{ $remaining }}"
                                        data-plan-weight="{{ $avPlan->weight ?? 0 }}"
                                        data-search-text="{{ strtolower($avPlan->code . ' ' . $avPlan->customer . ' ' . $avPlan->item_name . ' ' . $avPlan->size . ' ' . $avPlan->aisi) }}">
                                        <td class="p-2 font-mono font-bold text-slate-700">{{ $avPlan->code }}</td>
                                        <td class="p-2 text-slate-700 uppercase">{{ $avPlan->customer ?: '-' }}</td>
                                        <td class="p-2">
                                            <div class="font-semibold text-slate-800">{{ $avPlan->item_name }}</div>
                                            <div class="text-[10px] text-slate-400 font-mono">{{ $avPlan->size }} · {{ $avPlan->aisi }}</div>
                                        </td>
                                        <td class="p-2 text-center font-bold text-indigo-700">{{ number_format($remaining) }} pcs</td>
                                        <td class="p-2 text-center">
                                            <button type="button" class="select-plan-btn bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold px-2 py-1 rounded text-[11px] border border-indigo-200">
                                                Pilih
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr id="emptyPlansRow">
                                        <td colspan="5" class="p-4 text-center text-slate-400 italic">
                                            Tidak ada Production Plan aktif yang tersedia untuk ditambahkan.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <!-- Selected Plan Form Details -->
                    <div id="selectedPlanSection" class="hidden bg-slate-50 border border-slate-200 rounded-lg p-3 space-y-3">
                        <input type="hidden" name="production_plan_id" id="selectedPlanIdInput" value="">
                        
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Item Dipilih</span>
                                <div class="font-bold text-slate-800 text-xs mt-0.5" id="selectedPlanTitle">-</div>
                                <div class="text-[10px] text-slate-500 font-mono" id="selectedPlanMeta">-</div>
                            </div>
                            <div class="text-right">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Maksimum Sisa</span>
                                <span class="font-bold text-indigo-700 text-xs" id="selectedPlanRemainingLabel">0 pcs</span>
                            </div>
                        </div>

                        <div class="border-t border-slate-200 pt-2 flex items-center justify-between gap-4">
                            <label for="modalQtyInput" class="text-xs font-bold text-slate-700 whitespace-nowrap">
                                Qty yang Ditambahkan: <span class="text-red-500">*</span>
                            </label>
                            <div class="flex items-center gap-1.5">
                                <input type="number" name="qty_ordered" id="modalQtyInput" min="1" max="1" required
                                    class="w-32 bg-white border border-slate-300 rounded-lg px-3 py-1.5 text-xs text-center font-bold text-slate-800 focus:outline-none focus:border-indigo-500">
                                <span class="text-xs text-slate-500">pcs</span>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                        <button type="button" id="cancelAddItemBtn" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-1.5 px-4 rounded-lg text-xs transition-all">
                            Batal
                        </button>
                        <button type="submit" id="submitAddItemBtn" disabled class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-1.5 px-5 rounded-lg text-xs shadow-sm transition-all flex items-center gap-1.5 opacity-50 cursor-not-allowed">
                            <i class="fas fa-plus"></i> Tambahkan ke Dokumen
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Alert / Concurrency Logic -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Watch qty inputs for warnings
            const qtyInputs = document.querySelectorAll('.qty-input');

            const summaryTotalPcsEl = document.getElementById('summary-total-pcs');
            const summaryTotalKgEl = document.getElementById('summary-total-kg');
            const summaryItemCountEl = document.getElementById('summary-item-count');

            function formatPcs(value) {
                return Number(value || 0).toLocaleString('en-US');
            }

            function formatKg(value) {
                const rounded = Math.round((value || 0) * 100) / 100;
                return Number(rounded).toLocaleString('en-US', { maximumFractionDigits: 2 });
            }

            function recalcSummary() {
                let totalPcs = 0;
                let totalKg = 0;
                let itemCount = 0;

                qtyInputs.forEach(function (input) {
                    const qty = parseInt(input.value, 10) || 0;
                    const weight = parseFloat(input.getAttribute('data-weight-per-piece')) || 0;

                    totalPcs += qty;
                    totalKg += qty * weight;
                    itemCount += 1;
                });

                if (summaryTotalPcsEl) {
                    summaryTotalPcsEl.textContent = formatPcs(totalPcs) + ' pcs';
                }
                if (summaryTotalKgEl) {
                    summaryTotalKgEl.textContent = formatKg(totalKg) + ' kg';
                }
                if (summaryItemCountEl) {
                    summaryItemCountEl.textContent = itemCount + ' ITEM';
                }
            }

            function checkWarning(input) {
                const tr = input.closest('tr');
                const remainingCell = tr.querySelector('[data-remaining-qty]');
                if (!remainingCell) return;
                
                const remaining = parseInt(remainingCell.getAttribute('data-remaining-qty')) || 0;
                const inputVal = parseInt(input.value) || 0;
                const warningDiv = tr.querySelector('.warning-text');

                // Check if user has scheduled more than what's available
                if (remaining > 0 && inputVal > remaining) {
                    const diff = inputVal - remaining;
                    warningDiv.textContent = 'Penjadwalan lebih (' + diff + ' pcs)';
                    warningDiv.classList.remove('hidden');
                } else {
                    warningDiv.classList.add('hidden');
                }
            }

            qtyInputs.forEach(input => {
                input.addEventListener('input', function () {
                    checkWarning(input);
                    recalcSummary();
                });
                checkWarning(input);
            });

            recalcSummary();

            // Delete single line (DRAFT-only)
            const deleteForm = document.getElementById('delete-line-form');
            document.querySelectorAll('.delete-line-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const url = btn.getAttribute('data-delete-url');
                    const code = btn.getAttribute('data-line-code');
                    const qty = btn.getAttribute('data-line-qty');

                    const message = 'Hapus item ' + code + ' dari Perintah Cetak?\n\n' + qty + ' pcs akan dikembalikan ke Rencana Cetak.';
                    if (confirm(message) && deleteForm) {
                        deleteForm.action = url;
                        deleteForm.submit();
                    }
                });
            });

            // Modal Add Item Logic
            const openModalBtn = document.getElementById('openAddItemModalBtn');
            const closeModalBtn = document.getElementById('closeAddItemModalBtn');
            const cancelModalBtn = document.getElementById('cancelAddItemBtn');
            const modal = document.getElementById('addItemModal');
            const planSearchInput = document.getElementById('planSearchInput');
            const planRows = document.querySelectorAll('.plan-row');
            const selectedPlanSection = document.getElementById('selectedPlanSection');
            const selectedPlanIdInput = document.getElementById('selectedPlanIdInput');
            const selectedPlanTitle = document.getElementById('selectedPlanTitle');
            const selectedPlanMeta = document.getElementById('selectedPlanMeta');
            const selectedPlanRemainingLabel = document.getElementById('selectedPlanRemainingLabel');
            const modalQtyInput = document.getElementById('modalQtyInput');
            const submitAddItemBtn = document.getElementById('submitAddItemBtn');

            function openModal() {
                if (modal) {
                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                    if (planSearchInput) {
                        planSearchInput.value = '';
                        filterPlans('');
                        planSearchInput.focus();
                    }
                }
            }

            function closeModal() {
                if (modal) {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                }
            }

            function filterPlans(query) {
                const q = (query || '').toLowerCase().trim();
                let visibleCount = 0;
                planRows.forEach(row => {
                    const text = row.getAttribute('data-search-text') || '';
                    if (!q || text.includes(q)) {
                        row.classList.remove('hidden');
                        visibleCount++;
                    } else {
                        row.classList.add('hidden');
                    }
                });
            }

            if (openModalBtn) {
                openModalBtn.addEventListener('click', openModal);
            }

            if (closeModalBtn) {
                closeModalBtn.addEventListener('click', closeModal);
            }

            if (cancelModalBtn) {
                cancelModalBtn.addEventListener('click', closeModal);
            }

            if (modal) {
                modal.addEventListener('click', function (e) {
                    if (e.target === modal) {
                        closeModal();
                    }
                });
            }

            document.addEventListener('keydown', function (e) {
                if (modal && !modal.classList.contains('hidden') && e.key === 'Escape') {
                    closeModal();
                }
            });

            if (planSearchInput) {
                planSearchInput.addEventListener('input', function () {
                    filterPlans(this.value);
                });
            }

            // Plan row selection
            planRows.forEach(row => {
                row.addEventListener('click', function () {
                    const planId = this.getAttribute('data-plan-id');
                    const code = this.getAttribute('data-plan-code');
                    const cust = this.getAttribute('data-plan-customer') || '-';
                    const product = this.getAttribute('data-plan-product');
                    const size = this.getAttribute('data-plan-size') || '-';
                    const aisi = this.getAttribute('data-plan-aisi') || '-';
                    const remaining = parseInt(this.getAttribute('data-plan-remaining'), 10) || 0;

                    // Highlight selected row
                    planRows.forEach(r => r.classList.remove('bg-indigo-100'));
                    this.classList.add('bg-indigo-100');

                    // Fill selection details
                    selectedPlanIdInput.value = planId;
                    selectedPlanTitle.textContent = `${code} · ${product}`;
                    selectedPlanMeta.textContent = `Customer: ${cust} | Size: ${size} | AISI: ${aisi}`;
                    selectedPlanRemainingLabel.textContent = `${formatPcs(remaining)} pcs`;

                    modalQtyInput.max = remaining;
                    modalQtyInput.value = remaining;
                    modalQtyInput.min = 1;

                    selectedPlanSection.classList.remove('hidden');
                    submitAddItemBtn.disabled = false;
                    submitAddItemBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    modalQtyInput.focus();
                });
            });
        });
    </script>
@endsection
