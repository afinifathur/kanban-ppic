@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Detail Tree</h1>
            <p class="text-gray-500 text-[10px]">{{ $tree->barcode }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('lost-wax.trees.traveler', $tree) }}" target="_blank"
                class="bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold py-1.5 px-3 rounded">
                <i class="fas fa-print"></i> Print Traveler
            </a>
            <a href="{{ route('lost-wax.trees.history', $tree) }}"
                class="bg-slate-600 hover:bg-slate-700 text-white text-xs font-bold py-1.5 px-3 rounded">
                <i class="fas fa-history"></i> Riwayat Scan
            </a>
            <a href="{{ route('lost-wax.trees.index') }}" class="text-slate-500 hover:text-slate-700 text-xs">
                <i class="fas fa-arrow-left"></i> Kembali
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="space-y-6 max-w-4xl">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <div class="text-center mb-6">
                <img src="{{ route('lost-wax.trees.barcode', $tree) }}" alt="Barcode" class="mx-auto mb-2" style="max-width: 300px;">
                <div class="text-lg font-bold text-slate-800">{{ $tree->human_barcode }}</div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="rounded-lg bg-slate-50 p-3">
                    <div class="text-xs text-slate-500">Tree #</div>
                    <div class="font-bold text-slate-800">{{ str_pad((string) $tree->tree_number, 3, '0', STR_PAD_LEFT) }}</div>
                </div>
                <div class="rounded-lg bg-slate-50 p-3">
                    <div class="text-xs text-slate-500">Quantity</div>
                    <div class="font-bold text-slate-800">{{ number_format($tree->quantity) }} pcs</div>
                </div>
                <div class="rounded-lg bg-slate-50 p-3">
                    <div class="text-xs text-slate-500">Status</div>
                    <div class="font-bold text-slate-800">{{ ucfirst(str_replace('_', ' ', $tree->status)) }}</div>
                </div>
                <div class="rounded-lg bg-slate-50 p-3">
                    <div class="text-xs text-slate-500">Production Date</div>
                    <div class="font-bold text-slate-800">{{ $tree->production_date->format('d-m-Y') }}</div>
                </div>
                <div class="rounded-lg bg-slate-50 p-3">
                    <div class="text-xs text-slate-500">Family Code</div>
                    <div class="font-bold text-slate-800">{{ $tree->family_code }}</div>
                </div>
                <div class="rounded-lg bg-slate-50 p-3">
                    <div class="text-xs text-slate-500">Daily Sequence</div>
                    <div class="font-bold text-slate-800">{{ str_pad((string) $tree->daily_sequence, 3, '0', STR_PAD_LEFT) }}</div>
                </div>
            </div>

            <div class="border-t border-slate-200 pt-4">
                @if($tree->work_order_id)
                    <div class="text-xs text-slate-500 mb-2">Work Order</div>
                    <a href="{{ route('lost-wax.work-orders.show', $tree->workOrder) }}" class="text-amber-600 hover:text-amber-700 font-semibold">
                        {{ $tree->getSourceCode() }}
                    </a>
                    <div class="text-sm text-slate-600 mt-1">
                        {{ $tree->getSourceItemCode() ?? '-' }}
                        &mdash;
                        {{ $tree->getSourceProduct() ?? '-' }}
                    </div>
                    @if($tree->plan)
                        <div class="text-sm text-slate-600 mt-1">
                            Wave {{ str_pad((string) $tree->plan->wave_number, 3, '0', STR_PAD_LEFT) }}
                            ({{ $tree->plan->plan_type }}: {{ number_format($tree->plan->planned_quantity) }} pcs)
                        </div>
                    @endif
                @elseif($tree->lost_wax_print_order_line_id)
                    <div class="text-xs text-slate-500 mb-2">Perintah Cetak</div>
                    <a href="{{ route('lost-wax.print-orders.show', $tree->printOrderLine->lost_wax_print_order_id) }}" class="text-amber-600 hover:text-amber-700 font-semibold">
                        {{ $tree->getSourcePrintOrderNumber() }}
                    </a>
                    <div class="text-sm text-slate-600 mt-1">
                        Customer Code: {{ $tree->getSourceCode() ?? '-' }} ({{ $tree->getSourceCustomer() ?? '-' }})
                    </div>
                    <div class="text-sm text-slate-600 mt-1">
                        Item: {{ $tree->getSourceProduct() ?? '-' }}
                    </div>
                    @if($tree->getSourceSize() || $tree->getSourceAisi())
                        <div class="text-sm text-slate-600 mt-1">
                            Size: {{ $tree->getSourceSize() ?? '-' }} &middot; AISI: {{ $tree->getSourceAisi() ?? '-' }}
                        </div>
                    @endif
                @endif
            </div>
        </div>

        @if($tree->is_correctable)
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h2 class="font-bold text-slate-800 mb-3">Koreksi Quantity</h2>
                <form method="POST" action="{{ route('lost-wax.trees.update', $tree) }}" class="flex items-end gap-3">
                    @csrf
                    @method('PATCH')
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Quantity (pcs)</label>
                        <input type="number" name="quantity" value="{{ $tree->quantity }}" min="1"
                            class="w-32 rounded-lg border-slate-300 text-sm">
                    </div>
                    <button type="submit" class="bg-slate-700 hover:bg-slate-800 text-white text-sm font-bold py-2 px-4 rounded-lg">
                        <i class="fas fa-pen"></i> Koreksi
                    </button>
                </form>
            </div>
        @endif
    </div>
@endsection
