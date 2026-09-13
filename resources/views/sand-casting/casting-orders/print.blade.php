<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perintah Cor Pasir - {{ $castingOrder->casting_order_number }}</title>
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
    <style>
        @media print {
            @page {
                size: A4 portrait;
                margin: 10mm;
            }
            body {
                background: #fff !important;
                color: #000 !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body class="bg-slate-100 font-sans text-slate-900 p-4 sm:p-8">
    <!-- Non-printable top action bar -->
    <div class="max-w-4xl mx-auto mb-4 flex items-center justify-between no-print">
        <a href="{{ route('sand-casting.casting-orders.show', $castingOrder) }}" class="text-xs font-bold text-slate-600 hover:text-slate-900 flex items-center gap-1.5">
            &larr; Kembali ke Dokumen
        </a>
        <button onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs px-4 py-2 rounded-lg shadow flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
            Cetak Dokumen
        </button>
    </div>

    <!-- Printable Paper Sheet -->
    <div class="max-w-4xl mx-auto bg-white border border-slate-300 rounded-lg shadow-sm p-8 text-xs text-slate-800">
        <!-- Header -->
        <div class="border-b-2 border-slate-900 pb-4 mb-6">
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-xl font-black uppercase tracking-wider text-slate-900">PT. PERONI KARYA UTAMA</h1>
                    <h2 class="text-sm font-bold uppercase tracking-wide text-blue-700 mt-0.5">SURAT PERINTAH COR PASIR (SAND CASTING)</h2>
                </div>
                <div class="text-right">
                    <div class="text-sm font-mono font-bold text-slate-900">{{ $castingOrder->casting_order_number }}</div>
                    <div class="text-[10px] text-slate-500 font-semibold uppercase tracking-wider mt-0.5">
                        Status: <span class="font-bold text-slate-800">{{ $castingOrder->status }}</span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-4 pt-3 border-t border-slate-200">
                <div>
                    <div class="text-[9px] font-bold text-slate-400 uppercase">Tgl Rencana Cor</div>
                    <div class="text-xs font-bold text-slate-900 mt-0.5">
                        {{ $castingOrder->scheduled_date ? $castingOrder->scheduled_date->format('d/m/Y') : '-' }}
                    </div>
                </div>
                <div>
                    <div class="text-[9px] font-bold text-slate-400 uppercase">Dibuat Oleh</div>
                    <div class="text-xs font-semibold text-slate-800 mt-0.5">{{ $castingOrder->creator->name ?? '-' }}</div>
                </div>
                <div>
                    <div class="text-[9px] font-bold text-slate-400 uppercase">Tgl Cetak Dokumen</div>
                    <div class="text-xs text-slate-700 mt-0.5">{{ now()->format('d/m/Y H:i') }}</div>
                </div>
                <div>
                    <div class="text-[9px] font-bold text-slate-400 uppercase">Catatan</div>
                    <div class="text-xs text-slate-700 mt-0.5 italic">{{ $castingOrder->notes ?: '-' }}</div>
                </div>
            </div>
        </div>

        <!-- Order Table -->
        <table class="w-full border-collapse border border-slate-400 text-xs mb-6">
            <thead>
                <tr class="bg-slate-100 text-slate-800 font-bold uppercase tracking-wider">
                    <th class="border border-slate-400 p-2 text-center w-8">No</th>
                    <th class="border border-slate-400 p-2 text-left w-24">Kode Cust</th>
                    <th class="border border-slate-400 p-2 text-left w-32">Customer</th>
                    <th class="border border-slate-400 p-2 text-left">Nama Produk</th>
                    <th class="border border-slate-400 p-2 text-center w-16">Ukuran</th>
                    <th class="border border-slate-400 p-2 text-center w-16">AISI</th>
                    <th class="border border-slate-400 p-2 text-center w-20">Qty Perintah</th>
                    <th class="border border-slate-400 p-2 text-center w-20">Berat (Kg)</th>
                    <th class="border border-slate-400 p-2 text-left w-28">Keterangan</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $totalPcs = 0;
                    $totalKg = 0;
                @endphp
                @foreach($castingOrder->lines as $index => $line)
                    @php
                        $unitWeight = $line->productionPlan ? (float) ($line->productionPlan->weight ?? 0) : 0;
                        $subtotalWeight = $line->qty_ordered * $unitWeight;
                        $totalPcs += $line->qty_ordered;
                        $totalKg += $subtotalWeight;
                    @endphp
                    <tr>
                        <td class="border border-slate-400 p-2 text-center font-mono">{{ $index + 1 }}</td>
                        <td class="border border-slate-400 p-2 font-mono font-bold">{{ $line->code }}</td>
                        <td class="border border-slate-400 p-2">{{ $line->customer ?: '-' }}</td>
                        <td class="border border-slate-400 p-2 font-semibold">{{ $line->item_name }}</td>
                        <td class="border border-slate-400 p-2 text-center font-mono">{{ $line->size ?: '-' }}</td>
                        <td class="border border-slate-400 p-2 text-center font-mono">{{ $line->aisi ?: '-' }}</td>
                        <td class="border border-slate-400 p-2 text-center font-bold">{{ number_format($line->qty_ordered) }}</td>
                        <td class="border border-slate-400 p-2 text-center font-mono">{{ $subtotalWeight > 0 ? number_format($subtotalWeight, 2) : '-' }}</td>
                        <td class="border border-slate-400 p-2 italic text-[10px]">{{ $line->notes ?: '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="bg-slate-100 font-bold border-t-2 border-slate-400">
                    <td colspan="6" class="border border-slate-400 p-2 text-right uppercase">Total:</td>
                    <td class="border border-slate-400 p-2 text-center font-bold">{{ number_format($totalPcs) }} pcs</td>
                    <td class="border border-slate-400 p-2 text-center font-bold">{{ number_format($totalKg, 2) }} kg</td>
                    <td class="border border-slate-400 p-2"></td>
                </tr>
            </tfoot>
        </table>

        <!-- Signatures / Approval block -->
        <div class="grid grid-cols-3 gap-6 pt-4 text-center mt-12">
            <div>
                <div class="text-[10px] font-bold text-slate-500 uppercase">Dibuat Oleh (PPIC)</div>
                <div class="h-16"></div>
                <div class="border-t border-slate-400 pt-1 font-bold text-xs">{{ $castingOrder->creator->name ?? 'PPIC Staff' }}</div>
            </div>
            <div>
                <div class="text-[10px] font-bold text-slate-500 uppercase">Disetujui (SPV Cor Pasir)</div>
                <div class="h-16"></div>
                <div class="border-t border-slate-400 pt-1 font-bold text-xs">( ........................................ )</div>
            </div>
            <div>
                <div class="text-[10px] font-bold text-slate-500 uppercase">Diterima (Operator Peleburan)</div>
                <div class="h-16"></div>
                <div class="border-t border-slate-400 pt-1 font-bold text-xs">( ........................................ )</div>
            </div>
        </div>
    </div>
</body>
</html>
