<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostWaxWorkOrder extends Model
{
    protected $fillable = [
        'item_reference_id',
        'et_code',
        'et_prefix',
        'et_period',
        'et_sequence',
        'po_number',
        'customer_name',
        'po_quantity',
        'stock_quantity',
        'net_requirement_quantity',
        'status',
        'notes',
        'due_date',
        'family_code',
        'require_layer_7',
    ];

    protected $casts = [
        'po_quantity' => 'integer',
        'stock_quantity' => 'integer',
        'net_requirement_quantity' => 'integer',
        'et_sequence' => 'integer',
        'due_date' => 'date',
    ];

    public function itemReference()
    {
        return $this->belongsTo(LostWaxItemReference::class, 'item_reference_id');
    }

    public function plans()
    {
        return $this->hasMany(LostWaxWorkOrderPlan::class, 'work_order_id');
    }

    public function wipEntries()
    {
        return $this->hasMany(LostWaxWorkOrderWip::class, 'work_order_id');
    }

    public function getPlannedQuantityAttribute(): int
    {
        return (int) $this->plans->sum('planned_quantity');
    }

    public function getInitialPlannedQuantityAttribute(): int
    {
        return (int) $this->plans->where('plan_type', 'initial')->sum('planned_quantity');
    }

    public function getAdditionalPlannedQuantityAttribute(): int
    {
        return (int) $this->plans->where('plan_type', 'additional')->sum('planned_quantity');
    }

    public function getMouldingOutputQuantityAttribute(): int
    {
        return (int) $this->wipEntries->where('stage', 'moulding')->sum('quantity');
    }

    public function getAssemblyOutputQuantityAttribute(): int
    {
        return (int) $this->wipEntries->where('stage', 'assembly')->sum('quantity');
    }

    public function getActualOutputQuantityAttribute(): int
    {
        return $this->assembly_output_quantity;
    }

    public function getRemainingBeforeTreeQuantityAttribute(): int
    {
        return max(0, (int) $this->net_requirement_quantity - $this->actual_output_quantity);
    }

    public function trees()
    {
        return $this->hasMany(LostWaxTree::class, 'work_order_id');
    }

    public function getTreeQuantityAttribute(): int
    {
        return (int) $this->trees->sum('quantity');
    }

    public function getRemainingUnallocatedQuantityAttribute(): int
    {
        return max(0, $this->assembly_output_quantity - $this->tree_quantity);
    }

    public function getTreeCountAttribute(): int
    {
        return $this->trees->count();
    }

    public function getPlanWaveCountAttribute(): int
    {
        return $this->plans->count();
    }
}
