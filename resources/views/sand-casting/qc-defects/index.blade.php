@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between">
        <div class="flex flex-col">
            <h1 class="text-base font-bold text-slate-800 leading-tight flex items-center gap-2">
                <i class="fas fa-microscope text-blue-600"></i>
                VERIFIKASI KERUSAKAN (QC)
            </h1>
            <p class="text-slate-500 text-[11px]">Verifikasi detail defect Sand Casting sebelum proses dilanjutkan.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('sand-casting.kanban.index') }}" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold px-2.5 py-1 rounded-lg text-xs flex items-center gap-1.5 shadow-xs transition">
                <i class="fas fa-columns text-indigo-500"></i>
                Kanban Floor
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="flex flex-col gap-2.5">

        @if(session('success'))
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-3 py-2 rounded-lg flex items-center shadow-xs text-xs">
                <i class="fas fa-check-circle mr-2 text-emerald-500 text-sm shrink-0"></i>
                <span class="font-medium">{{ session('success') }}</span>
            </div>
        @endif

        @if(session('error'))
            <div class="bg-red-50 border border-red-200 text-red-800 px-3 py-2 rounded-lg flex items-center shadow-xs text-xs">
                <i class="fas fa-exclamation-circle mr-2 text-red-500 text-sm shrink-0"></i>
                <span class="font-medium">{{ session('error') }}</span>
            </div>
        @endif

        <!-- 1. COMPACT STATUS LINE (BLUE QC THEME) -->
        <div class="bg-white px-3.5 py-2 rounded-lg border border-slate-200 shadow-xs flex flex-wrap items-center justify-between gap-2 text-xs text-slate-600">
            <div class="flex items-center gap-2 flex-wrap">
                <span class="inline-flex items-center gap-1 text-slate-800">
                    <span class="w-2 h-2 rounded-full bg-blue-600"></span>
                    <strong class="font-black text-slate-900">{{ number_format($summary['waiting_qc_count'] ?? 0) }} KTR</strong> menunggu verifikasi
                </span>
                <span class="text-slate-300">·</span>
                <span class="inline-flex items-center gap-1 text-slate-800">
                    <strong class="font-black text-blue-700">{{ number_format($summary['waiting_qc_defect_pcs'] ?? 0) }} PCS</strong> defect
                </span>
                <span class="text-slate-300">·</span>
                <span class="text-slate-500">
                    Terverifikasi hari ini: <span class="font-semibold text-slate-700">{{ number_format($summary['today_verified_count'] ?? 0) }}</span>
                </span>
            </div>
            <div class="text-[11px] text-slate-400 font-medium">
                <i class="fas fa-sort-amount-down-alt mr-1 text-blue-600"></i>FIFO · Terlama &rarr; Terbaru
            </div>
        </div>

        <!-- 2. STAGE TABS (THIN & SLIM WITH BLUE ACTIVE ACCENT) -->
        <div class="bg-white border-b border-slate-200 px-1 rounded-t-lg shadow-xs flex items-center justify-between gap-2">
            <nav class="flex space-x-1 overflow-x-auto py-1" aria-label="Tabs">
                @foreach($stages as $stageKey)
                    @php
                        $isActive = ($activeTab === $stageKey);
                        $count = count($queues[$stageKey] ?? []);
                        $stageLabel = $stageLabels[$stageKey] ?? strtoupper(str_replace('_', ' ', $stageKey));
                    @endphp
                    <button type="button"
                        onclick="switchTab('{{ $stageKey }}')"
                        id="tab-btn-{{ $stageKey }}"
                        class="tab-button whitespace-nowrap py-1.5 px-3 border-b-2 font-bold text-xs flex items-center gap-1.5 transition-all {{ $isActive ? 'border-blue-600 text-blue-600 bg-blue-50/60 rounded-t' : 'border-transparent text-slate-500 hover:text-slate-800 hover:border-slate-300' }}">
                        <span>{{ $stageLabel }}</span>
                        <span class="inline-flex items-center justify-center px-1.5 py-0.2 text-[10px] font-black rounded-full {{ $isActive ? 'bg-blue-600 text-white' : ($count > 0 ? 'bg-blue-100 text-blue-800 font-bold' : 'bg-slate-100 text-slate-400 font-normal') }}">
                            {{ $count }}
                        </span>
                    </button>
                @endforeach
            </nav>
        </div>

        <!-- 3. COMPACT SEARCH / FILTER BAR -->
        <div class="bg-white px-3 py-1.5 rounded-b-lg border border-t-0 border-slate-200 shadow-xs flex items-center justify-between gap-2">
            <form method="GET" action="{{ route('sand-casting.qc-defects.index') }}" class="w-full flex items-center gap-2">
                <input type="hidden" name="tab" id="filterTabInput" value="{{ $activeTab }}">
                
                <div class="relative flex-1 max-w-md">
                    <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none text-slate-400 text-xs">
                        <i class="fas fa-search"></i>
                    </div>
                    <input type="text" name="search" value="{{ $search }}"
                        class="block w-full pl-7 pr-3 py-1 bg-slate-50 border border-slate-200 text-slate-700 rounded-md focus:ring-blue-500 focus:border-blue-500 text-xs placeholder:text-slate-400"
                        placeholder="Cari KTR / Heat / Produk / Checkpoint...">
                </div>

                <button type="submit" class="bg-slate-800 hover:bg-slate-900 text-white font-bold text-xs px-3 py-1 rounded-md shadow-xs transition flex items-center gap-1">
                    Filter
                </button>

                @if($search)
                    <a href="{{ route('sand-casting.qc-defects.index', ['tab' => $activeTab]) }}" class="bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold text-xs px-2.5 py-1 rounded-md transition" title="Reset Search">
                        Reset
                    </a>
                @endif
            </form>
        </div>

        <!-- 4. MAIN EXCEL-LIKE WORK QUEUE TABLE (80-85% VIEWPORT HEIGHT) -->
        <div class="bg-white rounded-lg border border-slate-200 shadow-xs overflow-hidden flex flex-col">
            @foreach($stages as $stageKey)
                @php
                    $stageItems = $queues[$stageKey] ?? [];
                    $stageLabel = $stageLabels[$stageKey] ?? strtoupper(str_replace('_', ' ', $stageKey));
                @endphp
                <div id="pane-{{ $stageKey }}" class="tab-pane {{ $activeTab === $stageKey ? '' : 'hidden' }}">
                    
                    @if(empty($stageItems))
                        <div class="p-12 text-center">
                            <div class="w-12 h-12 bg-slate-100 text-slate-400 rounded-full flex items-center justify-center mx-auto mb-2 text-xl">
                                <i class="fas fa-clipboard-check text-blue-500"></i>
                            </div>
                            <h3 class="text-sm font-bold text-slate-700">Semua Kerusakan di Tahap {{ $stageLabel }} Terverifikasi</h3>
                            <p class="text-xs text-slate-400 mt-0.5">
                                Tidak ada KTR yang sedang menunggu verifikasi detail defect oleh Admin QC pada tahap ini.
                            </p>
                        </div>
                    @else
                        <!-- Sticky Table Container -->
                        <div class="overflow-x-auto max-h-[calc(100vh-250px)] min-h-[400px]">
                            <table class="w-full text-left text-xs border-collapse">
                                <thead class="bg-slate-100 text-slate-700 font-bold sticky top-0 z-10 border-b border-slate-200 text-[11px] uppercase tracking-wider select-none shadow-xs">
                                    <tr>
                                        <th class="py-2.5 px-3 text-center w-12 bg-slate-100">#</th>
                                        <th class="py-2.5 px-3 bg-slate-100">KTR</th>
                                        <th class="py-2.5 px-3 bg-slate-100">Produk</th>
                                        <th class="py-2.5 px-3 bg-slate-100">Heat</th>
                                        <th class="py-2.5 px-3 bg-slate-100">Checkpoint</th>
                                        <th class="py-2.5 px-3 text-right bg-slate-100">Hasil</th>
                                        <th class="py-2.5 px-3 text-right bg-slate-100">Defect</th>
                                        <th class="py-2.5 px-3 text-right bg-slate-100">Good</th>
                                        <th class="py-2.5 px-3 text-center bg-slate-100 w-32">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 text-slate-700">
                                    @foreach($stageItems as $index => $card)
                                        <tr class="hover:bg-blue-50/40 transition-colors {{ $index === 0 ? 'bg-blue-50/20' : '' }}">
                                            <!-- FIFO Position Number (Display Only) -->
                                            <td class="py-2 px-3 text-center font-bold text-slate-500 bg-slate-50/50">
                                                {{ $index + 1 }}
                                            </td>

                                            <!-- KTR + Aging Badge -->
                                            <td class="py-2 px-3 whitespace-nowrap">
                                                <div class="flex items-center gap-1.5">
                                                    <span class="font-mono font-bold text-slate-900 bg-slate-100 px-1.5 py-0.5 rounded text-[11px]">
                                                        {{ $card['traveler_number'] }}
                                                    </span>
                                                    @if(!empty($card['aging']['stage_aging_label']))
                                                        <span class="text-[10px] font-bold px-1 py-0.2 bg-blue-50 text-blue-700 rounded border border-blue-200" title="Aging proses">
                                                            {{ $card['aging']['stage_aging_label'] }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </td>

                                            <!-- Item Name & Code & Customer -->
                                            <td class="py-2 px-3">
                                                <div class="font-bold text-slate-800 line-clamp-1 max-w-[240px]" title="{{ $card['item_name'] }}">
                                                    {{ $card['item_name'] ?? 'Item Tanpa Nama' }}
                                                </div>
                                                <div class="text-[10px] text-slate-400 font-mono flex items-center gap-1">
                                                    <span>{{ $card['item_code'] ?? $card['production_code'] ?? '-' }}</span>
                                                    @if($card['customer'])
                                                        <span class="text-slate-300">·</span>
                                                        <span class="text-slate-500">{{ $card['customer'] }}</span>
                                                    @endif
                                                </div>
                                            </td>

                                            <!-- Heat Number -->
                                            <td class="py-2 px-3 font-mono font-semibold text-blue-700 whitespace-nowrap">
                                                {{ $card['heat_number'] ?? '-' }}
                                            </td>

                                            <!-- Checkpoint Code + Line -->
                                            <td class="py-2 px-3 whitespace-nowrap">
                                                <div class="flex items-center gap-1">
                                                    <span class="font-mono font-bold text-[10px] bg-slate-100 text-slate-700 px-1.5 py-0.5 rounded border border-slate-200">
                                                        {{ $card['checkpoint_code'] }}
                                                    </span>
                                                    @if(!empty($card['line_number']))
                                                        <span class="text-[9px] font-bold bg-blue-50 text-blue-700 px-1 py-0.2 rounded border border-blue-200">
                                                            L{{ $card['line_number'] }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </td>

                                            <!-- Hasil Perpindahan (Input Qty) -->
                                            <td class="py-2 px-3 text-right font-semibold text-slate-800 whitespace-nowrap text-xs">
                                                {{ number_format($card['input_qty']) }} <span class="text-[10px] font-normal text-slate-400">pcs</span>
                                            </td>

                                            <!-- Total Defect PPIC -->
                                            <td class="py-2 px-3 text-right whitespace-nowrap font-black {{ $card['defect_qty'] > 0 ? 'text-red-600' : 'text-slate-500' }}">
                                                {{ number_format($card['defect_qty']) }} <span class="text-[10px] font-normal text-slate-400">pcs</span>
                                            </td>

                                            <!-- Good Qty -->
                                            <td class="py-2 px-3 text-right font-black text-emerald-600 whitespace-nowrap">
                                                {{ number_format($card['good_qty']) }} <span class="text-[10px] font-normal text-slate-400">pcs</span>
                                            </td>

                                            <!-- Aksi Button (Blue QC Action) -->
                                            <td class="py-2 px-3 text-center whitespace-nowrap">
                                                <button type="button"
                                                    onclick="openQcModal({{ json_encode($card) }})"
                                                    class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-3 py-1.5 rounded-md text-xs shadow-xs transition inline-flex items-center gap-1">
                                                    <i class="fas fa-check-double text-[10px]"></i>
                                                    VERIFIKASI
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                </div>
            @endforeach
        </div>

    </div>

    <!-- 5. MODAL VERIFIKASI QC -->
    <div id="qcModal" tabindex="-1" aria-hidden="true"
        class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 h-modal md:h-full bg-slate-900/60 backdrop-blur-xs">
        <div class="relative p-4 w-full max-w-lg max-h-full">
            <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 overflow-hidden">
                
                <!-- Modal Header -->
                <div class="flex items-center justify-between p-3.5 px-4 border-b border-slate-200 bg-slate-50">
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-xs">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                        <div>
                            <h3 class="text-xs font-bold text-slate-800">Verifikasi Detail Defect (QC)</h3>
                            <p class="text-[10px] text-slate-400">Klasifikasikan rincian defect sebelum konfirmasi final.</p>
                        </div>
                    </div>
                    <button type="button" onclick="closeQcModal()"
                        class="text-slate-400 hover:text-slate-600 bg-transparent hover:bg-slate-200 rounded-lg text-xs w-6 h-6 inline-flex justify-center items-center">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <!-- Modal Form -->
                <form id="qcForm" method="POST" action="">
                    @csrf
                    <div class="p-4 space-y-3 text-xs">
                        
                        <!-- Readonly Info Banner -->
                        <div class="bg-slate-50 p-3 rounded-lg border border-slate-200 text-xs space-y-1.5">
                            <div class="flex justify-between items-center">
                                <span class="text-slate-500">No. KTR:</span>
                                <span id="modalKtr" class="font-mono font-bold text-slate-800"></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-slate-500">Heat Number:</span>
                                <span id="modalHeat" class="font-mono font-semibold text-blue-700"></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-slate-500">Nama Produk:</span>
                                <span id="modalItemName" class="font-bold text-slate-800 text-right truncate max-w-[200px]"></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-slate-500">Checkpoint:</span>
                                <span id="modalCheckpoint" class="font-mono font-bold text-blue-700 bg-blue-50 px-1.5 py-0.2 rounded border border-blue-100"></span>
                            </div>
                            <div class="grid grid-cols-3 gap-2 pt-2 border-t border-slate-200 text-center">
                                <div class="bg-white p-1.5 rounded border border-slate-200">
                                    <div class="text-[10px] text-slate-400">Hasil (Input)</div>
                                    <div id="modalInputQty" class="font-black text-slate-800 text-xs"></div>
                                </div>
                                <div class="bg-red-50 p-1.5 rounded border border-red-200">
                                    <div class="text-[10px] text-red-500 font-bold">Total Defect PPIC</div>
                                    <div id="modalDefectQty" class="font-black text-red-600 text-xs"></div>
                                </div>
                                <div class="bg-emerald-50 p-1.5 rounded border border-emerald-200">
                                    <div class="text-[10px] text-emerald-600 font-bold">Good Qty</div>
                                    <div id="modalGoodQty" class="font-black text-emerald-600 text-xs"></div>
                                </div>
                            </div>
                        </div>

                        <!-- ZERO DEFECT SECTION -->
                        <div id="zeroDefectSection" class="hidden bg-emerald-50 border border-emerald-200 rounded-lg p-3 text-center space-y-1">
                            <div class="font-bold text-emerald-800 text-xs flex items-center justify-center gap-1.5">
                                <i class="fas fa-check-circle text-emerald-500"></i>
                                TOTAL DEFECT = 0 PCS
                            </div>
                            <p class="text-[11px] text-emerald-700">
                                Tidak diperlukan klasifikasi rincian cacat. KTR dapat langsung diverifikasi dan diteruskan ke proses berikutnya.
                            </p>
                        </div>

                        <!-- BREAKDOWN DEFECT SECTION (IF DEFECT > 0) -->
                        <div id="breakdownSection" class="space-y-2">
                            <div class="flex items-center justify-between">
                                <label class="text-xs font-bold text-slate-700">
                                    Rincian Klasifikasi Defect <span class="text-red-500">*</span>
                                </label>
                                <button type="button" onclick="addDefectRow()"
                                    class="text-blue-600 hover:text-blue-800 bg-blue-50 hover:bg-blue-100 px-2 py-1 rounded text-[11px] font-bold transition flex items-center gap-1">
                                    <i class="fas fa-plus-circle"></i> Tambah Baris
                                </button>
                            </div>

                            <!-- Dynamic Defect Rows Container -->
                            <div id="defectRowsContainer" class="space-y-1.5 max-h-48 overflow-y-auto pr-0.5">
                                <!-- Dynamic JS rows will be inserted here -->
                            </div>

                            <!-- Live Breakdown Match Indicator -->
                            <div class="bg-slate-100 p-2.5 rounded-lg border border-slate-200 flex items-center justify-between text-xs">
                                <div>
                                    <span class="text-slate-500">Alokasi Rincian:</span>
                                    <span id="allocatedCountDisplay" class="font-black ml-1 text-sm">0 / 0 PCS</span>
                                </div>
                                <div id="allocatedStatusBadge" class="text-[10px] font-bold px-2 py-0.5 rounded">
                                    Belum Lengkap
                                </div>
                            </div>
                        </div>

                        <!-- Catatan QC (Optional) -->
                        <div>
                            <label for="qc_notes" class="block text-xs font-bold text-slate-700 mb-1">
                                Catatan Verifikasi QC (Opsional)
                            </label>
                            <textarea name="notes" id="qc_notes" rows="2"
                                class="w-full text-xs p-2 bg-white border border-slate-300 text-slate-800 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                                placeholder="Tambahkan keterangan temuan QC jika diperlukan..."></textarea>
                        </div>

                    </div>

                    <!-- Modal Footer -->
                    <div class="flex items-center justify-end gap-2 p-3 px-4 border-t border-slate-200 bg-slate-50 rounded-b-xl">
                        <button type="button" onclick="closeQcModal()"
                            class="px-3.5 py-1.5 text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-md hover:bg-slate-100 transition">
                            Batal
                        </button>
                        <button type="submit" id="submitQcBtn"
                            class="px-4 py-1.5 text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 rounded-md shadow-xs transition flex items-center gap-1">
                            <i class="fas fa-check-double"></i>
                            <span id="submitBtnText">Konfirmasi Verifikasi</span>
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>

    <!-- 6. JAVASCRIPT LOGIC -->
    <script>
        const defectTypesMap = @json($defectTypesMap);
        let currentTargetDefectQty = 0;
        let currentStageKey = '{{ $activeTab }}';

        function switchTab(stageKey) {
            currentStageKey = stageKey;

            // Update active tab buttons
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('border-blue-600', 'text-blue-600', 'bg-blue-50/60', 'rounded-t');
                btn.classList.add('border-transparent', 'text-slate-500');
            });
            const activeBtn = document.getElementById('tab-btn-' + stageKey);
            if (activeBtn) {
                activeBtn.classList.remove('border-transparent', 'text-slate-500');
                activeBtn.classList.add('border-blue-600', 'text-blue-600', 'bg-blue-50/60', 'rounded-t');
            }

            // Update tab panes
            document.querySelectorAll('.tab-pane').forEach(pane => {
                pane.classList.add('hidden');
            });
            const activePane = document.getElementById('pane-' + stageKey);
            if (activePane) {
                activePane.classList.remove('hidden');
            }

            // Update hidden filter input
            const filterInput = document.getElementById('filterTabInput');
            if (filterInput) {
                filterInput.value = stageKey;
            }

            // Update URL query string without reloading page
            const url = new URL(window.location);
            url.searchParams.set('tab', stageKey);
            window.history.replaceState({}, '', url);
        }

        function openQcModal(card) {
            const modal = document.getElementById('qcModal');
            const form = document.getElementById('qcForm');

            currentTargetDefectQty = parseInt(card.defect_qty) || 0;
            currentStageKey = card.stage || 'netto';

            // Populate readonly details
            document.getElementById('modalKtr').textContent = card.traveler_number || '-';
            document.getElementById('modalHeat').textContent = card.heat_number || '-';
            document.getElementById('modalItemName').textContent = card.item_name || '-';
            document.getElementById('modalCheckpoint').textContent = card.checkpoint_code || '-';
            document.getElementById('modalInputQty').textContent = (card.input_qty || 0) + ' pcs';
            document.getElementById('modalDefectQty').textContent = currentTargetDefectQty + ' pcs';
            document.getElementById('modalGoodQty').textContent = (card.good_qty || 0) + ' pcs';
            document.getElementById('qc_notes').value = '';

            // Update action route URL
            form.action = "{{ route('sand-casting.qc-defects.verify', ':id') }}".replace(':id', card.id);

            const zeroSection = document.getElementById('zeroDefectSection');
            const breakdownSection = document.getElementById('breakdownSection');
            const submitBtn = document.getElementById('submitQcBtn');
            const submitBtnText = document.getElementById('submitBtnText');
            const container = document.getElementById('defectRowsContainer');

            container.innerHTML = '';

            if (currentTargetDefectQty === 0) {
                // Zero defect flow
                zeroSection.classList.remove('hidden');
                breakdownSection.classList.add('hidden');
                submitBtn.disabled = false;
                submitBtnText.textContent = 'Konfirmasi Verifikasi · 0 Defect';
            } else {
                // Non-zero defect flow
                zeroSection.classList.add('hidden');
                breakdownSection.classList.remove('hidden');
                submitBtnText.textContent = 'Konfirmasi Verifikasi';

                // Prepopulate with existing defect breakdown or add one empty row
                if (card.defects && card.defects.length > 0) {
                    card.defects.forEach(d => addDefectRow(d));
                } else {
                    addDefectRow();
                }

                updateTotalAllocated();
            }

            // Show modal
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeQcModal() {
            const modal = document.getElementById('qcModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function addDefectRow(data = null) {
            const container = document.getElementById('defectRowsContainer');
            const index = container.children.length;
            const availableTypes = defectTypesMap[currentStageKey] || [];

            const row = document.createElement('div');
            row.className = 'flex items-center gap-1.5 bg-slate-50 p-1.5 rounded border border-slate-200';

            let optionsHtml = '<option value="">Pilih Jenis Defect...</option>';
            availableTypes.forEach(type => {
                const selected = (data && data.defect_type_id == type.id) ? 'selected' : '';
                optionsHtml += `<option value="${type.id}" ${selected}>${type.name}</option>`;
            });

            row.innerHTML = `
                <div class="flex-1">
                    <select name="defects[${index}][defect_type_id]" class="w-full text-xs py-1 px-2 bg-white border border-slate-300 rounded focus:ring-blue-500 focus:border-blue-500" required>
                        ${optionsHtml}
                    </select>
                </div>
                <div class="w-20">
                    <input type="number" name="defects[${index}][qty]" value="${data ? data.qty : ''}" placeholder="Qty" min="1" step="1" required
                        class="w-full text-xs py-1 px-2 text-center font-bold bg-white border border-slate-300 rounded focus:ring-blue-500 focus:border-blue-500 qc-defect-qty"
                        oninput="updateTotalAllocated()">
                </div>
                <div class="w-28">
                    <input type="text" name="defects[${index}][notes]" value="${data && data.notes ? data.notes : ''}" placeholder="Ket (opsional)"
                        class="w-full text-xs py-1 px-2 bg-white border border-slate-300 rounded focus:ring-blue-500 focus:border-blue-500">
                </div>
                <button type="button" onclick="this.parentElement.remove(); updateTotalAllocated();" class="text-slate-400 hover:text-red-500 px-1 py-0.5 text-xs" title="Hapus Baris">
                    <i class="fas fa-times"></i>
                </button>
            `;

            container.appendChild(row);
            updateTotalAllocated();
        }

        function updateTotalAllocated() {
            if (currentTargetDefectQty === 0) {
                return;
            }

            const inputs = document.querySelectorAll('.qc-defect-qty');
            let total = 0;
            inputs.forEach(input => {
                const val = parseInt(input.value) || 0;
                total += val;
            });

            const display = document.getElementById('allocatedCountDisplay');
            const badge = document.getElementById('allocatedStatusBadge');
            const submitBtn = document.getElementById('submitQcBtn');

            display.textContent = total + ' / ' + currentTargetDefectQty + ' PCS';

            if (total === currentTargetDefectQty) {
                display.className = 'font-black ml-1 text-sm text-emerald-600';
                badge.textContent = '✓ Sesuai Target';
                badge.className = 'text-[10px] font-bold px-2 py-0.5 rounded bg-emerald-100 text-emerald-700';
                submitBtn.disabled = false;
            } else if (total > currentTargetDefectQty) {
                display.className = 'font-black ml-1 text-sm text-red-600';
                badge.textContent = 'Melebihi Target (' + (total - currentTargetDefectQty) + ' pcs)';
                badge.className = 'text-[10px] font-bold px-2 py-0.5 rounded bg-red-100 text-red-700';
                submitBtn.disabled = true;
            } else {
                display.className = 'font-black ml-1 text-sm text-amber-600';
                badge.textContent = 'Kurang ' + (currentTargetDefectQty - total) + ' pcs';
                badge.className = 'text-[10px] font-bold px-2 py-0.5 rounded bg-amber-100 text-amber-700';
                submitBtn.disabled = true;
            }
        }

        // Close modal on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeQcModal();
            }
        });
    </script>
@endsection
