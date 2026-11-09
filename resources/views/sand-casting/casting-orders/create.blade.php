@extends('layouts.app')

@section('top_bar')
    @php
        $selectionStorageKey = 'sand-casting-casting-orders-selection-'.auth()->id().'-'.(auth()->user()->product_scope ?: 'all');
    @endphp
    <div class="flex flex-col sm:flex-row sm:items-center justify-between w-full gap-4">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Buat Dokumen Perintah Cor</h1>
            <p class="text-gray-500 text-[10px]">Tentukan tanggal pelaksanaan cor dan alokasikan kuantitas untuk setiap item rencana</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            {{-- Summary Bar --}}
            <div id="selection-summary-bar" class="flex items-stretch divide-x divide-slate-200 bg-white border border-slate-200 rounded-lg shadow-sm shrink-0">
                <div class="flex items-center gap-2 px-3 py-1.5">
                    <span class="text-blue-500 text-sm"><i class="fas fa-check-square"></i></span>
                    <div class="text-right leading-tight">
                        <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider whitespace-nowrap">Item Terpilih</div>
                        <div id="summary-item-count" class="text-sm font-bold text-slate-800 whitespace-nowrap">{{ count($plans) }} item</div>
                    </div>
                </div>
                <div class="flex items-center gap-2 px-3 py-1.5">
                    <span class="text-indigo-500 text-sm"><i class="fas fa-cubes"></i></span>
                    <div class="text-right leading-tight">
                        <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider whitespace-nowrap">Total Qty Perintah</div>
                        <div id="summary-total-pcs" class="text-sm font-bold text-slate-800 whitespace-nowrap">0 pcs</div>
                    </div>
                </div>
                <div class="flex items-center gap-2 px-3 py-1.5">
                    <span class="text-emerald-500 text-sm"><i class="fas fa-weight-hanging"></i></span>
                    <div class="text-right leading-tight">
                        <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider whitespace-nowrap">Total Estimasi Berat</div>
                        <div id="summary-total-kg" class="text-sm font-bold text-emerald-600 whitespace-nowrap">0.00 kg</div>
                    </div>
                </div>
            </div>

            <a href="{{ route('sand-casting.casting-orders.plans') }}" class="text-slate-500 hover:text-slate-700 text-xs flex items-center gap-1.5 font-bold shrink-0">
                <i class="fas fa-arrow-left"></i> Batal & Kembali
            </a>
        </div>
    </div>
@endsection

@section('content')
    @php
        $selectionStorageKey = 'sand-casting-casting-orders-selection-'.auth()->id().'-'.(auth()->user()->product_scope ?: 'all');
    @endphp
    <div class="max-w-6xl mx-auto space-y-6">
        <form action="{{ route('sand-casting.casting-orders.store') }}" method="POST" class="space-y-6">
            @csrf

            <!-- Card Header info -->
            <div class="bg-white shadow-sm rounded-xl border border-slate-200 p-6">
                <h3 class="text-slate-800 font-bold mb-4 flex items-center gap-2 border-b border-slate-100 pb-2">
                    <i class="fas fa-file-invoice text-blue-600"></i> Informasi Dokumen Perintah Cor
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">No Perintah Cor (Unique)</label>
                        <input type="text" name="casting_order_number" value="{{ old('casting_order_number', $castingOrderNumber) }}" required
                            class="w-full bg-white border @error('casting_order_number') border-red-500 @else border-slate-300 @enderror rounded-lg px-3 py-2 text-sm text-slate-700 font-mono focus:outline-none focus:border-blue-500">
                        @error('casting_order_number')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Tanggal Rencana Cor</label>
                        <input type="date" name="scheduled_date" id="scheduled_date" value="{{ old('scheduled_date', $date) }}" required
                            class="w-full bg-white border @error('scheduled_date') border-red-500 @else border-slate-300 @enderror rounded-lg px-3 py-2 text-sm text-slate-700 focus:outline-none focus:border-blue-500">
                        @error('scheduled_date')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Catatan / Instruksi Khusus (Opsional)</label>
                        <input type="text" name="notes" value="{{ old('notes') }}" placeholder="Misal: Prioritas penuangan sore"
                            class="w-full bg-white border @error('notes') border-red-500 @else border-slate-300 @enderror rounded-lg px-3 py-2 text-sm text-slate-700 focus:outline-none focus:border-blue-500">
                        @error('notes')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <!-- Card Items table -->
            <div class="bg-white shadow-sm rounded-xl border border-slate-200 p-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between border-b border-slate-100 pb-3 mb-4 gap-2">
                    <h3 class="text-slate-800 font-bold flex items-center gap-2">
                        <i class="fas fa-list text-blue-600"></i> Alokasi Item Cor Pasir
                    </h3>
                    <div class="text-xs text-slate-500">
                        Total item dalam dokumen: <span class="font-bold text-slate-800">{{ count($plans) }}</span>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full border-collapse border border-slate-200 text-xs">
                        <thead class="bg-slate-50 text-slate-600 font-bold uppercase tracking-wider">
                            <tr>
                                <th class="border border-slate-200 p-3 text-center w-10">No</th>
                                <th class="border border-slate-200 p-3 text-left">Kode Cust</th>
                                <th class="border border-slate-200 p-3 text-left">Customer</th>
                                <th class="border border-slate-200 p-3 text-left">Nama Produk</th>
                                <th class="border border-slate-200 p-3 text-center">Ukuran / AISI</th>
                                <th class="border border-slate-200 p-3 text-center w-24">Line (1-4)</th>
                                <th class="border border-slate-200 p-3 text-center">Qty Rencana</th>
                                <th class="border border-slate-200 p-3 text-center">Sisa Kuota</th>
                                <th class="border border-slate-200 p-3 text-center w-32 bg-blue-50 text-blue-800">Qty Perintah</th>
                                <th class="border border-slate-200 p-3 text-center">Berat / Unit</th>
                                <th class="border border-slate-200 p-3 text-center">Total Berat</th>
                                <th class="border border-slate-200 p-3 text-left">Catatan Line</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($plans as $index => $plan)
                                @php
                                    $scheduled = (int) ($plan->qty_casting_scheduled ?? 0);
                                    $available = max(0, $plan->qty_planned - $scheduled);
                                    $passedQty = isset($selectedQtyMap[$plan->id]) ? (int) $selectedQtyMap[$plan->id] : $available;
                                    $passedQty = max(1, min($available, $passedQty));
                                    $passedLine = isset($selectedLineMap[$plan->id]) ? (int) $selectedLineMap[$plan->id] : (int) ($plan->line_number ?: 1);
                                    if ($passedLine < 1 || $passedLine > 4) {
                                        $passedLine = 1;
                                    }
                                    $defaultQty = old("items.{$index}.qty_ordered", $passedQty);
                                    $defaultLine = (int) old("items.{$index}.line_number", $passedLine);
                                    $unitWeight = (float) ($plan->weight ?? 0);
                                    $initialSubtotal = ((int) $defaultQty) * $unitWeight;
                                @endphp
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="border border-slate-200 p-3 text-center font-mono text-slate-400">{{ $index + 1 }}</td>
                                    <td class="border border-slate-200 p-3 font-mono font-bold text-blue-700">
                                        {{ $plan->code }}
                                        <input type="hidden" name="items[{{ $index }}][production_plan_id]" value="{{ $plan->id }}">
                                    </td>
                                    <td class="border border-slate-200 p-3 font-medium">{{ $plan->customer ?: '-' }}</td>
                                    <td class="border border-slate-200 p-3 font-semibold text-slate-800">{{ $plan->item_name }}</td>
                                    <td class="border border-slate-200 p-3 text-center font-mono text-slate-600">
                                        {{ $plan->size ?: '-' }} / {{ $plan->aisi ?: '-' }}
                                    </td>
                                    <td class="border border-slate-200 p-2 text-center">
                                        @php
                                            $planLine = (int) ($plan->line_number ?: 0);
                                        @endphp
                                        @if($planLine >= 1 && $planLine <= 4)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                                Line {{ $planLine }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200" title="Line belum ditentukan di Daftar Rencana">
                                                Line -
                                            </span>
                                        @endif
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center font-bold text-slate-900">{{ number_format($plan->qty_planned) }}</td>
                                    <td class="border border-slate-200 p-3 text-center font-bold text-emerald-600" data-remaining-qty="{{ $available }}">
                                        {{ number_format($available) }} pcs
                                    </td>
                                    <td class="border border-slate-200 p-2 bg-blue-50/40">
                                        <input type="number" name="items[{{ $index }}][qty_ordered]"
                                            value="{{ $defaultQty }}"
                                            min="1" max="{{ $available }}" required
                                            data-weight="{{ $unitWeight }}"
                                            class="qty-input w-full bg-white border border-blue-300 rounded-lg px-2.5 py-1.5 text-center text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center font-mono text-slate-500">
                                        {{ $unitWeight > 0 ? number_format($unitWeight, 2).' kg' : '-' }}
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center font-mono font-bold text-emerald-700 row-subtotal-kg">
                                        {{ $initialSubtotal > 0 ? number_format($initialSubtotal, 2).' kg' : '0.00 kg' }}
                                    </td>
                                    <td class="border border-slate-200 p-2">
                                        <input type="text" name="items[{{ $index }}][notes]"
                                            value="{{ old("items.{$index}.notes") }}"
                                            placeholder="Catatan line..."
                                            class="w-full bg-white border border-slate-200 rounded-lg px-2 py-1.5 text-xs text-slate-700 focus:outline-none focus:border-blue-500">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Submit buttons -->
            <div class="flex items-center justify-end gap-3 pt-2">
                <a href="{{ route('sand-casting.casting-orders.plans') }}" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-lg text-xs transition">
                    Batal
                </a>
                <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg text-xs flex items-center gap-2 shadow-md transition">
                    <i class="fas fa-save"></i> Simpan Dokumen Perintah Cor
                </button>
            </div>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const dateInput = document.getElementById('scheduled_date');
            const qtyInputs = document.querySelectorAll('.qty-input');
            const summaryPcs = document.getElementById('summary-total-pcs');
            const summaryKg = document.getElementById('summary-total-kg');

            function formatKg(val) {
                return Number(val || 0).toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                });
            }

            function recalculateTotals() {
                let totalPcs = 0;
                let totalKg = 0;

                qtyInputs.forEach(input => {
                    const qty = parseInt(input.value) || 0;
                    const weight = parseFloat(input.getAttribute('data-weight')) || 0;
                    const subtotal = qty * weight;
                    totalPcs += qty;
                    totalKg += subtotal;

                    const tr = input.closest('tr');
                    if (tr) {
                        const subtotalCell = tr.querySelector('.row-subtotal-kg');
                        if (subtotalCell) {
                            subtotalCell.textContent = formatKg(subtotal) + ' kg';
                        }
                    }
                });

                if (summaryPcs) summaryPcs.textContent = Number(totalPcs).toLocaleString('en-US') + ' pcs';
                if (summaryKg) summaryKg.textContent = formatKg(totalKg) + ' kg';
            }

            if (dateInput) {
                dateInput.addEventListener('change', function () {
                    const selectedDate = dateInput.value;
                    if (!selectedDate) return;

                    axios.get('{{ route("sand-casting.casting-orders.create") }}', {
                        params: {
                            scheduled_date: selectedDate,
                            plan_ids: {!! json_encode($plans->pluck('id')) !!}
                        }
                    }).then(function (response) {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(response.data, 'text/html');
                        const newNumberInput = doc.querySelector('input[name="casting_order_number"]');
                        if (newNumberInput) {
                            document.querySelector('input[name="casting_order_number"]').value = newNumberInput.value;
                        }
                    }).catch(function (error) {
                        console.error('Gagal mengambil nomor sequential dokumen.', error);
                    });
                });
            }

            qtyInputs.forEach(input => {
                input.addEventListener('input', recalculateTotals);
            });

            recalculateTotals();
        });
    </script>
@endsection
