@extends('layouts.app')

@section('top_bar')
    <div>
        <h1 class="text-lg font-bold text-slate-800 leading-tight">Dashboard Produksi</h1>
        <p class="text-gray-500 text-[10px]">Monitoring distribusi dan perpindahan produksi</p>
    </div>
@endsection

@section('content')
    <div class="p-0 h-full overflow-y-auto">

        <!-- Top Stats Cards -->
        <div class="grid grid-cols-6 gap-4 mb-6">
            @foreach($depts as $dept)
                <div class="bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
                    <h3 class="font-bold text-gray-700 mb-2">{{ $dept === 'cor' ? 'Rencana Cor' : ucfirst($dept) }}</h3>
                    <div class="flex justify-between items-end">
                        <div class="text-gray-500 text-xs">PCS:</div>
                        <div class="font-bold text-lg">{{ number_format($stats[$dept]['total_pcs'] ?? 0) }}</div>
                    </div>
                    <div class="flex justify-between items-end">
                        <div class="text-gray-500 text-xs">KG:</div>
                        <div class="font-bold text-green-600">{{ number_format($stats[$dept]['total_kg'] ?? 0) }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Charts Row 1: Distribution -->
        <div class="grid grid-cols-2 gap-6 mb-6">
            <div class="bg-white rounded-lg shadow p-4">
                <h3 class="font-bold text-gray-700 mb-4">Distribusi PCS per Departemen</h3>
                <div class="relative h-64">
                    <canvas id="distPcsChart"></canvas>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <h3 class="font-bold text-gray-700 mb-4">Distribusi KG per Departemen</h3>
                <div class="relative h-64">
                    <canvas id="distKgChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Charts Row 2: 12 Individual Movement Charts (6 Stages x 2 Metrics) -->
        <h2 class="text-xl font-bold text-gray-800 mb-4 mt-8 flex items-center gap-2">
            <i class="fas fa-chart-line text-blue-600"></i> Detail Perpindahan Produksi (7 Hari)
        </h2>

        <div class="space-y-8 pb-12">
            @foreach($stages as $stageKey => $stageName)
                <div class="grid grid-cols-2 gap-6">
                    <!-- PCS Chart for this stage -->
                    <div class="bg-white rounded-lg shadow-sm border border-slate-200 p-5 hover:shadow-md transition-shadow">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="font-bold text-slate-700">{{ $stageName }} (PCS)</h3>
                            <span
                                class="text-[10px] font-bold text-blue-600 bg-blue-50 px-2 py-1 rounded uppercase tracking-wider">Unit:
                                PCS</span>
                        </div>
                        <div class="relative h-48">
                            <canvas id="chart_pcs_{{ $stageKey }}"></canvas>
                        </div>
                    </div>

                    <!-- KG Chart for this stage -->
                    <div class="bg-white rounded-lg shadow-sm border border-slate-200 p-5 hover:shadow-md transition-shadow">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="font-bold text-slate-700">{{ $stageName }} (KG)</h3>
                            <span
                                class="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-1 rounded uppercase tracking-wider">Unit:
                                KG</span>
                        </div>
                        <div class="relative h-48">
                            <canvas id="chart_kg_{{ $stageKey }}"></canvas>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <script src="{{ asset('js/chart.min.js') }}"></script>
    <script>
        const depts = @json($depts);
        const stats = @json($stats);
        const dates = @json($dates);
        const lineStats = @json($lineStats);
        const stagesList = @json($stages);

        // 1. Distribution Charts (Donut)
        const totalPcs = depts.reduce((acc, d) => acc + Number(stats[d]?.total_pcs || 0), 0);
        const totalKg = depts.reduce((acc, d) => acc + Number(stats[d]?.total_kg || 0), 0);

        const labelsPcs = depts.map(d => {
            let name = d === 'cor' ? 'Rencana Cor' : d.charAt(0).toUpperCase() + d.slice(1);
            const val = Number(stats[d]?.total_pcs || 0);
            const pct = totalPcs > 0 ? ((val / totalPcs) * 100).toFixed(1) : 0;
            return `${name} (${pct}%)`;
        });

        const labelsKg = depts.map(d => {
            let name = d === 'cor' ? 'Rencana Cor' : d.charAt(0).toUpperCase() + d.slice(1);
            const val = Number(stats[d]?.total_kg || 0);
            const pct = totalKg > 0 ? ((val / totalKg) * 100).toFixed(1) : 0;
            return `${name} (${pct}%)`;
        });

        const dataPcs = depts.map(d => Number(stats[d]?.total_pcs || 0));
        const dataKg = depts.map(d => Number(stats[d]?.total_kg || 0));
        const donutColors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'];

        const donutOptions = (unit) => ({
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 10,
                        font: { size: 10, weight: 'bold' },
                        padding: 8
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            let label = context.label.split(' (')[0];
                            const value = context.parsed;
                            return ` ${label}: ${value.toLocaleString()} ${unit}`;
                        }
                    }
                }
            }
        });

        new Chart(document.getElementById('distPcsChart'), {
            type: 'doughnut', data: { labels: labelsPcs, datasets: [{ data: dataPcs, backgroundColor: donutColors }] }, options: donutOptions('PCS')
        });
        new Chart(document.getElementById('distKgChart'), {
            type: 'doughnut', data: { labels: labelsKg, datasets: [{ data: dataKg, backgroundColor: donutColors }] }, options: donutOptions('KG')
        });

        // 2. Individual Line Charts (12 Charts with 4 machine lines each)
        const lineColors = {
            1: '#3b82f6', // Blue (Line 1)
            2: '#10b981', // Green (Line 2)
            3: '#f59e0b', // Yellow/Orange (Line 3)
            4: '#ef4444'  // Red (Line 4)
        };

        const chartOptions = (title) => ({
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    align: 'end',
                    labels: { boxWidth: 8, padding: 10, font: { size: 9, weight: 'bold' }, usePointStyle: true }
                },
                tooltip: { backgroundColor: 'rgba(15, 23, 42, 0.9)', padding: 10 }
            },
            scales: {
                y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { font: { size: 8 }, color: '#94a3b8' } },
                x: { grid: { display: false }, ticks: { font: { size: 8 }, color: '#94a3b8' } }
            }
        });

        // Loop through stages and metrics to initialize 12 charts
        Object.keys(stagesList).forEach(stageKey => {
            const stageName = stagesList[stageKey];

            // PCS Chart
            new Chart(document.getElementById(`chart_pcs_${stageKey}`), {
                type: 'line',
                data: {
                    labels: dates.map(d => d.split('-')[2]),
                    datasets: [1, 2, 3, 4].map(line => ({
                        label: 'L' + line,
                        data: lineStats['pcs'][stageName][line],
                        borderColor: lineColors[line],
                        backgroundColor: lineColors[line] + '10',
                        fill: false,
                        tension: 0.3,
                        pointRadius: 2,
                        borderWidth: 1.5
                    }))
                },
                options: chartOptions(stageName + ' PCS')
            });

            // KG Chart
            new Chart(document.getElementById(`chart_kg_${stageKey}`), {
                type: 'line',
                data: {
                    labels: dates.map(d => d.split('-')[2]),
                    datasets: [1, 2, 3, 4].map(line => ({
                        label: 'L' + line,
                        data: lineStats['kg'][stageName][line],
                        borderColor: lineColors[line],
                        backgroundColor: lineColors[line] + '10',
                        fill: false,
                        tension: 0.3,
                        pointRadius: 2,
                        borderWidth: 1.5
                    }))
                },
                options: chartOptions(stageName + ' KG')
            });
        });

    </script>
@endsection