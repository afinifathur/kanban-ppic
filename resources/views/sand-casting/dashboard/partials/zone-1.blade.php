{{-- Zone 1: Ringkasan Produksi Sand Casting (SCADA Style - Balanced Layout) --}}
<section id="zone-1-container" class="space-y-2.5 sm:space-y-3">
    <!-- Zone 1 Header Banner (Compact SCADA) -->
    <div class="flex items-center justify-between border-b border-slate-200 pb-1.5">
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center justify-center px-2 py-0.5 rounded bg-slate-800 text-white text-xs font-black tracking-wider">
                Z1
            </span>
            <h2 class="text-base sm:text-lg font-black tracking-tight text-slate-900 uppercase">
                RINGKASAN PRODUKSI
            </h2>
        </div>
        <div class="flex items-center gap-2 text-xs">
            <span class="px-2.5 py-0.5 rounded bg-white text-slate-700 border border-slate-300 font-mono text-[11px] font-bold shadow-2xs">
                <i class="far fa-calendar-alt text-slate-400 mr-1.5"></i><span id="z1-current-date">{{ $initialData['zone_1']['date'] ?? now()->toDateString() }}</span>
            </span>
        </div>
    </div>

    <!-- Ringkasan Defect Terverifikasi Kemarin (Compact Card) -->
    @php
        $yesterdayDefect = $initialData['zone_1']['yesterday_verified_defect'] ?? null;
        $hasYesterdayDefect = $yesterdayDefect !== null && isset($yesterdayDefect['pcs']);
    @endphp
    <div class="bg-white rounded-lg p-2.5 sm:p-3 border border-slate-200 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-2">
        <div class="flex items-center gap-2.5">
            <span class="w-7 h-7 rounded bg-rose-50 text-rose-700 border border-rose-200 flex items-center justify-center text-xs font-black shrink-0">
                <i class="fas fa-shield-alt"></i>
            </span>
            <div>
                <div class="text-[10px] font-black text-slate-500 uppercase tracking-wider flex items-center gap-1.5">
                    <span>DEFECT TERVERIFIKASI KEMARIN</span>
                    <span class="text-[9px] px-1.5 py-0.2 rounded bg-slate-100 text-slate-600 font-mono font-bold">BASIS INSPEKSI QC</span>
                </div>
                <div class="flex items-baseline gap-2 font-mono" id="z1-yesterday-defect-container">
                    @if($hasYesterdayDefect)
                        <span class="text-base sm:text-lg font-black text-rose-700 leading-tight">
                            {{ number_format($yesterdayDefect['pcs']) }} PCS
                        </span>
                        @if(isset($yesterdayDefect['weight_kg']))
                            <span class="text-slate-300">/</span>
                            <span class="text-xs sm:text-sm font-bold text-slate-700">
                                {{ number_format($yesterdayDefect['weight_kg'], 1) }} kg
                            </span>
                        @endif
                    @else
                        <span class="text-xs sm:text-sm font-bold text-slate-600 italic">
                            Data H-1 belum tersedia di payload API (Memerlukan penambahan agregasi backend)
                        </span>
                    @endif
                </div>
            </div>
        </div>
        <div class="text-[10px] font-mono text-slate-500 shrink-0 flex items-center gap-2">
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-slate-50 border border-slate-200">
                <i class="fas fa-check-circle text-emerald-600 text-[9px]"></i> Status: QC CONFIRMED Only
            </span>
        </div>
    </div>

    <!-- Daily Throughput by Stage: Proportional Canvas with Direct Above-Bar Data Labels -->
    <div class="bg-white rounded-xl p-3.5 sm:p-4 border border-slate-200 shadow-2xs flex flex-col justify-between">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-2">
            <h3 class="text-xs sm:text-sm font-black text-slate-900 uppercase tracking-wider flex items-center gap-2">
                <i class="fas fa-chart-bar text-blue-600"></i>
                THROUGHPUT 7 TAHAP PROSES (TON & PCS)
            </h3>
            <div class="flex items-center gap-2 text-xs font-bold">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded bg-blue-50 text-blue-800 border border-blue-200 text-[11px]">
                    <span class="w-2.5 h-2.5 rounded bg-blue-600"></span> Output Baik (TON & PCS langsung di atas batang)
                </span>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded bg-rose-50 text-rose-800 border border-rose-200 text-[11px]">
                    <span class="w-2.5 h-2.5 rounded-full bg-rose-600"></span> Defect Rate (%)
                </span>
            </div>
        </div>

        <!-- Canvas Chart with Direct Data Labels rendered directly above each bar (Proportional Height) -->
        <div class="relative w-full h-[460px] sm:h-[480px] lg:h-[500px] xl:h-[520px]">
            <canvas id="chartThroughput"></canvas>
        </div>
    </div>
</section>
