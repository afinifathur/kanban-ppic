@extends('layouts.app')

@section('top_bar')
    <div class="flex flex-col sm:flex-row sm:items-center justify-between w-full gap-2">
        <div>
            <h1 class="text-base sm:text-lg font-bold text-slate-800 leading-tight">MASTER FOTO RANGKAI</h1>
            <p class="text-gray-500 text-[10px]">Kelola foto referensi visual perakitan (tampak depan & tampak samping) per produk</p>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <a href="{{ route('settings.assembly-photos.audit') }}" class="w-full sm:w-auto px-3.5 py-2 bg-slate-800 hover:bg-slate-900 active:bg-slate-950 text-white font-bold text-xs rounded-lg shadow-sm transition-all flex items-center justify-center gap-2">
                <i class="fas fa-list-check text-blue-400"></i>
                <span>Daftar Master Foto</span>
            </a>
        </div>
    </div>
@endsection

@section('content')
<div class="max-w-6xl mx-auto space-y-4 sm:space-y-6">

    {{-- Product Selector & Autocomplete --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-3.5 sm:p-5 md:p-6">
        <label for="productSearchInput" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">
            <i class="fas fa-search mr-1 text-blue-600"></i> Cari & Pilih Produk (Kode / Nama Produk)
        </label>
        
        <div class="relative">
            <div class="relative flex items-center">
                <input
                    type="text"
                    id="productSearchInput"
                    class="w-full pl-9 sm:pl-10 pr-9 sm:pr-10 py-2.5 sm:py-3 bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:bg-white focus:border-blue-600 focus:ring-2 focus:ring-blue-100 outline-none text-xs sm:text-sm transition-all"
                    placeholder="Ketik Kode Produk (cth: 268ETB733) atau Nama Produk..."
                    value="{{ $selectedCode ? ($selectedCode . ($selectedName ? ' — ' . $selectedName : '')) : '' }}"
                    autocomplete="off"
                >
                <div class="absolute left-3 sm:left-3.5 text-slate-400 pointer-events-none text-xs sm:text-sm">
                    <i class="fas fa-barcode"></i>
                </div>
                <button
                    type="button"
                    id="clearSearchBtn"
                    class="absolute right-3 text-slate-400 hover:text-slate-600 p-1 {{ $selectedCode ? '' : 'hidden' }}"
                    title="Bersihkan Pencarian"
                >
                    <i class="fas fa-times text-xs"></i>
                </button>
            </div>

            {{-- Autocomplete Dropdown --}}
            <div
                id="searchDropdown"
                class="absolute left-0 right-0 top-full mt-1 bg-white border border-slate-200 rounded-xl shadow-xl z-50 max-h-72 overflow-y-auto hidden"
            >
                <div id="searchResultsList" class="divide-y divide-slate-100"></div>
            </div>
        </div>

        <p class="text-[10px] sm:text-[11px] text-slate-400 mt-2 flex items-center gap-1">
            <i class="fas fa-info-circle text-blue-500"></i>
            <span>Pilih produk untuk melihat foto aktif saat ini dan mengunggah versi baru.</span>
        </p>
    </div>

    {{-- Main Workspace (Visible when a product is selected) --}}
    <div id="productWorkspace" class="{{ $selectedCode ? '' : 'hidden' }} space-y-4 sm:space-y-6">

        {{-- Product Banner --}}
        <div class="bg-gradient-to-r from-slate-900 to-slate-800 text-white rounded-xl p-4 sm:p-5 shadow-sm border border-slate-700 flex flex-col sm:flex-row sm:items-center justify-between gap-3 sm:gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2 mb-1">
                    <span class="px-2.5 py-0.5 rounded bg-blue-600/30 text-blue-300 border border-blue-500/40 text-xs font-mono font-bold tracking-wider" id="bannerProductCode">
                        {{ $selectedCode ?? '-' }}
                    </span>
                    <span class="text-[10px] sm:text-[11px] uppercase tracking-wider text-slate-400 font-semibold" id="bannerProductSpec">
                        {{ $currentPhoto ? 'Versi ' . $currentPhoto->version . ' Aktif' : 'Belum Ada Foto' }}
                    </span>
                </div>
                <h2 class="text-base sm:text-xl font-bold text-white tracking-tight" id="bannerProductName">
                    {{ $selectedName ?? ($currentPhoto?->product_name ?? 'Produk Terpilih') }}
                </h2>
            </div>

            <div class="self-start sm:self-auto sm:text-right">
                <span class="text-[10px] sm:text-xs text-slate-400 block mb-0.5">Status Foto Master</span>
                <span id="bannerPhotoBadge" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold {{ $currentPhoto ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : 'bg-amber-500/20 text-amber-300 border border-amber-500/30' }}">
                    <i class="fas {{ $currentPhoto ? 'fa-check-circle text-emerald-400' : 'fa-exclamation-triangle text-amber-400' }}"></i>
                    <span id="bannerPhotoStatusText">{{ $currentPhoto ? 'FOTO TERSEDIA (V' . $currentPhoto->version . ')' : 'FOTO BELUM TERSEDIA' }}</span>
                </span>
            </div>
        </div>

        {{-- Upload & Edit Section --}}
        @if(!auth()->user()->hasRole('admin_qc_fitting'))
        <form
            id="photoUploadForm"
            action="{{ route('settings.assembly-photos.store') }}"
            method="POST"
            enctype="multipart/form-data"
            class="bg-white rounded-xl shadow-sm border border-slate-200 p-3.5 sm:p-5 md:p-6 space-y-4 sm:space-y-6"
        >
            @csrf
            <input type="hidden" name="product_code" id="formProductCode" value="{{ $selectedCode }}">
            <input type="hidden" name="product_name" id="formProductName" value="{{ $selectedName ?? ($currentPhoto?->product_name ?? '') }}">

            <div class="border-b border-slate-100 pb-3">
                <h3 class="text-xs sm:text-sm font-bold text-slate-800 uppercase tracking-wider">Unggah / Perbarui Foto Referensi</h3>
                <p class="text-[11px] sm:text-xs text-slate-500 mt-0.5">Foto akan otomatis dikompres dan disimpan sebagai versi baru tanpa menghapus riwayat sebelumnya.</p>
            </div>

            {{-- 2-Column Photo Upload Grid --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">

                {{-- 1. Tampak Depan --}}
                <div class="border-2 border-slate-200 rounded-xl p-3.5 sm:p-4 bg-slate-50/50 flex flex-col justify-between space-y-3 sm:space-y-4 hover:border-slate-300 transition-all">
                    <div>
                        <div class="flex items-center justify-between mb-2 sm:mb-3">
                            <span class="text-xs font-bold text-slate-800 uppercase tracking-wider flex items-center gap-1.5">
                                <i class="fas fa-camera text-blue-600"></i> Tampak Depan (Front)
                            </span>
                            <span class="text-[10px] text-slate-500 font-medium" id="frontStatusTag">
                                {{ $currentPhoto?->front_image_path ? 'Tersedia di V'.$currentPhoto->version : 'Belum Ada' }}
                            </span>
                        </div>

                        {{-- Preview Box --}}
                        <div class="h-48 sm:h-56 md:h-64 rounded-lg bg-white border border-slate-200 overflow-hidden flex items-center justify-center p-2 relative group" id="frontPreviewContainer">
                            <img
                                id="frontImagePreview"
                                src="{{ $currentPhoto?->front_image_url ?? '' }}"
                                alt="Foto Depan"
                                class="max-h-full max-w-full object-contain rounded {{ $currentPhoto?->front_image_path ? '' : 'hidden' }}"
                            >
                            <div id="frontPlaceholder" class="flex flex-col items-center justify-center text-center p-4 {{ $currentPhoto?->front_image_path ? 'hidden' : '' }}">
                                <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-full bg-slate-100 border border-dashed border-slate-300 flex items-center justify-center text-slate-400 mb-2">
                                    <i class="fas fa-image text-xl sm:text-2xl"></i>
                                </div>
                                <span class="text-xs font-semibold text-slate-500">Foto Depan Belum Tersedia</span>
                                <span class="text-[10px] sm:text-[11px] text-slate-400 mt-0.5">Ambil dari kamera atau pilih file</span>
                            </div>
                        </div>
                    </div>

                    {{-- Action Buttons (Camera & Gallery) --}}
                    <div class="space-y-2">
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                type="button"
                                onclick="document.getElementById('frontCameraInput').click()"
                                class="py-2.5 px-2 rounded-lg bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white text-xs font-bold shadow-sm transition-all flex items-center justify-center gap-1.5 cursor-pointer text-center"
                                title="Buka Kamera HP Langsung"
                            >
                                <i class="fas fa-camera text-xs"></i>
                                <span>Ambil Foto</span>
                            </button>
                            <button
                                type="button"
                                onclick="document.getElementById('frontGalleryInput').click()"
                                class="py-2.5 px-2 rounded-lg bg-white hover:bg-slate-100 active:bg-slate-200 border border-slate-300 text-slate-700 text-xs font-bold shadow-sm transition-all flex items-center justify-center gap-1.5 cursor-pointer text-center"
                                title="Pilih File dari Galeri"
                            >
                                <i class="fas fa-images text-slate-500 text-xs"></i>
                                <span>Pilih Galeri</span>
                            </button>
                        </div>

                        {{-- Hidden File Inputs --}}
                        <input
                            type="file"
                            id="frontCameraInput"
                            accept="image/*"
                            capture="environment"
                            class="hidden"
                        >
                        <input
                            type="file"
                            id="frontGalleryInput"
                            accept="image/jpeg,image/png,image/webp,image/*"
                            class="hidden"
                        >
                        <input
                            type="file"
                            name="front_photo"
                            id="frontPhotoInput"
                            accept="image/jpeg,image/png,image/webp,image/*"
                            class="hidden"
                        >
                    </div>
                </div>

                {{-- 2. Tampak Samping --}}
                <div class="border-2 border-slate-200 rounded-xl p-3.5 sm:p-4 bg-slate-50/50 flex flex-col justify-between space-y-3 sm:space-y-4 hover:border-slate-300 transition-all">
                    <div>
                        <div class="flex items-center justify-between mb-2 sm:mb-3">
                            <span class="text-xs font-bold text-slate-800 uppercase tracking-wider flex items-center gap-1.5">
                                <i class="fas fa-camera text-blue-600"></i> Tampak Samping (Side)
                            </span>
                            <span class="text-[10px] text-slate-500 font-medium" id="sideStatusTag">
                                {{ $currentPhoto?->side_image_path ? 'Tersedia di V'.$currentPhoto->version : 'Belum Ada' }}
                            </span>
                        </div>

                        {{-- Preview Box --}}
                        <div class="h-48 sm:h-56 md:h-64 rounded-lg bg-white border border-slate-200 overflow-hidden flex items-center justify-center p-2 relative group" id="sidePreviewContainer">
                            <img
                                id="sideImagePreview"
                                src="{{ $currentPhoto?->side_image_url ?? '' }}"
                                alt="Foto Samping"
                                class="max-h-full max-w-full object-contain rounded {{ $currentPhoto?->side_image_path ? '' : 'hidden' }}"
                            >
                            <div id="sidePlaceholder" class="flex flex-col items-center justify-center text-center p-4 {{ $currentPhoto?->side_image_path ? 'hidden' : '' }}">
                                <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-full bg-slate-100 border border-dashed border-slate-300 flex items-center justify-center text-slate-400 mb-2">
                                    <i class="fas fa-image text-xl sm:text-2xl"></i>
                                </div>
                                <span class="text-xs font-semibold text-slate-500">Foto Samping Belum Tersedia</span>
                                <span class="text-[10px] sm:text-[11px] text-slate-400 mt-0.5">Ambil dari kamera atau pilih file</span>
                            </div>
                        </div>
                    </div>

                    {{-- Action Buttons (Camera & Gallery) --}}
                    <div class="space-y-2">
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                type="button"
                                onclick="document.getElementById('sideCameraInput').click()"
                                class="py-2.5 px-2 rounded-lg bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white text-xs font-bold shadow-sm transition-all flex items-center justify-center gap-1.5 cursor-pointer text-center"
                                title="Buka Kamera HP Langsung"
                            >
                                <i class="fas fa-camera text-xs"></i>
                                <span>Ambil Foto</span>
                            </button>
                            <button
                                type="button"
                                onclick="document.getElementById('sideGalleryInput').click()"
                                class="py-2.5 px-2 rounded-lg bg-white hover:bg-slate-100 active:bg-slate-200 border border-slate-300 text-slate-700 text-xs font-bold shadow-sm transition-all flex items-center justify-center gap-1.5 cursor-pointer text-center"
                                title="Pilih File dari Galeri"
                            >
                                <i class="fas fa-images text-slate-500 text-xs"></i>
                                <span>Pilih Galeri</span>
                            </button>
                        </div>

                        {{-- Hidden File Inputs --}}
                        <input
                            type="file"
                            id="sideCameraInput"
                            accept="image/*"
                            capture="environment"
                            class="hidden"
                        >
                        <input
                            type="file"
                            id="sideGalleryInput"
                            accept="image/jpeg,image/png,image/webp,image/*"
                            class="hidden"
                        >
                        <input
                            type="file"
                            name="side_photo"
                            id="sidePhotoInput"
                            accept="image/jpeg,image/png,image/webp,image/*"
                            class="hidden"
                        >
                    </div>
                </div>

            </div>

            {{-- Notes & Submit Area --}}
            <div class="pt-4 border-t border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3 sm:gap-4">
                <div class="flex-1">
                    <label for="notesInput" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">
                        Catatan Versi (Opsional)
                    </label>
                    <input
                        type="text"
                        name="notes"
                        id="notesInput"
                        class="w-full px-3 py-2.5 bg-slate-50 border border-slate-300 rounded-lg text-slate-800 text-xs focus:ring-2 focus:ring-blue-100 focus:border-blue-600 outline-none"
                        placeholder="Contoh: Penyesuaian posisi runner / penataan pola baru..."
                    >
                </div>

                <div class="flex items-center">
                    <button
                        type="submit"
                        id="savePhotoButton"
                        class="w-full sm:w-auto px-6 py-3 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white font-bold text-xs sm:text-sm rounded-lg shadow-sm hover:shadow transition-all flex items-center justify-center gap-2 cursor-pointer"
                    >
                        <i class="fas fa-save"></i>
                        <span>Simpan Foto Rangkai</span>
                    </button>
                </div>
            </div>
        </form>
        @else
        <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 text-xs text-blue-800 flex items-center gap-2">
            <i class="fas fa-info-circle text-blue-600 text-sm"></i>
            <span>Mode QC: Anda sedang melihat Master Foto Perakitan dalam mode <strong>Read-Only</strong>.</span>
        </div>
        @endif

        {{-- Version History Section --}}
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-3.5 sm:p-5 md:p-6 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-xs sm:text-sm font-bold text-slate-800 uppercase tracking-wider">Riwayat Versi Foto (History)</h3>
                    <p class="text-[11px] sm:text-xs text-slate-500">Daftar semua versi foto yang pernah diunggah untuk produk ini.</p>
                </div>
                <span id="historyCountBadge" class="px-2.5 py-1 rounded bg-slate-100 text-slate-700 text-xs font-bold font-mono">
                    {{ $history->count() }} Versi
                </span>
            </div>

            <div id="historyContainer" class="divide-y divide-slate-100">
                @forelse($history as $item)
                    <div class="py-3 sm:py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 sm:gap-4 {{ $item->is_current ? 'bg-blue-50/40 -mx-3.5 sm:-mx-6 px-3.5 sm:px-6 rounded-lg' : '' }}">
                        <div class="flex items-start gap-3">
                            <div class="flex flex-col items-center shrink-0">
                                <span class="px-2 py-0.5 rounded text-xs font-mono font-bold {{ $item->is_current ? 'bg-blue-600 text-white' : 'bg-slate-200 text-slate-700' }}">
                                    V{{ $item->version }}
                                </span>
                                @if($item->is_current)
                                    <span class="text-[8px] sm:text-[9px] font-bold text-blue-600 uppercase tracking-wider mt-0.5">CURRENT</span>
                                @endif
                            </div>

                            <div>
                                <div class="text-xs font-bold text-slate-800">
                                    Diunggah oleh: <span class="font-normal text-slate-600">{{ $item->uploader?->name ?? 'Admin / System' }}</span>
                                </div>
                                <div class="text-[10px] sm:text-[11px] text-slate-400 mt-0.5">
                                    {{ $item->created_at?->format('d M Y, H:i') ?? '-' }}
                                    @if($item->notes)
                                        &bull; <span class="italic text-slate-600">"{{ $item->notes }}"</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Thumbnails --}}
                        <div class="flex items-center gap-3 shrink-0 self-start sm:self-center">
                            {{-- Front Thumbnail --}}
                            <div class="text-center">
                                <span class="text-[9px] font-semibold text-slate-400 block uppercase mb-1">Depan</span>
                                @if($item->front_image_path)
                                    <a href="{{ $item->front_image_url }}" target="_blank" class="block w-12 h-12 sm:w-14 sm:h-14 rounded border border-slate-200 overflow-hidden bg-white hover:border-blue-500 shadow-sm transition-all" title="Klik untuk memperbesar">
                                        <img src="{{ $item->front_image_url }}" alt="Depan V{{ $item->version }}" class="w-full h-full object-cover">
                                    </a>
                                @else
                                    <div class="w-12 h-12 sm:w-14 sm:h-14 rounded border border-dashed border-slate-200 flex items-center justify-center text-[9px] sm:text-[10px] text-slate-300 bg-slate-50">
                                        Kosong
                                    </div>
                                @endif
                            </div>

                            {{-- Side Thumbnail --}}
                            <div class="text-center">
                                <span class="text-[9px] font-semibold text-slate-400 block uppercase mb-1">Samping</span>
                                @if($item->side_image_path)
                                    <a href="{{ $item->side_image_url }}" target="_blank" class="block w-12 h-12 sm:w-14 sm:h-14 rounded border border-slate-200 overflow-hidden bg-white hover:border-blue-500 shadow-sm transition-all" title="Klik untuk memperbesar">
                                        <img src="{{ $item->side_image_url }}" alt="Samping V{{ $item->version }}" class="w-full h-full object-cover">
                                    </a>
                                @else
                                    <div class="w-12 h-12 sm:w-14 sm:h-14 rounded border border-dashed border-slate-200 flex items-center justify-center text-[9px] sm:text-[10px] text-slate-300 bg-slate-50">
                                        Kosong
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="py-8 text-center text-slate-400 text-xs" id="emptyHistoryMsg">
                        <i class="fas fa-history text-2xl mb-2 text-slate-300 block"></i>
                        Belum ada riwayat foto untuk produk ini.
                    </div>
                @endforelse
            </div>
        </div>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('productSearchInput');
    const searchDropdown = document.getElementById('searchDropdown');
    const searchResultsList = document.getElementById('searchResultsList');
    const clearSearchBtn = document.getElementById('clearSearchBtn');
    const workspace = document.getElementById('productWorkspace');

    const formProductCode = document.getElementById('formProductCode');
    const formProductName = document.getElementById('formProductName');
    const bannerProductCode = document.getElementById('bannerProductCode');
    const bannerProductName = document.getElementById('bannerProductName');
    const bannerProductSpec = document.getElementById('bannerProductSpec');
    const bannerPhotoBadge = document.getElementById('bannerPhotoBadge');
    const bannerPhotoStatusText = document.getElementById('bannerPhotoStatusText');

    const frontPhotoInput = document.getElementById('frontPhotoInput');
    const frontCameraInput = document.getElementById('frontCameraInput');
    const frontGalleryInput = document.getElementById('frontGalleryInput');
    const frontImagePreview = document.getElementById('frontImagePreview');
    const frontPlaceholder = document.getElementById('frontPlaceholder');
    const frontStatusTag = document.getElementById('frontStatusTag');

    const sidePhotoInput = document.getElementById('sidePhotoInput');
    const sideCameraInput = document.getElementById('sideCameraInput');
    const sideGalleryInput = document.getElementById('sideGalleryInput');
    const sideImagePreview = document.getElementById('sideImagePreview');
    const sidePlaceholder = document.getElementById('sidePlaceholder');
    const sideStatusTag = document.getElementById('sideStatusTag');

    const historyContainer = document.getElementById('historyContainer');
    const historyCountBadge = document.getElementById('historyCountBadge');

    let debounceTimer = null;

    // Autocomplete input listener
    searchInput.addEventListener('input', function () {
        const query = searchInput.value.trim();
        clearSearchBtn.classList.toggle('hidden', query.length === 0);

        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            fetchProducts(query);
        }, 250);
    });

    clearSearchBtn.addEventListener('click', function () {
        searchInput.value = '';
        clearSearchBtn.classList.add('hidden');
        searchDropdown.classList.add('hidden');
        workspace.classList.add('hidden');
    });

    // Close dropdown on outside click
    document.addEventListener('click', function (e) {
        if (!searchInput.contains(e.target) && !searchDropdown.contains(e.target)) {
            searchDropdown.classList.add('hidden');
        }
    });

    function fetchProducts(query) {
        fetch(`{{ route('settings.assembly-photos.search') }}?q=${encodeURIComponent(query)}`)
            .then(res => res.json())
            .then(data => {
                if (!data.success || !data.products || data.products.length === 0) {
                    searchResultsList.innerHTML = `
                        <div class="p-4 text-center text-xs text-slate-400">
                            Tidak ada produk yang cocok dengan pencarian "${query}".
                        </div>
                    `;
                    searchDropdown.classList.remove('hidden');
                    return;
                }

                searchResultsList.innerHTML = data.products.map(p => `
                    <div class="p-3 hover:bg-blue-50/70 active:bg-blue-100 cursor-pointer flex flex-col sm:flex-row sm:items-center justify-between gap-1.5 sm:gap-2 transition-colors product-item"
                         data-code="${p.code}"
                         data-name="${p.name || ''}"
                         data-aisi="${p.aisi || ''}"
                         data-standard="${p.standard || ''}">
                        <div class="flex-1 min-w-0">
                            <div class="font-bold text-xs text-slate-900 flex flex-wrap items-center gap-1.5">
                                <span class="font-mono text-blue-700 bg-blue-50 px-1.5 py-0.5 rounded border border-blue-200 text-[11px] font-bold">${p.code}</span>
                                <span class="break-words">${p.name || '-'}</span>
                            </div>
                            <div class="text-[10px] text-slate-400 mt-0.5">
                                ${p.aisi ? `AISI: <strong class="text-slate-600">${p.aisi}</strong>` : ''} ${p.standard ? `&bull; Std: <strong class="text-slate-600">${p.standard}</strong>` : ''}
                            </div>
                        </div>
                        <div class="self-start sm:self-center shrink-0">
                            ${p.has_photo ? `
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-700 flex items-center gap-1">
                                    <i class="fas fa-check text-[8px]"></i> Ada Foto
                                </span>
                            ` : `
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-medium bg-slate-100 text-slate-500">
                                    Belum Ada
                                </span>
                            `}
                        </div>
                    </div>
                `).join('');

                searchDropdown.classList.remove('hidden');

                // Attach click handlers to items
                searchResultsList.querySelectorAll('.product-item').forEach(el => {
                    el.addEventListener('click', function () {
                        const code = this.dataset.code;
                        const name = this.dataset.name;
                        const aisi = this.dataset.aisi;
                        selectProduct(code, name, aisi);
                    });
                });
            })
            .catch(err => {
                console.error('Search error:', err);
            });
    }

    function selectProduct(code, name, aisi) {
        searchInput.value = `${code} — ${name}`;
        searchDropdown.classList.add('hidden');
        clearSearchBtn.classList.remove('hidden');

        formProductCode.value = code;
        formProductName.value = name;
        bannerProductCode.textContent = code;
        bannerProductName.textContent = name;
        bannerProductSpec.textContent = aisi ? `AISI: ${aisi}` : '';

        // Reset file inputs
        frontPhotoInput.value = '';
        if (frontCameraInput) frontCameraInput.value = '';
        if (frontGalleryInput) frontGalleryInput.value = '';
        sidePhotoInput.value = '';
        if (sideCameraInput) sideCameraInput.value = '';
        if (sideGalleryInput) sideGalleryInput.value = '';

        // Fetch detail & history via AJAX
        fetch(`{{ route('settings.assembly-photos.detail') }}?product_code=${encodeURIComponent(code)}`)
            .then(res => res.json())
            .then(data => {
                workspace.classList.remove('hidden');

                if (data.success && data.current) {
                    const c = data.current;
                    bannerPhotoBadge.className = 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30';
                    bannerPhotoStatusText.textContent = `FOTO TERSEDIA (V${c.version})`;

                    if (c.front_image_url) {
                        frontImagePreview.src = c.front_image_url;
                        frontImagePreview.classList.remove('hidden');
                        frontPlaceholder.classList.add('hidden');
                        frontStatusTag.textContent = `Tersedia di V${c.version}`;
                        frontStatusTag.className = 'text-[10px] text-slate-500 font-medium';
                    } else {
                        frontImagePreview.classList.add('hidden');
                        frontPlaceholder.classList.remove('hidden');
                        frontStatusTag.textContent = 'Belum Ada';
                        frontStatusTag.className = 'text-[10px] text-slate-500 font-medium';
                    }

                    if (c.side_image_url) {
                        sideImagePreview.src = c.side_image_url;
                        sideImagePreview.classList.remove('hidden');
                        sidePlaceholder.classList.add('hidden');
                        sideStatusTag.textContent = `Tersedia di V${c.version}`;
                        sideStatusTag.className = 'text-[10px] text-slate-500 font-medium';
                    } else {
                        sideImagePreview.classList.add('hidden');
                        sidePlaceholder.classList.remove('hidden');
                        sideStatusTag.textContent = 'Belum Ada';
                        sideStatusTag.className = 'text-[10px] text-slate-500 font-medium';
                    }
                } else {
                    bannerPhotoBadge.className = 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30';
                    bannerPhotoStatusText.textContent = 'FOTO BELUM TERSEDIA';

                    frontImagePreview.classList.add('hidden');
                    frontPlaceholder.classList.remove('hidden');
                    frontStatusTag.textContent = 'Belum Ada';
                    frontStatusTag.className = 'text-[10px] text-slate-500 font-medium';

                    sideImagePreview.classList.add('hidden');
                    sidePlaceholder.classList.remove('hidden');
                    sideStatusTag.textContent = 'Belum Ada';
                    sideStatusTag.className = 'text-[10px] text-slate-500 font-medium';
                }

                // Render history
                if (data.history && data.history.length > 0) {
                    historyCountBadge.textContent = `${data.history.length} Versi`;
                    historyContainer.innerHTML = data.history.map(item => `
                        <div class="py-4 flex flex-col md:flex-row md:items-center justify-between gap-4 ${item.is_current ? 'bg-blue-50/40 -mx-6 px-6 rounded-lg' : ''}">
                            <div class="flex items-start gap-3">
                                <div class="flex flex-col items-center">
                                    <span class="px-2 py-0.5 rounded text-xs font-mono font-bold ${item.is_current ? 'bg-blue-600 text-white' : 'bg-slate-200 text-slate-700'}">
                                        V${item.version}
                                    </span>
                                    ${item.is_current ? `<span class="text-[9px] font-bold text-blue-600 uppercase tracking-wider mt-0.5">CURRENT</span>` : ''}
                                </div>
                                <div>
                                    <div class="text-xs font-bold text-slate-800">
                                        Diunggah oleh: <span class="font-normal text-slate-600">${item.uploader_name}</span>
                                    </div>
                                    <div class="text-[11px] text-slate-400">
                                        ${item.created_at || '-'}
                                        ${item.notes ? `&bull; <span class="italic text-slate-600">"${item.notes}"</span>` : ''}
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="text-center">
                                    <span class="text-[9px] font-semibold text-slate-400 block uppercase mb-1">Depan</span>
                                    ${item.front_image_url ? `
                                        <a href="${item.front_image_url}" target="_blank" class="block w-14 h-14 rounded border border-slate-200 overflow-hidden bg-white hover:border-blue-500 shadow-sm transition-all" title="Perbesar">
                                            <img src="${item.front_image_url}" alt="Depan V${item.version}" class="w-full h-full object-cover">
                                        </a>
                                    ` : `
                                        <div class="w-14 h-14 rounded border border-dashed border-slate-200 flex items-center justify-center text-[10px] text-slate-300 bg-slate-50">Kosong</div>
                                    `}
                                </div>
                                <div class="text-center">
                                    <span class="text-[9px] font-semibold text-slate-400 block uppercase mb-1">Samping</span>
                                    ${item.side_image_url ? `
                                        <a href="${item.side_image_url}" target="_blank" class="block w-14 h-14 rounded border border-slate-200 overflow-hidden bg-white hover:border-blue-500 shadow-sm transition-all" title="Perbesar">
                                            <img src="${item.side_image_url}" alt="Samping V${item.version}" class="w-full h-full object-cover">
                                        </a>
                                    ` : `
                                        <div class="w-14 h-14 rounded border border-dashed border-slate-200 flex items-center justify-center text-[10px] text-slate-300 bg-slate-50">Kosong</div>
                                    `}
                                </div>
                            </div>
                        </div>
                    `).join('');
                } else {
                    historyCountBadge.textContent = '0 Versi';
                    historyContainer.innerHTML = `
                        <div class="py-8 text-center text-slate-400 text-xs">
                            <i class="fas fa-history text-2xl mb-2 text-slate-300 block"></i>
                            Belum ada riwayat foto untuk produk ini.
                        </div>
                    `;
                }
            });
    }

    // Generic file selection & synchronization handler
    function syncAndPreviewFile(sourceInput, targetInput, imagePreview, placeholder, statusTag) {
        if (sourceInput.files && sourceInput.files[0]) {
            const file = sourceInput.files[0];
            
            // Sync file to main form input using DataTransfer if different input
            if (sourceInput !== targetInput && typeof DataTransfer !== 'undefined') {
                try {
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    targetInput.files = dt.files;
                } catch (e) {
                    console.warn('DataTransfer sync warning:', e);
                }
            }

            imagePreview.src = URL.createObjectURL(file);
            imagePreview.classList.remove('hidden');
            placeholder.classList.add('hidden');

            const sizeKb = (file.size / 1024).toFixed(0);
            statusTag.textContent = `File Baru: ${sizeKb} KB (Siap Disimpan)`;
            statusTag.className = 'text-[10px] text-blue-600 font-bold';
        }
    }

    // Front photo listeners
    if (frontCameraInput) {
        frontCameraInput.addEventListener('change', function () {
            syncAndPreviewFile(this, frontPhotoInput, frontImagePreview, frontPlaceholder, frontStatusTag);
        });
    }
    if (frontGalleryInput) {
        frontGalleryInput.addEventListener('change', function () {
            syncAndPreviewFile(this, frontPhotoInput, frontImagePreview, frontPlaceholder, frontStatusTag);
        });
    }
    frontPhotoInput.addEventListener('change', function () {
        syncAndPreviewFile(this, frontPhotoInput, frontImagePreview, frontPlaceholder, frontStatusTag);
    });

    // Side photo listeners
    if (sideCameraInput) {
        sideCameraInput.addEventListener('change', function () {
            syncAndPreviewFile(this, sidePhotoInput, sideImagePreview, sidePlaceholder, sideStatusTag);
        });
    }
    if (sideGalleryInput) {
        sideGalleryInput.addEventListener('change', function () {
            syncAndPreviewFile(this, sidePhotoInput, sideImagePreview, sidePlaceholder, sideStatusTag);
        });
    }
    sidePhotoInput.addEventListener('change', function () {
        syncAndPreviewFile(this, sidePhotoInput, sideImagePreview, sidePlaceholder, sideStatusTag);
    });
});
</script>
@endsection
