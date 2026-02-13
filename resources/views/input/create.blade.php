@extends('layouts.app')

@section('content')
    <div class="bg-white shadow-md rounded-lg p-6 h-full flex flex-col">
        <!-- Header Section -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Input Produksi: <span
                        class="text-blue-600">{{ match ($dept) { 'bubut_od' => 'Bubut OD', 'bubut_cnc' => 'Bubut CNC', default => ucfirst($dept)} }}</span>
                </h1>
                <p class="text-sm text-slate-500 mt-1">Gunakan tabel di bawah untuk melaporkan hasil pekerjaan harian.</p>
            </div>

            <div class="flex items-center gap-4">
                <!-- Data Date Selector -->
                <div class="flex flex-col">
                    <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Tanggal
                        Pekerjaan</label>
                    <input type="date" id="production_date" value="{{ date('Y-m-d') }}"
                        class="border-slate-300 rounded-lg text-sm font-bold text-slate-700 shadow-sm focus:ring-blue-500">
                </div>

                <div class="h-10 w-px bg-slate-200 mx-2"></div>

                <div class="flex gap-2">
                    <a href="{{ route('input.index', $dept) }}"
                        class="bg-white hover:bg-slate-50 text-slate-600 font-bold py-2 px-4 rounded-lg text-sm flex items-center border border-slate-300 transition-all shadow-sm">
                        <i class="fas fa-arrow-left mr-2"></i> Kembali
                    </a>
                    <button onclick="confirmAndSubmit()"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg shadow-md text-sm transition-all flex items-center gap-2">
                        <i class="fas fa-save"></i> Simpan Data
                    </button>
                </div>
            </div>
        </div>

        <!-- Info/Instructions -->
        <div class="mb-4 p-4 bg-blue-50 border-l-4 border-blue-400 rounded-r-lg flex justify-between items-center">
            <div class="text-[11px] text-blue-800">
                <p class="font-bold mb-1"><i class="fas fa-info-circle mr-1"></i> Petunjuk Pengisian:</p>
                @if($dept === 'cor')
                    <p>Masukkan data aktual casting. Pastikan <b>Item Code</b> dan <b>Line</b> sesuai dengan Rencana Cor untuk
                        pemotongan otomatis.</p>
                @else
                    <p>Masukkan kombinasi <b>Code + Heat Number</b> untuk memindahkan barang ke departemen berikutnya.</p>
                    <p>Card akan terpisah (split) otomatis jika jumlah <b>Hasil + Rusak</b> kurang dari stok yang ada di
                        departemen ini.</p>
                @endif
            </div>
            <div class="text-right">
                <span
                    class="bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-[10px] font-bold uppercase border border-blue-200 shadow-sm">
                    Mode: {{ $dept === 'cor' ? 'New Production' : 'Item Movement' }}
                </span>
            </div>
        </div>

        <!-- Handsontable Container -->
        <div id="hotContainer" class="flex-1 overflow-hidden border border-slate-200 rounded-lg shadow-inner bg-slate-50">
        </div>

        <!-- Error/Notification Area -->
        <div id="errorArea" class="mt-4 hidden animate-pulse">
            <div class="bg-red-50 border border-red-200 rounded-lg p-3">
                <h4 class="text-red-800 text-xs font-bold flex items-center gap-2 mb-2">
                    <i class="fas fa-exclamation-triangle"></i> Beberapa baris gagal diproses:
                </h4>
                <ul id="errorList" class="text-[10px] text-red-600 list-disc list-inside space-y-1"></ul>
            </div>
        </div>
    </div>

    <script>
        let hot;
        const container = document.getElementById('hotContainer');
        const dept = "{{ $dept }}";

        // Define Columns based on Department
        let colHeaders, columns, schema;

        const parseNum = (val) => {
            if (val === null || val === undefined || val === '' || val === '-') return null;
            if (typeof val === 'number') return val;
            const clean = val.toString().replace(/[^-0-9.]/g, '');
            const num = parseFloat(clean);
            return isNaN(num) ? null : num;
        };

        if (dept === 'cor') {
            colHeaders = ['Code', 'Heat No', 'Item Code', 'Item Name', 'AISI', 'Size', 'Bruto (KG)', 'Netto (KG)', 'Finish (KG)', 'Qty (PCS)', 'Line', 'Customer'];
            columns = [
                { type: 'text' }, { type: 'text' }, { type: 'text' }, { type: 'text' },
                { type: 'text' }, { type: 'text' }, { type: 'text' }, { type: 'text' },
                { type: 'text' }, { type: 'numeric' }, { type: 'text' }, { type: 'text' }
            ];
            schema = (row) => ({
                code: row[0], heat_number: row[1], item_code: row[2], item_name: row[3],
                aisi: row[4], size: row[5],
                bruto_weight: parseNum(row[6]),
                netto_weight: parseNum(row[7]),
                finish_weight: parseNum(row[8]),
                qty_pcs: parseInt(row[9]) || 0,
                weight_kg: parseNum(row[8]) || parseNum(row[7]) || parseNum(row[6]) || 0,
                line_number: row[10], customer: row[11]
            });
        } else {
            // Movement Stages: Netto, Bubut, Bor, Finish
            colHeaders = ['Code', 'Heat Number', 'Item Name', 'Finish Weight (KG)', 'Hasil (PCS)', 'Rusak (PCS)'];
            columns = [
                { type: 'text' }, { type: 'text' }, { type: 'text' },
                { type: 'text' }, { type: 'numeric' }, { type: 'numeric' }
            ];

            if (dept === 'bubut_cnc') {
                colHeaders.splice(3, 0, 'Bubut Weight (KG)');
                columns.splice(3, 0, { type: 'text' });
                schema = (row) => ({
                    code: row[0], heat_number: row[1], item_name: row[2],
                    bubut_weight: parseNum(row[3]),
                    finish_weight: parseNum(row[4]),
                    hasil: parseInt(row[5]) || 0,
                    rusak: parseInt(row[6]) || 0,
                    qty_pcs: (parseInt(row[5]) || 0) + (parseInt(row[6]) || 0),
                    weight_kg: parseNum(row[4]) || parseNum(row[3]) || null
                });
            } else {
                schema = (row) => ({
                    code: row[0], heat_number: row[1], item_name: row[2],
                    finish_weight: parseNum(row[3]),
                    hasil: parseInt(row[4]) || 0,
                    rusak: parseInt(row[5]) || 0,
                    qty_pcs: (parseInt(row[4]) || 0) + (parseInt(row[5]) || 0),
                    weight_kg: parseNum(row[3]) || null
                });
            }
        }

        const initialData = Array.from({ length: 30 }, () => Array(colHeaders.length).fill(null));

        hot = new Handsontable(container, {
            data: initialData,
            rowHeaders: true,
            colHeaders: colHeaders,
            columns: columns,
            height: '100%',
            width: '100%',
            stretchH: 'all',
            manualColumnResize: true,
            contextMenu: true,
            rowHeights: 35,
            licenseKey: 'non-commercial-and-evaluation'
        });

        async function confirmAndSubmit() {
            const prodDate = document.getElementById('production_date').value;
            if (!prodDate) {
                Swal.fire('Error', 'Pilih tanggal pekerjaan terlebih dahulu!', 'error');
                return;
            }

            const { value: confirmed } = await Swal.fire({
                title: 'Konfirmasi Simpan',
                html: `Apakah tanggal pekerjaan <b>${prodDate}</b> sudah sesuai dengan data yang Anda upload?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, Sudah Sesuai',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#2563eb'
            });

            if (confirmed) {
                submitData(prodDate);
            }
        }

        function submitData(prodDate) {
            const rawData = hot.getData();
            const items = [];

            rawData.forEach(row => {
                // Minimum validation: Code + Heat or Item Code
                if (row[0] && row[1]) {
                    items.push(schema(row));
                }
            });

            if (items.length === 0) {
                Swal.fire('Info', 'Minimal masukkan satu baris data lengkap.', 'info');
                return;
            }

            Swal.fire({
                title: 'Memproses...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            axios.post('{{ route('input.store', $dept) }}', {
                production_date: prodDate,
                items: items
            })
                .then(res => {
                    Swal.close();
                    if (res.data.success) {
                        Swal.fire({
                            title: 'Berhasil!',
                            text: res.data.message,
                            icon: 'success',
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            window.location.href = res.data.redirect;
                        });
                    } else {
                        handleErrors(res.data.errors, res.data.message);
                    }
                })
                .catch(err => {
                    Swal.close();
                    console.error(err);
                    let errMsg = 'Terjadi kesalahan sistem.';
                    if (err.response && err.response.data && err.response.data.message) {
                        errMsg = err.response.data.message;
                    }
                    Swal.fire('Gagal', errMsg, 'error');
                });
        }

        function handleErrors(errors, message) {
            const errorArea = document.getElementById('errorArea');
            const errorList = document.getElementById('errorList');

            errorArea.classList.remove('hidden');
            errorList.innerHTML = '';

            errors.forEach(err => {
                const li = document.createElement('li');
                li.textContent = err;
                errorList.appendChild(li);
            });

            Swal.fire({
                title: 'Beberapa Data Gagal',
                text: message,
                icon: 'warning',
                confirmButtonText: 'Tinjau Kesalahan'
            });
        }
    </script>

    <style>
        .handsontable th {
            background-color: #f8fafc !important;
            font-weight: 800 !important;
            font-size: 11px !important;
            text-transform: uppercase;
            color: #475569 !important;
            vertical-align: middle !important;
            border-bottom: 2px solid #e2e8f0 !important;
        }

        .handsontable td {
            font-size: 12px !important;
            color: #1e293b !important;
            vertical-align: middle !important;
        }

        .htRowHeaders th {
            font-size: 10px !important;
            color: #94a3b8 !important;
        }
    </style>
@endsection