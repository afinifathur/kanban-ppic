{{-- Zone 3: Quality Analytics Dashboard (Compact Industrial SCADA - Weight in KG Primary) --}}
@php
    $z3 = $initialData['zone_3'] ?? [];
    $cards = $z3['action_cards'] ?? [];
    $defToday = $z3['defects_today'] ?? [];
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

    $todayBusinessDate = $initialData['zone_1']['date'] ?? now()->toDateString();

    // Card 3 metrics
    $ppicCard = $cards['ppic_defect_waiting_qc'] ?? [];
    $ppicKtrCount = $ppicCard['ktr_count'] ?? 0;
    $ppicDefectPcs = $ppicCard['pcs_defect'] ?? 0;
    $ppicDraftPcs = $ppicCard['draft_pcs'] ?? ($defToday['draft_breakdown_pcs'] ?? 0);
    $ppicUnallocatedPcs = $ppicCard['unallocated_pcs'] ?? ($defToday['unallocated_defect_pcs'] ?? 0);

    // Defects today metrics
    $todayTotalPcs = $defToday['total_defect_pcs'] ?? ($defToday['ppic_defect_pcs'] ?? 0);
    $todayTotalTon = $defToday['total_defect_tonnage'] ?? ($defToday['total_defect_ton'] ?? 0.0);
    $confirmedBreakdown = $defToday['confirmed_breakdown'] ?? ($defToday['verified_breakdown'] ?? []);
    $confirmedPcs = $defToday['confirmed_defect_pcs'] ?? 0;
    $draftBreakdown = $defToday['draft_breakdown'] ?? [];
    $draftPcs = $defToday['draft_breakdown_pcs'] ?? 0;
    $unallocatedPcs = $defToday['unallocated_defect_pcs'] ?? ($defToday['unverified_breakdown_pcs'] ?? 0);

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
                QUALITY & QC MONITORING
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

    <!-- 1. Three Action Backlog Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-2.5">
        <!-- Card 1: Belum Dicatat PPIC (WAITING_DEFECT) -->
        <div class="bg-white rounded-xl p-3 border border-slate-200 shadow-2xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-xs font-black text-slate-700 uppercase tracking-wide flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                        1. Belum Dicatat PPIC
                    </span>
                    <span class="px-1.5 py-0.2 rounded bg-amber-50 text-amber-800 border border-amber-200 text-[10px] font-mono font-bold">
                        WAITING_DEFECT
                    </span>
                </div>
                <div class="flex items-baseline gap-2 mb-1">
                    <span id="z3-unrecorded-ktr" class="text-2xl font-black tracking-tight text-slate-900 font-mono">
                        {{ number_format($cards['unrecorded_defects']['ktr_count'] ?? 0) }}
                    </span>
                    <span class="text-xs font-bold text-slate-500 uppercase">KTR</span>
                    <span class="text-slate-300 text-xs">/</span>
                    <span id="z3-unrecorded-pcs" class="text-sm font-black text-amber-800 font-mono">
                        {{ number_format($cards['unrecorded_defects']['pcs_waiting'] ?? 0) }}
                    </span>
                    <span class="text-[11px] text-slate-500 font-semibold">PCS Menunggu</span>
                </div>
                <p class="text-[11px] text-slate-500 leading-tight">Pekerjaan fisik selesai, PPIC belum mencatat laporan defect.</p>
            </div>
            <div class="pt-2 mt-2 border-t border-slate-100 flex items-center justify-between">
                <span class="text-[10px] text-slate-400 font-mono">Timeout: 5 hari</span>
                <a href="{{ route('sand-casting.defects.index') }}" class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-slate-100 hover:bg-slate-200 text-slate-800 text-[11px] font-bold transition-colors">
                    <span>Catat Defect</span>
                    <i class="fas fa-arrow-right text-[9px]"></i>
                </a>
            </div>
        </div>

        <!-- Card 2: Auto-Nihil Menunggu QC (AUTO_NIHIL_WAITING_QC) -->
        <div class="bg-white rounded-xl p-3 border border-slate-200 shadow-2xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-xs font-black text-slate-700 uppercase tracking-wide flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                        2. Auto-Nihil Menunggu QC
                    </span>
                    <span class="px-1.5 py-0.2 rounded bg-blue-50 text-blue-800 border border-blue-200 text-[10px] font-mono font-bold">
                        AUTO_NIHIL
                    </span>
                </div>
                <div class="flex items-baseline gap-2 mb-1">
                    <span id="z3-autonihil-ktr" class="text-2xl font-black tracking-tight text-slate-900 font-mono">
                        {{ number_format($cards['auto_nihil_waiting_qc']['ktr_count'] ?? 0) }}
                    </span>
                    <span class="text-xs font-bold text-slate-500 uppercase">KTR</span>
                    <span class="text-slate-300 text-xs">/</span>
                    <span class="text-sm font-black text-blue-700 font-mono">0</span>
                    <span class="text-[11px] text-slate-500 font-semibold">PCS Defect (Nihil)</span>
                </div>
                <p class="text-[11px] text-slate-500 leading-tight">Timeout otomatis 5 hari. Menunggu konfirmasi QC fisik.</p>
            </div>
            <div class="pt-2 mt-2 border-t border-slate-100 flex items-center justify-between">
                <span class="text-[10px] text-blue-700 font-mono font-bold">Sistem Timeout</span>
                <a href="{{ route('sand-casting.qc-defects.index') }}" class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 text-[11px] font-bold transition-colors">
                    <span>Verifikasi QC</span>
                    <i class="fas fa-arrow-right text-[9px]"></i>
                </a>
            </div>
        </div>

        <!-- Card 3: Defect Menunggu QC (DEFECT_WAITING_QC) -->
        <div class="bg-white rounded-xl p-3 border border-slate-200 shadow-2xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-xs font-black text-slate-700 uppercase tracking-wide flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                        3. Defect Menunggu QC
                    </span>
                    <span class="px-1.5 py-0.2 rounded bg-rose-50 text-rose-800 border border-rose-200 text-[10px] font-mono font-bold">
                        WAITING_QC
                    </span>
                </div>
                <div class="flex items-baseline gap-2 mb-1">
                    <span id="z3-ppicdefect-ktr" class="text-2xl font-black tracking-tight text-slate-900 font-mono">
                        {{ number_format($ppicKtrCount) }}
                    </span>
                    <span class="text-xs font-bold text-slate-500 uppercase">KTR</span>
                    <span class="text-slate-300 text-xs">/</span>
                    <span id="z3-ppicdefect-pcs" class="text-sm font-black text-rose-700 font-mono">
                        {{ number_format($ppicDefectPcs) }}
                    </span>
                    <span class="text-[11px] text-slate-500 font-semibold">PCS Defect PPIC</span>
                </div>
                <!-- Real-time Draft QC & Unallocated Breakdown Sub-metrics -->
                <div class="grid grid-cols-2 gap-1.5 my-1.5 p-1.5 rounded-lg bg-slate-50 border border-slate-100 font-mono text-[11px]">
                    <div class="flex flex-col">
                        <span class="text-[9px] text-slate-500 uppercase font-sans font-bold flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                            Draft QC
                        </span>
                        <span class="font-black text-blue-700 text-xs">
                            <span id="z3-ppicdefect-draft">{{ number_format($ppicDraftPcs) }}</span> PCS
                        </span>
                    </div>
                    <div class="flex flex-col border-l border-slate-200 pl-1.5">
                        <span class="text-[9px] text-slate-500 uppercase font-sans font-bold flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                            Belum Klasifikasi
                        </span>
                        <span class="font-black text-amber-700 text-xs">
                            <span id="z3-ppicdefect-unallocated">{{ number_format($ppicUnallocatedPcs) }}</span> PCS
                        </span>
                    </div>
                </div>
                <p class="text-[11px] text-slate-500 leading-tight">Defect dicatat PPIC, menunggu verifikasi fisik & klasifikasi QC.</p>
            </div>
            <div class="pt-2 mt-2 border-t border-slate-100 flex items-center justify-between">
                <span class="text-[10px] text-rose-700 font-mono font-bold">Prioritas QC</span>
                <a href="{{ route('sand-casting.qc-defects.index') }}" class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-[11px] font-bold transition-colors">
                    <span>Verifikasi QC</span>
                    <i class="fas fa-arrow-right text-[9px]"></i>
                </a>
            </div>
        </div>
    </div>

    @if(($cards['pre_activation_backlog']['ktr_count'] ?? 0) > 0)
        <!-- Historical Protected Backlog Notification -->
        <div class="p-2.5 rounded-lg border border-slate-200 bg-slate-50 text-slate-700 text-xs flex items-center justify-between shadow-2xs font-sans">
            <div class="flex items-center gap-2">
                <i class="fas fa-history text-slate-400"></i>
                <span><strong>Backlog Historis (< 09/10/2026):</strong> Terdapat <strong class="font-mono text-slate-900">{{ $cards['pre_activation_backlog']['ktr_count'] }} KTR</strong> ({{ number_format($cards['pre_activation_backlog']['pcs_waiting'] ?? 0) }} PCS) sebelum tanggal aktivasi yang dilindungi dari Auto-Nihil.</span>
            </div>
            <a href="{{ route('sand-casting.defects.index') }}" class="text-blue-700 hover:underline font-bold text-[11px] shrink-0 ml-2">
                Review Manual <i class="fas fa-arrow-right ml-0.5 text-[9px]"></i>
            </a>
        </div>
    @endif

    <!-- 2. Rincian Defect Hari Ini (Berdasarkan Tanggal Bisnis Defect) -->
    <div class="bg-white rounded-xl p-3 border border-slate-200 shadow-2xs">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1.5 border-b border-slate-100 pb-2 mb-2">
            <div>
                <h3 class="text-xs sm:text-sm font-black text-slate-900 uppercase tracking-wider flex items-center gap-1.5">
                    <i class="fas fa-clipboard-check text-rose-600"></i>
                    RINCIAN DEFECT HARI INI & PROGRES KLASIFIKASI QC
                </h3>
                <div class="text-[10px] text-slate-500 flex items-center gap-1.5 font-sans mt-0.5">
                    <span>Tanggal Bisnis Defect: <strong class="font-mono text-slate-800" id="z3-today-defect-date">{{ $todayBusinessDate }}</strong></span>
                    <span class="text-slate-300">•</span>
                    <span>Basis Inspeksi Fisik / Cor (Bukan Tanggal Verifikasi QC)</span>
                </div>
            </div>
            <div class="flex items-center gap-2 font-mono text-xs">
                <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 font-bold border border-slate-200 text-[11px]">
                    Total PPIC: <strong class="text-rose-700" id="z3-today-defect-pcs">{{ number_format($todayTotalPcs) }}</strong> PCS
                    <span class="text-slate-400">/</span> <strong class="text-slate-800" id="z3-today-defect-ton">{{ number_format($todayTotalTon, 2, ',', '.') }}</strong> TON
                </span>
            </div>
        </div>

        <!-- 3-Column Split: CONFIRMED vs DRAFT PROVISIONAL vs SISA BELUM DIKLASIFIKASIKAN -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-2.5 items-stretch">
            <!-- Box 1: CONFIRMED (Terkonfirmasi Final) -->
            <div class="rounded-lg border border-emerald-200 bg-emerald-50/30 p-2.5 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between border-b border-emerald-200/60 pb-1.5 mb-1.5">
                        <div class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-emerald-600"></span>
                            <span class="text-xs font-black text-emerald-950 uppercase tracking-wide">TERKONFIRMASI</span>
                        </div>
                        <span class="px-1.5 py-0.2 rounded bg-emerald-100 text-emerald-800 font-mono font-bold text-[10px]">
                            CONFIRMED
                        </span>
                    </div>
                    <div class="flex items-baseline justify-between mb-2">
                        <span class="text-[10px] text-emerald-800 font-sans font-bold uppercase">Klasifikasi Final QC:</span>
                        <span class="font-mono font-black text-emerald-800 text-sm">
                            <span id="z3-confirmed-pcs">{{ number_format($confirmedPcs) }}</span> PCS
                        </span>
                    </div>
                    <div class="overflow-x-auto max-h-36 overflow-y-auto custom-scrollbar border border-emerald-200/50 rounded bg-white">
                        <table class="w-full text-left border-collapse text-[11px]">
                            <thead>
                                <tr class="bg-emerald-50/70 text-emerald-900 text-[10px] font-bold uppercase border-b border-emerald-100">
                                    <th class="py-1 px-2">Jenis Defect</th>
                                    <th class="py-1 px-2">Dept</th>
                                    <th class="py-1 px-2 text-right">PCS</th>
                                    <th class="py-1 px-2 text-right">%</th>
                                </tr>
                            </thead>
                            <tbody id="z3-confirmed-breakdown-tbody" class="divide-y divide-slate-100 font-mono text-slate-700">
                                @forelse($confirmedBreakdown as $def)
                                    <tr class="hover:bg-emerald-50/40 transition-colors">
                                        <td class="py-1 px-2 font-sans font-bold text-slate-900 truncate max-w-[120px]" title="{{ $def['name'] }}">{{ $def['name'] }}</td>
                                        <td class="py-1 px-2 font-sans text-slate-500 text-[10px]">{{ $def['department'] ?? '-' }}</td>
                                        <td class="py-1 px-2 text-right font-bold text-slate-800">{{ number_format($def['qty_pcs']) }}</td>
                                        <td class="py-1 px-2 text-right text-slate-500 font-semibold">{{ number_format($def['percentage'], 1, ',', '.') }}%</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="py-3 text-center text-slate-400 font-sans text-[11px]">
                                            Belum ada defect terkonfirmasi hari ini.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="text-[10px] text-emerald-700 font-sans mt-2 pt-1 border-t border-emerald-200/60">
                    <i class="fas fa-check-circle mr-1"></i>Data final masuk ke KPI & analitik 30 hari resmi.
                </div>
            </div>

            <!-- Box 2: WAITING_QC DRAFT (Provisional Breakdown) -->
            <div class="rounded-lg border border-blue-200 bg-blue-50/30 p-2.5 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between border-b border-blue-200/60 pb-1.5 mb-1.5">
                        <div class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-blue-600"></span>
                            <span class="text-xs font-black text-blue-950 uppercase tracking-wide">DRAFT / PROVISIONAL</span>
                        </div>
                        <span class="px-1.5 py-0.2 rounded bg-blue-100 text-blue-800 font-mono font-bold text-[10px]">
                            WAITING_QC
                        </span>
                    </div>
                    <div class="flex items-baseline justify-between mb-2">
                        <span class="text-[10px] text-blue-800 font-sans font-bold uppercase">Draft QC Tersimpan:</span>
                        <span class="font-mono font-black text-blue-800 text-sm">
                            <span id="z3-draft-pcs">{{ number_format($draftPcs) }}</span> PCS
                        </span>
                    </div>
                    <div class="overflow-x-auto max-h-36 overflow-y-auto custom-scrollbar border border-blue-200/50 rounded bg-white">
                        <table class="w-full text-left border-collapse text-[11px]">
                            <thead>
                                <tr class="bg-blue-50/70 text-blue-900 text-[10px] font-bold uppercase border-b border-blue-100">
                                    <th class="py-1 px-2">Jenis Defect</th>
                                    <th class="py-1 px-2">Dept</th>
                                    <th class="py-1 px-2 text-right">PCS</th>
                                    <th class="py-1 px-2 text-right">%</th>
                                </tr>
                            </thead>
                            <tbody id="z3-draft-breakdown-tbody" class="divide-y divide-slate-100 font-mono text-slate-700">
                                @forelse($draftBreakdown as $def)
                                    <tr class="hover:bg-blue-50/40 transition-colors">
                                        <td class="py-1 px-2 font-sans font-bold text-slate-900 truncate max-w-[120px]" title="{{ $def['name'] }}">{{ $def['name'] }}</td>
                                        <td class="py-1 px-2 font-sans text-slate-500 text-[10px]">{{ $def['department'] ?? '-' }}</td>
                                        <td class="py-1 px-2 text-right font-bold text-slate-800">{{ number_format($def['qty_pcs']) }}</td>
                                        <td class="py-1 px-2 text-right text-slate-500 font-semibold">{{ number_format($def['percentage'], 1, ',', '.') }}%</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="py-3 text-center text-slate-400 font-sans text-[11px]">
                                            Belum ada draft breakdown tersimpan.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="text-[10px] text-blue-700 font-sans mt-2 pt-1 border-t border-blue-200/60">
                    <i class="fas fa-info-circle mr-1"></i>Provisional fisik tersimpan; belum dikonfirmasi final.
                </div>
            </div>

            <!-- Box 3: Sisa Belum Diklasifikasikan -->
            <div class="rounded-lg border border-amber-200 bg-amber-50/30 p-2.5 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between border-b border-amber-200/60 pb-1.5 mb-1.5">
                        <div class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                            <span class="text-xs font-black text-amber-950 uppercase tracking-wide">BELUM DIKLASIFIKASIKAN</span>
                        </div>
                        <span class="px-1.5 py-0.2 rounded bg-amber-100 text-amber-800 font-mono font-bold text-[10px]">
                            UNALLOCATED
                        </span>
                    </div>
                    <div class="flex items-baseline justify-between mb-2">
                        <span class="text-[10px] text-amber-800 font-sans font-bold uppercase">Sisa Defect PPIC:</span>
                        <span class="font-mono font-black text-amber-800 text-sm">
                            <span id="z3-unallocated-pcs">{{ number_format($unallocatedPcs) }}</span> PCS
                        </span>
                    </div>
                    <div class="p-3 rounded border border-amber-200/60 bg-white text-xs space-y-1.5">
                        <div class="flex items-center justify-between text-[11px] font-mono">
                            <span class="text-slate-600">Total Defect PPIC:</span>
                            <span class="font-bold text-slate-900">{{ number_format($todayTotalPcs) }} PCS</span>
                        </div>
                        <div class="flex items-center justify-between text-[11px] font-mono text-emerald-700">
                            <span>- Terkonfirmasi QC:</span>
                            <span class="font-bold">{{ number_format($confirmedPcs) }} PCS</span>
                        </div>
                        <div class="flex items-center justify-between text-[11px] font-mono text-blue-700">
                            <span>- Draft QC Tersimpan:</span>
                            <span class="font-bold">{{ number_format($draftPcs) }} PCS</span>
                        </div>
                        <div class="pt-1.5 border-t border-amber-100 flex items-center justify-between font-mono text-xs">
                            <span class="font-sans font-bold text-amber-900 text-[11px]">Sisa Unallocated:</span>
                            <span class="font-black text-amber-800 text-sm">{{ number_format($unallocatedPcs) }} PCS</span>
                        </div>
                    </div>
                </div>
                <div class="text-[10px] text-amber-800 font-sans mt-2 pt-1 border-t border-amber-200/60">
                    <i class="fas fa-exclamation-triangle mr-1 text-amber-600"></i>Rumus: max(0, defect PPIC - draft breakdown).
                </div>
            </div>
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
                    SATUAN: PCS & KG
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
            <span>Label batang: Baris 1 = Defect PCS, Baris 2 = Estimasi kg. Terverifikasi QC fisik (CONFIRMED).</span>
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
