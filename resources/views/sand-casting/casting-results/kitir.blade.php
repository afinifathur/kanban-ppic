<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KITIR PRODUKSI - {{ $line->traveler_number }}</title>
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('css/all.min.css') }}">
    <style>
        @page {
            size: A4 landscape;
            margin: 6mm 8mm;
        }

        body {
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: #f1f5f9;
            color: #000;
            margin: 0;
            padding: 0;
        }

        .sheet-container {
            width: 281mm;
            min-height: 196mm;
            max-height: 197mm;
            margin: 15px auto;
            background: #fff;
            padding: 5mm;
            box-sizing: border-box;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        .kitir-border {
            border: 2.5px solid #000;
        }

        .border-b-black-solid {
            border-bottom: 2px solid #000;
        }

        .border-r-black-solid {
            border-right: 2px solid #000;
        }

        .border-t-black-solid {
            border-top: 1.5px solid #000;
        }

        .process-row {
            height: 14.5mm;
        }

        @media print {
            body {
                background: #fff !important;
                margin: 0 !important;
                padding: 0 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .sheet-container {
                width: 100% !important;
                height: 100% !important;
                min-height: 196mm !important;
                max-height: 197mm !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                page-break-after: avoid !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body class="antialiased">

    <!-- Screen Action Floating Controls -->
    <div class="no-print fixed top-4 right-4 z-50 flex items-center gap-2 bg-slate-900/90 text-white p-2.5 rounded-xl shadow-xl backdrop-blur-sm border border-slate-700">
        <a href="{{ route('sand-casting.casting-results.show', $castingResult) }}" class="bg-slate-700 hover:bg-slate-600 text-white font-bold text-xs px-3 py-2 rounded-lg transition flex items-center gap-1.5">
            <i class="fas fa-arrow-left"></i> Kembali ke Heat
        </a>
        <button type="button" onclick="window.print()" class="bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-xs px-4 py-2 rounded-lg transition flex items-center gap-1.5 shadow">
            <i class="fas fa-print"></i> Cetak Kitir (A4 Landscape)
        </button>
    </div>

    <!-- Printable A4 Landscape Sheet -->
    <div class="sheet-container">
        <div class="kitir-border h-full flex flex-col justify-between">

            <!-- 1. Header Section -->
            <div class="grid grid-cols-12 border-b-black-solid">
                <!-- Left: Peroni Brand -->
                <div class="col-span-3 p-3 flex flex-col justify-center border-r-black-solid">
                    <div class="text-2xl font-black tracking-wider text-black leading-none">PERONI</div>
                    <div class="text-[9px] font-extrabold tracking-widest text-slate-800 uppercase mt-1">CASTING THE FUTURE</div>
                </div>

                <!-- Center: Kitir Produksi Title -->
                <div class="col-span-6 p-2.5 text-center flex flex-col justify-center border-r-black-solid bg-slate-50/50">
                    <div class="text-2xl font-black tracking-widest text-black uppercase leading-tight">KITIR PRODUKSI</div>
                    <div class="text-xs font-black tracking-widest text-slate-700 uppercase mt-0.5">SAND CASTING</div>
                </div>

                <!-- Right: Print Metadata -->
                <div class="col-span-3 p-3 flex flex-col justify-center text-right text-[11px] font-mono leading-tight">
                    <div><span class="text-slate-500 font-sans font-bold text-[10px]">TGL CETAK :</span> <strong class="text-black">{{ now()->format('d-m-Y') }}</strong></div>
                    <div class="mt-1"><span class="text-slate-500 font-sans font-bold text-[10px]">JAM :</span> <strong class="text-black">{{ now()->format('H:i') }} WIB</strong></div>
                </div>
            </div>

            <!-- 2. Product Information Section (3 Columns) -->
            <div class="grid grid-cols-12 border-b-black-solid">
                <!-- Col 1: Nama Item -->
                <div class="col-span-5 p-3 border-r-black-solid flex flex-col justify-between">
                    <div class="text-[10px] font-black uppercase tracking-wider text-slate-600 mb-0.5">NAMA ITEM :</div>
                    <div class="font-black text-base text-black uppercase leading-snug">
                        {{ $line->castingOrderLine->item_name ?? $line->productionPlan->item_name ?? '-' }}
                    </div>
                    @if($line->castingOrderLine && ($line->castingOrderLine->size || $line->castingOrderLine->aisi))
                        <div class="text-xs font-mono font-bold text-slate-700 mt-1">
                            {{ $line->castingOrderLine->size }} {{ $line->castingOrderLine->aisi ? '('.$line->castingOrderLine->aisi.')' : '' }}
                        </div>
                    @endif
                </div>

                <!-- Col 2: Customer -->
                <div class="col-span-4 p-3 border-r-black-solid flex flex-col justify-between">
                    <div class="text-[10px] font-black uppercase tracking-wider text-slate-600 mb-0.5">CUSTOMER :</div>
                    <div class="font-black text-lg text-black uppercase leading-tight truncate">
                        {{ $line->castingOrderLine->customer ?? $line->productionPlan->customer ?? '-' }}
                    </div>
                </div>

                <!-- Col 3: Kode Produksi -->
                <div class="col-span-3 p-3 bg-slate-50/70 flex flex-col justify-between">
                    <div class="text-[10px] font-black uppercase tracking-wider text-slate-600 mb-0.5">KODE PRODUKSI :</div>
                    <div class="font-mono font-black text-2xl text-black tracking-wider">
                        {{ $line->castingOrderLine->code ?? $line->productionPlan->code ?? '-' }}
                    </div>
                </div>
            </div>

            <!-- 3. Heat / Barcode / Output Section -->
            <div class="grid grid-cols-12 border-b-black-solid">
                <!-- Left: Heat Number -->
                <div class="col-span-4 p-3 border-r-black-solid flex flex-col justify-between">
                    <div class="text-[10px] font-black uppercase tracking-wider text-slate-600 mb-0.5">HEAT NUMBER :</div>
                    <div class="font-mono font-black text-2xl text-black tracking-wider">
                        {{ $castingResult->heat_number }}
                    </div>
                    <div class="text-[10px] font-mono text-slate-600 mt-1">
                        Tgl: {{ $castingResult->cast_date ? $castingResult->cast_date->format('d/m/Y') : '-' }} | F: {{ $castingResult->furnace ?: '-' }} | Shift: {{ $castingResult->shift ?: '-' }}
                    </div>
                </div>

                <!-- Center: Code 128 Barcode & Traveler -->
                <div class="col-span-4 p-2.5 text-center flex flex-col items-center justify-center border-r-black-solid">
                    <img src="data:image/png;base64,{{ $barcodeBase64 }}" alt="Barcode" class="h-10 max-w-full object-contain mx-auto">
                    <div class="font-mono font-black text-sm tracking-widest text-black mt-1">
                        {{ $line->traveler_number }}
                    </div>
                </div>

                <!-- Right: Hasil Cor (Good Quantity) -->
                <div class="col-span-4 p-3 bg-slate-50/70 flex flex-col justify-between text-right">
                    <div class="text-[10px] font-black uppercase tracking-wider text-slate-600 mb-0.5">HASIL COR :</div>
                    <div class="font-mono font-black text-3xl text-black leading-none">
                        {{ number_format($line->qty_good) }} <span class="text-lg font-bold">PCS</span>
                    </div>
                    @if($line->total_weight_kg > 0)
                        <div class="text-xs font-mono font-bold text-slate-700 mt-1">
                            Est. Berat: {{ number_format($line->total_weight_kg, 2) }} kg
                        </div>
                    @endif
                </div>
            </div>

            <!-- 4. Process Table (7 Strict Rows) -->
            <div class="w-full border-b-black-solid">
                <table class="w-full text-xs text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-100 text-black font-black uppercase text-[10px] tracking-wider">
                            <th class="p-2 text-center w-10 border-r-black-solid">NO.</th>
                            <th class="p-2 text-left w-56 border-r-black-solid">PROSES / DEPARTEMEN</th>
                            <th class="p-2 text-center w-28 border-r-black-solid">HASIL (PCS)</th>
                            <th class="p-2 text-center w-28 border-r-black-solid">RUSAK (PCS)</th>
                            <th class="p-2 text-center w-28 border-r-black-solid">TANGGAL</th>
                            <th class="p-2 text-center w-36 border-r-black-solid">OPERATOR / SPV</th>
                            <th class="p-2 text-left">KETERANGAN</th>
                        </tr>
                    </thead>
                    <tbody class="text-black">
                        <!-- 1. NETTO (POTONG) -->
                        <tr class="process-row border-t-black-solid">
                            <td class="text-center font-bold font-mono border-r-black-solid">1</td>
                            <td class="font-black border-r-black-solid pl-2 text-[11px]">NETTO (POTONG)</td>
                            <td class="border-r-black-solid"></td>
                            <td class="border-r-black-solid"></td>
                            <td class="border-r-black-solid"></td>
                            <td class="border-r-black-solid"></td>
                            <td></td>
                        </tr>

                        <!-- 2. BUBUT OD -->
                        <tr class="process-row border-t-black-solid">
                            <td class="text-center font-bold font-mono border-r-black-solid">2</td>
                            <td class="font-black border-r-black-solid pl-2 text-[11px]">BUBUT OD</td>
                            <td class="border-r-black-solid"></td>
                            <td class="border-r-black-solid"></td>
                            <td class="border-r-black-solid"></td>
                            <td class="border-r-black-solid"></td>
                            <td></td>
                        </tr>

                        <!-- 3. MARKING -->
                        <tr class="process-row border-t-black-solid">
                            <td class="text-center font-bold font-mono border-r-black-solid">3</td>
                            <td class="font-black border-r-black-solid pl-2 text-[11px]">MARKING</td>
                            <td class="border-r-black-solid"></td>
                            <td class="border-r-black-solid"></td>
                            <td class="border-r-black-solid"></td>
                            <td class="border-r-black-solid"></td>
                            <td></td>
                        </tr>

                        <!-- 4. BUBUT CNC -->
                        <tr class="process-row border-t-black-solid">
                            <td class="text-center font-bold font-mono border-r-black-solid">4</td>
                            <td class="font-black border-r-black-solid pl-2 text-[11px]">BUBUT CNC</td>
                            <td class="border-r-black-solid"></td>
                            <td class="border-r-black-solid"></td>
                            <td class="border-r-black-solid"></td>
                            <td class="border-r-black-solid"></td>
                            <td></td>
                        </tr>

                        <!-- 5. BOR -->
                        <tr class="process-row border-t-black-solid">
                            <td class="text-center font-bold font-mono border-r-black-solid">5</td>
                            <td class="font-black border-r-black-solid pl-2 text-[11px]">BOR</td>
                            <td class="border-r-black-solid"></td>
                            <td class="border-r-black-solid"></td>
                            <td class="border-r-black-solid"></td>
                            <td class="border-r-black-solid"></td>
                            <td></td>
                        </tr>

                        <!-- 6. QC (FINAL INSPECTION) -->
                        <tr class="process-row border-t-black-solid">
                            <td class="text-center font-bold font-mono border-r-black-solid">6</td>
                            <td class="font-black border-r-black-solid pl-2 text-[11px]">QC (FINAL INSPECTION)</td>
                            <td class="border-r-black-solid"></td>
                            <td class="border-r-black-solid"></td>
                            <td class="border-r-black-solid"></td>
                            <td class="border-r-black-solid"></td>
                            <td></td>
                        </tr>

                        <!-- 7. GUDANG JADI -->
                        <tr class="process-row border-t-black-solid">
                            <td class="text-center font-bold font-mono border-r-black-solid">7</td>
                            <td class="font-black border-r-black-solid pl-2 text-[11px]">GUDANG JADI</td>
                            <td class="border-r-black-solid"></td>
                            <td class="border-r-black-solid"></td>
                            <td class="border-r-black-solid"></td>
                            <td class="border-r-black-solid"></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- 5. Notes & Traceability Footer -->
            <div class="grid grid-cols-12 p-3 bg-white items-center">
                <!-- Left: Standard Instructions -->
                <div class="col-span-8">
                    <div class="text-[10px] font-black uppercase tracking-wider text-black mb-0.5">CATATAN :</div>
                    <ol class="list-decimal pl-4 text-[10px] font-semibold text-slate-800 space-y-0.5">
                        <li>Isi hasil dan rusak dengan jelas menggunakan spidol.</li>
                        <li>Pastikan jumlah hasil + rusak = jumlah sebelumnya.</li>
                        <li>Jaga kebersihan dan kondisi kitir produksi ini.</li>
                    </ol>
                </div>

                <!-- Right: Document References -->
                <div class="col-span-4 text-right text-[10px] font-mono text-slate-500 leading-tight">
                    <div>PCOR: <strong class="text-black">{{ $line->castingOrderLine->castingOrder->casting_order_number ?? '-' }}</strong></div>
                    <div class="mt-0.5">KITIR: <strong class="text-black">{{ $line->traveler_number }}</strong></div>
                </div>
            </div>

        </div>
    </div>

</body>
</html>
