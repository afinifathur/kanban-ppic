@extends('layouts.app')

@section('title', 'Lost Wax — Input Hasil Cor')

@section('top_bar')
<div class="flex items-center justify-between w-full min-w-0 gap-2">
    <div class="flex flex-col min-w-0">
        <div class="flex items-center gap-1.5 text-[11px] text-slate-400 font-medium">
            <span class="inline-flex items-center text-amber-600 font-bold">
                <i class="fas fa-fire mr-1 text-[10px]"></i>Pengecoran
            </span>
            <span class="text-slate-300">•</span>
            <span class="text-slate-500 font-medium">Phase 4</span>
        </div>
        <div class="flex items-baseline gap-2 min-w-0">
            <h1 class="text-base font-bold text-slate-900 leading-tight tracking-tight shrink-0">
                Input Hasil Cor
            </h1>
            <p class="hidden md:inline text-[11px] text-slate-500 font-normal truncate">
                — Hubungkan Traveler Card dari antrean Barang Siap Cor ke satu Heat Number penuangan aktual.
            </p>
        </div>
    </div>
    <div class="flex items-center gap-1.5 shrink-0">
        <a href="{{ route('lost-wax.casting-results.index') }}"
           class="inline-flex items-center px-2.5 py-1.5 rounded-lg text-xs font-semibold bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 transition shadow-xs">
            <i class="fas fa-history mr-1.5 text-slate-500"></i> Riwayat Hasil Cor
        </a>
        <a href="{{ route('lost-wax.reports.ready-for-casting.index') }}"
           class="inline-flex items-center px-2.5 py-1.5 rounded-lg text-xs font-semibold bg-slate-800 hover:bg-slate-900 text-white transition shadow-xs">
            <i class="fas fa-clipboard-check mr-1.5 text-amber-400"></i> Antrean Siap Cor
        </a>
    </div>
</div>
@endsection

@section('content')
<div class="space-y-4">

    @if($errors->any())
    <div class="bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 rounded-xl shadow-sm">
        <div class="flex items-center gap-2 font-semibold text-xs mb-1 text-rose-900">
            <i class="fas fa-exclamation-triangle text-rose-500"></i> Terdapat kesalahan penginputan:
        </div>
        <ul class="list-disc list-inside text-xs space-y-0.5 text-rose-700">
            @foreach($errors->all() as $err)
            <li>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <form id="castingResultForm" method="POST" action="{{ route('lost-wax.casting-results.store') }}" class="space-y-5">
        @csrf

        <!-- 1. Header Information Panel -->
        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <h2 class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-4 flex items-center gap-2">
                <span class="w-6 h-6 rounded-md bg-amber-50 text-amber-600 flex items-center justify-center text-xs">
                    <i class="fas fa-info-circle"></i>
                </span>
                Informasi Penuangan (Heat)
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4">
                <!-- Heat Number (Wajib) -->
                <div class="md:col-span-2 lg:col-span-2">
                    <label class="block text-xs font-semibold text-slate-700 mb-1.5">
                        Heat Number <span class="text-rose-500">*</span>
                    </label>
                    <input type="text"
                           name="heat_number"
                           id="heat_number"
                           value="{{ old('heat_number') }}"
                           required
                           autofocus
                           placeholder="Contoh: A411112601"
                           class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-900 font-mono font-bold tracking-wide placeholder-slate-400 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500 transition-colors">
                    <p class="text-[10px] text-slate-400 mt-1">Identitas unik nomor penuangan leburan tungku.</p>
                </div>

                <!-- Tanggal Cor (Wajib) -->
                <div class="md:col-span-1 lg:col-span-1">
                    <label class="block text-xs font-semibold text-slate-700 mb-1.5">
                        Tanggal Cor <span class="text-rose-500">*</span>
                    </label>
                    <input type="date"
                           name="cast_date"
                           id="cast_date"
                           value="{{ old('cast_date', $defaultDate) }}"
                           required
                           class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500 transition-colors">
                </div>

                <!-- Tungku (Opsional) -->
                <div class="md:col-span-1 lg:col-span-1">
                    <label class="block text-xs font-semibold text-slate-700 mb-1.5">Tungku</label>
                    <input type="text"
                           name="furnace"
                           id="furnace"
                           value="{{ old('furnace') }}"
                           placeholder="Contoh: 1 / A"
                           class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500 transition-colors">
                </div>

                <!-- Shift (Opsional) -->
                <div class="md:col-span-1 lg:col-span-1">
                    <label class="block text-xs font-semibold text-slate-700 mb-1.5">Shift</label>
                    <select name="shift" id="shift" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500 transition-colors">
                        <option value="1" {{ old('shift', '1') == '1' ? 'selected' : '' }}>Shift 1</option>
                        <option value="2" {{ old('shift') == '2' ? 'selected' : '' }}>Shift 2</option>
                        <option value="3" {{ old('shift') == '3' ? 'selected' : '' }}>Shift 3</option>
                    </select>
                </div>

                <!-- Operator Cor (Opsional) -->
                <div class="md:col-span-1 lg:col-span-1">
                    <label class="block text-xs font-semibold text-slate-700 mb-1.5">Operator Penuangan</label>
                    <input type="text"
                           name="operator_name"
                           id="operator_name"
                           value="{{ old('operator_name') }}"
                           placeholder="Nama Operator"
                           class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500 transition-colors">
                </div>

                <!-- Catatan (Opsional) -->
                <div class="md:col-span-3 lg:col-span-6">
                    <label class="block text-xs font-semibold text-slate-700 mb-1.5">Catatan Tambahan</label>
                    <input type="text"
                           name="notes"
                           id="notes"
                           value="{{ old('notes') }}"
                           placeholder="Catatan proses pengecoran atau kondisi leburan (opsional)..."
                           class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500 transition-colors">
                </div>
            </div>
        </div>

        <!-- 2. Traveler Card Selection Checklist Panel -->
        <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
            <!-- Filter & Quick Search Controls -->
            <div class="p-4 bg-slate-50 border-b border-slate-200 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
                <div class="flex items-center gap-3">
                    <span class="w-6 h-6 rounded-md bg-amber-50 text-amber-600 flex items-center justify-center text-xs">
                        <i class="fas fa-tasks"></i>
                    </span>
                    <div>
                        <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider">Pilih Traveler Card (FIFO)</h3>
                        <p class="text-[10px] text-slate-500">
                            Tersedia <strong id="visibleTreeCount" class="font-bold text-slate-700">{{ count($items) }}</strong> kartu siap dicor di lantai produksi
                            <span id="filteredIndicatorText" class="{{ (!empty($scanDateFrom) || !empty($scanDateTo) || !empty($summary['is_filtered'])) ? '' : 'hidden' }} text-amber-700 font-bold">
                                (difilter dari {{ $summary['queue_total_trees'] ?? count($items) }} total kartu)
                            </span>
                        </p>
                    </div>
                </div>

                <!-- Filter Controls: Date Range, Search & Batch Actions -->
                <div class="flex items-center gap-2 flex-wrap">
                    <!-- Date Range: Tanggal Scan -->
                    <div class="flex items-center gap-1.5 bg-white border border-slate-300 rounded-lg px-2.5 py-1 text-xs shadow-sm hover:border-amber-400 transition-colors {{ (!empty($scanDateFrom) || !empty($scanDateTo)) ? 'border-amber-500 bg-amber-50/40 text-amber-900' : '' }}" id="dateFilterWrapper">
                        <i class="fas fa-calendar-alt {{ (!empty($scanDateFrom) || !empty($scanDateTo)) ? 'text-amber-600' : 'text-slate-500' }} text-xs"></i>
                        <span class="text-[11px] font-bold text-slate-700">Tgl Scan:</span>
                        <input type="date"
                               id="scan_date_from"
                               value="{{ $scanDateFrom }}"
                               title="Tanggal awal scan"
                               class="bg-transparent border border-slate-200 rounded px-1.5 py-0.5 text-xs text-slate-800 focus:outline-none focus:border-amber-500 font-mono">
                        <span class="text-slate-400 text-xs font-bold">s/d</span>
                        <input type="date"
                               id="scan_date_to"
                               value="{{ $scanDateTo }}"
                               title="Tanggal akhir scan"
                               class="bg-transparent border border-slate-200 rounded px-1.5 py-0.5 text-xs text-slate-800 focus:outline-none focus:border-amber-500 font-mono">
                        <button type="button"
                                id="btnApplyDateFilter"
                                class="px-2 py-0.5 bg-amber-600 hover:bg-amber-700 text-white rounded font-bold text-[10px] transition shadow-xs cursor-pointer">
                            Filter
                        </button>
                        <button type="button"
                                id="btnResetDateFilter"
                                title="Reset filter tanggal scan"
                                class="text-xs text-rose-600 hover:text-rose-800 hover:underline font-bold px-1 transition {{ (empty($scanDateFrom) && empty($scanDateTo)) ? 'hidden' : '' }}">
                            Reset Filter
                        </button>
                    </div>

                    <!-- Quick Text Search -->
                    <div class="relative w-44 sm:w-48">
                        <i class="fas fa-search absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-[10px]"></i>
                        <input type="text"
                               id="quickSearch"
                               placeholder="Cari barcode / kode..."
                               class="w-full bg-white border border-slate-300 rounded-lg pl-7 pr-2.5 py-1 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500 transition-colors">
                    </div>

                    <!-- Selection Buttons -->
                    <button type="button"
                            id="btnSelectAll"
                            class="px-2.5 py-1 rounded-lg text-[11px] font-semibold bg-white hover:bg-slate-50 text-slate-700 border border-slate-300 transition-colors shadow-sm">
                        <i class="fas fa-check-square mr-1 text-amber-500"></i> Pilih Semua (Terlihat)
                    </button>
                    <button type="button"
                            id="btnDeselectAll"
                            class="px-2.5 py-1 rounded-lg text-[11px] font-semibold bg-white hover:bg-slate-50 text-slate-600 border border-slate-300 transition-colors shadow-sm">
                        <i class="fas fa-times mr-1 text-slate-400"></i> Batalkan
                    </button>
                </div>
            </div>

            <!-- Table of Candidate Trees -->
            <div class="overflow-x-auto max-h-[500px] overflow-y-auto">
                <table class="w-full text-left text-xs border-collapse">
                    <thead class="bg-slate-50 text-slate-600 uppercase text-[10px] font-bold tracking-wider border-b border-slate-200 sticky top-0 z-10">
                        <tr>
                            <th class="px-3 py-2.5 text-center w-10">
                                <input type="checkbox" id="headerCheckbox" class="rounded bg-white border-slate-300 text-amber-500 focus:ring-0 focus:ring-offset-0 cursor-pointer">
                            </th>
                            <th class="px-3 py-2.5 text-center w-10">No</th>
                            <th class="px-3 py-2.5">Traveler Card / Barcode</th>
                            <th class="px-3 py-2.5">Kode Produksi</th>
                            <th class="px-3 py-2.5">Customer</th>
                            <th class="px-3 py-2.5">Nama Item / Material</th>
                            <th class="px-3 py-2.5">SPK / WO</th>
                            <th class="px-3 py-2.5 text-right">Qty Siap Cor</th>
                        </tr>
                    </thead>
                    <tbody id="treeTableBody" class="divide-y divide-slate-100 text-slate-700">
                        @forelse($items as $idx => $tree)
                        <tr class="tree-row hover:bg-amber-50/40 transition-colors cursor-pointer"
                            data-tree-id="{{ $tree['tree_id'] }}"
                            data-barcode="{{ strtolower($tree['barcode']) }}"
                            data-code="{{ strtolower($tree['production_code']) }}"
                            data-customer="{{ strtolower($tree['customer']) }}"
                            data-item="{{ strtolower($tree['item_name']) }}"
                            data-qty="{{ $tree['quantity_pcs'] }}"
                            data-scan-date="{{ $tree['scan_date'] }}">
                            <td class="px-3 py-2 text-center" onclick="event.stopPropagation();">
                                <input type="checkbox"
                                       name="tree_ids[]"
                                       value="{{ $tree['tree_id'] }}"
                                       id="tree_check_{{ $tree['tree_id'] }}"
                                       class="tree-checkbox rounded bg-white border-slate-300 text-amber-500 focus:ring-0 focus:ring-offset-0 cursor-pointer"
                                       data-qty="{{ $tree['quantity_pcs'] }}"
                                       {{ in_array($tree['tree_id'], old('tree_ids', [])) ? 'checked' : '' }}>
                            </td>
                            <td class="px-3 py-2 text-center text-slate-400 font-medium row-number">
                                {{ $idx + 1 }}
                            </td>
                            <td class="px-3 py-2 font-mono font-bold text-amber-600">
                                <div>{{ $tree['barcode'] }}</div>
                                <div class="text-[10px] text-slate-400 font-sans font-normal flex items-center gap-1 mt-0.5" title="Waktu Scan Siap Cor (Dewaxing)">
                                    <i class="fas fa-calendar-alt text-slate-300 text-[9px]"></i>
                                    <span>{{ $tree['scanned_at_formatted'] ?? ($tree['scan_date_label'] ?? '-') }}</span>
                                </div>
                            </td>
                            <td class="px-3 py-2 font-mono font-bold text-slate-900">
                                {{ $tree['production_code'] }}
                            </td>
                            <td class="px-3 py-2 text-slate-600">
                                {{ $tree['customer'] }}
                            </td>
                            <td class="px-3 py-2 text-slate-700">
                                <div>{{ $tree['item_name'] }}</div>
                                @if(!empty($tree['material']) && $tree['material'] !== '-')
                                    <span class="text-[10px] text-slate-400 font-mono">{{ $tree['material'] }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-slate-500 font-mono text-[11px]">
                                {{ $tree['spk_number'] ?? '-' }}
                            </td>
                            <td class="px-3 py-2 text-right font-bold text-slate-900 text-sm whitespace-nowrap">
                                {{ number_format($tree['quantity_pcs']) }} <span class="text-[10px] text-slate-400 font-normal">PCS</span>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-slate-500">
                                <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-3 text-xl">
                                    <i class="fas fa-clipboard-check"></i>
                                </div>
                                <div class="font-semibold text-slate-700">Tidak ada antrean Barang Siap Cor</div>
                                <p class="text-xs text-slate-400 mt-1">Seluruh pohon telah dicor atau belum melalui Scan Siap Cor.</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Sticky Summary & Submit Bar -->
            <div class="p-4 bg-slate-50 border-t border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="flex items-center gap-6">
                    <div>
                        <span class="text-[10px] uppercase font-bold text-slate-500 tracking-wider block">Traveler Card Terpilih</span>
                        <div class="flex items-baseline gap-1.5 mt-0.5">
                            <span id="selectedCount" class="text-2xl font-black text-slate-900">0</span>
                            <span class="text-xs text-slate-500 font-medium">Pohon</span>
                        </div>
                    </div>
                    <div class="border-l border-slate-200 pl-6">
                        <span class="text-[10px] uppercase font-bold text-slate-500 tracking-wider block">Total Qty Siap Cor</span>
                        <div class="flex items-baseline gap-1.5 mt-0.5">
                            <span id="selectedPcs" class="text-2xl font-black text-amber-600">0</span>
                            <span class="text-xs text-slate-500 font-medium">PCS</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2.5">
                    <a href="{{ route('lost-wax.casting-results.index') }}"
                       class="px-4 py-2 rounded-lg text-xs font-semibold text-slate-600 hover:text-slate-800 bg-white hover:bg-slate-100 border border-slate-200 transition-colors shadow-sm">
                        Batal
                    </a>
                    <button type="submit"
                            id="btnSubmit"
                            disabled
                            class="px-6 py-2.5 rounded-lg text-xs font-bold tracking-wide uppercase transition-all shadow-sm bg-slate-200 text-slate-400 cursor-not-allowed">
                        <i class="fas fa-save mr-2"></i> Simpan Hasil Cor
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tableBody = document.getElementById('treeTableBody');
    const headerCheckbox = document.getElementById('headerCheckbox');
    const checkboxes = Array.from(document.querySelectorAll('.tree-checkbox'));
    const rows = Array.from(document.querySelectorAll('.tree-row'));
    const quickSearch = document.getElementById('quickSearch');
    const scanDateFromInput = document.getElementById('scan_date_from');
    const scanDateToInput = document.getElementById('scan_date_to');
    const btnApplyDateFilter = document.getElementById('btnApplyDateFilter');
    const btnResetDateFilter = document.getElementById('btnResetDateFilter');
    const dateFilterWrapper = document.getElementById('dateFilterWrapper');
    const visibleTreeCount = document.getElementById('visibleTreeCount');
    const filteredIndicatorText = document.getElementById('filteredIndicatorText');
    const btnSelectAll = document.getElementById('btnSelectAll');
    const btnDeselectAll = document.getElementById('btnDeselectAll');
    const selectedCountSpan = document.getElementById('selectedCount');
    const selectedPcsSpan = document.getElementById('selectedPcs');
    const btnSubmit = document.getElementById('btnSubmit');
    const form = document.getElementById('castingResultForm');
    const heatNumberInput = document.getElementById('heat_number');

    // Update totals and submit button state
    function updateSummary() {
        let count = 0;
        let pcs = 0;

        checkboxes.forEach(cb => {
            if (cb.checked) {
                count++;
                pcs += parseInt(cb.dataset.qty || 0, 10);
            }
        });

        selectedCountSpan.textContent = count.toLocaleString('id-ID');
        selectedPcsSpan.textContent = pcs.toLocaleString('id-ID');

        if (count > 0) {
            btnSubmit.disabled = false;
            btnSubmit.className = 'px-6 py-2.5 rounded-lg text-xs font-bold tracking-wide uppercase transition-all shadow-sm bg-amber-500 hover:bg-amber-600 text-white cursor-pointer';
        } else {
            btnSubmit.disabled = true;
            btnSubmit.className = 'px-6 py-2.5 rounded-lg text-xs font-bold tracking-wide uppercase transition-all shadow-sm bg-slate-200 text-slate-400 cursor-not-allowed';
        }
    }

    // Combined filter function (date range + quick search)
    function applyFilters() {
        let fromVal = scanDateFromInput ? scanDateFromInput.value.trim() : '';
        let toVal = scanDateToInput ? scanDateToInput.value.trim() : '';

        // Auto-swap if start date > end date
        if (fromVal && toVal && fromVal > toVal) {
            const tmp = fromVal;
            fromVal = toVal;
            toVal = tmp;
            if (scanDateFromInput) scanDateFromInput.value = fromVal;
            if (scanDateToInput) scanDateToInput.value = toVal;
        }

        const term = quickSearch ? quickSearch.value.toLowerCase().trim() : '';
        let visibleCount = 0;

        rows.forEach(row => {
            let matchesDate = true;
            const rowScanDate = row.dataset.scanDate || '';

            if (fromVal && (!rowScanDate || rowScanDate < fromVal)) {
                matchesDate = false;
            }
            if (toVal && (!rowScanDate || rowScanDate > toVal)) {
                matchesDate = false;
            }

            let matchesSearch = true;
            if (term) {
                const barcode = row.dataset.barcode || '';
                const code = row.dataset.code || '';
                const customer = row.dataset.customer || '';
                const item = row.dataset.item || '';
                matchesSearch = barcode.includes(term) || code.includes(term) || customer.includes(term) || item.includes(term);
            }

            if (matchesDate && matchesSearch) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        // Update UI filter state indicators
        const isDateFiltered = Boolean(fromVal || toVal);
        if (dateFilterWrapper) {
            if (isDateFiltered) {
                dateFilterWrapper.classList.add('border-amber-500', 'bg-amber-50/40', 'text-amber-900');
            } else {
                dateFilterWrapper.classList.remove('border-amber-500', 'bg-amber-50/40', 'text-amber-900');
            }
        }

        if (btnResetDateFilter) {
            if (isDateFiltered) {
                btnResetDateFilter.classList.remove('hidden');
            } else {
                btnResetDateFilter.classList.add('hidden');
            }
        }

        if (visibleTreeCount) {
            visibleTreeCount.textContent = visibleCount.toLocaleString('id-ID');
        }

        if (filteredIndicatorText) {
            if (isDateFiltered || term) {
                filteredIndicatorText.classList.remove('hidden');
            } else {
                filteredIndicatorText.classList.add('hidden');
            }
        }

        // If header checkbox is checked, sync state to visible rows
        if (headerCheckbox && headerCheckbox.checked) {
            headerCheckbox.checked = false;
        }
    }

    if (btnApplyDateFilter) {
        btnApplyDateFilter.addEventListener('click', applyFilters);
    }

    if (scanDateFromInput) {
        scanDateFromInput.addEventListener('change', applyFilters);
    }

    if (scanDateToInput) {
        scanDateToInput.addEventListener('change', applyFilters);
    }

    if (btnResetDateFilter) {
        btnResetDateFilter.addEventListener('click', function () {
            if (scanDateFromInput) scanDateFromInput.value = '';
            if (scanDateToInput) scanDateToInput.value = '';

            // If page was loaded with GET query params, reload clean create URL
            if (window.location.search && (window.location.search.includes('scan_date') || window.location.search.includes('date_'))) {
                window.location.href = "{{ route('lost-wax.casting-results.create') }}";
                return;
            }

            applyFilters();
        });
    }

    if (quickSearch) {
        quickSearch.addEventListener('input', applyFilters);
    }

    // Select all currently visible rows
    btnSelectAll.addEventListener('click', function () {
        rows.forEach(row => {
            if (row.style.display !== 'none') {
                const cb = row.querySelector('.tree-checkbox');
                if (cb) cb.checked = true;
            }
        });
        updateSummary();
    });

    // Deselect all
    btnDeselectAll.addEventListener('click', function () {
        checkboxes.forEach(cb => cb.checked = false);
        if (headerCheckbox) headerCheckbox.checked = false;
        updateSummary();
    });

    // Header checkbox toggles visible
    if (headerCheckbox) {
        headerCheckbox.addEventListener('change', function () {
            const shouldCheck = this.checked;
            rows.forEach(row => {
                if (row.style.display !== 'none') {
                    const cb = row.querySelector('.tree-checkbox');
                    if (cb) cb.checked = shouldCheck;
                }
            });
            updateSummary();
        });
    }

    // Form submission confirmation
    form.addEventListener('submit', function (e) {
        const heat = heatNumberInput.value.trim();
        let count = 0;
        let pcs = 0;

        checkboxes.forEach(cb => {
            if (cb.checked) {
                count++;
                pcs += parseInt(cb.dataset.qty || 0, 10);
            }
        });

        if (count === 0) {
            e.preventDefault();
            alert('Pilih minimal satu Traveler Card untuk disimpan.');
            return;
        }

        const confirmMessage = `Konfirmasi Simpan Hasil Cor:\n\n` +
            `Heat Number: ${heat}\n` +
            `Total Pohon: ${count} pohon\n` +
            `Total Kuantitas: ${pcs.toLocaleString('id-ID')} PCS\n\n` +
            `Traveler Card terpilih akan dipindahkan ke status Selesai Cor dan keluar dari antrean Siap Cor. Lanjutkan?`;

        if (!confirm(confirmMessage)) {
            e.preventDefault();
            return;
        }

        // Disable button to prevent duplicate clicks
        btnSubmit.disabled = true;
        btnSubmit.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Menyimpan...';
    });

    // Initial calculation on load
    updateSummary();
});
</script>
@endsection
