@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-gray-800 leading-tight">Edit Rencana Produksi</h1>
            <p class="text-gray-500 text-[10px]">ID Rencana: #{{ $plan->id }}</p>
        </div>
        <a href="{{ route('plan.index', ['date' => $plan->created_at->format('Y-m-d')]) }}"
            class="text-blue-600 hover:underline text-xs flex items-center gap-1">
            <i class="fas fa-arrow-left"></i> Batal
        </a>
    </div>
@endsection

@section('content')
    <div class="bg-white shadow-md rounded-lg p-6 max-w-4xl mx-auto">
        <form action="{{ route('plan.update', $plan->id) }}" method="POST" class="space-y-6">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Line & Status -->
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Line Number</label>
                        <select name="line_number"
                            class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            required>
                            @foreach(range(1, 4) as $l)
                                <option value="{{ $l }}" {{ $plan->line_number == $l ? 'selected' : '' }}>Line {{ $l }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="status"
                            class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            required>
                            <option value="planning" {{ $plan->status == 'planning' ? 'selected' : '' }}>Planning (Queue)
                            </option>
                            <option value="active" {{ $plan->status == 'active' ? 'selected' : '' }}>Active (In Process)
                            </option>
                            <option value="completed" {{ $plan->status == 'completed' ? 'selected' : '' }}>Completed</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Customer</label>
                        <input type="text" name="customer" value="{{ old('customer', $plan->customer) }}"
                            class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            placeholder="Optional">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">P.O. Number</label>
                        <input type="text" name="po_number" value="{{ old('po_number', $plan->po_number) }}"
                            class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            required>
                    </div>
                </div>

                <!-- Item Details -->
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Item Code</label>
                        <input type="text" name="item_code" value="{{ old('item_code', $plan->item_code) }}"
                            class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            required>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Item Name</label>
                        <input type="text" name="item_name" value="{{ old('item_name', $plan->item_name) }}"
                            class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            required>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">AISI</label>
                            <input type="text" name="aisi" value="{{ old('aisi', $plan->aisi) }}"
                                class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Size</label>
                            <input type="text" name="size" value="{{ old('size', $plan->size) }}"
                                class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Qty Planned (Pcs)</label>
                            <input type="number" name="qty_planned" value="{{ old('qty_planned', $plan->qty_planned) }}"
                                class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                required min="1">
                            <p class="text-[10px] text-gray-400 mt-1">Sisa saat ini:
                                {{ number_format($plan->qty_remaining) }} pcs</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Unit Weight (Kg)</label>
                            <input type="number" step="0.01" name="weight" value="{{ old('weight', $plan->weight) }}"
                                class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                    </div>
                </div>
            </div>

            <div class="pt-6 border-t border-gray-100 flex justify-end gap-3">
                <a href="{{ route('plan.index', ['date' => $plan->created_at->format('Y-m-d')]) }}"
                    class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Batal</a>
                <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded shadow transition-all">
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
@endsection