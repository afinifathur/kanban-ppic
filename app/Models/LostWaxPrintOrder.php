<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostWaxPrintOrder extends Model
{
    protected $attributes = [
        'order_type' => 'REGULAR',
        'reprint_cycle' => 0,
    ];

    protected $fillable = [
        'print_order_number',
        'scheduled_date',
        'status',
        'order_type',
        'reprint_reason',
        'reprint_cycle',
        'created_by',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'reprint_cycle' => 'integer',
    ];

    public function isRegular(): bool
    {
        return ($this->order_type ?? 'REGULAR') === 'REGULAR';
    }

    public function isReprint(): bool
    {
        return $this->order_type === 'REPRINT';
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines()
    {
        return $this->hasMany(LostWaxPrintOrderLine::class, 'lost_wax_print_order_id');
    }

    public function hasRecordedOutcomes(): bool
    {
        return $this->lines()->whereNotNull('qty_actual_good')->exists() ||
               $this->lines()->whereHas('executions')->exists();
    }

    public function getQtyOrderedAttribute(): int
    {
        return $this->lines->sum('qty_ordered');
    }

    public function getQtyExecutedGoodAttribute(): int
    {
        return $this->lines->sum('qty_executed_good');
    }

    public function getQtyExecutedDefectAttribute(): int
    {
        return $this->lines->sum('qty_executed_defect');
    }

    public function getQtyOutstandingAttribute(): int
    {
        return $this->lines->sum('qty_outstanding');
    }

    public function getProgressPercentAttribute(): float
    {
        $plan = $this->qty_ordered;
        if ($plan <= 0) {
            return 0.0;
        }
        $actual = $this->qty_executed_good + $this->qty_executed_defect;

        return round(($actual / $plan) * 100, 1);
    }
}
