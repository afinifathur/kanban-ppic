@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">LOST WAX &mdash; SCAN LAPISAN</h1>
            <p class="text-gray-500 text-[10px]">Scan barcode tree untuk mencatat tahapan coating</p>
        </div>
    </div>
@endsection

@section('content')
    <div class="max-w-2xl mx-auto space-y-6">
        {{-- Scan Area --}}
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-8 text-center">
            <div class="mb-6">
                <div id="statusIndicator" class="text-lg font-bold text-slate-500">
                    <i class="fas fa-qrcode mr-2"></i> SIAP SCAN
                </div>
            </div>

            <form id="scanForm" autocomplete="off" class="mb-6">
                <input
                    type="text"
                    id="scanInput"
                    name="barcode"
                    class="w-full text-center text-2xl font-mono tracking-widest py-4 rounded-xl border-2 border-slate-300 focus:border-amber-500 focus:ring-amber-500 outline-none"
                    placeholder="SCAN BARCODE"
                    autofocus
                    autocomplete="off"
                >
            </form>

            <div id="resultArea" class="hidden space-y-4"></div>
        </div>

        {{-- Last Tree Info --}}
        <div id="lastTreeInfo" class="bg-slate-900 text-white rounded-xl shadow-sm border border-slate-700 p-6 hidden">
            <h2 class="text-xs font-semibold text-slate-400 uppercase mb-4">Tree Terakhir</h2>
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
                    <div class="text-slate-400 text-xs">Posisi</div>
                    <div id="lastPosition" class="font-bold">-</div>
                </div>
                <div>
                    <div class="text-slate-400 text-xs">Scan Berikutnya</div>
                    <div id="lastNextStage" class="font-bold">-</div>
                </div>
            </div>
            <div id="lastAgingSection" class="mt-4 pt-4 border-t border-slate-700 hidden">
                <div class="flex justify-between text-sm">
                    <span class="text-slate-400">Interval terakhir</span>
                    <span id="lastAging" class="font-bold"></span>
                </div>
                <div class="flex justify-between text-sm mt-1">
                    <span class="text-slate-400">Status</span>
                    <span id="lastAgingStatus" class="font-bold"></span>
                </div>
            </div>
        </div>

        {{-- Error Area --}}
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
            statusIndicator.className = 'text-lg font-bold text-amber-600';

            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            fetch('{{ route('lost-wax.scan.process') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ barcode: barcode }),
            })
                .then(r => r.json())
                .then(data => {
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
            const ev = data.event || {};

            resultArea.classList.remove('hidden');
            resultArea.innerHTML = `
                <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-4">
                    <div class="text-emerald-600 font-bold text-xl mb-2">
                        <i class="fas fa-check-circle mr-2"></i> BERHASIL
                    </div>
                    <div class="text-lg font-mono font-bold text-emerald-800">${barcode}</div>
                    <div class="text-emerald-700 mt-2">
                        <span class="text-2xl font-bold">&rarr; ${data.stage_label || '-'}</span>
                    </div>
                    <div class="text-sm text-emerald-600 mt-2">
                        Time: ${new Date().toLocaleTimeString('id-ID')}
                    </div>
                    ${data.aging_label ? `
                        <div class="text-sm text-emerald-600">Aging: ${data.aging_label}</div>
                        <div class="text-sm font-bold ${
                            data.aging_status === 'normal' ? 'text-emerald-700' :
                            data.aging_status === 'too_fast' ? 'text-amber-700' : 'text-red-700'
                        }">Status: ${agingStatusLabel(data.aging_status)}</div>
                    ` : ''}
                </div>
            `;

            statusIndicator.innerHTML = '<i class="fas fa-check-circle mr-2 text-emerald-500"></i> SCAN BERHASIL';
            statusIndicator.className = 'text-lg font-bold text-emerald-600';

            lastTreeInfo.classList.remove('hidden');
            document.getElementById('lastBarcode').textContent = barcode;
            document.getElementById('lastProduct').textContent = (ti.item_code || '-') + ' — ' + (ti.item_name || '-');
            document.getElementById('lastPosition').textContent = data.stage_label || (ti.current_stage_label || '-');
            document.getElementById('lastNextStage').textContent = data.next_stage
                ? (data.next_stage_label || data.next_stage)
                : 'SELESAI';

            if (data.aging_label) {
                document.getElementById('lastAgingSection').classList.remove('hidden');
                document.getElementById('lastAging').textContent = data.aging_label;
                document.getElementById('lastAgingStatus').textContent = agingStatusLabel(data.aging_status);
                document.getElementById('lastAgingStatus').className = 'font-bold ' + (
                    data.aging_status === 'normal' ? 'text-emerald-400' :
                    data.aging_status === 'too_fast' ? 'text-amber-400' : 'text-red-400'
                );
            } else {
                document.getElementById('lastAgingSection').classList.add('hidden');
            }

            // Update last-next-stage after the success display
            fetchNextStageLabel(data.next_stage);

            setTimeout(() => {
                statusIndicator.innerHTML = '<i class="fas fa-qrcode mr-2"></i> SIAP SCAN';
                statusIndicator.className = 'text-lg font-bold text-slate-500';
            }, 3000);
        }

        function fetchNextStageLabel(nextStage) {
            if (nextStage) {
                fetch('/lost-wax/stage-label', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ stage: nextStage }),
                })
                    .then(r => r.json())
                    .then(d => {
                        if (d.label) {
                            document.getElementById('lastNextStage').textContent = d.label;
                        }
                    })
                    .catch(() => {});
            } else {
                document.getElementById('lastNextStage').textContent = 'SELESAI';
            }
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
                document.getElementById('lastNextStage').textContent = '-';
                document.getElementById('lastAgingSection').classList.add('hidden');
            }

            setTimeout(() => {
                statusIndicator.innerHTML = '<i class="fas fa-qrcode mr-2"></i> SIAP SCAN';
                statusIndicator.className = 'text-lg font-bold text-slate-500';
                errorArea.classList.add('hidden');
            }, 4000);
        }

        function agingStatusLabel(status) {
            const map = {
                'normal': '\u2705 NORMAL',
                'too_fast': '\u26A0\uFE0F TERLALU CEPAT',
                'too_long': '\u274C TERLALU LAMA',
            };
            return map[status] || (status || '-').toUpperCase();
        }
    </script>
@endsection
