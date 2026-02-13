@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Dashboard Kerusakan</h1>
            <p class="text-gray-500 text-[10px]">Monitoring trend dan distribusi defect produksi</p>
        </div>

        <form method="GET" action="{{ route('dashboard.defects') }}"
            class="flex bg-white rounded-lg shadow-sm border border-slate-200 p-0.5">
            <input type="date" name="start_date" value="{{ $startDate->format('Y-m-d') }}"
                class="border-none text-[11px] focus:ring-0 text-slate-600 px-2 py-1">
            <span class="flex items-center text-slate-400 px-1 text-xs">-</span>
            <input type="date" name="end_date" value="{{ $endDate->format('Y-m-d') }}"
                class="border-none text-[11px] focus:ring-0 text-slate-600 px-2 py-1">
            <button type="submit"
                class="bg-blue-600 hover:bg-blue-700 text-white px-3 rounded text-[11px] font-bold transition-colors">
                Filter
            </button>
        </form>
    </div>
@endsection

@section('content')
    <div class="px-2 py-4">

        <!-- Line Chart: Weekly Trend -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-8">
            <h3 class="text-lg font-bold text-slate-700 mb-6 flex items-center gap-2">
                <span class="p-2 bg-blue-50 text-blue-600 rounded-lg"><i class="fas fa-chart-line"></i></span>
                Trend Kerusakan Mingguan (Week {{ $startDate->weekOfYear }} - {{ $endDate->weekOfYear }})
            </h3>
            <div class="relative h-80">
                <canvas id="weeklyTrendChart"></canvas>
            </div>
        </div>

        <!-- Donut Charts Row -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Chart 1: By Type -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 flex flex-col">
                <h3 class="text-md font-bold text-slate-700 mb-4 pb-3 border-b border-slate-100">
                    Distribusi per Jenis Kerusakan
                </h3>
                <div class="relative h-64 flex-1">
                    <canvas id="chartByType"></canvas>
                </div>
            </div>

            <!-- Chart 2: By Department -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 flex flex-col">
                <h3 class="text-md font-bold text-slate-700 mb-4 pb-3 border-b border-slate-100">
                    Distribusi per Departemen
                </h3>
                <div class="relative h-64 flex-1">
                    <canvas id="chartByDept"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script src="{{ asset('js/chart.min.js') }}"></script>
    <script>
        // Common Options
        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.color = '#64748b';

        // 1. Weekly Trend Line Chart
        const pcsData = @json($lineChartPcs);
        const kgData = @json($lineChartKg);

        // Calculate max values for proportional scaling (2 PCS : 1 KG or 2000 PCS : 1 Ton)
        // Since $lineChartKg is already in Ton (kg/1000), ratio is 2000 PCS : 1 Ton
        const maxPcsValue = Math.max(...pcsData, 10);
        const maxTonValue = Math.max(...kgData, 0.01);

        // We want visual alignment: shared max height.
        // Let's find a scale that fits both.
        // If maxPcs is 3000 and maxTon is 3. 
        // 3000 / 2000 = 1.5. 
        // Max Scale for PCS could be 4000, for Ton could be 2.
        const suggestedMaxPcs = Math.max(maxPcsValue, maxTonValue * 2000) * 1.1;
        const suggestedMaxTon = suggestedMaxPcs / 2000;

        new Chart(document.getElementById('weeklyTrendChart'), {
            type: 'line',
            data: {
                labels: @json($lineChartLabels),
                datasets: [
                    {
                        label: 'Total PCS',
                        data: pcsData,
                        borderColor: '#3b82f6', // Blue
                        backgroundColor: '#3b82f6',
                        yAxisID: 'y',
                        tension: 0.3,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 5
                    },
                    {
                        label: 'Total Tonase (Ton)',
                        data: kgData,
                        borderColor: '#f97316', // Orange
                        backgroundColor: '#f97316',
                        yAxisID: 'y1',
                        tension: 0.3,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 5
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', align: 'end', labels: { usePointStyle: true, boxWidth: 8 } },
                    tooltip: { backgroundColor: 'rgba(15, 23, 42, 0.9)', padding: 12, cornerRadius: 8 }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        beginAtZero: true,
                        max: suggestedMaxPcs,
                        title: { display: true, text: 'PCS', color: '#3b82f6', font: { weight: 'bold' } },
                        grid: { color: '#f1f5f9' },
                        ticks: { precision: 0 }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        beginAtZero: true,
                        max: suggestedMaxTon,
                        title: { display: true, text: 'Tonase (Ton)', color: '#f97316', font: { weight: 'bold' } },
                        grid: { drawOnChartArea: false }
                    },
                    x: {
                        grid: { display: false },
                        ticks: {
                            maxRotation: 0,
                            autoSkip: false,
                            font: { size: 10 }
                        }
                    }
                }
            }
        });

        // 2. Donut Charts
        const donutColors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#6366f1'];

        // Chart By Type
        new Chart(document.getElementById('chartByType'), {
            type: 'doughnut',
            data: {
                labels: @json($chartByType['labels']),
                datasets: [{
                    data: @json($chartByType['data']),
                    backgroundColor: donutColors,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right', labels: { boxWidth: 10, padding: 15, font: { size: 11 } } }
                },
                layout: { padding: 20 },
                cutout: '65%'
            }
        });

        // Chart By Dept
        new Chart(document.getElementById('chartByDept'), {
            type: 'doughnut',
            data: {
                labels: @json($chartByDept['labels']),
                datasets: [{
                    data: @json($chartByDept['data']),
                    backgroundColor: donutColors,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right', labels: { boxWidth: 10, padding: 15, font: { size: 11 } } }
                },
                layout: { padding: 20 },
                cutout: '65%'
            }
        });
    </script>
@endsection