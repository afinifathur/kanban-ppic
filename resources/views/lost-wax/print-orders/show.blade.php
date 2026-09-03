@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Detail Perintah Cetak</h1>
            <p class="text-gray-500 text-[10px]">Informasi dokumen dan detail item pekerjaan cetak lilin</p>
        </div>
        @php
            $selectionStorageKey = 'lost-wax-print-orders-selection-'.auth()->id().'-'.(auth()->user()->product_scope ?: 'all');
        @endphp
        <a href="{{ route('lost-wax.print-orders.plans', ['tab' => 'orders']) }}" class="text-slate-500 hover:text-slate-700 text-xs flex items-center gap-1.5 font-bold">
            <i class="fas fa-arrow-left"></i> Kembali ke Daftar
        </a>
    </div>
@endsection

@section('content')
    <div class="space-y-6 max-w-6xl mx-auto pb-8">
        
        @php
            $totalItems = $printOrder->lines->count();
            $totalOrdered = (int) $printOrder->lines->sum('qty_ordered');
            $totalGood = (int) $printOrder->lines->sum(fn($l) => $l->qty_executed_good ?? ($l->qty_actual_good ?? 0));
            $totalDefect = (int) $printOrder->lines->sum(fn($l) => $l->qty_executed_defect ?? ($l->qty_actual_defect ?? 0));
            $totalOutstanding = (int) $printOrder->lines->sum('qty_outstanding');
            $totalCompleted = $totalGood + $totalDefect;
            $percentProgress = $totalOrdered > 0 ? min(100, round(($totalCompleted / $totalOrdered) * 100)) : 0;
            
            $statusClass = match($printOrder->status) {
                'DRAFT' => 'bg-slate-100 text-slate-700 border-slate-300',
                'ISSUED' => 'bg-blue-100 text-blue-800 border-blue-300',
                'PARTIALLY_COMPLETED' => 'bg-amber-100 text-amber-800 border-amber-300',
                'COMPLETED' => 'bg-emerald-100 text-emerald-800 border-emerald-300',
                'CANCELLED' => 'bg-rose-100 text-rose-800 border-rose-300',
                default => 'bg-slate-100 text-slate-700 border-slate-300'
            };

            // Collect all executions across all lines
            $allExecutions = collect();
            foreach ($printOrder->lines as $line) {
                foreach ($line->executions as $exec) {
                    $exec->setRelation('printOrderLine', $line);
                    $allExecutions->push($exec);
                }
            }
            $sortedExecutions = $allExecutions->sortByDesc(fn($e) => $e->id)->values();
        @endphp

        <!-- 1. Document Header Card -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 border-b border-slate-100 pb-4 mb-4">
                <div>
                    <span class="text-[10px] uppercase font-bold tracking-wider text-slate-400">Nomor Dokumen</span>
                    <h2 class="text-2xl font-bold font-mono text-slate-800">{{ $printOrder->print_order_number }}</h2>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-sm font-bold text-slate-500">Status:</span>
                    <span class="px-3 py-1 rounded-full text-xs font-extrabold uppercase border shadow-2xs {{ $statusClass }}">
                        {{ str_replace('_', ' ', $printOrder->status) }}
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-6 text-sm">
                <div>
                    <span class="block text-xs font-bold text-slate-400 uppercase">Tanggal Cetak</span>
                    <span class="font-bold text-slate-700">{{ $printOrder->scheduled_date ? $printOrder->scheduled_date->format('d F Y') : '-' }}</span>
                </div>
                <div>
                    <span class="block text-xs font-bold text-slate-400 uppercase">Dibuat Oleh</span>
                    <span class="font-bold text-slate-700">{{ optional($printOrder->creator)->name ?: '-' }}</span>
                </div>
                <div>
                    <span class="block text-xs font-bold text-slate-400 uppercase">Tanggal Dibuat</span>
                    <span class="font-bold text-slate-700">{{ $printOrder->created_at ? $printOrder->created_at->format('d/m/Y H:i') : '-' }}</span>
                </div>
                <div>
                    <span class="block text-xs font-bold text-slate-400 uppercase">Total Baris Item</span>
                    <span class="font-bold text-slate-700">{{ $totalItems }} item</span>
                </div>
            </div>
        </div>

        <!-- 2. Outcome Summary KPI Banner (Visible for COMPLETED or orders with recorded outcomes) -->
        @if($printOrder->status === 'COMPLETED' || $totalGood > 0 || $totalDefect > 0)
            <div class="bg-slate-900 text-white rounded-xl shadow-sm border border-slate-800 p-5">
                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                    <!-- Title & Overview -->
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <span class="text-[9px] uppercase font-bold text-amber-400 tracking-wider">Hasil Perintah Cetak</span>
                            <span class="text-slate-500 text-xs">&bull;</span>
                            <span class="text-xs font-bold text-slate-300 font-mono">{{ $printOrder->print_order_number }}</span>
                        </div>
                        <h3 class="text-lg font-black text-white tracking-tight flex items-center gap-2">
                            Rekapitulasi Hasil Produksi
                        </h3>
                        <div class="flex flex-wrap items-center gap-2 text-xs text-slate-300">
                            <span>Total Item: <strong class="text-amber-300">{{ number_format($totalItems) }}</strong></span>
                            <span>&bull;</span>
                            <span>Rencana: <strong class="text-white">{{ number_format($totalOrdered) }} pcs</strong></span>
                        </div>
                    </div>

                    <!-- KPI Badges -->
                    <div class="flex flex-wrap items-center gap-2.5">
                        <div class="bg-slate-800/90 border border-slate-700 rounded-lg px-3.5 py-2 text-center min-w-[90px]">
                            <span class="text-[8px] uppercase font-bold text-emerald-400 tracking-wider block">Total Good</span>
                            <span class="text-base font-black text-emerald-300">{{ number_format($totalGood) }} <span class="text-[10px] font-normal text-slate-400">pcs</span></span>
                        </div>
                        <div class="bg-slate-800/90 border border-slate-700 rounded-lg px-3.5 py-2 text-center min-w-[90px]">
                            <span class="text-[8px] uppercase font-bold text-rose-400 tracking-wider block">Total Defect</span>
                            <span class="text-base font-black text-rose-300">{{ number_format($totalDefect) }} <span class="text-[10px] font-normal text-slate-400">pcs</span></span>
                        </div>
                        <div class="bg-slate-800/90 border border-slate-700 rounded-lg px-3.5 py-2 text-center min-w-[90px]">
                            <span class="text-[8px] uppercase font-bold text-amber-400 tracking-wider block">Outstanding</span>
                            <span class="text-base font-black {{ $totalOutstanding > 0 ? 'text-amber-300' : 'text-slate-400' }}">{{ number_format($totalOutstanding) }} <span class="text-[10px] font-normal text-slate-400">pcs</span></span>
                        </div>
                        <div class="flex flex-col items-end gap-1 pl-1">
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase border tracking-wider
                                {{ $printOrder->status === 'COMPLETED' ? 'bg-emerald-950 text-emerald-300 border-emerald-800' : 'bg-amber-950 text-amber-300 border-amber-800' }}">
                                STATUS: {{ str_replace('_', ' ', $printOrder->status) }}
                            </span>
                            <span class="text-[9px] text-slate-400 font-semibold">Progress: {{ $percentProgress }}% ({{ number_format($totalCompleted) }}/{{ number_format($totalOrdered) }} pcs)</span>
                        </div>
                    </div>
                </div>

                <!-- Slim Progress Bar -->
                <div class="w-full bg-slate-800 rounded-full h-1.5 mt-4 overflow-hidden">
                    <div class="bg-emerald-500 h-1.5 rounded-full transition-all duration-500" style="width: {{ $percentProgress }}%"></div>
                </div>
            </div>
        @endif

        <!-- 3. Action Control Bar -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 flex flex-wrap items-center justify-between gap-4">
            <div class="flex flex-wrap items-center gap-3">
                @if($printOrder->status === 'COMPLETED')
                    <!-- COMPLETED ACTIONS: Read-only -->
                    <a href="{{ route('lost-wax.print-orders.print', $printOrder) }}" target="_blank"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-5 rounded-lg text-sm shadow flex items-center gap-2 transition-all">
                        <i class="fas fa-print"></i> Cetak Dokumen
                    </a>

                    <div class="text-xs text-slate-500 flex items-center gap-1.5 font-medium bg-slate-50 px-3 py-1.5 rounded-lg border border-slate-200">
                        <i class="fas fa-lock text-emerald-600"></i>
                        <span>Dokumen berstatus <strong>COMPLETED</strong>. Seluruh hasil cetak telah selesai dicatat dan dikunci (Read-Only).</span>
                    </div>
                @elseif($printOrder->status === 'DRAFT')
                    <!-- DRAFT ACTIONS -->
                    <a href="{{ route('lost-wax.print-orders.print', $printOrder) }}" target="_blank"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-5 rounded-lg text-sm shadow flex items-center gap-2 transition-all">
                        <i class="fas fa-print"></i> Cetak Draft
                    </a>

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
                @elseif($printOrder->status === 'ISSUED' || $printOrder->status === 'PARTIALLY_COMPLETED')
                    <!-- ISSUED / PARTIALLY_COMPLETED ACTIONS -->
                    <a href="{{ route('lost-wax.print-orders.print', $printOrder) }}" target="_blank"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-5 rounded-lg text-sm shadow flex items-center gap-2 transition-all">
                        <i class="fas fa-print"></i> Cetak Dokumen
                    </a>

                    @if($printOrder->status === 'ISSUED' && ! $printOrder->hasRecordedOutcomes())
                        <!-- Cancel Doc (ISSUED -> CANCELLED) -->
                        <form action="{{ route('lost-wax.print-orders.update-status', $printOrder) }}" method="POST" class="inline">
                            @csrf
                            <input type="hidden" name="status" value="CANCELLED">
                            <button type="submit" onclick="return confirm('Apakah Anda yakin ingin membatalkan Perintah Cetak ini?')"
                                class="bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 font-bold py-2 px-5 rounded-lg text-sm shadow flex items-center gap-2 transition-all">
                                <i class="fas fa-ban"></i> Batalkan
                            </button>
                        </form>
                    @endif
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

        <!-- 4. Daftar Item / Hasil Cetak (Read-Only Table) -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <div class="flex items-center justify-between mb-4 border-b border-slate-100 pb-2">
                <h3 class="text-slate-800 font-bold text-base flex items-center gap-2">
                    <i class="fas fa-cubes text-amber-500"></i>
                    <span>Daftar Item / Hasil Cetak</span>
                </h3>
                <span class="text-xs text-slate-500 font-semibold">{{ $totalItems }} Item Terdaftar</span>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full border-collapse border border-slate-200 text-xs">
                    <thead class="bg-slate-800 text-white uppercase text-[10px] tracking-wider font-bold">
                        <tr>
                            <th class="border border-slate-700 py-2.5 px-3 text-center w-10">#</th>
                            <th class="border border-slate-700 py-2.5 px-3 text-left w-28">Kode Produksi</th>
                            <th class="border border-slate-700 py-2.5 px-3 text-left">Nama Produk</th>
                            <th class="border border-slate-700 py-2.5 px-3 text-left w-28">Customer</th>
                            <th class="border border-slate-700 py-2.5 px-3 text-right w-24">Qty Perintah</th>
                            <th class="border border-slate-700 py-2.5 px-3 text-right w-20">Good</th>
                            <th class="border border-slate-700 py-2.5 px-3 text-right w-20">Defect</th>
                            <th class="border border-slate-700 py-2.5 px-3 text-right w-24">Outstanding</th>
                            <th class="border border-slate-700 py-2.5 px-3 text-center w-28">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                        @foreach($printOrder->lines as $index => $line)
                            @php
                                $good = (int) ($line->qty_executed_good ?? ($line->qty_actual_good ?? 0));
                                $defect = (int) ($line->qty_executed_defect ?? ($line->qty_actual_defect ?? 0));
                                $outstanding = (int) $line->qty_outstanding;
                                $status = $line->execution_status ?? ($good + $defect >= $line->qty_ordered ? 'COMPLETED' : ($good + $defect > 0 ? 'IN_PROGRESS' : 'NOT_STARTED'));
                                
                                $statusBadge = match($status) {
                                    'COMPLETED' => '<span class="px-2 py-0.5 rounded text-[9px] font-extrabold bg-emerald-100 text-emerald-800 border border-emerald-200 uppercase">SELESAI</span>',
                                    'IN_PROGRESS' => '<span class="px-2 py-0.5 rounded text-[9px] font-extrabold bg-amber-100 text-amber-800 border border-amber-200 uppercase">BERJALAN</span>',
                                    default => '<span class="px-2 py-0.5 rounded text-[9px] font-extrabold bg-slate-100 text-slate-600 border border-slate-200 uppercase">BELUM MULAI</span>'
                                };
                            @endphp
                            <tr class="hover:bg-slate-50/70 transition-colors {{ $status === 'COMPLETED' ? 'bg-slate-50/30' : '' }}">
                                <td class="border border-slate-200 py-2.5 px-3 text-center text-slate-400 font-mono">{{ $index + 1 }}</td>
                                <td class="border border-slate-200 py-2.5 px-3 font-mono font-bold text-slate-900">{{ $line->code ?: '-' }}</td>
                                <td class="border border-slate-200 py-2.5 px-3">
                                    <div class="font-bold text-slate-900 leading-snug">{{ $line->item_name }}</div>
                                    <div class="text-[10px] text-slate-400 flex flex-wrap items-center gap-1.5 mt-0.5">
                                        <span>AISI: <strong class="text-slate-600">{{ $line->aisi ?: '-' }}</strong></span>
                                        <span>&bull;</span>
                                        <span>Size: <strong class="text-slate-600">{{ $line->size ?: '-' }}</strong></span>
                                        <span>&bull;</span>
                                        <span>Std Cap: <strong class="text-slate-600">{{ $line->standard_tree_capacity ?: 20 }} pcs</strong></span>
                                    </div>
                                </td>
                                <td class="border border-slate-200 py-2.5 px-3 text-slate-700 font-medium truncate max-w-[120px]" title="{{ $line->customer }}">{{ $line->customer ?: '-' }}</td>
                                <td class="border border-slate-200 py-2.5 px-3 text-right font-bold text-slate-800">{{ number_format($line->qty_ordered) }} pcs</td>
                                <td class="border border-slate-200 py-2.5 px-3 text-right font-black text-emerald-600">{{ number_format($good) }} pcs</td>
                                <td class="border border-slate-200 py-2.5 px-3 text-right font-bold text-rose-600">{{ number_format($defect) }} pcs</td>
                                <td class="border border-slate-200 py-2.5 px-3 text-right font-black {{ $outstanding > 0 ? 'text-amber-700' : 'text-slate-400' }}">
                                    {{ number_format($outstanding) }} pcs
                                </td>
                                <td class="border border-slate-200 py-2.5 px-3 text-center">{!! $statusBadge !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50 font-bold text-slate-800">
                        <tr>
                            <td colspan="4" class="border border-slate-200 py-2.5 px-3 text-right uppercase text-[10px] tracking-wider text-slate-500">Total Keseluruhan</td>
                            <td class="border border-slate-200 py-2.5 px-3 text-right font-black text-slate-900">{{ number_format($totalOrdered) }} pcs</td>
                            <td class="border border-slate-200 py-2.5 px-3 text-right font-black text-emerald-600">{{ number_format($totalGood) }} pcs</td>
                            <td class="border border-slate-200 py-2.5 px-3 text-right font-black text-rose-600">{{ number_format($totalDefect) }} pcs</td>
                            <td class="border border-slate-200 py-2.5 px-3 text-right font-black {{ $totalOutstanding > 0 ? 'text-amber-700' : 'text-slate-400' }}">{{ number_format($totalOutstanding) }} pcs</td>
                            <td class="border border-slate-200 py-2.5 px-3 text-center">
                                @if($totalOutstanding <= 0)
                                    <span class="text-emerald-700 font-extrabold text-[10px]">100% SELESAI</span>
                                @else
                                    <span class="text-amber-700 font-extrabold text-[10px]">{{ $percentProgress }}%</span>
                                @endif
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- 5. Riwayat Pencatatan Hasil (Outcome Execution History) -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <div class="flex items-center justify-between mb-4 border-b border-slate-100 pb-2">
                <h3 class="text-slate-800 font-bold text-base flex items-center gap-2">
                    <i class="fas fa-history text-blue-500"></i>
                    <span>Riwayat Pencatatan Hasil</span>
                </h3>
                <span class="text-xs text-slate-500 font-semibold">{{ $sortedExecutions->count() }} Catatan Riwayat</span>
            </div>

            @if($sortedExecutions->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="min-w-full border-collapse border border-slate-200 text-xs">
                        <thead class="bg-slate-100 text-slate-700 uppercase text-[10px] tracking-wider font-bold">
                            <tr>
                                <th class="border border-slate-200 py-2 px-3 text-center w-10">#</th>
                                <th class="border border-slate-200 py-2 px-3 text-left w-28">Tanggal Eksekusi</th>
                                <th class="border border-slate-200 py-2 px-3 text-left w-28">Kode Produksi</th>
                                <th class="border border-slate-200 py-2 px-3 text-left">Nama Produk</th>
                                <th class="border border-slate-200 py-2 px-3 text-right w-20">Good</th>
                                <th class="border border-slate-200 py-2 px-3 text-right w-20">Defect</th>
                                <th class="border border-slate-200 py-2 px-3 text-left w-32">Operator / Pencatat</th>
                                <th class="border border-slate-200 py-2 px-3 text-center w-24">Status</th>
                                <th class="border border-slate-200 py-2 px-3 text-left">Catatan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                            @foreach($sortedExecutions as $index => $exec)
                                @php
                                    $line = $exec->printOrderLine;
                                    $recorderName = optional($exec->recorder)->name ?: 'System';
                                @endphp
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="border border-slate-200 py-2 px-3 text-center text-slate-400 font-mono">{{ $index + 1 }}</td>
                                    <td class="border border-slate-200 py-2 px-3 font-mono font-semibold text-slate-800">
                                        {{ $exec->execution_date ? $exec->execution_date->format('d/m/Y') : '-' }}
                                        @if($exec->created_at)
                                            <span class="block text-[9px] text-slate-400 font-normal">{{ $exec->created_at->format('H:i') }} WIB</span>
                                        @endif
                                    </td>
                                    <td class="border border-slate-200 py-2 px-3 font-mono font-bold text-slate-900">{{ $line ? $line->code : '-' }}</td>
                                    <td class="border border-slate-200 py-2 px-3 font-medium text-slate-800">{{ $line ? $line->item_name : '-' }}</td>
                                    <td class="border border-slate-200 py-2 px-3 text-right font-black text-emerald-600">+{{ number_format($exec->qty_good) }} pcs</td>
                                    <td class="border border-slate-200 py-2 px-3 text-right font-bold text-rose-600">
                                        {{ $exec->qty_defect > 0 ? '+'.number_format($exec->qty_defect).' pcs' : '-' }}
                                    </td>
                                    <td class="border border-slate-200 py-2 px-3 text-slate-600 font-medium">{{ $recorderName }}</td>
                                    <td class="border border-slate-200 py-2 px-3 text-center">
                                        <span class="px-2 py-0.5 rounded text-[9px] font-extrabold uppercase {{ $exec->status === 'FINALIZED' ? 'bg-emerald-100 text-emerald-800 border border-emerald-200' : 'bg-amber-100 text-amber-800 border border-amber-200' }}">
                                            {{ $exec->status }}
                                        </span>
                                    </td>
                                    <td class="border border-slate-200 py-2 px-3 text-slate-500 italic text-[11px]">
                                        {{ $exec->notes ?: '-' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="py-8 text-center text-slate-400 bg-slate-50 rounded-lg border border-slate-200">
                    <i class="fas fa-clipboard-check text-2xl mb-1.5 text-slate-300 block"></i>
                    <div class="font-bold text-slate-600 text-xs">Belum ada riwayat eksekusi terperinci</div>
                    <div class="text-[11px] text-slate-400 mt-0.5">
                        @if($totalGood > 0 || $totalDefect > 0)
                            Hasil cetak tercatat sebagai data agregat aktual ({{ number_format($totalGood) }} pcs Good, {{ number_format($totalDefect) }} pcs Defect).
                        @else
                            Hasil cetak belum dicatat di menu Outcomes.
                        @endif
                    </div>
                </div>
            @endif
        </div>

    </div>

    @if(session('success'))
        <script>
            sessionStorage.removeItem(@json($selectionStorageKey));
        </script>
    @endif
@endsection
