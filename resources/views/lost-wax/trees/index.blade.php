@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Rangkaian / Traveler</h1>
            <p class="text-gray-500 text-[10px]">Daftar Rangkaian Lost Wax, barcode &amp; traveler</p>
        </div>
    </div>
@endsection

@section('content')
    <div class="space-y-3 pb-6">
        <!-- Form Pencarian & Filter (Compact & Sticky Control Panel) -->
        <div class="sticky top-0 z-20 bg-white rounded-xl shadow-xs border border-slate-200 p-3.5">
            <form method="GET" action="{{ route('lost-wax.trees.index') }}" class="space-y-3">
                <div class="grid grid-cols-2 md:grid-cols-5 gap-2.5 items-end">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Cari Rangkaian / Barcode</label>
                        <div class="relative">
                            <i class="fas fa-barcode absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                            <input type="text" name="barcode" value="{{ request('barcode') }}" placeholder="Contoh: 1110826001" 
                                class="w-full pl-7 pr-2.5 py-1.5 rounded-lg border-slate-300 text-xs font-mono focus:ring-amber-500 focus:border-amber-500">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Kode Cust</label>
                        <input type="text" name="code" list="code-list" value="{{ request('code') }}" placeholder="Contoh: AB01" 
                            class="w-full py-1.5 px-2.5 rounded-lg border-slate-300 text-xs font-mono focus:ring-amber-500 focus:border-amber-500">
                        <datalist id="code-list">
                            @foreach($uniqueCodes as $uCode)
                                <option value="{{ $uCode }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Customer</label>
                        <input type="text" name="customer" list="customer-list" value="{{ request('customer') }}" placeholder="Contoh: PT. ABC" 
                            class="w-full py-1.5 px-2.5 rounded-lg border-slate-300 text-xs focus:ring-amber-500 focus:border-amber-500">
                        <datalist id="customer-list">
                            @foreach($uniqueCustomers as $uCust)
                                <option value="{{ $uCust }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Item / Product</label>
                        <input type="text" name="item" value="{{ request('item') }}" placeholder="Contoh: Flange" 
                            class="w-full py-1.5 px-2.5 rounded-lg border-slate-300 text-xs focus:ring-amber-500 focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Rack</label>
                        <select name="rack_id" class="w-full py-1.5 px-2.5 rounded-lg border-slate-300 text-xs focus:ring-amber-500 focus:border-amber-500">
                            <option value="">Semua Rack</option>
                            <option value="none" {{ request('rack_id') === 'none' ? 'selected' : '' }}>Belum Ada Rack</option>
                            @foreach($coatingRacks as $rack)
                                <option value="{{ $rack->id }}" {{ request('rack_id') == $rack->id ? 'selected' : '' }}>
                                    RAK-{{ str_pad((string)$rack->rack_number, 2, '0', STR_PAD_LEFT) }} {{ $rack->label ? '('.$rack->label.')' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <!-- Action Bar: Cari, Filter Reset & Bulk Action Buttons -->
                <div class="flex items-center justify-between gap-2.5 pt-2 border-t border-slate-100 flex-wrap">
                    <div class="flex items-center gap-2">
                        <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold py-1.5 px-3.5 rounded-lg shadow-2xs inline-flex items-center gap-1.5 transition-colors">
                            <i class="fas fa-search text-[10px]"></i> Cari &amp; Filter
                        </button>
                        @if(request()->anyFilled(['barcode', 'code', 'customer', 'item', 'rack_id']))
                            <a href="{{ route('lost-wax.trees.index') }}" class="text-xs text-slate-500 hover:text-slate-700 font-bold py-1.5 px-2.5 transition-colors flex items-center gap-1">
                                <i class="fas fa-undo text-[9px]"></i> Reset
                            </a>
                        @endif
                    </div>
                    
                    @if($trees->count() > 0)
                        <div class="flex items-center gap-2 flex-wrap">
                            <!-- Tombol Bulk Print Terpilih (Epson A4) -->
                            <button type="button" id="bulk-print-btn" disabled 
                                class="bg-slate-200 text-slate-400 cursor-not-allowed text-xs font-bold py-1.5 px-3 rounded-lg shadow-2xs inline-flex items-center gap-1.5 transition-all">
                                <i class="fas fa-print text-[10px]"></i> Cetak Terpilih (<span id="checked-count">0</span>)
                            </button>
                            <!-- Tombol Bulk Print Thermal Terpilih (TSC 90x50) -->
                            <button type="button" id="bulk-print-thermal-btn" disabled 
                                class="bg-slate-200 text-slate-400 cursor-not-allowed text-xs font-bold py-1.5 px-3.5 rounded-lg shadow-2xs inline-flex items-center gap-1.5 transition-all">
                                <i class="fas fa-barcode text-[10px]"></i> Cetak Thermal Terpilih
                            </button>
                            
                            <!-- Bulk Rack Assignment Panel -->
                            <div class="flex items-center gap-1.5 border-l border-slate-200 pl-2.5">
                                <input type="text" id="bulk-rack-select" list="coating-racks-list" disabled placeholder="- Pilih Rak -"
                                    class="bg-slate-100 text-slate-400 cursor-not-allowed text-xs font-bold py-1 px-2 rounded-lg border border-slate-200 focus:ring-amber-500 focus:border-amber-500 transition-all w-24 text-center">
                                <button type="button" id="bulk-assign-rack-btn" disabled
                                    class="bg-slate-200 text-slate-400 cursor-not-allowed text-xs font-bold py-1.5 px-3 rounded-lg shadow-2xs inline-flex items-center gap-1 transition-all">
                                    Tetapkan Rak
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
            </form>
        </div>

        <!-- Tabel Daftar Rangkaian dengan Internal Scroll & Sticky Header -->
        <div class="bg-white rounded-xl shadow-xs border border-slate-200 overflow-hidden">
            <div class="table-scroll-container overflow-y-auto overflow-x-auto" style="max-height: calc(100vh - 240px); min-height: 380px;">
                <table class="w-full border-collapse text-left text-xs text-slate-700">
                    <thead class="sticky top-0 z-10 bg-slate-800 text-white uppercase text-[10px] tracking-wider font-bold shadow-xs">
                        <tr>
                            <th class="sticky top-0 bg-slate-800 py-2.5 px-3 w-10 text-center">
                                <input type="checkbox" id="select-all" class="h-4 w-4 rounded border-slate-300 text-amber-600 focus:ring-amber-500 cursor-pointer">
                            </th>
                            <th class="sticky top-0 bg-slate-800 py-2.5 px-3 w-36 font-mono">Kode Rangkaian</th>
                            <th class="sticky top-0 bg-slate-800 py-2.5 px-3 w-16 text-center">Qty</th>
                            <th class="sticky top-0 bg-slate-800 py-2.5 px-3 w-24 text-center">Kode Cust</th>
                            <th class="sticky top-0 bg-slate-800 py-2.5 px-3 w-32">Customer</th>
                            <th class="sticky top-0 bg-slate-800 py-2.5 px-3 min-w-[260px]">Produk / Item</th>
                            <th class="sticky top-0 bg-slate-800 py-2.5 px-3 w-28 text-center">Rack</th>
                            <th class="sticky top-0 bg-slate-800 py-2.5 px-3 w-32 text-center">Status/Lapisan</th>
                            <th class="sticky top-0 bg-slate-800 py-2.5 px-3 w-20 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse($trees as $tree)
                            <tr class="hover:bg-slate-50/60 transition-colors">
                                <td class="py-2.5 px-3 text-center">
                                    <input type="checkbox" name="tree_ids[]" value="{{ $tree->id }}" class="tree-checkbox h-4 w-4 rounded border-slate-300 text-amber-600 focus:ring-amber-500 cursor-pointer">
                                </td>
                                <td class="py-2.5 px-3 font-mono font-bold text-slate-900">
                                    <a href="{{ route('lost-wax.trees.show', $tree) }}" class="hover:text-amber-600 transition-colors">
                                        {{ $tree->barcode }}
                                    </a>
                                </td>
                                <td class="py-2.5 px-3 text-center font-black text-slate-800">
                                    {{ number_format($tree->quantity) }}
                                </td>
                                <td class="py-2.5 px-3 text-center font-mono text-xs font-semibold text-slate-700">
                                    {{ $tree->getSourceCode() ?? '-' }}
                                </td>
                                <td class="py-2.5 px-3 font-semibold text-slate-700 truncate max-w-[140px]" title="{{ $tree->getSourceCustomer() }}">
                                    {{ $tree->getSourceCustomer() ?? '-' }}
                                </td>
                                <td class="py-2.5 px-3 font-medium text-slate-800">
                                    <div class="font-bold text-slate-900 leading-snug">{{ $tree->getSourceProduct() ?? '-' }}</div>
                                    <div class="text-[10px] text-slate-400 font-mono mt-0.5">{{ $tree->getSourceItemCode() ?? '-' }}</div>
                                </td>
                                <td class="py-2.5 px-3 text-center">
                                    @php
                                        $currentRackCount = $tree->rack_id ? ($rackCounts[$tree->rack_id] ?? 0) : 0;
                                        $isOverCapacity = $currentRackCount > 30;
                                        $originalValueLabel = $tree->rack_id && $tree->coatingRack ? 'RAK-'.str_pad($tree->coatingRack->rack_number, 2, '0', STR_PAD_LEFT) : '';
                                    @endphp
                                    <div class="relative inline-block w-24 text-center" id="rack-container-{{ $tree->id }}">
                                        <input type="text"
                                            list="coating-racks-list"
                                            onchange="onTreeRackInputChange(this, {{ $tree->id }})"
                                            data-original-value="{{ $originalValueLabel }}"
                                            value="{{ $originalValueLabel }}"
                                            placeholder="- Pilih Rak -"
                                            class="rack-input w-full rounded border-slate-300 py-1 px-1.5 text-xs focus:ring-amber-500 focus:border-amber-500 cursor-pointer text-center">
                                        <span class="rack-warning absolute -top-1.5 -right-1.5 text-amber-500 text-[10px] {{ $isOverCapacity ? '' : 'hidden' }}" 
                                            id="rack-warning-{{ $tree->id }}"
                                            title="Kapasitas rak ini saat ini {{ $currentRackCount }} tree (Kapasitas normal 25-30 tree)">
                                            ⚠️
                                        </span>
                                    </div>
                                </td>
                                <td class="py-2.5 px-3 text-center text-xs font-medium">
                                    <div class="flex flex-col items-center gap-0.5">
                                        @if($tree->current_stage)
                                            <span class="inline-block px-2 py-0.5 rounded-full font-bold text-[10.5px]
                                                {{ $tree->current_stage === 'oven' ? 'bg-emerald-100 text-emerald-800 border border-emerald-200' : 'bg-amber-100 text-amber-800 border border-amber-200' }}">
                                                {{ $tree->current_stage_label }}
                                            </span>
                                        @else
                                            <span class="inline-block px-2 py-0.5 rounded-full bg-blue-100 text-blue-800 border border-blue-200 font-bold text-[10.5px]">
                                                Sebelum Scan
                                            </span>
                                        @endif
                                        <span class="text-[9.5px] text-slate-400 italic">({{ $tree->status }})</span>
                                    </div>
                                </td>
                                <td class="py-2.5 px-3 text-center">
                                    <a href="{{ route('lost-wax.trees.show', $tree) }}" 
                                        class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold py-1 px-2.5 rounded-lg border border-slate-200 transition-colors inline-flex items-center justify-center gap-1 shadow-2xs"
                                        title="Lihat Detail">
                                        <i class="fas fa-eye text-[10px] text-slate-500"></i> Detail
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="p-12 text-center text-slate-400 font-medium">
                                    <div class="bg-slate-50 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 border border-dashed border-slate-300">
                                        <i class="fas fa-sitemap text-slate-400 text-2xl"></i>
                                    </div>
                                    <h3 class="text-slate-600 font-bold">Belum ada Rangkaian</h3>
                                    <p class="text-sm mt-1 text-slate-400">Silakan sesuaikan filter atau generate Rangkaian baru.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($trees->hasPages())
                <div class="p-3 border-t border-slate-200 bg-slate-50/50">
                    {{ $trees->links() }}
                </div>
            @endif
        </div>
    </div>

    <!-- Script Javascript untuk Checkbox dan Bulk Print -->
    <script>
        // Global rack lookup map
        const rackMap = {
            @foreach($coatingRacks as $rack)
                "rak-{{ str_pad($rack->rack_number, 2, '0', STR_PAD_LEFT) }}": "{{ $rack->id }}",
                "rak-{{ $rack->rack_number }}": "{{ $rack->id }}",
                "{{ str_pad($rack->rack_number, 2, '0', STR_PAD_LEFT) }}": "{{ $rack->id }}",
                "{{ $rack->rack_number }}": "{{ $rack->id }}",
            @endforeach
        };

        // Global functions (exposed to inline HTML/JS)
        function onTreeRackInputChange(inputElement, treeId) {
            const val = inputElement.value.trim().toLowerCase();
            const originalLabel = inputElement.getAttribute('data-original-value');
            
            let matchedKey = null;
            let matchedId = null;
            
            if (val !== '') {
                if (rackMap[val]) {
                    matchedId = rackMap[val];
                    const num = val.replace('rak-', '');
                    matchedKey = 'RAK-' + String(parseInt(num, 10)).padStart(2, '0');
                } else {
                    const num = parseInt(val, 10);
                    if (!isNaN(num) && num >= 1 && num <= 35) {
                        const paddedKey = 'rak-' + String(num).padStart(2, '0');
                        matchedId = rackMap[paddedKey];
                        matchedKey = 'RAK-' + String(num).padStart(2, '0');
                    }
                }
            }
            
            if (val === '') {
                updateTreeRackAjax(inputElement, treeId, null, '');
            } else if (matchedId) {
                inputElement.value = matchedKey;
                updateTreeRackAjax(inputElement, treeId, matchedId, matchedKey);
            } else {
                Swal.fire({
                    icon: 'warning',
                    title: 'Input Tidak Valid',
                    text: 'Silakan pilih atau ketik nomor rak yang valid (1-35).'
                });
                inputElement.value = originalLabel;
            }
        }

        function updateTreeRackAjax(inputElement, treeId, rackId, label) {
            const originalValue = inputElement.getAttribute('data-original-value');
            inputElement.disabled = true;

            const url = '{{ route("lost-wax.trees.update-rack", ":treeId") }}'.replace(':treeId', treeId);
            axios.patch(url, {
                rack_id: rackId,
                _token: '{{ csrf_token() }}'
            })
            .then(function(response) {
                inputElement.disabled = false;
                inputElement.setAttribute('data-original-value', label);
                
                // Show toast
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 3000,
                    icon: 'success',
                    title: response.data.message
                });

                // Update warning badge dynamically if present
                const warningSpan = document.getElementById('rack-warning-' + treeId);
                if (warningSpan) {
                    if (response.data.is_over_capacity) {
                        warningSpan.classList.remove('hidden');
                        warningSpan.setAttribute('title', 'Kapasitas rak ini saat ini ' + response.data.count + ' tree (Kapasitas normal 25-30 tree)');
                    } else {
                        warningSpan.classList.add('hidden');
                    }
                }
            })
            .catch(function(error) {
                inputElement.disabled = false;
                inputElement.value = originalValue;
                
                const errMsg = error.response && error.response.data && error.response.data.message 
                    ? error.response.data.message 
                    : 'Gagal memperbarui rack.';
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal',
                    text: errMsg
                });
            });
        }

        function printThermalSingle(id) {
            triggerThermalPrint(id);
        }

        function triggerThermalPrint(idsString) {
            const count = idsString.split(',').length;
            Swal.fire({
                title: 'Konfirmasi Cetak Thermal',
                text: 'Kirim ' + count + ' rangkaian ke antrean printer thermal?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Ya, Cetak!',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Memproses...',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    axios.post('{{ route("lost-wax.trees.print-thermal") }}', {
                        ids: idsString,
                        _token: '{{ csrf_token() }}'
                    })
                    .then(function (response) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Berhasil',
                            text: response.data.message,
                            confirmButtonColor: '#3085d6'
                        }).then(() => {
                            const selectAllCheckbox = document.getElementById('select-all');
                            const treeCheckboxes = document.querySelectorAll('.tree-checkbox');
                            if (selectAllCheckbox) selectAllCheckbox.checked = false;
                            treeCheckboxes.forEach(cb => cb.checked = false);
                            
                            const bulkPrintBtn = document.getElementById('bulk-print-btn');
                            const bulkPrintThermalBtn = document.getElementById('bulk-print-thermal-btn');
                            const checkedCountSpan = document.getElementById('checked-count');
                            checkedCountSpan.textContent = '0';
                            
                            bulkPrintBtn.disabled = true;
                            bulkPrintBtn.classList.remove('bg-amber-600', 'hover:bg-amber-700', 'text-white', 'cursor-pointer');
                            bulkPrintBtn.classList.add('bg-slate-200', 'text-slate-400', 'cursor-not-allowed');

                            if (bulkPrintThermalBtn) {
                                bulkPrintThermalBtn.disabled = true;
                                bulkPrintThermalBtn.classList.remove('bg-blue-600', 'hover:bg-blue-700', 'text-white', 'cursor-pointer');
                                bulkPrintThermalBtn.classList.add('bg-slate-200', 'text-slate-400', 'cursor-not-allowed');
                            }

                            window.location.reload();
                        });
                    })
                    .catch(function (error) {
                        const errMsg = error.response && error.response.data && error.response.data.message 
                            ? error.response.data.message 
                            : 'Gagal mengirim antrean cetak.';
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: errMsg,
                            confirmButtonColor: '#3085d6'
                        });
                    });
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const selectAllCheckbox = document.getElementById('select-all');
            const treeCheckboxes = document.querySelectorAll('.tree-checkbox');
            const bulkPrintBtn = document.getElementById('bulk-print-btn');
            const bulkPrintThermalBtn = document.getElementById('bulk-print-thermal-btn');
            const checkedCountSpan = document.getElementById('checked-count');

            function updateBulkPrintButton() {
                const checkedIds = Array.from(treeCheckboxes)
                    .filter(cb => cb.checked)
                    .map(cb => cb.value);

                const count = checkedIds.length;
                checkedCountSpan.textContent = count;

                const bulkRackSelect = document.getElementById('bulk-rack-select');
                const bulkAssignRackBtn = document.getElementById('bulk-assign-rack-btn');

                if (count > 0) {
                    bulkPrintBtn.disabled = false;
                    bulkPrintBtn.classList.remove('bg-slate-200', 'text-slate-400', 'cursor-not-allowed');
                    bulkPrintBtn.classList.add('bg-amber-600', 'hover:bg-amber-700', 'text-white', 'cursor-pointer');

                    if (bulkPrintThermalBtn) {
                        bulkPrintThermalBtn.disabled = false;
                        bulkPrintThermalBtn.classList.remove('bg-slate-200', 'text-slate-400', 'cursor-not-allowed');
                        bulkPrintThermalBtn.classList.add('bg-blue-600', 'hover:bg-blue-700', 'text-white', 'cursor-pointer');
                    }

                    if (bulkRackSelect) {
                        bulkRackSelect.disabled = false;
                        bulkRackSelect.classList.remove('bg-slate-100', 'text-slate-400', 'cursor-not-allowed');
                        bulkRackSelect.classList.add('bg-white', 'text-slate-800', 'cursor-pointer');
                    }
                    if (bulkAssignRackBtn) {
                        bulkAssignRackBtn.disabled = false;
                        bulkAssignRackBtn.classList.remove('bg-slate-200', 'text-slate-400', 'cursor-not-allowed');
                        bulkAssignRackBtn.classList.add('bg-amber-600', 'hover:bg-amber-700', 'text-white', 'cursor-pointer');
                    }
                } else {
                    bulkPrintBtn.disabled = true;
                    bulkPrintBtn.classList.remove('bg-amber-600', 'hover:bg-amber-700', 'text-white', 'cursor-pointer');
                    bulkPrintBtn.classList.add('bg-slate-200', 'text-slate-400', 'cursor-not-allowed');

                    if (bulkPrintThermalBtn) {
                        bulkPrintThermalBtn.disabled = true;
                        bulkPrintThermalBtn.classList.remove('bg-blue-600', 'hover:bg-blue-700', 'text-white', 'cursor-pointer');
                        bulkPrintThermalBtn.classList.add('bg-slate-200', 'text-slate-400', 'cursor-not-allowed');
                    }

                    if (bulkRackSelect) {
                        bulkRackSelect.disabled = true;
                        bulkRackSelect.value = '';
                        bulkRackSelect.classList.add('bg-slate-100', 'text-slate-400', 'cursor-not-allowed');
                        bulkRackSelect.classList.remove('bg-white', 'text-slate-800', 'cursor-pointer');
                    }
                    if (bulkAssignRackBtn) {
                        bulkAssignRackBtn.disabled = true;
                        bulkAssignRackBtn.classList.remove('bg-amber-600', 'hover:bg-amber-700', 'text-white', 'cursor-pointer');
                        bulkAssignRackBtn.classList.add('bg-slate-200', 'text-slate-400', 'cursor-not-allowed');
                    }
                }
            }

            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    treeCheckboxes.forEach(cb => cb.checked = selectAllCheckbox.checked);
                    updateBulkPrintButton();
                });
            }

            treeCheckboxes.forEach(cb => {
                cb.addEventListener('change', function() {
                    const allChecked = Array.from(treeCheckboxes).every(c => c.checked);
                    if (selectAllCheckbox) {
                        selectAllCheckbox.checked = allChecked;
                    }
                    updateBulkPrintButton();
                });
            });

            if (bulkPrintBtn) {
                bulkPrintBtn.addEventListener('click', function() {
                    const checkedIds = Array.from(treeCheckboxes)
                        .filter(cb => cb.checked)
                        .map(cb => cb.value);

                    if (checkedIds.length > 0) {
                        const firstId = checkedIds[0];
                        const idsQuery = checkedIds.join(',');
                        const printUrl = `{{ route('lost-wax.trees.traveler', ':firstId') }}`.replace(':firstId', firstId) + '?ids=' + idsQuery + '&auto_print=1';
                        window.open(printUrl, '_blank');
                    }
                });
            }

            if (bulkPrintThermalBtn) {
                bulkPrintThermalBtn.addEventListener('click', function() {
                    const checkedIds = Array.from(treeCheckboxes)
                        .filter(cb => cb.checked)
                        .map(cb => cb.value);

                    if (checkedIds.length > 0) {
                        triggerThermalPrint(checkedIds.join(','));
                    }
                });
            }

            const bulkAssignRackBtn = document.getElementById('bulk-assign-rack-btn');
            const bulkRackSelect = document.getElementById('bulk-rack-select');
            if (bulkAssignRackBtn && bulkRackSelect) {
                bulkAssignRackBtn.addEventListener('click', function() {
                    const val = bulkRackSelect.value.trim().toLowerCase();
                    let rackId = null;
                    let standardLabel = '';

                    if (val !== '') {
                        if (rackMap[val]) {
                            rackId = rackMap[val];
                            const num = val.replace('rak-', '');
                            standardLabel = 'RAK-' + String(parseInt(num, 10)).padStart(2, '0');
                        } else {
                            const num = parseInt(val, 10);
                            if (!isNaN(num) && num >= 1 && num <= 35) {
                                const key = 'rak-' + String(num).padStart(2, '0');
                                rackId = rackMap[key];
                                standardLabel = 'RAK-' + String(num).padStart(2, '0');
                            }
                        }
                    }

                    if (!rackId) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Pilih Rak',
                            text: 'Silakan pilih Coating Rack yang valid (1-35) terlebih dahulu.'
                        });
                        return;
                    }

                    bulkRackSelect.value = standardLabel;

                    const checkedIds = Array.from(treeCheckboxes)
                        .filter(cb => cb.checked)
                        .map(cb => cb.value);

                    Swal.fire({
                        title: 'Konfirmasi Bulk Assignment',
                        text: 'Tempatkan ' + checkedIds.length + ' rangkaian ke rak terpilih?',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: '#3085d6',
                        cancelButtonColor: '#d33',
                        confirmButtonText: 'Ya, Tetapkan!',
                        cancelButtonText: 'Batal'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            Swal.fire({
                                title: 'Memproses...',
                                allowOutsideClick: false,
                                didOpen: () => {
                                    Swal.showLoading();
                                }
                            });

                            axios.post('{{ route("lost-wax.trees.bulk-rack") }}', {
                                tree_ids: checkedIds,
                                rack_id: rackId,
                                _token: '{{ csrf_token() }}'
                            })
                            .then(function(response) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Berhasil',
                                    text: response.data.message,
                                    confirmButtonColor: '#3085d6'
                                }).then(() => {
                                    window.location.reload();
                                });
                            })
                            .catch(function(error) {
                                const errMsg = error.response && error.response.data && error.response.data.message
                                    ? error.response.data.message
                                    : 'Gagal melakukan bulk assignment.';
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Gagal',
                                    text: errMsg
                                });
                            });
                        }
                    });
                });
            }
        });
    </script>

    <datalist id="coating-racks-list">
        @foreach($coatingRacks as $rack)
            <option value="RAK-{{ str_pad((string)$rack->rack_number, 2, '0', STR_PAD_LEFT) }}"></option>
        @endforeach
    </datalist>
@endsection
