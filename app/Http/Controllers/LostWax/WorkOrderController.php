<?php

namespace App\Http\Controllers\LostWax;

use App\Contracts\ItemMasterRepository;
use App\Http\Controllers\Controller;
use App\Models\LostWaxItemReference;
use App\Models\LostWaxWorkOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkOrderController extends Controller
{
    public function __construct(private readonly ItemMasterRepository $itemMasterRepository) {}

    public function index()
    {
        $workOrders = LostWaxWorkOrder::with(['itemReference', 'plans', 'wipEntries'])
            ->orderByDesc('id')
            ->paginate(20);

        return view('lost-wax.work-orders.index', compact('workOrders'));
    }

    public function create()
    {
        $itemOptions = $this->itemMasterRepository->allActive();
        $workOrder = new LostWaxWorkOrder;

        return view('lost-wax.work-orders.create', compact('itemOptions', 'workOrder'));
    }

    public function store(Request $request)
    {
        $data = $this->validateWorkOrder($request);

        $item = $this->itemMasterRepository->findByCode($data['item_code']);

        if (! $item) {
            throw ValidationException::withMessages([
                'item_code' => 'Item tidak ditemukan di MasterDataKPI atau belum tersedia di koneksi baca-saja.',
            ]);
        }

        $workOrder = DB::transaction(function () use ($data, $item) {
            $itemReference = LostWaxItemReference::updateOrCreate(
                [
                    'master_source' => 'masterdata_kpi',
                    'master_item_key' => $item['code'],
                ],
                [
                    'item_code_snapshot' => $item['code'],
                    'item_name_snapshot' => $item['name'] ?? null,
                    'aisi_snapshot' => $item['aisi'] ?? null,
                    'standard_snapshot' => $item['standard'] ?? null,
                    'unit_weight_snapshot' => $item['unit_weight'] ?? null,
                    'status_snapshot' => $item['status'] ?? null,
                    'last_synced_at' => now(),
                ]
            );

            return LostWaxWorkOrder::create([
                'item_reference_id' => $itemReference->id,
                'et_code' => $data['et_code'],
                'et_prefix' => $this->parseEtParts($data['et_code'])['prefix'],
                'et_period' => $this->parseEtParts($data['et_code'])['period'],
                'et_sequence' => $this->parseEtParts($data['et_code'])['sequence'],
                'po_number' => $data['po_number'],
                'customer_name' => $data['customer_name'] ?? null,
                'po_quantity' => $data['po_quantity'],
                'stock_quantity' => $data['stock_quantity'],
                'net_requirement_quantity' => $data['net_requirement_quantity'],
                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'family_code' => $data['family_code'] ?? null,
                'require_layer_7' => $data['require_layer_7'] ?? false,
            ]);
        });

        return redirect()->route('lost-wax.work-orders.show', $workOrder)->with('success', 'Work order berhasil ditambahkan.');
    }

    public function show(LostWaxWorkOrder $workOrder)
    {
        $workOrder->load(['itemReference', 'plans', 'wipEntries.plan', 'wipEntries']);

        $nextWaveNumber = (int) ($workOrder->plans->max('wave_number') ?? 0) + 1;

        return view('lost-wax.work-orders.show', compact('workOrder', 'nextWaveNumber'));
    }

    public function edit(LostWaxWorkOrder $workOrder)
    {
        $itemOptions = $this->itemMasterRepository->allActive();
        $workOrder->load('itemReference');

        return view('lost-wax.work-orders.edit', compact('workOrder', 'itemOptions'));
    }

    public function update(Request $request, LostWaxWorkOrder $workOrder)
    {
        $data = $this->validateWorkOrder($request, $workOrder->id);

        $item = $this->itemMasterRepository->findByCode($data['item_code']);

        if (! $item) {
            throw ValidationException::withMessages([
                'item_code' => 'Item tidak ditemukan di MasterDataKPI atau belum tersedia di koneksi baca-saja.',
            ]);
        }

        DB::transaction(function () use ($workOrder, $data, $item) {
            $itemReference = LostWaxItemReference::updateOrCreate(
                [
                    'master_source' => 'masterdata_kpi',
                    'master_item_key' => $item['code'],
                ],
                [
                    'item_code_snapshot' => $item['code'],
                    'item_name_snapshot' => $item['name'] ?? null,
                    'aisi_snapshot' => $item['aisi'] ?? null,
                    'standard_snapshot' => $item['standard'] ?? null,
                    'unit_weight_snapshot' => $item['unit_weight'] ?? null,
                    'status_snapshot' => $item['status'] ?? null,
                    'last_synced_at' => now(),
                ]
            );

            $workOrder->update([
                'item_reference_id' => $itemReference->id,
                'et_code' => $data['et_code'],
                'et_prefix' => $this->parseEtParts($data['et_code'])['prefix'],
                'et_period' => $this->parseEtParts($data['et_code'])['period'],
                'et_sequence' => $this->parseEtParts($data['et_code'])['sequence'],
                'po_number' => $data['po_number'],
                'customer_name' => $data['customer_name'] ?? null,
                'po_quantity' => $data['po_quantity'],
                'stock_quantity' => $data['stock_quantity'],
                'net_requirement_quantity' => $data['net_requirement_quantity'],
                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'family_code' => $data['family_code'] ?? null,
                'require_layer_7' => $data['require_layer_7'] ?? false,
            ]);
        });

        return redirect()->route('lost-wax.work-orders.show', $workOrder)->with('success', 'Work order berhasil diperbarui.');
    }

    public function storePlan(Request $request, LostWaxWorkOrder $workOrder)
    {
        $data = $request->validate([
            'wave_number' => 'nullable|integer|min:1',
            'plan_type' => 'required|in:initial,additional',
            'planned_quantity' => 'required|integer|min:1',
            'status' => 'required|in:draft,planned,active,completed,cancelled',
            'reason' => 'nullable|string|max:500',
            'notes' => 'nullable|string',
        ]);

        $waveNumber = $data['wave_number'] ?? ((int) ($workOrder->plans()->max('wave_number') ?? 0) + 1);

        $workOrder->plans()->create([
            'wave_number' => $waveNumber,
            'plan_type' => $data['plan_type'],
            'planned_quantity' => $data['planned_quantity'],
            'status' => $data['status'],
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Plan/Wave berhasil ditambahkan.');
    }

    public function storeWip(Request $request, LostWaxWorkOrder $workOrder)
    {
        $data = $request->validate([
            'work_order_plan_id' => 'nullable|exists:lost_wax_work_order_plans,id',
            'stage' => 'required|in:moulding,assembly',
            'quantity' => 'required|integer|min:1',
            'status' => 'required|in:draft,recorded,closed',
            'notes' => 'nullable|string',
            'produced_at' => 'nullable|date',
        ]);

        $workOrder->wipEntries()->create([
            'work_order_plan_id' => $data['work_order_plan_id'] ?? null,
            'stage' => $data['stage'],
            'quantity' => $data['quantity'],
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'family_code' => $data['family_code'] ?? null,
        ]);

        return back()->with('success', 'WIP berhasil dicatat.');
    }

    public function bulkCreate()
    {
        $itemOptions = $this->itemMasterRepository->allActive();
        $families = config('lost_wax.families', []);

        return view('lost-wax.work-orders.bulk-create', compact('itemOptions', 'families'));
    }

    public function bulkStore(Request $request)
    {
        $rows = $request->input('rows', []);

        if (! is_array($rows) || count($rows) === 0) {
            return response()->json(['success' => false, 'message' => 'Tidak ada data yang dikirim.'], 422);
        }

        $errors = [];
        $etCodes = [];
        $existingEts = \App\Models\LostWaxWorkOrder::pluck('et_code')->toArray();

        $familiesConfig = config('lost_wax.families', []);

        foreach ($rows as $i => $row) {
            $line = $i + 1;

            $etCode = trim($row['et_code'] ?? '');
            $itemCode = trim($row['item_code'] ?? '');
            $poNumber = trim($row['po_number'] ?? '');
            $poQty = trim($row['po_quantity'] ?? '');
            $stockQty = trim($row['stock_quantity'] ?? '0');
            $netQty = trim($row['net_requirement_quantity'] ?? '');
            $status = trim($row['status'] ?? 'draft');
            $familyCode = trim($row['family_code'] ?? '');

            if ($etCode === '') {
                $errors[] = "Baris {$line}: ET Code wajib diisi.";
            } elseif (in_array($etCode, $etCodes)) {
                $errors[] = "Baris {$line}: ET \"{$etCode}\" duplikat dalam batch yang sama.";
            } elseif (in_array($etCode, $existingEts)) {
                $errors[] = "Baris {$line}: ET \"{$etCode}\" sudah ada di database.";
            } else {
                $etCodes[] = $etCode;
                $existingEts[] = $etCode;
            }

            if ($itemCode === '') {
                $errors[] = "Baris {$line}: Item Code wajib diisi.";
            } else {
                $item = $this->itemMasterRepository->findByCode($itemCode);
                if (! $item) {
                    $errors[] = "Baris {$line}: Item \"{$itemCode}\" tidak ditemukan di MasterDataKPI.";
                }
            }

            if ($poNumber === '') {
                $errors[] = "Baris {$line}: PO Number wajib diisi.";
            }

            if ($poQty === '' || ! is_numeric($poQty) || (int) $poQty < 0) {
                $errors[] = "Baris {$line}: PO Quantity harus angka >= 0.";
            }

            if (! is_numeric($stockQty) || (int) $stockQty < 0) {
                $errors[] = "Baris {$line}: Stock Quantity harus angka >= 0.";
            }

            if ($netQty === '' || ! is_numeric($netQty) || (int) $netQty < 0) {
                $errors[] = "Baris {$line}: Net Requirement harus angka >= 0.";
            }

            if (! in_array($status, ['draft', 'planned', 'active', 'hold', 'completed', 'cancelled'])) {
                $errors[] = "Baris {$line}: Status \"{$status}\" tidak valid.";
            }

            if ($familyCode !== '' && ! array_key_exists($familyCode, $familiesConfig)) {
                $errors[] = "Baris {$line}: Family Code \"{$familyCode}\" tidak dikenali.";
            }
        }

        if (count($errors) > 0) {
            return response()->json(['success' => false, 'errors' => $errors], 422);
        }

        try {
            $created = DB::transaction(function () use ($rows) {
                $workOrders = [];

                foreach ($rows as $row) {
                    $etCode = trim($row['et_code']);
                    $itemCode = trim($row['item_code']);
                    $item = $this->itemMasterRepository->findByCode($itemCode);

                    $itemReference = \App\Models\LostWaxItemReference::updateOrCreate(
                        [
                            'master_source' => 'masterdata_kpi',
                            'master_item_key' => $item['code'],
                        ],
                        [
                            'item_code_snapshot' => $item['code'],
                            'item_name_snapshot' => $item['name'] ?? null,
                            'aisi_snapshot' => $item['aisi'] ?? null,
                            'standard_snapshot' => $item['standard'] ?? null,
                            'unit_weight_snapshot' => $item['unit_weight'] ?? null,
                            'status_snapshot' => $item['status'] ?? null,
                            'last_synced_at' => now(),
                        ]
                    );

                    $wo = \App\Models\LostWaxWorkOrder::create([
                        'item_reference_id' => $itemReference->id,
                        'et_code' => $etCode,
                        'et_prefix' => $this->parseEtParts($etCode)['prefix'],
                        'et_period' => $this->parseEtParts($etCode)['period'],
                        'et_sequence' => $this->parseEtParts($etCode)['sequence'],
                        'po_number' => trim($row['po_number']),
                        'customer_name' => ! empty(trim($row['customer_name'] ?? '')) ? trim($row['customer_name']) : null,
                        'po_quantity' => (int) $row['po_quantity'],
                        'stock_quantity' => (int) ($row['stock_quantity'] ?? 0),
                        'net_requirement_quantity' => (int) $row['net_requirement_quantity'],
                        'status' => trim($row['status'] ?? 'draft'),
                        'notes' => ! empty(trim($row['notes'] ?? '')) ? trim($row['notes']) : null,
                        'due_date' => ! empty(trim($row['due_date'] ?? '')) ? trim($row['due_date']) : null,
                        'family_code' => ! empty(trim($row['family_code'] ?? '')) ? trim($row['family_code']) : null,
                        'require_layer_7' => (bool) ($row['require_layer_7'] ?? false),
                    ]);

                    $workOrders[] = $wo;
                }

                return $workOrders;
            });

            return response()->json([
                'success' => true,
                'message' => count($created).' Work Order berhasil dibuat.',
                'redirect' => route('lost-wax.work-orders.index'),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Gagal: '.$e->getMessage()], 500);
        }
    }

    protected function validateWorkOrder(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'et_code' => 'required|string|max:50|unique:lost_wax_work_orders,et_code'.($ignoreId ? ','.$ignoreId : ''),
            'item_code' => 'required|string|max:50',
            'po_number' => 'required|string|max:100',
            'customer_name' => 'nullable|string|max:150',
            'po_quantity' => 'required|integer|min:0',
            'stock_quantity' => 'required|integer|min:0',
            'net_requirement_quantity' => 'required|integer|min:0',
            'status' => 'required|in:draft,planned,active,hold,completed,cancelled',
            'notes' => 'nullable|string',
            'due_date' => 'nullable|date',
            'family_code' => 'nullable|string|max:10',
            'require_layer_7' => 'boolean',
        ]);
    }

    protected function parseEtParts(string $etCode): array
    {
        if (preg_match('/^(?<prefix>[A-Z]+)(?<period>\d{2,4})?-?(?<sequence>\d+)$/', $etCode, $matches)) {
            return [
                'prefix' => $matches['prefix'] ?? 'ET',
                'period' => $matches['period'] ?? null,
                'sequence' => isset($matches['sequence']) ? (int) $matches['sequence'] : null,
            ];
        }

        return [
            'prefix' => 'ET',
            'period' => null,
            'sequence' => null,
        ];
    }
}
