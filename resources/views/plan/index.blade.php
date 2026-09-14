@extends('layouts.app')

@section('top_bar')
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between w-full gap-2">
        <div>
            <h1 class="text-lg font-bold text-gray-800 leading-tight">Rencana Produksi</h1>
            <p class="text-gray-500 text-[10px]">Daftar rencana produksi (PPIC Planning) per tanggal & kelompok kerja</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('dashboard') }}" class="text-gray-500 hover:text-gray-700 text-xs">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <span class="text-gray-300">/</span>
            <span class="text-gray-700 text-xs font-bold">Rencana</span>
            @if(auth()->user()->hasRole('ppic') && auth()->user()->product_scope)
            <a href="{{ route('plan.create') }}"
                class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-1.5 px-3 rounded shadow text-xs flex items-center gap-2 ml-4">
                <i class="fas fa-plus"></i> Tambah Rencana Baru
            </a>
            @endif
        </div>
    </div>
@endsection

@section('content')
    <div class="space-y-4">
        <!-- Filter Bar -->
        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200">
            <form method="GET" action="{{ route('plan.index') }}" class="flex flex-wrap items-center justify-between gap-3">
                <!-- Search -->
                <div class="flex-1 min-w-[240px]">
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-400">
                            <i class="fas fa-search text-xs"></i>
                        </span>
                        <input type="text" name="search" value="{{ $search }}"
                            placeholder="Cari Judul, Item, Kode, Customer, No PO..."
                            class="w-full pl-9 pr-3 py-1.5 text-xs border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>

                <!-- Domain Filter Tabs / Select -->
                <div class="flex items-center gap-1.5">
                    <span class="text-xs font-semibold text-gray-600 mr-1">Domain:</span>
                    <a href="{{ route('plan.index', array_merge(request()->query(), ['production_domain' => 'ALL'])) }}"
                        class="px-2.5 py-1 text-xs font-bold rounded-md border transition-all {{ $selectedDomain === 'ALL' || !$selectedDomain ? 'bg-slate-800 text-white border-slate-800' : 'bg-gray-50 text-gray-600 border-gray-200 hover:bg-gray-100' }}">
                        ALL
                    </a>
                    <a href="{{ route('plan.index', array_merge(request()->query(), ['production_domain' => 'LOST_WAX'])) }}"
                        class="px-2.5 py-1 text-xs font-bold rounded-md border transition-all flex items-center gap-1 {{ $selectedDomain === 'LOST_WAX' ? 'bg-amber-500 text-white border-amber-600' : 'bg-amber-50 text-amber-800 border-amber-200 hover:bg-amber-100' }}">
                        <i class="fas fa-fire text-[10px]"></i> LOST WAX
                    </a>
                    <a href="{{ route('plan.index', array_merge(request()->query(), ['production_domain' => 'SAND_CASTING'])) }}"
                        class="px-2.5 py-1 text-xs font-bold rounded-md border transition-all flex items-center gap-1 {{ $selectedDomain === 'SAND_CASTING' ? 'bg-indigo-600 text-white border-indigo-700' : 'bg-indigo-50 text-indigo-800 border-indigo-200 hover:bg-indigo-100' }}">
                        <i class="fas fa-cubes text-[10px]"></i> SAND CASTING
                    </a>
                </div>

                <!-- Status Filter -->
                <div class="flex items-center gap-2">
                    <select name="status" onchange="this.form.submit()"
                        class="text-xs border-gray-300 rounded-md py-1.5 pl-2.5 pr-8 focus:ring-blue-500 focus:border-blue-500 bg-white">
                        <option value="">Semua Status</option>
                        <option value="planning" {{ $selectedStatus === 'planning' ? 'selected' : '' }}>Planning (Not Started)</option>
                        <option value="active" {{ $selectedStatus === 'active' ? 'selected' : '' }}>Active (In Progress)</option>
                        <option value="completed" {{ $selectedStatus === 'completed' ? 'selected' : '' }}>Completed</option>
                    </select>

                    <button type="submit" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold px-3 py-1.5 rounded-md text-xs border border-gray-300">
                        Filter
                    </button>

                    @if($search || ($selectedDomain && $selectedDomain !== 'ALL') || $selectedStatus)
                        <a href="{{ route('plan.index') }}" class="text-xs text-red-500 hover:text-red-700 hover:underline px-1">
                            Reset
                        </a>
                    @endif
                </div>
            </form>
        </div>

        <!-- Planning Groups List -->
        <div class="space-y-3">
            @forelse($dailyStats as $stat)
                @php
                    $agingDays = \Carbon\Carbon::parse($stat->date)->diffInDays(now()->startOfDay());
                    $agingColor = $agingDays < 7 ? 'blue' : ($agingDays < 14 ? 'yellow' : 'red');

                    // Determine domain presentation
                    $hasLostWax = ($stat->lost_wax_count ?? 0) > 0;
                    $hasSandCasting = ($stat->sand_casting_count ?? 0) > 0;
                    $primaryDomain = $stat->production_domain;
                @endphp
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-{{ $agingColor }}-500 hover:shadow-md transition-shadow">
                    <a href="{{ route('plan.index', array_filter(['date' => $stat->date, 'production_domain' => $selectedDomain !== 'ALL' ? $selectedDomain : null])) }}" class="block p-4">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-3">
                            <div class="flex-1 pr-4">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-lg font-bold text-gray-800 flex items-center gap-2">
                                        @if($stat->title)
                                            <i class="fas fa-tags text-blue-500 opacity-80"></i>
                                            {{ $stat->title }}
                                        @else
                                            <i class="far fa-calendar-alt text-gray-400"></i>
                                            {{ \Carbon\Carbon::parse($stat->date)->isoFormat('dddd, D MMMM Y') }}
                                        @endif
                                    </span>

                                    <!-- Domain Badge -->
                                    @if($hasLostWax && !$hasSandCasting)
                                        <span class="text-[11px] bg-amber-100 text-amber-900 border border-amber-300 font-bold px-2.5 py-0.5 rounded-md uppercase tracking-wider flex items-center gap-1">
                                            <i class="fas fa-fire text-amber-600 text-[10px]"></i> LOST WAX
                                        </span>
                                    @elseif($hasSandCasting && !$hasLostWax)
                                        <span class="text-[11px] bg-indigo-100 text-indigo-900 border border-indigo-300 font-bold px-2.5 py-0.5 rounded-md uppercase tracking-wider flex items-center gap-1">
                                            <i class="fas fa-cubes text-indigo-600 text-[10px]"></i> SAND CASTING
                                        </span>
                                    @elseif($hasLostWax && $hasSandCasting)
                                        <span class="text-[10px] bg-amber-50 text-amber-800 border border-amber-200 font-semibold px-2 py-0.5 rounded">LOST WAX: {{ $stat->lost_wax_count }}</span>
                                        <span class="text-[10px] bg-indigo-50 text-indigo-800 border border-indigo-200 font-semibold px-2 py-0.5 rounded">SAND CASTING: {{ $stat->sand_casting_count }}</span>
                                    @elseif($primaryDomain)
                                        <span class="text-[11px] bg-gray-100 text-gray-800 border border-gray-300 font-bold px-2.5 py-0.5 rounded-md uppercase">
                                            {{ str_replace('_', ' ', $primaryDomain) }}
                                        </span>
                                    @endif

                                    @if($agingDays > 0)
                                        <span class="text-[10px] bg-{{ $agingColor }}-100 text-{{ $agingColor }}-700 px-2 py-0.5 rounded-full font-bold uppercase border border-{{ $agingColor }}-200">
                                            {{ $agingDays }} Hari
                                        </span>
                                    @endif
                                </div>

                                <div class="text-xs text-gray-600 mt-2 flex flex-wrap gap-4">
                                    <span><i class="fas fa-clipboard-list text-blue-500 w-4"></i> Total Rencana:
                                        <strong class="text-gray-800">{{ number_format($stat->total_planned) }}</strong> pcs</span>
                                    <span><i class="fas fa-hourglass-half text-orange-500 w-4"></i> Sisa:
                                        <strong class="text-orange-600">{{ number_format($stat->total_remaining) }}</strong> pcs</span>
                                </div>

                                <div class="mt-2 text-xs flex items-center flex-wrap gap-3">
                                    <div class="text-blue-800 font-medium flex items-center gap-1 bg-blue-50 px-2 py-0.5 rounded border border-blue-100">
                                        <i class="fas fa-user-tie opacity-70"></i>
                                        {{ $stat->unique_customers ?: 'No Customer' }}
                                    </div>
                                    <div class="text-gray-500 flex items-center gap-1">
                                        <i class="fas fa-layer-group text-gray-400"></i> {{ $stat->items_count }} item dalam antrian
                                    </div>
                                    @if($stat->title)
                                        <div class="text-gray-400 text-[11px] font-medium flex items-center gap-1">
                                            <i class="far fa-calendar-alt opacity-70"></i>
                                            {{ \Carbon\Carbon::parse($stat->date)->isoFormat('dddd, D MMMM Y') }}
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-center gap-4 self-end md:self-center">
                                @php
                                    if ($stat->active_count > 0) {
                                        $overallStatus = 'In Progress';
                                        $statusClass = 'bg-blue-100 text-blue-700 border-blue-200';
                                        $statusIcon = 'fa-sync fa-spin';
                                    } elseif ($stat->completed_count > 0 && $stat->planning_count == 0 && $stat->active_count == 0) {
                                        $overallStatus = 'Completed';
                                        $statusClass = 'bg-green-100 text-green-700 border-green-200';
                                        $statusIcon = 'fa-check-double';
                                    } elseif ($stat->planning_count > 0) {
                                        $overallStatus = 'Not Started';
                                        $statusClass = 'bg-gray-100 text-gray-600 border-gray-200';
                                        $statusIcon = 'fa-clock';
                                    } else {
                                        $overallStatus = 'Unknown';
                                        $statusClass = 'bg-gray-100 text-gray-400 border-gray-200';
                                        $statusIcon = 'fa-question-circle';
                                    }
                                @endphp
                                <div class="{{ $statusClass }} px-3 py-1 rounded-full text-[10px] font-bold uppercase border flex items-center gap-1.5 shadow-sm">
                                    <i class="fas {{ $statusIcon }}"></i> {{ $overallStatus }}
                                </div>
                                <div class="text-gray-300">
                                    <i class="fas fa-chevron-right fa-lg"></i>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            @empty
                <div class="text-center py-16 bg-white rounded-lg shadow-sm border-2 border-dashed border-gray-200">
                    <div class="bg-gray-50 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-folder-open text-3xl text-gray-300"></i>
                    </div>
                    <h3 class="text-gray-800 font-bold text-base">Belum Ada Rencana Produksi</h3>
                    <p class="text-gray-500 text-xs mt-1">Silakan klik tombol "Tambah Rencana Baru" untuk memulai input PPIC.</p>
                </div>
            @endforelse
        </div>
    </div>
@endsection