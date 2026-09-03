@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-xl font-bold text-slate-900 leading-tight">Workbench Catat Hasil Perintah Cetak</h1>
            <p class="text-slate-500 text-[10px]">Dokumen {{ $printOrder->print_order_number }} &mdash; Jadwal: {{ $printOrder->scheduled_date->format('d-m-Y') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('lost-wax.outcomes.index') }}" class="text-slate-700 hover:text-slate-900 text-xs font-semibold flex items-center gap-1.5 bg-white hover:bg-slate-50 px-3 py-2 rounded-lg border border-slate-200 shadow-sm transition-all">
                <i class="fas fa-arrow-left"></i> Kembali ke Daftar
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="max-w-7xl mx-auto space-y-3 pb-6">
        
        <!-- 1. COMPACT HEADER PRINT ORDER SUMMARY -->
        @php
            $totalItems = $printOrder->lines->count();
            $totalOrdered = (int) $printOrder->lines->sum('qty_ordered');
            $totalGood = (int) $printOrder->lines->sum(fn($l) => $l->qty_executed_good ?? 0);
            $totalDefect = (int) $printOrder->lines->sum(fn($l) => $l->qty_executed_defect ?? 0);
            $totalOutstanding = (int) $printOrder->lines->sum('qty_outstanding');
            $totalCompleted = $totalGood + $totalDefect;
            $percentProgress = $totalOrdered > 0 ? min(100, round(($totalCompleted / $totalOrdered) * 100)) : 0;
            
            $statusClass = match($printOrder->status) {
                'ISSUED' => 'bg-blue-950 text-blue-300 border-blue-800',
                'PARTIALLY_COMPLETED' => 'bg-amber-950 text-amber-300 border-amber-800',
                'COMPLETED' => 'bg-emerald-950 text-emerald-300 border-emerald-800',
                default => 'bg-slate-800 text-slate-300 border-slate-700'
            };
        @endphp

        <div class="bg-slate-900 text-white rounded-xl shadow-xs border border-slate-800 py-3.5 px-4 sm:px-5">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
                
                <!-- Identitas Dokumen -->
                <div class="space-y-0.5">
                    <div class="flex items-center gap-2">
                        <span class="text-[9px] uppercase font-bold text-amber-400 tracking-wider">Catat Hasil Perintah Cetak</span>
                        <span class="text-slate-500 text-xs">&bull;</span>
                        <span class="text-xs font-bold text-slate-300 font-mono">{{ $printOrder->scheduled_date->format('d-m-Y') }}</span>
                    </div>
                    <h2 class="text-xl md:text-2xl font-black text-white tracking-tight flex items-center gap-2 font-mono">
                        {{ $printOrder->print_order_number }}
                    </h2>
                    <div class="flex flex-wrap items-center gap-2 text-[11px] text-slate-300">
                        <span>Total: <strong class="text-amber-300">{{ number_format($totalItems) }} Item</strong></span>
                        <span>&bull;</span>
                        <span>Rencana: <strong class="text-white">{{ number_format($totalOrdered) }} pcs</strong></span>
                    </div>
                </div>

                <!-- Status & KPI Badges (Compact Inline) -->
                <div class="flex flex-wrap items-center gap-2.5">
                    <div class="bg-slate-800/90 border border-slate-700/70 rounded-lg px-3 py-1.5 text-center">
                        <span class="text-[8px] uppercase font-bold text-emerald-400 tracking-wider block">Total Good</span>
                        <span class="text-sm font-black text-emerald-300">{{ number_format($totalGood) }}</span>
                    </div>
                    <div class="bg-slate-800/90 border border-slate-700/70 rounded-lg px-3 py-1.5 text-center">
                        <span class="text-[8px] uppercase font-bold text-rose-400 tracking-wider block">Total Defect</span>
                        <span class="text-sm font-black text-rose-300">{{ number_format($totalDefect) }}</span>
                    </div>
                    <div class="bg-slate-800/90 border border-slate-700/70 rounded-lg px-3 py-1.5 text-center">
                        <span class="text-[8px] uppercase font-bold text-amber-400 tracking-wider block">Outstanding</span>
                        <span class="text-sm font-black text-amber-300">{{ number_format($totalOutstanding) }}</span>
                    </div>

                    <div class="flex flex-col items-end gap-1 pl-1">
                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase border tracking-wider {{ $statusClass }}">
                            STATUS: {{ str_replace('_', ' ', $printOrder->status) }}
                        </span>
                        <span class="text-[9px] text-slate-400 font-semibold">Progress: {{ $percentProgress }}% ({{ number_format($totalCompleted) }}/{{ number_format($totalOrdered) }} pcs)</span>
                    </div>
                </div>
            </div>

            <!-- Slim Progress Bar -->
            <div class="w-full bg-slate-800 rounded-full h-1 mt-3 overflow-hidden">
                <div class="bg-amber-500 h-1 rounded-full transition-all duration-500" style="width: {{ $percentProgress }}%"></div>
            </div>
        </div>

        <!-- 2. COMPACT FILTER & PENCARIAN -->
        <div class="bg-white rounded-xl shadow-2xs border border-slate-200 px-4 py-2.5">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-2.5">
                
                <div class="flex flex-col sm:flex-row sm:items-center gap-2 flex-1">
                    <!-- Search Kode Produksi -->
                    <div class="w-full sm:w-48">
                        <div class="relative">
                            <i class="fas fa-barcode absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                            <input type="text" id="filterCode" placeholder="Kode (e.g. 268L651)..."
                                class="w-full pl-7 pr-2.5 py-1 text-xs font-medium rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500 font-mono">
                        </div>
                    </div>

                    <!-- Search Nama Produk -->
                    <div class="w-full sm:flex-1">
                        <div class="relative">
                            <i class="fas fa-search absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                            <input type="text" id="filterProduct" placeholder="Nama Produk / SKU..."
                                class="w-full pl-7 pr-2.5 py-1 text-xs font-medium rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500">
                        </div>
                    </div>

                    <!-- Filter Status -->
                    <div class="w-full sm:w-44">
                        <select id="filterStatus" class="w-full py-1 px-2.5 text-xs font-medium rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500">
                            <option value="">Semua Status</option>
                            <option value="NOT_STARTED">BELUM MULAI</option>
                            <option value="IN_PROGRESS">BERJALAN</option>
                            <option value="COMPLETED">SELESAI</option>
                        </select>
                    </div>

                    <!-- Tombol Reset -->
                    <button type="button" id="btnResetFilter" class="px-2.5 py-1 text-xs font-bold text-slate-600 hover:text-slate-800 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors flex items-center justify-center gap-1 shrink-0">
                        <i class="fas fa-undo text-[9px]"></i> Reset
                    </button>
                </div>

                <!-- Item Counter -->
                <div class="text-xs font-semibold text-slate-500 shrink-0 text-right">
                    Menampilkan <strong id="itemCountDisplay" class="text-slate-800">{{ $totalItems }}</strong> dari {{ $totalItems }} item
                </div>
            </div>
        </div>

        <!-- 3. TABEL ITEM DENGAN INTERNAL SCROLL & STICKY HEADER -->
        <div class="table-scroll-container overflow-y-auto overflow-x-auto border border-slate-200 rounded-xl bg-white shadow-2xs"
             style="max-height: calc(100vh - 250px); min-height: 380px;">
            <table class="w-full text-left text-xs border-collapse" id="workbenchTable">
                <thead class="sticky top-0 z-10 bg-slate-800 text-white uppercase text-[10px] tracking-wider font-bold shadow-xs">
                    <tr>
                        <th class="sticky top-0 bg-slate-800 py-2.5 px-3 text-center w-10">#</th>
                        <th class="sticky top-0 bg-slate-800 py-2.5 px-3 w-28">Kode</th>
                        <th class="sticky top-0 bg-slate-800 py-2.5 px-3">Nama Produk</th>
                        <th class="sticky top-0 bg-slate-800 py-2.5 px-3 w-28">Customer</th>
                        <th class="sticky top-0 bg-slate-800 py-2.5 px-3 text-right w-20">Rencana</th>
                        <th class="sticky top-0 bg-slate-800 py-2.5 px-3 text-right w-20">Good</th>
                        <th class="sticky top-0 bg-slate-800 py-2.5 px-3 text-right w-20">Defect</th>
                        <th class="sticky top-0 bg-slate-800 py-2.5 px-3 text-right w-24">Outstanding</th>
                        <th class="sticky top-0 bg-slate-800 py-2.5 px-3 text-center w-28">Status</th>
                        <th class="sticky top-0 bg-slate-800 py-2.5 px-3 text-center w-28">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                    @forelse($printOrder->lines as $index => $line)
                        @php
                            $good = (int) ($line->qty_executed_good ?? 0);
                            $defect = (int) ($line->qty_executed_defect ?? 0);
                            $outstanding = (int) $line->qty_outstanding;
                            $allocatedTrees = (int) $line->trees->where('status', '!=', 'cancelled')->sum('quantity');
                            $status = $line->execution_status ?? ($good + $defect >= $line->qty_ordered ? 'COMPLETED' : ($good + $defect > 0 ? 'IN_PROGRESS' : 'NOT_STARTED'));
                            
                            $statusBadge = match($status) {
                                'COMPLETED' => '<span class="px-2 py-0.5 rounded text-[9px] font-extrabold bg-emerald-100 text-emerald-800 border border-emerald-200 uppercase">SELESAI</span>',
                                'IN_PROGRESS' => '<span class="px-2 py-0.5 rounded text-[9px] font-extrabold bg-amber-100 text-amber-800 border border-amber-200 uppercase">BERJALAN</span>',
                                default => '<span class="px-2 py-0.5 rounded text-[9px] font-extrabold bg-slate-100 text-slate-600 border border-slate-200 uppercase">BELUM MULAI</span>'
                            };
                        @endphp
                        <tr class="item-row hover:bg-amber-50/50 transition-colors {{ $status === 'COMPLETED' ? 'bg-slate-50/40' : '' }}"
                            data-line-id="{{ $line->id }}"
                            data-code="{{ strtolower($line->code ?? '') }}"
                            data-product="{{ strtolower($line->item_name ?? '') }}"
                            data-status="{{ $status }}"
                            data-ordered="{{ $line->qty_ordered }}"
                            data-good="{{ $good }}"
                            data-defect="{{ $defect }}"
                            data-outstanding="{{ $outstanding }}"
                            data-allocated-trees="{{ $allocatedTrees }}"
                            data-capacity="{{ $line->standard_tree_capacity ?? 20 }}"
                            data-aisi="{{ $line->aisi ?? '-' }}"
                            data-size="{{ $line->size ?? '-' }}"
                            data-customer="{{ $line->customer ?? '-' }}">
                            
                            <td class="py-2 px-3 text-center text-slate-400 font-mono">{{ $index + 1 }}</td>
                            <td class="py-2 px-3 font-bold text-slate-900 font-mono">{{ $line->code ?? '-' }}</td>
                            <td class="py-2 px-3">
                                <div class="font-bold text-slate-900 leading-snug">{{ $line->item_name }}</div>
                                <div class="text-[10px] text-slate-400 flex flex-wrap items-center gap-1.5 mt-0.5">
                                    <span>Sudah Good: <strong class="text-emerald-700">{{ $good }} pcs</strong></span>
                                    <span>&bull;</span>
                                    <span>Defect: <strong class="text-rose-700">{{ $defect }} pcs</strong></span>
                                    <span>&bull;</span>
                                    <span>Outstanding: <strong class="text-amber-700">{{ $outstanding }} pcs</strong></span>
                                    <span>&bull;</span>
                                    <span>AISI: {{ $line->aisi ?? '-' }}</span>
                                    <span>&bull;</span>
                                    <span>Size: {{ $line->size ?? '-' }}</span>
                                    <span>&bull;</span>
                                    <span>Std Cap: {{ $line->standard_tree_capacity ?? 20 }} pcs</span>
                                </div>
                            </td>
                            <td class="py-2 px-3 text-slate-600 truncate max-w-[120px]" title="{{ $line->customer }}">{{ $line->customer ?? '-' }}</td>
                            <td class="py-2 px-3 text-right font-bold text-slate-800">{{ number_format($line->qty_ordered) }}</td>
                            <td class="py-2 px-3 text-right font-black text-emerald-600">{{ number_format($good) }}</td>
                            <td class="py-2 px-3 text-right font-bold text-rose-600">{{ number_format($defect) }}</td>
                            <td class="py-2 px-3 text-right font-black {{ $outstanding > 0 ? 'text-amber-700' : 'text-slate-400' }}">
                                {{ number_format($outstanding) }}
                            </td>
                            <td class="py-2 px-3 text-center">{!! $statusBadge !!}</td>
                            <td class="py-2 px-3 text-center">
                                @if($outstanding > 0)
                                    <button type="button" onclick="openCatatHasilModal({{ $line->id }})"
                                        class="bg-amber-600 hover:bg-amber-700 text-white font-extrabold text-[10.5px] px-2.5 py-1 rounded-lg transition-all shadow-2xs flex items-center justify-center gap-1 w-full">
                                        <i class="fas fa-pencil-alt text-[8.5px]"></i> Catat Hasil
                                    </button>
                                @else
                                    <button type="button" onclick="openCatatHasilModal({{ $line->id }})"
                                        class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-[10.5px] px-2.5 py-1 rounded-lg transition-all border border-slate-200 flex items-center justify-center gap-1 w-full">
                                        <i class="fas fa-list text-[8.5px]"></i> Riwayat
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="py-10 text-center text-slate-400 italic">
                                Tidak ada item perintah cetak yang ditemukan.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <!-- Empty search state -->
            <div id="noResultsState" class="hidden py-10 text-center text-slate-400">
                <i class="fas fa-search text-2xl mb-1.5 text-slate-300 block"></i>
                <div class="font-bold text-slate-700 text-xs">Tidak ada item yang sesuai dengan filter</div>
                <div class="text-[11px] text-slate-400 mt-0.5">Coba ubah kata kunci kode produksi, nama produk, atau reset filter.</div>
            </div>
        </div>

    </div>

    <!-- 4. MODAL CATAT HASIL CETAK (BAGIAN 4, 5, 6) -->
    <div id="modalCatatHasil" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="bg-white rounded-2xl max-w-2xl w-full p-6 shadow-2xl border border-slate-200 transform transition-all space-y-5 max-h-[90vh] overflow-y-auto">
            
            <!-- Modal Header -->
            <div class="flex items-start justify-between border-b border-slate-100 pb-3 gap-3">
                <div class="space-y-0.5">
                    <span class="text-[10px] uppercase font-extrabold text-amber-600 tracking-wider block">Catat Hasil Eksekusi Cetak</span>
                    <h3 class="text-lg font-black text-slate-900 leading-snug" id="modalProductName">
                        -
                    </h3>
                    <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500 font-mono pt-0.5">
                        <span>Kode: <strong id="modalProductCode" class="text-slate-800">-</strong></span>
                        <span>&bull;</span>
                        <span>Customer: <strong id="modalCustomer" class="text-slate-800">-</strong></span>
                        <span>&bull;</span>
                        <span>AISI: <strong id="modalAisi" class="text-slate-800">-</strong></span>
                        <span>&bull;</span>
                        <span>Size: <strong id="modalSize" class="text-slate-800">-</strong></span>
                    </div>
                </div>
                <button type="button" onclick="closeCatatHasilModal()" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-100 transition-colors">
                    <i class="fas fa-times text-base"></i>
                </button>
            </div>

            <!-- Progress Metrics Grid Modal -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
                <div class="bg-slate-50 border border-slate-200 rounded-xl p-3">
                    <span class="block text-slate-400 text-[9px] uppercase font-bold tracking-wider mb-0.5">Qty Rencana</span>
                    <span id="modalQtyOrdered" class="text-base font-black text-slate-700">0 pcs</span>
                </div>
                <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3">
                    <span class="block text-emerald-600 text-[9px] uppercase font-bold tracking-wider mb-0.5">Sudah Good</span>
                    <span id="modalQtyGood" class="text-base font-black text-emerald-700">0 pcs</span>
                </div>
                <div class="bg-rose-50 border border-rose-100 rounded-xl p-3">
                    <span class="block text-rose-600 text-[9px] uppercase font-bold tracking-wider mb-0.5">Rusak (Defect)</span>
                    <span id="modalQtyDefect" class="text-base font-black text-rose-700">0 pcs</span>
                </div>
                <div class="bg-amber-50 border-2 border-amber-200 rounded-xl p-3">
                    <span class="block text-amber-700 text-[9px] uppercase font-bold tracking-wider mb-0.5">Outstanding</span>
                    <span id="modalQtyOutstanding" class="text-base font-black text-amber-700">0 pcs</span>
                </div>
            </div>

            <!-- Form Catat Hasil Input (BAGIAN 5) -->
            <div id="formSectionContainer" class="space-y-4 bg-slate-50/70 border border-slate-200 rounded-xl p-4">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <h4 class="font-bold text-xs uppercase text-slate-700 tracking-wide flex items-center gap-1.5">
                        <i class="fas fa-pencil-alt text-amber-600"></i> Formulir Catat Hasil Baru
                    </h4>
                    <span class="text-[10px] text-slate-400">* Tanggal dan Kuantitas wajib diisi</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Tanggal Cetak <span class="text-red-500">*</span></label>
                        <input type="date" id="modalInputDate" value="{{ date('Y-m-d') }}" max="{{ date('Y-m-d') }}"
                            class="w-full rounded-lg border-slate-300 text-xs focus:border-amber-500 focus:ring-amber-500 shadow-sm font-semibold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Hasil Cetak / Counter (pcs) <span class="text-red-500">*</span></label>
                        <input type="number" id="modalInputGross" value="0" min="0" oninput="calculateModalNetGood()"
                            class="w-full rounded-lg border-slate-300 text-xs focus:border-blue-500 focus:ring-blue-500 shadow-sm font-bold text-blue-700" placeholder="Counter mesin / Gross">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Cacat / Defect (pcs) <span class="text-red-500">*</span></label>
                        <input type="number" id="modalInputDefect" value="0" min="0" oninput="calculateModalNetGood()"
                            class="w-full rounded-lg border-slate-300 text-xs focus:border-rose-500 focus:ring-rose-500 shadow-sm font-bold text-rose-700" placeholder="Jumlah lilin rusak">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-center">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Hasil Good / Net (pcs)</label>
                        <input type="number" id="modalInputGood" value="0" min="0" readonly
                            class="w-full rounded-lg border-emerald-300 bg-emerald-50/70 text-xs shadow-sm font-black text-emerald-800 cursor-not-allowed">
                        <span class="text-[10px] text-slate-400">Otomatis = Counter &minus; Cacat</span>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold text-slate-700 mb-1">Catatan</label>
                        <input type="text" id="modalInputNotes" placeholder="Catatan tambahan hasil cetak (opsional)..."
                            class="w-full rounded-lg border-slate-300 text-xs focus:border-amber-500 focus:ring-amber-500 shadow-sm">
                    </div>
                </div>

                <!-- Dynamic Preview Box -->
                <div class="grid grid-cols-2 gap-3 p-3 bg-white border border-slate-200 rounded-lg text-xs">
                    <div>Output Hari Ini: <strong id="modalPreviewToday" class="text-slate-900 font-bold">0</strong> pcs</div>
                    <div>Sisa Outstanding Setelah Finalisasi: <strong id="modalPreviewRemaining" class="text-amber-700 font-bold">0</strong> pcs</div>
                </div>

                <!-- Validation Warning Box -->
                <div id="modalWarningBox" class="hidden p-3 bg-red-50 border border-red-200 text-red-800 rounded-lg text-xs font-semibold"></div>

                <!-- Buttons: Simpan Draft & Finalisasi (BAGIAN 6) -->
                <div class="flex items-center justify-end gap-2.5 pt-2">
                    <button type="button" onclick="closeCatatHasilModal()" class="px-4 py-2 text-xs font-bold text-slate-600 hover:text-slate-800 bg-slate-200 hover:bg-slate-300 rounded-lg transition-colors">
                        Batal
                    </button>
                    <button type="button" id="btnModalSaveDraft" onclick="submitModalExecution('DRAFT')" class="px-4 py-2 text-xs font-bold text-slate-700 bg-white hover:bg-slate-100 border border-slate-300 rounded-lg transition-colors flex items-center gap-1.5 shadow-2xs">
                        <i class="fas fa-save text-slate-500"></i> Simpan Draft
                    </button>
                    <button type="button" id="btnModalFinalize" onclick="submitModalExecution('FINALIZED')" class="px-5 py-2 text-xs font-black text-white bg-amber-600 hover:bg-amber-700 rounded-lg transition-colors shadow-sm flex items-center gap-1.5">
                        <i class="fas fa-check-circle"></i> Finalisasi Eksekusi
                    </button>
                </div>
            </div>

            <!-- Standard Tree Capacity Setting Inline -->
            <div class="flex items-center justify-between p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                <div class="space-y-0.5">
                    <span class="font-bold text-slate-800 block">Kapasitas Tree Standard:</span>
                    <span class="text-[10px] text-slate-400">Pedoman pembagian lilin per pohon saat proses rangkai.</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <input type="number" id="modalInputCapacity" class="w-16 text-center text-xs font-bold rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500 py-1" min="1">
                    <button type="button" onclick="updateModalCapacity()" class="bg-slate-700 hover:bg-slate-800 text-white text-xs font-bold py-1 px-3 rounded-lg transition-all shadow-sm">
                        <i class="fas fa-save"></i> Simpan
                    </button>
                </div>
            </div>

            <!-- Execution History for Selected Item -->
            <div class="space-y-2 border-t border-slate-100 pt-3">
                <h4 class="font-bold text-xs uppercase text-slate-700 tracking-wide flex items-center justify-between">
                    <span>Riwayat Pencatatan Item Ini</span>
                    <span id="modalHistoryCount" class="text-[10px] text-slate-400 font-normal">0 Catatan</span>
                </h4>
                <div id="modalHistoryContainer" class="space-y-2 max-h-48 overflow-y-auto">
                    <!-- Dynamic history rows will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden template data for history rendering per line -->
    <div id="historyDataStore" class="hidden">
        @foreach($printOrder->lines as $line)
            <div id="history_line_{{ $line->id }}" data-line-id="{{ $line->id }}">
                @forelse($line->executions->sortByDesc('id') as $exec)
                    <div class="history-item p-3 bg-slate-50 rounded-lg border border-slate-200 flex items-center justify-between gap-3 text-xs"
                        data-exec-id="{{ $exec->id }}"
                        data-status="{{ $exec->status }}"
                        data-good="{{ $exec->qty_good }}"
                        data-defect="{{ $exec->qty_defect }}"
                        data-date="{{ $exec->execution_date->format('d-m-Y') }}"
                        data-notes="{{ $exec->notes ?? '' }}"
                        data-recorder="{{ $exec->recorder?->name ?? 'System' }}">
                        <div class="space-y-0.5">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-slate-800">#{{ $loop->iteration }} &bull; {{ $exec->execution_date->format('d-m-Y') }}</span>
                                <span class="px-2 py-0.5 rounded text-[9px] font-extrabold uppercase {{ $exec->status === 'FINALIZED' ? 'bg-emerald-100 text-emerald-800 border border-emerald-200' : 'bg-amber-100 text-amber-800 border border-amber-200' }}">
                                    {{ $exec->status }}
                                </span>
                            </div>
                            <div class="text-[11px] text-slate-600">
                                Good: <strong class="text-emerald-700">{{ $exec->qty_good }} pcs</strong> &bull;
                                Defect: <strong class="text-rose-700">{{ $exec->qty_defect }} pcs</strong>
                                @if($exec->notes)
                                    &bull; <em>"{{ $exec->notes }}"</em>
                                @endif
                            </div>
                        </div>
                        <div>
                            @if($exec->status === 'DRAFT')
                                <button type="button" onclick="finalizeHistoryRow({{ $exec->id }})" class="px-2.5 py-1 text-[10px] font-bold text-white bg-amber-600 hover:bg-amber-700 rounded-md shadow-2xs">
                                    Finalisasi Draft
                                </button>
                            @else
                                <span class="text-[10px] text-slate-400 italic">Terkunci</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="p-3 text-center text-slate-400 italic text-xs bg-slate-50 rounded-lg border border-slate-200">
                        Belum ada riwayat pencatatan hasil untuk item ini.
                    </div>
                @endforelse
            </div>
        @endforeach
    </div>

    <!-- Scripts: Filter, Modal & Ajax Actions -->
    <script>
        const storeExecutionUrlTemplate = "{{ route('lost-wax.outcomes.lines.execution.store', ':line') }}";
        const finalizeExecutionUrlTemplate = "{{ route('lost-wax.outcomes.executions.finalize', ':execution') }}";
        const updateOutcomeUrl = "{{ route('lost-wax.outcomes.update', $printOrder) }}";

        let currentActiveLineId = null;

        // 1. Live Filter Workbench Logic
        function applyTableFilter() {
            const filterCode = document.getElementById('filterCode').value.trim().toLowerCase();
            const filterProduct = document.getElementById('filterProduct').value.trim().toLowerCase();
            const filterStatus = document.getElementById('filterStatus').value;

            const rows = document.querySelectorAll('.item-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const code = row.dataset.code || '';
                const product = row.dataset.product || '';
                const status = row.dataset.status || '';

                const matchCode = !filterCode || code.includes(filterCode);
                const matchProduct = !filterProduct || product.includes(filterProduct);
                const matchStatus = !filterStatus || status === filterStatus;

                if (matchCode && matchProduct && matchStatus) {
                    row.classList.remove('hidden');
                    visibleCount++;
                } else {
                    row.classList.add('hidden');
                }
            });

            document.getElementById('itemCountDisplay').textContent = visibleCount;
            const noResultsState = document.getElementById('noResultsState');
            if (visibleCount === 0) {
                noResultsState.classList.remove('hidden');
            } else {
                noResultsState.classList.add('hidden');
            }
        }

        // 2. Open Modal for Selected Item
        function openCatatHasilModal(lineId) {
            const row = document.querySelector(`.item-row[data-line-id="${lineId}"]`);
            if (!row) return;

            currentActiveLineId = lineId;

            // Populate Item Details
            document.getElementById('modalProductName').textContent = row.querySelector('td:nth-child(3) .font-bold').textContent;
            document.getElementById('modalProductCode').textContent = row.dataset.code.toUpperCase();
            document.getElementById('modalCustomer').textContent = row.dataset.customer;
            document.getElementById('modalAisi').textContent = row.dataset.aisi;
            document.getElementById('modalSize').textContent = row.dataset.size;

            // Populate Metrics
            document.getElementById('modalQtyOrdered').textContent = Number(row.dataset.ordered).toLocaleString() + ' pcs';
            document.getElementById('modalQtyGood').textContent = Number(row.dataset.good).toLocaleString() + ' pcs';
            document.getElementById('modalQtyDefect').textContent = Number(row.dataset.defect).toLocaleString() + ' pcs';
            document.getElementById('modalQtyOutstanding').textContent = Number(row.dataset.outstanding).toLocaleString() + ' pcs';
            document.getElementById('modalInputCapacity').value = row.dataset.capacity;

            // Reset inputs
            document.getElementById('modalInputDate').value = new Date().toISOString().split('T')[0];
            const defaultGross = parseInt(row.dataset.outstanding) || 0;
            document.getElementById('modalInputGross').value = '0';
            document.getElementById('modalInputDefect').value = '0';
            document.getElementById('modalInputGood').value = '0';
            document.getElementById('modalInputNotes').value = '';

            // Update live preview calculation
            calculateModalNetGood();

            // Load History for this item
            const historyContainer = document.getElementById('modalHistoryContainer');
            const historySource = document.getElementById(`history_line_${lineId}`);
            if (historySource) {
                historyContainer.innerHTML = historySource.innerHTML;
                const itemsCount = historySource.querySelectorAll('.history-item').length;
                document.getElementById('modalHistoryCount').textContent = itemsCount + ' Catatan';
            } else {
                historyContainer.innerHTML = '<div class="p-3 text-center text-slate-400 italic text-xs">Belum ada riwayat.</div>';
                document.getElementById('modalHistoryCount').textContent = '0 Catatan';
            }

            // Open Modal
            const modal = document.getElementById('modalCatatHasil');
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            setTimeout(() => {
                const grossInput = document.getElementById('modalInputGross');
                grossInput.focus();
                grossInput.select();
            }, 50);
        }

        function closeCatatHasilModal() {
            const modal = document.getElementById('modalCatatHasil');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            currentActiveLineId = null;
        }

        // 3. Dynamic Calculation in Modal Form
        function calculateModalNetGood() {
            const gross = parseInt(document.getElementById('modalInputGross').value) || 0;
            const defect = parseInt(document.getElementById('modalInputDefect').value) || 0;
            const netGood = Math.max(0, gross - defect);
            document.getElementById('modalInputGood').value = netGood;
            updateModalPreview();
        }

        function updateModalPreview() {
            if (!currentActiveLineId) return;
            const row = document.querySelector(`.item-row[data-line-id="${currentActiveLineId}"]`);
            if (!row) return;

            const outstanding = parseInt(row.dataset.outstanding) || 0;
            const gross = parseInt(document.getElementById('modalInputGross').value) || 0;
            const defect = parseInt(document.getElementById('modalInputDefect').value) || 0;
            const good = Math.max(0, gross - defect);

            document.getElementById('modalPreviewToday').textContent = gross.toLocaleString();
            const newOutstanding = Math.max(0, outstanding - gross);
            document.getElementById('modalPreviewRemaining').textContent = newOutstanding.toLocaleString() + ' pcs';

            const warningBox = document.getElementById('modalWarningBox');
            const btnDraft = document.getElementById('btnModalSaveDraft');
            const btnFinalize = document.getElementById('btnModalFinalize');

            let warning = '';
            if (defect > gross) {
                warning = `⚠️ Kuantitas Cacat (${defect} pcs) tidak boleh melebihi Hasil Cetak / Counter (${gross} pcs).`;
            }

            const allocatedTrees = parseInt(row.dataset.allocatedTrees) || 0;
            const totalGoodExisting = parseInt(row.dataset.good) || 0;
            const projectedTotalGood = totalGoodExisting + good;

            if (!warning && allocatedTrees > 0 && projectedTotalGood < allocatedTrees) {
                warning = `⚠️ Total Hasil Good (${projectedTotalGood} pcs) tidak boleh kurang dari tree yang sudah dirangkai (${allocatedTrees} pcs).`;
            }

            const selectedDate = new Date(document.getElementById('modalInputDate').value);
            const todayDate = new Date();
            todayDate.setHours(23, 59, 59, 999);
            if (!warning && selectedDate > todayDate) {
                warning = '⚠️ Tanggal Cetak tidak boleh di masa depan.';
            }

            if (warning) {
                warningBox.textContent = warning;
                warningBox.classList.remove('hidden');
                btnDraft.disabled = true;
                btnFinalize.disabled = true;
                btnDraft.classList.add('opacity-40', 'cursor-not-allowed');
                btnFinalize.classList.add('opacity-40', 'cursor-not-allowed');
            } else {
                warningBox.classList.add('hidden');
                btnDraft.disabled = false;
                btnFinalize.disabled = false;
                btnDraft.classList.remove('opacity-40', 'cursor-not-allowed');
                btnFinalize.classList.remove('opacity-40', 'cursor-not-allowed');
            }
        }

        // 4. Submit Execution via AJAX (Draft or Finalized)
        function submitModalExecution(status) {
            if (!currentActiveLineId) return;

            const gross = parseInt(document.getElementById('modalInputGross').value) || 0;
            const defect = parseInt(document.getElementById('modalInputDefect').value) || 0;
            const good = Math.max(0, gross - defect);
            const date = document.getElementById('modalInputDate').value;
            const notes = document.getElementById('modalInputNotes').value;

            if (!date) {
                Swal.fire('Gagal', 'Tanggal Cetak harus dipilih.', 'error');
                return;
            }

            Swal.fire({
                title: status === 'FINALIZED' ? 'Finalisasikan Hasil Cetak?' : 'Simpan Draft Hasil Cetak?',
                text: status === 'FINALIZED'
                    ? 'Hasil cetak akan dikunci resmi, memotong outstanding, dan dialokasikan untuk perangkaian.'
                    : 'Hasil cetak akan disimpan sebagai draft sementara.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: status === 'FINALIZED' ? '#d97706' : '#475569',
                cancelButtonColor: '#94a3b8',
                confirmButtonText: status === 'FINALIZED' ? 'Ya, Finalisasi' : 'Ya, Simpan Draft',
                cancelButtonText: 'Batal',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    const url = storeExecutionUrlTemplate.replace(':line', currentActiveLineId);
                    return axios.post(url, {
                        qty_gross_output: gross,
                        qty_good: good,
                        qty_defect: defect,
                        execution_date: date,
                        status: status,
                        notes: notes
                    }).then(response => {
                        if (response.data.success) {
                            return response.data;
                        }
                        throw new Error(response.data.message || 'Gagal menyimpan hasil.');
                    }).catch(error => {
                        const msg = error.response?.data?.message || error.message;
                        Swal.showValidationMessage(`Gagal: ${msg}`);
                    });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil',
                        text: status === 'FINALIZED' ? 'Laporan cetak berhasil difinalisasi.' : 'Draft berhasil disimpan.',
                        timer: 1000,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.reload();
                    });
                }
            });
        }

        // 5. Finalize Existing Draft Row
        function finalizeHistoryRow(execId) {
            Swal.fire({
                title: 'Finalisasikan Draft Ini?',
                text: 'Hasil draft akan dikunci resmi dan memotong sisa outstanding.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d97706',
                cancelButtonColor: '#94a3b8',
                confirmButtonText: 'Ya, Finalisasi',
                cancelButtonText: 'Batal',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    const url = finalizeExecutionUrlTemplate.replace(':execution', execId);
                    return axios.post(url).then(response => {
                        if (response.data.success) {
                            return response.data;
                        }
                        throw new Error(response.data.message || 'Gagal melakukan finalisasi.');
                    }).catch(error => {
                        const msg = error.response?.data?.message || error.message;
                        Swal.showValidationMessage(`Gagal: ${msg}`);
                    });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil',
                        text: 'Laporan cetak berhasil difinalisasi.',
                        timer: 1000,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.reload();
                    });
                }
            });
        }

        // 6. Update Tree Capacity
        function updateModalCapacity() {
            if (!currentActiveLineId) return;
            const newCapacity = parseInt(document.getElementById('modalInputCapacity').value) || 20;

            if (newCapacity < 1) {
                Swal.fire('Gagal', 'Kapasitas tree minimal 1.', 'error');
                return;
            }

            const row = document.querySelector(`.item-row[data-line-id="${currentActiveLineId}"]`);
            const currentGood = parseInt(row?.dataset.good) || 0;
            const currentDefect = parseInt(row?.dataset.defect) || 0;

            Swal.fire({
                title: 'Update Kapasitas Tree?',
                text: 'Mengubah standar kapasitas tree untuk item ini.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#475569',
                cancelButtonColor: '#94a3b8',
                confirmButtonText: 'Ya, Simpan',
                cancelButtonText: 'Batal',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    return axios.put(updateOutcomeUrl, {
                        items: [
                            {
                                id: currentActiveLineId,
                                qty_actual_good: currentGood,
                                qty_actual_defect: currentDefect,
                                standard_tree_capacity: newCapacity
                            }
                        ]
                    }).then(response => response.data)
                    .catch(error => {
                        const msg = error.response?.data?.message || error.message;
                        Swal.showValidationMessage(`Gagal: ${msg}`);
                    });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil',
                        text: 'Kapasitas tree berhasil diperbarui.',
                        timer: 1000,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.reload();
                    });
                }
            });
        }

        // Event listeners on page load
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('filterCode').addEventListener('input', applyTableFilter);
            document.getElementById('filterProduct').addEventListener('input', applyTableFilter);
            document.getElementById('filterStatus').addEventListener('change', applyTableFilter);

            document.getElementById('btnResetFilter').addEventListener('click', function () {
                document.getElementById('filterCode').value = '';
                document.getElementById('filterProduct').value = '';
                document.getElementById('filterStatus').value = '';
                applyTableFilter();
            });

            document.getElementById('modalInputGood').addEventListener('input', updateModalPreview);
            document.getElementById('modalInputDefect').addEventListener('input', updateModalPreview);
            document.getElementById('modalInputDate').addEventListener('change', updateModalPreview);

            const modal = document.getElementById('modalCatatHasil');
            if (modal) {
                modal.addEventListener('click', function (e) {
                    if (e.target === modal) {
                        closeCatatHasilModal();
                    }
                });
            }

            document.addEventListener('keydown', function (e) {
                if (modal && !modal.classList.contains('hidden') && e.key === 'Escape') {
                    closeCatatHasilModal();
                }
            });
        });
    </script>
@endsection
