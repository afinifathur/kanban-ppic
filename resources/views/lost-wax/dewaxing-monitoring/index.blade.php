@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full gap-2">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">MONITORING PROSES DEWAXING</h1>
            <p class="text-gray-500 text-[10px]">Pemantauan pohon yang sedang menjalani proses dewaxing hingga masuk rak buffer.</p>
        </div>
        <div class="flex items-center gap-2">
            <a
                href="{{ route('lost-wax.scan-siap-cor.index') }}"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all shadow-xs bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-300 cursor-pointer"
                title="Kembali ke Stasiun Scan Selesai Dewaxing"
            >
                <i class="fas fa-arrow-left text-slate-500 text-xs"></i>
                <span class="hidden sm:inline">KEMBALI KE SCAN SIAP COR</span>
                <span class="sm:hidden">SCAN</span>
            </a>
            <button
                type="button"
                onclick="window.location.reload()"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all shadow-xs bg-emerald-600 hover:bg-emerald-700 text-white border border-emerald-700 cursor-pointer"
                title="Perbarui Data Antrean dan Durasi"
            >
                <i class="fas fa-sync-alt text-white text-xs"></i>
                <span>REFRESH</span>
            </button>
        </div>
    </div>
@endsection

@section('content')
    <div class="max-w-7xl mx-auto space-y-6">

        {{-- Meta Bar / Status Pembaruan --}}
        <div class="bg-white rounded-xl shadow-xs border border-slate-200 px-4 py-3 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 text-xs text-slate-600">
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-indigo-100 text-indigo-800 border border-indigo-200">
                    <i class="fas fa-shield-alt text-[9px]"></i> READ-ONLY MONITORING
                </span>
                <span class="text-slate-400">|</span>
                <span>Alur Proses: <strong class="text-slate-700">Scan Masuk Oven &rarr; Dewaxing &rarr; Scan Selesai Dewaxing</strong></span>
            </div>
            <div class="flex items-center gap-1.5 text-slate-500">
                <i class="fas fa-clock text-slate-400"></i>
                <span>Waktu Pembaruan Data: <strong class="text-slate-800 font-mono">{{ $updatedAt->translatedFormat('d F Y, H:i:s') }} WIB</strong></span>
            </div>
        </div>

        {{-- Tiga Kartu KPI Ringkasan --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            {{-- KPI 1: Total Pohon di Oven --}}
            <div class="bg-white rounded-xl shadow-xs border border-slate-200 p-5 flex items-center justify-between transition-all hover:border-amber-400">
                <div>
                    <div class="text-xs font-bold text-slate-500 tracking-wider mb-1">TOTAL POHON DI OVEN</div>
                    <div class="text-3xl font-black text-slate-800 tracking-tight font-mono">
                        {{ number_format($summary['total_trees']) }}
                    </div>
                    <div class="text-[11px] text-slate-400 mt-1">Traveler Card menunggu selesai dewaxing</div>
                </div>
                <div class="w-12 h-12 rounded-xl bg-amber-50 border border-amber-200 flex items-center justify-center text-amber-600 text-xl shadow-xs">
                    <i class="fas fa-fire-burner"></i>
                </div>
            </div>

            {{-- KPI 2: Total PCS di Oven --}}
            <div class="bg-white rounded-xl shadow-xs border border-slate-200 p-5 flex items-center justify-between transition-all hover:border-emerald-400">
                <div>
                    <div class="text-xs font-bold text-slate-500 tracking-wider mb-1">TOTAL PCS DI OVEN</div>
                    <div class="text-3xl font-black text-emerald-700 tracking-tight font-mono">
                        {{ number_format($summary['total_pcs']) }}
                    </div>
                    <div class="text-[11px] text-slate-400 mt-1">Total kuantitas layak siap proses</div>
                </div>
                <div class="w-12 h-12 rounded-xl bg-emerald-50 border border-emerald-200 flex items-center justify-center text-emerald-600 text-xl shadow-xs">
                    <i class="fas fa-cubes"></i>
                </div>
            </div>

            {{-- KPI 3: Pohon Terlama di Oven --}}
            <div class="bg-white rounded-xl shadow-xs border border-slate-200 p-5 flex items-center justify-between transition-all hover:border-indigo-400">
                <div>
                    <div class="text-xs font-bold text-slate-500 tracking-wider mb-1">POHON TERLAMA DI OVEN</div>
                    <div class="text-2xl font-black text-indigo-700 tracking-tight font-mono">
                        {{ $summary['longest_duration'] }}
                    </div>
                    <div class="text-[11px] text-slate-400 mt-1">Urutan antrean teratas (FIFO)</div>
                </div>
                <div class="w-12 h-12 rounded-xl bg-indigo-50 border border-indigo-200 flex items-center justify-center text-indigo-600 text-xl shadow-xs">
                    <i class="fas fa-hourglass-half"></i>
                </div>
            </div>
        </div>

        {{-- TABEL UTAMA — MENUNGGU SELESAI DEWAXING (FIFO) --}}
        <div class="bg-white rounded-xl shadow-xs border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 bg-slate-50 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <div class="flex items-center gap-2">
                        <i class="fas fa-temperature-high text-amber-600"></i>
                        <h2 class="font-bold text-slate-800 text-sm uppercase tracking-wider">Antrean Menunggu Selesai Dewaxing</h2>
                        <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-300">
                            FIFO (Waktu Masuk Terlama di Atas)
                        </span>
                    </div>
                    <p class="text-xs text-slate-500 mt-0.5">Pohon yang telah discan masuk oven dan belum discan keluar ke rak buffer.</p>
                </div>
                <div class="text-xs text-slate-500 font-mono">
                    Menampilkan: <strong class="text-slate-800">{{ number_format($waitingItems->firstItem() ?? 0) }}&ndash;{{ number_format($waitingItems->lastItem() ?? 0) }}</strong> dari <strong class="text-slate-800">{{ number_format($waitingItems->total()) }} Pohon</strong>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-700" id="waitingTable">
                    <thead class="bg-slate-100 text-slate-600 font-bold text-[11px] uppercase border-b border-slate-200">
                        <tr>
                            <th class="py-3 px-3 text-center w-12">No.</th>
                            <th class="py-3 px-3 w-44">Traveler Card</th>
                            <th class="py-3 px-3">Kode Produksi</th>
                            <th class="py-3 px-3">Nama Item</th>
                            <th class="py-3 px-3">Customer</th>
                            <th class="py-3 px-3">SPK</th>
                            <th class="py-3 px-3 text-right">Qty PCS</th>
                            <th class="py-3 px-3 text-center">Waktu Masuk Oven</th>
                            <th class="py-3 px-3 text-center">Durasi di Oven</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($waitingItems as $idx => $item)
                            <tr class="hover:bg-slate-50 transition-colors {{ ($waitingItems->currentPage() === 1 && $loop->first) ? 'bg-amber-50/40' : '' }}">
                                <td class="py-3 px-3 text-center font-mono text-slate-400 font-bold">
                                    {{ ($waitingItems->firstItem() ?? 1) + $idx }}
                                </td>
                                <td class="py-3 px-3">
                                    <div class="font-mono font-bold text-slate-800 text-sm">{{ $item['human_barcode'] }}</div>
                                    <div class="font-mono text-[10px] text-slate-400">{{ $item['barcode'] }} &bull; Pohon #{{ $item['tree_number'] }}</div>
                                </td>
                                <td class="py-3 px-3">
                                    <span class="inline-block font-mono font-bold text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded border border-indigo-200">
                                        {{ $item['production_code'] }}
                                    </span>
                                </td>
                                <td class="py-3 px-3">
                                    <div class="font-semibold text-slate-800 truncate max-w-xs" title="{{ $item['item_name'] }}">
                                        {{ $item['item_name'] }}
                                    </div>
                                </td>
                                <td class="py-3 px-3 text-slate-600">
                                    <div class="truncate max-w-[140px]" title="{{ $item['customer'] }}">
                                        {{ $item['customer'] }}
                                    </div>
                                </td>
                                <td class="py-3 px-3 font-mono text-slate-600">
                                    {{ $item['spk_number'] }}
                                </td>
                                <td class="py-3 px-3 text-right">
                                    <span class="font-mono font-black text-emerald-700 text-sm">
                                        {{ number_format($item['quantity_pcs']) }} PCS
                                    </span>
                                </td>
                                <td class="py-3 px-3 text-center font-mono text-slate-600 text-[11px]">
                                    {{ $item['entry_time_formatted'] }}
                                </td>
                                <td class="py-3 px-3 text-center">
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold font-mono {{ ($waitingItems->currentPage() === 1 && $loop->first) ? 'bg-amber-200 text-amber-900 border border-amber-400 font-black' : 'bg-amber-100 text-amber-800 border border-amber-300' }}">
                                        <i class="fas fa-hourglass-half text-amber-600 text-[10px]"></i>
                                        {{ $item['duration'] }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="py-12 text-center text-slate-400">
                                    <div class="flex flex-col items-center justify-center">
                                        <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center mb-3 text-slate-400 text-xl">
                                            <i class="fas fa-check-double"></i>
                                        </div>
                                        <p class="font-bold text-slate-600 text-sm">Tidak Ada Pohon Menunggu Selesai Dewaxing</p>
                                        <p class="text-xs text-slate-400 mt-1 max-w-sm">Seluruh pohon yang masuk oven telah discan selesai dewaxing, atau belum ada pemindaian masuk oven baru.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Navigasi Pagination Server-Side --}}
            @if($waitingItems->hasPages())
                <div class="px-5 py-3.5 bg-slate-50 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-600">
                    <div class="font-medium">
                        Menampilkan <strong class="text-slate-800">{{ number_format($waitingItems->firstItem() ?? 0) }}</strong> &ndash; <strong class="text-slate-800">{{ number_format($waitingItems->lastItem() ?? 0) }}</strong> dari <strong class="text-slate-800">{{ number_format($waitingItems->total()) }}</strong> pohon
                    </div>
                    <div class="overflow-x-auto max-w-full">
                        {{ $waitingItems->links() }}
                    </div>
                </div>
            @endif
        </div>

        {{-- TABEL SEKUNDER — RIWAYAT SELESAI DEWAXING (20 TERAKHIR) --}}
        <div class="bg-white rounded-xl shadow-xs border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 bg-slate-50 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <div class="flex items-center gap-2">
                        <i class="fas fa-history text-slate-500"></i>
                        <h2 class="font-bold text-slate-800 text-sm uppercase tracking-wider">Riwayat Selesai Dewaxing (20 Terakhir)</h2>
                        <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold bg-slate-200 text-slate-700">
                            Referensi Silang
                        </span>
                    </div>
                    <p class="text-xs text-slate-500 mt-0.5">Pohon yang baru saja menyelesaikan proses dewaxing dan telah berstatus Siap Cor di rak buffer.</p>
                </div>
                <div class="text-xs text-slate-500 font-mono">
                    Ditampilkan: <strong class="text-slate-800">{{ number_format($completedItems->count()) }} Pohon</strong>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-700" id="completedTable">
                    <thead class="bg-slate-100 text-slate-600 font-bold text-[11px] uppercase border-b border-slate-200">
                        <tr>
                            <th class="py-3 px-3 text-center w-12">No.</th>
                            <th class="py-3 px-3 w-48">Traveler Card</th>
                            <th class="py-3 px-3">Kode Produksi</th>
                            <th class="py-3 px-3">Nama Item</th>
                            <th class="py-3 px-3">Customer</th>
                            <th class="py-3 px-3 text-right">Qty PCS</th>
                            <th class="py-3 px-3 text-center">Waktu Selesai Dewaxing</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($completedItems as $idx => $c)
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="py-2.5 px-3 text-center font-mono text-slate-400">{{ $idx + 1 }}</td>
                                <td class="py-2.5 px-3">
                                    <div class="font-mono font-bold text-slate-800 text-xs">{{ $c['human_barcode'] }}</div>
                                    <div class="font-mono text-[10px] text-slate-400">{{ $c['barcode'] }}</div>
                                </td>
                                <td class="py-2.5 px-3">
                                    <span class="inline-block font-mono font-bold text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded border border-indigo-200 text-[11px]">
                                        {{ $c['production_code'] }}
                                    </span>
                                </td>
                                <td class="py-2.5 px-3">
                                    <div class="font-medium text-slate-700 truncate max-w-sm" title="{{ $c['item_name'] }}">
                                        {{ $c['item_name'] }}
                                    </div>
                                </td>
                                <td class="py-2.5 px-3 text-slate-600">
                                    <div class="truncate max-w-[140px]" title="{{ $c['customer'] }}">
                                        {{ $c['customer'] }}
                                    </div>
                                </td>
                                <td class="py-2.5 px-3 text-right">
                                    <span class="font-mono font-black text-emerald-700 text-xs">
                                        {{ number_format($c['quantity_pcs']) }} PCS
                                    </span>
                                </td>
                                <td class="py-2.5 px-3 text-center">
                                    <div class="inline-flex items-center gap-1.5 font-mono text-slate-600 text-[11px]">
                                        <span>{{ $c['completed_at_formatted'] }}</span>
                                        <span class="inline-block px-1.5 py-0.2 rounded text-[9px] font-black bg-emerald-100 text-emerald-800 border border-emerald-300">
                                            SELESAI
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-8 text-center text-slate-400">
                                    Belum ada data pohon yang menyelesaikan proses dewaxing.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
@endsection
