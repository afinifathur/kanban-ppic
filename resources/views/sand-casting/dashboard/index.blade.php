@extends('layouts.app')

@section('top_bar')
    <div class="flex flex-col md:flex-row md:items-center justify-between w-full gap-2 py-1">
        <!-- Left: Title & Subtitle -->
        <div class="flex items-center gap-2.5 shrink-0">
            <span class="px-2 py-0.5 rounded text-[10px] font-black bg-blue-600 text-white tracking-wider uppercase">
                SAND CASTING
            </span>
            <div>
                <h1 class="text-base sm:text-lg font-black text-slate-800 tracking-tight leading-tight uppercase flex items-center gap-2">
                    DASHBOARD OPERASIONAL
                </h1>
                <p class="text-slate-500 text-[11px]">Monitoring visual terpadu lantai produksi pengecoran pasir</p>
            </div>
        </div>

        <!-- Center: Zone Switcher Pills -->
        <div class="flex items-center gap-1 bg-slate-100 p-1 rounded-lg border border-slate-200 text-xs shrink-0 self-start md:self-auto overflow-x-auto no-scrollbar">
            <a href="{{ route('sand-casting.dashboard') }}"
                class="px-2.5 py-1 rounded-md font-bold transition-all {{ $activeZone === 'all' ? 'bg-white text-blue-700 shadow-xs' : 'text-slate-600 hover:text-slate-900' }}">
                Semua Zona
            </a>
            <a href="{{ route('sand-casting.dashboard', ['zone' => '1']) }}"
                class="px-2.5 py-1 rounded-md font-bold transition-all {{ $activeZone === '1' ? 'bg-white text-blue-700 shadow-xs' : 'text-slate-600 hover:text-slate-900' }}">
                Zona 1 <span class="hidden lg:inline font-normal text-slate-400">(Overview)</span>
            </a>
            <a href="{{ route('sand-casting.dashboard', ['zone' => '2']) }}"
                class="px-2.5 py-1 rounded-md font-bold transition-all {{ $activeZone === '2' ? 'bg-white text-blue-700 shadow-xs' : 'text-slate-600 hover:text-slate-900' }}">
                Zona 2 <span class="hidden lg:inline font-normal text-slate-400">(WIP)</span>
            </a>
            <a href="{{ route('sand-casting.dashboard', ['zone' => '3']) }}"
                class="px-2.5 py-1 rounded-md font-bold transition-all {{ $activeZone === '3' ? 'bg-white text-blue-700 shadow-xs' : 'text-slate-600 hover:text-slate-900' }}">
                Zona 3 <span class="hidden lg:inline font-normal text-slate-400">(Quality)</span>
            </a>
        </div>

        <!-- Right: Status Badge & Live Clock -->
        <div class="flex items-center gap-2 text-xs shrink-0">
            <!-- Heartbeat Status Indicator -->
            <div id="heartbeat-badge" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border font-bold uppercase tracking-wider text-[11px] bg-emerald-50 text-emerald-700 border-emerald-300">
                <span id="heartbeat-dot" class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <span id="heartbeat-text">LIVE</span>
            </div>

            <!-- Last Refresh & Clock -->
            <div class="hidden sm:flex flex-col text-right leading-tight">
                <span id="live-clock" class="font-mono font-bold text-slate-800 text-xs">--:--:-- WIB</span>
                <span id="last-refresh-time" class="text-[10px] text-slate-500">Update: Baru saja</span>
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
<div class="space-y-6">
    <!-- Alert Banner for STALE or OFFLINE state -->
    <div id="connection-alert" class="hidden rounded-xl p-3 border text-xs flex items-center justify-between shadow-xs">
        <div class="flex items-center gap-2">
            <i id="connection-alert-icon" class="fas fa-exclamation-triangle text-base"></i>
            <span id="connection-alert-msg" class="font-medium"></span>
        </div>
        <span class="text-[10px] uppercase font-mono opacity-80" id="connection-alert-sub">Mempertahankan Data Terakhir</span>
    </div>

    <!-- Master Industrial Dashboard Container (Light Mode) -->
    <div class="space-y-6 lg:space-y-8">
        {{-- Zone 1: Executive Overview --}}
        @if($activeZone === 'all' || $activeZone === '1')
            @include('sand-casting.dashboard.partials.zone-1')
        @endif

        {{-- Zone 2: WIP & Production Flow --}}
        @if($activeZone === 'all' || $activeZone === '2')
            @include('sand-casting.dashboard.partials.zone-2')
        @endif

        {{-- Zone 3: Quality, Defect & Action Required --}}
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

    // Initialize Chart.js instances
    function initCharts(data) {
        // 1. Throughput Chart (Zone 1)
        const throughputCanvas = document.getElementById('chartThroughput');
        if (throughputCanvas && (data.zone_1 || DASHBOARD_CONFIG.zone === '1' || DASHBOARD_CONFIG.zone === 'all')) {
            const stages = (data.zone_1 && data.zone_1.daily_throughput_stages) ? data.zone_1.daily_throughput_stages : [];
            const labels = stages.map(s => s.label);
            const tonnageData = stages.map(s => Number(s.good_tonnage ?? s.good_ton ?? 0));
            const defectRateData = stages.map(s => Number(s.defect_rate_pct ?? s.defect_rate ?? 0));

            const ctx = throughputCanvas.getContext('2d');
            dashboardCharts.throughput = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            type: 'bar',
                            label: 'Good Output (TON)',
                            data: tonnageData,
                            backgroundColor: '#3b82f6',
                            borderColor: '#2563eb',
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
                            pointBorderWidth: 1.5,
                            pointRadius: 4,
                            yAxisID: 'yPct',
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top', labels: { boxWidth: 12, color: '#334155', font: { size: 11, weight: 'bold' } } },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const stage = stages[context.dataIndex] || {};
                                    if (context.datasetIndex === 0) {
                                        return ` Good: ${formatNumber(context.raw, 2)} TON (${formatNumber(stage.good_pcs || 0)} PCS)`;
                                    } else {
                                        return ` Defect Rate: ${formatNumber(context.raw, 2)}% (${formatNumber(stage.defect_pcs || 0)} PCS cacat dari input ${formatNumber(stage.input_pcs || 0)} PCS)`;
                                    }
                                }
                            }
                        }
                    },
                    scales: {
                        x: { grid: { color: '#f1f5f9' }, ticks: { color: '#64748b', font: { weight: 'bold' } } },
                        yTon: {
                            type: 'linear',
                            position: 'left',
                            beginAtZero: true,
                            grid: { color: '#e2e8f0' },
                            title: { display: true, text: 'TON', color: '#2563eb', font: { size: 10, weight: 'bold' } },
                            ticks: { color: '#64748b' }
                        },
                        yPct: {
                            type: 'linear',
                            position: 'right',
                            beginAtZero: true,
                            grid: { drawOnChartArea: false },
                            title: { display: true, text: 'Defect %', color: '#dc2626', font: { size: 10, weight: 'bold' } },
                            ticks: { callback: v => v + '%', color: '#64748b' }
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
                        legend: { position: 'bottom', labels: { boxWidth: 10, color: '#475569', font: { size: 11 } } },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const w = wipDist[context.dataIndex] || {};
                                    return ` ${w.label}: ${formatNumber(w.wip_ton, 2)} TON (${formatNumber(w.wip_pcs)} PCS, ${formatNumber(w.percentage, 1)}%)`;
                                }
                            }
                        }
                    },
                    cutout: '65%',
                }
            });
        }

        // 3. Verified Defect Breakdown Donut Chart (Zone 3)
        const defectDonutCanvas = document.getElementById('chartVerifiedDefects');
        if (defectDonutCanvas && (data.zone_3 || DASHBOARD_CONFIG.zone === '3' || DASHBOARD_CONFIG.zone === 'all')) {
            const verified = (data.zone_3 && data.zone_3.defects_today && data.zone_3.defects_today.verified_breakdown) ? data.zone_3.defects_today.verified_breakdown : [];
            const labels = verified.length > 0 ? verified.map(d => d.name) : ['Nihil Defect'];
            const pcs = verified.length > 0 ? verified.map(d => Number(d.qty_pcs || 0)) : [0];
            const colors = ['#dc2626', '#ea580c', '#d97706', '#0284c7', '#4f46e5', '#7c3aed'];

            const ctx = defectDonutCanvas.getContext('2d');
            dashboardCharts.verifiedDefects = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: pcs,
                        backgroundColor: verified.length > 0 ? colors.slice(0, labels.length) : ['#e2e8f0'],
                        borderColor: '#ffffff',
                        borderWidth: 2,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 10, color: '#475569', font: { size: 10 } } },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const d = verified[context.dataIndex] || {};
                                    return ` ${context.label}: ${formatNumber(context.raw)} PCS (${formatNumber(d.percentage || 0, 1)}%)`;
                                }
                            }
                        }
                    },
                    cutout: '60%',
                }
            });
        }

        // 4. 7-Day Defect Trend Chart (Zone 3)
        const trendCanvas = document.getElementById('chartDefectTrend');
        if (trendCanvas && (data.zone_3 || DASHBOARD_CONFIG.zone === '3' || DASHBOARD_CONFIG.zone === 'all')) {
            const trend = (data.zone_3 && data.zone_3.defect_trend_7_days) ? data.zone_3.defect_trend_7_days : [];
            const labels = trend.map(t => t.day_label);
            const defectPcs = trend.map(t => Number(t.defect_pcs || 0));

            const ctx = trendCanvas.getContext('2d');
            dashboardCharts.defectTrend = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Defect (PCS)',
                        data: defectPcs,
                        borderColor: '#dc2626',
                        backgroundColor: 'rgba(220, 38, 38, 0.06)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.2,
                        pointBackgroundColor: '#dc2626',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 1.5,
                        pointRadius: 4,
                        pointHoverRadius: 6,
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
                                    return ` Defect: ${formatNumber(context.raw)} PCS (${formatNumber(t.defect_tonnage ?? t.defect_ton ?? 0, 2)} TON)`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: { grid: { color: '#f1f5f9' }, ticks: { color: '#64748b' } },
                        y: {
                            beginAtZero: true,
                            grid: { color: '#e2e8f0' },
                            ticks: { precision: 0, color: '#64748b' }
                        }
                    }
                }
            });
        }
    }

    // In-place Chart update
    function updateChartsInPlace(data) {
        // Update Throughput
        if (dashboardCharts.throughput && data.zone_1 && data.zone_1.daily_throughput_stages) {
            const stages = data.zone_1.daily_throughput_stages;
            dashboardCharts.throughput.data.labels = stages.map(s => s.label);
            dashboardCharts.throughput.data.datasets[0].data = stages.map(s => Number(s.good_tonnage ?? s.good_ton ?? 0));
            dashboardCharts.throughput.data.datasets[1].data = stages.map(s => Number(s.defect_rate_pct ?? s.defect_rate ?? 0));
            dashboardCharts.throughput.update();
        }

        // Update WIP Donut
        if (dashboardCharts.wipDonut && data.zone_2 && data.zone_2.wip_distribution) {
            const wipDist = data.zone_2.wip_distribution;
            dashboardCharts.wipDonut.data.labels = wipDist.map(w => w.label);
            dashboardCharts.wipDonut.data.datasets[0].data = wipDist.map(w => Number(w.wip_ton || 0));
            dashboardCharts.wipDonut.update();
        }

        // Update Verified Defect Donut
        if (dashboardCharts.verifiedDefects && data.zone_3 && data.zone_3.defects_today) {
            const verified = data.zone_3.defects_today.verified_breakdown || [];
            dashboardCharts.verifiedDefects.data.labels = verified.length > 0 ? verified.map(d => d.name) : ['Nihil Defect'];
            dashboardCharts.verifiedDefects.data.datasets[0].data = verified.length > 0 ? verified.map(d => Number(d.qty_pcs || 0)) : [0];
            dashboardCharts.verifiedDefects.update();
        }

        // Update Defect Trend
        if (dashboardCharts.defectTrend && data.zone_3 && data.zone_3.defect_trend_7_days) {
            const trend = data.zone_3.defect_trend_7_days;
            dashboardCharts.defectTrend.data.labels = trend.map(t => t.day_label);
            dashboardCharts.defectTrend.data.datasets[0].data = trend.map(t => Number(t.defect_pcs || 0));
            dashboardCharts.defectTrend.update();
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
        }

        // Zone 2 DOM
        if (data.zone_2) {
            const z2 = data.zone_2;
            const elTotalWipTon = document.getElementById('z2-total-wip-ton');
            if (elTotalWipTon) elTotalWipTon.textContent = formatNumber(z2.total_active_wip_ton ?? 0, 2) + ' TON';

            const elTotalWipPcs = document.getElementById('z2-total-wip-pcs');
            if (elTotalWipPcs) elTotalWipPcs.textContent = formatNumber(z2.total_active_wip_pcs ?? 0) + ' PCS';
        }

        // Zone 3 DOM
        if (data.zone_3) {
            const z3 = data.zone_3;
            const cards = z3.action_cards || {};
            const defToday = z3.defects_today || {};

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

        // Update refresh time text
        if (lastRefreshLabel) {
            if (elapsedSec < 15) {
                lastRefreshLabel.textContent = 'Update: Baru saja';
            } else if (elapsedSec < 60) {
                lastRefreshLabel.textContent = `Update: ${elapsedSec}d lalu`;
            } else {
                lastRefreshLabel.textContent = `Update: ${Math.floor(elapsedSec / 60)}m lalu`;
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
            badge.className = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border font-bold uppercase tracking-wider text-[11px] bg-emerald-50 text-emerald-800 border-emerald-300';
            dot.className = 'w-2 h-2 rounded-full bg-emerald-600';
            text.textContent = 'LIVE';
            alertBox.classList.add('hidden');
        } else if (state === 'STALE') {
            badge.className = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border font-bold uppercase tracking-wider text-[11px] bg-amber-50 text-amber-800 border-amber-300';
            dot.className = 'w-2 h-2 rounded-full bg-amber-500';
            text.textContent = 'STALE';

            alertBox.className = 'rounded-xl p-3 border text-xs flex items-center justify-between shadow-xs bg-amber-50 border-amber-300 text-amber-900';
            alertIcon.className = 'fas fa-exclamation-triangle text-amber-600 text-base';
            alertMsg.textContent = `Data berpotensi usang (Terakhir berhasil ${elapsedSec} detik lalu, ${consecutiveFailures} percobaan gagal).`;
            alertBox.classList.remove('hidden');
        } else {
            badge.className = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border font-bold uppercase tracking-wider text-[11px] bg-rose-50 text-rose-800 border-rose-300';
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
            // Note: We intentionally DO NOT wipe out DOM/charts with zeros on network failure!
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

        // Visibility handling (save resources when tab is backgrounded, refresh on return)
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
