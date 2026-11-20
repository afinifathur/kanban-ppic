@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full no-print">
        <div>
            <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded text-[10px] font-black bg-emerald-600 text-white tracking-wide uppercase">FITTING PRODUCTION</span>
                <h1 class="text-lg font-black text-slate-800 tracking-tight leading-tight uppercase">STATUS PRODUKSI</h1>
            </div>
            <p class="text-slate-500 text-[11px] mt-0.5">Monitoring progres SPK, alokasi pasokan Kitir, WIP tahapan, reject QC, dan kesiapan Gudang Jadi</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('fitting.production-status.export', request()->query()) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-emerald-600 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-xs transition-colors" title="Download Excel (.xlsx)">
                <i class="fas fa-file-excel"></i>
                <span>Download Excel</span>
            </a>
            <button type="button" onclick="window.print()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-xs font-bold shadow-xs transition-colors">
                <i class="fas fa-print text-slate-500"></i>
                <span>Print / PDF</span>
            </button>
        </div>
    </div>
@endsection

@section('content')
<style>
    .compact-th {
        padding: 5px 3px !important;
        font-size: 9.5px !important;
        font-weight: 700 !important;
        line-height: 1.15;
        vertical-align: middle;
        text-align: center;
        letter-spacing: 0.02em;
    }
    .compact-td {
        padding: 4.5px 3px !important;
        font-size: 10px !important;
        line-height: 1.15;
        vertical-align: middle;
    }
    .prod-name-cell {
        min-width: 150px;
        max-width: 200px;
        white-space: normal;
        word-wrap: break-word;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        line-height: 1.15;
    }

    @media screen {
        .cell-wip-active {
            background-color: #ecfdf5 !important;
            color: #065f46 !important;
            font-weight: 700;
        }
        .cell-gd-active {
            background-color: #d1fae5 !important;
            color: #047857 !important;
            font-weight: 800;
        }
        .cell-defect-active {
            color: #e11d48 !important;
            font-weight: 700;
        }

        .table-scroll-container {
            max-height: calc(100vh - 270px);
            min-height: 400px;
        }

        #fittingProdStatusTable thead {
            position: sticky;
            top: 0;
            z-index: 25;
        }
        #fittingProdStatusTable thead tr:first-child th {
            position: sticky;
            top: 0;
            z-index: 25;
            background-color: #0f172a !important;
            box-shadow: inset 0 -1px 0 #334155;
        }
        #fittingProdStatusTable thead tr:nth-child(2) th {
            position: sticky;
            top: 23px;
            z-index: 24;
            background-color: #1e293b !important;
            box-shadow: inset 0 -1px 0 #334155, 0 1px 2px rgba(0,0,0,0.15);
        }

        .production-status-print {
            display: none !important;
        }
    }

    @media print {
        .production-status-web, .no-print {
            display: none !important;
        }
        .production-status-print {
            display: block !important;
        }
        html, body {
            background: white !important;
            font-family: Arial, Helvetica, sans-serif !important;
        }
        .ps-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8px;
        }
        .ps-table th, .ps-table td {
            border: 0.5px solid #64748b;
            padding: 3px 2px;
            text-align: center;
        }
        .ps-table th {
            background: #0f172a !important;
            color: #fff !important;
        }
    }
</style>

<div class="production-status-web space-y-3 pb-8">
    {{-- 1. KPI SUMMARY CARDS --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
        <div class="bg-white rounded-lg border border-slate-200 p-2.5 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">SPK AKTIF</p>
                <p class="text-xl font-black text-slate-800 leading-tight mt-0.5">{{ number_format($summary['total_spk_aktif']) }}</p>
            </div>
            <div class="w-8 h-8 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center text-sm">
                <i class="fas fa-tasks"></i>
            </div>
        </div>

        <div class="bg-white rounded-lg border border-slate-200 p-2.5 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">TARGET PO (PCS)</p>
                <p class="text-xl font-black text-slate-800 leading-tight mt-0.5">{{ number_format($summary['total_target_po']) }}</p>
            </div>
            <div class="w-8 h-8 rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center text-sm">
                <i class="fas fa-bullseye"></i>
            </div>
        </div>

        <div class="bg-white rounded-lg border border-slate-200 p-2.5 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">SELESAI GUDANG</p>
                <p class="text-xl font-black text-emerald-700 leading-tight mt-0.5">{{ number_format($summary['total_selesai_gd']) }}</p>
            </div>
            <div class="w-8 h-8 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm">
                <i class="fas fa-warehouse"></i>
            </div>
        </div>

        <div class="bg-white rounded-lg border border-slate-200 p-2.5 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">SISA TARGET</p>
                <p class="text-xl font-black text-amber-700 leading-tight mt-0.5">{{ number_format($summary['total_sisa_target']) }}</p>
            </div>
            <div class="w-8 h-8 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center text-sm">
                <i class="fas fa-hourglass-half"></i>
            </div>
        </div>
    </div>

    {{-- 2. TABS & FILTER BAR --}}
    <div class="bg-white rounded-lg border border-slate-200 shadow-xs p-3 space-y-3">
        {{-- Status Tabs --}}
        <div class="flex items-center justify-between border-b border-slate-200 pb-2">
            <div class="flex items-center gap-1.5 overflow-x-auto">
                <a href="{{ request()->fullUrlWithQuery(['tab' => 'active', 'page' => 1]) }}"
                    class="px-3 py-1.5 rounded-md text-xs font-bold transition-colors inline-flex items-center gap-1.5 {{ $filters['tab'] === 'active' ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    <span>Aktif</span>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] {{ $filters['tab'] === 'active' ? 'bg-emerald-800 text-white' : 'bg-slate-200 text-slate-700' }}">{{ $tab_counts['active'] }}</span>
                </a>
                <a href="{{ request()->fullUrlWithQuery(['tab' => 'completed', 'page' => 1]) }}"
                    class="px-3 py-1.5 rounded-md text-xs font-bold transition-colors inline-flex items-center gap-1.5 {{ $filters['tab'] === 'completed' ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    <span>Selesai</span>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] {{ $filters['tab'] === 'completed' ? 'bg-emerald-800 text-white' : 'bg-slate-200 text-slate-700' }}">{{ $tab_counts['completed'] }}</span>
                </a>
                <a href="{{ request()->fullUrlWithQuery(['tab' => 'all', 'page' => 1]) }}"
                    class="px-3 py-1.5 rounded-md text-xs font-bold transition-colors inline-flex items-center gap-1.5 {{ $filters['tab'] === 'all' ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    <span>Semua Status</span>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] {{ $filters['tab'] === 'all' ? 'bg-emerald-800 text-white' : 'bg-slate-200 text-slate-700' }}">{{ $tab_counts['all'] }}</span>
                </a>

                @if($tab_counts['non_spk'] > 0 || $filters['tab'] === 'non_spk')
                    <a href="{{ request()->fullUrlWithQuery(['tab' => 'non_spk', 'page' => 1]) }}"
                        class="px-3 py-1.5 rounded-md text-xs font-bold transition-colors inline-flex items-center gap-1.5 {{ $filters['tab'] === 'non_spk' ? 'bg-amber-600 text-white' : 'text-amber-700 hover:bg-amber-50' }}">
                        <i class="fas fa-unlink text-[10px]"></i>
                        <span>Non-SPK / Unassigned</span>
                        <span class="px-1.5 py-0.2 rounded-full text-[10px] {{ $filters['tab'] === 'non_spk' ? 'bg-amber-800 text-white' : 'bg-amber-200 text-amber-800' }}">{{ $tab_counts['non_spk'] }}</span>
                    </a>
                @endif
            </div>

            <div class="text-[11px] text-slate-500 font-medium">
                Menampilkan <strong class="text-slate-800">{{ $filters['tab'] === 'non_spk' ? $non_spk_rows->count() : $rows->total() }}</strong> rekaman
            </div>
        </div>

        {{-- Filters Form --}}
        <form method="GET" action="{{ route('fitting.production-status.index') }}" class="grid grid-cols-1 sm:grid-cols-12 gap-2 pt-0.5">
            <input type="hidden" name="tab" value="{{ $filters['tab'] }}">

            {{-- Line Filter --}}
            <div class="sm:col-span-3">
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-0.5">Line Fitting</label>
                <select name="line" class="w-full text-xs rounded border-slate-300 py-1.5 px-2 focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">Semua Line</option>
                    @foreach($lines as $num => $label)
                        <option value="{{ $num }}" {{ (string)$filters['line'] === (string)$num ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Search Input --}}
            <div class="sm:col-span-3">
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-0.5">Cari (Kode / Item / Customer)</label>
                <div class="relative">
                    <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Ketik kode produksi / item..."
                        class="w-full text-xs rounded border-slate-300 py-1.5 pl-2 pr-7 focus:ring-emerald-500 focus:border-emerald-500">
                    @if($filters['search'])
                        <a href="{{ request()->fullUrlWithQuery(['search' => null]) }}" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 text-xs">
                            <i class="fas fa-times-circle"></i>
                        </a>
                    @endif
                </div>
            </div>

            {{-- Date Basis --}}
            <div class="sm:col-span-2">
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-0.5">Basis Tanggal</label>
                <select name="date_basis" class="w-full text-xs rounded border-slate-300 py-1.5 px-2 focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="transfer" {{ $filters['date_basis'] === 'transfer' ? 'selected' : '' }}>Tgl Transfer Kitir</option>
                    <option value="spk" {{ $filters['date_basis'] === 'spk' ? 'selected' : '' }}>Tgl Buat SPK</option>
                </select>
            </div>

            {{-- Date Range --}}
            <div class="sm:col-span-3 flex items-center gap-1.5">
                <div class="w-1/2">
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-0.5">Dari</label>
                    <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="w-full text-xs rounded border-slate-300 py-1 px-1.5 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div class="w-1/2">
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-0.5">Sampai</label>
                    <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="w-full text-xs rounded border-slate-300 py-1 px-1.5 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
            </div>

            {{-- Buttons --}}
            <div class="sm:col-span-1 flex items-end gap-1">
                <button type="submit" class="w-full bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold py-1.5 px-2 rounded transition-colors shadow-xs" title="Terapkan Filter">
                    <i class="fas fa-filter"></i>
                </button>
                <a href="{{ route('fitting.production-status.index', ['tab' => $filters['tab']]) }}" class="bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold py-1.5 px-2 rounded transition-colors" title="Reset Filter">
                    <i class="fas fa-undo"></i>
                </a>
            </div>
        </form>
    </div>

    {{-- 3. MAIN PRODUCTION STATUS TABLE (TWO-TIER STICKY) --}}
    @if($filters['tab'] === 'non_spk')
        {{-- VIEW NON-SPK TRAVELERS --}}
        <div class="bg-white rounded-lg border border-slate-200 shadow-xs overflow-hidden">
            <div class="p-3 bg-amber-50 border-b border-amber-200 flex items-center justify-between">
                <div>
                    <h3 class="text-xs font-bold text-amber-900 uppercase">Antrean PFKTR Non-SPK / Belum Terhubung</h3>
                    <p class="text-[11px] text-amber-700">Kartu traveler ini belum dihubungkan ke SPK resmi dan diisolasi agar tidak mendistorsi target pesanan.</p>
                </div>
                <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-amber-200 text-amber-800">{{ $non_spk_rows->count() }} PFKTR</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border-collapse">
                    <thead class="bg-slate-900 text-white text-[10px] uppercase">
                        <tr>
                            <th class="p-2 text-center">No</th>
                            <th class="p-2">Nomor PFKTR</th>
                            <th class="p-2">Heat & Tgl Cor</th>
                            <th class="p-2">Line</th>
                            <th class="p-2 text-right">Transfer (PCS)</th>
                            <th class="p-2 text-right">Input Efektif</th>
                            <th class="p-2 text-right">Defect (PCS)</th>
                            <th class="p-2 text-center">Posisi Tahap</th>
                            <th class="p-2 text-center">Tgl Transfer</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse($non_spk_rows as $idx => $item)
                            <tr class="hover:bg-slate-50">
                                <td class="p-2 text-center font-bold text-slate-500">{{ $idx + 1 }}</td>
                                <td class="p-2 font-mono font-bold text-slate-800">{{ $item['traveler_number'] }}</td>
                                <td class="p-2">
                                    <span class="font-mono font-bold">{{ $item['heat_number'] }}</span>
                                    <span class="text-[10px] text-slate-400 block">{{ $item['cast_date'] }}</span>
                                </td>
                                <td class="p-2">
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-700">{{ $item['line_label'] }}</span>
                                </td>
                                <td class="p-2 text-right font-bold">{{ number_format($item['qty_transferred']) }}</td>
                                <td class="p-2 text-right font-bold text-emerald-700">{{ number_format($item['effective_input']) }}</td>
                                <td class="p-2 text-right font-bold text-rose-600">{{ number_format($item['total_defect']) }}</td>
                                <td class="p-2 text-center">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800">{{ $item['current_stage_label'] }}</span>
                                </td>
                                <td class="p-2 text-center text-slate-500 text-[11px]">{{ $item['transfer_date'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="p-6 text-center text-slate-400 text-xs">
                                    <i class="fas fa-check-circle text-emerald-500 text-xl block mb-1"></i>
                                    Tidak ada kartu traveler Non-SPK aktif. Seluruh traveler terhubung ke SPK resmi.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        {{-- STANDARD SPK PRODUCTION STATUS TABLE --}}
        <div class="bg-white rounded-lg border border-slate-200 shadow-xs overflow-hidden">
            <div class="table-scroll-container overflow-auto">
                <table id="fittingProdStatusTable" class="w-full border-collapse text-slate-800 text-left">
                    <thead>
                        {{-- TIER 1 HEADER --}}
                        <tr class="text-white">
                            <th class="compact-th text-center" style="width: 30px;">NO</th>
                            <th class="compact-th text-left" style="width: 95px;">KODE PROD</th>
                            <th class="compact-th text-left" style="width: 100px;">CUSTOMER</th>
                            <th class="compact-th text-left" style="min-width: 150px;">ITEM / PART</th>
                            <th class="compact-th text-right" style="width: 65px;">TARGET PO</th>
                            <th class="compact-th text-center" colspan="2" style="background-color: #1e293b; border-left: 1px solid #334155; border-right: 1px solid #334155;">PASOKAN KITIR</th>
                            <th class="compact-th text-center" colspan="2" style="background-color: #0f172a; border-right: 1px solid #334155;">NETTO PF</th>
                            <th class="compact-th text-center" colspan="2" style="background-color: #1e293b; border-right: 1px solid #334155;">KIMIA</th>
                            <th class="compact-th text-center" colspan="2" style="background-color: #0f172a; border-right: 1px solid #334155;">MARKING</th>
                            <th class="compact-th text-center" colspan="2" style="background-color: #1e293b; border-right: 1px solid #334155;">BUBUT CNC</th>
                            <th class="compact-th text-center" colspan="2" style="background-color: #0f172a; border-right: 1px solid #334155;">QC PF</th>
                            <th class="compact-th text-right" style="width: 65px;">GUDANG JADI</th>
                            <th class="compact-th text-right" style="width: 60px;">SISA PO</th>
                            <th class="compact-th text-center" style="width: 65px;">STATUS</th>
                            <th class="compact-th text-center no-print" style="width: 45px;">AKSI</th>
                        </tr>
                        {{-- TIER 2 HEADER --}}
                        <tr class="text-slate-300">
                            {{-- Spans for order info --}}
                            <th class="compact-th bg-slate-900" colspan="5"></th>
                            {{-- Pasokan Kitir Sub-headers --}}
                            <th class="compact-th bg-slate-800 text-right" style="border-left: 1px solid #334155;">TRF</th>
                            <th class="compact-th bg-slate-800 text-center" style="border-right: 1px solid #334155;">STATUS</th>
                            {{-- Netto PF --}}
                            <th class="compact-th bg-slate-900 text-right">WIP</th>
                            <th class="compact-th bg-slate-900 text-right text-rose-300" style="border-right: 1px solid #334155;">R</th>
                            {{-- Kimia --}}
                            <th class="compact-th bg-slate-800 text-right">WIP</th>
                            <th class="compact-th bg-slate-800 text-right text-rose-300" style="border-right: 1px solid #334155;">R</th>
                            {{-- Marking --}}
                            <th class="compact-th bg-slate-900 text-right">WIP</th>
                            <th class="compact-th bg-slate-900 text-right text-rose-300" style="border-right: 1px solid #334155;">R</th>
                            {{-- Bubut CNC --}}
                            <th class="compact-th bg-slate-800 text-right">WIP</th>
                            <th class="compact-th bg-slate-800 text-right text-rose-300" style="border-right: 1px solid #334155;">R</th>
                            {{-- QC PF --}}
                            <th class="compact-th bg-slate-900 text-right">WIP</th>
                            <th class="compact-th bg-slate-900 text-right text-rose-300" style="border-right: 1px solid #334155;">R</th>
                            {{-- Outflow spans --}}
                            <th class="compact-th bg-slate-900" colspan="4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse($rows as $idx => $r)
                            @php
                                $isCompletedRow = ($r['status'] === 'COMPLETED');
                            @endphp
                            <tr class="hover:bg-slate-50 transition-colors {{ $isCompletedRow ? 'bg-slate-50/60' : '' }}">
                                {{-- NO --}}
                                <td class="compact-td text-center text-slate-400 font-bold border-r border-slate-200">
                                    {{ $rows->firstItem() + $idx }}
                                </td>

                                {{-- KODE PRODUKSI --}}
                                <td class="compact-td font-mono font-bold text-slate-900 border-r border-slate-200">
                                    <div class="flex items-center gap-1">
                                        <span>{{ $r['code'] }}</span>
                                    </div>
                                    <span class="text-[9px] text-slate-400 font-normal block">{{ $r['line_label'] }}</span>
                                </td>

                                {{-- CUSTOMER --}}
                                <td class="compact-td text-slate-700 truncate max-w-[110px] border-r border-slate-200" title="{{ $r['customer'] }}">
                                    {{ $r['customer'] }}
                                </td>

                                {{-- ITEM / PART --}}
                                <td class="compact-td border-r border-slate-200">
                                    <div class="prod-name-cell font-semibold text-slate-800 text-[10.5px]" title="{{ $r['item_name'] }}">
                                        {{ $r['item_name'] }}
                                    </div>
                                </td>

                                {{-- TARGET PO --}}
                                <td class="compact-td text-right font-black text-slate-900 border-r border-slate-300 bg-slate-50/50">
                                    {{ number_format($r['po_target']) }}
                                </td>

                                {{-- PASOKAN: TRANSFER KITIR --}}
                                <td class="compact-td text-right font-bold text-slate-800">
                                    {{ number_format($r['kitir_transfer']) }}
                                </td>

                                {{-- PASOKAN: STATUS KITIR SHORTAGE BADGE --}}
                                <td class="compact-td text-center border-r border-slate-300">
                                    @if($r['kitir_badge'] === 'KITIR')
                                        <span class="px-1.5 py-0.5 rounded text-[9.5px] font-black bg-rose-100 text-rose-800 border border-rose-300 inline-block whitespace-nowrap" title="Kurang pasokan Kitir: {{ $r['shortage'] }} PCS">
                                            KITIR -{{ $r['shortage'] }}
                                        </span>
                                    @else
                                        <span class="px-1.5 py-0.5 rounded text-[9px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 inline-block">
                                            CUKUP
                                        </span>
                                    @endif
                                </td>

                                {{-- NETTO PF: WIP | R --}}
                                <td class="compact-td text-right {{ $r['wip_netto'] > 0 ? 'cell-wip-active' : 'text-slate-400' }}">
                                    {{ $r['wip_netto'] > 0 ? number_format($r['wip_netto']) : '-' }}
                                </td>
                                <td class="compact-td text-right border-r border-slate-300 {{ $r['r_netto'] > 0 ? 'cell-defect-active' : 'text-slate-300' }}">
                                    {{ $r['r_netto'] > 0 ? number_format($r['r_netto']) : '-' }}
                                </td>

                                {{-- KIMIA: WIP | R --}}
                                <td class="compact-td text-right {{ $r['wip_kimia'] > 0 ? 'cell-wip-active' : 'text-slate-400' }}">
                                    {{ $r['wip_kimia'] > 0 ? number_format($r['wip_kimia']) : '-' }}
                                </td>
                                <td class="compact-td text-right border-r border-slate-300 {{ $r['r_kimia'] > 0 ? 'cell-defect-active' : 'text-slate-300' }}">
                                    {{ $r['r_kimia'] > 0 ? number_format($r['r_kimia']) : '-' }}
                                </td>

                                {{-- MARKING: WIP | R --}}
                                <td class="compact-td text-right {{ $r['wip_marking'] > 0 ? 'cell-wip-active' : 'text-slate-400' }}">
                                    {{ $r['wip_marking'] > 0 ? number_format($r['wip_marking']) : '-' }}
                                </td>
                                <td class="compact-td text-right border-r border-slate-300 {{ $r['r_marking'] > 0 ? 'cell-defect-active' : 'text-slate-300' }}">
                                    {{ $r['r_marking'] > 0 ? number_format($r['r_marking']) : '-' }}
                                </td>

                                {{-- BUBUT CNC: WIP | R --}}
                                <td class="compact-td text-right {{ $r['wip_cnc'] > 0 ? 'cell-wip-active' : 'text-slate-400' }}">
                                    {{ $r['wip_cnc'] > 0 ? number_format($r['wip_cnc']) : '-' }}
                                </td>
                                <td class="compact-td text-right border-r border-slate-300 {{ $r['r_cnc'] > 0 ? 'cell-defect-active' : 'text-slate-300' }}">
                                    {{ $r['r_cnc'] > 0 ? number_format($r['r_cnc']) : '-' }}
                                </td>

                                {{-- QC PF: WIP | R --}}
                                <td class="compact-td text-right {{ $r['wip_qc'] > 0 ? 'cell-wip-active' : 'text-slate-400' }}">
                                    {{ $r['wip_qc'] > 0 ? number_format($r['wip_qc']) : '-' }}
                                </td>
                                <td class="compact-td text-right border-r border-slate-300 {{ $r['r_qc'] > 0 ? 'cell-defect-active' : 'text-slate-300' }}">
                                    {{ $r['r_qc'] > 0 ? number_format($r['r_qc']) : '-' }}
                                </td>

                                {{-- GUDANG JADI --}}
                                <td class="compact-td text-right font-black border-r border-slate-200 {{ $r['gd'] > 0 ? 'cell-gd-active' : 'text-slate-400' }}">
                                    {{ $r['gd'] > 0 ? number_format($r['gd']) : '-' }}
                                </td>

                                {{-- SISA PO --}}
                                <td class="compact-td text-right font-black border-r border-slate-200 {{ $r['remaining_po'] > 0 ? 'text-amber-700' : 'text-slate-400' }}">
                                    {{ number_format($r['remaining_po']) }}
                                </td>

                                {{-- STATUS SPK --}}
                                <td class="compact-td text-center border-r border-slate-200">
                                    @if($isCompletedRow)
                                        <span class="px-2 py-0.5 rounded text-[9.5px] font-black bg-emerald-600 text-white">SELESAI</span>
                                    @else
                                        <span class="px-2 py-0.5 rounded text-[9.5px] font-black bg-blue-100 text-blue-800">PROSES</span>
                                    @endif
                                </td>

                                {{-- AKSI: DETAIL PFKTR --}}
                                <td class="compact-td text-center no-print">
                                    <button type="button" onclick="openPlanDrawer({{ $r['production_plan_id'] }})"
                                        class="p-1 rounded text-slate-500 hover:text-emerald-700 hover:bg-emerald-50 transition-colors"
                                        title="Lihat Rincian PFKTR">
                                        <i class="fas fa-list-ul text-xs"></i>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="20" class="p-8 text-center text-slate-400">
                                    <i class="fas fa-inbox text-3xl text-slate-300 block mb-2"></i>
                                    <p class="text-xs font-semibold">Tidak ada data status produksi Fitting yang sesuai dengan filter.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- PAGINATION --}}
            @if($rows->hasPages())
                <div class="p-3 border-t border-slate-200 flex items-center justify-between no-print">
                    <span class="text-xs text-slate-500">
                        Menampilkan {{ $rows->firstItem() }} - {{ $rows->lastItem() }} dari {{ $rows->total() }} SPK
                    </span>
                    <div>
                        {{ $rows->links() }}
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>

{{-- 4. SLIDE-OVER DRAWER FOR PFKTR DETAIL DRILL-DOWN --}}
<div id="planDetailDrawer" class="fixed inset-0 z-50 overflow-hidden hidden" aria-labelledby="slide-over-title" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-xs transition-opacity" onclick="closePlanDrawer()"></div>

    <div class="fixed inset-y-0 right-0 max-w-full flex pl-10">
        <div class="w-screen max-w-2xl bg-white shadow-2xl flex flex-col">
            {{-- Drawer Header --}}
            <div class="p-4 bg-slate-900 text-white flex items-center justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 rounded text-[10px] font-black bg-emerald-600 uppercase">RINCIAN PFKTR</span>
                        <h2 id="drawerCode" class="text-base font-black font-mono tracking-tight">-</h2>
                    </div>
                    <p id="drawerItem" class="text-xs text-slate-300 mt-0.5">-</p>
                </div>
                <button type="button" onclick="closePlanDrawer()" class="text-slate-400 hover:text-white p-1 rounded">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>

            {{-- Drawer Quick Metrics --}}
            <div id="drawerMetrics" class="grid grid-cols-4 gap-2 p-3 bg-slate-50 border-b border-slate-200 text-xs">
                <div>
                    <span class="text-[10px] font-bold text-slate-400 uppercase block">Customer</span>
                    <strong id="drawerCustomer" class="text-slate-800 text-xs">-</strong>
                </div>
                <div>
                    <span class="text-[10px] font-bold text-slate-400 uppercase block">Target PO</span>
                    <strong id="drawerPoTarget" class="text-slate-800 text-xs">-</strong>
                </div>
                <div>
                    <span class="text-[10px] font-bold text-slate-400 uppercase block">Kitir Transfer</span>
                    <strong id="drawerKitirTransfer" class="text-slate-800 text-xs">-</strong>
                </div>
                <div>
                    <span class="text-[10px] font-bold text-slate-400 uppercase block">Gudang Jadi</span>
                    <strong id="drawerGd" class="text-emerald-700 text-xs">-</strong>
                </div>
            </div>

            {{-- Drawer Body --}}
            <div class="p-4 flex-1 overflow-y-auto space-y-4">
                <div id="drawerLoading" class="py-12 text-center text-slate-400">
                    <i class="fas fa-circle-notch fa-spin text-2xl text-emerald-600 block mb-2"></i>
                    <p class="text-xs">Memuat rincian kartu PFKTR...</p>
                </div>

                <div id="drawerContent" class="space-y-3 hidden">
                    {{-- Dynamically populated --}}
                </div>
            </div>

            {{-- Drawer Footer --}}
            <div class="p-3 bg-slate-100 border-t border-slate-200 text-right">
                <button type="button" onclick="closePlanDrawer()" class="px-4 py-1.5 rounded-md text-xs font-bold bg-white border border-slate-300 text-slate-700 hover:bg-slate-50">
                    Tutup
                </button>
            </div>
        </div>
    </div>
</div>

{{-- 5. PRINT / PDF LAYOUT --}}
<div class="production-status-print p-4">
    <div style="border-bottom: 2px solid #0f172a; padding-bottom: 8px; margin-bottom: 12px;">
        <h2 style="font-size: 14px; font-weight: 900; margin: 0; text-transform: uppercase;">PT. PERONI KARYA UTAMA — STATUS PRODUKSI FITTING</h2>
        <p style="font-size: 9px; color: #64748b; margin: 2px 0 0 0;">Laporan Posisi Fisik PFKTR, WIP Departemen & Rekap Defect per Kode Produksi</p>
        <p style="font-size: 8px; color: #334155; margin-top: 4px;">
            Dicetak: <strong>{{ now()->format('d/m/Y H:i:s') }}</strong> | Status: <strong>{{ strtoupper($filters['tab']) }}</strong> | Line: <strong>{{ $filters['line'] ? $lines[$filters['line']] : 'Semua Line' }}</strong>
        </p>
    </div>

    <table class="ps-table">
        <thead>
            <tr>
                <th>No</th>
                <th>Kode</th>
                <th>Customer</th>
                <th>Item / Part</th>
                <th>Target</th>
                <th>Kitir</th>
                <th>Netto WIP/R</th>
                <th>Kimia WIP/R</th>
                <th>Marking WIP/R</th>
                <th>CNC WIP/R</th>
                <th>QC WIP/R</th>
                <th>Gudang</th>
                <th>Sisa</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $pIdx => $pr)
                <tr>
                    <td>{{ $pIdx + 1 }}</td>
                    <td style="font-weight: bold; text-align: left;">{{ $pr['code'] }}</td>
                    <td style="text-align: left;">{{ $pr['customer'] }}</td>
                    <td style="text-align: left;">{{ $pr['item_name'] }}</td>
                    <td>{{ number_format($pr['po_target']) }}</td>
                    <td>{{ number_format($pr['kitir_transfer']) }}</td>
                    <td>{{ $pr['wip_netto'] }} / {{ $pr['r_netto'] }}</td>
                    <td>{{ $pr['wip_kimia'] }} / {{ $pr['r_kimia'] }}</td>
                    <td>{{ $pr['wip_marking'] }} / {{ $pr['r_marking'] }}</td>
                    <td>{{ $pr['wip_cnc'] }} / {{ $pr['r_cnc'] }}</td>
                    <td>{{ $pr['wip_qc'] }} / {{ $pr['r_qc'] }}</td>
                    <td style="font-weight: bold;">{{ number_format($pr['gd']) }}</td>
                    <td>{{ number_format($pr['remaining_po']) }}</td>
                    <td>{{ $pr['status'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<script>
    function openPlanDrawer(planId) {
        const drawer = document.getElementById('planDetailDrawer');
        const loading = document.getElementById('drawerLoading');
        const content = document.getElementById('drawerContent');

        drawer.classList.remove('hidden');
        loading.classList.remove('hidden');
        content.classList.add('hidden');
        content.innerHTML = '';

        fetch(`{{ url('/fitting/production-status') }}/${planId}/details`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(res => {
            if (!res.ok) throw new Error('Gagal memuat rincian');
            return res.json();
        })
        .then(data => {
            loading.classList.add('hidden');
            content.classList.remove('hidden');

            const p = data.plan;
            document.getElementById('drawerCode').textContent = p.code;
            document.getElementById('drawerItem').textContent = `${p.item_name} (${p.item_code || '-'})`;
            document.getElementById('drawerCustomer').textContent = p.customer;
            document.getElementById('drawerPoTarget').textContent = `${Number(p.po_target).toLocaleString()} PCS`;
            document.getElementById('drawerKitirTransfer').textContent = `${Number(p.kitir_transfer).toLocaleString()} PCS`;
            document.getElementById('drawerGd').textContent = `${Number(p.gd).toLocaleString()} PCS`;

            if (!data.items || data.items.length === 0) {
                content.innerHTML = `<p class="text-xs text-slate-400 text-center py-6">Tidak ada kartu PFKTR fisik aktif pada SPK ini.</p>`;
                return;
            }

            let html = '';
            data.items.forEach(it => {
                const isMulti = it.is_multi_allocation;
                let defectRows = '';

                Object.keys(it.stages).forEach(stKey => {
                    const st = it.stages[stKey];
                    if (st.defects && st.defects.length > 0) {
                        st.defects.forEach(df => {
                            defectRows += `
                                <div class="text-[11px] text-rose-700 bg-rose-50 px-2 py-0.5 rounded border border-rose-200 mt-1 flex justify-between">
                                    <span>[${st.label}] ${df.type} ${df.notes ? `(${df.notes})` : ''}</span>
                                    <strong>${df.qty} PCS</strong>
                                </div>
                            `;
                        });
                    }
                });

                html += `
                    <div class="border border-slate-200 rounded-lg p-3 bg-white shadow-xs space-y-2">
                        <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                            <div>
                                <span class="font-mono font-bold text-xs text-slate-900">${it.traveler_number}</span>
                                <span class="text-[10px] text-slate-400 block">Heat: <strong>${it.heat_number}</strong> (${it.cast_date})</span>
                            </div>
                            <div class="text-right">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold ${isMulti ? 'bg-indigo-100 text-indigo-800' : 'bg-slate-100 text-slate-700'}">
                                    ${isMulti ? `MULTI [Alokasi: ${it.allocated_qty} PCS]` : 'SINGLE'}
                                </span>
                                <span class="text-[10px] text-slate-500 block mt-0.5">Transfer: <strong>${it.qty_transferred} PCS</strong></span>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2 text-[11px]">
                            <div>Line: <strong>${it.line_label}</strong></div>
                            <div class="text-right">Posisi: <span class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800">${it.current_stage_label}</span></div>
                        </div>

                        ${defectRows ? `
                            <div class="pt-1">
                                <span class="text-[10px] font-bold uppercase text-slate-400 block mb-0.5">Cacat Terdeteksi:</span>
                                ${defectRows}
                            </div>
                        ` : ''}
                    </div>
                `;
            });

            content.innerHTML = html;
        })
        .catch(err => {
            loading.classList.add('hidden');
            content.classList.remove('hidden');
            content.innerHTML = `<p class="text-xs text-rose-600 text-center py-6">Terjadi kesalahan: ${err.message}</p>`;
        });
    }

    function closePlanDrawer() {
        document.getElementById('planDetailDrawer').classList.add('hidden');
    }
</script>
@endsection
