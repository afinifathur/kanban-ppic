@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Detail Perintah Cetak</h1>
            <p class="text-gray-500 text-[10px]">Informasi dokumen dan detail item pekerjaan cetak lilin</p>
        </div>
        <a href="{{ route('lost-wax.print-orders.plans') }}" class="text-slate-500 hover:text-slate-700 text-xs flex items-center gap-1.5 font-bold">
            <i class="fas fa-arrow-left"></i> Kembali ke Daftar
        </a>
    </div>
@endsection

@section('content')
    <div class="space-y-6 max-w-6xl mx-auto">
        
        <!-- Document Header Card -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 border-b border-slate-100 pb-4 mb-4">
                <div>
                    <span class="text-[10px] uppercase font-bold tracking-wider text-slate-400">Nomor Dokumen</span>
                    <h2 class="text-2xl font-bold font-mono text-slate-800">{{ $printOrder->print_order_number }}</h2>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-sm font-bold text-slate-500">Status:</span>
                    <span class="px-3 py-1 rounded-full text-xs font-bold uppercase shadow-inner
                        {{ $printOrder->status === 'DRAFT' ? 'bg-slate-100 text-slate-600 border border-slate-200' : '' }}
                        {{ $printOrder->status === 'ISSUED' ? 'bg-blue-100 text-blue-600 border border-blue-200' : '' }}
                        {{ $printOrder->status === 'CANCELLED' ? 'bg-red-100 text-red-600 border border-red-200' : '' }}
                    ">
                        {{ $printOrder->status }}
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-6 text-sm">
                <div>
                    <span class="block text-xs font-bold text-slate-400 uppercase">Tanggal Cetak</span>
                    <span class="font-bold text-slate-700">{{ $printOrder->scheduled_date->format('d F Y') }}</span>
                </div>
                <div>
                    <span class="block text-xs font-bold text-slate-400 uppercase">Dibuat Oleh</span>
                    <span class="font-bold text-slate-700">{{ optional($printOrder->creator)->name ?: '-' }}</span>
                </div>
                <div>
                    <span class="block text-xs font-bold text-slate-400 uppercase">Tanggal Dibuat</span>
                    <span class="font-bold text-slate-700">{{ $printOrder->created_at->format('d/m/Y H:i') }}</span>
                </div>
                <div>
                    <span class="block text-xs font-bold text-slate-400 uppercase">Total Baris Item</span>
                    <span class="font-bold text-slate-700">{{ $printOrder->lines->count() }} item</span>
                </div>
            </div>
        </div>

        <!-- Action Control Bar -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                @if($printOrder->status === 'DRAFT')
                    <!-- DRAFT ACTIONS -->
                    <a href="{{ route('lost-wax.print-orders.edit', $printOrder) }}" 
                        class="bg-amber-500 hover:bg-amber-600 text-white font-bold py-2 px-5 rounded-lg text-sm shadow flex items-center gap-2 transition-all">
                        <i class="fas fa-edit"></i> Edit Dokumen
                    </a>

                    <!-- Issue Doc (DRAFT -> ISSUED) -->
                    <form action="{{ route('lost-wax.print-orders.update-status', $printOrder) }}" method="POST" class="inline">
                        @csrf
                        <input type="hidden" name="status" value="ISSUED">
                        <button type="submit" onclick="return confirm('Apakah Anda yakin ingin menerbitkan Perintah Cetak ini? Setelah diterbitkan, dokumen tidak dapat diedit kembali.')"
                            class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-5 rounded-lg text-sm shadow flex items-center gap-2 transition-all">
                            <i class="fas fa-check-circle"></i> Terbitkan (Issue)
                        </button>
                    </form>

                    <!-- Cancel Doc (DRAFT -> CANCELLED) -->
                    <form action="{{ route('lost-wax.print-orders.update-status', $printOrder) }}" method="POST" class="inline">
                        @csrf
                        <input type="hidden" name="status" value="CANCELLED">
                        <button type="submit" onclick="return confirm('Apakah Anda yakin ingin membatalkan Perintah Cetak ini?')"
                            class="bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 font-bold py-2 px-5 rounded-lg text-sm shadow flex items-center gap-2 transition-all">
                            <i class="fas fa-ban"></i> Batalkan
                        </button>
                    </form>
                @elseif($printOrder->status === 'ISSUED')
                    <!-- ISSUED ACTIONS -->
                    <a href="{{ route('lost-wax.print-orders.print', $printOrder) }}" target="_blank"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-5 rounded-lg text-sm shadow flex items-center gap-2 transition-all">
                        <i class="fas fa-print"></i> Cetak Dokumen
                    </a>

                    <!-- Cancel Doc (ISSUED -> CANCELLED) -->
                    <form action="{{ route('lost-wax.print-orders.update-status', $printOrder) }}" method="POST" class="inline">
                        @csrf
                        <input type="hidden" name="status" value="CANCELLED">
                        <button type="submit" onclick="return confirm('Apakah Anda yakin ingin membatalkan Perintah Cetak ini?')"
                            class="bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 font-bold py-2 px-5 rounded-lg text-sm shadow flex items-center gap-2 transition-all">
                            <i class="fas fa-ban"></i> Batalkan
                        </button>
                    </form>
                @elseif($printOrder->status === 'CANCELLED')
                    <!-- CANCELLED: Immutable info -->
                    <div class="text-xs text-slate-400 italic flex items-center gap-2">
                        <i class="fas fa-lock"></i> Dokumen ini telah dibatalkan secara permanen sebagai rekaman historis.
                    </div>
                @endif
            </div>

            <div>
                @if($printOrder->status === 'DRAFT')
                    <!-- Delete draft -->
                    <form action="{{ route('lost-wax.print-orders.destroy', $printOrder) }}" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus draft ini secara permanen?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg text-sm shadow transition-all">
                            <i class="fas fa-trash"></i> Hapus Draft
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <!-- Items Detail Table -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <h3 class="text-slate-800 font-bold mb-4 border-b border-slate-100 pb-2">Daftar Item Cetak</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full border-collapse border border-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="border border-slate-200 p-3 text-center w-12">No</th>
                            <th class="border border-slate-200 p-3 text-left">Kode Cust (Snapshot)</th>
                            <th class="border border-slate-200 p-3 text-left">Customer (Snapshot)</th>
                            <th class="border border-slate-200 p-3 text-left">Nama Produk (Snapshot)</th>
                            <th class="border border-slate-200 p-3 text-center">Ukuran (Snapshot)</th>
                            <th class="border border-slate-200 p-3 text-center">AISI (Snapshot)</th>
                            <th class="border border-slate-200 p-3 text-center">Qty Perintah Cetak</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($printOrder->lines as $index => $line)
                            <tr class="hover:bg-slate-50/50 transition-colors">
                                <td class="border border-slate-200 p-3 text-center text-slate-400">{{ $index + 1 }}</td>
                                <td class="border border-slate-200 p-3 font-mono text-xs font-bold text-slate-700">{{ $line->code }}</td>
                                <td class="border border-slate-200 p-3 font-bold text-slate-700 uppercase">{{ $line->customer ?: '-' }}</td>
                                <td class="border border-slate-200 p-3 text-slate-800 font-medium">{{ $line->item_name }}</td>
                                <td class="border border-slate-200 p-3 text-center font-mono text-xs text-slate-600">{{ $line->size ?: '-' }}</td>
                                <td class="border border-slate-200 p-3 text-center text-slate-600 font-mono text-xs">{{ $line->aisi ?: '-' }}</td>
                                <td class="border border-slate-200 p-3 text-center font-bold text-slate-800 text-base">{{ number_format($line->qty_ordered) }} pcs</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
