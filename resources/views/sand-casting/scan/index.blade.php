@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">SCANNER PRODUKSI &mdash; {{ $stageLabel }}</h1>
            <p class="text-gray-500 text-[10px]">SCANNER OPERASIONAL &bull; Pindai Barcode KTR untuk konfirmasi penyelesaian fisik tahap {{ $stageLabel }}</p>
        </div>
        <div>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-emerald-100 text-emerald-800 border border-emerald-300 shadow-sm">
                <i class="fas fa-layer-group mr-1.5 text-emerald-600"></i> {{ $stageLabel }}
            </span>
        </div>
    </div>
@endsection

@section('content')
<div class="max-w-xl mx-auto px-2 sm:px-4 py-3 space-y-4">

    {{-- 1. STAGE CONTEXT BANNER --}}
    <div class="bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 rounded-2xl p-4 text-white shadow-md border border-slate-700 flex items-center justify-between">
        <div>
            <span class="text-[11px] uppercase tracking-widest text-slate-400 font-semibold block">Stasiun Kerja Sand Casting</span>
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

    {{-- 2. MAIN SCANNER ACTION CARD (INITIAL / IDLE STATE) --}}
    <div id="scannerActionCard" class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5 text-center space-y-4">
        <div class="py-3">
            <div class="w-16 h-16 bg-emerald-50 text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-3 shadow-inner text-2xl">
                <i class="fas fa-barcode"></i>
            </div>
            <h2 class="text-lg font-bold text-slate-800">Scan Barcode KTR</h2>
            <p class="text-slate-500 text-xs mt-1">Gunakan kamera HP atau barcode scanner fisik</p>
        </div>

        {{-- Big Touch Action Buttons --}}
        <div class="grid grid-cols-1 gap-3">
            <button
                type="button"
                id="btnOpenScanCamera"
                class="w-full min-h-[54px] bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-black rounded-xl shadow-md transition-all flex items-center justify-center gap-2.5 text-base touch-manipulation">
                <i class="fas fa-camera text-lg"></i>
                <span>BUKA KAMERA SCANNER</span>
            </button>

            <button
                type="button"
                id="btnOpenHeatModal"
                class="w-full min-h-[46px] bg-amber-50 hover:bg-amber-100 text-amber-900 border border-amber-300 font-bold rounded-xl transition-all flex items-center justify-center gap-2 text-xs touch-manipulation">
                <i class="fas fa-search text-amber-700"></i>
                <span>BARCODE TIDAK TERBACA / CARI HEAT</span>
            </button>
        </div>

        {{-- Direct Input (Hardware gun / Manual Typing) --}}
        <div class="pt-3 border-t border-slate-100">
            <form id="manualKtrForm" class="flex gap-2" autocomplete="off">
                <input
                    type="text"
                    id="manualKtrInput"
                    name="traveler_number"
                    placeholder="KTR-YYYYMMDD-XXXX"
                    class="flex-1 min-h-[46px] px-3.5 text-center font-mono font-bold text-sm tracking-wider uppercase border-2 border-slate-300 rounded-xl focus:border-emerald-500 focus:ring-emerald-500 outline-none"
                    autocomplete="off"
                >
                <button
                    type="submit"
                    class="min-h-[46px] px-5 bg-slate-900 hover:bg-black text-white font-bold rounded-xl text-sm transition-colors touch-manipulation shadow-sm">
                    CARI
                </button>
            </form>
        </div>
    </div>

    {{-- 3. CAMERA SCANNER MODAL / OVERLAY --}}
    <div id="cameraModal" class="fixed inset-0 z-50 bg-black/90 flex flex-col items-center justify-between p-4 hidden">
        <div class="w-full max-w-md flex items-center justify-between text-white pt-2">
            <div class="flex items-center gap-2">
                <i class="fas fa-camera text-emerald-400"></i>
                <span class="font-bold text-sm">Scanner Kamera &mdash; {{ $stageLabel }}</span>
            </div>
            <button type="button" id="btnCloseCamera" class="w-10 h-10 rounded-full bg-white/20 text-white flex items-center justify-center text-lg touch-manipulation">
                <i class="fas fa-times"></i>
            </button>
        </div>

        {{-- Camera Viewport --}}
        <div class="relative w-full max-w-md flex-1 my-4 flex items-center justify-center overflow-hidden rounded-2xl bg-black border border-slate-800">
            <video id="cameraVideo" playsinline autoplay muted class="w-full h-full object-cover"></video>

            {{-- Scanning Reticle Overlay --}}
            <div class="absolute inset-0 pointer-events-none flex flex-col items-center justify-center p-6">
                <div class="w-64 h-40 border-2 border-emerald-400 rounded-xl relative shadow-[0_0_0_9999px_rgba(0,0,0,0.5)]">
                    <div class="absolute top-0 left-0 w-4 h-4 border-t-4 border-l-4 border-emerald-400 -mt-1 -ml-1"></div>
                    <div class="absolute top-0 right-0 w-4 h-4 border-t-4 border-r-4 border-emerald-400 -mt-1 -mr-1"></div>
                    <div class="absolute bottom-0 left-0 w-4 h-4 border-b-4 border-l-4 border-emerald-400 -mb-1 -ml-1"></div>
                    <div class="absolute bottom-0 right-0 w-4 h-4 border-b-4 border-r-4 border-emerald-400 -mb-1 -mr-1"></div>
                    {{-- Laser Scan Line --}}
                    <div class="w-full h-0.5 bg-emerald-400 shadow-[0_0_8px_#34d399] animate-pulse my-auto absolute inset-0"></div>
                </div>
                <p class="text-white text-xs font-semibold bg-black/70 px-3 py-1 rounded-full mt-4 backdrop-blur-sm">
                    Arahkan barcode KTR ke dalam kotak
                </p>
            </div>
        </div>

        {{-- Camera Footer --}}
        <div class="w-full max-w-md pb-4 text-center">
            <button type="button" id="btnCancelCamera" class="w-full min-h-[48px] bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl text-sm touch-manipulation">
                TUTUP KAMERA
            </button>
        </div>
    </div>

    {{-- 4. MANUAL HEAT SEARCH MODAL --}}
    <div id="heatModal" class="fixed inset-0 z-50 bg-black/60 flex items-end sm:items-center justify-center p-2 sm:p-4 hidden">
        <div class="bg-white w-full max-w-md rounded-t-3xl sm:rounded-2xl shadow-xl max-h-[90vh] flex flex-col overflow-hidden animate-in fade-in slide-in-from-bottom duration-200">
            <div class="p-4 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-bold text-slate-800">Cari Berdasarkan Heat Number</h3>
                    <p class="text-xs text-slate-500">Pilih KTR kandidat dari nomor tuangan dapur</p>
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
                        placeholder="Contoh: A214092001"
                        class="flex-1 min-h-[44px] px-3.5 uppercase font-mono font-bold text-sm border-2 border-slate-300 rounded-xl focus:border-amber-500 focus:ring-amber-500 outline-none"
                    >
                    <button
                        type="submit"
                        id="btnSubmitHeatSearch"
                        class="min-h-[44px] px-4 bg-amber-600 hover:bg-amber-700 text-white font-bold rounded-xl text-sm touch-manipulation">
                        CARI
                    </button>
                </form>

                <div id="heatSearchResultArea" class="space-y-2 overflow-y-auto max-h-64 pt-2">
                    {{-- Dynamically populated KTR Candidates --}}
                </div>
            </div>
        </div>
    </div>

    {{-- 5. KTR IDENTITY CARD & PHYSICAL EXECUTION ACTION --}}
    <div id="ktrDetailCard" class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden hidden space-y-0">
        {{-- Card Header --}}
        <div class="bg-slate-900 text-white p-4 flex items-center justify-between">
            <div>
                <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Identitas Traveler KTR</span>
                <div id="cardTravelerNumber" class="text-xl font-mono font-black tracking-wider text-emerald-400">KTR-XXXX</div>
            </div>
            <div id="cardUrgentBadge" class="hidden">
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-black bg-red-600 text-white animate-pulse shadow-sm">
                    <i class="fas fa-exclamation-triangle mr-1"></i> URGENT
                </span>
            </div>
        </div>

        {{-- Details Grid --}}
        <div class="p-4 bg-slate-50/70 border-b border-slate-200 grid grid-cols-2 gap-3 text-xs">
            <div>
                <span class="text-slate-500 block text-[11px]">Heat Number</span>
                <span id="cardHeatNumber" class="font-black text-slate-800 font-mono text-sm">-</span>
            </div>
            <div>
                <span class="text-slate-500 block text-[11px]">Kode Produksi</span>
                <span id="cardProductionCode" class="font-black text-slate-800 font-mono text-sm">-</span>
            </div>
            <div class="col-span-2">
                <span class="text-slate-500 block text-[11px]">Nama Produk / Item</span>
                <span id="cardItemName" class="font-black text-slate-900 text-sm">-</span>
            </div>
            <div>
                <span class="text-slate-500 block text-[11px]">Customer</span>
                <span id="cardCustomer" class="font-bold text-slate-700">-</span>
            </div>
            <div>
                <span class="text-slate-500 block text-[11px]">Tgl Cor / Shift</span>
                <span id="cardCastInfo" class="font-bold text-slate-700">-</span>
            </div>
        </div>

        {{-- Active Checkpoint & Input Qty Section --}}
        <div class="p-4 bg-emerald-50/50 border-b border-emerald-100 flex items-center justify-between">
            <div>
                <span class="text-xs text-slate-500 font-medium block">Kuantitas Masuk (Input Server)</span>
                <span id="cardInputQty" class="text-3xl font-black text-emerald-700">0 <span class="text-sm font-bold text-emerald-800">PCS</span></span>
            </div>
            <div class="text-right">
                <span class="text-xs text-slate-500 font-medium block mb-1">Checkpoint Aktif</span>
                <span id="cardActiveCheckpointBadge" class="inline-block font-mono font-black text-xs px-3 py-1 rounded-full bg-emerald-100 text-emerald-900 border border-emerald-300 uppercase shadow-sm">
                    -
                </span>
            </div>
        </div>

        {{-- State Mismatch Alert (If invalid for user stage) --}}
        <div id="stageMismatchAlert" class="p-4 bg-amber-50 border-b border-amber-200 text-amber-900 text-xs hidden">
            <div class="flex items-start gap-2.5">
                <i class="fas fa-exclamation-circle text-amber-600 text-base mt-0.5"></i>
                <div id="stageMismatchMessage" class="font-semibold leading-relaxed">
                    KTR ini tidak dapat diproses di stasiun {{ $stageLabel }}.
                </div>
            </div>
        </div>

        {{-- Lifecycle Status Alert (If already WAITING_DEFECT / WAITING_QC / CONFIRMED) --}}
        <div id="statusAlert" class="p-4 bg-blue-50 border-b border-blue-200 text-blue-900 text-xs hidden">
            <div class="flex items-start gap-2.5">
                <i class="fas fa-info-circle text-blue-600 text-base mt-0.5"></i>
                <div id="statusAlertMessage" class="font-semibold leading-relaxed">
                    Status KTR saat ini.
                </div>
            </div>
        </div>

        {{-- Halted State Alert (If input = 0 / halted) --}}
        <div id="haltedAlert" class="p-4 bg-red-50 border-b border-red-200 text-red-900 text-xs hidden">
            <div class="flex items-start gap-2.5">
                <i class="fas fa-ban text-red-600 text-base mt-0.5"></i>
                <div>
                    <div class="font-bold text-sm text-red-800">⚠ KTR TERHENTI (HALTED)</div>
                    <p class="mt-1 text-red-700 leading-snug">Tidak ada kuantitas bagus yang tersedia untuk proses berikutnya. Hubungi Supervisor / Admin.</p>
                </div>
            </div>
        </div>

        {{-- 6. PRIMARY PHYSICAL COMPLETION ACTION SECTION (Visible only if READY) --}}
        <div id="executionFormSection" class="p-4 space-y-4">
            <div>
                <label for="executionNotesInput" class="block text-xs font-bold text-slate-700 mb-1">
                    Catatan Proses Fisik (Opsional)
                </label>
                <textarea
                    id="executionNotesInput"
                    rows="2"
                    placeholder="Contoh: Selesai potong netto, mata pisau baru..."
                    class="w-full text-xs p-3 border border-slate-300 rounded-xl focus:border-emerald-500 focus:ring-emerald-500 outline-none"
                ></textarea>
            </div>

            {{-- Action Buttons --}}
            <div class="grid grid-cols-2 gap-2.5 pt-1">
                <button
                    type="button"
                    id="btnCancelKtr"
                    class="min-h-[50px] bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-sm transition-colors touch-manipulation">
                    BATAL
                </button>
                <button
                    type="button"
                    id="btnProceedConfirmation"
                    class="min-h-[50px] bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-black rounded-xl text-sm shadow-md transition-colors touch-manipulation flex items-center justify-center gap-1.5">
                    <i class="fas fa-check-circle text-base"></i>
                    <span>SELESAI PROSES</span>
                </button>
            </div>
        </div>

        {{-- Reset button if invalid/halted/in-progress --}}
        <div id="invalidKtrActionSection" class="p-4 hidden">
            <button
                type="button"
                id="btnResetInvalidKtr"
                class="w-full min-h-[48px] bg-slate-900 hover:bg-black text-white font-bold rounded-xl text-sm touch-manipulation shadow-sm">
                SCAN KTR LAIN
            </button>
        </div>
    </div>

    {{-- 7. PRE-EXECUTION CONFIRMATION MODAL --}}
    <div id="confirmModal" class="fixed inset-0 z-50 bg-black/75 flex items-end sm:items-center justify-center p-3 hidden">
        <div class="bg-white w-full max-w-sm rounded-3xl p-5 space-y-4 shadow-2xl animate-in fade-in zoom-in-95 duration-150">
            <div class="text-center">
                <div class="w-12 h-12 bg-emerald-100 text-emerald-700 rounded-full flex items-center justify-center mx-auto mb-2 text-xl shadow-inner">
                    <i class="fas fa-clipboard-check"></i>
                </div>
                <h3 class="text-base font-black text-slate-900">Konfirmasi Selesai Fisik</h3>
                <p class="text-xs text-slate-500 mt-0.5">Pastikan proses fisik pada tahap ini sudah selesai.</p>
            </div>

            <div class="bg-slate-50 rounded-2xl p-4 border border-slate-200 space-y-2.5 text-xs">
                <div class="flex justify-between items-center">
                    <span class="text-slate-500 font-medium">Nomor KTR:</span>
                    <span id="confirmKtr" class="font-mono font-black text-slate-900 text-sm">KTR-XXXX</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-500 font-medium">Proses / Tahap:</span>
                    <span class="font-black text-slate-900 uppercase">{{ $stageLabel }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-500 font-medium">Checkpoint:</span>
                    <span id="confirmCheckpoint" class="font-mono font-bold text-emerald-800 bg-emerald-100 px-2 py-0.5 rounded text-[11px] uppercase">-</span>
                </div>
                <div class="flex justify-between items-center pt-2 border-t border-slate-200">
                    <span class="text-slate-700 font-bold">Qty Masuk:</span>
                    <span id="confirmInputQty" class="font-black text-slate-900 text-sm">0 PCS</span>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2.5 pt-1">
                <button
                    type="button"
                    id="btnBackToForm"
                    class="min-h-[48px] bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-sm touch-manipulation">
                    BATAL
                </button>
                <button
                    type="button"
                    id="btnExecuteSubmit"
                    class="min-h-[48px] bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-black rounded-xl text-sm shadow-md transition-all touch-manipulation flex items-center justify-center gap-2">
                    <span id="btnExecuteSubmitText">SELESAI</span>
                </button>
            </div>
        </div>
    </div>

    {{-- 8. EXECUTION SUCCESS CARD (WAITING_DEFECT STATE) --}}
    <div id="successCard" class="bg-white rounded-2xl shadow-sm border border-emerald-200 p-6 text-center space-y-4 hidden">
        <div class="w-16 h-16 bg-emerald-100 text-emerald-700 rounded-full flex items-center justify-center mx-auto text-2xl shadow-inner">
            <i class="fas fa-check-double"></i>
        </div>
        <div>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-[11px] font-black uppercase tracking-wider bg-amber-100 text-amber-900 border border-amber-300 mb-1">
                <i class="fas fa-clock mr-1.5 text-amber-700"></i> WAITING DEFECT
            </span>
            <h3 class="text-xl font-black text-slate-900 mt-1">Proses Fisik Selesai</h3>
            <p id="successTravelerNum" class="font-mono font-bold text-sm text-slate-600 mt-0.5">KTR-XXXX</p>
        </div>

        <div class="bg-slate-50 rounded-2xl p-4 border border-slate-200 text-xs space-y-2 text-left">
            <div class="flex justify-between items-center">
                <span class="text-slate-500">Tahap:</span>
                <span class="font-black text-slate-800 uppercase">{{ $stageLabel }}</span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-slate-500">Checkpoint:</span>
                <span id="successCheckpoint" class="font-mono font-bold text-slate-800">-</span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-slate-500">Qty Masuk:</span>
                <span id="successInputQty" class="font-bold text-slate-800">0 PCS</span>
            </div>
            <div class="pt-2 border-t border-slate-200 text-amber-800 text-[11px] leading-relaxed">
                <i class="fas fa-info-circle mr-1 text-amber-600"></i>
                KTR ini kini <b>menunggu input defect</b> oleh Admin PPIC sebelum dilanjutkan ke verifikasi QC.
            </div>
        </div>

        <button
            type="button"
            id="btnResetScanner"
            class="w-full min-h-[52px] bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-black rounded-xl text-base shadow-md transition-colors touch-manipulation">
            SCAN KTR BERIKUTNYA
        </button>
    </div>

</div>

{{-- 9. OPERATIONAL STAGE SCANNER JAVASCRIPT ENGINE --}}
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Stage Context Configuration
    const CONTEXT = {
        stage: '{{ $stage }}',
        stageSlug: '{{ $stageSlug }}',
        stageLabel: '{{ $stageLabel }}',
        executeUrl: '{{ $executeUrl }}',
        lookupKtrUrl: '{{ $lookupKtrUrl }}',
        lookupHeatUrl: '{{ $lookupHeatUrl }}',
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
    };

    // State Holders
    let currentKtr = null;
    let cameraStream = null;
    let isScanning = false;
    let isSubmitting = false;

    // DOM Elements
    const scannerActionCard = document.getElementById('scannerActionCard');
    const manualKtrForm = document.getElementById('manualKtrForm');
    const manualKtrInput = document.getElementById('manualKtrInput');

    // Camera Elements
    const btnOpenScanCamera = document.getElementById('btnOpenScanCamera');
    const cameraModal = document.getElementById('cameraModal');
    const cameraVideo = document.getElementById('cameraVideo');
    const btnCloseCamera = document.getElementById('btnCloseCamera');
    const btnCancelCamera = document.getElementById('btnCancelCamera');

    // Heat Fallback Elements
    const btnOpenHeatModal = document.getElementById('btnOpenHeatModal');
    const heatModal = document.getElementById('heatModal');
    const btnCloseHeatModal = document.getElementById('btnCloseHeatModal');
    const heatSearchForm = document.getElementById('heatSearchForm');
    const heatSearchInput = document.getElementById('heatSearchInput');
    const heatSearchResultArea = document.getElementById('heatSearchResultArea');

    // KTR Detail & Form Elements
    const ktrDetailCard = document.getElementById('ktrDetailCard');
    const cardTravelerNumber = document.getElementById('cardTravelerNumber');
    const cardUrgentBadge = document.getElementById('cardUrgentBadge');
    const cardHeatNumber = document.getElementById('cardHeatNumber');
    const cardProductionCode = document.getElementById('cardProductionCode');
    const cardItemName = document.getElementById('cardItemName');
    const cardCustomer = document.getElementById('cardCustomer');
    const cardCastInfo = document.getElementById('cardCastInfo');
    const cardInputQty = document.getElementById('cardInputQty');
    const cardActiveCheckpointBadge = document.getElementById('cardActiveCheckpointBadge');
    const stageMismatchAlert = document.getElementById('stageMismatchAlert');
    const stageMismatchMessage = document.getElementById('stageMismatchMessage');
    const statusAlert = document.getElementById('statusAlert');
    const statusAlertMessage = document.getElementById('statusAlertMessage');
    const haltedAlert = document.getElementById('haltedAlert');
    const executionFormSection = document.getElementById('executionFormSection');
    const invalidKtrActionSection = document.getElementById('invalidKtrActionSection');
    const btnResetInvalidKtr = document.getElementById('btnResetInvalidKtr');
    const executionNotesInput = document.getElementById('executionNotesInput');
    const btnCancelKtr = document.getElementById('btnCancelKtr');
    const btnProceedConfirmation = document.getElementById('btnProceedConfirmation');

    // Confirmation Elements
    const confirmModal = document.getElementById('confirmModal');
    const confirmKtr = document.getElementById('confirmKtr');
    const confirmCheckpoint = document.getElementById('confirmCheckpoint');
    const confirmInputQty = document.getElementById('confirmInputQty');
    const btnBackToForm = document.getElementById('btnBackToForm');
    const btnExecuteSubmit = document.getElementById('btnExecuteSubmit');
    const btnExecuteSubmitText = document.getElementById('btnExecuteSubmitText');

    // Success Elements
    const successCard = document.getElementById('successCard');
    const successTravelerNum = document.getElementById('successTravelerNum');
    const successCheckpoint = document.getElementById('successCheckpoint');
    const successInputQty = document.getElementById('successInputQty');
    const btnResetScanner = document.getElementById('btnResetScanner');

    // ==========================================
    // 1. LOOKUP KTR BY BARCODE
    // ==========================================
    async function lookupKtr(travelerNumber) {
        const cleaned = travelerNumber.trim().toUpperCase();
        if (!cleaned) return;

        showLoading('Memuat Data KTR...');

        try {
            const url = `${CONTEXT.lookupKtrUrl}/${encodeURIComponent(cleaned)}`;
            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const result = await response.json();
            Swal.close();

            if (!response.ok || !result.success || !result.data) {
                Swal.fire({
                    icon: 'error',
                    title: 'KTR Tidak Ditemukan',
                    text: result.message || `Nomor KTR '${cleaned}' tidak ditemukan di sistem.`,
                    confirmButtonColor: '#059669',
                });
                return;
            }

            renderKtrCard(result.data);
        } catch (error) {
            Swal.close();
            Swal.fire({
                icon: 'error',
                title: 'Gangguan Jaringan',
                text: 'Gagal terhubung ke server. Silakan periksa koneksi Anda.',
                confirmButtonColor: '#059669',
            });
        }
    }

    // ==========================================
    // 2. RENDER KTR DETAILS & VALIDATE STATE
    // ==========================================
    function renderKtrCard(data) {
        currentKtr = data;

        // Hide idle & success cards
        scannerActionCard.classList.add('hidden');
        successCard.classList.add('hidden');
        ktrDetailCard.classList.remove('hidden');

        // Populate Identitas
        cardTravelerNumber.textContent = data.traveler_number;
        cardHeatNumber.textContent = data.heat_number || '-';
        cardProductionCode.textContent = data.production_code || '-';
        cardItemName.textContent = data.item_name || (data.item_code || '-');
        cardCustomer.textContent = data.customer || '-';
        cardCastInfo.textContent = `${data.cast_date || '-'} / Shift ${data.shift || '-'}`;
        cardInputQty.innerHTML = `${data.current_input_qty ?? 0} <span class="text-sm font-bold text-emerald-800">PCS</span>`;
        cardActiveCheckpointBadge.textContent = data.active_checkpoint || '-';

        if (data.is_urgent) {
            cardUrgentBadge.classList.remove('hidden');
        } else {
            cardUrgentBadge.classList.add('hidden');
        }

        // Reset Inputs
        executionNotesInput.value = '';

        // Operational & Stage State Evaluation
        const isCurrentStageMatch = (data.current_stage === CONTEXT.stage);
        const stageLabel = (data.current_stage || 'NO STAGE').replace('_', ' ').toUpperCase();
        const opStatus = data.operational_status;

        // Reset Alert & Form states
        stageMismatchAlert.classList.add('hidden');
        statusAlert.classList.add('hidden');
        haltedAlert.classList.add('hidden');
        executionFormSection.classList.add('hidden');
        invalidKtrActionSection.classList.add('hidden');

        if (!data.current_stage) {
            stageMismatchAlert.classList.remove('hidden');
            stageMismatchMessage.textContent = `Traveler ini belum memiliki operational stage dan belum dapat diproses.`;
            invalidKtrActionSection.classList.remove('hidden');
        } else if (data.current_stage === 'completed') {
            stageMismatchAlert.classList.remove('hidden');
            stageMismatchMessage.textContent = `KTR ${data.traveler_number} sudah selesai diproses (Completed).`;
            invalidKtrActionSection.classList.remove('hidden');
        } else if (!isCurrentStageMatch) {
            stageMismatchAlert.classList.remove('hidden');
            stageMismatchMessage.textContent = `KTR saat ini berada di stage ${stageLabel}. Tahap yang valid untuk stasiun ini adalah ${CONTEXT.stageLabel}.`;
            invalidKtrActionSection.classList.remove('hidden');
        } else if (opStatus === 'HALTED' || (data.current_input_qty !== null && data.current_input_qty <= 0)) {
            haltedAlert.classList.remove('hidden');
            invalidKtrActionSection.classList.remove('hidden');
        } else if (opStatus === 'WAITING_DEFECT') {
            statusAlert.classList.remove('hidden');
            statusAlertMessage.textContent = `KTR sudah selesai fisik pada checkpoint ${data.active_checkpoint} dan sedang menunggu input defect oleh Admin PPIC.`;
            invalidKtrActionSection.classList.remove('hidden');
        } else if (opStatus === 'WAITING_QC') {
            statusAlert.classList.remove('hidden');
            statusAlertMessage.textContent = `KTR sedang menunggu verifikasi klasifikasi defect oleh QC Inspector.`;
            invalidKtrActionSection.classList.remove('hidden');
        } else if (opStatus === 'CONFIRMED') {
            statusAlert.classList.remove('hidden');
            statusAlertMessage.textContent = `Eksekusi checkpoint ini sudah terkonfirmasi.`;
            invalidKtrActionSection.classList.remove('hidden');
        } else if (opStatus === 'READY') {
            // Authorized and Ready for Physical Done
            executionFormSection.classList.remove('hidden');
        } else {
            stageMismatchAlert.classList.remove('hidden');
            stageMismatchMessage.textContent = `Status KTR (${opStatus}) tidak siap diproses fisik.`;
            invalidKtrActionSection.classList.remove('hidden');
        }
    }

    // ==========================================
    // 3. CONFIRMATION MODAL & EXECUTION SUBMIT
    // ==========================================
    btnProceedConfirmation.addEventListener('click', function () {
        if (!currentKtr) return;
        const inputQty = parseInt(currentKtr.current_input_qty || 0, 10);

        confirmKtr.textContent = currentKtr.traveler_number;
        confirmCheckpoint.textContent = currentKtr.active_checkpoint || CONTEXT.stageLabel;
        confirmInputQty.textContent = `${inputQty} PCS`;

        confirmModal.classList.remove('hidden');
    });

    btnBackToForm.addEventListener('click', function () {
        confirmModal.classList.add('hidden');
    });

    btnExecuteSubmit.addEventListener('click', async function () {
        if (!currentKtr || isSubmitting) return;

        const notes = executionNotesInput.value.trim();

        // Concurrency Guard: Disable button immediately
        isSubmitting = true;
        btnExecuteSubmit.disabled = true;
        btnExecuteSubmitText.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> MEMPROSES...';

        try {
            const response = await fetch(CONTEXT.executeUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CONTEXT.csrfToken,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    traveler_number: currentKtr.traveler_number,
                    notes: notes || null
                })
            });

            const result = await response.json();
            confirmModal.classList.add('hidden');

            if (!response.ok || !result.success) {
                Swal.fire({
                    icon: 'error',
                    title: response.status === 403 ? 'Akses Ditolak (403)' : 'Eksekusi Ditolak',
                    text: result.message || 'Gagal memproses tahap eksekusi.',
                    confirmButtonColor: '#059669',
                });
                return;
            }

            renderSuccess(result.data);
        } catch (error) {
            confirmModal.classList.add('hidden');
            Swal.fire({
                icon: 'error',
                title: 'Gangguan Sistem',
                text: 'Terjadi kesalahan saat memproses data. Silakan coba lagi.',
                confirmButtonColor: '#059669',
            });
        } finally {
            isSubmitting = false;
            btnExecuteSubmit.disabled = false;
            btnExecuteSubmitText.textContent = 'SELESAI';
        }
    });

    // ==========================================
    // 4. SUCCESS SCREEN RENDERING (WAITING_DEFECT)
    // ==========================================
    function renderSuccess(data) {
        ktrDetailCard.classList.add('hidden');
        successCard.classList.remove('hidden');

        successTravelerNum.textContent = data.traveler_number;
        successCheckpoint.textContent = data.checkpoint_code;
        successInputQty.textContent = `${data.input_qty} PCS`;
    }

    // ==========================================
    // 5. RESET SCANNER STATE
    // ==========================================
    function resetToIdle() {
        currentKtr = null;
        ktrDetailCard.classList.add('hidden');
        successCard.classList.add('hidden');
        confirmModal.classList.add('hidden');
        scannerActionCard.classList.remove('hidden');
        manualKtrInput.value = '';
        executionNotesInput.value = '';
    }

    btnCancelKtr.addEventListener('click', resetToIdle);
    btnResetInvalidKtr.addEventListener('click', resetToIdle);
    btnResetScanner.addEventListener('click', resetToIdle);

    // ==========================================
    // 6. MANUAL KTR INPUT FORM
    // ==========================================
    manualKtrForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const travelerNumber = manualKtrInput.value.trim();
        if (travelerNumber) {
            lookupKtr(travelerNumber);
        }
    });

    // ==========================================
    // 7. MANUAL HEAT NUMBER SEARCH & CANDIDATES
    // ==========================================
    btnOpenHeatModal.addEventListener('click', function () {
        heatSearchResultArea.innerHTML = '';
        heatSearchInput.value = '';
        heatModal.classList.remove('hidden');
        setTimeout(() => heatSearchInput.focus(), 150);
    });

    btnCloseHeatModal.addEventListener('click', function () {
        heatModal.classList.add('hidden');
    });

    heatSearchForm.addEventListener('submit', async function (e) {
        e.preventDefault();
        const heatNumber = heatSearchInput.value.trim().toUpperCase();
        if (!heatNumber) return;

        heatSearchResultArea.innerHTML = `
            <div class="text-center py-4 text-slate-500 text-xs">
                <i class="fas fa-spinner fa-spin mr-1"></i> Mencari KTR untuk Heat ${heatNumber}...
            </div>
        `;

        try {
            const url = `${CONTEXT.lookupHeatUrl}/${encodeURIComponent(heatNumber)}`;
            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const result = await response.json();

            if (!response.ok || !result.success || !Array.isArray(result.data) || result.data.length === 0) {
                heatSearchResultArea.innerHTML = `
                    <div class="text-center py-4 text-amber-700 bg-amber-50 rounded-xl text-xs border border-amber-200">
                        <i class="fas fa-exclamation-circle mr-1"></i> Tidak ada KTR ditemukan untuk Heat <b>${heatNumber}</b>.
                    </div>
                `;
                return;
            }

            let html = '';
            result.data.forEach(item => {
                const stageName = (item.current_stage || 'NO STAGE').replace('_', ' ').toUpperCase();
                const isCurrentStage = (item.current_stage === CONTEXT.stage);
                const isReady = (item.operational_status === 'READY');

                html += `
                    <div class="p-3 bg-slate-50 hover:bg-slate-100 rounded-xl border border-slate-200 flex items-center justify-between gap-2 transition-colors">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5">
                                <span class="font-mono font-bold text-slate-900 text-xs">${item.traveler_number}</span>
                                ${item.is_urgent ? '<span class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-red-600 text-white">URGENT</span>' : ''}
                            </div>
                            <div class="text-[11px] text-slate-600 truncate mt-0.5">${item.production_code || '-'} &bull; ${item.item_name || '-'}</div>
                            <div class="text-[10px] text-slate-500 mt-0.5">
                                Tahap: <b class="${isCurrentStage ? 'text-emerald-700' : 'text-slate-700'}">${stageName}</b> &bull; Qty: <b>${item.current_input_qty ?? 0} PCS</b>
                            </div>
                        </div>
                        <button
                            type="button"
                            data-ktr="${item.traveler_number}"
                            class="btn-select-candidate px-3 py-2 ${isCurrentStage && isReady ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : 'bg-slate-700 hover:bg-slate-800 text-white'} rounded-lg text-xs font-bold touch-manipulation">
                            PILIH
                        </button>
                    </div>
                `;
            });

            heatSearchResultArea.innerHTML = html;

            document.querySelectorAll('.btn-select-candidate').forEach(btn => {
                btn.addEventListener('click', function () {
                    const travelerNumber = this.getAttribute('data-ktr');
                    heatModal.classList.add('hidden');
                    lookupKtr(travelerNumber);
                });
            });
        } catch (error) {
            heatSearchResultArea.innerHTML = `
                <div class="text-center py-4 text-red-600 text-xs">
                    Gagal memuat data Heat. Periksa koneksi jaringan.
                </div>
            `;
        }
    });

    // ==========================================
    // 8. CAMERA BARCODE SCANNER ENGINE
    // ==========================================
    btnOpenScanCamera.addEventListener('click', async function () {
        cameraModal.classList.remove('hidden');
        await startCamera();
    });

    btnCloseCamera.addEventListener('click', stopCamera);
    btnCancelCamera.addEventListener('click', stopCamera);

    async function startCamera() {
        isScanning = true;

        try {
            const constraints = {
                video: {
                    facingMode: { ideal: 'environment' },
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                },
                audio: false
            };

            cameraStream = await navigator.mediaDevices.getUserMedia(constraints);
            cameraVideo.srcObject = cameraStream;
            await cameraVideo.play();

            if ('BarcodeDetector' in window) {
                const barcodeDetector = new BarcodeDetector({
                    formats: ['code_128', 'code_39', 'qr_code', 'ean_13']
                });
                detectBarcodeLoop(barcodeDetector);
            } else {
                console.warn('BarcodeDetector API is not supported in this browser.');
            }
        } catch (err) {
            console.error('Camera access error:', err);
            stopCamera();
            Swal.fire({
                icon: 'warning',
                title: 'Akses Kamera Tidak Tersedia',
                text: 'Pastikan izin kamera diizinkan dan koneksi menggunakan HTTPS. Anda tetap dapat menggunakan input manual atau pencarian Heat.',
                confirmButtonColor: '#059669',
            });
        }
    }

    async function detectBarcodeLoop(detector) {
        if (!isScanning) return;

        try {
            if (cameraVideo.readyState === cameraVideo.HAVE_ENOUGH_DATA) {
                const barcodes = await detector.detect(cameraVideo);
                if (barcodes.length > 0) {
                    const detectedValue = barcodes[0].rawValue.trim();
                    if (detectedValue) {
                        stopCamera();
                        lookupKtr(detectedValue);
                        return;
                    }
                }
            }
        } catch (e) {
            // Silently ignore detection loop hiccups
        }

        if (isScanning) {
            requestAnimationFrame(() => detectBarcodeLoop(detector));
        }
    }

    function stopCamera() {
        isScanning = false;
        if (cameraStream) {
            cameraStream.getTracks().forEach(track => track.stop());
            cameraStream = null;
        }
        cameraVideo.srcObject = null;
        cameraModal.classList.add('hidden');
    }

    window.addEventListener('beforeunload', stopCamera);
    window.addEventListener('pagehide', stopCamera);

    function showLoading(msg) {
        Swal.fire({
            title: msg,
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
    }
});
</script>
@endsection
