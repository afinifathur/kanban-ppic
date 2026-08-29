@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">REKAP KERUSAKAN LOST WAX</h1>
            <p class="text-gray-500 text-[10px]">Laporan harian cacat produk (Daily Defect Report) dari proses Cetak, Rangkai, Lapisan 1–7, hingga Oven</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('lost-wax.quality.defects.export.excel', request()->query()) }}" class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-bold text-xs rounded-lg shadow-sm transition-all flex items-center gap-1.5" title="Download Excel">
                <i class="fas fa-file-excel"></i>
                <span>Export Excel</span>
            </a>
            <a href="{{ route('lost-wax.quality.defects.export.pdf', request()->query()) }}" target="_blank" class="px-3.5 py-2 bg-slate-800 hover:bg-slate-900 active:bg-slate-950 text-white font-bold text-xs rounded-lg shadow-sm transition-all flex items-center gap-1.5" title="Cetak / Export PDF">
                <i class="fas fa-print"></i>
                <span>Cetak / PDF</span>
            </a>
        </div>
    </div>
@endsection

@section('content')
<div class="max-w-7xl mx-auto space-y-6">

    {{-- Filter Panel --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <form action="{{ route('lost-wax.quality.defects.index') }}" method="GET" class="space-y-4">
            @if(!empty($activeFilters['production_code']))
                <input type="hidden" name="production_code" value="{{ $activeFilters['production_code'] }}">
            @endif

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                {{-- 1. Date From --}}
                <div>
                    <label for="date_from" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1">
                        <i class="fas fa-calendar-alt text-blue-600 mr-1"></i> Tanggal Dari
                    </label>
                    <input
                        type="date"
                        name="date_from"
                        id="date_from"
                        value="{{ $activeFilters['date_from'] }}"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs font-semibold focus:bg-white focus:border-blue-600 focus:ring-2 focus:ring-blue-100 outline-none"
                    >
                </div>

                {{-- 2. Date To --}}
                <div>
                    <label for="date_to" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1">
                        <i class="fas fa-calendar-alt text-blue-600 mr-1"></i> Tanggal Sampai
                    </label>
                    <input
                        type="date"
                        name="date_to"
                        id="date_to"
                        value="{{ $activeFilters['date_to'] }}"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs font-semibold focus:bg-white focus:border-blue-600 focus:ring-2 focus:ring-blue-100 outline-none"
                    >
                </div>

                {{-- 3. Stage Dropdown --}}
                <div>
                    <label for="stage" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1">
                        <i class="fas fa-layer-group text-blue-600 mr-1"></i> Tahapan (Stage)
                    </label>
                    <select
                        name="stage"
                        id="stage"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs font-semibold focus:bg-white focus:border-blue-600 focus:ring-2 focus:ring-blue-100 outline-none"
                    >
                        <option value="all" {{ $activeFilters['stage'] === 'all' ? 'selected' : '' }}>Semua Tahapan</option>
                        @foreach($stages as $stKey => $stLabel)
                            <option value="{{ $stKey }}" {{ $activeFilters['stage'] === $stKey ? 'selected' : '' }}>{{ $stLabel }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- 4. Search Input --}}
                <div>
                    <label for="search" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1">
                        <i class="fas fa-search text-blue-600 mr-1"></i> Cari (Kode / Nama / Barcode)
                    </label>
                    <input
                        type="text"
                        name="search"
                        id="search"
                        value="{{ $activeFilters['search'] }}"
                        placeholder="Contoh: 268L651 / ELBOW / 1020826..."
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs focus:bg-white focus:border-blue-600 focus:ring-2 focus:ring-blue-100 outline-none"
                    >
                </div>
            </div>

            {{-- Mode Switcher & Filter Buttons --}}
            <div class="pt-3 border-t border-slate-100 flex flex-col md:flex-row md:items-center justify-between gap-4">
                {{-- Mode Radio --}}
                <div class="flex items-center gap-3">
                    <span class="text-xs font-bold text-slate-600 uppercase tracking-wider">Mode Tampilan:</span>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer text-xs font-semibold text-slate-800">
                        <input
                            type="radio"
                            name="mode"
                            value="ringkas"
                            {{ $activeFilters['mode'] === 'ringkas' ? 'checked' : '' }}
                            class="text-blue-600 focus:ring-blue-500"
                        >
                        <span>Ringkas (Per Kode & Stage)</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer text-xs font-semibold text-slate-800 ml-2">
                        <input
                            type="radio"
                            name="mode"
                            value="detail"
                            {{ $activeFilters['mode'] === 'detail' ? 'checked' : '' }}
                            class="text-blue-600 focus:ring-blue-500"
                        >
                        <span>Detail (Per Barcode / Transaksi)</span>
                    </label>
                </div>

                {{-- Submit / Reset --}}
                <div class="flex items-center gap-2">
                    <a href="{{ route('lost-wax.quality.defects.index') }}" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-lg transition-colors">
                        Reset Filter
                    </a>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white font-bold text-xs rounded-lg shadow-sm transition-all flex items-center gap-1.5">
                        <i class="fas fa-filter"></i>
                        <span>Terapkan Filter</span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    {{-- Active Drill-down Banner (if filtered by Production Code) --}}
    @if(!empty($activeFilters['production_code']))
        <div class="bg-amber-50 border border-amber-300 rounded-xl p-4 flex items-center justify-between gap-4 shadow-xs">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-amber-200 text-amber-900 flex items-center justify-center font-bold text-sm">
                    <i class="fas fa-sitemap"></i>
                </div>
                <div>
                    <div class="text-xs font-bold text-amber-900 uppercase tracking-wider">
                        Filter Drill-Down Aktif: <span class="font-mono bg-white px-2 py-0.5 rounded border border-amber-300">{{ $activeFilters['production_code'] }}</span>
                    </div>
                    <div class="text-[11px] text-amber-700">
                        Menampilkan seluruh rincian cacat untuk Kode Produksi ini.
                    </div>
                </div>
            </div>
            <a
                href="{{ route('lost-wax.quality.defects.index', array_merge(request()->except('production_code'), ['mode' => 'ringkas'])) }}"
                class="px-3 py-1.5 bg-white hover:bg-amber-100 text-amber-900 border border-amber-300 rounded-lg text-xs font-bold transition-colors flex items-center gap-1"
            >
                <i class="fas fa-times"></i>
                <span>Tutup Drill-Down</span>
            </a>
        </div>
    @endif

    {{-- Summary KPI Grid --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 lg:grid-cols-11 gap-2.5">
        {{-- 1. Cetak --}}
        <div class="bg-white p-3 rounded-xl border border-slate-200 text-center shadow-xs">
            <div class="text-[10px] uppercase font-bold text-slate-500 tracking-wider">Cetak</div>
            <div class="text-base font-black text-slate-800 mt-1 font-mono">{{ number_format($summary['cetak']) }}</div>
            <div class="text-[9px] text-slate-400">pcs</div>
        </div>

        {{-- 2. Rangkai --}}
        <div class="bg-white p-3 rounded-xl border border-slate-200 text-center shadow-xs">
            <div class="text-[10px] uppercase font-bold text-slate-500 tracking-wider">Rangkai</div>
            <div class="text-base font-black text-slate-800 mt-1 font-mono">{{ number_format($summary['assembly']) }}</div>
            <div class="text-[9px] text-slate-400">pcs</div>
        </div>

        {{-- 3. Lapisan 1 --}}
        <div class="bg-white p-3 rounded-xl border border-slate-200 text-center shadow-xs">
            <div class="text-[10px] uppercase font-bold text-slate-500 tracking-wider">Lap 1</div>
            <div class="text-base font-black text-slate-800 mt-1 font-mono">{{ number_format($summary['layer_1']) }}</div>
            <div class="text-[9px] text-slate-400">pcs</div>
        </div>

        {{-- 4. Lapisan 2 --}}
        <div class="bg-white p-3 rounded-xl border border-slate-200 text-center shadow-xs">
            <div class="text-[10px] uppercase font-bold text-slate-500 tracking-wider">Lap 2</div>
            <div class="text-base font-black text-slate-800 mt-1 font-mono">{{ number_format($summary['layer_2']) }}</div>
            <div class="text-[9px] text-slate-400">pcs</div>
        </div>

        {{-- 5. Lapisan 3 --}}
        <div class="bg-white p-3 rounded-xl border border-slate-200 text-center shadow-xs">
            <div class="text-[10px] uppercase font-bold text-slate-500 tracking-wider">Lap 3</div>
            <div class="text-base font-black text-slate-800 mt-1 font-mono">{{ number_format($summary['layer_3']) }}</div>
            <div class="text-[9px] text-slate-400">pcs</div>
        </div>

        {{-- 6. Lapisan 4 --}}
        <div class="bg-white p-3 rounded-xl border border-slate-200 text-center shadow-xs">
            <div class="text-[10px] uppercase font-bold text-slate-500 tracking-wider">Lap 4</div>
            <div class="text-base font-black text-slate-800 mt-1 font-mono">{{ number_format($summary['layer_4']) }}</div>
            <div class="text-[9px] text-slate-400">pcs</div>
        </div>

        {{-- 7. Lapisan 5 --}}
        <div class="bg-white p-3 rounded-xl border border-slate-200 text-center shadow-xs">
            <div class="text-[10px] uppercase font-bold text-slate-500 tracking-wider">Lap 5</div>
            <div class="text-base font-black text-slate-800 mt-1 font-mono">{{ number_format($summary['layer_5']) }}</div>
            <div class="text-[9px] text-slate-400">pcs</div>
        </div>

        {{-- 8. Lapisan 6 --}}
        <div class="bg-white p-3 rounded-xl border border-slate-200 text-center shadow-xs">
            <div class="text-[10px] uppercase font-bold text-slate-500 tracking-wider">Lap 6</div>
            <div class="text-base font-black text-slate-800 mt-1 font-mono">{{ number_format($summary['layer_6']) }}</div>
            <div class="text-[9px] text-slate-400">pcs</div>
        </div>

        {{-- 9. Lapisan 7 --}}
        <div class="bg-white p-3 rounded-xl border border-slate-200 text-center shadow-xs">
            <div class="text-[10px] uppercase font-bold text-slate-500 tracking-wider">Lap 7</div>
            <div class="text-base font-black text-slate-800 mt-1 font-mono">{{ number_format($summary['layer_7']) }}</div>
            <div class="text-[9px] text-slate-400">pcs</div>
        </div>

        {{-- 10. Oven --}}
        <div class="bg-white p-3 rounded-xl border border-slate-200 text-center shadow-xs">
            <div class="text-[10px] uppercase font-bold text-slate-500 tracking-wider">Oven</div>
            <div class="text-base font-black text-slate-800 mt-1 font-mono">{{ number_format($summary['oven']) }}</div>
            <div class="text-[9px] text-slate-400">pcs</div>
        </div>

        {{-- 11. GRAND TOTAL --}}
        <div class="bg-rose-50 p-3 rounded-xl border border-rose-200 text-center shadow-xs">
            <div class="text-[10px] uppercase font-bold text-rose-700 tracking-wider">GRAND TOTAL</div>
            <div class="text-base font-black text-rose-700 mt-1 font-mono">{{ number_format($summary['grand_total']) }}</div>
            <div class="text-[9px] text-rose-500">pcs</div>
        </div>
    </div>

    {{-- Main Table Panel --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-4 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-2 bg-slate-50/50">
            <div>
                <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider">
                    Daftar Kerusakan &mdash; Mode {{ strtoupper($activeFilters['mode']) }}
                </h3>
                <p class="text-[11px] text-slate-500">
                    Periode: <span class="font-semibold text-slate-700">{{ $activeFilters['date_from'] }}</span> s/d <span class="font-semibold text-slate-700">{{ $activeFilters['date_to'] }}</span>
                    &bull; Tahapan: <span class="font-semibold text-slate-700">{{ strtoupper($activeFilters['stage']) }}</span>
                    &bull; Total: <span class="font-bold text-rose-600">{{ number_format($summary['grand_total']) }} pcs</span> ({{ $items->count() }} baris)
                </p>
            </div>
        </div>

        <div class="overflow-x-auto">
            @if($activeFilters['mode'] === 'detail')
                {{-- ================= MODE DETAIL TABLE ================= --}}
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-600 uppercase tracking-wider">
                            <th class="py-3 px-3 w-12 text-center">No</th>
                            <th class="py-3 px-3 w-32">Kode Produksi</th>
                            <th class="py-3 px-3 w-36">Barcode Tree</th>
                            <th class="py-3 px-3">Nama Item</th>
                            <th class="py-3 px-3 w-28 text-center">Tahapan</th>
                            <th class="py-3 px-3 w-28 text-right">Jumlah Rusak</th>
                            <th class="py-3 px-3">Alasan Kerusakan</th>
                            <th class="py-3 px-3 w-32">Operator</th>
                            <th class="py-3 px-3 w-36 text-center">Waktu Kejadian</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-xs">
                        @forelse($items as $idx => $item)
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="py-2.5 px-3 text-center font-mono text-slate-400 font-semibold">
                                    {{ $idx + 1 }}
                                </td>
                                <td class="py-2.5 px-3">
                                    <span class="font-mono font-bold text-blue-700 bg-blue-50 border border-blue-200 px-2 py-0.5 rounded text-[11px]">
                                        {{ $item['production_code'] }}
                                    </span>
                                </td>
                                <td class="py-2.5 px-3">
                                    @if($item['barcode'] !== '-')
                                        <a href="{{ route('lost-wax.trees.index', ['barcode' => $item['barcode']]) }}" class="font-mono font-bold text-amber-700 hover:text-amber-800 underline text-[11px]" title="Buka detail tree">
                                            {{ $item['barcode'] }}
                                        </a>
                                    @else
                                        <span class="text-slate-400 font-mono text-center block">-</span>
                                    @endif
                                </td>
                                <td class="py-2.5 px-3 font-semibold text-slate-800">
                                    {{ $item['item_name'] }}
                                </td>
                                <td class="py-2.5 px-3 text-center">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold {{ $item['stage'] === 'cetak' ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : ($item['stage'] === 'assembly' ? 'bg-blue-50 text-blue-700 border border-blue-200' : ($item['stage'] === 'oven' ? 'bg-rose-50 text-rose-700 border border-rose-200' : 'bg-amber-50 text-amber-800 border border-amber-200')) }}">
                                        {{ strtoupper($item['stage_label']) }}
                                    </span>
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono font-black text-rose-600">
                                    {{ number_format($item['defect_qty']) }} pcs
                                </td>
                                <td class="py-2.5 px-3 text-slate-700">
                                    <div class="font-semibold">{{ $item['defect_reason'] }}</div>
                                    @if(!empty($item['notes']) && $item['notes'] !== $item['defect_reason'])
                                        <div class="text-[10px] text-slate-400 italic">"{{ $item['notes'] }}"</div>
                                    @endif
                                </td>
                                <td class="py-2.5 px-3 text-slate-600">
                                    {{ $item['operator'] }}
                                </td>
                                <td class="py-2.5 px-3 text-center font-mono text-[11px] text-slate-500">
                                    {{ $item['occurred_at'] ? \Carbon\Carbon::parse($item['occurred_at'])->format('d-m-Y H:i') : '-' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="py-12 text-center text-slate-400">
                                    <i class="fas fa-check-circle text-3xl text-emerald-400 block mb-2"></i>
                                    <span class="font-medium text-xs">Tidak ada data kerusakan pada filter dan periode ini.</span>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            @else
                {{-- ================= MODE RINGKAS TABLE ================= --}}
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-600 uppercase tracking-wider">
                            <th class="py-3 px-4 w-12 text-center">No</th>
                            <th class="py-3 px-4 w-44">Kode Produksi</th>
                            <th class="py-3 px-4">Nama Item</th>
                            <th class="py-3 px-4 w-36 text-center">Tahapan (Stage)</th>
                            <th class="py-3 px-4 w-36 text-right">Jumlah Rusak</th>
                            <th class="py-3 px-4 w-32 text-center">Total Kejadian</th>
                            <th class="py-3 px-4 w-28 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-xs">
                        @forelse($items as $idx => $item)
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="py-3 px-4 text-center font-mono text-slate-400 font-semibold">
                                    {{ $idx + 1 }}
                                </td>
                                <td class="py-3 px-4">
                                    <span class="font-mono font-bold text-blue-700 bg-blue-50 border border-blue-200 px-2 py-0.5 rounded text-[11px]">
                                        {{ $item['production_code'] }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 font-semibold text-slate-800">
                                    {{ $item['item_name'] }}
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded text-[10px] font-bold {{ $item['stage'] === 'cetak' ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : ($item['stage'] === 'assembly' ? 'bg-blue-50 text-blue-700 border border-blue-200' : ($item['stage'] === 'oven' ? 'bg-rose-50 text-rose-700 border border-rose-200' : 'bg-amber-50 text-amber-800 border border-amber-200')) }}">
                                        {{ strtoupper($item['stage_label']) }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-right font-mono font-black text-rose-600 text-sm">
                                    {{ number_format($item['defect_qty']) }} pcs
                                </td>
                                <td class="py-3 px-4 text-center text-slate-500 font-mono text-xs">
                                    {{ $item['record_count'] }} kali
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <a
                                        href="{{ route('lost-wax.quality.defects.index', array_merge(request()->query(), ['production_code' => $item['production_code'], 'mode' => 'detail'])) }}"
                                        class="inline-flex items-center gap-1 px-3 py-1 bg-slate-100 hover:bg-blue-50 text-slate-700 hover:text-blue-700 border border-slate-200 hover:border-blue-300 rounded font-semibold text-[11px] transition-all"
                                        title="Lihat detail barcode & alasan kerusakan"
                                    >
                                        <i class="fas fa-list-ul text-[10px]"></i>
                                        <span>Detail</span>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-12 text-center text-slate-400">
                                    <i class="fas fa-check-circle text-3xl text-emerald-400 block mb-2"></i>
                                    <span class="font-medium text-xs">Tidak ada data kerusakan pada filter dan periode ini.</span>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            @endif
        </div>
    </div>

</div>
@endsection
