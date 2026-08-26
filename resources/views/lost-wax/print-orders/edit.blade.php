@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full gap-4">
        <div class="shrink-0">
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Edit Perintah Cetak: {{ $printOrder->print_order_number }}</h1>
            <p class="text-gray-500 text-[10px]">Ubah informasi dokumen atau kuantitas untuk Perintah Cetak draft ini</p>
        </div>
        <div class="flex items-stretch divide-x divide-slate-200 bg-white border border-slate-200 rounded-lg shadow-sm shrink-0">
            <div class="flex items-center gap-2 px-3 py-1.5">
                <span class="text-blue-500 text-sm" aria-hidden="true"><i class="fas fa-list"></i></span>
                <div class="text-right leading-tight">
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider whitespace-nowrap">Item</div>
                    <div id="summary-item-count" class="text-sm font-bold text-slate-800 whitespace-nowrap" aria-live="polite">0 ITEM</div>
                </div>
            </div>
            <div class="flex items-center gap-2 px-3 py-1.5">
                <span class="text-indigo-500 text-sm" aria-hidden="true"><i class="fas fa-cubes"></i></span>
                <div class="text-right leading-tight">
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider whitespace-nowrap">Total Qty (PCS)</div>
                    <div id="summary-total-pcs" class="text-sm font-bold text-slate-800 whitespace-nowrap" aria-live="polite">0 pcs</div>
                </div>
            </div>
            <div class="flex items-center gap-2 px-3 py-1.5">
                <span class="text-emerald-500 text-sm" aria-hidden="true"><i class="fas fa-weight-hanging"></i></span>
                <div class="text-right leading-tight">
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider whitespace-nowrap">Total Berat (KG)</div>
                    <div id="summary-total-kg" class="text-sm font-bold text-emerald-600 whitespace-nowrap" aria-live="polite">0 kg</div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <div class="max-w-6xl mx-auto">
        <form action="{{ route('lost-wax.print-orders.update', $printOrder) }}" method="POST" class="space-y-6">
            @csrf
            @method('PUT')

            <!-- Card Header info -->
            <div class="bg-white shadow-sm rounded-xl border border-slate-200 p-6">
                <h3 class="text-slate-800 font-bold mb-4 flex items-center gap-2 border-b border-slate-100 pb-2">
                    <i class="fas fa-file-invoice text-amber-500"></i> Informasi Dokumen Perintah Cetak
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">No Dokumen Perintah Cetak</label>
                        <input type="text" name="print_order_number" value="{{ old('print_order_number', $printOrder->print_order_number) }}" required
                            class="w-full bg-white border @error('print_order_number') border-red-500 @else border-slate-300 @enderror rounded-lg px-3 py-2 text-sm text-slate-700 font-mono focus:outline-none focus:border-amber-500">
                        @error('print_order_number')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Tanggal Cetak / Produksi</label>
                        <input type="date" name="scheduled_date" id="scheduled_date" value="{{ old('scheduled_date', $printOrder->scheduled_date->format('Y-m-d')) }}" required
                            class="w-full bg-white border @error('scheduled_date') border-red-500 @else border-slate-300 @enderror rounded-lg px-3 py-2 text-sm text-slate-700 focus:outline-none focus:border-amber-500">
                        @error('scheduled_date')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <!-- Card Items table -->
            <div class="bg-white shadow-sm rounded-xl border border-slate-200 p-6">
                <h3 class="text-slate-800 font-bold mb-4 flex items-center gap-2 border-b border-slate-100 pb-2">
                    <i class="fas fa-list text-amber-500"></i> Daftar Item Cetak Lilin
                </h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full border-collapse border border-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="border border-slate-200 p-3 text-center w-12">No</th>
                                <th class="border border-slate-200 p-3 text-left">Kode Cust (Snapshot)</th>
                                <th class="border border-slate-200 p-3 text-left">Customer (Snapshot)</th>
                                <th class="border border-slate-200 p-3 text-left">Nama Produk (Snapshot)</th>
                                <th class="border border-slate-200 p-3 text-center">Planned Qty</th>
                                <th class="border border-slate-200 p-3 text-center">Belum Dijadwalkan (Sisa)</th>
                                <th class="border border-slate-200 p-3 text-center w-40">Qty Perintah Cetak</th>
                                <th class="border border-slate-200 p-3 text-center w-16">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($printOrder->lines as $index => $line)
                                @php
                                    $plan = $line->productionPlan;
                                    // Sisa excluding current line quantity (so we add it back to show the sisa available before this line was made)
                                    $sisa = $plan ? ($plan->qty_remaining_scheduled + $line->qty_ordered) : 0;
                                @endphp
                                <tr class="hover:bg-slate-50/50 transition-colors">
                                    <td class="border border-slate-200 p-3 text-center text-slate-400">
                                        {{ $index + 1 }}
                                        <input type="hidden" name="items[{{ $index }}][id]" value="{{ $line->id }}">
                                    </td>
                                    <td class="border border-slate-200 p-3 font-mono text-xs font-bold text-slate-700">
                                        {{ $line->code }}
                                    </td>
                                    <td class="border border-slate-200 p-3 font-bold text-slate-700 uppercase">
                                        {{ $line->customer ?: '-' }}
                                    </td>
                                    <td class="border border-slate-200 p-3">
                                        <div class="font-bold text-slate-800">{{ $line->item_name }}</div>
                                        <div class="text-[10px] text-slate-500 font-mono">Size {{ $line->size ?: '-' }} | AISI {{ $line->aisi ?: '-' }}</div>
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center font-bold text-slate-600">
                                        {{ $plan ? number_format($plan->qty_planned) : '-' }}
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center font-bold text-slate-500" data-remaining-qty="{{ $sisa }}">
                                        @if($plan)
                                            @if($sisa < 0)
                                                <span class="text-amber-600">{{ number_format($sisa) }} (Lebih)</span>
                                            @else
                                                <span>{{ number_format($sisa) }}</span>
                                            @endif
                                        @else
                                            <span class="text-slate-400 font-normal italic">Rencana dihapus</span>
                                        @endif
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center">
                                        <input type="number" name="items[{{ $index }}][qty_ordered]" 
                                            value="{{ old("items.{$index}.qty_ordered", $line->qty_ordered) }}" 
                                            min="1" required
                                            data-weight-per-piece="{{ $line->productionPlan?->weight ?? 0 }}"
                                            class="qty-input w-full bg-white border border-slate-300 rounded px-3 py-1.5 text-sm text-center font-bold text-slate-800 focus:outline-none focus:border-amber-500">
                                        <div class="warning-text text-[10px] text-amber-600 mt-1 font-semibold hidden">
                                            Over-scheduled!
                                        </div>
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center">
                                        <button type="button"
                                            data-delete-url="{{ route('lost-wax.print-orders.lines.destroy', [$printOrder, $line->id]) }}"
                                            data-line-code="{{ $line->code }}"
                                            data-line-qty="{{ $line->qty_ordered }}"
                                            class="delete-line-btn bg-red-50 hover:bg-red-100 text-red-600 font-bold py-1.5 px-2.5 rounded text-xs border border-red-200 transition-all"
                                            title="Hapus Item">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Submit action -->
            <div class="flex justify-end gap-3">
                <a href="{{ route('lost-wax.print-orders.show', $printOrder) }}" class="bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold py-2 px-6 rounded-lg text-sm transition-all">
                    Batal
                </a>
                <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white font-bold py-2 px-6 rounded-lg text-sm shadow transition-all flex items-center gap-2">
                    <i class="fas fa-save"></i> Perbarui Perintah Cetak
                </button>
            </div>
        </form>
    </div>

    <!-- Hidden helper form for deleting a single line -->
    <form id="delete-line-form" method="POST" class="hidden">
        @csrf
        @method('DELETE')
    </form>

    <!-- Alert / Concurrency Logic -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Watch qty inputs for warnings
            const qtyInputs = document.querySelectorAll('.qty-input');

            const summaryTotalPcsEl = document.getElementById('summary-total-pcs');
            const summaryTotalKgEl = document.getElementById('summary-total-kg');
            const summaryItemCountEl = document.getElementById('summary-item-count');

            function formatPcs(value) {
                return Number(value || 0).toLocaleString('en-US');
            }

            function formatKg(value) {
                const rounded = Math.round((value || 0) * 100) / 100;
                return Number(rounded).toLocaleString('en-US', { maximumFractionDigits: 2 });
            }

            function recalcSummary() {
                let totalPcs = 0;
                let totalKg = 0;
                let itemCount = 0;

                qtyInputs.forEach(function (input) {
                    const qty = parseInt(input.value, 10) || 0;
                    const weight = parseFloat(input.getAttribute('data-weight-per-piece')) || 0;

                    totalPcs += qty;
                    totalKg += qty * weight;
                    itemCount += 1;
                });

                if (summaryTotalPcsEl) {
                    summaryTotalPcsEl.textContent = formatPcs(totalPcs) + ' pcs';
                }
                if (summaryTotalKgEl) {
                    summaryTotalKgEl.textContent = formatKg(totalKg) + ' kg';
                }
                if (summaryItemCountEl) {
                    summaryItemCountEl.textContent = itemCount + ' ITEM';
                }
            }

            function checkWarning(input) {
                const tr = input.closest('tr');
                const remainingCell = tr.querySelector('[data-remaining-qty]');
                if (!remainingCell) return;
                
                const remaining = parseInt(remainingCell.getAttribute('data-remaining-qty')) || 0;
                const inputVal = parseInt(input.value) || 0;
                const warningDiv = tr.querySelector('.warning-text');

                // Check if user has scheduled more than what's available
                if (remaining > 0 && inputVal > remaining) {
                    const diff = inputVal - remaining;
                    warningDiv.textContent = 'Penjadwalan lebih (' + diff + ' pcs)';
                    warningDiv.classList.remove('hidden');
                } else {
                    warningDiv.classList.add('hidden');
                }
            }

            qtyInputs.forEach(input => {
                input.addEventListener('input', function () {
                    checkWarning(input);
                    recalcSummary();
                });
                checkWarning(input);
            });

            recalcSummary();

            // Delete single line (DRAFT-only)
            const deleteForm = document.getElementById('delete-line-form');
            document.querySelectorAll('.delete-line-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const url = btn.getAttribute('data-delete-url');
                    const code = btn.getAttribute('data-line-code');
                    const qty = btn.getAttribute('data-line-qty');

                    const message = 'Hapus item ' + code + ' dari Perintah Cetak?\n\n' + qty + ' pcs akan dikembalikan ke Rencana Cetak.';
                    if (confirm(message) && deleteForm) {
                        deleteForm.action = url;
                        deleteForm.submit();
                    }
                });
            });
        });
    </script>
@endsection
