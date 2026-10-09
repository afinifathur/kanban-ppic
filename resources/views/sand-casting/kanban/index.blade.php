@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full gap-2 min-w-0">
        <div class="flex items-center gap-2 min-w-0">
            <h1 class="text-sm sm:text-base font-black text-slate-900 tracking-tight truncate uppercase">
                KANBAN PRODUKSI &mdash; {{ $stageLabel }}
            </h1>
        </div>
        <div class="flex items-center gap-1.5 shrink-0">
            @if(!empty($canReorder))
                <button type="button" onclick="openReorderModal()" class="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white shadow-sm transition-all min-h-[36px] touch-manipulation" title="Atur Prioritas Antrean (PPIC)">
                    <i class="fas fa-sort-amount-down text-xs"></i>
                    <span class="hidden sm:inline">EDIT ANTRIAN</span>
                    <span class="sm:hidden">ANTRIAN</span>
                </button>
            @endif
            <a href="{{ $scannerUrl }}" class="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white shadow-sm transition-all min-h-[36px] touch-manipulation" title="Buka Scanner {{ $stageLabel }}">
                <i class="fas fa-qrcode text-xs"></i>
                <span>SCAN</span>
            </a>
            {{-- Auto-Refresh Indicator Badge (Desktop / TV) --}}
            <span id="autoRefreshBadge" class="hidden sm:inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-mono font-bold bg-slate-100 border border-slate-200 text-slate-600 min-h-[36px]" title="Auto-Refresh Aktif (Desktop / TV)">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                <span class="text-[10px] text-slate-500 font-sans uppercase font-bold tracking-tight">AUTO:</span>
                <span id="autoRefreshTimer" class="font-mono">02:00</span>
            </span>
            <a href="{{ url()->current() . '?' . http_build_query(request()->query()) }}" class="inline-flex items-center justify-center px-2.5 py-1.5 rounded-lg text-xs font-bold bg-white border border-slate-300 text-slate-700 hover:bg-slate-50 active:bg-slate-100 shadow-sm transition-all min-h-[36px] touch-manipulation" title="Refresh data">
                <i class="fas fa-sync-alt text-slate-600 text-xs"></i>
            </a>
        </div>
    </div>
@endsection

@section('content')
@php
    $totalBacklogPcs = (int) array_sum(array_column($readyCards, 'qty'));
    $totalBacklogKg = (float) array_sum(array_column($readyCards, 'total_weight_kg'));

    // Group cards by Line Number (1 to 4)
    $readyByLine = [];
    foreach ($readyCards as $c) {
        $lNum = $c['line_number'] ?? 1;
        $readyByLine[$lNum][] = $c;
    }

    // Determine lines to render based on filter
    $selectedLine = isset($filters['line_number']) ? (int)$filters['line_number'] : null;
    $linesToRender = $selectedLine ? [$selectedLine] : [1, 2, 3, 4];

    // Aging resolver function: semantic color (<5d green, 5-7d yellow, >7d red)
    $getAgingInfo = function($agingData) {
        $hours = is_array($agingData) ? ($agingData['stage_aging_hours'] ?? 0) : 0;
        
        if ($hours >= 24) {
            $days = intdiv($hours, 24);
            $remHours = $hours % 24;
            $label = sprintf('Aging %dD %02dH', $days, $remHours);
        } else {
            $label = sprintf('Aging %02dH', $hours);
        }

        if ($hours < 120) {
            return [
                'label' => $label,
                'color' => 'green',
                'badgeClass' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                'dotClass' => 'bg-emerald-500',
            ];
        } elseif ($hours <= 168) {
            return [
                'label' => $label,
                'color' => 'yellow',
                'badgeClass' => 'bg-amber-50 text-amber-800 border-amber-300',
                'dotClass' => 'bg-amber-500',
            ];
        } else {
            return [
                'label' => $label,
                'color' => 'red',
                'badgeClass' => 'bg-rose-50 text-rose-700 border-rose-200',
                'dotClass' => 'bg-rose-500',
            ];
        }
    };
@endphp

<div class="space-y-3 w-full pb-6">

    {{-- FLASH MESSAGES --}}
    @if(session('success'))
        <div class="bg-emerald-50 border border-emerald-300 text-emerald-800 px-3.5 py-2.5 rounded-xl text-xs font-bold flex items-center justify-between shadow-xs">
            <div class="flex items-center gap-2">
                <i class="fas fa-check-circle text-emerald-600"></i>
                <span>{{ session('success') }}</span>
            </div>
            <button type="button" onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
    @endif

    @if($errors->has('reorder'))
        <div class="bg-rose-50 border border-rose-300 text-rose-800 px-3.5 py-2.5 rounded-xl text-xs font-bold flex items-center justify-between shadow-xs">
            <div class="flex items-center gap-2">
                <i class="fas fa-exclamation-circle text-rose-600"></i>
                <span>{{ $errors->first('reorder') }}</span>
            </div>
            <button type="button" onclick="this.parentElement.remove()" class="text-rose-500 hover:text-rose-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
    @endif

    {{-- STAGE SWITCHER (FOR MULTI-STAGE USERS: ADMIN / PPIC) --}}
    @if(count($availableStages) > 1)
        <div class="bg-white rounded-lg border border-slate-200 p-1 shadow-xs overflow-x-auto scrollbar-thin">
            <div class="flex items-center gap-1 min-w-max">
                @foreach($availableStages as $stgInfo)
                    <a href="{{ $stgInfo['url'] }}" class="px-2.5 py-1 rounded text-xs font-bold transition-all {{ $stgInfo['is_active'] ? 'bg-slate-900 text-white shadow-xs' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100' }}">
                        {{ $stgInfo['label'] }}
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- FIXED / STICKY HEADER AREA --}}
    <div class="sticky top-0 z-10 bg-slate-50/95 backdrop-blur-xs pt-1 pb-2 space-y-2">
        {{-- HERO: BELUM SELESAI --}}
        <div class="bg-slate-900 text-white rounded-xl p-3 sm:p-4 shadow-sm border border-slate-800">
            <div class="flex items-center justify-between">
                <div class="text-[10px] sm:text-xs font-black uppercase tracking-widest text-slate-400">
                    BELUM SELESAI (READY)
                </div>
            </div>
            <div class="mt-1 flex items-baseline justify-between gap-4 font-mono">
                <div class="text-xl sm:text-2xl font-black text-white tracking-tight">
                    {{ number_format($totalBacklogPcs, 0, ',', '.') }} <span class="text-xs sm:text-sm font-bold text-slate-400">PCS</span>
                </div>
                <div class="text-xl sm:text-2xl font-black text-emerald-400 tracking-tight">
                    {{ number_format($totalBacklogKg, 0, ',', '.') }} <span class="text-xs sm:text-sm font-bold text-emerald-300/80">KG</span>
                </div>
            </div>
        </div>

        {{-- SEARCH & FILTER BAR --}}
        <div class="bg-white rounded-xl border border-slate-200 p-2 shadow-xs space-y-2">
            {{-- Search Input Row --}}
            <form id="kanbanSearchForm" onsubmit="event.preventDefault(); applyKanbanSearch(document.getElementById('kanbanSearchInput').value);" class="flex items-center gap-2">
                <div class="relative flex-1 min-w-0">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                        <i class="fas fa-search text-xs sm:text-sm"></i>
                    </div>
                    <input
                        type="text"
                        id="kanbanSearchInput"
                        name="search"
                        value="{{ $filters['search'] ?? '' }}"
                        placeholder="Cari Heat Number atau Nama Item..."
                        autocomplete="off"
                        class="block w-full pl-9 pr-9 py-1.5 sm:py-2 text-xs sm:text-sm bg-slate-50/70 hover:bg-slate-50 focus:bg-white border border-slate-200 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 rounded-lg text-slate-900 placeholder-slate-400 transition-colors"
                    >
                    <button
                        type="button"
                        id="kanbanSearchClearBtn"
                        onclick="clearKanbanSearch()"
                        class="hidden absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none"
                        title="Hapus pencarian"
                    >
                        <i class="fas fa-times-circle text-sm"></i>
                    </button>
                </div>

                <div class="flex items-center gap-1.5 shrink-0">
                    {{-- Match Count Badge --}}
                    <div id="kanbanSearchMatchBadge" class="hidden items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-mono font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">
                        <span id="kanbanSearchMatchCount">0</span>
                        <span class="text-[10px] font-sans font-bold uppercase">COCOK</span>
                    </div>

                    <button
                        type="submit"
                        class="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 sm:py-2 rounded-lg text-xs font-bold bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white shadow-xs transition-all min-h-[36px] touch-manipulation"
                    >
                        <i class="fas fa-search text-xs"></i>
                        <span>CARI</span>
                    </button>

                    <button
                        type="button"
                        id="kanbanSearchResetBtn"
                        onclick="clearKanbanSearch()"
                        class="hidden inline-flex items-center justify-center gap-1.5 px-3 py-1.5 sm:py-2 rounded-lg text-xs font-bold bg-slate-100 hover:bg-slate-200 active:bg-slate-300 text-slate-700 border border-slate-200 shadow-xs transition-all min-h-[36px] touch-manipulation"
                    >
                        <i class="fas fa-undo text-xs"></i>
                        <span>RESET</span>
                    </button>
                </div>
            </form>

            {{-- Line Filter Row --}}
            <div class="flex items-center justify-between gap-1 pt-1.5 border-t border-slate-100 overflow-x-auto scrollbar-thin">
                <div class="flex items-center gap-1 shrink-0">
                    <a href="{{ url()->current() . '?' . http_build_query(array_merge(request()->query(), ['line_number' => null])) }}"
                        class="px-3 py-1.5 rounded-md text-xs font-black whitespace-nowrap min-h-[32px] flex items-center transition-all {{ !isset($filters['line_number']) ? 'bg-slate-900 text-white shadow-xs' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }}">
                        SEMUA
                    </a>
                    @for($l = 1; $l <= 4; $l++)
                        <a href="{{ url()->current() . '?' . http_build_query(array_merge(request()->query(), ['line_number' => $l])) }}"
                            class="px-3 py-1.5 rounded-md text-xs font-black whitespace-nowrap min-h-[32px] flex items-center transition-all {{ (isset($filters['line_number']) && (int)$filters['line_number'] === $l) ? 'bg-indigo-600 text-white shadow-xs' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }}">
                            LINE {{ $l }}
                        </a>
                    @endfor
                </div>

                @if(!empty($filters['search']))
                    <div class="flex items-center gap-1 shrink-0 pl-2">
                        <span class="text-[11px] text-slate-500 font-medium">Server filter: <strong class="text-slate-700">{{ $filters['search'] }}</strong></span>
                        <a href="{{ url()->current() . '?' . http_build_query(array_merge(request()->query(), ['search' => null])) }}" class="text-slate-400 hover:text-slate-600 p-1" title="Hapus filter server">
                            <i class="fas fa-times text-xs"></i>
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- GLOBAL NO SEARCH RESULTS MESSAGE --}}
    <div id="searchNoResults" class="hidden bg-white rounded-xl border border-dashed border-slate-300 p-8 text-center text-slate-500">
        <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-400 mx-auto flex items-center justify-center mb-3 text-lg">
            <i class="fas fa-search"></i>
        </div>
        <p class="text-sm font-bold text-slate-700">Tidak ada kartu yang cocok</p>
        <p class="text-xs text-slate-400 mt-1">
            Tidak ditemukan kartu antrean dengan kata kunci "<span id="searchNoResultsQuery" class="font-bold text-slate-600"></span>".
        </p>
        <div class="mt-4">
            <button type="button" onclick="clearKanbanSearch()" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg text-xs font-bold bg-indigo-600 hover:bg-indigo-700 text-white shadow-xs transition-all min-h-[36px]">
                <i class="fas fa-undo text-xs"></i>
                <span>Reset Pencarian</span>
            </button>
        </div>
    </div>

    {{-- SCROLL AREA: WORK QUEUE CARDS --}}
    @if(count($readyCards) === 0)
        <div class="bg-white rounded-xl border border-dashed border-slate-300 p-8 text-center text-slate-400">
            <i class="fas fa-clipboard-check text-3xl text-slate-300 mb-2"></i>
            <p class="text-sm font-bold text-slate-700">Tidak ada antrean pekerjaan.</p>
            <p class="text-xs text-slate-400 mt-0.5">Seluruh pekerjaan di tahap ini sudah selesai.</p>
        </div>
    @else
        {{-- DESKTOP: INDEPENDENT SCROLL CONTAINERS PER LINE (when SEMUA is active: 4 columns; when 1 line filtered: responsive grid) --}}
        @if(!$selectedLine)
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3 items-start">
                @foreach($linesToRender as $lineNo)
                    @php
                        $cardsInLine = $readyByLine[$lineNo] ?? [];
                        $linePcs = array_sum(array_column($cardsInLine, 'qty'));
                        $lineKg = array_sum(array_column($cardsInLine, 'total_weight_kg'));
                    @endphp
                    <div class="flex flex-col bg-slate-100/70 border border-slate-200/90 rounded-xl p-2.5 shadow-xs" data-line-column="{{ $lineNo }}">
                        {{-- Line Column Header --}}
                        <div class="flex items-center justify-between pb-2 mb-2 border-b border-slate-300 shrink-0">
                            <h2 class="text-xs sm:text-sm font-black text-slate-900 uppercase tracking-tight">
                                LINE {{ $lineNo }}
                            </h2>
                            <div class="text-xs sm:text-sm font-black text-slate-800 font-mono">
                                {{ number_format($lineKg, 0, ',', '.') }} KG
                            </div>
                        </div>

                        {{-- Independent Scroll Container for this Line (desktop only, natural scroll on mobile) --}}
                        <div class="md:overflow-y-auto md:overscroll-contain space-y-2 md:pr-1 md:custom-scrollbar md:h-[calc(100vh-250px)] min-h-[50px]" data-line-scroll="{{ $lineNo }}">
                            @if(count($cardsInLine) === 0)
                                <div class="p-4 text-center text-slate-400 text-xs italic bg-white/60 rounded-lg border border-dashed border-slate-200">
                                    Tidak ada antrean Line {{ $lineNo }}
                                </div>
                            @else
                                <div class="line-no-matches hidden p-4 text-center text-slate-400 text-xs italic bg-white/60 rounded-lg border border-dashed border-slate-200">
                                    Tidak ada kartu cocok di Line {{ $lineNo }}
                                </div>
                                {{-- 1. READY CARDS --}}
                                @foreach($cardsInLine as $card)
                                    @php
                                        $agingInfo = $getAgingInfo($card['aging'] ?? null);
                                        $searchText = strtolower(implode(' ', array_filter([
                                            $card['heat_number'] ?? '',
                                            $card['item_name'] ?? '',
                                            $card['production_code'] ?? '',
                                            $card['traveler_number'] ?? '',
                                            $card['customer'] ?? '',
                                            $card['item_code'] ?? '',
                                        ])));
                                    @endphp
                                    <div class="kanban-card bg-white rounded-xl border {{ $card['is_urgent'] ? 'border-rose-400 ring-1 ring-rose-400' : 'border-slate-200' }} p-2.5 sm:p-3 flex flex-col justify-between transition-all hover:border-slate-300 shadow-xs"
                                         data-search-text="{{ $searchText }}"
                                         data-card-line="{{ $lineNo }}">
                                        <div>
                                             {{-- Top Row: Nama Barang & Nomor Antrian --}}
                                            <div class="flex items-start justify-between gap-1.5">
                                                <div class="font-bold text-slate-900 text-xs sm:text-sm leading-snug tracking-tight line-clamp-2">
                                                    {{ $card['item_name'] ?? '-' }}
                                                </div>
                                                <span class="font-mono text-[11px] font-black text-slate-700 bg-slate-100 px-1.5 py-0.5 rounded border border-slate-200 shrink-0" title="Nomor Antrian">
                                                    {{ $card['queue_number'] ?? '#--' }}
                                                </span>
                                            </div>

                                            {{-- 2. Heat Number --}}
                                            <div class="text-[11px] sm:text-xs font-mono text-slate-500 font-medium mt-0.5">
                                                Heat {{ $card['heat_number'] ?? '-' }}
                                            </div>
                                        </div>

                                        <div>
                                            {{-- 3. Quantity PCS & Berat KG --}}
                                            <div class="mt-2 pt-1.5 border-t border-slate-100 flex items-baseline justify-between font-mono">
                                                <div class="text-xs sm:text-sm font-black text-slate-900">
                                                    {{ number_format($card['qty'], 0, ',', '.') }} <span class="text-[10px] sm:text-xs font-bold text-slate-500">PCS</span>
                                                </div>
                                                <div class="text-xs sm:text-sm font-black text-slate-900">
                                                    {{ number_format($card['total_weight_kg'], 0, ',', '.') }} <span class="text-[10px] sm:text-xs font-bold text-slate-500">KG</span>
                                                </div>
                                            </div>

                                            {{-- 4. Bottom Row: Aging (Left) & Customer Badge (Right) --}}
                                            <div class="mt-1.5 flex items-center justify-between gap-1">
                                                <div class="flex items-center gap-1">
                                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-mono font-bold border {{ $agingInfo['badgeClass'] }}" data-aging-color="{{ $agingInfo['color'] }}">
                                                        <span class="w-1.5 h-1.5 rounded-full {{ $agingInfo['dotClass'] }} shrink-0"></span>
                                                        <span>{{ $agingInfo['label'] }}</span>
                                                    </span>
                                                    @if(!empty($card['is_urgent']))
                                                        <span class="px-1.5 py-0.2 rounded text-[9px] font-black bg-rose-600 text-white animate-pulse">URGENT</span>
                                                    @endif
                                                </div>
                                                @if(!empty($card['customer_badge']))
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-mono font-black border {{ $card['customer_badge']['bg'] }} {{ $card['customer_badge']['text'] }} {{ $card['customer_badge']['border'] }}" title="Customer: {{ $card['customer'] ?? '-' }}">
                                                        [{{ $card['customer_badge']['label'] }}]
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            {{-- SINGLE LINE FILTERED VIEW --}}
            @php
                $lineNo = $selectedLine;
                $cardsInLine = $readyByLine[$lineNo] ?? [];
                $linePcs = array_sum(array_column($cardsInLine, 'qty'));
                $lineKg = array_sum(array_column($cardsInLine, 'total_weight_kg'));
            @endphp
            <div class="flex flex-col bg-slate-100/70 border border-slate-200/90 rounded-xl p-2.5 sm:p-3.5 shadow-xs" data-line-column="{{ $lineNo }}">
                {{-- Line Header --}}
                <div class="flex items-center justify-between pb-2 mb-2 border-b border-slate-300 shrink-0">
                    <div class="flex items-center gap-2">
                        <h2 class="text-xs sm:text-sm font-black text-slate-900 uppercase tracking-tight">
                            LINE {{ $lineNo }}
                        </h2>
                        <span class="text-[11px] font-mono text-slate-500 font-semibold">{{ count($cardsInLine) }} READY ({{ number_format($linePcs) }} PCS)</span>
                    </div>
                    <div class="text-xs sm:text-sm font-black text-slate-800 font-mono">
                        {{ number_format($lineKg, 0, ',', '.') }} KG
                    </div>
                </div>

                {{-- Scroll Container for Filtered Line (desktop only, natural scroll on mobile) --}}
                <div class="md:overflow-y-auto md:overscroll-contain md:pr-1 md:custom-scrollbar md:h-[calc(100vh-250px)] space-y-3" data-line-scroll="{{ $lineNo }}">
                    @if(count($cardsInLine) === 0)
                        <div class="p-8 text-center text-slate-400 text-xs italic bg-white rounded-lg border border-dashed border-slate-200">
                            Tidak ada antrean pada Line {{ $lineNo }}
                        </div>
                    @else
                        <div class="line-no-matches hidden p-8 text-center text-slate-400 text-xs italic bg-white rounded-lg border border-dashed border-slate-200">
                            Tidak ada kartu cocok pada Line {{ $lineNo }}
                        </div>
                        @if(count($cardsInLine) > 0)
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-2">
                                @foreach($cardsInLine as $card)
                                    @php
                                        $agingInfo = $getAgingInfo($card['aging'] ?? null);
                                        $searchText = strtolower(implode(' ', array_filter([
                                            $card['heat_number'] ?? '',
                                            $card['item_name'] ?? '',
                                            $card['production_code'] ?? '',
                                            $card['traveler_number'] ?? '',
                                            $card['customer'] ?? '',
                                            $card['item_code'] ?? '',
                                        ])));
                                    @endphp
                                    <div class="kanban-card bg-white rounded-xl border {{ $card['is_urgent'] ? 'border-rose-400 ring-1 ring-rose-400' : 'border-slate-200' }} p-2.5 sm:p-3 flex flex-col justify-between transition-all hover:border-slate-300 shadow-xs"
                                         data-search-text="{{ $searchText }}"
                                         data-card-line="{{ $lineNo }}">
                                        <div>
                                            {{-- Top Row: Nama Barang & Nomor Antrian --}}
                                            <div class="flex items-start justify-between gap-1.5">
                                                <div class="font-bold text-slate-900 text-xs sm:text-sm leading-snug tracking-tight line-clamp-2">
                                                    {{ $card['item_name'] ?? '-' }}
                                                </div>
                                                <span class="font-mono text-[11px] font-black text-slate-700 bg-slate-100 px-1.5 py-0.5 rounded border border-slate-200 shrink-0" title="Nomor Antrian">
                                                    {{ $card['queue_number'] ?? '#--' }}
                                                </span>
                                            </div>

                                            {{-- 2. Heat Number --}}
                                            <div class="text-[11px] sm:text-xs font-mono text-slate-500 font-medium mt-0.5">
                                                Heat {{ $card['heat_number'] ?? '-' }}
                                            </div>
                                        </div>

                                        <div>
                                            {{-- 3. Quantity PCS & Berat KG --}}
                                            <div class="mt-2 pt-1.5 border-t border-slate-100 flex items-baseline justify-between font-mono">
                                                <div class="text-xs sm:text-sm font-black text-slate-900">
                                                    {{ number_format($card['qty'], 0, ',', '.') }} <span class="text-[10px] sm:text-xs font-bold text-slate-500">PCS</span>
                                                </div>
                                                <div class="text-xs sm:text-sm font-black text-slate-900">
                                                    {{ number_format($card['total_weight_kg'], 0, ',', '.') }} <span class="text-[10px] sm:text-xs font-bold text-slate-500">KG</span>
                                                </div>
                                            </div>

                                            {{-- 4. Bottom Row: Aging (Left) & Customer Badge (Right) --}}
                                            <div class="mt-1.5 flex items-center justify-between gap-1">
                                                <div class="flex items-center gap-1">
                                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-mono font-bold border {{ $agingInfo['badgeClass'] }}" data-aging-color="{{ $agingInfo['color'] }}">
                                                        <span class="w-1.5 h-1.5 rounded-full {{ $agingInfo['dotClass'] }} shrink-0"></span>
                                                        <span>{{ $agingInfo['label'] }}</span>
                                                    </span>
                                                    @if(!empty($card['is_urgent']))
                                                        <span class="px-1.5 py-0.2 rounded text-[9px] font-black bg-rose-600 text-white animate-pulse">URGENT</span>
                                                    @endif
                                                </div>
                                                @if(!empty($card['customer_badge']))
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-mono font-black border {{ $card['customer_badge']['bg'] }} {{ $card['customer_badge']['text'] }} {{ $card['customer_badge']['border'] }}" title="Customer: {{ $card['customer'] ?? '-' }}">
                                                        [{{ $card['customer_badge']['label'] }}]
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        @endif
    @endif

</div>

{{-- PPIC REORDER MODAL --}}
@if(!empty($canReorder))
    <div id="reorderModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-xl border border-slate-200 max-w-sm w-full p-5 space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold">
                        <i class="fas fa-sort-amount-down"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-black text-slate-900 uppercase">Atur Urutan Antrean</h3>
                        <p class="text-[11px] text-slate-500 font-medium">Stage {{ $stageLabel }}</p>
                    </div>
                </div>
                <button type="button" onclick="closeReorderModal()" class="text-slate-400 hover:text-slate-600 p-1">
                    <i class="fas fa-times text-sm"></i>
                </button>
            </div>

            <form action="{{ route('sand-casting.kanban.reorder', $stageSlug) }}" method="POST" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-[11px] font-bold text-slate-700 uppercase mb-1">Pilih Line</label>
                    <select name="line_number" id="modalLineSelect" required class="w-full rounded-lg border-slate-300 text-xs font-bold text-slate-800 focus:ring-indigo-500 focus:border-indigo-500">
                        @for($l = 1; $l <= 4; $l++)
                            <option value="{{ $l }}" {{ ($selectedLine === $l) ? 'selected' : '' }}>LINE {{ $l }} ({{ count($readyByLine[$l] ?? []) }} KTR)</option>
                        @endfor
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 uppercase mb-1">Dari Urutan (#)</label>
                        <input type="number" name="from_position" id="modalFromPos" min="1" required placeholder="Contoh: 30"
                            class="w-full rounded-lg border-slate-300 text-xs font-mono font-bold text-slate-800 focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 uppercase mb-1">Ke Posisi (#)</label>
                        <input type="number" name="to_position" id="modalToPos" min="1" required placeholder="Contoh: 5"
                            class="w-full rounded-lg border-slate-300 text-xs font-mono font-bold text-slate-800 focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                </div>

                <div class="pt-2 flex items-center justify-end gap-2">
                    <button type="button" onclick="closeReorderModal()" class="px-3 py-2 rounded-lg text-xs font-bold text-slate-600 hover:bg-slate-100 transition-all">
                        Batal
                    </button>
                    <button type="submit" class="px-4 py-2 rounded-lg text-xs font-bold bg-indigo-600 hover:bg-indigo-700 text-white shadow-sm transition-all">
                        Simpan Urutan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openReorderModal() {
            const modal = document.getElementById('reorderModal');
            if (modal) {
                modal.classList.remove('hidden');
            }
        }

        function closeReorderModal() {
            const modal = document.getElementById('reorderModal');
            if (modal) {
                modal.classList.add('hidden');
            }
        }
    </script>
@endif

<script>
    function applyKanbanSearch(rawQuery) {
        const query = (rawQuery || '').trim().toLowerCase();
        const cards = document.querySelectorAll('.kanban-card');
        const clearBtn = document.getElementById('kanbanSearchClearBtn');
        const resetBtn = document.getElementById('kanbanSearchResetBtn');
        const matchBadge = document.getElementById('kanbanSearchMatchBadge');
        const matchCountEl = document.getElementById('kanbanSearchMatchCount');
        const noResultsEl = document.getElementById('searchNoResults');
        const noResultsQueryEl = document.getElementById('searchNoResultsQuery');

        if (!query) {
            cards.forEach(card => card.classList.remove('hidden'));
            if (clearBtn) clearBtn.classList.add('hidden');
            if (resetBtn) resetBtn.classList.add('hidden');
            if (matchBadge) {
                matchBadge.classList.add('hidden');
                matchBadge.classList.remove('inline-flex');
            }
            if (noResultsEl) noResultsEl.classList.add('hidden');
            document.querySelectorAll('.line-no-matches').forEach(el => el.classList.add('hidden'));
            return;
        }

        if (clearBtn) clearBtn.classList.remove('hidden');
        if (resetBtn) resetBtn.classList.remove('hidden');

        let totalMatches = 0;
        const lineMatchCounts = { 1: 0, 2: 0, 3: 0, 4: 0 };

        cards.forEach(card => {
            const searchText = (card.getAttribute('data-search-text') || '').toLowerCase();
            const lineNo = parseInt(card.getAttribute('data-card-line') || '1', 10);

            if (searchText.includes(query)) {
                card.classList.remove('hidden');
                totalMatches++;
                if (lineMatchCounts[lineNo] !== undefined) {
                    lineMatchCounts[lineNo]++;
                }
            } else {
                card.classList.add('hidden');
            }
        });

        if (matchBadge && matchCountEl) {
            matchCountEl.textContent = totalMatches;
            matchBadge.classList.remove('hidden');
            matchBadge.classList.add('inline-flex');
        }

        // Update line-level empty states
        document.querySelectorAll('[data-line-column]').forEach(col => {
            const lineNo = parseInt(col.getAttribute('data-line-column') || '0', 10);
            const lineNoMatchesEl = col.querySelector('.line-no-matches');
            const cardsInThisLine = col.querySelectorAll('.kanban-card');

            if (lineNoMatchesEl && cardsInThisLine.length > 0) {
                const matchesInLine = lineMatchCounts[lineNo] || 0;
                if (matchesInLine === 0) {
                    lineNoMatchesEl.classList.remove('hidden');
                } else {
                    lineNoMatchesEl.classList.add('hidden');
                }
            }
        });

        // Global empty state if 0 matches
        if (noResultsEl) {
            if (totalMatches === 0 && cards.length > 0) {
                if (noResultsQueryEl) noResultsQueryEl.textContent = rawQuery.trim();
                noResultsEl.classList.remove('hidden');
            } else {
                noResultsEl.classList.add('hidden');
            }
        }
    }

    function clearKanbanSearch() {
        const input = document.getElementById('kanbanSearchInput');
        if (input) {
            input.value = '';
            input.focus();
        }
        applyKanbanSearch('');
    }

    document.addEventListener('DOMContentLoaded', function () {
        const searchInput = document.getElementById('kanbanSearchInput');
        if (searchInput) {
            // Instant search on typing
            searchInput.addEventListener('input', function () {
                applyKanbanSearch(this.value);
            });

            // Clear on Escape
            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    clearKanbanSearch();
                }
            });

            // Initial search if value exists (e.g. from server query param)
            if (searchInput.value.trim() !== '') {
                applyKanbanSearch(searchInput.value);
            }
        }

        // Auto-Refresh Logic (120000 ms = 2 minutes)
        // Desktop / Laptop / TV Display = ON
        // Android / Mobile Operational Devices = OFF
        const isMobileDevice = (function () {
            const ua = navigator.userAgent || '';
            const isMobileUA = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(ua);
            const hasCoarsePointer = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
            const isSmallScreen = window.innerWidth < 1024;

            // Mobile phones & compact touch tablets in hand operation are detected as mobile
            return (isMobileUA && isSmallScreen) || (hasCoarsePointer && isSmallScreen);
        })();

        const badgeEl = document.getElementById('autoRefreshBadge');
        const timerEl = document.getElementById('autoRefreshTimer');

        if (isMobileDevice) {
            // Mobile device: No auto-refresh, keep badge hidden
            if (badgeEl) {
                badgeEl.classList.add('hidden');
            }
            return;
        }

        // Desktop / TV: Show indicator and activate 2-minute countdown
        if (badgeEl) {
            badgeEl.classList.remove('hidden');
        }

        let remainingSeconds = 120;

        function updateDisplay() {
            if (!timerEl) return;
            const m = Math.floor(remainingSeconds / 60);
            const s = remainingSeconds % 60;
            timerEl.textContent = `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
        }

        updateDisplay();

        const intervalId = setInterval(function () {
            // Pause auto-refresh if PPIC reorder modal is actively open
            const modal = document.getElementById('reorderModal');
            if (modal && !modal.classList.contains('hidden')) {
                return;
            }

            // Pause auto-refresh if user has active search text
            if (searchInput && searchInput.value.trim() !== '') {
                return;
            }

            remainingSeconds--;
            if (remainingSeconds <= 0) {
                clearInterval(intervalId);
                window.location.reload();
            } else {
                updateDisplay();
            }
        }, 1000);
    });
</script>
@endsection
