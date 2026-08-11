@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Generate Tree</h1>
            <p class="text-gray-500 text-[10px]">Work Order {{ $workOrder->et_code }} &mdash; Wave {{ str_pad((string) $plan->wave_number, 3, '0', STR_PAD_LEFT) }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('lost-wax.work-orders.show', $workOrder) }}" class="text-slate-500 hover:text-slate-700 text-xs">
                <i class="fas fa-arrow-left"></i> Kembali ke Work Order
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="space-y-6 max-w-4xl">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Assembly Output</div>
                <div class="font-bold text-slate-800 text-lg">{{ number_format($availableQty) }} pcs</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Default Qty / Tree</div>
                <div class="font-bold text-slate-800 text-lg">{{ $defaultQtyPerTree }} pcs</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Proposed Trees</div>
                <div class="font-bold text-slate-800 text-lg">{{ $proposedCount }}</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Item</div>
                <div class="font-bold text-slate-800 text-sm">{{ optional($workOrder->itemReference)->item_code_snapshot ?? '-' }}</div>
                <div class="text-xs text-slate-500">{{ optional($workOrder->itemReference)->item_name_snapshot ?? '-' }}</div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <h2 class="font-bold text-slate-800 mb-4">Rincian Tree</h2>

            @if($remaining > 0)
                <div class="mb-4 bg-amber-50 border border-amber-200 text-amber-700 px-4 py-3 rounded-lg text-sm">
                    <i class="fas fa-info-circle mr-2"></i>
                    Sisa quantity: <strong>{{ number_format($remaining) }} pcs</strong> belum teralokasi. Anda dapat menambah Tree manual atau menaikkan qty pada salah satu Tree.
                </div>
            @endif

            <form method="POST" action="{{ route('lost-wax.trees.store', $plan) }}" id="generateForm">
                @csrf
                <input type="hidden" name="default_qty" id="defaultQtyInput" value="{{ $defaultQtyPerTree }}">
                <input type="hidden" name="family_code" value="{{ $familyCode }}">

                <div class="flex items-end gap-3 mb-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Default Qty/Tree</label>
                        <input type="number" id="defaultQty" value="{{ $defaultQtyPerTree }}" min="1"
                            class="w-32 rounded-lg border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Family Code</label>
                        <select name="family_code_display" id="familyDisplay" class="w-64 rounded-lg border-slate-300 text-sm" {{ $familyCode ? '' : 'required' }}>
                            <option value="">-- Pilih Family --</option>
                            @foreach($families as $code => $label)
                                <option value="{{ $code }}" {{ $familyCode == $code ? 'selected' : '' }}>{{ $code }} - {{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <button type="button" id="recalculateBtn"
                            class="bg-slate-600 hover:bg-slate-700 text-white text-sm font-bold py-2 px-4 rounded-lg">
                            <i class="fas fa-calculator"></i> Hitung Ulang
                        </button>
                    </div>
                    <div>
                        <button type="button" id="addTreeBtn"
                            class="bg-amber-100 hover:bg-amber-200 text-amber-800 text-sm font-bold py-2 px-4 rounded-lg border border-amber-300">
                            <i class="fas fa-plus"></i> Tambah Tree Manual
                        </button>
                    </div>
                </div>

                <div id="treesContainer" class="space-y-2 mb-6">
                    @foreach($proposed as $i => $qty)
                        <div class="tree-row flex items-center gap-3 p-3 rounded-lg border border-slate-200 bg-slate-50">
                            <div class="w-24 text-sm font-semibold text-slate-700">Tree #{{ str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT) }}</div>
                            <div class="flex-1">
                                <input type="number" name="quantities[]" value="{{ $qty }}" min="1"
                                    class="tree-qty w-24 rounded-lg border-slate-300 text-sm">
                            </div>
                            <div class="text-xs text-slate-400">pcs</div>
                            <button type="button" class="remove-tree-btn text-red-400 hover:text-red-600 text-sm" {{ count($proposed) <= 1 ? 'disabled style=opacity:0.3' : '' }}>
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    @endforeach
                </div>

                <div id="treeSummary" class="text-sm text-slate-600 mb-4">
                    Total: <strong id="totalQty">{{ number_format($proposedTotal) }}</strong> pcs
                    &mdash; <span id="treeCount">{{ $proposedCount }}</span> Tree
                    &mdash; Max tersedia: <strong>{{ number_format($availableQty) }}</strong> pcs
                </div>

                <div class="flex justify-end gap-3 border-t border-slate-200 pt-4">
                    <a href="{{ route('lost-wax.work-orders.show', $workOrder) }}" class="bg-slate-200 hover:bg-slate-300 text-slate-700 text-sm font-bold py-2 px-4 rounded-lg">Batal</a>
                    <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white text-sm font-bold py-2 px-6 rounded-lg">
                        <i class="fas fa-sitemap"></i> Generate Tree
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const container = document.getElementById('treesContainer');
            const totalQtyEl = document.getElementById('totalQty');
            const treeCountEl = document.getElementById('treeCount');
            const defaultQtyEl = document.getElementById('defaultQty');
            const defaultQtyInput = document.getElementById('defaultQtyInput');
            const familyDisplay = document.getElementById('familyDisplay');
            const familyHidden = document.querySelector('input[name="family_code"]');
            const maxAvailable = {{ $availableQty }};

            familyDisplay.addEventListener('change', function () {
                familyHidden.value = this.value;
            });

            function updateSummary() {
                let total = 0;
                let count = 0;
                document.querySelectorAll('.tree-qty').forEach(function (input) {
                    total += parseInt(input.value) || 0;
                    count++;
                });
                totalQtyEl.textContent = new Intl.NumberFormat().format(total);
                treeCountEl.textContent = count;

                if (total > maxAvailable) {
                    totalQtyEl.classList.add('text-red-600');
                } else {
                    totalQtyEl.classList.remove('text-red-600');
                }

                document.querySelectorAll('.remove-tree-btn').forEach(function (btn, i) {
                    if (count <= 1) {
                        btn.disabled = true;
                        btn.style.opacity = '0.3';
                    } else {
                        btn.disabled = false;
                        btn.style.opacity = '1';
                    }
                });
            }

            container.addEventListener('input', function (e) {
                if (e.target.classList.contains('tree-qty')) {
                    updateSummary();
                }
            });

            container.addEventListener('click', function (e) {
                if (e.target.closest('.remove-tree-btn') && !e.target.closest('.remove-tree-btn').disabled) {
                    e.target.closest('.tree-row').remove();
                    updateSummary();
                }
            });

            document.getElementById('recalculateBtn').addEventListener('click', function () {
                const defaultQty = parseInt(defaultQtyEl.value) || 15;
                defaultQtyInput.value = defaultQty;
                recalculateTrees(defaultQty);
            });

            document.getElementById('addTreeBtn').addEventListener('click', function () {
                const defaultQty = parseInt(defaultQtyEl.value) || 15;
                const index = container.querySelectorAll('.tree-row').length;
                addTreeRow(index + 1, defaultQty);
                updateSummary();
            });

            function addTreeRow(index, qty) {
                const row = document.createElement('div');
                row.className = 'tree-row flex items-center gap-3 p-3 rounded-lg border border-slate-200 bg-slate-50';
                row.innerHTML = `
                    <div class="w-24 text-sm font-semibold text-slate-700">Tree #${String(index).padStart(3, '0')}</div>
                    <div class="flex-1">
                        <input type="number" name="quantities[]" value="${qty}" min="1"
                            class="tree-qty w-24 rounded-lg border-slate-300 text-sm">
                    </div>
                    <div class="text-xs text-slate-400">pcs</div>
                    <button type="button" class="remove-tree-btn text-red-400 hover:text-red-600 text-sm">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                container.appendChild(row);
            }

            function recalculateTrees(defaultQty) {
                const totalQty = maxAvailable;
                if (totalQty <= 0 || defaultQty <= 0) return;

                const fullTrees = Math.floor(totalQty / defaultQty);
                const remainder = totalQty % defaultQty;

                container.innerHTML = '';

                for (let i = 0; i < fullTrees; i++) {
                    addTreeRow(i + 1, defaultQty);
                }

                if (remainder > 0) {
                    addTreeRow(fullTrees + 1, remainder);
                }

                if (fullTrees === 0 && remainder === 0) {
                    addTreeRow(1, 0);
                }

                updateSummary();
            }
        });
    </script>
@endsection
