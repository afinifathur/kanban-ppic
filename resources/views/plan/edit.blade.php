@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-gray-800 leading-tight">Edit Rencana Produksi</h1>
            <p class="text-gray-500 text-[10px]">ID Rencana: #{{ $plan->id }} | Kode: {{ $plan->code ?? '-' }}</p>
        </div>
        <a href="{{ route('plan.index', ['date' => $plan->created_at->format('Y-m-d')]) }}"
            class="text-blue-600 hover:underline text-xs flex items-center gap-1">
            <i class="fas fa-arrow-left"></i> Batal
        </a>
    </div>
@endsection

@section('content')
    <div class="bg-white shadow-md rounded-lg p-6 max-w-4xl mx-auto">
        @if(session('error'))
            <div class="mb-4 p-3 bg-red-50 border-l-4 border-red-500 text-xs text-red-700 rounded-r flex items-center gap-2">
                <i class="fas fa-exclamation-triangle text-red-500"></i>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        @if(session('success'))
            <div class="mb-4 p-3 bg-green-50 border-l-4 border-green-500 text-xs text-green-700 rounded-r flex items-center gap-2">
                <i class="fas fa-check-circle text-green-500"></i>
                <span>{{ session('success') }}</span>
            </div>
        @endif

        <form action="{{ route('plan.update', $plan->id) }}" method="POST" class="space-y-6">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Line, Domain & Status -->
                <div class="space-y-4">
                    <!-- Production Domain -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Proses / Domain Produksi <span class="text-red-500">*</span>
                        </label>
                        @if($isDomainLocked)
                            <div class="p-3 bg-gray-50 border border-gray-200 rounded-md">
                                <div class="flex items-center gap-2">
                                    @if($plan->production_domain === 'LOST_WAX')
                                        <span class="px-2.5 py-1 text-xs font-bold rounded uppercase bg-amber-100 text-amber-800 border border-amber-300 flex items-center gap-1">
                                            <i class="fas fa-fire text-[11px]"></i> LOST WAX
                                        </span>
                                    @else
                                        <span class="px-2.5 py-1 text-xs font-bold rounded uppercase bg-indigo-100 text-indigo-800 border border-indigo-300 flex items-center gap-1">
                                            <i class="fas fa-cubes text-[11px]"></i> SAND CASTING
                                        </span>
                                    @endif
                                    <span class="text-xs text-gray-400 font-medium flex items-center gap-1">
                                        <i class="fas fa-lock text-gray-400"></i> Terkunci
                                    </span>
                                </div>
                                <input type="hidden" name="production_domain" value="{{ $plan->production_domain }}">
                                <p class="text-[11px] text-gray-500 mt-2">
                                    <i class="fas fa-info-circle text-gray-400 mr-0.5"></i>
                                    Domain produksi tidak dapat diubah karena rencana ini sudah memiliki transaksi SPK/produksi atau telah dimulai.
                                </p>
                            </div>
                        @else
                            <select name="production_domain"
                                class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                                required>
                                <option value="LOST_WAX" {{ old('production_domain', $plan->production_domain) === 'LOST_WAX' ? 'selected' : '' }}>
                                    LOST WAX (Lost Wax Production)
                                </option>
                                <option value="SAND_CASTING" {{ old('production_domain', $plan->production_domain) === 'SAND_CASTING' ? 'selected' : '' }}>
                                    SAND CASTING (Sand Casting Production)
                                </option>
                            </select>
                        @endif
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Line Number</label>
                        <select name="line_number"
                            class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                            required>
                            @foreach(range(1, 4) as $l)
                                <option value="{{ $l }}" {{ old('line_number', $plan->line_number) == $l ? 'selected' : '' }}>Line {{ $l }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="status"
                            class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                            required>
                            <option value="planning" {{ old('status', $plan->status) == 'planning' ? 'selected' : '' }}>Planning (Queue)
                            </option>
                            <option value="active" {{ old('status', $plan->status) == 'active' ? 'selected' : '' }}>Active (In Process)
                            </option>
                            <option value="completed" {{ old('status', $plan->status) == 'completed' ? 'selected' : '' }}>Completed</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Customer</label>
                        <input type="text" name="customer" value="{{ old('customer', $plan->customer) }}"
                            class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                            placeholder="Optional">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">P.O. Number</label>
                        <input type="text" name="po_number" value="{{ old('po_number', $plan->po_number) }}"
                            class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                            required>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">P.O. Quantity (Commitment)</label>
                        <input type="number" name="po_quantity" value="{{ old('po_quantity', $plan->po_quantity) }}" min="0"
                            class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                            placeholder="Optional">
                    </div>
                </div>

                <!-- Item Details -->
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Item Code</label>
                        <input type="text" name="item_code" value="{{ old('item_code', $plan->item_code) }}"
                            class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                            required>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Item Name</label>
                        <input type="text" name="item_name" value="{{ old('item_name', $plan->item_name) }}"
                            class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                            required>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">AISI</label>
                            <input type="text" name="aisi" value="{{ old('aisi', $plan->aisi) }}"
                                class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Size</label>
                            <input type="text" name="size" value="{{ old('size', $plan->size) }}"
                                class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Qty Planned (Pcs)</label>
                            <input type="number" name="qty_planned" value="{{ old('qty_planned', $plan->qty_planned) }}"
                                class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                                required min="1">
                            <p class="text-[10px] text-gray-400 mt-1">Sisa saat ini:
                                {{ number_format($plan->qty_remaining) }} pcs</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Unit Weight (Kg)</label>
                            <input type="number" step="0.01" name="weight" value="{{ old('weight', $plan->weight) }}"
                                class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm">
                        </div>
                    </div>
                </div>
            </div>

            <div class="pt-6 border-t border-gray-100 flex justify-end gap-3">
                <a href="{{ route('plan.index', ['date' => $plan->created_at->format('Y-m-d')]) }}"
                    class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Batal</a>
                <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded shadow transition-all text-sm">
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
@endsection