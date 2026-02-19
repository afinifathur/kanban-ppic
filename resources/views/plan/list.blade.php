@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-gray-800 leading-tight">Detail Rencana Produksi</h1>
            <p class="text-gray-500 text-[10px]">{{ \Carbon\Carbon::parse($date)->isoFormat('dddd, D MMMM Y') }}</p>
        </div>
        <a href="{{ route('plan.index') }}" class="text-blue-600 hover:underline text-xs flex items-center gap-1">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
    </div>
@endsection

@section('content')
    <div class="bg-white shadow-md rounded-lg p-6 h-full flex flex-col">
        <div class="flex-1 overflow-auto">
            <table class="min-w-full border-collapse border border-gray-200 text-sm">
                <thead class="bg-gray-100 sticky top-0">
                    <tr>
                        <th class="border border-gray-200 px-3 py-2 text-center w-10">No</th>
                        <th class="border border-gray-200 px-3 py-2 text-left">Line</th>
                        <th class="border border-gray-200 px-3 py-2 text-left">Customer</th>
                        <th class="border border-gray-200 px-3 py-2 text-left">P.O. Number</th>
                        <th class="border border-gray-200 px-3 py-2 text-left">Item Name</th>
                        <th class="border border-gray-200 px-3 py-2 text-center">Planned</th>
                        <th class="border border-gray-200 px-3 py-2 text-center">Remaining</th>
                        <th class="border border-gray-200 px-3 py-2 text-center">Status</th>
                        <th class="border border-gray-200 px-3 py-2 text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($plans as $index => $plan)
                        <tr class="hover:bg-gray-50 text-[12px]">
                            <td class="border border-gray-200 px-3 py-2 text-center text-gray-400">
                                {{ $index + 1 }}</td>
                            <td class="border border-gray-200 px-3 py-2 font-bold text-blue-600 text-center">
                                {{ $plan->line_number }}</td>
                            <td class="border border-gray-200 px-3 py-2 uppercase font-semibold text-slate-700">
                                {{ $plan->customer ?: '-' }}
                            </td>
                            <td class="border border-gray-200 px-3 py-2 font-mono">{{ $plan->po_number }}</td>
                            <td class="border border-gray-200 px-3 py-2">
                                <div class="font-bold">{{ $plan->item_name }}</div>
                                <div class="text-[10px] text-gray-500">{{ $plan->item_code }} | {{ $plan->aisi }} |
                                    {{ $plan->size }}</div>
                            </td>
                            <td class="border border-gray-200 px-3 py-2 text-center font-bold">
                                {{ number_format($plan->qty_planned) }}</td>
                            <td class="border border-gray-200 px-3 py-2 text-center font-bold text-orange-600">
                                {{ number_format($plan->qty_remaining) }}</td>
                            <td class="border border-gray-200 px-3 py-2 text-center">
                                <span
                                    class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase 
                                    {{ $plan->status == 'planning' ? 'bg-gray-100 text-gray-600' : ($plan->status == 'active' ? 'bg-blue-100 text-blue-600' : 'bg-green-100 text-green-600') }}">
                                    {{ $plan->status }}
                                </span>
                            </td>
                            <td class="border border-gray-200 px-3 py-2 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <a href="{{ route('plan.edit', $plan->id) }}" class="text-blue-500 hover:text-blue-700" title="Edit Rencana">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="{{ route('plan.destroy', $plan->id) }}" method="POST"
                                        onsubmit="return confirm('Apakah yakin ingin data {{ $plan->item_name }} dihapus?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-500 hover:text-red-700" title="Hapus Rencana">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="border border-gray-200 px-3 py-8 text-center text-gray-400 italic">Belum ada
                                data rencana.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection