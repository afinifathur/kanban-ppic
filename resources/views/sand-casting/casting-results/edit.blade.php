@extends('layouts.app')

@section('top_bar')
    <div class="flex flex-col sm:flex-row sm:items-center justify-between w-full gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-lg font-bold text-slate-800 leading-tight">Koreksi Hasil Cor (Sand Casting)</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-mono font-bold bg-amber-100 text-amber-900 border border-amber-300 shadow-sm">
                    HEAT: {{ $castingResult->heat_number }}
                </span>
            </div>
            <p class="text-gray-500 text-[10px]">Formulir koreksi data Hasil Cor dan manajemen integritas identitas fisik KTR</p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('sand-casting.casting-results.show', $castingResult) }}" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold px-3 py-2 rounded-lg text-xs flex items-center gap-1.5 shadow-sm transition">
                <i class="fas fa-arrow-left"></i> Batal &amp; Kembali
            </a>
            <button type="submit" form="koreksiHasilCorForm" class="bg-amber-600 hover:bg-amber-700 text-white font-bold px-4 py-2 rounded-lg text-xs flex items-center gap-1.5 shadow-sm transition">
                <i class="fas fa-save"></i> Simpan Koreksi Hasil Cor
            </button>
        </div>
    </div>
@endsection

@section('content')
    @php
        $hasAnyExecution = $castingResult->hasPhysicalExecution();
        $isAnyPrinted = $castingResult->lines->contains(fn($l) => ($l->print_count ?? 0) > 0 || $l->printed_at !== null);
    @endphp

    <!-- State Indicator Banner -->
    @if($hasAnyExecution)
        <div class="bg-red-50 border-2 border-red-300 text-red-950 p-4 rounded-xl shadow-sm flex items-start gap-3 mb-6">
            <div class="w-8 h-8 rounded-lg bg-red-100 text-red-700 flex items-center justify-center shrink-0 mt-0.5 text-base font-bold">
                <i class="fas fa-lock"></i>
            </div>
            <div>
                <div class="font-black text-xs uppercase tracking-wider text-red-900 flex items-center gap-2">
                    MODE: CONTROLLED CORRECTION (POST-PHYSICAL GATE)
                </div>
                <p class="text-xs text-red-800 mt-1 leading-relaxed">
                    Sebagian atau seluruh KTR pada Heat ini <strong>sudah memiliki riwayat eksekusi fisik (Gate Netto)</strong> di lantai produksi.
                    Sesuai prinsip integritas keterlacakan fisik, <strong>Nomor Heat, Identitas Produk/PCOR, dan Kuantitas Good (Baseline) TERKUNCI PERMANEN</strong>.
                    Perubahan administratif (Tanggal Cor, Furnace, Shift, Operator, Qty Reject, Berat Satuan) wajib menyertakan alasan koreksi.
                </p>
            </div>
        </div>
    @elseif($isAnyPrinted)
        <div class="bg-amber-50 border-2 border-amber-300 text-amber-950 p-4 rounded-xl shadow-sm flex items-start gap-3 mb-6">
            <div class="w-8 h-8 rounded-lg bg-amber-100 text-amber-800 flex items-center justify-center shrink-0 mt-0.5 text-base font-bold">
                <i class="fas fa-print"></i>
            </div>
            <div>
                <div class="font-black text-xs uppercase tracking-wider text-amber-900 flex items-center gap-2">
                    MODE: EDIT + REPRINT WARNING (KITIR SUDAH DICETAK)
                </div>
                <p class="text-xs text-amber-800 mt-1 leading-relaxed">
                    KTR belum memiliki riwayat eksekusi fisik, namun <strong>lembar Kitir fisik sudah pernah dicetak</strong>.
                    Jika Anda mengoreksi Heat Number, Kode Produksi, PCOR, Qty Good, atau Tanggal Cor, sistem akan otomatis menandai status <strong>PERLU CETAK ULANG</strong> agar Kitir lama segera ditarik dan diganti dengan versi terbaru.
                </p>
            </div>
        </div>
    @else
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-950 p-4 rounded-xl shadow-sm flex items-start gap-3 mb-6">
            <div class="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-800 flex items-center justify-center shrink-0 mt-0.5 text-base font-bold">
                <i class="fas fa-pen-to-square"></i>
            </div>
            <div>
                <div class="font-black text-xs uppercase tracking-wider text-emerald-900 flex items-center gap-2">
                    MODE: NORMAL EDIT (PRE-PHYSICAL GATE)
                </div>
                <p class="text-xs text-emerald-800 mt-1 leading-relaxed">
                    KTR belum pernah dicetak dan belum memiliki riwayat eksekusi fisik. Semua parameter penuangan dan alokasi produk masih bebas dikoreksi dengan validasi anti-duplikasi standar.
                </p>
            </div>
        </div>
    @endif

    <!-- Duplicate Alert Banner -->
    @if(session('duplicate_error'))
        <div class="bg-amber-50 border border-amber-300 text-amber-900 text-xs px-4 py-3 rounded-lg shadow-sm flex items-start gap-2.5 mb-6">
            <i class="fas fa-exclamation-triangle text-amber-600 text-base shrink-0 mt-0.5"></i>
            <div class="space-y-1">
                <div class="font-bold text-amber-900 uppercase">⚠️ HASIL COR SUDAH TERCATAT (DUPLIKASI DITOLAK)</div>
                <p>{{ session('duplicate_error') }}</p>
            </div>
            <button type="button" onclick="this.parentElement.remove()" class="text-amber-500 hover:text-amber-700 ml-auto text-sm">&times;</button>
        </div>
    @endif

    <!-- Error Alert -->
    @if(session('error'))
        <div class="bg-red-50 border border-red-200 text-red-800 text-sm px-4 py-3 rounded-lg flex items-center justify-between shadow-sm mb-6">
            <div class="flex items-center gap-2">
                <i class="fas fa-exclamation-circle text-red-500 text-base"></i>
                <span>{{ session('error') }}</span>
            </div>
            <button type="button" onclick="this.parentElement.remove()" class="text-red-400 hover:text-red-600 text-sm">&times;</button>
        </div>
    @endif

    @if($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-800 text-xs px-4 py-3 rounded-lg shadow-sm mb-6">
            <div class="font-bold flex items-center gap-1.5 mb-1">
                <i class="fas fa-exclamation-triangle text-red-500"></i> Periksa kembali data input koreksi:
            </div>
            <ul class="list-disc pl-5 space-y-0.5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form id="koreksiHasilCorForm" action="{{ route('sand-casting.casting-results.update', $castingResult) }}" method="POST">
        @csrf
        @method('PUT')

        <!-- 1. Section: Data Heat & Penuangan -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6 mb-6">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
                <h3 class="font-bold text-slate-800 text-sm flex items-center gap-2">
                    <i class="fas fa-fire text-orange-600"></i> HEADER PENUANGAN &amp; METALLURGICAL HEAT
                </h3>
                @if($hasAnyExecution)
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-red-100 text-red-800 border border-red-200">
                        <i class="fas fa-lock text-[9px]"></i> HEAT LOCKED (POST-GATE)
                    </span>
                @else
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                        <i class="fas fa-unlock text-[9px]"></i> EDITABLE
                    </span>
                @endif
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                <!-- Heat Number -->
                <div class="sm:col-span-2">
                    <label for="heat_number" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1 flex items-center justify-between">
                        <span>HEAT NUMBER <span class="text-red-500">*</span></span>
                        @if($hasAnyExecution)
                            <span class="text-[9px] text-red-600 font-normal">Terkunci (Sudah Eksekusi)</span>
                        @endif
                    </label>
                    @if($hasAnyExecution)
                        <input type="text" value="{{ $castingResult->heat_number }}" readonly disabled
                               class="w-full bg-slate-100 text-slate-500 cursor-not-allowed font-mono font-bold text-sm px-3.5 py-2.5 rounded-lg border border-slate-300 tracking-wider">
                        <input type="hidden" name="heat_number" value="{{ $castingResult->heat_number }}">
                    @else
                        <input type="text" id="heat_number" name="heat_number" value="{{ old('heat_number', $castingResult->heat_number) }}" required
                               class="w-full bg-slate-900 text-amber-300 placeholder-slate-500 font-mono font-bold text-sm px-3.5 py-2.5 rounded-lg border border-slate-700 focus:ring-2 focus:ring-amber-400 focus:outline-none tracking-wider">
                    @endif
                    <p class="text-[10px] text-slate-400 mt-1">Nomor wadah/leburan metalurgi</p>
                </div>

                <!-- Tanggal Cor -->
                <div>
                    <label for="cast_date" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">
                        Tanggal Cor <span class="text-red-500">*</span>
                    </label>
                    <input type="date" id="cast_date" name="cast_date" value="{{ old('cast_date', $castingResult->cast_date ? $castingResult->cast_date->format('Y-m-d') : '') }}" required
                           class="w-full text-xs font-medium px-3 py-2.5 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    <p class="text-[10px] text-slate-400 mt-1">Mengubah tanggal laporan &amp; aging</p>
                </div>

                <!-- Furnace -->
                <div>
                    <label for="furnace" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">
                        Furnace / Tungku
                    </label>
                    <input type="text" id="furnace" name="furnace" value="{{ old('furnace', $castingResult->furnace) }}" placeholder="F-01 / Induksi"
                           class="w-full text-xs px-3 py-2.5 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                </div>

                <!-- Shift -->
                <div>
                    <label for="shift" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">
                        Shift Kerja
                    </label>
                    <input type="text" id="shift" name="shift" value="{{ old('shift', $castingResult->shift) }}" placeholder="1 / 2 / 3"
                           class="w-full text-xs px-3 py-2.5 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                </div>

                <!-- Operator -->
                <div>
                    <label for="operator_name" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">
                        Operator Penuangan
                    </label>
                    <input type="text" id="operator_name" name="operator_name" value="{{ old('operator_name', $castingResult->operator_name) }}" placeholder="Nama Operator"
                           class="w-full text-xs px-3 py-2.5 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                </div>

                <!-- Catatan Header -->
                <div class="sm:col-span-3 lg:col-span-4">
                    <label for="notes" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">
                        Catatan Penuangan
                    </label>
                    <input type="text" id="notes" name="notes" value="{{ old('notes', $castingResult->notes) }}" placeholder="Catatan teknis penuangan"
                           class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                </div>

                <!-- Alasan Koreksi (Wajib Jika Post-Gate) -->
                <div class="sm:col-span-3 lg:col-span-2">
                    <label for="reason" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">
                        Alasan Koreksi @if($hasAnyExecution)<span class="text-red-500">*</span>@else<span class="text-slate-400 font-normal">(Audit)</span>@endif
                    </label>
                    <input type="text" id="reason" name="reason" value="{{ old('reason') }}" @if($hasAnyExecution) required @endif
                           placeholder="Contoh: Koreksi typo tanggal cor UAT"
                           class="w-full text-xs px-3 py-2 rounded-lg border @if($hasAnyExecution) border-red-300 bg-red-50/30 @else border-slate-300 @endif focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                </div>
            </div>
        </div>

        <!-- 2. Section: Item Lines -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden mb-6">
            <div class="p-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                <div>
                    <h3 class="font-bold text-slate-800 text-sm flex items-center gap-2">
                        <i class="fas fa-boxes-stacked text-indigo-600"></i> RINCIAN BARIS HASIL COR &amp; KTR TRAVELER
                    </h3>
                    <p class="text-slate-400 text-[11px]">Traveler number bersifat permanen dan tidak akan berubah</p>
                </div>
                <span class="text-xs font-mono font-bold text-slate-600 bg-white border border-slate-200 px-2 py-0.5 rounded shadow-sm">
                    {{ $castingResult->lines->count() }} KTR Line(s)
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-xs text-left">
                    <thead class="bg-slate-100 text-slate-600 font-bold uppercase tracking-wider text-[10px]">
                        <tr>
                            <th class="p-3 text-center w-10">No</th>
                            <th class="p-3 text-center w-36">Traveler (KTR)</th>
                            <th class="p-3 text-left w-72">Item / Perintah Cor (PCOR)</th>
                            <th class="p-3 text-center w-28 font-bold text-emerald-800">Qty Good (Pcs) <span class="text-red-500">*</span></th>
                            <th class="p-3 text-center w-24 font-bold text-red-700">Qty Reject</th>
                            <th class="p-3 text-center w-28">Unit Weight (Kg)</th>
                            <th class="p-3 text-center w-28">Total Berat</th>
                            <th class="p-3 text-left">Catatan Line</th>
                            <th class="p-3 text-center w-32">Status Fisik</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        @foreach($castingResult->lines as $idx => $line)
                            @php
                                $lineHasExecution = $line->hasPhysicalExecution();
                                $currentOrderLine = $line->castingOrderLine;
                            @endphp
                            <tr class="hover:bg-slate-50 transition" id="row-{{ $line->id }}">
                                <td class="p-3 text-center font-mono text-slate-400">
                                    {{ $idx + 1 }}
                                    <input type="hidden" name="items[{{ $idx }}][id]" value="{{ $line->id }}">
                                </td>

                                <!-- KTR Number (Immutable) -->
                                <td class="p-3 text-center">
                                    <span class="inline-flex items-center px-2 py-1 rounded bg-slate-900 text-amber-300 font-mono font-bold text-xs border border-slate-700 shadow-sm">
                                        <i class="fas fa-barcode text-[10px] mr-1.5 text-slate-400"></i>
                                        {{ $line->traveler_number }}
                                    </span>
                                    <div class="text-[9px] text-slate-400 font-mono mt-0.5">IMMUTABLE IDENTITY</div>
                                </td>

                                <!-- PCOR / Item Selection -->
                                <td class="p-3">
                                    @if($lineHasExecution)
                                        <div class="bg-slate-100 border border-slate-200 rounded p-2 text-slate-600">
                                            <div class="font-mono font-bold text-blue-800 text-xs flex items-center justify-between">
                                                <span>{{ $currentOrderLine->code ?? '-' }}</span>
                                                <span class="text-[9px] font-bold text-red-600 bg-red-50 border border-red-200 px-1 rounded">TERKUNCI</span>
                                            </div>
                                            <div class="font-semibold text-slate-800 text-[11px] truncate">{{ $currentOrderLine->item_name ?? '-' }}</div>
                                            <div class="text-[10px] text-slate-500 font-mono">
                                                PCOR: {{ $currentOrderLine->castingOrder->casting_order_number ?? '-' }}
                                            </div>
                                        </div>
                                        <input type="hidden" name="items[{{ $idx }}][sand_casting_casting_order_line_id]" value="{{ $line->sand_casting_casting_order_line_id }}">
                                    @else
                                        <select name="items[{{ $idx }}][sand_casting_casting_order_line_id]" required
                                                onchange="updateRowItemInfo(this, {{ $idx }})"
                                                class="w-full text-xs font-medium px-2.5 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                                            @foreach($availableLines as $avail)
                                                @php
                                                    $isSelected = (old("items.{$idx}.sand_casting_casting_order_line_id", $line->sand_casting_casting_order_line_id) == $avail->id);
                                                    $pWeight = $avail->productionPlan ? (float)($avail->productionPlan->weight ?? 0) : 0;
                                                @endphp
                                                <option value="{{ $avail->id }}"
                                                        data-weight="{{ $pWeight }}"
                                                        data-code="{{ $avail->code }}"
                                                        data-item="{{ $avail->item_name }}"
                                                        {{ $isSelected ? 'selected' : '' }}>
                                                    {{ $avail->code }} - {{ $avail->item_name }} ({{ $avail->castingOrder->casting_order_number ?? 'PCOR' }})
                                                </option>
                                            @endforeach
                                        </select>
                                    @endif
                                </td>

                                <!-- Qty Good -->
                                <td class="p-3 text-center">
                                    @if($lineHasExecution)
                                        <input type="number" value="{{ $line->qty_good }}" readonly disabled
                                               class="w-24 text-center font-mono font-bold text-emerald-800 bg-slate-100 border border-slate-300 rounded-lg px-2 py-1.5 cursor-not-allowed">
                                        <input type="hidden" name="items[{{ $idx }}][qty_good]" value="{{ $line->qty_good }}">
                                        <div class="text-[9px] text-red-600 font-mono mt-0.5">Terkunci (Gate Netto)</div>
                                    @else
                                        <input type="number" name="items[{{ $idx }}][qty_good]"
                                               id="qty_good_{{ $idx }}"
                                               value="{{ old("items.{$idx}.qty_good", $line->qty_good) }}"
                                               min="0" required
                                               oninput="calculateRowWeight({{ $idx }})"
                                               class="w-24 text-center font-mono font-bold text-emerald-800 bg-white border border-slate-300 rounded-lg px-2 py-1.5 focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                                    @endif
                                </td>

                                <!-- Qty Reject -->
                                <td class="p-3 text-center">
                                    <input type="number" name="items[{{ $idx }}][qty_reject]"
                                           id="qty_reject_{{ $idx }}"
                                           value="{{ old("items.{$idx}.qty_reject", $line->qty_reject) }}"
                                           min="0"
                                           class="w-20 text-center font-mono font-bold text-red-600 bg-white border border-slate-300 rounded-lg px-2 py-1.5 focus:ring-2 focus:ring-red-500 focus:outline-none">
                                </td>

                                <!-- Unit Weight (Kg) -->
                                <td class="p-3 text-center">
                                    <input type="number" step="0.01" name="items[{{ $idx }}][unit_weight_kg]"
                                           id="unit_weight_{{ $idx }}"
                                           value="{{ old("items.{$idx}.unit_weight_kg", $line->unit_weight_kg) }}"
                                           min="0"
                                           oninput="calculateRowWeight({{ $idx }})"
                                           class="w-24 text-center font-mono text-slate-700 bg-white border border-slate-300 rounded-lg px-2 py-1.5 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                                </td>

                                <!-- Total Weight (Calculated) -->
                                <td class="p-3 text-center font-mono font-bold text-slate-800 text-xs">
                                    <span id="total_weight_display_{{ $idx }}">{{ number_format($line->total_weight_kg, 2) }}</span> kg
                                </td>

                                <!-- Line Notes -->
                                <td class="p-3">
                                    <input type="text" name="items[{{ $idx }}][notes]"
                                           value="{{ old("items.{$idx}.notes", $line->notes) }}"
                                           placeholder="Catatan baris"
                                           class="w-full text-xs px-2.5 py-1.5 rounded border border-slate-300 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                                </td>

                                <!-- Physical Status Badge -->
                                <td class="p-3 text-center">
                                    @if($lineHasExecution)
                                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded text-[10px] font-bold bg-red-50 text-red-700 border border-red-200 shadow-sm">
                                            <i class="fas fa-industry text-[9px]"></i> POST-GATE
                                        </span>
                                        <div class="text-[9px] text-slate-400 font-mono mt-0.5 uppercase">
                                            {{ str_replace('_', ' ', $line->current_stage ?? 'netto') }}
                                        </div>
                                    @else
                                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 shadow-sm">
                                            <i class="fas fa-hourglass-start text-[9px]"></i> PRE-GATE
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </form>

    <script>
        function calculateRowWeight(idx) {
            const qtyInput = document.getElementById('qty_good_' + idx);
            const unitWeightInput = document.getElementById('unit_weight_' + idx);
            const display = document.getElementById('total_weight_display_' + idx);

            if (!display) return;

            const qty = qtyInput ? parseFloat(qtyInput.value || 0) : 0;
            const unitWeight = unitWeightInput ? parseFloat(unitWeightInput.value || 0) : 0;

            const total = (qty * unitWeight).toFixed(2);
            display.textContent = total;
        }

        function updateRowItemInfo(selectElem, idx) {
            const selectedOpt = selectElem.options[selectElem.selectedIndex];
            if (!selectedOpt) return;

            const defaultWeight = parseFloat(selectedOpt.getAttribute('data-weight') || 0);
            const unitWeightInput = document.getElementById('unit_weight_' + idx);
            if (unitWeightInput && (!unitWeightInput.value || parseFloat(unitWeightInput.value) === 0)) {
                unitWeightInput.value = defaultWeight.toFixed(2);
            }

            calculateRowWeight(idx);
        }
    </script>
@endsection
