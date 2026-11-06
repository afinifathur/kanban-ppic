<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perintah Cor Pasir - {{ $castingOrder->casting_order_number }}</title>
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
    <style>
        .pcor-print-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 8.5pt;
            line-height: 1.25;
        }
        .pcor-print-table th,
        .pcor-print-table td {
            border: 1px solid #475569;
            padding: 4px 4px;
            vertical-align: middle;
        }
        .pcor-print-table thead th {
            font-size: 7.5pt;
            background-color: #f1f5f9;
            color: #0f172a;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.01em;
            text-align: center;
            vertical-align: middle;
            line-height: 1.2;
            padding: 4px 2px;
            word-break: normal;
            overflow-wrap: normal;
            hyphens: none;
        }
        .pcor-print-table tbody td {
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        .pcor-print-table .col-product {
            word-break: normal;
            overflow-wrap: break-word;
            line-height: 1.3;
        }
        .pcor-print-table .manual-write-cell {
            height: 22px;
            background-color: #ffffff;
        }
        .rekap-summary-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 8pt;
            line-height: 1.25;
        }
        .rekap-summary-table th,
        .rekap-summary-table td {
            border: 1px solid #475569;
            padding: 3px 6px;
            vertical-align: middle;
        }
        .rekap-summary-table thead th {
            background-color: #f1f5f9;
            color: #0f172a;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 8mm 10mm;
            }
            html, body {
                background: #ffffff !important;
                color: #000000 !important;
                margin: 0 !important;
                padding: 0 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .no-print {
                display: none !important;
            }
            .print-sheet {
                max-width: 100% !important;
                width: 100% !important;
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .pcor-print-table thead {
                display: table-header-group;
            }
            .pcor-print-table tfoot {
                display: table-footer-group;
            }
            .pcor-print-table tr,
            .rekap-summary-table tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .rekap-balance-block,
            .signature-block {
                page-break-inside: avoid;
                break-inside: avoid;
            }
        }
    </style>
</head>
<body class="bg-slate-100 font-sans text-slate-900 p-4 sm:p-6">
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

    <!-- Printable Paper Sheet (A4 Portrait) -->
    <div class="print-sheet max-w-4xl mx-auto bg-white border border-slate-300 rounded-lg shadow-sm p-6 text-xs text-slate-800">
        <!-- Header -->
        <div class="border-b-2 border-slate-900 pb-3 mb-4">
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-lg font-black uppercase tracking-wider text-slate-900">PT. PERONI KARYA SENTRA</h1>
                    <h2 class="text-xs font-bold uppercase tracking-wide text-blue-700 mt-0.5">SURAT PERINTAH COR PASIR (SAND CASTING)</h2>
                </div>
                <div class="text-right">
                    <div class="text-sm font-mono font-bold text-slate-900">{{ $castingOrder->casting_order_number }}</div>
                    <div class="text-[10px] text-slate-500 font-semibold uppercase tracking-wider mt-0.5">
                        Status: <span class="font-bold text-slate-800">{{ $castingOrder->status }}</span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-3 pt-2.5 border-t border-slate-200">
                <div>
                    <div class="text-[9px] font-bold text-slate-400 uppercase">Tgl Rencana Cor:</div>
                    <div class="text-xs font-bold text-slate-900 mt-0.5">
                        {{ $castingOrder->scheduled_date ? $castingOrder->scheduled_date->format('d/m/Y') : '-' }}
                    </div>
                </div>
                <div>
                    <div class="text-[9px] font-bold text-slate-400 uppercase">Dibuat Oleh:</div>
                    <div class="text-xs font-semibold text-slate-800 mt-0.5">{{ $castingOrder->creator->name ?? '-' }}</div>
                </div>
                <div>
                    <div class="text-[9px] font-bold text-slate-400 uppercase">Tgl Cetak Dokumen:</div>
                    <div class="text-xs text-slate-700 mt-0.5">{{ ($printedAt ?? now())->format('d/m/Y H:i') }}</div>
                </div>
                <div>
                    <div class="text-[9px] font-bold text-slate-400 uppercase">Catatan:</div>
                    <div class="text-xs text-slate-700 mt-0.5 italic">{{ $castingOrder->notes ?: '-' }}</div>
                </div>
            </div>
        </div>

        @php
            $linesToPrint = isset($printableLines)
                ? $printableLines
                : $castingOrder->lines->filter(fn ($line) => $line->qty_remaining_to_cast > 0)->values();
            $totalPcs = 0;
            $totalKg = 0.0;
        @endphp

        @if($linesToPrint->isEmpty())
            <div class="my-6 p-6 border border-slate-300 rounded-lg bg-slate-50 text-center">
                <p class="text-sm font-bold text-slate-700">Tidak ada outstanding pengecoran pada PCOR ini</p>
            </div>
        @else
            <!-- Order Table (10 Columns, Fixed Layout) -->
            <table class="pcor-print-table mb-5">
                <colgroup>
                    <col style="width: 3.5%;"> {{-- 1. No --}}
                    <col style="width: 11%;">   {{-- 2. Kode Customer --}}
                    <col style="width: 11%;">   {{-- 3. Customer --}}
                    <col style="width: 26.5%;"> {{-- 4. Nama Produk (prioritas lebar) --}}
                    <col style="width: 8%;">    {{-- 5. Ukuran --}}
                    <col style="width: 6%;">    {{-- 6. AISI --}}
                    <col style="width: 10%;">   {{-- 7. Qty Perintah (Sisa) --}}
                    <col style="width: 6%;">    {{-- 8. Good (manual) --}}
                    <col style="width: 6%;">    {{-- 9. Reject (manual) --}}
                    <col style="width: 12%;">   {{-- 10. Keterangan --}}
                </colgroup>
                <thead>
                    <tr>
                        <th class="text-center whitespace-nowrap">No</th>
                        <th class="text-center">Kode Customer</th>
                        <th class="text-center">Customer</th>
                        <th class="text-center">Nama Produk</th>
                        <th class="text-center whitespace-nowrap">Ukuran</th>
                        <th class="text-center whitespace-nowrap">AISI</th>
                        <th class="text-center">Qty Perintah (Sisa)</th>
                        <th class="text-center whitespace-nowrap">Good</th>
                        <th class="text-center whitespace-nowrap">Reject</th>
                        <th class="text-center">Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($linesToPrint as $index => $line)
                        @php
                            $qtyOutstanding = (int) $line->qty_remaining_to_cast;
                            $unitWeight = $line->productionPlan ? (float) ($line->productionPlan->weight ?? 0) : 0.0;
                            $subtotalWeight = $qtyOutstanding * $unitWeight;
                            $totalPcs += $qtyOutstanding;
                            $totalKg += $subtotalWeight;
                        @endphp
                        <tr>
                            <td class="text-center font-mono">{{ $index + 1 }}</td>
                            <td class="font-mono font-bold">{{ $line->code }}</td>
                            <td>{{ $line->customer ?: '-' }}</td>
                            <td class="col-product font-semibold text-slate-900">{{ $line->item_name }}</td>
                            <td class="text-center font-mono">{{ $line->size ?: '-' }}</td>
                            <td class="text-center font-mono">{{ $line->aisi ?: '-' }}</td>
                            <td class="text-center font-bold">{{ number_format($qtyOutstanding) }}</td>
                            <td class="manual-write-cell text-center" aria-label="Kolom manual Good"></td>
                            <td class="manual-write-cell text-center" aria-label="Kolom manual Reject"></td>
                            <td class="text-[10px]">
                                @if($line->line_number)
                                    <span class="font-bold not-italic text-slate-700">L{{ $line->line_number }}</span>{{ $line->notes ? ' - ' : '' }}
                                @endif
                                <span class="italic">{{ $line->notes ?: ($line->line_number ? '' : '-') }}</span>
                                @if($line->qty_cast_good > 0 || $line->qty_cast_reject > 0)
                                    <div class="text-[9px] text-slate-500 not-italic mt-0.5 font-mono">
                                        Aktual G:{{ number_format($line->qty_cast_good) }}{{ $line->qty_cast_reject > 0 ? ' R:'.number_format($line->qty_cast_reject) : '' }} / Awal:{{ number_format($line->qty_ordered) }}
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="bg-slate-100 font-bold border-t-2 border-slate-600">
                        <td colspan="6" class="text-right uppercase">Total Perintah Cor:</td>
                        <td class="text-center font-bold">{{ number_format($totalPcs) }} pcs</td>
                        <td class="manual-write-cell text-center" aria-label="Rekap manual Good"></td>
                        <td class="manual-write-cell text-center" aria-label="Rekap manual Reject"></td>
                        <td class="text-left font-bold text-[10px]">
                            Estimasi Berat Bersih:<br>
                            <span class="font-mono text-slate-900">{{ number_format($totalKg, 2) }} kg</span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        @endif

        @php
            $resolvedBalance = isset($balanceSummary)
                ? $balanceSummary
                : app(\App\Http\Controllers\SandCasting\CastingOrderController::class)->buildPrintBalanceSummary($linesToPrint);

            $catSummary = $resolvedBalance['categories'] ?? [];
            $lineSummary = $resolvedBalance['lines'] ?? [];

            $catTotalPcs = (int) ($resolvedBalance['category_total_pcs'] ?? 0);
            $catTotalKg = round((float) ($resolvedBalance['category_total_kg'] ?? 0.0), 2);

            $lineTotalPcs = (int) ($resolvedBalance['line_total_pcs'] ?? 0);
            $lineTotalKg = round((float) ($resolvedBalance['line_total_kg'] ?? 0.0), 2);
        @endphp

        <!-- Rekap Balance Section (Kategori + Line 1-4) -->
        <div class="rekap-balance-block mb-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- 4. Rekap Berdasarkan Kategori -->
                <div>
                    <div class="text-[9px] font-bold uppercase tracking-wider text-slate-700 mb-1">Rekap Berdasarkan Kategori Customer</div>
                    <table class="rekap-summary-table" id="rekap-kategori-table">
                        <colgroup>
                            <col style="width: 46%;">
                            <col style="width: 24%;">
                            <col style="width: 30%;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th class="text-left">Kategori</th>
                                <th class="text-right">PCS</th>
                                <th class="text-right">KG</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach(['LOCAL', 'EXPORT', 'UNCLASSIFIED'] as $catKey)
                                @php
                                    $cPcs = (int) ($catSummary[$catKey]['pcs'] ?? 0);
                                    $cKg = (float) ($catSummary[$catKey]['kg'] ?? 0.0);
                                @endphp
                                <tr data-category-row="{{ $catKey }}">
                                    <td class="font-semibold text-slate-800">{{ $catKey }}</td>
                                    <td class="text-right font-mono">{{ number_format($cPcs) }}</td>
                                    <td class="text-right font-mono">{{ number_format($cKg, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="bg-slate-100 font-bold border-t-2 border-slate-600" data-category-row="TOTAL KATEGORI">
                                <td class="uppercase">TOTAL KATEGORI</td>
                                <td class="text-right font-mono">{{ number_format($catTotalPcs) }}</td>
                                <td class="text-right font-mono">{{ number_format($catTotalKg, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- 5. Rekap Berdasarkan Line -->
                <div>
                    <div class="text-[9px] font-bold uppercase tracking-wider text-slate-700 mb-1">Rekap Berdasarkan Line Produksi</div>
                    <table class="rekap-summary-table" id="rekap-line-table">
                        <colgroup>
                            <col style="width: 46%;">
                            <col style="width: 24%;">
                            <col style="width: 30%;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th class="text-left">Line</th>
                                <th class="text-right">PCS</th>
                                <th class="text-right">KG</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach([1, 2, 3, 4] as $lNum)
                                @php
                                    $lPcs = (int) ($lineSummary[$lNum]['pcs'] ?? 0);
                                    $lKg = (float) ($lineSummary[$lNum]['kg'] ?? 0.0);
                                @endphp
                                <tr data-line-row="LINE {{ $lNum }}">
                                    <td class="font-semibold text-slate-800">LINE {{ $lNum }}</td>
                                    <td class="text-right font-mono">{{ number_format($lPcs) }}</td>
                                    <td class="text-right font-mono">{{ number_format($lKg, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="bg-slate-100 font-bold border-t-2 border-slate-600" data-line-row="TOTAL LINE">
                                <td class="uppercase">TOTAL LINE</td>
                                <td class="text-right font-mono">{{ number_format($lineTotalPcs) }}</td>
                                <td class="text-right font-mono">{{ number_format($lineTotalKg, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- Signatures / Approval block -->
        <div class="signature-block grid grid-cols-3 gap-6 pt-3 text-center mt-8">
            <div>
                <div class="text-[10px] font-bold text-slate-600 uppercase">Dibuat Oleh (PPIC)</div>
                <div class="h-14"></div>
                <div class="border-t border-slate-500 pt-1 font-bold text-xs">{{ $castingOrder->creator->name ?? 'PPIC Staff' }}</div>
            </div>
            <div>
                <div class="text-[10px] font-bold text-slate-600 uppercase">Disetujui (Kabag Cor Pasir)</div>
                <div class="h-14"></div>
                <div class="border-t border-slate-500 pt-1 font-bold text-xs">( ........................................ )</div>
            </div>
            <div>
                <div class="text-[10px] font-bold text-slate-600 uppercase">Diterima (SPV Cor)</div>
                <div class="h-14"></div>
                <div class="border-t border-slate-500 pt-1 font-bold text-xs">( ........................................ )</div>
            </div>
        </div>
    </div>
</body>
</html>
