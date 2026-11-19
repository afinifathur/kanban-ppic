@extends('layouts.app')

@section('title', 'Transfer Kitir — Fitting Production')

@section('top_bar')
    <div class="flex items-center justify-between w-full gap-2 min-w-0">
        <div class="min-w-0">
            <div class="flex items-center gap-1.5 text-[10px] sm:text-[11px] font-bold text-emerald-700 uppercase tracking-wider leading-none">
                <i class="fas fa-cubes text-[9px] text-emerald-600"></i>
                <span>FITTING PRODUCTION</span>
                <span class="text-slate-300">•</span>
                <span class="text-slate-500">JEMBATAN LOST WAX → NETTO PF</span>
            </div>
            <h1 class="text-sm sm:text-base md:text-lg font-black text-slate-900 tracking-tight leading-tight mt-0.5 truncate">
                Transfer Kitir Produksi (PFKTR)
            </h1>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <a href="{{ route('lost-wax.casting-results.index') }}"
               class="inline-flex items-center px-2.5 sm:px-3 py-1.5 rounded-lg text-xs font-bold bg-white hover:bg-amber-50 text-amber-700 border border-amber-300 transition-colors shadow-xs whitespace-nowrap">
                <i class="fas fa-fire mr-1.5 text-amber-500"></i>
                <span class="hidden sm:inline">Lihat Hasil Cor Lost Wax</span>
                <span class="sm:hidden">Hasil Cor</span>
            </a>
        </div>
    </div>
@endsection

@section('content')
<div class="space-y-2.5">
    <!-- Compact Horizontal KPI Indicators (Clickable Tab Navigation) -->
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('fitting.transfers.index', array_merge(request()->except(['tab', 'transferred_page', 'decisions_page']), ['tab' => 'available'])) }}"
           class="inline-flex items-center gap-2 px-2.5 py-1.5 rounded-lg border transition-all shadow-xs {{ $tab === 'available' ? 'bg-emerald-50/80 border-emerald-400 ring-1 ring-emerald-200' : 'bg-white border-slate-200 hover:border-slate-300 hover:bg-slate-50' }}">
            <span class="w-6 h-6 rounded-md bg-emerald-100/80 text-emerald-600 flex items-center justify-center text-[11px] shrink-0">
                <i class="fas fa-inbox"></i>
            </span>
            <div class="leading-tight">
                <div class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Siap Ditransfer</div>
                <div class="text-xs font-semibold text-slate-800 whitespace-nowrap mt-0.5">
                    <span class="font-mono font-black text-slate-900">{{ number_format($counts['available']) }}</span> Kitir
                    <span class="text-slate-300 mx-1">|</span>
                    <span class="font-mono font-bold text-emerald-700">{{ number_format($counts['available_pcs']) }} PCS</span>
                </div>
            </div>
        </a>

        <a href="{{ route('fitting.transfers.index', array_merge(request()->except(['tab', 'transferred_page', 'decisions_page']), ['tab' => 'transferred'])) }}"
           class="inline-flex items-center gap-2 px-2.5 py-1.5 rounded-lg border transition-all shadow-xs {{ $tab === 'transferred' ? 'bg-blue-50/80 border-blue-400 ring-1 ring-blue-200' : 'bg-white border-slate-200 hover:border-slate-300 hover:bg-slate-50' }}">
            <span class="w-6 h-6 rounded-md bg-blue-100/80 text-blue-600 flex items-center justify-center text-[11px] shrink-0">
                <i class="fas fa-exchange-alt"></i>
            </span>
            <div class="leading-tight">
                <div class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Sudah Ditransfer</div>
                <div class="text-xs font-semibold text-slate-800 whitespace-nowrap mt-0.5">
                    <span class="font-mono font-black text-slate-900">{{ number_format($counts['transferred_active']) }}</span> Traveler Aktif
                    @if($counts['transferred_cancelled'] > 0)
                        <span class="text-slate-300 mx-1">|</span>
                        <span class="text-[10px] text-slate-400 font-normal">{{ number_format($counts['transferred_cancelled']) }} batal</span>
                    @endif
                </div>
            </div>
        </a>

        <a href="{{ route('fitting.transfers.index', array_merge(request()->except(['tab', 'transferred_page', 'decisions_page']), ['tab' => 'decisions'])) }}"
           class="inline-flex items-center gap-2 px-2.5 py-1.5 rounded-lg border transition-all shadow-xs {{ $tab === 'decisions' ? 'bg-amber-50/80 border-amber-400 ring-1 ring-amber-200' : 'bg-white border-slate-200 hover:border-slate-300 hover:bg-slate-50' }}">
            <span class="w-6 h-6 rounded-md bg-amber-100/80 text-amber-600 flex items-center justify-center text-[11px] shrink-0">
                <i class="fas fa-history"></i>
            </span>
            <div class="leading-tight">
                <div class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Decision History</div>
                <div class="text-xs font-semibold text-slate-800 whitespace-nowrap mt-0.5">
                    <span class="font-mono font-black text-slate-900">{{ number_format($counts['decisions_active']) }}</span> Aktif
                    <span class="text-slate-300 mx-1">|</span>
                    <span class="text-[11px] text-slate-500 font-normal">{{ number_format($counts['decisions_total']) }} total riwayat</span>
                </div>
            </div>
        </a>
    </div>

    <!-- Error Alerts -->
    @if($errors->has('transfer_error'))
    <div class="bg-rose-50 border border-rose-200 text-rose-800 px-3.5 py-2.5 rounded-xl flex items-center gap-2.5 shadow-sm">
        <i class="fas fa-exclamation-circle text-rose-500 text-sm shrink-0"></i>
        <div class="text-xs font-medium leading-relaxed">{{ $errors->first('transfer_error') }}</div>
    </div>
    @endif

    @if($errors->any() && !$errors->has('transfer_error'))
    <div class="bg-rose-50 border border-rose-200 text-rose-800 px-3.5 py-2.5 rounded-xl flex items-start gap-2.5 shadow-sm">
        <i class="fas fa-exclamation-triangle text-rose-500 text-sm shrink-0 mt-0.5"></i>
        <ul class="text-xs font-medium list-disc pl-4 space-y-0.5">
            @foreach($errors->all() as $err)
                <li>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <!-- Tabs & Filter Toolbar -->
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <!-- Tabs -->
        <div class="flex flex-wrap items-center justify-between gap-2 px-3.5 pt-2 border-b border-slate-200 bg-slate-50/70">
            <div class="flex items-center gap-1 -mb-px">
                <a href="{{ route('fitting.transfers.index', array_merge(request()->except(['tab', 'transferred_page', 'decisions_page']), ['tab' => 'available'])) }}"
                   class="px-3.5 py-2 text-xs font-bold border-b-2 transition-colors {{ $tab === 'available' ? 'border-emerald-600 text-emerald-700 bg-white rounded-t-lg' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                    <i class="fas fa-inbox mr-1.5"></i> Siap Ditransfer
                    <span class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] {{ $tab === 'available' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' }}">{{ $counts['available'] }}</span>
                </a>
                <a href="{{ route('fitting.transfers.index', array_merge(request()->except(['tab', 'transferred_page', 'decisions_page']), ['tab' => 'transferred'])) }}"
                   class="px-3.5 py-2 text-xs font-bold border-b-2 transition-colors {{ $tab === 'transferred' ? 'border-blue-600 text-blue-700 bg-white rounded-t-lg' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                    <i class="fas fa-check-double mr-1.5"></i> Sudah Ditransfer (Netto PF)
                    <span class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] {{ $tab === 'transferred' ? 'bg-blue-100 text-blue-800' : 'bg-slate-200 text-slate-600' }}">{{ $counts['transferred_active'] }}</span>
                </a>
                <a href="{{ route('fitting.transfers.index', array_merge(request()->except(['tab', 'transferred_page', 'decisions_page']), ['tab' => 'decisions'])) }}"
                   class="px-3.5 py-2 text-xs font-bold border-b-2 transition-colors {{ $tab === 'decisions' ? 'border-amber-600 text-amber-700 bg-white rounded-t-lg' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                    <i class="fas fa-history mr-1.5"></i> Decision History
                    <span class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] {{ $tab === 'decisions' ? 'bg-amber-100 text-amber-800' : 'bg-slate-200 text-slate-600' }}">{{ $counts['decisions_active'] }}</span>
                </a>
            </div>
        </div>

        <!-- Filter Form -->
        <form method="GET" action="{{ route('fitting.transfers.index') }}" class="px-3.5 py-2.5 border-b border-slate-100 bg-white">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-2.5 items-end">
                <div class="lg:col-span-4">
                    <label class="block text-[10px] font-bold uppercase text-slate-400 mb-0.5">Pencarian</label>
                    <input type="text" name="search" value="{{ $filters['search'] }}"
                           placeholder="Cari PFKTR, Heat, Kode Produksi, Customer, Item..."
                           class="w-full px-2.5 py-1.5 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500">
                </div>
                <div class="lg:col-span-2">
                    <label class="block text-[10px] font-bold uppercase text-slate-400 mb-0.5">Dari Tanggal</label>
                    <input type="date" name="date_from" value="{{ $filters['date_from'] }}"
                           class="w-full px-2.5 py-1.5 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-emerald-500">
                </div>
                <div class="lg:col-span-2">
                    <label class="block text-[10px] font-bold uppercase text-slate-400 mb-0.5">Sampai Tanggal</label>
                    <input type="date" name="date_to" value="{{ $filters['date_to'] }}"
                           class="w-full px-2.5 py-1.5 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-emerald-500">
                </div>
                @if($tab === 'transferred')
                <div class="lg:col-span-2">
                    <label class="block text-[10px] font-bold uppercase text-slate-400 mb-0.5">Line Fitting</label>
                    <select name="line" class="w-full px-2.5 py-1.5 text-xs border border-slate-300 rounded-lg focus:outline-none focus:border-emerald-500">
                        <option value="">Semua Line</option>
                        @foreach($lineLabels as $lNum => $lLabel)
                            <option value="{{ $lNum }}" {{ $filters['line'] === (string) $lNum ? 'selected' : '' }}>{{ $lLabel }}</option>
                        @endforeach
                        <option value="unassigned" {{ $filters['line'] === 'unassigned' ? 'selected' : '' }}>Unassigned (Historis)</option>
                    </select>
                </div>
                @endif
                <div class="{{ $tab === 'transferred' ? 'lg:col-span-2' : 'lg:col-span-4' }} flex items-center gap-2">
                    <button type="submit"
                            class="flex-1 inline-flex items-center justify-center px-3 py-1.5 rounded-lg text-xs font-bold bg-slate-800 hover:bg-slate-900 text-white transition-colors">
                        <i class="fas fa-search mr-1.5"></i> Filter
                    </button>
                    <a href="{{ route('fitting.transfers.index', ['tab' => $tab]) }}"
                       class="inline-flex items-center justify-center px-2.5 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 hover:bg-slate-200 text-slate-600 transition-colors"
                       title="Reset Filter">
                        <i class="fas fa-undo"></i>
                    </a>
                </div>
            </div>
        </form>

        <!-- TAB 1: AVAILABLE KITIRS FOR PARTIAL TRANSFER -->
        @if($tab === 'available')
            @if($availableRows->isEmpty())
                <div class="p-10 text-center text-slate-500">
                    <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-3 text-lg">
                        <i class="fas fa-inbox"></i>
                    </div>
                    <p class="text-sm font-bold text-slate-700">Tidak ada Kitir yang tersedia untuk ditransfer.</p>
                    <p class="text-xs text-slate-500 mt-1">
                        Pastikan Kitir sudah <strong>diterbitkan</strong> pada halaman Detail Hasil Cor Lost Wax (cetak tidak wajib).
                    </p>
                </div>
            @else
                <form id="transferBatchForm" method="POST" action="{{ route('fitting.transfers.store') }}">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

                    <!-- Selection Action Bar -->
                    <div class="px-3.5 py-2 bg-emerald-50/70 border-b border-emerald-200 flex flex-col md:flex-row md:items-center md:justify-between gap-2.5">
                        <div class="flex flex-wrap items-center gap-2.5 text-xs">
                            <span class="font-bold text-emerald-900">
                                <i class="fas fa-check-square mr-1 text-emerald-600"></i>
                                Terpilih: <span id="selectedCount" class="font-mono text-sm">0</span> Kitir
                            </span>
                            <span class="text-emerald-300">|</span>
                            <span class="text-emerald-800 font-semibold">
                                Total Qty: <span id="selectedPcs" class="font-mono font-bold">0</span> PCS
                            </span>
                            <span class="text-emerald-300">|</span>
                            <span class="text-emerald-800 font-semibold">
                                Estimasi Berat: <span id="selectedWeight" class="font-mono font-bold">0,00</span> KG
                            </span>
                            <span id="missingLineBadge" class="hidden inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-rose-100 text-rose-700 border border-rose-300">
                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                <span id="missingLineCount">0</span> belum pilih Line Fitting
                            </span>
                        </div>

                        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                            <input type="text" name="notes" value="{{ old('notes') }}" maxlength="1000"
                                   placeholder="Catatan transfer (opsional)..."
                                   class="px-2.5 py-1.5 text-xs bg-white border border-emerald-300 rounded-lg focus:outline-none focus:border-emerald-600 sm:w-56">
                            <button type="submit" id="btnSubmitTransfer" disabled
                                    class="inline-flex items-center justify-center px-3.5 py-1.5 rounded-lg text-xs font-bold bg-emerald-600 hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed text-white transition-colors shadow-sm whitespace-nowrap">
                                <i class="fas fa-paper-plane mr-1.5"></i> Transfer Terpilih ke NETTO PF
                            </button>
                        </div>
                    </div>

                    @php
                        $oldKitirIds = array_map('strval', (array) old('kitir_ids', []));
                        $oldKitirLines = (array) old('kitir_lines', []);
                    @endphp

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs border-collapse">
                            <thead class="bg-slate-50 text-slate-600 uppercase text-[10px] font-bold tracking-wider border-b border-slate-200">
                                <tr>
                                    <th class="px-2.5 py-2 text-center w-9">
                                        <input type="checkbox" id="selectAllKitirs" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                    </th>
                                    <th class="px-2.5 py-2 whitespace-nowrap">Kitir / Heat</th>
                                    <th class="px-2.5 py-2 whitespace-nowrap">Kode Produksi</th>
                                    <th class="px-2.5 py-2 whitespace-nowrap">Customer / PO</th>
                                    <th class="px-2.5 py-2">Item / Produk</th>
                                    <th class="px-2.5 py-2 text-left min-w-[165px] whitespace-nowrap">Line Fitting <span class="text-rose-500">*</span></th>
                                    <th class="px-2.5 py-2 text-right whitespace-nowrap">Qty (PCS)</th>
                                    <th class="px-2.5 py-2 text-right whitespace-nowrap">Berat Satuan</th>
                                    <th class="px-2.5 py-2 text-right whitespace-nowrap">Total Berat</th>
                                    <th class="px-2.5 py-2 text-right whitespace-nowrap">Keputusan</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                @foreach($availableRows as $row)
                                    @php
                                        $k = $row['kitir'];
                                        $cr = $row['casting_result'];
                                        $snap = $row['snapshot'];
                                        $isChecked = in_array((string) $k->id, $oldKitirIds, true);
                                        $hasOldLine = array_key_exists($k->id, $oldKitirLines) || array_key_exists((string) $k->id, $oldKitirLines);
                                        $suggestedLine = ($snap['suggested_line_status'] ?? null) === \App\Services\Fitting\FittingKitirSnapshotResolverService::SUGGESTED_LINE_CONSISTENT
                                            ? $snap['suggested_line_number']
                                            : null;
                                        $selectedLineVal = $hasOldLine
                                            ? (string) ($oldKitirLines[$k->id] ?? $oldKitirLines[(string) $k->id] ?? '')
                                            : ($suggestedLine !== null ? (string) $suggestedLine : '');
                                    @endphp
                                    <tr class="kitir-transfer-row hover:bg-emerald-50/30 transition-colors" data-kitir-id="{{ $k->id }}">
                                        <td class="px-2.5 py-2 text-center align-middle">
                                            <input type="checkbox"
                                                   name="kitir_ids[]"
                                                   value="{{ $k->id }}"
                                                   {{ $isChecked ? 'checked' : '' }}
                                                   data-pcs="{{ $snap['qty_transferred'] }}"
                                                   data-weight="{{ $snap['total_weight_kg'] }}"
                                                   class="kitir-checkbox rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                        </td>
                                        <td class="px-2.5 py-2 whitespace-nowrap align-middle">
                                            <div>
                                                <span class="font-mono font-bold bg-amber-50 text-amber-800 border border-amber-200 px-1.5 py-0.5 rounded text-[11px]">
                                                    {{ $k->traveler_number }}
                                                </span>
                                            </div>
                                            <div class="text-[11px] text-slate-600 mt-0.5 leading-tight">
                                                Heat:
                                                <a href="{{ route('lost-wax.casting-results.show', $cr) }}"
                                                   class="font-mono font-bold text-blue-700 hover:underline">{{ $cr->heat_number }}</a>
                                                <span class="text-slate-300 mx-0.5">•</span>
                                                <span class="text-slate-500">{{ $cr->cast_date ? $cr->cast_date->format('d/m/Y') : '-' }}</span>
                                            </div>
                                            <div class="text-[10px] text-slate-400 leading-tight mt-0.5">
                                                {{ $k->tree_count }} Pohon
                                            </div>
                                        </td>
                                        <td class="px-2.5 py-2 font-mono font-bold text-slate-900 whitespace-nowrap align-middle">
                                            {{ $snap['production_code'] }}
                                            @if($snap['is_multi_allocation'])
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200 ml-1">
                                                    Multi ({{ count($snap['allocations']) }})
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-2.5 py-2 align-middle">
                                            <div class="font-medium text-slate-800 leading-snug">{{ $snap['customer'] ?: '-' }}</div>
                                            @if($snap['po_number'])
                                                <div class="text-[10px] font-mono text-slate-500 leading-tight mt-0.5">PO: {{ $snap['po_number'] }}</div>
                                            @endif
                                        </td>
                                        <td class="px-2.5 py-2 align-middle">
                                            <div class="text-slate-800 font-medium leading-snug">{{ $snap['item_name'] ?: '-' }}</div>
                                            @if($snap['item_code'])
                                                <div class="text-[10px] font-mono text-slate-400 leading-tight mt-0.5">{{ $snap['item_code'] }}</div>
                                            @endif
                                        </td>
                                        <td class="px-2.5 py-2 whitespace-nowrap align-middle">
                                            <select name="kitir_lines[{{ $k->id }}]"
                                                    data-kitir-id="{{ $k->id }}"
                                                    data-suggested="{{ $suggestedLine ?? '' }}"
                                                    class="kitir-line-select w-full px-2 py-1 text-xs font-semibold rounded-lg border border-slate-300 bg-white focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500">
                                                <option value="">-- Pilih Line --</option>
                                                @foreach($lineLabels as $lNum => $lLabel)
                                                    <option value="{{ $lNum }}" {{ $selectedLineVal === (string) $lNum ? 'selected' : '' }}>{{ $lLabel }}</option>
                                                @endforeach
                                            </select>
                                            <div class="line-override-hint text-[10px] text-indigo-600 font-semibold mt-0.5 {{ ($suggestedLine !== null && $selectedLineVal !== '' && $selectedLineVal !== (string) $suggestedLine) ? '' : 'hidden' }}">
                                                Koreksi dari Line {{ $suggestedLine }}
                                            </div>
                                        </td>
                                        <td class="px-2.5 py-2 text-right font-mono font-black text-slate-900 whitespace-nowrap align-middle">
                                            {{ number_format($snap['qty_transferred']) }}
                                        </td>
                                        <td class="px-2.5 py-2 text-right font-mono text-slate-600 whitespace-nowrap align-middle">
                                            @if($snap['has_complete_weight'])
                                                {{ number_format($snap['unit_weight_kg'], 2, ',', '.') }} kg
                                            @elseif($snap['weight_status'] === \App\Services\Fitting\FittingKitirSnapshotResolverService::WEIGHT_STATUS_PARTIAL)
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold bg-amber-50 text-amber-700 border border-amber-200"
                                                      title="Berat belum lengkap untuk: {{ implode(', ', $snap['missing_weight_codes']) }}">
                                                    Tidak lengkap
                                                </span>
                                            @else
                                                <span class="text-slate-400">-</span>
                                            @endif
                                        </td>
                                        <td class="px-2.5 py-2 text-right font-mono font-bold text-slate-900 whitespace-nowrap align-middle">
                                            @if($snap['has_complete_weight'])
                                                {{ number_format($snap['total_weight_kg'], 2, ',', '.') }} kg
                                            @elseif($snap['weight_status'] === \App\Services\Fitting\FittingKitirSnapshotResolverService::WEIGHT_STATUS_PARTIAL)
                                                <span>{{ number_format($snap['total_weight_kg'], 2, ',', '.') }} kg*</span>
                                                <div class="text-[9px] text-amber-700 font-normal">Sebagian</div>
                                            @else
                                                <span class="text-slate-400">-</span>
                                            @endif
                                        </td>
                                        <td class="px-2.5 py-2 text-right whitespace-nowrap align-middle">
                                            <button type="button"
                                                    onclick="openNotContinuedModal({{ $k->id }}, @js($k->traveler_number), @js($snap['production_code']), {{ $snap['qty_transferred'] }})"
                                                    class="inline-flex items-center px-2 py-1 rounded-lg text-[11px] font-bold bg-white hover:bg-rose-50 text-rose-600 border border-rose-200 transition-colors">
                                                <i class="fas fa-ban mr-1"></i> Tidak Dilanjutkan
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </form>
            @endif

        <!-- TAB 2: TRANSFERRED ITEMS (NETTO PF) -->
        @elseif($tab === 'transferred')
            @if($transferredItems->isEmpty())
                <div class="p-10 text-center text-slate-500">
                    <p class="text-sm font-bold text-slate-700">Belum ada Kitir yang ditransfer ke Fitting Production.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead class="bg-slate-50 text-slate-600 uppercase text-[10px] font-bold tracking-wider border-b border-slate-200">
                            <tr>
                                <th class="px-2.5 py-2 whitespace-nowrap">No. Transfer / Tgl</th>
                                <th class="px-2.5 py-2 whitespace-nowrap">Nomor Kitir (PFKTR)</th>
                                <th class="px-2.5 py-2 whitespace-nowrap">Heat / Kode Produksi</th>
                                <th class="px-2.5 py-2">Customer / Item</th>
                                <th class="px-2.5 py-2 text-center whitespace-nowrap">Line</th>
                                <th class="px-2.5 py-2 text-right whitespace-nowrap">Qty (PCS)</th>
                                <th class="px-2.5 py-2 text-right whitespace-nowrap">Unit / Total KG</th>
                                <th class="px-2.5 py-2 text-center whitespace-nowrap">Stage / Status</th>
                                <th class="px-2.5 py-2 text-right whitespace-nowrap">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            @foreach($transferredItems as $item)
                                <tr class="{{ $item->status === \App\Models\FittingItem::STATUS_CANCELLED ? 'bg-slate-50/80 opacity-75' : 'hover:bg-slate-50/70' }}">
                                    <td class="px-2.5 py-2 whitespace-nowrap">
                                        <div class="font-mono font-bold text-slate-900">{{ $item->transfer?->transfer_number ?? '-' }}</div>
                                        <div class="text-[10px] text-slate-400">
                                            {{ $item->transfer?->transfer_date ? $item->transfer->transfer_date->format('d/m/Y') : '-' }}
                                            • {{ $item->transfer?->transferredBy?->name ?? '-' }}
                                        </div>
                                    </td>
                                    <td class="px-2.5 py-2 font-mono font-bold whitespace-nowrap">
                                        <span class="bg-blue-50 text-blue-800 border border-blue-200 px-1.5 py-0.5 rounded text-[11px]">
                                            {{ $item->traveler_number }}
                                        </span>
                                    </td>
                                    <td class="px-2.5 py-2">
                                        <div class="font-mono font-bold text-slate-900">{{ $item->heat_number }}</div>
                                        <div class="font-mono text-[11px] text-slate-600">
                                            {{ $item->production_code }}
                                            @if($item->is_multi_allocation)
                                                <span class="px-1.5 py-0.5 rounded text-[9px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200 ml-1">
                                                    Multi ({{ $item->allocations->count() }})
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-2.5 py-2">
                                        <div class="font-medium text-slate-800">{{ $item->customer ?: '-' }}</div>
                                        <div class="text-[11px] text-slate-500">{{ $item->item_name ?: '-' }}</div>
                                    </td>
                                    <td class="px-2.5 py-2 text-center whitespace-nowrap">
                                        @if($item->line_number !== null)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200"
                                                  title="{{ \App\Models\FittingItem::lineLabelFor($item->line_number) }}">
                                                {{ \App\Models\FittingItem::lineLabelFor($item->line_number) }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600 border border-slate-300">
                                                Unassigned
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-2.5 py-2 text-right font-mono font-black text-slate-900 whitespace-nowrap">
                                        {{ number_format($item->qty_transferred) }}
                                    </td>
                                    <td class="px-2.5 py-2 text-right font-mono whitespace-nowrap">
                                        <div class="font-bold text-slate-900">{{ number_format((float) $item->total_weight_kg, 2, ',', '.') }} kg</div>
                                        <div class="text-[10px] text-slate-400">@ {{ number_format((float) $item->unit_weight_kg, 2, ',', '.') }} kg</div>
                                    </td>
                                    <td class="px-2.5 py-2 text-center whitespace-nowrap">
                                        @if($item->status === \App\Models\FittingItem::STATUS_ACTIVE)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                {{ \App\Services\Fitting\FittingStageAuthorizationService::STAGE_LABELS[$item->current_stage] ?? strtoupper((string) $item->current_stage) }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                                DIBATALKAN
                                            </span>
                                            @if($item->cancel_reason)
                                                <div class="text-[10px] text-slate-400 max-w-[160px] truncate mt-0.5" title="{{ $item->cancel_reason }}">
                                                    {{ $item->cancel_reason }}
                                                </div>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="px-2.5 py-2 text-right whitespace-nowrap">
                                        @if($item->status === \App\Models\FittingItem::STATUS_ACTIVE && ($item->stage_executions_count ?? 0) === 0)
                                            <button type="button"
                                                    onclick="openCancelTransferModal({{ $item->id }}, @js($item->traveler_number))"
                                                    class="inline-flex items-center px-2 py-1 rounded-lg text-[11px] font-bold bg-white hover:bg-rose-50 text-rose-600 border border-rose-200 transition-colors">
                                                <i class="fas fa-times-circle mr-1"></i> Batalkan Transfer
                                            </button>
                                        @elseif($item->status === \App\Models\FittingItem::STATUS_ACTIVE)
                                            <span class="text-[10px] text-slate-400 italic">Sudah di-scan</span>
                                        @else
                                            <span class="text-[10px] text-slate-400">{{ $item->cancelled_at ? $item->cancelled_at->format('d/m/Y H:i') : '-' }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="p-3 border-t border-slate-100">
                    {{ $transferredItems->links() }}
                </div>
            @endif

        <!-- TAB 3: DECISION HISTORY (NOT_CONTINUED & REOPENED) -->
        @else
            @if($decisions->isEmpty())
                <div class="p-10 text-center text-slate-500">
                    <p class="text-sm font-bold text-slate-700">Belum ada riwayat keputusan NOT_CONTINUED.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead class="bg-slate-50 text-slate-600 uppercase text-[10px] font-bold tracking-wider border-b border-slate-200">
                            <tr>
                                <th class="px-2.5 py-2 whitespace-nowrap">Nomor Kitir (PFKTR)</th>
                                <th class="px-2.5 py-2 whitespace-nowrap">Heat / Kode Produksi</th>
                                <th class="px-2.5 py-2">Customer / Item</th>
                                <th class="px-2.5 py-2 text-right whitespace-nowrap">Qty (PCS)</th>
                                <th class="px-2.5 py-2">Keputusan & Alasan</th>
                                <th class="px-2.5 py-2 whitespace-nowrap">Diputuskan Oleh</th>
                                <th class="px-2.5 py-2 text-center whitespace-nowrap">Status</th>
                                <th class="px-2.5 py-2 text-right whitespace-nowrap">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            @foreach($decisions as $dec)
                                @php
                                    $dk = $dec->lostWaxKitir;
                                    $dcr = $dec->lostWaxCastingResult;
                                    $canReopen = $dec->status === \App\Models\FittingKitirDecision::STATUS_ACTIVE
                                        && $dk
                                        && $dk->activeFittingItem === null
                                        && $dcr
                                        && $dcr->status === 'COMPLETED';
                                @endphp
                                <tr class="{{ $dec->status === \App\Models\FittingKitirDecision::STATUS_REOPENED ? 'bg-slate-50/80' : 'hover:bg-amber-50/30' }}">
                                    <td class="px-2.5 py-2 font-mono font-bold whitespace-nowrap">
                                        <span class="bg-amber-50 text-amber-800 border border-amber-200 px-1.5 py-0.5 rounded text-[11px]">
                                            {{ $dk?->traveler_number ?? '-' }}
                                        </span>
                                    </td>
                                    <td class="px-2.5 py-2">
                                        <div class="font-mono font-bold text-slate-900">{{ $dcr?->heat_number ?? '-' }}</div>
                                        <div class="font-mono text-[11px] text-slate-600">{{ $dk?->production_code ?? '-' }}</div>
                                    </td>
                                    <td class="px-2.5 py-2">
                                        <div class="font-medium text-slate-800">{{ $dk?->customer ?: '-' }}</div>
                                        <div class="text-[11px] text-slate-500">{{ $dk?->item_name ?: '-' }}</div>
                                    </td>
                                    <td class="px-2.5 py-2 text-right font-mono font-black text-slate-900 whitespace-nowrap">
                                        {{ number_format((int) ($dk?->quantity_pcs ?? 0)) }}
                                    </td>
                                    <td class="px-2.5 py-2">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                            {{ $dec->decision }}
                                        </span>
                                        <div class="text-xs text-slate-600 mt-1">
                                            {{ $dec->reason ?: '— (tanpa alasan)' }}
                                        </div>
                                        @if($dec->status === \App\Models\FittingKitirDecision::STATUS_REOPENED)
                                            <div class="text-[10px] text-emerald-700 mt-1">
                                                <i class="fas fa-undo mr-1"></i>
                                                Dikembalikan oleh <strong>{{ $dec->reopenedBy?->name ?? '-' }}</strong>
                                                ({{ $dec->reopened_at ? $dec->reopened_at->format('d/m/Y H:i') : '-' }})
                                                @if($dec->reopen_reason)
                                                    — "{{ $dec->reopen_reason }}"
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-2.5 py-2 whitespace-nowrap">
                                        <div class="font-semibold text-slate-800">{{ $dec->decidedBy?->name ?? '-' }}</div>
                                        <div class="text-[10px] text-slate-400">
                                            {{ $dec->decided_at ? $dec->decided_at->format('d/m/Y H:i') : '-' }}
                                        </div>
                                    </td>
                                    <td class="px-2.5 py-2 text-center whitespace-nowrap">
                                        @if($dec->status === \App\Models\FittingKitirDecision::STATUS_ACTIVE)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-800 border border-amber-200">
                                                NOT_CONTINUED
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600 border border-slate-300">
                                                REOPENED
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-2.5 py-2 text-right whitespace-nowrap">
                                        @if($canReopen)
                                            <button type="button"
                                                    onclick="openReopenModal({{ $dec->id }}, @js($dk->traveler_number))"
                                                    class="inline-flex items-center px-2 py-1 rounded-lg text-[11px] font-bold bg-emerald-600 hover:bg-emerald-700 text-white transition-colors shadow-sm">
                                                <i class="fas fa-undo mr-1"></i> Kembalikan ke Daftar Transfer
                                            </button>
                                        @else
                                            <span class="text-[10px] text-slate-400 italic">
                                                {{ $dec->status === \App\Models\FittingKitirDecision::STATUS_REOPENED ? 'Sudah dikembalikan' : 'Tidak dapat di-undo' }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="p-3 border-t border-slate-100">
                    {{ $decisions->links() }}
                </div>
            @endif
        @endif
    </div>
</div>

<!-- Modal: Mark NOT_CONTINUED -->
<div id="notContinuedModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white border border-slate-200 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex items-center gap-3 pb-3 border-b border-slate-100">
            <span class="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center text-lg shrink-0">
                <i class="fas fa-ban"></i>
            </span>
            <div>
                <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wider">Tandai Tidak Dilanjutkan</h3>
                <p class="text-xs text-slate-500">Kitir: <strong id="ncTravelerLabel" class="font-mono text-slate-800">-</strong> (<span id="ncMetaLabel">-</span>)</p>
            </div>
        </div>

        <form id="notContinuedForm" method="POST" action="" class="space-y-4">
            @csrf
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-amber-800 text-xs leading-relaxed">
                Kitir akan diberi status <strong>NOT_CONTINUED</strong> (tidak dihapus permanen), dikeluarkan dari daftar transfer aktif, dan tersimpan di <strong>Decision History</strong>.
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Alasan (Opsional)</label>
                <textarea name="reason" rows="3" maxlength="1000"
                          placeholder="Contoh: dijual as-cast, diproses di luar Fitting, dsb (boleh dikosongkan)..."
                          class="w-full bg-white border border-slate-300 rounded-lg p-2.5 text-xs text-slate-800 focus:outline-none focus:border-rose-500"></textarea>
            </div>
            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" onclick="closeModal('notContinuedModal')"
                        class="px-4 py-2 rounded-lg text-xs font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200">
                    Batal
                </button>
                <button type="submit"
                        class="px-4 py-2 rounded-lg text-xs font-bold bg-rose-600 hover:bg-rose-700 text-white shadow-sm">
                    <i class="fas fa-ban mr-1.5"></i> Simpan NOT_CONTINUED
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Reopen / Undo NOT_CONTINUED -->
<div id="reopenModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white border border-slate-200 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex items-center gap-3 pb-3 border-b border-slate-100">
            <span class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-lg shrink-0">
                <i class="fas fa-undo"></i>
            </span>
            <div>
                <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wider">Kembalikan ke Daftar Transfer</h3>
                <p class="text-xs text-slate-500">Kitir: <strong id="reopenTravelerLabel" class="font-mono text-slate-800">-</strong></p>
            </div>
        </div>

        <form id="reopenForm" method="POST" action="" class="space-y-4">
            @csrf
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-3 text-emerald-800 text-xs leading-relaxed">
                Status keputusan akan diubah menjadi <strong>REOPENED</strong>, histori keputusan tetap tersimpan, dan Kitir kembali muncul pada daftar <strong>Siap Ditransfer</strong>.
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Alasan Pengembalian (Opsional)</label>
                <textarea name="reopen_reason" rows="2" maxlength="1000"
                          placeholder="Catatan pengembalian (boleh dikosongkan)..."
                          class="w-full bg-white border border-slate-300 rounded-lg p-2.5 text-xs text-slate-800 focus:outline-none focus:border-emerald-500"></textarea>
            </div>
            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" onclick="closeModal('reopenModal')"
                        class="px-4 py-2 rounded-lg text-xs font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200">
                    Batal
                </button>
                <button type="submit"
                        class="px-4 py-2 rounded-lg text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm">
                    <i class="fas fa-undo mr-1.5"></i> Kembalikan ke Daftar Transfer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Cancel Transfer (before physical scan) -->
<div id="cancelTransferModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white border border-slate-200 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex items-center gap-3 pb-3 border-b border-slate-100">
            <span class="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center text-lg shrink-0">
                <i class="fas fa-times-circle"></i>
            </span>
            <div>
                <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wider">Batalkan Transfer Kitir</h3>
                <p class="text-xs text-slate-500">Kitir: <strong id="cancelTransferLabel" class="font-mono text-slate-800">-</strong></p>
            </div>
        </div>

        <form id="cancelTransferForm" method="POST" action="" class="space-y-4">
            @csrf
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-amber-800 text-xs leading-relaxed">
                Pembatalan hanya dapat dilakukan sebelum ada scan di lantai produksi Fitting. Kitir akan kembali tersedia di daftar transfer.
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Alasan Pembatalan <span class="text-rose-500">*</span></label>
                <textarea name="cancel_reason" rows="3" required minlength="3" maxlength="500"
                          placeholder="Masukkan alasan pembatalan transfer..."
                          class="w-full bg-white border border-slate-300 rounded-lg p-2.5 text-xs text-slate-800 focus:outline-none focus:border-rose-500"></textarea>
            </div>
            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" onclick="closeModal('cancelTransferModal')"
                        class="px-4 py-2 rounded-lg text-xs font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200">
                    Tutup
                </button>
                <button type="submit"
                        class="px-4 py-2 rounded-lg text-xs font-bold bg-rose-600 hover:bg-rose-700 text-white shadow-sm">
                    <i class="fas fa-times-circle mr-1.5"></i> Konfirmasi Batalkan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    const notContinuedUrlTemplate = @json(route('fitting.transfers.kitirs.not-continued', ['kitir' => '__ID__']));
    const reopenUrlTemplate = @json(route('fitting.transfers.decisions.reopen', ['decision' => '__ID__']));
    const cancelTransferUrlTemplate = @json(route('fitting.transfers.items.cancel', ['fittingItem' => '__ID__']));

    function openNotContinuedModal(kitirId, traveler, code, qty) {
        document.getElementById('ncTravelerLabel').textContent = traveler;
        document.getElementById('ncMetaLabel').textContent = code + ' • ' + qty + ' PCS';
        document.getElementById('notContinuedForm').action = notContinuedUrlTemplate.replace('__ID__', kitirId);
        document.getElementById('notContinuedModal').classList.remove('hidden');
    }

    function openReopenModal(decisionId, traveler) {
        document.getElementById('reopenTravelerLabel').textContent = traveler;
        document.getElementById('reopenForm').action = reopenUrlTemplate.replace('__ID__', decisionId);
        document.getElementById('reopenModal').classList.remove('hidden');
    }

    function openCancelTransferModal(itemId, traveler) {
        document.getElementById('cancelTransferLabel').textContent = traveler;
        document.getElementById('cancelTransferForm').action = cancelTransferUrlTemplate.replace('__ID__', itemId);
        document.getElementById('cancelTransferModal').classList.remove('hidden');
    }

    function closeModal(id) {
        document.getElementById(id).classList.add('hidden');
    }

    (function initTransferSelection() {
        const selectAll = document.getElementById('selectAllKitirs');
        const checkboxes = Array.from(document.querySelectorAll('.kitir-checkbox'));
        const lineSelects = Array.from(document.querySelectorAll('.kitir-line-select'));
        const countEl = document.getElementById('selectedCount');
        const pcsEl = document.getElementById('selectedPcs');
        const weightEl = document.getElementById('selectedWeight');
        const missingBadge = document.getElementById('missingLineBadge');
        const missingCountEl = document.getElementById('missingLineCount');
        const submitBtn = document.getElementById('btnSubmitTransfer');
        const form = document.getElementById('transferBatchForm');

        if (!checkboxes.length) return;

        function updateSummary() {
            let count = 0;
            let totalPcs = 0;
            let totalWeight = 0;
            let missingLineCount = 0;

            checkboxes.forEach((cb) => {
                const row = cb.closest('.kitir-transfer-row');
                const sel = row ? row.querySelector('.kitir-line-select') : null;
                const lineVal = sel ? sel.value : '';

                if (cb.checked) {
                    count++;
                    totalPcs += parseInt(cb.dataset.pcs || '0', 10);
                    totalWeight += parseFloat(cb.dataset.weight || '0');

                    if (!lineVal) {
                        missingLineCount++;
                        if (row) row.classList.add('bg-rose-50/70');
                        if (sel) sel.classList.add('border-rose-400', 'ring-1', 'ring-rose-300');
                    } else {
                        if (row) row.classList.remove('bg-rose-50/70');
                        if (sel) sel.classList.remove('border-rose-400', 'ring-1', 'ring-rose-300');
                    }
                } else {
                    if (row) row.classList.remove('bg-rose-50/70');
                    if (sel) sel.classList.remove('border-rose-400', 'ring-1', 'ring-rose-300');
                }
            });

            if (countEl) countEl.textContent = count.toLocaleString('id-ID');
            if (pcsEl) pcsEl.textContent = totalPcs.toLocaleString('id-ID');
            if (weightEl) weightEl.textContent = totalWeight.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            if (missingBadge && missingCountEl) {
                missingCountEl.textContent = missingLineCount;
                missingBadge.classList.toggle('hidden', missingLineCount === 0);
            }

            if (submitBtn) submitBtn.disabled = count === 0;
            if (selectAll) {
                selectAll.checked = count > 0 && count === checkboxes.length;
                selectAll.indeterminate = count > 0 && count < checkboxes.length;
            }
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checkboxes.forEach((cb) => { cb.checked = selectAll.checked; });
                updateSummary();
            });
        }

        checkboxes.forEach((cb) => cb.addEventListener('change', updateSummary));

        lineSelects.forEach((sel) => {
            sel.addEventListener('change', function () {
                const suggested = sel.dataset.suggested || '';
                const hint = sel.parentElement ? sel.parentElement.querySelector('.line-override-hint') : null;
                if (hint) {
                    const isOverridden = suggested !== '' && sel.value !== '' && sel.value !== suggested;
                    hint.classList.toggle('hidden', !isOverridden);
                }
                updateSummary();
            });
        });

        updateSummary();

        if (form && submitBtn) {
            form.addEventListener('submit', function () {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1.5"></i> Memproses Transfer...';
            });
        }
    })();
</script>
@endsection
