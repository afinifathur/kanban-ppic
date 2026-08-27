<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perintah Rangkai - {{ $workOrder->rangkai_order_number }}</title>
    <!-- Tailwind CSS Standalone -->
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
    <style>
        @page {
            size: A4 portrait;
            margin: 0;
        }
        @media print {
            html, body {
                margin: 0 !important;
                padding: 0 !important;
            }
            body {
                display: block !important;
                background: white;
                color: black;
                font-size: 9.5px;
            }
            .no-print {
                display: none !important;
            }
            .print-wrapper {
                transform: scale(0.90) !important;
                transform-origin: top left !important;
                margin-left: 5.25mm !important;
                margin-top: 4mm !important;
                display: block !important;
                width: 210mm !important;
                height: 148mm !important;
            }
            .print-page {
                width: 210mm !important;
                height: 148mm !important;
                max-width: 210mm !important;
                max-height: 148mm !important;
                border: none !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                page-break-after: avoid;
                break-after: avoid;
            }
            .cut-guide {
                display: block !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 210mm !important;
                page-break-inside: avoid;
            }
        }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: #f8fafc;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .print-wrapper {
            display: block;
        }
        .print-page {
            width: 210mm;
            height: 148mm;
            max-width: 210mm;
            max-height: 148mm;
            background: white;
            box-sizing: border-box;
        }
        .cut-guide {
            display: none;
            width: 210mm;
            border-top: 1px dashed #cbd5e1;
            height: 0;
        }
    </style>
</head>
<body class="p-0 sm:p-4 flex justify-center items-center">

    <!-- Top Action Bar for web viewing only -->
    <div class="no-print fixed top-4 right-4 flex gap-2">
        <button onclick="window.print()" class="bg-amber-600 hover:bg-amber-700 text-white font-bold text-xs py-2 px-4 rounded-lg shadow transition-all flex items-center gap-1.5">
            Cetak Dokumen
        </button>
        <button onclick="window.close()" class="bg-slate-800 hover:bg-slate-900 text-white font-bold text-xs py-2 px-4 rounded-lg shadow transition-all flex items-center gap-1.5">
            Tutup
        </button>
    </div>

    <!-- Wrapper to support 90% scale and print safety margins -->
    <div class="print-wrapper">
        <!-- Main Printable A5 Card -->
        <div class="print-page border border-slate-900 p-2.5 flex flex-col justify-start box-border bg-white">
            
            <!-- Header -->
            <div class="border-b border-slate-900 pb-0.5 flex justify-between items-center">
                <div>
                    <span class="text-[7.5px] uppercase font-black text-slate-500 tracking-wider block leading-none">LOST WAX ASSEMBLY - TRACEABILITY PICKING TICKET</span>
                    <h1 class="text-xs font-black text-slate-950 leading-none mt-0.5">NO. WO: {{ $workOrder->rangkai_order_number }}</h1>
                </div>
                <div class="text-right flex items-center gap-2">
                    <span class="text-[7.5px] font-bold text-slate-700 uppercase">Tanggal Terbit: {{ $workOrder->created_at->format('d-m-Y H:i') }}</span>
                    <span class="inline-block bg-slate-950 text-white text-[7.5px] font-black uppercase px-1.5 py-0.5 rounded leading-none">
                        STATUS: {{ $workOrder->status }}
                    </span>
                </div>
            </div>

            <!-- Details Grid -->
            <div class="grid grid-cols-[63%_37%] gap-2.5 my-1.5 items-start">
                
                <!-- Kolom Kiri: Traceability & Spec -->
                <div class="space-y-1.5">
                    <!-- Box Prominent: Kode Produksi & Qty Ambil -->
                    <div class="grid grid-cols-2 gap-1.5">
                        <div class="bg-slate-100 border border-slate-900 rounded p-1 text-center flex flex-col justify-center">
                            <span class="text-[7.5px] font-bold text-slate-500 block uppercase tracking-wider leading-tight">KODE PRODUKSI</span>
                            <span class="text-base font-extrabold text-slate-950 leading-tight break-all">{{ $line->code ?? '-' }}</span>
                        </div>
                        <div class="bg-amber-50 border border-amber-500 rounded p-1 text-center flex flex-col justify-center">
                            <span class="text-[7.5px] font-bold text-amber-700 block uppercase tracking-wider leading-tight">AMBIL & RANGKAI</span>
                            <span class="text-base font-black text-amber-900 leading-tight">{{ number_format($workOrder->qty_planned_pcs) }} PCS</span>
                        </div>
                    </div>

                    <!-- Detail Spesifikasi Produk -->
                    <div class="border border-slate-300 rounded p-1.5 bg-slate-50/30">
                        <span class="text-[7.5px] font-bold text-slate-500 uppercase tracking-wider block mb-0.5 border-b border-slate-200 pb-0.5 leading-none">IDENTITAS BATCH / PRODUK</span>
                        <div class="grid grid-cols-2 gap-x-2 gap-y-0.5 text-[8.5px]">
                            <div class="col-span-2">
                                <span class="text-slate-500 text-[7.5px] uppercase font-semibold">Nama Produk:</span>
                                <span class="font-bold text-slate-950 text-[13px] leading-tight block break-words">{{ $line->item_name }}</span>
                            </div>
                            <div>
                                <span class="text-slate-500 text-[7.5px] uppercase font-semibold">Customer:</span>
                                <span class="font-bold text-slate-900 block break-words leading-tight">{{ $line->customer ?? '-' }}</span>
                            </div>
                            <div>
                                <span class="text-slate-500 text-[7.5px] uppercase font-semibold">AISI & Ukuran:</span>
                                <span class="font-bold text-slate-900 block break-words leading-tight">{{ $line->aisi ?? '-' }} · {{ $line->size ?? '-' }}</span>
                            </div>
                            <div>
                                <span class="text-slate-500 text-[7.5px] uppercase font-semibold">No. Print Order:</span>
                                <span class="font-bold text-slate-900 block break-words leading-tight">{{ $line->printOrder->print_order_number }}</span>
                            </div>
                            <div>
                                <span class="text-slate-500 text-[7.5px] uppercase font-semibold">Tanggal Sch:</span>
                                <span class="font-bold text-slate-900 block break-words leading-tight">{{ $line->printOrder->scheduled_date }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Catatan SPV & Informasi Rangkai -->
                    <div class="grid grid-cols-12 gap-1.5">
                        <!-- INSTRUKSI SPV -->
                        <div class="col-span-6 bg-slate-50 border border-slate-300 rounded p-1 flex flex-col justify-start">
                            <span class="text-[7.5px] font-bold text-slate-500 uppercase tracking-wider block mb-0.5 leading-tight">INSTRUKSI SPV</span>
                            <span class="text-[8px] text-slate-700 italic leading-snug break-words block">{{ $workOrder->notes ?? 'Tidak ada instruksi khusus.' }}</span>
                        </div>
                        <!-- INFORMASI RANGKAI -->
                        <div class="col-span-6 bg-slate-50 border border-slate-300 rounded p-1 flex flex-col justify-center">
                            <span class="text-[7.5px] font-bold text-slate-500 uppercase tracking-wider block mb-0.5 text-center leading-tight">INFORMASI RANGKAI</span>
                            <div class="text-[8px] text-slate-900 leading-snug space-y-0.5">
                                <div class="flex items-center justify-between">
                                    <span class="whitespace-nowrap">• Setiap rangkai (tree) berisi :</span>
                                    <span class="flex items-center">
                                        <span class="border-b border-slate-900 w-8 inline-block h-3"></span>
                                        <span class="text-slate-500 ml-0.5">pcs</span>
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="whitespace-nowrap">• Jumlah Rangkaian :</span>
                                    <span class="flex items-center">
                                        <span class="border-b border-slate-900 w-8 inline-block h-3"></span>
                                        <span class="text-slate-500 ml-0.5">rangkai</span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Kolom Kanan: Hasil Aktual & Ref Visual -->
                <div class="space-y-1.5 pl-0.5">
                    <!-- Referensi Gambar -->
                    <div class="border border-slate-300 rounded p-1 flex flex-col justify-start bg-white">
                        <span class="text-[7.5px] font-bold text-slate-500 uppercase tracking-wider block mb-0.5 border-b border-slate-200 pb-0.5 leading-none">REFERENSI GAMBAR</span>
                        
                        <div class="grid grid-cols-2 gap-1.5 h-[34mm] min-h-[60px]">
                            <!-- TAMPAK DEPAN -->
                            <div class="border border-dashed border-slate-300 rounded p-0.5 bg-slate-50/50 flex flex-col justify-center items-center text-center overflow-hidden">
                                <span class="text-[6.5px] font-bold text-slate-500 block uppercase leading-none mb-0.5">TAMPAK DEPAN</span>
                                @if(!empty($assemblyPhoto?->front_image_url))
                                    <img src="{{ $assemblyPhoto->front_image_url }}" class="max-h-[25mm] max-w-full object-contain" alt="Tampak Depan">
                                @elseif(!empty($workOrder->reference_image_path))
                                    <img src="{{ asset($workOrder->reference_image_path) }}" class="max-h-[25mm] max-w-full object-contain" alt="Tampak Depan">
                                @else
                                    <div class="flex flex-col items-center justify-center p-1 text-center h-full">
                                        <span class="text-[6.5px] font-bold text-slate-400 leading-tight">FOTO BELUM TERSEDIA</span>
                                    </div>
                                @endif
                            </div>
                            
                            <!-- TAMPAK SAMPING -->
                            <div class="border border-dashed border-slate-300 rounded p-0.5 bg-slate-50/50 flex flex-col justify-center items-center text-center overflow-hidden">
                                <span class="text-[6.5px] font-bold text-slate-500 block uppercase leading-none mb-0.5">TAMPAK SAMPING</span>
                                @if(!empty($assemblyPhoto?->side_image_url))
                                    <img src="{{ $assemblyPhoto->side_image_url }}" class="max-h-[25mm] max-w-full object-contain" alt="Tampak Samping">
                                @elseif(!empty($workOrder->reference_image_path))
                                    <img src="{{ asset($workOrder->reference_image_path) }}" class="max-h-[25mm] max-w-full object-contain" alt="Tampak Samping">
                                @else
                                    <div class="flex flex-col items-center justify-center p-1 text-center h-full">
                                        <span class="text-[6.5px] font-bold text-slate-400 leading-tight">FOTO BELUM TERSEDIA</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Hasil Aktual Rangkai -->
                    <div class="border-2 border-slate-900 rounded p-1 bg-slate-50/30">
                        <span class="text-[7.5px] font-bold text-slate-950 uppercase tracking-wider block border-b border-slate-900 pb-0.5 text-center leading-tight">HASIL AKTUAL RANGKAI</span>
                        <div class="grid grid-cols-2 gap-x-1.5 gap-y-0.5 mt-0.5 text-[8px]">
                            <div class="flex items-center">
                                <span class="text-slate-700 font-medium mr-1 whitespace-nowrap">Qty Diambil:</span>
                                <span class="border-b border-slate-900 flex-grow h-3 text-center font-bold"></span>
                                <span class="text-slate-500 ml-0.5">pcs</span>
                            </div>
                            <div class="flex items-center">
                                <span class="text-slate-700 font-medium mr-1 whitespace-nowrap">Qty Good:</span>
                                <span class="border-b border-slate-900 flex-grow h-3 text-center font-bold"></span>
                                <span class="text-slate-500 ml-0.5">pcs</span>
                            </div>
                            <div class="flex items-center">
                                <span class="text-slate-700 font-medium mr-1 whitespace-nowrap">Qty Defect:</span>
                                <span class="border-b border-slate-900 flex-grow h-3 text-center font-bold"></span>
                                <span class="text-slate-500 ml-0.5">pcs</span>
                            </div>
                            <div class="flex items-center">
                                <span class="text-slate-700 font-medium mr-1 whitespace-nowrap">Tanggal:</span>
                                <span class="border-b border-slate-900 flex-grow h-3 text-center font-bold"></span>
                            </div>
                            <div class="flex items-center col-span-2 mt-0.5">
                                <span class="text-slate-700 font-medium mr-1 whitespace-nowrap">Jam:</span>
                                <span class="text-slate-500 mr-0.5">Mulai:</span>
                                <span class="border-b border-slate-900 w-10 h-3 text-center font-bold mr-1.5"></span>
                                <span class="text-slate-500 mr-0.5">Selesai:</span>
                                <span class="border-b border-slate-900 w-10 h-3 text-center font-bold"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Signatures / Verifikasi -->
            <div class="grid grid-cols-3 gap-3 border-t border-slate-900 pt-1 mt-1.5">
                <div class="flex flex-col items-center justify-between h-12">
                    <span class="text-slate-500 font-bold uppercase text-[7.5px] leading-none">OPERATOR RANGKAI:</span>
                    <div class="flex-grow"></div>
                    <div class="border-b border-slate-900 w-[75%]"></div>
                </div>
                <div class="flex flex-col items-center justify-between h-12">
                    <span class="text-slate-500 font-bold uppercase text-[7.5px] leading-none">SUPERVISOR RANGKAI:</span>
                    <div class="flex-grow"></div>
                    <div class="border-b border-slate-900 w-[75%]"></div>
                </div>
                <div class="flex flex-col items-center justify-between h-12">
                    <span class="text-slate-500 font-bold uppercase text-[7.5px] leading-none">PPIC / ADMIN:</span>
                    <div class="flex-grow"></div>
                    <div class="border-b border-slate-900 w-[75%]"></div>
                </div>
            </div>

        </div>

        <!-- Cutting guide (print only) -->
        <div class="cut-guide"></div>
    </div>

</body>
</html>
