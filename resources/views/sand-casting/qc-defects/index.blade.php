@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between">
        <div class="flex flex-col">
            <h1 class="text-base font-bold text-slate-800 leading-tight flex items-center gap-2">
                <i class="fas fa-microscope text-blue-600"></i>
                VERIFIKASI KERUSAKAN (QC)
            </h1>
            <p class="text-slate-500 text-[11px]">Verifikasi detail breakdown defect Sand Casting sebelum proses dilanjutkan.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('sand-casting.defects.index') }}" class="bg-amber-50 border border-amber-200 hover:bg-amber-100 text-amber-800 font-bold px-2.5 py-1 rounded-lg text-xs flex items-center gap-1.5 shadow-xs transition">
                <i class="fas fa-tools text-amber-500"></i>
                Pencatatan PPIC
            </a>
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
                <i class="fas fa-sort-amount-down-alt mr-1 text-blue-600"></i>FIFO Antrian · Terlama &rarr; Terbaru
            </div>
        </div>

        <!-- 2. STAGE FILTER BAR -->
        <div class="bg-white border-b border-slate-200 px-2 rounded-t-lg shadow-xs flex items-center justify-between gap-2">
            <nav class="flex space-x-1 overflow-x-auto py-1.5" aria-label="Stage Tabs">
                <a href="{{ route('sand-casting.qc-defects.index', array_merge(request()->query(), ['stage' => 'all', 'page' => 1])) }}"
                    class="whitespace-nowrap py-1.5 px-3 border-b-2 font-bold text-xs flex items-center gap-1.5 transition-all {{ empty($selectedStage) ? 'border-blue-600 text-blue-700 bg-blue-50/70 rounded-t' : 'border-transparent text-slate-500 hover:text-slate-800 hover:border-slate-300' }}">
                    <span>SEMUA TAHAP</span>
                    <span class="inline-flex items-center justify-center px-1.5 py-0.2 text-[10px] font-black rounded-full {{ empty($selectedStage) ? 'bg-blue-600 text-white' : 'bg-slate-200 text-slate-700' }}">
                        {{ $summary['waiting_qc_count'] ?? 0 }}
                    </span>
                </a>
                @foreach($stages as $stageKey)
                    @php
                        $isStgActive = ($selectedStage === $stageKey);
                        $count = $summary['stage_counts'][$stageKey] ?? 0;
                        $stageLabel = $stageLabels[$stageKey] ?? strtoupper(str_replace('_', ' ', $stageKey));
                    @endphp
                    <a href="{{ route('sand-casting.qc-defects.index', array_merge(request()->query(), ['stage' => $stageKey, 'page' => 1])) }}"
                        class="whitespace-nowrap py-1.5 px-3 border-b-2 font-bold text-xs flex items-center gap-1.5 transition-all {{ $isStgActive ? 'border-blue-600 text-blue-700 bg-blue-50/70 rounded-t' : 'border-transparent text-slate-500 hover:text-slate-800 hover:border-slate-300' }}">
                        <span>{{ $stageLabel }}</span>
                        <span class="inline-flex items-center justify-center px-1.5 py-0.2 text-[10px] font-black rounded-full {{ $isStgActive ? 'bg-blue-600 text-white' : ($count > 0 ? 'bg-blue-100 text-blue-800 font-bold' : 'bg-slate-100 text-slate-400 font-normal') }}">
                            {{ $count }}
                        </span>
                    </a>
                @endforeach
            </nav>
        </div>

        <!-- 3. COMPACT SEARCH / FILTER BAR -->
        <div class="bg-white px-3 py-2 rounded-b-lg border border-t-0 border-slate-200 shadow-xs flex items-center justify-between gap-2">
            <form method="GET" action="{{ route('sand-casting.qc-defects.index') }}" class="w-full flex items-center gap-2">
                @if($selectedStage)
                    <input type="hidden" name="stage" value="{{ $selectedStage }}">
                @endif
                
                <div class="relative flex-1 max-w-md">
                    <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none text-slate-400 text-xs">
                        <i class="fas fa-search"></i>
                    </div>
                    <input type="text" name="search" value="{{ $search }}"
                        class="block w-full pl-7 pr-3 py-1.5 bg-slate-50 border border-slate-200 text-slate-700 rounded-md focus:ring-blue-500 focus:border-blue-500 text-xs placeholder:text-slate-400"
                        placeholder="Cari KTR / Heat / Produk / Checkpoint...">
                </div>

                <button type="submit" class="bg-slate-800 hover:bg-slate-900 text-white font-bold text-xs px-3.5 py-1.5 rounded-md shadow-xs transition flex items-center gap-1">
                    <i class="fas fa-filter text-[10px]"></i>
                    Filter
                </button>

                @if($search || $selectedStage)
                    <a href="{{ route('sand-casting.qc-defects.index') }}" class="bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold text-xs px-2.5 py-1.5 rounded-md transition" title="Reset Search">
                        Reset
                    </a>
                @endif
            </form>
        </div>

        <!-- 4. MAIN EXCEL-LIKE WORK QUEUE TABLE -->
        <div class="bg-white rounded-lg border border-slate-200 shadow-xs overflow-hidden flex flex-col">
            @if($executions->isEmpty())
                <div class="p-12 text-center">
                    <div class="w-12 h-12 bg-slate-100 text-slate-400 rounded-full flex items-center justify-center mx-auto mb-2 text-xl">
                        <i class="fas fa-clipboard-check text-blue-500"></i>
                    </div>
                    <h3 class="text-sm font-bold text-slate-700">Semua Kerusakan Terverifikasi</h3>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Tidak ada KTR yang sedang menunggu verifikasi detail defect oleh Admin QC.
                    </p>
                </div>
            @else
                <!-- Table Container -->
                <div class="overflow-x-auto min-h-[350px]">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead class="bg-slate-100 text-slate-700 font-bold sticky top-0 z-10 border-b border-slate-200 text-[11px] uppercase tracking-wider select-none shadow-xs">
                            <tr>
                                <th class="py-2.5 px-3 text-center w-12 bg-slate-100">#</th>
                                <th class="py-2.5 px-3 bg-slate-100">KTR</th>
                                <th class="py-2.5 px-3 bg-slate-100">Produk</th>
                                <th class="py-2.5 px-3 bg-slate-100">Heat</th>
                                <th class="py-2.5 px-3 bg-slate-100">Tahap / Checkpoint</th>
                                <th class="py-2.5 px-3 text-right bg-slate-100">Hasil (Input)</th>
                                <th class="py-2.5 px-3 text-right bg-slate-100">Total Defect</th>
                                <th class="py-2.5 px-3 text-right bg-slate-100">Good</th>
                                <th class="py-2.5 px-3 text-center bg-slate-100">Status Re-Verifikasi</th>
                                <th class="py-2.5 px-3 text-center bg-slate-100 w-32">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            @foreach($executions as $index => $card)
                                @php
                                    $rowNumber = ($executions->currentPage() - 1) * $executions->perPage() + $index + 1;
                                @endphp
                                <tr class="hover:bg-blue-50/40 transition-colors {{ $card['is_reverification'] ? 'bg-amber-50/30' : ($index === 0 ? 'bg-blue-50/20' : '') }}">
                                    <!-- Row Number -->
                                    <td class="py-2 px-3 text-center font-bold text-slate-500 bg-slate-50/50">
                                        {{ $rowNumber }}
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
                                        <div class="font-bold text-slate-800 line-clamp-1 max-w-[220px]" title="{{ $card['item_name'] }}">
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

                                    <!-- Checkpoint Code + Stage -->
                                    <td class="py-2 px-3 whitespace-nowrap">
                                        <div class="flex flex-col gap-0.5">
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
                                            <span class="text-[10px] text-slate-400 font-semibold uppercase">
                                                {{ $stageLabels[$card['stage']] ?? $card['stage'] }}
                                            </span>
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

                                    <!-- Status Re-Verifikasi Indicator -->
                                    <td class="py-2 px-3 text-center whitespace-nowrap">
                                        @if($card['is_reverification'])
                                            <span class="bg-amber-100 text-amber-900 border border-amber-300 font-bold px-2 py-0.5 rounded text-[10px] inline-flex items-center gap-1" title="Defect bertambah dari {{ $card['previous_defect_qty'] }} pcs menjadi {{ $card['defect_qty'] }} pcs (+{{ $card['added_defect_qty'] }} pcs)">
                                                <i class="fas fa-exclamation-triangle text-amber-600"></i>
                                                DEFECT BERTAMBAH (+{{ $card['added_defect_qty'] }})
                                            </span>
                                        @else
                                            <span class="bg-blue-50 text-blue-700 border border-blue-200 font-semibold px-1.5 py-0.5 rounded text-[10px]">
                                                Verifikasi Baru
                                            </span>
                                        @endif
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

                <!-- Server-side Pagination Bar -->
                <div class="p-3 border-t border-slate-200 bg-slate-50 flex items-center justify-between">
                    <div class="text-xs text-slate-500">
                        Menampilkan <strong class="text-slate-800">{{ $executions->firstItem() ?? 0 }}</strong> - <strong class="text-slate-800">{{ $executions->lastItem() ?? 0 }}</strong> dari <strong class="text-slate-800">{{ $executions->total() }}</strong> baris
                    </div>
                    <div>
                        {{ $executions->links() }}
                    </div>
                </div>
            @endif
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
                        
                        <!-- Re-Verification Alert Banner -->
                        <div id="reverificationAlertBanner" class="hidden bg-amber-50 border border-amber-300 rounded-lg p-2.5 text-amber-900 text-xs">
                            <div class="font-bold flex items-center gap-1.5 text-amber-800">
                                <i class="fas fa-exclamation-triangle text-amber-600"></i>
                                DEFECT BERTAMBAH — PERLU VERIFIKASI ULANG
                            </div>
                            <div id="reverificationAlertText" class="text-[11px] text-amber-700 mt-1">
                                Defect sebelumnya: <span id="alertPrevDefect"></span> pcs · Defect sekarang: <span id="alertCurDefect"></span> pcs · Tambahan: <span id="alertAddDefect"></span> pcs
                            </div>
                        </div>

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

                        <!-- Defect History Accordion / List for QC -->
                        <div id="qcDefectLogsContainer" class="hidden pt-1">
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">
                                <i class="fas fa-history text-slate-400 mr-1"></i>Defect History (Readonly):
                            </label>
                            <div class="max-h-24 overflow-y-auto border border-slate-200 rounded-md bg-slate-50">
                                <table class="w-full text-left text-[10px]">
                                    <thead class="bg-slate-100 text-slate-600 font-bold border-b border-slate-200">
                                        <tr>
                                            <th class="py-1 px-2">Tanggal/Waktu</th>
                                            <th class="py-1 px-2">User</th>
                                            <th class="py-1 px-2 text-right">Penambahan</th>
                                            <th class="py-1 px-2 text-right">Sebelum</th>
                                            <th class="py-1 px-2 text-right">Sesudah</th>
                                        </tr>
                                    </thead>
                                    <tbody id="qcDefectLogsTbody" class="divide-y divide-slate-100 font-mono">
                                    </tbody>
                                </table>
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
        let currentStageKey = '{{ $selectedStage ?? "netto" }}';

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

            // Re-verification alert
            const alertBanner = document.getElementById('reverificationAlertBanner');
            if (card.is_reverification) {
                alertBanner.classList.remove('hidden');
                document.getElementById('alertPrevDefect').textContent = card.previous_defect_qty || 0;
                document.getElementById('alertCurDefect').textContent = card.defect_qty || 0;
                document.getElementById('alertAddDefect').textContent = '+' + (card.added_defect_qty || 0);
            } else {
                alertBanner.classList.add('hidden');
            }

            // Defect logs history for QC
            const logsContainer = document.getElementById('qcDefectLogsContainer');
            const logsTbody = document.getElementById('qcDefectLogsTbody');
            logsTbody.innerHTML = '';
            if (card.defect_logs && card.defect_logs.length > 0) {
                logsContainer.classList.remove('hidden');
                card.defect_logs.forEach(log => {
                    const tr = document.createElement('tr');
                    tr.className = 'hover:bg-slate-100';
                    tr.innerHTML = `
                        <td class="py-1 px-2 whitespace-nowrap text-slate-500">${log.created_at || '-'}</td>
                        <td class="py-1 px-2 font-sans font-semibold text-slate-700 truncate max-w-[80px]">${log.user_name || 'Admin'}</td>
                        <td class="py-1 px-2 text-right font-bold text-amber-600">+${log.added_qty}</td>
                        <td class="py-1 px-2 text-right text-slate-500">${log.previous_total}</td>
                        <td class="py-1 px-2 text-right font-black text-slate-800">${log.new_total}</td>
                    `;
                    logsTbody.appendChild(tr);
                });
            } else {
                logsContainer.classList.add('hidden');
            }

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
                submitBtnText.textContent = card.is_reverification ? 'Konfirmasi Re-Verifikasi' : 'Konfirmasi Verifikasi';

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
