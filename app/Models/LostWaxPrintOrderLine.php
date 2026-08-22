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
        'execution_status',
        'require_layer_7',
        'qty_executed_good',
        'qty_executed_defect',
    ];

    protected $casts = [
        'qty_ordered' => 'integer',
        'qty_actual_good' => 'integer',
        'qty_actual_defect' => 'integer',
        'standard_tree_capacity' => 'integer',
        'actual_recorded_at' => 'datetime',
        'require_layer_7' => 'boolean',
        'qty_executed_good' => 'integer',
        'qty_executed_defect' => 'integer',
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

    public function executions()
    {
        return $this->hasMany(LostWaxPrintExecution::class, 'lost_wax_print_order_line_id');
    }

    public function rangkaiWorkOrder()
    {
        return $this->hasOne(LostWaxRangkaiWorkOrder::class, 'lost_wax_print_order_line_id');
    }

    public function rangkaiWorkOrders()
    {
        return $this->hasMany(LostWaxRangkaiWorkOrder::class, 'lost_wax_print_order_line_id');
    }

    public function getQtyAvailableForRangkaiAttribute(): int
    {
        // Source of truth: finalized print execution good pcs (fallback to legacy qty_actual_good for compatibility)
        $good = $this->qty_executed_good !== null && $this->qty_executed_good > 0
            ? (int) $this->qty_executed_good
            : (int) ($this->qty_actual_good ?? 0);

        $allocated = (int) $this->trees()->sum('quantity');

        return max(0, $good - $allocated);
    }

    public function getIsOutcomeRecordedAttribute(): bool
    {
        return $this->qty_actual_good !== null || $this->executions()->exists();
    }

    public function getQtyOutstandingAttribute(): int
    {
        $good = (int) ($this->qty_executed_good ?? 0);
        $defect = (int) ($this->qty_executed_defect ?? 0);

        return max(0, $this->qty_ordered - $good - $defect);
    }
}
