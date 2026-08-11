@php
    $workOrder = $workOrder ?? new \App\Models\LostWaxWorkOrder();
    $isEdit = $workOrder->exists;
@endphp

<form method="POST" action="{{ $isEdit ? route('lost-wax.work-orders.update', $workOrder) : route('lost-wax.work-orders.store') }}" class="space-y-4">
    @csrf
    @if($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">ET / Referensi Work Order</label>
            <input type="text" name="et_code" value="{{ old('et_code', $workOrder->et_code ?? '') }}"
                class="w-full rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500" required>
            @error('et_code')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Kode Item</label>
            <input type="text" name="item_code" list="lostWaxItems" value="{{ old('item_code', optional($workOrder->itemReference)->item_code_snapshot ?? '') }}"
                class="w-full rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500" required>
            <datalist id="lostWaxItems">
                @foreach($itemOptions as $item)
                    <option value="{{ $item['code'] }}">{{ $item['code'] }} - {{ $item['name'] ?? '' }}</option>
                @endforeach
            </datalist>
            @error('item_code')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">No. PO</label>
            <input type="text" name="po_number" value="{{ old('po_number', $workOrder->po_number ?? '') }}"
                class="w-full rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500" required>
            @error('po_number')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Nama Customer</label>
            <input type="text" name="customer_name" value="{{ old('customer_name', $workOrder->customer_name ?? '') }}"
                class="w-full rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500">
            @error('customer_name')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Qty PO</label>
            <input type="number" name="po_quantity" min="0" value="{{ old('po_quantity', $workOrder->po_quantity ?? 0) }}"
                class="w-full rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500" required>
            @error('po_quantity')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Qty Stock</label>
            <input type="number" name="stock_quantity" min="0" value="{{ old('stock_quantity', $workOrder->stock_quantity ?? 0) }}"
                class="w-full rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500" required>
            @error('stock_quantity')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Qty Net Requirement</label>
            <input type="number" name="net_requirement_quantity" min="0" value="{{ old('net_requirement_quantity', $workOrder->net_requirement_quantity ?? 0) }}"
                class="w-full rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500" required>
            @error('net_requirement_quantity')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
            <select name="status" class="w-full rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500">
                @foreach(['draft','planned','active','hold','completed','cancelled'] as $status)
                    <option value="{{ $status }}" @selected(old('status', $workOrder->status ?? 'draft') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
            @error('status')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Target Tanggal</label>
            <input type="date" name="due_date" value="{{ old('due_date', optional($workOrder->due_date ?? null)->format('Y-m-d')) }}"
                class="w-full rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500">
            @error('due_date')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Family Code</label>
            @php $families = config('lost_wax.families', []); @endphp
            @if(count($families) > 0)
                <select name="family_code" class="w-full rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500 text-sm">
                    <option value="">-- Pilih Family --</option>
                    @foreach($families as $code => $label)
                        <option value="{{ $code }}" @selected(old('family_code', $workOrder->family_code ?? '') === $code)>{{ $code }} - {{ $label }}</option>
                    @endforeach
                </select>
            @else
                <input type="text" name="family_code" value="{{ old('family_code', $workOrder->family_code ?? '') }}"
                    class="w-full rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500" maxlength="10">
            @endif
            @error('family_code')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror
        </div>

        <div class="flex items-center gap-2 pt-2">
            <input type="hidden" name="require_layer_7" value="0">
            <input type="checkbox" name="require_layer_7" id="require_layer_7" value="1"
                class="rounded border-slate-300 text-amber-600 focus:ring-amber-500"
                @checked(old('require_layer_7', $workOrder->require_layer_7 ?? false))>
            <label for="require_layer_7" class="text-sm font-medium text-slate-700">Produk ini memerlukan Lapisan 7</label>
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Catatan</label>
        <textarea name="notes" rows="4" class="w-full rounded-lg border-slate-300 focus:border-amber-500 focus:ring-amber-500">{{ old('notes', $workOrder->notes ?? '') }}</textarea>
        @error('notes')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror
    </div>

    <div class="flex items-center gap-3">
        <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white font-bold py-2 px-4 rounded-lg">
            {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Work Order' }}
        </button>
        <a href="{{ route('lost-wax.work-orders.index') }}" class="text-slate-500 hover:text-slate-700 text-sm">Kembali</a>
    </div>
</form>
