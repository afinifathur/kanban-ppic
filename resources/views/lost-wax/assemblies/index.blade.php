@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Perintah Rangkai (Assembly)</h1>
            <p class="text-gray-500 text-[10px]">Kelola Rencana &amp; Eksekusi Rangkai Pohon (Tree) Lilin</p>
        </div>
    </div>
@endsection

@section('content')
    @php
        $activeTab = request('tab', 'available');
    @endphp

    <div class="space-y-4">
        <!-- Navigation Tabs -->
        <div class="border-b border-slate-200">
            <nav class="flex gap-6 text-sm font-semibold" aria-label="Tabs">
                <a href="{{ route('lost-wax.assemblies.index', ['tab' => 'available']) }}" 
                    class="py-2.5 border-b-2 px-1 transition-all {{ $activeTab === 'available' ? 'border-amber-600 text-amber-700 font-bold' : 'border-transparent text-gray-500 hover:text-slate-700' }}">
                    Hasil Cetak Siap Rangkai
                </a>
                <a href="{{ route('lost-wax.assemblies.index', ['tab' => 'work-orders']) }}" 
                    class="py-2.5 border-b-2 px-1 transition-all {{ $activeTab === 'work-orders' ? 'border-amber-600 text-amber-700 font-bold' : 'border-transparent text-gray-500 hover:text-slate-700' }}">
                    Daftar Perintah Rangkai (Work Orders)
                </a>
            </nav>
        </div>

            <!-- Search bar & Filters -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <form method="GET" action="{{ route('lost-wax.assemblies.index') }}" class="flex flex-wrap items-end gap-3.5">
                    <input type="hidden" name="tab" value="{{ $activeTab }}">
                    
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Cari Item / No Perintah</label>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Produk, PC-..." class="rounded-lg border-slate-300 text-sm w-48 py-1.5 px-3">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Kode Produksi</label>
                        <input type="text" name="code" value="{{ request('code') }}" placeholder="26AB001" class="rounded-lg border-slate-300 text-sm w-36 py-1.5 px-3">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Kode Customer</label>
                        <input type="text" name="customer" value="{{ request('customer') }}" placeholder="A06" class="rounded-lg border-slate-300 text-sm w-36 py-1.5 px-3">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Size</label>
                        <input type="text" name="size" value="{{ request('size') }}" placeholder='1/2"' class="rounded-lg border-slate-300 text-sm w-28 py-1.5 px-3">
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="submit" class="bg-slate-700 hover:bg-slate-800 text-white text-xs font-bold py-2 px-4 rounded-lg transition-all shadow-sm">
                            Filter
                        </button>
                        @if(request('search') || request('code') || request('customer') || request('size'))
                            <a href="{{ route('lost-wax.assemblies.index', ['tab' => $activeTab]) }}" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold py-2 px-4 rounded-lg transition-all border border-slate-200 text-center">
                                Reset
                            </a>
                        @endif
                    </div>
                </form>
            </div>

        @if($activeTab === 'available')
            <!-- Tab 1: Available lines for Rangkai -->

            <!-- Table of assembly items -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-xs font-bold text-slate-700 uppercase">
                            <th class="p-4">Kode Produksi</th>
                            <th class="p-4">Produk</th>
                            <th class="p-4">No Perintah Cetak</th>
                            <th class="p-4">Qty Rencana</th>
                            <th class="p-4">Hasil Good (Cetak)</th>
                            <th class="p-4">Sudah Rangkai</th>
                            <th class="p-4">Sisa Available</th>
                            <th class="p-4 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                        @forelse($lines as $line)
                            <tr>
                                <td class="p-4 font-semibold text-slate-800">{{ $line->code ?? '-' }}</td>
                                <td class="p-4">
                                    <div class="font-bold text-slate-800">{{ $line->item_name }}</div>
                                    <div class="text-xs text-slate-500">
                                        Size: {{ $line->size ?? '-' }} &middot; AISI: {{ $line->aisi ?? '-' }} &middot; Cust: {{ $line->customer ?? '-' }}
                                    </div>
                                </td>
                                <td class="p-4">
                                    <a href="{{ route('lost-wax.print-orders.show', $line->lost_wax_print_order_id) }}" class="hover:text-amber-600 font-medium">
                                        {{ $line->printOrder->print_order_number }}
                                    </a>
                                </td>
                                <td class="p-4">{{ number_format($line->qty_ordered) }} pcs</td>
                                <td class="p-4 font-semibold text-slate-700">{{ number_format($line->qty_executed_good) }} pcs</td>
                                <td class="p-4 text-slate-500">
                                    @php
                                        $allocated = $line->trees->sum('quantity');
                                    @endphp
                                    {{ number_format($allocated) }} pcs
                                </td>
                                <td class="p-4 font-bold text-amber-700 bg-amber-50/50">
                                    {{ number_format($line->qty_available_for_rangkai) }} pcs
                                </td>
                                <td class="p-4 text-right">
                                    <a href="{{ route('lost-wax.assemblies.create', $line) }}" class="inline-flex items-center gap-1 bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold py-1.5 px-3 rounded-lg transition-all shadow-sm">
                                        <i class="fas fa-plus"></i> Buat WO Rangkai
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="p-8 text-center text-slate-400">
                                    <i class="fas fa-link text-3xl mb-2 block"></i>
                                    Tidak ada hasil cetak yang tersedia untuk dirangkai.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $lines->appends(request()->query())->links() }}</div>
        @else
            <!-- Tab 2: Rangkai Work Orders -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-xs font-bold text-slate-700 uppercase">
                            <th class="p-4">No WO Rangkai</th>
                            <th class="p-4">No Perintah Cetak</th>
                            <th class="p-4">Produk / Item</th>
                            <th class="p-4">Rencana Rangkai (Pcs)</th>
                            <th class="p-4">Realisasi (Pcs)</th>
                            <th class="p-4">Outstanding (Pcs)</th>
                            <th class="p-4">Status</th>
                            <th class="p-4 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                        @forelse($workOrders as $wo)
                            <tr>
                                <td class="p-4 font-semibold text-slate-850">{{ $wo->rangkai_order_number }}</td>
                                <td class="p-4">
                                    <a href="{{ route('lost-wax.print-orders.show', $wo->printOrderLine->lost_wax_print_order_id) }}" class="hover:text-amber-600 font-medium">
                                        {{ $wo->printOrderLine->printOrder->print_order_number }}
                                    </a>
                                </td>
                                <td class="p-4">
                                    <div class="font-bold text-slate-800">{{ $wo->printOrderLine->item_name }}</div>
                                    <div class="text-xs text-slate-500">
                                        AISI: {{ $wo->printOrderLine->aisi ?? '-' }} &middot; Size: {{ $wo->printOrderLine->size ?? '-' }} &middot; Kode: {{ $wo->printOrderLine->code ?? '-' }}
                                    </div>
                                </td>
                                <td class="p-4">{{ number_format($wo->qty_planned_pcs) }} pcs</td>
                                <td class="p-4 font-semibold text-slate-700">{{ number_format($wo->qty_executed_pcs) }} pcs</td>
                                <td class="p-4 font-bold {{ $wo->qty_outstanding > 0 ? 'text-amber-700' : 'text-emerald-700' }}">
                                    {{ number_format($wo->qty_outstanding) }} pcs
                                </td>
                                <td class="p-4">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase
                                        {{ $wo->status === 'COMPLETED' ? 'bg-emerald-100 text-emerald-800' : ($wo->status === 'IN_PROGRESS' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-800') }}">
                                        {{ $wo->status }}
                                    </span>
                                </td>
                                <td class="p-4 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('lost-wax.assemblies.work-orders.print', $wo) }}" target="_blank" class="inline-flex items-center gap-1 bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold py-1.5 px-3 rounded-lg transition-all shadow-sm">
                                            <i class="fas fa-print"></i> Print A5
                                        </a>
                                        <a href="{{ route('lost-wax.assemblies.work-orders.show', $wo) }}" class="inline-flex items-center gap-1 bg-slate-700 hover:bg-slate-800 text-white text-xs font-bold py-1.5 px-3 rounded-lg transition-all shadow-sm">
                                            <i class="fas fa-eye"></i> Detail &amp; Eksekusi
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="p-8 text-center text-slate-400">
                                    <i class="fas fa-folder-open text-3xl mb-2 block"></i>
                                    Belum ada perintah Rangkai Work Order yang diterbitkan.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $workOrders->appends(request()->query())->links() }}</div>
        @endif
    </div>
@endsection
