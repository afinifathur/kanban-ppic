<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LostWaxCastingResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'heat_number',
        'cast_date',
        'furnace',
        'shift',
        'operator_name',
        'total_trees',
        'total_pcs',
        'notes',
        'status',
        'recorded_by',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    protected $casts = [
        'cast_date' => 'date',
        'voided_at' => 'datetime',
        'total_trees' => 'integer',
        'total_pcs' => 'integer',
    ];

    /**
     * Detail rows (Traveler Cards associated with this casting result).
     */
    public function trees()
    {
        return $this->hasMany(\App\Models\LostWaxCastingResultTree::class, 'lost_wax_casting_result_id');
    }

    /**
     * User who recorded this casting result.
     */
    public function recorder()
    {
        return $this->belongsTo(\App\Models\User::class, 'recorded_by');
    }

    /**
     * User who voided/cancelled this casting result.
     */
    public function voider()
    {
        return $this->belongsTo(\App\Models\User::class, 'voided_by');
    }

    /**
     * Check if the casting result is cancelled.
     */
    public function getIsCancelledAttribute(): bool
    {
        return $this->status === 'CANCELLED';
    }

    /**
     * Check if the casting result is completed/active.
     */
    public function getIsCompletedAttribute(): bool
    {
        return $this->status === 'COMPLETED';
    }

    /**
     * Generate transaction number based on ID and cast date.
     */
    public function getTransactionNumberAttribute(): string
    {
        $datePart = $this->cast_date ? $this->cast_date->format('Ymd') : date('Ymd');

        return sprintf('LW-COR-%s-%04d', $datePart, $this->id);
    }
}

