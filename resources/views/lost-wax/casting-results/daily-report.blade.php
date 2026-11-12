<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Hasil Pengecoran Harian - {{ $cast_date->format('d/m/Y') }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 8mm 10mm 10mm 10mm;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9.5px;
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
            gap: 12px;
            flex-wrap: wrap;
        }

        .toolbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .date-picker-form {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .date-picker-form label {
            font-size: 11px;
            font-weight: 600;
            color: #cbd5e1;
        }

        .date-picker-form input[type="date"] {
            background: #334155;
            border: 1px solid #475569;
            color: #ffffff;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            outline: none;
        }

        .date-picker-form input[type="date"]:focus {
            border-color: #d97706;
        }

        .toolbar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
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

        .btn-submit {
            background: #0284c7;
            color: #ffffff;
            padding: 4px 10px;
        }

        .btn-submit:hover {
            background: #0369a1;
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
            padding-bottom: 6px;
            margin-bottom: 8px;
        }

        .doc-header .company {
            font-size: 10px;
            font-weight: bold;
            color: #475569;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        .doc-header h1 {
            margin: 2px 0 4px 0;
            font-size: 15px;
            font-weight: 900;
            color: #0f172a;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .doc-header .meta-bar {
            margin-top: 4px;
            display: flex;
            justify-content: space-between;
            font-size: 9px;
            color: #475569;
            flex-wrap: wrap;
            gap: 4px;
        }

        /* Summary KPI Cards for Print */
        .summary-bar {
            display: flex;
            gap: 8px;
            margin-bottom: 10px;
        }

        .summary-card {
            flex: 1;
            border: 1px solid #cbd5e1;
            padding: 5px 8px;
            border-radius: 4px;
            background: #f8fafc;
        }

        .summary-card .label {
            font-size: 8px;
            text-transform: uppercase;
            color: #64748b;
            font-weight: bold;
            letter-spacing: 0.3px;
        }

        .summary-card .value {
            font-size: 13px;
            font-weight: 900;
            color: #0f172a;
            margin-top: 1px;
        }

        .summary-card .subtext {
            font-size: 8px;
            color: #64748b;
        }

        /* Heat Group Section */
        .heat-section {
            margin-bottom: 12px;
            page-break-inside: avoid;
        }

        .heat-header-box {
            background-color: #f1f5f9;
            border: 1px solid #94a3b8;
            border-bottom: none;
            padding: 5px 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 4px;
        }

        .heat-title {
            font-size: 10.5px;
            font-weight: 900;
            color: #0f172a;
            letter-spacing: 0.3px;
        }

        .heat-meta-tags {
            font-size: 8.5px;
            color: #334155;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .heat-meta-tags strong {
            color: #0f172a;
        }

        /* Table Styling */
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5px;
            margin-bottom: 0;
        }

        table.data-table th,
        table.data-table td {
            border: 1px solid #94a3b8;
            padding: 3px 6px;
            vertical-align: middle;
        }

        table.data-table th {
            background-color: #e2e8f0;
            font-weight: bold;
            color: #0f172a;
            text-align: center;
            text-transform: uppercase;
            font-size: 8px;
            letter-spacing: 0.3px;
        }

        table.data-table tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }

        table.data-table tfoot tr {
            background-color: #f1f5f9;
            font-weight: bold;
        }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-mono { font-family: "Courier New", Courier, monospace; }
        .font-bold { font-weight: bold; }

        /* Grand Total Box */
        .grand-total-container {
            margin-top: 10px;
            margin-bottom: 12px;
            display: flex;
            justify-content: flex-end;
            page-break-inside: avoid;
        }

        .grand-total-table {
            border-collapse: collapse;
            font-size: 9.5px;
            min-width: 280px;
        }

        .grand-total-table td {
            padding: 4px 8px;
            border: 1px solid #94a3b8;
        }

        .grand-total-table .header-col {
            font-weight: bold;
            background-color: #f1f5f9;
            text-align: right;
            width: 55%;
        }

        .grand-total-table .value-col {
            font-weight: 900;
            text-align: right;
            width: 45%;
        }

        /* Empty State */
        .empty-state {
            border: 1px dashed #cbd5e1;
            background-color: #f8fafc;
            border-radius: 6px;
            padding: 24px;
            text-align: center;
            margin: 20px 0;
        }

        .empty-state h3 {
            margin: 0 0 6px 0;
            font-size: 13px;
            font-weight: bold;
            color: #475569;
        }

        .empty-state p {
            margin: 0;
            font-size: 10px;
            color: #64748b;
        }

        /* Signatures block */
        .signature-container {
            margin-top: 14px;
            page-break-inside: avoid;
        }

        .signature-table {
            width: 100%;
            border-collapse: collapse;
            text-align: center;
            font-size: 8.5px;
        }

        .signature-table td {
            border: 1px solid #cbd5e1;
            padding: 4px 6px;
            width: 33.33%;
            vertical-align: top;
        }

        .signature-title {
            font-weight: bold;
            color: #334155;
            background-color: #f8fafc;
            padding: 3px;
            border-bottom: 1px solid #cbd5e1;
        }

        .signature-space {
            height: 44px;
        }

        .signature-name {
            font-weight: bold;
            color: #0f172a;
            border-top: 1px dotted #94a3b8;
            padding-top: 3px;
            margin: 0 10px;
        }

        .signature-role {
            font-size: 8px;
            color: #64748b;
            margin-top: 1px;
        }

        @media print {
            .no-print-toolbar {
                display: none !important;
            }

            body {
                background: #ffffff !important;
            }

            table.data-table th,
            .heat-header-box {
                background-color: #e2e8f0 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            table.data-table tfoot tr,
            .grand-total-table .header-col,
            .signature-title,
            .summary-card {
                background-color: #f1f5f9 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>

    <!-- Non-print toolbar -->
    <div class="no-print-toolbar">
        <div class="toolbar-left">
            <strong style="font-size: 13px;">Laporan Hasil Pengecoran Harian</strong>
            <form method="GET" action="{{ route('lost-wax.casting-results.daily-report') }}" class="date-picker-form">
                <label for="date-input">Tanggal Cor:</label>
                <input type="date" id="date-input" name="date" value="{{ $date }}" onchange="this.form.submit()">
                <button type="submit" class="btn btn-submit">Lihat</button>
            </form>
        </div>
        <div class="toolbar-actions">
            <button onclick="window.print()" class="btn btn-print">
                Cetak Dokumen (A4)
            </button>
            <a href="{{ route('lost-wax.casting-results.index') }}" class="btn btn-close">
                Kembali ke Riwayat
            </a>
        </div>
    </div>

    <!-- Document Header -->
    <div class="doc-header">
        <div class="company">PT. PERONI KARYA SENTRA • DIVISI FOUNDRY & LOST WAX</div>
        <h1>LAPORAN HASIL PENGECORAN HARIAN</h1>
        <div class="meta-bar">
            <span>Tanggal Cor: <strong>{{ $cast_date->format('d/m/Y') }}</strong></span>
            <span>Waktu Cetak: <strong>{{ now()->format('d/m/Y H:i:s') }}</strong></span>
            <span>Dicetak Oleh: <strong>{{ auth()->user()->name ?? 'Admin PPIC' }}</strong></span>
        </div>
    </div>

    <!-- Summary KPI Header Bar -->
    <div class="summary-bar">
        <div class="summary-card">
            <div class="label">Total Heat Number</div>
            <div class="value">{{ number_format($summary['total_heats']) }} <span style="font-size: 9px; font-weight: normal; color: #64748b;">Heat</span></div>
            <div class="subtext">Batch penuangan selesai</div>
        </div>
        <div class="summary-card">
            <div class="label">Total Pohon Dicor</div>
            <div class="value">{{ number_format($summary['total_trees']) }} <span style="font-size: 9px; font-weight: normal; color: #64748b;">Pohon</span></div>
            <div class="subtext">Traveler Card aktif terkonsumsi</div>
        </div>
        <div class="summary-card">
            <div class="label">Total PCS Dituang</div>
            <div class="value">{{ number_format($summary['total_pcs']) }} <span style="font-size: 9px; font-weight: normal; color: #d97706;">PCS</span></div>
            <div class="subtext">Total kuantitas produk dituang</div>
        </div>
    </div>

    @if($results->isEmpty())
        <div class="empty-state">
            <h3>Tidak Ada Transaksi Hasil Pengecoran</h3>
            <p>Tidak ditemukan data hasil pengecoran dengan status COMPLETED pada tanggal <strong>{{ $cast_date->format('d/m/Y') }}</strong>.</p>
            <p style="margin-top: 6px; font-size: 9px; color: #94a3b8;">Pilih tanggal lain melalui toolbar di atas untuk melihat laporan pada tanggal terkait.</p>
        </div>
    @else
        <!-- Grouped by Heat Number -->
        @foreach($results as $res)
        <div class="heat-section">
            <div class="heat-header-box">
                <div class="heat-title">
                    HEAT NUMBER: <span class="font-mono">{{ $res->heat_number }}</span>
                    <span style="font-weight: normal; font-size: 9px; color: #64748b; margin-left: 6px;">
                        ({{ $res->transaction_number }})
                    </span>
                </div>
                <div class="heat-meta-tags">
                    <span>Tungku: <strong>{{ $res->furnace ? 'Tungku '.$res->furnace : '-' }}</strong></span>
                    <span>Shift: <strong>{{ $res->shift ? 'Shift '.$res->shift : '-' }}</strong></span>
                    <span>Operator: <strong>{{ $res->operator_name ?: '-' }}</strong></span>
                    <span>Admin Input: <strong>{{ $res->recorder?->name ?? 'Admin PPIC' }}</strong></span>
                </div>
            </div>

            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 28px;">No</th>
                        <th style="width: 105px;">Barcode Traveler</th>
                        <th style="width: 95px;">Kode Produksi</th>
                        <th>Nama Item</th>
                        <th style="width: 120px;">Customer</th>
                        <th style="width: 65px;" class="text-right">Qty (PCS)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($res->trees as $treeIdx => $treeDetail)
                    <tr>
                        <td class="text-center">{{ $treeIdx + 1 }}</td>
                        <td class="font-mono text-center font-bold">{{ $treeDetail->barcode }}</td>
                        <td class="font-mono text-center">{{ $treeDetail->production_code ?: '-' }}</td>
                        <td>{{ $treeDetail->item_name ?: '-' }}</td>
                        <td>{{ $treeDetail->customer ?: '-' }}</td>
                        <td class="text-right font-bold">{{ number_format($treeDetail->quantity_pcs) }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" class="text-right">
                            SUBTOTAL HEAT {{ $res->heat_number }} ({{ $res->total_trees }} Pohon):
                        </td>
                        <td class="text-right font-bold">
                            {{ number_format($res->total_pcs) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        @endforeach

        <!-- Grand Total Summary -->
        <div class="grand-total-container">
            <table class="grand-total-table">
                <tr>
                    <td class="header-col">TOTAL HEAT NUMBER:</td>
                    <td class="value-col">{{ number_format($summary['total_heats']) }} Heat</td>
                </tr>
                <tr>
                    <td class="header-col">TOTAL POHON DICOR:</td>
                    <td class="value-col">{{ number_format($summary['total_trees']) }} Pohon</td>
                </tr>
                <tr>
                    <td class="header-col" style="background-color: #fef3c7; color: #92400e;">TOTAL PCS DITUANG:</td>
                    <td class="value-col" style="background-color: #fef3c7; color: #92400e;">{{ number_format($summary['total_pcs']) }} PCS</td>
                </tr>
            </table>
        </div>
    @endif

    <!-- Signatures block -->
    <div class="signature-container">
        <table class="signature-table">
            <thead>
                <tr>
                    <td class="signature-title">Operator Cor / Pelaksana</td>
                    <td class="signature-title">Pengawas / Supervisor Lapangan</td>
                    <td class="signature-title">Disetujui PPIC / Admin</td>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <div class="signature-space"></div>
                        <div class="signature-name">
                            ( &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; )
                        </div>
                        <div class="signature-role">Operator Produksi</div>
                    </td>
                    <td>
                        <div class="signature-space"></div>
                        <div class="signature-name">
                            ( &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; )
                        </div>
                        <div class="signature-role">Supervisor / Mandor</div>
                    </td>
                    <td>
                        <div class="signature-space"></div>
                        <div class="signature-name">{{ auth()->user()->name ?? 'Admin PPIC' }}</div>
                        <div class="signature-role">PPIC / Staff Kontrol</div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

</body>
</html>

