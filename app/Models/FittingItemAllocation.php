<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FittingItemAllocation extends Model
{
    protected $fillable = [
        'fitting_item_id',
        'production_plan_id',
        'production_code',
        'item_code',
        'item_name',
        'customer',
        'line_number',
        'allocated_qty_transferred',
        'unit_weight_kg',
        'allocated_weight_kg',
    ];

    protected $casts = [
        'line_number' => 'integer',
        'allocated_qty_transferred' => 'integer',
        'unit_weight_kg' => 'decimal:4',
        'allocated_weight_kg' => 'decimal:2',
    ];

    public function fittingItem(): BelongsTo
    {
        return $this->belongsTo(\App\Models\FittingItem::class, 'fitting_item_id');
    }

    public function productionPlan(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ProductionPlan::class, 'production_plan_id');
    }
}
