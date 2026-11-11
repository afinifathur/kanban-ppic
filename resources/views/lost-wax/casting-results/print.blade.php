<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bukti Hasil Cor - {{ $castingResult->heat_number }} ({{ $castingResult->transaction_number }})</title>
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
            padding-bottom: 6px;
            margin-bottom: 8px;
            position: relative;
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
            margin: 0;
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

        /* Cancellation Banner / Watermark */
        .cancelled-banner {
            border: 2px dashed #dc2626;
            background-color: #fef2f2;
            color: #991b1b;
            padding: 6px 10px;
            text-align: center;
            font-weight: bold;
            font-size: 12px;
            margin-bottom: 8px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Meta Info Grid */
        .info-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
            font-size: 9.5px;
        }

        .info-grid td {
            padding: 3px 6px;
            vertical-align: top;
        }

        .info-grid .label {
            font-weight: bold;
            color: #475569;
            width: 15%;
        }

        .info-grid .value {
            font-weight: 600;
            color: #0f172a;
            width: 35%;
        }

        /* Table Styling */
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
            margin-bottom: 8px;
        }

        table.data-table th,
        table.data-table td {
            border: 1px solid #94a3b8;
            padding: 4px 6px;
            vertical-align: middle;
        }

        table.data-table th {
            background-color: #f1f5f9;
            font-weight: bold;
            color: #0f172a;
            text-align: center;
            text-transform: uppercase;
            font-size: 8.5px;
            letter-spacing: 0.3px;
        }

        table.data-table tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-mono { font-family: "Courier New", Courier, monospace; }
        .font-bold { font-weight: bold; }

        /* Summary Total Box */
        .summary-box {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 12px;
        }

        .summary-table {
            border-collapse: collapse;
            font-size: 9.5px;
            min-width: 250px;
        }

        .summary-table td {
            padding: 3px 8px;
            border: 1px solid #94a3b8;
        }

        .summary-table .header-col {
            font-weight: bold;
            background-color: #f1f5f9;
            text-align: right;
            width: 60%;
        }

        .summary-table .value-col {
            font-weight: bold;
            text-align: right;
            width: 40%;
        }

        /* Signatures block */
        .signature-container {
            margin-top: 15px;
            page-break-inside: avoid;
        }

        .signature-table {
            width: 100%;
            border-collapse: collapse;
            text-align: center;
            font-size: 9px;
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
            height: 48px;
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
        }

        @media print {
            .no-print-toolbar {
                display: none !important;
            }

            body {
                background: #ffffff !important;
            }

            table.data-table th {
                background-color: #e2e8f0 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .summary-table .header-col,
            .signature-title {
                background-color: #f1f5f9 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .cancelled-banner {
                background-color: #fee2e2 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>

    <!-- Non-print toolbar -->
    <div class="no-print-toolbar">
        <div>
            <strong style="font-size: 13px;">Bukti Hasil Cor: {{ $castingResult->heat_number }}</strong>
            <span style="font-size: 11px; opacity: 0.8; margin-left: 8px;">({{ $castingResult->transaction_number }})</span>
        </div>
        <div style="display: flex; gap: 8px;">
            <button onclick="window.print()" class="btn btn-print">
                Cetak Dokumen (A4)
            </button>
            <button onclick="window.close()" class="btn btn-close">
                Tutup
            </button>
        </div>
    </div>

    <!-- Document Header -->
    <div class="doc-header">
        <div class="company">PT. PERONI KARYA SENTRA • DIVISI FOUNDRY & LOST WAX</div>
        <h1>BUKTI PENCATATAN HASIL COR — LOST WAX</h1>
        <div class="meta-bar">
            <span>No. Transaksi: <strong class="font-mono">{{ $castingResult->transaction_number }}</strong></span>
            <span>Waktu Input: <strong>{{ $castingResult->created_at ? $castingResult->created_at->format('d/m/Y H:i:s') : '-' }}</strong></span>
            <span>Admin: <strong>{{ $castingResult->recorder?->name ?? 'Admin PPIC' }}</strong></span>
        </div>
    </div>

    @if($castingResult->is_cancelled)
    <div class="cancelled-banner">
        *** DOKUMEN INI TELAH DIBATALKAN (VOID) ***<br>
        <span style="font-size: 9px; font-weight: normal; text-transform: none;">
            Dibatalkan oleh: <strong>{{ $castingResult->voider?->name ?? 'Admin' }}</strong> |
            Waktu: <strong>{{ $castingResult->voided_at ? $castingResult->voided_at->format('d/m/Y H:i:s') : '-' }}</strong> |
            Alasan: "{{ $castingResult->void_reason }}"
        </span>
    </div>
    @endif

    <!-- Information Metadata -->
    <table class="info-grid">
        <tr>
            <td class="label">Heat Number:</td>
            <td class="value"><span class="font-mono" style="font-size: 12px; font-weight: 900;">{{ $castingResult->heat_number }}</span></td>
            <td class="label">Tungku / Shift:</td>
            <td class="value">
                {{ $castingResult->furnace ? 'Tungku ' . $castingResult->furnace : '-' }}
                @if($castingResult->shift) / Shift {{ $castingResult->shift }} @endif
            </td>
        </tr>
        <tr>
            <td class="label">Tanggal Cor:</td>
            <td class="value">{{ $castingResult->cast_date ? $castingResult->cast_date->format('d/m/Y') : '-' }}</td>
            <td class="label">Operator Cor:</td>
            <td class="value">{{ $castingResult->operator_name ?: '-' }}</td>
        </tr>
        @if($castingResult->notes)
        <tr>
            <td class="label">Catatan:</td>
            <td class="value" colspan="3">{{ $castingResult->notes }}</td>
        </tr>
        @endif
    </table>

    <!-- Table of Details -->
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 4%;">No</th>
                <th style="width: 18%;">Traveler Card / Barcode</th>
                <th style="width: 18%;">Kode Produksi</th>
                <th style="width: 18%;">Customer</th>
                <th style="width: 28%;">Nama Item / Material</th>
                <th style="width: 14%;" class="text-right">Qty Siap Cor</th>
            </tr>
        </thead>
        <tbody>
            @foreach($castingResult->trees as $idx => $tree)
            <tr>
                <td class="text-center">{{ $idx + 1 }}</td>
                <td class="font-mono font-bold text-center">{{ $tree->barcode }}</td>
                <td class="font-mono text-center">{{ $tree->production_code }}</td>
                <td>{{ $tree->customer ?: '-' }}</td>
                <td>{{ $tree->item_name ?: '-' }}</td>
                <td class="text-right font-bold font-mono">{{ number_format($tree->quantity_pcs) }} PCS</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Summary Total Box -->
    <div class="summary-box">
        <table class="summary-table">
            <tr>
                <td class="header-col">TOTAL TRAVELER CARD:</td>
                <td class="value-col font-mono">{{ number_format($castingResult->total_trees) }} Pohon</td>
            </tr>
            <tr>
                <td class="header-col">TOTAL HASIL PENUANGAN:</td>
                <td class="value-col font-mono" style="font-size: 11px;">{{ number_format($castingResult->total_pcs) }} PCS</td>
            </tr>
        </table>
    </div>

    <!-- Signatures -->
    <div class="signature-container">
        <table class="signature-table">
            <tr>
                <td>
                    <div class="signature-title">Dibuat Oleh (Admin PPIC)</div>
                    <div class="signature-space"></div>
                    <div class="signature-name">{{ $castingResult->recorder?->name ?? 'Admin PPIC' }}</div>
                    <div class="signature-role">PPIC / Administrasi Pengecoran</div>
                </td>
                <td>
                    <div class="signature-title">Dituang Oleh (Operator Cor)</div>
                    <div class="signature-space"></div>
                    <div class="signature-name">{{ $castingResult->operator_name ?: 'Operator Pengecoran' }}</div>
                    <div class="signature-role">Divisi Foundry / Lost Wax</div>
                </td>
                <td>
                    <div class="signature-title">Diterima Oleh (Netto / Finishing)</div>
                    <div class="signature-space"></div>
                    <div class="signature-name">( ............................................ )</div>
                    <div class="signature-role">Penerima Hasil Cor (Bongkar / Netto)</div>
                </td>
            </tr>
        </table>
    </div>

</body>
</html>

