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
                font-size: 10px;
            }
            .no-print {
                display: none !important;
            }
            .print-wrapper {
                transform: scale(0.95) !important;
                transform-origin: top left !important;
                margin-left: 5.25mm !important;
                margin-top: 5mm !important;
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

    <!-- Wrapper to support 95% scale and print safety margins -->
    <div class="print-wrapper">
        <!-- Main Printable A5 Card -->
        <div class="print-page border border-slate-900 p-3 flex flex-col justify-start box-border bg-white">
            
            <!-- Header -->
            <div class="border-b border-slate-900 pb-1 flex justify-between items-center">
                <div>
                    <span class="text-[8px] uppercase font-black text-slate-500 tracking-wider block">LOST WAX ASSEMBLY - TRACEABILITY PICKING TICKET</span>
                    <h1 class="text-sm font-black text-slate-950 leading-none mt-0.5">NO. WO: {{ $workOrder->rangkai_order_number }}</h1>
                </div>
                <div class="text-right flex items-center gap-2">
                    <span class="text-[8px] font-bold text-slate-700 uppercase">Tanggal Terbit: {{ $workOrder->created_at->format('d-m-Y H:i') }}</span>
                    <span class="inline-block bg-slate-950 text-white text-[8px] font-black uppercase px-2 py-0.5 rounded">
                        STATUS: {{ $workOrder->status }}
                    </span>
                </div>
            </div>

            <!-- Details Grid -->
            <div class="grid grid-cols-[63%_37%] gap-3 my-2 items-start">
                
                <!-- Kolom Kiri: Traceability & Spec -->
                <div class="space-y-2">
                    <!-- Box Prominent: Kode Produksi & Qty Ambil -->
                    <div class="grid grid-cols-2 gap-2">
                        <div class="bg-slate-100 border border-slate-900 rounded p-1.5 text-center flex flex-col justify-center">
                            <span class="text-[8px] font-bold text-slate-500 block uppercase tracking-wider">KODE PRODUKSI</span>
                            <span class="text-lg font-extrabold text-slate-950 leading-tight break-all">{{ $line->code ?? '-' }}</span>
                        </div>
                        <div class="bg-amber-50 border border-amber-500 rounded p-1.5 text-center flex flex-col justify-center">
                            <span class="text-[8px] font-bold text-amber-700 block uppercase tracking-wider">AMBIL & RANGKAI</span>
                            <span class="text-lg font-black text-amber-900 leading-tight">{{ number_format($workOrder->qty_planned_pcs) }} PCS</span>
                        </div>
                    </div>

                    <!-- Detail Spesifikasi Produk -->
                    <div class="border border-slate-300 rounded p-1.5 bg-slate-50/30">
                        <span class="text-[8px] font-bold text-slate-500 uppercase tracking-wider block mb-1 border-b border-slate-200 pb-0.5">IDENTITAS BATCH / PRODUK</span>
                        <div class="grid grid-cols-2 gap-x-3 gap-y-0.5 text-[9px]">
                            <div class="col-span-2">
                                <span class="text-slate-500 text-[8px] uppercase font-semibold">Nama Produk:</span>
                                <span class="font-bold text-slate-900 leading-tight block break-words">{{ $line->item_name }}</span>
                            </div>
                            <div>
                                <span class="text-slate-500 text-[8px] uppercase font-semibold">Customer:</span>
                                <span class="font-bold text-slate-900 block break-words">{{ $line->customer ?? '-' }}</span>
                            </div>
                            <div>
                                <span class="text-slate-500 text-[8px] uppercase font-semibold">AISI & Ukuran:</span>
                                <span class="font-bold text-slate-900 block break-words">{{ $line->aisi ?? '-' }} · {{ $line->size ?? '-' }}</span>
                            </div>
                            <div>
                                <span class="text-slate-500 text-[8px] uppercase font-semibold">No. Print Order:</span>
                                <span class="font-bold text-slate-900 block break-words">{{ $line->printOrder->print_order_number }}</span>
                            </div>
                            <div>
                                <span class="text-slate-500 text-[8px] uppercase font-semibold">Tanggal Sch:</span>
                                <span class="font-bold text-slate-900 block break-words">{{ $line->printOrder->scheduled_date }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Catatan SPV & Parameter -->
                    <div class="grid grid-cols-12 gap-2">
                        <!-- INSTRUKSI SPV -->
                        <div class="col-span-7 bg-slate-50 border border-slate-300 rounded p-1.5 flex flex-col justify-start">
                            <span class="text-[8px] font-bold text-slate-500 uppercase tracking-wider block mb-0.5">INSTRUKSI SPV</span>
                            <span class="text-[9px] text-slate-700 italic leading-tight break-words block">{{ $workOrder->notes ?? 'Tidak ada instruksi khusus.' }}</span>
                        </div>
                        <!-- PEDOMAN TREE / L7 -->
                        <div class="col-span-5 bg-slate-50 border border-slate-300 rounded p-1.5 flex flex-col justify-center">
                            <span class="text-[8px] font-bold text-slate-500 uppercase tracking-wider block mb-0.5 text-center">PEDOMAN TREE / L7</span>
                            <div class="text-[9px] font-bold text-slate-800 text-center leading-normal">
                                <div>{{ $workOrder->tree_capacity === 1 ? ($workOrder->standard_capacity_guide ?: 20) : $workOrder->tree_capacity }} pcs/tree</div>
                                <div class="{{ $workOrder->require_layer_7 ? 'text-red-600' : 'text-slate-500' }}">Layer 7: {{ $workOrder->require_layer_7 ? 'WAJIB' : 'TIDAK' }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Kolom Kanan: Hasil Aktual & Ref Visual -->
                <div class="space-y-2 pl-1">
                    <!-- Referensi Gambar -->
                    <div class="border border-slate-300 rounded p-1.5 flex flex-col justify-start bg-white">
                        <span class="text-[8px] font-bold text-slate-500 uppercase tracking-wider block mb-1 border-b border-slate-200 pb-0.5">REFERENSI GAMBAR</span>
                        
                        <div class="grid grid-cols-2 gap-2 h-[42mm] min-h-[80px]">
                            <!-- TAMPAK DEPAN -->
                            <div class="border border-dashed border-slate-300 rounded p-1 bg-slate-50/50 flex flex-col justify-center items-center text-center overflow-hidden">
                                <span class="text-[7px] font-bold text-slate-500 block uppercase leading-none mb-1">TAMPAK DEPAN</span>
                                @if($workOrder->reference_image_path)
                                    <img src="{{ asset($workOrder->reference_image_path) }}" class="max-h-[30mm] max-w-full object-contain" alt="Tampak Depan">
                                @else
                                    <div class="w-4 h-4 rounded-full border border-dashed border-slate-400 flex items-center justify-center text-[8px] text-slate-400 mt-1">+</div>
                                @endif
                            </div>
                            
                            <!-- TAMPAK SAMPING -->
                            <div class="border border-dashed border-slate-300 rounded p-1 bg-slate-50/50 flex flex-col justify-center items-center text-center overflow-hidden">
                                <span class="text-[7px] font-bold text-slate-500 block uppercase leading-none mb-1">TAMPAK SAMPING</span>
                                @if($workOrder->reference_image_path)
                                    <img src="{{ asset($workOrder->reference_image_path) }}" class="max-h-[30mm] max-w-full object-contain" alt="Tampak Samping">
                                @else
                                    <div class="w-4 h-4 rounded-full border border-dashed border-slate-400 flex items-center justify-center text-[8px] text-slate-400 mt-1">+</div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Hasil Aktual Rangkai -->
                    <div class="border-2 border-slate-900 rounded p-1.5 bg-slate-50/30">
                        <span class="text-[8px] font-bold text-slate-950 uppercase tracking-wider block border-b border-slate-900 pb-0.5 text-center">HASIL AKTUAL RANGKAI</span>
                        <div class="grid grid-cols-2 gap-x-2 gap-y-1 mt-1 text-[9px]">
                            <div class="flex items-center">
                                <span class="text-slate-700 font-medium mr-1 whitespace-nowrap">Qty Diambil:</span>
                                <span class="border-b border-slate-900 flex-grow h-3.5 text-center font-bold"></span>
                                <span class="text-slate-500 ml-0.5">pcs</span>
                            </div>
                            <div class="flex items-center">
                                <span class="text-slate-700 font-medium mr-1 whitespace-nowrap">Qty Good:</span>
                                <span class="border-b border-slate-900 flex-grow h-3.5 text-center font-bold"></span>
                                <span class="text-slate-500 ml-0.5">pcs</span>
                            </div>
                            <div class="flex items-center">
                                <span class="text-slate-700 font-medium mr-1 whitespace-nowrap">Qty Defect:</span>
                                <span class="border-b border-slate-900 flex-grow h-3.5 text-center font-bold"></span>
                                <span class="text-slate-500 ml-0.5">pcs</span>
                            </div>
                            <div class="flex items-center">
                                <span class="text-slate-700 font-medium mr-1 whitespace-nowrap">Tanggal:</span>
                                <span class="border-b border-slate-900 flex-grow h-3.5 text-center font-bold"></span>
                            </div>
                            <div class="flex items-center col-span-2 mt-0.5">
                                <span class="text-slate-700 font-medium mr-1 whitespace-nowrap">Jam:</span>
                                <span class="text-slate-500 mr-0.5">Mulai:</span>
                                <span class="border-b border-slate-900 w-12 h-3.5 text-center font-bold mr-2"></span>
                                <span class="text-slate-500 mr-0.5">Selesai:</span>
                                <span class="border-b border-slate-900 w-12 h-3.5 text-center font-bold"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Signatures / Verifikasi -->
            <div class="grid grid-cols-3 gap-4 border-t border-slate-900 pt-2 mt-3">
                <div class="flex flex-col items-center justify-between h-16">
                    <span class="text-slate-500 font-bold uppercase text-[8px]">OPERATOR RANGKAI:</span>
                    <div class="flex-grow"></div>
                    <div class="border-b border-slate-900 w-[75%]"></div>
                </div>
                <div class="flex flex-col items-center justify-between h-16">
                    <span class="text-slate-500 font-bold uppercase text-[8px]">SUPERVISOR RANGKAI:</span>
                    <div class="flex-grow"></div>
                    <div class="border-b border-slate-900 w-[75%]"></div>
                </div>
                <div class="flex flex-col items-center justify-between h-16">
                    <span class="text-slate-550 font-bold uppercase text-[8px]">PPIC / ADMIN:</span>
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
