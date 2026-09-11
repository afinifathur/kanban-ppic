@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">REPORT PRODUKSI LOST WAX</h1>
            <p class="text-gray-500 text-[10px]">Laporan aktivitas produksi Lost Wax per tahapan (Cetak, Rangkai, Lapisan 1–7, hingga Oven)</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('lost-wax.report.production.export.excel', request()->query()) }}" class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-bold text-xs rounded-lg shadow-sm transition-all flex items-center gap-1.5" title="Download Excel">
                <i class="fas fa-file-excel"></i>
                <span>Export Excel</span>
            </a>
            <a href="{{ route('lost-wax.report.production.export.pdf', request()->query()) }}" target="_blank" class="px-3.5 py-2 bg-rose-600 hover:bg-rose-700 active:bg-rose-800 text-white font-bold text-xs rounded-lg shadow-sm transition-all flex items-center gap-1.5" title="Cetak / Export PDF">
                <i class="fas fa-file-pdf"></i>
                <span>Cetak / PDF</span>
            </a>
        </div>
    </div>
@endsection

@section('content')
<div class="max-w-7xl mx-auto space-y-6">

    {{-- Filter Panel --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <form action="{{ route('lost-wax.report.production.index') }}" method="GET" class="space-y-4">
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
                        <i class="fas fa-search text-blue-600 mr-1"></i> Cari (Kode / Customer / Nama)
                    </label>
                    <input
                        type="text"
                        name="search"
                        id="search"
                        value="{{ $activeFilters['search'] }}"
                        placeholder="Contoh: 268L731 / FLANGE..."
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs focus:bg-white focus:border-blue-600 focus:ring-2 focus:ring-blue-100 outline-none"
                    >
                </div>
            </div>

            {{-- Submit / Reset --}}
            <div class="pt-3 border-t border-slate-100 flex items-center justify-between">
                <div class="text-xs text-slate-500 font-medium">
                    Menampilkan aktivitas produksi periode: <span class="font-bold text-slate-700">{{ \Carbon\Carbon::parse($activeFilters['date_from'])->format('d M Y') }}</span> s/d <span class="font-bold text-slate-700">{{ \Carbon\Carbon::parse($activeFilters['date_to'])->format('d M Y') }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('lost-wax.report.production.index') }}" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-lg transition-colors">
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

    {{-- KPI Summary Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        {{-- Total Qty --}}
        <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-xs">
            <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1 flex items-center justify-between">
                <span>Total Qty Dikerjakan</span>
                <i class="fas fa-cubes text-blue-500"></i>
            </div>
            <div class="text-2xl font-black text-slate-900">
                {{ number_format($summary['total_qty']) }} <span class="text-xs font-semibold text-slate-500">pcs</span>
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5">Seluruh output baik pada periode</div>
        </div>

        {{-- Total Berat --}}
        <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-xs">
            <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1 flex items-center justify-between">
                <span>Total Berat Produksi</span>
                <i class="fas fa-weight-hanging text-emerald-500"></i>
            </div>
            <div class="text-2xl font-black text-slate-900">
                {{ number_format($summary['total_weight'], 2) }} <span class="text-xs font-semibold text-slate-500">kg</span>
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5">Akumulasi Qty × Berat/pcs</div>
        </div>

        {{-- Total Rusak --}}
        <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-xs">
            <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1 flex items-center justify-between">
                <span>Total Rusak (Defect)</span>
                <i class="fas fa-exclamation-triangle text-rose-500"></i>
            </div>
            <div class="text-2xl font-black {{ $summary['total_defect'] > 0 ? 'text-rose-600' : 'text-slate-900' }}">
                {{ number_format($summary['total_defect']) }} <span class="text-xs font-semibold text-slate-500">pcs</span>
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5">Total tercatat pada tahapan terkait</div>
        </div>

        {{-- Total Records --}}
        <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-xs">
            <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1 flex items-center justify-between">
                <span>Jumlah Baris / Item</span>
                <i class="fas fa-list-ol text-purple-500"></i>
            </div>
            <div class="text-2xl font-black text-slate-900">
                {{ number_format($summary['total_records']) }} <span class="text-xs font-semibold text-slate-500">baris</span>
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5">Kombinasi Kode & Tahapan</div>
        </div>
    </div>

    {{-- Main Data Table Card --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <i class="fas fa-table text-slate-400 text-sm"></i>
                <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wide">Rincian Laporan Produksi</h2>
            </div>
            <span class="text-xs font-medium text-slate-500">Total: <b class="text-slate-800">{{ $items->count() }}</b> baris</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50 text-[11px] font-bold text-slate-700 uppercase tracking-wider border-b border-slate-200">
                        <th class="py-3 px-3 text-center w-12">No</th>
                        <th class="py-3 px-3">Kode Produksi</th>
                        <th class="py-3 px-3">Kode Customer</th>
                        <th class="py-3 px-4">Nama Barang</th>
                        <th class="py-3 px-3 text-center">Tahapan</th>
                        <th class="py-3 px-3 text-right">Berat</th>
                        <th class="py-3 px-3 text-right">Qty Dikerjakan</th>
                        <th class="py-3 px-3 text-right">Total Berat</th>
                        <th class="py-3 px-3 text-right">Qty Rusak</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-xs">
                    @forelse($items as $idx => $item)
                        <tr class="hover:bg-blue-50/40 transition-colors">
                            <td class="py-2.5 px-3 text-center text-slate-500 font-mono">{{ $idx + 1 }}</td>
                            <td class="py-2.5 px-3">
                                <span class="font-mono font-bold text-slate-900 bg-slate-100 px-2 py-0.5 rounded border border-slate-200 text-xs">
                                    {{ $item['production_code'] }}
                                </span>
                            </td>
                            <td class="py-2.5 px-3 text-slate-700 font-medium">
                                {{ $item['customer_code'] ?: '-' }}
                            </td>
                            <td class="py-2.5 px-4 font-semibold text-slate-800">
                                {{ $item['item_name'] }}
                            </td>
                            <td class="py-2.5 px-3 text-center">
                                @php
                                    $stageColor = match($item['stage']) {
                                        'cetak' => 'bg-amber-100 text-amber-800 border-amber-200',
                                        'assembly' => 'bg-indigo-100 text-indigo-800 border-indigo-200',
                                        'oven' => 'bg-orange-100 text-orange-800 border-orange-200',
                                        default => 'bg-blue-100 text-blue-800 border-blue-200',
                                    };
                                @endphp
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold border {{ $stageColor }}">
                                    {{ $item['stage_label'] }}
                                </span>
                            </td>
                            <td class="py-2.5 px-3 text-right font-mono text-slate-700">
                                {{ number_format($item['weight'], 2) }} kg
                            </td>
                            <td class="py-2.5 px-3 text-right font-mono font-bold text-slate-900">
                                {{ number_format($item['qty_processed']) }} pcs
                            </td>
                            <td class="py-2.5 px-3 text-right font-mono font-bold text-emerald-700 bg-emerald-50/50">
                                {{ number_format($item['total_weight'], 2) }} kg
                            </td>
                            <td class="py-2.5 px-3 text-right font-mono {{ $item['qty_defect'] > 0 ? 'font-bold text-rose-600 bg-rose-50/50' : 'text-slate-400' }}">
                                {{ $item['qty_defect'] > 0 ? number_format($item['qty_defect']) . ' pcs' : '0 pcs' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="py-12 text-center text-slate-400">
                                <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-3 text-slate-400">
                                    <i class="fas fa-inbox text-xl"></i>
                                </div>
                                <div class="font-bold text-sm text-slate-600">Tidak ada data aktivitas produksi</div>
                                <div class="text-xs text-slate-400 mt-1">Coba sesuaikan filter rentang tanggal atau tahapan.</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if($items->isNotEmpty())
                    <tfoot>
                        <tr class="bg-slate-100 font-bold text-slate-900 border-t-2 border-slate-300 text-xs">
                            <td colspan="5" class="py-3 px-4 text-center uppercase tracking-wider">TOTAL KESELURUHAN</td>
                            <td class="py-3 px-3 text-center text-slate-400 font-mono">-</td>
                            <td class="py-3 px-3 text-right font-mono text-blue-700">
                                {{ number_format($summary['total_qty']) }} pcs
                            </td>
                            <td class="py-3 px-3 text-right font-mono text-emerald-800">
                                {{ number_format($summary['total_weight'], 2) }} kg
                            </td>
                            <td class="py-3 px-3 text-right font-mono {{ $summary['total_defect'] > 0 ? 'text-rose-700' : 'text-slate-600' }}">
                                {{ number_format($summary['total_defect']) }} pcs
                            </td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

</div>
@endsection
