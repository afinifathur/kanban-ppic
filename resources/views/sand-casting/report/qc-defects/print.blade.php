<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Rincian Defect QC Sand Casting ({{ $filters['date_from'] }} s/d {{ $filters['date_to'] }})</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 8mm 10mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 8.5px;
            color: #0f172a;
            margin: 0;
            padding: 0;
            background: #ffffff;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Interactive Non-print Toolbar */
        .no-print {
            background: #0f172a;
            color: #ffffff;
            padding: 8px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            border-radius: 4px;
        }

        .no-print-title {
            font-weight: bold;
            font-size: 11px;
            color: #f8fafc;
        }

        .btn {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: bold;
            font-size: 10px;
            cursor: pointer;
            border: none;
        }

        .btn-print {
            background: #2563eb;
            color: #ffffff;
        }

        .btn-close {
            background: #475569;
            color: #ffffff;
            margin-left: 6px;
        }

        @media print {
            .no-print {
                display: none !important;
            }
        }

        /* Document Corporate Header */
        .header {
            text-align: left;
            border-bottom: 1.5px solid #0f172a;
            padding-bottom: 6px;
            margin-bottom: 8px;
        }

        .company-name {
            font-size: 9px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin: 0 0 2px 0;
        }

        .report-title {
            font-size: 13px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.01em;
            margin: 0 0 3px 0;
            text-transform: uppercase;
        }

        .meta-strip {
            font-size: 7.5px;
            color: #334155;
            background: #f8fafc;
            padding: 3px 6px;
            border: 0.5px solid #cbd5e1;
            border-radius: 2px;
            margin-top: 4px;
            line-height: 1.3;
        }

        /* KPI Box */
        .kpi-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        .kpi-table th, .kpi-table td {
            border: 0.5px solid #94a3b8;
            padding: 3px 5px;
            text-align: center;
        }

        .kpi-table th {
            background: #f1f5f9 !important;
            font-size: 7.5px;
            font-weight: bold;
            color: #475569;
            text-transform: uppercase;
        }

        .kpi-table td {
            font-size: 10px;
            font-weight: 800;
            font-family: 'Courier New', Courier, monospace;
            color: #0f172a;
        }

        /* Section Headings */
        .section-title {
            font-size: 8.5px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            margin: 8px 0 4px 0;
            border-left: 3px solid #2563eb;
            padding-left: 5px;
        }

        /* Tables */
        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 7.5px;
            margin-bottom: 10px;
        }

        .report-table th, .report-table td {
            border: 0.5px solid #64748b;
            padding: 2.5px 3.5px;
            vertical-align: middle;
            line-height: 1.15;
        }

        .report-table th {
            background: #0f172a !important;
            color: #ffffff !important;
            font-weight: 800;
            text-align: center;
        }

        .report-table tbody tr:nth-child(even) {
            background-color: #f8fafc !important;
        }

        .report-table td.center { text-align: center; }
        .report-table td.right { text-align: right; }
        .report-table td.left { text-align: left; }
        .report-table td.bold { font-weight: bold; }
        .report-table td.mono { font-family: 'Courier New', Courier, monospace; }

        .report-table tfoot td {
            background: #e2e8f0 !important;
            font-weight: 800;
            border-top: 1px solid #0f172a;
        }

        .badge-stage {
            display: inline-block;
            padding: 1px 3px;
            font-size: 7px;
            font-weight: bold;
            border-radius: 2px;
            border: 0.5px solid #94a3b8;
            background: #f1f5f9 !important;
        }

        .notes-text {
            font-size: 7px;
            color: #475569;
            font-style: italic;
            margin-top: 2px;
        }

        thead { display: table-header-group !important; }
        tr { break-inside: avoid !important; page-break-inside: avoid !important; }
    </style>
</head>
<body>

    <!-- Non-print Toolbar -->
    <div class="no-print">
        <div class="no-print-title">
            Preview Cetak — Laporan Rincian Defect QC Sand Casting
        </div>
        <div>
            <button class="btn btn-print" onclick="window.print()">
                Cetak Dokumen / Simpan PDF
            </button>
            <button class="btn btn-close" onclick="window.close()">
                Tutup Jendela
            </button>
        </div>
    </div>

    <!-- Corporate Header -->
    <div class="header">
        <div class="company-name">PT. PERONI KARYA UTAMA</div>
        <div class="report-title">LAPORAN RINCIAN DEFECT QC — SAND CASTING</div>
        <div class="meta-strip">
            <strong>Periode Laporan:</strong> {{ \Carbon\Carbon::parse($filters['date_from'])->format('d/m/Y') }} s/d {{ \Carbon\Carbon::parse($filters['date_to'])->format('d/m/Y') }}
            &nbsp;|&nbsp;
            <strong>Tahapan:</strong> {{ $filters['stage'] === 'all' ? 'SEMUA TAHAPAN' : strtoupper($stageLabels[$filters['stage']] ?? $filters['stage']) }}
            &nbsp;|&nbsp;
            <strong>Status Sumber:</strong> Verifikasi QC (CONFIRMED)
            &nbsp;|&nbsp;
            <strong>Dicetak Oleh:</strong> {{ auth()->user()->name ?? 'System' }} ({{ now()->format('d/m/Y H:i') }})
        </div>
    </div>

    <!-- KPI Summary Boxes -->
    <table class="kpi-table">
        <thead>
            <tr>
                <th style="width: 20%;">TOTAL DEFECT (PCS)</th>
                <th style="width: 25%;">ESTIMASI BERAT DEFECT (KG)*</th>
                <th style="width: 20%;">KTR TERDAMPAK</th>
                <th style="width: 20%;">JENIS CACAT TERBANYAK</th>
                <th style="width: 15%;">BEBAS CACAT (0 DEFECT)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="color: #b91c1c;">{{ number_format($summary['total_defect_pcs']) }} PCS</td>
                <td>{{ number_format($summary['total_estimated_kg'], 2) }} KG</td>
                <td>{{ number_format($summary['affected_ktr_count']) }} KTR</td>
                <td style="font-family: inherit; font-size: 8.5px;">{{ $summary['top_defect_type'] }}</td>
                <td style="color: #047857;">{{ number_format($summary['zero_defect_ktr_count']) }} KTR</td>
            </tr>
        </tbody>
    </table>
    <div class="notes-text" style="margin-top: -6px; margin-bottom: 8px;">
        * Catatan: Metrik utama adalah kuantitas fisik (PCS). Estimasi berat dihitung berdasarkan unit weight master part dan bukan hasil timbangan timbang riil scrap. Coverage data berat: {{ $summary['weight_coverage_pct'] }}%.
    </div>

    <!-- Section 1: Pareto Summary Table -->
    <div class="section-title">1. Ringkasan Pareto Defect (Per Jenis Cacat & Tahapan)</div>
    <table class="report-table">
        <thead>
            <tr>
                <th style="width: 5%;">No</th>
                <th style="width: 32%;">Jenis Cacat</th>
                <th style="width: 15%;">Tahap Produksi</th>
                <th style="width: 13%;">Jumlah Rusak (PCS)</th>
                <th style="width: 10%;">Kontribusi (%)</th>
                <th style="width: 13%;">Estimasi Berat (KG)</th>
                <th style="width: 12%;">Frekuensi KTR</th>
            </tr>
        </thead>
        <tbody>
            @forelse($paretoSummary as $pIdx => $p)
                <tr>
                    <td class="center mono">{{ $pIdx + 1 }}</td>
                    <td class="left bold">{{ $p['defect_name'] }}</td>
                    <td class="center"><span class="badge-stage">{{ $p['stage_label'] }}</span></td>
                    <td class="right bold mono" style="color: #b91c1c;">{{ number_format($p['qty_pcs']) }}</td>
                    <td class="right mono">{{ number_format($p['contribution_pct'], 1) }}%</td>
                    <td class="right mono">{{ number_format($p['estimated_kg'], 2) }}</td>
                    <td class="right mono">{{ number_format($p['ktr_count']) }} KTR</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="center" style="padding: 10px; color: #64748b;">
                        Tidak ada data defect QC pada periode ini.
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if(count($paretoSummary) > 0)
            <tfoot>
                <tr>
                    <td colspan="3" class="right bold">TOTAL TERVERIFIKASI:</td>
                    <td class="right bold mono" style="color: #b91c1c;">{{ number_format($summary['total_defect_pcs']) }}</td>
                    <td class="right bold mono">100.0%</td>
                    <td class="right bold mono">{{ number_format($summary['total_estimated_kg'], 2) }}</td>
                    <td class="right bold mono">{{ number_format($summary['affected_ktr_count']) }} KTR</td>
                </tr>
            </tfoot>
        @endif
    </table>

    <!-- Section 2: Detailed Traceability Table -->
    <div class="section-title" style="page-break-before: auto;">2. Rincian Data Defect QC (Traceability Detail)</div>
    <table class="report-table">
        <thead>
            <tr>
                <th style="width: 3%;">No</th>
                <th style="width: 7%;">Tanggal QC</th>
                <th style="width: 11%;">KTR (Traveler)</th>
                <th style="width: 8%;">Heat Number</th>
                <th style="width: 7%;">Kode Plan</th>
                <th style="width: 6%;">PO</th>
                <th style="width: 16%;">Nama Barang & Customer</th>
                <th style="width: 7%;">Tahapan</th>
                <th style="width: 11%;">Jenis Cacat</th>
                <th style="width: 5%;">Qty</th>
                <th style="width: 6%;">Berat (KG)</th>
                <th style="width: 7%;">Verifikator</th>
                <th style="width: 6%;">Catatan</th>
            </tr>
        </thead>
        <tbody>
            @forelse($records as $idx => $r)
                <tr>
                    <td class="center mono">{{ $idx + 1 }}</td>
                    <td class="center mono">{{ $r['date'] }}</td>
                    <td class="center mono bold">{{ $r['traveler_number'] }}</td>
                    <td class="center mono bold">{{ $r['heat_number'] }}</td>
                    <td class="center mono">{{ $r['production_code'] }}</td>
                    <td class="center mono">{{ $r['po_number'] }}</td>
                    <td class="left">
                        <div class="bold">{{ $r['item_name'] }}</div>
                        <div style="font-size: 6.5px; color: #64748b;">{{ $r['customer'] }}</div>
                    </td>
                    <td class="center"><span class="badge-stage">{{ $r['stage_label'] }}</span></td>
                    <td class="left bold">{{ $r['defect_name'] }}</td>
                    <td class="right bold mono" style="color: #b91c1c;">{{ number_format($r['qty']) }}</td>
                    <td class="right mono">{{ $r['has_weight'] ? number_format($r['estimated_kg'], 2) : '—' }}</td>
                    <td class="center">{{ $r['qc_verifier'] }}</td>
                    <td class="left" style="font-size: 7px; color: #64748b;">{{ $r['notes'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="13" class="center" style="padding: 10px; color: #64748b;">
                        Tidak ada data rincian defect QC yang memenuhi filter pencarian.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

</body>
</html>
