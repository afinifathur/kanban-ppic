<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Accounting Lost Wax ({{ $filters['date_from'] }} s/d {{ $filters['date_to'] }})</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 6mm 8mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 7.5px;
            color: #0f172a;
            margin: 0;
            padding: 0;
            background: #ffffff;
        }

        .no-print {
            background: #1e293b;
            color: #ffffff;
            padding: 8px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            border-radius: 6px;
        }

        .no-print-title {
            font-weight: bold;
            font-size: 12px;
            color: #f8fafc;
            letter-spacing: 0.3px;
        }

        .btn {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: bold;
            font-size: 11px;
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
            margin-left: 8px;
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 6px;
            margin-bottom: 8px;
        }

        .header h1 {
            margin: 0;
            font-size: 13px;
            font-weight: bold;
            color: #0f172a;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .header .meta {
            margin-top: 3px;
            font-size: 8px;
            color: #475569;
        }

        /* 29-Column Main Table */
        .tracking-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 8px;
        }

        .tracking-table thead {
            display: table-header-group;
        }

        .tracking-table th {
            border: 1px solid #334155;
            padding: 3px 2px;
            text-align: center;
            font-weight: bold;
            font-size: 7px;
            vertical-align: middle;
        }

        .th-identity {
            background: #0f172a;
            color: #ffffff;
        }

        .th-stage {
            background: #0369a1;
            color: #ffffff;
        }

        .th-sub {
            background: #1e293b;
            color: #f1f5f9;
            font-size: 6.5px;
        }

        .tracking-table td {
            border: 1px solid #cbd5e1;
            padding: 2.5px 2px;
            font-size: 7px;
            vertical-align: middle;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .tracking-table tr:nth-child(even) {
            background: #f8fafc;
        }

        .text-center { text-align: center; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
        .font-mono { font-family: "Courier New", Courier, monospace; }

        .defect-positive {
            color: #e11d48;
            font-weight: bold;
        }

        .unreached {
            color: #94a3b8;
        }

        .footer {
            margin-top: 8px;
            display: flex;
            justify-content: space-between;
            font-size: 7.5px;
            color: #64748b;
        }

        @media print {
            .no-print {
                display: none !important;
            }
            body {
                font-size: 7px;
            }
            .tracking-table tr {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>

    {{-- Top Bar (Screen Only) --}}
    <div class="no-print">
        <div class="no-print-title">
            LAPORAN ACCOUNTING LOST WAX — CETAK PDF
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-print">Cetak / Simpan PDF</button>
            <button onclick="window.close()" class="btn btn-close">Tutup</button>
        </div>
    </div>

    @php
        $meta = $data['meta'];
        $stagesList = [
            'cetak' => 'CETAK',
            'rangkai' => 'RANGKAI',
            'layer_1' => 'LAP 1',
            'layer_2' => 'LAP 2',
            'layer_3' => 'LAP 3',
            'layer_4' => 'LAP 4',
            'layer_5' => 'LAP 5',
            'layer_6' => 'LAP 6',
            'oven' => 'OVEN',
            'cor' => 'HASIL COR',
        ];
    @endphp

    {{-- Document Header --}}
    <div class="header">
        <h1>Laporan Accounting Lost Wax — Pergerakan Produksi Horizontal</h1>
        <div class="meta">
            <strong>Format:</strong> Ringkas per Kode Produksi &nbsp;|&nbsp;
            <strong>Periode:</strong> {{ $meta['date_from'] }} s/d {{ $meta['date_to'] }} (Cutoff: {{ $meta['date_to'] }} 23:59:59 WIB) &nbsp;|&nbsp;
            <strong>Dicetak:</strong> {{ $meta['generated_at'] }} &nbsp;|&nbsp;
            <strong>Operator:</strong> {{ $meta['generated_by'] }} &nbsp;|&nbsp;
            <strong>Total:</strong> {{ count($data['pergerakan']) }} Kode Produksi
        </div>
    </div>

    {{-- 29-Column Horizontal Table --}}
    <table class="tracking-table">
        <colgroup>
            <col style="width: 2%;">  {{-- No --}}
            <col style="width: 5.5%;">  {{-- Kode Produksi --}}
            <col style="width: 10%;"> {{-- Nama Item --}}
            <col style="width: 3.5%;">  {{-- AISI --}}
            <col style="width: 6%;">  {{-- Customer --}}
            <col style="width: 4%;">  {{-- PO --}}
            <col style="width: 4%;">  {{-- Plan --}}
            <col style="width: 4.5%;">  {{-- Weight --}}
            <col style="width: 5%;">  {{-- Total Weight --}}
            {{-- 10 Stages x 2 Cols (Hasil & Rusak) = 20 cols = 55.5% --}}
            @for ($i = 0; $i < 20; $i++)
                <col style="width: 2.77%;">
            @endfor
        </colgroup>
        <thead>
            {{-- Row 1: Group Headers --}}
            <tr>
                <th rowspan="2" class="th-identity">No</th>
                <th rowspan="2" class="th-identity">Kode</th>
                <th rowspan="2" class="th-identity">Nama Item</th>
                <th rowspan="2" class="th-identity">AISI</th>
                <th rowspan="2" class="th-identity">Cust</th>
                <th rowspan="2" class="th-identity">P.O</th>
                <th rowspan="2" class="th-identity">Plan</th>
                <th rowspan="2" class="th-identity">Weight</th>
                <th rowspan="2" class="th-identity">Tot. Wt</th>
                @foreach ($stagesList as $stgKey => $stgLabel)
                    <th colspan="2" class="th-stage">{{ $stgLabel }}</th>
                @endforeach
            </tr>
            {{-- Row 2: Sub Headers --}}
            <tr>
                @foreach ($stagesList as $stgKey => $stgLabel)
                    <th class="th-sub">Hsl</th>
                    <th class="th-sub">Rsk</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($data['pergerakan'] as $idx => $r)
                <tr>
                    <td class="text-center font-mono">{{ $idx + 1 }}</td>
                    <td class="font-bold font-mono">{{ $r['code'] }}</td>
                    <td title="{{ $r['item_name'] }}">{{ $r['item_name'] }}</td>
                    <td class="text-center font-mono">{{ $r['aisi'] }}</td>
                    <td title="{{ $r['customer'] }}">{{ $r['customer'] }}</td>
                    <td class="text-right font-mono">{{ is_numeric($r['po_qty']) ? number_format($r['po_qty'], 0, ',', '.') : $r['po_qty'] }}</td>
                    <td class="text-right font-mono">{{ is_numeric($r['qty_planned']) ? number_format($r['qty_planned'], 0, ',', '.') : $r['qty_planned'] }}</td>
                    <td class="text-right font-mono">{{ is_numeric($r['unit_weight_label']) ? number_format((float)$r['unit_weight_label'], 2, ',', '.') : $r['unit_weight_label'] }}</td>
                    <td class="text-right font-mono font-bold">{{ number_format((float)$r['total_weight_kg'], 2, ',', '.') }}</td>

                    @foreach (array_keys($stagesList) as $stgKey)
                        @php
                            $stgInfo = $r['stages'][$stgKey] ?? null;
                            $h = $stgInfo['hasil'] ?? null;
                            $rus = $stgInfo['rusak'] ?? null;
                        @endphp
                        <td class="text-right font-mono {{ $h === null ? 'unreached' : '' }}">
                            {{ $h !== null ? number_format($h, 0, ',', '.') : '—' }}
                        </td>
                        <td class="text-right font-mono {{ (int)$rus > 0 ? 'defect-positive' : ($rus === null ? 'unreached' : '') }}">
                            {{ $rus !== null ? number_format($rus, 0, ',', '.') : '—' }}
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="29" class="text-center" style="padding: 20px;">
                        Tidak ada data pergerakan produksi pada rentang tanggal ini.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        <div>Keterangan: Hsl = Hasil Selesai Departemen | Rsk = Rusak/Defect | — = Tahap Belum Tercapai</div>
        <div>Dicetak melalui Sistem Kanban-PPIC &bull; Halaman 1</div>
    </div>

</body>
</html>
