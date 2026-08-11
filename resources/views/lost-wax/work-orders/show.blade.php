@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">{{ $workOrder->et_code }}</h1>
            <p class="text-gray-500 text-[10px]">Detail Work Order Lost Wax</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('lost-wax.work-orders.edit', $workOrder) }}" class="bg-slate-700 hover:bg-slate-800 text-white text-xs font-bold py-1.5 px-3 rounded">
                <i class="fas fa-pen"></i> Edit
            </a>
            <a href="{{ route('lost-wax.work-orders.index') }}" class="text-slate-500 hover:text-slate-700 text-xs">
                <i class="fas fa-arrow-left"></i> Kembali
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="space-y-6 max-w-7xl">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Item</div>
                <div class="font-bold text-slate-800">{{ optional($workOrder->itemReference)->item_code_snapshot ?? '-' }}</div>
                <div class="text-xs text-slate-500">{{ optional($workOrder->itemReference)->item_name_snapshot ?? '-' }}</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Qty PO / Stock</div>
                <div class="font-bold text-slate-800">{{ number_format($workOrder->po_quantity) }} / {{ number_format($workOrder->stock_quantity) }}</div>
                <div class="text-xs text-slate-500">Net: {{ number_format($workOrder->net_requirement_quantity) }} pcs</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Plan / WIP</div>
                <div class="font-bold text-slate-800">{{ number_format($workOrder->planned_quantity) }} / {{ number_format($workOrder->assembly_output_quantity) }}</div>
                <div class="text-xs text-slate-500">WIP sebelum tree: {{ number_format($workOrder->remaining_before_tree_quantity) }} pcs</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Status</div>
                <div class="font-bold text-slate-800">{{ ucfirst($workOrder->status) }}</div>
                <div class="text-xs text-slate-500">{{ $workOrder->po_number }}</div>
            </div>
        </div>

        {{-- Tree Summary --}}
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl shadow-sm border border-l-4 border-l-amber-400 border-slate-200 p-4">
                <div class="text-xs text-slate-500">Assembly Output</div>
                <div class="font-bold text-slate-800 text-lg">{{ number_format($workOrder->assembly_output_quantity) }} pcs</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-l-4 border-l-amber-400 border-slate-200 p-4">
                <div class="text-xs text-slate-500">Tree Dialokasikan</div>
                <div class="font-bold text-slate-800 text-lg">{{ number_format($workOrder->tree_quantity) }} pcs</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-l-4 border-l-amber-400 border-slate-200 p-4">
                <div class="text-xs text-slate-500">Tree Count</div>
                <div class="font-bold text-slate-800 text-lg">{{ $workOrder->tree_count }} tree</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-l-4 border-l-amber-400 border-slate-200 p-4">
                <div class="text-xs text-slate-500">Sisa Belum Tree</div>
                <div class="font-bold text-slate-800 text-lg">{{ number_format($workOrder->remaining_unallocated_quantity) }} pcs</div>
            </div>
        </div>

        {{-- Generated Trees --}}
        @php
            $generatedTrees = \App\Models\LostWaxTree::where('work_order_id', $workOrder->id)
                ->with('plan')
                ->orderBy('tree_number')
                ->get();
        @endphp

        @if($generatedTrees->isNotEmpty())
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-bold text-slate-800">Tree List ({{ $generatedTrees->count() }})</h2>
                    <span class="text-xs text-slate-500">
                        Total: {{ number_format($generatedTrees->sum('quantity')) }} pcs
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-slate-500 border-b border-slate-200">
                                <th class="pb-2 pr-4">Barcode</th>
                                <th class="pb-2 pr-4">Tree #</th>
                                <th class="pb-2 pr-4">Qty</th>
                                <th class="pb-2 pr-4">Plan</th>
                                <th class="pb-2 pr-4">Status</th>
                                <th class="pb-2 pr-4">Tgl</th>
                                <th class="pb-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($generatedTrees as $tree)
                                <tr class="border-b border-slate-100 hover:bg-slate-50">
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $tree->barcode }}</td>
                                    <td class="py-2 pr-4">{{ str_pad((string) $tree->tree_number, 3, '0', STR_PAD_LEFT) }}</td>
                                    <td class="py-2 pr-4">{{ number_format($tree->quantity) }}</td>
                                    <td class="py-2 pr-4">{{ optional($tree->plan)->wave_number ? 'Wave '.str_pad((string) $tree->plan->wave_number, 3, '0', STR_PAD_LEFT) : '-' }}</td>
                                    <td class="py-2 pr-4">
                                        <span class="text-[10px] bg-amber-50 text-amber-700 px-2 py-0.5 rounded-full">{{ $tree->status }}</span>
                                    </td>
                                    <td class="py-2 pr-4 text-xs text-slate-500">{{ $tree->production_date->format('d-m-Y') }}</td>
                                    <td class="py-2 text-right">
                                        <a href="{{ route('lost-wax.trees.show', $tree) }}" class="text-amber-600 hover:text-amber-700 text-xs mr-2">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="{{ route('lost-wax.trees.traveler', $tree) }}" target="_blank" class="text-amber-600 hover:text-amber-700 text-xs" title="Print Traveler">
                                            <i class="fas fa-print"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-bold text-slate-800">Plan / Wave</h2>
                    <span class="text-xs text-slate-500">{{ $workOrder->plan_wave_count }} plan</span>
                </div>

                <div class="space-y-3 mb-6">
                    @forelse($workOrder->plans as $plan)
                        <div class="rounded-lg border border-slate-200 p-3">
                            <div class="flex items-center justify-between">
                                <div class="font-semibold text-slate-800">Wave {{ str_pad($plan->wave_number, 3, '0', STR_PAD_LEFT) }} - {{ ucfirst($plan->plan_type) }}</div>
                                <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">{{ $plan->status }}</span>
                            </div>
                            <div class="text-sm text-slate-600 mt-1">Qty: {{ number_format($plan->planned_quantity) }} pcs</div>
                            @if($plan->reason)
                                <div class="text-xs text-slate-500 mt-1">Alasan: {{ $plan->reason }}</div>
                            @endif
                            <div class="mt-2">
                                <a href="{{ route('lost-wax.trees.generate', $plan) }}"
                                    class="text-xs bg-amber-100 hover:bg-amber-200 text-amber-800 font-bold py-1 px-2 rounded inline-block">
                                    <i class="fas fa-sitemap"></i> Generate Tree
                                </a>
                            </div>
                        </div>
                    @empty
                        <div class="text-sm text-slate-500">Belum ada plan/wave.</div>
                    @endforelse
                </div>

                <form method="POST" action="{{ route('lost-wax.work-orders.plans.store', $workOrder) }}" class="grid grid-cols-1 md:grid-cols-2 gap-3 border-t border-slate-200 pt-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Wave</label>
                        <input type="number" name="wave_number" min="1" value="{{ $nextWaveNumber }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Tipe Plan</label>
                        <select name="plan_type" class="w-full rounded-lg border-slate-300 text-sm">
                            <option value="initial">Initial</option>
                            <option value="additional">Additional</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Qty Plan</label>
                        <input type="number" name="planned_quantity" min="1" class="w-full rounded-lg border-slate-300 text-sm" required>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Status</label>
                        <select name="status" class="w-full rounded-lg border-slate-300 text-sm">
                            <option value="planned">Planned</option>
                            <option value="draft">Draft</option>
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-slate-700 mb-1">Alasan / Catatan</label>
                        <textarea name="reason" rows="2" class="w-full rounded-lg border-slate-300 text-sm"></textarea>
                    </div>
                    <div class="md:col-span-2 flex justify-end">
                        <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white text-sm font-bold py-2 px-4 rounded-lg">Tambah Plan</button>
                    </div>
                </form>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-bold text-slate-800">WIP Sebelum Tree</h2>
                    <span class="text-xs text-slate-500">Moulding / Assembly</span>
                </div>

                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div class="rounded-lg bg-slate-50 p-3">
                        <div class="text-xs text-slate-500">Moulding Output</div>
                        <div class="font-bold text-slate-800">{{ number_format($workOrder->moulding_output_quantity) }} pcs</div>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-3">
                        <div class="text-xs text-slate-500">Assembly Output</div>
                        <div class="font-bold text-slate-800">{{ number_format($workOrder->assembly_output_quantity) }} pcs</div>
                    </div>
                </div>

                <div class="space-y-2 mb-6">
                    @forelse($workOrder->wipEntries as $entry)
                        <div class="rounded-lg border border-slate-200 p-3 text-sm">
                            <div class="flex items-center justify-between">
                                <div class="font-semibold text-slate-800">{{ ucfirst($entry->stage) }} - {{ number_format($entry->quantity) }} pcs</div>
                                <span class="text-xs text-slate-500">{{ optional($entry->produced_at)->format('d M Y H:i') }}</span>
                            </div>
                            <div class="text-xs text-slate-500 mt-1">Status: {{ $entry->status }}</div>
                            @if($entry->notes)
                                <div class="text-xs text-slate-500 mt-1">{{ $entry->notes }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="text-sm text-slate-500">Belum ada catatan WIP.</div>
                    @endforelse
                </div>

                <form method="POST" action="{{ route('lost-wax.work-orders.wip.store', $workOrder) }}" class="grid grid-cols-1 md:grid-cols-2 gap-3 border-t border-slate-200 pt-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Stage</label>
                        <select name="stage" class="w-full rounded-lg border-slate-300 text-sm">
                            <option value="moulding">Moulding</option>
                            <option value="assembly">Assembly</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Qty</label>
                        <input type="number" name="quantity" min="1" class="w-full rounded-lg border-slate-300 text-sm" required>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Status</label>
                        <select name="status" class="w-full rounded-lg border-slate-300 text-sm">
                            <option value="recorded">Recorded</option>
                            <option value="draft">Draft</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Plan Terkait</label>
                        <select name="work_order_plan_id" class="w-full rounded-lg border-slate-300 text-sm">
                            <option value="">-</option>
                            @foreach($workOrder->plans as $plan)
                                <option value="{{ $plan->id }}">Wave {{ str_pad($plan->wave_number, 3, '0', STR_PAD_LEFT) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-slate-700 mb-1">Catatan</label>
                        <textarea name="notes" rows="2" class="w-full rounded-lg border-slate-300 text-sm"></textarea>
                    </div>
                    <div class="md:col-span-2 flex justify-end">
                        <button type="submit" class="bg-slate-700 hover:bg-slate-800 text-white text-sm font-bold py-2 px-4 rounded-lg">Simpan WIP</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
