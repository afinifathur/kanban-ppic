<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FORM BARCODE LAPISAN - Traveler Cards</title>
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
    @php
        $isThermal = request('format') === 'thermal';
    @endphp
    <style>
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        body {
            font-family: 'Courier New', Courier, monospace, 'Liberation Mono';
            background: #f1f5f9;
            margin: 0;
            padding: 0;
            color: #000;
        }
        
        .print-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 24px;
            padding: 20px;
        }

        /* Mode A4 Sheet (Default 6-grid) */
        .print-page-a4 {
            background: #fff;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            display: grid;
            grid-template-columns: repeat(2, 92mm);
            grid-template-rows: repeat(3, 88mm);
            gap: 4mm;
            padding: 8mm 6mm;
            box-sizing: border-box;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            width: 208mm;
            min-height: 288mm;
        }

        /* Mode Thermal Single Label (90x50 mm) */
        .print-page-thermal {
            background: #fff;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
        }

        .traveler-card {
            background: #fff;
            box-sizing: border-box;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        /* A4 Card Container */
        .traveler-card-a4 {
            width: 92mm;
            height: 88mm;
            max-width: 92mm;
            max-height: 88mm;
            padding: 1.5mm;
        }

        /* Thermal Card Container */
        .traveler-card-thermal {
            width: 90mm;
            height: 50mm;
            max-width: 90mm;
            max-height: 50mm;
            padding: 0;
            margin: 0 auto;
        }

        /* Inner bordered box */
        .card-inner {
            width: 100%;
            height: 100%;
            border: 1.5pt solid #000;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            background: #fff;
            overflow: hidden;
        }

        /* SECTION A: HEADER (~10%) */
        .card-header {
            display: flex;
            align-items: center;
            justify-content: center;
            border-bottom: 1.5pt solid #000;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: #fff;
            text-align: center;
        }
        .traveler-card-a4 .card-header {
            height: 8.5mm;
            min-height: 8.5mm;
            font-size: 11pt;
        }
        .traveler-card-thermal .card-header {
            height: 5mm;
            min-height: 5mm;
            font-size: 8.5pt;
        }

        /* SECTION B: PRODUCT INFO (~33%) */
        .card-info {
            display: flex;
            flex-direction: column;
            justify-content: space-evenly;
            border-bottom: 1.5pt solid #000;
            box-sizing: border-box;
            background: #fff;
        }
        .traveler-card-a4 .card-info {
            height: 29mm;
            min-height: 29mm;
            padding: 1mm 2.5mm;
            font-size: 7.5pt;
        }
        .traveler-card-thermal .card-info {
            height: 16.5mm;
            min-height: 16.5mm;
            padding: 0.5mm 1.5mm;
            font-size: 5.8pt;
        }

        .info-row {
            display: flex;
            align-items: center;
            line-height: 1.25;
            white-space: nowrap;
        }
        .traveler-card-a4 .info-label {
            width: 33mm;
            flex-shrink: 0;
            font-weight: 700;
            text-align: left;
        }
        .traveler-card-thermal .info-label {
            width: 26mm;
            flex-shrink: 0;
            font-weight: 700;
            text-align: left;
        }
        .info-colon {
            width: 2.5mm;
            flex-shrink: 0;
            text-align: center;
            font-weight: 700;
        }
        .info-val {
            flex-grow: 1;
            min-width: 0;
            font-weight: 700;
            text-align: left;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* SECTION C: BARCODE, QR, PCS (~39%) */
        .card-middle {
            display: flex;
            flex-direction: row;
            align-items: stretch;
            border-bottom: 1.5pt solid #000;
            box-sizing: border-box;
            background: #fff;
        }
        .traveler-card-a4 .card-middle {
            height: 34.5mm;
            min-height: 34.5mm;
        }
        .traveler-card-thermal .card-middle {
            height: 19.5mm;
            min-height: 19.5mm;
        }

        /* Left Column (~38%) */
        .col-barcode {
            width: 38%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-right: 1.5pt solid #000;
            padding: 1mm;
            box-sizing: border-box;
        }
        .traveler-card-a4 .barcode-img {
            max-width: 95%;
            height: 22mm;
            object-fit: contain;
        }
        .traveler-card-thermal .barcode-img {
            max-width: 95%;
            height: 12mm;
            object-fit: contain;
        }
        .traveler-card-a4 .barcode-num {
            font-size: 8.5pt;
            font-weight: 800;
            font-family: monospace;
            letter-spacing: 0.8px;
            margin-top: 1mm;
            text-align: center;
        }
        .traveler-card-thermal .barcode-num {
            font-size: 6.5pt;
            font-weight: 800;
            font-family: monospace;
            letter-spacing: 0.5px;
            margin-top: 0.5mm;
            text-align: center;
        }

        /* Center Column (~30%) - ENLARGED QR CODE */
        .col-qr {
            width: 30%;
            display: flex;
            align-items: center;
            justify-content: center;
            border-right: 1.5pt solid #000;
            padding: 1mm;
            box-sizing: border-box;
            background: #fff;
        }
        .traveler-card-a4 .qr-img {
            width: 27mm;
            height: 27mm;
            max-width: 96%;
            max-height: 96%;
            aspect-ratio: 1/1;
            object-fit: contain;
            display: block;
        }
        .traveler-card-thermal .qr-img {
            width: 17.5mm;
            height: 17.5mm;
            max-width: 96%;
            max-height: 96%;
            aspect-ratio: 1/1;
            object-fit: contain;
            display: block;
        }

        /* Right Column (~32%) - PCS */
        .col-qty {
            width: 32%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1mm;
            box-sizing: border-box;
            text-align: center;
        }
        .traveler-card-a4 .qty-title {
            font-size: 7.5pt;
            font-weight: 800;
            letter-spacing: 0.5px;
            margin-bottom: 2mm;
            text-transform: uppercase;
        }
        .traveler-card-thermal .qty-title {
            font-size: 6pt;
            font-weight: 800;
            letter-spacing: 0.3px;
            margin-bottom: 1mm;
            text-transform: uppercase;
        }
        .traveler-card-a4 .qty-val {
            font-size: 15pt;
            font-weight: 900;
            line-height: 1;
        }
        .traveler-card-thermal .qty-val {
            font-size: 11pt;
            font-weight: 900;
            line-height: 1;
        }

        /* SECTION D: FOOTER (~18%) */
        .card-footer {
            display: flex;
            flex-direction: row;
            align-items: stretch;
            box-sizing: border-box;
            background: #fff;
        }
        .traveler-card-a4 .card-footer {
            height: 16mm;
            min-height: 16mm;
            font-size: 7pt;
        }
        .traveler-card-thermal .card-footer {
            height: 9mm;
            min-height: 9mm;
            font-size: 5.5pt;
        }

        .footer-left {
            width: 50%;
            display: flex;
            flex-direction: column;
            justify-content: space-evenly;
            border-right: 1.5pt solid #000;
            box-sizing: border-box;
        }
        .traveler-card-a4 .footer-left {
            padding: 1mm 2.5mm;
        }
        .traveler-card-thermal .footer-left {
            padding: 0.5mm 1.5mm;
        }

        .footer-right {
            width: 50%;
            display: flex;
            flex-direction: column;
            justify-content: space-evenly;
            box-sizing: border-box;
        }
        .traveler-card-a4 .footer-right {
            padding: 1mm 2.5mm;
        }
        .traveler-card-thermal .footer-right {
            padding: 0.5mm 1.5mm;
        }

        .footer-row {
            display: flex;
            align-items: center;
            line-height: 1.25;
            white-space: nowrap;
        }
        .traveler-card-a4 .footer-label-left {
            width: 20mm;
            flex-shrink: 0;
            font-weight: 700;
        }
        .traveler-card-thermal .footer-label-left {
            width: 14mm;
            flex-shrink: 0;
            font-weight: 700;
        }
        .traveler-card-a4 .footer-label-right {
            width: 23mm;
            flex-shrink: 0;
            font-weight: 700;
        }
        .traveler-card-thermal .footer-label-right {
            width: 16mm;
            flex-shrink: 0;
            font-weight: 700;
        }
        .footer-colon {
            width: 2mm;
            flex-shrink: 0;
            text-align: center;
            font-weight: 700;
        }
        .footer-val {
            flex-grow: 1;
            min-width: 0;
            font-weight: 700;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* PRINT MEDIA STYLES */
        @media print {
            @if($isThermal)
                @page {
                    size: 90mm 50mm;
                    margin: 0;
                }
                body {
                    background: #fff !important;
                    margin: 0 !important;
                    padding: 0 !important;
                }
                .print-container {
                    display: block !important;
                    padding: 0 !important;
                    margin: 0 !important;
                }
                .print-page-thermal {
                    display: block !important;
                    padding: 0 !important;
                    margin: 0 !important;
                    box-shadow: none !important;
                    border: none !important;
                    border-radius: 0 !important;
                }
                .traveler-card-thermal {
                    width: 90mm !important;
                    height: 50mm !important;
                    page-break-after: always !important;
                    break-after: page !important;
                    margin: 0 !important;
                    padding: 0 !important;
                }
                .traveler-card-thermal:last-child {
                    page-break-after: avoid !important;
                    break-after: avoid !important;
                }
            @else
                @page {
                    size: A4 portrait;
                    margin: 8mm 6mm;
                }
                body {
                    background: #fff !important;
                    margin: 0 !important;
                    padding: 0 !important;
                }
                .print-container {
                    display: block !important;
                    padding: 0 !important;
                    margin: 0 !important;
                }
                .print-page-a4 {
                    box-shadow: none !important;
                    border: none !important;
                    border-radius: 0 !important;
                    padding: 0 !important;
                    gap: 4mm !important;
                    width: 188mm !important;
                    height: 272mm !important;
                    margin: 0 auto !important;
                    page-break-after: always !important;
                    break-inside: avoid !important;
                }
                .print-page-a4:last-child {
                    page-break-after: avoid !important;
                }
                .traveler-card-a4 {
                    width: 92mm !important;
                    height: 88mm !important;
                    min-height: 88mm !important;
                    padding: 0 !important;
                    box-shadow: none !important;
                    break-inside: avoid !important;
                    page-break-inside: avoid !important;
                }
            @endif

            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body class="min-h-screen">
    @if(isset($warnings) && count($warnings) > 0)
        <div class="no-print max-w-4xl mx-auto my-4 p-4 bg-amber-100 border border-amber-400 text-amber-800 rounded-lg shadow-sm font-sans">
            <h4 class="font-bold flex items-center gap-1.5 mb-1 text-sm">
                <i class="fas fa-exclamation-triangle"></i> Peringatan Traveler Gate
            </h4>
            <ul class="list-disc pl-5 text-xs space-y-0.5">
                @foreach($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="print-container">
        @if($isThermal)
            <div class="print-page-thermal">
                @foreach($treesList as $t)
                    <div class="traveler-card traveler-card-thermal" data-document-type="LOST WAX TRAVELER">
                        <div class="card-inner">
                            <!-- A. HEADER (~10%) -->
                            <div class="card-header">
                                FORM BARCODE LAPISAN
                            </div>

                            <!-- B. INFORMASI PRODUK (~33%) -->
                            <div class="card-info">
                                <div class="info-row">
                                    <span class="info-label">1. KODE PRODUKSI</span>
                                    <span class="info-colon">:</span>
                                    <span class="info-val">{{ $t->printOrderLine?->productionPlan?->code ?? $t->getSourceCode() ?? '-' }}</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">2. KODE ITEM</span>
                                    <span class="info-colon">:</span>
                                    <span class="info-val">{{ $t->getSourceItemCode() ?? '-' }}</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">3. NAMA ITEM</span>
                                    <span class="info-colon">:</span>
                                    <span class="info-val" title="{{ $t->getSourceProduct() }}">{{ $t->getSourceProduct() ?? '-' }}</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">4. UKURAN</span>
                                    <span class="info-colon">:</span>
                                    <span class="info-val">{{ $t->getSourceSize() ?? '-' }}</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">5. AISI</span>
                                    <span class="info-colon">:</span>
                                    <span class="info-val">{{ $t->getSourceAisi() ?? '-' }}</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">6. KODE CUST</span>
                                    <span class="info-colon">:</span>
                                    <span class="info-val" title="{{ $t->getSourceCustomer() }}">{{ $t->getSourceCustomer() ?? '-' }}</span>
                                </div>
                            </div>

                            <!-- C. BARCODE, QR CODE, DAN JUMLAH PCS (~39%) -->
                            <div class="card-middle">
                                <!-- Kolom Kiri: Linear Barcode (~38%) -->
                                <div class="col-barcode">
                                    <img src="{{ route('lost-wax.trees.barcode', $t) }}" alt="Barcode {{ $t->barcode }}" class="barcode-img">
                                    <div class="barcode-num">{{ $t->barcode }}</div>
                                </div>

                                <!-- Kolom Tengah: QR Code (~30%) -->
                                <div class="col-qr">
                                    @if(isset($qrCodes[$t->id]))
                                        <img src="{{ $qrCodes[$t->id] }}" alt="SCAN QR" class="qr-img">
                                    @else
                                        <div class="text-[9px] font-bold text-slate-400">-</div>
                                    @endif
                                </div>

                                <!-- Kolom Kanan: Jumlah PCS (~32%) -->
                                <div class="col-qty">
                                    <div class="qty-title">ISI RANGKAIAN</div>
                                    <div class="qty-val">{{ number_format($t->quantity) }} PCS</div>
                                </div>
                            </div>

                            <!-- D. FOOTER (~18%) -->
                            <div class="card-footer">
                                <div class="footer-left">
                                    <div class="footer-row">
                                        <span class="footer-label-left">TGL PRINT</span>
                                        <span class="footer-colon">:</span>
                                        <span class="footer-val">{{ now()->format('d-m-Y') }}</span>
                                    </div>
                                    <div class="footer-row">
                                        <span class="footer-label-left">JAM PRINT</span>
                                        <span class="footer-colon">:</span>
                                        <span class="footer-val">{{ now()->format('H:i') }}</span>
                                    </div>
                                </div>
                                <div class="footer-right">
                                    <div class="footer-row">
                                        <span class="footer-label-right">KODE RAK</span>
                                        <span class="footer-colon">:</span>
                                        <span class="footer-val font-bold">{{ $t->coatingRack ? 'RAK-' . str_pad($t->coatingRack->rack_number, 2, '0', STR_PAD_LEFT) : '-' }}</span>
                                    </div>
                                    <div class="footer-row">
                                        <span class="footer-label-right">KETERANGAN</span>
                                        <span class="footer-colon">:</span>
                                        <span class="footer-val font-mono">___________</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            @foreach($treesList->chunk(6) as $pageChunk)
                <div class="print-page-a4">
                    @foreach($pageChunk as $t)
                        <div class="traveler-card traveler-card-a4" data-document-type="LOST WAX TRAVELER">
                            <div class="card-inner">
                                <!-- A. HEADER (~10%) -->
                                <div class="card-header">
                                    FORM BARCODE LAPISAN
                                </div>

                                <!-- B. INFORMASI PRODUK (~33%) -->
                                <div class="card-info">
                                    <div class="info-row">
                                        <span class="info-label">1. KODE PRODUKSI</span>
                                        <span class="info-colon">:</span>
                                        <span class="info-val">{{ $t->printOrderLine?->productionPlan?->code ?? $t->getSourceCode() ?? '-' }}</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">2. KODE ITEM</span>
                                        <span class="info-colon">:</span>
                                        <span class="info-val">{{ $t->getSourceItemCode() ?? '-' }}</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">3. NAMA ITEM</span>
                                        <span class="info-colon">:</span>
                                        <span class="info-val" title="{{ $t->getSourceProduct() }}">{{ $t->getSourceProduct() ?? '-' }}</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">4. UKURAN</span>
                                        <span class="info-colon">:</span>
                                        <span class="info-val">{{ $t->getSourceSize() ?? '-' }}</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">5. AISI</span>
                                        <span class="info-colon">:</span>
                                        <span class="info-val">{{ $t->getSourceAisi() ?? '-' }}</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">6. KODE CUST</span>
                                        <span class="info-colon">:</span>
                                        <span class="info-val" title="{{ $t->getSourceCustomer() }}">{{ $t->getSourceCustomer() ?? '-' }}</span>
                                    </div>
                                </div>

                                <!-- C. BARCODE, QR CODE, DAN JUMLAH PCS (~39%) -->
                                <div class="card-middle">
                                    <!-- Kolom Kiri: Linear Barcode (~38%) -->
                                    <div class="col-barcode">
                                        <img src="{{ route('lost-wax.trees.barcode', $t) }}" alt="Barcode {{ $t->barcode }}" class="barcode-img">
                                        <div class="barcode-num">{{ $t->barcode }}</div>
                                    </div>

                                    <!-- Kolom Tengah: QR Code (~30%) -->
                                    <div class="col-qr">
                                        @if(isset($qrCodes[$t->id]))
                                            <img src="{{ $qrCodes[$t->id] }}" alt="SCAN QR" class="qr-img">
                                        @else
                                            <div class="text-[9px] font-bold text-slate-400">-</div>
                                        @endif
                                    </div>

                                    <!-- Kolom Kanan: Jumlah PCS (~32%) -->
                                    <div class="col-qty">
                                        <div class="qty-title">ISI RANGKAIAN</div>
                                        <div class="qty-val">{{ number_format($t->quantity) }} PCS</div>
                                    </div>
                                </div>

                                <!-- D. FOOTER (~18%) -->
                                <div class="card-footer">
                                    <div class="footer-left">
                                        <div class="footer-row">
                                            <span class="footer-label-left">TGL PRINT</span>
                                            <span class="footer-colon">:</span>
                                            <span class="footer-val">{{ now()->format('d-m-Y') }}</span>
                                        </div>
                                        <div class="footer-row">
                                            <span class="footer-label-left">JAM PRINT</span>
                                            <span class="footer-colon">:</span>
                                            <span class="footer-val">{{ now()->format('H:i') }}</span>
                                        </div>
                                    </div>
                                    <div class="footer-right">
                                        <div class="footer-row">
                                            <span class="footer-label-right">KODE RAK</span>
                                            <span class="footer-colon">:</span>
                                            <span class="footer-val font-bold">{{ $t->coatingRack ? 'RAK-' . str_pad($t->coatingRack->rack_number, 2, '0', STR_PAD_LEFT) : '-' }}</span>
                                        </div>
                                        <div class="footer-row">
                                            <span class="footer-label-right">KETERANGAN</span>
                                            <span class="footer-colon">:</span>
                                            <span class="footer-val font-mono">___________</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach
        @endif
    </div>

    <!-- Floating Action Toolbar -->
    <div class="no-print fixed bottom-4 left-1/2 -translate-x-1/2 flex items-center gap-2.5 z-50 bg-white/95 backdrop-blur p-2 rounded-xl shadow-2xl border border-slate-300 font-sans">
        @if($isThermal)
            <a href="{{ request()->fullUrlWithQuery(['format' => 'a4']) }}" class="bg-slate-700 hover:bg-slate-800 text-white text-xs font-bold py-2 px-3 rounded-lg shadow flex items-center gap-1.5 transition-colors">
                <i class="fas fa-file-alt"></i> Format A4 (6/sheet)
            </a>
            <button onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold py-2 px-3 rounded-lg shadow flex items-center gap-1.5 transition-colors">
                <i class="fas fa-print"></i> Cetak Browser Thermal (90×50)
            </button>
        @else
            <button onclick="window.print()" class="bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold py-2 px-3 rounded-lg shadow flex items-center gap-1.5 transition-colors">
                <i class="fas fa-print"></i> Cetak Epson A4
            </button>
            <a href="{{ request()->fullUrlWithQuery(['format' => 'thermal']) }}" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold py-2 px-3 rounded-lg shadow flex items-center gap-1.5 transition-colors">
                <i class="fas fa-tag"></i> Format Thermal (90×50)
            </a>
        @endif
        <button onclick="printThermal()" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold py-2 px-3 rounded-lg shadow flex items-center gap-1.5 transition-colors">
            <i class="fas fa-barcode"></i> Cetak Thermal 90×50
        </button>
        <button onclick="window.close()" class="bg-slate-500 hover:bg-slate-600 text-white text-xs font-bold py-2 px-3 rounded-lg shadow transition-colors">
            Tutup
        </button>
    </div>

    <!-- Assets -->
    <link rel="stylesheet" href="{{ asset('css/all.min.css') }}">
    <script src="{{ asset('js/axios.min.js') }}"></script>
    <script src="{{ asset('js/sweetalert2.all.min.js') }}"></script>

    <script>
        // Set CSRF token for Axios from cookie or meta tag if available
        const tokenMeta = document.querySelector('meta[name="csrf-token"]');
        if (tokenMeta && window.axios) {
            window.axios.defaults.headers.common['X-CSRF-TOKEN'] = tokenMeta.getAttribute('content');
        }

        window.onload = function () {
            // Auto print if requested via query parameter
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('auto_print') === '1') {
                window.print();
            }
        };

        function printThermal() {
            const ids = [ @foreach($treesList as $t) {{ $t->id }}, @endforeach ];
            
            if (ids.length === 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Tidak ada rangkaian untuk dicetak.',
                    confirmButtonColor: '#3085d6'
                });
                return;
            }

            Swal.fire({
                title: 'Konfirmasi Cetak Thermal',
                text: 'Kirim ' + ids.length + ' rangkaian ke antrean printer thermal?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Ya, Cetak!',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Memproses...',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    axios.post('{{ route("lost-wax.trees.print-thermal") }}', {
                        ids: ids.join(','),
                        _token: '{{ csrf_token() }}'
                    })
                    .then(function (response) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Berhasil',
                            text: response.data.message,
                            confirmButtonColor: '#3085d6'
                        }).then(() => {
                            window.close();
                        });
                    })
                    .catch(function (error) {
                        const errMsg = error.response && error.response.data && error.response.data.message 
                            ? error.response.data.message 
                            : 'Gagal mengirim antrean cetak.';
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: errMsg,
                            confirmButtonColor: '#3085d6'
                        });
                    });
                }
            });
        }
    </script>
</body>
</html>
