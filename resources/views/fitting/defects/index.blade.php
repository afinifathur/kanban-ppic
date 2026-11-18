@extends('layouts.app')

@section('title', 'Pencatatan Kerusakan Fitting')

@section('top_bar')
<div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2 w-full">
    <div>
        <h1 class="text-lg font-bold text-slate-800 flex items-center gap-2">
            <i class="fas fa-exclamation-circle text-rose-500"></i>
            Pencatatan Kerusakan Fitting
        </h1>
        <p class="text-xs text-slate-500">Input dan penyesuaian kuantitas defect per PFKTR untuk operasional Fitting Production</p>
    </div>
    <div class="flex items-center gap-2">
        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-rose-50 text-rose-700 border border-rose-200">
            <i class="fas fa-hourglass-half mr-1.5"></i>
            Belum Dicatat: <strong class="ml-1">{{ number_format($summary['unrecorded_total']) }}</strong>
        </span>
        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200">
            <i class="fas fa-clipboard-check mr-1.5"></i>
            Menunggu QC: <strong class="ml-1">{{ number_format($summary['waiting_qc_total']) }}</strong>
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
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Belum Dicatat</div>
            <div class="mt-1 text-2xl font-bold text-rose-600">{{ number_format($summary['unrecorded_total']) }}</div>
            <div class="text-xs text-slate-400 mt-1">Status WAITING_DEFECT</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Nihil (0 PCS)</div>
            <div class="mt-1 text-2xl font-bold text-slate-700">{{ number_format($summary['no_defect_total']) }}</div>
            <div class="text-xs text-slate-400 mt-1">NO_DEFECT ({{ number_format($summary['auto_nihil_total']) }} Auto-Nihil)</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Menunggu QC</div>
            <div class="mt-1 text-2xl font-bold text-amber-600">{{ number_format($summary['waiting_qc_total']) }}</div>
            <div class="text-xs text-slate-400 mt-1">WAITING_QC (&gt; 0 PCS)</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Reopen QC</div>
            <div class="mt-1 text-2xl font-bold text-purple-600">{{ number_format($summary['reopened_total']) }}</div>
            <div class="text-xs text-slate-400 mt-1">Perlu Koreksi PPIC</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Terverifikasi QC</div>
            <div class="mt-1 text-2xl font-bold text-emerald-600">{{ number_format($summary['confirmed_total']) }}</div>
            <div class="text-xs text-slate-400 mt-1">Status CONFIRMED</div>
        </div>
    </div>

    {{-- Mode Tabs & Filters --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="border-b border-slate-200 px-4 pt-3 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <a href="{{ route('fitting.defects.index', array_filter(['mode' => 'unrecorded', 'stage' => $selectedStage, 'search' => $search])) }}"
                   class="px-4 py-2 text-sm font-semibold border-b-2 transition {{ $mode === 'unrecorded' ? 'border-rose-600 text-rose-700' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
                    Belum Dicatat
                    <span class="ml-1.5 px-2 py-0.5 text-xs rounded-full {{ $mode === 'unrecorded' ? 'bg-rose-100 text-rose-700' : 'bg-slate-100 text-slate-600' }}">{{ $summary['unrecorded_total'] }}</span>
                </a>
                <a href="{{ route('fitting.defects.index', array_filter(['mode' => 'recorded', 'stage' => $selectedStage, 'search' => $search])) }}"
                   class="px-4 py-2 text-sm font-semibold border-b-2 transition {{ $mode === 'recorded' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
                    Sudah Dicatat
                    <span class="ml-1.5 px-2 py-0.5 text-xs rounded-full {{ $mode === 'recorded' ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-600' }}">{{ $summary['recorded_total'] }}</span>
                </a>
            </div>

            <form method="GET" action="{{ route('fitting.defects.index') }}" class="pb-2 flex items-center gap-2">
                <input type="hidden" name="mode" value="{{ $mode }}">
                @if($selectedStage)
                    <input type="hidden" name="stage" value="{{ $selectedStage }}">
                @endif
                <input type="text" name="search" value="{{ $search }}"
                       placeholder="Cari PFKTR / Heat / Item / Customer..."
                       class="w-60 md:w-72 rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:ring-2 focus:ring-rose-500 focus:border-rose-500">
                <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-800 text-white text-sm font-medium hover:bg-slate-700">
                    <i class="fas fa-search mr-1"></i>Cari
                </button>
                @if($search !== '' || $selectedStage !== null)
                    <a href="{{ route('fitting.defects.index', ['mode' => $mode]) }}" class="px-2.5 py-1.5 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">
                        Reset
                    </a>
                @endif
            </form>
        </div>

        {{-- Stage Filter Pills --}}
        <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center gap-2">
            @php
                $stageCounts = $mode === 'unrecorded' ? $summary['per_stage_unrecorded'] : $summary['per_stage_recorded'];
            @endphp
            <a href="{{ route('fitting.defects.index', array_filter(['mode' => $mode, 'search' => $search])) }}"
               class="px-3 py-1 rounded-full text-xs font-semibold border transition {{ $selectedStage === null ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-100' }}">
                Semua Tahap
            </a>
            @foreach($stages as $st)
                @php $cnt = (int) ($stageCounts[$st] ?? 0); @endphp
                <a href="{{ route('fitting.defects.index', array_filter(['mode' => $mode, 'stage' => $st, 'search' => $search])) }}"
                   class="px-3 py-1 rounded-full text-xs font-semibold border transition {{ $selectedStage === $st ? 'bg-rose-600 text-white border-rose-600' : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-100' }}">
                    {{ $stageLabels[$st] ?? strtoupper($st) }}
                    <span class="ml-1 opacity-80">({{ $cnt }})</span>
                </a>
            @endforeach
        </div>

        {{-- Executions Table --}}
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold">PFKTR / Heat</th>
                        <th class="px-4 py-3 text-left font-semibold">Item & Customer</th>
                        <th class="px-4 py-3 text-left font-semibold">Tahap & Scan Fisik</th>
                        <th class="px-4 py-3 text-right font-semibold">Transfer</th>
                        <th class="px-4 py-3 text-right font-semibold">Input Efektif</th>
                        <th class="px-4 py-3 text-right font-semibold">Defect</th>
                        <th class="px-4 py-3 text-right font-semibold">Good Efektif</th>
                        <th class="px-4 py-3 text-left font-semibold">Status</th>
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
                            $maxAllowed = (int) $exec->max_allowed_defect_qty;
                            $isLocked = (bool) $exec->is_quality_locked;
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
                                    @if($item?->current_stage === \App\Models\FittingItem::STAGE_COMPLETED)
                                        <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-100 text-emerald-700">COMPLETED</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div class="font-semibold text-slate-800">{{ $item?->item_name ?? '-' }}</div>
                                <div class="text-xs text-slate-500">{{ $item?->production_code ?? '-' }} &middot; {{ $item?->customer ?? '-' }}</div>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <span class="inline-flex px-2 py-0.5 rounded text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200">{{ $stageLabel }}</span>
                                <div class="text-xs text-slate-500 mt-1">
                                    <i class="far fa-clock mr-1"></i>{{ $exec->physical_done_at?->format('d/m/Y H:i') ?? '-' }}
                                </div>
                                <div class="text-xs text-slate-400">SPV: {{ $exec->operator?->name ?? '-' }}</div>
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-slate-600 align-top">{{ number_format((int) ($item?->qty_transferred ?? 0)) }}</td>
                            <td class="px-4 py-3 text-right font-mono font-semibold text-slate-800 align-top">
                                {{ number_format($effectiveInput) }}
                                @if($effectiveInput !== (int) $exec->input_qty)
                                    <div class="text-[10px] text-slate-400" title="Snapshot saat scan fisik">Scan: {{ number_format((int) $exec->input_qty) }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-mono font-bold {{ (int) $exec->defect_qty > 0 ? 'text-rose-600' : 'text-slate-500' }} align-top">
                                {{ number_format((int) $exec->defect_qty) }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono font-bold text-emerald-700 align-top">{{ number_format($effectiveGood) }}</td>
                            <td class="px-4 py-3 align-top space-y-1">
                                @if($exec->status === \App\Models\FittingStageExecution::STATUS_WAITING_DEFECT)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-rose-100 text-rose-700">WAITING DEFECT</span>
                                @elseif($exec->status === \App\Models\FittingStageExecution::STATUS_NO_DEFECT)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-700">NO DEFECT (0 PCS)</span>
                                @elseif($exec->status === \App\Models\FittingStageExecution::STATUS_WAITING_QC)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800">WAITING QC</span>
                                @elseif($exec->status === \App\Models\FittingStageExecution::STATUS_REOPENED_FOR_CORRECTION)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-purple-100 text-purple-800">REOPEN QC (PERLU KOREKSI)</span>
                                @elseif($exec->status === \App\Models\FittingStageExecution::STATUS_CONFIRMED)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800">CONFIRMED</span>
                                @endif
                                @if($exec->is_auto_nihil)
                                    <div><span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-indigo-100 text-indigo-700"><i class="fas fa-robot mr-1"></i>AUTO-NIHIL</span></div>
                                @elseif($exec->auto_nihil_at)
                                    <div><span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200" title="Pernah diproses Auto-Nihil pada {{ $exec->auto_nihil_at->format('d/m/Y H:i') }}"><i class="fas fa-history mr-1"></i>Pernah Auto-Nihil</span></div>
                                @endif
                                @if($isLocked)
                                    <div><span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-800 text-white"><i class="fas fa-lock mr-1"></i>QUALITY LOCK</span></div>
                                @endif
                                @if($exec->notes)
                                    <div class="text-[11px] text-slate-500 max-w-xs truncate" title="{{ $exec->notes }}">{{ $exec->notes }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right align-top whitespace-nowrap">
                                <div class="inline-flex flex-col items-end gap-1.5">
                                    @if($isLocked)
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-slate-100 text-slate-500 text-xs font-medium cursor-not-allowed" title="Terkunci oleh Final Quality Lock (Gudang Jadi PF selesai)">
                                            <i class="fas fa-lock mr-1"></i>Terkunci
                                        </span>
                                    @elseif($exec->status === \App\Models\FittingStageExecution::STATUS_WAITING_DEFECT)
                                        <button type="button"
                                                onclick="openRecordModal({{ $exec->id }}, @js($pfktr), @js($stageLabel), {{ $effectiveInput }}, {{ $maxAllowed }}, @js($exec->notes ?? ''))"
                                                class="inline-flex items-center px-3 py-1.5 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold shadow-sm">
                                            <i class="fas fa-edit mr-1.5"></i>Catat Defect
                                        </button>
                                    @elseif($exec->status === \App\Models\FittingStageExecution::STATUS_CONFIRMED)
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-medium" title="Sudah terverifikasi QC. Admin QC harus melakukan Reopen terlebih dahulu untuk koreksi.">
                                            <i class="fas fa-shield-alt mr-1"></i>Perlu Reopen QC
                                        </span>
                                    @else
                                        <button type="button"
                                                onclick="openAdjustModal({{ $exec->id }}, @js($pfktr), @js($stageLabel), {{ (int) $exec->defect_qty }}, {{ $maxAllowed }}, @js($exec->status))"
                                                class="inline-flex items-center px-3 py-1.5 rounded-lg {{ $exec->status === \App\Models\FittingStageExecution::STATUS_REOPENED_FOR_CORRECTION ? 'bg-purple-600 hover:bg-purple-700' : 'bg-indigo-600 hover:bg-indigo-700' }} text-white text-xs font-semibold shadow-sm">
                                            <i class="fas fa-sliders-h mr-1.5"></i>{{ $exec->status === \App\Models\FittingStageExecution::STATUS_REOPENED_FOR_CORRECTION ? 'Koreksi Pasca Reopen' : 'Sesuaikan (+/-)' }}
                                        </button>
                                    @endif

                                    <button type="button"
                                            onclick='openLogsModal(@js($pfktr), @js($stageLabel), @json($logsPayload))'
                                            class="inline-flex items-center px-2.5 py-1 rounded border border-slate-200 hover:bg-slate-100 text-slate-600 text-[11px] font-medium">
                                        <i class="fas fa-history mr-1"></i>Log ({{ count($logsPayload) }})
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-10 text-center text-slate-400">
                                <i class="fas fa-inbox text-3xl mb-2 block"></i>
                                Tidak ada data eksekusi Fitting pada filter ini.
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

{{-- Modal: Record Initial Defect --}}
<div id="recordModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl max-w-md w-full overflow-hidden">
        <div class="px-5 py-4 bg-rose-600 text-white flex items-center justify-between">
            <h3 class="font-bold text-base"><i class="fas fa-exclamation-circle mr-2"></i>Catat Defect Fitting</h3>
            <button type="button" onclick="closeModal('recordModal')" class="text-white/80 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        <form id="recordForm" method="POST" class="p-5 space-y-4">
            @csrf
            <input type="hidden" name="idempotency_key" id="recordIdempotencyKey">
            <div class="bg-slate-50 rounded-lg p-3 text-xs space-y-1 border border-slate-200">
                <div><strong>PFKTR:</strong> <span id="recPfktr" class="font-mono"></span></div>
                <div><strong>Tahap:</strong> <span id="recStage"></span></div>
                <div><strong>Input Efektif:</strong> <span id="recEffectiveInput" class="font-mono font-bold"></span> PCS &middot; <strong>Maks Defect:</strong> <span id="recMaxAllowed" class="font-mono font-bold text-rose-600"></span> PCS</div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Jumlah Defect (PCS) <span class="text-rose-500">*</span></label>
                <input type="number" name="defect_qty" id="recDefectQty" min="0" required
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-rose-500">
                <p class="text-[11px] text-slate-400 mt-1">Isi 0 jika seluruh kuantitas dinyatakan baik (nihil defect).</p>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Tanggal Pemeriksaan (Opsional)</label>
                <input type="date" name="inspection_date" max="{{ now()->format('Y-m-d') }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Catatan (Opsional)</label>
                <textarea name="notes" id="recNotes" rows="2" maxlength="500"
                          class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Catatan pemeriksaan..."></textarea>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeModal('recordModal')" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium hover:bg-slate-50">Batal</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-sm font-semibold">Simpan Defect</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal: Adjust Defect (+/-) --}}
<div id="adjustModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl max-w-md w-full overflow-hidden">
        <div class="px-5 py-4 bg-indigo-600 text-white flex items-center justify-between">
            <h3 class="font-bold text-base"><i class="fas fa-sliders-h mr-2"></i>Penyesuaian Defect (+/-)</h3>
            <button type="button" onclick="closeModal('adjustModal')" class="text-white/80 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        <form id="adjustForm" method="POST" class="p-5 space-y-4">
            @csrf
            <input type="hidden" name="idempotency_key" id="adjustIdempotencyKey">
            <div class="bg-slate-50 rounded-lg p-3 text-xs space-y-1 border border-slate-200">
                <div><strong>PFKTR:</strong> <span id="adjPfktr" class="font-mono"></span> (<span id="adjStage"></span>)</div>
                <div><strong>Defect Saat Ini:</strong> <span id="adjCurrentDefect" class="font-mono font-bold text-rose-600"></span> PCS &middot; <strong>Maks Total:</strong> <span id="adjMaxAllowed" class="font-mono font-bold"></span> PCS</div>
            </div>
            <div id="adjReopenWarn" class="hidden bg-purple-50 border border-purple-200 text-purple-900 rounded-lg p-3 text-xs">
                <i class="fas fa-undo mr-1"></i>
                Eksekusi ini <strong>dibuka kembali (Reopen) oleh QC</strong>. Masukkan delta koreksi (mis. <code>-5</code> untuk menjadi 0 PCS, atau isi <code>0</code> jika jumlah defect sebenarnya tetap sama) beserta <strong>alasan wajib</strong>.
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Delta Penyesuaian (+/- PCS) <span class="text-rose-500">*</span></label>
                <input type="number" name="adjustment_qty" id="adjDeltaQty" required
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-indigo-500"
                       placeholder="Contoh: +2, -1, atau 0 (khusus Reopen QC)">
                <p id="adjDeltaHint" class="text-[11px] text-slate-400 mt-1">Nilai positif menambah defect, nilai negatif mengurangi defect (alasan wajib diisi).</p>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Alasan / Catatan <span id="adjNoteReq" class="text-rose-500 hidden">*</span></label>
                <textarea name="notes" id="adjNotes" rows="2" maxlength="1000"
                          class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Wajib diisi untuk pengurangan defect atau koreksi pasca Reopen QC..."></textarea>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeModal('adjustModal')" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium hover:bg-slate-50">Batal</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold">Simpan Penyesuaian</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal: Audit Log --}}
<div id="logsModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full overflow-hidden">
        <div class="px-5 py-4 bg-slate-800 text-white flex items-center justify-between">
            <h3 class="font-bold text-base"><i class="fas fa-history mr-2"></i>Audit Log Defect — <span id="logTitle" class="font-mono"></span></h3>
            <button type="button" onclick="closeModal('logsModal')" class="text-white/80 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        <div id="logsBody" class="p-5 max-h-96 overflow-y-auto space-y-2.5 text-xs"></div>
        <div class="px-5 py-3 bg-slate-50 border-t border-slate-200 flex justify-end">
            <button type="button" onclick="closeModal('logsModal')" class="px-4 py-1.5 rounded-lg bg-slate-800 text-white text-xs font-semibold">Tutup</button>
        </div>
    </div>
</div>

<script>
function genIdemKey(prefix, execId) {
    return prefix + '-' + execId + '-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
}
function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}
function openRecordModal(execId, pfktr, stageLabel, effectiveInput, maxAllowed, existingNotes) {
    document.getElementById('recordForm').action = "{{ url('/fitting/defects') }}/" + execId + "/record";
    document.getElementById('recordIdempotencyKey').value = genIdemKey('fit-rec', execId);
    document.getElementById('recPfktr').textContent = pfktr;
    document.getElementById('recStage').textContent = stageLabel;
    document.getElementById('recEffectiveInput').textContent = effectiveInput;
    document.getElementById('recMaxAllowed').textContent = maxAllowed;
    const qtyInput = document.getElementById('recDefectQty');
    qtyInput.value = 0;
    qtyInput.max = maxAllowed;
    document.getElementById('recNotes').value = '';
    document.getElementById('recordModal').classList.remove('hidden');
}
function openAdjustModal(execId, pfktr, stageLabel, currentDefect, maxAllowed, status) {
    document.getElementById('adjustForm').action = "{{ url('/fitting/defects') }}/" + execId + "/adjust";
    document.getElementById('adjustIdempotencyKey').value = genIdemKey('fit-adj', execId);
    document.getElementById('adjPfktr').textContent = pfktr;
    document.getElementById('adjStage').textContent = stageLabel;
    document.getElementById('adjCurrentDefect').textContent = currentDefect;
    document.getElementById('adjMaxAllowed').textContent = maxAllowed;
    document.getElementById('adjDeltaQty').value = status === 'REOPENED_FOR_CORRECTION' ? '0' : '';
    document.getElementById('adjNotes').value = '';
    const warn = document.getElementById('adjReopenWarn');
    const reqMark = document.getElementById('adjNoteReq');
    if (status === 'REOPENED_FOR_CORRECTION') {
        warn.classList.remove('hidden');
        reqMark.classList.remove('hidden');
        document.getElementById('adjNotes').required = true;
    } else {
        warn.classList.add('hidden');
        reqMark.classList.add('hidden');
        document.getElementById('adjNotes').required = false;
    }
    document.getElementById('adjustModal').classList.remove('hidden');
}
function openLogsModal(pfktr, stageLabel, logs) {
    document.getElementById('logTitle').textContent = pfktr + ' (' + stageLabel + ')';
    const body = document.getElementById('logsBody');
    if (!logs || logs.length === 0) {
        body.innerHTML = '<div class="text-slate-400 text-center py-6">Belum ada catatan audit log defect.</div>';
    } else {
        body.innerHTML = logs.map(l => {
            const sign = l.added_qty > 0 ? '+' + l.added_qty : String(l.added_qty);
            let snapHtml = '';
            if (l.qc_snapshot && l.qc_snapshot.previous_defects) {
                const defs = l.qc_snapshot.previous_defects.map(d => escapeHtml(d.defect_type_name) + ': ' + d.qty + ' PCS').join(', ') || '-';
                snapHtml = '<div class="mt-2 p-2 rounded bg-purple-50 border border-purple-200 text-[11px] text-purple-900">' +
                    '<strong>Arsip Verifikasi QC Sebelumnya:</strong> ' + escapeHtml(l.qc_snapshot.previous_qc_verified_by_name || '-') +
                    ' (' + escapeHtml(l.qc_snapshot.previous_qc_verified_at || '-') + ')<br>' +
                    '<strong>Rincian:</strong> ' + defs +
                '</div>';
            }
            return '<div class="border border-slate-200 rounded-lg p-3 bg-slate-50">' +
                '<div class="flex items-center justify-between font-semibold text-slate-700">' +
                    '<span>' + escapeHtml(l.actor) + '</span>' +
                    '<span class="text-slate-400 font-mono">' + escapeHtml(l.at || '-') + '</span>' +
                '</div>' +
                '<div class="mt-1 font-mono text-slate-600">Delta: <strong>' + sign + '</strong> &middot; ' + l.previous_total + ' &rarr; <strong>' + l.new_total + ' PCS</strong></div>' +
                '<div class="mt-1 text-slate-500">' + escapeHtml(l.notes || '-') + '</div>' +
                snapHtml +
            '</div>';
        }).join('');
    }
    document.getElementById('logsModal').classList.remove('hidden');
}
function escapeHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>
@endsection
