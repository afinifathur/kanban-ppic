@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Hasil Cetak (Print Outcome)</h1>
            <p class="text-gray-500 text-[10px]">Catat hasil aktual eksekusi harian &amp; rusak dari perintah cetak lilin</p>
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
                        <th class="p-4">Status Dokumen</th>
                        <th class="p-4">Total Item</th>
                        <th class="p-4">Progress Eksekusi (Pcs)</th>
                        <th class="p-4">Status Item (Lines)</th>
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
                                @php
                                    $statusClass = 'bg-slate-100 text-slate-700';
                                    if ($order->status === 'ISSUED') {
                                        $statusClass = 'bg-blue-100 text-blue-700';
                                    } elseif ($order->status === 'PARTIALLY_COMPLETED') {
                                        $statusClass = 'bg-amber-100 text-amber-700';
                                    } elseif ($order->status === 'COMPLETED') {
                                        $statusClass = 'bg-emerald-100 text-emerald-700';
                                    } elseif ($order->status === 'CANCELLED') {
                                        $statusClass = 'bg-red-100 text-red-700';
                                    }
                                @endphp
                                <span class="px-2.5 py-0.5 text-xs font-bold rounded-full {{ $statusClass }}">
                                    {{ str_replace('_', ' ', $order->status) }}
                                </span>
                            </td>
                            <td class="p-4">{{ $order->lines->count() }} item</td>
                            <td class="p-4 w-64">
                                <div class="space-y-1">
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="font-bold text-slate-800">{{ $order->progress_percent }}%</span>
                                        <span class="text-slate-500 font-medium">{{ number_format($order->qty_executed_good + $order->qty_executed_defect) }} / {{ number_format($order->qty_ordered) }} pcs</span>
                                    </div>
                                    <div class="w-full bg-slate-100 rounded-full h-1.5 overflow-hidden">
                                        <div class="bg-amber-500 h-1.5 rounded-full transition-all duration-300" style="width: {{ min(100, $order->progress_percent) }}%"></div>
                                    </div>
                                    <div class="flex flex-wrap gap-x-2 text-[10px] text-slate-400 font-semibold uppercase">
                                        <span>Good: <strong class="text-slate-700">{{ number_format($order->qty_executed_good) }}</strong></span>
                                        <span>Defect: <strong class="text-red-600">{{ number_format($order->qty_executed_defect) }}</strong></span>
                                        <span>Ostd: <strong class="text-amber-700">{{ number_format($order->qty_outstanding) }}</strong></span>
                                    </div>
                                </div>
                            </td>
                            <td class="p-4">
                                @php
                                    $completedLines = $order->lines->filter(fn($l) => $l->execution_status === 'COMPLETED')->count();
                                    $inProgressLines = $order->lines->filter(fn($l) => $l->execution_status === 'IN_PROGRESS')->count();
                                    $totalLines = $order->lines->count();
                                @endphp
                                @if($completedLines === $totalLines)
                                    <span class="text-emerald-600 text-xs font-semibold flex items-center gap-1">
                                        <i class="fas fa-check-circle text-[10px]"></i> Selesai ({{ $completedLines }}/{{ $totalLines }})
                                    </span>
                                @elseif($completedLines > 0 || $inProgressLines > 0)
                                    <span class="text-amber-600 text-xs font-semibold flex items-center gap-1">
                                        <i class="fas fa-spinner fa-spin text-[10px]"></i> Sebagian ({{ $completedLines }} selesai, {{ $inProgressLines }} proses)
                                    </span>
                                @else
                                    <span class="text-slate-400 text-xs flex items-center gap-1">
                                        <i class="far fa-circle text-[10px]"></i> Belum Mulai
                                    </span>
                                @endif
                            </td>
                            <td class="p-4 text-right">
                                @if(in_array($order->status, ['ISSUED', 'PARTIALLY_COMPLETED']))
                                    <a href="{{ route('lost-wax.outcomes.edit', $order) }}" class="inline-flex items-center gap-1 bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold py-1.5 px-3 rounded-lg transition-all shadow-sm">
                                        <i class="fas fa-edit"></i> Catat Hasil
                                    </a>
                                @else
                                    <span class="text-xs text-slate-400 font-semibold">Hanya mode baca</span>
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
