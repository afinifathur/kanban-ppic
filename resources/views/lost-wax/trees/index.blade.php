@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Tree / Traveler</h1>
            <p class="text-gray-500 text-[10px]">Daftar Tree Lost Wax, barcode &amp; traveler</p>
        </div>
    </div>
@endsection

@section('content')
    <div class="space-y-4">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-medium text-slate-700 mb-1">Filter ET</label>
                <form method="GET" class="flex items-end gap-2">
                    <input type="text" name="et_filter" value="{{ request('et_filter') }}" placeholder="ET26-0232" class="rounded-lg border-slate-300 text-sm w-40">
                    <button type="submit" class="bg-slate-700 hover:bg-slate-800 text-white text-xs font-bold py-1.5 px-3 rounded">Filter</button>
                    @if(request('et_filter'))
                        <a href="{{ route('lost-wax.trees.index') }}" class="text-xs text-slate-500 hover:text-slate-700 py-1.5">Clear</a>
                    @endif
                </form>
            </div>
        </div>

        @forelse($trees as $tree)
            <a href="{{ route('lost-wax.trees.show', $tree) }}"
                class="block bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow-md hover:border-amber-300 transition-all group">
                <div class="flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 mb-1 flex-wrap">
                            <span class="text-slate-800 font-bold text-base group-hover:text-amber-600 transition-colors">{{ $tree->barcode }}</span>
                            <span class="text-[10px] bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full">{{ $tree->status }}</span>
                            @if($tree->current_stage)
                                <span class="text-[10px] px-2 py-0.5 rounded-full
                                    {{ $tree->current_stage === 'oven' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
                                    {{ $tree->current_stage_label }}
                                </span>
                            @else
                                <span class="text-[10px] bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">Sebelum Scan</span>
                            @endif
                        </div>
                        <div class="text-sm text-slate-500">
                            Tree #{{ str_pad((string) $tree->tree_number, 3, '0', STR_PAD_LEFT) }} &middot;
                            Qty {{ number_format($tree->quantity) }} pcs
                        </div>
                        <div class="text-xs text-slate-400 mt-1 flex flex-wrap gap-3">
                            <span>ET: {{ $tree->workOrder->et_code ?? '-' }}</span>
                            <span>{{ optional($tree->plan)->wave_number ? 'Wave '.str_pad((string) $tree->plan->wave_number, 3, '0', STR_PAD_LEFT) : '-' }}</span>
                            <span>Tgl: {{ $tree->production_date->format('d-m-Y') }}</span>
                            <span>Item: {{ optional($tree->workOrder->itemReference)->item_code_snapshot ?? '-' }}</span>
                            @if($tree->last_scan_at)
                                <span class="text-slate-400">Last: {{ $tree->last_scan_at->format('H:i d/m') }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('lost-wax.trees.traveler', $tree) }}" target="_blank"
                            class="bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold py-1 px-2 rounded"
                            onclick="event.stopPropagation()" title="Print Traveler">
                            <i class="fas fa-print"></i>
                        </a>
                        <div class="text-slate-300 group-hover:text-amber-500 transition-colors">
                            <i class="fas fa-chevron-right fa-lg"></i>
                        </div>
                    </div>
                </div>
            </a>
        @empty
            <div class="bg-white rounded-xl shadow-sm border border-dashed border-slate-300 p-12 text-center">
                <div class="bg-slate-50 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-sitemap text-slate-400 text-2xl"></i>
                </div>
                <h3 class="text-slate-600 font-bold">Belum ada Tree</h3>
                <p class="text-slate-400 text-sm mt-1">Generate Tree dari Work Order Plan untuk memulai.</p>
            </div>
        @endforelse

        <div>{{ $trees->links() }}</div>
    </div>
@endsection
