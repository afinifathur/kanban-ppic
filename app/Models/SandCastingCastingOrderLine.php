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

    public function resultLines()
    {
        return $this->hasMany(SandCastingCastingResultLine::class, 'sand_casting_casting_order_line_id');
    }

    public function getQtyCastGoodAttribute(): int
    {
        return (int) $this->resultLines->sum('qty_good');
    }

    public function getQtyCastRejectAttribute(): int
    {
        return (int) $this->resultLines->sum('qty_reject');
    }

    public function getQtyCastTotalAttribute(): int
    {
        return $this->qty_cast_good + $this->qty_cast_reject;
    }

    public function getQtyRemainingToCastAttribute(): int
    {
        return max(0, $this->qty_ordered - $this->qty_cast_good);
    }
}
