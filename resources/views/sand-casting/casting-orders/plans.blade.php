@extends('layouts.app')

@section('top_bar')
    <div class="flex flex-col sm:flex-row sm:items-center justify-between w-full gap-4">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Perintah Cor — Cor Pasir (Sand Casting)</h1>
            <p class="text-gray-500 text-[10px]">Alokasi dan penerbitan instruksi penuangan cor pasir berdasarkan rencana produksi</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            @if($activeTab === 'plans')
                <button type="button" id="btn-create-order" onclick="submitSelectedPlans()"
                    class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-4 py-2 rounded-lg text-xs flex items-center gap-2 shadow-sm transition disabled:opacity-50 disabled:cursor-not-allowed"
                    disabled>
                    <i class="fas fa-plus"></i> Buat Perintah Cor (<span id="selected-count">0</span>)
                </button>
            @endif
        </div>
    </div>
@endsection

@section('content')
    <div class="space-y-4">
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
        <div class="flex border-b border-slate-200 bg-white px-4 pt-3 rounded-t-xl shadow-sm">
            <a href="{{ route('sand-casting.casting-orders.plans', ['tab' => 'plans']) }}"
               class="px-4 py-2.5 text-xs font-bold border-b-2 transition flex items-center gap-2 {{ $activeTab === 'plans' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
                <i class="fas fa-clipboard-list"></i>
                <span>Rencana Cor (Demand)</span>
                <span class="bg-blue-100 text-blue-800 text-[10px] font-bold px-2 py-0.5 rounded-full">{{ $plans->total() }}</span>
            </a>
            <a href="{{ route('sand-casting.casting-orders.plans', ['tab' => 'orders']) }}"
               class="px-4 py-2.5 text-xs font-bold border-b-2 transition flex items-center gap-2 {{ $activeTab === 'orders' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
                <i class="fas fa-file-invoice"></i>
                <span>Dokumen Perintah Cor</span>
                <span class="bg-slate-100 text-slate-700 text-[10px] font-bold px-2 py-0.5 rounded-full">{{ $castingOrders->total() }}</span>
            </a>
        </div>

        @if($activeTab === 'plans')
            <!-- Filter Bar for Plans -->
            <div class="bg-white p-4 rounded-b-xl border border-slate-200 shadow-sm">
                <form method="GET" action="{{ route('sand-casting.casting-orders.plans') }}" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3">
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
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs px-3 py-2 rounded-lg flex items-center justify-center gap-1.5 flex-1 shadow-sm transition">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                        <a href="{{ route('sand-casting.casting-orders.plans', ['tab' => 'plans']) }}" class="bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold text-xs px-3 py-2 rounded-lg transition" title="Reset">
                            <i class="fas fa-undo"></i>
                        </a>
                    </div>
                </form>
            </div>

            <!-- Plans Table -->
            <form id="form-create-order" action="{{ route('sand-casting.casting-orders.create') }}" method="GET">
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
                                    <th class="p-3 text-center">Ukuran</th>
                                    <th class="p-3 text-center">AISI</th>
                                    <th class="p-3 text-center">Qty Rencana</th>
                                    <th class="p-3 text-center">Qty Sudah Diperintah</th>
                                    <th class="p-3 text-center">Qty Sisa Dapat Diperintah</th>
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
                                    @endphp
                                    <tr class="hover:bg-slate-50 transition {{ $plan->is_closed ? 'bg-slate-50/50 opacity-60' : '' }}">
                                        <td class="p-3 text-center">
                                            @if($canSelect)
                                                <input type="checkbox" name="plan_ids[]" value="{{ $plan->id }}" class="plan-checkbox rounded text-blue-600 focus:ring-blue-500 border-slate-300">
                                            @else
                                                <span class="text-slate-300"><i class="fas fa-ban"></i></span>
                                            @endif
                                        </td>
                                        <td class="p-3 text-center font-mono text-slate-400">{{ $plans->firstItem() + $index }}</td>
                                        <td class="p-3 font-mono font-bold text-blue-700">{{ $plan->code }}</td>
                                        <td class="p-3 font-medium">{{ $plan->customer ?: '-' }}</td>
                                        <td class="p-3 font-semibold text-slate-800">{{ $plan->item_name }}</td>
                                        <td class="p-3 text-center font-mono">{{ $plan->size ?: '-' }}</td>
                                        <td class="p-3 text-center font-mono">{{ $plan->aisi ?: '-' }}</td>
                                        <td class="p-3 text-center font-bold text-slate-900">{{ number_format($plan->qty_planned) }}</td>
                                        <td class="p-3 text-center font-medium {{ $scheduled > 0 ? 'text-amber-600' : 'text-slate-400' }}">
                                            {{ number_format($scheduled) }}
                                        </td>
                                        <td class="p-3 text-center font-bold {{ $remainingToOrder > 0 ? 'text-blue-600' : 'text-emerald-600' }}">
                                            {{ number_format($remainingToOrder) }}
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
                                                <form action="{{ route('sand-casting.casting-orders.store') }}" method="POST" class="inline" onsubmit="return confirm('Tutup rencana ini agar tidak dapat dibuatkan Perintah Cor baru?')">
                                                    @csrf
                                                    <input type="hidden" name="action" value="close_plan">
                                                    <input type="hidden" name="production_plan_id" value="{{ $plan->id }}">
                                                    <button type="submit" class="text-slate-400 hover:text-red-500 font-bold p-1" title="Tutup Rencana (Close)">
                                                        <i class="fas fa-lock"></i>
                                                    </button>
                                                </form>
                                            @else
                                                <form action="{{ route('sand-casting.casting-orders.store') }}" method="POST" class="inline" onsubmit="return confirm('Buka kembali rencana ini?')">
                                                    @csrf
                                                    <input type="hidden" name="action" value="open_plan">
                                                    <input type="hidden" name="production_plan_id" value="{{ $plan->id }}">
                                                    <button type="submit" class="text-slate-400 hover:text-emerald-500 font-bold p-1" title="Buka Rencana (Reopen)">
                                                        <i class="fas fa-lock-open"></i>
                                                    </button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="13" class="p-8 text-center text-slate-400">
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
            <!-- Filter Bar for Casting Orders -->
            <div class="bg-white p-4 rounded-b-xl border border-slate-200 shadow-sm">
                <form method="GET" action="{{ route('sand-casting.casting-orders.plans') }}" class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <input type="hidden" name="tab" value="orders">

                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">No Perintah Cor</label>
                        <input type="text" name="casting_order_number" value="{{ request('casting_order_number') }}" placeholder="PCOR-..."
                               class="w-full bg-slate-50 border border-slate-200 text-xs rounded-lg px-2.5 py-1.5 focus:outline-none focus:border-blue-500 font-medium text-slate-700">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Cari Item / Kode</label>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari dalam dokumen..."
                               class="w-full bg-slate-50 border border-slate-200 text-xs rounded-lg px-2.5 py-1.5 focus:outline-none focus:border-blue-500 font-medium text-slate-700">
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
                                <th class="p-3 text-center">Status</th>
                                <th class="p-3 text-left">Dibuat Oleh</th>
                                <th class="p-3 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            @forelse($castingOrders as $index => $order)
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="p-3 text-center font-mono text-slate-400">{{ $castingOrders->firstItem() + $index }}</td>
                                    <td class="p-3 font-mono font-bold text-blue-700">
                                        <a href="{{ route('sand-casting.casting-orders.show', $order) }}" class="hover:underline">
                                            {{ $order->casting_order_number }}
                                        </a>
                                    </td>
                                    <td class="p-3 text-center font-medium">{{ $order->scheduled_date ? $order->scheduled_date->format('d/m/Y') : '-' }}</td>
                                    <td class="p-3 text-center font-medium">{{ $order->lines->count() }} item</td>
                                    <td class="p-3 text-center font-bold text-slate-900">{{ number_format($order->qty_ordered) }} pcs</td>
                                    <td class="p-3 text-center">
                                        @if($order->status === 'DRAFT')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">DRAFT</span>
                                        @elseif($order->status === 'ISSUED')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-100 text-blue-800">ISSUED</span>
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
                                                <i class="fas fa-print"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="p-8 text-center text-slate-400">
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

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const checkAll = document.getElementById('check-all');
            const checkboxes = document.querySelectorAll('.plan-checkbox');
            const btnCreate = document.getElementById('btn-create-order');
            const selectedCountSpan = document.getElementById('selected-count');

            function updateSelection() {
                const checked = document.querySelectorAll('.plan-checkbox:checked');
                const count = checked.length;
                if (selectedCountSpan) selectedCountSpan.textContent = count;
                if (btnCreate) btnCreate.disabled = count === 0;
            }

            if (checkAll) {
                checkAll.addEventListener('change', function () {
                    checkboxes.forEach(cb => cb.checked = checkAll.checked);
                    updateSelection();
                });
            }

            checkboxes.forEach(cb => {
                cb.addEventListener('change', updateSelection);
            });
        });

        function submitSelectedPlans() {
            const form = document.getElementById('form-create-order');
            if (form) form.submit();
        }
    </script>
@endsection
