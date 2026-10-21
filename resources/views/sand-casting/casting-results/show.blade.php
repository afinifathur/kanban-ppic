@extends('layouts.app')

@section('top_bar')
    @php
        $singleLine = $castingResult->lines->count() === 1 ? $castingResult->lines->first() : null;
    @endphp
    <div class="flex flex-col sm:flex-row sm:items-center justify-between w-full gap-2 sm:gap-4">
        <div class="flex items-center gap-2 sm:gap-3 min-w-0">
            <h1 class="text-base sm:text-lg font-bold text-slate-800 leading-tight shrink-0">Detail Hasil Cor</h1>
            <span class="inline-flex items-center px-2 sm:px-2.5 py-0.5 rounded-full text-xs font-mono font-bold bg-amber-100 text-amber-900 border border-amber-300 shadow-sm shrink-0">
                HEAT: {{ $castingResult->heat_number }}
            </span>
            <span class="hidden md:inline text-gray-400 text-[11px] truncate">Fakta penuangan cor pasir & pencatatan Kitir</span>
        </div>

        <div class="flex items-center gap-1.5 sm:gap-2 shrink-0">
            @if($singleLine)
                <a href="{{ route('sand-casting.casting-results.kitir', [$castingResult, $singleLine]) }}" target="_blank"
                   class="bg-slate-900 hover:bg-slate-800 text-amber-300 hover:text-amber-200 font-bold px-2.5 sm:px-3 py-1.5 rounded-lg text-xs flex items-center gap-1.5 shadow-sm transition">
                    <i class="fas fa-print text-[11px]"></i>
                    <span>{{ ($singleLine->print_count ?? 0) > 0 ? 'Cetak Ulang Kitir' : 'Cetak Kitir' }}</span>
                </a>
            @endif

            @if(auth()->user() && (auth()->user()->hasRole('ppic') || auth()->user()->hasRole('admin') || auth()->user()->hasRole('super_admin')))
                <a href="{{ route('sand-casting.casting-results.edit', $castingResult) }}" class="bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold px-2.5 sm:px-3 py-1.5 rounded-lg text-xs flex items-center gap-1.5 shadow-sm transition">
                    <i class="fas fa-edit text-[11px]"></i> <span class="hidden sm:inline">Koreksi</span>
                </a>
            @endif
            <a href="{{ route('sand-casting.casting-results.index') }}" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold px-2.5 sm:px-3 py-1.5 rounded-lg text-xs flex items-center gap-1.5 shadow-sm transition">
                <i class="fas fa-arrow-left text-[11px]"></i> <span class="hidden sm:inline">Daftar</span>
            </a>
            <a href="{{ route('sand-casting.casting-orders.plans') }}" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold px-2.5 sm:px-3 py-1.5 rounded-lg text-xs flex items-center gap-1.5 shadow-sm transition">
                <i class="fas fa-file-invoice text-[11px]"></i> <span class="hidden sm:inline">Perintah Cor</span>
            </a>
        </div>
    </div>
@endsection

@section('content')
    @php
        $defSummary = $defectSummary ?? app(\App\Services\SandCasting\SandCastingProductionStatusService::class)->resolveHeatDefectSummary($castingResult);
        $posSummary = $positionSummary ?? app(\App\Services\SandCasting\SandCastingProductionStatusService::class)->resolveHeatPositionSummary($castingResult);
        $totalWeight = (float) $castingResult->total_weight_kg;
        $orderNumbers = $castingResult->lines->pluck('castingOrderLine.castingOrder.casting_order_number')->filter()->unique()->implode(', ') ?: '-';
    @endphp

    <div class="space-y-2.5 sm:space-y-3">
        <!-- Flash Messages -->
        @if(session('success'))
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs px-3 py-2 rounded-lg flex items-center justify-between shadow-sm">
                <div class="flex items-center gap-2">
                    <i class="fas fa-check-circle text-emerald-500 text-sm"></i>
                    <span>{{ session('success') }}</span>
                </div>
                <button type="button" onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-emerald-600">&times;</button>
            </div>
        @endif

        @if($castingResult->lines->contains('needs_reprint', true))
            <div class="bg-amber-50 border border-amber-300 text-amber-950 px-3 py-2 rounded-lg shadow-sm flex items-center gap-2.5 text-xs">
                <i class="fas fa-triangle-exclamation text-amber-600 text-base shrink-0"></i>
                <div class="flex-1">
                    <span class="font-bold uppercase tracking-wide text-amber-900">Perlu Cetak Ulang Kitir:</span>
                    <span class="text-amber-800 ml-1">Kitir fisik versi sebelumnya sudah pernah dicetak. Silakan cetak ulang Kitir terbaru pada baris yang ditandai.</span>
                </div>
            </div>
        @endif

        <!-- Heat Header Card (Compact & Integrated) -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="bg-slate-900 text-white px-3 sm:px-4 py-2 sm:py-2.5 flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2.5">
                    <span class="text-[9px] font-bold text-amber-400 uppercase tracking-widest bg-amber-400/10 border border-amber-400/30 px-1.5 py-0.5 rounded">HEAT</span>
                    <span class="text-lg sm:text-2xl font-mono font-black tracking-wider text-amber-300">
                        {{ $castingResult->heat_number }}
                    </span>
                </div>
                <div class="flex items-center gap-2 text-xs">
                    <span class="text-slate-400 text-[11px]">Sumber Alokasi:</span>
                    <span class="font-semibold text-slate-200 font-mono">{{ $orderNumbers }}</span>
                </div>
            </div>

            <div class="px-3 sm:px-4 py-2 sm:py-2.5 grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-5 gap-2 sm:gap-3 text-xs border-t border-slate-100">
                <div>
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Tanggal Cor Aktual</div>
                    <div class="text-xs sm:text-sm font-bold text-slate-800 mt-0.5">
                        {{ $castingResult->cast_date ? $castingResult->cast_date->format('d F Y') : '-' }}
                    </div>
                </div>
                <div>
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Furnace / Shift</div>
                    <div class="text-xs sm:text-sm font-semibold text-slate-800 mt-0.5">
                        {{ $castingResult->furnace ?: '-' }} {{ $castingResult->shift ? '('.$castingResult->shift.')' : '' }}
                    </div>
                </div>
                <div>
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Operator Cor</div>
                    <div class="text-xs sm:text-sm font-semibold text-slate-800 mt-0.5 truncate" title="{{ $castingResult->operator_name ?: '-' }}">
                        {{ $castingResult->operator_name ?: '-' }}
                    </div>
                </div>
                <div>
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Dicatat Oleh</div>
                    <div class="text-xs sm:text-sm font-medium text-slate-700 mt-0.5 truncate" title="{{ $castingResult->recorder->name ?? '-' }}">
                        {{ $castingResult->recorder->name ?? '-' }}
                        <span class="text-[10px] text-slate-400 inline sm:block">{{ $castingResult->created_at ? $castingResult->created_at->format('d/m/Y H:i') : '' }}</span>
                    </div>
                </div>
                @if($castingResult->notes)
                    <div class="col-span-2 sm:col-span-4 lg:col-span-1">
                        <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Catatan Penuangan</div>
                        <div class="text-xs text-slate-600 mt-0.5 italic truncate" title="{{ $castingResult->notes }}">{{ $castingResult->notes }}</div>
                    </div>
                @endif
            </div>
        </div>

        <!-- 5 Metric Summary Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2 sm:gap-2.5">
            <!-- 1. Total Good -->
            <div class="bg-white rounded-lg border border-slate-200 p-2 sm:p-2.5 shadow-sm flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm shrink-0">
                    <i class="fas fa-circle-check"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Total Good</div>
                    <div class="text-base sm:text-lg font-bold text-emerald-700 font-mono leading-tight">
                        {{ number_format($defSummary['cast_good']) }} pcs
                    </div>
                    @if($defSummary['process_defect'] > 0)
                        <div class="text-[10px] text-slate-500 font-mono leading-tight">
                            Efektif: {{ number_format($defSummary['net_effective_good']) }} pcs
                        </div>
                    @else
                        <div class="text-[10px] text-slate-400 font-mono leading-tight">Hasil Cor</div>
                    @endif
                </div>
            </div>

            <!-- 2. Total Reject -->
            <div class="bg-white rounded-lg border border-slate-200 p-2 sm:p-2.5 shadow-sm flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-red-50 text-red-600 flex items-center justify-center text-sm shrink-0">
                    <i class="fas fa-circle-xmark"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Total Reject</div>
                    <div class="text-base sm:text-lg font-bold text-red-600 font-mono leading-tight">
                        {{ number_format($defSummary['total_cumulative_reject']) }} pcs
                    </div>
                    @if($defSummary['process_defect'] > 0)
                        <div class="text-[10px] text-slate-500 font-mono leading-tight" title="Reject Cor: {{ $defSummary['cast_reject'] }} pcs | Defect Proses: {{ $defSummary['process_defect'] }} pcs">
                            Cor: {{ $defSummary['cast_reject'] }} &bull; Proses: {{ $defSummary['process_defect'] }}
                        </div>
                    @else
                        <div class="text-[10px] text-slate-400 font-mono leading-tight">Reject Hasil Cor</div>
                    @endif
                </div>
            </div>

            <!-- 3. Total Output -->
            <div class="bg-white rounded-lg border border-slate-200 p-2 sm:p-2.5 shadow-sm flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center text-sm shrink-0">
                    <i class="fas fa-cubes"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Total Output</div>
                    <div class="text-base sm:text-lg font-bold text-slate-800 font-mono leading-tight">
                        {{ number_format($defSummary['cast_total_output']) }} pcs
                    </div>
                    <div class="text-[10px] text-slate-400 font-mono leading-tight">Penuangan Cor</div>
                </div>
            </div>

            <!-- 4. Total Berat Cor -->
            <div class="bg-white rounded-lg border border-slate-200 p-2 sm:p-2.5 shadow-sm flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center text-sm shrink-0">
                    <i class="fas fa-weight-hanging"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Total Berat Cor</div>
                    <div class="text-base sm:text-lg font-bold text-amber-700 font-mono leading-tight">
                        {{ number_format($totalWeight, 2) }} kg
                    </div>
                    <div class="text-[10px] text-slate-400 font-mono leading-tight">Timbangan Cor</div>
                </div>
            </div>

            <!-- 5. Posisi Barang Saat Ini -->
            <div class="bg-white rounded-lg border border-slate-200 p-2 sm:p-2.5 shadow-sm flex items-center gap-2.5 col-span-2 sm:col-span-1 lg:col-span-1">
                <div class="w-8 h-8 rounded-lg bg-sky-50 text-sky-600 flex items-center justify-center text-sm shrink-0">
                    <i class="fas fa-layer-group"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Posisi Barang Saat Ini</div>
                    @if($posSummary['is_empty'])
                        <div class="text-xs font-bold text-slate-400 leading-tight">BELUM ADA KTR</div>
                        <div class="text-[10px] text-slate-400 font-mono leading-tight">0 pcs (0 KTR)</div>
                    @elseif(! $posSummary['is_multi_stage'])
                        @php $single = $posSummary['single_stage']; @endphp
                        <div class="text-sm sm:text-base font-bold text-sky-700 font-mono truncate leading-tight" title="{{ $single['stage_label'] }}">
                            {{ $single['stage_label'] }}
                        </div>
                        <div class="text-[11px] font-semibold text-slate-600 font-mono leading-tight">
                            {{ number_format($single['total_pcs']) }} <span class="text-[10px] font-normal text-slate-400">pcs ({{ $single['ktr_count'] }} KTR)</span>
                        </div>
                    @else
                        <div class="text-xs font-black text-sky-800 tracking-tight leading-tight">
                            MULTI TAHAP
                            <span class="text-[10px] font-semibold text-sky-600 font-mono">({{ count($posSummary['stages']) }} Tahap)</span>
                        </div>
                        <div class="mt-0.5 space-y-0.5 text-[10px] font-mono divide-y divide-slate-100 max-h-12 overflow-y-auto custom-scrollbar">
                            @foreach($posSummary['stages'] as $stg)
                                <div class="pt-0.5 first:pt-0 flex items-center justify-between gap-1">
                                    <span class="font-bold text-slate-700 truncate text-[9px]" title="{{ $stg['stage_label'] }}">{{ $stg['stage_label'] }}</span>
                                    <span class="text-slate-600 font-bold whitespace-nowrap text-[9px]">
                                        {{ number_format($stg['total_pcs']) }} <span class="font-normal text-slate-400">({{ $stg['ktr_count'] }})</span>
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Traceability Flow Card (Compact) -->
        <div class="bg-white rounded-lg border border-slate-200 px-3 py-2 shadow-sm">
            <div class="flex items-center justify-between gap-2 mb-1">
                <span class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Traceability Chain (Rantai Telusur)</span>
                <span class="text-[10px] text-slate-500 font-mono">{{ $castingResult->lines->count() }} Production Item(s)</span>
            </div>
            <div class="bg-slate-50 border border-slate-200 rounded p-1.5 font-mono text-[11px] text-slate-700 space-y-0.5">
                <div class="flex items-center gap-2">
                    <span class="font-bold text-slate-900 bg-amber-200 px-1.5 py-0.5 rounded text-[10px]">HEAT: {{ $castingResult->heat_number }}</span>
                    <i class="fas fa-arrow-right text-slate-400 text-[9px]"></i>
                    <span class="text-slate-500 text-[10px]">{{ $castingResult->lines->count() }} Production Item(s)</span>
                </div>
                @foreach($castingResult->lines as $line)
                    @php $ol = $line->castingOrderLine; @endphp
                    <div class="pl-3 text-[10px] text-slate-600 flex items-center gap-1.5 flex-wrap">
                        <span>├──</span>
                        <span class="font-bold text-blue-700">{{ $ol->code ?? '-' }}</span>
                        @if($ol && $ol->castingOrder)
                            <span class="text-slate-400">({{ $ol->castingOrder->casting_order_number }})</span>
                        @endif
                        <span class="text-slate-400">&rarr;</span>
                        <span class="font-bold text-emerald-700">{{ number_format($line->qty_good) }} pcs Good</span>
                        <span class="text-slate-400">&rarr;</span>
                        <span class="bg-slate-200 text-slate-800 px-1 py-0.5 rounded font-bold">{{ $line->traveler_number }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- Result Line Items Table (Compact & Direct Action) -->
        <div class="bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-3 py-2 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h2 class="font-bold text-slate-800 text-xs sm:text-sm">Rincian Item Hasil Cor</h2>
                    <p class="text-slate-400 text-[10px]">Daftar alokasi penuangan item produk dan penomoran traveler unik</p>
                </div>
                <span class="text-[11px] text-slate-500 font-mono">{{ $castingResult->lines->count() }} item dicor</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-xs text-left">
                    <thead class="bg-slate-50 text-slate-600 font-bold uppercase tracking-wider text-[10px]">
                        <tr>
                            <th class="px-2 py-1.5 text-center w-8">No</th>
                            <th class="px-2.5 py-1.5 text-left">No Perintah Cor</th>
                            <th class="px-2.5 py-1.5 text-left">Kode Prod</th>
                            <th class="px-2.5 py-1.5 text-left">Customer</th>
                            <th class="px-2.5 py-1.5 text-left">Nama Produk</th>
                            <th class="px-2.5 py-1.5 text-center w-28">Traveler (Kitir)</th>
                            <th class="px-2.5 py-1.5 text-center w-20 font-bold text-emerald-700">Good (Pcs)</th>
                            <th class="px-2.5 py-1.5 text-center w-20 font-bold text-red-600">Reject</th>
                            <th class="px-2.5 py-1.5 text-center w-20">Total Cor</th>
                            <th class="px-2.5 py-1.5 text-center w-20">Unit Wt</th>
                            <th class="px-2.5 py-1.5 text-center w-24">Total Berat</th>
                            <th class="px-2.5 py-1.5 text-left">Catatan Line</th>
                            <th class="px-2.5 py-1.5 text-center w-24">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        @foreach($castingResult->lines as $idx => $line)
                            @php
                                $orderLine = $line->castingOrderLine;
                                $ld = $defSummary['line_defects'][$line->id] ?? null;
                            @endphp
                            <tr class="hover:bg-slate-50/80 transition">
                                <td class="px-2 py-1.5 text-center font-mono text-slate-400">{{ $idx + 1 }}</td>
                                <td class="px-2.5 py-1.5 font-mono font-semibold text-slate-600">
                                    @if($orderLine && $orderLine->castingOrder)
                                        <a href="{{ route('sand-casting.casting-orders.show', $orderLine->castingOrder) }}" class="text-blue-600 hover:text-blue-800 underline">
                                            {{ $orderLine->castingOrder->casting_order_number }}
                                        </a>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-2.5 py-1.5 font-mono font-bold text-blue-700">{{ $orderLine->code ?? '-' }}</td>
                                <td class="px-2.5 py-1.5 font-medium">{{ $orderLine->customer ?? '-' }}</td>
                                <td class="px-2.5 py-1.5 font-semibold text-slate-800">
                                    {{ $orderLine->item_name ?? '-' }}
                                    @if($orderLine && ($orderLine->size || $orderLine->aisi))
                                        <span class="block text-[10px] text-slate-400 font-mono">{{ $orderLine->size }} {{ $orderLine->aisi ? '('.$orderLine->aisi.')' : '' }}</span>
                                    @endif
                                </td>
                                <!-- Physical Traveler Identity -->
                                <td class="px-2.5 py-1.5 text-center">
                                    <div class="inline-flex items-center px-1.5 py-0.5 rounded bg-slate-100 text-slate-800 font-mono font-bold text-[11px] border border-slate-300">
                                        <i class="fas fa-barcode text-[9px] mr-1 text-slate-500"></i>
                                        {{ $line->traveler_number }}
                                    </div>
                                    @php
                                        $currentStageKey = $line->current_stage ?? 'cor';
                                        $currentStageLabel = \App\Services\SandCasting\SandCastingProductionStatusService::STAGES_DISPLAY[$currentStageKey] ?? strtoupper(str_replace('_', ' ', $currentStageKey));
                                    @endphp
                                    <div class="mt-0.5 flex items-center justify-center gap-1 flex-wrap">
                                        <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-bold bg-sky-50 text-sky-700 border border-sky-200" title="Posisi Tahap Fisik Saat Ini">
                                            {{ $currentStageLabel }}
                                        </span>
                                        @if($line->needs_reprint)
                                            <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-black bg-amber-100 text-amber-900 border border-amber-400 animate-pulse">
                                                REPRINT
                                            </span>
                                        @endif
                                        @if(($line->print_count ?? 0) === 0)
                                            <span class="inline-flex items-center px-1 py-0.2 rounded text-[9px] font-bold bg-slate-100 text-slate-500 border border-slate-200">
                                                BELUM CETAK
                                            </span>
                                        @elseif($line->print_count === 1)
                                            <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                <i class="fas fa-check text-[8px] mr-0.5"></i> SUDAH CETAK
                                            </span>
                                        @else
                                            <div class="flex flex-col items-center">
                                                <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                                                    {{ $line->print_count }}x CETAK
                                                </span>
                                                <span class="text-[8px] text-amber-600 font-semibold leading-tight">
                                                    {{ $line->print_count - 1 }}x REPRINT
                                                </span>
                                            </div>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-2.5 py-1.5 text-center font-mono">
                                    <div class="font-bold text-emerald-700 text-xs sm:text-sm">{{ number_format($line->qty_good) }}</div>
                                    @if($ld && $ld['process_defect'] > 0)
                                        <div class="text-[9px] text-slate-500 font-sans" title="Kuantitas Good Efektif Saat Ini">Net: {{ number_format($ld['net_good']) }}</div>
                                    @endif
                                </td>
                                <td class="px-2.5 py-1.5 text-center font-mono">
                                    <div class="font-bold text-red-600">{{ number_format($line->qty_reject) }}</div>
                                    @if($ld && $ld['process_defect'] > 0)
                                        <div class="text-[9px] text-amber-700 font-semibold" title="Defect di tahap proses lanjutan (Netto/Bubut/QC)">
                                            +{{ $ld['process_defect'] }} proses
                                        </div>
                                    @endif
                                </td>
                                <td class="px-2.5 py-1.5 text-center font-mono font-bold text-slate-800">{{ number_format($line->qty_total) }}</td>
                                <td class="px-2.5 py-1.5 text-center font-mono text-slate-600">{{ number_format($line->unit_weight_kg, 2) }}</td>
                                <td class="px-2.5 py-1.5 text-center font-mono font-bold text-slate-700">{{ number_format($line->total_weight_kg, 2) }} kg</td>
                                <td class="px-2.5 py-1.5 text-slate-500 italic truncate max-w-xs" title="{{ $line->notes ?: '-' }}">{{ $line->notes ?: '-' }}</td>
                                <td class="px-2.5 py-1.5 text-center">
                                    <a href="{{ route('sand-casting.casting-results.kitir', [$castingResult, $line]) }}" target="_blank"
                                       class="inline-flex items-center gap-1 bg-slate-900 hover:bg-slate-800 text-amber-300 hover:text-amber-200 font-bold px-2 py-1 rounded text-[11px] transition shadow-sm">
                                        <i class="fas fa-print text-[9px]"></i> {{ ($line->print_count ?? 0) > 0 ? 'Cetak Ulang' : 'Cetak Kitir' }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50 font-bold text-slate-800 border-t border-slate-200">
                        <tr>
                            <td colspan="6" class="px-2.5 py-1.5 text-right uppercase tracking-wider text-[10px]">Total:</td>
                            <td class="px-2.5 py-1.5 text-center text-emerald-700 text-xs sm:text-sm font-mono">
                                {{ number_format($defSummary['cast_good']) }} pcs
                                @if($defSummary['process_defect'] > 0)
                                    <span class="block text-[9px] text-slate-500 font-normal">Net: {{ number_format($defSummary['net_effective_good']) }} pcs</span>
                                @endif
                            </td>
                            <td class="px-2.5 py-1.5 text-center text-red-600 text-xs sm:text-sm font-mono">
                                {{ number_format($defSummary['cast_reject']) }} pcs
                                @if($defSummary['process_defect'] > 0)
                                    <span class="block text-[9px] text-amber-700 font-semibold" title="Total Reject Kumulatif Cor + Proses">Tot: {{ number_format($defSummary['total_cumulative_reject']) }} pcs</span>
                                @endif
                            </td>
                            <td class="px-2.5 py-1.5 text-center text-slate-900 text-xs sm:text-sm font-mono">{{ number_format($defSummary['cast_total_output']) }} pcs</td>
                            <td class="px-2.5 py-1.5"></td>
                            <td class="px-2.5 py-1.5 text-center text-slate-900 font-mono text-xs sm:text-sm">{{ number_format($totalWeight, 2) }} kg</td>
                            <td class="px-2.5 py-1.5" colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Audit Trail / Correction History Card (Compact) -->
        @php
            $allCorrections = $castingResult->corrections;
        @endphp
        @if($allCorrections->isNotEmpty())
            <div class="bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-3 py-2 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                    <div>
                        <h2 class="font-bold text-slate-800 text-xs sm:text-sm flex items-center gap-1.5">
                            <i class="fas fa-clock-rotate-left text-amber-600"></i> Riwayat Koreksi Hasil Cor (Audit Trail)
                        </h2>
                        <p class="text-slate-400 text-[10px]">Catatan perubahan, nilai lama, nilai baru, alasan, dan pelaksana koreksi</p>
                    </div>
                    <span class="text-[10px] font-mono font-bold text-slate-600 bg-white border border-slate-200 px-2 py-0.5 rounded shadow-sm">
                        {{ $allCorrections->count() }} Perubahan Dicatat
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left">
                        <thead class="bg-slate-100 text-slate-600 font-bold uppercase tracking-wider text-[9px]">
                            <tr>
                                <th class="px-2.5 py-1.5 text-left w-32">Waktu Koreksi</th>
                                <th class="px-2.5 py-1.5 text-left w-20">Target</th>
                                <th class="px-2.5 py-1.5 text-left w-28">KTR / Line</th>
                                <th class="px-2.5 py-1.5 text-left w-28">Field</th>
                                <th class="px-2.5 py-1.5 text-left">Nilai Lama</th>
                                <th class="px-2.5 py-1.5 text-left">Nilai Baru</th>
                                <th class="px-2.5 py-1.5 text-left">Alasan Koreksi</th>
                                <th class="px-2.5 py-1.5 text-left w-28">Diubah Oleh</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            @foreach($allCorrections as $corr)
                                <tr class="hover:bg-slate-50/80 transition">
                                    <td class="px-2.5 py-1.5 font-mono text-slate-500 whitespace-nowrap text-[11px]">
                                        {{ $corr->corrected_at ? $corr->corrected_at->format('d/m/Y H:i:s') : ($corr->created_at ? $corr->created_at->format('d/m/Y H:i:s') : '-') }}
                                    </td>
                                    <td class="px-2.5 py-1.5 font-semibold">
                                        @if($corr->target_type === 'HEADER')
                                            <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-bold bg-purple-50 text-purple-700 border border-purple-200">
                                                HEADER
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                                                LINE
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-2.5 py-1.5 font-mono font-bold text-slate-700 text-[11px]">
                                        {{ $corr->castingResultLine ? $corr->castingResultLine->traveler_number : 'Seluruh Heat' }}
                                    </td>
                                    <td class="px-2.5 py-1.5 font-mono font-bold text-slate-800 text-[11px]">
                                        {{ $corr->field_name }}
                                    </td>
                                    <td class="px-2.5 py-1.5 font-mono text-red-600 bg-red-50/50 rounded text-[11px]">
                                        {{ $corr->old_value ?? '-' }}
                                    </td>
                                    <td class="px-2.5 py-1.5 font-mono font-bold text-emerald-700 bg-emerald-50/50 rounded text-[11px]">
                                        {{ $corr->new_value ?? '-' }}
                                    </td>
                                    <td class="px-2.5 py-1.5 text-slate-600 italic text-[11px]">
                                        {{ $corr->reason ?: '-' }}
                                    </td>
                                    <td class="px-2.5 py-1.5 font-medium text-slate-800 text-[11px]">
                                        {{ $corr->corrector->name ?? '-' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
@endsection
