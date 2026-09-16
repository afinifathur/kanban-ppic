@extends('layouts.app')

@section('top_bar')
    <div class="flex flex-col sm:flex-row sm:items-center justify-between w-full gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-lg font-bold text-slate-800 leading-tight">Input Hasil Cor (Sand Casting)</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800 border border-amber-200">
                    Pencatatan Heat
                </span>
            </div>
            <p class="text-gray-500 text-[10px]">Pencatatan aktual penuangan leburan (Heat Number) dan alokasi item Perintah Cor</p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('sand-casting.casting-results.index') }}" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold px-3 py-2 rounded-lg text-xs flex items-center gap-1.5 shadow-sm transition">
                <i class="fas fa-arrow-left"></i> Kembali
            </a>
            <button type="button" onclick="submitHasilCorForm()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-2 rounded-lg text-xs flex items-center gap-1.5 shadow-sm transition">
                <i class="fas fa-save"></i> Simpan Hasil Cor
            </button>
        </div>
    </div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Error / Flash Alert -->
    @if(session('error'))
        <div class="bg-red-50 border border-red-200 text-red-800 text-sm px-4 py-3 rounded-lg flex items-center justify-between shadow-sm">
            <div class="flex items-center gap-2">
                <i class="fas fa-exclamation-circle text-red-500 text-base"></i>
                <span>{{ session('error') }}</span>
            </div>
            <button type="button" onclick="this.parentElement.remove()" class="text-red-400 hover:text-red-600 text-sm">&times;</button>
        </div>
    @endif

    @if($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-800 text-xs px-4 py-3 rounded-lg shadow-sm">
            <div class="font-bold flex items-center gap-1.5 mb-1">
                <i class="fas fa-exclamation-triangle text-red-500"></i> Periksa kembali data input:
            </div>
            <ul class="list-disc pl-5 space-y-0.5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form id="hasilCorForm" action="{{ route('sand-casting.casting-results.store') }}" method="POST">
        @csrf

        <!-- 1. Section: Data Heat & Penuangan -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6 mb-6">
            <h3 class="font-bold text-slate-800 text-sm flex items-center gap-2 mb-4 pb-3 border-b border-slate-100">
                <i class="fas fa-fire text-orange-600"></i> DATA HEAT &amp; PENUANGAN
            </h3>

            <div class="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                <!-- Heat Number -->
                <div class="sm:col-span-2">
                    <label for="heat_number" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">
                        HEAT NUMBER <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="heat_number" name="heat_number" value="{{ old('heat_number') }}" required placeholder="Contoh: A214092601"
                           class="w-full bg-slate-900 text-amber-300 placeholder-slate-500 font-mono font-bold text-sm px-3.5 py-2.5 rounded-lg border border-slate-700 focus:ring-2 focus:ring-amber-400 focus:outline-none tracking-wider">
                    <p class="text-[10px] text-slate-400 mt-1">Nomor Heat aktual dari lantai peleburan (tidak di-generate otomatis)</p>
                </div>

                <!-- Tanggal Cor -->
                <div>
                    <label for="cast_date" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">
                        Tanggal Cor <span class="text-red-500">*</span>
                    </label>
                    <input type="date" id="cast_date" name="cast_date" value="{{ old('cast_date', date('Y-m-d')) }}" required
                           class="w-full text-xs font-medium px-3 py-2.5 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                </div>

                <!-- Furnace -->
                <div>
                    <label for="furnace" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">
                        Furnace / Tungku
                    </label>
                    <input type="text" id="furnace" name="furnace" value="{{ old('furnace') }}" placeholder="F-01 / Induksi"
                           class="w-full text-xs px-3 py-2.5 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                </div>

                <!-- Shift -->
                <div>
                    <label for="shift" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">
                        Shift
                    </label>
                    <select id="shift" name="shift" class="w-full text-xs px-3 py-2.5 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 focus:outline-none bg-white">
                        <option value="1" {{ old('shift') == '1' ? 'selected' : '' }}>Shift 1 (Pagi)</option>
                        <option value="2" {{ old('shift') == '2' ? 'selected' : '' }}>Shift 2 (Siang)</option>
                        <option value="3" {{ old('shift') == '3' ? 'selected' : '' }}>Shift 3 (Malam)</option>
                    </select>
                </div>

                <!-- Operator -->
                <div>
                    <label for="operator_name" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">
                        Operator
                    </label>
                    <input type="text" id="operator_name" name="operator_name" value="{{ old('operator_name') }}" placeholder="Nama operator"
                           class="w-full text-xs px-3 py-2.5 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                </div>

                <!-- Catatan Penuangan -->
                <div class="sm:col-span-3 lg:col-span-6">
                    <label for="notes" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">
                        Catatan Penuangan
                    </label>
                    <input type="text" id="notes" name="notes" value="{{ old('notes') }}" placeholder="Catatan kondisi leburan, suhu pouring, atau anomali cetakan..."
                           class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                </div>
            </div>
        </div>

        <!-- 2. Section: Production Code Selector (Excel-like Fast Entry) -->
        <div class="bg-gradient-to-r from-slate-900 to-indigo-950 text-white rounded-xl shadow-md p-6 mb-6">
            <h3 class="font-bold text-sm text-amber-300 flex items-center gap-2 mb-3">
                <i class="fas fa-search-plus"></i> PENCARIAN &amp; ALOKASI PRODUCTION CODE KE HEAT
            </h3>
            <p class="text-xs text-slate-300 mb-4">Cari Production Code dari Perintah Cor (PCOR) yang aktif, masukkan hasil Good &amp; Reject, lalu klik <strong>Tambahkan ke Heat</strong>.</p>

            <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end bg-slate-800/80 p-4 rounded-lg border border-slate-700">
                <!-- Search Box -->
                <div class="md:col-span-4">
                    <label class="block text-[11px] font-bold text-slate-300 uppercase tracking-wider mb-1">
                        Production Code <span class="text-amber-400">*</span>
                    </label>
                    <div class="flex gap-2">
                        <input type="text" id="search_prod_code" placeholder="Ketik Kode (misal: 268ET001)..." list="availableCodesList"
                               class="w-full text-xs font-mono font-bold bg-white text-slate-900 px-3 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-amber-400 focus:outline-none"
                               onkeydown="if(event.key==='Enter'){ event.preventDefault(); lookupProductionCode(); }">
                        <datalist id="availableCodesList">
                            <!-- Injected dynamically -->
                        </datalist>
                        <button type="button" onclick="lookupProductionCode()" class="bg-amber-500 hover:bg-amber-600 text-slate-900 font-bold px-3 py-2 rounded-lg text-xs transition shrink-0">
                            <i class="fas fa-search"></i> Cari
                        </button>
                    </div>
                </div>

                <!-- Candidate Selector (If ambiguous or single matched) -->
                <div class="md:col-span-8" id="candidateContainer">
                    <div class="text-xs text-slate-400 italic py-2">
                        Masukkan kode produk dan klik Cari untuk melihat opsi Perintah Cor.
                    </div>
                </div>
            </div>

            <!-- Staging input controls if selected line -->
            <div id="stagingInputRow" class="hidden mt-4 pt-4 border-t border-slate-700 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-12 gap-3 items-end">
                <div class="md:col-span-6 space-y-1">
                    <div class="text-[11px] font-bold text-slate-300 uppercase tracking-wider">Item Terpilih:</div>
                    <div class="text-xs font-bold text-white truncate" id="selectedItemTitle">-</div>
                    <div class="text-[11px] font-mono text-amber-300 flex flex-wrap gap-x-2" id="selectedItemSubtitle">-</div>
                    <div class="text-[11px] text-slate-300">
                        <span class="text-slate-400 font-medium">Keterangan:</span> <span id="selectedItemKeterangan" class="font-semibold text-slate-200">-</span>
                    </div>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-[11px] font-bold text-emerald-400 uppercase tracking-wider mb-1">
                        Qty Good (Pcs) <span class="text-red-400">*</span>
                    </label>
                    <input type="number" id="stage_qty_good" min="0" placeholder="0"
                           class="w-full text-center text-xs font-mono font-bold bg-white text-emerald-800 px-2 py-2 rounded-lg border border-emerald-400 focus:ring-2 focus:ring-emerald-500 focus:outline-none"
                           onkeydown="if(event.key==='Enter'){ event.preventDefault(); addStagedLineToHeat(); }">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-[11px] font-bold text-red-400 uppercase tracking-wider mb-1">
                        Qty Reject (Pcs)
                    </label>
                    <input type="number" id="stage_qty_reject" min="0" placeholder="0"
                           class="w-full text-center text-xs font-mono font-bold bg-white text-red-700 px-2 py-2 rounded-lg border border-red-300 focus:ring-2 focus:ring-red-400 focus:outline-none"
                           onkeydown="if(event.key==='Enter'){ event.preventDefault(); addStagedLineToHeat(); }">
                </div>

                <div class="md:col-span-2 flex items-end">
                    <button type="button" onclick="addStagedLineToHeat()" class="w-full bg-emerald-500 hover:bg-emerald-600 text-slate-900 font-bold px-3 py-2 rounded-lg text-xs transition flex items-center justify-center gap-1.5 shadow">
                        <i class="fas fa-plus"></i> Tambahkan
                    </button>
                </div>
            </div>
        </div>

        <!-- 3. Section: Allocated Items in this Heat (Table) -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden mb-6">
            <div class="p-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <h3 class="font-bold text-slate-800 text-sm flex items-center gap-2">
                        <i class="fas fa-cubes text-indigo-600"></i> DAFTAR ITEM HASIL COR PADA HEAT INI
                    </h3>
                    <p class="text-slate-400 text-[11px]">Item-item dari Perintah Cor yang akan dimasukkan ke dalam Heat Number ini.</p>
                </div>
                <div class="text-xs text-slate-500 font-mono font-bold" id="stagedCountBadge">
                    0 Item dialokasikan
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-xs text-left" id="heatItemsTable">
                    <thead class="bg-slate-50 text-slate-600 font-bold uppercase tracking-wider border-b border-slate-200">
                        <tr>
                            <th class="p-3 text-center w-10">No</th>
                            <th class="p-3 text-left">No Perintah Cor</th>
                            <th class="p-3 text-left">Kode Prod</th>
                            <th class="p-3 text-left min-w-[200px]">Nama Produk</th>
                            <th class="p-3 text-left">Customer</th>
                            <th class="p-3 text-center w-24">Sisa Quota</th>
                            <th class="p-3 text-center w-28 font-bold text-emerald-700 bg-emerald-50/50">Qty Good (Pcs) <span class="text-red-500">*</span></th>
                            <th class="p-3 text-center w-24 font-bold text-red-600 bg-red-50/50">Qty Reject</th>
                            <th class="p-3 text-center w-28">Subtotal Berat (Kg)</th>
                            <th class="p-3 text-left min-w-[150px]">Keterangan</th>
                            <th class="p-3 text-center w-16">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700" id="heatItemsTbody">
                        <tr id="emptyTablePlaceholder">
                            <td colspan="11" class="p-8 text-center text-slate-400">
                                <i class="fas fa-arrow-up mr-1 text-indigo-400"></i> Belum ada item yang ditambahkan ke Heat ini. Gunakan pencarian di atas untuk menambahkan item.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Footer Summary Preview -->
            <div class="bg-slate-900 text-white p-5 border-t border-slate-800">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-center">
                    <div>
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Item Cor</div>
                        <div class="text-xl font-mono font-bold text-white mt-0.5" id="summaryActiveItems">0 item</div>
                    </div>
                    <div>
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Good</div>
                        <div class="text-xl font-mono font-bold text-emerald-400 mt-0.5" id="summaryTotalGood">0 pcs</div>
                    </div>
                    <div>
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Reject</div>
                        <div class="text-xl font-mono font-bold text-red-400 mt-0.5" id="summaryTotalReject">0 pcs</div>
                    </div>
                    <div>
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Estimasi Berat</div>
                        <div class="text-xl font-mono font-bold text-amber-300 mt-0.5" id="summaryTotalWeight">0.00 kg</div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
    // Master available lines dataset serialized from backend
    const AVAILABLE_LINES = @json($availableLines ?? []);
    let stagedItems = [];
    let selectedLineData = null;

    // Helper to resolve remaining quota
    function getRemainingQuota(line) {
        if (typeof line.qty_remaining_to_cast === 'number') {
            return line.qty_remaining_to_cast;
        }
        const ordered = parseInt(line.qty_ordered) || 0;
        const good = parseInt(line.qty_cast_good) || 0;
        return Math.max(0, ordered - good);
    }

    // Helper to resolve automatic line description
    function getAutoKeterangan(line) {
        return line.notes || (line.production_plan ? line.production_plan.title : '') || '-';
    }

    // Populate Datalist
    document.addEventListener('DOMContentLoaded', () => {
        const datalist = document.getElementById('availableCodesList');
        const uniqueCodes = [...new Set(AVAILABLE_LINES.map(l => l.code).filter(Boolean))].sort();
        uniqueCodes.forEach(code => {
            const opt = document.createElement('option');
            opt.value = code;
            datalist.appendChild(opt);
        });

        // If preselected order was provided, we can optionally pre-filter
        const preselectedOrderId = {{ $preselectedOrderId ? $preselectedOrderId : 'null' }};
        if (preselectedOrderId) {
            const orderLines = AVAILABLE_LINES.filter(l => l.sand_casting_casting_order_id === preselectedOrderId);
            if (orderLines.length > 0) {
                orderLines.forEach(l => {
                    const rem = getRemainingQuota(l);
                    stagedItems.push({
                        lineId: l.id,
                        orderNumber: l.casting_order ? l.casting_order.casting_order_number : '-',
                        code: l.code,
                        itemName: l.item_name,
                        sizeAisi: (l.size || '') + (l.aisi ? ' (' + l.aisi + ')' : ''),
                        customer: l.customer || '-',
                        ordered: l.qty_ordered || 0,
                        remaining: rem,
                        unitWeight: l.production_plan ? (parseFloat(l.production_plan.weight) || 0) : 0,
                        qtyGood: rem,
                        qtyReject: 0,
                        notes: getAutoKeterangan(l)
                    });
                });
                renderStagedTable();
            }
        }
    });

    function lookupProductionCode() {
        const query = document.getElementById('search_prod_code').value.trim().toUpperCase();
        const container = document.getElementById('candidateContainer');
        const stagingRow = document.getElementById('stagingInputRow');

        if (!query) {
            container.innerHTML = '<div class="text-xs text-amber-300 font-semibold py-2">Silakan ketik kode produk terlebih dahulu.</div>';
            stagingRow.classList.add('hidden');
            return;
        }

        // Find available lines matching code
        const matches = AVAILABLE_LINES.filter(l => l.code && l.code.toUpperCase() === query);

        if (matches.length === 0) {
            container.innerHTML = `<div class="text-xs text-red-300 font-semibold py-2">Tidak ditemukan Perintah Cor aktif dengan kode <strong>${query}</strong> (atau sisa kuota cor sudah habis).</div>`;
            stagingRow.classList.add('hidden');
            selectedLineData = null;
            return;
        }

        if (matches.length === 1) {
            // Exactly 1 line found
            selectCandidateLine(matches[0]);
            const rem = getRemainingQuota(matches[0]);
            container.innerHTML = `
                <div class="bg-indigo-900/60 border border-indigo-500/40 p-2.5 rounded-lg flex items-center justify-between">
                    <div>
                        <span class="text-xs font-bold text-white">${matches[0].code}</span> &bull;
                        <span class="text-xs text-amber-300 font-mono">${matches[0].casting_order ? matches[0].casting_order.casting_order_number : '-'}</span> &bull;
                        <span class="text-xs text-slate-300">Sisa: <strong>${rem} pcs</strong></span>
                    </div>
                    <span class="text-[10px] bg-emerald-500/20 text-emerald-300 font-bold px-2 py-0.5 rounded border border-emerald-500/30">Otomatis Terpilih</span>
                </div>
            `;
        } else {
            // Ambiguous: Multiple PCOR lines exist for this Production Code
            let html = `
                <div class="space-y-2">
                    <div class="text-xs font-bold text-amber-300 flex items-center gap-1.5">
                        <i class="fas fa-exclamation-circle"></i> Ditemukan ${matches.length} Perintah Cor untuk kode ${query}. Silakan pilih dokumen PCOR target:
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            `;

            matches.forEach(m => {
                const orderNum = m.casting_order ? m.casting_order.casting_order_number : '-';
                const rem = getRemainingQuota(m);
                html += `
                    <button type="button" onclick='selectCandidateLineById(${m.id})'
                            class="text-left bg-slate-900/90 hover:bg-indigo-900 border border-slate-600 hover:border-amber-400 p-2.5 rounded-lg transition group">
                        <div class="flex items-center justify-between">
                            <span class="font-mono font-bold text-amber-300 text-xs">${orderNum}</span>
                            <span class="text-[10px] text-emerald-400 font-bold bg-emerald-950 px-1.5 py-0.5 rounded">Sisa: ${rem} pcs</span>
                        </div>
                        <div class="text-[11px] text-slate-300 truncate mt-1">${m.item_name}</div>
                        <div class="text-[10px] text-slate-400">Order: ${m.qty_ordered} pcs &bull; Customer: ${m.customer || '-'}</div>
                    </button>
                `;
            });

            html += `</div></div>`;
            container.innerHTML = html;
            stagingRow.classList.add('hidden');
            selectedLineData = null;
        }
    }

    function selectCandidateLineById(lineId) {
        const line = AVAILABLE_LINES.find(l => l.id === lineId);
        if (line) {
            selectCandidateLine(line);
            const container = document.getElementById('candidateContainer');
            const orderNum = line.casting_order ? line.casting_order.casting_order_number : '-';
            const rem = getRemainingQuota(line);
            container.innerHTML = `
                <div class="bg-indigo-900/60 border border-indigo-500/40 p-2.5 rounded-lg flex items-center justify-between">
                    <div>
                        <span class="text-xs font-bold text-white">${line.code}</span> &bull;
                        <span class="text-xs text-amber-300 font-mono">${orderNum}</span> &bull;
                        <span class="text-xs text-slate-300">Sisa: <strong>${rem} pcs</strong></span>
                    </div>
                    <button type="button" onclick="lookupProductionCode()" class="text-[10px] bg-slate-700 hover:bg-slate-600 text-slate-200 font-bold px-2 py-0.5 rounded">Ganti Pilihan</button>
                </div>
            `;
        }
    }

    function selectCandidateLine(line) {
        selectedLineData = line;
        const stagingRow = document.getElementById('stagingInputRow');
        const orderNum = line.casting_order ? line.casting_order.casting_order_number : '-';
        const remainingQuota = getRemainingQuota(line);
        const autoKeterangan = getAutoKeterangan(line);

        document.getElementById('selectedItemTitle').innerText = `${line.code} - ${line.item_name}`;
        document.getElementById('selectedItemSubtitle').innerText = `${orderNum} | Sisa Kuota: ${remainingQuota} pcs | Cust: ${line.customer || '-'}`;
        document.getElementById('selectedItemKeterangan').innerText = autoKeterangan;

        const goodInput = document.getElementById('stage_qty_good');
        goodInput.value = remainingQuota;
        goodInput.max = remainingQuota;
        document.getElementById('stage_qty_reject').value = 0;

        stagingRow.classList.remove('hidden');
        goodInput.focus();
        goodInput.select();
    }

    function addStagedLineToHeat() {
        if (!selectedLineData) {
            alert('Pilih item terlebih dahulu.');
            return;
        }

        const remainingQuota = getRemainingQuota(selectedLineData);
        const good = parseInt(document.getElementById('stage_qty_good').value) || 0;
        const reject = parseInt(document.getElementById('stage_qty_reject').value) || 0;

        if (good < 0 || reject < 0) {
            alert('Kuantitas tidak boleh bernilai negatif.');
            return;
        }

        if (good === 0 && reject === 0) {
            alert('Masukkan kuantitas Good atau Reject minimal 1 pcs.');
            return;
        }

        if (good > remainingQuota) {
            alert(`Kuantitas Good (${good} pcs) melebihi sisa kuota perintah cor (${remainingQuota} pcs).`);
            return;
        }

        // Duplicate line protection: check if exact PCOR line already staged
        const isAlreadyStaged = stagedItems.some(item => item.lineId === selectedLineData.id);
        if (isAlreadyStaged) {
            alert(`Item dari Perintah Cor ini (${selectedLineData.code}) sudah ditambahkan ke dalam Heat. Silakan ubah kuantitas langsung pada tabel di bawah.`);
            return;
        }

        const autoKeterangan = getAutoKeterangan(selectedLineData);

        stagedItems.push({
            lineId: selectedLineData.id,
            orderNumber: selectedLineData.casting_order ? selectedLineData.casting_order.casting_order_number : '-',
            code: selectedLineData.code,
            itemName: selectedLineData.item_name,
            sizeAisi: (selectedLineData.size || '') + (selectedLineData.aisi ? ' (' + selectedLineData.aisi + ')' : ''),
            customer: selectedLineData.customer || '-',
            ordered: selectedLineData.qty_ordered || 0,
            remaining: remainingQuota,
            unitWeight: selectedLineData.production_plan ? (parseFloat(selectedLineData.production_plan.weight) || 0) : 0,
            qtyGood: good,
            qtyReject: reject,
            notes: autoKeterangan
        });

        renderStagedTable();

        // Reset search and temporary staging input area only (preserving all staged lines)
        document.getElementById('search_prod_code').value = '';
        document.getElementById('candidateContainer').innerHTML = '<div class="text-xs text-emerald-300 font-semibold py-2"><i class="fas fa-check"></i> Item berhasil ditambahkan ke Heat. Silakan cari Production Code berikutnya.</div>';
        document.getElementById('stagingInputRow').classList.add('hidden');
        selectedLineData = null;
        document.getElementById('search_prod_code').focus();
    }

    function removeStagedItem(index) {
        stagedItems.splice(index, 1);
        renderStagedTable();
    }

    function updateStagedItem(index, field, value) {
        if (!stagedItems[index]) return;

        if (field === 'qtyGood') {
            let val = parseInt(value) || 0;
            if (val < 0) val = 0;
            if (val > stagedItems[index].remaining) {
                alert(`Kuantitas Good melebihi sisa kuota perintah (${stagedItems[index].remaining} pcs).`);
                val = stagedItems[index].remaining;
            }
            stagedItems[index].qtyGood = val;
        } else if (field === 'qtyReject') {
            let val = parseInt(value) || 0;
            if (val < 0) val = 0;
            stagedItems[index].qtyReject = val;
        }

        renderStagedTable(false);
    }

    function renderStagedTable(rebuildHtml = true) {
        const tbody = document.getElementById('heatItemsTbody');
        const countBadge = document.getElementById('stagedCountBadge');

        countBadge.innerText = `${stagedItems.length} Item dialokasikan`;

        if (stagedItems.length === 0) {
            tbody.innerHTML = `
                <tr id="emptyTablePlaceholder">
                    <td colspan="11" class="p-8 text-center text-slate-400">
                        <i class="fas fa-arrow-up mr-1 text-indigo-400"></i> Belum ada item yang ditambahkan ke Heat ini. Gunakan pencarian di atas untuk menambahkan item.
                    </td>
                </tr>
            `;
            calculateTotals();
            return;
        }

        if (rebuildHtml) {
            let html = '';
            stagedItems.forEach((item, index) => {
                const subtotalWeight = ((parseInt(item.qtyGood) || 0) * (parseFloat(item.unitWeight) || 0)).toFixed(2);
                html += `
                    <tr class="hover:bg-slate-50/80 transition" id="row_${index}">
                        <td class="p-3 text-center font-mono text-slate-400">
                            ${index + 1}
                            <input type="hidden" name="items[${index}][sand_casting_casting_order_line_id]" value="${item.lineId}">
                            <input type="hidden" name="items[${index}][unit_weight_kg]" value="${item.unitWeight}">
                            <input type="hidden" name="items[${index}][total_weight_kg]" value="${subtotalWeight}">
                            <input type="hidden" name="items[${index}][notes]" value="${item.notes}">
                        </td>
                        <td class="p-3 font-mono font-semibold text-slate-600 text-xs">
                            ${item.orderNumber}
                        </td>
                        <td class="p-3 font-mono font-bold text-blue-700 text-xs">
                            ${item.code}
                        </td>
                        <td class="p-3">
                            <div class="font-semibold text-slate-800">${item.itemName}</div>
                            ${item.sizeAisi ? `<div class="text-[10px] text-slate-400 font-mono">${item.sizeAisi}</div>` : ''}
                        </td>
                        <td class="p-3 text-slate-600 font-medium">
                            ${item.customer}
                        </td>
                        <td class="p-3 text-center font-mono font-bold text-indigo-700 text-xs">
                            ${(item.remaining || 0).toLocaleString()}
                        </td>
                        <td class="p-2 bg-emerald-50/30">
                            <input type="number" name="items[${index}][qty_good]" value="${item.qtyGood}" min="0" max="${item.remaining}"
                                   class="w-full text-center font-mono font-bold text-emerald-800 bg-white border border-emerald-300 rounded-lg px-2 py-1.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:outline-none"
                                   onchange="updateStagedItem(${index}, 'qtyGood', this.value)">
                        </td>
                        <td class="p-2 bg-red-50/30">
                            <input type="number" name="items[${index}][qty_reject]" value="${item.qtyReject}" min="0"
                                   class="w-full text-center font-mono font-bold text-red-700 bg-white border border-red-200 rounded-lg px-2 py-1.5 text-sm focus:ring-2 focus:ring-red-400 focus:outline-none"
                                   onchange="updateStagedItem(${index}, 'qtyReject', this.value)">
                        </td>
                        <td class="p-3 text-center font-mono font-bold text-slate-800" id="subtotalWeight_${index}">
                            ${subtotalWeight} kg
                        </td>
                        <td class="p-3 text-xs text-slate-700">
                            <div class="truncate max-w-[200px]" title="${item.notes}">${item.notes}</div>
                        </td>
                        <td class="p-3 text-center">
                            <button type="button" onclick="removeStagedItem(${index})" class="text-red-500 hover:text-red-700 font-bold text-sm transition" title="Hapus dari Heat">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        } else {
            stagedItems.forEach((item, index) => {
                const subtotalWeight = ((parseInt(item.qtyGood) || 0) * (parseFloat(item.unitWeight) || 0)).toFixed(2);
                const elWeight = document.getElementById(`subtotalWeight_${index}`);
                if (elWeight) elWeight.innerText = `${subtotalWeight} kg`;
            });
        }

        calculateTotals();
    }

    function calculateTotals() {
        let totalGood = 0;
        let totalReject = 0;
        let totalWeight = 0;

        stagedItems.forEach(item => {
            totalGood += (parseInt(item.qtyGood) || 0);
            totalReject += (parseInt(item.qtyReject) || 0);
            totalWeight += ((parseInt(item.qtyGood) || 0) * (parseFloat(item.unitWeight) || 0));
        });

        document.getElementById('summaryActiveItems').innerText = `${stagedItems.length} item`;
        document.getElementById('summaryTotalGood').innerText = `${totalGood.toLocaleString()} pcs`;
        document.getElementById('summaryTotalReject').innerText = `${totalReject.toLocaleString()} pcs`;
        document.getElementById('summaryTotalWeight').innerText = `${totalWeight.toFixed(2)} kg`;
    }

    function submitHasilCorForm() {
        const heatNumber = document.getElementById('heat_number').value.trim();
        if (!heatNumber) {
            alert('Nomor Heat wajib diisi.');
            document.getElementById('heat_number').focus();
            return;
        }

        if (stagedItems.length === 0) {
            alert('Minimal satu item Perintah Cor harus ditambahkan ke dalam Heat.');
            return;
        }

        const totalGood = stagedItems.reduce((sum, item) => sum + (parseInt(item.qtyGood) || 0), 0);
        const totalReject = stagedItems.reduce((sum, item) => sum + (parseInt(item.qtyReject) || 0), 0);

        if (totalGood === 0 && totalReject === 0) {
            alert('Total kuantitas hasil cor (Good atau Reject) tidak boleh 0.');
            return;
        }

        document.getElementById('hasilCorForm').submit();
    }
</script>
@endsection
