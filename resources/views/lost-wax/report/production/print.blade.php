<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Produksi Lost Wax ({{ $activeFilters['date_from'] }} s/d {{ $activeFilters['date_to'] }})</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 12mm 15mm 12mm 15mm;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #1e293b;
            margin: 0;
            padding: 0;
            background: #ffffff;
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }

        .header h1 {
            margin: 0;
            font-size: 16px;
            font-weight: bold;
            color: #0f172a;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .header .meta {
            margin-top: 4px;
            font-size: 10px;
            color: #475569;
        }

        .kpi-container {
            margin-bottom: 14px;
        }

        .kpi-table {
            width: 100%;
            border-collapse: collapse;
            text-align: center;
        }

        .kpi-table th {
            background-color: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 5px 8px;
            font-size: 9px;
            text-transform: uppercase;
            font-weight: bold;
        }

        .kpi-table td {
            border: 1px solid #cbd5e1;
            padding: 6px;
            font-size: 12px;
            font-weight: bold;
            font-family: monospace;
        }

        .kpi-table .grand-total {
            background-color: #eff6ff;
            color: #1e40af;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }

        .data-table th {
            background-color: #e2e8f0;
            border: 1px solid #94a3b8;
            padding: 6px 8px;
            font-size: 10px;
            text-transform: uppercase;
            font-weight: bold;
            text-align: left;
        }

        .data-table td {
            border: 1px solid #cbd5e1;
            padding: 5px 8px;
            font-size: 10px;
        }

        .data-table tr:nth-child(even) {
            background-color: #f8fafc;
        }

        .text-center {
            text-align: center !important;
        }

        .text-right {
            text-align: right !important;
        }

        .font-mono {
            font-family: monospace;
        }

        .font-bold {
            font-weight: bold;
        }

        .badge-stage {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 9px;
            border: 1px solid #94a3b8;
            background: #f1f5f9;
        }

        .footer {
            margin-top: 24px;
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #64748b;
        }

        .sign-table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }

        .sign-table td {
            width: 33.33%;
            text-align: center;
            vertical-align: top;
            padding: 0 10px;
        }

        .sign-box {
            height: 55px;
        }

        /* Non-print toolbar */
        .no-print {
            background: #1e293b;
            color: white;
            padding: 10px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            border-radius: 6px;
        }

        .btn {
            display: inline-block;
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
            color: white;
        }

        .btn-close {
            background: #475569;
            color: white;
            margin-left: 8px;
        }

        @media print {
            .no-print {
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

    {{-- Interactive Top Bar for Previewing before Printing --}}
    <div class="no-print">
        <span style="font-weight: bold; color: #f8fafc;">Pratinjau Dokumen Report Produksi Lost Wax</span>
        <div>
            <button onclick="window.print()" class="btn btn-print">Cetak Sekarang (Print / Save as PDF)</button>
            <button onclick="window.close()" class="btn btn-close">Tutup Tab</button>
        </div>
    </div>

    {{-- Report Header --}}
    <div class="header">
        <h1>REPORT PRODUKSI LOST WAX</h1>
        <div class="meta">
            @php
                $stageTitle = $activeFilters['stage'] === 'all'
                    ? 'SEMUA TAHAPAN'
                    : strtoupper($stages[$activeFilters['stage']] ?? $activeFilters['stage']);
            @endphp
            <strong>Periode:</strong> {{ \Carbon\Carbon::parse($activeFilters['date_from'])->format('d/m/Y') }} s/d {{ \Carbon\Carbon::parse($activeFilters['date_to'])->format('d/m/Y') }}
            &nbsp;|&nbsp;
            <strong>Tahapan:</strong> {{ $stageTitle }}
            @if(!empty($activeFilters['search']))
                &nbsp;|&nbsp;
                <strong>Pencarian:</strong> "{{ $activeFilters['search'] }}"
            @endif
            &nbsp;|&nbsp;
            <strong>Dicetak Pada:</strong> {{ \Carbon\Carbon::now()->format('d/m/Y H:i') }}
        </div>
    </div>

    {{-- Summary Table --}}
    <div class="kpi-container">
        <table class="kpi-table">
            <thead>
                <tr>
                    <th>Total Qty Dikerjakan</th>
                    <th>Total Berat Produksi</th>
                    <th>Total Rusak (Defect)</th>
                    <th>Jumlah Baris Item</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="grand-total">{{ number_format($summary['total_qty']) }} pcs</td>
                    <td style="color: #047857;">{{ number_format($summary['total_weight'], 2) }} kg</td>
                    <td style="color: {{ $summary['total_defect'] > 0 ? '#b91c1c' : '#334155' }};">
                        {{ number_format($summary['total_defect']) }} pcs
                    </td>
                    <td>{{ number_format($summary['total_records']) }} baris</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Main Data Table --}}
    <table class="data-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 30px;">No</th>
                <th style="width: 90px;">Kode Produksi</th>
                <th style="width: 90px;">Kode Customer</th>
                <th>Nama Barang</th>
                <th class="text-center" style="width: 80px;">Tahapan</th>
                <th class="text-right" style="width: 70px;">Berat (kg/pcs)</th>
                <th class="text-right" style="width: 85px;">Qty Dikerjakan</th>
                <th class="text-right" style="width: 85px;">Total Berat</th>
                <th class="text-right" style="width: 75px;">Qty Rusak</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $idx => $item)
                <tr>
                    <td class="text-center font-mono">{{ $idx + 1 }}</td>
                    <td class="font-mono font-bold">{{ $item['production_code'] }}</td>
                    <td>{{ $item['customer_code'] ?: '-' }}</td>
                    <td class="font-bold">{{ $item['item_name'] }}</td>
                    <td class="text-center">
                        <span class="badge-stage">{{ $item['stage_label'] }}</span>
                    </td>
                    <td class="text-right font-mono">{{ number_format($item['weight'], 2) }} kg</td>
                    <td class="text-right font-mono font-bold">{{ number_format($item['qty_processed']) }} pcs</td>
                    <td class="text-right font-mono font-bold" style="color: #047857;">{{ number_format($item['total_weight'], 2) }} kg</td>
                    <td class="text-right font-mono {{ $item['qty_defect'] > 0 ? 'font-bold' : '' }}" style="{{ $item['qty_defect'] > 0 ? 'color: #b91c1c;' : 'color: #64748b;' }}">
                        {{ number_format($item['qty_defect']) }} pcs
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-center" style="padding: 20px; color: #94a3b8;">
                        Tidak ada catatan aktivitas produksi yang sesuai dengan filter pada periode ini.
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if($items->isNotEmpty())
            <tfoot>
                <tr style="background-color: #f1f5f9; font-weight: bold; border-top: 2px solid #0f172a;">
                    <td colspan="5" class="text-center" style="padding: 6px; text-transform: uppercase;">TOTAL KESELURUHAN</td>
                    <td class="text-center font-mono">-</td>
                    <td class="text-right font-mono" style="color: #1e40af;">{{ number_format($summary['total_qty']) }} pcs</td>
                    <td class="text-right font-mono" style="color: #047857;">{{ number_format($summary['total_weight'], 2) }} kg</td>
                    <td class="text-right font-mono" style="{{ $summary['total_defect'] > 0 ? 'color: #b91c1c;' : '' }}">
                        {{ number_format($summary['total_defect']) }} pcs
                    </td>
                </tr>
            </tfoot>
        @endif
    </table>

    {{-- Official Signatures Section --}}
    <table class="sign-table">
        <tr>
            <td>
                <div>Dibuat Oleh,</div>
                <div class="sign-box"></div>
                <div style="font-weight: bold; text-decoration: underline;">( Admin / PPIC )</div>
            </td>
            <td>
                <div>Diperiksa Oleh,</div>
                <div class="sign-box"></div>
                <div style="font-weight: bold; text-decoration: underline;">( SPV / QC )</div>
            </td>
            <td>
                <div>Disetujui Oleh,</div>
                <div class="sign-box"></div>
                <div style="font-weight: bold; text-decoration: underline;">( Direktur )</div>
            </td>
        </tr>
    </table>

    <script>
        window.onload = function() {
            // Uncomment if auto-print popup is desired on page load
            // window.print();
        };
    </script>
</body>
</html>
