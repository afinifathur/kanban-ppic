<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Kerja Cetak Lilin - {{ $printOrder->print_order_number }}</title>
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
    <style>
        @media print {
            body {
                background: #fff !important;
                color: #000 !important;
                -webkit-print-color-adjust: exact;
            }
            @page {
                size: A4 landscape;
                margin: 8mm;
            }
            .no-print {
                display: none !important;
            }
            .print-border {
                border-color: #000000 !important;
            }
        }
        body {
            font-family: 'Times New Roman', Times, serif;
            background-color: #f8fafc;
        }
    </style>
</head>
<body class="p-6 bg-slate-50 print:bg-white print:p-0" onload="window.print()">

    <div class="max-w-6xl mx-auto bg-white p-6 shadow-sm border border-slate-200 print:border-none print:shadow-none print:w-full print:p-0">
        
        <!-- Document Title Header -->
        <div class="text-center mb-6 border-b-2 border-black pb-4">
            <h1 class="text-2xl font-bold uppercase tracking-wider">FORM LAPORAN KERJA CETAK LILIN</h1>
            <p class="text-xs text-slate-500 font-mono mt-1 no-print">Sistem FIFO Tracking | No. Dokumen: {{ $printOrder->print_order_number }}</p>
        </div>

        <!-- Metadata & Manual Handwriting Fields -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6 text-sm font-bold border border-black p-4 rounded print:border-black print:rounded-none">
            <div>
                <p class="text-slate-500 text-xs font-bold uppercase print:text-black">NO. DOKUMEN</p>
                <p class="text-base font-mono">{{ $printOrder->print_order_number }}</p>
            </div>
            <div>
                <p class="text-slate-500 text-xs font-bold uppercase print:text-black">TANGGAL PERINTAH</p>
                <p class="text-base font-medium">{{ $printOrder->scheduled_date->format('d/m/Y') }}</p>
            </div>
            <div class="col-span-2 grid grid-cols-2 gap-2 border-l border-slate-300 pl-4 print:border-black">
                <div>
                    <span class="text-xs text-slate-400 print:text-black uppercase block">MESIN :</span>
                    <span class="inline-block w-full border-b border-black h-5 mt-1"></span>
                </div>
                <div>
                    <span class="text-xs text-slate-400 print:text-black uppercase block">SHIFT :</span>
                    <span class="inline-block w-full border-b border-black h-5 mt-1"></span>
                </div>
                <div>
                    <span class="text-xs text-slate-400 print:text-black uppercase block">HARI :</span>
                    <span class="inline-block w-full border-b border-black h-5 mt-1"></span>
                </div>
                <div>
                    <span class="text-xs text-slate-400 print:text-black uppercase block">TANGGAL (AKTUAL) :</span>
                    <span class="inline-block w-full border-b border-black h-5 mt-1"></span>
                </div>
            </div>
        </div>

        <!-- 10 Columns Table -->
        <table class="w-full border-collapse border border-black mb-8 text-sm">
            <thead>
                <tr class="bg-slate-100 text-black border-b border-black font-bold text-xs uppercase">
                    <th class="border border-black px-2 py-2 text-center w-8">NO</th>
                    <th class="border border-black px-2 py-2 text-center w-36">NO.PO / SPK</th>
                    <th class="border border-black px-2 py-2 text-left">NAMA PRODUK</th>
                    <th class="border border-black px-2 py-2 text-center w-24">UKURAN</th>
                    <th class="border border-black px-2 py-2 text-center w-28">QTY PRODUKSI</th>
                    <th class="border border-black px-2 py-2 text-center w-16">AISI</th>
                    <th class="border border-black px-2 py-2 text-center w-24">LOGO PROD</th>
                    <th class="border border-black px-2 py-2 text-center w-24">INITIAL CUST</th>
                    <th class="border border-black px-2 py-2 text-center w-20 text-blue-600 print:text-black font-extrabold">HASIL</th>
                    <th class="border border-black px-2 py-2 text-center w-20 text-red-600 print:text-black font-extrabold">RUSAK</th>
                </tr>
            </thead>
            <tbody>
                @foreach($printOrder->lines as $index => $line)
                    <tr class="h-10 text-xs">
                        <td class="border border-black px-2 py-1 text-center font-bold">{{ $index + 1 }}</td>
                        <!-- NO.PO / SPK = Kode Cust snapshot -->
                        <td class="border border-black px-2 py-1 font-mono text-center">{{ $line->code ?: '-' }}</td>
                        <!-- NAMA PRODUK = Item Name snapshot -->
                        <td class="border border-black px-2 py-1 font-bold">{{ $line->item_name }}</td>
                        <!-- UKURAN = Size snapshot -->
                        <td class="border border-black px-2 py-1 text-center font-mono">{{ $line->size ?: '-' }}</td>
                        <!-- QTY PRODUKSI = Qty Perintah Cetak -->
                        <td class="border border-black px-2 py-1 text-center font-bold text-sm">{{ number_format($line->qty_ordered) }}</td>
                        <!-- AISI = AISI snapshot -->
                        <td class="border border-black px-2 py-1 text-center font-mono">{{ $line->aisi ?: '-' }}</td>
                        <!-- LOGO PROD = Manual Column -->
                        <td class="border border-black px-2 py-1 text-center"></td>
                        <!-- INITIAL CUST = Customer snapshot -->
                        <td class="border border-black px-2 py-1 text-center uppercase font-bold">{{ $line->customer ?: '-' }}</td>
                        <!-- HASIL = Manual Column -->
                        <td class="border border-black px-2 py-1 text-center"></td>
                        <!-- RUSAK = Manual Column -->
                        <td class="border border-black px-2 py-1 text-center"></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Signatures & Verification Section -->
        <div class="grid grid-cols-3 text-center mt-12 text-sm font-bold">
            <div>
                <p class="mb-20">Operator Cetak Lilin</p>
                <div class="border-t border-black w-44 mx-auto"></div>
                <p class="text-xs font-normal mt-1">( Nama Terang )</p>
            </div>
            <div>
                <p class="mb-20">Supervisor (SPV)</p>
                <div class="border-t border-black w-44 mx-auto"></div>
                <p class="text-xs font-normal mt-1">( Nama Terang )</p>
            </div>
            <div>
                <p class="mb-20">Admin PPIC</p>
                <div class="border-t border-black w-44 mx-auto"></div>
                <p class="text-xs font-normal mt-1">{{ optional($printOrder->creator)->name }}</p>
            </div>
        </div>
    </div>

    <!-- Print control bar for screen viewing -->
    <div class="no-print fixed bottom-6 left-1/2 -translate-x-1/2 flex gap-3 shadow-lg p-3 bg-white border border-slate-200 rounded-xl z-50">
        <button onclick="window.print()" class="bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold py-2 px-6 rounded-lg shadow transition-all">
            <i class="fas fa-print mr-1"></i> Cetak Ulang
        </button>
        <button onclick="window.close()" class="bg-slate-600 hover:bg-slate-700 text-white text-sm font-bold py-2 px-6 rounded-lg shadow transition-all">
            Tutup
        </button>
    </div>

    <link rel="stylesheet" href="{{ asset('css/all.min.css') }}">
</body>
</html>
