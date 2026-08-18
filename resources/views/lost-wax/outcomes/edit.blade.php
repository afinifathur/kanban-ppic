@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Catat Hasil Perintah Cetak</h1>
            <p class="text-gray-500 text-[10px]">Dokumen {{ $printOrder->print_order_number }} &mdash; {{ $printOrder->scheduled_date->format('d-m-Y') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('lost-wax.outcomes.index') }}" class="text-slate-500 hover:text-slate-700 text-xs">
                <i class="fas fa-arrow-left"></i> Kembali
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="max-w-6xl">
        <form method="POST" action="{{ route('lost-wax.outcomes.update', $printOrder) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h2 class="font-bold text-slate-800 mb-4">Item Perintah Cetak</h2>

                <div class="space-y-4">
                    @foreach($printOrder->lines as $index => $line)
                        <div class="p-4 rounded-xl border border-slate-200 bg-slate-50 flex flex-col md:flex-row md:items-center gap-4">
                            <input type="hidden" name="items[{{ $index }}][id]" value="{{ $line->id }}">
                            
                            <!-- Snapshot info -->
                            <div class="flex-1 min-w-0">
                                <div class="font-bold text-slate-800 text-sm truncate">{{ $line->item_name }}</div>
                                <div class="text-xs text-slate-500 flex flex-wrap gap-x-3 gap-y-1 mt-1">
                                    <span>Kode: <strong>{{ $line->code ?? '-' }}</strong></span>
                                    <span>Cust: <strong>{{ $line->customer ?? '-' }}</strong></span>
                                    <span>AISI: <strong>{{ $line->aisi ?? '-' }}</strong></span>
                                    <span>Size: <strong>{{ $line->size ?? '-' }}</strong></span>
                                    <span>Qty Perintah: <strong class="text-slate-700">{{ number_format($line->qty_ordered) }} pcs</strong></span>
                                </div>
                                
                                @php
                                    $allocated = $line->trees->sum('quantity');
                                @endphp
                                @if($allocated > 0)
                                    <div class="mt-2 text-xs text-amber-700 bg-amber-50 border border-amber-100 rounded-lg px-2.5 py-1 inline-flex items-center gap-1">
                                        <i class="fas fa-info-circle"></i>
                                        Sebab sudah dirangkai <strong>{{ $allocated }} pcs</strong>, Hasil (Good) minimal harus <strong>{{ $allocated }} pcs</strong>.
                                    </div>
                                @endif
                            </div>

                            <!-- Input fields -->
                            <div class="flex flex-wrap items-end gap-3">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 mb-1">Hasil (Good) <span class="text-red-500">*</span></label>
                                    <input type="number" name="items[{{ $index }}][qty_actual_good]" 
                                        value="{{ old("items.{$index}.qty_actual_good", $line->qty_actual_good ?? $line->qty_ordered) }}" 
                                        min="0" required class="w-28 rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 mb-1">Rusak (Defect) <span class="text-red-500">*</span></label>
                                    <input type="number" name="items[{{ $index }}][qty_actual_defect]" 
                                        value="{{ old("items.{$index}.qty_actual_defect", $line->qty_actual_defect ?? 0) }}" 
                                        min="0" required class="w-28 rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 mb-1">Kapasitas Tree <span class="text-red-500">*</span></label>
                                    <input type="number" name="items[{{ $index }}][standard_tree_capacity]" 
                                        value="{{ old("items.{$index}.standard_tree_capacity", $line->standard_tree_capacity ?? 20) }}" 
                                        min="1" required class="w-28 rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500">
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <a href="{{ route('lost-wax.outcomes.index') }}" class="bg-slate-200 hover:bg-slate-300 text-slate-700 text-sm font-bold py-2 px-5 rounded-lg transition-all">
                    Batal
                </a>
                <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white text-sm font-bold py-2 px-6 rounded-lg transition-all shadow-sm">
                    <i class="fas fa-save mr-1"></i> Simpan Hasil Cetak
                </button>
            </div>
        </form>
    </div>
@endsection
