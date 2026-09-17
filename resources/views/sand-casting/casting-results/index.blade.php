@extends('layouts.app')

@section('top_bar')
    <div class="flex flex-col sm:flex-row sm:items-center justify-between w-full gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-lg font-bold text-slate-800 leading-tight">Hasil Cor (Sand Casting)</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800 border border-amber-200">
                    Penuangan Logam (Heat)
                </span>
            </div>
            <p class="text-gray-500 text-[10px]">Daftar riwayat aktual penuangan metalurgi / Heat Number dan alokasi item Perintah Cor</p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('sand-casting.casting-orders.plans') }}" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold px-3 py-2 rounded-lg text-xs flex items-center gap-1.5 shadow-sm transition">
                <i class="fas fa-file-invoice"></i> Perintah Cor
            </a>
            <a href="{{ route('sand-casting.casting-results.create') }}" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-2 rounded-lg text-xs flex items-center gap-1.5 shadow-sm transition">
                <i class="fas fa-plus"></i> Input Hasil Cor
            </a>
        </div>
    </div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Flash Messages -->
    @if(session('success'))
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs px-4 py-3 rounded-lg flex items-center justify-between shadow-sm">
            <div class="flex items-center gap-2">
                <i class="fas fa-check-circle text-emerald-500 text-base"></i>
                <span>{{ session('success') }}</span>
            </div>
            <button type="button" onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-emerald-600 text-sm">&times;</button>
        </div>
    @endif

    <!-- Filter Card -->
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
        <form method="GET" action="{{ route('sand-casting.casting-results.index') }}" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3">
            <div>
                <label for="heat_number" class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1">Heat Number</label>
                <input type="text" name="heat_number" id="heat_number" value="{{ request('heat_number') }}" placeholder="Cari nomor heat..."
                       class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
            </div>

            <div>
                <label for="date" class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1">Tanggal Cor</label>
                <input type="date" name="date" id="date" value="{{ request('date') }}"
                       class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
            </div>

            <div>
                <label for="furnace" class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1">Furnace</label>
                <input type="text" name="furnace" id="furnace" value="{{ request('furnace') }}" placeholder="F-01, Tungku..."
                       class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
            </div>

            <div>
                <label for="shift" class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1">Shift</label>
                <select name="shift" id="shift" class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 focus:outline-none bg-white">
                    <option value="">Semua Shift</option>
                    <option value="1" {{ request('shift') == '1' ? 'selected' : '' }}>Shift 1</option>
                    <option value="2" {{ request('shift') == '2' ? 'selected' : '' }}>Shift 2</option>
                    <option value="3" {{ request('shift') == '3' ? 'selected' : '' }}>Shift 3</option>
                </select>
            </div>

            <div class="flex items-end gap-2">
                <button type="submit" class="flex-1 bg-slate-800 hover:bg-slate-900 text-white font-bold text-xs px-3 py-2 rounded-lg transition flex items-center justify-center gap-1.5 shadow-sm">
                    <i class="fas fa-search"></i> Filter
                </button>
                @if(request()->anyFilled(['heat_number', 'date', 'furnace', 'shift']))
                    <a href="{{ route('sand-casting.casting-results.index') }}" class="bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold text-xs px-3 py-2 rounded-lg transition flex items-center justify-center" title="Reset">
                        <i class="fas fa-undo"></i>
                    </a>
                @endif
            </div>
        </form>
    </div>

    <!-- Results Table Card -->
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-xs text-left">
                <thead class="bg-slate-50 text-slate-600 font-bold uppercase tracking-wider border-b border-slate-200">
                    <tr>
                        <th class="p-3.5 text-left">Heat Number</th>
                        <th class="p-3.5 text-center w-28">Tanggal</th>
                        <th class="p-3.5 text-center w-24">Furnace</th>
                        <th class="p-3.5 text-center w-20">Shift</th>
                        <th class="p-3.5 text-center w-24 font-bold text-emerald-700">Total Good</th>
                        <th class="p-3.5 text-center w-24 font-bold text-red-600">Total Reject</th>
                        <th class="p-3.5 text-center w-28 font-bold text-slate-800">Total Weight</th>
                        <th class="p-3.5 text-center w-24">Jumlah Line</th>
                        <th class="p-3.5 text-center w-28">Status Kitir</th>
                        <th class="p-3.5 text-left min-w-[140px]">Recorded By</th>
                        <th class="p-3.5 text-center w-24">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    @forelse($results as $result)
                        @php
                            $totalKitir = $result->lines->count();
                            $printedKitir = $result->lines->where('print_count', '>', 0)->count();
                            $totalPrint = $result->lines->sum('print_count');
                        @endphp
                        <tr class="hover:bg-slate-50/80 transition">
                            <td class="p-3.5">
                                <a href="{{ route('sand-casting.casting-results.show', $result) }}" class="font-mono font-bold text-blue-700 hover:text-blue-900 text-xs tracking-wider flex items-center gap-1.5">
                                    <i class="fas fa-fire text-amber-500 text-[10px]"></i>
                                    {{ $result->heat_number }}
                                </a>
                                @if($result->notes)
                                    <div class="text-[10px] text-slate-400 mt-0.5 truncate max-w-xs">{{ $result->notes }}</div>
                                @endif
                            </td>
                            <td class="p-3.5 text-center font-mono text-slate-600">
                                {{ $result->cast_date ? $result->cast_date->format('d/m/Y') : '-' }}
                            </td>
                            <td class="p-3.5 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-slate-100 text-slate-700">
                                    {{ $result->furnace ?: '-' }}
                                </span>
                            </td>
                            <td class="p-3.5 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-blue-50 text-blue-700">
                                    Shift {{ $result->shift ?: '-' }}
                                </span>
                            </td>
                            <td class="p-3.5 text-center font-mono font-bold text-emerald-700 text-sm">
                                {{ number_format($result->total_qty_good) }}
                            </td>
                            <td class="p-3.5 text-center font-mono font-bold text-red-600">
                                {{ number_format($result->total_qty_reject) }}
                            </td>
                            <td class="p-3.5 text-center font-mono font-bold text-slate-800">
                                {{ number_format($result->total_weight_kg, 2) }} kg
                            </td>
                            <td class="p-3.5 text-center font-mono">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-50 text-indigo-700">
                                    {{ $totalKitir }} line
                                </span>
                            </td>
                            <td class="p-3.5 text-center">
                                @if($totalKitir === 0)
                                    <span class="text-slate-400 font-mono text-[11px]">-</span>
                                @elseif($printedKitir === 0)
                                    <div class="flex flex-col items-center">
                                        <span class="font-mono text-xs font-bold text-slate-600">0 / {{ $totalKitir }}</span>
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold bg-slate-100 text-slate-500 border border-slate-200 mt-0.5">
                                            BELUM CETAK
                                        </span>
                                    </div>
                                @elseif($printedKitir < $totalKitir)
                                    <div class="flex flex-col items-center">
                                        <span class="font-mono text-xs font-bold text-amber-700">{{ $printedKitir }} / {{ $totalKitir }}</span>
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold bg-amber-50 text-amber-800 border border-amber-200 mt-0.5">
                                            BELUM LENGKAP
                                        </span>
                                        @if($totalPrint > $printedKitir)
                                            <span class="text-[8px] text-slate-400 mt-0.5 font-mono font-medium">Total: {{ $totalPrint }} print</span>
                                        @endif
                                    </div>
                                @else
                                    <div class="flex flex-col items-center">
                                        <span class="font-mono text-xs font-bold text-emerald-700">{{ $printedKitir }} / {{ $totalKitir }}</span>
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 mt-0.5">
                                            <i class="fas fa-check text-[8px] mr-1"></i> SUDAH CETAK
                                        </span>
                                        @if($totalPrint > $printedKitir)
                                            <span class="text-[8px] text-slate-400 mt-0.5 font-mono font-medium">Total: {{ $totalPrint }} print</span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td class="p-3.5 text-slate-600">
                                <div class="font-medium truncate max-w-[150px]">{{ $result->recorder->name ?? 'User #'.$result->recorded_by }}</div>
                                <div class="text-[10px] text-slate-400">{{ $result->created_at ? $result->created_at->format('H:i') : '' }}</div>
                            </td>
                            <td class="p-3.5 text-center">
                                <a href="{{ route('sand-casting.casting-results.show', $result) }}" class="inline-flex items-center gap-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold px-2.5 py-1.5 rounded-lg text-xs transition">
                                    <i class="fas fa-eye"></i> Detail
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="p-8 text-center text-slate-400">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <i class="fas fa-fire-extinguisher text-3xl text-slate-300"></i>
                                    <p class="font-medium text-slate-500">Belum ada riwayat Hasil Cor.</p>
                                    <a href="{{ route('sand-casting.casting-results.create') }}" class="text-indigo-600 hover:text-indigo-800 font-bold text-xs mt-1">
                                        + Input Hasil Cor Pertama
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($results->hasPages())
            <div class="p-4 border-t border-slate-100">
                {{ $results->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
