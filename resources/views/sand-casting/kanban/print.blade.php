<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Kanban {{ $stageLabel }} - {{ $printedAtWib }}</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 8mm 8mm 8mm 8mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9.5px;
            color: #0f172a;
            margin: 0;
            padding: 0;
            background: #ffffff;
            line-height: 1.3;
        }

        /* Non-print Interactive Toolbar */
        .no-print-toolbar {
            background: #0f172a;
            color: #ffffff;
            padding: 9px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .no-print-title {
            font-weight: bold;
            font-size: 13px;
            letter-spacing: 0.2px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .no-print-actions {
            display: flex;
            gap: 8px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: opacity 0.15s;
        }

        .btn-print {
            background: #2563eb;
            color: #ffffff;
        }

        .btn-close {
            background: #475569;
            color: #ffffff;
        }

        .btn:hover {
            opacity: 0.9;
        }

        /* Document Header */
        .doc-header {
            border-bottom: 2px solid #0f172a;
            padding-bottom: 6px;
            margin-bottom: 8px;
        }

        .header-top {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
        }

        .doc-title {
            margin: 0;
            font-size: 15px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #0f172a;
        }

        .doc-timestamp {
            font-size: 10px;
            font-family: monospace;
            font-weight: bold;
            color: #475569;
        }

        /* Filter & Summary Bar */
        .meta-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 4px 8px;
            margin-top: 5px;
            font-size: 9.5px;
        }

        .filter-badges {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .filter-item {
            color: #64748b;
        }

        .filter-item strong {
            color: #0f172a;
        }

        .kpi-totals {
            display: flex;
            align-items: center;
            gap: 12px;
            font-family: monospace;
            font-weight: bold;
            font-size: 10.5px;
        }

        .kpi-totals .total-item {
            color: #0f172a;
        }

        .kpi-totals .total-pcs {
            color: #0284c7;
        }

        .kpi-totals .total-kg {
            color: #059669;
        }

        /* 4-Line Column Table */
        .kanban-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-top: 6px;
        }

        .kanban-table thead {
            display: table-header-group;
        }

        .kanban-table tr {
            page-break-inside: avoid;
        }

        .kanban-table th {
            width: 25%;
            background: #0f172a;
            color: #ffffff;
            padding: 6px 8px;
            text-align: left;
            border: 1px solid #0f172a;
            font-size: 10px;
        }

        .line-header-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .line-header-title {
            font-weight: 900;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .line-header-meta {
            font-family: monospace;
            font-size: 9px;
            opacity: 0.9;
        }

        .kanban-table td {
            width: 25%;
            vertical-align: top;
            padding: 4px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
        }

        /* Item Card Inside Table Cell */
        .item-card {
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 4px 6px;
            background: #ffffff;
            margin-bottom: 2px;
            page-break-inside: avoid;
        }

        .item-card.is-urgent {
            border: 1.5px solid #dc2626;
            background: #fff5f5;
        }

        .item-row-1 {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 4px;
            margin-bottom: 2px;
        }

        .queue-badge {
            font-family: monospace;
            font-weight: 900;
            font-size: 9.5px;
            background: #f1f5f9;
            color: #0f172a;
            padding: 1px 4px;
            border-radius: 3px;
            border: 1px solid #cbd5e1;
            flex-shrink: 0;
        }

        .item-title {
            font-weight: bold;
            font-size: 9.5px;
            color: #0f172a;
            line-height: 1.2;
            flex-grow: 1;
            word-break: break-word;
        }

        .urgent-tag {
            background: #dc2626;
            color: #ffffff;
            font-size: 8px;
            font-weight: 900;
            padding: 1px 3px;
            border-radius: 2px;
            flex-shrink: 0;
            text-transform: uppercase;
        }

        .item-row-2 {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-family: monospace;
            font-size: 8.5px;
            color: #475569;
            margin-bottom: 3px;
            padding-bottom: 2px;
            border-bottom: 1px dashed #e2e8f0;
        }

        .ktr-badge {
            font-weight: bold;
            color: #1e293b;
        }

        .heat-badge {
            color: #64748b;
        }

        .spk-badge {
            color: #475569;
        }

        .item-row-3 {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            font-family: monospace;
            font-size: 9px;
            margin-bottom: 2px;
        }

        .qty-pcs {
            font-weight: 900;
            color: #0f172a;
        }

        .qty-kg {
            font-weight: bold;
            color: #047857;
        }

        .item-row-4 {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 8.5px;
            color: #64748b;
            gap: 4px;
        }

        .customer-text {
            font-weight: 600;
            color: #334155;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 110px;
        }

        .customer-badge {
            font-family: monospace;
            font-weight: 900;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 0 3px;
            border-radius: 2px;
            font-size: 8px;
        }

        .aging-badge {
            font-family: monospace;
            font-weight: bold;
            font-size: 8px;
            padding: 0 4px;
            border-radius: 2px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
        }

        .aging-badge.green {
            background: #f0fdf4;
            color: #166534;
            border-color: #bbf7d0;
        }

        .aging-badge.yellow {
            background: #fffbeb;
            color: #92400e;
            border-color: #fde68a;
        }

        .aging-badge.red {
            background: #fef2f2;
            color: #991b1b;
            border-color: #fecaca;
        }

        /* Empty line message */
        .line-empty {
            padding: 16px 8px;
            text-align: center;
            color: #94a3b8;
            font-style: italic;
            font-size: 9px;
            background: #f8fafc;
            border: 1px dashed #e2e8f0;
            border-radius: 4px;
        }

        /* Print Media Styles */
        @media print {
            .no-print-toolbar {
                display: none !important;
            }

            body {
                margin: 0;
                padding: 0;
                background: #ffffff;
            }

            .kanban-table {
                width: 100%;
            }

            .kanban-table th {
                background: #0f172a !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .item-card {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .item-card.is-urgent {
                border-color: #dc2626 !important;
                background: #fff5f5 !important;
            }

            .urgent-tag {
                background: #dc2626 !important;
                color: #ffffff !important;
            }

            .aging-badge.green {
                background: #f0fdf4 !important;
                color: #166534 !important;
                border-color: #bbf7d0 !important;
            }

            .aging-badge.yellow {
                background: #fffbeb !important;
                color: #92400e !important;
                border-color: #fde68a !important;
            }

            .aging-badge.red {
                background: #fef2f2 !important;
                color: #991b1b !important;
                border-color: #fecaca !important;
            }
        }
    </style>
</head>
<body>

    {{-- Interactive Non-print Toolbar --}}
    <div class="no-print-toolbar">
        <div class="no-print-title">
            <span>Preview Cetak PDF Kanban &mdash; Stage {{ $stageLabel }}</span>
            <span style="font-size: 11px; opacity: 0.8; font-weight: normal;">(A4 Landscape &middot; 4-Line Production Columns)</span>
        </div>
        <div class="no-print-actions">
            <button onclick="window.print()" class="btn btn-print">
                Cetak / Simpan PDF
            </button>
            <button onclick="window.close()" class="btn btn-close">
                Tutup
            </button>
        </div>
    </div>

    {{-- Document Header --}}
    <div class="doc-header">
        <div class="header-top">
            <h1 class="doc-title">KANBAN PRODUKSI SAND CASTING &mdash; TAHAP {{ $stageLabel }}</h1>
            <div class="doc-timestamp">{{ $printedAtWib }}</div>
        </div>

        <div class="meta-bar">
            <div class="filter-badges">
                <span class="filter-item">Filter: 
                    @if(!empty($filters['search']))
                        Pencarian "<strong>{{ $filters['search'] }}</strong>"
                    @else
                        <strong>Semua (Tanpa Pencarian)</strong>
                    @endif
                </span>
                <span class="filter-item">&middot;</span>
                <span class="filter-item">Line: 
                    @if(!empty($filters['line_number']))
                        <strong>Line {{ $filters['line_number'] }}</strong>
                    @else
                        <strong>Semua Line (1-4)</strong>
                    @endif
                </span>
                @if(!empty($filters['customer']))
                    <span class="filter-item">&middot;</span>
                    <span class="filter-item">Customer: <strong>{{ $filters['customer'] }}</strong></span>
                @endif
                @if(!empty($filters['is_urgent']))
                    <span class="filter-item">&middot;</span>
                    <span class="filter-item"><strong style="color: #dc2626;">HANYA URGENT</strong></span>
                @endif
            </div>

            <div class="kpi-totals">
                <span class="total-item">{{ number_format($totalCards) }} Kartu Aktif</span>
                <span>&middot;</span>
                <span class="total-pcs">{{ number_format($totalPcs, 0, ',', '.') }} PCS</span>
                <span>&middot;</span>
                <span class="total-kg">{{ number_format($totalWeightKg, 0, ',', '.') }} KG</span>
            </div>
        </div>
    </div>

    @php
        $maxRows = max(
            count($readyByLine[1]),
            count($readyByLine[2]),
            count($readyByLine[3]),
            count($readyByLine[4]),
            1
        );

        $getAgingBadge = function($agingData) {
            $hours = is_array($agingData) ? ($agingData['stage_aging_hours'] ?? 0) : 0;
            if ($hours >= 24) {
                $days = intdiv($hours, 24);
                $remHours = $hours % 24;
                $label = sprintf('Aging %dD %02dH', $days, $remHours);
            } else {
                $label = sprintf('Aging %02dH', $hours);
            }

            if ($hours < 120) {
                return ['label' => $label, 'class' => 'green'];
            } elseif ($hours <= 168) {
                return ['label' => $label, 'class' => 'yellow'];
            } else {
                return ['label' => $label, 'class' => 'red'];
            }
        };
    @endphp

    {{-- Main 4-Column Table --}}
    <table class="kanban-table">
        <thead>
            <tr>
                @for($l = 1; $l <= 4; $l++)
                    @php
                        $lineCount = count($readyByLine[$l]);
                        $linePcs = array_sum(array_column($readyByLine[$l], 'qty'));
                        $lineKg = array_sum(array_column($readyByLine[$l], 'total_weight_kg'));
                    @endphp
                    <th>
                        <div class="line-header-inner">
                            <span class="line-header-title">LINE {{ $l }}</span>
                            <span class="line-header-meta">
                                {{ $lineCount }} KTR &middot; {{ number_format($linePcs, 0, ',', '.') }} PCS &middot; {{ number_format($lineKg, 0, ',', '.') }} KG
                            </span>
                        </div>
                    </th>
                @endfor
            </tr>
        </thead>
        <tbody>
            @if($totalCards === 0)
                <tr>
                    <td colspan="4" style="text-align: center; padding: 24px; color: #64748b; font-style: italic;">
                        Tidak ada antrean kartu Kanban aktif yang sesuai dengan kriteria filter saat ini.
                    </td>
                </tr>
            @else
                @for($r = 0; $r < $maxRows; $r++)
                    <tr>
                        @for($l = 1; $l <= 4; $l++)
                            <td>
                                @if(isset($readyByLine[$l][$r]))
                                    @php
                                        $item = $readyByLine[$l][$r];
                                        $agingInfo = $getAgingBadge($item['aging'] ?? null);
                                        $itemQty = (int) ($item['qty'] ?? 0);
                                        $itemWeight = (float) ($item['total_weight_kg'] ?? 0.0);
                                        $showPendingQcWarning = !empty($item['has_pending_qc']) && $itemQty === 0 && abs($itemWeight) < 0.0001;
                                    @endphp
                                    <div class="item-card {{ !empty($item['is_urgent']) ? 'is-urgent' : '' }}">
                                        {{-- Row 1: Antrian #, Nama Item, Urgent tag --}}
                                        <div class="item-row-1">
                                            @if($showQueueNumbers ?? true)
                                            <span class="queue-badge">{{ $item['queue_number'] ?? '#--' }}</span>
                                            @endif
                                            <span class="item-title">{{ $item['item_name'] ?? '-' }}</span>
                                            @if(!empty($item['is_urgent']))
                                                <span class="urgent-tag">URGENT</span>
                                            @endif
                                        </div>

                                        @if($showPendingQcWarning)
                                        <div style="margin: 2px 0;">
                                            <span style="display: inline-block; font-size: 7.5px; font-weight: 900; background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; padding: 1px 3px; border-radius: 2px; text-transform: uppercase;">
                                                MENUNGGU VERIFIKASI QC
                                            </span>
                                        </div>
                                        @endif

                                        {{-- Row 2: KTR, Heat, SPK --}}
                                        <div class="item-row-2">
                                            <span class="ktr-badge">{{ $item['traveler_number'] ?? '-' }}</span>
                                            <span class="heat-badge">Heat: {{ $item['heat_number'] ?? '-' }}</span>
                                            @if(!empty($item['production_code']))
                                                <span class="spk-badge">SPK: {{ $item['production_code'] }}</span>
                                            @endif
                                        </div>

                                        {{-- Row 3: Qty PCS & Berat KG --}}
                                        <div class="item-row-3">
                                            <span class="qty-pcs">{{ number_format($item['qty'] ?? 0, 0, ',', '.') }} PCS</span>
                                            <span class="qty-kg">{{ number_format($item['total_weight_kg'] ?? 0, 0, ',', '.') }} KG</span>
                                        </div>

                                        {{-- Row 4: Customer badge & Aging --}}
                                        <div class="item-row-4">
                                            <div>
                                                @if(!empty($item['customer_badge']))
                                                    <span class="customer-badge">[{{ $item['customer_badge']['label'] }}]</span>
                                                @endif
                                                <span class="customer-text" title="{{ $item['customer'] ?? '-' }}">{{ $item['customer'] ?? '-' }}</span>
                                            </div>
                                            <span class="aging-badge {{ $agingInfo['class'] }}">
                                                {{ $agingInfo['label'] }}
                                            </span>
                                        </div>
                                    </div>
                                @elseif($r === 0 && count($readyByLine[$l]) === 0)
                                    <div class="line-empty">
                                        Tidak ada antrean Line {{ $l }}
                                    </div>
                                @endif
                            </td>
                        @endfor
                    </tr>
                @endfor
            @endif
        </tbody>
    </table>

</body>
</html>
