@extends('layouts.app')

@section('title', 'Pencatatan Kerusakan Fitting')

@section('top_bar')
<div class="flex items-center justify-between w-full">
    <div class="flex flex-col">
        <h1 class="text-base font-bold text-slate-800 leading-tight flex items-center gap-2">
            <i class="fas fa-exclamation-circle text-rose-500"></i>
            Pencatatan Kerusakan Fitting
            @if($selectedStage)
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-rose-100 text-rose-800 border border-rose-200">
                    {{ $stageLabels[$selectedStage] ?? strtoupper($selectedStage) }}
                </span>
            @endif
        </h1>
        <p class="text-slate-500 text-[11px]">
            @if($selectedStage)
                Pencatatan & koreksi defect tahap <strong class="text-slate-700">{{ $stageLabels[$selectedStage] ?? strtoupper($selectedStage) }}</strong>
            @else
                Pilih tahap produksi eksplisit untuk memulai pencatatan defect PFKTR
            @endif
        </p>
    </div>
    <div class="flex items-center gap-2">
        @if($selectedStage)
            <a href="{{ route('fitting.defects.index', ['mode' => $mode]) }}" class="bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold px-2.5 py-1 rounded-lg text-xs flex items-center gap-1.5 shadow-xs transition" title="Ganti ke tahap lain">
                <i class="fas fa-th-large text-rose-600"></i>
                Pilih Tahap Lain
            </a>
        @endif
        <a href="{{ route('fitting.qc-defects.index') }}" class="bg-blue-50 border border-blue-200 hover:bg-blue-100 text-blue-700 font-bold px-2.5 py-1 rounded-lg text-xs flex items-center gap-1.5 shadow-xs transition">
            <i class="fas fa-microscope text-blue-600"></i>
            Verifikasi QC
        </a>
        <a href="{{ route('fitting.kanban.index') }}" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold px-2.5 py-1 rounded-lg text-xs flex items-center gap-1.5 shadow-xs transition">
            <i class="fas fa-columns text-indigo-500"></i>
            Kanban Floor
        </a>
    </div>
</div>
@endsection

@section('content')
<div class="px-2 py-3 space-y-2.5">
    {{-- Flash & Error Alerts --}}
    @if(session('success'))
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-3 py-2 rounded-lg text-xs flex items-center gap-2 shadow-xs">
            <i class="fas fa-check-circle text-emerald-500 text-sm shrink-0"></i>
            <span class="font-medium">{{ session('success') }}</span>
        </div>
    @endif
    @if(session('error'))
        <div class="bg-rose-50 border border-rose-200 text-rose-800 px-3 py-2 rounded-lg text-xs flex items-center gap-2 shadow-xs">
            <i class="fas fa-exclamation-triangle text-rose-500 text-sm shrink-0"></i>
            <span class="font-medium">{{ session('error') }}</span>
        </div>
    @endif
    @if(!empty($stageError))
        <div class="bg-amber-50 border border-amber-200 text-amber-800 px-3 py-2 rounded-lg text-xs flex items-center gap-2 shadow-xs">
            <i class="fas fa-exclamation-circle text-amber-500 text-sm shrink-0"></i>
            <span class="font-medium">{{ $stageError }}</span>
        </div>
    @endif
    @if($errors->any())
        <div class="bg-rose-50 border border-rose-200 text-rose-800 px-3 py-2 rounded-lg text-xs shadow-xs">
            <div class="font-bold mb-1 flex items-center gap-1"><i class="fas fa-times-circle"></i>Validasi gagal:</div>
            <ul class="list-disc pl-5 space-y-0.5">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(empty($selectedStage))
        {{-- ================================================================= --}}
        {{-- 1. TAMPILAN AWAL: EXPLICIT STAGE SELECTION                        --}}
        {{-- ================================================================= --}}
        <div class="bg-white rounded-lg border border-slate-200 shadow-xs p-6 md:p-10 text-center max-w-4xl mx-auto my-4">
            <div class="w-14 h-14 bg-rose-50 text-rose-600 rounded-2xl flex items-center justify-center mx-auto mb-3 text-2xl border border-rose-200 shadow-xs">
                <i class="fas fa-layer-group"></i>
            </div>
            <h2 class="text-base md:text-lg font-bold text-slate-800">
                Pilih Tahap Produksi Terlebih Dahulu
            </h2>
            <p class="text-xs text-slate-500 mt-1 max-w-lg mx-auto leading-relaxed">
                Pilih tahap produksi terlebih dahulu untuk menampilkan daftar PFKTR. Pencatatan defect wajib dilakukan dalam konteks tahap yang eksplisit.
            </p>

            {{-- Grid 6 Tahap Fitting --}}
            <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 text-left">
                @foreach($stages as $index => $st)
                    @php
                        $label = $stageLabels[$st] ?? strtoupper($st);
                        $unrecordedCount = (int) ($summary['per_stage_unrecorded'][$st] ?? 0);
                        $recordedCount = (int) ($summary['per_stage_recorded'][$st] ?? 0);
                    @endphp
                    <a href="{{ route('fitting.defects.index', ['stage' => $st, 'mode' => $mode]) }}"
                       class="group p-4 rounded-lg border border-slate-200 bg-white hover:bg-rose-50/50 hover:border-rose-400 hover:shadow-sm transition flex flex-col justify-between">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-xs text-slate-800 group-hover:text-rose-700 flex items-center gap-2">
                                <span class="w-5 h-5 rounded-full bg-slate-100 group-hover:bg-rose-100 text-slate-600 group-hover:text-rose-700 text-[11px] font-mono flex items-center justify-center font-bold">
                                    {{ $index + 1 }}
                                </span>
                                <span>{{ $label }}</span>
                            </span>
                            <i class="fas fa-chevron-right text-slate-300 group-hover:text-rose-500 text-xs transition-transform group-hover:translate-x-0.5"></i>
                        </div>
                        <div class="mt-3 flex items-center justify-between text-[11px] border-t border-slate-100 pt-2.5">
                            <span class="text-slate-500">Belum Dicatat:</span>
                            <span class="font-mono font-bold {{ $unrecordedCount > 0 ? 'text-rose-600 bg-rose-50 px-1.5 py-0.5 rounded' : 'text-slate-400' }}">
                                {{ number_format($unrecordedCount) }} PFKTR
                            </span>
                        </div>
                        <div class="mt-1 flex items-center justify-between text-[11px]">
                            <span class="text-slate-400">Sudah Dicatat:</span>
                            <span class="font-mono text-slate-600 font-semibold">
                                {{ number_format($recordedCount) }} PFKTR
                            </span>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    @else
        {{-- ================================================================= --}}
        {{-- 2. SETELAH TAHAP DIPILIH: 5 KPI SUMMARY, TABS, SEARCH & TABLE     --}}
        {{-- ================================================================= --}}

        {{-- 5-KPI SUMMARY CARDS (Single Row on Desktop for Selected Stage) --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
            <!-- 1. Belum Dicatat -->
            <div class="bg-white rounded-lg border border-slate-200 px-3 py-2 shadow-xs hover:border-rose-300 transition-colors">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold text-slate-600 uppercase tracking-wider">Belum Dicatat</span>
                    <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                </div>
                <div class="flex items-baseline justify-between mt-1">
                    <span class="text-xl font-black font-mono text-rose-600 leading-none">{{ number_format($summary['unrecorded_total']) }}</span>
                    <span class="text-[10px] text-slate-400 font-medium truncate ml-1">WAITING_DEFECT</span>
                </div>
            </div>

            <!-- 2. Nihil (0 PCS) -->
            <div class="bg-white rounded-lg border border-slate-200 px-3 py-2 shadow-xs hover:border-slate-300 transition-colors">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold text-slate-600 uppercase tracking-wider">Nihil (0 PCS)</span>
                    <span class="w-2 h-2 rounded-full bg-slate-400"></span>
                </div>
                <div class="flex items-baseline justify-between mt-1">
                    <span class="text-xl font-black font-mono text-slate-700 leading-none">{{ number_format($summary['no_defect_total']) }}</span>
                    <span class="text-[10px] text-slate-400 font-medium truncate ml-1" title="{{ number_format($summary['auto_nihil_total']) }} Auto-Nihil">
                        {{ number_format($summary['auto_nihil_total']) }} Auto
                    </span>
                </div>
            </div>

            <!-- 3. Menunggu QC -->
            <div class="bg-white rounded-lg border border-slate-200 px-3 py-2 shadow-xs hover:border-amber-300 transition-colors">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold text-slate-600 uppercase tracking-wider">Menunggu QC</span>
                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                </div>
                <div class="flex items-baseline justify-between mt-1">
                    <span class="text-xl font-black font-mono text-amber-600 leading-none">{{ number_format($summary['waiting_qc_total']) }}</span>
                    <span class="text-[10px] text-slate-400 font-medium truncate ml-1">&gt; 0 PCS</span>
                </div>
            </div>

            <!-- 4. Reopen QC -->
            <div class="bg-white rounded-lg border border-slate-200 px-3 py-2 shadow-xs hover:border-purple-300 transition-colors">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold text-slate-600 uppercase tracking-wider">Reopen QC</span>
                    <span class="w-2 h-2 rounded-full bg-purple-500"></span>
                </div>
                <div class="flex items-baseline justify-between mt-1">
                    <span class="text-xl font-black font-mono text-purple-600 leading-none">{{ number_format($summary['reopened_total']) }}</span>
                    <span class="text-[10px] text-purple-600 font-medium truncate ml-1">Koreksi PPIC</span>
                </div>
            </div>

            <!-- 5. Terverifikasi QC -->
            <div class="bg-white rounded-lg border border-slate-200 px-3 py-2 shadow-xs hover:border-emerald-300 transition-colors">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold text-slate-600 uppercase tracking-wider">Terverifikasi QC</span>
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                </div>
                <div class="flex items-baseline justify-between mt-1">
                    <span class="text-xl font-black font-mono text-emerald-600 leading-none">{{ number_format($summary['confirmed_total']) }}</span>
                    <span class="text-[10px] text-slate-400 font-medium truncate ml-1">CONFIRMED</span>
                </div>
            </div>
        </div>

        {{-- MAIN CARD: TABS, SEARCH, STAGE PILLS & WORK QUEUE TABLE --}}
        <div class="bg-white rounded-lg border border-slate-200 shadow-xs overflow-hidden">
            {{-- Tabs & Search Toolbar --}}
            <div class="border-b border-slate-200 px-3 py-1.5 flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-1">
                    <a href="{{ route('fitting.defects.index', array_filter(['mode' => 'unrecorded', 'stage' => $selectedStage, 'search' => $search])) }}"
                       class="px-3 py-1.5 text-xs font-bold border-b-2 transition flex items-center gap-1.5 {{ $mode === 'unrecorded' ? 'border-rose-600 text-rose-700 bg-rose-50/60 rounded-t' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
                        <i class="fas fa-clock {{ $mode === 'unrecorded' ? 'text-rose-600' : 'text-slate-400' }}"></i>
                        <span>BELUM DICATAT</span>
                        <span class="px-1.5 py-0.2 text-[10px] font-black rounded-full {{ $mode === 'unrecorded' ? 'bg-rose-600 text-white' : 'bg-slate-200 text-slate-700' }}">{{ $summary['unrecorded_total'] }}</span>
                    </a>
                    <a href="{{ route('fitting.defects.index', array_filter(['mode' => 'recorded', 'stage' => $selectedStage, 'search' => $search])) }}"
                       class="px-3 py-1.5 text-xs font-bold border-b-2 transition flex items-center gap-1.5 {{ $mode === 'recorded' ? 'border-indigo-600 text-indigo-700 bg-indigo-50/60 rounded-t' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
                        <i class="fas fa-clipboard-check {{ $mode === 'recorded' ? 'text-indigo-600' : 'text-slate-400' }}"></i>
                        <span>SUDAH DICATAT</span>
                        <span class="px-1.5 py-0.2 text-[10px] font-black rounded-full {{ $mode === 'recorded' ? 'bg-indigo-600 text-white' : 'bg-slate-200 text-slate-700' }}">{{ $summary['recorded_total'] }}</span>
                    </a>
                </div>

                <form method="GET" action="{{ route('fitting.defects.index') }}" class="flex items-center gap-1.5">
                    <input type="hidden" name="mode" value="{{ $mode }}">
                    <input type="hidden" name="stage" value="{{ $selectedStage }}">
                    <div class="relative">
                        <input type="text" name="search" value="{{ $search }}"
                               placeholder="Cari PFKTR / Heat / Item / Customer..."
                               class="w-52 md:w-64 rounded-md border border-slate-300 pl-7 pr-2.5 py-1 text-xs focus:ring-1 focus:ring-rose-500 focus:border-rose-500">
                        <i class="fas fa-search absolute left-2.5 top-2 text-slate-400 text-xs"></i>
                    </div>
                    <button type="submit" class="px-2.5 py-1 rounded-md bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold transition">
                        Cari
                    </button>
                    @if($search !== '')
                        <a href="{{ route('fitting.defects.index', ['stage' => $selectedStage, 'mode' => $mode]) }}" class="px-2 py-1 rounded-md border border-slate-300 text-slate-600 text-xs hover:bg-slate-50 font-medium">
                            Reset
                        </a>
                    @endif
                </form>
            </div>

            {{-- Stage Filter Pills (STRICTLY 6 Stages, ZERO "Semua Tahap") --}}
            <div class="px-3 py-1.5 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center justify-between gap-1.5 text-xs">
                @php
                    $stageCounts = $mode === 'unrecorded' ? $summary['per_stage_unrecorded'] : $summary['per_stage_recorded'];
                @endphp
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="text-slate-400 font-bold text-[11px] mr-1">Tahap:</span>
                    @foreach($stages as $st)
                        @php
                            $cnt = (int) ($stageCounts[$st] ?? 0);
                            $isActive = ($selectedStage === $st);
                        @endphp
                        <a href="{{ route('fitting.defects.index', array_filter(['mode' => $mode, 'stage' => $st, 'search' => $search])) }}"
                           class="px-2.5 py-0.5 rounded-full text-[11px] font-bold border transition flex items-center gap-1 {{ $isActive ? 'bg-rose-600 text-white border-rose-600 shadow-xs' : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-100' }}">
                            <span>{{ $stageLabels[$st] ?? strtoupper($st) }}</span>
                            <span class="{{ $isActive ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-700' }} text-[10px] px-1 rounded-full font-black">({{ $cnt }})</span>
                        </a>
                    @endforeach
                </div>
                <a href="{{ route('fitting.defects.index', ['mode' => $mode]) }}" class="text-[11px] font-semibold text-slate-500 hover:text-rose-600 flex items-center gap-1 transition">
                    <i class="fas fa-th-large text-xs"></i>
                    <span>Pilih Tahap Lain</span>
                </a>
            </div>

            {{-- Executions Table --}}
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-xs">
                    <thead class="bg-slate-50 text-slate-600 text-[11px] uppercase tracking-wider font-bold">
                        <tr>
                            <th class="px-2.5 py-2 text-left font-bold">PFKTR / Heat</th>
                            <th class="px-2.5 py-2 text-left font-bold">Item & Customer</th>
                            <th class="px-2.5 py-2 text-left font-bold">Tahap & Scan Fisik</th>
                            <th class="px-2.5 py-2 text-right font-bold">Transfer</th>
                            <th class="px-2.5 py-2 text-right font-bold">Input Efektif</th>
                            <th class="px-2.5 py-2 text-right font-bold">Defect</th>
                            <th class="px-2.5 py-2 text-right font-bold">Good Efektif</th>
                            <th class="px-2.5 py-2 text-left font-bold">Status</th>
                            <th class="px-2.5 py-2 text-right font-bold">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse($executions as $exec)
                            @php
                                $item = $exec->fittingItem;
                                $pfktr = $item?->traveler_number ?? '-';
                                $stageLabel = $stageLabels[$exec->stage] ?? strtoupper($exec->stage);
                                $effectiveInput = (int) $exec->effective_input_qty;
                                $effectiveGood = (int) $exec->effective_good_qty;
                                $maxAllowed = (int) $exec->max_allowed_defect_qty;
                                $isLocked = (bool) $exec->is_quality_locked;
                                $scanDate = $exec->resolveDefaultInspectionDate();
                                $logsPayload = $exec->defectLogs->map(fn ($l) => [
                                    'at' => $l->created_at?->format('d/m/Y H:i:s'),
                                    'actor' => $l->is_system_action ? 'SISTEM (AUTO-NIHIL)' : ($l->user?->name ?? 'User'),
                                    'added_qty' => (int) $l->added_qty,
                                    'previous_total' => (int) $l->previous_total,
                                    'new_total' => (int) $l->new_total,
                                    'notes' => $l->notes ?? '-',
                                    'qc_snapshot' => $l->qc_snapshot_json,
                                ])->values()->all();
                            @endphp
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                {{-- PFKTR / Heat --}}
                                <td class="px-2.5 py-1.5 align-top whitespace-nowrap">
                                    <div class="font-mono font-bold text-slate-900 text-xs">{{ $pfktr }}</div>
                                    <div class="text-[11px] text-slate-500 font-mono">Heat: {{ $item?->heat_number ?? '-' }}</div>
                                    <div class="mt-0.5 flex flex-wrap gap-1">
                                        @if($item?->line_number)
                                            <span class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-sky-100 text-sky-700">Line {{ $item->line_number }}</span>
                                        @else
                                            <span class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-slate-200 text-slate-700">Unassigned</span>
                                        @endif
                                        @if($item?->current_stage === \App\Models\FittingItem::STAGE_COMPLETED)
                                            <span class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-emerald-100 text-emerald-700">COMPLETED</span>
                                        @endif
                                    </div>
                                </td>

                                {{-- Item & Customer --}}
                                <td class="px-2.5 py-1.5 align-top">
                                    <div class="font-semibold text-slate-800 text-xs leading-snug">{{ $item?->item_name ?? '-' }}</div>
                                    <div class="text-[11px] text-slate-500 truncate max-w-xs">{{ $item?->production_code ?? '-' }} &middot; {{ $item?->customer ?? '-' }}</div>
                                </td>

                                {{-- Tahap & Scan Fisik --}}
                                <td class="px-2.5 py-1.5 align-top whitespace-nowrap">
                                    <span class="inline-flex px-1.5 py-0.5 rounded text-[11px] font-bold bg-slate-100 text-slate-700 border border-slate-200">{{ $stageLabel }}</span>
                                    <div class="text-[10px] text-slate-500 mt-0.5">
                                        <i class="far fa-clock mr-1 text-slate-400"></i>{{ $exec->physical_done_at?->format('d/m/y H:i') ?? '-' }}
                                    </div>
                                    <div class="text-[10px] text-slate-400">SPV: {{ $exec->operator?->name ?? '-' }}</div>
                                </td>

                                {{-- Transfer --}}
                                <td class="px-2.5 py-1.5 text-right font-mono text-slate-600 align-top text-xs">{{ number_format((int) ($item?->qty_transferred ?? 0)) }}</td>

                                {{-- Input Efektif --}}
                                <td class="px-2.5 py-1.5 text-right font-mono font-bold text-slate-800 align-top text-xs">
                                    {{ number_format($effectiveInput) }}
                                    @if($effectiveInput !== (int) $exec->input_qty)
                                        <div class="text-[9px] text-slate-400 font-normal" title="Snapshot saat scan fisik">Scan: {{ number_format((int) $exec->input_qty) }}</div>
                                    @endif
                                </td>

                                {{-- Defect --}}
                                <td class="px-2.5 py-1.5 text-right font-mono font-black {{ (int) $exec->defect_qty > 0 ? 'text-rose-600' : 'text-slate-500' }} align-top text-xs">
                                    {{ number_format((int) $exec->defect_qty) }}
                                </td>

                                {{-- Good Efektif --}}
                                <td class="px-2.5 py-1.5 text-right font-mono font-black text-emerald-700 align-top text-xs">{{ number_format($effectiveGood) }}</td>

                                {{-- Status & Badges --}}
                                <td class="px-2.5 py-1.5 align-top space-y-0.5 whitespace-nowrap">
                                    @if($exec->status === \App\Models\FittingStageExecution::STATUS_WAITING_DEFECT)
                                        <span class="inline-flex items-center px-1.5 py-0.2 rounded-full text-[10px] font-bold bg-rose-100 text-rose-700">WAITING DEFECT</span>
                                    @elseif($exec->status === \App\Models\FittingStageExecution::STATUS_NO_DEFECT)
                                        <span class="inline-flex items-center px-1.5 py-0.2 rounded-full text-[10px] font-bold bg-slate-100 text-slate-700">NO DEFECT (0 PCS)</span>
                                    @elseif($exec->status === \App\Models\FittingStageExecution::STATUS_WAITING_QC)
                                        <span class="inline-flex items-center px-1.5 py-0.2 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">WAITING QC</span>
                                    @elseif($exec->status === \App\Models\FittingStageExecution::STATUS_REOPENED_FOR_CORRECTION)
                                        <span class="inline-flex items-center px-1.5 py-0.2 rounded-full text-[10px] font-bold bg-purple-100 text-purple-800">REOPEN QC (KOREKSI)</span>
                                    @elseif($exec->status === \App\Models\FittingStageExecution::STATUS_CONFIRMED)
                                        <span class="inline-flex items-center px-1.5 py-0.2 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">CONFIRMED</span>
                                    @endif
                                    @if($exec->is_auto_nihil)
                                        <div><span class="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-bold bg-indigo-100 text-indigo-700"><i class="fas fa-robot mr-1"></i>AUTO-NIHIL</span></div>
                                    @elseif($exec->auto_nihil_at)
                                        <div><span class="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200" title="Pernah diproses Auto-Nihil pada {{ $exec->auto_nihil_at->format('d/m/Y H:i') }}"><i class="fas fa-history mr-1"></i>Pernah Auto-Nihil</span></div>
                                    @endif
                                    @if($isLocked)
                                        <div><span class="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-bold bg-slate-800 text-white"><i class="fas fa-lock mr-1"></i>QUALITY LOCK</span></div>
                                    @endif
                                    @if($exec->notes)
                                        <div class="text-[10px] text-slate-500 max-w-xs truncate" title="{{ $exec->notes }}">{{ $exec->notes }}</div>
                                    @endif
                                </td>

                                {{-- Actions --}}
                                <td class="px-2.5 py-1.5 text-right align-top whitespace-nowrap">
                                    <div class="inline-flex flex-col items-end gap-1">
                                        @if($isLocked)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded bg-slate-100 text-slate-500 text-[11px] font-medium cursor-not-allowed" title="Terkunci oleh Final Quality Lock (Gudang Jadi PF selesai)">
                                                <i class="fas fa-lock mr-1"></i>Terkunci
                                            </span>
                                        @elseif($exec->status === \App\Models\FittingStageExecution::STATUS_WAITING_DEFECT)
                                            <button type="button"
                                                    onclick="openRecordModal({{ $exec->id }}, @js($pfktr), @js($stageLabel), {{ $effectiveInput }}, {{ $maxAllowed }}, @js($exec->notes ?? ''), @js($scanDate))"
                                                    class="inline-flex items-center px-2.5 py-1 rounded bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-xs transition">
                                                <i class="fas fa-edit mr-1"></i>Catat Defect
                                            </button>
                                        @elseif($exec->status === \App\Models\FittingStageExecution::STATUS_CONFIRMED)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200 text-[11px] font-medium" title="Sudah terverifikasi QC. Admin QC harus melakukan Reopen terlebih dahulu untuk koreksi.">
                                                <i class="fas fa-shield-alt mr-1"></i>Perlu Reopen QC
                                            </span>
                                        @else
                                            <button type="button"
                                                    onclick="openAdjustModal({{ $exec->id }}, @js($pfktr), @js($stageLabel), {{ (int) $exec->defect_qty }}, {{ $maxAllowed }}, @js($exec->status))"
                                                    class="inline-flex items-center px-2.5 py-1 rounded {{ $exec->status === \App\Models\FittingStageExecution::STATUS_REOPENED_FOR_CORRECTION ? 'bg-purple-600 hover:bg-purple-700' : 'bg-indigo-600 hover:bg-indigo-700' }} text-white text-xs font-bold shadow-xs transition">
                                                <i class="fas fa-sliders-h mr-1"></i>{{ $exec->status === \App\Models\FittingStageExecution::STATUS_REOPENED_FOR_CORRECTION ? 'Koreksi Reopen' : 'Sesuaikan (+/-)' }}
                                            </button>
                                        @endif

                                        <button type="button"
                                                onclick='openLogsModal(@js($pfktr), @js($stageLabel), @json($logsPayload))'
                                                class="inline-flex items-center px-2 py-0.5 rounded border border-slate-200 hover:bg-slate-100 text-slate-600 text-[10px] font-medium transition">
                                                <i class="fas fa-history mr-1"></i>Log ({{ count($logsPayload) }})
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-8 text-center text-slate-400">
                                    <i class="fas fa-inbox text-2xl mb-1.5 block"></i>
                                    Tidak ada data eksekusi Fitting pada tahap {{ $stageLabels[$selectedStage] ?? strtoupper($selectedStage) }} dengan filter ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($executions->hasPages())
                <div class="px-3 py-2 border-t border-slate-200 bg-slate-50">
                    {{ $executions->links() }}
                </div>
            @endif
        </div>
    @endif
</div>

@if($selectedStage)
{{-- Modal: Record Initial Defect --}}
<div id="recordModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 flex items-center justify-center p-3">
    <div class="bg-white rounded-xl shadow-xl max-w-md w-full overflow-hidden">
        <div class="px-4 py-2.5 bg-rose-600 text-white flex items-center justify-between">
            <h3 class="font-bold text-sm flex items-center gap-1.5"><i class="fas fa-exclamation-circle"></i>Catat Defect Fitting</h3>
            <button type="button" onclick="closeModal('recordModal')" class="text-white/80 hover:text-white"><i class="fas fa-times text-xs"></i></button>
        </div>
        <form id="recordForm" method="POST" class="p-3.5 space-y-2.5">
            @csrf
            <input type="hidden" name="idempotency_key" id="recordIdempotencyKey">
            <input type="hidden" name="stage" value="{{ $selectedStage }}">
            <input type="hidden" name="execution_id" id="recExecutionId" value="{{ old('execution_id') }}">
            <input type="hidden" name="pfktr" id="recPfktrInput" value="{{ old('pfktr') }}">
            <input type="hidden" name="stage_label" id="recStageLabelInput" value="{{ old('stage_label') }}">
            <input type="hidden" name="effective_input" id="recEffectiveInputInput" value="{{ old('effective_input') }}">
            <input type="hidden" name="max_allowed" id="recMaxAllowedInput" value="{{ old('max_allowed') }}">
            <input type="hidden" name="scan_date" id="recScanDateInput" value="{{ old('scan_date') }}">
            <div class="bg-slate-50 rounded-lg p-2 text-xs border border-slate-200 grid grid-cols-2 gap-x-2 gap-y-1">
                <div><span class="text-slate-500">PFKTR:</span> <strong id="recPfktr" class="font-mono text-slate-800"></strong></div>
                <div><span class="text-slate-500">Tahap:</span> <strong id="recStage" class="text-slate-800"></strong></div>
                <div><span class="text-slate-500">Input Efektif:</span> <strong id="recEffectiveInput" class="font-mono text-slate-800"></strong> PCS</div>
                <div><span class="text-slate-500">Maks Defect:</span> <strong id="recMaxAllowed" class="font-mono text-rose-600"></strong> PCS</div>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-0.5">Jumlah Defect (PCS) <span class="text-rose-500">*</span></label>
                <input type="number" name="defect_qty" id="recDefectQty" min="0" required
                       class="w-full rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-mono focus:ring-1 focus:ring-rose-500 focus:border-rose-500">
                <p class="text-[10px] text-slate-400 mt-0.5">Isi 0 jika seluruh kuantitas dinyatakan baik (nihil defect / NO_DEFECT).</p>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-0.5">Tanggal Pemeriksaan (Opsional)</label>
                <input type="date" name="inspection_date" id="recInspectionDate" max="{{ now('Asia/Jakarta')->format('Y-m-d') }}"
                       class="w-full rounded-md border border-slate-300 px-2.5 py-1 text-xs">
                <p class="text-[10px] text-slate-400 mt-0.5">Otomatis terisi tanggal scan fisik, dapat diubah jika pemeriksaan dilakukan pada tanggal lain.</p>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-0.5">Catatan (Opsional)</label>
                <textarea name="notes" id="recNotes" rows="2" maxlength="500"
                          class="w-full rounded-md border border-slate-300 px-2.5 py-1.5 text-xs" placeholder="Catatan hasil pemeriksaan fisik..."></textarea>
            </div>
            <div class="flex justify-end gap-1.5 pt-1 border-t border-slate-100">
                <button type="button" onclick="closeModal('recordModal')" class="px-3 py-1.5 rounded-md border border-slate-300 text-slate-700 text-xs font-medium hover:bg-slate-50">Batal</button>
                <button type="submit" class="px-3 py-1.5 rounded-md bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold transition">Simpan Defect</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal: Adjust Defect (+/-) --}}
<div id="adjustModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 flex items-center justify-center p-3">
    <div class="bg-white rounded-xl shadow-xl max-w-md w-full overflow-hidden">
        <div class="px-4 py-2.5 bg-indigo-600 text-white flex items-center justify-between">
            <h3 class="font-bold text-sm flex items-center gap-1.5"><i class="fas fa-sliders-h"></i>Penyesuaian Defect (+/-)</h3>
            <button type="button" onclick="closeModal('adjustModal')" class="text-white/80 hover:text-white"><i class="fas fa-times text-xs"></i></button>
        </div>
        <form id="adjustForm" method="POST" class="p-3.5 space-y-2.5">
            @csrf
            <input type="hidden" name="idempotency_key" id="adjustIdempotencyKey">
            <input type="hidden" name="stage" value="{{ $selectedStage }}">
            <div class="bg-slate-50 rounded-lg p-2 text-xs border border-slate-200 grid grid-cols-2 gap-x-2 gap-y-1">
                <div><span class="text-slate-500">PFKTR:</span> <strong id="adjPfktr" class="font-mono text-slate-800"></strong> (<span id="adjStage"></span>)</div>
                <div><span class="text-slate-500">Defect Saat Ini:</span> <strong id="adjCurrentDefect" class="font-mono text-rose-600"></strong> PCS</div>
                <div><span class="text-slate-500">Maks Total:</span> <strong id="adjMaxAllowed" class="font-mono text-slate-800"></strong> PCS</div>
            </div>
            <div id="adjReopenWarn" class="hidden bg-purple-50 border border-purple-200 text-purple-900 rounded-lg p-2 text-[11px] leading-relaxed">
                <i class="fas fa-undo mr-1 text-purple-700"></i>
                Eksekusi ini <strong>dibuka kembali (Reopen) oleh QC</strong>. Masukkan delta koreksi (mis. <code>-5</code> untuk menjadi 0 PCS, atau isi <code>0</code> jika kuantitas defect tetap) beserta <strong>alasan wajib</strong>.
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-0.5">Delta Penyesuaian (+/- PCS) <span class="text-rose-500">*</span></label>
                <input type="number" name="adjustment_qty" id="adjDeltaQty" required
                       class="w-full rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-mono focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500"
                       placeholder="Contoh: +2, -1, atau 0 (khusus Reopen QC)">
                <p id="adjDeltaHint" class="text-[10px] text-slate-400 mt-0.5">Nilai positif (+) menambah defect, nilai negatif (-) mengurangi defect (alasan wajib diisi).</p>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-0.5">Alasan / Catatan <span id="adjNoteReq" class="text-rose-500 hidden">*</span></label>
                <textarea name="notes" id="adjNotes" rows="2" maxlength="1000"
                          class="w-full rounded-md border border-slate-300 px-2.5 py-1.5 text-xs" placeholder="Wajib diisi untuk pengurangan defect atau koreksi pasca Reopen QC..."></textarea>
            </div>
            <div class="flex justify-end gap-1.5 pt-1 border-t border-slate-100">
                <button type="button" onclick="closeModal('adjustModal')" class="px-3 py-1.5 rounded-md border border-slate-300 text-slate-700 text-xs font-medium hover:bg-slate-50">Batal</button>
                <button type="submit" class="px-3 py-1.5 rounded-md bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold transition">Simpan Penyesuaian</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal: Audit Log --}}
<div id="logsModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 flex items-center justify-center p-3">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full overflow-hidden">
        <div class="px-4 py-2.5 bg-slate-800 text-white flex items-center justify-between">
            <h3 class="font-bold text-sm flex items-center gap-1.5"><i class="fas fa-history"></i>Audit Log Defect — <span id="logTitle" class="font-mono"></span></h3>
            <button type="button" onclick="closeModal('logsModal')" class="text-white/80 hover:text-white"><i class="fas fa-times text-xs"></i></button>
        </div>
        <div id="logsBody" class="p-3.5 max-h-80 overflow-y-auto space-y-2 text-xs"></div>
        <div class="px-4 py-2 bg-slate-50 border-t border-slate-200 flex justify-end">
            <button type="button" onclick="closeModal('logsModal')" class="px-3 py-1 rounded-md bg-slate-800 text-white text-xs font-bold">Tutup</button>
        </div>
    </div>
</div>

<script>
function genIdemKey(prefix, execId) {
    return prefix + '-' + execId + '-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
}
function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}
function openRecordModal(execId, pfktr, stageLabel, effectiveInput, maxAllowed, existingNotes, defaultDate) {
    document.getElementById('recordForm').action = "{{ url('/fitting/defects') }}/" + execId + "/record";
    document.getElementById('recordIdempotencyKey').value = genIdemKey('fit-rec', execId);
    document.getElementById('recExecutionId').value = execId;
    document.getElementById('recPfktrInput').value = pfktr;
    document.getElementById('recStageLabelInput').value = stageLabel;
    document.getElementById('recEffectiveInputInput').value = effectiveInput;
    document.getElementById('recMaxAllowedInput').value = maxAllowed;
    document.getElementById('recScanDateInput').value = defaultDate || '';
    document.getElementById('recPfktr').textContent = pfktr;
    document.getElementById('recStage').textContent = stageLabel;
    document.getElementById('recEffectiveInput').textContent = effectiveInput;
    document.getElementById('recMaxAllowed').textContent = maxAllowed;
    const qtyInput = document.getElementById('recDefectQty');
    qtyInput.value = 0;
    qtyInput.max = maxAllowed;
    document.getElementById('recNotes').value = existingNotes || '';

    const dateInput = document.getElementById('recInspectionDate');
    if (dateInput) {
        dateInput.value = defaultDate || '';
    }

    document.getElementById('recordModal').classList.remove('hidden');
}
function openAdjustModal(execId, pfktr, stageLabel, currentDefect, maxAllowed, status) {
    document.getElementById('adjustForm').action = "{{ url('/fitting/defects') }}/" + execId + "/adjust";
    document.getElementById('adjustIdempotencyKey').value = genIdemKey('fit-adj', execId);
    document.getElementById('adjPfktr').textContent = pfktr;
    document.getElementById('adjStage').textContent = stageLabel;
    document.getElementById('adjCurrentDefect').textContent = currentDefect;
    document.getElementById('adjMaxAllowed').textContent = maxAllowed;
    document.getElementById('adjDeltaQty').value = status === 'REOPENED_FOR_CORRECTION' ? '0' : '';
    document.getElementById('adjNotes').value = '';
    const warn = document.getElementById('adjReopenWarn');
    const reqMark = document.getElementById('adjNoteReq');
    if (status === 'REOPENED_FOR_CORRECTION') {
        warn.classList.remove('hidden');
        reqMark.classList.remove('hidden');
        document.getElementById('adjNotes').required = true;
    } else {
        warn.classList.add('hidden');
        reqMark.classList.add('hidden');
        document.getElementById('adjNotes').required = false;
    }
    document.getElementById('adjustModal').classList.remove('hidden');
}
function openLogsModal(pfktr, stageLabel, logs) {
    document.getElementById('logTitle').textContent = pfktr + ' (' + stageLabel + ')';
    const body = document.getElementById('logsBody');
    if (!logs || logs.length === 0) {
        body.innerHTML = '<div class="text-slate-400 text-center py-5 text-xs">Belum ada catatan audit log defect.</div>';
    } else {
        body.innerHTML = logs.map(l => {
            const sign = l.added_qty > 0 ? '+' + l.added_qty : String(l.added_qty);
            let snapHtml = '';
            if (l.qc_snapshot && l.qc_snapshot.previous_defects) {
                const defs = l.qc_snapshot.previous_defects.map(d => escapeHtml(d.defect_type_name) + ': ' + d.qty + ' PCS').join(', ') || '-';
                snapHtml = '<div class="mt-1.5 p-1.5 rounded bg-purple-50 border border-purple-200 text-[10px] text-purple-900">' +
                    '<strong>Arsip Verifikasi QC:</strong> ' + escapeHtml(l.qc_snapshot.previous_qc_verified_by_name || '-') +
                    ' (' + escapeHtml(l.qc_snapshot.previous_qc_verified_at || '-') + ')<br>' +
                    '<strong>Rincian:</strong> ' + defs +
                '</div>';
            }
            return '<div class="border border-slate-200 rounded-lg p-2.5 bg-slate-50">' +
                '<div class="flex items-center justify-between font-semibold text-slate-700 text-xs">' +
                    '<span>' + escapeHtml(l.actor) + '</span>' +
                    '<span class="text-slate-400 font-mono text-[10px]">' + escapeHtml(l.at || '-') + '</span>' +
                '</div>' +
                '<div class="mt-0.5 font-mono text-slate-600 text-xs">Delta: <strong>' + sign + '</strong> &middot; ' + l.previous_total + ' &rarr; <strong>' + l.new_total + ' PCS</strong></div>' +
                '<div class="mt-0.5 text-slate-500 text-[11px]">' + escapeHtml(l.notes || '-') + '</div>' +
                snapHtml +
            '</div>';
        }).join('');
    }
    document.getElementById('logsModal').classList.remove('hidden');
}
function escapeHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

@if($errors->any() && old('execution_id'))
document.addEventListener('DOMContentLoaded', function() {
    const oldExecId = @json(old('execution_id'));
    const oldPfktr = @json(old('pfktr'));
    const oldStageLabel = @json(old('stage_label'));
    const oldEffectiveInput = @json(old('effective_input'));
    const oldMaxAllowed = @json(old('max_allowed'));
    const oldNotes = @json(old('notes'));
    const oldInspDate = @json(old('inspection_date'));
    const oldScanDate = @json(old('scan_date'));
    const oldDefectQty = @json(old('defect_qty'));

    openRecordModal(oldExecId, oldPfktr, oldStageLabel, oldEffectiveInput, oldMaxAllowed, oldNotes, oldInspDate || oldScanDate);
    if (oldDefectQty !== null && oldDefectQty !== undefined) {
        document.getElementById('recDefectQty').value = oldDefectQty;
    }
});
@endif
</script>
@endif
@endsection
