@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between">
        <div class="flex flex-col">
            <h1 class="text-base font-bold text-slate-800 leading-tight flex items-center gap-2">
                <i class="fas fa-tools text-amber-500"></i>
                Pencatatan Kerusakan (PPIC)
            </h1>
            <p class="text-slate-500 text-[11px]">Total defect sebelum verifikasi QC</p>
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

        <!-- 1. COMPACT STATUS LINE (REPLACES 3 BIG CARDS) -->
        <div class="bg-white px-3.5 py-2 rounded-lg border border-slate-200 shadow-xs flex flex-wrap items-center justify-between gap-2 text-xs text-slate-600">
            <div class="flex items-center gap-2 flex-wrap">
                <span class="inline-flex items-center gap-1 text-slate-800">
                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                    <strong class="font-black text-slate-900">{{ number_format($summary['waiting_defect_count'] ?? 0) }} KTR</strong> belum dicatat
                </span>
                <span class="text-slate-300">·</span>
                <span class="inline-flex items-center gap-1 text-slate-800">
                    <strong class="font-black text-slate-900">{{ number_format($summary['waiting_defect_pcs'] ?? 0) }} PCS</strong> menunggu
                </span>
                <span class="text-slate-300">·</span>
                <span class="text-slate-500">
                    Incoming {{ number_format($summary['today_incoming_count'] ?? 0) }}
                </span>
            </div>
            <div class="text-[11px] text-slate-400 font-medium">
                <i class="fas fa-sort-amount-down-alt mr-1 text-amber-500"></i>FIFO · Terlama &rarr; Terbaru
            </div>
        </div>

        <!-- 2. STAGE TABS (THIN & SLIM) -->
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
                        class="tab-button whitespace-nowrap py-1.5 px-3 border-b-2 font-bold text-xs flex items-center gap-1.5 transition-all {{ $isActive ? 'border-amber-500 text-amber-600 bg-amber-50/60 rounded-t' : 'border-transparent text-slate-500 hover:text-slate-800 hover:border-slate-300' }}">
                        <span>{{ $stageLabel }}</span>
                        <span class="inline-flex items-center justify-center px-1.5 py-0.2 text-[10px] font-black rounded-full {{ $isActive ? 'bg-amber-500 text-white' : ($count > 0 ? 'bg-slate-200 text-slate-800 font-bold' : 'bg-slate-100 text-slate-400 font-normal') }}">
                            {{ $count }}
                        </span>
                    </button>
                @endforeach
            </nav>
        </div>

        <!-- 3. COMPACT SEARCH / FILTER BAR -->
        <div class="bg-white px-3 py-1.5 rounded-b-lg border border-t-0 border-slate-200 shadow-xs flex items-center justify-between gap-2">
            <form method="GET" action="{{ route('sand-casting.defects.index') }}" class="w-full flex items-center gap-2">
                <input type="hidden" name="tab" id="filterTabInput" value="{{ $activeTab }}">
                
                <div class="relative flex-1 max-w-md">
                    <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none text-slate-400 text-xs">
                        <i class="fas fa-search"></i>
                    </div>
                    <input type="text" name="search" value="{{ $search }}"
                        class="block w-full pl-7 pr-3 py-1 bg-slate-50 border border-slate-200 text-slate-700 rounded-md focus:ring-amber-500 focus:border-amber-500 text-xs placeholder:text-slate-400"
                        placeholder="Cari KTR / Heat / Produk / Checkpoint...">
                </div>

                <button type="submit" class="bg-slate-800 hover:bg-slate-900 text-white font-bold text-xs px-3 py-1 rounded-md shadow-xs transition flex items-center gap-1">
                    Filter
                </button>

                @if($search)
                    <a href="{{ route('sand-casting.defects.index', ['tab' => $activeTab]) }}" class="bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold text-xs px-2.5 py-1 rounded-md transition" title="Reset Search">
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
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <h3 class="text-sm font-bold text-slate-700">Antrian {{ $stageLabel }} Kosong</h3>
                            <p class="text-xs text-slate-400 mt-0.5">
                                Semua proses fisik pada tahap ini sudah dicatat atau belum ada KTR yang diselesaikan SPV.
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
                                        <th class="py-2.5 px-3 text-center bg-slate-100">Rusak</th>
                                        <th class="py-2.5 px-3 text-right bg-slate-100">Good</th>
                                        <th class="py-2.5 px-3 text-center bg-slate-100 w-32">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 text-slate-700">
                                    @foreach($stageItems as $index => $card)
                                        <tr class="hover:bg-amber-50/40 transition-colors {{ $index === 0 ? 'bg-amber-50/20' : '' }}">
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
                                                        <span class="text-[10px] font-bold px-1 py-0.2 bg-amber-100 text-amber-800 rounded border border-amber-200" title="Aging proses">
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
                                            <td class="py-2 px-3 text-right font-black text-slate-900 whitespace-nowrap text-xs">
                                                {{ number_format($card['input_qty']) }} <span class="text-[10px] font-normal text-slate-400">pcs</span>
                                            </td>

                                            <!-- Rusak / Defect Status -->
                                            <td class="py-2 px-3 text-center whitespace-nowrap">
                                                @if($card['defect_state'] === 'unrecorded')
                                                    <span class="bg-amber-50 text-amber-700 border border-amber-200 font-bold px-1.5 py-0.5 rounded text-[10px] inline-flex items-center gap-1">
                                                        <i class="fas fa-exclamation-circle text-amber-500"></i>
                                                        BELUM DICATAT
                                                    </span>
                                                @elseif($card['defect_state'] === 'zero')
                                                    <span class="bg-emerald-50 text-emerald-700 border border-emerald-200 font-bold px-1.5 py-0.5 rounded text-[10px] inline-flex items-center gap-1">
                                                        <i class="fas fa-check-circle text-emerald-500"></i>
                                                        RUSAK 0 PCS
                                                    </span>
                                                @else
                                                    <span class="bg-red-50 text-red-700 border border-red-200 font-bold px-1.5 py-0.5 rounded text-[10px] inline-flex items-center gap-1">
                                                        <i class="fas fa-times-circle text-red-500"></i>
                                                        {{ $card['defect_status_label'] }}
                                                    </span>
                                                @endif
                                            </td>

                                            <!-- Good Qty -->
                                            <td class="py-2 px-3 text-right font-bold whitespace-nowrap">
                                                @if($card['status'] === \App\Models\SandCastingStageExecution::STATUS_WAITING_DEFECT)
                                                    <span class="text-slate-400">—</span>
                                                @else
                                                    <span class="text-emerald-600 font-black">{{ number_format($card['good_qty']) }}</span>
                                                    <span class="text-[10px] font-normal text-slate-400">pcs</span>
                                                @endif
                                            </td>

                                            <!-- Aksi Button -->
                                            <td class="py-2 px-3 text-center whitespace-nowrap">
                                                @if($card['status'] === \App\Models\SandCastingStageExecution::STATUS_WAITING_DEFECT)
                                                    <button type="button"
                                                        onclick="openDefectModal({{ json_encode($card) }})"
                                                        class="bg-amber-500 hover:bg-amber-600 text-white font-bold px-3 py-1.5 rounded-md text-xs shadow-xs transition inline-flex items-center gap-1">
                                                        <i class="fas fa-edit text-[10px]"></i>
                                                        CATAT RUSAK
                                                    </button>
                                                @else
                                                    <span class="text-[11px] text-indigo-600 font-medium italic">
                                                        <i class="fas fa-hourglass-half text-indigo-400 mr-0.5"></i> Menunggu QC
                                                    </span>
                                                @endif
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

    <!-- 5. MODAL CATAT RUSAK (PPIC) -->
    <div id="defectModal" tabindex="-1" aria-hidden="true"
        class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 h-modal md:h-full bg-slate-900/60 backdrop-blur-xs">
        <div class="relative p-4 w-full max-w-md max-h-full">
            <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 overflow-hidden">
                
                <!-- Modal Header -->
                <div class="flex items-center justify-between p-3.5 px-4 border-b border-slate-200 bg-slate-50">
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 rounded-lg bg-amber-100 text-amber-600 flex items-center justify-center font-bold text-xs">
                            <i class="fas fa-edit"></i>
                        </div>
                        <div>
                            <h3 class="text-xs font-bold text-slate-800">Catat Kerusakan (PPIC)</h3>
                            <p class="text-[10px] text-slate-400">Input kuantitas total rusak untuk KTR</p>
                        </div>
                    </div>
                    <button type="button" onclick="closeDefectModal()"
                        class="text-slate-400 hover:text-slate-600 bg-transparent hover:bg-slate-200 rounded-lg text-xs w-6 h-6 inline-flex justify-center items-center">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <!-- Modal Form -->
                <form id="defectForm" method="POST" action="">
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
                                <span id="modalCheckpoint" class="font-mono font-bold text-indigo-700 bg-indigo-50 px-1.5 py-0.2 rounded border border-indigo-100"></span>
                            </div>
                            <div class="flex justify-between items-center pt-1 border-t border-slate-200">
                                <span class="text-slate-700 font-bold">Hasil Perpindahan:</span>
                                <span id="modalInputQtyDisplay" class="font-black text-slate-900"></span>
                            </div>
                        </div>

                        <input type="hidden" id="modalInputQty" value="0">

                        <!-- Input Defect Qty -->
                        <div>
                            <label for="defect_qty" class="block text-xs font-bold text-slate-700 mb-1">
                                Jumlah Rusak / Defect (PCS) <span class="text-red-500">*</span>
                            </label>
                            <input type="number" name="defect_qty" id="defect_qty" min="0" step="1" required
                                class="w-full text-center text-lg font-black tracking-wider py-1.5 bg-white border border-slate-300 text-slate-800 rounded-lg focus:ring-amber-500 focus:border-amber-500"
                                placeholder="0" oninput="calculateGoodQty()">
                            <p class="text-[10px] text-slate-400 mt-0.5">
                                Masukkan 0 jika barang 100% bagus tanpa cacat.
                            </p>
                        </div>

                        <!-- Live Calculation Preview -->
                        <div class="bg-slate-100/80 p-2.5 rounded-lg border border-slate-200">
                            <div class="flex items-center justify-between text-xs mb-0.5">
                                <span class="text-slate-600 font-medium">Hasil Bagus (Good Qty):</span>
                                <span id="modalGoodQtyDisplay" class="font-black text-emerald-600 text-sm">0 PCS</span>
                            </div>
                            <div id="modalCalculationHint" class="text-[10px] text-slate-500">
                                Good Qty = Input Qty (0) - Defect Qty (0)
                            </div>
                        </div>

                        <!-- Tanggal Proses (Default physical_done_at, Editable) -->
                        <div>
                            <label for="process_date" class="block text-xs font-bold text-slate-700 mb-1">
                                Tanggal Proses
                            </label>
                            <input type="date" name="process_date" id="process_date"
                                class="w-full text-xs py-1.5 bg-white border border-slate-300 text-slate-800 rounded-lg focus:ring-amber-500 focus:border-amber-500">
                            <p class="text-[10px] text-slate-400 mt-0.5">
                                Default dari waktu selesai fisik SPV.
                            </p>
                        </div>

                        <!-- Notes (Optional) -->
                        <div>
                            <label for="notes" class="block text-xs font-bold text-slate-700 mb-1">
                                Catatan (Opsional)
                            </label>
                            <textarea name="notes" id="notes" rows="2"
                                class="w-full text-xs p-2 bg-white border border-slate-300 text-slate-800 rounded-lg focus:ring-amber-500 focus:border-amber-500"
                                placeholder="Tambahkan catatan jika diperlukan..."></textarea>
                        </div>

                    </div>

                    <!-- Modal Footer -->
                    <div class="flex items-center justify-end gap-2 p-3 px-4 border-t border-slate-200 bg-slate-50 rounded-b-xl">
                        <button type="button" onclick="closeDefectModal()"
                            class="px-3.5 py-1.5 text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-md hover:bg-slate-100 transition">
                            Batal
                        </button>
                        <button type="submit" id="submitDefectBtn"
                            class="px-4 py-1.5 text-xs font-bold text-white bg-amber-500 hover:bg-amber-600 rounded-md shadow-xs transition flex items-center gap-1">
                            <i class="fas fa-check"></i>
                            Simpan Data
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>

    <!-- 6. JAVASCRIPT LOGIC -->
    <script>
        function switchTab(stageKey) {
            // Update active tab buttons
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('border-amber-500', 'text-amber-600', 'bg-amber-50/60', 'rounded-t');
                btn.classList.add('border-transparent', 'text-slate-500');
            });
            const activeBtn = document.getElementById('tab-btn-' + stageKey);
            if (activeBtn) {
                activeBtn.classList.remove('border-transparent', 'text-slate-500');
                activeBtn.classList.add('border-amber-500', 'text-amber-600', 'bg-amber-50/60', 'rounded-t');
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

        function openDefectModal(card) {
            const modal = document.getElementById('defectModal');
            const form = document.getElementById('defectForm');

            // Populate readonly details
            document.getElementById('modalKtr').textContent = card.traveler_number || '-';
            document.getElementById('modalHeat').textContent = card.heat_number || '-';
            document.getElementById('modalItemName').textContent = card.item_name || '-';
            document.getElementById('modalCheckpoint').textContent = card.checkpoint_code || '-';
            document.getElementById('modalInputQtyDisplay').textContent = (card.input_qty || 0) + ' pcs';
            document.getElementById('modalInputQty').value = card.input_qty || 0;

            // Date default to physical_done_date
            document.getElementById('process_date').value = card.physical_done_date || new Date().toISOString().split('T')[0];
            
            // Clear inputs
            document.getElementById('defect_qty').value = '';
            document.getElementById('defect_qty').max = card.input_qty || 0;
            document.getElementById('notes').value = card.notes || '';

            // Update action route URL
            form.action = "{{ route('sand-casting.defects.record', ':id') }}".replace(':id', card.id);

            calculateGoodQty();

            // Show modal
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            setTimeout(() => {
                document.getElementById('defect_qty').focus();
            }, 100);
        }

        function closeDefectModal() {
            const modal = document.getElementById('defectModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function calculateGoodQty() {
            const inputQty = parseInt(document.getElementById('modalInputQty').value) || 0;
            const defectInput = document.getElementById('defect_qty');
            const defectVal = defectInput.value.trim();
            const defectQty = defectVal === '' ? 0 : parseInt(defectVal);
            const goodDisplay = document.getElementById('modalGoodQtyDisplay');
            const hintDisplay = document.getElementById('modalCalculationHint');
            const submitBtn = document.getElementById('submitDefectBtn');

            if (defectVal !== '' && (isNaN(defectQty) || defectQty < 0)) {
                goodDisplay.textContent = 'Invalid';
                goodDisplay.className = 'font-black text-red-600 text-sm';
                hintDisplay.textContent = 'Jumlah defect tidak boleh negatif.';
                hintDisplay.className = 'text-[10px] text-red-500 font-bold';
                submitBtn.disabled = true;
                return;
            }

            if (defectQty > inputQty) {
                goodDisplay.textContent = 'Melebihi Input';
                goodDisplay.className = 'font-black text-red-600 text-sm';
                hintDisplay.textContent = 'Jumlah defect (' + defectQty + ') melebihi input (' + inputQty + ').';
                hintDisplay.className = 'text-[10px] text-red-500 font-bold';
                submitBtn.disabled = true;
                return;
            }

            const goodQty = Math.max(0, inputQty - defectQty);
            goodDisplay.textContent = goodQty + ' PCS';
            goodDisplay.className = 'font-black text-emerald-600 text-sm';
            hintDisplay.textContent = 'Good Qty = ' + inputQty + ' - ' + (defectVal === '' ? 0 : defectQty) + ' = ' + goodQty + ' pcs';
            hintDisplay.className = 'text-[10px] text-slate-500';
            submitBtn.disabled = false;
        }

        // Close modal on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeDefectModal();
            }
        });
    </script>
@endsection
