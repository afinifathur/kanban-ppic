@extends('layouts.app')

@section('top_bar')
    <div class="flex flex-col">
        <h1 class="text-lg font-bold text-slate-800 leading-tight">Input Kerusakan: {{ ucfirst(str_replace('_', ' ', $dept)) }}</h1>
        <p class="text-slate-500 text-[10px]">Detailkan item yang memiliki jumlah reject/scrap.</p>
    </div>
@endsection

@section('content')
    <div class="flex flex-col gap-6">

        @if(session('success'))
            <div
                class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg flex items-center shadow-sm">
                <i class="fas fa-check-circle mr-3 text-emerald-500"></i>
                <span class="font-medium">{{ session('success') }}</span>
            </div>
        @endif

        <!-- Stats and Filters -->
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
            <!-- Stat Card -->
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center gap-4">
                <div
                    class="bg-amber-100 text-amber-600 w-12 h-12 rounded-lg flex items-center justify-center text-xl shrink-0">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div>
                    <div class="text-xs font-bold text-slate-500 uppercase tracking-wider">Antrian Detail Kerusakan</div>
                    <div class="text-2xl font-black text-slate-800">{{ $incompleteCount }} <span
                            class="text-sm font-normal text-slate-400">item</span></div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="lg:col-span-3 bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                <form action="{{ route('defects.index', $dept) }}" method="GET" class="flex flex-wrap items-center gap-4">
                    <div class="flex-1 min-w-[200px] relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-search text-slate-400"></i>
                        </div>
                        <input type="text" name="search" value="{{ $search }}"
                            class="block w-full pl-10 pr-3 py-2 bg-slate-50 border border-slate-200 text-slate-700 rounded-lg focus:ring-blue-500 focus:border-blue-500 text-sm"
                            placeholder="Cari Heat Number / Nama Item...">
                    </div>

                    <div class="w-40 shrink-0">
                        <select name="status" onchange="this.form.submit()"
                            class="block w-full py-2 bg-slate-50 border border-slate-200 text-slate-700 rounded-lg focus:ring-blue-500 focus:border-blue-500 text-sm">
                            <option value="all" {{ $status === 'all' ? 'selected' : '' }}>Semua Status</option>
                            <option value="incomplete" {{ $status === 'incomplete' ? 'selected' : '' }}>Belum Selesai</option>
                            <option value="completed" {{ $status === 'completed' ? 'selected' : '' }}>Selesai</option>
                        </select>
                    </div>

                    <div class="w-40 shrink-0">
                        <select name="sort" onchange="this.form.submit()"
                            class="block w-full py-2 bg-slate-50 border border-slate-200 text-slate-700 rounded-lg focus:ring-blue-500 focus:border-blue-500 text-sm">
                            <option value="newest" {{ $sort === 'newest' ? 'selected' : '' }}>Terbaru</option>
                            <option value="oldest" {{ $sort === 'oldest' ? 'selected' : '' }}>Terlama</option>
                        </select>
                    </div>

                    <button type="submit"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-bold shadow-sm transition-colors">
                        Filter
                    </button>

                    @if($search || $status !== 'all' || $sort !== 'newest')
                        <a href="{{ route('defects.index', $dept) }}"
                            class="text-slate-400 hover:text-slate-600 text-sm font-medium">Reset</a>
                    @endif
                </form>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-600">
                    <thead class="bg-slate-50 text-slate-700 font-semibold border-b border-slate-200">
                        <tr>
                            <th class="px-6 py-3">Tanggal</th>
                            <th class="px-6 py-3">Item</th>
                            <th class="px-6 py-3">Heat No / Code</th>
                            <th class="px-6 py-3 text-center">Total Rusak</th>
                            <th class="px-6 py-3">Detail Kerusakan</th>
                            <th class="px-6 py-3 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($items as $item)
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    {{ $item->production_date ?? $item->created_at->format('Y-m-d') }}
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-medium text-slate-800">{{ $item->item_name }}</div>
                                    <div class="text-xs text-slate-500">{{ $item->item_code }}</div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-mono text-xs bg-slate-100 px-2 py-1 rounded inline-block">
                                        {{ $item->heat_number ?? $item->code }}
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="bg-red-100 text-red-700 font-bold px-2 py-1 rounded">
                                        {{ $item->scrap_qty }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    @php
                                        $loggedQty = $item->defects->sum('qty');
                                        $remainingQty = $item->scrap_qty - $loggedQty;
                                    @endphp

                                    @if($item->defects->count() > 0)
                                        <ul class="text-xs space-y-1 mb-2">
                                            @foreach($item->defects as $defect)
                                                <li class="flex items-center gap-2">
                                                    <span class="font-semibold text-slate-700">{{ $defect->qty }}</span>
                                                    <span class="text-slate-500">{{ $defect->defectType->name }}</span>
                                                    @if($defect->notes)
                                                        <span class="text-slate-400 italic">({{ $defect->notes }})</span>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    @if($remainingQty > 0)
                                        <div class="text-xs text-amber-600 font-medium">
                                            <i class="fas fa-exclamation-triangle mr-1"></i>
                                            Perlu detail untuk {{ $remainingQty }} pcs
                                        </div>
                                    @elseif($remainingQty < 0)
                                        <div class="text-xs text-red-600 font-medium">
                                            <i class="fas fa-times-circle mr-1"></i>
                                            Over-logged by {{ abs($remainingQty) }} pcs
                                        </div>
                                    @else
                                        <div class="text-xs text-emerald-600 font-medium">
                                            <i class="fas fa-check-circle mr-1"></i>
                                            Completed
                                        </div>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <button
                                        onclick="openDefectModal('{{ $item->id }}', '{{ $item->scrap_qty }}', {{ $item->defects->toJson() }})"
                                        class="text-blue-600 hover:text-blue-800 font-medium text-sm border border-blue-200 hover:bg-blue-50 focus:ring-4 focus:outline-none focus:ring-blue-300 rounded-lg px-3 py-1.5 text-center inline-flex items-center">
                                        <i class="fas fa-edit mr-2"></i> Update
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-slate-400">
                                    Tidak ada item dengan status rusak di departemen ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-4 border-t border-slate-200">
                {{ $items->links() }}
            </div>
        </div>
    </div>

    <!-- Defect Modal -->
    <div id="defectModal" tabindex="-1" aria-hidden="true"
        class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 h-[calc(100%-1rem)] max-h-full bg-slate-900/50 backdrop-blur-sm">
        <div class="relative p-4 w-full max-w-2xl max-h-full">
            <div class="relative bg-white rounded-lg shadow dark:bg-gray-700">
                <div class="flex items-center justify-between p-4 md:p-5 border-b rounded-t dark:border-gray-600">
                    <h3 class="text-xl font-semibold text-gray-900 dark:text-white">
                        Update Detail Kerusakan
                    </h3>
                    <button type="button" onclick="closeDefectModal()"
                        class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ms-auto inline-flex justify-center items-center dark:hover:bg-gray-600 dark:hover:text-white">
                        <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none"
                            viewBox="0 0 14 14">
                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6" />
                        </svg>
                        <span class="sr-only">Close modal</span>
                    </button>
                </div>

                <form id="defectForm" method="POST" action="">
                    @csrf
                    <div class="p-4 md:p-5 space-y-4">
                        <div class="flex justify-between items-center bg-slate-50 p-3 rounded mb-4">
                            <span class="text-sm font-medium text-slate-600">Total Rusak (Scrap):</span>
                            <span id="modalTotalScrap" class="text-lg font-bold text-red-600">0</span>
                        </div>

                        <div id="defectInputs" class="space-y-3">
                            <!-- Dynamic Inputs added via JS -->
                        </div>

                        <button type="button" onclick="addDefectRow()"
                            class="mt-2 text-sm text-blue-600 hover:text-blue-800 bg-blue-50 hover:bg-blue-100 px-3 py-1.5 rounded transition-colors flex items-center gap-1">
                            <i class="fas fa-plus-circle"></i> Tambah Baris
                        </button>

                        <div class="flex justify-end items-center mt-4 pt-4 border-t border-slate-100">
                            <span class="text-sm font-medium text-slate-600 mr-2">Total Allocated:</span>
                            <span id="modalTotalAllocated" class="text-lg font-bold text-slate-800">0</span>
                        </div>
                    </div>

                    <div class="flex items-center p-4 md:p-5 border-t border-gray-200 rounded-b dark:border-gray-600">
                        <button type="submit"
                            class="text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:outline-none focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 text-center dark:bg-blue-600 dark:hover:bg-blue-700 dark:focus:ring-blue-800">Simpan
                            Perubahan</button>
                        <button type="button" onclick="closeDefectModal()"
                            class="py-2.5 px-5 ms-3 text-sm font-medium text-gray-900 focus:outline-none bg-white rounded-lg border border-gray-200 hover:bg-gray-100 hover:text-blue-700 focus:z-10 focus:ring-4 focus:ring-gray-100 dark:focus:ring-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:border-gray-600 dark:hover:text-white dark:hover:bg-gray-700">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Templates for defect types
        const defectTypes = @json($defectTypes);

        function openDefectModal(itemId, scrapQty, existingDefects) {
            const modal = document.getElementById('defectModal');
            const form = document.getElementById('defectForm');

            // Show modal
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            // Set basic values
            document.getElementById('modalTotalScrap').textContent = scrapQty;
            form.action = "{{ route('defects.store', ':id') }}".replace(':id', itemId);

            // Clear previous inputs
            const container = document.getElementById('defectInputs');
            container.innerHTML = '';

            // Add rows for existing defects or at least one empty row
            if (existingDefects && existingDefects.length > 0) {
                existingDefects.forEach((defect, index) => {
                    addDefectRow(defect);
                });
            } else {
                addDefectRow();
            }

            updateTotalAllocated();
        }

        function closeDefectModal() {
            const modal = document.getElementById('defectModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function addDefectRow(data = null) {
            const container = document.getElementById('defectInputs');
            const index = container.children.length;

            const row = document.createElement('div');
            row.className = 'flex gap-2 items-start';

            let optionsHtml = '<option value="">Pilih Jenis...</option>';
            defectTypes.forEach(type => {
                const selected = (data && data.defect_type_id == type.id) ? 'selected' : '';
                optionsHtml += `<option value="${type.id}" ${selected}>${type.name}</option>`;
            });

            row.innerHTML = `
                    <div class="flex-1">
                        <select name="defects[${index}][defect_type_id]" class="w-full rounded-md border-slate-300 py-1.5 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                            ${optionsHtml}
                        </select>
                    </div>
                    <div class="w-20">
                        <input type="number" name="defects[${index}][qty]" value="${data ? data.qty : ''}" placeholder="Qty" min="1" 
                            class="w-full rounded-md border-slate-300 py-1.5 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 text-center defect-qty" required oninput="updateTotalAllocated()">
                    </div>
                    <div class="flex-1">
                        <input type="text" name="defects[${index}][notes]" value="${data ? data.notes || '' : ''}" placeholder="Catatan (opsional)" 
                            class="w-full rounded-md border-slate-300 py-1.5 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <button type="button" onclick="this.parentElement.remove(); updateTotalAllocated();" class="text-slate-400 hover:text-red-500 pt-2">
                        <i class="fas fa-times"></i>
                    </button>
                `;

            container.appendChild(row);
        }

        function updateTotalAllocated() {
            const inputs = document.querySelectorAll('.defect-qty');
            let total = 0;
            inputs.forEach(input => {
                const val = parseInt(input.value) || 0;
                total += val;
            });

            const display = document.getElementById('modalTotalAllocated');
            display.textContent = total;

            const max = parseInt(document.getElementById('modalTotalScrap').textContent) || 0;

            if (total === max) {
                display.className = 'text-lg font-bold text-emerald-600';
            } else if (total > max) {
                display.className = 'text-lg font-bold text-red-600';
            } else {
                display.className = 'text-lg font-bold text-amber-600';
            }
        }
    </script>
@endsection