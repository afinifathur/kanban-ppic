@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">LOST WAX &mdash; SCAN OVEN</h1>
            <p class="text-gray-500 text-[10px]">Scan barcode Tree saat rak masuk Oven</p>
        </div>
    </div>
@endsection

@section('content')
    <div class="max-w-2xl mx-auto space-y-6">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-8 text-center">
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
                    class="w-full text-center text-2xl font-mono tracking-widest py-4 rounded-xl border-2 border-slate-300 focus:border-orange-500 focus:ring-orange-500 outline-none"
                    placeholder="SCAN BARCODE OVEN"
                    autofocus
                    autocomplete="off"
                >
            </form>

            <div id="resultArea" class="hidden space-y-4"></div>
        </div>

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

        <div id="errorArea" class="hidden bg-red-50 border border-red-200 rounded-xl p-6 text-center">
            <div class="text-red-600 font-bold text-lg mb-2">
                <i class="fas fa-times-circle mr-2"></i> SCAN DITOLAK
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

        let processing = false;

        scanInput.focus();

        document.addEventListener('click', function () {
            scanInput.focus();
        });

        function handleSessionRecovery(barcode) {
            const RELOAD_GUARD_KEY = 'scanner_oven_last_reload';
            const now = Date.now();
            const lastReload = parseInt(sessionStorage.getItem(RELOAD_GUARD_KEY) || '0', 10);

            // Avoid rapid reload loop if server is down or unauthenticated (throttle: 30s)
            if (now - lastReload < 30000) {
                showError({ reason: 'Sesi kedaluwarsa atau otentikasi gagal. Silakan muat ulang halaman atau login kembali.' }, barcode);
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
            }).catch(() => {
                // Silently ignore temporary network blips
            });
        }, KEEPALIVE_INTERVAL);

        scanForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (processing) return;

            const barcode = scanInput.value.trim();
            if (!barcode) return;

            processing = true;
            errorArea.classList.add('hidden');
            resultArea.classList.add('hidden');
            resultArea.innerHTML = '';
            statusIndicator.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> MEMPROSES...';
            statusIndicator.className = 'text-lg font-bold text-orange-600';

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
                .then(r => {
                    if (r.status === 419 || r.status === 401) {
                        handleSessionRecovery(barcode);
                        return null;
                    }
                    return r.json();
                })
                .then(data => {
                    if (!data) return;
                    if (data.success) {
                        showSuccess(data, barcode);
                    } else {
                        showError(data, barcode);
                    }
                })
                .catch(err => {
                    showError({ reason: 'Gagal terhubung ke server.' }, barcode);
                })
                .finally(() => {
                    processing = false;
                    scanInput.value = '';
                    scanInput.focus();
                });
        });

        function showSuccess(data, barcode) {
            const ti = data.tree_info || data.tree || {};

            resultArea.classList.remove('hidden');
            resultArea.innerHTML = `
                <div class="bg-orange-50 border border-orange-200 rounded-lg p-4">
                    <div class="text-orange-700 font-bold text-xl mb-2">
                        <i class="fas fa-check-circle mr-2"></i> BERHASIL
                    </div>
                    <div class="text-lg font-mono font-bold text-orange-800">${barcode}</div>
                    <div class="text-orange-700 mt-2 text-sm">
                        Posisi terakhir: <span class="font-bold">${ti.current_stage_label || '-'}</span>
                    </div>
                    <div class="text-orange-700 text-sm">
                        Quantity: <span class="font-bold">${ti.quantity || '-'} PCS</span>
                    </div>
                    <div class="mt-3 text-2xl font-bold text-orange-800">
                        &rarr; OVEN
                    </div>
                    <div class="text-lg font-bold text-orange-800">FINAL</div>
                    <div class="text-sm text-orange-600 mt-2">
                        Time: ${new Date().toLocaleTimeString('id-ID')}
                    </div>
                    ${data.aging_label ? `
                        <div class="text-sm text-orange-600">Aging: ${data.aging_label}</div>
                    ` : ''}
                </div>
            `;

            statusIndicator.innerHTML = '<i class="fas fa-check-circle mr-2 text-orange-500"></i> SCAN BERHASIL';
            statusIndicator.className = 'text-lg font-bold text-orange-700';

            lastTreeInfo.classList.remove('hidden');
            document.getElementById('lastBarcode').textContent = barcode;
            document.getElementById('lastProduct').textContent = (ti.item_code || '-') + ' — ' + (ti.item_name || '-');
            document.getElementById('lastPosition').textContent = ti.current_stage_label || '-';
            document.getElementById('lastQuantity').textContent = (ti.quantity || '-') + ' PCS';

            document.getElementById('lastResultSection').classList.remove('hidden');
            document.getElementById('lastResult').textContent = '\u2192 OVEN \u2014 FINAL';
            document.getElementById('lastResult').className = 'font-bold text-orange-400';

            setTimeout(() => {
                statusIndicator.innerHTML = '<i class="fas fa-fire mr-2"></i> SIAP SCAN OVEN';
                statusIndicator.className = 'text-lg font-bold text-slate-500';
            }, 3000);
        }

        function showError(data, barcode) {
            errorArea.classList.remove('hidden');
            document.getElementById('errorReason').textContent = data.reason || 'Scan tidak valid.';

            statusIndicator.innerHTML = '<i class="fas fa-times-circle mr-2 text-red-500"></i> SCAN DITOLAK';
            statusIndicator.className = 'text-lg font-bold text-red-600';

            if (data.tree_info || data.tree) {
                const ti = data.tree_info || data.tree;
                lastTreeInfo.classList.remove('hidden');
                document.getElementById('lastBarcode').textContent = barcode;
                document.getElementById('lastProduct').textContent = (ti.item_code || '-') + ' — ' + (ti.item_name || '-');
                document.getElementById('lastPosition').textContent = ti.current_stage_label || '-';
                document.getElementById('lastQuantity').textContent = (ti.quantity || '-') + ' PCS';
                document.getElementById('lastResultSection').classList.add('hidden');
            }

            setTimeout(() => {
                statusIndicator.innerHTML = '<i class="fas fa-fire mr-2"></i> SIAP SCAN OVEN';
                statusIndicator.className = 'text-lg font-bold text-slate-500';
                errorArea.classList.add('hidden');
            }, 4000);
        }
    </script>
@endsection
