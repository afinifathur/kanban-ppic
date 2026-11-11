<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lembar Kontrol Pengecoran - {{ $summary['generated_at_formatted'] }}</title>
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

        /* Summary KPI Cards for Print */
        .summary-bar {
            display: flex;
            gap: 8px;
            margin-bottom: 8px;
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
            font-size: 9px;
            margin-top: 4px;
        }

        table.print-table th,
        table.print-table td {
            border: 1px solid #64748b;
            padding: 4px 6px;
            vertical-align: middle;
        }

        table.print-table th {
            background-color: #f1f5f9;
            font-weight: 800;
            text-transform: uppercase;
            font-size: 8.5px;
            color: #1e293b;
        }

        table.print-table tr.data-row {
            height: 32px;
        }

        .handwrite-box {
            border: 1px dashed #94a3b8;
            height: 24px;
            border-radius: 2px;
            background-color: #ffffff;
        }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .font-mono { font-family: monospace; }
        .font-bold { font-weight: bold; }

        /* Signatures */
        .sign-section {
            margin-top: 14px;
            page-break-inside: avoid;
        }

        .sign-table {
            width: 100%;
            border-collapse: collapse;
        }

        .sign-table td {
            border: 1px solid #cbd5e1;
            width: 33.33%;
            padding: 6px;
            text-align: center;
            vertical-align: top;
            font-size: 8.5px;
        }

        .sign-role {
            font-weight: bold;
            color: #334155;
            margin-bottom: 34px;
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
            table.print-table tr.data-row {
                height: 32px !important;
            }
        }
    </style>
</head>
<body>
    <!-- Non-print Toolbar -->
    <div class="no-print-toolbar">
        <div>
            <strong>Pratinjau Cetak Lembar Kontrol Pengecoran (A4)</strong> &bull; Total {{ number_format($summary['total_trees']) }} Pohon ({{ number_format($summary['total_usable_pcs']) }} PCS)
        </div>
        <div style="display: flex; gap: 8px;">
            <button class="btn btn-print" onclick="window.print()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"></path><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><path d="M6 14h12v8H6z"></path></svg>
                Cetak Lembar Kontrol
            </button>
            <button class="btn btn-close" onclick="window.close()">Tutup</button>
        </div>
    </div>

    <!-- Header Dokumen -->
    <div class="doc-header">
        <div class="company">PT. PERONI KARYA SENTRA &bull; DIVISI FOUNDRY &amp; LOST WAX</div>
        <h1>LEMBAR KONTROL PENGECORAN &mdash; PERSEDIAAN PASCA DEWAXING (LOST WAX)</h1>
        <div class="meta-bar">
            <div><strong>Tanggal Cetak:</strong> {{ $summary['generated_at_formatted'] }} ({{ $summary['generated_time'] }} WIB)</div>
            @if($summary['is_filtered'])
                <div><strong>Filter:</strong> {{ $summary['active_filter_text'] }}</div>
            @else
                <div><strong>Filter:</strong> Semua Data Antrean (FIFO)</div>
            @endif
            <div><strong>Dicetak Oleh:</strong> {{ auth()->user()?->name ?? 'Admin PPIC' }}</div>
        </div>
    </div>

    <!-- Summary Box -->
    <div class="summary-bar">
        <div class="summary-box">
            <div class="label">Total Traveler Card</div>
            <div class="value">{{ number_format($summary['total_trees']) }} Pohon</div>
            <div class="subtext">WIP Siap Cor</div>
        </div>
        <div class="summary-box">
            <div class="label">Total Qty Siap Cor</div>
            <div class="value" style="color: #047857;">{{ number_format($summary['total_usable_pcs']) }} PCS</div>
            <div class="subtext">Bruto: {{ number_format($summary['total_gross_pcs']) }} | Afkir: {{ number_format($summary['total_defect_pcs']) }}</div>
        </div>
        <div class="summary-box">
            <div class="label">Total Kode Produksi</div>
            <div class="value">{{ number_format($summary['total_codes']) }} Kode</div>
            <div class="subtext">Prioritas FIFO Scan Siap Cor</div>
        </div>
    </div>

    <!-- Tabel Lembar Kontrol -->
    <table class="print-table">
        <thead>
            <tr>
                <th style="width: 25px;" class="text-center">No.</th>
                <th style="width: 140px;">Kode Produksi &amp; Customer</th>
                <th style="width: 130px;">Traveler Card / Barcode</th>
                <th style="width: 65px;" class="text-right">Qty (PCS)</th>
                <th style="width: 135px;" class="text-center">LOKASI RAK / CATATAN PPIC</th>
                <th style="width: 135px;" class="text-center">KETERANGAN / PARAF</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
                <tr class="data-row">
                    <td class="text-center font-bold" style="color: #334155;">
                        {{ $loop->iteration }}
                    </td>
                    <td>
                        <div class="font-bold font-mono" style="font-size: 10px; color: #0f172a;">{{ $item['production_code'] }}</div>
                        <div style="font-size: 7.5px; color: #475569;">{{ $item['customer'] }} &bull; {{ $item['item_name'] }}</div>
                        @if($item['material'] !== '-')
                            <div style="font-size: 7px; color: #64748b;">Mat: {{ $item['material'] }}</div>
                        @endif
                    </td>
                    <td>
                        <div class="font-bold font-mono" style="font-size: 9.5px; color: #0f172a;">{{ $item['human_barcode'] }}</div>
                        <div class="font-mono" style="font-size: 7.5px; color: #64748b;">{{ $item['barcode'] }} &bull; Selesai: {{ $item['scanned_at_formatted'] }}</div>
                    </td>
                    <td class="text-right font-bold" style="font-size: 10px; color: #047857;">
                        {{ number_format($item['quantity_pcs']) }}
                    </td>
                    <td>
                        <div class="handwrite-box"></div>
                    </td>
                    <td>
                        <div class="handwrite-box"></div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center" style="padding: 24px; color: #94a3b8;">
                        Tidak ada persediaan cangkang pasca dewaxing saat ini.
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if($items->isNotEmpty())
            <tfoot>
                <tr style="background-color: #f8fafc; font-weight: bold;">
                    <td colspan="3" class="text-right" style="padding: 5px 8px; text-transform: uppercase;">
                        TOTAL {{ $summary['is_filtered'] ? 'HASIL FILTER' : 'KESELURUHAN' }} ({{ number_format($summary['total_trees']) }} POHON):
                    </td>
                    <td class="text-right" style="font-size: 10px; color: #047857;">
                        {{ number_format($summary['total_usable_pcs']) }} PCS
                    </td>
                    <td colspan="2" style="font-size: 7.5px; color: #64748b; font-style: italic; text-align: center;">
                        *Persediaan cangkang matang di rak buffer pasca dewaxing
                    </td>
                </tr>
            </tfoot>
        @endif
    </table>

    <div style="margin-top: 4px; font-size: 7.5px; color: #64748b; font-style: italic;">
        *Catatan: Dokumen ini menyajikan persediaan cangkang matang di rak buffer pasca dewaxing. Urutan baris diurutkan secara FIFO berdasarkan waktu Selesai Dewaxing terawal. Realisasi pengecoran dicatat pada formulir Rencana Pengecoran atau Bukti Hasil Cor.
    </div>

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

