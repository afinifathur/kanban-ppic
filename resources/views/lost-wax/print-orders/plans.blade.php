@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full gap-4">
        <div class="shrink-0">
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Perencanaan Perintah Cetak Lilin</h1>
            <p class="text-gray-500 text-[10px]">Pilih rencana produksi untuk diterbitkan menjadi Perintah Cetak Lilin</p>
        </div>
        @if(($activeTab ?? 'plans') === 'plans')
        <div id="selection-summary-bar" title="Total dihitung dari item yang dicentang (mencakup seluruh halaman pagination)"
            class="flex items-stretch divide-x divide-slate-200 bg-white border border-slate-200 rounded-lg shadow-sm shrink-0">
            <div class="flex items-center gap-2 px-3 py-1.5">
                <span class="text-blue-500 text-sm" aria-hidden="true"><i class="fas fa-check-square"></i></span>
                <div class="text-right leading-tight">
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider whitespace-nowrap">Item Terpilih</div>
                    <div id="summary-count" class="text-sm font-bold text-slate-800 whitespace-nowrap" aria-live="polite">0 item</div>
                </div>
            </div>
            <div class="flex items-center gap-2 px-3 py-1.5">
                <span class="text-indigo-500 text-sm" aria-hidden="true"><i class="fas fa-cubes"></i></span>
                <div class="text-right leading-tight">
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider whitespace-nowrap">Total Qty (PCS)</div>
                    <div id="summary-qty" class="text-sm font-bold text-slate-800 whitespace-nowrap" aria-live="polite">0 pcs</div>
                </div>
            </div>
            <div class="flex items-center gap-2 px-3 py-1.5">
                <span class="text-emerald-500 text-sm" aria-hidden="true"><i class="fas fa-weight-hanging"></i></span>
                <div class="text-right leading-tight">
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider whitespace-nowrap">Total Berat (KG)</div>
                    <div id="summary-weight" class="text-sm font-bold text-emerald-600 whitespace-nowrap" aria-live="polite">0 kg</div>
                </div>
            </div>
        </div>
        @endif
    </div>
@endsection

@section('content')
    @php
        $selectionStorageKey = 'lost-wax-print-orders-selection-'.auth()->id().'-'.(auth()->user()->product_scope ?: 'all');
    @endphp

    <div class="space-y-6">
        <!-- Tabs Header -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-1 flex gap-2">
            <a href="{{ route('lost-wax.print-orders.plans', ['tab' => 'plans'] + request()->except(['tab', 'plans_page', 'orders_page', 'recovery_page'])) }}"
                class="flex-1 text-center py-2.5 rounded-lg text-sm font-bold transition-all {{ ($activeTab ?? 'plans') === 'plans' ? 'bg-amber-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-800 hover:bg-slate-50' }}">
                <i class="fas fa-clipboard-list mr-2"></i> Rencana Cetak (Plan Items)
            </a>
            <a href="{{ route('lost-wax.print-orders.plans', ['tab' => 'orders'] + request()->except(['tab', 'plans_page', 'orders_page', 'recovery_page'])) }}" data-clear-selection-on-click="true"
                class="flex-1 text-center py-2.5 rounded-lg text-sm font-bold transition-all {{ ($activeTab ?? 'plans') === 'orders' ? 'bg-amber-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-800 hover:bg-slate-50' }}">
                <i class="fas fa-file-invoice mr-2"></i> Dokumen Perintah Cetak (Print Orders)
            </a>
            <a href="{{ route('lost-wax.print-orders.plans', ['tab' => 'recovery'] + request()->except(['tab', 'plans_page', 'orders_page', 'recovery_page'])) }}" data-clear-selection-on-click="true"
                class="flex-1 text-center py-2.5 rounded-lg text-sm font-bold transition-all {{ ($activeTab ?? 'plans') === 'recovery' ? 'bg-amber-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-800 hover:bg-slate-50' }}">
                <i class="fas fa-tools mr-2 text-rose-500"></i> Recovery Pool
                @if(($totalActiveRecoveryCount ?? 0) > 0)
                    <span class="ml-1.5 px-2 py-0.5 text-xs bg-rose-100 text-rose-700 rounded-full font-bold">{{ $totalActiveRecoveryCount }}</span>
                @endif
            </a>
        </div>

        @if(($activeTab ?? 'plans') === 'plans')
            <!-- Tab 1: Rencana Cetak -->
            <div class="bg-white shadow-sm rounded-xl border border-slate-200 p-6 space-y-4">
                <!-- Filters -->
                <form method="GET" action="{{ route('lost-wax.print-orders.plans') }}" data-plan-filter="true" class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end bg-slate-50 p-4 rounded-lg border border-slate-100">
                    <input type="hidden" name="tab" value="plans">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Tanggal Rencana</label>
                        <input type="date" name="date" value="{{ request('date') }}" class="w-full bg-white border border-slate-300 rounded px-3 py-1.5 text-sm text-slate-700 focus:outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Kode Cust</label>
                        <input type="text" name="code" list="code-list" value="{{ request('code') }}" placeholder="Contoh: AB01" class="w-full bg-white border border-slate-300 rounded px-3 py-1.5 text-sm text-slate-700 focus:outline-none focus:border-amber-500">
                        <datalist id="code-list">
                            @foreach($uniqueCodes as $uCode)
                                <option value="{{ $uCode }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Customer</label>
                        <input type="text" name="customer" list="customer-list" value="{{ request('customer') }}" placeholder="Contoh: A06" class="w-full bg-white border border-slate-300 rounded px-3 py-1.5 text-sm text-slate-700 focus:outline-none focus:border-amber-500">
                        <datalist id="customer-list">
                            @foreach($uniqueCustomers as $uCust)
                                <option value="{{ $uCust }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Status</label>
                        <select name="status" class="w-full bg-white border border-slate-300 rounded px-3 py-1.5 text-sm text-slate-700 focus:outline-none focus:border-amber-500">
                            <option value="active" {{ request('status', 'active') === 'active' ? 'selected' : '' }}>Aktif</option>
                            <option value="closed" {{ request('status') === 'closed' ? 'selected' : '' }}>Closed</option>
                            <option value="all" {{ request('status') === 'all' ? 'selected' : '' }}>Semua</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 bg-amber-600 hover:bg-amber-700 text-white font-bold py-1.5 px-4 rounded text-sm transition-all flex items-center justify-center gap-1.5">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                        <a href="{{ route('lost-wax.print-orders.plans', ['tab' => 'plans']) }}" class="flex-1 bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold py-1.5 px-4 rounded text-sm transition-all flex items-center justify-center">
                            Reset
                        </a>
                    </div>
                </form>

                <!-- Selection Form -->
                <form id="create-order-form" method="GET" action="{{ route('lost-wax.print-orders.create') }}">
                    <div class="mt-1 mb-3 flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div id="selection-summary" class="text-xs font-bold text-slate-500" aria-live="polite">0 rencana dipilih</div>
                            <button type="button" id="clear-selection-btn" class="hidden text-xs text-red-600 hover:text-red-800 hover:underline font-semibold transition-colors items-center gap-1">
                                <i class="fas fa-times-circle"></i> Bersihkan Pilihan
                            </button>
                        </div>
                        <div id="selected-plan-inputs"></div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse border border-slate-200 text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="border border-slate-200 p-3 text-center w-12">
                                        <input type="checkbox" id="select-all" class="h-4 w-4 rounded border-slate-300 text-amber-600 focus:ring-amber-500">
                                    </th>
                                    <th class="border border-slate-200 p-3 text-left">Kode Cust</th>
                                    <th class="border border-slate-200 p-3 text-left">Customer</th>
                                    <th class="border border-slate-200 p-3 text-left">Nama Produk</th>
                                    <th class="border border-slate-200 p-3 text-center">Planned</th>
                                    <th class="border border-slate-200 p-3 text-center">Terjadwal</th>
                                    <th class="border border-slate-200 p-3 text-center">Sisa</th>
                                    <th class="border border-slate-200 p-3 text-center">Line</th>
                                    <th class="border border-slate-200 p-3 text-center">Tanggal Rencana</th>
                                    <th class="border border-slate-200 p-3 text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($plans as $plan)
                                    <tr class="hover:bg-slate-50/50 transition-colors">
                                        <td class="border border-slate-200 p-3 text-center">
                                            @if(auth()->user()->hasRole('ppic') && auth()->user()->product_scope)
                                                @if(!$plan->is_closed)
                                                    <input type="checkbox" value="{{ $plan->id }}" data-qty="{{ max(0, $plan->qty_remaining_scheduled) }}" data-weight="{{ $plan->weight ?? 0 }}" class="plan-checkbox h-4 w-4 rounded border-slate-300 text-amber-600 focus:ring-amber-500">
                                                @else
                                                    <span class="text-xs font-bold text-red-600 uppercase tracking-wider bg-red-50 px-2 py-1 rounded border border-red-200">Closed</span>
                                                @endif
                                            @else
                                                @if($plan->is_closed)
                                                    <span class="text-xs font-bold text-red-600 uppercase tracking-wider bg-red-50 px-2 py-1 rounded border border-red-200">Closed</span>
                                                @else
                                                    <span class="text-xs text-slate-300">—</span>
                                                @endif
                                            @endif
                                        </td>
                                        <td class="border border-slate-200 p-3 font-mono text-xs font-bold text-slate-700">{{ $plan->code }}</td>
                                        <td class="border border-slate-200 p-3">
                                            <div class="font-bold text-slate-800 uppercase">{{ $plan->customer ?: '-' }}</div>
                                            <div class="text-[10px] text-slate-400 font-mono">{{ $plan->po_number }}</div>
                                        </td>
                                        <td class="border border-slate-200 p-3">
                                            <div class="font-bold text-slate-800">{{ $plan->item_name }}</div>
                                            <div class="text-[10px] text-slate-500 font-mono">{{ $plan->item_code }} | AISI {{ $plan->aisi ?: '-' }} | Size {{ $plan->size ?: '-' }}</div>
                                        </td>
                                        <td class="border border-slate-200 p-3 text-center">
                                            <div class="font-bold text-slate-700">{{ number_format($plan->qty_planned) }}</div>
                                            <div class="text-[10px] text-slate-400 mt-0.5" title="Sisa untuk Produksi Aktual">Sisa Prod: {{ number_format($plan->qty_remaining_to_produce) }}</div>
                                        </td>
                                        <td class="border border-slate-200 p-3 text-center font-bold text-blue-600">{{ number_format($plan->qty_scheduled) }}</td>
                                        <td class="border border-slate-200 p-3 text-center font-bold">
                                            @php
                                                $sisa = $plan->qty_remaining_scheduled;
                                            @endphp
                                            @if($sisa < 0)
                                                <span class="text-amber-600" title="Terjadwal Lebih">
                                                    {{ number_format($sisa) }} <span class="text-[9px] px-1 bg-amber-50 text-amber-700 rounded border border-amber-200">Lebih</span>
                                                </span>
                                            @elseif($sisa == 0)
                                                <span class="text-emerald-600">0</span>
                                            @else
                                                <span class="text-orange-500">{{ number_format($sisa) }}</span>
                                            @endif
                                        </td>
                                        <td class="border border-slate-200 p-3 text-center font-bold text-slate-500 text-base">{{ $plan->line_number }}</td>
                                        <td class="border border-slate-200 p-3 text-center text-xs text-slate-400">
                                            {{ $plan->created_at->format('d/m/Y') }}
                                        </td>
                                        <td class="border border-slate-200 p-3 text-center">
                                            @if(auth()->user()->hasRole('ppic') && auth()->user()->product_scope)
                                                @if(!$plan->is_closed)
                                                    <button type="button" 
                                                        onclick="submitSingleAction('close_plan', '{{ $plan->id }}', 'Tutup rencana ini dari Pool Perencanaan Cetak?\nData Production Plan tidak akan dihapus dan dapat dibuka kembali.')"
                                                        class="bg-red-50 hover:bg-red-100 text-red-600 font-bold py-1 px-2 rounded text-xs border border-red-200 transition-all flex items-center gap-1 mx-auto" title="Tutup Rencana">
                                                        <i class="fas fa-times-circle"></i> Tutup
                                                    </button>
                                                @else
                                                    <button type="button"
                                                        onclick="submitSingleAction('open_plan', '{{ $plan->id }}', 'Buka kembali rencana produksi ini?')"
                                                        class="bg-emerald-50 hover:bg-emerald-100 text-emerald-600 font-bold py-1 px-2 rounded text-xs border border-emerald-200 transition-all flex items-center gap-1 mx-auto" title="Buka Rencana">
                                                        <i class="fas fa-check-circle"></i> Buka
                                                    </button>
                                                @endif
                                            @else
                                                <span class="text-xs text-slate-300">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="border border-slate-200 p-8 text-center text-slate-400 italic">
                                            Tidak ada data rencana produksi ditemukan.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 flex items-center justify-between">
                        <div class="text-xs text-slate-400">
                            Menampilkan {{ $plans->firstItem() ?? 0 }} - {{ $plans->lastItem() ?? 0 }} dari {{ $plans->total() }} Rencana
                        </div>
                        <div>
                            {{ $plans->links() }}
                        </div>
                    </div>

                    <div class="mt-6 border-t border-slate-100 pt-4 flex justify-end gap-3">
                        @if(auth()->user()->hasRole('ppic') && auth()->user()->product_scope)
                        <button type="button" id="bulk-close-btn" disabled
                            class="bg-slate-300 text-slate-500 cursor-not-allowed font-bold py-2.5 px-6 rounded-lg text-sm shadow transition-all flex items-center gap-2">
                            <i class="fas fa-times-circle"></i> Tutup Terpilih
                        </button>
                        <button type="submit" id="submit-btn" disabled
                            class="bg-slate-300 text-slate-500 cursor-not-allowed font-bold py-2.5 px-6 rounded-lg text-sm shadow transition-all flex items-center gap-2">
                            <i class="fas fa-file-signature"></i> Buat Perintah Cetak
                        </button>
                        @endif
                    </div>
                </form>
            </div>
        @else
            <!-- Tab 2: Dokumen Perintah Cetak -->
            <div class="bg-white shadow-sm rounded-xl border border-slate-200 p-6 space-y-4">
                <!-- Filters -->
                <form method="GET" action="{{ route('lost-wax.print-orders.plans') }}" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end bg-slate-50 p-4 rounded-lg border border-slate-100">
                    <input type="hidden" name="tab" value="orders">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">No Perintah Cetak</label>
                        <input type="text" name="print_order_number" value="{{ request('print_order_number') }}" placeholder="PC-202608..." class="w-full bg-white border border-slate-300 rounded px-3 py-1.5 text-sm text-slate-700 focus:outline-none focus:border-amber-500">
                    </div>
                    <div></div>
                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 bg-amber-600 hover:bg-amber-700 text-white font-bold py-1.5 px-4 rounded text-sm transition-all flex items-center justify-center gap-1.5">
                            <i class="fas fa-search"></i> Cari
                        </button>
                        <a href="{{ route('lost-wax.print-orders.plans', ['tab' => 'orders']) }}" class="flex-1 bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold py-1.5 px-4 rounded text-sm transition-all flex items-center justify-center">
                            Reset
                        </a>
                    </div>
                </form>

                <!-- List of Print Orders -->
                <div class="overflow-x-auto">
                    <table class="min-w-full border-collapse border border-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="border border-slate-200 p-3 text-left">No. Perintah Cetak</th>
                                <th class="border border-slate-200 p-3 text-center">Tanggal Produksi</th>
                                <th class="border border-slate-200 p-3 text-center">Jumlah Item</th>
                                <th class="border border-slate-200 p-3 text-center">Total Qty</th>
                                <th class="border border-slate-200 p-3 text-left">Pembuat</th>
                                <th class="border border-slate-200 p-3 text-center">Status</th>
                                <th class="border border-slate-200 p-3 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($printOrders as $order)
                                <tr class="hover:bg-slate-50/50 transition-colors">
                                    <td class="border border-slate-200 p-3">
                                        <a href="{{ route('lost-wax.print-orders.show', $order) }}" class="font-bold text-amber-600 hover:underline font-mono">
                                            {{ $order->print_order_number }}
                                        </a>
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center font-bold text-slate-700">
                                        {{ $order->scheduled_date->format('d/m/Y') }}
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center font-bold text-slate-600">
                                        {{ $order->lines->count() }} item
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center font-bold text-slate-700">
                                        {{ number_format($order->lines->sum('qty_ordered')) }}
                                    </td>
                                    <td class="border border-slate-200 p-3 text-slate-600">
                                        {{ optional($order->creator)->name ?? '-' }}
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center">
                                        <span class="px-2 py-1 rounded-full text-[10px] font-bold uppercase
                                            {{ $order->status === 'DRAFT' ? 'bg-slate-100 text-slate-600' : '' }}
                                            {{ $order->status === 'ISSUED' ? 'bg-blue-100 text-blue-600' : '' }}
                                            {{ $order->status === 'CANCELLED' ? 'bg-red-100 text-red-600' : '' }}
                                        ">
                                            {{ $order->status }}
                                        </span>
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <a href="{{ route('lost-wax.print-orders.show', $order) }}" class="text-slate-500 hover:text-slate-700" title="Detail">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="{{ route('lost-wax.print-orders.print', $order) }}" target="_blank" class="text-blue-500 hover:text-blue-700" title="Cetak Form">
                                                <i class="fas fa-print"></i>
                                            </a>
                                            @if($order->status === 'DRAFT')
                                                <a href="{{ route('lost-wax.print-orders.edit', $order) }}" class="text-amber-500 hover:text-amber-700" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <form action="{{ route('lost-wax.print-orders.destroy', $order) }}" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus draft Perintah Cetak ini?')" class="inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="text-red-500 hover:text-red-700" title="Hapus">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="border border-slate-200 p-8 text-center text-slate-400 italic">
                                        Tidak ada dokumen Perintah Cetak ditemukan.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex items-center justify-between">
                    <div class="text-xs text-slate-400">
                        Menampilkan {{ $printOrders->firstItem() ?? 0 }} - {{ $printOrders->lastItem() ?? 0 }} dari {{ $printOrders->total() }} Dokumen
                    </div>
                    <div>
                        {{ $printOrders->links() }}
                    </div>
                </div>
            </div>
        @endif

        @if(($activeTab ?? 'plans') === 'recovery')
            <!-- Tab 3: Recovery Pool -->
            <div class="bg-white shadow-sm rounded-xl border border-slate-200 p-6 space-y-4">
                <!-- Recovery Pool Filters -->
                <form method="GET" action="{{ route('lost-wax.print-orders.plans') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end bg-slate-50 p-4 rounded-lg border border-slate-100">
                    <input type="hidden" name="tab" value="recovery">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Kode Cust</label>
                        <input type="text" name="recovery_code" list="code-list" value="{{ request('recovery_code') }}" placeholder="Cari Kode..." class="w-full bg-white border border-slate-300 rounded px-3 py-1.5 text-sm text-slate-700 focus:outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Customer</label>
                        <input type="text" name="recovery_customer" list="customer-list" value="{{ request('recovery_customer') }}" placeholder="Cari Customer..." class="w-full bg-white border border-slate-300 rounded px-3 py-1.5 text-sm text-slate-700 focus:outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Status Recovery</label>
                        <select name="recovery_status" class="w-full bg-white border border-slate-300 rounded px-3 py-1.5 text-sm text-slate-700 focus:outline-none focus:border-amber-500">
                            <option value="active" {{ request('recovery_status', 'active') === 'active' ? 'selected' : '' }}>Perlu Tindakan (Defisit / Terancam)</option>
                            <option value="closed" {{ request('recovery_status') === 'closed' ? 'selected' : '' }}>Selesai / Ditutup</option>
                            <option value="all" {{ request('recovery_status') === 'all' ? 'selected' : '' }}>Semua Rencana</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 bg-amber-600 hover:bg-amber-700 text-white font-bold py-1.5 px-4 rounded text-sm transition-all flex items-center justify-center gap-1.5">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                        <a href="{{ route('lost-wax.print-orders.plans', ['tab' => 'recovery']) }}" class="flex-1 bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold py-1.5 px-4 rounded text-sm transition-all flex items-center justify-center">
                            Reset
                        </a>
                    </div>
                </form>

                <!-- Recovery Pool Table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full border-collapse border border-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="border border-slate-200 p-3 text-left">Kode Cust & Produk</th>
                                <th class="border border-slate-200 p-3 text-center">Target Plan</th>
                                <th class="border border-slate-200 p-3 text-center">Target PO</th>
                                <th class="border border-slate-200 p-3 text-center">Ctk Bagus</th>
                                <th class="border border-slate-200 p-3 text-center">Standby</th>
                                <th class="border border-slate-200 p-3 text-center">WIP Net</th>
                                <th class="border border-slate-200 p-3 text-center">Oven</th>
                                <th class="border border-slate-200 p-3 text-center">Rusak</th>
                                <th class="border border-slate-200 p-3 text-center bg-slate-100 font-bold">Total Usable</th>
                                <th class="border border-slate-200 p-3 text-center">Defisit Plan</th>
                                <th class="border border-slate-200 p-3 text-center">Defisit PO</th>
                                <th class="border border-slate-200 p-3 text-center">Status</th>
                                <th class="border border-slate-200 p-3 text-center">Alur Recovery</th>
                                <th class="border border-slate-200 p-3 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recoveryPlans as $item)
                                @php
                                    $plan = $item->plan;
                                    $bd = $item->breakdown;
                                    $activeReprint = $item->active_reprint;
                                    $isScopeOwner = auth()->user()->hasRole('ppic') && auth()->user()->product_scope === $plan->product_scope;
                                @endphp
                                <tr class="hover:bg-slate-50/50 transition-colors {{ $bd['status'] === 'CRITICAL' ? 'bg-rose-50/30' : '' }}">
                                    <td class="border border-slate-200 p-3">
                                        <div class="font-mono font-bold text-slate-800 text-sm flex items-center gap-1.5">
                                            {{ $plan->code }}
                                            <span class="px-1.5 py-0.5 rounded text-[10px] font-bold {{ $plan->product_scope === 'SS' ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-200 text-slate-700' }}">{{ $plan->product_scope }}</span>
                                        </div>
                                        <div class="text-xs text-slate-600 font-semibold">{{ $plan->item_name }}</div>
                                        <div class="text-[11px] text-slate-400">Cust: <span class="font-bold text-slate-600">{{ $plan->customer }}</span> | {{ $plan->size }} | {{ $plan->aisi }}</div>
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center font-bold text-slate-700">
                                        {{ number_format($plan->qty_planned) }}
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center">
                                        @if($plan->po_quantity !== null)
                                            <div class="font-bold text-slate-800">{{ number_format($plan->po_quantity) }}</div>
                                            <div class="text-[10px] text-slate-400 font-mono">{{ $plan->po_number ?? 'PO' }}</div>
                                        @else
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-500 border border-slate-200">
                                                PO BELUM DIISI
                                            </span>
                                        @endif
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center text-slate-700 font-semibold">
                                        {{ number_format($bd['q_print_good']) }}
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center text-slate-600">
                                        {{ number_format($bd['q_standby']) }}
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center text-slate-600">
                                        {{ number_format($bd['q_wip_net']) }}
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center text-slate-600">
                                        {{ number_format($bd['q_final_usable']) }}
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center font-bold {{ $bd['q_tree_defect'] > 0 ? 'text-red-600' : 'text-slate-400' }}">
                                        {{ number_format($bd['q_tree_defect']) }}
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center font-bold bg-slate-50 text-slate-900 text-sm">
                                        {{ number_format($bd['q_usable']) }}
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center font-bold {{ $bd['deficit_vs_plan'] > 0 ? 'text-amber-700' : 'text-slate-400' }}">
                                        {{ $bd['deficit_vs_plan'] > 0 ? number_format($bd['deficit_vs_plan']).' pcs' : '0' }}
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center font-bold {{ ($bd['deficit_vs_po'] ?? 0) > 0 ? 'text-rose-700' : 'text-slate-400' }}">
                                        @if($bd['deficit_vs_po'] !== null)
                                            {{ $bd['deficit_vs_po'] > 0 ? number_format($bd['deficit_vs_po']).' pcs' : '0' }}
                                        @else
                                            <span class="text-slate-300 font-normal">—</span>
                                        @endif
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center">
                                        @if($bd['status'] === 'CRITICAL')
                                            <span class="px-2.5 py-1 rounded-full text-[10px] font-extrabold uppercase bg-rose-100 text-rose-800 border border-rose-200 tracking-wider animate-pulse">
                                                CRITICAL
                                            </span>
                                        @elseif($bd['status'] === 'WARNING')
                                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase bg-amber-100 text-amber-800 border border-amber-200 tracking-wider">
                                                WARNING
                                            </span>
                                        @else
                                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase bg-emerald-100 text-emerald-800 border border-emerald-200 tracking-wider">
                                                NORMAL
                                            </span>
                                        @endif
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center text-xs">
                                        @if($activeReprint)
                                            <a href="{{ route('lost-wax.print-orders.show', $activeReprint) }}" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold bg-amber-50 text-amber-800 border border-amber-300 hover:bg-amber-100 transition-colors shadow-xs" title="Buka Detail SPK Cetak Ulang">
                                                <i class="fas fa-file-invoice"></i> SPK #{{ $activeReprint->reprint_cycle }}: {{ $activeReprint->print_order_number }}
                                                <span class="px-1.5 py-0.2 rounded text-[9px] uppercase bg-amber-200 text-amber-900">{{ $activeReprint->status }}</span>
                                            </a>
                                        @elseif($plan->is_closed)
                                            <div class="inline-flex flex-col items-center">
                                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-slate-100 text-slate-600 border border-slate-200">
                                                    <i class="fas fa-lock mr-1"></i> DITUTUP
                                                </span>
                                                <span class="text-[10px] text-slate-400 truncate max-w-[130px]" title="{{ $plan->closure_reason }}">{{ $plan->closure_reason }}</span>
                                            </div>
                                        @else
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-blue-50 text-blue-700 border border-blue-200">
                                                <i class="fas fa-clock mr-1"></i> PERLU KEPUTUSAN
                                            </span>
                                        @endif
                                    </td>
                                    <td class="border border-slate-200 p-3 text-center">
                                        <div class="flex items-center justify-center gap-1.5">
                                            @if($isScopeOwner)
                                                @if(! $plan->is_closed && ! $activeReprint)
                                                    <button type="button" onclick="openReprintModal({{ $plan->id }}, '{{ $plan->code }}', '{{ addslashes($plan->item_name) }}', {{ $plan->qty_planned }}, '{{ $plan->po_quantity ?? '-' }}', {{ $bd['q_usable'] }}, {{ $bd['deficit_vs_plan'] }}, '{{ $bd['deficit_vs_po'] ?? '-' }}', '{{ $bd['status'] }}')" class="bg-amber-600 hover:bg-amber-700 text-white font-bold py-1 px-2.5 rounded text-xs transition-colors flex items-center gap-1 shadow-xs" title="Terbitkan SPK Cetak Ulang">
                                                        <i class="fas fa-redo"></i> + SPK Reprint
                                                    </button>
                                                    <button type="button" onclick="openCloseModal({{ $plan->id }}, '{{ $plan->code }}', '{{ addslashes($plan->item_name) }}', {{ $plan->qty_planned }}, '{{ $plan->po_quantity ?? '-' }}', {{ $bd['q_usable'] }}, {{ $bd['deficit_vs_plan'] }}, '{{ route('lost-wax.production-plans.close-recovery', $plan) }}')" class="bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold py-1 px-2 rounded text-xs transition-colors flex items-center gap-1" title="Tutup Rencana Tanpa Reprint">
                                                        <i class="fas fa-ban"></i> Tutup
                                                    </button>
                                                @endif
                                                <button type="button" onclick="openPoModal({{ $plan->id }}, '{{ $plan->code }}', '{{ addslashes($plan->item_name) }}', '{{ $plan->po_number }}', '{{ $plan->po_quantity }}', '{{ route('lost-wax.production-plans.update-po', $plan) }}')" class="text-blue-600 hover:text-blue-800 text-xs font-bold px-1.5 py-1 rounded hover:bg-blue-50 transition-colors flex items-center gap-1" title="Perbarui Nomor & Kuantitas PO">
                                                    <i class="fas fa-edit"></i> Isi PO
                                                </button>
                                            @else
                                                <span class="text-xs text-slate-400 italic">Read-only</span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="14" class="border border-slate-200 p-8 text-center text-slate-400 italic">
                                        Tidak ada item pada Recovery Pool untuk kriteria ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex items-center justify-between">
                    <div class="text-xs text-slate-400">
                        Menampilkan {{ $recoveryPlans->firstItem() ?? 0 }} - {{ $recoveryPlans->lastItem() ?? 0 }} dari {{ $recoveryPlans->total() }} Rencana
                    </div>
                    <div>
                        {{ $recoveryPlans->links() }}
                    </div>
                </div>
            </div>
        @endif
    </div>

    <!-- Hidden helper forms for single and bulk actions -->
    <form id="single-action-form" action="{{ route('lost-wax.print-orders.store') }}" method="POST" class="hidden">
        @csrf
        <input type="hidden" name="action" id="single-action-val" value="">
        <input type="hidden" name="production_plan_id" id="single-plan-id" value="">
    </form>

    <form id="bulk-close-form" action="{{ route('lost-wax.print-orders.store') }}" method="POST" class="hidden">
        @csrf
        <input type="hidden" name="action" value="bulk_close_plans">
    </form>

    <!-- JS for Selection Logic -->
    <script>
        function submitSingleAction(action, planId, confirmMsg) {
            if (confirm(confirmMsg)) {
                document.getElementById('single-action-val').value = action;
                document.getElementById('single-plan-id').value = planId;
                document.getElementById('single-action-form').submit();
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            const selectionStorageKey = @json($selectionStorageKey);
            const selectAll = document.getElementById('select-all');
            const checkboxes = Array.from(document.querySelectorAll('.plan-checkbox'));
            const submitBtn = document.getElementById('submit-btn');
            const bulkCloseBtn = document.getElementById('bulk-close-btn');
            const selectionSummary = document.getElementById('selection-summary');
            const clearSelectionBtn = document.getElementById('clear-selection-btn');
            const selectedPlanInputs = document.getElementById('selected-plan-inputs');
            const summaryCountEl = document.getElementById('summary-count');
            const summaryQtyEl = document.getElementById('summary-qty');
            const summaryWeightEl = document.getElementById('summary-weight');
            const ordersTabLink = document.querySelector('[data-clear-selection-on-click="true"]');

            function formatQty(value) {
                return Number(value || 0).toLocaleString('en-US');
            }

            function formatWeight(value) {
                return Number(value || 0).toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                });
            }

            // Read persisted selection. Supports both the legacy array-of-ids
            // format and the current object format ({ id, qty, weight }).
            function readStoredSelection() {
                try {
                    const parsed = JSON.parse(sessionStorage.getItem(selectionStorageKey) || '[]');
                    if (!Array.isArray(parsed)) {
                        return { ids: [], meta: {} };
                    }

                    const ids = [];
                    const meta = {};

                    parsed.forEach(function (value) {
                        let id = null;

                        if (value !== null && typeof value === 'object') {
                            id = parseInt(value.id, 10);
                            if (Number.isInteger(id) && id > 0) {
                                meta[id] = {
                                    qty: parseInt(value.qty, 10) || 0,
                                    weight: parseFloat(value.weight) || 0,
                                };
                            }
                        } else {
                            id = parseInt(value, 10);
                        }

                        if (Number.isInteger(id) && id > 0 && ids.indexOf(id) === -1) {
                            ids.push(id);
                        }
                    });

                    return { ids: ids, meta: meta };
                } catch (error) {
                    return { ids: [], meta: {} };
                }
            }

            function persistSelection() {
                const data = Array.from(selectedIds).map(function (id) {
                    const m = selectedMeta[id] || { qty: 0, weight: 0 };

                    return { id: id, qty: m.qty, weight: m.weight };
                });

                sessionStorage.setItem(selectionStorageKey, JSON.stringify(data));
            }

            function clearSelection() {
                sessionStorage.removeItem(selectionStorageKey);
            }

            const stored = readStoredSelection();
            let selectedIds = new Set(stored.ids);
            let selectedMeta = stored.meta;

            function syncVisibleCheckboxes() {
                checkboxes.forEach(function (checkbox) {
                    const planId = parseInt(checkbox.value, 10);
                    checkbox.checked = selectedIds.has(planId);

                    if (checkbox.checked && !selectedMeta[planId]) {
                        selectedMeta[planId] = {
                            qty: parseInt(checkbox.getAttribute('data-qty'), 10) || 0,
                            weight: parseFloat(checkbox.getAttribute('data-weight')) || 0,
                        };
                    }
                });

                if (selectAll) {
                    selectAll.checked = checkboxes.length > 0 && checkboxes.every(function (checkbox) {
                        return checkbox.checked;
                    });
                }
            }

            function syncSummary() {
                let totalQty = 0;
                let totalWeight = 0;

                Array.from(selectedIds).forEach(function (id) {
                    const m = selectedMeta[id];
                    if (m) {
                        const qty = m.qty || 0;
                        totalQty += qty;
                        totalWeight += qty * (m.weight || 0);
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

                if (summaryCountEl) {
                    summaryCountEl.textContent = selectedIds.size + ' item';
                }

                if (summaryQtyEl) {
                    summaryQtyEl.textContent = formatQty(totalQty) + ' pcs';
                }

                if (summaryWeightEl) {
                    summaryWeightEl.textContent = formatWeight(totalWeight) + ' kg';
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
                });
            }

            function syncSubmitButton() {
                if (!submitBtn) {
                    return;
                }

                submitBtn.disabled = selectedIds.size === 0;
                submitBtn.classList.toggle('bg-amber-600', selectedIds.size > 0);
                submitBtn.classList.toggle('hover:bg-amber-700', selectedIds.size > 0);
                submitBtn.classList.toggle('text-white', selectedIds.size > 0);
                submitBtn.classList.toggle('bg-slate-300', selectedIds.size === 0);
                submitBtn.classList.toggle('text-slate-500', selectedIds.size === 0);
                submitBtn.classList.toggle('cursor-not-allowed', selectedIds.size === 0);
            }

            function syncBulkCloseButton() {
                if (!bulkCloseBtn) {
                    return;
                }

                const checkedVisibleCount = checkboxes.filter(function (checkbox) {
                    return checkbox.checked;
                }).length;

                bulkCloseBtn.disabled = checkedVisibleCount === 0;
                bulkCloseBtn.classList.toggle('bg-red-600', checkedVisibleCount > 0);
                bulkCloseBtn.classList.toggle('hover:bg-red-700', checkedVisibleCount > 0);
                bulkCloseBtn.classList.toggle('text-white', checkedVisibleCount > 0);
                bulkCloseBtn.classList.toggle('bg-slate-300', checkedVisibleCount === 0);
                bulkCloseBtn.classList.toggle('text-slate-500', checkedVisibleCount === 0);
                bulkCloseBtn.classList.toggle('cursor-not-allowed', checkedVisibleCount === 0);
            }

            function persistAndRender() {
                persistSelection();
                syncVisibleCheckboxes();
                syncSummary();
                syncHiddenInputs();
                syncSubmitButton();
                syncBulkCloseButton();
            }

            function handleCheckboxChange(checkbox) {
                const planId = parseInt(checkbox.value, 10);

                if (checkbox.checked) {
                    selectedIds.add(planId);
                    selectedMeta[planId] = {
                        qty: parseInt(checkbox.getAttribute('data-qty'), 10) || 0,
                        weight: parseFloat(checkbox.getAttribute('data-weight')) || 0,
                    };
                } else {
                    selectedIds.delete(planId);
                    delete selectedMeta[planId];
                }

                persistAndRender();
            }

            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    checkboxes.forEach(function (checkbox) {
                        const planId = parseInt(checkbox.value, 10);
                        checkbox.checked = selectAll.checked;

                        if (selectAll.checked) {
                            selectedIds.add(planId);
                            selectedMeta[planId] = {
                                qty: parseInt(checkbox.getAttribute('data-qty'), 10) || 0,
                                weight: parseFloat(checkbox.getAttribute('data-weight')) || 0,
                            };
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

            if (clearSelectionBtn) {
                clearSelectionBtn.addEventListener('click', function () {
                    selectedIds.clear();
                    selectedMeta = {};
                    clearSelection();
                    persistAndRender();
                });
            }

            if (bulkCloseBtn) {
                bulkCloseBtn.addEventListener('click', function () {
                    const checked = document.querySelectorAll('.plan-checkbox:checked');
                    if (checked.length === 0) {
                        return;
                    }

                    if (confirm(`Apakah Anda yakin ingin menutup ${checked.length} rencana produksi terpilih dari Pool Perencanaan Cetak?\nData Production Plan tidak akan dihapus dan dapat dibuka kembali.`)) {
                        const form = document.getElementById('bulk-close-form');
                        form.querySelectorAll('input[name="plan_ids[]"]').forEach(function (el) {
                            el.remove();
                        });

                        checked.forEach(function (checkbox) {
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = 'plan_ids[]';
                            input.value = checkbox.value;
                            form.appendChild(input);
                        });

                        form.submit();
                    }
                });
            }

            if (ordersTabLink) {
                ordersTabLink.addEventListener('click', function () {
                    clearSelection();
                });
            }

            persistAndRender();
        });

        function openReprintModal(planId, code, item, planQty, poQty, usableQty, deficitPlan, deficitPo, status) {
            document.getElementById('reprint-plan-id').value = planId;
            document.getElementById('reprint-code').textContent = code;
            document.getElementById('reprint-item').textContent = item;
            document.getElementById('reprint-plan-qty').textContent = Number(planQty).toLocaleString() + ' pcs';
            document.getElementById('reprint-usable-qty').textContent = Number(usableQty).toLocaleString() + ' pcs';
            document.getElementById('reprint-deficit-qty').textContent = Number(deficitPlan).toLocaleString() + ' pcs';
            document.getElementById('reprint-qty-input').value = Math.max(1, deficitPlan);
            document.getElementById('reprint-reason-input').value = '';
            document.getElementById('reprint-modal').classList.remove('hidden');
        }

        function openCloseModal(planId, code, item, planQty, poQty, usableQty, deficitPlan, closeUrl) {
            document.getElementById('close-code').textContent = code;
            document.getElementById('close-item').textContent = item;
            document.getElementById('close-usable-plan').textContent = Number(usableQty).toLocaleString() + ' / ' + Number(planQty).toLocaleString() + ' pcs';
            document.getElementById('close-form').action = closeUrl;
            document.getElementById('close-reason-input').value = '';
            document.getElementById('close-modal').classList.remove('hidden');
        }

        function openPoModal(planId, code, item, poNumber, poQty, updateUrl) {
            document.getElementById('po-code').textContent = code;
            document.getElementById('po-item').textContent = item;
            document.getElementById('po-number-input').value = poNumber && poNumber !== 'null' ? poNumber : '';
            document.getElementById('po-qty-input').value = poQty && poQty !== 'null' ? poQty : '';
            document.getElementById('po-form').action = updateUrl;
            document.getElementById('po-modal').classList.remove('hidden');
        }

        function closeRecoveryModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }

        // Attach double submission prevention
        ['reprint-form', 'close-form', 'po-form'].forEach(function (formId) {
            const form = document.getElementById(formId);
            if (form) {
                form.addEventListener('submit', function () {
                    const btn = form.querySelector('button[type="submit"]');
                    if (btn) {
                        btn.disabled = true;
                        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Memproses...';
                    }
                });
            }
        });
    </script>

    <!-- Modal: Buat SPK Reprint -->
    <div id="reprint-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs hidden">
        <div class="bg-white rounded-xl shadow-2xl border border-slate-200 max-w-lg w-full p-6 space-y-4 m-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg bg-amber-100 text-amber-600 flex items-center justify-center font-bold">
                        <i class="fas fa-redo"></i>
                    </div>
                    <h3 class="text-base font-bold text-slate-800">Terbitkan SPK Cetak Ulang (Reprint)</h3>
                </div>
                <button type="button" onclick="closeRecoveryModal('reprint-modal')" class="text-slate-400 hover:text-slate-600 p-1">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Summary Box -->
            <div class="bg-slate-50 border border-slate-200 rounded-lg p-3 text-xs space-y-1.5">
                <div class="flex justify-between"><span class="text-slate-500">Kode Cust:</span> <span id="reprint-code" class="font-mono font-bold text-slate-800"></span></div>
                <div class="flex justify-between"><span class="text-slate-500">Nama Produk:</span> <span id="reprint-item" class="font-semibold text-slate-700"></span></div>
                <div class="grid grid-cols-3 gap-2 pt-1 border-t border-slate-200 text-center font-bold">
                    <div class="bg-white p-1 rounded border border-slate-100">
                        <div class="text-[9px] text-slate-400 uppercase">Target Plan</div>
                        <div id="reprint-plan-qty" class="text-slate-800"></div>
                    </div>
                    <div class="bg-white p-1 rounded border border-slate-100">
                        <div class="text-[9px] text-slate-400 uppercase">Total Usable</div>
                        <div id="reprint-usable-qty" class="text-emerald-700"></div>
                    </div>
                    <div class="bg-amber-50 p-1 rounded border border-amber-200">
                        <div class="text-[9px] text-amber-700 uppercase">Defisit Plan</div>
                        <div id="reprint-deficit-qty" class="text-amber-800 font-extrabold"></div>
                    </div>
                </div>
            </div>

            <form id="reprint-form" method="POST" action="{{ route('lost-wax.print-orders.reprint.store') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="production_plan_id" id="reprint-plan-id" value="">

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1">
                        Kuantitas Cetak Ulang (PCS) <span class="text-red-500">*</span>
                    </label>
                    <input type="number" name="quantity" id="reprint-qty-input" min="1" required class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm font-bold text-slate-800 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                    <p class="text-[11px] text-slate-500 mt-1 italic">
                        Kuantitas default adalah defisit terhadap rencana internal. PPIC dapat menyesuaikan angka sesuai kebutuhan produksi.
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1">
                        Alasan Cetak Ulang <span class="text-red-500">*</span>
                    </label>
                    <textarea name="reprint_reason" id="reprint-reason-input" rows="2" required placeholder="Contoh: Kompensasi 50 pcs retak di Layer 3..." class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-800 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500"></textarea>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1">
                        Tanggal Rencana Cetak
                    </label>
                    <input type="date" name="scheduled_date" id="reprint-date-input" value="{{ date('Y-m-d') }}" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-800 focus:outline-none focus:border-amber-500">
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                    <button type="button" onclick="closeRecoveryModal('reprint-modal')" class="px-4 py-2 rounded-lg text-sm font-bold text-slate-600 hover:bg-slate-100 transition-colors">
                        Batal
                    </button>
                    <button type="submit" id="reprint-submit-btn" class="px-4 py-2 rounded-lg text-sm font-bold bg-amber-600 hover:bg-amber-700 text-white transition-all shadow-sm flex items-center gap-1.5">
                        <i class="fas fa-check"></i> Terbitkan SPK Reprint
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Tutup Rencana Tanpa Reprint -->
    <div id="close-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs hidden">
        <div class="bg-white rounded-xl shadow-2xl border border-slate-200 max-w-md w-full p-6 space-y-4 m-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg bg-rose-100 text-rose-600 flex items-center justify-center font-bold">
                        <i class="fas fa-lock"></i>
                    </div>
                    <h3 class="text-base font-bold text-slate-800">Tutup Rencana Tanpa Reprint</h3>
                </div>
                <button type="button" onclick="closeRecoveryModal('close-modal')" class="text-slate-400 hover:text-slate-600 p-1">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-800 flex gap-2 items-start">
                <i class="fas fa-exclamation-triangle text-amber-600 mt-0.5"></i>
                <div>
                    Setelah ditutup, rencana ini akan dikeluarkan dari antrean Recovery Pool aktif dan ditandai sebagai <strong>CLOSED WITHOUT REPRINT</strong>.
                </div>
            </div>

            <div class="bg-slate-50 border border-slate-200 rounded-lg p-3 text-xs space-y-1">
                <div class="flex justify-between"><span class="text-slate-500">Kode Cust:</span> <span id="close-code" class="font-mono font-bold text-slate-800"></span></div>
                <div class="flex justify-between"><span class="text-slate-500">Produk:</span> <span id="close-item" class="font-semibold text-slate-700"></span></div>
                <div class="flex justify-between"><span class="text-slate-500">Total Usable / Target:</span> <span id="close-usable-plan" class="font-bold text-slate-800"></span></div>
            </div>

            <form id="close-form" method="POST" action="" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1">
                        Alasan Penutupan Rencana <span class="text-red-500">*</span>
                    </label>
                    <textarea name="closure_reason" id="close-reason-input" rows="2" required minlength="3" placeholder="Contoh: Disetujui kirim 1150 pcs sesuai toleransi customer..." class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-800 focus:outline-none focus:border-rose-500 focus:ring-1 focus:ring-rose-500"></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                    <button type="button" onclick="closeRecoveryModal('close-modal')" class="px-4 py-2 rounded-lg text-sm font-bold text-slate-600 hover:bg-slate-100 transition-colors">
                        Batal
                    </button>
                    <button type="submit" id="close-submit-btn" class="px-4 py-2 rounded-lg text-sm font-bold bg-rose-600 hover:bg-rose-700 text-white transition-all shadow-sm flex items-center gap-1.5">
                        <i class="fas fa-check"></i> Konfirmasi Tutup
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Isi / Update PO -->
    <div id="po-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs hidden">
        <div class="bg-white rounded-xl shadow-2xl border border-slate-200 max-w-md w-full p-6 space-y-4 m-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center font-bold">
                        <i class="fas fa-file-contract"></i>
                    </div>
                    <h3 class="text-base font-bold text-slate-800">Perbarui Data PO Customer</h3>
                </div>
                <button type="button" onclick="closeRecoveryModal('po-modal')" class="text-slate-400 hover:text-slate-600 p-1">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="bg-slate-50 border border-slate-200 rounded-lg p-3 text-xs space-y-1">
                <div class="flex justify-between"><span class="text-slate-500">Kode Cust:</span> <span id="po-code" class="font-mono font-bold text-slate-800"></span></div>
                <div class="flex justify-between"><span class="text-slate-500">Produk:</span> <span id="po-item" class="font-semibold text-slate-700"></span></div>
            </div>

            <form id="po-form" method="POST" action="" class="space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1">
                        Nomor PO Customer
                    </label>
                    <input type="text" name="po_number" id="po-number-input" placeholder="Contoh: PO-2026-001" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-800 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1">
                        Kuantitas PO (PCS)
                    </label>
                    <input type="number" name="po_quantity" id="po-qty-input" min="0" placeholder="Kosongkan jika belum ada..." class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm font-bold text-slate-800 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    <p class="text-[11px] text-slate-500 mt-1 italic">
                        Perubahan kuantitas PO akan langsung memperbarui kalkulasi status (NORMAL / WARNING / CRITICAL).
                    </p>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                    <button type="button" onclick="closeRecoveryModal('po-modal')" class="px-4 py-2 rounded-lg text-sm font-bold text-slate-600 hover:bg-slate-100 transition-colors">
                        Batal
                    </button>
                    <button type="submit" id="po-submit-btn" class="px-4 py-2 rounded-lg text-sm font-bold bg-blue-600 hover:bg-blue-700 text-white transition-all shadow-sm flex items-center gap-1.5">
                        <i class="fas fa-save"></i> Simpan Data PO
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
