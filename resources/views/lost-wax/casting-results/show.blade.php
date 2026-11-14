@extends('layouts.app')

@section('title', 'Detail Bukti Hasil Cor — ' . $castingResult->heat_number)

@section('content')
<div class="space-y-5">
    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-3 border-b border-slate-200">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('lost-wax.casting-results.index') }}" class="text-xs font-semibold text-slate-500 hover:text-slate-800 transition-colors">
                    <i class="fas fa-arrow-left mr-1"></i> Riwayat Hasil Cor
                </a>
                <span class="text-slate-300">•</span>
                <span class="text-slate-500 text-xs font-mono font-medium">{{ $castingResult->transaction_number }}</span>
            </div>
            <div class="flex items-center gap-3 mt-1.5">
                <h1 class="text-2xl font-black text-slate-900 tracking-tight font-mono">
                    Heat {{ $castingResult->heat_number }}
                </h1>
                @if($castingResult->is_completed)
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                        <i class="fas fa-check-circle mr-1.5 text-xs text-emerald-600"></i> SELESAI
                    </span>
                @else
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200">
                        <i class="fas fa-ban mr-1.5 text-xs text-rose-600"></i> TRANSAKSI DIBATALKAN
                    </span>
                @endif
            </div>
        </div>

        <div class="flex items-center flex-wrap gap-2.5">
            <a href="{{ route('lost-wax.casting-results.print', $castingResult) }}"
               target="_blank"
               class="inline-flex items-center px-3.5 py-2 rounded-lg text-xs font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-300 transition-colors shadow-sm">
                <i class="fas fa-file-alt mr-1.5 text-slate-500"></i> Cetak Bukti A4
            </a>

            <a href="{{ route('lost-wax.casting-results.print-thermal', $castingResult) }}"
               target="_blank"
               class="inline-flex items-center px-3.5 py-2 rounded-lg text-xs font-bold bg-white hover:bg-amber-50 text-amber-700 border border-amber-300 transition-colors shadow-sm">
                <i class="fas fa-eye mr-1.5 text-amber-500"></i> Preview Thermal
            </a>

            @if($castingResult->is_completed)
            <button type="button"
                    id="btn-queue-thermal"
                    onclick="queueThermalPrintJob()"
                    class="inline-flex items-center px-4 py-2 rounded-lg text-xs font-bold bg-amber-500 hover:bg-amber-600 text-white transition-colors shadow-sm">
                <i class="fas fa-print mr-2"></i> Cetak Label Keranjang (TSC)
            </button>

            <button type="button"
                    onclick="openCancelModal()"
                    class="inline-flex items-center px-3.5 py-2 rounded-lg text-xs font-bold bg-white hover:bg-rose-50 text-rose-600 border border-rose-200 transition-colors shadow-sm">
                <i class="fas fa-ban mr-1.5 text-rose-500"></i> Batalkan Transaksi
            </button>
            @endif
        </div>
    </div>

    @if(session('success'))
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-xl flex items-center gap-3 shadow-sm">
        <i class="fas fa-check-circle text-emerald-500 text-base shrink-0"></i>
        <div class="text-xs font-medium leading-relaxed">{{ session('success') }}</div>
    </div>
    @endif

    @if($errors->has('cancel_error'))
    <div class="bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 rounded-xl flex items-center gap-3 shadow-sm">
        <i class="fas fa-exclamation-circle text-rose-500 text-base shrink-0"></i>
        <div class="text-xs font-medium leading-relaxed">{{ $errors->first('cancel_error') }}</div>
    </div>
    @endif

    <!-- Cancelled Warning Banner -->
    @if($castingResult->is_cancelled)
    <div class="bg-rose-50 border border-rose-200 rounded-xl p-4 text-rose-900 shadow-sm">
        <div class="flex items-start gap-3">
            <span class="w-8 h-8 rounded-lg bg-rose-100 text-rose-600 flex items-center justify-center shrink-0 text-sm mt-0.5">
                <i class="fas fa-exclamation-triangle"></i>
            </span>
            <div class="space-y-1 text-xs">
                <div class="font-bold text-rose-800 text-sm">Transaksi Hasil Cor Ini Telah Dibatalkan (Void)</div>
                <p class="text-rose-700">
                    Dibatalkan oleh <strong class="text-slate-900">{{ $castingResult->voider?->name ?? 'Admin' }}</strong>
                    pada {{ $castingResult->voided_at ? $castingResult->voided_at->format('d/m/Y H:i:s') : '-' }}.
                </p>
                <div class="bg-white rounded-lg p-3 border border-rose-200 mt-2 font-mono text-slate-800">
                    <span class="text-slate-400 text-[10px] uppercase font-bold block mb-0.5">Alasan Pembatalan:</span>
                    "{{ $castingResult->void_reason }}"
                </div>
                <p class="text-[11px] text-slate-500 italic mt-1">
                    * Seluruh Traveler Card dalam transaksi ini telah dikembalikan ke antrean Barang Siap Cor dan dapat dicor kembali pada Heat Number lain.
                </p>
            </div>
        </div>
    </div>
    @endif

    <!-- Transaction Header Details -->
    <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
            <div>
                <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider block">No. Transaksi</span>
                <span class="text-xs font-mono font-bold text-slate-900 mt-0.5 block">{{ $castingResult->transaction_number }}</span>
            </div>
            <div>
                <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider block">Tanggal Cor</span>
                <span class="text-xs font-bold text-slate-900 mt-0.5 block">
                    {{ $castingResult->cast_date ? $castingResult->cast_date->format('d/m/Y') : '-' }}
                </span>
            </div>
            <div>
                <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider block">Tungku / Shift</span>
                <span class="text-xs font-medium text-slate-700 mt-0.5 block">
                    {{ $castingResult->furnace ? 'Tungku ' . $castingResult->furnace : '-' }}
                    @if($castingResult->shift) / Shift {{ $castingResult->shift }} @endif
                </span>
            </div>
            <div>
                <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider block">Operator Penuangan</span>
                <span class="text-xs font-medium text-slate-700 mt-0.5 block">
                    {{ $castingResult->operator_name ?: '-' }}
                </span>
            </div>
            <div>
                <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider block">Total Pohon</span>
                <span class="text-sm font-black text-slate-900 mt-0.5 block">
                    {{ number_format($castingResult->total_trees) }} <span class="text-xs text-slate-500 font-normal">Pohon</span>
                </span>
            </div>
            <div>
                <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider block">Total Kuantitas</span>
                <span class="text-sm font-black text-amber-600 mt-0.5 block">
                    {{ number_format($castingResult->total_pcs) }} <span class="text-xs text-slate-500 font-normal">PCS</span>
                </span>
            </div>

            @if($castingResult->notes)
            <div class="col-span-2 md:col-span-4 lg:col-span-6 pt-3 border-t border-slate-100">
                <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider block">Catatan</span>
                <p class="text-xs text-slate-700 mt-0.5">{{ $castingResult->notes }}</p>
            </div>
            @endif

            <div class="col-span-2 md:col-span-4 lg:col-span-6 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-400">
                <div>
                    Dibuat oleh: <strong class="text-slate-700">{{ $castingResult->recorder?->name ?? 'Admin' }}</strong>
                    pada {{ $castingResult->created_at ? $castingResult->created_at->format('d/m/Y H:i:s') : '-' }}
                </div>
                <div>
                    Status Konsumsi WIP:
                    @if($castingResult->is_completed)
                        <span class="text-emerald-600 font-bold">Telah Dikonsumsi dari Barang Siap Cor</span>
                    @else
                        <span class="text-slate-500">Dikembalikan ke Antrean Siap Cor</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Detail Traveler Cards Table -->
    <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
        <div class="p-4 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
            <h2 class="text-xs font-bold text-slate-900 uppercase tracking-wider flex items-center gap-2">
                <i class="fas fa-tree text-amber-500"></i>
                Rincian Traveler Card Terkait ({{ $castingResult->trees->count() }} Pohon)
            </h2>
            <span class="text-xs font-bold text-slate-900">
                Total: <span class="text-amber-600">{{ number_format($castingResult->total_pcs) }}</span> PCS
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs border-collapse">
                <thead class="bg-slate-50 text-slate-600 uppercase text-[10px] font-bold tracking-wider border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-3 text-center w-12">No</th>
                        <th class="px-4 py-3">Traveler Card / Barcode</th>
                        <th class="px-4 py-3">Kode Produksi</th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Nama Item</th>
                        <th class="px-4 py-3 text-right">Gross (PCS)</th>
                        <th class="px-4 py-3 text-right">Defect Pre-Cor</th>
                        <th class="px-4 py-3 text-right">Qty Siap Cor</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    @foreach($castingResult->trees as $i => $detail)
                    <tr class="hover:bg-slate-50/80 transition-colors">
                        <td class="px-4 py-3 text-center text-slate-400 font-medium">{{ $i + 1 }}</td>
                        <td class="px-4 py-3 font-mono font-bold text-amber-600">
                            {{ $detail->barcode }}
                        </td>
                        <td class="px-4 py-3 font-mono font-bold text-slate-900">
                            {{ $detail->production_code }}
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            {{ $detail->customer ?: '-' }}
                        </td>
                        <td class="px-4 py-3 text-slate-700">
                            {{ $detail->item_name ?: '-' }}
                        </td>
                        <td class="px-4 py-3 text-right text-slate-500 font-mono">
                            {{ number_format($detail->gross_pcs) }}
                        </td>
                        <td class="px-4 py-3 text-right font-mono {{ $detail->defect_pcs > 0 ? 'text-rose-600 font-bold' : 'text-slate-400' }}">
                            {{ number_format($detail->defect_pcs) }}
                        </td>
                        <td class="px-4 py-3 text-right font-bold text-slate-900 text-sm whitespace-nowrap">
                            {{ number_format($detail->quantity_pcs) }} <span class="text-[10px] text-slate-400 font-normal">PCS</span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-slate-50 text-slate-900 font-bold border-t-2 border-slate-200">
                    <tr>
                        <td colspan="5" class="px-4 py-3 uppercase tracking-wider text-right text-slate-500">
                            Total Kuantitas:
                        </td>
                        <td class="px-4 py-3 text-right text-slate-600 font-mono">
                            {{ number_format($castingResult->trees->sum('gross_pcs')) }}
                        </td>
                        <td class="px-4 py-3 text-right text-rose-600 font-mono">
                            {{ number_format($castingResult->trees->sum('defect_pcs')) }}
                        </td>
                        <td class="px-4 py-3 text-right text-amber-600 text-base">
                            {{ number_format($castingResult->total_pcs) }} PCS
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Modal Dialog Pembatalan Transaksi -->
@if($castingResult->is_completed)
<div id="cancelModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white border border-slate-200 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex items-center gap-3 pb-3 border-b border-slate-100">
            <span class="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center text-lg shrink-0">
                <i class="fas fa-ban"></i>
            </span>
            <div>
                <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wider">Batalkan Transaksi Hasil Cor</h3>
                <p class="text-xs text-slate-500">Heat Number: <strong class="text-slate-800">{{ $castingResult->heat_number }}</strong></p>
            </div>
        </div>

        <form method="POST" action="{{ route('lost-wax.casting-results.cancel', $castingResult) }}" class="space-y-4">
            @csrf

            <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-amber-800 text-xs leading-relaxed">
                <i class="fas fa-info-circle mr-1 text-amber-600"></i>
                Pembatalan akan mengembalikan <strong>{{ $castingResult->total_trees }} Traveler Card</strong> ({{ number_format($castingResult->total_pcs) }} PCS) kembali ke antrean <strong>Barang Siap Cor</strong>.
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1.5">
                    Alasan Pembatalan <span class="text-rose-500">*</span>
                </label>
                <textarea name="reason"
                          rows="3"
                          required
                          minlength="3"
                          placeholder="Masukkan alasan pembatalan (contoh: salah input nomor heat, gagal tuang, dsb)..."
                          class="w-full bg-white border border-slate-300 rounded-lg p-2.5 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-rose-500 focus:ring-1 focus:ring-rose-500 transition-colors"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2.5 pt-2">
                <button type="button"
                        onclick="closeCancelModal()"
                        class="px-4 py-2 rounded-lg text-xs font-semibold text-slate-600 hover:text-slate-800 bg-slate-100 hover:bg-slate-200 transition-colors">
                    Tutup
                </button>
                <button type="submit"
                        class="px-4 py-2 rounded-lg text-xs font-bold bg-rose-600 hover:bg-rose-700 text-white transition-colors shadow-sm">
                    <i class="fas fa-trash-alt mr-1.5"></i> Konfirmasi Pembatalan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openCancelModal() {
    document.getElementById('cancelModal').classList.remove('hidden');
}
function closeCancelModal() {
    document.getElementById('cancelModal').classList.add('hidden');
}

async function queueThermalPrintJob() {
    const btn = document.getElementById('btn-queue-thermal');
    if (!btn || btn.disabled) return;

    btn.disabled = true;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Mengirim ke Printer...';

    try {
        const response = await fetch("{{ route('lost-wax.casting-results.queue-thermal', $castingResult) }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': "{{ csrf_token() }}"
            }
        });

        const data = await response.json();

        if (response.ok && data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Antrean Cetak Berhasil',
                text: data.message,
                confirmButtonColor: '#f59e0b'
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Gagal Mengantrekan Cetak',
                text: data.message || 'Terjadi kesalahan sistem saat mengirim ke printer thermal.'
            });
        }
    } catch (err) {
        Swal.fire({
            icon: 'error',
            title: 'Koneksi Terputus',
            text: 'Gagal menghubungi server: ' + err.message
        });
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}
</script>
@endif
@endsection
