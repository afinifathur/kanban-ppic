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
                <h2 class="font-bold text-slate-800 mb-4">Item Perintah Cetak & Riwayat Eksekusi</h2>

                <div class="space-y-6">
                    @foreach($printOrder->lines as $index => $line)
                        <div class="p-5 rounded-xl border border-slate-200 bg-slate-50 flex flex-col gap-4">
                            <input type="hidden" name="items[{{ $index }}][id]" value="{{ $line->id }}">
                            
                            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
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
                                            Sebab sudah dirangkai <strong>{{ $allocated }} pcs</strong>, Total Hasil (Good) minimal harus <strong>{{ $allocated }} pcs</strong>.
                                        </div>
                                    @endif
                                </div>

                                <!-- Input fields (Kumulatif Target Baru) -->
                                <div class="flex flex-wrap items-end gap-3 bg-white p-3 rounded-lg border border-slate-200">
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-700 mb-1">Target Total Good <span class="text-red-500">*</span></label>
                                        <input type="number" name="items[{{ $index }}][qty_actual_good]" 
                                            value="{{ old("items.{$index}.qty_actual_good", $line->qty_executed_good ?? $line->qty_ordered) }}" 
                                            min="0" required class="w-28 rounded-lg border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-700 mb-1">Target Total Defect <span class="text-red-500">*</span></label>
                                        <input type="number" name="items[{{ $index }}][qty_actual_defect]" 
                                            value="{{ old("items.{$index}.qty_actual_defect", $line->qty_executed_defect ?? 0) }}" 
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

                            <!-- Execution History & Summary -->
                            <div class="bg-white rounded-lg p-4 border border-slate-200">
                                <div class="text-xs font-bold text-slate-700 mb-2 flex items-center justify-between">
                                    <span>Riwayat Eksekusi Cetak Harian:</span>
                                    <span class="text-gray-400 font-normal">Sistem mencatat setiap tanggal eksekusi secara terpisah</span>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-xs text-left border-collapse">
                                        <thead>
                                            <tr class="border-b border-slate-100 text-slate-500 font-semibold bg-slate-50">
                                                <th class="p-2 text-center">Execution #</th>
                                                <th class="p-2">Tanggal Eksekusi</th>
                                                <th class="p-2 text-center">Good (Pcs)</th>
                                                <th class="p-2 text-center">Defect (Pcs)</th>
                                                <th class="p-2 text-center">Total Output (Pcs)</th>
                                                <th class="p-2">Dicatat Oleh</th>
                                                <th class="p-2">Status</th>
                                                <th class="p-2">Tanggal Finalisasi</th>
                                                <th class="p-2">Catatan</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 text-slate-600">
                                            @forelse($line->executions->sortBy('created_at') as $exec)
                                                <tr>
                                                    <td class="p-2 text-center font-semibold text-slate-500">#{{ $loop->iteration }}</td>
                                                    <td class="p-2 font-medium">{{ $exec->execution_date->format('d-m-Y') }}</td>
                                                    <td class="p-2 text-center font-bold text-slate-800">{{ number_format($exec->qty_good) }}</td>
                                                    <td class="p-2 text-center text-red-600 font-medium">{{ number_format($exec->qty_defect) }}</td>
                                                    <td class="p-2 text-center font-bold text-slate-700">{{ number_format($exec->qty_good + $exec->qty_defect) }}</td>
                                                    <td class="p-2">{{ $exec->recorder?->name ?? 'System' }}</td>
                                                    <td class="p-2">
                                                        @if($exec->status === 'FINALIZED')
                                                            <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                                                <i class="fas fa-check-circle text-[8px]"></i> FINALIZED
                                                            </span>
                                                        @else
                                                            <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-800 border border-amber-300 border-dashed">
                                                                <i class="fas fa-pencil-alt text-[8px]"></i> DRAFT
                                                            </span>
                                                        @endif
                                                    </td>
                                                    <td class="p-2 text-slate-500">{{ $exec->finalized_at ? $exec->finalized_at->format('d-m-Y H:i') : '-' }}</td>
                                                    <td class="p-2 text-slate-400 italic">{{ $exec->notes ?? '-' }}</td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="9" class="p-4 text-center text-slate-400 italic">Belum ada eksekusi cetak harian yang dicatat. Gunakan form di atas untuk menambah total target.</td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Summary Footer -->
                                <div class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-4 border-t border-slate-100 pt-3 text-xs">
                                    <div class="bg-slate-50 p-2 rounded-lg border border-slate-100">
                                        <div class="text-slate-500">Total Good Terkumpul</div>
                                        <div class="text-sm font-bold text-slate-800 mt-0.5">{{ number_format($line->qty_executed_good ?? 0) }} pcs</div>
                                    </div>
                                    <div class="bg-slate-50 p-2 rounded-lg border border-slate-100">
                                        <div class="text-slate-500">Total Defect Terkumpul</div>
                                        <div class="text-sm font-bold text-slate-800 mt-0.5">{{ number_format($line->qty_executed_defect ?? 0) }} pcs</div>
                                    </div>
                                    <div class="bg-slate-50 p-2 rounded-lg border border-slate-100">
                                        <div class="text-slate-500">Sisa Outstanding Cetak</div>
                                        <div class="text-sm font-bold mt-0.5 {{ $line->qty_outstanding > 0 ? 'text-amber-600' : 'text-emerald-600' }}">
                                            {{ number_format($line->qty_outstanding) }} pcs
                                        </div>
                                    </div>
                                    <div class="bg-slate-50 p-2 rounded-lg border border-slate-100">
                                        <div class="text-slate-500">Status Eksekusi Line</div>
                                        <div class="text-sm font-bold mt-0.5">
                                            <span class="px-2 py-0.5 rounded text-[10px] uppercase font-bold
                                                {{ $line->execution_status === 'COMPLETED' ? 'bg-emerald-100 text-emerald-700' : ($line->execution_status === 'IN_PROGRESS' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-700') }}">
                                                {{ $line->execution_status }}
                                            </span>
                                        </div>
                                    </div>
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
                    <i class="fas fa-save mr-1"></i> Simpan & Finalisasi Hasil Cetak
                </button>
            </div>
        </form>
    </div>
@endsection
