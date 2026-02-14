<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Production Input - Kanban PPIC</title>
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
    <script src="{{ asset('js/axios.min.js') }}"></script>
</head>

<body class="bg-gray-100 min-h-screen p-6">

    <div class="max-w-4xl mx-auto bg-white rounded-lg shadow-md p-8">
        <h1 class="text-2xl font-bold mb-6 text-gray-800">Input Production Log</h1>

        @if(session('success'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('production.store') }}" method="POST">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Column 1 -->
                <div>
                    <label class="block text-gray-700 font-medium mb-2">Date</label>
                    <input type="date" name="date"
                        class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 p-2 border"
                        value="{{ date('Y-m-d', strtotime('-1 day')) }}" required>
                </div>

                <div>
                    <label class="block text-gray-700 font-medium mb-2">Machine ID</label>
                    <input type="text" name="machine_id"
                        class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 p-2 border"
                        required>
                </div>

                <div>
                    <label class="block text-gray-700 font-medium mb-2">Operator Name</label>
                    <input type="text" name="operator_name"
                        class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 p-2 border"
                        required>
                </div>

                <div>
                    <label class="block text-gray-700 font-medium mb-2">Item Name</label>
                    <input type="text" id="item_name" name="item_name"
                        class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 p-2 border"
                        required>
                </div>

                <!-- Column 2 -->

                <div>
                    <label class="block text-gray-700 font-medium mb-2">Cycle Time (seconds)</label>
                    <div class="flex flex-col">
                        <input type="number" name="cycle_time"
                            class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 p-2 border"
                            required>
                        <span id="cycle-time-hint" class="text-sm text-blue-600 mt-1 hidden">Recommended: <span
                                id="recommended-val" class="font-bold"></span>s</span>
                    </div>
                </div>

                <div>
                    <label class="block text-gray-700 font-medium mb-2">Qty OK</label>
                    <input type="number" name="qty_ok"
                        class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 p-2 border"
                        required>
                </div>

                <div>
                    <label class="block text-gray-700 font-medium mb-2">Qty Reject</label>
                    <input type="number" name="qty_reject"
                        class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 p-2 border"
                        value="0">
                </div>

                <div>
                    <label class="block text-gray-700 font-medium mb-2">Downtime (minutes)</label>
                    <input type="number" name="downtime_minutes"
                        class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 p-2 border"
                        value="0">
                </div>
            </div>

            <div class="mt-6">
                <label class="block text-gray-700 font-medium mb-2">Remarks</label>
                <textarea name="remarks"
                    class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 p-2 border"
                    rows="3"></textarea>
            </div>

            <div class="mt-8">
                <button type="submit"
                    class="w-full bg-blue-600 text-white font-bold py-2 px-4 rounded hover:bg-blue-700 transition duration-150">
                    Submit Log
                </button>
            </div>
        </form>
    </div>

    <script>
        const itemInput = document.getElementById('item_name');
        const hintEl = document.getElementById('cycle-time-hint');
        const recommendedValEl = document.getElementById('recommended-val');

        let timeout = null;

        itemInput.addEventListener('input', function () {
            clearTimeout(timeout);
            const val = this.value;

            if (val.length < 2) {
                hintEl.classList.add('hidden');
                return;
            }

            timeout = setTimeout(() => {
                axios.get('{{ route("production.cycle-time") }}', {
                    params: { item_name: val }
                })
                    .then(response => {
                        const avg = response.data.average_cycle_time;
                        if (avg > 0) {
                            recommendedValEl.textContent = avg;
                            hintEl.classList.remove('hidden');
                        } else {
                            hintEl.classList.add('hidden');
                        }
                    })
                    .catch(error => console.error(error));
            }, 500); // Debounce 500ms
        });
    </script>
</body>

</html>