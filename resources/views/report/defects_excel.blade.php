<table>
    <thead>
        <tr>
            <th colspan="5" style="text-align: center; font-size: 14pt; font-weight: bold;">LAPORAN KERUSAKAN PRODUKSI
            </th>
        </tr>
        <tr>
            <th colspan="5" style="text-align: center; font-size: 12pt; font-weight: bold;">DEPARTEMEN
                {{ strtoupper($department === 'all' ? 'SEMUA DEPARTEMEN' : str_replace('_', ' ', $department)) }}
            </th>
        </tr>
        <tr>
            <th colspan="5" style="text-align: center;">Tanggal: {{ date('d F Y', strtotime($date)) }}</th>
        </tr>
        @if($department !== 'all')
            <tr>
                <th colspan="5" style="text-align: center; font-weight: bold;">JENIS:
                    {{ $defectType ? strtoupper($defectType->name) : 'SEMUA JENIS' }}
                </th>
            </tr>
        @endif
        <tr></tr>
    </thead>
    <tbody>
        @if($department === 'all')
            @foreach($results as $deptName => $deptItems)
                <tr style="background-color: #f3f4f6;">
                    <th colspan="5" style="border: 1px solid #000000; font-weight: bold; text-align: left;">
                        DEPARTEMEN: {{ strtoupper(str_replace('_', ' ', $deptName)) }}
                    </th>
                </tr>
                <tr style="background-color: #cccccc;">
                    <th style="border: 1px solid #000000; font-weight: bold;">No</th>
                    <th style="border: 1px solid #000000; font-weight: bold;">Heat Number</th>
                    <th style="border: 1px solid #000000; font-weight: bold;">Nama Item</th>
                    <th style="border: 1px solid #000000; font-weight: bold;">Qty (pcs)</th>
                    <th style="border: 1px solid #000000; font-weight: bold;">Catatan</th>
                </tr>
                @foreach($deptItems as $index => $item)
                    <tr>
                        <td style="border: 1px solid #000000; text-align: center;">{{ $index + 1 }}</td>
                        <td style="border: 1px solid #000000; font-weight: bold;">{{ $item->heat_number }}</td>
                        <td style="border: 1px solid #000000;">{{ $item->item_name }}</td>
                        <td style="border: 1px solid #000000; text-align: center;">{{ $item->total_defect_qty }}</td>
                        <td style="border: 1px solid #000000; font-weight: bold;">{{ strtoupper($item->defect_summary) ?: '-' }}
                        </td>
                    </tr>
                @endforeach
                <tr style="font-weight: bold; background-color: #eeeeee;">
                    <td colspan="3" style="border: 1px solid #000000; text-align: right;">TOTAL
                        {{ strtoupper(str_replace('_', ' ', $deptName)) }}</td>
                    <td style="border: 1px solid #000000; text-align: center;">{{ $deptItems->sum('total_defect_qty') }}</td>
                    <td style="border: 1px solid #000000;"></td>
                </tr>
                <tr></tr> {{-- Spacer --}}
            @endforeach
            <tr style="font-weight: bold; background-color: #d1d5db;">
                <td colspan="3" style="border: 1px solid #000000; text-align: right; font-size: 12pt;">GRAND TOTAL</td>
                <td style="border: 1px solid #000000; text-align: center; font-size: 12pt;">{{ $totalQty }}</td>
                <td style="border: 1px solid #000000;"></td>
            </tr>
        @else
            <tr style="background-color: #cccccc;">
                <th style="border: 1px solid #000000; font-weight: bold;">No</th>
                <th style="border: 1px solid #000000; font-weight: bold;">Heat Number</th>
                <th style="border: 1px solid #000000; font-weight: bold;">Nama Item</th>
                <th style="border: 1px solid #000000; font-weight: bold;">Qty (pcs)</th>
                <th style="border: 1px solid #000000; font-weight: bold;">Catatan</th>
            </tr>
            @foreach($results as $index => $item)
                <tr>
                    <td style="border: 1px solid #000000; text-align: center;">{{ $index + 1 }}</td>
                    <td style="border: 1px solid #000000; font-weight: bold;">{{ $item->heat_number }}</td>
                    <td style="border: 1px solid #000000;">{{ $item->item_name }}</td>
                    <td style="border: 1px solid #000000; text-align: center;">{{ $item->total_defect_qty }}</td>
                    <td style="border: 1px solid #000000; font-weight: bold;">{{ strtoupper($item->defect_summary) ?: '-' }}
                    </td>
                </tr>
            @endforeach
            <tr>
                <td colspan="3" style="border: 1px solid #000000; text-align: right; font-weight: bold;">TOTAL</td>
                <td style="border: 1px solid #000000; text-align: center; font-weight: bold;">{{ $totalQty }}</td>
                <td style="border: 1px solid #000000;"></td>
            </tr>
        @endif
    </tbody>
</table>