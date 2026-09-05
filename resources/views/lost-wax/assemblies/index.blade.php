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
                                <div class="flex items-center justify-end gap-1.5">
                                    <a href="{{ route('lost-wax.assemblies.create', $line) }}" class="inline-flex items-center gap-1 bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold py-1.5 px-3 rounded-lg transition-all shadow-sm">
                                        <i class="fas fa-plus"></i> Buat WO Rangkai
                                    </a>
                                    @if($line->qty_available_for_rangkai > 0)
                                        <button type="button" onclick="openIndexScrapModal({{ $line->id }}, '{{ $line->code }}', '{{ addslashes($line->item_name) }}', {{ $line->qty_available_for_rangkai }})" 
                                            title="Afkir Sisa Lilin (Scrap)" class="p-1.5 rounded-lg bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 transition-colors text-xs font-semibold">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    @endif
                                </div>
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

    <!-- MODAL AFKIR SISA LILIN (INDEX) -->
    <div id="indexScrapModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4" aria-labelledby="index-scrap-title" role="dialog" aria-modal="true">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl border border-slate-200 transform transition-all">
            <form id="indexScrapForm" method="POST" action="">
                @csrf
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-red-100 text-red-700 flex items-center justify-center shrink-0">
                        <i class="fas fa-trash-alt text-xl"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <span class="text-[10px] font-extrabold uppercase text-red-700 tracking-wider block">Afkir / Tutup Sisa Lilin</span>
                        <h3 class="text-base font-black text-slate-900 leading-snug" id="index-scrap-title">
                            AFKIR SISA LILIN CETAK?
                        </h3>
                        <p class="text-xs font-bold text-slate-700 mt-0.5" id="indexScrapItemInfo">-</p>
                    </div>
                </div>

                <div class="mt-4 p-3 bg-red-50 border border-red-200 rounded-xl text-xs text-red-900">
                    <div class="font-bold flex items-center gap-1.5 mb-1 text-red-900">
                        <i class="fas fa-exclamation-triangle"></i> Perhatian:
                    </div>
                    <p class="text-[11px] leading-relaxed">
                        Kuantitas lilin yang diafkir akan <strong>ditutup secara permanen</strong> dari saldo tersedia sistem, tidak dapat digunakan untuk assembly/sumber tambahan, dan dicatat dalam audit trail.
                    </p>
                </div>

                <div class="mt-4 space-y-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-800 mb-1">
                            Jumlah Lilin yang Diafkir (pcs) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="qty_to_close" id="indexScrapQtyInput" min="1" required
                            class="w-full rounded-lg border-slate-300 text-xs focus:border-red-500 focus:ring-red-500 shadow-sm font-bold">
                        <span class="text-[10px] text-slate-400 block mt-0.5" id="indexScrapMaxHint">Maksimal: 0 pcs</span>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-800 mb-1">
                            Alasan Afkir / Scrap <span class="text-red-500">*</span>
                        </label>
                        <textarea name="excess_closure_reason" id="indexScrapReasonInput" rows="3" required
                            placeholder="Contoh: Lilin cacat/patah, pattern rusak, disposal sisa cetak..."
                            class="w-full rounded-lg border-slate-300 text-xs focus:border-red-500 focus:ring-red-500 shadow-sm leading-relaxed"></textarea>
                        <span class="text-[10px] text-slate-400 block">Alasan afkir wajib diisi untuk rekam jejak audit.</span>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                    <button type="button" onclick="closeIndexScrapModal()" class="px-4 py-2.5 text-xs font-bold text-slate-600 hover:text-slate-800 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="px-5 py-2.5 text-xs font-black text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors shadow-sm flex items-center gap-1.5">
                        <i class="fas fa-ban"></i> Konfirmasi Afkir Sisa
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openIndexScrapModal(lineId, lineCode, itemName, maxAvailable) {
            const modal = document.getElementById('indexScrapModal');
            const form = document.getElementById('indexScrapForm');
            const itemInfo = document.getElementById('indexScrapItemInfo');
            const qtyInput = document.getElementById('indexScrapQtyInput');
            const reasonInput = document.getElementById('indexScrapReasonInput');
            const maxHint = document.getElementById('indexScrapMaxHint');

            form.action = `/lost-wax/assemblies/lines/${lineId}/close-excess`;
            itemInfo.textContent = `${lineCode} • ${itemName}`;
            qtyInput.max = maxAvailable;
            qtyInput.value = maxAvailable;
            maxHint.textContent = `Maksimal tersedia: ${maxAvailable} pcs`;
            reasonInput.value = '';

            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => reasonInput.focus(), 50);
        }

        function closeIndexScrapModal() {
            const modal = document.getElementById('indexScrapModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    </script>
@endsection
