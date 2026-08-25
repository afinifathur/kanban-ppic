@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Buat Perintah Cetak Baru</h1>
            <p class="text-gray-500 text-[10px]">Tentukan tanggal dan jumlah cetak untuk setiap item terpilih</p>
        </div>
        @php
            $selectionStorageKey = 'lost-wax-print-orders-selection-'.auth()->id().'-'.(auth()->user()->product_scope ?: 'all');
        @endphp
        <a href="{{ route('lost-wax.print-orders.plans') }}" onclick="sessionStorage.removeItem(@json($selectionStorageKey));" class="text-slate-500 hover:text-slate-700 text-xs flex items-center gap-1.5 font-bold">
            <i class="fas fa-arrow-left"></i> Batal & Kembali
        </a>
    </div>
@endsection

@section('content')
    <div class="max-w-6xl mx-auto">
        <form action="{{ route('lost-wax.print-orders.store') }}" method="POST" class="space-y-6">
            @csrf

            <!-- Card Header info -->
            <div class="bg-white shadow-sm rounded-xl border border-slate-200 p-6">
                <h3 class="text-slate-800 font-bold mb-4 flex items-center gap-2 border-b border-slate-100 pb-2">
                    <i class="fas fa-file-invoice text-amber-500"></i> Informasi Dokumen Perintah Cetak
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">No Dokumen Perintah Cetak (Unique)</label>
                        <input type="text" name="print_order_number" value="{{ old('print_order_number', $printOrderNumber) }}" required
                            class="w-full bg-white border @error('print_order_number') border-red-500 @else border-slate-300 @enderror rounded-lg px-3 py-2 text-sm text-slate-700 font-mono focus:outline-none focus:border-amber-500">
                        @error('print_order_number')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Tanggal Cetak / Produksi</label>
                        <input type="date" name="scheduled_date" id="scheduled_date" value="{{ old('scheduled_date', $date) }}" required
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
                                <th class="border border-slate-200 p-3 text-left">Kode Cust</th>
                                <th class="border border-slate-200 p-3 text-left">Customer</th>
                                <th class="border border-slate-200 p-3 text-left">Nama Produk</th>
                                <th class="border border-slate-200 p-3 text-center">Planned Qty</th>
                                <th class="border border-slate-200 p-3 text-center">Belum Dijadwalkan (Sisa)</th>
                                <th class="border border-slate-200 p-3 text-center w-40">Qty Perintah Cetak</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($plans as $index => $plan)
                                @php
                                    $sisa = $plan->qty_remaining_scheduled;
                                    $defaultQty = $sisa > 0 ? $sisa : 1;
                                @endphp
                                <tr class="hover:bg-slate-50/50 transition-colors" data-plan-id="{{ $plan->id }}">
                                    <td class="border border-slate-200 p-3 text-center text-slate-400">
                                        {{ $index + 1 }}
                                        <input type="hidden" name="items[{{ $index }}][production_plan_id]" value="{{ $plan->id }}">
                                    </td>
                                    <td class="border border-slate-200 p-3 font-mono text-xs font-bold text-slate-700">
                                        {{ $plan->code }}
                                    </td>
                                    <td class="border border-slate-200 p-3 font-bold text-slate-700 uppercase">
                                        {{ $plan->customer ?: '-' }}
                                    </td>
                                    <td class="border border-slate-200 p-3">
                                        <div class="font-bold text-slate-800">{{ $plan->item_name }}</div>
                                        <div class="text-[10px] text-slate-500 font-mono">{{ $plan->item_code }} | AISI {{ $plan->aisi ?: '-' }} | Size {{ $plan->size ?: '-' }}</div>
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center font-bold text-slate-600" data-planned-qty="{{ $plan->qty_planned }}">
                                        {{ number_format($plan->qty_planned) }}
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center font-bold text-slate-500" data-remaining-qty="{{ $sisa }}">
                                        @if($sisa < 0)
                                            <span class="text-amber-600">{{ number_format($sisa) }} (Lebih)</span>
                                        @else
                                            <span>{{ number_format($sisa) }}</span>
                                        @endif
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center">
                                        <input type="number" name="items[{{ $index }}][qty_ordered]" 
                                            value="{{ old("items.{$index}.qty_ordered", $defaultQty) }}" 
                                            min="1" required
                                            data-index="{{ $index }}"
                                            class="qty-input w-full bg-white border border-slate-300 rounded px-3 py-1.5 text-sm text-center font-bold text-slate-800 focus:outline-none focus:border-amber-500">
                                        <div class="warning-text text-[10px] text-amber-600 mt-1 font-semibold hidden">
                                            Over-scheduled!
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Submit action -->
            <div class="flex justify-end gap-3">
                <a href="{{ route('lost-wax.print-orders.plans') }}" onclick="sessionStorage.removeItem(@json($selectionStorageKey));" class="bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold py-2 px-6 rounded-lg text-sm transition-all">
                    Batal
                </a>
                <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white font-bold py-2 px-6 rounded-lg text-sm shadow transition-all flex items-center gap-2">
                    <i class="fas fa-save"></i> Simpan Draft
                </button>
            </div>
        </form>
    </div>

    <!-- Alert / Concurrency Logic -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const dateInput = document.getElementById('scheduled_date');
            
            // Watch date input changes to fetch concurrency-safe document number
            if (dateInput) {
                dateInput.addEventListener('change', function () {
                    const selectedDate = dateInput.value;
                    if (!selectedDate) return;
                    
                    // Simple AJAX query to print-order create page to load sequential code
                    axios.get('{{ route("lost-wax.print-orders.create") }}', {
                        params: {
                            scheduled_date: selectedDate,
                            plan_ids: {!! json_encode($plans->pluck('id')) !!}
                        }
                    }).then(function (response) {
                        // Extract print_order_number from response page
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(response.data, 'text/html');
                        const newNumberInput = doc.querySelector('input[name="print_order_number"]');
                        if (newNumberInput) {
                            document.querySelector('input[name="print_order_number"]').value = newNumberInput.value;
                        }
                    }).catch(function (error) {
                        console.error('Gagal mengambil nomor sequential dokumen.', error);
                    });
                });
            }

            // Watch qty inputs for warnings
            const qtyInputs = document.querySelectorAll('.qty-input');
            
            function checkWarning(input) {
                const tr = input.closest('tr');
                const remaining = parseInt(tr.querySelector('[data-remaining-qty]').getAttribute('data-remaining-qty'));
                const inputVal = parseInt(input.value) || 0;
                const warningDiv = tr.querySelector('.warning-text');

                // If input quantity is greater than remaining, trigger a clear warning message
                if (remaining > 0 && inputVal > remaining) {
                    const diff = inputVal - remaining;
                    warningDiv.textContent = 'Penjadwalan lebih (' + diff + ' pcs)';
                    warningDiv.classList.remove('hidden');
                } else if (remaining <= 0 && inputVal > 0) {
                    warningDiv.textContent = 'Penjadwalan lebih (' + inputVal + ' pcs)';
                    warningDiv.classList.remove('hidden');
                } else {
                    warningDiv.classList.add('hidden');
                }
            }

            qtyInputs.forEach(input => {
                input.addEventListener('input', function () {
                    checkWarning(input);
                });
                checkWarning(input);
            });
        });
    </script>
@endsection
