@extends('layouts.app')

@section('top_bar')
    <div>
        <h1 class="text-lg font-bold text-slate-800 leading-tight">Rekap Kerusakan</h1>
        <p class="text-gray-500 text-[10px]">Ringkasan Jenis Kerusakan per Periode</p>
    </div>
@endsection

@section('content')
    <div class="container mx-auto max-w-4xl px-0 py-0">

        <!-- Form -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-8">
            <form action="{{ route('report-defects.summary') }}" method="GET" id="reportForm"
                class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Dari Tanggal</label>
                    <input type="date" name="start_date" value="{{ $startDate }}"
                        class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sampai Tanggal</label>
                    <input type="date" name="end_date" value="{{ $endDate }}"
                        class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Departemen</label>
                    <select name="department"
                        class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
                        <option value="">Pilih Departemen</option>
                        @foreach($departments as $dept)
                            <option value="{{ $dept }}" {{ $selectedDept == $dept ? 'selected' : '' }}>
                                {{ ucfirst(str_replace('_', ' ', $dept)) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <button type="submit" name="generate" value="1"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg shadow transition-colors flex items-center justify-center gap-2">
                        <i class="fas fa-sync-alt"></i> Generate
                    </button>
                </div>
            </form>
        </div>

        <!-- Preview -->
        @if($results !== null)
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8">
                <div class="text-center mb-8 border-b pb-4">
                    <h2 class="text-xl font-bold text-gray-900 uppercase">REKAP KERUSAKAN PRODUKSI</h2>
                    <h3 class="text-lg text-gray-600 uppercase">DEPARTEMEN {{ str_replace('_', ' ', $selectedDept) }}</h3>
                    <p class="text-sm text-gray-500 mt-1">
                        Periode: {{ date('d F Y', strtotime($startDate)) }} - {{ date('d F Y', strtotime($endDate)) }}
                    </p>
                </div>

                <div class="max-w-2xl mx-auto">
                    <table class="w-full border-collapse border border-gray-300 text-sm">
                        <thead>
                            <tr class="bg-gray-100">
                                <th class="border border-gray-300 px-4 py-2 text-center w-16">No</th>
                                <th class="border border-gray-300 px-4 py-2 text-left">Jenis Kerusakan</th>
                                <th class="border border-gray-300 px-4 py-2 text-center w-32">Qty (pcs)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $i = 1; @endphp
                            @forelse($results as $typeId => $data)
                                <tr>
                                    <td class="border border-gray-300 px-4 py-2 text-center">{{ $i++ }}</td>
                                    <td class="border border-gray-300 px-4 py-2 font-medium uppercase">{{ $data['name'] }}</td>
                                    <td class="border border-gray-300 px-4 py-2 text-center font-bold">
                                        {{ number_format($data['qty']) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="border border-gray-300 px-4 py-6 text-center text-gray-500 italic">
                                        Tidak ada data kerusakan pada periode ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr class="font-bold border-t-2 border-gray-400">
                                <td colspan="2" class="border border-gray-300 px-4 py-2 text-right uppercase">Total Kerusakan
                                </td>
                                <td class="border border-gray-300 px-4 py-2 text-center text-red-600 text-lg">
                                    {{ number_format($totalDefects) }}
                                </td>
                            </tr>
                            <tr class="text-gray-600 italic">
                                <td colspan="2" class="border border-gray-300 px-4 py-2 text-right">Total Distribusi</td>
                                <td class="border border-gray-300 px-4 py-2 text-center">
                                    {{ number_format($totalDistribution) }}
                                </td>
                            </tr>
                            @if($totalDistribution > 0)
                                <tr class="font-bold bg-blue-50 text-blue-800">
                                    <td colspan="2" class="border border-gray-300 px-4 py-2 text-right">Persentase Kerusakan</td>
                                    <td class="border border-gray-300 px-4 py-2 text-center">
                                        {{ number_format(($totalDefects / $totalDistribution) * 100, 2) }}%
                                    </td>
                                </tr>
                            @endif
                        </tfoot>
                    </table>
                </div>

                <!-- Signatures -->
                <div class="mt-12 flex justify-end gap-16 px-12 print:mt-24">
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

            <div class="mt-4 flex justify-end no-print">
                <button onclick="window.print()"
                    class="bg-gray-800 hover:bg-black text-white px-6 py-2 rounded-lg shadow flex items-center gap-2">
                    <i class="fas fa-print"></i> Print Report
                </button>
            </div>
        @endif

    </div>
@endsection