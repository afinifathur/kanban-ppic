@extends('layouts.app')

@section('top_bar')
    <div class="flex flex-col sm:flex-row sm:items-center justify-between w-full gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-lg font-bold text-slate-800 leading-tight">Detail Hasil Cor</h1>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-mono font-bold bg-amber-100 text-amber-900 border border-amber-300 shadow-sm">
                    HEAT: {{ $castingResult->heat_number }}
                </span>
            </div>
            <p class="text-gray-500 text-[10px]">Fakta penuangan cor pasir dan pencatatan physical traveler (Kitir)</p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('sand-casting.casting-results.index') }}" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold px-3 py-2 rounded-lg text-xs flex items-center gap-1.5 shadow-sm transition">
                <i class="fas fa-arrow-left"></i> Daftar Hasil Cor
            </a>
            <a href="{{ route('sand-casting.casting-orders.plans') }}" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold px-3 py-2 rounded-lg text-xs flex items-center gap-1.5 shadow-sm transition">
                <i class="fas fa-file-invoice"></i> Perintah Cor
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="space-y-6">
        <!-- Flash Messages -->
        @if(session('success'))
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm px-4 py-3 rounded-lg flex items-center justify-between shadow-sm">
                <div class="flex items-center gap-2">
                    <i class="fas fa-check-circle text-emerald-500 text-base"></i>
                    <span>{{ session('success') }}</span>
                </div>
                <button type="button" onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-emerald-600 text-sm">&times;</button>
            </div>
        @endif

        <!-- Heat Header Card (Prominent Visual Emphasis) -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="bg-slate-900 text-white px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <div class="text-[10px] font-bold text-amber-400 uppercase tracking-widest">IDENTITAS METALLURGICAL HEAT</div>
                    <div class="text-2xl sm:text-3xl font-mono font-black tracking-wider text-amber-300 mt-0.5">
                        {{ $castingResult->heat_number }}
                    </div>
                </div>
                <div class="text-left sm:text-right">
                    <div class="text-[10px] text-slate-400 uppercase tracking-wider">Sumber Alokasi</div>
                    <div class="text-sm font-semibold text-slate-200 mt-0.5">
                        {{ $castingResult->lines->pluck('castingOrderLine.castingOrder.casting_order_number')->filter()->unique()->implode(', ') ?: '-' }}
                    </div>
                </div>
            </div>

            <div class="p-6 grid grid-cols-2 sm:grid-cols-4 gap-6 text-xs border-t border-slate-100">
                <div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Tanggal Cor Aktual</div>
                    <div class="text-sm font-bold text-slate-800 mt-1">
                        {{ $castingResult->cast_date ? $castingResult->cast_date->format('d F Y') : '-' }}
                    </div>
                </div>
                <div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Furnace / Shift</div>
                    <div class="text-sm font-semibold text-slate-800 mt-1">
                        {{ $castingResult->furnace ?: '-' }} {{ $castingResult->shift ? '('.$castingResult->shift.')' : '' }}
                    </div>
                </div>
                <div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Operator / Pelaksana</div>
                    <div class="text-sm font-semibold text-slate-800 mt-1">
                        {{ $castingResult->operator_name ?: '-' }}
                    </div>
                </div>
                <div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Dicatat Oleh</div>
                    <div class="text-sm font-medium text-slate-700 mt-1">
                        {{ $castingResult->recorder->name ?? '-' }}
                        <span class="text-[10px] text-slate-400 block">{{ $castingResult->created_at ? $castingResult->created_at->format('d/m/Y H:i') : '' }}</span>
                    </div>
                </div>
                @if($castingResult->notes)
                    <div class="col-span-2 sm:col-span-4 pt-2 border-t border-slate-100">
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Catatan Penuangan</div>
                        <div class="text-xs text-slate-600 mt-0.5 italic">{{ $castingResult->notes }}</div>
                    </div>
                @endif
            </div>
        </div>

        <!-- Metric Summary Cards -->
        @php
            $totalGood = $castingResult->total_qty_good;
            $totalReject = $castingResult->total_qty_reject;
            $totalActual = $totalGood + $totalReject;
            $totalWeight = $castingResult->total_weight_kg;
        @endphp
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-lg shrink-0">
                    <i class="fas fa-circle-check"></i>
                </div>
                <div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Good</div>
                    <div class="text-xl font-bold text-emerald-700 font-mono">{{ number_format($totalGood) }} pcs</div>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-red-50 text-red-600 flex items-center justify-center text-lg shrink-0">
                    <i class="fas fa-circle-xmark"></i>
                </div>
                <div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Reject</div>
                    <div class="text-xl font-bold text-red-600 font-mono">{{ number_format($totalReject) }} pcs</div>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center text-lg shrink-0">
                    <i class="fas fa-cubes"></i>
                </div>
                <div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Output</div>
                    <div class="text-xl font-bold text-slate-800 font-mono">{{ number_format($totalActual) }} pcs</div>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center text-lg shrink-0">
                    <i class="fas fa-weight-hanging"></i>
                </div>
                <div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Berat Cor</div>
                    <div class="text-xl font-bold text-amber-700 font-mono">{{ number_format($totalWeight, 2) }} kg</div>
                </div>
            </div>
        </div>

        <!-- Traceability Flow Card -->
        <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3">Traceability Chain (Rantai Telusur)</div>
            <div class="bg-slate-50 border border-slate-200 rounded-lg p-3 font-mono text-xs text-slate-700 space-y-1">
                <div class="flex items-center gap-2">
                    <span class="font-bold text-slate-900 bg-amber-200 px-2 py-0.5 rounded">HEAT: {{ $castingResult->heat_number }}</span>
                    <i class="fas fa-arrow-right text-slate-400 text-[10px]"></i>
                    <span class="text-slate-500">{{ $castingResult->lines->count() }} Production Item(s)</span>
                </div>
                @foreach($castingResult->lines as $line)
                    @php $ol = $line->castingOrderLine; @endphp
                    <div class="pl-4 text-[11px] text-slate-600 flex items-center gap-2">
                        <span>├──</span>
                        <span class="font-bold text-blue-700">{{ $ol->code ?? '-' }}</span>
                        @if($ol && $ol->castingOrder)
                            <span class="text-slate-400">({{ $ol->castingOrder->casting_order_number }})</span>
                        @endif
                        <span class="text-slate-400">&rarr;</span>
                        <span class="font-bold text-emerald-700">{{ number_format($line->qty_good) }} pcs Good</span>
                        <span class="text-slate-400">&rarr;</span>
                        <span class="bg-slate-200 text-slate-800 px-1.5 py-0.5 rounded font-bold">{{ $line->traveler_number }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- Result Line Items Table -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h2 class="font-bold text-slate-800 text-sm">Rincian Item Hasil Cor</h2>
                    <p class="text-slate-400 text-[11px]">Daftar alokasi penuangan item produk dan penomoran traveler unik</p>
                </div>
                <span class="text-xs text-slate-500 font-mono">{{ $castingResult->lines->count() }} item dicor</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-xs text-left">
                    <thead class="bg-slate-50 text-slate-600 font-bold uppercase tracking-wider">
                        <tr>
                            <th class="p-3 text-center w-10">No</th>
                            <th class="p-3 text-left">No Perintah Cor</th>
                            <th class="p-3 text-left">Kode Prod</th>
                            <th class="p-3 text-left">Customer</th>
                            <th class="p-3 text-left">Nama Produk</th>
                            <th class="p-3 text-center w-24">Traveler (Kitir)</th>
                            <th class="p-3 text-center w-20 font-bold text-emerald-700">Good (Pcs)</th>
                            <th class="p-3 text-center w-20 font-bold text-red-600">Reject</th>
                            <th class="p-3 text-center w-20">Total Cor</th>
                            <th class="p-3 text-center w-20">Unit Weight</th>
                            <th class="p-3 text-center w-24">Total Berat</th>
                            <th class="p-3 text-left">Catatan Line</th>
                            <th class="p-3 text-center w-28">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        @foreach($castingResult->lines as $idx => $line)
                            @php
                                $orderLine = $line->castingOrderLine;
                            @endphp
                            <tr class="hover:bg-slate-50 transition">
                                <td class="p-3 text-center font-mono text-slate-400">{{ $idx + 1 }}</td>
                                <td class="p-3 font-mono font-semibold text-slate-600">
                                    @if($orderLine && $orderLine->castingOrder)
                                        <a href="{{ route('sand-casting.casting-orders.show', $orderLine->castingOrder) }}" class="text-blue-600 hover:text-blue-800 underline">
                                            {{ $orderLine->castingOrder->casting_order_number }}
                                        </a>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="p-3 font-mono font-bold text-blue-700">{{ $orderLine->code ?? '-' }}</td>
                                <td class="p-3 font-medium">{{ $orderLine->customer ?? '-' }}</td>
                                <td class="p-3 font-semibold text-slate-800">
                                    {{ $orderLine->item_name ?? '-' }}
                                    @if($orderLine && ($orderLine->size || $orderLine->aisi))
                                        <span class="block text-[10px] text-slate-400 font-mono">{{ $orderLine->size }} {{ $orderLine->aisi ? '('.$orderLine->aisi.')' : '' }}</span>
                                    @endif
                                </td>
                                <!-- Prominent Physical Traveler Identity -->
                                <td class="p-3 text-center">
                                    <span class="inline-flex items-center px-2 py-1 rounded bg-slate-100 text-slate-800 font-mono font-bold text-xs border border-slate-300">
                                        <i class="fas fa-barcode text-[10px] mr-1 text-slate-500"></i>
                                        {{ $line->traveler_number }}
                                    </span>
                                </td>
                                <td class="p-3 text-center font-mono font-bold text-emerald-700 text-sm">{{ number_format($line->qty_good) }}</td>
                                <td class="p-3 text-center font-mono font-bold text-red-600">{{ number_format($line->qty_reject) }}</td>
                                <td class="p-3 text-center font-mono font-bold text-slate-800">{{ number_format($line->qty_total) }}</td>
                                <td class="p-3 text-center font-mono text-slate-600">{{ number_format($line->unit_weight_kg, 2) }} kg</td>
                                <td class="p-3 text-center font-mono font-bold text-slate-700">{{ number_format($line->total_weight_kg, 2) }} kg</td>
                                <td class="p-3 text-slate-500 italic">{{ $line->notes ?: '-' }}</td>
                                <td class="p-3 text-center">
                                    <a href="{{ route('sand-casting.casting-results.kitir', [$castingResult, $line]) }}" target="_blank"
                                       class="inline-flex items-center gap-1.5 bg-slate-900 hover:bg-slate-800 text-amber-300 hover:text-amber-200 font-bold px-2.5 py-1.5 rounded-lg text-xs transition shadow-sm">
                                        <i class="fas fa-print text-[10px]"></i> Cetak Kitir
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50 font-bold text-slate-800 border-t border-slate-200">
                        <tr>
                            <td colspan="6" class="p-3 text-right uppercase tracking-wider text-[11px]">Total:</td>
                            <td class="p-3 text-center text-emerald-700 text-sm">{{ number_format($totalGood) }} pcs</td>
                            <td class="p-3 text-center text-red-600 text-sm">{{ number_format($totalReject) }} pcs</td>
                            <td class="p-3 text-center text-slate-900 text-sm">{{ number_format($totalActual) }} pcs</td>
                            <td class="p-3"></td>
                            <td class="p-3 text-center text-slate-900">{{ number_format($totalWeight, 2) }} kg</td>
                            <td class="p-3" colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
@endsection
