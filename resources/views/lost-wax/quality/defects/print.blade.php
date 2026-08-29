<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Kerusakan Lost Wax ({{ $activeFilters['date_from'] }} s/d {{ $activeFilters['date_to'] }})</title>
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
            padding: 4px 6px;
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
            background-color: #fee2e2;
            color: #991b1b;
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

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-mono { font-family: monospace; }
        .font-bold { font-weight: bold; }
        .badge {
            display: inline-block;
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            border: 1px solid #cbd5e1;
        }

        .footer {
            margin-top: 15px;
            display: flex;
            justify-content: space-between;
            font-size: 9px;
            color: #64748b;
        }

        @media print {
            .no-print { display: none !important; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>

    <div class="no-print" style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 10px 15px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center;">
        <span style="font-weight: bold; color: #334155;">Pratinjau Dokumen Rekap Kerusakan Lost Wax</span>
        <div>
            <button onclick="window.print()" style="padding: 6px 16px; background: #2563eb; color: #fff; font-weight: bold; border: none; border-radius: 4px; cursor: pointer;">
                Cetak / Simpan PDF
            </button>
            <button onclick="window.close()" style="padding: 6px 12px; background: #94a3b8; color: #fff; font-weight: bold; border: none; border-radius: 4px; cursor: pointer; margin-left: 5px;">
                Tutup
            </button>
        </div>
    </div>

    <div class="header">
        <h1>Rekap Kerusakan Lost Wax (Daily Defect Report)</h1>
        <div class="meta">
            Periode: <strong>{{ $activeFilters['date_from'] }}</strong> s/d <strong>{{ $activeFilters['date_to'] }}</strong>
            &bull; Tahapan: <strong>{{ strtoupper($activeFilters['stage']) }}</strong>
            &bull; Mode: <strong>{{ strtoupper($activeFilters['mode']) }}</strong>
            @if(!empty($activeFilters['production_code']))
                &bull; Kode Produksi: <strong>{{ $activeFilters['production_code'] }}</strong>
            @endif
            &bull; Dicetak pada: {{ now()->format('d-m-Y H:i:s') }}
        </div>
    </div>

    {{-- KPI Summary --}}
    <div class="kpi-container">
        <table class="kpi-table">
            <thead>
                <tr>
                    <th>Cetak</th>
                    <th>Rangkai</th>
                    <th>Lap 1</th>
                    <th>Lap 2</th>
                    <th>Lap 3</th>
                    <th>Lap 4</th>
                    <th>Lap 5</th>
                    <th>Lap 6</th>
                    <th>Lap 7</th>
                    <th>Oven</th>
                    <th class="grand-total">GRAND TOTAL</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ number_format($summary['cetak']) }}</td>
                    <td>{{ number_format($summary['assembly']) }}</td>
                    <td>{{ number_format($summary['layer_1']) }}</td>
                    <td>{{ number_format($summary['layer_2']) }}</td>
                    <td>{{ number_format($summary['layer_3']) }}</td>
                    <td>{{ number_format($summary['layer_4']) }}</td>
                    <td>{{ number_format($summary['layer_5']) }}</td>
                    <td>{{ number_format($summary['layer_6']) }}</td>
                    <td>{{ number_format($summary['layer_7']) }}</td>
                    <td>{{ number_format($summary['oven']) }}</td>
                    <td class="grand-total">{{ number_format($summary['grand_total']) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Main Data Table --}}
    @if($activeFilters['mode'] === 'detail')
        <table class="data-table">
            <thead>
                <tr>
                    <th class="text-center" style="width: 30px;">No</th>
                    <th style="width: 90px;">Kode Produksi</th>
                    <th style="width: 100px;">Barcode Tree</th>
                    <th>Nama Item</th>
                    <th class="text-center" style="width: 80px;">Tahapan</th>
                    <th class="text-right" style="width: 70px;">Rusak</th>
                    <th>Alasan Defect</th>
                    <th style="width: 90px;">Operator</th>
                    <th class="text-center" style="width: 100px;">Waktu Kejadian</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $idx => $item)
                    <tr>
                        <td class="text-center font-mono">{{ $idx + 1 }}</td>
                        <td class="font-mono font-bold">{{ $item['production_code'] }}</td>
                        <td class="font-mono">{{ $item['barcode'] }}</td>
                        <td>{{ $item['item_name'] }}</td>
                        <td class="text-center">
                            <span class="badge">{{ strtoupper($item['stage_label']) }}</span>
                        </td>
                        <td class="text-right font-mono font-bold">{{ number_format($item['defect_qty']) }}</td>
                        <td>
                            <strong>{{ $item['defect_reason'] }}</strong>
                            @if(!empty($item['notes']) && $item['notes'] !== $item['defect_reason'])
                                <br><span style="color: #64748b; font-style: italic;">{{ $item['notes'] }}</span>
                            @endif
                        </td>
                        <td>{{ $item['operator'] }}</td>
                        <td class="text-center font-mono">
                            {{ $item['occurred_at'] ? \Carbon\Carbon::parse($item['occurred_at'])->format('d-m-Y H:i') : '-' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center" style="padding: 20px; color: #94a3b8;">
                            Tidak ada data kerusakan tercatat untuk parameter ini.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    @else
        <table class="data-table">
            <thead>
                <tr>
                    <th class="text-center" style="width: 35px;">No</th>
                    <th style="width: 130px;">Kode Produksi</th>
                    <th>Nama Item</th>
                    <th class="text-center" style="width: 120px;">Tahapan (Stage)</th>
                    <th class="text-right" style="width: 100px;">Jumlah Rusak (pcs)</th>
                    <th class="text-center" style="width: 100px;">Jumlah Kejadian</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $idx => $item)
                    <tr>
                        <td class="text-center font-mono">{{ $idx + 1 }}</td>
                        <td class="font-mono font-bold">{{ $item['production_code'] }}</td>
                        <td>{{ $item['item_name'] }}</td>
                        <td class="text-center">
                            <span class="badge">{{ strtoupper($item['stage_label']) }}</span>
                        </td>
                        <td class="text-right font-mono font-bold">{{ number_format($item['defect_qty']) }}</td>
                        <td class="text-center font-mono">{{ $item['record_count'] }} kali</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center" style="padding: 20px; color: #94a3b8;">
                            Tidak ada data kerusakan tercatat untuk parameter ini.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    @endif

    <div class="footer">
        <div>FIFO Tracking System &bull; Quality Control Subsystem</div>
        <div>Halaman 1 dari 1</div>
    </div>

</body>
</html>
