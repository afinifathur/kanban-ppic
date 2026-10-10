{{-- Zone 3: Kualitas, Defect & Tindakan Segera Sand Casting --}}
<section id="zone-3-container" class="space-y-4">
    <!-- Zone 3 Header Banner -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-200 pb-3">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-blue-50 text-blue-700 border border-blue-200 text-xs font-bold">
                Z3
            </span>
            <div>
                <h2 class="text-base sm:text-lg font-bold tracking-tight text-slate-900 uppercase flex items-center gap-2">
                    KUALITAS, DEFECT & TINDAKAN SEGERA
                </h2>
                <p class="text-slate-500 text-xs">Backlog aksi PPIC & QC, klasifikasi cacat riil terverifikasi, dan tren cacat 7 hari</p>
            </div>
        </div>
        <div class="flex items-center gap-2 text-xs">
            <span class="px-2.5 py-1 rounded bg-white text-slate-700 border border-slate-200 text-[11px] shadow-2xs">
                <i class="fas fa-shield-alt text-amber-600 mr-1.5"></i>QC Gatekeeper Active
            </span>
        </div>
    </div>

    <!-- 3 Distinct Action Backlog Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 sm:gap-4">
        <!-- Card 1: Belum Dicatat PPIC (WAITING_DEFECT) -->
        <div class="bg-white rounded-xl p-4 sm:p-5 border border-slate-200 shadow-xs relative flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-slate-700 uppercase tracking-wide flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                        1. Belum Dicatat PPIC
                    </span>
                    <span class="px-2 py-0.5 rounded bg-amber-50 text-amber-800 border border-amber-200 text-[10px] font-mono font-semibold">
                        WAITING_DEFECT
                    </span>
                </div>
                <div class="flex items-baseline gap-2 mb-2">
                    <span id="z3-unrecorded-ktr" class="text-3xl sm:text-4xl font-extrabold tracking-tight text-slate-900 font-mono">
                        {{ number_format($initialData['zone_3']['action_cards']['unrecorded_defects']['ktr_count'] ?? 0) }}
                    </span>
                    <span class="text-sm font-semibold text-slate-500 uppercase">KTR</span>
                    <span class="text-slate-300 text-xs font-medium">/</span>
                    <span id="z3-unrecorded-pcs" class="text-sm sm:text-base font-bold text-amber-800 font-mono">
                        {{ number_format($initialData['zone_3']['action_cards']['unrecorded_defects']['pcs_waiting'] ?? 0) }}
                    </span>
                    <span class="text-xs font-medium text-slate-500">PCS Menunggu</span>
                </div>
                <p class="text-xs text-slate-500 mb-3">Pekerjaan fisik selesai oleh operator. PPIC belum mencatat laporan defect.</p>
            </div>
            <div class="pt-2 border-t border-slate-100 flex items-center justify-between">
                <span class="text-[11px] text-slate-400">Batas timeout: 5 hari kalender</span>
                <a href="{{ route('sand-casting.defects.index') }}" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-semibold transition-colors">
                    <span>Catat Defect</span>
                    <i class="fas fa-arrow-right text-[10px]"></i>
                </a>
            </div>
        </div>

        <!-- Card 2: Auto-Nihil Menunggu QC (is_auto_nihil = true, WAITING_QC) -->
        <div class="bg-white rounded-xl p-4 sm:p-5 border border-slate-200 shadow-xs relative flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-slate-700 uppercase tracking-wide flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                        2. Auto-Nihil Menunggu QC
                    </span>
                    <span class="px-2 py-0.5 rounded bg-blue-50 text-blue-800 border border-blue-200 text-[10px] font-mono font-semibold">
                        AUTO_NIHIL
                    </span>
                </div>
                <div class="flex items-baseline gap-2 mb-2">
                    <span id="z3-autonihil-ktr" class="text-3xl sm:text-4xl font-extrabold tracking-tight text-slate-900 font-mono">
                        {{ number_format($initialData['zone_3']['action_cards']['auto_nihil_waiting_qc']['ktr_count'] ?? 0) }}
                    </span>
                    <span class="text-sm font-semibold text-slate-500 uppercase">KTR</span>
                    <span class="text-slate-300 text-xs font-medium">/</span>
                    <span class="text-sm sm:text-base font-bold text-blue-700 font-mono">0</span>
                    <span class="text-xs font-medium text-slate-500">PCS Defect (Nihil)</span>
                </div>
                <p class="text-xs text-slate-500 mb-3">Timeout otomatis sistem (5 hari kalender). Belum diverifikasi QC fisik.</p>
            </div>
            <div class="pt-2 border-t border-slate-100 flex items-center justify-between">
                <span class="text-[11px] text-blue-700 font-medium">Penetapan Otomatis Sistem</span>
                <a href="{{ route('sand-casting.qc-defects.index') }}" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 text-xs font-semibold transition-colors">
                    <span>Verifikasi QC</span>
                    <i class="fas fa-arrow-right text-[10px]"></i>
                </a>
            </div>
        </div>

        <!-- Card 3: Defect Menunggu QC (is_auto_nihil = false, WAITING_QC) -->
        <div class="bg-white rounded-xl p-4 sm:p-5 border border-slate-200 shadow-xs relative flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-slate-700 uppercase tracking-wide flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                        3. Defect Menunggu QC
                    </span>
                    <span class="px-2 py-0.5 rounded bg-rose-50 text-rose-800 border border-rose-200 text-[10px] font-mono font-semibold">
                        WAITING_QC
                    </span>
                </div>
                <div class="flex items-baseline gap-2 mb-2">
                    <span id="z3-ppicdefect-ktr" class="text-3xl sm:text-4xl font-extrabold tracking-tight text-slate-900 font-mono">
                        {{ number_format($initialData['zone_3']['action_cards']['ppic_defect_waiting_qc']['ktr_count'] ?? 0) }}
                    </span>
                    <span class="text-sm font-semibold text-slate-500 uppercase">KTR</span>
                    <span class="text-slate-300 text-xs font-medium">/</span>
                    <span id="z3-ppicdefect-pcs" class="text-sm sm:text-base font-bold text-rose-700 font-mono">
                        {{ number_format($initialData['zone_3']['action_cards']['ppic_defect_waiting_qc']['pcs_defect'] ?? 0) }}
                    </span>
                    <span class="text-xs font-medium text-slate-500">PCS Defect</span>
                </div>
                <p class="text-xs text-slate-500 mb-3">Defect telah dicatat oleh PPIC, menunggu verifikasi & klasifikasi oleh QC.</p>
            </div>
            <div class="pt-2 border-t border-slate-100 flex items-center justify-between">
                <span class="text-[11px] text-rose-700 font-medium">Prioritas Verifikasi</span>
                <a href="{{ route('sand-casting.qc-defects.index') }}" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-semibold transition-colors">
                    <span>Verifikasi QC</span>
                    <i class="fas fa-arrow-right text-[10px]"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Conditional Pre-Activation Backlog Banner (< 2026-10-09) -->
    @if(($initialData['zone_3']['action_cards']['pre_activation_backlog']['ktr_count'] ?? 0) > 0)
        <div class="p-3 rounded-lg bg-slate-50 border border-slate-200 text-xs flex items-center justify-between text-slate-600">
            <div class="flex items-center gap-2">
                <i class="fas fa-history text-slate-400"></i>
                <span class="font-bold text-slate-800">Backlog Historis (< 09/10/2026):</span>
                <span>Terdapat <span class="font-bold text-slate-900 font-mono">{{ $initialData['zone_3']['action_cards']['pre_activation_backlog']['ktr_count'] }} KTR</span> ({{ number_format($initialData['zone_3']['action_cards']['pre_activation_backlog']['pcs_waiting']) }} pcs) sebelum tanggal aktivasi yang dilindungi dari Auto-Nihil.</span>
            </div>
            <a href="{{ route('sand-casting.defects.index') }}" class="text-blue-600 hover:underline font-bold text-[11px]">
                Review Manual <i class="fas fa-external-link-alt ml-1"></i>
            </a>
        </div>
    @endif

    <!-- 2-Column: Defect Summary & 7-Day Trend -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
        <!-- Left: Defect Summary & Verified Breakdown (5 Cols) -->
        <div class="lg:col-span-5 bg-white rounded-xl p-4 sm:p-5 border border-slate-200 shadow-xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h3 class="text-sm sm:text-base font-bold text-slate-900 uppercase tracking-wide flex items-center gap-2">
                            <i class="fas fa-clipboard-check text-rose-600"></i>
                            DEFECT HARI INI & BREAKDOWN
                        </h3>
                        <p class="text-xs text-slate-500">Total defect hari ini berdasarkan inspeksi</p>
                    </div>
                    <div class="text-right">
                        <div class="text-lg font-extrabold text-rose-700 font-mono">
                            <span id="z3-today-defect-pcs">{{ number_format($initialData['zone_3']['defects_today']['total_defect_pcs'] ?? 0) }}</span> PCS
                        </div>
                        <div class="text-[11px] text-slate-500 font-mono">
                            <span id="z3-today-defect-ton">{{ number_format($initialData['zone_3']['defects_today']['total_defect_ton'] ?? 0, 2) }}</span> TON
                        </div>
                    </div>
                </div>

                <!-- Verified Defect Donut Canvas -->
                <div class="relative w-full h-44 sm:h-52 mb-3">
                    <canvas id="chartVerifiedDefects"></canvas>
                </div>

                <!-- Verified Label Banner -->
                <div class="text-[11px] font-semibold text-slate-700 mb-2 flex items-center justify-between bg-slate-50 border border-slate-200 p-2 rounded">
                    <span class="flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        Berdasarkan Verifikasi QC (CONFIRMED)
                    </span>
                    @if(($initialData['zone_3']['defects_today']['unverified_breakdown_pcs'] ?? 0) > 0)
                        <span class="text-amber-700 font-semibold">
                            <span id="z3-unverified-pcs">{{ number_format($initialData['zone_3']['defects_today']['unverified_breakdown_pcs']) }}</span> pcs belum verif
                        </span>
                    @endif
                </div>
            </div>

            <!-- Breakdown Table -->
            <div class="overflow-x-auto border border-slate-200 rounded-lg text-xs mt-2">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-slate-600 text-[10px] font-bold uppercase border-b border-slate-200">
                            <th class="py-1.5 px-2.5">Jenis Cacat</th>
                            <th class="py-1.5 px-2.5">Dept</th>
                            <th class="py-1.5 px-2.5 text-right">PCS</th>
                            <th class="py-1.5 px-2.5 text-right">Porsi</th>
                        </tr>
                    </thead>
                    <tbody id="z3-defects-tbody" class="divide-y divide-slate-100 font-mono text-slate-700">
                        @forelse($initialData['zone_3']['defects_today']['verified_breakdown'] ?? [] as $def)
                            <tr class="hover:bg-slate-50/70 transition-colors">
                                <td class="py-1.5 px-2.5 font-sans font-bold text-slate-900">{{ $def['name'] }}</td>
                                <td class="py-1.5 px-2.5 font-sans text-slate-500 text-[11px]">{{ $def['department'] }}</td>
                                <td class="py-1.5 px-2.5 text-right font-bold text-rose-600">{{ number_format($def['qty_pcs']) }}</td>
                                <td class="py-1.5 px-2.5 text-right text-slate-500">{{ number_format($def['percentage'], 1) }}%</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-4 text-center text-slate-400 font-sans">
                                    Belum ada defect terverifikasi QC hari ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right: 7-Day Defect Trend (7 Cols) -->
        <div class="lg:col-span-7 bg-white rounded-xl p-4 sm:p-5 border border-slate-200 shadow-xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h3 class="text-sm sm:text-base font-bold text-slate-900 uppercase tracking-wide flex items-center gap-2">
                            <i class="fas fa-chart-line text-blue-600"></i>
                            TREN DEFECT 7 HARI KALENDER
                        </h3>
                        <p class="text-xs text-slate-500">Jumlah reject & defect PCS per tanggal inspeksi (Asia/Jakarta)</p>
                    </div>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-700 border border-slate-200 uppercase tracking-wider">
                        7 HARI TERAKHIR
                    </span>
                </div>

                <!-- 7-Day Trend Canvas -->
                <div class="relative w-full h-48 sm:h-56 mb-3">
                    <canvas id="chartDefectTrend"></canvas>
                </div>
            </div>

            <!-- Mini 7 Days Metric Strip -->
            <div class="grid grid-cols-7 gap-1.5 pt-2 border-t border-slate-100 text-center font-mono" id="z3-trend-strip">
                @foreach($initialData['zone_3']['defect_trend_7_days'] ?? [] as $tDay)
                    <div class="p-1.5 rounded bg-slate-50 border border-slate-200">
                        <div class="text-[9px] text-slate-500 uppercase font-sans">{{ $tDay['day_label'] }}</div>
                        <div class="text-xs font-bold {{ $tDay['defect_pcs'] > 0 ? 'text-rose-700' : 'text-slate-400' }}">
                            {{ number_format($tDay['defect_pcs']) }}
                        </div>
                        <div class="text-[9px] text-slate-500">
                            {{ number_format($tDay['defect_tonnage'] ?? $tDay['defect_ton'] ?? 0, 2) }}T
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Top Action Required Items Table -->
    <div class="bg-white rounded-xl p-4 sm:p-5 border border-slate-200 shadow-xs">
        <div class="flex items-center justify-between mb-3">
            <div>
                <h3 class="text-sm sm:text-base font-bold text-slate-900 uppercase tracking-wide flex items-center gap-2">
                    <i class="fas fa-exclamation-triangle text-amber-600"></i>
                    DAFTAR TINDAKAN PRIORITAS (TOP ACTION ITEMS)
                </h3>
                <p class="text-xs text-slate-500">KTR yang mengalami penghentian total (Good Qty 0) atau mendekati batas timeout Auto-Nihil</p>
            </div>
            <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-600 uppercase tracking-wider">
                MAKSIMAL 5 ITEM
            </span>
        </div>

        <div class="overflow-x-auto border border-slate-200 rounded-lg">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50 text-slate-600 text-[10px] font-bold uppercase border-b border-slate-200">
                        <th class="py-2.5 px-3">Status & Tingkat</th>
                        <th class="py-2.5 px-3">KTR & Heat</th>
                        <th class="py-2.5 px-3">Kode Produksi</th>
                        <th class="py-2.5 px-3">Tahap & Checkpoint</th>
                        <th class="py-2.5 px-3">Deskripsi Isu</th>
                        <th class="py-2.5 px-3">Rekomendasi Tindakan</th>
                        <th class="py-2.5 px-3 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody id="z3-action-items-tbody" class="divide-y divide-slate-100 font-sans text-slate-700">
                    @forelse($initialData['zone_3']['top_action_items'] ?? [] as $act)
                        <tr class="hover:bg-slate-50/70 transition-colors">
                            <td class="py-2 px-3">
                                @if(($act['severity'] ?? '') === 'CRITICAL')
                                    <span class="px-2 py-0.5 rounded bg-rose-50 text-rose-700 border border-rose-200 font-bold text-[10px] uppercase">
                                        KRITIS (HALTED)
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 rounded bg-amber-50 text-amber-800 border border-amber-200 font-semibold text-[10px] uppercase">
                                        PERINGATAN
                                    </span>
                                @endif
                            </td>
                            <td class="py-2 px-3 font-mono">
                                <div class="font-bold text-slate-900">{{ $act['traveler_number'] }}</div>
                                <div class="text-[11px] text-slate-500 font-sans">Heat: {{ $act['heat_number'] }}</div>
                            </td>
                            <td class="py-2 px-3 font-mono font-bold text-slate-800">
                                {{ $act['production_code'] }}
                            </td>
                            <td class="py-2 px-3">
                                <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 font-semibold text-[10px] uppercase font-mono">
                                    {{ $act['stage'] }}
                                </span>
                            </td>
                            <td class="py-2 px-3 text-slate-800 font-medium">
                                {{ $act['issue'] }}
                            </td>
                            <td class="py-2 px-3 text-slate-500 text-[11px]">
                                {{ $act['action_recommendation'] }}
                            </td>
                            <td class="py-2 px-3 text-center">
                                <a href="{{ route($act['action_route'] ?? 'sand-casting.defects.index') }}" class="inline-flex items-center gap-1 px-2.5 py-1 rounded bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 text-[11px] font-semibold transition-colors">
                                    <span>Tindak Lanjuti</span>
                                    <i class="fas fa-external-link-alt text-[9px]"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-6 text-center text-slate-400">
                                <i class="fas fa-check-circle text-emerald-600 mr-1.5"></i>
                                Tidak ada tindakan kritis yang tertunda saat ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
