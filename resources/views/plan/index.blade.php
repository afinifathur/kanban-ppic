@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-gray-800 leading-tight">Index Rencana Produksi</h1>
            <p class="text-gray-500 text-[10px]">Daftar rencana cor per tanggal input</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('dashboard') }}" class="text-gray-500 hover:text-gray-700 text-xs">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <span class="text-gray-300">/</span>
            <span class="text-gray-500 text-xs text-bold">Rencana</span>
            <a href="{{ route('plan.create') }}"
                class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-1.5 px-3 rounded shadow text-xs flex items-center gap-2 ml-4">
                <i class="fas fa-plus"></i> Tambah Rencana Baru
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="p-0">
        <div class="space-y-4">
            @forelse($dailyStats as $stat)
                @php
                    $agingDays = \Carbon\Carbon::parse($stat->date)->diffInDays(now()->startOfDay());
                    $agingColor = $agingDays < 7 ? 'blue' : ($agingDays < 14 ? 'yellow' : 'red');
                @endphp
                <div
                    class="bg-white rounded-lg shadow p-4 border-l-4 border-{{ $agingColor }}-500 hover:shadow-md transition-shadow">
                    <a href="{{ route('plan.index', ['date' => $stat->date]) }}" class="block">
                        <div class="flex justify-between items-center">
                            <div>
                                <div class="text-lg font-bold text-gray-800 flex items-center gap-2">
                                    <i class="far fa-calendar-alt text-gray-400"></i>
                                    {{ \Carbon\Carbon::parse($stat->date)->isoFormat('dddd, D MMMM Y') }}
                                    @if($agingDays > 0)
                                        <span
                                            class="text-[10px] bg-{{ $agingColor }}-100 text-{{ $agingColor }}-700 px-2 py-0.5 rounded-full font-bold uppercase border border-{{ $agingColor }}-200">
                                            {{ $agingDays }} Hari
                                        </span>
                                    @endif
                                </div>
                                <div class="text-sm text-gray-500 mt-1 flex gap-4">
                                    <span><i class="fas fa-clipboard-list text-blue-500 w-4"></i> Total Rencana:
                                        {{ number_format($stat->total_planned) }} pcs</span>
                                    <span><i class="fas fa-hourglass-half text-orange-500 w-4"></i> Sisa:
                                        {{ number_format($stat->total_remaining) }} pcs</span>
                                </div>
                                <div class="mt-2 text-xs flex items-center gap-4">
                                    <div
                                        class="text-blue-800 font-medium flex items-center gap-1 bg-blue-50 px-2 py-0.5 rounded-full border border-blue-100 w-fit">
                                        <i class="fas fa-user-tie opacity-70"></i>
                                        {{ $stat->unique_customers ?: 'No Customer' }}
                                    </div>
                                    <div class="text-gray-400 flex items-center gap-1">
                                        <i class="fas fa-layer-group text-gray-300"></i> {{ $stat->items_count }} item dalam
                                        antrian
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-4">
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
                                <div
                                    class="{{ $statusClass }} px-3 py-1 rounded-full text-[10px] font-bold uppercase border flex items-center gap-1.5 shadow-sm">
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
                <div class="text-center py-20 bg-white rounded-lg shadow border-2 border-dashed border-gray-100">
                    <div class="bg-gray-50 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-folder-open text-3xl text-gray-300"></i>
                    </div>
                    <h3 class="text-gray-800 font-bold">Belum Ada Rencana</h3>
                    <p class="text-gray-500 text-sm mt-1">Silakan klik tombol "Tambah Rencana" untuk memulai.</p>
                </div>
            @endforelse
        </div>
    </div>
@endsection