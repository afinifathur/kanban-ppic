<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FittingTransfer extends Model
{
    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'transfer_number',
        'transfer_date',
        'transferred_by',
        'total_kitirs',
        'total_pcs',
        'total_weight_kg',
        'status',
        'notes',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'idempotency_key',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'total_kitirs' => 'integer',
        'total_pcs' => 'integer',
        'total_weight_kg' => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'transferred_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'cancelled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(\App\Models\FittingItem::class, 'fitting_transfer_id');
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getIsCancelledAttribute(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
