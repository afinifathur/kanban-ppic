<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Label Keranjang Hasil Cor - Heat {{ $castingResult->heat_number }}</title>
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('css/fontawesome.all.min.css') }}">
    <script src="{{ asset('js/sweetalert2.all.min.js') }}"></script>
    @php
        $isFont1 = ($fontMode ?? 'font1') === 'font1';
    @endphp
    <style>
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        @page {
            size: 90mm 50mm;
            margin: 0;
        }

        body {
            font-family: 'Courier New', Courier, monospace, 'Liberation Mono', monospace;
            background: #f1f5f9;
            margin: 0;
            padding: 16px;
            color: #000;
        }

        /* Non-print toolbar */
        .no-print-toolbar {
            max-width: 90mm;
            margin: 0 auto 12px auto;
            background: #1e293b;
            color: #ffffff;
            padding: 8px 12px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }

        /* 90mm x 50mm Thermal Label Canvas */
        .label-page {
            width: 90mm;
            height: 50mm;
            max-width: 90mm;
            max-height: 50mm;
            background: #ffffff;
            border: 2px solid #000000;
            padding: 1.5mm;
            display: flex;
            flex-direction: column;
            margin: 0 auto 16px auto;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.15);
            position: relative;
            overflow: hidden;
        }

        /* Header: Heat Number prominent on left, HAL & TGL inline on right */
        .label-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #000000;
            padding-bottom: 1mm;
            margin-bottom: 0.8mm;
        }

        .heat-value {
            font-size: 13pt;
            font-weight: 900;
            letter-spacing: 0.5px;
            line-height: 1.1;
            font-family: 'Courier New', monospace;
        }

        .meta-inline {
            font-size: 7.5pt;
            font-weight: bold;
            line-height: 1;
            white-space: nowrap;
        }

        /* 3-Column Table with semantic HTML table layout */
        .table-container {
            width: 85mm;
            margin: 0 auto;
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: #ffffff;
        }

        .basket-table {
            width: 85mm !important;
            max-width: 85mm !important;
            margin: 0 auto !important;
            table-layout: fixed !important;
            border-collapse: collapse !important;
            border: 1.5px solid #000000 !important;
            background: #ffffff !important;
        }

        .basket-table col.col-item {
            width: 58.75mm !important;
        }

        .basket-table col.col-qty {
            width: 10.00mm !important;
        }

        .basket-table col.col-rusak {
            width: 16.25mm !important;
        }

        .basket-table thead th {
            border-bottom: 1.5px solid #000000 !important;
            font-size: 7pt !important;
            font-weight: 900 !important;
            background: #f8fafc !important;
            padding: 0.8mm 1mm !important;
            line-height: 1.2 !important;
            box-sizing: border-box !important;
        }

        .basket-table th.th-item {
            text-align: left !important;
            border-right: 1.5px solid #000000 !important;
            width: 58.75mm !important;
        }

        .basket-table th.th-qty {
            text-align: center !important;
            border-right: 1.5px solid #000000 !important;
            width: 10.00mm !important;
        }

        .basket-table th.th-rusak {
            text-align: center !important;
            width: 16.25mm !important;
        }

        .basket-table tbody td {
            box-sizing: border-box !important;
            padding: 0.3mm 0.8mm !important;
            border-bottom: 1px solid #000000 !important;
            vertical-align: middle !important;
        }

        .basket-table tbody tr:last-child td {
            border-bottom: none !important;
        }

        .basket-table td.td-item {
            text-align: left !important;
            vertical-align: middle !important;
            border-right: 1.5px solid #000000 !important;
            font-weight: bold !important;
            font-size: {{ $isFont1 ? '6.5pt' : '7pt' }} !important;
            line-height: 1.15 !important;
            overflow: hidden !important;
            word-break: break-word !important;
            width: 58.75mm !important;
        }

        .basket-table td.td-qty {
            text-align: center !important;
            vertical-align: middle !important;
            border-right: 1.5px solid #000000 !important;
            font-weight: 900 !important;
            font-size: {{ $isFont1 ? '7pt' : '8pt' }} !important;
            line-height: 1 !important;
            width: 10.00mm !important;
            padding: 0 !important;
        }

        .basket-table td.td-rusak {
            text-align: center !important;
            vertical-align: middle !important;
            background: #ffffff !important;
            width: 16.25mm !important;
        }

        .item-row.row-single {
            height: {{ $isFont1 ? '3.5mm' : '4.25mm' }};
        }

        .item-row.row-double {
            height: {{ $isFont1 ? '5.5mm' : '6.88mm' }};
        }

        .item-row.row-empty {
            height: {{ $isFont1 ? '3.5mm' : '4.25mm' }};
        }

        .item-line {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.15;
        }

        .qty-val {
            display: inline-block !important;
            text-align: center !important;
            width: 100% !important;
            line-height: 1 !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        @media print {
            body {
                background: none !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .no-print {
                display: none !important;
            }
            .label-page {
                box-shadow: none !important;
                margin: 0 !important;
                page-break-after: always;
                break-after: page;
            }
        }
    </style>
</head>
<body>

    <!-- Non-Print Toolbar -->
    <div class="no-print no-print-toolbar">
        <a href="{{ route('lost-wax.casting-results.show', $castingResult) }}"
           class="inline-flex items-center text-xs font-semibold text-slate-300 hover:text-white transition-colors">
            <i class="fas fa-arrow-left mr-1.5"></i> Kembali
        </a>

        <!-- Font Toggle (Layout B Font 1 vs Layout A Font 2) -->
        <div class="flex items-center gap-1 bg-slate-800 p-0.5 rounded text-[10px]">
            <a href="{{ route('lost-wax.casting-results.print-thermal', ['castingResult' => $castingResult, 'font' => 1]) }}"
               class="px-2 py-0.5 rounded transition-colors {{ $isFont1 ? 'bg-amber-500 text-white font-bold' : 'text-slate-400 hover:text-white' }}">
                Font 1 (Baru)
            </a>
            <a href="{{ route('lost-wax.casting-results.print-thermal', ['castingResult' => $castingResult, 'font' => 2]) }}"
               class="px-2 py-0.5 rounded transition-colors {{ !$isFont1 ? 'bg-amber-500 text-white font-bold' : 'text-slate-400 hover:text-white' }}">
                Font 2 (Lama)
            </a>
        </div>

        <div class="flex items-center gap-2">
            @if($castingResult->is_completed)
            <button type="button"
                    id="btn-queue-tsc"
                    onclick="queueDirectTsc()"
                    class="inline-flex items-center px-2.5 py-1 rounded bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold transition-colors">
                <i class="fas fa-print mr-1"></i> Cetak ke TSC
            </button>
            @endif
            <button type="button"
                    onclick="window.print()"
                    class="inline-flex items-center px-2.5 py-1 rounded bg-slate-700 hover:bg-slate-600 text-white text-xs font-bold transition-colors">
                <i class="fas fa-desktop mr-1"></i> Browser Print
            </button>
        </div>
    </div>

    <!-- Multi-label pages (Dynamic row height & wrapping) -->
    @forelse($chunks as $pageIndex => $chunk)
    <div class="label-page">
        <!-- Header Section -->
        <div class="label-header">
            <div class="heat-value">{{ $castingResult->heat_number }}</div>
            <div class="meta-inline">
                HAL {{ $pageIndex + 1 }}/{{ $totalPages }} | TGL: {{ $castingResult->cast_date ? $castingResult->cast_date->format('d/m/Y') : date('d/m/Y') }}
            </div>
        </div>

        <!-- 3-Column Semantic Table Container -->
        <div class="table-container">
            <table class="basket-table">
                <colgroup>
                    <col class="col-item" style="width: 58.75mm;">
                    <col class="col-qty" style="width: 10.00mm;">
                    <col class="col-rusak" style="width: 16.25mm;">
                </colgroup>
                <thead>
                    <tr>
                        <th class="th-item">NAMA BARANG</th>
                        <th class="th-qty">QTY</th>
                        <th class="th-rusak">RUSAK</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $usedHeight = 0;
                        $singleHeightDots = $isFont1 ? 28 : 34;
                        $doubleHeightDots = $isFont1 ? 44 : 55;
                    @endphp
                    @foreach($chunk as $itemData)
                        @php
                            $tree = $itemData['tree'];
                            $lines = $itemData['lines'];
                            $isDouble = count($lines) > 1;
                            $usedHeight += $itemData['height'] ?? ($isDouble ? $doubleHeightDots : $singleHeightDots);
                        @endphp
                        <tr class="item-row {{ $isDouble ? 'row-double' : 'row-single' }}">
                            <td class="td-item">
                                @foreach($lines as $line)
                                    <div class="item-line">{{ $line }}</div>
                                @endforeach
                            </td>
                            <td class="td-qty">
                                <span class="qty-val">{{ (int) $tree->quantity_pcs }}</span>
                            </td>
                            <td class="td-rusak">
                                {{-- DELIBERATELY EMPTY FOR OPERATOR HANDWRITING --}}
                            </td>
                        </tr>
                    @endforeach

                    @php
                        $remainingHeight = 308 - $usedHeight;
                        $fillerCount = max(0, (int) floor($remainingHeight / $singleHeightDots));
                    @endphp
                    @for($i = 0; $i < $fillerCount; $i++)
                        <tr class="item-row row-empty {{ $i === $fillerCount - 1 ? 'row-last-filler' : '' }}">
                            <td class="td-item">&nbsp;</td>
                            <td class="td-qty">&nbsp;</td>
                            <td class="td-rusak">&nbsp;</td>
                        </tr>
                    @endfor
                </tbody>
            </table>
        </div>
    </div>
    @empty
    <div class="label-page">
        <div class="label-header">
            <div class="heat-value">{{ $castingResult->heat_number }}</div>
            <div class="meta-inline">
                HAL 1/1 | TGL: {{ $castingResult->cast_date ? $castingResult->cast_date->format('d/m/Y') : date('d/m/Y') }}
            </div>
        </div>
        <div class="table-container">
            <table class="basket-table">
                <colgroup>
                    <col class="col-item" style="width: 58.75mm;">
                    <col class="col-qty" style="width: 10.00mm;">
                    <col class="col-rusak" style="width: 16.25mm;">
                </colgroup>
                <thead>
                    <tr>
                        <th class="th-item">NAMA BARANG</th>
                        <th class="th-qty">QTY</th>
                        <th class="th-rusak">RUSAK</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="item-row row-single">
                        <td class="td-item">-</td>
                        <td class="td-qty"><span class="qty-val">0</span></td>
                        <td class="td-rusak">&nbsp;</td>
                    </tr>
                    @php
                        $emptySlots = $isFont1 ? 10 : 8;
                    @endphp
                    @for($i = 0; $i < $emptySlots; $i++)
                        <tr class="item-row row-empty {{ $i === $emptySlots - 1 ? 'row-last-filler' : '' }}">
                            <td class="td-item">&nbsp;</td>
                            <td class="td-qty">&nbsp;</td>
                            <td class="td-rusak">&nbsp;</td>
                        </tr>
                    @endfor
                </tbody>
            </table>
        </div>
    </div>
    @endforelse

    <script>
        async function queueDirectTsc() {
            const btn = document.getElementById('btn-queue-tsc');
            if (!btn || btn.disabled) return;

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Mengirim...';

            try {
                const response = await fetch("{{ route('lost-wax.casting-results.queue-thermal', $castingResult) }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        font: "{{ $isFont1 ? '1' : '2' }}"
                    })
                });

                const result = await response.json();

                if (response.ok && result.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Antrean Cetak Berhasil',
                        text: result.message || 'Label berhasil dikirim ke antrean cetak printer thermal TSC TE200.',
                        timer: 2500,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Mengirim Cetakan',
                        text: result.message || 'Terjadi kesalahan saat memproses antrean cetak.',
                    });
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Kesalahan Jaringan',
                    text: 'Tidak dapat menghubungi server. Periksa koneksi Anda.',
                });
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-print mr-1"></i> Cetak ke TSC';
            }
        }
    </script>
</body>
</html>
