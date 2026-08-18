<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Traveler Cards</title>
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #f3f4f6; /* bg-gray-100 */
        }
        
        .print-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
            padding: 20px;
        }

        .traveler-card {
            background: #fff;
            width: 85mm;
            min-height: 54mm;
            border: 1px solid #cbd5e1;
            padding: 6mm;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 5mm;
            }
            body {
                background: #fff !important;
                min-height: auto !important;
            }
            .print-container {
                display: block !important;
                padding: 0 !important;
                gap: 0 !important;
            }
            .traveler-card {
                break-inside: avoid !important;
                page-break-inside: avoid !important;
                margin-top: 0 !important;
                margin-bottom: 6mm !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
                border: 1px solid #000 !important;
                box-shadow: none !important;
                float: left;
                clear: both;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body class="min-h-screen">
    @php
        if (request()->has('ids')) {
            $ids = explode(',', request()->input('ids'));
            $treesList = \App\Models\LostWaxTree::with(['workOrder.itemReference', 'plan', 'printOrderLine.printOrder', 'printOrderLine.productionPlan'])
                ->whereIn('id', $ids)
                ->get();
        } else {
            $tree->load(['workOrder.itemReference', 'plan', 'printOrderLine.printOrder', 'printOrderLine.productionPlan']);
            $treesList = collect([$tree]);
        }
    @endphp

    <div class="print-container">
        @foreach($treesList as $t)
            <div class="traveler-card">
                <div class="text-center border-2 border-black p-2 rounded text-[10px]">
                    <div class="font-bold text-xs mb-1">LOST WAX TRAVELER</div>

                    <div class="border-t border-black pt-1 mb-1">
                        <div class="font-bold">{{ $t->getSourcePrintOrderNumber() ?? $t->getSourceCode() }}</div>
                        <div class="text-[8px]">{{ $t->getSourceItemCode() ?? '-' }}</div>
                        <div class="text-[8px] truncate">{{ $t->getSourceProduct() ?? '-' }}</div>
                        @if($t->getSourceAisi())
                            <div class="text-[8px]">AISI: {{ $t->getSourceAisi() }}</div>
                        @endif
                    </div>

                    <div class="border-t border-black pt-1 mb-1">
                        <img src="{{ route('lost-wax.trees.barcode', $t) }}" alt="Barcode" class="mx-auto" style="height: 50px; max-width: 100%;">
                    </div>

                    <div class="font-bold text-xs tracking-wider mb-1">{{ $t->barcode }}</div>

                    <div class="border-t border-black pt-1">
                        <div class="flex justify-between text-[8px]">
                            <span>TREE:</span>
                            <span class="font-bold">{{ str_pad((string) $t->tree_number, 3, '0', STR_PAD_LEFT) }}</span>
                        </div>
                        <div class="flex justify-between text-[8px]">
                            <span>QTY:</span>
                            <span class="font-bold">{{ number_format($t->quantity) }} PCS</span>
                        </div>
                        <div class="flex justify-between text-[8px]">
                            <span>DATE:</span>
                            <span class="font-bold">{{ $t->production_date->format('d-m-Y') }}</span>
                        </div>
                        <div class="flex justify-between text-[8px]">
                            <span>PLAN:</span>
                            <span class="font-bold">{{ optional($t->plan)->wave_number ? 'Wave '.str_pad((string) $t->plan->wave_number, 3, '0', STR_PAD_LEFT) : '-' }}</span>
                        </div>
                        <div class="flex justify-between text-[8px]">
                            <span>CODE:</span>
                            <span class="font-bold">{{ $t->barcode }}</span>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="no-print fixed bottom-4 left-1/2 -translate-x-1/2 flex gap-3">
        <button onclick="window.print()" class="bg-amber-600 hover:bg-amber-700 text-white text-sm font-bold py-2 px-6 rounded-lg shadow">
            <i class="fas fa-print"></i> Print
        </button>
        <button onclick="window.close()" class="bg-slate-600 hover:bg-slate-700 text-white text-sm font-bold py-2 px-6 rounded-lg shadow">
            Tutup
        </button>
    </div>

    <link rel="stylesheet" href="{{ asset('css/all.min.css') }}">
    <script>
        window.onload = function () {
            window.print();
        };
    </script>
</body>
</html>
