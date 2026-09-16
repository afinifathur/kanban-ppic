<?php

namespace App\Http\Controllers;

use App\Models\ProductionPlan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index(Request $request)
    {
        $date = $request->query('date');
        $scope = auth()->user()->product_scope;
        $isPpic = auth()->user()->hasRole('ppic');
        $selectedDomain = $request->query('production_domain', 'ALL');
        $search = trim((string) $request->query('search', ''));
        $selectedStatus = $request->query('status');

        if ($date) {
            // Detail View for a specific planning group
            $groupTitle = $request->query('title');
            $groupDomain = $request->query('production_domain');
            $groupScope = $request->query('product_scope') ?: ($isPpic ? $scope : null);
            $sort = $request->query('sort');
            $direction = $request->query('direction', 'asc');

            $query = ProductionPlan::whereDate('created_at', $date);

            if ($groupTitle !== null && $groupTitle !== '') {
                $query->where('title', $groupTitle);
            }

            if ($groupDomain && $groupDomain !== 'ALL') {
                $query->where('production_domain', $groupDomain);
            }

            if ($groupScope) {
                $query->where('product_scope', $groupScope);
            } elseif ($isPpic && $scope) {
                $query->where('product_scope', $scope);
            }

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                        ->orWhere('item_code', 'like', "%{$search}%")
                        ->orWhere('item_name', 'like', "%{$search}%")
                        ->orWhere('customer', 'like', "%{$search}%")
                        ->orWhere('po_number', 'like', "%{$search}%");
                });
            }

            $plans = $query->orderBy('line_number', 'asc')->orderBy('id', 'asc')->get();

            // Bulk calculate actual execution for detail lines
            $planIds = $plans->pluck('id');
            $scGoods = \App\Models\SandCastingCastingResultLine::whereIn('production_plan_id', $planIds)
                ->groupBy('production_plan_id')
                ->selectRaw('production_plan_id, SUM(qty_good) as total_good')
                ->pluck('total_good', 'production_plan_id');

            $lwGoods = \App\Models\LostWaxPrintOrderLine::whereHas('printOrder', fn ($q) => $q->where('status', '!=', 'CANCELLED'))
                ->whereIn('production_plan_id', $planIds)
                ->groupBy('production_plan_id')
                ->selectRaw('production_plan_id, SUM(qty_executed_good) as total_good')
                ->pluck('total_good', 'production_plan_id');

            foreach ($plans as $plan) {
                if ($plan->production_domain === ProductionPlan::DOMAIN_SAND_CASTING) {
                    $plan->actual_execution = (int) ($scGoods[$plan->id] ?? 0);
                } elseif ($plan->production_domain === ProductionPlan::DOMAIN_LOST_WAX) {
                    $plan->actual_execution = (int) ($lwGoods[$plan->id] ?? 0);
                } else {
                    $plan->actual_execution = 0;
                }

                if ($plan->actual_execution == 0) {
                    $plan->execution_status = 'NOT_STARTED';
                } elseif ($plan->actual_execution < $plan->qty_planned) {
                    $plan->execution_status = 'IN_PROGRESS';
                } else {
                    $plan->execution_status = 'COMPLETED';
                }

                $plan->remaining_target = max(0, $plan->qty_planned - $plan->actual_execution);
                $plan->over_target = $plan->actual_execution > $plan->qty_planned ? ($plan->actual_execution - $plan->qty_planned) : 0;
            }

            if ($selectedStatus) {
                $statusNormalized = strtoupper($selectedStatus);
                if ($statusNormalized === 'PLANNING') {
                    $statusNormalized = 'NOT_STARTED';
                }
                if ($statusNormalized === 'ACTIVE') {
                    $statusNormalized = 'IN_PROGRESS';
                }
                $plans = $plans->filter(fn ($p) => $p->execution_status === $statusNormalized);
            }

            if ($sort) {
                if ($sort === 'hasil_cor' || $sort === 'actual_execution') {
                    $plans = $direction === 'desc'
                        ? $plans->sortByDesc('actual_execution')->values()
                        : $plans->sortBy('actual_execution')->values();
                } elseif ($sort === 'remaining_target') {
                    $plans = $direction === 'desc'
                        ? $plans->sortByDesc('remaining_target')->values()
                        : $plans->sortBy('remaining_target')->values();
                } else {
                    $plans = $direction === 'desc'
                        ? $plans->sortByDesc($sort)->values()
                        : $plans->sortBy($sort)->values();
                }
            }

            $planTitle = $groupTitle ?: $plans->first()?->title;
            $headerDomain = $groupDomain && $groupDomain !== 'ALL' ? $groupDomain : $plans->first()?->production_domain;
            $headerScope = $groupScope ?: $plans->first()?->product_scope;

            return view('plan.list', compact(
                'plans',
                'date',
                'planTitle',
                'sort',
                'direction',
                'selectedDomain',
                'search',
                'selectedStatus',
                'headerDomain',
                'headerScope',
                'groupTitle',
                'groupDomain',
                'groupScope'
            ));
        }

        // Summary View (Control Tower Default)
        $query = ProductionPlan::query();

        if ($isPpic && $scope) {
            $query->where('product_scope', $scope);
        }

        if ($selectedDomain && $selectedDomain !== 'ALL') {
            $query->where('production_domain', $selectedDomain);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('customer', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('item_code', 'like', "%{$search}%")
                    ->orWhere('item_name', 'like', "%{$search}%")
                    ->orWhere('po_number', 'like', "%{$search}%");
            });
        }

        $allPlans = $query->get();
        $planIds = $allPlans->pluck('id');

        // Bulk fetch execution good quantities (2 queries total, zero N+1)
        $scGoods = \App\Models\SandCastingCastingResultLine::whereIn('production_plan_id', $planIds)
            ->groupBy('production_plan_id')
            ->selectRaw('production_plan_id, SUM(qty_good) as total_good')
            ->pluck('total_good', 'production_plan_id');

        $lwGoods = \App\Models\LostWaxPrintOrderLine::whereHas('printOrder', fn ($q) => $q->where('status', '!=', 'CANCELLED'))
            ->whereIn('production_plan_id', $planIds)
            ->groupBy('production_plan_id')
            ->selectRaw('production_plan_id, SUM(qty_executed_good) as total_good')
            ->pluck('total_good', 'production_plan_id');

        // Group by composite key: DATE + title + production_domain + product_scope
        $grouped = [];
        foreach ($allPlans as $plan) {
            $planDate = $plan->created_at ? $plan->created_at->format('Y-m-d') : now()->format('Y-m-d');
            $title = $plan->title ?? '';
            $domain = $plan->production_domain ?? ProductionPlan::DOMAIN_LOST_WAX;
            $productScope = $plan->product_scope ?? '';

            $groupKey = $planDate.'|'.$title.'|'.$domain.'|'.$productScope;

            if ($domain === ProductionPlan::DOMAIN_SAND_CASTING) {
                $actualGood = (int) ($scGoods[$plan->id] ?? 0);
            } elseif ($domain === ProductionPlan::DOMAIN_LOST_WAX) {
                $actualGood = (int) ($lwGoods[$plan->id] ?? 0);
            } else {
                $actualGood = 0;
            }

            if (! isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [
                    'group_key' => $groupKey,
                    'plan_date' => $planDate,
                    'title' => $plan->title,
                    'production_domain' => $domain,
                    'product_scope' => $productScope,
                    'total_items' => 0,
                    'total_planned' => 0,
                    'total_actual_good' => 0,
                    'customers' => [],
                    'earliest_created_at' => $plan->created_at,
                ];
            }

            $grouped[$groupKey]['total_items']++;
            $grouped[$groupKey]['total_planned'] += (int) $plan->qty_planned;
            $grouped[$groupKey]['total_actual_good'] += $actualGood;
            if ($plan->customer && ! in_array($plan->customer, $grouped[$groupKey]['customers'], true)) {
                $grouped[$groupKey]['customers'][] = $plan->customer;
            }
            if ($plan->created_at && ($grouped[$groupKey]['earliest_created_at'] === null || $plan->created_at < $grouped[$groupKey]['earliest_created_at'])) {
                $grouped[$groupKey]['earliest_created_at'] = $plan->created_at;
            }
        }

        // Transform into rich object for blade view
        $planningGroups = collect($grouped)->map(function ($item) {
            $totalPlanned = $item['total_planned'];
            $totalGood = $item['total_actual_good'];

            if ($totalGood == 0) {
                $status = 'NOT_STARTED';
                $statusLabel = 'Not Started';
                $statusClass = 'bg-gray-100 text-gray-700 border-gray-300';
                $statusIcon = 'fa-clock text-gray-500';
            } elseif ($totalGood < $totalPlanned) {
                $status = 'IN_PROGRESS';
                $statusLabel = 'In Progress';
                $statusClass = 'bg-blue-100 text-blue-800 border-blue-300';
                $statusIcon = 'fa-sync-alt fa-spin text-blue-600';
            } else {
                $status = 'COMPLETED';
                $statusLabel = 'Completed';
                $statusClass = 'bg-emerald-100 text-emerald-800 border-emerald-300';
                $statusIcon = 'fa-check-double text-emerald-600';
            }

            $progressPercentage = $totalPlanned > 0 ? min(100, round(($totalGood / $totalPlanned) * 100, 1)) : 0;
            $achievementPercentage = $totalPlanned > 0 ? round(($totalGood / $totalPlanned) * 100, 1) : 0;
            $isOverTarget = $totalGood > $totalPlanned;
            $overTargetQty = $isOverTarget ? ($totalGood - $totalPlanned) : 0;
            $remainingQty = max(0, $totalPlanned - $totalGood);

            $agingDays = \Carbon\Carbon::parse($item['plan_date'])->diffInDays(now()->startOfDay());
            $agingColor = $agingDays < 7 ? 'blue' : ($agingDays < 14 ? 'yellow' : 'red');

            return (object) array_merge($item, [
                'execution_status' => $status,
                'status_label' => $statusLabel,
                'status_class' => $statusClass,
                'status_icon' => $statusIcon,
                'progress_percentage' => $progressPercentage,
                'achievement_percentage' => $achievementPercentage,
                'is_over_target' => $isOverTarget,
                'over_target_qty' => $overTargetQty,
                'remaining_qty' => $remainingQty,
                'aging_days' => $agingDays,
                'aging_color' => $agingColor,
                'unique_customers' => implode(', ', $item['customers']),
            ]);
        });

        // Filter by derived execution status if selected
        if ($selectedStatus) {
            $statusNormalized = strtoupper($selectedStatus);
            if ($statusNormalized === 'PLANNING') {
                $statusNormalized = 'NOT_STARTED';
            }
            if ($statusNormalized === 'ACTIVE') {
                $statusNormalized = 'IN_PROGRESS';
            }
            $planningGroups = $planningGroups->filter(fn ($g) => $g->execution_status === $statusNormalized);
        }

        // Sort by plan_date desc, earliest_created_at desc
        $dailyStats = $planningGroups->sortByDesc(fn ($g) => $g->plan_date.'_'.($g->earliest_created_at ? $g->earliest_created_at->format('Y-m-d H:i:s') : ''))->values();

        return view('plan.index', compact('dailyStats', 'selectedDomain', 'search', 'selectedStatus'));
    }

    public function create()
    {
        $user = auth()->user();
        if (! $user->hasRole('ppic') || ! $user->product_scope) {
            abort(403, 'Hanya user PPIC dengan product scope yang dapat membuat rencana produksi.');
        }

        $customers = \App\Models\Customer::where('is_active', true)->orderBy('name')->get();

        return view('plan.create', compact('customers'));
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        if (! $user->hasRole('ppic') || ! $user->product_scope) {
            abort(403, 'Hanya user PPIC dengan product scope yang dapat membuat rencana produksi.');
        }

        $data = $request->validate([
            'date' => 'nullable|date',
            'title' => 'required|string|max:255',
            'production_domain' => 'required|string|in:'.ProductionPlan::DOMAIN_LOST_WAX.','.ProductionPlan::DOMAIN_SAND_CASTING,
            'plans' => 'required|array|min:1',
        ]);

        $customDate = $data['date'] ?? null;
        $customTitle = $data['title'] ?? null;
        $productionDomain = $data['production_domain'];
        $userScope = $user->product_scope;

        $errors = [];
        $validatedPlans = [];
        $seenBatchCodes = [];

        // Pass 1: Row format, required fields, within-batch duplicates, and code normalization
        foreach ($data['plans'] as $index => $rawPlan) {
            $rowNumber = $rawPlan['row_number'] ?? ($index + 1);

            $rawCode = $rawPlan['code'] ?? '';
            $normalizedCode = strtoupper(trim((string) $rawCode));

            $itemCode = isset($rawPlan['item_code']) ? trim((string) $rawPlan['item_code']) : '';
            $itemName = isset($rawPlan['item_name']) ? trim((string) $rawPlan['item_name']) : '';
            $poNumber = isset($rawPlan['po_number']) ? trim((string) $rawPlan['po_number']) : '';
            $qtyPlannedRaw = $rawPlan['qty_planned'] ?? null;
            $lineNumberRaw = $rawPlan['line_number'] ?? null;
            $customer = isset($rawPlan['customer']) ? trim((string) $rawPlan['customer']) : null;
            $aisi = isset($rawPlan['aisi']) ? trim((string) $rawPlan['aisi']) : null;
            $size = isset($rawPlan['size']) ? trim((string) $rawPlan['size']) : null;
            $weight = (isset($rawPlan['weight']) && $rawPlan['weight'] !== '' && is_numeric($rawPlan['weight'])) ? (float) $rawPlan['weight'] : null;
            $poQty = (isset($rawPlan['po_quantity']) && $rawPlan['po_quantity'] !== '' && is_numeric($rawPlan['po_quantity'])) ? (int) $rawPlan['po_quantity'] : null;

            // Required field checks
            $rowHasError = false;

            if ($normalizedCode === '') {
                $errors[] = [
                    'row' => $rowNumber,
                    'code' => null,
                    'reason' => 'Kode Produksi (Code) wajib diisi.',
                ];
                $rowHasError = true;
            }

            if ($itemCode === '') {
                $errors[] = [
                    'row' => $rowNumber,
                    'code' => $normalizedCode ?: null,
                    'reason' => 'Item Code wajib diisi.',
                ];
                $rowHasError = true;
            }

            if ($itemName === '') {
                $errors[] = [
                    'row' => $rowNumber,
                    'code' => $normalizedCode ?: null,
                    'reason' => 'Item Name wajib diisi.',
                ];
                $rowHasError = true;
            }

            if ($poNumber === '') {
                $errors[] = [
                    'row' => $rowNumber,
                    'code' => $normalizedCode ?: null,
                    'reason' => 'P.O. Number wajib diisi.',
                ];
                $rowHasError = true;
            }

            if ($qtyPlannedRaw === null || $qtyPlannedRaw === '' || ! is_numeric($qtyPlannedRaw) || (int) $qtyPlannedRaw <= 0) {
                $errors[] = [
                    'row' => $rowNumber,
                    'code' => $normalizedCode ?: null,
                    'reason' => 'Qty Planned wajib berupa angka lebih dari 0.',
                ];
                $rowHasError = true;
            }

            $lineNumber = (int) filter_var((string) $lineNumberRaw, FILTER_SANITIZE_NUMBER_INT);
            if ($lineNumber <= 0) {
                $lineNumber = $index + 1;
            }

            // Within-batch duplicate check
            if ($normalizedCode !== '') {
                if (isset($seenBatchCodes[$normalizedCode])) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'code' => $normalizedCode,
                        'reason' => "Kode Produksi '{$normalizedCode}' duplikat di dalam batch ini (sama dengan Baris {$seenBatchCodes[$normalizedCode]}).",
                    ];
                    $rowHasError = true;
                } else {
                    $seenBatchCodes[$normalizedCode] = $rowNumber;
                }
            }

            if (! $rowHasError) {
                $validatedPlans[] = [
                    'row_number' => $rowNumber,
                    'code' => $normalizedCode,
                    'item_code' => $itemCode,
                    'item_name' => $itemName,
                    'aisi' => $aisi,
                    'size' => $size,
                    'weight' => $weight,
                    'po_number' => $poNumber,
                    'po_quantity' => $poQty,
                    'qty_planned' => (int) $qtyPlannedRaw,
                    'line_number' => $lineNumber,
                    'customer' => $customer,
                ];
            }
        }

        // Pass 2: Bulk Database Duplicate Query across all candidate codes (O(1) query)
        $distinctCodes = array_keys($seenBatchCodes);
        if (! empty($distinctCodes)) {
            $existingPlans = ProductionPlan::whereIn('code', $distinctCodes)
                ->get(['id', 'code', 'title', 'po_number', 'item_code', 'item_name', 'customer', 'created_at', 'production_domain', 'product_scope']);

            if ($existingPlans->isNotEmpty()) {
                $existingGrouped = $existingPlans->groupBy(fn ($p) => strtoupper(trim((string) $p->code)));

                foreach ($validatedPlans as $vPlan) {
                    $c = $vPlan['code'];
                    if ($existingGrouped->has($c)) {
                        $matches = $existingGrouped->get($c);
                        $firstMatch = $matches->first();
                        $matchCount = $matches->count();

                        $details = "ID: {$firstMatch->id}, PO: {$firstMatch->po_number}, Item: {$firstMatch->item_code}";
                        if ($firstMatch->created_at) {
                            $details .= ', Tgl: '.$firstMatch->created_at->format('Y-m-d');
                        }
                        if ($matchCount > 1) {
                            $details .= " dan {$matchCount} data historis lainnya";
                        }

                        $errors[] = [
                            'row' => $vPlan['row_number'],
                            'code' => $c,
                            'reason' => "Kode Produksi '{$c}' sudah terdaftar di sistem ({$details}).",
                        ];
                    }
                }
            }
        }

        // Pass 3: All-or-Nothing validation barrier
        if (! empty($errors)) {
            usort($errors, fn ($a, $b) => ($a['row'] ?? 0) <=> ($b['row'] ?? 0));

            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal: Terdapat '.count($errors).' masalah pada data rencana yang diunggah.',
                'errors' => $errors,
                'total_submitted' => count($data['plans']),
                'rejected_count' => count($errors),
            ], 422);
        }

        // Pass 4: Transactional persistence
        \DB::transaction(function () use ($validatedPlans, $customTitle, $customDate, $userScope, $productionDomain) {
            foreach ($validatedPlans as $plan) {
                $newPlan = [
                    'code' => $plan['code'],
                    'title' => $customTitle,
                    'item_code' => $plan['item_code'],
                    'item_name' => $plan['item_name'],
                    'aisi' => $plan['aisi'],
                    'size' => $plan['size'],
                    'weight' => $plan['weight'],
                    'po_number' => $plan['po_number'],
                    'po_quantity' => $plan['po_quantity'],
                    'qty_planned' => $plan['qty_planned'],
                    'qty_remaining' => $plan['qty_planned'],
                    'line_number' => $plan['line_number'],
                    'customer' => $plan['customer'],
                    'product_scope' => $userScope,
                    'production_domain' => $productionDomain,
                    'status' => 'planning',
                ];

                if ($customDate) {
                    $newPlan['created_at'] = $customDate.' '.now()->format('H:i:s');
                    $newPlan['updated_at'] = $customDate.' '.now()->format('H:i:s');
                }

                ProductionPlan::create($newPlan);
            }
        });

        $processedCount = count($validatedPlans);

        return response()->json([
            'success' => true,
            'message' => $processedCount.' Rencana Produksi Berhasil Ditambahkan!',
            'processed' => $processedCount,
            'redirect' => route('plan.index'),
        ]);
    }

    public function edit(ProductionPlan $plan)
    {
        $user = auth()->user();
        if (! $user->hasRole('ppic') || ! $user->product_scope || $plan->product_scope !== $user->product_scope) {
            abort(403, 'Unauthorized.');
        }

        $isDomainLocked = $plan->is_closed || $plan->printOrderLines()->exists() || $plan->items()->exists() || $plan->status !== 'planning';

        return view('plan.edit', compact('plan', 'isDomainLocked'));
    }

    public function update(Request $request, ProductionPlan $plan)
    {
        $user = auth()->user();
        if (! $user->hasRole('ppic') || ! $user->product_scope || $plan->product_scope !== $user->product_scope) {
            abort(403, 'Unauthorized.');
        }

        $data = $request->validate([
            'line_number' => 'required|integer',
            'customer' => 'nullable|string',
            'po_number' => 'required|string',
            'po_quantity' => 'nullable|integer|min:0',
            'item_code' => 'required|string',
            'item_name' => 'required|string',
            'aisi' => 'nullable|string',
            'size' => 'nullable|string',
            'weight' => 'nullable|numeric',
            'qty_planned' => 'required|integer|min:1',
            'status' => 'required|in:planning,active,completed',
            'product_scope' => 'nullable|string|in:FLANGE_STAINLESS,FLANGE_BESI,FITTING_STAINLESS',
            'production_domain' => 'nullable|string|in:'.ProductionPlan::DOMAIN_LOST_WAX.','.ProductionPlan::DOMAIN_SAND_CASTING,
        ]);

        // Always force user's own product scope
        $data['product_scope'] = $user->product_scope;

        // Domain lock guard: prevent changing domain if transactions exist or plan is not in planning
        if (isset($data['production_domain']) && $data['production_domain'] !== $plan->production_domain) {
            $hasTransactions = $plan->is_closed || $plan->printOrderLines()->exists() || $plan->items()->exists() || $plan->status !== 'planning';
            if ($hasTransactions) {
                return back()->withInput()->with('error', 'Domain produksi tidak dapat diubah karena rencana produksi ini sudah memiliki transaksi atau telah dimulai.');
            }
        }

        // Calculate new qty_remaining if qty_planned changed
        if ($data['qty_planned'] != $plan->qty_planned) {
            $diff = $data['qty_planned'] - $plan->qty_planned;
            $data['qty_remaining'] = max(0, $plan->qty_remaining + $diff);
        }

        $plan->update($data);

        return redirect()->route('plan.index', ['date' => $plan->created_at->format('Y-m-d')])
            ->with('success', 'Rencana berhasil diperbarui.');
    }

    public function destroy(ProductionPlan $plan)
    {
        $user = auth()->user();
        if (! $user->hasRole('ppic') || ! $user->product_scope || $plan->product_scope !== $user->product_scope) {
            abort(403, 'Unauthorized.');
        }

        // Guard B: Closed Plan
        if ($plan->is_closed) {
            return back()->with('error', 'Tidak bisa menghapus rencana yang sudah ditutup.');
        }

        // Guard A: Lost Wax Print Order Line exists (Freeze Point)
        if ($plan->printOrderLines()->exists()) {
            return back()->with('error', 'Tidak bisa menghapus rencana yang sudah memiliki SPK cetak.');
        }

        // Guard C: Legacy Cor ProductionItem exists
        if ($plan->items()->exists()) {
            return back()->with('error', 'Tidak bisa menghapus rencana yang sudah memiliki data produksi.');
        }

        $plan->delete();

        return back()->with('success', 'Rencana berhasil dihapus.');
    }

    public function updateTitle(Request $request)
    {
        $user = auth()->user();
        if (! $user->hasRole('ppic') || ! $user->product_scope) {
            abort(403, 'Hanya user PPIC dengan product scope yang dapat memperbarui judul rencana.');
        }

        $request->validate([
            'date' => 'required|date',
            'title' => 'required|string|max:255',
        ]);

        $query = ProductionPlan::whereDate('created_at', $request->date)
            ->where('product_scope', $user->product_scope);

        $query->update(['title' => $request->title]);

        return back()->with('success', 'Judul Rencana berhasil diperbarui.');
    }
}
