@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Hasil Cetak</h1>
            <p class="text-gray-500 text-[10px]">Catat hasil aktual &amp; rusak dari form kerja cetak lilin</p>
        </div>
    </div>
@endsection

@section('content')
    <div class="space-y-4">
        <!-- Search bar -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-medium text-slate-700 mb-1">Cari No Perintah Cetak</label>
                <form method="GET" class="flex items-end gap-2">
                    <input type="text" name="print_order_number" value="{{ request('print_order_number') }}" placeholder="PC-YYYYMMDD-XXXX" class="rounded-lg border-slate-300 text-sm w-56">
                    <button type="submit" class="bg-slate-700 hover:bg-slate-800 text-white text-xs font-bold py-1.5 px-3 rounded">Filter</button>
                    @if(request('print_order_number'))
                        <a href="{{ route('lost-wax.outcomes.index') }}" class="text-xs text-slate-500 hover:text-slate-700 py-1.5">Clear</a>
                    @endif
                </form>
            </div>
        </div>

        <!-- Table of print orders -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-xs font-bold text-slate-700 uppercase">
                        <th class="p-4">No Perintah Cetak</th>
                        <th class="p-4">Tanggal Jadwal</th>
                        <th class="p-4">Status</th>
                        <th class="p-4">Total Item</th>
                        <th class="p-4">Status Pencatatan</th>
                        <th class="p-4 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                    @forelse($printOrders as $order)
                        <tr>
                            <td class="p-4 font-semibold text-slate-800">
                                <a href="{{ route('lost-wax.print-orders.show', $order) }}" class="hover:text-amber-600">
                                    {{ $order->print_order_number }}
                                </a>
                            </td>
                            <td class="p-4">{{ $order->scheduled_date->format('d-m-Y') }}</td>
                            <td class="p-4">
                                <span class="px-2.5 py-0.5 text-xs font-bold rounded-full
                                    {{ $order->status === 'ISSUED' ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-700' }}">
                                    {{ $order->status }}
                                </span>
                            </td>
                            <td class="p-4">{{ $order->lines->count() }} item</td>
                            <td class="p-4">
                                @php
                                    $recordedLines = $order->lines->filter(fn($l) => $l->qty_actual_good !== null)->count();
                                @endphp
                                @if($recordedLines === 0)
                                    <span class="text-slate-400 text-xs flex items-center gap-1">
                                        <i class="far fa-circle"></i> Belum Dicatat
                                    </span>
                                @elseif($recordedLines === $order->lines->count())
                                    <span class="text-emerald-600 text-xs font-semibold flex items-center gap-1">
                                        <i class="fas fa-check-circle"></i> Lengkap
                                    </span>
                                @else
                                    <span class="text-amber-600 text-xs font-semibold flex items-center gap-1">
                                        <i class="fas fa-spinner fa-spin"></i> Sebagian ({{ $recordedLines }}/{{ $order->lines->count() }})
                                    </span>
                                @endif
                            </td>
                            <td class="p-4 text-right">
                                @if($order->status === 'ISSUED')
                                    <a href="{{ route('lost-wax.outcomes.edit', $order) }}" class="inline-flex items-center gap-1 bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold py-1.5 px-3 rounded-lg transition-all">
                                        <i class="fas fa-edit"></i> Catat Hasil
                                    </a>
                                @else
                                    <span class="text-xs text-slate-400">Hanya mode baca</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-8 text-center text-slate-400">
                                <i class="fas fa-inbox text-3xl mb-2 block"></i>
                                Belum ada dokumen perintah cetak berstatus diterbitkan (ISSUED).
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $printOrders->links() }}</div>
    </div>
@endsection
