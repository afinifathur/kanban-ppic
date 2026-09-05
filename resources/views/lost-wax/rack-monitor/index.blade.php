@extends('layouts.app')

@section('top_bar')
    <div class="flex flex-wrap items-center justify-between w-full gap-4">
        <div>
            <h1 class="text-xl font-bold text-slate-800 leading-tight">MONITORING RAK LAPISAN</h1>
            <p class="text-slate-500 text-xs">Mata ketiga SPV Coating: Urutan prioritas pengeluaran rak dari drying room</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-xs text-slate-500 font-medium">
                Update terakhir: <span id="last-updated-time" class="font-mono font-bold text-slate-800">{{ now()->format('H:i:s') }}</span>
            </span>
            <button onclick="window.location.reload();" class="flex items-center gap-2 bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold py-2 px-4 rounded-lg border border-slate-200 shadow-sm transition-all">
                <i class="fas fa-sync-alt text-slate-500"></i> Refresh
            </button>
        </div>
    </div>
@endsection

@section('content')
    <div class="space-y-6 max-w-[1600px] mx-auto text-slate-800">
        
        {{-- 1. UNASSIGNED TREES WARNING BANNER --}}
        @if(($summary['unassigned'] ?? 0) > 0)
            <div class="flex items-center justify-between bg-amber-50 border border-amber-200 text-amber-900 px-4 py-3 rounded-xl shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 w-8 h-8 rounded-lg flex items-center justify-center font-bold">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div>
                        <span class="font-bold text-sm">Peringatan: Terdapat Tree Belum Ditempatkan!</span>
                        <p class="text-xs text-amber-700 mt-0.5">Ada <strong>{{ $summary['unassigned'] }} tree</strong> aktif di sistem yang belum dimasukkan ke nomor rak fisik. Harap ingatkan operator untuk melakukan scan assignment rak.</p>
                    </div>
                </div>
                <a href="{{ route('lost-wax.trees.index') }}" class="text-xs bg-amber-600 hover:bg-amber-700 text-white font-bold py-1.5 px-3 rounded-lg transition-all">
                    Lihat Tree <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>
        @endif

        {{-- 2. SUMMARY BAR & FILTERS --}}
        <div class="space-y-4">
            {{-- Summary Cards (Double as quick-filters) --}}
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                
                <div onclick="setFilter('all')" class="cursor-pointer bg-white hover:border-slate-400 rounded-xl shadow-sm border border-slate-200 p-4 transition-all duration-200 filter-card" id="card-filter-all">
                    <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Total Rak Aktif</div>
                    <div class="text-3xl font-extrabold text-slate-900 mt-1">{{ $summary['total_active'] }}</div>
                    <div class="text-[10px] text-slate-400 mt-1">Berisi tree aktif</div>
                </div>

                <div onclick="setFilter('LATE')" class="cursor-pointer bg-white hover:border-red-400 rounded-xl shadow-sm border border-slate-200 border-l-4 border-l-red-500 p-4 transition-all duration-200 filter-card" id="card-filter-LATE">
                    <div class="text-[11px] font-bold text-red-600 uppercase tracking-wider flex items-center justify-between">
                        Terlambat
                        @if($summary['late'] > 0)
                            <span class="w-2 h-2 rounded-full bg-red-500 animate-ping"></span>
                        @endif
                    </div>
                    <div class="text-3xl font-extrabold text-red-600 mt-1">{{ $summary['late'] }}</div>
                    <div class="text-[10px] text-slate-400 mt-1">Melebihi batas toleransi</div>
                </div>

                <div onclick="setFilter('READY')" class="cursor-pointer bg-white hover:border-emerald-400 rounded-xl shadow-sm border border-slate-200 border-l-4 border-l-emerald-500 p-4 transition-all duration-200 filter-card" id="card-filter-READY">
                    <div class="text-[11px] font-bold text-emerald-600 uppercase tracking-wider">Siap Diproses</div>
                    <div class="text-3xl font-extrabold text-emerald-600 mt-1">{{ $summary['ready'] }}</div>
                    <div class="text-[10px] text-slate-400 mt-1">Dalam drying window</div>
                </div>

                <div onclick="setFilter('NEAR_READY')" class="cursor-pointer bg-white hover:border-indigo-400 rounded-xl shadow-sm border border-slate-200 border-l-4 border-l-indigo-500 p-4 transition-all duration-200 filter-card" id="card-filter-NEAR_READY">
                    <div class="text-[11px] font-bold text-indigo-600 uppercase tracking-wider">Akan Siap</div>
                    <div class="text-3xl font-extrabold text-indigo-600 mt-1">{{ $summary['near_ready'] }}</div>
                    <div class="text-[10px] text-slate-400 mt-1">Siap dalam <= 60m</div>
                </div>

                <div onclick="setFilter('NORMAL')" class="cursor-pointer bg-white hover:border-slate-400 rounded-xl shadow-sm border border-slate-200 border-l-4 border-l-slate-400 p-4 transition-all duration-200 filter-card" id="card-filter-NORMAL">
                    <div class="text-[11px] font-bold text-slate-600 uppercase tracking-wider">Proses Normal</div>
                    <div class="text-3xl font-extrabold text-slate-700 mt-1">{{ $summary['normal'] }}</div>
                    <div class="text-[10px] text-slate-400 mt-1">Sedang dikeringkan</div>
                </div>

                <div onclick="toggleSplitOnly()" class="cursor-pointer bg-white hover:border-purple-400 rounded-xl shadow-sm border border-slate-200 border-l-4 border-l-purple-500 p-4 transition-all duration-200" id="card-filter-split">
                    <div class="text-[11px] font-bold text-purple-600 uppercase tracking-wider">Rak L7 Split</div>
                    <div class="text-3xl font-extrabold text-purple-600 mt-1">{{ $summary['split'] }}</div>
                    <div class="text-[10px] text-slate-400 mt-1">Mengalami split stage</div>
                </div>

            </div>

            {{-- Compact Filter Badges --}}
            <div class="flex items-center justify-between bg-white rounded-xl shadow-sm border border-slate-200 px-4 py-2.5">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-bold text-slate-500 mr-2">Filter Tampilan:</span>
                    <button onclick="setFilter('all')" id="btn-filter-all" class="text-xs font-semibold px-3 py-1 rounded-lg bg-slate-800 text-white shadow-sm transition-all btn-filter">
                        Semua
                    </button>
                    <button onclick="setFilter('LATE')" id="btn-filter-LATE" class="text-xs font-semibold px-3 py-1 rounded-lg bg-slate-100 text-slate-600 hover:bg-slate-200 transition-all btn-filter">
                        Terlambat ({{ $summary['late'] }})
                    </button>
                    <button onclick="setFilter('READY')" id="btn-filter-READY" class="text-xs font-semibold px-3 py-1 rounded-lg bg-slate-100 text-slate-600 hover:bg-slate-200 transition-all btn-filter">
                        Siap ({{ $summary['ready'] }})
                    </button>
                    <button onclick="setFilter('NEAR_READY')" id="btn-filter-NEAR_READY" class="text-xs font-semibold px-3 py-1 rounded-lg bg-slate-100 text-slate-600 hover:bg-slate-200 transition-all btn-filter">
                        Akan Siap ({{ $summary['near_ready'] }})
                    </button>
                    <button onclick="setFilter('NORMAL')" id="btn-filter-NORMAL" class="text-xs font-semibold px-3 py-1 rounded-lg bg-slate-100 text-slate-600 hover:bg-slate-200 transition-all btn-filter">
                        Normal ({{ $summary['normal'] }})
                    </button>
                </div>
                
                <div class="text-xs text-slate-500 font-medium">
                    Menampilkan <span id="visible-count" class="font-bold text-slate-800">{{ $summary['total_active'] }}</span> dari {{ $summary['total_active'] }} rak
                </div>
            </div>
        </div>

        {{-- 3. MAIN DASHBOARD CONTENT AREA --}}
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            
            {{-- LEFT SIDE: PRIORITY QUEUE (3/4 Width) --}}
            <div class="lg:col-span-3 space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-bold text-slate-500 uppercase tracking-wider">Antrean Prioritas Rak (Priority Queue)</h2>
                    <span class="text-xs text-slate-400">Urutan: Terlambat &rarr; Siap &rarr; Akan Siap &rarr; Normal</span>
                </div>

                {{-- The Cards Container --}}
                <div class="space-y-3" id="racks-container">
                    @forelse($racks as $rack)
                        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 flex flex-col md:flex-row md:items-center justify-between gap-4 transition-all duration-200 hover:shadow-md hover:border-slate-300 rack-card" 
                             data-state="{{ $rack['presentation_state'] }}" 
                             data-split="{{ $rack['is_layer7_split'] ? '1' : '0' }}">
                            
                            {{-- Info Utama & Identitas Rak --}}
                            <div class="flex items-start gap-4">
                                <div class="flex-shrink-0 w-16 h-16 rounded-xl flex flex-col items-center justify-center font-bold border shadow-sm
                                    {{ $rack['presentation_state'] === 'LATE' ? 'bg-red-50 border-red-200 text-red-700' : '' }}
                                    {{ $rack['presentation_state'] === 'READY' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : '' }}
                                    {{ $rack['presentation_state'] === 'NEAR_READY' ? 'bg-indigo-50 border-indigo-200 text-indigo-700' : '' }}
                                    {{ $rack['presentation_state'] === 'NORMAL' ? 'bg-slate-50 border-slate-200 text-slate-700' : '' }}
                                ">
                                    <span class="text-[9px] uppercase tracking-wide opacity-75">RAK</span>
                                    <span class="text-xl -mt-1 font-extrabold">{{ str_pad((string) $rack['rack_number'], 2, '0', STR_PAD_LEFT) }}</span>
                                </div>

                                <div class="space-y-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-bold text-slate-900 text-base">{{ $rack['rack_label'] }}</span>
                                        
                                        {{-- Badges Status --}}
                                        @if($rack['presentation_state'] === 'LATE')
                                            <span class="text-[10px] bg-red-100 text-red-800 px-2.5 py-0.5 rounded-full font-bold border border-red-200 flex items-center gap-1">
                                                <i class="fas fa-exclamation-circle text-red-500"></i> TERLAMBAT
                                            </span>
                                        @elseif($rack['presentation_state'] === 'READY')
                                            <span class="text-[10px] bg-emerald-100 text-emerald-800 px-2.5 py-0.5 rounded-full font-bold border border-emerald-200 flex items-center gap-1">
                                                <i class="fas fa-check-circle text-emerald-600"></i> SIAP DIPROSES
                                            </span>
                                        @elseif($rack['presentation_state'] === 'NEAR_READY')
                                            <span class="text-[10px] bg-indigo-100 text-indigo-800 px-2.5 py-0.5 rounded-full font-bold border border-indigo-200 flex items-center gap-1">
                                                <i class="fas fa-clock text-indigo-500"></i> AKAN SIAP
                                            </span>
                                        @else
                                            <span class="text-[10px] bg-slate-100 text-slate-700 px-2.5 py-0.5 rounded-full font-semibold border border-slate-200">
                                                PENGERINGAN
                                            </span>
                                        @endif

                                        {{-- Special Badges --}}
                                        @if($rack['is_layer7_split'])
                                            <span class="text-[10px] bg-purple-100 text-purple-800 px-2.5 py-0.5 rounded-full font-bold border border-purple-200">
                                                L7 SPLIT
                                            </span>
                                        @endif
                                        @if($rack['is_mixed'] && !$rack['is_layer7_split'])
                                            <span class="text-[10px] bg-amber-100 text-amber-800 px-2.5 py-0.5 rounded-full font-bold border border-amber-200">
                                                MIXED
                                            </span>
                                        @endif
                                    </div>
                                    
                                    {{-- Tahapan & Qty Info --}}
                                    <div class="text-slate-600 text-sm flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="font-bold text-slate-800 bg-slate-100 px-1.5 py-0.5 rounded text-xs border border-slate-200">
                                            {{ $rack['dominant_stage'] ? ucfirst(str_replace('_', ' ', $rack['dominant_stage'])) : 'Sebelum Scan' }}
                                        </span>
                                        <span>&middot;</span>
                                        <span class="font-semibold text-slate-700">{{ $rack['tree_count'] }} Tree</span>
                                        <span>&middot;</span>
                                        <span class="text-slate-500">{{ number_format($rack['total_quantity']) }} pcs</span>
                                    </div>

                                    {{-- Items / Products on Rack --}}
                                    @if(!empty($rack['item_names']))
                                        <div class="text-xs text-slate-700 font-medium flex flex-wrap items-center gap-1.5 mt-1.5">
                                            @foreach($rack['item_names'] as $itemName => $treeCount)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-slate-100 border border-slate-200 text-slate-800 font-semibold text-xs truncate max-w-[280px]" title="{{ $itemName }}">
                                                    <i class="fas fa-box text-slate-400 mr-1 text-[10px]"></i>
                                                    {{ $itemName }}
                                                    @if(count($rack['item_names']) > 1)
                                                        <span class="ml-1 text-[10px] text-slate-500 font-normal">({{ $treeCount }} Tree)</span>
                                                    @endif
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>

                            {{-- Aging Stats Section --}}
                            <div class="flex items-center justify-between md:justify-end gap-6 border-t md:border-t-0 pt-3 md:pt-0 border-slate-100">
                                <div class="text-left md:text-right space-y-0.5">
                                    @if($rack['rack_age_minutes'] !== null)
                                        @php
                                            $h = floor($rack['rack_age_minutes'] / 60);
                                            $m = $rack['rack_age_minutes'] % 60;
                                            $ageLabel = $h > 0 ? "{$h}j {$m}m" : "{$m}m";
                                        @endphp
                                        <div class="text-xs text-slate-500 font-medium">Umur: <strong class="text-slate-800">{{ $ageLabel }}</strong></div>
                                        
                                        <div class="text-sm font-bold">
                                            @if($rack['presentation_state'] === 'LATE')
                                                @php
                                                    $oh = floor($rack['overdue_minutes'] / 60);
                                                    $om = $rack['overdue_minutes'] % 60;
                                                    $overdueLabel = $oh > 0 ? "{$oh}j {$om}m" : "{$om}m";
                                                @endphp
                                                <span class="text-red-600">TERLAMBAT {{ $overdueLabel }}</span>
                                            @elseif($rack['presentation_state'] === 'READY')
                                                <span class="text-emerald-600">SIAP DIPROSES</span>
                                            @elseif($rack['presentation_state'] === 'NEAR_READY')
                                                <span class="text-indigo-600">Siap dalam: {{ $rack['remaining_minutes'] }}m</span>
                                            @else
                                                @php
                                                    $remMin = ($rack['min_hours'] * 60) - $rack['rack_age_minutes'];
                                                    $rh = floor($remMin / 60);
                                                    $rm = $remMin % 60;
                                                    $remLabel = $rh > 0 ? "{$rh}j {$rm}m" : "{$rm}m";
                                                @endphp
                                                <span class="text-slate-500 font-medium text-xs">Menuju siap: {{ $remLabel }}</span>
                                            @endif
                                        </div>
                                    @else
                                        <div class="text-xs text-slate-400">Belum mulai aging</div>
                                    @endif
                                </div>

                                {{-- Action Button --}}
                                <button onclick="openRackDetailModal({{ json_encode($rack) }})" class="bg-slate-700 hover:bg-slate-800 text-white text-xs font-bold py-2 px-4 rounded-lg shadow-sm transition-all">
                                    Lihat Detail
                                </button>
                            </div>

                        </div>
                    @empty
                        <div class="bg-white rounded-xl shadow-sm border border-slate-200 py-12 text-center text-slate-500">
                            <i class="fas fa-check-circle text-emerald-500 text-4xl mb-3"></i>
                            <p class="font-bold text-slate-700 text-base">✓ Tidak ada rack yang aktif.</p>
                            <p class="text-xs text-slate-400 mt-1">Harap pastikan tree sudah ditempatkan ke rack pada menu assignment.</p>
                        </div>
                    @endforelse

                    {{-- Empty States for Filters --}}
                    <div id="filter-empty-state" class="hidden bg-white rounded-xl shadow-sm border border-slate-200 py-12 text-center text-slate-500">
                        <i class="fas fa-info-circle text-slate-400 text-4xl mb-3"></i>
                        <p class="font-bold text-slate-700 text-base" id="empty-state-title">Tidak ada data.</p>
                        <p class="text-xs text-slate-400 mt-1">Cobalah memfilter dengan kriteria status lainnya.</p>
                    </div>
                </div>
            </div>

            {{-- RIGHT SIDE: METRICS & DISTRIBUTION (1/4 Width) --}}
            <div class="space-y-6">
                
                {{-- Compact Stage Distribution Section --}}
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                    <h3 class="font-bold text-xs text-slate-500 uppercase tracking-wider mb-3">Distribusi Rak Aktif</h3>
                    <div class="space-y-2">
                        @php
                            $totalRacks = count($racks);
                        @endphp
                        @foreach($stageReport as $key => $report)
                            @if($report['rack_count'] > 0)
                                @php
                                    $pct = $totalRacks > 0 ? round(($report['rack_count'] / $totalRacks) * 100) : 0;
                                @endphp
                                <div class="space-y-1">
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="font-medium text-slate-700">{{ $report['label'] }}</span>
                                        <span class="font-bold text-slate-800">{{ $report['rack_count'] }} rak <span class="text-slate-400 font-normal">({{ $pct }}%)</span></span>
                                    </div>
                                    <div class="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden flex">
                                        <div class="h-full bg-slate-400 transition-all rounded-full" style="width: {{ $pct }}%"></div>
                                    </div>
                                </div>
                            @endif
                        @endforeach
                        
                        @if($totalRacks === 0)
                            <div class="text-xs text-slate-400 text-center py-3">Tidak ada rak aktif</div>
                        @endif
                    </div>
                </div>

                {{-- Aging Monitor Table Section --}}
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                    <h3 class="font-bold text-xs text-slate-500 uppercase tracking-wider mb-3">Aging Monitor</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="text-left text-slate-400 border-b border-slate-200">
                                    <th class="pb-1.5 font-bold">Stage</th>
                                    <th class="pb-1.5 text-center font-bold">Rak</th>
                                    <th class="pb-1.5 text-center text-slate-600 font-bold">Normal</th>
                                    <th class="pb-1.5 text-center text-emerald-600 font-bold">Siap</th>
                                    <th class="pb-1.5 text-center text-red-600 font-bold">Late</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($stageReport as $key => $report)
                                    @if($report['rack_count'] > 0)
                                        <tr class="border-b border-slate-100 hover:bg-slate-50">
                                            <td class="py-2 font-medium text-slate-700">{{ $report['label'] }}</td>
                                            <td class="py-2 text-center font-bold text-slate-800">{{ $report['rack_count'] }}</td>
                                            <td class="py-2 text-center text-slate-600">{{ $report['normal'] > 0 ? $report['normal'] : '-' }}</td>
                                            <td class="py-2 text-center text-emerald-600 font-bold">{{ $report['ready'] > 0 ? $report['ready'] : '-' }}</td>
                                            <td class="py-2 text-center text-red-600 font-bold">{{ $report['late'] > 0 ? $report['late'] : '-' }}</td>
                                        </tr>
                                    @endif
                                @endforeach

                                @if($totalRacks === 0)
                                    <tr>
                                        <td colspan="5" class="py-4 text-center text-slate-400">Tidak ada data rak</td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

        </div>

    </div>

    {{-- 4. DETAILED MODAL DIALOG (REUSABLE / JS-DRIVEN) --}}
    <div id="rack-detail-modal" class="fixed inset-0 z-50 overflow-y-auto hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            
            {{-- Backdrop --}}
            <div onclick="closeRackDetailModal()" class="fixed inset-0 bg-slate-900 bg-opacity-50 transition-opacity" aria-hidden="true"></div>

            {{-- Center alignment helper --}}
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            {{-- Modal Content Box --}}
            <div class="inline-block align-bottom bg-white rounded-xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full border border-slate-200">
                
                {{-- Header --}}
                <div class="bg-slate-50 px-6 py-4 border-b border-slate-200 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div id="modal-rack-badge" class="font-extrabold text-sm px-2.5 py-1 rounded-lg border border-slate-300"></div>
                        <h3 class="text-base font-bold text-slate-800" id="modal-rack-title">Detail Rak</h3>
                    </div>
                    <button onclick="closeRackDetailModal()" class="text-slate-400 hover:text-slate-600 transition-all">
                        <i class="fas fa-times text-lg"></i>
                    </button>
                </div>

                {{-- Body --}}
                <div class="p-6 space-y-5 text-sm">
                    
                    {{-- Section A: Summary Row --}}
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 p-4 bg-slate-50 border border-slate-200 rounded-xl">
                        <div>
                            <span class="text-[11px] font-bold text-slate-400 uppercase">Status</span>
                            <div id="modal-status-badge" class="font-bold text-sm mt-0.5"></div>
                        </div>
                        <div>
                            <span class="text-[11px] font-bold text-slate-400 uppercase">Stage Dominan</span>
                            <div id="modal-dominant-stage" class="font-bold text-slate-800 text-sm mt-0.5"></div>
                        </div>
                        <div>
                            <span class="text-[11px] font-bold text-slate-400 uppercase">Jumlah Tree</span>
                            <div id="modal-tree-count" class="font-bold text-slate-800 text-sm mt-0.5"></div>
                        </div>
                        <div>
                            <span class="text-[11px] font-bold text-slate-400 uppercase">Total Qty Pcs</span>
                            <div id="modal-total-qty" class="font-bold text-slate-800 text-sm mt-0.5"></div>
                        </div>
                    </div>

                    {{-- Section B: Aging & Milestones --}}
                    <div class="space-y-2 border-b border-slate-100 pb-4">
                        <h4 class="font-bold text-xs text-slate-500 uppercase tracking-wider">Metrik Drying (Pengeringan)</h4>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                            <div class="space-y-1">
                                <div class="flex justify-between">
                                    <span class="text-slate-500">Mulai Stage:</span>
                                    <span id="modal-time-start" class="font-mono font-bold text-slate-800"></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-500">Umur Saat Ini:</span>
                                    <span id="modal-time-age" class="font-bold text-slate-800"></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-500">Target Min Stage:</span>
                                    <span id="modal-target-min" class="font-bold text-slate-800"></span>
                                </div>
                            </div>
                            <div class="space-y-1">
                                <div class="flex justify-between">
                                    <span class="text-slate-500">Matang Sejak:</span>
                                    <span id="modal-time-ready" class="font-mono font-bold text-slate-800"></span>
                                </div>
                                <div class="flex justify-between" id="modal-remaining-row">
                                    <span id="modal-remaining-label" class="text-slate-500">Sisa Waktu:</span>
                                    <span id="modal-time-remaining" class="font-bold"></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-500">Batas Toleransi (Buffer):</span>
                                    <span id="modal-target-buffer" class="font-bold text-slate-800"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Section C: Stage Distribution --}}
                    <div class="space-y-2 border-b border-slate-100 pb-4">
                        <h4 class="font-bold text-xs text-slate-500 uppercase tracking-wider">Distribusi Tree Per Stage</h4>
                        <div class="flex flex-wrap gap-1.5" id="modal-stage-distribution">
                            {{-- Populated by JS --}}
                        </div>
                    </div>

                    {{-- Section D: Item Names & Production Codes --}}
                    <div class="space-y-2 border-b border-slate-100 pb-4">
                        <h4 class="font-bold text-xs text-slate-500 uppercase tracking-wider">Ringkasan Item & Kode Produksi</h4>
                        <div class="flex flex-wrap gap-2" id="modal-item-names">
                            {{-- Populated by JS --}}
                        </div>
                    </div>

                    {{-- Section E: Trees List --}}
                    <div class="space-y-2">
                        <h4 class="font-bold text-xs text-slate-500 uppercase tracking-wider">Daftar Barcode Tree di Rak</h4>
                        <div class="overflow-y-auto max-h-[220px] border border-slate-200 rounded-lg">
                            <table class="w-full text-xs text-left">
                                <thead class="bg-slate-50 text-slate-500 border-b border-slate-200">
                                    <tr>
                                        <th class="p-2">Barcode</th>
                                        <th class="p-2">Nama Item</th>
                                        <th class="p-2">Kode Produksi</th>
                                        <th class="p-2 text-center">Qty Pcs</th>
                                        <th class="p-2">Stage Saat Ini</th>
                                        <th class="p-2">Scan Terakhir</th>
                                    </tr>
                                </thead>
                                <tbody id="modal-trees-table-body">
                                    {{-- Populated by JS --}}
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>

                {{-- Footer --}}
                <div class="bg-slate-50 px-6 py-3 border-t border-slate-200 flex justify-end">
                    <button onclick="closeRackDetailModal()" class="bg-white border border-slate-300 text-slate-700 text-xs font-semibold py-2 px-4 rounded-lg shadow-sm hover:bg-slate-50 transition-all">
                        Tutup
                    </button>
                </div>

            </div>
        </div>
    </div>

    {{-- Client-side Filter & Interactive JS --}}
    <script>
        let currentFilter = 'all';
        let splitOnly = false;

        // Auto Refresh every 60 seconds
        setInterval(function() {
            window.location.reload();
        }, 60000);

        function setFilter(state) {
            currentFilter = state;
            splitOnly = false; // Reset split filter when changing state filter
            applyFilters();

            // Update UI filter button states
            document.querySelectorAll('.btn-filter').forEach(btn => {
                btn.classList.remove('bg-slate-800', 'text-white', 'shadow-sm');
                btn.classList.add('bg-slate-100', 'text-slate-600');
            });
            const activeBtn = document.getElementById('btn-filter-' + state);
            if (activeBtn) {
                activeBtn.classList.remove('bg-slate-100', 'text-slate-600');
                activeBtn.classList.add('bg-slate-800', 'text-white', 'shadow-sm');
            }

            // Update UI card highlight state
            document.querySelectorAll('.filter-card').forEach(card => {
                card.classList.remove('ring-2', 'ring-slate-800', 'ring-offset-2');
            });
            const activeCard = document.getElementById('card-filter-' + state);
            if (activeCard) {
                activeCard.classList.add('ring-2', 'ring-slate-800', 'ring-offset-2');
            }
        }

        function toggleSplitOnly() {
            splitOnly = !splitOnly;
            
            // Clear standard filter if we are enabling splitOnly
            if (splitOnly) {
                currentFilter = 'all';
                document.querySelectorAll('.btn-filter').forEach(btn => {
                    btn.classList.remove('bg-slate-800', 'text-white', 'shadow-sm');
                    btn.classList.add('bg-slate-100', 'text-slate-600');
                });
                document.querySelectorAll('.filter-card').forEach(card => {
                    card.classList.remove('ring-2', 'ring-slate-800', 'ring-offset-2');
                });
                document.getElementById('card-filter-split').classList.add('ring-2', 'ring-purple-600', 'ring-offset-2');
            } else {
                document.getElementById('card-filter-split').classList.remove('ring-2', 'ring-purple-600', 'ring-offset-2');
            }

            applyFilters();
        }

        function applyFilters() {
            const cards = document.querySelectorAll('.rack-card');
            let visibleCount = 0;

            cards.forEach(card => {
                const cardState = card.getAttribute('data-state');
                const cardSplit = card.getAttribute('data-split') === '1';

                let matchesState = (currentFilter === 'all' || cardState === currentFilter);
                let matchesSplit = (!splitOnly || cardSplit);

                if (matchesState && matchesSplit) {
                    card.style.display = 'flex';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            // Update visible counter
            document.getElementById('visible-count').textContent = visibleCount;

            // Handle empty states
            const emptyState = document.getElementById('filter-empty-state');
            if (visibleCount === 0) {
                emptyState.classList.remove('hidden');
                
                let titleText = 'Tidak ada rak dengan kriteria tersebut.';
                if (currentFilter === 'LATE') {
                    titleText = '✓ Tidak ada rack yang terlambat. Semua proses berada dalam batas waktu.';
                } else if (currentFilter === 'READY') {
                    titleText = 'Belum ada rack yang siap diproses.';
                }
                document.getElementById('empty-state-title').textContent = titleText;
            } else {
                emptyState.classList.add('hidden');
            }
        }

        // Modal Logic
        function openRackDetailModal(rack) {
            // 1. Identify dominant stage config name
            let stageLabel = rack.dominant_stage ? rack.dominant_stage.replace('_', ' ').toUpperCase() : 'SEBELUM SCAN';
            
            // 2. Set title and badge
            document.getElementById('modal-rack-title').textContent = 'Detail ' + rack.rack_label;
            
            const badge = document.getElementById('modal-rack-badge');
            badge.textContent = 'RAK ' + String(rack.rack_number).padStart(2, '0');
            badge.className = 'font-extrabold text-sm px-2.5 py-1 rounded-lg border ';
            
            // 3. Set status badge in header & body summary
            const status = document.getElementById('modal-status-badge');
            if (rack.presentation_state === 'LATE') {
                badge.className += 'bg-red-50 border-red-200 text-red-700';
                status.innerHTML = '<span class="text-red-700 bg-red-100 border border-red-200 px-2 py-0.5 rounded text-xs font-bold"><i class="fas fa-exclamation-circle mr-1"></i>TERLAMBAT</span>';
            } else if (rack.presentation_state === 'READY') {
                badge.className += 'bg-emerald-50 border-emerald-200 text-emerald-700';
                status.innerHTML = '<span class="text-emerald-700 bg-emerald-100 border border-emerald-200 px-2 py-0.5 rounded text-xs font-bold"><i class="fas fa-check-circle mr-1"></i>SIAP DIPROSES</span>';
            } else if (rack.presentation_state === 'NEAR_READY') {
                badge.className += 'bg-indigo-50 border-indigo-200 text-indigo-700';
                status.innerHTML = '<span class="text-indigo-700 bg-indigo-100 border border-indigo-200 px-2 py-0.5 rounded text-xs font-bold"><i class="fas fa-clock mr-1"></i>AKAN SIAP</span>';
            } else {
                badge.className += 'bg-slate-50 border-slate-200 text-slate-700';
                status.innerHTML = '<span class="text-slate-700 bg-slate-100 border border-slate-200 px-2 py-0.5 rounded text-xs font-bold">NORMAL</span>';
            }

            document.getElementById('modal-dominant-stage').textContent = stageLabel;
            document.getElementById('modal-tree-count').textContent = rack.tree_count + ' Tree';
            document.getElementById('modal-total-qty').textContent = Number(rack.total_quantity).toLocaleString() + ' PCS';

            // 4. Fill Metrik Drying (dates & aging)
            if (rack.rack_stage_started_at) {
                const dateStart = new Date(rack.rack_stage_started_at);
                document.getElementById('modal-time-start').textContent = formatDateTime(dateStart);

                // calculate age
                const ageH = Math.floor(rack.rack_age_minutes / 60);
                const ageM = rack.rack_age_minutes % 60;
                document.getElementById('modal-time-age').textContent = ageH > 0 ? `${ageH}j ${ageM}m` : `${ageM}m`;

                // thresholds
                document.getElementById('modal-target-min').textContent = rack.min_hours + ' jam';
                document.getElementById('modal-target-buffer').textContent = rack.buffer_hours + ' jam';

                // ready date (start + min_hours)
                const dateReady = new Date(dateStart.getTime() + (rack.min_hours * 60 * 60 * 1000));
                document.getElementById('modal-time-ready').textContent = formatDateTime(dateReady);

                // remaining / overdue info
                const remRow = document.getElementById('modal-remaining-row');
                const remLabel = document.getElementById('modal-remaining-label');
                const remVal = document.getElementById('modal-time-remaining');
                
                remRow.classList.remove('hidden');
                if (rack.presentation_state === 'LATE') {
                    remLabel.textContent = 'Durasi Overdue:';
                    const oh = Math.floor(rack.overdue_minutes / 60);
                    const om = rack.overdue_minutes % 60;
                    remVal.textContent = oh > 0 ? `${oh}j ${om}m` : `${om}m`;
                    remVal.className = 'font-bold text-red-600';
                } else if (rack.presentation_state === 'READY') {
                    remLabel.textContent = 'Lama Siap:';
                    const activeAge = rack.rack_age_minutes;
                    const minAge = rack.min_hours * 60;
                    const readyForMin = activeAge - minAge;
                    const rh = Math.floor(readyForMin / 60);
                    const rm = Math.floor(readyForMin % 60);
                    remVal.textContent = `Sudah matang ${rh > 0 ? rh + 'j ' : ''}${rm}m yang lalu`;
                    remVal.className = 'font-bold text-emerald-600';
                } else if (rack.presentation_state === 'NEAR_READY') {
                    remLabel.textContent = 'Siap Dalam:';
                    remVal.textContent = rack.remaining_minutes + ' menit';
                    remVal.className = 'font-bold text-indigo-600';
                } else {
                    remLabel.textContent = 'Siap Dalam:';
                    const remMin = Math.round((rack.min_hours * 60) - rack.rack_age_minutes);
                    const rh = Math.floor(remMin / 60);
                    const rm = remMin % 60;
                    remVal.textContent = rh > 0 ? `${rh}j ${rm}m` : `${rm}m`;
                    remVal.className = 'font-bold text-slate-700';
                }
            } else {
                document.getElementById('modal-time-start').textContent = '-';
                document.getElementById('modal-time-age').textContent = '-';
                document.getElementById('modal-target-min').textContent = '-';
                document.getElementById('modal-target-buffer').textContent = '-';
                document.getElementById('modal-time-ready').textContent = '-';
                document.getElementById('modal-remaining-row').classList.add('hidden');
            }

            // 5. Populate Stage Distribution
            const stageDistDiv = document.getElementById('modal-stage-distribution');
            stageDistDiv.innerHTML = '';
            for (let [stageKey, count] of Object.entries(rack.stage_distribution)) {
                if (count > 0) {
                    const label = stageKey.replace('_', ' ').toUpperCase();
                    const badgeClass = stageKey === 'oven' ? 'bg-emerald-100 text-emerald-800 border-emerald-200' :
                                      (stageKey === 'sebelum_scan' ? 'bg-blue-100 text-blue-800 border-blue-200' :
                                      'bg-amber-100 text-amber-800 border-amber-200');
                    stageDistDiv.innerHTML += `
                        <div class="px-2.5 py-1 rounded-lg text-xs font-semibold border ${badgeClass}">
                            ${label}: <strong class="text-slate-900">${count} Tree</strong>
                        </div>
                    `;
                }
            }

            // 6. Populate Item Names and Production Codes
            const itemNamesDiv = document.getElementById('modal-item-names');
            itemNamesDiv.innerHTML = '';
            if (rack.item_names && Object.keys(rack.item_names).length > 0) {
                for (let [itemName, count] of Object.entries(rack.item_names)) {
                    itemNamesDiv.innerHTML += `
                        <div class="px-3 py-1.5 rounded-lg text-xs bg-slate-50 border border-slate-200 flex items-center gap-2">
                            <span class="font-bold text-slate-800">${itemName}</span>
                            <span class="text-[11px] text-slate-500 bg-white px-2 py-0.5 rounded border border-slate-200 font-semibold">${count} Tree</span>
                        </div>
                    `;
                }
            } else if (rack.production_codes && Object.keys(rack.production_codes).length > 0) {
                for (let [code, count] of Object.entries(rack.production_codes)) {
                    itemNamesDiv.innerHTML += `
                        <div class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-100 text-slate-800 border border-slate-200">
                            ${code}: <strong class="text-slate-900">${count} Tree</strong>
                        </div>
                    `;
                }
            }

            // 7. Populate Trees List
            const tbody = document.getElementById('modal-trees-table-body');
            tbody.innerHTML = '';
            
            rack.trees.forEach(tree => {
                let dateStr = '-';
                if (tree.last_scan_at) {
                    const d = new Date(tree.last_scan_at);
                    dateStr = formatDateTime(d);
                }

                tbody.innerHTML += `
                    <tr class="border-b border-slate-100 hover:bg-slate-50">
                        <td class="p-2 font-mono text-xs font-bold text-slate-700 whitespace-nowrap">${tree.barcode || tree.human_barcode}</td>
                        <td class="p-2 font-semibold text-slate-800">${tree.item_name || '-'}</td>
                        <td class="p-2 font-medium text-slate-500 text-xs whitespace-nowrap">${tree.production_code}</td>
                        <td class="p-2 text-center font-bold text-slate-800">${tree.quantity}</td>
                        <td class="p-2">
                            <span class="px-2 py-0.5 text-[10px] rounded-full font-semibold bg-slate-100 text-slate-700 whitespace-nowrap">
                                ${tree.current_stage_label}
                            </span>
                        </td>
                        <td class="p-2 text-slate-500 font-mono text-[11px] whitespace-nowrap">${dateStr}</td>
                    </tr>
                `;
            });

            // 8. Show Modal
            document.getElementById('rack-detail-modal').classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }

        function closeRackDetailModal() {
            document.getElementById('rack-detail-modal').classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }

        // Helper to format Date
        function formatDateTime(date) {
            const h = String(date.getHours()).padStart(2, '0');
            const m = String(date.getMinutes()).padStart(2, '0');
            const d = String(date.getDate()).padStart(2, '0');
            const mo = String(date.getMonth() + 1).padStart(2, '0');
            return `${h}:${m} (${d}/${mo})`;
        }
    </script>
@endsection
