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
                <i class="fas fa-download mr-1"></i> CSV
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
            max-width: 140px;
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
            #prodStatusTable thead th { position: sticky; top: 0; z-index: 20; }
            #prodStatusTable thead th:first-child { z-index: 30; }
            .production-status-print { display: none; }
        }

        @media print {
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
        <div class="bg-white rounded-lg shadow-sm border border-slate-200 p-4">
            <form method="GET" action="{{ route('lost-wax.production-status') }}" class="space-y-3 w-full">
                <input type="hidden" name="filter" value="{{ $filter }}">
                
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Search</label>
                        <div class="relative">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                            <input type="text" name="search" value="{{ $search }}"
                                placeholder="Kode / Product / PO..."
                                class="w-full pl-9 pr-3 py-1.5 rounded-lg border-slate-300 text-xs focus:border-amber-500 focus:ring-amber-500">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Customer</label>
                        <input type="text" name="customer" value="{{ $customer }}" list="customer_list"
                            placeholder="Pilih Customer..."
                            class="w-full px-3 py-1.5 rounded-lg border-slate-300 text-xs focus:border-amber-500 focus:ring-amber-500">
                        <datalist id="customer_list">
                            @foreach($allCustomers as $c)
                                <option value="{{ $c }}"></option>
                            @endforeach
                        </datalist>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">P.O. Number</label>
                        <input type="text" name="po_number" value="{{ $po_number }}" list="po_list"
                            placeholder="Pilih PO..."
                            class="w-full px-3 py-1.5 rounded-lg border-slate-300 text-xs focus:border-amber-500 focus:ring-amber-500">
                        <datalist id="po_list">
                            @foreach($allPos as $po)
                                <option value="{{ $po }}"></option>
                            @endforeach
                        </datalist>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">AISI</label>
                        <input type="text" name="aisi" value="{{ $aisi }}" list="aisi_list"
                            placeholder="Pilih AISI..."
                            class="w-full px-3 py-1.5 rounded-lg border-slate-300 text-xs focus:border-amber-500 focus:ring-amber-500">
                        <datalist id="aisi_list">
                            @foreach($allAisi as $a)
                                <option value="{{ $a }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row justify-between items-stretch sm:items-center gap-3 pt-1 border-t border-slate-100">
                    <div class="flex gap-2">
                        <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold py-1.5 px-4 rounded-lg shadow-sm">
                            Filter
                        </button>
                        <a href="{{ route('lost-wax.production-status', ['filter' => $filter]) }}" 
                           class="bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold py-1.5 px-4 rounded-lg shadow-sm text-center">
                            Reset
                        </a>
                    </div>

                    <div class="flex gap-1 bg-slate-100 rounded-lg p-1">
                        <a href="{{ route('lost-wax.production-status', array_merge(request()->query(), ['filter' => 'active'])) }}" 
                           class="px-2.5 py-1 rounded-md text-[10px] font-bold {{ $filter==='active' ? 'bg-amber-500 text-white' : 'text-slate-600 hover:bg-slate-200' }}">
                            ACTIVE
                        </a>
                        <a href="{{ route('lost-wax.production-status', array_merge(request()->query(), ['filter' => 'completed'])) }}" 
                           class="px-2.5 py-1 rounded-md text-[10px] font-bold {{ $filter==='completed' ? 'bg-emerald-500 text-white' : 'text-slate-600 hover:bg-slate-200' }}">
                            COMPLETED
                        </a>
                        <a href="{{ route('lost-wax.production-status', array_merge(request()->query(), ['filter' => 'all'])) }}" 
                           class="px-2.5 py-1 rounded-md text-[10px] font-bold {{ $filter==='all' ? 'bg-slate-600 text-white' : 'text-slate-600 hover:bg-slate-200' }}">
                            ALL
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-slate-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-[10px] whitespace-nowrap border-collapse" id="prodStatusTable">
                    <thead>
                        <tr class="bg-slate-800 text-white">
                            <th class="compact-th text-left min-w-[80px]">Kode Cust</th>
                            <th class="compact-th text-left min-w-[130px] max-w-[140px]">Product Name</th>
                            <th class="compact-th text-left min-w-[40px]">AISI</th>
                            <th class="compact-th text-right min-w-[40px]">PO</th>
                            <th class="compact-th text-right min-w-[40px]">Plan</th>
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
                                <td class="compact-td text-center font-mono {{ $row['r_ctk_display']>0?'cell-layer-active':'text-slate-400' }}">{{ $row['r_ctk_display'] > 0 ? $row['r_ctk_display'] : '-' }}</td>
                                
                                <!-- RGKI & R after RGKI -->
                                <td class="compact-td text-center font-mono {{ $row['rgki_display']>0?'cell-layer-active':'text-slate-400' }}">{{ $row['rgki_display'] > 0 ? $row['rgki_display'] : '-' }}</td>
                                <td class="compact-td text-center font-mono {{ $row['r_rgki_display']>0?'cell-layer-active':'text-slate-400' }}">{{ $row['r_rgki_display'] > 0 ? $row['r_rgki_display'] : '-' }}</td>
                                
                                <!-- L1 - L7 with R spacers -->
                                @foreach(['layer_1','layer_2','layer_3','layer_4','layer_5','layer_6','layer_7'] as $s)
                                    <td class="compact-td text-center font-mono {{ $row[$s]>0?'cell-layer-active':'text-slate-400' }}">{{ $row[$s] > 0 ? $row[$s] : '-' }}</td>
                                    <td class="compact-td text-center text-slate-400">-</td>
                                @endforeach
                                
                                <td class="compact-td text-center font-mono {{ $row['oven_qty']>0?'cell-oven':'text-slate-400' }}">{{ $row['oven_qty'] > 0 ? $row['oven_qty'] : '-' }}</td>
                                <td class="compact-td text-center">
                                    <span class="inline-block px-1.5 py-0.5 rounded-full font-bold text-[9px] {{ $row['status']==='ACTIVE'?'bg-amber-100 text-amber-800':($row['status']==='COMPLETED'?'bg-emerald-100 text-emerald-800':'bg-slate-100 text-slate-600') }}">
                                        {{ $row['status']==='ACTIVE'?'ACTIVE':($row['status']==='COMPLETED'?'SELESAI':$row['status']) }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="27" class="px-6 py-12 text-center text-slate-500"><i class="fas fa-inbox text-3xl mb-2 block opacity-30"></i>Tidak ada data.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="text-xs text-slate-500">
            {{ count($rows) }} Kode Cust ditampilkan.
            @if($search) Filter: <strong>{{ $search }}</strong> @endif
            @if($customer) &middot; Customer: <strong>{{ $customer }}</strong> @endif
            @if($po_number) &middot; PO: <strong>{{ $po_number }}</strong> @endif
            @if($aisi) &middot; AISI: <strong>{{ $aisi }}</strong> @endif
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
                @if($customer) &nbsp;|&nbsp; Customer: {{ $customer }} @endif
                @if($po_number) &nbsp;|&nbsp; PO: {{ $po_number }} @endif
                @if($aisi) &nbsp;|&nbsp; AISI: {{ $aisi }} @endif
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
                    <th>Oven</th>
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
                        <td class="{{ $row['r_ctk_display']>0?'ps-cell-green':'' }}">{{ $row['r_ctk_display'] > 0 ? $row['r_ctk_display'] : '-' }}</td>
                        <td class="{{ $row['rgki_display']>0?'ps-cell-green':'' }}">{{ $row['rgki_display'] > 0 ? $row['rgki_display'] : '-' }}</td>
                        <td class="{{ $row['r_rgki_display']>0?'ps-cell-green':'' }}">{{ $row['r_rgki_display'] > 0 ? $row['r_rgki_display'] : '-' }}</td>
                        
                        <!-- Coating values -->
                        @foreach(['layer_1','layer_2','layer_3','layer_4','layer_5','layer_6','layer_7'] as $s)
                            <td class="{{ $row[$s]>0?'ps-cell-green':'' }}">{{ $row[$s] > 0 ? $row[$s] : '-' }}</td>
                            <td>-</td>
                        @endforeach
                        
                        <td class="{{ $row['oven_qty']>0?'ps-cell-oven':'' }}">{{ $row['oven_qty'] > 0 ? $row['oven_qty'] : '-' }}</td>
                        <td style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $row['status']==='ACTIVE'?'ACTIVE':($row['status']==='COMPLETED'?'SELESAI':$row['status']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="27" style="text-align:center;padding:10px;">Tidak ada data.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

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
                    if(e.target.tagName !== 'A' && !e.target.closest('a')) {
                        openETDetail(this.dataset.sourceType, this.dataset.sourceId);
                    }
                });
            });
            document.getElementById('etDetailModal').addEventListener('click',function(e){if(e.target===this)closeETDetail()});
            document.addEventListener('keydown',function(e){if(e.key==='Escape')closeETDetail()})
        });
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
