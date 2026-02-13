@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-gray-800 leading-tight">Index Input {{ ucfirst($dept) }}</h1>
            <p class="text-gray-500 text-[10px]">Daftar input harian per tanggal</p>
        </div>
        <a href="{{ route('input.create', $dept) }}"
            class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-1.5 px-3 rounded shadow text-xs flex items-center gap-2">
            <i class="fas fa-plus"></i> Bulk Import Baru
        </a>
    </div>
@endsection

@section('content')
    <div class="p-0">

        <div class="space-y-4">
            @forelse($dailyStats as $stat)
                <div class="bg-white rounded-lg shadow p-4 border-l-4 border-blue-500 hover:shadow-md transition-shadow">
                    <a href="{{ route('input.show', ['dept' => $dept, 'date' => $stat->date]) }}" class="block">
                        <div class="flex justify-between items-center">
                            <div>
                                <div class="text-lg font-bold text-gray-800 flex items-center gap-2">
                                    <i class="far fa-calendar-alt text-gray-400"></i>
                                    {{ \Carbon\Carbon::parse($stat->date)->isoFormat('dddd, D MMMM Y') }}
                                </div>
                                <div class="text-sm text-gray-500 mt-1 flex gap-4">
                                    <span><i class="fas fa-box text-blue-500"></i> Total: {{ $stat->total_pcs }} pcs</span>
                                    <span><i class="fas fa-weight-hanging text-green-500"></i> Total: {{ $stat->total_kg }}
                                        kg</span>
                                </div>
                                <div class="text-xs text-gray-400 mt-1">
                                    {{ $stat->items_count }} item diinput
                                </div>
                            </div>
                            <div class="text-gray-400">
                                <i class="fas fa-chevron-right"></i>
                            </div>
                        </div>
                    </a>
                </div>
            @empty
                <div class="text-center py-10 text-gray-500 bg-white rounded-lg shadow">
                    <i class="fas fa-folder-open text-4xl mb-3 text-gray-300"></i>
                    <p>Belum ada data input untuk departemen {{ ucfirst($dept) }}.</p>
                </div>
            @endforelse
        </div>
    </div>
@endsection