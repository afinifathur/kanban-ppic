<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Barang Siap Cor - {{ $summary['generated_at_formatted'] }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 7mm 9mm 8mm 9mm;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10px;
            color: #0f172a;
            margin: 0;
            padding: 0;
            background: #ffffff;
            line-height: 1.25;
        }

        /* Non-print toolbar */
        .no-print-toolbar {
            background: #1e293b;
            color: #ffffff;
            padding: 8px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            border-radius: 6px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: bold;
            font-size: 11px;
            cursor: pointer;
            border: none;
        }

        .btn-print {
            background: #d97706;
            color: #ffffff;
        }

        .btn-print:hover {
            background: #b45309;
        }

        .btn-close {
            background: #475569;
            color: #ffffff;
        }

        .btn-close:hover {
            background: #334155;
        }

        /* Document Header */
        .doc-header {
            text-align: center;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 4px;
            margin-bottom: 6px;
        }

        .doc-header .company {
            font-size: 9.5px;
            font-weight: bold;
            color: #475569;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            margin-bottom: 1px;
        }

        .doc-header h1 {
            margin: 1px 0 3px 0;
            font-size: 17px;
            font-weight: 900;
            color: #0f172a;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .doc-header .meta-bar {
            margin-top: 3px;
            display: flex;
            justify-content: space-between;
            font-size: 8.5px;
            color: #475569;
            flex-wrap: wrap;
            gap: 4px;
        }

        /* Summary KPI Cards for Print */
        .summary-bar {
            display: flex;
            gap: 8px;
            margin-bottom: 6px;
        }

        .summary-box {
            flex: 1;
            border: 1px solid #cbd5e1;
            padding: 4px 8px;
            border-radius: 4px;
            background: #f8fafc;
        }

        .summary-box .label {
            font-size: 7.5px;
            text-transform: uppercase;
            color: #64748b;
            font-weight: bold;
            letter-spacing: 0.3px;
        }

        .summary-box .value {
            font-size: 12px;
            font-weight: 900;
            color: #0f172a;
            margin-top: 1px;
        }

        .summary-box .subtext {
            font-size: 7.5px;
            color: #64748b;
        }

        /* Table */
        table.print-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
            margin-top: 2px;
            table-layout: fixed;
        }

        table.print-table thead {
            display: table-header-group;
        }

        table.print-table tfoot {
            display: table-row-group;
        }

        table.print-table th,
        table.print-table td {
            border: 1px solid #475569;
            padding: 4px 6px;
            vertical-align: middle;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        table.print-table th {
            background-color: #f1f5f9;
            font-weight: 900;
            text-transform: uppercase;
            font-size: 9px;
            color: #0f172a;
            letter-spacing: 0.3px;
            padding: 5px 6px;
        }

        /* Keep each Traveler Card + Heat Number pair together without blocking inter-group flow */
        table.print-table tr.tc-row {
            break-inside: avoid;
            page-break-inside: avoid;
        }

        table.print-table td.group-meta-cell {
            vertical-align: middle;
            padding: 2.5px 6px;
            border-top: none;
            border-bottom: none;
        }

        table.print-table tr.group-first td.group-meta-cell {
            vertical-align: top;
            padding: 3.5px 6px 3px 6px;
            border-top: 1.25px solid #475569;
        }

        table.print-table tr.group-last td.group-meta-cell {
            border-bottom: 1.25px solid #475569;
        }

        table.print-table td.tc-cell,
        table.print-table td.heat-cell,
        table.print-table td.temp-cell {
            padding: 2.5px 4px;
            vertical-align: middle;
            line-height: 1.25;
            border-top: 1px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
        }

        table.print-table tr.group-first td.tc-cell,
        table.print-table tr.group-first td.heat-cell,
        table.print-table tr.group-first td.temp-cell {
            border-top: 1.25px solid #475569;
            vertical-align: middle;
        }

        table.print-table tr.group-last td.tc-cell,
        table.print-table tr.group-last td.heat-cell,
        table.print-table tr.group-last td.temp-cell {
            border-bottom: 1.25px solid #475569;
        }

        .heat-dotted-line {
            display: block;
            width: 100%;
            height: 12px;
            border-bottom: 1.5px dotted #94a3b8;
            box-sizing: border-box;
        }

        .manual-dots {
            color: #64748b;
            font-size: 9.5px;
            font-family: monospace;
            font-weight: bold;
            letter-spacing: 0.5px;
        }

        table.print-table tr.total-row {
            break-inside: avoid;
            page-break-inside: avoid;
            background-color: #f8fafc;
            font-weight: bold;
        }

        table.print-table tr.total-row td {
            border-top: 1.5px solid #0f172a;
            padding: 5px 7px;
        }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .font-mono { font-family: monospace, Courier, sans-serif; }
        .font-bold { font-weight: bold; }

        /* Signatures */
        .sign-section {
            margin-top: 8px;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .sign-table {
            width: 100%;
            border-collapse: collapse;
        }

        .sign-table td {
            border: 1px solid #cbd5e1;
            width: 33.33%;
            padding: 5px 6px;
            text-align: center;
            vertical-align: top;
            font-size: 8.5px;
        }

        .sign-role {
            font-weight: bold;
            color: #334155;
            margin-bottom: 26px;
            line-height: 1.25;
        }

        .sign-name {
            color: #64748b;
            font-size: 8px;
        }

        @media print {
            .no-print-toolbar {
                display: none !important;
            }
            body {
                background: none;
                margin: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Non-print Toolbar -->
    <div class="no-print-toolbar">
        <div>
            <strong>Pratinjau Cetak Laporan Barang Siap Cor (A4)</strong> &bull; Total {{ number_format($summary['total_trees']) }} Pohon Fisik ({{ number_format($summary['total_usable_pcs']) }} PCS)
        </div>
        <div style="display: flex; gap: 8px;">
            <button class="btn btn-print" onclick="window.print()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"></path><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><path d="M6 14h12v8H6z"></path></svg>
                Cetak Laporan
            </button>
            <button class="btn btn-close" onclick="window.close()">Tutup</button>
        </div>
    </div>

    <!-- Header Dokumen -->
    <div class="doc-header">
        <div class="company">PT. PERONI KARYA SENTRA &bull; KANBAN PPIC LOST WAX</div>
        <h1>LAPORAN BARANG SIAP COR</h1>
        <div class="meta-bar">
            <div><strong>Waktu Cetak:</strong> {{ $summary['generated_at_formatted'] }} ({{ $summary['generated_time'] }} WIB) &bull; <strong>Prioritas:</strong> FIFO</div>
            @if($summary['is_filtered'])
                <div><strong>Filter:</strong> {{ $summary['active_filter_text'] }}</div>
            @else
                <div><strong>Filter:</strong> Semua Data Antrean</div>
            @endif
            <div><strong>Dicetak Oleh:</strong> {{ auth()->user()?->name ?? 'Admin PPIC' }}</div>
        </div>
    </div>

    <!-- Summary Box -->
    <div class="summary-bar">
        <div class="summary-box">
            <div class="label">Total Traveler Card</div>
            <div class="value">{{ number_format($summary['total_trees']) }} Pohon</div>
            <div class="subtext">Jumlah Pohon Cangkang Fisik</div>
        </div>
        <div class="summary-box">
            <div class="label">Total Qty Siap Cor</div>
            <div class="value" style="color: #047857;">{{ number_format($summary['total_usable_pcs']) }} PCS</div>
            <div class="subtext">Bruto: {{ number_format($summary['total_gross_pcs']) }} | Afkir: {{ number_format($summary['total_defect_pcs']) }}</div>
        </div>
        <div class="summary-box">
            <div class="label">Total Kode Produksi</div>
            <div class="value">{{ number_format($summary['total_codes']) }} Kode</div>
            <div class="subtext">Kode Produk Berbeda</div>
        </div>
    </div>

    @php
        // Group items by identical production_code, item_name, and customer
        $groupedRows = $items->groupBy(function ($item) {
            $code = trim((string) ($item['production_code'] ?? ''));
            $name = trim((string) ($item['item_name'] ?? ''));
            $cust = trim((string) ($item['customer'] ?? ''));
            return "{$code}|{$name}|{$cust}";
        })->values();
    @endphp

    <!-- Tabel Laporan Barang Siap Cor -->
    <table class="print-table">
        <thead>
            <tr>
                <th style="width: 5%;" class="text-center">No.</th>
                <th style="width: 31%;" class="text-left">Kode Produksi / Nama Item</th>
                <th style="width: 13%;" class="text-right">Qty (PCS)</th>
                <th style="width: 26%;" class="text-left">Traveler Card</th>
                <th style="width: 11%;" class="text-center">HEAT NUMBER</th>
                <th style="width: 7%;" class="text-center">SUHU OVEN</th>
                <th style="width: 7%;" class="text-center">SUHU DAPUR</th>
            </tr>
        </thead>
        <tbody>
            @forelse($groupedRows as $group)
                @php
                    $groupNumber = $loop->iteration;
                    $first = $group->first();
                    $code = $first['production_code'] ?? '-';
                    $customer = $first['customer'] ?? '-';
                    $itemName = $first['item_name'] ?? '-';
                    $material = $first['material'] ?? '-';
                    $groupPcs = (int) $group->sum('quantity_pcs');
                    $groupTrees = $group->count();
                    $travelerCards = $group->unique(fn ($i) => $i['tree_id'] ?? $i['barcode'])->map(function ($i) {
                        return [
                            'code' => !empty($i['human_barcode']) ? $i['human_barcode'] : $i['barcode'],
                            'pcs' => (int) ($i['quantity_pcs'] ?? 0),
                        ];
                    })->values();
                    $tcCount = max(1, $travelerCards->count());
                @endphp
                @foreach($travelerCards as $tc)
                    @php
                        $rowClasses = ['tc-row'];
                        if ($loop->first) {
                            $rowClasses[] = 'group-first';
                        } else {
                            $rowClasses[] = 'group-cont';
                        }
                        if ($loop->last) {
                            $rowClasses[] = 'group-last';
                        }
                    @endphp
                    <tr class="{{ implode(' ', $rowClasses) }}" data-group-code="{{ $code }}">
                        @if($loop->first)
                            <td class="text-center font-bold group-meta-cell" style="font-size: 10.5px; color: #334155;">
                                {{ $groupNumber }}
                            </td>
                            <td class="group-meta-cell">
                                <div style="display: flex; align-items: baseline; gap: 6px; flex-wrap: wrap;">
                                    <span style="font-size: 11.5px; font-weight: 900; font-family: monospace; color: #0f172a; letter-spacing: 0.4px;">{{ $code }}</span>
                                    @if($material !== '-' && !empty($material))
                                        <span style="font-size: 9.5px; font-weight: 700; color: #334155;">{{ $material }}</span>
                                    @endif
                                </div>
                                <div style="font-size: 9.5px; font-weight: 700; color: #1e293b; margin-top: 1px; line-height: 1.25;">
                                    @if($customer !== '-' && !empty($customer))
                                        <span style="color: #475569;">{{ $customer }} &bull;</span>
                                    @endif
                                    {{ $itemName }}
                                </div>
                            </td>
                            <td class="text-right group-meta-cell">
                                <div style="font-size: 12px; font-weight: 900; color: #047857; white-space: nowrap; line-height: 1.2;">
                                    {{ number_format($groupPcs) }} <span style="font-size: 8.5px; font-weight: normal; color: #64748b;">PCS</span>
                                </div>
                                @if($groupTrees > 1)
                                    <div style="font-size: 8px; color: #64748b; margin-top: 1px;">
                                        ({{ $groupTrees }} Pohon)
                                    </div>
                                @endif
                            </td>
                        @else
                            <td class="text-center group-meta-cell"></td>
                            <td class="group-meta-cell"></td>
                            <td class="text-right group-meta-cell"></td>
                        @endif
                        <td class="tc-cell" data-traveler-card="1">
                            <div style="font-size: 9.5px; line-height: 1.25; white-space: nowrap;">
                                <span class="font-mono font-bold" style="color: #0f172a; letter-spacing: 0.2px;">{{ $tc['code'] }}</span>
                                <span style="color: #64748b; margin: 0 3px; font-weight: bold;">—</span>
                                <span style="font-weight: 800; color: #0f172a;">{{ number_format($tc['pcs']) }}</span>
                                <span style="font-size: 8.5px; font-weight: 700; color: #64748b;">PCS</span>
                            </div>
                        </td>
                        <td class="heat-cell" data-heat-line="1">
                            <span class="heat-dotted-line" aria-label="Ruang isi Heat Number"></span>
                        </td>
                        <td class="temp-cell text-center" data-temp-oven="1">
                            <span class="manual-dots"> </span>
                        </td>
                        <td class="temp-cell text-center" data-temp-dapur="1">
                            <span class="manual-dots"> </span>
                        </td>
                    </tr>
                @endforeach
            @empty
                <tr>
                    <td colspan="7" class="text-center" style="padding: 24px; font-size: 11px; color: #94a3b8;">
                        Tidak ada antrean barang siap cor saat ini.
                    </td>
                </tr>
            @endforelse
            @if($groupedRows->isNotEmpty())
                <tr class="total-row">
                    <td colspan="2" class="text-right" style="padding: 6px 10px; font-size: 10.5px; text-transform: uppercase;">
                        TOTAL {{ $summary['is_filtered'] ? 'HASIL FILTER' : 'KESELURUHAN' }} ({{ number_format($summary['total_trees']) }} POHON FISIK):
                    </td>
                    <td class="text-right" style="font-size: 13px; font-weight: 900; color: #047857; padding: 6px 8px; white-space: nowrap;">
                        {{ number_format($summary['total_usable_pcs']) }} PCS
                    </td>
                    <td colspan="4" style="font-size: 8.5px; color: #64748b; text-align: center; font-style: italic;">
                        *Isi Heat Number, Suhu Oven, dan Suhu Dapur aktual pada tiap baris Traveler Card
                    </td>
                </tr>
            @endif
        </tbody>
    </table>

    <!-- Tanda Tangan Operasional -->
    <div class="sign-section">
        <table class="sign-table">
            <tr>
                <td>
                    <div class="sign-role">Diserahkan Oleh,<br>(Admin PPIC / Produksi)</div>
                    <div class="sign-name">( ........................................ )<br>Tgl: .................................</div>
                </td>
                <td>
                    <div class="sign-role">Diterima &amp; Dituang Oleh,<br>(Kepala Bagian Pengecoran)</div>
                    <div class="sign-name">( ........................................ )<br>Tgl: .................................</div>
                </td>
                <td>
                    <div class="sign-role">Diperiksa &amp; Diinput Oleh,<br>(PPIC / QC Casting)</div>
                    <div class="sign-name">( ........................................ )<br>Tgl: .................................</div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
