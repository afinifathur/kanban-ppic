@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Riwayat Scan Tree</h1>
            <p class="text-gray-500 text-[10px]">{{ $tree->barcode }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('lost-wax.trees.show', $tree) }}" class="text-slate-500 hover:text-slate-700 text-xs">
                <i class="fas fa-arrow-left"></i> Detail Tree
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="space-y-6 max-w-3xl">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-center gap-4 mb-4">
                <div class="text-2xl font-mono font-bold text-slate-800">{{ $tree->barcode }}</div>
                <span class="text-xs bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full">{{ $tree->current_stage_label }}</span>
            </div>
            <div class="text-sm text-slate-600">
                <strong>{{ $tree->workOrder->et_code ?? '-' }}</strong>
                &mdash;
                {{ optional($tree->workOrder->itemReference)->item_code_snapshot ?? '-' }}
                &mdash;
                {{ optional($tree->workOrder->itemReference)->item_name_snapshot ?? '-' }}
            </div>
            <div class="text-xs text-slate-500 mt-2">
                Tree #{{ str_pad((string) $tree->tree_number, 3, '0', STR_PAD_LEFT) }}
                &middot;
                Qty {{ number_format($tree->quantity) }} pcs
                &middot;
                Last scan: {{ $tree->last_scan_at?->format('d M Y H:i:s') ?? '-' }}
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <h2 class="font-bold text-slate-800 mb-4">Timeline Scan</h2>

            @forelse($events as $event)
                <div class="relative pl-6 pb-6 border-l-2 {{ $event->result === 'success' ? 'border-emerald-400' : 'border-red-400' }} last:border-transparent last:pb-0">
                    <div class="absolute -left-[9px] top-0 w-4 h-4 rounded-full border-2 {{ $event->result === 'success' ? 'bg-emerald-100 border-emerald-400' : 'bg-red-100 border-red-400' }}"></div>

                    <div class="bg-slate-50 rounded-lg p-3 ml-2">
                        <div class="flex items-center justify-between">
                            <div class="font-semibold text-slate-800">
                                @if($event->result === 'success')
                                    <span class="text-emerald-600">{{ $event->stage_label }}</span>
                                @else
                                    <span class="text-red-600">DITOLAK</span>
                                @endif
                            </div>
                            <span class="text-xs text-slate-500">{{ $event->scanned_at->format('H:i:s') }}</span>
                        </div>

                        <div class="text-xs text-slate-500 mt-1">
                            {{ $event->scanned_at->format('d M Y') }}
                            &middot;
                            Operator: {{ optional($event->operator)->name ?? '-' }}
                            @if($event->result === 'success')
                                &middot;
                                <span class="
                                    {{ $event->aging_status === 'normal' ? 'text-emerald-600' : '' }}
                                    {{ $event->aging_status === 'too_fast' ? 'text-amber-600' : '' }}
                                    {{ $event->aging_status === 'too_long' ? 'text-red-600' : '' }}
                                ">
                                    {{ $event->aging_status ? strtoupper(str_replace('_', ' ', $event->aging_status)) : '-' }}
                                </span>
                                @if($event->aging_label)
                                    &middot; {{ $event->aging_label }}
                                @endif
                            @endif
                        </div>

                        @if($event->anomaly_reason)
                            <div class="text-xs text-red-600 mt-1 bg-red-50 p-2 rounded">
                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                {{ $event->anomaly_reason }}
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="text-sm text-slate-500 text-center py-8">Belum ada aktivitas scan.</div>
            @endforelse
        </div>
    </div>
@endsection
