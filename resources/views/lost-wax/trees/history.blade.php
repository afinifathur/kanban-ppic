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
    @php
        $latestSuccessEvent = $events->where('result', 'success')->reject(function ($e) {
            return $e->void !== null;
        })->sortByDesc('scanned_at')->first();

        $canVoid = auth()->user()->hasRole('ppic') || auth()->user()->hasRole('admin');
    @endphp

    <div class="space-y-6 max-w-3xl">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-center gap-4 mb-4">
                <div class="text-2xl font-mono font-bold text-slate-800">{{ $tree->barcode }}</div>
                <span class="text-xs bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full">{{ $tree->current_stage_label }}</span>
            </div>
            <div class="text-sm text-slate-600">
                <strong>{{ $tree->getSourceCode() ?? '-' }}</strong>
                &mdash;
                {{ $tree->getSourceItemCode() ?? '-' }}
                &mdash;
                {{ $tree->getSourceProduct() ?? '-' }}
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

            <div class="space-y-0">
                @forelse($events as $event)
                    @php
                        $isVoided = $event->void !== null;
                    @endphp
                    <div class="relative pl-6 pb-6 border-l-2 
                        {{ $isVoided ? 'border-slate-250 opacity-60' : ($event->result === 'success' ? 'border-emerald-400' : 'border-red-400') }} 
                        last:border-transparent last:pb-0">
                        
                        <div class="absolute -left-[9px] top-0 w-4 h-4 rounded-full border-2 
                            {{ $isVoided ? 'bg-slate-100 border-slate-350' : ($event->result === 'success' ? 'bg-emerald-100 border-emerald-400' : 'bg-red-100 border-red-400') }}">
                        </div>

                        <div class="bg-slate-50 rounded-lg p-3 ml-2 border border-slate-150">
                            <div class="flex items-start justify-between">
                                <div>
                                    <div class="font-semibold text-slate-800">
                                        @if($isVoided)
                                            <span class="text-slate-500 line-through">{{ $event->stage_label }}</span>
                                            <span class="ml-2 bg-red-100 text-red-800 text-[9px] font-bold px-1.5 py-0.5 rounded uppercase">VOIDED / BATAL</span>
                                        @elseif($event->result === 'success')
                                            <span class="text-emerald-600">{{ $event->stage_label }}</span>
                                        @else
                                            <span class="text-red-600">DITOLAK</span>
                                        @endif
                                    </div>
                                </div>
                                <span class="text-xs text-slate-500">{{ $event->scanned_at->format('H:i:s') }}</span>
                            </div>

                            <div class="text-xs text-slate-500 mt-1">
                                {{ $event->scanned_at->format('d M Y') }}
                                &middot;
                                Operator: {{ optional($event->operator)->name ?? '-' }}
                                @if($event->result === 'success' && !$isVoided)
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

                            @if($event->anomaly_reason && !$isVoided)
                                <div class="text-xs text-red-600 mt-1 bg-red-50 p-2 rounded border border-red-100">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>
                                    {{ $event->anomaly_reason }}
                                </div>
                            @endif

                            @if($isVoided)
                                <div class="mt-2 text-xs bg-red-50 border border-red-100 text-red-950 p-2.5 rounded">
                                    <div class="font-bold flex items-center gap-1 mb-0.5">
                                        <i class="fas fa-info-circle text-red-600"></i>
                                        Detail Pembatalan:
                                    </div>
                                    <div class="italic">"{{ $event->void->void_reason }}"</div>
                                    <div class="text-[10px] text-red-750 mt-1">
                                        Batal Oleh: <strong>{{ $event->void->voidedByUser->name }}</strong> &middot; Tanggal: {{ $event->void->voided_at->format('d-m-Y H:i') }}
                                    </div>
                                </div>
                            @endif

                            <!-- Void Button for Latest Success Event -->
                            @if(!$isVoided && $event->result === 'success' && $latestSuccessEvent && $event->id === $latestSuccessEvent->id && $canVoid)
                                <div class="mt-3 border-t border-slate-200 pt-2 flex justify-end">
                                    <button type="button" onclick="confirmVoid('{{ $event->id }}', '{{ $event->stage_label }}')"
                                        class="bg-red-50 hover:bg-red-100 text-red-750 hover:text-red-900 border border-red-200 hover:border-red-300 text-xs font-bold py-1 px-2.5 rounded flex items-center gap-1 transition-all">
                                        <i class="fas fa-undo"></i> Batalkan Scan (Void)
                                    </button>

                                    <form id="voidForm-{{ $event->id }}" action="{{ route('lost-wax.scan-events.void', $event) }}" method="POST" style="display:none;">
                                        @csrf
                                        <input type="hidden" name="void_reason" value="">
                                    </form>
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="text-sm text-slate-500 text-center py-8">Belum ada aktivitas scan.</div>
                @endforelse
            </div>
        </div>
    </div>

    @if($canVoid)
        <script>
            window.confirmVoid = function(eventId, stageLabel) {
                Swal.fire({
                    title: 'Batalkan Scan Event?',
                    text: 'Anda akan membatalkan scan untuk: ' + stageLabel + '. Alasan pembatalan wajib ditulis.',
                    input: 'textarea',
                    inputPlaceholder: 'Tulis alasan pembatalan...',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Batalkan Scan',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#475569',
                    preConfirm: (reason) => {
                        if (!reason || reason.trim() === '') {
                            Swal.showValidationMessage('Alasan pembatalan wajib diisi.');
                            return false;
                        }
                        return reason;
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        const form = document.getElementById('voidForm-' + eventId);
                        form.querySelector('input[name="void_reason"]').value = result.value;
                        form.submit();
                    }
                });
            }
        </script>
    @endif
@endsection
