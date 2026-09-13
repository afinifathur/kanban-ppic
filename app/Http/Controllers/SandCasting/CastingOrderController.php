<?php

namespace App\Http\Controllers\SandCasting;

use App\Http\Controllers\Controller;
use App\Models\ProductionPlan;
use App\Models\SandCastingCastingOrder;
use App\Models\SandCastingCastingOrderLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CastingOrderController extends Controller
{
    /**
     * Display a listing of plans and casting orders for Sand Casting.
     */
    public function plans(Request $request)
    {
        $scope = auth()->user()->product_scope;
        $isPpic = auth()->user()->hasRole('ppic');

        // 1. Datalists for Autocomplete Search (Domain: SAND_CASTING)
        $uniqueCodesQuery = ProductionPlan::where('production_domain', ProductionPlan::DOMAIN_SAND_CASTING)
            ->whereNotNull('code')
            ->where('code', '!=', '');
        if ($isPpic && $scope) {
            $uniqueCodesQuery->where('product_scope', $scope);
        }
        $uniqueCodes = $uniqueCodesQuery->distinct()->orderBy('code')->pluck('code');

        $uniqueCustomersQuery = ProductionPlan::where('production_domain', ProductionPlan::DOMAIN_SAND_CASTING)
            ->whereNotNull('customer')
            ->where('customer', '!=', '');
        if ($isPpic && $scope) {
            $uniqueCustomersQuery->where('product_scope', $scope);
        }
        $uniqueCustomers = $uniqueCustomersQuery->distinct()->orderBy('customer')->pluck('customer');

        // 2. Rencana Cor (Sand Casting Plan Items)
        $plansQuery = ProductionPlan::query()
            ->where('production_domain', ProductionPlan::DOMAIN_SAND_CASTING)
            ->withSum(['castingOrderLines as qty_casting_scheduled' => function ($query) {
                $query->whereHas('castingOrder', function ($q) {
                    $q->whereIn('status', ['DRAFT', 'ISSUED']);
                });
            }], 'qty_ordered');

        if ($isPpic && $scope) {
            $plansQuery->where('product_scope', $scope);
        }

        if ($request->filled('date')) {
            $plansQuery->whereDate('created_at', $request->date);
        }

        if ($request->filled('customer')) {
            $plansQuery->where('customer', 'like', '%'.$request->customer.'%');
        }

        if ($request->filled('code')) {
            $plansQuery->where('code', 'like', '%'.$request->code.'%');
        }

        $statusFilter = $request->input('status', 'active');
        if ($statusFilter === 'closed') {
            $plansQuery->where('is_closed', true);
        } elseif ($statusFilter === 'all') {
            // No filter on is_closed or remaining qty
        } else {
            // Default: 'active'
            $plansQuery->where('is_closed', false);

            $subquery = DB::table('sand_casting_casting_order_lines')
                ->join('sand_casting_casting_orders', 'sand_casting_casting_order_lines.sand_casting_casting_order_id', '=', 'sand_casting_casting_orders.id')
                ->whereColumn('sand_casting_casting_order_lines.production_plan_id', 'production_plans.id')
                ->whereIn('sand_casting_casting_orders.status', ['DRAFT', 'ISSUED'])
                ->selectRaw('COALESCE(SUM(sand_casting_casting_order_lines.qty_ordered), 0)');

            $plansQuery->whereRaw('qty_planned > ('.$subquery->toSql().')', $subquery->getBindings());
        }

        $plans = $plansQuery->orderBy('id', 'desc')
            ->paginate(50, ['*'], 'plans_page')
            ->withQueryString();

        // 3. Dokumen Perintah Cor (Casting Orders)
        $castingOrdersQuery = SandCastingCastingOrder::with(['creator', 'lines']);

        if ($isPpic && $scope) {
            $castingOrdersQuery->whereHas('lines.productionPlan', function ($q) use ($scope) {
                $q->where('product_scope', $scope);
            });
        }

        if ($request->filled('casting_order_number')) {
            $castingOrdersQuery->where('casting_order_number', 'like', '%'.$request->casting_order_number.'%');
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $castingOrdersQuery->whereHas('lines', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('code', 'like', '%'.$search.'%')
                        ->orWhere('item_name', 'like', '%'.$search.'%')
                        ->orWhereHas('productionPlan', function ($p) use ($search) {
                            $p->where('code', 'like', '%'.$search.'%')
                                ->orWhere('item_name', 'like', '%'.$search.'%');
                        });
                });
            });
        }

        $castingOrders = $castingOrdersQuery->orderBy('id', 'desc')
            ->paginate(15, ['*'], 'orders_page')
            ->withQueryString();

        $activeTab = $request->query('tab');
        if (! in_array($activeTab, ['plans', 'orders'])) {
            $activeTab = $request->query('orders_page') ? 'orders' : 'plans';
        }

        return view('sand-casting.casting-orders.plans', compact(
            'plans',
            'castingOrders',
            'activeTab',
            'uniqueCodes',
            'uniqueCustomers'
        ));
    }

    /**
     * Alias for index (redirects to plans list dashboard).
     */
    public function index(Request $request)
    {
        return redirect()->route('sand-casting.casting-orders.plans', $request->all());
    }

    /**
     * Show the form for creating a new casting order.
     */
    public function create(Request $request)
    {
        $planIds = $this->normalizeSelectedPlanIds($request->input('plan_ids'));

        if (empty($planIds)) {
            return redirect()->route('sand-casting.casting-orders.plans')
                ->with('error', 'Pilih minimal satu item rencana untuk membuat perintah cor.');
        }

        $scope = auth()->user()->product_scope;
        $isPpic = auth()->user()->hasRole('ppic');

        // Only PPIC with a product_scope or Admin may create Casting Orders.
        if ($isPpic && ! $scope) {
            abort(403, 'User PPIC tanpa product_scope tidak dapat membuat Perintah Cor.');
        }

        $plans = ProductionPlan::whereIn('id', $planIds)->get();

        if ($plans->count() !== count($planIds)) {
            return redirect()->route('sand-casting.casting-orders.plans')
                ->with('error', 'Item rencana tidak ditemukan.');
        }

        foreach ($plans as $plan) {
            // Strict Domain Guard: Reject non-SAND_CASTING plans
            if (! $plan->isSandCasting()) {
                abort(403, 'Item rencana '.$plan->code.' bukan bagian dari domain Sand Casting.');
            }

            if ($isPpic && $scope && $plan->product_scope !== $scope) {
                abort(403, 'Unauthorized.');
            }

            if ($plan->is_closed || $plan->qty_remaining_casting_scheduled <= 0) {
                return redirect()->route('sand-casting.casting-orders.plans')
                    ->with('error', 'Item rencana '.$plan->code.' sudah tidak aktif atau kuota perintah sudah habis.');
            }
        }

        $date = $request->input('scheduled_date', date('Y-m-d'));
        $castingOrderNumber = $this->generateNextCastingOrderNumber($date);

        return view('sand-casting.casting-orders.create', compact('plans', 'castingOrderNumber', 'date'));
    }

    /**
     * Store a newly created casting order in storage.
     */
    public function store(Request $request)
    {
        $scope = auth()->user()->product_scope;
        $isPpic = auth()->user()->hasRole('ppic');

        if ($request->input('action') === 'close_plan') {
            if ($isPpic && ! $scope) {
                abort(403, 'Unauthorized.');
            }
            $planId = $request->input('production_plan_id');
            $plan = ProductionPlan::findOrFail($planId);
            if (! $plan->isSandCasting()) {
                abort(403, 'Invalid domain.');
            }
            if ($isPpic && $scope && $plan->product_scope !== $scope) {
                abort(403, 'Unauthorized.');
            }
            $plan->update(['is_closed' => true]);

            return redirect()->back()->with('success', 'Rencana produksi '.$plan->code.' berhasil ditutup (CLOSED).');
        }

        if ($request->input('action') === 'open_plan') {
            if ($isPpic && ! $scope) {
                abort(403, 'Unauthorized.');
            }
            $planId = $request->input('production_plan_id');
            $plan = ProductionPlan::findOrFail($planId);
            if (! $plan->isSandCasting()) {
                abort(403, 'Invalid domain.');
            }
            if ($isPpic && $scope && $plan->product_scope !== $scope) {
                abort(403, 'Unauthorized.');
            }
            $plan->update(['is_closed' => false]);

            return redirect()->back()->with('success', 'Rencana produksi '.$plan->code.' berhasil dibuka kembali (OPEN).');
        }

        if ($request->input('action') === 'bulk_close_plans') {
            if ($isPpic && ! $scope) {
                abort(403, 'Unauthorized.');
            }
            $planIds = $this->normalizeSelectedPlanIds($request->input('plan_ids'));
            if (empty($planIds)) {
                return redirect()->back()->with('error', 'Pilih minimal satu item rencana untuk ditutup.');
            }

            $plans = ProductionPlan::whereIn('id', $planIds)->get();
            if ($plans->isEmpty()) {
                return redirect()->back()->with('error', 'Rencana produksi tidak ditemukan.');
            }

            foreach ($plans as $plan) {
                if (! $plan->isSandCasting()) {
                    abort(403, 'Invalid domain.');
                }
                if ($isPpic && $scope && $plan->product_scope !== $scope) {
                    abort(403, 'Unauthorized.');
                }
            }

            DB::transaction(function () use ($plans) {
                foreach ($plans as $plan) {
                    $plan->update(['is_closed' => true]);
                }
            });

            return redirect()->back()->with('success', count($plans).' rencana produksi berhasil ditutup (CLOSED).');
        }

        if ($isPpic && ! $scope) {
            abort(403, 'User PPIC tanpa product_scope tidak dapat membuat Perintah Cor.');
        }

        $items = collect($request->input('items', []))
            ->filter(function ($item) {
                return is_array($item) && isset($item['production_plan_id']);
            })
            ->unique('production_plan_id')
            ->values();

        $request->merge([
            'items' => $items->all(),
        ]);

        $request->validate([
            'scheduled_date' => 'required|date',
            'casting_order_number' => 'required|string|unique:sand_casting_casting_orders,casting_order_number',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.production_plan_id' => 'required|integer|exists:production_plans,id',
            'items.*.qty_ordered' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string|max:255',
        ]);

        $castingOrder = DB::transaction(function () use ($request, $isPpic, $scope) {
            // Lock and validate all plans
            foreach ($request->items as $itemData) {
                $plan = ProductionPlan::lockForUpdate()->findOrFail($itemData['production_plan_id']);

                // Strict Backend Domain Guard
                if (! $plan->isSandCasting()) {
                    abort(403, 'Rencana '.$plan->code.' bukan Sand Casting.');
                }

                if ($isPpic && $scope && $plan->product_scope !== $scope) {
                    abort(403, 'Unauthorized.');
                }

                if ($plan->is_closed) {
                    abort(422, 'Rencana '.$plan->code.' sudah ditutup (CLOSED).');
                }

                $available = $plan->qty_remaining_casting_scheduled;
                if ($itemData['qty_ordered'] > $available) {
                    abort(422, 'Kuantitas perintah untuk '.$plan->code.' melebihi sisa yang dapat diperintah (Maks: '.$available.' pcs).');
                }
            }

            $order = SandCastingCastingOrder::create([
                'casting_order_number' => $request->casting_order_number,
                'scheduled_date' => $request->scheduled_date,
                'status' => 'DRAFT',
                'notes' => $request->notes,
                'created_by' => auth()->id(),
            ]);

            foreach ($request->items as $itemData) {
                $plan = ProductionPlan::findOrFail($itemData['production_plan_id']);

                $order->lines()->create([
                    'production_plan_id' => $plan->id,
                    'qty_ordered' => $itemData['qty_ordered'],
                    'code' => $plan->code,
                    'customer' => $plan->customer,
                    'item_name' => $plan->item_name,
                    'size' => $plan->size,
                    'aisi' => $plan->aisi,
                    'notes' => $itemData['notes'] ?? null,
                ]);
            }

            return $order;
        });

        return redirect()->route('sand-casting.casting-orders.show', $castingOrder)
            ->with('success', 'Dokumen Perintah Cor '.$castingOrder->casting_order_number.' berhasil dibuat.');
    }

    /**
     * Display the specified casting order.
     */
    public function show(SandCastingCastingOrder $castingOrder)
    {
        $this->authorizeCastingOrder($castingOrder);
        $castingOrder->load(['creator', 'lines.productionPlan']);

        return view('sand-casting.casting-orders.show', compact('castingOrder'));
    }

    /**
     * Show the form for editing the specified casting order.
     */
    public function edit(SandCastingCastingOrder $castingOrder)
    {
        $this->authorizeCastingOrder($castingOrder);
        if ($castingOrder->status !== 'DRAFT') {
            return redirect()->route('sand-casting.casting-orders.show', $castingOrder)
                ->with('error', 'Hanya dokumen berstatus DRAFT yang dapat diedit.');
        }

        $castingOrder->load('lines.productionPlan');

        $scope = auth()->user()->product_scope;
        $existingPlanIds = $castingOrder->lines->pluck('production_plan_id')->filter()->all();

        $availablePlansQuery = ProductionPlan::query()
            ->where('production_domain', ProductionPlan::DOMAIN_SAND_CASTING)
            ->where('is_closed', false)
            ->whereNotIn('id', $existingPlanIds);

        if (auth()->user()->hasRole('ppic') && $scope) {
            $availablePlansQuery->where('product_scope', $scope);
        }

        $availablePlans = $availablePlansQuery->orderBy('code')->get()->filter(function ($plan) {
            return $plan->qty_remaining_casting_scheduled > 0;
        })->values();

        return view('sand-casting.casting-orders.edit', compact('castingOrder', 'availablePlans'));
    }

    /**
     * Update the specified casting order in storage.
     */
    public function update(Request $request, SandCastingCastingOrder $castingOrder)
    {
        $this->authorizeCastingOrder($castingOrder);
        if ($castingOrder->status !== 'DRAFT') {
            return redirect()->route('sand-casting.casting-orders.show', $castingOrder)
                ->with('error', 'Hanya dokumen berstatus DRAFT yang dapat diperbarui.');
        }

        $request->validate([
            'scheduled_date' => 'required|date',
            'casting_order_number' => 'required|string|unique:sand_casting_casting_orders,casting_order_number,'.$castingOrder->id,
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:sand_casting_casting_order_lines,id',
            'items.*.qty_ordered' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($request, $castingOrder) {
            $castingOrder->update([
                'scheduled_date' => $request->scheduled_date,
                'casting_order_number' => $request->casting_order_number,
                'notes' => $request->notes,
            ]);

            foreach ($request->items as $itemData) {
                $line = $castingOrder->lines()->findOrFail($itemData['id']);
                $plan = $line->productionPlan;

                if ($plan) {
                    $otherOrdersSum = (int) $plan->castingOrderLines()
                        ->where('id', '!=', $line->id)
                        ->whereHas('castingOrder', function ($q) {
                            $q->whereIn('status', ['DRAFT', 'ISSUED']);
                        })
                        ->sum('qty_ordered');

                    $maxAllowed = max(0, $plan->qty_planned - $otherOrdersSum);
                    if ($itemData['qty_ordered'] > $maxAllowed) {
                        abort(422, 'Kuantitas untuk '.$line->code.' melebihi kuota rencana (Maks: '.$maxAllowed.' pcs).');
                    }
                }

                $line->update([
                    'qty_ordered' => $itemData['qty_ordered'],
                    'notes' => $itemData['notes'] ?? null,
                ]);
            }
        });

        return redirect()->route('sand-casting.casting-orders.show', $castingOrder)
            ->with('success', 'Dokumen Perintah Cor berhasil diperbarui.');
    }

    /**
     * Transition status of the casting order.
     */
    public function updateStatus(Request $request, SandCastingCastingOrder $castingOrder)
    {
        $this->authorizeCastingOrder($castingOrder);
        $targetStatus = $request->input('status');

        if (! in_array($targetStatus, ['DRAFT', 'ISSUED', 'COMPLETED', 'CANCELLED'])) {
            return back()->with('error', 'Status target tidak valid.');
        }

        if ($castingOrder->status === 'CANCELLED') {
            return back()->with('error', 'Dokumen yang sudah dibatalkan tidak dapat diubah kembali.');
        }

        if ($targetStatus === 'CANCELLED') {
            if ($castingOrder->hasRecordedOutcomes()) {
                return back()->with('error', 'Dokumen tidak dapat dibatalkan karena hasil cor sudah dicatat.');
            }
        }

        if ($targetStatus === 'ISSUED') {
            if ($castingOrder->status !== 'DRAFT') {
                return back()->with('error', 'Hanya dokumen DRAFT yang dapat diterbitkan.');
            }
        }

        if ($targetStatus === 'DRAFT') {
            if ($castingOrder->status === 'ISSUED') {
                return back()->with('error', 'Dokumen yang sudah diterbitkan tidak dapat dikembalikan ke DRAFT.');
            }
        }

        $castingOrder->update(['status' => $targetStatus]);

        return back()->with('success', 'Status dokumen berhasil diubah menjadi '.$targetStatus.'.');
    }

    /**
     * Render the printable version of the casting order.
     */
    public function print(SandCastingCastingOrder $castingOrder)
    {
        $this->authorizeCastingOrder($castingOrder);
        $castingOrder->load(['creator', 'lines.productionPlan']);

        return view('sand-casting.casting-orders.print', compact('castingOrder'));
    }

    /**
     * Remove the specified casting order from storage.
     */
    public function destroy(SandCastingCastingOrder $castingOrder)
    {
        $this->authorizeCastingOrder($castingOrder);
        if ($castingOrder->status !== 'DRAFT') {
            return redirect()->route('sand-casting.casting-orders.show', $castingOrder)
                ->with('error', 'Hanya dokumen berstatus DRAFT yang dapat dihapus.');
        }

        $castingOrder->delete();

        return redirect()->route('sand-casting.casting-orders.plans')
            ->with('success', 'Dokumen Perintah Cor berhasil dihapus.');
    }

    /**
     * Add a single Production Plan line to an existing DRAFT casting order.
     */
    public function storeLine(Request $request, SandCastingCastingOrder $castingOrder)
    {
        $this->authorizeCastingOrder($castingOrder);

        if ($castingOrder->status !== 'DRAFT') {
            abort(403, 'Hanya dokumen berstatus DRAFT yang dapat ditambahkan item baru.');
        }

        $request->validate([
            'production_plan_id' => 'required|integer|exists:production_plans,id',
            'qty_ordered' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:255',
        ]);

        $scope = auth()->user()->product_scope;
        $isPpic = auth()->user()->hasRole('ppic');

        if ($isPpic && ! $scope) {
            abort(403, 'User PPIC tanpa product_scope tidak dapat menambahkan item.');
        }

        $plan = DB::transaction(function () use ($request, $castingOrder, $scope, $isPpic) {
            $plan = ProductionPlan::lockForUpdate()->findOrFail($request->production_plan_id);

            // Backend Domain Guard
            if (! $plan->isSandCasting()) {
                abort(403, 'Rencana '.$plan->code.' bukan domain Sand Casting.');
            }

            if ($isPpic && $scope && $plan->product_scope !== $scope) {
                abort(403, 'Unauthorized.');
            }

            if ($plan->is_closed) {
                return back()->with('error', 'Item rencana produksi ini sudah ditutup (CLOSED).');
            }

            if ($castingOrder->lines()->where('production_plan_id', $plan->id)->exists()) {
                return back()->with('error', 'Item rencana produksi ini sudah ada di dalam dokumen Perintah Cor.');
            }

            $remaining = $plan->qty_remaining_casting_scheduled;
            if ($request->qty_ordered > $remaining) {
                return back()->with('error', 'Kuantitas melebihi sisa yang belum diperintah (Maks: '.number_format($remaining).' pcs).');
            }

            $castingOrder->lines()->create([
                'production_plan_id' => $plan->id,
                'qty_ordered' => $request->qty_ordered,
                'code' => $plan->code,
                'customer' => $plan->customer,
                'item_name' => $plan->item_name,
                'size' => $plan->size,
                'aisi' => $plan->aisi,
                'notes' => $request->notes,
            ]);

            return $plan;
        });

        if ($plan instanceof \Illuminate\Http\RedirectResponse) {
            return $plan;
        }

        return redirect()->route('sand-casting.casting-orders.edit', $castingOrder)
            ->with('success', 'Item '.$plan->code.' ('.number_format($request->qty_ordered).' pcs) berhasil ditambahkan ke Perintah Cor.');
    }

    /**
     * Remove a single line from a DRAFT casting order, releasing its
     * allocation back to the originating Production Plan.
     */
    public function destroyLine(SandCastingCastingOrder $castingOrder, SandCastingCastingOrderLine $line)
    {
        $this->authorizeCastingOrder($castingOrder);

        if ($line->sand_casting_casting_order_id !== $castingOrder->id) {
            abort(404);
        }

        if ($castingOrder->status !== 'DRAFT') {
            abort(403, 'Hanya item dari dokumen berstatus DRAFT yang dapat dihapus.');
        }

        $wasLastLine = $castingOrder->lines()->count() <= 1;
        $code = $line->code;
        $qtyReleased = $line->qty_ordered;

        DB::transaction(function () use ($castingOrder, $line, $wasLastLine) {
            $line->delete();

            if ($wasLastLine) {
                $castingOrder->delete();
            }
        });

        if ($wasLastLine) {
            return redirect()->route('sand-casting.casting-orders.plans')
                ->with('success', 'Semua item telah dihapus. Draft Perintah Cor telah dihapus.');
        }

        return redirect()->route('sand-casting.casting-orders.edit', $castingOrder)
            ->with('success', 'Item '.$code.' berhasil dihapus. '.number_format($qtyReleased).' pcs telah dikembalikan ke Rencana Cor.');
    }

    /**
     * Generate sequential casting order number inside a concurrency-safe lock block.
     * Format: PCOR-YYYYMMDD-XXXX
     */
    protected function generateNextCastingOrderNumber(string $date): string
    {
        return DB::transaction(function () use ($date) {
            $dateStr = str_replace('-', '', $date);

            // Lock existing orders for that date to avoid race conditions
            $lastOrder = SandCastingCastingOrder::whereDate('scheduled_date', $date)
                ->lockForUpdate()
                ->orderBy('id', 'desc')
                ->first();

            $sequence = 1;
            if ($lastOrder && $lastOrder->casting_order_number) {
                $parts = explode('-', $lastOrder->casting_order_number);
                if (count($parts) === 3) {
                    $sequence = ((int) $parts[2]) + 1;
                }
            }

            // Concurrency collision retry safeguard
            $candidate = 'PCOR-'.$dateStr.'-'.str_pad($sequence, 4, '0', STR_PAD_LEFT);
            while (SandCastingCastingOrder::where('casting_order_number', $candidate)->exists()) {
                $sequence++;
                $candidate = 'PCOR-'.$dateStr.'-'.str_pad($sequence, 4, '0', STR_PAD_LEFT);
            }

            return $candidate;
        });
    }

    /**
     * Authorize access to casting order based on user role and product scope.
     */
    protected function authorizeCastingOrder(SandCastingCastingOrder $castingOrder): void
    {
        $scope = auth()->user()->product_scope;
        if (auth()->user()->hasRole('ppic') && $scope) {
            $unauthorized = $castingOrder->lines()->whereHas('productionPlan', function ($q) use ($scope) {
                $q->where('product_scope', '!=', $scope);
            })->exists();

            if ($unauthorized) {
                abort(403, 'Unauthorized.');
            }
        }
    }

    /**
     * Normalize plan_ids input into array of integers.
     */
    protected function normalizeSelectedPlanIds($planIds): array
    {
        return collect(is_array($planIds) ? $planIds : [$planIds])
            ->map(function ($planId) {
                return (int) $planId;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
