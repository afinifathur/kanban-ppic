<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SandCastingCastingOrderLine extends Model
{
    protected $fillable = [
        'sand_casting_casting_order_id',
        'production_plan_id',
        'qty_ordered',
        'code',
        'customer',
        'item_name',
        'size',
        'aisi',
        'notes',
    ];

    protected $casts = [
        'qty_ordered' => 'integer',
    ];

    public function castingOrder()
    {
        return $this->belongsTo(SandCastingCastingOrder::class, 'sand_casting_casting_order_id');
    }

    public function productionPlan()
    {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_id');
    }
}
