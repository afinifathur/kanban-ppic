@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Rangkai</h1>
            <p class="text-gray-500 text-[10px]">Hasil Cetak Siap Rangkai — Kelola hasil cetak lilin yang siap untuk dirangkai</p>
        </div>
    </div>
@endsection

@section('content')
    <div class="space-y-4">
        <!-- Search bar & Filters -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <form method="GET" action="{{ route('lost-wax.assemblies.index') }}" class="flex flex-wrap items-end gap-3.5">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Cari Item / No Perintah</label>
                    <input type="text" name="search" list="item-suggestions" value="{{ request('search') }}" placeholder="Produk, PC-..." class="rounded-lg border-slate-300 text-sm w-48 py-1.5 px-3">
                    <datalist id="item-suggestions">
                        @foreach($itemSuggestions ?? [] as $suggestion)
                            <option value="{{ $suggestion }}"></option>
                        @endforeach
                    </datalist>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Kode Produksi</label>
                    <input type="text" name="code" list="code-suggestions" value="{{ request('code') }}" placeholder="Contoh: 758 atau 26AB001" class="rounded-lg border-slate-300 text-sm w-36 py-1.5 px-3">
                    <datalist id="code-suggestions">
                        @foreach($codeSuggestions ?? [] as $suggestion)
                            <option value="{{ $suggestion }}"></option>
                        @endforeach
                    </datalist>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Kode Customer</label>
                    <input type="text" name="customer" list="customer-suggestions" value="{{ request('customer') }}" placeholder="A06" class="rounded-lg border-slate-300 text-sm w-36 py-1.5 px-3">
                    <datalist id="customer-suggestions">
                        @foreach($customerSuggestions ?? [] as $suggestion)
                            <option value="{{ $suggestion }}"></option>
                        @endforeach
                    </datalist>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Size</label>
                    <input type="text" name="size" list="size-suggestions" value="{{ request('size') }}" placeholder='1/2"' class="rounded-lg border-slate-300 text-sm w-28 py-1.5 px-3">
                    <datalist id="size-suggestions">
                        @foreach($sizeSuggestions ?? [] as $suggestion)
                            <option value="{{ $suggestion }}"></option>
                        @endforeach
                    </datalist>
                </div>

                <div class="flex items-center gap-2">
                    <button type="submit" class="bg-slate-700 hover:bg-slate-800 text-white text-xs font-bold py-2 px-4 rounded-lg transition-all shadow-sm">
                        Cari
                    </button>
                    <a href="{{ route('lost-wax.assemblies.index') }}" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold py-2 px-4 rounded-lg transition-all border border-slate-200 text-center">
                        Reset
                    </a>
                </div>
            </form>
        </div>

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
    </div>
@endsection
