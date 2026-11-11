<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barang Siap Oven - {{ $summary['generated_at_formatted'] }}</title>
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
            font-size: 16px;
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
        }

        /* Summary KPI Cards for Print */
        .summary-box {
            display: flex;
            gap: 6px;
            margin-bottom: 8px;
        }

        .kpi-cell {
            flex: 1;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            padding: 4px 6px;
            border-radius: 4px;
            text-align: center;
        }

        .kpi-label {
            font-size: 7.5px;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
        }

        .kpi-value {
            font-size: 12px;
            font-weight: 900;
            color: #0f172a;
            margin-top: 1px;
        }

        .kpi-sub {
            font-size: 7.5px;
            color: #64748b;
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
            padding: 4.5px 5px;
            font-size: 8.5px;
            text-transform: uppercase;
            font-weight: bold;
            letter-spacing: 0.3px;
        }

        .data-table td {
            border: 1px solid #cbd5e1;
            padding: 4px 5px;
            font-size: 9px;
            vertical-align: top;
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
            padding: 5px;
            font-size: 9.5px;
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
            font-size: 7.5px;
            color: #475569;
            font-family: ui-monospace, monospace;
            margin-top: 1px;
            line-height: 1.2;
            word-break: break-word;
        }

        .badge-stage {
            display: inline-block;
            font-size: 7.5px;
            font-weight: bold;
            padding: 1px 3px;
            border-radius: 2px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            margin-top: 1px;
        }

        .badge-batch-full {
            display: inline-block;
            font-size: 7.5px;
            font-weight: bold;
            padding: 1px 4px;
            border-radius: 2px;
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .badge-batch-partial {
            display: inline-block;
            font-size: 7.5px;
            font-weight: bold;
            padding: 1px 4px;
            border-radius: 2px;
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        /* Signature block */
        .sign-section {
            margin-top: 14px;
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
            padding: 0 8px;
        }

        .sign-role {
            font-size: 8.5px;
            font-weight: bold;
            color: #334155;
            text-transform: uppercase;
            margin-bottom: 40px;
        }

        .sign-name {
            font-size: 9px;
            font-weight: bold;
            border-top: 1px solid #64748b;
            padding-top: 2px;
            display: inline-block;
            min-width: 110px;
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
            <strong style="font-size: 13px;">Preview Dokumen Cetak A4 Portrait &mdash; LAPORAN BARANG SIAP OVEN{{ $summary['is_filtered'] ? ' — ' . $summary['active_filter_text'] : '' }}</strong>
            <span style="font-size: 10.5px; color: #94a3b8; margin-left: 8px;">
                ({{ number_format($summary['total_unique_trees']) }} Pohon, {{ number_format($summary['total_usable_pcs'], 0, ',', '.') }} / {{ number_format($summary['total_po_pcs'], 0, ',', '.') }} Pcs Layak/PO, {{ $summary['total_weight_kg_formatted'] }} kg)
            </span>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-print">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                Cetak Dokumen
            </button>
            <button onclick="window.close()" class="btn btn-close" style="margin-left: 6px;">
                Tutup
            </button>
        </div>
    </div>

    <!-- Header Dokumen -->
    <div class="doc-header">
        <div class="company">PT. PERONI KARYA SENTRA &bull; KANBAN PPIC LOST WAX</div>
        <h1>LAPORAN BARANG SIAP OVEN{{ $summary['is_filtered'] ? ' — ' . $summary['active_filter_text'] : '' }}</h1>
        <div class="meta-bar">
            <span><strong>Waktu Cetak:</strong> {{ $summary['generated_at_formatted'] }} WIB</span>
            <span><strong>Prioritas:</strong> FIFO (Waktu Selesai Lapisan Terawal)</span>
            @if($summary['is_filtered'])
                <span><strong>Filter:</strong> {{ $summary['active_filter_text'] }} ({{ $summary['total_groups'] }} Kode)</span>
            @endif
            <span><strong>Dicetak Oleh:</strong> {{ auth()->user()->name ?? 'Operator' }}</span>
        </div>
    </div>

    <!-- Summary Box -->
    <div class="summary-box">
        <div class="kpi-cell">
            <div class="kpi-label">Pohon Fisik Unik {{ $summary['is_filtered'] ? '(Filter)' : '' }}</div>
            <div class="kpi-value">{{ number_format($summary['total_unique_trees']) }} <span style="font-size: 8.5px; font-weight: normal;">Pohon</span></div>
            <div class="kpi-sub">{{ number_format($summary['total_rangkai']) }} Rangkai</div>
        </div>
        <div class="kpi-cell">
            <div class="kpi-label">Siap Oven / PO {{ $summary['is_filtered'] ? '(Filter)' : '' }}</div>
            <div class="kpi-value" style="color: #047857;">
                {{ number_format($summary['total_usable_pcs'], 0, ',', '.') }} <span style="font-size: 8.5px; font-weight: normal; color: #475569;">/ {{ number_format($summary['total_po_pcs'], 0, ',', '.') }}</span>
            </div>
            <div class="kpi-sub">
                Bruto: {{ number_format($summary['total_gross_pcs'], 0, ',', '.') }} | Afkir: {{ number_format($summary['total_defect_pcs'], 0, ',', '.') }}
                @if($summary['overall_po_variance_text'])
                    &bull; <strong>{{ $summary['overall_po_variance_text'] }}</strong>
                @endif
            </div>
        </div>
        <div class="kpi-cell">
            <div class="kpi-label">Total Berat Siap Oven {{ $summary['is_filtered'] ? '(Filter)' : '' }}</div>
            <div class="kpi-value">{{ $summary['total_weight_kg_formatted'] }} <span style="font-size: 8.5px; font-weight: normal;">Kg</span></div>
            <div class="kpi-sub">
                @if($summary['has_incomplete_weights'])
                    <span style="color: #b45309;">*{{ $summary['groups_without_weight_count'] }} item belum ada berat</span>
                @else
                    <span>Dari {{ number_format($summary['groups_with_weight_count']) }} kelompok item valid</span>
                @endif
            </div>
        </div>
        <div class="kpi-cell">
            <div class="kpi-label">Estimasi Coverage {{ $summary['is_filtered'] ? '(Filter)' : '' }}</div>
            <div class="kpi-value" style="color: #4338ca;">{{ $summary['coverage_days_formatted'] }} <span style="font-size: 8.5px; font-weight: normal;">Hari</span></div>
            <div class="kpi-sub">Target: {{ $summary['daily_target_kg_formatted'] }} kg/hari</div>
        </div>
        <div class="kpi-cell">
            <div class="kpi-label">Kode Produksi {{ $summary['is_filtered'] ? '(Filter)' : '' }}</div>
            <div class="kpi-value">{{ number_format($summary['total_groups']) }} <span style="font-size: 8.5px; font-weight: normal;">Kode</span></div>
            <div class="kpi-sub">
                @if($summary['is_filtered'])
                    Dari {{ number_format($summary['queue_total_groups']) }} total kode
                @else
                    {{ number_format($summary['total_spk_count']) }} SPK Rangkai
                @endif
            </div>
        </div>
    </div>

    <!-- Table Data -->
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 24px;" class="text-center">No</th>
                <th style="width: 110px;">Kode Produksi</th>
                <th>Nama Item</th>
                <th style="width: 55px;" class="text-center">Customer</th>
                <th style="width: 55px;" class="text-center">Jumlah Rangkai</th>
                <th style="width: 105px;" class="text-right">SIAP OVEN / PO</th>
                <th style="width: 90px;" class="text-center">Waktu FIFO</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $index => $item)
                <!-- Master Row -->
                <tr>
                    <!-- No -->
                    <td class="text-center font-bold" style="color: #64748b;">
                        {{ $index + 1 }}
                    </td>

                    <!-- Kode Produksi -->
                    <td class="font-bold">
                        <div style="font-size: 10px;">{{ $item['production_code'] }}</div>
                        <div style="font-size: 7.5px; color: #4338ca; font-family: ui-monospace, monospace;">
                            {{ count($item['work_orders']) }} SPK Rangkai
                        </div>
                    </td>

                    <!-- Nama Item -->
                    <td>
                        <div style="font-weight: 500;">{{ $item['item_name'] }}</div>
                    </td>

                    <!-- Customer -->
                    <td class="text-center font-bold">
                        {{ $item['customer'] }}
                    </td>

                    <!-- Jumlah Rangkai -->
                    <td class="text-center font-bold">
                        <div>{{ $item['tree_count'] }}</div>
                        <div style="font-size: 7px; font-weight: normal; color: #64748b;">pohon</div>
                    </td>

                    <!-- SIAP OVEN / PO (One Aggregate per Code) -->
                    <td class="text-right font-bold">
                        <div style="font-size: 10px; color: #0f172a;">
                            {{ number_format($item['quantity_pcs'], 0, ',', '.') }} / {{ $item['po_quantity'] !== null ? number_format($item['po_quantity'], 0, ',', '.') : '-' }} PCS
                        </div>
                        @if($item['po_quantity'] !== null)
                            @if($item['po_variance_status'] === 'LEBIH')
                                <div style="font-size: 7px; font-weight: bold; color: #166534; background: #dcfce7; border: 1px solid #86efac; border-radius: 2px; padding: 0.5px 3px; display: inline-block; margin-top: 1px;">
                                    +{{ $item['po_variance_text'] }}
                                </div>
                            @elseif($item['po_variance_status'] === 'KURANG')
                                <div style="font-size: 7px; font-weight: bold; color: #9f1239; background: #ffe4e6; border: 1px solid #fecdd3; border-radius: 2px; padding: 0.5px 3px; display: inline-block; margin-top: 1px;">
                                    -{{ $item['po_variance_text'] }}
                                </div>
                            @else
                                <div style="font-size: 7px; font-weight: bold; color: #166534; background: #dcfce7; border: 1px solid #86efac; border-radius: 2px; padding: 0.5px 3px; display: inline-block; margin-top: 1px;">
                                    Sesuai PO
                                </div>
                            @endif
                        @else
                            <div style="font-size: 7px; color: #b45309; margin-top: 1px;">
                                PO belum ditentukan
                            </div>
                        @endif
                    </td>

                    <!-- Waktu Selesai Lapisan (FIFO) -->
                    <td class="text-center font-mono" style="font-size: 8.5px;">
                        <div>{{ $item['finished_at_formatted'] }}</div>
                        <div class="badge-stage">{{ $item['finished_stage_label'] }}</div>
                        @if($item['has_mixed_times'])
                            <div style="font-size: 7px; color: #64748b;">(s/d {{ $item['latest_finished_at']->format('H:i') }})</div>
                        @endif
                    </td>
                </tr>

                <!-- Sub-Row: Detail SPK Rangkai -->
                <tr style="background-color: #f8fafc;">
                    <td></td>
                    <td colspan="6" style="padding: 3px 6px; font-size: 8px; border-bottom: 1.5px solid #cbd5e1;">
                        <span style="font-weight: bold; color: #475569;">Rincian SPK ({{ count($item['work_orders']) }} SPK):</span>
                        <div style="margin-top: 1.5px;">
                            @foreach($item['work_orders'] as $wo)
                                <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed #e2e8f0; padding: 1.5px 0;">
                                    <span>
                                        <strong style="color: #1e293b; font-family: monospace;">{{ $wo['work_order_number'] }}</strong>: 
                                        {{ $wo['tree_count'] }} pohon (<strong>{{ number_format($wo['quantity_pcs']) }} PCS</strong>)
                                        @if($wo['racks_compact'] !== '-')
                                            <span style="background: #e2e8f0; padding: 0 3px; border-radius: 2px; font-size: 7.5px;">[{{ $wo['racks_compact'] }}]</span>
                                        @endif
                                        <span style="color: #64748b; font-family: monospace; font-size: 7.5px;">({{ implode(', ', $wo['barcodes']) }})</span>
                                    </span>
                                    <span style="color: #64748b; font-family: monospace;">{{ $wo['finished_at_formatted'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center" style="padding: 24px; color: #94a3b8;">
                        @if($summary['is_filtered'])
                            Tidak ada kode produksi yang memenuhi kriteria filter <strong>{{ $summary['active_filter_text'] }}</strong>.
                        @else
                            Tidak ada antrean barang yang siap masuk Oven saat ini.
                        @endif
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if($items->isNotEmpty())
            <tfoot>
                <tr>
                    <td colspan="4" class="text-right" style="letter-spacing: 0.5px;">
                        TOTAL {{ $summary['is_filtered'] ? 'HASIL FILTER (' . $summary['active_filter_text'] . ')' : 'KESELURUHAN' }}:
                    </td>
                    <td class="text-center">
                        <div style="font-size: 10px;">{{ number_format($summary['total_rangkai']) }}</div>
                        <div style="font-size: 7px; font-weight: normal; color: #64748b;">({{ number_format($summary['total_unique_trees']) }} Pohon Fisik)</div>
                    </td>
                    <td class="text-right" style="font-size: 10px;">
                        <div style="font-weight: bold; color: #047857;">
                            {{ number_format($summary['total_usable_pcs'], 0, ',', '.') }} / {{ number_format($summary['total_po_pcs'], 0, ',', '.') }} PCS
                        </div>
                        @if($summary['overall_po_variance_text'])
                            <div style="font-size: 7.5px; font-weight: bold; color: {{ $summary['overall_po_variance_status'] === 'KURANG' ? '#9f1239' : '#166534' }};">
                                {{ $summary['overall_po_variance_text'] }}
                            </div>
                        @endif
                    </td>
                    <td class="text-center" style="font-size: 7.5px; font-weight: normal; color: #64748b;">
                        {{ $summary['total_groups'] }} Kode ({{ $summary['total_spk_count'] }} SPK)<br>
                        Berat: {{ $summary['total_weight_kg_formatted'] }} kg<br>
                        Coverage: {{ $summary['coverage_days_formatted'] }} Hari
                    </td>
                </tr>
            </tfoot>
        @endif
    </table>

    <div style="margin-top: 4px; font-size: 7.5px; color: #64748b; font-style: italic;">
        *Catatan: Pohon fisik unik dihitung dari nomor barcode fisik unik. Antrean diurutkan berdasarkan FIFO per Kode Produksi. Kolom SIAP OVEN / PO membandingkan akumulasi PCS layak antrean dengan PO resmi. Rincian SPK disajikan di bawah masing-masing kode produksi.
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
