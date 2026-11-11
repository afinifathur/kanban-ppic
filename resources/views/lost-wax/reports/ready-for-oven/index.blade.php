@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full flex-wrap gap-2">
        <div>
            <div class="text-[11px] font-bold text-slate-500 tracking-wider uppercase">PT. PERONI KARYA SENTRA &bull; KANBAN PPIC LOST WAX</div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">LOST WAX &mdash; ANTREAN SIAP OVEN DEWAXING</h1>
            <p class="text-gray-500 text-[10px]">Monitoring antrean pohon cangkang yang telah tuntas pelapisan dan siap masuk ke oven untuk proses pelelehan lilin (FIFO)</p>
        </div>
        <div class="flex items-center gap-2 no-print">
            <a href="{{ route('lost-wax.reports.ready-for-oven.print', request()->query()) }}" target="_blank"
                class="text-xs bg-rose-600 hover:bg-rose-700 text-white font-bold py-1.5 px-3 rounded shadow-sm flex items-center gap-1.5"
                title="Cetak data sesuai filter yang sedang aktif">
                <i class="fas fa-print"></i>
                <span>Cetak Hasil Filter</span>
            </a>
            <a href="{{ route('lost-wax.reports.ready-for-oven.print', ['po_status' => 'all', 'daily_target_kg' => request('daily_target_kg')]) }}" target="_blank"
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
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
        <!-- 1. Pohon Fisik Unik -->
        <div class="bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm">
            <div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center justify-between">
                <span>Pohon Fisik Unik</span>
                @if($summary['is_filtered'])
                    <span class="text-[9px] font-bold px-1.5 py-0.2 rounded bg-rose-100 text-rose-800">Filter</span>
                @endif
            </div>
            <div class="text-2xl font-black text-slate-800 mt-1">{{ number_format($summary['total_unique_trees']) }} <span class="text-xs font-normal text-slate-500">Pohon</span></div>
            <div class="text-[10px] text-slate-400 mt-1 flex items-center gap-1">
                <i class="fas fa-barcode text-slate-400"></i>
                <span>Total antrean: <strong>{{ number_format($summary['total_rangkai']) }}</strong> rangkai</span>
            </div>
        </div>

        <!-- 2. Kuantitas PCS & Pemenuhan PO -->
        <div class="bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm">
            <div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center justify-between">
                <span>Total Siap Oven / PO</span>
                @if($summary['is_filtered'])
                    <span class="text-[9px] font-bold px-1.5 py-0.2 rounded bg-rose-100 text-rose-800">Filter</span>
                @endif
            </div>
            <div class="text-2xl font-black text-emerald-600 mt-1">
                {{ number_format($summary['total_usable_pcs']) }} <span class="text-xs font-normal text-slate-500">/ {{ number_format($summary['total_po_pcs']) }} Pcs</span>
            </div>
            <div class="text-[10px] text-slate-500 mt-1 flex items-center justify-between gap-1 flex-wrap">
                <span>Bruto: {{ number_format($summary['total_gross_pcs']) }} | Afkir: {{ number_format($summary['total_defect_pcs']) }}</span>
                @if($summary['overall_po_variance_text'])
                    <span class="font-bold {{ $summary['overall_po_variance_status'] === 'KURANG' ? 'text-rose-600' : 'text-emerald-700' }}">
                        ({{ $summary['overall_po_variance_text'] }})
                    </span>
                @endif
            </div>
        </div>

        <!-- 3. Total Berat Siap Oven (KG) -->
        <div class="bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm">
            <div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center justify-between">
                <span>Total Berat Siap Oven</span>
                @if($summary['is_filtered'])
                    <span class="text-[9px] font-bold px-1.5 py-0.2 rounded bg-rose-100 text-rose-800">Filter</span>
                @endif
            </div>
            <div class="text-2xl font-black text-slate-800 mt-1">{{ $summary['total_weight_kg_formatted'] }} <span class="text-xs font-normal text-slate-500">Kg</span></div>
            <div class="text-[10px] mt-1">
                @if($summary['has_incomplete_weights'])
                    <span class="text-amber-600 font-semibold flex items-center gap-1" title="Terdapat item yang belum memiliki berat master pada Production Plan">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>{{ $summary['groups_without_weight_count'] }} kelompok belum ada berat</span>
                    </span>
                @else
                    <span class="text-slate-400">
                        Dari <strong>{{ number_format($summary['groups_with_weight_count']) }}</strong> kelompok item valid
                    </span>
                @endif
            </div>
        </div>

        <!-- 4. Estimasi Coverage Days -->
        <div class="bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm">
            <div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center justify-between">
                <span>Coverage Days</span>
                @if($summary['is_filtered'])
                    <span class="text-[9px] font-bold px-1.5 py-0.2 rounded bg-rose-100 text-rose-800">Filter</span>
                @endif
            </div>
            <div class="text-2xl font-black text-indigo-700 mt-1">{{ $summary['coverage_days_formatted'] }} <span class="text-xs font-normal text-slate-500">Hari</span></div>
            <div class="text-[10px] text-slate-500 mt-1 flex flex-col">
                <span>Target: <strong>{{ $summary['daily_target_kg_formatted'] }}</strong> kg/hari</span>
                @if($summary['has_incomplete_weights'])
                    <span class="text-amber-600 text-[9px] font-medium">*Berdasarkan berat tersedia</span>
                @endif
            </div>
        </div>

        <!-- 5. Prioritas Antrean FIFO -->
        <div class="bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm">
            <div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Prioritas Antrean</div>
            <div class="text-sm font-bold text-slate-800 mt-1">FIFO (Selesai Terawal)</div>
            <div class="text-[10px] text-slate-500 mt-1">
                {{ number_format($summary['total_groups']) }} kode produksi
                @if($summary['is_filtered'])
                    <span class="text-slate-400">(dari {{ number_format($summary['queue_total_groups']) }})</span>
                @endif
                &bull; {{ number_format($summary['total_spk_count']) }} SPK
            </div>
        </div>
    </div>

    <!-- Filter & Simulation Toolbar (Production Status Dropdown Pattern) -->
    <div class="bg-white p-3 rounded-lg border border-slate-200 shadow-sm flex flex-col md:flex-row items-center justify-between gap-3 no-print">
        <div class="flex flex-wrap items-center gap-2.5 w-full md:w-auto flex-1">
            <!-- Global Keyword Search Form -->
            <form method="GET" action="{{ route('lost-wax.reports.ready-for-oven.index') }}" class="w-full sm:w-auto sm:min-w-[260px] relative">
                <input type="hidden" name="po_status" value="{{ $poStatus }}">
                @foreach($codes as $c)<input type="hidden" name="codes[]" value="{{ $c }}">@endforeach
                @foreach($customers as $cust)<input type="hidden" name="customers[]" value="{{ $cust }}">@endforeach
                @if(request('daily_target_kg'))<input type="hidden" name="daily_target_kg" value="{{ request('daily_target_kg') }}">@endif

                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                <input type="text" name="search" value="{{ $search }}"
                    placeholder="Cari Kode, Item, SPK Rangkai, Barcode..."
                    class="w-full pl-8 pr-3 py-1.5 rounded border border-slate-300 text-xs focus:border-rose-500 focus:ring-rose-500 bg-white">
            </form>

            <!-- Dropdown Trigger: Kode Produksi -->
            <button type="button" class="filter-dropdown-trigger text-xs bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold py-1.5 px-3 rounded flex items-center gap-1.5 transition-colors cursor-pointer {{ !empty($codes) ? 'border-amber-500 text-amber-700 bg-amber-50/30' : '' }}" data-filter-type="codes">
                <i class="fas fa-barcode text-slate-500"></i>
                <span>Kode Produksi</span>
                @if(!empty($codes))
                    <span class="bg-amber-100 text-amber-800 text-[10px] px-1.5 py-0.2 rounded-full font-black">{{ count($codes) }}</span>
                @endif
                <i class="fas fa-chevron-down text-[9px] text-slate-400 ml-0.5"></i>
            </button>

            <!-- Dropdown Trigger: Status PO -->
            <button type="button" class="filter-dropdown-trigger text-xs bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold py-1.5 px-3 rounded flex items-center gap-1.5 transition-colors cursor-pointer {{ $poStatus !== 'all' ? 'border-amber-500 text-amber-700 bg-amber-50/30' : '' }}" data-filter-type="po_status">
                <i class="fas fa-tasks text-slate-500"></i>
                <span>Status PO</span>
                @if($poStatus !== 'all')
                    <span class="bg-amber-100 text-amber-800 text-[10px] px-1.5 py-0.2 rounded-full font-bold uppercase">{{ $summary['po_status_label'] }}</span>
                @endif
                <i class="fas fa-chevron-down text-[9px] text-slate-400 ml-0.5"></i>
            </button>

            <!-- Dropdown Trigger: Customer -->
            <button type="button" class="filter-dropdown-trigger text-xs bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold py-1.5 px-3 rounded flex items-center gap-1.5 transition-colors cursor-pointer {{ !empty($customers) ? 'border-amber-500 text-amber-700 bg-amber-50/30' : '' }}" data-filter-type="customers">
                <i class="fas fa-user-tie text-slate-500"></i>
                <span>Customer</span>
                @if(!empty($customers))
                    <span class="bg-amber-100 text-amber-800 text-[10px] px-1.5 py-0.2 rounded-full font-black">{{ count($customers) }}</span>
                @endif
                <i class="fas fa-chevron-down text-[9px] text-slate-400 ml-0.5"></i>
            </button>

            <!-- Daily Target Simulation Input -->
            <form method="GET" action="{{ route('lost-wax.reports.ready-for-oven.index') }}" class="flex items-center gap-1.5 bg-slate-50 border border-slate-300 px-2 py-1 rounded">
                <input type="hidden" name="search" value="{{ $search }}">
                <input type="hidden" name="po_status" value="{{ $poStatus }}">
                @foreach($codes as $c)<input type="hidden" name="codes[]" value="{{ $c }}">@endforeach
                @foreach($customers as $cust)<input type="hidden" name="customers[]" value="{{ $cust }}">@endforeach

                <span class="text-[11px] font-semibold text-slate-600 whitespace-nowrap">Target Harian:</span>
                <input type="number" step="100" min="1" name="daily_target_kg" value="{{ $summary['daily_target_kg'] }}"
                    title="Ubah target harian (kg) untuk simulasi Coverage Days"
                    class="w-20 px-1.5 py-0.5 text-xs border border-slate-300 rounded text-right font-bold focus:outline-none focus:ring-1 focus:ring-rose-500">
                <span class="text-[10px] text-slate-500 font-bold">kg/hari</span>
                <button type="submit" class="bg-slate-700 hover:bg-slate-800 text-white text-[10px] font-bold py-0.5 px-2 rounded ml-1">
                    Set
                </button>
            </form>

            @if($search || request('daily_target_kg') || $summary['is_filtered'])
                <a href="{{ route('lost-wax.reports.ready-for-oven.index') }}" class="text-xs text-rose-600 hover:underline font-bold ml-1">
                    Reset
                </a>
            @endif
        </div>

        <div class="text-[11px] text-slate-500 text-right w-full md:w-auto">
            <span>Perbarui data: <strong>{{ $summary['generated_at_formatted'] }}</strong> WIB</span>
        </div>
    </div>

    <!-- Active Filters Indicator Bar (Like Production Status) -->
    @if($summary['is_filtered'] || $search !== '')
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

            @if($poStatus !== 'all')
                <span class="inline-flex items-center gap-1 bg-emerald-100 text-emerald-800 px-2.5 py-0.5 rounded-full font-bold">
                    Status PO: {{ $summary['po_status_label'] }}
                    <button type="button" class="hover:text-red-500 font-bold ml-1 text-emerald-700" onclick="removeSingleFilter('po_status')">&times;</button>
                </span>
            @endif

            @foreach($customers as $cust)
                <span class="inline-flex items-center gap-1 bg-blue-100 text-blue-800 px-2.5 py-0.5 rounded-full font-bold">
                    Cust: {{ $cust }}
                    <button type="button" class="hover:text-red-500 font-bold ml-1 text-blue-700" onclick="removeFilterValue('customers', '{{ $cust }}')">&times;</button>
                </span>
            @endforeach

            <span class="text-slate-400 text-[10px] ml-2">
                (Menampilkan <strong>{{ $summary['total_groups'] }}</strong> dari {{ $summary['queue_total_groups'] }} total kode)
            </span>

            <a href="{{ route('lost-wax.reports.ready-for-oven.index') }}" class="text-blue-600 hover:underline font-bold text-xs ml-auto">
                Clear All
            </a>
        </div>
    @endif

    <!-- Antrean Rules & Information Disclaimer -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-[11px] text-blue-900 flex items-start gap-2.5">
        <i class="fas fa-info-circle text-blue-500 text-sm mt-0.5 flex-shrink-0"></i>
        <div class="space-y-0.5">
            <span class="font-bold">Mekanisme Antrean FIFO &amp; Pemenuhan PO:</span>
            <p class="text-blue-800 leading-relaxed">
                Antrean diurutkan berdasarkan <strong>FIFO (Waktu Selesai Lapisan Terawal)</strong> per Kode Produksi. Kolom <strong>SIAP OVEN / PO</strong> menyajikan total PCS pohon yang siap masuk oven dibandingkan dengan target pesanan (PO) resmi. Klik tombol <strong>Lihat Rincian</strong> untuk melihat pembagian pohon per SPK Rangkai, nomor barcode fisik, dan lokasi rak.
            </p>
        </div>
    </div>

    <!-- Main Data Table -->
    <div class="bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs border-collapse">
                <thead>
                    <tr class="bg-slate-800 text-white font-bold text-[11px] tracking-wider uppercase">
                        <th class="py-2.5 px-3 text-center w-12 border-b border-slate-700">No</th>
                        <th class="py-2.5 px-3 border-b border-slate-700">Kode Produksi</th>
                        <th class="py-2.5 px-3 border-b border-slate-700">Nama Item</th>
                        <th class="py-2.5 px-3 border-b border-slate-700">Customer</th>
                        <th class="py-2.5 px-3 text-center border-b border-slate-700">Jumlah Rangkai</th>
                        <th class="py-2.5 px-3 text-right border-b border-slate-700">SIAP OVEN / PO</th>
                        <th class="py-2.5 px-3 text-center border-b border-slate-700">Waktu Selesai (FIFO)</th>
                        <th class="py-2.5 px-3 text-center border-b border-slate-700 w-28">Rincian</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 text-slate-700">
                    @forelse($items as $index => $item)
                        <!-- Master Row (One per Production Code) -->
                        <tr class="hover:bg-slate-50 transition-colors">
                            <!-- No -->
                            <td class="py-2.5 px-3 text-center font-bold text-slate-500 align-middle">
                                {{ $index + 1 }}
                            </td>

                            <!-- Kode Produksi -->
                            <td class="py-2.5 px-3 font-semibold text-slate-900 align-middle">
                                <div class="text-sm font-bold text-slate-900 font-mono">{{ $item['production_code'] }}</div>
                                <div class="text-[10px] text-slate-500 font-sans mt-0.5">
                                    {{ $item['work_order_count'] }} SPK Rangkai
                                </div>
                            </td>

                            <!-- Nama Item -->
                            <td class="py-2.5 px-3 text-slate-800 max-w-xs align-middle font-medium" title="{{ $item['item_name'] }}">
                                {{ $item['item_name'] }}
                            </td>

                            <!-- Customer -->
                            <td class="py-2.5 px-3 text-slate-700 align-middle">
                                <span class="inline-block bg-slate-100 text-slate-800 px-2 py-0.5 rounded text-[11px] font-bold">
                                    {{ $item['customer'] }}
                                </span>
                            </td>

                            <!-- Jumlah Rangkai (Pohon Fisik) -->
                            <td class="py-2.5 px-3 text-center font-bold text-slate-800 align-middle">
                                <div class="text-base font-black text-slate-900">{{ number_format($item['tree_count']) }}</div>
                                <div class="text-[9.5px] font-normal text-slate-500">pohon</div>
                            </td>

                            <!-- SIAP OVEN / PO (One Aggregate per Code) -->
                            <td class="py-2.5 px-3 text-right font-bold text-slate-900 align-middle">
                                <div class="text-base font-black text-slate-900 tracking-tight">
                                    <span class="text-emerald-700">{{ number_format($item['quantity_pcs'], 0, ',', '.') }}</span>
                                    <span class="text-slate-400 font-normal">/</span>
                                    <span class="text-slate-700">{{ $item['po_quantity'] !== null ? number_format($item['po_quantity'], 0, ',', '.') : '-' }}</span>
                                    <span class="text-[11px] font-bold text-slate-500">PCS</span>
                                </div>

                                <!-- Variance Badge -->
                                <div class="mt-1">
                                    @if($item['po_quantity'] !== null)
                                        @if($item['po_variance_status'] === 'LEBIH')
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-300">
                                                <i class="fas fa-arrow-up text-[9px]"></i> {{ $item['po_variance_text'] }}
                                            </span>
                                        @elseif($item['po_variance_status'] === 'KURANG')
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-800 border border-rose-300">
                                                <i class="fas fa-arrow-down text-[9px]"></i> {{ $item['po_variance_text'] }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-300">
                                                <i class="fas fa-check text-[9px]"></i> Sesuai PO
                                            </span>
                                        @endif
                                    @else
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-300" title="Kuantitas PO belum ditentukan pada Production Plan">
                                            <i class="fas fa-exclamation-circle text-[9px]"></i> PO belum ditentukan
                                        </span>
                                    @endif
                                </div>
                            </td>

                            <!-- Waktu Selesai Lapisan (FIFO) -->
                            <td class="py-2.5 px-3 text-center font-mono text-[11px] text-slate-700 align-middle">
                                <div class="font-bold text-slate-800">{{ $item['finished_at_formatted'] }}</div>
                                <span class="inline-block mt-0.5 px-1.5 py-0.2 rounded text-[9px] font-sans font-semibold {{ $item['finished_stage'] === 'layer_7' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' }}">
                                    {{ $item['finished_stage_label'] }}
                                </span>
                                @if($item['has_mixed_times'])
                                    <div class="text-[9px] text-slate-400 font-sans mt-0.5">
                                        s/d {{ $item['latest_finished_at']->format('H:i') }}
                                    </div>
                                @endif
                            </td>

                            <!-- Rincian SPK Toggle -->
                            <td class="py-2.5 px-3 text-center align-middle">
                                <button type="button" onclick="toggleDetails('details-{{ $index }}')"
                                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded text-[11px] font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-300 transition-colors cursor-pointer">
                                    <i class="fas fa-list-ul text-[10px] text-slate-500"></i>
                                    <span>Lihat Rincian</span>
                                    <i class="fas fa-chevron-down text-[9px] transition-transform duration-200" id="icon-details-{{ $index }}"></i>
                                </button>
                            </td>
                        </tr>

                        <!-- Sub-Row: Detail SPK & Keterlacakan Pohon -->
                        <tr id="details-{{ $index }}" class="hidden bg-slate-50 border-b border-slate-200">
                            <td colspan="8" class="p-3 bg-slate-50 border-t border-slate-200">
                                <div class="rounded border border-slate-200 bg-white p-3 space-y-2 shadow-inner">
                                    <!-- Header Sub-Table -->
                                    <div class="flex items-center justify-between pb-2 border-b border-slate-100 flex-wrap gap-2">
                                        <div class="text-xs font-bold text-slate-800 flex items-center gap-2">
                                            <i class="fas fa-layer-group text-indigo-600"></i>
                                            <span>Rincian SPK &amp; Keterlacakan Pohon — Kode Produksi: <strong>{{ $item['production_code'] }}</strong></span>
                                            <span class="text-[10px] font-normal text-slate-500">({{ count($item['work_orders']) }} SPK &bull; {{ number_format($item['tree_count']) }} Pohon &bull; {{ number_format($item['quantity_pcs']) }} PCS Siap Oven)</span>
                                        </div>
                                        <div class="text-[10px] text-slate-500">
                                            Diurutkan berdasarkan waktu selesai lapisan terawal (FIFO)
                                        </div>
                                    </div>

                                    <!-- Table of SPKs -->
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-left text-xs border-collapse">
                                            <thead>
                                                <tr class="bg-slate-100 text-slate-600 font-bold text-[10px] uppercase border-b border-slate-200">
                                                    <th class="py-1.5 px-2.5 w-10 text-center">No</th>
                                                    <th class="py-1.5 px-2.5">Nomor SPK Rangkai</th>
                                                    <th class="py-1.5 px-2.5 text-center">Jumlah Pohon</th>
                                                    <th class="py-1.5 px-2.5 text-right">Siap Oven (PCS)</th>
                                                    <th class="py-1.5 px-2.5 text-center">Waktu Selesai Coating</th>
                                                    <th class="py-1.5 px-2.5 text-center">Lokasi Rak</th>
                                                    <th class="py-1.5 px-2.5">Barcode Pohon Fisik</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                                @foreach($item['work_orders'] as $wIdx => $wo)
                                                    <tr class="hover:bg-slate-50">
                                                        <td class="py-2 px-2.5 text-center text-slate-400 font-bold text-[11px]">{{ $wIdx + 1 }}</td>
                                                        <td class="py-2 px-2.5 font-bold font-mono text-indigo-700 text-xs">
                                                            <i class="fas fa-file-alt text-[9px] mr-1 text-indigo-400"></i>{{ $wo['work_order_number'] }}
                                                        </td>
                                                        <td class="py-2 px-2.5 text-center font-bold text-slate-800">{{ $wo['tree_count'] }} pohon</td>
                                                        <td class="py-2 px-2.5 text-right font-black text-emerald-700 text-xs">{{ number_format($wo['quantity_pcs']) }} PCS</td>
                                                        <td class="py-2 px-2.5 text-center font-mono text-slate-600 text-[11px]">{{ $wo['finished_at_formatted'] }}</td>
                                                        <td class="py-2 px-2.5 text-center">
                                                            @if($wo['racks_compact'] !== '-')
                                                                <span class="inline-block bg-slate-100 text-slate-700 border border-slate-200 px-1.5 py-0.5 rounded text-[10px] font-bold">
                                                                    {{ $wo['racks_compact'] }}
                                                                </span>
                                                            @else
                                                                <span class="text-slate-400 text-xs">-</span>
                                                            @endif
                                                        </td>
                                                        <td class="py-2 px-2.5">
                                                            <div class="flex flex-wrap gap-1 max-w-lg">
                                                                @foreach($wo['barcodes_with_rack'] as $bcr)
                                                                    <span class="inline-block px-1.5 py-0.2 rounded font-mono text-[9.5px] bg-slate-100 text-slate-700 border border-slate-200">
                                                                        {{ $bcr }}
                                                                    </span>
                                                                @endforeach
                                                            </div>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-12 text-center text-slate-500">
                                <i class="fas fa-inbox text-3xl mb-2 text-slate-400 block"></i>
                                @if($summary['is_filtered'] || $search !== '')
                                    <div class="font-bold text-slate-700">Tidak ada kode produksi yang memenuhi kriteria filter.</div>
                                    <div class="text-xs text-slate-400 mt-1">Coba sesuaikan pilihan filter dropdown atau atur ulang pencarian.</div>
                                    <div class="mt-3">
                                        <a href="{{ route('lost-wax.reports.ready-for-oven.index') }}"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold rounded shadow-sm">
                                            <i class="fas fa-undo text-[10px]"></i>
                                            <span>Reset Semua Filter</span>
                                        </a>
                                    </div>
                                @else
                                    <div class="font-medium text-slate-600">Tidak ada antrean barang yang siap masuk Oven saat ini.</div>
                                    <div class="text-xs text-slate-400 mt-1">Pohon fisik yang selesai pelapisan (Layer 6/7) akan otomatis tampil di sini.</div>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if($items->isNotEmpty())
                    <tfoot class="bg-slate-100 font-bold text-slate-800 text-[11px] border-t-2 border-slate-300">
                        <tr>
                            <td colspan="4" class="py-3 px-3 text-right uppercase tracking-wider text-slate-600">
                                TOTAL {{ $summary['is_filtered'] ? 'HASIL FILTER' : 'KESELURUHAN' }}:
                            </td>
                            <!-- Total Rangkai -->
                            <td class="py-3 px-3 text-center text-slate-900">
                                <div class="text-base font-black">{{ number_format($summary['total_rangkai']) }}</div>
                                <div class="text-[9.5px] font-normal text-slate-500 leading-tight">({{ number_format($summary['total_unique_trees']) }} Pohon Fisik*)</div>
                            </td>
                            <!-- Total Siap Oven / PO -->
                            <td class="py-3 px-3 text-right text-slate-900">
                                <div class="text-base font-black text-emerald-700">
                                    {{ number_format($summary['total_usable_pcs'], 0, ',', '.') }} / {{ number_format($summary['total_po_pcs'], 0, ',', '.') }} <span class="text-xs font-bold text-slate-600">PCS</span>
                                </div>
                                @if($summary['overall_po_variance_text'])
                                    <div class="text-[10px] font-bold {{ $summary['overall_po_variance_status'] === 'KURANG' ? 'text-rose-600' : 'text-emerald-700' }} mt-0.5">
                                        {{ $summary['overall_po_variance_text'] }}
                                    </div>
                                @endif
                                @if($summary['has_incomplete_po'])
                                    <div class="text-[9px] font-medium text-amber-600 leading-tight">
                                        *{{ $summary['codes_without_po_count'] }} kode belum ada PO
                                    </div>
                                @endif
                            </td>
                            <!-- Coverage Days Note & Total Berat -->
                            <td class="py-3 px-3 text-center text-[10px] text-indigo-700 font-bold">
                                <div>Coverage: {{ $summary['coverage_days_formatted'] }} Hari</div>
                                <div class="text-[9px] font-normal text-slate-500">Berat: {{ $summary['total_weight_kg_formatted'] }} kg</div>
                            </td>
                            <!-- Kelompok Barang & SPK -->
                            <td class="py-3 px-3 text-center text-[10px] text-slate-600">
                                <div><strong>{{ $summary['total_groups'] }}</strong> Kode Produksi</div>
                                <div class="text-[9.5px] text-slate-500 font-normal">({{ number_format($summary['total_spk_count']) }} SPK)</div>
                            </td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>

{{-- Excel Dropdowns (Like Production Status Pattern) --}}
<!-- Dropdown 1: Kode Produksi -->
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
        <button type="button" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 rounded font-semibold text-[10px] text-slate-700" onclick="closeAllDropdowns()">Cancel</button>
        <button type="button" class="px-2.5 py-1 bg-amber-600 hover:bg-amber-700 text-white rounded font-semibold text-[10px]" onclick="applyFilter('codes')">Apply</button>
    </div>
</div>

<!-- Dropdown 2: Status PO -->
<div id="po_status-filter-dropdown" class="filter-dropdown-menu fixed hidden z-50 bg-white border border-slate-300 rounded-lg shadow-xl p-3 w-64 text-slate-800 text-[11px] no-print">
    <div class="flex items-center justify-between pb-1.5 mb-1.5 border-b border-slate-100">
        <span class="font-bold text-slate-700">Filter Status Pemenuhan PO</span>
        <button type="button" class="text-slate-400 hover:text-slate-600 font-bold text-sm leading-none" onclick="closeAllDropdowns()">&times;</button>
    </div>
    <div class="space-y-1.5 py-1.5">
        @php
            $statusOptions = [
                'all' => ['label' => 'ALL', 'desc' => 'Tampilkan seluruh antrean'],
                'completed' => ['label' => 'COMPLETED', 'desc' => 'Siap Oven > PO (Lebih PO)'],
                'kurang' => ['label' => 'KURANG', 'desc' => 'Siap Oven < PO (Kurang PO)'],
                'sesuai' => ['label' => 'SESUAI', 'desc' => 'Siap Oven == PO (Sesuai PO)'],
                'belum_ditentukan' => ['label' => 'PO BELUM DITENTUKAN', 'desc' => 'PO tidak tersedia'],
            ];
        @endphp
        @foreach($statusOptions as $val => $opt)
            <label class="flex items-start gap-2 cursor-pointer py-1 px-1.5 rounded hover:bg-slate-50 transition-colors">
                <input type="radio" name="po_status_radio" value="{{ $val }}" class="mt-0.5 text-amber-600 focus:ring-amber-500" {{ $poStatus === $val ? 'checked' : '' }}>
                <div>
                    <div class="font-bold text-slate-800">{{ $opt['label'] }}</div>
                    <div class="text-[10px] text-slate-500">{{ $opt['desc'] }}</div>
                </div>
            </label>
        @endforeach
    </div>
    <div class="flex justify-end gap-1.5 pt-2 border-t border-slate-100">
        <button type="button" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 rounded font-semibold text-[10px] text-slate-700" onclick="closeAllDropdowns()">Cancel</button>
        <button type="button" class="px-2.5 py-1 bg-amber-600 hover:bg-amber-700 text-white rounded font-semibold text-[10px]" onclick="applySingleFilter('po_status')">Apply</button>
    </div>
</div>

<!-- Dropdown 3: Customer -->
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
        <button type="button" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 rounded font-semibold text-[10px] text-slate-700" onclick="closeAllDropdowns()">Cancel</button>
        <button type="button" class="px-2.5 py-1 bg-amber-600 hover:bg-amber-700 text-white rounded font-semibold text-[10px]" onclick="applyFilter('customers')">Apply</button>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function(){
        // Excel dropdown trigger setup
        document.querySelectorAll('.filter-dropdown-trigger').forEach(function(button){
            button.addEventListener('click', function(e){
                e.stopPropagation();
                const type = this.dataset.filterType;
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

    function applySingleFilter(paramName) {
        const dropdown = document.getElementById(paramName + '-filter-dropdown');
        if (!dropdown) return;
        const checkedRadio = dropdown.querySelector('input[type="radio"]:checked');
        const urlParams = new URLSearchParams(window.location.search);
        if (checkedRadio && checkedRadio.value && checkedRadio.value !== 'all') {
            urlParams.set(paramName, checkedRadio.value);
        } else {
            urlParams.delete(paramName);
        }
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

    function removeSingleFilter(paramName) {
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.delete(paramName);
        window.location.href = window.location.pathname + '?' + urlParams.toString();
    }

    function clearGlobalSearch() {
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.delete('search');
        window.location.href = window.location.pathname + '?' + urlParams.toString();
    }

    function toggleDetails(rowId) {
        const row = document.getElementById(rowId);
        const icon = document.getElementById('icon-' + rowId);
        if (row) {
            row.classList.toggle('hidden');
            if (icon) {
                icon.classList.toggle('rotate-180');
            }
        }
    }
</script>
@endsection
