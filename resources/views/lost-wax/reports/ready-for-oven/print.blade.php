<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barang Siap Oven - {{ $summary['generated_at_formatted'] }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm 12mm 14mm 12mm;
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
            padding: 10px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
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
            font-size: 12px;
            cursor: pointer;
            border: none;
        }

        .btn-print {
            background: #e11d48;
            color: #ffffff;
        }

        .btn-print:hover {
            background: #be123c;
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
            padding-bottom: 8px;
            margin-bottom: 10px;
        }

        .doc-header .company {
            font-size: 11px;
            font-weight: bold;
            color: #475569;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        .doc-header h1 {
            margin: 0;
            font-size: 18px;
            font-weight: 900;
            color: #0f172a;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .doc-header .meta-bar {
            margin-top: 5px;
            display: flex;
            justify-content: space-between;
            font-size: 9.5px;
            color: #475569;
        }

        /* Summary KPI Cards for Print */
        .summary-box {
            display: flex;
            gap: 8px;
            margin-bottom: 10px;
        }

        .kpi-cell {
            flex: 1;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            padding: 5px 8px;
            border-radius: 4px;
            text-align: center;
        }

        .kpi-label {
            font-size: 8px;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
        }

        .kpi-value {
            font-size: 13px;
            font-weight: 900;
            color: #0f172a;
            margin-top: 1px;
        }

        /* Data Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }

        .data-table thead {
            display: table-header-group;
        }

        .data-table th {
            background-color: #1e293b;
            color: #ffffff;
            border: 1px solid #0f172a;
            padding: 5px 6px;
            font-size: 9px;
            text-transform: uppercase;
            font-weight: bold;
            letter-spacing: 0.3px;
        }

        .data-table td {
            border: 1px solid #cbd5e1;
            padding: 4.5px 6px;
            font-size: 9.5px;
            vertical-align: middle;
        }

        .data-table tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }

        .data-table tfoot {
            display: table-footer-group;
            font-weight: bold;
            background-color: #f1f5f9;
        }

        .data-table tfoot td {
            border-top: 2px solid #0f172a;
            border-bottom: 2px solid #0f172a;
            padding: 6px;
            font-size: 10px;
        }

        .text-center {
            text-align: center !important;
        }

        .text-right {
            text-align: right !important;
        }

        .font-mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }

        .font-bold {
            font-weight: bold;
        }

        .barcode-sub {
            font-size: 8px;
            color: #475569;
            font-family: ui-monospace, monospace;
            margin-top: 1.5px;
            line-height: 1.2;
            word-break: break-word;
        }

        .badge-stage {
            display: inline-block;
            font-size: 8px;
            font-weight: bold;
            padding: 1px 4px;
            border-radius: 3px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            margin-top: 1px;
        }

        /* Signature block */
        .sign-section {
            margin-top: 18px;
            page-break-inside: avoid;
        }

        .sign-table {
            width: 100%;
            border-collapse: collapse;
        }

        .sign-table td {
            width: 33.33%;
            text-align: center;
            vertical-align: top;
            padding: 0 10px;
        }

        .sign-role {
            font-size: 9px;
            font-weight: bold;
            color: #334155;
            text-transform: uppercase;
            margin-bottom: 45px;
        }

        .sign-name {
            font-size: 9.5px;
            font-weight: bold;
            border-top: 1px solid #64748b;
            padding-top: 3px;
            display: inline-block;
            min-width: 120px;
        }

        @media print {
            .no-print-toolbar {
                display: none !important;
            }
            body {
                padding: 0;
            }
            .data-table tr {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <!-- Floating On-Screen Toolbar -->
    <div class="no-print-toolbar">
        <div>
            <strong style="font-size: 14px;">Preview Dokumen Cetak A4 Portrait</strong>
            <span style="font-size: 11px; color: #94a3b8; margin-left: 8px;">(Total {{ number_format($summary['total_unique_trees']) }} Pohon Fisik, {{ number_format($summary['total_rangkai']) }} Rangkai, {{ number_format($summary['total_usable_pcs']) }} Pcs)</span>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-print">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                Cetak Dokumen
            </button>
            <button onclick="window.close()" class="btn btn-close" style="margin-left: 8px;">
                Tutup
            </button>
        </div>
    </div>

    <!-- Header Dokumen -->
    <div class="doc-header">
        <div class="company">PT. PERONI KARYA SENTRA &bull; KANBAN PPIC LOST WAX</div>
        <h1>BARANG SIAP OVEN</h1>
        <div class="meta-bar">
            <span><strong>Waktu Cetak:</strong> {{ $summary['generated_at_formatted'] }} WIB</span>
            <span><strong>Prioritas:</strong> FIFO (Waktu Selesai Lapisan Terlama)</span>
            <span><strong>Dicetak Oleh:</strong> {{ auth()->user()->name ?? 'Operator' }}</span>
        </div>
    </div>

    <!-- Summary Box -->
    <div class="summary-box">
        <div class="kpi-cell">
            <div class="kpi-label">Pohon Fisik Unik</div>
            <div class="kpi-value">{{ number_format($summary['total_unique_trees']) }} <span style="font-size: 9.5px; font-weight: normal;">Pohon</span></div>
        </div>
        <div class="kpi-cell">
            <div class="kpi-label">Total Rangkai</div>
            <div class="kpi-value">{{ number_format($summary['total_rangkai']) }} <span style="font-size: 9.5px; font-weight: normal;">Rangkai</span></div>
        </div>
        <div class="kpi-cell">
            <div class="kpi-label">Kuantitas Layak Pakai</div>
            <div class="kpi-value" style="color: #047857;">{{ number_format($summary['total_usable_pcs']) }} <span style="font-size: 9.5px; font-weight: normal;">Pcs</span></div>
        </div>
        <div class="kpi-cell">
            <div class="kpi-label">Kelompok Barang</div>
            <div class="kpi-value">{{ number_format($summary['total_groups']) }} <span style="font-size: 9.5px; font-weight: normal;">Baris</span></div>
        </div>
    </div>

    <!-- Table Data -->
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 26px;" class="text-center">No</th>
                <th style="width: 120px;">Kode Produksi</th>
                <th>Nama Item</th>
                <th style="width: 75px;">Customer</th>
                <th style="width: 65px;" class="text-center">Jumlah Rangkai</th>
                <th style="width: 60px;" class="text-right">Jumlah Pcs</th>
                <th style="width: 105px;" class="text-center">Waktu Selesai Lapisan</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $index => $item)
                <tr>
                    <td class="text-center font-bold" style="color: #64748b;">
                        {{ $index + 1 }}
                    </td>
                    <td>
                        <div class="font-bold">{{ $item['production_code'] }}</div>
                        <div class="barcode-sub">
                            @if(count($item['members']) === 1)
                                {{ $item['members'][0]['barcode'] }}
                                @if($item['members'][0]['rack_label'] !== '-')
                                    &bull; [{{ $item['members'][0]['rack_label'] }}]
                                @endif
                            @else
                                <span><strong>Rangkai ({{ $item['tree_count'] }}):</strong> {{ implode(', ', $item['barcodes_with_rack']) }}</span>
                            @endif
                        </div>
                    </td>
                    <td>
                        <div>{{ $item['item_name'] }}</div>
                    </td>
                    <td>
                        {{ $item['customer'] }}
                    </td>
                    <td class="text-center font-bold">
                        {{ $item['tree_count'] }}
                        @if($item['is_multi_allocated'])
                            <div style="font-size: 7.5px; font-weight: normal; color: #b45309;">*Multi-Alloc</div>
                        @endif
                    </td>
                    <td class="text-right font-bold" style="color: #047857;">
                        {{ number_format($item['quantity_pcs']) }}
                        @if($item['defect_pcs'] > 0)
                            <div style="font-size: 7.5px; font-weight: normal; color: #e11d48;">(-{{ $item['defect_pcs'] }})</div>
                        @endif
                    </td>
                    <td class="text-center font-mono" style="font-size: 9px;">
                        <div>{{ $item['finished_at_formatted'] }}</div>
                        <div class="badge-stage">{{ $item['finished_stage_label'] }}</div>
                        @if($item['has_mixed_times'])
                            <div style="font-size: 7.5px; color: #64748b;">(s/d {{ $item['latest_finished_at']->format('H:i') }})</div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center" style="padding: 24px; color: #94a3b8;">
                        Tidak ada antrean barang yang siap masuk Oven saat ini.
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if($items->isNotEmpty())
            <tfoot>
                <tr>
                    <td colspan="4" class="text-right" style="letter-spacing: 0.5px;">
                        TOTAL KESELURUHAN:
                    </td>
                    <td class="text-center">
                        <div style="font-size: 11px;">{{ number_format($summary['total_rangkai']) }}</div>
                        <div style="font-size: 7.5px; font-weight: normal; color: #64748b;">({{ number_format($summary['total_unique_trees']) }} Pohon Fisik*)</div>
                    </td>
                    <td class="text-right" style="color: #047857; font-size: 11px;">
                        {{ number_format($summary['total_usable_pcs']) }}
                    </td>
                    <td class="text-center" style="font-size: 8px; font-weight: normal; color: #64748b;">
                        {{ $summary['total_groups'] }} Kelompok Barang
                    </td>
                </tr>
            </tfoot>
        @endif
    </table>

    <div style="margin-top: 4px; font-size: 7.5px; color: #64748b; font-style: italic;">
        *Catatan: Pohon fisik unik dihitung dari nomor barcode fisik yang berbeda. Pengelompokan menggabungkan alokasi yang memiliki Kode Produksi, Nama Item, Customer, dan Lapisan yang identik. Prioritas FIFO menggunakan waktu selesai lapisan terawal dalam kelompok.
    </div>

    <!-- Tanda Tangan Operasional -->
    <div class="sign-section">
        <table class="sign-table">
            <tr>
                <td>
                    <div class="sign-role">Dibuat Oleh,<br>(SPV / Operator Lapisan)</div>
                    <div class="sign-name">( ........................................ )</div>
                </td>
                <td>
                    <div class="sign-role">Diterima Oleh,<br>(Operator Oven)</div>
                    <div class="sign-name">( ........................................ )</div>
                </td>
                <td>
                    <div class="sign-role">Mengetahui,<br>(SPV PPIC / QC)</div>
                    <div class="sign-name">( ........................................ )</div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
