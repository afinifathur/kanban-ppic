<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Traveler - {{ $tree->barcode }}</title>
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
    <style>
        @media print {
            @page {
                size: 85mm 54mm;
                margin: 0;
            }
            body {
                width: 85mm;
                min-height: 54mm;
            }
            .no-print {
                display: none !important;
            }
        }
        body {
            font-family: 'Courier New', monospace;
            background: #fff;
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen bg-gray-100">
    <div class="bg-white p-[6mm] mx-auto" style="width: 85mm; min-height: 54mm; border: 1px solid #e5e7eb;">
        <div class="text-center border-2 border-black p-2 rounded text-[10px]">
            <div class="font-bold text-xs mb-1">LOST WAX TRAVELER</div>

            <div class="border-t border-black pt-1 mb-1">
                <div class="font-bold">{{ $tree->workOrder->et_code }}</div>
                <div class="text-[8px]">{{ optional($tree->workOrder->itemReference)->item_code_snapshot ?? '-' }}</div>
                <div class="text-[8px] truncate">{{ optional($tree->workOrder->itemReference)->item_name_snapshot ?? '-' }}</div>
                @if(optional($tree->workOrder->itemReference)->aisi_snapshot)
                    <div class="text-[8px]">AISI: {{ $tree->workOrder->itemReference->aisi_snapshot }}</div>
                @endif
            </div>

            <div class="border-t border-black pt-1 mb-1">
                <img src="{{ route('lost-wax.trees.barcode', $tree) }}" alt="Barcode" class="mx-auto" style="height: 50px; max-width: 100%;">
            </div>

            <div class="font-bold text-xs tracking-wider mb-1">{{ $tree->barcode }}</div>

            <div class="border-t border-black pt-1">
                <div class="flex justify-between text-[8px]">
                    <span>TREE:</span>
                    <span class="font-bold">{{ str_pad((string) $tree->tree_number, 3, '0', STR_PAD_LEFT) }}</span>
                </div>
                <div class="flex justify-between text-[8px]">
                    <span>QTY:</span>
                    <span class="font-bold">{{ number_format($tree->quantity) }} PCS</span>
                </div>
                <div class="flex justify-between text-[8px]">
                    <span>DATE:</span>
                    <span class="font-bold">{{ $tree->production_date->format('d-m-Y') }}</span>
                </div>
                <div class="flex justify-between text-[8px]">
                    <span>PLAN:</span>
                    <span class="font-bold">{{ optional($tree->plan)->wave_number ? 'Wave '.str_pad((string) $tree->plan->wave_number, 3, '0', STR_PAD_LEFT) : '-' }}</span>
                </div>
                <div class="flex justify-between text-[8px]">
                    <span>CODE:</span>
                    <span class="font-bold">{{ $tree->barcode }}</span>
                </div>
            </div>
        </div>
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
