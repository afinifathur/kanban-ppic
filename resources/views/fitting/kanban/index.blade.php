@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full gap-2 min-w-0">
        <div class="flex items-center gap-2 min-w-0">
            <h1 class="text-sm sm:text-base font-black text-slate-900 tracking-tight truncate uppercase">
                KANBAN FITTING &mdash; {{ $stageLabel }}
            </h1>
        </div>
        <div class="flex items-center gap-1.5 shrink-0">
            @if(!empty($canReorder))
                <button type="button" onclick="openFittingReorderModal()"
                        class="inline-flex items-center justify-center gap-1.5 px-2.5 sm:px-3 py-1 sm:py-1.5 rounded-lg text-xs font-bold bg-indigo-600 hover:bg-indigo-700 text-white shadow-sm transition-all min-h-[32px] sm:min-h-[36px]"
                        title="Atur Prioritas Antrean Fitting (PPIC)">
                    <i class="fas fa-sort-amount-down text-xs"></i>
                    <span class="hidden sm:inline">EDIT ANTRIAN</span>
                    <span class="sm:hidden">ANTRIAN</span>
                </button>
            @endif
            @if(!empty($canScan) && !empty($scannerUrl))
                <a href="{{ $scannerUrl }}"
                   class="inline-flex items-center justify-center gap-1.5 px-2.5 sm:px-3 py-1 sm:py-1.5 rounded-lg text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm transition-all min-h-[32px] sm:min-h-[36px]"
                   title="Buka Scanner {{ $stageLabel }}">
                    <i class="fas fa-qrcode text-xs"></i>
                    <span>SCAN</span>
                </a>
            @endif
            <span id="autoRefreshBadge" class="hidden sm:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-slate-100 border border-slate-200 text-slate-600 min-h-[32px] sm:min-h-[36px]">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                <span class="text-[10px] text-slate-500 font-sans uppercase font-bold">AUTO:</span>
                <span id="autoRefreshTimer" class="font-mono">02:00</span>
            </span>
            <a href="{{ url()->current() . (request()->getQueryString() ? '?' . request()->getQueryString() : '') }}"
               class="inline-flex items-center justify-center px-2.5 py-1 rounded-lg text-xs font-bold bg-white border border-slate-300 text-slate-700 hover:bg-slate-50 shadow-sm min-h-[32px] sm:min-h-[36px]"
               title="Refresh data">
                <i class="fas fa-sync-alt text-slate-600 text-xs"></i>
            </a>
        </div>
    </div>
@endsection

@section('content')
@php
    $selectedBucket = $filters['line_bucket'] ?? null;
    $bucketsToRender = $selectedBucket && in_array($selectedBucket, \App\Services\Fitting\FittingKanbanQueryService::ALL_BUCKETS, true)
        ? [$selectedBucket]
        : \App\Services\Fitting\FittingKanbanQueryService::ALL_BUCKETS;

    $bucketMeta = [
        '1' => ['title' => 'LINE 1 — ELBOW / TEE', 'headerClass' => 'bg-blue-600 text-white', 'borderClass' => 'border-blue-200'],
        '2' => ['title' => 'LINE 2 — UNION', 'headerClass' => 'bg-emerald-600 text-white', 'borderClass' => 'border-emerald-200'],
        '3' => ['title' => 'LINE 3 — BW', 'headerClass' => 'bg-amber-600 text-white', 'borderClass' => 'border-amber-200'],
        '4' => ['title' => 'LINE 4 — BALL VALVE', 'headerClass' => 'bg-purple-600 text-white', 'borderClass' => 'border-purple-200'],
        'unassigned' => ['title' => 'UNASSIGNED', 'headerClass' => 'bg-slate-700 text-white', 'borderClass' => 'border-slate-300'],
    ];
@endphp

<div class="space-y-3 w-full pb-6">

    {{-- Alerts --}}
    @if($errors->has('reorder'))
        <div class="bg-rose-50 border border-rose-300 text-rose-800 px-3.5 py-2.5 rounded-xl text-xs font-bold flex items-center gap-2">
            <i class="fas fa-exclamation-circle text-rose-600"></i>
            <span>{{ $errors->first('reorder') }}</span>
        </div>
    @endif

    {{-- Stage Switcher Tabs (Multi-Stage Users) --}}
    @if(count($availableStages) > 1)
        <div class="bg-white rounded-xl border border-slate-200 p-1.5 shadow-sm overflow-x-auto">
            <div class="flex items-center gap-1 min-w-max">
                @foreach($availableStages as $stgInfo)
                    <a href="{{ $stgInfo['url'] }}"
                       class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all {{ $stgInfo['is_active'] ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100' }}">
                        {{ $stgInfo['label'] }}
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Summary + Filter Bar --}}
    <div class="bg-white rounded-xl border border-slate-200 p-3 shadow-sm space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="px-2.5 py-1 rounded-lg bg-slate-900 text-white font-bold">
                    {{ $stageLabel }}
                </span>
                <span class="px-2.5 py-1 rounded-lg bg-slate-100 text-slate-800 font-mono font-bold">
                    {{ number_format($summary['total_cards']) }} Traveler
                </span>
                <span class="px-2.5 py-1 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 font-mono font-bold">
                    {{ number_format($summary['total_pcs']) }} PCS
                </span>
                <span class="px-2.5 py-1 rounded-lg bg-blue-50 text-blue-800 border border-blue-200 font-mono font-bold">
                    {{ number_format($summary['total_weight_kg'], 2, ',', '.') }} KG
                </span>
                @if($summary['unassigned_cards'] > 0)
                    <span class="px-2.5 py-1 rounded-lg bg-amber-50 text-amber-800 border border-amber-200 font-bold">
                        <i class="fas fa-exclamation-triangle mr-1"></i> {{ $summary['unassigned_cards'] }} Unassigned
                    </span>
                @endif
            </div>
            <div class="text-[11px] text-slate-400 font-mono">
                Update: {{ $lastRefreshedAt }}
            </div>
        </div>

        <form method="GET" action="{{ route('fitting.kanban.stage', $stageSlug) }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-2 items-end">
            <div class="lg:col-span-5">
                <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                       placeholder="Cari PFKTR, Heat, Kode Produksi, Item, Customer..."
                       class="w-full px-3 py-1.5 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-emerald-500">
            </div>
            <div class="lg:col-span-3">
                <input type="text" name="customer" value="{{ $filters['customer'] ?? '' }}"
                       placeholder="Filter Customer..."
                       class="w-full px-3 py-1.5 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-emerald-500">
            </div>
            <div class="lg:col-span-2">
                <select name="line_bucket" class="w-full px-3 py-1.5 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-emerald-500">
                    <option value="">Semua Bucket (Line 1–4 + Unassigned)</option>
                    <option value="1" {{ ($filters['line_bucket'] ?? '') === '1' ? 'selected' : '' }}>Line 1</option>
                    <option value="2" {{ ($filters['line_bucket'] ?? '') === '2' ? 'selected' : '' }}>Line 2</option>
                    <option value="3" {{ ($filters['line_bucket'] ?? '') === '3' ? 'selected' : '' }}>Line 3</option>
                    <option value="4" {{ ($filters['line_bucket'] ?? '') === '4' ? 'selected' : '' }}>Line 4</option>
                    <option value="unassigned" {{ ($filters['line_bucket'] ?? '') === 'unassigned' ? 'selected' : '' }}>Unassigned</option>
                </select>
            </div>
            <div class="lg:col-span-2 flex items-center gap-1.5">
                <button type="submit" class="flex-1 px-3 py-1.5 rounded-lg text-xs font-bold bg-slate-800 hover:bg-slate-900 text-white">
                    <i class="fas fa-search mr-1"></i> Filter
                </button>
                <a href="{{ route('fitting.kanban.stage', $stageSlug) }}"
                   class="px-2.5 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 hover:bg-slate-200 text-slate-600"
                   title="Reset">
                    <i class="fas fa-undo"></i>
                </a>
            </div>
        </form>
    </div>

    {{-- 5-BUCKET KANBAN COLUMNS (LINE 1, LINE 2, LINE 3, LINE 4, UNASSIGNED) --}}
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-3 items-start">
        @foreach($bucketsToRender as $bucketKey)
            @php
                $meta = $bucketMeta[$bucketKey];
                $bucketCards = $buckets[$bucketKey] ?? [];
                $bucketPcs = (int) array_sum(array_column($bucketCards, 'qty_transferred'));
                $bucketKg = (float) array_sum(array_column($bucketCards, 'total_weight_kg'));
            @endphp
            <div class="bg-white rounded-xl border {{ $meta['borderClass'] }} shadow-sm overflow-hidden flex flex-col">
                <div class="px-3 py-2.5 {{ $meta['headerClass'] }} flex items-center justify-between">
                    <div>
                        <div class="text-xs font-black tracking-wider">{{ $meta['title'] }}</div>
                        <div class="text-[10px] opacity-90 font-mono">
                            {{ number_format($bucketPcs) }} PCS &bull; {{ number_format($bucketKg, 2, ',', '.') }} KG
                        </div>
                    </div>
                    <span class="px-2 py-0.5 rounded-full text-xs font-mono font-black bg-white/20">
                        {{ count($bucketCards) }}
                    </span>
                </div>

                <div class="p-2 space-y-2 bg-slate-50/70 min-h-[180px]">
                    @forelse($bucketCards as $card)
                        <div class="bg-white rounded-lg border {{ $card['is_urgent'] ? 'border-rose-400 ring-1 ring-rose-200' : 'border-slate-200' }} p-2.5 shadow-xs space-y-1.5 text-xs">
                            <div class="flex items-center justify-between gap-1.5">
                                <div class="flex items-center gap-1.5 min-w-0">
                                    <span class="inline-flex items-center justify-center min-w-[24px] h-5 px-1.5 rounded bg-slate-900 text-white font-mono font-black text-[10px]">
                                        #{{ $card['queue_seq'] }}
                                    </span>
                                    @if($card['queue_position'] !== null)
                                        <span class="px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-700 border border-indigo-200 text-[9px] font-bold" title="Prioritas Manual PPIC">
                                            PPIC
                                        </span>
                                    @endif
                                    @if($card['is_urgent'])
                                        <span class="px-1.5 py-0.5 rounded bg-rose-100 text-rose-700 text-[9px] font-black">URGENT</span>
                                    @endif
                                </div>
                                <span class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 font-bold">
                                    {{ $card['aging']['label'] }}
                                </span>
                            </div>

                            <div class="font-mono font-black text-emerald-800 text-[11px] truncate" title="{{ $card['traveler_number'] }}">
                                {{ $card['traveler_number'] }}
                            </div>

                            <div class="flex items-center justify-between text-[11px]">
                                <span class="font-mono font-bold text-slate-800">Heat: {{ $card['heat_number'] }}</span>
                                <span class="font-mono font-semibold text-slate-500">{{ $card['production_code'] }}</span>
                            </div>

                            <div class="text-[11px] font-semibold text-slate-900 leading-snug line-clamp-2" title="{{ $card['item_name'] }}">
                                {{ $card['item_name'] }}
                            </div>

                            <div class="text-[10px] text-slate-500 truncate" title="{{ $card['customer'] }}">
                                <i class="fas fa-building mr-1 opacity-60"></i>{{ $card['customer'] }}
                                @if($card['is_multi_allocation'])
                                    <span class="ml-1 px-1 py-0.5 rounded bg-indigo-50 text-indigo-700 font-bold text-[9px]">Multi</span>
                                @endif
                            </div>

                            <div class="pt-1.5 border-t border-slate-100 flex items-center justify-between text-[11px]">
                                <span class="font-mono font-black text-slate-900">
                                    {{ number_format($card['effective_input_qty'] ?? $card['qty_transferred']) }} PCS
                                    @if(($card['upstream_defect_qty'] ?? 0) > 0)
                                        <span class="text-[9px] font-semibold text-rose-600" title="Transfer awal {{ number_format($card['qty_transferred']) }} PCS">(Trf {{ number_format($card['qty_transferred']) }})</span>
                                    @endif
                                </span>
                                <span class="font-mono font-semibold {{ $card['has_incomplete_weight'] ? 'text-amber-700' : 'text-slate-600' }}">
                                    {{ number_format($card['total_weight_kg'], 2, ',', '.') }} KG{{ $card['has_incomplete_weight'] ? '*' : '' }}
                                </span>
                            </div>

                            <div class="flex items-center justify-between text-[10px] text-slate-400">
                                <span>Trf: {{ $card['transfer_date_formatted'] }}</span>
                                <span class="font-bold text-emerald-700">{{ $card['current_stage_label'] }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center text-[11px] text-slate-400 italic">
                            Tidak ada antrean
                        </div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
</div>

{{-- Reorder Modal (PPIC Fitting & System Admin only) --}}
@if(!empty($canReorder))
<div id="fittingReorderModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white border border-slate-200 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <div>
                <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wider">Edit Prioritas Antrean &mdash; {{ $stageLabel }}</h3>
                <p class="text-[11px] text-slate-500">Ubah posisi antrean dalam bucket Line 1–4 atau Unassigned (tidak mengubah histori transfer).</p>
            </div>
            <button type="button" onclick="closeFittingReorderModal()" class="text-slate-400 hover:text-slate-700">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form method="POST" action="{{ $reorderUrl }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Bucket Line</label>
                <select name="line_bucket" required class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-indigo-500">
                    <option value="1">Line 1 ({{ $summary['bucket_counts']['1'] ?? 0 }} item)</option>
                    <option value="2">Line 2 ({{ $summary['bucket_counts']['2'] ?? 0 }} item)</option>
                    <option value="3">Line 3 ({{ $summary['bucket_counts']['3'] ?? 0 }} item)</option>
                    <option value="4">Line 4 ({{ $summary['bucket_counts']['4'] ?? 0 }} item)</option>
                    <option value="unassigned">Unassigned ({{ $summary['bucket_counts']['unassigned'] ?? 0 }} item)</option>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Dari Posisi (#)</label>
                    <input type="number" name="from_position" min="1" required placeholder="Contoh: 3"
                           class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Ke Posisi (#)</label>
                    <input type="number" name="to_position" min="1" required placeholder="Contoh: 1"
                           class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
            </div>
            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" onclick="closeFittingReorderModal()"
                        class="px-4 py-2 rounded-lg text-xs font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200">
                    Batal
                </button>
                <button type="submit"
                        class="px-4 py-2 rounded-lg text-xs font-bold bg-indigo-600 hover:bg-indigo-700 text-white shadow-sm">
                    <i class="fas fa-save mr-1.5"></i> Simpan Urutan
                </button>
            </div>
        </form>
    </div>
</div>
<script>
    function openFittingReorderModal() {
        document.getElementById('fittingReorderModal').classList.remove('hidden');
    }
    function closeFittingReorderModal() {
        document.getElementById('fittingReorderModal').classList.add('hidden');
    }
</script>
@endif

<script>
(function () {
    let remainingSec = 120;
    const timerEl = document.getElementById('autoRefreshTimer');
    setInterval(function () {
        remainingSec--;
        if (remainingSec <= 0) {
            window.location.reload();
            return;
        }
        if (timerEl) {
            const m = String(Math.floor(remainingSec / 60)).padStart(2, '0');
            const s = String(remainingSec % 60).padStart(2, '0');
            timerEl.textContent = m + ':' + s;
        }
    }, 1000);
})();
</script>
@endsection
