@extends('layouts.app')

@section('top_bar')
    <div>
        <h1 class="text-lg font-bold text-slate-800 leading-tight">Input Harian (WIP)</h1>
        <p class="text-gray-500 text-[10px]">Pilih tanggal untuk manajemen berat Heat Number</p>
    </div>
@endsection

@section('content')
    <div class="space-y-4">
        @forelse($dailyStats as $stat)
            <a href="{{ route('wip.show', $stat->date) }}"
                class="block bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow-md hover:border-blue-300 transition-all group">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="flex items-center gap-3 mb-1">
                            <span class="text-slate-800 font-bold text-base group-hover:text-blue-600 transition-colors">
                                {{ \Carbon\Carbon::parse($stat->date)->translatedFormat('l, j F Y') }}
                            </span>
                        </div>
                        <div class="text-sm text-gray-500 mt-1 flex gap-4">
                            <span><i class="fas fa-layer-group text-blue-500 w-4"></i> {{ $stat->heat_count }} Heat
                                Number</span>
                            <span><i class="fas fa-clipboard-list text-gray-400 w-4"></i> {{ $stat->items_count }} Item</span>
                        </div>
                        <div class="text-sm text-gray-500 mt-1 flex gap-4">
                            <span><i class="fas fa-weight text-emerald-500 w-4"></i> Total Berat Jadi:
                                {{ number_format($stat->total_kg, 2) }} kg</span>
                        </div>
                    </div>
                    <div class="text-gray-300 group-hover:text-blue-400 transition-colors">
                        <i class="fas fa-chevron-right fa-lg"></i>
                    </div>
                </div>
            </a>
        @empty
            <div class="bg-white rounded-xl shadow-sm border border-dashed border-slate-300 p-12 text-center">
                <div class="bg-slate-50 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-info-circle text-slate-400 text-2xl"></i>
                </div>
                <h3 class="text-slate-600 font-bold">Belum ada data input harian</h3>
                <p class="text-slate-400 text-sm mt-1">Data akan muncul setelah ada input di departemen Cor.</p>
            </div>
        @endforelse
    </div>
@endsection