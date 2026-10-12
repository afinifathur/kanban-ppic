{{-- Zone 3: Quality Analytics Dashboard (Compact Industrial SCADA - Weight in KG Primary) --}}
@php
    $z3 = $initialData['zone_3'] ?? [];
    $summary = $z3['quality_analytics_summary'] ?? [];
    $trend30 = $z3['defect_trend_30_days'] ?? [];
    $contrib = $z3['stage_defect_contribution'] ?? [];
    $overallTypes = $z3['overall_defect_types'] ?? [];
    $stageTypes = $z3['stage_defect_types'] ?? [];
    $totalVerifiedPcs = $summary['total_verified_pcs'] ?? 0;
    $totalEstimatedKg = $summary['total_estimated_defect_kg'] ?? 0.0;
    $weightCoveragePct = $summary['weight_coverage_pct'] ?? 100.0;
    $isWeightComplete = $summary['is_weight_fully_available'] ?? true;
    $unavailPcs = $summary['unavailable_weight_pcs'] ?? 0;

    // Palette warna terpadu dan konsisten untuk semua diagram defect
    $typePalette = ['#dc2626', '#ea580c', '#d97706', '#0284c7', '#4f46e5', '#7c3aed', '#059669', '#0891b2', '#64748b'];
    $colorMap = [];
    foreach ($overallTypes as $idx => $t) {
        $colorMap[$t['defect_type_id']] = $typePalette[$idx % count($typePalette)];
    }
@endphp

<section id="zone-3-container" class="space-y-2.5">
    <!-- Zone 3 Header Banner (Compact SCADA) -->
    <div class="flex items-center justify-between border-b border-slate-200 pb-1.5">
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center justify-center px-2 py-0.5 rounded bg-slate-800 text-white text-xs font-black tracking-wider">
                Z3
            </span>
            <h2 class="text-base sm:text-lg font-black tracking-tight text-slate-900 uppercase">
                KUALITAS & ANALISIS DEFECT
            </h2>
            <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600 font-bold text-[10px] uppercase font-mono">
                QUALITY ANALYTICS
            </span>
        </div>
        <div class="flex items-center gap-2 font-mono text-xs">
            @if(! $isWeightComplete)
                <span class="px-2 py-0.5 rounded bg-amber-50 text-amber-800 border border-amber-200 font-black text-[10px] shadow-2xs" id="z3-coverage-badge">
                    Cakupan Berat: {{ number_format($weightCoveragePct, 1, ',', '.') }}% ({{ number_format($unavailPcs) }} PCS N/A)
                </span>
            @endif
            <span class="px-2.5 py-0.5 rounded bg-rose-50 text-rose-800 border border-rose-200 font-black text-[11px] shadow-2xs">
                Total Defect 30 Hari: <span id="z3-total-30d-kg">{{ number_format($totalEstimatedKg, 1, ',', '.') }}</span> kg <span class="font-normal text-rose-600 text-[10px]">| <span id="z3-total-30d-pcs">{{ number_format($totalVerifiedPcs) }}</span> PCS</span>
            </span>
        </div>
    </div>

    <!-- 1. Tren Defect Harian (30 Hari Kalender) - Sepanjang Lebar Layar -->
    <div class="bg-white rounded-xl p-3 border border-slate-200 shadow-2xs">
        <div class="flex items-center justify-between mb-1.5">
            <h3 class="text-xs sm:text-sm font-black text-slate-900 uppercase tracking-wider flex items-center gap-1.5">
                <i class="fas fa-chart-line text-rose-600"></i>
                TREN ESTIMASI BERAT DEFECT — 30 HARI
            </h3>
            <div class="flex items-center gap-2">
                <span class="text-[10px] font-black px-2 py-0.5 rounded bg-rose-50 text-rose-700 border border-rose-200 uppercase font-mono">
                    SATUAN: KG
                </span>
                <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-600 uppercase font-mono">
                    30 HARI TERAKHIR
                </span>
            </div>
        </div>
        <!-- Canvas Bar Chart 30 Hari -->
        <div class="relative w-full h-36 sm:h-40">
            <canvas id="chartDefectTrend30Days"></canvas>
        </div>
        <div class="pt-1.5 mt-1 border-t border-slate-100 flex items-center justify-between text-[10px] text-slate-500 font-sans">
            <span>Metrik utama: Estimasi Berat Defect (kg) terverifikasi QC fisik (CONFIRMED) berdasarkan tanggal inspeksi.</span>
            <span class="font-bold text-slate-700 font-mono">Periode: {{ $summary['period_start'] ?? '-' }} s/d {{ $summary['period_end'] ?? '-' }}</span>
        </div>
    </div>

    <!-- 2. Middle Row: Komposisi Jenis Defect Keseluruhan & Kontribusi per Tahap -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-2.5 items-stretch">
        <!-- A. Donut Komposisi Jenis Defect Keseluruhan (5 Cols) -->
        <div class="lg:col-span-5 bg-white rounded-xl p-3 border border-slate-200 shadow-2xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-xs font-black text-slate-900 uppercase tracking-wider flex items-center gap-1.5">
                        <i class="fas fa-pie-chart text-indigo-600"></i>
                        KOMPOSISI ESTIMASI BERAT DEFECT — SEMUA TAHAP
                    </h3>
                    <span class="text-[10px] font-black px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-700 border border-indigo-200 uppercase font-mono">
                        BASIS: KG
                    </span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center">
                    <!-- Donut Canvas -->
                    <div class="sm:col-span-5 relative w-full h-36">
                        <canvas id="chartOverallDefectTypes"></canvas>
                    </div>
                    <!-- Direct Readable External Legend -->
                    <div class="sm:col-span-7 space-y-1 font-mono text-xs max-h-36 overflow-y-auto custom-scrollbar" id="z3-overall-legend">
                        @forelse($overallTypes as $idx => $t)
                            @php
                                $dotCol = $typePalette[$idx % count($typePalette)];
                            @endphp
                            <div class="p-1 rounded border border-slate-100 bg-slate-50 flex items-center justify-between hover:bg-slate-100 transition-colors">
                                <div class="flex items-center gap-1.5 truncate">
                                    <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ $dotCol }};"></span>
                                    <span class="font-sans font-bold text-slate-800 text-[11px] truncate">{{ $t['name'] }}</span>
                                </div>
                                <div class="text-right shrink-0 ml-1">
                                    <span class="font-black text-slate-900 text-xs">{{ number_format($t['estimated_defect_kg'], 1, ',', '.') }} kg</span>
                                    <span class="font-black text-rose-600 text-xs">· {{ number_format($t['weight_percentage'], 1, ',', '.') }}%</span>
                                    <span class="text-[10px] text-slate-500 font-semibold font-mono">({{ number_format($t['qty_pcs']) }} pcs)</span>
                                </div>
                            </div>
                        @empty
                            <div class="p-4 text-center text-slate-400 font-sans text-xs">
                                Belum ada defect terverifikasi dalam 30 hari terakhir.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
            <div class="pt-1.5 mt-2 border-t border-slate-100 flex items-center justify-between text-[10px] text-slate-500 font-sans">
                <span>Total Estimasi: {{ number_format($totalEstimatedKg, 1, ',', '.') }} kg ({{ number_format($totalVerifiedPcs) }} PCS)</span>
                @if(! $isWeightComplete)
                    <span class="font-bold text-amber-700 font-mono">*Persentase dihitung dari data berat valid</span>
                @else
                    <span class="font-bold text-slate-800 font-mono">Cakupan Berat 100%</span>
                @endif
            </div>
        </div>

        <!-- B. Kontribusi Defect per Tahap — Horizontal Bar Chart (7 Cols) -->
        <div class="lg:col-span-7 bg-white rounded-xl p-3 border border-slate-200 shadow-2xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-xs font-black text-slate-900 uppercase tracking-wider flex items-center gap-1.5">
                        <i class="fas fa-layer-group text-blue-600"></i>
                        KONTRIBUSI ESTIMASI BERAT DEFECT PER TAHAP
                    </h3>
                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-blue-50 text-blue-800 border border-blue-200 uppercase font-mono">
                        6 TAHAP PROSES
                    </span>
                </div>
                <!-- Direct Horizontal Bars with direct legible labels -->
                <div class="space-y-1.5" id="z3-stage-contribution-container">
                    @foreach($contrib as $c)
                        <div class="p-1.5 rounded-lg border border-slate-100 bg-slate-50 flex flex-col gap-0.5">
                            <div class="flex items-center justify-between text-xs">
                                <span class="font-bold text-slate-800 font-sans text-[11px]">{{ $c['label'] }}</span>
                                <div class="font-mono text-right text-xs">
                                    <span class="font-black text-slate-900">{{ number_format($c['estimated_defect_kg'], 1, ',', '.') }} kg</span>
                                    <span class="font-black text-rose-600">· {{ number_format($c['weight_contribution_pct'], 1, ',', '.') }}%</span>
                                    <span class="font-normal text-slate-500 font-sans text-[11px]">| {{ number_format($c['defect_pcs']) }} PCS</span>
                                    @if(! ($c['is_weight_complete'] ?? true))
                                        <span class="text-amber-600 font-black text-[10px]" title="Sebagian berat tidak tersedia">*</span>
                                    @endif
                                </div>
                            </div>
                            <!-- Bar fill based on weight contribution % -->
                            <div class="w-full bg-slate-200 h-2 rounded-full overflow-hidden">
                                <div class="bg-blue-600 h-full rounded-full transition-all duration-300" style="width: {{ min(100, max(0, $c['weight_contribution_pct'])) }}%;"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="pt-1.5 mt-2 border-t border-slate-100 flex items-center justify-between text-[10px] text-slate-500 font-sans">
                <span>Kontribusi dihitung dari estimasi berat defect valid per tahap terhadap total 6 tahap.</span>
                @if(! $isWeightComplete)
                    <span class="font-bold text-amber-700 font-mono">*{{ number_format($unavailPcs) }} PCS tanpa data berat</span>
                @else
                    <span class="font-bold text-slate-700 font-mono">Basis 30 Hari</span>
                @endif
            </div>
        </div>
    </div>

    <!-- 3. Enam Donut Komposisi Defect per Tahap (3 Kolom x 2 Baris) -->
    <div class="bg-white rounded-xl p-3 border border-slate-200 shadow-2xs">
        <div class="flex items-center justify-between mb-2">
            <h3 class="text-xs sm:text-sm font-black text-slate-900 uppercase tracking-wider flex items-center gap-1.5">
                <i class="fas fa-th-large text-slate-700"></i>
                KOMPOSISI ESTIMASI BERAT DEFECT PER TAHAP
            </h3>
            <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-700 font-mono uppercase">
                6 TAHAP PROSES (KG)
            </span>
        </div>

        <!-- Grid 3 Kolom x 2 Baris -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2.5">
            @php
                $sixStagesOrder = ['netto', 'bubut_od', 'bubut_cnc', 'bor', 'qc', 'gudang_jadi'];
            @endphp
            @foreach($sixStagesOrder as $stgKey)
                @php
                    $stgData = $stageTypes[$stgKey] ?? ['stage' => $stgKey, 'label' => ucfirst($stgKey), 'total_pcs' => 0, 'total_estimated_defect_kg' => 0.0, 'is_weight_available' => false, 'types' => []];
                    $stgPcs = $stgData['total_pcs'] ?? 0;
                    $stgKg = $stgData['total_estimated_defect_kg'] ?? 0.0;
                    $stgWeightAvail = $stgData['is_weight_available'] ?? false;
                    $typesList = $stgData['types'] ?? [];
                @endphp
                <div class="p-2 rounded-lg border border-slate-200 bg-slate-50/70 flex flex-col justify-between">
                    <div class="flex items-center justify-between border-b border-slate-200/60 pb-1 mb-1.5">
                        <span class="font-sans font-black text-xs text-slate-900 uppercase">{{ $stgData['label'] }}</span>
                        <span class="px-1.5 py-0.2 rounded text-[10px] font-black font-mono {{ $stgPcs > 0 ? 'bg-rose-100 text-rose-800' : 'bg-slate-200 text-slate-600' }}">
                            {{ number_format($stgKg, 1, ',', '.') }} kg | {{ number_format($stgPcs) }} PCS
                        </span>
                    </div>

                    @if($stgPcs === 0)
                        <div class="py-3 text-center font-sans text-slate-400 text-xs flex flex-col items-center justify-center gap-0.5">
                            <i class="far fa-check-circle text-emerald-600 text-sm"></i>
                            <span class="font-bold text-slate-500 text-[10px]">0 PCS — Nihil defect terverifikasi</span>
                        </div>
                    @elseif(! $stgWeightAvail && $stgKg <= 0)
                        <div class="py-3 text-center font-sans text-amber-600 text-xs flex flex-col items-center justify-center gap-0.5">
                            <i class="fas fa-exclamation-triangle text-amber-500 text-sm"></i>
                            <span class="font-bold text-amber-700 text-[10px]">{{ number_format($stgPcs) }} PCS — Berat tidak tersedia</span>
                        </div>
                    @elseif($stgPcs > 0 && count($typesList) > 0)
                        <div class="grid grid-cols-12 gap-2 items-center">
                            <div class="col-span-5 relative w-full h-20">
                                <canvas id="chartStageDonut_{{ $stgKey }}"></canvas>
                            </div>
                            <div class="col-span-7 space-y-1 font-mono text-[11px] max-h-20 overflow-y-auto custom-scrollbar">
                                @foreach($typesList as $t)
                                    @php
                                        $dotCol = $colorMap[$t['defect_type_id']] ?? '#64748b';
                                    @endphp
                                    <div class="flex items-center justify-between leading-tight">
                                        <div class="flex items-center gap-1 truncate max-w-[85px]">
                                            <span class="w-2 h-2 rounded-full shrink-0" style="background-color: {{ $dotCol }};"></span>
                                            <span class="font-sans font-bold text-slate-700 text-[10px] truncate" title="{{ $t['name'] }}">{{ $t['name'] }}</span>
                                        </div>
                                        <div class="text-right shrink-0">
                                            <span class="font-black text-slate-900 text-[10px]">{{ number_format($t['estimated_defect_kg'], 1, ',', '.') }} kg</span>
                                            <span class="text-slate-500 text-[9px]">({{ number_format($t['weight_percentage'], 1, ',', '.') }}%)</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="py-3 text-center font-sans text-slate-400 text-xs flex flex-col items-center justify-center gap-0.5">
                            <i class="far fa-check-circle text-emerald-600 text-sm"></i>
                            <span class="font-bold text-slate-500 text-[10px]">0 PCS — Nihil defect terverifikasi</span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</section>
