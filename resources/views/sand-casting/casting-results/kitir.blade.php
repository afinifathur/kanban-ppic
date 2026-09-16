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
            margin: 15px auto;
            background: #fff;
            padding: 36mm 5mm 20mm 5mm;
            box-sizing: border-box;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }

        .kitir-border {
            border: 2px solid #000;
        }

        .border-b-black-solid {
            border-bottom: 1.5px solid #000;
        }

        .border-r-black-solid {
            border-right: 1.5px solid #000;
        }

        .process-row {
            height: 12mm;
        }

        .item-name-wrap {
            overflow-wrap: anywhere;
            word-break: break-word;
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
                margin: 0 !important;
                padding: 36mm 0 0 0 !important;
                box-shadow: none !important;
                page-break-after: avoid !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                display: block !important;
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
        <div class="kitir-border bg-white">

            <!-- 1. Header Section -->
            <div class="grid grid-cols-12 border-b-black-solid">
                <!-- Left: Peroni Brand -->
                <div class="col-span-3 p-1.5 px-3 flex items-center border-r-black-solid">
                    <span class="text-2xl font-black tracking-wider text-black leading-none">PERONI</span>
                </div>

                <!-- Center: Kitir Produksi Title -->
                <div class="col-span-6 p-1.5 text-center flex items-center justify-center border-r-black-solid bg-slate-50/50">
                    <span class="text-2xl font-black tracking-widest text-black uppercase leading-none">KITIR PRODUKSI</span>
                </div>

                <!-- Right: Print Metadata -->
                <div class="col-span-3 p-1.5 px-3 flex items-center justify-end text-[10px] font-mono leading-none gap-3">
                    <div><span class="text-slate-500 font-sans font-bold text-[9px]">TGL CETAK :</span> <strong class="text-black">{{ now()->format('d-m-Y') }}</strong></div>
                    <div><span class="text-slate-500 font-sans font-bold text-[9px]">JAM :</span> <strong class="text-black">{{ now()->format('H:i') }} WIB</strong></div>
                </div>
            </div>

            <!-- 2. Product Information Section (Customer | Kode Produksi | Hasil Cor - Typography Scaled Up) -->
            <div class="grid grid-cols-12 border-b-black-solid bg-slate-50/40 items-center">
                <!-- Col 1: Customer -->
                <div class="col-span-4 p-2 px-3 border-r-black-solid flex items-center overflow-hidden">
                    <span class="text-[11px] font-black uppercase tracking-wider text-slate-600 mr-2 shrink-0">CUSTOMER :</span>
                    <span class="font-black text-base text-black uppercase truncate">{{ $line->castingOrderLine->customer ?? $line->productionPlan->customer ?? '-' }}</span>
                </div>

                <!-- Col 2: Kode Produksi -->
                <div class="col-span-4 p-2 px-3 border-r-black-solid flex items-center overflow-hidden">
                    <span class="text-[11px] font-black uppercase tracking-wider text-slate-600 mr-2 shrink-0">KODE PRODUKSI :</span>
                    <span class="font-mono font-black text-base text-black uppercase tracking-wider truncate">{{ $line->castingOrderLine->code ?? $line->productionPlan->code ?? '-' }}</span>
                </div>

                <!-- Col 3: Hasil Cor -->
                <div class="col-span-4 p-2 px-3 flex items-center justify-between">
                    <span class="text-[11px] font-black uppercase tracking-wider text-slate-600 mr-2 shrink-0">HASIL COR :</span>
                    <span class="font-mono font-black text-xl text-black whitespace-nowrap">{{ number_format($line->qty_good) }} PCS</span>
                </div>
            </div>

            <!-- 3. Main Section: Left Panel (Heat/Barcode/Item/PCOR/KTR) & Right Panel (Full-height Process Table) -->
            <div class="flex flex-row w-full items-stretch">

                <!-- Left Panel: Heat Number, Horizontal Barcode, Prominently Scaled Wrapped Item Name, PCOR & KITIR -->
                <div class="w-[76mm] shrink-0 border-r-black-solid p-2.5 flex flex-col justify-between bg-white">
                    <div>
                        <!-- Heat Number -->
                        <div class="text-[10px] font-black uppercase tracking-wider text-slate-600 leading-none">HEAT NUMBER :</div>
                        <div class="font-mono font-black text-3xl tracking-wider text-black my-1 leading-none">
                            {{ $castingResult->heat_number }}
                        </div>
                        <div class="text-[9px] font-mono text-slate-700 leading-snug border-b border-slate-200 pb-1 mb-2">
                            Tgl : {{ $castingResult->cast_date ? $castingResult->cast_date->format('d/m/Y') : '-' }} | F : {{ $castingResult->furnace ?: '-' }} | Shift : {{ $castingResult->shift ?: '-' }}
                        </div>

                        <!-- Horizontal Barcode in Left Panel -->
                        <div class="bg-slate-50/70 border border-slate-300 rounded p-1.5 mb-2.5 flex flex-col items-center justify-center">
                            <img src="data:image/png;base64,{{ $barcodeBase64 }}" alt="Barcode" class="h-8 w-full max-w-[220px] object-fill">
                            <div class="font-mono font-black text-xs tracking-widest text-black mt-0.5 leading-none">
                                {{ $line->traveler_number }}
                            </div>
                        </div>

                        <!-- Item Name (Prominent & Flexible multi-line wrapping) -->
                        <div class="mb-2">
                            <div class="text-[11px] font-black uppercase tracking-wider text-slate-600 leading-none mb-1">NAMA ITEM :</div>
                            <div class="font-black text-lg text-black uppercase leading-tight item-name-wrap tracking-wide">
                                {{ $line->castingOrderLine->item_name ?? $line->productionPlan->item_name ?? '-' }}
                            </div>
                        </div>
                    </div>

                    <!-- PCOR & KITIR Identifiers -->
                    <div class="space-y-1 text-[10px] font-mono pt-1">
                        <div class="bg-slate-50/80 border border-slate-300 rounded p-1.5">
                            <div class="text-[8px] font-sans font-bold text-slate-500 uppercase leading-none mb-0.5">PCOR :</div>
                            <div class="font-bold text-black text-xs truncate leading-tight">{{ $line->castingOrderLine->castingOrder->casting_order_number ?? '-' }}</div>
                        </div>
                        <div class="bg-slate-50/80 border border-slate-300 rounded p-1.5">
                            <div class="text-[8px] font-sans font-bold text-slate-500 uppercase leading-none mb-0.5">KITIR :</div>
                            <div class="font-bold text-black text-xs truncate leading-tight">{{ $line->traveler_number }}</div>
                        </div>
                    </div>
                </div>

                <!-- Right Panel: Full-height 7-Row Process Table -->
                <div class="flex-1 flex flex-col bg-white">
                    <table class="w-full text-xs text-left border-collapse h-full">
                        <thead>
                            <tr class="bg-slate-100 text-black font-black uppercase text-[10px] tracking-wider border-b-black-solid">
                                <th class="p-2 text-center w-10 border-r-black-solid">NO.</th>
                                <th class="p-2 text-left w-48 border-r-black-solid">PROSES / DEPT</th>
                                <th class="p-2 text-center w-28 border-r-black-solid">HASIL (PCS)</th>
                                <th class="p-2 text-center w-28 border-r-black-solid">RUSAK (PCS)</th>
                                <th class="p-2 text-center w-32 border-r-black-solid">TANGGAL</th>
                                <th class="p-2 text-center">OPERATOR / SPV</th>
                            </tr>
                        </thead>
                        <tbody class="text-black font-mono">
                            <!-- 1. NETTO (POTONG) -->
                            <tr class="process-row border-b-black-solid">
                                <td class="text-center font-bold border-r-black-solid font-mono text-sm">1</td>
                                <td class="font-black border-r-black-solid pl-2 text-[11px] font-sans">NETTO (POTONG)</td>
                                <td class="border-r-black-solid"></td>
                                <td class="border-r-black-solid"></td>
                                <td class="border-r-black-solid"></td>
                                <td></td>
                            </tr>

                            <!-- 2. BUBUT OD -->
                            <tr class="process-row border-b-black-solid">
                                <td class="text-center font-bold border-r-black-solid font-mono text-sm">2</td>
                                <td class="font-black border-r-black-solid pl-2 text-[11px] font-sans">BUBUT OD</td>
                                <td class="border-r-black-solid"></td>
                                <td class="border-r-black-solid"></td>
                                <td class="border-r-black-solid"></td>
                                <td></td>
                            </tr>

                            <!-- 3. MARKING -->
                            <tr class="process-row border-b-black-solid">
                                <td class="text-center font-bold border-r-black-solid font-mono text-sm">3</td>
                                <td class="font-black border-r-black-solid pl-2 text-[11px] font-sans">MARKING</td>
                                <td class="border-r-black-solid"></td>
                                <td class="border-r-black-solid"></td>
                                <td class="border-r-black-solid"></td>
                                <td></td>
                            </tr>

                            <!-- 4. BUBUT CNC -->
                            <tr class="process-row border-b-black-solid">
                                <td class="text-center font-bold border-r-black-solid font-mono text-sm">4</td>
                                <td class="font-black border-r-black-solid pl-2 text-[11px] font-sans">BUBUT CNC</td>
                                <td class="border-r-black-solid"></td>
                                <td class="border-r-black-solid"></td>
                                <td class="border-r-black-solid"></td>
                                <td></td>
                            </tr>

                            <!-- 5. BOR -->
                            <tr class="process-row border-b-black-solid">
                                <td class="text-center font-bold border-r-black-solid font-mono text-sm">5</td>
                                <td class="font-black border-r-black-solid pl-2 text-[11px] font-sans">BOR</td>
                                <td class="border-r-black-solid"></td>
                                <td class="border-r-black-solid"></td>
                                <td class="border-r-black-solid"></td>
                                <td></td>
                            </tr>

                            <!-- 6. QC (FINAL INSPECTION) -->
                            <tr class="process-row border-b-black-solid">
                                <td class="text-center font-bold border-r-black-solid font-mono text-sm">6</td>
                                <td class="font-black border-r-black-solid pl-2 text-[11px] font-sans">QC (FINAL INSPECTION)</td>
                                <td class="border-r-black-solid"></td>
                                <td class="border-r-black-solid"></td>
                                <td class="border-r-black-solid"></td>
                                <td></td>
                            </tr>

                            <!-- 7. GUDANG JADI -->
                            <tr class="process-row">
                                <td class="text-center font-bold border-r-black-solid font-mono text-sm">7</td>
                                <td class="font-black border-r-black-solid pl-2 text-[11px] font-sans">GUDANG JADI</td>
                                <td class="border-r-black-solid"></td>
                                <td class="border-r-black-solid"></td>
                                <td class="border-r-black-solid"></td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>

        </div>
    </div>

</body>
</html>
