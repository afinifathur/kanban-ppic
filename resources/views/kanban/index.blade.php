@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center gap-2">
        @foreach($flow as $index => $d)
            @php
                $isCurrent = $d === $dept;
                $stat = $allStats[$d] ?? null;
                $dName = match($d) {
                    'bubut_od' => 'Bubut OD',
                    'bubut_cnc' => 'Bubut CNC', 
                    default => ucfirst($d)
                };
            @endphp
            
            <a href="{{ route('kanban.index', $d) }}" class="flex-shrink-0 group relative flex items-center">
                <div class="px-2.5 py-1 {{ $isCurrent ? 'bg-blue-600 text-white shadow-sm ring-1 ring-blue-600' : 'bg-gray-50 text-gray-600 hover:bg-gray-100 border border-gray-200 dashed' }} transition-all rounded text-center">
                    <div class="text-[11px] font-bold whitespace-nowrap leading-tight">{{ $dName }}</div>
                    <div class="text-[9px] {{ $isCurrent ? 'text-blue-100' : 'text-gray-500' }} mt-0.5 whitespace-nowrap font-mono">
                        {{ \App\Helpers\FormatHelper::compactNumber($stat->total_pcs ?? 0) }}<span class="opacity-75">pcs</span> • {{ \App\Helpers\FormatHelper::compactNumber($stat->total_kg ?? 0) }}<span class="opacity-75">kg</span>
                    </div>
                </div>
            </a>

            @if(!$loop->last)
                <div class="text-gray-300 px-0.5">
                    <i class="fas fa-chevron-right text-[10px]"></i>
                </div>
            @endif
        @endforeach
    </div>
@endsection

@section('content')
<div class="h-full flex flex-col">
    <!-- Header -->
    <div class="bg-white px-6 py-3 flex justify-between items-center shrink-0 border-b border-gray-200">
        <div>
            <h1 class="text-xl font-bold text-slate-800">Departemen {{ match($dept) { 'bubut_od' => 'Bubut OD', 'bubut_cnc' => 'Bubut CNC', default => ucfirst($dept) } }}</h1>
            <p class="text-xs text-slate-500 mt-1">Tracking Produksi FIFO - Kanban System</p>
        </div>
        
        <div class="flex items-center gap-4">
             <div class="text-right mr-4">
                <div class="text-xs text-slate-500 uppercase font-semibold">Total di Departemen</div>
                <div class="text-lg font-bold text-slate-700">
                    <span class="text-blue-600"><i class="fas fa-cube mr-1"></i>{{ $totalPcs }}</span> <span class="text-sm text-slate-400">pcs</span>
                    <span class="mx-2 text-slate-300">|</span>
                    <span class="text-green-600"><i class="fas fa-weight-hanging mr-1"></i>{{ $totalKg }}</span> <span class="text-sm text-slate-400">kg</span>
                </div>
            </div>

            @if($nextDept && $dept !== 'rencana_cor')
            <button onclick="submitMove()" class="bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold py-2 px-4 rounded shadow-sm text-sm flex items-center gap-2 transition-colors">
                Proses ke {{ match($nextDept) { 'bubut_od' => 'Bubut OD', 'bubut_cnc' => 'Bubut CNC', default => ucfirst($nextDept) } }} <i class="fas fa-arrow-right"></i>
            </button>
            @elseif($dept === 'rencana_cor')
            <span class="bg-blue-50 text-blue-600 py-2 px-4 rounded text-sm font-bold border border-blue-200">Antrian PPIC</span>
            @else
            <span class="bg-gray-100 text-gray-500 py-2 px-4 rounded text-sm font-medium border border-gray-200">End of Line</span>
            @endif
        </div>
    </div>

    <!-- Kanban Board -->
    <div class="flex-1 overflow-hidden bg-gray-100 p-2">
        <form id="moveForm" action="{{ route('kanban.move') }}" method="POST" class="h-full flex flex-col">
            @csrf
            <input type="hidden" name="to_dept" value="{{ $nextDept }}">
            
            <div class="grid grid-cols-4 gap-4 h-full min-w-[1000px]">
                @foreach($lines as $lineNum => $items)
                <div class="flex flex-col h-full bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                    <!-- Column Header -->
                    <div class="bg-blue-600 text-white px-3 py-2 flex justify-between items-center shrink-0">
                        <h3 class="font-bold text-sm">Line {{ $lineNum }}</h3>
                        <div class="text-[10px] opacity-90 font-mono">
                            @if($dept === 'rencana_cor')
                                {{ $items->count() }} P.O <span class="mx-1 opacity-50">|</span> {{ number_format($items->sum('qty_remaining')) }} pcs <span class="mx-1 opacity-50">|</span> {{ number_format($items->sum(fn($i) => $i->qty_remaining * $i->weight)) }} kg
                            @else
                                {{ $items->count() }} HN <span class="mx-1 opacity-50">|</span> {{ number_format($items->sum('qty_pcs')) }} pcs <span class="mx-1 opacity-50">|</span> {{ number_format($items->sum(fn($i) => $i->qty_pcs * $i->weight_kg)) }} kg
                            @endif
                        </div>
                    </div>

                    <!-- Items Container -->
                    <div class="flex-1 overflow-y-auto p-1.5 space-y-2 bg-gray-50 custom-scrollbar">
                        @foreach($items as $item)
                            @if($dept === 'rencana_cor')
                                <!-- Production Plan Card -->
                                <div class="relative bg-white p-2 rounded shadow-sm border border-gray-200 border-l-[3px] border-l-blue-400 hover:shadow-md transition-shadow group">
                                    <div class="flex flex-col gap-1 min-w-0">
                                        <div class="flex justify-between items-start gap-1">
                                            <div class="text-[11px] font-bold text-gray-800 leading-tight line-clamp-2 whitespace-normal flex-1" title="{{ $item->item_name }}">
                                                {{ $item->item_name }}
                                            </div>
                                            <div class="text-[10px] font-bold text-blue-600 shrink-0">#{{ $loop->iteration }}</div>
                                        </div>
                                        
                                        <div class="text-[10px] text-gray-500 font-mono italic">#{{ $item->po_number }}</div>
                                        
                                        <div class="flex items-center justify-between mt-1 pt-1 border-t border-gray-50">
                                            <div class="flex gap-2 text-[10px] font-semibold text-slate-600">
                                                <span>{{ number_format($item->qty_remaining) }} pcs</span>
                                                <span class="text-gray-300">|</span>
                                                <span>{{ number_format($item->qty_remaining * $item->weight, 2) }} kg</span>
                                            </div>
                                            @if($item->customer)
                                            <span class="text-[9px] bg-gray-100 text-gray-500 px-1 rounded uppercase font-bold truncate max-w-[50px]">
                                                {{ $item->customer }}
                                            </span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @else
                                <!-- Production Item Card (Heat Number) -->
                                @php
                                    $agingDays = $item->aging_days;
                                    $agingTextClass = 'text-gray-500';
                                    $borderColor = 'border-l-gray-400';
                                    
                                    if($item->aging_color == 'green') { $agingTextClass = 'text-green-600'; $borderColor = 'border-l-green-500'; }
                                    elseif($item->aging_color == 'yellow') { $agingTextClass = 'text-yellow-600'; $borderColor = 'border-l-yellow-500'; }
                                    elseif($item->aging_color == 'orange') { $agingTextClass = 'text-orange-600'; $borderColor = 'border-l-orange-500'; }
                                    elseif($item->aging_color == 'red') { $agingTextClass = 'text-red-600'; $borderColor = 'border-l-red-500'; }
                                @endphp

                                <div class="relative bg-white p-2 rounded shadow-sm border border-gray-200 border-l-[3px] {{ $borderColor }} hover:shadow-md transition-shadow group">
                                    <div class="flex items-start gap-1.5">
                                        <input type="checkbox" name="item_ids[]" value="{{ $item->id }}" class="mt-0.5 w-3 h-3 text-blue-600 rounded border-gray-300 focus:ring-blue-500 cursor-pointer shrink-0">
                                        
                                        <div class="flex-1 min-w-0">
                                            <div class="flex justify-between items-start gap-1">
                                                <div class="text-[11px] font-bold text-gray-800 leading-tight line-clamp-2 whitespace-normal" title="{{ $item->item_name }}">{{ $item->item_name }}</div>
                                                <div class="text-[11px] font-bold {{ $agingTextClass }} shrink-0">{{ number_format($agingDays, 0) }}h</div>
                                            </div>
                                            
                                            <div class="text-[10px] text-gray-500 font-mono mt-0.5 italic">#{{ $item->heat_number }}</div>

                                            <div class="flex items-center justify-between mt-1 pt-1 border-t border-gray-50">
                                                <div class="flex gap-2 text-[10px] font-semibold text-slate-600">
                                                    <span>{{ $item->qty_pcs }} <span class="text-[8px] font-normal opacity-70 uppercase tracking-tighter">pcs</span></span>
                                                    <span class="text-gray-300">|</span>
                                                    <span>{{ number_format($item->qty_pcs * $item->weight_kg, 2) }} <span class="text-[8px] font-normal opacity-70 uppercase tracking-tighter">kg</span></span>
                                                </div>
                                                
                                                <div class="flex items-center gap-1">
                                                    @if($item->customer)
                                                    <span class="text-[9px] bg-gray-100 text-gray-500 px-1 rounded border border-gray-100 uppercase font-bold truncate max-w-[40px]" title="{{ $item->customer }}">
                                                        {{ $item->customer }}
                                                    </span>
                                                    @endif
                                                    <span class="text-[10px] font-bold text-slate-300 group-hover:text-blue-500 transition-colors">
                                                        {{ $loop->iteration }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>
        </form>
    </div>
</div>

<!-- Reorder Modal -->
<div id="reorderModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 backdrop-blur-sm">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 transform transition-all scale-100">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-slate-800">Edit Antrian (Queue)</h3>
            <button onclick="closeReorderModal()" class="text-slate-400 hover:text-slate-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form action="{{ route('kanban.reorder') }}" method="POST">
            @csrf
            <input type="hidden" name="department" value="{{ $dept }}">

            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-1">Departemen</label>
                <div class="w-full bg-slate-100 border border-slate-300 rounded px-3 py-2 text-slate-600 uppercase font-bold text-sm">
                    {{ str_replace('_', ' ', $dept) }}
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-1">Line Produksi</label>
                <select name="line" class="w-full border-slate-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                    <option value="1">Line 1</option>
                    <option value="2">Line 2</option>
                    <option value="3">Line 3</option>
                    <option value="4">Line 4</option>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-4 mb-6">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Pindahkan Dari (No Urut)</label>
                    <input type="number" name="from_pos" min="1" required class="w-full border-slate-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 text-center font-bold text-lg" placeholder="1">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Ke Posisi (No Urut)</label>
                    <input type="number" name="to_pos" min="1" required class="w-full border-slate-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 text-center font-bold text-lg" placeholder="5">
                </div>
            </div>

            <div class="flex gap-3">
                <button type="button" onclick="closeReorderModal()" class="flex-1 bg-white border border-slate-300 text-slate-700 font-bold py-2 rounded hover:bg-slate-50 transition-colors">
                    Batal
                </button>
                <button type="submit" class="flex-1 bg-blue-600 text-white font-bold py-2 rounded hover:bg-blue-700 shadow transition-colors">
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function submitMove() {
    const checked = document.querySelectorAll('input[name="item_ids[]"]:checked');
    if (checked.length === 0) {
        alert('Pilih item terlebih dahulu!');
        return;
    }
    if (confirm('Pindahkan ' + checked.length + ' item ke {{ ucfirst($nextDept) }}?')) {
        document.getElementById('moveForm').submit();
    }
}

function openReorderModal() {
    document.getElementById('reorderModal').classList.remove('hidden');
    document.getElementById('reorderModal').classList.add('flex');
}

function closeReorderModal() {
    document.getElementById('reorderModal').classList.add('hidden');
    document.getElementById('reorderModal').classList.remove('flex');
}
</script>
@endsection
