@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between">
        <div class="flex flex-col">
            <h1 class="text-base font-bold text-slate-800 leading-tight flex items-center gap-2">
                <i class="fas fa-tools text-amber-500"></i>
                Pencatatan Kerusakan (PPIC)
            </h1>
            <p class="text-slate-500 text-[11px]">Pencatatan & penambahan kumulatif defect Sand Casting</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('sand-casting.qc-defects.index') }}" class="bg-blue-50 border border-blue-200 hover:bg-blue-100 text-blue-700 font-bold px-2.5 py-1 rounded-lg text-xs flex items-center gap-1.5 shadow-xs transition">
                <i class="fas fa-microscope text-blue-600"></i>
                Verifikasi QC
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

        <!-- 1. COMPACT STATUS LINE -->
        <div class="bg-white px-3.5 py-2 rounded-lg border border-slate-200 shadow-xs flex flex-wrap items-center justify-between gap-2 text-xs text-slate-600">
            <div class="flex items-center gap-2 flex-wrap">
                <span class="inline-flex items-center gap-1 text-slate-800">
                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                    <strong class="font-black text-slate-900">{{ number_format($summary['unrecorded_count'] ?? $summary['waiting_defect_count'] ?? 0) }} KTR</strong> belum dicatat
                </span>
                <span class="text-slate-300">·</span>
                <span class="inline-flex items-center gap-1 text-slate-800">
                    <strong class="font-black text-slate-900">{{ number_format($summary['unrecorded_pcs'] ?? $summary['waiting_defect_pcs'] ?? 0) }} PCS</strong> menunggu defect
                </span>
                <span class="text-slate-300">·</span>
                <span class="inline-flex items-center gap-1 text-emerald-700">
                    <strong class="font-black">{{ number_format($summary['recorded_count'] ?? 0) }} KTR</strong> sudah dicatat ({{ number_format($summary['recorded_defect_pcs'] ?? 0) }} pcs defect)
                </span>
            </div>
            <div class="text-[11px] text-slate-400 font-medium">
                @if($mode === 'recorded')
                    <i class="fas fa-history mr-1 text-emerald-600"></i>Monitoring · Defect Terbaru &rarr; Terlama
                @else
                    <i class="fas fa-sort-amount-down-alt mr-1 text-amber-500"></i>FIFO Antrian · Terlama &rarr; Terbaru
                @endif
            </div>
        </div>

        <!-- 2. MAIN 2-TAB BAR (BELUM DICATAT VS SUDAH DICATAT) -->
        <div class="bg-white border-b border-slate-200 px-2 rounded-t-lg shadow-xs flex items-center justify-between gap-2">
            <nav class="flex space-x-2 overflow-x-auto py-1.5" aria-label="Main Tabs">
                <!-- Tab 1: Belum Dicatat -->
                <a href="{{ route('sand-casting.defects.index', array_merge(request()->query(), ['mode' => 'unrecorded', 'page' => 1])) }}"
                    class="whitespace-nowrap py-1.5 px-3.5 border-b-2 font-bold text-xs flex items-center gap-2 transition-all {{ $mode === 'unrecorded' ? 'border-amber-500 text-amber-700 bg-amber-50/70 rounded-t' : 'border-transparent text-slate-500 hover:text-slate-800 hover:border-slate-300' }}">
                    <i class="fas fa-clock text-amber-500"></i>
                    <span>BELUM DICATAT</span>
                    <span class="inline-flex items-center justify-center px-1.5 py-0.2 text-[10px] font-black rounded-full {{ $mode === 'unrecorded' ? 'bg-amber-500 text-white' : 'bg-slate-200 text-slate-700' }}">
                        {{ $summary['unrecorded_count'] ?? 0 }}
                    </span>
                </a>

                <!-- Tab 2: Sudah Dicatat -->
                <a href="{{ route('sand-casting.defects.index', array_merge(request()->query(), ['mode' => 'recorded', 'page' => 1])) }}"
                    class="whitespace-nowrap py-1.5 px-3.5 border-b-2 font-bold text-xs flex items-center gap-2 transition-all {{ $mode === 'recorded' ? 'border-emerald-600 text-emerald-700 bg-emerald-50/70 rounded-t' : 'border-transparent text-slate-500 hover:text-slate-800 hover:border-slate-300' }}">
                    <i class="fas fa-clipboard-check text-emerald-600"></i>
                    <span>SUDAH DICATAT</span>
                    <span class="inline-flex items-center justify-center px-1.5 py-0.2 text-[10px] font-black rounded-full {{ $mode === 'recorded' ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-700' }}">
                        {{ $summary['recorded_count'] ?? 0 }}
                    </span>
                </a>
            </nav>

            <!-- Stage Filter Pills -->
            <div class="hidden lg:flex items-center gap-1 text-[11px] overflow-x-auto py-1">
                <span class="text-slate-400 font-bold mr-1">Tahap:</span>
                <a href="{{ route('sand-casting.defects.index', array_merge(request()->query(), ['stage' => 'all', 'page' => 1])) }}"
                    class="px-2 py-0.5 rounded font-bold transition {{ empty($selectedStage) ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }}">
                    Semua
                </a>
                @foreach($stages as $stgKey)
                    @php
                        $stgLabel = $stageLabels[$stgKey] ?? strtoupper($stgKey);
                        $isStgActive = ($selectedStage === $stgKey);
                        $stageCounter = ($mode === 'recorded') ? ($summary['stage_recorded_counts'][$stgKey] ?? 0) : ($summary['stage_counts'][$stgKey] ?? 0);
                    @endphp
                    <a href="{{ route('sand-casting.defects.index', array_merge(request()->query(), ['stage' => $stgKey, 'page' => 1])) }}"
                        class="px-2 py-0.5 rounded font-bold transition flex items-center gap-1 {{ $isStgActive ? 'bg-amber-500 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }}">
                        <span>{{ $stgLabel }}</span>
                        @if($stageCounter > 0)
                            <span class="text-[9px] px-1 rounded-full {{ $isStgActive ? 'bg-white text-amber-600 font-black' : 'bg-slate-200 text-slate-700' }}">{{ $stageCounter }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>

        <!-- 3. COMPACT SEARCH / FILTER BAR -->
        <div class="bg-white px-3 py-2 rounded-b-lg border border-t-0 border-slate-200 shadow-xs flex flex-wrap items-center justify-between gap-2">
            <form method="GET" action="{{ route('sand-casting.defects.index') }}" class="w-full flex flex-wrap items-center gap-2">
                <input type="hidden" name="mode" value="{{ $mode }}">
                
                <div class="relative flex-1 min-w-[200px] max-w-md">
                    <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none text-slate-400 text-xs">
                        <i class="fas fa-search"></i>
                    </div>
                    <input type="text" name="search" value="{{ $search }}"
                        class="block w-full pl-7 pr-3 py-1.5 bg-slate-50 border border-slate-200 text-slate-700 rounded-md focus:ring-amber-500 focus:border-amber-500 text-xs placeholder:text-slate-400"
                        placeholder="Cari KTR / Heat / Produk / Checkpoint...">
                </div>

                <!-- Dropdown Stage Filter on Mobile/Tablet -->
                <div class="lg:hidden">
                    <select name="stage" onchange="this.form.submit()" class="py-1.5 px-2.5 bg-slate-50 border border-slate-200 text-slate-700 rounded-md text-xs font-bold">
                        <option value="all" {{ empty($selectedStage) ? 'selected' : '' }}>Semua Tahap</option>
                        @foreach($stages as $stgKey)
                            <option value="{{ $stgKey }}" {{ $selectedStage === $stgKey ? 'selected' : '' }}>
                                {{ $stageLabels[$stgKey] ?? strtoupper($stgKey) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <button type="submit" class="bg-slate-800 hover:bg-slate-900 text-white font-bold text-xs px-3.5 py-1.5 rounded-md shadow-xs transition flex items-center gap-1">
                    <i class="fas fa-filter text-[10px]"></i>
                    Filter
                </button>

                @if($search || $selectedStage)
                    <a href="{{ route('sand-casting.defects.index', ['mode' => $mode]) }}" class="bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold text-xs px-2.5 py-1.5 rounded-md transition" title="Reset Search">
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
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3 class="text-sm font-bold text-slate-700">
                        {{ $mode === 'unrecorded' ? 'Tidak Ada Antrian Defect' : 'Belum Ada Riwayat Defect Tercatat' }}
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">
                        {{ $mode === 'unrecorded' ? 'Semua proses fisik pada tahap ini sudah dicatat atau belum ada KTR yang diselesaikan SPV.' : 'Data defect yang sudah dicatat akan tampil di tab ini.' }}
                    </p>
                </div>
            @else
                <!-- Table Container -->
                <div class="overflow-x-auto w-full min-h-[350px]">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead class="bg-slate-100 text-slate-700 font-bold sticky top-0 z-10 border-b border-slate-200 text-[11px] uppercase tracking-wider select-none shadow-xs">
                            <tr>
                                <th class="py-2.5 px-3 text-center w-10 bg-slate-100">#</th>
                                <th class="py-2.5 px-3 bg-slate-100 whitespace-nowrap">KTR</th>
                                <th class="py-2.5 px-3 bg-slate-100 min-w-[260px]">PRODUK</th>
                                <th class="py-2.5 px-3 bg-slate-100 whitespace-nowrap">HEAT</th>
                                <th class="py-2.5 px-3 text-right bg-slate-100 whitespace-nowrap">HASIL (INPUT)</th>
                                <th class="py-2.5 px-3 text-center bg-slate-100 whitespace-nowrap">RUSAK (DEFECT)</th>
                                <th class="py-2.5 px-3 text-right bg-slate-100 whitespace-nowrap">GOOD</th>
                                <th class="py-2.5 px-3 text-center bg-slate-100 whitespace-nowrap">STATUS</th>
                                <th class="py-2.5 px-3 text-center bg-slate-100 w-36 whitespace-nowrap">AKSI</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            @foreach($executions as $index => $card)
                                @php
                                    $rowNumber = ($executions->currentPage() - 1) * $executions->perPage() + $index + 1;
                                @endphp
                                <tr class="hover:bg-amber-50/40 transition-colors {{ $index === 0 && $mode === 'unrecorded' ? 'bg-amber-50/20' : '' }}">
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
                                                <span class="text-[10px] font-bold px-1 py-0.2 bg-amber-100 text-amber-800 rounded border border-amber-200" title="Aging proses">
                                                    {{ $card['aging']['stage_aging_label'] }}
                                                </span>
                                            @endif
                                        </div>
                                    </td>

                                    <!-- Item Name & Code & Customer (Flexible 2-Line Wrap) -->
                                    <td class="py-2 px-3">
                                        <div class="font-bold text-slate-800 text-xs leading-snug whitespace-normal break-words line-clamp-2" title="{{ $card['item_name'] }}">
                                            {{ $card['item_name'] ?? 'Item Tanpa Nama' }}
                                        </div>
                                        <div class="text-[10px] text-slate-400 font-mono flex items-center gap-1 mt-0.5">
                                            <span>{{ $card['item_code'] ?? $card['production_code'] ?? '-' }}</span>
                                            @if($card['customer'])
                                                <span class="text-slate-300">·</span>
                                                <span class="text-slate-500 font-sans">{{ $card['customer'] }}</span>
                                            @endif
                                        </div>
                                    </td>

                                    <!-- Heat Number -->
                                    <td class="py-2 px-3 font-mono font-semibold text-blue-700 whitespace-nowrap">
                                        {{ $card['heat_number'] ?? '-' }}
                                    </td>

                                    <!-- Hasil Perpindahan (Input Qty) -->
                                    <td class="py-2 px-3 text-right font-black text-slate-900 whitespace-nowrap text-xs">
                                        {{ number_format($card['input_qty']) }} <span class="text-[10px] font-normal text-slate-400">pcs</span>
                                    </td>

                                    <!-- Rusak / Defect Status -->
                                    <td class="py-2 px-3 text-center whitespace-nowrap">
                                        @if($card['status'] === \App\Models\SandCastingStageExecution::STATUS_WAITING_DEFECT)
                                            <span class="bg-amber-50 text-amber-700 border border-amber-200 font-bold px-1.5 py-0.5 rounded text-[10px] inline-flex items-center gap-1">
                                                <i class="fas fa-exclamation-circle text-amber-500"></i>
                                                BELUM DICATAT
                                            </span>
                                        @elseif($card['defect_qty'] === 0)
                                            <span class="bg-emerald-50 text-emerald-700 border border-emerald-200 font-bold px-1.5 py-0.5 rounded text-[10px] inline-flex items-center gap-1">
                                                <i class="fas fa-check-circle text-emerald-500"></i>
                                                0 PCS
                                            </span>
                                        @else
                                            <span class="bg-red-50 text-red-700 border border-red-200 font-bold px-1.5 py-0.5 rounded text-[10px] inline-flex items-center gap-1">
                                                <i class="fas fa-times-circle text-red-500"></i>
                                                {{ number_format($card['defect_qty']) }} PCS
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

                                    <!-- Status Lifecycle -->
                                    <td class="py-2 px-3 text-center whitespace-nowrap">
                                        @if($card['status'] === \App\Models\SandCastingStageExecution::STATUS_WAITING_DEFECT)
                                            <span class="bg-slate-100 text-slate-700 font-bold px-1.5 py-0.5 rounded text-[10px]">
                                                Menunggu Input
                                            </span>
                                        @elseif($card['status'] === \App\Models\SandCastingStageExecution::STATUS_WAITING_QC)
                                            <span class="bg-indigo-50 text-indigo-700 border border-indigo-200 font-bold px-1.5 py-0.5 rounded text-[10px] inline-flex items-center gap-1">
                                                <i class="fas fa-hourglass-half text-indigo-500"></i>
                                                Menunggu QC
                                            </span>
                                        @elseif($card['status'] === \App\Models\SandCastingStageExecution::STATUS_CONFIRMED)
                                            <span class="bg-emerald-50 text-emerald-700 border border-emerald-200 font-bold px-1.5 py-0.5 rounded text-[10px] inline-flex items-center gap-1">
                                                <i class="fas fa-check-double text-emerald-500"></i>
                                                CONFIRMED
                                            </span>
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
                                            <button type="button"
                                                onclick="openAddDefectModal({{ json_encode($card) }})"
                                                class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-3 py-1.5 rounded-md text-xs shadow-xs transition inline-flex items-center gap-1"
                                                title="Tambah defect susulan secara kumulatif">
                                                <i class="fas fa-plus-circle text-[10px]"></i>
                                                TAMBAH DEFECT
                                            </button>
                                        @endif
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

    <!-- 5A. MODAL CATAT RUSAK AWAL (PPIC INITIAL RECORDING) -->
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
                            <p class="text-[10px] text-slate-400">Input kuantitas total defect awal untuk KTR</p>
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
                                <span class="text-slate-700 font-bold">Hasil Perpindahan (Input):</span>
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

                        <!-- Tanggal Proses -->
                        <div>
                            <label for="process_date" class="block text-xs font-bold text-slate-700 mb-1">
                                Tanggal Proses
                            </label>
                            <input type="date" name="process_date" id="process_date"
                                class="w-full text-xs py-1.5 bg-white border border-slate-300 text-slate-800 rounded-lg focus:ring-amber-500 focus:border-amber-500">
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

    <!-- 5B. MODAL TAMBAH DEFECT SUSULAN (PPIC CUMULATIVE ADDITION) -->
    <div id="addDefectModal" tabindex="-1" aria-hidden="true"
        class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 h-modal md:h-full bg-slate-900/60 backdrop-blur-xs">
        <div class="relative p-4 w-full max-w-lg max-h-full">
            <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 overflow-hidden">
                
                <!-- Modal Header -->
                <div class="flex items-center justify-between p-3.5 px-4 border-b border-slate-200 bg-emerald-50">
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-xs">
                            <i class="fas fa-plus-circle"></i>
                        </div>
                        <div>
                            <h3 class="text-xs font-bold text-emerald-900">Tambah Defect (PPIC)</h3>
                            <p class="text-[10px] text-emerald-700">Penambahan defect susulan bersifat kumulatif</p>
                        </div>
                    </div>
                    <button type="button" onclick="closeAddDefectModal()"
                        class="text-slate-400 hover:text-slate-600 bg-transparent hover:bg-slate-200 rounded-lg text-xs w-6 h-6 inline-flex justify-center items-center">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <!-- Modal Form -->
                <form id="addDefectForm" method="POST" action="">
                    @csrf
                    <div class="p-4 space-y-3 text-xs">
                        
                        <!-- Readonly Info Banner -->
                        <div class="bg-slate-50 p-3 rounded-lg border border-slate-200 text-xs space-y-1.5">
                            <div class="flex justify-between items-center">
                                <span class="text-slate-500">No. KTR:</span>
                                <span id="addModalKtr" class="font-mono font-bold text-slate-800"></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-slate-500">Nama Produk:</span>
                                <span id="addModalItemName" class="font-bold text-slate-800 text-right truncate max-w-[220px]"></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-slate-500">Checkpoint:</span>
                                <span id="addModalCheckpoint" class="font-mono font-bold text-indigo-700 bg-indigo-50 px-1.5 py-0.2 rounded border border-indigo-100"></span>
                            </div>
                            <div class="grid grid-cols-3 gap-2 pt-2 border-t border-slate-200 text-center">
                                <div class="bg-white p-1.5 rounded border border-slate-200">
                                    <div class="text-[10px] text-slate-400">Input Produksi</div>
                                    <div id="addModalInputQty" class="font-black text-slate-800 text-xs"></div>
                                </div>
                                <div class="bg-amber-50 p-1.5 rounded border border-amber-200">
                                    <div class="text-[10px] text-amber-700 font-bold">Defect Saat Ini</div>
                                    <div id="addModalCurrentDefectQty" class="font-black text-amber-800 text-xs"></div>
                                </div>
                                <div class="bg-emerald-50 p-1.5 rounded border border-emerald-200">
                                    <div class="text-[10px] text-emerald-600 font-bold">Good Saat Ini</div>
                                    <div id="addModalCurrentGoodQty" class="font-black text-emerald-600 text-xs"></div>
                                </div>
                            </div>
                        </div>

                        <input type="hidden" id="addHiddenInputQty" value="0">
                        <input type="hidden" id="addHiddenCurrentDefect" value="0">

                        <!-- Input Added Defect Qty -->
                        <div>
                            <label for="added_qty" class="block text-xs font-bold text-slate-700 mb-1">
                                Tambah Defect (PCS) <span class="text-red-500">*</span>
                            </label>
                            <input type="number" name="added_qty" id="added_qty" min="1" step="1" required
                                class="w-full text-center text-lg font-black tracking-wider py-1.5 bg-white border border-slate-300 text-slate-800 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                placeholder="1" oninput="calculateCumulativeDefect()">
                            <p class="text-[10px] text-slate-400 mt-0.5">
                                Masukkan jumlah defect tambahan yang baru ditemukan (bukan total akhir).
                            </p>
                        </div>

                        <!-- Live Cumulative Preview -->
                        <div class="bg-emerald-50/70 p-3 rounded-lg border border-emerald-200 space-y-1">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-slate-700 font-bold">Total Defect Baru:</span>
                                <span id="addModalNewDefectDisplay" class="font-black text-red-600 text-sm">0 PCS</span>
                            </div>
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-slate-700 font-bold">Good Qty Baru:</span>
                                <span id="addModalNewGoodDisplay" class="font-black text-emerald-700 text-sm">0 PCS</span>
                            </div>
                            <div id="addModalCalculationHint" class="text-[10px] text-slate-500 pt-1 border-t border-emerald-200/60">
                                Total Defect Baru = 0 + 0 = 0 PCS
                            </div>
                        </div>

                        <!-- Notes (Optional) -->
                        <div>
                            <label for="add_notes" class="block text-xs font-bold text-slate-700 mb-1">
                                Catatan Penambahan (Opsional)
                            </label>
                            <textarea name="notes" id="add_notes" rows="2"
                                class="w-full text-xs p-2 bg-white border border-slate-300 text-slate-800 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                placeholder="Alasan penambahan defect..."></textarea>
                        </div>

                        <!-- Defect History Accordion / List -->
                        <div id="addDefectLogsContainer" class="hidden pt-1">
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">
                                <i class="fas fa-history text-slate-400 mr-1"></i>Riwayat Perubahan Defect:
                            </label>
                            <div class="max-h-28 overflow-y-auto border border-slate-200 rounded-md bg-slate-50">
                                <table class="w-full text-left text-[10px]">
                                    <thead class="bg-slate-100 text-slate-600 font-bold border-b border-slate-200">
                                        <tr>
                                            <th class="py-1 px-2">Waktu</th>
                                            <th class="py-1 px-2">User</th>
                                            <th class="py-1 px-2 text-right">Tambah</th>
                                            <th class="py-1 px-2 text-right">Sebelum</th>
                                            <th class="py-1 px-2 text-right">Sesudah</th>
                                        </tr>
                                    </thead>
                                    <tbody id="addDefectLogsTbody" class="divide-y divide-slate-100 font-mono">
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>

                    <!-- Modal Footer -->
                    <div class="flex items-center justify-end gap-2 p-3 px-4 border-t border-slate-200 bg-slate-50 rounded-b-xl">
                        <button type="button" onclick="closeAddDefectModal()"
                            class="px-3.5 py-1.5 text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-md hover:bg-slate-100 transition">
                            Batal
                        </button>
                        <button type="submit" id="submitAddDefectBtn"
                            class="px-4 py-1.5 text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 rounded-md shadow-xs transition flex items-center gap-1">
                            <i class="fas fa-save"></i>
                            Simpan Penambahan
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>

    <!-- 6. JAVASCRIPT LOGIC -->
    <script>
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

        function openAddDefectModal(card) {
            const modal = document.getElementById('addDefectModal');
            const form = document.getElementById('addDefectForm');

            const inputQty = parseInt(card.input_qty) || 0;
            const currentDefect = parseInt(card.defect_qty) || 0;
            const currentGood = parseInt(card.good_qty) || 0;

            document.getElementById('addModalKtr').textContent = card.traveler_number || '-';
            document.getElementById('addModalItemName').textContent = card.item_name || '-';
            document.getElementById('addModalCheckpoint').textContent = card.checkpoint_code || '-';
            document.getElementById('addModalInputQty').textContent = inputQty + ' pcs';
            document.getElementById('addModalCurrentDefectQty').textContent = currentDefect + ' pcs';
            document.getElementById('addModalCurrentGoodQty').textContent = currentGood + ' pcs';

            document.getElementById('addHiddenInputQty').value = inputQty;
            document.getElementById('addHiddenCurrentDefect').value = currentDefect;
            document.getElementById('added_qty').value = '';
            document.getElementById('add_notes').value = '';

            // Update action route URL
            form.action = "{{ route('sand-casting.defects.add', ':id') }}".replace(':id', card.id);

            // Populate defect history logs if available
            const logsContainer = document.getElementById('addDefectLogsContainer');
            const logsTbody = document.getElementById('addDefectLogsTbody');
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

            calculateCumulativeDefect();

            modal.classList.remove('hidden');
            modal.classList.add('flex');

            setTimeout(() => {
                document.getElementById('added_qty').focus();
            }, 100);
        }

        function closeAddDefectModal() {
            const modal = document.getElementById('addDefectModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function calculateCumulativeDefect() {
            const inputQty = parseInt(document.getElementById('addHiddenInputQty').value) || 0;
            const currentDefect = parseInt(document.getElementById('addHiddenCurrentDefect').value) || 0;
            const addedInput = document.getElementById('added_qty');
            const addedVal = addedInput.value.trim();
            const addedQty = addedVal === '' ? 0 : parseInt(addedVal);

            const newDefectDisplay = document.getElementById('addModalNewDefectDisplay');
            const newGoodDisplay = document.getElementById('addModalNewGoodDisplay');
            const hintDisplay = document.getElementById('addModalCalculationHint');
            const submitBtn = document.getElementById('submitAddDefectBtn');

            if (addedVal !== '' && (isNaN(addedQty) || addedQty <= 0)) {
                newDefectDisplay.textContent = 'Invalid';
                newDefectDisplay.className = 'font-black text-red-600 text-sm';
                hintDisplay.textContent = 'Jumlah penambahan harus minimal 1 PCS.';
                hintDisplay.className = 'text-[10px] text-red-500 font-bold';
                submitBtn.disabled = true;
                return;
            }

            const newTotalDefect = currentDefect + addedQty;

            if (newTotalDefect > inputQty) {
                newDefectDisplay.textContent = newTotalDefect + ' PCS (Melebihi Input)';
                newDefectDisplay.className = 'font-black text-red-600 text-sm';
                newGoodDisplay.textContent = '-';
                hintDisplay.textContent = 'Total defect baru (' + newTotalDefect + ') melebihi input (' + inputQty + '). Penambahan ditolak.';
                hintDisplay.className = 'text-[10px] text-red-500 font-bold';
                submitBtn.disabled = true;
                return;
            }

            const newGoodQty = Math.max(0, inputQty - newTotalDefect);
            newDefectDisplay.textContent = newTotalDefect + ' PCS';
            newDefectDisplay.className = 'font-black text-red-600 text-sm';
            newGoodDisplay.textContent = newGoodQty + ' PCS';
            newGoodDisplay.className = 'font-black text-emerald-700 text-sm';

            hintDisplay.textContent = 'Total Defect Baru = ' + currentDefect + ' + ' + addedQty + ' = ' + newTotalDefect + ' PCS (Good: ' + newGoodQty + ' PCS)';
            hintDisplay.className = 'text-[10px] text-slate-600';
            submitBtn.disabled = false;
        }

        // Close modal on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeDefectModal();
                closeAddDefectModal();
            }
        });
    </script>
@endsection
