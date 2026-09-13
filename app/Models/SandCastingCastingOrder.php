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

    /**
     * Safety guard for cancellation/deletion:
     * Check if downstream production facts (Hasil Cor) exist.
     * Currently returns false since Hasil Cor is a future phase,
     * but provides the canonical hook for future checks.
     */
    public function hasRecordedOutcomes(): bool
    {
        return false;
    }

    public function getQtyOrderedAttribute(): int
    {
        return (int) $this->lines->sum('qty_ordered');
    }
}
