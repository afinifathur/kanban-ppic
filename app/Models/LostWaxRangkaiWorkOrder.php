<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostWaxRangkaiWorkOrder extends Model
{
    protected $table = 'lost_wax_rangkai_work_orders';

    protected $fillable = [
        'rangkai_order_number',
        'lost_wax_print_order_line_id',
        'qty_trees_planned',
        'tree_capacity',
        'require_layer_7',
        'status',
        'notes',
        'reference_image_path',
        'created_by',
        'standard_capacity_guide',
    ];

    protected $casts = [
        'require_layer_7' => 'boolean',
        'qty_trees_planned' => 'integer',
        'tree_capacity' => 'integer',
        'standard_capacity_guide' => 'integer',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function printOrderLine()
    {
        return $this->belongsTo(LostWaxPrintOrderLine::class, 'lost_wax_print_order_line_id');
    }

    public function executions()
    {
        return $this->hasMany(LostWaxRangkaiExecution::class, 'rangkai_work_order_id');
    }

    // Accessor: Qty Planned in pcs
    public function getQtyPlannedPcsAttribute(): int
    {
        /**
         * COMPATIBILITY BRIDGE:
         * - Legacy WO: tree_capacity > 1
         *   → qty_trees_planned means number of physical trees.
         *   → qty_planned_pcs = qty_trees_planned * tree_capacity
         *
         * - New WO: tree_capacity = 1
         *   → qty_trees_planned is repurposed as the ordered PCS quantity.
         *   → qty_planned_pcs = qty_trees_planned
         *   → NO physical tree is implied by this value.
         *
         * Note to future developers: tree_capacity = 1 does NOT mean "1 pcs per tree".
         * The standard_capacity_guide column contains the capacity guide/pedoman instead.
         */
        if ($this->tree_capacity === 1) {
            return $this->qty_trees_planned;
        }

        return $this->qty_trees_planned * $this->tree_capacity;
    }

    // Accessor: Qty Executed (Good) in pcs
    public function getQtyExecutedPcsAttribute(): int
    {
        // Rangkai execution sum of trees * capacity
        return $this->executions->sum(function ($exec) {
            return $exec->trees->sum('quantity');
        });
    }

    // Accessor: Total Trees Completed
    public function getTreesCompletedAttribute(): int
    {
        return $this->executions->sum('trees_created');
    }

    // Accessor: Outstanding in pcs
    public function getQtyOutstandingAttribute(): int
    {
        return max(0, $this->qty_planned_pcs - $this->qty_executed_pcs);
    }
}
