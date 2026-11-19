<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Kanban Fitting {{ $stageLabel }} - {{ $printedAtWib }}</title>
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

        .company-name {
            font-size: 11px;
            font-weight: bold;
            color: #475569;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
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
            font-size: 10px;
        }

        .kpi-item {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .kpi-val {
            font-weight: 900;
            color: #0f172a;
        }

        .kpi-val.pcs {
            color: #0f172a;
        }

        .kpi-val.kg {
            color: #047857;
        }

        /* 4-Column Layout */
        .kanban-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
            align-items: start;
            margin-top: 8px;
        }

        .kanban-column {
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            background: #f8fafc;
            overflow: hidden;
        }

        .column-header {
            padding: 4px 6px;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .column-header.line-1 { background: #2563eb; }
        .column-header.line-2 { background: #059669; }
        .column-header.line-3 { background: #9333ea; }
        .column-header.line-4 { background: #d97706; }

        .column-title {
            font-weight: 900;
            font-size: 10px;
            letter-spacing: 0.3px;
        }

        .column-totals {
            font-family: monospace;
            font-size: 8.5px;
            opacity: 0.95;
        }

        .column-body {
            padding: 4px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .card-item {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 3px;
            padding: 4px 5px;
            page-break-inside: avoid;
        }

        .card-item.is-urgent {
            border: 1.5px solid #dc2626;
            background: #fff5f5;
        }

        .card-row-1 {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 4px;
            margin-bottom: 2px;
        }

        .item-name {
            font-weight: bold;
            font-size: 9px;
            line-height: 1.15;
            color: #0f172a;
            flex-grow: 1;
        }

        .seq-badge {
            font-family: monospace;
            font-weight: 900;
            font-size: 8.5px;
            background: #0f172a;
            color: #ffffff;
            padding: 1px 3px;
            border-radius: 2px;
            flex-shrink: 0;
        }

        .urgent-badge {
            background: #dc2626;
            color: #ffffff;
            font-size: 7.5px;
            font-weight: 900;
            padding: 1px 2px;
            border-radius: 2px;
            flex-shrink: 0;
        }

        .card-row-2 {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-family: monospace;
            font-size: 8px;
            color: #475569;
            margin-bottom: 2px;
            padding-bottom: 2px;
            border-bottom: 1px dashed #e2e8f0;
        }

        .traveler-badge {
            font-weight: 900;
            color: #065f46;
        }

        .card-row-3 {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-family: monospace;
            font-size: 8.5px;
            margin-bottom: 2px;
        }

        .heat-text {
            color: #475569;
        }

        .qty-val {
            font-weight: 900;
            color: #0f172a;
        }

        .weight-val {
            font-weight: bold;
            color: #047857;
        }

        .card-row-4 {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 8px;
            color: #64748b;
            padding-top: 2px;
            border-top: 1px solid #f1f5f9;
        }

        .customer-badge {
            font-family: monospace;
            font-weight: bold;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 0 3px;
            border-radius: 2px;
            color: #334155;
        }

        .card-empty {
            padding: 16px 8px;
            text-align: center;
            color: #94a3b8;
            font-style: italic;
            font-size: 8.5px;
            background: #ffffff;
            border: 1px dashed #e2e8f0;
            border-radius: 3px;
        }

        @media print {
            .no-print-toolbar {
                display: none !important;
            }
            body {
                background: #ffffff;
                font-size: 9px;
            }
        }
    </style>
</head>
<body>

    {{-- Interactive Toolbar --}}
    <div class="no-print-toolbar">
        <div class="no-print-title">
            <span>KANBAN FITTING PRODUCTION &mdash; {{ $stageLabel }}</span>
        </div>
        <div class="no-print-actions">
            <button type="button" class="btn btn-print" onclick="window.print()">
                <span>Cetak Dokumen</span>
            </button>
            <button type="button" class="btn btn-close" onclick="window.close()">
                <span>Tutup</span>
            </button>
        </div>
    </div>

    {{-- Document Header --}}
    <div class="doc-header">
        <div class="company-name">PT. PERONI KARYA SENTRA</div>
        <div class="header-top">
            <h1 class="doc-title">KANBAN FITTING PRODUCTION &mdash; {{ $stageLabel }}</h1>
            <div class="doc-timestamp">Waktu Cetak: {{ $printedAtWib }}</div>
        </div>

        <div class="meta-bar">
            <div class="filter-badges">
                <span class="filter-item">Tahap: <strong>{{ $stageLabel }}</strong></span>
                @if(!empty($filters['search']))
                    <span class="filter-item">Pencarian: <strong>"{{ $filters['search'] }}"</strong></span>
                @endif
                @if(!empty($filters['customer']))
                    <span class="filter-item">Customer: <strong>"{{ $filters['customer'] }}"</strong></span>
                @endif
                @if(!empty($filters['line_bucket']))
                    <span class="filter-item">Line: <strong>Line {{ $filters['line_bucket'] }}</strong></span>
                @else
                    <span class="filter-item">Line: <strong>Semua Line (Line 1–4)</strong></span>
                @endif
                @if(!empty($filters['is_urgent']))
                    <span class="filter-item">Status: <strong>HANYA URGENT</strong></span>
                @endif
            </div>

            <div class="kpi-totals">
                <div class="kpi-item">
                    <span>Total Kartu:</span>
                    <span class="kpi-val">{{ number_format($summary['total_cards']) }}</span>
                </div>
                <div class="kpi-item">
                    <span>Total Qty:</span>
                    <span class="kpi-val pcs">{{ number_format($summary['total_pcs']) }} PCS</span>
                </div>
                <div class="kpi-item">
                    <span>Total Berat:</span>
                    <span class="kpi-val kg">{{ number_format($summary['total_weight_kg'], 1, ',', '.') }} KG</span>
                </div>
            </div>
        </div>
    </div>

    @php
        $bucketTitles = [
            '1' => 'LINE 1 — FITTING BIASA',
            '2' => 'LINE 2 — UNION',
            '3' => 'LINE 3 — BALL VALVE',
            '4' => 'LINE 4 — BW',
        ];
        $linesToRender = !empty($filters['line_bucket']) && in_array($filters['line_bucket'], ['1', '2', '3', '4'], true)
            ? [$filters['line_bucket']]
            : ['1', '2', '3', '4'];
    @endphp

    {{-- 4 Columns Grid --}}
    <div class="kanban-grid" style="{{ count($linesToRender) === 1 ? 'grid-template-columns: 1fr;' : '' }}">
        @foreach($linesToRender as $bKey)
            @php
                $colCards = $buckets[$bKey] ?? [];
                $colPcs = (int) array_sum(array_column($colCards, 'qty_transferred'));
                $colKg = (float) array_sum(array_column($colCards, 'total_weight_kg'));
            @endphp
            <div class="kanban-column">
                <div class="column-header line-{{ $bKey }}">
                    <div class="column-title">{{ $bucketTitles[$bKey] ?? "LINE {$bKey}" }}</div>
                    <div class="column-totals">{{ count($colCards) }} Kartu &bull; {{ number_format($colPcs) }} PCS &bull; {{ number_format($colKg, 1, ',', '.') }} KG</div>
                </div>

                <div class="column-body">
                    @forelse($colCards as $card)
                        @php
                            $effectiveQty = (int) ($card['effective_input_qty'] ?? $card['qty_transferred']);
                            $cardWeight = (float) ($card['total_weight_kg'] ?? 0.0);
                        @endphp
                        <div class="card-item {{ $card['is_urgent'] ? 'is-urgent' : '' }}">
                            <div class="card-row-1">
                                <div class="item-name">{{ $card['item_name'] }}</div>
                                <div style="display: flex; gap: 2px;">
                                    @if($card['is_urgent'])
                                        <span class="urgent-badge">URGENT</span>
                                    @endif
                                    <span class="seq-badge">#{{ $card['queue_seq'] }}</span>
                                </div>
                            </div>

                            <div class="card-row-2">
                                <span class="traveler-badge">{{ $card['traveler_number'] }}</span>
                                <span>{{ $card['production_code'] }}</span>
                            </div>

                            <div class="card-row-3">
                                <span class="heat-text">Heat: <strong>{{ $card['heat_number'] }}</strong></span>
                                <div>
                                    <span class="qty-val">{{ number_format($effectiveQty) }} PCS</span>
                                    <span style="color: #cbd5e1;">/</span>
                                    <span class="weight-val">{{ number_format($cardWeight, 1, ',', '.') }} KG</span>
                                </div>
                            </div>

                            <div class="card-row-4">
                                <span class="customer-badge">[{{ $card['customer'] }}]</span>
                                <span>Trf: {{ $card['transfer_date_formatted'] }} &bull; <strong>{{ $card['current_stage_label'] }}</strong></span>
                            </div>
                        </div>
                    @empty
                        <div class="card-empty">
                            Tidak ada antrean
                        </div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

</body>
</html>
