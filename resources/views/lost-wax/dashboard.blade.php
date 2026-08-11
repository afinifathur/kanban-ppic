@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Lost Wax Dashboard</h1>
            <p class="text-gray-500 text-[10px]">Monitoring posisi Tree, aging, dan anomali</p>
        </div>
    </div>
@endsection

@section('content')
    <div class="space-y-6 max-w-[1600px]">

        {{-- Barcode Search --}}
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <form method="GET" class="flex gap-3 items-center">
                <div class="flex-1 relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                    <input type="text" name="search" value="{{ $search }}"
                        placeholder="Cari Barcode / ET / PO / SKU"
                        class="w-full pl-10 pr-4 py-2.5 rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500">
                </div>
                <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white text-sm font-bold py-2.5 px-6 rounded-lg">
                    Cari
                </button>
                @if($search)
                    <a href="{{ route('lost-wax.dashboard') }}" class="text-xs text-slate-500 hover:text-slate-700">Clear</a>
                @endif
            </form>

            @if($searchResult)
                <div class="mt-4 p-4 rounded-lg border {{ $searchResult['found'] ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' }}">
                    @if($searchResult['found'])
                        <div class="text-emerald-700 font-bold text-sm mb-2">
                            <i class="fas fa-check-circle mr-1"></i> Tree Ditemukan
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
                            <div>
                                <span class="text-slate-500 text-xs">Barcode</span>
                                <div class="font-mono font-bold text-slate-800">{{ $searchResult['barcode'] }}</div>
                            </div>
                            <div>
                                <span class="text-slate-500 text-xs">Tree / Qty</span>
                                <div class="font-bold text-slate-800">#{{ str_pad((string) $searchResult['tree_number'], 3, '0', STR_PAD_LEFT) }} &middot; {{ number_format($searchResult['quantity']) }} PCS</div>
                            </div>
                            <div>
                                <span class="text-slate-500 text-xs">ET</span>
                                <div class="font-bold text-slate-800">{{ $searchResult['et_code'] }}</div>
                            </div>
                            <div>
                                <span class="text-slate-500 text-xs">SKU / Produk</span>
                                <div class="font-bold text-slate-800 truncate">{{ $searchResult['item_code'] }} &mdash; {{ $searchResult['item_name'] }}</div>
                            </div>
                        </div>
                        <div class="mt-3 pt-3 border-t border-emerald-200 grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
                            <div>
                                <span class="text-slate-500 text-xs">Posisi Saat Ini</span>
                                <div class="font-bold text-lg {{ $searchResult['current_stage'] === 'oven' ? 'text-slate-500' : ($searchResult['current_stage'] ? 'text-amber-700' : 'text-blue-600') }}">
                                    {{ $searchResult['current_stage_label'] }}
                                </div>
                            </div>
                            <div>
                                <span class="text-slate-500 text-xs">Terakhir Scan</span>
                                <div class="font-bold text-slate-800">{{ $searchResult['last_scan_at']?->format('H:i:s d-m-Y') ?? '-' }}</div>
                            </div>
                            <div>
                                <span class="text-slate-500 text-xs">Aging</span>
                                <div class="font-bold {{ ($searchResult['aging_status'] ?? '') === 'too_long' ? 'text-red-600' : (($searchResult['aging_status'] ?? '') === 'too_fast' ? 'text-amber-600' : 'text-slate-800') }}">
                                    {{ $searchResult['aging_label'] ?? '-' }}
                                </div>
                            </div>
                            <div>
                                <span class="text-slate-500 text-xs">Berikutnya</span>
                                <div class="font-bold text-slate-800">{{ $searchResult['next_stage_label'] ?? 'SELESAI' }}</div>
                            </div>
                        </div>
                        <div class="mt-3 flex gap-2">
                            <a href="{{ route('lost-wax.trees.show', $searchResult['tree']) }}" class="text-xs bg-slate-700 hover:bg-slate-800 text-white py-1 px-3 rounded">Detail Tree</a>
                            <a href="{{ route('lost-wax.trees.history', $searchResult['tree']) }}" class="text-xs bg-slate-600 hover:bg-slate-700 text-white py-1 px-3 rounded">Riwayat</a>
                        </div>
                    @elseif($searchResult['matched_wos']->isNotEmpty())
                        <div class="text-amber-700 font-bold text-sm mb-2">
                            <i class="fas fa-info-circle mr-1"></i> Work Order Ditemukan (bukan barcode langsung)
                        </div>
                        @foreach($searchResult['matched_wos'] as $match)
                            <div class="text-sm text-slate-700 mb-1">
                                <strong>{{ $match['et_code'] }}</strong> &mdash;
                                {{ $match['item_code'] }} &mdash; {{ $match['item_name'] }}
                                ({{ $match['tree_count'] }} tree)
                            </div>
                        @endforeach
                    @else
                        <div class="text-amber-700 text-sm">Tidak ditemukan tree atau Work Order dengan kode tersebut.</div>
                    @endif
                </div>
            @endif
        </div>

        {{-- Section 1: Overview Cards --}}
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Active WO</div>
                <div class="text-2xl font-bold text-slate-800">{{ $overview['activeWos'] }}</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Total Tree</div>
                <div class="text-2xl font-bold text-slate-800">{{ $overview['totalTrees'] }}</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 border-l-4 border-l-amber-400">
                <div class="text-xs text-slate-500">In Process</div>
                <div class="text-2xl font-bold text-amber-700">{{ $overview['inProcess'] }}</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 border-l-4 border-l-emerald-400">
                <div class="text-xs text-slate-500">Completed</div>
                <div class="text-2xl font-bold text-emerald-700">{{ $overview['completed'] }}</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 border-l-4 {{ $overview['agingAnomaly'] > 0 ? 'border-l-red-400' : 'border-l-slate-300' }}">
                <div class="text-xs text-slate-500">Aging TOO_LONG</div>
                <div class="text-2xl font-bold {{ $overview['agingAnomaly'] > 0 ? 'text-red-600' : 'text-slate-800' }}">{{ $overview['agingAnomaly'] }}</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 border-l-4 {{ $overview['rejectedCount'] > 0 ? 'border-l-orange-400' : 'border-l-slate-300' }}">
                <div class="text-xs text-slate-500">Scan Ditolak</div>
                <div class="text-2xl font-bold {{ $overview['rejectedCount'] > 0 ? 'text-orange-600' : 'text-slate-800' }}">{{ $overview['rejectedCount'] }}</div>
            </div>
        </div>

        {{-- Filters --}}
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-3">
            <form method="GET" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="search" value="{{ $search }}">
                <div>
                    <label class="block text-[10px] font-medium text-slate-500 mb-0.5">ET</label>
                    <select name="et" class="rounded-lg border-slate-300 text-xs py-1.5">
                        <option value="">Semua ET</option>
                        @foreach($filters['ets'] as $et)
                            <option value="{{ $et }}" @selected($filters['current_et'] === $et)>{{ $et }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-medium text-slate-500 mb-0.5">Stage</label>
                    <select name="stage" class="rounded-lg border-slate-300 text-xs py-1.5">
                        <option value="">Semua</option>
                        <option value="sebelum_scan" @selected($filters['current_stage'] === 'sebelum_scan')>Sebelum Scan</option>
                        @foreach($filters['stages'] as $key => $label)
                            <option value="{{ $key }}" @selected($filters['current_stage'] === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-medium text-slate-500 mb-0.5">Aging</label>
                    <select name="aging" class="rounded-lg border-slate-300 text-xs py-1.5">
                        <option value="">Semua</option>
                        @foreach($filters['aging'] as $key => $label)
                            <option value="{{ $key }}" @selected($filters['current_aging'] === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="bg-slate-600 hover:bg-slate-700 text-white text-xs font-bold py-1.5 px-4 rounded">Filter</button>
                <a href="{{ route('lost-wax.dashboard') }}" class="text-xs text-slate-500 hover:text-slate-700 py-1.5">Reset</a>
            </form>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            {{-- Section 2: Stage Distribution --}}
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h2 class="font-bold text-slate-800 mb-3">Distribusi Stage</h2>
                <div class="space-y-2">
                    @foreach($stageDistribution as $item)
                        <div class="flex items-center gap-3">
                            <div class="w-24 text-xs text-slate-600 text-right">{{ $item['label'] }}</div>
                            <div class="flex-1 bg-slate-100 rounded-full h-5 overflow-hidden">
                                <div class="h-full rounded-full transition-all
                                    {{ $item['key'] === 'oven' ? 'bg-emerald-500' : '' }}
                                    {{ $item['key'] === 'sebelum_scan' ? 'bg-blue-400' : '' }}
                                    {{ str_starts_with((string) $item['key'], 'layer_') ? 'bg-amber-400' : '' }}
                                    " style="width: {{ max($item['pct'], 3) }}%">
                                </div>
                            </div>
                            <div class="w-12 text-xs font-bold text-slate-700 text-right">{{ $item['count'] }}</div>
                            <div class="w-12 text-xs text-slate-400">{{ $item['pct'] }}%</div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Section 3: Aging Monitor --}}
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h2 class="font-bold text-slate-800 mb-3">Aging Monitor</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="text-left text-slate-500 border-b border-slate-200">
                                <th class="pb-2 pr-3">Stage</th>
                                <th class="pb-2 pr-3 text-center">Total</th>
                                <th class="pb-2 pr-3 text-center text-emerald-600">Normal</th>
                                <th class="pb-2 pr-3 text-center text-amber-600">Cepat</th>
                                <th class="pb-2 pr-3 text-center text-red-600">Lama</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $stageOrder = ['sebelum_scan', 'layer_1', 'layer_2', 'layer_3', 'layer_4', 'layer_5', 'layer_6', 'layer_7', 'oven'];
                            @endphp
                            @foreach($stageOrder as $sk)
                                @php $d = $agingByStage[$sk] ?? null; @endphp
                                @if($d && $d['total'] > 0)
                                    <tr class="border-b border-slate-100">
                                        <td class="py-1.5 pr-3 font-medium text-slate-700">{{ $d['label'] }}</td>
                                        <td class="py-1.5 pr-3 text-center font-bold">{{ $d['total'] }}</td>
                                        <td class="py-1.5 pr-3 text-center text-emerald-600">{{ $d['normal'] }}</td>
                                        <td class="py-1.5 pr-3 text-center text-amber-600">{{ $d['too_fast'] }}</td>
                                        <td class="py-1.5 pr-3 text-center text-red-600 font-bold">{{ $d['too_long'] > 0 ? $d['too_long'] : '-' }}</td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Section 4: Hot List --}}
        <div class="bg-white rounded-xl shadow-sm border border-red-200 p-5">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-bold text-slate-800">
                    <i class="fas fa-exclamation-triangle text-red-500 mr-2"></i>Perlu Perhatian
                </h2>
                <span class="text-xs text-slate-500">{{ count($hotList) }} tree</span>
            </div>

            @if(count($hotList) > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-slate-500 border-b border-slate-200">
                                <th class="pb-2 pr-3">Barcode</th>
                                <th class="pb-2 pr-3">Tree</th>
                                <th class="pb-2 pr-3">ET</th>
                                <th class="pb-2 pr-3">Produk</th>
                                <th class="pb-2 pr-3">Stage</th>
                                <th class="pb-2 pr-3">Qty</th>
                                <th class="pb-2 pr-3">Last Scan</th>
                                <th class="pb-2 pr-3">Aging</th>
                                <th class="pb-2 pr-3">Status</th>
                                <th class="pb-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($hotList as $item)
                                <tr class="border-b border-slate-100 hover:bg-slate-50
                                    {{ ($item['aging_status'] ?? '') === 'too_long' ? 'bg-red-50/30' : '' }}">
                                    <td class="py-2 pr-3 font-mono text-xs">{{ $item['barcode'] }}</td>
                                    <td class="py-2 pr-3">#{{ str_pad((string) $item['tree_number'], 3, '0', STR_PAD_LEFT) }}</td>
                                    <td class="py-2 pr-3">{{ $item['et_code'] }}</td>
                                    <td class="py-2 pr-3 text-xs max-w-[120px] truncate">{{ $item['item_code'] }}</td>
                                    <td class="py-2 pr-3">
                                        <span class="text-xs px-2 py-0.5 rounded-full {{ ($item['aging_status'] ?? '') === 'too_long' ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600' }}">
                                            {{ $item['current_stage_label'] }}
                                        </span>
                                    </td>
                                    <td class="py-2 pr-3">{{ number_format($item['quantity']) }}</td>
                                    <td class="py-2 pr-3 text-xs text-slate-500">{{ $item['last_scan_at']?->format('H:i d/m') ?? '-' }}</td>
                                    <td class="py-2 pr-3 text-xs">{{ $item['aging_label'] ?? '-' }}</td>
                                    <td class="py-2 pr-3">
                                        @if(($item['aging_status'] ?? '') === 'too_long')
                                            <span class="text-[10px] bg-red-100 text-red-700 px-2 py-0.5 rounded-full font-bold">TERLALU LAMA</span>
                                        @elseif(($item['aging_status'] ?? '') === 'too_fast')
                                            <span class="text-[10px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full">TERLALU CEPAT</span>
                                        @else
                                            <span class="text-[10px] bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full">NORMAL</span>
                                        @endif
                                        @if($item['has_anomaly'] ?? false)
                                            <span class="text-[10px] bg-orange-100 text-orange-700 px-2 py-0.5 rounded-full mt-1 block">ANOMALI</span>
                                        @endif
                                    </td>
                                    <td class="py-2 text-right">
                                        <a href="{{ route('lost-wax.trees.show', $item['tree']) }}" class="text-amber-600 hover:text-amber-700 text-xs">
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-sm text-slate-500 text-center py-6">Semua tree dalam kondisi normal.</div>
            @endif
        </div>

        {{-- Section 6: ET / Work Order Aggregate --}}
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <h2 class="font-bold text-slate-800 mb-3">Work Order Monitor</h2>

            @if(count($etAggregate) > 0)
                <div class="space-y-4">
                    @foreach($etAggregate as $etData)
                        <div class="rounded-lg border border-slate-200 p-4 hover:border-amber-300 transition-all">
                            <div class="flex items-center justify-between mb-3">
                                <div>
                                    <span class="font-bold text-slate-800 text-base">{{ $etData['et_code'] }}</span>
                                    @if($etData['has_anomaly'] ?? false)
                                        <span class="text-[10px] bg-orange-100 text-orange-700 px-2 py-0.5 rounded-full ml-2">ANOMALI</span>
                                    @endif
                                    @if($etData['has_aging_issue'] ?? false)
                                        <span class="text-[10px] bg-red-100 text-red-700 px-2 py-0.5 rounded-full ml-1">TOO LONG</span>
                                    @endif
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{ $etData['tree_count'] }} tree &middot; {{ number_format($etData['tree_quantity']) }} pcs
                                </div>
                            </div>

                            <div class="text-sm text-slate-600 mb-2">
                                {{ $etData['item_code'] }} &mdash; {{ $etData['item_name'] }}
                                <span class="text-xs text-slate-400 ml-2">PO: {{ $etData['po_number'] }}</span>
                            </div>

                            <div class="flex flex-wrap gap-1">
                                @php
                                    $stageOrder = ['sebelum_scan', 'layer_1', 'layer_2', 'layer_3', 'layer_4', 'layer_5', 'layer_6', 'layer_7', 'oven'];
                                @endphp
                                @foreach($stageOrder as $sk)
                                    @php $d = $etData['distribution'][$sk] ?? null; @endphp
                                    @if($d && $d['count'] > 0)
                                        <div class="text-[10px] px-2 py-1 rounded
                                            {{ $sk === 'oven' ? 'bg-emerald-100 text-emerald-700' : '' }}
                                            {{ $sk === 'sebelum_scan' ? 'bg-blue-100 text-blue-700' : '' }}
                                            {{ str_starts_with((string) $sk, 'layer_') ? 'bg-amber-100 text-amber-700' : '' }}">
                                            {{ $d['label'] }}: <strong>{{ $d['count'] }}</strong>
                                            <span class="text-slate-400">({{ number_format($d['qty']) }} pcs)</span>
                                        </div>
                                    @endif
                                @endforeach
                            </div>

                            @if(count($etData['distribution'] ?? []) === 0)
                                <div class="text-xs text-slate-400">Belum ada tree.</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-sm text-slate-500 text-center py-6">Tidak ada Work Order aktif.</div>
            @endif
        </div>

    </div>
@endsection
