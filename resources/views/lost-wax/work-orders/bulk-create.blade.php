@extends('layouts.app')

@section('top_bar')
    <div class="flex items-center justify-between w-full">
        <div>
            <h1 class="text-lg font-bold text-slate-800 leading-tight">Bulk Input Work Order</h1>
            <p class="text-gray-500 text-[10px]">Copy dari Excel, paste langsung ke tabel</p>
        </div>
        <a href="{{ route('lost-wax.work-orders.index') }}" class="text-slate-500 hover:text-slate-700 text-xs">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
    </div>
@endsection

@section('content')
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 h-full flex flex-col">
        <div class="mb-4 p-3 bg-amber-50 border-l-4 border-amber-400 text-xs text-amber-700">
            <p><strong>Tips:</strong> Copy (Ctrl+C) data dari Excel, lalu Paste (Ctrl+V) langsung ke tabel di bawah. Isi minimal kolom yang wajib (*).</p>
            <p class="mt-1">Format: ET Code* | Item Code* | PO Number* | Customer | PO Qty* | Stock Qty | Net Req* | Family | Status | Notes | Due Date</p>
        </div>

        <div id="bulkTable" class="flex-1 overflow-hidden border border-gray-200 rounded"></div>

        <div class="flex justify-between items-center mt-4">
            <div id="validationSummary" class="text-xs text-red-600 hidden"></div>
            <button onclick="submitBulk()" id="submitBtn"
                class="bg-amber-600 hover:bg-amber-700 text-white font-bold py-2 px-6 rounded shadow-sm transition-all disabled:opacity-50 disabled:cursor-not-allowed">
                <i class="fas fa-save mr-2"></i> Simpan Semua Work Order
            </button>
        </div>
    </div>

    <script>
        let hot;
        const container = document.getElementById('bulkTable');
        const itemCodes = @json($itemOptions->pluck('code')->toArray());
        const families = @json(array_keys($families));
        const statuses = ['draft', 'planned', 'active', 'hold', 'completed', 'cancelled'];

        const initialData = Array.from({ length: 30 }, () => ['', '', '', '', '', '0', '', '', 'draft', '', '']);

        hot = new Handsontable(container, {
            data: initialData,
            rowHeaders: true,
            colHeaders: [
                'ET Code *', 'Item Code *', 'PO Number *', 'Customer',
                'PO Qty *', 'Stock Qty', 'Net Req *', 'Family',
                'Status', 'Notes', 'Due Date'
            ],
            columns: [
                { type: 'text' },
                {
                    type: 'dropdown',
                    source: itemCodes,
                    strict: false
                },
                { type: 'text' },
                { type: 'text' },
                { type: 'numeric' },
                { type: 'numeric' },
                { type: 'numeric' },
                {
                    type: 'dropdown',
                    source: families,
                    strict: false
                },
                {
                    type: 'dropdown',
                    source: statuses,
                    strict: false
                },
                { type: 'text' },
                { type: 'date', dateFormat: 'YYYY-MM-DD', correctFormat: true }
            ],
            colWidths: [100, 80, 100, 120, 80, 80, 80, 70, 80, 120, 100],
            height: '100%',
            width: '100%',
            stretchH: 'all',
            manualColumnResize: true,
            contextMenu: true,
            licenseKey: 'non-commercial-and-evaluation'
        });

        function submitBulk() {
            const submitBtn = document.getElementById('submitBtn');
            const summaryEl = document.getElementById('validationSummary');
            const rawData = hot.getData();
            const rows = [];

            rawData.forEach(row => {
                const etCode = (row[0] || '').toString().trim();
                const itemCode = (row[1] || '').toString().trim();
                const poNumber = (row[2] || '').toString().trim();

                if (etCode && itemCode && poNumber) {
                    rows.push({
                        et_code: etCode,
                        item_code: itemCode,
                        po_number: poNumber,
                        customer_name: (row[3] || '').toString().trim(),
                        po_quantity: parseInt(row[4]) || 0,
                        stock_quantity: parseInt(row[5]) || 0,
                        net_requirement_quantity: parseInt(row[6]) || 0,
                        family_code: (row[7] || '').toString().trim(),
                        status: (row[8] || 'draft').toString().trim(),
                        notes: (row[9] || '').toString().trim(),
                        due_date: (row[10] || '').toString().trim(),
                    });
                }
            });

            if (rows.length === 0) {
                alert('Silakan masukkan minimal satu baris data (ET Code, Item Code, PO Number).');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Memproses...';
            summaryEl.classList.add('hidden');

            axios.post('{{ route('lost-wax.work-orders.bulk.store') }}', { rows: rows })
                .then(res => {
                    if (res.data.success) {
                        alert(res.data.message);
                        if (res.data.redirect) {
                            window.location.href = res.data.redirect;
                        }
                    }
                })
                .catch(err => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-save mr-2"></i> Simpan Semua Work Order';

                    if (err.response && err.response.data && err.response.data.errors) {
                        const errorList = err.response.data.errors;
                        summaryEl.innerHTML = '<strong>Error validasi:</strong><br>' + errorList.join('<br>');
                        summaryEl.classList.remove('hidden');

                        highlightErrors(errorList);
                    } else {
                        const msg = (err.response && err.response.data && err.response.data.message)
                            ? err.response.data.message
                            : 'Terjadi kesalahan. Coba lagi.';
                        alert(msg);
                    }
                });
        }

        function highlightErrors(errors) {
            const rowCount = hot.countRows();
            for (let r = 0; r < rowCount; r++) {
                hot.setCellMeta(r, 0, 'className', '');
            }
            hot.render();

            errors.forEach(function(err) {
                const match = err.match(/Baris (\d+):/);
                if (match) {
                    const rowIdx = parseInt(match[1]) - 1;
                    if (rowIdx >= 0 && rowIdx < rowCount) {
                        hot.setCellMeta(rowIdx, 0, 'className', 'htInvalid');
                    }
                }
            });

            hot.render();
        }
    </script>

    <style>
        .handsontable th {
            background-color: #f8fafc !important;
            font-weight: bold !important;
            font-size: 11px !important;
            color: #475569 !important;
        }

        .handsontable td {
            font-size: 12px !important;
        }

        .htInvalid {
            background-color: #fef2f2 !important;
            color: #dc2626 !important;
        }
    </style>
@endsection
