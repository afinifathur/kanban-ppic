@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full gap-4">
        <div class="shrink-0">
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Perintah Cor — Cor Pasir (Sand Casting)</h1>
            <p class="text-gray-500 text-[10px]">Alokasi dan penerbitan instruksi penuangan cor pasir berdasarkan rencana produksi</p>
        </div>
    </div>
@endsection

@section('content')
    @php
        $selectionStorageKey = 'sand-casting-casting-orders-selection-'.auth()->id().'-'.(auth()->user()->product_scope ?: 'all');
    @endphp

    @if(session('clear_pcor_selection'))
        <script>
            try {
                sessionStorage.removeItem(@json($selectionStorageKey));
            } catch (e) {}
        </script>
    @endif

    <style>
        #app-main-content {
            padding-top: 0 !important;
        }
        #composition-calculator-panel {
            position: sticky;
            top: 0;
            z-index: 20;
        }
    </style>

    <div class="space-y-3 pt-2 sm:pt-4">
        <!-- Flash Messages -->
        @if(session('success'))
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm px-4 py-3 rounded-lg flex items-center justify-between shadow-sm">
                <div class="flex items-center gap-2">
                    <i class="fas fa-check-circle text-emerald-500 text-base"></i>
                    <span>{{ session('success') }}</span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-emerald-600 text-sm">&times;</button>
            </div>
        @endif

        @if(session('error'))
            <div class="bg-red-50 border border-red-200 text-red-800 text-sm px-4 py-3 rounded-lg flex items-center justify-between shadow-sm">
                <div class="flex items-center gap-2">
                    <i class="fas fa-exclamation-circle text-red-500 text-base"></i>
                    <span>{{ session('error') }}</span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-red-400 hover:text-red-600 text-sm">&times;</button>
            </div>
        @endif

        <!-- Tab Navigation -->
        <div class="flex border-b border-slate-200 bg-white px-4 pt-2.5 rounded-t-xl shadow-sm">
            <a href="{{ route('sand-casting.casting-orders.plans', ['tab' => 'plans'] + request()->except(['tab', 'plans_page', 'orders_page'])) }}"
               class="px-4 py-2 text-xs font-bold border-b-2 transition flex items-center gap-2 {{ $activeTab === 'plans' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
                <i class="fas fa-clipboard-list"></i>
                <span>Rencana Cor (Demand)</span>
                <span class="bg-blue-100 text-blue-800 text-[10px] font-bold px-2 py-0.5 rounded-full">{{ $plans->total() }}</span>
            </a>
            <a href="{{ route('sand-casting.casting-orders.plans', ['tab' => 'orders'] + request()->except(['tab', 'plans_page', 'orders_page'])) }}"
               class="px-4 py-2 text-xs font-bold border-b-2 transition flex items-center gap-2 {{ $activeTab === 'orders' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
                <i class="fas fa-file-invoice"></i>
                <span>Dokumen Perintah Cor</span>
                <span class="bg-slate-100 text-slate-700 text-[10px] font-bold px-2 py-0.5 rounded-full">{{ $castingOrders->total() }}</span>
            </a>
        </div>

        @if($activeTab === 'plans')
            <!-- Filter Bar for Plans -->
            <div class="bg-white p-3.5 rounded-b-xl border border-slate-200 shadow-sm">
                <form method="GET" action="{{ route('sand-casting.casting-orders.plans') }}" data-plan-filter="true" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3">
                    <input type="hidden" name="tab" value="plans">

                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Status Rencana</label>
                        <select name="status" onchange="this.form.submit()" class="w-full bg-slate-50 border border-slate-200 text-xs rounded-lg px-2.5 py-1.5 focus:outline-none focus:border-blue-500 font-medium text-slate-700">
                            <option value="active" {{ request('status', 'active') === 'active' ? 'selected' : '' }}>Aktif (Tersedia Kuota)</option>
                            <option value="closed" {{ request('status') === 'closed' ? 'selected' : '' }}>Tertutup (Closed)</option>
                            <option value="all" {{ request('status') === 'all' ? 'selected' : '' }}>Semua Rencana</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Cari Kode</label>
                        <input type="text" name="code" list="codeList" value="{{ request('code') }}" placeholder="Kode item/plan..."
                               class="w-full bg-slate-50 border border-slate-200 text-xs rounded-lg px-2.5 py-1.5 focus:outline-none focus:border-blue-500 font-medium text-slate-700">
                        <datalist id="codeList">
                            @foreach($uniqueCodes as $code)
                                <option value="{{ $code }}">
                            @endforeach
                        </datalist>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Customer</label>
                        <input type="text" name="customer" list="customerList" value="{{ request('customer') }}" placeholder="Nama customer..."
                               class="w-full bg-slate-50 border border-slate-200 text-xs rounded-lg px-2.5 py-1.5 focus:outline-none focus:border-blue-500 font-medium text-slate-700">
                        <datalist id="customerList">
                            @foreach($uniqueCustomers as $customer)
                                <option value="{{ $customer }}">
                            @endforeach
                        </datalist>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Tanggal Buat</label>
                        <input type="date" name="date" value="{{ request('date') }}"
                               class="w-full bg-slate-50 border border-slate-200 text-xs rounded-lg px-2.5 py-1.5 focus:outline-none focus:border-blue-500 font-medium text-slate-700">
                    </div>

                    <div class="flex items-end gap-2">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs px-3 py-1.5 rounded-lg flex items-center justify-center gap-1.5 flex-1 shadow-sm transition">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                        <a href="{{ route('sand-casting.casting-orders.plans', ['tab' => 'plans']) }}" class="bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold text-xs px-3 py-1.5 rounded-lg transition" title="Reset Filter">
                            <i class="fas fa-undo"></i>
                        </a>
                    </div>
                </form>
            </div>

            <!-- Sticky Compact Calculator Header + Expandable Composition Details -->
            <div id="composition-calculator-panel" class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <!-- Baris 1: Compact Calculator Header -->
                <div class="flex flex-wrap items-center justify-between gap-2 px-3.5 py-2 bg-white">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <span class="w-6 h-6 rounded-md bg-blue-50 text-blue-600 border border-blue-100 flex items-center justify-center text-xs shrink-0">
                            <i class="fas fa-calculator"></i>
                        </span>
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-xs font-bold text-slate-800 tracking-tight">Kalkulator Rencana Cor</span>
                            <span class="text-slate-300">|</span>
                            <span id="selection-summary" class="text-[11px] font-semibold text-slate-500" aria-live="polite">0 rencana dipilih</span>
                            <button type="button" id="clear-selection-btn" class="hidden text-[11px] text-red-600 hover:text-red-800 hover:underline font-bold transition-colors items-center gap-1 ml-1">
                                <i class="fas fa-times-circle"></i> Bersihkan Pilihan
                            </button>
                        </div>
                    </div>

                    <!-- 4 Kolom Ringkasan Utama + Tombol Aksi -->
                    <div class="flex flex-wrap items-center gap-2 sm:gap-3 ml-auto">
                        <div id="selection-summary-bar" title="Akumulasi seluruh item terpilih lintas halaman pagination"
                            class="flex items-stretch divide-x divide-slate-200 bg-slate-50/70 border border-slate-200 rounded-lg shrink-0">
                            <div class="flex items-center gap-2 px-3 py-1">
                                <span class="text-blue-500 text-xs" aria-hidden="true"><i class="fas fa-check-square"></i></span>
                                <div class="text-right leading-tight">
                                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider whitespace-nowrap">Item Terpilih</div>
                                    <div id="summary-count" class="text-xs sm:text-sm font-extrabold text-slate-800 whitespace-nowrap" aria-live="polite">0 item</div>
                                    <span id="calc-total-items" class="hidden">0</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 px-3 py-1">
                                <span class="text-indigo-500 text-xs" aria-hidden="true"><i class="fas fa-cubes"></i></span>
                                <div class="text-right leading-tight">
                                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider whitespace-nowrap">Total Qty (PCS)</div>
                                    <div id="summary-qty" class="text-xs sm:text-sm font-extrabold text-slate-800 font-mono whitespace-nowrap" aria-live="polite"><span id="calc-total-pcs">0</span> pcs</div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 px-3 py-1">
                                <span class="text-emerald-500 text-xs" aria-hidden="true"><i class="fas fa-weight-hanging"></i></span>
                                <div class="text-right leading-tight">
                                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider whitespace-nowrap">Total Berat (KG)</div>
                                    <div id="summary-weight" class="text-xs sm:text-sm font-extrabold text-emerald-600 font-mono whitespace-nowrap" aria-live="polite"><span id="calc-total-kg">0.00</span> kg</div>
                                </div>
                            </div>
                        </div>

                        <button type="button" id="btn-create-order" onclick="submitSelectedPlans()"
                            class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-3.5 py-2 rounded-lg text-xs flex items-center gap-1.5 shadow-sm transition disabled:opacity-50 disabled:cursor-not-allowed shrink-0"
                            disabled>
                            <i class="fas fa-plus"></i>
                            <span>Buat Perintah Cor (<span id="selected-count">0</span>)</span>
                        </button>
                    </div>
                </div>

                <!-- Baris 2: Toggle Strip Rincian Komposisi (Default Collapsed) -->
                <div class="border-t border-slate-200 bg-slate-50/90 px-3.5 py-1 flex items-center justify-between">
                    <button type="button" id="toggle-composition-btn" aria-expanded="false" aria-controls="composition-details-drawer"
                        class="text-[11px] font-bold text-slate-600 hover:text-blue-600 flex items-center gap-1.5 py-0.5 focus:outline-none transition cursor-pointer">
                        <i class="fas fa-layer-group text-blue-500 text-[10px]"></i>
                        <span>Rincian Komposisi</span>
                        <span id="composition-toggle-arrow" class="text-[10px] text-slate-500 ml-0.5">▼</span>
                    </button>

                    <!-- Mini inline summary when collapsed -->
                    <div id="composition-inline-hint" class="hidden sm:flex items-center gap-3 text-[10px] text-slate-500 font-medium">
                        <span>Local: <strong id="mini-local-kg" class="text-blue-700 font-mono">0.00 kg</strong></span>
                        <span>Export: <strong id="mini-export-kg" class="text-purple-700 font-mono">0.00 kg</strong></span>
                        <span class="text-slate-300">|</span>
                        <span>L1: <strong id="mini-line-1-kg" class="text-slate-700 font-mono">0.00 kg</strong></span>
                        <span>L2: <strong id="mini-line-2-kg" class="text-slate-700 font-mono">0.00 kg</strong></span>
                        <span>L3: <strong id="mini-line-3-kg" class="text-slate-700 font-mono">0.00 kg</strong></span>
                        <span>L4: <strong id="mini-line-4-kg" class="text-slate-700 font-mono">0.00 kg</strong></span>
                    </div>
                </div>

                <!-- Collapsible Rincian Komposisi Drawer (Default COLLAPSED / hidden) -->
                <div id="composition-details-drawer" class="hidden border-t border-slate-200 bg-slate-50/95 px-3.5 py-2.5">
                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-2.5">
                        <!-- A. Komposisi Customer (Market) -->
                        <div class="lg:col-span-5 bg-white rounded-lg border border-slate-200 p-2">
                            <div class="text-[9px] font-bold text-slate-500 uppercase tracking-wider mb-1.5 flex items-center gap-1">
                                <i class="fas fa-globe-asia text-blue-500"></i> Komposisi Customer (Market)
                            </div>
                            <div class="grid grid-cols-3 gap-1.5">
                                <div class="bg-blue-50/40 rounded border border-blue-200 px-2 py-1.5">
                                    <div class="text-[9px] font-extrabold text-blue-700 uppercase">LOCAL</div>
                                    <div class="text-xs font-extrabold text-slate-800 font-mono leading-tight mt-0.5"><span id="calc-cust-local-pcs">0</span> <span class="text-[9px] font-normal text-slate-500">pcs</span></div>
                                    <div class="text-[10px] font-bold text-emerald-600 font-mono leading-tight"><span id="calc-cust-local-kg">0.00</span> kg</div>
                                </div>
                                <div class="bg-purple-50/40 rounded border border-purple-200 px-2 py-1.5">
                                    <div class="text-[9px] font-extrabold text-purple-700 uppercase">EXPORT</div>
                                    <div class="text-xs font-extrabold text-slate-800 font-mono leading-tight mt-0.5"><span id="calc-cust-export-pcs">0</span> <span class="text-[9px] font-normal text-slate-500">pcs</span></div>
                                    <div class="text-[10px] font-bold text-emerald-600 font-mono leading-tight"><span id="calc-cust-export-kg">0.00</span> kg</div>
                                </div>
                                <div id="calc-cust-unclassified-card" class="bg-slate-50 rounded border border-slate-200 px-2 py-1.5">
                                    <div class="text-[9px] font-extrabold text-slate-600 uppercase">UNCLASSIFIED</div>
                                    <div class="text-xs font-extrabold text-slate-800 font-mono leading-tight mt-0.5"><span id="calc-cust-unclassified-pcs">0</span> <span class="text-[9px] font-normal text-slate-500">pcs</span></div>
                                    <div class="text-[10px] font-bold text-emerald-600 font-mono leading-tight"><span id="calc-cust-unclassified-kg">0.00</span> kg</div>
                                </div>
                            </div>
                        </div>

                        <!-- B. Komposisi Beban Line -->
                        <div class="lg:col-span-7 bg-white rounded-lg border border-slate-200 p-2">
                            <div class="text-[9px] font-bold text-slate-500 uppercase tracking-wider mb-1.5 flex items-center gap-1">
                                <i class="fas fa-stream text-amber-500"></i> Komposisi Beban Line
                            </div>
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-1.5">
                                @for($lineNum = 1; $lineNum <= 4; $lineNum++)
                                    <div class="bg-slate-50/70 rounded border border-slate-200 px-2 py-1.5">
                                        <div class="text-[9px] font-extrabold text-slate-600 uppercase">LINE {{ $lineNum }}</div>
                                        <div class="text-xs font-extrabold text-slate-800 font-mono leading-tight mt-0.5"><span id="calc-line-{{ $lineNum }}-pcs">0</span> <span class="text-[9px] font-normal text-slate-500">pcs</span></div>
                                        <div class="text-[10px] font-bold text-emerald-600 font-mono leading-tight"><span id="calc-line-{{ $lineNum }}-kg">0.00</span> kg</div>
                                    </div>
                                @endfor
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Peringatan Validasi Kuota (muncul otomatis jika Qty melebihi kuota / kuota berubah) -->
                <div id="quota-warning-banner" class="hidden border-t border-amber-200 bg-amber-50 px-3.5 py-1.5 text-amber-800 text-xs items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-exclamation-triangle text-amber-500"></i>
                        <span id="quota-warning-text" class="font-semibold"></span>
                    </div>
                    <button type="button" onclick="hideQuotaWarning()" class="text-amber-500 hover:text-amber-700 font-bold text-sm leading-none">&times;</button>
                </div>
            </div>

            <!-- Plans Table -->
            <form id="form-create-order" action="{{ route('sand-casting.casting-orders.create') }}" method="GET">
                <div id="selected-plan-inputs"></div>

                <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-xs">
                            <thead class="bg-slate-50 text-slate-600 font-bold uppercase tracking-wider">
                                <tr>
                                    <th class="p-3 text-center w-10">
                                        <input type="checkbox" id="check-all" class="rounded text-blue-600 focus:ring-blue-500 border-slate-300">
                                    </th>
                                    <th class="p-3 text-center w-12">No</th>
                                    <th class="p-3 text-left">Kode Cust</th>
                                    <th class="p-3 text-left">Customer</th>
                                    <th class="p-3 text-left">Nama Produk</th>
                                    <th class="p-3 text-center">Ukuran / AISI</th>
                                    <th class="p-3 text-center">Berat Bersih</th>
                                    <th class="p-3 text-center w-28">Line</th>
                                    <th class="p-3 text-center">Qty Rencana</th>
                                    <th class="p-3 text-center">Qty Sudah Diperintah</th>
                                    <th class="p-3 text-center w-36">Qty Sisa Dapat Diperintah</th>
                                    <th class="p-3 text-center">Tgl Input</th>
                                    <th class="p-3 text-center">Status</th>
                                    <th class="p-3 text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                @forelse($plans as $index => $plan)
                                    @php
                                        $scheduled = (int) ($plan->qty_casting_scheduled ?? 0);
                                        $remainingToOrder = max(0, $plan->qty_planned - $scheduled);
                                        $canSelect = ! $plan->is_closed && $remainingToOrder > 0;
                                        $unitWeight = (float) ($plan->weight ?? 0);
                                        $defaultLine = (int) ($plan->line_number ?: 1);
                                        if ($defaultLine < 1 || $defaultLine > 4) {
                                            $defaultLine = 1;
                                        }
                                        $customerCategory = \App\Models\Customer::resolveCategory($plan->customer, $customerCategoryMap ?? []);
                                    @endphp
                                    <tr class="hover:bg-slate-50 transition {{ $plan->is_closed ? 'bg-slate-50/50 opacity-60' : '' }}"
                                        data-plan-row="{{ $plan->id }}"
                                        data-plan-code="{{ $plan->code }}"
                                        data-plan-selectable="{{ $canSelect ? '1' : '0' }}">
                                        <td class="p-3 text-center">
                                            @if($canSelect)
                                                <input type="checkbox" value="{{ $plan->id }}"
                                                    data-code="{{ $plan->code }}"
                                                    data-qty="{{ $remainingToOrder }}"
                                                    data-max-qty="{{ $remainingToOrder }}"
                                                    data-weight="{{ $unitWeight }}"
                                                    data-line="{{ $defaultLine }}"
                                                    data-category="{{ $customerCategory }}"
                                                    class="plan-checkbox rounded text-blue-600 focus:ring-blue-500 border-slate-300">
                                            @else
                                                <span class="text-slate-300"><i class="fas fa-ban"></i></span>
                                            @endif
                                        </td>
                                        <td class="p-3 text-center font-mono text-slate-400">{{ $plans->firstItem() + $index }}</td>
                                        <td class="p-3 font-mono font-bold text-blue-700">{{ $plan->code }}</td>
                                        <td class="p-3 font-medium">
                                            <div class="flex items-center gap-1.5 flex-wrap">
                                                <span class="font-bold text-slate-800 uppercase">{{ $plan->customer ?: '-' }}</span>
                                                @if($customerCategory === 'LOCAL')
                                                    <span class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-blue-50 text-blue-700 border border-blue-200">LOCAL</span>
                                                @elseif($customerCategory === 'EXPORT')
                                                    <span class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-purple-50 text-purple-700 border border-purple-200">EXPORT</span>
                                                @else
                                                    <span class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-slate-100 text-slate-500 border border-slate-200">UNCLASSIFIED</span>
                                                @endif
                                            </div>
                                            @if($plan->po_number)
                                                <div class="text-[10px] text-slate-400 font-mono">{{ $plan->po_number }}</div>
                                            @endif
                                        </td>
                                        <td class="p-3 font-semibold text-slate-800">
                                            <div>{{ $plan->item_name }}</div>
                                            @if($plan->item_code)
                                                <div class="text-[10px] text-slate-400 font-mono">{{ $plan->item_code }}</div>
                                            @endif
                                        </td>
                                        <td class="p-3 text-center font-mono text-slate-600">{{ $plan->size ?: '-' }} / {{ $plan->aisi ?: '-' }}</td>
                                        <td class="p-3 text-center font-mono font-semibold text-emerald-700">
                                            {{ $unitWeight > 0 ? number_format($unitWeight, 2).' kg' : '-' }}
                                        </td>
                                        <td class="p-3 text-center">
                                            @if($canSelect)
                                                <select data-plan-line="{{ $plan->id }}"
                                                    class="plan-line-select bg-white border border-slate-300 rounded px-2 py-1 text-xs font-bold text-slate-700 focus:outline-none focus:border-blue-500">
                                                    @for($l = 1; $l <= 4; $l++)
                                                        <option value="{{ $l }}" {{ $defaultLine === $l ? 'selected' : '' }}>Line {{ $l }}</option>
                                                    @endfor
                                                </select>
                                            @else
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                                    Line {{ $defaultLine }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="p-3 text-center font-bold text-slate-900">{{ number_format($plan->qty_planned) }}</td>
                                        <td class="p-3 text-center font-medium {{ $scheduled > 0 ? 'text-amber-600' : 'text-slate-400' }}">
                                            {{ number_format($scheduled) }}
                                        </td>
                                        <td class="p-3 text-center">
                                            @if($canSelect)
                                                <input type="number" data-plan-qty="{{ $plan->id }}"
                                                    value="{{ $remainingToOrder }}" min="1" max="{{ $remainingToOrder }}"
                                                    class="plan-qty-input w-20 bg-white border border-blue-300 rounded px-2 py-1 text-center text-xs font-bold text-blue-700 focus:outline-none focus:ring-1 focus:ring-blue-500">
                                                <div class="text-[9px] text-slate-400 mt-0.5">Sisa: {{ number_format($remainingToOrder) }} pcs</div>
                                            @else
                                                <span class="font-bold text-emerald-600">{{ number_format($remainingToOrder) }}</span>
                                            @endif
                                        </td>
                                        <td class="p-3 text-center text-slate-500 whitespace-nowrap">{{ $plan->created_at ? $plan->created_at->format('d/m/Y') : '-' }}</td>
                                        <td class="p-3 text-center">
                                            @if($plan->is_closed)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-200 text-slate-700">CLOSED</span>
                                            @elseif($remainingToOrder <= 0)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">LENGKAP</span>
                                            @else
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-100 text-blue-800">TERSEDIA</span>
                                            @endif
                                        </td>
                                        <td class="p-3 text-center whitespace-nowrap">
                                            @if(! $plan->is_closed)
                                                <button type="button"
                                                    onclick="submitSinglePlanAction('close_plan', '{{ $plan->id }}', 'Tutup rencana ini agar tidak dapat dibuatkan Perintah Cor baru?')"
                                                    class="text-slate-400 hover:text-red-500 font-bold p-1" title="Tutup Rencana (Close)">
                                                    <i class="fas fa-lock"></i>
                                                </button>
                                            @else
                                                <button type="button"
                                                    onclick="submitSinglePlanAction('open_plan', '{{ $plan->id }}', 'Buka kembali rencana ini?')"
                                                    class="text-slate-400 hover:text-emerald-500 font-bold p-1" title="Buka Rencana (Reopen)">
                                                    <i class="fas fa-lock-open"></i>
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="14" class="p-8 text-center text-slate-400">
                                            <div class="flex flex-col items-center justify-center gap-2">
                                                <i class="fas fa-inbox text-3xl opacity-50"></i>
                                                <p>Tidak ada rencana produksi Sand Casting yang sesuai kriteria.</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($plans->hasPages())
                        <div class="p-4 border-t border-slate-100 bg-slate-50">
                            {{ $plans->links() }}
                        </div>
                    @endif
                </div>
            </form>

        @elseif($activeTab === 'orders')
            <!-- Filter Bar for Casting Orders (Monitoring Harian & Histori) -->
            <div class="bg-white p-4 rounded-b-xl border border-slate-200 shadow-sm">
                <form method="GET" action="{{ route('sand-casting.casting-orders.plans') }}" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3">
                    <input type="hidden" name="tab" value="orders">

                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">No Perintah Cor</label>
                        <input type="text" name="casting_order_number" value="{{ request('casting_order_number') }}" placeholder="PCOR-..."
                               class="w-full bg-slate-50 border border-slate-200 text-xs rounded-lg px-2.5 py-1.5 focus:outline-none focus:border-blue-500 font-medium text-slate-700">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Tgl Rencana Cor</label>
                        <input type="date" name="scheduled_date" value="{{ request('scheduled_date') }}"
                               class="w-full bg-slate-50 border border-slate-200 text-xs rounded-lg px-2.5 py-1.5 focus:outline-none focus:border-blue-500 font-medium text-slate-700">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Cari Item / Kode</label>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari dalam dokumen..."
                               class="w-full bg-slate-50 border border-slate-200 text-xs rounded-lg px-2.5 py-1.5 focus:outline-none focus:border-blue-500 font-medium text-slate-700">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Status Dokumen</label>
                        <select name="order_status" onchange="this.form.submit()" class="w-full bg-slate-50 border border-slate-200 text-xs rounded-lg px-2.5 py-1.5 focus:outline-none focus:border-blue-500 font-medium text-slate-700">
                            <option value="">Semua Status</option>
                            <option value="DRAFT" {{ request('order_status') === 'DRAFT' ? 'selected' : '' }}>DRAFT</option>
                            <option value="ISSUED" {{ request('order_status') === 'ISSUED' ? 'selected' : '' }}>ISSUED</option>
                            <option value="PARTIALLY_COMPLETED" {{ request('order_status') === 'PARTIALLY_COMPLETED' ? 'selected' : '' }}>PARTIALLY COMPLETED</option>
                            <option value="COMPLETED" {{ request('order_status') === 'COMPLETED' ? 'selected' : '' }}>COMPLETED</option>
                            <option value="CANCELLED" {{ request('order_status') === 'CANCELLED' ? 'selected' : '' }}>CANCELLED</option>
                        </select>
                    </div>

                    <div class="flex items-end gap-2">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs px-3 py-2 rounded-lg flex items-center justify-center gap-1.5 flex-1 shadow-sm transition">
                            <i class="fas fa-search"></i> Cari Dokumen
                        </button>
                        <a href="{{ route('sand-casting.casting-orders.plans', ['tab' => 'orders']) }}" class="bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold text-xs px-3 py-2 rounded-lg transition" title="Reset">
                            <i class="fas fa-undo"></i>
                        </a>
                    </div>
                </form>
            </div>

            <!-- Orders Table -->
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-xs">
                        <thead class="bg-slate-50 text-slate-600 font-bold uppercase tracking-wider">
                            <tr>
                                <th class="p-3 text-center w-12">No</th>
                                <th class="p-3 text-left">No Perintah Cor</th>
                                <th class="p-3 text-center">Tgl Rencana Cor</th>
                                <th class="p-3 text-center">Total Item</th>
                                <th class="p-3 text-center">Total Qty (Pcs)</th>
                                <th class="p-3 text-left w-64">Progress Eksekusi (Pcs)</th>
                                <th class="p-3 text-left">Status Item (Lines)</th>
                                <th class="p-3 text-center">Status Dokumen</th>
                                <th class="p-3 text-left">Dibuat Oleh</th>
                                <th class="p-3 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            @forelse($castingOrders as $index => $order)
                                @php
                                    $completedLines = $order->lines->filter(fn($l) => $l->execution_status === 'COMPLETED')->count();
                                    $inProgressLines = $order->lines->filter(fn($l) => $l->execution_status === 'IN_PROGRESS')->count();
                                    $totalLines = $order->lines->count();
                                    $overcastPcs = max(0, $order->qty_cast_good - $order->qty_ordered);
                                @endphp
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="p-3 text-center font-mono text-slate-400">{{ $castingOrders->firstItem() + $index }}</td>
                                    <td class="p-3 font-mono font-bold text-blue-700">
                                        <a href="{{ route('sand-casting.casting-orders.show', $order) }}" class="hover:underline">
                                            {{ $order->casting_order_number }}
                                        </a>
                                    </td>
                                    <td class="p-3 text-center font-medium">{{ $order->scheduled_date ? $order->scheduled_date->format('d/m/Y') : '-' }}</td>
                                    <td class="p-3 text-center font-medium">{{ $totalLines }} item</td>
                                    <td class="p-3 text-center font-bold text-slate-900">{{ number_format($order->qty_ordered) }} pcs</td>
                                    <td class="p-3 w-64">
                                        <div class="space-y-1">
                                            <div class="flex items-center justify-between text-xs gap-1">
                                                <span class="font-bold text-slate-800">{{ $order->progress_percent }}%</span>
                                                <div class="flex items-center gap-1">
                                                    <span class="text-slate-500 font-medium">{{ number_format($order->qty_cast_good) }} / {{ number_format($order->qty_ordered) }} pcs</span>
                                                    @if($overcastPcs > 0)
                                                        <span class="px-1.5 py-0.2 rounded bg-indigo-50 text-indigo-700 border border-indigo-200 text-[9px] font-bold" title="Aktual hasil cor melebihi Qty Perintah (+{{ number_format($overcastPcs) }} pcs)">
                                                            +{{ number_format($overcastPcs) }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="w-full bg-slate-100 rounded-full h-1.5 overflow-hidden">
                                                <div class="bg-blue-600 h-1.5 rounded-full transition-all duration-300" style="width: {{ min(100, $order->progress_percent) }}%"></div>
                                            </div>
                                            <div class="flex flex-wrap gap-x-2 text-[10px] text-slate-400 font-semibold uppercase">
                                                <span>Good: <strong class="text-emerald-700">{{ number_format($order->qty_cast_good) }}</strong></span>
                                                <span>Defect: <strong class="text-red-600">{{ number_format($order->qty_cast_reject) }}</strong></span>
                                                <span>Ostd: <strong class="text-amber-700">{{ number_format($order->qty_outstanding) }}</strong></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="p-3">
                                        @if($totalLines > 0 && $completedLines === $totalLines)
                                            <span class="text-emerald-600 text-xs font-semibold flex items-center gap-1">
                                                <i class="fas fa-check-circle text-[10px]"></i> Selesai ({{ $completedLines }}/{{ $totalLines }})
                                            </span>
                                        @elseif($completedLines > 0 || $inProgressLines > 0)
                                            <span class="text-amber-600 text-xs font-semibold flex items-center gap-1">
                                                <i class="fas fa-spinner fa-spin text-[10px]"></i> Sebagian ({{ $completedLines }} selesai, {{ $inProgressLines }} proses)
                                            </span>
                                        @else
                                            <span class="text-slate-400 text-xs flex items-center gap-1">
                                                <i class="far fa-circle text-[10px]"></i> Belum Mulai
                                            </span>
                                        @endif
                                    </td>
                                    <td class="p-3 text-center">
                                        @if($order->status === 'DRAFT')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">DRAFT</span>
                                        @elseif($order->status === 'ISSUED')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-100 text-blue-800">ISSUED</span>
                                        @elseif($order->status === 'PARTIALLY_COMPLETED')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">PARTIALLY COMPLETED</span>
                                        @elseif($order->status === 'COMPLETED')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">COMPLETED</span>
                                        @elseif($order->status === 'CANCELLED')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-100 text-red-800">CANCELLED</span>
                                        @endif
                                    </td>
                                    <td class="p-3 text-slate-500">{{ $order->creator->name ?? '-' }}</td>
                                    <td class="p-3 text-center whitespace-nowrap">
                                        <div class="flex items-center justify-center gap-1">
                                            <a href="{{ route('sand-casting.casting-orders.show', $order) }}"
                                               class="bg-blue-50 text-blue-600 hover:bg-blue-100 font-bold px-2 py-1 rounded text-xs transition" title="Lihat Dokumen">
                                                <i class="fas fa-eye"></i> Detail
                                            </a>
                                            @if($order->status === 'DRAFT')
                                                <a href="{{ route('sand-casting.casting-orders.edit', $order) }}"
                                                   class="bg-amber-50 text-amber-600 hover:bg-amber-100 font-bold px-2 py-1 rounded text-xs transition" title="Edit Dokumen">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            @endif
                                            <a href="{{ route('sand-casting.casting-orders.print', $order) }}" target="_blank"
                                               class="bg-slate-100 text-slate-600 hover:bg-slate-200 font-bold px-2 py-1 rounded text-xs transition" title="Cetak Dokumen">
                                                <i class="fas fa-print"></i> Print
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="p-8 text-center text-slate-400">
                                        <div class="flex flex-col items-center justify-center gap-2">
                                            <i class="fas fa-inbox text-3xl opacity-50"></i>
                                            <p>Belum ada Dokumen Perintah Cor yang dibuat.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($castingOrders->hasPages())
                    <div class="p-4 border-t border-slate-100 bg-slate-50">
                        {{ $castingOrders->links() }}
                    </div>
                @endif
            </div>
        @endif
    </div>

    <!-- Hidden helper form for single close/open plan actions -->
    <form id="single-plan-action-form" action="{{ route('sand-casting.casting-orders.store') }}" method="POST" class="hidden">
        @csrf
        <input type="hidden" name="action" id="single-plan-action-val" value="">
        <input type="hidden" name="production_plan_id" id="single-plan-id-val" value="">
    </form>

    <script>
        function submitSinglePlanAction(action, planId, confirmMsg) {
            if (confirm(confirmMsg)) {
                document.getElementById('single-plan-action-val').value = action;
                document.getElementById('single-plan-id-val').value = planId;
                document.getElementById('single-plan-action-form').submit();
            }
        }

        function showQuotaWarning(message) {
            const banner = document.getElementById('quota-warning-banner');
            const textEl = document.getElementById('quota-warning-text');
            if (banner && textEl) {
                textEl.textContent = message;
                banner.classList.remove('hidden');
                banner.classList.add('flex');
            }
        }

        function hideQuotaWarning() {
            const banner = document.getElementById('quota-warning-banner');
            if (banner) {
                banner.classList.add('hidden');
                banner.classList.remove('flex');
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            const selectionStorageKey = @json($selectionStorageKey);
            const checkAll = document.getElementById('check-all');
            const checkboxes = Array.from(document.querySelectorAll('.plan-checkbox'));
            const qtyInputs = Array.from(document.querySelectorAll('.plan-qty-input'));
            const lineSelects = Array.from(document.querySelectorAll('.plan-line-select'));
            const btnCreate = document.getElementById('btn-create-order');
            const selectedCountSpan = document.getElementById('selected-count');
            const selectionSummary = document.getElementById('selection-summary');
            const clearSelectionBtn = document.getElementById('clear-selection-btn');
            const selectedPlanInputs = document.getElementById('selected-plan-inputs');
            const summaryCountEl = document.getElementById('summary-count');
            const toggleCompBtn = document.getElementById('toggle-composition-btn');
            const compDrawer = document.getElementById('composition-details-drawer');
            const compArrow = document.getElementById('composition-toggle-arrow');

            // Autocollapse toggle behavior (Default: COLLAPSED)
            if (toggleCompBtn && compDrawer) {
                toggleCompBtn.addEventListener('click', function () {
                    const isHidden = compDrawer.classList.contains('hidden');
                    if (isHidden) {
                        compDrawer.classList.remove('hidden');
                        toggleCompBtn.setAttribute('aria-expanded', 'true');
                        if (compArrow) compArrow.textContent = '▲';
                    } else {
                        compDrawer.classList.add('hidden');
                        toggleCompBtn.setAttribute('aria-expanded', 'false');
                        if (compArrow) compArrow.textContent = '▼';
                    }
                });
            }

            function formatQty(value) {
                return Number(value || 0).toLocaleString('en-US');
            }

            function formatWeight(value) {
                return Number(value || 0).toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                });
            }

            function getRowValues(checkbox) {
                const planId = parseInt(checkbox.value, 10);
                const code = checkbox.getAttribute('data-code') || ('#' + planId);
                const maxQty = parseInt(checkbox.getAttribute('data-max-qty') || checkbox.getAttribute('data-qty'), 10) || 1;
                const qtyInput = document.querySelector('.plan-qty-input[data-plan-qty="' + planId + '"]');
                const lineSelect = document.querySelector('.plan-line-select[data-plan-line="' + planId + '"]');

                let rawQty = qtyInput ? parseInt(qtyInput.value, 10) : parseInt(checkbox.getAttribute('data-qty'), 10);
                let qty = Number.isInteger(rawQty) ? rawQty : maxQty;

                if (qty < 1) {
                    qty = 1;
                }
                if (qty > maxQty) {
                    qty = maxQty;
                }

                let line = lineSelect ? (parseInt(lineSelect.value, 10) || 1) : (parseInt(checkbox.getAttribute('data-line'), 10) || 1);
                if (line < 1 || line > 4) line = 1;

                const weight = parseFloat(checkbox.getAttribute('data-weight')) || 0;
                const category = (checkbox.getAttribute('data-category') || 'UNCLASSIFIED').toUpperCase();

                return {
                    id: planId,
                    code: code,
                    selected: true,
                    qty: qty,
                    maxQty: maxQty,
                    weight: weight,
                    line: line,
                    category: category,
                };
            }

            function readStoredSelection() {
                try {
                    const raw = sessionStorage.getItem(selectionStorageKey);
                    if (!raw) {
                        return { ids: [], meta: {} };
                    }
                    const parsed = JSON.parse(raw);
                    const ids = [];
                    const meta = {};

                    if (Array.isArray(parsed)) {
                        parsed.forEach(function (value) {
                            let id = null;
                            if (value !== null && typeof value === 'object') {
                                id = parseInt(value.id, 10);
                                if (Number.isInteger(id) && id > 0 && value.selected !== false) {
                                    meta[id] = {
                                        id: id,
                                        code: value.code || ('#' + id),
                                        selected: true,
                                        qty: parseInt(value.qty, 10) || 1,
                                        maxQty: parseInt(value.maxQty || value.qty, 10) || 1,
                                        weight: parseFloat(value.weight) || 0,
                                        line: parseInt(value.line, 10) || 1,
                                        category: (value.category || 'UNCLASSIFIED').toUpperCase(),
                                    };
                                }
                            } else {
                                id = parseInt(value, 10);
                            }

                            if (Number.isInteger(id) && id > 0 && ids.indexOf(id) === -1) {
                                ids.push(id);
                            }
                        });
                    } else if (parsed !== null && typeof parsed === 'object') {
                        Object.keys(parsed).forEach(function (key) {
                            const entry = parsed[key];
                            const id = parseInt(entry && entry.id ? entry.id : key, 10);
                            if (Number.isInteger(id) && id > 0 && entry && entry.selected !== false) {
                                ids.push(id);
                                meta[id] = {
                                    id: id,
                                    code: entry.code || ('#' + id),
                                    selected: true,
                                    qty: parseInt(entry.qty, 10) || 1,
                                    maxQty: parseInt(entry.maxQty || entry.qty, 10) || 1,
                                    weight: parseFloat(entry.weight) || 0,
                                    line: parseInt(entry.line, 10) || 1,
                                    category: (entry.category || 'UNCLASSIFIED').toUpperCase(),
                                };
                            }
                        });
                    }

                    return { ids: ids, meta: meta };
                } catch (error) {
                    return { ids: [], meta: {} };
                }
            }

            function persistSelection() {
                const stateMap = {};
                Array.from(selectedIds).forEach(function (id) {
                    const m = selectedMeta[id] || { qty: 1, weight: 0, line: 1, category: 'UNCLASSIFIED' };
                    stateMap[id] = {
                        id: id,
                        code: m.code || ('#' + id),
                        selected: true,
                        qty: m.qty,
                        maxQty: m.maxQty || m.qty,
                        weight: m.weight,
                        line: m.line || 1,
                        category: m.category || 'UNCLASSIFIED',
                    };
                });

                sessionStorage.setItem(selectionStorageKey, JSON.stringify(stateMap));
            }

            function clearSelection() {
                sessionStorage.removeItem(selectionStorageKey);
            }

            const stored = readStoredSelection();
            let selectedIds = new Set(stored.ids);
            let selectedMeta = stored.meta;

            function validateVisibleQuotaAgainstStoredState() {
                const rows = Array.from(document.querySelectorAll('tr[data-plan-row]'));
                rows.forEach(function (row) {
                    const planId = parseInt(row.getAttribute('data-plan-row'), 10);
                    if (!selectedIds.has(planId)) return;

                    const planCode = row.getAttribute('data-plan-code') || ('#' + planId);
                    const selectable = row.getAttribute('data-plan-selectable') === '1';
                    const checkbox = row.querySelector('.plan-checkbox');

                    if (!selectable || !checkbox) {
                        selectedIds.delete(planId);
                        delete selectedMeta[planId];
                        showQuotaWarning('Perhatian: Rencana ' + planCode + ' sudah tidak memiliki sisa kuota atau telah ditutup, sehingga dihapus dari pilihan.');
                        return;
                    }

                    const maxQty = parseInt(checkbox.getAttribute('data-max-qty') || checkbox.getAttribute('data-qty'), 10) || 1;
                    if (selectedMeta[planId] && selectedMeta[planId].qty > maxQty) {
                        selectedMeta[planId].qty = maxQty;
                        selectedMeta[planId].maxQty = maxQty;
                        showQuotaWarning('Perhatian: Sisa kuota untuk ' + planCode + ' berubah menjadi ' + formatQty(maxQty) + ' pcs. Qty pilihan telah disesuaikan.');
                    }
                });
            }

            function syncVisibleCheckboxes() {
                validateVisibleQuotaAgainstStoredState();

                checkboxes.forEach(function (checkbox) {
                    const planId = parseInt(checkbox.value, 10);
                    checkbox.checked = selectedIds.has(planId);

                    const qtyInput = document.querySelector('.plan-qty-input[data-plan-qty="' + planId + '"]');
                    const lineSelect = document.querySelector('.plan-line-select[data-plan-line="' + planId + '"]');
                    const maxQty = parseInt(checkbox.getAttribute('data-max-qty') || checkbox.getAttribute('data-qty'), 10) || 1;

                    if (checkbox.checked) {
                        if (!selectedMeta[planId]) {
                            selectedMeta[planId] = getRowValues(checkbox);
                        } else {
                            // Ensure weight/category/code are hydrated from DOM if missing
                            selectedMeta[planId].weight = parseFloat(checkbox.getAttribute('data-weight')) || selectedMeta[planId].weight || 0;
                            selectedMeta[planId].category = (checkbox.getAttribute('data-category') || selectedMeta[planId].category || 'UNCLASSIFIED').toUpperCase();
                            selectedMeta[planId].code = checkbox.getAttribute('data-code') || selectedMeta[planId].code || ('#' + planId);
                            selectedMeta[planId].maxQty = maxQty;

                            if (qtyInput && selectedMeta[planId].qty > 0) {
                                qtyInput.value = Math.min(maxQty, selectedMeta[planId].qty);
                                checkbox.setAttribute('data-qty', qtyInput.value);
                            }
                            if (lineSelect && selectedMeta[planId].line >= 1 && selectedMeta[planId].line <= 4) {
                                lineSelect.value = selectedMeta[planId].line;
                                checkbox.setAttribute('data-line', lineSelect.value);
                            }
                        }
                    }
                });

                if (checkAll) {
                    checkAll.checked = checkboxes.length > 0 && checkboxes.every(function (checkbox) {
                        return checkbox.checked;
                    });
                }
            }

            function setElText(id, text) {
                const el = document.getElementById(id);
                if (el) el.textContent = text;
            }

            function syncSummary() {
                let totalQty = 0;
                let totalWeight = 0;

                const custStats = {
                    LOCAL: { pcs: 0, kg: 0 },
                    EXPORT: { pcs: 0, kg: 0 },
                    UNCLASSIFIED: { pcs: 0, kg: 0 },
                };

                const lineStats = {
                    1: { pcs: 0, kg: 0 },
                    2: { pcs: 0, kg: 0 },
                    3: { pcs: 0, kg: 0 },
                    4: { pcs: 0, kg: 0 },
                };

                Array.from(selectedIds).forEach(function (id) {
                    const m = selectedMeta[id];
                    if (m) {
                        const qty = parseInt(m.qty, 10) || 0;
                        const unitWeight = parseFloat(m.weight) || 0;
                        const itemKg = qty * unitWeight;
                        const line = (m.line >= 1 && m.line <= 4) ? parseInt(m.line, 10) : 1;
                        const cat = (m.category === 'LOCAL' || m.category === 'EXPORT') ? m.category : 'UNCLASSIFIED';

                        totalQty += qty;
                        totalWeight += itemKg;

                        custStats[cat].pcs += qty;
                        custStats[cat].kg += itemKg;

                        lineStats[line].pcs += qty;
                        lineStats[line].kg += itemKg;
                    }
                });

                if (selectionSummary) {
                    selectionSummary.textContent = selectedIds.size + ' rencana dipilih';
                }

                if (clearSelectionBtn) {
                    if (selectedIds.size > 0) {
                        clearSelectionBtn.classList.remove('hidden');
                        clearSelectionBtn.classList.add('inline-flex');
                    } else {
                        clearSelectionBtn.classList.add('hidden');
                        clearSelectionBtn.classList.remove('inline-flex');
                    }
                }

                // Compact Header Summary
                if (summaryCountEl) summaryCountEl.textContent = selectedIds.size + ' item';
                if (selectedCountSpan) selectedCountSpan.textContent = selectedIds.size;

                setElText('calc-total-items', formatQty(selectedIds.size));
                setElText('calc-total-pcs', formatQty(totalQty));
                setElText('calc-total-kg', formatWeight(totalWeight));

                // Expandable Customer & Line Composition
                setElText('calc-cust-local-pcs', formatQty(custStats.LOCAL.pcs));
                setElText('calc-cust-local-kg', formatWeight(custStats.LOCAL.kg));
                setElText('calc-cust-export-pcs', formatQty(custStats.EXPORT.pcs));
                setElText('calc-cust-export-kg', formatWeight(custStats.EXPORT.kg));
                setElText('calc-cust-unclassified-pcs', formatQty(custStats.UNCLASSIFIED.pcs));
                setElText('calc-cust-unclassified-kg', formatWeight(custStats.UNCLASSIFIED.kg));

                setElText('mini-local-kg', formatWeight(custStats.LOCAL.kg) + ' kg');
                setElText('mini-export-kg', formatWeight(custStats.EXPORT.kg) + ' kg');

                for (let l = 1; l <= 4; l++) {
                    setElText('calc-line-' + l + '-pcs', formatQty(lineStats[l].pcs));
                    setElText('calc-line-' + l + '-kg', formatWeight(lineStats[l].kg));
                    setElText('mini-line-' + l + '-kg', formatWeight(lineStats[l].kg) + ' kg');
                }

                if (btnCreate) {
                    btnCreate.disabled = selectedIds.size === 0;
                }
            }

            function syncHiddenInputs() {
                if (!selectedPlanInputs) {
                    return;
                }

                selectedPlanInputs.innerHTML = '';
                Array.from(selectedIds).forEach(function (planId) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'plan_ids[]';
                    input.value = planId;
                    selectedPlanInputs.appendChild(input);

                    const m = selectedMeta[planId];
                    if (m) {
                        const qtyHidden = document.createElement('input');
                        qtyHidden.type = 'hidden';
                        qtyHidden.name = 'selected_qty[' + planId + ']';
                        qtyHidden.value = m.qty;
                        selectedPlanInputs.appendChild(qtyHidden);

                        const lineHidden = document.createElement('input');
                        lineHidden.type = 'hidden';
                        lineHidden.name = 'selected_line[' + planId + ']';
                        lineHidden.value = m.line || 1;
                        selectedPlanInputs.appendChild(lineHidden);
                    }
                });
            }

            function persistAndRender() {
                syncVisibleCheckboxes();
                persistSelection();
                syncSummary();
                syncHiddenInputs();
            }

            function handleCheckboxChange(checkbox) {
                const planId = parseInt(checkbox.value, 10);

                if (checkbox.checked) {
                    selectedIds.add(planId);
                    selectedMeta[planId] = getRowValues(checkbox);
                } else {
                    selectedIds.delete(planId);
                    delete selectedMeta[planId];
                }

                persistAndRender();
            }

            if (checkAll) {
                checkAll.addEventListener('change', function () {
                    checkboxes.forEach(function (checkbox) {
                        const planId = parseInt(checkbox.value, 10);
                        checkbox.checked = checkAll.checked;

                        if (checkAll.checked) {
                            selectedIds.add(planId);
                            selectedMeta[planId] = getRowValues(checkbox);
                        } else {
                            selectedIds.delete(planId);
                            delete selectedMeta[planId];
                        }
                    });

                    persistAndRender();
                });
            }

            checkboxes.forEach(function (checkbox) {
                checkbox.addEventListener('change', function () {
                    handleCheckboxChange(checkbox);
                });
            });

            qtyInputs.forEach(function (input) {
                function validateAndSyncQty(enforceMinOnEmpty) {
                    const planId = parseInt(input.getAttribute('data-plan-qty'), 10);
                    const checkbox = document.querySelector('.plan-checkbox[value="' + planId + '"]');
                    if (!checkbox) return;

                    const code = checkbox.getAttribute('data-code') || ('#' + planId);
                    const maxQty = parseInt(checkbox.getAttribute('data-max-qty') || checkbox.getAttribute('data-qty'), 10) || 1;
                    let rawVal = parseInt(input.value, 10);

                    if (Number.isNaN(rawVal)) {
                        if (enforceMinOnEmpty) {
                            rawVal = 1;
                            input.value = 1;
                            showQuotaWarning('Kuantitas untuk item ' + code + ' tidak boleh kosong. Nilai disesuaikan ke 1 pcs.');
                        } else {
                            return;
                        }
                    } else if (rawVal < 1) {
                        rawVal = 1;
                        input.value = 1;
                        showQuotaWarning('Kuantitas untuk item ' + code + ' minimal 1 pcs.');
                    } else if (rawVal > maxQty) {
                        rawVal = maxQty;
                        input.value = maxQty;
                        showQuotaWarning('Kuantitas untuk item ' + code + ' tidak boleh melebihi sisa kuota (' + formatQty(maxQty) + ' pcs). Nilai disesuaikan ke ' + formatQty(maxQty) + ' pcs.');
                    }

                    checkbox.setAttribute('data-qty', rawVal);

                    if (selectedIds.has(planId)) {
                        selectedMeta[planId] = getRowValues(checkbox);
                        persistSelection();
                        syncSummary();
                        syncHiddenInputs();
                    }
                }

                input.addEventListener('input', function () {
                    validateAndSyncQty(false);
                });

                input.addEventListener('change', function () {
                    validateAndSyncQty(true);
                });

                input.addEventListener('blur', function () {
                    validateAndSyncQty(true);
                });
            });

            lineSelects.forEach(function (select) {
                select.addEventListener('change', function () {
                    const planId = parseInt(select.getAttribute('data-plan-line'), 10);
                    const checkbox = document.querySelector('.plan-checkbox[value="' + planId + '"]');
                    if (!checkbox) return;

                    const values = getRowValues(checkbox);
                    checkbox.setAttribute('data-line', values.line);

                    if (selectedIds.has(planId)) {
                        selectedMeta[planId] = values;
                        persistSelection();
                        syncSummary();
                        syncHiddenInputs();
                    }
                });
            });

            if (clearSelectionBtn) {
                clearSelectionBtn.addEventListener('click', function () {
                    selectedIds.clear();
                    selectedMeta = {};
                    clearSelection();
                    hideQuotaWarning();
                    checkboxes.forEach(function (cb) {
                        cb.checked = false;
                    });
                    if (checkAll) checkAll.checked = false;
                    syncSummary();
                    syncHiddenInputs();
                });
            }

            persistAndRender();
        });

        function submitSelectedPlans() {
            const form = document.getElementById('form-create-order');
            if (form) form.submit();
        }
    </script>
@endsection
