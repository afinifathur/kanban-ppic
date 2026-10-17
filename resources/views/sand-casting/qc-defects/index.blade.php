@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between">
        <div class="flex flex-col">
            <h1 class="text-base font-bold text-slate-800 leading-tight flex items-center gap-2">
                <i class="fas fa-microscope text-blue-600"></i>
                VERIFIKASI KERUSAKAN (QC)
            </h1>
            <p class="text-slate-500 text-[11px]">Verifikasi detail breakdown defect Sand Casting sebelum proses dilanjutkan.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('sand-casting.defects.index') }}" class="bg-amber-50 border border-amber-200 hover:bg-amber-100 text-amber-800 font-bold px-2.5 py-1 rounded-lg text-xs flex items-center gap-1.5 shadow-xs transition">
                <i class="fas fa-tools text-amber-500"></i>
                Pencatatan PPIC
            </a>
            <a href="{{ route('sand-casting.kanban.index') }}" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold px-2.5 py-1 rounded-lg text-xs flex items-center gap-1.5 shadow-xs transition">
                <i class="fas fa-columns text-indigo-500"></i>
                Kanban Floor
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="flex flex-col gap-2.5">

        @if(session('success'))
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-3 py-2 rounded-lg flex items-center shadow-xs text-xs">
                <i class="fas fa-check-circle mr-2 text-emerald-500 text-sm shrink-0"></i>
                <span class="font-medium">{{ session('success') }}</span>
            </div>
        @endif

        @if(session('error'))
            <div class="bg-red-50 border border-red-200 text-red-800 px-3 py-2 rounded-lg flex items-center shadow-xs text-xs">
                <i class="fas fa-exclamation-circle mr-2 text-red-500 text-sm shrink-0"></i>
                <span class="font-medium">{{ session('error') }}</span>
            </div>
        @endif

        <!-- 1. COMPACT STATUS LINE (BLUE QC THEME) -->
        <div class="bg-white px-3.5 py-2 rounded-lg border border-slate-200 shadow-xs flex flex-wrap items-center justify-between gap-2 text-xs text-slate-600">
            <div class="flex items-center gap-2 flex-wrap">
                <span class="inline-flex items-center gap-1 text-slate-800">
                    <span class="w-2 h-2 rounded-full bg-blue-600"></span>
                    <strong class="font-black text-slate-900">{{ number_format($summary['unrecorded_count'] ?? $summary['waiting_qc_count'] ?? 0) }} KTR</strong> belum dicatat
                </span>
                <span class="text-slate-300">·</span>
                <span class="inline-flex items-center gap-1 text-slate-800">
                    <strong class="font-black text-blue-700">{{ number_format($summary['waiting_qc_defect_pcs'] ?? 0) }} PCS</strong> defect
                </span>
                <span class="text-slate-300">·</span>
                <span class="inline-flex items-center gap-1 text-emerald-700">
                    <strong class="font-black">{{ number_format($summary['recorded_count'] ?? 0) }} KTR</strong> sudah dicatat ({{ number_format($summary['recorded_defect_pcs'] ?? 0) }} pcs defect)
                </span>
                <span class="text-slate-300">·</span>
                <span class="text-slate-500">
                    Terverifikasi hari ini: <span class="font-semibold text-slate-700">{{ number_format($summary['today_verified_count'] ?? 0) }}</span>
                </span>
            </div>
            <div class="text-[11px] text-slate-400 font-medium">
                @if($mode === 'recorded')
                    <i class="fas fa-history mr-1 text-emerald-600"></i>Monitoring · Defect Terbaru &rarr; Terlama
                @else
                    <i class="fas fa-sort-amount-down-alt mr-1 text-blue-600"></i>FIFO Antrian · Terlama &rarr; Terbaru
                @endif
            </div>
        </div>

        <!-- 2. MAIN 2-TAB BAR (BELUM CATAT VS SUDAH CATAT) -->
        <div class="bg-white border-b border-slate-200 px-2 rounded-t-lg shadow-xs flex flex-wrap items-center justify-between gap-2">
            <nav class="flex space-x-2 overflow-x-auto py-1.5" aria-label="Main Tabs">
                <!-- Tab 1: Belum Catat -->
                <a href="{{ route('sand-casting.qc-defects.index', array_merge(request()->query(), ['mode' => 'unrecorded', 'page' => 1])) }}"
                    class="whitespace-nowrap py-1.5 px-3.5 border-b-2 font-bold text-xs flex items-center gap-2 transition-all {{ $mode === 'unrecorded' ? 'border-blue-600 text-blue-700 bg-blue-50/70 rounded-t' : 'border-transparent text-slate-500 hover:text-slate-800 hover:border-slate-300' }}">
                    <i class="fas fa-clock text-blue-600"></i>
                    <span>BELUM CATAT</span>
                    <span class="inline-flex items-center justify-center px-1.5 py-0.2 text-[10px] font-black rounded-full {{ $mode === 'unrecorded' ? 'bg-blue-600 text-white' : 'bg-slate-200 text-slate-700' }}">
                        {{ $summary['unrecorded_count'] ?? $summary['waiting_qc_count'] ?? 0 }}
                    </span>
                </a>

                <!-- Tab 2: Sudah Catat -->
                <a href="{{ route('sand-casting.qc-defects.index', array_merge(request()->query(), ['mode' => 'recorded', 'page' => 1])) }}"
                    class="whitespace-nowrap py-1.5 px-3.5 border-b-2 font-bold text-xs flex items-center gap-2 transition-all {{ $mode === 'recorded' ? 'border-emerald-600 text-emerald-700 bg-emerald-50/70 rounded-t' : 'border-transparent text-slate-500 hover:text-slate-800 hover:border-slate-300' }}">
                    <i class="fas fa-clipboard-check text-emerald-600"></i>
                    <span>SUDAH CATAT</span>
                    <span class="inline-flex items-center justify-center px-1.5 py-0.2 text-[10px] font-black rounded-full {{ $mode === 'recorded' ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-700' }}">
                        {{ $summary['recorded_count'] ?? 0 }}
                    </span>
                </a>
            </nav>

            <!-- Stage Filter Pills -->
            <div class="flex items-center gap-1 text-[11px] overflow-x-auto py-1">
                <span class="text-slate-400 font-bold mr-1">Tahap:</span>
                @foreach($stages as $stgKey)
                    @php
                        $stgLabel = $stageLabels[$stgKey] ?? strtoupper(str_replace('_', ' ', $stgKey));
                        $isStgActive = ($selectedStage === $stgKey);
                        $stageCounter = ($mode === 'recorded') ? ($summary['stage_recorded_counts'][$stgKey] ?? 0) : ($summary['stage_counts'][$stgKey] ?? 0);
                    @endphp
                    <a href="{{ route('sand-casting.qc-defects.index', array_merge(request()->query(), ['stage' => $stgKey, 'page' => 1])) }}"
                        class="px-2.5 py-1 rounded-md font-bold text-xs transition flex items-center gap-1.5 {{ $isStgActive ? 'bg-blue-600 text-white shadow-xs' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }}">
                        <span>{{ $stgLabel }}</span>
                        @if($stageCounter > 0)
                            <span class="text-[9px] px-1.5 py-0.2 rounded-full font-black {{ $isStgActive ? 'bg-white text-blue-700' : 'bg-slate-200 text-slate-700' }}">{{ $stageCounter }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>

        <!-- 3. COMPACT SEARCH / FILTER BAR -->
        <div class="bg-white px-3 py-2 rounded-b-lg border border-t-0 border-slate-200 shadow-xs flex items-center justify-between gap-2">
            <form method="GET" action="{{ route('sand-casting.qc-defects.index') }}" class="w-full flex items-center gap-2">
                <input type="hidden" name="mode" value="{{ $mode }}">
                @if($selectedStage)
                    <input type="hidden" name="stage" value="{{ $selectedStage }}">
                @endif
                
                <div class="relative flex-1 max-w-md">
                    <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none text-slate-400 text-xs">
                        <i class="fas fa-search"></i>
                    </div>
                    <input type="text" name="search" value="{{ $search }}"
                        class="block w-full pl-7 pr-3 py-1.5 bg-slate-50 border border-slate-200 text-slate-700 rounded-md focus:ring-blue-500 focus:border-blue-500 text-xs placeholder:text-slate-400"
                        placeholder="Cari KTR / Heat / Produk / Checkpoint...">
                </div>

                <button type="submit" class="bg-slate-800 hover:bg-slate-900 text-white font-bold text-xs px-3.5 py-1.5 rounded-md shadow-xs transition flex items-center gap-1">
                    <i class="fas fa-filter text-[10px]"></i>
                    Filter
                </button>

                @if($search || $selectedStage)
                    <a href="{{ $selectedStage ? route('sand-casting.qc-defects.index', ['mode' => $mode, 'stage' => $selectedStage]) : route('sand-casting.qc-defects.index', ['mode' => $mode]) }}" class="bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold text-xs px-2.5 py-1.5 rounded-md transition" title="Reset Search">
                        Reset
                    </a>
                @endif
            </form>
        </div>

        <!-- 4. MAIN EXCEL-LIKE WORK QUEUE TABLE -->
        <div class="bg-white rounded-lg border border-slate-200 shadow-xs overflow-hidden flex flex-col">
            @if(! empty($invalidStage))
                <div class="p-12 text-center">
                    <div class="w-14 h-14 bg-red-50 text-red-500 rounded-2xl flex items-center justify-center mx-auto mb-3 text-2xl border border-red-200 shadow-xs">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h3 class="text-sm font-bold text-slate-800">
                        Tahap '{{ $invalidStage }}' Tidak Valid
                    </h3>
                    <p class="text-xs text-slate-500 mt-1 max-w-md mx-auto leading-relaxed">
                        Tahap yang Anda pilih tidak termasuk dalam 6 tahap resmi workflow Sand Casting. Silakan pilih salah satu tahap resmi di bawah ini.
                    </p>
                    <div class="mt-4 flex flex-wrap justify-center gap-2">
                        @foreach($stages as $stageKey)
                            @php
                                $count = ($mode === 'recorded') ? ($summary['stage_recorded_counts'][$stageKey] ?? 0) : ($summary['stage_counts'][$stageKey] ?? 0);
                                $stageLabel = $stageLabels[$stageKey] ?? strtoupper(str_replace('_', ' ', $stageKey));
                            @endphp
                            <a href="{{ route('sand-casting.qc-defects.index', array_merge(request()->query(), ['stage' => $stageKey, 'page' => 1])) }}"
                                class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-blue-50 hover:border-blue-300 text-slate-700 font-bold text-xs transition flex items-center gap-1.5 shadow-xs">
                                <span>{{ $stageLabel }}</span>
                                @if($count > 0)
                                    <span class="text-[10px] px-1.5 py-0.2 bg-blue-100 text-blue-800 font-black rounded-full">{{ $count }}</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @elseif(empty($selectedStage))
                <div class="p-12 text-center">
                    <div class="w-14 h-14 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center mx-auto mb-3 text-2xl border border-blue-200 shadow-xs">
                        <i class="fas fa-hand-pointer"></i>
                    </div>
                    <h3 class="text-sm font-bold text-slate-800">
                        Pilih Tahap Produksi Terlebih Dahulu
                    </h3>
                    <p class="text-xs text-slate-500 mt-1 max-w-md mx-auto leading-relaxed">
                        Pilih salah satu tahap produksi di atas untuk menampilkan antrean verifikasi QC. Verifikasi defect wajib dilakukan dalam konteks tahap yang eksplisit.
                    </p>
                    <div class="mt-4 flex flex-wrap justify-center gap-2">
                        @foreach($stages as $stageKey)
                            @php
                                $count = ($mode === 'recorded') ? ($summary['stage_recorded_counts'][$stageKey] ?? 0) : ($summary['stage_counts'][$stageKey] ?? 0);
                                $stageLabel = $stageLabels[$stageKey] ?? strtoupper(str_replace('_', ' ', $stageKey));
                            @endphp
                            <a href="{{ route('sand-casting.qc-defects.index', array_merge(request()->query(), ['stage' => $stageKey, 'page' => 1])) }}"
                                class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-blue-50 hover:border-blue-300 text-slate-700 font-bold text-xs transition flex items-center gap-1.5 shadow-xs">
                                <span>{{ $stageLabel }}</span>
                                @if($count > 0)
                                    <span class="text-[10px] px-1.5 py-0.2 bg-blue-100 text-blue-800 font-black rounded-full">{{ $count }}</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @elseif($executions->isEmpty())
                <div class="p-12 text-center">
                    <div class="w-12 h-12 bg-slate-100 text-slate-400 rounded-full flex items-center justify-center mx-auto mb-2 text-xl">
                        @if($mode === 'recorded')
                            <i class="fas fa-clipboard-list text-emerald-500"></i>
                        @else
                            <i class="fas fa-clipboard-check text-blue-500"></i>
                        @endif
                    </div>
                    <h3 class="text-sm font-bold text-slate-700">
                        {{ $mode === 'recorded' ? 'Belum Ada Riwayat Verifikasi QC' : 'Semua Kerusakan Terverifikasi' }}
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">
                        {{ $mode === 'recorded'
                            ? 'Data breakdown defect yang sudah diverifikasi dan terkonfirmasi QC pada tahap ' . ($stageLabels[$selectedStage] ?? strtoupper($selectedStage)) . ' akan tampil di tab ini.'
                            : 'Tidak ada KTR pada tahap ' . ($stageLabels[$selectedStage] ?? strtoupper($selectedStage)) . ' yang sedang menunggu verifikasi detail defect oleh Admin QC.' }}
                    </p>
                </div>
            @else
                <!-- Table Container -->
                <div class="overflow-x-auto min-h-[350px]">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead class="bg-slate-100 text-slate-700 font-bold sticky top-0 z-10 border-b border-slate-200 text-[11px] uppercase tracking-wider select-none shadow-xs">
                            <tr>
                                <th class="py-2.5 px-3 text-center w-12 bg-slate-100">#</th>
                                <th class="py-2.5 px-3 bg-slate-100">KTR</th>
                                <th class="py-2.5 px-3 bg-slate-100">Produk</th>
                                <th class="py-2.5 px-3 bg-slate-100">Heat</th>
                                <th class="py-2.5 px-3 bg-slate-100">Tahap / Checkpoint</th>
                                <th class="py-2.5 px-3 text-right bg-slate-100">Hasil (Input)</th>
                                @if($mode === 'recorded')
                                    <th class="py-2.5 px-3 text-right bg-slate-100">Defect Terkonfirmasi</th>
                                    <th class="py-2.5 px-3 text-right bg-slate-100">Good</th>
                                    <th class="py-2.5 px-3 bg-slate-100">Rincian Defect QC</th>
                                    <th class="py-2.5 px-3 bg-slate-100">Diverifikasi Oleh</th>
                                    <th class="py-2.5 px-3 text-center bg-slate-100 w-32">Status</th>
                                @else
                                    <th class="py-2.5 px-3 text-right bg-slate-100">Total Defect</th>
                                    <th class="py-2.5 px-3 text-right bg-slate-100">Good</th>
                                    <th class="py-2.5 px-3 text-center bg-slate-100">Status Re-Verifikasi</th>
                                    <th class="py-2.5 px-3 text-center bg-slate-100 w-32">Aksi</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            @foreach($executions as $index => $card)
                                @php
                                    $rowNumber = ($executions->currentPage() - 1) * $executions->perPage() + $index + 1;
                                @endphp
                                <tr id="queue-row-{{ $card['id'] }}" class="hover:bg-blue-50/40 transition-colors {{ $card['is_reverification'] ? 'bg-amber-50/30' : ($index === 0 ? 'bg-blue-50/20' : '') }}">
                                    <!-- Row Number -->
                                    <td class="py-2 px-3 text-center font-bold text-slate-500 bg-slate-50/50">
                                        {{ $rowNumber }}
                                    </td>

                                    <!-- KTR + Aging Badge -->
                                    <td class="py-2 px-3 whitespace-nowrap">
                                        <div class="flex items-center gap-1.5">
                                            <span class="font-mono font-bold text-slate-900 bg-slate-100 px-1.5 py-0.5 rounded text-[11px]">
                                                {{ $card['traveler_number'] }}
                                            </span>
                                            @if(!empty($card['aging']['stage_aging_label']))
                                                <span class="text-[10px] font-bold px-1 py-0.2 bg-blue-50 text-blue-700 rounded border border-blue-200" title="Aging proses">
                                                    {{ $card['aging']['stage_aging_label'] }}
                                                </span>
                                            @endif
                                        </div>
                                    </td>

                                    <!-- Item Name & Code & Customer -->
                                    <td class="py-2 px-3">
                                        <div class="font-bold text-slate-800 line-clamp-1 max-w-[220px]" title="{{ $card['item_name'] }}">
                                            {{ $card['item_name'] ?? 'Item Tanpa Nama' }}
                                        </div>
                                        <div class="text-[10px] text-slate-400 font-mono flex items-center gap-1">
                                            <span>{{ $card['item_code'] ?? $card['production_code'] ?? '-' }}</span>
                                            @if($card['customer'])
                                                <span class="text-slate-300">·</span>
                                                <span class="text-slate-500">{{ $card['customer'] }}</span>
                                            @endif
                                        </div>
                                    </td>

                                    <!-- Heat Number -->
                                    <td class="py-2 px-3 font-mono font-semibold text-blue-700 whitespace-nowrap">
                                        {{ $card['heat_number'] ?? '-' }}
                                    </td>

                                    <!-- Checkpoint Code + Stage -->
                                    <td class="py-2 px-3 whitespace-nowrap">
                                        <div class="flex flex-col gap-0.5">
                                            <div class="flex items-center gap-1">
                                                <span class="font-mono font-bold text-[10px] bg-slate-100 text-slate-700 px-1.5 py-0.5 rounded border border-slate-200">
                                                    {{ $card['checkpoint_code'] }}
                                                </span>
                                                @if(!empty($card['line_number']))
                                                    <span class="text-[9px] font-bold bg-blue-50 text-blue-700 px-1 py-0.2 rounded border border-blue-200">
                                                        L{{ $card['line_number'] }}
                                                    </span>
                                                @endif
                                            </div>
                                            <span class="text-[10px] text-slate-400 font-semibold uppercase">
                                                {{ $stageLabels[$card['stage']] ?? $card['stage'] }}
                                            </span>
                                        </div>
                                    </td>

                                    <!-- Hasil Perpindahan (Input Qty) -->
                                    <td class="py-2 px-3 text-right font-semibold text-slate-800 whitespace-nowrap text-xs">
                                        {{ number_format($card['input_qty']) }} <span class="text-[10px] font-normal text-slate-400">pcs</span>
                                    </td>

                                    @if($mode === 'recorded')
                                        <!-- Defect Terkonfirmasi -->
                                        <td class="py-2 px-3 text-right whitespace-nowrap font-black {{ $card['defect_qty'] > 0 ? 'text-rose-600' : 'text-slate-500' }}">
                                            {{ number_format($card['defect_qty']) }} <span class="text-[10px] font-normal text-slate-400">pcs</span>
                                        </td>

                                        <!-- Good Qty -->
                                        <td class="py-2 px-3 text-right font-black text-emerald-600 whitespace-nowrap">
                                            {{ number_format($card['good_qty']) }} <span class="text-[10px] font-normal text-slate-400">pcs</span>
                                        </td>

                                        <!-- Rincian Defect QC -->
                                        <td class="py-2 px-3">
                                            @if(!empty($card['defects']))
                                                <div class="flex flex-wrap gap-1 max-w-[260px]">
                                                    @foreach($card['defects'] as $def)
                                                        <span class="inline-flex items-center gap-1 bg-rose-50 text-rose-800 border border-rose-200 px-1.5 py-0.5 rounded text-[10px] font-mono">
                                                            <span class="font-bold">{{ $def['defect_name'] }}:</span>
                                                            <span class="font-black text-rose-600">{{ number_format($def['qty']) }}</span>
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @else
                                                <span class="text-slate-400 text-xs italic">Nihil / 0 pcs</span>
                                            @endif
                                        </td>

                                        <!-- Diverifikasi Oleh -->
                                        <td class="py-2 px-3 whitespace-nowrap">
                                            <div class="font-bold text-slate-800 text-xs">{{ $card['qc_verified_by_name'] }}</div>
                                            <div class="text-[10px] text-slate-400 font-mono">{{ $card['qc_verified_at'] ? \Carbon\Carbon::parse($card['qc_verified_at'])->format('d/m/Y H:i') : '-' }}</div>
                                        </td>

                                        <!-- Status -->
                                        <td class="py-2 px-3 text-center whitespace-nowrap">
                                            <span class="bg-emerald-50 text-emerald-700 border border-emerald-200 font-bold px-2 py-0.5 rounded text-[10px] inline-flex items-center gap-1">
                                                <i class="fas fa-check-double text-emerald-600"></i>
                                                TERKONFIRMASI
                                            </span>
                                        </td>
                                    @else
                                        <!-- Total Defect PPIC -->
                                        <td class="py-2 px-3 text-right whitespace-nowrap font-black {{ $card['defect_qty'] > 0 ? 'text-red-600' : 'text-slate-500' }}">
                                            {{ number_format($card['defect_qty']) }} <span class="text-[10px] font-normal text-slate-400">pcs</span>
                                        </td>

                                        <!-- Good Qty -->
                                        <td class="py-2 px-3 text-right font-black text-emerald-600 whitespace-nowrap">
                                            {{ number_format($card['good_qty']) }} <span class="text-[10px] font-normal text-slate-400">pcs</span>
                                        </td>

                                        <!-- Status Re-Verifikasi / Draft Indicator -->
                                        <td id="status-cell-{{ $card['id'] }}" class="py-2 px-3 text-center whitespace-nowrap">
                                            <div class="flex flex-col items-center gap-1">
                                                @if(!empty($card['has_draft']))
                                                    <span class="bg-cyan-50 text-cyan-800 border border-cyan-300 font-bold px-2 py-0.5 rounded text-[10px] inline-flex items-center gap-1"
                                                        title="Draft breakdown tersimpan: {{ $card['draft_defect_qty'] }} dari {{ $card['defect_qty'] }} pcs">
                                                        <i class="fas fa-file-signature text-cyan-600"></i>
                                                        DRAFT {{ $card['draft_defect_qty'] }}/{{ $card['defect_qty'] }} PCS
                                                    </span>
                                                @endif

                                                @if($card['is_reverification'])
                                                    <span class="bg-amber-100 text-amber-900 border border-amber-300 font-bold px-2 py-0.5 rounded text-[10px] inline-flex items-center gap-1" title="Defect bertambah dari {{ $card['previous_defect_qty'] }} pcs menjadi {{ $card['defect_qty'] }} pcs (+{{ $card['added_defect_qty'] }} pcs)">
                                                        <i class="fas fa-exclamation-triangle text-amber-600"></i>
                                                        DEFECT BERTAMBAH (+{{ $card['added_defect_qty'] }})
                                                    </span>
                                                @elseif(empty($card['has_draft']))
                                                    <span class="bg-blue-50 text-blue-700 border border-blue-200 font-semibold px-1.5 py-0.5 rounded text-[10px]">
                                                        Verifikasi Baru
                                                    </span>
                                                @endif
                                            </div>
                                        </td>

                                        <!-- Aksi Button (Blue QC Action) -->
                                        <td id="action-cell-{{ $card['id'] }}" class="py-2 px-3 text-center whitespace-nowrap">
                                            @if(!empty($card['has_draft']))
                                                <button type="button"
                                                    onclick="openQcModal({{ json_encode($card) }})"
                                                    class="bg-cyan-700 hover:bg-cyan-800 text-white font-bold px-2.5 py-1.5 rounded-md text-xs shadow-xs transition inline-flex items-center gap-1"
                                                    title="Lanjutkan pengisian draft ({{ $card['draft_defect_qty'] }}/{{ $card['defect_qty'] }} pcs)">
                                                    <i class="fas fa-edit text-[10px]"></i>
                                                    LANJUTKAN DRAFT
                                                </button>
                                            @else
                                                <button type="button"
                                                    onclick="openQcModal({{ json_encode($card) }})"
                                                    class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-3 py-1.5 rounded-md text-xs shadow-xs transition inline-flex items-center gap-1">
                                                    <i class="fas fa-check-double text-[10px]"></i>
                                                    VERIFIKASI
                                                </button>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- Server-side Pagination Bar -->
                <div class="p-3 border-t border-slate-200 bg-slate-50 flex items-center justify-between">
                    <div class="text-xs text-slate-500">
                        Menampilkan <strong class="text-slate-800">{{ $executions->firstItem() ?? 0 }}</strong> - <strong class="text-slate-800">{{ $executions->lastItem() ?? 0 }}</strong> dari <strong class="text-slate-800">{{ $executions->total() }}</strong> baris
                    </div>
                    <div>
                        {{ $executions->links() }}
                    </div>
                </div>
            @endif
        </div>

    </div>

    <!-- 5. MODAL VERIFIKASI QC -->
    <div id="qcModal" tabindex="-1" aria-hidden="true"
        class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 h-modal md:h-full bg-slate-900/60 backdrop-blur-xs">
        <div class="relative p-4 w-full max-w-lg max-h-full">
            <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 overflow-hidden">
                
                <!-- Modal Header -->
                <div class="flex items-center justify-between p-3.5 px-4 border-b border-slate-200 bg-slate-50">
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-xs">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                        <div>
                            <h3 class="text-xs font-bold text-slate-800">Verifikasi Detail Defect (QC)</h3>
                            <p class="text-[10px] text-slate-400">Klasifikasikan rincian defect sebelum konfirmasi final.</p>
                        </div>
                    </div>
                    <button type="button" onclick="closeQcModal()"
                        class="text-slate-400 hover:text-slate-600 bg-transparent hover:bg-slate-200 rounded-lg text-xs w-6 h-6 inline-flex justify-center items-center">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <!-- Modal Form -->
                <form id="qcForm" method="POST" action="">
                    @csrf
                    <input type="hidden" name="stage" id="qc_modal_stage" value="{{ $selectedStage ?? '' }}">
                    <div class="p-4 space-y-3 text-xs">
                        
                        <!-- Re-Verification Alert Banner -->
                        <div id="reverificationAlertBanner" class="hidden bg-amber-50 border border-amber-300 rounded-lg p-2.5 text-amber-900 text-xs">
                            <div class="font-bold flex items-center gap-1.5 text-amber-800">
                                <i class="fas fa-exclamation-triangle text-amber-600"></i>
                                DEFECT BERTAMBAH — PERLU VERIFIKASI ULANG
                            </div>
                            <div id="reverificationAlertText" class="text-[11px] text-amber-700 mt-1">
                                Defect sebelumnya: <span id="alertPrevDefect"></span> pcs · Defect sekarang: <span id="alertCurDefect"></span> pcs · Tambahan: <span id="alertAddDefect"></span> pcs
                            </div>
                        </div>

                        <!-- Draft Active Alert Banner -->
                        <div id="draftActiveAlertBanner" class="hidden bg-cyan-50 border border-cyan-300 rounded-lg p-2.5 text-cyan-900 text-xs">
                            <div class="font-bold flex items-center gap-1.5 text-cyan-800">
                                <i class="fas fa-file-signature text-cyan-600"></i>
                                DRAFT BREAKDOWN TERSIMPAN
                            </div>
                            <div id="draftActiveAlertText" class="text-[11px] text-cyan-700 mt-1">
                                Tersimpan draft breakdown: <span id="alertDraftQty" class="font-bold text-cyan-900">0</span> dari <span id="alertTargetQty" class="font-bold text-cyan-900">0</span> pcs defect PPIC. Anda dapat menyimpan perubahan draft sementara atau melengkapi rincian untuk konfirmasi final.
                            </div>
                        </div>

                        <!-- Readonly Info Banner -->
                        <div class="bg-slate-50 p-3 rounded-lg border border-slate-200 text-xs space-y-1.5">
                            <div class="flex justify-between items-center">
                                <span class="text-slate-500">No. KTR:</span>
                                <span id="modalKtr" class="font-mono font-bold text-slate-800"></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-slate-500">Heat Number:</span>
                                <span id="modalHeat" class="font-mono font-semibold text-blue-700"></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-slate-500">Nama Produk:</span>
                                <span id="modalItemName" class="font-bold text-slate-800 text-right truncate max-w-[200px]"></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-slate-500">Checkpoint:</span>
                                <span id="modalCheckpoint" class="font-mono font-bold text-blue-700 bg-blue-50 px-1.5 py-0.2 rounded border border-blue-100"></span>
                            </div>
                            <div class="grid grid-cols-3 gap-2 pt-2 border-t border-slate-200 text-center">
                                <div class="bg-white p-1.5 rounded border border-slate-200">
                                    <div class="text-[10px] text-slate-400">Hasil (Input)</div>
                                    <div id="modalInputQty" class="font-black text-slate-800 text-xs"></div>
                                </div>
                                <div class="bg-red-50 p-1.5 rounded border border-red-200">
                                    <div class="text-[10px] text-red-500 font-bold">Total Defect PPIC</div>
                                    <div id="modalDefectQty" class="font-black text-red-600 text-xs"></div>
                                </div>
                                <div class="bg-emerald-50 p-1.5 rounded border border-emerald-200">
                                    <div class="text-[10px] text-emerald-600 font-bold">Good Qty</div>
                                    <div id="modalGoodQty" class="font-black text-emerald-600 text-xs"></div>
                                </div>
                            </div>
                        </div>

                        <!-- TANGGAL PEMERIKSAAN FISIK / DEFECT -->
                        <div class="bg-blue-50/60 p-2.5 rounded-lg border border-blue-200 text-xs space-y-1">
                            <label for="modal_inspection_date" class="block text-xs font-bold text-slate-800 flex items-center justify-between">
                                <span class="flex items-center gap-1.5">
                                    <i class="fas fa-calendar-day text-blue-600"></i>
                                    Tanggal Pemeriksaan Fisik / Defect <span class="text-red-500">*</span>
                                </span>
                                <span class="text-[10px] text-slate-500 font-normal">Maksimal Hari Ini</span>
                            </label>
                            <input type="date" name="inspection_date" id="modal_inspection_date" required
                                max="{{ date('Y-m-d') }}"
                                class="w-full text-xs p-2 bg-white border border-slate-300 text-slate-800 rounded-md focus:ring-blue-500 focus:border-blue-500 font-medium">
                            <p class="text-[10px] text-slate-500">
                                Tanggal aktual pemeriksaan fisik di lantai produksi (Zone 3 & pelaporan defect).
                            </p>
                        </div>

                        <!-- ZERO DEFECT SECTION -->
                        <div id="zeroDefectSection" class="hidden bg-emerald-50 border border-emerald-200 rounded-lg p-3 text-center space-y-1">
                            <div class="font-bold text-emerald-800 text-xs flex items-center justify-center gap-1.5">
                                <i class="fas fa-check-circle text-emerald-500"></i>
                                TOTAL DEFECT = 0 PCS
                            </div>
                            <p class="text-[11px] text-emerald-700">
                                Tidak diperlukan klasifikasi rincian cacat. KTR dapat langsung diverifikasi dan diteruskan ke proses berikutnya.
                            </p>
                        </div>

                        <!-- BREAKDOWN DEFECT SECTION (IF DEFECT > 0) -->
                        <div id="breakdownSection" class="space-y-2">
                            <div class="flex items-center justify-between">
                                <label class="text-xs font-bold text-slate-700">
                                    Rincian Klasifikasi Defect <span class="text-red-500">*</span>
                                </label>
                                <button type="button" onclick="addDefectRow()"
                                    class="text-blue-600 hover:text-blue-800 bg-blue-50 hover:bg-blue-100 px-2 py-1 rounded text-[11px] font-bold transition flex items-center gap-1">
                                    <i class="fas fa-plus-circle"></i> Tambah Baris
                                </button>
                            </div>

                            <!-- Dynamic Defect Rows Container -->
                            <div id="defectRowsContainer" class="space-y-1.5 max-h-48 overflow-y-auto pr-0.5">
                                <!-- Dynamic JS rows will be inserted here -->
                            </div>

                            <!-- Live Breakdown Match Indicator -->
                            <div class="bg-slate-100 p-2.5 rounded-lg border border-slate-200 flex items-center justify-between text-xs">
                                <div>
                                    <span class="text-slate-500">Alokasi Rincian:</span>
                                    <span id="allocatedCountDisplay" class="font-black ml-1 text-sm">0 / 0 PCS</span>
                                </div>
                                <div id="allocatedStatusBadge" class="text-[10px] font-bold px-2 py-0.5 rounded">
                                    Belum Lengkap
                                </div>
                            </div>
                        </div>

                        <!-- Defect History Accordion / List for QC -->
                        <div id="qcDefectLogsContainer" class="hidden pt-1">
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">
                                <i class="fas fa-history text-slate-400 mr-1"></i>Defect History (Readonly):
                            </label>
                            <div class="max-h-24 overflow-y-auto border border-slate-200 rounded-md bg-slate-50">
                                <table class="w-full text-left text-[10px]">
                                    <thead class="bg-slate-100 text-slate-600 font-bold border-b border-slate-200">
                                        <tr>
                                            <th class="py-1 px-2">Tanggal/Waktu</th>
                                            <th class="py-1 px-2">User</th>
                                            <th class="py-1 px-2 text-right">Penambahan</th>
                                            <th class="py-1 px-2 text-right">Sebelum</th>
                                            <th class="py-1 px-2 text-right">Sesudah</th>
                                        </tr>
                                    </thead>
                                    <tbody id="qcDefectLogsTbody" class="divide-y divide-slate-100 font-mono">
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Catatan QC (Optional) -->
                        <div>
                            <label for="qc_notes" class="block text-xs font-bold text-slate-700 mb-1">
                                Catatan Verifikasi QC (Opsional)
                            </label>
                            <textarea name="notes" id="qc_notes" rows="2"
                                class="w-full text-xs p-2 bg-white border border-slate-300 text-slate-800 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                                placeholder="Tambahkan keterangan temuan QC jika diperlukan..."></textarea>
                        </div>

                    </div>

                    <!-- Modal Footer -->
                    <div class="flex items-center justify-end gap-2 p-3 px-4 border-t border-slate-200 bg-slate-50 rounded-b-xl">
                        <button type="button" onclick="closeQcModal()"
                            class="px-3.5 py-1.5 text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-md hover:bg-slate-100 transition">
                            Batal
                        </button>
                        <button type="button" id="submitDraftBtn" onclick="submitQcDraft()"
                            class="px-3.5 py-1.5 text-xs font-bold text-cyan-800 bg-cyan-100 hover:bg-cyan-200 border border-cyan-300 rounded-md shadow-xs transition flex items-center gap-1.5 disabled:opacity-50 disabled:cursor-not-allowed">
                            <i class="fas fa-save"></i>
                            <span id="submitDraftBtnText">Simpan Draft Sementara</span>
                        </button>
                        <button type="submit" id="submitQcBtn"
                            class="px-4 py-1.5 text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 rounded-md shadow-xs transition flex items-center gap-1 disabled:opacity-50 disabled:cursor-not-allowed">
                            <i class="fas fa-check-double"></i>
                            <span id="submitBtnText">Konfirmasi Verifikasi</span>
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>

    <!-- 6. JAVASCRIPT LOGIC -->
    <script>
        const defectTypesMap = @json($defectTypesMap);
        let currentTargetDefectQty = 0;
        let currentStageKey = '{{ $selectedStage ?? "netto" }}';
        let currentCard = null;
        let currentDraftUrl = '';
        let currentVerifyUrl = '';
        let isSubmitting = false;

        function openQcModal(card) {
            currentCard = card;
            isSubmitting = false;
            const modal = document.getElementById('qcModal');
            const form = document.getElementById('qcForm');

            currentTargetDefectQty = parseInt(card.defect_qty) || 0;
            currentStageKey = card.stage || '{{ $selectedStage ?? "netto" }}';

            // Populate readonly details
            document.getElementById('modalKtr').textContent = card.traveler_number || '-';
            document.getElementById('modalHeat').textContent = card.heat_number || '-';
            document.getElementById('modalItemName').textContent = card.item_name || '-';
            document.getElementById('modalCheckpoint').textContent = card.checkpoint_code || '-';
            document.getElementById('modalInputQty').textContent = (card.input_qty || 0) + ' pcs';
            document.getElementById('modalDefectQty').textContent = currentTargetDefectQty + ' pcs';
            document.getElementById('modalGoodQty').textContent = (card.good_qty || 0) + ' pcs';
            document.getElementById('qc_notes').value = card.notes || '';

            // Prepopulate Tanggal Pemeriksaan Fisik / Defect
            let resolvedInspectionDate = card.inspection_date || '';
            if (!resolvedInspectionDate && card.physical_done_at) {
                resolvedInspectionDate = card.physical_done_at.substring(0, 10);
            }
            if (!resolvedInspectionDate && card.cast_date) {
                resolvedInspectionDate = card.cast_date;
            }
            if (!resolvedInspectionDate) {
                resolvedInspectionDate = '{{ date('Y-m-d') }}';
            }
            const inspectionDateInput = document.getElementById('modal_inspection_date');
            if (inspectionDateInput) {
                inspectionDateInput.value = resolvedInspectionDate;
            }

            // Update action route URLs
            currentVerifyUrl = "{{ route('sand-casting.qc-defects.verify', ':id') }}".replace(':id', card.id);
            currentDraftUrl = "{{ route('sand-casting.qc-defects.save-draft', ':id') }}".replace(':id', card.id);
            form.action = currentVerifyUrl;

            const modalStageInput = document.getElementById('qc_modal_stage');
            if (modalStageInput) {
                modalStageInput.value = card.stage || '';
            }

            // Re-verification alert
            const alertBanner = document.getElementById('reverificationAlertBanner');
            if (card.is_reverification) {
                alertBanner.classList.remove('hidden');
                document.getElementById('alertPrevDefect').textContent = card.previous_defect_qty || 0;
                document.getElementById('alertCurDefect').textContent = card.defect_qty || 0;
                document.getElementById('alertAddDefect').textContent = '+' + (card.added_defect_qty || 0);
            } else {
                alertBanner.classList.add('hidden');
            }

            // Draft alert banner
            const draftBanner = document.getElementById('draftActiveAlertBanner');
            if (card.has_draft && card.draft_defect_qty > 0) {
                draftBanner.classList.remove('hidden');
                document.getElementById('alertDraftQty').textContent = card.draft_defect_qty;
                document.getElementById('alertTargetQty').textContent = currentTargetDefectQty;
            } else {
                draftBanner.classList.add('hidden');
            }

            // Defect logs history for QC
            const logsContainer = document.getElementById('qcDefectLogsContainer');
            const logsTbody = document.getElementById('qcDefectLogsTbody');
            logsTbody.innerHTML = '';
            if (card.defect_logs && card.defect_logs.length > 0) {
                logsContainer.classList.remove('hidden');
                card.defect_logs.forEach(log => {
                    const tr = document.createElement('tr');
                    tr.className = 'hover:bg-slate-100';
                    tr.innerHTML = `
                        <td class="py-1 px-2 whitespace-nowrap text-slate-500">${log.created_at || '-'}</td>
                        <td class="py-1 px-2 font-sans font-semibold text-slate-700 truncate max-w-[80px]">${log.user_name || 'Admin'}</td>
                        <td class="py-1 px-2 text-right font-bold text-amber-600">+${log.added_qty}</td>
                        <td class="py-1 px-2 text-right text-slate-500">${log.previous_total}</td>
                        <td class="py-1 px-2 text-right font-black text-slate-800">${log.new_total}</td>
                    `;
                    logsTbody.appendChild(tr);
                });
            } else {
                logsContainer.classList.add('hidden');
            }

            const zeroSection = document.getElementById('zeroDefectSection');
            const breakdownSection = document.getElementById('breakdownSection');
            const submitBtn = document.getElementById('submitQcBtn');
            const submitBtnText = document.getElementById('submitBtnText');
            const submitDraftBtn = document.getElementById('submitDraftBtn');
            const submitDraftBtnText = document.getElementById('submitDraftBtnText');
            const container = document.getElementById('defectRowsContainer');

            container.innerHTML = '';
            if (submitDraftBtnText) {
                submitDraftBtnText.textContent = 'Simpan Draft Sementara';
            }

            if (currentTargetDefectQty === 0) {
                // Zero defect flow
                zeroSection.classList.remove('hidden');
                breakdownSection.classList.add('hidden');
                if (submitDraftBtn) submitDraftBtn.classList.add('hidden');
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                submitBtnText.textContent = 'Konfirmasi Verifikasi · 0 Defect';
            } else {
                // Non-zero defect flow
                zeroSection.classList.add('hidden');
                breakdownSection.classList.remove('hidden');
                if (submitDraftBtn) submitDraftBtn.classList.remove('hidden');
                submitBtnText.textContent = card.is_reverification ? 'Konfirmasi Re-Verifikasi' : 'Konfirmasi Verifikasi';

                // Prepopulate with existing defect breakdown (which contains saved draft rows) or add one empty row
                if (card.defects && card.defects.length > 0) {
                    card.defects.forEach(d => addDefectRow(d));
                } else {
                    addDefectRow();
                }

                updateTotalAllocated();
            }

            // Show modal
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeQcModal() {
            if (isSubmitting) return;
            const modal = document.getElementById('qcModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            currentCard = null;
        }

        function addDefectRow(data = null) {
            const container = document.getElementById('defectRowsContainer');
            const index = container.children.length;
            const availableTypes = defectTypesMap[currentStageKey] || [];

            const row = document.createElement('div');
            row.className = 'flex items-center gap-1.5 bg-slate-50 p-1.5 rounded border border-slate-200';

            let optionsHtml = '<option value="">Pilih Jenis Defect...</option>';
            availableTypes.forEach(type => {
                const selected = (data && data.defect_type_id == type.id) ? 'selected' : '';
                optionsHtml += `<option value="${type.id}" ${selected}>${type.name}</option>`;
            });

            row.innerHTML = `
                <div class="flex-1">
                    <select name="defects[${index}][defect_type_id]" class="w-full text-xs py-1 px-2 bg-white border border-slate-300 rounded focus:ring-blue-500 focus:border-blue-500" required>
                        ${optionsHtml}
                    </select>
                </div>
                <div class="w-20">
                    <input type="number" name="defects[${index}][qty]" value="${data ? data.qty : ''}" placeholder="Qty" min="1" step="1" required
                        class="w-full text-xs py-1 px-2 text-center font-bold bg-white border border-slate-300 rounded focus:ring-blue-500 focus:border-blue-500 qc-defect-qty"
                        oninput="updateTotalAllocated()">
                </div>
                <div class="w-28">
                    <input type="text" name="defects[${index}][notes]" value="${data && data.notes ? data.notes : ''}" placeholder="Ket (opsional)"
                        class="w-full text-xs py-1 px-2 bg-white border border-slate-300 rounded focus:ring-blue-500 focus:border-blue-500">
                </div>
                <button type="button" onclick="this.parentElement.remove(); updateTotalAllocated();" class="text-slate-400 hover:text-red-500 px-1 py-0.5 text-xs" title="Hapus Baris">
                    <i class="fas fa-times"></i>
                </button>
            `;

            container.appendChild(row);
            updateTotalAllocated();
        }

        function updateTotalAllocated() {
            if (currentTargetDefectQty === 0) {
                return;
            }

            const inputs = document.querySelectorAll('.qc-defect-qty');
            let total = 0;
            inputs.forEach(input => {
                const val = parseInt(input.value) || 0;
                total += val;
            });

            const display = document.getElementById('allocatedCountDisplay');
            const badge = document.getElementById('allocatedStatusBadge');
            const submitBtn = document.getElementById('submitQcBtn');
            const submitDraftBtn = document.getElementById('submitDraftBtn');

            if (total === 0) {
                display.textContent = '0 / ' + currentTargetDefectQty + ' PCS';
                display.className = 'font-black ml-1 text-sm text-slate-500';
                badge.textContent = 'Belum Diisi · Kurang ' + currentTargetDefectQty + ' pcs';
                badge.className = 'text-[10px] font-bold px-2 py-0.5 rounded bg-slate-200 text-slate-700';

                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                }
                if (submitDraftBtn) {
                    submitDraftBtn.disabled = true;
                    submitDraftBtn.classList.add('opacity-50', 'cursor-not-allowed');
                }
            } else if (total === currentTargetDefectQty) {
                display.textContent = total + ' / ' + currentTargetDefectQty + ' PCS';
                display.className = 'font-black ml-1 text-sm text-emerald-600';
                badge.textContent = '✓ Sesuai Target · Siap Konfirmasi';
                badge.className = 'text-[10px] font-bold px-2 py-0.5 rounded bg-emerald-100 text-emerald-700';

                if (submitBtn) {
                    submitBtn.disabled = isSubmitting;
                    if (isSubmitting) {
                        submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                    } else {
                        submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    }
                }
                if (submitDraftBtn) {
                    submitDraftBtn.disabled = isSubmitting;
                    if (isSubmitting) {
                        submitDraftBtn.classList.add('opacity-50', 'cursor-not-allowed');
                    } else {
                        submitDraftBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    }
                }
            } else if (total > currentTargetDefectQty) {
                const excess = total - currentTargetDefectQty;
                display.textContent = total + ' / ' + currentTargetDefectQty + ' PCS';
                display.className = 'font-black ml-1 text-sm text-red-600';
                badge.textContent = 'Melebihi Target (+' + excess + ' pcs)';
                badge.className = 'text-[10px] font-bold px-2 py-0.5 rounded bg-red-100 text-red-700';

                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                }
                if (submitDraftBtn) {
                    submitDraftBtn.disabled = true;
                    submitDraftBtn.classList.add('opacity-50', 'cursor-not-allowed');
                }
            } else {
                // 0 < total < currentTargetDefectQty (Draft eligible, Confirm disabled)
                const remaining = currentTargetDefectQty - total;
                display.textContent = 'Draft ' + total + ' / ' + currentTargetDefectQty + ' PCS';
                display.className = 'font-black ml-1 text-sm text-cyan-700';
                badge.textContent = 'Draft Tersedia · Kurang ' + remaining + ' pcs';
                badge.className = 'text-[10px] font-bold px-2 py-0.5 rounded bg-cyan-100 text-cyan-800';

                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                }
                if (submitDraftBtn) {
                    submitDraftBtn.disabled = isSubmitting;
                    if (isSubmitting) {
                        submitDraftBtn.classList.add('opacity-50', 'cursor-not-allowed');
                    } else {
                        submitDraftBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    }
                }
            }
        }

        async function submitQcDraft() {
            if (isSubmitting) return;

            if (!currentDraftUrl) {
                alert('URL simpan draft tidak valid.');
                return;
            }

            const container = document.getElementById('defectRowsContainer');
            const rows = container.children;
            const defects = [];
            let hasInvalidRow = false;

            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const select = row.querySelector('select');
                const qtyInput = row.querySelector('.qc-defect-qty');
                const notesInput = row.querySelector('input[type="text"]');

                const defectTypeId = select ? select.value : '';
                const qty = qtyInput ? parseInt(qtyInput.value) || 0 : 0;
                const notes = notesInput ? notesInput.value.trim() : '';

                if (!defectTypeId && qty === 0) {
                    continue; // Skip entirely empty row
                }

                if (!defectTypeId || qty <= 0) {
                    hasInvalidRow = true;
                    break;
                }

                defects.push({
                    defect_type_id: parseInt(defectTypeId),
                    qty: qty,
                    notes: notes
                });
            }

            if (hasInvalidRow) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Data Baris Belum Lengkap',
                        text: 'Pastikan jenis cacat dipilih dan kuantitas minimal 1 PCS untuk setiap baris yang diisi.'
                    });
                } else {
                    alert('Pastikan jenis cacat dipilih dan kuantitas minimal 1 PCS untuk setiap baris yang diisi.');
                }
                return;
            }

            if (defects.length === 0) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Rincian Belum Diisi',
                        text: 'Minimal 1 baris rincian jenis defect wajib diisi untuk menyimpan draft.'
                    });
                } else {
                    alert('Minimal 1 baris rincian jenis defect wajib diisi untuk menyimpan draft.');
                }
                return;
            }

            const totalAllocated = defects.reduce((sum, d) => sum + d.qty, 0);
            if (totalAllocated > currentTargetDefectQty) {
                const excess = totalAllocated - currentTargetDefectQty;
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Melebihi Target Defect',
                        text: `Total draft (${totalAllocated} PCS) melebihi total defect PPIC (${currentTargetDefectQty} PCS) sebanyak ${excess} PCS.`
                    });
                } else {
                    alert(`Total draft (${totalAllocated} PCS) melebihi total defect PPIC (${currentTargetDefectQty} PCS) sebanyak ${excess} PCS.`);
                }
                return;
            }

            const inspectionDateInput = document.getElementById('modal_inspection_date');
            const inspectionDateVal = inspectionDateInput ? inspectionDateInput.value : '';

            if (!inspectionDateVal) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Tanggal Pemeriksaan Wajib Diisi',
                        text: 'Silakan isi Tanggal Pemeriksaan Fisik / Defect sebelum menyimpan draft.'
                    });
                } else {
                    alert('Silakan isi Tanggal Pemeriksaan Fisik / Defect sebelum menyimpan draft.');
                }
                return;
            }

            const notesVal = document.getElementById('qc_notes').value;
            const stageVal = document.getElementById('qc_modal_stage').value || currentStageKey;

            // Loading state
            isSubmitting = true;
            const submitDraftBtn = document.getElementById('submitDraftBtn');
            const submitDraftBtnText = document.getElementById('submitDraftBtnText');
            const submitQcBtn = document.getElementById('submitQcBtn');

            if (submitDraftBtn) {
                submitDraftBtn.disabled = true;
                submitDraftBtn.classList.add('opacity-50', 'cursor-not-allowed');
            }
            if (submitDraftBtnText) {
                submitDraftBtnText.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Menyimpan...';
            }
            if (submitQcBtn) {
                submitQcBtn.disabled = true;
                submitQcBtn.classList.add('opacity-50', 'cursor-not-allowed');
            }

            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                const response = await axios.post(currentDraftUrl, {
                    stage: stageVal,
                    inspection_date: inspectionDateVal,
                    defects: defects,
                    notes: notesVal
                }, {
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                if (response.data && response.data.success) {
                    const data = response.data.data;

                    // Update in-memory currentCard
                    if (currentCard) {
                        currentCard.has_draft = true;
                        currentCard.draft_defect_qty = data.draft_qty;
                        currentCard.defects = data.defects;
                        currentCard.notes = notesVal;
                        currentCard.inspection_date = data.inspection_date || inspectionDateVal;
                    }

                    // Update draft alert banner in modal
                    const draftBanner = document.getElementById('draftActiveAlertBanner');
                    if (draftBanner) {
                        draftBanner.classList.remove('hidden');
                        document.getElementById('alertDraftQty').textContent = data.draft_qty;
                        document.getElementById('alertTargetQty').textContent = data.defect_qty;
                    }

                    // Update queue table row dynamically
                    updateQueueTableRow(data);

                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Draft Tersimpan',
                            text: response.data.message || `Draft ${data.draft_qty}/${data.defect_qty} PCS berhasil disimpan.`,
                            timer: 2000,
                            showConfirmButton: false
                        });
                    } else {
                        alert(response.data.message || `Draft ${data.draft_qty}/${data.defect_qty} PCS berhasil disimpan.`);
                    }
                }
            } catch (err) {
                let errorMsg = 'Terjadi kesalahan sistem saat menyimpan draft.';
                if (err.response && err.response.data) {
                    if (err.response.data.message) {
                        errorMsg = err.response.data.message;
                    }
                    if (err.response.data.errors) {
                        const detailErrors = Object.values(err.response.data.errors).flat().join('<br>');
                        errorMsg += '<div class="mt-2 text-xs text-red-600 text-left bg-red-50 p-2 rounded border border-red-200">' + detailErrors + '</div>';
                    }
                } else if (err.message) {
                    errorMsg = err.message;
                }

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Menyimpan Draft',
                        html: errorMsg
                    });
                } else {
                    alert('Gagal Menyimpan Draft:\n' + errorMsg.replace(/<br>/g, '\n').replace(/<[^>]+>/g, ''));
                }
            } finally {
                isSubmitting = false;
                if (submitDraftBtnText) {
                    submitDraftBtnText.textContent = 'Simpan Draft Sementara';
                }
                updateTotalAllocated();
            }
        }

        function updateQueueTableRow(data) {
            if (!data || !data.execution_id) return;
            const executionId = data.execution_id;

            // Update status cell badge
            const statusCell = document.getElementById(`status-cell-${executionId}`);
            if (statusCell) {
                const badgeContainer = statusCell.querySelector('.flex.flex-col');
                if (badgeContainer) {
                    // Check if draft badge already exists
                    let draftBadge = badgeContainer.querySelector('.fa-file-signature')?.closest('span');
                    if (draftBadge) {
                        draftBadge.setAttribute('title', `Draft breakdown tersimpan: ${data.draft_qty} dari ${data.defect_qty} pcs`);
                        draftBadge.innerHTML = `<i class="fas fa-file-signature text-cyan-600"></i> DRAFT ${data.draft_qty}/${data.defect_qty} PCS`;
                    } else {
                        const newBadge = document.createElement('span');
                        newBadge.className = 'bg-cyan-50 text-cyan-800 border border-cyan-300 font-bold px-2 py-0.5 rounded text-[10px] inline-flex items-center gap-1';
                        newBadge.setAttribute('title', `Draft breakdown tersimpan: ${data.draft_qty} dari ${data.defect_qty} pcs`);
                        newBadge.innerHTML = `<i class="fas fa-file-signature text-cyan-600"></i> DRAFT ${data.draft_qty}/${data.defect_qty} PCS`;
                        badgeContainer.insertBefore(newBadge, badgeContainer.firstChild);

                        // Remove "Verifikasi Baru" if present
                        const newVerificationBadge = badgeContainer.querySelector('.bg-blue-50');
                        if (newVerificationBadge) {
                            newVerificationBadge.remove();
                        }
                    }
                }
            }

            // Update action cell button
            const actionCell = document.getElementById(`action-cell-${executionId}`);
            if (actionCell && currentCard) {
                actionCell.innerHTML = `
                    <button type="button"
                        onclick='openQcModal(${JSON.stringify(currentCard)})'
                        class="bg-cyan-700 hover:bg-cyan-800 text-white font-bold px-2.5 py-1.5 rounded-md text-xs shadow-xs transition inline-flex items-center gap-1"
                        title="Lanjutkan pengisian draft (${data.draft_qty}/${data.defect_qty} pcs)">
                        <i class="fas fa-edit text-[10px]"></i>
                        LANJUTKAN DRAFT
                    </button>
                `;
            }
        }

        // Final verification form submit handler (double click prevention & validation)
        const qcForm = document.getElementById('qcForm');
        if (qcForm) {
            qcForm.addEventListener('submit', function(e) {
                if (isSubmitting) {
                    e.preventDefault();
                    return false;
                }

                const inspectionDateInput = document.getElementById('modal_inspection_date');
                if (inspectionDateInput && !inspectionDateInput.value) {
                    e.preventDefault();
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Tanggal Pemeriksaan Wajib Diisi',
                            text: 'Silakan isi Tanggal Pemeriksaan Fisik / Defect sebelum konfirmasi verifikasi.'
                        });
                    } else {
                        alert('Silakan isi Tanggal Pemeriksaan Fisik / Defect sebelum konfirmasi verifikasi.');
                    }
                    return false;
                }

                if (currentTargetDefectQty > 0) {
                    const inputs = document.querySelectorAll('.qc-defect-qty');
                    let total = 0;
                    inputs.forEach(input => {
                        total += (parseInt(input.value) || 0);
                    });

                    if (total !== currentTargetDefectQty) {
                        e.preventDefault();
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'error',
                                title: 'Alokasi Belum Sesuai',
                                text: `Total rincian cacat (${total} PCS) harus sama persis dengan total defect PPIC (${currentTargetDefectQty} PCS).`
                            });
                        } else {
                            alert(`Total rincian cacat (${total} PCS) harus sama persis dengan total defect PPIC (${currentTargetDefectQty} PCS).`);
                        }
                        return false;
                    }
                }

                isSubmitting = true;
                const submitBtn = document.getElementById('submitQcBtn');
                const submitBtnText = document.getElementById('submitBtnText');
                const submitDraftBtn = document.getElementById('submitDraftBtn');

                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                }
                if (submitDraftBtn) {
                    submitDraftBtn.disabled = true;
                    submitDraftBtn.classList.add('opacity-50', 'cursor-not-allowed');
                }
                if (submitBtnText) {
                    submitBtnText.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Mengonfirmasi...';
                }
            });
        }

        // Close modal on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeQcModal();
            }
        });
    </script>
@endsection
