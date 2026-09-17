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
            size: 215mm 330mm;
            margin: 0;
        }
        @media print {
            html, body {
                margin: 0 !important;
                padding: 0 !important;
                width: 215mm !important;
                height: 330mm !important;
                background: white !important;
            }
            body {
                display: block !important;
                color: black !important;
                font-size: 8.5px;
            }
            .no-print {
                display: none !important;
            }
            .f4-sheet {
                width: 215mm !important;
                height: 330mm !important;
                max-width: 215mm !important;
                max-height: 330mm !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
                box-shadow: none !important;
                page-break-after: avoid !important;
                page-break-inside: avoid !important;
                break-after: avoid !important;
                box-sizing: border-box !important;
                position: relative !important;
                display: block !important;
            }
            .print-page {
                width: 215mm !important;
                height: 110mm !important;
                max-width: 215mm !important;
                max-height: 110mm !important;
                position: absolute !important;
                top: 0 !important;
                left: 0 !important;
                border: none !important;
                box-shadow: none !important;
                margin: 0 !important;
                padding: 2.5mm 3.5mm !important;
                box-sizing: border-box !important;
                overflow: hidden !important;
            }
            .cut-guide-line {
                position: absolute !important;
                top: 110mm !important;
                left: 0 !important;
                width: 215mm !important;
                border-top: 1px dashed #cbd5e1 !important;
                display: block !important;
            }
        }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: #e2e8f0;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            margin: 0;
            padding: 20px 0;
        }
        .f4-sheet {
            width: 215mm;
            height: 330mm;
            max-width: 215mm;
            max-height: 330mm;
            background: white;
            box-sizing: border-box;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            margin: 0 auto;
            position: relative;
        }
        .print-page {
            width: 215mm;
            height: 110mm;
            max-width: 215mm;
            max-height: 110mm;
            background: white;
            box-sizing: border-box;
            padding: 2.5mm 3.5mm;
            overflow: hidden;
            position: absolute;
            top: 0;
            left: 0;
        }
        .cut-guide-line {
            position: absolute;
            top: 110mm;
            left: 0;
            width: 215mm;
            border-top: 1px dashed #cbd5e1;
        }
    </style>
</head>
<body class="p-0 sm:py-6 flex justify-center items-start">

    <!-- Top Action Bar for web viewing only -->
    <div class="no-print fixed top-4 right-4 flex gap-2 z-50">
        <button onclick="window.print()" class="bg-amber-600 hover:bg-amber-700 text-white font-bold text-xs py-2 px-4 rounded-lg shadow transition-all flex items-center gap-1.5">
            Cetak Dokumen (F4 Portrait)
        </button>
        <button onclick="window.close()" class="bg-slate-800 hover:bg-slate-900 text-white font-bold text-xs py-2 px-4 rounded-lg shadow transition-all flex items-center gap-1.5">
            Tutup
        </button>
    </div>

    <!-- F4 Portrait Sheet (215mm x 330mm) -->
    <div class="f4-sheet">
        
        <!-- Ticket Form (Top 1/3: 215mm x 110mm, Top = 0) -->
        <div class="print-page border border-slate-900 flex flex-col justify-between box-border bg-white">
        
        <!-- 1. Header -->
        <div class="border-b border-slate-900 pb-0.5 flex justify-between items-center shrink-0">
            <div>
                <span class="text-[7px] uppercase font-black text-slate-500 tracking-wider block leading-none">LOST WAX ASSEMBLY - TRACEABILITY PICKING TICKET</span>
                <h1 class="text-[11.5px] font-black text-slate-950 leading-tight mt-0.5">NO. WO: {{ $workOrder->rangkai_order_number }}</h1>
            </div>
            <div class="text-right flex items-center gap-2">
                <span class="text-[7.5px] font-bold text-slate-700 uppercase">Tanggal Terbit: {{ $workOrder->created_at->format('d-m-Y H:i') }}</span>
                <span class="inline-block bg-slate-950 text-white text-[7.5px] font-black uppercase px-2 py-0.5 rounded leading-none">
                    STATUS: {{ $workOrder->status }}
                </span>
            </div>
        </div>

        <!-- 2. Main 2-Column Grid (Left 50%, Right 50%) -->
        <div class="grid grid-cols-2 gap-1.5 my-0.5 items-stretch flex-grow overflow-hidden">
            
            <!-- Kolom Kiri (50%): Informasi WO, Spec, Catatan SPV, Info Rangkai, & Hasil Aktual -->
            <div class="flex flex-col justify-between gap-1 overflow-hidden">
                
                <!-- Box Prominent: Kode Produksi & Qty Ambil -->
                <div class="grid grid-cols-2 gap-1 shrink-0">
                    <div class="bg-slate-100 border border-slate-900 rounded px-1.5 py-0.5 text-center flex flex-col justify-center">
                        <span class="text-[7px] font-bold text-slate-500 block uppercase tracking-wider leading-none mb-0.5">KODE PRODUKSI</span>
                        <span class="text-[14px] font-black text-slate-950 leading-none tracking-tight break-all">{{ $line->code ?? '-' }}</span>
                    </div>
                    <div class="bg-amber-50 border border-amber-500 rounded px-1.5 py-0.5 text-center flex flex-col justify-center">
                        <span class="text-[7px] font-bold text-amber-800 block uppercase tracking-wider leading-none mb-0.5">AMBIL & RANGKAI</span>
                        <span class="text-[14px] font-black text-amber-950 leading-none tracking-tight">{{ number_format($workOrder->qty_planned_pcs) }} PCS</span>
                    </div>
                </div>

                <!-- Detail Spesifikasi Produk: IDENTITAS BATCH / PRODUK -->
                <div class="border border-slate-300 rounded px-1.5 py-0.5 bg-slate-50/30 shrink-0">
                    <span class="text-[7.5px] font-black text-slate-500 uppercase tracking-wider block border-b border-slate-200 pb-0.5 leading-none mb-0.5">IDENTITAS BATCH / PRODUK</span>
                    <div class="grid grid-cols-2 gap-x-1.5 gap-y-0 text-[8px] leading-tight">
                        <div class="col-span-2">
                            <span class="text-slate-500 text-[6.5px] uppercase font-semibold block leading-none">Nama Produk:</span>
                            <span class="font-black text-slate-950 text-[11px] leading-tight block truncate" title="{{ $line->item_name }}">{{ $line->item_name }}</span>
                        </div>
                        <div class="truncate">
                            <span class="text-slate-500 text-[6.5px] uppercase font-semibold">Customer:</span>
                            <span class="font-black text-slate-900 text-[8.5px]">{{ $line->customer ?? '-' }}</span>
                        </div>
                        <div>
                            <span class="text-slate-500 text-[6.5px] uppercase font-semibold">AISI:</span>
                            <span class="font-black text-slate-900 text-[8.5px]">{{ $line->aisi ?? '-' }}</span>
                        </div>
                        <div class="truncate">
                            <span class="text-slate-500 text-[6.5px] uppercase font-semibold">No. PO:</span>
                            <span class="font-black text-slate-900 text-[8.5px]">{{ $line->printOrder->print_order_number }}</span>
                        </div>
                        <div>
                            <span class="text-slate-500 text-[6.5px] uppercase font-semibold">Tgl Sch:</span>
                            <span class="font-black text-slate-900 text-[8.5px]">{{ $line->printOrder->scheduled_date }}</span>
                        </div>
                    </div>
                </div>

                <!-- Informasi Rangkai (Stacked Top) -->
                <div class="bg-slate-50 border border-slate-300 rounded px-1.5 py-0.5 shrink-0">
                    <div class="flex items-center justify-between border-b border-slate-200 pb-0.5 mb-0.5">
                        <span class="text-[7.5px] font-black text-slate-600 uppercase tracking-wider leading-none">INFORMASI RANGKAI</span>
                    </div>
                    <div class="grid grid-cols-2 gap-1 text-[8px] leading-tight">
                        <div class="flex items-center gap-1">
                            <span class="font-bold text-slate-700 whitespace-nowrap">• Isi / tree:</span>
                            <span class="font-black text-slate-950 text-[10px]">{{ $workOrder->standard_capacity_guide ?: ($workOrder->tree_capacity > 1 ? $workOrder->tree_capacity : 20) }} pcs</span>
                        </div>
                        <div class="flex items-center justify-end gap-1">
                            <span class="font-bold text-slate-700 whitespace-nowrap">• Jml Rangkaian:</span>
                            <span class="flex items-center">
                                <span class="border-b border-slate-900 w-8 inline-block h-2"></span>
                                <span class="text-slate-500 ml-0.5 font-semibold text-[7.5px]">tree</span>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Instruksi SPV (Stacked Bottom) -->
                <div class="bg-amber-50/50 border border-slate-300 rounded px-1.5 py-0.5 shrink-0 flex flex-col justify-start">
                    <span class="text-[7.5px] font-black text-slate-600 uppercase tracking-wider block leading-none mb-0.5">INSTRUKSI SPV</span>
                    <span class="text-[9px] font-semibold text-slate-800 italic leading-snug break-words block line-clamp-2">{{ $workOrder->notes ?? 'Tidak ada instruksi khusus.' }}</span>
                </div>

                <!-- Hasil Aktual Rangkai -->
                <div class="border border-slate-900 rounded px-1.5 py-0.5 bg-slate-50/30 shrink-0">
                    <span class="text-[7.5px] font-black text-slate-950 uppercase tracking-wider block border-b border-slate-900 pb-0.5 text-center leading-none">HASIL AKTUAL RANGKAI</span>
                    <div class="grid grid-cols-2 gap-x-1 gap-y-0.5 mt-0.5 text-[7.5px] leading-tight">
                        <div class="flex items-center">
                            <span class="text-slate-700 font-bold mr-1 whitespace-nowrap">Qty Ambil:</span>
                            <span class="border-b border-slate-900 flex-grow h-2 text-center font-bold"></span>
                            <span class="text-slate-500 ml-0.5 text-[6.5px]">pcs</span>
                        </div>
                        <div class="flex items-center">
                            <span class="text-slate-700 font-bold mr-1 whitespace-nowrap">Qty Good:</span>
                            <span class="border-b border-slate-900 flex-grow h-2 text-center font-bold"></span>
                            <span class="text-slate-500 ml-0.5 text-[6.5px]">pcs</span>
                        </div>
                        <div class="flex items-center">
                            <span class="text-slate-700 font-bold mr-1 whitespace-nowrap">Qty Defect:</span>
                            <span class="border-b border-slate-900 flex-grow h-2 text-center font-bold"></span>
                            <span class="text-slate-500 ml-0.5 text-[6.5px]">pcs</span>
                        </div>
                        <div class="flex items-center">
                            <span class="text-slate-700 font-bold mr-1 whitespace-nowrap">Tanggal:</span>
                            <span class="border-b border-slate-900 flex-grow h-2 text-center font-bold"></span>
                        </div>
                        <div class="flex items-center col-span-2">
                            <span class="text-slate-700 font-bold mr-1 whitespace-nowrap">Jam:</span>
                            <span class="text-slate-500 mr-0.5">Mulai:</span>
                            <span class="border-b border-slate-900 w-8 h-2 text-center font-bold mr-1.5"></span>
                            <span class="text-slate-500 mr-0.5">Selesai:</span>
                            <span class="border-b border-slate-900 w-8 h-2 text-center font-bold"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Kolom Kanan (50%): Referensi Gambar (Vertical Portrait Photos Side-by-Side) -->
            <div class="h-full flex flex-col overflow-hidden">
                <div class="border border-slate-300 rounded p-1 flex flex-col justify-start bg-white h-full flex-grow overflow-hidden">
                    <span class="text-[7.5px] font-black text-slate-500 uppercase tracking-wider block mb-0.5 border-b border-slate-200 pb-0.5 leading-none">REFERENSI GAMBAR</span>
                    
                    @php
                        $frontUrl = !empty($assemblyPhoto?->front_image_url) ? $assemblyPhoto->front_image_url : (!empty($workOrder->reference_image_path) ? asset($workOrder->reference_image_path) : null);
                        $sideUrl = !empty($assemblyPhoto?->side_image_url) ? $assemblyPhoto->side_image_url : null;
                    @endphp

                    @if($frontUrl && $sideUrl)
                        <div class="grid grid-cols-2 gap-1 flex-grow h-full items-stretch overflow-hidden">
                            <div class="border border-slate-200 rounded overflow-hidden bg-slate-50 flex items-center justify-center h-full">
                                <img src="{{ $frontUrl }}" class="w-full h-full object-cover" alt="Foto Rangkai 1">
                            </div>
                            <div class="border border-slate-200 rounded overflow-hidden bg-slate-50 flex items-center justify-center h-full">
                                <img src="{{ $sideUrl }}" class="w-full h-full object-cover" alt="Foto Rangkai 2">
                            </div>
                        </div>
                    @elseif($frontUrl)
                        <div class="flex-grow h-full border border-slate-200 rounded overflow-hidden bg-slate-50 flex items-center justify-center">
                            <img src="{{ $frontUrl }}" class="w-full h-full object-contain" alt="Foto Rangkai">
                        </div>
                    @else
                        <div class="flex-grow h-full border border-dashed border-slate-300 rounded bg-slate-50/50 flex flex-col items-center justify-center p-2 text-center">
                            <span class="text-[7.5px] font-bold text-slate-400 leading-tight">FOTO BELUM TERSEDIA</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- 3. Signatures / Verifikasi (Positioned immediately below content) -->
        <div class="grid grid-cols-3 gap-2 border-t border-slate-900 pt-0.5 mt-0.5 shrink-0">
            <div class="flex flex-col items-center justify-between h-7">
                <span class="text-slate-600 font-bold uppercase text-[7px] leading-none">OPERATOR RANGKAI:</span>
                <div class="flex-grow"></div>
                <div class="border-b border-slate-900 w-[70%]"></div>
            </div>
            <div class="flex flex-col items-center justify-between h-7">
                <span class="text-slate-600 font-bold uppercase text-[7px] leading-none">SUPERVISOR RANGKAI:</span>
                <div class="flex-grow"></div>
                <div class="border-b border-slate-900 w-[70%]"></div>
            </div>
            <div class="flex flex-col items-center justify-between h-7">
                <span class="text-slate-600 font-bold uppercase text-[7px] leading-none">PPIC / ADMIN:</span>
                <div class="flex-grow"></div>
                <div class="border-b border-slate-900 w-[70%]"></div>
            </div>
        </div>

        <!-- Cut guide line at 110mm -->
        <div class="cut-guide-line"></div>

    </div>

</body>
</html>

