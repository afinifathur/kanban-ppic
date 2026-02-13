<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\ProductionItem;
use App\Models\DefectType;
use App\Models\ProductionDefect;
use Illuminate\Support\Facades\DB;

class DefectController extends Controller
{
    public function index(Request $request, $dept)
    {
        $search = $request->query('search');
        $sort = $request->query('sort', 'newest');
        $status = $request->query('status', 'all');

        $query = ProductionItem::where('current_dept', $dept)
            ->where('scrap_qty', '>', 0)
            ->withSum('defects', 'qty');

        // Search Filter
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('heat_number', 'like', "%{$search}%")
                    ->orWhere('item_code', 'like', "%{$search}%")
                    ->orWhere('item_name', 'like', "%{$search}%");
            });
        }

        // Status Filter
        if ($status === 'incomplete') {
            $query->havingRaw('COALESCE(defects_sum_qty, 0) < scrap_qty');
        } elseif ($status === 'completed') {
            $query->havingRaw('COALESCE(defects_sum_qty, 0) >= scrap_qty');
        }

        // Sorting
        if ($sort === 'oldest') {
            $query->orderBy('production_date', 'asc')->orderBy('created_at', 'asc');
        } else {
            $query->orderByDesc('production_date')->orderByDesc('created_at');
        }

        $items = $query->paginate(20)->withQueryString();

        // Stats: Total Incomplete in this Dept
        $incompleteCount = ProductionItem::where('current_dept', $dept)
            ->where('scrap_qty', '>', 0)
            ->withSum('defects', 'qty')
            ->havingRaw('COALESCE(defects_sum_qty, 0) < scrap_qty')
            ->count();

        $defectTypes = DefectType::where('department', $dept)->active()->get();

        return view('input.defect.index', compact('dept', 'items', 'defectTypes', 'incompleteCount', 'search', 'sort', 'status'));
    }

    public function store(Request $request, ProductionItem $item)
    {
        $request->validate([
            'defects' => 'required|array',
            'defects.*.defect_type_id' => 'required|exists:defect_types,id',
            'defects.*.qty' => 'required|integer|min:1',
            'defects.*.notes' => 'nullable|string',
        ]);

        DB::transaction(function () use ($request, $item) {
            // Remove existing defects for this item to allow "sync" style update
            // Or typically we might just add new ones. 
            // Let's go with: delete all existing defects for this item and re-create.
            $item->defects()->delete();

            $totalLogged = 0;
            foreach ($request->defects as $defectData) {
                if ($defectData['qty'] > 0) {
                    $item->defects()->create([
                        'defect_type_id' => $defectData['defect_type_id'],
                        'qty' => $defectData['qty'],
                        'notes' => $defectData['notes'] ?? null,
                    ]);
                    $totalLogged += $defectData['qty'];
                }
            }

            // Optional: Validation that totalLogged <= $item->scrap_qty
            // For now, we allow flexibility but maybe warn in UI
        });

        return back()->with('success', 'Detail kerusakan berhasil disimpan.');
    }
}
