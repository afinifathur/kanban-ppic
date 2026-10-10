{{-- Zone 2: WIP & Aliran Produksi Sand Casting --}}
<section id="zone-2-container" class="space-y-4">
    <!-- Zone 2 Header Banner -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-200 pb-3">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-blue-50 text-blue-700 border border-blue-200 text-xs font-bold">
                Z2
            </span>
            <div>
                <h2 class="text-base sm:text-lg font-bold tracking-tight text-slate-900 uppercase flex items-center gap-2">
                    WIP & ALIRAN PRODUKSI
                </h2>
                <p class="text-slate-500 text-xs">Distribusi beban kerja fisik aktif (5 Tahap) & pemantauan antrean FIFO (Aging)</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <!-- Active WIP Aggregate Badge -->
            <div class="px-3 py-1.5 rounded-lg bg-white border border-slate-200 flex items-center gap-2 text-xs shadow-2xs">
                <span class="text-slate-500 font-medium">Total WIP Aktif:</span>
                <span id="z2-total-wip-ton" class="text-slate-900 font-bold font-mono text-sm">
                    {{ number_format($initialData['zone_2']['total_active_wip_ton'] ?? 0, 2) }} TON
                </span>
                <span class="text-slate-300">/</span>
                <span id="z2-total-wip-pcs" class="text-slate-600 font-semibold font-mono">
                    {{ number_format($initialData['zone_2']['total_active_wip_pcs'] ?? 0) }} PCS
                </span>
            </div>
        </div>
    </div>

    <!-- Production Pipeline Flow Visualizer -->
    <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-xs">
        <div class="flex items-center justify-between mb-3 text-xs">
            <span class="font-bold text-slate-800 uppercase tracking-wide flex items-center gap-2">
                <i class="fas fa-stream text-blue-600"></i>
                PIPELINE ALIRAN OPERASIONAL SAND CASTING
            </span>
            <span class="text-[11px] text-slate-500 flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-blue-600"></span> 5 Tahap WIP Aktif
                <span class="text-slate-300">•</span>
                <span class="w-2 h-2 rounded-full bg-slate-400"></span> Gudang Jadi (Terminal)
            </span>
        </div>

        <!-- 7 Stages Flow Cards Grid -->
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-2">
            @php
                $distMap = collect($initialData['zone_2']['wip_distribution'] ?? [])->keyBy('stage');
                $maxWipTon = collect($initialData['zone_2']['wip_distribution'] ?? [])->max('wip_ton') ?? 0;
            @endphp

            <!-- Stage 1: Cor (Source / Origin) -->
            <div class="bg-slate-50 rounded-lg p-3 border border-slate-200 relative flex flex-col justify-between">
                <div>
                    <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1 flex items-center justify-between">
                        <span>1. Cor</span>
                        <i class="fas fa-industry text-slate-400"></i>
                    </div>
                    <div class="text-xs font-bold text-slate-800">Hasil Pengecoran</div>
                </div>
                <div class="text-[11px] text-slate-500 mt-2 pt-1 border-t border-slate-200/60">Titik Awal Input</div>
            </div>

            @php
                $activeStages = [
                    ['stage' => 'netto', 'num' => '2', 'label' => 'Netto'],
                    ['stage' => 'bubut_od', 'num' => '3', 'label' => 'Bubut OD'],
                    ['stage' => 'bubut_cnc', 'num' => '4', 'label' => 'Bubut CNC'],
                    ['stage' => 'bor', 'num' => '5', 'label' => 'Bor'],
                    ['stage' => 'qc', 'num' => '6', 'label' => 'QC'],
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
                <div class="{{ $isHighest ? 'bg-blue-50/50 border-2 border-blue-400/80 shadow-2xs' : 'bg-white border border-slate-200' }} rounded-lg p-3 relative flex flex-col justify-between">
                    <div>
                        <div class="text-[11px] font-bold {{ $isHighest ? 'text-blue-900' : 'text-slate-700' }} uppercase tracking-wide mb-1 flex items-center justify-between">
                            <span>{{ $stg['num'] }}. {{ $stg['label'] }}</span>
                            @if($isHighest)
                                <span class="px-1.5 py-0.5 rounded bg-blue-100 text-blue-700 text-[9px] font-bold uppercase tracking-wider">Tertinggi</span>
                            @endif
                        </div>
                        <!-- Tonase WIP: Angka Utama Paling Mudah Dilihat -->
                        <div id="p-{{ $sKey }}-ton" class="text-base sm:text-lg font-bold text-slate-900 font-mono leading-tight">
                            {{ number_format($sTon, 2) }} <span class="text-xs font-semibold text-blue-600">TON</span>
                        </div>
                    </div>
                    <!-- KTR & PCS: Informasi Sekunder -->
                    <div class="flex items-center justify-between text-xs text-slate-500 font-mono mt-2 pt-1 border-t {{ $isHighest ? 'border-blue-100' : 'border-slate-100' }}">
                        <span id="p-{{ $sKey }}-pcs">{{ number_format($sPcs) }} pcs</span>
                        <span id="p-{{ $sKey }}-ktr" class="font-semibold text-slate-700">{{ $sKtr }} KTR</span>
                    </div>
                </div>
            @endforeach

            <!-- Stage 7: Gudang Jadi (Terminal Output) -->
            <div class="bg-slate-50 rounded-lg p-3 border border-slate-200 relative flex flex-col justify-between">
                <div>
                    <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1 flex items-center justify-between">
                        <span>7. Gudang Jadi</span>
                        <span class="px-1.5 py-0.5 rounded bg-slate-200 text-slate-600 text-[9px] uppercase font-bold">Terminal</span>
                    </div>
                    <div class="text-xs font-bold text-slate-800">Penerimaan Selesai</div>
                </div>
                <div class="text-[11px] text-slate-500 mt-2 pt-1 border-t border-slate-200/60">Non-WIP (Output)</div>
            </div>
        </div>
    </div>

    <!-- WIP Distribution Donut & FIFO Aging Watchlist Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
        <!-- Left: WIP Distribution Donut Chart (5 Cols) -->
        <div class="lg:col-span-5 bg-white rounded-xl p-4 sm:p-5 border border-slate-200 shadow-xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm sm:text-base font-bold text-slate-900 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-chart-pie text-blue-600"></i>
                        Distribusi WIP Aktif (Ton)
                    </h3>
                    <span class="text-[11px] font-medium text-slate-500">5 Tahap Fisik</span>
                </div>

                <!-- Donut Canvas Container -->
                <div class="relative w-full h-48 sm:h-56 mb-3">
                    <canvas id="chartWipDonut"></canvas>
                </div>
            </div>

            <!-- Mini Breakdown Table -->
            <div class="overflow-x-auto border border-slate-200 rounded-lg text-xs mt-2">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-slate-600 text-[10px] font-bold uppercase border-b border-slate-200">
                            <th class="py-2 px-2.5">Tahap</th>
                            <th class="py-2 px-2.5 text-right">KTR</th>
                            <th class="py-2 px-2.5 text-right">PCS</th>
                            <th class="py-2 px-2.5 text-right text-slate-900">TON</th>
                            <th class="py-2 px-2.5 text-right">Porsi</th>
                        </tr>
                    </thead>
                    <tbody id="z2-wip-table-body" class="divide-y divide-slate-100 font-mono text-slate-700">
                        @foreach($initialData['zone_2']['wip_distribution'] ?? [] as $w)
                            <tr class="hover:bg-slate-50/70 transition-colors">
                                <td class="py-1.5 px-2.5 font-sans font-bold text-slate-900">{{ $w['label'] }}</td>
                                <td class="py-1.5 px-2.5 text-right text-slate-600">{{ number_format($w['ktr_count']) }}</td>
                                <td class="py-1.5 px-2.5 text-right text-slate-600">{{ number_format($w['wip_pcs']) }}</td>
                                <td class="py-1.5 px-2.5 text-right text-slate-900 font-bold">{{ number_format($w['wip_ton'], 2) }}</td>
                                <td class="py-1.5 px-2.5 text-right text-slate-500">{{ number_format($w['percentage'], 1) }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right: FIFO Aging Watchlist (7 Cols) -->
        <div class="lg:col-span-7 bg-white rounded-xl p-4 sm:p-5 border border-slate-200 shadow-xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h3 class="text-sm sm:text-base font-bold text-slate-900 uppercase tracking-wide flex items-center gap-2">
                            <i class="fas fa-hourglass-half text-amber-600"></i>
                            FIFO AGING WATCHLIST (TOP 5 TERTUA)
                        </h3>
                        <p class="text-xs text-slate-500">KTR Traveler dengan waktu tunggu antrean antartahap paling lama</p>
                    </div>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-amber-50 text-amber-800 border border-amber-200 uppercase tracking-wider">
                        PRIORITAS FIFO
                    </span>
                </div>

                <!-- Watchlist Table -->
                <div class="overflow-x-auto border border-slate-200 rounded-lg">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-50 text-slate-600 text-[10px] font-bold uppercase border-b border-slate-200">
                                <th class="py-2 px-3">KTR & Heat</th>
                                <th class="py-2 px-3">Produk / Item</th>
                                <th class="py-2 px-3">Tahap Aktif</th>
                                <th class="py-2 px-3 text-right">Usable</th>
                                <th class="py-2 px-3 text-center">Lama Antre</th>
                                <th class="py-2 px-3 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody id="z2-watchlist-tbody" class="divide-y divide-slate-100 font-mono text-slate-700">
                            @forelse($initialData['zone_2']['aging_watchlist'] ?? [] as $watch)
                                <tr class="hover:bg-slate-50/70 transition-colors">
                                    <td class="py-2 px-3 font-sans">
                                        <div class="font-bold text-slate-900">{{ $watch['traveler_number'] }}</div>
                                        <div class="text-[11px] text-slate-500 font-mono">Heat: {{ $watch['heat_number'] }}</div>
                                    </td>
                                    <td class="py-2 px-3 font-sans">
                                        <div class="font-bold text-slate-800">{{ $watch['production_code'] }}</div>
                                        <div class="text-[11px] text-slate-500 truncate max-w-[140px]">{{ $watch['item_name'] }}</div>
                                    </td>
                                    <td class="py-2 px-3 font-sans">
                                        <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 font-bold text-[10px] uppercase">
                                            {{ $watch['stage_label'] }}
                                        </span>
                                    </td>
                                    <td class="py-2 px-3 text-right">
                                        <div class="font-bold text-slate-900">{{ number_format($watch['qty_pcs']) }} pcs</div>
                                    </td>
                                    <td class="py-2 px-3 text-center font-sans">
                                        <span class="inline-flex items-center gap-1 font-bold {{ $watch['stage_aging_hours'] >= 72 ? 'text-rose-700' : ($watch['stage_aging_hours'] >= 24 ? 'text-amber-700' : 'text-slate-700') }}">
                                            <i class="far fa-clock text-[10px]"></i>
                                            {{ $watch['stage_aging_label'] }}
                                        </span>
                                        <div class="text-[10px] text-slate-400 font-mono">Total: {{ $watch['total_aging_days'] }}h</div>
                                    </td>
                                    <td class="py-2 px-3 text-center font-sans">
                                        @if($watch['is_urgent'])
                                            <span class="px-2 py-0.5 rounded bg-rose-50 text-rose-700 border border-rose-200 text-[10px] font-bold uppercase tracking-wider">
                                                URGENT
                                            </span>
                                        @else
                                            <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600 text-[10px] font-medium">
                                                NORMAL
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-6 text-center text-slate-400 font-sans">
                                        Tidak ada KTR aktif dalam daftar antrean aging saat ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Terminal Note Banner -->
            <div class="mt-3 p-2.5 rounded-lg bg-slate-50 border border-slate-200 text-[11px] text-slate-500 flex items-center justify-between">
                <span>
                    <i class="fas fa-info-circle text-slate-400 mr-1.5"></i>
                    Tahap Gudang Jadi merupakan titik penerimaan terminal akhir dan dikeluarkan dari perhitungan WIP operasional aktif.
                </span>
                <a href="{{ route('sand-casting.production-status.index') }}" class="text-blue-600 hover:underline font-bold text-[11px] shrink-0 ml-2">
                    Status Produksi <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>
        </div>
    </div>
</section>
