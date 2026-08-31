@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">LOST WAX &mdash; PRODUCTION STATUS</h1>
            <p class="text-gray-500 text-[10px]">Posisi produksi per Kode Cust / Work Order</p>
        </div>
        <div class="flex gap-2 no-print">
            <button onclick="window.print()"
                class="text-xs bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold py-1.5 px-3 rounded">
                <i class="fas fa-print mr-1"></i> Print
            </button>
            <a href="{{ route('lost-wax.production-status.export', request()->query()) }}"
                class="text-xs bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-1.5 px-3 rounded">
                <i class="fas fa-file-excel mr-1"></i> XLSX
            </a>
        </div>
    </div>
@endsection

@section('content')
    <style>
        .compact-th {
            padding: 4px 5px !important;
            font-size: 10px !important;
            line-height: 1.15;
            vertical-align: middle;
            text-align: center;
        }
        .compact-td {
            padding: 4px 5px !important;
            font-size: 10px !important;
            line-height: 1.15;
            vertical-align: middle;
        }
        .prod-name-cell {
            max-width: 190px;
            white-space: normal;
            word-wrap: break-word;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.15;
        }

        @media screen {
            .cell-layer-active { background: #d1fae5; color: #065f46; font-weight: 700; }
            .cell-oven { background: #ccfbf1; color: #0f766e; font-weight: 700; }
            #prodStatusTable thead {
                position: sticky;
                top: 0;
                z-index: 20;
            }
            #prodStatusTable thead th {
                position: sticky;
                top: 0;
                z-index: 20;
                background-color: #1e293b !important;
                box-shadow: inset 0 -1px 0 #334155;
            }
            #prodStatusTable thead th:first-child { z-index: 25; }
            .production-status-print { display: none; }
        }

        @media print {
            .table-scroll-container {
                overflow: visible !important;
                max-height: none !important;
                height: auto !important;
            }
            .production-status-web { display: none !important; }
            .production-status-print { display: block !important; }

            html, body {
                display: block !important;
                width: auto !important;
                height: auto !important;
                min-height: 0 !important;
                overflow: visible !important;
                margin: 0 !important;
                padding: 0 !important;
                background: white !important;
                flex-direction: row !important;
                font-family: Arial, Helvetica, sans-serif !important;
            }
            body { display: block !important; }

            body > aside { display: none !important; }
            body > main { display: block !important; width: 100% !important; max-width: none !important; overflow: visible !important; flex: none !important; }
            body > main > header { display: none !important; }
            body > main > div { display: block !important; overflow: visible !important; padding: 0 !important; margin: 0 !important; flex: none !important; width: 100% !important; }
            body > main > div > div { display: block !important; overflow: visible !important; padding: 0 !important; margin: 0 !important; }

            .sticky { position: static !important; }
            .fixed { position: static !important; }
            .rounded-lg, .rounded-xl, .rounded-sm { border-radius: 0 !important; }
            .shadow-sm, .shadow-xl, .shadow { box-shadow: none !important; }
            .border, .border-slate-200, .border-slate-300 { border: none !important; }
            .divide-y { border: none !important; }
            .no-print { display: none !important; }

            .print-header { text-align: center; margin-bottom: 5mm; font-family: Arial, Helvetica, sans-serif !important; }
            .print-header .company { font-size: 12px; font-weight: 700; color: #1F2937; margin: 0; }
            .print-header .title { font-size: 14px; font-weight: 700; color: #1F2937; margin: 1.5mm 0; }
            .print-header .subtitle { font-size: 9px; color: #475569; margin: 0 0 1mm 0; }
            .print-header .meta { font-size: 9px; color: #475569; margin: 0; }

            .ps-table { 
                width: 100%; 
                border-collapse: collapse; 
                font-size: 11px;
                table-layout: fixed;
                font-family: Arial, Helvetica, sans-serif !important;
            }
            .ps-table th, .ps-table td { 
                border: 0.5px solid #888; 
                padding: 2px 2px; 
                text-align: center; 
                vertical-align: middle; 
                overflow: hidden;
            }
            .ps-table th { 
                background: #1F2937 !important;
                color: white !important; 
                font-weight: 700; 
                font-size: 9px;
                -webkit-print-color-adjust: exact; 
                print-color-adjust: exact; 
                line-height: 1.15;
            }
            .ps-table td.left { text-align: left; }
            .ps-table td.right { text-align: right; }
            .ps-table td.prod-name { text-align: left; }

            .ps-cell-green { 
                background: #DCFCE7 !important;
                color: #166534 !important;
                -webkit-print-color-adjust: exact; 
                print-color-adjust: exact; 
            }
            .ps-cell-oven { 
                background: #DCFCE7 !important;
                color: #166534 !important;
                -webkit-print-color-adjust: exact; 
                print-color-adjust: exact; 
            }
            .ps-cell-red { 
                background: #FEE2E2 !important;
                color: #DC2626 !important;
                font-weight: bold;
                -webkit-print-color-adjust: exact; 
                print-color-adjust: exact; 
            }

            thead { display: table-header-group !important; }
            tr { break-inside: avoid !important; page-break-inside: avoid !important; }

            @page { size: A4 landscape; margin: 5mm; }
        }
    </style>

    {{-- WEB UI --}}
    <div class="production-status-web space-y-4">
        <div class="bg-white rounded-lg shadow-sm border border-slate-200 p-4 no-print">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                {{-- Left: Global Search and Categorical dropdowns --}}
                <div class="flex flex-wrap items-center gap-3 flex-1">
                    <form method="GET" action="{{ route('lost-wax.production-status') }}" id="searchForm" class="w-full sm:w-auto sm:min-w-[280px] relative">
                        <input type="hidden" name="filter" value="{{ $filter }}">
                        @foreach($codes as $c)<input type="hidden" name="codes[]" value="{{ $c }}">@endforeach
                        @foreach($customers as $cust)<input type="hidden" name="customers[]" value="{{ $cust }}">@endforeach
                        @foreach($po_numbers as $po)<input type="hidden" name="po_numbers[]" value="{{ $po }}">@endforeach
                        @foreach($aisis as $a)<input type="hidden" name="aisis[]" value="{{ $a }}">@endforeach

                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                        <input type="text" name="search" value="{{ $search }}"
                            placeholder="Search Kode / Product / PO..."
                            class="w-full pl-9 pr-3 py-1.5 rounded-lg border border-slate-300 text-xs focus:border-amber-500 focus:ring-amber-500 bg-white">
                    </form>

                    {{-- Kode Cust Filter Trigger --}}
                    <button type="button" class="filter-dropdown-trigger text-xs bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold py-1.5 px-3 rounded-lg flex items-center gap-1.5 {{ !empty($codes) ? 'border-amber-500 text-amber-700 bg-amber-50/20' : '' }}" data-filter-type="codes">
                        <i class="fas fa-barcode"></i> Kode Cust
                        @if(!empty($codes)) <span class="bg-amber-100 text-amber-800 text-[9px] px-1 rounded-full">{{ count($codes) }}</span> @endif
                        <i class="fas fa-chevron-down text-[9px]"></i>
                    </button>

                    {{-- Customer Filter Trigger --}}
                    <button type="button" class="filter-dropdown-trigger text-xs bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold py-1.5 px-3 rounded-lg flex items-center gap-1.5 {{ !empty($customers) ? 'border-amber-500 text-amber-700 bg-amber-50/20' : '' }}" data-filter-type="customers">
                        <i class="fas fa-user-tie"></i> Customer
                        @if(!empty($customers)) <span class="bg-amber-100 text-amber-800 text-[9px] px-1 rounded-full">{{ count($customers) }}</span> @endif
                        <i class="fas fa-chevron-down text-[9px]"></i>
                    </button>

                    {{-- PO Filter Trigger --}}
                    <button type="button" class="filter-dropdown-trigger text-xs bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold py-1.5 px-3 rounded-lg flex items-center gap-1.5 {{ !empty($po_numbers) ? 'border-amber-500 text-amber-700 bg-amber-50/20' : '' }}" data-filter-type="po_numbers">
                        <i class="fas fa-file-invoice"></i> PO
                        @if(!empty($po_numbers)) <span class="bg-amber-100 text-amber-800 text-[9px] px-1 rounded-full">{{ count($po_numbers) }}</span> @endif
                        <i class="fas fa-chevron-down text-[9px]"></i>
                    </button>

                    {{-- AISI Filter Trigger --}}
                    <button type="button" class="filter-dropdown-trigger text-xs bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold py-1.5 px-3 rounded-lg flex items-center gap-1.5 {{ !empty($aisis) ? 'border-amber-500 text-amber-700 bg-amber-50/20' : '' }}" data-filter-type="aisis">
                        <i class="fas fa-cog"></i> AISI
                        @if(!empty($aisis)) <span class="bg-amber-100 text-amber-800 text-[9px] px-1 rounded-full">{{ count($aisis) }}</span> @endif
                        <i class="fas fa-chevron-down text-[9px]"></i>
                    </button>
                </div>

                {{-- Right: Status Tabs --}}
                <div class="flex gap-1 bg-slate-100 rounded-lg p-1 self-start md:self-auto">
                    <a href="{{ route('lost-wax.production-status', array_merge(request()->query(), ['filter' => 'active'])) }}" 
                       class="px-3 py-1 rounded-md text-[10px] font-bold flex items-center gap-1 {{ $filter==='active' ? 'bg-amber-500 text-white' : 'text-slate-600 hover:bg-slate-200' }}">
                        <span>ACTIVE</span>
                        <span class="bg-white/20 text-[9px] px-1 rounded-full">{{ $activeCount }}</span>
                    </a>
                    <a href="{{ route('lost-wax.production-status', array_merge(request()->query(), ['filter' => 'completed'])) }}" 
                       class="px-3 py-1 rounded-md text-[10px] font-bold flex items-center gap-1 {{ $filter==='completed' ? 'bg-emerald-500 text-white' : 'text-slate-600 hover:bg-slate-200' }}">
                        <span>COMPLETED</span>
                        <span class="bg-white/20 text-[9px] px-1 rounded-full">{{ $completedCount }}</span>
                    </a>
                    <a href="{{ route('lost-wax.production-status', array_merge(request()->query(), ['filter' => 'all'])) }}" 
                       class="px-3 py-1 rounded-md text-[10px] font-bold flex items-center gap-1 {{ $filter==='all' ? 'bg-slate-600 text-white' : 'text-slate-600 hover:bg-slate-200' }}">
                        <span>ALL</span>
                        <span class="bg-white/20 text-[9px] px-1 rounded-full">{{ $totalCount }}</span>
                    </a>
                </div>
            </div>
        </div>

        {{-- Active Filters Indicators --}}
        @if(!empty($codes) || !empty($customers) || !empty($po_numbers) || !empty($aisis) || $search !== '')
            <div class="flex flex-wrap items-center gap-1.5 text-[11px] text-slate-600 bg-slate-50 border border-slate-200 p-2 rounded-lg no-print">
                <span class="font-semibold mr-1">Active Filters:</span>
                
                @if($search !== '')
                    <span class="inline-flex items-center gap-1 bg-slate-200 text-slate-700 px-2.5 py-0.5 rounded-full font-medium">
                        Search: "{{ $search }}"
                        <button type="button" class="hover:text-red-500 font-bold ml-1 text-slate-500" onclick="clearGlobalSearch()">&times;</button>
                    </span>
                @endif

                @foreach($codes as $c)
                    <span class="inline-flex items-center gap-1 bg-amber-100 text-amber-800 px-2.5 py-0.5 rounded-full font-mono font-medium">
                        Code: {{ $c }}
                        <button type="button" class="hover:text-red-500 font-bold ml-1 text-amber-700" onclick="removeFilterValue('codes', '{{ $c }}')">&times;</button>
                    </span>
                @endforeach

                @foreach($customers as $cust)
                    <span class="inline-flex items-center gap-1 bg-blue-100 text-blue-800 px-2.5 py-0.5 rounded-full font-medium">
                        Customer: {{ $cust }}
                        <button type="button" class="hover:text-red-500 font-bold ml-1 text-blue-700" onclick="removeFilterValue('customers', '{{ $cust }}')">&times;</button>
                    </span>
                @endforeach

                @foreach($po_numbers as $po)
                    <span class="inline-flex items-center gap-1 bg-indigo-100 text-indigo-800 px-2.5 py-0.5 rounded-full font-mono font-medium">
                        PO: {{ $po }}
                        <button type="button" class="hover:text-red-500 font-bold ml-1 text-indigo-700" onclick="removeFilterValue('po_numbers', '{{ $po }}')">&times;</button>
                    </span>
                @endforeach

                @foreach($aisis as $a)
                    <span class="inline-flex items-center gap-1 bg-teal-100 text-teal-800 px-2.5 py-0.5 rounded-full font-mono font-medium">
                        AISI: {{ $a }}
                        <button type="button" class="hover:text-red-500 font-bold ml-1 text-teal-700" onclick="removeFilterValue('aisis', '{{ $a }}')">&times;</button>
                    </span>
                @endforeach

                <a href="{{ route('lost-wax.production-status', ['filter' => $filter]) }}" class="text-blue-600 hover:underline font-semibold ml-2">Clear All</a>
            </div>
        @endif

        <div class="bg-white rounded-lg shadow-sm border border-slate-200 overflow-hidden">
            <div class="table-scroll-container overflow-y-auto overflow-x-auto" style="max-height: calc(100vh - 230px); min-height: 400px;">
                <table class="w-full text-[10px] whitespace-nowrap border-collapse" id="prodStatusTable">
                    <thead class="sticky top-0 z-20 bg-slate-800 text-white shadow-xs">
                        <tr class="bg-slate-800 text-white">
                            <th class="compact-th text-left min-w-[58px] relative">
                                <div class="flex items-center justify-between">
                                    <span>Kode Cust</span>
                                    <button type="button" class="filter-dropdown-trigger text-slate-400 hover:text-white ml-1 p-0.5 rounded" data-filter-type="codes">
                                        <i class="fas fa-filter text-[9px] {{ !empty($codes) ? 'text-amber-400' : '' }}"></i>
                                    </button>
                                </div>
                            </th>
                            <th class="compact-th text-left min-w-[170px] max-w-[190px]">Product Name</th>
                            <th class="compact-th text-left min-w-[45px] relative">
                                <div class="flex items-center justify-between">
                                    <span>AISI</span>
                                    <button type="button" class="filter-dropdown-trigger text-slate-400 hover:text-white ml-1 p-0.5 rounded" data-filter-type="aisis">
                                        <i class="fas fa-filter text-[9px] {{ !empty($aisis) ? 'text-amber-400' : '' }}"></i>
                                    </button>
                                </div>
                            </th>
                            <th class="compact-th text-right min-w-[42px] relative">
                                <div class="flex items-center justify-between">
                                    <span>PO</span>
                                    <button type="button" class="filter-dropdown-trigger text-slate-400 hover:text-white ml-1 p-0.5 rounded" data-filter-type="po_numbers">
                                        <i class="fas fa-filter text-[9px] {{ !empty($po_numbers) ? 'text-amber-400' : '' }}"></i>
                                    </button>
                                </div>
                            </th>
                            <th class="compact-th text-right min-w-[45px]">Plan</th>
                            <th class="compact-th text-center min-w-[45px]">Tot Lap</th>
                            <th class="compact-th text-center min-w-[45px]">Tot Rsk</th>
                            
                            <!-- Cetak/Rangkai flow columns -->
                            <th class="compact-th text-center min-w-[32px]">CTK</th>
                            <th class="compact-th text-center text-slate-400 min-w-[20px]">R</th>
                            <th class="compact-th text-center min-w-[32px]">RGKI</th>
                            <th class="compact-th text-center text-slate-400 min-w-[20px]">R</th>
                            
                            <!-- Coating flow columns -->
                            <th class="compact-th text-center min-w-[30px]">L1</th>
                            <th class="compact-th text-center text-slate-400 min-w-[20px]">R</th>
                            <th class="compact-th text-center min-w-[30px]">L2</th>
                            <th class="compact-th text-center text-slate-400 min-w-[20px]">R</th>
                            <th class="compact-th text-center min-w-[30px]">L3</th>
                            <th class="compact-th text-center text-slate-400 min-w-[20px]">R</th>
                            <th class="compact-th text-center min-w-[30px]">L4</th>
                            <th class="compact-th text-center text-slate-400 min-w-[20px]">R</th>
                            <th class="compact-th text-center min-w-[30px]">L5</th>
                            <th class="compact-th text-center text-slate-400 min-w-[20px]">R</th>
                            <th class="compact-th text-center min-w-[30px]">L6</th>
                            <th class="compact-th text-center text-slate-400 min-w-[20px]">R</th>
                            <th class="compact-th text-center min-w-[30px]">L7</th>
                            <th class="compact-th text-center text-slate-400 min-w-[20px]">R</th>
                            <th class="compact-th text-center min-w-[38px]">Oven</th>
                            <th class="compact-th text-center text-slate-400 min-w-[20px]">R</th>
                            <th class="compact-th text-center min-w-[60px]">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($rows as $row)
                            <tr class="hover:bg-slate-50 cursor-pointer" 
                                data-source-type="{{ $row['source_type'] }}" 
                                data-source-id="{{ $row['source_id'] }}">
                                <td class="compact-td font-mono font-bold text-slate-800">
                                    <a href="#" class="hover:text-amber-600 et-detail-link" 
                                        data-source-type="{{ $row['source_type'] }}" 
                                        data-source-id="{{ $row['source_id'] }}">{{ $row['code'] }}</a>
                                </td>
                                <td class="compact-td text-slate-700">
                                    <div class="prod-name-cell" title="{{ $row['product_name'] }}">{{ $row['product_name'] }}</div>
                                </td>
                                <td class="compact-td text-slate-600">{{ $row['aisi'] }}</td>
                                <td class="compact-td text-right font-mono text-slate-700">{{ $row['planned_qty'] > 0 ? number_format($row['planned_qty'], 0, ',', '.') : '-' }}</td>
                                <td class="compact-td text-right font-mono text-slate-700">{{ $row['scheduled_qty'] > 0 ? number_format($row['scheduled_qty'], 0, ',', '.') : '-' }}</td>
                                <td class="compact-td text-center font-mono {{ $row['total_lap']>0?'font-bold text-slate-800':'text-slate-400' }}">{{ $row['total_lap'] > 0 ? $row['total_lap'] : '-' }}</td>
                                <td class="compact-td text-center font-mono {{ $row['overall_defect']>0?'text-red-600 font-bold':'text-slate-400' }}">{{ $row['overall_defect'] > 0 ? $row['overall_defect'] : '-' }}</td>
                                
                                <!-- CTK & R after CTK -->
                                <td class="compact-td text-center font-mono {{ $row['ctk_display']>0?'cell-layer-active':'text-slate-400' }}">{{ $row['ctk_display'] > 0 ? $row['ctk_display'] : '-' }}</td>
                                <td class="compact-td text-center font-mono {{ $row['r_ctk_display']>0?'font-bold text-red-600 bg-red-50/50':'text-slate-400' }}">{{ $row['r_ctk_display'] > 0 ? $row['r_ctk_display'] : '-' }}</td>
                                
                                <!-- RGKI & R after RGKI -->
                                <td class="compact-td text-center font-mono {{ $row['rgki_display']>0?'cell-layer-active':'text-slate-400' }}">{{ $row['rgki_display'] > 0 ? $row['rgki_display'] : '-' }}</td>
                                <td class="compact-td text-center font-mono {{ $row['r_rgki_display']>0?'font-bold text-red-600 bg-red-50/50':'text-slate-400' }}">{{ $row['r_rgki_display'] > 0 ? $row['r_rgki_display'] : '-' }}</td>
                                
                                <!-- L1 - L7 with R defect indicators -->
                                @foreach(['layer_1','layer_2','layer_3','layer_4','layer_5','layer_6','layer_7'] as $s)
                                    <td class="compact-td text-center font-mono {{ $row[$s]>0?'cell-layer-active':'text-slate-400' }}">{{ $row[$s] > 0 ? $row[$s] : '-' }}</td>
                                    @php $rVal = $row['r_' . $s] ?? 0; @endphp
                                    <td class="compact-td text-center font-mono {{ $rVal > 0 ? 'font-bold text-red-600 bg-red-50/50' : 'text-slate-400' }}">{{ $rVal > 0 ? $rVal : '-' }}</td>
                                @endforeach
                                
                                <td class="compact-td text-center font-mono {{ $row['oven_qty']>0?'cell-oven':'text-slate-400' }}">{{ $row['oven_qty'] > 0 ? $row['oven_qty'] : '-' }}</td>
                                @php $rOven = $row['r_oven'] ?? 0; @endphp
                                <td class="compact-td text-center font-mono {{ $rOven > 0 ? 'font-bold text-red-600 bg-red-50/50' : 'text-slate-400' }}">{{ $rOven > 0 ? $rOven : '-' }}</td>
                                <td class="compact-td text-center">
                                    @if(isset($row['quality_status']))
                                        @if($row['status'] === 'COMPLETED')
                                            <span class="inline-block px-1.5 py-0.5 rounded-full font-extrabold text-[9px] bg-emerald-100 text-emerald-800 border border-emerald-300">
                                                SELESAI
                                            </span>
                                        @elseif($row['quality_status'] === 'NORMAL')
                                            <span class="inline-block px-1.5 py-0.5 rounded-full font-extrabold text-[9px] bg-emerald-100 text-emerald-800 border border-emerald-300" title="Usable: {{ number_format($row['q_usable']) }} pcs &ge; Plan">
                                                NORMAL
                                            </span>
                                        @elseif($row['quality_status'] === 'WARNING')
                                            <span class="inline-block px-1.5 py-0.5 rounded-full font-extrabold text-[9px] bg-amber-100 text-amber-800 border border-amber-300" title="Defisit ke Plan: {{ number_format($row['deficit_vs_plan']) }} pcs{{ $row['po_quantity'] ? ' (PO Aman: ' . number_format($row['po_quantity']) . ' pcs)' : '' }}">
                                                WARNING
                                            </span>
                                        @elseif($row['quality_status'] === 'CRITICAL')
                                            <span class="inline-block px-1.5 py-0.5 rounded-full font-extrabold text-[9px] bg-rose-100 text-rose-800 border border-rose-300" title="PO Terancam! Defisit ke PO: {{ number_format($row['deficit_vs_po']) }} pcs">
                                                CRITICAL
                                            </span>
                                        @else
                                            <span class="inline-block px-1.5 py-0.5 rounded-full font-bold text-[9px] bg-slate-100 text-slate-700 border border-slate-300">
                                                ACTIVE
                                            </span>
                                        @endif
                                    @else
                                        <span class="inline-block px-1.5 py-0.5 rounded-full font-bold text-[9px] {{ $row['status']==='ACTIVE'?'bg-amber-100 text-amber-800':($row['status']==='COMPLETED'?'bg-emerald-100 text-emerald-800':'bg-slate-100 text-slate-600') }}">
                                            {{ $row['status']==='ACTIVE'?'ACTIVE':($row['status']==='COMPLETED'?'SELESAI':$row['status']) }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="28" class="px-6 py-12 text-center text-slate-500"><i class="fas fa-inbox text-3xl mb-2 block opacity-30"></i>Tidak ada data.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="text-xs text-slate-500">
            {{ count($rows) }} Kode Cust ditampilkan.
            @if($search) Filter: <strong>"{{ $search }}"</strong> @endif
            @if(!empty($codes)) &middot; Kode Cust: <strong>{{ implode(', ', $codes) }}</strong> @endif
            @if(!empty($customers)) &middot; Customer: <strong>{{ implode(', ', $customers) }}</strong> @endif
            @if(!empty($po_numbers)) &middot; PO: <strong>{{ implode(', ', $po_numbers) }}</strong> @endif
            @if(!empty($aisis)) &middot; AISI: <strong>{{ implode(', ', $aisis) }}</strong> @endif
            &middot; Status: <strong>{{ strtoupper($filter) }}</strong>
        </div>
    </div>

    {{-- PRINT REPORT --}}
    <div class="production-status-print">
        <div class="print-header">
            <p class="company">PT. PERONI KARYA SENTRA</p>
            <p class="title">LOST WAX &mdash; PRODUCTION STATUS</p>
            <p class="subtitle">Posisi produksi per Kode Cust / Work Order</p>
            <p class="meta">
                Tanggal: {{ now()->format('d/m/Y H:i') }} &nbsp;|&nbsp; Filter: {{ strtoupper($filter) }}
                @if($search) &nbsp;|&nbsp; Search: {{ $search }} @endif
                @if(!empty($codes)) &nbsp;|&nbsp; Kode Cust: {{ implode(', ', $codes) }} @endif
                @if(!empty($customers)) &nbsp;|&nbsp; Customer: {{ implode(', ', $customers) }} @endif
                @if(!empty($po_numbers)) &nbsp;|&nbsp; PO: {{ implode(', ', $po_numbers) }} @endif
                @if(!empty($aisis)) &nbsp;|&nbsp; AISI: {{ implode(', ', $aisis) }} @endif
            </p>
        </div>

        <table class="ps-table">
            <colgroup>
                <col style="width: 24mm;"> <!-- Kode Cust -->
                <col style="width: 65mm;"> <!-- Product Name -->
                <col style="width: 14mm;"> <!-- AISI -->
                <col style="width: 10mm;"> <!-- PO -->
                <col style="width: 10mm;"> <!-- Plan -->
                <col style="width: 12mm;"> <!-- Total Lap. -->
                <col style="width: 12mm;"> <!-- Total Rusak -->
                <col style="width: 8mm;">  <!-- Cetak -->
                <col style="width: 5mm;">  <!-- R -->
                <col style="width: 8mm;">  <!-- Rangkai -->
                <col style="width: 5mm;">  <!-- R -->
                <col style="width: 8mm;">  <!-- L1 -->
                <col style="width: 5mm;">  <!-- R -->
                <col style="width: 8mm;">  <!-- L2 -->
                <col style="width: 5mm;">  <!-- R -->
                <col style="width: 8mm;">  <!-- L3 -->
                <col style="width: 5mm;">  <!-- R -->
                <col style="width: 8mm;">  <!-- L4 -->
                <col style="width: 5mm;">  <!-- R -->
                <col style="width: 8mm;">  <!-- L5 -->
                <col style="width: 5mm;">  <!-- R -->
                <col style="width: 8mm;">  <!-- L6 -->
                <col style="width: 5mm;">  <!-- R -->
                <col style="width: 8mm;">  <!-- L7 -->
                <col style="width: 5mm;">  <!-- R -->
                <col style="width: 9mm;">  <!-- Oven -->
                <col style="width: 5mm;">  <!-- R -->
                <col style="width: 14mm;"> <!-- Status -->
            </colgroup>
            <thead>
                <tr>
                    <th class="left">Kode Cust</th>
                    <th class="left">Product Name</th>
                    <th>AISI</th>
                    <th>PO</th>
                    <th>Plan</th>
                    <th>TOTAL<br>LAP.</th>
                    <th>TOTAL<br>RUSAK</th>
                    
                    <!-- Cetak/Rangkai flow columns -->
                    <th>CTK</th><th>R</th>
                    <th>RANG-<br>KAI</th><th>R</th>
                    
                    <!-- Coating flow columns -->
                    <th>L1</th><th>R</th>
                    <th>L2</th><th>R</th>
                    <th>L3</th><th>R</th>
                    <th>L4</th><th>R</th>
                    <th>L5</th><th>R</th>
                    <th>L6</th><th>R</th>
                    <th>L7</th><th>R</th>
                    <th>Oven</th><th>R</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    <tr>
                        <td class="left" style="font-weight: bold; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            {{ $row['code'] }}
                        </td>
                        <td class="prod-name left">
                            <div style="max-height: 2.3em; line-height: 1.15; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; white-space: normal; word-wrap: break-word;">
                                {{ $row['product_name'] }}
                            </div>
                        </td>
                        <td style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $row['aisi'] }}</td>
                        <td class="right">{{ $row['planned_qty'] > 0 ? number_format($row['planned_qty'],0,',','.') : '-' }}</td>
                        <td class="right">{{ $row['scheduled_qty'] > 0 ? number_format($row['scheduled_qty'],0,',','.') : '-' }}</td>
                        <td class="right" style="font-weight: bold;">{{ $row['total_lap'] > 0 ? $row['total_lap'] : '-' }}</td>
                        <td class="{{ $row['overall_defect'] > 0 ? 'ps-cell-red' : '' }}">{{ $row['overall_defect'] > 0 ? $row['overall_defect'] : '-' }}</td>
                        
                        <!-- Cetak/Rangkai values -->
                        <td class="{{ $row['ctk_display']>0?'ps-cell-green':'' }}">{{ $row['ctk_display'] > 0 ? $row['ctk_display'] : '-' }}</td>
                        <td class="{{ $row['r_ctk_display']>0?'ps-cell-red':'' }}">{{ $row['r_ctk_display'] > 0 ? $row['r_ctk_display'] : '-' }}</td>
                        <td class="{{ $row['rgki_display']>0?'ps-cell-green':'' }}">{{ $row['rgki_display'] > 0 ? $row['rgki_display'] : '-' }}</td>
                        <td class="{{ $row['r_rgki_display']>0?'ps-cell-red':'' }}">{{ $row['r_rgki_display'] > 0 ? $row['r_rgki_display'] : '-' }}</td>
                        
                        <!-- Coating values -->
                        @foreach(['layer_1','layer_2','layer_3','layer_4','layer_5','layer_6','layer_7'] as $s)
                            <td class="{{ $row[$s]>0?'ps-cell-green':'' }}">{{ $row[$s] > 0 ? $row[$s] : '-' }}</td>
                            @php $rVal = $row['r_' . $s] ?? 0; @endphp
                            <td class="{{ $rVal > 0 ? 'ps-cell-red' : '' }}">{{ $rVal > 0 ? $rVal : '-' }}</td>
                        @endforeach
                        
                        <td class="{{ $row['oven_qty']>0?'ps-cell-oven':'' }}">{{ $row['oven_qty'] > 0 ? $row['oven_qty'] : '-' }}</td>
                        @php $rOven = $row['r_oven'] ?? 0; @endphp
                        <td class="{{ $rOven > 0 ? 'ps-cell-red' : '' }}">{{ $rOven > 0 ? $rOven : '-' }}</td>
                        
                        <td style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            @if(isset($row['quality_status']) && $row['status'] !== 'COMPLETED')
                                {{ $row['quality_status'] }}
                            @else
                                {{ $row['status']==='ACTIVE'?'ACTIVE':($row['status']==='COMPLETED'?'SELESAI':$row['status']) }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="28" style="text-align:center;padding:10px;">Tidak ada data.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Excel Dropdowns --}}
    @foreach(['codes' => ['title' => 'Filter Kode Cust', 'options' => $allCodes, 'active' => $codes], 
              'customers' => ['title' => 'Filter Customer', 'options' => $allCustomers, 'active' => $customers],
              'po_numbers' => ['title' => 'Filter PO Number', 'options' => $allPos, 'active' => $po_numbers],
              'aisis' => ['title' => 'Filter AISI', 'options' => $allAisi, 'active' => $aisis]] as $type => $conf)
        <div id="{{ $type }}-filter-dropdown" class="filter-dropdown-menu fixed hidden z-50 bg-white border border-slate-300 rounded-lg shadow-xl p-3 w-60 text-slate-800 text-[11px] no-print">
            <div class="flex items-center justify-between pb-1.5 mb-1.5 border-b border-slate-100">
                <span class="font-bold text-slate-700">{{ $conf['title'] }}</span>
                <button type="button" class="text-slate-400 hover:text-slate-600 font-bold text-sm leading-none" onclick="closeAllDropdowns()">&times;</button>
            </div>
            <div class="mb-2">
                <input type="text" placeholder="Search..." class="w-full px-2 py-1 border border-slate-300 rounded text-[11px] filter-search-box focus:border-amber-500 focus:ring-amber-500" oninput="filterDropdownOptions('{{ $type }}', this.value)">
            </div>
            <div class="mb-2 flex items-center justify-between">
                <label class="flex items-center gap-1.5 cursor-pointer font-semibold text-slate-600">
                    <input type="checkbox" class="select-all-checkbox rounded border-slate-300 text-amber-600 focus:ring-amber-500" onchange="toggleSelectAll('{{ $type }}', this.checked)">
                    <span>(Select All)</span>
                </label>
                <button type="button" class="text-blue-600 hover:underline" onclick="clearAllOptions('{{ $type }}')">Clear</button>
            </div>
            <div class="max-h-40 overflow-y-auto space-y-0.5 border-t border-b border-slate-100 py-1.5 option-list-container">
                @if(!app()->runningUnitTests())
                    @foreach($conf['options'] as $opt)
                        <label class="flex items-start gap-2 cursor-pointer py-0.5 px-1 rounded hover:bg-slate-50 option-item">
                            <input type="checkbox" value="{{ $opt }}" class="option-checkbox rounded border-slate-300 text-amber-600 focus:ring-amber-500" {{ in_array($opt, $conf['active']) ? 'checked' : '' }}>
                            <span class="option-text font-mono text-slate-700">{{ $opt }}</span>
                        </label>
                    @endforeach
                @endif
            </div>
            <div class="flex justify-end gap-1.5 pt-2">
                <button type="button" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 rounded font-semibold text-[10px] text-slate-700" onclick="closeAllDropdowns()">Cancel</button>
                <button type="button" class="px-2.5 py-1 bg-amber-600 hover:bg-amber-700 text-white rounded font-semibold text-[10px]" onclick="applyFilter('{{ $type }}')">Apply</button>
            </div>
        </div>
    @endforeach

    {{-- Detail Modal --}}
    <div id="etDetailModal" class="fixed inset-0 z-50 hidden no-print" style="background:rgba(0,0,0,0.5)">
        <div class="absolute right-0 top-0 h-full w-full max-w-lg bg-white shadow-2xl overflow-y-auto">
            <div class="sticky top-0 bg-slate-800 text-white px-5 py-4 flex items-center justify-between z-10">
                <div><h3 class="font-bold text-sm" id="modalTitle">Detail Kode Cust</h3><p class="text-xs text-slate-400" id="modalSubtitle"></p></div>
                <button onclick="closeETDetail()" class="text-white hover:text-slate-300 text-xl leading-none">&times;</button>
            </div>
            <div id="etDetailContent" class="p-5"><div class="text-center py-8 text-slate-500"><i class="fas fa-spinner fa-spin text-2xl"></i></div></div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded',function(){
            document.querySelectorAll('.et-detail-link').forEach(function(l){
                l.addEventListener('click',function(e){
                    e.preventDefault();
                    openETDetail(this.dataset.sourceType, this.dataset.sourceId);
                });
            });
            document.querySelectorAll('#prodStatusTable tbody tr').forEach(function(r){
                r.addEventListener('click',function(e){
                    if(e.target.tagName !== 'A' && !e.target.closest('a') && !e.target.closest('input') && !e.target.closest('button')) {
                        openETDetail(this.dataset.sourceType, this.dataset.sourceId);
                    }
                });
            });
            document.getElementById('etDetailModal').addEventListener('click',function(e){if(e.target===this)closeETDetail()});
            document.addEventListener('keydown',function(e){if(e.key==='Escape')closeETDetail()});

            // Excel drop trigger setup
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
            closeAllDropdowns();
            const dropdown = document.getElementById(type + '-filter-dropdown');
            if (!dropdown) return;
            dropdown.classList.remove('hidden');

            const rect = button.getBoundingClientRect();
            const dropdownWidth = 240;
            let left = rect.left;
            let top = rect.bottom + 4;

            if (left + dropdownWidth > window.innerWidth) {
                left = window.innerWidth - dropdownWidth - 10;
            }

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
            const items = dropdown.querySelectorAll('.option-item');
            const q = query.toLowerCase();
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
            dropdown.querySelectorAll('.option-item').forEach(function(item){
                if (item.style.display !== 'none') {
                    item.querySelector('.option-checkbox').checked = checked;
                }
            });
        }

        function clearAllOptions(type) {
            const dropdown = document.getElementById(type + '-filter-dropdown');
            dropdown.querySelectorAll('.option-checkbox').forEach(function(cb){
                cb.checked = false;
            });
            const selectAll = dropdown.querySelector('.select-all-checkbox');
            if (selectAll) selectAll.checked = false;
        }

        function applyFilter(type) {
            const dropdown = document.getElementById(type + '-filter-dropdown');
            const checkedValues = [];
            dropdown.querySelectorAll('.option-checkbox').forEach(function(cb){
                if (cb.checked) {
                    checkedValues.push(cb.value);
                }
            });

            const urlParams = new URLSearchParams(window.location.search);
            urlParams.delete(type + '[]');
            urlParams.delete(type); // fallback check

            checkedValues.forEach(function(val){
                urlParams.append(type + '[]', val);
            });

            window.location.href = window.location.pathname + '?' + urlParams.toString();
        }

        function removeFilterValue(type, value) {
            const urlParams = new URLSearchParams(window.location.search);
            const values = urlParams.getAll(type + '[]');
            urlParams.delete(type + '[]');
            urlParams.delete(type); // fallback check

            values.forEach(function(val){
                if (val !== value) {
                    urlParams.append(type + '[]', val);
                }
            });

            window.location.href = window.location.pathname + '?' + urlParams.toString();
        }

        function clearGlobalSearch() {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.delete('search');
            window.location.href = window.location.pathname + '?' + urlParams.toString();
        }

        function openETDetail(sourceType, sourceId){
            var m=document.getElementById('etDetailModal'),c=document.getElementById('etDetailContent'),t=document.getElementById('modalTitle'),s=document.getElementById('modalSubtitle');
            m.classList.remove('hidden');
            c.innerHTML='<div class="text-center py-8 text-slate-500"><i class="fas fa-spinner fa-spin text-2xl"></i></div>';
            t.textContent='Detail Kode Cust';
            s.textContent='';
            var url = '{{ route('lost-wax.production-status.trees') }}';
            if (sourceType === 'legacy_work_order') {
                url += '?work_order_id=' + sourceId;
            } else {
                url += '?print_order_line_id=' + sourceId;
            }
            fetch(url).then(function(r){return r.json()}).then(function(d){
                t.textContent='Detail Kode Cust: '+(d.et_code||'-');
                s.textContent=d.item_name||d.tree_count+' Trees';
                if(!d.trees||d.trees.length===0){c.innerHTML='<p class="text-slate-500 text-sm text-center py-8">Belum ada Tree untuk Kode Cust ini.</p>';return}
                var h='<div class="space-y-3"><div class="text-xs text-slate-500 mb-3">'+d.trees.length+' Tree</div>';
                d.trees.forEach(function(x){
                    var sc=x.current_stage==='oven'?'bg-teal-100 text-teal-800':'bg-amber-100 text-amber-800';
                    var ai=x.aging_status==='too_fast'?'\u26A0\uFE0F':(x.aging_status==='too_long'?'\u274C':'');
                    h+='<div class="border border-slate-200 rounded-lg p-3"><div class="flex items-center justify-between mb-2"><span class="font-mono font-bold text-sm">'+x.tree_number+' &mdash; '+(x.barcode||'-')+'</span><span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold '+sc+'">'+(x.current_stage_label||'-')+'</span></div><div class="grid grid-cols-3 gap-2 text-xs text-slate-600"><div><span class="text-slate-400">Qty:</span> <span class="font-bold">'+x.quantity+' PCS</span></div><div><span class="text-slate-400">Last Scan:</span> '+(x.last_scan_at||'-')+'</div><div><span class="text-slate-400">Aging:</span> '+(x.aging_label||'-')+' '+ai+'</div></div></div>';
                });
                c.innerHTML=h+'</div>';
            }).catch(function(){c.innerHTML='<p class="text-red-500 text-sm text-center py-8">Gagal memuat data.</p>'});
        }
        function closeETDetail(){document.getElementById('etDetailModal').classList.add('hidden')}
    </script>
@endsection
