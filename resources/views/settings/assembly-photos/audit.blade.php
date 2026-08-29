@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">MASTER FOTO RANGKAI — AUDIT STATUS</h1>
            <p class="text-gray-500 text-[10px]">Audit kelengkapan master foto referensi perakitan (Tampak Depan & Samping) seluruh produk</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('settings.assembly-photos.index') }}" class="px-3.5 py-2 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white font-bold text-xs rounded-lg shadow-sm transition-all flex items-center gap-2">
                <i class="fas fa-camera"></i>
                <span>Upload / Kelola Foto</span>
            </a>
        </div>
    </div>
@endsection

@section('content')
<div class="max-w-7xl mx-auto space-y-6">

    {{-- Summary Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <a href="{{ route('settings.assembly-photos.audit', array_merge(request()->query(), ['status' => 'all', 'page' => 1])) }}" class="bg-white p-4 rounded-xl border {{ $statusFilter === 'all' ? 'border-blue-500 ring-2 ring-blue-100' : 'border-slate-200' }} shadow-sm hover:border-blue-400 transition-all block">
            <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Total Master Produk</div>
            <div class="text-2xl font-black text-slate-800 mt-1">{{ number_format($counts['total'] ?? 0) }}</div>
            <div class="text-[10px] text-slate-400 mt-0.5">Semua item terdaftar</div>
        </a>

        <a href="{{ route('settings.assembly-photos.audit', array_merge(request()->query(), ['status' => 'complete', 'page' => 1])) }}" class="bg-white p-4 rounded-xl border {{ $statusFilter === 'complete' ? 'border-emerald-500 ring-2 ring-emerald-100' : 'border-slate-200' }} shadow-sm hover:border-emerald-400 transition-all block">
            <div class="text-[11px] font-bold text-emerald-700 uppercase tracking-wider flex items-center gap-1.5">
                <i class="fas fa-check-circle"></i> Sudah Lengkap
            </div>
            <div class="text-2xl font-black text-emerald-700 mt-1">{{ number_format($counts['complete'] ?? 0) }}</div>
            <div class="text-[10px] text-emerald-600 mt-0.5">Semua versi memiliki 2 foto (Depan & Samping)</div>
        </a>

        <a href="{{ route('settings.assembly-photos.audit', array_merge(request()->query(), ['status' => 'incomplete', 'page' => 1])) }}" class="bg-white p-4 rounded-xl border {{ $statusFilter === 'incomplete' ? 'border-amber-500 ring-2 ring-amber-100' : 'border-slate-200' }} shadow-sm hover:border-amber-400 transition-all block">
            <div class="text-[11px] font-bold text-amber-700 uppercase tracking-wider flex items-center gap-1.5">
                <i class="fas fa-exclamation-circle"></i> Incomplete (Belum Lengkap)
            </div>
            <div class="text-2xl font-black text-amber-700 mt-1">{{ number_format($counts['incomplete'] ?? 0) }}</div>
            <div class="text-[10px] text-amber-600 mt-0.5">Hanya memiliki salah satu foto di versi aktif</div>
        </a>

        <a href="{{ route('settings.assembly-photos.audit', array_merge(request()->query(), ['status' => 'none', 'page' => 1])) }}" class="bg-white p-4 rounded-xl border {{ $statusFilter === 'none' ? 'border-slate-500 ring-2 ring-slate-200' : 'border-slate-200' }} shadow-sm hover:border-slate-400 transition-all block">
            <div class="text-[11px] font-bold text-slate-600 uppercase tracking-wider flex items-center gap-1.5">
                <i class="fas fa-minus-circle"></i> Belum Ada Foto
            </div>
            <div class="text-2xl font-black text-slate-700 mt-1">{{ number_format($counts['none'] ?? 0) }}</div>
            <div class="text-[10px] text-slate-400 mt-0.5">0 foto terdaftar</div>
        </a>
    </div>

    {{-- Filter & Search Bar --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <form action="{{ route('settings.assembly-photos.audit') }}" method="GET" class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <input type="hidden" name="status" value="{{ $statusFilter }}">

            <div class="relative flex-1 max-w-lg">
                <input
                    type="text"
                    name="q"
                    value="{{ $search }}"
                    class="w-full pl-9 pr-8 py-2 bg-slate-50 border border-slate-300 rounded-lg text-slate-900 text-xs focus:bg-white focus:border-blue-600 focus:ring-2 focus:ring-blue-100 outline-none transition-all"
                    placeholder="Cari Kode Item, Nama Produk, atau Material AISI..."
                >
                <div class="absolute left-3 top-2.5 text-slate-400 pointer-events-none">
                    <i class="fas fa-search text-xs"></i>
                </div>
                @if($search)
                    <a href="{{ route('settings.assembly-photos.audit', ['status' => $statusFilter]) }}" class="absolute right-2.5 top-2 text-slate-400 hover:text-slate-600 text-xs p-1" title="Reset filter cari">
                        <i class="fas fa-times"></i>
                    </a>
                @endif
            </div>

            {{-- Status Filter Buttons --}}
            <div class="flex items-center gap-1 overflow-x-auto pb-1 md:pb-0">
                <a href="{{ route('settings.assembly-photos.audit', ['q' => $search, 'status' => 'all']) }}" class="px-3 py-1.5 rounded-lg text-xs font-semibold {{ $statusFilter === 'all' ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }} transition-colors whitespace-nowrap">
                    Semua
                </a>
                <a href="{{ route('settings.assembly-photos.audit', ['q' => $search, 'status' => 'complete']) }}" class="px-3 py-1.5 rounded-lg text-xs font-semibold {{ $statusFilter === 'complete' ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' }} transition-colors whitespace-nowrap">
                    Sudah Lengkap
                </a>
                <a href="{{ route('settings.assembly-photos.audit', ['q' => $search, 'status' => 'incomplete']) }}" class="px-3 py-1.5 rounded-lg text-xs font-semibold {{ $statusFilter === 'incomplete' ? 'bg-amber-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-amber-50 hover:text-amber-700' }} transition-colors whitespace-nowrap">
                    Incomplete
                </a>
                <a href="{{ route('settings.assembly-photos.audit', ['q' => $search, 'status' => 'none']) }}" class="px-3 py-1.5 rounded-lg text-xs font-semibold {{ $statusFilter === 'none' ? 'bg-slate-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }} transition-colors whitespace-nowrap">
                    Belum Ada
                </a>
            </div>
        </form>
    </div>

    {{-- Main Table --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-600 uppercase tracking-wider">
                        <th class="py-3 px-4 w-12 text-center">No</th>
                        <th class="py-3 px-4 w-52">Kode Item</th>
                        <th class="py-3 px-4">Nama Item</th>
                        <th class="py-3 px-4 w-60">Status Foto</th>
                        <th class="py-3 px-4 w-28 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-xs">
                    @php
                        $startNumber = ($items->currentPage() - 1) * $items->perPage() + 1;
                    @endphp
                    @forelse($items as $idx => $item)
                        <tr class="hover:bg-slate-50/80 transition-colors">
                            <td class="py-3 px-4 text-center font-mono text-slate-400 font-semibold">
                                {{ $startNumber + $idx }}
                            </td>
                            <td class="py-3 px-4">
                                <span class="font-mono font-bold text-blue-700 bg-blue-50 border border-blue-200 px-2 py-0.5 rounded text-[11px]">
                                    {{ $item['code'] }}
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <div class="font-bold text-slate-900 leading-snug">{{ $item['name'] }}</div>
                                @if(!empty($item['aisi']) || !empty($item['standard']))
                                    <div class="text-[10px] text-slate-400 mt-0.5">
                                        @if(!empty($item['aisi']))
                                            <span>AISI: <strong class="text-slate-600">{{ $item['aisi'] }}</strong></span>
                                        @endif
                                        @if(!empty($item['standard']))
                                            <span class="ml-1.5">&bull; Std: <strong class="text-slate-600">{{ $item['standard'] }}</strong></span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td class="py-3 px-4">
                                <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-[11px] {{ $item['status']['badge_class'] }}">
                                    @if($item['status']['status_key'] === 'complete')
                                        <i class="fas fa-check-circle text-emerald-600 text-xs"></i>
                                    @elseif($item['status']['status_key'] === 'incomplete')
                                        <i class="fas fa-exclamation-triangle text-amber-600 text-xs"></i>
                                    @else
                                        <i class="fas fa-circle-xmark text-slate-400 text-xs"></i>
                                    @endif
                                    <span>{{ $item['status']['label'] }}</span>
                                    <span class="text-[10px] opacity-75">({{ $item['status']['detail'] }})</span>
                                </div>
                            </td>
                            <td class="py-3 px-4 text-center">
                                <a
                                    href="{{ route('settings.assembly-photos.index', ['product_code' => $item['code'], 'product_name' => $item['name']]) }}"
                                    class="inline-flex items-center gap-1 px-3 py-1 bg-slate-100 hover:bg-blue-50 text-slate-700 hover:text-blue-700 border border-slate-200 hover:border-blue-300 rounded font-semibold text-[11px] transition-all"
                                    title="Kelola foto untuk produk ini"
                                >
                                    <i class="fas fa-sliders text-[10px]"></i>
                                    <span>Kelola</span>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-12 text-center text-slate-400">
                                <i class="fas fa-box-open text-3xl text-slate-300 block mb-2"></i>
                                <span class="font-medium text-xs">Tidak ada data produk yang sesuai filter.</span>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if($items->hasPages())
            <div class="p-4 border-t border-slate-200 bg-slate-50/50 flex items-center justify-between">
                <div class="text-xs text-slate-500">
                    Menampilkan <span class="font-bold">{{ $items->firstItem() ?? 0 }}</span> - <span class="font-bold">{{ $items->lastItem() ?? 0 }}</span> dari <span class="font-bold">{{ number_format($items->total()) }}</span> produk
                </div>
                <div>
                    {{ $items->links() }}
                </div>
            </div>
        @endif
    </div>

</div>
@endsection
