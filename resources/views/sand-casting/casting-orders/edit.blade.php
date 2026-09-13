@extends('layouts.app')

@section('top_bar')
    <div class="flex flex-col sm:flex-row sm:items-center justify-between w-full gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-lg font-bold text-slate-800 leading-tight">Edit Draft Perintah Cor</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800 border border-amber-200">DRAFT</span>
            </div>
            <p class="text-gray-500 text-[10px]">Ubah kuantitas atau tambahkan item rencana baru ke dalam draft dokumen ini</p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('sand-casting.casting-orders.show', $castingOrder) }}" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold px-3 py-2 rounded-lg text-xs flex items-center gap-1.5 shadow-sm transition">
                <i class="fas fa-arrow-left"></i> Batal & Kembali
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="max-w-6xl mx-auto space-y-6">
        <!-- Flash Messages -->
        @if(session('success'))
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm px-4 py-3 rounded-lg flex items-center justify-between shadow-sm">
                <div class="flex items-center gap-2">
                    <i class="fas fa-check-circle text-emerald-500 text-base"></i>
                    <span>{{ session('success') }}</span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-emerald-600 text-sm">&times;</button>
            </div>
        @endif

        @if(session('error'))
            <div class="bg-red-50 border border-red-200 text-red-800 text-sm px-4 py-3 rounded-lg flex items-center justify-between shadow-sm">
                <div class="flex items-center gap-2">
                    <i class="fas fa-exclamation-circle text-red-500 text-base"></i>
                    <span>{{ session('error') }}</span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-red-400 hover:text-red-600 text-sm">&times;</button>
            </div>
        @endif

        <!-- Form Edit Header & Lines -->
        <form action="{{ route('sand-casting.casting-orders.update', $castingOrder) }}" method="POST" class="space-y-6">
            @csrf
            @method('PUT')

            <!-- Header Info -->
            <div class="bg-white shadow-sm rounded-xl border border-slate-200 p-6">
                <h3 class="text-slate-800 font-bold mb-4 flex items-center gap-2 border-b border-slate-100 pb-2">
                    <i class="fas fa-file-invoice text-blue-600"></i> Informasi Dokumen
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">No Perintah Cor</label>
                        <input type="text" name="casting_order_number" value="{{ old('casting_order_number', $castingOrder->casting_order_number) }}" required
                            class="w-full bg-white border @error('casting_order_number') border-red-500 @else border-slate-300 @enderror rounded-lg px-3 py-2 text-sm text-slate-700 font-mono focus:outline-none focus:border-blue-500">
                        @error('casting_order_number')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Tanggal Rencana Cor</label>
                        <input type="date" name="scheduled_date" value="{{ old('scheduled_date', $castingOrder->scheduled_date ? $castingOrder->scheduled_date->format('Y-m-d') : '') }}" required
                            class="w-full bg-white border @error('scheduled_date') border-red-500 @else border-slate-300 @enderror rounded-lg px-3 py-2 text-sm text-slate-700 focus:outline-none focus:border-blue-500">
                        @error('scheduled_date')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Catatan</label>
                        <input type="text" name="notes" value="{{ old('notes', $castingOrder->notes) }}"
                            class="w-full bg-white border @error('notes') border-red-500 @else border-slate-300 @enderror rounded-lg px-3 py-2 text-sm text-slate-700 focus:outline-none focus:border-blue-500">
                    </div>
                </div>
            </div>

            <!-- Existing Lines Table -->
            <div class="bg-white shadow-sm rounded-xl border border-slate-200 p-6">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
                    <h3 class="text-slate-800 font-bold flex items-center gap-2">
                        <i class="fas fa-list text-blue-600"></i> Item dalam Dokumen
                    </h3>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full border-collapse border border-slate-200 text-xs">
                        <thead class="bg-slate-50 text-slate-600 font-bold uppercase tracking-wider">
                            <tr>
                                <th class="border border-slate-200 p-3 text-center w-12">No</th>
                                <th class="border border-slate-200 p-3 text-left">Kode Cust</th>
                                <th class="border border-slate-200 p-3 text-left">Customer</th>
                                <th class="border border-slate-200 p-3 text-left">Nama Produk</th>
                                <th class="border border-slate-200 p-3 text-center">Ukuran / AISI</th>
                                <th class="border border-slate-200 p-3 text-center">Qty Rencana</th>
                                <th class="border border-slate-200 p-3 text-center w-36 bg-blue-50 text-blue-800">Qty Perintah</th>
                                <th class="border border-slate-200 p-3 text-left">Catatan Line</th>
                                <th class="border border-slate-200 p-3 text-center w-16">Hapus</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($castingOrder->lines as $index => $line)
                                @php
                                    $plan = $line->productionPlan;
                                    $otherSum = $plan ? (int) $plan->castingOrderLines()->where('id', '!=', $line->id)->whereHas('castingOrder', fn($q) => $q->whereIn('status', ['DRAFT', 'ISSUED']))->sum('qty_ordered') : 0;
                                    $maxAllowed = $plan ? max(1, $plan->qty_planned - $otherSum) : 999999;
                                @endphp
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="border border-slate-200 p-3 text-center font-mono text-slate-400">{{ $index + 1 }}</td>
                                    <td class="border border-slate-200 p-3 font-mono font-bold text-blue-700">
                                        {{ $line->code }}
                                        <input type="hidden" name="items[{{ $index }}][id]" value="{{ $line->id }}">
                                    </td>
                                    <td class="border border-slate-200 p-3 font-medium">{{ $line->customer ?: '-' }}</td>
                                    <td class="border border-slate-200 p-3 font-semibold text-slate-800">{{ $line->item_name }}</td>
                                    <td class="border border-slate-200 p-3 text-center font-mono text-slate-600">
                                        {{ $line->size ?: '-' }} / {{ $line->aisi ?: '-' }}
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center font-bold text-slate-900">
                                        {{ $plan ? number_format($plan->qty_planned) : '-' }}
                                    </td>
                                    <td class="border border-slate-200 p-2 bg-blue-50/40">
                                        <input type="number" name="items[{{ $index }}][qty_ordered]"
                                            value="{{ old("items.{$index}.qty_ordered", $line->qty_ordered) }}"
                                            min="1" max="{{ $maxAllowed }}" required
                                            class="w-full bg-white border border-blue-300 rounded-lg px-2 py-1 text-center text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        <div class="text-[9px] text-slate-400 text-center mt-0.5">Maks: {{ number_format($maxAllowed) }} pcs</div>
                                    </td>
                                    <td class="border border-slate-200 p-2">
                                        <input type="text" name="items[{{ $index }}][notes]"
                                            value="{{ old("items.{$index}.notes", $line->notes) }}"
                                            placeholder="Catatan line..."
                                            class="w-full bg-white border border-slate-200 rounded-lg px-2 py-1 text-xs text-slate-700 focus:outline-none focus:border-blue-500">
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center">
                                        <button type="button" onclick="confirmDeleteLine('{{ $line->id }}', '{{ $line->code }}')"
                                            class="text-red-400 hover:text-red-600 p-1 font-bold" title="Hapus item ini">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex justify-end pt-4">
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg text-xs flex items-center gap-2 shadow-sm transition">
                        <i class="fas fa-save"></i> Perbarui Dokumen
                    </button>
                </div>
            </div>
        </form>

        <!-- Hidden delete line forms -->
        @foreach($castingOrder->lines as $line)
            <form id="delete-line-form-{{ $line->id }}" action="{{ route('sand-casting.casting-orders.lines.destroy', [$castingOrder, $line]) }}" method="POST" class="hidden">
                @csrf
                @method('DELETE')
            </form>
        @endforeach

        <!-- Add New Plan Line Card -->
        @if($availablePlans->isNotEmpty())
            <div class="bg-white shadow-sm rounded-xl border border-slate-200 p-6">
                <h3 class="text-slate-800 font-bold mb-4 flex items-center gap-2 border-b border-slate-100 pb-2">
                    <i class="fas fa-plus-circle text-emerald-600"></i> Tambah Item Rencana ke Dokumen
                </h3>

                <form action="{{ route('sand-casting.casting-orders.lines.store', $castingOrder) }}" method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                    @csrf
                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Pilih Item Rencana Cor</label>
                        <select name="production_plan_id" id="add_production_plan_id" required onchange="updateMaxAddQty()"
                            class="w-full bg-slate-50 border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-blue-500">
                            <option value="">-- Pilih Item Rencana --</option>
                            @foreach($availablePlans as $plan)
                                <option value="{{ $plan->id }}" data-remaining="{{ $plan->qty_remaining_casting_scheduled }}">
                                    {{ $plan->code }} — {{ $plan->item_name }} (Sisa: {{ number_format($plan->qty_remaining_casting_scheduled) }} pcs)
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Qty Perintah</label>
                        <input type="number" name="qty_ordered" id="add_qty_ordered" min="1" required placeholder="Jumlah pcs"
                            class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs font-bold text-slate-800 focus:outline-none focus:border-blue-500">
                    </div>

                    <div>
                        <button type="submit" class="w-full px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg text-xs flex items-center justify-center gap-2 shadow-sm transition">
                            <i class="fas fa-plus"></i> Tambahkan Item
                        </button>
                    </div>
                </form>
            </div>
        @endif
    </div>

    <script>
        function confirmDeleteLine(lineId, code) {
            if (confirm('Hapus item ' + code + ' dari draft Perintah Cor ini? Kuantitas akan dikembalikan ke Rencana Cor.')) {
                const form = document.getElementById('delete-line-form-' + lineId);
                if (form) form.submit();
            }
        }

        function updateMaxAddQty() {
            const select = document.getElementById('add_production_plan_id');
            const qtyInput = document.getElementById('add_qty_ordered');
            const selectedOption = select.options[select.selectedIndex];
            const remaining = selectedOption ? parseInt(selectedOption.getAttribute('data-remaining')) || 1 : 1;

            if (qtyInput) {
                qtyInput.max = remaining;
                qtyInput.value = remaining;
            }
        }
    </script>
@endsection
