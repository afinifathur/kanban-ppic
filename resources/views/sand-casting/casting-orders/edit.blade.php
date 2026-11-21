@extends('layouts.app')

@section('top_bar')
    @php
        $initialTotalPcs = $castingOrder->lines->sum('qty_ordered');
        $initialTotalKg = $castingOrder->lines->sum(function ($l) {
            $w = $l->productionPlan ? (float) ($l->productionPlan->weight ?? 0) : 0;
            return $l->qty_ordered * $w;
        });
    @endphp
    <div class="flex flex-col sm:flex-row sm:items-center justify-between w-full gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-lg font-bold text-slate-800 leading-tight">Edit Draft Perintah Cor</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800 border border-amber-200">DRAFT</span>
            </div>
            <p class="text-gray-500 text-[10px]">Ubah kuantitas, Line produksi (1–4), atau tambahkan item rencana baru ke dalam draft dokumen ini</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <!-- Live Summary Bar -->
            <div class="bg-slate-800 text-white px-4 py-1.5 rounded-lg flex items-center gap-4 text-xs shadow-sm">
                <div>
                    <span class="text-slate-400">Item:</span>
                    <span id="summary-item-count" class="font-bold text-amber-400 ml-1">{{ $castingOrder->lines->count() }}</span>
                </div>
                <div class="h-3 w-px bg-slate-600"></div>
                <div>
                    <span class="text-slate-400">Total Qty:</span>
                    <span id="summary-total-pcs" class="font-bold text-emerald-400 ml-1">{{ number_format($initialTotalPcs) }}</span>
                    <span class="text-[10px] text-slate-300">PCS</span>
                </div>
                <div class="h-3 w-px bg-slate-600"></div>
                <div>
                    <span class="text-slate-400">Total Berat:</span>
                    <span id="summary-total-kg" class="font-bold text-sky-400 ml-1">{{ number_format($initialTotalKg, 2) }}</span>
                    <span class="text-[10px] text-slate-300">KG</span>
                </div>
            </div>

            <a href="{{ route('sand-casting.casting-orders.show', $castingOrder) }}" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold px-3 py-2 rounded-lg text-xs flex items-center gap-1.5 shadow-sm transition">
                <i class="fas fa-arrow-left"></i> Batal & Kembali
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="max-w-6xl mx-auto space-y-6">
        <!-- Flash Messages -->
        @if(session('success'))
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm px-4 py-3 rounded-lg flex items-center justify-between shadow-sm">
                <div class="flex items-center gap-2">
                    <i class="fas fa-check-circle text-emerald-500 text-base"></i>
                    <span>{{ session('success') }}</span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-emerald-600 text-sm">&times;</button>
            </div>
        @endif

        @if(session('error'))
            <div class="bg-red-50 border border-red-200 text-red-800 text-sm px-4 py-3 rounded-lg flex items-center justify-between shadow-sm">
                <div class="flex items-center gap-2">
                    <i class="fas fa-exclamation-circle text-red-500 text-base"></i>
                    <span>{{ session('error') }}</span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-red-400 hover:text-red-600 text-sm">&times;</button>
            </div>
        @endif

        <!-- Form Edit Header & Lines -->
        <form action="{{ route('sand-casting.casting-orders.update', $castingOrder) }}" method="POST" class="space-y-6">
            @csrf
            @method('PUT')

            <!-- Header Info -->
            <div class="bg-white shadow-sm rounded-xl border border-slate-200 p-6">
                <h3 class="text-slate-800 font-bold mb-4 flex items-center gap-2 border-b border-slate-100 pb-2">
                    <i class="fas fa-file-invoice text-blue-600"></i> Informasi Dokumen
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">No Perintah Cor</label>
                        <input type="text" name="casting_order_number" value="{{ old('casting_order_number', $castingOrder->casting_order_number) }}" required
                            class="w-full bg-white border @error('casting_order_number') border-red-500 @else border-slate-300 @enderror rounded-lg px-3 py-2 text-sm text-slate-700 font-mono focus:outline-none focus:border-blue-500">
                        @error('casting_order_number')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Tanggal Rencana Cor</label>
                        <input type="date" name="scheduled_date" value="{{ old('scheduled_date', $castingOrder->scheduled_date ? $castingOrder->scheduled_date->format('Y-m-d') : '') }}" required
                            class="w-full bg-white border @error('scheduled_date') border-red-500 @else border-slate-300 @enderror rounded-lg px-3 py-2 text-sm text-slate-700 focus:outline-none focus:border-blue-500">
                        @error('scheduled_date')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Catatan</label>
                        <input type="text" name="notes" value="{{ old('notes', $castingOrder->notes) }}"
                            class="w-full bg-white border @error('notes') border-red-500 @else border-slate-300 @enderror rounded-lg px-3 py-2 text-sm text-slate-700 focus:outline-none focus:border-blue-500">
                    </div>
                </div>
            </div>

            <!-- Existing Lines Table -->
            <div class="bg-white shadow-sm rounded-xl border border-slate-200 p-6">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
                    <h3 class="text-slate-800 font-bold flex items-center gap-2">
                        <i class="fas fa-list text-blue-600"></i> Item dalam Dokumen
                    </h3>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full border-collapse border border-slate-200 text-xs">
                        <thead class="bg-slate-50 text-slate-600 font-bold uppercase tracking-wider">
                            <tr>
                                <th class="border border-slate-200 p-3 text-center w-12">No</th>
                                <th class="border border-slate-200 p-3 text-left">Kode Cust</th>
                                <th class="border border-slate-200 p-3 text-left">Customer</th>
                                <th class="border border-slate-200 p-3 text-left">Nama Produk</th>
                                <th class="border border-slate-200 p-3 text-center">Ukuran / AISI</th>
                                <th class="border border-slate-200 p-3 text-center">Berat / Unit</th>
                                <th class="border border-slate-200 p-3 text-center">Qty Rencana</th>
                                <th class="border border-slate-200 p-3 text-center w-28 bg-amber-50 text-amber-800">Line (1–4)</th>
                                <th class="border border-slate-200 p-3 text-center w-36 bg-blue-50 text-blue-800">Qty Perintah</th>
                                <th class="border border-slate-200 p-3 text-center w-32 bg-emerald-50 text-emerald-800">Total Berat</th>
                                <th class="border border-slate-200 p-3 text-left">Catatan Line</th>
                                <th class="border border-slate-200 p-3 text-center w-16">Hapus</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($castingOrder->lines as $index => $line)
                                @php
                                    $plan = $line->productionPlan;
                                    $otherSum = $plan ? (int) $plan->castingOrderLines()->where('id', '!=', $line->id)->whereHas('castingOrder', fn($q) => $q->whereIn('status', ['DRAFT', 'ISSUED', 'PARTIALLY_COMPLETED', 'COMPLETED']))->sum('qty_ordered') : 0;
                                    $maxAllowed = $plan ? max(1, $plan->qty_planned - $otherSum) : 999999;
                                    $unitWeight = $plan ? (float) ($plan->weight ?? 0) : 0;
                                    $currentQty = (int) old("items.{$index}.qty_ordered", $line->qty_ordered);
                                    $currentLine = (int) old("items.{$index}.line_number", $plan?->line_number ?: 1);
                                    $initialRowWeight = $currentQty * $unitWeight;
                                @endphp
                                <tr class="hover:bg-slate-50 transition item-row">
                                    <td class="border border-slate-200 p-3 text-center font-mono text-slate-400">{{ $index + 1 }}</td>
                                    <td class="border border-slate-200 p-3 font-mono font-bold text-blue-700">
                                        {{ $line->code }}
                                        <input type="hidden" name="items[{{ $index }}][id]" value="{{ $line->id }}">
                                    </td>
                                    <td class="border border-slate-200 p-3 font-medium">{{ $line->customer ?: '-' }}</td>
                                    <td class="border border-slate-200 p-3 font-semibold text-slate-800">{{ $line->item_name }}</td>
                                    <td class="border border-slate-200 p-3 text-center font-mono text-slate-600">
                                        {{ $line->size ?: '-' }} / {{ $line->aisi ?: '-' }}
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center font-mono text-slate-600">
                                        {{ $unitWeight > 0 ? number_format($unitWeight, 2) . ' kg' : '-' }}
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center font-bold text-slate-900">
                                        {{ $plan ? number_format($plan->qty_planned) : '-' }}
                                    </td>
                                    <td class="border border-slate-200 p-2 text-center">
                                        @php
                                            $currentLineVal = (int) ($plan?->line_number ?: ($line->line_number ?: 0));
                                        @endphp
                                        @if($currentLineVal >= 1 && $currentLineVal <= 4)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                                Line {{ $currentLineVal }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                Line -
                                            </span>
                                        @endif
                                    </td>
                                    <td class="border border-slate-200 p-2 bg-blue-50/40">
                                        <input type="number" name="items[{{ $index }}][qty_ordered]"
                                            value="{{ $currentQty }}"
                                            min="1" max="{{ $maxAllowed }}" required
                                            data-weight="{{ $unitWeight }}"
                                            oninput="recalculateTotals()"
                                            class="qty-input w-full bg-white border border-blue-300 rounded-lg px-2 py-1 text-center text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        <div class="text-[9px] text-slate-400 text-center mt-0.5">Maks: {{ number_format($maxAllowed) }} pcs</div>
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center font-mono font-bold text-emerald-700 bg-emerald-50/30 row-total-weight">
                                        {{ number_format($initialRowWeight, 2) }} kg
                                    </td>
                                    <td class="border border-slate-200 p-2">
                                        <input type="text" name="items[{{ $index }}][notes]"
                                            value="{{ old("items.{$index}.notes", $line->notes) }}"
                                            placeholder="Catatan line..."
                                            class="w-full bg-white border border-slate-200 rounded-lg px-2 py-1 text-xs text-slate-700 focus:outline-none focus:border-blue-500">
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center">
                                        <button type="button" onclick="confirmDeleteLine('{{ $line->id }}', '{{ $line->code }}')"
                                            class="text-red-400 hover:text-red-600 p-1 font-bold" title="Hapus item ini">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-slate-50 font-bold text-slate-800 border-t border-slate-200">
                            <tr>
                                <td colspan="8" class="border border-slate-200 p-3 text-right uppercase tracking-wider text-[11px]">Total Dokumen:</td>
                                <td class="border border-slate-200 p-3 text-center text-blue-800 text-sm"><span id="table-total-pcs">{{ number_format($initialTotalPcs) }}</span> pcs</td>
                                <td class="border border-slate-200 p-3 text-center text-emerald-700 font-mono"><span id="table-total-kg">{{ number_format($initialTotalKg, 2) }}</span> kg</td>
                                <td colspan="2" class="border border-slate-200 p-3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="flex justify-end pt-4">
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg text-xs flex items-center gap-2 shadow-sm transition">
                        <i class="fas fa-save"></i> Perbarui Dokumen
                    </button>
                </div>
            </div>
        </form>

        <!-- Hidden delete line forms -->
        @foreach($castingOrder->lines as $line)
            <form id="delete-line-form-{{ $line->id }}" action="{{ route('sand-casting.casting-orders.lines.destroy', [$castingOrder, $line]) }}" method="POST" class="hidden">
                @csrf
                @method('DELETE')
            </form>
        @endforeach

        <!-- Add New Plan Line Card -->
        @if($availablePlans->isNotEmpty() || $selectedPlan)
            <div class="bg-white shadow-sm rounded-xl border border-slate-200 p-6">
                <h3 class="text-slate-800 font-bold mb-4 flex items-center gap-2 border-b border-slate-100 pb-2">
                    <i class="fas fa-plus-circle text-emerald-600"></i> Tambah Item Rencana ke Dokumen
                </h3>

                <form id="addPlanLineForm" action="{{ route('sand-casting.casting-orders.lines.store', $castingOrder) }}" method="POST" class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                    @csrf
                    <div class="md:col-span-2 relative">
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">
                            Pilih Item Rencana Cor <span class="text-red-500">*</span>
                        </label>

                        {{-- Hidden input for production_plan_id --}}
                        <input type="hidden" name="production_plan_id" id="add_production_plan_id" value="{{ old('production_plan_id', $selectedPlan?->id) }}" required>

                        {{-- Autocomplete Search Input --}}
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none text-slate-400">
                                <i id="planSearchIcon" class="fas fa-search text-xs"></i>
                                <i id="planSearchSpinner" class="fas fa-circle-notch fa-spin text-xs text-blue-600 hidden"></i>
                            </div>
                            <input type="text" id="planSearchInput"
                                value="{{ $selectedPlan ? ($selectedPlan->code . ' — ' . $selectedPlan->item_name) : '' }}"
                                placeholder="Ketik kode item rencana (cth: 2610...)..."
                                autocomplete="off"
                                class="w-full bg-slate-50 border border-slate-300 rounded-lg pl-8 pr-8 py-2 text-xs text-slate-800 focus:bg-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 font-medium">
                            <button type="button" id="clearPlanSearchBtn"
                                class="absolute inset-y-0 right-0 pr-2.5 flex items-center text-slate-400 hover:text-slate-600 {{ $selectedPlan ? '' : 'hidden' }}"
                                title="Bersihkan pilihan">
                                <i class="fas fa-times text-xs"></i>
                            </button>
                        </div>

                        {{-- Autocomplete Dropdown List --}}
                        <div id="planSearchDropdown"
                            class="absolute left-0 right-0 top-full mt-1 bg-white border border-slate-200 rounded-xl shadow-xl z-50 max-h-72 overflow-y-auto hidden">
                            <div id="planSearchResultsList" class="divide-y divide-slate-100"></div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Line (Read-only)</label>
                        <div id="add_line_display" class="w-full bg-slate-100 border border-slate-200 rounded-lg px-3 py-2 text-xs font-bold text-slate-700">
                            {{ $selectedPlan ? ('Line ' . ($selectedPlan->line_number ?: 1)) : '-' }}
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Qty Perintah</label>
                        <input type="number" name="qty_ordered" id="add_qty_ordered" min="1"
                            max="{{ $selectedPlan ? $selectedPlan->qty_remaining_casting_scheduled : '' }}"
                            value="{{ old('qty_ordered', $selectedPlan?->qty_remaining_casting_scheduled) }}"
                            required placeholder="Jumlah pcs"
                            class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs font-bold text-slate-800 focus:outline-none focus:border-blue-500">
                    </div>

                    <div>
                        <button type="submit" id="add_line_submit_btn" class="w-full px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg text-xs flex items-center justify-center gap-2 shadow-sm transition">
                            <i class="fas fa-plus"></i> Tambahkan Item
                        </button>
                    </div>
                </form>
            </div>
        @endif
    </div>

    <script>
        function recalculateTotals() {
            const rows = document.querySelectorAll('.item-row');
            let totalPcs = 0;
            let totalKg = 0;

            rows.forEach(row => {
                const input = row.querySelector('.qty-input');
                const weightCell = row.querySelector('.row-total-weight');
                const qty = parseInt(input?.value) || 0;
                const unitWeight = parseFloat(input?.dataset.weight) || 0;
                const rowWeight = qty * unitWeight;

                totalPcs += qty;
                totalKg += rowWeight;

                if (weightCell) {
                    weightCell.textContent = rowWeight.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' kg';
                }
            });

            const summaryPcs = document.getElementById('summary-total-pcs');
            const summaryKg = document.getElementById('summary-total-kg');
            const tablePcs = document.getElementById('table-total-pcs');
            const tableKg = document.getElementById('table-total-kg');

            if (summaryPcs) summaryPcs.textContent = totalPcs.toLocaleString('en-US');
            if (summaryKg) summaryKg.textContent = totalKg.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            if (tablePcs) tablePcs.textContent = totalPcs.toLocaleString('en-US');
            if (tableKg) tableKg.textContent = totalKg.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function confirmDeleteLine(lineId, code) {
            if (confirm('Hapus item ' + code + ' dari draft Perintah Cor ini? Kuantitas akan dikembalikan ke Rencana Cor.')) {
                const form = document.getElementById('delete-line-form-' + lineId);
                if (form) form.submit();
            }
        }

        // ==========================================
        // Autocomplete Searchable Dropdown for Plan
        // ==========================================
        const planSearchInput = document.getElementById('planSearchInput');
        const planSearchDropdown = document.getElementById('planSearchDropdown');
        const planSearchResultsList = document.getElementById('planSearchResultsList');
        const hiddenPlanId = document.getElementById('add_production_plan_id');
        const clearPlanBtn = document.getElementById('clearPlanSearchBtn');
        const planSearchIcon = document.getElementById('planSearchIcon');
        const planSearchSpinner = document.getElementById('planSearchSpinner');
        const addLineDisplay = document.getElementById('add_line_display');
        const addQtyInput = document.getElementById('add_qty_ordered');
        const searchPlansUrl = "{{ route('sand-casting.casting-orders.search-plans', $castingOrder) }}";

        let planDebounceTimer = null;
        let currentSelectedPlan = @json($selectedPlanData ?? null);

        function setPlanSearching(isSearching) {
            if (isSearching) {
                if (planSearchIcon) planSearchIcon.classList.add('hidden');
                if (planSearchSpinner) planSearchSpinner.classList.remove('hidden');
            } else {
                if (planSearchSpinner) planSearchSpinner.classList.add('hidden');
                if (planSearchIcon) planSearchIcon.classList.remove('hidden');
            }
        }

        function clearPlanSelection() {
            currentSelectedPlan = null;
            if (hiddenPlanId) hiddenPlanId.value = '';
            if (planSearchInput) {
                planSearchInput.value = '';
                planSearchInput.focus();
            }
            if (clearPlanBtn) clearPlanBtn.classList.add('hidden');
            if (planSearchDropdown) planSearchDropdown.classList.add('hidden');
            if (addLineDisplay) addLineDisplay.textContent = '-';
            if (addQtyInput) {
                addQtyInput.value = '';
                addQtyInput.removeAttribute('max');
            }
        }

        function selectPlanItem(item) {
            currentSelectedPlan = item;
            if (hiddenPlanId) hiddenPlanId.value = item.id;
            if (planSearchInput) planSearchInput.value = `${item.code} — ${item.item_name}`;
            if (clearPlanBtn) clearPlanBtn.classList.remove('hidden');
            if (planSearchDropdown) planSearchDropdown.classList.add('hidden');

            // 5. Update Line (Read-only) display
            if (addLineDisplay) {
                addLineDisplay.textContent = item.line_label || `Line ${item.line_number || 1}`;
            }

            // 6. Update Qty Perintah
            if (addQtyInput) {
                addQtyInput.max = item.remaining_qty;
                addQtyInput.value = item.remaining_qty;
                addQtyInput.focus();
            }
        }

        if (clearPlanBtn) {
            clearPlanBtn.addEventListener('click', clearPlanSelection);
        }

        if (planSearchInput) {
            planSearchInput.addEventListener('input', function () {
                const query = this.value.trim();

                // If modified from current selection, reset the hidden ID
                if (currentSelectedPlan && this.value !== `${currentSelectedPlan.code} — ${currentSelectedPlan.item_name}`) {
                    currentSelectedPlan = null;
                    if (hiddenPlanId) hiddenPlanId.value = '';
                    if (addLineDisplay) addLineDisplay.textContent = '-';
                    if (addQtyInput) {
                        addQtyInput.value = '';
                        addQtyInput.removeAttribute('max');
                    }
                }

                if (!query) {
                    clearPlanSelection();
                    return;
                }

                if (clearPlanBtn) clearPlanBtn.classList.remove('hidden');

                if (query.length < 2) {
                    planSearchResultsList.innerHTML = `
                        <div class="p-3 text-center text-xs text-slate-400">
                            Ketik minimal 2 karakter untuk mencari item rencana...
                        </div>
                    `;
                    planSearchDropdown.classList.remove('hidden');
                    return;
                }

                setPlanSearching(true);
                clearTimeout(planDebounceTimer);
                planDebounceTimer = setTimeout(() => {
                    fetch(`${searchPlansUrl}?q=${encodeURIComponent(query)}`, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                        .then(res => res.json())
                        .then(data => {
                            setPlanSearching(false);
                            if (!data.success || !data.items || data.items.length === 0) {
                                planSearchResultsList.innerHTML = `
                                    <div class="p-3 text-center text-xs text-slate-400">
                                        Tidak ada item rencana yang cocok dengan "${query}".
                                    </div>
                                `;
                                planSearchDropdown.classList.remove('hidden');
                                return;
                            }

                            planSearchResultsList.innerHTML = data.items.map(item => `
                                <div class="p-2.5 hover:bg-blue-50/70 active:bg-blue-100 cursor-pointer flex items-center justify-between gap-3 transition-colors plan-result-item"
                                    data-item='${JSON.stringify(item).replace(/'/g, "&#39;")}'>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex flex-wrap items-center gap-1.5 mb-0.5">
                                            <span class="font-mono text-blue-700 bg-blue-50 px-1.5 py-0.5 rounded border border-blue-200 text-xs font-bold tracking-tight">
                                                ${item.code}
                                            </span>
                                            <span class="text-xs font-bold text-slate-800 truncate">
                                                ${item.item_name}
                                            </span>
                                        </div>
                                        <div class="text-[10px] text-slate-500 flex flex-wrap items-center gap-x-2">
                                            <span>Cust: <strong class="text-slate-700">${item.customer}</strong></span>
                                            ${item.size && item.size !== '-' ? `<span>&bull; Ukuran: <strong class="text-slate-700">${item.size}</strong></span>` : ''}
                                            ${item.weight > 0 ? `<span>&bull; Berat: <strong class="text-slate-700">${Number(item.weight).toFixed(2)} kg</strong></span>` : ''}
                                        </div>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-200 mb-0.5">
                                            ${item.line_label}
                                        </span>
                                        <div class="text-[10px] font-bold text-emerald-600">
                                            Sisa: ${Number(item.remaining_qty).toLocaleString('en-US')} pcs
                                        </div>
                                    </div>
                                </div>
                            `).join('');

                            planSearchDropdown.classList.remove('hidden');

                            // Click listeners on search items
                            planSearchResultsList.querySelectorAll('.plan-result-item').forEach(el => {
                                el.addEventListener('click', function () {
                                    const itemData = JSON.parse(this.getAttribute('data-item'));
                                    selectPlanItem(itemData);
                                });
                            });
                        })
                        .catch(err => {
                            setPlanSearching(false);
                            console.error('Plan search error:', err);
                        });
                }, 250); // 250ms debounce
            });

            // Focus show dropdown if query has results
            planSearchInput.addEventListener('focus', function () {
                if (this.value.trim().length >= 2 && planSearchResultsList.children.length > 0) {
                    planSearchDropdown.classList.remove('hidden');
                }
            });

            // Close on Escape
            planSearchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    planSearchDropdown.classList.add('hidden');
                }
            });
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function (e) {
            if (planSearchDropdown && planSearchInput && !planSearchInput.contains(e.target) && !planSearchDropdown.contains(e.target)) {
                planSearchDropdown.classList.add('hidden');
            }
        });

        // Form submission safety guard
        const addPlanLineForm = document.getElementById('addPlanLineForm');
        if (addPlanLineForm) {
            addPlanLineForm.addEventListener('submit', function (e) {
                if (!hiddenPlanId || !hiddenPlanId.value) {
                    e.preventDefault();
                    alert('Silakan cari dan pilih Item Rencana Cor dari hasil pencarian.');
                    if (planSearchInput) planSearchInput.focus();
                    return false;
                }
            });
        }
    </script>
@endsection
