<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan QC Defect Fitting Production ({{ $filters['date_from'] }} s/d {{ $filters['date_to'] }})</title>
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
            background: #059669;
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

        /* Document Header */
        .header {
            text-align: left;
            border-bottom: 1.5px solid #064e3b;
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
            color: #064e3b;
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
            border-left: 3px solid #059669;
            padding-left: 5px;
        }

        /* Tables */
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
            font-size: 7.5px;
        }

        table.data-table th, table.data-table td {
            border: 0.5px solid #94a3b8;
            padding: 2.5px 4px;
        }

        table.data-table th {
            background: #e2e8f0 !important;
            color: #0f172a;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 7px;
        }

        table.data-table tr:nth-child(even) td {
            background: #f8fafc;
        }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .font-mono { font-family: 'Courier New', Courier, monospace; }
        .font-bold { font-weight: bold; }
        .text-rose { color: #be123c; }

        /* Signature Block */
        .signature-table {
            width: 100%;
            margin-top: 14px;
            border-collapse: collapse;
            page-break-inside: avoid;
        }

        .signature-table td {
            width: 33.33%;
            text-align: center;
            vertical-align: top;
            font-size: 7.5px;
        }

        .signature-space {
            height: 38px;
        }

        .signature-name {
            font-weight: bold;
            text-decoration: underline;
        }
    </style>
</head>
<body>

    {{-- Interactive Toolbar --}}
    <div class="no-print">
        <div class="no-print-title">
            Preview Dokumen Cetak — Laporan QC Defect Fitting Production
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
        <div class="company-name">PT Peroni Karya Sentosa &nbsp;·&nbsp; Sistem Kanban PPIC</div>
        <div class="report-title">Laporan Rincian QC Defect Fitting Production (CONFIRMED)</div>
        <div class="meta-strip">
            <strong>Periode:</strong> {{ \Carbon\Carbon::parse($filters['date_from'])->format('d M Y') }} s/d {{ \Carbon\Carbon::parse($filters['date_to'])->format('d M Y') }}
            &nbsp;|&nbsp; <strong>Tahapan:</strong> {{ $filters['stage'] === 'all' ? 'SEMUA TAHAP (6 TAHAPAN)' : strtoupper($stages[$filters['stage']] ?? $filters['stage']) }}
            @if(!empty($filters['line']))
                &nbsp;|&nbsp; <strong>Line:</strong> {{ \App\Models\FittingItem::lineLabelFor($filters['line']) }}
            @endif
            @if(!empty($filters['search']))
                &nbsp;|&nbsp; <strong>Kata Kunci:</strong> "{{ $filters['search'] }}"
            @endif
            &nbsp;|&nbsp; <strong>Basis Tanggal:</strong> Tanggal Kejadian / Inspeksi
            &nbsp;|&nbsp; <strong>Dicetak:</strong> {{ now()->format('d/m/Y H:i') }} WIB
        </div>
    </div>

    {{-- KPI Summary Boxes --}}
    <table class="kpi-table">
        <tr>
            <th>Total Defect Terverifikasi</th>
            <th>Estimasi Berat Defect</th>
            <th>PFKTR Terdampak Cacat</th>
            <th>Jenis Cacat Dominan</th>
            <th>Total Baris Rincian</th>
        </tr>
        <tr>
            <td class="text-rose">{{ number_format($summary['total_defect_pcs']) }} PCS</td>
            <td>{{ number_format($summary['total_estimated_kg'], 2) }} KG</td>
            <td>{{ number_format($summary['affected_ktr_count']) }} TRAVELER</td>
            <td style="font-size: 8.5px; font-weight: bold;">{{ $summary['top_defect_type'] }}</td>
            <td>{{ number_format($summary['total_records_count']) }} BARIS</td>
        </tr>
    </table>

    {{-- SECTION I: STAGE DISTRIBUTION SUMMARY --}}
    <div class="section-title">I. Ringkasan Distribusi Defect per Tahapan (6 Tahap Fitting)</div>
    <table class="data-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 25px;">No</th>
                <th class="text-left">Tahapan Produksi</th>
                <th class="text-center" style="width: 70px;">PFKTR Terdampak</th>
                <th class="text-right" style="width: 80px;">Defect (PCS)</th>
                <th class="text-right" style="width: 60px;">Kontribusi (%)</th>
                <th class="text-right" style="width: 75px;">Berat (KG)</th>
                <th class="text-left">Jenis Cacat Terbanyak</th>
            </tr>
        </thead>
        <tbody>
            @php $stgIdx = 1; @endphp
            @foreach($stageDistribution as $stgKey => $sd)
                <tr>
                    <td class="text-center font-mono">{{ $stgIdx++ }}</td>
                    <td class="font-bold">{{ $sd['stage_label'] }}</td>
                    <td class="text-center font-mono">{{ number_format($sd['affected_ktr_count']) }} KTR</td>
                    <td class="text-right font-mono font-bold {{ $sd['defect_pcs'] > 0 ? 'text-rose' : '' }}">
                        {{ number_format($sd['defect_pcs']) }}
                    </td>
                    <td class="text-right font-mono">{{ number_format($sd['contribution_pct'], 1) }}%</td>
                    <td class="text-right font-mono">{{ number_format($sd['estimated_kg'], 2) }}</td>
                    <td>{{ $sd['top_defect_name'] }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="background: #e2e8f0; font-weight: bold;">
                <td colspan="2" class="text-right uppercase">TOTAL:</td>
                <td class="text-center font-mono">{{ number_format($summary['affected_ktr_count']) }} KTR</td>
                <td class="text-right font-mono text-rose">{{ number_format($summary['total_defect_pcs']) }}</td>
                <td class="text-right font-mono">100.0%</td>
                <td class="text-right font-mono">{{ number_format($summary['total_estimated_kg'], 2) }}</td>
                <td>—</td>
            </tr>
        </tfoot>
    </table>

    {{-- SECTION II: PARETO DEFECT SUMMARY --}}
    <div class="section-title">II. Ringkasan Pareto Defect (Urutan Jumlah Kerusakan Terbesar)</div>
    <table class="data-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 25px;">No</th>
                <th class="text-left">Jenis Cacat (Defect Type)</th>
                <th class="text-right" style="width: 65px;">Total (PCS)</th>
                <th class="text-right" style="width: 50px;">Kontribusi</th>
                <th class="text-right" style="width: 65px;">Berat (KG)</th>
                <th class="text-center" style="width: 55px;">PFKTR</th>
                <th class="text-right" style="width: 45px;">Netto</th>
                <th class="text-right" style="width: 45px;">Kimia</th>
                <th class="text-right" style="width: 45px;">Marking</th>
                <th class="text-right" style="width: 45px;">CNC</th>
                <th class="text-right" style="width: 45px;">QC</th>
                <th class="text-right" style="width: 45px;">Gudang</th>
            </tr>
        </thead>
        <tbody>
            @forelse($paretoSummary as $pIdx => $p)
                <tr>
                    <td class="text-center font-mono">{{ $pIdx + 1 }}</td>
                    <td class="font-bold">{{ $p['defect_name'] }}</td>
                    <td class="text-right font-mono font-bold text-rose">{{ number_format($p['qty_pcs']) }}</td>
                    <td class="text-right font-mono">{{ number_format($p['contribution_pct'], 1) }}%</td>
                    <td class="text-right font-mono">{{ number_format($p['estimated_kg'], 2) }}</td>
                    <td class="text-center font-mono">{{ number_format($p['ktr_count']) }}</td>
                    <td class="text-right font-mono">{{ ($p['stage_breakdown']['netto_pf'] ?? 0) > 0 ? number_format($p['stage_breakdown']['netto_pf']) : '—' }}</td>
                    <td class="text-right font-mono">{{ ($p['stage_breakdown']['kimia'] ?? 0) > 0 ? number_format($p['stage_breakdown']['kimia']) : '—' }}</td>
                    <td class="text-right font-mono">{{ ($p['stage_breakdown']['marking'] ?? 0) > 0 ? number_format($p['stage_breakdown']['marking']) : '—' }}</td>
                    <td class="text-right font-mono">{{ ($p['stage_breakdown']['bubut_cnc_pf'] ?? 0) > 0 ? number_format($p['stage_breakdown']['bubut_cnc_pf']) : '—' }}</td>
                    <td class="text-right font-mono">{{ ($p['stage_breakdown']['qc_pf'] ?? 0) > 0 ? number_format($p['stage_breakdown']['qc_pf']) : '—' }}</td>
                    <td class="text-right font-mono">{{ ($p['stage_breakdown']['gudang_jadi_pf'] ?? 0) > 0 ? number_format($p['stage_breakdown']['gudang_jadi_pf']) : '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" class="text-center" style="padding: 8px;">Tidak ada data defect QC pada periode ini.</td>
                </tr>
            @endforelse
        </tbody>
        @if(count($paretoSummary) > 0)
            <tfoot>
                <tr style="background: #e2e8f0; font-weight: bold;">
                    <td colspan="2" class="text-right uppercase">TOTAL:</td>
                    <td class="text-right font-mono text-rose">{{ number_format($summary['total_defect_pcs']) }}</td>
                    <td class="text-right font-mono">100.0%</td>
                    <td class="text-right font-mono">{{ number_format($summary['total_estimated_kg'], 2) }}</td>
                    <td class="text-center font-mono">{{ number_format($summary['affected_ktr_count']) }}</td>
                    <td colspan="6" class="text-right" style="font-size: 7px; color: #475569;">Rincian 6 tahap tertera di atas</td>
                </tr>
            </tfoot>
        @endif
    </table>

    {{-- SECTION III: DETAILED TRACEABILITY TABLE --}}
    <div class="section-title">III. Rincian Data Defect QC (Traceability Detail)</div>
    <table class="data-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 20px;">No</th>
                <th class="text-center" style="width: 50px;">Tgl Kejadian</th>
                <th class="text-center" style="width: 55px;">Verifikasi QC</th>
                <th class="text-left" style="width: 60px;">PFKTR</th>
                <th class="text-left" style="width: 50px;">Heat No</th>
                <th class="text-left" style="width: 60px;">Kode Produk</th>
                <th class="text-left">Nama Produk & Customer</th>
                <th class="text-center" style="width: 35px;">Line</th>
                <th class="text-center" style="width: 50px;">Tahap</th>
                <th class="text-left" style="width: 70px;">Jenis Cacat</th>
                <th class="text-right" style="width: 45px;">Qty (PCS)</th>
                <th class="text-right" style="width: 45px;">Berat (KG)</th>
                <th class="text-left" style="width: 60px;">QC Verifikator</th>
                <th class="text-left" style="width: 70px;">Catatan</th>
            </tr>
        </thead>
        <tbody>
            @forelse($all_records as $idx => $r)
                <tr>
                    <td class="text-center font-mono">{{ $idx + 1 }}</td>
                    <td class="text-center font-mono">{{ $r['date'] }}</td>
                    <td class="text-center font-mono">{{ $r['verified_at_formatted'] }}</td>
                    <td class="font-mono font-bold">{{ $r['traveler_number'] }}</td>
                    <td class="font-mono">{{ $r['heat_number'] }}</td>
                    <td class="font-mono">{{ $r['production_code'] }}</td>
                    <td>{{ $r['item_name'] }} <span style="color: #64748b;">({{ $r['customer'] }})</span></td>
                    <td class="text-center">Line {{ $r['line_number'] }}</td>
                    <td class="text-center font-bold">{{ $r['stage_label'] }}</td>
                    <td class="font-bold">{{ $r['defect_name'] }}</td>
                    <td class="text-right font-mono font-bold text-rose">{{ number_format($r['qty']) }}</td>
                    <td class="text-right font-mono">{{ $r['has_weight'] ? number_format($r['estimated_kg'], 2) : '—' }}</td>
                    <td>{{ $r['qc_verifier'] }}</td>
                    <td>{{ $r['notes'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="14" class="text-center" style="padding: 10px;">Tidak ada rincian data defect yang tercatat.</td>
                </tr>
            @endforelse
        </tbody>
        @if(count($all_records) > 0)
            <tfoot>
                <tr style="background: #e2e8f0; font-weight: bold;">
                    <td colspan="10" class="text-right uppercase">TOTAL:</td>
                    <td class="text-right font-mono text-rose">{{ number_format($summary['total_defect_pcs']) }}</td>
                    <td class="text-right font-mono">{{ number_format($summary['total_estimated_kg'], 2) }}</td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        @endif
    </table>

    {{-- SECTION IV: FORMAL SIGNATURE BLOCK --}}
    <table class="signature-table">
        <tr>
            <td>
                <div>Dibuat Oleh,</div>
                <div style="color: #475569; font-size: 7px;">Admin QC Fitting</div>
                <div class="signature-space"></div>
                <div class="signature-name">( ____________________ )</div>
                <div style="font-size: 7px; color: #64748b; margin-top: 2px;">Tgl: ................................</div>
            </td>
            <td>
                <div>Diperiksa Oleh,</div>
                <div style="color: #475569; font-size: 7px;">Supervisor QC Fitting</div>
                <div class="signature-space"></div>
                <div class="signature-name">( ____________________ )</div>
                <div style="font-size: 7px; color: #64748b; margin-top: 2px;">Tgl: ................................</div>
            </td>
            <td>
                <div>Disetujui Oleh,</div>
                <div style="color: #475569; font-size: 7px;">Manager QA / QC</div>
                <div class="signature-space"></div>
                <div class="signature-name">( ____________________ )</div>
                <div style="font-size: 7px; color: #64748b; margin-top: 2px;">Tgl: ................................</div>
            </td>
        </tr>
    </table>

</body>
</html>
