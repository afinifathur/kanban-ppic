@extends('layouts.app')

@section('top_bar')
    <div>
        <h1 class="text-lg font-bold text-slate-800 leading-tight">Generate Report Kerusakan</h1>
        <p class="text-gray-500 text-[10px]">Laporan Detail Item Rusak/Scrap</p>
    </div>
@endsection

@section('content')
    <div class="container mx-auto max-w-5xl px-0 py-0">

        <!-- Form -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-8">
            <form action="{{ route('report-defects.index') }}" method="GET" id="reportForm"
                class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                <!-- Parameters -->

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tanggal</label>
                    <input type="date" name="date" value="{{ $selectedDate }}"
                        class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Departemen</label>
                    <select name="department" onchange="this.form.submit()"
                        class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
                        <option value="">Pilih Departemen</option>
                        <option value="all" {{ $selectedDept == 'all' ? 'selected' : '' }}>Semua Departemen</option>
                        @foreach($departments as $dept)
                            <option value="{{ $dept }}" {{ $selectedDept == $dept ? 'selected' : '' }}>
                                {{ ucfirst(str_replace('_', ' ', $dept)) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Jenis Kerusakan</label>
                    <select name="defect_type_id"
                        class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
                        <option value="">Pilih Jenis</option>
                        @foreach($defectTypes as $type)
                            <option value="{{ $type->id }}" {{ $selectedDefectType == $type->id ? 'selected' : '' }}>
                                {{ $type->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Jumlah HN</label>
                    <input type="number" name="count" min="1" max="50" value="{{ $selectedCount }}"
                        class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
                </div>

                <div>
                    <button type="submit" name="generate" value="1"
                        class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg shadow transition-colors flex items-center justify-center gap-2">
                        <i class="fas fa-search"></i> Generate
                    </button>
                </div>
            </form>
        </div>

        <!-- Preview -->
        @if($results !== null)
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8">
                <div class="flex justify-between items-start mb-6">
                    <div>
                        <h2 class="text-xl font-bold text-gray-900 uppercase">LAPORAN KERUSAKAN PRODUKSI</h2>
                        <h3 class="text-lg text-gray-600">
                            DEPARTEMEN: {{ $selectedDept === 'all' ? 'SEMUA DEPARTEMEN' : strtoupper(str_replace('_', ' ', $selectedDept)) }}
                        </h3>
                        <p class="text-sm text-gray-500 mt-1">Tanggal: {{ date('d F Y', strtotime($selectedDate)) }}</p>
                        @if($selectedDept !== 'all')
                            <p class="text-red-700 font-bold mt-2 text-lg uppercase">JENIS:
                                {{ $defectType ? $defectType->name : 'SEMUA JENIS' }}
                            </p>
                        @endif
                    </div>
                    <div class="flex gap-2">
                        <a href="{{ route('report-defects.export', ['type' => 'pdf'] + request()->all()) }}" target="_blank"
                            class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg shadow transition-colors flex items-center gap-2">
                            <i class="fas fa-file-pdf"></i> Download PDF
                        </a>
                        <a href="{{ route('report-defects.export', ['type' => 'excel'] + request()->all()) }}" target="_blank"
                            class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg shadow transition-colors flex items-center gap-2">
                            <i class="fas fa-file-excel"></i> Download Excel
                        </a>
                    </div>
                </div>

                <div class="overflow-x-auto space-y-8">
                    @if($selectedDept === 'all')
                        @foreach($results as $deptName => $deptItems)
                            <div class="mb-8">
                                <h4 class="text-md font-bold text-slate-700 mb-2 uppercase border-b pb-1">
                                    DEPARTEMEN: {{ str_replace('_', ' ', $deptName) }}
                                </h4>
                                <table class="w-full border-collapse border border-gray-300 text-sm">
                                    <thead>
                                        <tr class="bg-gray-100">
                                            <th class="border border-gray-300 px-3 py-2 text-center w-12">No</th>
                                            <th class="border border-gray-300 px-3 py-2 text-left">Heat Number</th>
                                            <th class="border border-gray-300 px-3 py-2 text-left">Nama Item</th>
                                            <th class="border border-gray-300 px-3 py-2 text-center">Qty Rusak (pcs)</th>
                                            <th class="border border-gray-300 px-3 py-2 text-left">Catatan</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($deptItems as $index => $item)
                                            <tr>
                                                <td class="border border-gray-300 px-3 py-2 text-center">{{ $index + 1 }}</td>
                                                <td class="border border-gray-300 px-3 py-2 font-mono font-bold">{{ $item->heat_number }}</td>
                                                <td class="border border-gray-300 px-3 py-2">{{ $item->item_name }}</td>
                                                <td class="border border-gray-300 px-3 py-2 text-center">{{ number_format($item->total_defect_qty) }}</td>
                                                <td class="border border-gray-300 px-3 py-2 text-red-600 font-medium">{{ $item->defect_summary ?: '-' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot class="bg-gray-50 font-bold">
                                        <tr>
                                            <td colspan="3" class="border border-gray-300 px-3 py-2 text-right">TOTAL {{ strtoupper(str_replace('_', ' ', $deptName)) }}</td>
                                            <td class="border border-gray-300 px-3 py-2 text-center">{{ number_format($deptItems->sum('total_defect_qty')) }}</td>
                                            <td class="border border-gray-300 px-3 py-2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        @endforeach
                    @else
                        <table class="w-full border-collapse border border-gray-300 text-sm">
                            <thead>
                                <tr class="bg-gray-100">
                                    <th class="border border-gray-300 px-3 py-2 text-center w-12">No</th>
                                    <th class="border border-gray-300 px-3 py-2 text-left">Heat Number</th>
                                    <th class="border border-gray-300 px-3 py-2 text-left">Nama Item</th>
                                    <th class="border border-gray-300 px-3 py-2 text-center">Qty Rusak (pcs)</th>
                                    <th class="border border-gray-300 px-3 py-2 text-left">Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($results as $index => $item)
                                    <tr>
                                        <td class="border border-gray-300 px-3 py-2 text-center">{{ $index + 1 }}</td>
                                        <td class="border border-gray-300 px-3 py-2 font-mono font-bold">
                                            {{ $item->heat_number }}
                                        </td>
                                        <td class="border border-gray-300 px-3 py-2">{{ $item->item_name }}</td>
                                        <td class="border border-gray-300 px-3 py-2 text-center">
                                            {{ number_format($item->total_defect_qty) }}</td>
                                        <td class="border border-gray-300 px-3 py-2 text-red-600 font-medium">
                                            {{ $item->defect_summary ?: '-' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="border border-gray-300 px-3 py-6 text-center text-gray-500 italic">
                                            Tidak ada data tersedia untuk kriteria ini.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                            <tfoot class="bg-gray-50 font-bold">
                                <tr>
                                    <td colspan="3" class="border border-gray-300 px-3 py-2 text-right">TOTAL</td>
                                    <td class="border border-gray-300 px-3 py-2 text-center">{{ number_format($totalQty) }}</td>
                                    <td class="border border-gray-300 px-3 py-2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    @endif
                </div>

                <!-- Signatures -->
                <div class="mt-12 flex justify-end gap-16 px-12">
                    <div class="text-center">
                        <p class="mb-16">Diterima (SPV)</p>
                        <div class="border-t border-gray-800 w-32 mx-auto"></div>
                    </div>
                    <div class="text-center">
                        <p class="mb-16">Admin PPIC</p>
                        <div class="border-t border-gray-800 w-32 mx-auto"></div>
                        <p class="text-xs mt-1">{{ Auth::user()->name }}</p>
                    </div>
                </div>
            </div>
        @endif

    </div>
@endsection