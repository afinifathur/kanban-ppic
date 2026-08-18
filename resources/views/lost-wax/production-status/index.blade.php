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

            .print-header { text-align: center; margin-bottom: 5mm; }
            .print-header .company { font-size: 13px; font-weight: 700; margin: 0; }
            .print-header .title { font-size: 12px; font-weight: 700; margin: 2mm 0; }
            .print-header .subtitle { font-size: 8px; color: #555; margin: 0 0 1mm 0; }
            .print-header .meta { font-size: 8px; color: #555; margin: 0; }

            .ps-table { width: 100%; border-collapse: collapse; font-size: 7px; }
            .ps-table th, .ps-table td { border: 0.5px solid #888; padding: 1.5px 2px; text-align: center; vertical-align: middle; word-break: break-word; }
            .ps-table th { background: #1e293b !important; color: white !important; font-weight: 700; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .ps-table td.left { text-align: left; }
            .ps-table td.right { text-align: right; }
            .ps-table td.prod-name { text-align: left; font-size: 6.5px; }

            .ps-cell-green { background: #d1fae5 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .ps-cell-oven { background: #ccfbf1 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .ps-cell-red { background: #fee2e2 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

            thead { display: table-header-group !important; }
            tr { break-inside: avoid !important; page-break-inside: avoid !important; }

            @page { size: A4 landscape; margin: 6mm; }
        }
    </style>

    {{-- WEB UI --}}
    <div class="production-status-web space-y-4">
        <div class="bg-white rounded-lg shadow-sm border border-slate-200 p-4">
            <div class="flex flex-col sm:flex-row gap-3 items-start sm:items-center">
                <form method="GET" class="flex gap-2 items-center flex-1 w-full">
                    <input type="hidden" name="filter" value="{{ $filter }}">
                    <div class="relative flex-1">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                        <input type="text" name="search" value="{{ $search }}"
                            placeholder="Cari Kode Cust / Product Name / PO..."
                            class="w-full pl-9 pr-4 py-2 rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500">
                    </div>
                    <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white text-sm font-bold py-2 px-4 rounded-lg">Cari</button>
                    @if($search) <a href="{{ route('lost-wax.production-status', ['filter' => $filter]) }}" class="text-xs text-slate-500 hover:text-slate-700">Clear</a> @endif
                </form>
                <div class="flex gap-1 bg-slate-100 rounded-lg p-1">
                    <a href="{{ route('lost-wax.production-status', ['filter' => 'active', 'search' => $search]) }}" class="px-3 py-1.5 rounded-md text-xs font-bold {{ $filter==='active' ? 'bg-amber-500 text-white' : 'text-slate-600 hover:bg-slate-200' }}">ACTIVE</a>
                    <a href="{{ route('lost-wax.production-status', ['filter' => 'completed', 'search' => $search]) }}" class="px-3 py-1.5 rounded-md text-xs font-bold {{ $filter==='completed' ? 'bg-emerald-500 text-white' : 'text-slate-600 hover:bg-slate-200' }}">COMPLETED</a>
                    <a href="{{ route('lost-wax.production-status', ['filter' => 'all', 'search' => $search]) }}" class="px-3 py-1.5 rounded-md text-xs font-bold {{ $filter==='all' ? 'bg-slate-600 text-white' : 'text-slate-600 hover:bg-slate-200' }}">ALL</a>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-slate-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-xs whitespace-nowrap border-collapse" id="prodStatusTable">
                    <thead>
                        <tr class="bg-slate-800 text-white">
                            <th class="px-2.5 py-2 text-left font-semibold min-w-[95px]">Kode Cust</th>
                            <th class="px-2.5 py-2 text-left font-semibold min-w-[180px]">Product Name</th>
                            <th class="px-2.5 py-2 text-left font-semibold min-w-[50px]">AISI</th>
                            <th class="px-2.5 py-2 text-right font-semibold min-w-[50px]">PO</th>
                            <th class="px-2.5 py-2 text-right font-semibold min-w-[50px]">Plan</th>
                            <th class="px-2.5 py-2 text-center font-semibold min-w-[50px]">Total Lap.</th>
                            <th class="px-2.5 py-2 text-center font-semibold min-w-[50px]">Total Rusak</th>
                            <th class="px-2.5 py-2 text-center font-semibold min-w-[42px]">L1</th><th class="px-2.5 py-2 text-center font-semibold text-slate-400 min-w-[30px]">R</th>
                            <th class="px-2.5 py-2 text-center font-semibold min-w-[42px]">L2</th><th class="px-2.5 py-2 text-center font-semibold text-slate-400 min-w-[30px]">R</th>
                            <th class="px-2.5 py-2 text-center font-semibold min-w-[42px]">L3</th><th class="px-2.5 py-2 text-center font-semibold text-slate-400 min-w-[30px]">R</th>
                            <th class="px-2.5 py-2 text-center font-semibold min-w-[42px]">L4</th><th class="px-2.5 py-2 text-center font-semibold text-slate-400 min-w-[30px]">R</th>
                            <th class="px-2.5 py-2 text-center font-semibold min-w-[42px]">L5</th><th class="px-2.5 py-2 text-center font-semibold text-slate-400 min-w-[30px]">R</th>
                            <th class="px-2.5 py-2 text-center font-semibold min-w-[42px]">L6</th><th class="px-2.5 py-2 text-center font-semibold text-slate-400 min-w-[30px]">R</th>
                            <th class="px-2.5 py-2 text-center font-semibold min-w-[42px]">L7</th><th class="px-2.5 py-2 text-center font-semibold text-slate-400 min-w-[30px]">R</th>
                            <th class="px-2.5 py-2 text-center font-semibold min-w-[48px]">Oven</th>
                            <th class="px-2.5 py-2 text-center font-semibold min-w-[70px]">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @php $sk = ['layer_1','layer_2','layer_3','layer_4','layer_5','layer_6','layer_7']; @endphp
                        @forelse($rows as $row)
                            <tr class="hover:bg-slate-50 cursor-pointer" 
                                data-source-type="{{ $row['source_type'] }}" 
                                data-source-id="{{ $row['source_id'] }}">
                                <td class="px-2.5 py-1.5 font-mono font-bold text-slate-800 text-[11px]">
                                    <a href="#" class="hover:text-amber-600 et-detail-link" 
                                        data-source-type="{{ $row['source_type'] }}" 
                                        data-source-id="{{ $row['source_id'] }}">{{ $row['code'] }}</a>
                                </td>
                                <td class="px-2.5 py-1.5 text-slate-700 text-[11px] max-w-[220px] truncate" title="{{ $row['product_name'] }}">{{ $row['product_name'] }}</td>
                                <td class="px-2.5 py-1.5 text-slate-600 text-[11px]">{{ $row['aisi'] }}</td>
                                <td class="px-2.5 py-1.5 text-right font-mono text-slate-700 text-[11px]">{{ number_format($row['planned_qty'],0,',','.') }}</td>
                                <td class="px-2.5 py-1.5 text-right font-mono text-slate-700 text-[11px]">{{ number_format($row['scheduled_qty'],0,',','.') }}</td>
                                <td class="px-2.5 py-1.5 text-center font-mono text-[11px] {{ $row['total_lap']>0?'font-bold text-slate-800':'text-slate-300' }}">{{ $row['total_lap'] }}</td>
                                <td class="px-2.5 py-1.5 text-center font-mono text-[11px] {{ $row['actual_defect']>0?'text-red-600 font-bold':'text-slate-300' }}">{{ $row['actual_defect'] > 0 ? $row['actual_defect'] : '—' }}</td>
                                @foreach($sk as $s) <td class="px-2.5 py-1.5 text-center font-mono text-[11px] {{ $row[$s]>0?'cell-layer-active':'text-slate-300' }}">{{ $row[$s] }}</td> <td class="px-2.5 py-1.5 text-center text-slate-300 text-[11px]">&mdash;</td> @endforeach
                                <td class="px-2.5 py-1.5 text-center font-mono text-[11px] {{ $row['oven_qty']>0?'cell-oven':'text-slate-300' }}">{{ $row['oven_qty'] }}</td>
                                <td class="px-2.5 py-1.5 text-center text-[10px]">
                                    <span class="inline-block px-1.5 py-0.5 rounded-full font-bold {{ $row['status']==='ACTIVE'?'bg-amber-100 text-amber-800':($row['status']==='COMPLETED'?'bg-emerald-100 text-emerald-800':'bg-slate-100 text-slate-600') }}">
                                        {{ $row['status']==='ACTIVE'?'ACTIVE':($row['status']==='COMPLETED'?'SELESAI':$row['status']) }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="23" class="px-6 py-12 text-center text-slate-500"><i class="fas fa-inbox text-3xl mb-2 block opacity-30"></i>Tidak ada data.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="text-xs text-slate-500">{{ count($rows) }} Kode Cust ditampilkan. @if($search) Filter: <strong>{{ $search }}</strong> @endif &middot; Status: <strong>{{ strtoupper($filter) }}</strong></div>
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
            </p>
        </div>

        <table class="ps-table">
            <thead>
                <tr>
                    <th class="left">Kode Cust</th>
                    <th class="left">Product Name</th>
                    <th>AISI</th>
                    <th>PO</th>
                    <th>Plan</th>
                    <th>Total Lap.</th>
                    <th>Total Rusak</th>
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
                @php $sk = ['layer_1','layer_2','layer_3','layer_4','layer_5','layer_6','layer_7']; @endphp
                @forelse($rows as $row)
                    <tr>
                        <td class="left"><strong>{{ $row['code'] }}</strong></td>
                        <td class="prod-name">{{ $row['product_name'] }}</td>
                        <td>{{ $row['aisi'] }}</td>
                        <td class="right">{{ number_format($row['planned_qty'],0,',','.') }}</td>
                        <td class="right">{{ number_format($row['scheduled_qty'],0,',','.') }}</td>
                        <td class="right"><strong>{{ $row['total_lap'] }}</strong></td>
                        <td>{{ $row['actual_defect'] > 0 ? $row['actual_defect'] : '—' }}</td>
                        @foreach($sk as $s) <td class="{{ $row[$s]>0?'ps-cell-green':'' }}"><strong>{{ $row[$s] }}</strong></td> <td class="{{ ($row['actual_defect']??0)>0?'ps-cell-red':'' }}">—</td> @endforeach
                        <td class="{{ $row['oven_qty']>0?'ps-cell-oven':'' }}"><strong>{{ $row['oven_qty'] }}</strong></td>
                        <td>{{ $row['status']==='ACTIVE'?'ACTIVE':($row['status']==='COMPLETED'?'SELESAI':$row['status']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="23" style="text-align:center;padding:10px;">Tidak ada data.</td></tr>
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
