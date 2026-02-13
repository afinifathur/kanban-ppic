@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold text-slate-800">Setting Daftar Customer</h1>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 flex flex-col h-full">
                <h3 class="text-lg font-bold text-slate-700 mb-4 pb-2 border-b border-slate-100 flex items-center gap-2">
                    <span class="bg-blue-100 text-blue-700 text-xs px-2 py-0.5 rounded uppercase">Daftar Semua
                        Customer</span>
                </h3>

                <ul class="space-y-3 flex-1 mb-4 max-h-[400px] overflow-y-auto">
                    @forelse($customers as $customer)
                        <li
                            class="flex items-center justify-between group p-2 rounded hover:bg-slate-50 transition-colors border-b border-slate-50 last:border-0">
                            <span class="text-slate-700 font-medium">{{ $customer->name }}</span>
                            <form action="{{ route('settings.customers.destroy', $customer->id) }}" method="POST"
                                onsubmit="return confirm('Hapus customer ini?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                    class="text-slate-400 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-opacity p-1">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </li>
                    @empty
                        <li class="text-slate-400 text-sm italic py-2">Belum ada daftar customer.</li>
                    @endforelse
                </ul>

                <form action="{{ route('settings.customers.store') }}" method="POST"
                    class="mt-auto pt-4 border-t border-slate-100">
                    @csrf
                    <div class="flex gap-2">
                        <input type="text" name="name" placeholder="Tambah customer baru..."
                            class="flex-1 rounded-md border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                            required>
                        <button type="submit"
                            class="bg-blue-600 hover:bg-blue-700 text-white rounded-md px-3 py-2 text-sm shadow-sm transition-colors">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection