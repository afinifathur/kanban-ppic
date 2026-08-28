@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full relative">
        <div>
            <h1 class="text-lg font-bold text-gray-800 leading-tight">Detail Rencana Produksi</h1>
            <p class="text-gray-500 text-[10px]">{{ \Carbon\Carbon::parse($date)->isoFormat('dddd, D MMMM Y') }}</p>
        </div>
        
        @if(isset($planTitle) && $planTitle)
        <div class="absolute hidden md:flex items-center gap-2 left-1/2 transform -translate-x-1/2 bg-blue-50 px-4 py-1.5 rounded-full border border-blue-200 group">
            <span class="text-sm font-bold text-blue-800"><i class="fas fa-clipboard-check mr-1 opacity-70"></i> {{ $planTitle }}</span>
            <button onclick="editTitle('{{ $date }}', '{{ $planTitle }}')" class="text-blue-400 hover:text-blue-600 ml-1" title="Edit Judul">
                <i class="fas fa-edit"></i>
            </button>
        </div>
        @else
        <div class="absolute hidden md:flex items-center gap-2 left-1/2 transform -translate-x-1/2 bg-gray-50 px-4 py-1.5 rounded-full border border-gray-200 group">
            <button onclick="editTitle('{{ $date }}', '')" class="text-sm font-bold text-gray-500 hover:text-blue-600" title="Tambah Judul">
                <i class="fas fa-plus"></i> Tambah Judul
            </button>
        </div>
        @endif

        <a href="{{ route('plan.index') }}" class="text-blue-600 hover:underline text-xs flex items-center gap-1">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
    </div>
@endsection

@section('content')
    <div class="bg-white shadow-md rounded-lg p-6 h-full flex flex-col">
        <div class="flex-1 overflow-auto">
            <table class="min-w-full border-collapse border border-gray-200 text-sm">
                <thead class="bg-gray-100 sticky top-0">
                    @php
                        if (!function_exists('sortLink')) {
                            function sortLink($column, $label, $align = 'left') {
                                $currentSort = request('sort');
                                $currentDirection = request('direction', 'asc');
                                $direction = ($currentSort === $column && $currentDirection === 'asc') ? 'desc' : 'asc';
                                $url = request()->fullUrlWithQuery(['sort' => $column, 'direction' => $direction]);
                                $icon = '';
                                if ($currentSort === $column) {
                                    $icon = $currentDirection === 'asc' ? '<i class="fas fa-sort-up ml-1 text-blue-500"></i>' : '<i class="fas fa-sort-down ml-1 text-blue-500"></i>';
                                } else {
                                    $icon = '<i class="fas fa-sort ml-1 text-gray-300 group-hover:text-gray-400"></i>';
                                }
                                $justify = $align === 'center' ? 'justify-center' : 'justify-start';
                                return "<a href=\"{$url}\" class=\"flex items-center {$justify} hover:text-blue-600 group w-full\"><span>{$label}</span> {$icon}</a>";
                            }
                        }
                    @endphp
                    <tr>
                        <th class="border border-gray-200 px-3 py-2 text-center w-10">No</th>
                        <th class="border border-gray-200 px-3 py-2 text-left">{!! sortLink('code', 'Code') !!}</th>
                        <th class="border border-gray-200 px-3 py-2 text-left">{!! sortLink('customer', 'Customer') !!}</th>
                        <th class="border border-gray-200 px-3 py-2 text-left">{!! sortLink('item_name', 'Item Name') !!}</th>
                        <th class="border border-gray-200 px-3 py-2 text-center">{!! sortLink('qty_planned', 'Planned', 'center') !!}</th>
                        <th class="border border-gray-200 px-3 py-2 text-center">{!! sortLink('hasil_cor', 'Hasil Cor', 'center') !!}</th>
                        <th class="border border-gray-200 px-3 py-2 text-center">{!! sortLink('qty_remaining', 'Remaining', 'center') !!}</th>
                        <th class="border border-gray-200 px-3 py-2 text-center">{!! sortLink('status', 'Status', 'center') !!}</th>
                        <th class="border border-gray-200 px-3 py-2 text-center">{!! sortLink('line_number', 'Line', 'center') !!}</th>
                        <th class="border border-gray-200 px-3 py-2 text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($plans as $index => $plan)
                        <tr class="hover:bg-gray-50 text-[12px]">
                            <td class="border border-gray-200 px-3 py-2 text-center text-gray-400">
                                {{ $index + 1 }}</td>
                            <td class="border border-gray-200 px-3 py-2 font-mono text-xs text-center">
                                {{ $plan->code }}</td>
                            <td class="border border-gray-200 px-3 py-2">
                                <div class="uppercase font-semibold text-slate-700">{{ $plan->customer ?: '-' }}</div>
                                <div class="text-[11px] text-gray-500 font-mono mt-0.5">{{ $plan->po_number }}</div>
                            </td>
                            <td class="border border-gray-200 px-3 py-2">
                                <div class="font-bold">{{ $plan->item_name }}</div>
                                <div class="text-[10px] text-gray-500">{{ $plan->item_code }} | {{ $plan->aisi }} |
                                    {{ $plan->size }}</div>
                            </td>
                            <td class="border border-gray-200 px-3 py-2 text-center font-bold">
                                {{ number_format($plan->qty_planned) }}</td>
                            <td class="border border-gray-200 px-3 py-2 text-center font-bold text-green-600">
                                {{ number_format($plan->qty_planned - $plan->qty_remaining) }}</td>
                            <td class="border border-gray-200 px-3 py-2 text-center font-bold text-orange-600">
                                {{ number_format($plan->qty_remaining) }}</td>
                            <td class="border border-gray-200 px-3 py-2 text-center">
                                <span
                                    class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase 
                                    {{ $plan->status == 'planning' ? 'bg-gray-100 text-gray-600' : ($plan->status == 'active' ? 'bg-blue-100 text-blue-600' : 'bg-green-100 text-green-600') }}">
                                    {{ $plan->status }}
                                </span>
                            </td>
                            <td class="border border-gray-200 px-3 py-2 font-bold text-blue-600 text-center text-lg">
                                {{ $plan->line_number }}</td>
                            <td class="border border-gray-200 px-3 py-2 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <a href="{{ route('plan.edit', $plan->id) }}" class="text-blue-500 hover:text-blue-700" title="Edit Rencana">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    @php
                                        $isFrozen = $plan->is_closed || $plan->printOrderLines()->exists() || $plan->items()->exists();
                                    @endphp
                                    @if($isFrozen)
                                        <span class="text-gray-300 cursor-not-allowed" title="{{ $plan->is_closed ? 'Rencana sudah ditutup dan tidak dapat dihapus.' : ($plan->printOrderLines()->exists() ? 'Rencana sudah memiliki SPK cetak dan tidak dapat dihapus.' : 'Rencana sudah memiliki data produksi dan tidak dapat dihapus.') }}">
                                            <i class="fas fa-trash"></i>
                                        </span>
                                    @else
                                        <form action="{{ route('plan.destroy', $plan->id) }}" method="POST"
                                            onsubmit="return confirm('Apakah yakin ingin data {{ $plan->item_name }} dihapus?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-500 hover:text-red-700" title="Hapus Rencana">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="border border-gray-200 px-3 py-8 text-center text-gray-400 italic">Belum ada
                                data rencana.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Hidden Form for Editing Title -->
    <form id="editTitleForm" method="POST" action="{{ route('plan.updateTitle') }}" style="display: none;">
        @csrf
        <input type="hidden" name="date" id="editTitleDate">
        <input type="hidden" name="title" id="editTitleInput">
    </form>

    <script>
        function editTitle(date, currentTitle) {
            let newTitle = prompt("Masukkan Judul Rencana untuk antrian pada tanggal ini:", currentTitle);
            if (newTitle !== null && newTitle.trim() !== "") {
                document.getElementById('editTitleDate').value = date;
                document.getElementById('editTitleInput').value = newTitle;
                document.getElementById('editTitleForm').submit();
            }
        }
    </script>
@endsection