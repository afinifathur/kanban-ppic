@extends('layouts.app')

@section('top_bar')
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between w-full gap-2">
        <div>
            <h1 class="text-lg font-bold text-gray-800 leading-tight">Production Plan Control Tower</h1>
            <p class="text-gray-500 text-[10px]">Monitoring pencapaian target rencana produksi PPIC terhadap eksekusi awal domain</p>
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
                        <option value="NOT_STARTED" {{ in_array($selectedStatus, ['NOT_STARTED', 'planning']) ? 'selected' : '' }}>Not Started (0%)</option>
                        <option value="IN_PROGRESS" {{ in_array($selectedStatus, ['IN_PROGRESS', 'active']) ? 'selected' : '' }}>In Progress (1% - 99%)</option>
                        <option value="COMPLETED" {{ in_array($selectedStatus, ['COMPLETED', 'completed']) ? 'selected' : '' }}>Completed (100%+)</option>
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

        <!-- Planning Groups (Control Tower Cards) -->
        <div class="space-y-3">
            @forelse($dailyStats as $stat)
                @php
                    $detailParams = array_filter([
                        'date' => $stat->plan_date,
                        'title' => $stat->title,
                        'production_domain' => $stat->production_domain,
                        'product_scope' => $stat->product_scope,
                    ]);
                @endphp
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-{{ $stat->aging_color }}-500 hover:shadow-md transition-shadow group">
                    <a href="{{ route('plan.index', $detailParams) }}" class="block p-4">
                        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-center">
                            
                            <!-- ZONE 1: IDENTITY (~30% / 4 cols) -->
                            <div class="lg:col-span-4 border-b lg:border-b-0 lg:border-r border-gray-100 pb-3 lg:pb-0 lg:pr-4 space-y-1.5">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    @if($stat->production_domain === 'LOST_WAX')
                                        <span class="text-[10px] bg-amber-100 text-amber-900 border border-amber-300 font-bold px-2 py-0.5 rounded uppercase tracking-wider flex items-center gap-1">
                                            <i class="fas fa-fire text-amber-600 text-[9px]"></i> LOST WAX
                                        </span>
                                    @elseif($stat->production_domain === 'SAND_CASTING')
                                        <span class="text-[10px] bg-indigo-100 text-indigo-900 border border-indigo-300 font-bold px-2 py-0.5 rounded uppercase tracking-wider flex items-center gap-1">
                                            <i class="fas fa-cubes text-indigo-600 text-[9px]"></i> SAND CASTING
                                        </span>
                                    @endif

                                    @if($stat->product_scope)
                                        <span class="text-[10px] bg-slate-100 text-slate-700 border border-slate-200 font-semibold px-2 py-0.5 rounded">
                                            {{ str_replace('_', ' ', $stat->product_scope) }}
                                        </span>
                                    @endif

                                    @if($stat->aging_days > 0)
                                        <span class="text-[10px] bg-{{ $stat->aging_color }}-50 text-{{ $stat->aging_color }}-700 px-1.5 py-0.5 rounded font-bold border border-{{ $stat->aging_color }}-200">
                                            {{ $stat->aging_days }} Hari
                                        </span>
                                    @endif
                                </div>

                                <div class="text-base font-bold text-gray-800 group-hover:text-blue-600 transition-colors flex items-center gap-1.5">
                                    <i class="fas fa-clipboard-list text-blue-500 opacity-80 text-xs"></i>
                                    <span>{{ $stat->title ?: 'Rencana Kerja' }}</span>
                                </div>

                                <div class="text-xs text-gray-500 flex flex-wrap items-center gap-2 pt-0.5">
                                    <span class="flex items-center gap-1 text-gray-600">
                                        <i class="far fa-calendar-alt text-gray-400"></i>
                                        {{ \Carbon\Carbon::parse($stat->plan_date)->isoFormat('dddd, D MMMM Y') }}
                                    </span>
                                    <span class="text-gray-300">•</span>
                                    <span class="flex items-center gap-1 text-blue-700 bg-blue-50 px-1.5 py-0.5 rounded border border-blue-100 text-[11px] font-medium">
                                        <i class="fas fa-user-tie text-[10px] opacity-70"></i>
                                        {{ $stat->unique_customers ?: 'Tanpa Customer' }}
                                    </span>
                                    <span class="text-gray-300">•</span>
                                    <span class="text-gray-500 text-[11px]">
                                        {{ $stat->total_items }} item
                                    </span>
                                </div>
                            </div>

                            <!-- ZONE 2: EXECUTION PROGRESS (~50% / 6 cols) -->
                            <div class="lg:col-span-6 px-0 lg:px-4 py-1 space-y-2">
                                <div class="flex items-baseline justify-between">
                                    <div>
                                        <span class="text-xl font-extrabold text-gray-900 tracking-tight">{{ number_format($stat->total_actual_good) }}</span>
                                        <span class="text-xs text-gray-500 font-semibold">/ {{ number_format($stat->total_planned) }} pcs</span>
                                    </div>
                                    <div class="flex items-baseline gap-1">
                                        <span class="text-xs text-gray-400 font-medium">Target:</span>
                                        <span class="text-sm font-extrabold {{ $stat->is_over_target ? 'text-emerald-600' : 'text-blue-600' }}">
                                            {{ $stat->achievement_percentage }}%
                                        </span>
                                    </div>
                                </div>

                                <!-- Progress Bar -->
                                <div class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden shadow-inner">
                                    <div class="h-2.5 rounded-full transition-all duration-500 {{ $stat->execution_status === 'COMPLETED' ? 'bg-emerald-500' : ($stat->execution_status === 'IN_PROGRESS' ? 'bg-blue-600' : 'bg-gray-400') }}"
                                        style="width: {{ $stat->progress_percentage }}%"></div>
                                </div>

                                <!-- Progress Subtitle Details -->
                                <div class="flex items-center justify-between text-[11px] pt-0.5">
                                    <span class="text-gray-500 font-medium flex items-center gap-1">
                                        <i class="fas {{ $stat->production_domain === 'SAND_CASTING' ? 'fa-fire-alt text-amber-500' : 'fa-print text-blue-500' }} text-[10px]"></i>
                                        {{ $stat->production_domain === 'SAND_CASTING' ? 'Good Hasil Cor' : 'Good Hasil Cetak' }}
                                    </span>

                                    @if($stat->is_over_target)
                                        <span class="font-bold text-emerald-800 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
                                            +{{ number_format($stat->over_target_qty) }} pcs Over Target
                                        </span>
                                    @else
                                        <span class="text-gray-600">
                                            Sisa target: <strong class="text-gray-800">{{ number_format($stat->remaining_qty) }}</strong> pcs
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <!-- ZONE 3: STATUS & ACTION (~20% / 2 cols) -->
                            <div class="lg:col-span-2 flex lg:flex-col items-center lg:items-end justify-between lg:justify-center gap-2.5 border-t lg:border-t-0 pt-2 lg:pt-0">
                                <div class="{{ $stat->status_class }} px-3 py-1 rounded-full text-[10px] font-bold uppercase border flex items-center gap-1.5 shadow-sm">
                                    <i class="fas {{ $stat->status_icon }}"></i>
                                    <span>{{ $stat->status_label }}</span>
                                </div>

                                <span class="text-xs font-bold text-blue-600 group-hover:text-blue-800 flex items-center gap-1 transition-colors">
                                    Buka Detail
                                    <i class="fas fa-arrow-right text-[10px] transition-transform group-hover:translate-x-1"></i>
                                </span>
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