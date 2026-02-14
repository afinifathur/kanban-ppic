<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>FIFO Tracking - Production System</title>
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
    <script src="{{ asset('js/axios.min.js') }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            if (window.axios) {
                window.axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken;
                window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
            }
        });

        function toggleInputMenu() {
            const menu = document.getElementById('inputMenu');
            const icon = document.getElementById('inputMenuIcon');
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
    </style>
</head>

<body class="bg-gray-100 font-sans text-gray-900 flex h-screen overflow-hidden">

    <!-- Sidebar -->
    <aside class="w-64 bg-slate-900 text-white flex-shrink-0 flex flex-col">
        <div class="p-4 border-b border-slate-700">
            <h1 class="text-xl font-bold">FIFO Tracking</h1>
            <p class="text-xs text-slate-400 mb-4">Production System</p>

            <!-- User Profile in Sidebar -->
            <div class="flex items-center gap-3 p-2 bg-slate-800/50 rounded-lg border border-slate-700/50 group">
                <div
                    class="h-9 w-9 rounded-full bg-blue-600 flex items-center justify-center text-white text-sm font-bold shadow-inner shrink-0">
                    {{ substr(Auth::user()->name ?? 'U', 0, 1) }}
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-bold text-slate-100 truncate">
                        {{ explode(' ', Auth::user()->name)[0] }}
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] text-slate-400 uppercase tracking-wider font-semibold">Admin</span>
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
                <li>
                    <a href="{{ route('dashboard') }}"
                        class="flex items-center px-6 py-2 hover:bg-slate-800 {{ request()->routeIs('dashboard') ? 'bg-blue-600 text-white' : 'text-slate-300' }}">
                        <i class="fas fa-chart-line w-6"></i> Dashboard
                    </a>
                </li>

                <li>
                    <a href="{{ route('dashboard.defects') }}"
                        class="flex items-center px-6 py-2 hover:bg-slate-800 {{ request()->routeIs('dashboard.defects') ? 'bg-blue-600 text-white border-l-4 border-red-400' : 'text-slate-300' }}">
                        <i class="fas fa-chart-pie w-6"></i> Dashboard Kerusakan
                    </a>
                </li>

                <li class="px-6 pt-4 pb-2 text-xs font-semibold text-slate-500 uppercase">Kanban</li>

                <li>
                    <a href="{{ route('kanban.index', 'cor') }}"
                        class="flex items-center px-6 py-2 hover:bg-slate-800 {{ request()->is('kanban*') ? 'bg-blue-600 text-white border-l-4 border-blue-300' : 'text-slate-300' }}">
                        <i class="fas fa-columns w-6"></i> Kanban Board
                    </a>
                </li>

                <li>
                    <a href="{{ route('plan.index') }}"
                        class="flex items-center px-6 py-2 hover:bg-slate-800 {{ request()->routeIs('plan.index') ? 'bg-blue-600 text-white border-l-4 border-blue-300' : 'text-slate-300' }}">
                        <i class="fas fa-clipboard-list w-6"></i> Daftar Rencana
                    </a>
                </li>

                <li>
                    <button onclick="toggleInputMenu()"
                        class="w-full flex items-center justify-between px-6 py-3 hover:bg-slate-800 transition-colors focus:outline-none group">
                        <span class="text-xs font-semibold text-slate-500 uppercase group-hover:text-slate-300">Input
                            Departments</span>
                        <i id="inputMenuIcon"
                            class="fas fa-chevron-right text-xs text-slate-500 transition-transform duration-200 {{ request()->is('input*') ? 'rotate-90' : '' }}"></i>
                    </button>
                    <ul id="inputMenu"
                        class="{{ request()->is('input*') ? '' : 'hidden' }} space-y-1 bg-slate-800/30 pb-2">
                        @php
                            $deptIcons = [
                                'cor' => 'fa-fire',
                                'netto' => 'fa-cut',
                                'bubut_od' => 'fa-sync-alt',
                                'bubut_cnc' => 'fa-microchip',
                                'bor' => 'fa-screwdriver',
                                'finish' => 'fa-clipboard-check'
                            ];
                        @endphp
                        @foreach($deptIcons as $dept => $icon)
                            <li>
                                <a href="{{ route('input.index', $dept) }}"
                                    class="flex items-center pl-10 pr-6 py-2 hover:bg-slate-800 {{ request()->is('input/' . $dept) ? 'text-white font-medium border-l-2 border-blue-500' : 'text-slate-300' }}">
                                    <i class="fas {{ $icon }} w-4 mr-2 text-xs opacity-70"></i>
                                    <span class="text-sm">{{ ucfirst(str_replace('_', ' ', $dept)) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </li>

                <!-- Defect Input Section -->
                <li>
                    <button onclick="toggleDefectMenu()"
                        class="w-full flex items-center justify-between px-6 py-3 hover:bg-slate-800 transition-colors focus:outline-none group">
                        <span class="text-xs font-semibold text-slate-500 uppercase group-hover:text-slate-300">Input
                            Kerusakan</span>
                        <i id="defectMenuIcon"
                            class="fas fa-chevron-right text-xs text-slate-500 transition-transform duration-200 {{ request()->is('defects*') ? 'rotate-90' : '' }}"></i>
                    </button>
                    <ul id="defectMenu"
                        class="{{ request()->is('defects*') ? '' : 'hidden' }} space-y-1 bg-slate-800/30 pb-2">
                        @foreach($deptIcons as $dept => $icon)
                            @if($dept !== 'cor') {{-- Cor usually doesn't have defects entry in this flow --}}
                                <li>
                                    <a href="{{ route('defects.index', $dept) }}"
                                        class="flex items-center pl-10 pr-6 py-2 hover:bg-slate-800 {{ request()->is('defects/' . $dept) ? 'text-white font-medium border-l-2 border-red-500' : 'text-slate-300' }}">
                                        <i class="fas {{ $icon }} w-4 mr-2 text-xs opacity-70"></i>
                                        <span class="text-sm">{{ ucfirst(str_replace('_', ' ', $dept)) }}</span>
                                    </a>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </li>

                <li class="px-6 pt-4 pb-2 text-xs font-semibold text-slate-500 uppercase">Report</li>
                <li>
                    <a href="{{ route('report.index') }}"
                        class="flex items-center px-6 py-2 hover:bg-slate-800 {{ request()->routeIs('report.*') ? 'bg-blue-600 text-white border-l-4 border-blue-300' : 'text-slate-300' }}">
                        <i class="fas fa-print w-6"></i> Report SPK
                    </a>
                </li>
                <li>
                    <a href="{{ route('report-defects.index') }}"
                        class="flex items-center px-6 py-2 hover:bg-slate-800 {{ request()->routeIs('report-defects.index') ? 'bg-blue-600 text-white border-l-4 border-red-300' : 'text-slate-300' }}">
                        <i class="fas fa-file-invoice-dollar w-6"></i> Report Kerusakan
                    </a>
                </li>

                <li>
                    <a href="{{ route('report-defects.summary') }}"
                        class="flex items-center px-6 py-2 hover:bg-slate-800 {{ request()->routeIs('report-defects.summary') ? 'bg-blue-600 text-white border-l-4 border-orange-300' : 'text-slate-300' }}">
                        <i class="fas fa-file-alt w-6"></i> Rekap Kerusakan
                    </a>
                </li>

                <li>
                    <button onclick="toggleSettingsMenu()"
                        class="w-full flex items-center justify-between px-6 py-3 hover:bg-slate-800 transition-colors focus:outline-none group">
                        <span
                            class="text-xs font-semibold text-slate-500 uppercase group-hover:text-slate-300">Setting</span>
                        <i id="settingsMenuIcon"
                            class="fas fa-chevron-right text-xs text-slate-500 transition-transform duration-200 {{ request()->is('settings*') ? 'rotate-90' : '' }}"></i>
                    </button>
                    <ul id="settingsMenu"
                        class="{{ request()->is('settings*') ? '' : 'hidden' }} space-y-1 bg-slate-800/30 pb-2">
                        <li>
                            <a href="{{ route('settings.defect-types.index') }}"
                                class="flex items-center pl-10 pr-6 py-2 hover:bg-slate-800 {{ request()->routeIs('settings.defect-types.*') ? 'text-white font-medium border-l-2 border-blue-500' : 'text-slate-300' }}">
                                <i class="fas fa-exclamation-triangle w-4 mr-2 text-xs opacity-70"></i>
                                <span class="text-sm">Setting Kerusakan</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('settings.customers.index') }}"
                                class="flex items-center pl-10 pr-6 py-2 hover:bg-slate-800 {{ request()->routeIs('settings.customers.*') ? 'text-white font-medium border-l-2 border-blue-500' : 'text-slate-300' }}">
                                <i class="fas fa-users w-4 mr-2 text-xs opacity-70"></i>
                                <span class="text-sm">Setting Customer</span>
                            </a>
                        </li>
                    </ul>
                </li>

                <script>
                    function toggleDefectMenu() {
                        const menu = document.getElementById('defectMenu');
                        const icon = document.getElementById('defectMenuIcon');
                        menu.classList.toggle('hidden');
                        icon.classList.toggle('rotate-90');
                    }
                    function toggleSettingsMenu() {
                        const menu = document.getElementById('settingsMenu');
                        const icon = document.getElementById('settingsMenuIcon');
                        menu.classList.toggle('hidden');
                        icon.classList.toggle('rotate-90');
                    }
                </script>
            </ul>
        </nav>

        <div class="p-4 border-t border-slate-700 text-xs text-center text-slate-500">
            v1.0.0
        </div>
    </aside>

    <!-- Main Content -->
    <!-- Main Content -->
    <main class="flex-1 flex flex-col overflow-hidden bg-slate-50 relative">

        <!-- Top Navigation Bar -->
        <header
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
        <div class="flex-1 overflow-y-auto overflow-x-auto p-6 md:p-8 custom-scrollbar">
            @if(session('success'))
                <div
                    class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg flex items-center shadow-sm">
                    <i class="fas fa-check-circle mr-3 text-emerald-500"></i>
                    <span class="font-medium">{{ session('success') }}</span>
                </div>
            @endif

            @yield('content')
        </div>
    </main>

</body>

</html>