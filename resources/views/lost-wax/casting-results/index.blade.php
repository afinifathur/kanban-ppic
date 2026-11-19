@extends('layouts.app')

@section('title', 'Riwayat Hasil Cor — Lost Wax')

@section('top_bar')
<div class="flex items-center justify-between w-full min-w-0 gap-2">
    <div class="flex flex-col min-w-0">
        <div class="flex items-center gap-1.5 text-[11px] text-slate-400 font-medium">
            <span class="inline-flex items-center text-amber-600 font-bold">
                <i class="fas fa-fire mr-1 text-[10px]"></i>Lost Wax Pengecoran
            </span>
            <span class="text-slate-300">•</span>
            <span class="truncate text-slate-500 font-medium">Phase 4 — Input Hasil Cor</span>
        </div>
        <h1 class="text-base font-bold text-slate-900 leading-tight tracking-tight">
            Riwayat Hasil Cor
        </h1>
    </div>
    <div class="flex items-center gap-1.5 shrink-0">
        <a href="{{ route('lost-wax.casting-results.daily-report') }}"
           class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 transition shadow-xs">
            <i class="fas fa-calendar-day mr-1.5 text-amber-500"></i> Laporan Cor Harian
        </a>
        <a href="{{ route('lost-wax.reports.ready-for-casting.index') }}"
           class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-800 hover:bg-slate-900 text-white transition shadow-xs">
            <i class="fas fa-clipboard-check mr-1.5 text-amber-400"></i> Antrean Siap Cor
        </a>
        <a href="{{ route('lost-wax.casting-results.create') }}"
           class="inline-flex items-center px-3 py-1 rounded-lg text-xs font-bold bg-amber-500 hover:bg-amber-600 text-white transition shadow-xs">
            <i class="fas fa-plus mr-1 text-[10px]"></i> Input Hasil Cor Baru
        </a>
    </div>
</div>
@endsection

@section('content')
<div class="px-2 py-3 space-y-2.5">
    <!-- Flash Alerts -->
    @if(session('success'))
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-3 py-2 rounded-lg flex items-center gap-2 shadow-xs text-xs">
        <i class="fas fa-check-circle text-emerald-500 text-sm shrink-0"></i>
        <span class="font-medium">{{ session('success') }}</span>
    </div>
    @endif
    @if(session('error'))
    <div class="bg-rose-50 border border-rose-200 text-rose-800 px-3 py-2 rounded-lg flex items-center gap-2 shadow-xs text-xs">
        <i class="fas fa-exclamation-triangle text-rose-500 text-sm shrink-0"></i>
        <span class="font-medium">{{ session('error') }}</span>
    </div>
    @endif

    <!-- Compact 4-KPI Summary Cards -->
    <div class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-2">
        <!-- 1. Total Transaksi -->
        <div class="bg-white border border-slate-200 rounded-lg px-3 py-2 shadow-xs hover:border-slate-300 transition-colors">
            <div class="flex items-center justify-between">
                <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Total Transaksi</span>
                <i class="fas fa-list text-slate-400 text-[10px]"></i>
            </div>
            <div class="mt-0.5 flex items-baseline justify-between">
                <span class="text-lg font-black font-mono text-slate-900 leading-none">{{ number_format($summary['total_transactions']) }}</span>
                <span class="text-[10px] text-slate-400 font-medium">Semua batch</span>
            </div>
        </div>

        <!-- 2. Heat Aktif (Selesai) -->
        <div class="bg-white border border-slate-200 rounded-lg px-3 py-2 shadow-xs hover:border-emerald-300 transition-colors">
            <div class="flex items-center justify-between">
                <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Heat Aktif (Selesai)</span>
                <i class="fas fa-check-circle text-emerald-500 text-[10px]"></i>
            </div>
            <div class="mt-0.5 flex items-baseline justify-between">
                <span class="text-lg font-black font-mono text-emerald-600 leading-none">{{ number_format($summary['total_completed']) }}</span>
                <span class="text-[10px] text-slate-400 font-medium">
                    @if($summary['total_cancelled'] > 0)
                        <span class="text-rose-500 font-bold">{{ number_format($summary['total_cancelled']) }} batal</span>
                    @else
                        Semua aktif
                    @endif
                </span>
            </div>
        </div>

        <!-- 3. Total Pohon Dicor -->
        <div class="bg-white border border-slate-200 rounded-lg px-3 py-2 shadow-xs hover:border-amber-300 transition-colors">
            <div class="flex items-center justify-between">
                <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Total Pohon Dicor</span>
                <i class="fas fa-tree text-amber-500 text-[10px]"></i>
            </div>
            <div class="mt-0.5 flex items-baseline justify-between">
                <span class="text-lg font-black font-mono text-amber-600 leading-none">{{ number_format($summary['total_trees']) }}</span>
                <span class="text-[10px] text-slate-400 font-medium">Pohon terkonsumsi</span>
            </div>
        </div>

        <!-- 4. Total Kuantitas Dicor -->
        <div class="bg-white border border-slate-200 rounded-lg px-3 py-2 shadow-xs hover:border-blue-300 transition-colors">
            <div class="flex items-center justify-between">
                <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Total Kuantitas Dicor</span>
                <i class="fas fa-cubes text-blue-500 text-[10px]"></i>
            </div>
            <div class="mt-0.5 flex items-baseline justify-between">
                <span class="text-lg font-black font-mono text-slate-900 leading-none">{{ number_format($summary['total_pcs']) }} <span class="text-xs font-bold text-amber-600">PCS</span></span>
                <span class="text-[10px] text-slate-400 font-medium">Akumulasi cor</span>
            </div>
        </div>
    </div>

    <!-- Main Container: Tabs, Filter & Table -->
    <div class="bg-white border border-slate-200 rounded-lg shadow-xs overflow-hidden">
        <!-- Toolbar: 2 Tabs + Filter Form -->
        <div class="border-b border-slate-200 px-3 py-1.5 flex flex-wrap items-center justify-between gap-2 bg-slate-50/60">
            <!-- Two Tabs: Selesai vs Dibatalkan -->
            <div class="flex items-center gap-1">
                <a href="{{ route('lost-wax.casting-results.index', array_filter(['tab' => 'completed', 'search' => $search, 'date_from' => $dateFrom, 'date_to' => $dateTo])) }}"
                   class="px-3 py-1.5 text-xs font-bold border-b-2 transition flex items-center gap-1.5 {{ $activeTab === 'completed' ? 'border-emerald-600 text-emerald-700 bg-emerald-50/70 rounded-t' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
                    <i class="fas fa-check-circle {{ $activeTab === 'completed' ? 'text-emerald-600' : 'text-slate-400' }} text-xs"></i>
                    <span>SELESAI</span>
                    <span class="px-1.5 py-0.2 text-[10px] font-black rounded-full {{ $activeTab === 'completed' ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-700' }}">
                        {{ number_format($tabCompletedCount) }}
                    </span>
                </a>
                <a href="{{ route('lost-wax.casting-results.index', array_filter(['tab' => 'cancelled', 'search' => $search, 'date_from' => $dateFrom, 'date_to' => $dateTo])) }}"
                   class="px-3 py-1.5 text-xs font-bold border-b-2 transition flex items-center gap-1.5 {{ $activeTab === 'cancelled' ? 'border-rose-600 text-rose-700 bg-rose-50/70 rounded-t' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
                    <i class="fas fa-ban {{ $activeTab === 'cancelled' ? 'text-rose-600' : 'text-slate-400' }} text-xs"></i>
                    <span>DIBATALKAN</span>
                    <span class="px-1.5 py-0.2 text-[10px] font-black rounded-full {{ $activeTab === 'cancelled' ? 'bg-rose-600 text-white' : 'bg-slate-200 text-slate-700' }}">
                        {{ number_format($tabCancelledCount) }}
                    </span>
                </a>
            </div>

            <!-- Filter & Search Controls -->
            <form method="GET" action="{{ route('lost-wax.casting-results.index') }}" class="flex flex-wrap items-center gap-1.5">
                <input type="hidden" name="tab" value="{{ $activeTab }}">
                <div class="relative">
                    <input type="text"
                           name="search"
                           value="{{ $search }}"
                           placeholder="Cari Heat / Barcode / Kode..."
                           class="w-44 md:w-56 rounded-md border border-slate-300 pl-7 pr-2.5 py-1 text-xs text-slate-800 placeholder-slate-400 focus:ring-1 focus:ring-amber-500 focus:border-amber-500">
                    <i class="fas fa-search absolute left-2.5 top-2 text-slate-400 text-xs"></i>
                </div>
                <div class="flex items-center gap-1 text-xs text-slate-500">
                    <input type="date"
                           name="date_from"
                           value="{{ $dateFrom }}"
                           title="Dari tanggal cor"
                           class="rounded-md border border-slate-300 px-2 py-1 text-xs text-slate-700 focus:ring-1 focus:ring-amber-500">
                    <span>-</span>
                    <input type="date"
                           name="date_to"
                           value="{{ $dateTo }}"
                           title="Sampai tanggal cor"
                           class="rounded-md border border-slate-300 px-2 py-1 text-xs text-slate-700 focus:ring-1 focus:ring-amber-500">
                </div>
                <button type="submit" class="px-2.5 py-1 rounded-md bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold transition shadow-xs">
                    Filter
                </button>
                @if($search !== '' || $dateFrom !== '' || $dateTo !== '')
                    <a href="{{ route('lost-wax.casting-results.index', ['tab' => $activeTab]) }}" class="px-2 py-1 rounded-md border border-slate-300 text-slate-600 text-xs hover:bg-slate-100 font-medium" title="Reset filter">
                        Reset
                    </a>
                @endif
            </form>
        </div>

        <!-- Table of Results -->
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-xs text-left">
                <thead class="bg-slate-50 text-slate-600 uppercase text-[10px] font-bold tracking-wider">
                    <tr>
                        <th class="px-3 py-2 text-center w-10">No</th>
                        <th class="px-3 py-2">No. Transaksi</th>
                        <th class="px-3 py-2">Tgl Cor</th>
                        <th class="px-3 py-2">Heat Number</th>
                        <th class="px-3 py-2 text-center">Status Kitir</th>
                        <th class="px-3 py-2 text-right">Pohon</th>
                        <th class="px-3 py-2 text-right">Qty (PCS)</th>
                        <th class="px-3 py-2 text-center">Status</th>
                        <th class="px-3 py-2">Dibuat Oleh</th>
                        <th class="px-3 py-2 text-center w-24">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700 bg-white">
                    @forelse($results as $index => $res)
                        @php
                            $kitirStatus = $res->kitir_status;
                            $totProd = $kitirStatus['total'];
                            $issCount = $kitirStatus['issued'];
                            $prtCount = $kitirStatus['printed'];
                        @endphp
                        <tr class="hover:bg-slate-50/80 transition-colors {{ $res->is_cancelled ? 'bg-rose-50/20' : '' }}">
                            <!-- No -->
                            <td class="px-3 py-2 text-center text-slate-400 font-medium">
                                {{ $results->firstItem() + $index }}
                            </td>

                            <!-- No. Transaksi -->
                            <td class="px-3 py-2 font-mono font-semibold text-slate-600 whitespace-nowrap">
                                {{ $res->transaction_number }}
                            </td>

                            <!-- Tgl Cor -->
                            <td class="px-3 py-2 whitespace-nowrap text-slate-600">
                                {{ $res->cast_date ? $res->cast_date->format('d/m/Y') : '-' }}
                            </td>

                            <!-- Heat Number -->
                            <td class="px-3 py-2">
                                <span class="font-mono font-bold text-amber-600 text-xs tracking-wide">
                                    {{ $res->heat_number }}
                                </span>
                            </td>

                            <!-- STATUS KITIR (Terbit / Cetak Ratio against unique products) -->
                            <td class="px-3 py-2 whitespace-nowrap text-center">
                                @if($totProd > 0)
                                    <div class="inline-flex flex-col items-center gap-1">
                                        <!-- Terbit Ratio -->
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold {{ $issCount === $totProd ? 'bg-blue-50 text-blue-700 border border-blue-200' : ($issCount > 0 ? 'bg-amber-50 text-amber-700 border border-amber-200' : 'bg-slate-100 text-slate-500 border border-slate-200') }}" title="{{ $issCount }} dari {{ $totProd }} produk telah diterbitkan Kitir">
                                            <span class="text-slate-500 mr-1 font-medium">Terbit:</span>
                                            <span class="font-mono">{{ $issCount }}/{{ $totProd }}</span>
                                        </span>
                                        <!-- Cetak Ratio -->
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold {{ $prtCount === $totProd ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : ($prtCount > 0 ? 'bg-amber-50 text-amber-700 border border-amber-200' : 'bg-slate-100 text-slate-500 border border-slate-200') }}" title="{{ $prtCount }} dari {{ $totProd }} produk telah dicetak Kitirnya">
                                            <span class="text-slate-500 mr-1 font-medium">Cetak:</span>
                                            <span class="font-mono">{{ $prtCount }}/{{ $totProd }}</span>
                                        </span>
                                    </div>
                                @else
                                    <span class="text-slate-400 font-mono text-[11px]">-</span>
                                @endif
                            </td>

                            <!-- Pohon -->
                            <td class="px-3 py-2 text-right font-bold text-slate-900 font-mono">
                                {{ number_format($res->total_trees) }}
                            </td>

                            <!-- Qty (PCS) -->
                            <td class="px-3 py-2 text-right font-bold text-slate-900 font-mono">
                                {{ number_format($res->total_pcs) }}
                            </td>

                            <!-- Status Transaksi -->
                            <td class="px-3 py-2 text-center whitespace-nowrap">
                                @if($res->is_completed)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        <i class="fas fa-check-circle mr-1 text-[9px]"></i> SELESAI
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200" title="{{ $res->void_reason }}">
                                        <i class="fas fa-ban mr-1 text-[9px]"></i> DIBATALKAN
                                    </span>
                                @endif
                            </td>

                            <!-- Dibuat Oleh -->
                            <td class="px-3 py-2 text-slate-600 whitespace-nowrap">
                                <div class="font-semibold text-slate-800 text-[11px]">{{ $res->recorder?->name ?? 'Admin' }}</div>
                                <div class="text-[10px] text-slate-400 font-mono">{{ $res->created_at ? $res->created_at->format('d/m/Y H:i') : '' }}</div>
                            </td>

                            <!-- Aksi -->
                            <td class="px-3 py-2 text-center whitespace-nowrap">
                                <div class="inline-flex items-center gap-1">
                                    <a href="{{ route('lost-wax.casting-results.show', $res) }}"
                                       class="px-2 py-1 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold border border-slate-200 transition"
                                       title="Lihat Detail Transaksi">
                                        <i class="fas fa-eye text-xs"></i>
                                    </a>
                                    <a href="{{ route('lost-wax.casting-results.print', $res) }}"
                                       target="_blank"
                                       class="px-2 py-1 rounded bg-amber-50 hover:bg-amber-100 text-amber-700 text-xs font-semibold border border-amber-200 transition"
                                       title="Cetak Bukti Hasil Cor">
                                        <i class="fas fa-print text-xs"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-4 py-8 text-center text-slate-500">
                                <div class="w-10 h-10 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-2 text-lg">
                                    <i class="fas fa-inbox"></i>
                                </div>
                                <div class="font-semibold text-slate-700 text-xs">
                                    {{ $activeTab === 'cancelled' ? 'Tidak ada transaksi hasil cor yang dibatalkan' : 'Belum ada transaksi hasil cor selesai' }}
                                </div>
                                <p class="text-[11px] text-slate-400 mt-0.5">
                                    {{ $activeTab === 'cancelled' ? 'Seluruh transaksi cor saat ini berstatus aktif/selesai.' : 'Gunakan tombol "Input Hasil Cor Baru" untuk mencatat Heat Number dari antrean Siap Cor.' }}
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($results->hasPages())
        <div class="px-3 py-2 border-t border-slate-200 bg-slate-50/60">
            {{ $results->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
