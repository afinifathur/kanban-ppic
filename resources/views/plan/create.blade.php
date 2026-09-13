@extends('layouts.app')

@section('content')
    <div class="bg-white shadow-md rounded-lg p-6 h-full flex flex-col">
        <!-- Top Bar / Header -->
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-4">
            <div>
                <h1 class="text-xl font-bold text-gray-800">Rencana Produksi</h1>
                <p class="text-sm text-gray-500">Masukkan data P.O. dari Customer untuk perencanaan produksi.</p>
            </div>

            <div class="flex flex-wrap items-center gap-4">
                <!-- Planning Info Inputs -->
                <div class="bg-slate-50 p-2.5 rounded-lg flex flex-wrap items-center gap-3 border border-slate-200">
                    <div class="flex items-center gap-2">
                        <label class="text-xs font-bold text-slate-600 uppercase">Judul Rencana <span class="text-red-500">*</span>:</label>
                        <input type="text" id="planTitle" placeholder="Misal: Rencana A06" required
                            class="text-sm border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500 w-48 px-2.5 py-1.5 bg-white">
                    </div>

                    <div class="hidden sm:block h-6 w-px bg-slate-300"></div>

                    <div class="flex items-center gap-2">
                        <label class="text-xs font-bold text-slate-600 uppercase">Tanggal <span class="text-red-500">*</span>:</label>
                        <input type="date" id="planDate" value="{{ date('Y-m-d') }}" required
                            class="text-sm border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500 w-36 px-2 py-1.5 bg-white">
                    </div>

                    <div class="hidden sm:block h-6 w-px bg-slate-300"></div>

                    <!-- Production Domain Selection -->
                    <div class="flex items-center gap-2">
                        <label class="text-xs font-bold text-slate-600 uppercase">Proses Produksi <span class="text-red-500">*</span>:</label>
                        <select id="productionDomain" required
                            class="text-sm border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500 px-3 py-1.5 bg-white font-medium text-slate-700">
                            <option value="" disabled selected>-- Pilih Proses Produksi --</option>
                            <option value="LOST_WAX">Lost Wax</option>
                            <option value="SAND_CASTING">Sand Casting</option>
                        </select>
                    </div>
                </div>

                <button onclick="savePlans()" id="saveBtn"
                    class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 px-6 rounded-lg shadow-sm transition-all disabled:opacity-50 disabled:cursor-not-allowed text-sm flex items-center gap-2">
                    <i class="fas fa-save"></i> Simpan Rencana
                </button>
            </div>
        </div>

        <!-- Instructions Tip -->
        <div class="mb-4 p-3 bg-blue-50 border-l-4 border-blue-500 text-xs text-blue-800 rounded-r flex items-start gap-2">
            <i class="fas fa-info-circle text-blue-500 mt-0.5"></i>
            <div>
                <p><strong>Tips:</strong> Anda bisa Copy (Ctrl+C) data dari Excel dan Paste (Ctrl+V) langsung ke tabel di bawah. Semua baris di bawah akan mewarisi Proses Produksi yang dipilih di atas.</p>
                <p class="text-slate-600 mt-0.5">Format Kolom: Code | Item Code | Item Name | AISI | Size | Weight | P.O. Number | P.O. Quantity | Qty Plan | Line | Customer</p>
            </div>
        </div>

        <div id="planTable" class="flex-1 overflow-hidden border border-gray-200 rounded"></div>
    </div>

    <script>
        let hot;
        const container = document.getElementById('planTable');
        const customerList = @json($customers->pluck('name'));

        // Initial data: 30 empty rows
        const initialData = Array.from({ length: 30 }, () => [null, null, null, null, null, null, null, null, null, null, null]);

        hot = new Handsontable(container, {
            data: initialData,
            rowHeaders: true,
            colHeaders: [
                'Code', 'Item Code', 'Item Name', 'AISI', 'Size', 'Weight', 'P.O. Number', 'P.O. Quantity', 'Qty Plan', 'Line', 'Customer'
            ],
            columns: [
                { type: 'text' },
                { type: 'text' },
                { type: 'text' },
                { type: 'text' },
                { type: 'text' },
                { type: 'numeric' },
                { type: 'text' },
                { type: 'numeric' },
                { type: 'numeric' },
                { type: 'text' },
                {
                    type: 'autocomplete',
                    source: customerList,
                    strict: false // Allow manual entry if not in list
                },
            ],
            height: '100%',
            width: '100%',
            stretchH: 'all',
            manualColumnResize: true,
            contextMenu: true,
            filters: true,
            dropdownMenu: true,
            licenseKey: 'non-commercial-and-evaluation'
        });

        function savePlans() {
            const saveBtn = document.getElementById('saveBtn');
            const planTitle = document.getElementById('planTitle').value;
            const planDate = document.getElementById('planDate').value;
            const domainSelect = document.getElementById('productionDomain');
            const productionDomain = domainSelect ? domainSelect.value : '';

            // Priority 1: Judul Rencana
            if (!planTitle || planTitle.trim() === '') {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Judul Rencana Belum Diisi',
                        text: 'Silakan isi Judul Rencana terlebih dahulu.',
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#2563eb'
                    }).then(() => {
                        document.getElementById('planTitle').focus();
                    });
                } else {
                    alert('Silakan isi Judul Rencana terlebih dahulu.');
                    document.getElementById('planTitle').focus();
                }
                return;
            }

            // Priority 2: Tanggal Rencana
            if (!planDate || planDate.trim() === '') {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Tanggal Rencana Belum Diisi',
                        text: 'Silakan pilih Tanggal Rencana terlebih dahulu.',
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#2563eb'
                    }).then(() => {
                        document.getElementById('planDate').focus();
                    });
                } else {
                    alert('Silakan pilih Tanggal Rencana terlebih dahulu.');
                    document.getElementById('planDate').focus();
                }
                return;
            }

            // Priority 3: Proses Produksi
            if (!productionDomain || productionDomain.trim() === '') {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Proses Produksi Belum Dipilih',
                        text: 'Silakan pilih Proses Produksi terlebih dahulu: Lost Wax atau Sand Casting.',
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#2563eb'
                    }).then(() => {
                        document.getElementById('productionDomain').focus();
                    });
                } else {
                    alert('Silakan pilih Proses Produksi terlebih dahulu: Lost Wax atau Sand Casting.');
                    document.getElementById('productionDomain').focus();
                }
                return;
            }

            const rawData = hot.getData();
            const plans = [];

            rawData.forEach(row => {
                // Check if Item Code, Item Name, PO Number, Qty Plan, and Line are filled
                if (row[1] && row[2] && row[6] && row[8] && row[9]) {
                    plans.push({
                        code: row[0],
                        item_code: row[1],
                        item_name: row[2],
                        aisi: row[3],
                        size: row[4],
                        weight: row[5],
                        po_number: row[6],
                        po_quantity: row[7] !== null && row[7] !== '' && !isNaN(row[7]) ? Number(row[7]) : null,
                        qty_planned: row[8],
                        line_number: row[9],
                        customer: row[10]
                    });
                }
            });

            // Priority 4: Handsontable Rows
            if (plans.length === 0) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Data Rencana Kosong',
                        text: 'Silakan masukkan minimal satu baris data rencana yang lengkap (Item Code, Name, PO, Qty, Line).',
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#2563eb'
                    });
                } else {
                    alert('Silakan masukkan minimal satu baris data rencana yang lengkap (Item Code, Name, PO, Qty, Line).');
                }
                return;
            }

            // Disable button and show loading
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Memproses...';

            axios.post('{{ route('plan.store') }}', {
                plans: plans,
                date: planDate,
                title: planTitle,
                production_domain: productionDomain
            })
                .then(res => {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Berhasil',
                            text: res.data.message,
                            confirmButtonColor: '#2563eb'
                        }).then(() => {
                            if (res.data.redirect) {
                                window.location.href = res.data.redirect;
                            } else {
                                saveBtn.disabled = false;
                                saveBtn.innerHTML = '<i class="fas fa-save mr-2"></i> Simpan Rencana';
                            }
                        });
                    } else {
                        alert(res.data.message);
                        if (res.data.redirect) {
                            window.location.href = res.data.redirect;
                        } else {
                            saveBtn.disabled = false;
                            saveBtn.innerHTML = '<i class="fas fa-save mr-2"></i> Simpan Rencana';
                        }
                    }
                })
                .catch(err => {
                    console.error(err);
                    const msg = err.response && err.response.data && err.response.data.message ? err.response.data.message : 'Terjadi kesalahan saat menyimpan data. Periksa konsol.';
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal Menyimpan',
                            text: msg,
                            confirmButtonColor: '#2563eb'
                        });
                    } else {
                        alert(msg);
                    }
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="fas fa-save mr-2"></i> Simpan Rencana';
                });
        }
    </script>

    <style>
        /* Adjust Handsontable for better look */
        .handsontable th {
            background-color: #f8fafc !important;
            font-weight: bold !important;
            font-size: 11px !important;
            color: #475569 !important;
        }

        .handsontable td {
            font-size: 12px !important;
        }
    </style>
@endsection