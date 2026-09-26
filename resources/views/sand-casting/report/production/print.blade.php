<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Produksi Sand Casting ({{ $activeFilters['date_from'] }} s/d {{ $activeFilters['date_to'] }})</title>
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
            background: #1e293b;
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
            background: #2563eb;
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

        /* KPI Summary Boxes */
        .kpi-container {
            margin-bottom: 12px;
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
            color: #475569;
        }

        .kpi-table td {
            border: 1px solid #cbd5e1;
            padding: 7px 8px;
            font-size: 13px;
            font-weight: bold;
            font-family: monospace;
        }

        .kpi-blue {
            background-color: #eff6ff;
            color: #1e40af;
        }

        .kpi-green {
            background-color: #f0fdf4;
            color: #047857;
        }

        .kpi-red {
            background-color: #fef2f2;
            color: #b91c1c;
        }

        .kpi-slate {
            background-color: #f8fafc;
            color: #334155;
        }

        /* Section Titles */
        .section-title {
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            color: #0f172a;
            margin-top: 14px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .section-subtitle {
            font-size: 9px;
            color: #64748b;
            font-weight: normal;
        }

        /* Summary Table & Detail Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 12px;
        }

        .data-table th {
            background-color: #e2e8f0;
            border: 1px solid #94a3b8;
            padding: 5px 6px;
            font-size: 9px;
            text-transform: uppercase;
            font-weight: bold;
            color: #0f172a;
        }

        .data-table td {
            border: 1px solid #cbd5e1;
            padding: 4px 5px;
            font-size: 9.5px;
            color: #1e293b;
            word-wrap: break-word;
            overflow: hidden;
        }

        /* Detail Table 10-Kolom Compact & Readable (8.5pt - 9pt) */
        .detail-table th {
            background-color: #f1f5f9;
            border: 1px solid #94a3b8;
            padding: 5px 4px;
            font-size: 8.5px;
            color: #0f172a;
        }

        .detail-table td {
            border: 1px solid #cbd5e1;
            padding: 3.5px 4px;
            font-size: 8.5px;
            line-height: 1.25;
        }

        .data-table tr:nth-child(even) {
            background-color: #f8fafc;
        }

        .data-table tfoot tr {
            background-color: #f1f5f9;
            font-weight: bold;
            border-top: 2px solid #0f172a;
        }

        .data-table tfoot td {
            font-weight: bold;
            border: 1px solid #94a3b8;
        }

        .text-left { text-align: left !important; }
        .text-center { text-align: center !important; }
        .text-right { text-align: right !important; }
        .font-mono { font-family: monospace; }
        .font-bold { font-weight: bold; }

        .badge-stage {
            display: inline-block;
            padding: 1.5px 5px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 8px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            color: #0f172a;
            white-space: nowrap;
        }

        /* Signature Table */
        .sign-table {
            width: 100%;
            margin-top: 14px;
            border-collapse: collapse;
            page-break-inside: avoid;
        }

        .sign-table td {
            width: 33.33%;
            text-align: center;
            vertical-align: top;
            padding: 0 10px;
            font-size: 9.5px;
        }

        .sign-box {
            height: 40px;
        }

        .footer-note {
            margin-top: 10px;
            font-size: 8.5px;
            color: #64748b;
            text-align: right;
            page-break-inside: avoid;
        }

        /* Print Media Styles */
        @media print {
            .no-print {
                display: none !important;
            }

            body {
                margin: 0;
                padding: 0;
                background: #ffffff;
            }

            table {
                width: 100%;
                border-collapse: collapse;
            }

            thead {
                display: table-header-group;
            }

            tr {
                page-break-inside: avoid;
            }

            .sign-table {
                page-break-inside: avoid;
            }

            .footer-note {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>

    {{-- Interactive Top Bar for Previewing before Printing (Excluded from Paper) --}}
    <div class="no-print">
        <span class="no-print-title">Pratinjau Dokumen Report Produksi Sand Casting</span>
        <div>
            <button type="button" onclick="window.print()" class="btn btn-print">Cetak Dokumen (Print / Save as PDF)</button>
            <button type="button" onclick="window.close()" class="btn btn-close">Tutup Tab</button>
        </div>
    </div>

    {{-- Document Header --}}
    <div class="header">
        <h1>REPORT PRODUKSI SAND CASTING</h1>
        <div class="meta">
            @php
                $stageTitle = strtoupper($stages[$activeFilters['stage']] ?? $activeFilters['stage']);
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

    {{-- Summary KPI Cards (Consistent with Web & Excel) --}}
    <div class="kpi-container">
        <table class="kpi-table">
            <thead>
                <tr>
                    <th>Total Aktivitas KTR</th>
                    <th>Total Output Baik</th>
                    <th>Total Berat Output</th>
                    <th>Total Defect (Rusak)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="kpi-blue">{{ number_format($summary['total_ktr_activities']) }} KTR</td>
                    <td class="kpi-green">{{ number_format($summary['total_qty_output']) }} pcs</td>
                    <td class="kpi-slate">{{ number_format($summary['total_weight'], 2) }} kg</td>
                    <td class="kpi-red">{{ number_format($summary['total_defect']) }} pcs</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Stage Summary Table --}}
    <div class="section-title">
        <span>Ringkasan Output per Tahapan</span>
        <span class="section-subtitle">Tahapan: <strong>{{ $stageTitle }}</strong></span>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th class="text-left" style="width: 22%;">Tahapan</th>
                <th class="text-center" style="width: 10%;">KTR</th>
                <th class="text-right" style="width: 14%;">Input PCS</th>
                <th class="text-right" style="width: 13%;">Rusak</th>
                <th class="text-right" style="width: 14%;">Good</th>
                <th class="text-right" style="width: 14%;">Berat KG</th>
                <th class="text-right" style="width: 13%;">% Defect</th>
            </tr>
        </thead>
        <tbody>
            @foreach($stageSummaries as $stg)
                <tr>
                    <td class="text-left font-bold">
                        <span class="badge-stage">{{ $stg['stage_label'] }}</span>
                    </td>
                    <td class="text-center font-mono font-bold">{{ number_format($stg['ktr_count']) }}</td>
                    <td class="text-right font-mono">{{ number_format($stg['input_pcs']) }}</td>
                    <td class="text-right font-mono" style="color: {{ $stg['defect_pcs'] > 0 ? '#b91c1c' : '#475569' }}; font-weight: {{ $stg['defect_pcs'] > 0 ? 'bold' : 'normal' }};">
                        {{ number_format($stg['defect_pcs']) }}
                    </td>
                    <td class="text-right font-mono font-bold" style="color: #047857;">{{ number_format($stg['good_pcs']) }}</td>
                    <td class="text-right font-mono">{{ number_format($stg['weight_kg'], 2) }}</td>
                    <td class="text-right font-mono" style="color: {{ $stg['defect_rate'] > 5 ? '#b91c1c' : '#334155' }}; font-weight: {{ $stg['defect_rate'] > 0 ? 'bold' : 'normal' }};">
                        {{ number_format($stg['defect_rate'], 2) }}%
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td class="text-left">TOTAL / RINGKASAN</td>
                <td class="text-center font-mono">{{ number_format($summary['total_ktr_activities']) }}</td>
                <td class="text-right font-mono">{{ number_format($stageSummaries->sum('input_pcs')) }}</td>
                <td class="text-right font-mono" style="color: {{ $summary['total_defect'] > 0 ? '#b91c1c' : '#334155' }};">
                    {{ number_format($summary['total_defect']) }}
                </td>
                <td class="text-right font-mono" style="color: #047857;">{{ number_format($summary['total_qty_output']) }}</td>
                <td class="text-right font-mono">{{ number_format($summary['total_weight'], 2) }}</td>
                <td class="text-right font-mono">
                    @php
                        $totInput = $stageSummaries->sum('input_pcs');
                        $totDefect = $summary['total_defect'];
                        $avgDefect = $totInput > 0 ? round(($totDefect / $totInput) * 100, 2) : 0;
                    @endphp
                    {{ number_format($avgDefect, 2) }}%
                </td>
            </tr>
        </tfoot>
    </table>

    {{-- Detail Activity Table (PAGE 2+ / 10 Final Readable Columns) --}}
    @if(isset($items) && $items->isNotEmpty())
        <div class="section-title" style="margin-top: 16px;">
            <span>Rincian Aktivitas Produksi</span>
            <span class="section-subtitle">Total: <strong>{{ number_format($items->count()) }}</strong> baris aktivitas</span>
        </div>

        <table class="data-table detail-table">
            <thead>
                <tr>
                    <th class="text-center" style="width: 4%;">No</th>
                    <th class="text-center" style="width: 11%;">Kode Produksi</th>
                    <th class="text-left" style="width: 12%;">Customer</th>
                    <th class="text-center" style="width: 10%;">Heat</th>
                    <th class="text-left" style="width: 21%;">Nama Item</th>
                    <th class="text-right" style="width: 7%;">Berat Item</th>
                    <th class="text-right" style="width: 7%;">Input</th>
                    <th class="text-right" style="width: 6%;">Rusak</th>
                    <th class="text-right" style="width: 12%;">Total Berat Input</th>
                    <th class="text-center" style="width: 10%;">Waktu</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $idx => $item)
                    @php
                        // Calculate total input weight = input_qty * unit_weight_kg
                        $unitWeight = (float) ($item['weight'] ?? 0);
                        $inputQty = (int) ($item['input_qty'] ?? 0);
                        $totalInputWeight = round($inputQty * $unitWeight, 2);

                        // Format timestamp to compact dd/mm HH:mm
                        $rawTime = (string) ($item['physical_done_at'] ?? '-');
                        $displayTime = $rawTime;
                        if (strlen($rawTime) >= 16) {
                            try {
                                $displayTime = \Carbon\Carbon::parse($rawTime)->format('d/m H:i');
                            } catch (\Throwable $e) {
                                $displayTime = $rawTime;
                            }
                        }
                    @endphp
                    <tr>
                        <td class="text-center font-mono text-slate-500">{{ $idx + 1 }}</td>
                        <td class="text-center font-mono font-bold">{{ $item['production_code'] }}</td>
                        <td class="text-left" title="{{ $item['customer'] }}">{{ \Illuminate\Support\Str::limit($item['customer'], 16) }}</td>
                        <td class="text-center font-mono">{{ $item['heat_number'] }}</td>
                        <td class="text-left font-semibold text-slate-900" title="{{ $item['item_name'] }}">{{ $item['item_name'] }}</td>
                        <td class="text-right font-mono">{{ number_format($unitWeight, 2) }} kg</td>
                        <td class="text-right font-mono">{{ number_format($inputQty) }}</td>
                        <td class="text-right font-mono" style="color: {{ $item['defect_qty'] > 0 ? '#b91c1c' : '#64748b' }}; font-weight: {{ $item['defect_qty'] > 0 ? 'bold' : 'normal' }};">
                            {{ $item['defect_qty'] > 0 ? number_format($item['defect_qty']) : '0' }}
                        </td>
                        <td class="text-right font-mono font-bold">{{ number_format($totalInputWeight, 2) }} kg</td>
                        <td class="text-center font-mono" style="font-size: 8px;">{{ $displayTime }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Signatures --}}
    <table class="sign-table">
        <tr>
            <td>
                Dibuat Oleh,<br>
                <strong>Admin PPIC</strong>
                <div class="sign-box"></div>
                ( ............................................ )
            </td>
            <td>
                Diperiksa Oleh,<br>
                <strong>SPV Produksi / QC</strong>
                <div class="sign-box"></div>
                ( ............................................ )
            </td>
            <td>
                Disetujui Oleh,<br>
                <strong>Manager Produksi</strong>
                <div class="sign-box"></div>
                ( ............................................ )
            </td>
        </tr>
    </table>

    <div class="footer-note">
        Dokumen ini dicetak otomatis dari Sistem Tracking Kanban PPIC Sand Casting &mdash; {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }}
    </div>

</body>
</html>
