<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Accounting Sand Casting ({{ $filters['date_from'] }} s/d {{ $filters['date_to'] }})</title>
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
            font-size: 8px;
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
            background: #059669;
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
            font-size: 8.5px;
            color: #475569;
        }

        /* 22-Column Main Table */
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
            border: 1px solid #475569;
            padding: 3px 2px;
            font-size: 7.5px;
            text-align: center;
            font-weight: bold;
            text-transform: uppercase;
        }

        .tracking-table th.th-ident {
            background-color: #1e293b;
            color: #ffffff;
        }

        .tracking-table th.th-dept {
            background-color: #0284c7;
            color: #ffffff;
            border-left: 1px solid #0369a1;
            border-right: 1px solid #0369a1;
        }

        .tracking-table th.th-sub {
            background-color: #0f172a;
            color: #94a3b8;
            font-size: 7px;
        }

        .tracking-table td {
            border: 1px solid #cbd5e1;
            padding: 2.5px 2px;
            font-size: 7px;
            vertical-align: middle;
            word-wrap: break-word;
            overflow: hidden;
        }

        .tracking-table tr:nth-child(even) td {
            background-color: #f8fafc;
        }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .font-mono { font-family: monospace; }
        .font-bold { font-weight: bold; }
        .text-rose { color: #dc2626; font-weight: bold; }
        .text-muted { color: #94a3b8; }

        @media print {
            .no-print {
                display: none !important;
            }
            body {
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>
<body>

    {{-- Interactive Non-print Toolbar --}}
    <div class="no-print">
        <div class="no-print-title">
            PRINTER PREVIEW — LAPORAN ACCOUNTING SAND CASTING (A4 LANDSCAPE)
        </div>
        <div>
            <button class="btn btn-print" onclick="window.print()">
                Cetak Dokumen
            </button>
            <button class="btn btn-close" onclick="window.close()">
                Tutup
            </button>
        </div>
    </div>

    {{-- Header --}}
    <div class="header">
        <h1>LAPORAN ACCOUNTING SAND CASTING</h1>
        <div class="meta">
            HORIZONTAL PRODUCTION TRACKING ({{ ($filters['report_format'] ?? 'detail') === 'summary' || ($filters['report_format'] ?? '') === 'ringkas' ? 'RINGKAS PER KODE PRODUKSI' : 'KODE PRODUKSI + HEAT NUMBER' }})
            | Periode: <strong>{{ \Carbon\Carbon::parse($filters['date_from'])->format('d/m/Y') }}</strong> s/d <strong>{{ \Carbon\Carbon::parse($filters['date_to'])->format('d/m/Y') }}</strong>
            | Dicetak: <strong>{{ now()->setTimezone(\App\Services\SandCasting\SandCastingAccountingReportService::TIMEZONE)->format('d/m/Y H:i:s') }}</strong>
            | User: <strong>{{ auth()->user()->name ?? 'Accounting' }}</strong>
            | Total: <strong>{{ count($data['pergerakan']) }} {{ ($filters['report_format'] ?? 'detail') === 'summary' || ($filters['report_format'] ?? '') === 'ringkas' ? 'Kode Produksi' : 'Baris' }}</strong>
        </div>
    </div>

    @php
        $isSummary = ($filters['report_format'] ?? 'detail') === 'summary' || ($filters['report_format'] ?? '') === 'ringkas';
        $stagesList = ['cor' => 'COR', 'netto' => 'NETTO', 'bubut_od' => 'BUBUT OD', 'bubut_cnc' => 'BUBUT CNC', 'bor' => 'BOR', 'qc' => 'QC', 'gudang_jadi' => 'GUDANG JADI'];
    @endphp

    <table class="tracking-table">
        {{-- Repeatable 2-Tier Header --}}
        <thead>
            <tr>
                <th rowspan="2" class="th-ident" style="width: 18px;">No</th>
                <th rowspan="2" class="th-ident" style="width: 58px;">Kode Produksi</th>
                @if(!$isSummary)
                    <th rowspan="2" class="th-ident" style="width: 52px;">Heat Number</th>
                @endif
                <th rowspan="2" class="th-ident" style="width: {{ $isSummary ? '100px' : '110px' }};">Nama Item</th>
                <th rowspan="2" class="th-ident" style="width: 30px;">AISI</th>
                <th rowspan="2" class="th-ident" style="width: {{ $isSummary ? '60px' : '65px' }};">Customer</th>
                @if($isSummary)
                    <th rowspan="2" class="th-ident" style="width: 45px;">P.O (PCS)</th>
                    <th rowspan="2" class="th-ident" style="width: 40px;">Plan (PCS)</th>
                @endif
                <th rowspan="2" class="th-ident" style="width: 38px;">Kg/Pcs</th>
                <th rowspan="2" class="th-ident" style="width: 44px;">Total (KG)</th>

                {{-- 7 Stages Groups --}}
                @foreach($stagesList as $stKey => $stLabel)
                    <th colspan="2" class="th-dept" style="width: 50px;">{{ $stLabel }}</th>
                @endforeach
            </tr>
            <tr>
                @foreach($stagesList as $stKey => $stLabel)
                    <th class="th-sub" style="width: 25px;">H</th>
                    <th class="th-sub" style="width: 25px; color: #fca5a5;">R</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($data['pergerakan'] as $idx => $r)
                <tr>
                    <td class="text-center font-mono">{{ $idx + 1 }}</td>
                    <td class="font-bold">{{ $r['code'] }}</td>
                    @if(!$isSummary)
                        <td class="font-mono">{{ $r['heat_number'] }}</td>
                    @endif
                    <td>{{ $r['item_name'] }}</td>
                    <td class="text-center font-mono">{{ $r['aisi'] }}</td>
                    <td>{{ $r['customer'] }}</td>

                    @if($isSummary)
                        <td class="text-right font-mono" style="font-size: 7px;">
                            @if(($r['is_po_anomaly'] ?? false) || ($r['po_number'] ?? '') === 'Bervariasi' || ($r['po_qty'] ?? '') === 'Bervariasi')
                                <span style="color: #d97706; font-weight: bold;">Bervariasi</span>
                            @elseif(is_numeric($r['po_qty'] ?? $r['po_number'] ?? null))
                                <strong>{{ number_format($r['po_qty'] ?? $r['po_number'], 0, ',', '.') }}</strong>
                            @else
                                
                            @endif
                        </td>
                        <td class="text-right font-mono font-bold" style="font-size: 7px;">
                            @if(($r['is_plan_anomaly'] ?? false) || ($r['qty_planned'] ?? '') === 'Bervariasi')
                                <span style="color: #d97706; font-weight: bold;">Bervariasi</span>
                            @elseif(is_numeric($r['qty_planned'] ?? null))
                                <strong>{{ number_format($r['qty_planned'], 0, ',', '.') }}</strong>
                            @else
                                
                            @endif
                        </td>
                    @endif

                    <td class="text-right font-mono">
                        @if(($r['unit_weight_label'] ?? '') === 'Bervariasi')
                            <span style="color: #d97706; font-weight: bold;">Bervariasi</span>
                        @elseif(is_numeric($r['unit_weight_kg'] ?? null) && (float)$r['unit_weight_kg'] > 0)
                            {{ number_format($r['unit_weight_kg'], 2, ',', '.') }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="text-right font-mono font-bold">{{ number_format($r['total_weight_kg'], 2, ',', '.') }}</td>

                    {{-- 7 Stages Columns --}}
                    @foreach(array_keys($stagesList) as $stKey)
                        @php
                            $st = $r['stages'][$stKey] ?? null;
                            $hasData = ($st && $st['is_done']);
                            $hVal = $hasData && $st['hasil'] !== null ? number_format($st['hasil'], 0, ',', '.') : '—';
                            $rRaw = $hasData && $st['rusak'] !== null ? (int)$st['rusak'] : null;
                            $rVal = $rRaw !== null ? number_format($rRaw, 0, ',', '.') : '—';
                        @endphp
                        <td class="text-center font-mono {{ $hasData && $st['hasil'] !== null ? 'font-bold' : 'text-muted' }}">{{ $hVal }}</td>
                        <td class="text-center font-mono {{ $rRaw !== null && $rRaw > 0 ? 'text-rose' : ($rRaw === 0 ? '' : 'text-muted') }}">{{ $rVal }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $isSummary ? 23 : 22 }}" class="text-center" style="padding: 15px; color: #64748b;">
                        Tidak ada aktivitas pergerakan produksi pada rentang tanggal ini.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- Keterangan / Legend & Footer Info --}}
    <div style="margin-top: 8px; padding: 6px 10px; border: 1px solid #cbd5e1; background: #f8fafc; font-size: 7.5px; page-break-inside: avoid;">
        <div style="font-weight: bold; margin-bottom: 3px; color: #0f172a; text-transform: uppercase;">
            Keterangan Matriks Pergerakan Produksi:
        </div>
        <div style="display: flex; gap: 15px; margin-bottom: 3px;">
            <div><strong>H (Hasil):</strong> Kuantitas (PCS) yang dikerjakan pada proses tersebut (termasuk cacat).</div>
            <div><strong style="color: #b91c1c;">R (Rusak):</strong> Kuantitas (PCS) defect yang dicatat pada proses tersebut.</div>
            <div><strong style="color: #047857;">Output:</strong> Kuantitas bersih yang diteruskan ke proses berikutnya (Hasil - Rusak).</div>
        </div>
        <div style="color: #64748b; font-size: 7px; border-top: 1px dashed #cbd5e1; padding-top: 3px; display: flex; justify-content: space-between;">
            <div>
                • Tanda <strong>—</strong> : Tahapan proses belum tercapai / belum dikerjakan pada periode ini.
                &nbsp;|&nbsp; • Angka <strong>0</strong> : Selesai tanpa kerusakan (defect 0 PCS).
                &nbsp;|&nbsp; • <strong>Total Weight (KG)</strong> : Dihitung dari Batch Physical PCS × Weight (KG/PCS).
            </div>
            <div>
                Dokumen Resmi PPIC / Accounting Sand Casting — Dicetak: {{ now()->setTimezone(\App\Services\SandCasting\SandCastingAccountingReportService::TIMEZONE)->format('d/m/Y H:i') }}
            </div>
        </div>
    </div>

</body>
</html>
