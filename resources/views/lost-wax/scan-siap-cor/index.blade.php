@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full gap-2">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">LOST WAX &mdash; SCAN SELESAI DEWAXING</h1>
            <p class="text-gray-500 text-[10px]">Scan saat pohon keluar dari oven dan ditempatkan di rak buffer</p>
        </div>
        <div>
            {{-- Audio Toggle Control --}}
            <button
                type="button"
                id="audioToggleBtn"
                onclick="toggleAudio()"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all shadow-xs bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-300 cursor-pointer"
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
        <div id="scanPanelCard" class="scan-panel-card bg-white rounded-xl shadow-sm border border-slate-200 p-8 text-center transition-colors">
            <div class="mb-6">
                <div id="statusIndicator" class="text-lg font-bold text-slate-600">
                    <i class="fas fa-check-double mr-2 text-emerald-600"></i> SIAP SCAN SELESAI DEWAXING
                </div>
            </div>

            <form id="scanForm" autocomplete="off" class="mb-4">
                <input
                    type="text"
                    id="scanInput"
                    name="barcode"
                    class="w-full text-center text-2xl font-mono tracking-widest py-4 rounded-xl border-2 border-slate-300 focus:border-emerald-600 focus:ring-emerald-600 outline-none transition-colors"
                    placeholder="SCAN BARCODE TRAVELER CARD"
                    autofocus
                    autocomplete="off"
                >
            </form>

            <div class="text-xs text-slate-400">
                Pindai barcode pohon saat keluar dari oven dewaxing untuk memindahkan kuantitas ke rak buffer.
            </div>

            <div id="resultArea" class="hidden space-y-4 mt-6"></div>
        </div>

        {{-- Last Tree Info Card --}}
        <div id="lastTreeInfo" class="bg-slate-900 text-white rounded-xl shadow-sm border border-slate-700 p-6 hidden">
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

        let feedbackTimeoutId = null;
        let requestSeq = 0;
        let latestHandledRequestSeq = 0;

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
            scanInput.focus();
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

        // Form submission & barcode processing
        scanForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const barcode = scanInput.value.trim();
            if (!barcode) return;

            scanInput.value = '';
            scanInput.focus();

            requestSeq++;
            const thisRequestSeq = requestSeq;

            errorArea.classList.add('hidden');
            resultArea.classList.add('hidden');
            resultArea.innerHTML = '';
            statusIndicator.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> MEMPROSES SCAN...';
            statusIndicator.className = 'text-lg font-bold text-amber-600';

            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            fetch('{{ route('lost-wax.scan-siap-cor.process') }}', {
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
                    showSuccess(data, barcode, thisRequestSeq);
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
                scanInput.focus();
            });
        });

        function showSuccess(data, barcode, thisRequestSeq) {
            const ti = data.tree_info || {};

            statusIndicator.innerHTML = '<i class="fas fa-check-circle mr-2 text-emerald-600"></i> BERHASIL';
            statusIndicator.className = 'text-lg font-bold text-emerald-700';

            playSound(true);

            // Fullscreen feedback colors
            document.body.classList.remove('scanner-feedback-failed');
            document.body.classList.add('scanner-feedback-success');

            // Banner
            fullFeedbackBanner.className = 'rounded-2xl p-6 sm:p-8 text-center shadow-lg border-2 bg-emerald-100 border-emerald-400 text-emerald-900 block';
            feedbackIcon.className = 'fas fa-check-circle text-emerald-600';
            feedbackStatusTitle.innerText = data.message || 'BERHASIL — SIAP COR';
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
                keterangan: 'Siap Cor'
            });

            clearTimeout(feedbackTimeoutId);
            feedbackTimeoutId = setTimeout(() => {
                if (thisRequestSeq === latestHandledRequestSeq) {
                    document.body.classList.remove('scanner-feedback-success');
                    fullFeedbackBanner.classList.add('hidden');
                    statusIndicator.innerHTML = '<i class="fas fa-check-double mr-2 text-emerald-600"></i> SIAP SCAN SELESAI DEWAXING';
                    statusIndicator.className = 'text-lg font-bold text-slate-600';
                }
            }, 5000);
        }

        function showError(data, barcode, thisRequestSeq) {
            const ti = data.tree_info || {};
            const reason = data.reason || 'GAGAL — TERJADI KESALAHAN';

            statusIndicator.innerHTML = '<i class="fas fa-times-circle mr-2 text-rose-600"></i> ' + reason;
            statusIndicator.className = 'text-lg font-bold text-rose-700';

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
                if (thisRequestSeq === latestHandledRequestSeq) {
                    document.body.classList.remove('scanner-feedback-failed');
                    fullFeedbackBanner.classList.add('hidden');
                    errorArea.classList.add('hidden');
                    statusIndicator.innerHTML = '<i class="fas fa-check-double mr-2 text-emerald-600"></i> SIAP SCAN SELESAI DEWAXING';
                    statusIndicator.className = 'text-lg font-bold text-slate-600';
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

        // Re-focus protection when clicking outside or window active
        document.addEventListener('click', function(e) {
            if (e.target.tagName !== 'BUTTON' && e.target.tagName !== 'A' && e.target.tagName !== 'INPUT') {
                scanInput.focus();
            }
        });

        window.addEventListener('focus', function() {
            scanInput.focus();
        });
    </script>
@endsection

