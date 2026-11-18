<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LostWaxKitir extends Model
{
    use HasFactory;

    protected $fillable = [
        'lost_wax_casting_result_id',
        'traveler_number',
        'production_code',
        'customer',
        'item_name',
        'tree_count',
        'quantity_pcs',
        'print_count',
        'issued_at',
        'printed_at',
        'issued_by',
        'last_printed_by',
        'notes',
    ];

    protected $casts = [
        'tree_count' => 'integer',
        'quantity_pcs' => 'integer',
        'print_count' => 'integer',
        'issued_at' => 'datetime',
        'printed_at' => 'datetime',
    ];

    /**
     * Parent Casting Result header.
     */
    public function castingResult()
    {
        return $this->belongsTo(LostWaxCastingResult::class, 'lost_wax_casting_result_id');
    }

    /**
     * User who first issued this kitir.
     */
    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * User who last printed/reprinted this kitir.
     */
    public function lastPrinter()
    {
        return $this->belongsTo(User::class, 'last_printed_by');
    }

    /**
     * Check if this kitir has been printed at least once.
     */
    public function getIsPrintedAttribute(): bool
    {
        return $this->print_count > 0;
    }

    /**
     * Active Fitting Production traveler item linked via active_kitir_id (read-only bridge relation).
     */
    public function activeFittingItem()
    {
        return $this->hasOne(\App\Models\FittingItem::class, 'active_kitir_id');
    }

    /**
     * All Fitting Production traveler items (including cancelled transfer history).
     */
    public function fittingItems()
    {
        return $this->hasMany(\App\Models\FittingItem::class, 'lost_wax_kitir_id');
    }

    /**
     * Active NOT_CONTINUED decision linked via active_kitir_id (read-only bridge relation).
     */
    public function activeFittingDecision()
    {
        return $this->hasOne(\App\Models\FittingKitirDecision::class, 'active_kitir_id');
    }

    /**
     * All Fitting kitir decisions (including REOPENED audit history).
     */
    public function fittingDecisions()
    {
        return $this->hasMany(\App\Models\FittingKitirDecision::class, 'lost_wax_kitir_id');
    }
}
