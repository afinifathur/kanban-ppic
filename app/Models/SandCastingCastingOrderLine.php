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

    protected $appends = [
        'qty_cast_good',
        'qty_cast_reject',
        'qty_cast_total',
        'qty_remaining_to_cast',
        'progress_percent',
        'execution_status',
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

    public function getLineNumberAttribute(): ?int
    {
        if (array_key_exists('line_number', $this->attributes) && $this->attributes['line_number'] !== null) {
            return (int) $this->attributes['line_number'];
        }

        if ($this->productionPlan && $this->productionPlan->line_number !== null) {
            return (int) $this->productionPlan->line_number;
        }

        return null;
    }

    public function getQtyCastGoodAttribute(): int
    {
        if (array_key_exists('qty_cast_good', $this->attributes)) {
            return (int) $this->attributes['qty_cast_good'];
        }

        return (int) $this->resultLines->sum('qty_good');
    }

    public function getQtyCastRejectAttribute(): int
    {
        if (array_key_exists('qty_cast_reject', $this->attributes)) {
            return (int) $this->attributes['qty_cast_reject'];
        }

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

    public function getProgressPercentAttribute(): float
    {
        if ($this->qty_ordered <= 0) {
            return 0.0;
        }

        return min(100.0, round(($this->qty_cast_good / $this->qty_ordered) * 100, 1));
    }

    public function getExecutionStatusAttribute(): string
    {
        if ($this->qty_ordered > 0 && $this->qty_remaining_to_cast <= 0) {
            return 'COMPLETED';
        }

        if ($this->qty_cast_good > 0 || $this->qty_cast_reject > 0) {
            return 'IN_PROGRESS';
        }

        return 'NOT_STARTED';
    }
}
