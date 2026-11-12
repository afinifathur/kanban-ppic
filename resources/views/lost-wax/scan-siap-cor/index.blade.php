@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full gap-2">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">LOST WAX &mdash; SCAN SELESAI DEWAXING</h1>
            <p class="text-gray-500 text-[10px]">Scan saat pohon keluar dari oven dan ditempatkan di rak buffer</p>
        </div>
        <div class="flex items-center gap-2">
            {{-- Tombol Navigasi Monitoring Dewaxing --}}
            <a
                href="{{ route('lost-wax.dewaxing-monitoring') }}"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all shadow-xs bg-indigo-600 hover:bg-indigo-700 text-white border border-indigo-700 cursor-pointer"
                title="Buka Halaman Monitoring Proses Dewaxing"
            >
                <i class="fas fa-desktop text-white text-xs"></i>
                <span class="hidden sm:inline">MONITORING PROSES DEWAXING</span>
                <span class="sm:hidden">MONITORING</span>
            </a>

            {{-- Audio Toggle Control --}}
            <button
                type="button"
                id="audioToggleBtn"
                onclick="toggleAudio()"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all shadow-xs bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-300 cursor-pointer"
                title="Aktifkan / Nonaktifkan Audio Feedback"
            >
                <i id="audioIcon" class="fas fa-volume-mute text-slate-500 text-xs"></i>
                <span id="audioText" class="hidden sm:inline">AKTIFKAN SUARA</span>
            </button>
        </div>
    </div>
@endsection

@section('content')
    <style>
        /* Full-page visual feedback transitions and overrides */
        body.scanner-feedback-success #app-main,
        body.scanner-feedback-success #app-main-header,
        body.scanner-feedback-success .scan-panel-card {
            background-color: #DCFCE7 !important;
            border-color: #86EFAC !important;
            transition: background-color 0.2s ease-in-out;
        }

        body.scanner-feedback-success #scanInput {
            background-color: #ffffff !important;
            border-color: #166534 !important;
            color: #166534 !important;
        }

        body.scanner-feedback-failed #app-main,
        body.scanner-feedback-failed #app-main-header,
        body.scanner-feedback-failed .scan-panel-card {
            background-color: #FEE2E2 !important;
            border-color: #FCA5A5 !important;
            transition: background-color 0.2s ease-in-out;
        }

        body.scanner-feedback-failed #scanInput {
            background-color: #ffffff !important;
            border-color: #991B1B !important;
            color: #991B1B !important;
        }
    </style>

    <div class="max-w-4xl mx-auto space-y-6">
        {{-- PROMINENT 10-METER DISTANCE FEEDBACK BANNER --}}
        <div id="fullFeedbackBanner" class="hidden rounded-2xl p-6 sm:p-8 text-center shadow-lg border-2 transition-all">
            <div id="feedbackIconWrapper" class="text-5xl sm:text-6xl mb-3">
                <i id="feedbackIcon" class="fas fa-check-circle"></i>
            </div>
            <div id="feedbackStatusTitle" class="text-3xl sm:text-4xl font-black tracking-tight mb-2 uppercase">
                BERHASIL &mdash; SELESAI DEWAXING
            </div>
            <div id="feedbackItemName" class="text-xl sm:text-2xl font-black tracking-tight mb-1">
                {{-- Scanned Item Name / Failure Reason --}}
            </div>
            <div id="feedbackSubDetail" class="text-base sm:text-lg font-mono font-bold opacity-90">
                {{-- Barcode / Code / Usable PCS --}}
            </div>
        </div>

        {{-- Scan Input Panel --}}
        <div id="scanPanelCard" class="scan-panel-card bg-white rounded-xl shadow-sm border border-slate-200 p-6 sm:p-8 text-center transition-colors">
            <div class="mb-4 sm:mb-6">
                <div id="statusIndicator" class="text-base sm:text-lg font-bold text-slate-600">
                    <i class="fas fa-qrcode mr-2 text-emerald-600"></i> SIAP SCAN SELESAI DEWAXING
                </div>
            </div>

            <form id="scanForm" autocomplete="off" class="mb-4">
                <input
                    type="text"
                    id="scanInput"
                    name="barcode"
                    class="w-full text-center text-xl sm:text-2xl font-mono tracking-widest py-3.5 sm:py-4 rounded-xl border-2 border-slate-300 focus:border-emerald-600 focus:ring-emerald-600 outline-none transition-colors"
                    placeholder="SCAN BARCODE / QR CODE TRAVELER"
                    autofocus
                    autocomplete="off"
                >
            </form>

            <div class="text-xs text-slate-400">
                Pindai QR Code atau Barcode Traveler Card pohon. Sistem akan menampilkan popup verifikasi sebelum menyelesaikan proses dewaxing.
            </div>

            <div id="resultArea" class="hidden space-y-4 mt-6"></div>
        </div>

        {{-- Last Tree Info Card --}}
        <div id="lastTreeInfo" class="bg-slate-900 text-white rounded-xl shadow-sm border border-slate-700 p-5 sm:p-6 hidden">
            <div class="flex items-center justify-between mb-4 border-b border-slate-800 pb-2">
                <h2 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Hasil Pemindaian Terakhir</h2>
                <span id="lastBadgeStatus" class="px-2.5 py-0.5 rounded text-xs font-bold font-mono"></span>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                <div>
                    <div class="text-slate-400 text-xs">Barcode</div>
                    <div id="lastBarcode" class="font-mono font-bold text-white text-base">-</div>
                    <div id="lastTreeNumber" class="text-[11px] text-slate-400">Pohon # -</div>
                </div>
                <div>
                    <div class="text-slate-400 text-xs">Kode Produksi</div>
                    <div id="lastCode" class="font-bold text-white text-base">-</div>
                    <div id="lastCustomer" class="text-[11px] text-slate-400">Cust: -</div>
                </div>
                <div>
                    <div class="text-slate-400 text-xs">Produk / Item</div>
                    <div id="lastProduct" class="font-bold text-white text-base truncate">-</div>
                    <div id="lastAisi" class="text-[11px] text-slate-400">AISI: -</div>
                </div>
                <div>
                    <div class="text-slate-400 text-xs">Kuantitas Siap Cor</div>
                    <div id="lastQuantity" class="font-black text-emerald-400 text-lg">-</div>
                    <div id="lastDefects" class="text-[11px] text-slate-400">Bruto: - | Cacat: -</div>
                </div>
            </div>
        </div>

        {{-- Error Area Alert --}}
        <div id="errorArea" class="hidden bg-red-50 border border-red-200 rounded-xl p-5 text-center">
            <div class="text-red-700 font-black text-lg mb-1 uppercase tracking-tight flex items-center justify-center gap-2">
                <i class="fas fa-times-circle"></i>
                <span id="errorTitle">SCAN GAGAL</span>
            </div>
            <div id="errorReason" class="text-red-800 font-bold text-sm"></div>
        </div>

        {{-- Riwayat 10 Pemindaian Terakhir --}}
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-3.5 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <i class="fas fa-history text-slate-500"></i>
                    <h3 class="font-bold text-slate-700 text-xs uppercase tracking-wider">Riwayat 10 Pemindaian Terakhir (Selesai Dewaxing)</h3>
                </div>
                <span class="text-[11px] text-slate-400">Pembaruan Real-Time</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-700" id="historyTable">
                    <thead class="bg-slate-100 text-slate-600 font-bold text-[11px] uppercase border-b border-slate-200">
                        <tr>
                            <th class="py-2.5 px-3 text-center w-12">Waktu</th>
                            <th class="py-2.5 px-3">Barcode</th>
                            <th class="py-2.5 px-3">Kode Produksi</th>
                            <th class="py-2.5 px-3">Nama Produk</th>
                            <th class="py-2.5 px-3 text-right">Qty Cangkang</th>
                            <th class="py-2.5 px-3 text-center">Status</th>
                            <th class="py-2.5 px-3">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100" id="historyTableBody">
                        @forelse($recentEvents as $ev)
                            @php
                                $tree = $ev->tree;
                                $isSuccess = $ev->result === 'success';
                                $code = $tree ? ($tree->getSourceCode() ?? '-') : '-';
                                $product = $tree ? ($tree->getSourceProduct() ?? '-') : '-';
                                $usableQty = $tree ? $tree->usable_quantity : '-';
                            @endphp
                            <tr class="hover:bg-slate-50">
                                <td class="py-2 px-3 text-center font-mono text-[11px] text-slate-500">{{ $ev->scanned_at ? $ev->scanned_at->format('H:i:s') : '-' }}</td>
                                <td class="py-2 px-3 font-mono font-bold text-slate-800">{{ $ev->barcode }}</td>
                                <td class="py-2 px-3 font-mono font-bold text-indigo-700">{{ $code }}</td>
                                <td class="py-2 px-3 truncate max-w-[200px]" title="{{ $product }}">{{ $product }}</td>
                                <td class="py-2 px-3 text-right font-black {{ $isSuccess ? 'text-emerald-700' : 'text-slate-400' }}">{{ is_numeric($usableQty) ? number_format($usableQty) . ' PCS' : '-' }}</td>
                                <td class="py-2 px-3 text-center">
                                    @if($isSuccess)
                                        <span class="inline-block px-2 py-0.5 rounded text-[10px] font-black bg-emerald-100 text-emerald-800 border border-emerald-300">BERHASIL</span>
                                    @else
                                        <span class="inline-block px-2 py-0.5 rounded text-[10px] font-black bg-rose-100 text-rose-800 border border-rose-300">GAGAL</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-[11px] {{ $isSuccess ? 'text-slate-500' : 'text-rose-600 font-bold' }}">
                                    {{ $isSuccess ? 'Selesai Dewaxing' : ($ev->anomaly_reason ?? 'Ditolak') }}
                                </td>
                            </tr>
                        @empty
                            <tr id="emptyHistoryRow">
                                <td colspan="7" class="py-8 text-center text-slate-400">
                                    Belum ada pemindaian Scan Selesai Dewaxing pada sesi ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- POPUP VERIFIKASI IDENTITAS BARANG SEBELUM KONFIRMASI (MODAL ANDROID-OPTIMIZED) --}}
    <div id="verifyModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/80 backdrop-blur-xs flex items-center justify-center p-3 sm:p-4">
        <div class="bg-white w-full max-w-lg rounded-2xl shadow-2xl border-2 border-emerald-500 overflow-hidden flex flex-col max-h-[92vh] animate-in fade-in zoom-in-95 duration-150">
            {{-- Modal Header --}}
            <div class="bg-gradient-to-r from-emerald-600 to-teal-700 px-5 py-4 text-white flex items-center justify-between shrink-0">
                <div class="flex items-center gap-2.5">
                    <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center text-lg">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div>
                        <h2 class="text-base font-black tracking-tight leading-tight uppercase">VERIFIKASI BARANG SELESAI DEWAXING</h2>
                        <p class="text-[11px] text-emerald-100 font-medium">Cocokkan label fisik pada pohon dengan data di bawah</p>
                    </div>
                </div>
                <button type="button" onclick="cancelVerification()" class="text-white/80 hover:text-white p-1.5 rounded-lg hover:bg-white/10 transition-colors" title="Batal & Tutup">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>

            {{-- Modal Body --}}
            <div class="p-4 sm:p-5 overflow-y-auto space-y-3.5">
                {{-- Primary Item Highlight Card --}}
                <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-2">
                    <div class="flex items-start justify-between gap-2">
                        <span class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">1. Nama Produk / Item:</span>
                        <span id="modalAisiBadge" class="px-2 py-0.5 rounded text-[10px] font-black bg-slate-200 text-slate-700 font-mono">AISI: -</span>
                    </div>
                    <div id="modalProductName" class="text-lg sm:text-xl font-black text-slate-900 leading-snug">
                        -
                    </div>
                    <div class="flex items-center gap-2 pt-1 border-t border-slate-200">
                        <span class="text-xs text-slate-500 font-semibold">2. Kode Produksi / Item:</span>
                        <span id="modalCode" class="text-sm font-black font-mono text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded border border-indigo-200">-</span>
                    </div>
                </div>

                {{-- Grid Details --}}
                <div class="grid grid-cols-2 gap-3">
                    {{-- Barcode Traveler --}}
                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-3">
                        <span class="text-[10px] font-bold text-slate-500 uppercase block mb-1">3. Barcode Traveler:</span>
                        <span id="modalBarcode" class="text-sm sm:text-base font-black font-mono text-slate-800 block break-all">-</span>
                    </div>

                    {{-- Customer --}}
                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-3">
                        <span class="text-[10px] font-bold text-slate-500 uppercase block mb-1">4. Customer:</span>
                        <span id="modalCustomer" class="text-xs sm:text-sm font-bold text-slate-800 block truncate" title="-">-</span>
                    </div>

                    {{-- Quantity PCS Aktual --}}
                    <div class="bg-emerald-50 border-2 border-emerald-300 rounded-xl p-3 col-span-1">
                        <span class="text-[10px] font-extrabold text-emerald-800 uppercase block mb-0.5">5. Jumlah PCS Aktual:</span>
                        <div class="flex items-baseline gap-1.5">
                            <span id="modalQuantityPcs" class="text-2xl sm:text-3xl font-black text-emerald-700">0</span>
                            <span class="text-xs font-bold text-emerald-800">PCS LAYAK</span>
                        </div>
                        <span id="modalDefectNote" class="text-[10px] text-emerald-700/80 block mt-0.5">Bruto: - | Cacat: -</span>
                    </div>

                    {{-- Lokasi Rak / Buffer --}}
                    <div class="bg-amber-50/70 border-2 border-amber-300 rounded-xl p-3 col-span-1">
                        <span class="text-[10px] font-extrabold text-amber-900 uppercase block mb-0.5">6. Asal Rak / Lokasi Buffer:</span>
                        <div class="flex items-center gap-1.5 mt-1">
                            <i class="fas fa-map-marker-alt text-amber-600 text-sm shrink-0"></i>
                            <span id="modalRackLocation" class="text-sm sm:text-base font-black text-amber-950">Belum ditentukan</span>
                        </div>
                    </div>
                </div>

                {{-- Warning / Attention Notice --}}
                <div class="bg-blue-50 border border-blue-200 rounded-xl p-3 flex items-start gap-2.5 text-xs text-blue-900">
                    <i class="fas fa-info-circle text-blue-600 text-sm mt-0.5 shrink-0"></i>
                    <div class="leading-relaxed">
                        Periksa apakah fisik pohon yang keluar dari oven sesuai dengan <strong>Nama Item</strong> dan <strong>Barcode</strong> di atas sebelum menekan Konfirmasi.
                    </div>
                </div>

                {{-- Error Message inside Modal (if confirm fails) --}}
                <div id="modalConfirmError" class="hidden bg-red-50 border border-red-300 rounded-xl p-3 text-xs text-red-800 font-bold flex items-center gap-2">
                    <i class="fas fa-exclamation-triangle text-red-600 text-sm shrink-0"></i>
                    <span id="modalConfirmErrorText">Terjadi kesalahan.</span>
                </div>
            </div>

            {{-- Modal Footer Actions (Large Finger-Friendly Buttons for Android Screen) --}}
            <div class="p-3 sm:p-4 bg-slate-50 border-t border-slate-200 grid grid-cols-2 gap-3 shrink-0">
                <button
                    type="button"
                    id="modalCancelBtn"
                    onclick="cancelVerification()"
                    class="w-full py-3.5 px-4 rounded-xl font-black text-sm bg-slate-200 hover:bg-slate-300 active:bg-slate-400 text-slate-800 border border-slate-300 transition-all flex items-center justify-center gap-2 cursor-pointer shadow-xs"
                >
                    <i class="fas fa-times text-base"></i>
                    <span>BATAL</span>
                </button>
                <button
                    type="button"
                    id="modalConfirmBtn"
                    onclick="executeConfirmation()"
                    class="w-full py-3.5 px-4 rounded-xl font-black text-sm bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white transition-all flex items-center justify-center gap-2 cursor-pointer shadow-md disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <span id="modalConfirmBtnSpinner" class="hidden"><i class="fas fa-spinner fa-spin text-base"></i></span>
                    <i id="modalConfirmBtnIcon" class="fas fa-check-circle text-base"></i>
                    <span id="modalConfirmBtnText">KONFIRMASI</span>
                </button>
            </div>
        </div>
    </div>

    <script>
        const scanInput = document.getElementById('scanInput');
        const scanForm = document.getElementById('scanForm');
        const resultArea = document.getElementById('resultArea');
        const errorArea = document.getElementById('errorArea');
        const errorReason = document.getElementById('errorReason');
        const statusIndicator = document.getElementById('statusIndicator');
        const lastTreeInfo = document.getElementById('lastTreeInfo');
        const fullFeedbackBanner = document.getElementById('fullFeedbackBanner');
        const feedbackIcon = document.getElementById('feedbackIcon');
        const feedbackStatusTitle = document.getElementById('feedbackStatusTitle');
        const feedbackItemName = document.getElementById('feedbackItemName');
        const feedbackSubDetail = document.getElementById('feedbackSubDetail');

        // Modal Elements
        const verifyModal = document.getElementById('verifyModal');
        const modalProductName = document.getElementById('modalProductName');
        const modalCode = document.getElementById('modalCode');
        const modalBarcode = document.getElementById('modalBarcode');
        const modalCustomer = document.getElementById('modalCustomer');
        const modalQuantityPcs = document.getElementById('modalQuantityPcs');
        const modalDefectNote = document.getElementById('modalDefectNote');
        const modalRackLocation = document.getElementById('modalRackLocation');
        const modalAisiBadge = document.getElementById('modalAisiBadge');
        const modalConfirmBtn = document.getElementById('modalConfirmBtn');
        const modalCancelBtn = document.getElementById('modalCancelBtn');
        const modalConfirmBtnSpinner = document.getElementById('modalConfirmBtnSpinner');
        const modalConfirmBtnIcon = document.getElementById('modalConfirmBtnIcon');
        const modalConfirmBtnText = document.getElementById('modalConfirmBtnText');
        const modalConfirmError = document.getElementById('modalConfirmError');
        const modalConfirmErrorText = document.getElementById('modalConfirmErrorText');

        let feedbackTimeoutId = null;
        let requestSeq = 0;
        let latestHandledRequestSeq = 0;

        // Modal state tracking
        let modalIsOpen = false;
        let pendingBarcode = null;
        let isSubmitting = false;

        // Audio Configuration & Native Audio Elements
        const AUDIO_VOLUME = 0.8;
        const SUCCESS_AUDIO_URL = "{{ asset('audio/lost-wax/success-bell.mp3') }}";
        const FAILED_AUDIO_URL = "{{ asset('audio/lost-wax/failed-buzzer.mp3') }}";

        let successAudio = null;
        let failedAudio = null;
        let audioEnabled = false;

        function initAudioElements() {
            try {
                if (!successAudio) {
                    successAudio = new Audio(SUCCESS_AUDIO_URL);
                    successAudio.preload = 'auto';
                    successAudio.volume = AUDIO_VOLUME;
                    successAudio.load();
                }
                if (!failedAudio) {
                    failedAudio = new Audio(FAILED_AUDIO_URL);
                    failedAudio.preload = 'auto';
                    failedAudio.volume = AUDIO_VOLUME;
                    failedAudio.load();
                }
            } catch (e) {
                console.warn('Audio init warning:', e);
            }
        }

        function toggleAudio() {
            initAudioElements();
            audioEnabled = !audioEnabled;
            const btn = document.getElementById('audioToggleBtn');
            const icon = document.getElementById('audioIcon');
            const text = document.getElementById('audioText');

            if (audioEnabled) {
                btn.className = "inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all shadow-xs bg-emerald-600 hover:bg-emerald-700 text-white border border-emerald-700 cursor-pointer";
                icon.className = "fas fa-volume-up text-white text-xs";
                text.innerText = "SUARA AKTIF";
            } else {
                btn.className = "inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all shadow-xs bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-300 cursor-pointer";
                icon.className = "fas fa-volume-mute text-slate-500 text-xs";
                text.innerText = "AKTIFKAN SUARA";
            }
            if (!modalIsOpen) {
                scanInput.focus();
            }
        }

        function playSound(isSuccess) {
            if (!audioEnabled) return;
            initAudioElements();
            try {
                const audio = isSuccess ? successAudio : failedAudio;
                if (audio) {
                    audio.pause();
                    audio.currentTime = 0;
                    audio.play().catch(e => console.warn('Audio play prevented:', e));
                }
            } catch (e) {}
        }

        // Form submission & barcode preview request
        scanForm.addEventListener('submit', function(e) {
            e.preventDefault();
            if (modalIsOpen || isSubmitting) return;

            const barcode = scanInput.value.trim();
            if (!barcode) return;

            scanInput.value = '';
            scanInput.blur();

            requestSeq++;
            const thisRequestSeq = requestSeq;

            errorArea.classList.add('hidden');
            resultArea.classList.add('hidden');
            resultArea.innerHTML = '';
            statusIndicator.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> MEMERIKSA DATA TRAVELER...';
            statusIndicator.className = 'text-base sm:text-lg font-bold text-amber-600';

            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            // Call PREVIEW endpoint (No database changes)
            fetch('{{ route('lost-wax.scan-siap-cor.preview') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ barcode: barcode }),
            })
            .then(async r => {
                if (thisRequestSeq < latestHandledRequestSeq) return null;
                if (!r.ok) {
                    let errJson = null;
                    try { errJson = await r.json(); } catch(e) {}
                    const reason = (errJson && (errJson.reason || errJson.message))
                        ? (errJson.reason || errJson.message)
                        : `Terjadi kesalahan server (HTTP ${r.status}).`;
                    showError({ reason: reason }, barcode, thisRequestSeq);
                    return null;
                }
                return r.json();
            })
            .then(data => {
                if (!data) return;
                if (thisRequestSeq < latestHandledRequestSeq) return;
                latestHandledRequestSeq = thisRequestSeq;

                if (data.success === true) {
                    openVerificationModal(data, barcode);
                } else {
                    showError(data, barcode, thisRequestSeq);
                }
            })
            .catch(err => {
                if (thisRequestSeq < latestHandledRequestSeq) return;
                latestHandledRequestSeq = thisRequestSeq;
                showError({ reason: 'GAGAL — KONEKSI SERVER TERPUTUS' }, barcode, thisRequestSeq);
            })
            .finally(() => {
                if (!modalIsOpen) {
                    scanInput.focus();
                }
            });
        });

        // Open Verification Modal with Preview Data
        function openVerificationModal(data, barcode) {
            const ti = data.tree_info || {};
            pendingBarcode = barcode;
            modalIsOpen = true;

            // Populate Modal Content
            modalProductName.innerText = ti.product_name || '-';
            modalCode.innerText = ti.code || '-';
            modalBarcode.innerText = barcode;
            modalCustomer.innerText = ti.customer || '-';
            modalQuantityPcs.innerText = Number(ti.quantity_usable || 0).toLocaleString();
            modalDefectNote.innerText = `Bruto: ${Number(ti.quantity_gross || 0).toLocaleString()} | Cacat: ${Number(ti.total_defects || 0).toLocaleString()}`;
            modalRackLocation.innerText = ti.rack_location || 'Belum ditentukan';
            modalAisiBadge.innerText = 'AISI: ' + (ti.aisi || '-');

            // Reset modal error alert
            modalConfirmError.classList.add('hidden');
            modalConfirmErrorText.innerText = '';

            // Reset button states
            modalConfirmBtn.disabled = false;
            modalCancelBtn.disabled = false;
            modalConfirmBtnSpinner.classList.add('hidden');
            modalConfirmBtnIcon.classList.remove('hidden');
            modalConfirmBtnText.innerText = 'KONFIRMASI';

            // Show modal
            verifyModal.classList.remove('hidden');

            statusIndicator.innerHTML = '<i class="fas fa-exclamation-circle mr-2 text-indigo-600"></i> MENUNGGU VERIFIKASI OPERATOR';
            statusIndicator.className = 'text-base sm:text-lg font-bold text-indigo-700';
        }

        // Action: BATAL
        function cancelVerification() {
            if (isSubmitting) return;

            verifyModal.classList.add('hidden');
            modalIsOpen = false;
            pendingBarcode = null;

            statusIndicator.innerHTML = '<i class="fas fa-qrcode mr-2 text-emerald-600"></i> SIAP SCAN SELESAI DEWAXING';
            statusIndicator.className = 'text-base sm:text-lg font-bold text-slate-600';

            scanInput.value = '';
            scanInput.focus();
        }

        // Action: KONFIRMASI (Calls process endpoint to save transaction)
        function executeConfirmation() {
            if (isSubmitting || !pendingBarcode) return;
            isSubmitting = true;

            modalConfirmBtn.disabled = true;
            modalCancelBtn.disabled = true;
            modalConfirmBtnSpinner.classList.remove('hidden');
            modalConfirmBtnIcon.classList.add('hidden');
            modalConfirmBtnText.innerText = 'MENYIMPAN...';
            modalConfirmError.classList.add('hidden');

            const barcodeToConfirm = pendingBarcode;
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            requestSeq++;
            const thisRequestSeq = requestSeq;

            fetch('{{ route('lost-wax.scan-siap-cor.process') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ barcode: barcodeToConfirm }),
            })
            .then(async r => {
                if (!r.ok) {
                    let errJson = null;
                    try { errJson = await r.json(); } catch(e) {}
                    const reason = (errJson && (errJson.reason || errJson.message))
                        ? (errJson.reason || errJson.message)
                        : `Terjadi kesalahan server (HTTP ${r.status}).`;
                    return { success: false, reason: reason };
                }
                return r.json();
            })
            .then(data => {
                latestHandledRequestSeq = thisRequestSeq;

                if (data.success === true) {
                    // Close modal and show success feedback
                    verifyModal.classList.add('hidden');
                    modalIsOpen = false;
                    pendingBarcode = null;
                    isSubmitting = false;

                    showSuccess(data, barcodeToConfirm, thisRequestSeq);
                } else {
                    // Reject confirmation: close modal and show error banner
                    verifyModal.classList.add('hidden');
                    modalIsOpen = false;
                    pendingBarcode = null;
                    isSubmitting = false;

                    showError(data, barcodeToConfirm, thisRequestSeq);
                }
            })
            .catch(err => {
                modalConfirmErrorText.innerText = 'GAGAL — KONEKSI SERVER TERPUTUS';
                modalConfirmError.classList.remove('hidden');

                modalConfirmBtn.disabled = false;
                modalCancelBtn.disabled = false;
                modalConfirmBtnSpinner.classList.add('hidden');
                modalConfirmBtnIcon.classList.remove('hidden');
                modalConfirmBtnText.innerText = 'KONFIRMASI ULANG';
                isSubmitting = false;
            })
            .finally(() => {
                if (!modalIsOpen) {
                    scanInput.focus();
                }
            });
        }

        function showSuccess(data, barcode, thisRequestSeq) {
            const ti = data.tree_info || {};

            statusIndicator.innerHTML = '<i class="fas fa-check-circle mr-2 text-emerald-600"></i> BERHASIL';
            statusIndicator.className = 'text-base sm:text-lg font-bold text-emerald-700';

            playSound(true);

            // Fullscreen feedback colors
            document.body.classList.remove('scanner-feedback-failed');
            document.body.classList.add('scanner-feedback-success');

            // Banner
            fullFeedbackBanner.className = 'rounded-2xl p-6 sm:p-8 text-center shadow-lg border-2 bg-emerald-100 border-emerald-400 text-emerald-900 block';
            feedbackIcon.className = 'fas fa-check-circle text-emerald-600';
            feedbackStatusTitle.innerText = data.message || 'BERHASIL — SELESAI DEWAXING';
            feedbackItemName.innerText = (ti.code ? ti.code + ' — ' : '') + (ti.product_name || 'Item Terverifikasi');
            feedbackSubDetail.innerText = `${barcode} | Pohon #${ti.tree_number || '-'} | ${Number(ti.quantity_usable || 0).toLocaleString()} PCS Layak`;

            // Last tree info card
            updateLastTreeCard(ti, barcode, true, 'BERHASIL');

            // Add row to recent history table
            addHistoryRow({
                time: new Date().toLocaleTimeString('id-ID'),
                barcode: barcode,
                code: ti.code || '-',
                product: ti.product_name || '-',
                usableQty: Number(ti.quantity_usable || 0).toLocaleString() + ' PCS',
                isSuccess: true,
                keterangan: 'Selesai Dewaxing'
            });

            clearTimeout(feedbackTimeoutId);
            feedbackTimeoutId = setTimeout(() => {
                if (thisRequestSeq === latestHandledRequestSeq && !modalIsOpen) {
                    document.body.classList.remove('scanner-feedback-success');
                    fullFeedbackBanner.classList.add('hidden');
                    statusIndicator.innerHTML = '<i class="fas fa-qrcode mr-2 text-emerald-600"></i> SIAP SCAN SELESAI DEWAXING';
                    statusIndicator.className = 'text-base sm:text-lg font-bold text-slate-600';
                }
            }, 5000);
        }

        function showError(data, barcode, thisRequestSeq) {
            const ti = data.tree_info || {};
            const reason = data.reason || 'GAGAL — TERJADI KESALAHAN';

            statusIndicator.innerHTML = '<i class="fas fa-times-circle mr-2 text-rose-600"></i> ' + reason;
            statusIndicator.className = 'text-base sm:text-lg font-bold text-rose-700';

            playSound(false);

            document.body.classList.remove('scanner-feedback-success');
            document.body.classList.add('scanner-feedback-failed');

            // Banner
            fullFeedbackBanner.className = 'rounded-2xl p-6 sm:p-8 text-center shadow-lg border-2 bg-rose-100 border-rose-400 text-rose-900 block';
            feedbackIcon.className = 'fas fa-times-circle text-rose-600';
            feedbackStatusTitle.innerText = 'SCAN GAGAL';
            feedbackItemName.innerText = reason;
            feedbackSubDetail.innerText = barcode + (ti.code ? ' (' + ti.code + ')' : '');

            // Inline error alert
            errorArea.classList.remove('hidden');
            errorReason.innerText = reason;

            // Last tree info card
            if (data.tree_info) {
                updateLastTreeCard(ti, barcode, false, 'GAGAL');
            }

            // Add row to recent history table
            addHistoryRow({
                time: new Date().toLocaleTimeString('id-ID'),
                barcode: barcode,
                code: ti.code || '-',
                product: ti.product_name || '-',
                usableQty: ti.quantity_usable ? Number(ti.quantity_usable).toLocaleString() + ' PCS' : '-',
                isSuccess: false,
                keterangan: reason
            });

            clearTimeout(feedbackTimeoutId);
            feedbackTimeoutId = setTimeout(() => {
                if (thisRequestSeq === latestHandledRequestSeq && !modalIsOpen) {
                    document.body.classList.remove('scanner-feedback-failed');
                    fullFeedbackBanner.classList.add('hidden');
                    errorArea.classList.add('hidden');
                    statusIndicator.innerHTML = '<i class="fas fa-qrcode mr-2 text-emerald-600"></i> SIAP SCAN SELESAI DEWAXING';
                    statusIndicator.className = 'text-base sm:text-lg font-bold text-slate-600';
                }
            }, 5000);
        }

        function updateLastTreeCard(ti, barcode, isSuccess, statusLabel) {
            lastTreeInfo.classList.remove('hidden');
            document.getElementById('lastBarcode').innerText = barcode;
            document.getElementById('lastTreeNumber').innerText = 'Pohon #' + (ti.tree_number || '-');
            document.getElementById('lastCode').innerText = ti.code || '-';
            document.getElementById('lastCustomer').innerText = 'Cust: ' + (ti.customer || '-');
            document.getElementById('lastProduct').innerText = ti.product_name || '-';
            document.getElementById('lastAisi').innerText = 'AISI: ' + (ti.aisi || '-');
            document.getElementById('lastQuantity').innerText = Number(ti.quantity_usable || 0).toLocaleString() + ' PCS';
            document.getElementById('lastDefects').innerText = `Bruto: ${Number(ti.quantity_gross || 0).toLocaleString()} | Cacat: ${Number(ti.total_defects || 0).toLocaleString()}`;

            const badge = document.getElementById('lastBadgeStatus');
            badge.innerText = statusLabel;
            badge.className = isSuccess
                ? "px-2.5 py-0.5 rounded text-xs font-bold font-mono bg-emerald-500 text-white"
                : "px-2.5 py-0.5 rounded text-xs font-bold font-mono bg-rose-500 text-white";
        }

        function addHistoryRow(item) {
            const tbody = document.getElementById('historyTableBody');
            const emptyRow = document.getElementById('emptyHistoryRow');
            if (emptyRow) {
                emptyRow.remove();
            }

            const tr = document.createElement('tr');
            tr.className = "hover:bg-slate-50 transition-colors bg-slate-50/50";
            tr.innerHTML = `
                <td class="py-2 px-3 text-center font-mono text-[11px] text-slate-500">${item.time}</td>
                <td class="py-2 px-3 font-mono font-bold text-slate-800">${item.barcode}</td>
                <td class="py-2 px-3 font-mono font-bold text-indigo-700">${item.code}</td>
                <td class="py-2 px-3 truncate max-w-[200px]" title="${item.product}">${item.product}</td>
                <td class="py-2 px-3 text-right font-black ${item.isSuccess ? 'text-emerald-700' : 'text-slate-400'}">${item.usableQty}</td>
                <td class="py-2 px-3 text-center">
                    ${item.isSuccess 
                        ? '<span class="inline-block px-2 py-0.5 rounded text-[10px] font-black bg-emerald-100 text-emerald-800 border border-emerald-300">BERHASIL</span>'
                        : '<span class="inline-block px-2 py-0.5 rounded text-[10px] font-black bg-rose-100 text-rose-800 border border-rose-300">GAGAL</span>'
                    }
                </td>
                <td class="py-2 px-3 text-[11px] ${item.isSuccess ? 'text-slate-500' : 'text-rose-600 font-bold'}">${item.keterangan}</td>
            `;

            tbody.insertBefore(tr, tbody.firstChild);

            // Keep max 10 rows
            while (tbody.children.length > 10) {
                tbody.removeChild(tbody.lastChild);
            }
        }

        // Re-focus protection when clicking outside or window active (Disabled when modal is open)
        document.addEventListener('click', function(e) {
            if (modalIsOpen) return;
            if (e.target.tagName !== 'BUTTON' && e.target.tagName !== 'A' && e.target.tagName !== 'INPUT') {
                scanInput.focus();
            }
        });

        window.addEventListener('focus', function() {
            if (!modalIsOpen) {
                scanInput.focus();
            }
        });

        // Keyboard navigation inside modal (ESC to Cancel, Enter to Confirm)
        document.addEventListener('keydown', function(e) {
            if (!modalIsOpen) return;

            if (e.key === 'Escape') {
                e.preventDefault();
                cancelVerification();
            }
        });
    </script>
@endsection
