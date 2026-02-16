@extends('layouts.app')

@section('content')
    <div class="bg-white shadow-md rounded-lg p-6 h-full flex flex-col">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h1 class="text-xl font-bold text-gray-800">Rencana Cor (Input PPIC)</h1>
                <p class="text-sm text-gray-500">Masukkan data P.O. dari Customer untuk antrian Cor.</p>
            </div>
            <button onclick="savePlans()"
                class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded shadow-sm transition-all">
                <i class="fas fa-save mr-2"></i> Simpan Rencana
            </button>
        </div>

        <div class="mb-4 p-3 bg-blue-50 border-l-4 border-blue-400 text-xs text-blue-700">
            <p><strong>Tips:</strong> Anda bisa Copy (Ctrl+C) data dari Excel dan Paste (Ctrl+V) langsung ke tabel di bawah.
            </p>
            <p>Format Kolom: Code | Item Code | Item Name | AISI | Size | Weight | P.O. Number | Qty Plan | Line | Customer
            </p>
        </div>

        <div id="planTable" class="flex-1 overflow-hidden border border-gray-200 rounded"></div>
    </div>

    <script>
        let hot;
        const container = document.getElementById('planTable');

        // Initial data: 30 empty rows
        const initialData = Array.from({ length: 30 }, () => [null, null, null, null, null, null, null, null, null, null]);

        hot = new Handsontable(container, {
            data: initialData,
            rowHeaders: true,
            colHeaders: [
                'Code', 'Item Code', 'Item Name', 'AISI', 'Size', 'Weight', 'P.O. Number', 'Qty Plan', 'Line', 'Customer'
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
                { type: 'text' },
                { type: 'text' },
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
            const rawData = hot.getData();
            const plans = [];

            rawData.forEach(row => {
                // Check if Item Code, Item Name, PO, Qty, and Line are filled
                if (row[1] && row[2] && row[6] && row[7] && row[8]) {
                    plans.push({
                        code: row[0],
                        item_code: row[1],
                        item_name: row[2],
                        aisi: row[3],
                        size: row[4],
                        weight: row[5],
                        po_number: row[6],
                        qty_planned: row[7],
                        line_number: row[8],
                        customer: row[9]
                    });
                }
            });

            if (plans.length === 0) {
                alert('Silakan masukkan minimal satu baris data rencana yang lengkap (Item Code, Name, PO, Qty, Line).');
                return;
            }

            axios.post('{{ route('plan.store') }}', { plans: plans })
                .then(res => {
                    alert(res.data.message);
                    if (res.data.redirect) {
                        window.location.href = res.data.redirect;
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Terjadi kesalahan saat menyimpan data. Periksa konsol.');
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