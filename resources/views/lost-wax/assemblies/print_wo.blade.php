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
                page-break-inside: avoid;
            }
        }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: #f8fafc;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
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

    <!-- Main Printable A5 Card -->
    <div class="print-page border-2 border-slate-900 rounded-lg p-4 flex flex-col justify-between box-border">
        
        <!-- Header -->
        <div class="border-b-2 border-slate-950 pb-1.5 flex justify-between items-start">
            <div>
                <span class="text-[8px] uppercase font-black text-slate-500 tracking-wider">LOST WAX ASSEMBLY - TRACEABILITY PICKING TICKET</span>
                <h1 class="text-base font-black text-slate-950 leading-none mt-1">NO. WO: {{ $workOrder->rangkai_order_number }}</h1>
            </div>
            <div class="text-right">
                <span class="text-[8px] font-bold text-slate-700 uppercase block">Tanggal Terbit: {{ $workOrder->created_at->format('d-m-Y H:i') }}</span>
                <span class="inline-block bg-slate-950 text-white text-[8px] font-black uppercase px-2 py-0.5 rounded mt-0.5">
                    STATUS: {{ $workOrder->status }}
                </span>
            </div>
        </div>

        <!-- Details Grid -->
        <div class="grid grid-cols-12 gap-3 my-2 flex-grow">
            
            <!-- Kolom Kiri: Traceability & Spec (Prominent) -->
            <div class="col-span-8 flex flex-col justify-between border-r border-slate-300 pr-3">
                
                <!-- Box Prominent: Kode Produksi & Qty Ambil -->
                <div class="grid grid-cols-2 gap-2">
                    <div class="bg-slate-100 border-2 border-slate-950 rounded p-2 text-center flex flex-col justify-center">
                        <span class="text-[9px] font-extrabold text-slate-600 block uppercase tracking-wider">KODE PRODUKSI</span>
                        <span class="text-xl font-black text-slate-950 leading-tight break-all">{{ $line->code ?? '-' }}</span>
                    </div>
                    <div class="bg-amber-50 border-2 border-amber-500 rounded p-2 text-center flex flex-col justify-center">
                        <span class="text-[9px] font-extrabold text-amber-700 block uppercase tracking-wider">AMBIL & RANGKAI</span>
                        <span class="text-xl font-black text-amber-900 leading-tight">{{ number_format($workOrder->qty_planned_pcs) }} PCS</span>
                    </div>
                </div>

                <!-- Detail Spesifikasi Produk -->
                <div class="border border-slate-300 rounded p-2 mt-2">
                    <span class="text-[8px] font-black text-slate-500 uppercase tracking-wider block mb-1 border-b border-slate-200 pb-0.5">IDENTITAS BATCH / PRODUK</span>
                    <div class="grid grid-cols-2 gap-x-2 gap-y-1 text-[10px]">
                        <div class="col-span-2">
                            <span class="text-slate-500 text-[8px] block leading-none">NAMA PRODUK:</span>
                            <span class="font-bold text-slate-900 break-words leading-tight block">{{ $line->item_name }}</span>
                        </div>
                        <div>
                            <span class="text-slate-500 text-[8px] block leading-none">CUSTOMER:</span>
                            <span class="font-bold text-slate-900">{{ $line->customer ?? '-' }}</span>
                        </div>
                        <div>
                            <span class="text-slate-500 text-[8px] block leading-none">AISI & UKURAN:</span>
                            <span class="font-bold text-slate-900">{{ $line->aisi ?? '-' }} &middot; {{ $line->size ?? '-' }}</span>
                        </div>
                        <div>
                            <span class="text-slate-500 text-[8px] block leading-none">NO. PRINT ORDER:</span>
                            <span class="font-bold text-slate-900">{{ $line->printOrder->print_order_number }}</span>
                        </div>
                        <div>
                            <span class="text-slate-500 text-[8px] block leading-none">TANGGAL SCH:</span>
                            <span class="font-bold text-slate-900">{{ $line->printOrder->scheduled_date }}</span>
                        </div>
                    </div>
                </div>

                <!-- Catatan SPV & Parameter -->
                <div class="grid grid-cols-3 gap-2 mt-2 text-[9px]">
                    <div class="col-span-2 bg-slate-50 border border-slate-200 rounded p-1">
                        <span class="text-slate-500 text-[7px] block">INSTRUKSI SPV:</span>
                        <span class="text-slate-700 italic leading-tight break-words block">{{ $workOrder->notes ?? 'Tidak ada instruksi khusus.' }}</span>
                    </div>
                    <div class="bg-slate-50 border border-slate-200 rounded p-1 text-center flex flex-col justify-center">
                        <span class="text-slate-500 text-[7px] block">PEDOMAN TREE / L7:</span>
                        <span class="font-bold text-slate-800">{{ $workOrder->tree_capacity === 1 ? ($workOrder->standard_capacity_guide ?: 20) : $workOrder->tree_capacity }} pcs/tree</span>
                        <span class="text-[7px] font-extrabold {{ $workOrder->require_layer_7 ? 'text-red-600' : 'text-slate-500' }}">
                            Layer 7: {{ $workOrder->require_layer_7 ? 'WAJIB' : 'TIDAK' }}
                        </span>
                    </div>
                </div>

            </div>

            <!-- Kolom Kanan: Hasil Aktual & Ref Visual -->
            <div class="col-span-4 flex flex-col justify-between pl-1">

                <!-- Referensi Gambar Visual (Diperbesar secara signifikan) -->
                <div class="border border-slate-300 rounded p-1.5 mb-1.5 flex-grow flex flex-col justify-between">
                    <span class="text-[8px] font-bold text-slate-500 uppercase tracking-wider block border-b border-slate-200 pb-0.5">REFERENSI GAMBAR</span>
                    @if($workOrder->reference_image_path)
                        <div class="flex-grow flex items-center justify-center overflow-hidden p-1 min-h-[90px]">
                            <img src="{{ asset($workOrder->reference_image_path) }}" class="max-h-[90px] max-w-full object-contain" alt="Ref Visual">
                        </div>
                    @else
                        <div class="grid grid-cols-2 gap-2 mt-1 flex-grow">
                            <div class="border border-dashed border-slate-300 rounded p-2 bg-slate-50 flex flex-col justify-center items-center min-h-[80px] text-center">
                                <span class="text-[7px] font-bold text-slate-500 block leading-none">TAMPAK DEPAN</span>
                            </div>
                            <div class="border border-dashed border-slate-300 rounded p-2 bg-slate-50 flex flex-col justify-center items-center min-h-[80px] text-center">
                                <span class="text-[7px] font-bold text-slate-500 block leading-none">TAMPAK SAMPING</span>
                            </div>
                        </div>
                    @endif
                </div>

                <!-- HASIL AKTUAL RANGKAI (TULIS TANGAN) -->
                <div class="border-2 border-slate-950 rounded p-2 bg-slate-50">
                    <span class="text-[9px] font-black text-slate-900 uppercase tracking-wider block border-b border-slate-950 pb-0.5 text-center">HASIL AKTUAL RANGKAI</span>
                    <div class="grid grid-cols-2 gap-x-2 gap-y-1.5 mt-1.5 text-[9px]">
                        <div class="flex items-center">
                            <span class="text-slate-600 mr-1 whitespace-nowrap">Qty Diambil:</span>
                            <span class="border-b border-slate-950 flex-grow h-3 text-center font-bold"></span>
                            <span class="text-slate-600 ml-1">pcs</span>
                        </div>
                        <div class="flex items-center">
                            <span class="text-slate-600 mr-1 whitespace-nowrap">Qty Good:</span>
                            <span class="border-b border-slate-950 flex-grow h-3 text-center font-bold"></span>
                            <span class="text-slate-600 ml-1">pcs</span>
                        </div>
                        <div class="flex items-center">
                            <span class="text-slate-600 mr-1 whitespace-nowrap">Qty Defect:</span>
                            <span class="border-b border-slate-950 flex-grow h-3 text-center font-bold"></span>
                            <span class="text-slate-600 ml-1">pcs</span>
                        </div>
                        <div class="flex items-center">
                            <span class="text-slate-600 mr-1 whitespace-nowrap">Tanggal:</span>
                            <span class="border-b border-slate-950 flex-grow h-3 text-center font-bold"></span>
                        </div>
                        <div class="flex items-center col-span-2">
                            <span class="text-slate-600 mr-1 whitespace-nowrap">Jam:</span>
                            <span class="text-slate-500 mr-0.5">Mulai:</span>
                            <span class="border-b border-slate-950 w-16 h-3 text-center font-bold mr-2"></span>
                            <span class="text-slate-500 mr-0.5">Selesai:</span>
                            <span class="border-b border-slate-950 w-16 h-3 text-center font-bold"></span>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Signatures / Verifikasi -->
        <div class="grid grid-cols-3 gap-2 border-t border-slate-950 pt-1 text-center text-[8px] leading-tight">
            <div class="flex flex-col justify-between h-8">
                <span class="text-slate-600 font-bold">OPERATOR RANGKAI:</span>
                <span class="font-bold text-slate-800">( ........................................ )</span>
            </div>
            <div class="flex flex-col justify-between h-8">
                <span class="text-slate-600 font-bold">SUPERVISOR RANGKAI:</span>
                <span class="font-bold text-slate-800">( ........................................ )</span>
            </div>
            <div class="flex flex-col justify-between h-8">
                <span class="text-slate-600 font-bold">PPIC / ADMIN:</span>
                <span class="font-bold text-slate-800">( ........................................ )</span>
            </div>
        </div>

    </div>

    <!-- Cutting guide (print only) -->
    <div class="cut-guide"></div>

</body>
</html>
