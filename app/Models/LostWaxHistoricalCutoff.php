<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LostWaxHistoricalCutoff extends Model
{
    use HasFactory;

    protected $fillable = [
        'cutoff_number',
        'cutoff_date',
        'reason',
        'notes',
        'total_trees',
        'total_quantity',
        'total_defect_quantity',
        'total_usable_quantity',
        'status',
        'executed_by',
        'executed_at',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    protected $casts = [
        'cutoff_date' => 'date',
        'executed_at' => 'datetime',
        'voided_at' => 'datetime',
        'total_trees' => 'integer',
        'total_quantity' => 'integer',
        'total_defect_quantity' => 'integer',
        'total_usable_quantity' => 'integer',
    ];

    /**
     * Associated cutoff tree items.
     */
    public function trees()
    {
        return $this->hasMany(\App\Models\LostWaxHistoricalCutoffTree::class, 'lost_wax_historical_cutoff_id');
    }

    /**
     * User who executed the cutoff.
     */
    public function executor()
    {
        return $this->belongsTo(\App\Models\User::class, 'executed_by');
    }

    /**
     * User who voided the cutoff (if any).
     */
    public function voider()
    {
        return $this->belongsTo(\App\Models\User::class, 'voided_by');
    }

    /**
     * Check if cutoff transaction is active.
     */
    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'ACTIVE';
    }

    /**
     * Check if cutoff transaction is cancelled/voided.
     */
    public function getIsVoidedAttribute(): bool
    {
        return $this->status === 'CANCELLED';
    }
}

