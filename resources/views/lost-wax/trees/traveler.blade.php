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
            gap: 30px;
            padding: 20px;
        }

        .print-page {
            background: #fff;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            display: grid;
            grid-template-columns: repeat(2, 92mm);
            grid-template-rows: repeat(3, 88mm);
            gap: 4mm;
            padding: 10mm;
            box-sizing: border-box;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
        }

        .traveler-card {
            background: #fff;
            border: 1px dashed #cbd5e1;
            padding: 4mm;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 8mm 6mm;
            }
            body {
                background: #fff !important;
                min-height: auto !important;
                margin: 0 !important;
            }
            .print-container {
                display: block !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .print-page {
                box-shadow: none !important;
                border: none !important;
                border-radius: 0 !important;
                padding: 0 !important;
                gap: 4mm !important;
                width: 188mm !important;
                height: 272mm !important;
                margin: 0 auto !important;
                page-break-after: always !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            .print-page:last-child {
                page-break-after: avoid !important;
            }
            .traveler-card {
                width: 92mm !important;
                height: 88mm !important;
                min-height: 88mm !important;
                border: 1px dashed #000 !important;
                padding: 3mm !important;
                box-shadow: none !important;
                break-inside: avoid !important;
                page-break-inside: avoid !important;
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
        @foreach($treesList->chunk(6) as $pageChunk)
            <div class="print-page">
                @foreach($pageChunk as $t)
                    <div class="traveler-card">
                        <div class="h-full flex flex-col justify-between text-center border-2 border-black p-3 rounded">
                            <div>
                                <div class="font-bold text-xs mb-1">LOST WAX TRAVELER</div>
                                <div class="border-t border-black pt-1 mb-1 text-[10px]">
                                    <div class="font-bold">{{ $t->getSourcePrintOrderNumber() ?? $t->getSourceCode() }}</div>
                                    <div class="text-[9px] font-semibold mt-0.5">{{ $t->getSourceItemCode() ?? '-' }}</div>
                                    <div class="text-[9px] truncate max-w-full block">{{ $t->getSourceProduct() ?? '-' }}</div>
                                    @if($t->getSourceAisi())
                                        <div class="text-[9px] font-semibold">AISI: {{ $t->getSourceAisi() }}</div>
                                    @endif
                                </div>
                            </div>

                            <div class="flex flex-col items-center justify-center my-1">
                                <img src="{{ route('lost-wax.trees.barcode', $t) }}" alt="Barcode" class="mx-auto" style="height: 48px; max-width: 100%;">
                                <div class="font-bold text-[10px] tracking-wider mt-1">{{ $t->barcode }}</div>
                            </div>

                            <div class="border-t border-black pt-1 text-[9px] space-y-0.5">
                                <div class="flex justify-between">
                                    <span>TREE:</span>
                                    <span class="font-bold">{{ str_pad((string) $t->tree_number, 3, '0', STR_PAD_LEFT) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>QTY:</span>
                                    <span class="font-bold">{{ number_format($t->quantity) }} PCS</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>DATE:</span>
                                    <span class="font-bold">{{ $t->production_date->format('d-m-Y') }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>PLAN:</span>
                                    <span class="font-bold">{{ optional($t->plan)->wave_number ? 'Wave '.str_pad((string) $t->plan->wave_number, 3, '0', STR_PAD_LEFT) : '-' }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>CODE:</span>
                                    <span class="font-bold">{{ $t->barcode }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    <div class="no-print fixed bottom-4 left-1/2 -translate-x-1/2 flex gap-3 z-50">
        <button onclick="window.print()" class="bg-amber-600 hover:bg-amber-700 text-white text-sm font-bold py-2 px-4 rounded-lg shadow-lg flex items-center gap-1.5 transition-colors">
            <i class="fas fa-print"></i> Cetak Epson A4
        </button>
        <button onclick="printThermal()" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold py-2 px-4 rounded-lg shadow-lg flex items-center gap-1.5 transition-colors">
            <i class="fas fa-barcode"></i> Cetak Thermal 90×50
        </button>
        <button onclick="window.close()" class="bg-slate-600 hover:bg-slate-700 text-white text-sm font-bold py-2 px-4 rounded-lg shadow-lg transition-colors">
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
            // Auto print for Epson if requested via query parameter
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

                    // Laravel session auth includes CSRF validation. Let's send POST request.
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
