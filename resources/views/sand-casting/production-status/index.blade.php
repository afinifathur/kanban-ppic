@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full no-print">
        <div>
            <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded text-[10px] font-black bg-amber-600 text-white tracking-wide uppercase">SAND CASTING</span>
                <h1 class="text-lg font-black text-slate-800 tracking-tight leading-tight uppercase">PRODUCTION STATUS</h1>
            </div>
            <p class="text-slate-500 text-[11px] mt-0.5">Posisi gate fisik dan status shortage per Kode Produksi</p>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" onclick="window.print()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-xs font-bold shadow-xs transition-colors">
                <i class="fas fa-print text-slate-500"></i>
                <span>Print / PDF</span>
            </button>
        </div>
    </div>
@endsection

@section('content')
<style>
    /* Table Density & Formatting (Aligned with Lost Wax) */
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
        min-width: 170px;
        max-width: 220px;
        white-space: normal;
        word-wrap: break-word;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        line-height: 1.15;
    }

    /* Screen Styles & Sticky Two-Tier Headers */
    @media screen {
        .cell-physical-active {
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
            max-height: calc(100vh - 230px);
            min-height: 400px;
        }

        #scProdStatusTable thead {
            position: sticky;
            top: 0;
            z-index: 25;
        }
        #scProdStatusTable thead tr:first-child th {
            position: sticky;
            top: 0;
            z-index: 25;
            background-color: #0f172a !important;
            box-shadow: inset 0 -1px 0 #334155;
        }
        #scProdStatusTable thead tr:nth-child(2) th {
            position: sticky;
            top: 21px;
            z-index: 24;
            background-color: #1e293b !important;
            box-shadow: inset 0 -1px 0 #334155, 0 1px 2px rgba(0,0,0,0.15);
        }

        .production-status-print {
            display: none !important;
        }
    }

    /* Print / PDF Styles (Aligned with Lost Wax Corporate Format - PRESERVED) */
    @media print {
        .production-status-web {
            display: none !important;
        }
        .production-status-print {
            display: block !important;
        }

        html, body {
            display: block !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            background: white !important;
            font-family: Arial, Helvetica, sans-serif !important;
        }

        body > aside,
        body > main > header,
        .no-print {
            display: none !important;
        }

        body > main {
            display: block !important;
            width: 100% !important;
            max-width: none !important;
            padding: 0 !important;
            margin: 0 !important;
        }

        .print-header {
            text-align: left;
            margin-bottom: 3mm;
            padding-bottom: 2mm;
            border-bottom: 1.5px solid #0f172a;
            font-family: Arial, Helvetica, sans-serif !important;
        }
        .print-header .company {
            font-size: 8.5px;
            font-weight: 700;
            letter-spacing: 0.08em;
            color: #475569;
            text-transform: uppercase;
            margin: 0;
        }
        .print-header .title {
            font-size: 13px;
            font-weight: 800;
            letter-spacing: -0.01em;
            color: #0f172a;
            margin: 1mm 0 0.5mm 0;
        }
        .print-header .subtitle {
            font-size: 8px;
            color: #64748b;
            margin: 0;
        }
        .print-header .meta {
            font-size: 7.5px;
            color: #334155;
            background: #f8fafc;
            padding: 1.5mm 2.5mm;
            border: 0.5px solid #cbd5e1;
            border-radius: 2px;
            margin-top: 1.5mm;
            line-height: 1.3;
        }

        .ps-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 7.5px;
            table-layout: fixed;
            font-family: Arial, Helvetica, sans-serif !important;
        }
        .ps-table th, .ps-table td {
            border: 0.5px solid #64748b;
            padding: 2px 1.5px;
            text-align: center;
            vertical-align: middle;
            line-height: 1.15;
        }
        .ps-table th {
            background: #0f172a !important;
            color: #ffffff !important;
            font-weight: 800;
            font-size: 7.5px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .ps-table tbody tr:nth-child(even) {
            background-color: #f8fafc !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .ps-table td.left { text-align: left; }
        .ps-table td.right { text-align: right; }
        .ps-table td.prod-name { text-align: left; white-space: normal !important; word-wrap: break-word !important; }
        .ps-cell-green {
            background: #ecfdf5 !important;
            color: #065f46 !important;
            font-weight: 700;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .ps-cell-gd {
            background: #d1fae5 !important;
            color: #047857 !important;
            font-weight: 800;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .ps-cell-red {
            color: #dc2626 !important;
            font-weight: 700;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .ps-badge-cor {
            display: inline-block;
            background: #fef3c7 !important;
            color: #92400e !important;
            border: 0.5px solid #fde68a !important;
            padding: 1px 3px;
            font-size: 7px;
            font-weight: 800;
            border-radius: 2px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .ps-badge-completed {
            display: inline-block;
            background: #dcfce7 !important;
            color: #166534 !important;
            border: 0.5px solid #86efac !important;
            padding: 1px 3px;
            font-size: 7px;
            font-weight: 800;
            border-radius: 2px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .ps-badge-active {
            display: inline-block;
            background: #eff6ff !important;
            color: #1e40af !important;
            border: 0.5px solid #bfdbfe !important;
            padding: 1px 3px;
            font-size: 7px;
            font-weight: 800;
            border-radius: 2px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        thead { display: table-header-group !important; }
        tr { break-inside: avoid !important; page-break-inside: avoid !important; }

        @page {
            size: A4 landscape;
            margin: 5mm;
        }
    }
</style>

{{-- ========================================================================= --}}
{{-- 1. WEB UI PRESENTATION --}}
{{-- ========================================================================= --}}
<div class="production-status-web space-y-3">
    <!-- Filter Bar with Search, Codes/Customer Filters, and Segmented Tabs (Aligned with Lost Wax) -->
    <div class="bg-white rounded-lg border border-slate-200/90 p-3 shadow-xs no-print">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
            <!-- Search & Filters -->
            <form method="GET" action="{{ route('sand-casting.production-status.index') }}" id="searchForm" class="flex flex-wrap items-center gap-2 flex-grow">
                <input type="hidden" name="filter" value="{{ $filter ?? ($filters['filter'] ?? 'active') }}">
                
                <div class="relative min-w-[200px] flex-grow sm:flex-grow-0">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-2.5 pointer-events-none text-slate-400">
                        <i class="fas fa-search text-[10px]"></i>
                    </span>
                    <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                           placeholder="Cari Kode / PO / Item..."
                           class="w-full pl-7 pr-2.5 py-1 text-xs bg-white border border-slate-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-amber-500 focus:border-amber-500 font-medium">
                </div>

                <div class="min-w-[120px]">
                    <input type="text" name="production_code"
                           value="{{ is_array($filters['codes'] ?? null) ? implode(',', $filters['codes']) : ($filters['codes'] ?? '') }}"
                           placeholder="Kode Produksi..."
                           class="w-full px-2.5 py-1 text-xs bg-white border border-slate-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-amber-500 focus:border-amber-500 font-medium">
                </div>

                <div class="min-w-[120px]">
                    <input type="text" name="customer"
                           value="{{ is_array($filters['customers'] ?? null) ? implode(',', $filters['customers']) : ($filters['customers'] ?? '') }}"
                           placeholder="Customer..."
                           class="w-full px-2.5 py-1 text-xs bg-white border border-slate-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-amber-500 focus:border-amber-500 font-medium">
                </div>

                <div class="flex items-center gap-1.5 shrink-0">
                    <button type="submit" class="px-3 py-1 bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold rounded-lg shadow-xs transition-colors flex items-center gap-1">
                        <i class="fas fa-filter text-[9px]"></i>
                        <span>Terapkan</span>
                    </button>
                    @if(!empty($filters['search']) || !empty($filters['codes']) || !empty($filters['customers']) || ($filters['filter'] ?? 'active') !== 'active')
                        <a href="{{ route('sand-casting.production-status.index') }}" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg transition-colors">
                            Reset
                        </a>
                    @endif
                </div>
            </form>

            <!-- Status Tabs (Lost Wax Segmented Pill Style) -->
            <div class="flex gap-1 bg-slate-100 rounded-lg p-1 shrink-0 self-start md:self-auto">
                <a href="{{ route('sand-casting.production-status.index', array_merge(request()->query(), ['filter' => 'active'])) }}" 
                   class="px-3 py-1 rounded-md text-[10px] font-bold flex items-center gap-1.5 transition-colors {{ ($filter ?? 'active') === 'active' ? 'bg-amber-500 text-white shadow-xs' : 'text-slate-600 hover:bg-slate-200' }}">
                    <span>ACTIVE</span>
                    <span class="bg-white/20 text-[9px] px-1 rounded-full">{{ $summary['active_count'] ?? 0 }}</span>
                </a>
                <a href="{{ route('sand-casting.production-status.index', array_merge(request()->query(), ['filter' => 'completed'])) }}" 
                   class="px-3 py-1 rounded-md text-[10px] font-bold flex items-center gap-1.5 transition-colors {{ ($filter ?? '') === 'completed' ? 'bg-emerald-600 text-white shadow-xs' : 'text-slate-600 hover:bg-slate-200' }}">
                    <span>COMPLETED</span>
                    <span class="bg-white/20 text-[9px] px-1 rounded-full">{{ $summary['completed_count'] ?? 0 }}</span>
                </a>
                <a href="{{ route('sand-casting.production-status.index', array_merge(request()->query(), ['filter' => 'all'])) }}" 
                   class="px-3 py-1 rounded-md text-[10px] font-bold flex items-center gap-1.5 transition-colors {{ ($filter ?? '') === 'all' ? 'bg-slate-700 text-white shadow-xs' : 'text-slate-600 hover:bg-slate-200' }}">
                    <span>ALL</span>
                    <span class="bg-white/20 text-[9px] px-1 rounded-full">{{ $summary['total_count'] ?? 0 }}</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Main Production Status Table Container (Independent Vertical Scroll & Sticky Two-Tier Header) -->
    <div class="bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden">
        <div class="table-scroll-container overflow-y-auto overflow-x-auto" style="max-height: calc(100vh - 230px); min-height: 400px;">
            <table class="w-full text-[10px] whitespace-nowrap border-collapse" id="scProdStatusTable">
                <!-- Grouped Header Tier 1 -->
                <thead>
                    <tr class="bg-slate-900 text-slate-300 text-[8.5px] font-black uppercase tracking-wider">
                        <th colspan="6" class="px-2 py-1 border-r border-slate-800 text-left">
                            <span class="text-slate-400">1. Production Identity</span>
                        </th>
                        <th colspan="1" class="px-1 py-1 border-r border-slate-800 text-center bg-amber-950/70 text-amber-300">
                            <span>2. Shortage</span>
                        </th>
                        <th colspan="11" class="px-2 py-1 border-r border-slate-800 text-center bg-slate-950 text-slate-200">
                            <span>3. Physical Gate Custody & Defect Handover Chain</span>
                        </th>
                        <th colspan="1" class="px-1.5 py-1 text-center">
                            <span class="text-slate-400">4. Status</span>
                        </th>
                    </tr>
                    <!-- Header Tier 2 (Sub-columns) -->
                    <tr class="bg-slate-800 text-white">
                        <th class="compact-th min-w-[28px]">No</th>
                        <th class="compact-th text-left min-w-[65px] px-1.5">Kode</th>
                        <th class="compact-th text-left min-w-[170px] max-w-[220px] px-1.5">Product Name</th>
                        <th class="compact-th text-left min-w-[80px] px-1.5">Customer</th>
                        <th class="compact-th text-right min-w-[42px] px-1.5">PO</th>
                        <th class="compact-th text-right min-w-[45px] px-1.5 border-r border-slate-700">Plan</th>

                        <!-- Demand Shortage -->
                        <th class="compact-th text-center min-w-[36px] bg-amber-900/50 text-amber-200 border-r border-slate-700">COR</th>

                        <!-- Physical Gate 1: Netto & R (R = R_cor + R_netto) -->
                        <th class="compact-th text-right min-w-[38px] px-1">Netto</th>
                        <th class="compact-th text-right min-w-[22px] px-1 bg-rose-950/40 text-rose-300 border-r border-slate-700/60">R</th>

                        <!-- Physical Gate 2: Bubut OD & R -->
                        <th class="compact-th text-right min-w-[38px] px-1">OD</th>
                        <th class="compact-th text-right min-w-[22px] px-1 bg-rose-950/40 text-rose-300 border-r border-slate-700/60">R</th>

                        <!-- Physical Gate 3: Bubut CNC & R -->
                        <th class="compact-th text-right min-w-[38px] px-1">CNC</th>
                        <th class="compact-th text-right min-w-[22px] px-1 bg-rose-950/40 text-rose-300 border-r border-slate-700/60">R</th>

                        <!-- Physical Gate 4: Bor & R -->
                        <th class="compact-th text-right min-w-[38px] px-1">Bor</th>
                        <th class="compact-th text-right min-w-[22px] px-1 bg-rose-950/40 text-rose-300 border-r border-slate-700/60">R</th>

                        <!-- Physical Gate 5: QC & R -->
                        <th class="compact-th text-right min-w-[38px] px-1">QC</th>
                        <th class="compact-th text-right min-w-[22px] px-1 bg-rose-950/40 text-rose-300 border-r border-slate-700/60">R</th>

                        <!-- Physical Gate 6: GD (Gudang Jadi) -->
                        <th class="compact-th text-right min-w-[42px] px-1 bg-emerald-950/50 text-emerald-300 border-r border-slate-700">GD</th>

                        <!-- Status -->
                        <th class="compact-th text-center min-w-[65px] px-1">Status</th>
                    </tr>
                </thead>
                <!-- Body Rows -->
                <tbody class="divide-y divide-slate-200">
                    @forelse($rows as $row)
                        @php
                            $rNettoDisplay = (int) $row['r_cor'] + (int) $row['r_netto'];
                        @endphp
                        <tr class="sc-status-row hover:bg-blue-50/50 cursor-pointer transition-colors {{ $loop->even ? 'bg-slate-50/50' : 'bg-white' }}"
                            data-plan-id="{{ $row['production_plan_id'] }}"
                            data-code="{{ $row['code'] }}">
                            <!-- No -->
                            <td class="compact-td text-center font-bold text-slate-400 tabular-nums">
                                {{ $row['no'] }}
                            </td>

                            <!-- Kode Produksi (Clickable) -->
                            <td class="compact-td font-black text-slate-900 truncate px-1.5" title="{{ $row['code'] }}">
                                <a href="#" class="hover:text-amber-600 hover:underline sc-detail-link"
                                   data-plan-id="{{ $row['production_plan_id'] }}"
                                   data-code="{{ $row['code'] }}">{{ $row['code'] }}</a>
                            </td>

                            <!-- Product Name (Natural Multi-line Wrapping, Readable, Aligned with Lost Wax) -->
                            <td class="compact-td text-slate-800 font-medium px-1.5">
                                <div class="prod-name-cell" title="{{ $row['item_name'] }}">{{ $row['item_name'] }}</div>
                            </td>

                            <!-- Customer -->
                            <td class="compact-td text-slate-600 truncate px-1.5" title="{{ $row['customer'] }}">
                                {{ $row['customer'] }}
                            </td>

                            <!-- PO -->
                            <td class="compact-td text-right font-black text-slate-900 tabular-nums px-1.5">
                                {{ number_format($row['po_target']) }}
                            </td>

                            <!-- Plan -->
                            <td class="compact-td text-right text-slate-500 tabular-nums px-1.5 border-r border-slate-200">
                                {{ number_format($row['planned_qty']) }}
                            </td>

                            <!-- COR Shortage Indicator -->
                            <td class="compact-td text-center border-r border-slate-200 {{ $row['cor_indicator'] === 'COR' ? 'bg-amber-50/60' : '' }}">
                                @if($row['cor_indicator'] === 'COR')
                                    <span class="inline-block px-1.5 py-0.2 rounded text-[9px] font-black tracking-wider bg-amber-100 text-amber-900 border border-amber-300 leading-tight" title="Shortage: Usable ({{ number_format($row['net_available_good']) }}) < PO ({{ number_format($row['po_target']) }})">
                                        COR
                                    </span>
                                @else
                                    <span class="text-slate-300 font-bold">-</span>
                                @endif
                            </td>

                            <!-- Netto -->
                            <td class="compact-td text-right tabular-nums px-1 {{ $row['netto'] > 0 ? 'cell-physical-active' : 'text-slate-400' }}">
                                {{ $row['netto'] > 0 ? number_format($row['netto']) : '-' }}
                            </td>
                            <!-- Netto R (R_cor + R_netto) -->
                            <td class="compact-td text-right tabular-nums px-1 border-r border-slate-100 {{ $rNettoDisplay > 0 ? 'cell-defect-active' : 'text-slate-300' }}">
                                {{ $rNettoDisplay > 0 ? number_format($rNettoDisplay) : '-' }}
                            </td>

                            <!-- Bubut OD -->
                            <td class="compact-td text-right tabular-nums px-1 {{ $row['bubut_od'] > 0 ? 'cell-physical-active' : 'text-slate-400' }}">
                                {{ $row['bubut_od'] > 0 ? number_format($row['bubut_od']) : '-' }}
                            </td>
                            <!-- OD R -->
                            <td class="compact-td text-right tabular-nums px-1 border-r border-slate-100 {{ $row['r_od'] > 0 ? 'cell-defect-active' : 'text-slate-300' }}">
                                {{ $row['r_od'] > 0 ? number_format($row['r_od']) : '-' }}
                            </td>

                            <!-- Bubut CNC -->
                            <td class="compact-td text-right tabular-nums px-1 {{ $row['bubut_cnc'] > 0 ? 'cell-physical-active' : 'text-slate-400' }}">
                                {{ $row['bubut_cnc'] > 0 ? number_format($row['bubut_cnc']) : '-' }}
                            </td>
                            <!-- CNC R -->
                            <td class="compact-td text-right tabular-nums px-1 border-r border-slate-100 {{ $row['r_cnc'] > 0 ? 'cell-defect-active' : 'text-slate-300' }}">
                                {{ $row['r_cnc'] > 0 ? number_format($row['r_cnc']) : '-' }}
                            </td>

                            <!-- Bor -->
                            <td class="compact-td text-right tabular-nums px-1 {{ $row['bor'] > 0 ? 'cell-physical-active' : 'text-slate-400' }}">
                                {{ $row['bor'] > 0 ? number_format($row['bor']) : '-' }}
                            </td>
                            <!-- Bor R -->
                            <td class="compact-td text-right tabular-nums px-1 border-r border-slate-100 {{ $row['r_bor'] > 0 ? 'cell-defect-active' : 'text-slate-300' }}">
                                {{ $row['r_bor'] > 0 ? number_format($row['r_bor']) : '-' }}
                            </td>

                            <!-- QC -->
                            <td class="compact-td text-right tabular-nums px-1 {{ $row['qc'] > 0 ? 'cell-physical-active' : 'text-slate-400' }}">
                                {{ $row['qc'] > 0 ? number_format($row['qc']) : '-' }}
                            </td>
                            <!-- QC R -->
                            <td class="compact-td text-right tabular-nums px-1 border-r border-slate-100 {{ $row['r_qc'] > 0 ? 'cell-defect-active' : 'text-slate-300' }}">
                                {{ $row['r_qc'] > 0 ? number_format($row['r_qc']) : '-' }}
                            </td>

                            <!-- GD (Gudang Jadi) -->
                            <td class="compact-td text-right tabular-nums px-1 border-r border-slate-200 {{ $row['gd'] > 0 ? 'cell-gd-active' : 'text-slate-400' }}">
                                {{ $row['gd'] > 0 ? number_format($row['gd']) : '-' }}
                            </td>

                            <!-- Status -->
                            <td class="compact-td text-center px-1">
                                @if($row['status'] === 'COMPLETED')
                                    <span class="inline-block px-1.5 py-0.5 rounded text-[8.5px] font-black bg-emerald-100 text-emerald-800 border border-emerald-200 leading-tight">
                                        COMPLETED
                                    </span>
                                @else
                                    <span class="inline-block px-1.5 py-0.5 rounded text-[8.5px] font-bold bg-blue-50 text-blue-800 border border-blue-200 leading-tight">
                                        ACTIVE
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="19" class="px-6 py-10 text-center text-slate-500 bg-slate-50/50">
                                <p class="text-xs font-bold text-slate-700">
                                    @if(!empty($filters['search']) || !empty($filters['codes']) || !empty($filters['customers']))
                                        Tidak ada data yang sesuai dengan filter.
                                    @else
                                        Tidak ada data Production Status Sand Casting.
                                    @endif
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- ========================================================================= --}}
{{-- 2. PRINT / PDF PRESENTATION (Aligned with Lost Wax Corporate Format) --}}
{{-- ========================================================================= --}}
<div class="production-status-print">
    <!-- Corporate Print Header -->
    <div class="print-header">
        <div class="company">PT. PERONI KARYA UTAMA</div>
        <div class="title">SAND CASTING &mdash; PRODUCTION STATUS REPORT</div>
        <div class="subtitle">Physical Gate Custody & Demand Shortage Overview per Production Code</div>
        <div class="meta">
            <strong>Printed By:</strong> {{ auth()->user()->name ?? 'System' }} &nbsp;|&nbsp;
            <strong>Printed At:</strong> {{ now()->format('d/m/Y H:i') }} &nbsp;|&nbsp;
            <strong>Status Filter:</strong> {{ strtoupper($filters['status'] ?? 'ALL') }} &nbsp;|&nbsp;
            <strong>Total Records:</strong> {{ count($rows) }} Production Codes
        </div>
    </div>

    <!-- Print Table (Aligned with Lost Wax Corporate Colgroup & Product Name Wrapping) -->
    <table class="ps-table">
        <colgroup>
            <col style="width: 7mm;">   <!-- No -->
            <col style="width: 18mm;">  <!-- Kode -->
            <col style="width: 58mm;">  <!-- Product Name -->
            <col style="width: 24mm;">  <!-- Customer -->
            <col style="width: 11mm;">  <!-- PO -->
            <col style="width: 11mm;">  <!-- Plan -->
            <col style="width: 9mm;">   <!-- COR -->
            <col style="width: 10mm;">  <!-- Netto -->
            <col style="width: 6mm;">   <!-- R -->
            <col style="width: 10mm;">  <!-- OD -->
            <col style="width: 6mm;">   <!-- R -->
            <col style="width: 10mm;">  <!-- CNC -->
            <col style="width: 6mm;">   <!-- R -->
            <col style="width: 10mm;">  <!-- Bor -->
            <col style="width: 6mm;">   <!-- R -->
            <col style="width: 10mm;">  <!-- QC -->
            <col style="width: 6mm;">   <!-- R -->
            <col style="width: 12mm;">  <!-- GD -->
            <col style="width: 16mm;">  <!-- Status -->
        </colgroup>
        <thead>
            <tr>
                <th colspan="6" style="border-right: 1px solid #ffffff;">PRODUCTION IDENTITY</th>
                <th colspan="1" style="background: #78350f !important; border-right: 1px solid #ffffff;">SHORTAGE</th>
                <th colspan="11" style="background: #020617 !important; border-right: 1px solid #ffffff;">PHYSICAL GATE CUSTODY & DEFECT HANDOVER CHAIN</th>
                <th colspan="1">STATUS</th>
            </tr>
            <tr>
                <th>No</th>
                <th style="text-align: left;">Kode</th>
                <th style="text-align: left;">Product Name</th>
                <th style="text-align: left;">Customer</th>
                <th style="text-align: right;">PO</th>
                <th style="text-align: right; border-right: 1px solid #64748b;">Plan</th>
                <th style="background: #78350f !important;">COR</th>
                <th style="text-align: right;">Netto</th>
                <th style="text-align: right;">R</th>
                <th style="text-align: right;">OD</th>
                <th style="text-align: right;">R</th>
                <th style="text-align: right;">CNC</th>
                <th style="text-align: right;">R</th>
                <th style="text-align: right;">Bor</th>
                <th style="text-align: right;">R</th>
                <th style="text-align: right;">QC</th>
                <th style="text-align: right;">R</th>
                <th style="text-align: right; background: #064e3b !important;">GD</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                @php
                    $rNettoPrint = (int) $row['r_cor'] + (int) $row['r_netto'];
                @endphp
                <tr>
                    <td>{{ $row['no'] }}</td>
                    <td class="left" style="font-weight: bold; white-space: nowrap;">{{ $row['code'] }}</td>
                    <td class="left prod-name">
                        <div style="max-height: 2.3em; line-height: 1.15; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; white-space: normal; word-wrap: break-word;">
                            {{ $row['item_name'] }}
                        </div>
                    </td>
                    <td class="left" style="white-space: nowrap;">{{ $row['customer'] }}</td>
                    <td class="right" style="font-weight: bold;">{{ number_format($row['po_target']) }}</td>
                    <td class="right" style="border-right: 1px solid #64748b;">{{ number_format($row['planned_qty']) }}</td>
                    <td>
                        @if($row['cor_indicator'] === 'COR')
                            <span class="ps-badge-cor">COR</span>
                        @else
                            -
                        @endif
                    </td>
                    <td class="right {{ $row['netto'] > 0 ? 'ps-cell-green' : '' }}">
                        {{ $row['netto'] > 0 ? number_format($row['netto']) : '-' }}
                    </td>
                    <td class="right {{ $rNettoPrint > 0 ? 'ps-cell-red' : '' }}">
                        {{ $rNettoPrint > 0 ? number_format($rNettoPrint) : '-' }}
                    </td>
                    <td class="right {{ $row['bubut_od'] > 0 ? 'ps-cell-green' : '' }}">
                        {{ $row['bubut_od'] > 0 ? number_format($row['bubut_od']) : '-' }}
                    </td>
                    <td class="right {{ $row['r_od'] > 0 ? 'ps-cell-red' : '' }}">
                        {{ $row['r_od'] > 0 ? number_format($row['r_od']) : '-' }}
                    </td>
                    <td class="right {{ $row['bubut_cnc'] > 0 ? 'ps-cell-green' : '' }}">
                        {{ $row['bubut_cnc'] > 0 ? number_format($row['bubut_cnc']) : '-' }}
                    </td>
                    <td class="right {{ $row['r_cnc'] > 0 ? 'ps-cell-red' : '' }}">
                        {{ $row['r_cnc'] > 0 ? number_format($row['r_cnc']) : '-' }}
                    </td>
                    <td class="right {{ $row['bor'] > 0 ? 'ps-cell-green' : '' }}">
                        {{ $row['bor'] > 0 ? number_format($row['bor']) : '-' }}
                    </td>
                    <td class="right {{ $row['r_bor'] > 0 ? 'ps-cell-red' : '' }}">
                        {{ $row['r_bor'] > 0 ? number_format($row['r_bor']) : '-' }}
                    </td>
                    <td class="right {{ $row['qc'] > 0 ? 'ps-cell-green' : '' }}">
                        {{ $row['qc'] > 0 ? number_format($row['qc']) : '-' }}
                    </td>
                    <td class="right {{ $row['r_qc'] > 0 ? 'ps-cell-red' : '' }}">
                        {{ $row['r_qc'] > 0 ? number_format($row['r_qc']) : '-' }}
                    </td>
                    <td class="right {{ $row['gd'] > 0 ? 'ps-cell-gd' : '' }}">
                        {{ $row['gd'] > 0 ? number_format($row['gd']) : '-' }}
                    </td>
                    <td>
                        @if($row['status'] === 'COMPLETED')
                            <span class="ps-badge-completed">COMPLETED</span>
                        @else
                            <span class="ps-badge-active">ACTIVE</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="19" style="padding: 10px; text-align: center; color: #64748b;">
                        Tidak ada data Production Status Sand Casting.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- ========================================================================= --}}
{{-- 3. PRODUCTION CODE DETAIL DRAWER (Aligned with Lost Wax Architecture) --}}
{{-- ========================================================================= --}}
<div id="scDetailModal" class="fixed inset-0 z-50 hidden no-print" style="background:rgba(0,0,0,0.5)">
    <div class="absolute right-0 top-0 h-full w-full max-w-lg bg-white shadow-2xl overflow-y-auto flex flex-col">
        <!-- Sticky Drawer Header -->
        <div class="sticky top-0 bg-slate-800 text-white px-5 py-4 flex items-center justify-between z-10 shadow-md">
            <div>
                <h3 class="font-bold text-sm tracking-tight" id="scModalTitle">Detail Kode Produksi</h3>
                <p class="text-xs text-slate-400 mt-0.5" id="scModalSubtitle"></p>
            </div>
            <button onclick="closeSCDetail()" class="text-white hover:text-slate-300 text-2xl leading-none font-bold p-1 rounded transition-colors" title="Tutup (Esc)">&times;</button>
        </div>

        <!-- Dynamic Drawer Content Container -->
        <div id="scDetailContent" class="p-5 flex-1 space-y-4">
            <div class="text-center py-12 text-slate-500">
                <i class="fas fa-spinner fa-spin text-3xl text-amber-500 mb-3"></i>
                <p class="text-xs font-semibold">Memuat detail physical KTR...</p>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Bind click on Code link
        document.querySelectorAll('.sc-detail-link').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                openSCDetail(this.dataset.planId, this.dataset.code);
            });
        });

        // Bind click on Table Row
        document.querySelectorAll('.sc-status-row').forEach(function(row) {
            row.addEventListener('click', function(e) {
                if (e.target.tagName !== 'A' && !e.target.closest('a') && !e.target.closest('input') && !e.target.closest('button')) {
                    openSCDetail(this.dataset.planId, this.dataset.code);
                }
            });
        });

        // Backdrop & Escape key close bindings
        const modal = document.getElementById('scDetailModal');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeSCDetail();
                }
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSCDetail();
            }
        });
    });

    function openSCDetail(planId, code) {
        const modal = document.getElementById('scDetailModal');
        const content = document.getElementById('scDetailContent');
        const title = document.getElementById('scModalTitle');
        const subtitle = document.getElementById('scModalSubtitle');

        if (!modal || !content) return;

        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';

        content.innerHTML = `
            <div class="text-center py-12 text-slate-500">
                <i class="fas fa-spinner fa-spin text-3xl text-amber-500 mb-3"></i>
                <p class="text-xs font-semibold">Memuat detail physical KTR...</p>
            </div>
        `;
        title.textContent = 'Detail Kode Produksi: ' + (code || '-');
        subtitle.textContent = 'Memuat informasi...';

        const endpoint = '{{ route('sand-casting.production-status.details') }}?production_plan_id=' + encodeURIComponent(planId || code);

        fetch(endpoint, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(res) {
            if (!res.ok) {
                throw new Error('HTTP error ' + res.status);
            }
            return res.json();
        })
        .then(function(data) {
            renderSCDetailData(data);
        })
        .catch(function(err) {
            console.error('Failed to load detail:', err);
            content.innerHTML = `
                <div class="text-center py-12 text-rose-500">
                    <i class="fas fa-exclamation-triangle text-3xl mb-3 block opacity-80"></i>
                    <p class="text-sm font-bold">Gagal memuat detail data fisik.</p>
                    <p class="text-xs text-slate-400 mt-1">Silakan coba beberapa saat lagi.</p>
                </div>
            `;
        });
    }

    function closeSCDetail() {
        const modal = document.getElementById('scDetailModal');
        if (modal) {
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }
    }

    function renderSCDetailData(data) {
        const title = document.getElementById('scModalTitle');
        const subtitle = document.getElementById('scModalSubtitle');
        const content = document.getElementById('scDetailContent');

        title.textContent = 'Kode: ' + (data.production_code || '-');
        subtitle.textContent = (data.item_name || '-') + ' · ' + (data.customer || '-');

        if (!data.items || data.items.length === 0) {
            content.innerHTML = `
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-4 space-y-3">
                    <div class="flex items-center justify-between text-xs pb-2 border-b border-slate-200">
                        <span class="text-slate-500 font-semibold">Customer:</span>
                        <span class="font-bold text-slate-800">${escapeHtml(data.customer || '-')}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs pb-2 border-b border-slate-200">
                        <span class="text-slate-500 font-semibold">Item:</span>
                        <span class="font-bold text-slate-800">${escapeHtml(data.item_name || '-')}</span>
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-xs pt-1">
                        <div><span class="text-slate-400">PO Target:</span> <span class="font-bold font-mono">${(data.po_target || 0).toLocaleString('id-ID')}</span></div>
                        <div><span class="text-slate-400">Plan:</span> <span class="font-bold font-mono">${(data.planned_qty || 0).toLocaleString('id-ID')}</span></div>
                    </div>
                </div>
                <div class="text-center py-10 text-slate-500">
                    <i class="fas fa-inbox text-3xl mb-2 block opacity-30"></i>
                    <p class="text-sm font-semibold">Belum ada barcode / KTR terkait Kode Produksi ini.</p>
                </div>
            `;
            return;
        }

        // Summary Card
        let html = `
            <div class="bg-slate-50 border border-slate-200 rounded-lg p-3.5 space-y-2.5">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider">Customer</div>
                        <div class="text-xs font-bold text-slate-800">${escapeHtml(data.customer || '-')}</div>
                    </div>
                    <div class="text-right">
                        ${data.status === 'COMPLETED' 
                            ? '<span class="inline-block px-2 py-0.5 rounded text-[9px] font-black bg-emerald-100 text-emerald-800 border border-emerald-300">COMPLETED</span>'
                            : '<span class="inline-block px-2 py-0.5 rounded text-[9px] font-black bg-blue-100 text-blue-800 border border-blue-300">ACTIVE</span>'}
                        ${data.cor_indicator === 'COR' 
                            ? '<span class="inline-block ml-1 px-1.5 py-0.5 rounded text-[9px] font-black bg-amber-100 text-amber-900 border border-amber-300">COR</span>' 
                            : ''}
                    </div>
                </div>
                <div>
                    <div class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider">Item Product</div>
                    <div class="text-xs font-bold text-slate-800">${escapeHtml(data.item_name || '-')}</div>
                </div>
                <div class="grid grid-cols-3 gap-2 text-xs pt-2 border-t border-slate-200">
                    <div>
                        <span class="text-[10px] text-slate-400 block font-semibold">PO Target</span>
                        <span class="font-extrabold font-mono text-slate-900">${(data.po_target || 0).toLocaleString('id-ID')}</span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-400 block font-semibold">Plan Qty</span>
                        <span class="font-extrabold font-mono text-slate-700">${(data.planned_qty || 0).toLocaleString('id-ID')}</span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-400 block font-semibold">Total Fisik</span>
                        <span class="font-extrabold font-mono text-emerald-700">${(data.total_physical_qty || 0).toLocaleString('id-ID')} PCS</span>
                    </div>
                </div>
            </div>

            <!-- List Section Header -->
            <div class="flex items-center justify-between pt-1">
                <span class="text-xs font-extrabold text-slate-700 uppercase tracking-wider">Produksi Fisik / KTR</span>
                <span class="bg-slate-100 text-slate-700 text-[10px] font-bold px-2 py-0.5 rounded-full border border-slate-200">${data.items.length} Traveler</span>
            </div>

            <!-- List of KTR Cards -->
            <div class="space-y-2.5">
        `;

        const badgeColors = {
            'NETTO': 'bg-amber-100 text-amber-900 border-amber-300',
            'OD': 'bg-blue-100 text-blue-900 border-blue-300',
            'CNC': 'bg-indigo-100 text-indigo-900 border-indigo-300',
            'BOR': 'bg-purple-100 text-purple-900 border-purple-300',
            'QC': 'bg-teal-100 text-teal-900 border-teal-300',
            'GD': 'bg-emerald-100 text-emerald-900 border-emerald-300',
            'COR': 'bg-slate-100 text-slate-800 border-slate-300'
        };

        data.items.forEach(function(item) {
            const badgeClass = badgeColors[item.current_stage_label] || 'bg-slate-100 text-slate-800 border-slate-300';
            html += `
                <div class="border border-slate-200 rounded-lg p-3 bg-white shadow-2xs hover:border-amber-400 transition-colors">
                    <div class="flex items-center justify-between mb-1.5">
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <span class="font-mono font-bold text-xs text-slate-900 bg-slate-100 px-1.5 py-0.5 rounded border border-slate-200">
                                <i class="fas fa-fire text-amber-600 text-[9px] mr-0.5"></i>${escapeHtml(item.heat_number)}
                            </span>
                            <span class="font-mono font-bold text-xs text-slate-700">
                                ${escapeHtml(item.traveler_number)}
                            </span>
                        </div>
                        <span class="inline-block px-2 py-0.5 rounded text-[10px] font-black border tracking-wider ${badgeClass}">
                            ${escapeHtml(item.current_stage_label)}
                        </span>
                    </div>

                    <div class="grid grid-cols-3 gap-2 text-xs py-1.5 border-y border-slate-100 bg-slate-50/50 px-2 rounded">
                        <div>
                            <span class="text-[10px] text-slate-400 block font-semibold">Posisi Qty</span>
                            <span class="font-extrabold text-sm text-slate-900 font-mono">${(item.quantity || 0).toLocaleString('id-ID')} <span class="text-[10px] font-normal text-slate-500">PCS</span></span>
                        </div>
                        <div>
                            <span class="text-[10px] text-slate-400 block font-semibold">Hasil Cor</span>
                            <span class="font-bold text-xs text-slate-700 font-mono">${(item.qty_cor_good || 0).toLocaleString('id-ID')} pcs</span>
                        </div>
                        <div>
                            <span class="text-[10px] text-slate-400 block font-semibold">Total Rusak</span>
                            <span class="font-bold text-xs font-mono ${item.defect_qty > 0 ? 'text-rose-600' : 'text-slate-400'}">${(item.defect_qty || 0).toLocaleString('id-ID')} pcs</span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between text-[10px] text-slate-400 pt-1.5">
                        <span>Tgl Cor: <strong class="text-slate-600">${escapeHtml(item.cast_date)}</strong></span>
                        <span>Update: <strong class="text-slate-600">${escapeHtml(item.last_activity_at)}</strong></span>
                    </div>
                </div>
            `;
        });

        html += `</div>`;
        content.innerHTML = html;
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
</script>
@endsection

