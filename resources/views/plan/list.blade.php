@extends('layouts.app')

@section('top_bar')
    <div class="flex flex-col md:flex-row items-start md:items-center justify-between w-full gap-2 relative">
        <div class="flex items-center gap-3">
            <div>
                <h1 class="text-lg font-bold text-gray-800 leading-tight">Detail Rencana Produksi</h1>
                <p class="text-gray-500 text-[10px]">{{ \Carbon\Carbon::parse($date)->isoFormat('dddd, D MMMM Y') }}</p>
            </div>
            
            <!-- Prominent Domain Badge in Header -->
            @if(isset($headerDomain) && $headerDomain)
                @if($headerDomain === 'LOST_WAX')
                    <span class="text-xs bg-amber-100 text-amber-900 border border-amber-300 font-bold px-3 py-1 rounded-full uppercase tracking-wider flex items-center gap-1.5 shadow-sm">
                        <i class="fas fa-fire text-amber-600"></i> LOST WAX
                    </span>
                @elseif($headerDomain === 'SAND_CASTING')
                    <span class="text-xs bg-indigo-100 text-indigo-900 border border-indigo-300 font-bold px-3 py-1 rounded-full uppercase tracking-wider flex items-center gap-1.5 shadow-sm">
                        <i class="fas fa-cubes text-indigo-600"></i> SAND CASTING
                    </span>
                @else
                    <span class="text-xs bg-gray-100 text-gray-800 border border-gray-300 font-bold px-3 py-1 rounded-full uppercase">
                        {{ str_replace('_', ' ', $headerDomain) }}
                    </span>
                @endif
            @endif

            @if(isset($headerScope) && $headerScope)
                <span class="text-xs bg-slate-100 text-slate-800 border border-slate-300 font-bold px-2.5 py-1 rounded-full uppercase">
                    {{ str_replace('_', ' ', $headerScope) }}
                </span>
            @endif
        </div>
        
        @if(isset($planTitle) && $planTitle)
        <div class="flex items-center gap-2 bg-blue-50 px-4 py-1.5 rounded-full border border-blue-200 group">
            <span class="text-sm font-bold text-blue-800"><i class="fas fa-clipboard-check mr-1 opacity-70"></i> {{ $planTitle }}</span>
            @if(auth()->user()->hasRole('ppic') && auth()->user()->product_scope)
            <button onclick="editTitle('{{ $date }}', '{{ $planTitle }}')" class="text-blue-400 hover:text-blue-600 ml-1" title="Edit Judul">
                <i class="fas fa-edit"></i>
            </button>
            @endif
        </div>
        @else
        <div class="flex items-center gap-2 bg-gray-50 px-4 py-1.5 rounded-full border border-gray-200 group">
            @if(auth()->user()->hasRole('ppic') && auth()->user()->product_scope)
            <button onclick="editTitle('{{ $date }}', '')" class="text-sm font-bold text-gray-500 hover:text-blue-600" title="Tambah Judul">
                <i class="fas fa-plus"></i> Tambah Judul
            </button>
            @else
            <span class="text-xs font-semibold text-gray-400 italic">Tanpa Judul</span>
            @endif
        </div>
        @endif

        <a href="{{ route('plan.index', array_filter(['production_domain' => $selectedDomain !== 'ALL' ? $selectedDomain : null])) }}" class="text-blue-600 hover:underline text-xs flex items-center gap-1">
            <i class="fas fa-arrow-left"></i> Kembali ke Index
        </a>
    </div>
@endsection

@section('content')
    <div class="bg-white shadow-md rounded-lg p-4 sm:p-6 h-full flex flex-col space-y-3">
        {{-- SEARCH & FILTER BAR --}}
        <div class="bg-slate-50 border border-slate-200 rounded-lg p-2.5 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2 shadow-xs">
            <div class="relative flex-1 min-w-0">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                    <i class="fas fa-search text-xs"></i>
                </div>
                <input
                    type="text"
                    id="planSearchInput"
                    placeholder="Cari kode produksi, nama item, atau customer..."
                    autocomplete="off"
                    class="block w-full pl-9 pr-8 py-1.5 text-xs bg-white border border-slate-300 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 rounded-md text-slate-900 placeholder-slate-400 transition-colors"
                >
                <button
                    type="button"
                    id="planSearchClearBtn"
                    onclick="clearPlanSearch()"
                    class="hidden absolute inset-y-0 right-0 pr-2.5 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none"
                    title="Hapus pencarian"
                >
                    <i class="fas fa-times-circle text-xs"></i>
                </button>
            </div>

            <div class="flex items-center gap-2 shrink-0 justify-between sm:justify-end">
                {{-- Match Count Badge --}}
                <div id="planSearchMatchBadge" class="hidden items-center gap-1 px-2.5 py-1 rounded-md text-xs font-mono font-bold bg-blue-50 text-blue-700 border border-blue-200">
                    <span id="planSearchMatchCount">0</span>
                    <span class="text-[10px] font-sans font-bold uppercase">BARIS COCOK</span>
                </div>

                <button
                    type="button"
                    id="planSearchResetBtn"
                    onclick="clearPlanSearch()"
                    class="hidden inline-flex items-center justify-center gap-1 px-2.5 py-1.5 rounded-md text-xs font-bold bg-white hover:bg-slate-100 text-slate-700 border border-slate-300 shadow-xs transition-all min-h-[32px]"
                >
                    <i class="fas fa-undo text-[10px]"></i>
                    <span>RESET</span>
                </button>
            </div>
        </div>

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
                        <th class="border border-gray-200 px-3 py-2 text-center">{!! sortLink('production_domain', 'Domain', 'center') !!}</th>
                        <th class="border border-gray-200 px-3 py-2 text-center">{!! sortLink('qty_planned', 'Planned', 'center') !!}</th>
                        <th class="border border-gray-200 px-3 py-2 text-center">
                            {!! sortLink('actual_execution', ($headerDomain === 'SAND_CASTING' ? 'Hasil Cor (Good)' : ($headerDomain === 'LOST_WAX' ? 'Hasil Cetak (Good)' : 'Actual Good')), 'center') !!}
                        </th>
                        <th class="border border-gray-200 px-3 py-2 text-center">{!! sortLink('remaining_target', 'Sisa Target', 'center') !!}</th>
                        <th class="border border-gray-200 px-3 py-2 text-center">{!! sortLink('status', 'Status', 'center') !!}</th>
                        <th class="border border-gray-200 px-3 py-2 text-center">{!! sortLink('line_number', 'Line', 'center') !!}</th>
                        <th class="border border-gray-200 px-3 py-2 text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr id="planNoMatchesRow" class="hidden">
                        <td colspan="11" class="border border-gray-200 px-3 py-8 text-center text-slate-500 bg-slate-50/50">
                            <div class="flex flex-col items-center justify-center gap-1.5">
                                <i class="fas fa-search text-xl text-slate-400 mb-1"></i>
                                <p class="font-bold text-slate-700 text-xs sm:text-sm">Tidak ada data yang cocok</p>
                                <p class="text-[11px] text-slate-400">Tidak ditemukan rencana dengan kata kunci "<span id="planNoMatchesQuery" class="font-bold text-slate-600"></span>".</p>
                                <button type="button" onclick="clearPlanSearch()" class="mt-2 inline-flex items-center gap-1 px-3 py-1 text-xs font-bold rounded-md bg-slate-200 hover:bg-slate-300 text-slate-700 transition-colors">
                                    <i class="fas fa-undo text-[10px]"></i> Reset Pencarian
                                </button>
                            </div>
                        </td>
                    </tr>
                    @forelse($plans as $index => $plan)
                        @php
                            $statusLabel = $plan->execution_status === 'COMPLETED' ? 'Completed' : ($plan->execution_status === 'IN_PROGRESS' ? 'In Progress' : 'Not Started');
                            $searchText = strtolower(implode(' ', array_filter([
                                $plan->code,
                                $plan->customer,
                                $plan->po_number,
                                $plan->item_name,
                                $plan->item_code,
                                $plan->aisi,
                                $plan->size,
                                $plan->production_domain,
                                $plan->execution_status,
                                $statusLabel,
                                'line ' . $plan->line_number,
                                (string) $plan->line_number,
                            ])));
                        @endphp
                        <tr class="plan-row hover:bg-gray-50 text-[12px]" data-search-text="{{ $searchText }}">
                            <td class="border border-gray-200 px-3 py-2 text-center text-gray-400">
                                {{ $index + 1 }}</td>
                            <td class="border border-gray-200 px-3 py-2 font-mono text-xs text-center font-bold">
                                {{ $plan->code }}</td>
                            <td class="border border-gray-200 px-3 py-2">
                                <div class="uppercase font-semibold text-slate-700">{{ $plan->customer ?: '-' }}</div>
                                <div class="text-[11px] text-gray-500 font-mono mt-0.5">{{ $plan->po_number }}</div>
                            </td>
                            <td class="border border-gray-200 px-3 py-2">
                                <div class="font-bold text-gray-800">{{ $plan->item_name }}</div>
                                <div class="text-[10px] text-gray-500">{{ $plan->item_code }} | {{ $plan->aisi }} |
                                    {{ $plan->size }}</div>
                            </td>
                            <td class="border border-gray-200 px-3 py-2 text-center">
                                @if($plan->production_domain === 'LOST_WAX')
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-amber-100 text-amber-800 border border-amber-300">
                                        Lost Wax
                                    </span>
                                @elseif($plan->production_domain === 'SAND_CASTING')
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-indigo-100 text-indigo-800 border border-indigo-300">
                                        Sand Casting
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-gray-100 text-gray-600">
                                        {{ $plan->production_domain }}
                                    </span>
                                @endif
                            </td>
                            <td class="border border-gray-200 px-3 py-2 text-center font-bold">
                                {{ number_format($plan->qty_planned) }}</td>
                            <td class="border border-gray-200 px-3 py-2 text-center font-bold {{ $plan->actual_execution > 0 ? ($plan->actual_execution >= $plan->qty_planned ? 'text-emerald-600' : 'text-blue-600') : 'text-gray-400' }}">
                                {{ number_format($plan->actual_execution) }}</td>
                            <td class="border border-gray-200 px-3 py-2 text-center">
                                @if($plan->over_target > 0)
                                    <span class="text-[10px] font-bold text-emerald-800 bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-200">
                                        +{{ number_format($plan->over_target) }} Over
                                    </span>
                                @else
                                    <span class="font-bold text-gray-700">{{ number_format($plan->remaining_target) }}</span>
                                @endif
                            </td>
                            <td class="border border-gray-200 px-3 py-2 text-center">
                                @if($plan->execution_status === 'COMPLETED')
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-emerald-100 text-emerald-800 border border-emerald-300 flex items-center justify-center gap-1">
                                        <i class="fas fa-check-double text-[9px]"></i> Completed
                                    </span>
                                @elseif($plan->execution_status === 'IN_PROGRESS')
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-blue-100 text-blue-800 border border-blue-300 flex items-center justify-center gap-1">
                                        <i class="fas fa-sync-alt fa-spin text-[9px]"></i> In Progress
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-gray-100 text-gray-700 border border-gray-300 flex items-center justify-center gap-1">
                                        <i class="fas fa-clock text-[9px]"></i> Not Started
                                    </span>
                                @endif
                            </td>
                            <td class="border border-gray-200 px-3 py-2 font-bold text-blue-600 text-center text-lg">
                                {{ $plan->line_number }}</td>
                            <td class="border border-gray-200 px-3 py-2 text-center">
                                @if(auth()->user()->hasRole('ppic') && auth()->user()->product_scope && auth()->user()->product_scope === $plan->product_scope)
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
                                @else
                                    <span class="text-gray-300 text-xs italic">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="border border-gray-200 px-3 py-8 text-center text-gray-400 italic">Belum ada
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

        function applyPlanSearch(rawQuery) {
            const query = (rawQuery || '').trim().toLowerCase();
            const rows = document.querySelectorAll('.plan-row');
            const clearBtn = document.getElementById('planSearchClearBtn');
            const resetBtn = document.getElementById('planSearchResetBtn');
            const matchBadge = document.getElementById('planSearchMatchBadge');
            const matchCountEl = document.getElementById('planSearchMatchCount');
            const noMatchesRow = document.getElementById('planNoMatchesRow');
            const noMatchesQueryEl = document.getElementById('planNoMatchesQuery');

            if (!query) {
                rows.forEach(row => row.classList.remove('hidden'));
                if (clearBtn) clearBtn.classList.add('hidden');
                if (resetBtn) resetBtn.classList.add('hidden');
                if (matchBadge) {
                    matchBadge.classList.add('hidden');
                    matchBadge.classList.remove('inline-flex');
                }
                if (noMatchesRow) noMatchesRow.classList.add('hidden');
                return;
            }

            if (clearBtn) clearBtn.classList.remove('hidden');
            if (resetBtn) resetBtn.classList.remove('hidden');

            let matchCount = 0;
            rows.forEach(row => {
                const searchText = (row.getAttribute('data-search-text') || '').toLowerCase();
                if (searchText.includes(query)) {
                    row.classList.remove('hidden');
                    matchCount++;
                } else {
                    row.classList.add('hidden');
                }
            });

            if (matchBadge && matchCountEl) {
                matchCountEl.textContent = matchCount;
                matchBadge.classList.remove('hidden');
                matchBadge.classList.add('inline-flex');
            }

            if (noMatchesRow) {
                if (matchCount === 0 && rows.length > 0) {
                    if (noMatchesQueryEl) noMatchesQueryEl.textContent = rawQuery.trim();
                    noMatchesRow.classList.remove('hidden');
                } else {
                    noMatchesRow.classList.add('hidden');
                }
            }
        }

        function clearPlanSearch() {
            const input = document.getElementById('planSearchInput');
            if (input) {
                input.value = '';
                input.focus();
            }
            applyPlanSearch('');
        }

        document.addEventListener('DOMContentLoaded', function () {
            const searchInput = document.getElementById('planSearchInput');
            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    applyPlanSearch(this.value);
                });

                searchInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') {
                        clearPlanSearch();
                    }
                });
            }
        });
    </script>
@endsection