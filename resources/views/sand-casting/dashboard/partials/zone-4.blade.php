{{-- Zone 4: Daily Production Tonnage & WIP Snapshot Trend (30 Calendar Days) --}}
@php
    $z4 = $initialData['zone_4'] ?? [];
    $throughputStages = $z4['stages'] ?? [];
    $defaultStage = $z4['default_stage'] ?? 'qc';
    $currentStageData = $throughputStages[$defaultStage] ?? ($throughputStages['qc'] ?? []);

    $initialTotalKg = $currentStageData['total_weight_kg'] ?? 0.0;
    $initialTotalPcs = $currentStageData['total_good_pcs'] ?? 0;
    $initialTotalKtr = $currentStageData['total_ktr_count'] ?? 0;
    $periodStart = $z4['period_start'] ?? '-';
    $periodEnd = $z4['period_end'] ?? '-';

    // WIP Snapshot dataset
    $wipData = $z4['wip_snapshot'] ?? [];
    $wipStages = $wipData['stages'] ?? [];
    $defaultWipStage = $wipData['default_stage'] ?? 'qc';
    $currentWipStageData = $wipStages[$defaultWipStage] ?? ($wipStages['qc'] ?? []);

    $initialWipTotalKg = $currentWipStageData['total_weight_kg'] ?? 0.0;
    $initialWipTotalPcs = $currentWipStageData['total_good_pcs'] ?? 0;
    $initialWipTotalKtr = $currentWipStageData['total_ktr_count'] ?? 0;
    $wipPeriodStart = $wipData['period_start'] ?? $periodStart;
    $wipPeriodEnd = $wipData['period_end'] ?? $periodEnd;
@endphp

<section id="zone-4-container" class="space-y-2.5">
    <!-- Zone 4 Header Banner (Compact SCADA) -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between border-b border-slate-200 pb-1.5 gap-2">
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center justify-center px-2 py-0.5 rounded bg-slate-800 text-white text-xs font-black tracking-wider">
                Z4
            </span>
            <h2 class="text-base sm:text-lg font-black tracking-tight text-slate-900 uppercase">
                TREN TONASE PRODUKSI HARIAN — 30 HARI
            </h2>
            <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600 font-bold text-[10px] uppercase font-mono">
                OUTPUT BAIK (KG)
            </span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- Stage Selector Dropdown for Throughput & Line Distribution (6 Stages) -->
            <div class="flex items-center gap-1.5 text-xs">
                <label for="zone4-stage-select" class="font-black text-slate-600 uppercase tracking-wider text-[10px]">
                    Tahap:
                </label>
                <select id="zone4-stage-select" onchange="switchZone4Stage(this.value)"
                    class="px-2.5 py-1 rounded-md border border-slate-300 bg-white font-mono font-bold text-xs text-slate-800 shadow-2xs hover:border-slate-400 focus:outline-hidden focus:ring-1 focus:ring-blue-500">
                    <option value="qc" @selected($defaultStage === 'qc')>QC</option>
                    <option value="netto" @selected($defaultStage === 'netto')>NETTO</option>
                    <option value="bubut_od" @selected($defaultStage === 'bubut_od')>Bubut OD</option>
                    <option value="bubut_cnc" @selected($defaultStage === 'bubut_cnc')>Bubut CNC</option>
                    <option value="bor" @selected($defaultStage === 'bor')>BOR</option>
                    <option value="gudang_jadi" @selected($defaultStage === 'gudang_jadi')>Gudang Jadi</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Chart Card 1: Daily Throughput Bar Chart -->
    <div class="bg-white rounded-xl p-3 sm:p-3.5 border border-slate-200 shadow-2xs flex flex-col justify-between">
        <div class="flex items-center justify-between mb-1.5">
            <h3 class="text-xs sm:text-sm font-black text-slate-900 uppercase tracking-wider flex items-center gap-1.5">
                <i class="fas fa-chart-column text-blue-600"></i>
                <span>TREN OUTPUT BAIK HARIAN — <span id="z4-stage-label" class="text-blue-700">{{ $currentStageData['stage_label'] ?? 'QC' }}</span></span>
            </h3>
            <div class="flex items-center gap-2">
                <span class="text-[10px] font-black px-2 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200 uppercase font-mono">
                    SATUAN: KG & PCS
                </span>
                <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-600 uppercase font-mono">
                    30 HARI TERAKHIR
                </span>
            </div>
        </div>

        <!-- Compact Summary Metadata Above Bar Chart (Gaya SCADA / Zona 3) -->
        <div class="flex items-center justify-between mb-2 text-[11px] font-mono">
            <div class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded bg-slate-50 border border-slate-200/70 text-slate-700">
                <span class="font-black text-slate-800 uppercase tracking-wider text-[10px]">TOTAL 30 HARI (<span id="z4-summary-stage-name" class="text-blue-700">{{ $currentStageData['stage_label'] ?? 'QC' }}</span>):</span>
                <span class="font-black text-slate-950" id="z4-total-kg">{{ number_format($initialTotalKg, 0, ',', '.') }}</span> <span class="font-bold text-slate-500 text-[10px]">kg</span>
                <span class="text-slate-300 font-bold">|</span>
                <span class="font-bold text-slate-800" id="z4-total-pcs">{{ number_format($initialTotalPcs, 0, ',', '.') }}</span> <span class="text-slate-500 text-[10px]">PCS</span>
                <span class="text-slate-300 font-bold">|</span>
                <span class="font-bold text-slate-800" id="z4-total-ktr">{{ number_format($initialTotalKtr, 0, ',', '.') }}</span> <span class="text-slate-500 text-[10px]">KTR</span>
            </div>
            <span class="text-[10px] text-slate-400 font-sans hidden sm:inline">
                Agregat fisik tahap aktif
            </span>
        </div>

        <!-- Canvas Bar Chart 30 Hari Throughput -->
        <div class="relative w-full h-52 sm:h-56">
            <canvas id="chartZone4Throughput"></canvas>
        </div>

        <div class="pt-1.5 mt-1 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between text-[10px] text-slate-500 gap-1 font-sans">
            <span>Berdasarkan tanggal pengerjaan fisik (physical_done_at). Tanggal tanpa produksi tetap ditampilkan dengan nilai 0,0000 kg.</span>
            <span class="font-bold text-slate-700 font-mono shrink-0" id="z4-period-label">Periode: {{ $periodStart }} s/d {{ $periodEnd }}</span>
        </div>
    </div>

    <!-- Chart Card 2: Line Distribution (Line 1 - 4 Throughput) -->
    <div class="bg-white rounded-xl p-3 sm:p-3.5 border border-slate-200 shadow-2xs flex flex-col justify-between">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-2 gap-2">
            <h3 class="text-xs sm:text-sm font-black text-slate-900 uppercase tracking-wider flex items-center gap-1.5">
                <i class="fas fa-chart-line text-indigo-600"></i>
                <span>DISTRIBUSI OUTPUT PER LINE (LINE 1–4) — <span id="z4-lines-stage-label" class="text-blue-700">{{ $currentStageData['stage_label'] ?? 'QC' }}</span></span>
            </h3>
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200">
                    <span class="w-2 h-2 rounded-full bg-blue-600 inline-block"></span> Line 1
                </span>
                <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">
                    <span class="w-2 h-2 rounded-full bg-emerald-600 inline-block"></span> Line 2
                </span>
                <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-200">
                    <span class="w-2 h-2 rounded-full bg-amber-600 inline-block"></span> Line 3
                </span>
                <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded bg-purple-50 text-purple-700 border border-purple-200">
                    <span class="w-2 h-2 rounded-full bg-purple-600 inline-block"></span> Line 4
                </span>
                <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-600 uppercase font-mono">
                    30 HARI TERAKHIR
                </span>
            </div>
        </div>

        <!-- Canvas Line Chart 30 Hari Per Line -->
        <div class="relative w-full h-56 sm:h-64">
            <canvas id="chartZone4LineDistribution"></canvas>
        </div>

        <div class="pt-1.5 mt-1 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between text-[10px] text-slate-500 gap-1 font-sans">
            <span>Distribusi output per line (Line 1–4) menggunakan tanggal pengerjaan fisik (physical_done_at) dan kuantitas efektif.</span>
            <span class="font-bold text-slate-700 font-mono shrink-0" id="z4-lines-period-label">Periode: {{ $periodStart }} s/d {{ $periodEnd }}</span>
        </div>
    </div>

    <!-- Chart Card 3: Daily WIP Snapshot Bar Chart -->
    <div class="bg-white rounded-xl p-3 sm:p-3.5 border border-slate-200 shadow-2xs flex flex-col justify-between">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-1.5 gap-2">
            <div class="flex items-center gap-2">
                <h3 class="text-xs sm:text-sm font-black text-slate-900 uppercase tracking-wider flex items-center gap-1.5">
                    <i class="fas fa-layer-group text-sky-600"></i>
                    <span>TREN WIP SNAPSHOT HARIAN — <span id="z4-wip-stage-label" class="text-sky-700">{{ $currentWipStageData['stage_label'] ?? 'QC' }}</span></span>
                </h3>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-[10px] font-black px-2 py-0.5 rounded bg-sky-50 text-sky-700 border border-sky-200 uppercase font-mono">
                    CUTOFF: 07.00 WIB
                </span>
                <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-600 uppercase font-mono">
                    30 HARI TERAKHIR
                </span>
                <!-- Independent WIP Stage Selector Dropdown (5 WIP Stages) -->
                <div class="flex items-center gap-1.5 text-xs">
                    <label for="zone4-wip-stage-select" class="font-black text-slate-600 uppercase tracking-wider text-[10px]">
                        Tahap WIP:
                    </label>
                    <select id="zone4-wip-stage-select" onchange="switchZone4WipStage(this.value)"
                        class="px-2.5 py-1 rounded-md border border-slate-300 bg-white font-mono font-bold text-xs text-slate-800 shadow-2xs hover:border-slate-400 focus:outline-hidden focus:ring-1 focus:ring-sky-500">
                        <option value="qc" @selected($defaultWipStage === 'qc')>QC</option>
                        <option value="netto" @selected($defaultWipStage === 'netto')>NETTO</option>
                        <option value="bubut_od" @selected($defaultWipStage === 'bubut_od')>Bubut OD</option>
                        <option value="bubut_cnc" @selected($defaultWipStage === 'bubut_cnc')>Bubut CNC</option>
                        <option value="bor" @selected($defaultWipStage === 'bor')>BOR</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Compact Summary Metadata Above Bar Chart (Gaya SCADA / Zona 3) -->
        <div class="flex items-center justify-between mb-2 text-[11px] font-mono">
            <div class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded bg-slate-50 border border-slate-200/70 text-slate-700">
                <span class="font-black text-slate-800 uppercase tracking-wider text-[10px]">TOTAL SNAPSHOT TERBARU (<span id="z4-wip-summary-stage-name" class="text-sky-700">{{ $currentWipStageData['stage_label'] ?? 'QC' }}</span>):</span>
                @php
                    $isSnapAvailable = (bool) ($currentWipStageData['latest_snapshot']['is_available'] ?? false);
                @endphp
                <span id="z4-wip-summary-content" class="inline-flex items-center gap-1.5">
                    @if($isSnapAvailable)
                        <span class="font-black text-slate-950" id="z4-wip-total-kg">{{ number_format($initialWipTotalKg, 0, ',', '.') }}</span> <span class="font-bold text-slate-500 text-[10px]">kg</span>
                        <span class="text-slate-300 font-bold">|</span>
                        <span class="font-bold text-slate-800" id="z4-wip-total-pcs">{{ number_format($initialWipTotalPcs, 0, ',', '.') }}</span> <span class="text-slate-500 text-[10px]">PCS</span>
                        <span class="text-slate-300 font-bold">|</span>
                        <span class="font-bold text-slate-800" id="z4-wip-total-ktr">{{ number_format($initialWipTotalKtr, 0, ',', '.') }}</span> <span class="text-slate-500 text-[10px]">KTR</span>
                    @else
                        <span class="text-slate-400 italic font-sans text-[11px]" id="z4-wip-unavailable-label">Data Snapshot Tidak Tersedia</span>
                        <span class="hidden" id="z4-wip-total-kg">0</span>
                        <span class="hidden" id="z4-wip-total-pcs">0</span>
                        <span class="hidden" id="z4-wip-total-ktr">0</span>
                    @endif
                </span>
            </div>
            <div class="flex items-center gap-2 text-[10px] font-sans hidden sm:flex">
                <span class="inline-flex items-center gap-1 text-slate-600">
                    <span class="w-2.5 h-2.5 rounded-xs bg-[#0284c7] inline-block"></span> Aktual
                </span>
                <span class="inline-flex items-center gap-1 text-slate-600">
                    <span class="w-2.5 h-2.5 rounded-xs bg-[#f59e0b] inline-block"></span> Rekonstruksi
                </span>
                <span class="text-slate-400">
                    Cutoff 07.00 WIB
                </span>
            </div>
        </div>

        <!-- Canvas Bar Chart 30 Hari WIP Snapshot -->
        <div class="relative w-full h-52 sm:h-56">
            <canvas id="chartZone4WipSnapshot"></canvas>
        </div>

        <div class="pt-1.5 mt-1 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between text-[10px] text-slate-500 gap-1 font-sans">
            <span>Berdasarkan posisi antrean fisik pada cutoff harian pukul 07.00 WIB. Tanggal tanpa snapshot ditampilkan sebagai data tidak tersedia.</span>
            <span class="font-bold text-slate-700 font-mono shrink-0" id="z4-wip-period-label">Periode: {{ $wipPeriodStart }} s/d {{ $wipPeriodEnd }}</span>
        </div>
    </div>
</section>
