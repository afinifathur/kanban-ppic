@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full flex-wrap gap-2">
        <div>
            <div class="text-[11px] font-bold text-slate-500 tracking-wider uppercase">PT. PERONI KARYA SENTRA &bull; KANBAN PPIC LOST WAX</div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">LOST WAX &mdash; PERSEDIAAN PASCA DEWAXING</h1>
            <p class="text-gray-500 text-[10px]">Daftar seluruh pohon cangkang yang telah selesai dewaxing dan belum dicor (Buffer Inventory - FIFO)</p>
        </div>
        <div class="flex items-center gap-2 no-print">
            <a href="{{ route('lost-wax.reports.ready-for-casting.print', request()->query()) }}" target="_blank"
                class="text-xs bg-amber-600 hover:bg-amber-700 text-white font-bold py-1.5 px-3 rounded shadow-sm flex items-center gap-1.5"
                title="Cetak lembar kontrol pengecoran sesuai filter aktif">
                <i class="fas fa-print"></i>
                <span>Cetak Lembar Kontrol</span>
            </a>
            <a href="{{ route('lost-wax.reports.ready-for-casting.print') }}" target="_blank"
                class="text-xs bg-slate-700 hover:bg-slate-800 text-white font-bold py-1.5 px-3 rounded shadow-sm flex items-center gap-1.5"
                title="Cetak seluruh antrean tanpa filter">
                <i class="fas fa-file-alt"></i>
                <span>Cetak Semua</span>
            </a>
        </div>
    </div>
@endsection

@section('content')
<div class="space-y-4">
    <!-- KPI Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <!-- 1. Total Traveler Card -->
        <div class="bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm">
            <div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center justify-between">
                <span>Total Traveler Card</span>
                @if($summary['is_filtered'])
                    <span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-amber-100 text-amber-800">Filter</span>
                @endif
            </div>
            <div class="text-2xl font-black text-slate-800 mt-1">
                {{ number_format($summary['total_trees']) }} <span class="text-xs font-normal text-slate-500">Pohon</span>
            </div>
            <div class="text-[10px] text-slate-400 mt-1 flex items-center gap-1">
                <i class="fas fa-barcode text-slate-400"></i>
                <span>Total antrean: <strong>{{ number_format($summary['queue_total_trees']) }}</strong> pohon fisik</span>
            </div>
        </div>

        <!-- 2. Total Qty Siap Cor (PCS) -->
        <div class="bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm">
            <div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center justify-between">
                <span>Total Qty Cangkang Siap Cor</span>
                @if($summary['is_filtered'])
                    <span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-amber-100 text-amber-800">Filter</span>
                @endif
            </div>
            <div class="text-2xl font-black text-emerald-600 mt-1">
                {{ number_format($summary['total_usable_pcs']) }} <span class="text-xs font-normal text-slate-500">PCS</span>
            </div>
            <div class="text-[10px] text-slate-500 mt-1">
                <span>Bruto: <strong>{{ number_format($summary['total_gross_pcs']) }}</strong> PCS &bull; Afkir: <strong>{{ number_format($summary['total_defect_pcs']) }}</strong> PCS</span>
            </div>
        </div>

        <!-- 3. Total Kode Produksi -->
        <div class="bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm">
            <div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center justify-between">
                <span>Total Kode Produksi</span>
                @if($summary['is_filtered'])
                    <span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-amber-100 text-amber-800">Filter</span>
                @endif
            </div>
            <div class="text-2xl font-black text-slate-800 mt-1">
                {{ number_format($summary['total_codes']) }} <span class="text-xs font-normal text-slate-500">Kode</span>
            </div>
            <div class="text-[10px] text-slate-400 mt-1">
                <span>Dari <strong>{{ number_format($summary['queue_total_codes']) }}</strong> total kode pada antrean aktif</span>
            </div>
        </div>
    </div>

    <!-- Filter Toolbar -->
    <div class="bg-white p-3 rounded-lg border border-slate-200 shadow-sm flex flex-col md:flex-row items-center justify-between gap-3 no-print">
        <div class="flex flex-wrap items-center gap-2.5 w-full md:w-auto flex-1">
            <!-- Global Keyword Search Form -->
            <form method="GET" action="{{ route('lost-wax.reports.ready-for-casting.index') }}" class="w-full sm:w-auto sm:min-w-[280px] relative">
                @foreach($codes as $c)<input type="hidden" name="codes[]" value="{{ $c }}">@endforeach
                @foreach($customers as $cust)<input type="hidden" name="customers[]" value="{{ $cust }}">@endforeach

                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                <input type="text" name="search" value="{{ $search }}"
                    placeholder="Cari Barcode, Kode, Customer, SPK, Item..."
                    class="w-full pl-8 pr-3 py-1.5 rounded border border-slate-300 text-xs focus:border-amber-500 focus:ring-amber-500 bg-white">
            </form>

            <!-- Dropdown Trigger: Kode Produksi -->
            <button type="button" class="filter-dropdown-trigger text-xs bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold py-1.5 px-3 rounded flex items-center gap-1.5 transition-colors cursor-pointer {{ !empty($codes) ? 'border-amber-500 text-amber-700 bg-amber-50/40' : '' }}" data-filter-type="codes">
                <i class="fas fa-barcode text-slate-500"></i>
                <span>Kode Produksi</span>
                @if(!empty($codes))
                    <span class="bg-amber-100 text-amber-800 text-[10px] px-1.5 py-0.2 rounded-full font-black">{{ count($codes) }}</span>
                @endif
                <i class="fas fa-chevron-down text-[9px] text-slate-400 ml-0.5"></i>
            </button>

            <!-- Dropdown Trigger: Customer -->
            <button type="button" class="filter-dropdown-trigger text-xs bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold py-1.5 px-3 rounded flex items-center gap-1.5 transition-colors cursor-pointer {{ !empty($customers) ? 'border-amber-500 text-amber-700 bg-amber-50/40' : '' }}" data-filter-type="customers">
                <i class="fas fa-user-tie text-slate-500"></i>
                <span>Customer</span>
                @if(!empty($customers))
                    <span class="bg-amber-100 text-amber-800 text-[10px] px-1.5 py-0.2 rounded-full font-black">{{ count($customers) }}</span>
                @endif
                <i class="fas fa-chevron-down text-[9px] text-slate-400 ml-0.5"></i>
            </button>

            @if($search !== '' || !empty($codes) || !empty($customers))
                <a href="{{ route('lost-wax.reports.ready-for-casting.index') }}" class="text-xs text-rose-600 hover:underline font-bold ml-1">
                    Reset Filter
                </a>
            @endif
        </div>

        <div class="text-[11px] text-slate-500 text-right w-full md:w-auto">
            <span>Perbarui data: <strong>{{ $summary['generated_at_formatted'] }} {{ $summary['generated_time'] }}</strong> WIB</span>
        </div>
    </div>

    <!-- Active Filters Indicator Bar -->
    @if($summary['is_filtered'])
        <div class="flex flex-wrap items-center gap-1.5 text-[11px] text-slate-600 bg-slate-50 border border-slate-200 p-2.5 rounded-lg no-print">
            <span class="font-bold text-slate-700 mr-1 flex items-center gap-1">
                <i class="fas fa-filter text-slate-400"></i>
                <span>Filter Aktif:</span>
            </span>

            @if($search !== '')
                <span class="inline-flex items-center gap-1 bg-slate-200 text-slate-700 px-2.5 py-0.5 rounded-full font-medium">
                    Cari: "{{ $search }}"
                    <button type="button" class="hover:text-red-500 font-bold ml-1 text-slate-500" onclick="clearGlobalSearch()">&times;</button>
                </span>
            @endif

            @foreach($codes as $c)
                <span class="inline-flex items-center gap-1 bg-amber-100 text-amber-800 px-2.5 py-0.5 rounded-full font-mono font-bold">
                    Kode: {{ $c }}
                    <button type="button" class="hover:text-red-500 font-bold ml-1 text-amber-700" onclick="removeFilterValue('codes', '{{ $c }}')">&times;</button>
                </span>
            @endforeach

            @foreach($customers as $cust)
                <span class="inline-flex items-center gap-1 bg-blue-100 text-blue-800 px-2.5 py-0.5 rounded-full font-bold">
                    Cust: {{ $cust }}
                    <button type="button" class="hover:text-red-500 font-bold ml-1 text-blue-700" onclick="removeFilterValue('customers', '{{ $cust }}')">&times;</button>
                </span>
            @endforeach

            <span class="text-slate-400 text-[10px] ml-2">
                (Menampilkan <strong>{{ $summary['total_trees'] }}</strong> dari {{ $summary['queue_total_trees'] }} total pohon)
            </span>

            <a href="{{ route('lost-wax.reports.ready-for-casting.index') }}" class="text-blue-600 hover:underline font-bold text-xs ml-auto">
                Clear All
            </a>
        </div>
    @endif

    <!-- Main Table (One Row per Traveler Card) -->
    <div class="bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-xs text-left text-slate-700">
                <thead class="text-[11px] uppercase bg-slate-100 text-slate-700 border-b border-slate-200 select-none">
                    <tr>
                        <th scope="col" class="py-2.5 px-3 text-center w-12 font-bold">No.</th>
                        <th scope="col" class="py-2.5 px-3 font-bold whitespace-nowrap">Waktu Selesai Dewaxing</th>
                        <th scope="col" class="py-2.5 px-3 font-bold whitespace-nowrap">Traveler Card / Barcode</th>
                        <th scope="col" class="py-2.5 px-3 font-bold whitespace-nowrap">Kode Produksi</th>
                        <th scope="col" class="py-2.5 px-3 font-bold whitespace-nowrap">Customer</th>
                        <th scope="col" class="py-2.5 px-3 font-bold">Nama Barang / Material</th>
                        <th scope="col" class="py-2.5 px-3 font-bold whitespace-nowrap">SPK / WO</th>
                        <th scope="col" class="py-2.5 px-3 text-right font-bold whitespace-nowrap">Qty Cangkang (PCS)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($items as $item)
                        <tr class="hover:bg-amber-50/40 transition-colors {{ $loop->even ? 'bg-slate-50/50' : 'bg-white' }}">
                            <td class="py-2.5 px-3 text-center font-bold text-slate-500">
                                {{ $loop->iteration }}
                            </td>
                            <td class="py-2.5 px-3 whitespace-nowrap">
                                <div class="font-bold text-slate-800">{{ $item['scanned_at_formatted'] }}</div>
                                <div class="text-[10px] text-slate-400">{{ $item['scanned_diff'] }}</div>
                            </td>
                            <td class="py-2.5 px-3 whitespace-nowrap">
                                <div class="font-mono font-bold text-slate-900 tracking-wider">{{ $item['human_barcode'] }}</div>
                                <div class="text-[10px] font-mono text-slate-400">{{ $item['barcode'] }}</div>
                            </td>
                            <td class="py-2.5 px-3 whitespace-nowrap">
                                <span class="px-2 py-0.5 rounded font-mono font-bold text-xs bg-amber-100 text-amber-900 border border-amber-200">
                                    {{ $item['production_code'] }}
                                </span>
                                @if($item['is_multi_allocated'])
                                    <span class="ml-1 text-[9px] font-bold px-1 py-0.2 rounded bg-purple-100 text-purple-800" title="Pohon ini dialokasikan ke beberapa pesanan">
                                        Multi
                                    </span>
                                @endif
                            </td>
                            <td class="py-2.5 px-3 font-medium text-slate-800">
                                {{ $item['customer'] }}
                            </td>
                            <td class="py-2.5 px-3">
                                <div class="font-semibold text-slate-800">{{ $item['item_name'] }}</div>
                                @if($item['material'] !== '-')
                                    <div class="text-[10px] text-slate-500 font-medium">Material: <span class="font-semibold text-slate-700">{{ $item['material'] }}</span></div>
                                @endif
                            </td>
                            <td class="py-2.5 px-3 whitespace-nowrap font-mono text-slate-700">
                                {{ $item['spk_number'] }}
                            </td>
                            <td class="py-2.5 px-3 text-right whitespace-nowrap">
                                <div class="text-sm font-black text-emerald-700">
                                    {{ number_format($item['quantity_pcs']) }} PCS
                                </div>
                                @if($item['defect_pcs'] > 0)
                                    <div class="text-[9.5px] text-slate-400">
                                        (Bruto: {{ number_format($item['gross_pcs']) }}, Afkir: {{ number_format($item['defect_pcs']) }})
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-12 text-slate-400">
                                <div class="flex flex-col items-center justify-center gap-1.5">
                                    <i class="fas fa-inbox text-3xl text-slate-300"></i>
                                    @if($summary['is_filtered'])
                                        <p class="font-medium text-slate-600">Tidak ada Traveler Card yang memenuhi filter aktif.</p>
                                        <a href="{{ route('lost-wax.reports.ready-for-casting.index') }}" class="text-xs text-blue-600 hover:underline font-bold mt-1">
                                            Reset Filter
                                        </a>
                                    @else
                                        <p class="font-medium text-slate-600">Tidak ada persediaan cangkang pasca dewaxing saat ini.</p>
                                        <p class="text-[11px] text-slate-400">Traveler Card akan muncul setelah operator menyelesaikan pemindaian pada menu Scan Selesai Dewaxing.</p>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if($items->isNotEmpty())
                    <tfoot class="bg-slate-100 text-slate-800 font-bold border-t-2 border-slate-300 text-xs">
                        <tr>
                            <td colspan="7" class="py-2.5 px-3 text-right uppercase tracking-wider">
                                Total {{ $summary['is_filtered'] ? 'Hasil Filter' : 'Keseluruhan' }} ({{ number_format($summary['total_trees']) }} Pohon):
                            </td>
                            <td class="py-2.5 px-3 text-right text-emerald-800 font-black text-sm whitespace-nowrap">
                                {{ number_format($summary['total_usable_pcs']) }} PCS
                            </td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>

<!-- Floating Dropdown 1: Kode Produksi -->
<div id="codes-filter-dropdown" class="filter-dropdown-menu fixed hidden z-50 bg-white border border-slate-300 rounded-lg shadow-xl p-3 w-64 text-slate-800 text-[11px] no-print">
    <div class="flex items-center justify-between pb-1.5 mb-1.5 border-b border-slate-100">
        <span class="font-bold text-slate-700">Filter Kode Produksi</span>
        <button type="button" class="text-slate-400 hover:text-slate-600 font-bold text-sm leading-none" onclick="closeAllDropdowns()">&times;</button>
    </div>
    <div class="mb-2">
        <input type="text" placeholder="Cari kode..." class="w-full px-2 py-1 border border-slate-300 rounded text-[11px] filter-search-box focus:border-amber-500 focus:ring-amber-500" oninput="filterDropdownOptions('codes', this.value)">
    </div>
    <div class="mb-2 flex items-center justify-between">
        <label class="flex items-center gap-1.5 cursor-pointer font-semibold text-slate-600">
            <input type="checkbox" class="select-all-checkbox rounded border-slate-300 text-amber-600 focus:ring-amber-500" onchange="toggleSelectAll('codes', this.checked)">
            <span>(Select All)</span>
        </label>
        <button type="button" class="text-blue-600 hover:underline" onclick="clearAllOptions('codes')">Clear</button>
    </div>
    <div class="max-h-48 overflow-y-auto space-y-0.5 border-t border-b border-slate-100 py-1.5 option-list-container">
        @forelse($allCodes as $c)
            <label class="flex items-start gap-2 cursor-pointer py-0.5 px-1 rounded hover:bg-slate-50 option-item">
                <input type="checkbox" value="{{ $c }}" class="option-checkbox rounded border-slate-300 text-amber-600 focus:ring-amber-500" {{ in_array($c, $codes) ? 'checked' : '' }}>
                <span class="option-text font-mono font-bold text-slate-700">{{ $c }}</span>
            </label>
        @empty
            <div class="text-slate-400 text-center py-2">Tidak ada kode tersedia</div>
        @endforelse
    </div>
    <div class="flex justify-end gap-1.5 pt-2">
        <button type="button" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 rounded font-semibold text-[10px] text-slate-700" onclick="closeAllDropdowns()">Batal</button>
        <button type="button" class="px-2.5 py-1 bg-amber-600 hover:bg-amber-700 text-white rounded font-semibold text-[10px]" onclick="applyFilter('codes')">Terapkan</button>
    </div>
</div>

<!-- Floating Dropdown 2: Customer -->
<div id="customers-filter-dropdown" class="filter-dropdown-menu fixed hidden z-50 bg-white border border-slate-300 rounded-lg shadow-xl p-3 w-64 text-slate-800 text-[11px] no-print">
    <div class="flex items-center justify-between pb-1.5 mb-1.5 border-b border-slate-100">
        <span class="font-bold text-slate-700">Filter Customer</span>
        <button type="button" class="text-slate-400 hover:text-slate-600 font-bold text-sm leading-none" onclick="closeAllDropdowns()">&times;</button>
    </div>
    <div class="mb-2">
        <input type="text" placeholder="Cari customer..." class="w-full px-2 py-1 border border-slate-300 rounded text-[11px] filter-search-box focus:border-amber-500 focus:ring-amber-500" oninput="filterDropdownOptions('customers', this.value)">
    </div>
    <div class="mb-2 flex items-center justify-between">
        <label class="flex items-center gap-1.5 cursor-pointer font-semibold text-slate-600">
            <input type="checkbox" class="select-all-checkbox rounded border-slate-300 text-amber-600 focus:ring-amber-500" onchange="toggleSelectAll('customers', this.checked)">
            <span>(Select All)</span>
        </label>
        <button type="button" class="text-blue-600 hover:underline" onclick="clearAllOptions('customers')">Clear</button>
    </div>
    <div class="max-h-48 overflow-y-auto space-y-0.5 border-t border-b border-slate-100 py-1.5 option-list-container">
        @forelse($allCustomers as $cust)
            <label class="flex items-start gap-2 cursor-pointer py-0.5 px-1 rounded hover:bg-slate-50 option-item">
                <input type="checkbox" value="{{ $cust }}" class="option-checkbox rounded border-slate-300 text-amber-600 focus:ring-amber-500" {{ in_array($cust, $customers) ? 'checked' : '' }}>
                <span class="option-text font-bold text-slate-700">{{ $cust }}</span>
            </label>
        @empty
            <div class="text-slate-400 text-center py-2">Tidak ada customer tersedia</div>
        @endforelse
    </div>
    <div class="flex justify-end gap-1.5 pt-2">
        <button type="button" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 rounded font-semibold text-[10px] text-slate-700" onclick="closeAllDropdowns()">Batal</button>
        <button type="button" class="px-2.5 py-1 bg-amber-600 hover:bg-amber-700 text-white rounded font-semibold text-[10px]" onclick="applyFilter('customers')">Terapkan</button>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function(){
        document.querySelectorAll('.filter-dropdown-trigger').forEach(function(btn){
            btn.addEventListener('click', function(e){
                e.stopPropagation();
                const type = this.getAttribute('data-filter-type');
                showDropdown(type, this);
            });
        });

        document.addEventListener('click', function(e){
            if (!e.target.closest('.filter-dropdown-menu')) {
                closeAllDropdowns();
            }
        });

        document.querySelectorAll('.filter-dropdown-menu').forEach(function(menu){
            menu.addEventListener('click', function(e){
                e.stopPropagation();
            });
        });
    });

    function showDropdown(type, button) {
        const dropdown = document.getElementById(type + '-filter-dropdown');
        if (!dropdown) return;
        const isCurrentlyOpen = !dropdown.classList.contains('hidden');
        closeAllDropdowns();
        if (isCurrentlyOpen) return;

        dropdown.classList.remove('hidden');

        const rect = button.getBoundingClientRect();
        const dropdownWidth = 260;
        let left = rect.left;
        let top = rect.bottom + 4;

        if (left + dropdownWidth > window.innerWidth) {
            left = window.innerWidth - dropdownWidth - 10;
        }
        if (left < 10) left = 10;

        dropdown.style.left = left + 'px';
        dropdown.style.top = top + 'px';
    }

    function closeAllDropdowns() {
        document.querySelectorAll('.filter-dropdown-menu').forEach(function(menu){
            menu.classList.add('hidden');
        });
    }

    function filterDropdownOptions(type, query) {
        const dropdown = document.getElementById(type + '-filter-dropdown');
        if (!dropdown) return;
        const items = dropdown.querySelectorAll('.option-item');
        const q = query.toLowerCase().trim();
        items.forEach(function(item){
            const text = item.querySelector('.option-text').textContent.toLowerCase();
            if (text.includes(q)) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    }

    function toggleSelectAll(type, checked) {
        const dropdown = document.getElementById(type + '-filter-dropdown');
        if (!dropdown) return;
        dropdown.querySelectorAll('.option-item').forEach(function(item){
            if (item.style.display !== 'none') {
                const cb = item.querySelector('.option-checkbox');
                if (cb) cb.checked = checked;
            }
        });
    }

    function clearAllOptions(type) {
        const dropdown = document.getElementById(type + '-filter-dropdown');
        if (!dropdown) return;
        dropdown.querySelectorAll('.option-checkbox').forEach(function(cb){
            cb.checked = false;
        });
        const selectAll = dropdown.querySelector('.select-all-checkbox');
        if (selectAll) selectAll.checked = false;
    }

    function applyFilter(type) {
        const dropdown = document.getElementById(type + '-filter-dropdown');
        if (!dropdown) return;
        const checkedValues = [];
        dropdown.querySelectorAll('.option-checkbox').forEach(function(cb){
            if (cb.checked) {
                checkedValues.push(cb.value);
            }
        });

        const urlParams = new URLSearchParams(window.location.search);
        urlParams.delete(type + '[]');
        urlParams.delete(type);

        checkedValues.forEach(function(val){
            urlParams.append(type + '[]', val);
        });

        window.location.href = window.location.pathname + '?' + urlParams.toString();
    }

    function removeFilterValue(type, value) {
        const urlParams = new URLSearchParams(window.location.search);
        const values = urlParams.getAll(type + '[]');
        urlParams.delete(type + '[]');
        urlParams.delete(type);

        values.forEach(function(val){
            if (val !== value) {
                urlParams.append(type + '[]', val);
            }
        });

        window.location.href = window.location.pathname + '?' + urlParams.toString();
    }

    function clearGlobalSearch() {
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.delete('search');
        window.location.href = window.location.pathname + '?' + urlParams.toString();
    }
</script>
@endsection

