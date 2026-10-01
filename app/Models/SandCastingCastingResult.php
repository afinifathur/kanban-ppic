<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SandCastingCastingResult extends Model
{
    protected $fillable = [
        'heat_number',
        'cast_date',
        'furnace',
        'shift',
        'operator_name',
        'notes',
        'recorded_by',
    ];

    protected $casts = [
        'cast_date' => 'date',
    ];

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function lines()
    {
        return $this->hasMany(SandCastingCastingResultLine::class, 'sand_casting_casting_result_id');
    }

    public function orderLines()
    {
        return $this->hasManyThrough(
            SandCastingCastingOrderLine::class,
            SandCastingCastingResultLine::class,
            'sand_casting_casting_result_id',
            'id',
            'id',
            'sand_casting_casting_order_line_id'
        );
    }

    public function corrections()
    {
        return $this->hasMany(SandCastingCastingResultCorrection::class, 'sand_casting_casting_result_id')
            ->orderBy('created_at', 'desc');
    }

    public function hasPhysicalExecution(): bool
    {
        if ($this->relationLoaded('lines')) {
            foreach ($this->lines as $line) {
                if ($line->hasPhysicalExecution()) {
                    return true;
                }
            }

            return false;
        }

        return $this->lines()->whereHas('stageExecutions')->exists();
    }

    public function getTotalQtyGoodAttribute(): int
    {
        return (int) $this->lines->sum('qty_good');
    }

    public function getTotalQtyRejectAttribute(): int
    {
        return (int) $this->lines->sum('qty_reject');
    }

    public function getTotalWeightKgAttribute(): float
    {
        return (float) $this->lines->sum('total_weight_kg');
    }
}
