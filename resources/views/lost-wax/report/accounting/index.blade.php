@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full no-print">
        <div>
            <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded text-[10px] font-black bg-blue-600 text-white tracking-wide uppercase">LOST WAX</span>
                <h1 class="text-lg font-black text-slate-800 tracking-tight leading-tight uppercase">LAPORAN ACCOUNTING</h1>
            </div>
            <p class="text-slate-500 text-[11px] mt-0.5">Horizontal Production Tracking — Ringkas per Kode Produksi (Cetak s/d Hasil Cor)</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('lost-wax.report.accounting.export.excel', request()->query()) }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-xs transition-colors" title="Download Excel (.xlsx) Pergerakan Produksi">
                <i class="fas fa-file-excel"></i>
                <span>Export Excel</span>
            </a>
            <a href="{{ route('lost-wax.report.accounting.export.pdf', request()->query()) }}" target="_blank" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-xs transition-colors" title="Cetak / Export PDF (Landscape)">
                <i class="fas fa-print"></i>
                <span>Cetak / PDF</span>
            </a>
        </div>
    </div>
@endsection

@section('content')
<div class="space-y-6">

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

    {{-- Anomaly Warning --}}
    @if (!empty($data['anomalies']))
        <div class="bg-amber-50 border border-amber-300 text-amber-900 px-4 py-3 rounded-xl text-xs flex items-start gap-2 shadow-xs">
            <i class="fas fa-exclamation-triangle text-amber-600 text-base shrink-0 mt-0.5"></i>
            <div>
                <div class="font-bold">Peringatan: Anomali Data Terdeteksi pada Laporan</div>
                <div class="text-[11px] text-amber-800 mt-1 space-y-0.5">
                    @foreach($data['anomalies'] as $anom)
                        @if(isset($anom['message']))
                            <div>• {{ $anom['message'] }}</div>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- Filter Panel --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <form action="{{ route('lost-wax.report.accounting.index') }}" method="GET" class="space-y-4">
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
                        value="{{ $filters['date_from'] }}"
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
                        value="{{ $filters['date_to'] }}"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs font-semibold focus:bg-white focus:border-blue-600 focus:ring-2 focus:ring-blue-100 outline-none"
                    >
                </div>

                {{-- 3. Search Input --}}
                <div>
                    <label for="search" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1">
                        <i class="fas fa-search text-blue-600 mr-1"></i> Cari (Kode / Item / Customer / AISI)
                    </label>
                    <input
                        type="text"
                        name="search"
                        id="search"
                        value="{{ $filters['search'] }}"
                        placeholder="Contoh: 268KS / FLANGE / CS Q235"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs focus:bg-white focus:border-blue-600 focus:ring-2 focus:ring-blue-100 outline-none"
                    >
                </div>

                {{-- 4. Submit Button --}}
                <div>
                    <button
                        type="submit"
                        class="w-full px-4 py-2 bg-slate-800 hover:bg-slate-900 active:bg-black text-white font-bold text-xs rounded-lg shadow-sm transition-all flex items-center justify-center gap-2"
                    >
                        <i class="fas fa-filter"></i>
                        <span>Tampilkan Laporan</span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    @php
        $meta = $data['meta'];
        $stagesList = [
            'cetak' => 'CETAK',
            'rangkai' => 'RANGKAI',
            'layer_1' => 'LAPISAN 1',
            'layer_2' => 'LAPISAN 2',
            'layer_3' => 'LAPISAN 3',
            'layer_4' => 'LAPISAN 4',
            'layer_5' => 'LAPISAN 5',
            'layer_6' => 'LAPISAN 6',
            'oven' => 'OVEN',
            'cor' => 'HASIL COR',
        ];
    @endphp

    {{-- ======================================================== --}}
    {{-- HORIZONTAL PRODUCTION TRACKING (PRIMARY TABLE - 29 COLS)  --}}
    {{-- ======================================================== --}}
    <div class="space-y-4">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200 bg-slate-50 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-blue-600"></span>
                    <h2 class="text-xs font-black text-slate-800 uppercase tracking-wider">Pergerakan Produksi Horizontal — Ringkas per Kode Produksi</h2>
                    <span class="text-[11px] text-slate-500 font-medium">({{ count($data['pergerakan']) }} Kode Produksi)</span>
                </div>
                <div class="text-[11px] text-slate-400">
                    Cutoff: <span class="font-semibold text-slate-600">{{ $meta['date_to'] }} 23:59:59 WIB</span>
                </div>
            </div>

            <div class="overflow-x-auto max-h-[750px] relative">
                <table class="w-full text-[11px] border-collapse min-w-[2100px]">
                    {{-- Row 1: Group Headers --}}
                    <thead class="sticky top-0 z-20 shadow-xs">
                        <tr class="bg-slate-900 text-white uppercase text-[10px] tracking-wider border-b border-slate-800">
                            {{-- 9 Identity Columns --}}
                            <th rowspan="2" class="px-3 py-2 text-center font-bold border-r border-slate-800 w-12 sticky left-0 z-30 bg-slate-900">No</th>
                            <th rowspan="2" class="px-3 py-2 text-left font-bold border-r border-slate-800 w-28 sticky left-12 z-30 bg-slate-900">Kode Produksi</th>
                            <th rowspan="2" class="px-3 py-2 text-left font-bold border-r border-slate-800 min-w-[220px]">Nama Item</th>
                            <th rowspan="2" class="px-2 py-2 text-center font-bold border-r border-slate-800 w-16">AISI</th>
                            <th rowspan="2" class="px-3 py-2 text-left font-bold border-r border-slate-800 w-32">Customer</th>
                            <th rowspan="2" class="px-2 py-2 text-right font-bold border-r border-slate-800 w-20">P.O (PCS)</th>
                            <th rowspan="2" class="px-2 py-2 text-right font-bold border-r border-slate-800 w-20">Plan (PCS)</th>
                            <th rowspan="2" class="px-2 py-2 text-right font-bold border-r border-slate-800 w-24">Weight (KG/PCS)</th>
                            <th rowspan="2" class="px-2 py-2 text-right font-bold border-r border-slate-800 w-28">Total Weight (KG)</th>

                            {{-- 10 Production Stages --}}
                            @foreach ($stagesList as $stgKey => $stgLabel)
                                <th colspan="2" class="px-2 py-1.5 text-center font-bold border-r border-slate-800 bg-sky-800 text-sky-100">
                                    {{ $stgLabel }}
                                </th>
                            @endforeach
                        </tr>

                        {{-- Row 2: Sub-headers for Stages (Hasil & Rusak) --}}
                        <tr class="bg-slate-800 text-slate-200 uppercase text-[9px] font-bold tracking-wider border-b border-slate-700">
                            @foreach ($stagesList as $stgKey => $stgLabel)
                                <th class="px-2 py-1 text-right border-r border-slate-700 w-16 bg-slate-800/90 text-emerald-300">Hasil</th>
                                <th class="px-2 py-1 text-right border-r border-slate-700 w-16 bg-slate-800/90 text-rose-300">Rusak</th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse ($data['pergerakan'] as $idx => $row)
                            <tr class="hover:bg-blue-50/50 transition-colors {{ $idx % 2 === 0 ? 'bg-white' : 'bg-slate-50/40' }}">
                                {{-- 1. No --}}
                                <td class="px-3 py-2 text-center text-slate-500 font-mono border-r border-slate-200 sticky left-0 z-10 {{ $idx % 2 === 0 ? 'bg-white' : 'bg-slate-50' }}">
                                    {{ $idx + 1 }}
                                </td>

                                {{-- 2. Kode Produksi --}}
                                <td class="px-3 py-2 font-bold text-slate-900 border-r border-slate-200 sticky left-12 z-10 {{ $idx % 2 === 0 ? 'bg-white' : 'bg-slate-50' }}">
                                    <span class="px-1.5 py-0.5 rounded bg-slate-100 border border-slate-200 font-mono text-[11px] text-slate-800">{{ $row['code'] }}</span>
                                </td>

                                {{-- 3. Nama Item --}}
                                <td class="px-3 py-2 font-medium text-slate-800 border-r border-slate-200 max-w-xs truncate" title="{{ $row['item_name'] }}">
                                    {{ $row['item_name'] }}
                                </td>

                                {{-- 4. AISI --}}
                                <td class="px-2 py-2 text-center text-slate-600 font-mono border-r border-slate-200">
                                    {{ $row['aisi'] }}
                                </td>

                                {{-- 5. Customer --}}
                                <td class="px-3 py-2 text-slate-700 border-r border-slate-200 truncate" title="{{ $row['customer'] }}">
                                    {{ $row['customer'] }}
                                </td>

                                {{-- 6. P.O (PCS) --}}
                                <td class="px-2 py-2 text-right border-r border-slate-200 font-mono">
                                    @if(is_numeric($row['po_qty']))
                                        <span class="font-bold text-slate-800">{{ number_format($row['po_qty'], 0, ',', '.') }}</span>
                                    @elseif($row['po_qty'] === 'Bervariasi')
                                        <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-200">Bervariasi</span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>

                                {{-- 7. Plan (PCS) --}}
                                <td class="px-2 py-2 text-right border-r border-slate-200 font-mono">
                                    @if(is_numeric($row['qty_planned']))
                                        <span class="text-slate-700 font-semibold">{{ number_format($row['qty_planned'], 0, ',', '.') }}</span>
                                    @elseif($row['qty_planned'] === 'Bervariasi')
                                        <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-200">Bervariasi</span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>

                                {{-- 8. Weight (KG/PCS) --}}
                                <td class="px-2 py-2 text-right border-r border-slate-200 font-mono">
                                    @if($row['unit_weight_label'] === 'Bervariasi')
                                        <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-200" title="Variasi bobot terdeteksi">Bervariasi</span>
                                    @elseif(is_numeric($row['unit_weight_label']))
                                        <span class="text-slate-700">{{ number_format((float) $row['unit_weight_label'], 2, ',', '.') }}</span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>

                                {{-- 9. Total Weight (KG) --}}
                                <td class="px-2 py-2 text-right border-r border-slate-200 font-mono font-bold text-slate-900 bg-slate-50/50">
                                    {{ number_format((float) $row['total_weight_kg'], 2, ',', '.') }}
                                </td>

                                {{-- 10-29. 10 Production Stages (Hasil & Rusak) --}}
                                @foreach ($stagesList as $stgKey => $stgLabel)
                                    @php
                                        $stageInfo = $row['stages'][$stgKey] ?? null;
                                        $h = $stageInfo['hasil'] ?? null;
                                        $r = $stageInfo['rusak'] ?? null;
                                    @endphp

                                    {{-- Hasil --}}
                                    <td class="px-2 py-2 text-right border-r border-slate-100 font-mono {{ $h !== null ? 'text-slate-800 font-semibold bg-emerald-50/20' : 'text-slate-400' }}">
                                        {{ $h !== null ? number_format($h, 0, ',', '.') : '—' }}
                                    </td>

                                    {{-- Rusak --}}
                                    <td class="px-2 py-2 text-right border-r border-slate-200 font-mono {{ (int)$r > 0 ? 'text-rose-600 font-bold bg-rose-50/40' : ($r !== null ? 'text-slate-500' : 'text-slate-400') }}">
                                        {{ $r !== null ? number_format($r, 0, ',', '.') : '—' }}
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="29" class="px-6 py-12 text-center text-slate-400">
                                    <i class="fas fa-inbox text-3xl mb-2 text-slate-300"></i>
                                    <div class="text-xs font-semibold">Tidak ada data pergerakan produksi pada rentang tanggal ini.</div>
                                    <div class="text-[11px] text-slate-400 mt-1">Coba ubah tanggal filter atau kata kunci pencarian.</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Table Footer Summary --}}
            <div class="px-5 py-3 border-t border-slate-200 bg-slate-50 flex items-center justify-between text-xs text-slate-600">
                <div class="flex items-center gap-4">
                    <span>Total Baris: <strong>{{ count($data['pergerakan']) }}</strong> Kode Produksi</span>
                </div>
                <div class="text-[11px] text-slate-400">
                    Kuantitas tahap yang belum tercapai ditampilkan <strong class="text-slate-600">—</strong>. Rusak = 0 menandakan tahap selesai tanpa cacat.
                </div>
            </div>
        </div>
    </div>

</div>
@endsection
