@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-gray-800 leading-tight">Detail Input {{ ucfirst($dept) }}</h1>
            <p class="text-gray-500 text-[10px]">{{ \Carbon\Carbon::parse($date)->isoFormat('dddd, D MMMM Y') }}</p>
        </div>
        <a href="{{ route('input.index', $dept) }}" class="text-blue-600 hover:underline text-xs flex items-center gap-1">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
    </div>
@endsection

@section('content')
    <div class="p-0 h-full flex flex-col">

        <div class="bg-white shadow rounded-lg flex-1 overflow-hidden flex flex-col">
            <div class="overflow-x-auto flex-1">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Heat
                                Number</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item
                                Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Line
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Qty
                                (PCS)</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Status/Note</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($items as $item)
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $item->code }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    {{ $item->heat_number }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $item->item_name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">Line {{ $item->line_number }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $item->qty_pcs }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $item->weight_kg }}</td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span
                                        class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                        {{ match ($item->current_dept) { 'bubut_od' => 'Bubut OD', 'bubut_cnc' => 'Bubut CNC', default => ucfirst($item->current_dept)} }}
                                    </span>
                                    @if($item->scrap_qty > 0)
                                        <span
                                            class="ml-1 px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                            {{ $item->scrap_qty }} SCRAP
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <button onclick="openEditModal({{ json_encode($item) }})"
                                        class="text-blue-600 hover:text-blue-900 mr-3">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="openDeleteModal({{ $item->history_id }})"
                                        class="text-red-600 hover:text-red-900">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 backdrop-blur-sm">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6">
            <h3 class="text-lg font-bold text-slate-800 mb-4">Edit Data Produksi</h3>
            <form id="editForm">
                @csrf
                <input type="hidden" id="edit_history_id">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Qty (PCS)</label>
                    <input type="number" id="edit_qty" required
                        class="w-full border-slate-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Weight (KG)</label>
                    <input type="number" step="0.01" id="edit_weight" required
                        class="w-full border-slate-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Bruto (KG)</label>
                        <input type="number" step="0.01" id="edit_bruto"
                            class="w-full border-slate-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Netto (KG)</label>
                        <input type="number" step="0.01" id="edit_netto"
                            class="w-full border-slate-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Customer</label>
                    <select id="edit_customer"
                        class="w-full border-slate-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                        <option value="">-- Pilih Customer --</option>
                        @foreach($customers as $cust)
                            <option value="{{ $cust->name }}">{{ $cust->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex gap-3">
                    <button type="button" onclick="closeModal('editModal')"
                        class="flex-1 bg-white border border-slate-300 text-slate-700 font-bold py-2 rounded">Batal</button>
                    <button type="submit" class="flex-1 bg-blue-600 text-white font-bold py-2 rounded">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 backdrop-blur-sm">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-sm p-6 text-center">
            <div class="text-red-600 text-5xl mb-4">
                <i class="fas fa-exclamation-circle"></i>
            </div>
            <h3 class="text-lg font-bold text-slate-800 mb-2">Hapus Data?</h3>
            <p class="text-slate-500 mb-6">Apakah Anda yakin ingin menghapus data ini? Tindakan ini tidak dapat dibatalkan.
            </p>
            <div class="flex gap-3">
                <button type="button" onclick="closeModal('deleteModal')"
                    class="flex-1 bg-white border border-slate-300 text-slate-700 font-bold py-2 rounded">Batal</button>
                <button id="confirmDeleteBtn" class="flex-1 bg-red-600 text-white font-bold py-2 rounded">Ya, Hapus</button>
            </div>
        </div>
    </div>

    <script>
        function openEditModal(item) {
            document.getElementById('edit_history_id').value = item.history_id;
            document.getElementById('edit_qty').value = item.qty_pcs;
            document.getElementById('edit_weight').value = item.weight_kg;
            document.getElementById('edit_bruto').value = item.bruto_weight || '';
            document.getElementById('edit_netto').value = item.netto_weight || '';
            document.getElementById('edit_customer').value = item.customer || '';
            document.getElementById('editModal').classList.remove('hidden');
            document.getElementById('editModal').classList.add('flex');
        }

        function openDeleteModal(historyId) {
            document.getElementById('deleteModal').classList.remove('hidden');
            document.getElementById('deleteModal').classList.add('flex');
            document.getElementById('confirmDeleteBtn').onclick = () => deleteHistory(historyId);
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            document.getElementById(modalId).classList.remove('flex');
        }

        document.getElementById('editForm').onsubmit = async (e) => {
            e.preventDefault();
            const id = document.getElementById('edit_history_id').value;
            const res = await fetch(`/input/history/${id}`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({
                    qty_pcs: document.getElementById('edit_qty').value,
                    weight_kg: document.getElementById('edit_weight').value,
                    bruto_weight: document.getElementById('edit_bruto').value,
                    netto_weight: document.getElementById('edit_netto').value,
                    customer: document.getElementById('edit_customer').value
                })
            });
            if (res.ok) window.location.reload();
            else alert('Gagal memperbarui data.');
        };

        async function deleteHistory(id) {
            const res = await fetch(`/input/history/${id}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            });
            if (res.ok) window.location.reload();
            else alert('Gagal menghapus data.');
        }
    </script>
@endsection