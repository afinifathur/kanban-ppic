@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center gap-4">
        <a href="{{ route('wip.index') }}"
            class="w-10 h-10 flex items-center justify-center rounded-full bg-white shadow-sm border border-slate-200 text-slate-600 hover:bg-slate-50 transition-colors">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">WIP Detail -
                {{ \Carbon\Carbon::parse($date)->translatedFormat('l, j F Y') }}</h1>
            <p class="text-gray-500 text-[10px]">Manajemen berat Bahan Baku (Gross) vs Berat Jadi (Net)</p>
        </div>
    </div>
@endsection

@section('content')
    <div class="space-y-6 pb-12">
        @foreach($groups as $heatNumber => $items)
            @php
                $totalFinished = $items->sum(fn($i) => $i->qty_pcs * $i->weight_kg);
                $brutoWeight = $items->first()->bruto_weight;
                $scrap = $brutoWeight > 0 ? $brutoWeight - $totalFinished : 0;
                $scrapPct = $brutoWeight > 0 ? ($scrap / $brutoWeight) * 100 : 0;
            @endphp
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="bg-slate-50 px-5 py-3 border-b border-slate-200 flex justify-between items-center">
                    <div class="flex items-center gap-3">
                        <div
                            class="bg-emerald-100 text-emerald-700 w-10 h-10 rounded-lg flex items-center justify-center font-bold">
                            <i class="fas fa-fire"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-slate-800">Heat Number: {{ $heatNumber ?: 'N/A' }}</h3>
                            <p class="text-[10px] text-slate-500 uppercase tracking-wider font-semibold">Casting Group</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="text-right">
                            <div class="text-[10px] text-slate-400 font-bold uppercase">Total Berat Jadi</div>
                            <div class="font-bold text-slate-700">{{ number_format($totalFinished, 2) }} kg</div>
                        </div>
                    </div>
                </div>

                <div class="p-0 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="bg-slate-50/50 text-slate-500 text-[10px] uppercase tracking-wider border-b border-slate-100">
                                <th class="px-5 py-3 text-left font-bold">Item Name</th>
                                <th class="px-5 py-3 text-center font-bold">Qty</th>
                                <th class="px-5 py-3 text-right font-bold">Weight/pc</th>
                                <th class="px-5 py-3 text-right font-bold">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($items as $item)
                                <tr>
                                    <td class="px-5 py-3">
                                        <div class="font-medium text-slate-700">{{ $item->item_name }}</div>
                                        <div class="text-[10px] text-slate-400">{{ $item->item_code }} | {{ $item->aisi }}</div>
                                    </td>
                                    <td class="px-5 py-3 text-center font-bold text-slate-600">{{ $item->qty_pcs }} pcs</td>
                                    <td class="px-5 py-3 text-right text-slate-500">{{ number_format($item->weight_kg, 2) }} kg</td>
                                    <td class="px-5 py-3 text-right font-bold text-slate-700">
                                        {{ number_format($item->qty_pcs * $item->weight_kg, 2) }} kg</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="p-5 bg-slate-50/30 border-t border-slate-100">
                    <div class="grid grid-cols-3 gap-6 items-end">
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Bahan Baku / Bruto
                                (kg)</label>
                            <div class="relative">
                                <input type="number" step="0.01" value="{{ $brutoWeight ?: '' }}"
                                    id="bruto_{{ str_replace(' ', '_', $heatNumber) }}"
                                    class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all"
                                    placeholder="0.00">
                                <div class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs">KG</div>
                            </div>
                        </div>
                        <div class="flex gap-4">
                            <div class="flex-1 p-2 bg-red-50 rounded-lg border border-red-100">
                                <div class="text-[10px] text-red-500 font-bold uppercase">Scrap / Riser</div>
                                <div class="text-sm font-bold text-red-700 flex justify-between">
                                    <span>{{ number_format($scrap, 2) }} kg</span>
                                    <span class="text-xs opacity-70">{{ number_format($scrapPct, 1) }}%</span>
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <button onclick="updateWip('{{ $heatNumber }}', '{{ $date }}')"
                                class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg text-sm transition-all shadow-sm hover:shadow active:transform active:scale-95">
                                <i class="fas fa-save mr-1"></i> Update
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <script>
        async function updateWip(heatNumber, date) {
            const id = 'bruto_' + heatNumber.replace(/ /g, '_');
            const weight = document.getElementById(id).value;

            if (!weight || weight <= 0) {
                alert('Silakan isi berat bahan baku yang valid.');
                return;
            }

            const res = await fetch('{{ route('wip.update') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({
                    date: date,
                    heat_number: heatNumber,
                    bruto_weight: weight
                })
            });

            const result = await res.json();
            if (result.success) {
                window.location.reload();
            } else {
                alert(result.message || 'Gagal mengupdate data.');
            }
        }
    </script>
@endsection