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

            {{-- Tombol Cetak PDF --}}
            <a href="{{ route('fitting.kanban.print', array_merge(['stage' => $stageSlug], request()->query())) }}"
               target="_blank"
               class="inline-flex items-center justify-center gap-1.5 px-2.5 sm:px-3 py-1 sm:py-1.5 rounded-lg text-xs font-bold bg-slate-800 hover:bg-slate-900 active:bg-slate-950 text-white shadow-sm transition-all min-h-[32px] sm:min-h-[36px]"
               title="Cetak PDF Antrean Kanban {{ $stageLabel }} (A4 Landscape)">
                <i class="fas fa-print text-xs"></i>
                <span class="hidden sm:inline">CETAK PDF</span>
                <span class="sm:hidden">PDF</span>
            </a>

            {{-- Tombol Export Excel --}}
            <a href="{{ route('fitting.kanban.export.excel', array_merge(['stage' => $stageSlug], request()->query())) }}"
               class="inline-flex items-center justify-center gap-1.5 px-2.5 sm:px-3 py-1 sm:py-1.5 rounded-lg text-xs font-bold bg-emerald-700 hover:bg-emerald-800 active:bg-emerald-900 text-white shadow-sm transition-all min-h-[32px] sm:min-h-[36px]"
               title="Export Excel Antrean Kanban {{ $stageLabel }}">
                <i class="fas fa-file-excel text-xs"></i>
                <span class="hidden sm:inline">EXPORT EXCEL</span>
                <span class="sm:hidden">EXCEL</span>
            </a>

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
        '1' => ['title' => 'LINE 1 — FITTING BIASA', 'headerClass' => 'bg-blue-600 text-white', 'borderClass' => 'border-blue-200'],
        '2' => ['title' => 'LINE 2 — UNION', 'headerClass' => 'bg-emerald-600 text-white', 'borderClass' => 'border-emerald-200'],
        '3' => ['title' => 'LINE 3 — BALL VALVE', 'headerClass' => 'bg-purple-600 text-white', 'borderClass' => 'border-purple-200'],
        '4' => ['title' => 'LINE 4 — BW', 'headerClass' => 'bg-amber-600 text-white', 'borderClass' => 'border-amber-200'],
    ];
@endphp

<div class="space-y-2.5 w-full pb-4">

    {{-- Alerts --}}
    @if($errors->has('reorder'))
        <div class="bg-rose-50 border border-rose-300 text-rose-800 px-3.5 py-2.5 rounded-xl text-xs font-bold flex items-center gap-2">
            <i class="fas fa-exclamation-circle text-rose-600"></i>
            <span>{{ $errors->first('reorder') }}</span>
        </div>
    @endif

    {{-- Stage Switcher Tabs (Multi-Stage Users) --}}
    @if(count($availableStages) > 1)
        <div class="bg-white rounded-xl border border-slate-200 p-1.5 shadow-xs overflow-x-auto">
            <div class="flex items-center gap-1 min-w-max">
                @foreach($availableStages as $stgInfo)
                    <a href="{{ $stgInfo['url'] }}"
                       class="px-2.5 py-1 rounded-lg text-xs font-bold transition-all {{ $stgInfo['is_active'] ? 'bg-emerald-600 text-white shadow-xs' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100' }}">
                        {{ $stgInfo['label'] }}
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Summary + Compact Filter Bar --}}
    <div class="bg-white rounded-xl border border-slate-200 p-2.5 shadow-xs space-y-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-1.5 text-xs">
                <span class="px-2 py-0.5 rounded-md bg-slate-900 text-white font-bold text-[11px]">
                    {{ $stageLabel }}
                </span>
                <span class="px-2 py-0.5 rounded-md bg-slate-100 text-slate-800 font-mono font-bold text-[11px]">
                    <span id="headerTotalCards">{{ number_format($summary['total_cards']) }}</span> Traveler
                </span>
                <span class="px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-800 border border-emerald-200 font-mono font-bold text-[11px]">
                    <span id="headerTotalPcs">{{ number_format($summary['total_pcs']) }}</span> PCS
                </span>
                <span class="px-2 py-0.5 rounded-md bg-blue-50 text-blue-800 border border-blue-200 font-mono font-bold text-[11px]">
                    <span id="headerTotalKg">{{ number_format($summary['total_weight_kg'], 1, ',', '.') }}</span> KG
                </span>
                @if(($summary['unassigned_cards'] ?? 0) > 0)
                    <span class="px-2 py-0.5 rounded-md bg-amber-50 text-amber-800 border border-amber-200 font-bold text-[11px]">
                        <i class="fas fa-exclamation-triangle mr-1 text-amber-600"></i> {{ $summary['unassigned_cards'] }} Unassigned
                    </span>
                @endif
                <span id="clientMatchBadge" class="hidden px-2 py-0.5 rounded-md bg-indigo-50 text-indigo-700 border border-indigo-200 font-mono font-bold text-[11px]">
                    <span id="clientMatchCount">0</span> Cocok
                </span>
            </div>
            <div class="text-[10px] text-slate-400 font-mono">
                Update: {{ $lastRefreshedAt }}
            </div>
        </div>

        <form method="GET" action="{{ route('fitting.kanban.stage', $stageSlug) }}" id="kanbanFilterForm" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-2 items-center">
            <div class="lg:col-span-5 relative">
                <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none text-slate-400">
                    <i class="fas fa-search text-xs"></i>
                </div>
                <input type="text"
                       id="kanbanSearchInput"
                       name="search"
                       value="{{ $filters['search'] ?? '' }}"
                       placeholder="Cari Customer Code (cth: A02), PFKTR, Heat, Item..."
                       autocomplete="off"
                       class="w-full pl-8 pr-7 py-1.5 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500">
                <button type="button"
                        id="kanbanSearchClear"
                        onclick="clearSearchInput()"
                        class="{{ empty($filters['search']) ? 'hidden' : '' }} absolute inset-y-0 right-0 pr-2.5 flex items-center text-slate-400 hover:text-slate-600">
                    <i class="fas fa-times-circle text-xs"></i>
                </button>
            </div>
            <div class="lg:col-span-3">
                <input type="text"
                       id="kanbanCustomerInput"
                       name="customer"
                       value="{{ $filters['customer'] ?? '' }}"
                       placeholder="Filter Customer Code..."
                       class="w-full px-2.5 py-1.5 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-emerald-500">
            </div>
            <div class="lg:col-span-2">
                <select name="line_bucket" class="w-full px-2 py-1.5 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-emerald-500">
                    <option value="">Semua Bucket (Line 1–4)</option>
                    <option value="1" {{ ($filters['line_bucket'] ?? '') === '1' ? 'selected' : '' }}>Line 1 — Fitting biasa</option>
                    <option value="2" {{ ($filters['line_bucket'] ?? '') === '2' ? 'selected' : '' }}>Line 2 — Union</option>
                    <option value="3" {{ ($filters['line_bucket'] ?? '') === '3' ? 'selected' : '' }}>Line 3 — Ball Valve</option>
                    <option value="4" {{ ($filters['line_bucket'] ?? '') === '4' ? 'selected' : '' }}>Line 4 — BW</option>
                </select>
            </div>
            <div class="lg:col-span-2 flex items-center gap-1.5">
                <button type="submit" class="flex-1 px-3 py-1.5 rounded-lg text-xs font-bold bg-slate-800 hover:bg-slate-900 active:bg-slate-950 text-white shadow-xs transition-all">
                    <i class="fas fa-filter mr-1"></i> Filter
                </button>
                <a href="{{ route('fitting.kanban.stage', $stageSlug) }}"
                   class="px-2.5 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 hover:bg-slate-200 active:bg-slate-300 text-slate-600 transition-all"
                   title="Reset Filter">
                    <i class="fas fa-undo"></i>
                </a>
            </div>
        </form>
    </div>

    {{-- Data Anomaly Alert: Active Travelers without Line --}}
    @if(($summary['unassigned_cards'] ?? 0) > 0)
        <div class="bg-amber-50 border border-amber-300 text-amber-900 px-3.5 py-2.5 rounded-xl text-xs flex items-start gap-2 shadow-xs">
            <i class="fas fa-exclamation-triangle text-amber-600 mt-0.5 text-sm shrink-0"></i>
            <div>
                <div class="font-bold">Anomali Data: Ditemukan {{ $summary['unassigned_cards'] }} traveler aktif tanpa Line Fitting valid (UNASSIGNED).</div>
                <div class="text-[11px] text-amber-800 mt-0.5">
                    Seluruh traveler wajib memiliki Line Fitting (1–4) agar masuk ke dalam antrean pengerjaan Kanban.
                    @if(!empty($summary['unassigned_items']))
                        <span class="font-mono font-semibold">Traveler: {{ implode(', ', array_column($summary['unassigned_items'], 'traveler_number')) }}</span>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- 4-BUCKET KANBAN COLUMNS (LINE 1, LINE 2, LINE 3, LINE 4) --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-2.5 items-start">
        @foreach($bucketsToRender as $bucketKey)
            @php
                $meta = $bucketMeta[$bucketKey];
                $bucketCards = $buckets[$bucketKey] ?? [];
                $bucketPcs = (int) array_sum(array_column($bucketCards, 'qty_transferred'));
                $bucketKg = (float) array_sum(array_column($bucketCards, 'total_weight_kg'));
            @endphp
            <div class="bg-white rounded-xl border {{ $meta['borderClass'] }} shadow-xs overflow-hidden flex flex-col h-full" data-line-column="{{ $bucketKey }}">
                {{-- Column Header: Fixed / Sticky outside scroll container --}}
                <div class="px-2.5 py-1.5 {{ $meta['headerClass'] }} flex items-center justify-between shrink-0">
                    <div class="min-w-0">
                        <div class="text-[11px] sm:text-xs font-black tracking-wider uppercase truncate">{{ $meta['title'] }}</div>
                        <div class="text-[9.5px] opacity-90 font-mono">
                            <span data-line-pcs="{{ $bucketKey }}">{{ number_format($bucketPcs) }}</span> PCS &bull;
                            <span data-line-kg="{{ $bucketKey }}">{{ number_format($bucketKg, 1, ',', '.') }}</span> KG
                        </div>
                    </div>
                    <span class="px-2 py-0.5 rounded-full text-[11px] font-mono font-black bg-white/20 shrink-0 ml-1" data-line-count="{{ $bucketKey }}">
                        {{ count($bucketCards) }}
                    </span>
                </div>

                {{-- Independent Column Scroll Area (Viewport-bounded, does not elongate full page) --}}
                <div class="p-1.5 space-y-1.5 bg-slate-50/70 overflow-y-auto overscroll-contain h-[calc(100vh-215px)] min-h-[220px] scrollbar-thin" data-line-scroll="{{ $bucketKey }}">
                    <div class="line-no-matches hidden p-3 text-center text-slate-400 text-xs italic bg-white/60 rounded-lg border border-dashed border-slate-200">
                        Tidak ada kartu cocok di {{ $meta['title'] }}
                    </div>

                    @forelse($bucketCards as $card)
                        @php
                            $effectiveQty = (int) ($card['effective_input_qty'] ?? $card['qty_transferred']);
                            $cardWeight = (float) ($card['total_weight_kg'] ?? 0.0);
                            $searchText = strtolower(implode(' ', array_filter([
                                $card['traveler_number'] ?? '',
                                $card['heat_number'] ?? '',
                                $card['production_code'] ?? '',
                                $card['item_code'] ?? '',
                                $card['item_name'] ?? '',
                                $card['customer'] ?? '',
                                $card['transfer_number'] ?? '',
                                implode(' ', array_column($card['allocations'] ?? [], 'customer')),
                                implode(' ', array_column($card['allocations'] ?? [], 'production_code')),
                                implode(' ', array_column($card['allocations'] ?? [], 'item_name')),
                            ])));
                        @endphp
                        {{-- COMPACT CARD (Reference: Sand Casting density) --}}
                        <div class="kanban-card bg-white rounded-lg border {{ $card['is_urgent'] ? 'border-rose-400 ring-1 ring-rose-300' : 'border-slate-200 hover:border-slate-300' }} p-2 shadow-2xs space-y-1 transition-all text-xs"
                             data-search-text="{{ $searchText }}"
                             data-card-line="{{ $bucketKey }}"
                             data-card-qty="{{ $effectiveQty }}"
                             data-card-weight="{{ $cardWeight }}">

                            {{-- Row 1: Item Name (Bold) + Badges (Queue Seq, PPIC, Urgent) --}}
                            <div class="flex items-start justify-between gap-1 leading-snug">
                                <div class="font-bold text-slate-900 text-[11px] leading-tight line-clamp-2 min-w-0" title="{{ $card['item_name'] }}">
                                    {{ $card['item_name'] }}
                                </div>
                                <div class="flex items-center gap-1 shrink-0">
                                    @if($card['is_urgent'])
                                        <span class="px-1 py-0.2 rounded bg-rose-100 text-rose-700 text-[8.5px] font-black uppercase">URGENT</span>
                                    @endif
                                    @if($card['queue_position'] !== null)
                                        <span class="px-1 py-0.2 rounded bg-indigo-50 text-indigo-700 border border-indigo-200 text-[8.5px] font-bold" title="Prioritas Manual PPIC">PPIC</span>
                                    @endif
                                    <span class="inline-flex items-center justify-center min-w-[20px] h-4 px-1 rounded bg-slate-900 text-white font-mono font-black text-[9px]">
                                        #{{ $card['queue_seq'] }}
                                    </span>
                                </div>
                            </div>

                            {{-- Row 2: Traveler (PFKTR) & Production Code --}}
                            <div class="flex items-center justify-between text-[10px] font-mono leading-none pt-0.5">
                                <span class="font-black text-emerald-800 truncate" title="Traveler: {{ $card['traveler_number'] }}">
                                    {{ $card['traveler_number'] }}
                                </span>
                                <span class="font-semibold text-slate-500 truncate ml-1 text-right" title="Kode: {{ $card['production_code'] }}">
                                    {{ $card['production_code'] }}
                                </span>
                            </div>

                            {{-- Row 3: Heat (Left) & Qty / Weight (Right on same line) --}}
                            <div class="pt-0.5 border-t border-slate-100 flex items-center justify-between text-[10px] font-mono">
                                <span class="font-semibold text-slate-600 truncate" title="Heat: {{ $card['heat_number'] }}">
                                    Heat: <strong class="text-slate-800">{{ $card['heat_number'] }}</strong>
                                </span>
                                <div class="text-right shrink-0 font-black text-slate-900 ml-1">
                                    <span>{{ number_format($effectiveQty) }}<span class="text-[8.5px] font-bold text-slate-400 ml-0.5">PCS</span></span>
                                    <span class="text-slate-300 font-normal">/</span>
                                    <span class="{{ $card['has_incomplete_weight'] ? 'text-amber-700' : '' }}">{{ number_format($cardWeight, 1, ',', '.') }}<span class="text-[8.5px] font-bold text-slate-400 ml-0.5">KG{{ $card['has_incomplete_weight'] ? '*' : '' }}</span></span>
                                </div>
                            </div>

                            {{-- Row 4: Customer Code badge (Left) & Transfer Date / Stage (Right) --}}
                            <div class="flex items-center justify-between gap-1 text-[9px] pt-0.5 border-t border-slate-100">
                                <div class="flex items-center gap-1 min-w-0">
                                    <span class="inline-flex items-center px-1.5 py-0.2 rounded font-mono font-bold bg-slate-100 text-slate-700 border border-slate-200 truncate" title="Customer: {{ $card['customer'] }}">
                                        [{{ $card['customer'] }}]
                                    </span>
                                    @if($card['is_multi_allocation'])
                                        <span class="px-1 py-0.2 rounded bg-indigo-50 text-indigo-700 font-bold text-[8.5px]">Multi</span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-1.5 shrink-0 text-slate-400 font-mono">
                                    <span>{{ $card['transfer_date_formatted'] }}</span>
                                    <span class="font-bold text-emerald-700 font-sans">{{ $card['current_stage_label'] }}</span>
                                </div>
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

{{-- Real-time Client-side Filter Script (Customer Code, PFKTR, Heat, Item) + Auto-Refresh --}}
<script>
function clearSearchInput() {
    const input = document.getElementById('kanbanSearchInput');
    if (input) {
        input.value = '';
        input.dispatchEvent(new Event('input'));
        input.focus();
    }
}

(function () {
    const searchInput = document.getElementById('kanbanSearchInput');
    const customerInput = document.getElementById('kanbanCustomerInput');
    const clearBtn = document.getElementById('kanbanSearchClear');
    const matchBadge = document.getElementById('clientMatchBadge');
    const matchCountEl = document.getElementById('clientMatchCount');
    const cards = document.querySelectorAll('.kanban-card');

    function applyLiveFilter() {
        const sQuery = (searchInput ? searchInput.value : '').trim().toLowerCase();
        const cQuery = (customerInput ? customerInput.value : '').trim().toLowerCase();

        if (clearBtn) {
            if (sQuery !== '') {
                clearBtn.classList.remove('hidden');
            } else {
                clearBtn.classList.add('hidden');
            }
        }

        let totalMatched = 0;
        const lineBuckets = ['1', '2', '3', '4'];
        const lineStats = {};
        lineBuckets.forEach(function (l) {
            lineStats[l] = { count: 0, pcs: 0, kg: 0 };
        });

        cards.forEach(function (card) {
            const text = card.getAttribute('data-search-text') || '';
            const line = card.getAttribute('data-card-line') || '';
            const qty = parseInt(card.getAttribute('data-card-qty') || '0', 10);
            const weight = parseFloat(card.getAttribute('data-card-weight') || '0');

            let matched = true;
            if (sQuery !== '' && !text.includes(sQuery)) {
                matched = false;
            }
            if (cQuery !== '' && !text.includes(cQuery)) {
                matched = false;
            }

            if (matched) {
                card.style.display = '';
                totalMatched++;
                if (lineStats[line]) {
                    lineStats[line].count++;
                    lineStats[line].pcs += qty;
                    lineStats[line].kg += weight;
                }
            } else {
                card.style.display = 'none';
            }
        });

        // Update column headers & no-match alerts
        lineBuckets.forEach(function (l) {
            const countEl = document.querySelector('[data-line-count="' + l + '"]');
            const pcsEl = document.querySelector('[data-line-pcs="' + l + '"]');
            const kgEl = document.querySelector('[data-line-kg="' + l + '"]');
            const scrollArea = document.querySelector('[data-line-scroll="' + l + '"]');
            const noMatchAlert = scrollArea ? scrollArea.querySelector('.line-no-matches') : null;

            if (countEl) countEl.textContent = lineStats[l].count.toLocaleString('id-ID');
            if (pcsEl) pcsEl.textContent = lineStats[l].pcs.toLocaleString('id-ID');
            if (kgEl) kgEl.textContent = lineStats[l].kg.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

            if (noMatchAlert) {
                const totalCardsInLine = scrollArea.querySelectorAll('.kanban-card').length;
                if (totalCardsInLine > 0 && lineStats[l].count === 0 && (sQuery !== '' || cQuery !== '')) {
                    noMatchAlert.classList.remove('hidden');
                } else {
                    noMatchAlert.classList.add('hidden');
                }
            }
        });

        if (matchBadge && matchCountEl) {
            if (sQuery !== '' || cQuery !== '') {
                matchBadge.classList.remove('hidden');
                matchCountEl.textContent = totalMatched.toLocaleString('id-ID');
            } else {
                matchBadge.classList.add('hidden');
            }
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyLiveFilter);
    }
    if (customerInput) {
        customerInput.addEventListener('input', applyLiveFilter);
    }

    // Auto-refresh timer
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
