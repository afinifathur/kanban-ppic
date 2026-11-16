<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KITIR PRODUKSI FITTING - {{ count($kitirItems) === 1 ? $kitirItems[0]['kitir']->traveler_number : 'Heat ' . $castingResult->heat_number }}</title>
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
            height: 14mm;
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
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                page-break-after: always !important;
                break-after: page !important;
                display: block !important;
            }

            .sheet-container:last-child {
                page-break-after: auto !important;
                break-after: auto !important;
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
        <a href="{{ route('lost-wax.casting-results.show', $castingResult) }}" class="bg-slate-700 hover:bg-slate-600 text-white font-bold text-xs px-3 py-2 rounded-lg transition flex items-center gap-1.5">
            <i class="fas fa-arrow-left"></i> Kembali ke Heat
        </a>
        <button type="button" onclick="window.print()" class="bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-xs px-4 py-2 rounded-lg transition flex items-center gap-1.5 shadow">
            <i class="fas fa-print"></i> Cetak Kitir (A4 Landscape{{ count($kitirItems) > 1 ? ' — ' . count($kitirItems) . ' Halaman' : '' }})
        </button>
    </div>

    @foreach($kitirItems as $itemIndex => $rendered)
    @php
        $kitir = $rendered['kitir'];
        $barcodeBase64 = $rendered['barcodeBase64'];
        $qrCodeSvg = $rendered['qrCodeSvg'];
        $processRows = $rendered['processRows'];
    @endphp

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
                    <span class="text-2xl font-black tracking-widest text-black uppercase leading-none">KITIR PRODUKSI - FITTING</span>
                </div>

                <!-- Right: Print Metadata -->
                <div class="col-span-3 p-1.5 px-3 flex items-center justify-end text-[10px] font-mono leading-none gap-3">
                    <div><span class="text-slate-500 font-sans font-bold text-[9px]">TGL CETAK :</span> <strong class="text-black">{{ now()->format('d-m-Y') }}</strong></div>
                    <div><span class="text-slate-500 font-sans font-bold text-[9px]">JAM :</span> <strong class="text-black">{{ now()->format('H:i') }} WIB</strong></div>
                </div>
            </div>

            <!-- 2. Product Information Section (Customer | Kode Produksi | Hasil Cor) -->
            <div class="grid grid-cols-12 border-b-black-solid bg-slate-50/40 items-center">
                <!-- Col 1: Customer -->
                <div class="col-span-4 p-2 px-3 border-r-black-solid flex items-center overflow-hidden">
                    <span class="text-[11px] font-black uppercase tracking-wider text-slate-600 mr-2 shrink-0">CUSTOMER :</span>
                    <span class="font-black text-base text-black uppercase truncate">{{ $kitir->customer ?: '-' }}</span>
                </div>

                <!-- Col 2: Kode Produksi -->
                <div class="col-span-4 p-2 px-3 border-r-black-solid flex items-center overflow-hidden">
                    <span class="text-[11px] font-black uppercase tracking-wider text-slate-600 mr-2 shrink-0">KODE PRODUKSI :</span>
                    <span class="font-mono font-black text-base text-black uppercase tracking-wider truncate">{{ $kitir->production_code ?: '-' }}</span>
                </div>

                <!-- Col 3: Hasil Cor -->
                <div class="col-span-4 p-2 px-3 flex items-center">
                    <span class="text-[11px] font-black uppercase tracking-wider text-slate-600 mr-2 shrink-0">HASIL COR :</span>
                    <span class="font-mono font-black text-xl text-black whitespace-nowrap">{{ number_format($kitir->quantity_pcs) }} PCS</span>
                </div>
            </div>

            <!-- 3. Main Section: Left Panel (Heat/Barcode/Item/KTR) & Right Panel (Process Table) -->
            <div class="flex flex-row w-full items-stretch">

                <!-- Left Panel: Heat Number, Item Name, Dual Machine-Readable Code (QR + Code 128) -->
                <div class="w-[82mm] shrink-0 border-r-black-solid p-2.5 flex flex-col justify-between bg-white">
                    <div>
                        <!-- Heat Number -->
                        <div class="text-[10px] font-black uppercase tracking-wider text-slate-600 leading-none">HEAT NUMBER :</div>
                        <div class="font-mono font-black text-3xl tracking-wider text-black my-1 leading-none">
                            {{ $castingResult->heat_number }}
                        </div>
                        <div class="text-[9px] font-mono text-slate-700 leading-snug border-b border-slate-200 pb-1 mb-2">
                            Tgl : {{ $castingResult->cast_date ? $castingResult->cast_date->format('d/m/Y') : '-' }} | F : {{ $castingResult->furnace ?: '-' }} | Shift : {{ $castingResult->shift ?: '-' }}
                        </div>

                        <!-- Item Name -->
                        <div class="mb-2">
                            <div class="text-[11px] font-black uppercase tracking-wider text-slate-600 leading-none mb-1">NAMA ITEM :</div>
                            <div class="font-black text-lg text-black uppercase leading-tight item-name-wrap tracking-wide">
                                {{ $kitir->item_name ?: '-' }}
                            </div>
                        </div>
                    </div>

                    <!-- Dual Machine-Readable Codes (QR Code Primary + Code 128 Backup) -->
                    <div class="grid grid-cols-12 gap-1.5 items-stretch pt-1">
                        <!-- Left: Large QR Code (Primary Camera Scanning) -->
                        <div class="col-span-6 bg-slate-50/80 border border-slate-300 rounded p-1 flex flex-col items-center justify-between">
                            <div class="text-[7.5px] font-sans font-black text-slate-500 uppercase tracking-wider leading-none text-center">PRIMARY SCAN</div>
                            <div class="w-full aspect-square flex items-center justify-center p-0.5 bg-white border border-slate-200 rounded my-0.5 [&>svg]:w-full [&>svg]:h-full [&>svg]:max-w-full [&>svg]:max-h-full">
                                {!! $qrCodeSvg !!}
                            </div>
                            <div class="text-[7px] font-mono font-bold text-slate-600 leading-none text-center">QR (ECC LEVEL H)</div>
                        </div>

                        <!-- Right: KTR Number, Pohon Count, and Backup Code 128 -->
                        <div class="col-span-6 flex flex-col justify-between gap-1">
                            <!-- KTR -->
                            <div class="bg-slate-50/80 border border-slate-300 rounded p-1">
                                <div class="text-[7.5px] font-sans font-bold text-slate-500 uppercase leading-none mb-0.5">KITIR NO :</div>
                                <div class="font-mono font-black text-black text-[10px] truncate leading-tight">{{ $kitir->traveler_number }}</div>
                            </div>

                            <!-- Pohon Info -->
                            <div class="bg-slate-50/80 border border-slate-300 rounded p-1">
                                <div class="text-[7.5px] font-sans font-bold text-slate-500 uppercase leading-none mb-0.5">POHON FISIK :</div>
                                <div class="font-mono font-bold text-black text-[10px] truncate leading-tight">{{ $kitir->tree_count }} Pohon</div>
                            </div>

                            <!-- Backup Code 128 Barcode -->
                            <div class="bg-slate-50/80 border border-slate-300 rounded p-1 flex flex-col items-center justify-center">
                                <div class="text-[7px] font-sans font-bold text-slate-500 uppercase leading-none mb-0.5 text-center">BACKUP CODE 128</div>
                                <img src="data:image/png;base64,{{ $barcodeBase64 }}" alt="Code 128 Barcode" class="h-6 w-full max-w-[110px] object-fill">
                                <div class="font-mono font-bold text-[8px] tracking-tight text-black mt-0.5 leading-none text-center">
                                    {{ $kitir->traveler_number }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Panel: Full-height 6-Row Process Table -->
                <div class="flex-1 flex flex-col bg-white">
                    <table class="w-full text-xs text-left border-collapse h-full">
                        <thead>
                            <tr class="bg-slate-100 text-black font-black uppercase text-[10px] tracking-wider border-b-black-solid">
                                <th class="p-2 text-center w-9 border-r-black-solid">NO.</th>
                                <th class="p-2 text-left w-32 border-r-black-solid">PROSES / DEPT</th>
                                <th class="p-2 text-center w-22 border-r-black-solid">HASIL (PCS)</th>
                                <th class="p-2 text-center w-22 border-r-black-solid">RUSAK (PCS)</th>
                                <th class="p-2 text-center w-24 border-r-black-solid">TANGGAL</th>
                                <th class="p-2 text-center w-32 border-r-black-solid">KPI</th>
                                <th class="p-2 text-center w-28">OPERATOR / SPV</th>
                            </tr>
                        </thead>
                        <tbody class="text-black font-mono">
                            @foreach($processRows as $row)
                                <tr class="process-row {{ !$loop->last ? 'border-b-black-solid' : '' }}">
                                    <td class="text-center font-bold border-r-black-solid font-mono text-sm">{{ $row['no'] }}</td>
                                    <td class="font-black border-r-black-solid pl-2.5 text-[11px] font-sans">{{ $row['label'] }}</td>
                                    <td class="border-r-black-solid text-center font-bold font-mono text-sm"></td>
                                    <td class="border-r-black-solid text-center font-bold font-mono text-sm"></td>
                                    <td class="border-r-black-solid text-center font-bold font-mono text-xs"></td>
                                    <td class="border-r-black-solid text-center align-middle font-bold text-[8.5pt] text-slate-800">
                                        {!! $row['kpi'] !!}
                                    </td>
                                    <td class="text-center font-bold font-sans text-xs"></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

            </div>

        </div>

        <!-- ISO Document Code Footer (Outside Main Form Border, Bottom Left) -->
        <div class="mt-1.5 flex items-center justify-between text-left">
            <span class="font-mono text-[9pt] font-bold text-gray-800 tracking-wider">FR/PPIC/11-01/09/26-00-01</span>
            @if(count($kitirItems) > 1)
                <span class="font-mono text-[8pt] text-gray-600">Dokumen {{ $itemIndex + 1 }} dari {{ count($kitirItems) }} Kitir</span>
            @endif
        </div>
    </div>
    @endforeach

</body>
</html>

