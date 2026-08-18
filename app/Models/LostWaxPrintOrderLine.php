<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostWaxPrintOrderLine extends Model
{
    protected $fillable = [
        'lost_wax_print_order_id',
        'production_plan_id',
        'qty_ordered',
        'code',
        'customer',
        'item_name',
        'size',
        'aisi',
        'qty_actual_good',
        'qty_actual_defect',
        'standard_tree_capacity',
        'actual_recorded_at',
        'actual_recorded_by',
    ];

    protected $casts = [
        'qty_ordered' => 'integer',
        'qty_actual_good' => 'integer',
        'qty_actual_defect' => 'integer',
        'standard_tree_capacity' => 'integer',
        'actual_recorded_at' => 'datetime',
    ];

    public function printOrder()
    {
        return $this->belongsTo(LostWaxPrintOrder::class, 'lost_wax_print_order_id');
    }

    public function productionPlan()
    {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_id');
    }

    public function trees()
    {
        return $this->hasMany(LostWaxTree::class, 'lost_wax_print_order_line_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'actual_recorded_by');
    }

    public function getQtyAvailableForRangkaiAttribute(): int
    {
        if ($this->qty_actual_good === null) {
            return 0;
        }
        $allocated = (int) $this->trees()->sum('quantity');

        return max(0, $this->qty_actual_good - $allocated);
    }

    public function getIsOutcomeRecordedAttribute(): bool
    {
        return $this->qty_actual_good !== null;
    }
}
