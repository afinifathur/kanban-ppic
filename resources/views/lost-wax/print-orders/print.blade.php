<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perintah Cetak Lilin - {{ $printOrder->print_order_number }}</title>
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
    <style>
        @media print {
            body {
                background: #fff !important;
                color: #000 !important;
                -webkit-print-color-adjust: exact;
            }
            @page {
                size: A4 portrait;
                margin: 5mm;
            }
            .no-print {
                display: none !important;
            }
        }
        body {
            font-family: 'Times New Roman', Times, serif;
            background-color: #f8fafc;
        }
    </style>
</head>
<body class="p-4 bg-slate-50 print:bg-white print:p-0" onload="window.print()">

    <div class="max-w-4xl mx-auto bg-white p-6 shadow-sm border border-slate-200 print:border-none print:shadow-none print:w-full print:p-0">
        
        <!-- ========================================== -->
        <!-- FORM 1: FORM LAPORAN KERJA CETAK LILIN     -->
        <!-- ========================================== -->
        <div class="text-center mb-2 border-b border-black pb-1">
            <h1 class="text-base font-extrabold uppercase tracking-wider">FORM LAPORAN KERJA CETAK LILIN</h1>
        </div>

        <!-- Metadata & Handwriting Fields (Form 1) -->
        <div class="grid grid-cols-4 gap-2 mb-2 text-xs border border-black p-2 font-bold">
            <div>
                <p class="text-[9px] text-slate-500 uppercase print:text-black">NO. DOKUMEN</p>
                <p class="font-mono text-sm">{{ $printOrder->print_order_number }}</p>
            </div>
            <div>
                <p class="text-[9px] text-slate-500 uppercase print:text-black">TANGGAL PERINTAH</p>
                <p class="text-sm">{{ $printOrder->scheduled_date->format('d/m/Y') }}</p>
            </div>
            <div class="col-span-2 grid grid-cols-2 gap-1 border-l border-black pl-2">
                <div class="flex items-center gap-1">
                    <span class="text-[9px] text-slate-500 uppercase print:text-black shrink-0">MESIN :</span>
                    <span class="border-b border-black flex-1 h-4"></span>
                </div>
                <div class="flex items-center gap-1">
                    <span class="text-[9px] text-slate-500 uppercase print:text-black shrink-0">SHIFT :</span>
                    <span class="border-b border-black flex-1 h-4"></span>
                </div>
                <div class="flex items-center gap-1">
                    <span class="text-[9px] text-slate-500 uppercase print:text-black shrink-0">HARI :</span>
                    <span class="border-b border-black flex-1 h-4"></span>
                </div>
                <div class="flex items-center gap-1">
                    <span class="text-[9px] text-slate-500 uppercase print:text-black shrink-0">TGL AKTUAL :</span>
                    <span class="border-b border-black flex-1 h-4"></span>
                </div>
            </div>
        </div>

        <!-- Table Form 1 -->
        <table class="w-full border-collapse border border-black text-xs mb-2 table-fixed">
            <thead>
                <tr class="bg-slate-100 text-black border-b border-black font-bold text-[9px] uppercase text-center">
                    <th class="border border-black p-1 w-[4%]">NO</th>
                    <th class="border border-black p-1 w-[15%]">NO.PO / SPK</th>
                    <th class="border border-black p-1 text-left w-[36%]">NAMA PRODUK</th>
                    <th class="border border-black p-1 w-[10%]">UKURAN</th>
                    <th class="border border-black p-1 w-[10%]">QTY PRODUKSI</th>
                    <th class="border border-black p-1 w-[6%]">AISI</th>
                    <th class="border border-black p-1 w-[6%]">LOGO</th>
                    <th class="border border-black p-1 w-[7%]">INIT CUST</th>
                    <th class="border border-black p-1 w-[7%]">HASIL</th>
                    <th class="border border-black p-1 w-[7%]">RUSAK</th>
                </tr>
            </thead>
            <tbody>
                @foreach($printOrder->lines->take(10) as $index => $line)
                    <tr class="h-6">
                        <td class="border border-black p-1 text-center font-bold">{{ $index + 1 }}</td>
                        <td class="border border-black p-1 font-mono text-center truncate">{{ $line->code ?: '-' }}</td>
                        <td class="border border-black p-1 font-bold leading-tight break-words text-[10px]">{{ $line->item_name }}</td>
                        <td class="border border-black p-1 text-center font-mono truncate">{{ $line->size ?: '-' }}</td>
                        <td class="border border-black p-1 text-center font-bold">{{ number_format($line->qty_ordered) }}</td>
                        <td class="border border-black p-1 text-center font-mono truncate">{{ $line->isi ?: '-' }}</td>
                        <td class="border border-black p-1 text-center"></td>
                        <td class="border border-black p-1 text-center uppercase font-bold truncate">{{ $line->customer ?: '-' }}</td>
                        <td class="border border-black p-1 text-center"></td>
                        <td class="border border-black p-1 text-center"></td>
                    </tr>
                @endforeach
                @for($i = count($printOrder->lines); $i < 10; $i++)
                    <tr class="h-6">
                        <td class="border border-black p-1 text-center font-bold text-slate-300">{{ $i + 1 }}</td>
                        <td class="border border-black p-1"></td>
                        <td class="border border-black p-1"></td>
                        <td class="border border-black p-1"></td>
                        <td class="border border-black p-1"></td>
                        <td class="border border-black p-1"></td>
                        <td class="border border-black p-1"></td>
                        <td class="border border-black p-1"></td>
                        <td class="border border-black p-1"></td>
                        <td class="border border-black p-1"></td>
                    </tr>
                @endfor
            </tbody>
        </table>

        <!-- Signatures (Form 1) -->
        <div class="grid grid-cols-3 text-center text-xs font-bold mt-2">
            <div>
                <p class="mb-6">Operator Cetak Lilin</p>
                <div class="border-t border-black w-32 mx-auto"></div>
                <p class="text-[9px] font-normal mt-0.5">( Nama Terang )</p>
            </div>
            <div>
                <p class="mb-6">Supervisor (SPV)</p>
                <div class="border-t border-black w-32 mx-auto"></div>
                <p class="text-[9px] font-normal mt-0.5">( Nama Terang )</p>
            </div>
            <div>
                <p class="mb-6">Admin PPIC</p>
                <div class="border-t border-black w-32 mx-auto text-slate-700"></div>
                <p class="text-[9px] font-bold mt-0.5">{{ optional($printOrder->creator)->name }}</p>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- DIVIDER                                    -->
        <!-- ========================================== -->
        <div class="border-t border-dashed border-black my-3"></div>

        <!-- ========================================== -->
        <!-- FORM 2: FORM SETTING MESIN CETAK           -->
        <!-- ========================================== -->
        <div class="text-center mb-2">
            <h1 class="text-base font-extrabold uppercase tracking-wider">FORM SETTING MESIN CETAK</h1>
        </div>

        <!-- Metadata & Handwriting Fields (Form 2) -->
        <div class="grid grid-cols-5 gap-2 mb-2 text-xs border border-black p-2 font-bold">
            <div>
                <p class="text-[9px] text-slate-500 uppercase print:text-black">NO. DOKUMEN</p>
                <p class="font-mono text-sm leading-none mt-0.5">{{ $printOrder->print_order_number }}</p>
            </div>
            <div>
                <p class="text-[9px] text-slate-500 uppercase print:text-black">TANGGAL PERINTAH</p>
                <p class="text-sm leading-none mt-0.5">{{ $printOrder->scheduled_date->format('d/m/Y') }}</p>
            </div>
            <div class="col-span-3 grid grid-cols-3 gap-1 border-l border-black pl-2">
                <div class="flex items-center gap-1">
                    <span class="text-[9px] text-slate-500 uppercase print:text-black shrink-0">NAMA OPERATOR :</span>
                    <span class="border-b border-black flex-1 h-4"></span>
                </div>
                <div class="flex items-center gap-1">
                    <span class="text-[9px] text-slate-500 uppercase print:text-black shrink-0">MESIN :</span>
                    <span class="border-b border-black flex-1 h-4"></span>
                </div>
                <div class="flex items-center gap-1">
                    <span class="text-[9px] text-slate-500 uppercase print:text-black shrink-0">TANGGAL :</span>
                    <span class="border-b border-black flex-1 h-4"></span>
                </div>
            </div>
        </div>

        <!-- Table Form 2 -->
        <table class="w-full border-collapse border border-black text-xs mb-2 table-fixed">
            <thead>
                <tr class="bg-slate-100 text-black border-b border-black font-bold text-[8px] uppercase text-center leading-tight">
                    <th class="border border-black p-1 w-[4%]">NO</th>
                    <th class="border border-black p-1 w-[12%]">KODE</th>
                    <th class="border border-black p-1 text-left w-[28%]">ITEM</th>
                    <th class="border border-black p-1 w-[8%]">SIZE</th>
                    <th class="border border-black p-1 w-[7%]">TEMP. LILIN</th>
                    <th class="border border-black p-1 w-[6%]">PRESS 1</th>
                    <th class="border border-black p-1 w-[6%]">PRESS 2</th>
                    <th class="border border-black p-1 w-[6%]">COOLING</th>
                    <th class="border border-black p-1 w-[5%]">CLAMP</th>
                    <th class="border border-black p-1 w-[6%]">BONGKAR</th>
                    <th class="border border-black p-1 w-[7%]">HASIL CETAK</th>
                    <th class="border border-black p-1 w-[5%]">RUSAK</th>
                    <th class="border border-black p-1 w-[5%]">JAM AWAL</th>
                    <th class="border border-black p-1 w-[5%]">JAM AKHIR</th>
                </tr>
            </thead>
            <tbody>
                @foreach($printOrder->lines->take(10) as $index => $line)
                    <tr class="h-6">
                        <td class="border border-black p-1 text-center font-bold">{{ $index + 1 }}</td>
                        <td class="border border-black p-1 font-mono text-center truncate">{{ $line->code ?: '-' }}</td>
                        <td class="border border-black p-1 font-bold leading-tight break-words text-[10px]">{{ $line->item_name }}</td>
                        <td class="border border-black p-1 text-center font-mono truncate">{{ $line->size ?: '-' }}</td>
                        <td class="border border-black p-1 text-center"></td>
                        <td class="border border-black p-1 text-center"></td>
                        <td class="border border-black p-1 text-center"></td>
                        <td class="border border-black p-1 text-center"></td>
                        <td class="border border-black p-1 text-center"></td>
                        <td class="border border-black p-1 text-center"></td>
                        <td class="border border-black p-1 text-center"></td>
                        <td class="border border-black p-1 text-center"></td>
                        <td class="border border-black p-1 text-center"></td>
                        <td class="border border-black p-1 text-center"></td>
                    </tr>
                @endforeach
                @for($i = count($printOrder->lines); $i < 10; $i++)
                    <tr class="h-6">
                        <td class="border border-black p-1 text-center font-bold text-slate-300">{{ $i + 1 }}</td>
                        <td class="border border-black p-1"></td>
                        <td class="border border-black p-1"></td>
                        <td class="border border-black p-1"></td>
                        <td class="border border-black p-1"></td>
                        <td class="border border-black p-1"></td>
                        <td class="border border-black p-1"></td>
                        <td class="border border-black p-1"></td>
                        <td class="border border-black p-1"></td>
                        <td class="border border-black p-1"></td>
                        <td class="border border-black p-1"></td>
                        <td class="border border-black p-1"></td>
                        <td class="border border-black p-1"></td>
                        <td class="border border-black p-1"></td>
                    </tr>
                @endfor
            </tbody>
        </table>

        <!-- Form 2 Bottom: Kendala & TTD Operator -->
        <div class="grid grid-cols-3 gap-2 mt-2 text-xs">
            <!-- Kotak Kendala -->
            <div class="col-span-2 border border-black p-1.5 h-16 relative">
                <span class="text-[9px] text-slate-500 uppercase font-bold absolute top-1 left-2">KENDALA :</span>
            </div>
            <!-- Kotak TTD -->
            <div class="border border-black p-1.5 h-16 flex flex-col justify-between text-center font-bold">
                <span class="text-[9px] text-slate-500 uppercase font-bold block">Tanda Tangan Operator</span>
                <div class="border-t border-dashed border-black w-24 mx-auto mb-1"></div>
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
