<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Produksi Fitting ({{ $activeFilters['date_from'] }} s/d {{ $activeFilters['date_to'] }})</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 10mm 12mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10px;
            color: #1e293b;
            margin: 0;
            padding: 0;
            background: #ffffff;
        }

        /* Interactive Non-print Toolbar */
        .no-print {
            background: #064e3b;
            color: #ffffff;
            padding: 10px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            border-radius: 6px;
        }

        .no-print-title {
            font-weight: bold;
            font-size: 13px;
            color: #f8fafc;
            letter-spacing: 0.3px;
        }

        .btn {
            display: inline-block;
            padding: 7px 16px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: bold;
            font-size: 12px;
            cursor: pointer;
            border: none;
            transition: opacity 0.15s ease-in-out;
        }

        .btn:hover {
            opacity: 0.9;
        }

        .btn-print {
            background: #10b981;
            color: #ffffff;
        }

        .btn-close {
            background: #475569;
            color: #ffffff;
            margin-left: 8px;
        }

        /* Document Header */
        .header {
            text-align: center;
            border-bottom: 2px solid #064e3b;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }

        .header h1 {
            margin: 0;
            font-size: 16px;
            font-weight: bold;
            color: #064e3b;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .header .meta {
            margin-top: 4px;
            font-size: 10px;
            color: #475569;
        }

        /* KPI Summary Boxes */
        .kpi-container {
            margin-bottom: 12px;
        }

        .kpi-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }

        .kpi-table td {
            width: 20%;
            border: 1px solid #cbd5e1;
            padding: 6px 10px;
            text-align: center;
            background: #f8fafc;
        }

        .kpi-label {
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            color: #475569;
            margin-bottom: 2px;
        }

        .kpi-value {
            font-size: 13px;
            font-weight: bold;
            color: #0f172a;
        }

        .kpi-value.good {
            color: #047857;
        }

        .kpi-value.defect {
            color: #be123c;
        }

        /* Section Headings */
        .section-title {
            font-size: 11px;
            font-weight: bold;
            color: #0f172a;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        /* Data Tables */
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            font-size: 9.5px;
        }

        table.data-table th,
        table.data-table td {
            border: 1px solid #94a3b8;
            padding: 4px 6px;
        }

        table.data-table th {
            background: #e2e8f0;
            font-weight: bold;
            color: #0f172a;
            text-transform: uppercase;
            font-size: 9px;
        }

        table.data-table tr:nth-child(even) {
            background: #f8fafc;
        }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .font-bold { font-weight: bold; }
        .font-mono { font-family: monospace, monospace; }

        .stage-badge {
            display: inline-block;
            padding: 1px 4px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 8.5px;
            border: 1px solid #cbd5e1;
        }

        /* Signature area */
        .signature-table {
            width: 100%;
            margin-top: 24px;
            border-collapse: collapse;
            page-break-inside: avoid;
        }

        .signature-table td {
            width: 33.33%;
            text-align: center;
            vertical-align: top;
            font-size: 9px;
        }

        .signature-space {
            height: 48px;
        }

        @media print {
            .no-print {
                display: none !important;
            }
            body {
                padding: 0;
                background: #ffffff;
            }
        }
    </style>
</head>
<body>

    {{-- Interactive Toolbar (Hidden during print) --}}
    <div class="no-print">
        <div class="no-print-title">
            Preview Dokumen Cetak — Laporan Produksi Fitting
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-print">
                Cetak Dokumen
            </button>
            <button onclick="window.close()" class="btn btn-close">
                Tutup
            </button>
        </div>
    </div>

    {{-- Document Header --}}
    <div class="header">
        <h1>Laporan Produksi Fitting Production</h1>
        <div class="meta">
            Periode: <strong>{{ \Carbon\Carbon::parse($activeFilters['date_from'])->format('d M Y') }}</strong> s/d <strong>{{ \Carbon\Carbon::parse($activeFilters['date_to'])->format('d M Y') }}</strong>
            | Tahapan: <strong>{{ $activeFilters['stage'] === 'all' ? 'SEMUA TAHAPAN (6 TAHAP)' : strtoupper($stages[$activeFilters['stage']] ?? $activeFilters['stage']) }}</strong>
            @if(!empty($activeFilters['line']))
                | Line: <strong>{{ \App\Models\FittingItem::lineLabelFor($activeFilters['line']) }}</strong>
            @endif
            | Dicetak pada: {{ now()->format('d/m/Y H:i:s') }}
        </div>
    </div>

    {{-- KPI Summary Boxes --}}
    <div class="kpi-container">
        <table class="kpi-table">
            <tr>
                <td>
                    <div class="kpi-label">Total Aktivitas PFKTR</div>
                    <div class="kpi-value font-mono">{{ number_format($summary['total_pfktr_activities']) }} KTR</div>
                </td>
                <td>
                    <div class="kpi-label">Hasil Kerja (Input)</div>
                    <div class="kpi-value font-mono">{{ number_format($summary['total_input_pcs']) }} pcs</div>
                </td>
                <td>
                    <div class="kpi-label">Total Reject</div>
                    <div class="kpi-value font-mono defect">{{ number_format($summary['total_defect_pcs']) }} pcs</div>
                </td>
                <td>
                    <div class="kpi-label">Good Forward</div>
                    <div class="kpi-value font-mono good">{{ number_format($summary['total_good_pcs']) }} pcs</div>
                </td>
                <td>
                    <div class="kpi-label">Berat Good (Tonase)</div>
                    <div class="kpi-value font-mono">{{ number_format($summary['total_weight_kg'], 2) }} kg ({{ $summary['total_weight_ton'] }} Ton)</div>
                </td>
            </tr>
        </table>
    </div>

    {{-- 1. Stage Summary Table --}}
    <div class="section-title">I. Ringkasan Hasil Produksi per Tahapan</div>
    <table class="data-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 30px;">No</th>
                <th class="text-left">Tahapan</th>
                <th class="text-center" style="width: 70px;">PFKTR</th>
                <th class="text-right" style="width: 75px;">Hasil Kerja (In)</th>
                <th class="text-right" style="width: 65px;">Reject (pcs)</th>
                <th class="text-right" style="width: 75px;">Good Forward</th>
                <th class="text-right" style="width: 80px;">Berat (kg)</th>
                <th class="text-right" style="width: 55px;">% Reject</th>
                <th class="text-right" style="width: 60px;">Line 1</th>
                <th class="text-right" style="width: 60px;">Line 2</th>
                <th class="text-right" style="width: 60px;">Line 3</th>
                <th class="text-right" style="width: 60px;">Line 4</th>
            </tr>
        </thead>
        <tbody>
            @foreach($stageSummaries as $idx => $stg)
                <tr>
                    <td class="text-center font-mono">{{ $idx + 1 }}</td>
                    <td class="font-bold">{{ $stg['stage_label'] }}</td>
                    <td class="text-center font-mono">{{ number_format($stg['ktr_count']) }}</td>
                    <td class="text-right font-mono">{{ number_format($stg['input_pcs']) }}</td>
                    <td class="text-right font-mono {{ $stg['defect_pcs'] > 0 ? 'defect font-bold' : '' }}">
                        {{ number_format($stg['defect_pcs']) }}
                    </td>
                    <td class="text-right font-mono font-bold">{{ number_format($stg['good_pcs']) }}</td>
                    <td class="text-right font-mono">{{ number_format($stg['weight_kg'], 2) }}</td>
                    <td class="text-right font-mono">{{ number_format($stg['defect_rate'], 2) }}%</td>
                    <td class="text-right font-mono">{{ number_format($stg['line_breakdown'][1]['pcs'] ?? 0) }}</td>
                    <td class="text-right font-mono">{{ number_format($stg['line_breakdown'][2]['pcs'] ?? 0) }}</td>
                    <td class="text-right font-mono">{{ number_format($stg['line_breakdown'][3]['pcs'] ?? 0) }}</td>
                    <td class="text-right font-mono">{{ number_format($stg['line_breakdown'][4]['pcs'] ?? 0) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="background: #cbd5e1; font-weight: bold;">
                <td colspan="2" class="text-left font-bold">TOTAL KESELURUHAN</td>
                <td class="text-center font-mono">{{ number_format($summary['total_pfktr_activities']) }}</td>
                <td class="text-right font-mono">{{ number_format($summary['total_input_pcs']) }}</td>
                <td class="text-right font-mono">{{ number_format($summary['total_defect_pcs']) }}</td>
                <td class="text-right font-mono">{{ number_format($summary['total_good_pcs']) }}</td>
                <td class="text-right font-mono">{{ number_format($summary['total_weight_kg'], 2) }}</td>
                <td class="text-right font-mono">{{ number_format($summary['overall_defect_rate'], 2) }}%</td>
                <td colspan="4" class="text-center">-</td>
            </tr>
        </tfoot>
    </table>

    {{-- 2. Detail Rows Table --}}
    <div class="section-title">II. Rincian Aktivitas Produksi per PFKTR</div>
    <table class="data-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 25px;">No</th>
                <th class="text-center" style="width: 80px;">Tgl Selesai</th>
                <th class="text-left" style="width: 100px;">PFKTR</th>
                <th class="text-center" style="width: 45px;">Line</th>
                <th class="text-left" style="width: 75px;">Kode SPK</th>
                <th class="text-left" style="width: 110px;">Nama Produk</th>
                <th class="text-left" style="width: 90px;">Customer</th>
                <th class="text-center" style="width: 60px;">Heat</th>
                <th class="text-center" style="width: 75px;">Tahapan</th>
                <th class="text-right" style="width: 45px;">In</th>
                <th class="text-right" style="width: 40px;">Reject</th>
                <th class="text-right" style="width: 45px;">Good</th>
                <th class="text-right" style="width: 50px;">Kg</th>
                <th class="text-left" style="width: 70px;">Operator</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $idx => $item)
                <tr>
                    <td class="text-center font-mono">{{ $idx + 1 }}</td>
                    <td class="text-center font-mono">{{ $item['physical_done_at'] }}</td>
                    <td class="text-left font-mono font-bold">{{ $item['traveler_number'] }}</td>
                    <td class="text-center font-mono">{{ $item['line_label'] }}</td>
                    <td class="text-left font-mono">
                        {{ $item['is_multi_allocation'] ? 'MULTI-SPK (' . count($item['allocations']) . ')' : $item['production_code'] }}
                    </td>
                    <td class="text-left">{{ $item['item_name'] }}</td>
                    <td class="text-left">{{ $item['customer'] }}</td>
                    <td class="text-center font-mono">{{ $item['heat_number'] }}</td>
                    <td class="text-center font-bold">{{ $item['stage_label'] }}</td>
                    <td class="text-right font-mono">{{ number_format($item['input_qty']) }}</td>
                    <td class="text-right font-mono {{ $item['defect_qty'] > 0 ? 'defect font-bold' : '' }}">
                        {{ number_format($item['defect_qty']) }}
                    </td>
                    <td class="text-right font-mono font-bold">{{ number_format($item['good_qty']) }}</td>
                    <td class="text-right font-mono">{{ number_format($item['total_weight'], 2) }}</td>
                    <td class="text-left">{{ $item['operator'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="14" class="text-center" style="padding: 18px; color: #64748b;">
                        Tidak ada catatan aktivitas produksi fisik pada periode filter ini.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- Signatures --}}
    <table class="signature-table">
        <tr>
            <td>
                Dibuat Oleh,<br>
                <strong>Admin PPIC Fitting</strong>
                <div class="signature-space"></div>
                ( .................................................. )
            </td>
            <td>
                Diperiksa Oleh,<br>
                <strong>SPV Fitting Production</strong>
                <div class="signature-space"></div>
                ( .................................................. )
            </td>
            <td>
                Disetujui Oleh,<br>
                <strong>Manajer Produksi</strong>
                <div class="signature-space"></div>
                ( .................................................. )
            </td>
        </tr>
    </table>

</body>
</html>
