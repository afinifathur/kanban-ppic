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
        verifiedDefects: null,
        defectTrend: null,
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

    // Custom Chart.js Plugin for Direct Data Labels on Throughput Bars (SCADA style, no hover needed)
    const directDataLabelsPlugin = {
        id: 'directDataLabels',
        afterDatasetsDraw(chart) {
            const { ctx, data } = chart;
            const meta = chart.getDatasetMeta(0); // Bar dataset (Good Output TON)
            if (!meta || meta.hidden) return;

            const barDataset = data.datasets[0];
            const stages = barDataset.stagesData || [];

            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'bottom';

            meta.data.forEach((bar, index) => {
                const tonVal = Number(barDataset.data[index] || 0);
                const stage = stages[index] || {};
                const pcsVal = Number(stage.good_pcs || 0);
                const isZero = (tonVal === 0 && pcsVal === 0);

                const x = bar.x;
                const topY = bar.y;

                // Format values
                const tonText = `${formatNumber(tonVal, 2)} TON`;
                const pcsText = `${formatNumber(pcsVal, 0)} PCS`;

                // Safe clearance from chartArea top to guarantee no clipping
                const safeTopLimit = chart.chartArea.top + 36;
                const pcsY = Math.max(topY - 6, safeTopLimit);
                const tonY = pcsY - 16;

                // Subtle semi-transparent rounded pill for background contrast
                ctx.font = 'bold 14px ui-sans-serif, system-ui, sans-serif';
                const textWidth = Math.max(ctx.measureText(tonText).width, ctx.measureText(pcsText).width);
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

                // Subtle border on pill
                ctx.strokeStyle = isZero ? '#cbd5e1' : '#bfdbfe';
                ctx.lineWidth = 1;
                ctx.stroke();

                // Baris 1: Tonase aktual (TON)
                ctx.font = isZero
                    ? 'bold 12px ui-sans-serif, system-ui, sans-serif'
                    : 'bold 14px ui-sans-serif, system-ui, sans-serif';
                ctx.fillStyle = isZero ? '#64748b' : '#0f172a';
                ctx.fillText(tonText, x, tonY);

                // Baris 2: PCS aktual (PCS)
                ctx.font = isZero
                    ? 'bold 11px ui-sans-serif, system-ui, sans-serif'
                    : 'bold 12px ui-sans-serif, system-ui, sans-serif';
                ctx.fillStyle = isZero ? '#64748b' : '#1e3a8a';
                ctx.fillText(pcsText, x, pcsY);
            });

            ctx.restore();
        }
    };

    // Initialize Chart.js instances
    function initCharts(data) {
        // 1. Throughput Chart (Zone 1)
        const throughputCanvas = document.getElementById('chartThroughput');
        if (throughputCanvas && (data.zone_1 || DASHBOARD_CONFIG.zone === '1' || DASHBOARD_CONFIG.zone === 'all')) {
            const stages = (data.zone_1 && data.zone_1.daily_throughput_stages) ? data.zone_1.daily_throughput_stages : [];
            const labels = stages.map(s => s.label);
            const tonnageData = stages.map(s => Number(s.good_tonnage ?? s.good_ton ?? 0));
            const defectRateData = stages.map(s => Number(s.defect_rate_pct ?? s.defect_rate ?? 0));
            const maxTon = Math.max(...tonnageData, 1);

            const ctx = throughputCanvas.getContext('2d');
            dashboardCharts.throughput = new Chart(ctx, {
                type: 'bar',
                plugins: [directDataLabelsPlugin],
                data: {
                    labels: labels,
                    datasets: [
                        {
                            type: 'bar',
                            label: 'Good Output (TON)',
                            data: tonnageData,
                            stagesData: stages,
                            backgroundColor: '#2563eb',
                            borderColor: '#1d4ed8',
                            borderWidth: 1,
                            borderRadius: 4,
                            yAxisID: 'yTon',
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
                                        return ` Good: ${formatNumber(context.raw, 2)} TON (${formatNumber(stage.good_pcs || 0)} PCS)`;
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
                            ticks: { color: '#0f172a', font: { weight: 'bold', size: 12 } }
                        },
                        yTon: {
                            type: 'linear',
                            position: 'left',
                            beginAtZero: true,
                            suggestedMax: maxTon > 0 ? maxTon * 1.45 : 5,
                            grid: { color: '#e2e8f0' },
                            title: { display: true, text: 'TON', color: '#1d4ed8', font: { size: 11, weight: 'bold' } },
                            ticks: { color: '#475569', font: { weight: 'bold' } }
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

            const ctx = trendCanvas.getContext('2d');
            dashboardCharts.defectTrend30 = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Estimasi Defect (kg)',
                        data: defectKg,
                        backgroundColor: barColors,
                        borderRadius: 3,
                        maxBarThickness: 24,
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
                                    const t = trend[context.dataIndex] || {};
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
    }

    // In-place Chart update
    function updateChartsInPlace(data) {
        // Update Throughput
        if (dashboardCharts.throughput && data.zone_1 && data.zone_1.daily_throughput_stages) {
            const stages = data.zone_1.daily_throughput_stages;
            const newTonnage = stages.map(s => Number(s.good_tonnage ?? s.good_ton ?? 0));
            const newDefectRate = stages.map(s => Number(s.defect_rate_pct ?? s.defect_rate ?? 0));
            const maxT = Math.max(...newTonnage, 1);
            dashboardCharts.throughput.data.labels = stages.map(s => s.label);
            dashboardCharts.throughput.data.datasets[0].data = newTonnage;
            dashboardCharts.throughput.data.datasets[0].stagesData = stages;
            dashboardCharts.throughput.data.datasets[1].data = newDefectRate;
            dashboardCharts.throughput.options.scales.yTon.suggestedMax = maxT * 1.45;
            dashboardCharts.throughput.options.scales.yPct.suggestedMax = Math.max(...newDefectRate, 5) * 1.25;
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
            dashboardCharts.defectTrend30.data.labels = trend.map(t => t.day_label);
            dashboardCharts.defectTrend30.data.datasets[0].data = trend.map(t => (t.estimated_defect_kg !== null && t.estimated_defect_kg !== undefined) ? Number(t.estimated_defect_kg) : 0);
            dashboardCharts.defectTrend30.data.datasets[0].backgroundColor = trend.map(t => (t.defect_pcs === null) ? 'rgba(203, 213, 225, 0.4)' : ((t.estimated_defect_kg > 0 || t.defect_pcs > 0) ? '#e11d48' : '#e2e8f0'));
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
                    html += ` <span class="text-slate-300">/</span> <span class="text-xs sm:text-sm font-bold text-slate-700">${formatNumber(yd.weight_kg, 1)} kg</span>`;
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
            const cards = z3.action_cards || {};
            const defToday = z3.defects_today || {};

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

            const elUnrecKtr = document.getElementById('z3-unrecorded-ktr');
            if (elUnrecKtr) elUnrecKtr.textContent = formatNumber(cards.unrecorded_defects?.ktr_count ?? 0);

            const elUnrecPcs = document.getElementById('z3-unrecorded-pcs');
            if (elUnrecPcs) elUnrecPcs.textContent = formatNumber(cards.unrecorded_defects?.pcs_waiting ?? 0);

            const elAutoKtr = document.getElementById('z3-autonihil-ktr');
            if (elAutoKtr) elAutoKtr.textContent = formatNumber(cards.auto_nihil_waiting_qc?.ktr_count ?? 0);

            const elPpicKtr = document.getElementById('z3-ppicdefect-ktr');
            if (elPpicKtr) elPpicKtr.textContent = formatNumber(cards.ppic_defect_waiting_qc?.ktr_count ?? 0);

            const elPpicPcs = document.getElementById('z3-ppicdefect-pcs');
            if (elPpicPcs) elPpicPcs.textContent = formatNumber(cards.ppic_defect_waiting_qc?.pcs_defect ?? 0);

            const elDefPcs = document.getElementById('z3-today-defect-pcs');
            if (elDefPcs) elDefPcs.textContent = formatNumber(defToday.total_defect_pcs ?? 0);

            const elDefTon = document.getElementById('z3-today-defect-ton');
            if (elDefTon) elDefTon.textContent = formatNumber(defToday.total_defect_tonnage ?? defToday.total_defect_ton ?? 0, 2);

            const elUnverPcs = document.getElementById('z3-unverified-pcs');
            if (elUnverPcs) elUnverPcs.textContent = formatNumber(defToday.unverified_breakdown_pcs ?? 0);
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

        const url = `${DASHBOARD_CONFIG.dataEndpoint}?zone=${encodeURIComponent(DASHBOARD_CONFIG.zone)}&_t=${Date.now()}`;

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
