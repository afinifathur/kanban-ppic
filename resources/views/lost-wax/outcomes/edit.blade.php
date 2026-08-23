@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Catat Hasil Perintah Cetak</h1>
            <p class="text-gray-500 text-[10px]">Dokumen {{ $printOrder->print_order_number }} &mdash; Jadwal: {{ $printOrder->scheduled_date->format('d-m-Y') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('lost-wax.outcomes.index') }}" class="text-slate-500 hover:text-slate-700 text-xs font-semibold flex items-center gap-1.5 bg-white px-3 py-1.5 rounded-lg border border-slate-200 shadow-sm transition-all">
                <i class="fas fa-arrow-left"></i> Kembali
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="max-w-6xl mx-auto pb-12">
        
        <!-- Header Info Card -->
        <div class="bg-gradient-to-r from-slate-800 to-slate-900 rounded-2xl shadow-md border border-slate-700/50 p-6 mb-6 text-white relative overflow-hidden">
            <div class="absolute -right-16 -top-16 opacity-10">
                <i class="fas fa-print text-9xl"></i>
            </div>
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <span class="text-slate-400 text-xs uppercase font-bold tracking-widest">No. Perintah Cetak</span>
                    <h2 class="text-2xl font-black tracking-tight mt-0.5">{{ $printOrder->print_order_number }}</h2>
                </div>
                <div class="flex flex-wrap gap-4 text-xs">
                    <div class="bg-slate-700/40 border border-slate-600/30 rounded-xl px-4 py-2">
                        <span class="text-slate-400 block text-[9px] uppercase font-bold">Tanggal Jadwal</span>
                        <span class="font-bold text-slate-100">{{ $printOrder->scheduled_date->format('d-m-Y') }}</span>
                    </div>
                    <div class="bg-slate-700/40 border border-slate-600/30 rounded-xl px-4 py-2">
                        <span class="text-slate-400 block text-[9px] uppercase font-bold">Status Dokumen</span>
                        @php
                            $statusClass = 'text-slate-400';
                            if ($printOrder->status === 'ISSUED') {
                                $statusClass = 'text-blue-400';
                            } elseif ($printOrder->status === 'PARTIALLY_COMPLETED') {
                                $statusClass = 'text-amber-400';
                            } elseif ($printOrder->status === 'COMPLETED') {
                                $statusClass = 'text-emerald-400';
                            }
                        @endphp
                        <span class="font-bold {{ $statusClass }} uppercase tracking-wider">{{ str_replace('_', ' ', $printOrder->status) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-8">
            @foreach($printOrder->lines as $index => $line)
                @php
                    $allocated = (int) $line->trees->sum('quantity');
                @endphp
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden hover:shadow-md transition-shadow duration-300">
                    
                    <!-- Line Header -->
                    <div class="bg-gradient-to-r from-slate-700 to-slate-800 px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-600">
                        <div class="min-w-0">
                            <span class="text-amber-400 text-[10px] uppercase font-bold tracking-widest">Item Perintah Cetak #{{ $index + 1 }}</span>
                            <h3 class="text-white font-bold text-base truncate mt-0.5">{{ $line->item_name }}</h3>
                        </div>
                        <div>
                            <span class="px-3 py-1 rounded-full text-xs font-extrabold border uppercase tracking-wider
                                {{ $line->execution_status === 'COMPLETED' ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30' : ($line->execution_status === 'IN_PROGRESS' ? 'bg-amber-500/20 text-amber-300 border-amber-500/30' : 'bg-slate-600/40 text-slate-300 border-slate-600/30') }}">
                                {{ $line->execution_status }}
                            </span>
                        </div>
                    </div>

                    <!-- Line Details Grid & Stats -->
                    <div class="p-6 space-y-6">
                        
                        <!-- Metadata and Tree Capacity Update -->
                        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 bg-slate-50 border border-slate-100 rounded-xl p-4">
                            <!-- Technical specifications -->
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-slate-600">
                                <div>Kode: <strong class="text-slate-800">{{ $line->code ?? '-' }}</strong></div>
                                <div class="text-slate-300">|</div>
                                <div>Cust: <strong class="text-slate-800">{{ $line->customer ?? '-' }}</strong></div>
                                <div class="text-slate-300">|</div>
                                <div>AISI: <strong class="text-slate-800">{{ $line->aisi ?? '-' }}</strong></div>
                                <div class="text-slate-300">|</div>
                                <div>Size: <strong class="text-slate-800">{{ $line->size ?? '-' }}</strong></div>
                            </div>

                            <!-- Capacity tree setting inline -->
                            <div class="capacity-container flex items-center gap-2 self-start lg:self-auto" data-line-id="{{ $line->id }}" data-current-good="{{ $line->qty_executed_good ?? 0 }}" data-current-defect="{{ $line->qty_executed_defect ?? 0 }}">
                                <label class="text-xs font-semibold text-slate-700" for="cap_{{ $line->id }}">Kapasitas Tree Standard:</label>
                                <div class="flex items-center gap-1.5">
                                    <input type="number" id="cap_{{ $line->id }}" class="capacity-input w-16 text-center text-xs font-bold rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500 py-1" value="{{ $line->standard_tree_capacity ?? 20 }}" min="1">
                                    <button type="button" onclick="updateCapacity({{ $line->id }})" class="bg-slate-700 hover:bg-slate-800 text-white text-xs font-bold py-1 px-2.5 rounded-lg transition-all shadow-sm" title="Simpan Kapasitas">
                                        <i class="fas fa-save"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Progress Metrics Grid (Isolated PLAN, GOOD, DEFECT, OUTSTANDING) -->
                        <div>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 text-center">
                                    <span class="block text-slate-400 text-[10px] uppercase font-bold tracking-widest mb-1">Qty Rencana (PLAN)</span>
                                    <span class="text-lg font-black text-slate-700">{{ number_format($line->qty_ordered) }} pcs</span>
                                </div>
                                <div class="bg-emerald-50/50 border border-emerald-100 rounded-xl p-4 text-center">
                                    <span class="block text-emerald-600/70 text-[10px] uppercase font-bold tracking-widest mb-1">Sudah Good</span>
                                    <span class="text-lg font-black text-emerald-700">{{ number_format($line->qty_executed_good ?? 0) }} pcs</span>
                                </div>
                                <div class="bg-rose-50/50 border border-rose-100 rounded-xl p-4 text-center">
                                    <span class="block text-rose-600/70 text-[10px] uppercase font-bold tracking-widest mb-1">Rusak (Defect)</span>
                                    <span class="text-lg font-black text-rose-700">{{ number_format($line->qty_executed_defect ?? 0) }} pcs</span>
                                </div>
                                <div class="bg-amber-50/80 border-2 border-amber-200 rounded-xl p-4 text-center relative shadow-sm">
                                    <div class="absolute top-1 right-2 text-amber-300">
                                        <i class="fas fa-exclamation-circle text-xs"></i>
                                    </div>
                                    <span class="block text-amber-700 text-[10px] uppercase font-bold tracking-widest mb-1">Sisa Outstanding</span>
                                    <span class="text-xl font-extrabold text-amber-700">{{ number_format($line->qty_outstanding) }} pcs</span>
                                </div>
                            </div>
                            
                            <!-- Help text -->
                            <div class="mt-2 text-[10px] text-slate-400 flex flex-col gap-0.5 px-1">
                                <span>* Formula Outstanding = Qty Rencana - Sudah Good - Rusak.</span>
                                <span>* Defect dihitung sebagai bagian dari penyelesaian rencana (mengurangi outstanding). Jika fisik cetak masih kurang, supervisor dapat menambahkan Rencana baru.</span>
                            </div>

                            @if($allocated > 0)
                                <div class="mt-3 text-xs text-amber-700 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2 flex items-center gap-2">
                                    <i class="fas fa-info-circle text-amber-500"></i>
                                    <span>Karena sudah dirangkai <strong>{{ $allocated }} pcs</strong>, Total Hasil Good terkumpul minimal harus <strong>{{ $allocated }} pcs</strong>.</span>
                                </div>
                            @endif
                        </div>

                        <!-- Catat Eksekusi Hari Ini Form -->
                        @if($line->qty_outstanding > 0)
                            <div class="execution-form-container border border-slate-200 rounded-xl p-5 bg-slate-50/50 space-y-4" 
                                 data-line-id="{{ $line->id }}" 
                                 data-outstanding="{{ $line->qty_outstanding }}"
                                 data-allocated-trees="{{ $allocated }}"
                                 data-total-good-existing="{{ $line->qty_executed_good ?? 0 }}">
                                
                                <div class="flex items-center gap-2 border-b border-slate-100 pb-2">
                                    <i class="fas fa-pencil-alt text-amber-600 text-sm"></i>
                                    <h4 class="font-extrabold text-xs text-slate-700 uppercase tracking-wider">Catat Hasil Cetak Baru</h4>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
                                    <!-- Inputs (Date, Good, Defect) -->
                                    <div class="md:col-span-6 grid grid-cols-1 sm:grid-cols-3 gap-3">
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-700 mb-1" for="date_{{ $line->id }}">Tanggal Cetak <span class="text-rose-500">*</span></label>
                                            <input type="date" id="date_{{ $line->id }}" class="form-execution-date w-full text-xs rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500 py-1.5" value="{{ now()->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}" required>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-700 mb-1" for="good_{{ $line->id }}">Good (pcs) <span class="text-rose-500">*</span></label>
                                            <input type="number" id="good_{{ $line->id }}" class="form-qty-good w-full text-xs font-bold rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500 py-1.5" value="0" min="0" required>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-700 mb-1" for="defect_{{ $line->id }}">Defect (pcs) <span class="text-rose-500">*</span></label>
                                            <input type="number" id="defect_{{ $line->id }}" class="form-qty-defect w-full text-xs font-bold rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500 py-1.5" value="0" min="0" required>
                                        </div>
                                    </div>

                                    <!-- Notes -->
                                    <div class="md:col-span-6">
                                        <label class="block text-xs font-semibold text-slate-700 mb-1" for="notes_{{ $line->id }}">Catatan</label>
                                        <textarea id="notes_{{ $line->id }}" class="form-notes w-full text-xs rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500 py-1 h-[38px] resize-none" placeholder="Catatan tambahan..."></textarea>
                                    </div>
                                </div>

                                <div class="text-[10px] text-slate-400 mt-1">
                                    * Tanggal Cetak adalah tanggal pekerjaan benar-benar dilakukan. Jika laporan terlambat dicatat, Anda dapat memilih tanggal sebelumnya.
                                </div>

                                <!-- Dynamic calculations and Warnings -->
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-2">
                                    <div class="live-preview-box bg-slate-100/70 border border-slate-200/60 rounded-xl px-4 py-2.5 text-xs text-slate-600 flex flex-wrap gap-x-5 gap-y-1">
                                        <div>Output Cetak Hari Ini: <span class="preview-output-today font-bold text-slate-800">0</span> pcs</div>
                                        <div class="hidden sm:block text-slate-300">|</div>
                                        <div>Sisa Outstanding Setelah Finalisasi: <span class="preview-new-outstanding font-bold text-amber-700">{{ $line->qty_outstanding }}</span> pcs</div>
                                    </div>

                                    <div class="flex items-center gap-2 justify-end">
                                        <button type="button" onclick="submitExecution({{ $line->id }}, 'DRAFT')" class="btn-save-draft bg-white hover:bg-slate-100 text-slate-700 border border-slate-300 text-xs font-bold py-2 px-4 rounded-lg transition-all shadow-sm flex items-center gap-1.5">
                                            <i class="far fa-save text-slate-400"></i> Simpan Draft
                                        </button>
                                        <button type="button" onclick="submitExecution({{ $line->id }}, 'FINALIZED')" class="btn-finalize bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold py-2 px-4 rounded-lg transition-all shadow-sm flex items-center gap-1.5">
                                            <i class="fas fa-check-circle"></i> Finalisasi Eksekusi
                                        </button>
                                    </div>
                                </div>

                                <!-- Server/Client warning banner placeholder -->
                                <div class="validation-warning hidden text-rose-700 bg-rose-50 border border-rose-200/50 rounded-xl px-4 py-2 text-xs font-bold flex items-center gap-2">
                                    <!-- Dynamic warning message inside -->
                                </div>
                            </div>
                        @else
                            <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-4 text-center text-emerald-800 text-xs font-semibold flex items-center justify-center gap-1.5 shadow-inner">
                                <i class="fas fa-check-double text-emerald-500"></i>
                                Item ini telah selesai dieksekusi sepenuhnya (Outstanding 0 pcs).
                            </div>
                        @endif

                        <!-- Riwayat Eksekusi Harian Table -->
                        <div>
                            <div class="text-xs font-bold text-slate-700 mb-3 flex items-center gap-1">
                                <i class="fas fa-history text-slate-400"></i>
                                <span>Riwayat Eksekusi Cetak Harian:</span>
                            </div>
                            <div class="overflow-x-auto rounded-xl border border-slate-200">
                                <table class="w-full text-xs text-left border-collapse">
                                    <thead>
                                        <tr class="border-b border-slate-200 text-slate-500 font-semibold bg-slate-50/70">
                                            <th class="p-3 text-center w-16">Exec #</th>
                                            <th class="p-3">Tanggal Cetak</th>
                                            <th class="p-3 text-center w-24">Good (pcs)</th>
                                            <th class="p-3 text-center w-24">Defect (pcs)</th>
                                            <th class="p-3 text-center w-24">Total Output</th>
                                            <th class="p-3">Dicatat Oleh</th>
                                            <th class="p-3">Tanggal Dicatat</th>
                                            <th class="p-3 w-28">Status</th>
                                            <th class="p-3">Catatan</th>
                                            <th class="p-3 text-right w-36">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 text-slate-600">
                                        @forelse($line->executions->sortBy('created_at') as $exec)
                                            <tr class="execution-row hover:bg-slate-50/30 transition-colors duration-150" data-exec-id="{{ $exec->id }}">
                                                
                                                <td class="p-3 text-center font-semibold text-slate-500">#{{ $loop->iteration }}</td>
                                                
                                                <td class="p-3">
                                                    <span class="read-mode-exec-date font-medium">{{ $exec->execution_date->format('d-m-Y') }}</span>
                                                    <input type="date" class="edit-mode-exec-date hidden w-28 text-xs rounded border-slate-300 py-0.5 px-1.5 focus:ring-amber-500 focus:border-amber-500" value="{{ $exec->execution_date->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}">
                                                </td>
                                                
                                                <td class="p-3 text-center">
                                                    <span class="read-mode-qty-good font-bold text-slate-800">{{ number_format($exec->qty_good) }}</span>
                                                    <input type="number" class="edit-mode-qty-good hidden w-16 text-center text-xs font-bold rounded border-slate-300 py-0.5 px-1.5 focus:ring-amber-500 focus:border-amber-500" value="{{ $exec->qty_good }}" min="0">
                                                </td>
                                                
                                                <td class="p-3 text-center">
                                                    <span class="read-mode-qty-defect text-rose-600 font-medium">{{ number_format($exec->qty_defect) }}</span>
                                                    <input type="number" class="edit-mode-qty-defect hidden w-16 text-center text-xs font-medium rounded border-slate-300 py-0.5 px-1.5 focus:ring-amber-500 focus:border-amber-500 text-rose-600" value="{{ $exec->qty_defect }}" min="0">
                                                </td>
                                                
                                                <td class="p-3 text-center font-bold text-slate-700">
                                                    <span class="read-mode-total">{{ number_format($exec->qty_good + $exec->qty_defect) }}</span>
                                                </td>
                                                
                                                <td class="p-3">{{ $exec->recorder?->name ?? 'System' }}</td>
                                                
                                                <td class="p-3 text-slate-400">{{ $exec->created_at ? $exec->created_at->format('d-m-Y H:i') : '-' }}</td>
                                                
                                                <td class="p-3">
                                                    @if($exec->status === 'FINALIZED')
                                                        <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded text-[10px] font-extrabold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                                            <i class="fas fa-check-circle text-[8px]"></i> FINALIZED
                                                        </span>
                                                    @else
                                                        <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded text-[10px] font-extrabold bg-amber-50 text-amber-800 border border-amber-300 border-dashed">
                                                            <i class="fas fa-pencil-alt text-[8px]"></i> DRAFT
                                                        </span>
                                                    @endif
                                                </td>
                                                
                                                <td class="p-3">
                                                    <span class="read-mode-notes text-slate-400 italic">{{ $exec->notes ?? '-' }}</span>
                                                    <input type="text" class="edit-mode-notes hidden w-full text-xs rounded border-slate-300 py-0.5 px-1.5 focus:ring-amber-500 focus:border-amber-500" value="{{ $exec->notes }}" placeholder="Catatan...">
                                                </td>
                                                
                                                <td class="p-3 text-right">
                                                    @if($exec->status === 'DRAFT')
                                                        <div class="read-mode-actions flex items-center justify-end gap-2">
                                                            <button type="button" onclick="enableEditRow({{ $exec->id }})" class="text-blue-600 hover:text-blue-800 font-extrabold text-[10px] flex items-center gap-0.5">
                                                                <i class="fas fa-edit"></i> Edit
                                                            </button>
                                                            <span class="text-slate-300">|</span>
                                                            <button type="button" onclick="finalizeRow({{ $exec->id }})" class="text-emerald-600 hover:text-emerald-800 font-extrabold text-[10px] flex items-center gap-0.5">
                                                                <i class="fas fa-check-circle"></i> Finalisasi
                                                            </button>
                                                        </div>
                                                        <div class="edit-mode-actions hidden flex items-center justify-end gap-2">
                                                            <button type="button" onclick="saveRow({{ $exec->id }})" class="text-emerald-700 hover:text-emerald-950 font-extrabold text-[10px] flex items-center gap-0.5">
                                                                <i class="fas fa-save"></i> Simpan
                                                            </button>
                                                            <span class="text-slate-300">|</span>
                                                            <button type="button" onclick="disableEditRow({{ $exec->id }})" class="text-slate-500 hover:text-slate-700 font-extrabold text-[10px] flex items-center gap-0.5">
                                                                <i class="fas fa-times-circle"></i> Batal
                                                            </button>
                                                        </div>
                                                    @else
                                                        <span class="text-slate-400 italic text-[10px]"><i class="fas fa-lock text-[8px]"></i> Terkunci</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="10" class="p-6 text-center text-slate-400 italic">Belum ada eksekusi cetak harian yang dicatat. Silakan catat di form di atas.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>
                </div>
            @endforeach
        </div>

        <div class="flex justify-end mt-8">
            <a href="{{ route('lost-wax.outcomes.index') }}" class="bg-slate-700 hover:bg-slate-800 text-white text-sm font-bold py-2.5 px-6 rounded-xl transition-all shadow-sm flex items-center gap-1.5">
                <i class="fas fa-check-circle"></i> Selesai & Kembali
            </a>
        </div>
    </div>

    <!-- Client-side Interactive Logic & AJAX handlers -->
    <script>
        // Named routes templates from server to prevent 404 in subdirectory hosting
        const storeExecutionUrlTemplate = "{{ route('lost-wax.outcomes.lines.execution.store', ':line') }}";
        const updateExecutionUrlTemplate = "{{ route('lost-wax.outcomes.executions.update', ':execution') }}";
        const finalizeExecutionUrlTemplate = "{{ route('lost-wax.outcomes.executions.finalize', ':execution') }}";

        document.addEventListener('DOMContentLoaded', function () {
            // Live validation and calculations for daily execution form inputs
            document.querySelectorAll('.execution-form-container').forEach(container => {
                const goodInput = container.querySelector('.form-qty-good');
                const defectInput = container.querySelector('.form-qty-defect');
                const previewOutputToday = container.querySelector('.preview-output-today');
                const previewNewOutstanding = container.querySelector('.preview-new-outstanding');
                const warningBox = container.querySelector('.validation-warning');
                const btnDraft = container.querySelector('.btn-save-draft');
                const btnFinalize = container.querySelector('.btn-finalize');
                const dateInput = container.querySelector('.form-execution-date');

                const outstanding = parseInt(container.dataset.outstanding) || 0;
                const allocatedTrees = parseInt(container.dataset.allocatedTrees) || 0;
                const totalGoodExisting = parseInt(container.dataset.totalGoodExisting) || 0;

                function updatePreview() {
                    const good = parseInt(goodInput.value) || 0;
                    const defect = parseInt(defectInput.value) || 0;
                    const totalInput = good + defect;

                    // Update live preview output fields
                    previewOutputToday.textContent = totalInput;
                    const newOutstanding = Math.max(0, outstanding - totalInput);
                    previewNewOutstanding.textContent = newOutstanding;

                    let warningMsg = '';

                    // Check outstanding limit boundary
                    if (totalInput > outstanding) {
                        warningMsg = `⚠️ Total hasil (Good + Defect) cetak baru (${totalInput} pcs) tidak boleh melebihi sisa outstanding saat ini (${outstanding} pcs).`;
                    }

                    // Check allocated trees boundary
                    const projectedTotalGood = totalGoodExisting + good;
                    if (allocatedTrees > 0 && projectedTotalGood < allocatedTrees) {
                        warningMsg = `⚠️ Total Hasil Good terkumpul (${projectedTotalGood} pcs) tidak boleh kurang dari jumlah tree yang sudah dirangkai (${allocatedTrees} pcs).`;
                    }

                    // Check date validity (cannot be future)
                    const selectedDate = new Date(dateInput.value);
                    const todayDate = new Date();
                    todayDate.setHours(23,59,59,999); // allow up to end of today
                    if (selectedDate > todayDate) {
                        warningMsg = `⚠️ Tanggal Cetak tidak boleh di masa depan (maksimal hari ini).`;
                    }

                    // Show warning banner and disable buttons if invalid
                    if (warningMsg) {
                        warningBox.textContent = warningMsg;
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

                goodInput.addEventListener('input', updatePreview);
                defectInput.addEventListener('input', updatePreview);
                dateInput.addEventListener('change', updatePreview);
            });
        });

        // 1. Submit a brand new Daily Execution (AJAX POST)
        function submitExecution(lineId, status) {
            const container = document.querySelector(`.execution-form-container[data-line-id="${lineId}"]`);
            if (!container) return;

            const good = parseInt(container.querySelector('.form-qty-good').value) || 0;
            const defect = parseInt(container.querySelector('.form-qty-defect').value) || 0;
            const date = container.querySelector('.form-execution-date').value;
            const notes = container.querySelector('.form-notes').value;

            if (!date) {
                Swal.fire('Gagal', 'Tanggal Cetak harus dipilih.', 'error');
                return;
            }

            Swal.fire({
                title: status === 'FINALIZED' ? 'Finalisasikan Hasil Cetak?' : 'Simpan Draft Hasil Cetak?',
                text: status === 'FINALIZED' 
                    ? 'Hasil cetak akan dikunci, memotong outstanding, dan tidak dapat diubah lagi.' 
                    : 'Hasil cetak akan disimpan sebagai rancangan (draft) sementara.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: status === 'FINALIZED' ? '#d97706' : '#475569',
                cancelButtonColor: '#94a3b8',
                confirmButtonText: status === 'FINALIZED' ? 'Ya, Finalisasi' : 'Ya, Simpan Draft',
                cancelButtonText: 'Batal',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    const url = storeExecutionUrlTemplate.replace(':line', lineId);
                    return axios.post(url, {
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
                        const msg = error.response && error.response.data && error.response.data.message 
                            ? error.response.data.message 
                            : error.message;
                        Swal.showValidationMessage(`Gagal: ${msg}`);
                    });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil',
                        text: 'Catatan hasil cetak berhasil disimpan.',
                        timer: 1200,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                }
            });
        }

        // 2. Update Standard Tree Capacity (AJAX PUT via legacy updateOutcome)
        function updateCapacity(lineId) {
            const container = document.querySelector(`.capacity-container[data-line-id="${lineId}"]`);
            if (!container) return;

            const newCapacity = parseInt(container.querySelector('.capacity-input').value) || 20;
            const currentGood = parseInt(container.dataset.currentGood) || 0;
            const currentDefect = parseInt(container.dataset.currentDefect) || 0;

            if (newCapacity < 1) {
                Swal.fire('Gagal', 'Kapasitas tree minimal harus 1.', 'error');
                return;
            }

            Swal.fire({
                title: 'Update Kapasitas Tree?',
                text: 'Mengubah standar kapasitas tree cetak untuk item ini.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#475569',
                cancelButtonColor: '#94a3b8',
                confirmButtonText: 'Ya, Simpan',
                cancelButtonText: 'Batal',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    const url = "{{ route('lost-wax.outcomes.update', $printOrder) }}";
                    return axios.put(url, {
                        items: [
                            {
                                id: lineId,
                                qty_actual_good: currentGood,
                                qty_actual_defect: currentDefect,
                                standard_tree_capacity: newCapacity
                            }
                        ]
                    }).then(response => {
                        return response.data;
                    }).catch(error => {
                        const msg = error.response && error.response.data && error.response.data.message 
                            ? error.response.data.message 
                            : error.message;
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
                        timer: 1200,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                }
            });
        }

        // 3. Enable inline editing for a history draft row
        function enableEditRow(execId) {
            const row = document.querySelector(`.execution-row[data-exec-id="${execId}"]`);
            if (!row) return;

            row.querySelectorAll('.read-mode-exec-date, .read-mode-qty-good, .read-mode-qty-defect, .read-mode-notes, .read-mode-actions, .read-mode-total').forEach(el => el.classList.add('hidden'));
            row.querySelectorAll('.edit-mode-exec-date, .edit-mode-qty-good, .edit-mode-qty-defect, .edit-mode-notes, .edit-mode-actions').forEach(el => el.classList.remove('hidden'));
        }

        // 4. Cancel/Disable inline editing for a history draft row
        function disableEditRow(execId) {
            const row = document.querySelector(`.execution-row[data-exec-id="${execId}"]`);
            if (!row) return;

            row.querySelectorAll('.read-mode-exec-date, .read-mode-qty-good, .read-mode-qty-defect, .read-mode-notes, .read-mode-actions, .read-mode-total').forEach(el => el.classList.remove('hidden'));
            row.querySelectorAll('.edit-mode-exec-date, .edit-mode-qty-good, .edit-mode-qty-defect, .edit-mode-notes, .edit-mode-actions').forEach(el => el.classList.add('hidden'));
        }

        // 5. Save modified Draft Execution inline (AJAX PUT)
        function saveRow(execId) {
            const row = document.querySelector(`.execution-row[data-exec-id="${execId}"]`);
            if (!row) return;

            const date = row.querySelector('.edit-mode-exec-date').value;
            const good = parseInt(row.querySelector('.edit-mode-qty-good').value) || 0;
            const defect = parseInt(row.querySelector('.edit-mode-qty-defect').value) || 0;
            const notes = row.querySelector('.edit-mode-notes').value;

            if (!date) {
                Swal.fire('Gagal', 'Tanggal Cetak harus diisi.', 'error');
                return;
            }

            // Client side validation against future date
            const selectedDate = new Date(date);
            const todayDate = new Date();
            todayDate.setHours(23,59,59,999);
            if (selectedDate > todayDate) {
                Swal.fire('Gagal', 'Tanggal Cetak tidak boleh di masa depan.', 'error');
                return;
            }

            Swal.fire({
                title: 'Simpan Perubahan Draft?',
                text: 'Laporan cetak draft ini akan diperbarui.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#475569',
                cancelButtonColor: '#94a3b8',
                confirmButtonText: 'Ya, Simpan',
                cancelButtonText: 'Batal',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    const url = updateExecutionUrlTemplate.replace(':execution', execId);
                    return axios.put(url, {
                        qty_good: good,
                        qty_defect: defect,
                        execution_date: date,
                        notes: notes
                    }).then(response => {
                        if (response.data.success) {
                            return response.data;
                        }
                        throw new Error(response.data.message || 'Gagal memperbarui draft.');
                    }).catch(error => {
                        const msg = error.response && error.response.data && error.response.data.message 
                            ? error.response.data.message 
                            : error.message;
                        Swal.showValidationMessage(`Gagal: ${msg}`);
                    });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil',
                        text: 'Draft laporan cetak diperbarui.',
                        timer: 1200,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                }
            });
        }

        // 6. Finalize a Draft Execution inline (AJAX POST)
        function finalizeRow(execId) {
            Swal.fire({
                title: 'Finalisasikan Hasil Cetak?',
                text: 'Hasil draft ini akan dikunci resmi dan memotong sisa outstanding.',
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
                        const msg = error.response && error.response.data && error.response.data.message 
                            ? error.response.data.message 
                            : error.message;
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
                        timer: 1200,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                }
            });
        }
    </script>
@endsection
