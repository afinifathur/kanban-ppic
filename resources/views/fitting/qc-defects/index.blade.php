@extends('layouts.app')

@section('title', 'Verifikasi Defect QC Fitting')

@section('top_bar')
<div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2 w-full">
    <div>
        <h1 class="text-lg font-bold text-slate-800 flex items-center gap-2">
            <i class="fas fa-check-double text-emerald-600"></i>
            Verifikasi Defect QC Fitting
        </h1>
        <p class="text-xs text-slate-500">Klasifikasi jenis kerusakan, simpan draft, konfirmasi QC, atau kembalikan ke PPIC untuk koreksi</p>
    </div>
    <div class="flex items-center gap-2">
        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-amber-50 text-amber-800 border border-amber-200">
            <i class="fas fa-clock mr-1.5"></i>
            Menunggu QC: <strong class="ml-1">{{ number_format($summary['waiting_total']) }}</strong>
        </span>
        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-50 text-emerald-800 border border-emerald-200">
            <i class="fas fa-check-circle mr-1.5"></i>
            Terverifikasi: <strong class="ml-1">{{ number_format($summary['confirmed_total']) }}</strong>
        </span>
    </div>
</div>
@endsection

@section('content')
<div class="p-4 md:p-6 space-y-5">
    {{-- Flash Alerts --}}
    @if(session('success'))
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-lg text-sm flex items-center gap-2">
            <i class="fas fa-check-circle text-emerald-500"></i>
            <span>{{ session('success') }}</span>
        </div>
    @endif
    @if(session('error'))
        <div class="bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 rounded-lg text-sm flex items-center gap-2">
            <i class="fas fa-exclamation-triangle text-rose-500"></i>
            <span>{{ session('error') }}</span>
        </div>
    @endif
    @if($errors->any())
        <div class="bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 rounded-lg text-sm">
            <div class="font-semibold mb-1"><i class="fas fa-times-circle mr-1"></i>Validasi gagal:</div>
            <ul class="list-disc pl-5 space-y-0.5">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Summary Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Menunggu Verifikasi QC</div>
            <div class="mt-1 text-2xl font-bold text-amber-600">{{ number_format($summary['waiting_total']) }}</div>
            <div class="text-xs text-slate-400 mt-1">Status WAITING_QC (defect &gt; 0 PCS)</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Terverifikasi QC</div>
            <div class="mt-1 text-2xl font-bold text-emerald-600">{{ number_format($summary['confirmed_total']) }}</div>
            <div class="text-xs text-slate-400 mt-1">Status CONFIRMED</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Dibuka Ulang (Menunggu PPIC)</div>
            <div class="mt-1 text-2xl font-bold text-purple-600">{{ number_format($summary['reopened_total']) }}</div>
            <div class="text-xs text-slate-400 mt-1">REOPENED_FOR_CORRECTION</div>
        </div>
    </div>

    {{-- Main Card --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="border-b border-slate-200 px-4 pt-3 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <a href="{{ route('fitting.qc-defects.index', array_filter(['mode' => 'waiting', 'stage' => $selectedStage, 'search' => $search])) }}"
                   class="px-4 py-2 text-sm font-semibold border-b-2 transition {{ $mode === 'waiting' ? 'border-amber-600 text-amber-700' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
                    Menunggu Verifikasi
                    <span class="ml-1.5 px-2 py-0.5 text-xs rounded-full {{ $mode === 'waiting' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600' }}">{{ $summary['waiting_total'] }}</span>
                </a>
                <a href="{{ route('fitting.qc-defects.index', array_filter(['mode' => 'confirmed', 'stage' => $selectedStage, 'search' => $search])) }}"
                   class="px-4 py-2 text-sm font-semibold border-b-2 transition {{ $mode === 'confirmed' ? 'border-emerald-600 text-emerald-700' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
                    Terverifikasi
                    <span class="ml-1.5 px-2 py-0.5 text-xs rounded-full {{ $mode === 'confirmed' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $summary['confirmed_total'] }}</span>
                </a>
            </div>

            <form method="GET" action="{{ route('fitting.qc-defects.index') }}" class="pb-2 flex flex-wrap items-center gap-2">
                <input type="hidden" name="mode" value="{{ $mode }}">
                @if($selectedStage)
                    <input type="hidden" name="stage" value="{{ $selectedStage }}">
                @endif
                <select name="draft_status" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
                    <option value="all" {{ $draftStatus === 'all' ? 'selected' : '' }}>Draft: Semua</option>
                    <option value="has_draft" {{ $draftStatus === 'has_draft' ? 'selected' : '' }}>Ada Draft</option>
                    <option value="no_draft" {{ $draftStatus === 'no_draft' ? 'selected' : '' }}>Belum Ada Draft</option>
                </select>
                <input type="text" name="search" value="{{ $search }}"
                       placeholder="Cari PFKTR / Heat / Item..."
                       class="w-52 rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-800 text-white text-sm font-medium hover:bg-slate-700">
                    <i class="fas fa-search mr-1"></i>Filter
                </button>
            </form>
        </div>

        {{-- Stage Filter Pills --}}
        <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center gap-2">
            @php
                $stageCounts = $mode === 'waiting' ? $summary['per_stage_waiting'] : $summary['per_stage_confirmed'];
            @endphp
            <a href="{{ route('fitting.qc-defects.index', array_filter(['mode' => $mode, 'search' => $search])) }}"
               class="px-3 py-1 rounded-full text-xs font-semibold border transition {{ $selectedStage === null ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-100' }}">
                Semua Tahap
            </a>
            @foreach($stages as $st)
                @php $cnt = (int) ($stageCounts[$st] ?? 0); @endphp
                <a href="{{ route('fitting.qc-defects.index', array_filter(['mode' => $mode, 'stage' => $st, 'search' => $search])) }}"
                   class="px-3 py-1 rounded-full text-xs font-semibold border transition {{ $selectedStage === $st ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-100' }}">
                    {{ $stageLabels[$st] ?? strtoupper($st) }}
                    <span class="ml-1 opacity-80">({{ $cnt }})</span>
                </a>
            @endforeach
        </div>

        {{-- Table --}}
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold">PFKTR / Heat</th>
                        <th class="px-4 py-3 text-left font-semibold">Item & Customer</th>
                        <th class="px-4 py-3 text-left font-semibold">Tahap & Sumber</th>
                        <th class="px-4 py-3 text-right font-semibold">Input Efektif</th>
                        <th class="px-4 py-3 text-right font-semibold">Total Defect</th>
                        <th class="px-4 py-3 text-right font-semibold">Good Efektif</th>
                        <th class="px-4 py-3 text-left font-semibold">Rincian Klasifikasi</th>
                        <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse($executions as $exec)
                        @php
                            $item = $exec->fittingItem;
                            $pfktr = $item?->traveler_number ?? '-';
                            $stageLabel = $stageLabels[$exec->stage] ?? strtoupper($exec->stage);
                            $effectiveInput = (int) $exec->effective_input_qty;
                            $effectiveGood = (int) $exec->effective_good_qty;
                            $isLocked = (bool) $exec->is_quality_locked;
                            $draftSum = (int) $exec->defects->sum('qty');
                            $existingDefects = $exec->defects->map(fn ($d) => [
                                'defect_type_id' => (int) $d->defect_type_id,
                                'qty' => (int) $d->qty,
                                'notes' => $d->notes ?? '',
                            ])->values()->all();
                            $logsPayload = $exec->defectLogs->map(fn ($l) => [
                                'at' => $l->created_at?->format('d/m/Y H:i:s'),
                                'actor' => $l->is_system_action ? 'SISTEM (AUTO-NIHIL)' : ($l->user?->name ?? 'User'),
                                'added_qty' => (int) $l->added_qty,
                                'previous_total' => (int) $l->previous_total,
                                'new_total' => (int) $l->new_total,
                                'notes' => $l->notes ?? '-',
                                'qc_snapshot' => $l->qc_snapshot_json,
                            ])->values()->all();
                        @endphp
                        <tr class="hover:bg-slate-50/70">
                            <td class="px-4 py-3 align-top">
                                <div class="font-mono font-bold text-slate-800">{{ $pfktr }}</div>
                                <div class="text-xs text-slate-500 mt-0.5">Heat: <span class="font-mono">{{ $item?->heat_number ?? '-' }}</span></div>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @if($item?->line_number)
                                        <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-sky-100 text-sky-700">Line {{ $item->line_number }}</span>
                                    @else
                                        <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-slate-200 text-slate-700">Unassigned</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div class="font-semibold text-slate-800">{{ $item?->item_name ?? '-' }}</div>
                                <div class="text-xs text-slate-500">{{ $item?->production_code ?? '-' }} &middot; {{ $item?->customer ?? '-' }}</div>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <span class="inline-flex px-2 py-0.5 rounded text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200">{{ $stageLabel }}</span>
                                @if($exec->is_auto_nihil)
                                    <div class="mt-1"><span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-indigo-100 text-indigo-700"><i class="fas fa-robot mr-1"></i>AUTO-NIHIL</span></div>
                                @else
                                    <div class="text-xs text-slate-500 mt-1">Dicatat: {{ $exec->defectEnteredBy?->name ?? '-' }}</div>
                                @endif
                                <div class="text-[11px] text-slate-400">{{ $exec->defect_entered_at?->format('d/m/Y H:i') ?? '-' }}</div>
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-slate-800 align-top">{{ number_format($effectiveInput) }}</td>
                            <td class="px-4 py-3 text-right font-mono font-bold {{ (int) $exec->defect_qty > 0 ? 'text-rose-600' : 'text-emerald-600' }} align-top">
                                {{ number_format((int) $exec->defect_qty) }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono font-bold text-emerald-700 align-top">{{ number_format($effectiveGood) }}</td>
                            <td class="px-4 py-3 align-top">
                                @if($exec->defects->isNotEmpty())
                                    <ul class="space-y-0.5 text-xs text-slate-700">
                                        @foreach($exec->defects as $d)
                                            <li><span class="font-semibold">{{ $d->defectType?->name ?? 'Unknown' }}:</span> <span class="font-mono">{{ $d->qty }} PCS</span></li>
                                        @endforeach
                                    </ul>
                                    @if($exec->status === \App\Models\FittingStageExecution::STATUS_WAITING_QC)
                                        <div class="text-[10px] mt-1 font-semibold {{ $draftSum === (int) $exec->defect_qty ? 'text-emerald-600' : 'text-amber-600' }}">
                                            Draft: {{ $draftSum }} / {{ (int) $exec->defect_qty }} PCS
                                        </div>
                                    @endif
                                @elseif((int) $exec->defect_qty === 0)
                                    <span class="text-xs text-emerald-600 font-medium">Nihil (0 PCS)</span>
                                @else
                                    <span class="text-xs text-slate-400 italic">Belum ada rincian klasifikasi</span>
                                @endif

                                @if($exec->status === \App\Models\FittingStageExecution::STATUS_CONFIRMED)
                                    <div class="text-[11px] text-emerald-700 mt-1">
                                        <i class="fas fa-check-circle mr-1"></i>QC: {{ $exec->qcVerifiedBy?->name ?? '-' }} ({{ $exec->qc_verified_at?->format('d/m/Y H:i') }})
                                    </div>
                                @endif
                                @if($isLocked)
                                    <div class="mt-1"><span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-800 text-white"><i class="fas fa-lock mr-1"></i>QUALITY LOCK</span></div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right align-top whitespace-nowrap">
                                <div class="inline-flex flex-col items-end gap-1.5">
                                    @if($isLocked)
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-slate-100 text-slate-500 text-xs font-medium cursor-not-allowed">
                                            <i class="fas fa-lock mr-1"></i>Terkunci
                                        </span>
                                    @elseif($exec->status === \App\Models\FittingStageExecution::STATUS_WAITING_QC)
                                        <button type="button"
                                                onclick='openVerifyModal({{ $exec->id }}, @js($pfktr), @js($exec->stage), @js($stageLabel), {{ (int) $exec->defect_qty }}, @json($existingDefects), @js($exec->inspection_date?->format("Y-m-d") ?? ""))'
                                                class="inline-flex items-center px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold shadow-sm">
                                            <i class="fas fa-check-double mr-1.5"></i>Verifikasi / Draft
                                        </button>
                                        <button type="button"
                                                onclick="openReturnModal({{ $exec->id }}, @js($pfktr), @js($stageLabel))"
                                                class="inline-flex items-center px-2.5 py-1 rounded-lg border border-rose-300 bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-semibold">
                                            <i class="fas fa-undo mr-1"></i>Kembalikan ke PPIC
                                        </button>
                                    @elseif($exec->status === \App\Models\FittingStageExecution::STATUS_CONFIRMED)
                                        <button type="button"
                                                onclick="openReopenModal({{ $exec->id }}, @js($pfktr), @js($stageLabel), {{ (int) $exec->defect_qty }})"
                                                class="inline-flex items-center px-2.5 py-1 rounded-lg border border-purple-300 bg-purple-50 hover:bg-purple-100 text-purple-700 text-xs font-semibold">
                                            <i class="fas fa-unlock-alt mr-1"></i>Ajukan Koreksi (Reopen)
                                        </button>
                                    @endif

                                    <button type="button"
                                            onclick='openQcLogsModal(@js($pfktr), @js($stageLabel), @json($logsPayload))'
                                            class="inline-flex items-center px-2.5 py-1 rounded border border-slate-200 hover:bg-slate-100 text-slate-600 text-[11px] font-medium">
                                        <i class="fas fa-history mr-1"></i>Log ({{ count($logsPayload) }})
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-10 text-center text-slate-400">
                                <i class="fas fa-clipboard-check text-3xl mb-2 block"></i>
                                Tidak ada antrean verifikasi QC Fitting pada filter ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($executions->hasPages())
            <div class="px-4 py-3 border-t border-slate-200 bg-slate-50">
                {{ $executions->links() }}
            </div>
        @endif
    </div>
</div>

{{-- Modal: QC Verify & Draft Breakdown --}}
<div id="qcVerifyModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl max-w-xl w-full overflow-hidden">
        <div class="px-5 py-4 bg-emerald-600 text-white flex items-center justify-between">
            <h3 class="font-bold text-base"><i class="fas fa-check-double mr-2"></i>Verifikasi Klasifikasi Defect QC</h3>
            <button type="button" onclick="closeQcModal('qcVerifyModal')" class="text-white/80 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        <form id="qcVerifyForm" method="POST" class="p-5 space-y-4">
            @csrf
            <div class="bg-slate-50 rounded-lg p-3 text-xs flex flex-wrap justify-between gap-2 border border-slate-200">
                <div><strong>PFKTR:</strong> <span id="qcPfktr" class="font-mono"></span> (<span id="qcStageLabel"></span>)</div>
                <div><strong>Target Defect:</strong> <span id="qcTargetDefect" class="font-mono font-bold text-rose-600"></span> PCS &middot; <strong>Terisi:</strong> <span id="qcCurrentSum" class="font-mono font-bold text-emerald-700">0</span> PCS</div>
            </div>

            <div id="qcZeroNotice" class="hidden bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg p-3 text-xs">
                <i class="fas fa-check-circle mr-1"></i>
                Total defect adalah <strong>0 PCS (Nihil)</strong>. Klik <strong>Verifikasi & Konfirmasi</strong> untuk menyetujui status CONFIRMED.
            </div>

            <div id="qcBreakdownSection" class="space-y-2">
                <div class="flex items-center justify-between">
                    <label class="text-xs font-semibold text-slate-700">Rincian Jenis Kerusakan (Master Defect)</label>
                    <button type="button" onclick="addDefectRow()" class="px-2.5 py-1 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold">
                        <i class="fas fa-plus mr-1"></i>Tambah Baris
                    </button>
                </div>
                <div id="qcRowsContainer" class="space-y-2 max-h-56 overflow-y-auto pr-1"></div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Tanggal Pemeriksaan QC</label>
                    <input type="date" name="inspection_date" id="qcInspectionDate" max="{{ now()->format('Y-m-d') }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Catatan QC (Opsional)</label>
                    <input type="text" name="notes" id="qcNotes" maxlength="500"
                           class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm" placeholder="Catatan inspeksi...">
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-2 pt-2 border-t border-slate-200">
                <button type="button" onclick="closeQcModal('qcVerifyModal')" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium hover:bg-slate-50">Batal</button>
                <button type="button" id="btnSaveDraft" onclick="submitQcForm('draft')" class="px-4 py-2 rounded-lg bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold">
                    <i class="fas fa-save mr-1"></i>Simpan Draft
                </button>
                <button type="button" onclick="submitQcForm('verify')" class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold">
                    <i class="fas fa-check-double mr-1"></i>Verifikasi & Konfirmasi
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Modal: Return for Correction --}}
<div id="qcReturnModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl max-w-md w-full overflow-hidden">
        <div class="px-5 py-4 bg-rose-600 text-white flex items-center justify-between">
            <h3 class="font-bold text-base"><i class="fas fa-undo mr-2"></i>Kembalikan ke PPIC untuk Koreksi</h3>
            <button type="button" onclick="closeQcModal('qcReturnModal')" class="text-white/80 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        <form id="qcReturnForm" method="POST" class="p-5 space-y-4">
            @csrf
            <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-lg p-3 text-xs">
                Eksekusi <strong id="retPfktr" class="font-mono"></strong> (<span id="retStageLabel"></span>) akan dikembalikan ke status <strong>WAITING_DEFECT</strong> agar Admin PPIC dapat memperbaiki jumlah defect.
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Alasan Pengembalian <span class="text-rose-500">*</span></label>
                <textarea name="reason" id="retReason" rows="3" required minlength="3" maxlength="500"
                          class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                          placeholder="Jelaskan ketidaksesuaian fisik / jumlah defect..."></textarea>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeQcModal('qcReturnModal')" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium">Batal</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-sm font-semibold">Kembalikan</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal: Reopen CONFIRMED Verification for Two-Party Correction --}}
<div id="qcReopenModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl max-w-md w-full overflow-hidden">
        <div class="px-5 py-4 bg-purple-600 text-white flex items-center justify-between">
            <h3 class="font-bold text-base"><i class="fas fa-unlock-alt mr-2"></i>Buka Kembali Verifikasi QC (Reopen)</h3>
            <button type="button" onclick="closeQcModal('qcReopenModal')" class="text-white/80 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        <form id="qcReopenForm" method="POST" class="p-5 space-y-4">
            @csrf
            <div class="bg-purple-50 border border-purple-200 text-purple-900 rounded-lg p-3 text-xs leading-relaxed">
                Verifikasi QC untuk <strong id="reopenPfktr" class="font-mono"></strong> (<span id="reopenStageLabel"></span>, defect saat ini <strong id="reopenDefectQty"></strong> PCS) akan dibuka kembali menjadi <strong>REOPENED_FOR_CORRECTION</strong>. Histori verifikasi sebelumnya diarsipkan permanen, dan <strong>jumlah defect milik PPIC tidak diubah oleh QC</strong>.
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Alasan Pembukaan Kembali <span class="text-rose-500">*</span></label>
                <textarea name="reason" id="reopenReason" rows="3" required minlength="3" maxlength="1000"
                          class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                          placeholder="Jelaskan alasan pengajuan koreksi pasca CONFIRMED..."></textarea>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeQcModal('qcReopenModal')" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium">Batal</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold">Buka Kembali untuk Koreksi</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal: Audit Log --}}
<div id="qcLogsModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full overflow-hidden">
        <div class="px-5 py-4 bg-slate-800 text-white flex items-center justify-between">
            <h3 class="font-bold text-base"><i class="fas fa-history mr-2"></i>Audit Log Defect — <span id="qcLogTitle" class="font-mono"></span></h3>
            <button type="button" onclick="closeQcModal('qcLogsModal')" class="text-white/80 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        <div id="qcLogsBody" class="p-5 max-h-96 overflow-y-auto space-y-2.5 text-xs"></div>
        <div class="px-5 py-3 bg-slate-50 border-t border-slate-200 flex justify-end">
            <button type="button" onclick="closeQcModal('qcLogsModal')" class="px-4 py-1.5 rounded-lg bg-slate-800 text-white text-xs font-semibold">Tutup</button>
        </div>
    </div>
</div>

<script>
const DEFECT_TYPES_MAP = @json($defectTypesMap);
let currentExecId = null;
let currentStageKey = null;
let currentTargetDefect = 0;
let rowIndex = 0;

function closeQcModal(id) {
    document.getElementById(id).classList.add('hidden');
}

function openVerifyModal(execId, pfktr, stageKey, stageLabel, defectQty, existingDefects, inspectionDate) {
    currentExecId = execId;
    currentStageKey = stageKey;
    currentTargetDefect = Number(defectQty || 0);
    rowIndex = 0;

    document.getElementById('qcPfktr').textContent = pfktr;
    document.getElementById('qcStageLabel').textContent = stageLabel;
    document.getElementById('qcTargetDefect').textContent = currentTargetDefect;
    document.getElementById('qcInspectionDate').value = inspectionDate || '';
    document.getElementById('qcNotes').value = '';

    const container = document.getElementById('qcRowsContainer');
    container.innerHTML = '';

    const zeroNotice = document.getElementById('qcZeroNotice');
    const breakdownSection = document.getElementById('qcBreakdownSection');
    const btnDraft = document.getElementById('btnSaveDraft');

    if (currentTargetDefect === 0) {
        zeroNotice.classList.remove('hidden');
        breakdownSection.classList.add('hidden');
        btnDraft.classList.add('hidden');
    } else {
        zeroNotice.classList.add('hidden');
        breakdownSection.classList.remove('hidden');
        btnDraft.classList.remove('hidden');

        if (existingDefects && existingDefects.length > 0) {
            existingDefects.forEach(d => addDefectRow(d.defect_type_id, d.qty, d.notes));
        } else {
            addDefectRow('', currentTargetDefect, '');
        }
    }

    recalcQcSum();
    document.getElementById('qcVerifyModal').classList.remove('hidden');
}

function addDefectRow(selectedTypeId = '', qty = '', notes = '') {
    const types = DEFECT_TYPES_MAP[currentStageKey] || [];
    const idx = rowIndex++;
    const options = ['<option value="">-- Pilih Jenis Kerusakan --</option>'].concat(
        types.map(t => '<option value="' + t.id + '"' + (Number(selectedTypeId) === Number(t.id) ? ' selected' : '') + '>' + escapeQcHtml(t.name) + '</option>')
    ).join('');

    const row = document.createElement('div');
    row.className = 'grid grid-cols-12 gap-2 items-center qc-defect-row';
    row.innerHTML =
        '<div class="col-span-6"><select name="defects[' + idx + '][defect_type_id]" required class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">' + options + '</select></div>' +
        '<div class="col-span-2"><input type="number" name="defects[' + idx + '][qty]" min="1" required value="' + (qty || '') + '" oninput="recalcQcSum()" class="qc-qty-input w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs font-mono" placeholder="Qty"></div>' +
        '<div class="col-span-3"><input type="text" name="defects[' + idx + '][notes]" value="' + escapeQcHtml(notes || '') + '" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs" placeholder="Catatan"></div>' +
        '<div class="col-span-1 text-right"><button type="button" onclick="this.closest(\'.qc-defect-row\').remove(); recalcQcSum();" class="text-rose-500 hover:text-rose-700"><i class="fas fa-trash"></i></button></div>';
    document.getElementById('qcRowsContainer').appendChild(row);
    recalcQcSum();
}

function recalcQcSum() {
    let sum = 0;
    document.querySelectorAll('#qcRowsContainer .qc-qty-input').forEach(inp => {
        sum += Number(inp.value || 0);
    });
    const el = document.getElementById('qcCurrentSum');
    el.textContent = sum;
    el.className = 'font-mono font-bold ' + (sum === currentTargetDefect ? 'text-emerald-700' : 'text-amber-600');
}

function submitQcForm(action) {
    const form = document.getElementById('qcVerifyForm');
    if (action === 'draft') {
        form.action = "{{ url('/fitting/qc-defects') }}/" + currentExecId + "/save-draft";
    } else {
        form.action = "{{ url('/fitting/qc-defects') }}/" + currentExecId + "/verify";
    }
    form.submit();
}

function openReturnModal(execId, pfktr, stageLabel) {
    document.getElementById('qcReturnForm').action = "{{ url('/fitting/qc-defects') }}/" + execId + "/return";
    document.getElementById('retPfktr').textContent = pfktr;
    document.getElementById('retStageLabel').textContent = stageLabel;
    document.getElementById('retReason').value = '';
    document.getElementById('qcReturnModal').classList.remove('hidden');
}

function openReopenModal(execId, pfktr, stageLabel, defectQty) {
    document.getElementById('qcReopenForm').action = "{{ url('/fitting/qc-defects') }}/" + execId + "/reopen";
    document.getElementById('reopenPfktr').textContent = pfktr;
    document.getElementById('reopenStageLabel').textContent = stageLabel;
    document.getElementById('reopenDefectQty').textContent = defectQty;
    document.getElementById('reopenReason').value = '';
    document.getElementById('qcReopenModal').classList.remove('hidden');
}

function openQcLogsModal(pfktr, stageLabel, logs) {
    document.getElementById('qcLogTitle').textContent = pfktr + ' (' + stageLabel + ')';
    const body = document.getElementById('qcLogsBody');
    if (!logs || logs.length === 0) {
        body.innerHTML = '<div class="text-slate-400 text-center py-6">Belum ada catatan audit log defect.</div>';
    } else {
        body.innerHTML = logs.map(l => {
            const sign = l.added_qty > 0 ? '+' + l.added_qty : String(l.added_qty);
            let snapHtml = '';
            if (l.qc_snapshot && l.qc_snapshot.previous_defects) {
                const defs = l.qc_snapshot.previous_defects.map(d => escapeQcHtml(d.defect_type_name) + ': ' + d.qty + ' PCS').join(', ') || '-';
                snapHtml = '<div class="mt-2 p-2 rounded bg-purple-50 border border-purple-200 text-[11px] text-purple-900">' +
                    '<strong>Arsip Verifikasi QC Sebelumnya:</strong> ' + escapeQcHtml(l.qc_snapshot.previous_qc_verified_by_name || '-') +
                    ' (' + escapeQcHtml(l.qc_snapshot.previous_qc_verified_at || '-') + ')<br>' +
                    '<strong>Rincian:</strong> ' + defs +
                '</div>';
            }
            return '<div class="border border-slate-200 rounded-lg p-3 bg-slate-50">' +
                '<div class="flex items-center justify-between font-semibold text-slate-700">' +
                    '<span>' + escapeQcHtml(l.actor) + '</span>' +
                    '<span class="text-slate-400 font-mono">' + escapeQcHtml(l.at || '-') + '</span>' +
                '</div>' +
                '<div class="mt-1 font-mono text-slate-600">Delta: <strong>' + sign + '</strong> &middot; ' + l.previous_total + ' &rarr; <strong>' + l.new_total + ' PCS</strong></div>' +
                '<div class="mt-1 text-slate-500">' + escapeQcHtml(l.notes || '-') + '</div>' +
                snapHtml +
            '</div>';
        }).join('');
    }
    document.getElementById('qcLogsModal').classList.remove('hidden');
}

function escapeQcHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>
@endsection
