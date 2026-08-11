@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Tambah Work Order</h1>
            <p class="text-gray-500 text-[10px]">Referensi Work Order baru untuk satu item PO</p>
        </div>
        <a href="{{ route('lost-wax.work-orders.index') }}" class="text-slate-500 hover:text-slate-700 text-xs">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
    </div>
@endsection

@section('content')
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 max-w-4xl">
        @if($itemOptions->isEmpty())
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 text-amber-800 p-4 text-sm">
                Master item belum terhubung. Setel koneksi read-only MasterDataKPI agar daftar item muncul.
            </div>
        @endif

        @include('lost-wax.work-orders._form')
    </div>
@endsection
