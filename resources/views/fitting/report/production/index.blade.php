@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">REPORT PRODUKSI FITTING</h1>
            <p class="text-gray-500 text-[10px]">Laporan aktivitas produksi aktual harian (Netto PF → Kimia → Marking → Bubut CNC PF → QC PF → Gudang Jadi PF)</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('fitting.report.production.export.excel', request()->query()) }}" class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-bold text-xs rounded-lg shadow-sm transition-all flex items-center gap-1.5" title="Download Excel">
                <i class="fas fa-file-excel"></i>
                <span>Export Excel</span>
            </a>
            <a href="{{ route('fitting.report.production.export.pdf', request()->query()) }}" target="_blank" class="px-3.5 py-2 bg-rose-600 hover:bg-rose-700 active:bg-rose-800 text-white font-bold text-xs rounded-lg shadow-sm transition-all flex items-center gap-1.5" title="Cetak / Export PDF">
                <i class="fas fa-file-pdf"></i>
                <span>Cetak / PDF</span>
            </a>
        </div>
    </div>
@endsection

@section('content')
<div class="max-w-7xl mx-auto space-y-6">

    {{-- Validation Error Alert --}}
    @if ($errors->any())
        <div class="bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 rounded-xl text-xs font-semibold flex items-center gap-2 shadow-xs">
            <i class="fas fa-exclamation-circle text-rose-600 text-base shrink-0"></i>
            <div>
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Filter Panel --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <form action="{{ route('fitting.report.production.index') }}" method="GET" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                {{-- 1. Date From --}}
                <div>
                    <label for="date_from" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1">
                        <i class="fas fa-calendar-alt text-emerald-600 mr-1"></i> Tanggal Dari
                    </label>
                    <input
                        type="date"
                        name="date_from"
                        id="date_from"
                        value="{{ $activeFilters['date_from'] }}"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs font-semibold focus:bg-white focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 outline-none"
                    >
                </div>

                {{-- 2. Date To --}}
                <div>
                    <label for="date_to" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1">
                        <i class="fas fa-calendar-alt text-emerald-600 mr-1"></i> Tanggal Sampai
                    </label>
                    <input
                        type="date"
                        name="date_to"
                        id="date_to"
                        value="{{ $activeFilters['date_to'] }}"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs font-semibold focus:bg-white focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 outline-none"
                    >
                </div>

                {{-- 3. Stage Dropdown --}}
                <div>
                    <label for="stage" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1">
                        <i class="fas fa-layer-group text-emerald-600 mr-1"></i> Tahapan (Stage)
                    </label>
                    <select
                        name="stage"
                        id="stage"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs font-semibold focus:bg-white focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 outline-none"
                    >
                        <option value="all" {{ $activeFilters['stage'] === 'all' ? 'selected' : '' }}>Semua Tahapan (6 Tahap)</option>
                        @foreach($stages as $stKey => $stLabel)
                            <option value="{{ $stKey }}" {{ $activeFilters['stage'] === $stKey ? 'selected' : '' }}>{{ $stLabel }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- 4. Line Dropdown --}}
                <div>
                    <label for="line" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1">
                        <i class="fas fa-stream text-emerald-600 mr-1"></i> Line Produksi
                    </label>
                    <select
                        name="line"
                        id="line"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs font-semibold focus:bg-white focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 outline-none"
                    >
                        <option value="">Semua Line</option>
                        @foreach($lines as $lNum => $lName)
                            <option value="{{ $lNum }}" {{ (string)($activeFilters['line'] ?? '') === (string)$lNum ? 'selected' : '' }}>{{ $lName }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- 5. Search Input --}}
                <div>
                    <label for="search" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1">
                        <i class="fas fa-search text-emerald-600 mr-1"></i> Cari (PFKTR / Heat / SPK)
                    </label>
                    <input
                        type="text"
                        name="search"
                        id="search"
                        value="{{ $activeFilters['search'] }}"
                        placeholder="Contoh: PFKTR-... / Heat / SPK"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs focus:bg-white focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 outline-none"
                    >
                </div>
            </div>

            {{-- Submit / Reset --}}
            <div class="pt-3 border-t border-slate-100 flex items-center justify-between">
                <div class="text-xs text-slate-500 font-medium">
                    Menampilkan aktivitas fisik periode: <span class="font-bold text-slate-700">{{ \Carbon\Carbon::parse($activeFilters['date_from'])->format('d M Y') }}</span> s/d <span class="font-bold text-slate-700">{{ \Carbon\Carbon::parse($activeFilters['date_to'])->format('d M Y') }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('fitting.report.production.index') }}" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-lg transition-colors">
                        Reset Filter
                    </a>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-bold text-xs rounded-lg shadow-sm transition-all flex items-center gap-1.5">
                        <i class="fas fa-filter"></i>
                        <span>Terapkan Filter</span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    {{-- KPI Summary Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        {{-- Total Aktivitas PFKTR --}}
        <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-xs">
            <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1 flex items-center justify-between">
                <span>Total Aktivitas</span>
                <i class="fas fa-file-invoice text-blue-500"></i>
            </div>
            <div class="text-2xl font-black text-slate-900">
                {{ number_format($summary['total_pfktr_activities']) }} <span class="text-xs font-semibold text-slate-500">PFKTR</span>
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5">Agregasi PFKTR diproses di tahapan</div>
        </div>

        {{-- Hasil Kerja (Input Efektif) --}}
        <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-xs">
            <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1 flex items-center justify-between">
                <span>Hasil Kerja (Input)</span>
                <i class="fas fa-arrow-down text-indigo-500"></i>
            </div>
            <div class="text-2xl font-black text-slate-900">
                {{ number_format($summary['total_input_pcs']) }} <span class="text-xs font-semibold text-slate-500">pcs</span>
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5">Kuantitas input efektif operasional</div>
        </div>

        {{-- Total Reject (Defect) --}}
        <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-xs">
            <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1 flex items-center justify-between">
                <span>Total Reject</span>
                <i class="fas fa-exclamation-triangle text-rose-500"></i>
            </div>
            <div class="text-2xl font-black {{ $summary['total_defect_pcs'] > 0 ? 'text-rose-600' : 'text-slate-900' }}">
                {{ number_format($summary['total_defect_pcs']) }} <span class="text-xs font-semibold text-slate-500">pcs</span>
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5">Defect tercatat di tahapan</div>
        </div>

        {{-- Total Good Forward --}}
        <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-xs">
            <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1 flex items-center justify-between">
                <span>Good Forward</span>
                <i class="fas fa-arrow-right text-emerald-500"></i>
            </div>
            <div class="text-2xl font-black text-emerald-600">
                {{ number_format($summary['total_good_pcs']) }} <span class="text-xs font-semibold text-slate-500">pcs</span>
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5">Output bagus diteruskan ke tahap lanjut</div>
        </div>

        {{-- Total Berat Good --}}
        <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-xs col-span-2 md:col-span-1">
            <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1 flex items-center justify-between">
                <span>Berat Output</span>
                <i class="fas fa-weight-hanging text-amber-500"></i>
            </div>
            <div class="text-2xl font-black text-slate-900">
                {{ number_format($summary['total_weight_kg'], 2) }} <span class="text-xs font-semibold text-slate-500">kg</span>
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5">Setara {{ $summary['total_weight_ton'] }} Ton Good Qty</div>
        </div>
    </div>

    {{-- Stage Summary Table (Ringkasan 6 Tahapan) --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <div class="flex items-center gap-2">
                <i class="fas fa-chart-bar text-emerald-600 text-sm"></i>
                <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wide">Ringkasan Output per Tahapan</h2>
            </div>
            <span class="text-xs font-medium text-slate-500">Pipeline Fitting Production (6 Tahap)</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-100 text-[11px] font-bold text-slate-700 uppercase tracking-wider border-b border-slate-200">
                        <th class="py-3 px-4">Tahapan</th>
                        <th class="py-3 px-4 text-center">PFKTR Diproses</th>
                        <th class="py-3 px-4 text-right">Hasil Kerja (Input)</th>
                        <th class="py-3 px-4 text-right">Reject (pcs)</th>
                        <th class="py-3 px-4 text-right">Good Forward</th>
                        <th class="py-3 px-4 text-right">Berat Good</th>
                        <th class="py-3 px-4 text-right">% Reject</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-xs">
                    @forelse($stageSummaries as $stg)
                        @php
                            $badgeColor = match($stg['stage']) {
                                'netto_pf' => 'bg-emerald-100 text-emerald-900 border-emerald-300',
                                'kimia' => 'bg-cyan-100 text-cyan-900 border-cyan-300',
                                'marking' => 'bg-purple-100 text-purple-900 border-purple-300',
                                'bubut_cnc_pf' => 'bg-blue-100 text-blue-900 border-blue-300',
                                'qc_pf' => 'bg-indigo-100 text-indigo-900 border-indigo-300',
                                'gudang_jadi_pf' => 'bg-slate-200 text-slate-900 border-slate-400',
                                default => 'bg-slate-100 text-slate-800 border-slate-200',
                            };
                        @endphp
                        <tr class="hover:bg-emerald-50/30 transition-colors">
                            <td class="py-3 px-4 font-bold text-slate-900 flex items-center gap-2">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold border {{ $badgeColor }}">
                                    {{ $stg['stage_label'] }}
                                </span>
                            </td>
                            <td class="py-3 px-4 text-center font-mono font-bold text-slate-800">
                                {{ number_format($stg['ktr_count']) }} PFKTR
                            </td>
                            <td class="py-3 px-4 text-right font-mono font-medium text-slate-700">
                                {{ number_format($stg['input_pcs']) }} pcs
                            </td>
                            <td class="py-3 px-4 text-right font-mono {{ $stg['defect_pcs'] > 0 ? 'font-bold text-rose-600 bg-rose-50/40' : 'text-slate-400' }}">
                                {{ $stg['defect_pcs'] > 0 ? number_format($stg['defect_pcs']) . ' pcs' : '0 pcs' }}
                            </td>
                            <td class="py-3 px-4 text-right font-mono font-bold text-emerald-700 bg-emerald-50/30">
                                {{ number_format($stg['good_pcs']) }} pcs
                            </td>
                            <td class="py-3 px-4 text-right font-mono font-bold text-slate-800">
                                {{ number_format($stg['weight_kg'], 2) }} kg
                            </td>
                            <td class="py-3 px-4 text-right font-mono {{ $stg['defect_rate'] > 0 ? 'font-bold text-rose-600' : 'text-slate-400' }}">
                                {{ number_format($stg['defect_rate'], 2) }}%
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-slate-400">Tidak ada data aktivitas tahapan</td>
                        </tr>
                    @endforelse
                </tbody>
                @if($stageSummaries->isNotEmpty())
                    <tfoot>
                        <tr class="bg-slate-100 font-bold text-slate-900 border-t-2 border-slate-300 text-xs">
                            <td class="py-3 px-4 uppercase tracking-wider">TOTAL KESELURUHAN</td>
                            <td class="py-3 px-4 text-center font-mono text-emerald-800">
                                {{ number_format($summary['total_pfktr_activities']) }} PFKTR
                            </td>
                            <td class="py-3 px-4 text-right font-mono">
                                {{ number_format($summary['total_input_pcs']) }} pcs
                            </td>
                            <td class="py-3 px-4 text-right font-mono {{ $summary['total_defect_pcs'] > 0 ? 'text-rose-700' : 'text-slate-600' }}">
                                {{ number_format($summary['total_defect_pcs']) }} pcs
                            </td>
                            <td class="py-3 px-4 text-right font-mono text-emerald-800">
                                {{ number_format($summary['total_good_pcs']) }} pcs
                            </td>
                            <td class="py-3 px-4 text-right font-mono text-slate-900">
                                {{ number_format($summary['total_weight_kg'], 2) }} kg
                            </td>
                            <td class="py-3 px-4 text-right font-mono text-slate-600">
                                {{ number_format($summary['overall_defect_rate'], 2) }}%
                            </td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    {{-- Main Data Table Card (Rincian per PFKTR) --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <i class="fas fa-table text-slate-400 text-sm"></i>
                <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wide">Rincian Aktivitas Produksi per PFKTR</h2>
            </div>
            <span class="text-xs font-medium text-slate-500">Total: <b class="text-slate-800">{{ $items->count() }}</b> eksekusi</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50 text-[11px] font-bold text-slate-700 uppercase tracking-wider border-b border-slate-200">
                        <th class="py-3 px-3 text-center w-10">No</th>
                        <th class="py-3 px-3">PFKTR</th>
                        <th class="py-3 px-3">Line</th>
                        <th class="py-3 px-3">Kode SPK</th>
                        <th class="py-3 px-3">Customer</th>
                        <th class="py-3 px-4">Nama Produk</th>
                        <th class="py-3 px-3 text-center">Heat</th>
                        <th class="py-3 px-3 text-center">Tahapan</th>
                        <th class="py-3 px-3 text-right">Hasil Kerja</th>
                        <th class="py-3 px-3 text-right">Reject</th>
                        <th class="py-3 px-3 text-right">Good</th>
                        <th class="py-3 px-3 text-center">Waktu Selesai</th>
                        <th class="py-3 px-3 text-left">Operator</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-xs">
                    @forelse($items as $idx => $item)
                        @php
                            $rowBadge = match($item['stage']) {
                                'netto_pf' => 'bg-emerald-100 text-emerald-900 border-emerald-200',
                                'kimia' => 'bg-cyan-100 text-cyan-900 border-cyan-200',
                                'marking' => 'bg-purple-100 text-purple-900 border-purple-200',
                                'bubut_cnc_pf' => 'bg-blue-100 text-blue-900 border-blue-200',
                                'qc_pf' => 'bg-indigo-100 text-indigo-900 border-indigo-200',
                                'gudang_jadi_pf' => 'bg-slate-200 text-slate-900 border-slate-300',
                                default => 'bg-slate-100 text-slate-800 border-slate-200',
                            };
                            $allocCount = count($item['allocations'] ?? []);
                        @endphp
                        <tr class="hover:bg-emerald-50/40 transition-colors">
                            <td class="py-2.5 px-3 text-center text-slate-500 font-mono">{{ $idx + 1 }}</td>
                            <td class="py-2.5 px-3">
                                <span class="font-mono font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200 text-xs">
                                    {{ $item['traveler_number'] }}
                                </span>
                            </td>
                            <td class="py-2.5 px-3 font-semibold text-slate-800">
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                    {{ $item['line_label'] }}
                                </span>
                            </td>
                            <td class="py-2.5 px-3 font-mono font-semibold text-slate-800">
                                @if($item['is_multi_allocation'])
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-300">
                                        MULTI-SPK ({{ $allocCount }})
                                    </span>
                                @else
                                    {{ $item['production_code'] }}
                                @endif
                            </td>
                            <td class="py-2.5 px-3 text-slate-700 font-medium">
                                {{ $item['customer'] }}
                            </td>
                            <td class="py-2.5 px-4 font-semibold text-slate-900">
                                {{ $item['item_name'] }}
                            </td>
                            <td class="py-2.5 px-3 text-center font-mono text-slate-600">
                                {{ $item['heat_number'] }}
                            </td>
                            <td class="py-2.5 px-3 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold border {{ $rowBadge }}">
                                    {{ $item['stage_label'] }}
                                </span>
                            </td>
                            <td class="py-2.5 px-3 text-right font-mono font-medium text-slate-800">
                                {{ number_format($item['input_qty']) }}
                            </td>
                            <td class="py-2.5 px-3 text-right font-mono {{ $item['defect_qty'] > 0 ? 'font-bold text-rose-600 bg-rose-50/50' : 'text-slate-400' }}">
                                {{ $item['defect_qty'] > 0 ? number_format($item['defect_qty']) : '0' }}
                            </td>
                            <td class="py-2.5 px-3 text-right font-mono font-bold text-emerald-700 bg-emerald-50/40">
                                {{ number_format($item['good_qty']) }}
                            </td>
                            <td class="py-2.5 px-3 text-center font-mono text-[11px] text-slate-600">
                                {{ $item['physical_done_at'] }}
                            </td>
                            <td class="py-2.5 px-3 text-slate-700 text-xs">
                                {{ $item['operator'] }}
                            </td>
                        </tr>

                        {{-- Multi-SPK Allocation Sub-Rows (Expandable) --}}
                        @if($item['is_multi_allocation'] && !empty($item['allocations']))
                            @foreach($item['allocations'] as $alloc)
                                <tr class="bg-amber-50/30 text-[11px] border-b border-amber-100">
                                    <td class="py-1 px-3 text-center text-amber-500">
                                        <i class="fas fa-level-up-alt rotate-90 text-[10px]"></i>
                                    </td>
                                    <td class="py-1 px-3 text-slate-400 text-[10px]" colspan="2">
                                        ↳ Alokasi SPK
                                    </td>
                                    <td class="py-1 px-3 font-mono font-bold text-amber-900">
                                        {{ $alloc['production_code'] }}
                                    </td>
                                    <td class="py-1 px-3 text-slate-600">
                                        {{ $alloc['customer'] }}
                                    </td>
                                    <td class="py-1 px-4 text-slate-700">
                                        {{ $alloc['item_name'] }}
                                    </td>
                                    <td class="py-1 px-3 text-center text-slate-400" colspan="2">
                                        -
                                    </td>
                                    <td class="py-1 px-3 text-right font-mono text-slate-600">
                                        {{ number_format($alloc['allocated_input_qty']) }}
                                    </td>
                                    <td class="py-1 px-3 text-right font-mono {{ $alloc['allocated_defect_qty'] > 0 ? 'text-rose-600 font-bold' : 'text-slate-400' }}">
                                        {{ $alloc['allocated_defect_qty'] > 0 ? number_format($alloc['allocated_defect_qty']) : '0' }}
                                    </td>
                                    <td class="py-1 px-3 text-right font-mono font-bold text-emerald-800">
                                        {{ number_format($alloc['allocated_good_qty']) }}
                                    </td>
                                    <td class="py-1 px-3 text-center text-slate-400" colspan="2">
                                        -
                                    </td>
                                </tr>
                            @endforeach
                        @endif
                    @empty
                        <tr>
                            <td colspan="13" class="py-12 text-center text-slate-400">
                                <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-3 text-slate-400">
                                    <i class="fas fa-inbox text-xl"></i>
                                </div>
                                <div class="font-bold text-sm text-slate-600">Tidak ada data aktivitas produksi Fitting</div>
                                <div class="text-xs text-slate-400 mt-1">Coba sesuaikan filter rentang tanggal atau tahapan Fitting.</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
