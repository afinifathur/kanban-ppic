@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <div class="text-[11px] font-bold text-slate-500 tracking-wider uppercase">PT. PERONI KARYA SENTRA &bull; KANBAN PPIC LOST WAX</div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">LOST WAX &mdash; BARANG SIAP OVEN</h1>
            <p class="text-gray-500 text-[10px]">Daftar kelompok barang yang telah menyelesaikan lapisan dan siap masuk Oven (FIFO)</p>
        </div>
        <div class="flex gap-2 no-print">
            <a href="{{ route('lost-wax.reports.ready-for-oven.print', request()->query()) }}" target="_blank"
                class="text-xs bg-rose-600 hover:bg-rose-700 text-white font-bold py-1.5 px-3 rounded shadow-sm flex items-center gap-1.5">
                <i class="fas fa-print"></i>
                <span>Cetak Dokumen A4</span>
            </a>
        </div>
    </div>
@endsection

@section('content')
<div class="space-y-4">
    <!-- KPI Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm">
            <div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Pohon Fisik Unik</div>
            <div class="text-2xl font-black text-slate-800 mt-1">{{ number_format($summary['total_unique_trees']) }} <span class="text-xs font-normal text-slate-500">Pohon</span></div>
            <div class="text-[10px] text-slate-400 mt-1 flex items-center gap-1">
                <i class="fas fa-barcode text-slate-400"></i>
                <span>Total antrean: <strong>{{ number_format($summary['total_rangkai']) }}</strong> rangkai</span>
            </div>
        </div>

        <div class="bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm">
            <div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Total Kuantitas Layak Pakai</div>
            <div class="text-2xl font-black text-emerald-600 mt-1">{{ number_format($summary['total_usable_pcs']) }} <span class="text-xs font-normal text-slate-500">Pcs</span></div>
            <div class="text-[10px] text-slate-500 mt-1">
                Bruto: {{ number_format($summary['total_gross_pcs']) }} | Afkir: {{ number_format($summary['total_defect_pcs']) }} pcs
            </div>
        </div>

        <div class="bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm">
            <div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Kelompok Barang (Baris)</div>
            <div class="text-2xl font-black text-slate-800 mt-1">{{ number_format($summary['total_groups']) }} <span class="text-xs font-normal text-slate-500">Kelompok</span></div>
            <div class="text-[10px] text-slate-400 mt-1">
                Dari <strong>{{ number_format($summary['distinct_plans_count']) }}</strong> Kode Produksi berbeda
            </div>
        </div>

        <div class="bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm">
            <div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Prioritas Antrean</div>
            <div class="text-sm font-bold text-indigo-700 mt-1">FIFO (Selesai Terawal)</div>
            <div class="text-[10px] text-slate-500 mt-1">
                Berdasarkan waktu selesai lapisan paling awal dalam kelompok
            </div>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="bg-white p-3 rounded-lg border border-slate-200 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-3">
        <form method="GET" action="{{ route('lost-wax.reports.ready-for-oven.index') }}" class="flex items-center gap-2 w-full sm:w-auto">
            <div class="relative flex-1 sm:w-80">
                <i class="fas fa-search absolute left-3 top-2.5 text-slate-400 text-xs"></i>
                <input type="text" name="search" value="{{ $search }}"
                    placeholder="Cari Kode Produksi, Item, Customer, Barcode, Rak..."
                    class="w-full pl-8 pr-3 py-1.5 text-xs border border-slate-300 rounded focus:outline-none focus:ring-1 focus:ring-rose-500">
            </div>
            <button type="submit" class="bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold py-1.5 px-3 rounded">
                Cari
            </button>
            @if($search)
                <a href="{{ route('lost-wax.reports.ready-for-oven.index') }}" class="text-xs text-rose-600 hover:underline">
                    Reset
                </a>
            @endif
        </form>

        <div class="text-[11px] text-slate-500 text-right w-full sm:w-auto">
            <span>Perbarui data: <strong>{{ $summary['generated_at_formatted'] }}</strong> WIB</span>
        </div>
    </div>

    <!-- Antrean Rules & Grouping Disclaimer -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-[11px] text-blue-900 flex items-start gap-2.5">
        <i class="fas fa-layer-group text-blue-500 text-sm mt-0.5 flex-shrink-0"></i>
        <div class="space-y-0.5">
            <span class="font-bold">Mekanisme Pengelompokan &amp; Kelayakan Antrean:</span>
            <p class="text-blue-800 leading-relaxed">
                Rangkai yang memiliki <strong>Kode Produksi, Nama Item, Customer, dan Tahap Lapisan Terakhir</strong> yang sama digabung menjadi 1 baris kelompok. Seluruh barcode anggota kelompok tetap tercantum di bawah baris untuk memastikan ketertelusuran fisik. Prioritas FIFO ditentukan oleh waktu selesai lapisan paling awal dari anggota kelompok tersebut.
            </p>
        </div>
    </div>

    <!-- Main Data Table -->
    <div class="bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs border-collapse">
                <thead>
                    <tr class="bg-slate-800 text-white font-bold text-[11px] tracking-wider uppercase">
                        <th class="py-2.5 px-3 text-center w-12 border-b border-slate-700">No</th>
                        <th class="py-2.5 px-3 border-b border-slate-700">Kode Produksi</th>
                        <th class="py-2.5 px-3 border-b border-slate-700">Nama Item</th>
                        <th class="py-2.5 px-3 border-b border-slate-700">Customer</th>
                        <th class="py-2.5 px-3 text-center border-b border-slate-700">Jumlah Rangkai</th>
                        <th class="py-2.5 px-3 text-right border-b border-slate-700">Jumlah Pcs</th>
                        <th class="py-2.5 px-3 text-center border-b border-slate-700">Waktu Selesai Lapisan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 text-slate-700">
                    @forelse($items as $index => $item)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="py-2.5 px-3 text-center font-bold text-slate-500 align-top">
                                {{ $index + 1 }}
                            </td>
                            <td class="py-2.5 px-3 font-semibold text-slate-900 align-top">
                                <div class="text-sm font-bold text-slate-900">{{ $item['production_code'] }}</div>
                                
                                <!-- Barcode / Rangkai Traceability Details -->
                                <div class="mt-1">
                                    @if(count($item['members']) === 1)
                                        <div class="text-[10px] text-slate-500 font-mono flex items-center gap-1.5">
                                            <span>{{ $item['members'][0]['human_barcode'] }}</span>
                                            @if($item['members'][0]['rack_label'] !== '-')
                                                <span class="inline-block bg-slate-100 text-slate-700 px-1.5 py-0.2 rounded text-[9px] border border-slate-200 font-sans font-bold">
                                                    {{ $item['members'][0]['rack_label'] }}
                                                </span>
                                            @endif
                                            @if($item['is_multi_allocated'])
                                                <span class="inline-block bg-amber-100 text-amber-800 px-1 py-0.2 rounded text-[9px] font-sans font-bold">
                                                    Multi-Alloc
                                                </span>
                                            @endif
                                        </div>
                                    @else
                                        <div class="space-y-1">
                                            <div class="text-[10px] font-bold text-slate-600 flex items-center gap-1">
                                                <i class="fas fa-barcode text-slate-400"></i>
                                                <span>{{ $item['tree_count'] }} Rangkai:</span>
                                            </div>
                                            <div class="flex flex-wrap gap-1 max-w-md">
                                                @foreach($item['members'] as $member)
                                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9.5px] font-mono bg-slate-100 text-slate-700 border border-slate-200"
                                                          title="Waktu: {{ $member['finished_at_formatted'] }} | Pcs: {{ $member['quantity_pcs'] }}">
                                                        <span>{{ $member['barcode'] }}</span>
                                                        @if($member['rack_label'] !== '-')
                                                            <span class="text-[8.5px] font-sans font-bold text-slate-500">[{{ $member['rack_label'] }}]</span>
                                                        @endif
                                                        <span class="text-[8.5px] font-sans font-semibold text-emerald-700">({{ $member['quantity_pcs'] }}p)</span>
                                                    </span>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </td>
                            <td class="py-2.5 px-3 text-slate-800 max-w-xs align-top font-medium" title="{{ $item['item_name'] }}">
                                {{ $item['item_name'] }}
                            </td>
                            <td class="py-2.5 px-3 text-slate-700 align-top">
                                <span class="inline-block bg-slate-100 text-slate-800 px-2 py-0.5 rounded text-[11px] font-bold">
                                    {{ $item['customer'] }}
                                </span>
                            </td>
                            <td class="py-2.5 px-3 text-center font-bold text-slate-800 align-top">
                                <div class="text-base font-black text-slate-900">{{ $item['tree_count'] }}</div>
                                <div class="text-[9.5px] font-normal text-slate-500">Rangkai</div>
                                @if($item['is_multi_allocated'])
                                    <span class="text-[9px] font-bold text-amber-600 block leading-tight mt-0.5">*Multi-Alloc</span>
                                @endif
                            </td>
                            <td class="py-2.5 px-3 text-right font-bold text-slate-900 align-top">
                                <div class="text-base font-black text-emerald-700">{{ number_format($item['quantity_pcs']) }}</div>
                                <div class="text-[9.5px] font-normal text-slate-500">Pcs Layak</div>
                                @if($item['defect_pcs'] > 0)
                                    <span class="text-[9.5px] font-medium text-rose-500 block leading-tight">
                                        (Afkir: {{ $item['defect_pcs'] }})
                                    </span>
                                @endif
                            </td>
                            <td class="py-2.5 px-3 text-center font-mono text-[11px] text-slate-700 align-top">
                                <div class="font-bold text-slate-800">{{ $item['finished_at_formatted'] }}</div>
                                <span class="inline-block mt-0.5 px-1.5 py-0.2 rounded text-[9px] font-sans font-semibold {{ $item['finished_stage'] === 'layer_7' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' }}">
                                    {{ $item['finished_stage_label'] }}
                                </span>
                                @if($item['has_mixed_times'])
                                    <div class="text-[9px] text-slate-400 font-sans mt-0.5">
                                        s/d {{ $item['latest_finished_at']->format('H:i') }}
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-slate-400 text-sm">
                                <i class="fas fa-inbox text-3xl mb-2 block"></i>
                                Tidak ada antrean barang yang siap masuk Oven saat ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if($items->isNotEmpty())
                    <tfoot class="bg-slate-100 font-bold text-slate-800 text-[11px] border-t-2 border-slate-300">
                        <tr>
                            <td colspan="4" class="py-3 px-3 text-right uppercase tracking-wider text-slate-600">
                                TOTAL KESELURUHAN:
                            </td>
                            <td class="py-3 px-3 text-center text-slate-900">
                                <div class="text-base font-black">{{ number_format($summary['total_rangkai']) }}</div>
                                <div class="text-[9.5px] font-normal text-slate-500 leading-tight">({{ number_format($summary['total_unique_trees']) }} Pohon Fisik Unik*)</div>
                            </td>
                            <td class="py-3 px-3 text-right text-slate-900">
                                <div class="text-base font-black text-emerald-700">{{ number_format($summary['total_usable_pcs']) }}</div>
                                <div class="text-[9.5px] font-normal text-slate-500 leading-tight">Pcs Layak Pakai</div>
                            </td>
                            <td class="py-3 px-3 text-center text-[10px] text-slate-600">
                                <strong>{{ $summary['total_groups'] }}</strong> Kelompok Barang
                            </td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>
@endsection
