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
            size: A5 landscape;
            margin: 5mm;
        }
        @media print {
            body {
                background: white;
                color: black;
                font-size: 11px;
            }
            .no-print {
                display: none !important;
            }
            .print-page {
                border: none !important;
                box-shadow: none !important;
                width: 100% !important;
                height: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                page-break-after: always;
            }
        }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: #f8fafc;
        }
        .print-page {
            width: 210mm;
            height: 148mm;
            max-width: 210mm;
            max-height: 148mm;
            background: white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body class="p-0 sm:p-4 flex justify-center items-center">

    <!-- Top Action Bar for web viewing only -->
    <div class="no-print fixed top-4 right-4 flex gap-2">
        <button onclick="window.print()" class="bg-amber-600 hover:bg-amber-700 text-white font-bold text-xs py-2 px-4 rounded-lg shadow transition-all flex items-center gap-1.5">
            <i class="fas fa-print"></i> Cetak Dokumen
        </button>
        <button onclick="window.close()" class="bg-slate-800 hover:bg-slate-900 text-white font-bold text-xs py-2 px-4 rounded-lg shadow transition-all flex items-center gap-1.5">
            Tutup
        </button>
    </div>

    <!-- Main Printable A5 Card -->
    <div class="print-page border border-slate-300 rounded-lg p-5 flex flex-col justify-between box-border">
        
        <!-- Header -->
        <div class="border-b-2 border-slate-900 pb-2 flex justify-between items-start">
            <div>
                <span class="text-[9px] uppercase font-extrabold text-slate-500 tracking-wider">Perintah Rangkai (Lost Wax Assembly Work Order)</span>
                <h1 class="text-lg font-black text-slate-900 leading-none mt-1">NO. WO: {{ $workOrder->rangkai_order_number }}</h1>
            </div>
            <div class="text-right">
                <span class="text-[9px] font-bold text-slate-500 uppercase block">Tanggal Terbit: {{ $workOrder->created_at->format('d-m-Y') }}</span>
                <span class="inline-block bg-slate-900 text-white text-[9px] font-black uppercase px-2 py-0.5 rounded-md mt-1">
                    STATUS: {{ $workOrder->status }}
                </span>
            </div>
        </div>

        <!-- Details Grid -->
        <div class="grid grid-cols-3 gap-3 my-2.5">
            
            <!-- Identitas Produk -->
            <div class="col-span-2 border border-slate-300 rounded-md p-2.5 space-y-1.5">
                <span class="text-[9px] uppercase font-extrabold text-slate-500 tracking-wider border-b border-slate-200 pb-0.5 block">Identitas Produk</span>
                <div class="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                    <div>
                        <span class="text-slate-400 block text-[9px]">Kode Produksi:</span>
                        <strong class="text-slate-850 text-xs font-black">{{ $line->code ?? '-' }}</strong>
                    </div>
                    <div>
                        <span class="text-slate-400 block text-[9px]">Nama Produk:</span>
                        <strong class="text-slate-850 text-[10px] font-black truncate block" title="{{ $line->item_name }}">{{ $line->item_name }}</strong>
                    </div>
                    <div>
                        <span class="text-slate-400 block text-[9px]">Customer:</span>
                        <strong class="text-slate-850 font-bold">{{ $line->customer ?? '-' }}</strong>
                    </div>
                    <div>
                        <span class="text-slate-400 block text-[9px]">AISI / Size:</span>
                        <strong class="text-slate-850 font-bold">{{ $line->aisi ?? '-' }} &middot; {{ $line->size ?? '-' }}</strong>
                    </div>
                    <div class="col-span-2">
                        <span class="text-slate-400 block text-[9px]">No Perintah Cetak:</span>
                        <strong class="text-slate-700">{{ $line->printOrder->print_order_number }}</strong>
                    </div>
                </div>
            </div>

            <!-- Informasi Hasil Cetak -->
            <div class="border border-slate-300 rounded-md p-2.5 flex flex-col justify-between">
                <div>
                    <span class="text-[9px] uppercase font-extrabold text-slate-500 tracking-wider border-b border-slate-200 pb-0.5 block mb-1.5">Hasil Cetak</span>
                    <div class="space-y-1 text-xs">
                        <div class="flex justify-between">
                            <span class="text-slate-400 text-[10px]">Cetak Good:</span>
                            <span class="font-bold text-slate-850">{{ number_format($line->qty_executed_good ?: ($line->qty_actual_good ?? 0)) }} pcs</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400 text-[10px]">Sudah Rangkai:</span>
                            <span class="font-bold text-slate-850">{{ number_format($line->trees()->sum('quantity')) }} pcs</span>
                        </div>
                    </div>
                </div>
                <div class="bg-amber-50 border border-amber-200 p-1.5 rounded text-center">
                    <span class="text-amber-700 text-[8px] font-bold uppercase tracking-wider block">Tersedia Rangkai</span>
                    <strong class="text-amber-900 text-sm font-black">{{ number_format($availableQty) }} pcs</strong>
                </div>
            </div>
        </div>

        <!-- Perintah & Instruksi Rangkai -->
        <div class="grid grid-cols-3 gap-3">
            
            <!-- Parameter Perintah -->
            <div class="col-span-2 border border-slate-300 rounded-md p-2.5 flex flex-col justify-between">
                <div>
                    <span class="text-[9px] uppercase font-extrabold text-slate-500 tracking-wider border-b border-slate-200 pb-0.5 block mb-2">Instruksi Perintah Rangkai</span>
                    <div class="grid grid-cols-3 gap-2 text-xs">
                        <div class="bg-slate-50 border border-slate-200 rounded p-1.5 text-center">
                            <span class="text-slate-500 text-[9px] block">Qty Order:</span>
                            <strong class="text-slate-900 text-sm font-black">{{ number_format($workOrder->qty_planned_pcs) }} pcs</strong>
                        </div>
                        <div class="bg-slate-50 border border-slate-200 rounded p-1.5 text-center">
                            <span class="text-slate-500 text-[9px] block">Pedoman Tree:</span>
                            <strong class="text-slate-900 text-sm font-black">
                                {{ $workOrder->tree_capacity === 1 ? ($workOrder->standard_capacity_guide ?: 20) : $workOrder->tree_capacity }} pcs/tree
                            </strong>
                        </div>
                        <div class="border rounded p-1.5 text-center {{ $workOrder->require_layer_7 ? 'bg-red-50 border-red-200 text-red-800' : 'bg-slate-50 border-slate-200 text-slate-600' }}">
                            <span class="text-[9px] block">Layer 7:</span>
                            <strong class="text-xs uppercase font-extrabold">{{ $workOrder->require_layer_7 ? 'Wajib' : 'Tidak' }}</strong>
                        </div>
                    </div>
                </div>
                
                <div class="text-xs mt-2">
                    <span class="text-slate-400 text-[9px] block">Catatan Internal / Instruksi SPV:</span>
                    <div class="p-1.5 rounded bg-slate-50 border border-slate-200 italic text-slate-700 min-h-[36px] text-[10px]">
                        {{ $workOrder->notes ?? 'Tidak ada catatan khusus.' }}
                    </div>
                </div>
            </div>

            <!-- Referensi Visual / Manual Area -->
            <div class="border border-slate-300 rounded-md p-2.5 flex flex-col justify-between">
                <div>
                    <span class="text-[9px] uppercase font-extrabold text-slate-500 tracking-wider border-b border-slate-200 pb-0.5 block mb-1">Referensi Visual</span>
                    <div class="grid grid-cols-2 gap-1.5">
                        <div class="border border-dashed border-slate-300 rounded p-1 bg-slate-50 text-center flex flex-col justify-center items-center min-h-[45px]">
                            <span class="text-[8px] font-bold text-slate-600 block leading-none">Depan</span>
                            <span class="text-[7px] text-slate-400 mt-0.5">Placeholder</span>
                        </div>
                        <div class="border border-dashed border-slate-300 rounded p-1 bg-slate-50 text-center flex flex-col justify-center items-center min-h-[45px]">
                            <span class="text-[8px] font-bold text-slate-600 block leading-none">Samping</span>
                            <span class="text-[7px] text-slate-400 mt-0.5">Placeholder</span>
                        </div>
                    </div>
                </div>
                <!-- Manual Marking Signature Area -->
                <div class="grid grid-cols-2 gap-2 text-center text-[8px] border-t border-slate-200 pt-1.5">
                    <div>
                        <span class="text-slate-400 block">Operator:</span>
                        <div class="h-4"></div>
                        <span class="text-slate-600 font-bold">(..................)</span>
                    </div>
                    <div>
                        <span class="text-slate-400 block">SPV / PPIC:</span>
                        <div class="h-4"></div>
                        <span class="text-slate-600 font-bold">(..................)</span>
                    </div>
                </div>
            </div>
        </div>

    </div>

</body>
</html>
