@extends('layouts.app')

@section('top_bar')
    <div class="flex flex-col sm:flex-row sm:items-center justify-between w-full gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-lg font-bold text-slate-800 leading-tight">{{ $castingOrder->casting_order_number }}</h1>
                @if($castingOrder->status === 'DRAFT')
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800 border border-amber-200">DRAFT</span>
                @elseif($castingOrder->status === 'ISSUED')
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-blue-100 text-blue-800 border border-blue-200">ISSUED</span>
                @elseif($castingOrder->status === 'COMPLETED')
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">COMPLETED</span>
                @elseif($castingOrder->status === 'CANCELLED')
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-800 border border-red-200">CANCELLED</span>
                @endif
            </div>
            <p class="text-gray-500 text-[10px]">Dokumen instruksi penuangan cor pasir (Sand Casting)</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('sand-casting.casting-orders.plans', ['tab' => 'orders']) }}" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold px-3 py-2 rounded-lg text-xs flex items-center gap-1.5 shadow-sm transition">
                <i class="fas fa-arrow-left"></i> Kembali
            </a>

            <a href="{{ route('sand-casting.casting-orders.print', $castingOrder) }}" target="_blank" class="bg-slate-800 hover:bg-slate-900 text-white font-bold px-3 py-2 rounded-lg text-xs flex items-center gap-1.5 shadow-sm transition">
                <i class="fas fa-print"></i> Cetak Instruksi
            </a>

            @if($castingOrder->status === 'DRAFT')
                <a href="{{ route('sand-casting.casting-orders.edit', $castingOrder) }}" class="bg-amber-500 hover:bg-amber-600 text-white font-bold px-3 py-2 rounded-lg text-xs flex items-center gap-1.5 shadow-sm transition">
                    <i class="fas fa-edit"></i> Edit Draft
                </a>

                <form action="{{ route('sand-casting.casting-orders.update-status', $castingOrder) }}" method="POST" class="inline" onsubmit="return confirm('Terbitkan dokumen ini secara resmi ke lantai produksi Sand Casting?')">
                    @csrf
                    <input type="hidden" name="status" value="ISSUED">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-4 py-2 rounded-lg text-xs flex items-center gap-1.5 shadow-sm transition">
                        <i class="fas fa-paper-plane"></i> Terbitkan (ISSUE)
                    </button>
                </form>

                <form action="{{ route('sand-casting.casting-orders.destroy', $castingOrder) }}" method="POST" class="inline" onsubmit="return confirm('Hapus seluruh dokumen draft ini?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="bg-red-50 hover:bg-red-100 text-red-600 font-bold px-3 py-2 rounded-lg text-xs flex items-center gap-1.5 transition">
                        <i class="fas fa-trash"></i> Hapus
                    </button>
                </form>
            @elseif($castingOrder->status === 'ISSUED')
                <form action="{{ route('sand-casting.casting-orders.update-status', $castingOrder) }}" method="POST" class="inline" onsubmit="return confirm('Tandai instruksi Perintah Cor ini sudah selesai sepenuhnya?')">
                    @csrf
                    <input type="hidden" name="status" value="COMPLETED">
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-3 py-2 rounded-lg text-xs flex items-center gap-1.5 shadow-sm transition">
                        <i class="fas fa-check"></i> Selesaikan (COMPLETE)
                    </button>
                </form>

                <form action="{{ route('sand-casting.casting-orders.update-status', $castingOrder) }}" method="POST" class="inline" onsubmit="return confirm('Batalkan dokumen ini? Alokasi kuantitas akan dikembalikan ke Rencana Cor.')">
                    @csrf
                    <input type="hidden" name="status" value="CANCELLED">
                    <button type="submit" class="bg-red-50 hover:bg-red-100 text-red-600 font-bold px-3 py-2 rounded-lg text-xs flex items-center gap-1.5 transition">
                        <i class="fas fa-ban"></i> Batalkan (CANCEL)
                    </button>
                </form>
            @endif
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

        <!-- Document Details Card -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-6">
                <div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">No Perintah Cor</div>
                    <div class="text-base font-mono font-bold text-blue-700 mt-1">{{ $castingOrder->casting_order_number }}</div>
                </div>
                <div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Tanggal Rencana Cor</div>
                    <div class="text-base font-bold text-slate-800 mt-1">
                        {{ $castingOrder->scheduled_date ? $castingOrder->scheduled_date->format('d F Y') : '-' }}
                    </div>
                </div>
                <div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Dibuat Oleh</div>
                    <div class="text-sm font-medium text-slate-700 mt-1">
                        {{ $castingOrder->creator->name ?? '-' }}
                        <span class="text-xs text-slate-400 block">{{ $castingOrder->created_at ? $castingOrder->created_at->format('d/m/Y H:i') : '' }}</span>
                    </div>
                </div>
                <div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Catatan / Keterangan</div>
                    <div class="text-sm text-slate-600 mt-1 italic">{{ $castingOrder->notes ?: '-' }}</div>
                </div>
            </div>
        </div>

        <!-- Metric Summary Cards -->
        @php
            $totalPcs = $castingOrder->lines->sum('qty_ordered');
            $totalKg = $castingOrder->lines->sum(function ($line) {
                $w = $line->productionPlan ? (float) ($line->productionPlan->weight ?? 0) : 0;
                return $line->qty_ordered * $w;
            });
        @endphp
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-xl shrink-0">
                    <i class="fas fa-list-ol"></i>
                </div>
                <div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Item Produk</div>
                    <div class="text-xl font-bold text-slate-800">{{ $castingOrder->lines->count() }} item</div>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center text-xl shrink-0">
                    <i class="fas fa-cubes"></i>
                </div>
                <div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Kuantitas Perintah</div>
                    <div class="text-xl font-bold text-indigo-700">{{ number_format($totalPcs) }} pcs</div>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl shrink-0">
                    <i class="fas fa-weight-hanging"></i>
                </div>
                <div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Estimasi Berat</div>
                    <div class="text-xl font-bold text-emerald-600">{{ number_format($totalKg, 2) }} kg</div>
                </div>
            </div>
        </div>

        <!-- Order Lines Table -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-slate-100 flex items-center justify-between">
                <h3 class="font-bold text-slate-800 text-sm flex items-center gap-2">
                    <i class="fas fa-table text-blue-600"></i> Rincian Item Perintah Cor
                </h3>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-xs">
                    <thead class="bg-slate-50 text-slate-600 font-bold uppercase tracking-wider">
                        <tr>
                            <th class="p-3 text-center w-12">No</th>
                            <th class="p-3 text-left">Kode Cust</th>
                            <th class="p-3 text-left">Customer</th>
                            <th class="p-3 text-left">Nama Produk</th>
                            <th class="p-3 text-center">Ukuran</th>
                            <th class="p-3 text-center">AISI</th>
                            <th class="p-3 text-center font-bold text-blue-800">Qty Perintah</th>
                            <th class="p-3 text-center">Berat Satuan</th>
                            <th class="p-3 text-center">Total Berat</th>
                            <th class="p-3 text-left">Catatan Line</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        @foreach($castingOrder->lines as $index => $line)
                            @php
                                $unitWeight = $line->productionPlan ? (float) ($line->productionPlan->weight ?? 0) : 0;
                                $subtotalWeight = $line->qty_ordered * $unitWeight;
                            @endphp
                            <tr class="hover:bg-slate-50 transition">
                                <td class="p-3 text-center font-mono text-slate-400">{{ $index + 1 }}</td>
                                <td class="p-3 font-mono font-bold text-blue-700">{{ $line->code }}</td>
                                <td class="p-3 font-medium">{{ $line->customer ?: '-' }}</td>
                                <td class="p-3 font-semibold text-slate-800">{{ $line->item_name }}</td>
                                <td class="p-3 text-center font-mono">{{ $line->size ?: '-' }}</td>
                                <td class="p-3 text-center font-mono">{{ $line->aisi ?: '-' }}</td>
                                <td class="p-3 text-center font-bold text-blue-700 text-sm">{{ number_format($line->qty_ordered) }} pcs</td>
                                <td class="p-3 text-center font-mono text-slate-500">{{ $unitWeight > 0 ? number_format($unitWeight, 2).' kg' : '-' }}</td>
                                <td class="p-3 text-center font-mono font-medium text-slate-700">{{ $subtotalWeight > 0 ? number_format($subtotalWeight, 2).' kg' : '-' }}</td>
                                <td class="p-3 text-slate-500 italic">{{ $line->notes ?: '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50 font-bold text-slate-800 border-t border-slate-200">
                        <tr>
                            <td colspan="6" class="p-3 text-right uppercase tracking-wider text-[11px]">Total:</td>
                            <td class="p-3 text-center text-blue-800 text-sm">{{ number_format($totalPcs) }} pcs</td>
                            <td class="p-3"></td>
                            <td class="p-3 text-center text-emerald-600">{{ number_format($totalKg, 2) }} kg</td>
                            <td class="p-3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
@endsection
