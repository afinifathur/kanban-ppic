@extends('layouts.app')

@section('top_bar')
    <div class="flex flex-col md:flex-row md:items-center justify-between w-full gap-2 py-0.5">
        <!-- Left: SCADA Title -->
        <div class="flex items-center gap-2 shrink-0">
            <span class="px-2 py-0.5 rounded text-[10px] font-black bg-slate-800 text-white tracking-wider uppercase">
                SCADA
            </span>
            <h1 class="text-base sm:text-lg font-black text-slate-900 tracking-tight leading-none uppercase">
                DASHBOARD OPERASIONAL
            </h1>
        </div>

        <!-- Center: Zone Switcher Pills -->
        <div class="flex items-center gap-1 bg-slate-100 p-0.5 rounded-lg border border-slate-200 text-xs shrink-0 self-start md:self-auto overflow-x-auto no-scrollbar">
            <a href="{{ route('sand-casting.dashboard') }}"
                class="px-3 py-1 rounded-md font-bold transition-all {{ $activeZone === 'all' ? 'bg-white text-blue-700 shadow-xs' : 'text-slate-600 hover:text-slate-900' }}">
                Semua Zona
            </a>
            <a href="{{ route('sand-casting.dashboard', ['zone' => '1']) }}"
                class="px-3 py-1 rounded-md font-bold transition-all {{ $activeZone === '1' ? 'bg-white text-blue-700 shadow-xs' : 'text-slate-600 hover:text-slate-900' }}">
                Zona 1
            </a>
            <a href="{{ route('sand-casting.dashboard', ['zone' => '2']) }}"
                class="px-3 py-1 rounded-md font-bold transition-all {{ $activeZone === '2' ? 'bg-white text-blue-700 shadow-xs' : 'text-slate-600 hover:text-slate-900' }}">
                Zona 2
            </a>
            <a href="{{ route('sand-casting.dashboard', ['zone' => '3']) }}"
                class="px-3 py-1 rounded-md font-bold transition-all {{ $activeZone === '3' ? 'bg-white text-blue-700 shadow-xs' : 'text-slate-600 hover:text-slate-900' }}">
                Zona 3
            </a>
            <a href="{{ route('sand-casting.dashboard', ['zone' => '4']) }}"
                class="px-3 py-1 rounded-md font-bold transition-all {{ $activeZone === '4' ? 'bg-white text-blue-700 shadow-xs' : 'text-slate-600 hover:text-slate-900' }}">
                Zona 4
            </a>
        </div>

        <!-- Right: Status Badge & Live Clock -->
        <div class="flex items-center gap-2.5 text-xs shrink-0">
            <!-- Heartbeat Status Indicator -->
            <div id="heartbeat-badge" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border font-black uppercase tracking-wider text-[11px] bg-emerald-50 text-emerald-800 border-emerald-300">
                <span id="heartbeat-dot" class="w-2 h-2 rounded-full bg-emerald-600"></span>
                <span id="heartbeat-text">LIVE</span>
            </div>

            <!-- Last Refresh & Live Clock -->
            <div class="flex flex-col text-right leading-tight">
                <span id="live-clock" class="font-mono font-black text-slate-900 text-xs">--:--:-- WIB</span>
                <span id="last-refresh-time" class="text-[10px] font-mono text-slate-500 font-medium">Update: Baru saja</span>
            </div>

            <!-- Manual Refresh Button -->
            <button type="button" id="btn-manual-refresh" onclick="triggerManualRefresh()"
                class="p-1.5 rounded-lg border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 transition-colors shadow-2xs"
                title="Segarkan Data Sekarang">
                <i id="refresh-icon" class="fas fa-sync-alt text-xs"></i>
            </button>
        </div>
    </div>
@endsection

@section('content')
<div class="space-y-4">
    <!-- Alert Banner for STALE or OFFLINE state -->
    <div id="connection-alert" class="hidden rounded-xl p-3 border text-xs flex items-center justify-between shadow-xs">
        <div class="flex items-center gap-2">
            <i id="connection-alert-icon" class="fas fa-exclamation-triangle text-base"></i>
            <span id="connection-alert-msg" class="font-medium"></span>
        </div>
        <span class="text-[10px] uppercase font-mono opacity-80" id="connection-alert-sub">Mempertahankan Data Terakhir</span>
    </div>

    <!-- Master Industrial Dashboard Container (Light Mode, Compact SCADA) -->
    <div class="space-y-4">
        {{-- Zone 1: Executive Overview --}}
        @if($activeZone === 'all' || $activeZone === '1')
            @include('sand-casting.dashboard.partials.zone-1')
        @endif

        {{-- Zone 2: WIP & Production Flow --}}
        @if($activeZone === 'all' || $activeZone === '2')
            @include('sand-casting.dashboard.partials.zone-2')
        @endif

        {{-- Zone 3: Quality & Defect Analysis --}}
        @if($activeZone === 'all' || $activeZone === '3')
            @include('sand-casting.dashboard.partials.zone-3')
        @endif

        {{-- Zone 4: Daily Production Tonnage Trend --}}
        @if($activeZone === 'all' || $activeZone === '4')
            @include('sand-casting.dashboard.partials.zone-4')
        @endif
    </div>
</div>

{{-- Chart.js Vendored Library --}}
<script src="{{ asset('js/chart.min.js') }}"></script>

<script>
    // Global dashboard configuration
    const DASHBOARD_CONFIG = {
        zone: @json($activeZone),
        dataEndpoint: @json(route('sand-casting.dashboard.data')),
        pollIntervalMs: 60000, // 60 seconds
        initialData: @json($initialData),
    };

    // State management
    let lastSuccessfulRefresh = new Date();
    let consecutiveFailures = 0;
    let pollIntervalTimer = null;
    let heartbeatTickTimer = null;
    let isPolling = false;

    // Chart.js Registry to prevent duplicate instances
    const dashboardCharts = {
        throughput: null,
        wipDonut: null,
        overallDefects: null,
        defectTrend30: null,
        stageDonuts: {},
        zone4Throughput: null,
        currentZone4Stage: 'qc',
    };

    // Light industrial Chart.js defaults
    Chart.defaults.color = '#475569';
    Chart.defaults.borderColor = '#e2e8f0';
    Chart.defaults.font.family = 'ui-sans-serif, system-ui, sans-serif';

    // Format helper
    function formatNumber(num, decimals = 0) {
        if (num === null || num === undefined || isNaN(num)) return '0';
        return Number(num).toLocaleString('id-ID', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        });
    }

    // Custom Chart.js Plugin for Direct Data Labels on Throughput Bars (Zona 1 & Zona 4 - Weight in KG & PCS)
    const directDataLabelsPlugin = {
        id: 'directDataLabels',
        afterDatasetsDraw(chart) {
            const { ctx, data } = chart;
            const meta = chart.getDatasetMeta(0); // Bar dataset (Good Output KG)
            if (!meta || meta.hidden) return;

            const barDataset = data.datasets[0];
            const items = barDataset.dailyData || barDataset.stagesData || [];
            if (!items.length) return;

            const barCount = meta.data.length;
            const chartWidth = chart.chartArea.right - chart.chartArea.left;
            const slotWidth = barCount > 0 ? (chartWidth / barCount) : 40;
            const isDense = barCount > 8; // Zona 4 has 30 bars; Zona 1 has 5-6 bars

            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'bottom';

            meta.data.forEach((bar, index) => {
                const item = items[index] || {};
                const kgVal = Number(item.good_kg ?? barDataset.data[index] ?? 0);
                const pcsVal = Number(item.good_pcs || 0);

                // For 30-day dense mode (Zona 4), omit labels for missing snapshot or days without production (0 kg & 0 pcs)
                if (item.is_available === false || item.good_kg === null) {
                    return;
                }
                if (isDense && kgVal === 0 && pcsVal === 0) {
                    return;
                }

                const isZero = (kgVal === 0 && pcsVal === 0);

                const x = bar.x;
                const topY = bar.y;

                // Format values in Indonesian locale: integer kg without decimals, and PCS
                const kgText = `${formatNumber(kgVal, 0)} kg`;
                const pcsText = `${formatNumber(pcsVal, 0)} PCS`;

                if (isDense) {
                    // Zona 4: Compact adaptive pill for 30 calendar days
                    const fontSizeKg = slotWidth < 26 ? 7.5 : (slotWidth < 34 ? 8.5 : 9);
                    const fontSizePcs = slotWidth < 26 ? 7 : (slotWidth < 34 ? 8 : 8.5);

                    ctx.font = `bold ${fontSizeKg}px ui-sans-serif, system-ui, sans-serif`;
                    const kgWidth = ctx.measureText(kgText).width;
                    ctx.font = `bold ${fontSizePcs}px ui-sans-serif, system-ui, sans-serif`;
                    const pcsWidth = ctx.measureText(pcsText).width;
                    const maxTextW = Math.max(kgWidth, pcsWidth);

                    const pillW = Math.min(maxTextW + 6, Math.max(slotWidth - 2, maxTextW));
                    const pillH = fontSizeKg + fontSizePcs + 6;

                    // Safe headroom clearance from chartArea top to guarantee no clipping
                    const safeTopLimit = chart.chartArea.top + pillH + 2;
                    const pcsY = Math.max(topY - 3, safeTopLimit);
                    const kgY = pcsY - fontSizePcs - 2;
                    const pillX = x - (pillW / 2);
                    const pillY = kgY - fontSizeKg - 1;

                    ctx.fillStyle = 'rgba(255, 255, 255, 0.95)';
                    ctx.beginPath();
                    if (typeof ctx.roundRect === 'function') {
                        ctx.roundRect(pillX, pillY, pillW, pillH, 3);
                    } else {
                        ctx.rect(pillX, pillY, pillW, pillH);
                    }
                    ctx.fill();

                    ctx.strokeStyle = '#bfdbfe';
                    ctx.lineWidth = 0.75;
                    ctx.stroke();

                    // Baris 1: Berat aktual (KG dengan 4 angka desimal)
                    ctx.font = `bold ${fontSizeKg}px ui-sans-serif, system-ui, sans-serif`;
                    ctx.fillStyle = '#0f172a';
                    ctx.fillText(kgText, x, kgY);

                    // Baris 2: PCS aktual
                    ctx.font = `bold ${fontSizePcs}px ui-sans-serif, system-ui, sans-serif`;
                    ctx.fillStyle = '#1e3a8a';
                    ctx.fillText(pcsText, x, pcsY);
                } else {
                    // Zona 1: Spacious SCADA pill for wide stage bars
                    const safeTopLimit = chart.chartArea.top + 36;
                    const pcsY = Math.max(topY - 6, safeTopLimit);
                    const tonY = pcsY - 16;

                    ctx.font = 'bold 13px ui-sans-serif, system-ui, sans-serif';
                    const textWidth = Math.max(ctx.measureText(kgText).width, ctx.measureText(pcsText).width);
                    const pillW = textWidth + 16;
                    const pillH = 34;
                    const pillX = x - pillW / 2;
                    const pillY = tonY - 16;

                    ctx.fillStyle = 'rgba(255, 255, 255, 0.94)';
                    ctx.beginPath();
                    if (typeof ctx.roundRect === 'function') {
                        ctx.roundRect(pillX, pillY, pillW, pillH, 5);
                    } else {
                        ctx.rect(pillX, pillY, pillW, pillH);
                    }
                    ctx.fill();

                    ctx.strokeStyle = isZero ? '#cbd5e1' : '#bfdbfe';
                    ctx.lineWidth = 1;
                    ctx.stroke();

                    // Baris 1: Berat aktual (KG dengan 4 angka desimal)
                    ctx.font = isZero
                        ? 'bold 11px ui-sans-serif, system-ui, sans-serif'
                        : 'bold 13px ui-sans-serif, system-ui, sans-serif';
                    ctx.fillStyle = isZero ? '#64748b' : '#0f172a';
                    ctx.fillText(kgText, x, tonY);

                    // Baris 2: PCS aktual (PCS)
                    ctx.font = isZero
                        ? 'bold 11px ui-sans-serif, system-ui, sans-serif'
                        : 'bold 12px ui-sans-serif, system-ui, sans-serif';
                    ctx.fillStyle = isZero ? '#64748b' : '#1e3a8a';
                    ctx.fillText(pcsText, x, pcsY);
                }
            });

            ctx.restore();
        }
    };

    // Custom Chart.js Plugin for Direct Data Labels on 30-Day Defect Trend Bars (Zone 3 - PCS & KG above bar)
    const defectTrendDirectLabelsPlugin = {
        id: 'defectTrendDirectLabels',
        afterDatasetsDraw(chart) {
            const { ctx, data } = chart;
            const meta = chart.getDatasetMeta(0);
            if (!meta || meta.hidden) return;

            const barDataset = data.datasets[0];
            const trendData = barDataset.trendData || [];
            if (!trendData.length) return;

            // Determine available slot width per bar to adapt font size
            const barCount = meta.data.length;
            const chartWidth = chart.chartArea.right - chart.chartArea.left;
            const slotWidth = barCount > 0 ? (chartWidth / barCount) : 30;

            // Adaptive font sizes based on horizontal density
            const fontSizePcs = slotWidth < 22 ? 8 : (slotWidth < 30 ? 8.5 : 9);
            const fontSizeKg = slotWidth < 22 ? 7.5 : (slotWidth < 30 ? 8 : 8.5);

            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'bottom';

            meta.data.forEach((bar, index) => {
                const item = trendData[index];
                if (!item) return;

                // Tahap 3: Tanggal dengan no_data (defect_pcs === null) atau zero_defect (defect_pcs === 0)
                // Jangan menampilkan angka nol palsu untuk data tidak tersedia.
                // Hindari label 0 PCS / 0 kg berulang agar chart 30-31 titik tidak tertumpuk/padat.
                if (item.defect_pcs === null || item.defect_pcs === undefined || Number(item.defect_pcs) <= 0) {
                    return;
                }

                const pcsVal = Number(item.defect_pcs);
                const kgVal = item.estimated_defect_kg;
                const isWeightAvail = (kgVal !== null && kgVal !== undefined && item.is_weight_available !== false);

                // Baris 1: Defect PCS
                const pcsText = slotWidth >= 44 ? `${formatNumber(pcsVal)} PCS` : `${formatNumber(pcsVal)}`;

                // Baris 2: Estimasi kg aktual dari payload
                let kgText;
                if (isWeightAvail) {
                    const numKg = Number(kgVal);
                    const formattedKg = formatNumber(numKg, (numKg % 1 === 0) ? 0 : 1);
                    kgText = `${formattedKg} kg`;
                } else {
                    kgText = 'n/a';
                }

                const x = bar.x;
                const topY = bar.y;

                // Safe clearance from chartArea top to guarantee no clipping
                const safeTopLimit = chart.chartArea.top + 22;
                const kgY = Math.max(topY - 3, safeTopLimit);
                const pcsY = kgY - (fontSizeKg + 2);

                // Subtle semi-transparent rounded pill for background contrast
                ctx.font = `bold ${fontSizePcs}px ui-sans-serif, system-ui, sans-serif`;
                const pcsWidth = ctx.measureText(pcsText).width;
                ctx.font = `bold ${fontSizeKg}px ui-sans-serif, system-ui, sans-serif`;
                const kgWidth = ctx.measureText(kgText).width;
                const maxTextW = Math.max(pcsWidth, kgWidth);

                const pillW = Math.min(maxTextW + 4, Math.max(slotWidth - 2, maxTextW));
                const pillH = fontSizePcs + fontSizeKg + 5;
                const pillX = x - (pillW / 2);
                const pillY = pcsY - fontSizePcs - 1;

                ctx.fillStyle = 'rgba(255, 255, 255, 0.94)';
                ctx.beginPath();
                if (typeof ctx.roundRect === 'function') {
                    ctx.roundRect(pillX, pillY, pillW, pillH, 3);
                } else {
                    ctx.rect(pillX, pillY, pillW, pillH);
                }
                ctx.fill();

                ctx.strokeStyle = 'rgba(226, 232, 240, 0.8)';
                ctx.lineWidth = 0.5;
                ctx.stroke();

                // Draw Baris 1: Defect PCS
                ctx.font = `bold ${fontSizePcs}px ui-sans-serif, system-ui, sans-serif`;
                ctx.fillStyle = '#0f172a';
                ctx.fillText(pcsText, x, pcsY);

                // Draw Baris 2: Estimasi Defect kg
                ctx.font = `bold ${fontSizeKg}px ui-sans-serif, system-ui, sans-serif`;
                ctx.fillStyle = isWeightAvail ? '#be123c' : '#d97706';
                ctx.fillText(kgText, x, kgY);
            });

            ctx.restore();
        }
    };

    // Initialize Chart.js instances
    function initCharts(data) {
        // 1. Throughput Chart (Zone 1)
        const throughputCanvas = document.getElementById('chartThroughput');
        if (throughputCanvas && (data.zone_1 || DASHBOARD_CONFIG.zone === '1' || DASHBOARD_CONFIG.zone === 'all')) {
            const rawStages = (data.zone_1 && data.zone_1.daily_throughput_stages) ? data.zone_1.daily_throughput_stages : [];
            const stages = rawStages.filter(s => s.stage !== 'cor');
            const labels = stages.map(s => s.label);
            const kgData = stages.map(s => Number(s.good_kg ?? ((s.good_tonnage ?? s.good_ton ?? 0) * 1000)));
            const defectRateData = stages.map(s => Number(s.defect_rate_pct ?? s.defect_rate ?? 0));
            const maxKg = Math.max(...kgData, 1);

            const ctx = throughputCanvas.getContext('2d');
            dashboardCharts.throughput = new Chart(ctx, {
                type: 'bar',
                plugins: [directDataLabelsPlugin],
                data: {
                    labels: labels,
                    datasets: [
                        {
                            type: 'bar',
                            label: 'Good Output (kg)',
                            data: kgData,
                            stagesData: stages,
                            backgroundColor: '#2563eb',
                            borderColor: '#1d4ed8',
                            borderWidth: 1,
                            borderRadius: 4,
                            yAxisID: 'yKg',
                        },
                        {
                            type: 'line',
                            label: 'Defect Rate (%)',
                            data: defectRateData,
                            borderColor: '#dc2626',
                            backgroundColor: 'rgba(220, 38, 38, 0.08)',
                            borderWidth: 2,
                            pointBackgroundColor: '#dc2626',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            yAxisID: 'yPct',
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    layout: {
                        padding: {
                            top: 40, // Ensure ample headroom for direct above-bar labels
                            left: 10,
                            right: 10,
                            bottom: 6,
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const stage = stages[context.dataIndex] || {};
                                    if (context.datasetIndex === 0) {
                                        return ` Good: ${formatNumber(stage.good_kg ?? context.raw, 4)} kg (${formatNumber(stage.good_pcs || 0)} PCS)`;
                                    } else {
                                        return ` Defect Rate: ${formatNumber(context.raw, 2)}% (${formatNumber(stage.defect_pcs || 0)} PCS defect)`;
                                    }
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { color: '#f1f5f9' },
                            ticks: { color: '#4B5563', font: { weight: 'bold', size: 12 } }
                        },
                        yKg: {
                            type: 'linear',
                            position: 'left',
                            beginAtZero: true,
                            suggestedMax: maxKg > 0 ? maxKg * 1.45 : 5000,
                            grid: { color: '#e2e8f0' },
                            title: { display: true, text: 'KG', color: '#1d4ed8', font: { size: 11, weight: 'bold' } },
                            ticks: {
                                callback: v => formatNumber(v, 0) + ' kg',
                                color: '#475569',
                                font: { weight: 'bold' }
                            }
                        },
                        yPct: {
                            type: 'linear',
                            position: 'right',
                            beginAtZero: true,
                            suggestedMax: Math.max(...defectRateData, 5) * 1.25,
                            grid: { drawOnChartArea: false },
                            title: { display: true, text: 'Defect %', color: '#dc2626', font: { size: 11, weight: 'bold' } },
                            ticks: { callback: v => v + '%', color: '#dc2626', font: { weight: 'bold' } }
                        }
                    }
                }
            });
        }

        // 2. WIP Donut Chart (Zone 2)
        const wipCanvas = document.getElementById('chartWipDonut');
        if (wipCanvas && (data.zone_2 || DASHBOARD_CONFIG.zone === '2' || DASHBOARD_CONFIG.zone === 'all')) {
            const wipDist = (data.zone_2 && data.zone_2.wip_distribution) ? data.zone_2.wip_distribution : [];
            const labels = wipDist.map(w => w.label);
            const tons = wipDist.map(w => Number(w.wip_ton || 0));
            const donutColors = ['#2563eb', '#0284c7', '#0d9488', '#d97706', '#64748b'];

            const ctx = wipCanvas.getContext('2d');
            dashboardCharts.wipDonut = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: tons,
                        backgroundColor: donutColors,
                        borderColor: '#ffffff',
                        borderWidth: 2,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const w = wipDist[context.dataIndex] || {};
                                    return ` ${w.label}: ${formatNumber(w.wip_ton, 2)} TON (${formatNumber(w.wip_pcs)} PCS, ${formatNumber(w.percentage, 1)}%)`;
                                }
                            }
                        }
                    },
                    cutout: '62%',
                }
            });
        }

        // 3. Overall Defect Types Donut Chart (Zone 3 - KG Primary)
        const overallDonutCanvas = document.getElementById('chartOverallDefectTypes');
        if (overallDonutCanvas && (data.zone_3 || DASHBOARD_CONFIG.zone === '3' || DASHBOARD_CONFIG.zone === 'all')) {
            const overallTypes = (data.zone_3 && data.zone_3.overall_defect_types) ? data.zone_3.overall_defect_types : (data.zone_3 && data.zone_3.defects_today ? data.zone_3.defects_today.verified_breakdown || [] : []);
            const labels = overallTypes.length > 0 ? overallTypes.map(d => d.name) : ['Nihil Defect'];
            const kgValues = overallTypes.length > 0 ? overallTypes.map(d => Number(d.estimated_defect_kg || 0)) : [0];
            const colors = ['#dc2626', '#ea580c', '#d97706', '#0284c7', '#4f46e5', '#7c3aed', '#059669', '#0891b2', '#64748b'];

            const ctx = overallDonutCanvas.getContext('2d');
            dashboardCharts.overallDefects = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: kgValues,
                        backgroundColor: overallTypes.length > 0 ? overallTypes.map((_, i) => colors[i % colors.length]) : ['#e2e8f0'],
                        borderColor: '#ffffff',
                        borderWidth: 2,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const d = overallTypes[context.dataIndex] || {};
                                    const kgStr = formatNumber(d.estimated_defect_kg || 0, 1) + ' kg';
                                    const pctStr = formatNumber(d.weight_percentage ?? d.percentage ?? 0, 1) + '%';
                                    const pcsStr = formatNumber(d.qty_pcs || 0) + ' PCS';
                                    return ` ${context.label}: ${kgStr} (${pctStr}) · ${pcsStr}`;
                                }
                            }
                        }
                    },
                    cutout: '60%',
                }
            });
        }

        // 4. 30-Day Defect Trend Bar Chart (Zone 3 - KG Primary)
        const trendCanvas = document.getElementById('chartDefectTrend30Days');
        if (trendCanvas && (data.zone_3 || DASHBOARD_CONFIG.zone === '3' || DASHBOARD_CONFIG.zone === 'all')) {
            const trend = (data.zone_3 && data.zone_3.defect_trend_30_days) ? data.zone_3.defect_trend_30_days : (data.zone_3 && data.zone_3.defect_trend_7_days ? data.zone_3.defect_trend_7_days : []);
            const labels = trend.map(t => t.day_label);
            const defectKg = trend.map(t => (t.estimated_defect_kg !== null && t.estimated_defect_kg !== undefined) ? Number(t.estimated_defect_kg) : 0);
            const barColors = trend.map(t => (t.defect_pcs === null) ? 'rgba(203, 213, 225, 0.4)' : ((t.estimated_defect_kg > 0 || t.defect_pcs > 0) ? '#e11d48' : '#e2e8f0'));
            const maxDefectKg = Math.max(...defectKg, 0);

            const ctx = trendCanvas.getContext('2d');
            dashboardCharts.defectTrend30 = new Chart(ctx, {
                type: 'bar',
                plugins: [defectTrendDirectLabelsPlugin],
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Estimasi Defect (kg)',
                        data: defectKg,
                        trendData: trend,
                        backgroundColor: barColors,
                        borderRadius: 3,
                        maxBarThickness: 24,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: {
                        padding: {
                            top: 28,
                            left: 4,
                            right: 4,
                            bottom: 2,
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const currentTrend = context.chart.data.datasets[0].trendData || trend;
                                    const t = currentTrend[context.dataIndex] || {};
                                    if (t.defect_pcs === null) {
                                        return ' Data Tidak Tersedia';
                                    }
                                    if (t.defect_pcs === 0) {
                                        return ' 0,0 kg · 0 PCS (Nihil Defect QC)';
                                    }
                                    const kgVal = (t.estimated_defect_kg !== null && t.estimated_defect_kg !== undefined) ? formatNumber(t.estimated_defect_kg, 1) + ' kg' : 'Berat n/a';
                                    const pcsVal = formatNumber(t.defect_pcs) + ' PCS';
                                    const unavailText = (t.unavailable_weight_pcs > 0) ? ` (${formatNumber(t.unavailable_weight_pcs)} PCS tanpa data berat)` : '';
                                    return ` Estimasi Defect: ${kgVal} · ${pcsVal}${unavailText}`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { color: '#f8fafc' },
                            ticks: {
                                color: '#475569',
                                font: { weight: 'bold', size: 9 },
                                autoSkip: true,
                                maxTicksLimit: 15,
                            }
                        },
                        y: {
                            beginAtZero: true,
                            suggestedMax: maxDefectKg > 0 ? maxDefectKg * 1.25 : 10,
                            grid: { color: '#f1f5f9' },
                            ticks: {
                                color: '#475569',
                                font: { weight: 'bold', size: 10 },
                                callback: function(value) { return formatNumber(value) + ' kg'; }
                            }
                        }
                    }
                }
            });
        }

        // 5. 6 Per-Stage Donut Charts (Zone 3 - KG Primary)
        dashboardCharts.stageDonuts = dashboardCharts.stageDonuts || {};
        const stageKeys = ['netto', 'bubut_od', 'bubut_cnc', 'bor', 'qc', 'gudang_jadi'];
        const sharedPalette = ['#dc2626', '#ea580c', '#d97706', '#0284c7', '#4f46e5', '#7c3aed', '#059669', '#0891b2', '#64748b'];

        stageKeys.forEach(stgKey => {
            const stgCanvas = document.getElementById('chartStageDonut_' + stgKey);
            if (stgCanvas && data.zone_3 && data.zone_3.stage_defect_types) {
                const stgData = data.zone_3.stage_defect_types[stgKey] || {};
                const types = stgData.types || [];
                if (types.length > 0) {
                    const ctx = stgCanvas.getContext('2d');
                    dashboardCharts.stageDonuts[stgKey] = new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: types.map(t => t.name),
                            datasets: [{
                                data: types.map(t => Number(t.estimated_defect_kg || 0)),
                                backgroundColor: types.map((t, idx) => sharedPalette[(t.defect_type_id || idx) % sharedPalette.length]),
                                borderColor: '#ffffff',
                                borderWidth: 1.5,
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            const item = types[context.dataIndex] || {};
                                            const kgStr = formatNumber(item.estimated_defect_kg || 0, 1) + ' kg';
                                            const pctStr = formatNumber(item.weight_percentage ?? item.percentage ?? 0, 1) + '%';
                                            const pcsStr = formatNumber(item.qty_pcs || 0) + ' PCS';
                                            return ` ${context.label}: ${kgStr} (${pctStr}) · ${pcsStr}`;
                                        }
                                    }
                                }
                            },
                            cutout: '58%',
                        }
                    });
                }
            }
        });

        // 6. Zone 4: Daily Production Throughput (30 Calendar Days, 6 Stages, Weight in KG)
        const z4Canvas = document.getElementById('chartZone4Throughput');
        if (z4Canvas && (data.zone_4 || DASHBOARD_CONFIG.zone === '4' || DASHBOARD_CONFIG.zone === 'all')) {
            const z4Data = data.zone_4 || {};
            const stagesMap = z4Data.stages || {};
            const selectEl = document.getElementById('zone4-stage-select');
            const activeStageKey = selectEl?.value || dashboardCharts.currentZone4Stage || z4Data.default_stage || 'qc';
            dashboardCharts.currentZone4Stage = activeStageKey;

            const stageData = stagesMap[activeStageKey] || {};
            const daily = stageData.daily || [];
            const labels = daily.map(d => d.day_label);
            const kgSeries = daily.map(d => Number(d.good_kg || 0));
            const maxKg = Math.max(...kgSeries, 0);

            const ctx = z4Canvas.getContext('2d');
            dashboardCharts.zone4Throughput = new Chart(ctx, {
                type: 'bar',
                plugins: [directDataLabelsPlugin],
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Output Baik (kg)',
                        data: kgSeries,
                        dailyData: daily,
                        backgroundColor: '#0284c7',
                        hoverBackgroundColor: '#0369a1',
                        borderColor: '#0369a1',
                        borderWidth: 1,
                        borderRadius: 3,
                        maxBarThickness: 24,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: {
                        padding: { top: 32, left: 4, right: 4, bottom: 2 }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                title: function(context) {
                                    const idx = context[0]?.dataIndex ?? 0;
                                    const currentDaily = context[0]?.chart?.data?.datasets[0]?.dailyData || daily;
                                    const d = currentDaily[idx] || {};
                                    const year = d.year || new Date().getFullYear();
                                    return d.date ? `${d.day_label} ${year} (${d.date})` : context[0]?.label;
                                },
                                label: function(context) {
                                    const currentDaily = context.chart.data.datasets[0].dailyData || daily;
                                    const d = currentDaily[context.dataIndex] || {};
                                    const kgVal = Number(d.good_kg || 0);
                                    const pcsVal = Number(d.good_pcs || 0);
                                    const ktrVal = Number(d.ktr_count || 0);

                                    if (kgVal === 0 && pcsVal === 0) {
                                        return ' Nihil Produksi (0,0000 kg · 0 PCS)';
                                    }

                                    return [
                                        ` Output Baik: ${formatNumber(kgVal, 4)} kg`,
                                        ` Kuantitas: ${formatNumber(pcsVal)} PCS`,
                                        ` KTR Selesai: ${formatNumber(ktrVal)} KTR`,
                                    ];
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { color: '#f8fafc' },
                            ticks: {
                                color: '#475569',
                                font: { weight: 'bold', size: 9 },
                                autoSkip: true,
                                maxTicksLimit: 15,
                            }
                        },
                        y: {
                            beginAtZero: true,
                            suggestedMax: maxKg > 0 ? maxKg * 1.35 : 100,
                            grid: { color: '#f1f5f9' },
                            title: { display: true, text: 'BERAT (KG)', color: '#0369a1', font: { size: 10, weight: 'bold' } },
                            ticks: {
                                color: '#475569',
                                font: { weight: 'bold', size: 10 },
                                callback: function(value) { return formatNumber(value, 0) + ' kg'; }
                            }
                        }
                    }
                }
            });
        }

        // 7. Zone 4: Daily Production Output Distribution Per Line (Line 1 - 4)
        const z4LinesCanvas = document.getElementById('chartZone4LineDistribution');
        if (z4LinesCanvas && (data.zone_4 || DASHBOARD_CONFIG.zone === '4' || DASHBOARD_CONFIG.zone === 'all')) {
            const z4Data = data.zone_4 || {};
            const stagesMap = z4Data.stages || {};
            const selectEl = document.getElementById('zone4-stage-select');
            const activeStageKey = selectEl?.value || dashboardCharts.currentZone4Stage || z4Data.default_stage || 'qc';

            const stageData = stagesMap[activeStageKey] || {};
            const daily = stageData.daily || [];
            const labels = daily.map(d => d.day_label);
            const seriesByLine = stageData.series_by_line || {};

            const line1Data = (seriesByLine[1] && seriesByLine[1].data) ? seriesByLine[1].data : daily.map(d => Number(d.lines?.[1]?.good_kg || 0));
            const line2Data = (seriesByLine[2] && seriesByLine[2].data) ? seriesByLine[2].data : daily.map(d => Number(d.lines?.[2]?.good_kg || 0));
            const line3Data = (seriesByLine[3] && seriesByLine[3].data) ? seriesByLine[3].data : daily.map(d => Number(d.lines?.[3]?.good_kg || 0));
            const line4Data = (seriesByLine[4] && seriesByLine[4].data) ? seriesByLine[4].data : daily.map(d => Number(d.lines?.[4]?.good_kg || 0));

            const line1PcsData = (seriesByLine[1] && seriesByLine[1].pcs_data) ? seriesByLine[1].pcs_data : daily.map(d => Number(d.lines?.[1]?.good_pcs || 0));
            const line2PcsData = (seriesByLine[2] && seriesByLine[2].pcs_data) ? seriesByLine[2].pcs_data : daily.map(d => Number(d.lines?.[2]?.good_pcs || 0));
            const line3PcsData = (seriesByLine[3] && seriesByLine[3].pcs_data) ? seriesByLine[3].pcs_data : daily.map(d => Number(d.lines?.[3]?.good_pcs || 0));
            const line4PcsData = (seriesByLine[4] && seriesByLine[4].pcs_data) ? seriesByLine[4].pcs_data : daily.map(d => Number(d.lines?.[4]?.good_pcs || 0));

            const allKgValues = [...line1Data, ...line2Data, ...line3Data, ...line4Data];
            const maxLineKg = Math.max(...allKgValues, 0);

            const ctxLines = z4LinesCanvas.getContext('2d');
            dashboardCharts.zone4Lines = new Chart(ctxLines, {
                type: 'line',
                data: {
                    labels: labels,
                    dailyData: daily,
                    datasets: [
                        {
                            label: 'Line 1',
                            lineNumber: 1,
                            data: line1Data,
                            pcsData: line1PcsData,
                            borderColor: '#2563eb',
                            backgroundColor: 'rgba(37, 99, 235, 0.1)',
                            borderWidth: 2,
                            tension: 0.25,
                            pointRadius: 2.5,
                            pointHoverRadius: 5,
                            pointBackgroundColor: '#2563eb',
                        },
                        {
                            label: 'Line 2',
                            lineNumber: 2,
                            data: line2Data,
                            pcsData: line2PcsData,
                            borderColor: '#059669',
                            backgroundColor: 'rgba(5, 150, 105, 0.1)',
                            borderWidth: 2,
                            tension: 0.25,
                            pointRadius: 2.5,
                            pointHoverRadius: 5,
                            pointBackgroundColor: '#059669',
                        },
                        {
                            label: 'Line 3',
                            lineNumber: 3,
                            data: line3Data,
                            pcsData: line3PcsData,
                            borderColor: '#d97706',
                            backgroundColor: 'rgba(217, 119, 6, 0.1)',
                            borderWidth: 2,
                            tension: 0.25,
                            pointRadius: 2.5,
                            pointHoverRadius: 5,
                            pointBackgroundColor: '#d97706',
                        },
                        {
                            label: 'Line 4',
                            lineNumber: 4,
                            data: line4Data,
                            pcsData: line4PcsData,
                            borderColor: '#7c3aed',
                            backgroundColor: 'rgba(124, 58, 237, 0.1)',
                            borderWidth: 2,
                            tension: 0.25,
                            pointRadius: 2.5,
                            pointHoverRadius: 5,
                            pointBackgroundColor: '#7c3aed',
                        },
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    layout: {
                        padding: { top: 12, left: 4, right: 4, bottom: 2 }
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                boxWidth: 12,
                                boxHeight: 12,
                                usePointStyle: true,
                                font: { weight: 'bold', size: 10 },
                                color: '#334155',
                            }
                        },
                        tooltip: {
                            callbacks: {
                                title: function(context) {
                                    const idx = context[0]?.dataIndex ?? 0;
                                    const currentDaily = context[0]?.chart?.data?.dailyData || daily;
                                    const d = currentDaily[idx] || {};
                                    if (d.date) {
                                        const year = d.date.split('-')[0];
                                        return `${d.day_label} ${year}`;
                                    }
                                    return context[0]?.label || '';
                                },
                                label: function(context) {
                                    const idx = context.dataIndex;
                                    const currentDaily = context.chart?.data?.dailyData || daily;
                                    const d = currentDaily[idx] || {};
                                    const lineNo = context.dataset?.lineNumber || (context.datasetIndex + 1);
                                    const lineInfo = d.lines?.[lineNo] || {};
                                    const kgVal = Number(context.raw ?? lineInfo.good_kg ?? 0);
                                    const pcsVal = Number(lineInfo.good_pcs ?? context.dataset?.pcsData?.[idx] ?? 0);
                                    return ` ${context.dataset.label}: ${formatNumber(kgVal, 0)} kg | ${formatNumber(pcsVal, 0)} PCS`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { color: '#f8fafc' },
                            ticks: {
                                color: '#475569',
                                font: { weight: 'bold', size: 9 },
                                autoSkip: true,
                                maxTicksLimit: 15,
                            }
                        },
                        y: {
                            beginAtZero: true,
                            suggestedMax: maxLineKg > 0 ? maxLineKg * 1.25 : 50,
                            grid: { color: '#f1f5f9' },
                            title: { display: true, text: 'BERAT (KG)', color: '#4338ca', font: { size: 10, weight: 'bold' } },
                            ticks: {
                                color: '#475569',
                                font: { weight: 'bold', size: 10 },
                                callback: function(value) { return formatNumber(value, 0) + ' kg'; }
                            }
                        }
                    }
                }
            });
        }

        // 8. Zone 4: Daily WIP Snapshot Bar Chart (Cutoff 07.00 WIB, 5 Stages: NETTO, BUBUT OD, BUBUT CNC, BOR, QC)
        const z4WipCanvas = document.getElementById('chartZone4WipSnapshot');
        const wipPayload = data.zone_4?.wip_snapshot;
        if (z4WipCanvas && (wipPayload || DASHBOARD_CONFIG.zone === '4' || DASHBOARD_CONFIG.zone === 'all')) {
            const wipStagesMap = wipPayload?.stages || {};
            const selectWipEl = document.getElementById('zone4-wip-stage-select');
            const activeWipStageKey = selectWipEl?.value || dashboardCharts.currentZone4WipStage || wipPayload?.default_stage || 'qc';
            dashboardCharts.currentZone4WipStage = activeWipStageKey;

            const stageData = wipStagesMap[activeWipStageKey] || {};
            const daily = stageData.daily || [];
            const labels = daily.map(d => d.day_label);
            const kgSeries = daily.map(d => (d.good_kg !== null && d.good_kg !== undefined) ? Number(d.good_kg) : null);
            const validKgValues = kgSeries.filter(v => v !== null && !isNaN(v));
            const maxKg = validKgValues.length > 0 ? Math.max(...validKgValues, 0) : 0;
            const bgColors = daily.map(d => d.is_reconstructed ? '#f59e0b' : '#0284c7');
            const hoverBgColors = daily.map(d => d.is_reconstructed ? '#d97706' : '#0369a1');

            const ctxWip = z4WipCanvas.getContext('2d');
            dashboardCharts.zone4WipSnapshot = new Chart(ctxWip, {
                type: 'bar',
                plugins: [directDataLabelsPlugin],
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Posisi WIP (kg)',
                        data: kgSeries,
                        dailyData: daily,
                        backgroundColor: bgColors,
                        hoverBackgroundColor: hoverBgColors,
                        borderColor: '#0369a1',
                        borderWidth: 1,
                        borderRadius: 3,
                        maxBarThickness: 24,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: {
                        padding: { top: 32, left: 4, right: 4, bottom: 2 }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                title: function(context) {
                                    const idx = context[0]?.dataIndex ?? 0;
                                    const currentDaily = context[0]?.chart?.data?.datasets[0]?.dailyData || daily;
                                    const d = currentDaily[idx] || {};
                                    const year = d.year || new Date().getFullYear();
                                    return d.date ? `${d.day_label} ${year} (${d.date})` : context[0]?.label;
                                },
                                label: function(context) {
                                    const currentDaily = context.chart.data.datasets[0].dailyData || daily;
                                    const d = currentDaily[context.dataIndex] || {};
                                    if (d.is_available === false || d.good_kg === null || d.good_kg === undefined) {
                                        return ' Data Snapshot Tidak Tersedia';
                                    }
                                    const kgVal = Number(d.good_kg || 0);
                                    const pcsVal = Number(d.good_pcs || 0);
                                    const ktrVal = Number(d.ktr_count || 0);
                                    const reconNotice = d.is_reconstructed ? ' (Rekonstruksi Historis)' : '';

                                    return [
                                        ` Posisi WIP${reconNotice}: ${formatNumber(kgVal, 4)} kg`,
                                        ` Antrean: ${formatNumber(pcsVal)} PCS`,
                                        ` Total KTR: ${formatNumber(ktrVal)} KTR`,
                                    ];
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { color: '#f8fafc' },
                            ticks: {
                                color: '#475569',
                                font: { weight: 'bold', size: 9 },
                                autoSkip: true,
                                maxTicksLimit: 15,
                            }
                        },
                        y: {
                            beginAtZero: true,
                            suggestedMax: maxKg > 0 ? maxKg * 1.35 : 100,
                            grid: { color: '#f1f5f9' },
                            title: { display: true, text: 'WIP (KG)', color: '#0369a1', font: { size: 10, weight: 'bold' } },
                            ticks: {
                                color: '#475569',
                                font: { weight: 'bold', size: 10 },
                                callback: function(value) { return formatNumber(value, 0) + ' kg'; }
                            }
                        }
                    }
                }
            });
        }
    }

    // In-place Chart update
    function updateChartsInPlace(data) {
        // Update Throughput (Zone 1 - 4-decimal KG & PCS)
        if (dashboardCharts.throughput && data.zone_1 && data.zone_1.daily_throughput_stages) {
            const rawStages = data.zone_1.daily_throughput_stages || [];
            const stages = rawStages.filter(s => s.stage !== 'cor');
            const newKg = stages.map(s => Number(s.good_kg ?? ((s.good_tonnage ?? s.good_ton ?? 0) * 1000)));
            const newDefectRate = stages.map(s => Number(s.defect_rate_pct ?? s.defect_rate ?? 0));
            const maxK = Math.max(...newKg, 1);
            dashboardCharts.throughput.data.labels = stages.map(s => s.label);
            dashboardCharts.throughput.data.datasets[0].data = newKg;
            dashboardCharts.throughput.data.datasets[0].stagesData = stages;
            dashboardCharts.throughput.data.datasets[1].data = newDefectRate;
            if (dashboardCharts.throughput.options.scales.yKg) {
                dashboardCharts.throughput.options.scales.yKg.suggestedMax = maxK * 1.45;
            }
            dashboardCharts.throughput.options.scales.yPct.suggestedMax = Math.max(...newDefectRate, 5) * 1.25;
            if (dashboardCharts.throughput.options.scales.x && dashboardCharts.throughput.options.scales.x.ticks) {
                dashboardCharts.throughput.options.scales.x.ticks.color = '#4B5563';
            }
            dashboardCharts.throughput.update();
        }

        // Update WIP Donut
        if (dashboardCharts.wipDonut && data.zone_2 && data.zone_2.wip_distribution) {
            const wipDist = data.zone_2.wip_distribution;
            dashboardCharts.wipDonut.data.labels = wipDist.map(w => w.label);
            dashboardCharts.wipDonut.data.datasets[0].data = wipDist.map(w => Number(w.wip_ton || 0));
            dashboardCharts.wipDonut.update();
        }

        // Update 30-Day Defect Trend (KG Primary)
        if (dashboardCharts.defectTrend30 && data.zone_3 && data.zone_3.defect_trend_30_days) {
            const trend = data.zone_3.defect_trend_30_days;
            const newDefectKg = trend.map(t => (t.estimated_defect_kg !== null && t.estimated_defect_kg !== undefined) ? Number(t.estimated_defect_kg) : 0);
            const maxDefectKg = Math.max(...newDefectKg, 0);
            dashboardCharts.defectTrend30.data.labels = trend.map(t => t.day_label);
            dashboardCharts.defectTrend30.data.datasets[0].data = newDefectKg;
            dashboardCharts.defectTrend30.data.datasets[0].trendData = trend;
            dashboardCharts.defectTrend30.data.datasets[0].backgroundColor = trend.map(t => (t.defect_pcs === null) ? 'rgba(203, 213, 225, 0.4)' : ((t.estimated_defect_kg > 0 || t.defect_pcs > 0) ? '#e11d48' : '#e2e8f0'));
            dashboardCharts.defectTrend30.options.scales.y.suggestedMax = maxDefectKg > 0 ? maxDefectKg * 1.25 : 10;
            dashboardCharts.defectTrend30.update();
        }

        // Update Overall Defect Donut (KG Primary)
        if (dashboardCharts.overallDefects && data.zone_3 && data.zone_3.overall_defect_types) {
            const overallTypes = data.zone_3.overall_defect_types;
            const colors = ['#dc2626', '#ea580c', '#d97706', '#0284c7', '#4f46e5', '#7c3aed', '#059669', '#0891b2', '#64748b'];
            dashboardCharts.overallDefects.data.labels = overallTypes.length > 0 ? overallTypes.map(d => d.name) : ['Nihil Defect'];
            dashboardCharts.overallDefects.data.datasets[0].data = overallTypes.length > 0 ? overallTypes.map(d => Number(d.estimated_defect_kg || 0)) : [0];
            dashboardCharts.overallDefects.data.datasets[0].backgroundColor = overallTypes.length > 0 ? overallTypes.map((_, i) => colors[i % colors.length]) : ['#e2e8f0'];
            dashboardCharts.overallDefects.update();
        }

        // Update 6 Per-Stage Donut Charts (KG Primary)
        if (dashboardCharts.stageDonuts && data.zone_3 && data.zone_3.stage_defect_types) {
            const stageKeys = ['netto', 'bubut_od', 'bubut_cnc', 'bor', 'qc', 'gudang_jadi'];
            const sharedPalette = ['#dc2626', '#ea580c', '#d97706', '#0284c7', '#4f46e5', '#7c3aed', '#059669', '#0891b2', '#64748b'];
            stageKeys.forEach(stgKey => {
                const chart = dashboardCharts.stageDonuts[stgKey];
                const stgData = data.zone_3.stage_defect_types[stgKey];
                if (chart && stgData && stgData.types) {
                    chart.data.labels = stgData.types.map(t => t.name);
                    chart.data.datasets[0].data = stgData.types.map(t => Number(t.estimated_defect_kg || 0));
                    chart.data.datasets[0].backgroundColor = stgData.types.map((t, idx) => sharedPalette[(t.defect_type_id || idx) % sharedPalette.length]);
                    chart.update();
                }
            });
        }

        // Update Zone 4 Throughput (30 Days, 6 Stages, 4-decimal KG)
        if (dashboardCharts.zone4Throughput && data.zone_4 && data.zone_4.stages) {
            const selectEl = document.getElementById('zone4-stage-select');
            const activeStage = selectEl?.value || dashboardCharts.currentZone4Stage || 'qc';
            const stageData = data.zone_4.stages[activeStage] || {};
            const daily = stageData.daily || [];
            const kgSeries = daily.map(d => Number(d.good_kg || 0));
            const maxKg = Math.max(...kgSeries, 0);

            dashboardCharts.zone4Throughput.data.labels = daily.map(d => d.day_label);
            dashboardCharts.zone4Throughput.data.datasets[0].data = kgSeries;
            dashboardCharts.zone4Throughput.data.datasets[0].dailyData = daily;
            dashboardCharts.zone4Throughput.data.datasets[0].backgroundColor = '#0284c7';
            dashboardCharts.zone4Throughput.data.datasets[0].hoverBackgroundColor = '#0369a1';
            dashboardCharts.zone4Throughput.options.scales.y.suggestedMax = maxKg > 0 ? maxKg * 1.35 : 100;
            dashboardCharts.zone4Throughput.update();
        }

        // Update Zone 4 Line Distribution (Line 1 - 4)
        if (dashboardCharts.zone4Lines && data.zone_4 && data.zone_4.stages) {
            const selectEl = document.getElementById('zone4-stage-select');
            const activeStage = selectEl?.value || dashboardCharts.currentZone4Stage || 'qc';
            const stageData = data.zone_4.stages[activeStage] || {};
            const daily = stageData.daily || [];
            const labels = daily.map(d => d.day_label);
            const seriesByLine = stageData.series_by_line || {};

            const line1Data = (seriesByLine[1] && seriesByLine[1].data) ? seriesByLine[1].data : daily.map(d => Number(d.lines?.[1]?.good_kg || 0));
            const line2Data = (seriesByLine[2] && seriesByLine[2].data) ? seriesByLine[2].data : daily.map(d => Number(d.lines?.[2]?.good_kg || 0));
            const line3Data = (seriesByLine[3] && seriesByLine[3].data) ? seriesByLine[3].data : daily.map(d => Number(d.lines?.[3]?.good_kg || 0));
            const line4Data = (seriesByLine[4] && seriesByLine[4].data) ? seriesByLine[4].data : daily.map(d => Number(d.lines?.[4]?.good_kg || 0));

            const line1PcsData = (seriesByLine[1] && seriesByLine[1].pcs_data) ? seriesByLine[1].pcs_data : daily.map(d => Number(d.lines?.[1]?.good_pcs || 0));
            const line2PcsData = (seriesByLine[2] && seriesByLine[2].pcs_data) ? seriesByLine[2].pcs_data : daily.map(d => Number(d.lines?.[2]?.good_pcs || 0));
            const line3PcsData = (seriesByLine[3] && seriesByLine[3].pcs_data) ? seriesByLine[3].pcs_data : daily.map(d => Number(d.lines?.[3]?.good_pcs || 0));
            const line4PcsData = (seriesByLine[4] && seriesByLine[4].pcs_data) ? seriesByLine[4].pcs_data : daily.map(d => Number(d.lines?.[4]?.good_pcs || 0));

            const allKgValues = [...line1Data, ...line2Data, ...line3Data, ...line4Data];
            const maxLineKg = Math.max(...allKgValues, 0);

            dashboardCharts.zone4Lines.data.labels = labels;
            dashboardCharts.zone4Lines.data.dailyData = daily;
            dashboardCharts.zone4Lines.data.datasets[0].data = line1Data;
            dashboardCharts.zone4Lines.data.datasets[0].pcsData = line1PcsData;
            dashboardCharts.zone4Lines.data.datasets[1].data = line2Data;
            dashboardCharts.zone4Lines.data.datasets[1].pcsData = line2PcsData;
            dashboardCharts.zone4Lines.data.datasets[2].data = line3Data;
            dashboardCharts.zone4Lines.data.datasets[2].pcsData = line3PcsData;
            dashboardCharts.zone4Lines.data.datasets[3].data = line4Data;
            dashboardCharts.zone4Lines.data.datasets[3].pcsData = line4PcsData;
            dashboardCharts.zone4Lines.options.scales.y.suggestedMax = maxLineKg > 0 ? maxLineKg * 1.25 : 50;
            dashboardCharts.zone4Lines.update();
        }

        // Update Zone 4 WIP Snapshot (30 Days, 5 Stages: NETTO, BUBUT OD, BUBUT CNC, BOR, QC)
        const wipPayloadUpdate = data.zone_4?.wip_snapshot;
        if (dashboardCharts.zone4WipSnapshot && wipPayloadUpdate && wipPayloadUpdate.stages) {
            const selectWipEl = document.getElementById('zone4-wip-stage-select');
            const activeWipStage = selectWipEl?.value || dashboardCharts.currentZone4WipStage || 'qc';
            const stageData = wipPayloadUpdate.stages[activeWipStage] || {};
            const daily = stageData.daily || [];
            const kgSeries = daily.map(d => (d.good_kg !== null && d.good_kg !== undefined) ? Number(d.good_kg) : null);
            const validKgValues = kgSeries.filter(v => v !== null && !isNaN(v));
            const maxKg = validKgValues.length > 0 ? Math.max(...validKgValues, 0) : 0;

            dashboardCharts.zone4WipSnapshot.data.labels = daily.map(d => d.day_label);
            dashboardCharts.zone4WipSnapshot.data.datasets[0].data = kgSeries;
            dashboardCharts.zone4WipSnapshot.data.datasets[0].dailyData = daily;
            dashboardCharts.zone4WipSnapshot.data.datasets[0].backgroundColor = daily.map(d => d.is_reconstructed ? '#f59e0b' : '#0284c7');
            dashboardCharts.zone4WipSnapshot.data.datasets[0].hoverBackgroundColor = daily.map(d => d.is_reconstructed ? '#d97706' : '#0369a1');
            dashboardCharts.zone4WipSnapshot.options.scales.y.suggestedMax = maxKg > 0 ? maxKg * 1.35 : 100;
            dashboardCharts.zone4WipSnapshot.update();
        }
    }

    // In-place DOM update
    function updateDom(data) {
        // Zone 1 DOM
        if (data.zone_1) {
            const z1 = data.zone_1;
            const c = z1.casting_today || {};
            const w = z1.warehouse_receipt_today || {};
            const h = z1.production_code_health || {};

            const elGoodTon = document.getElementById('z1-good-ton');
            if (elGoodTon) elGoodTon.textContent = formatNumber(c.good_tonnage ?? c.good_ton ?? 0, 2);

            const elGoodPcs = document.getElementById('z1-good-pcs');
            if (elGoodPcs) elGoodPcs.textContent = formatNumber(c.good_pcs ?? 0);

            const elRejectTon = document.getElementById('z1-reject-ton');
            if (elRejectTon) elRejectTon.textContent = formatNumber(c.reject_tonnage ?? c.reject_ton ?? 0, 2) + ' T';

            const elHeatCount = document.getElementById('z1-heat-count');
            if (elHeatCount) elHeatCount.textContent = formatNumber(c.heat_count ?? 0);

            const elGdTon = document.getElementById('z1-gd-ton');
            if (elGdTon) elGdTon.textContent = formatNumber(w.good_tonnage ?? w.good_ton ?? 0, 2);

            const elGdPcs = document.getElementById('z1-gd-pcs');
            if (elGdPcs) elGdPcs.textContent = formatNumber(w.good_pcs ?? 0);

            const elGdKtr = document.getElementById('z1-gd-ktr');
            if (elGdKtr) elGdKtr.textContent = formatNumber(w.ktr_count ?? 0);

            const elActiveCodes = document.getElementById('z1-active-codes');
            if (elActiveCodes) elActiveCodes.textContent = formatNumber(h.active_production_code_count ?? 0);

            const elCompletedCodes = document.getElementById('z1-completed-codes');
            if (elCompletedCodes) elCompletedCodes.textContent = formatNumber(h.completed_production_code_count ?? 0);

            const elShortageCodes = document.getElementById('z1-shortage-codes');
            if (elShortageCodes) elShortageCodes.textContent = formatNumber(h.cor_shortage_count ?? 0);

            const elTotalPlans = document.getElementById('z1-total-plans');
            if (elTotalPlans) elTotalPlans.textContent = formatNumber(h.total_plan_count ?? 0);

            const elYDef = document.getElementById('z1-yesterday-defect-container');
            if (elYDef && z1.yesterday_verified_defect && z1.yesterday_verified_defect.pcs !== undefined) {
                const yd = z1.yesterday_verified_defect;
                let html = `<span class="text-base sm:text-lg font-black text-rose-700 leading-tight">${formatNumber(yd.pcs)} PCS</span>`;
                if (yd.weight_kg !== undefined) {
                    html += ` <span class="text-slate-300">/</span> <span class="text-xs sm:text-sm font-bold text-slate-700">${formatNumber(yd.weight_kg, 4)} kg</span>`;
                }
                elYDef.innerHTML = html;
            }
        }

        // Zone 2 DOM
        if (data.zone_2) {
            const z2 = data.zone_2;
            const elTotalWipTon = document.getElementById('z2-total-wip-ton');
            if (elTotalWipTon) elTotalWipTon.textContent = formatNumber(z2.total_active_wip_ton ?? 0, 2) + ' TON';

            const elTotalWipPcs = document.getElementById('z2-total-wip-pcs');
            if (elTotalWipPcs) elTotalWipPcs.textContent = formatNumber(z2.total_active_wip_pcs ?? 0) + ' PCS';

            // Update pipeline active stages if present
            if (z2.wip_distribution) {
                z2.wip_distribution.forEach(w => {
                    const elTon = document.getElementById(`p-${w.stage}-ton`);
                    if (elTon) elTon.innerHTML = `${formatNumber(w.wip_ton || 0, 2)} <span class="text-xs font-black text-blue-600 uppercase">TON</span>`;
                    const elPcs = document.getElementById(`p-${w.stage}-pcs`);
                    if (elPcs) elPcs.textContent = `${formatNumber(w.wip_pcs || 0)} PCS`;
                    const elKtr = document.getElementById(`p-${w.stage}-ktr`);
                    if (elKtr) elKtr.textContent = `${formatNumber(w.ktr_count || 0)} KTR`;
                });
            }
        }

        // Zone 3 DOM
        if (data.zone_3) {
            const z3 = data.zone_3;

            const elTotal30dKg = document.getElementById('z3-total-30d-kg');
            if (elTotal30dKg && z3.quality_analytics_summary) {
                elTotal30dKg.textContent = formatNumber(z3.quality_analytics_summary.total_estimated_defect_kg ?? 0, 1);
            }

            const elTotal30d = document.getElementById('z3-total-30d-pcs');
            if (elTotal30d && z3.quality_analytics_summary) {
                elTotal30d.textContent = formatNumber(z3.quality_analytics_summary.total_verified_pcs ?? 0);
            }

            const elCoverageBadge = document.getElementById('z3-coverage-badge');
            if (elCoverageBadge && z3.quality_analytics_summary) {
                const cov = z3.quality_analytics_summary;
                if (!cov.is_weight_fully_available) {
                    elCoverageBadge.textContent = `Cakupan Berat: ${formatNumber(cov.weight_coverage_pct ?? 100, 1)}% (${formatNumber(cov.unavailable_weight_pcs ?? 0)} PCS N/A)`;
                    elCoverageBadge.classList.remove('hidden');
                } else {
                    elCoverageBadge.classList.add('hidden');
                }
            }

            const elTrendPeriod = document.getElementById('z3-trend-period');
            if (elTrendPeriod && z3.quality_analytics_summary) {
                const pStart = z3.quality_analytics_summary.period_start || '-';
                const pEnd = z3.quality_analytics_summary.period_end || '-';
                elTrendPeriod.textContent = `Periode: ${pStart} s/d ${pEnd}`;
            }
        }

        // Zone 4 DOM (Daily Production Throughput)
        if (data.zone_4) {
            const z4 = data.zone_4;
            const selectEl = document.getElementById('zone4-stage-select');
            const activeStage = selectEl?.value || dashboardCharts.currentZone4Stage || 'qc';
            const stageData = (z4.stages && z4.stages[activeStage]) ? z4.stages[activeStage] : {};

            const elTotalKg = document.getElementById('z4-total-kg');
            if (elTotalKg && stageData.total_weight_kg !== undefined) {
                elTotalKg.textContent = formatNumber(stageData.total_weight_kg, 0);
            }

            const elTotalPcs = document.getElementById('z4-total-pcs');
            if (elTotalPcs && stageData.total_good_pcs !== undefined) {
                elTotalPcs.textContent = formatNumber(stageData.total_good_pcs);
            }

            const elTotalKtr = document.getElementById('z4-total-ktr');
            if (elTotalKtr && stageData.total_ktr_count !== undefined) {
                elTotalKtr.textContent = formatNumber(stageData.total_ktr_count);
            }

            const elStageLabel = document.getElementById('z4-stage-label');
            if (elStageLabel && stageData.stage_label) {
                elStageLabel.textContent = stageData.stage_label;
            }

            const elSummaryStage = document.getElementById('z4-summary-stage-name');
            if (elSummaryStage && stageData.stage_label) {
                elSummaryStage.textContent = stageData.stage_label;
            }

            const elLinesStageLabel = document.getElementById('z4-lines-stage-label');
            if (elLinesStageLabel && stageData.stage_label) {
                elLinesStageLabel.textContent = stageData.stage_label;
            }

            const elPeriod = document.getElementById('z4-period-label');
            if (elPeriod && z4.period_start && z4.period_end) {
                elPeriod.textContent = `Periode: ${z4.period_start} s/d ${z4.period_end}`;
            }

            const elLinesPeriod = document.getElementById('z4-lines-period-label');
            if (elLinesPeriod && z4.period_start && z4.period_end) {
                elLinesPeriod.textContent = `Periode: ${z4.period_start} s/d ${z4.period_end}`;
            }

            // Zone 4 WIP Snapshot DOM
            const z4Wip = z4.wip_snapshot;
            if (z4Wip) {
                const selectWipEl = document.getElementById('zone4-wip-stage-select');
                const activeWipStage = selectWipEl?.value || dashboardCharts.currentZone4WipStage || 'qc';
                const stageData = (z4Wip.stages && z4Wip.stages[activeWipStage]) ? z4Wip.stages[activeWipStage] : {};
                const latestSnap = stageData.latest_snapshot || {};
                const isAvail = !!latestSnap.is_available;

                const summaryContentEl = document.getElementById('z4-wip-summary-content');
                if (summaryContentEl) {
                    if (isAvail) {
                        const kgVal = stageData.total_weight_kg ?? latestSnap.weight_kg ?? 0;
                        const pcsVal = stageData.total_good_pcs ?? latestSnap.qty_pcs ?? 0;
                        const ktrVal = stageData.total_ktr_count ?? latestSnap.ktr_count ?? 0;
                        summaryContentEl.innerHTML = `
                            <span class="font-black text-slate-950" id="z4-wip-total-kg">${formatNumber(kgVal, 0)}</span> <span class="font-bold text-slate-500 text-[10px]">kg</span>
                            <span class="text-slate-300 font-bold">|</span>
                            <span class="font-bold text-slate-800" id="z4-wip-total-pcs">${formatNumber(pcsVal)}</span> <span class="text-slate-500 text-[10px]">PCS</span>
                            <span class="text-slate-300 font-bold">|</span>
                            <span class="font-bold text-slate-800" id="z4-wip-total-ktr">${formatNumber(ktrVal)}</span> <span class="text-slate-500 text-[10px]">KTR</span>
                        `;
                    } else {
                        summaryContentEl.innerHTML = `
                            <span class="text-slate-400 italic font-sans text-[11px]" id="z4-wip-unavailable-label">Data Snapshot Tidak Tersedia</span>
                            <span class="hidden" id="z4-wip-total-kg">0</span>
                            <span class="hidden" id="z4-wip-total-pcs">0</span>
                            <span class="hidden" id="z4-wip-total-ktr">0</span>
                        `;
                    }
                } else {
                    const elWipTotalKg = document.getElementById('z4-wip-total-kg');
                    if (elWipTotalKg && stageData.total_weight_kg !== undefined) {
                        elWipTotalKg.textContent = formatNumber(stageData.total_weight_kg, 0);
                    }

                    const elWipTotalPcs = document.getElementById('z4-wip-total-pcs');
                    if (elWipTotalPcs && stageData.total_good_pcs !== undefined) {
                        elWipTotalPcs.textContent = formatNumber(stageData.total_good_pcs);
                    }

                    const elWipTotalKtr = document.getElementById('z4-wip-total-ktr');
                    if (elWipTotalKtr && stageData.total_ktr_count !== undefined) {
                        elWipTotalKtr.textContent = formatNumber(stageData.total_ktr_count);
                    }
                }

                const elWipStageLabel = document.getElementById('z4-wip-stage-label');
                if (elWipStageLabel && stageData.stage_label) {
                    elWipStageLabel.textContent = stageData.stage_label;
                }

                const elWipSummaryStage = document.getElementById('z4-wip-summary-stage-name');
                if (elWipSummaryStage && stageData.stage_label) {
                    elWipSummaryStage.textContent = stageData.stage_label;
                }

                const elWipPeriod = document.getElementById('z4-wip-period-label');
                if (elWipPeriod && z4Wip.period_start && z4Wip.period_end) {
                    elWipPeriod.textContent = `Periode: ${z4Wip.period_start} s/d ${z4Wip.period_end}`;
                }
            }
        }
    }

    // Switch active stage for Zone 4 (Seamless update without full page reload)
    function switchZone4Stage(stageKey) {
        dashboardCharts.currentZone4Stage = stageKey;
        const latestData = window.latestDashboardData || DASHBOARD_CONFIG.initialData || {};
        const z4Data = latestData.zone_4 || {};
        const stagesMap = z4Data.stages || {};
        const stageData = stagesMap[stageKey] || {};
        const daily = stageData.daily || [];

        // Update Summary KPI
        const elTotalKg = document.getElementById('z4-total-kg');
        if (elTotalKg && stageData.total_weight_kg !== undefined) {
            elTotalKg.textContent = formatNumber(stageData.total_weight_kg, 0);
        }
        const elTotalPcs = document.getElementById('z4-total-pcs');
        if (elTotalPcs && stageData.total_good_pcs !== undefined) {
            elTotalPcs.textContent = formatNumber(stageData.total_good_pcs);
        }
        const elTotalKtr = document.getElementById('z4-total-ktr');
        if (elTotalKtr && stageData.total_ktr_count !== undefined) {
            elTotalKtr.textContent = formatNumber(stageData.total_ktr_count);
        }
        const elStageLabel = document.getElementById('z4-stage-label');
        if (elStageLabel && stageData.stage_label) {
            elStageLabel.textContent = stageData.stage_label;
        }

        const elSummaryStage = document.getElementById('z4-summary-stage-name');
        if (elSummaryStage && stageData.stage_label) {
            elSummaryStage.textContent = stageData.stage_label;
        }

        const elLinesStageLabel = document.getElementById('z4-lines-stage-label');
        if (elLinesStageLabel && stageData.stage_label) {
            elLinesStageLabel.textContent = stageData.stage_label;
        }

        // Update Bar Chart in place
        if (dashboardCharts.zone4Throughput) {
            const kgSeries = daily.map(d => Number(d.good_kg || 0));
            const maxKg = Math.max(...kgSeries, 0);

            dashboardCharts.zone4Throughput.data.labels = daily.map(d => d.day_label);
            dashboardCharts.zone4Throughput.data.datasets[0].data = kgSeries;
            dashboardCharts.zone4Throughput.data.datasets[0].dailyData = daily;
            dashboardCharts.zone4Throughput.data.datasets[0].backgroundColor = '#0284c7';
            dashboardCharts.zone4Throughput.data.datasets[0].hoverBackgroundColor = '#0369a1';
            dashboardCharts.zone4Throughput.options.scales.y.suggestedMax = maxKg > 0 ? maxKg * 1.35 : 100;
            dashboardCharts.zone4Throughput.update();
        }

        // Update Line Chart in place
        if (dashboardCharts.zone4Lines) {
            const seriesByLine = stageData.series_by_line || {};
            const line1Data = (seriesByLine[1] && seriesByLine[1].data) ? seriesByLine[1].data : daily.map(d => Number(d.lines?.[1]?.good_kg || 0));
            const line2Data = (seriesByLine[2] && seriesByLine[2].data) ? seriesByLine[2].data : daily.map(d => Number(d.lines?.[2]?.good_kg || 0));
            const line3Data = (seriesByLine[3] && seriesByLine[3].data) ? seriesByLine[3].data : daily.map(d => Number(d.lines?.[3]?.good_kg || 0));
            const line4Data = (seriesByLine[4] && seriesByLine[4].data) ? seriesByLine[4].data : daily.map(d => Number(d.lines?.[4]?.good_kg || 0));

            const line1PcsData = (seriesByLine[1] && seriesByLine[1].pcs_data) ? seriesByLine[1].pcs_data : daily.map(d => Number(d.lines?.[1]?.good_pcs || 0));
            const line2PcsData = (seriesByLine[2] && seriesByLine[2].pcs_data) ? seriesByLine[2].pcs_data : daily.map(d => Number(d.lines?.[2]?.good_pcs || 0));
            const line3PcsData = (seriesByLine[3] && seriesByLine[3].pcs_data) ? seriesByLine[3].pcs_data : daily.map(d => Number(d.lines?.[3]?.good_pcs || 0));
            const line4PcsData = (seriesByLine[4] && seriesByLine[4].pcs_data) ? seriesByLine[4].pcs_data : daily.map(d => Number(d.lines?.[4]?.good_pcs || 0));

            const allKgValues = [...line1Data, ...line2Data, ...line3Data, ...line4Data];
            const maxLineKg = Math.max(...allKgValues, 0);

            dashboardCharts.zone4Lines.data.labels = daily.map(d => d.day_label);
            dashboardCharts.zone4Lines.data.dailyData = daily;
            dashboardCharts.zone4Lines.data.datasets[0].data = line1Data;
            dashboardCharts.zone4Lines.data.datasets[0].pcsData = line1PcsData;
            dashboardCharts.zone4Lines.data.datasets[1].data = line2Data;
            dashboardCharts.zone4Lines.data.datasets[1].pcsData = line2PcsData;
            dashboardCharts.zone4Lines.data.datasets[2].data = line3Data;
            dashboardCharts.zone4Lines.data.datasets[2].pcsData = line3PcsData;
            dashboardCharts.zone4Lines.data.datasets[3].data = line4Data;
            dashboardCharts.zone4Lines.data.datasets[3].pcsData = line4PcsData;
            dashboardCharts.zone4Lines.options.scales.y.suggestedMax = maxLineKg > 0 ? maxLineKg * 1.25 : 50;
            dashboardCharts.zone4Lines.update();
        }
    }

    // Switch active stage for Zone 4 WIP Snapshot (Independent dropdown)
    function switchZone4WipStage(stageKey) {
        dashboardCharts.currentZone4WipStage = stageKey;
        const latestData = window.latestDashboardData || DASHBOARD_CONFIG.initialData || {};
        const z4Wip = latestData.zone_4?.wip_snapshot || {};
        const stagesMap = z4Wip.stages || {};
        const stageData = stagesMap[stageKey] || {};
        const daily = stageData.daily || [];
        const latestSnap = stageData.latest_snapshot || {};
        const isAvail = !!latestSnap.is_available;

        const summaryContentEl = document.getElementById('z4-wip-summary-content');
        if (summaryContentEl) {
            if (isAvail) {
                const kgVal = stageData.total_weight_kg ?? latestSnap.weight_kg ?? 0;
                const pcsVal = stageData.total_good_pcs ?? latestSnap.qty_pcs ?? 0;
                const ktrVal = stageData.total_ktr_count ?? latestSnap.ktr_count ?? 0;
                summaryContentEl.innerHTML = `
                    <span class="font-black text-slate-950" id="z4-wip-total-kg">${formatNumber(kgVal, 0)}</span> <span class="font-bold text-slate-500 text-[10px]">kg</span>
                    <span class="text-slate-300 font-bold">|</span>
                    <span class="font-bold text-slate-800" id="z4-wip-total-pcs">${formatNumber(pcsVal)}</span> <span class="text-slate-500 text-[10px]">PCS</span>
                    <span class="text-slate-300 font-bold">|</span>
                    <span class="font-bold text-slate-800" id="z4-wip-total-ktr">${formatNumber(ktrVal)}</span> <span class="text-slate-500 text-[10px]">KTR</span>
                `;
            } else {
                summaryContentEl.innerHTML = `
                    <span class="text-slate-400 italic font-sans text-[11px]" id="z4-wip-unavailable-label">Data Snapshot Tidak Tersedia</span>
                    <span class="hidden" id="z4-wip-total-kg">0</span>
                    <span class="hidden" id="z4-wip-total-pcs">0</span>
                    <span class="hidden" id="z4-wip-total-ktr">0</span>
                `;
            }
        } else {
            const elTotalKg = document.getElementById('z4-wip-total-kg');
            if (elTotalKg && stageData.total_weight_kg !== undefined) {
                elTotalKg.textContent = formatNumber(stageData.total_weight_kg, 0);
            }

            const elTotalPcs = document.getElementById('z4-wip-total-pcs');
            if (elTotalPcs && stageData.total_good_pcs !== undefined) {
                elTotalPcs.textContent = formatNumber(stageData.total_good_pcs);
            }

            const elTotalKtr = document.getElementById('z4-wip-total-ktr');
            if (elTotalKtr && stageData.total_ktr_count !== undefined) {
                elTotalKtr.textContent = formatNumber(stageData.total_ktr_count);
            }
        }

        const elStageLabel = document.getElementById('z4-wip-stage-label');
        if (elStageLabel && stageData.stage_label) {
            elStageLabel.textContent = stageData.stage_label;
        }

        const elSummaryStage = document.getElementById('z4-wip-summary-stage-name');
        if (elSummaryStage && stageData.stage_label) {
            elSummaryStage.textContent = stageData.stage_label;
        }

        if (dashboardCharts.zone4WipSnapshot) {
            const kgSeries = daily.map(d => (d.good_kg !== null && d.good_kg !== undefined) ? Number(d.good_kg) : null);
            const validKgValues = kgSeries.filter(v => v !== null && !isNaN(v));
            const maxKg = validKgValues.length > 0 ? Math.max(...validKgValues, 0) : 0;

            dashboardCharts.zone4WipSnapshot.data.labels = daily.map(d => d.day_label);
            dashboardCharts.zone4WipSnapshot.data.datasets[0].data = kgSeries;
            dashboardCharts.zone4WipSnapshot.data.datasets[0].dailyData = daily;
            dashboardCharts.zone4WipSnapshot.data.datasets[0].backgroundColor = daily.map(d => d.is_reconstructed ? '#f59e0b' : '#0284c7');
            dashboardCharts.zone4WipSnapshot.data.datasets[0].hoverBackgroundColor = daily.map(d => d.is_reconstructed ? '#d97706' : '#0369a1');
            dashboardCharts.zone4WipSnapshot.options.scales.y.suggestedMax = maxKg > 0 ? maxKg * 1.35 : 100;
            dashboardCharts.zone4WipSnapshot.update();
        }
    }

    // Heartbeat state machine (LIVE / STALE / OFFLINE)
    function evaluateHeartbeat() {
        const now = new Date();
        const elapsedSec = Math.floor((now.getTime() - lastSuccessfulRefresh.getTime()) / 1000);

        const badge = document.getElementById('heartbeat-badge');
        const dot = document.getElementById('heartbeat-dot');
        const text = document.getElementById('heartbeat-text');
        const alertBox = document.getElementById('connection-alert');
        const alertMsg = document.getElementById('connection-alert-msg');
        const alertIcon = document.getElementById('connection-alert-icon');
        const lastRefreshLabel = document.getElementById('last-refresh-time');

        // Update refresh time text (unambiguous format)
        if (lastRefreshLabel) {
            const timeStr = lastSuccessfulRefresh.toLocaleTimeString('id-ID', { hour12: false });
            if (elapsedSec < 10) {
                lastRefreshLabel.textContent = `Update ${timeStr} WIB (Baru saja)`;
            } else if (elapsedSec < 60) {
                lastRefreshLabel.textContent = `Update ${timeStr} WIB (${elapsedSec} detik lalu)`;
            } else {
                lastRefreshLabel.textContent = `Update ${timeStr} WIB (${Math.floor(elapsedSec / 60)} menit lalu)`;
            }
        }

        // Live Clock
        const clockEl = document.getElementById('live-clock');
        if (clockEl) {
            clockEl.textContent = now.toLocaleTimeString('id-ID', { hour12: false }) + ' WIB';
        }

        // Determine state
        let state = 'LIVE';
        if (consecutiveFailures >= 4 || elapsedSec >= 300) {
            state = 'OFFLINE';
        } else if (consecutiveFailures >= 1 || elapsedSec >= 90) {
            state = 'STALE';
        }

        // Apply UI styling according to state (Light Mode)
        if (state === 'LIVE') {
            badge.className = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border font-black uppercase tracking-wider text-[11px] bg-emerald-50 text-emerald-800 border-emerald-300';
            dot.className = 'w-2 h-2 rounded-full bg-emerald-600';
            text.textContent = 'LIVE';
            alertBox.classList.add('hidden');
        } else if (state === 'STALE') {
            badge.className = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border font-black uppercase tracking-wider text-[11px] bg-amber-50 text-amber-800 border-amber-300';
            dot.className = 'w-2 h-2 rounded-full bg-amber-500';
            text.textContent = 'STALE';

            alertBox.className = 'rounded-xl p-3 border text-xs flex items-center justify-between shadow-xs bg-amber-50 border-amber-300 text-amber-900';
            alertIcon.className = 'fas fa-exclamation-triangle text-amber-600 text-base';
            alertMsg.textContent = `Data berpotensi usang (Terakhir berhasil ${elapsedSec} detik lalu, ${consecutiveFailures} percobaan gagal).`;
            alertBox.classList.remove('hidden');
        } else {
            badge.className = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border font-black uppercase tracking-wider text-[11px] bg-rose-50 text-rose-800 border-rose-300';
            dot.className = 'w-2 h-2 rounded-full bg-rose-600';
            text.textContent = 'OFFLINE';

            alertBox.className = 'rounded-xl p-3 border text-xs flex items-center justify-between shadow-xs bg-rose-50 border-rose-300 text-rose-900';
            alertIcon.className = 'fas fa-ban text-rose-600 text-base';
            alertMsg.textContent = `Koneksi ke server terputus (${consecutiveFailures} kegagalan berturut-turut). Menampilkan data lokal terakhir.`;
            alertBox.classList.remove('hidden');
        }
    }

    // Polling function
    async function pollDashboardData(isManual = false) {
        if (isPolling) return;
        isPolling = true;

        const refreshIcon = document.getElementById('refresh-icon');
        if (refreshIcon) refreshIcon.classList.add('fa-spin');

        const freshParam = isManual ? '&fresh=1' : '';
        const url = `${DASHBOARD_CONFIG.dataEndpoint}?zone=${encodeURIComponent(DASHBOARD_CONFIG.zone)}${freshParam}&_t=${Date.now()}`;

        try {
            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            // Store latest received data for dropdown stage switching
            window.latestDashboardData = data;

            // Success: update timestamp & reset failures
            lastSuccessfulRefresh = new Date();
            consecutiveFailures = 0;

            // In-place updates
            updateDom(data);
            updateChartsInPlace(data);
        } catch (error) {
            console.warn('[Dashboard Polling Error]', error.message);
            consecutiveFailures++;
        } finally {
            isPolling = false;
            if (refreshIcon) refreshIcon.classList.remove('fa-spin');
            evaluateHeartbeat();
        }
    }

    function triggerManualRefresh() {
        pollDashboardData(true);
    }

    // Lifecycle setup
    document.addEventListener('DOMContentLoaded', function() {
        // Store preloaded initial payload
        window.latestDashboardData = DASHBOARD_CONFIG.initialData;

        // Initialize charts using preloaded initial payload
        initCharts(DASHBOARD_CONFIG.initialData);

        // Start heartbeat tick every second
        evaluateHeartbeat();
        heartbeatTickTimer = setInterval(evaluateHeartbeat, 1000);

        // Start polling every 60s
        pollIntervalTimer = setInterval(() => {
            if (!document.hidden) {
                pollDashboardData(false);
            }
        }, DASHBOARD_CONFIG.pollIntervalMs);

        // Visibility handling (refresh if returning after >= 45s)
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                const elapsedSec = Math.floor((Date.now() - lastSuccessfulRefresh.getTime()) / 1000);
                if (elapsedSec >= 45) {
                    pollDashboardData(false);
                }
            }
        });
    });
</script>
@endsection
