<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>FIFO Tracking - Production System</title>
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
    <script src="{{ asset('js/axios.min.js') }}"></script>
    <!-- Fonts -->
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            if (window.axios) {
                window.axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken;
                window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
            }

            // Sidebar Collapse Event Listener
            const sidebar = document.getElementById('app-sidebar');
            const toggleIcon = document.getElementById('sidebarToggleIcon');
            const toggleBtn = document.getElementById('sidebarToggle');

            if (sidebar && sidebar.classList.contains('sidebar-collapsed') && toggleIcon) {
                toggleIcon.classList.remove('fa-chevron-left');
                toggleIcon.classList.add('fa-chevron-right');
            }

            if (toggleBtn) {
                toggleBtn.addEventListener('click', function () {
                    const collapsed = sidebar.classList.toggle('sidebar-collapsed');
                    localStorage.setItem('sidebar-collapsed', collapsed ? 'true' : 'false');

                    if (toggleIcon) {
                        if (collapsed) {
                            toggleIcon.classList.remove('fa-chevron-left');
                            toggleIcon.classList.add('fa-chevron-right');
                        } else {
                            toggleIcon.classList.remove('fa-chevron-right');
                            toggleIcon.classList.add('fa-chevron-left');
                        }
                    }
                });
            }
        });

        function toggleCorPasirMenu() {
            const menu = document.getElementById('corPasirMenu');
            const icon = document.getElementById('corPasirMenuIcon');
            menu.classList.toggle('hidden');
            icon.classList.toggle('rotate-90');
        }

        function toggleLostWaxMenu() {
            const menu = document.getElementById('lostWaxMenu');
            const icon = document.getElementById('lostWaxMenuIcon');
            menu.classList.toggle('hidden');
            icon.classList.toggle('rotate-90');
        }
    </script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="{{ asset('css/all.min.css') }}">
    <!-- Handsontable -->
    <link rel="stylesheet" href="{{ asset('css/handsontable.full.min.css') }}">
    <script src="{{ asset('js/handsontable.full.min.js') }}"></script>
    <!-- SweetAlert2 -->
    <script src="{{ asset('js/sweetalert2.all.min.js') }}"></script>
    <style>
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            /* slate-300 */
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
            /* slate-400 */
        }

        /* Sidebar Collapse Styles */
        #app-sidebar {
            transition: width 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        #app-sidebar.sidebar-collapsed {
            width: 4rem !important; /* w-16 */
        }

        #app-sidebar.sidebar-collapsed .sidebar-text,
        #app-sidebar.sidebar-collapsed .sidebar-header,
        #app-sidebar.sidebar-collapsed nav button i.fa-chevron-right,
        #app-sidebar.sidebar-collapsed .sidebar-footer-text {
            display: none !important;
        }

        #app-sidebar.sidebar-collapsed nav ul ul {
            display: none !important;
        }

        #app-sidebar.sidebar-collapsed .sidebar-compact {
            display: block !important;
        }

        #app-sidebar.sidebar-collapsed .sidebar-link,
        #app-sidebar.sidebar-collapsed nav button {
            padding-left: 0 !important;
            padding-right: 0 !important;
            justify-content: center !important;
        }

        #app-sidebar.sidebar-collapsed .sidebar-link i,
        #app-sidebar.sidebar-collapsed nav button i:first-child {
            margin-right: 0 !important;
            width: 1.5rem !important;
            text-align: center;
        }
    </style>
</head>

<body id="app-body" class="bg-gray-100 font-sans text-gray-900 flex h-screen overflow-hidden">

    <!-- Sidebar -->
    <aside id="app-sidebar" class="relative w-64 bg-slate-900 text-white flex-shrink-0 flex flex-col">
        <script>
            if (localStorage.getItem('sidebar-collapsed') === 'true') {
                document.getElementById('app-sidebar').classList.add('sidebar-collapsed');
            }
        </script>
        <div class="p-4 border-b border-slate-700 relative">
            <div class="sidebar-text">
                <h1 class="text-xl font-bold">FIFO Tracking</h1>
                <p class="text-xs text-slate-400 mb-4">Production System</p>
            </div>
            <div class="sidebar-compact hidden text-center mb-4">
                <span class="text-xl font-bold text-blue-500">FT</span>
            </div>

            <!-- Toggle Button -->
            <button id="sidebarToggle" class="absolute -right-3 top-6 bg-slate-800 text-slate-400 hover:text-white border border-slate-700 rounded-full w-6 h-6 flex items-center justify-center focus:outline-none transition-all z-50 shadow-sm" title="Toggle Sidebar">
                <i class="fas fa-chevron-left text-[10px]" id="sidebarToggleIcon"></i>
            </button>

            <!-- User Profile in Sidebar -->
            <div class="flex items-center gap-3 p-2 bg-slate-800/50 rounded-lg border border-slate-700/50 group">
                <div
                    class="h-9 w-9 rounded-full bg-blue-600 flex items-center justify-center text-white text-sm font-bold shadow-inner shrink-0"
                    title="User Profile: {{ Auth::user()->name ?? 'User' }}">
                    {{ substr(Auth::user()->name ?? 'U', 0, 1) }}
                </div>
                <div class="flex-1 min-w-0 sidebar-text">
                    <div class="text-sm font-bold text-slate-100 truncate">
                        {{ explode(' ', Auth::user()->name)[0] }}
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] text-slate-400 uppercase tracking-wider font-semibold truncate" title="{{ Auth::user()->roles->pluck('name')->first() ?? 'User' }}{{ Auth::user()->product_scope ? ' - '.Auth::user()->product_scope : '' }}">
                            {{ Auth::user()->roles->pluck('name')->first() ?? 'User' }}{{ Auth::user()->product_scope ? ' - '.Auth::user()->product_scope : '' }}
                        </span>
                        <form action="{{ route('logout') }}" method="POST" class="inline">
                            @csrf
                            <button type="submit"
                                class="text-slate-500 hover:text-red-400 transition-colors text-xs p-1"
                                title="Sign Out">
                                <i class="fas fa-sign-out-alt"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <nav class="flex-1 overflow-y-auto py-4">
            <ul class="space-y-1">
                <!-- 1. DASHBOARD -->
                <li class="sidebar-header px-6 pt-4 pb-2 text-xs font-semibold text-slate-500 uppercase">
                    <span class="sidebar-text">Dashboard</span>
                </li>
                <li>
                    <a href="{{ route('dashboard') }}"
                        class="sidebar-link flex items-center px-6 py-2 hover:bg-slate-800 {{ request()->routeIs('dashboard') ? 'bg-blue-600 text-white' : 'text-slate-300' }}"
                        title="Dashboard">
                        <i class="fas fa-chart-line w-6 shrink-0 text-center"></i>
                        <span class="text-sm sidebar-text ml-2">Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('dashboard.defects') }}"
                        class="sidebar-link flex items-center px-6 py-2 hover:bg-slate-800 {{ request()->routeIs('dashboard.defects') ? 'bg-blue-600 text-white border-l-4 border-red-400' : 'text-slate-300' }}"
                        title="Dashboard Kerusakan">
                        <i class="fas fa-chart-pie w-6 shrink-0 text-center"></i>
                        <span class="text-sm sidebar-text ml-2">Dashboard Kerusakan</span>
                    </a>
                </li>

                <!-- 2. PLANNING -->
                @if(Auth::user() && (Auth::user()->roles->contains('name', 'admin') || Auth::user()->roles->contains('name', 'ppic')))
                <li class="sidebar-header px-6 pt-4 pb-2 text-xs font-semibold text-slate-500 uppercase">
                    <span class="sidebar-text">Planning</span>
                </li>
                <li>
                    <a href="{{ route('plan.index') }}"
                        class="sidebar-link flex items-center px-6 py-2 hover:bg-slate-800 {{ request()->routeIs('plan.index') ? 'bg-blue-600 text-white border-l-4 border-blue-300' : 'text-slate-300' }}"
                        title="Daftar Rencana">
                        <i class="fas fa-clipboard-list w-6 shrink-0 text-center"></i>
                        <span class="text-sm sidebar-text ml-2">Daftar Rencana</span>
                    </a>
                </li>
                @endif

                <!-- 3. PRODUKSI -->
                <li class="sidebar-header px-6 pt-4 pb-2 text-xs font-semibold text-slate-500 uppercase">
                    <span class="sidebar-text">Produksi</span>
                </li>

                <!-- 3A. COR PASIR -->
                @php
                    $isCorPasirActive = request()->is('kanban*') || (request()->is('input*') && request()->query('source') !== 'lost-wax');
                @endphp
                <li>
                    <button onclick="toggleCorPasirMenu()"
                        class="w-full flex items-center px-6 py-3 hover:bg-slate-800 transition-colors focus:outline-none group {{ $isCorPasirActive ? 'bg-slate-800/80 text-blue-400 border-l-4 border-blue-500 font-bold' : '' }}"
                        title="Cor Pasir">
                        <i class="fas fa-cubes w-6 shrink-0 text-center mr-2 {{ $isCorPasirActive ? 'text-blue-400' : 'text-slate-400 group-hover:text-slate-300' }}"></i>
                        <span class="text-xs font-semibold uppercase group-hover:text-slate-300 sidebar-text {{ $isCorPasirActive ? 'text-blue-400' : 'text-slate-555' }}">Cor Pasir</span>
                        <i id="corPasirMenuIcon"
                            class="fas fa-chevron-right text-xs text-slate-500 transition-transform duration-200 ml-auto {{ $isCorPasirActive ? 'rotate-90 text-blue-400' : '' }}"></i>
                    </button>
                    <ul id="corPasirMenu"
                        class="{{ $isCorPasirActive ? '' : 'hidden' }} space-y-1 bg-slate-800/30 pb-2">
                        <li>
                            <a href="{{ route('kanban.index', 'rencana_cor') }}"
                                class="sidebar-link flex items-center pl-10 pr-6 py-2 hover:bg-slate-800 {{ request()->is('kanban/rencana_cor') ? 'text-white font-medium border-l-2 border-blue-500' : 'text-slate-300' }}"
                                title="Rencana Cor">
                                <i class="fas fa-columns w-4 shrink-0 text-center text-xs opacity-70 mr-2"></i>
                                <span class="text-sm sidebar-text">Rencana Cor</span>
                            </a>
                        </li>
                        @php
                            $corPasirDepts = [
                                'cor' => 'fa-fire',
                                'netto' => 'fa-cut',
                                'bubut_od' => 'fa-sync-alt',
                                'bubut_cnc' => 'fa-microchip',
                                'bor' => 'fa-screwdriver',
                                'finish' => 'fa-clipboard-check'
                            ];
                        @endphp
                        @foreach($corPasirDepts as $dept => $icon)
                            <li>
                                <a href="{{ route('input.index', $dept) }}"
                                    class="sidebar-link flex items-center pl-10 pr-6 py-2 hover:bg-slate-800 {{ (request()->is('input/' . $dept) && request()->query('source') !== 'lost-wax') ? 'text-white font-medium border-l-2 border-blue-500' : 'text-slate-300' }}"
                                    title="{{ $dept === 'cor' ? 'Cor Pasir' : ucfirst(str_replace('_', ' ', $dept)) }}">
                                    <i class="fas {{ $icon }} w-4 shrink-0 text-center text-xs opacity-70 mr-2"></i>
                                    <span class="text-sm sidebar-text">{{ $dept === 'cor' ? 'Cor Pasir' : ucfirst(str_replace('_', ' ', $dept)) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </li>

                <!-- 3B. LOST WAX -->
                @php
                    $isLostWaxActive = request()->is('lost-wax*') || (request()->is('input*') && request()->query('source') === 'lost-wax');
                    $isLostWaxSidebarOpen = $isLostWaxActive && !request()->routeIs('lost-wax.production-status');
                @endphp
                <li>
                    <button onclick="toggleLostWaxMenu()"
                        class="w-full flex items-center px-6 py-3 hover:bg-slate-800 transition-colors focus:outline-none group {{ $isLostWaxSidebarOpen ? 'bg-slate-800/80 text-amber-400 border-l-4 border-amber-400 font-bold' : '' }}"
                        title="Lost Wax">
                        <i class="fas fa-layer-group w-6 shrink-0 text-center mr-2 {{ $isLostWaxSidebarOpen ? 'text-amber-400' : 'text-slate-400 group-hover:text-slate-300' }}"></i>
                        <span class="text-xs font-semibold uppercase group-hover:text-slate-300 sidebar-text {{ $isLostWaxSidebarOpen ? 'text-amber-400' : 'text-slate-555' }}">Lost Wax</span>
                        <i id="lostWaxMenuIcon"
                            class="fas fa-chevron-right text-xs text-slate-500 transition-transform duration-200 ml-auto {{ $isLostWaxSidebarOpen ? 'rotate-90 text-amber-400' : '' }}"></i>
                    </button>
                    <ul id="lostWaxMenu"
                        class="{{ $isLostWaxSidebarOpen ? '' : 'hidden' }} space-y-1 bg-slate-800/30 pb-2">
                        @if(Auth::user() && (Auth::user()->roles->contains('name', 'admin') || Auth::user()->roles->contains('name', 'ppic')))
                        <li>
                            <a href="{{ route('lost-wax.print-orders.plans') }}"
                                class="sidebar-link flex items-center pl-10 pr-6 py-2 hover:bg-slate-800 {{ request()->routeIs('lost-wax.print-orders.*') ? 'text-white font-medium border-l-2 border-amber-400' : 'text-slate-300' }}"
                                title="Perintah Cetak">
                                <i class="fas fa-print w-4 shrink-0 text-center text-xs opacity-70 mr-2"></i>
                                <span class="text-sm sidebar-text">Perintah Cetak</span>
                            </a>
                        </li>
                        @endif
                        @if(Auth::user() && (Auth::user()->roles->contains('name', 'admin') || Auth::user()->roles->contains('name', 'spv')))
                        <li>
                            <a href="{{ route('lost-wax.outcomes.index') }}"
                                class="sidebar-link flex items-center pl-10 pr-6 py-2 hover:bg-slate-800 {{ request()->routeIs('lost-wax.outcomes.*') ? 'text-white font-medium border-l-2 border-amber-400' : 'text-slate-300' }}"
                                title="Hasil Cetak">
                                <i class="fas fa-edit w-4 shrink-0 text-center text-xs opacity-70 mr-2"></i>
                                <span class="text-sm sidebar-text">Hasil Cetak</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('lost-wax.assemblies.index') }}"
                                class="sidebar-link flex items-center pl-10 pr-6 py-2 hover:bg-slate-800 {{ request()->routeIs('lost-wax.assemblies.*') ? 'text-white font-medium border-l-2 border-amber-400' : 'text-slate-300' }}"
                                title="Perintah Rangkai">
                                <i class="fas fa-link w-4 shrink-0 text-center text-xs opacity-70 mr-2"></i>
                                <span class="text-sm sidebar-text">Perintah Rangkai</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('lost-wax.trees.index') }}"
                                class="sidebar-link flex items-center pl-10 pr-6 py-2 hover:bg-slate-800 {{ request()->routeIs('lost-wax.trees.*') ? 'text-white font-medium border-l-2 border-amber-400' : 'text-slate-300' }}"
                                title="Rangkaian / Traveler">
                                <i class="fas fa-sitemap w-4 shrink-0 text-center text-xs opacity-70 mr-2"></i>
                                <span class="text-sm sidebar-text">Rangkaian / Traveler</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('lost-wax.scan.index') }}"
                                class="sidebar-link flex items-center pl-10 pr-6 py-2 hover:bg-slate-800 {{ request()->routeIs('lost-wax.scan.*') ? 'text-white font-medium border-l-2 border-amber-400' : 'text-slate-300' }}"
                                title="Scan Lapisan">
                                <i class="fas fa-qrcode w-4 shrink-0 text-center text-xs opacity-70 mr-2"></i>
                                <span class="text-sm sidebar-text">Scan Lapisan</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('lost-wax.scan-oven.index') }}"
                                class="sidebar-link flex items-center pl-10 pr-6 py-2 hover:bg-slate-800 {{ request()->routeIs('lost-wax.scan-oven.*') ? 'text-white font-medium border-l-2 border-amber-400' : 'text-slate-300' }}"
                                title="Scan Oven">
                                <i class="fas fa-fire-alt w-4 shrink-0 text-center text-xs opacity-70 mr-2"></i>
                                <span class="text-sm sidebar-text">Scan Oven</span>
                            </a>
                        </li>
                        @endif
                        @php
                            $lostWaxDepts = [
                                'cor' => 'fa-fire',
                                'netto' => 'fa-cut',
                                'bubut_od' => 'fa-sync-alt',
                                'bubut_cnc' => 'fa-microchip',
                                'finish' => 'fa-clipboard-check'
                            ];
                        @endphp
                        @foreach($lostWaxDepts as $dept => $icon)
                            <li>
                                <a href="{{ route('input.index', ['dept' => $dept, 'source' => 'lost-wax']) }}"
                                    class="sidebar-link flex items-center pl-10 pr-6 py-2 hover:bg-slate-800 {{ (request()->is('input/' . $dept) && request()->query('source') === 'lost-wax') ? 'text-white font-medium border-l-2 border-amber-400' : 'text-slate-300' }}"
                                    title="{{ $dept === 'cor' ? 'Cor Lost Wax' : ucfirst(str_replace('_', ' ', $dept)) }}">
                                    <i class="fas {{ $icon }} w-4 shrink-0 text-center text-xs opacity-70 mr-2"></i>
                                    <span class="text-sm sidebar-text">{{ $dept === 'cor' ? 'Cor Lost Wax' : ucfirst(str_replace('_', ' ', $dept)) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </li>

                <!-- 4. MONITORING -->
                <li class="sidebar-header px-6 pt-4 pb-2 text-xs font-semibold text-slate-500 uppercase">
                    <span class="sidebar-text">Monitoring</span>
                </li>
                <li>
                    <a href="{{ route('lost-wax.production-status') }}"
                        class="sidebar-link flex items-center px-6 py-2 hover:bg-slate-800 {{ request()->routeIs('lost-wax.production-status') ? 'bg-blue-600 text-white' : 'text-slate-300' }}"
                        title="Production Status">
                        <i class="fas fa-table w-6 shrink-0 text-center"></i>
                        <span class="text-sm sidebar-text ml-2">Production Status</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('lost-wax.rack-monitor.index') }}"
                        class="sidebar-link flex items-center px-6 py-2 hover:bg-slate-800 {{ request()->routeIs('lost-wax.rack-monitor.index') ? 'bg-blue-600 text-white' : 'text-slate-300' }}"
                        title="Rack Monitoring">
                        <i class="fas fa-desktop w-6 shrink-0 text-center"></i>
                        <span class="text-sm sidebar-text ml-2">Rack Monitoring</span>
                    </a>
                </li>

                <!-- 5. WIP -->
                <li class="sidebar-header px-6 pt-4 pb-2 text-xs font-semibold text-slate-500 uppercase">
                    <span class="sidebar-text">WIP</span>
                </li>
                <li>
                    <a href="{{ route('wip.index') }}"
                        class="sidebar-link flex items-center px-6 py-2 hover:bg-slate-800 {{ (request()->is('wip') || request()->is('wip/*')) ? 'bg-blue-600 text-white border-l-4 border-emerald-400' : 'text-slate-300' }}"
                        title="Input Harian (WIP)">
                        <i class="fas fa-layer-group w-6 shrink-0 text-center"></i>
                        <span class="text-sm sidebar-text ml-2">Input Harian (WIP)</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('wip.report') }}"
                        class="sidebar-link flex items-center px-6 py-2 border-l-4 border-transparent hover:bg-slate-800 {{ request()->routeIs('wip.report') ? 'bg-blue-600 text-white border-l-emerald-400' : 'text-slate-300' }}"
                        title="Report WIP">
                        <i class="fas fa-file-invoice w-6 shrink-0 text-center"></i>
                        <span class="text-sm sidebar-text ml-2">Report WIP</span>
                    </a>
                </li>
                <!-- Input Kerusakan -->
                <li>
                    <button onclick="toggleDefectMenu()"
                        class="w-full flex items-center px-6 py-3 hover:bg-slate-800 transition-colors focus:outline-none group {{ request()->is('defects*') ? 'bg-slate-800/80 text-red-400 border-l-4 border-red-500 font-bold' : '' }}"
                        title="Input Kerusakan">
                        <i class="fas fa-exclamation-circle w-6 shrink-0 text-center mr-2 {{ request()->is('defects*') ? 'text-red-400' : 'text-slate-400 group-hover:text-slate-300' }}"></i>
                        <span class="text-xs font-semibold uppercase group-hover:text-slate-300 sidebar-text {{ request()->is('defects*') ? 'text-red-400' : 'text-slate-555' }}">Input Kerusakan</span>
                        <i id="defectMenuIcon"
                            class="fas fa-chevron-right text-xs text-slate-500 transition-transform duration-200 ml-auto {{ request()->is('defects*') ? 'rotate-90 text-red-400' : '' }}"></i>
                    </button>
                    <ul id="defectMenu"
                        class="{{ request()->is('defects*') ? '' : 'hidden' }} space-y-1 bg-slate-800/30 pb-2">
                        @php
                            $defectPasirDepts = [
                                'netto' => 'fa-cut',
                                'bubut_od' => 'fa-sync-alt',
                                'bubut_cnc' => 'fa-microchip',
                                'bor' => 'fa-screwdriver',
                                'finish' => 'fa-clipboard-check'
                            ];
                        @endphp
                        @foreach($defectPasirDepts as $dept => $icon)
                            <li>
                                <a href="{{ route('defects.index', $dept) }}"
                                    class="sidebar-link flex items-center pl-10 pr-6 py-2 hover:bg-slate-800 {{ request()->is('defects/' . $dept) ? 'text-white font-medium border-l-2 border-red-500' : 'text-slate-300' }}"
                                    title="Kerusakan {{ ucfirst(str_replace('_', ' ', $dept)) }}">
                                    <i class="fas {{ $icon }} w-4 shrink-0 text-center text-xs opacity-70 mr-2"></i>
                                    <span class="text-sm sidebar-text">{{ ucfirst(str_replace('_', ' ', $dept)) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </li>

                <!-- 6. REPORT -->
                <li class="sidebar-header px-6 pt-4 pb-2 text-xs font-semibold text-slate-500 uppercase">
                    <span class="sidebar-text">Report</span>
                </li>
                <li>
                    <a href="{{ route('report.index') }}"
                        class="sidebar-link flex items-center px-6 py-2 hover:bg-slate-800 {{ request()->routeIs('report.*') ? 'bg-blue-600 text-white border-l-4 border-blue-300' : 'text-slate-300' }}"
                        title="Report SPK">
                        <i class="fas fa-print w-6 shrink-0 text-center"></i>
                        <span class="text-sm sidebar-text ml-2">Report SPK</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('report-defects.index') }}"
                        class="sidebar-link flex items-center px-6 py-2 hover:bg-slate-800 {{ request()->routeIs('report-defects.index') ? 'bg-blue-600 text-white border-l-4 border-red-300' : 'text-slate-300' }}"
                        title="Report Kerusakan">
                        <i class="fas fa-file-invoice-dollar w-6 shrink-0 text-center"></i>
                        <span class="text-sm sidebar-text ml-2">Report Kerusakan</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('report-defects.summary') }}"
                        class="sidebar-link flex items-center px-6 py-2 hover:bg-slate-800 {{ request()->routeIs('report-defects.summary') ? 'bg-blue-600 text-white border-l-4 border-orange-300' : 'text-slate-300' }}"
                        title="Rekap Kerusakan">
                        <i class="fas fa-file-alt w-6 shrink-0 text-center"></i>
                        <span class="text-sm sidebar-text ml-2">Rekap Kerusakan</span>
                    </a>
                </li>

                <!-- 7. SETTING -->
                <li class="sidebar-header px-6 pt-4 pb-2 text-xs font-semibold text-slate-500 uppercase">
                    <span class="sidebar-text">Setting</span>
                </li>
                <li>
                    <button onclick="toggleSettingsMenu()"
                        class="w-full flex items-center px-6 py-3 hover:bg-slate-800 transition-colors focus:outline-none group {{ request()->is('settings*') ? 'bg-slate-800/80 text-blue-400 border-l-4 border-blue-500 font-bold' : '' }}"
                        title="Setting">
                        <i class="fas fa-cog w-6 shrink-0 text-center mr-2 {{ request()->is('settings*') ? 'text-blue-400' : 'text-slate-400 group-hover:text-slate-300' }}"></i>
                        <span class="text-xs font-semibold uppercase group-hover:text-slate-300 sidebar-text {{ request()->is('settings*') ? 'text-blue-400' : 'text-slate-500' }}">Setting</span>
                        <i id="settingsMenuIcon"
                            class="fas fa-chevron-right text-xs text-slate-500 transition-transform duration-200 ml-auto {{ request()->is('settings*') ? 'rotate-90 text-blue-400' : '' }}"></i>
                    </button>
                    <ul id="settingsMenu"
                        class="{{ request()->is('settings*') ? '' : 'hidden' }} space-y-1 bg-slate-800/30 pb-2">
                        <li>
                            <a href="{{ route('settings.defect-types.index') }}"
                                class="sidebar-link flex items-center pl-10 pr-6 py-2 hover:bg-slate-800 {{ request()->routeIs('settings.defect-types.*') ? 'text-white font-medium border-l-2 border-blue-500' : 'text-slate-300' }}"
                                title="Setting Kerusakan">
                                <i class="fas fa-exclamation-triangle w-4 shrink-0 text-center text-xs opacity-70 mr-2"></i>
                                <span class="text-sm sidebar-text">Setting Kerusakan</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('settings.customers.index') }}"
                                class="sidebar-link flex items-center pl-10 pr-6 py-2 hover:bg-slate-800 {{ request()->routeIs('settings.customers.*') ? 'text-white font-medium border-l-2 border-blue-500' : 'text-slate-300' }}"
                                title="Setting Customer">
                                <i class="fas fa-users w-4 shrink-0 text-center text-xs opacity-70 mr-2"></i>
                                <span class="text-sm sidebar-text">Setting Customer</span>
                            </a>
                        </li>
                    </ul>
                </li>

                <script>
                    function handleCollapsedClick(menuId, iconId) {
                        const sidebar = document.getElementById('app-sidebar');
                        const menu = document.getElementById(menuId);
                        const icon = document.getElementById(iconId);

                        if (sidebar && sidebar.classList.contains('sidebar-collapsed')) {
                            sidebar.classList.remove('sidebar-collapsed');
                            const toggleIcon = document.getElementById('sidebarToggleIcon');
                            if (toggleIcon) {
                                toggleIcon.classList.remove('fa-chevron-right');
                                toggleIcon.classList.add('fa-chevron-left');
                            }
                            menu.classList.remove('hidden');
                            icon.classList.add('rotate-90');
                            return true; // Click handled for collapsed state
                        }
                        return false;
                    }

                    function toggleCorPasirMenu() {
                        if (handleCollapsedClick('corPasirMenu', 'corPasirMenuIcon')) return;
                        const menu = document.getElementById('corPasirMenu');
                        const icon = document.getElementById('corPasirMenuIcon');
                        menu.classList.toggle('hidden');
                        icon.classList.toggle('rotate-90');
                    }
                    function toggleDefectMenu() {
                        if (handleCollapsedClick('defectMenu', 'defectMenuIcon')) return;
                        const menu = document.getElementById('defectMenu');
                        const icon = document.getElementById('defectMenuIcon');
                        menu.classList.toggle('hidden');
                        icon.classList.toggle('rotate-90');
                    }
                    function toggleLostWaxMenu() {
                        if (handleCollapsedClick('lostWaxMenu', 'lostWaxMenuIcon')) return;
                        const menu = document.getElementById('lostWaxMenu');
                        const icon = document.getElementById('lostWaxMenuIcon');
                        menu.classList.toggle('hidden');
                        icon.classList.toggle('rotate-90');
                    }
                    function toggleSettingsMenu() {
                        if (handleCollapsedClick('settingsMenu', 'settingsMenuIcon')) return;
                        const menu = document.getElementById('settingsMenu');
                        const icon = document.getElementById('settingsMenuIcon');
                        menu.classList.toggle('hidden');
                        icon.classList.toggle('rotate-90');
                    }
                </script>
            </ul>
        </nav>

        <div class="p-4 border-t border-slate-700 text-xs text-center text-slate-500">
            <span class="sidebar-footer-text">v1.0.0</span>
        </div>
    </aside>

    <!-- Main Content -->
    <!-- Main Content -->
    <main id="app-main" class="flex-1 flex flex-col overflow-hidden bg-slate-50 relative">

        <!-- Top Navigation Bar -->
        <header id="app-main-header"
            class="bg-white border-b border-gray-200 h-16 flex items-center justify-between px-4 shadow-sm z-20 shrink-0">
            <!-- Dynamic Top Bar Content (Process Flow) -->
            <div class="flex-1 flex items-center overflow-x-auto no-scrollbar gap-2">
                @yield('top_bar')
            </div>

            <!-- Right Side: Actions -->
            <div class="flex items-center gap-3 shrink-0 ml-2">
                @if(request()->routeIs('kanban.index'))
                    <button onclick="openReorderModal()"
                        class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold py-1.5 px-3 rounded shadow-sm flex items-center gap-2 transition-all">
                        <i class="fas fa-sort"></i>
                        <span class="hidden sm:inline">Edit Antrian</span>
                    </button>
                @endif
            </div>
        </header>

        <!-- Scrollable Content -->
        <div id="app-main-content" class="flex-1 overflow-y-auto overflow-x-auto p-6 md:p-8 custom-scrollbar">
            @if(session('success'))
                <div
                    class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg flex items-center shadow-sm">
                    <i class="fas fa-check-circle mr-3 text-emerald-500"></i>
                    <span class="font-medium">{{ session('success') }}</span>
                </div>
            @endif

            @if(session('error'))
                <div
                    class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg flex items-center shadow-sm">
                    <i class="fas fa-exclamation-circle mr-3 text-red-500"></i>
                    <span class="font-medium">{{ session('error') }}</span>
                </div>
            @endif

            @yield('content')
        </div>
    </main>

</body>

</html>
