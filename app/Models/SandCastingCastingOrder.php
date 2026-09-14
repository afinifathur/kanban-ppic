<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SandCastingCastingOrder extends Model
{
    protected $fillable = [
        'casting_order_number',
        'scheduled_date',
        'status',
        'notes',
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
        return $this->hasMany(SandCastingCastingOrderLine::class, 'sand_casting_casting_order_id');
    }

    public function resultLines()
    {
        return $this->hasManyThrough(
            SandCastingCastingResultLine::class,
            SandCastingCastingOrderLine::class,
            'sand_casting_casting_order_id',
            'sand_casting_casting_order_line_id',
            'id',
            'id'
        );
    }

    public function results()
    {
        return SandCastingCastingResult::whereHas('lines', function ($q) {
            $q->whereIn('sand_casting_casting_order_line_id', $this->lines()->select('id'));
        });
    }

    public function getResultsAttribute()
    {
        return $this->results()->with('lines')->get();
    }

    /**
     * Safety guard for cancellation/deletion:
     * Check if downstream production facts (Hasil Cor) exist.
     */
    public function hasRecordedOutcomes(): bool
    {
        return $this->lines()->whereHas('resultLines')->exists();
    }

    public function getQtyOrderedAttribute(): int
    {
        return (int) $this->lines->sum('qty_ordered');
    }
}
