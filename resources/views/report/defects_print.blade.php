<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Kerusakan - {{ ucfirst($department) }}</title>
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
    <style>
        @media print {
            body {
                -webkit-print-color-adjust: exact;
            }

            .no-print {
                display: none;
            }
        }

        body {
            font-family: 'Times New Roman', serif;
        }
    </style>
</head>

<body class="bg-gray-100 p-8 print:bg-white print:p-0" onload="window.print()">

    <div class="max-w-4xl mx-auto bg-white p-8 shadow-sm print:shadow-none print:w-full">
        <!-- Header -->
        <div class="text-center mb-6 border-b-2 border-black pb-4">
            <h1 class="text-2xl font-bold uppercase tracking-wider">Laporan Kerusakan Produksi</h1>
            <h2 class="text-lg font-bold uppercase mt-1">Departemen
                {{ $department === 'all' ? 'SEMUA DEPARTEMEN' : str_replace('_', ' ', $department) }}</h2>
        </div>

        <!-- Info -->
        <div class="flex justify-between mb-6 text-sm font-medium">
            <div>
                <p>Tanggal: <span class="font-normal">{{ date('d F Y', strtotime($date)) }}</span></p>
                @if($department !== 'all')
                    <p>Jenis Kerusakan: <span
                            class="font-bold text-lg uppercase">{{ $defectType ? $defectType->name : 'SEMUA JENIS' }}</span>
                    </p>
                @endif
            </div>
            <div class="text-right">
                <p>No. Dokumen:
                    DEF/{{ $department === 'all' ? 'ALL' : strtoupper(substr($department, 0, 3)) }}/{{ date('Ymd') }}
                </p>
                <p>Dicetak Oleh: {{ Auth::user()->name }}</p>
            </div>
        </div>

        <!-- Table(s) -->
        <div class="space-y-12">
            @if($department === 'all')
                @foreach($results as $deptName => $deptItems)
                    <div class="dept-section">
                        <h3 class="text-md font-bold uppercase border-b border-black mb-2">Departemen:
                            {{ str_replace('_', ' ', $deptName) }}</h3>
                        <table class="w-full border-collapse border border-black mb-4 text-sm">
                            <thead>
                                <tr class="bg-gray-200 text-black">
                                    <th class="border border-black px-2 py-1.5 text-center w-10">No</th>
                                    <th class="border border-black px-2 py-1.5 text-left">Heat Number</th>
                                    <th class="border border-black px-2 py-1.5 text-left">Nama Item</th>
                                    <th class="border border-black px-2 py-1.5 text-center">Qty (pcs)</th>
                                    <th class="border border-black px-2 py-1.5 text-left">Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($deptItems as $index => $item)
                                    <tr>
                                        <td class="border border-black px-2 py-1.5 text-center">{{ $index + 1 }}</td>
                                        <td class="border border-black px-2 py-1.5 font-bold">{{ $item->heat_number }}</td>
                                        <td class="border border-black px-2 py-1.5">{{ $item->item_name }}</td>
                                        <td class="border border-black px-2 py-1.5 text-center">
                                            {{ number_format($item->total_defect_qty) }}</td>
                                        <td class="border border-black px-2 py-1.5 text-xs font-bold uppercase">
                                            {{ $item->defect_summary ?: '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="font-bold bg-gray-100">
                                    <td colspan="3" class="border border-black px-2 py-1.5 text-right uppercase">Total
                                        {{ str_replace('_', ' ', $deptName) }}</td>
                                    <td class="border border-black px-2 py-1.5 text-center">
                                        {{ number_format($deptItems->sum('total_defect_qty')) }}</td>
                                    <td class="border border-black px-2 py-1.5"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endforeach
                <div class="text-right font-bold text-lg mt-4 border-t-2 border-double border-black pt-2">
                    GRAND TOTAL: {{ number_format($totalQty) }} pcs
                </div>
            @else
                <table class="w-full border-collapse border border-black mb-8 text-sm">
                    <thead>
                        <tr class="bg-gray-200 text-black">
                            <th class="border border-black px-2 py-1.5 text-center w-10">No</th>
                            <th class="border border-black px-2 py-1.5 text-left">Heat Number</th>
                            <th class="border border-black px-2 py-1.5 text-left">Nama Item</th>
                            <th class="border border-black px-2 py-1.5 text-center">Qty (pcs)</th>
                            <th class="border border-black px-2 py-1.5 text-left">Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($results as $index => $item)
                            <tr>
                                <td class="border border-black px-2 py-1.5 text-center">{{ $index + 1 }}</td>
                                <td class="border border-black px-2 py-1.5 font-bold">{{ $item->heat_number }}</td>
                                <td class="border border-black px-2 py-1.5">{{ $item->item_name }}</td>
                                <td class="border border-black px-2 py-1.5 text-center">
                                    {{ number_format($item->total_defect_qty) }}
                                </td>
                                <td class="border border-black px-2 py-1.5 text-xs font-bold uppercase">
                                    {{ $item->defect_summary ?: '-' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-bold bg-gray-100">
                            <td colspan="3" class="border border-black px-2 py-1.5 text-right">TOTAL</td>
                            <td class="border border-black px-2 py-1.5 text-center">{{ number_format($totalQty) }}</td>
                            <td class="border border-black px-2 py-1.5"></td>
                        </tr>
                    </tfoot>
                </table>
            @endif
        </div>

        <!-- Signatures -->
        <div class="flex justify-between mt-16 px-16">
            <div class="text-center">
                <p class="mb-20">Diterima (SPV)</p>
                <div class="border-t border-black w-40 mx-auto"></div>
            </div>
            <div class="text-center">
                <p class="mb-20">Dibuat Oleh (Admin PPIC)</p>
                <div class="border-t border-black w-40 mx-auto"></div>
                <p class="text-xs mt-1">{{ Auth::user()->name }}</p>
            </div>
        </div>
    </div>

</body>

</html>