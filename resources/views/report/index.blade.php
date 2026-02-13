@extends('layouts.app')

@section('top_bar')
    <div>
        <h1 class="text-lg font-bold text-slate-800 leading-tight">Generate Report</h1>
        <p class="text-gray-500 text-[10px]">Buat Surat Perintah Kerja (SPK) Produksi</p>
    </div>
@endsection

@section('content')
    <div class="container mx-auto max-w-5xl px-0 py-0">

        <!-- Form -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-8">
            <form action="{{ route('report.index') }}" method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                <!-- Parameters -->
                <input type="hidden" name="generate" value="1">

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tanggal</label>
                    <input type="date" name="date" value="{{ $selectedDate }}"
                        class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Departemen</label>
                    <select name="department"
                        class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
                        @foreach($departments as $dept)
                            <option value="{{ $dept }}" {{ $selectedDept == $dept ? 'selected' : '' }}>
                                {{ ucfirst(str_replace('_', ' ', $dept)) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Line</label>
                    <select name="line" class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
                        @foreach([1, 2, 3, 4] as $l)
                            <option value="{{ $l }}" {{ $selectedLine == $l ? 'selected' : '' }}>Line {{ $l }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Jumlah HN</label>
                    <input type="number" name="count" min="1" max="20" value="{{ $selectedCount }}"
                        class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
                </div>

                <div>
                    <button type="submit"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg shadow transition-colors flex items-center justify-center gap-2">
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
                        <h2 class="text-xl font-bold text-gray-900">SURAT PERINTAH KERJA PRODUKSI</h2>
                        <h3 class="text-lg text-gray-600">DEPARTEMEN {{ strtoupper(str_replace('_', ' ', $selectedDept)) }}</h3>
                        <p class="text-sm text-gray-500 mt-1">Tanggal: {{ date('d F Y', strtotime($selectedDate)) }}</p>
                        <p class="text-gray-700 font-bold mt-2">LINE {{ $selectedLine }}</p>
                    </div>
                    <div class="flex gap-2">
                        <a href="{{ route('report.export', ['type' => 'pdf'] + request()->all()) }}" target="_blank"
                            class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg shadow transition-colors flex items-center gap-2">
                            <i class="fas fa-file-pdf"></i> Download PDF
                        </a>
                        <a href="{{ route('report.export', ['type' => 'excel'] + request()->all()) }}" target="_blank"
                            class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg shadow transition-colors flex items-center gap-2">
                            <i class="fas fa-file-excel"></i> Download Excel
                        </a>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full border-collapse border border-gray-300 text-sm">
                        <thead>
                            <tr class="bg-gray-100">
                                <th class="border border-gray-300 px-3 py-2 text-center w-12">No</th>
                                <th class="border border-gray-300 px-3 py-2 text-left">Heat Number</th>
                                <th class="border border-gray-300 px-3 py-2 text-left">Nama Item</th>
                                <th class="border border-gray-300 px-3 py-2 text-center">Jumlah (pcs)</th>
                                <th class="border border-gray-300 px-3 py-2 text-center">Berat (kg)</th>
                                <th class="border border-gray-300 px-3 py-2 text-center">Antri (Hari)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($results as $index => $item)
                                <tr>
                                    <td class="border border-gray-300 px-3 py-2 text-center">{{ $index + 1 }}</td>
                                    <td class="border border-gray-300 px-3 py-2 font-mono font-bold">{{ $item->heat_number }}</td>
                                    <td class="border border-gray-300 px-3 py-2">{{ $item->item_name }}</td>
                                    <td class="border border-gray-300 px-3 py-2 text-center">{{ number_format($item->qty_pcs) }}
                                    </td>
                                    <td class="border border-gray-300 px-3 py-2 text-center">
                                        {{ number_format($item->weight_kg, 1) }}</td>
                                    <td
                                        class="border border-gray-300 px-3 py-2 text-center {{ $item->aging_days > 14 ? 'text-red-500 font-bold' : '' }}">
                                        {{ number_format($item->aging_days, 1) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="border border-gray-300 px-3 py-6 text-center text-gray-500 italic">
                                        Tidak ada data tersedia untuk kriteria ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot class="bg-gray-50 font-bold">
                            <tr>
                                <td colspan="3" class="border border-gray-300 px-3 py-2 text-right">TOTAL</td>
                                <td class="border border-gray-300 px-3 py-2 text-center">{{ number_format($totalPcs) }}</td>
                                <td class="border border-gray-300 px-3 py-2 text-center">{{ number_format($totalKg, 1) }}</td>
                                <td class="border border-gray-300 px-3 py-2"></td>
                            </tr>
                        </tfoot>
                    </table>
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