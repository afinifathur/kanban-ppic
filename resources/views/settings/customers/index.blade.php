@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-5 max-w-6xl mx-auto">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Master Customer</h1>
                <p class="text-xs text-slate-500 mt-0.5">
                    Kelola daftar customer dan klasifikasi pasar (<span class="font-bold text-blue-700">LOCAL</span> / <span class="font-bold text-purple-700">EXPORT</span>) untuk kalkulator komposisi dan rekap PCOR Sand Casting.
                </p>
            </div>
            <button type="button" onclick="openAddCustomerModal()"
                class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs px-4 py-2.5 rounded-lg shadow-sm transition">
                <i class="fas fa-plus"></i> Tambah Customer
            </button>
        </div>

        <!-- Validation Alerts -->
        @if($errors->any())
            <div class="bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 rounded-lg text-xs">
                <div class="font-bold mb-1 flex items-center gap-1.5">
                    <i class="fas fa-times-circle text-rose-600"></i> Terdapat kesalahan input:
                </div>
                <ul class="list-disc list-inside space-y-0.5">
                    @foreach($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- Summary Cards -->
        @php
            $summary = $stats ?? ['total' => $customers->count(), 'local' => 0, 'export' => 0, 'unclassified' => 0, 'active' => 0, 'inactive' => 0];
            $usedMap = $usedCustomerNames ?? [];
        @endphp
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
            <div class="bg-white rounded-xl border border-slate-200 p-3.5 shadow-sm">
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Customer</div>
                <div class="text-xl font-black text-slate-800 mt-0.5 font-mono">{{ number_format($summary['total']) }}</div>
            </div>
            <div class="bg-white rounded-xl border border-blue-200 p-3.5 shadow-sm">
                <div class="text-[10px] font-bold text-blue-600 uppercase tracking-wider">Kategori LOCAL</div>
                <div class="text-xl font-black text-blue-700 mt-0.5 font-mono">{{ number_format($summary['local']) }}</div>
            </div>
            <div class="bg-white rounded-xl border border-purple-200 p-3.5 shadow-sm">
                <div class="text-[10px] font-bold text-purple-600 uppercase tracking-wider">Kategori EXPORT</div>
                <div class="text-xl font-black text-purple-700 mt-0.5 font-mono">{{ number_format($summary['export']) }}</div>
            </div>
            <div class="bg-white rounded-xl border border-amber-200 p-3.5 shadow-sm">
                <div class="text-[10px] font-bold text-amber-600 uppercase tracking-wider">Belum Kategori</div>
                <div class="text-xl font-black text-amber-700 mt-0.5 font-mono">{{ number_format($summary['unclassified']) }}</div>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-3.5 shadow-sm col-span-2 sm:col-span-1">
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Status Aktif / Non</div>
                <div class="text-sm font-extrabold text-slate-800 mt-1 font-mono">
                    <span class="text-emerald-600">{{ number_format($summary['active']) }} Aktif</span>
                    <span class="text-slate-300 mx-1">/</span>
                    <span class="text-slate-500">{{ number_format($summary['inactive']) }} Non</span>
                </div>
            </div>
        </div>

        <!-- Inline Quick Add Customer Card -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4" id="add-customer-card">
            <h2 class="text-xs font-bold text-slate-700 uppercase tracking-wider mb-3 flex items-center gap-2">
                <i class="fas fa-user-plus text-blue-600"></i> Tambah Customer Baru
            </h2>
            <form action="{{ route('settings.customers.store') }}" method="POST" class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-end">
                @csrf
                <div class="sm:col-span-6">
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Nama Customer <span class="text-rose-500">*</span></label>
                    <input type="text" name="name" id="add_customer_name" value="{{ old('name') }}"
                        placeholder="Contoh: PT SINAR BAJA PERKASA"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-800 focus:border-blue-500 focus:outline-none"
                        required>
                </div>
                <div class="sm:col-span-3">
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Kategori Pasar <span class="text-rose-500">*</span></label>
                    <select name="category" id="add_customer_category"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold text-slate-800 focus:border-blue-500 focus:outline-none"
                        required>
                        <option value="" disabled {{ old('category') ? '' : 'selected' }}>Pilih LOCAL / EXPORT...</option>
                        <option value="LOCAL" {{ old('category') === 'LOCAL' ? 'selected' : '' }}>LOCAL</option>
                        <option value="EXPORT" {{ old('category') === 'EXPORT' ? 'selected' : '' }}>EXPORT</option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Status</label>
                    <select name="is_active"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold text-slate-800 focus:border-blue-500 focus:outline-none">
                        <option value="1" {{ old('is_active', '1') == '1' ? 'selected' : '' }}>Aktif</option>
                        <option value="0" {{ old('is_active') === '0' ? 'selected' : '' }}>Nonaktif</option>
                    </select>
                </div>
                <div class="sm:col-span-1">
                    <button type="submit"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg px-3 py-2 text-xs shadow-sm transition flex items-center justify-center gap-1.5">
                        <i class="fas fa-save"></i> Simpan
                    </button>
                </div>
            </form>
            <div class="mt-2 text-[11px] text-slate-500 flex flex-wrap gap-x-4 gap-y-1">
                <span><i class="fas fa-info-circle text-blue-500"></i> <strong>STOK NON PO</strong> otomatis dikategorikan sebagai <strong>LOCAL</strong>.</span>
                <span><i class="fas fa-info-circle text-purple-500"></i> <strong>STOK EXP</strong> otomatis dikategorikan sebagai <strong>EXPORT</strong>.</span>
            </div>
        </div>

        <!-- Search & Filter Bar + Customer Table -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="p-4 border-b border-slate-100 bg-slate-50/60">
                <form action="{{ route('settings.customers.index') }}" method="GET" class="grid grid-cols-1 sm:grid-cols-12 gap-2.5 items-center">
                    <div class="sm:col-span-5 relative">
                        <i class="fas fa-search text-slate-400 text-xs absolute left-3 top-1/2 -translate-y-1/2"></i>
                        <input type="text" name="search" value="{{ $search ?? request('search') }}"
                            placeholder="Cari nama customer..."
                            class="w-full rounded-lg border border-slate-300 pl-8 pr-3 py-1.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none">
                    </div>
                    <div class="sm:col-span-3">
                        <select name="category" onchange="this.form.submit()"
                            class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 focus:border-blue-500 focus:outline-none">
                            <option value="">Semua Kategori</option>
                            <option value="LOCAL" {{ ($categoryFilter ?? request('category')) === 'LOCAL' ? 'selected' : '' }}>Kategori: LOCAL</option>
                            <option value="EXPORT" {{ ($categoryFilter ?? request('category')) === 'EXPORT' ? 'selected' : '' }}>Kategori: EXPORT</option>
                            <option value="UNCLASSIFIED" {{ ($categoryFilter ?? request('category')) === 'UNCLASSIFIED' ? 'selected' : '' }}>Belum Dikategorikan (UNCLASSIFIED)</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <select name="status" onchange="this.form.submit()"
                            class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 focus:border-blue-500 focus:outline-none">
                            <option value="all" {{ ($statusFilter ?? request('status', 'all')) === 'all' ? 'selected' : '' }}>Semua Status</option>
                            <option value="active" {{ ($statusFilter ?? request('status')) === 'active' ? 'selected' : '' }}>Status: Aktif</option>
                            <option value="inactive" {{ ($statusFilter ?? request('status')) === 'inactive' ? 'selected' : '' }}>Status: Nonaktif</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2 flex items-center gap-1.5">
                        <button type="submit" class="flex-1 bg-slate-800 hover:bg-slate-900 text-white font-bold text-xs py-1.5 px-3 rounded-lg transition">
                            Filter
                        </button>
                        @if(!empty($search) || !empty($categoryFilter) || (($statusFilter ?? 'all') !== 'all'))
                            <a href="{{ route('settings.customers.index') }}" class="bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold text-xs py-1.5 px-2.5 rounded-lg transition" title="Reset Filter">
                                <i class="fas fa-undo"></i>
                            </a>
                        @endif
                    </div>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-xs">
                    <thead class="bg-slate-100 text-slate-600 font-bold uppercase tracking-wider text-[11px]">
                        <tr>
                            <th class="px-4 py-3 text-center w-12">No</th>
                            <th class="px-4 py-3 text-left">Nama Customer</th>
                            <th class="px-4 py-3 text-center w-48">Kategori Pasar</th>
                            <th class="px-4 py-3 text-center w-32">Status</th>
                            <th class="px-4 py-3 text-center w-40">Relasi Produksi</th>
                            <th class="px-4 py-3 text-right w-40">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse($customers as $index => $customer)
                            @php
                                $upperName = strtoupper(trim((string) $customer->name));
                                $effectiveCategory = \App\Models\Customer::resolveCategory(
                                    $customer->name,
                                    [$upperName => strtoupper((string) $customer->category)]
                                );
                                $isUsed = !empty($usedMap[$upperName]);
                            @endphp
                            <tr class="hover:bg-slate-50/80 transition {{ !$customer->is_active ? 'opacity-60 bg-slate-50/40' : '' }}">
                                <td class="px-4 py-2.5 text-center font-mono text-slate-400">{{ $index + 1 }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="font-bold text-slate-800 text-xs">{{ $customer->name }}</div>
                                    @if(in_array($upperName, ['STOK NON PO', 'STOK EXP']))
                                        <span class="text-[10px] text-slate-400 italic">Kategori otomatis sistem ({{ $effectiveCategory }})</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <form action="{{ route('settings.customers.update', $customer->id) }}" method="POST" class="inline-block">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="name" value="{{ $customer->name }}">
                                        <input type="hidden" name="is_active" value="{{ $customer->is_active ? '1' : '0' }}">
                                        <select name="category" onchange="this.form.submit()"
                                            class="text-[11px] rounded-md border px-2.5 py-1 font-bold cursor-pointer focus:outline-none {{ $effectiveCategory === 'LOCAL' ? 'bg-blue-50 border-blue-200 text-blue-700' : ($effectiveCategory === 'EXPORT' ? 'bg-purple-50 border-purple-200 text-purple-700' : 'bg-amber-50 border-amber-300 text-amber-800') }}">
                                            @if($effectiveCategory === 'UNCLASSIFIED')
                                                <option value="" selected disabled>UNCLASSIFIED (Pilih...)</option>
                                            @endif
                                            <option value="LOCAL" {{ $effectiveCategory === 'LOCAL' ? 'selected' : '' }}>LOCAL</option>
                                            <option value="EXPORT" {{ $effectiveCategory === 'EXPORT' ? 'selected' : '' }}>EXPORT</option>
                                        </select>
                                    </form>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    @if($customer->is_active)
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> AKTIF
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-200 text-slate-600 border border-slate-300">
                                            <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span> NONAKTIF
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    @if($isUsed)
                                        <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-slate-600 bg-slate-100 px-2 py-0.5 rounded border border-slate-200" title="Sudah digunakan pada Rencana Produksi / PCOR">
                                            <i class="fas fa-link text-slate-400"></i> Terpakai di Produksi
                                        </span>
                                    @else
                                        <span class="text-[10px] text-slate-400">Belum digunakan</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <div class="inline-flex items-center gap-1.5">
                                        <button type="button"
                                            onclick="openEditCustomerModal({{ $customer->id }}, @js($customer->name), @js($effectiveCategory === 'UNCLASSIFIED' ? 'LOCAL' : $effectiveCategory), {{ $customer->is_active ? '1' : '0' }})"
                                            class="px-2.5 py-1 rounded bg-slate-100 hover:bg-blue-50 text-slate-700 hover:text-blue-700 border border-slate-200 font-semibold text-[11px] transition"
                                            title="Edit Customer">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>

                                        @if(!$isUsed)
                                            <form action="{{ route('settings.customers.destroy', $customer->id) }}" method="POST"
                                                onsubmit="return confirm('Hapus customer {{ addslashes($customer->name) }}?')" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                    class="px-2 py-1 rounded bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 text-[11px] transition"
                                                    title="Hapus Customer">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-slate-400 italic text-xs">
                                    Tidak ada data customer yang sesuai dengan pencarian / filter.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Edit Customer -->
    <div id="editCustomerModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
        <div class="bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md overflow-hidden">
            <div class="px-5 py-3.5 bg-slate-800 text-white flex items-center justify-between">
                <h3 class="text-sm font-bold flex items-center gap-2">
                    <i class="fas fa-user-edit text-blue-400"></i> Edit Data Customer
                </h3>
                <button type="button" onclick="closeEditCustomerModal()" class="text-slate-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="editCustomerForm" method="POST" class="p-5 space-y-4">
                @csrf
                @method('PUT')
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nama Customer <span class="text-rose-500">*</span></label>
                    <input type="text" name="name" id="edit_customer_name" required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-800 focus:border-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Kategori Customer <span class="text-rose-500">*</span></label>
                    <select name="category" id="edit_customer_category" required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold text-slate-800 focus:border-blue-500 focus:outline-none">
                        <option value="LOCAL">LOCAL</option>
                        <option value="EXPORT">EXPORT</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Status Customer</label>
                    <select name="is_active" id="edit_customer_is_active"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold text-slate-800 focus:border-blue-500 focus:outline-none">
                        <option value="1">Aktif</option>
                        <option value="0">Nonaktif</option>
                    </select>
                </div>
                <div class="pt-2 flex items-center justify-end gap-2 border-t border-slate-100">
                    <button type="button" onclick="closeEditCustomerModal()"
                        class="px-4 py-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs transition">
                        Batal
                    </button>
                    <button type="submit"
                        class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs shadow-sm transition">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddCustomerModal() {
            const input = document.getElementById('add_customer_name');
            if (input) {
                input.scrollIntoView({ behavior: 'smooth', block: 'center' });
                input.focus();
            }
        }

        function openEditCustomerModal(id, name, category, isActive) {
            const modal = document.getElementById('editCustomerModal');
            const form = document.getElementById('editCustomerForm');
            form.action = "{{ url('/settings/customers') }}/" + id;
            document.getElementById('edit_customer_name').value = name;
            document.getElementById('edit_customer_category').value = (category === 'EXPORT') ? 'EXPORT' : 'LOCAL';
            document.getElementById('edit_customer_is_active').value = String(isActive);
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeEditCustomerModal() {
            const modal = document.getElementById('editCustomerModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    </script>
@endsection
