@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-xl font-bold text-slate-900 leading-tight">Detail Tree / Traveler</h1>
            <p class="text-slate-500 text-[10px] font-mono">{{ $tree->barcode }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('lost-wax.trees.traveler', $tree) }}" target="_blank"
                class="bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold py-1.5 px-3 rounded-lg shadow-2xs transition-colors inline-flex items-center gap-1.5">
                <i class="fas fa-print text-[11px]"></i> Print Traveler
            </a>
            <a href="{{ route('lost-wax.trees.index') }}" class="text-slate-700 hover:text-slate-900 text-xs font-semibold flex items-center gap-1.5 bg-white hover:bg-slate-50 px-3 py-1.5 rounded-lg border border-slate-200 shadow-2xs transition-all">
                <i class="fas fa-arrow-left text-[11px]"></i> Kembali
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="max-w-5xl mx-auto space-y-4 pb-12">
        
        <!-- 1. HERO CARD: NAMA PRODUK PROMINENT & STATUS UTAMA -->
        <div class="bg-white rounded-2xl shadow-xs border border-slate-200 p-5 space-y-4">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 border-b border-slate-100 pb-4">
                <div class="space-y-1 flex-1">
                    <span class="text-[10px] uppercase font-bold text-amber-600 tracking-wider block">Item / Produk</span>
                    <h2 class="text-xl md:text-2xl font-black text-slate-900 leading-snug">
                        {{ $tree->getSourceProduct() ?? '-' }}
                    </h2>
                    <div class="flex flex-wrap items-center gap-2.5 text-xs text-slate-600 font-medium pt-0.5">
                        <span>SKU: <strong class="text-slate-900 font-mono">{{ $tree->getSourceItemCode() ?? '-' }}</strong></span>
                        <span>&bull;</span>
                        <span>Kode Cust: <strong class="text-slate-900 font-mono">{{ $tree->getSourceCode() ?? '-' }}</strong></span>
                        <span>&bull;</span>
                        <span>Customer: <strong class="text-slate-900">{{ $tree->getSourceCustomer() ?? '-' }}</strong></span>
                    </div>
                </div>

                <!-- Badges Status -->
                <div class="flex flex-row md:flex-col items-start md:items-end gap-1.5 shrink-0">
                    <span class="px-3 py-1 rounded-full text-xs font-extrabold border uppercase tracking-wider
                        {{ $tree->current_stage === 'oven' ? 'bg-emerald-100 text-emerald-800 border-emerald-300' : ($tree->current_stage ? 'bg-amber-100 text-amber-800 border-amber-300' : 'bg-blue-100 text-blue-800 border-blue-300') }}">
                        {{ $tree->current_stage_label }}
                    </span>
                    <span class="text-[10px] text-slate-500 font-bold uppercase">
                        Status: <span class="text-slate-800">{{ ucfirst(str_replace('_', ' ', $tree->status)) }}</span>
                    </span>
                </div>
            </div>

            <!-- Barcode & Quick Specs Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-center">
                <!-- Barcode Image -->
                <div class="bg-slate-50 border border-slate-200 rounded-xl p-3.5 text-center flex flex-col items-center justify-center">
                    <img src="{{ route('lost-wax.trees.barcode', $tree) }}" alt="Barcode" class="max-h-12 object-contain mb-1.5">
                    <div class="text-xs font-bold font-mono text-slate-800 tracking-wider">{{ $tree->human_barcode }}</div>
                </div>

                <!-- Tree Specs -->
                <div class="md:col-span-2 grid grid-cols-2 sm:grid-cols-3 gap-2.5">
                    <div class="bg-slate-50 border border-slate-100 rounded-lg p-2.5">
                        <span class="block text-[9px] uppercase font-bold text-slate-400">Tree #</span>
                        <span class="text-sm font-black text-slate-800 font-mono">{{ str_pad((string) $tree->tree_number, 3, '0', STR_PAD_LEFT) }}</span>
                    </div>
                    <div class="bg-slate-50 border border-slate-100 rounded-lg p-2.5">
                        <span class="block text-[9px] uppercase font-bold text-slate-400">Gross Qty</span>
                        <span class="text-sm font-black text-slate-800">{{ number_format($tree->quantity) }} pcs</span>
                    </div>
                    <div class="{{ $tree->usable_quantity > 0 ? 'bg-emerald-50 border-emerald-100 text-emerald-800' : 'bg-rose-50 border-rose-100 text-rose-800' }} border rounded-lg p-2.5">
                        <span class="block text-[9px] uppercase font-bold {{ $tree->usable_quantity > 0 ? 'text-emerald-700' : 'text-rose-700' }}">Usable Qty</span>
                        <span class="text-sm font-black">{{ number_format($tree->usable_quantity) }} pcs</span>
                    </div>
                    <div class="bg-slate-50 border border-slate-100 rounded-lg p-2.5">
                        <span class="block text-[9px] uppercase font-bold text-slate-400">Tgl Produksi</span>
                        <span class="text-xs font-bold text-slate-800">{{ $tree->production_date->format('d-m-Y') }}</span>
                    </div>
                    <div class="bg-slate-50 border border-slate-100 rounded-lg p-2.5">
                        <span class="block text-[9px] uppercase font-bold text-slate-400">Family Code</span>
                        <span class="text-xs font-bold text-slate-800">{{ $tree->family_code }}</span>
                    </div>
                    <div class="bg-slate-50 border border-slate-100 rounded-lg p-2.5">
                        <span class="block text-[9px] uppercase font-bold text-slate-400">Posisi Rack</span>
                        <span class="text-xs font-bold {{ $tree->coatingRack ? 'text-blue-700' : 'text-slate-400' }}">
                            {{ $tree->coatingRack ? 'RAK-'.str_pad($tree->coatingRack->rack_number, 2, '0', STR_PAD_LEFT) : 'Belum Ada Rack' }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Reference Order Metadata -->
            <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 text-xs text-slate-600 flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-3">
                    @if($tree->work_order_id && $tree->workOrder)
                        <div>
                            <span class="text-slate-400 font-semibold">Work Order:</span>
                            <a href="{{ route('lost-wax.work-orders.show', $tree->workOrder) }}" class="text-amber-600 hover:text-amber-700 font-bold ml-1">
                                {{ $tree->getSourceCode() }}
                            </a>
                            @if($tree->plan)
                                <span class="text-slate-400 ml-1.5">(Wave {{ str_pad((string) $tree->plan->wave_number, 3, '0', STR_PAD_LEFT) }})</span>
                            @endif
                        </div>
                    @elseif($tree->lost_wax_print_order_line_id && $tree->printOrderLine)
                        <div>
                            <span class="text-slate-400 font-semibold">Perintah Cetak:</span>
                            <a href="{{ route('lost-wax.print-orders.show', $tree->printOrderLine->lost_wax_print_order_id) }}" class="text-amber-600 hover:text-amber-700 font-bold ml-1 font-mono">
                                {{ $tree->getSourcePrintOrderNumber() }}
                            </a>
                        </div>
                    @else
                        <div class="text-slate-400 italic">Dokumen referensi tidak terhubung</div>
                    @endif

                    @if($tree->allocations && $tree->allocations->isNotEmpty())
                        <div class="text-[11px] text-slate-500 bg-white border border-slate-200 rounded px-2 py-0.5">
                            <span class="text-slate-400 font-semibold">Alokasi FIFO:</span>
                            @foreach($tree->allocations as $alloc)
                                <span class="font-mono font-bold text-slate-800 ml-1">
                                    {{ optional($alloc->printOrderLine->printOrder)->print_order_number ?? $alloc->printOrderLine->code }} ({{ $alloc->allocated_qty }} pcs)
                                </span>{{ !$loop->last ? ',' : '' }}
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="flex items-center gap-3 text-slate-500">
                    <span>Size: <strong class="text-slate-800">{{ $tree->getSourceSize() ?? '-' }}</strong></span>
                    <span>&bull;</span>
                    <span>AISI: <strong class="text-slate-800">{{ $tree->getSourceAisi() ?? '-' }}</strong></span>
                </div>
            </div>
        </div>

        <!-- 2. KOREKSI QUANTITY (JIKA CORRECTABLE) -->
        @if($tree->is_correctable && !auth()->user()->hasRole('admin_qc_fitting'))
            <div class="bg-white rounded-xl shadow-xs border border-slate-200 p-4">
                <h3 class="font-bold text-xs uppercase text-slate-700 tracking-wider mb-2.5 flex items-center gap-1.5">
                    <i class="fas fa-pen text-amber-600"></i> Koreksi Quantity Rangkaian
                </h3>
                <form method="POST" action="{{ route('lost-wax.trees.update', $tree) }}" class="flex items-end gap-3">
                    @csrf
                    @method('PATCH')
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Quantity (pcs)</label>
                        <input type="number" name="quantity" value="{{ $tree->quantity }}" min="1"
                            class="w-32 rounded-lg border-slate-300 text-xs font-bold py-1.5 px-3 focus:ring-amber-500 focus:border-amber-500">
                    </div>
                    <button type="submit" class="bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold py-2 px-4 rounded-lg shadow-2xs transition-colors">
                        Simpan Koreksi
                    </button>
                </form>
            </div>
        @endif

        <!-- 3. SECTION QUALITY & DEFECT LOG -->
        @php
            $treeDefectsList = isset($defects) ? $defects : $tree->defects;
            $totalDefectsCount = $tree->total_defect_quantity;
            $preSiapCorDefects = $tree->pre_siap_cor_defect_quantity;
            $postSiapCorDefects = $tree->post_siap_cor_defect_quantity;
            $isCancelled = $tree->status === 'cancelled';
            $isCorLocked = $tree->current_stage === 'cor' || ($tree->activeCastingResultTree !== null);
            $isCutoffLocked = $tree->is_historical_cutoff;
            $canMutateDefect = ($isDefectMutable ?? (!$isCancelled && !$isCorLocked && !$isCutoffLocked))
                && !auth()->user()->hasRole('admin_qc_fitting');
            $hasActiveSiapCor = isset($activeSiapCorEvent) && $activeSiapCorEvent !== null;
        @endphp

        <div class="bg-white rounded-2xl shadow-xs border border-slate-200 p-5 space-y-4">
            <!-- Section Header & Actions -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 pb-3">
                <div>
                    <h3 class="text-base font-black text-slate-900 flex items-center gap-2">
                        <i class="fas fa-shield-alt text-amber-600"></i> LOG KUALITAS & DEFECT
                    </h3>
                    <p class="text-[11px] text-slate-400">Pencatatan cacat produk pada tahapan Rangkai, Lapisan 1–7, atau Oven (termasuk Pasca-Siap Cor sebelum penuangan)</p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <!-- KPI Chips -->
                    <div class="bg-slate-100 border border-slate-200 rounded-lg px-2.5 py-1 text-center">
                        <span class="text-[9px] uppercase font-bold text-slate-500 block">Gross Qty</span>
                        <span class="text-xs font-black text-slate-800">{{ number_format($tree->quantity) }} pcs</span>
                    </div>
                    @if($hasActiveSiapCor)
                        <div class="bg-sky-50 border border-sky-200 text-sky-900 rounded-lg px-2.5 py-1 text-center"
                             title="Histori terkoreksi: {{ $tree->siap_cor_historical_quantity }} pcs{{ $activeSiapCorEvent->scanned_qty !== null ? ' | Snapshot saat scan: '.$activeSiapCorEvent->scanned_qty.' pcs' : '' }}">
                            <span class="text-[9px] uppercase font-bold text-sky-700 block">Siap Cor Hist</span>
                            <span class="text-xs font-black">{{ number_format($tree->siap_cor_historical_quantity) }} pcs</span>
                            @if($activeSiapCorEvent->scanned_qty !== null && (int) $activeSiapCorEvent->scanned_qty !== (int) $tree->siap_cor_historical_quantity)
                                <span class="block text-[8.5px] font-semibold text-sky-600">Scan: {{ number_format($activeSiapCorEvent->scanned_qty) }}</span>
                            @endif
                        </div>
                    @endif
                    <div class="{{ $totalDefectsCount > 0 ? 'bg-rose-50 border-rose-200 text-rose-800' : 'bg-slate-100 border-slate-200 text-slate-600' }} border rounded-lg px-2.5 py-1 text-center"
                         title="Pra-Siap Cor: {{ $preSiapCorDefects }} pcs | Pasca-Siap Cor: {{ $postSiapCorDefects }} pcs">
                        <span class="text-[9px] uppercase font-bold block">Total Rusak</span>
                        <span class="text-xs font-black">{{ number_format($totalDefectsCount) }} pcs</span>
                        @if($postSiapCorDefects > 0)
                            <span class="block text-[8.5px] font-semibold text-rose-600">Pra {{ $preSiapCorDefects }} / Pasca {{ $postSiapCorDefects }}</span>
                        @endif
                    </div>
                    <div class="{{ $tree->usable_quantity > 0 ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800' }} border rounded-lg px-2.5 py-1 text-center">
                        <span class="text-[9px] uppercase font-bold block">Sisa Usable</span>
                        <span class="text-xs font-black">{{ number_format($tree->usable_quantity) }} pcs</span>
                    </div>

                    <!-- Action Button -->
                    @if($canMutateDefect && $tree->usable_quantity > 0)
                        <button type="button" onclick="openDefectModal()"
                            class="bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold py-2 px-3.5 rounded-lg shadow-2xs transition-colors inline-flex items-center gap-1.5 ml-1">
                            <i class="fas fa-plus text-[10px]"></i> Catat Defect
                        </button>
                    @elseif($isCancelled)
                        <span class="text-[10px] bg-slate-200 text-slate-600 font-bold px-2.5 py-1.5 rounded-lg uppercase">
                            Tree Dibatalkan
                        </span>
                    @elseif($isCorLocked)
                        <span class="text-[10px] bg-amber-100 text-amber-800 border border-amber-300 font-bold px-2.5 py-1.5 rounded-lg uppercase">
                            <i class="fas fa-lock mr-1"></i> Terkunci (Sudah Cor)
                        </span>
                    @elseif($isCutoffLocked)
                        <span class="text-[10px] bg-purple-100 text-purple-800 border border-purple-300 font-bold px-2.5 py-1.5 rounded-lg uppercase">
                            <i class="fas fa-lock mr-1"></i> Historical Cutoff
                        </span>
                    @elseif($tree->usable_quantity <= 0)
                        <span class="text-[10px] bg-rose-100 text-rose-800 border border-rose-200 font-bold px-2.5 py-1.5 rounded-lg uppercase">
                            Usable Habis (0 pcs)
                        </span>
                    @endif
                </div>
            </div>

            <!-- Defects Table / List -->
            @if($treeDefectsList->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left">
                        <thead class="bg-slate-50 text-slate-600 text-[10px] uppercase font-bold border-b border-slate-200">
                            <tr>
                                <th class="py-2.5 px-3">Tahapan &amp; Konteks</th>
                                <th class="py-2.5 px-3 text-right">Kuantitas</th>
                                <th class="py-2.5 px-3">Alasan Cacat</th>
                                <th class="py-2.5 px-3">Waktu Kejadian Fisik</th>
                                <th class="py-2.5 px-3">Dicatat Oleh &amp; Waktu</th>
                                <th class="py-2.5 px-3">Catatan / Audit</th>
                                @if($canMutateDefect)
                                    <th class="py-2.5 px-3 text-center">Aksi</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($treeDefectsList as $defect)
                                @php $dVoided = $defect->is_voided; @endphp
                                <tr class="hover:bg-slate-50 transition-colors {{ $dVoided ? 'opacity-60 bg-slate-50/70' : '' }}">
                                    <td class="py-2.5 px-3 font-extrabold text-slate-800 space-y-1">
                                        <div class="flex flex-wrap items-center gap-1">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-800 border border-amber-200 {{ $dVoided ? 'line-through' : '' }}">
                                                {{ strtoupper($defect->stage_label) }}
                                            </span>
                                            @if($defect->stage === 'oven')
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold border {{ $defect->is_post_siap_cor ? 'bg-rose-50 text-rose-800 border-rose-200' : 'bg-slate-100 text-slate-600 border-slate-200' }}">
                                                    {{ $defect->context_label }}
                                                </span>
                                            @endif
                                            @if($defect->is_late_entry)
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">
                                                    Koreksi Terlambat
                                                </span>
                                            @endif
                                            @if($dVoided)
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-extrabold bg-slate-200 text-slate-700 border border-slate-300 uppercase">
                                                    VOIDED
                                                </span>
                                            @endif
                                        </div>
                                        @if($defect->scanEvent)
                                            <div class="text-[9.5px] font-mono font-normal text-slate-400">
                                                Scan Siap Cor: {{ $defect->scanEvent->scanned_at->copy()->setTimezone('Asia/Jakarta')->format('d-m-Y H:i') }} WIB
                                            </div>
                                        @endif
                                    </td>
                                    <td class="py-2.5 px-3 text-right font-black font-mono {{ $dVoided ? 'text-slate-400 line-through' : 'text-rose-700' }}">
                                        {{ number_format($defect->defect_qty) }} pcs
                                    </td>
                                    <td class="py-2.5 px-3 font-semibold text-slate-800">
                                        {{ ucwords(str_replace('_', ' ', $defect->defect_reason)) }}
                                    </td>
                                    <td class="py-2.5 px-3 text-slate-600 font-mono">
                                        @if($defect->occurred_at)
                                            {{ $defect->occurred_at->copy()->setTimezone('Asia/Jakarta')->format('d-m-Y H:i') }} WIB
                                        @else
                                            <span class="text-slate-400 italic font-sans">Tidak diketahui</span>
                                        @endif
                                    </td>
                                    <td class="py-2.5 px-3 text-slate-600">
                                        <div class="font-bold text-slate-800">{{ optional($defect->recordedBy)->name ?? 'System' }}</div>
                                        <div class="text-[10px] text-slate-400 font-mono">{{ $defect->created_at->copy()->setTimezone('Asia/Jakarta')->format('d-m-Y H:i') }} WIB</div>
                                    </td>
                                    <td class="py-2.5 px-3 text-slate-600">
                                        <div class="italic">{{ $defect->notes ?: '-' }}</div>
                                        @if($dVoided)
                                            <div class="text-[10px] text-rose-700 bg-rose-50 border border-rose-200 rounded px-2 py-1 mt-1 not-italic">
                                                <strong>VOID</strong> oleh {{ optional($defect->voidedBy)->name ?? 'Admin' }}
                                                ({{ $defect->voided_at->copy()->setTimezone('Asia/Jakarta')->format('d-m-Y H:i') }} WIB):
                                                "{{ $defect->void_reason }}"
                                            </div>
                                        @endif
                                    </td>
                                    @if($canMutateDefect)
                                        <td class="py-2.5 px-3 text-center">
                                            @if(!$dVoided)
                                                <button type="button"
                                                    onclick="openVoidDefectModal({{ $defect->id }}, '{{ number_format($defect->defect_qty) }} pcs ({{ strtoupper($defect->stage_label) }} - {{ $defect->context_label }})')"
                                                    class="text-[10px] font-bold text-rose-600 hover:text-rose-800 bg-rose-50 hover:bg-rose-100 border border-rose-200 px-2 py-1 rounded transition-colors">
                                                    <i class="fas fa-ban mr-0.5"></i> Void
                                                </button>
                                            @else
                                                <span class="text-[10px] text-slate-400">-</span>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="py-6 text-center text-slate-400 bg-slate-50 border border-slate-200 rounded-xl">
                    <i class="fas fa-check-circle text-2xl mb-1 text-emerald-500 block"></i>
                    <div class="font-bold text-slate-700 text-xs">Belum ada defect tercatat pada Tree ini</div>
                    <div class="text-[11px] text-slate-400 mt-0.5">Seluruh quantity ({{ $tree->quantity }} pcs) berstatus usable dan siap diproses ke tahapan selanjutnya.</div>
                </div>
            @endif
        </div>

        <!-- 4. SECTION RIWAYAT SCAN (INLINE TIMELINE) -->
        @php
            $successScansCount = $events->where('result', 'success')->reject(fn($e) => $e->void !== null)->count();
            $rejectedScansCount = $events->where('result', 'rejected')->count();
            $voidedScansCount = $events->filter(fn($e) => $e->void !== null)->count();
        @endphp

        <div class="bg-white rounded-2xl shadow-xs border border-slate-200 p-5 space-y-4">
            
            <!-- Section Header & KPI Stats -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 pb-3">
                <div>
                    <h3 class="text-base font-black text-slate-900 flex items-center gap-2">
                        <i class="fas fa-history text-amber-600"></i> RIWAYAT SCAN
                    </h3>
                    <p class="text-[11px] text-slate-400">Riwayat proses scan Traveler ini secara kronologis</p>
                </div>

                <!-- KPI Summary Chips -->
                <div class="flex flex-wrap items-center gap-2">
                    <div class="bg-slate-100 border border-slate-200 rounded-lg px-2.5 py-1 text-center">
                        <span class="text-[9px] uppercase font-bold text-slate-500 block">Tahapan Saat Ini</span>
                        <span class="text-xs font-extrabold text-slate-800">{{ $tree->current_stage ? strtoupper(str_replace('_', ' ', $tree->current_stage_label)) : 'BELUM SCAN' }}</span>
                    </div>
                    <div class="bg-emerald-50 border border-emerald-100 rounded-lg px-2.5 py-1 text-center">
                        <span class="text-[9px] uppercase font-bold text-emerald-600 block">Berhasil</span>
                        <span class="text-xs font-black text-emerald-700">{{ $successScansCount }}</span>
                    </div>
                    @if($rejectedScansCount > 0)
                        <div class="bg-rose-50 border border-rose-100 rounded-lg px-2.5 py-1 text-center">
                            <span class="text-[9px] uppercase font-bold text-rose-600 block">Ditolak</span>
                            <span class="text-xs font-black text-rose-700">{{ $rejectedScansCount }}</span>
                        </div>
                    @endif
                    @if($voidedScansCount > 0)
                        <div class="bg-amber-50 border border-amber-100 rounded-lg px-2.5 py-1 text-center">
                            <span class="text-[9px] uppercase font-bold text-amber-700 block">Dibatalkan (Void)</span>
                            <span class="text-xs font-black text-amber-800">{{ $voidedScansCount }}</span>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Timeline Body -->
            <div class="pt-2">
                @forelse($events as $event)
                    @php
                        $isVoided = $event->void !== null;
                        $isSuccess = $event->result === 'success' && !$isVoided;
                        $isRejected = $event->result === 'rejected';
                    @endphp
                    <div class="relative pl-6 pb-5 border-l-2 {{ $isVoided ? 'border-slate-200 opacity-60' : ($isSuccess ? 'border-emerald-400' : 'border-rose-400') }} last:border-transparent last:pb-0">
                        
                        <!-- Timeline Bullet -->
                        <div class="absolute -left-[9px] top-0.5 w-4 h-4 rounded-full border-2 {{ $isVoided ? 'bg-slate-100 border-slate-300' : ($isSuccess ? 'bg-emerald-100 border-emerald-500' : 'bg-rose-100 border-rose-500') }}"></div>

                        <!-- Event Card -->
                        <div class="bg-slate-50 border border-slate-200 rounded-xl p-3.5 space-y-1.5">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-black text-xs {{ $isVoided ? 'text-slate-500 line-through' : ($isSuccess ? 'text-slate-900' : 'text-rose-700') }}">
                                        {{ strtoupper($event->stage_label) }}
                                    </span>
                                    
                                    @if($isVoided)
                                        <span class="px-2 py-0.5 rounded text-[9px] font-extrabold bg-slate-200 text-slate-700 border border-slate-300 uppercase">
                                            VOIDED / DIBATALKAN
                                        </span>
                                    @elseif($isSuccess)
                                        <span class="px-2 py-0.5 rounded text-[9px] font-extrabold bg-emerald-100 text-emerald-800 border border-emerald-200 uppercase">
                                            BERHASIL
                                        </span>
                                        @if($event->source === 'historical_reconciliation')
                                            <span class="px-2 py-0.5 rounded text-[9px] font-extrabold bg-blue-100 text-blue-800 border border-blue-200 uppercase">
                                                <i class="fas fa-history mr-0.5"></i> Rekonsiliasi Historis
                                            </span>
                                        @else
                                            <span class="px-2 py-0.5 rounded text-[9px] font-medium bg-emerald-50 text-emerald-700 border border-emerald-200 uppercase">
                                                <i class="fas fa-barcode mr-0.5"></i> Scan Fisik
                                            </span>
                                        @endif
                                    @else
                                        <span class="px-2 py-0.5 rounded text-[9px] font-extrabold bg-rose-100 text-rose-800 border border-rose-200 uppercase">
                                            DITOLAK
                                        </span>
                                    @endif
                                </div>

                                <span class="text-[11px] font-mono text-slate-500 font-semibold">
                                    {{ $event->scanned_at->format('d-m-Y H:i:s') }}
                                </span>
                            </div>

                            <div class="text-xs text-slate-600 flex flex-wrap items-center gap-2">
                                <span>Operator: <strong class="text-slate-800">{{ optional($event->operator)->name ?? 'System' }}</strong></span>
                                
                                @if($isSuccess && $event->aging_status)
                                    <span>&bull;</span>
                                    <span>Aging: 
                                        <strong class="{{ $event->aging_status === 'normal' ? 'text-emerald-600' : ($event->aging_status === 'too_fast' ? 'text-amber-600' : 'text-rose-600') }}">
                                            {{ strtoupper(str_replace('_', ' ', $event->aging_status)) }}
                                        </strong>
                                        @if($event->aging_label)
                                            <span class="text-slate-400">({{ $event->aging_label }})</span>
                                        @endif
                                    </span>
                                @endif
                            </div>

                            @if($event->anomaly_reason && !$isVoided)
                                <div class="text-[11px] {{ $isRejected ? 'text-rose-700 bg-rose-50 border border-rose-200' : 'text-amber-700 bg-amber-50 border border-amber-200' }} rounded-md p-2 mt-1">
                                    <i class="fas {{ $isRejected ? 'fa-times-circle text-rose-500' : 'fa-exclamation-triangle text-amber-500' }} mr-1"></i> {{ $event->anomaly_reason }}
                                </div>
                            @endif

                            @if($event->notes && !$isVoided)
                                <div class="text-[11px] text-blue-700 bg-blue-50 border border-blue-200 rounded-md p-2 mt-1">
                                    <i class="fas fa-info-circle text-blue-500 mr-1"></i> {{ $event->notes }}
                                </div>
                            @endif

                            @if($isVoided && $event->void)
                                <div class="text-[10px] text-slate-500 bg-slate-100 border border-slate-200 rounded-md p-2 mt-1">
                                    Dibatalkan oleh: <strong>{{ optional($event->void->voidedByUser)->name ?? 'Admin' }}</strong>
                                    pada {{ $event->void->created_at->format('d-m-Y H:i') }}
                                    &mdash; Alasan: <em>"{{ $event->void->reason }}"</em>
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="py-8 text-center text-slate-400 bg-slate-50 border border-slate-200 rounded-xl">
                        <i class="fas fa-barcode text-2xl mb-1.5 text-slate-300 block"></i>
                        <div class="font-bold text-slate-700 text-xs">Belum ada aktivitas scan untuk Traveler ini</div>
                        <div class="text-[11px] text-slate-400 mt-0.5">Riwayat tahapan scan akan otomatis tercatat saat barcode dipindai oleh scanner operator.</div>
                    </div>
                @endforelse
            </div>
        </div>

    </div>

    <!-- MODAL CATAT DEFECT -->
    <div id="defectModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs hidden p-4 sm:p-6 overflow-y-auto">
        <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-lg my-auto flex flex-col max-h-[calc(100vh-2rem)] sm:max-h-[calc(100vh-3.5rem)] overflow-hidden transform transition-all">
            <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 bg-slate-50 shrink-0">
                <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                    <i class="fas fa-exclamation-triangle text-rose-600"></i> Catat Defect Tree
                </h3>
                <button type="button" onclick="closeDefectModal()" class="text-slate-400 hover:text-slate-600 text-sm p-1 rounded-lg transition-colors" title="Tutup">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <form id="defectForm" method="POST" action="{{ route('lost-wax.trees.defects.store', $tree) }}" class="flex flex-col flex-1 min-h-0 overflow-hidden">
                @csrf

                <!-- Scrollable Fields Body -->
                <div class="p-5 space-y-4 overflow-y-auto flex-1 overscroll-contain">
                    <!-- Balance Preview Card -->
                    <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-xs flex justify-between items-center text-amber-900">
                        <div>
                            <span class="text-[10px] uppercase font-bold text-amber-700 block">Kuantitas Pohon</span>
                            <span class="font-extrabold font-mono text-slate-800">{{ $tree->quantity }} pcs</span>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase font-bold text-amber-700 block">Cacat Terdaftar</span>
                            <span class="font-extrabold font-mono text-rose-700">{{ $tree->total_defect_quantity }} pcs</span>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase font-bold text-amber-700 block">Maksimal Defect</span>
                            <span id="maxRemainingQty" class="font-black font-mono text-emerald-800 text-sm">{{ $tree->usable_quantity }} pcs</span>
                        </div>
                    </div>

                    <!-- Stage Selection -->
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 uppercase mb-1">
                            Tahapan Kejadian Cacat <span class="text-rose-500">*</span>
                        </label>
                        <select name="stage" id="defectStageInput" required onchange="syncDefectContextUi()"
                            class="w-full rounded-lg border-slate-300 text-xs font-semibold py-2 px-3 focus:ring-amber-500 focus:border-amber-500">
                            <option value="assembly" {{ $tree->current_stage === null ? 'selected' : '' }}>Rangkai (Assembly)</option>
                            <option value="layer_1" {{ $tree->current_stage === 'layer_1' ? 'selected' : '' }}>Lapisan 1 (Layer 1)</option>
                            <option value="layer_2" {{ $tree->current_stage === 'layer_2' ? 'selected' : '' }}>Lapisan 2 (Layer 2)</option>
                            <option value="layer_3" {{ $tree->current_stage === 'layer_3' ? 'selected' : '' }}>Lapisan 3 (Layer 3)</option>
                            <option value="layer_4" {{ $tree->current_stage === 'layer_4' ? 'selected' : '' }}>Lapisan 4 (Layer 4)</option>
                            <option value="layer_5" {{ $tree->current_stage === 'layer_5' ? 'selected' : '' }}>Lapisan 5 (Layer 5)</option>
                            <option value="layer_6" {{ $tree->current_stage === 'layer_6' ? 'selected' : '' }}>Lapisan 6 (Layer 6)</option>
                            <option value="layer_7" {{ $tree->current_stage === 'layer_7' ? 'selected' : '' }}>Lapisan 7 (Layer 7)</option>
                            <option value="oven" {{ in_array($tree->current_stage, ['oven', 'siap_cor'], true) ? 'selected' : '' }}>Oven (Dewaxing)</option>
                        </select>
                        <p class="text-[10px] text-slate-400 mt-1">Pilih tahapan aktual tempat cacat fisik terjadi. Kerusakan pasca-Siap Cor tetap dicatat pada Departemen Oven.</p>
                    </div>

                    @if($tree->current_stage === 'siap_cor' && $hasActiveSiapCor)
                        <!-- Defect Timing Context (Pre vs Post Scan Siap Cor) -->
                        <div id="defectContextBox" class="bg-slate-50 border border-slate-200 rounded-xl p-3 space-y-2">
                            <label class="block text-[11px] font-bold text-slate-700 uppercase">
                                Konteks Waktu Kejadian (Terkait Scan Siap Cor {{ $activeSiapCorEvent->scanned_at->copy()->setTimezone('Asia/Jakarta')->format('d-m-Y H:i') }} WIB) <span class="text-rose-500">*</span>
                            </label>
                            <input type="hidden" name="scan_event_id" id="defectScanEventIdInput" value="{{ $activeSiapCorEvent->id }}">
                            <div class="space-y-1.5 text-xs">
                                <label class="flex items-start gap-2 cursor-pointer p-2 rounded-lg border border-rose-200 bg-rose-50/50">
                                    <input type="radio" name="defect_context" value="post_siap_cor" checked onchange="syncDefectContextUi()" class="mt-0.5 text-rose-600 focus:ring-rose-500">
                                    <span>
                                        <strong class="text-rose-800 block">Pasca-Siap Cor (Antrean Cor)</strong>
                                        <span class="text-[10px] text-slate-600">Kerusakan terjadi setelah scan Siap Cor (tersenggol, jatuh, pecah di antrean). Histori Siap Cor ({{ $tree->siap_cor_historical_quantity }} pcs) tetap dipertahankan.</span>
                                    </span>
                                </label>
                                <label class="flex items-start gap-2 cursor-pointer p-2 rounded-lg border border-slate-200 bg-white">
                                    <input type="radio" name="defect_context" value="pre_siap_cor" onchange="syncDefectContextUi()" class="mt-0.5 text-indigo-600 focus:ring-indigo-500">
                                    <span>
                                        <strong class="text-indigo-800 block">Pra-Siap Cor (Koreksi Terlambat)</strong>
                                        <span class="text-[10px] text-slate-600">Kerusakan sebenarnya terjadi sebelum scan Siap Cor namun baru dicatat sekarang. Wajib mengisi alasan keterlambatan di Catatan.</span>
                                    </span>
                                </label>
                            </div>
                            <div id="lateUnknownConfirmWrap" class="hidden pt-1 border-t border-slate-200">
                                <label class="flex items-start gap-2 text-[11px] text-indigo-800 font-semibold cursor-pointer">
                                    <input type="checkbox" name="confirm_unknown_occurred_at" id="confirmUnknownOccurredAtInput" value="1" class="mt-0.5 rounded border-indigo-300 text-indigo-600">
                                    <span>Waktu kejadian fisik persis tidak diketahui, tetapi dikonfirmasi terjadi sebelum Scan Siap Cor.</span>
                                </label>
                            </div>
                        </div>
                    @endif

                    <!-- Defect Quantity -->
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 uppercase mb-1">
                            Jumlah Rusak / Cacat (pcs) <span class="text-rose-500">*</span>
                        </label>
                        <input type="number" name="defect_qty" id="defectQtyInput" min="1" max="{{ $tree->usable_quantity }}" value="1" required
                            class="w-full rounded-lg border-slate-300 text-xs font-bold py-2 px-3 focus:ring-amber-500 focus:border-amber-500">
                        <p id="defectQtyWarning" class="text-[10px] text-rose-600 font-bold mt-1 hidden">
                            <i class="fas fa-exclamation-circle mr-0.5"></i> Jumlah cacat melebihi sisa usable pohon ({{ $tree->usable_quantity }} pcs).
                        </p>
                    </div>

                    <!-- Defect Reason -->
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 uppercase mb-1">
                            Alasan / Kategori Cacat <span class="text-rose-500">*</span>
                        </label>
                        <select name="defect_reason" id="defectReasonInput" required
                            class="w-full rounded-lg border-slate-300 text-xs font-semibold py-2 px-3 focus:ring-amber-500 focus:border-amber-500">
                            <option value="tersenggol_jatuh" {{ $tree->current_stage === 'siap_cor' ? 'selected' : '' }}>Tersenggol / Jatuh (Handling Pasca-Oven)</option>
                            <option value="cangkang_pecah_antrean">Cangkang Pecah di Antrean Siap Cor</option>
                            <option value="retak_lapisan" {{ $tree->current_stage !== 'siap_cor' ? 'selected' : '' }}>Retak Lapisan / Slurry</option>
                            <option value="lapisan_rontok">Lapisan Slurry Rontok / Mengelupas</option>
                            <option value="lapisan_tipis">Ketebalan Coating Tidak Merata</option>
                            <option value="pola_patah">Pola Lilin Patah / Rusak</option>
                            <option value="lilin_bocor_dini">Lilin Meleleh / Bocor Dini</option>
                            <option value="oven_pecah">Cangkang Keramik Pecah di Oven</option>
                            <option value="lainnya">Alasan Lainnya</option>
                        </select>
                    </div>

                    <!-- Occurred At -->
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 uppercase mb-1">
                            Waktu Kejadian Fisik <span class="text-slate-400 font-normal lowercase">(opsional — WIB)</span>
                        </label>
                        <input type="datetime-local" name="occurred_at" id="defectOccurredAtInput" oninput="syncDefectContextUi()"
                            class="w-full rounded-lg border-slate-300 text-xs font-semibold py-2 px-3 focus:ring-amber-500 focus:border-amber-500">
                        <p class="text-[10px] text-slate-400 mt-1">Kosongkan bila waktu kejadian fisik tidak diketahui (tidak akan memalsukan waktu pencatatan).</p>
                    </div>

                    <!-- Notes -->
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 uppercase mb-1">
                            Catatan Tambahan <span id="notesRequiredMark" class="text-slate-400 font-normal lowercase">(opsional)</span>
                        </label>
                        <textarea name="notes" id="defectNotesInput" rows="2" placeholder="Contoh: Pohon tersenggol di rak antrean cor / alasan koreksi terlambat..."
                            class="w-full rounded-lg border-slate-300 text-xs py-2 px-3 focus:ring-amber-500 focus:border-amber-500"></textarea>
                    </div>
                </div>

                <!-- Footer Buttons (Sticky at Bottom) -->
                <div class="flex items-center justify-end gap-2.5 px-5 py-3.5 border-t border-slate-100 bg-slate-50/80 shrink-0">
                    <button type="button" onclick="closeDefectModal()"
                        class="px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-200/70 rounded-lg transition-colors">
                        Batal
                    </button>
                    <button type="submit" id="submitDefectBtn"
                        class="bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold py-2 px-5 rounded-lg shadow-2xs transition-colors cursor-pointer">
                        Simpan Defect
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL SOFT-VOID DEFECT -->
    <div id="voidDefectModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs hidden p-4">
        <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-md overflow-hidden">
            <div class="flex items-center justify-between p-4 border-b border-slate-100 bg-rose-50">
                <h3 class="text-sm font-black text-rose-900 flex items-center gap-2">
                    <i class="fas fa-ban text-rose-600"></i> Batalkan (Soft-Void) Defect
                </h3>
                <button type="button" onclick="closeVoidDefectModal()" class="text-slate-400 hover:text-slate-600 text-sm p-1 rounded-lg">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="voidDefectForm" method="POST" action="#" class="p-5 space-y-4">
                @csrf
                <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-xs text-amber-900">
                    Record defect <strong id="voidDefectSummary">-</strong> akan ditandai <strong>VOIDED</strong> (histori tetap tersimpan untuk audit trail) dan kuantitas sisa pohon akan dipulihkan.
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-700 uppercase mb-1">
                        Alasan Pembatalan (Void) <span class="text-rose-500">*</span>
                    </label>
                    <textarea name="void_reason" id="voidDefectReasonInput" rows="3" required minlength="3" maxlength="255"
                        placeholder="Jelaskan alasan pembatalan defect (minimal 3 karakter)..."
                        class="w-full rounded-lg border-slate-300 text-xs py-2 px-3 focus:ring-rose-500 focus:border-rose-500"></textarea>
                </div>
                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                    <button type="button" onclick="closeVoidDefectModal()"
                        class="px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 rounded-lg transition-colors">
                        Batal
                    </button>
                    <button type="submit"
                        class="bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold py-2 px-5 rounded-lg shadow-2xs transition-colors">
                        Konfirmasi Void Defect
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openDefectModal() {
            document.getElementById('defectModal').classList.remove('hidden');
            syncDefectContextUi();
            document.getElementById('defectQtyInput').focus();
        }

        function closeDefectModal() {
            document.getElementById('defectModal').classList.add('hidden');
        }

        function syncDefectContextUi() {
            const stageEl = document.getElementById('defectStageInput');
            const ctxBox = document.getElementById('defectContextBox');
            const scanEventInput = document.getElementById('defectScanEventIdInput');
            const lateWrap = document.getElementById('lateUnknownConfirmWrap');
            const occurredInput = document.getElementById('defectOccurredAtInput');
            const notesInput = document.getElementById('defectNotesInput');
            const notesMark = document.getElementById('notesRequiredMark');
            const selectedRadio = document.querySelector('input[name="defect_context"]:checked');

            if (!ctxBox || !stageEl) return;

            const isOven = stageEl.value === 'oven';
            const postRadio = document.querySelector('input[name="defect_context"][value="post_siap_cor"]');
            const preRadio = document.querySelector('input[name="defect_context"][value="pre_siap_cor"]');

            if (!isOven && preRadio && postRadio) {
                postRadio.disabled = true;
                preRadio.checked = true;
            } else if (postRadio) {
                postRadio.disabled = false;
            }

            const activeCtx = (document.querySelector('input[name="defect_context"]:checked') || selectedRadio)?.value || 'post_siap_cor';
            const isLatePre = activeCtx === 'pre_siap_cor';

            if (scanEventInput) {
                scanEventInput.disabled = isLatePre;
            }
            if (notesInput && notesMark) {
                notesInput.required = isLatePre;
                notesMark.innerHTML = isLatePre ? '<span class="text-rose-500 font-bold">* (wajib untuk koreksi terlambat)</span>' : '(opsional)';
            }
            if (lateWrap && occurredInput) {
                const showUnknownConfirm = isLatePre && !occurredInput.value;
                lateWrap.classList.toggle('hidden', !showUnknownConfirm);
            }
        }

        function openVoidDefectModal(defectId, summaryText) {
            const form = document.getElementById('voidDefectForm');
            const base = "{{ url('/lost-wax/trees/'.$tree->id.'/defects') }}";
            form.action = base + '/' + defectId + '/void';
            document.getElementById('voidDefectSummary').textContent = summaryText;
            document.getElementById('voidDefectReasonInput').value = '';
            document.getElementById('voidDefectModal').classList.remove('hidden');
            document.getElementById('voidDefectReasonInput').focus();
        }

        function closeVoidDefectModal() {
            document.getElementById('voidDefectModal').classList.add('hidden');
        }

        // Live validation on quantity input
        document.getElementById('defectQtyInput')?.addEventListener('input', function(e) {
            const maxQty = {{ (int) $tree->usable_quantity }};
            const currentVal = parseInt(e.target.value, 10);
            const warningEl = document.getElementById('defectQtyWarning');
            const submitBtn = document.getElementById('submitDefectBtn');

            if (currentVal > maxQty) {
                warningEl.classList.remove('hidden');
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
            } else {
                warningEl.classList.add('hidden');
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            }
        });
    </script>
@endsection
