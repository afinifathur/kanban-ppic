@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Work Order Lost Wax</h1>
            <p class="text-gray-500 text-[10px]">Daftar ET, plan/wave, dan WIP sebelum tree</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('lost-wax.work-orders.create') }}"
                class="bg-white hover:bg-slate-50 text-slate-700 font-bold py-1.5 px-3 rounded shadow text-xs border border-slate-300 flex items-center gap-2">
                <i class="fas fa-pen"></i> Input Manual
            </a>
            <a href="{{ route('lost-wax.work-orders.bulk.create') }}"
                class="bg-amber-600 hover:bg-amber-700 text-white font-bold py-1.5 px-3 rounded shadow text-xs flex items-center gap-2">
                <i class="fas fa-copy"></i> Bulk Input
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="space-y-4">
        @forelse($workOrders as $workOrder)
            <a href="{{ route('lost-wax.work-orders.show', $workOrder) }}"
                class="block bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow-md hover:border-amber-300 transition-all group">
                <div class="flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 mb-1 flex-wrap">
                            <span class="text-slate-800 font-bold text-base group-hover:text-amber-600 transition-colors">{{ $workOrder->et_code }}</span>
                            <span class="text-[10px] bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full">{{ $workOrder->status }}</span>
                        </div>
                        <div class="text-sm text-slate-500 truncate">
                            {{ optional($workOrder->itemReference)->item_code_snapshot ?? '-' }} | {{ optional($workOrder->itemReference)->item_name_snapshot ?? '-' }}
                        </div>
                        <div class="text-xs text-slate-400 mt-1 flex flex-wrap gap-3">
                            <span>PO: {{ $workOrder->po_number }}</span>
                            <span>Net: {{ number_format($workOrder->net_requirement_quantity) }} pcs</span>
                            <span>Plan: {{ number_format($workOrder->planned_quantity) }} pcs</span>
                            <span>WIP Assembly: {{ number_format($workOrder->assembly_output_quantity) }} pcs</span>
                            <span>Sisa: {{ number_format($workOrder->remaining_before_tree_quantity) }} pcs</span>
                        </div>
                    </div>
                    <div class="text-slate-300 group-hover:text-amber-500 transition-colors">
                        <i class="fas fa-chevron-right fa-lg"></i>
                    </div>
                </div>
            </a>
        @empty
            <div class="bg-white rounded-xl shadow-sm border border-dashed border-slate-300 p-12 text-center">
                <div class="bg-slate-50 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-industry text-slate-400 text-2xl"></i>
                </div>
                <h3 class="text-slate-600 font-bold">Belum ada Work Order</h3>
                <p class="text-slate-400 text-sm mt-1">Tambahkan ET pertama untuk memulai perencanaan Lost Wax.</p>
            </div>
        @endforelse

        <div>{{ $workOrders->links() }}</div>
    </div>
@endsection
