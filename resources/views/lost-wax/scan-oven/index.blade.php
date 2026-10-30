@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full gap-2">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">LOST WAX &mdash; SCAN OVEN</h1>
            <p class="text-gray-500 text-[10px]">Scan barcode Tree saat rak masuk Oven</p>
        </div>
        <div>
            {{-- Audio Toggle Control --}}
            <button
                type="button"
                id="audioToggleBtn"
                onclick="toggleAudio()"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all shadow-xs bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-300"
                title="Aktifkan / Nonaktifkan Audio Feedback"
            >
                <i id="audioIcon" class="fas fa-volume-mute text-slate-500 text-xs"></i>
                <span id="audioText">AKTIFKAN SUARA</span>
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

    <div class="max-w-2xl mx-auto space-y-6">
        {{-- PROMINENT 10-METER DISTANCE FEEDBACK BANNER --}}
        <div id="fullFeedbackBanner" class="hidden rounded-2xl p-6 sm:p-8 text-center shadow-lg border-2 transition-all">
            <div id="feedbackIconWrapper" class="text-5xl sm:text-6xl mb-3">
                <i id="feedbackIcon" class="fas fa-check-circle"></i>
            </div>
            <div id="feedbackStatusTitle" class="text-3xl sm:text-4xl font-black tracking-tight mb-2 uppercase">
                SCAN BERHASIL
            </div>
            <div id="feedbackItemName" class="text-xl sm:text-2xl font-black tracking-tight mb-1">
                {{-- Scanned Item Name / Failure Reason --}}
            </div>
            <div id="feedbackSubDetail" class="text-base sm:text-lg font-mono font-bold opacity-90">
                {{-- Heat Number / Barcode / Quantity --}}
            </div>
        </div>

        {{-- Scan Area --}}
        <div id="scanPanelCard" class="scan-panel-card bg-white rounded-xl shadow-sm border border-slate-200 p-8 text-center transition-colors">
            <div class="mb-6">
                <div id="statusIndicator" class="text-lg font-bold text-slate-500">
                    <i class="fas fa-fire mr-2"></i> SIAP SCAN OVEN
                </div>
            </div>

            <form id="scanForm" autocomplete="off" class="mb-6">
                <input
                    type="text"
                    id="scanInput"
                    name="barcode"
                    class="w-full text-center text-2xl font-mono tracking-widest py-4 rounded-xl border-2 border-slate-300 focus:border-orange-500 focus:ring-orange-500 outline-none transition-colors"
                    placeholder="SCAN BARCODE OVEN"
                    autofocus
                    autocomplete="off"
                >
            </form>

            <div id="resultArea" class="hidden space-y-4"></div>
        </div>

        {{-- Last Tree Info --}}
        <div id="lastTreeInfo" class="bg-slate-900 text-white rounded-xl shadow-sm border border-slate-700 p-6 hidden">
            <h2 class="text-xs font-semibold text-slate-400 uppercase mb-4">Hasil Terakhir</h2>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <div class="text-slate-400 text-xs">Barcode</div>
                    <div id="lastBarcode" class="font-mono font-bold">-</div>
                </div>
                <div>
                    <div class="text-slate-400 text-xs">Produk</div>
                    <div id="lastProduct" class="font-bold">-</div>
                </div>
                <div>
                    <div class="text-slate-400 text-xs">Posisi Terakhir</div>
                    <div id="lastPosition" class="font-bold">-</div>
                </div>
                <div>
                    <div class="text-slate-400 text-xs">Quantity</div>
                    <div id="lastQuantity" class="font-bold">-</div>
                </div>
            </div>
            <div id="lastResultSection" class="mt-4 pt-4 border-t border-slate-700 hidden">
                <div class="flex justify-between text-sm">
                    <span class="text-slate-400">Status</span>
                    <span id="lastResult" class="font-bold"></span>
                </div>
            </div>
        </div>

        {{-- Error Area (Legacy inline area preserved) --}}
        <div id="errorArea" class="hidden bg-red-50 border border-red-200 rounded-xl p-6 text-center">
            <div class="text-red-600 font-bold text-lg mb-2">
                <i class="fas fa-times-circle mr-2"></i> SCAN GAGAL
            </div>
            <div id="errorReason" class="text-red-700 text-sm"></div>
        </div>
    </div>

    <script>
        const scanInput = document.getElementById('scanInput');
        const scanForm = document.getElementById('scanForm');
        const resultArea = document.getElementById('resultArea');
        const errorArea = document.getElementById('errorArea');
        const statusIndicator = document.getElementById('statusIndicator');
        const lastTreeInfo = document.getElementById('lastTreeInfo');
        const fullFeedbackBanner = document.getElementById('fullFeedbackBanner');
        const feedbackIcon = document.getElementById('feedbackIcon');
        const feedbackStatusTitle = document.getElementById('feedbackStatusTitle');
        const feedbackItemName = document.getElementById('feedbackItemName');
        const feedbackSubDetail = document.getElementById('feedbackSubDetail');

        let feedbackTimeoutId = null;
        let currentFeedbackId = 0;
        let requestSeq = 0;
        let latestHandledRequestSeq = 0;

        // Audio Configuration & Native HTMLAudioElement Instances
        const AUDIO_VOLUME = 0.8;
        const SUCCESS_AUDIO_URL = "{{ asset('audio/lost-wax/success-bell.mp3') }}";
        const FAILED_AUDIO_URL = "{{ asset('audio/lost-wax/failed-buzzer.mp3') }}";

        let successAudio = null;
        let failedAudio = null;
        let currentPlayingAudio = null;
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
                // Silently handle if audio element creation fails
            }
        }

        // Initialize audio elements early on page load
        initAudioElements();

        function stopAllAudio() {
            try {
                if (currentPlayingAudio) {
                    currentPlayingAudio.pause();
                    currentPlayingAudio.currentTime = 0;
                    currentPlayingAudio = null;
                }
                if (successAudio) {
                    successAudio.pause();
                    successAudio.currentTime = 0;
                }
                if (failedAudio) {
                    failedAudio.pause();
                    failedAudio.currentTime = 0;
                }
            } catch (e) {
                // Ignore pause errors
            }
        }

        function playSuccessSound() {
            if (!audioEnabled) return;
            try {
                initAudioElements();
                if (!successAudio) return;

                stopAllAudio();
                successAudio.currentTime = 0;
                currentPlayingAudio = successAudio;

                const playPromise = successAudio.play();
                if (playPromise !== undefined) {
                    playPromise.catch(() => {
                        // Browser autoplay policy might reject; ignore gracefully
                    });
                }
            } catch (e) {
                // Audio failure must never interrupt scanning or visual feedback
            }
        }

        function playErrorSound() {
            if (!audioEnabled) return;
            try {
                initAudioElements();
                if (!failedAudio) return;

                stopAllAudio();
                failedAudio.currentTime = 0;
                currentPlayingAudio = failedAudio;

                const playPromise = failedAudio.play();
                if (playPromise !== undefined) {
                    playPromise.catch(() => {
                        // Browser autoplay policy might reject; ignore gracefully
                    });
                }
            } catch (e) {
                // Audio failure must never interrupt scanning or visual feedback
            }
        }

        function enableAudio() {
            initAudioElements();
            audioEnabled = true;
            updateAudioUI(true);

            // Prime audio playback within user gesture context without playing loud test sound
            try {
                if (successAudio) {
                    successAudio.play().then(() => {
                        successAudio.pause();
                        successAudio.currentTime = 0;
                    }).catch(() => {});
                }
                if (failedAudio) {
                    failedAudio.load();
                }
            } catch (e) {}
        }

        function toggleAudio() {
            if (!audioEnabled) {
                enableAudio();
            } else {
                audioEnabled = false;
                stopAllAudio();
                updateAudioUI(false);
            }
        }

        function updateAudioUI(enabled) {
            const btn = document.getElementById('audioToggleBtn');
            const icon = document.getElementById('audioIcon');
            const text = document.getElementById('audioText');
            if (!btn || !icon || !text) return;

            if (enabled) {
                btn.className = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all shadow-xs bg-emerald-100 hover:bg-emerald-200 text-emerald-800 border border-emerald-300';
                icon.className = 'fas fa-volume-up text-emerald-600 text-xs';
                text.textContent = 'SUARA AKTIF';
            } else {
                btn.className = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all shadow-xs bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-300';
                icon.className = 'fas fa-volume-mute text-slate-500 text-xs';
                text.textContent = 'AKTIFKAN SUARA';
            }
        }

        // Auto-refocus scanner input on click
        scanInput.focus();
        document.addEventListener('click', function () {
            scanInput.focus();
        }, { passive: true });

        function handleSessionRecovery(barcode) {
            const RELOAD_GUARD_KEY = 'scanner_oven_last_reload';
            const now = Date.now();
            const lastReload = parseInt(sessionStorage.getItem(RELOAD_GUARD_KEY) || '0', 10);

            if (now - lastReload < 30000) {
                showError({ reason: 'Sesi kedaluwarsa atau otentikasi gagal. Silakan muat ulang halaman atau login kembali.' }, barcode, latestHandledRequestSeq);
                return;
            }

            sessionStorage.setItem(RELOAD_GUARD_KEY, now.toString());
            statusIndicator.innerHTML = '<i class="fas fa-sync fa-spin mr-2"></i> MEMPERBARUI SESI...';
            statusIndicator.className = 'text-lg font-bold text-blue-600';
            setTimeout(() => {
                window.location.reload();
            }, 300);
        }

        // Heartbeat keepalive every 20 minutes to prevent session idle timeout
        const KEEPALIVE_INTERVAL = 20 * 60 * 1000;
        setInterval(function () {
            fetch('{{ route('lost-wax.scan.keepalive') }}', {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).catch(() => {});
        }, KEEPALIVE_INTERVAL);

        function clearFeedback() {
            if (feedbackTimeoutId) {
                clearTimeout(feedbackTimeoutId);
                feedbackTimeoutId = null;
            }

            document.body.classList.remove('scanner-feedback-success', 'scanner-feedback-failed');
            fullFeedbackBanner.classList.add('hidden');

            statusIndicator.innerHTML = '<i class="fas fa-fire mr-2"></i> SIAP SCAN OVEN';
            statusIndicator.className = 'text-lg font-bold text-slate-500';
            errorArea.classList.add('hidden');
        }

        function triggerFeedback(type, data, barcode) {
            currentFeedbackId++;
            const thisFeedbackId = currentFeedbackId;

            if (feedbackTimeoutId) {
                clearTimeout(feedbackTimeoutId);
                feedbackTimeoutId = null;
            }

            document.body.classList.remove('scanner-feedback-success', 'scanner-feedback-failed');

            if (type === 'SUCCESS') {
                document.body.classList.add('scanner-feedback-success');

                const ti = data.tree_info || data.tree || {};
                const itemName = (ti.item_name && ti.item_name !== '-')
                    ? ((ti.item_code && ti.item_code !== '-') ? `${ti.item_code} — ${ti.item_name}` : ti.item_name)
                    : ((ti.item_code && ti.item_code !== '-') ? ti.item_code : barcode);

                const heatNum = (ti.heat_number && ti.heat_number !== '-') ? `HEAT: ${ti.heat_number} | ` : '';
                const qtyTxt = ti.quantity ? `${ti.quantity} PCS | ` : '';

                fullFeedbackBanner.className = 'rounded-2xl p-6 sm:p-8 text-center shadow-lg border-2 border-[#86EFAC] bg-[#DCFCE7] text-[#166534] transition-all';
                feedbackIcon.className = 'fas fa-check-circle text-emerald-600';
                feedbackStatusTitle.textContent = 'SCAN BERHASIL';
                feedbackStatusTitle.className = 'text-3xl sm:text-4xl font-black tracking-tight mb-2 text-[#166534] uppercase';
                feedbackItemName.textContent = itemName;
                feedbackItemName.className = 'text-xl sm:text-2xl font-black tracking-tight mb-1 text-[#166534]';
                feedbackSubDetail.innerHTML = `${heatNum}${qtyTxt}${barcode} &rarr; OVEN (FINAL)`;
                feedbackSubDetail.className = 'text-base sm:text-lg font-mono font-bold text-[#166534]/90';
                fullFeedbackBanner.classList.remove('hidden');

                playSuccessSound();
            } else {
                document.body.classList.add('scanner-feedback-failed');

                const failureReason = (data && data.reason)
                    ? data.reason
                    : ((data && data.message) ? data.message : 'Scan Oven tidak valid atau ditolak.');

                fullFeedbackBanner.className = 'rounded-2xl p-6 sm:p-8 text-center shadow-lg border-2 border-[#FCA5A5] bg-[#FEE2E2] text-[#991B1B] transition-all';
                feedbackIcon.className = 'fas fa-times-circle text-red-600';
                feedbackStatusTitle.textContent = 'SCAN GAGAL';
                feedbackStatusTitle.className = 'text-3xl sm:text-4xl font-black tracking-tight mb-2 text-[#991B1B] uppercase';
                feedbackItemName.textContent = failureReason;
                feedbackItemName.className = 'text-xl sm:text-2xl font-bold tracking-tight mb-1 text-[#991B1B]';
                feedbackSubDetail.textContent = barcode ? `BARCODE: ${barcode}` : '';
                feedbackSubDetail.className = 'text-base sm:text-lg font-mono font-bold text-[#991B1B]/90';
                fullFeedbackBanner.classList.remove('hidden');

                playErrorSound();
            }

            feedbackTimeoutId = setTimeout(() => {
                if (currentFeedbackId === thisFeedbackId) {
                    clearFeedback();
                }
            }, 5000);
        }

        scanForm.addEventListener('submit', function (e) {
            e.preventDefault();

            const barcode = scanInput.value.trim();
            if (!barcode) return;

            // Immediately clear input and keep focus so operator can scan next barcode without delay
            scanInput.value = '';
            scanInput.focus();

            requestSeq++;
            const thisRequestSeq = requestSeq;

            errorArea.classList.add('hidden');
            resultArea.classList.add('hidden');
            resultArea.innerHTML = '';
            statusIndicator.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> MEMPROSES...';
            statusIndicator.className = 'text-lg font-bold text-amber-600';

            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            fetch('{{ route('lost-wax.scan-oven.process') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ barcode: barcode }),
            })
                .then(async r => {
                    // Ignore response if a newer scan request has already been processed
                    if (thisRequestSeq < latestHandledRequestSeq) return null;

                    if (r.status === 419 || r.status === 401) {
                        showError({ reason: 'Sesi kedaluwarsa atau otentikasi gagal. Memperbarui sesi...' }, barcode, thisRequestSeq);
                        handleSessionRecovery(barcode);
                        return null;
                    }
                    if (!r.ok) {
                        let errJson = null;
                        try {
                            errJson = await r.json();
                        } catch (e) {}
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
                    // Ignore response if a newer scan request has already been processed
                    if (thisRequestSeq < latestHandledRequestSeq) return;

                    latestHandledRequestSeq = thisRequestSeq;

                    if (data.success === true) {
                        showSuccess(data, barcode, thisRequestSeq);
                    } else {
                        showError(data, barcode, thisRequestSeq);
                    }
                })
                .catch(err => {
                    if (thisRequestSeq < latestHandledRequestSeq) return;
                    latestHandledRequestSeq = thisRequestSeq;
                    showError({ reason: 'Gagal terhubung ke server (Network Error).' }, barcode, thisRequestSeq);
                })
                .finally(() => {
                    scanInput.focus();
                });
        });

        function showSuccess(data, barcode, thisRequestSeq) {
            const ti = data.tree_info || data.tree || {};

            resultArea.classList.remove('hidden');
            resultArea.innerHTML = `
                <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-4">
                    <div class="text-emerald-600 font-bold text-xl mb-2">
                        <i class="fas fa-check-circle mr-2"></i> BERHASIL
                    </div>
                    <div class="text-lg font-mono font-bold text-emerald-800">${barcode}</div>
                    <div class="text-emerald-700 mt-2 text-sm">
                        Posisi terakhir: <span class="font-bold">${ti.current_stage_label || '-'}</span>
                    </div>
                    <div class="text-emerald-700 text-sm">
                        Quantity: <span class="font-bold">${ti.quantity || '-'} PCS</span>
                    </div>
                    <div class="mt-3 text-2xl font-bold text-emerald-800">
                        &rarr; OVEN
                    </div>
                    <div class="text-lg font-bold text-emerald-800">FINAL</div>
                    <div class="text-sm text-emerald-600 mt-2">
                        Time: ${new Date().toLocaleTimeString('id-ID')}
                    </div>
                    ${data.aging_label ? `
                        <div class="text-sm text-emerald-600">Aging: ${data.aging_label}</div>
                    ` : ''}
                </div>
            `;

            statusIndicator.innerHTML = '<i class="fas fa-check-circle mr-2 text-emerald-600"></i> SCAN BERHASIL';
            statusIndicator.className = 'text-lg font-bold text-emerald-700';

            lastTreeInfo.classList.remove('hidden');
            document.getElementById('lastBarcode').textContent = barcode;
            document.getElementById('lastProduct').textContent = (ti.item_code || '-') + ' — ' + (ti.item_name || '-');
            document.getElementById('lastPosition').textContent = ti.current_stage_label || '-';
            document.getElementById('lastQuantity').textContent = (ti.quantity || '-') + ' PCS';

            document.getElementById('lastResultSection').classList.remove('hidden');
            document.getElementById('lastResult').textContent = '\u2192 OVEN \u2014 FINAL';
            document.getElementById('lastResult').className = 'font-bold text-emerald-400';

            // Trigger full-page SUCCESS feedback
            triggerFeedback('SUCCESS', data, barcode);
        }

        function showError(data, barcode, thisRequestSeq) {
            errorArea.classList.remove('hidden');
            const failureReason = data.reason || 'Scan Oven tidak valid atau ditolak.';
            document.getElementById('errorReason').textContent = failureReason;

            statusIndicator.innerHTML = '<i class="fas fa-times-circle mr-2 text-red-600"></i> SCAN GAGAL';
            statusIndicator.className = 'text-lg font-bold text-red-700';

            if (data.tree_info || data.tree) {
                const ti = data.tree_info || data.tree;
                lastTreeInfo.classList.remove('hidden');
                document.getElementById('lastBarcode').textContent = barcode;
                document.getElementById('lastProduct').textContent = (ti.item_code || '-') + ' — ' + (ti.item_name || '-');
                document.getElementById('lastPosition').textContent = ti.current_stage_label || '-';
                document.getElementById('lastQuantity').textContent = (ti.quantity || '-') + ' PCS';
                document.getElementById('lastResultSection').classList.add('hidden');
            }

            // Trigger full-page FAILED feedback
            triggerFeedback('FAILED', data, barcode);
        }
    </script>
@endsection
