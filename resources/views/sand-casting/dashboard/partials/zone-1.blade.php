{{-- Zone 1: Ringkasan Eksekutif Produksi Sand Casting --}}
<section id="zone-1-container" class="space-y-4">
    <!-- Zone 1 Header Banner -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-200 pb-3">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-blue-50 text-blue-700 border border-blue-200 text-xs font-bold">
                Z1
            </span>
            <div>
                <h2 class="text-base sm:text-lg font-bold tracking-tight text-slate-900 uppercase flex items-center gap-2">
                    RINGKASAN PRODUKSI (SAND CASTING)
                </h2>
                <p class="text-slate-500 text-xs">Output pengecoran harian, penerimaan gudang jadi & throughput 7 tahapan proses</p>
            </div>
        </div>
        <div class="flex items-center gap-2 text-xs">
            <span class="px-2.5 py-1 rounded bg-white text-slate-700 border border-slate-200 font-mono text-[11px] shadow-2xs">
                <i class="far fa-calendar-alt text-slate-400 mr-1.5"></i><span id="z1-current-date">{{ $initialData['zone_1']['date'] ?? now()->toDateString() }}</span>
            </span>
        </div>
    </div>

    <!-- 3 Executive KPI Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 sm:gap-4">
        <!-- KPI 1: Hasil Cor Hari Ini (KPI Utama) -->
        <div class="bg-white rounded-xl p-4 sm:p-5 border border-slate-200 shadow-xs relative flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Hasil Cor Hari Ini</span>
                    <span class="w-7 h-7 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-xs">
                        <i class="fas fa-fire-alt"></i>
                    </span>
                </div>
                <div class="flex items-baseline gap-2 mb-2">
                    <span id="z1-good-ton" class="text-3xl sm:text-4xl font-extrabold tracking-tight text-slate-900 font-mono">
                        {{ number_format($initialData['zone_1']['casting_today']['good_ton'] ?? 0, 2) }}
                    </span>
                    <span class="text-sm font-bold text-blue-600 uppercase">TON</span>
                    <span class="text-slate-300 text-xs font-medium">/</span>
                    <span id="z1-good-pcs" class="text-sm sm:text-base font-semibold text-slate-600 font-mono">
                        {{ number_format($initialData['zone_1']['casting_today']['good_pcs'] ?? 0) }}
                    </span>
                    <span class="text-xs font-medium text-slate-500">PCS</span>
                </div>
            </div>
            <div class="pt-2 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                <div class="flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                    <span>Reject Cor:</span>
                    <span id="z1-reject-ton" class="font-bold text-rose-600 font-mono">{{ number_format($initialData['zone_1']['casting_today']['reject_ton'] ?? 0, 2) }} T</span>
                    <span class="text-slate-400">({{ number_format($initialData['zone_1']['casting_today']['reject_pcs'] ?? 0) }} pcs)</span>
                </div>
                <div class="flex items-center gap-1">
                    <span class="text-slate-500">Heat:</span>
                    <span id="z1-heat-count" class="font-semibold text-slate-800 font-mono">{{ number_format($initialData['zone_1']['casting_today']['heat_count'] ?? 0) }}</span>
                </div>
            </div>
        </div>

        <!-- KPI 2: Penerimaan Gudang Jadi Hari Ini -->
        <div class="bg-white rounded-xl p-4 sm:p-5 border border-slate-200 shadow-xs relative flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Penerimaan Gudang Jadi</span>
                    <span class="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-xs">
                        <i class="fas fa-warehouse"></i>
                    </span>
                </div>
                <div class="flex items-baseline gap-2 mb-2">
                    <span id="z1-gd-ton" class="text-3xl sm:text-4xl font-extrabold tracking-tight text-slate-900 font-mono">
                        {{ number_format($initialData['zone_1']['warehouse_receipt_today']['good_ton'] ?? 0, 2) }}
                    </span>
                    <span class="text-sm font-bold text-emerald-600 uppercase">TON</span>
                    <span class="text-slate-300 text-xs font-medium">/</span>
                    <span id="z1-gd-pcs" class="text-sm sm:text-base font-semibold text-slate-600 font-mono">
                        {{ number_format($initialData['zone_1']['warehouse_receipt_today']['good_pcs'] ?? 0) }}
                    </span>
                    <span class="text-xs font-medium text-slate-500">PCS</span>
                </div>
            </div>
            <div class="pt-2 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                <div class="flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                    <span>Penerimaan Fisik:</span>
                    <span class="text-emerald-700 font-semibold font-mono text-[11px]">GUDANG_RECEIVE</span>
                </div>
                <div class="flex items-center gap-1">
                    <span class="text-slate-500">KTR Selesai:</span>
                    <span id="z1-gd-ktr" class="font-bold text-slate-800 font-mono">{{ number_format($initialData['zone_1']['warehouse_receipt_today']['ktr_count'] ?? 0) }}</span>
                </div>
            </div>
        </div>

        <!-- KPI 3: Status Kode Produksi -->
        <div class="bg-white rounded-xl p-4 sm:p-5 border border-slate-200 shadow-xs relative flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Status Kode Produksi (PO)</span>
                    <span class="w-7 h-7 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center text-xs">
                        <i class="fas fa-tasks"></i>
                    </span>
                </div>
                <div class="flex items-baseline gap-2 mb-2">
                    <span id="z1-active-codes" class="text-3xl sm:text-4xl font-extrabold tracking-tight text-slate-900 font-mono">
                        {{ number_format($initialData['zone_1']['production_code_health']['active_production_code_count'] ?? 0) }}
                    </span>
                    <span class="text-sm font-semibold text-slate-700 uppercase">KODE AKTIF</span>
                    <span class="text-slate-300 text-xs font-medium">/</span>
                    <span id="z1-completed-codes" class="text-sm sm:text-base font-semibold text-slate-500 font-mono">
                        {{ number_format($initialData['zone_1']['production_code_health']['completed_production_code_count'] ?? 0) }}
                    </span>
                    <span class="text-xs font-medium text-slate-500">Selesai</span>
                </div>
            </div>
            <div class="pt-2 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                <div class="flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 rounded-full {{ ($initialData['zone_1']['production_code_health']['cor_shortage_count'] ?? 0) > 0 ? 'bg-amber-500' : 'bg-slate-300' }}"></span>
                    <span>Perlu Cor (Shortage):</span>
                    <span id="z1-shortage-codes" class="font-bold {{ ($initialData['zone_1']['production_code_health']['cor_shortage_count'] ?? 0) > 0 ? 'text-amber-700' : 'text-slate-600' }} font-mono">
                        {{ number_format($initialData['zone_1']['production_code_health']['cor_shortage_count'] ?? 0) }}
                    </span>
                </div>
                <div class="flex items-center gap-1">
                    <span class="text-slate-500">Total PO:</span>
                    <span id="z1-total-plans" class="font-semibold text-slate-700 font-mono">{{ number_format($initialData['zone_1']['production_code_health']['total_plan_count'] ?? 0) }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Daily Throughput by Stage: Chart & Breakdown -->
    <div class="bg-white rounded-xl p-4 sm:p-5 border border-slate-200 shadow-xs">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4">
            <div>
                <h3 class="text-sm sm:text-base font-bold text-slate-900 uppercase tracking-wide flex items-center gap-2">
                    <i class="fas fa-chart-bar text-blue-600"></i>
                    THROUGHPUT HARIAN PER TAHAPAN PROSES
                </h3>
                <p class="text-xs text-slate-500">Volume output efektif dihitung berdasarkan data rekonsiliasi laporan produksi</p>
            </div>
            <div class="flex items-center gap-2 text-xs">
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200 font-medium">
                    <span class="w-2 h-2 rounded-full bg-blue-600"></span> Good Tonnage (TON)
                </span>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-rose-50 text-rose-700 border border-rose-200 font-medium">
                    <span class="w-2 h-2 rounded-full bg-rose-600"></span> Defect Rate (%)
                </span>
            </div>
        </div>

        <!-- Canvas Chart -->
        <div class="relative w-full h-56 sm:h-72 mb-4">
            <canvas id="chartThroughput"></canvas>
        </div>

        <!-- 7 Stages Matrix Table -->
        <div class="overflow-x-auto border border-slate-200 rounded-lg">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50 text-slate-600 uppercase text-[11px] font-bold border-b border-slate-200">
                        <th class="py-2.5 px-3">Tahapan Proses</th>
                        <th class="py-2.5 px-3 text-center">Urutan</th>
                        <th class="py-2.5 px-3 text-right">KTR</th>
                        <th class="py-2.5 px-3 text-right">Input (PCS)</th>
                        <th class="py-2.5 px-3 text-right text-slate-800">Good (PCS)</th>
                        <th class="py-2.5 px-3 text-right text-blue-700 font-bold">Good (TON)</th>
                        <th class="py-2.5 px-3 text-right text-rose-600">Defect (PCS)</th>
                        <th class="py-2.5 px-3 text-right">Defect Rate</th>
                    </tr>
                </thead>
                <tbody id="z1-throughput-tbody" class="divide-y divide-slate-100 font-mono text-slate-700">
                    @forelse($initialData['zone_1']['daily_throughput_stages'] ?? [] as $stg)
                        <tr class="hover:bg-slate-50/70 transition-colors">
                            <td class="py-2 px-3 font-sans font-bold text-slate-900 flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full {{ $loop->last ? 'bg-emerald-500' : ($loop->first ? 'bg-blue-600' : 'bg-slate-400') }}"></span>
                                {{ $stg['label'] }}
                            </td>
                            <td class="py-2 px-3 text-center text-slate-500 font-sans">{{ $stg['order'] }}</td>
                            <td class="py-2 px-3 text-right text-slate-700">{{ number_format($stg['ktr_count']) }}</td>
                            <td class="py-2 px-3 text-right text-slate-500">{{ number_format($stg['input_pcs'] ?? 0) }}</td>
                            <td class="py-2 px-3 text-right text-slate-800 font-semibold">{{ number_format($stg['good_pcs']) }}</td>
                            <td class="py-2 px-3 text-right text-blue-700 font-bold text-sm">{{ number_format($stg['good_tonnage'] ?? $stg['good_ton'] ?? 0, 2) }}</td>
                            <td class="py-2 px-3 text-right text-rose-600 font-semibold">{{ number_format($stg['defect_pcs']) }}</td>
                            <td class="py-2 px-3 text-right {{ ($stg['defect_rate_pct'] ?? 0) > 0 ? 'text-amber-700 font-semibold' : 'text-slate-500' }}">
                                {{ number_format($stg['defect_rate_pct'] ?? $stg['defect_rate'] ?? 0, 2) }}%
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-6 text-center text-slate-400 font-sans">
                                Belum ada data throughput pada tanggal ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
