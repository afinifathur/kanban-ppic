@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">REPORT PRODUKSI SAND CASTING</h1>
            <p class="text-gray-500 text-[10px]">Laporan aktivitas produksi aktual harian per tahapan (Hasil Cor → Netto → Bubut OD → Bubut CNC → Bor → QC → Gudang Jadi)</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('sand-casting.report.production.export.excel', request()->query()) }}" class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-bold text-xs rounded-lg shadow-sm transition-all flex items-center gap-1.5" title="Download Excel">
                <i class="fas fa-file-excel"></i>
                <span>Export Excel</span>
            </a>
            <a href="{{ route('sand-casting.report.production.export.pdf', request()->query()) }}" target="_blank" class="px-3.5 py-2 bg-rose-600 hover:bg-rose-700 active:bg-rose-800 text-white font-bold text-xs rounded-lg shadow-sm transition-all flex items-center gap-1.5" title="Cetak / Export PDF">
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
        <form action="{{ route('sand-casting.report.production.index') }}" method="GET" class="space-y-4">
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

                {{-- 3. Stage Dropdown (Single Stage Only) --}}
                <div>
                    <label for="stage" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1">
                        <i class="fas fa-layer-group text-blue-600 mr-1"></i> Tahapan (Stage)
                    </label>
                    <select
                        name="stage"
                        id="stage"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs font-semibold focus:bg-white focus:border-blue-600 focus:ring-2 focus:ring-blue-100 outline-none"
                    >
                        @foreach($stages as $stKey => $stLabel)
                            <option value="{{ $stKey }}" {{ $activeFilters['stage'] === $stKey ? 'selected' : '' }}>{{ $stLabel }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- 4. Search Input --}}
                <div>
                    <label for="search" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1">
                        <i class="fas fa-search text-blue-600 mr-1"></i> Cari (KTR / Heat / Produk / Kode)
                    </label>
                    <input
                        type="text"
                        name="search"
                        id="search"
                        value="{{ $activeFilters['search'] }}"
                        placeholder="Contoh: KTR-... / A214... / FLANGE"
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
                    <a href="{{ route('sand-casting.report.production.index') }}" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-lg transition-colors">
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
        {{-- Total Aktivitas KTR --}}
        <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-xs">
            <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1 flex items-center justify-between">
                <span>Total Aktivitas KTR</span>
                <i class="fas fa-file-invoice text-blue-500"></i>
            </div>
            <div class="text-2xl font-black text-slate-900">
                {{ number_format($summary['total_ktr_activities']) }} <span class="text-xs font-semibold text-slate-500">KTR</span>
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5">Agregasi KTR diproses seluruh tahapan</div>
        </div>

        {{-- Total Output Baik --}}
        <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-xs">
            <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1 flex items-center justify-between">
                <span>Total Output Baik (Good)</span>
                <i class="fas fa-cubes text-emerald-500"></i>
            </div>
            <div class="text-2xl font-black text-slate-900">
                {{ number_format($summary['total_qty_output']) }} <span class="text-xs font-semibold text-slate-500">pcs</span>
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5">Agregat pcs output lolos proses</div>
        </div>

        {{-- Total Berat Output --}}
        <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-xs">
            <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1 flex items-center justify-between">
                <span>Total Berat Output</span>
                <i class="fas fa-weight-hanging text-indigo-500"></i>
            </div>
            <div class="text-2xl font-black text-slate-900">
                {{ number_format($summary['total_weight'], 2) }} <span class="text-xs font-semibold text-slate-500">kg</span>
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5">Akumulasi Good Qty × Berat/pcs</div>
        </div>

        {{-- Total Rusak (Defect) --}}
        <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-xs">
            <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1 flex items-center justify-between">
                <span>Total Rusak (Defect)</span>
                <i class="fas fa-exclamation-triangle text-rose-500"></i>
            </div>
            <div class="text-2xl font-black {{ $summary['total_defect'] > 0 ? 'text-rose-600' : 'text-slate-900' }}">
                {{ number_format($summary['total_defect']) }} <span class="text-xs font-semibold text-slate-500">pcs</span>
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5">Total reject tercatat pada tahapan</div>
        </div>
    </div>

    {{-- Stage Summary Table (Ringkasan 7 Tahapan) --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <div class="flex items-center gap-2">
                <i class="fas fa-chart-bar text-blue-600 text-sm"></i>
                <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wide">Ringkasan Output per Tahapan</h2>
            </div>
            <span class="text-xs font-medium text-slate-500">Pipeline Sand Casting (7 Tahapan)</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-100 text-[11px] font-bold text-slate-700 uppercase tracking-wider border-b border-slate-200">
                        <th class="py-3 px-4">Tahapan</th>
                        <th class="py-3 px-4 text-center">KTR Diproses</th>
                        <th class="py-3 px-4 text-right">Input PCS</th>
                        <th class="py-3 px-4 text-right">Rusak (Defect)</th>
                        <th class="py-3 px-4 text-right">Output Baik (Good)</th>
                        <th class="py-3 px-4 text-right">Berat Output</th>
                        <th class="py-3 px-4 text-right">% Defect</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-xs">
                    @forelse($stageSummaries as $stg)
                        @php
                            $badgeColor = match($stg['stage']) {
                                'cor' => 'bg-amber-100 text-amber-900 border-amber-300',
                                'netto' => 'bg-emerald-100 text-emerald-900 border-emerald-300',
                                'bubut_od' => 'bg-cyan-100 text-cyan-900 border-cyan-300',
                                'bubut_cnc' => 'bg-blue-100 text-blue-900 border-blue-300',
                                'bor' => 'bg-purple-100 text-purple-900 border-purple-300',
                                'qc' => 'bg-indigo-100 text-indigo-900 border-indigo-300',
                                'gudang_jadi' => 'bg-slate-200 text-slate-900 border-slate-400',
                                default => 'bg-slate-100 text-slate-800 border-slate-200',
                            };
                        @endphp
                        <tr class="hover:bg-blue-50/30 transition-colors">
                            <td class="py-3 px-4 font-bold text-slate-900 flex items-center gap-2">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold border {{ $badgeColor }}">
                                    {{ $stg['stage_label'] }}
                                </span>
                            </td>
                            <td class="py-3 px-4 text-center font-mono font-bold text-slate-800">
                                {{ number_format($stg['ktr_count']) }} KTR
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
                            <td colspan="7" class="py-8 text-center text-slate-400">Tidak ada data tahapan</td>
                        </tr>
                    @endforelse
                </tbody>
                @if($stageSummaries->isNotEmpty())
                    <tfoot>
                        <tr class="bg-slate-100 font-bold text-slate-900 border-t-2 border-slate-300 text-xs">
                            <td class="py-3 px-4 uppercase tracking-wider">TOTAL KESELURUHAN</td>
                            <td class="py-3 px-4 text-center font-mono text-blue-700">
                                {{ number_format($summary['total_ktr_activities']) }} KTR
                            </td>
                            <td class="py-3 px-4 text-right font-mono">
                                {{ number_format($stageSummaries->sum('input_pcs')) }} pcs
                            </td>
                            <td class="py-3 px-4 text-right font-mono {{ $summary['total_defect'] > 0 ? 'text-rose-700' : 'text-slate-600' }}">
                                {{ number_format($summary['total_defect']) }} pcs
                            </td>
                            <td class="py-3 px-4 text-right font-mono text-emerald-800">
                                {{ number_format($summary['total_qty_output']) }} pcs
                            </td>
                            <td class="py-3 px-4 text-right font-mono text-slate-900">
                                {{ number_format($summary['total_weight'], 2) }} kg
                            </td>
                            <td class="py-3 px-4 text-right font-mono text-slate-600">
                                @php
                                    $totIn = $stageSummaries->sum('input_pcs');
                                    $totDef = $summary['total_defect'];
                                    $avgDefRate = $totIn > 0 ? round(($totDef / $totIn) * 100, 2) : 0;
                                @endphp
                                {{ number_format($avgDefRate, 2) }}%
                            </td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    {{-- Main Data Table Card (Rincian Itemized) --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <i class="fas fa-table text-slate-400 text-sm"></i>
                <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wide">Rincian Aktivitas Produksi</h2>
            </div>
            <span class="text-xs font-medium text-slate-500">Total: <b class="text-slate-800">{{ $items->count() }}</b> baris</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50 text-[11px] font-bold text-slate-700 uppercase tracking-wider border-b border-slate-200">
                        <th class="py-3 px-3 text-center w-10">No</th>
                        <th class="py-3 px-3">KTR</th>
                        <th class="py-3 px-3">Kode Prod</th>
                        <th class="py-3 px-3">Customer</th>
                        <th class="py-3 px-4">Nama Produk</th>
                        <th class="py-3 px-3 text-center">Heat</th>
                        <th class="py-3 px-3 text-center">Tahapan</th>
                        <th class="py-3 px-3 text-center">Checkpoint</th>
                        <th class="py-3 px-3 text-right">Input</th>
                        <th class="py-3 px-3 text-right">Rusak</th>
                        <th class="py-3 px-3 text-right">Good</th>
                        <th class="py-3 px-3 text-center">Waktu Selesai</th>
                        <th class="py-3 px-3 text-left">Operator</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-xs">
                    @forelse($items as $idx => $item)
                        @php
                            $rowBadge = match($item['stage']) {
                                'cor' => 'bg-amber-100 text-amber-900 border-amber-200',
                                'netto' => 'bg-emerald-100 text-emerald-900 border-emerald-200',
                                'bubut_od' => 'bg-cyan-100 text-cyan-900 border-cyan-200',
                                'bubut_cnc' => 'bg-blue-100 text-blue-900 border-blue-200',
                                'bor' => 'bg-purple-100 text-purple-900 border-purple-200',
                                'qc' => 'bg-indigo-100 text-indigo-900 border-indigo-200',
                                'gudang_jadi' => 'bg-slate-200 text-slate-900 border-slate-300',
                                default => 'bg-slate-100 text-slate-800 border-slate-200',
                            };
                        @endphp
                        <tr class="hover:bg-blue-50/40 transition-colors">
                            <td class="py-2.5 px-3 text-center text-slate-500 font-mono">{{ $idx + 1 }}</td>
                            <td class="py-2.5 px-3">
                                <span class="font-mono font-bold text-blue-700 bg-blue-50 px-2 py-0.5 rounded border border-blue-200 text-xs">
                                    {{ $item['ktr'] }}
                                </span>
                            </td>
                            <td class="py-2.5 px-3 font-mono font-semibold text-slate-800">
                                {{ $item['production_code'] }}
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
                            <td class="py-2.5 px-3 text-center font-mono text-[11px] text-slate-600">
                                {{ $item['checkpoint_code'] }}
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
                    @empty
                        <tr>
                            <td colspan="13" class="py-12 text-center text-slate-400">
                                <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-3 text-slate-400">
                                    <i class="fas fa-inbox text-xl"></i>
                                </div>
                                <div class="font-bold text-sm text-slate-600">Tidak ada data aktivitas produksi</div>
                                <div class="text-xs text-slate-400 mt-1">Coba sesuaikan filter rentang tanggal atau tahapan Sand Casting.</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
