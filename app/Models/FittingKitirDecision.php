<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 2 Operational Contract (M4):
 * - Mutual exclusion with `fitting_items.active_kitir_id` MUST be enforced inside `DB::transaction()`
 *   using `LostWaxKitir::lockForUpdate()` so a Kitir cannot be simultaneously TRANSFERRED and NOT_CONTINUED.
 * - Undo/Re-open MUST set `status = STATUS_REOPENED`, `active_kitir_id = null`, `reopened_by`, and `reopened_at`.
 */
class FittingKitirDecision extends Model
{
    public const DECISION_NOT_CONTINUED = 'NOT_CONTINUED';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_REOPENED = 'REOPENED';

    protected $fillable = [
        'lost_wax_kitir_id',
        'active_kitir_id',
        'lost_wax_casting_result_id',
        'decision',
        'reason',
        'decided_by',
        'decided_at',
        'status',
        'reopened_by',
        'reopened_at',
        'reopen_reason',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    public function lostWaxKitir(): BelongsTo
    {
        return $this->belongsTo(\App\Models\LostWaxKitir::class, 'lost_wax_kitir_id');
    }

    public function lostWaxCastingResult(): BelongsTo
    {
        return $this->belongsTo(\App\Models\LostWaxCastingResult::class, 'lost_wax_casting_result_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'decided_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'reopened_by');
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
