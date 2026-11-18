@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full gap-2 min-w-0">
        <div class="min-w-0">
            <h1 class="text-sm sm:text-lg font-black text-slate-800 leading-tight truncate">
                SCANNER FITTING &mdash; {{ $stageLabel }}
            </h1>
            <p class="text-slate-500 text-[10px] truncate">
                FITTING PRODUCTION &bull; Pindai Nomor Kitir (PFKTR) untuk konfirmasi fisik tahap {{ $stageLabel }}
            </p>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <a href="{{ $kanbanUrl }}"
               class="inline-flex items-center px-2.5 py-1.5 rounded-lg text-xs font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-300 transition-colors">
                <i class="fas fa-columns mr-1.5 text-slate-500"></i>
                <span class="hidden sm:inline">Kanban</span>
            </a>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-emerald-100 text-emerald-800 border border-emerald-300 shadow-sm">
                <i class="fas fa-cubes mr-1.5 text-emerald-600"></i> {{ $stageLabel }}
            </span>
        </div>
    </div>
@endsection

@section('content')
<div class="max-w-xl mx-auto px-2 sm:px-4 py-3 space-y-4">

    {{-- Optional Stage Switcher (System Admin only) --}}
    @if(!empty($switchableStages))
        <div class="bg-white rounded-xl border border-slate-200 p-1.5 shadow-sm overflow-x-auto">
            <div class="flex items-center gap-1 min-w-max">
                @foreach($switchableStages as $stg)
                    <a href="{{ $stg['url'] }}"
                       class="px-2.5 py-1.5 rounded-lg text-[11px] font-bold transition-colors {{ $stg['is_active'] ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100' }}">
                        {{ $stg['label'] }}
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- 1. STAGE CONTEXT BANNER --}}
    <div class="bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 rounded-2xl p-4 text-white shadow-md border border-slate-700 flex items-center justify-between">
        <div>
            <span class="text-[11px] uppercase tracking-widest text-emerald-400 font-semibold block">Stasiun Kerja Fitting Production</span>
            <div class="text-2xl font-black tracking-tight text-white flex items-center gap-2 mt-0.5">
                <span>{{ $stageLabel }}</span>
                <span class="text-xs font-semibold px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">Aktif</span>
            </div>
        </div>
        <div class="text-right">
            <span class="text-[11px] text-slate-400 block">Supervisor / Operator</span>
            <span class="text-sm font-bold text-slate-200">{{ Auth::user()->name }}</span>
        </div>
    </div>

    {{-- 2. MAIN SCANNER INPUT CARD --}}
    <div id="scannerActionCard" class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5 text-center space-y-4">
        <div class="py-1">
            <div class="w-14 h-14 bg-emerald-50 text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-2.5 shadow-inner text-2xl">
                <i class="fas fa-qrcode"></i>
            </div>
            <h2 class="text-base font-bold text-slate-800">Scan Kitir Produksi Fitting (PFKTR)</h2>
            <p class="text-slate-500 text-xs mt-0.5">Gunakan barcode scanner gun, kamera perangkat, atau ketik nomor PFKTR</p>
        </div>

        {{-- Fast PFKTR Input (Hardware Gun / Manual Typing — auto-focused) --}}
        <form id="manualPfktrForm" class="flex gap-2" autocomplete="off">
            <input
                type="text"
                id="manualPfktrInput"
                name="traveler_number"
                placeholder="PFKTR-YYYYMMDD-XXXX"
                autofocus
                class="flex-1 min-h-[48px] px-3.5 text-center font-mono font-bold text-sm tracking-wider uppercase border-2 border-emerald-400 rounded-xl focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200 outline-none"
                autocomplete="off"
            >
            <button
                type="submit"
                id="btnLookupPfktr"
                class="min-h-[48px] px-5 bg-slate-900 hover:bg-black text-white font-bold rounded-xl text-sm transition-colors touch-manipulation shadow-sm">
                CARI
            </button>
        </form>

        {{-- Big Touch Action Buttons --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 pt-1">
            <button
                type="button"
                id="btnOpenScanCamera"
                class="w-full min-h-[48px] bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-black rounded-xl shadow-sm transition-all flex items-center justify-center gap-2 text-xs touch-manipulation">
                <i class="fas fa-camera text-sm"></i>
                <span>BUKA KAMERA SCANNER</span>
            </button>

            <button
                type="button"
                id="btnOpenHeatModal"
                class="w-full min-h-[48px] bg-amber-50 hover:bg-amber-100 text-amber-900 border border-amber-300 font-bold rounded-xl transition-all flex items-center justify-center gap-2 text-xs touch-manipulation">
                <i class="fas fa-search text-amber-700"></i>
                <span>CARI ALTERNATIF VIA HEAT</span>
            </button>
        </div>
    </div>

    {{-- 3. FEEDBACK ALERT AREA --}}
    <div id="scanFeedbackBox" class="hidden rounded-2xl p-4 border shadow-sm text-xs"></div>

    {{-- 4. TRAVELER IDENTITY & CONFIRMATION CARD --}}
    <div id="travelerCard" class="hidden bg-white rounded-2xl shadow-md border-2 border-slate-200 overflow-hidden">
        <div class="px-5 py-3.5 bg-slate-900 text-white flex items-center justify-between gap-2">
            <div>
                <span class="text-[10px] uppercase tracking-wider text-slate-400 font-bold block">Identitas Traveler Fitting</span>
                <div id="cardPfktr" class="text-lg font-mono font-black text-emerald-400">-</div>
            </div>
            <div class="text-right">
                <span id="cardStageBadge" class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold bg-slate-800 text-white border border-slate-600">-</span>
            </div>
        </div>

        <div class="p-5 space-y-4">
            <div class="grid grid-cols-2 gap-3 text-xs">
                <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
                    <span class="text-[10px] uppercase font-bold text-slate-400 block">Heat Number</span>
                    <span id="cardHeat" class="font-mono font-bold text-slate-900 text-sm">-</span>
                </div>
                <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
                    <span class="text-[10px] uppercase font-bold text-slate-400 block">Kode Produksi</span>
                    <span id="cardProdCode" class="font-mono font-bold text-slate-900 text-sm">-</span>
                </div>
                <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
                    <span class="text-[10px] uppercase font-bold text-slate-400 block">Customer</span>
                    <span id="cardCustomer" class="font-semibold text-slate-800">-</span>
                </div>
                <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
                    <span class="text-[10px] uppercase font-bold text-slate-400 block">Line / Bucket</span>
                    <span id="cardLine" class="font-bold text-emerald-700">-</span>
                </div>
                <div class="col-span-2 bg-slate-50 rounded-xl p-3 border border-slate-100">
                    <span class="text-[10px] uppercase font-bold text-slate-400 block">Nama Item</span>
                    <span id="cardItemName" class="font-semibold text-slate-900">-</span>
                </div>
                <div class="bg-emerald-50 rounded-xl p-3 border border-emerald-200">
                    <span class="text-[10px] uppercase font-bold text-emerald-700 block">Qty Efektif Masuk</span>
                    <span id="cardQty" class="font-mono font-black text-emerald-900 text-base">-</span>
                    <div id="cardQtySub" class="text-[10px] text-emerald-700/80 mt-0.5">-</div>
                </div>
                <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
                    <span class="text-[10px] uppercase font-bold text-slate-400 block">Total Berat / Transfer</span>
                    <span id="cardWeight" class="font-mono font-bold text-slate-900">-</span>
                    <div id="cardTransferDate" class="text-[10px] text-slate-500 mt-0.5">-</div>
                </div>
            </div>

            {{-- Eligibility Warning (when traveler cannot be scanned on this stage) --}}
            <div id="cardIneligibleBanner" class="hidden bg-rose-50 border border-rose-200 rounded-xl p-3.5 text-rose-800 text-xs font-semibold flex items-start gap-2.5">
                <i class="fas fa-ban text-rose-500 text-base mt-0.5 shrink-0"></i>
                <div id="cardIneligibleReason">-</div>
            </div>

            {{-- Execution Action Area (ONLY when eligible — 2-Step Confirmation like Sand Casting) --}}
            <div id="cardExecuteArea" class="hidden space-y-3 pt-2 border-t border-slate-100">
                <div>
                    <label for="scanNotesInput" class="block text-[11px] font-bold text-slate-600 mb-1">Catatan Eksekusi (Opsional)</label>
                    <input type="text" id="scanNotesInput" maxlength="500"
                           placeholder="Catatan lapangan jika ada..."
                           class="w-full px-3 py-2 text-xs border border-slate-300 rounded-xl focus:outline-none focus:border-emerald-500">
                </div>
                <div class="grid grid-cols-2 gap-2.5">
                    <button type="button" id="btnCancelTraveler"
                            class="min-h-[52px] bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-sm transition-colors touch-manipulation">
                        BATAL
                    </button>
                    <button type="button" id="btnOpenConfirmModal"
                            class="min-h-[52px] bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-black rounded-xl shadow-md transition-all flex items-center justify-center gap-1.5 text-sm touch-manipulation">
                        <i class="fas fa-check-circle text-base"></i>
                        <span>SELESAI PROSES</span>
                    </button>
                </div>
            </div>

            {{-- Execution History Timeline --}}
            <div id="cardHistorySection" class="pt-3 border-t border-slate-100">
                <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider block mb-2">Riwayat Eksekusi Tahap</span>
                <div id="cardHistoryList" class="space-y-1.5 text-xs"></div>
            </div>

            <div class="pt-1">
                <button type="button" id="btnResetScanner"
                        class="w-full py-2.5 rounded-xl text-xs font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 transition-colors">
                    <i class="fas fa-redo mr-1.5"></i> Scan PFKTR Lainnya
                </button>
            </div>
        </div>
    </div>

    {{-- 4B. PRE-EXECUTION 2-STEP CONFIRMATION MODAL --}}
    <div id="confirmScanModal" class="fixed inset-0 z-50 bg-black/75 flex items-end sm:items-center justify-center p-3 hidden">
        <div class="bg-white w-full max-w-sm rounded-3xl p-5 space-y-4 shadow-2xl">
            <div class="text-center">
                <div class="w-12 h-12 bg-emerald-100 text-emerald-700 rounded-full flex items-center justify-center mx-auto mb-2 text-xl shadow-inner">
                    <i class="fas fa-clipboard-check"></i>
                </div>
                <h3 class="text-base font-black text-slate-900">Konfirmasi Selesai Fisik</h3>
                <p class="text-xs text-slate-500 mt-0.5">Bandingkan fisik &amp; Kitir dengan kuantitas efektif sebelum konfirmasi.</p>
            </div>

            <div class="bg-slate-50 rounded-2xl p-4 border border-slate-200 space-y-2 text-xs">
                <div class="flex justify-between items-center">
                    <span class="text-slate-500 font-medium">Nomor PFKTR:</span>
                    <span id="confirmPfktr" class="font-mono font-black text-slate-900 text-sm">-</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-500 font-medium">Tahap:</span>
                    <span class="font-black text-slate-900 uppercase">{{ $stageLabel }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-500 font-medium">Line Fitting:</span>
                    <span id="confirmLine" class="font-bold text-emerald-800">-</span>
                </div>
                <div class="flex justify-between items-center pt-2 border-t border-slate-200">
                    <span class="text-slate-700 font-bold">Qty Efektif Masuk:</span>
                    <span id="confirmEffectiveQty" class="font-mono font-black text-emerald-700 text-base">0 PCS</span>
                </div>
            </div>

            @if($stage === \App\Models\FittingItem::STAGE_GUDANG_JADI_PF)
                <div class="bg-amber-50 border border-amber-300 rounded-xl p-3 text-[11px] text-amber-900 leading-relaxed">
                    <i class="fas fa-lock mr-1 text-amber-700"></i>
                    <strong>Perhatian Final Quality Lock:</strong> Jika jumlah fisik tidak sesuai Kitir/sistem, klik <strong>BATAL</strong> dan kembalikan Kitir ke produksi. Setelah konfirmasi Gudang Jadi PF, tahap 1–5 akan terkunci permanen.
                </div>
            @endif

            <div class="grid grid-cols-2 gap-2.5 pt-1">
                <button type="button" id="btnCancelConfirmModal"
                        class="min-h-[48px] bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-sm touch-manipulation">
                    BATAL
                </button>
                <button type="button" id="btnConfirmExecute"
                        class="min-h-[48px] bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 disabled:bg-slate-300 text-white font-black rounded-xl text-sm shadow-md transition-all touch-manipulation flex items-center justify-center gap-1.5">
                    <span>SELESAI</span>
                </button>
            </div>
        </div>
    </div>

    {{-- 5. CAMERA SCANNER MODAL --}}
    <div id="cameraModal" class="fixed inset-0 z-50 bg-black/90 flex flex-col items-center justify-between p-4 hidden">
        <div class="w-full max-w-md flex items-center justify-between text-white pt-2">
            <div class="flex items-center gap-2">
                <i class="fas fa-camera text-emerald-400"></i>
                <span class="font-bold text-sm">Kamera Scanner &mdash; {{ $stageLabel }}</span>
            </div>
            <button type="button" id="btnCloseCamera" class="w-10 h-10 rounded-full bg-white/20 text-white flex items-center justify-center text-lg">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="relative w-full max-w-md flex-1 my-4 flex items-center justify-center overflow-hidden rounded-2xl bg-black border border-slate-800">
            <video id="cameraVideo" playsinline autoplay muted class="w-full h-full object-cover"></video>
            <div class="absolute inset-0 pointer-events-none flex flex-col items-center justify-center p-6">
                <div class="w-64 h-40 border-2 border-emerald-400 rounded-xl relative shadow-[0_0_0_9999px_rgba(0,0,0,0.5)]">
                    <div class="w-full h-0.5 bg-emerald-400 shadow-[0_0_8px_#34d399] animate-pulse my-auto absolute inset-0"></div>
                </div>
                <p class="text-white text-xs font-semibold bg-black/70 px-3 py-1 rounded-full mt-4">
                    Arahkan QR / Barcode PFKTR ke dalam kotak
                </p>
            </div>
        </div>

        <div class="w-full max-w-md pb-4 text-center">
            <button type="button" id="btnCancelCamera" class="w-full min-h-[48px] bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl text-sm">
                TUTUP KAMERA
            </button>
        </div>
    </div>

    {{-- 6. HEAT NUMBER FALLBACK SEARCH MODAL (SELECT SPECIFIC PFKTR ONLY) --}}
    <div id="heatModal" class="fixed inset-0 z-50 bg-black/60 flex items-end sm:items-center justify-center p-2 sm:p-4 hidden">
        <div class="bg-white w-full max-w-md rounded-t-3xl sm:rounded-2xl shadow-xl max-h-[90vh] flex flex-col overflow-hidden">
            <div class="p-4 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-bold text-slate-800">Pencarian Alternatif Berdasarkan Heat Number</h3>
                    <p class="text-[11px] text-slate-500">Pilih <strong>satu nomor PFKTR spesifik</strong> dari daftar untuk dipindai.</p>
                </div>
                <button type="button" id="btnCloseHeatModal" class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="p-4 space-y-3">
                <form id="heatSearchForm" class="flex gap-2" autocomplete="off">
                    <input
                        type="text"
                        id="heatSearchInput"
                        placeholder="Masukkan Heat Number..."
                        class="flex-1 min-h-[44px] px-3.5 uppercase font-mono font-bold text-sm border-2 border-slate-300 rounded-xl focus:border-amber-500 outline-none"
                    >
                    <button type="submit" id="btnSubmitHeatSearch"
                            class="min-h-[44px] px-4 bg-amber-600 hover:bg-amber-700 text-white font-bold rounded-xl text-sm">
                        CARI
                    </button>
                </form>

                <div id="heatSearchResultArea" class="space-y-2 overflow-y-auto max-h-72 pt-1 text-xs">
                    <p class="text-slate-400 text-center py-4">Masukkan nomor Heat untuk melihat daftar PFKTR terkait.</p>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
(function () {
    const STAGE = @json($stage);
    const STAGE_LABEL = @json($stageLabel);
    const EXECUTE_URL = @json($executeUrl);
    const LOOKUP_PFKTR_URL = @json($lookupPfktrUrl);
    const LOOKUP_HEAT_URL = @json($lookupHeatUrl);
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    let currentTraveler = null;
    let cameraStream = null;
    let scanLoopActive = false;

    const pfktrInput = document.getElementById('manualPfktrInput');
    const pfktrForm = document.getElementById('manualPfktrForm');
    const feedbackBox = document.getElementById('scanFeedbackBox');
    const travelerCard = document.getElementById('travelerCard');
    const confirmScanModal = document.getElementById('confirmScanModal');
    const btnOpenConfirmModal = document.getElementById('btnOpenConfirmModal');
    const btnCancelTraveler = document.getElementById('btnCancelTraveler');
    const btnCancelConfirmModal = document.getElementById('btnCancelConfirmModal');
    const btnConfirm = document.getElementById('btnConfirmExecute');
    const notesInput = document.getElementById('scanNotesInput');

    function showFeedback(type, message) {
        feedbackBox.classList.remove('hidden', 'bg-emerald-50', 'border-emerald-300', 'text-emerald-900', 'bg-rose-50', 'border-rose-300', 'text-rose-900');
        if (type === 'success') {
            feedbackBox.classList.add('bg-emerald-50', 'border-emerald-300', 'text-emerald-900');
            feedbackBox.innerHTML = '<div class="flex items-start gap-2.5"><i class="fas fa-check-circle text-emerald-600 text-base mt-0.5"></i><div class="font-semibold">' + escapeHtml(message) + '</div></div>';
        } else {
            feedbackBox.classList.add('bg-rose-50', 'border-rose-300', 'text-rose-900');
            feedbackBox.innerHTML = '<div class="flex items-start gap-2.5"><i class="fas fa-exclamation-circle text-rose-600 text-base mt-0.5"></i><div class="font-semibold">' + escapeHtml(message) + '</div></div>';
        }
    }

    function hideFeedback() {
        feedbackBox.classList.add('hidden');
    }

    function escapeHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
    }

    function renderTravelerCard(t) {
        currentTraveler = t;
        document.getElementById('cardPfktr').textContent = t.traveler_number;
        document.getElementById('cardStageBadge').textContent = t.current_stage_label;
        document.getElementById('cardHeat').textContent = t.heat_number || '-';
        document.getElementById('cardProdCode').textContent = t.production_code || '-';
        document.getElementById('cardCustomer').textContent = t.customer || '-';
        document.getElementById('cardLine').textContent = t.line_label || 'Unassigned';
        document.getElementById('cardItemName').textContent = t.item_name || '-';
        const effQty = Number(t.effective_input_qty ?? t.qty_transferred ?? 0);
        const trfQty = Number(t.qty_transferred ?? 0);
        const upDef = Number(t.upstream_defect_qty ?? 0);
        document.getElementById('cardQty').textContent = effQty.toLocaleString('id-ID') + ' PCS';
        document.getElementById('cardQtySub').textContent = upDef > 0
            ? ('Transfer: ' + trfQty.toLocaleString('id-ID') + ' PCS (−' + upDef.toLocaleString('id-ID') + ' defect hulu)')
            : ('Transfer awal: ' + trfQty.toLocaleString('id-ID') + ' PCS');
        document.getElementById('cardWeight').textContent = Number(t.total_weight_kg || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' KG';
        document.getElementById('cardTransferDate').textContent = 'Transfer: ' + (t.transfer_date || '-');

        const ineligibleBanner = document.getElementById('cardIneligibleBanner');
        const executeArea = document.getElementById('cardExecuteArea');

        if (t.is_eligible_for_stage) {
            ineligibleBanner.classList.add('hidden');
            executeArea.classList.remove('hidden');
            btnConfirm.disabled = false;
            notesInput.value = '';
        } else {
            executeArea.classList.add('hidden');
            ineligibleBanner.classList.remove('hidden');
            document.getElementById('cardIneligibleReason').textContent = t.ineligible_reason || 'Traveler tidak memenuhi syarat tahap ini.';
        }

        const histList = document.getElementById('cardHistoryList');
        if (Array.isArray(t.executions) && t.executions.length > 0) {
            histList.innerHTML = t.executions.map((ex) =>
                '<div class="flex items-center justify-between bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">' +
                    '<div><span class="font-bold text-slate-800">' + escapeHtml(ex.stage_label) + '</span> <span class="text-[10px] text-slate-500">oleh ' + escapeHtml(ex.operator_name) + '</span></div>' +
                    '<div class="text-right font-mono text-[11px] text-emerald-700 font-bold">' + escapeHtml(ex.physical_done_at || '-') + '</div>' +
                '</div>'
            ).join('');
        } else {
            histList.innerHTML = '<div class="text-slate-400 italic">Belum ada riwayat scan tahap sebelumnya.</div>';
        }

        travelerCard.classList.remove('hidden');
    }

    async function lookupPfktr(rawValue) {
        const pfktr = String(rawValue || '').trim().toUpperCase();
        if (!pfktr) return;

        hideFeedback();
        travelerCard.classList.add('hidden');
        currentTraveler = null;

        try {
            const url = LOOKUP_PFKTR_URL + '/' + encodeURIComponent(pfktr) + '?stage=' + encodeURIComponent(STAGE);
            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            const json = await res.json();

            if (!res.ok || !json.success) {
                showFeedback('error', json.message || 'PFKTR tidak ditemukan.');
                return;
            }

            renderTravelerCard(json.data);
        } catch (err) {
            showFeedback('error', 'Gagal menghubungi server: ' + err.message);
        }
    }

    pfktrForm.addEventListener('submit', function (e) {
        e.preventDefault();
        lookupPfktr(pfktrInput.value);
    });

    btnOpenConfirmModal.addEventListener('click', function () {
        if (!currentTraveler || !currentTraveler.is_eligible_for_stage) return;
        const effQty = Number(currentTraveler.effective_input_qty ?? currentTraveler.qty_transferred ?? 0);
        document.getElementById('confirmPfktr').textContent = currentTraveler.traveler_number;
        document.getElementById('confirmLine').textContent = currentTraveler.line_label || 'Unassigned';
        document.getElementById('confirmEffectiveQty').textContent = effQty.toLocaleString('id-ID') + ' PCS';
        confirmScanModal.classList.remove('hidden');
    });

    btnCancelConfirmModal.addEventListener('click', function () {
        confirmScanModal.classList.add('hidden');
    });

    btnCancelTraveler.addEventListener('click', function () {
        confirmScanModal.classList.add('hidden');
        travelerCard.classList.add('hidden');
        hideFeedback();
        currentTraveler = null;
        pfktrInput.value = '';
        pfktrInput.focus();
    });

    btnConfirm.addEventListener('click', async function () {
        if (!currentTraveler || btnConfirm.disabled) return;

        btnConfirm.disabled = true;
        const originalHtml = btnConfirm.innerHTML;
        btnConfirm.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Memproses...</span>';

        try {
            const res = await fetch(EXECUTE_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN,
                },
                body: JSON.stringify({
                    traveler_number: currentTraveler.traveler_number,
                    notes: notesInput.value || null,
                }),
            });

            const json = await res.json();
            confirmScanModal.classList.add('hidden');

            if (!res.ok || !json.success) {
                showFeedback('error', json.message || 'Gagal mengeksekusi scan.');
                btnConfirm.disabled = false;
                btnConfirm.innerHTML = originalHtml;
                return;
            }

            showFeedback('success', json.message);
            if (json.data && json.data.traveler) {
                renderTravelerCard(json.data.traveler);
            }
            pfktrInput.value = '';
            pfktrInput.focus();
        } catch (err) {
            confirmScanModal.classList.add('hidden');
            showFeedback('error', 'Koneksi terputus: ' + err.message);
        } finally {
            btnConfirm.innerHTML = originalHtml;
        }
    });

    document.getElementById('btnResetScanner').addEventListener('click', function () {
        travelerCard.classList.add('hidden');
        hideFeedback();
        currentTraveler = null;
        pfktrInput.value = '';
        pfktrInput.focus();
    });

    // Heat Number Fallback Modal (ONLY selects specific PFKTR)
    const heatModal = document.getElementById('heatModal');
    const heatInput = document.getElementById('heatSearchInput');
    const heatResultArea = document.getElementById('heatSearchResultArea');

    document.getElementById('btnOpenHeatModal').addEventListener('click', function () {
        heatModal.classList.remove('hidden');
        heatInput.focus();
    });
    document.getElementById('btnCloseHeatModal').addEventListener('click', function () {
        heatModal.classList.add('hidden');
    });

    document.getElementById('heatSearchForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const heat = heatInput.value.trim();
        if (!heat) return;

        heatResultArea.innerHTML = '<p class="text-slate-500 text-center py-4"><i class="fas fa-spinner fa-spin mr-1"></i> Mencari traveler...</p>';

        try {
            const url = LOOKUP_HEAT_URL + '/' + encodeURIComponent(heat) + '?stage=' + encodeURIComponent(STAGE);
            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            const json = await res.json();

            if (!res.ok || !json.success || !Array.isArray(json.data)) {
                heatResultArea.innerHTML = '<div class="p-3 bg-rose-50 text-rose-700 rounded-xl border border-rose-200 font-semibold">' + escapeHtml(json.message || 'Tidak ditemukan.') + '</div>';
                return;
            }

            heatResultArea.innerHTML = json.data.map((t) => {
                const badge = t.is_eligible_for_stage
                    ? '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">Siap Scan ' + escapeHtml(STAGE_LABEL) + '</span>'
                    : '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600">' + escapeHtml(t.current_stage_label) + '</span>';

                return '<button type="button" data-pfktr="' + escapeHtml(t.traveler_number) + '" class="heat-pick-btn w-full text-left p-3 rounded-xl border border-slate-200 hover:border-emerald-400 hover:bg-emerald-50/40 transition-colors">' +
                    '<div class="flex items-center justify-between gap-2">' +
                        '<span class="font-mono font-black text-slate-900">' + escapeHtml(t.traveler_number) + '</span>' +
                        badge +
                    '</div>' +
                    '<div class="text-[11px] text-slate-600 mt-1">' + escapeHtml(t.production_code) + ' &bull; ' + escapeHtml(t.item_name) + ' &bull; <strong>' + Number(t.effective_input_qty ?? t.qty_transferred).toLocaleString('id-ID') + ' PCS</strong> (' + escapeHtml(t.line_label) + ')</div>' +
                '</button>';
            }).join('');

            heatResultArea.querySelectorAll('.heat-pick-btn').forEach((btn) => {
                btn.addEventListener('click', function () {
                    const chosen = this.getAttribute('data-pfktr');
                    heatModal.classList.add('hidden');
                    pfktrInput.value = chosen;
                    lookupPfktr(chosen);
                });
            });
        } catch (err) {
            heatResultArea.innerHTML = '<div class="p-3 bg-rose-50 text-rose-700 rounded-xl">Error: ' + escapeHtml(err.message) + '</div>';
        }
    });

    // Camera BarcodeDetector Scanner
    const cameraModal = document.getElementById('cameraModal');
    const cameraVideo = document.getElementById('cameraVideo');

    function stopCamera() {
        scanLoopActive = false;
        if (cameraStream) {
            cameraStream.getTracks().forEach((tr) => tr.stop());
            cameraStream = null;
        }
        cameraModal.classList.add('hidden');
    }

    document.getElementById('btnCloseCamera').addEventListener('click', stopCamera);
    document.getElementById('btnCancelCamera').addEventListener('click', stopCamera);

    document.getElementById('btnOpenScanCamera').addEventListener('click', async function () {
        if (!('BarcodeDetector' in window)) {
            showFeedback('error', 'Browser perangkat ini belum mendukung kamera BarcodeDetector. Gunakan scanner gun atau ketik PFKTR manual.');
            return;
        }

        try {
            cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
            cameraVideo.srcObject = cameraStream;
            cameraModal.classList.remove('hidden');
            scanLoopActive = true;

            const detector = new BarcodeDetector({ formats: ['qr_code', 'code_128'] });
            async function tick() {
                if (!scanLoopActive) return;
                try {
                    const codes = await detector.detect(cameraVideo);
                    if (codes.length > 0 && codes[0].rawValue) {
                        const raw = codes[0].rawValue.trim();
                        stopCamera();
                        pfktrInput.value = raw;
                        lookupPfktr(raw);
                        return;
                    }
                } catch (_) {}
                requestAnimationFrame(tick);
            }
            requestAnimationFrame(tick);
        } catch (err) {
            showFeedback('error', 'Tidak dapat membuka kamera: ' + err.message);
        }
    });
})();
</script>
@endsection
