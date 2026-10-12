{{-- Zone 2: WIP & Aliran Produksi Sand Casting (SCADA Style) --}}
<section id="zone-2-container" class="space-y-3 sm:space-y-4">
    <!-- Zone 2 Header Banner (Compact SCADA) -->
    <div class="flex items-center justify-between border-b border-slate-200 pb-2">
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center justify-center px-2 py-0.5 rounded bg-slate-800 text-white text-xs font-black tracking-wider">
                Z2
            </span>
            <h2 class="text-base sm:text-lg font-black tracking-tight text-slate-900 uppercase">
                WIP & ALIRAN PRODUKSI
            </h2>
        </div>
        <div class="flex items-center gap-2">
            <!-- Active WIP Aggregate Badge -->
            <div class="px-3 py-1 rounded bg-white border border-slate-300 flex items-center gap-2 text-xs shadow-2xs font-mono">
                <span class="text-slate-500 font-sans font-bold">Total WIP Aktif:</span>
                <span id="z2-total-wip-ton" class="text-slate-900 font-black text-sm">
                    {{ number_format($initialData['zone_2']['total_active_wip_ton'] ?? 0, 2) }} TON
                </span>
                <span class="text-slate-300">/</span>
                <span id="z2-total-wip-pcs" class="text-slate-600 font-bold">
                    {{ number_format($initialData['zone_2']['total_active_wip_pcs'] ?? 0) }} PCS
                </span>
            </div>
        </div>
    </div>

    <!-- 7-Stage Production Flow Cards (SCADA Pipeline) -->
    <div class="bg-white rounded-xl p-3 sm:p-4 border border-slate-200 shadow-2xs">
        <div class="flex items-center justify-between mb-2.5 text-xs">
            <span class="font-black text-slate-800 uppercase tracking-wider flex items-center gap-1.5 text-[11px]">
                <i class="fas fa-stream text-blue-600"></i>
                PIPELINE ALIRAN OPERASIONAL (7 TAHAP)
            </span>
            <span class="text-[11px] font-bold text-slate-500 flex items-center gap-2">
                <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-blue-600"></span> 5 WIP Aktif</span>
                <span class="text-slate-300">•</span>
                <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-slate-400"></span> Terminal Gudang</span>
            </span>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-2">
            @php
                $distMap = collect($initialData['zone_2']['wip_distribution'] ?? [])->keyBy('stage');
                $maxWipTon = collect($initialData['zone_2']['wip_distribution'] ?? [])->max('wip_ton') ?? 0;
            @endphp

            <!-- Stage 1: Cor (Source / Origin) -->
            <div class="bg-slate-50 rounded-lg p-2.5 border border-slate-200 flex flex-col justify-between">
                <div>
                    <div class="text-[10px] font-black text-slate-400 uppercase tracking-wider mb-1">
                        1. COR
                    </div>
                    <div class="text-xs font-bold text-slate-800 leading-tight">Titik Awal</div>
                </div>
                <div class="mt-2 pt-1 border-t border-slate-200 text-[10px] text-slate-500 font-semibold">
                    Input Fisik
                </div>
            </div>

            @php
                $activeStages = [
                    ['stage' => 'netto', 'num' => '2', 'label' => 'NETTO', 'color' => '#2563eb'],
                    ['stage' => 'bubut_od', 'num' => '3', 'label' => 'BUBUT OD', 'color' => '#0284c7'],
                    ['stage' => 'bubut_cnc', 'num' => '4', 'label' => 'BUBUT CNC', 'color' => '#0d9488'],
                    ['stage' => 'bor', 'num' => '5', 'label' => 'BOR', 'color' => '#d97706'],
                    ['stage' => 'qc', 'num' => '6', 'label' => 'QC', 'color' => '#64748b'],
                ];
            @endphp

            @foreach($activeStages as $stg)
                @php
                    $sKey = $stg['stage'];
                    $sData = $distMap[$sKey] ?? [];
                    $sTon = (float) ($sData['wip_ton'] ?? 0);
                    $sPcs = (int) ($sData['wip_pcs'] ?? 0);
                    $sKtr = (int) ($sData['ktr_count'] ?? 0);
                    $isHighest = ($maxWipTon > 0 && $sTon === (float) $maxWipTon);
                @endphp
                <div class="{{ $isHighest ? 'bg-blue-50/60 border-2 border-blue-500 shadow-2xs' : 'bg-white border border-slate-200' }} rounded-lg p-2.5 flex flex-col justify-between">
                    <div>
                        <div class="text-[11px] font-black {{ $isHighest ? 'text-blue-900' : 'text-slate-700' }} uppercase tracking-wider mb-1 flex items-center justify-between">
                            <span>{{ $stg['num'] }}. {{ $stg['label'] }}</span>
                            @if($isHighest)
                                <span class="px-1 py-0.2 rounded bg-blue-600 text-white text-[8px] font-black tracking-widest uppercase">TERTINGGI</span>
                            @endif
                        </div>
                        <!-- Tonase WIP: Angka Utama Terbesar -->
                        <div id="p-{{ $sKey }}-ton" class="text-xl sm:text-2xl font-black text-slate-900 font-mono leading-none tracking-tight">
                            {{ number_format($sTon, 2) }} <span class="text-xs font-black text-blue-600 uppercase">TON</span>
                        </div>
                    </div>
                    <!-- PCS & KTR: Single Secondary Line -->
                    <div class="flex items-center justify-between text-xs text-slate-600 font-mono mt-2 pt-1 border-t {{ $isHighest ? 'border-blue-200' : 'border-slate-100' }}">
                        <span id="p-{{ $sKey }}-pcs" class="font-semibold">{{ number_format($sPcs) }} PCS</span>
                        <span id="p-{{ $sKey }}-ktr" class="font-bold text-slate-800">{{ $sKtr }} KTR</span>
                    </div>
                </div>
            @endforeach

            <!-- Stage 7: Gudang Jadi (Terminal Output) -->
            <div class="bg-slate-50 rounded-lg p-2.5 border border-slate-200 flex flex-col justify-between">
                <div>
                    <div class="text-[10px] font-black text-slate-400 uppercase tracking-wider mb-1 flex items-center justify-between">
                        <span>7. GUDANG JADI</span>
                        <span class="px-1 py-0.2 rounded bg-slate-200 text-slate-700 text-[8px] font-bold">TERMINAL</span>
                    </div>
                    <div class="text-xs font-bold text-slate-800 leading-tight">Penerimaan Selesai</div>
                </div>
                <div class="mt-2 pt-1 border-t border-slate-200 text-[10px] text-slate-500 font-semibold">
                    Non-WIP (Output)
                </div>
            </div>
        </div>
    </div>

    <!-- WIP Distribution Donut & FIFO Aging Watchlist Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-3 sm:gap-4">
        <!-- Left: WIP Distribution Donut Chart & Direct Percentages (5 Cols) -->
        <div class="lg:col-span-5 bg-white rounded-xl p-4 border border-slate-200 shadow-2xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-xs sm:text-sm font-black text-slate-900 uppercase tracking-wider flex items-center gap-1.5">
                        <i class="fas fa-chart-pie text-blue-600"></i>
                        DISTRIBUSI WIP AKTIF (TON)
                    </h3>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-600 uppercase font-mono">
                        5 TAHAP FISIK
                    </span>
                </div>

                <!-- Donut Chart & Direct Readable Legend Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center">
                    <!-- Donut Canvas (5 Cols) -->
                    <div class="sm:col-span-6 relative w-full h-44 sm:h-52">
                        <canvas id="chartWipDonut"></canvas>
                    </div>

                    <!-- Direct Readable External Legend (6 Cols) -->
                    <div class="sm:col-span-6 space-y-1.5 font-mono text-xs" id="z2-wip-direct-legend">
                        @php
                            $colorsMap = [
                                'netto' => '#2563eb',
                                'bubut_od' => '#0284c7',
                                'bubut_cnc' => '#0d9488',
                                'bor' => '#d97706',
                                'qc' => '#64748b',
                            ];
                        @endphp
                        @foreach($initialData['zone_2']['wip_distribution'] ?? [] as $w)
                            @php
                                $col = $colorsMap[$w['stage']] ?? '#64748b';
                            @endphp
                            <div class="p-1.5 rounded-lg border border-slate-100 bg-slate-50/70 flex items-center justify-between hover:bg-slate-100 transition-colors">
                                <div class="flex items-center gap-2">
                                    <span class="w-3 h-3 rounded-full shrink-0" style="background-color: {{ $col }};"></span>
                                    <span class="font-sans font-bold text-slate-800 text-[11px]">{{ $w['label'] }}</span>
                                </div>
                                <div class="text-right">
                                    <span class="font-black text-slate-900 text-xs">{{ number_format($w['percentage'], 1) }}%</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Single Line Status Helper -->
            <div class="pt-2 mt-2 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-500 font-sans">
                <span>Basis persentase dihitung dari total WIP aktif 5 tahap.</span>
                <span class="font-bold text-slate-700 font-mono">{{ number_format($initialData['zone_2']['total_active_wip_ton'] ?? 0, 2) }} TON</span>
            </div>
        </div>

        <!-- Right: FIFO Aging Watchlist (7 Cols) -->
        <div class="lg:col-span-7 bg-white rounded-xl p-4 border border-slate-200 shadow-2xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-xs sm:text-sm font-black text-slate-900 uppercase tracking-wider flex items-center gap-1.5">
                        <i class="fas fa-hourglass-half text-amber-600"></i>
                        FIFO AGING WATCHLIST (TOP 5 TERTUA)
                    </h3>
                    <span class="text-[10px] font-black px-2 py-0.5 rounded bg-amber-50 text-amber-900 border border-amber-300 uppercase tracking-wider">
                        PRIORITAS FIFO
                    </span>
                </div>

                <!-- Streamlined SCADA Table -->
                <div class="overflow-x-auto border border-slate-200 rounded-lg">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-50 text-slate-600 text-[10px] font-black uppercase border-b border-slate-200">
                                <th class="py-1.5 px-2.5">KTR & Heat</th>
                                <th class="py-1.5 px-2.5">Produk / Item</th>
                                <th class="py-1.5 px-2.5">Tahap</th>
                                <th class="py-1.5 px-2.5 text-right">Usable</th>
                                <th class="py-1.5 px-2.5 text-right">Lama Antre</th>
                            </tr>
                        </thead>
                        <tbody id="z2-watchlist-tbody" class="divide-y divide-slate-100 font-mono text-slate-700">
                            @forelse($initialData['zone_2']['aging_watchlist'] ?? [] as $watch)
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="py-1.5 px-2.5 font-sans">
                                        <div class="font-bold text-slate-900 leading-tight">{{ $watch['traveler_number'] }}</div>
                                        <div class="text-[10px] text-slate-500 font-mono">Heat: {{ $watch['heat_number'] }}</div>
                                    </td>
                                    <td class="py-1.5 px-2.5 font-sans">
                                        <div class="font-bold text-slate-800 leading-tight">{{ $watch['production_code'] }}</div>
                                        <div class="text-[10px] text-slate-500 truncate max-w-[180px] lg:max-w-[210px]">{{ $watch['item_name'] }}</div>
                                    </td>
                                    <td class="py-1.5 px-2.5 font-sans">
                                        <span class="px-1.5 py-0.5 rounded bg-slate-100 text-slate-800 font-bold text-[10px] uppercase">
                                            {{ $watch['stage_label'] }}
                                        </span>
                                    </td>
                                    <td class="py-1.5 px-2.5 text-right font-black text-slate-900">
                                        {{ number_format($watch['qty_pcs']) }} pcs
                                    </td>
                                    <td class="py-1.5 px-2.5 text-right font-sans">
                                        <span class="inline-flex items-center gap-1 font-black {{ $watch['stage_aging_hours'] >= 72 ? 'text-rose-700' : ($watch['stage_aging_hours'] >= 24 ? 'text-amber-700' : 'text-slate-800') }}">
                                            <i class="far fa-clock text-[10px]"></i>
                                            {{ $watch['stage_aging_label'] }}
                                        </span>
                                        <div class="text-[9px] text-slate-400 font-mono">Total {{ $watch['total_aging_days'] }}h</div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-4 text-center text-slate-400 font-sans">
                                        Tidak ada KTR aktif dalam daftar antrean aging saat ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Concise Footer Link -->
            <div class="pt-2 mt-2 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-500 font-sans">
                <span>Gudang Jadi (terminal) dikeluarkan dari antrean WIP.</span>
                <a href="{{ route('sand-casting.production-status.index') }}" class="text-blue-700 hover:underline font-bold text-[11px]">
                    Lihat Status Lengkap <i class="fas fa-arrow-right ml-1 text-[9px]"></i>
                </a>
            </div>
        </div>
    </div>
</section>
