@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded text-[10px] font-black bg-blue-600 text-white tracking-wide uppercase">QC VERIFIED</span>
                <h1 class="text-lg font-bold text-slate-800 leading-tight uppercase">LAPORAN RINCIAN DEFECT QC</h1>
            </div>
            <p class="text-slate-500 text-[11px] mt-0.5">Analisis rincian jenis cacat fisik hasil verifikasi inspeksi QC (Status: CONFIRMED)</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('sand-casting.report.qc-defects.export.excel', request()->query()) }}" class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-bold text-xs rounded-lg shadow-xs transition-all flex items-center gap-1.5" title="Download Excel">
                <i class="fas fa-file-excel"></i>
                <span>Export Excel</span>
            </a>
            <a href="{{ route('sand-casting.report.qc-defects.export.pdf', request()->query()) }}" target="_blank" class="px-3.5 py-2 bg-rose-600 hover:bg-rose-700 active:bg-rose-800 text-white font-bold text-xs rounded-lg shadow-xs transition-all flex items-center gap-1.5" title="Cetak / Export PDF">
                <i class="fas fa-file-pdf"></i>
                <span>Cetak / PDF</span>
            </a>
        </div>
    </div>
@endsection

@section('content')
<div class="space-y-5">

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
    <div class="bg-white rounded-xl shadow-xs border border-slate-200 p-4">
        <form action="{{ route('sand-casting.report.qc-defects.index') }}" method="GET" class="space-y-3">
            {{-- 45-Day Period Notice --}}
            <div class="bg-amber-50/80 border border-amber-200/80 rounded-lg px-3 py-2 flex flex-col sm:flex-row sm:items-center justify-between gap-1 text-[11px] text-amber-800">
                <div class="flex items-center gap-1.5 font-medium">
                    <i class="fas fa-info-circle text-amber-600 shrink-0"></i>
                    <span><strong>Batas Periode Laporan:</strong> Maksimal <strong>45 hari kalender inklusif</strong> (contoh: 01/09/2026 s/d 15/10/2026).</span>
                </div>
                <div class="text-[10px] text-amber-700 font-semibold sm:text-right">
                    Periode lebih panjang dapat diekspor menjadi beberapa laporan terpisah.
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3 items-end">
                {{-- 1. Date From --}}
                <div>
                    <label for="date_from" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1">
                        <i class="fas fa-calendar-alt text-blue-600 mr-1"></i> Tanggal Dari
                    </label>
                    <input
                        type="date"
                        name="date_from"
                        id="date_from"
                        value="{{ old('date_from', $filters['date_from']) }}"
                        class="w-full px-2.5 py-1.5 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs font-semibold focus:bg-white focus:border-blue-600 focus:ring-1 focus:ring-blue-100 outline-none"
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
                        value="{{ old('date_to', $filters['date_to']) }}"
                        class="w-full px-2.5 py-1.5 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs font-semibold focus:bg-white focus:border-blue-600 focus:ring-1 focus:ring-blue-100 outline-none"
                    >
                </div>

                {{-- 3. Stage Dropdown --}}
                <div>
                    <label for="stage" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1">
                        <i class="fas fa-layer-group text-blue-600 mr-1"></i> Tahapan
                    </label>
                    <select
                        name="stage"
                        id="stage"
                        class="w-full px-2.5 py-1.5 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs font-semibold focus:bg-white focus:border-blue-600 focus:ring-1 focus:ring-blue-100 outline-none"
                        onchange="this.form.submit()"
                    >
                        <option value="all" {{ old('stage', $filters['stage']) === 'all' ? 'selected' : '' }}>Semua Tahap</option>
                        @foreach($stages as $stKey => $stLabel)
                            <option value="{{ $stKey }}" {{ old('stage', $filters['stage']) === $stKey ? 'selected' : '' }}>{{ $stLabel }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- 4. Defect Type Dropdown --}}
                <div>
                    <label for="defect_type_id" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1">
                        <i class="fas fa-bug text-blue-600 mr-1"></i> Jenis Cacat
                    </label>
                    <select
                        name="defect_type_id"
                        id="defect_type_id"
                        class="w-full px-2.5 py-1.5 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs font-semibold focus:bg-white focus:border-blue-600 focus:ring-1 focus:ring-blue-100 outline-none"
                    >
                        <option value="all">Semua Jenis Cacat</option>
                        @foreach($availableDefectTypes as $type)
                            <option value="{{ $type->id }}" {{ (string)old('defect_type_id', $filters['defect_type_id']) === (string)$type->id ? 'selected' : '' }}>
                                {{ $type->name }} ({{ strtoupper($type->department ?? 'ALL') }})
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- 5. Search Input --}}
                <div>
                    <label for="search" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1">
                        <i class="fas fa-search text-blue-600 mr-1"></i> Cari Data
                    </label>
                    <input
                        type="text"
                        name="search"
                        id="search"
                        value="{{ old('search', $filters['search']) }}"
                        placeholder="KTR / Heat / Produk / PO..."
                        class="w-full px-2.5 py-1.5 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs focus:bg-white focus:border-blue-600 focus:ring-1 focus:ring-blue-100 outline-none font-medium"
                    >
                </div>
            </div>

            {{-- Submit / Reset --}}
            <div class="pt-2.5 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div class="text-[11px] text-slate-500 font-medium">
                    Periode: <strong class="text-slate-700">{{ \Carbon\Carbon::parse($filters['date_from'])->format('d/m/Y') }}</strong> s/d <strong class="text-slate-700">{{ \Carbon\Carbon::parse($filters['date_to'])->format('d/m/Y') }}</strong>
                    &nbsp;·&nbsp; Basis Tanggal: <span class="bg-blue-50 text-blue-700 px-1.5 py-0.5 rounded text-[10px] font-semibold">Inspeksi / QC Verified</span>
                    &nbsp;·&nbsp; <span class="bg-amber-50 text-amber-700 border border-amber-200 px-1.5 py-0.5 rounded text-[10px] font-semibold"><i class="fas fa-calendar-day mr-0.5"></i> Maks. 45 Hari Inklusif</span>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('sand-casting.report.qc-defects.index') }}" class="px-3.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-lg transition-colors">
                        Reset Filter
                    </a>
                    <button type="submit" class="px-4 py-1.5 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white font-bold text-xs rounded-lg shadow-xs transition-all flex items-center gap-1.5">
                        <i class="fas fa-filter text-[10px]"></i>
                        <span>Terapkan Filter</span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    {{-- KPI Summary Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <!-- 1. Total Defect PCS -->
        <div class="bg-white rounded-xl border border-slate-200 p-3.5 shadow-xs">
            <div class="flex items-center justify-between text-slate-400 mb-1">
                <span class="text-[10px] font-bold uppercase tracking-wider">Total Defect Terverifikasi</span>
                <i class="fas fa-times-circle text-rose-500 text-sm"></i>
            </div>
            <div class="flex items-baseline gap-1">
                <span class="text-2xl font-black text-rose-600 font-mono">{{ number_format($summary['total_defect_pcs']) }}</span>
                <span class="text-xs font-bold text-slate-500">PCS</span>
            </div>
            <p class="text-[10px] text-slate-400 mt-1">Akumulasi rincian cacat terkonfirmasi</p>
        </div>

        <!-- 2. Total Estimated Weight KG -->
        <div class="bg-white rounded-xl border border-slate-200 p-3.5 shadow-xs">
            <div class="flex items-center justify-between text-slate-400 mb-1">
                <span class="text-[10px] font-bold uppercase tracking-wider">Estimasi Berat Defect</span>
                <i class="fas fa-weight-hanging text-amber-500 text-sm"></i>
            </div>
            <div class="flex items-baseline gap-1">
                <span class="text-2xl font-black text-slate-900 font-mono">{{ number_format($summary['total_estimated_kg'], 2) }}</span>
                <span class="text-xs font-bold text-slate-500">KG</span>
            </div>
            <div class="mt-1 flex items-center gap-1">
                @if($summary['is_weight_fully_available'])
                    <span class="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-1.5 py-0.2 rounded border border-emerald-200">
                        100% basis berat tersedia
                    </span>
                @else
                    <span class="text-[10px] font-bold text-amber-700 bg-amber-50 px-1.5 py-0.2 rounded border border-amber-200" title="{{ number_format($summary['unavailable_weight_pcs']) }} PCS tanpa master berat">
                        Coverage {{ $summary['weight_coverage_pct'] }}% ({{ number_format($summary['unavailable_weight_pcs']) }} pcs tanpa berat)
                    </span>
                @endif
            </div>
        </div>

        <!-- 3. Affected KTRs -->
        <div class="bg-white rounded-xl border border-slate-200 p-3.5 shadow-xs">
            <div class="flex items-center justify-between text-slate-400 mb-1">
                <span class="text-[10px] font-bold uppercase tracking-wider">KTR Terdampak Cacat</span>
                <i class="fas fa-barcode text-blue-500 text-sm"></i>
            </div>
            <div class="flex items-baseline gap-1">
                <span class="text-2xl font-black text-slate-900 font-mono">{{ number_format($summary['affected_ktr_count']) }}</span>
                <span class="text-xs font-bold text-slate-500">Traveler</span>
            </div>
            <p class="text-[10px] text-slate-400 mt-1">
                @if($summary['zero_defect_ktr_count'] > 0)
                    +{{ number_format($summary['zero_defect_ktr_count']) }} KTR bebas cacat (0 Defect)
                @else
                    Jumlah fisik KTR yang memiliki defect
                @endif
            </p>
        </div>

        <!-- 4. Top Defect Type -->
        <div class="bg-white rounded-xl border border-slate-200 p-3.5 shadow-xs">
            <div class="flex items-center justify-between text-slate-400 mb-1">
                <span class="text-[10px] font-bold uppercase tracking-wider">Jenis Cacat Terbanyak</span>
                <i class="fas fa-chart-pie text-indigo-500 text-sm"></i>
            </div>
            <div class="text-sm font-extrabold text-indigo-900 truncate mt-1" title="{{ $summary['top_defect_type'] }}">
                {{ $summary['top_defect_type'] }}
            </div>
            <p class="text-[10px] text-slate-400 mt-1">Pareto defect frekuensi tertinggi</p>
        </div>
    </div>

    {{-- SECTION 1: PARETO SUMMARY TABLE (Grouped by Defect Type & Stage) --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="px-4 py-3 bg-slate-900 text-white flex items-center justify-between">
            <div class="flex items-center gap-2">
                <i class="fas fa-chart-bar text-amber-400 text-xs"></i>
                <h3 class="text-xs font-black uppercase tracking-wider">1. Ringkasan Pareto Defect (Per Jenis Cacat & Tahapan)</h3>
            </div>
            <span class="text-[11px] font-semibold text-slate-300">{{ count($paretoSummary) }} Kategori</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-xs text-left border-collapse">
                <thead class="bg-slate-800 text-slate-200 text-[10.5px] uppercase font-bold tracking-wider">
                    <tr>
                        <th class="py-2 px-3 text-center w-12 border-b border-slate-700">No</th>
                        <th class="py-2 px-3 border-b border-slate-700">Jenis Cacat</th>
                        <th class="py-2 px-3 text-center border-b border-slate-700">Tahap Produksi</th>
                        <th class="py-2 px-3 text-right border-b border-slate-700">Jumlah Rusak (PCS)</th>
                        <th class="py-2 px-3 text-right border-b border-slate-700">Kontribusi (%)</th>
                        <th class="py-2 px-3 text-right border-b border-slate-700">Estimasi Berat (KG)</th>
                        <th class="py-2 px-3 text-right border-b border-slate-700">Frekuensi KTR</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    @forelse($paretoSummary as $pIdx => $p)
                        <tr class="hover:bg-slate-50/70 transition-colors {{ $loop->even ? 'bg-slate-50/30' : 'bg-white' }}">
                            <td class="py-2 px-3 text-center font-mono font-bold text-slate-400">{{ $pIdx + 1 }}</td>
                            <td class="py-2 px-3 font-bold text-slate-900">
                                <span class="inline-block px-1.5 py-0.5 rounded bg-slate-100 text-slate-800 border border-slate-200 text-[11px] font-mono">
                                    {{ $p['defect_name'] }}
                                </span>
                            </td>
                            <td class="py-2 px-3 text-center">
                                <span class="inline-block px-2 py-0.5 rounded text-[10px] font-extrabold tracking-wide uppercase bg-blue-50 text-blue-800 border border-blue-200">
                                    {{ $p['stage_label'] }}
                                </span>
                            </td>
                            <td class="py-2 px-3 text-right font-black font-mono text-rose-600 text-sm">
                                {{ number_format($p['qty_pcs']) }}
                            </td>
                            <td class="py-2 px-3 text-right font-mono font-bold text-slate-700">
                                <div class="flex items-center justify-end gap-1.5">
                                    <div class="w-16 bg-slate-100 rounded-full h-1.5 overflow-hidden hidden sm:block">
                                        <div class="bg-rose-500 h-1.5 rounded-full" style="width: {{ min(100, $p['contribution_pct']) }}%"></div>
                                    </div>
                                    <span>{{ number_format($p['contribution_pct'], 1) }}%</span>
                                </div>
                            </td>
                            <td class="py-2 px-3 text-right font-mono text-slate-800 font-semibold">
                                {{ number_format($p['estimated_kg'], 2) }}
                                @if($p['has_unavailable_weight'])
                                    <span class="text-[9px] text-amber-600 font-sans" title="Sebagian KTR tidak memiliki data berat">*</span>
                                @endif
                            </td>
                            <td class="py-2 px-3 text-right font-mono text-slate-600">
                                {{ number_format($p['ktr_count']) }} KTR
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-slate-400 bg-slate-50/50">
                                <i class="fas fa-check-circle text-emerald-400 text-2xl mb-1.5 block"></i>
                                <span class="font-bold text-slate-600">Tidak ada data defect QC yang tercatat pada periode ini.</span>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if(count($paretoSummary) > 0)
                    <tfoot class="bg-slate-100 font-black text-slate-900 text-xs border-t-2 border-slate-300">
                        <tr>
                            <td colspan="3" class="py-2.5 px-3 text-right uppercase tracking-wider">TOTAL TERVERIFIKASI:</td>
                            <td class="py-2.5 px-3 text-right font-mono text-rose-600 text-sm">{{ number_format($summary['total_defect_pcs']) }}</td>
                            <td class="py-2.5 px-3 text-right font-mono">100.0%</td>
                            <td class="py-2.5 px-3 text-right font-mono">{{ number_format($summary['total_estimated_kg'], 2) }} KG</td>
                            <td class="py-2.5 px-3 text-right font-mono">{{ number_format($summary['affected_ktr_count']) }} KTR</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    {{-- SECTION 2: DETAILED TRACEABILITY TABLE --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="px-4 py-3 bg-slate-900 text-white flex items-center justify-between">
            <div class="flex items-center gap-2">
                <i class="fas fa-list-alt text-blue-400 text-xs"></i>
                <h3 class="text-xs font-black uppercase tracking-wider">2. Rincian Data Defect QC (Traceability Detail)</h3>
            </div>
            <span class="text-[11px] font-semibold text-slate-300">
                Menampilkan {{ $records->count() }} dari {{ number_format($summary['total_records_count']) }} baris
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-xs text-left border-collapse whitespace-nowrap">
                <thead class="bg-slate-800 text-slate-200 text-[10px] uppercase font-bold tracking-wider">
                    <tr>
                        <th class="py-2 px-2.5 text-center w-10 border-b border-slate-700">No</th>
                        <th class="py-2 px-2.5 border-b border-slate-700">Tanggal QC</th>
                        <th class="py-2 px-2.5 border-b border-slate-700">KTR (Traveler)</th>
                        <th class="py-2 px-2.5 border-b border-slate-700">Heat Number</th>
                        <th class="py-2 px-2.5 border-b border-slate-700">Kode Produksi</th>
                        <th class="py-2 px-2.5 border-b border-slate-700">PO</th>
                        <th class="py-2 px-3 border-b border-slate-700">Nama Barang & Customer</th>
                        <th class="py-2 px-2.5 text-center border-b border-slate-700">Tahap</th>
                        <th class="py-2 px-2.5 border-b border-slate-700">Jenis Cacat</th>
                        <th class="py-2 px-2.5 text-right border-b border-slate-700">Qty (PCS)</th>
                        <th class="py-2 px-2.5 text-right border-b border-slate-700">Estimasi (KG)</th>
                        <th class="py-2 px-2.5 border-b border-slate-700">Verifikator QC</th>
                        <th class="py-2 px-3 border-b border-slate-700">Catatan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    @forelse($records as $idx => $r)
                        @php
                            $rowNumber = ($records instanceof \Illuminate\Pagination\LengthAwarePaginator)
                                ? ($records->firstItem() + $idx)
                                : ($idx + 1);
                        @endphp
                        <tr class="hover:bg-blue-50/40 transition-colors {{ $loop->even ? 'bg-slate-50/40' : 'bg-white' }}">
                            <td class="py-2 px-2.5 text-center font-mono font-bold text-slate-400 text-[11px]">{{ $rowNumber }}</td>
                            <td class="py-2 px-2.5 font-mono text-slate-600 text-[11px]">{{ $r['verified_at_formatted'] }}</td>
                            <td class="py-2 px-2.5 font-mono font-bold text-slate-900">
                                <span class="bg-slate-100 px-1.5 py-0.5 rounded border border-slate-200">{{ $r['traveler_number'] }}</span>
                            </td>
                            <td class="py-2 px-2.5 font-mono font-bold text-amber-700">
                                <span class="flex items-center gap-1">
                                    <i class="fas fa-fire text-amber-500 text-[9px]"></i>
                                    {{ $r['heat_number'] }}
                                </span>
                            </td>
                            <td class="py-2 px-2.5 font-mono font-bold text-slate-800">{{ $r['production_code'] }}</td>
                            <td class="py-2 px-2.5 font-mono text-slate-500">{{ $r['po_number'] }}</td>
                            <td class="py-2 px-3 max-w-[220px] truncate" title="{{ $r['item_name'] }} · {{ $r['customer'] }}">
                                <div class="font-bold text-slate-800 truncate">{{ $r['item_name'] }}</div>
                                <div class="text-[10px] text-slate-400 truncate">{{ $r['customer'] }}</div>
                            </td>
                            <td class="py-2 px-2.5 text-center">
                                <span class="inline-block px-1.5 py-0.5 rounded text-[9.5px] font-extrabold uppercase bg-blue-50 text-blue-700 border border-blue-200">
                                    {{ $r['stage_label'] }}
                                </span>
                            </td>
                            <td class="py-2 px-2.5 font-bold text-slate-900">
                                <span class="inline-block px-1.5 py-0.5 rounded bg-rose-50 text-rose-800 border border-rose-200 text-[11px] font-mono">
                                    {{ $r['defect_name'] }}
                                </span>
                            </td>
                            <td class="py-2 px-2.5 text-right font-black font-mono text-rose-600 text-[13px]">
                                {{ number_format($r['qty']) }}
                            </td>
                            <td class="py-2 px-2.5 text-right font-mono text-slate-800">
                                @if($r['has_weight'])
                                    <span class="font-bold">{{ number_format($r['estimated_kg'], 2) }}</span>
                                @else
                                    <span class="text-slate-300 font-bold" title="Berat unit tidak tersedia">—</span>
                                @endif
                            </td>
                            <td class="py-2 px-2.5 text-slate-600 font-semibold">{{ $r['qc_verifier'] }}</td>
                            <td class="py-2 px-3 max-w-[150px] truncate text-slate-500 text-[11px]" title="{{ $r['notes'] }}">
                                {{ $r['notes'] }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="13" class="py-10 text-center text-slate-400 bg-slate-50/50">
                                <i class="fas fa-clipboard-check text-slate-300 text-3xl mb-2 block"></i>
                                <span class="font-bold text-slate-600">Tidak ada data rincian defect yang memenuhi filter pencarian.</span>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if($records instanceof \Illuminate\Pagination\LengthAwarePaginator && $records->hasPages())
            <div class="px-4 py-3 border-t border-slate-200 bg-slate-50 flex items-center justify-between">
                <div class="text-xs text-slate-500">
                    Menampilkan <span class="font-bold">{{ $records->firstItem() }}</span> s/d <span class="font-bold">{{ $records->lastItem() }}</span> dari <span class="font-bold">{{ $records->total() }}</span> catatan
                </div>
                <div>
                    {{ $records->links() }}
                </div>
            </div>
        @endif
    </div>

</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const fromInput = document.getElementById('date_from');
        const toInput = document.getElementById('date_to');

        if (!fromInput || !toInput) return;

        function padZero(num) {
            return String(num).padStart(2, '0');
        }

        function formatDate(date) {
            return `${date.getFullYear()}-${padZero(date.getMonth() + 1)}-${padZero(date.getDate())}`;
        }

        function updateDateBounds() {
            // If date_from is set:
            // 1. date_to cannot be earlier than date_from
            // 2. date_to cannot be more than 44 days after date_from (45 inclusive calendar days)
            if (fromInput.value) {
                toInput.min = fromInput.value;
                const fromDate = new Date(fromInput.value + 'T00:00:00');
                if (!isNaN(fromDate.getTime())) {
                    const maxTo = new Date(fromDate);
                    maxTo.setDate(maxTo.getDate() + 44);
                    toInput.max = formatDate(maxTo);
                }
            } else {
                toInput.min = '';
                toInput.max = '';
            }

            // If date_to is set:
            // 1. date_from cannot be later than date_to
            // 2. date_from cannot be more than 44 days before date_to (45 inclusive calendar days)
            if (toInput.value) {
                fromInput.max = toInput.value;
                const toDate = new Date(toInput.value + 'T00:00:00');
                if (!isNaN(toDate.getTime())) {
                    const minFrom = new Date(toDate);
                    minFrom.setDate(minFrom.getDate() - 44);
                    fromInput.min = formatDate(minFrom);
                }
            } else {
                fromInput.min = '';
                fromInput.max = '';
            }
        }

        fromInput.addEventListener('change', updateDateBounds);
        toInput.addEventListener('change', updateDateBounds);
        updateDateBounds();
    });
</script>
@endsection
