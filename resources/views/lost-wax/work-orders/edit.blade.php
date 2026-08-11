@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Ubah Work Order</h1>
            <p class="text-gray-500 text-[10px]">{{ $workOrder->et_code }}</p>
        </div>
        <a href="{{ route('lost-wax.work-orders.show', $workOrder) }}" class="text-slate-500 hover:text-slate-700 text-xs">
            <i class="fas fa-arrow-left"></i> Detail
        </a>
    </div>
@endsection

@section('content')
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 max-w-4xl">
        @include('lost-wax.work-orders._form')
    </div>
@endsection
