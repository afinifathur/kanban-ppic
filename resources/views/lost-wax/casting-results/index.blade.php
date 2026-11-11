@extends('layouts.app')

@section('title', 'Riwayat Hasil Cor — Lost Wax')

@section('content')
<div class="space-y-5">
    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-3 border-b border-slate-200">
        <div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                    <i class="fas fa-fire mr-1.5 text-xs text-amber-500"></i> Lost Wax Pengecoran
                </span>
                <span class="text-slate-300 text-xs">•</span>
                <span class="text-slate-500 text-xs font-medium">Phase 4 — Input Hasil Cor</span>
            </div>
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight mt-1.5">Riwayat Hasil Cor</h1>
            <p class="text-xs text-slate-500 mt-0.5">Daftar transaksi penuangan Heat Number dan Traveler Card yang telah dicor.</p>
        </div>
        <div class="flex items-center gap-2.5">
            <a href="{{ route('lost-wax.reports.ready-for-casting.index') }}"
               class="inline-flex items-center px-3.5 py-2 rounded-lg text-xs font-semibold bg-slate-800 hover:bg-slate-900 text-white transition-colors shadow-sm">
                <i class="fas fa-clipboard-check mr-2 text-amber-400"></i> Antrean Siap Cor
            </a>
            <a href="{{ route('lost-wax.casting-results.create') }}"
               class="inline-flex items-center px-4 py-2 rounded-lg text-xs font-bold bg-amber-500 hover:bg-amber-600 text-white transition-colors shadow-sm">
                <i class="fas fa-plus mr-1.5 text-xs"></i> Input Hasil Cor Baru
            </a>
        </div>
    </div>

    <!-- KPI Summary Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <!-- 1. Total Transaksi -->
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Transaksi</span>
                <span class="w-8 h-8 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center text-xs">
                    <i class="fas fa-list"></i>
                </span>
            </div>
            <div class="mt-2.5 flex items-baseline gap-2">
                <span class="text-2xl font-black text-slate-900 tracking-tight">{{ number_format($summary['total_transactions']) }}</span>
                <span class="text-xs text-slate-500 font-medium">Batch</span>
            </div>
            <div class="text-[11px] text-slate-400 mt-1">Keseluruhan batch penuangan</div>
        </div>

        <!-- 2. Heat Aktif (Selesai) -->
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Heat Aktif (Selesai)</span>
                <span class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-xs">
                    <i class="fas fa-check-circle"></i>
                </span>
            </div>
            <div class="mt-2.5 flex items-baseline gap-2">
                <span class="text-2xl font-black text-emerald-600 tracking-tight">{{ number_format($summary['total_completed']) }}</span>
                <span class="text-xs text-slate-500 font-medium">Heat</span>
            </div>
            <div class="text-[11px] text-slate-400 mt-1">
                @if($summary['total_cancelled'] > 0)
                    <span class="text-rose-500 font-medium">{{ number_format($summary['total_cancelled']) }} batch dibatalkan</span>
                @else
                    Semua transaksi aktif
                @endif
            </div>
        </div>

        <!-- 3. Total Pohon Dicor -->
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Pohon Dicor</span>
                <span class="w-8 h-8 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center text-xs">
                    <i class="fas fa-tree"></i>
                </span>
            </div>
            <div class="mt-2.5 flex items-baseline gap-2">
                <span class="text-2xl font-black text-amber-600 tracking-tight">{{ number_format($summary['total_trees']) }}</span>
                <span class="text-xs text-slate-500 font-medium">Pohon</span>
            </div>
            <div class="text-[11px] text-slate-400 mt-1">Traveler Card aktif terkonsumsi</div>
        </div>

        <!-- 4. Total Kuantitas Dicor -->
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Kuantitas Dicor</span>
                <span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-xs">
                    <i class="fas fa-cubes"></i>
                </span>
            </div>
            <div class="mt-2.5 flex items-baseline gap-2">
                <span class="text-2xl font-black text-slate-900 tracking-tight">{{ number_format($summary['total_pcs']) }}</span>
                <span class="text-xs text-amber-600 font-bold">PCS</span>
            </div>
            <div class="text-[11px] text-slate-400 mt-1">Akumulasi kuantitas cor layak</div>
        </div>
    </div>

    <!-- Alert Success -->
    @if(session('success'))
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-xl flex items-center gap-3 shadow-sm">
        <i class="fas fa-check-circle text-emerald-500 text-base shrink-0"></i>
        <div class="text-xs font-medium leading-relaxed">{{ session('success') }}</div>
    </div>
    @endif

    <!-- Filter & Search Panel -->
    <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <form method="GET" action="{{ route('lost-wax.casting-results.index') }}" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
            <!-- Search Keyword -->
            <div class="md:col-span-4">
                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Cari Transaksi / Heat</label>
                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                    <input type="text"
                           name="search"
                           value="{{ $search }}"
                           placeholder="Heat Number, Barcode, Kode..."
                           class="w-full bg-white border border-slate-300 rounded-lg pl-9 pr-3 py-2 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500 transition-colors">
                </div>
            </div>

            <!-- Status Filter -->
            <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Status Transaksi</label>
                <select name="status" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500 transition-colors">
                    <option value="ALL" {{ $status === 'ALL' ? 'selected' : '' }}>Semua Status</option>
                    <option value="COMPLETED" {{ $status === 'COMPLETED' ? 'selected' : '' }}>COMPLETED (Selesai)</option>
                    <option value="CANCELLED" {{ $status === 'CANCELLED' ? 'selected' : '' }}>CANCELLED (Dibatalkan)</option>
                </select>
            </div>

            <!-- Date From -->
            <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Dari Tanggal</label>
                <input type="date"
                       name="date_from"
                       value="{{ $dateFrom }}"
                       class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500 transition-colors">
            </div>

            <!-- Date To -->
            <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Sampai Tanggal</label>
                <input type="date"
                       name="date_to"
                       value="{{ $dateTo }}"
                       class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500 transition-colors">
            </div>

            <!-- Action Buttons -->
            <div class="md:col-span-2 flex items-center gap-2">
                <button type="submit" class="flex-1 bg-amber-500 hover:bg-amber-600 text-white font-bold px-3 py-2 rounded-lg text-xs transition-colors shadow-sm flex items-center justify-center gap-1.5">
                    <i class="fas fa-filter text-xs"></i> Filter
                </button>
                <a href="{{ route('lost-wax.casting-results.index') }}" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 py-2 rounded-lg text-xs font-semibold border border-slate-200 transition-colors" title="Reset Filter">
                    <i class="fas fa-undo"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Table of Results -->
    <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-50 text-slate-600 uppercase text-[10px] font-bold tracking-wider border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-3 text-center w-12">No</th>
                        <th class="px-4 py-3">No. Transaksi</th>
                        <th class="px-4 py-3">Tgl Cor</th>
                        <th class="px-4 py-3">Heat Number</th>
                        <th class="px-4 py-3">Tungku / Shift</th>
                        <th class="px-4 py-3 text-right">Pohon</th>
                        <th class="px-4 py-3 text-right">Qty (PCS)</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3">Dibuat Oleh</th>
                        <th class="px-4 py-3 text-center w-28">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    @forelse($results as $index => $res)
                    <tr class="hover:bg-slate-50/80 transition-colors {{ $res->is_cancelled ? 'bg-rose-50/20' : '' }}">
                        <td class="px-4 py-3 text-center text-slate-400 font-medium">
                            {{ $results->firstItem() + $index }}
                        </td>
                        <td class="px-4 py-3 font-mono font-semibold text-slate-600">
                            {{ $res->transaction_number }}
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-slate-600">
                            {{ $res->cast_date ? $res->cast_date->format('d/m/Y') : '-' }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-mono font-bold text-amber-600 text-sm tracking-wide">
                                {{ $res->heat_number }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            {{ $res->furnace ? 'Tungku '.$res->furnace : '-' }}
                            @if($res->shift)
                                <span class="text-slate-400">/ Shift {{ $res->shift }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-bold text-slate-900">
                            {{ number_format($res->total_trees) }}
                        </td>
                        <td class="px-4 py-3 text-right font-bold text-slate-900">
                            {{ number_format($res->total_pcs) }}
                        </td>
                        <td class="px-4 py-3 text-center whitespace-nowrap">
                            @if($res->is_completed)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    <i class="fas fa-check-circle mr-1 text-[9px]"></i> SELESAI
                                </span>
                            @else
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200" title="{{ $res->void_reason }}">
                                    <i class="fas fa-ban mr-1 text-[9px]"></i> DIBATALKAN
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                            <div class="font-semibold text-slate-800">{{ $res->recorder?->name ?? 'Admin' }}</div>
                            <div class="text-[10px] text-slate-400">{{ $res->created_at ? $res->created_at->format('d/m/Y H:i') : '' }}</div>
                        </td>
                        <td class="px-4 py-3 text-center whitespace-nowrap">
                            <div class="inline-flex items-center gap-1.5">
                                <a href="{{ route('lost-wax.casting-results.show', $res) }}"
                                   class="px-2.5 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold border border-slate-200 transition-colors"
                                   title="Lihat Detail">
                                    <i class="fas fa-eye text-xs"></i>
                                </a>
                                <a href="{{ route('lost-wax.casting-results.print', $res) }}"
                                   target="_blank"
                                   class="px-2.5 py-1.5 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-700 text-xs font-semibold border border-amber-200 transition-colors"
                                   title="Cetak Bukti Hasil Cor">
                                    <i class="fas fa-print text-xs"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="10" class="px-6 py-12 text-center text-slate-500">
                            <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-3 text-xl">
                                <i class="fas fa-inbox"></i>
                            </div>
                            <div class="font-semibold text-slate-700">Belum ada transaksi hasil cor</div>
                            <p class="text-xs text-slate-400 mt-1">Gunakan tombol "Input Hasil Cor Baru" untuk mencatat Heat Number dari antrean Siap Cor.</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($results->hasPages())
        <div class="px-4 py-3 border-t border-slate-200 bg-slate-50/50">
            {{ $results->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
