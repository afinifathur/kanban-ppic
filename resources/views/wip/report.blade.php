@extends('layouts.app')

@section('top_bar')
    <div>
        <h1 class="text-lg font-bold text-slate-800 leading-tight">Report WIP</h1>
        <p class="text-gray-500 text-[10px]">Laporan sisa pengerjaan dan efisiensi bahan baku</p>
    </div>
@endsection

@section('content')
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
            <form action="{{ route('wip.report') }}" method="GET" class="flex gap-2">
                <input type="date" name="date" value="{{ $date }}"
                    class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-500">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-bold">
                    <i class="fas fa-filter"></i> Filter
                </button>
            </form>
            <button onclick="window.print()" class="bg-emerald-600 text-white px-4 py-2 rounded-lg text-sm font-bold">
                <i class="fas fa-print"></i> Cetak Report
            </button>
        </div>

        <div class="p-0 overflow-x-auto">
            <table class="w-full text-sm border-collapse">
                <thead>
                    <tr class="bg-slate-100 text-slate-600 text-[10px] uppercase tracking-wider font-bold">
                        <th class="px-5 py-4 border-b border-slate-200 text-left">Heat Number</th>
                        <th class="px-5 py-4 border-b border-slate-200 text-center">Total PCS</th>
                        <th class="px-5 py-4 border-b border-slate-200 text-right">Berat Jadi (Net)</th>
                        <th class="px-5 py-4 border-b border-slate-200 text-right">Bahan Baku (Gross)</th>
                        <th class="px-5 py-4 border-b border-slate-200 text-right">Scrap (Riser)</th>
                        <th class="px-5 py-4 border-b border-slate-200 text-center">Yield (%)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($stats as $stat)
                        @php
                            $scrap = $stat->total_bruto_kg > 0 ? $stat->total_bruto_kg - $stat->total_finished_kg : 0;
                            $yield = $stat->total_bruto_kg > 0 ? ($stat->total_finished_kg / $stat->total_bruto_kg) * 100 : 0;
                        @endphp
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-4 font-bold text-slate-700">{{ $stat->heat_number ?: 'N/A' }}</td>
                            <td class="px-5 py-4 text-center text-slate-600">{{ number_format($stat->total_pcs) }}</td>
                            <td class="px-5 py-4 text-right font-medium">{{ number_format($stat->total_finished_kg, 2) }} kg
                            </td>
                            <td class="px-5 py-4 text-right font-bold text-blue-600">
                                {{ number_format($stat->total_bruto_kg, 2) }} kg</td>
                            <td class="px-5 py-4 text-right font-medium text-red-500">{{ number_format($scrap, 2) }} kg</td>
                            <td class="px-5 py-4 text-center">
                                <span
                                    class="px-2 py-1 rounded bg-{{ $yield > 70 ? 'emerald' : ($yield > 50 ? 'orange' : 'red') }}-100 text-{{ $yield > 70 ? 'emerald' : ($yield > 50 ? 'orange' : 'red') }}-700 text-[10px] font-bold">
                                    {{ number_format($yield, 1) }}%
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-12 text-center text-gray-400 italic">
                                Belum ada data untuk tanggal ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <style>
        @media print {

            .bg-slate-50\/50,
            form,
            button {
                display: none !important;
            }

            body {
                background: white !important;
            }

            .bg-white {
                border: none !important;
                shadow: none !important;
            }
        }
    </style>
@endsection