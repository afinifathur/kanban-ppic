<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostWaxPrintOrder extends Model
{
    protected $fillable = [
        'print_order_number',
        'scheduled_date',
        'status',
        'created_by',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
    ];

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
