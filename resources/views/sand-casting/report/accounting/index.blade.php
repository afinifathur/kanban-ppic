@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full no-print">
        <div>
            <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded text-[10px] font-black bg-emerald-600 text-white tracking-wide uppercase">SAND CASTING</span>
                <h1 class="text-lg font-black text-slate-800 tracking-tight leading-tight uppercase">LAPORAN ACCOUNTING</h1>
            </div>
            <p class="text-slate-500 text-[11px] mt-0.5">Horizontal Production Tracking — {{ ($filters['report_format'] ?? 'detail') === 'summary' ? 'Ringkas per Kode Produksi (Seluruh Heat Number Digabung)' : 'Satu Baris per Kode Produksi + Heat Number (COR s/d Gudang Jadi)' }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('sand-casting.report.accounting.export.excel', request()->query()) }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-xs transition-colors" title="Download Excel (.xlsx) Pergerakan Produksi">
                <i class="fas fa-file-excel"></i>
                <span>Export Excel</span>
            </a>
            <a href="{{ route('sand-casting.report.accounting.export.pdf', request()->query()) }}" target="_blank" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-xs transition-colors" title="Cetak / Export PDF (Landscape)">
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
                    @foreach($data['anomalies'] as $k => $anom)
                        @if(isset($anom['message']))
                            <div>• {{ $anom['message'] }}</div>
                        @elseif(isset($anom['weights']))
                            <div>• Kode: <strong>{{ $anom['code'] }}</strong> | Heat: <strong>{{ $anom['heat_number'] }}</strong> (Bobot tercatat: {{ implode(' kg, ', $anom['weights']) }} kg)</div>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- Filter Panel --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <form action="{{ route('sand-casting.report.accounting.index') }}" method="GET" class="space-y-4">
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
                        value="{{ $filters['date_from'] }}"
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
                        value="{{ $filters['date_to'] }}"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs font-semibold focus:bg-white focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 outline-none"
                    >
                </div>

                {{-- 3. Report Format --}}
                <div>
                    <label for="report_format" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1">
                        <i class="fas fa-layer-group text-emerald-600 mr-1"></i> Format Laporan
                    </label>
                    <select
                        name="report_format"
                        id="report_format"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs font-semibold focus:bg-white focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 outline-none"
                    >
                        <option value="detail" {{ ($filters['report_format'] ?? 'detail') === 'detail' ? 'selected' : '' }}>
                            Detail per Heat Number
                        </option>
                        <option value="summary" {{ in_array($filters['report_format'] ?? '', ['summary', 'ringkas']) ? 'selected' : '' }}>
                            Ringkas per Kode Produksi
                        </option>
                    </select>
                </div>

                {{-- 4. Search Input --}}
                <div>
                    <label for="search" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1">
                        <i class="fas fa-search text-emerald-600 mr-1"></i> Cari (Kode / Heat / Item / Customer)
                    </label>
                    <input
                        type="text"
                        name="search"
                        id="search"
                        value="{{ $filters['search'] }}"
                        placeholder="Contoh: 268ET / HEAT-01 / FLANGE"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs focus:bg-white focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 outline-none"
                    >
                </div>

                {{-- 5. Submit Button --}}
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
        $isSummary = ($filters['report_format'] ?? 'detail') === 'summary' || ($filters['report_format'] ?? '') === 'ringkas';
        $stagesList = ['cor' => 'COR', 'netto' => 'NETTO', 'bubut_od' => 'BUBUT OD', 'bubut_cnc' => 'BUBUT CNC', 'bor' => 'BOR', 'qc' => 'QC', 'gudang_jadi' => 'GUDANG JADI'];
    @endphp

    {{-- ======================================================== --}}
    {{-- HORIZONTAL PRODUCTION TRACKING (PRIMARY TABLE)            --}}
    {{-- ======================================================== --}}
    <div class="space-y-4">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200 bg-slate-50 flex items-center justify-between">
                <div>
                    <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        Matriks Tracking Produksi Horizontal — {{ $isSummary ? 'Ringkas per Kode Produksi' : 'Detail per Heat Number' }}
                    </h3>
                    <p class="text-[10px] text-slate-500">
                        {{ $isSummary ? 'Seluruh Heat Number pada Kode Produksi yang sama digabungkan menjadi satu baris (COR s/d Gudang Jadi)' : 'Aliran perjalanan produksi dari COR hingga Gudang Jadi lengkap dengan Hasil dan Rusak per tahapan' }}
                    </p>
                </div>
                <span class="text-xs font-bold font-mono text-slate-700 bg-white border border-slate-200 px-2.5 py-1 rounded-md shadow-2xs">
                    {{ count($data['pergerakan']) }} {{ $isSummary ? 'Kode Produksi' : 'Kombinasi' }}
                </span>
            </div>

            <div class="overflow-x-auto max-h-[650px]">
                <table class="w-full text-left text-xs border-collapse">
                    {{-- Grouped Header 2-Tier --}}
                    <thead class="sticky top-0 z-20 shadow-xs">
                        {{-- Tier 1: Parent Groups --}}
                        <tr class="text-[10px] font-bold uppercase tracking-wider text-white">
                            <th rowspan="2" class="py-2.5 px-3 bg-slate-800 border-r border-slate-700 text-center w-10">No</th>
                            <th rowspan="2" class="py-2.5 px-3 bg-slate-800 border-r border-slate-700 whitespace-nowrap">Kode Produksi</th>
                            @if(!$isSummary)
                                <th rowspan="2" class="py-2.5 px-3 bg-slate-800 border-r border-slate-700 whitespace-nowrap">Heat Number</th>
                            @endif
                            <th rowspan="2" class="py-2.5 px-3 bg-slate-800 border-r border-slate-700 min-w-[170px]">Nama Item</th>
                            <th rowspan="2" class="py-2.5 px-2 bg-slate-800 border-r border-slate-700 text-center">AISI</th>
                            <th rowspan="2" class="py-2.5 px-3 bg-slate-800 border-r border-slate-700 min-w-[120px]">Customer</th>
                            @if($isSummary)
                                <th rowspan="2" class="py-2.5 px-2.5 bg-slate-800 border-r border-slate-700 text-center whitespace-nowrap">P.O (PCS)</th>
                                <th rowspan="2" class="py-2.5 px-2.5 bg-slate-800 border-r border-slate-700 text-center whitespace-nowrap">Plan (PCS)</th>
                            @endif
                            <th rowspan="2" class="py-2.5 px-2.5 bg-slate-800 border-r border-slate-700 text-right whitespace-nowrap">Weight (KG/PCS)</th>
                            <th rowspan="2" class="py-2.5 px-2.5 bg-slate-800 border-r border-slate-700 text-right whitespace-nowrap">Total Weight (KG)</th>

                            {{-- 7 Department Groups --}}
                            @foreach($stagesList as $stKey => $stLabel)
                                <th colspan="2" class="py-1 px-2 border-r border-sky-800 text-center {{ $loop->last ? '' : 'border-r' }} bg-sky-700">
                                    {{ $stLabel }}
                                </th>
                            @endforeach
                        </tr>

                        {{-- Tier 2: Subcolumns Hasil & Rusak --}}
                        <tr class="text-[9.5px] font-bold uppercase tracking-wider bg-slate-900 text-slate-300 text-center">
                            @foreach($stagesList as $stKey => $stLabel)
                                <th class="py-1 px-1.5 border-r border-slate-700 w-11 font-mono text-slate-200">Hasil</th>
                                <th class="py-1 px-1.5 border-r border-slate-700 w-11 font-mono text-rose-300">Rusak</th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100 text-slate-800">
                        @forelse($data['pergerakan'] as $idx => $r)
                            <tr class="hover:bg-blue-50/40 transition-colors {{ $idx % 2 === 0 ? 'bg-white' : 'bg-slate-50/40' }}">
                                <td class="py-2 px-3 text-center text-slate-400 font-mono text-[11px] border-r border-slate-100">{{ $idx + 1 }}</td>
                                <td class="py-2 px-3 font-bold text-slate-900 whitespace-nowrap border-r border-slate-100">{{ $r['code'] }}</td>
                                @if(!$isSummary)
                                    <td class="py-2 px-3 font-mono font-semibold text-slate-800 whitespace-nowrap border-r border-slate-100">{{ $r['heat_number'] }}</td>
                                @endif
                                <td class="py-2 px-3 text-slate-800 font-medium whitespace-normal max-w-[220px] border-r border-slate-100" title="{{ $r['item_name'] }}">
                                    {{ $r['item_name'] }}
                                </td>
                                <td class="py-2 px-2 text-center text-slate-600 font-mono text-[11px] border-r border-slate-100">{{ $r['aisi'] }}</td>
                                <td class="py-2 px-3 text-slate-600 text-[11px] whitespace-normal max-w-[140px] border-r border-slate-100">{{ $r['customer'] }}</td>

                                @if($isSummary)
                                    {{-- P.O (PCS) --}}
                                    <td class="py-2 px-2.5 text-right font-mono text-[11px] border-r border-slate-100">
                                        @if(($r['is_po_anomaly'] ?? false) || ($r['po_number'] ?? '') === 'Bervariasi' || ($r['po_qty'] ?? '') === 'Bervariasi')
                                            <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 text-center block" title="Variasi kuantitas P.O antar Heat Number">Bervariasi</span>
                                        @elseif(is_numeric($r['po_qty'] ?? $r['po_number'] ?? null))
                                            <span class="font-bold text-slate-800">{{ number_format($r['po_qty'] ?? $r['po_number'], 0, ',', '.') }}</span>
                                        @else
                                            <span></span>
                                        @endif
                                    </td>
                                    {{-- Plan (PCS) --}}
                                    <td class="py-2 px-2.5 text-right font-mono text-[11px] border-r border-slate-100">
                                        @if(($r['is_plan_anomaly'] ?? false) || ($r['qty_planned'] ?? '') === 'Bervariasi')
                                            <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 text-center block" title="Variasi Plan antar Heat Number">Bervariasi</span>
                                        @elseif(is_numeric($r['qty_planned'] ?? null))
                                            <span class="font-bold text-slate-800">{{ number_format($r['qty_planned'], 0, ',', '.') }}</span>
                                        @else
                                            <span></span>
                                        @endif
                                    </td>
                                @endif

                                {{-- Weight --}}
                                <td class="py-2 px-2.5 text-right font-mono text-slate-700 border-r border-slate-100">
                                    @if(($r['is_weight_anomaly'] ?? false) || ($r['unit_weight_label'] ?? '') === 'Bervariasi')
                                        <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800" title="{{ $r['anomaly_notes'] ?? 'Variasi bobot satuan antar Heat Number' }}">Bervariasi</span>
                                    @elseif(is_numeric($r['unit_weight_kg'] ?? null) && (float)$r['unit_weight_kg'] > 0)
                                        {{ number_format($r['unit_weight_kg'], 2, ',', '.') }}
                                    @else
                                        <span class="text-slate-300">—</span>
                                    @endif
                                </td>
                                <td class="py-2 px-2.5 text-right font-mono font-bold text-slate-800 border-r border-slate-200 bg-slate-50/50">
                                    {{ number_format($r['total_weight_kg'], 2, ',', '.') }}
                                </td>

                                {{-- 7 Stages Loop: Hasil & Rusak --}}
                                @foreach(array_keys($stagesList) as $stKey)
                                    @php
                                        $st = $r['stages'][$stKey] ?? null;
                                        $hasData = ($st && $st['is_done']);
                                        $hasilVal = $hasData && $st['hasil'] !== null ? number_format($st['hasil'], 0, ',', '.') : '—';
                                        $rusakRaw = $hasData && $st['rusak'] !== null ? (int)$st['rusak'] : null;
                                        $rusakVal = $rusakRaw !== null ? number_format($rusakRaw, 0, ',', '.') : '—';
                                    @endphp
                                    {{-- Hasil --}}
                                    <td class="py-1.5 px-1 text-center font-mono text-[11px] border-r border-slate-100 {{ $hasData && $st['hasil'] !== null ? 'font-bold text-slate-900' : 'text-slate-300' }}">
                                        {{ $hasilVal }}
                                    </td>
                                    {{-- Rusak --}}
                                    <td class="py-1.5 px-1 text-center font-mono text-[11px] border-r border-slate-200 {{ $rusakRaw !== null && $rusakRaw > 0 ? 'font-bold text-rose-600 bg-rose-50/40' : ($rusakRaw === 0 ? 'text-slate-400' : 'text-slate-300') }}">
                                        {{ $rusakVal }}
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $isSummary ? 23 : 22 }}" class="py-12 text-center text-slate-400">
                                    <i class="fas fa-inbox text-3xl mb-2 block"></i>
                                    Tidak ada pergerakan produksi pada rentang tanggal ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Footnote / Legend Card --}}
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-xs text-xs text-slate-600 space-y-2">
            <div class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fas fa-info-circle text-emerald-600"></i>
                <span>Keterangan Matriks Pergerakan Produksi:</span>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-[11px]">
                <div class="bg-slate-50 p-2.5 rounded-lg border border-slate-100">
                    <strong class="text-slate-800 font-mono">Hasil:</strong> Kuantitas (PCS) yang telah diselesaikan pada tahapan tersebut, termasuk barang yang kemudian dinyatakan cacat.
                </div>
                <div class="bg-slate-50 p-2.5 rounded-lg border border-slate-100">
                    <strong class="text-rose-700 font-mono">Rusak:</strong> Kuantitas (PCS) defect yang dicatat pada tahapan tersebut.
                </div>
                <div class="bg-slate-50 p-2.5 rounded-lg border border-slate-100">
                    <strong class="text-emerald-700 font-mono">Output:</strong> Kuantitas bersih yang diteruskan ke tahapan berikutnya (<span class="font-mono font-semibold">Hasil - Rusak</span>).
                </div>
            </div>
            <div class="text-[10px] text-slate-500 pt-1 border-t border-slate-100 flex flex-wrap gap-x-6 gap-y-1">
                <span>• Tanda <strong class="font-mono text-slate-400">—</strong> : Tahapan proses belum tercapai / belum dikerjakan pada periode ini.</span>
                <span>• Angka <strong class="font-mono text-slate-700">0</strong> : Tahapan proses telah selesai dikerjakan dengan cacat 0 PCS.</span>
                <span>• <strong class="text-slate-700">Total Weight (KG)</strong> : Dihitung dari Kuantitas Fisik Batch × Bobot Satuan (KG/PCS), tidak digandakan antar departemen.</span>
            </div>
        </div>
    </div>

</div>
@endsection
