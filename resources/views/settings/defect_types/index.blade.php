@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold text-slate-800">Setting Jenis Kerusakan</h1>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach($departments as $dept)
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 flex flex-col h-full">
                    <h3 class="text-lg font-bold text-slate-700 mb-4 pb-2 border-b border-slate-100 flex items-center gap-2">
                        <span
                            class="bg-blue-100 text-blue-700 text-xs px-2 py-0.5 rounded uppercase">{{ ucfirst(str_replace('_', ' ', $dept)) }}</span>
                    </h3>

                    <ul class="space-y-3 flex-1 mb-4">
                        @forelse($defectTypes[$dept] ?? [] as $type)
                            <li class="flex items-center justify-between group p-2 rounded hover:bg-slate-50 transition-colors">
                                <span class="text-slate-700 font-medium">{{ $type->name }}</span>
                                <button type="button" onclick="editDefect({{ $type->id }}, '{{ addslashes($type->name) }}')"
                                    class="text-slate-400 hover:text-blue-500 opacity-0 group-hover:opacity-100 transition-opacity"
                                    title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </li>
                        @empty
                            <li class="text-slate-400 text-sm italic py-2">Belum ada jenis kerusakan.</li>
                        @endforelse
                    </ul>

                    <form action="{{ route('settings.defect-types.store') }}" method="POST"
                        class="mt-auto pt-4 border-t border-slate-100">
                        @csrf
                        <input type="hidden" name="department" value="{{ $dept }}">
                        <div class="flex gap-2">
                            <input type="text" name="name" placeholder="Tambah baru..."
                                class="flex-1 rounded-md border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                                required>
                            <button type="submit"
                                class="bg-blue-600 hover:bg-blue-700 text-white rounded-md px-3 py-2 text-sm shadow-sm transition-colors">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </form>
                </div>
            @endforeach
        </div>
    </div>

    <!-- Hidden form for editing -->
    <form id="editForm" method="POST" style="display: none;">
        @csrf
        @method('PUT')
        <input type="hidden" name="name" id="editNameInput">
    </form>

    <script>
        function editDefect(id, currentName) {
            Swal.fire({
                title: 'Edit Jenis Kerusakan',
                input: 'text',
                inputValue: currentName,
                inputPlaceholder: 'Masukkan nama kerusakan...',
                showCancelButton: true,
                confirmButtonText: 'Simpan',
                cancelButtonText: 'Batal',
                inputValidator: (value) => {
                    if (!value || value.trim() === '') {
                        return 'Nama kerusakan tidak boleh kosong!'
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.getElementById('editForm');
                    // Use Laravel's base url to handle subdirectory structures like /kanban-ppic/public/index.php/
                    form.action = "{{ url('settings/defect-types') }}/" + id;
                    document.getElementById('editNameInput').value = result.value.trim();
                    form.submit();
                }
            });
        }
    </script>
@endsection